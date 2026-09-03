<?php
declare(strict_types=1);

/**
 * Unified, read-only "Download Template + Import" activity log for Manual Time Entry Import
 * (2026-08-30, explicit request: "เก็บประวัติการ Download ข้อมูลออกจากระบบ และการ Import ข้อมูลเข้าระบบ...
 * เพิ่ม Tab ในการดูประวัติการ Download Upload ด้วยครับ"). Combines TWO independently-written source
 * tables at the READ layer via UNION ALL -- `import_template_download_logs` (a Download event) and
 * `sync_batches` filtered to `source='import'` (an Import/Upload event) -- rather than forcing one
 * shared write schema, the SAME "combine at the read layer" approach `DocumentDeliveryLogModel`
 * already established for Payslip/Employment Certificate history (see that model's own docblock).
 *
 * Row shape returned by list(): event_type ('download'/'import'), id (the row's own id in ITS
 * source table -- an import_template_download_logs.id or a sync_batches.id, never a shared/
 * synthetic id -- a caller wanting T034's drill-down must only ever pass an 'import' row's own id
 * to importBatchDetail(), never a 'download' row's), entity_type (attendance/leave/overtime),
 * status ('completed' for a download -- logged only on a genuine successful stream, so there is no
 * failure state to represent there -- or sync_batches' own running/completed/failed for an import),
 * performed_at, performed_by/performed_by_name_th/en, file_name (download only, NULL for import),
 * total_count/success_count/error_count (import only, NULL for download), and the full device/IP
 * audit set (ip_address/device_type/os_name/browser_name/browser_version) both source tables now
 * carry identically (see the 2026-08-30 migration widening sync_batches to match
 * import_template_download_logs/report_export_logs' own already-proven shape).
 */
class ImportActivityLogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $filters optional: event_type ('download'/'import'), entity_type, date_from, date_to */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE 1=1";
        $params = [':comp_id1' => $compId, ':comp_id2' => $compId];
        if (!empty($filters['event_type'])) {
            $where .= " AND x.event_type = :event_type";
            $params[':event_type'] = $filters['event_type'];
        }
        if (!empty($filters['entity_type'])) {
            $where .= " AND x.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND x.performed_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND x.performed_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        $sql = "SELECT x.*,
                    CONCAT(e.name_th, ' ', e.surname_th) AS performed_by_name_th,
                    CONCAT(e.name_en, ' ', e.surname_en) AS performed_by_name_en
                FROM (
                    SELECT
                        'download' AS event_type, l.id AS id, l.entity_type, 'completed' AS status,
                        l.downloaded_at AS performed_at, l.downloaded_by AS performed_by,
                        l.file_name, NULL AS total_count, NULL AS success_count, NULL AS error_count,
                        l.ip_address, l.device_type, l.os_name, l.browser_name, l.browser_version, l.source,
                        NULL AS original_file_name
                    FROM `import_template_download_logs` l
                    WHERE l.comp_id = :comp_id1

                    UNION ALL

                    SELECT
                        'import' AS event_type, b.id AS id, b.entity_type, b.status,
                        b.started_at AS performed_at, b.triggered_by AS performed_by,
                        NULL AS file_name, b.total_count, b.success_count, b.error_count,
                        b.ip_address, b.device_type, b.os_name, b.browser_name, b.browser_version, NULL AS source,
                        b.original_file_name
                    FROM `sync_batches` b
                    WHERE b.comp_id = :comp_id2 AND b.source = 'import'
                ) x
                LEFT JOIN `employees` e ON e.id = x.performed_by
                {$where}
                ORDER BY x.performed_at DESC LIMIT 200";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
