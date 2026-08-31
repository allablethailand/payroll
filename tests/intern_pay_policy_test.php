<?php
/**
 * Lightweight end-to-end verification for internship pay conditions (2026-08-31, explicit request:
 * "และถ้าต้องการ Set การจ่ายสำหรับเด็กฝึกงาน...ให้ครอบคลุมถึงเด็กฝึกงานบางคนที่ให้เงินเดือน แต่อยากให้ตั้งเงื่อนไข
 * ได้แบบ Probation...ในหน้าเงินเดือนก็แก้ไขได้เป็นรายบุคคลด้วย" -- confirmed via AskUserQuestion: same engine
 * as Probation, own separate field set (company_payroll_policies.intern_*), gated by
 * employees.employment_type='internship' instead of employment_status='probation', PLUS a
 * per-employee override of the ratio (employees.intern_base_salary_ratio_override). Direct structural
 * mirror of tests/probation_pay_policy_test.php -- see that file's own comments for the shared
 * reasoning, not repeated here. See PayrollPolicyModel::internSettings()'s own docblock and
 * PayrollRunModel::recalculate()'s own precedence comments for what happens when an employee is
 * somehow both an intern AND on probation at once.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back, so it never leaves any data behind.
 * Run with: php tests/intern_pay_policy_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';

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
    $adminUserId = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();

    // Same isolation precedent as tests/probation_pay_policy_test.php's own top-of-file comment.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL AND id != :keep")
        ->execute([':comp_id' => $compId, ':keep' => $adminUserId]);

    $policyModel = new PayrollPolicyModel($pdo);
    $runModel = new PayrollRunModel($pdo);
    $cycleModel = new PayrollCycleModel();

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $paymentDate = $periodEnd;

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1998-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', NULL, :employee_status_enum, :employment_type_enum, 'office', 'manual',
         'bank', 'monthly', :base_salary, '2020-01-01', 'average', 'active',
         1, 1, 0)");

    // Salaried intern (permanent status, internship TYPE -- the real gate for everything below).
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_INTERN_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'เด็กฝึกงาน', ':name_en' => 'Test', ':surname_en' => 'Intern',
        ':email' => uniqid() . '@test.local', ':employee_status_enum' => 'permanent', ':employment_type_enum' => 'internship',
        ':base_salary' => 30000,
    ]);
    $internEmpId = (int)$pdo->lastInsertId();

    // Ordinary full-time employee -- unaffected control.
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_FULLTIME_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'พนักงานประจำ', ':name_en' => 'Test', ':surname_en' => 'FullTime',
        ':email' => uniqid() . '@test.local', ':employee_status_enum' => 'permanent', ':employment_type_enum' => 'full_time',
        ':base_salary' => 30000,
    ]);
    $fullTimeEmpId = (int)$pdo->lastInsertId();

    // Employee who is BOTH employment_type='internship' AND employment_status='probation' at once --
    // proves internship settings take PRECEDENCE over probation settings, never stacking/multiplying.
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_INTERN_PROBATION_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ฝึกงานทดลองงาน', ':name_en' => 'Test', ':surname_en' => 'InternOnProbation',
        ':email' => uniqid() . '@test.local', ':employee_status_enum' => 'probation', ':employment_type_enum' => 'internship',
        ':base_salary' => 30000,
    ]);
    $internProbationEmpId = (int)$pdo->lastInsertId();

    // A Recurring Allowance on all 3 employees -- same fixture shape as probation_pay_policy_test.php.
    $pedTypeModel = new PayrollEarningDeductionTypeModel();
    $pedRes = $pedTypeModel->save($compId, [
        'item_code' => 'TESTINTALLOW' . rand(100, 999), 'item_name_th' => 'ค่าตำแหน่งทดสอบ', 'item_name_en' => 'Test Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 1500,
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: recurring allowance PED type created', $pedRes['status']);
    $recurringPedTypeId = $pedRes['id'];
    $insRecurring = $pdo->prepare("INSERT INTO `employee_recurring_earnings` (employee_id, ped_type_id, amount, effective_date, created_by)
        VALUES (:employee_id, :ped_type_id, 1500, '2020-01-01', :created_by)");
    foreach ([$internEmpId, $fullTimeEmpId, $internProbationEmpId] as $eid) {
        $insRecurring->execute([':employee_id' => $eid, ':ped_type_id' => $recurringPedTypeId, ':created_by' => $adminUserId]);
    }

    $createRun = function () use ($runModel, $cycleModel, $compId, $periodStart, $periodEnd, $paymentDate, $adminUserId) {
        $freshCycleRes = $cycleModel->save($compId, [
            'cycle_name' => 'INTERN_TEST_CYCLE_' . uniqid(),
            'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
            'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
        ], $adminUserId);
        $res = $runModel->create($compId, [
            'cycle_id' => $freshCycleRes['id'], 'run_name' => 'INTERN_TEST_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
        ], $adminUserId, true);
        if (empty($res['status'])) {
            throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
        }
        $runModel->recalculate($res['id'], $compId, $adminUserId, true);
        return $runModel->getDetails($res['id'], $compId);
    };

    echo "=== Baseline: all intern policies OFF (unchanged default behavior) ===\n";
    $policyModel->save($compId, ['intern_defer_pvd' => false, 'intern_defer_recurring_earning' => false, 'intern_base_salary_ratio' => null], $adminUserId);
    $baselineDetails = $createRun();
    $baselineInternRow = current(array_filter($baselineDetails, fn($d) => (int)$d['employee_id'] === $internEmpId));
    check('baseline: intern employee still gets full base salary (no ratio applied)', (float)$baselineInternRow['base_salary_amount'], 30000.0);
    $baselineRecurringLine = current(array_filter($baselineInternRow['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    checkTrue('baseline: recurring_earning source line present for intern employee', $baselineRecurringLine !== false);
    $pvdLineBaseline = current(array_filter($baselineInternRow['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
    $pvdActiveForThisCompany = $pvdLineBaseline !== false && (float)($pvdLineBaseline['employee_amount'] ?? 0) > 0;
    echo $pvdActiveForThisCompany
        ? "  (TH_PVD is configured active for comp_id=1 -- baseline employee_amount = " . ($pvdLineBaseline['employee_amount'] ?? 'n/a') . ", will verify the defer switch turns it off below)\n"
        : "  (TH_PVD is not active/configured for comp_id=1 in this dev DB -- skipping the PVD-specific defer assertion)\n";

    echo "=== intern_base_salary_ratio: 80% applied ONLY to the intern employee (company-wide default) ===\n";
    $policyModel->save($compId, ['intern_defer_pvd' => false, 'intern_defer_recurring_earning' => false, 'intern_base_salary_ratio' => 80], $adminUserId);
    $ratioDetails = $createRun();
    $ratioInternRow = current(array_filter($ratioDetails, fn($d) => (int)$d['employee_id'] === $internEmpId));
    $ratioFullTimeRow = current(array_filter($ratioDetails, fn($d) => (int)$d['employee_id'] === $fullTimeEmpId));
    check('intern employee base salary reduced to 80% (30000 * 0.8 = 24000)', (float)$ratioInternRow['base_salary_amount'], 24000.0);
    check('full-time employee UNAFFECTED (still full 30000)', (float)$ratioFullTimeRow['base_salary_amount'], 30000.0);

    echo "=== Per-employee override: this ONE intern's own ratio (50%) wins over the company default (80%) ===\n";
    // Written directly via SQL (not EmployeeModel::save()) -- that method's generic allColumns() loop
    // treats every save as a FULL-form submission (matching collectEmployeeFormData()'s own "always
    // submit the whole form together" behavior in the real UI), so a minimal payload here would
    // silently null out this fixture's other real column values instead of a true partial update.
    $pdo->prepare("UPDATE `employees` SET intern_base_salary_ratio_override = 50 WHERE id = :id")->execute([':id' => $internEmpId]);
    $overrideDetails = $createRun();
    $overrideInternRow = current(array_filter($overrideDetails, fn($d) => (int)$d['employee_id'] === $internEmpId));
    $overrideFullTimeRow = current(array_filter($overrideDetails, fn($d) => (int)$d['employee_id'] === $fullTimeEmpId));
    check('the intern with an override uses THEIR OWN 50% (30000 * 0.5 = 15000), not the company 80%', (float)$overrideInternRow['base_salary_amount'], 15000.0);
    check('full-time employee still fully unaffected (30000)', (float)$overrideFullTimeRow['base_salary_amount'], 30000.0);

    // Clear the override for the remaining scenarios so they exercise the company-wide default again.
    $pdo->prepare("UPDATE `employees` SET intern_base_salary_ratio_override = NULL WHERE id = :id")->execute([':id' => $internEmpId]);

    echo "=== intern_defer_recurring_earning: recurring allowance withheld ONLY for interns ===\n";
    $policyModel->save($compId, ['intern_defer_pvd' => false, 'intern_defer_recurring_earning' => true, 'intern_base_salary_ratio' => null], $adminUserId);
    $deferRecurringDetails = $createRun();
    $deferInternRow = current(array_filter($deferRecurringDetails, fn($d) => (int)$d['employee_id'] === $internEmpId));
    $deferFullTimeRow = current(array_filter($deferRecurringDetails, fn($d) => (int)$d['employee_id'] === $fullTimeEmpId));
    $internRecurringLine = current(array_filter($deferInternRow['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    $fullTimeRecurringLine = current(array_filter($deferFullTimeRow['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    checkTrue('intern employee: recurring allowance line ABSENT while deferred', $internRecurringLine === false);
    checkTrue('full-time employee: recurring allowance line still present (unaffected)', $fullTimeRecurringLine !== false);

    if ($pvdActiveForThisCompany) {
        echo "=== intern_defer_pvd: PVD contribution withheld ONLY for interns ===\n";
        $policyModel->save($compId, ['intern_defer_pvd' => true, 'intern_defer_recurring_earning' => false, 'intern_base_salary_ratio' => null], $adminUserId);
        $deferPvdDetails = $createRun();
        $deferPvdInternRow = current(array_filter($deferPvdDetails, fn($d) => (int)$d['employee_id'] === $internEmpId));
        $deferPvdFullTimeRow = current(array_filter($deferPvdDetails, fn($d) => (int)$d['employee_id'] === $fullTimeEmpId));
        $pvdLineIntern = current(array_filter($deferPvdInternRow['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
        $pvdLineFullTime = current(array_filter($deferPvdFullTimeRow['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
        $internPvdAmount = $pvdLineIntern !== false ? (float)($pvdLineIntern['employee_amount'] ?? 0) : 0.0;
        $fullTimePvdAmount = $pvdLineFullTime !== false ? (float)($pvdLineFullTime['employee_amount'] ?? 0) : 0.0;
        check('intern employee: PVD employee_amount is 0 while deferred', $internPvdAmount, 0.0);
        checkTrue('full-time employee: PVD still contributes normally (unaffected)', $fullTimePvdAmount > 0);
    }

    echo "=== Precedence: employment_type='internship' wins over employment_status='probation' when BOTH are true ===\n";
    // Intern ratio (80%) and probation ratio (20%) deliberately set to DIFFERENT values -- the result
    // must reflect ONLY the intern ratio (80% -> 24000), never the probation ratio, and never both
    // multiplied together (30000 * 0.8 * 0.2 = 4800, which would prove the wrong, stacked behavior).
    $policyModel->save($compId, [
        'intern_defer_pvd' => false, 'intern_defer_recurring_earning' => false, 'intern_base_salary_ratio' => 80,
        'probation_defer_pvd' => false, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => 20,
    ], $adminUserId);
    $precedenceDetails = $createRun();
    $precedenceRow = current(array_filter($precedenceDetails, fn($d) => (int)$d['employee_id'] === $internProbationEmpId));
    check('employee that is BOTH intern and on probation: base salary uses the INTERN ratio (30000 * 0.8 = 24000), not probation\'s 20% and not both stacked', (float)$precedenceRow['base_salary_amount'], 24000.0);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
