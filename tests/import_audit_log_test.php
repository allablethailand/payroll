<?php
/**
 * Verification for ImportAuditLogModel (import_audit_logs). Runs inside a transaction that is always rolled back.
 * Requires migration 2026-10-01_import_audit_logs to be applied.
 *
 * Run with: php tests/import_audit_log_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/ImportAuditLogModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
function check(string $label, $actual, $expected): void {
    global $failures;
    if ($actual === $expected) {
        echo "PASS: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

try {
    $compId = (int)$pdo->query("SELECT id FROM companies ORDER BY id LIMIT 1")->fetchColumn();
    $model = new ImportAuditLogModel($pdo);
    $ua = str_repeat('x', 600);

    $id = $model->log($compId, null, 'employee', 'commit', ['file_name' => 'ไฟล์.xlsx', 'total' => 3, 'success' => 2, 'failed' => 1], false, 99, '/api/x?y=1', '127.0.0.1', $ua);
    check('log returns an id', is_int($id) && $id > 0, true);

    $rows = $model->list($compId, ['entity_type' => 'employee', 'action' => 'commit']);
    $row = $rows[0] ?? [];
    check('row listed first', (int)($row['id'] ?? 0), $id);
    check('outcome failed', $row['outcome'] ?? null, 'failed');
    check('user agent truncated to 500', strlen((string)$row['user_agent']), 500);
    check('thai payload survives', json_decode((string)$row['payload_json'], true)['file_name'] ?? null, 'ไฟล์.xlsx');
    check('batch_id kept', (int)$row['batch_id'], 99);

    $threw = false;
    try { $model->log($compId, null, 'employee', 'bogus'); } catch (InvalidArgumentException $e) { $threw = true; }
    check('unknown action rejected', $threw, true);
} finally {
    $pdo->rollBack();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
