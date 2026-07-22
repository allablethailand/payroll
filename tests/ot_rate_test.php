<?php
/**
 * Lightweight verification script for OT Rate CRUD (SetupRulesModel::otRate*). Not PHPUnit --
 * see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/ot_rate_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/SetupRulesModel.php';

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
    $model = new SetupRulesModel($pdo);

    $scopes = $model->otScopeOptions();
    check('3 OT scope types seeded', count($scopes), 3);
    $weekdayScope = null;
    foreach ($scopes as $s) {
        if ($s['text_en'] === 'Weekday') { $weekdayScope = $s; }
    }
    checkTrue('weekday scope found', $weekdayScope !== null);
    $weekdayId = (int)$weekdayScope['id'];

    $save1 = $model->otRateSave([
        'ot_name_th' => 'ทดสอบโอทีวันธรรมดา', 'ot_name_en' => 'Test Weekday OT',
        'ot_scope_id' => $weekdayId, 'multiplier_rate' => 1.5, 'calculation_base' => 'hourly', 'status' => 'active',
    ], $compId, $userId);
    checkTrue('OT rate create succeeds', $save1['status']);
    $id1 = $save1['id'];

    $missingField = $model->otRateSave(['ot_name_th' => 'X'], $compId, $userId);
    checkFalse('missing scope/multiplier rejected', $missingField['status']);

    $badScope = $model->otRateSave([
        'ot_name_th' => 'X', 'ot_scope_id' => 999999, 'multiplier_rate' => 1.5,
    ], $compId, $userId);
    checkFalse('nonexistent scope rejected', $badScope['status']);

    $zeroMultiplier = $model->otRateSave([
        'ot_name_th' => 'X', 'ot_scope_id' => $weekdayId, 'multiplier_rate' => 0,
    ], $compId, $userId);
    checkFalse('zero multiplier rejected', $zeroMultiplier['status']);

    $fetched = $model->otRateGet((int)$id1, $compId);
    check('fetched rate has correct scope label', $fetched['scope_name_en'], 'Weekday');
    check('fetched rate defaults calculation_base to hourly', $fetched['calculation_base'], 'hourly');

    $list = $model->otRateList($compId);
    check('list returns 1 rate', count($list), 1);

    $toggle = $model->otRateToggleStatus((int)$id1, $compId, $userId);
    checkTrue('toggle status succeeds', $toggle['status']);
    check('toggled to inactive', $toggle['new_status'], 'inactive');

    $del = $model->otRateDelete((int)$id1, $compId, $userId);
    checkTrue('delete succeeds', $del['status']);
    check('deleted rate no longer retrievable', $model->otRateGet((int)$id1, $compId), null);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
