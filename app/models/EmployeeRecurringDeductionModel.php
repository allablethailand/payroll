<?php
declare(strict_types=1);

/**
 * Recurring monthly DEDUCTIONS (uniform fee, locker fee, etc.) assigned per employee -- 2026-08-31,
 * explicit request: "หน้า Employee Detail เพิ่มรายหักประจำด้วยครับ และนำไปเพิ่มใน ตรงสรุปรายได้ประจำ ด้วย".
 *
 * A near-mirror of EmployeeRecurringEarningModel -- same reasoning throughout (own new table, not a
 * mode of `employee_earning_deductions`, which is inherently installment-based with a finite
 * schedule and doesn't fit an indefinitely-recurring item; reuses the SAME
 * `payroll_earning_deduction_types` catalog, restricted to item_type='deduction' AND
 * calculation_method='fixed_amount' instead of 'earning'; "Suspend" is the same both-or-neither
 * date-range mechanism). See that class's own docblock for the full design rationale -- not
 * re-explained here.
 */
class EmployeeRecurringDeductionModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private function employeeCompId(int $employeeId): ?int {
        $stmt = $this->db->prepare("SELECT comp_id FROM `employees` WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId]);
        $compId = $stmt->fetchColumn();
        return $compId !== false ? (int)$compId : null;
    }

