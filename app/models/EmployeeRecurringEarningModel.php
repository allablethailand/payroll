<?php
declare(strict_types=1);

/**
 * Recurring monthly earning allowances (position/car/fuel allowance, etc.) assigned per employee --
 * 2026-08-26, explicit request: "ส่วนของเงินเดือนในหน้าจัดการข้อมูลพนักงาน จะมีรายรับที่ได้ทุกเดือนเช่นพวก
 * ค่าตำแหน่ง ค่ารถ ค่าน้ำมัน และอื่นๆ ให้เพิ่มส่วนนี้เข้าไปด้วย และระงับการจ่ายได้ รวมถึงการตั้งค่าส่วนนี้เพิ่มเติมให้นำไป
 * คำนวณในรอบการจ่ายด้วย".
 *
 * Deliberately a NEW table (`employee_recurring_earnings`), not a new mode of
 * `employee_earning_deductions` -- that table is inherently installment-based
 * (total_installments/current_installment NOT NULL, a fixed total amount split across a finite
 * schedule) and genuinely doesn't fit an allowance that recurs indefinitely with no end date.
 * Confirmed via AskUserQuestion: this lives as its OWN new section on the Employee Detail Salary
 * tab (not folded into the Earning-Deduction tab, which stays loan/installment-only).
 *
 * Reuses the EXISTING `payroll_earning_deduction_types` catalog (Payroll Configuration > Earning-
 * Deduction Types) for "what kind of allowance" rather than a new master table or free text --
 * restricted to item_type='earning' AND calculation_method='fixed_amount' (this feature is
 * specifically "enter this employee's own flat monthly amount", not a percentage-of-base-salary or
 * manual-entry-per-run item).
 *
 * "Suspend" (confirmed via AskUserQuestion: a date RANGE, not a plain on/off toggle) is
 * `suspended_from`/`suspended_to` -- both set together or neither. `activeForPeriod()` (called from
 * PayrollRunModel::recalculate()'s own "Recurring earnings" section) excludes a row from a run
 * whenever that run's pay period overlaps the suspend window at all, and automatically resumes once
 * the run's period moves past `suspended_to` -- no separate re-activation step needed.
 */
