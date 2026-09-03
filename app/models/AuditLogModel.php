<?php
declare(strict_types=1);

/**
 * Platform Hardening Phase 6 (pilot): generic field-level audit log. No generic audit mechanism
 * existed anywhere in this app before this -- the closest precedents (PayrollRunModel::logAudit(),
 * private/scoped to one model+table; PayrollRunModel's own old_value/new_value columns, scoped to
 * payroll line overrides only) were each one-off, not reusable. This is the shared, reusable one.
 *
 * record() is called by a wiring caller (see the pilot's 3 target models: EmployeeModel::save(),
 * CompanyProfileModel::save(), PayrollEarningDeductionTypeModel/PayrollPolicyModel/
 * AttendanceDeductionRuleModel's own write methods) AFTER a real write already succeeded --
 * $oldRow/$newRow are plain associative arrays (a full `SELECT *` row, fetched by the caller
 * BEFORE its own UPDATE runs, and the row's own final state AFTER).
 *
 * action='update': diffs $oldRow vs $newRow key-by-key -- only keys present in BOTH arrays whose
 * values genuinely differ (loose string comparison, so '1' vs 1 doesn't false-positive) produce a
 * row; a small denylist of bookkeeping columns that change on every save regardless of real content
 * is always skipped. A no-op save (nothing changed) writes zero rows.
 * action='create'/'delete': exactly one row, field_name=NULL, old_value/new_value holds the
 * json_encode() of whichever side is non-null -- a whole-row event, not a field-level diff.
 *
 * A logging failure must NEVER abort the caller's real write -- this table is diagnostic, not
 * transactional business data (same "never let a side effect fail the primary action" precedent as
 * ThumbnailGenerator::generate()) -- record() swallows every exception internally.
 */
class AuditLogModel {
    private PDO $db;

    /** Bookkeeping columns that change on every save regardless of real content -- never worth a diff row. */
    private const DENYLIST_FIELDS = ['updated_at', 'updated_by', 'created_at', 'created_by'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $excludeFields update-mode only: extra field names to skip on top of DENYLIST_FIELDS
     *  -- e.g. a caller with application-layer-encrypted columns (ciphertext changes on every save
     *  even when the plaintext is unchanged, and diffing raw ciphertext into a log is pointless/noisy
     *  at best) passes those column names (+ their hash/key_version columns) here rather than
     *  polluting the shared DENYLIST_FIELDS with one caller's own schema. */
    public function record(
        int $compId,
        string $tableName,
        int $recordId,
        string $action,
        ?array $oldRow,
        ?array $newRow,
        ?int $performedBy,
        string $source = 'web',
        ?string $ip = null,
        ?string $userAgent = null,
        array $excludeFields = []
    ): void {
        try {
            if (!in_array($action, ['create', 'update', 'delete'], true)) {
                return;
            }
            $own = !$this->db->inTransaction();
            if ($own) {
                $this->db->beginTransaction();
            }
            try {
                if ($action === 'update') {
                    $this->recordUpdate($compId, $tableName, $recordId, $oldRow ?? [], $newRow ?? [], $performedBy, $source, $ip, $userAgent, $excludeFields);
                } else {
                    $this->insertRow($compId, $tableName, $recordId, $action, null,
                        $action === 'delete' ? json_encode($oldRow, JSON_UNESCAPED_UNICODE) : null,
                        $action === 'create' ? json_encode($newRow, JSON_UNESCAPED_UNICODE) : null,
                        $performedBy, $source, $ip, $userAgent);
                }
                if ($own) {
                    $this->db->commit();
                }
            } catch (Throwable $e) {
                if ($own) {
                    $this->db->rollBack();
                }
            }
        } catch (Throwable $e) {
            // Never let an audit-logging failure surface to the caller.
        }
    }

    private function recordUpdate(int $compId, string $tableName, int $recordId, array $oldRow, array $newRow,
        ?int $performedBy, string $source, ?string $ip, ?string $userAgent, array $excludeFields = []): void {
        foreach ($newRow as $field => $newValue) {
            if (in_array($field, self::DENYLIST_FIELDS, true) || in_array($field, $excludeFields, true)) {
                continue;
            }
            if (!array_key_exists($field, $oldRow)) {
                continue;
            }
            $oldValue = $oldRow[$field];
            if ((string)($oldValue ?? '') === (string)($newValue ?? '') && ($oldValue === null) === ($newValue === null)) {
                continue;
            }
            $this->insertRow($compId, $tableName, $recordId, 'update', $field,
                $oldValue === null ? null : (string)$oldValue,
                $newValue === null ? null : (string)$newValue,
                $performedBy, $source, $ip, $userAgent);
        }
    }

    private function insertRow(int $compId, string $tableName, int $recordId, string $action, ?string $fieldName,
        ?string $oldValue, ?string $newValue, ?int $performedBy, string $source, ?string $ip, ?string $userAgent): void {
        $stmt = $this->db->prepare(
            "INSERT INTO `audit_logs` (comp_id, table_name, record_id, action, field_name, old_value, new_value, performed_by, source, ip_address, user_agent)
             VALUES (:comp_id, :table_name, :record_id, :action, :field_name, :old_value, :new_value, :performed_by, :source, :ip_address, :user_agent)"
        );
        $stmt->execute([
            ':comp_id' => $compId,
            ':table_name' => $tableName,
            ':record_id' => $recordId,
            ':action' => $action,
            ':field_name' => $fieldName,
            ':old_value' => $oldValue,
            ':new_value' => $newValue,
            ':performed_by' => $performedBy,
            ':source' => $source,
            ':ip_address' => $ip,
            ':user_agent' => $userAgent !== null && $userAgent !== '' ? substr($userAgent, 0, 500) : null,
        ]);
    }

    /** @param array $filters optional: table_name, record_id, performed_by, date_from, date_to */
    public function list(int $compId, array $filters = [], int $start = 0, int $length = 50): array {
        $where = "WHERE a.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['table_name'])) {
            $where .= " AND a.table_name = :table_name";
            $params[':table_name'] = $filters['table_name'];
        }
        if (!empty($filters['record_id'])) {
            $where .= " AND a.record_id = :record_id";
            $params[':record_id'] = (int)$filters['record_id'];
        }
        if (!empty($filters['performed_by'])) {
            $where .= " AND a.performed_by = :performed_by";
            $params[':performed_by'] = (int)$filters['performed_by'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND a.performed_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND a.performed_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `audit_logs` a {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT a.*, e.name_th AS performed_by_name_th, e.surname_th AS performed_by_surname_th,
                    e.name_en AS performed_by_name_en, e.surname_en AS performed_by_surname_en
             FROM `audit_logs` a
             LEFT JOIN `employees` e ON e.id = a.performed_by
             {$where}
             ORDER BY a.performed_at DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $stmt->execute();
        return ['recordsTotal' => $total, 'recordsFiltered' => $total, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
}
