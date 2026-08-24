<?php
declare(strict_types=1);
class EmployeeEarningDeductionModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    private function employeeBelongsToComp(int $employeeId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    /** $itemType: optional 'earning'/'deduction' filter -- COALESCE against custom_item_type so a
     *  custom row is filtered by its own declared type, not left out just because it has no
     *  ped_type_id to join a catalog item_type from. */
    public function list(int $employeeId, int $compId, ?string $itemType = null): array {
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return [];
        }
        $sql = "SELECT eed.*, pt.item_code,
                    COALESCE(pt.item_name_th, eed.custom_item_name) AS item_name_th,
                    COALESCE(pt.item_name_en, eed.custom_item_name) AS item_name_en,
                    COALESCE(pt.item_type, eed.custom_item_type) AS item_type,
                    pt.source_event_code,
                    payee.employee_no AS payee_employee_no
                FROM `employee_earning_deductions` eed
                LEFT JOIN `payroll_earning_deduction_types` pt ON eed.ped_type_id = pt.id
                LEFT JOIN `employees` payee ON payee.id = eed.payee_employee_id
                WHERE eed.employee_id = :employee_id AND eed.deleted_at IS NULL";
        $params = [':employee_id' => $employeeId];
        if ($itemType !== null && $itemType !== '') {
            $sql .= " AND COALESCE(pt.item_type, eed.custom_item_type) = :item_type";
            $params[':item_type'] = $itemType;
        }
        $sql .= " ORDER BY eed.effective_date DESC, eed.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $sql = "SELECT eed.*, pt.item_code,
                    COALESCE(pt.item_name_th, eed.custom_item_name) AS item_name_th,
                    COALESCE(pt.item_name_en, eed.custom_item_name) AS item_name_en,
                    COALESCE(pt.item_type, eed.custom_item_type) AS item_type,
                    pt.calculation_method AS ped_calculation_method,
                    payee.employee_no AS payee_employee_no
                FROM `employee_earning_deductions` eed
                LEFT JOIN `payroll_earning_deduction_types` pt ON eed.ped_type_id = pt.id
                LEFT JOIN `employees` payee ON payee.id = eed.payee_employee_id
                JOIN `employees` e ON eed.employee_id = e.id
                WHERE eed.id = :id AND e.comp_id = :comp_id AND eed.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $stmtInst = $this->db->prepare("SELECT * FROM `employee_earning_deduction_installments` WHERE assignment_id = :assignment_id ORDER BY installment_no ASC");
        $stmtInst->execute([':assignment_id' => $id]);
        $row['installments'] = $stmtInst->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function activeOptions(int $compId, string $search, int $page, int $limit, ?string $itemType = null): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' AND is_sync_only = 0";
        $params = [':comp_id' => $compId];
        if ($itemType !== null && $itemType !== '') {
            $where .= " AND item_type = :item_type";
            $params[':item_type'] = $itemType;
        }
        if ($search !== '') {
            $where .= " AND (item_code LIKE :search1 OR item_name_th LIKE :search2 OR item_name_en LIKE :search3)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_earning_deduction_types` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id,
                    CONCAT('[', item_code, '] ', item_name_th) AS text_th,
                    CONCAT('[', item_code, '] ', item_name_en) AS text_en,
                    item_type
                FROM `payroll_earning_deduction_types` {$where}
                ORDER BY item_type ASC, item_code ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    /** Splits $totalAmount evenly across $totalInstallments, dumping the rounding remainder into
     *  the last installment so the sum always exactly equals $totalAmount. */
    private function evenSplit(float $totalAmount, int $totalInstallments): array {
        $base = round($totalAmount / $totalInstallments, 2);
        $amounts = array_fill(0, $totalInstallments, $base);
        $remainder = round($totalAmount - ($base * $totalInstallments), 2);
        $amounts[$totalInstallments - 1] = round($amounts[$totalInstallments - 1] + $remainder, 2);
        return $amounts;
    }

    private function buildInstallmentAmounts(string $amountMode, float $totalAmount, int $totalInstallments, array $customAmounts): array {
        if ($amountMode === 'custom_per_installment') {
            return array_map(fn($v) => round((float)$v, 2), $customAmounts);
        }
        return $this->evenSplit($totalAmount, $totalInstallments);
    }

    /** Pure calculation, no DB access -- computes the per-installment amount schedule for a loan-
     *  style deduction (2026-08-20, explicit request: "กำหนดได้ค่าคิดดอกเบี้ยหรือไม่คิดดอกเบี้ย...
     *  คงที่ ลดต้นลดดอก"). $interestRatePercent is a % PER INSTALLMENT PERIOD, not annual -- nothing
     *  in this schema ties an assignment to its payroll_cycles.payroll_frequency, so there's no
     *  reliable way to convert an annual rate without guessing; per-period keeps the math
     *  unambiguous (see the matching comment on the interest_type/interest_rate columns).
     *  'fixed' = flat/add-on interest (totalInterest = principal * rate * n, spread evenly).
     *  'reducing_balance' = standard amortized-payment schedule (equal total payments, but the
     *  principal/interest split shifts each period) -- the LAST installment is deliberately
     *  "whatever balance remains + interest on it" rather than the regular payment amount, so the
     *  schedule always exactly zeroes out regardless of per-installment rounding drift. */
    public function computeInstallmentSchedule(float $principal, int $totalInstallments, string $interestType, ?float $interestRatePercent): array {
        if ($principal <= 0) {
            throw new InvalidArgumentException('principal must be greater than zero.');
        }
        if ($totalInstallments < 1) {
            throw new InvalidArgumentException('total_installments must be at least 1.');
        }
        if (!in_array($interestType, ['none', 'fixed', 'reducing_balance'], true)) {
            throw new InvalidArgumentException('Invalid interest_type.');
        }
        if ($interestType === 'none') {
            return $this->evenSplit($principal, $totalInstallments);
        }
        if ($interestRatePercent === null || $interestRatePercent <= 0) {
            throw new InvalidArgumentException('interest_rate must be greater than zero when interest_type is not none.');
        }
        $r = $interestRatePercent / 100;

        if ($interestType === 'fixed') {
            $totalInterest = $principal * $r * $totalInstallments;
            return $this->evenSplit($principal + $totalInterest, $totalInstallments);
        }

        // reducing_balance
        $payment = $principal * $r / (1 - (1 + $r) ** (-$totalInstallments));
        $amounts = [];
        $balance = $principal;
        for ($i = 0; $i < $totalInstallments - 1; $i++) {
            $installmentInterest = $balance * $r;
            $roundedPayment = round($payment, 2);
            $principalPortion = $roundedPayment - $installmentInterest;
            $balance -= $principalPortion;
            $amounts[] = $roundedPayment;
        }
        $amounts[] = round($balance + ($balance * $r), 2);
        return $amounts;
    }

    public function save(int $employeeId, int $compId, array $data, int $userId): array {
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['total_installments', 'amount_mode', 'effective_date'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        // Either a catalog reference (ped_type_id) OR a free-text item (custom_item_name +
        // custom_item_type) -- explicit request ("Item ให้สามารถใส่เองได้ โดยบอกว่าเป็นรายได้หรือ
        // รายหัก"), same either/or shape as PayrollRunModel::addManualLine()'s custom items.
        $pedTypeId = null;
        $customItemName = null;
        $customItemType = null;
        $resolvedItemType = null;
        if (!empty($data['ped_type_id'])) {
            $pedTypeId = (int)$data['ped_type_id'];
            $stmtType = $this->db->prepare("SELECT id, item_type FROM `payroll_earning_deduction_types` WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND is_sync_only = 0 AND deleted_at IS NULL");
            $stmtType->execute([':id' => $pedTypeId, ':comp_id' => $compId]);
            $pedType = $stmtType->fetch(PDO::FETCH_ASSOC);
            if (!$pedType) {
                return ['status' => false, 'message' => 'Invalid or inactive payroll item selected.'];
            }
            $resolvedItemType = (string)$pedType['item_type'];
        } elseif (!empty($data['custom_item_name']) && !empty($data['custom_item_type'])) {
            if (!in_array($data['custom_item_type'], ['earning', 'deduction'], true)) {
                return ['status' => false, 'message' => 'Invalid custom_item_type.'];
            }
            $customItemName = trim((string)$data['custom_item_name']);
            $customItemType = (string)$data['custom_item_type'];
            $resolvedItemType = $customItemType;
        } else {
            return ['status' => false, 'message' => 'Select an item from the list, or enter a custom item name and type.'];
        }

        $totalInstallments = (int)$data['total_installments'];
        if ($totalInstallments < 1) {
            return ['status' => false, 'message' => 'Total installments must be at least 1.'];
        }

        $amountMode = (string)$data['amount_mode'];
        if (!in_array($amountMode, ['even_split', 'custom_per_installment'], true)) {
            return ['status' => false, 'message' => 'Invalid amount_mode.'];
        }

        $customAmounts = [];
        $totalAmount = 0.0;
        if ($amountMode === 'even_split') {
            if (!isset($data['total_amount']) || !is_numeric($data['total_amount']) || (float)$data['total_amount'] <= 0) {
                return ['status' => false, 'message' => 'Missing or invalid field: total_amount'];
            }
            $totalAmount = (float)$data['total_amount'];
        } else {
            $customAmounts = is_array($data['installment_amounts'] ?? null) ? $data['installment_amounts'] : [];
            if (count($customAmounts) !== $totalInstallments) {
                return ['status' => false, 'message' => 'installment_amounts must have exactly total_installments entries.'];
            }
            foreach ($customAmounts as $amt) {
                if (!is_numeric($amt) || (float)$amt < 0) {
                    return ['status' => false, 'message' => 'Each installment amount must be a non-negative number.'];
                }
            }
            $totalAmount = array_sum(array_map('floatval', $customAmounts));
            if ($totalAmount <= 0) {
                return ['status' => false, 'message' => 'Total of installment amounts must be greater than zero.'];
            }
        }

        // Interest (2026-08-20, explicit request). The schedule itself (installment_amounts,
        // above) is always taken from the client verbatim same as before -- these three fields
        // are stored purely as metadata about how that schedule was derived, not recomputed here.
        // See computeInstallmentSchedule()'s docblock for why interest_rate is per-period, not
        // annual, and the ALTER TABLE comment in database/payroll.sql for principal_amount vs
        // total_amount's split meaning.
        $interestType = !empty($data['interest_type']) ? (string)$data['interest_type'] : 'none';
        if (!in_array($interestType, ['none', 'fixed', 'reducing_balance'], true)) {
            return ['status' => false, 'message' => 'Invalid interest_type.'];
        }
        // 2026-08-21, explicit request ("รายรับให้ตัดเรื่องดอกเบี้ยไปเลย มีแค่รายหักที่บอกว่าคิดหรือ
        // ไม่คิดดอกเบี้ย") -- interest never applies to an earning. The modal already hides the whole
        // interest section for earnings (applyEedInterestVisibility() in detail.js), this is the
        // server-side backstop against a malformed/direct API call.
        if ($interestType !== 'none' && $resolvedItemType === 'earning') {
            return ['status' => false, 'message' => 'Interest is not applicable to earning items.'];
        }
        $interestRate = null;
        if ($interestType !== 'none') {
            if (!isset($data['interest_rate']) || !is_numeric($data['interest_rate']) || (float)$data['interest_rate'] <= 0) {
                return ['status' => false, 'message' => 'interest_rate must be greater than zero when interest_type is not none.'];
            }
            $interestRate = round((float)$data['interest_rate'], 2);
        }
        $principalAmount = (isset($data['principal_amount']) && is_numeric($data['principal_amount']) && (float)$data['principal_amount'] > 0)
            ? round((float)$data['principal_amount'], 2)
            : $totalAmount;

        $effectiveDate = (string)$data['effective_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date.'];
        }

        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;

        // Transfer-to-payee (2026-08-21, explicit request: "หักเพื่อไปจ่ายให้ใคร โดยเลือกพนักงานได้ว่า
        // จะหักของคนนี้ไปให้คนนี้") -- only meaningful on a deduction; forced null (not an error) for
        // an earning, same as interest_type being forced to 'none' above. Wired into
        // PayrollRunModel::recalculate()'s transfer-credit pass -- see that method's own docblock.
        $payeeEmployeeId = null;
        if (!empty($data['payee_employee_id']) && $resolvedItemType === 'deduction') {
            $payeeEmployeeId = (int)$data['payee_employee_id'];
            if ($payeeEmployeeId === $employeeId) {
                return ['status' => false, 'message' => 'An employee cannot be their own transfer payee.'];
            }
            if (!$this->employeeBelongsToComp($payeeEmployeeId, $compId)) {
                return ['status' => false, 'message' => 'Invalid payee employee.'];
            }
        }
        $externalReferenceNo = !empty($data['external_reference_no']) ? trim((string)$data['external_reference_no']) : null;

        $installmentAmounts = $this->buildInstallmentAmounts($amountMode, $totalAmount, $totalInstallments, $customAmounts);

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }

            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT eed.id, eed.current_installment FROM `employee_earning_deductions` eed
                    JOIN `employees` e ON eed.employee_id = e.id
                    WHERE eed.id = :id AND eed.employee_id = :employee_id AND e.comp_id = :comp_id AND eed.deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId, ':comp_id' => $compId]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    if ($own) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                if ((int)$existing['current_installment'] > 0) {
                    if ($own) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'This assignment has already started processing installments and can no longer be edited. Use pause/cancel instead.'];
                }

                $sql = "UPDATE `employee_earning_deductions` SET
                            ped_type_id = :ped_type_id, custom_item_name = :custom_item_name, custom_item_type = :custom_item_type,
                            total_installments = :total_installments,
                            amount_mode = :amount_mode, interest_type = :interest_type, interest_rate = :interest_rate,
                            total_amount = :total_amount, principal_amount = :principal_amount,
                            effective_date = :effective_date, notes = :notes, external_reference_no = :external_reference_no,
                            payee_employee_id = :payee_employee_id,
                            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':ped_type_id' => $pedTypeId,
                    ':custom_item_name' => $customItemName,
                    ':custom_item_type' => $customItemType,
                    ':total_installments' => $totalInstallments,
                    ':amount_mode' => $amountMode,
                    ':interest_type' => $interestType,
                    ':interest_rate' => $interestRate,
                    ':total_amount' => $totalAmount,
                    ':principal_amount' => $principalAmount,
                    ':effective_date' => $effectiveDate,
                    ':notes' => $notes,
                    ':external_reference_no' => $externalReferenceNo,
                    ':payee_employee_id' => $payeeEmployeeId,
                    ':updated_by' => $userId,
                    ':id' => $id,
                ]);

                $delStmt = $this->db->prepare("DELETE FROM `employee_earning_deduction_installments` WHERE assignment_id = :assignment_id");
                $delStmt->execute([':assignment_id' => $id]);
                $assignmentId = $id;
            } else {
                $sql = "INSERT INTO `employee_earning_deductions`
                            (employee_id, ped_type_id, custom_item_name, custom_item_type, total_installments, current_installment, amount_mode, interest_type, interest_rate, total_amount, principal_amount, effective_date, status, notes, external_reference_no, payee_employee_id, created_by)
                        VALUES
                            (:employee_id, :ped_type_id, :custom_item_name, :custom_item_type, :total_installments, 0, :amount_mode, :interest_type, :interest_rate, :total_amount, :principal_amount, :effective_date, 'active', :notes, :external_reference_no, :payee_employee_id, :created_by)";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':ped_type_id' => $pedTypeId,
                    ':custom_item_name' => $customItemName,
                    ':custom_item_type' => $customItemType,
                    ':total_installments' => $totalInstallments,
                    ':amount_mode' => $amountMode,
                    ':interest_type' => $interestType,
                    ':interest_rate' => $interestRate,
                    ':total_amount' => $totalAmount,
                    ':principal_amount' => $principalAmount,
                    ':effective_date' => $effectiveDate,
                    ':notes' => $notes,
                    ':external_reference_no' => $externalReferenceNo,
                    ':payee_employee_id' => $payeeEmployeeId,
                    ':created_by' => $userId,
                ]);
                $assignmentId = (int)$this->db->lastInsertId();
            }

            $insStmt = $this->db->prepare("INSERT INTO `employee_earning_deduction_installments` (assignment_id, installment_no, amount, status) VALUES (:assignment_id, :installment_no, :amount, 'pending')");
            foreach ($installmentAmounts as $idx => $amount) {
                $insStmt->execute([
                    ':assignment_id' => $assignmentId,
                    ':installment_no' => $idx + 1,
                    ':amount' => $amount,
                ]);
            }

            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => $id !== null ? 'Updated successfully.' : 'Created successfully.', 'id' => $assignmentId];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function updateStatus(int $id, int $compId, int $employeeId, string $newStatus, int $userId): array {
        if (!in_array($newStatus, ['active', 'paused', 'cancelled'], true)) {
            return ['status' => false, 'message' => 'Invalid status.'];
        }
        $stmtCheck = $this->db->prepare("SELECT eed.id, eed.status FROM `employee_earning_deductions` eed
            JOIN `employees` e ON eed.employee_id = e.id
            WHERE eed.id = :id AND eed.employee_id = :employee_id AND e.comp_id = :comp_id AND eed.deleted_at IS NULL");
        $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId, ':comp_id' => $compId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $currentStatus = (string)$existing['status'];
        if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
            return ['status' => false, 'message' => 'This assignment is already completed or cancelled and cannot change status.'];
        }
        if (!in_array($currentStatus, ['active', 'paused'], true)) {
            return ['status' => false, 'message' => 'Invalid current status for this operation.'];
        }

        $stmt = $this->db->prepare("UPDATE `employee_earning_deductions` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Status updated successfully.'];
    }

    public function delete(int $id, int $compId, int $employeeId, int $userId): array {
        $stmtCheck = $this->db->prepare("SELECT eed.id, eed.current_installment FROM `employee_earning_deductions` eed
            JOIN `employees` e ON eed.employee_id = e.id
            WHERE eed.id = :id AND eed.employee_id = :employee_id AND e.comp_id = :comp_id AND eed.deleted_at IS NULL");
        $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId, ':comp_id' => $compId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ((int)$existing['current_installment'] > 0) {
            return ['status' => false, 'message' => 'This assignment has already started processing installments and cannot be deleted. Use cancel instead.'];
        }
        $stmt = $this->db->prepare("UPDATE `employee_earning_deductions` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
        $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }
}
