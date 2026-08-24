<?php
/**
 * Lightweight verification script for Attendance Deduction Rule CRUD
 * (AttendanceDeductionRuleModel), covering all 3 events (late/absent/unpaid_leave). Not PHPUnit --
 * see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction
 * that is always rolled back.
 * Run with: php tests/attendance_deduction_rule_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AttendanceDeductionRuleModel.php';

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

    // Real leftover data from actual interactive testing on this shared dev DB, not a test fixture
    // (confirmed via created_by/created_at, see feedback_dev_db_shared_state_test_fragility in
    // project memory / the same guard in tests/payroll_run_test.php) -- a real
    // attendance_deduction_rules row for comp_id=1's 'late' event breaks this file's "no rows saved
    // yet" default-shape assumptions. Cleared for the duration of this transaction only, rolled back
    // at the end.
    $pdo->prepare("DELETE FROM `attendance_deduction_rules` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);

    $model = new AttendanceDeductionRuleModel($pdo);

    echo "=== Method options (master data) ===\n";
    $methods = $model->methodOptions();
    check('3 attendance deduction methods seeded', count($methods), 3);

    echo "=== Default (no rows saved yet) -- one shape per event ===\n";
    $all = $model->ruleGetAll($compId);
    check('all 3 events present in ruleGetAll()', array_keys($all), ['late', 'absent', 'unpaid_leave']);
    $defaultRateUnit = ['late' => 'minute', 'absent' => 'day', 'unpaid_leave' => 'day'];
    foreach (['late', 'absent', 'unpaid_leave'] as $eventCode) {
        check("{$eventCode}: default method_code is percent_of_rate", $all[$eventCode]['method_code'], 'percent_of_rate');
        check("{$eventCode}: default multiplier_rate is 1.00", (float)$all[$eventCode]['multiplier_rate'], 1.0);
        check("{$eventCode}: default has no id (not persisted)", $all[$eventCode]['id'], null);
        check("{$eventCode}: default has no brackets", $all[$eventCode]['brackets'], []);
        check("{$eventCode}: default rate_unit is {$defaultRateUnit[$eventCode]}", $all[$eventCode]['rate_unit'], $defaultRateUnit[$eventCode]);
    }

    echo "=== Validation ===\n";
    $badEvent = $model->ruleSave(['event_code' => 'not_a_real_event', 'method_code' => 'flat_amount', 'rate_per_unit' => 1], $compId, $userId);
    checkFalse('unknown event_code rejected', $badEvent['status']);

    $badMethod = $model->ruleSave(['event_code' => 'late', 'method_code' => 'not_a_real_method'], $compId, $userId);
    checkFalse('unknown method_code rejected', $badMethod['status']);

    $missingRate = $model->ruleSave(['event_code' => 'late', 'method_code' => 'flat_amount'], $compId, $userId);
    checkFalse('flat_amount without rate_per_unit rejected', $missingRate['status']);

    $emptyBrackets = $model->ruleSave(['event_code' => 'absent', 'method_code' => 'tiered_bracket', 'brackets' => []], $compId, $userId);
    checkFalse('tiered_bracket with no brackets rejected', $emptyBrackets['status']);

    $badBracketRow = $model->ruleSave([
        'event_code' => 'absent', 'method_code' => 'tiered_bracket',
        'brackets' => [['min_units' => -1, 'deduction_amount' => 10]],
    ], $compId, $userId);
    checkFalse('bracket with negative min_units rejected', $badBracketRow['status']);

    echo "=== rate_unit (2026-08-21, \"นาทีละ กี่บาท ชั่วโมงละกี่บาท\") ===\n";
    $saveHourRate = $model->ruleSave(['event_code' => 'late', 'method_code' => 'flat_amount', 'rate_unit' => 'hour', 'rate_per_unit' => 20], $compId, $userId);
    checkTrue('save with explicit rate_unit=hour succeeds' . (empty($saveHourRate['status']) ? " ({$saveHourRate['message']})" : ''), $saveHourRate['status']);
    $rulesAfterHourSave = $model->ruleGetAll($compId);
    check("late's rate_unit round-trips as hour", $rulesAfterHourSave['late']['rate_unit'], 'hour');

    $saveBogusRateUnit = $model->ruleSave(['event_code' => 'absent', 'method_code' => 'flat_amount', 'rate_unit' => 'not_a_real_unit', 'rate_per_unit' => 5], $compId, $userId);
    checkTrue('save with an invalid rate_unit still succeeds (falls back, not rejected)', $saveBogusRateUnit['status']);
    $rulesAfterBogusUnit = $model->ruleGetAll($compId);
    check("absent's rate_unit falls back to its default (day) when an invalid value is sent", $rulesAfterBogusUnit['absent']['rate_unit'], 'day');
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = ? AND event_code = 'absent'")->execute([$compId]);

    echo "=== Save 'late' as flat_amount ===\n";
    $saveLate = $model->ruleSave(['event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5], $compId, $userId);
    checkTrue('late flat_amount save succeeds' . (empty($saveLate['status']) ? " ({$saveLate['message']})" : ''), $saveLate['status']);
    $lateRuleId = $saveLate['id'];
    $lateAfterDefaultUnitResave = $model->ruleGetAll($compId);
    check("late's rate_unit defaults back to minute when this save omitted rate_unit", $lateAfterDefaultUnitResave['late']['rate_unit'], 'minute');

    echo "=== Save 'absent' as percent_of_rate -- independent from 'late' ===\n";
    $saveAbsent = $model->ruleSave(['event_code' => 'absent', 'method_code' => 'percent_of_rate', 'multiplier_rate' => 0.75], $compId, $userId);
    checkTrue('absent percent_of_rate save succeeds', $saveAbsent['status']);
    $absentRuleId = $saveAbsent['id'];
    check('late and absent got different row ids (one per event, not a shared row)', $lateRuleId !== $absentRuleId, true);

    $allAfterTwoSaves = $model->ruleGetAll($compId);
    check("late's method_code is flat_amount", $allAfterTwoSaves['late']['method_code'], 'flat_amount');
    check("late's rate_per_unit round-trips", (float)$allAfterTwoSaves['late']['rate_per_unit'], 2.5);
    check("absent's method_code is percent_of_rate (unaffected by late's save)", $allAfterTwoSaves['absent']['method_code'], 'percent_of_rate');
    check("absent's multiplier_rate round-trips", (float)$allAfterTwoSaves['absent']['multiplier_rate'], 0.75);
    check("unpaid_leave is still untouched/default (only late and absent were saved)", $allAfterTwoSaves['unpaid_leave']['method_code'], 'percent_of_rate');
    check("unpaid_leave still has no id (never saved)", $allAfterTwoSaves['unpaid_leave']['id'], null);

    echo "=== Re-save 'late' (upsert -- same row, no duplicate) ===\n";
    $resaveLate = $model->ruleSave(['event_code' => 'late', 'method_code' => 'percent_of_rate', 'multiplier_rate' => 1.25], $compId, $userId);
    checkTrue('re-save succeeds', $resaveLate['status']);
    check('still the same row id (upsert, not a new row)', (int)$resaveLate['id'], (int)$lateRuleId);
    $countLateRows = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rules WHERE comp_id = {$compId} AND event_code = 'late'")->fetchColumn();
    check('still exactly 1 row for (comp_id, late)', $countLateRows, 1);
    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rules WHERE comp_id = {$compId}")->fetchColumn();
    check('exactly 2 total rows across both configured events (late + absent)', $totalRows, 2);

    echo "=== Save 'unpaid_leave' as tiered_bracket -- delete+reinsert semantics on re-save ===\n";
    $saveLeaveBrackets = $model->ruleSave([
        'event_code' => 'unpaid_leave', 'method_code' => 'tiered_bracket',
        'brackets' => [
            ['min_units' => 3, 'max_units' => null, 'deduction_amount' => 1000],
            ['min_units' => 1, 'max_units' => 2, 'deduction_amount' => 500],
        ],
    ], $compId, $userId);
    checkTrue('unpaid_leave tiered_bracket save succeeds' . (empty($saveLeaveBrackets['status']) ? " ({$saveLeaveBrackets['message']})" : ''), $saveLeaveBrackets['status']);
    $leaveRuleId = $saveLeaveBrackets['id'];

    $allAfterBrackets = $model->ruleGetAll($compId);
    check('2 brackets returned for unpaid_leave', count($allAfterBrackets['unpaid_leave']['brackets']), 2);
    check('brackets sorted by min_units ascending', array_map('intval', array_column($allAfterBrackets['unpaid_leave']['brackets'], 'min_units')), [1, 3]);
    checkTrue("late/absent brackets are empty (bracket rows scoped to unpaid_leave's rule_id only)",
        empty($allAfterBrackets['late']['brackets']) && empty($allAfterBrackets['absent']['brackets']));

    $resaveLeaveBrackets = $model->ruleSave([
        'event_code' => 'unpaid_leave', 'method_code' => 'tiered_bracket',
        'brackets' => [['min_units' => 0, 'max_units' => null, 'deduction_amount' => 9999]],
    ], $compId, $userId);
    checkTrue('re-save with 1 bracket succeeds', $resaveLeaveBrackets['status']);
    $bracketCountInDb = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rule_brackets WHERE rule_id = {$leaveRuleId}")->fetchColumn();
    check('exactly 1 bracket row after re-save (old 2 replaced, not accumulated)', $bracketCountInDb, 1);

    echo "=== Switching unpaid_leave away from tiered_bracket clears its brackets ===\n";
    $backToFlat = $model->ruleSave(['event_code' => 'unpaid_leave', 'method_code' => 'flat_amount', 'rate_per_unit' => 100], $compId, $userId);
    checkTrue('switch to flat_amount succeeds', $backToFlat['status']);
    $bracketCountAfterSwitch = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rule_brackets WHERE rule_id = {$leaveRuleId}")->fetchColumn();
    check('brackets cleared after switching away from tiered_bracket', $bracketCountAfterSwitch, 0);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
