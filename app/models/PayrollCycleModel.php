<?php
declare(strict_types=1);
class PayrollCycleModel {
    private $db;
    private const DAYS_OF_WEEK = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    public function list(int $compId): array {
        $sql = "SELECT pc.*, f.name_th AS bank_file_format_name_th, f.name_en AS bank_file_format_name_en
                FROM `payroll_cycles` pc
                LEFT JOIN `master_bank_file_formats` f ON f.id = pc.bank_file_format_id
                WHERE pc.comp_id = :comp_id AND pc.deleted_at IS NULL ORDER BY pc.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $sql = "SELECT pc.*, f.name_th AS bank_file_format_name_th, f.name_en AS bank_file_format_name_en
                FROM `payroll_cycles` pc
                LEFT JOIN `master_bank_file_formats` f ON f.id = pc.bank_file_format_id
                WHERE pc.id = :id AND pc.comp_id = :comp_id AND pc.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function options(int $compId, string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'";
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            $where .= " AND cycle_name LIKE :search";
            $params[':search'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_cycles` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id, cycle_name AS text_th, cycle_name AS text_en FROM `payroll_cycles` {$where} ORDER BY cycle_name ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    public function bankFileFormatOptions(string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE is_active = 1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (name_th LIKE :search1 OR name_en LIKE :search2 OR code LIKE :search3)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `master_bank_file_formats` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id, name_th AS text_th, name_en AS text_en FROM `master_bank_file_formats` {$where} ORDER BY sort_order ASC, id ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    private function isCycleNameDuplicate(int $compId, string $name, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `payroll_cycles` WHERE comp_id = :comp_id AND cycle_name = :cycle_name AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':cycle_name' => $name];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Resolves a "day of month or last day" pair from request data for a given field prefix.
     * Returns [dayOfMonth|null, useLastDay(0|1)] or null on validation failure.
     */
    private function resolveDayOfMonth(array $data, string $dayField, string $lastDayField): ?array {
        $useLastDay = !empty($data[$lastDayField]) ? 1 : 0;
        if ($useLastDay) {
            return [null, 1];
        }
        if (!isset($data[$dayField]) || $data[$dayField] === '' || !is_numeric($data[$dayField])) {
            return null;
        }
        $day = (int)$data[$dayField];
        if ($day < 1 || $day > 28) {
            return null;
        }
        return [$day, 0];
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['cycle_name', 'payroll_frequency', 'ot_cutoff_type', 'bank_file_format_id'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $cycleName = trim((string)$data['cycle_name']);
        if ($this->isCycleNameDuplicate($compId, $cycleName, $id)) {
            return ['status' => false, 'message' => 'This cycle name is already in use.'];
        }

        $frequency = (string)$data['payroll_frequency'];
        if (!in_array($frequency, ['monthly', 'semi_monthly', 'weekly', 'bi_weekly'], true)) {
            return ['status' => false, 'message' => 'Invalid payroll_frequency.'];
        }

        $bankFileFormatId = (int)$data['bank_file_format_id'];
        $stmtFormat = $this->db->prepare("SELECT id FROM `master_bank_file_formats` WHERE id = :id AND is_active = 1");
        $stmtFormat->execute([':id' => $bankFileFormatId]);
        if (!$stmtFormat->fetch()) {
            return ['status' => false, 'message' => 'Invalid bank_file_format_id.'];
        }

        $cutoffDayOfMonth = null;
        $cutoffUseLastDay = 0;
        $cutoffDayOfWeek = null;
        $paymentDayOfMonth = null;
        $paymentUseLastDay = 0;
        $paymentDayOfWeek = null;

        if ($frequency === 'weekly') {
            $cutoffDayOfWeek = (string)($data['cutoff_day_of_week'] ?? '');
            $paymentDayOfWeek = (string)($data['payment_day_of_week'] ?? '');
            if (!in_array($cutoffDayOfWeek, self::DAYS_OF_WEEK, true)) {
                return ['status' => false, 'message' => 'Missing or invalid field: cutoff_day_of_week'];
            }
            if (!in_array($paymentDayOfWeek, self::DAYS_OF_WEEK, true)) {
                return ['status' => false, 'message' => 'Missing or invalid field: payment_day_of_week'];
            }
        } else {
            $cutoffResolved = $this->resolveDayOfMonth($data, 'cutoff_day_of_month', 'cutoff_use_last_day');
            if ($cutoffResolved === null) {
                return ['status' => false, 'message' => 'Cutoff day must be between 1-28, or use last day of month.'];
            }
            [$cutoffDayOfMonth, $cutoffUseLastDay] = $cutoffResolved;

            $paymentResolved = $this->resolveDayOfMonth($data, 'payment_day_of_month', 'payment_use_last_day');
            if ($paymentResolved === null) {
                return ['status' => false, 'message' => 'Payment day must be between 1-28, or use last day of month.'];
            }
            [$paymentDayOfMonth, $paymentUseLastDay] = $paymentResolved;
        }

        $otCutoffType = (string)$data['ot_cutoff_type'];
        if (!in_array($otCutoffType, ['same_as_attendance', 'custom'], true)) {
            return ['status' => false, 'message' => 'Invalid ot_cutoff_type.'];
        }
        $otCutoffDayOfMonth = null;
        $otCutoffUseLastDay = 0;
        if ($otCutoffType === 'custom') {
            $otResolved = $this->resolveDayOfMonth($data, 'ot_cutoff_day_of_month', 'ot_cutoff_use_last_day');
            if ($otResolved === null) {
                return ['status' => false, 'message' => 'OT cutoff day must be between 1-28, or use last day of month.'];
            }
            [$otCutoffDayOfMonth, $otCutoffUseLastDay] = $otResolved;
        }

        $statusInput = $data['status'] ?? 'active';
        $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';

        $params = [
            ':cycle_name' => $cycleName,
            ':payroll_frequency' => $frequency,
            ':cutoff_day_of_month' => $cutoffDayOfMonth,
            ':cutoff_use_last_day' => $cutoffUseLastDay,
            ':cutoff_day_of_week' => $cutoffDayOfWeek,
            ':payment_day_of_month' => $paymentDayOfMonth,
            ':payment_use_last_day' => $paymentUseLastDay,
            ':payment_day_of_week' => $paymentDayOfWeek,
            ':ot_cutoff_type' => $otCutoffType,
            ':ot_cutoff_day_of_month' => $otCutoffDayOfMonth,
            ':ot_cutoff_use_last_day' => $otCutoffUseLastDay,
            ':bank_file_format_id' => $bankFileFormatId,
            ':status' => $status,
        ];

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $sql = "UPDATE `payroll_cycles` SET
                            cycle_name = :cycle_name, payroll_frequency = :payroll_frequency,
                            cutoff_day_of_month = :cutoff_day_of_month, cutoff_use_last_day = :cutoff_use_last_day, cutoff_day_of_week = :cutoff_day_of_week,
                            payment_day_of_month = :payment_day_of_month, payment_use_last_day = :payment_use_last_day, payment_day_of_week = :payment_day_of_week,
                            ot_cutoff_type = :ot_cutoff_type, ot_cutoff_day_of_month = :ot_cutoff_day_of_month, ot_cutoff_use_last_day = :ot_cutoff_use_last_day,
                            bank_file_format_id = :bank_file_format_id, status = :status,
                            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `payroll_cycles`
                        (comp_id, cycle_name, payroll_frequency, cutoff_day_of_month, cutoff_use_last_day, cutoff_day_of_week,
                         payment_day_of_month, payment_use_last_day, payment_day_of_week,
                         ot_cutoff_type, ot_cutoff_day_of_month, ot_cutoff_use_last_day, bank_file_format_id, status, created_by)
                    VALUES
                        (:comp_id, :cycle_name, :payroll_frequency, :cutoff_day_of_month, :cutoff_use_last_day, :cutoff_day_of_week,
                         :payment_day_of_month, :payment_use_last_day, :payment_day_of_week,
                         :ot_cutoff_type, :ot_cutoff_day_of_month, :ot_cutoff_use_last_day, :bank_file_format_id, :status, :created_by)";
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
            $stmtCheck = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `payroll_cycles` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