class EmployeeRecurringEarningModel {
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
        $stmt = $this->db->prepare("SELECT ere.*, pt.item_code, pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_earnings` ere
            JOIN `payroll_earning_deduction_types` pt ON pt.id = ere.ped_type_id
            JOIN `employees` e ON e.id = ere.employee_id
            WHERE ere.employee_id = :employee_id AND e.comp_id = :comp_id AND ere.status = 'active' AND ere.deleted_at IS NULL
            ORDER BY ere.effective_date DESC, ere.id DESC");
        $stmt->execute([':employee_id' => $employeeId, ':comp_id' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Display-only convenience so the UI doesn't have to re-derive today-vs-range itself --
        // PayrollRunModel's own activeForPeriod() below does the real per-run check independently.
        $today = date('Y-m-d');
        foreach ($rows as &$row) {
            $row['is_suspended_now'] = $row['suspended_from'] !== null && $row['suspended_to'] !== null
                && $row['suspended_from'] <= $today && $row['suspended_to'] >= $today;
        }
        unset($row);
        return $rows;
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT ere.*, pt.item_code, pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_earnings` ere
            JOIN `payroll_earning_deduction_types` pt ON pt.id = ere.ped_type_id
            JOIN `employees` e ON e.id = ere.employee_id
            WHERE ere.id = :id AND e.comp_id = :comp_id AND ere.deleted_at IS NULL");
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
            WHERE id = :id AND comp_id = :comp_id AND item_type = 'earning' AND calculation_method = 'fixed_amount'
              AND is_sync_only = 0 AND status = 'active' AND deleted_at IS NULL");
        $stmtType->execute([':id' => $pedTypeId, ':comp_id' => $compId]);
        if (!$stmtType->fetch()) {
            return ['status' => false, 'message' => 'Invalid or inactive allowance type selected.'];
        }

        if (!isset($data['amount']) || !is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
            return ['status' => false, 'message' => 'Amount must be greater than zero.'];
        }
        $amount = round((float)$data['amount'], 2);

        $effectiveDate = (string)($data['effective_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date.'];
        }

        // "Suspend" is a date RANGE, both-or-neither -- see class docblock.
        $suspendedFrom = !empty($data['suspended_from']) ? (string)$data['suspended_from'] : null;
        $suspendedTo = !empty($data['suspended_to']) ? (string)$data['suspended_to'] : null;
        if (($suspendedFrom !== null) !== ($suspendedTo !== null)) {
            return ['status' => false, 'message' => 'Both a suspend start date and end date are required to suspend payment.'];
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

        // App-layer duplicate re-check (deleted_at-aware unique, per this project's own convention):
        // one active assignment per (employee, allowance type) at a time -- editing an existing
        // row's own id is excluded from the check.
        $dupSql = "SELECT id FROM `employee_recurring_earnings`
            WHERE employee_id = :employee_id AND ped_type_id = :ped_type_id AND status = 'active' AND deleted_at IS NULL";
        $dupParams = [':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId];
        if ($id !== null) {
            $dupSql .= " AND id != :id";
            $dupParams[':id'] = $id;
        }
        $dupStmt = $this->db->prepare($dupSql);
        $dupStmt->execute($dupParams);
        if ($dupStmt->fetch()) {
            return ['status' => false, 'message' => 'This employee already has an active assignment of this allowance type.'];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `employee_recurring_earnings` WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId]);
                if (!$stmtCheck->fetch()) {
                    if ($own) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE `employee_recurring_earnings` SET
                        ped_type_id = :ped_type_id, amount = :amount, effective_date = :effective_date,
                        suspended_from = :suspended_from, suspended_to = :suspended_to, notes = :notes,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $stmt->execute([
                    ':ped_type_id' => $pedTypeId, ':amount' => $amount, ':effective_date' => $effectiveDate,
                    ':suspended_from' => $suspendedFrom, ':suspended_to' => $suspendedTo, ':notes' => $notes,
                    ':updated_by' => $userId, ':id' => $id,
                ]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO `employee_recurring_earnings`
                        (employee_id, ped_type_id, amount, effective_date, suspended_from, suspended_to, notes, status, created_by)
                    VALUES (:employee_id, :ped_type_id, :amount, :effective_date, :suspended_from, :suspended_to, :notes, 'active', :created_by)");
                $stmt->execute([
                    ':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId, ':amount' => $amount, ':effective_date' => $effectiveDate,
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
        $stmtCheck = $this->db->prepare("SELECT ere.id FROM `employee_recurring_earnings` ere
            JOIN `employees` e ON e.id = ere.employee_id
            WHERE ere.id = :id AND ere.employee_id = :employee_id AND e.comp_id = :comp_id AND ere.deleted_at IS NULL");
        $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE `employee_recurring_earnings` SET status = 'deleted', deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    /**
     * Rows to include in a payroll run whose pay period is [$periodStart, $periodEnd] -- excludes
     * anything not yet effective, and anything whose suspend window (suspended_from/suspended_to,
     * both-or-neither) overlaps the period at all. Called from PayrollRunModel::recalculate().
     */
    public function activeForPeriod(int $employeeId, string $periodStart, string $periodEnd): array {
        $stmt = $this->db->prepare("SELECT ere.id AS recurring_id, ere.amount, pt.item_code, pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_earnings` ere
            JOIN `payroll_earning_deduction_types` pt ON pt.id = ere.ped_type_id
            WHERE ere.employee_id = :employee_id AND ere.status = 'active' AND ere.deleted_at IS NULL
              AND ere.effective_date <= :period_end
              AND NOT (ere.suspended_from IS NOT NULL AND ere.suspended_to IS NOT NULL
                       AND ere.suspended_from <= :period_end2 AND ere.suspended_to >= :period_start)
            ORDER BY ere.id ASC");
        $stmt->execute([
            ':employee_id' => $employeeId, ':period_end' => $periodEnd,
            ':period_end2' => $periodEnd, ':period_start' => $periodStart,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
