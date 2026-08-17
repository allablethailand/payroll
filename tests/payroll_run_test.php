<?php
/**
 * Lightweight verification script for the Payroll Run state machine (PayrollRunModel).
 * Not PHPUnit — see tests/statutory_engine_test.php for why. Runs against the real dev DB
 * inside a transaction that is always rolled back, so it never leaves any data behind.
 * Run with: php tests/payroll_run_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/AttendanceBonusSchemeModel.php';
require_once __DIR__ . '/../app/models/AttendanceBonusLedgerModel.php';
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
    $adminUserId = 1; // used with isAdmin=true throughout except the dedicated permission-denial check

    // ---------- Fixtures (created inside the transaction, rolled back at the end) ----------
    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $paymentDate = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $midMonthJoin = (clone $today)->modify('first day of this month')->modify('+15 days')->format('Y-m-d');

    // Cycle
    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'TEST_CYCLE_' . uniqid(),
        'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25,
        'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, // seeded master_bank_file_formats row: KBANK_SMART
        'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created' . (empty($cycleRes['status']) ? " ({$cycleRes['message']})" : ''), $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    // Employees: full-period (SSO/PVD enrolled), mid-period joiner, opted-out (no SSO/PVD),
    // and a mid-period leaver — covering both the enrollment-flag fix and the leaver pro-rate fix.
    $midMonthLeaveDate = (clone $today)->modify('first day of this month')->modify('+10 days')->format('Y-m-d');
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         :employment_date, :employment_end_date, :employee_status_enum, 'full_time', 'office', 'manual',
         'bank', 'monthly', :base_salary, :salary_effective_date, 'average', 'active',
         :sso_enrolled, :pvd_enrolled, :tax_exempt)");

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_FULL_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'เต็มเดือน', ':name_en' => 'Test', ':surname_en' => 'FullMonth',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => 30000, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeFullId = (int)$pdo->lastInsertId();

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_MID_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'กลางเดือน', ':name_en' => 'Test', ':surname_en' => 'MidJoiner',
        ':email' => uniqid() . '@test.local', ':employment_date' => $midMonthJoin, ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => 30000, ':salary_effective_date' => $midMonthJoin,
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeMidId = (int)$pdo->lastInsertId();

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_OPTOUT_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ไม่เข้าประกันสังคม', ':name_en' => 'Test', ':surname_en' => 'OptedOut',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => 30000, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 0, ':pvd_enrolled' => 0, ':tax_exempt' => 0,
    ]);
    $employeeOptOutId = (int)$pdo->lastInsertId();

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_LEAVER_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ลาออกกลางเดือน', ':name_en' => 'Test', ':surname_en' => 'MidLeaver',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => $midMonthLeaveDate,
        ':employee_status_enum' => 'resigned',
        ':base_salary' => 30000, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeLeaverId = (int)$pdo->lastInsertId();

    // Role with no payroll permissions, for the permission-denial check
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en, can_process_payroll, can_approve_payroll, can_finalize_payroll)
        VALUES (:comp_id, 'ทดสอบไม่มีสิทธิ์', 'Test No Permission', 0, 0, 0)")->execute([':comp_id' => $compId]);
    $noPermRoleId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id WHERE id = :id")->execute([':role_id' => $noPermRoleId, ':id' => $employeeFullId]);

    // A PED earning assignment (transport allowance) on the full-period employee
    $pedTypeModel = new PayrollEarningDeductionTypeModel();
    $pedRes = $pedTypeModel->save($compId, [
        'item_code' => 'TESTALLOW' . rand(100, 999),
        'item_name_th' => 'ค่าเดินทางทดสอบ', 'item_name_en' => 'Test Transport Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 1000,
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: PED type created', $pedRes['status']);
    $pedTypeId = $pedRes['id'];

    // Inserted directly (not via EmployeeEarningDeductionModel::save) because that method
    // manages its own transaction internally, which would conflict with the outer
    // transaction wrapping this script (see tests/statutory_engine_test.php for the same issue).
    $pdo->prepare("INSERT INTO `employee_earning_deductions`
        (employee_id, ped_type_id, total_installments, current_installment, amount_mode, total_amount, effective_date, status, created_by)
        VALUES (:employee_id, :ped_type_id, 1, 0, 'even_split', 1000, :effective_date, 'active', :created_by)")
        ->execute([':employee_id' => $employeeFullId, ':ped_type_id' => $pedTypeId, ':effective_date' => $periodStart, ':created_by' => $adminUserId]);
    $assignmentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `employee_earning_deduction_installments` (assignment_id, installment_no, amount, status)
        VALUES (:assignment_id, 1, 1000, 'pending')")->execute([':assignment_id' => $assignmentId]);
    $eedRes = ['status' => true, 'id' => $assignmentId];
    checkTrue('fixture: employee PED assignment created', $eedRes['status']);

    // An attendance bonus scheme + a passed ledger entry for this period
    $schemeModel = new AttendanceBonusSchemeModel();
    $schemeRes = $schemeModel->save($compId, [
        'scheme_name' => 'TEST_SCHEME_' . uniqid(), 'condition_no_absent' => true,
        'starting_amount' => 500, 'increment_amount' => 0, 'reset_cycle_months' => 12, 'reset_cycle_basis' => 'employee_anniversary',
    ], $adminUserId);
    checkTrue('fixture: bonus scheme created', $schemeRes['status']);
    $schemeId = $schemeRes['id'];

    $ledgerModel = new AttendanceBonusLedgerModel();
    $ledgerRes = $ledgerModel->save($compId, [
        'employee_id' => $employeeFullId, 'scheme_id' => $schemeId,
        'period_year' => (int)date('Y', strtotime($periodStart)), 'period_month' => (int)date('n', strtotime($periodStart)),
        'status' => 'passed',
    ], $adminUserId);
    checkTrue('fixture: bonus ledger entry created' . (empty($ledgerRes['status']) ? " ({$ledgerRes['message']})" : ''), $ledgerRes['status']);

    // ---------- Actual state machine tests ----------
    $runModel = new PayrollRunModel($pdo);

    echo "=== Create (draft) ===\n";
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('create succeeds' . (empty($createRes['status']) ? " ({$createRes['message']})" : ''), $createRes['status']);
    if (empty($createRes['status'])) {
        throw new RuntimeException('Cannot continue without a created run: ' . $createRes['message']);
    }
    $runId = $createRes['id'];
    $run = $runModel->get($runId, $compId);
    check('state is draft', $run['state'], 'draft');

    echo "=== Duplicate period rejected ===\n";
    $dupRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'DUP', 'period_start_date' => $periodStart,
        'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    check('duplicate period+cycle rejected', $dupRes['status'], false);

    echo "=== Pending Pull (sync_process_id) ===\n";
    $insSyncProc = $pdo->prepare("INSERT INTO payroll_sync_processes
        (comp_id, origami_process_id, process_no, origami_comp_code, origami_comp_name, frequency_type, schema_version, raw_payload)
        VALUES (:comp_id, :origami_process_id, :process_no, 'TESTCODE', 'Test Co.', 'monthly', 1, '{}')");
    $insSyncProc->execute([':comp_id' => $compId, ':origami_process_id' => random_int(1000000, 9999999), ':process_no' => 'SYNCTEST_' . uniqid()]);
    $syncProcessId = (int)$pdo->lastInsertId();

    // Distinct periods from every other run created in this test (this-month is already taken by
    // the very first fixture run above; next-month is taken by the "Delete only allowed in draft"
    // run further down) -- +2/+3 months so isDuplicatePeriod() never interferes with what this
    // section is actually testing.
    $pullPeriodStart = (clone $today)->modify('first day of +2 months')->format('Y-m-d');
    $pullPeriodEnd = (clone $today)->modify('last day of +2 months')->format('Y-m-d');
    $pullRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PULLED_' . uniqid(),
        'period_start_date' => $pullPeriodStart, 'period_end_date' => $pullPeriodEnd, 'payment_date' => $pullPeriodEnd,
        'sync_process_id' => $syncProcessId,
    ], $adminUserId, true);
    checkTrue('create with a valid unlinked sync_process_id succeeds' . (empty($pullRes['status']) ? " ({$pullRes['message']})" : ''), $pullRes['status']);
    $pulledRunId = $pullRes['id'] ?? 0;
    $pulledRunRow = $pdo->query("SELECT sync_process_id FROM payroll_runs WHERE id = {$pulledRunId}")->fetch(PDO::FETCH_ASSOC);
    check('created run stored the sync_process_id', (int)($pulledRunRow['sync_process_id'] ?? 0), $syncProcessId);

    $secondPullDate = (clone $today)->modify('first day of +3 months')->format('Y-m-d');
    $secondPullEnd = (clone $today)->modify('last day of +3 months')->format('Y-m-d');
    $reuseRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'REUSE_' . uniqid(),
        'period_start_date' => $secondPullDate, 'period_end_date' => $secondPullEnd, 'payment_date' => $secondPullEnd,
        'sync_process_id' => $syncProcessId,
    ], $adminUserId, true);
    check('reusing an already-pulled sync_process_id is rejected', $reuseRes['status'], false);

    echo "=== Recalculate ===\n";
    $calcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds', $calcRes['status']);
    check('employee_count is 4 (full, mid-joiner, opt-out, leaver)', $calcRes['employee_count'], 4);
    check('no validation errors', $calcRes['has_validation_errors'], false);

    $details = $runModel->getDetails($runId, $compId);
    $fullDetail = null;
    $midDetail = null;
    $optOutDetail = null;
    $leaverDetail = null;
    foreach ($details as $d) {
        if ((int)$d['employee_id'] === $employeeFullId) $fullDetail = $d;
        if ((int)$d['employee_id'] === $employeeMidId) $midDetail = $d;
        if ((int)$d['employee_id'] === $employeeOptOutId) $optOutDetail = $d;
        if ((int)$d['employee_id'] === $employeeLeaverId) $leaverDetail = $d;
    }
    check('full-period employee is not prorated', $fullDetail['prorate_days'], null);
    checkTrue('mid-month joiner IS prorated', $midDetail['prorate_days'] !== null);
    checkTrue('mid-month joiner base salary reduced by proration', (float)$midDetail['base_salary_amount'] < 30000.0);
    check('full-period gross = base(30000) + allowance(1000) + bonus(500)', (float)$fullDetail['gross_amount'], 31500.0);
    checkTrue('full-period has a PED earning line', count(array_filter($fullDetail['earning_breakdown'], fn($l) => $l['source'] === 'ped')) === 1);
    checkTrue('full-period has an attendance_bonus earning line', count(array_filter($fullDetail['earning_breakdown'], fn($l) => $l['source'] === 'attendance_bonus')) === 1);
    check('both core employees calculated cleanly', $fullDetail['calc_status'] === 'calculated' && $midDetail['calc_status'] === 'calculated', true);

    echo "=== Per-employee SSO/PVD enrollment fix ===\n";
    $fullSso = array_values(array_filter($fullDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? null;
    $optOutSso = array_values(array_filter($optOutDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? null;
    $optOutPvd = array_values(array_filter($optOutDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'))[0] ?? null;
    checkTrue('SSO-enrolled employee gets a real SSO deduction', $fullSso !== null && (float)$fullSso['employee_amount'] > 0);
    check('opted-out employee gets 0 SSO deduction', $optOutSso !== null ? (float)$optOutSso['employee_amount'] : null, 0.0);
    check('opted-out employee SSO line is flagged not-enrolled', $optOutSso['note'] ?? null, 'employee_not_enrolled');
    check('opted-out employee gets 0 PVD deduction', $optOutPvd !== null ? (float)$optOutPvd['employee_amount'] : null, 0.0);

    echo "=== Mid-period leaver pro-rate fix ===\n";
    checkTrue('mid-period leaver IS prorated', $leaverDetail['prorate_days'] !== null);
    check('leaver prorate_days = 11 (period start through leave date, inclusive)', (int)$leaverDetail['prorate_days'], 11);
    checkTrue('leaver base salary reduced by proration', (float)$leaverDetail['base_salary_amount'] < 30000.0);

    $runAfterCalc = $runModel->get($runId, $compId);
    checkTrue('run totals updated (gross > 0)', (float)$runAfterCalc['total_gross_amount'] > 0);

    echo "=== Permission denial (no-permission role, not admin) ===\n";
    $permDenyRes = $runModel->submit($runId, $compId, $employeeFullId, false);
    check('submit denied for role with can_process_payroll=0', $permDenyRes['status'], false);

    echo "=== Submit (draft -> pending_approval) ===\n";
    $submitRes = $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('submit succeeds', $submitRes['status']);
    check('state is pending_approval', $runModel->get($runId, $compId)['state'], 'pending_approval');

    echo "=== Illegal transition blocked (recalculate while pending_approval) ===\n";
    $illegalRecalc = $runModel->recalculate($runId, $compId, $adminUserId, true);
    check('recalculate blocked outside draft', $illegalRecalc['status'], false);

    echo "=== Revert (pending_approval -> draft) ===\n";
    $revertRes = $runModel->revert($runId, $compId, $adminUserId, true, 'test revert');
    checkTrue('revert succeeds', $revertRes['status']);
    check('state is draft again', $runModel->get($runId, $compId)['state'], 'draft');

    echo "=== Re-submit then Reject (pending_approval -> rejected) ===\n";
    $runModel->submit($runId, $compId, $adminUserId, true);
    $rejectNoReasonRes = $runModel->reject($runId, $compId, $adminUserId, true, '');
    check('reject without reason is rejected', $rejectNoReasonRes['status'], false);
    $rejectRes = $runModel->reject($runId, $compId, $adminUserId, true, 'ยอดไม่ตรง');
    checkTrue('reject with reason succeeds', $rejectRes['status']);
    check('state is rejected', $runModel->get($runId, $compId)['state'], 'rejected');

    echo "=== Revise after reject (rejected -> draft) ===\n";
    $reviseRes = $runModel->reviseAfterReject($runId, $compId, $adminUserId, true);
    checkTrue('reviseAfterReject succeeds', $reviseRes['status']);
    check('state is draft', $runModel->get($runId, $compId)['state'], 'draft');

    echo "=== Full happy path to Approved -> Paid -> Locked ===\n";
    $runModel->recalculate($runId, $compId, $adminUserId, true);
    $runModel->submit($runId, $compId, $adminUserId, true);
    $approveRes = $runModel->approve($runId, $compId, $adminUserId, true);
    checkTrue('approve succeeds', $approveRes['status']);
    check('state is approved', $runModel->get($runId, $compId)['state'], 'approved');

    $illegalDelete = $runModel->delete($runId, $compId, $adminUserId, true);
    check('delete blocked once approved', $illegalDelete['status'], false);

    $markPaidRes = $runModel->markPaid($runId, $compId, $adminUserId, true, [
        'payment_method' => 'bank_transfer', 'payment_reference' => 'TEST-REF-001',
    ]);
    checkTrue('markPaid succeeds', $markPaidRes['status']);
    check('state is paid', $runModel->get($runId, $compId)['state'], 'paid');

    // Side effects of markPaid: PED installment processed, ledger entry locked
    $instStmt = $pdo->prepare("SELECT status, payroll_run_id FROM `employee_earning_deduction_installments`
        WHERE assignment_id = :assignment_id");
    $instStmt->execute([':assignment_id' => $eedRes['id']]);
    $inst = $instStmt->fetch(PDO::FETCH_ASSOC);
    check('PED installment flipped to processed', $inst['status'], 'processed');
    check('PED installment tagged with this run_id', (int)$inst['payroll_run_id'], $runId);

    $ledgerCheck = $ledgerModel->get($ledgerRes['id'], $compId);
    checkTrue('attendance bonus ledger entry got locked', $ledgerCheck['locked_at'] !== null);

    $lockRes = $runModel->lock($runId, $compId, $adminUserId, true);
    checkTrue('lock succeeds', $lockRes['status']);
    check('state is locked', $runModel->get($runId, $compId)['state'], 'locked');

    $illegalLockAgain = $runModel->lock($runId, $compId, $adminUserId, true);
    check('locking an already-locked run is blocked', $illegalLockAgain['status'], false);

    echo "=== Delete only allowed in draft ===\n";
    $secondRun = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_DELETE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of next month')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of next month')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of next month')->format('Y-m-d'),
    ], $adminUserId, true);
    $deleteRes = $runModel->delete($secondRun['id'], $compId, $adminUserId, true);
    checkTrue('delete succeeds on draft run', $deleteRes['status']);
    check('deleted run no longer retrievable', $runModel->get($secondRun['id'], $compId), null);

    echo "=== Cancel ===\n";
    $cancelTargetRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_CANCEL_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +4 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +4 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +4 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $cancelTargetId = $cancelTargetRes['id'];

    $emptyReasonRes = $runModel->cancel($cancelTargetId, $compId, $adminUserId, true, '   ');
    check('cancel without a reason is rejected', $emptyReasonRes['status'], false);

    $cancelRes = $runModel->cancel($cancelTargetId, $compId, $adminUserId, true, 'No longer needed this period.');
    checkTrue('cancel from draft succeeds' . (empty($cancelRes['status']) ? " ({$cancelRes['message']})" : ''), $cancelRes['status']);
    $cancelledRun = $runModel->get($cancelTargetId, $compId);
    check('state is cancelled', $cancelledRun['state'], 'cancelled');
    check('cancel_reason stored', $cancelledRun['cancel_reason'], 'No longer needed this period.');
    check('cancelled_by stored', (int)$cancelledRun['cancelled_by'], $adminUserId);

    $recancelRes = $runModel->cancel($cancelTargetId, $compId, $adminUserId, true, 'Again.');
    check('cancelling an already-cancelled run is rejected', $recancelRes['status'], false);

    // $runId is 'locked' at this point in the test (happy-path section above) -- money has moved,
    // so cancel() must refuse it regardless of reason.
    $cancelLockedRes = $runModel->cancel($runId, $compId, $adminUserId, true, 'Trying to cancel a locked run.');
    check('cancelling a locked (already-paid) run is rejected', $cancelLockedRes['status'], false);

    echo "=== Audit log has one entry per action ===\n";
    $auditLog = $runModel->getAuditLog($runId, $compId);
    checkTrue('audit log recorded multiple actions', count($auditLog) >= 6);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
