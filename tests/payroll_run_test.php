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
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../app/models/SetupRulesModel.php';

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

    // recalculate() used to filter is_payroll_ready=1, which incidentally hid every leftover
    // placeholder employee anyone had ever created against this real, shared dev-DB company (id 1)
    // -- e.g. via interactive manual testing of the Pending Pull screen, outside any rolled-back
    // test transaction. Per explicit request (2026-08-19), recalculate() now pulls incomplete
    // profiles into the calculation table (flagged with calc_errors='profile_incomplete') instead
    // of excluding them -- which means any such leftover row now legitimately shows up in every
    // run this test creates for "this month" onward (their employment_date is in the past and
    // employment_end_date is NULL, so they match any period from here on), breaking this test's
    // employee_count/has_validation_errors assertions and everything downstream that depends on a
    // clean calculation (submit onward) through no fault of the run this test is actually building.
    // Originally scoped to is_payroll_ready=0 only, on the assumption every OTHER leftover row was
    // genuinely complete and therefore harmless noise -- broadened to every employee at comp_id=1
    // (2026-08-19, found while adding independent-tab-save support to EmployeeModel::save(): a real
    // leftover row from earlier manual testing, is_payroll_ready=1 from back when that column was
    // hardcoded true on every successful save, still matched this test's date range and inflated
    // employee_count) since this test creates its own complete fixture set from scratch regardless
    // and never depends on any pre-existing employee at comp_id=1. Soft-delete them for the duration
    // of this run ONLY -- entirely inside this script's own transaction, rolled back at the very end,
    // so nothing here is a real/permanent change.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);

    // Same reasoning as the employees soft-delete just above (real leftover data from actual
    // interactive testing on this shared dev DB, not a test fixture -- confirmed via created_by/
    // created_at, see feedback_dev_db_shared_state_test_fragility in project memory): a real
    // attendance_deduction_rules row for comp_id=1's 'late' event (flat_amount @ 1.00/minute) was
    // found live, breaking this file's "sync late deduction = (hourlyRate/60)*minutes*1.0" fixed
    // assumption below. Cleared for the duration of this transaction only, rolled back at the end.
    $pdo->prepare("DELETE FROM `attendance_deduction_rules` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);

    // Same reasoning again (real dev-DB config, not a test fixture): a real ACTIVE Approval
    // Workflow is configured for PAYROLL_RUN_APPROVAL in this shared dev DB (the exact one the
    // 2026-08-23 bug report was about -- a single approver_type='user' step). Since submit() now
    // routes through it whenever hasActiveWorkflow() is true (see PayrollRunModel::submit()'s own
    // docblock), leaving it active would silently hijack every run this file submits away from
    // the flat-role fixtures below and onto that unrelated real user. Deactivated for the duration
    // of this transaction only, rolled back at the end -- the dedicated "Approval Workflow engine
    // integration" section further down creates and activates its OWN throwaway workflow instead.
    $pdo->prepare("UPDATE `approval_workflows` w
        JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
        SET w.status = 'inactive'
        WHERE w.comp_id = :comp_id AND awdt.document_type_code = 'PAYROLL_RUN_APPROVAL' AND w.status = 'active'")
        ->execute([':comp_id' => $compId]);

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

    // A custom-item PED assignment (2026-08-19, explicit request: "Item ให้สามารถใส่เองได้") --
    // ped_type_id NULL, custom_item_name/custom_item_type set instead. Proves recalculate()'s LEFT
    // JOIN fix actually pulls this into the calculation (an INNER JOIN would silently drop it).
    $pdo->prepare("INSERT INTO `employee_earning_deductions`
        (employee_id, ped_type_id, custom_item_name, custom_item_type, total_installments, current_installment, amount_mode, total_amount, effective_date, status, created_by)
        VALUES (:employee_id, NULL, 'ค่ามัดจำชุดยูนิฟอร์ม', 'deduction', 1, 0, 'even_split', 200, :effective_date, 'active', :created_by)")
        ->execute([':employee_id' => $employeeFullId, ':effective_date' => $periodStart, ':created_by' => $adminUserId]);
    $customAssignmentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `employee_earning_deduction_installments` (assignment_id, installment_no, amount, status)
        VALUES (:assignment_id, 1, 200, 'pending')")->execute([':assignment_id' => $customAssignmentId]);
    checkTrue('fixture: custom-item PED assignment created', $customAssignmentId > 0);

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

    echo "=== Off-cycle run (no cycle_id) ===\n";
    $offCycleStart = (clone $today)->modify('first day of +5 months')->format('Y-m-d');
    $offCycleEnd = (clone $today)->modify('last day of +5 months')->format('Y-m-d');
    $offCycleRes = $runModel->create($compId, [
        'run_name' => 'OFFCYCLE_' . uniqid(),
        'period_start_date' => $offCycleStart, 'period_end_date' => $offCycleEnd, 'payment_date' => $offCycleEnd,
    ], $adminUserId, true);
    checkTrue('create with no cycle_id at all succeeds' . (empty($offCycleRes['status']) ? " ({$offCycleRes['message']})" : ''), $offCycleRes['status']);
    $offCycleRunId = $offCycleRes['id'] ?? 0;
    $offCycleRow = $pdo->query("SELECT cycle_id FROM payroll_runs WHERE id = {$offCycleRunId}")->fetch(PDO::FETCH_ASSOC);
    check('off-cycle run stored cycle_id as NULL', $offCycleRow['cycle_id'], null);

    // Same period range as the off-cycle run above, but WITH a real cycle -- must still succeed,
    // proving isDuplicatePeriod() correctly treats a NULL-cycle run as no collision at all (an
    // ad-hoc payment legitimately CAN share a date range with a normal cycle's run).
    $sameRangeCycleRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'SAME_RANGE_CYCLE_' . uniqid(),
        'period_start_date' => $offCycleStart, 'period_end_date' => $offCycleEnd, 'payment_date' => $offCycleEnd,
    ], $adminUserId, true);
    checkTrue('a cycle-based run for the same date range as an off-cycle run still succeeds (no false collision)' . (empty($sameRangeCycleRes['status']) ? " ({$sameRangeCycleRes['message']})" : ''), $sameRangeCycleRes['status']);

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

    $insSyncProc2 = $pdo->prepare("INSERT INTO payroll_sync_processes
        (comp_id, origami_process_id, process_no, origami_comp_code, origami_comp_name, frequency_type, schema_version, raw_payload)
        VALUES (:comp_id, :origami_process_id, :process_no, 'TESTCODE', 'Test Co.', 'monthly', 1, '{}')");
    $insSyncProc2->execute([':comp_id' => $compId, ':origami_process_id' => random_int(1000000, 9999999), ':process_no' => 'SYNCTEST_' . uniqid()]);
    $syncProcessId2 = (int)$pdo->lastInsertId();
    $noCycleWithSyncRes = $runModel->create($compId, [
        'run_name' => 'PULLED_NO_CYCLE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +6 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +6 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +6 months')->format('Y-m-d'),
        'sync_process_id' => $syncProcessId2,
    ], $adminUserId, true);
    check('pulling from a sync process without a cycle_id is rejected (sync data is inherently cycle-based)', $noCycleWithSyncRes['status'], false);

    // ---------- Eligibility branching (2026-08-19): Pending-Pull runs only include employees
    // actually present in the sync payload -- not the broader date-range membership a cycle-based
    // run uses. $syncProcessId currently has zero payroll_sync_items rows; give it two -- one
    // resolved to a real fixture employee, one deliberately left unmapped -- to prove both "not in
    // the payload at all" and "in the payload but unmapped" are excluded, while every OTHER
    // date-range-eligible fixture employee (mid/opt-out/leaver) is excluded too despite matching
    // dates, because they were simply never part of what this process actually sent.
    echo "=== Eligibility: Pending-Pull run only includes sync-payload employees ===\n";
    $insSyncItem = $pdo->prepare("INSERT INTO payroll_sync_items (process_id, employee_id, payroll_code, mapping_status)
        VALUES (:process_id, :employee_id, :payroll_code, :mapping_status)");
    $insSyncItem->execute([':process_id' => $syncProcessId, ':employee_id' => $employeeFullId, ':payroll_code' => 'PULL_MAPPED', ':mapping_status' => 'mapped']);
    $insSyncItem->execute([':process_id' => $syncProcessId, ':employee_id' => null, ':payroll_code' => 'PULL_UNMAPPED', ':mapping_status' => 'unmapped']);

    $pullCalcRes = $runModel->recalculate($pulledRunId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds on the pulled run' . (empty($pullCalcRes['status']) ? " ({$pullCalcRes['message']})" : ''), $pullCalcRes['status']);
    check('only the 1 mapped sync-payload employee is included (not the other 3 date-range-eligible fixture employees)', $pullCalcRes['employee_count'], 1);
    $pullDetails = $runModel->getDetails($pulledRunId, $compId);
    check('the included employee is the one the sync payload actually mapped', (int)($pullDetails[0]['employee_id'] ?? 0), $employeeFullId);
    $pullGrossBefore = (float)$pullDetails[0]['gross_amount'];

    // ---------- Sync-derived earning/deduction wiring (2026-08-20): proves SyncPayResolver is
    // actually invoked end-to-end from recalculate() for a sync-based run, not just correct in
    // isolation (see tests/sync_pay_resolver_test.php for the resolver's own unit coverage).
    // $employeeFullId has base_salary_amount=30000 -> dailyRate=1000, hourlyRate=125.
    echo "=== Sync-derived lines flow into recalculate()'s earning/deduction breakdown ===\n";
    $weekdayOtScopeId = (int)$pdo->query("SELECT id FROM master_ot_scope_types WHERE code = 'weekday'")->fetchColumn();
    $pdo->prepare("INSERT INTO `ot_rates` (comp_id, ot_name_th, ot_name_en, ot_scope_id, multiplier_rate, calculation_base, status, created_by)
        VALUES (?, 'OT ทดสอบ', 'Test OT', ?, 1.50, 'hourly', 'active', ?)")
        ->execute([$compId, $weekdayOtScopeId, $adminUserId]);
    $itemValuesJson = json_encode([
        ['item_id' => 99, 'item_code' => 'CUSTOM_ATTENDANCE_BONUS', 'item_name' => 'Attendance Bonus', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 500, 'remark' => null],
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("UPDATE `payroll_sync_items` SET ot_req_working_day_hrs = 2, trip_allowance = 300, late_mins = 15, leave_without_pay_days = 3, item_values = :iv
            WHERE process_id = :process_id AND employee_id = :employee_id")
        ->execute([':iv' => $itemValuesJson, ':process_id' => $syncProcessId, ':employee_id' => $employeeFullId]);

    $pullCalcRes2 = $runModel->recalculate($pulledRunId, $compId, $adminUserId, true);
    checkTrue('recalculate still succeeds after adding sync attendance data' . (empty($pullCalcRes2['status']) ? " ({$pullCalcRes2['message']})" : ''), $pullCalcRes2['status']);
    $pullDetails2 = $runModel->getDetails($pulledRunId, $compId);
    $syncEarning = array_values(array_filter($pullDetails2[0]['earning_breakdown'] ?? [], fn($l) => ($l['source'] ?? null) === 'sync'));
    $syncDeduction = array_values(array_filter($pullDetails2[0]['deduction_breakdown'] ?? [], fn($l) => ($l['source'] ?? null) === 'sync'));
    check('3 sync-sourced earning lines (OT, trip allowance, custom bonus)', count($syncEarning), 3);
    check('2 sync-sourced deduction lines (late, unpaid leave)', count($syncDeduction), 2);
    $syncOtLine = current(array_filter($syncEarning, fn($l) => $l['code'] === 'OT'));
    check('sync OT amount = hourlyRate(125) * 1.5 * 2h = 375', (float)($syncOtLine['amount'] ?? null), 375.0);
    $syncTripLine = current(array_filter($syncEarning, fn($l) => $l['code'] === 'TRIP_ALLOW'));
    check('sync trip allowance amount = face value 300', (float)($syncTripLine['amount'] ?? null), 300.0);
    $syncBonusLine = current(array_filter($syncEarning, fn($l) => $l['code'] === 'CUSTOM:Attendance Bonus'));
    checkTrue('unmatched item_values code became a custom sync earning line', $syncBonusLine !== false);
    check('sync custom bonus amount = face value 500 (unit_type null)', (float)($syncBonusLine['amount'] ?? null), 500.0);
    $syncLateLine = current(array_filter($syncDeduction, fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('sync late deduction amount = (125/60)*15 = 31.25', (float)($syncLateLine['amount'] ?? null), 31.25);
    $syncLeaveLine = current(array_filter($syncDeduction, fn($l) => $l['code'] === 'LEAVE_NO_PAY_DEDUCT'));
    checkTrue('sync unpaid leave deduction line present', $syncLeaveLine !== false);
    check('sync unpaid leave deduction amount = dailyRate(1000)*3 = 3000', (float)($syncLeaveLine['amount'] ?? null), 3000.0);
    // Diff against the pre-sync-data gross (not an absolute figure) -- this employee may also carry
    // other, unrelated standing earning lines (PED assignments/attendance bonus) from earlier
    // fixtures in this same test file that legitimately apply to any run of theirs; isolating the
    // diff is what actually proves the sync lines specifically, without being fragile to those.
    $pullGrossAfter = (float)$pullDetails2[0]['gross_amount'];
    check('gross_amount increased by exactly the sync earning total: 375 + 300 + 500 = 1175', round($pullGrossAfter - $pullGrossBefore, 2), 1175.0);

    // ---------- Sync deduction line overrides (2026-08-21, explicit request: "ปรับค่า สาย ขาดงาน
    // ลาไม่รับเงิน หรือยกเว้นไม่ให้หัก") -- per-run, per-employee, per-item adjustment on a sync-
    // computed deduction line. Reuses $pulledRunId/$employeeFullId's already-computed LATE_DEDUCT
    // (31.25) and LEAVE_NO_PAY_DEDUCT (3000) lines from the section just above. ----------
    echo "=== Line overrides: override_amount on a sync-computed deduction line ===\n";
    $overrideRes = $runModel->lineOverrideSave($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', 'override_amount', 10.00, 'HR waived most of it', $adminUserId, true);
    checkTrue('lineOverrideSave() override_amount succeeds' . (empty($overrideRes['status']) ? " ({$overrideRes['message']})" : ''), $overrideRes['status']);
    $afterOverrideDetails = $runModel->getDetails($pulledRunId, $compId);
    $lateLineAfterOverride = current(array_filter($afterOverrideDetails[0]['deduction_breakdown'], fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('LATE_DEDUCT amount is now the overridden 10.00, not the computed 31.25', (float)($lateLineAfterOverride['amount'] ?? null), 10.0);
    checkTrue('overridden line note mentions the override', strpos($lateLineAfterOverride['note'] ?? '', 'override') !== false);

    echo "=== Line overrides: exclude a sync-computed deduction line entirely ===\n";
    $excludeRes = $runModel->lineOverrideSave($pulledRunId, $compId, $employeeFullId, 'LEAVE_NO_PAY_DEDUCT', 'exclude', null, null, $adminUserId, true);
    checkTrue('lineOverrideSave() exclude succeeds' . (empty($excludeRes['status']) ? " ({$excludeRes['message']})" : ''), $excludeRes['status']);
    $afterExcludeDetails = $runModel->getDetails($pulledRunId, $compId);
    $leaveLineAfterExclude = current(array_filter($afterExcludeDetails[0]['deduction_breakdown'], fn($l) => $l['code'] === 'LEAVE_NO_PAY_DEDUCT'));
    checkTrue('LEAVE_NO_PAY_DEDUCT line no longer present after exclude', $leaveLineAfterExclude === false);

    echo "=== syncDeductionLinesForEmployee(): raw computed amount + current override state, for the UI ===\n";
    $syncLinesForUi = $runModel->syncDeductionLinesForEmployee($compId, $pulledRunId, $employeeFullId);
    $lateUiLine = current(array_filter($syncLinesForUi, fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('UI-facing computed_amount stays the RAW 31.25 even though an override is active', (float)($lateUiLine['computed_amount'] ?? null), 31.25);
    check('UI-facing override_action reflects the active override', $lateUiLine['override_action'] ?? null, 'override_amount');
    check('UI-facing override_amount reflects the active override', (float)($lateUiLine['override_amount'] ?? null), 10.0);
    $leaveUiLine = current(array_filter($syncLinesForUi, fn($l) => $l['code'] === 'LEAVE_NO_PAY_DEDUCT'));
    check('excluded line is still listed for the UI (so it can be un-excluded) with its raw computed amount', (float)($leaveUiLine['computed_amount'] ?? null), 3000.0);
    check('excluded line reports override_action=exclude', $leaveUiLine['override_action'] ?? null, 'exclude');

    echo "=== Line overrides: removing an override reverts to the computed default ===\n";
    $removeOverrideRes = $runModel->lineOverrideRemove($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', $adminUserId, true);
    checkTrue('lineOverrideRemove() succeeds' . (empty($removeOverrideRes['status']) ? " ({$removeOverrideRes['message']})" : ''), $removeOverrideRes['status']);
    $afterResetDetails = $runModel->getDetails($pulledRunId, $compId);
    $lateLineAfterReset = current(array_filter($afterResetDetails[0]['deduction_breakdown'], fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('LATE_DEDUCT amount reverted to the computed 31.25 after removing the override', (float)($lateLineAfterReset['amount'] ?? null), 31.25);
    // Cleanup: also remove the still-active exclude override on LEAVE_NO_PAY_DEDUCT so later
    // sections of this file that reuse $pulledRunId/$employeeFullId see plain computed lines.
    $runModel->lineOverrideRemove($pulledRunId, $compId, $employeeFullId, 'LEAVE_NO_PAY_DEDUCT', $adminUserId, true);

    echo "=== Line overrides: guards ===\n";
    $overrideOnNonSyncRunRes = $runModel->lineOverrideSave($runId, $compId, $employeeFullId, 'LATE_DEDUCT', 'override_amount', 5.00, null, $adminUserId, true);
    check('lineOverrideSave() rejected on a non-sync (cycle-based) run', $overrideOnNonSyncRunRes['status'], false);
    $overrideBadActionRes = $runModel->lineOverrideSave($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', 'not_a_real_action', null, null, $adminUserId, true);
    check('lineOverrideSave() rejected with an invalid action', $overrideBadActionRes['status'], false);
    $overrideMissingAmountRes = $runModel->lineOverrideSave($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', 'override_amount', null, null, $adminUserId, true);
    check('lineOverrideSave() rejected with override_amount action but no amount', $overrideMissingAmountRes['status'], false);

    echo "=== Audit log: line_override_save / line_override_remove entries ===\n";
    $pulledAuditLogAfterOverrides = $runModel->getAuditLog($pulledRunId, $compId);
    $overrideSaveEntries = array_values(array_filter($pulledAuditLogAfterOverrides, fn($a) => $a['action'] === 'line_override_save'));
    $overrideRemoveEntries = array_values(array_filter($pulledAuditLogAfterOverrides, fn($a) => $a['action'] === 'line_override_remove'));
    check('2 line_override_save entries logged (LATE_DEDUCT override + LEAVE_NO_PAY_DEDUCT exclude)', count($overrideSaveEntries), 2);
    check('2 line_override_remove entries logged (LATE_DEDUCT reset + LEAVE_NO_PAY_DEDUCT cleanup)', count($overrideRemoveEntries), 2);

    // ---------- Attendance data overrides (2026-08-21, explicit request: "ต้องการแก้ตัวเลขดิบที่ Sync
    // มา ไม่ใช่แค่ยอดเงิน") -- distinct from the $-amount line overrides just above: corrects the RAW
    // number Origami sent so the deduction recomputes from it. Reuses $pulledRunId/$employeeFullId,
    // whose raw late_mins=15 (see the "Sync-derived lines" fixture far above) computes to the plain
    // 31.25 LATE_DEDUCT figure at this point in the file (both overrides from the section above were
    // cleaned up). ----------
    echo "=== Attendance data overrides: correcting raw late_mins recomputes the deduction ===\n";
    $attOverrideRes = $runModel->attendanceOverrideSave($pulledRunId, $compId, $employeeFullId, ['late_mins' => 5], 'HR corrected the timesheet', $adminUserId, true);
    checkTrue('attendanceOverrideSave() succeeds' . (empty($attOverrideRes['status']) ? " ({$attOverrideRes['message']})" : ''), $attOverrideRes['status']);
    $afterAttOverrideDetails = $runModel->getDetails($pulledRunId, $compId);
    $lateLineAfterAttOverride = current(array_filter($afterAttOverrideDetails[0]['deduction_breakdown'], fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('LATE_DEDUCT recomputed from the corrected 5 minutes: (125/60)*5 = 10.42', round((float)($lateLineAfterAttOverride['amount'] ?? 0), 2), 10.42);

    echo "=== Attendance data overrides: attendanceDataForEmployee() returns synced + override side by side ===\n";
    $attData = $runModel->attendanceDataForEmployee($compId, $pulledRunId, $employeeFullId);
    check('synced late_mins reflects the original Origami value (15), unaffected by the override', $attData['synced']['late_mins'] ?? null, 15.0);
    check('override late_mins reflects the correction (5)', $attData['override']['late_mins'] ?? null, 5.0);
    check('an untouched field (absent_days) has a null override', $attData['override']['absent_days'], null);

    echo "=== Attendance data overrides compose with the \$-amount line override (both active on the same line at once) ===\n";
    $attPlusLineOverrideRes = $runModel->lineOverrideSave($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', 'override_amount', 2.00, 'extra discretionary reduction', $adminUserId, true);
    checkTrue('$-amount override on top of an already-corrected attendance figure succeeds', $attPlusLineOverrideRes['status']);
    $afterBothDetails = $runModel->getDetails($pulledRunId, $compId);
    $lateLineAfterBoth = current(array_filter($afterBothDetails[0]['deduction_breakdown'], fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('the $-amount override (2.00) wins over the attendance-corrected 10.42 -- both mechanisms compose', (float)($lateLineAfterBoth['amount'] ?? null), 2.0);
    $runModel->lineOverrideRemove($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', $adminUserId, true);

    echo "=== Attendance data overrides: full-replace semantics (omitted field clears any previous override for it) ===\n";
    $attOverrideReplaceRes = $runModel->attendanceOverrideSave($pulledRunId, $compId, $employeeFullId, ['absent_days' => 0.5], null, $adminUserId, true);
    checkTrue('re-saving with a different field set succeeds', $attOverrideReplaceRes['status']);
    $attDataAfterReplace = $runModel->attendanceDataForEmployee($compId, $pulledRunId, $employeeFullId);
    check('late_mins override cleared (full-replace, not a partial patch)', $attDataAfterReplace['override']['late_mins'], null);
    check('absent_days override now set to 0.5', $attDataAfterReplace['override']['absent_days'] ?? null, 0.5);

    echo "=== Attendance data overrides: Reset All reverts every field ===\n";
    $attRemoveRes = $runModel->attendanceOverrideRemove($pulledRunId, $compId, $employeeFullId, $adminUserId, true);
    checkTrue('attendanceOverrideRemove() succeeds' . (empty($attRemoveRes['status']) ? " ({$attRemoveRes['message']})" : ''), $attRemoveRes['status']);
    $attDataAfterRemove = $runModel->attendanceDataForEmployee($compId, $pulledRunId, $employeeFullId);
    check('every override field is null after Reset All', $attDataAfterRemove['override']['late_mins'], null);
    check('absent_days override also cleared', $attDataAfterRemove['override']['absent_days'], null);
    $afterAttRemoveDetails = $runModel->getDetails($pulledRunId, $compId);
    $lateLineAfterAttRemove = current(array_filter($afterAttRemoveDetails[0]['deduction_breakdown'], fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('LATE_DEDUCT back to the original computed 31.25 after Reset All', (float)($lateLineAfterAttRemove['amount'] ?? null), 31.25);

    echo "=== Attendance data overrides: guards ===\n";
    $attOverrideOnNonSyncRunRes = $runModel->attendanceOverrideSave($runId, $compId, $employeeFullId, ['late_mins' => 5], null, $adminUserId, true);
    check('attendanceOverrideSave() rejected on a non-sync (cycle-based) run', $attOverrideOnNonSyncRunRes['status'], false);
    $attOverrideNegativeRes = $runModel->attendanceOverrideSave($pulledRunId, $compId, $employeeFullId, ['late_mins' => -5], null, $adminUserId, true);
    check('attendanceOverrideSave() rejected with a negative value', $attOverrideNegativeRes['status'], false);

    echo "=== Audit log: attendance_override_save / attendance_override_remove entries ===\n";
    $pulledAuditLogAfterAttOverrides = $runModel->getAuditLog($pulledRunId, $compId);
    $attSaveEntries = array_values(array_filter($pulledAuditLogAfterAttOverrides, fn($a) => $a['action'] === 'attendance_override_save'));
    $attRemoveEntries = array_values(array_filter($pulledAuditLogAfterAttOverrides, fn($a) => $a['action'] === 'attendance_override_remove'));
    check('2 attendance_override_save entries logged (initial correction + the full-replace re-save)', count($attSaveEntries), 2);
    check('1 attendance_override_remove entry logged (Reset All)', count($attRemoveEntries), 1);

    // ---------- Employee-to-employee transfer deductions (2026-08-21, explicit request: "หักเพื่อ
    // ไปจ่ายให้ใคร โดยเลือกพนักงานได้ว่าจะหักของคนนี้ไปให้คนนี้") -- a deduction line with
    // payee_employee_id set becomes a real taxable earning line for the payee, in the SAME run. ----------
    echo "=== Transfer deduction: payee IS part of this run -> credited as a real earning line ===\n";
    $insSyncItem->execute([':process_id' => $syncProcessId, ':employee_id' => $employeeMidId, ':payroll_code' => 'PULL_MAPPED_PAYEE', ':mapping_status' => 'mapped']);
    $baselineRes = $runModel->recalculate($pulledRunId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds after mapping the payee employee into the same sync process' . (empty($baselineRes['status']) ? " ({$baselineRes['message']})" : ''), $baselineRes['status']);
    check('run now has 2 employees (both mapped in the sync payload)', $baselineRes['employee_count'] ?? null, 2);
    $baselineDetails = $runModel->getDetails($pulledRunId, $compId);
    $toRowBaseline = current(array_filter($baselineDetails, fn($d) => (int)$d['employee_id'] === $employeeMidId));
    $baselineGross = (float)$toRowBaseline['gross_amount'];

    $transferManualRes = $runModel->addManualLine($pulledRunId, $compId, $employeeFullId, null, 800.00, $adminUserId, true, 'Loan repayment to colleague', 'Loan Repayment', 'deduction', $employeeMidId);
    checkTrue('addManualLine() with a payee_employee_id succeeds' . (empty($transferManualRes['status']) ? " ({$transferManualRes['message']})" : ''), $transferManualRes['status']);
    $afterTransferDetails = $runModel->getDetails($pulledRunId, $compId);
    $fromRow = current(array_filter($afterTransferDetails, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    $toRow = current(array_filter($afterTransferDetails, fn($d) => (int)$d['employee_id'] === $employeeMidId));
    $transferDeductionLine = current(array_filter($fromRow['deduction_breakdown'], fn($l) => $l['code'] === 'CUSTOM:Loan Repayment'));
    checkTrue('the deduction line on the FROM employee carries the payee employee_no for display', !empty($transferDeductionLine['payee_employee_no'] ?? null));
    $transferEarningLine = current(array_filter($toRow['earning_breakdown'], fn($l) => $l['code'] === 'TRANSFER_IN'));
    checkTrue('TRANSFER_IN earning line present on the payee', $transferEarningLine !== false);
    check('TRANSFER_IN amount matches the deducted amount exactly', (float)($transferEarningLine['amount'] ?? null), 800.0);
    checkTrue('TRANSFER_IN name mentions the FROM employee', strpos($transferEarningLine['name_en'] ?? '', 'Transfer from') === 0);
    check('payee gross_amount increased by exactly the transferred amount (taxable, added to gross like any other earning)', round((float)$toRow['gross_amount'] - $baselineGross, 2), 800.0);
    checkTrue('payee calc_status stays calculated (a valid transfer is not an error)', $toRow['calc_status'] === 'calculated');

    echo "=== Transfer deduction: payee is NOT part of this run -> deduction still happens, error surfaced ===\n";
    $transferNoPayeeInRunRes = $runModel->addManualLine($pulledRunId, $compId, $employeeFullId, null, 200.00, $adminUserId, true, null, 'Orphan Transfer', 'deduction', $employeeOptOutId);
    checkTrue('addManualLine() still succeeds even though the payee is not part of this run (the deduction itself is still valid)', $transferNoPayeeInRunRes['status']);
    $afterOrphanDetails = $runModel->getDetails($pulledRunId, $compId);
    $fromRowOrphan = current(array_filter($afterOrphanDetails, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    checkTrue('the deduction line still applies to the FROM employee', current(array_filter($fromRowOrphan['deduction_breakdown'], fn($l) => $l['code'] === 'CUSTOM:Orphan Transfer')) !== false);
    checkTrue('calc_errors surfaces transfer_payee_not_in_run instead of silently dropping the transfer', strpos((string)($fromRowOrphan['calc_errors'] ?? ''), 'transfer_payee_not_in_run:CUSTOM:Orphan Transfer') !== false);

    echo "=== EmployeeEarningDeductionModel::save() payee_employee_id guards (standing assignment) ===\n";
    // A deduction-type catalog item, distinct from $pedTypeId (an earning) -- payee_employee_id is
    // only meaningful on a deduction, so the validation guards below need a real deduction item to
    // actually exercise that branch rather than being silently no-op'd by the earning short-circuit.
    $deductionPedRes = $pedTypeModel->save($compId, [
        'item_code' => 'TESTDEDUCT' . rand(100, 999),
        'item_name_th' => 'หักทดสอบ', 'item_name_en' => 'Test Deduction',
        'item_type' => 'deduction', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 100,
        'tax_deduction_impact' => 'after_tax', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: deduction PED type created' . (empty($deductionPedRes['status']) ? " ({$deductionPedRes['message']})" : ''), $deductionPedRes['status']);
    $deductionPedTypeId = $deductionPedRes['id'];

    $eedModelForPayee = new EmployeeEarningDeductionModel();
    $eedSelfPayeeRes = $eedModelForPayee->save($employeeFullId, $compId, [
        'ped_type_id' => $deductionPedTypeId, 'total_installments' => 1, 'amount_mode' => 'even_split',
        'total_amount' => 100, 'effective_date' => $periodStart, 'payee_employee_id' => $employeeFullId,
    ], $adminUserId);
    check('save() rejects an employee being their own transfer payee', $eedSelfPayeeRes['status'], false);
    $eedForeignPayeeRes = $eedModelForPayee->save($employeeFullId, $compId, [
        'ped_type_id' => $deductionPedTypeId, 'total_installments' => 1, 'amount_mode' => 'even_split',
        'total_amount' => 100, 'effective_date' => $periodStart, 'payee_employee_id' => 999999,
    ], $adminUserId);
    check('save() rejects a payee_employee_id that does not belong to this company', $eedForeignPayeeRes['status'], false);
    $eedValidPayeeRes = $eedModelForPayee->save($employeeFullId, $compId, [
        'ped_type_id' => $deductionPedTypeId, 'total_installments' => 1, 'amount_mode' => 'even_split',
        'total_amount' => 100, 'effective_date' => $periodStart, 'payee_employee_id' => $employeeMidId,
    ], $adminUserId);
    checkTrue('save() accepts a valid same-company payee on a deduction item' . (empty($eedValidPayeeRes['status']) ? " ({$eedValidPayeeRes['message']})" : ''), $eedValidPayeeRes['status']);
    $eedWithPayee = $eedModelForPayee->get((int)$eedValidPayeeRes['id'], $compId);
    check('payee_employee_id round-trips on get()', (int)($eedWithPayee['payee_employee_id'] ?? 0), $employeeMidId);
    check('payee_employee_no resolved for display', $eedWithPayee['payee_employee_no'] ?? null, $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeMidId}")->fetchColumn());
    // Cleanup: this standing assignment would otherwise flow into every later recalculate($runId)
    // call further down in this file (its effective_date falls inside $runId's own period, and both
    // employeeFullId/employeeMidId are already members of $runId), silently adding an unrelated
    // transfer deduction/credit on top of totals those later sections assert exact figures for.
    $deleteEedWithPayeeRes = $eedModelForPayee->delete((int)$eedValidPayeeRes['id'], $compId, $employeeFullId, $adminUserId);
    checkTrue('cleanup: standing payee assignment deleted so it does not leak into later recalculate($runId) totals', $deleteEedWithPayeeRes['status']);

    // ---------- Eligibility branching: a genuine off-cycle run (no cycle, no sync) has NO
    // automatic membership at all -- only employees explicitly Joined are included. ----------
    echo "=== Eligibility: off-cycle run has no employees until Joined ===\n";
    $offCalcRes = $runModel->recalculate($offCycleRunId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds on the off-cycle run (even with zero employees)' . (empty($offCalcRes['status']) ? " ({$offCalcRes['message']})" : ''), $offCalcRes['status']);
    check('off-cycle run has 0 employees before anyone is Joined', $offCalcRes['employee_count'], 0);

    echo "=== joinEmployees() / removeManualEmployee() guards ===\n";
    // employeeOptOutId is already a normal date-range member of $runId, not currently excluded --
    // 2026-08-21: joinEmployees() on a cycle-based run now means "re-include a removed employee",
    // never "add someone arbitrary", so this is rejected (an empty intersection against the
    // exclusion list), not because cycle-based joins are blocked outright anymore (see below).
    $joinOnCycleRes = $runModel->joinEmployees($runId, $compId, [$employeeOptOutId], $adminUserId, true);
    check('joinEmployees() rejected on a cycle-based run for an employee who is not currently excluded', $joinOnCycleRes['status'], false);
    $joinEmptyRes = $runModel->joinEmployees($offCycleRunId, $compId, [], $adminUserId, true);
    check('joinEmployees() rejected with an empty employee list', $joinEmptyRes['status'], false);
    $joinForeignRes = $runModel->joinEmployees($offCycleRunId, 999999, [$employeeOptOutId], $adminUserId, true);
    check('joinEmployees() rejected for a run that does not belong to the given company', $joinForeignRes['status'], false);

    echo "=== joinEmployees() / removeManualEmployee() ===\n";
    $joinRes = $runModel->joinEmployees($offCycleRunId, $compId, [$employeeOptOutId, $employeeLeaverId, $employeeOptOutId], $adminUserId, true);
    checkTrue('joinEmployees() succeeds and recalculates in one call' . (empty($joinRes['status']) ? " ({$joinRes['message']})" : ''), $joinRes['status']);
    check('joined_count reports 2 (duplicate id in the request de-duplicated)', $joinRes['joined_count'] ?? null, 2);
    check('employee_count reflects both newly-joined employees', $joinRes['employee_count'] ?? null, 2);

    $joinAgainRes = $runModel->joinEmployees($offCycleRunId, $compId, [$employeeOptOutId], $adminUserId, true);
    check('joining an already-joined employee again is a harmless no-op (INSERT IGNORE)', $joinAgainRes['employee_count'] ?? null, 2);

    $removeRes = $runModel->removeManualEmployee($offCycleRunId, $compId, $employeeLeaverId, $adminUserId, true);
    checkTrue('removeManualEmployee() succeeds' . (empty($removeRes['status']) ? " ({$removeRes['message']})" : ''), $removeRes['status']);
    check('employee_count drops to 1 after removing one joined employee', $removeRes['employee_count'] ?? null, 1);
    $offCycleDetailsAfterRemove = $runModel->getDetails($offCycleRunId, $compId);
    check('the remaining detail row is the employee who was NOT removed', (int)($offCycleDetailsAfterRemove[0]['employee_id'] ?? 0), $employeeOptOutId);

    echo "=== manualEmployeeOptions() (Join Employees picker) ===\n";
    // Search-scoped rather than a blind page window -- this shared dev-DB company can have many
    // other real employees sorted ahead of this fixture's TEST_* employee_no values, which would
    // otherwise push it off a fixed-size page and produce a false failure unrelated to the actual
    // exclusion logic being tested here.
    $optOutEmployeeNo = $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeOptOutId}")->fetchColumn();
    $fullEmployeeNo = $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeFullId}")->fetchColumn();
    $optionsExcludedSearch = $runModel->manualEmployeeOptions($compId, $offCycleRunId, 0, 50, [], $optOutEmployeeNo, 'en');
    checkTrue('manualEmployeeOptions() excludes the already-joined employee even when searched for by name/code', empty($optionsExcludedSearch['data']));
    $optionsIncludedSearch = $runModel->manualEmployeeOptions($compId, $offCycleRunId, 0, 50, [], $fullEmployeeNo, 'en');
    checkTrue('manualEmployeeOptions() still includes an employee nobody has joined yet', in_array($employeeFullId, array_map('intval', array_column($optionsIncludedSearch['data'], 'id'))));

    // 2026-08-22, explicit request ("ตรง Join Employee อยากให้เพิ่ม Filter รอบเงินเดือนได้ด้วย") --
    // filters by the employee's own standing payroll cycle (employees.cycle_id), not the run's own.
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cycle_id WHERE id = :id")->execute([':cycle_id' => $cycleId, ':id' => $employeeFullId]);
    $optionsCycleMatch = $runModel->manualEmployeeOptions($compId, $offCycleRunId, 0, 50, ['emp_cycle_id' => $cycleId], $fullEmployeeNo, 'en');
    checkTrue('manualEmployeeOptions() emp_cycle_id filter includes an employee on that cycle', in_array($employeeFullId, array_map('intval', array_column($optionsCycleMatch['data'], 'id'))));
    $optionsCycleMismatch = $runModel->manualEmployeeOptions($compId, $offCycleRunId, 0, 50, ['emp_cycle_id' => 999999], $fullEmployeeNo, 'en');
    checkTrue('manualEmployeeOptions() emp_cycle_id filter excludes an employee on a different cycle', empty($optionsCycleMismatch['data']));
    $expectedCycleName = $pdo->query("SELECT cycle_name FROM payroll_cycles WHERE id = {$cycleId}")->fetchColumn();
    check("manualEmployeeOptions() data includes the employee's cycle_name for display", $optionsCycleMatch['data'][0]['cycle_name'] ?? null, $expectedCycleName);
    $pdo->prepare("UPDATE `employees` SET cycle_id = NULL WHERE id = :id")->execute([':id' => $employeeFullId]);

    // ---------- Manual employee add on a Sync run + Sync/Manual badge (2026-08-21, explicit
    // request: "เพิ่มพนักงานเข้ามาในรอบได้แบบ Manual...ถ้าเป็นการ Sync...ต้องมีสัญลักษณ์ว่า ใคร Sync มา
    // เพิ่มเข้ามาแบบ Manual") -- reuses $pulledRunId, which at this point has 2 sync-mapped employees
    // ($employeeFullId, $employeeMidId -- see the Transfer Deductions section above). ----------
    echo "=== manualEmployeeOptions() also excludes an already-synced employee on a sync-based run ===\n";
    $pickerOnPulledRun = $runModel->manualEmployeeOptions($compId, $pulledRunId, 0, 50, [], $fullEmployeeNo, 'en');
    checkTrue('the already-synced employee is excluded from the Join Employees picker on this sync-based run', empty($pickerOnPulledRun['data']));

    echo "=== joinEmployees()/removeManualEmployee() now ALSO work on a sync-based run ===\n";
    $joinOnPulledRes = $runModel->joinEmployees($pulledRunId, $compId, [$employeeOptOutId], $adminUserId, true);
    checkTrue('joinEmployees() now succeeds on a sync-based (Pending-Pull) run' . (empty($joinOnPulledRes['status']) ? " ({$joinOnPulledRes['message']})" : ''), $joinOnPulledRes['status']);
    $pulledDetailsAfterJoin = $runModel->getDetails($pulledRunId, $compId);
    $manualRowOnPulled = current(array_filter($pulledDetailsAfterJoin, fn($d) => (int)$d['employee_id'] === $employeeOptOutId));
    checkTrue('the manually-joined employee is now part of the sync-based run', $manualRowOnPulled !== false);
    check("the manually-joined employee's data_source is 'manual'", $manualRowOnPulled['data_source'] ?? null, 'manual');
    $syncedRowOnPulled = current(array_filter($pulledDetailsAfterJoin, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    check("the originally-synced employee's data_source stays 'sync' (not disturbed by the manual join)", $syncedRowOnPulled['data_source'] ?? null, 'sync');

    echo "=== The manually-joined employee gets paid via the EXISTING Manage Items Add-Item form -- no new form needed ===\n";
    $grossBeforeManualLine = (float)$manualRowOnPulled['gross_amount'];
    $manualEmpLineRes = $runModel->addManualLine($pulledRunId, $compId, $employeeOptOutId, null, 1000.00, $adminUserId, true, 'Manually entered income', 'Manual Income', 'earning');
    checkTrue('addManualLine() succeeds for the manually-added employee' . (empty($manualEmpLineRes['status']) ? " ({$manualEmpLineRes['message']})" : ''), $manualEmpLineRes['status']);
    $pulledDetailsAfterManualLine = $runModel->getDetails($pulledRunId, $compId);
    $manualRowAfterLine = current(array_filter($pulledDetailsAfterManualLine, fn($d) => (int)$d['employee_id'] === $employeeOptOutId));
    checkTrue('the manual earning line appears in their own breakdown', current(array_filter($manualRowAfterLine['earning_breakdown'], fn($l) => $l['code'] === 'CUSTOM:Manual Income')) !== false);
    check('gross_amount increased by exactly the manually-entered income (1000)', round((float)$manualRowAfterLine['gross_amount'] - $grossBeforeManualLine, 2), 1000.0);

    echo "=== rawSyncDataForEmployee(): full raw row for a synced employee, null for a manually-added one or a non-sync run ===\n";
    $rawSyncData = $runModel->rawSyncDataForEmployee($compId, $pulledRunId, $employeeFullId);
    checkTrue('rawSyncDataForEmployee() returns data for the genuinely-synced employee', $rawSyncData !== null);
    check('raw payroll_code matches the fixture', $rawSyncData['payroll_code'] ?? null, 'PULL_MAPPED');
    checkTrue('raw item_values is decoded to an array', is_array($rawSyncData['item_values'] ?? null));
    check('rawSyncDataForEmployee() returns null for the manually-added employee (no sync row exists for them)', $runModel->rawSyncDataForEmployee($compId, $pulledRunId, $employeeOptOutId), null);
    check('rawSyncDataForEmployee() returns null on a non-sync (cycle-based) run', $runModel->rawSyncDataForEmployee($compId, $runId, $employeeFullId), null);

    echo "=== removeManualEmployee() also works on a sync-based run, only for the manually-added row ===\n";
    $removeManualOnPulledRes = $runModel->removeManualEmployee($pulledRunId, $compId, $employeeOptOutId, $adminUserId, true);
    checkTrue('removeManualEmployee() succeeds for the manually-added employee on the sync-based run' . (empty($removeManualOnPulledRes['status']) ? " ({$removeManualOnPulledRes['message']})" : ''), $removeManualOnPulledRes['status']);
    $pulledDetailsAfterManualRemove = $runModel->getDetails($pulledRunId, $compId);
    checkTrue('the manually-added employee is gone after removal', current(array_filter($pulledDetailsAfterManualRemove, fn($d) => (int)$d['employee_id'] === $employeeOptOutId)) === false);
    checkTrue('the genuinely-synced employee is still present (removal only affects the manual roster)', current(array_filter($pulledDetailsAfterManualRemove, fn($d) => (int)$d['employee_id'] === $employeeFullId)) !== false);

    echo "=== Per-run tax/SSO exemption (2026-08-21, explicit request: \"จัดการได้ว่า คนนี้ไม่ต้องคำนวณภาษี ไม่นำส่งประกันสังคมในรอบนี้\") ===\n";
    $beforeExemptionDetail = array_values(array_filter($runModel->getDetails($pulledRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeFullId))[0] ?? [];
    $ssoBeforeExemption = (float)((array_values(array_filter($beforeExemptionDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    checkTrue('before exemption: employee has a real (nonzero) SSO deduction on the sync-based run', $ssoBeforeExemption > 0);

    $defaultExemption = $runModel->getEmployeeExemption($pulledRunId, $compId, $employeeFullId);
    check('getEmployeeExemption() returns zeroed defaults before anything is saved', $defaultExemption, ['exempt_tax' => false, 'exempt_sso' => false, 'note' => null]);

    $exemptionSaveRes = $runModel->saveEmployeeExemption($pulledRunId, $compId, $employeeFullId, true, true, 'requested by employee', $adminUserId, true);
    checkTrue('saveEmployeeExemption() succeeds' . (empty($exemptionSaveRes['status']) ? " ({$exemptionSaveRes['message']})" : ''), $exemptionSaveRes['status']);
    $afterExemptionDetail = array_values(array_filter($runModel->getDetails($pulledRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeFullId))[0] ?? [];
    $ssoAfterExemption = (float)((array_values(array_filter($afterExemptionDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    check('after exempt_sso=true: SSO deduction is zeroed', $ssoAfterExemption, 0.0);
    $pitAfterExemption = array_values(array_filter($afterExemptionDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PIT'))[0] ?? [];
    check('after exempt_tax=true: TH_PIT is zeroed and flagged employee_tax_exempt (same engine note as the permanent tax_exempt flag)', [(float)($pitAfterExemption['employee_amount'] ?? -1), $pitAfterExemption['note'] ?? null], [0.0, 'employee_tax_exempt']);

    $savedExemption = $runModel->getEmployeeExemption($pulledRunId, $compId, $employeeFullId);
    check('getEmployeeExemption() reflects the saved row', $savedExemption, ['exempt_tax' => true, 'exempt_sso' => true, 'note' => 'requested by employee']);

    $exemptionClearRes = $runModel->saveEmployeeExemption($pulledRunId, $compId, $employeeFullId, false, false, null, $adminUserId, true);
    checkTrue('saveEmployeeExemption() with both flags false clears the row (deletes rather than keeping an all-zero row)' . (empty($exemptionClearRes['status']) ? " ({$exemptionClearRes['message']})" : ''), $exemptionClearRes['status']);
    $afterClearDetail = array_values(array_filter($runModel->getDetails($pulledRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeFullId))[0] ?? [];
    $ssoAfterClear = (float)((array_values(array_filter($afterClearDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    check('after clearing the exemption: SSO deduction is back to the real computed amount', $ssoAfterClear, $ssoBeforeExemption);

    echo "=== Exemption is per-run only -- a different run for the same employee is unaffected ===\n";
    $reExemptRes = $runModel->saveEmployeeExemption($pulledRunId, $compId, $employeeFullId, true, false, null, $adminUserId, true);
    checkTrue('re-applying exempt_tax=true on the sync-based run succeeds' . (empty($reExemptRes['status']) ? " ({$reExemptRes['message']})" : ''), $reExemptRes['status']);
    // $runId (the cycle-based run) is recalculated and asserted for real SSO/PIT amounts for this
    // same $employeeFullId later in this file ("Per-employee SSO/PVD enrollment fix" section) --
    // that assertion passing with a nonzero SSO/PIT for $employeeFullId on $runId, despite the
    // tax exemption saved here being still active on $pulledRunId, IS the proof this is scoped
    // per-run and never leaks onto another run for the same employee.

    echo "=== removeManualEmployee() removes a genuinely-synced row too, and it does not come back (2026-08-21: universal remove) ===\n";
    $removeSyncedRes = $runModel->removeManualEmployee($pulledRunId, $compId, $employeeFullId, $adminUserId, true);
    checkTrue('removeManualEmployee() succeeds for a genuinely-synced row' . (empty($removeSyncedRes['status']) ? " ({$removeSyncedRes['message']})" : ''), $removeSyncedRes['status']);
    $pulledDetailsAfterSyncedRemove = $runModel->getDetails($pulledRunId, $compId);
    checkTrue('the synced employee is gone right after removal', current(array_filter($pulledDetailsAfterSyncedRemove, fn($d) => (int)$d['employee_id'] === $employeeFullId)) === false);

    $runModel->recalculate($pulledRunId, $compId, $adminUserId, true);
    $pulledDetailsAfterExtraRecalc = $runModel->getDetails($pulledRunId, $compId);
    checkTrue('the removed synced employee does NOT come back on a later recalculate() (payroll_sync_items alone would otherwise re-pull them)', current(array_filter($pulledDetailsAfterExtraRecalc, fn($d) => (int)$d['employee_id'] === $employeeFullId)) === false);

    $pickerAfterSyncedExclude = $runModel->manualEmployeeOptions($compId, $pulledRunId, 0, 50, [], $fullEmployeeNo, 'en');
    checkTrue('manualEmployeeOptions() surfaces the excluded synced employee back into the Join Employees picker', in_array($employeeFullId, array_map('intval', array_column($pickerAfterSyncedExclude['data'], 'id'))));

    $rejoinSyncedRes = $runModel->joinEmployees($pulledRunId, $compId, [$employeeFullId], $adminUserId, true);
    checkTrue('joinEmployees() re-includes the previously-removed synced employee' . (empty($rejoinSyncedRes['status']) ? " ({$rejoinSyncedRes['message']})" : ''), $rejoinSyncedRes['status']);
    $pulledDetailsAfterSyncedRejoin = $runModel->getDetails($pulledRunId, $compId);
    $rejoinedRow = current(array_filter($pulledDetailsAfterSyncedRejoin, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    checkTrue('the re-included employee is back', $rejoinedRow !== false);
    check("the re-included employee's data_source resolves back to 'sync' (their real sync row was never touched, only the exclusion)", $rejoinedRow['data_source'] ?? null, 'sync');

    // ---------- "Incentive/Other Payment" runs (2026-08-19): no base salary, only manually-picked
    // earning/deduction items per employee, statutory computed only when the admin opts in. ----------
    echo "=== Incentive/Other Payment run creation guards ===\n";
    $incentiveOnCycleRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_purpose' => 'incentive', 'run_name' => 'INCENTIVE_BAD_CYCLE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +7 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +7 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +7 months')->format('Y-m-d'),
    ], $adminUserId, true);
    check('run_purpose=incentive rejected when a cycle is selected', $incentiveOnCycleRes['status'], false);

    $payrollOffCycleRes = $runModel->create($compId, [
        'run_purpose' => 'payroll', 'compute_statutory' => 0, 'run_name' => 'PAYROLL_FORCE_STAT_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +8 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +8 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +8 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('a normal payroll off-cycle run still succeeds' . (empty($payrollOffCycleRes['status']) ? " ({$payrollOffCycleRes['message']})" : ''), $payrollOffCycleRes['status']);
    $payrollOffCycleRow = $pdo->query("SELECT run_purpose, compute_statutory FROM payroll_runs WHERE id = {$payrollOffCycleRes['id']}")->fetch(PDO::FETCH_ASSOC);
    check('run_purpose stored as payroll (the default)', $payrollOffCycleRow['run_purpose'] ?? null, 'payroll');
    checkTrue('compute_statutory is forced to 1 for a payroll run even when the request tried to send 0', (int)($payrollOffCycleRow['compute_statutory'] ?? 0) === 1);

    echo "=== Incentive/Other Payment run: no base salary, manual lines only ===\n";
    $incentiveRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 0, 'run_name' => 'INCENTIVE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +9 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('creating an incentive run succeeds' . (empty($incentiveRes['status']) ? " ({$incentiveRes['message']})" : ''), $incentiveRes['status']);
    $incentiveRunId = $incentiveRes['id'];
    $incentiveRow = $pdo->query("SELECT run_purpose, compute_statutory FROM payroll_runs WHERE id = {$incentiveRunId}")->fetch(PDO::FETCH_ASSOC);
    check('run_purpose stored as incentive', $incentiveRow['run_purpose'] ?? null, 'incentive');
    check('compute_statutory stored as the requested 0 (opted out)', (int)($incentiveRow['compute_statutory'] ?? -1), 0);

    $incentiveJoinRes = $runModel->joinEmployees($incentiveRunId, $compId, [$employeeOptOutId, $employeeLeaverId], $adminUserId, true);
    checkTrue('joining employees to an incentive run succeeds' . (empty($incentiveJoinRes['status']) ? " ({$incentiveJoinRes['message']})" : ''), $incentiveJoinRes['status']);
    check('employee_count is 2 after joining', $incentiveJoinRes['employee_count'] ?? null, 2);

    $incentiveDetailsNoLines = $runModel->getDetails($incentiveRunId, $compId);
    checkTrue('every joined employee has base_salary_amount = 0 (incentive runs never carry base salary)', array_reduce($incentiveDetailsNoLines, fn($carry, $d) => $carry && (float)$d['base_salary_amount'] === 0.0, true));
    checkTrue('every joined employee is flagged no_manual_lines before anything is picked', array_reduce($incentiveDetailsNoLines, fn($carry, $d) => $carry && strpos((string)$d['calc_errors'], 'no_manual_lines') !== false, true));

    $otPedTypeId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'OT' AND deleted_at IS NULL")->fetchColumn();
    $loanPedTypeId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'LOAN_REPAY' AND deleted_at IS NULL")->fetchColumn();
    checkTrue('fixture: OT default item exists for this company (seeded earlier)', $otPedTypeId > 0);
    checkTrue('fixture: LOAN_REPAY default item exists for this company (seeded earlier)', $loanPedTypeId > 0);

    echo "=== addManualLine() / removeManualLine() guards ===\n";
    $addLineOnCycleRes = $runModel->addManualLine($runId, $compId, $employeeFullId, $otPedTypeId, 1000, $adminUserId, true);
    check('addManualLine() rejected on a cycle-based run', $addLineOnCycleRes['status'], false);
    $addLineOnPayrollOffCycleRes = $runModel->addManualLine((int)$payrollOffCycleRes['id'], $compId, $employeeFullId, $otPedTypeId, 1000, $adminUserId, true);
    check('addManualLine() rejected on a payroll-purpose off-cycle run (not incentive)', $addLineOnPayrollOffCycleRes['status'], false);
    $addLineZeroRes = $runModel->addManualLine($incentiveRunId, $compId, $employeeOptOutId, $otPedTypeId, 0, $adminUserId, true);
    check('addManualLine() rejected with amount <= 0', $addLineZeroRes['status'], false);
    $insDeletedPed = $pdo->prepare("INSERT INTO payroll_earning_deduction_types
        (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, tax_treatment, status, deleted_at, created_by)
        VALUES (:comp_id, :code, 'ทดสอบลบ', 'Deleted Test', 'earning', 'manual_entry', 'taxable', 'deleted', NOW(), :created_by)");
    $insDeletedPed->execute([':comp_id' => $compId, ':code' => 'DEL_TEST_' . rand(1000, 9999), ':created_by' => $adminUserId]);
    $deletedPedTypeId = (int)$pdo->lastInsertId();
    $addLineDeletedPedRes = $runModel->addManualLine($incentiveRunId, $compId, $employeeOptOutId, $deletedPedTypeId, 1000, $adminUserId, true);
    check('addManualLine() rejected for a soft-deleted item', $addLineDeletedPedRes['status'], false);

    echo "=== addManualLine() ===\n";
    $addLine1Res = $runModel->addManualLine($incentiveRunId, $compId, $employeeOptOutId, $otPedTypeId, 5000, $adminUserId, true);
    checkTrue('adding an earning line for employee 1 succeeds' . (empty($addLine1Res['status']) ? " ({$addLine1Res['message']})" : ''), $addLine1Res['status']);
    $addLine2Res = $runModel->addManualLine($incentiveRunId, $compId, $employeeLeaverId, $otPedTypeId, 3000, $adminUserId, true);
    checkTrue('adding an earning line for employee 2 succeeds' . (empty($addLine2Res['status']) ? " ({$addLine2Res['message']})" : ''), $addLine2Res['status']);
    $addLine3Res = $runModel->addManualLine($incentiveRunId, $compId, $employeeOptOutId, $loanPedTypeId, 500, $adminUserId, true);
    checkTrue('adding a deduction line for employee 1 succeeds' . (empty($addLine3Res['status']) ? " ({$addLine3Res['message']})" : ''), $addLine3Res['status']);
    check('employee_count still 2 after adding lines (no employee added/removed)', $addLine3Res['employee_count'] ?? null, 2);

    $incentiveDetailsWithLines = $runModel->getDetails($incentiveRunId, $compId);
    $emp1Detail = null;
    $emp2Detail = null;
    foreach ($incentiveDetailsWithLines as $d) {
        if ((int)$d['employee_id'] === $employeeOptOutId) { $emp1Detail = $d; }
        if ((int)$d['employee_id'] === $employeeLeaverId) { $emp2Detail = $d; }
    }
    checkTrue('employee 1 no longer flagged no_manual_lines', strpos((string)($emp1Detail['calc_errors'] ?? ''), 'no_manual_lines') === false);
    check('employee 1 gross = 5000 (OT earning only, no base salary)', (float)($emp1Detail['gross_amount'] ?? -1), 5000.0);
    check('employee 1 net = 4500 (5000 earning - 500 manual deduction, statutory opted out)', (float)($emp1Detail['net_amount'] ?? -1), 4500.0);
    check('employee 1 statutory_breakdown is empty (compute_statutory=0)', $emp1Detail['statutory_breakdown'] ?? null, []);
    check('employee 1 calc_status is calculated (no more errors)', $emp1Detail['calc_status'] ?? null, 'calculated');
    check('employee 2 gross = 3000 (OT earning only)', (float)($emp2Detail['gross_amount'] ?? -1), 3000.0);
    check('employee 2 prorate_days is null (incentive runs never prorate)', $emp2Detail['prorate_days'], null);

    echo "=== removeManualLine() ===\n";
    $lines = $runModel->manualLinesForEmployee($compId, $incentiveRunId, $employeeOptOutId);
    check('manualLinesForEmployee() returns both of employee 1\'s lines', count($lines), 2);
    $deductionLineId = null;
    foreach ($lines as $l) {
        if ($l['item_type'] === 'deduction') { $deductionLineId = (int)$l['id']; }
    }
    checkTrue('found the deduction line id to remove', $deductionLineId !== null);
    $removeLineRes = $runModel->removeManualLine($incentiveRunId, $compId, $deductionLineId, $adminUserId, true);
    checkTrue('removeManualLine() succeeds' . (empty($removeLineRes['status']) ? " ({$removeLineRes['message']})" : ''), $removeLineRes['status']);
    $emp1DetailAfterRemove = null;
    foreach ($runModel->getDetails($incentiveRunId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeOptOutId) { $emp1DetailAfterRemove = $d; }
    }
    check('employee 1 net = 5000 after removing the deduction line (no more -500)', (float)($emp1DetailAfterRemove['net_amount'] ?? -1), 5000.0);

    echo "=== addManualLine()/removeManualLine() write an audit log entry (2026-08-21, explicit request: \"ต้องเก็บ Log ว่าใครแก้ไขข้อมูลอะไรไปเมื่อไหร่\") ===\n";
    $incentiveAuditLog = $runModel->getAuditLog($incentiveRunId, $compId);
    $addLogEntries = array_values(array_filter($incentiveAuditLog, fn($a) => $a['action'] === 'add_manual_line'));
    $removeLogEntries = array_values(array_filter($incentiveAuditLog, fn($a) => $a['action'] === 'remove_manual_line'));
    check('3 add_manual_line entries logged (one per addManualLine() call above)', count($addLogEntries), 3);
    check('1 remove_manual_line entry logged', count($removeLogEntries), 1);
    checkTrue('add_manual_line note names the employee and item', strpos($addLogEntries[0]['note'] ?? '', 'Employee') === 0 && strpos($addLogEntries[0]['note'] ?? '', 'OT') !== false);
    checkTrue('remove_manual_line note names the employee and item', strpos($removeLogEntries[0]['note'] ?? '', 'Employee') === 0);
    check('audit log entries performed_by is the acting admin user', (int)$addLogEntries[0]['performed_by'], $adminUserId);

    echo "=== Incentive run WITH statutory opted in ===\n";
    $incentiveStatRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 1, 'run_name' => 'INCENTIVE_STAT_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +10 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +10 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +10 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('creating an incentive run with compute_statutory=1 succeeds' . (empty($incentiveStatRes['status']) ? " ({$incentiveStatRes['message']})" : ''), $incentiveStatRes['status']);
    $incentiveStatRunId = $incentiveStatRes['id'];
    $runModel->joinEmployees($incentiveStatRunId, $compId, [$employeeFullId], $adminUserId, true);
    $addStatLineRes = $runModel->addManualLine($incentiveStatRunId, $compId, $employeeFullId, $otPedTypeId, 20000, $adminUserId, true);
    checkTrue('adding a large earning line succeeds' . (empty($addStatLineRes['status']) ? " ({$addStatLineRes['message']})" : ''), $addStatLineRes['status']);
    $statDetail = $runModel->getDetails($incentiveStatRunId, $compId)[0] ?? [];
    checkTrue('statutory_breakdown is NOT empty when compute_statutory=1 (real SSO/tax calc applied to the incentive gross)', !empty($statDetail['statutory_breakdown']));
    checkTrue('net amount is less than gross once statutory is actually withheld', (float)($statDetail['net_amount'] ?? 0) < (float)($statDetail['gross_amount'] ?? 0));

    echo "=== removeManualEmployee() now works on a cycle-based run too (2026-08-21: \"พนักงานทุกคน สามารถลบข้อมูลออกจากรอบได้ ต่อให้ Sync มาจาก Origami เองก็ตาม\") ===\n";
    // Placed here (just before the real "=== Recalculate ===" section below, rather than right
    // after the guards section above) so it doesn't disturb the "addManualLine() rejected on a
    // cycle-based run" assertion's precondition just above -- that assertion relies on $runId
    // never having been recalculated yet at that point (an employee only fails the
    // "not part of the calculated run" check while it's genuinely uncalculated). This round trip
    // fully restores $runId to its original 4-employee membership before the real Recalculate
    // section runs, so nothing downstream of it is affected either.
    $removeOnCycleRes = $runModel->removeManualEmployee($runId, $compId, $employeeFullId, $adminUserId, true);
    checkTrue('removeManualEmployee() succeeds on a cycle-based run' . (empty($removeOnCycleRes['status']) ? " ({$removeOnCycleRes['message']})" : ''), $removeOnCycleRes['status']);
    $cycleDetailsAfterRemove = $runModel->getDetails($runId, $compId);
    checkTrue('the removed employee is gone from a cycle-based run right after removeManualEmployee()', current(array_filter($cycleDetailsAfterRemove, fn($d) => (int)$d['employee_id'] === $employeeFullId)) === false);

    $runModel->recalculate($runId, $compId, $adminUserId, true);
    $cycleDetailsAfterExtraRecalc = $runModel->getDetails($runId, $compId);
    checkTrue('the removed employee does NOT come back on a later recalculate() (date range alone would otherwise re-include them)', current(array_filter($cycleDetailsAfterExtraRecalc, fn($d) => (int)$d['employee_id'] === $employeeFullId)) === false);

    $joinNonExcludedRes = $runModel->joinEmployees($runId, $compId, [$employeeOptOutId], $adminUserId, true);
    check('joinEmployees() on a cycle-based run rejects an employee who is not currently excluded from it', $joinNonExcludedRes['status'], false);

    $rejoinRes = $runModel->joinEmployees($runId, $compId, [$employeeFullId], $adminUserId, true);
    checkTrue('joinEmployees() on a cycle-based run re-includes a previously-removed employee' . (empty($rejoinRes['status']) ? " ({$rejoinRes['message']})" : ''), $rejoinRes['status']);
    $cycleDetailsAfterRejoin = $runModel->getDetails($runId, $compId);
    checkTrue('the re-included employee is back after joinEmployees()', current(array_filter($cycleDetailsAfterRejoin, fn($d) => (int)$d['employee_id'] === $employeeFullId)) !== false);
    check('employee_count is back to the original 4 after the full remove -> recalculate -> re-include round trip', $rejoinRes['employee_count'] ?? null, 4);

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

    echo "=== Custom-item PED assignment flows into the real calculation ===\n";
    $customDedLines = array_values(array_filter($fullDetail['deduction_breakdown'], fn($l) => $l['source'] === 'ped' && !empty($l['is_custom'])));
    checkTrue('exactly one custom-item deduction line present (not silently dropped by the PED JOIN)', count($customDedLines) === 1);
    if (!empty($customDedLines)) {
        $customLine = $customDedLines[0];
        check('custom deduction amount matches the fixture (200)', (float)$customLine['amount'], 200.0);
        check('custom deduction code is CUSTOM:<name> (same shape as manual_line custom items)', $customLine['code'], 'CUSTOM:ค่ามัดจำชุดยูนิฟอร์ม');
        check('custom deduction name reflects custom_item_name', $customLine['name_th'], 'ค่ามัดจำชุดยูนิฟอร์ม');
    }
    checkTrue('catalog PED earning line is NOT flagged is_custom', empty(array_values(array_filter($fullDetail['earning_breakdown'], fn($l) => $l['source'] === 'ped'))[0]['is_custom'] ?? false));

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

    echo "=== salary_type wired into real calculation (2026-08-21, explicit request) ===\n";
    // Added AFTER the baseline recalculate()/employee_count=4 assertions above (not into the
    // original fixture set) so those assertions stay untouched -- proof this feature is additive,
    // zero regression for 'monthly' (the default/common case already covered above).
    $setupRulesModelForTest = new SetupRulesModel($pdo);
    $dailyShift = $setupRulesModelForTest->shiftSave([
        'shift_name_th' => 'กะรายวันทดสอบ', 'shift_name_en' => 'Daily Test Shift', 'shift_code' => 'PRT_DSHIFT_' . uniqid(),
        'start_time' => '08:00', 'end_time' => '17:00', 'status' => 'active',
        'works_monday' => 1, 'works_tuesday' => 1, 'works_wednesday' => 1, 'works_thursday' => 1, 'works_friday' => 1,
        'works_saturday' => 0, 'works_sunday' => 0,
    ], $compId, $adminUserId);
    checkTrue('fixture: Mon-Fri shift for the daily-salary employee saves', $dailyShift['status']);
    $dailyShiftId = $dailyShift['id'];

    // A company-wide holiday somewhere inside the run period, on a weekday that doesn't collide
    // with the mid-joiner/leaver dates above -- picked programmatically so this stays correct no
    // matter which calendar month the test happens to run in.
    $dailyHolidayDate = null;
    $cursor = new DateTime($periodStart);
    $periodEndDt = new DateTime($periodEnd);
    while ($cursor <= $periodEndDt) {
        $dateStr = $cursor->format('Y-m-d');
        if ((int)$cursor->format('N') <= 5 && $dateStr !== $midMonthJoin && $dateStr !== $midMonthLeaveDate) {
            $dailyHolidayDate = $dateStr;
            break;
        }
        $cursor->modify('+1 day');
    }
    checkTrue('fixture: found a usable weekday for the test holiday', $dailyHolidayDate !== null);
    $dailyHolidayRes = $setupRulesModelForTest->holidaySave([
        'name_th' => 'วันหยุดทดสอบรายวัน', 'name_en' => 'Daily Salary Test Holiday', 'holiday_date' => $dailyHolidayDate,
        'is_recurring' => 0, 'assignment_mode' => 'exclude', 'status' => 'active', 'assignments' => [],
    ], $compId, $adminUserId);
    checkTrue('fixture: company-wide holiday saves', $dailyHolidayRes['status']);

    $dailyRate = 1200.0;
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_DAILY_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายวัน', ':name_en' => 'Test', ':surname_en' => 'DailySalary',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => $dailyRate, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeDailyId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET salary_type = 'daily', shift_id = :shift_id WHERE id = :id")
        ->execute([':shift_id' => $dailyShiftId, ':id' => $employeeDailyId]);

    $hourlyRate = 30000.0;
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_HOURLY_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายชั่วโมง', ':name_en' => 'Test', ':surname_en' => 'HourlySalary',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => $hourlyRate, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeHourlyId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET salary_type = 'hourly' WHERE id = :id")->execute([':id' => $employeeHourlyId]);

    $noShiftRate = 1000.0;
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_DAILYNOSHIFT_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายวันไม่มีกะ', ':name_en' => 'Test', ':surname_en' => 'DailyNoShift',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => $noShiftRate, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeDailyNoShiftId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET salary_type = 'daily' WHERE id = :id")->execute([':id' => $employeeDailyNoShiftId]);

    $expectedPayableDaily = $setupRulesModelForTest->payableDaysForEmployee($employeeDailyId, $compId, $periodStart, $periodEnd);
    $expectedPayableNoShift = $setupRulesModelForTest->payableDaysForEmployee($employeeDailyNoShiftId, $compId, $periodStart, $periodEnd);

    $calcRes2 = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds after adding daily/hourly employees', $calcRes2['status']);
    check('employee_count is 7 after adding the 3 salary_type fixtures', $calcRes2['employee_count'], 7);

    $details2 = $runModel->getDetails($runId, $compId);
    $dailyDetail = null;
    $hourlyDetail = null;
    $noShiftDetail = null;
    foreach ($details2 as $d) {
        if ((int)$d['employee_id'] === $employeeDailyId) $dailyDetail = $d;
        if ((int)$d['employee_id'] === $employeeHourlyId) $hourlyDetail = $d;
        if ((int)$d['employee_id'] === $employeeDailyNoShiftId) $noShiftDetail = $d;
    }

    check('daily employee base_salary_amount = dailyRate * payable_days', (float)$dailyDetail['base_salary_amount'], round($dailyRate * $expectedPayableDaily['payable_days'], 2));
    check('daily employee prorate_days holds payable_days (repurposed display field)', (int)$dailyDetail['prorate_days'], $expectedPayableDaily['payable_days']);
    check('daily employee prorate_total_days holds total_days', (int)$dailyDetail['prorate_total_days'], $expectedPayableDaily['total_days']);
    check('daily employee WITH a shift is not flagged daily_salary_no_shift_pattern', strpos((string)($dailyDetail['calc_errors'] ?? ''), 'daily_salary_no_shift_pattern'), false);

    checkTrue('hourly employee flagged salary_type_hourly_not_supported', strpos((string)($hourlyDetail['calc_errors'] ?? ''), 'salary_type_hourly_not_supported') !== false);
    check('hourly employee falls back to the unprorated monthly formula for a full period', (float)$hourlyDetail['base_salary_amount'], $hourlyRate);

    checkTrue('daily employee WITHOUT a shift flagged daily_salary_no_shift_pattern', strpos((string)($noShiftDetail['calc_errors'] ?? ''), 'daily_salary_no_shift_pattern') !== false);
    check('daily employee without a shift has_shift_pattern is false', $expectedPayableNoShift['has_shift_pattern'], false);
    check('no-shift daily employee base_salary_amount = rate * payable_days (holiday-only exclusion)', (float)$noShiftDetail['base_salary_amount'], round($noShiftRate * $expectedPayableNoShift['payable_days'], 2));

    echo "=== Per-run earning/deduction item selection (two-panel, per-type) ===\n";
    // A second earning PED type + standing assignment on the same full-period employee, so
    // restricting to just $pedTypeId (transport allowance) has something else to visibly exclude.
    $mealItemCode = 'TESTMEAL' . rand(100, 999);
    $mealPedRes = $pedTypeModel->save($compId, [
        'item_code' => $mealItemCode,
        'item_name_th' => 'ค่าอาหารทดสอบ', 'item_name_en' => 'Test Meal Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 300,
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: second PED type created', $mealPedRes['status']);
    $mealPedTypeId = $mealPedRes['id'];
    $pdo->prepare("INSERT INTO `employee_earning_deductions`
        (employee_id, ped_type_id, total_installments, current_installment, amount_mode, total_amount, effective_date, status, created_by)
        VALUES (:employee_id, :ped_type_id, 1, 0, 'even_split', 300, :effective_date, 'active', :created_by)")
        ->execute([':employee_id' => $employeeFullId, ':ped_type_id' => $mealPedTypeId, ':effective_date' => $periodStart, ':created_by' => $adminUserId]);
    $mealAssignmentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `employee_earning_deduction_installments` (assignment_id, installment_no, amount, status)
        VALUES (:assignment_id, 1, 300, 'pending')")->execute([':assignment_id' => $mealAssignmentId]);

    $settingsBefore = $runModel->getPedTypeSettings($runId, $compId);
    checkTrue('getPedTypeSettings succeeds before any selection saved' . (empty($settingsBefore['status']) ? " ({$settingsBefore['message']})" : ''), $settingsBefore['status']);
    check('earning side unrestricted by default (no rows saved yet)', $settingsBefore['earning']['is_restricted'], false);
    check('deduction side unrestricted by default (no rows saved yet)', $settingsBefore['deduction']['is_restricted'], false);
    checkTrue('earning default selected_ids includes both allowance types', in_array($pedTypeId, $settingsBefore['earning']['selected_ids'], true) && in_array($mealPedTypeId, $settingsBefore['earning']['selected_ids'], true));

    // Recalc without restriction first -- both allowance types should show up.
    $runModel->recalculate($runId, $compId, $adminUserId, true);
    $unrestrictedDetail = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeFullId) $unrestrictedDetail = $d;
    }
    check('unrestricted: both PED earning lines present', count(array_filter($unrestrictedDetail['earning_breakdown'], fn($l) => $l['source'] === 'ped')), 2);

    $invalidTypeRes = $runModel->savePedTypeSettings($runId, $compId, 'bogus', [$pedTypeId], $adminUserId, true);
    check('savePedTypeSettings rejects an invalid item_type', $invalidTypeRes['status'], false);

    $invalidSaveRes = $runModel->savePedTypeSettings($runId, $compId, 'earning', [999999], $adminUserId, true);
    check('savePedTypeSettings rejects an invalid/foreign ped_type_id', $invalidSaveRes['status'], false);

    $crossTypeSaveRes = $runModel->savePedTypeSettings($runId, $compId, 'deduction', [$pedTypeId], $adminUserId, true);
    check('savePedTypeSettings rejects an earning id submitted under item_type=deduction', $crossTypeSaveRes['status'], false);

    $incentiveSaveRes = $runModel->savePedTypeSettings($incentiveRunId, $compId, 'earning', [$pedTypeId], $adminUserId, true);
    check('savePedTypeSettings rejects an incentive run (items are picked per-employee there instead)', $incentiveSaveRes['status'], false);

    $restrictRes = $runModel->savePedTypeSettings($runId, $compId, 'earning', [$pedTypeId], $adminUserId, true);
    checkTrue('savePedTypeSettings succeeds restricting earning to the transport-allowance type only' . (empty($restrictRes['status']) ? " ({$restrictRes['message']})" : ''), $restrictRes['status']);

    $settingsAfter = $runModel->getPedTypeSettings($runId, $compId);
    check('earning side is now restricted', $settingsAfter['earning']['is_restricted'], true);
    check('earning selected_ids reflects the saved selection', $settingsAfter['earning']['selected_ids'], [$pedTypeId]);
    check('deduction side is still unrestricted (earning save did not touch it)', $settingsAfter['deduction']['is_restricted'], false);

    // savePedTypeSettings() recalculates internally now (2026-08-19, explicit request) -- no
    // separate recalculate() call needed to see the effect below.
    $restrictedDetail = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeFullId) $restrictedDetail = $d;
    }
    $restrictedPedCodes = array_column(array_filter($restrictedDetail['earning_breakdown'], fn($l) => $l['source'] === 'ped'), 'code');
    check('restricted: only the selected item is included', $restrictedPedCodes, [$pedTypeModel->get($compId, $pedTypeId)['item_code']]);
    checkTrue('restricted: the excluded item is really gone', !in_array($mealItemCode, $restrictedPedCodes, true));

    echo "=== Per-employee ad-hoc adjustment on a normal (non-incentive) run ===\n";
    $notMemberRes = $runModel->addManualLine($runId, $compId, 999999, $mealPedTypeId, 100, $adminUserId, true);
    check('addManualLine rejects an employee who is not part of the calculated run', $notMemberRes['status'], false);

    $midBeforeAdjust = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeMidId) $midBeforeAdjust = $d;
    }
    $midGrossBefore = (float)$midBeforeAdjust['gross_amount'];

    $adjustComment = 'August OT shortfall top-up';
    $adjustEarnRes = $runModel->addManualLine($runId, $compId, $employeeMidId, $mealPedTypeId, 250, $adminUserId, true, $adjustComment);
    checkTrue('addManualLine succeeds for a normal-run employee (ad-hoc adjustment, not an incentive run)' . (empty($adjustEarnRes['status']) ? " ({$adjustEarnRes['message']})" : ''), $adjustEarnRes['status']);

    $midAfterAdjust = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeMidId) $midAfterAdjust = $d;
    }
    check('ad-hoc earning adjustment increases gross by exactly 250 on top of the normal calculation', round((float)$midAfterAdjust['gross_amount'] - $midGrossBefore, 2), 250.0);
    $adjustLines = array_values(array_filter($midAfterAdjust['earning_breakdown'], fn($l) => $l['source'] === 'manual_line'));
    checkTrue('the added line is tagged source=manual_line', count($adjustLines) === 1);
    check('the comment round-trips into earning_breakdown', $adjustLines[0]['note'] ?? null, $adjustComment);

    $midLines = $runModel->manualLinesForEmployee($compId, $runId, $employeeMidId);
    check('manualLinesForEmployee returns the one adjustment line', count($midLines), 1);
    check('manualLinesForEmployee returns the comment too', $midLines[0]['note'] ?? null, $adjustComment);
    $adjustLineId = (int)$midLines[0]['id'];

    $removeAdjustRes = $runModel->removeManualLine($runId, $compId, $adjustLineId, $adminUserId, true);
    checkTrue('removeManualLine succeeds' . (empty($removeAdjustRes['status']) ? " ({$removeAdjustRes['message']})" : ''), $removeAdjustRes['status']);
    $midAfterRemove = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeMidId) $midAfterRemove = $d;
    }
    check('gross is back to its pre-adjustment amount after removing the line', round((float)$midAfterRemove['gross_amount'], 2), round($midGrossBefore, 2));

    echo "=== Custom (not-in-the-catalog) manual line item ===\n";
    $noNameRes = $runModel->addManualLine($runId, $compId, $employeeMidId, null, 100, $adminUserId, true, null, '', 'earning');
    check('addManualLine rejects a custom item with a blank name', $noNameRes['status'], false);
    $badTypeRes = $runModel->addManualLine($runId, $compId, $employeeMidId, null, 100, $adminUserId, true, null, 'Test Custom Item', 'bogus');
    check('addManualLine rejects an invalid custom_item_type', $badTypeRes['status'], false);

    $customEarnName = 'Uniform Deposit Refund';
    $customEarnRes = $runModel->addManualLine($runId, $compId, $employeeMidId, null, 400, $adminUserId, true, 'test comment', $customEarnName, 'earning');
    checkTrue('addManualLine succeeds for a custom earning item' . (empty($customEarnRes['status']) ? " ({$customEarnRes['message']})" : ''), $customEarnRes['status']);
    $customDeductName = 'Parking Fine Deduction';
    $customDeductRes = $runModel->addManualLine($runId, $compId, $employeeMidId, null, 150, $adminUserId, true, null, $customDeductName, 'deduction');
    checkTrue('addManualLine succeeds for a custom deduction item' . (empty($customDeductRes['status']) ? " ({$customDeductRes['message']})" : ''), $customDeductRes['status']);

    $midAfterCustom = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeMidId) $midAfterCustom = $d;
    }
    check('custom earning item increases gross by exactly 400', round((float)$midAfterCustom['gross_amount'] - $midGrossBefore, 2), 400.0);
    $customEarnLine = array_values(array_filter($midAfterCustom['earning_breakdown'], fn($l) => $l['source'] === 'manual_line'))[0] ?? null;
    checkTrue('custom earning line is flagged is_custom', ($customEarnLine['is_custom'] ?? null) === true);
    check('custom earning line name is the free-text name typed in', $customEarnLine['name_th'] ?? null, $customEarnName);
    checkTrue('custom earning line code carries the CUSTOM: prefix (internal grouping key, never shown)', str_starts_with((string)($customEarnLine['code'] ?? ''), 'CUSTOM:'));
    $customDeductLine = array_values(array_filter($midAfterCustom['deduction_breakdown'], fn($l) => $l['source'] === 'manual_line'))[0] ?? null;
    check('custom deduction line name is the free-text name typed in', $customDeductLine['name_th'] ?? null, $customDeductName);

    $midCustomLines = $runModel->manualLinesForEmployee($compId, $runId, $employeeMidId);
    check('manualLinesForEmployee returns both custom lines', count($midCustomLines), 2);
    checkTrue('manualLinesForEmployee flags both as is_custom', $midCustomLines[0]['is_custom'] && $midCustomLines[1]['is_custom']);

    foreach ($midCustomLines as $l) {
        $runModel->removeManualLine($runId, $compId, (int)$l['id'], $adminUserId, true);
    }
    $midAfterCustomRemove = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeMidId) $midAfterCustomRemove = $d;
    }
    check('gross is back to baseline after removing both custom lines', round((float)$midAfterCustomRemove['gross_amount'], 2), round($midGrossBefore, 2));

    // Reset the earning restriction back to unrestricted -- both the setting AND the run must be
    // recalculated back to the fully-included state here so the rest of this script (markPaid's
    // installment assertions below) sees exactly what the pre-existing flow always expected.
    $resetRes = $runModel->savePedTypeSettings($runId, $compId, 'earning', [], $adminUserId, true);
    checkTrue('savePedTypeSettings with an empty array resets earning to unrestricted' . (empty($resetRes['status']) ? " ({$resetRes['message']})" : ''), $resetRes['status']);
    $settingsReset = $runModel->getPedTypeSettings($runId, $compId);
    check('earning side is unrestricted again after reset', $settingsReset['earning']['is_restricted'], false);
    $fullAfterReset = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeFullId) $fullAfterReset = $d;
    }
    check('unrestricted again: both PED earning lines present for the full-period employee', count(array_filter($fullAfterReset['earning_breakdown'], fn($l) => $l['source'] === 'ped')), 2);

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
    checkTrue('submitted_at is set before reverting (sanity check on the fixture)', !empty($runModel->get($runId, $compId)['submitted_at']));
    $revertRes = $runModel->revert($runId, $compId, $adminUserId, true, 'test revert');
    checkTrue('revert succeeds', $revertRes['status']);
    check('state is draft again', $runModel->get($runId, $compId)['state'], 'draft');
    // 2026-08-27, explicit bug report ("ในหน้า Process List ถ้ายังไม่ส่งไป Approve ปุ่ม Timeline ยังไม่
    // ควรขึ้นมาให้กดดูได้") -- pulling back to draft must clear submitted_at, otherwise the Process
    // List's workflowTimelineButtonHtml()/mini-timeline still treated this run as "already submitted"
    // even though it's editable draft again.
    check('submitted_at is cleared after reverting to draft (Timeline button must not show again)', $runModel->get($runId, $compId)['submitted_at'], null);

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

    echo "=== list()'s cancelled_from_state column (2026-08-22, feeds the Process List mini-timeline) ===\n";
    $listAfterDraftCancel = $runModel->list($compId, []);
    $draftCancelRow = array_values(array_filter($listAfterDraftCancel, fn($r) => (int)$r['id'] === $cancelTargetId))[0] ?? [];
    check('cancelled_from_state is draft for a run cancelled straight from draft', $draftCancelRow['cancelled_from_state'] ?? null, 'draft');

    $cancelFromPendingRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_CANCEL_FROM_PENDING_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +6 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +6 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +6 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $cancelFromPendingId = $cancelFromPendingRes['id'];
    $runModel->recalculate($cancelFromPendingId, $compId, $adminUserId, true);
    $runModel->submit($cancelFromPendingId, $compId, $adminUserId, true);
    $runModel->cancel($cancelFromPendingId, $compId, $adminUserId, true, 'Cancelling while pending approval.');
    $listAfterPendingCancel = $runModel->list($compId, []);
    $pendingCancelRow = array_values(array_filter($listAfterPendingCancel, fn($r) => (int)$r['id'] === $cancelFromPendingId))[0] ?? [];
    check('cancelled_from_state is pending_approval for a run cancelled after submit', $pendingCancelRow['cancelled_from_state'] ?? null, 'pending_approval');
    $lockedRunRow = array_values(array_filter($listAfterDraftCancel, fn($r) => (int)$r['id'] === $runId))[0] ?? ['cancelled_from_state' => 'MISSING_ROW'];
    check('a non-cancelled run has a null cancelled_from_state', $lockedRunRow['cancelled_from_state'], null);

    echo "=== bulkApprove()/bulkReject() (2026-08-22, explicit request: \"การอนุมุติให้มี checkbox เลือกอนุมุติได้หลายรายการพร้อมกัน\") ===\n";
    $bulkRunA = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_BULK_A_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +7 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +7 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +7 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $bulkRunB = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_BULK_B_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +8 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +8 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +8 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $bulkRunAId = $bulkRunA['id'];
    $bulkRunBId = $bulkRunB['id'];
    foreach ([$bulkRunAId, $bulkRunBId] as $bid) {
        $runModel->recalculate($bid, $compId, $adminUserId, true);
        $runModel->submit($bid, $compId, $adminUserId, true);
    }

    $bulkApproveRes = $runModel->bulkApprove([$bulkRunAId, $bulkRunBId], $compId, $adminUserId, true, 'bulk-approved in test');
    checkTrue('bulkApprove() succeeds when both ids are valid', $bulkApproveRes['status']);
    check('bulkApprove() succeeded count is 2', $bulkApproveRes['succeeded'], 2);
    check('bulkApprove() total count is 2', $bulkApproveRes['total'], 2);
    check('run A is approved', $runModel->get($bulkRunAId, $compId)['state'], 'approved');
    check('run B is approved', $runModel->get($bulkRunBId, $compId)['state'], 'approved');

    // Partial success: run A is already approved (no longer pending_approval) -- bulk-approving it
    // again alongside a genuinely still-draft run (never submitted) means BOTH fail individually,
    // but the call itself should still report a clean partial-failure shape, not throw/error out.
    $bulkRunC = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_BULK_C_DRAFT_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +9 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $bulkRunCId = $bulkRunC['id'];
    $bulkApprovePartialRes = $runModel->bulkApprove([$bulkRunAId, $bulkRunCId], $compId, $adminUserId, true, null);
    check('bulkApprove() with 2 invalid ids (already-approved + still-draft) reports 0 succeeded', $bulkApprovePartialRes['succeeded'], 0);
    check('bulkApprove() status is false when nothing succeeded', $bulkApprovePartialRes['status'], false);
    check('bulkApprove() still reports the correct total', $bulkApprovePartialRes['total'], 2);
    checkTrue('bulkApprove() results carry a per-id failure message', !empty($bulkApprovePartialRes['results'][$bulkRunAId]['message'] ?? ''));

    // bulkReject(): 2 fresh pending_approval runs, empty reason -- mirrors reject()'s own
    // per-id validation (trim($reason)==='' rejected), so both fail individually here too.
    $bulkRunD = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_BULK_D_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +10 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +10 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +10 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $bulkRunE = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_BULK_E_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +11 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +11 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +11 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $bulkRunDId = $bulkRunD['id'];
    $bulkRunEId = $bulkRunE['id'];
    foreach ([$bulkRunDId, $bulkRunEId] as $bid) {
        $runModel->recalculate($bid, $compId, $adminUserId, true);
        $runModel->submit($bid, $compId, $adminUserId, true);
    }

    $bulkRejectEmptyRes = $runModel->bulkReject([$bulkRunDId, $bulkRunEId], $compId, $adminUserId, true, '   ');
    check('bulkReject() with an empty/whitespace reason succeeds for nobody', $bulkRejectEmptyRes['succeeded'], 0);
    check('bulkReject() with an empty reason has status false', $bulkRejectEmptyRes['status'], false);
    check('both runs stay pending_approval after the empty-reason bulk reject', $runModel->get($bulkRunDId, $compId)['state'], 'pending_approval');

    $bulkRejectRes = $runModel->bulkReject([$bulkRunDId, $bulkRunEId], $compId, $adminUserId, true, 'Numbers look wrong, please recheck.');
    checkTrue('bulkReject() with a real reason succeeds', $bulkRejectRes['status']);
    check('bulkReject() succeeded count is 2', $bulkRejectRes['succeeded'], 2);
    check('run D is rejected', $runModel->get($bulkRunDId, $compId)['state'], 'rejected');
    check('run E is rejected', $runModel->get($bulkRunEId, $compId)['state'], 'rejected');
    check('reject_reason stored for run D via bulkReject()', $runModel->get($bulkRunDId, $compId)['reject_reason'], 'Numbers look wrong, please recheck.');

    echo "=== requestInfo()/reviseAfterNeedInfo()/bulkRequestInfo() (2026-08-22, explicit request: \"Status ในหน้า Approve มี Waiting Approve Not Approve Need Information\" -- confirmed as a REAL third state) ===\n";
    $needInfoRunA = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_NEEDINFO_A_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +12 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +12 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +12 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $needInfoRunAId = $needInfoRunA['id'];
    $runModel->recalculate($needInfoRunAId, $compId, $adminUserId, true);
    $runModel->submit($needInfoRunAId, $compId, $adminUserId, true);

    $requestInfoEmptyRes = $runModel->requestInfo($needInfoRunAId, $compId, $adminUserId, true, '   ');
    check('requestInfo() with an empty/whitespace reason is rejected', $requestInfoEmptyRes['status'], false);

    $requestInfoRes = $runModel->requestInfo($needInfoRunAId, $compId, $adminUserId, true, 'Please confirm the OT hours for employee X.');
    checkTrue('requestInfo() with a real reason succeeds' . (empty($requestInfoRes['status']) ? " ({$requestInfoRes['message']})" : ''), $requestInfoRes['status']);
    $needInfoRunAAfter = $runModel->get($needInfoRunAId, $compId);
    check('state is need_info', $needInfoRunAAfter['state'], 'need_info');
    check('need_info_reason stored', $needInfoRunAAfter['need_info_reason'], 'Please confirm the OT hours for employee X.');
    check('need_info_by stored', (int)$needInfoRunAAfter['need_info_by'], $adminUserId);
    checkTrue('need_info_at stored', $needInfoRunAAfter['need_info_at'] !== null);

    $requestInfoAgainRes = $runModel->requestInfo($needInfoRunAId, $compId, $adminUserId, true, 'Second request.');
    check('requestInfo() rejected once no longer pending_approval', $requestInfoAgainRes['status'], false);

    $reviseWrongStateRes = $runModel->reviseAfterNeedInfo($bulkRunAId, $compId, $adminUserId, true);
    check('reviseAfterNeedInfo() rejected for a run that is not need_info (it is approved)', $reviseWrongStateRes['status'], false);

    $reviseNeedInfoRes = $runModel->reviseAfterNeedInfo($needInfoRunAId, $compId, $adminUserId, true);
    checkTrue('reviseAfterNeedInfo() succeeds' . (empty($reviseNeedInfoRes['status']) ? " ({$reviseNeedInfoRes['message']})" : ''), $reviseNeedInfoRes['status']);
    check('state is draft again after reviseAfterNeedInfo()', $runModel->get($needInfoRunAId, $compId)['state'], 'draft');

    echo "=== cancel() now allowed from need_info too ===\n";
    $needInfoRunB = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_NEEDINFO_CANCEL_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +13 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +13 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +13 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $needInfoRunBId = $needInfoRunB['id'];
    $runModel->recalculate($needInfoRunBId, $compId, $adminUserId, true);
    $runModel->submit($needInfoRunBId, $compId, $adminUserId, true);
    $runModel->requestInfo($needInfoRunBId, $compId, $adminUserId, true, 'Need clarification.');
    $cancelNeedInfoRes = $runModel->cancel($needInfoRunBId, $compId, $adminUserId, true, 'No longer needed, cancelling outright.');
    checkTrue('cancel() succeeds from need_info' . (empty($cancelNeedInfoRes['status']) ? " ({$cancelNeedInfoRes['message']})" : ''), $cancelNeedInfoRes['status']);
    check('state is cancelled', $runModel->get($needInfoRunBId, $compId)['state'], 'cancelled');

    echo "=== bulkRequestInfo() ===\n";
    $bulkRunF = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_BULK_F_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +14 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +14 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +14 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $bulkRunG = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_BULK_G_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +15 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +15 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +15 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $bulkRunFId = $bulkRunF['id'];
    $bulkRunGId = $bulkRunG['id'];
    foreach ([$bulkRunFId, $bulkRunGId] as $bid) {
        $runModel->recalculate($bid, $compId, $adminUserId, true);
        $runModel->submit($bid, $compId, $adminUserId, true);
    }

    $bulkRequestInfoEmptyRes = $runModel->bulkRequestInfo([$bulkRunFId, $bulkRunGId], $compId, $adminUserId, true, '   ');
    check('bulkRequestInfo() with an empty reason succeeds for nobody', $bulkRequestInfoEmptyRes['succeeded'], 0);
    check('bulkRequestInfo() with an empty reason has status false', $bulkRequestInfoEmptyRes['status'], false);

    $bulkRequestInfoRes = $runModel->bulkRequestInfo([$bulkRunFId, $bulkRunGId], $compId, $adminUserId, true, 'Please double-check the bank details.');
    checkTrue('bulkRequestInfo() with a real reason succeeds' . (empty($bulkRequestInfoRes['status']) ? " ({$bulkRequestInfoRes['message']})" : ''), $bulkRequestInfoRes['status']);
    check('bulkRequestInfo() succeeded count is 2', $bulkRequestInfoRes['succeeded'], 2);
    check('run F is need_info', $runModel->get($bulkRunFId, $compId)['state'], 'need_info');
    check('run G is need_info', $runModel->get($bulkRunGId, $compId)['state'], 'need_info');

    echo "=== Audit log has one entry per action ===\n";
    $auditLog = $runModel->getAuditLog($runId, $compId);
    checkTrue('audit log recorded multiple actions', count($auditLog) >= 6);

    echo "=== Generalized revert() (2026-08-23, explicit request: \"ถ้ามีการกดอะไรก็ตาม ฝั่งผู้อนุมัติ" .
        "สามารถถอยอนุมัติได้ เช่น ถ้า Approve not approve หรือ need info สามารถถอยกลับไป Status อื่น" .
        "ที่ไม่ใช่ Status ปัจจุบันได้\") -- approved/rejected/need_info can now all be reverted back to" .
        " pending_approval (not just pending_approval -> draft), clearing that state's own columns" .
        " so nothing stale is left behind, while the audit log keeps every past decision. ===\n";
    $revertRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_REVERT_GEN_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +16 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +16 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +16 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: revert-test run created' . (empty($revertRunRes['status']) ? " ({$revertRunRes['message']})" : ''), $revertRunRes['status']);
    $revertRunId = $revertRunRes['id'];
    $runModel->recalculate($revertRunId, $compId, $adminUserId, true);

    // approved -> pending_approval
    $runModel->submit($revertRunId, $compId, $adminUserId, true);
    $runModel->approve($revertRunId, $compId, $adminUserId, true, 'looks good');
    check('state is approved before revert', $runModel->get($revertRunId, $compId)['state'], 'approved');
    $undoApprovedRes = $runModel->revert($revertRunId, $compId, $adminUserId, true, 'undo: wrong amount');
    checkTrue('revert from approved succeeds', $undoApprovedRes['status']);
    $afterUndoApproved = $runModel->get($revertRunId, $compId);
    check('state is pending_approval again after undoing approve', $afterUndoApproved['state'], 'pending_approval');
    check('approved_by cleared after undoing approve', $afterUndoApproved['approved_by'], null);
    check('approved_at cleared after undoing approve', $afterUndoApproved['approved_at'], null);

    // rejected -> pending_approval
    $runModel->reject($revertRunId, $compId, $adminUserId, true, 'missing overtime');
    check('state is rejected before revert', $runModel->get($revertRunId, $compId)['state'], 'rejected');
    $undoRejectedRes = $runModel->revert($revertRunId, $compId, $adminUserId, true);
    checkTrue('revert from rejected succeeds', $undoRejectedRes['status']);
    $afterUndoRejected = $runModel->get($revertRunId, $compId);
    check('state is pending_approval again after undoing reject', $afterUndoRejected['state'], 'pending_approval');
    check('rejected_by cleared after undoing reject', $afterUndoRejected['rejected_by'], null);
    check('reject_reason cleared after undoing reject', $afterUndoRejected['reject_reason'], null);

    // need_info -> pending_approval
    $runModel->requestInfo($revertRunId, $compId, $adminUserId, true, 'need the OT sheet');
    check('state is need_info before revert', $runModel->get($revertRunId, $compId)['state'], 'need_info');
    $undoNeedInfoRes = $runModel->revert($revertRunId, $compId, $adminUserId, true);
    checkTrue('revert from need_info succeeds', $undoNeedInfoRes['status']);
    $afterUndoNeedInfo = $runModel->get($revertRunId, $compId);
    check('state is pending_approval again after undoing need_info', $afterUndoNeedInfo['state'], 'pending_approval');
    check('need_info_by cleared after undoing need_info', $afterUndoNeedInfo['need_info_by'], null);
    check('need_info_reason cleared after undoing need_info', $afterUndoNeedInfo['need_info_reason'], null);

    // pending_approval -> draft (original behavior, unchanged)
    $undoPendingRes = $runModel->revert($revertRunId, $compId, $adminUserId, true);
    checkTrue('revert from pending_approval still succeeds', $undoPendingRes['status']);
    check('state is draft after reverting from pending_approval', $runModel->get($revertRunId, $compId)['state'], 'draft');

    // Every decision this run ever went through is still in the audit trail (2026-08-23: "แต่ต้องเก็บ
    // Log การอนุมัตด้วยว่าเคยอนุมัติไปแล้วกี่ครั้ง แต่ละครั้งเป็นยังไง ดูใน Log Audit").
    $revertAuditLog = $runModel->getAuditLog($revertRunId, $compId);
    $revertActions = array_column($revertAuditLog, 'action');
    check('audit trail kept the approve action', in_array('approve', $revertActions, true), true);
    check('audit trail kept the reject action', in_array('reject', $revertActions, true), true);
    check('audit trail kept the request_info action', in_array('request_info', $revertActions, true), true);
    check('audit trail kept 4 separate revert actions (one per undo above)', count(array_filter($revertActions, fn($a) => $a === 'revert')), 4);
    checkTrue('every audit log row carries a client IP (or null column, but the key exists)', array_key_exists('ip_address', $revertAuditLog[0]));
    checkTrue('every audit log row carries a user_agent column', array_key_exists('user_agent', $revertAuditLog[0]));

    // draft/paid/locked/cancelled all refuse revert -- no "decision" to undo there.
    $undoFromDraftRes = $runModel->revert($revertRunId, $compId, $adminUserId, true);
    check('revert from draft is refused', $undoFromDraftRes['status'], false);
    $undoFromLockedRes = $runModel->revert($runId, $compId, $adminUserId, true); // $runId is 'locked' by this point in the file
    check('revert from a locked (already-paid) run is refused', $undoFromLockedRes['status'], false);

    echo "=== 2026-08-24, revert() to a CHOSEN status (explicit follow-up request: \"ถ้า Process นั้น" .
        "อนุมัติ สามารถถอยมารออนุมัติ ไม่อนุมัติ ขอข้อมูลเพิ่มเติมได้ คือ Status ที่ถอยหรือเปลี่ยน ต้องไม่ใช่" .
        " Status เดิม\") -- supersedes the always-goes-to-pending_approval behavior tested just above" .
        " (which stays as the DEFAULT when no target is given, for backward compatibility) with an" .
        " explicit \$toState the approver picks among the other 2 decided-adjacent statuses. ===\n";
    $chooseRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_REVERT_CHOOSE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +25 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +25 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +25 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: choose-target revert-test run created', $chooseRunRes['status']);
    $chooseRunId = $chooseRunRes['id'];
    $runModel->recalculate($chooseRunId, $compId, $adminUserId, true);
    $runModel->submit($chooseRunId, $compId, $adminUserId, true);
    $runModel->approve($chooseRunId, $compId, $adminUserId, true, 'looks fine');
    check('state is approved before the direct-to-rejected revert', $runModel->get($chooseRunId, $compId)['state'], 'approved');

    $sameStatusRes = $runModel->revert($chooseRunId, $compId, $adminUserId, true, null, 'approved');
    check('reverting to the SAME status the run is already at is refused', $sameStatusRes['status'], false);
    $invalidTargetRes = $runModel->revert($chooseRunId, $compId, $adminUserId, true, null, 'paid');
    check('an invalid/unreachable target status is refused', $invalidTargetRes['status'], false);
    check('run is untouched by both refused attempts (still approved)', $runModel->get($chooseRunId, $compId)['state'], 'approved');

    $toRejectedRes = $runModel->revert($chooseRunId, $compId, $adminUserId, true, 'discovered a calculation error', 'rejected');
    checkTrue('approved -> rejected directly (skipping pending_approval) succeeds' . (empty($toRejectedRes['status']) ? " ({$toRejectedRes['message']})" : ''), $toRejectedRes['status']);
    $afterToRejected = $runModel->get($chooseRunId, $compId);
    check('state is now rejected', $afterToRejected['state'], 'rejected');
    check('approved_by was cleared (exiting approved)', $afterToRejected['approved_by'], null);
    checkTrue('rejected_by was SET (entering rejected via the override)', $afterToRejected['rejected_by'] !== null);
    check('reject_reason carries the note passed to revert()', $afterToRejected['reject_reason'], 'discovered a calculation error');

    $toNeedInfoRes = $runModel->revert($chooseRunId, $compId, $adminUserId, true, null, 'need_info');
    checkTrue('rejected -> need_info directly succeeds' . (empty($toNeedInfoRes['status']) ? " ({$toNeedInfoRes['message']})" : ''), $toNeedInfoRes['status']);
    $afterToNeedInfo = $runModel->get($chooseRunId, $compId);
    check('state is now need_info', $afterToNeedInfo['state'], 'need_info');
    check('rejected_by was cleared (exiting rejected)', $afterToNeedInfo['rejected_by'], null);
    check('reject_reason was cleared (exiting rejected)', $afterToNeedInfo['reject_reason'], null);
    checkTrue('need_info_by was SET (entering need_info via the override, no note given -- default reason used)', $afterToNeedInfo['need_info_by'] !== null);
    checkTrue('need_info_reason got a sensible default when no note was passed', !empty($afterToNeedInfo['need_info_reason']));

    $backToPendingRes = $runModel->revert($chooseRunId, $compId, $adminUserId, true, null, 'pending_approval');
    checkTrue('need_info -> pending_approval (still a valid explicit choice) succeeds', $backToPendingRes['status']);
    check('state is pending_approval', $runModel->get($chooseRunId, $compId)['state'], 'pending_approval');

    echo "=== revert() permission split: submitter can pull back their own still-undecided" .
        " submission, but not an already-decided one (explicit request: \"ในกรณีที่ส่ง Approve แล้ว" .
        "ยังไม่มีใคร Approve สามารถดึง Process กลับได้\") ===\n";
    // Submitter-only role: can_process_payroll=1, can_approve_payroll=0.
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en, can_process_payroll, can_approve_payroll, can_finalize_payroll)
        VALUES (:comp_id, 'ทดสอบผู้ส่งอย่างเดียว', 'Test Submitter Only', 1, 0, 0)")->execute([':comp_id' => $compId]);
    $submitterOnlyRoleId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id WHERE id = :id")->execute([':role_id' => $submitterOnlyRoleId, ':id' => $employeeFullId]);

    $pullbackRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_SUBMITTER_PULLBACK_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +18 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +18 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +18 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: submitter-pullback-test run created' . (empty($pullbackRunRes['status']) ? " ({$pullbackRunRes['message']})" : ''), $pullbackRunRes['status']);
    $pullbackRunId = $pullbackRunRes['id'];
    $runModel->recalculate($pullbackRunId, $compId, $adminUserId, true);
    // $employeeFullId (submitter-only role) submits it themselves.
    $submitBySubmitterRes = $runModel->submit($pullbackRunId, $compId, $employeeFullId, false);
    checkTrue('submitter-only role can submit', $submitBySubmitterRes['status']);

    $submitterPullbackRes = $runModel->revert($pullbackRunId, $compId, $employeeFullId, false);
    checkTrue('submitter (can_process_payroll, no can_approve_payroll) can pull back their own pending_approval run', $submitterPullbackRes['status']);
    check('state is draft after the submitter pulls it back', $runModel->get($pullbackRunId, $compId)['state'], 'draft');

    // Resubmit, approve it, then confirm the SAME submitter-only role cannot undo that decision --
    // undoing an already-decided state stays approver-only.
    $runModel->submit($pullbackRunId, $compId, $employeeFullId, false);
    $runModel->approve($pullbackRunId, $compId, $adminUserId, true);
    $submitterUndoApprovedRes = $runModel->revert($pullbackRunId, $compId, $employeeFullId, false);
    check('submitter-only role cannot undo an already-approved decision', $submitterUndoApprovedRes['status'], false);

    echo "=== approvalFlow() reflects role/approver changes live, even after resubmit (explicit" .
        " report: \"มีการปรับ Flow Approve ไปแต่พอส่งไป Approve อีกครั้ง Flow ไม่เปลี่ยน\") ===\n";
    // 2026-08-23, follow-up explicit report ("Approval ตอนนี้ Set ไว้แค่คนเดียว แต่ดึงมาหลายคน") --
    // approvalFlow() is now scoped to the SUBMITTER's own department (see its own docblock), so
    // this fixture needs a real department shared by the submitter and both candidate approvers,
    // not just a role flag -- otherwise every approver query below would legitimately come back
    // empty regardless of role.
    $pdo->prepare("INSERT INTO `structure_departments` (comp_id, department_code, department_name_th, department_name_en, status)
        VALUES (:comp_id, :code, 'ทดสอบแผนก', 'Test Department', 'active')")->execute([':comp_id' => $compId, ':code' => 'TESTDEPT_' . uniqid()]);
    $testDepartmentId = (int)$pdo->lastInsertId();
    // $employeeFullId already holds the submitter-only role (can_process_payroll=1) from the
    // section just above -- reused here as the submitter so submitted_by resolves to a fully
    // test-controlled employee instead of the shared dev-DB admin account (id 1).
    $pdo->prepare("UPDATE `employees` SET department_id = :dept WHERE id = :id")->execute([':dept' => $testDepartmentId, ':id' => $employeeFullId]);

    // Role A: the only can_approve_payroll holder at first, held by an employee in the SAME department.
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en, can_process_payroll, can_approve_payroll, can_finalize_payroll)
        VALUES (:comp_id, 'ทดสอบผู้อนุมัติ A', 'Test Approver A', 0, 1, 0)")->execute([':comp_id' => $compId]);
    $approverRoleAId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id, department_id = :dept WHERE id = :id")
        ->execute([':role_id' => $approverRoleAId, ':dept' => $testDepartmentId, ':id' => $employeeMidId]);

    $flowRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_FLOW_CHANGE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +17 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +17 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +17 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: flow-change-test run created' . (empty($flowRunRes['status']) ? " ({$flowRunRes['message']})" : ''), $flowRunRes['status']);
    $flowRunId = $flowRunRes['id'];
    $runModel->recalculate($flowRunId, $compId, $adminUserId, true);
    $runModel->submit($flowRunId, $compId, $employeeFullId, false);

    $flowBefore = $runModel->approvalFlow($flowRunId, $compId);
    $flowBeforeIds = array_map('intval', array_column($flowBefore['approvers'], 'id'));
    checkTrue('flow before the role change includes approver A', in_array($employeeMidId, $flowBeforeIds, true));

    // Reject it, then change who can approve BEFORE it gets revised/resubmitted (the exact
    // sequence reported: adjust the flow, then send for approval again).
    $runModel->reject($flowRunId, $compId, $adminUserId, true, 'need changes');
    $pdo->prepare("UPDATE `structure_roles` SET can_approve_payroll = 0 WHERE id = :id")->execute([':id' => $approverRoleAId]);
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en, can_process_payroll, can_approve_payroll, can_finalize_payroll)
        VALUES (:comp_id, 'ทดสอบผู้อนุมัติ B', 'Test Approver B', 0, 1, 0)")->execute([':comp_id' => $compId]);
    $approverRoleBId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id, department_id = :dept WHERE id = :id")
        ->execute([':role_id' => $approverRoleBId, ':dept' => $testDepartmentId, ':id' => $employeeOptOutId]);

    $runModel->reviseAfterReject($flowRunId, $compId, $employeeFullId, false);
    $runModel->submit($flowRunId, $compId, $employeeFullId, false);
    $flowAfter = $runModel->approvalFlow($flowRunId, $compId);
    $flowAfterIds = array_map('intval', array_column($flowAfter['approvers'], 'id'));
    checkTrue('flow after resubmit no longer includes the removed approver A', !in_array($employeeMidId, $flowAfterIds, true));
    checkTrue('flow after resubmit includes the newly-added approver B', in_array($employeeOptOutId, $flowAfterIds, true));
    checkTrue('every approver in the refreshed flow is marked pending (not stale)', count(array_filter($flowAfter['approvers'], fn($a) => $a['status'] !== 'pending')) === 0);

    echo "=== canApproveThisRun() department scoping (explicit report: \"Approval ตอนนี้ Set ไว้แค่" .
        "คนเดียว แต่ดึงมาหลายคน\") -- approver B (same department as the submitter) can act; a" .
        " same-role approver in a DIFFERENT department cannot ===\n";
    // Approver B (role B, can_approve_payroll=1) is in $testDepartmentId -- same as the submitter --
    // and should be able to approve.
    $sameDeptApproveRes = $runModel->approve($flowRunId, $compId, $employeeOptOutId, false, 'ok from same department');
    checkTrue('same-department approver (role B) can approve', $sameDeptApproveRes['status']);

    // A THIRD employee holds the exact same can_approve_payroll role (role B) but sits in a
    // DIFFERENT department -- must be refused even though the role flag alone would have allowed it
    // under the old company-wide check.
    $pdo->prepare("INSERT INTO `structure_departments` (comp_id, department_code, department_name_th, department_name_en, status)
        VALUES (:comp_id, :code, 'ทดสอบแผนกอื่น', 'Test Other Department', 'active')")->execute([':comp_id' => $compId, ':code' => 'TESTDEPT2_' . uniqid()]);
    $otherDepartmentId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id, department_id = :dept WHERE id = :id")
        ->execute([':role_id' => $approverRoleBId, ':dept' => $otherDepartmentId, ':id' => $employeeLeaverId]);

    // Undo the approval above so there's something pending again to attempt (and to keep testing
    // a real decision, not a no-op on an already-approved run).
    $runModel->revert($flowRunId, $compId, $adminUserId, true);
    $otherDeptApproveRes = $runModel->approve($flowRunId, $compId, $employeeLeaverId, false, 'should be refused');
    check('same-role approver in a DIFFERENT department is refused', $otherDeptApproveRes['status'], false);
    check('state is still pending_approval after the refused cross-department approve attempt', $runModel->get($flowRunId, $compId)['state'], 'pending_approval');

    echo "=== list()'s per-row can_approve_payroll flag matches canApproveThisRun() (feeds the" .
        " Approval Queue page hiding Approve/Reject/Request Info on a row the viewer can't" .
        " actually act on) ===\n";
    $listForSameDept = $runModel->list($compId, [], $employeeOptOutId, false);
    $rowForSameDeptViewer = array_values(array_filter($listForSameDept, fn($r) => (int)$r['id'] === $flowRunId))[0] ?? null;
    checkTrue('same-department approver sees can_approve_payroll=true for this run', $rowForSameDeptViewer !== null && $rowForSameDeptViewer['can_approve_payroll'] === true);

    $listForOtherDept = $runModel->list($compId, [], $employeeLeaverId, false);
    $rowForOtherDeptViewer = array_values(array_filter($listForOtherDept, fn($r) => (int)$r['id'] === $flowRunId))[0] ?? null;
    checkTrue('different-department, same-role viewer sees can_approve_payroll=false for this run', $rowForOtherDeptViewer !== null && $rowForOtherDeptViewer['can_approve_payroll'] === false);

    $listForAdmin = $runModel->list($compId, [], $adminUserId, true);
    $rowForAdmin = array_values(array_filter($listForAdmin, fn($r) => (int)$r['id'] === $flowRunId))[0] ?? null;
    checkTrue('admin always sees can_approve_payroll=true regardless of department', $rowForAdmin !== null && $rowForAdmin['can_approve_payroll'] === true);

    $listNoActingEmployee = $runModel->list($compId, []);
    $rowNoActingEmployee = array_values(array_filter($listNoActingEmployee, fn($r) => (int)$r['id'] === $flowRunId))[0] ?? [];
    checkTrue('can_approve_payroll key is omitted entirely when no acting employee is passed (backward compatible)', !array_key_exists('can_approve_payroll', $rowNoActingEmployee));

    echo "=== Department-scoping falls back to company-wide when the submitter has no department" .
        " (real bug fix, explicit report: \"ตอนนี้ Set ไว้ที่ Specific User ในหน้า Approve มีรายการ" .
        " แต่พอกดดู timeline No employee in the submitter's department currently holds approval" .
        " permission\" -- traced to a real dev-DB submitter with department_id = NULL) ===\n";
    // A submitter with NO department at all (default for a freshly-created test employee --
    // mirrors the real SSO-provisioned placeholder account that triggered this report).
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en, can_process_payroll, can_approve_payroll, can_finalize_payroll)
        VALUES (:comp_id, 'ทดสอบผู้ส่งไม่มีแผนก', 'Test Submitter No Dept', 1, 0, 0)")->execute([':comp_id' => $compId]);
    $noDeptSubmitterRoleId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id, department_id = NULL WHERE id = :id")
        ->execute([':role_id' => $noDeptSubmitterRoleId, ':id' => $employeeOptOutId]);

    $noDeptRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_NO_DEPT_SUBMITTER_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +19 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +19 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +19 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: no-department-submitter run created' . (empty($noDeptRunRes['status']) ? " ({$noDeptRunRes['message']})" : ''), $noDeptRunRes['status']);
    $noDeptRunId = $noDeptRunRes['id'];
    $runModel->recalculate($noDeptRunId, $compId, $adminUserId, true);
    $noDeptSubmitRes = $runModel->submit($noDeptRunId, $compId, $employeeOptOutId, false);
    checkTrue('no-department employee can still submit', $noDeptSubmitRes['status']);

    $noDeptFlow = $runModel->approvalFlow($noDeptRunId, $compId);
    $noDeptFlowIds = array_map('intval', array_column($noDeptFlow['approvers'], 'id'));
    // Role A was disabled earlier in this file (can_approve_payroll set back to 0), so
    // $employeeMidId is correctly absent either way -- $employeeLeaverId (role B, in
    // $otherDepartmentId, unrelated to this submitter's missing department) is the one that
    // proves the fallback: under a hard department match this would come back empty; with the
    // fallback it must include every can_approve_payroll holder company-wide, same as before
    // department scoping existed at all.
    // (The real dev-DB "Department Manager" holders are soft-deleted for this test's duration --
    // see this file's own fixture setup at the top -- so $employeeLeaverId being present at all
    // here, despite sitting in a completely different department than this submitter, is itself
    // the proof: a hard department match would have excluded it and left the list empty.)
    checkTrue('flow falls back to the company-wide approver list when submitter has no department', in_array($employeeLeaverId, $noDeptFlowIds, true));

    // Approver in $otherDepartmentId (role B, can_approve_payroll=1) -- would be refused under a
    // hard department match against a real submitter department, but the submitter here has none,
    // so this must be allowed.
    $noDeptApproveRes = $runModel->approve($noDeptRunId, $compId, $employeeLeaverId, false, 'fallback should allow this');
    checkTrue('any can_approve_payroll holder can approve a run whose submitter has no department', $noDeptApproveRes['status']);

    $listForNoDeptRun = $runModel->list($compId, [], $employeeLeaverId, false);
    $rowForNoDeptRun = array_values(array_filter($listForNoDeptRun, fn($r) => (int)$r['id'] === $noDeptRunId))[0] ?? null;
    // The run above is now 'approved' (previous line), so re-fetch a fresh still-pending case
    // isn't needed here -- just confirm list() itself didn't blow up and the flag key exists.
    checkTrue('list() still returns the no-department-submitter run without error', $rowForNoDeptRun !== null);

    echo "=== Approval Workflow engine actually wired to PayrollRunModel (explicit bug report:" .
        " \"ใส่คน Approve ไว้แค่คนเดียวแต่ดึงอะไรมาก้ไม่รู้...ในตาราง approval_workflow_steps คุณรู้ใช่" .
        " ไหมว่ามันมีการตั้งค่าส่วนนี้ ทำไมถึงยังดึงไม่ถูก\") -- a workflow configured via" .
        " approval_workflow_steps (single approver_type='user' step) was being completely ignored;" .
        " submit()/approve()/reject()/requestInfo()/revert()/approvalFlow() now all route through" .
        " it when one is active for PAYROLL_RUN_APPROVAL. ===\n";
    require_once __DIR__ . '/../app/models/ApprovalRequestModel.php';
    $approvalRequestModel = new ApprovalRequestModel($pdo);

    // Two dedicated, fresh employees -- the designated approver and an unrelated bystander who
    // must NOT be able to act, to prove this is really gating on the SPECIFIC configured user, not
    // falling back to some broader check.
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_WF_APPROVER_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ผู้อนุมัติเจาะจง', ':name_en' => 'Test', ':surname_en' => 'WorkflowApprover',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => 30000, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $wfApproverId = (int)$pdo->lastInsertId();
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_WF_BYSTANDER_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ไม่เกี่ยวข้อง', ':name_en' => 'Test', ':surname_en' => 'WorkflowBystander',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => 30000, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $wfBystanderId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `approval_workflows` (comp_id, workflow_name, status) VALUES (:comp_id, :workflow_name, 'active')")
        ->execute([':comp_id' => $compId, ':workflow_name' => 'TEST_WF_' . uniqid()]);
    $testWorkflowId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `approval_workflow_document_types` (workflow_id, document_type_code) VALUES (:workflow_id, 'PAYROLL_RUN_APPROVAL')")
        ->execute([':workflow_id' => $testWorkflowId]);
    $pdo->prepare("INSERT INTO `approval_workflow_steps` (workflow_id, step_order, step_name, joint_approve_mode)
        VALUES (:workflow_id, 1, 'Approve', 'any')")
        ->execute([':workflow_id' => $testWorkflowId]);
    $testWfStepId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `approval_workflow_step_approvers` (step_id, approver_type, approver_id)
        VALUES (:step_id, 'user', :approver_id)")
        ->execute([':step_id' => $testWfStepId, ':approver_id' => $wfApproverId]);

    $wfRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_WORKFLOW_ENGINE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +20 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +20 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +20 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: workflow-engine-test run created' . (empty($wfRunRes['status']) ? " ({$wfRunRes['message']})" : ''), $wfRunRes['status']);
    $wfRunId = $wfRunRes['id'];
    $runModel->recalculate($wfRunId, $compId, $adminUserId, true);
    $runModel->submit($wfRunId, $compId, $adminUserId, true);

    $wfRunAfterSubmit = $runModel->get($wfRunId, $compId);
    checkTrue('submit() linked a real approval_request_id (active workflow was configured)', $wfRunAfterSubmit['approval_request_id'] !== null);
    $wfRequest = $approvalRequestModel->get($compId, (int)$wfRunAfterSubmit['approval_request_id']);
    checkTrue('the linked approval_requests row exists', $wfRequest !== null);
    check('linked request is for PAYROLL_RUN_APPROVAL', $wfRequest['document_type_code'], 'PAYROLL_RUN_APPROVAL');
    check('linked request references this run', (int)$wfRequest['reference_id'], $wfRunId);
    check('linked request starts pending', $wfRequest['status'], 'pending');

    echo "=== approvalFlow() shows exactly the ONE configured user (not a role, not a department" .
        " query) ===\n";
    $wfFlow = $runModel->approvalFlow($wfRunId, $compId);
    check('exactly 1 eligible approver', count($wfFlow['approvers']), 1);
    check('it is the specifically-configured user', (int)$wfFlow['approvers'][0]['id'], $wfApproverId);
    check('that approver is marked pending', $wfFlow['approvers'][0]['status'], 'pending');

    echo "=== only the configured user can act -- an unrelated bystander (even with no special" .
        " permission needed, since this is a real Approval Workflow, not the flat role check) is" .
        " refused ===\n";
    $bystanderApproveRes = $runModel->approve($wfRunId, $compId, $wfBystanderId, false, 'should be refused');
    check('bystander cannot approve', $bystanderApproveRes['status'], false);
    check('run is still pending_approval after the refused attempt', $runModel->get($wfRunId, $compId)['state'], 'pending_approval');

    $approverApproveRes = $runModel->approve($wfRunId, $compId, $wfApproverId, false, 'approved via the real workflow');
    checkTrue('the configured user CAN approve' . (empty($approverApproveRes['status']) ? " ({$approverApproveRes['message']})" : ''), $approverApproveRes['status']);
    $wfRunAfterApprove = $runModel->get($wfRunId, $compId);
    check('run state flipped to approved', $wfRunAfterApprove['state'], 'approved');
    check('the linked approval_requests row is now approved too', $approvalRequestModel->get($compId, (int)$wfRunAfterApprove['approval_request_id'])['status'], 'approved');

    echo "=== revert() reopens the linked approval_requests row so it can be decided again ===\n";
    $wfRevertRes = $runModel->revert($wfRunId, $compId, $wfApproverId, false);
    checkTrue('the same configured approver can undo their own approval' . (empty($wfRevertRes['status']) ? " ({$wfRevertRes['message']})" : ''), $wfRevertRes['status']);
    $wfRunAfterRevert = $runModel->get($wfRunId, $compId);
    check('run state back to pending_approval', $wfRunAfterRevert['state'], 'pending_approval');
    $reopenedRequest = $approvalRequestModel->get($compId, (int)$wfRunAfterRevert['approval_request_id']);
    check('linked request re-opened to pending', $reopenedRequest['status'], 'pending');
    check('linked request back at step 1', (int)$reopenedRequest['current_step_order'], 1);

    echo "=== reject() also routes through the engine ===\n";
    $wfRejectRes = $runModel->reject($wfRunId, $compId, $wfApproverId, false, 'needs changes');
    checkTrue('the configured approver can reject via the real workflow' . (empty($wfRejectRes['status']) ? " ({$wfRejectRes['message']})" : ''), $wfRejectRes['status']);
    check('run state flipped to rejected', $runModel->get($wfRunId, $compId)['state'], 'rejected');

    echo "=== requestInfo() gates on the same engine (act() has no need_info verb of its own) ===\n";
    // Bring it back to pending_approval to test requestInfo() specifically.
    $runModel->revert($wfRunId, $compId, $wfApproverId, false);
    $bystanderNeedInfoRes = $runModel->requestInfo($wfRunId, $compId, $wfBystanderId, false, 'should be refused');
    check('bystander cannot request info', $bystanderNeedInfoRes['status'], false);
    $approverNeedInfoRes = $runModel->requestInfo($wfRunId, $compId, $wfApproverId, false, 'need the OT sheet');
    checkTrue('the configured approver can request info' . (empty($approverNeedInfoRes['status']) ? " ({$approverNeedInfoRes['message']})" : ''), $approverNeedInfoRes['status']);
    check('run state flipped to need_info', $runModel->get($wfRunId, $compId)['state'], 'need_info');

    echo "=== 2026-08-24 fix: a joint 'any'-mode step's visibility/button must disappear for a" .
        " co-approver once someone ELSE in the same pool has already decided it, UNLESS that person" .
        " is ALSO eligible on a different still-open step of the same request (explicit bug report:" .
        " \"ถ้าเรามีสิทธิ์ แต่เป็นสิทธิ์ร่วมกับคนอื่นในแถวเดียวกัน แล้วอีกคนอนุมัติไปแล้ว รายการนั้นจะต้องไม่เห็น" .
        " ...ยกเว้นเราจะมีสิทธิ์ในแถวอนุมัติอื่นที่ยังสามารถมองเห็นได้\" -- canActOnRequest() used to gate the" .
        " Approve/Reject/Request Info buttons AND the Approval Queue row itself, but it only checks" .
        " 'was this user EVER eligible on ANY row', ignoring whether that row is still 'pending';" .
        " canActOnRequestNow() fixes that). Two AND-group steps so the request stays pending after" .
        " step 1 alone is decided (step 2 still open) -- lets step 1's OTHER pool member's" .
        " visibility be checked while the run is still genuinely pending_approval. ===\n";
    foreach (['X', 'Y', 'Z', 'W'] as $label) {
        $insEmp->execute([
            ':comp_id' => $compId, ':employee_no' => "TEST_WF_JOINT_{$label}_" . uniqid(),
            ':name_th' => 'ทดสอบ', ':surname_th' => "ร่วมอนุมัติ{$label}", ':name_en' => 'Test', ':surname_en' => "JointApprover{$label}",
            ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
            ':employee_status_enum' => 'permanent',
            ':base_salary' => 30000, ':salary_effective_date' => '2020-01-01',
            ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
        ]);
        $$label = (int)$pdo->lastInsertId(); // $X, $Y, $Z, $W employee ids
    }
    // Retire the single-approver step from the earlier test and replace it with a fresh 2-step,
    // joint 'any' config: step 1 pool = {X, Y, W}, step 2 pool = {Z, W} -- W straddles both.
    $pdo->prepare("UPDATE `approval_workflow_steps` SET status = 'deleted' WHERE id = :id")->execute([':id' => $testWfStepId]);
    $pdo->prepare("INSERT INTO `approval_workflow_steps` (workflow_id, step_order, step_name, joint_approve_mode)
        VALUES (:workflow_id, 1, 'Joint Step 1', 'any')")->execute([':workflow_id' => $testWorkflowId]);
    $jointStep1Id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `approval_workflow_steps` (workflow_id, step_order, step_name, joint_approve_mode)
        VALUES (:workflow_id, 2, 'Joint Step 2', 'any')")->execute([':workflow_id' => $testWorkflowId]);
    $jointStep2Id = (int)$pdo->lastInsertId();
    $insStepApprover = $pdo->prepare("INSERT INTO `approval_workflow_step_approvers` (step_id, approver_type, approver_id) VALUES (:step_id, 'user', :approver_id)");
    foreach ([$X, $Y, $W] as $empId) { $insStepApprover->execute([':step_id' => $jointStep1Id, ':approver_id' => $empId]); }
    foreach ([$Z, $W] as $empId) { $insStepApprover->execute([':step_id' => $jointStep2Id, ':approver_id' => $empId]); }

    $jointRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_JOINT_STEP_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +22 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +22 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +22 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: joint-step-test run created' . (empty($jointRunRes['status']) ? " ({$jointRunRes['message']})" : ''), $jointRunRes['status']);
    $jointRunId = $jointRunRes['id'];
    $runModel->recalculate($jointRunId, $compId, $adminUserId, true);
    $runModel->submit($jointRunId, $compId, $adminUserId, true);

    echo "--- before anyone acts: X, Y, W (step 1 pool) and Z, W (step 2 pool) are all currently" .
        " actionable; someone outside every pool is not ---\n";
    $jointRunRow = $runModel->get($jointRunId, $compId);
    check('X can act now (eligible, step 1 pending & unlocked)', $runModel->canApprovePayroll($X, false, $jointRunRow), true);
    check('Y can act now (same reason)', $runModel->canApprovePayroll($Y, false, $jointRunRow), true);
    check('Z can act now (eligible, step 2 pending & unlocked)', $runModel->canApprovePayroll($Z, false, $jointRunRow), true);
    check('W can act now (eligible on both steps)', $runModel->canApprovePayroll($W, false, $jointRunRow), true);
    check('the unrelated bystander cannot act at all', $runModel->canApprovePayroll($wfBystanderId, false, $jointRunRow), false);
    $listBeforeX = $runModel->list($compId, [], $X, false, true);
    checkTrue('approval-queue list() includes the run for X before any decision', in_array((int)$jointRunId, array_map('intval', array_column($listBeforeX, 'id')), true));
    $listBeforeBystander = $runModel->list($compId, [], $wfBystanderId, false, true);
    checkTrue('approval-queue list() excludes the run for the bystander from the start', !in_array((int)$jointRunId, array_map('intval', array_column($listBeforeBystander, 'id')), true));

    echo "--- X approves step 1 (joint 'any' -- first action decides the whole pool's row) ---\n";
    $xApproveRes = $runModel->approve($jointRunId, $compId, $X, false, 'X decides for the pool');
    checkTrue('X (in the pool) can approve step 1' . (empty($xApproveRes['status']) ? " ({$xApproveRes['message']})" : ''), $xApproveRes['status']);
    check('run stays pending_approval (step 2 -- an AND-group step -- is still open)', $runModel->get($jointRunId, $compId)['state'], 'pending_approval');
    $jointRunAfterX = $runModel->get($jointRunId, $compId);

    echo "--- Y shared the SAME row with X; now that X decided it, Y must lose visibility/the" .
        " button entirely (Y has no other open step) -- the actual bug report ---\n";
    check('Y can no longer act (their only step was just decided by X)', $runModel->canApprovePayroll($Y, false, $jointRunAfterX), false);
    $yApproveAttempt = $runModel->approve($jointRunId, $compId, $Y, false, 'Y tries after X already decided it');
    check('Y is REFUSED server-side too if they try anyway (defense in depth)', $yApproveAttempt['status'], false);
    $listAfterXForY = $runModel->list($compId, [], $Y, false, true);
    checkTrue('approval-queue list() no longer includes the run for Y', !in_array((int)$jointRunId, array_map('intval', array_column($listAfterXForY, 'id')), true));

    echo "--- W shared step 1 with X too, but is ALSO eligible on step 2 (still open) -- W must" .
        " stay visible/actionable via that other step (the explicit exception) ---\n";
    check('W can still act (via step 2, even though their step-1 row is decided)', $runModel->canApprovePayroll($W, false, $jointRunAfterX), true);
    $listAfterXForW = $runModel->list($compId, [], $W, false, true);
    checkTrue('approval-queue list() still includes the run for W', in_array((int)$jointRunId, array_map('intval', array_column($listAfterXForW, 'id')), true));

    echo "--- Z's step (2) was never touched -- unaffected by step 1's decision ---\n";
    check('Z can still act (step 2 untouched)', $runModel->canApprovePayroll($Z, false, $jointRunAfterX), true);
    $zApproveRes = $runModel->approve($jointRunId, $compId, $Z, false, 'Z closes out step 2');
    checkTrue('Z can approve step 2' . (empty($zApproveRes['status']) ? " ({$zApproveRes['message']})" : ''), $zApproveRes['status']);
    check('both AND-group steps now decided -- run flips to approved', $runModel->get($jointRunId, $compId)['state'], 'approved');
    $jointRunAfterZ = $runModel->get($jointRunId, $compId);
    echo "--- once fully decided, Undo Decision must still be available to anyone who was EVER" .
        " part of the flow (the coarser check on purpose -- unlike the tightened pending-side" .
        " check above) ---\n";
    check('Y (never actually decided anything) can still undo/revert the outcome', $runModel->canApprovePayroll($Y, false, $jointRunAfterZ), true);

    echo "=== 2026-08-24 fix, round 2: admin session no longer bypasses an ACTIVE configured" .
        " workflow (explicit repro from the user -- logged in as employee 28, session role" .
        " 'admin', which is NOT in the configured PAYROLL_RUN_APPROVAL flow (only employee 190" .
        " is), yet can_approve_payroll still came back true and the Approve button still worked)." .
        " Admin keeps its bypass ONLY on the flat fallback (no workflow configured at all) --" .
        " tested separately below. ===\n";
    $adminOutsideFlowRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_ADMIN_NOT_IN_FLOW_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +23 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +23 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +23 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: admin-not-in-flow-test run created' . (empty($adminOutsideFlowRunRes['status']) ? " ({$adminOutsideFlowRunRes['message']})" : ''), $adminOutsideFlowRunRes['status']);
    $adminOutsideFlowRunId = $adminOutsideFlowRunRes['id'];
    $runModel->recalculate($adminOutsideFlowRunId, $compId, $adminUserId, true);
    $runModel->submit($adminOutsideFlowRunId, $compId, $adminUserId, true);
    $adminOutsideFlowRun = $runModel->get($adminOutsideFlowRunId, $compId);
    checkTrue('setup: this run IS routed through the engine (approval_request_id set)', $adminOutsideFlowRun['approval_request_id'] !== null);

    check('admin (not a configured approver on this workflow) sees can_approve_payroll=false', $runModel->canApprovePayroll($adminUserId, true, $adminOutsideFlowRun), false);
    $listForAdminApprovalQueue = $runModel->list($compId, [], $adminUserId, true, true);
    checkTrue('approval-queue list() excludes this run for admin too', !in_array((int)$adminOutsideFlowRunId, array_map('intval', array_column($listForAdminApprovalQueue, 'id')), true));
    $adminApproveAttempt = $runModel->approve($adminOutsideFlowRunId, $compId, $adminUserId, true, 'admin trying to bypass the configured flow');
    check('admin is REFUSED server-side (approve)', $adminApproveAttempt['status'], false);
    check('run is untouched -- still pending_approval', $runModel->get($adminOutsideFlowRunId, $compId)['state'], 'pending_approval');
    $adminRejectAttempt = $runModel->reject($adminOutsideFlowRunId, $compId, $adminUserId, true, 'admin trying to bypass the configured flow');
    check('admin is REFUSED server-side (reject)', $adminRejectAttempt['status'], false);
    $adminNeedInfoAttempt = $runModel->requestInfo($adminOutsideFlowRunId, $compId, $adminUserId, true, 'admin trying to bypass the configured flow');
    check('admin is REFUSED server-side (request info)', $adminNeedInfoAttempt['status'], false);

    echo "--- the actually-configured approver (X, from step 1's pool above) can still decide it" .
        " normally -- this run's flow just happens to reuse the same 2-step AND-group config, so" .
        " BOTH steps need a real approver before the run itself flips to approved ---\n";
    $realApproverStep1Res = $runModel->approve($adminOutsideFlowRunId, $compId, $X, false, 'the real approver decides step 1');
    checkTrue('the genuinely eligible approver can decide step 1' . (empty($realApproverStep1Res['status']) ? " ({$realApproverStep1Res['message']})" : ''), $realApproverStep1Res['status']);
    check('run stays pending_approval (step 2 still open)', $runModel->get($adminOutsideFlowRunId, $compId)['state'], 'pending_approval');
    $realApproverStep2Res = $runModel->approve($adminOutsideFlowRunId, $compId, $Z, false, 'the real approver decides step 2');
    checkTrue('the genuinely eligible approver can decide step 2' . (empty($realApproverStep2Res['status']) ? " ({$realApproverStep2Res['message']})" : ''), $realApproverStep2Res['status']);
    check('run is now approved (both AND-group steps decided)', $runModel->get($adminOutsideFlowRunId, $compId)['state'], 'approved');

    echo "--- once approved, admin STILL cannot undo it (not a configured approver) -- Undo" .
        " Decision is approver-only, admin included, once a workflow governs the run ---\n";
    $adminAfterApprove = $runModel->get($adminOutsideFlowRunId, $compId);
    check('admin cannot see/click Undo Decision either', $runModel->canApprovePayroll($adminUserId, true, $adminAfterApprove), false);
    $adminRevertAttempt = $runModel->revert($adminOutsideFlowRunId, $compId, $adminUserId, true, 'admin trying to undo without being in the flow');
    check('admin is REFUSED server-side (revert/undo)', $adminRevertAttempt['status'], false);

    echo "--- admin STILL bypasses everything on the flat fallback (no active workflow at all) --" .
        " confirms the fix is scoped to engine-routed runs only, not a blanket admin nerf ---\n";
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'inactive' WHERE id = :id")->execute([':id' => $testWorkflowId]);
    $adminFlatFallbackRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_ADMIN_FLAT_FALLBACK_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +24 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +24 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +24 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $adminFlatFallbackRunId = $adminFlatFallbackRunRes['id'];
    $runModel->recalculate($adminFlatFallbackRunId, $compId, $adminUserId, true);
    $runModel->submit($adminFlatFallbackRunId, $compId, $adminUserId, true);
    $adminFlatFallbackRun = $runModel->get($adminFlatFallbackRunId, $compId);
    check('setup: this run is NOT routed through the engine (no active workflow)', $adminFlatFallbackRun['approval_request_id'], null);
    check('admin still sees can_approve_payroll=true when no workflow is configured', $runModel->canApprovePayroll($adminUserId, true, $adminFlatFallbackRun), true);
    $adminFlatApproveRes = $runModel->approve($adminFlatFallbackRunId, $compId, $adminUserId, true, 'admin approves via the legacy flat fallback');
    checkTrue('admin can still approve via the flat fallback' . (empty($adminFlatApproveRes['status']) ? " ({$adminFlatApproveRes['message']})" : ''), $adminFlatApproveRes['status']);
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'active' WHERE id = :id")->execute([':id' => $testWorkflowId]);

    echo "=== a run with NO workflow configured still falls back to the flat role check" .
        " (backward compatibility -- confirms this integration didn't break the pre-existing" .
        " company-wide fallback path) ===\n";
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'inactive' WHERE id = :id")->execute([':id' => $testWorkflowId]);
    $noWfRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_NO_WORKFLOW_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +21 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +21 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +21 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $noWfRunId = $noWfRunRes['id'];
    $runModel->recalculate($noWfRunId, $compId, $adminUserId, true);
    $runModel->submit($noWfRunId, $compId, $adminUserId, true);
    check('no approval_request_id linked once the workflow is inactive', $runModel->get($noWfRunId, $compId)['approval_request_id'], null);

    echo "=== 2026-08-26: recalculate() includes/excludes Recurring Earnings (EmployeeRecurringEarningModel) ===\n";
    // See tests/employee_recurring_earning_test.php for the model's own validation/activeForPeriod()
    // unit coverage -- this section only confirms recalculate() actually wires it into a real run's
    // earning_breakdown, and that a date-range suspend actually excludes it for the overlapping run.
    require_once __DIR__ . '/../app/models/EmployeeRecurringEarningModel.php';
    $recEmpStmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0)");
    $recEmpStmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_RECEARN_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายรับประจำ', ':name_en' => 'Test', ':surname_en' => 'RecurringEarning',
        ':email' => uniqid() . '@test.local',
    ]);
    $recEmployeeId = (int)$pdo->lastInsertId();

    $recTypeModel = new PayrollEarningDeductionTypeModel($pdo);
    $recTypeRes = $recTypeModel->save($compId, [
        'item_code' => 'RECTEST1', 'item_name_th' => 'ค่าตำแหน่งทดสอบ', 'item_name_en' => 'Test Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 2000, 'tax_treatment' => 'taxable',
    ], $adminUserId);
    checkTrue('fixture: recurring-earning catalog type created', $recTypeRes['status']);
    $recTypeId = $recTypeRes['id'];

    $recEarningModel = new EmployeeRecurringEarningModel($pdo);
    $recAssignRes = $recEarningModel->save($recEmployeeId, $compId, [
        'ped_type_id' => $recTypeId, 'amount' => 1200, 'effective_date' => '2020-01-01',
    ], $adminUserId);
    checkTrue('fixture: recurring earning assigned to the employee', $recAssignRes['status']);
    $recAssignmentId = $recAssignRes['id'];

    // Run 1 (+30 months, no suspend window yet) -- the recurring earning should be included.
    $recRun1PeriodStart = (clone $today)->modify('first day of +30 months')->format('Y-m-d');
    $recRun1PeriodEnd = (clone $today)->modify('last day of +30 months')->format('Y-m-d');
    $recRun1Res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_RECEARN_ACTIVE_' . uniqid(),
        'period_start_date' => $recRun1PeriodStart, 'period_end_date' => $recRun1PeriodEnd, 'payment_date' => $recRun1PeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run 1 created', $recRun1Res['status']);
    $recRun1Id = $recRun1Res['id'];
    $runModel->recalculate($recRun1Id, $compId, $adminUserId, true);
    $recRun1Detail = current(array_filter($runModel->getDetails($recRun1Id, $compId), fn($d) => (int)$d['employee_id'] === $recEmployeeId));
    checkTrue('run 1: the employee has a calculated row', $recRun1Detail !== false);
    $recRun1Line = current(array_filter($recRun1Detail['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    checkTrue('run 1: a recurring_earning line is present (no suspend window yet)', $recRun1Line !== false);
    check('run 1: the line carries the right amount', (float)$recRun1Line['amount'], 1200.0);
    check('run 1: the line carries the catalog item_code', $recRun1Line['code'], 'RECTEST1');
    check('run 1: gross = base(30000) + recurring earning(1200)', (float)$recRun1Detail['gross_amount'], 31200.0);

    // Suspend the allowance for a window overlapping Run 2's period (+31 months) but NOT Run 1's.
    $recRun2PeriodStart = (clone $today)->modify('first day of +31 months')->format('Y-m-d');
    $recRun2PeriodEnd = (clone $today)->modify('last day of +31 months')->format('Y-m-d');
    $recSuspendRes = $recEarningModel->save($recEmployeeId, $compId, [
        'id' => $recAssignmentId, 'ped_type_id' => $recTypeId, 'amount' => 1200, 'effective_date' => '2020-01-01',
        'suspended_from' => $recRun2PeriodStart, 'suspended_to' => $recRun2PeriodEnd,
    ], $adminUserId);
    checkTrue('fixture: allowance suspended for run 2\'s exact period', $recSuspendRes['status']);

    $recRun2Res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_RECEARN_SUSPENDED_' . uniqid(),
        'period_start_date' => $recRun2PeriodStart, 'period_end_date' => $recRun2PeriodEnd, 'payment_date' => $recRun2PeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run 2 created', $recRun2Res['status']);
    $recRun2Id = $recRun2Res['id'];
    $runModel->recalculate($recRun2Id, $compId, $adminUserId, true);
    $recRun2Detail = current(array_filter($runModel->getDetails($recRun2Id, $compId), fn($d) => (int)$d['employee_id'] === $recEmployeeId));
    $recRun2Line = current(array_filter($recRun2Detail['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    check('run 2: the recurring_earning line is EXCLUDED (suspend window covers this run\'s period)', $recRun2Line !== false, false);
    check('run 2: gross = base(30000) only, allowance suspended', (float)$recRun2Detail['gross_amount'], 30000.0);

    // Run 3 (+32 months, after the suspend window ends) -- resumes automatically, no re-activation step.
    $recRun3PeriodStart = (clone $today)->modify('first day of +32 months')->format('Y-m-d');
    $recRun3PeriodEnd = (clone $today)->modify('last day of +32 months')->format('Y-m-d');
    $recRun3Res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_RECEARN_RESUMED_' . uniqid(),
        'period_start_date' => $recRun3PeriodStart, 'period_end_date' => $recRun3PeriodEnd, 'payment_date' => $recRun3PeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run 3 created', $recRun3Res['status']);
    $recRun3Id = $recRun3Res['id'];
    $runModel->recalculate($recRun3Id, $compId, $adminUserId, true);
    $recRun3Detail = current(array_filter($runModel->getDetails($recRun3Id, $compId), fn($d) => (int)$d['employee_id'] === $recEmployeeId));
    $recRun3Line = current(array_filter($recRun3Detail['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_earning'));
    checkTrue('run 3: the recurring_earning line resumes automatically after the suspend window ends', $recRun3Line !== false);
    check('run 3: gross = base(30000) + recurring earning(1200) again', (float)$recRun3Detail['gross_amount'], 31200.0);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
