<?php
/**
 * Verifies ThPitCalculator (2026-08-21, real bug fix: a 25,000/month employee was withheld
 * ~7,500 THB in a single period instead of the correct 0 -- see the class docblock for the full
 * root-cause writeup). Not PHPUnit -- runs against the real dev DB inside a transaction that is
 * always rolled back. Run with: php tests/th_pit_calculator_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/services/StatutoryCalculationEngine.php';
require_once __DIR__ . '/../app/services/ThPitCalculator.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

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

try {
    $compId = 1; // TH company, same fixture convention as tests/statutory_engine_test.php
    $userId = 1;
    $calc = new ThPitCalculator($pdo);
    $model = new EmployeeModel();

    // Fresh synthetic employee (not an existing real row) so dependent-count tests don't touch
    // real employee_dependents data -- same "Dev DB has real user data" caution as other tests.
    $empNo = 'PIT-TEST-' . uniqid();
    $res = $model->save($compId, [
        'employee_no' => $empNo, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'ภาษี',
        'name_en' => 'Test', 'surname_en' => 'Tax', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'id_card_no' => '1234567890121', // valid mod-11 checksum
    ], $userId);
    checkTrue('fixture: synthetic employee created' . (empty($res['status']) ? " ({$res['message']})" : ''), $res['status']);
    $employeeId = (int)$res['id'];

    echo "=== The user's exact reported case: 25,000/month, no spouse/children/SSO/PVD, average ===\n";
    $r = $calc->calculate($compId, $employeeId, 25000.0, 0.0, 0.0, 12, 'average', false, '2026-08-01', '2026-08-01');
    check('annual_taxable_income = 300,000 - 100,000 expense - 60,000 personal = 140,000', $r['annual_taxable_income'], 140000.0);
    check('annual_tax is 0 (140,000 is entirely within the 0%-exempt <=150,000 bracket)', $r['annual_tax'], 0.0);
    check('employee_amount (this period\'s withholding) is 0.00, not ~7,500', $r['employee_amount'], 0.0);

    echo "=== 50,000/month, no spouse, average (crosses into the 5%/10% brackets) ===\n";
    $r = $calc->calculate($compId, $employeeId, 50000.0, 0.0, 0.0, 12, 'average', false, '2026-08-01', '2026-08-01');
    // 600,000 gross - 100,000 (capped expense) - 60,000 personal = 440,000 net.
    // 0-150k@0=0; 150k-300k@5%=7,500; 300k-440k@10%=14,000. Annual tax = 21,500.
    check('annual_taxable_income', $r['annual_taxable_income'], 440000.0);
    check('annual_tax (published-table-equivalent hand calc)', $r['annual_tax'], 21500.0);
    check('employee_amount = 21,500 / 12', $r['employee_amount'], round(21500 / 12, 2));

    echo "=== Same 50,000/month, but with spouse allowance ===\n";
    $r = $calc->calculate($compId, $employeeId, 50000.0, 0.0, 0.0, 12, 'average', true, '2026-08-01', '2026-08-01');
    // Net = 600,000 - 100,000 - 60,000 (personal) - 60,000 (spouse) = 380,000.
    // 0-150k@0=0; 150k-300k@5%=7,500; 300k-380k@10%=8,000. Annual tax = 15,500.
    check('spouse allowance reduces net taxable income to 380,000', $r['annual_taxable_income'], 380000.0);
    check('spouse allowance reduces annual tax to 15,500', $r['annual_tax'], 15500.0);

    echo "=== SSO/PVD employee contributions reduce taxable income ===\n";
    $r = $calc->calculate($compId, $employeeId, 50000.0, 750.0, 1500.0, 12, 'average', false, '2026-08-01', '2026-08-01');
    // Net = 600,000 - 100,000 - 60,000 - (750*12) - (1500*12) = 600,000-100,000-60,000-9,000-18,000 = 413,000.
    check('SSO+PVD annualized deduction reduces net taxable income to 413,000', $r['annual_taxable_income'], 413000.0);

    echo "=== tax_exempt is handled by the caller (PayrollRunModel), not this class -- documented, not tested here ===\n";

    echo "=== Child allowance: 2 active dependents (children), 50,000/month, no spouse ===\n";
    // Deliberately the LAST test using $employeeId before the 'actual' cumulative tests below --
    // employee_dependents rows persist for the rest of this transaction, so every earlier test
    // above intentionally ran before any dependents existed on this employee. The 'actual' tests
    // below reuse $employeeId too, but only ever compare two calls against each other (both sides
    // carry the same +60,000 child allowance equally), so that comparison stays valid regardless.
    $insDep = $pdo->prepare("INSERT INTO `employee_dependents` (employee_id, name, relationship, status, created_by) VALUES (?, ?, 'child_legitimate', 'active', ?)");
    $insDep->execute([$employeeId, 'บุตรทดสอบ 1', $userId]);
    $insDep->execute([$employeeId, 'บุตรทดสอบ 2', $userId]);
    // An inactive/deleted dependent must NOT count.
    $pdo->prepare("INSERT INTO `employee_dependents` (employee_id, name, relationship, status, created_by) VALUES (?, ?, 'child_legitimate', 'deleted', ?)")
        ->execute([$employeeId, 'บุตรทดสอบ 3 (deleted)', $userId]);
    $r = $calc->calculate($compId, $employeeId, 50000.0, 0.0, 0.0, 12, 'average', false, '2026-08-01', '2026-08-01');
    // Net = 600,000 - 100,000 - 60,000 (personal) - 2*30,000 (children) = 380,000 -- same as the
    // spouse case above, confirms the deleted 3rd dependent was correctly excluded.
    check('2 active children allowance (deleted 3rd excluded) reduces net taxable income to 380,000', $r['annual_taxable_income'], 380000.0);

    echo "=== 'actual' cumulative method: constant salary should converge to the same total as 'average' ===\n";
    // Period 1 (no prior history): should match the plain 'average' result for the same salary.
    $p1 = $calc->calculate($compId, $employeeId, 30000.0, 0.0, 0.0, 12, 'actual', false, '2026-01-01', '2026-01-01');
    check('period 1 (no history) periods_elapsed is 1', $p1['periods_elapsed'], 1);
    $avgEquivalent = $calc->calculate($compId, $employeeId, 30000.0, 0.0, 0.0, 12, 'average', false, '2026-01-01', '2026-01-01');
    check('period 1 employee_amount matches what average() gives for the same constant salary', $p1['employee_amount'], $avgEquivalent['employee_amount']);

    // Simulate period 1 having actually been processed and approved, carrying $p1's PIT amount.
    $insRun = $pdo->prepare("INSERT INTO `payroll_runs` (comp_id, run_name, period_start_date, period_end_date, payment_date, state, status, created_by)
        VALUES (?, 'PIT test period 1', '2026-01-01', '2026-01-31', '2026-02-05', 'approved', 'active', ?)");
    $insRun->execute([$compId, $userId]);
    $run1Id = (int)$pdo->lastInsertId();
    $breakdown1 = json_encode([['code' => 'TH_PIT', 'employee_amount' => $p1['employee_amount']]]);
    $pdo->prepare("INSERT INTO `payroll_run_details` (run_id, employee_id, gross_amount, statutory_breakdown, calc_status, data_source)
        VALUES (?, ?, 30000.00, ?, 'calculated', 'manual')")->execute([$run1Id, $employeeId, $breakdown1]);

    // Period 2, same constant salary: cumulative true-up should land within a rounding cent of
    // 2x a single average period (since income hasn't actually changed).
    $p2 = $calc->calculate($compId, $employeeId, 30000.0, 0.0, 0.0, 12, 'actual', false, '2026-02-01', '2026-02-01');
    check('period 2 periods_elapsed is 2', $p2['periods_elapsed'], 2);
    check('period1 + period2 (constant salary) ~= 2x a single average-method period', $p1['employee_amount'] + $p2['employee_amount'], $avgEquivalent['employee_amount'] * 2, 0.02);

    // Simulate period 2 also having been processed, then give period 3 a big raise -- the
    // cumulative method should catch up the underwithheld difference in period 3 itself, i.e.
    // period 3 withholds MORE than a flat (new annual tax)/12 would alone.
    $breakdown2 = json_encode([['code' => 'TH_PIT', 'employee_amount' => $p2['employee_amount']]]);
    $insRun2 = $pdo->prepare("INSERT INTO `payroll_runs` (comp_id, run_name, period_start_date, period_end_date, payment_date, state, status, created_by)
        VALUES (?, 'PIT test period 2', '2026-02-01', '2026-02-28', '2026-03-05', 'approved', 'active', ?)");
    $insRun2->execute([$compId, $userId]);
    $run2Id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `payroll_run_details` (run_id, employee_id, gross_amount, statutory_breakdown, calc_status, data_source)
        VALUES (?, ?, 30000.00, ?, 'calculated', 'manual')")->execute([$run2Id, $employeeId, $breakdown2]);

    $p3Raise = $calc->calculate($compId, $employeeId, 100000.0, 0.0, 0.0, 12, 'actual', false, '2026-03-01', '2026-03-01');
    $p3FlatEquivalent = $calc->calculate($compId, $employeeId, 100000.0, 0.0, 0.0, 12, 'average', false, '2026-03-01', '2026-03-01');
    checkTrue('a mid-year raise: cumulative true-up withholds MORE in period 3 than a flat average-method period at the new salary would alone (catching up prior under-withholding)',
        $p3Raise['employee_amount'] > $p3FlatEquivalent['employee_amount']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
