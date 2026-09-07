<?php
/**
 * Lightweight end-to-end verification for probation pay conditions (2026-08-30, explicit request,
 * confirmed via AskUserQuestion: defer PVD until probation passes, defer Recurring Allowances until
 * probation passes, a configurable base-salary ratio during probation) -- see
 * PayrollPolicyModel::probationSettings()'s own docblock and PayrollRunModel::recalculate()'s own
 * wiring comments. Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real
 * dev DB inside a transaction that is always rolled back, so it never leaves any data behind.
 * Run with: php tests/probation_pay_policy_test.php
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

    // Same isolation precedent as tests/payroll_run_test.php's own top-of-file comment: comp_id=1 is
    // the real, shared dev DB. This file builds its own complete fixture set and never depends on
    // any pre-existing employee, so leftover rows are cleared for the duration of this transaction
    // only (rolled back at the end).
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL AND id != :keep")
        ->execute([':comp_id' => $compId, ':keep' => $adminUserId]);
    // 2026-09-04, Backlog Phase 10, T056: same isolation precedent -- clear any pre-existing active
    // probation_policy_sets for comp_id=1 so this test's own Default Set (created below) is
    // guaranteed to actually BE the resolved default, not shadowed by a real one left over from
    // interactive UI testing on this shared dev DB.
    $pdo->prepare("UPDATE `probation_policy_sets` SET status = 'inactive' WHERE comp_id = :comp_id AND status = 'active'")
        ->execute([':comp_id' => $compId]);

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
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1995-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', NULL, :employee_status_enum, 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2020-01-01', 'average', 'active',
         1, 1, 0)");

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_PROBATION_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ทดลองงาน', ':name_en' => 'Test', ':surname_en' => 'Probation',
        ':email' => uniqid() . '@test.local', ':employee_status_enum' => 'probation', ':base_salary' => 30000,
    ]);
    $probationEmpId = (int)$pdo->lastInsertId();

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_PERMANENT_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ประจำ', ':name_en' => 'Test', ':surname_en' => 'Permanent',
        ':email' => uniqid() . '@test.local', ':employee_status_enum' => 'permanent', ':base_salary' => 30000,
    ]);
    $permanentEmpId = (int)$pdo->lastInsertId();

    // A Recurring Allowance (position allowance) on BOTH employees -- same shape/precedent as
    // tests/employee_recurring_earning_test.php's own fixture, inserted directly (not via the
    // model) to avoid the model's own transaction conflicting with this script's outer one.
    $pedTypeModel = new PayrollEarningDeductionTypeModel();
    $pedRes = $pedTypeModel->save($compId, [
        'item_code' => 'TESTPOSALLOW' . rand(100, 999), 'item_name_th' => 'ค่าตำแหน่งทดสอบ', 'item_name_en' => 'Test Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 1500,
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: recurring allowance PED type created', $pedRes['status']);
    $recurringPedTypeId = $pedRes['id'];
    $insRecurring = $pdo->prepare("INSERT INTO `employee_recurring_earnings` (employee_id, ped_type_id, amount, effective_date, created_by)
        VALUES (:employee_id, :ped_type_id, 1500, '2020-01-01', :created_by)");
    $insRecurring->execute([':employee_id' => $probationEmpId, ':ped_type_id' => $recurringPedTypeId, ':created_by' => $adminUserId]);
    $insRecurring->execute([':employee_id' => $permanentEmpId, ':ped_type_id' => $recurringPedTypeId, ':created_by' => $adminUserId]);

    // Each scenario below creates its own run over the SAME period -- a fresh cycle per call sidesteps
    // create()'s own (cycle_id, period) duplicate-period rejection (isDuplicatePeriod() is scoped per
    // cycle, not globally, same as the "same period range, WITH a real cycle -- must still succeed"
    // case tests/payroll_run_test.php's own fixture already exercises).
    $createRun = function () use ($runModel, $cycleModel, $compId, $periodStart, $periodEnd, $paymentDate, $adminUserId) {
        $freshCycleRes = $cycleModel->save($compId, [
            'cycle_name' => 'PROBATION_TEST_CYCLE_' . uniqid(),
            'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
            'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
        ], $adminUserId);
        $res = $runModel->create($compId, [
            'cycle_id' => $freshCycleRes['id'], 'run_name' => 'PROBATION_TEST_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
        ], $adminUserId, true);
        if (empty($res['status'])) {
            throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
        }
        $runModel->recalculate($res['id'], $compId, $adminUserId, true);
        return $runModel->getDetails($res['id'], $compId);
    };

    // 2026-09-04, Backlog Phase 10, T056: probation_* is no longer read from PayrollPolicyModel::
    // save()'s own company_payroll_policies row (that write still succeeds, it's just dead for
    // calculation now) -- probationSettings() resolves through probation_policy_sets instead. Every
    // scenario below now goes through probationSetSave() targeting the SAME Set by id (captured from
    // the first call) so it stays this company's single Default Set across all 4 scenarios, same
    // "one company-wide probation policy" shape this test has always exercised -- T056 only adds the
    // ability to have MORE than one Set, it doesn't change what "the Default" means for a company
    // that only ever configures one.
    $probationSetId = null;
    $saveProbationDefault = function (array $fields) use ($policyModel, $compId, $adminUserId, &$probationSetId) {
        $payload = array_merge(['set_name_th' => 'ค่าเริ่มต้น', 'set_name_en' => 'Default', 'is_default' => true], $fields);
        if ($probationSetId !== null) {
            $payload['id'] = $probationSetId;
        }
        $res = $policyModel->probationSetSave($compId, $payload, $adminUserId);
        if (empty($res['status'])) {
            throw new RuntimeException('probationSetSave() failed: ' . ($res['message'] ?? ''));
        }
        $probationSetId = (int)$res['id'];
    };

    echo "=== Baseline: all probation policies OFF (unchanged default behavior) ===\n";
    $saveProbationDefault(['probation_defer_pvd' => false, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => null]);
    $baselineDetails = $createRun();
    $baselineProbationRow = current(array_filter($baselineDetails, fn($d) => (int)$d['employee_id'] === $probationEmpId));
    check('baseline: probation employee still gets full base salary (no ratio applied)', (float)$baselineProbationRow['base_salary_amount'], 30000.0);
    $baselineRecurringLine = current(array_filter($baselineProbationRow['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    checkTrue('baseline: recurring_earning source line present for probation employee', $baselineRecurringLine !== false);
    $pvdLineBaseline = current(array_filter($baselineProbationRow['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
    $pvdActiveForThisCompany = $pvdLineBaseline !== false && (float)($pvdLineBaseline['employee_amount'] ?? 0) > 0;
    echo $pvdActiveForThisCompany
        ? "  (TH_PVD is configured active for comp_id=1 -- baseline employee_amount = " . ($pvdLineBaseline['employee_amount'] ?? 'n/a') . ", will verify the defer switch turns it off below)\n"
        : "  (TH_PVD is not active/configured for comp_id=1 in this dev DB -- skipping the PVD-specific defer assertion, base-salary-ratio and recurring-earning-defer are unaffected by this and still verified)\n";

    echo "=== probation_base_salary_ratio: 80% applied ONLY to the probation employee ===\n";
    $saveProbationDefault(['probation_defer_pvd' => false, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => 80]);
    $ratioDetails = $createRun();
    $ratioProbationRow = current(array_filter($ratioDetails, fn($d) => (int)$d['employee_id'] === $probationEmpId));
    $ratioPermanentRow = current(array_filter($ratioDetails, fn($d) => (int)$d['employee_id'] === $permanentEmpId));
    check('probation employee base salary reduced to 80% (30000 * 0.8 = 24000)', (float)$ratioProbationRow['base_salary_amount'], 24000.0);
    check('permanent employee UNAFFECTED (still full 30000)', (float)$ratioPermanentRow['base_salary_amount'], 30000.0);

    echo "=== probation_defer_recurring_earning: recurring allowance withheld ONLY for the probation employee ===\n";
    $saveProbationDefault(['probation_defer_pvd' => false, 'probation_defer_recurring_earning' => true, 'probation_base_salary_ratio' => null]);
    $deferRecurringDetails = $createRun();
    $deferProbationRow = current(array_filter($deferRecurringDetails, fn($d) => (int)$d['employee_id'] === $probationEmpId));
    $deferPermanentRow = current(array_filter($deferRecurringDetails, fn($d) => (int)$d['employee_id'] === $permanentEmpId));
    $probationRecurringLine = current(array_filter($deferProbationRow['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    $permanentRecurringLine = current(array_filter($deferPermanentRow['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    checkTrue('probation employee: recurring allowance line ABSENT while deferred', $probationRecurringLine === false);
    checkTrue('permanent employee: recurring allowance line still present (unaffected)', $permanentRecurringLine !== false);

    if ($pvdActiveForThisCompany) {
        echo "=== probation_defer_pvd: PVD contribution withheld ONLY for the probation employee ===\n";
        $saveProbationDefault(['probation_defer_pvd' => true, 'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => null]);
        $deferPvdDetails = $createRun();
        $deferPvdProbationRow = current(array_filter($deferPvdDetails, fn($d) => (int)$d['employee_id'] === $probationEmpId));
        $deferPvdPermanentRow = current(array_filter($deferPvdDetails, fn($d) => (int)$d['employee_id'] === $permanentEmpId));
        $pvdLineProbation = current(array_filter($deferPvdProbationRow['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
        $pvdLinePermanent = current(array_filter($deferPvdPermanentRow['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
        $probationPvdAmount = $pvdLineProbation !== false ? (float)($pvdLineProbation['employee_amount'] ?? 0) : 0.0;
        $permanentPvdAmount = $pvdLinePermanent !== false ? (float)($pvdLinePermanent['employee_amount'] ?? 0) : 0.0;
        check('probation employee: PVD employee_amount is 0 while deferred', $probationPvdAmount, 0.0);
        checkTrue('permanent employee: PVD still contributes normally (unaffected)', $permanentPvdAmount > 0);
    }

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