    public function list(int $employeeId, int $compId): array {
        $stmt = $this->db->prepare("SELECT erd.*, pt.item_code, pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_deductions` erd
            JOIN `payroll_earning_deduction_types` pt ON pt.id = erd.ped_type_id
            JOIN `employees` e ON e.id = erd.employee_id
            WHERE erd.employee_id = :employee_id AND e.comp_id = :comp_id AND erd.status = 'active' AND erd.deleted_at IS NULL
            ORDER BY erd.effective_date DESC, erd.id DESC");
        $stmt->execute([':employee_id' => $employeeId, ':comp_id' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $today = date('Y-m-d');
        foreach ($rows as &$row) {
            $row['is_suspended_now'] = $row['suspended_from'] !== null && $row['suspended_to'] !== null
                && $row['suspended_from'] <= $today && $row['suspended_to'] >= $today;
        }
        unset($row);
        return $rows;
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT erd.*, pt.item_code, pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_deductions` erd
            JOIN `payroll_earning_deduction_types` pt ON pt.id = erd.ped_type_id
            JOIN `employees` e ON e.id = erd.employee_id
            WHERE erd.id = :id AND e.comp_id = :comp_id AND erd.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(int $employeeId, int $compId, array $data, int $userId): array {
        if ($this->employeeCompId($employeeId) !== $compId) {
            return ['status' => false, 'message' => 'Invalid employee.'];
        }
        $pedTypeId = (int)($data['ped_type_id'] ?? 0);
        if ($pedTypeId <= 0) {
            return ['status' => false, 'message' => 'Missing ped_type_id.'];
        }
        $stmtType = $this->db->prepare("SELECT id FROM `payroll_earning_deduction_types`
            WHERE id = :id AND comp_id = :comp_id AND item_type = 'deduction' AND calculation_method = 'fixed_amount'
              AND is_sync_only = 0 AND status = 'active' AND deleted_at IS NULL");
        $stmtType->execute([':id' => $pedTypeId, ':comp_id' => $compId]);
        if (!$stmtType->fetch()) {
            return ['status' => false, 'message' => 'Invalid or inactive deduction type selected.'];
        }

        if (!isset($data['amount']) || !is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
            return ['status' => false, 'message' => 'Amount must be greater than zero.'];
        }
        $amount = round((float)$data['amount'], 2);

        // 2026-08-31, explicit request: "Form ที่เป็นรายการหัก...ให้เพิ่มว่า คิดดอกเบี้ย ค่าธรรมเนียม หรือไม่มี" --
        // "Interest" has no equivalent here (no principal/installment-schedule concept, see this
        // table's own migration comment), only "Fee" does -- an ONGOING % of the employee's own base
        // salary, recomputed fresh every payroll run (not baked in once like the loan side's fee is),
        // since base salary can change over time. `fee_base` only ever validates to 'base_salary' for
        // this table today (no 'principal_amount' equivalent -- there's no principal here).
        $feePercent = null;
        $feeBase = null;
        if (!empty($data['fee_percent'])) {
            if (!is_numeric($data['fee_percent']) || (float)$data['fee_percent'] <= 0) {
                return ['status' => false, 'message' => 'fee_percent must be greater than zero.'];
            }
            $feePercent = round((float)$data['fee_percent'], 2);
            $feeBase = (string)($data['fee_base'] ?? '');
            if ($feeBase !== 'base_salary') {
                return ['status' => false, 'message' => 'Invalid fee_base.'];
            }
        }

        $effectiveDate = (string)($data['effective_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date.'];
        }

        $suspendedFrom = !empty($data['suspended_from']) ? (string)$data['suspended_from'] : null;
        $suspendedTo = !empty($data['suspended_to']) ? (string)$data['suspended_to'] : null;
        if (($suspendedFrom !== null) !== ($suspendedTo !== null)) {
            return ['status' => false, 'message' => 'Both a suspend start date and end date are required to suspend deduction.'];
        }
        if ($suspendedFrom !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $suspendedFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $suspendedTo)) {
                return ['status' => false, 'message' => 'Invalid suspend date.'];
            }
            if ($suspendedTo < $suspendedFrom) {
                return ['status' => false, 'message' => 'Suspend end date must be on or after the start date.'];
            }
        }

        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        $dupSql = "SELECT id FROM `employee_recurring_deductions`
            WHERE employee_id = :employee_id AND ped_type_id = :ped_type_id AND status = 'active' AND deleted_at IS NULL";
        $dupParams = [':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId];
        if ($id !== null) {
            $dupSql .= " AND id != :id";
            $dupParams[':id'] = $id;
        }
        $dupStmt = $this->db->prepare($dupSql);
        $dupStmt->execute($dupParams);
        if ($dupStmt->fetch()) {
            return ['status' => false, 'message' => 'This employee already has an active assignment of this deduction type.'];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `employee_recurring_deductions` WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId]);
                if (!$stmtCheck->fetch()) {
                    if ($own) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE `employee_recurring_deductions` SET
                        ped_type_id = :ped_type_id, amount = :amount, fee_percent = :fee_percent, fee_base = :fee_base, effective_date = :effective_date,
                        suspended_from = :suspended_from, suspended_to = :suspended_to, notes = :notes,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $stmt->execute([
                    ':ped_type_id' => $pedTypeId, ':amount' => $amount, ':fee_percent' => $feePercent, ':fee_base' => $feeBase, ':effective_date' => $effectiveDate,
                    ':suspended_from' => $suspendedFrom, ':suspended_to' => $suspendedTo, ':notes' => $notes,
                    ':updated_by' => $userId, ':id' => $id,
                ]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO `employee_recurring_deductions`
                        (employee_id, ped_type_id, amount, fee_percent, fee_base, effective_date, suspended_from, suspended_to, notes, status, created_by)
                    VALUES (:employee_id, :ped_type_id, :amount, :fee_percent, :fee_base, :effective_date, :suspended_from, :suspended_to, :notes, 'active', :created_by)");
                $stmt->execute([
                    ':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId, ':amount' => $amount, ':fee_percent' => $feePercent, ':fee_base' => $feeBase, ':effective_date' => $effectiveDate,
                    ':suspended_from' => $suspendedFrom, ':suspended_to' => $suspendedTo, ':notes' => $notes, ':created_by' => $userId,
                ]);
                $id = (int)$this->db->lastInsertId();
            }
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.', 'id' => $id];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $id, int $compId, int $employeeId, int $userId): array {
        $stmtCheck = $this->db->prepare("SELECT erd.id FROM `employee_recurring_deductions` erd
            JOIN `employees` e ON e.id = erd.employee_id
            WHERE erd.id = :id AND erd.employee_id = :employee_id AND e.comp_id = :comp_id AND erd.deleted_at IS NULL");
        $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE `employee_recurring_deductions` SET status = 'deleted', deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    /**
     * Rows to include in a payroll run whose pay period is [$periodStart, $periodEnd] -- excludes
     * anything not yet effective, and anything whose suspend window overlaps the period at all.
     * Called from PayrollRunModel::recalculate(). Mirrors
     * EmployeeRecurringEarningModel::activeForPeriod() exactly.
     * Returns fee_percent/fee_base too (2026-08-31) -- PayrollRunModel::recalculate() is the one
     * place that actually computes fee_percent% of the employee's CURRENT base_salary_amount and
     * adds it to `amount`, fresh every run (not baked into a stored value here), since base salary
     * can change over time and this is an indefinitely-recurring item, not a one-time snapshot.
     */
    public function activeForPeriod(int $employeeId, string $periodStart, string $periodEnd): array {
        $stmt = $this->db->prepare("SELECT erd.id AS recurring_id, erd.amount, erd.fee_percent, erd.fee_base, pt.item_code, pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_deductions` erd
            JOIN `payroll_earning_deduction_types` pt ON pt.id = erd.ped_type_id
            WHERE erd.employee_id = :employee_id AND erd.status = 'active' AND erd.deleted_at IS NULL
              AND erd.effective_date <= :period_end
              AND NOT (erd.suspended_from IS NOT NULL AND erd.suspended_to IS NOT NULL
                       AND erd.suspended_from <= :period_end2 AND erd.suspended_to >= :period_start)
            ORDER BY erd.id ASC");
        $stmt->execute([
            ':employee_id' => $employeeId, ':period_end' => $periodEnd,
            ':period_end2' => $periodEnd, ':period_start' => $periodStart,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
