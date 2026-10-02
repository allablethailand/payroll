<?php
/**
 * Verification for EmployeeImporter (entity type employee_import): match by employee code then National ID,
 * create when unmatched, update without blanks and without protected columns.
 * Runs inside a transaction that is always rolled back.
 *
 * Run with: php tests/employee_importer_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/import/ImportService.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';

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
function throws(callable $fn): ?string {
    try { $fn(); } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    return null;
}

try {
    $compId = (int)$pdo->query("SELECT id FROM companies ORDER BY id LIMIT 1")->fetchColumn();
    $batchId = (new SyncBatchModel($pdo))->start($compId, 'employee_import', 'import', 'manual', null);
    $imp = new EmployeeImporter($pdo);
    $fetch = fn(string $code) => $pdo->query("SELECT * FROM employees WHERE comp_id = {$compId} AND employee_no = " . $pdo->quote($code))->fetch(PDO::FETCH_ASSOC);

    $base = ['employee_no' => 'T-IMP-1', 'id_card_no' => '1234567890123', 'name_th' => 'ทดสอบ', 'surname_th' => 'นำเข้า', 'employment_date' => '2026-01-01',
        'employment_status' => 'permanent', 'personal_email' => 'a@example.com', 'mobile_no' => '0811111111', 'gender' => 'female'];

    // create
    check('unmatched row is created', $imp->importRow($compId, $base, $batchId, null)['action'], 'inserted');
    $e = $fetch('T-IMP-1');
    check('created as active / import source', [$e['employee_status'], $e['data_source'], (int)$e['sync_batch_id']], ['active', 'import', $batchId]);
    check('national id stored encrypted and hashed', [$e['id_card_no'] !== '1234567890123', EncryptionService::decrypt($e['id_card_no'], (int)$e['key_version']), $e['id_card_no_hash'] === EncryptionService::hash('1234567890123')], [true, '1234567890123', true]);

    // update by code: only provided, non-blank cells change; protected columns stay
    $pdo->exec("UPDATE employees SET employee_status = 'suspended' WHERE id = " . (int)$e['id']);
    check('code match updates', $imp->importRow($compId, ['employee_no' => 'T-IMP-1', 'name_th' => 'ใหม่', 'personal_email' => '', 'mobile_no' => null], $batchId, 5)['action'], 'updated');
    $u = $fetch('T-IMP-1');
    check('provided cell changed', $u['name_th'], 'ใหม่');
    check('blank cells did not overwrite', [$u['personal_email'], $u['mobile_no'], $u['surname_th'], $u['id_card_no_hash'] === $e['id_card_no_hash']], ['a@example.com', '0811111111', 'นำเข้า', true]);
    check('employee_status and created_by untouched', [$u['employee_status'], $u['created_by'], $u['created_at']], ['suspended', $e['created_by'], $e['created_at']]);
    check('updated_by recorded', (int)$u['updated_by'], 5);

    // update by national id (no code in file); employee_no is never rewritten
    check('national id match updates', $imp->importRow($compId, ['employee_no' => '', 'id_card_no' => '1234567890123', 'nickname' => 'x', 'name_en' => 'Test', 'surname_en' => 'Import'], $batchId, null)['action'], 'updated');
    $n = $fetch('T-IMP-1');
    check('same row updated, code unchanged', [$n['name_en'], $n['employee_no'], (int)$n['id']], ['Test', 'T-IMP-1', (int)$e['id']]);
    check('file code differing from stored code does not rename', $imp->importRow($compId, ['employee_no' => 'T-IMP-OTHER', 'id_card_no' => '1234567890123', 'name_en' => 'Same'], $batchId, null)['action'], 'updated');
    check('employee_no still the original', [$fetch('T-IMP-1')['name_en'], $fetch('T-IMP-OTHER')], ['Same', false]);

    // conflicting identifiers
    $imp->importRow($compId, ['employee_no' => 'T-IMP-2', 'id_card_no' => '9999999999999', 'name_th' => 'บี', 'surname_th' => 'บี', 'employment_date' => '2026-02-01', 'employment_status' => 'contract'], $batchId, null);
    check('code of A with national id of B is rejected', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-1', 'id_card_no' => '9999999999999'], $batchId, null)), true);

    // create validation
    check('create needs employment_date', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-3', 'name_th' => 'ก', 'surname_th' => 'ข', 'employment_status' => 'permanent'], $batchId, null)), true);
    check('create needs a name', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-4', 'employment_date' => '2026-01-01', 'employment_status' => 'permanent'], $batchId, null)), true);
    check('create needs a code', (bool)throws(fn() => $imp->importRow($compId, ['name_th' => 'ก', 'surname_th' => 'ข', 'employment_date' => '2026-01-01', 'employment_status' => 'permanent'], $batchId, null)), true);
    check('bad date rejected', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-1', 'date_of_birth' => '31/12/1990'], $batchId, null)), true);
    check('impossible date rejected', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-1', 'date_of_birth' => '2026-02-30'], $batchId, null)), true);
    check('bad gender rejected', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-1', 'gender' => 'x'], $batchId, null)), true);
    check('bad email rejected', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-1', 'personal_email' => 'nope'], $batchId, null)), true);
    check('unknown department code rejected', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'T-IMP-1', 'department_code' => 'NO-SUCH-DEPT'], $batchId, null)), true);

    // through ImportService: preview rolls back, commit keeps
    $svc = new ImportService($pdo);
    $before = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE comp_id = {$compId}")->fetchColumn();
    $row = ['employee_no' => 'T-IMP-SVC', 'name_en' => 'Svc', 'surname_en' => 'Test', 'employment_date' => '2026-03-01', 'employment_status' => 'probation'];
    $prev = $svc->preview($compId, 'employee_import', [$row], null);
    check('preview reports an insert but writes nothing', [$prev['success'], (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE comp_id = {$compId}")->fetchColumn()], [1, $before]);
    $svc->commit($compId, 'employee_import', [$row], null);
    check('commit writes it', $fetch('T-IMP-SVC') !== false, true);
    check('template exposes the 16 columns', count($svc->templateColumns('employee_import')), 16);
} finally {
    $pdo->rollBack();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
