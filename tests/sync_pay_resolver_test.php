<?php
/**
 * Verifies SyncPayResolver (2026-08-20, wiring Origami-synced attendance data into real payroll
 * calculation -- see the class's own docblock for the full design/simplifications). Not PHPUnit --
 * see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction
 * that is always rolled back. Run with: php tests/sync_pay_resolver_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/SyncPayResolver.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected, float $tolerance = 0.005): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < $tolerance : $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label} => " . var_export($actual, true) . "\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function findLine(array $lines, string $code): ?array {
    foreach ($lines as $l) {
        if ($l['code'] === $code) {
            return $l;
        }
    }
    return null;
}

try {
    $compId = 1; // TH company fixture convention, same as other tests in this suite.
    $userId = 1;

    // Real leftover data from actual interactive testing on this shared dev DB, not a test fixture
    // (confirmed via created_by/created_at, see feedback_dev_db_shared_state_test_fragility in
    // project memory / the same guard in tests/payroll_run_test.php) -- a real
    // attendance_deduction_rules row for comp_id=1's 'late' event breaks this file's "no rule
    // configured -- falls back to the default formula" assumption. Cleared for the duration of this
    // transaction only, rolled back at the end.
    $pdo->prepare("DELETE FROM `attendance_deduction_rules` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);

    $resolver = new SyncPayResolver($pdo);
    $baseSalary = 24000.0; // daily = 800, hourly = 100 (24000/30/8) -- easy numbers to hand-verify.

    // Base sync item row -- individual tests override just the fields they need.
    $blankRow = [
        'ot_req_working_day_hrs' => 0, 'ot_req_weekend_hrs' => 0, 'ot_req_holiday_hrs' => 0,
        'trip_allowance' => 0, 'late_mins' => 0, 'absent_days' => 0, 'item_values' => [],
    ];

    echo "=== No sync data at all -- resolver returns nothing, no errors ===\n";
    $r = $resolver->resolve($compId, $blankRow, $baseSalary);
    check('no earning lines', count($r['earning']), 0);
    check('no deduction lines', count($r['deduction']), 0);
    check('no errors', count($r['errors']), 0);

    echo "=== OT: weekday scope, hourly-base rate, no rate for weekend/holiday ===\n";
    // 2026-08-30 (OT Rate Set replacement): SyncPayResolver::resolve() no longer reads `ot_rates`
    // (retired table) or any table at all for OT rates -- it's fed a pre-resolved
    // $otRateSetRatesByScope array (scope_code => rate) by the caller, normally
    // OtRateSetModel::resolveRatesForEmployees() via PayrollRunModel::recalculate() (see that
    // model's own docblock; covered end-to-end there and in tests/payroll_run_test.php /
    // tests/employee_ot_rate_override_test.php). This file tests SyncPayResolver's OWN calculation
    // logic in isolation, so the rate array is built directly here rather than round-tripping
    // through OtRateSetModel/the DB.
    $insScope = $pdo->query("SELECT id, code FROM master_ot_scope_types")->fetchAll(PDO::FETCH_KEY_PAIR);
    $weekdayScopeId = array_search('weekday', $insScope, true);
    checkTrue('weekday OT scope exists in master data', $weekdayScopeId !== false);

    $otRateSetRates = [
        'weekday' => ['multiplier_rate' => 1.50, 'calculation_base' => 'hourly', 'calculation_method' => 'multiplier', 'flat_amount_rate' => 0.0],
    ];
    $otRow = $blankRow;
    $otRow['ot_req_working_day_hrs'] = 2.0; // hourlyRate=100 * 1.5 * 2 = 300
    $otRow['ot_req_weekend_hrs'] = 3.0;      // no configured rate for this scope -> error, no amount
    $r = $resolver->resolve($compId, $otRow, $baseSalary, [], [], null, null, true, [], $otRateSetRates);
    $otLine = findLine($r['earning'], 'OT');
    checkTrue('weekday OT line present', $otLine !== null);
    check('weekday OT amount = hourlyRate(100) * 1.5 * 2h = 300', $otLine['amount'] ?? null, 300.0);
    check('weekend OT (no rate configured) reports an error, not a guessed amount', in_array('missing_ot_rate_weekend', $r['errors'], true), true);
    check('only 1 earning line (weekend skipped, not a zero/garbage line)', count($r['earning']), 1);

    echo "=== OT: daily-base calculation_base ===\n";
    $otRateSetRates['holiday'] = ['multiplier_rate' => 2.00, 'calculation_base' => 'daily', 'calculation_method' => 'multiplier', 'flat_amount_rate' => 0.0];
    $dailyRow = $blankRow;
    $dailyRow['ot_req_holiday_hrs'] = 8.0; // 1 full day: dailyRate(800) * 2.0 * (8/8) = 1600
    $r = $resolver->resolve($compId, $dailyRow, $baseSalary, [], [], null, null, true, [], $otRateSetRates);
    $holidayLine = findLine($r['earning'], 'OT');
    check('holiday OT (daily base, 8h = 1 day) = dailyRate(800) * 2.0 * 1 = 1600', $holidayLine['amount'] ?? null, 1600.0);

    echo "=== OT: calculation_method=flat_amount (2026-08-21, \"เพิ่มตัวเลือก 'จำนวนเงินคงที่'\") -- hourly base ===\n";
    $otRateSetRates['weekend'] = ['multiplier_rate' => 1.0, 'calculation_base' => 'hourly', 'calculation_method' => 'flat_amount', 'flat_amount_rate' => 40.00];
    $flatOtRow = $blankRow;
    $flatOtRow['ot_req_weekend_hrs'] = 3.0; // flat_amount_rate(40) * 3h = 120, NOT hourlyRate(100)*multiplier*3
    $r = $resolver->resolve($compId, $flatOtRow, $baseSalary, [], [], null, null, true, [], $otRateSetRates);
    $flatOtLine = findLine($r['earning'], 'OT');
    check('flat_amount OT (hourly base) = 40.00 * 3h = 120.00, ignores salary-derived rate entirely', $flatOtLine['amount'] ?? null, 120.0);

    echo "=== OT: calculation_method=flat_amount -- daily base ===\n";
    $otRateSetRates['weekend'] = ['multiplier_rate' => 1.0, 'calculation_base' => 'daily', 'calculation_method' => 'flat_amount', 'flat_amount_rate' => 500.00];
    $flatOtDailyRow = $blankRow;
    $flatOtDailyRow['ot_req_weekend_hrs'] = 4.0; // half a day: flat_amount_rate(500) * (4/8) = 250
    $r = $resolver->resolve($compId, $flatOtDailyRow, $baseSalary, [], [], null, null, true, [], $otRateSetRates);
    $flatOtDailyLine = findLine($r['earning'], 'OT');
    check('flat_amount OT (daily base, 4h = half day) = 500.00 * 0.5 = 250.00', $flatOtDailyLine['amount'] ?? null, 250.0);

    echo "=== Trip allowance: direct money passthrough, no rate math ===\n";
    $tripRow = $blankRow;
    $tripRow['trip_allowance'] = 350.5;
    $r = $resolver->resolve($compId, $tripRow, $baseSalary);
    $tripLine = findLine($r['earning'], 'TRIP_ALLOW');
    check('trip allowance line = face value 350.50', $tripLine['amount'] ?? null, 350.5);

    echo "=== Late deduction: no rule configured -- falls back to (hourlyRate/60)*minutes*1.0 (unchanged formula) ===\n";
    $lateRow = $blankRow;
    $lateRow['late_mins'] = 30; // hourlyRate(100)/60 * 30 = 50
    $r = $resolver->resolve($compId, $lateRow, $baseSalary);
    $lateLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check('late deduction = (100/60)*30 = 50', $lateLine['amount'] ?? null, 50.0);

    echo "=== Late deduction: configurable rule -- flat_amount ===\n";
    $pdo->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, rate_per_unit, created_by) VALUES (?, 'late', 'flat_amount', 2.00, ?)")
        ->execute([$compId, $userId]);
    $r = $resolver->resolve($compId, $lateRow, $baseSalary);
    $lateFlatLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check('flat_amount: 2.00 * 30 = 60.00 (not the salary-derived 50.00)', $lateFlatLine['amount'] ?? null, 60.0);

    echo "=== Late deduction: configurable rule -- percent_of_rate with a non-1.0 multiplier ===\n";
    $pdo->prepare("UPDATE attendance_deduction_rules SET method_code = 'percent_of_rate', rate_per_unit = NULL, multiplier_rate = 2.00 WHERE comp_id = ? AND event_code = 'late'")
        ->execute([$compId]);
    $r = $resolver->resolve($compId, $lateRow, $baseSalary);
    $latePercentLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check('percent_of_rate @ 2.0x: (100/60)*30*2 = 100.00', $latePercentLine['amount'] ?? null, 100.0);

    echo "=== Late deduction: configurable rule -- tiered_bracket ===\n";
    $lateRuleId = (int)$pdo->query("SELECT id FROM attendance_deduction_rules WHERE comp_id = {$compId} AND event_code = 'late'")->fetchColumn();
    $pdo->prepare("UPDATE attendance_deduction_rules SET method_code = 'tiered_bracket', multiplier_rate = NULL WHERE id = ?")->execute([$lateRuleId]);
    $insBracket = $pdo->prepare("INSERT INTO attendance_deduction_rule_brackets (rule_id, min_units, max_units, deduction_amount, sort_order) VALUES (?, ?, ?, ?, ?)");
    $insBracket->execute([$lateRuleId, 5, 15, 20.00, 0]);
    $insBracket->execute([$lateRuleId, 16, 30, 50.00, 1]);
    $insBracket->execute([$lateRuleId, 31, null, 100.00, 2]);

    $r = $resolver->resolve($compId, $lateRow, $baseSalary); // late_mins=30 -> bracket 16-30
    $bracketMidLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check('tiered_bracket: 30 minutes falls in the 16-30 bracket -> flat 50.00', $bracketMidLine['amount'] ?? null, 50.0);

    $lateRowHigh = $blankRow;
    $lateRowHigh['late_mins'] = 45; // above the last bracket's min, max_units=NULL (unbounded)
    $r = $resolver->resolve($compId, $lateRowHigh, $baseSalary);
    $bracketUnboundedLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check('tiered_bracket: 45 minutes falls in the unbounded 31+ bracket -> flat 100.00', $bracketUnboundedLine['amount'] ?? null, 100.0);

    $lateRowGap = $blankRow;
    $lateRowGap['late_mins'] = 2; // below the first bracket's min_units=5 -- an intentional grace zone
    $r = $resolver->resolve($compId, $lateRowGap, $baseSalary);
    checkTrue('tiered_bracket: minutes below the first bracket (grace zone) produces no deduction line', findLine($r['deduction'], 'LATE_DEDUCT') === null);
    check('tiered_bracket: grace-zone gap is not treated as an error', count($r['errors']), 0);

    echo "=== Late deduction: tiered_bracket method selected but no brackets configured -- surfaced as an error, not guessed ===\n";
    $pdo->prepare("DELETE FROM attendance_deduction_rule_brackets WHERE rule_id = ?")->execute([$lateRuleId]);
    $r = $resolver->resolve($compId, $lateRow, $baseSalary);
    checkTrue('no brackets configured: no deduction line produced', findLine($r['deduction'], 'LATE_DEDUCT') === null);
    checkTrue('no brackets configured: error surfaced', in_array('attendance_deduction_no_brackets_configured_late', $r['errors'], true));

    echo "=== Late deduction: removing the company's rule reverts to the default formula ===\n";
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = ? AND event_code = 'late'")->execute([$compId]);
    $r = $resolver->resolve($compId, $lateRow, $baseSalary);
    $lateRevertedLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check('back to (100/60)*30 = 50.00 after the company rule is removed', $lateRevertedLine['amount'] ?? null, 50.0);

    echo "=== Late deduction: rate_unit='hour' -- flat_amount (2026-08-21, \"นาทีละกี่บาท ชั่วโมงละกี่บาท\") ===\n";
    $pdo->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, rate_unit, rate_per_unit, created_by) VALUES (?, 'late', 'flat_amount', 'hour', 20.00, ?)")
        ->execute([$compId, $userId]);
    $r = $resolver->resolve($compId, $lateRow, $baseSalary); // late_mins=30 -> 0.5h
    $lateHourFlatLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check('flat_amount rate_unit=hour: 20.00 * 0.5h (30min) = 10.00', $lateHourFlatLine['amount'] ?? null, 10.0);

    echo "=== Late deduction: rate_unit='hour' -- tiered_bracket ===\n";
    $lateHourRuleId = (int)$pdo->query("SELECT id FROM attendance_deduction_rules WHERE comp_id = {$compId} AND event_code = 'late'")->fetchColumn();
    $pdo->prepare("UPDATE attendance_deduction_rules SET method_code = 'tiered_bracket', rate_per_unit = NULL WHERE id = ?")->execute([$lateHourRuleId]);
    $pdo->prepare("INSERT INTO attendance_deduction_rule_brackets (rule_id, min_units, max_units, deduction_amount, sort_order) VALUES (?, ?, ?, ?, ?)")
        ->execute([$lateHourRuleId, 0, 1, 30.00, 0]); // 0-1 hour bracket
    $r = $resolver->resolve($compId, $lateRow, $baseSalary); // 30min = 0.5h -> falls in 0-1 bracket
    $lateHourBracketLine = findLine($r['deduction'], 'LATE_DEDUCT');
    check("tiered_bracket rate_unit=hour: 0.5h falls in 0-1 bracket -> flat 30.00", $lateHourBracketLine['amount'] ?? null, 30.0);
    // Cleanup: restore 'late' to its default (unconfigured) state -- later tests below assume the
    // default percent_of_rate@1.0 formula for late.
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = ? AND event_code = 'late'")->execute([$compId]);

    echo "=== Absent deduction: no rule configured -- falls back to dailyRate*days*1.0 (unchanged formula) ===\n";
    $absentRow = $blankRow;
    $absentRow['absent_days'] = 1.5; // dailyRate(800) * 1.5 = 1200
    $r = $resolver->resolve($compId, $absentRow, $baseSalary);
    $absentLine = findLine($r['deduction'], 'ABSENT_DEDUCT');
    check('absent deduction = 800 * 1.5 = 1200', $absentLine['amount'] ?? null, 1200.0);

    echo "=== Absent deduction: configurable rule -- flat_amount (per day) ===\n";
    $pdo->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, rate_unit, rate_per_unit, created_by) VALUES (?, 'absent', 'flat_amount', 'day', 300.00, ?)")
        ->execute([$compId, $userId]);
    $r = $resolver->resolve($compId, $absentRow, $baseSalary);
    $absentFlatLine = findLine($r['deduction'], 'ABSENT_DEDUCT');
    check('flat_amount: 300.00 * 1.5 = 450.00 (not the salary-derived 1200.00)', $absentFlatLine['amount'] ?? null, 450.0);

    echo "=== Absent deduction: configurable rule -- tiered_bracket (by days) ===\n";
    $absentRuleId = (int)$pdo->query("SELECT id FROM attendance_deduction_rules WHERE comp_id = {$compId} AND event_code = 'absent'")->fetchColumn();
    $pdo->prepare("UPDATE attendance_deduction_rules SET method_code = 'tiered_bracket', rate_unit = 'day', rate_per_unit = NULL WHERE id = ?")->execute([$absentRuleId]);
    $pdo->prepare("INSERT INTO attendance_deduction_rule_brackets (rule_id, min_units, max_units, deduction_amount, sort_order) VALUES (?, ?, ?, ?, ?)")
        ->execute([$absentRuleId, 1, 2, 500.00, 0]);
    $r = $resolver->resolve($compId, $absentRow, $baseSalary); // absent_days=1.5 -> bracket 1-2
    $absentBracketLine = findLine($r['deduction'], 'ABSENT_DEDUCT');
    check('tiered_bracket: 1.5 days falls in the 1-2 bracket -> flat 500.00', $absentBracketLine['amount'] ?? null, 500.0);
    // Cleanup: restore 'absent' to its default (unconfigured) state -- later tests below assume the
    // default percent_of_rate@1.0 formula for absent, same as before this rule-driven section existed.
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = ? AND event_code = 'absent'")->execute([$compId]);

    echo "=== Absent: 'late' and 'absent' rules are independently configured (saving one does not affect the other) ===\n";
    $lateStillTieredRow = $blankRow;
    $lateStillTieredRow['late_mins'] = 45;
    $rLate = $resolver->resolve($compId, $lateStillTieredRow, $baseSalary);
    checkTrue("late's own rule (removed above) is back to default, unaffected by absent's tiered_bracket config", findLine($rLate['deduction'], 'LATE_DEDUCT') !== null);
    check("late reverted amount unaffected by absent's config: (100/60)*45 = 75.00", findLine($rLate['deduction'], 'LATE_DEDUCT')['amount'] ?? null, 75.0);

    echo "=== Unpaid leave deduction: configurable rule -- percent_of_rate ===\n";
    $leaveRuleTestRow = $blankRow;
    $leaveRuleTestRow['leave_without_pay_days'] = 1.0;
    $pdo->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, multiplier_rate, created_by) VALUES (?, 'unpaid_leave', 'percent_of_rate', 0.5, ?)")
        ->execute([$compId, $userId]);
    $r = $resolver->resolve($compId, $leaveRuleTestRow, $baseSalary);
    $leaveRuleLine = findLine($r['deduction'], 'LEAVE_NO_PAY_DEDUCT');
    check('percent_of_rate @ 0.5x: dailyRate(800)*1.0*0.5 = 400.00 (half-pay unpaid leave policy)', $leaveRuleLine['amount'] ?? null, 400.0);
    // Cleanup: restore 'unpaid_leave' to its default (unconfigured) state -- the later "per-day rate
    // derived from salary" test below assumes the default percent_of_rate@1.0 formula.
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = ? AND event_code = 'unpaid_leave'")->execute([$compId]);

    echo "=== Absent: real-world item_code 'Absent' (not this company's catalog code 'ABSENT_DEDUCT') -- real bug report, was double-deducting ===\n";
    $absentAliasRow = $blankRow;
    $absentAliasRow['absent_days'] = 1.0; // structured column: dailyRate(800)*1 = 800
    $absentAliasRow['item_values'] = [
        ['item_id' => 1, 'item_code' => 'Absent', 'item_name' => 'Absent', 'item_type' => 'DEDUCTION', 'unit_type' => 'days', 'value' => 1.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $absentAliasRow, $baseSalary);
    $absentAliasDeducts = array_values(array_filter($r['deduction'], fn($l) => $l['code'] === 'ABSENT_DEDUCT'));
    check('exactly 1 ABSENT_DEDUCT line (item_code "Absent" recognized via alias, not double-deducted)', count($absentAliasDeducts), 1);
    check('amount is 800, not 1600 (the "Absent" item_values row must not also become a separate custom line)', $absentAliasDeducts[0]['amount'] ?? null, 800.0);
    checkTrue('no stray custom "Absent" line was also created', findLine($r['deduction'], 'CUSTOM:Absent') === null);

    echo "=== Absent: fully absent for the whole period -> deducts the FULL base salary, not a 30-day-divisor fraction ===\n";
    $fullAbsentRow = $blankRow;
    $fullAbsentRow['working_days'] = 22; // Origami's real working-day count for this employee/period
    $fullAbsentRow['absent_days'] = 22; // absent the entire period
    $r = $resolver->resolve($compId, $fullAbsentRow, $baseSalary);
    $fullAbsentLine = findLine($r['deduction'], 'ABSENT_DEDUCT');
    check('deduction equals the full base salary (24000/22*22 = 24000), not 24000/30*22 = 17600', $fullAbsentLine['amount'] ?? null, $baseSalary);

    echo "=== Absent: 4 redundant representations at once (real report, 2026-08-20) -- only 1 line, finest unit (minutes) wins ===\n";
    $absentMultiRow = $blankRow;
    $absentMultiRow['absent_days'] = 1.0; // structured, would be dailyRate(800)*1 = 800 if used
    $absentMultiRow['absent_mins'] = 480; // structured, finest unit: hourlyRate(100)/60*480 = 800 (1 day = 8h = 480min, same event)
    $absentMultiRow['item_values'] = [
        ['item_id' => 1, 'item_code' => 'ABSENT_DEDUCT', 'item_name' => 'Absence', 'item_type' => 'DEDUCTION', 'unit_type' => 'days', 'value' => 1.0, 'remark' => null],
        ['item_id' => 1, 'item_code' => 'ABSENT_DEDUCT', 'item_name' => 'Absence', 'item_type' => 'DEDUCTION', 'unit_type' => 'hours', 'value' => 8.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $absentMultiRow, $baseSalary);
    $absentDeducts = array_values(array_filter($r['deduction'], fn($l) => $l['code'] === 'ABSENT_DEDUCT'));
    check('exactly 1 ABSENT_DEDUCT line despite 4 representations (2 structured + 2 item_values)', count($absentDeducts), 1);
    check('amount uses the finest unit (minutes): (100/60)*480 = 800, not 800*4=3200', $absentDeducts[0]['amount'] ?? null, 800.0);

    echo "=== Absent: only the coarsest unit (days) sent -- still resolves correctly (may not send all units) ===\n";
    $absentDaysOnlyRow = $blankRow;
    $absentDaysOnlyRow['absent_days'] = 2.0; // dailyRate(800)*2 = 1600
    $r = $resolver->resolve($compId, $absentDaysOnlyRow, $baseSalary);
    $absentDaysOnlyLine = findLine($r['deduction'], 'ABSENT_DEDUCT');
    check('absent deduction from days-only data = 800*2 = 1600', $absentDaysOnlyLine['amount'] ?? null, 1600.0);

    echo "=== Absent: mixed-shift-length accuracy (2026-08-21, \"บางที่ทำ 7 ชั่วโมงครึ่ง เสาร์ครึ่งวัน บางที่ทำ 9 ชั่วโมง\") -- percent_of_rate now scales with actual minutes, not a day-average ===\n";
    $mixedShiftRow = $blankRow;
    $mixedShiftRow['working_mins'] = 9900; // irregular period total (e.g. 7.5h weekdays + short Saturdays) -- NOT a clean multiple of 480
    $mixedShiftRow['absent_mins'] = 240; // a half-day Saturday absence (4h), reported directly in minutes
    $r = $resolver->resolve($compId, $mixedShiftRow, $baseSalary);
    $mixedShiftLine = findLine($r['deduction'], 'ABSENT_DEDUCT');
    check('half-day Saturday absence = baseSalary(24000)*240/9900 = 581.82 (true per-minute rate, not a fixed 8h/day assumption)', $mixedShiftLine['amount'] ?? null, 581.82);

    $mixedShiftFullDayRow = $blankRow;
    $mixedShiftFullDayRow['working_mins'] = 9900;
    $mixedShiftFullDayRow['absent_mins'] = 450; // a full 7.5h weekday absence
    $rFull = $resolver->resolve($compId, $mixedShiftFullDayRow, $baseSalary);
    $mixedShiftFullLine = findLine($rFull['deduction'], 'ABSENT_DEDUCT');
    check('full weekday absence = baseSalary(24000)*450/9900 = 1090.91', $mixedShiftFullLine['amount'] ?? null, 1090.91);

    checkTrue('half-day Saturday deducts proportionally less than a full weekday (240min vs 450min), not the same flat "1 day" amount', ($mixedShiftLine['amount'] ?? 0) < ($mixedShiftFullLine['amount'] ?? 0));

    echo "=== Unpaid leave deduction: per-day rate derived from salary ===\n";
    $leaveRow = $blankRow;
    $leaveRow['leave_without_pay_days'] = 2.0; // dailyRate(800) * 2.0 = 1600
    $r = $resolver->resolve($compId, $leaveRow, $baseSalary);
    $leaveLine = findLine($r['deduction'], 'LEAVE_NO_PAY_DEDUCT');
    checkTrue('unpaid leave deduction line present', $leaveLine !== null);
    check('unpaid leave deduction = 800 * 2.0 = 1600', $leaveLine['amount'] ?? null, 1600.0);

    echo "=== Late: structured column AND a matching item_values row sent together -- still only 1 line ===\n";
    $lateBothRow = $blankRow;
    $lateBothRow['late_mins'] = 30; // structured, finest unit already (minutes)
    $lateBothRow['item_values'] = [
        ['item_id' => 2, 'item_code' => 'LATE_DEDUCT', 'item_name' => 'Late', 'item_type' => 'DEDUCTION', 'unit_type' => 'hours', 'value' => 0.5, 'remark' => null], // same 30 min, coarser unit
    ];
    $r = $resolver->resolve($compId, $lateBothRow, $baseSalary);
    $lateBoth = array_values(array_filter($r['deduction'], fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('exactly 1 LATE_DEDUCT line despite structured + item_values both present', count($lateBoth), 1);
    check('amount uses the finest unit (minutes, from the structured column): (100/60)*30 = 50', $lateBoth[0]['amount'] ?? null, 50.0);

    echo "=== Generic custom item: same item_code sent with 2 different units at once -- only 1 line ===\n";
    $customMultiRow = $blankRow;
    $customMultiRow['item_values'] = [
        ['item_id' => 50, 'item_code' => 'CUSTOM_MULTI_UNIT', 'item_name' => 'Custom Multi Unit', 'item_type' => 'DEDUCTION', 'unit_type' => 'days', 'value' => 1.0, 'remark' => null],
        ['item_id' => 50, 'item_code' => 'CUSTOM_MULTI_UNIT', 'item_name' => 'Custom Multi Unit', 'item_type' => 'DEDUCTION', 'unit_type' => 'minutes', 'value' => 480.0, 'remark' => 'same event, finer unit'],
    ];
    $r = $resolver->resolve($compId, $customMultiRow, $baseSalary);
    $customMultiLines = array_values(array_filter($r['deduction'], fn($l) => $l['code'] === 'CUSTOM:Custom Multi Unit'));
    check('exactly 1 line for a generic item_code sent with 2 units at once', count($customMultiLines), 1);
    check('amount uses the finest unit (minutes): (100/60)*480 = 800, not 800+800=1600', $customMultiLines[0]['amount'] ?? null, 800.0);

    echo "=== item_values: OT rows are skipped entirely (already covered by structured columns) ===\n";
    $itemValueOtRow = $blankRow;
    $itemValueOtRow['ot_req_working_day_hrs'] = 2.0; // covered via structured column, amount 300 (from the weekday rate fixture above)
    $itemValueOtRow['item_values'] = [
        ['item_id' => 4, 'item_code' => 'OT', 'item_name' => 'Overtime', 'item_type' => 'INCOME', 'unit_type' => 'hours', 'value' => 2.0, 'remark' => null],
        ['item_id' => 4, 'item_code' => 'OT', 'item_name' => 'Overtime', 'item_type' => 'INCOME', 'unit_type' => 'days', 'value' => 0.25, 'remark' => 'same OT, days unit'],
    ];
    $r = $resolver->resolve($compId, $itemValueOtRow, $baseSalary, [], [], null, null, true, [], $otRateSetRates);
    check('exactly 1 OT earning line (item_values OT rows did not double it)', count(array_filter($r['earning'], fn($l) => $l['code'] === 'OT')), 1);
    $otAmounts = array_column(array_filter($r['earning'], fn($l) => $l['code'] === 'OT'), 'amount');
    check('OT amount still exactly 300 (not doubled/tripled by item_values rows)', $otAmounts[0] ?? null, 300.0);

    echo "=== item_values: catalog match by item_code (case-insensitive) ===\n";
    $catalogRow = $blankRow;
    $catalogRow['item_values'] = [
        ['item_id' => 9, 'item_code' => 'bonus', 'item_name' => 'Should be ignored, catalog name used instead', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 1234.56, 'remark' => 'catalog match test'],
    ];
    $r = $resolver->resolve($compId, $catalogRow, $baseSalary);
    $bonusLine = findLine($r['earning'], 'BONUS');
    checkTrue('BONUS catalog item matched case-insensitively', $bonusLine !== null);
    check('null unit_type value used directly as money (1234.56)', $bonusLine['amount'] ?? null, 1234.56);
    check('catalog name used, not the payload item_name', $bonusLine['name_en'] ?? null, 'Bonus');

    echo "=== item_values: no catalog match -> custom line, unit conversion still applied ===\n";
    $customRow = $blankRow;
    $customRow['item_values'] = [
        ['item_id' => 99, 'item_code' => 'CUSTOM_ATTENDANCE_BONUS', 'item_name' => 'Attendance Bonus', 'item_type' => 'INCOME', 'unit_type' => 'days', 'value' => 1.0, 'remark' => 'no catalog match'],
        ['item_id' => 100, 'item_code' => 'CUSTOM_PENALTY', 'item_name' => 'Disciplinary Penalty', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 200.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $customRow, $baseSalary);
    $customEarning = findLine($r['earning'], 'CUSTOM:Attendance Bonus');
    checkTrue('unmatched INCOME item_code becomes a custom earning line', $customEarning !== null);
    check('custom earning, unit_type=days -> dailyRate(800)*1.0 = 800', $customEarning['amount'] ?? null, 800.0);
    checkTrue('custom line flagged is_custom', $customEarning['is_custom'] ?? false);
    $customDeduction = findLine($r['deduction'], 'CUSTOM:Disciplinary Penalty');
    checkTrue('unmatched DEDUCTION item_code becomes a custom deduction line', $customDeduction !== null);
    check('custom deduction, unit_type=null -> face value 200', $customDeduction['amount'] ?? null, 200.0);

    echo "=== Zero/negative values are skipped, not pushed as zero lines ===\n";
    $zeroRow = $blankRow;
    $zeroRow['item_values'] = [
        ['item_id' => 1, 'item_code' => 'ZERO_ITEM', 'item_name' => 'Zero', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 0, 'remark' => null],
        ['item_id' => 2, 'item_code' => 'NEG_ITEM', 'item_name' => 'Negative', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => -5, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $zeroRow, $baseSalary);
    check('no earning lines from zero/negative item_values', count($r['earning']), 0);

    // ---------- Attendance-data overrides (2026-08-21, explicit request: "ต้องการแก้ตัวเลขดิบที่
    // Sync มา ไม่ใช่แค่ยอดเงิน") -- the resolve() 4th param corrects the RAW input before any
    // candidate-pool logic runs, rather than overriding the resulting amount afterward. Each case
    // below deliberately leaves the ORIGINAL (uncorrected) structured data in the row too, to prove
    // the override truly bypasses the pool rather than just happening to agree with it. ----------
    echo "=== Attendance override: late_mins bypasses the pool entirely (raw data left stale on purpose) ===\n";
    $lateOverrideRow = $blankRow;
    $lateOverrideRow['late_mins'] = 30; // if NOT bypassed: (100/60)*30 = 50
    $rLateOv = $resolver->resolve($compId, $lateOverrideRow, $baseSalary, ['late_mins' => 5]);
    $lateOvLine = findLine($rLateOv['deduction'], 'LATE_DEDUCT');
    checkTrue('LATE_DEDUCT line present with the override applied', $lateOvLine !== null);
    check('override wins: (100/60)*5 = 8.33, NOT the stale raw 30min figure (50.00)', $lateOvLine['amount'] ?? null, 8.33);
    checkTrue('overridden line note is suffixed _corrected', strpos($lateOvLine['note'] ?? '', '_corrected') !== false);

    echo "=== Attendance override: absent_days bypasses the pool EVEN WHEN both absent_days AND absent_mins (the exact multi-unit trap) are still present in the row ===\n";
    $absentOverrideRow = $blankRow;
    $absentOverrideRow['absent_days'] = 1;    // if NOT bypassed: finest unit is absent_mins below -> 800
    $absentOverrideRow['absent_mins'] = 480;  // same stale event, finer unit -- would normally win the pool
    $rAbsentOv = $resolver->resolve($compId, $absentOverrideRow, $baseSalary, ['absent_days' => 0.5]);
    $absentOvLine = findLine($rAbsentOv['deduction'], 'ABSENT_DEDUCT');
    checkTrue('ABSENT_DEDUCT line present with the override applied', $absentOvLine !== null);
    check('override (0.5 day = 240min) wins: (100/60)*240 = 400.00, NOT the stale 800.00 the pool would have picked', $absentOvLine['amount'] ?? null, 400.0);

    echo "=== Attendance override: leave_without_pay_days bypasses the pool ===\n";
    $leaveOverrideRow = $blankRow;
    $leaveOverrideRow['leave_without_pay_days'] = 3; // if NOT bypassed: 3*480min -> (100/60)*1440 = 2400
    $rLeaveOv = $resolver->resolve($compId, $leaveOverrideRow, $baseSalary, ['leave_without_pay_days' => 1]);
    $leaveOvLine = findLine($rLeaveOv['deduction'], 'LEAVE_NO_PAY_DEDUCT');
    checkTrue('LEAVE_NO_PAY_DEDUCT line present with the override applied', $leaveOvLine !== null);
    check('override (1 day = 480min) wins: (100/60)*480 = 800.00, NOT the stale 2400.00 the pool would have picked', $leaveOvLine['amount'] ?? null, 800.0);

    echo "=== Attendance override: correcting to 0 suppresses the line entirely (not a zero-amount line) ===\n";
    $lateZeroOverrideRow = $blankRow;
    $lateZeroOverrideRow['late_mins'] = 30; // raw data says late -- override says otherwise
    $rLateZero = $resolver->resolve($compId, $lateZeroOverrideRow, $baseSalary, ['late_mins' => 0]);
    checkTrue('LATE_DEDUCT line does not appear at all when corrected to 0', findLine($rLateZero['deduction'], 'LATE_DEDUCT') === null);

    echo "=== Attendance override: OT hours (no candidate pool involved for OT -- pure substitution) ===\n";
    $otOverrideRow = $blankRow;
    $otOverrideRow['ot_req_working_day_hrs'] = 5; // if NOT overridden: hourlyRate(100)*1.5*5 = 750 (reuses the weekday OT rate fixture from earlier in this file)
    $rOtOv = $resolver->resolve($compId, $otOverrideRow, $baseSalary, ['ot_req_working_day_hrs' => 2], [], null, null, true, [], $otRateSetRates);
    $otOvLine = findLine($rOtOv['earning'], 'OT');
    checkTrue('OT line present with the override applied', $otOvLine !== null);
    check('override wins: hourlyRate(100)*1.5*2h = 300.00, NOT the stale 750.00', $otOvLine['amount'] ?? null, 300.0);

    echo "=== Attendance override: trip_allowance (direct money substitution, bypasses its own candidate pool too) ===\n";
    $tripOverrideRow = $blankRow;
    $tripOverrideRow['trip_allowance'] = 300; // stale raw value
    $rTripOv = $resolver->resolve($compId, $tripOverrideRow, $baseSalary, ['trip_allowance' => 500]);
    $tripOvLine = findLine($rTripOv['earning'], 'TRIP_ALLOW');
    checkTrue('TRIP_ALLOW line present with the override applied', $tripOvLine !== null);
    check('override wins: face value 500.00, NOT the stale 300.00', $tripOvLine['amount'] ?? null, 500.0);

    echo "=== Attendance override: an omitted/null key behaves exactly as if the param were never passed ===\n";
    $noOverrideRow = $blankRow;
    $noOverrideRow['late_mins'] = 30;
    $rNoOv = $resolver->resolve($compId, $noOverrideRow, $baseSalary, ['absent_days' => null]); // present but null, and an unrelated field
    $noOvLine = findLine($rNoOv['deduction'], 'LATE_DEDUCT');
    check('late_mins untouched by an override array that never mentions it: (100/60)*30 = 50.00', $noOvLine['amount'] ?? null, 50.0);

    // 2026-08-29, real bug found and fixed (explicit report: "ค่าเที่ยวยังแสดงผลอยู่ครับ ทั้งๆที่ไม่ได้
    // กด Sync มาจาก Origami เพราะติ๊กส่วนนั้นออกไป" -- deactivating the Trip Allowance Earning Type in
    // Payroll Configuration had NO effect on calculation at all; the line still showed under a
    // hardcoded fallback label). Root cause was pedTypeBySourceEvent() treating "deactivated" and
    // "never configured" identically (both returned null, both fell through to the same hardcoded
    // default) -- see that method's own docblock. Reuses whatever real payroll_earning_deduction_types
    // row (if any) already maps comp_id=1's 'trip_allowance' source event, so this exercises the
    // REAL row this dev DB has, not a synthetic one that might not match how the bug actually
    // manifested -- inserts one only if comp_id=1 genuinely has none configured yet.
    echo "=== Admin deactivates a source_event_code-mapped catalog type -- must be excluded entirely, not silently fall back to a hardcoded default ===\n";
    $stmtTripType = $pdo->prepare("SELECT id, status FROM payroll_earning_deduction_types WHERE comp_id = :c AND source_event_code = 'trip_allowance' AND deleted_at IS NULL LIMIT 1");
    $stmtTripType->execute([':c' => $compId]);
    $tripType = $stmtTripType->fetch(PDO::FETCH_ASSOC);
    if ($tripType === false) {
        $insTripType = $pdo->prepare("INSERT INTO payroll_earning_deduction_types
            (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, tax_treatment, source_event_code, is_sync_only, status, created_by)
            VALUES (:comp_id, 'TRIP_ALLOW_TEST_FIXTURE', 'ค่าเที่ยวทดสอบ', 'Test Trip Allowance', 'earning', 'manual_entry', 'taxable', 'trip_allowance', 1, 'active', :user_id)");
        $insTripType->execute([':comp_id' => $compId, ':user_id' => $userId]);
        $tripTypeId = (int)$pdo->lastInsertId();
    } else {
        $tripTypeId = (int)$tripType['id'];
    }
    $pdo->prepare("UPDATE payroll_earning_deduction_types SET status = 'inactive' WHERE id = :id")->execute([':id' => $tripTypeId]);

    $deactivatedTripRow = $blankRow;
    $deactivatedTripRow['trip_allowance'] = 350.5;
    $rDeactivatedTrip = $resolver->resolve($compId, $deactivatedTripRow, $baseSalary);
    $leakedTripLine = array_filter($rDeactivatedTrip['earning'], fn($l) => stripos((string)($l['note'] ?? ''), 'sync_trip_allowance') !== false);
    check('trip_allowance produces ZERO earning lines once its catalog type is deactivated (no fallback leak)', count($leakedTripLine), 0);

    $pdo->prepare("UPDATE payroll_earning_deduction_types SET status = 'active' WHERE id = :id")->execute([':id' => $tripTypeId]);
    $rReactivatedTrip = $resolver->resolve($compId, $deactivatedTripRow, $baseSalary);
    $restoredTripLine = array_filter($rReactivatedTrip['earning'], fn($l) => stripos((string)($l['note'] ?? ''), 'sync_trip_allowance') !== false);
    check('re-activating the same type immediately makes trip_allowance compute again (fix is reversible, not a one-way regression)', count($restoredTripLine), 1);

    // 2026-08-29, real bugs found and fixed (explicit report: "Leave Approved ต้องไม่นำมาบวกเป็นเงินได้
    // ลาไม่รับเงิน และลารออนุมัติ ถึงจะเอามาคำนวณเป็นเงินหัก...มีส่ง หักเงินคำประกันการทำงานมา แต่ไม่นำไปคิดเป็น
    // รายการหัก...OT ยังคำนวณไม่ถูกต้อง").
    echo "=== INFO-typed item (e.g. Leave Approved) with no catalog mapping produces NO line at all -- must not silently default to income ===\n";
    $leaveApprovedRow = $blankRow;
    $leaveApprovedRow['item_values'] = [
        ['item_id' => 200, 'item_code' => 'LEAVE_APPROVED', 'item_name' => 'Leave Approved', 'item_type' => 'INFO', 'unit_type' => 'days', 'value' => 2.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $leaveApprovedRow, $baseSalary);
    checkTrue('no earning line for an INFO-typed item', findLine($r['earning'], 'CUSTOM:Leave Approved') === null);
    checkTrue('no deduction line for an INFO-typed item either', findLine($r['deduction'], 'CUSTOM:Leave Approved') === null);
    check('zero earning lines produced at all', count($r['earning']), 0);
    check('zero deduction lines produced at all', count($r['deduction']), 0);

    echo "=== Leave pending approval: new rule-driven deduction event, no rule configured -- default formula, item_code matched via alias only (no structured column) ===\n";
    $leavePendingRow = $blankRow;
    $leavePendingRow['item_values'] = [
        ['item_id' => 201, 'item_code' => 'LEAVE_PENDING', 'item_name' => 'Leave Pending', 'item_type' => 'INFO', 'unit_type' => 'days', 'value' => 1.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $leavePendingRow, $baseSalary);
    $leavePendingLine = findLine($r['deduction'], 'LEAVE_PENDING_DEDUCT');
    checkTrue('LEAVE_PENDING_DEDUCT line present despite the payload itself being tagged INFO, not DEDUCTION', $leavePendingLine !== null);
    check('1 day pending leave -> minutes(480)*(100/60)*1.0 = 800.00, same default formula as unpaid leave', $leavePendingLine['amount'] ?? null, 800.0);

    echo "=== Leave pending approval: company-configured rule (percent_of_rate 0.5x, half-pay policy) ===\n";
    $pdo->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, multiplier_rate, created_by) VALUES (?, 'leave_pending', 'percent_of_rate', 0.5, ?)")
        ->execute([$compId, $userId]);
    $r = $resolver->resolve($compId, $leavePendingRow, $baseSalary);
    $leavePendingHalfLine = findLine($r['deduction'], 'LEAVE_PENDING_DEDUCT');
    check('percent_of_rate @ 0.5x: (100/60)*480*0.5 = 400.00', $leavePendingHalfLine['amount'] ?? null, 400.0);
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = ? AND event_code = 'leave_pending'")->execute([$compId]);

    echo "=== Leave pending approval: zero value produces no line (no leave currently pending) ===\n";
    $leavePendingZeroRow = $blankRow;
    $leavePendingZeroRow['item_values'] = [
        ['item_id' => 201, 'item_code' => 'LEAVE_PENDING', 'item_name' => 'Leave Pending', 'item_type' => 'INFO', 'unit_type' => 'days', 'value' => 0.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $leavePendingZeroRow, $baseSalary);
    checkTrue('no LEAVE_PENDING_DEDUCT line when the reported value is 0', findLine($r['deduction'], 'LEAVE_PENDING_DEDUCT') === null);

    echo "=== Generic custom deduction item sent with a NEGATIVE value (Origami's own real payload shape for a deduction -- e.g. a guarantee-money installment sent as -500.00) -- must still deduct 500.00, not be silently dropped ===\n";
    $negDeductionRow = $blankRow;
    $negDeductionRow['item_values'] = [
        ['item_id' => 202, 'item_code' => 'CUSTOM_ITEM_5', 'item_name' => 'หักเงินค้ำประกันการทำงาน', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => -500.0, 'remark' => 'งวดที่ 1/10'],
    ];
    $r = $resolver->resolve($compId, $negDeductionRow, $baseSalary);
    $negDeductionLine = findLine($r['deduction'], 'CUSTOM:หักเงินค้ำประกันการทำงาน');
    checkTrue('a DEDUCTION-typed item sent with a negative raw value still produces a line', $negDeductionLine !== null);
    check('amount is the positive magnitude 500.00, not dropped and not stored as -500.00', $negDeductionLine['amount'] ?? null, 500.0);

    echo "=== A DEDUCTION-typed item with a POSITIVE raw value still works exactly as before (no regression from the abs() normalization) ===\n";
    $posDeductionRow = $blankRow;
    $posDeductionRow['item_values'] = [
        ['item_id' => 203, 'item_code' => 'CUSTOM_ITEM_6', 'item_name' => 'Positive Deduction', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 250.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $posDeductionRow, $baseSalary);
    $posDeductionLine = findLine($r['deduction'], 'CUSTOM:Positive Deduction');
    check('positive deduction value unaffected: 250.00', $posDeductionLine['amount'] ?? null, 250.0);

    echo "=== OT: premium pay uses the FIXED standard 30-day/8-hour divisor, never the period's actual working_days/working_mins (real bug report, exact hand-worked example: baseSalary 13,500, 1.5h OT at 1.5x -> 126.56) ===\n";
    $otRatesFixed = ['weekday' => ['multiplier_rate' => 1.50, 'calculation_base' => 'hourly', 'calculation_method' => 'multiplier', 'flat_amount_rate' => 0.0]];
    $otFixedDivisorRow = $blankRow;
    $otFixedDivisorRow['ot_req_working_day_hrs'] = 1.5;
    // Deliberately a NON-standard working_days/working_mins for this period (22 real working days,
    // not the standard 30) -- if OT wrongly reused the variable-divisor hourlyRate() (as it did
    // before this fix), this would silently change the OT result away from the user's own hand-
    // verified expectation below, exactly as they reported happening in production.
    $otFixedDivisorRow['working_days'] = 22;
    $otFixedDivisorRow['working_mins'] = 22 * 8 * 60;
    $r = $resolver->resolve($compId, $otFixedDivisorRow, 13500.0, [], [], null, null, true, [], $otRatesFixed);
    $otFixedLine = findLine($r['earning'], 'OT');
    checkTrue('OT line present', $otFixedLine !== null);
    check('13,500/30/8=56.25/hr, x1.5 OT rate=84.375/hr, x1.5h = 126.5625 -> 126.56, UNAFFECTED by working_days=22 in the row', $otFixedLine['amount'] ?? null, 126.56);

    // 2026-08-29, explicit request: "OT ก็ให้เห็นสูตรคำนวณเลยว่า คำนวณจากอะไร ฐานเงินเดือนเท่าไหร่ / กี่วัน
    // และคูณกับอะไร ผลลัพธ์ออกมาเท่าไหร่" -- the structured 'formula' trace attached to this exact line.
    echo "=== OT line carries a structured 'formula' trace for the Detail page's popover ===\n";
    checkTrue('OT line has a formula field', isset($otFixedLine['formula']));
    check('formula type is ot_multiplier (percent-rate OT, not flat_amount)', $otFixedLine['formula']['type'] ?? null, 'ot_multiplier');
    check('formula base_salary is the real 13500, not affected by working_days=22', $otFixedLine['formula']['base_salary'] ?? null, 13500.0);
    check('formula days_divisor is the fixed standard 30', $otFixedLine['formula']['days_divisor'] ?? null, 30.0);
    check('formula hours_divisor is the fixed standard 8', $otFixedLine['formula']['hours_divisor'] ?? null, 8.0);
    check('formula unit_rate = 13500/30/8 = 56.25/hr', round($otFixedLine['formula']['unit_rate'] ?? 0, 2), 56.25);
    check('formula multiplier is 1.5', $otFixedLine['formula']['multiplier'] ?? null, 1.5);
    check('formula hours is 1.5', $otFixedLine['formula']['hours'] ?? null, 1.5);
    check('formula result matches the line amount', $otFixedLine['formula']['result'] ?? null, 126.56);

    echo "=== OT: the SAME row's absence deduction (a DIFFERENT calculation) correctly STILL uses the actual working_days=22, proving the two are properly decoupled, not both accidentally fixed ===\n";
    $mixedOtAbsentRow = $otFixedDivisorRow;
    $mixedOtAbsentRow['absent_days'] = 1.0; // baseSalary(13500)/working_days(22)*1 = 613.64, NOT baseSalary/30*1=450.00
    $r = $resolver->resolve($compId, $mixedOtAbsentRow, 13500.0, [], [], null, null, true, [], $otRatesFixed);
    $mixedOtLine = findLine($r['earning'], 'OT');
    check('OT amount in the same row is still the fixed-divisor 126.56 (unaffected by the absence line existing too)', $mixedOtLine['amount'] ?? null, 126.56);
    $mixedAbsentLine = findLine($r['deduction'], 'ABSENT_DEDUCT');
    check("absence deduction in the SAME row correctly still uses the period's real working_days(22): 13500/22*1 = 613.64", $mixedAbsentLine['amount'] ?? null, 613.64);

    echo "=== An INCOME-typed item with a negative value is still correctly skipped (abs() normalization is scoped to DEDUCTION only) ===\n";
    $negIncomeRow = $blankRow;
    $negIncomeRow['item_values'] = [
        ['item_id' => 204, 'item_code' => 'CUSTOM_ITEM_7', 'item_name' => 'Negative Income', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => -100.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $negIncomeRow, $baseSalary);
    checkTrue('a negative-valued INCOME item is still skipped, not turned into a 100.00 earning line', findLine($r['earning'], 'CUSTOM:Negative Income') === null);
    check('zero earning lines', count($r['earning']), 0);

    echo "=== 2026-08-30: exemptEventCodes param (per-department/team/individual attendance-deduction exemption) ===\n";
    $pdo->prepare("DELETE FROM `attendance_deduction_rules` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
    $pdo->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, rate_unit, rate_per_unit, created_by) VALUES (?, 'late', 'flat_amount', 'minute', 2.00, ?)")
        ->execute([$compId, $userId]);
    $exLateRow = $blankRow;
    $exLateRow['late_mins'] = 30; // 30 * 2.00 = 60.00, same fixture shape as the earlier flat_amount late test above.

    $rNotExempt = $resolver->resolve($compId, $exLateRow, $baseSalary, [], []);
    $lineNotExempt = findLine($rNotExempt['deduction'], 'LATE_DEDUCT');
    check('not exempt: full 60.00 late deduction applied', $lineNotExempt['amount'] ?? null, 60.00);
    check('not exempt: is_exempted is false', $lineNotExempt['is_exempted'] ?? null, false);
    check('not exempt: exempted_amount is null', $lineNotExempt['exempted_amount'], null);

    $rExempt = $resolver->resolve($compId, $exLateRow, $baseSalary, [], ['late']);
    $lineExempt = findLine($rExempt['deduction'], 'LATE_DEDUCT');
    checkTrue('exempt: a line still appears (not silently dropped)', $lineExempt !== null);
    check('exempt: amount forced to 0', $lineExempt['amount'] ?? null, 0.0);
    check('exempt: is_exempted is true', $lineExempt['is_exempted'] ?? null, true);
    check('exempt: exempted_amount carries what it would have been (60.00)', $lineExempt['exempted_amount'] ?? null, 60.00);
    checkTrue('exempt: note is tagged _exempted for the breakdown modal to key off of', str_contains($lineExempt['note'] ?? '', '_exempted'));

    $rExemptOtherEvent = $resolver->resolve($compId, $exLateRow, $baseSalary, [], ['absent']);
    $lineExemptOtherEvent = findLine($rExemptOtherEvent['deduction'], 'LATE_DEDUCT');
    check('exempting a DIFFERENT event code (absent) leaves late deduction untouched', $lineExemptOtherEvent['amount'] ?? null, 60.00);

    echo "=== 2026-08-30 fix: Trip allowance real-world item_code 'ROUND' -- structured column AND a matching item_values row sent together must still be only 1 line (real bug report: EVENT_ALIASES only knew 'TRIP'/'TRIPALLOWANCE', never the real 'ROUND' Origami actually sends) ===\n";
    $pdo->prepare("DELETE FROM `attendance_deduction_rules` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
    $roundRow = $blankRow;
    $roundRow['trip_allowance'] = 350.5; // structured column
    $roundRow['item_values'] = [
        ['item_id' => 301, 'item_code' => 'ROUND', 'item_name' => 'Trip Allowance', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 350.5, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $roundRow, $baseSalary);
    $roundTripLines = array_values(array_filter($r['earning'], fn($l) => $l['code'] === 'TRIP_ALLOW'));
    check('exactly 1 TRIP_ALLOW line (item_code "ROUND" recognized via alias, not double-counted)', count($roundTripLines), 1);
    check('amount is 350.50, not 701.00 (the "ROUND" item_values row must not also become a separate custom earning line)', $roundTripLines[0]['amount'] ?? null, 350.5);
    checkTrue('no stray custom "ROUND"/"Trip Allowance" line was also created', findLine($r['earning'], 'CUSTOM:Trip Allowance') === null);

    echo "=== 2026-08-30 fix: Early leave deduction -- 'early_leave' was completely missing from RULE_DRIVEN_ITEM_DEFS (selectable in the 'Linked Attendance Event' dropdown for years, but never actually computed) ===\n";
    $earlyLeaveRow = $blankRow;
    $earlyLeaveRow['early_mins'] = 30; // hourlyRate(100)/60 * 30 = 50, same default formula shape as late
    $r = $resolver->resolve($compId, $earlyLeaveRow, $baseSalary);
    $earlyLeaveLine = findLine($r['deduction'], 'EARLY_LEAVE_DEDUCT');
    checkTrue('EARLY_LEAVE_DEDUCT line present (previously this event produced nothing at all)', $earlyLeaveLine !== null);
    check('early leave deduction = (100/60)*30 = 50', $earlyLeaveLine['amount'] ?? null, 50.0);

    echo "=== 2026-08-30 fix: Early leave -- item_code alias 'EARLY_LEAVE' also recognized, structured + item_values together still only 1 line ===\n";
    $earlyLeaveAliasRow = $blankRow;
    $earlyLeaveAliasRow['early_mins'] = 30;
    $earlyLeaveAliasRow['item_values'] = [
        ['item_id' => 401, 'item_code' => 'EARLY_LEAVE', 'item_name' => 'Early Leave', 'item_type' => 'DEDUCTION', 'unit_type' => 'minutes', 'value' => 30.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $earlyLeaveAliasRow, $baseSalary);
    $earlyLeaveAliasLines = array_values(array_filter($r['deduction'], fn($l) => $l['code'] === 'EARLY_LEAVE_DEDUCT'));
    check('exactly 1 EARLY_LEAVE_DEDUCT line (item_code "EARLY_LEAVE" recognized via alias, not double-deducted)', count($earlyLeaveAliasLines), 1);
    check('amount is 50.00, not 100.00', $earlyLeaveAliasLines[0]['amount'] ?? null, 50.0);

    echo "=== 2026-08-30 fix: Leave pending -- now read from the STRUCTURED leave_wait_days column (docblock previously, wrongly, claimed no such column existed; item_values was the only path before this fix) ===\n";
    $leaveWaitStructuredRow = $blankRow;
    $leaveWaitStructuredRow['leave_wait_days'] = 1.0; // 1 day = 480 minutes: (100/60)*480*1.0 = 800.00, no item_values at all this time
    $r = $resolver->resolve($compId, $leaveWaitStructuredRow, $baseSalary);
    $leaveWaitLine = findLine($r['deduction'], 'LEAVE_PENDING_DEDUCT');
    checkTrue('LEAVE_PENDING_DEDUCT line present from the structured column alone (previously silently produced nothing)', $leaveWaitLine !== null);
    check('1 day via leave_wait_days -> minutes(480)*(100/60)*1.0 = 800.00', $leaveWaitLine['amount'] ?? null, 800.0);

    echo "=== 2026-08-30 fix: Leave pending -- structured leave_wait_days AND a matching item_values row together still only 1 line ===\n";
    $leaveWaitBothRow = $blankRow;
    $leaveWaitBothRow['leave_wait_days'] = 1.0;
    $leaveWaitBothRow['item_values'] = [
        ['item_id' => 201, 'item_code' => 'LEAVE_PENDING', 'item_name' => 'Leave Pending', 'item_type' => 'INFO', 'unit_type' => 'days', 'value' => 1.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $leaveWaitBothRow, $baseSalary);
    $leaveWaitBothLines = array_values(array_filter($r['deduction'], fn($l) => $l['code'] === 'LEAVE_PENDING_DEDUCT'));
    check('exactly 1 LEAVE_PENDING_DEDUCT line (structured column + item_values duplicate, not double-deducted)', count($leaveWaitBothLines), 1);
    check('amount is 800.00, not 1600.00', $leaveWaitBothLines[0]['amount'] ?? null, 800.0);

    // 2026-08-30 (Phase 2, T011, explicit request: "เพิ่มเบี้ยขยันเป็นเหตุการณ์ที่ดึงจาก Origami") --
    // diligence has no structured payroll_sync_items column (only ever arrives via item_values, same
    // as before this fix), but is now a proper KNOWN_ITEM_DEFS/EVENT_ALIASES entry -- matched via
    // pedTypeBySourceEvent() instead of the generic item_code fallback loop. `note` prefix matching
    // (not exact `code`), same robust-to-whatever-catalog-code-exists pattern as the trip_allowance
    // deactivation test above, since comp_id=1's real catalog row (backfilled by
    // 2026-08-30_11b_diligence_source_event_backfill.sql) resolves to ITS OWN item_code, not the
    // hardcoded DILIGENCE_ALLOW fallback.
    echo "=== 2026-08-30 (T011): Diligence Allowance -- item_values only (no structured column), resolved via source_event_code like OT/Trip Allowance, not the generic item_code fallback ===\n";
    $diligenceRow = $blankRow;
    $diligenceRow['item_values'] = [
        ['item_id' => 501, 'item_code' => 'DILIGENCE', 'item_name' => 'Diligence Allowance', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 888.0, 'remark' => null],
    ];
    $r = $resolver->resolve($compId, $diligenceRow, $baseSalary);
    $diligenceLines = array_values(array_filter($r['earning'], fn($l) => stripos((string)($l['note'] ?? ''), 'sync_diligence') !== false));
    checkTrue('exactly 1 diligence earning line produced', count($diligenceLines) === 1);
    check('diligence amount = face value 888.00 (direct passthrough, no rate math)', $diligenceLines[0]['amount'] ?? null, 888.0);
    check('diligence line is NOT a generic CUSTOM: fallback (is_custom=false, backed by the real catalog row)', $diligenceLines[0]['is_custom'] ?? null, false);

    echo "=== 2026-08-30 (T011): Diligence Allowance -- deactivating its catalog type excludes it entirely, no fallback leak (same fix class as trip_allowance above) ===\n";
    // Same "reuse the real row if this dev DB has one, else insert a fixture" robustness as the
    // trip_allowance deactivation test above -- must not silently no-op the UPDATE below if comp_id=1
    // genuinely has no diligence-linked catalog row yet.
    $stmtDiligenceType = $pdo->prepare("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = :c AND source_event_code = 'diligence' AND deleted_at IS NULL LIMIT 1");
    $stmtDiligenceType->execute([':c' => $compId]);
    $diligenceType = $stmtDiligenceType->fetch(PDO::FETCH_ASSOC);
    if ($diligenceType === false) {
        $insDiligenceType = $pdo->prepare("INSERT INTO payroll_earning_deduction_types
            (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, tax_treatment, source_event_code, is_sync_only, status, created_by)
            VALUES (:comp_id, 'DILIGENCE_TEST_FIXTURE', 'เบี้ยขยันทดสอบ', 'Test Diligence Allowance', 'earning', 'manual_entry', 'taxable', 'diligence', 1, 'active', :user_id)");
        $insDiligenceType->execute([':comp_id' => $compId, ':user_id' => $userId]);
        $diligenceTypeId = (int)$pdo->lastInsertId();
    } else {
        $diligenceTypeId = (int)$diligenceType['id'];
    }
    $pdo->prepare("UPDATE payroll_earning_deduction_types SET status = 'inactive' WHERE id = :id")->execute([':id' => $diligenceTypeId]);
    $rDiligenceOff = $resolver->resolve($compId, $diligenceRow, $baseSalary);
    $leakedDiligenceLines = array_filter($rDiligenceOff['earning'], fn($l) => stripos((string)($l['note'] ?? ''), 'sync_diligence') !== false);
    check('diligence produces ZERO earning lines once its catalog type is deactivated', count($leakedDiligenceLines), 0);
    $pdo->prepare("UPDATE payroll_earning_deduction_types SET status = 'active' WHERE id = :id")->execute([':id' => $diligenceTypeId]);

    // 2026-08-30 (Phase 2, T013, explicit decision confirmed with user) -- OPT-IN only: a company
    // that has NOT set source_event_code='student_loan'/'loan_repay' on its own catalog row must
    // see ZERO behavior change from this feature -- it's confirmed unverified against real Origami
    // data (see EVENT_ALIASES's own comment), so the existing manual employee_earning_deductions
    // installment mechanism must keep working untouched unless an admin explicitly opts in.
    echo "=== 2026-08-30 (T013): Student Loan / Loan Repay are OPT-IN -- with NO catalog row linking source_event_code, item_values matching this item_code is untouched (falls through to the SAME generic item_code-matching path this app already used before T013 existed -- comp_id=1's own real seeded STUDENT_LOAN row, no source_event_code, exercised here directly rather than a synthetic stand-in) ===\n";
    $studentLoanNoOptInRow = $blankRow;
    $studentLoanNoOptInRow['item_values'] = [
        ['item_id' => 601, 'item_code' => 'STUDENT_LOAN', 'item_name' => 'Student Loan', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 500.0, 'remark' => null],
    ];
    $pdo->prepare("UPDATE payroll_earning_deduction_types SET source_event_code = NULL WHERE comp_id = :c AND UPPER(item_code) IN ('STUDENT_LOAN','LOAN_REPAY')")->execute([':c' => $compId]);
    $rNoOptIn = $resolver->resolve($compId, $studentLoanNoOptInRow, $baseSalary);
    $noOptInLine = findLine($rNoOptIn['deduction'], 'STUDENT_LOAN');
    checkTrue('with no opt-in, STILL resolves via the OLD generic item_code match against the real catalog row (not silently dropped, not a CUSTOM: fallback)', $noOptInLine !== null);
    check('with no opt-in, is_custom=false (backed by the real, pre-existing catalog row)', $noOptInLine['is_custom'] ?? null, false);
    check('with no opt-in, the amount is still the correct face value 500.00', $noOptInLine['amount'] ?? null, 500.0);

    echo "=== 2026-08-30 (T013): Student Loan / Loan Repay -- once an admin opts in (source_event_code set), resolved via the SAME event-linked path as diligence/trip allowance ===\n";
    $insStudentLoanType = $pdo->prepare("INSERT INTO payroll_earning_deduction_types
        (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, tax_deduction_impact, statutory_report_code, source_event_code, is_sync_only, status, created_by)
        VALUES (:comp_id, 'STUDENT_LOAN_TEST', 'กยศ ทดสอบ', 'Test Student Loan', 'deduction', 'manual_entry', 'before_tax', 'TH_SLF', 'student_loan', 1, 'active', :user_id)");
    $insStudentLoanType->execute([':comp_id' => $compId, ':user_id' => $userId]);
    $studentLoanRow = $blankRow;
    $studentLoanRow['item_values'] = [
        ['item_id' => 602, 'item_code' => 'STUDENT_LOAN', 'item_name' => 'Student Loan', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 500.0, 'remark' => null],
    ];
    $rOptedIn = $resolver->resolve($compId, $studentLoanRow, $baseSalary);
    $optedInLine = findLine($rOptedIn['deduction'], 'STUDENT_LOAN_TEST');
    checkTrue('once opted in, resolves to the REAL catalog code (STUDENT_LOAN_TEST), not a generic CUSTOM: line', $optedInLine !== null);
    check('opted-in amount = face value 500.00', $optedInLine['amount'] ?? null, 500.0);
    check('opted-in line is NOT a generic CUSTOM: fallback (is_custom=false, backed by the real catalog row)', $optedInLine['is_custom'] ?? null, false);

    $insLoanRepayType = $pdo->prepare("INSERT INTO payroll_earning_deduction_types
        (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, tax_deduction_impact, source_event_code, is_sync_only, status, created_by)
        VALUES (:comp_id, 'LOAN_REPAY_TEST', 'เงินกู้ทดสอบ', 'Test Loan Repay', 'deduction', 'manual_entry', 'after_tax', 'loan_repay', 1, 'active', :user_id)");
    $insLoanRepayType->execute([':comp_id' => $compId, ':user_id' => $userId]);
    $loanRepayRow = $blankRow;
    $loanRepayRow['item_values'] = [
        ['item_id' => 603, 'item_code' => 'LOAN_REPAY', 'item_name' => 'Loan Repayment', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 1200.0, 'remark' => null],
    ];
    $rLoanRepayOptedIn = $resolver->resolve($compId, $loanRepayRow, $baseSalary);
    $loanRepayLine = findLine($rLoanRepayOptedIn['deduction'], 'LOAN_REPAY_TEST');
    checkTrue('LOAN_REPAY opted in resolves to the real catalog code too', $loanRepayLine !== null);
    check('LOAN_REPAY opted-in amount = face value 1200.00', $loanRepayLine['amount'] ?? null, 1200.0);

    // Cleanup -- these 2 test-only catalog rows would otherwise leak into OTHER sections of this
    // same shared-transaction file if any ran after this point (none currently do, but matches this
    // file's own defensive convention elsewhere).
    $pdo->prepare("DELETE FROM payroll_earning_deduction_types WHERE comp_id = :c AND item_code IN ('STUDENT_LOAN_TEST','LOAN_REPAY_TEST')")->execute([':c' => $compId]);

    echo "=== 2026-09-02, Origami's own bug-fix notice: working_days/working_mins now arrive as 0 when their Report Item isn't selected -- must surface a visible warning, not silently fall back to the 30-day divisor, ONLY when it actually changes the computed money ===\n";
    $wdRow = $blankRow;
    $wdRow['late_mins'] = 30; // percent_of_rate @ default 1.0x -- DOES depend on hourlyRate.
    // working_days/working_mins deliberately absent (same as $blankRow) -- simulates the exact
    // scenario Origami flagged: Late selected as a Report Item, Working Days/Minutes not selected.
    $rWd = $resolver->resolve($compId, $wdRow, $baseSalary);
    checkTrue('percent_of_rate (default) + late_mins>0 + working_days/mins both 0 => the new warning fires', in_array('working_days_fallback_with_attendance_deduction:late', $rWd['errors'], true));
    check('the deduction amount itself is UNCHANGED by this warning (still the same fallback-divisor math as before)', findLine($rWd['deduction'], 'LATE_DEDUCT')['amount'] ?? null, 50.0);

    echo "--- working_days actually present (real value from Origami) -- no warning, exact same scenario otherwise ---\n";
    $wdPresentRow = $wdRow;
    $wdPresentRow['working_days'] = 22;
    $rWdPresent = $resolver->resolve($compId, $wdPresentRow, $baseSalary);
    checkTrue('warning does NOT fire once working_days is present (real value, no fallback needed)', !in_array('working_days_fallback_with_attendance_deduction:late', $rWdPresent['errors'], true));

    echo "--- working_mins alone (no working_days) is ALSO enough to suppress the warning -- either one avoiding the fallback counts ---\n";
    $wmPresentRow = $wdRow;
    $wmPresentRow['working_mins'] = 10560; // 22 days * 480 mins
    $rWmPresent = $resolver->resolve($compId, $wmPresentRow, $baseSalary);
    checkTrue('warning does NOT fire when working_mins alone is present', !in_array('working_days_fallback_with_attendance_deduction:late', $rWmPresent['errors'], true));

    echo "--- flat_amount method does NOT depend on hourlyRate/dailyRate at all -- no false-positive warning even with the fallback active ---\n";
    $pdo->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, rate_per_unit, created_by) VALUES (?, 'late', 'flat_amount', 2.00, ?)")
        ->execute([$compId, $userId]);
    $rWdFlat = $resolver->resolve($compId, $wdRow, $baseSalary);
    checkTrue('flat_amount: NO warning even though working_days/mins are both 0 (the amount never used the divisor)', !in_array('working_days_fallback_with_attendance_deduction:late', $rWdFlat['errors'], true));
    check('flat_amount deduction still computes correctly (2.00 * 30 = 60.00)', findLine($rWdFlat['deduction'], 'LATE_DEDUCT')['amount'] ?? null, 60.0);
    $pdo->prepare("DELETE FROM attendance_deduction_rules WHERE comp_id = :c AND event_code = 'late'")->execute([':c' => $compId]);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
