<?php
declare(strict_types=1);

/**
 * Read-only audit log for payslip_delivery_logs -- log/list only, no update/delete, same
 * convention as ReportExportLogModel/payroll_run_audit_logs. Writing happens exclusively from
 * PayslipDeliveryService::deliver().
 */
class PayslipDeliveryLogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $filters optional: run_id, employee_id, channel_code, status, source */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE l.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['run_id'])) {
            $where .= " AND l.run_id = :run_id";
            $params[':run_id'] = (int)$filters['run_id'];
        }
        if (!empty($filters['employee_id'])) {
            $where .= " AND l.employee_id = :employee_id";
            $params[':employee_id'] = (int)$filters['employee_id'];
        }
        if (!empty($filters['channel_code'])) {
            $where .= " AND l.channel_code = :channel_code";
            $params[':channel_code'] = $filters['channel_code'];
        }
        if (!empty($filters['status'])) {
            $where .= " AND l.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['source'])) {
            $where .= " AND l.source = :source";
            $params[':source'] = $filters['source'];
        }
        $sql = "SELECT l.*,
                    e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    r.run_name, r.period_start_date, r.period_end_date,
                    CONCAT(u.name_th, ' ', u.surname_th) AS sent_by_name_th, CONCAT(u.name_en, ' ', u.surname_en) AS sent_by_name_en
                FROM `payslip_delivery_logs` l
                JOIN `employees` e ON e.id = l.employee_id
                JOIN `payroll_runs` r ON r.id = l.run_id
                LEFT JOIN `employees` u ON u.id = l.sent_by
                {$where}
                ORDER BY l.sent_at DESC LIMIT 200";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
