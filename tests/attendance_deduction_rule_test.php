<?php
/**
 * Lightweight verification script for Attendance Deduction Rule CRUD
 * (AttendanceDeductionRuleModel), covering all 5 events (late/early_leave/absent/unpaid_leave/
 * leave_pending -- early_leave added 2026-08-30, Phase 8 T043, "เพิ่ม 'กลับก่อนเวลา' ตั้งค่าได้แบบ
 * เดียวกับ 'มาสาย'"; leave_pending added 2026-08-29) AND the 2026-08-30 multi-scope rollout
 * (team/department-scoped rule variants, priority resolution, ruleDelete()). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that is
 * always rolled back.
 * Run with: php tests/attendance_deduction_rule_test.php
 *
 * ruleGetAll()'s return shape changed in the 2026-08-30 multi-scope rollout: each event_code key now
 * maps to a LIST of rule-variant rows (company-wide default first, then any scoped variants), not a
 * single associative row -- every access below is $all[$eventCode][0] for the default variant.
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
    // 2026-08-30 (T015, "เพิ่มตัวเลือก 'ไม่หัก'"): 3 -> 4 (percent_of_rate/flat_amount/tiered_bracket/
    // no_deduction).
    check('4 attendance deduction methods seeded', count($methods), 4);

    echo "=== Default (no rows saved yet) -- one virtual default variant per event ===\n";
    $all = $model->ruleGetAll($compId);
    // 2026-08-30 (Phase 8, T043): 'early_leave' added as the 2nd event, mirroring 'late' exactly
    // (SyncPayResolver::attendanceDeductionRuleFor() already queried attendance_deduction_rules
    // generically by event_code with no hardcoded list -- the only real gap was EVENT_CODES never
    // including it, so the settings UI never let an admin configure it).
    check('all 5 events present in ruleGetAll()', array_keys($all), ['late', 'early_leave', 'absent', 'unpaid_leave', 'leave_pending']);
    $defaultRateUnit = ['late' => 'minute', 'early_leave' => 'minute', 'absent' => 'day', 'unpaid_leave' => 'day', 'leave_pending' => 'day'];
    foreach (['late', 'early_leave', 'absent', 'unpaid_leave', 'leave_pending'] as $eventCode) {
        check("{$eventCode}: exactly 1 variant (the virtual default) when nothing saved", count($all[$eventCode]), 1);
        check("{$eventCode}: default method_code is percent_of_rate", $all[$eventCode][0]['method_code'], 'percent_of_rate');
        check("{$eventCode}: default multiplier_rate is 1.00", (float)$all[$eventCode][0]['multiplier_rate'], 1.0);
        check("{$eventCode}: default has no id (not persisted)", $all[$eventCode][0]['id'], null);
        check("{$eventCode}: default has no brackets", $all[$eventCode][0]['brackets'], []);
        check("{$eventCode}: default rate_unit is {$defaultRateUnit[$eventCode]}", $all[$eventCode][0]['rate_unit'], $defaultRateUnit[$eventCode]);
        check("{$eventCode}: default scope_type is null", $all[$eventCode][0]['scope_type'], null);
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
    check("late's rate_unit round-trips as hour", $rulesAfterHourSave['late'][0]['rate_unit'], 'hour');
    $lateDefaultId = $rulesAfterHourSave['late'][0]['id'];

    $saveBogusRateUnit = $model->ruleSave(['event_code' => 'absent', 'method_code' => 'flat_amount', 'rate_unit' => 'not_a_real_unit', 'rate_per_unit' => 5], $compId, $userId);
    checkTrue('save with an invalid rate_unit still succeeds (falls back, not rejected)', $saveBogusRateUnit['status']);
    $rulesAfterBogusUnit = $model->ruleGetAll($compId);
    check("absent's rate_unit falls back to its default (day) when an invalid value is sent", $rulesAfterBogusUnit['absent'][0]['rate_unit'], 'day');
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = ? AND event_code = 'absent'")->execute([$compId]);

    echo "=== Save 'late' as flat_amount (edit, by id, of the row just created above) ===\n";
    $saveLate = $model->ruleSave(['id' => $lateDefaultId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5], $compId, $userId);
    checkTrue('late flat_amount save succeeds' . (empty($saveLate['status']) ? " ({$saveLate['message']})" : ''), $saveLate['status']);
    $lateRuleId = $saveLate['id'];
    check('same row id as before (update, not a new row)', (int)$lateRuleId, (int)$lateDefaultId);
    $lateAfterDefaultUnitResave = $model->ruleGetAll($compId);
    check("late's rate_unit defaults back to minute when this save omitted rate_unit", $lateAfterDefaultUnitResave['late'][0]['rate_unit'], 'minute');

    echo "=== Save 'absent' as percent_of_rate -- independent from 'late' ===\n";
    $saveAbsent = $model->ruleSave(['event_code' => 'absent', 'method_code' => 'percent_of_rate', 'multiplier_rate' => 0.75], $compId, $userId);
    checkTrue('absent percent_of_rate save succeeds', $saveAbsent['status']);
    $absentRuleId = $saveAbsent['id'];
    check('late and absent got different row ids (one default per event, not a shared row)', $lateRuleId !== $absentRuleId, true);

    $allAfterTwoSaves = $model->ruleGetAll($compId);
    check("late's method_code is flat_amount", $allAfterTwoSaves['late'][0]['method_code'], 'flat_amount');
    check("late's rate_per_unit round-trips", (float)$allAfterTwoSaves['late'][0]['rate_per_unit'], 2.5);
    check("absent's method_code is percent_of_rate (unaffected by late's save)", $allAfterTwoSaves['absent'][0]['method_code'], 'percent_of_rate');
    check("absent's multiplier_rate round-trips", (float)$allAfterTwoSaves['absent'][0]['multiplier_rate'], 0.75);
    check("unpaid_leave is still untouched/default (only late and absent were saved)", $allAfterTwoSaves['unpaid_leave'][0]['method_code'], 'percent_of_rate');
    check("unpaid_leave still has no id (never saved)", $allAfterTwoSaves['unpaid_leave'][0]['id'], null);

    echo "=== Re-save 'late' by id (update -- same row, no duplicate) ===\n";
    $resaveLate = $model->ruleSave(['id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'percent_of_rate', 'multiplier_rate' => 1.25], $compId, $userId);
    checkTrue('re-save succeeds' . (empty($resaveLate['status']) ? " ({$resaveLate['message']})" : ''), $resaveLate['status']);
    check('still the same row id (update, not a new row)', (int)$resaveLate['id'], (int)$lateRuleId);
    $countLateRows = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rules WHERE comp_id = {$compId} AND event_code = 'late'")->fetchColumn();
    check('still exactly 1 row for (comp_id, late)', $countLateRows, 1);
    $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rules WHERE comp_id = {$compId}")->fetchColumn();
    check('exactly 2 total rows across both configured events (late + absent)', $totalRows, 2);

    echo "=== Saving 'late' again WITHOUT an id is now rejected as a duplicate default ===\n";
    // 2026-08-30 behavior change: with multiple rows now possible per event, ruleSave() can no
    // longer implicitly upsert-by-event_code -- an id is required to edit an existing row. Omitting
    // id always means "create a new variant", so a 2nd unscoped (company-wide default) save for the
    // same event correctly collides with the one that already exists.
    $dupDefault = $model->ruleSave(['event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 9], $compId, $userId);
    checkFalse('creating a 2nd company-wide default for the same event (no id) is rejected', $dupDefault['status']);

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
    check('2 brackets returned for unpaid_leave', count($allAfterBrackets['unpaid_leave'][0]['brackets']), 2);
    check('brackets sorted by min_units ascending', array_map('intval', array_column($allAfterBrackets['unpaid_leave'][0]['brackets'], 'min_units')), [1, 3]);
    checkTrue("late/absent brackets are empty (bracket rows scoped to unpaid_leave's rule_id only)",
        empty($allAfterBrackets['late'][0]['brackets']) && empty($allAfterBrackets['absent'][0]['brackets']));

    $resaveLeaveBrackets = $model->ruleSave([
        'id' => $leaveRuleId, 'event_code' => 'unpaid_leave', 'method_code' => 'tiered_bracket',
        'brackets' => [['min_units' => 0, 'max_units' => null, 'deduction_amount' => 9999]],
    ], $compId, $userId);
    checkTrue('re-save with 1 bracket succeeds', $resaveLeaveBrackets['status']);
    $bracketCountInDb = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rule_brackets WHERE rule_id = {$leaveRuleId}")->fetchColumn();
    check('exactly 1 bracket row after re-save (old 2 replaced, not accumulated)', $bracketCountInDb, 1);

    echo "=== Switching unpaid_leave away from tiered_bracket clears its brackets ===\n";
    $backToFlat = $model->ruleSave(['id' => $leaveRuleId, 'event_code' => 'unpaid_leave', 'method_code' => 'flat_amount', 'rate_per_unit' => 100], $compId, $userId);
    checkTrue('switch to flat_amount succeeds', $backToFlat['status']);
    $bracketCountAfterSwitch = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rule_brackets WHERE rule_id = {$leaveRuleId}")->fetchColumn();
    check('brackets cleared after switching away from tiered_bracket', $bracketCountAfterSwitch, 0);

    echo "=== 2026-08-30 (Phase 8, T043) -- 'early_leave' configures independently, same shape as 'late' ===\n";
    $saveEarlyLeave = $model->ruleSave(['event_code' => 'early_leave', 'method_code' => 'flat_amount', 'rate_unit' => 'minute', 'rate_per_unit' => 3.0], $compId, $userId);
    checkTrue('early_leave flat_amount save succeeds' . (empty($saveEarlyLeave['status']) ? " ({$saveEarlyLeave['message']})" : ''), $saveEarlyLeave['status']);
    $earlyLeaveRuleId = $saveEarlyLeave['id'];
    $allAfterEarlyLeave = $model->ruleGetAll($compId);
    check("early_leave's method_code is flat_amount", $allAfterEarlyLeave['early_leave'][0]['method_code'], 'flat_amount');
    check("early_leave's rate_per_unit round-trips", (float)$allAfterEarlyLeave['early_leave'][0]['rate_per_unit'], 3.0);
    check("early_leave's rate_unit round-trips as minute", $allAfterEarlyLeave['early_leave'][0]['rate_unit'], 'minute');
    // 'late' was already resaved to percent_of_rate (multiplier 1.25) by the "Re-save 'late' by id"
    // section earlier in this file -- this assertion only confirms early_leave's own save didn't
    // ALSO touch late's row (independent rows), not that late is still at its very first value.
    check("late is unaffected by early_leave's save (independent rows)", $allAfterEarlyLeave['late'][0]['method_code'], 'percent_of_rate');
    check('late and early_leave got different row ids', $lateRuleId !== $earlyLeaveRuleId, true);

    echo "=== 2026-08-30 (T015, \"เพิ่มตัวเลือก 'ไม่หัก'\") -- no_deduction needs no extra config at all ===\n";
    // Real bug found and fixed while adding this method: ruleSave()'s validation used to be an
    // unconditional trailing `else { // tiered_bracket }` that assumed anything not flat_amount/
    // percent_of_rate MUST be tiered_bracket -- would have silently required a bracket row for a
    // method that has none. Confirmed fixed: saving with NO brackets/rate_per_unit/multiplier_rate
    // at all succeeds.
    // Updates the EXISTING company-wide 'late' default row (id already tracked in $lateRuleId from
    // earlier in this file) rather than creating a new one -- ruleSave() correctly rejects a second
    // default row for an event that already has one (see the dup-default test further up).
    $saveNoDeduction = $model->ruleSave(['id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'no_deduction'], $compId, $userId);
    checkTrue('saving method_code=no_deduction succeeds with zero extra fields' . (empty($saveNoDeduction['status']) ? " ({$saveNoDeduction['message']})" : ''), $saveNoDeduction['status']);
    $noDeductionRow = $pdo->query("SELECT method_code, rate_per_unit, multiplier_rate FROM attendance_deduction_rules WHERE id = {$lateRuleId}")->fetch(PDO::FETCH_ASSOC);
    check('persisted method_code is no_deduction', $noDeductionRow['method_code'] ?? null, 'no_deduction');
    check('rate_per_unit stays null (no rate to configure for this method)', $noDeductionRow['rate_per_unit'], null);
    check('multiplier_rate stays null too', $noDeductionRow['multiplier_rate'], null);
    $noDeductionBracketCount = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rule_brackets rb JOIN attendance_deduction_rules r ON r.id = rb.rule_id WHERE r.comp_id = {$compId} AND r.event_code = 'late'")->fetchColumn();
    check('no bracket rows created for no_deduction', $noDeductionBracketCount, 0);

    echo "--- SyncPayResolver::computeAttendanceDeductionFromConfig() -- always 0, regardless of minutes ---\n";
    require_once __DIR__ . '/../app/services/SyncPayResolver.php';
    $noDeductionResult1 = SyncPayResolver::computeAttendanceDeductionFromConfig(['method_code' => 'no_deduction'], [], 0.0, 100.0);
    check('0 minutes -> amount 0.00', $noDeductionResult1['amount'], 0.0);
    $noDeductionResult2 = SyncPayResolver::computeAttendanceDeductionFromConfig(['method_code' => 'no_deduction'], [], 99999.0, 500.0);
    check('a huge minute count and a large hourly rate STILL produce 0.00 -- no formula applies at all', $noDeductionResult2['amount'], 0.0);
    check('formula type is attendance_no_deduction (for the UI trace, even though this specific line never reaches the breakdown modal today -- see detail.js\'s own comment on that)', $noDeductionResult2['formula']['type'] ?? null, 'attendance_no_deduction');

    echo "--- previewCalculation() -- Configure modal's own \"try it out\" button, same no_deduction behavior ---\n";
    $previewNoDeduction = $model->previewCalculation(['method_code' => 'no_deduction'], 50000.0, 480.0);
    checkTrue('previewCalculation() accepts no_deduction' . (empty($previewNoDeduction['status']) ? " ({$previewNoDeduction['message']})" : ''), $previewNoDeduction['status']);
    check('preview amount is 0.00 even with a large sample (480 minutes = a full 8h day)', $previewNoDeduction['amount'], 0.0);

    echo "=== 2026-08-30: is_active toggle + department/team/individual exemptions ===\n";
    // No real structure_teams row exists anywhere in this dev DB yet (confirmed via a direct query
    // before writing this section) -- a minimal fixture row, created inside this same rolled-back
    // transaction, same "no dedicated fixture table exists yet, build a throwaway one" precedent as
    // every other test file in this suite that needs a table with zero real data to test against.
    $realDeptId = (int)$pdo->query("SELECT id FROM structure_departments WHERE comp_id = {$compId} AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    $realEmpIds = $pdo->query("SELECT id FROM employees WHERE comp_id = {$compId} AND deleted_at IS NULL ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    checkTrue('fixture sanity: a real department exists for comp_id=1', $realDeptId > 0);
    checkTrue('fixture sanity: at least 2 real employees exist for comp_id=1', count($realEmpIds) >= 2);
    $exemptEmpId = (int)$realEmpIds[0];
    $otherEmpId = (int)$realEmpIds[1];
    $pdo->prepare("INSERT INTO structure_teams (comp_id, team_code, team_name_th, team_name_en, status, created_by) VALUES (?, 'ADR_TEST_TEAM', 'ทีมทดสอบ', 'Test Team', 'active', ?)")
        ->execute([$compId, $userId]);
    $realTeamId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO structure_teams (comp_id, team_code, team_name_th, team_name_en, status, created_by) VALUES (?, 'ADR_TEST_TEAM2', 'ทีมทดสอบ 2', 'Test Team 2', 'active', ?)")
        ->execute([$compId, $userId]);
    $realTeamId2 = (int)$pdo->lastInsertId();

    echo "--- assignableOptions() ---\n";
    $options = $model->assignableOptions($compId);
    checkTrue('assignableOptions() returns a department list including the real fixture department', in_array($realDeptId, array_column($options['departments'], 'id')));
    checkTrue('assignableOptions() returns a team list including the fixture team', in_array($realTeamId, array_column($options['teams'], 'id')));
    checkTrue('assignableOptions() returns an employee list including both fixture employees', in_array($exemptEmpId, array_column($options['employees'], 'id')) && in_array($otherEmpId, array_column($options['employees'], 'id')));

    echo "--- is_active toggle: company-wide off exempts EVERYONE regardless of scope ---\n";
    $saveInactive = $model->ruleSave(['id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5, 'is_active' => false], $compId, $userId);
    checkTrue('save with is_active=false succeeds' . (empty($saveInactive['status']) ? " ({$saveInactive['message']})" : ''), $saveInactive['status']);
    check("ruleGetAll() reflects is_active=false for 'late'", $model->ruleGetAll($compId)['late'][0]['is_active'], false);
    check('employee is exempt from late (whole event off)', $model->exemptEventCodesForEmployee($compId, $exemptEmpId, $realDeptId, null), ['late']);
    check('a DIFFERENT employee is ALSO exempt (company-wide off, not scoped)', $model->exemptEventCodesForEmployee($compId, $otherEmpId, null, null), ['late']);

    echo "--- scoped exemptions: department ---\n";
    $model->ruleSave(['id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5, 'is_active' => true], $compId, $userId);
    $saveDeptExempt = $model->ruleSave([
        'id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5, 'is_active' => true,
        'exemptions' => [['scope_type' => 'department', 'scope_id' => $realDeptId]],
    ], $compId, $userId);
    checkTrue('save with a department exemption succeeds' . (empty($saveDeptExempt['status']) ? " ({$saveDeptExempt['message']})" : ''), $saveDeptExempt['status']);
    check('an employee IN that department is exempt from late', $model->exemptEventCodesForEmployee($compId, $exemptEmpId, $realDeptId, null), ['late']);
    check('an employee NOT in that department (department_id=null here) is NOT exempt', $model->exemptEventCodesForEmployee($compId, $otherEmpId, null, null), []);
    $rulesAfterDeptExempt = $model->ruleGetAll($compId);
    check('exactly 1 exemption row returned for late', count($rulesAfterDeptExempt['late'][0]['exemptions']), 1);
    check('exemption row scope_type is department', $rulesAfterDeptExempt['late'][0]['exemptions'][0]['scope_type'], 'department');
    checkTrue('exemption row carries a resolved display label, not just the raw id', !empty($rulesAfterDeptExempt['late'][0]['exemptions'][0]['label']));

    echo "--- scoped exemptions: team ---\n";
    $model->ruleSave([
        'id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5, 'is_active' => true,
        'exemptions' => [['scope_type' => 'team', 'scope_id' => $realTeamId]],
    ], $compId, $userId);
    check('an employee whose team_id matches is exempt', $model->exemptEventCodesForEmployee($compId, $exemptEmpId, null, $realTeamId), ['late']);
    check('an employee with no matching team is not exempt', $model->exemptEventCodesForEmployee($compId, $exemptEmpId, null, null), []);

    echo "--- scoped exemptions: individual employee (replaces the previous set -- delete+reinsert) ---\n";
    $model->ruleSave([
        'id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5, 'is_active' => true,
        'exemptions' => [['scope_type' => 'employee', 'scope_id' => $exemptEmpId]],
    ], $compId, $userId);
    check('the named employee is exempt regardless of department/team', $model->exemptEventCodesForEmployee($compId, $exemptEmpId, null, null), ['late']);
    check('a different employee is not exempt', $model->exemptEventCodesForEmployee($compId, $otherEmpId, null, null), []);
    check('the earlier TEAM exemption no longer applies (replaced, not accumulated)', $model->exemptEventCodesForEmployee($compId, $otherEmpId, null, $realTeamId), []);
    $rulesAfterEmpExempt = $model->ruleGetAll($compId);
    check('exactly 1 exemption row after replace (not 2 accumulated)', count($rulesAfterEmpExempt['late'][0]['exemptions']), 1);

    echo "--- validation: exemption scope refs must belong to this company ---\n";
    $saveBadScopeType = $model->ruleSave([
        'id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5,
        'exemptions' => [['scope_type' => 'branch', 'scope_id' => $realDeptId]],
    ], $compId, $userId);
    checkFalse('an unknown scope_type is rejected', $saveBadScopeType['status']);

    $saveNonexistentScope = $model->ruleSave([
        'id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5,
        'exemptions' => [['scope_type' => 'department', 'scope_id' => 999999]],
    ], $compId, $userId);
    checkFalse('a department id that does not exist is rejected', $saveNonexistentScope['status']);

    // Reset late back to a plain, no-exemption active default before the multi-scope section below.
    $model->ruleSave(['id' => $lateRuleId, 'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 2.5, 'is_active' => true, 'exemptions' => []], $compId, $userId);

    echo "=== 2026-08-30: multi-scope rule variants (Clone feature's backend) ===\n";
    echo "--- creating a team-scoped variant alongside the company-wide default ---\n";
    $saveTeamVariant = $model->ruleSave([
        'event_code' => 'late', 'method_code' => 'percent_of_rate', 'multiplier_rate' => 2.0,
        'scope_type' => 'team', 'scope_id' => $realTeamId, 'label' => 'Warehouse team - stricter late policy',
    ], $compId, $userId);
    checkTrue('team-scoped variant save succeeds' . (empty($saveTeamVariant['status']) ? " ({$saveTeamVariant['message']})" : ''), $saveTeamVariant['status']);
    $teamVariantId = $saveTeamVariant['id'];
    check('team-scoped variant got a different id than the default', $teamVariantId !== $lateRuleId, true);

    $lateVariants = $model->ruleGetAll($compId)['late'];
    check('late now has 2 variants: default + the team-scoped one', count($lateVariants), 2);
    check('the default (unscoped) row is listed first', $lateVariants[0]['scope_type'], null);
    check('the team-scoped row is listed second', $lateVariants[1]['scope_type'], 'team');
    check('the team-scoped row carries the resolved team name as scope_label, not just the raw id', $lateVariants[1]['scope_label'], 'ทีมทดสอบ');
    check('the team-scoped row carries the free-text label', $lateVariants[1]['label'], 'Warehouse team - stricter late policy');

    echo "--- duplicate-variant rejection: a 2nd rule for the SAME team+event is rejected ---\n";
    $dupTeamVariant = $model->ruleSave([
        'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 1, 'scope_type' => 'team', 'scope_id' => $realTeamId,
    ], $compId, $userId);
    checkFalse('a 2nd rule for the same (event, team) is rejected as a duplicate', $dupTeamVariant['status']);

    echo "--- a DIFFERENT team for the same event is fine (no collision) ---\n";
    $otherTeamVariant = $model->ruleSave([
        'event_code' => 'late', 'method_code' => 'flat_amount', 'rate_per_unit' => 3, 'scope_type' => 'team', 'scope_id' => $realTeamId2,
    ], $compId, $userId);
    checkTrue('rule for a different team on the same event succeeds', $otherTeamVariant['status']);
    check('late now has 3 variants total', count($model->ruleGetAll($compId)['late']), 3);

    echo "--- validation: scope_type without a valid scope_id is rejected ---\n";
    checkFalse('scope_type=team with no scope_id is rejected',
        $model->ruleSave(['event_code' => 'absent', 'method_code' => 'flat_amount', 'rate_per_unit' => 1, 'scope_type' => 'team'], $compId, $userId)['status']);
    checkFalse('scope_type=department with a team id from a DIFFERENT company is rejected',
        $model->ruleSave(['event_code' => 'absent', 'method_code' => 'flat_amount', 'rate_per_unit' => 1, 'scope_type' => 'department', 'scope_id' => 999999], $compId, $userId)['status']);

    echo "--- priority resolution: team > department > company-wide default (exemptEventCodesForEmployee's is_active gate) ---\n";
    // Turn the team-scoped variant OFF -- only employees resolving to THAT specific row (i.e. on
    // that team) should become exempt; the company-wide default (still active) must keep applying
    // to everyone else, including employees on realTeamId2 (whose own scoped row is still active).
    $model->ruleSave(['id' => $teamVariantId, 'event_code' => 'late', 'method_code' => 'percent_of_rate', 'multiplier_rate' => 2.0,
        'scope_type' => 'team', 'scope_id' => $realTeamId, 'is_active' => false], $compId, $userId);
    check('an employee on the OFF team resolves to the team row and is exempt', $model->exemptEventCodesForEmployee($compId, $exemptEmpId, null, $realTeamId), ['late']);
    check('an employee on the OTHER team (its own row still active) is NOT exempt', $model->exemptEventCodesForEmployee($compId, $otherEmpId, null, $realTeamId2), []);
    check('an employee on NEITHER team falls through to the still-active company-wide default -> not exempt', $model->exemptEventCodesForEmployee($compId, $otherEmpId, null, null), []);
    // Restore active before the amount-resolution assertions below.
    $model->ruleSave(['id' => $teamVariantId, 'event_code' => 'late', 'method_code' => 'percent_of_rate', 'multiplier_rate' => 2.0,
        'scope_type' => 'team', 'scope_id' => $realTeamId, 'is_active' => true], $compId, $userId);

    echo "--- priority resolution feeds through to the real calc engine (SyncPayResolver) ---\n";
    require_once __DIR__ . '/../app/services/SyncPayResolver.php';
    $resolver = new SyncPayResolver($pdo);
    $ref = new ReflectionClass($resolver);
    $method = $ref->getMethod('attendanceDeductionRuleFor');
    $method->setAccessible(true);
    $ruleForTeamEmployee = $method->invoke($resolver, $compId, 'late', null, $realTeamId);
    check('an employee on the scoped team resolves to the team-scoped rule (percent_of_rate, 2.0x)', $ruleForTeamEmployee['method_code'], 'percent_of_rate');
    check('...with the team-scoped multiplier, not the default', (float)$ruleForTeamEmployee['multiplier_rate'], 2.0);
    $ruleForOtherEmployee = $method->invoke($resolver, $compId, 'late', null, null);
    check('an employee with no matching team resolves to the company-wide default (flat_amount)', $ruleForOtherEmployee['method_code'], 'flat_amount');
    check('...with the default rate_per_unit', (float)$ruleForOtherEmployee['rate_per_unit'], 2.5);
    $ruleForOtherTeamEmployee = $method->invoke($resolver, $compId, 'late', null, $realTeamId2);
    check('an employee on the 2nd scoped team resolves to ITS OWN rule (flat_amount, rate 3)', $ruleForOtherTeamEmployee['method_code'], 'flat_amount');
    check('...with that team-scoped rate, not the default 2.5', (float)$ruleForOtherTeamEmployee['rate_per_unit'], 3.0);

    echo "--- ruleDelete(): the company-wide default can never be deleted, only a scoped variant ---\n";
    $deleteDefault = $model->ruleDelete($lateRuleId, $compId);
    checkFalse('deleting the default (unscoped) row is rejected', $deleteDefault['status']);
    check('the default row is still there afterward', (int)$model->ruleGetAll($compId)['late'][0]['id'], (int)$lateRuleId);

    $deleteScoped = $model->ruleDelete($teamVariantId, $compId);
    checkTrue('deleting a team-scoped variant succeeds' . (empty($deleteScoped['status']) ? " ({$deleteScoped['message']})" : ''), $deleteScoped['status']);
    check('late now has 2 variants left (default + the other team)', count($model->ruleGetAll($compId)['late']), 2);
    check('an employee on the now-deleted team\'s scope falls back to the company-wide default', $method->invoke($resolver, $compId, 'late', null, $realTeamId)['method_code'], 'flat_amount');

    checkFalse('deleting an id from a DIFFERENT company is rejected (not found)', $model->ruleDelete($otherTeamVariant['id'], 999999)['status']);
    checkFalse('deleting an unknown id is rejected', $model->ruleDelete(9999999, $compId)['status']);

    echo "=== 2026-08-30: previewCalculation() -- computes against DRAFT (unsaved) form values ===\n";
    $rowCountBeforePreview = (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rules WHERE comp_id = {$compId}")->fetchColumn();
    $flatPreview = $model->previewCalculation(['method_code' => 'flat_amount', 'rate_unit' => 'minute', 'rate_per_unit' => 2.5], 30000.0, 30.0);
    checkTrue('flat_amount preview succeeds' . (empty($flatPreview['status']) ? " ({$flatPreview['message']})" : ''), $flatPreview['status']);
    check('flat_amount: 2.5/min * 30min = 75.00', $flatPreview['amount'], 75.0);
    check('sample hourly_rate = 30000/30/8 = 125', $flatPreview['hourly_rate'], 125.0);

    $percentPreview = $model->previewCalculation(['method_code' => 'percent_of_rate', 'multiplier_rate' => 1.5], 30000.0, 30.0);
    checkTrue('percent_of_rate preview succeeds', $percentPreview['status']);
    check('percent_of_rate: (125/60)*30*1.5 = 93.75', $percentPreview['amount'], 93.75);

    $bracketPreview = $model->previewCalculation([
        'method_code' => 'tiered_bracket', 'rate_unit' => 'minute',
        'brackets' => [['min_units' => 0, 'max_units' => 15, 'deduction_amount' => 50], ['min_units' => 16, 'max_units' => null, 'deduction_amount' => 100]],
    ], 30000.0, 30.0);
    checkTrue('tiered_bracket preview succeeds', $bracketPreview['status']);
    check('tiered_bracket: 30 minutes falls in the 16+ bracket -> 100.00', $bracketPreview['amount'], 100.0);

    $bracketPreviewLow = $model->previewCalculation([
        'method_code' => 'tiered_bracket', 'rate_unit' => 'minute',
        'brackets' => [['min_units' => 0, 'max_units' => 15, 'deduction_amount' => 50], ['min_units' => 16, 'max_units' => null, 'deduction_amount' => 100]],
    ], 30000.0, 10.0);
    check('tiered_bracket: 10 minutes (a different sample) falls in the 0-15 bracket -> 50.00', $bracketPreviewLow['amount'], 50.0);

    checkTrue('previewCalculation() defaults to the 30000/30min sample when none is passed', $model->previewCalculation(['method_code' => 'flat_amount', 'rate_per_unit' => 1.0])['status']);
    check('default sample: 1.00/min * 30min = 30.00', $model->previewCalculation(['method_code' => 'flat_amount', 'rate_per_unit' => 1.0])['amount'], 30.0);

    check('previewCalculation() rejects an unknown method_code', $model->previewCalculation(['method_code' => 'not_a_real_method'])['status'], false);
    check('previewCalculation() rejects a non-positive sample base salary', $model->previewCalculation(['method_code' => 'flat_amount', 'rate_per_unit' => 1.0], 0.0, 30.0)['status'], false);
    check('previewCalculation() rejects a negative sample minutes', $model->previewCalculation(['method_code' => 'flat_amount', 'rate_per_unit' => 1.0], 30000.0, -5.0)['status'], false);

    check('previewCalculation() never actually writes a row (row count unchanged by every call above)',
        (int)$pdo->query("SELECT COUNT(*) FROM attendance_deduction_rules WHERE comp_id = {$compId}")->fetchColumn(), $rowCountBeforePreview);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
