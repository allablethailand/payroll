<?php
/**
 * Lightweight verification script for PayslipDistributionSettingModel: default (unsaved) shape,
 * validation (mode/channel/department/employment-status), upsert semantics (singleton per
 * company), and channel ordering. Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/payslip_distribution_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayslipDistributionSettingModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    $compId = 1;
    $userId = 1;
    $model = new PayslipDistributionSettingModel($pdo);

    // ---------- Fixtures: a couple of real departments to scope against ----------
    $pdo->prepare("INSERT INTO structure_departments (id, comp_id, department_code, department_name_th, department_name_en, status)
        VALUES (90001, :comp_id, 'PDT_TEST_A', 'แผนกทดสอบเอ', 'Test Dept A', 'active')")->execute([':comp_id' => $compId]);
    $pdo->prepare("INSERT INTO structure_departments (id, comp_id, department_code, department_name_th, department_name_en, status)
        VALUES (90002, :comp_id, 'PDT_TEST_B', 'แผนกทดสอบบี', 'Test Dept B', 'active')")->execute([':comp_id' => $compId]);

    // ---------- channelOptions() ----------
    $channels = $model->channelOptions();
    check('3 channels seeded', count($channels), 3);
    check('channel options carry an id key (code, not numeric pk)', $channels[0]['id'], 'email');

    // ---------- get() default (unsaved) shape ----------
    $default = $model->get($compId);
    check('no settings row yet: id is null', $default['id'], null);
    check('default distribution_mode is request_only', $default['distribution_mode'], 'request_only');
    check('default channels is empty array', $default['channels'], []);
    check('default scope_departments is empty array', $default['scope_departments'], []);

    // ---------- Validation ----------
    $r = $model->save(['distribution_mode' => 'not_a_real_mode'], $compId, $userId);
    checkFalse('invalid distribution_mode rejected', $r['status']);

    $r = $model->save(['distribution_mode' => 'auto', 'channels' => []], $compId, $userId);
    checkFalse('auto mode with no channels rejected', $r['status']);

    $r = $model->save(['distribution_mode' => 'auto', 'channels' => ['fax']], $compId, $userId);
    checkFalse('unknown channel code rejected', $r['status']);

    $r = $model->save(['distribution_mode' => 'auto', 'channels' => ['email'], 'send_delay_hours' => -1], $compId, $userId);
    checkFalse('negative send_delay_hours rejected', $r['status']);

    $r = $model->save(['distribution_mode' => 'auto', 'channels' => ['email'], 'scope_department_ids' => '999999'], $compId, $userId);
    checkFalse('non-existent department id rejected', $r['status']);

    $r = $model->save(['distribution_mode' => 'auto', 'channels' => ['email'], 'scope_employment_statuses' => 'not_a_real_status'], $compId, $userId);
    checkFalse('invalid employment status rejected', $r['status']);

    // ---------- Save (create) ----------
    $r = $model->save([
        'distribution_mode' => 'both', 'channels' => ['line', 'email'], 'send_delay_hours' => 2,
        'scope_department_ids' => '90001,90002', 'scope_employment_statuses' => 'permanent,contract', 'is_active' => 1,
    ], $compId, $userId);
    checkTrue('initial save succeeds', $r['status']);
    $settingId = $r['id'];

    $saved = $model->get($compId);
    check('saved id matches', (int)$saved['id'], $settingId);
    check('distribution_mode persisted', $saved['distribution_mode'], 'both');
    check('send_delay_hours persisted', (int)$saved['send_delay_hours'], 2);
    check('scope_department_ids persisted as CSV', $saved['scope_department_ids'], '90001,90002');
    check('scope_employment_statuses persisted as CSV', $saved['scope_employment_statuses'], 'permanent,contract');
    check('channel order preserved: line first (primary)', $saved['channels'][0]['code'], 'line');
    check('channel order preserved: email second (fallback)', $saved['channels'][1]['code'], 'email');
    check('channel row carries name_en for label display', $saved['channels'][0]['name_en'], 'LINE');
    check('exactly 2 channels', count($saved['channels']), 2);
    check('scope_departments resolved with names', count($saved['scope_departments']), 2);
    check('scope_departments carries name_en', $saved['scope_departments'][0]['name_en'], 'Test Dept A');

    // ---------- Save (update / upsert -- singleton per company) ----------
    $r2 = $model->save([
        'distribution_mode' => 'request_only', 'channels' => [], 'send_delay_hours' => 0,
    ], $compId, $userId);
    checkTrue('second save (upsert) succeeds', $r2['status']);
    check('upsert updates the SAME row, not a new one', $r2['id'], $settingId);

    $afterUpsert = $model->get($compId);
    check('distribution_mode updated', $afterUpsert['distribution_mode'], 'request_only');
    check('channels cleared (request_only does not require any)', $afterUpsert['channels'], []);
    check('scope_department_ids cleared', $afterUpsert['scope_department_ids'], null);
    check('scope_departments cleared', $afterUpsert['scope_departments'], []);

    // ---------- Reorder: flip channel priority on a fresh save ----------
    $model->save(['distribution_mode' => 'auto', 'channels' => ['telegram', 'line', 'email']], $compId, $userId);
    $reordered = $model->get($compId);
    check('reordered channels[0]', $reordered['channels'][0]['code'], 'telegram');
    check('reordered channels[1]', $reordered['channels'][1]['code'], 'line');
    check('reordered channels[2]', $reordered['channels'][2]['code'], 'email');

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo->rollBack();
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
