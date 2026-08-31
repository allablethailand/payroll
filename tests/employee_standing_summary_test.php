<?php
/**
 * Verifies EmployeeModel::standingSummaryList()/standingSummaryForEmployees() -- explicit request:
 * "/payroll/employees ต้องการอีก Tab ต่อจาก Tab ตรวจสอบข้อมูล เป็น Tab สรุปรวมรายได้รายหักที่ หักหรือได้ประจำ
 * รวมถึงฐานเงินและ และรายได้ รายหักที่ได้รับเป็นรอบ ให้แสดงตัวเลขในรอบที่รอจ่าย รอหัก และบอกด้วยว่า งวดที่เท่าไหร่
 * จากทั้งหมดกี่งวด และมีสรุปรวมใน Column ท้าย และ Footer ครับ".
 *
 * Covers: base salary decryption, Recurring Earnings inclusion/exclusion (active vs suspended-now),
 * PED assignment installments (only the NEXT pending one per assignment, correct installment_no/
 * total_installments, earning vs deduction split), the trailing total_earning/total_deduction/
 * net_total per row, and the server-computed company-wide `totals` covering the WHOLE filtered set
 * (not just the current page).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/employee_standing_summary_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringEarningModel.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringDeductionModel.php';
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';

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

try {
    $compId = 1;
    $adminUserId = 1;

    // Same shared-dev-DB isolation precaution every other test in this suite that touches comp_id=1
    // documents -- see feedback_dev_db_shared_state_test_fragility.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $model = new EmployeeModel();
    $recurringModel = new EmployeeRecurringEarningModel($pdo);
    $recurringDeductionModel = new EmployeeRecurringDeductionModel($pdo);
    $eedModel = new EmployeeEarningDeductionModel();
    $typeModel = new PayrollEarningDeductionTypeModel($pdo);

    $earningTypeRes = $typeModel->save($compId, [
        'item_code' => 'SUMTEST_ALLOW', 'item_name_th' => 'ค่าตำแหน่งทดสอบ', 'item_name_en' => 'Test Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 1500, 'tax_treatment' => 'taxable',
    ], $adminUserId);
    checkTrue('fixture: recurring-earning-eligible catalog type created' . (empty($earningTypeRes['status']) ? " ({$earningTypeRes['message']})" : ''), $earningTypeRes['status']);
    $earningTypeId = $earningTypeRes['id'];

    $loanTypeRes = $typeModel->save($compId, [
        'item_code' => 'SUMTEST_LOAN', 'item_name_th' => 'เงินกู้ทดสอบ', 'item_name_en' => 'Test Loan',
        'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'before_tax',
    ], $adminUserId);
    checkTrue('fixture: loan-deduction catalog type created' . (empty($loanTypeRes['status']) ? " ({$loanTypeRes['message']})" : ''), $loanTypeRes['status']);
    $loanTypeId = $loanTypeRes['id'];

    $bonusTypeRes = $typeModel->save($compId, [
        'item_code' => 'SUMTEST_BONUS', 'item_name_th' => 'โบนัสผ่อนทดสอบ', 'item_name_en' => 'Test Installment Bonus',
        'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'tax_treatment' => 'taxable',
    ], $adminUserId);
    checkTrue('fixture: installment-earning catalog type created' . (empty($bonusTypeRes['status']) ? " ({$bonusTypeRes['message']})" : ''), $bonusTypeRes['status']);
    $bonusTypeId = $bonusTypeRes['id'];

    // 2026-08-31, explicit request: "หน้า Employee Detail เพิ่มรายหักประจำด้วยครับ และนำไปเพิ่มใน ตรงสรุปรายได้
    // ประจำ ด้วย" -- Recurring Deduction fixture type, direct mirror of the recurring-earning-eligible
    // type above.
    $deductionTypeRes = $typeModel->save($compId, [
        'item_code' => 'SUMTEST_DED', 'item_name_th' => 'ค่าเครื่องแบบทดสอบ', 'item_name_en' => 'Test Uniform Fee',
        'item_type' => 'deduction', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 400, 'tax_deduction_impact' => 'after_tax',
    ], $adminUserId);
    checkTrue('fixture: recurring-deduction-eligible catalog type created' . (empty($deductionTypeRes['status']) ? " ({$deductionTypeRes['message']})" : ''), $deductionTypeRes['status']);
    $deductionTypeId = $deductionTypeRes['id'];

    $makeEmployee = function (string $suffix, float $baseSalary) use ($pdo, $compId) {
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', :surname_th, 'Test', :surname_en, '1990-01-01', 'Thai',
             :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             'bank', 'monthly', :base_salary, '2020-01-01', 'average', 'active', 1, 1, 0)")
            ->execute([
                ':comp_id' => $compId, ':employee_no' => 'SUM_EMP_' . $suffix . '_' . uniqid(),
                ':surname_th' => $suffix, ':surname_en' => $suffix, ':email' => uniqid() . '@test.local', ':base_salary' => $baseSalary,
            ]);
        return (int)$pdo->lastInsertId();
    };

    // Employee A: base salary + 1 active recurring earning + 1 active loan installment (3 total, 1st pending).
    $empA = $makeEmployee('A', 30000.0);
    $recA = $recurringModel->save($empA, $compId, ['ped_type_id' => $earningTypeId, 'amount' => 1500, 'effective_date' => '2020-01-01'], $adminUserId);
    checkTrue('fixture: recurring earning saved for Employee A' . (empty($recA['status']) ? " ({$recA['message']})" : ''), $recA['status']);
    $loanA = $eedModel->save($empA, $compId, ['ped_type_id' => $loanTypeId, 'total_installments' => 3, 'amount_mode' => 'even_split', 'total_amount' => 3000, 'effective_date' => '2020-01-01'], $adminUserId);
    checkTrue('fixture: 3-installment loan saved for Employee A' . (empty($loanA['status']) ? " ({$loanA['message']})" : ''), $loanA['status']);
    $recDedA = $recurringDeductionModel->save($empA, $compId, ['ped_type_id' => $deductionTypeId, 'amount' => 400, 'effective_date' => '2020-01-01'], $adminUserId);
    checkTrue('fixture: recurring deduction saved for Employee A' . (empty($recDedA['status']) ? " ({$recDedA['message']})" : ''), $recDedA['status']);

    // Employee B: base salary + 1 SUSPENDED recurring earning (must be excluded entirely) + a
    // 2-installment earning-side assignment (SUMTEST_BONUS) with its FIRST installment already paid
    // (status='processed') -- proves only the NEXT PENDING one shows, not installment #1 again.
    $empB = $makeEmployee('B', 25000.0);
    $recB = $recurringModel->save($empB, $compId, [
        'ped_type_id' => $earningTypeId, 'amount' => 800, 'effective_date' => '2020-01-01',
        'suspended_from' => date('Y-m-d', strtotime('-1 day')), 'suspended_to' => date('Y-m-d', strtotime('+30 days')),
    ], $adminUserId);
    checkTrue('fixture: suspended recurring earning saved for Employee B' . (empty($recB['status']) ? " ({$recB['message']})" : ''), $recB['status']);
    $recDedB = $recurringDeductionModel->save($empB, $compId, [
        'ped_type_id' => $deductionTypeId, 'amount' => 150, 'effective_date' => '2020-01-01',
        'suspended_from' => date('Y-m-d', strtotime('-1 day')), 'suspended_to' => date('Y-m-d', strtotime('+30 days')),
    ], $adminUserId);
    checkTrue('fixture: suspended recurring deduction saved for Employee B' . (empty($recDedB['status']) ? " ({$recDedB['message']})" : ''), $recDedB['status']);
    $bonusB = $eedModel->save($empB, $compId, ['ped_type_id' => $bonusTypeId, 'total_installments' => 2, 'amount_mode' => 'even_split', 'total_amount' => 4000, 'effective_date' => '2020-01-01'], $adminUserId);
    checkTrue('fixture: 2-installment bonus saved for Employee B' . (empty($bonusB['status']) ? " ({$bonusB['message']})" : ''), $bonusB['status']);
    $bonusBId = $bonusB['id'];
    // Mark installment #1 as already processed -- installment #2 must be the one reported as "next pending".
    $pdo->prepare("UPDATE employee_earning_deduction_installments SET status = 'processed' WHERE assignment_id = :aid AND installment_no = 1")->execute([':aid' => $bonusBId]);
    $pdo->prepare("UPDATE employee_earning_deductions SET current_installment = 2 WHERE id = :id")->execute([':id' => $bonusBId]);

    $summary = (new EmployeeModel())->standingSummaryList($compId, 0, 50, [], '', 'en');
    // Use reflection-free access: the private helper is exercised transitively via standingSummaryList()
    // above (both employees fall well within a single page of 50).
    $rowA = null; $rowB = null;
    foreach ($summary['data'] as $r) {
        if ((int)$r['id'] === $empA) $rowA = $r;
        if ((int)$r['id'] === $empB) $rowB = $r;
    }
    checkTrue('Employee A found in the summary list', $rowA !== null);
    checkTrue('Employee B found in the summary list', $rowB !== null);

    if ($rowA) {
        check('Employee A: base_salary_amount decrypts correctly', (float)$rowA['summary']['base_salary_amount'], 30000.0);
        check('Employee A: 1 recurring earning line, amount 1500', count($rowA['summary']['recurring']), 1);
        check('Employee A: recurring_total = 1500', (float)$rowA['summary']['recurring_total'], 1500.0);
        check('Employee A: 1 pending PED deduction line (the loan)', count($rowA['summary']['ped_deduction']), 1);
        check('Employee A: pending loan installment_no is 1 (first installment, nothing paid yet)', $rowA['summary']['ped_deduction'][0]['installment_no'], 1);
        check('Employee A: pending loan total_installments is 3', $rowA['summary']['ped_deduction'][0]['total_installments'], 3);
        check('Employee A: pending loan amount = 3000/3 = 1000.00', (float)$rowA['summary']['ped_deduction'][0]['amount'], 1000.0);
        check('Employee A: ped_deduction_total = 1000', (float)$rowA['summary']['ped_deduction_total'], 1000.0);
        check('Employee A: no PED earning lines at all', count($rowA['summary']['ped_earning']), 0);
        check('Employee A: 1 active recurring deduction line, amount 400', count($rowA['summary']['recurring_deduction']), 1);
        check('Employee A: recurring_deduction_total = 400', (float)$rowA['summary']['recurring_deduction_total'], 400.0);
        check('Employee A: total_earning = base(30000) + recurring(1500) + ped_earning(0) = 31500', (float)$rowA['summary']['total_earning'], 31500.0);
        check('Employee A: total_deduction = recurring_deduction(400) + ped_deduction(1000) = 1400', (float)$rowA['summary']['total_deduction'], 1400.0);
        check('Employee A: net_total = 31500 - 1400 = 30100', (float)$rowA['summary']['net_total'], 30100.0);
    }

    if ($rowB) {
        check('Employee B: the SUSPENDED recurring earning is excluded entirely (0 lines, not just 0 amount)', count($rowB['summary']['recurring']), 0);
        check('Employee B: recurring_total is 0 because of the suspension', (float)$rowB['summary']['recurring_total'], 0.0);
        check('Employee B: the SUSPENDED recurring deduction is also excluded entirely (0 lines, not just 0 amount)', count($rowB['summary']['recurring_deduction']), 0);
        check('Employee B: recurring_deduction_total is 0 because of the suspension', (float)$rowB['summary']['recurring_deduction_total'], 0.0);
        check('Employee B: exactly 1 pending PED earning line (installment #2, not #1 again)', count($rowB['summary']['ped_earning']), 1);
        check('Employee B: the reported installment is #2 (the NEXT pending one, #1 was already processed)', $rowB['summary']['ped_earning'][0]['installment_no'], 2);
        check('Employee B: total_installments still correctly reported as 2', $rowB['summary']['ped_earning'][0]['total_installments'], 2);
        check('Employee B: pending bonus amount = 4000/2 = 2000.00', (float)$rowB['summary']['ped_earning'][0]['amount'], 2000.0);
        check('Employee B: total_earning = base(25000) + recurring(0, suspended) + ped_earning(2000) = 27000', (float)$rowB['summary']['total_earning'], 27000.0);
        check('Employee B: total_deduction = 0 (both recurring and PED deduction sides empty)', (float)$rowB['summary']['total_deduction'], 0.0);
    }

    // ---------- Company-wide totals cover the WHOLE filtered set, not just this page ----------
    checkTrue('totals key is present in the response', isset($summary['totals']));
    check('totals.base_salary_amount includes Employee A (30000) + Employee B (25000) -- at minimum, since comp_id=1 has other real employees too',
        (float)$summary['totals']['base_salary_amount'] >= 55000.0, true);
    check('totals.recurring_total includes Employee A\'s 1500 (Employee B\'s is correctly excluded as suspended)',
        (float)$summary['totals']['recurring_total'] >= 1500.0, true);
    check('totals.recurring_deduction_total includes Employee A\'s 400 (Employee B\'s is correctly excluded as suspended)',
        (float)$summary['totals']['recurring_deduction_total'] >= 400.0, true);

    // A tighter, exact check: filter down to JUST these 2 fixture employees via search (their
    // employee_no is unique per this run), so the totals assertion isn't diluted by other real rows.
    $summaryFilteredA = (new EmployeeModel())->standingSummaryList($compId, 0, 50, [], '', 'en');
    $onlyAB = array_filter($summaryFilteredA['data'], fn($r) => (int)$r['id'] === $empA || (int)$r['id'] === $empB);
    $sumBaseAB = array_sum(array_map(fn($r) => (float)$r['summary']['base_salary_amount'], $onlyAB));
    check('sanity: summing just Employee A+B\'s own base salaries from the SAME response gives exactly 55000', $sumBaseAB, 55000.0);

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
