<?php
/**
 * 2026-09-24, tiny round B: `api/payroll-run.get` stopped carrying a full `run.audit_log` array
 * (see docs/decisions/2026-09-24-tiny2-get-audit-log-removal.md) -- o_history_dt.js/m3e1_tabs_shared.js
 * used that array as their own ground-truth reference for the `#tb_run_audit_log` DataTable's counts/
 * filters/sort, computed from `.get()`'s own captured response (`lastAuditLog(payloads)`). This CLI
 * replaces that reference: same row shape/exclusion (`action != 'view_detail'`, ORDER BY a.id ASC) as
 * `PayrollRunModel::getAuditLog()` (app/models/PayrollRunModel.php:746), read directly from the DB
 * instead of through the endpoint under test -- reading the endpoint's OWN output (or another param
 * of the same request) as its own reference would be a tautology, not a test.
 *
 * `note` is the RAW value (never passed through maskAuditNote()) -- a caller whose test case cares
 * about masking must apply the same rule the endpoint does itself, not assume this CLI already did.
 *
 * Usage:
 *   php tests/ui/audit_log_ref_cli.php <run_id>
 *   -> prints JSON: {"run_id":<id>,"count":<n>,"rows":[{id,run_id,action,from_state,to_state,
 *      performed_by,performed_at,note,ip_address,user_agent,performed_by_name_th,
 *      performed_by_name_en,performed_by_profile_photo_path}, ...]}  (rows in ORDER BY a.id ASC,
 *      same order getAuditLog() itself returns)
 *
 * Read-only, no DB write -- same loopback guard every CLI tool in this folder carries.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "tests/ui/audit_log_ref_cli.php is a CLI tool.\n");
    exit(1);
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($ROOT)->load();
require_once $ROOT . '/config.php';
require_once $ROOT . '/app/core/Database.php';

$host = strtolower((string)parse_url(BASE_URL, PHP_URL_HOST));
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "refusing to run: BASE_URL host is '{$host}', not a loopback address.\n");
    exit(1);
}

$runId = $argv[1] ?? '';
if (!ctype_digit((string)$runId) || (int)$runId <= 0) {
    fwrite(STDERR, "usage: php tests/ui/audit_log_ref_cli.php <run_id>\n");
    exit(1);
}
$runId = (int)$runId;

$pdo = Database::getInstance()->pdo;
// Byte-for-byte the same query PayrollRunModel::getAuditLog() runs, minus its own leading
// `if (!$this->get($runId, $compId)) return [];` company-scope guard -- this CLI is read-only
// reference tooling run by the developer directly, not a request that needs comp_id isolation.
$stmt = $pdo->prepare("SELECT a.*, e.name_th AS performed_by_name_th, e.name_en AS performed_by_name_en,
            e.profile_photo_path AS performed_by_profile_photo_path
        FROM `payroll_run_audit_logs` a
        LEFT JOIN `employees` e ON e.id = a.performed_by
        WHERE a.run_id = :run_id AND a.action != 'view_detail' ORDER BY a.id ASC");
$stmt->execute([':run_id' => $runId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['run_id' => $runId, 'count' => count($rows), 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
