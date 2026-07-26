<?php
declare(strict_types=1);

/**
 * Manual entry for attendance_records -- the "No HR" user type keying in attendance directly.
 * department/position/shift/holiday/leave_type/ot_rate/employees already have full manual-entry
 * CRUD elsewhere in this system (Company Setup / Setup & Rules / Employee Detail, all pre-dating
 * this feature) -- this and its two siblings (LeaveRequestModel/OvertimeRecordModel) are the only
 * NEW manual-entry surfaces this feature needs, since attendance/leave/overtime had zero UI
 * before Step 2's schema work.
 *
 * data_source is always 'manual' here and sync_batch_id always NULL -- a manual save is a single
 * record, not a batch operation (no sync_batches row gets created).
 */
class AttendanceRecordModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $filters optional: employee_id, date_from, date_to */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE a.comp_id = :comp_id AND a.deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if (!empty($filters['employee_id'])) {
            $where .= " AND a.employee_id = :employee_id";
            $params[':employee_id'] = (int)$filters['employee_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND a.work_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND a.work_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        $sql = "SELECT a.*, e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    s.shift_name_th, s.shift_name_en
                FROM attendance_records a
                JOIN employees e ON e.id = a.employee_id
                LEFT JOIN shifts s ON s.id = a.shift_id
                {$where}
                ORDER BY a.work_date DESC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT a.*, e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    s.shift_name_th, s.shift_name_en
                FROM attendance_records a
                JOIN employees e ON e.id = a.employee_id
                LEFT JOIN shifts s ON s.id = a.shift_id
                WHERE a.id = :id AND a.comp_id = :comp_id AND a.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function employeeBelongsToCompany(int $employeeId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    private function shiftBelongsToCompany(int $shiftId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $shiftId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    private function isDuplicate(int $compId, int $employeeId, string $workDate, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM attendance_records WHERE comp_id = :comp_id AND employee_id = :employee_id AND work_date = :work_date AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':employee_id' => $employeeId, ':work_date' => $workDate];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function save(array $data, int $compId, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $employeeId = (int)($data['employee_id'] ?? 0);
        $workDate = trim((string)($data['work_date'] ?? ''));
        if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            return ['status' => false, 'message' => 'employee_id and a valid work_date are required.'];
        }
        if (!$this->employeeBelongsToCompany($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        $shiftId = (!empty($data['shift_id']) && is_numeric($data['shift_id'])) ? (int)$data['shift_id'] : null;
        if ($shiftId !== null && !$this->shiftBelongsToCompany($shiftId, $compId)) {
            return ['status' => false, 'message' => 'Shift not found.'];
        }
        $clockIn = !empty($data['clock_in']) ? trim((string)$data['clock_in']) : null;
        $clockOut = !empty($data['clock_out']) ? trim((string)$data['clock_out']) : null;
        $actualMinutes = null;
        if ($clockIn && $clockOut) {
            $diff = (strtotime($clockOut) - strtotime($clockIn)) / 60;
            if ($diff <= 0) {
                return ['status' => false, 'message' => 'clock_out must be after clock_in.'];
            }
            $actualMinutes = (int)$diff;
        }
        $status = in_array($data['status'] ?? '', ['present', 'absent', 'leave', 'holiday'], true) ? $data['status'] : 'present';
        $lateMinutes = isset($data['late_minutes']) && is_numeric($data['late_minutes']) ? (int)$data['late_minutes'] : 0;
        $earlyMinutes = isset($data['early_leave_minutes']) && is_numeric($data['early_leave_minutes']) ? (int)$data['early_leave_minutes'] : 0;

        if ($this->isDuplicate($compId, $employeeId, $workDate, $id)) {
            return ['status' => false, 'message' => 'An attendance record for this employee and date already exists.'];
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM attendance_records WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE attendance_records SET
                        employee_id = :employee_id, work_date = :work_date, shift_id = :shift_id, clock_in = :clock_in, clock_out = :clock_out,
                        actual_work_minutes = :actual_minutes, late_minutes = :late, early_leave_minutes = :early, status = :status,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $stmt->execute([
                    ':employee_id' => $employeeId, ':work_date' => $workDate, ':shift_id' => $shiftId, ':clock_in' => $clockIn, ':clock_out' => $clockOut,
                    ':actual_minutes' => $actualMinutes, ':late' => $lateMinutes, ':early' => $earlyMinutes, ':status' => $status,
                    ':updated_by' => $userId, ':id' => $id,
                ]);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }
            $stmt = $this->db->prepare("INSERT INTO attendance_records
                    (comp_id, employee_id, work_date, shift_id, clock_in, clock_out, actual_work_minutes, late_minutes, early_leave_minutes, status, data_source, created_by)
                VALUES (:comp_id, :employee_id, :work_date, :shift_id, :clock_in, :clock_out, :actual_minutes, :late, :early, :status, 'manual', :created_by)");
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId, ':work_date' => $workDate, ':shift_id' => $shiftId,
                ':clock_in' => $clockIn, ':clock_out' => $clockOut, ':actual_minutes' => $actualMinutes,
                ':late' => $lateMinutes, ':early' => $earlyMinutes, ':status' => $status, ':created_by' => $userId,
            ]);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM attendance_records WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE attendance_records SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }
}
