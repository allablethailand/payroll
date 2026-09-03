<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';

/**
 * 2026-09-02, extends the 2026-09-02_4 Origami candidates.php field batch (passport/work_permit),
 * which deliberately deferred `foreign_worker_info` -- see that migration's own follow-up header.
 * One row per employee (Thai-immigration-arrival-card-style reference data: recruitment agency,
 * arrival/due date, arrival card no., non-Thai home address) -- purely informational, never read by
 * any payroll calculation. Same "separate 1:1 table for a large field block only relevant to a
 * subset of employees" reasoning `employee_documents`/`employee_dependents` already established,
 * see this table's own migration header.
 *
 * Field ownership mirrors EmployeeSyncer's own convention for passport/work_permit: HR-owned data,
 * overwritten by every sync pull that resolves a value (never blanked by a sparse re-sync that
 * omits it), but also directly editable here for a No-HR-user company that never syncs at all.
 */
class EmployeeForeignWorkerDetailModel {
    private PDO $db;

    private const COLUMNS = [
        'recruitment_agency', 'arrival_date', 'due_date', 'arrival_card_no', 'arrival_by_vehicle',
        'address', 'soi', 'province', 'district', 'sub_district', 'tel_code', 'tel',
    ];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function get(int $employeeId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `employee_foreign_worker_details` WHERE employee_id = :employee_id");
        $stmt->execute([':employee_id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Full-replace upsert, same ON DUPLICATE KEY pattern every other company/employee singleton
     *  settings row in this app already uses. `$data` may carry only a subset of COLUMNS (a plain
     *  employee-detail-tab save, or a sparse sync payload) -- any key genuinely ABSENT from $data is
     *  left at whatever it already was (not overwritten to null), same "don't blank a manually-
     *  filled value with a sparse re-sync" contract EmployeeSyncer's own passport/work_permit
     *  handling already follows; a key present but explicitly empty/null DOES clear that one field. */
    public function save(int $employeeId, array $data, ?int $userId = null): void {
        $provided = array_intersect(array_keys($data), self::COLUMNS);
        if (empty($provided)) {
            return;
        }
        $existing = $this->get($employeeId);
        $merged = [];
        foreach (self::COLUMNS as $col) {
            if (in_array($col, $provided, true)) {
                $val = $data[$col];
                $merged[$col] = ($val === '' || $val === null) ? null : (string)$val;
            } else {
                $merged[$col] = $existing[$col] ?? null;
            }
        }
        $cols = array_merge(['employee_id'], self::COLUMNS, ['updated_by']);
        $placeholders = array_map(fn($c) => ":{$c}", $cols);
        $updateClause = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", array_merge(self::COLUMNS, ['updated_by'])));
        $stmt = $this->db->prepare("INSERT INTO `employee_foreign_worker_details` (" . implode(', ', array_map(fn($c) => "`{$c}`", $cols)) . ")
            VALUES (" . implode(', ', $placeholders) . ")
            ON DUPLICATE KEY UPDATE {$updateClause}");
        $params = [':employee_id' => $employeeId, ':updated_by' => $userId];
        foreach (self::COLUMNS as $col) {
            $params[":{$col}"] = $merged[$col];
        }
        $stmt->execute($params);
    }
}
