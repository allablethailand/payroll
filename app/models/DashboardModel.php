<?php
declare(strict_types=1);

class DashboardModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    public function currentEmployee(int $employeeId, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT e.id, e.name_th, e.surname_th, e.name_en, e.surname_en,
                p.position_name_th, p.position_name_en
            FROM `employees` e
            LEFT JOIN `structure_positions` p ON p.id = e.position_id
            WHERE e.id = :id AND e.comp_id = :comp_id AND e.deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Active headcount + hires whose employment_date falls within the current calendar month. */
    public function employeeStats(int $compId): array {
        $stmt = $this->db->prepare("SELECT
                SUM(CASE WHEN employee_status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN employment_date >= :month_start THEN 1 ELSE 0 END) AS new_this_month
            FROM `employees`
            WHERE comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':comp_id' => $compId, ':month_start' => date('Y-m-01')]);
        $row = $stmt->fetch();
        return [
            'active_count' => (int)($row['active_count'] ?? 0),
            'new_this_month' => (int)($row['new_this_month'] ?? 0),
        ];
    }
}
