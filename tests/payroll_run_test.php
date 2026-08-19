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

    // ---------- Eligibility branching: a genuine off-cycle run (no cycle, no sync) has NO
    // automatic membership at all -- only employees explicitly Joined are included. ----------
    echo "=== Eligibility: off-cycle run has no employees until Joined ===\n";
    $offCalcRes = $runModel->recalculate($offCycleRunId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds on the off-cycle run (even with zero employees)' . (empty($offCalcRes['status']) ? " ({$offCalcRes['message']})" : ''), $offCalcRes['status']);
    check('off-cycle run has 0 employees before anyone is Joined', $offCalcRes['employee_count'], 0);

    echo "=== joinEmployees() / removeManualEmployee() guards ===\n";
    $joinOnCycleRes = $runModel->joinEmployees($runId, $compId, [$employeeOptOutId], $adminUserId, true);
    check('joinEmployees() rejected on a cycle-based run', $joinOnCycleRes['status'], false);
    $joinOnPulledRes = $runModel->joinEmployees($pulledRunId, $compId, [$employeeOptOutId], $adminUserId, true);
    check('joinEmployees() rejected on a Pending-Pull run', $joinOnPulledRes['status'], false);
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

    $removeOnCycleRes = $runModel->removeManualEmployee($runId, $compId, $employeeFullId, $adminUserId, true);
    check('removeManualEmployee() rejected on a cycle-based run', $removeOnCycleRes['status'], false);

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
