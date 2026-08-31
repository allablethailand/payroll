<?php
declare(strict_types=1);

/**
 * Manual entry for overtime_records -- see AttendanceRecordModel's docblock for the overall rationale.
 *
 * 2026-08-30: `ot_rate_id` FK's target was repointed from the now-retired flat `ot_rates` table to
 * `ot_rate_set_items` (a single scope's rate row within an OT Rate Set -- see OtRateSetModel's own
 * docblock for the full replacement). The actual multiplier/flat rate on the referenced row is NEVER
 * read here or by TransactionDataPayAdapter -- only its `ot_scope_id` matters (which OT type this
 * record belongs to); real payroll OT pay is always computed later via the scope-based Set-resolution
 * path (OtRateSetModel::resolveRatesForEmployees()), same as a genuine Origami sync row. `r.ot_name_th`/
 * `r.ot_name_en` (display label for the picker/list, unchanged shape for the frontend) now resolve via
 * `master_ot_scope_types.name_th`/`name_en` (the OT TYPE's own name, e.g. "OT วันธรรมดา") rather than a
 * free-text name a company typed on the old flat row -- a Set's own individual items were never
 * separately named, only the containing Set was.
 */
class OvertimeRecordModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $filters optional: employee_id, date_from, date_to, batch_id (2026-08-30, Phase 5 T034 -- drill into one import batch's rows) */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE o.comp_id = :comp_id AND o.deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if (!empty($filters['employee_id'])) {
            $where .= " AND o.employee_id = :employee_id";
            $params[':employee_id'] = (int)$filters['employee_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND o.ot_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND o.ot_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        if (!empty($filters['batch_id'])) {
            $where .= " AND o.sync_batch_id = :batch_id";
            $params[':batch_id'] = (int)$filters['batch_id'];
        }
        $sql = "SELECT o.*, e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    sc.name_th AS ot_name_th, sc.name_en AS ot_name_en
                FROM overtime_records o
                JOIN employees e ON e.id = o.employee_id
                JOIN ot_rate_set_items r ON r.id = o.ot_rate_id
                JOIN master_ot_scope_types sc ON sc.id = r.ot_scope_id
                {$where}
                ORDER BY o.ot_date DESC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT o.*, e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    sc.name_th AS ot_name_th, sc.name_en AS ot_name_en
                FROM overtime_records o
                JOIN employees e ON e.id = o.employee_id
                JOIN ot_rate_set_items r ON r.id = o.ot_rate_id
                JOIN master_ot_scope_types sc ON sc.id = r.ot_scope_id
                WHERE o.id = :id AND o.comp_id = :comp_id AND o.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function employeeBelongsToCompany(int $employeeId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    private function otRateBelongsToCompany(int $otRateId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT i.id FROM ot_rate_set_items i
            JOIN ot_rate_sets s ON s.id = i.set_id AND s.comp_id = :comp_id AND s.deleted_at IS NULL
            WHERE i.id = :id");
        $stmt->execute([':id' => $otRateId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    private function isDuplicate(int $compId, int $employeeId, int $otRateId, string $otDate, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM overtime_records
            WHERE comp_id = :comp_id AND employee_id = :employee_id AND ot_rate_id = :ot_rate_id AND ot_date = :ot_date AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':employee_id' => $employeeId, ':ot_rate_id' => $otRateId, ':ot_date' => $otDate];
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
        $otRateId = (int)($data['ot_rate_id'] ?? 0);
        $otDate = trim((string)($data['ot_date'] ?? ''));
        if ($employeeId <= 0 || $otRateId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $otDate)) {
            return ['status' => false, 'message' => 'employee_id, ot_rate_id, and a valid ot_date are required.'];
        }
        if (!$this->employeeBelongsToCompany($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        if (!$this->otRateBelongsToCompany($otRateId, $compId)) {
            return ['status' => false, 'message' => 'OT rate not found.'];
        }
        $hours = isset($data['hours']) && is_numeric($data['hours']) ? (float)$data['hours'] : 0.0;
        if ($hours <= 0) {
            return ['status' => false, 'message' => 'hours must be greater than zero.'];
        }
        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (float)$data['amount'] : null;
        $status = in_array($data['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $data['status'] : 'approved';

        if ($this->isDuplicate($compId, $employeeId, $otRateId, $otDate, $id)) {
            return ['status' => false, 'message' => 'An overtime record for this employee, rate, and date already exists.'];
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM overtime_records WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                // 2026-08-30, conflict-prevention fix -- see AttendanceRecordModel's own equivalent comment.
                $stmt = $this->db->prepare("UPDATE overtime_records SET
                        employee_id = :employee_id, ot_rate_id = :ot_rate_id, ot_date = :ot_date, hours = :hours, amount = :amount, status = :status,
                        data_source = 'manual', updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $stmt->execute([
                    ':employee_id' => $employeeId, ':ot_rate_id' => $otRateId, ':ot_date' => $otDate, ':hours' => $hours,
                    ':amount' => $amount, ':status' => $status, ':updated_by' => $userId, ':id' => $id,
                ]);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }
            $stmt = $this->db->prepare("INSERT INTO overtime_records
                    (comp_id, employee_id, ot_rate_id, ot_date, hours, amount, status, data_source, created_by)
                VALUES (:comp_id, :employee_id, :ot_rate_id, :ot_date, :hours, :amount, :status, 'manual', :created_by)");
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId, ':ot_rate_id' => $otRateId, ':ot_date' => $otDate,
                ':hours' => $hours, ':amount' => $amount, ':status' => $status, ':created_by' => $userId,
            ]);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM overtime_records WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE overtime_records SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }
}
