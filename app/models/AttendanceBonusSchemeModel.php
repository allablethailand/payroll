<?php
declare(strict_types=1);
class AttendanceBonusSchemeModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    public function list(int $compId): array {
        $stmt = $this->db->prepare("SELECT * FROM `attendance_bonus_schemes` WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY id ASC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `attendance_bonus_schemes` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isSchemeNameDuplicate(int $compId, string $name, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `attendance_bonus_schemes` WHERE comp_id = :comp_id AND scheme_name = :scheme_name AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':scheme_name' => $name];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['scheme_name', 'starting_amount', 'reset_cycle_months', 'reset_cycle_basis'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $schemeName = trim((string)$data['scheme_name']);
        if ($this->isSchemeNameDuplicate($compId, $schemeName, $id)) {
            return ['status' => false, 'message' => 'This scheme name is already in use.'];
        }

        $conditionNoAbsent = !empty($data['condition_no_absent']) ? 1 : 0;
        $conditionNoLate = !empty($data['condition_no_late']) ? 1 : 0;
        $conditionNoLeave = !empty($data['condition_no_leave']) ? 1 : 0;
        $conditionNoTimeAdjust = !empty($data['condition_no_time_adjust']) ? 1 : 0;
        if (!$conditionNoAbsent && !$conditionNoLate && !$conditionNoLeave && !$conditionNoTimeAdjust) {
            return ['status' => false, 'message' => 'Select at least one eligibility condition.'];
        }

        if (!is_numeric($data['starting_amount']) || (float)$data['starting_amount'] < 0) {
            return ['status' => false, 'message' => 'Starting amount must be a non-negative number.'];
        }
        $startingAmount = (float)$data['starting_amount'];

        $incrementInput = $data['increment_amount'] ?? 0;
        if (!is_numeric($incrementInput) || (float)$incrementInput < 0) {
            return ['status' => false, 'message' => 'Increment amount must be a non-negative number.'];
        }
        $incrementAmount = (float)$incrementInput;

        $maxAmount = null;
        if (isset($data['max_amount']) && $data['max_amount'] !== '') {
            if (!is_numeric($data['max_amount']) || (float)$data['max_amount'] < $startingAmount) {
                return ['status' => false, 'message' => 'Max amount must be a number greater than or equal to the starting amount.'];
            }
            $maxAmount = (float)$data['max_amount'];
        }

        if (!is_numeric($data['reset_cycle_months']) || (int)$data['reset_cycle_months'] < 1 || (int)$data['reset_cycle_months'] > 60) {
            return ['status' => false, 'message' => 'Reset cycle must be between 1-60 months.'];
        }
        $resetCycleMonths = (int)$data['reset_cycle_months'];

        $resetCycleBasis = (string)$data['reset_cycle_basis'];
        if (!in_array($resetCycleBasis, ['employee_anniversary', 'fixed_month'], true)) {
            return ['status' => false, 'message' => 'Invalid reset_cycle_basis.'];
        }

        $resetCycleStartMonth = null;
        if ($resetCycleBasis === 'fixed_month') {
            if (!isset($data['reset_cycle_start_month']) || !is_numeric($data['reset_cycle_start_month'])) {
                return ['status' => false, 'message' => 'Missing required field: reset_cycle_start_month'];
            }
            $resetCycleStartMonth = (int)$data['reset_cycle_start_month'];
            if ($resetCycleStartMonth < 1 || $resetCycleStartMonth > 12) {
                return ['status' => false, 'message' => 'reset_cycle_start_month must be between 1-12.'];
            }
        }

        $statusInput = $data['status'] ?? 'active';
        $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';

        $params = [
            ':scheme_name' => $schemeName,
            ':condition_no_absent' => $conditionNoAbsent,
            ':condition_no_late' => $conditionNoLate,
            ':condition_no_leave' => $conditionNoLeave,
            ':condition_no_time_adjust' => $conditionNoTimeAdjust,
            ':starting_amount' => $startingAmount,
            ':increment_amount' => $incrementAmount,
            ':max_amount' => $maxAmount,
            ':reset_cycle_months' => $resetCycleMonths,
            ':reset_cycle_basis' => $resetCycleBasis,
            ':reset_cycle_start_month' => $resetCycleStartMonth,
            ':status' => $status,
        ];

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `attendance_bonus_schemes` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $sql = "UPDATE `attendance_bonus_schemes` SET
                            scheme_name = :scheme_name,
                            condition_no_absent = :condition_no_absent, condition_no_late = :condition_no_late,
                            condition_no_leave = :condition_no_leave, condition_no_time_adjust = :condition_no_time_adjust,
                            starting_amount = :starting_amount, increment_amount = :increment_amount, max_amount = :max_amount,
                            reset_cycle_months = :reset_cycle_months, reset_cycle_basis = :reset_cycle_basis, reset_cycle_start_month = :reset_cycle_start_month,
                            status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `attendance_bonus_schemes`
                        (comp_id, scheme_name, condition_no_absent, condition_no_late, condition_no_leave, condition_no_time_adjust,
                         starting_amount, increment_amount, max_amount, reset_cycle_months, reset_cycle_basis, reset_cycle_start_month, status, created_by)
                    VALUES
                        (:comp_id, :scheme_name, :condition_no_absent, :condition_no_late, :condition_no_leave, :condition_no_time_adjust,
                         :starting_amount, :increment_amount, :max_amount, :reset_cycle_months, :reset_cycle_basis, :reset_cycle_start_month, :status, :created_by)";
            $params[':comp_id'] = $compId;
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $compId, int $id, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `attendance_bonus_schemes` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `attendance_bonus_schemes` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
