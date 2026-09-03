<?php
/**
 * Verifies 2 new pieces of the 2026-09-02 "multi-channel payment method + probation/intern policy"
 * feature: (1) employees.probation_base_salary_ratio_override -- Probation never had a
 * per-employee ratio override before this round (Internship already did, see
 * tests/intern_pay_policy_test.php, which this file mirrors structurally for the ratio half); (2)
 * the OT-eligible-default-on-transition rule (company policy sets employees.ot_eligible's DEFAULT
 * value exactly once, at the moment an employee transitions INTO probation/internship -- the
 * checkbox itself always wins afterward, confirmed via AskUserQuestion "default only, checkbox
 * still wins").
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Isolates from comp_id=1's real data the same way
 * tests/intern_pay_policy_test.php already does (soft-delete existing employees within this
 * transaction) -- see feedback_dev_db_shared_state_test_fragility.
 * Run with: php tests/probation_ratio_override_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

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
    $adminUserId = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();

    // Same isolation precedent as tests/intern_pay_policy_test.php's own top-of-file comment.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL AND id != :keep")
        ->execute([':comp_id' => $compId, ':keep' => $adminUserId]);

    $policyModel = new PayrollPolicyModel($pdo);
    $runModel = new PayrollRunModel($pdo);
    $cycleModel = new PayrollCycleModel();
    $employeeModel = new EmployeeModel();

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $paymentDate = $periodEnd;

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, probation_base_salary_ratio_override)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1998-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', :employee_status_enum, 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2020-01-01', 'average', 'active',
         0, 0, 0, :ratio_override)");

    // A: probation, no override -- uses company default.
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PROB_A_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'A',
        ':name_en' => 'Test', ':surname_en' => 'A', ':email' => uniqid() . '@test.local', ':employee_status_enum' => 'probation',
        ':base_salary' => 30000, ':ratio_override' => null]);
    $empAId = (int)$pdo->lastInsertId();

    // B: probation, own override (50%) -- must win over the company-wide 80% default.
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PROB_B_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'B',
        ':name_en' => 'Test', ':surname_en' => 'B', ':email' => uniqid() . '@test.local', ':employee_status_enum' => 'probation',
        ':base_salary' => 30000, ':ratio_override' => 50]);
    $empBId = (int)$pdo->lastInsertId();

    $createRun = function () use ($runModel, $cycleModel, $compId, $periodStart, $periodEnd, $paymentDate, $adminUserId) {
        $freshCycleRes = $cycleModel->save($compId, [
            'cycle_name' => 'PROBRATIO_TEST_CYCLE_' . uniqid(),
            'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
            'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
        ], $adminUserId);
        $res = $runModel->create($compId, [
            'cycle_id' => $freshCycleRes['id'], 'run_name' => 'PROBRATIO_TEST_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
        ], $adminUserId, true);
        if (empty($res['status'])) {
            throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
        }
        $runModel->recalculate($res['id'], $compId, $adminUserId, true);
        return $runModel->getDetails($res['id'], $compId);
    };

    echo "=== probation_base_salary_ratio_override: employee's OWN ratio wins over company default ===\n";
    $policyModel->save($compId, ['probation_defer_pvd' => false, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => 80], $adminUserId);
    $details = $createRun();
    $rowA = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $empAId));
    $rowB = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $empBId));
    check('A (no override) uses the company-wide 80% (30000 * 0.8 = 24000)', (float)$rowA['base_salary_amount'], 24000.0);
    check('B (own 50% override) uses ITS OWN ratio, not the company 80% (30000 * 0.5 = 15000)', (float)$rowB['base_salary_amount'], 15000.0);

    echo "=== Precedence unchanged: intern wins over probation even when probation ALSO has an override ===\n";
    $pdo->prepare("UPDATE `employees` SET employment_type = 'internship' WHERE id = :id")->execute([':id' => $empBId]);
    $pdo->prepare("UPDATE `employees` SET intern_base_salary_ratio_override = 20 WHERE id = :id")->execute([':id' => $empBId]);
    $detailsPrecedence = $createRun();
    $rowBPrecedence = current(array_filter($detailsPrecedence, fn($d) => (int)$d['employee_id'] === $empBId));
    check('B is now BOTH probation (50% override) AND internship (20% override) -- intern wins (30000 * 0.2 = 6000), never stacked', (float)$rowBPrecedence['base_salary_amount'], 6000.0);
    // Revert B back to full_time/no intern override for the sections below.
    $pdo->prepare("UPDATE `employees` SET employment_type = 'full_time', intern_base_salary_ratio_override = NULL WHERE id = :id")->execute([':id' => $empBId]);

    echo "=== OT-eligible-default-on-transition: soft default only, checkbox always wins afterward ===\n";
    $policyModel->save($compId, [
        'probation_defer_pvd' => false, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => 80,
        'probation_ot_eligible_default' => 0,
    ], $adminUserId);

    // Case 1: brand-new employee created directly WITH employment_status=probation, ot_eligible not
    // explicitly checked (submitted as false, the common "left it unchecked" create-time state) --
    // the company default (0 = not eligible) should apply. Using a truthy default (1) would be a
    // no-op test here, so also cover a truthy default in Case 3 below via an existing-employee
    // transition instead.
    $saveC = $employeeModel->save($compId, [
        'employee_no' => 'TEST_PROB_C_' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'name_en' => 'Test', 'date_of_birth' => '1998-01-01',
        'nationality' => 'Thai', 'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0800000001',
        'employment_date' => '2020-01-01', 'employment_status' => 'probation', 'employment_type' => 'full_time',
        'payment_method_id' => 1, 'salary_type' => 'monthly', 'base_salary_amount' => 25000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average', 'ot_eligible' => false,
    ], $adminUserId);
    checkTrue('fixture: employee C created' . (empty($saveC['status']) ? " ({$saveC['message']})" : ''), $saveC['status']);
    $empCId = $saveC['id'] ?? 0;
    $otC = (int)$pdo->query("SELECT ot_eligible FROM employees WHERE id = {$empCId}")->fetchColumn();
    check('C: OT-default (0) applied at CREATE time while entering probation directly', $otC, 0);

    // Case 2: an EXISTING permanent employee (ot_eligible=1, explicitly set) later reclassified into
    // probation -- the OT-default is CREATE-TIME-ONLY (see EmployeeModel::save()'s own docblock for
    // the real bug this test caught in an earlier "also fire on transition" design: there's no way
    // to distinguish "admin didn't touch this checkbox" from "admin's pre-existing true value
    // coincidentally wasn't changed," so an update must NEVER apply the default, full stop).
    $insEmp2 = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1998-01-01', 'Thai',
         :email, '0800000002', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 25000, '2020-01-01', 'average', 'active',
         0, 0, 0, 1)");
    $insEmp2->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PROB_D_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'D',
        ':name_en' => 'Test', ':surname_en' => 'D', ':email' => uniqid() . '@test.local']);
    $empDId = (int)$pdo->lastInsertId();
    $saveD = $employeeModel->save($compId, [
        'id' => $empDId, 'employee_no' => 'TEST_PROB_D_' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'name_en' => 'Test', 'date_of_birth' => '1998-01-01',
        'nationality' => 'Thai', 'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0800000002',
        'employment_date' => '2020-01-01', 'employment_status' => 'probation', 'employment_type' => 'full_time',
        'payment_method_id' => 1, 'salary_type' => 'monthly', 'base_salary_amount' => 25000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average', 'ot_eligible' => true,
    ], $adminUserId);
    checkTrue('fixture: employee D transitioned to probation' . (empty($saveD['status']) ? " ({$saveD['message']})" : ''), $saveD['status']);
    $otD = (int)$pdo->query("SELECT ot_eligible FROM employees WHERE id = {$empDId}")->fetchColumn();
    check('D: existing employee transitioning into probation -- OT-default NEVER fires on an update, submitted value (true) is respected exactly', $otD, 1);

    // Case 3: employee C (already in probation from Case 1, ot_eligible=0 from the default) has an
    // admin EXPLICITLY re-check the box on a LATER save while still in probation -- must stick, the
    // one-time default never re-fires on a later save.
    $saveCAgain = $employeeModel->save($compId, [
        'id' => $empCId, 'employee_no' => 'TEST_PROB_C_' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'name_en' => 'Test', 'date_of_birth' => '1998-01-01',
        'nationality' => 'Thai', 'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0800000001',
        'employment_date' => '2020-01-01', 'employment_status' => 'probation', 'employment_type' => 'full_time',
        'payment_method_id' => 1, 'salary_type' => 'monthly', 'base_salary_amount' => 25000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average', 'ot_eligible' => true,
    ], $adminUserId);
    checkTrue('C: explicit re-save while STILL in probation succeeds' . (empty($saveCAgain['status']) ? " ({$saveCAgain['message']})" : ''), $saveCAgain['status']);
    $otCAgain = (int)$pdo->query("SELECT ot_eligible FROM employees WHERE id = {$empCId}")->fetchColumn();
    check('C: admin\'s explicit re-check (true) sticks -- the one-time default from Case 1 never re-fires on a later save', $otCAgain, 1);

    // ==================================================================================================
    // 2026-09-02, same-day follow-up: closes 2 gaps flagged after review --
    // (1) "เงื่อนไขการหักภาษี/ประกันสังคมที่แตกต่างจากพนักงานปกติ" was never actually built (only the
    //     pre-existing defer_pvd covered PVD) -- defer_sso (company-wide) + tax_exempt_default
    //     (soft, create-time-only) close it.
    // (2) the per-employee "custom settings" override only ever covered 1 field (ratio), not
    //     "ครบทุกช่อง ไม่ตัดทอน" as explicitly requested -- defer_pvd_override/defer_sso_override/
    //     defer_recurring_earning_override now let ONE employee's own setting win over the company
    //     default in EITHER direction (force-on even if company is off, force-off even if company
    //     is on), proven below.
    // ==================================================================================================
    require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
    $pedTypeModel = new PayrollEarningDeductionTypeModel();

    $insEmpFull = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt,
         probation_defer_pvd_override, probation_defer_sso_override, probation_defer_recurring_earning_override)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1998-01-01', 'Thai',
         :email, :mobile, 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'probation', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0,
         :defer_pvd_ov, :defer_sso_ov, :defer_recurring_ov)");

    // G: company policy has BOTH defers OFF, but G's own override forces them ON.
    $insEmpFull->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PROB_G_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'G',
        ':name_en' => 'Test', ':surname_en' => 'G', ':email' => uniqid() . '@test.local', ':mobile' => '0800000003',
        ':defer_pvd_ov' => 1, ':defer_sso_ov' => 1, ':defer_recurring_ov' => null]);
    $empGId = (int)$pdo->lastInsertId();

    // H: company policy has BOTH defers ON, but H's own override forces them OFF.
    $insEmpFull->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PROB_H_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'H',
        ':name_en' => 'Test', ':surname_en' => 'H', ':email' => uniqid() . '@test.local', ':mobile' => '0800000004',
        ':defer_pvd_ov' => 0, ':defer_sso_ov' => 0, ':defer_recurring_ov' => null]);
    $empHId = (int)$pdo->lastInsertId();

    // I: recurring-earning defer override -- company policy OFF, I's own override forces it ON.
    $insEmpFull->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PROB_I_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'I',
        ':name_en' => 'Test', ':surname_en' => 'I', ':email' => uniqid() . '@test.local', ':mobile' => '0800000005',
        ':defer_pvd_ov' => null, ':defer_sso_ov' => null, ':defer_recurring_ov' => 1]);
    $empIId = (int)$pdo->lastInsertId();

    $pedRes = $pedTypeModel->save($compId, [
        'item_code' => 'TESTPROBALLOW' . rand(100, 999), 'item_name_th' => 'ค่าตำแหน่งทดสอบ', 'item_name_en' => 'Test Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 1500,
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: recurring allowance PED type created', $pedRes['status']);
    $pdo->prepare("INSERT INTO `employee_recurring_earnings` (employee_id, ped_type_id, amount, effective_date, created_by)
        VALUES (:employee_id, :ped_type_id, 1500, '2020-01-01', :created_by)")
        ->execute([':employee_id' => $empIId, ':ped_type_id' => $pedRes['id'], ':created_by' => $adminUserId]);

    $createRun2 = function () use ($runModel, $cycleModel, $compId, $periodStart, $periodEnd, $paymentDate, $adminUserId) {
        $freshCycleRes = $cycleModel->save($compId, [
            'cycle_name' => 'PROBOVERRIDE_TEST_CYCLE_' . uniqid(),
            'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
            'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
        ], $adminUserId);
        $res = $runModel->create($compId, [
            'cycle_id' => $freshCycleRes['id'], 'run_name' => 'PROBOVERRIDE_TEST_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
        ], $adminUserId, true);
        if (empty($res['status'])) {
            throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
        }
        $runModel->recalculate($res['id'], $compId, $adminUserId, true);
        return $runModel->getDetails($res['id'], $compId);
    };

    echo "=== defer_pvd_override / defer_sso_override: per-employee override wins over company default in EITHER direction ===\n";
    $policyModel->save($compId, ['probation_defer_pvd' => false, 'probation_defer_sso' => false, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => null], $adminUserId);
    $detailsOffCompany = $createRun2();
    $rowG1 = current(array_filter($detailsOffCompany, fn($d) => (int)$d['employee_id'] === $empGId));
    $pvdLineG1 = current(array_filter($rowG1['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
    $ssoLineG1 = current(array_filter($rowG1['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'));
    $pvdActive = $pvdLineG1 !== false;
    $ssoActive = $ssoLineG1 !== false;
    echo $pvdActive ? "  (TH_PVD is configured for comp_id=1 -- verifying override forces it off)\n" : "  (TH_PVD not configured for comp_id=1 -- skipping PVD-specific assertions)\n";
    echo $ssoActive ? "  (TH_SSO is configured for comp_id=1 -- verifying override forces it off)\n" : "  (TH_SSO not configured for comp_id=1 -- skipping SSO-specific assertions)\n";
    if ($pvdActive) {
        check('G: company defer_pvd=OFF, but G\'s own override=ON -- PVD is 0 for G', round((float)($pvdLineG1['employee_amount'] ?? -1), 2), 0.0);
    }
    if ($ssoActive) {
        check('G: company defer_sso=OFF, but G\'s own override=ON -- SSO is 0 for G', round((float)($ssoLineG1['employee_amount'] ?? -1), 2), 0.0);
    }

    $policyModel->save($compId, ['probation_defer_pvd' => true, 'probation_defer_sso' => true, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => null], $adminUserId);
    $detailsOnCompany = $createRun2();
    $rowH1 = current(array_filter($detailsOnCompany, fn($d) => (int)$d['employee_id'] === $empHId));
    $pvdLineH1 = current(array_filter($rowH1['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
    $ssoLineH1 = current(array_filter($rowH1['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'));
    if ($pvdActive) {
        checkTrue('H: company defer_pvd=ON, but H\'s own override=OFF -- PVD still contributes normally for H', ($pvdLineH1 !== false) && (float)($pvdLineH1['employee_amount'] ?? 0) > 0);
    }
    if ($ssoActive) {
        checkTrue('H: company defer_sso=ON, but H\'s own override=OFF -- SSO still contributes normally for H', ($ssoLineH1 !== false) && (float)($ssoLineH1['employee_amount'] ?? 0) > 0);
    }
    // Also re-confirm G is UNCHANGED by this second policy flip (its own override still governs).
    $rowG2 = current(array_filter($detailsOnCompany, fn($d) => (int)$d['employee_id'] === $empGId));
    $pvdLineG2 = current(array_filter($rowG2['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
    if ($pvdActive) {
        check('G: still deferred (0) even though company policy flipped to ON in the meantime -- G\'s own override is unaffected', round((float)($pvdLineG2['employee_amount'] ?? -1), 2), 0.0);
    }

    echo "=== defer_recurring_earning_override: per-employee override forces a defer the company policy doesn't have ===\n";
    $rowI = current(array_filter($detailsOnCompany, fn($d) => (int)$d['employee_id'] === $empIId));
    $recurringLineI = current(array_filter($rowI['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    checkTrue('I: recurring allowance line ABSENT -- I\'s own override defers it even though company policy does not', $recurringLineI === false);

    echo "=== tax_exempt_default: same CREATE-TIME-ONLY soft-default contract as ot_eligible_default ===\n";
    $policyModel->save($compId, ['probation_tax_exempt_default' => 1], $adminUserId);
    $saveJ = $employeeModel->save($compId, [
        'employee_no' => 'TEST_PROB_J_' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'name_en' => 'Test', 'date_of_birth' => '1998-01-01',
        'nationality' => 'Thai', 'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0800000006',
        'employment_date' => '2020-01-01', 'employment_status' => 'probation', 'employment_type' => 'full_time',
        'payment_method_id' => 1, 'salary_type' => 'monthly', 'base_salary_amount' => 25000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average', 'tax_exempt' => false,
    ], $adminUserId);
    checkTrue('fixture: employee J created' . (empty($saveJ['status']) ? " ({$saveJ['message']})" : ''), $saveJ['status']);
    $empJId = $saveJ['id'] ?? 0;
    $taxExemptJ = (int)$pdo->query("SELECT tax_exempt FROM employees WHERE id = {$empJId}")->fetchColumn();
    check('J: tax_exempt_default (1) applied at CREATE time while entering probation directly', $taxExemptJ, 1);

    // Existing employee transitioning into probation must NEVER have tax_exempt touched (same
    // CREATE-TIME-ONLY rule as ot_eligible_default, proven by the D case above).
    $insEmpK = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1998-01-01', 'Thai',
         :email, '0800000007', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 25000, '2020-01-01', 'average', 'active',
         0, 0, 0)");
    $insEmpK->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PROB_K_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'K',
        ':name_en' => 'Test', ':surname_en' => 'K', ':email' => uniqid() . '@test.local']);
    $empKId = (int)$pdo->lastInsertId();
    $saveK = $employeeModel->save($compId, [
        'id' => $empKId, 'employee_no' => 'TEST_PROB_K_' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'name_en' => 'Test', 'date_of_birth' => '1998-01-01',
        'nationality' => 'Thai', 'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0800000007',
        'employment_date' => '2020-01-01', 'employment_status' => 'probation', 'employment_type' => 'full_time',
        'payment_method_id' => 1, 'salary_type' => 'monthly', 'base_salary_amount' => 25000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average', 'tax_exempt' => false,
    ], $adminUserId);
    checkTrue('fixture: employee K transitioned to probation' . (empty($saveK['status']) ? " ({$saveK['message']})" : ''), $saveK['status']);
    $taxExemptK = (int)$pdo->query("SELECT tax_exempt FROM employees WHERE id = {$empKId}")->fetchColumn();
    check('K: existing employee transitioning into probation -- tax_exempt_default NEVER fires on an update, submitted value (false) is respected exactly', $taxExemptK, 0);

    echo "=== Validation: negative day-count overrides are rejected ===\n";
    $badLeaveLimit = $employeeModel->save($compId, [
        'id' => $empGId, 'employee_no' => 'TEST_PROB_G_' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'name_en' => 'Test', 'date_of_birth' => '1998-01-01',
        'nationality' => 'Thai', 'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0800000003',
        'employment_date' => '2020-01-01', 'employment_status' => 'probation', 'employment_type' => 'full_time',
        'payment_method_id' => 1, 'salary_type' => 'monthly', 'base_salary_amount' => 30000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average',
        'probation_leave_days_limit_override' => -5,
    ], $adminUserId);
    checkFalse('save() rejects a negative probation_leave_days_limit_override', $badLeaveLimit['status']);
    $badPeriodDays = $employeeModel->save($compId, [
        'id' => $empGId, 'employee_no' => 'TEST_PROB_G_' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'name_en' => 'Test', 'date_of_birth' => '1998-01-01',
        'nationality' => 'Thai', 'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0800000003',
        'employment_date' => '2020-01-01', 'employment_status' => 'probation', 'employment_type' => 'full_time',
        'payment_method_id' => 1, 'salary_type' => 'monthly', 'base_salary_amount' => 30000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average',
        'probation_period_days_override' => -1,
    ], $adminUserId);
    checkFalse('save() rejects a negative probation_period_days_override', $badPeriodDays['status']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
