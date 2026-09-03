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

    /** @param ?array{path:string,name:string,size:int} $originalFile Platform Hardening Phase 5C --
     *  meaningful only for source='import' (ManualEntryController::importPreview() copies the
     *  uploaded file BEFORE this batch row exists, since $_FILES is gone by the time commit() runs --
     *  see that controller's own docblock). Null for every sync-engine call site, unchanged. */
    public function start(int $compId, string $entityType, string $source, string $triggerType, ?int $triggeredBy, ?string $scopeDateFrom = null, ?string $scopeDateTo = null, ?string $ipAddress = null, ?string $userAgent = null, ?array $originalFile = null): int {
        $parsed = $userAgent ? EmployeeLoginLogModel::parseUserAgent($userAgent) : ['device_type' => null, 'os_name' => null, 'browser_name' => null, 'browser_version' => null];
        $stmt = $this->db->prepare("INSERT INTO sync_batches
                (comp_id, entity_type, source, trigger_type, scope_date_from, scope_date_to, status, triggered_by,
                 ip_address, device_type, os_name, browser_name, browser_version, user_agent,
                 original_file_path, original_file_name, original_file_size)
            VALUES (:comp_id, :entity_type, :source, :trigger_type, :scope_from, :scope_to, 'running', :triggered_by,
                 :ip_address, :device_type, :os_name, :browser_name, :browser_version, :user_agent,
                 :original_file_path, :original_file_name, :original_file_size)");
        $stmt->execute([
            ':comp_id' => $compId, ':entity_type' => $entityType, ':source' => $source, ':trigger_type' => $triggerType,
            ':scope_from' => $scopeDateFrom, ':scope_to' => $scopeDateTo, ':triggered_by' => $triggeredBy,
            ':ip_address' => $ipAddress, ':device_type' => $parsed['device_type'], ':os_name' => $parsed['os_name'],
            ':browser_name' => $parsed['browser_name'], ':browser_version' => $parsed['browser_version'],
            ':user_agent' => $userAgent !== null && $userAgent !== '' ? substr($userAgent, 0, 500) : null,
            ':original_file_path' => $originalFile['path'] ?? null,
            ':original_file_name' => $originalFile['name'] ?? null,
            ':original_file_size' => $originalFile['size'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Platform Hardening Phase 5C: single-row lookup backing ManualEntryController::downloadImportOriginal(). */
    public function get(int $batchId, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM sync_batches WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $batchId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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

    /** @param array $filters optional: entity_type, status, source, date_from, date_to (on started_at)
     *  2026-09-02, "Data Sync" page redesign into Sync/History tabs, explicit request: "ในประวัติให้มี
     *  Filter ด้วย" -- date_from/date_to added alongside the existing entity_type/status/source
     *  filters. `triggered_by_name_*` resolved via the SAME LEFT JOIN pattern
     *  EmployeeSyncModel::log() already established for this exact table (see that method's own
     *  docblock) -- reused here rather than re-invented, since it's the same "who ran this batch"
     *  question against the same column. */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE b.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['entity_type'])) {
            $where .= " AND b.entity_type = :entity_type";
            $params[':entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['status'])) {
            $where .= " AND b.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['source'])) {
            $where .= " AND b.source = :source";
            $params[':source'] = $filters['source'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND b.started_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND b.started_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        $stmt = $this->db->prepare("SELECT b.*, e.employee_no AS triggered_by_employee_no,
                e.name_th AS triggered_by_name_th, e.surname_th AS triggered_by_surname_th,
                e.name_en AS triggered_by_name_en, e.surname_en AS triggered_by_surname_en
            FROM sync_batches b
            LEFT JOIN employees e ON e.id = b.triggered_by
            {$where} ORDER BY b.started_at DESC LIMIT 200");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 2026-09-02, same redesign, explicit request: "ใน Tab Sync ต้องบอกด้วยว่า Sync ล่าสุดเมื่อไหร่ โดยใคร"
     *  -- widened from a bare entity_type=>datetime map into entity_type=>{last_sync_at,
     *  triggered_by_name_*} so the Sync tab's own per-entity cards can show both in one fetch. Needs
     *  the ROW that produced MAX(completed_at), not just the max value itself, to know who ran THAT
     *  specific batch -- a plain GROUP BY can't answer that (aggregate functions lose which row they
     *  came from). A window function would be the obvious tool, but this dev environment's own
     *  MariaDB is 10.1.31 (confirmed via `SELECT VERSION()`) -- window functions only landed in
     *  MariaDB 10.2, so ROW_NUMBER() OVER(...) would be a hard SQL syntax error here. Uses the
     *  classic MariaDB-10.1-compatible "greatest-n-per-group" join instead: a subquery finds each
     *  entity_type's own MAX(completed_at), then joins back to the real row matching on both
     *  entity_type and that exact timestamp. (Two batches for the same entity_type completing at
     *  the identical microsecond would both match and the later one read here would simply win --
     *  accepted as a non-issue for a manual/scheduled sync trigger, not a request this needs to
     *  guard against.) */
    public function lastSyncTimes(int $compId, string $source = 'sync'): array {
        $stmt = $this->db->prepare("SELECT b.entity_type, b.completed_at AS last_sync_at, b.triggered_by,
                e.name_th AS triggered_by_name_th, e.surname_th AS triggered_by_surname_th,
                e.name_en AS triggered_by_name_en, e.surname_en AS triggered_by_surname_en
            FROM sync_batches b
            INNER JOIN (
                SELECT entity_type, MAX(completed_at) AS max_completed_at
                FROM sync_batches
                WHERE comp_id = :comp_id_sub AND source = :source_sub AND status = 'completed'
                GROUP BY entity_type
            ) m ON m.entity_type = b.entity_type AND m.max_completed_at = b.completed_at
            LEFT JOIN employees e ON e.id = b.triggered_by
            WHERE b.comp_id = :comp_id AND b.source = :source AND b.status = 'completed'");
        $stmt->execute([':comp_id' => $compId, ':source' => $source, ':comp_id_sub' => $compId, ':source_sub' => $source]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['entity_type']] = [
                'last_sync_at' => $row['last_sync_at'],
                'triggered_by' => $row['triggered_by'] !== null ? (int)$row['triggered_by'] : null,
                'triggered_by_name_th' => $row['triggered_by_name_th'],
                'triggered_by_surname_th' => $row['triggered_by_surname_th'],
                'triggered_by_name_en' => $row['triggered_by_name_en'],
                'triggered_by_surname_en' => $row['triggered_by_surname_en'],
            ];
        }
        return $result;
    }
}
