<?php
/**
 * 2026-08-30, real bug fix verification: payroll_earning_deduction_types.tax_treatment
 * ('taxable'/'non_taxable', required on every Earning item) and .tax_deduction_impact
 * ('before_tax'/'after_tax', required on every Deduction item) were saved/validated as
 * required data entry but never actually read anywhere in real payroll calculation --
 * PayrollRunModel::recalculate() summed every earning line unconditionally into the figure
 * that fed TH_PIT withholding (ThPitCalculator), with zero regard for either flag. This
 * script proves the fix end-to-end through the real recalculate() path (via addManualLine(),
 * which internally calls recalculate()), not just at the isolated ThPitCalculator unit level
 * already covered by tests/th_pit_calculator_test.php.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB
 * inside a transaction that is always rolled back, so it never leaves any data behind.
 * Run with: php tests/tax_treatment_pit_fix_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

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
function checkTrue(string $label, bool $actual): void {
    check($label, $actual, true);
}

try {
    $compId = 1;
    $adminUserId = 1;
    $today = new DateTime();

    // Same isolation reasoning as tests/payroll_run_test.php's own top-of-file comment (real dev-DB
    // leftover data, not a test fixture -- see feedback_dev_db_shared_state_test_fragility memory):
    // a real active Approval Workflow for PAYROLL_RUN_APPROVAL would otherwise route submit()
    // through it, and incentive runs created here never get submitted anyway so it doesn't matter
    // for THIS script -- but leftover placeholder employees at comp_id=1 WOULD inflate any run this
    // script joins them into if not cleared, same as that file's own reasoning.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, has_spouse)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', NULL, 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 0, '2020-01-01', 'average', 'active',
         0, 0, 0, 0)");
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_TAXFIX_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'แก้ภาษี', ':name_en' => 'Test', ':surname_en' => 'TaxFix',
        ':email' => uniqid() . '@test.local',
    ]);
    $employeeId = (int)$pdo->lastInsertId();

    // Real seeded catalog items for comp_id=1 (see PayrollEarningDeductionTypeModel::defaultItems()):
    // BONUS (earning, taxable), MEAL_ALLOW (earning, non_taxable), LATE_DEDUCT (deduction, before_tax),
    // LOAN_REPAY (deduction, after_tax) -- exactly the 4 combinations this fix changes behavior for.
    $bonusId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'BONUS' AND deleted_at IS NULL")->fetchColumn();
    $mealAllowId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'MEAL_ALLOW' AND deleted_at IS NULL")->fetchColumn();
    $lateDeductId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'LATE_DEDUCT' AND deleted_at IS NULL")->fetchColumn();
    $loanRepayId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'LOAN_REPAY' AND deleted_at IS NULL")->fetchColumn();
    checkTrue('fixture: BONUS (earning, taxable) exists', $bonusId > 0);
    checkTrue('fixture: MEAL_ALLOW (earning, non_taxable) exists', $mealAllowId > 0);
    checkTrue('fixture: LATE_DEDUCT (deduction, before_tax) exists', $lateDeductId > 0);
    checkTrue('fixture: LOAN_REPAY (deduction, after_tax) exists', $loanRepayId > 0);

    $runModel = new PayrollRunModel($pdo);

    echo "=== Scenario 1: a non-taxable earning line must NOT increase TH_PIT withholding ===\n";
    $r1 = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 1, 'run_name' => 'TAXFIX_1_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +11 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +11 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +11 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: incentive run 1 created' . (empty($r1['status']) ? " ({$r1['message']})" : ''), $r1['status']);
    $run1Id = $r1['id'];
    $runModel->joinEmployees($run1Id, $compId, [$employeeId], $adminUserId, true);

    // 40,000/period taxable bonus (annualized 480,000 -- solidly into a real taxable bracket, no
    // SSO/PVD in play since this fixture employee is opted out of both, so the PIT figure below is
    // driven purely by the earning lines/allowances, easy to reason about).
    $add1 = $runModel->addManualLine($run1Id, $compId, $employeeId, $bonusId, 40000, $adminUserId, true);
    checkTrue('adding the taxable BONUS line succeeds' . (empty($add1['status']) ? " ({$add1['message']})" : ''), $add1['status']);
    $detail1a = $runModel->getDetails($run1Id, $compId)[0] ?? [];
    $pitBefore = null;
    foreach (($detail1a['statutory_breakdown'] ?? []) as $item) {
        if ($item['code'] === 'TH_PIT') { $pitBefore = (float)$item['employee_amount']; }
    }
    checkTrue('TH_PIT is computed and non-zero on a 480,000/year taxable base', $pitBefore !== null && $pitBefore > 0);
    check('gross_amount after taxable-only line is 40000', (float)($detail1a['gross_amount'] ?? -1), 40000.0);
    checkTrue('taxable_gross_amount matches gross_amount when every earning line is taxable', abs((float)($detail1a['taxable_gross_amount'] ?? -1) - 40000.0) < 0.005);

    $add2 = $runModel->addManualLine($run1Id, $compId, $employeeId, $mealAllowId, 40000, $adminUserId, true);
    checkTrue('adding the non-taxable MEAL_ALLOW line succeeds' . (empty($add2['status']) ? " ({$add2['message']})" : ''), $add2['status']);
    $detail1b = $runModel->getDetails($run1Id, $compId)[0] ?? [];
    $pitAfter = null;
    foreach (($detail1b['statutory_breakdown'] ?? []) as $item) {
        if ($item['code'] === 'TH_PIT') { $pitAfter = (float)$item['employee_amount']; }
    }
    check('gross_amount now includes BOTH lines (80000) -- non-taxable still counts for net pay', (float)($detail1b['gross_amount'] ?? -1), 80000.0);
    checkTrue('taxable_gross_amount stays at 40000 -- the non-taxable line is excluded from it', abs((float)($detail1b['taxable_gross_amount'] ?? -1) - 40000.0) < 0.005);
    checkTrue('TH_PIT withholding is UNCHANGED by the non-taxable earning (same taxable base as before)', abs($pitAfter - $pitBefore) < 0.01);
    checkTrue('net_amount increased by essentially the full 40000 non-taxable amount (untaxed pass-through)', abs(((float)$detail1b['net_amount'] - (float)$detail1a['net_amount']) - 40000.0) < 0.01);

    echo "=== Scenario 2: a before_tax deduction reduces TH_PIT, an after_tax deduction does not ===\n";
    $r2 = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 1, 'run_name' => 'TAXFIX_2_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +12 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +12 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +12 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: incentive run 2 created' . (empty($r2['status']) ? " ({$r2['message']})" : ''), $r2['status']);
    $run2Id = $r2['id'];
    $runModel->joinEmployees($run2Id, $compId, [$employeeId], $adminUserId, true);

    // 50,000/period taxable bonus (annualized 600,000) as the baseline taxable income for this
    // second employee/run, entirely separate from Scenario 1's own run/lines.
    $runModel->addManualLine($run2Id, $compId, $employeeId, $bonusId, 50000, $adminUserId, true);
    $detail2Base = $runModel->getDetails($run2Id, $compId)[0] ?? [];
    $pitBase = null;
    foreach (($detail2Base['statutory_breakdown'] ?? []) as $item) {
        if ($item['code'] === 'TH_PIT') { $pitBase = (float)$item['employee_amount']; }
    }
    checkTrue('baseline TH_PIT (before any deduction line) is computed and non-zero', $pitBase !== null && $pitBase > 0);

    $addBeforeTax = $runModel->addManualLine($run2Id, $compId, $employeeId, $lateDeductId, 10000, $adminUserId, true);
    checkTrue('adding the before_tax LATE_DEDUCT line succeeds' . (empty($addBeforeTax['status']) ? " ({$addBeforeTax['message']})" : ''), $addBeforeTax['status']);
    $detail2BeforeTax = $runModel->getDetails($run2Id, $compId)[0] ?? [];
    $pitWithBeforeTax = null;
    foreach (($detail2BeforeTax['statutory_breakdown'] ?? []) as $item) {
        if ($item['code'] === 'TH_PIT') { $pitWithBeforeTax = (float)$item['employee_amount']; }
    }
    checkTrue('TH_PIT DROPS once a before_tax deduction is added (folded into allowances like SSO/PVD)', $pitWithBeforeTax < $pitBase);

    // Remove it and confirm PIT returns to exactly the baseline before testing the after_tax case,
    // so the two deduction types are compared against the same clean starting point.
    $line2 = null;
    foreach ($runModel->manualLinesForEmployee($compId, $run2Id, $employeeId) as $l) {
        if ($l['item_code'] === 'LATE_DEDUCT') { $line2 = $l; }
    }
    checkTrue('found the before_tax manual line to remove', $line2 !== null);
    $runModel->removeManualLine($run2Id, $compId, (int)$line2['id'], $adminUserId, true);
    $detail2AfterRemove = $runModel->getDetails($run2Id, $compId)[0] ?? [];
    $pitAfterRemove = null;
    foreach (($detail2AfterRemove['statutory_breakdown'] ?? []) as $item) {
        if ($item['code'] === 'TH_PIT') { $pitAfterRemove = (float)$item['employee_amount']; }
    }
    checkTrue('TH_PIT returns to the baseline after removing the before_tax line', abs($pitAfterRemove - $pitBase) < 0.01);

    $addAfterTax = $runModel->addManualLine($run2Id, $compId, $employeeId, $loanRepayId, 10000, $adminUserId, true);
    checkTrue('adding the after_tax LOAN_REPAY line succeeds' . (empty($addAfterTax['status']) ? " ({$addAfterTax['message']})" : ''), $addAfterTax['status']);
    $detail2AfterTax = $runModel->getDetails($run2Id, $compId)[0] ?? [];
    $pitWithAfterTax = null;
    foreach (($detail2AfterTax['statutory_breakdown'] ?? []) as $item) {
        if ($item['code'] === 'TH_PIT') { $pitWithAfterTax = (float)$item['employee_amount']; }
    }
    checkTrue('TH_PIT is UNCHANGED by an after_tax deduction of the same 10000 amount', abs($pitWithAfterTax - $pitBase) < 0.01);
    checkTrue('net_amount is still reduced by the after_tax deduction itself (it still deducts real money, just doesn\'t affect withholding)',
        (float)$detail2AfterTax['net_amount'] < (float)$detail2AfterRemove['net_amount']);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
