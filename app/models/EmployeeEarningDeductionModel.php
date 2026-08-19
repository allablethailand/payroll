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
                    pt.source_event_code
                FROM `employee_earning_deductions` eed
                LEFT JOIN `payroll_earning_deduction_types` pt ON eed.ped_type_id = pt.id
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
                    pt.calculation_method AS ped_calculation_method
                FROM `employee_earning_deductions` eed
                LEFT JOIN `payroll_earning_deduction_types` pt ON eed.ped_type_id = pt.id
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

    private function buildInstallmentAmounts(string $amountMode, float $totalAmount, int $totalInstallments, array $customAmounts): array {
        if ($amountMode === 'custom_per_installment') {
            return array_map(fn($v) => round((float)$v, 2), $customAmounts);
        }
        $base = round($totalAmount / $totalInstallments, 2);
        $amounts = array_fill(0, $totalInstallments, $base);
        $remainder = round($totalAmount - ($base * $totalInstallments), 2);
        $amounts[$totalInstallments - 1] = round($amounts[$totalInstallments - 1] + $remainder, 2);
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
        if (!empty($data['ped_type_id'])) {
            $pedTypeId = (int)$data['ped_type_id'];
            $stmtType = $this->db->prepare("SELECT id FROM `payroll_earning_deduction_types` WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND is_sync_only = 0 AND deleted_at IS NULL");
            $stmtType->execute([':id' => $pedTypeId, ':comp_id' => $compId]);
            if (!$stmtType->fetch()) {
                return ['status' => false, 'message' => 'Invalid or inactive payroll item selected.'];
            }
        } elseif (!empty($data['custom_item_name']) && !empty($data['custom_item_type'])) {
            if (!in_array($data['custom_item_type'], ['earning', 'deduction'], true)) {
                return ['status' => false, 'message' => 'Invalid custom_item_type.'];
            }
            $customItemName = trim((string)$data['custom_item_name']);
            $customItemType = (string)$data['custom_item_type'];
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

        $effectiveDate = (string)$data['effective_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date.'];
        }

        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;
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
                            amount_mode = :amount_mode, total_amount = :total_amount,
                            effective_date = :effective_date, notes = :notes, external_reference_no = :external_reference_no,
                            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':ped_type_id' => $pedTypeId,
                    ':custom_item_name' => $customItemName,
                    ':custom_item_type' => $customItemType,
                    ':total_installments' => $totalInstallments,
                    ':amount_mode' => $amountMode,
                    ':total_amount' => $totalAmount,
                    ':effective_date' => $effectiveDate,
                    ':notes' => $notes,
                    ':external_reference_no' => $externalReferenceNo,
                    ':updated_by' => $userId,
                    ':id' => $id,
                ]);

                $delStmt = $this->db->prepare("DELETE FROM `employee_earning_deduction_installments` WHERE assignment_id = :assignment_id");
                $delStmt->execute([':assignment_id' => $id]);
                $assignmentId = $id;
            } else {
                $sql = "INSERT INTO `employee_earning_deductions`
                            (employee_id, ped_type_id, custom_item_name, custom_item_type, total_installments, current_installment, amount_mode, total_amount, effective_date, status, notes, external_reference_no, created_by)
                        VALUES
                            (:employee_id, :ped_type_id, :custom_item_name, :custom_item_type, :total_installments, 0, :amount_mode, :total_amount, :effective_date, 'active', :notes, :external_reference_no, :created_by)";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':ped_type_id' => $pedTypeId,
                    ':custom_item_name' => $customItemName,
                    ':custom_item_type' => $customItemType,
                    ':total_installments' => $totalInstallments,
                    ':amount_mode' => $amountMode,
                    ':total_amount' => $totalAmount,
                    ':effective_date' => $effectiveDate,
                    ':notes' => $notes,
                    ':external_reference_no' => $externalReferenceNo,
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
