<?php
declare(strict_types=1);

/** Insert-only audit trail for every Import Framework action; a write failure never blocks the import itself. */
class ImportAuditLogModel {
    public const ACTIONS = ['download_template', 'upload', 'map', 'validate', 'edit_row', 'commit', 'discard', 'rollback'];

    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $payload file_name, mapped_fields, total/success/failed counts -- summary only, never row data */
    public function log(int $compId, ?int $userId, string $entityType, string $action, array $payload = [], bool $success = true, ?int $batchId = null, ?string $route = null, ?string $ip = null, ?string $userAgent = null): ?int {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Unknown import audit action: {$action}");
        }
        try {
            $stmt = $this->db->prepare("INSERT INTO `import_audit_logs`
                (comp_id, user_id, entity_type, action, outcome, batch_id, route, ip_address, user_agent, payload_json)
                VALUES (:comp_id, :user_id, :entity_type, :action, :outcome, :batch_id, :route, :ip, :ua, :payload)");
            $stmt->execute([
                ':comp_id' => $compId, ':user_id' => $userId, ':entity_type' => $entityType, ':action' => $action,
                ':outcome' => $success ? 'success' : 'failed', ':batch_id' => $batchId,
                ':route' => $route !== null ? substr($route, 0, 255) : null, ':ip' => $ip,
                ':ua' => $userAgent !== null && $userAgent !== '' ? substr($userAgent, 0, 500) : null,
                ':payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('import_audit_logs write failed (is migration 2026-10-01_import_audit_logs applied?): ' . $e->getMessage());
            return null;
        }
    }

    /** @param array $filters optional: entity_type, action, date_from, date_to */
    public function list(int $compId, array $filters = [], int $limit = 200): array {
        $where = 'WHERE l.comp_id = :comp_id';
        $params = [':comp_id' => $compId];
        foreach (['entity_type', 'action'] as $k) {
            if (!empty($filters[$k])) {
                $where .= " AND l.{$k} = :{$k}";
                $params[":{$k}"] = $filters[$k];
            }
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND l.performed_at >= :df';
            $params[':df'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND l.performed_at <= :dt';
            $params[':dt'] = $filters['date_to'] . ' 23:59:59';
        }
        $stmt = $this->db->prepare("SELECT l.id, l.user_id, l.entity_type, l.action, l.outcome, l.batch_id, l.route, l.ip_address, l.user_agent, l.payload_json, l.performed_at,
                CONCAT(e.name_th, ' ', e.surname_th) AS performed_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS performed_by_name_en
            FROM `import_audit_logs` l LEFT JOIN `employees` e ON e.id = l.user_id
            {$where} ORDER BY l.performed_at DESC, l.id DESC LIMIT " . (int)$limit);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
