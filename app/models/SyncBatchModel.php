<?php
declare(strict_types=1);
require_once __DIR__ . '/EmployeeLoginLogModel.php';

/**
 * sync_batches CRUD -- start() opens a 'running' row, complete()/fail() closes it. Read-only
 * list()/lastSyncTimes() power the sync/import results screen (per-entity-type success/error
 * counts and last-run-at, derived from MAX(completed_at) rather than a separate stored column).
 * Shared by both the sync engine and the import engine -- `source` distinguishes which one a
 * given batch came from; manual entry never creates a batch row (it's a single-record write, not
 * a batch operation).
 *
 * 2026-08-30, explicit request: "เก็บประวัติการ...Import ข้อมูลเข้าระบบ...เก็บตาม Format Log ที่ควรเก็บเพื่อ
 * ให้สามารถ Audit ต่อได้" -- start() now ALSO captures ip_address/device_type/os_name/browser_name/
 * browser_version/user_agent (parsed via EmployeeLoginLogModel::parseUserAgent(), the SAME
 * convention report_export_logs already proved out for this exact "who/when/what device/IP/browser"
 * audit shape, per that migration's own header comment -- not reinvented here). Both new params are
 * optional/nullable and default to null so every EXISTING sync-engine call site (a scheduled
 * Origami pull has no browser request to fingerprint at all) keeps working unchanged.
 */
class SyncBatchModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function start(int $compId, string $entityType, string $source, string $triggerType, ?int $triggeredBy, ?string $scopeDateFrom = null, ?string $scopeDateTo = null, ?string $ipAddress = null, ?string $userAgent = null): int {
        $parsed = $userAgent ? EmployeeLoginLogModel::parseUserAgent($userAgent) : ['device_type' => null, 'os_name' => null, 'browser_name' => null, 'browser_version' => null];
        $stmt = $this->db->prepare("INSERT INTO sync_batches
                (comp_id, entity_type, source, trigger_type, scope_date_from, scope_date_to, status, triggered_by,
                 ip_address, device_type, os_name, browser_name, browser_version, user_agent)
            VALUES (:comp_id, :entity_type, :source, :trigger_type, :scope_from, :scope_to, 'running', :triggered_by,
                 :ip_address, :device_type, :os_name, :browser_name, :browser_version, :user_agent)");
        $stmt->execute([
            ':comp_id' => $compId, ':entity_type' => $entityType, ':source' => $source, ':trigger_type' => $triggerType,
            ':scope_from' => $scopeDateFrom, ':scope_to' => $scopeDateTo, ':triggered_by' => $triggeredBy,
            ':ip_address' => $ipAddress, ':device_type' => $parsed['device_type'], ':os_name' => $parsed['os_name'],
            ':browser_name' => $parsed['browser_name'], ':browser_version' => $parsed['browser_version'],
            ':user_agent' => $userAgent !== null && $userAgent !== '' ? substr($userAgent, 0, 500) : null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function complete(int $batchId, int $total, int $success, int $error, array $errors): void {
        $stmt = $this->db->prepare("UPDATE sync_batches SET status = 'completed', total_count = :total, success_count = :success,
                error_count = :error, error_detail = :error_detail, completed_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $stmt->execute([
            ':total' => $total, ':success' => $success, ':error' => $error,
            ':error_detail' => !empty($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null, ':id' => $batchId,
        ]);
    }

    public function fail(int $batchId, string $message): void {
        $stmt = $this->db->prepare("UPDATE sync_batches SET status = 'failed', error_count = 1,
                error_detail = :error_detail, completed_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $stmt->execute([':error_detail' => json_encode([['message' => $message]], JSON_UNESCAPED_UNICODE), ':id' => $batchId]);
    }

    /** @param array $filters optional: entity_type, status, source */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['entity_type'])) {
            $where .= " AND entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['status'])) {
            $where .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['source'])) {
            $where .= " AND source = :source";
            $params[':source'] = $filters['source'];
        }
        $stmt = $this->db->prepare("SELECT * FROM sync_batches {$where} ORDER BY started_at DESC LIMIT 200");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, ?string> entity_type => last completed_at (or null if never synced successfully) */
    public function lastSyncTimes(int $compId, string $source = 'sync'): array {
        $stmt = $this->db->prepare("SELECT entity_type, MAX(completed_at) AS last_sync_at
            FROM sync_batches WHERE comp_id = :comp_id AND source = :source AND status = 'completed' GROUP BY entity_type");
        $stmt->execute([':comp_id' => $compId, ':source' => $source]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['entity_type']] = $row['last_sync_at'];
        }
        return $result;
    }
}
