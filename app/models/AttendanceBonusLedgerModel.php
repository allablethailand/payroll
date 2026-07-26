<?php
declare(strict_types=1);
class AttendanceBonusLedgerModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    public function schemeOptions(int $compId, string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'";
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            $where .= " AND scheme_name LIKE :search";
            $params[':search'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `attendance_bonus_schemes` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id, scheme_name AS text_th, scheme_name AS text_en FROM `attendance_bonus_schemes` {$where} ORDER BY scheme_name ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    private function schemeBelongsToComp(int $schemeId, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `attendance_bonus_schemes` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $schemeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function employeeBelongsToComp(int $employeeId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    public function list(int $compId, int $schemeId, int $year, int $month): array {
        if (!$this->schemeBelongsToComp($schemeId, $compId)) {
            return [];
        }
        $sql = "SELECT l.*, CONCAT(e.employee_no, ' - ', e.name_th, ' ', e.surname_th) AS employee_name_th,
                    CONCAT(e.employee_no, ' - ', e.name_en, ' ', e.surname_en) AS employee_name_en,
                    s.scheme_name
                FROM `attendance_bonus_ledger` l
                JOIN `employees` e ON l.employee_id = e.id
                JOIN `attendance_bonus_schemes` s ON l.scheme_id = s.id
                WHERE l.scheme_id = :scheme_id AND l.period_year = :year AND l.period_month = :month AND e.comp_id = :comp_id
                ORDER BY e.employee_no ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':scheme_id' => $schemeId, ':year' => $year, ':month' => $month, ':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $sql = "SELECT l.*, CONCAT(e.employee_no, ' - ', e.name_th, ' ', e.surname_th) AS employee_name_th,
                    CONCAT(e.employee_no, ' - ', e.name_en, ' ', e.surname_en) AS employee_name_en,
                    s.scheme_name
                FROM `attendance_bonus_ledger` l
                JOIN `employees` e ON l.employee_id = e.id
                JOIN `attendance_bonus_schemes` s ON l.scheme_id = s.id
                WHERE l.id = :id AND e.comp_id = :comp_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function previousPeriod(int $year, int $month): array {
        if ($month === 1) {
            return [$year - 1, 12];
        }
        return [$year, $month - 1];
    }

    private function findEntry(int $employeeId, int $schemeId, int $year, int $month): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `attendance_bonus_ledger` WHERE employee_id = :employee_id AND scheme_id = :scheme_id AND period_year = :year AND period_month = :month");
        $stmt->execute([':employee_id' => $employeeId, ':scheme_id' => $schemeId, ':year' => $year, ':month' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Computes streak_count, cycle_count and locked amount for a given month's result,
     * based on the scheme's escalation rules and the immediately preceding month's entry (if any).
     */
    private function computeEscalation(array $scheme, ?array $prevEntry, string $status): array {
        $startingAmount = (float)$scheme['starting_amount'];
        $incrementAmount = (float)$scheme['increment_amount'];
        $maxAmount = $scheme['max_amount'] !== null ? (float)$scheme['max_amount'] : null;
        $resetCycleMonths = (int)$scheme['reset_cycle_months'];
        $prevCycle = $prevEntry ? (int)$prevEntry['cycle_count'] : 1;

        if ($status !== 'passed') {
            return ['streak_count' => 0, 'cycle_count' => $prevCycle, 'amount' => 0.0];
        }

        $prevStreak = ($prevEntry && $prevEntry['status'] === 'passed') ? (int)$prevEntry['streak_count'] : 0;
        $newStreak = $prevStreak + 1;
        $newCycle = $prevCycle;
        if ($newStreak > $resetCycleMonths) {
            $newStreak = 1;
            $newCycle = $prevCycle + 1;
        }

        $amount = $startingAmount + $incrementAmount * ($newStreak - 1);
        if ($maxAmount !== null) {
            $amount = min($amount, $maxAmount);
        }

        return ['streak_count' => $newStreak, 'cycle_count' => $newCycle, 'amount' => $amount];
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['employee_id', 'scheme_id', 'period_year', 'period_month', 'status'] as $field) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $employeeId = (int)$data['employee_id'];
        $schemeId = (int)$data['scheme_id'];
        $year = (int)$data['period_year'];
        $month = (int)$data['period_month'];
        $status = (string)$data['status'];

        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        $scheme = $this->schemeBelongsToComp($schemeId, $compId);
        if (!$scheme) {
            return ['status' => false, 'message' => 'Invalid scheme.'];
        }
        if ($month < 1 || $month > 12) {
            return ['status' => false, 'message' => 'Invalid period_month.'];
        }
        if (!in_array($status, ['passed', 'failed'], true)) {
            return ['status' => false, 'message' => 'Status must be either passed or failed.'];
        }

        $existing = $this->findEntry($employeeId, $schemeId, $year, $month);
        if ($existing && ($id === null || (int)$existing['id'] !== $id)) {
            return ['status' => false, 'message' => 'A ledger entry already exists for this employee, scheme, and period.'];
        }
        if ($id !== null) {
            if (!$existing) {
                $existing = $this->get($id, $compId);
            }
            if (!$existing || (int)$existing['id'] !== $id) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            if ($existing['locked_at'] !== null) {
                return ['status' => false, 'message' => 'This entry is locked and cannot be edited.'];
            }
        }

        $failReasons = !empty($data['fail_reasons']) ? trim((string)$data['fail_reasons']) : null;

        [$prevYear, $prevMonth] = $this->previousPeriod($year, $month);
        $prevEntry = $this->findEntry($employeeId, $schemeId, $prevYear, $prevMonth);
        $calc = $this->computeEscalation($scheme, $prevEntry, $status);

        try {
            if ($id !== null) {
                $sql = "UPDATE `attendance_bonus_ledger` SET
                            status = :status, fail_reasons = :fail_reasons,
                            streak_count = :streak_count, cycle_count = :cycle_count, amount = :amount,
                            calculated_by = :calculated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':status' => $status,
                    ':fail_reasons' => $failReasons,
                    ':streak_count' => $calc['streak_count'],
                    ':cycle_count' => $calc['cycle_count'],
                    ':amount' => $calc['amount'],
                    ':calculated_by' => $userId,
                    ':id' => $id,
                ]);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `attendance_bonus_ledger`
                        (scheme_id, employee_id, period_year, period_month, data_source, status, fail_reasons,
                         streak_count, cycle_count, amount, calculated_by)
                    VALUES
                        (:scheme_id, :employee_id, :period_year, :period_month, 'manual', :status, :fail_reasons,
                         :streak_count, :cycle_count, :amount, :calculated_by)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':scheme_id' => $schemeId,
                ':employee_id' => $employeeId,
                ':period_year' => $year,
                ':period_month' => $month,
                ':status' => $status,
                ':fail_reasons' => $failReasons,
                ':streak_count' => $calc['streak_count'],
                ':cycle_count' => $calc['cycle_count'],
                ':amount' => $calc['amount'],
                ':calculated_by' => $userId,
            ]);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function lock(int $id, int $compId, int $userId): array {
        $existing = $this->get($id, $compId);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($existing['locked_at'] !== null) {
            return ['status' => false, 'message' => 'This entry is already locked.'];
        }
        $stmt = $this->db->prepare("UPDATE `attendance_bonus_ledger` SET locked_at = CURRENT_TIMESTAMP, calculated_by = :calculated_by WHERE id = :id");
        $stmt->execute([':calculated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Locked successfully.'];
    }

    public function delete(int $id, int $compId): array {
        $existing = $this->get($id, $compId);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($existing['locked_at'] !== null) {
            return ['status' => false, 'message' => 'This entry is locked and cannot be deleted.'];
        }
        $stmt = $this->db->prepare("DELETE FROM `attendance_bonus_ledger` WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }
}
