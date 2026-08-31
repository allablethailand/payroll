<?php
declare(strict_types=1);

/**
 * Manual entry for leave_requests -- see AttendanceRecordModel's docblock for why only
 * attendance/leave/overtime get new manual-entry models in this feature.
 *
 * Does NOT enforce leave_types' quota/min-service-days/advance-notice rules -- same deliberate
 * scope limit as LeaveTypeSyncer for sync/import (see its docblock): this only validates
 * structural correctness (dates, employee/leave-type existence, no exact duplicate), not HR
 * policy compliance. Consistent across all 3 data-entry paths (sync/import/manual) for leave.
 * status defaults to 'approved' -- manual entry is HR directly recording an outcome, not
 * submitting something for approval.
 */
class LeaveRequestModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $filters optional: employee_id, date_from, date_to, batch_id (2026-08-30, Phase 5 T034 -- drill into one import batch's rows) */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE l.comp_id = :comp_id AND l.deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if (!empty($filters['employee_id'])) {
            $where .= " AND l.employee_id = :employee_id";
            $params[':employee_id'] = (int)$filters['employee_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND l.start_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND l.end_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        if (!empty($filters['batch_id'])) {
            $where .= " AND l.sync_batch_id = :batch_id";
            $params[':batch_id'] = (int)$filters['batch_id'];
        }
        $sql = "SELECT l.*, e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    t.name_th AS leave_type_name_th, t.name_en AS leave_type_name_en
                FROM leave_requests l
                JOIN employees e ON e.id = l.employee_id
                JOIN leave_types t ON t.id = l.leave_type_id
                {$where}
                ORDER BY l.start_date DESC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT l.*, e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    t.name_th AS leave_type_name_th, t.name_en AS leave_type_name_en
                FROM leave_requests l
                JOIN employees e ON e.id = l.employee_id
                JOIN leave_types t ON t.id = l.leave_type_id
                WHERE l.id = :id AND l.comp_id = :comp_id AND l.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function employeeBelongsToCompany(int $employeeId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    private function leaveTypeBelongsToCompany(int $leaveTypeId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $leaveTypeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    private function isDuplicate(int $compId, int $employeeId, int $leaveTypeId, string $startDate, string $endDate, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM leave_requests
            WHERE comp_id = :comp_id AND employee_id = :employee_id AND leave_type_id = :leave_type_id
              AND start_date = :start_date AND end_date = :end_date AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':employee_id' => $employeeId, ':leave_type_id' => $leaveTypeId, ':start_date' => $startDate, ':end_date' => $endDate];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * 2026-08-30 (Phase 5, conflict-prevention decision) -- widens isDuplicate()'s exact-match-only
     * check: a manually-entered leave request whose dates merely OVERLAP (not equal) an existing
     * row for the same employee+leave_type is now also rejected, not just an exact duplicate --
     * same reasoning and same (employee_id, leave_type_id)-scoped conflict LeaveRequestSyncer::
     * findOverlappingConflict() enforces for sync/import, kept consistent across all 3 entry paths
     * now that they all write into the same table. See that method's own docblock for why this
     * rejects rather than silently merges.
     */
    private function findOverlappingConflict(int $compId, int $employeeId, int $leaveTypeId, string $startDate, string $endDate, ?int $excludeId): ?array {
        $sql = "SELECT id, start_date, end_date FROM leave_requests
            WHERE comp_id = :comp_id AND employee_id = :employee_id AND leave_type_id = :leave_type_id AND deleted_at IS NULL
              AND start_date <= :end_date AND end_date >= :start_date
              AND NOT (start_date = :start_date2 AND end_date = :end_date2)";
        $params = [':comp_id' => $compId, ':employee_id' => $employeeId, ':leave_type_id' => $leaveTypeId,
            ':start_date' => $startDate, ':end_date' => $endDate, ':start_date2' => $startDate, ':end_date2' => $endDate];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data, int $compId, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $employeeId = (int)($data['employee_id'] ?? 0);
        $leaveTypeId = (int)($data['leave_type_id'] ?? 0);
        $startDate = trim((string)($data['start_date'] ?? ''));
        $endDate = trim((string)($data['end_date'] ?? ''));
        if ($employeeId <= 0 || $leaveTypeId <= 0
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return ['status' => false, 'message' => 'employee_id, leave_type_id, start_date, and end_date are required.'];
        }
        if ($endDate < $startDate) {
            return ['status' => false, 'message' => 'end_date must not be before start_date.'];
        }
        if (!$this->employeeBelongsToCompany($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        if (!$this->leaveTypeBelongsToCompany($leaveTypeId, $compId)) {
            return ['status' => false, 'message' => 'Leave type not found.'];
        }
        $totalDays = isset($data['total_days']) && is_numeric($data['total_days']) ? (float)$data['total_days'] : 0.0;
        if ($totalDays <= 0) {
            return ['status' => false, 'message' => 'total_days must be greater than zero.'];
        }
        $reason = !empty($data['reason']) ? trim((string)$data['reason']) : null;
        $status = in_array($data['status'] ?? '', ['pending', 'approved', 'rejected', 'cancelled'], true) ? $data['status'] : 'approved';

        if ($this->isDuplicate($compId, $employeeId, $leaveTypeId, $startDate, $endDate, $id)) {
            return ['status' => false, 'message' => 'A leave request for this employee, leave type, and date range already exists.'];
        }
        $conflict = $this->findOverlappingConflict($compId, $employeeId, $leaveTypeId, $startDate, $endDate, $id);
        if ($conflict !== null) {
            return ['status' => false, 'message' => "Overlaps an existing leave request for this employee and leave type ({$conflict['start_date']} to {$conflict['end_date']})."];
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM leave_requests WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                // 2026-08-30, conflict-prevention fix -- see AttendanceRecordModel's own equivalent comment.
                $stmt = $this->db->prepare("UPDATE leave_requests SET
                        employee_id = :employee_id, leave_type_id = :leave_type_id, start_date = :start_date, end_date = :end_date,
                        total_days = :total_days, reason = :reason, status = :status,
                        data_source = 'manual', updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $stmt->execute([
                    ':employee_id' => $employeeId, ':leave_type_id' => $leaveTypeId, ':start_date' => $startDate, ':end_date' => $endDate,
                    ':total_days' => $totalDays, ':reason' => $reason, ':status' => $status, ':updated_by' => $userId, ':id' => $id,
                ]);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }
            $stmt = $this->db->prepare("INSERT INTO leave_requests
                    (comp_id, employee_id, leave_type_id, start_date, end_date, total_days, reason, status, data_source, created_by)
                VALUES (:comp_id, :employee_id, :leave_type_id, :start_date, :end_date, :total_days, :reason, :status, 'manual', :created_by)");
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId, ':leave_type_id' => $leaveTypeId, ':start_date' => $startDate,
                ':end_date' => $endDate, ':total_days' => $totalDays, ':reason' => $reason, ':status' => $status, ':created_by' => $userId,
            ]);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM leave_requests WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE leave_requests SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }
}
