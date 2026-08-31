<?php
declare(strict_types=1);
require_once __DIR__ . '/EmployeeLoginLogModel.php';

/**
 * Audit log for the "Download Template" action (Manual Time Entry Import, T031) -- 2026-08-30,
 * explicit request: "เก็บประวัติการ Download ข้อมูลออกจากระบบ...เก็บตาม Format Log ที่ควรเก็บเพื่อให้สามารถ
 * Audit ต่อได้". A NEW, dedicated table rather than folding this into `report_export_logs` --
 * that table's own shape (report_code from ReportRegistry, payroll_run_id, period_year/
 * period_month) doesn't fit a blank template download, same "don't force a shared write schema"
 * reasoning DocumentDeliveryLogModel's own docblock already established -- but reuses the EXACT
 * SAME audit field set (ip_address/device_type/os_name/browser_name/browser_version/user_agent via
 * EmployeeLoginLogModel::parseUserAgent(), source) `report_export_logs` already proved out for
 * this exact purpose (see that table's own 2026-08-29 migration).
 */
class ImportTemplateDownloadLogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function log(int $compId, string $entityType, string $fileName, ?int $userId, ?string $ipAddress = null, ?string $userAgent = null, ?string $source = null): int {
        $parsed = $userAgent ? EmployeeLoginLogModel::parseUserAgent($userAgent) : ['device_type' => null, 'os_name' => null, 'browser_name' => null, 'browser_version' => null];
        $stmt = $this->db->prepare("INSERT INTO `import_template_download_logs`
            (comp_id, entity_type, file_name, downloaded_by, ip_address, device_type, os_name, browser_name, browser_version, user_agent, source)
            VALUES (:comp_id, :entity_type, :file_name, :downloaded_by, :ip_address, :device_type, :os_name, :browser_name, :browser_version, :user_agent, :source)");
        $stmt->execute([
            ':comp_id' => $compId, ':entity_type' => $entityType, ':file_name' => $fileName, ':downloaded_by' => $userId,
            ':ip_address' => $ipAddress, ':device_type' => $parsed['device_type'], ':os_name' => $parsed['os_name'],
            ':browser_name' => $parsed['browser_name'], ':browser_version' => $parsed['browser_version'],
            ':user_agent' => $userAgent !== null && $userAgent !== '' ? substr($userAgent, 0, 500) : null,
            ':source' => $source,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** @param array $filters optional: entity_type, date_from, date_to */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE l.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['entity_type'])) {
            $where .= " AND l.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND l.downloaded_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND l.downloaded_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        $sql = "SELECT l.*, CONCAT(e.name_th, ' ', e.surname_th) AS downloaded_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS downloaded_by_name_en
                FROM `import_template_download_logs` l
                LEFT JOIN `employees` e ON e.id = l.downloaded_by
                {$where}
                ORDER BY l.downloaded_at DESC LIMIT 200";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
