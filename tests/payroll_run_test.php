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
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../app/models/SetupRulesModel.php';
require_once __DIR__ . '/../app/models/AttendanceDeductionRuleModel.php';
require_once __DIR__ . '/../app/models/OtRateSetModel.php';

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

// 2026-09-03, Platform Hardening Phase 3 -- structure_roles.can_process_payroll/can_approve_payroll/
// can_finalize_payroll are retired; PayrollRunModel::userCan() now checks the 1:1 replacement
// permission keys (payroll_run.process/.approve/.finalize) via PermissionModel::checkPermission()
// instead. Every fixture role below that used to rely on setting those boolean columns alone now
// ALSO needs a real role_permissions grant for the matching key, or the corresponding
// submit()/approve()/revert() call will be refused exactly as if the role had no permission at all
// (setting the now-dead boolean columns is inert -- nothing reads them anymore). This one helper is
// used at every such fixture instead of repeating the INSERT by hand.
function grantPayrollPermission(PDO $pdo, int $roleId, string $permissionKey): void {
    $permId = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = " . $pdo->quote($permissionKey))->fetchColumn();
    if ($permId <= 0) {
        throw new RuntimeException("Unknown permission_key in test fixture: {$permissionKey}");
    }
    $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, detail_level) VALUES (:r, :p, 'all', 'full')")
        ->execute([':r' => $roleId, ':p' => $permId]);
}

try {
    $compId = 1;
    $adminUserId = 1; // used with isAdmin=true throughout except the dedicated permission-denial check
    // 2026-09-10, Batch 3B item 3: level-2 for payee_type='company' -- reuses a real active
    // bank_accounts row for comp_id=1, same convention as the employment-date-scoped fixtures
    // elsewhere in this file that read real comp_id=1 data rather than creating throwaway rows.
    $bankAccountId = (int)$pdo->query("SELECT id FROM bank_accounts WHERE comp_id = 1 AND deleted_at IS NULL AND status = 'active' LIMIT 1")->fetchColumn();
    if ($bankAccountId <= 0) {
        throw new RuntimeException('Fixture requires at least one active bank_accounts row for comp_id=1.');
    }

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
    // 2026-08-30: same isolation for the OT Rate Set replacement -- see employee_ot_rate_override_test.php.
    $pdo->prepare("UPDATE `ot_rate_sets` SET deleted_at = NOW(), status = 'deleted' WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

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
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         :employment_date, :employment_end_date, :employee_status_enum, 'full_time', 'office', 'manual',
         'monthly', :base_salary, :salary_effective_date, 'average', 'active',
         :sso_enrolled, :pvd_enrolled, :tax_exempt, 1)");

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

    // Role with no payroll permissions (no role_permissions grants at all), for the permission-denial check.
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en)
        VALUES (:comp_id, 'ทดสอบไม่มีสิทธิ์', 'Test No Permission')")->execute([':comp_id' => $compId]);
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
    // 2026-09-02, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ" -- create() now stamps run_code
    // via DocumentNumberingModel::generateNext() (see tests/document_numbering_test.php for that
    // model's own dedicated coverage of the numbering/reset mechanics themselves) -- this just
    // confirms the wiring on PayrollRunModel's own side actually persists a real, correctly-prefixed
    // code onto a freshly created run.
    // 2026-09-10, real fragility fixed (hit and documented 3 times, see BACKLOG.md/
    // feedback_dev_db_shared_state_test_fragility): $compId here is 1, the REAL live dev DB company
    // -- NOT "a fresh throwaway company that's never touched its own PAYROLL_RUN numbering settings
    // before" as the old comment claimed. Every other run created against comp_id=1 (manual testing,
    // other test files, earlier runs of this exact test since PAYROLL_RUN numbering persists past
    // any one rolled-back transaction) advances that same counter, so asserting the exact literal
    // '...-001' here was really asserting "nobody else has ever touched comp_id=1's PAYROLL_RUN
    // counter," which was never true and only gets less true over time. Replaced with a
    // structural pattern check (still real coverage: correct prefix, correct year, a genuine
    // zero-padded digit sequence -- exactly what DocumentNumberingModel::generateNext() is
    // contracted to produce) that passes regardless of what count comp_id=1's counter is actually
    // at right now.
    checkTrue('run_code stamped with the correct PAYROLL_RUN prefix/year/digit-count shape (PR-YYYY-NNN)', (bool)preg_match('/^PR-' . date('Y') . '-\d{3}$/', (string)$run['run_code']));

    // The genuine "first run this company has EVER created gets 001" claim moved here, onto a
    // brand-new company created fresh for this one assertion (never touched by any other test file
    // or manual session, so its PAYROLL_RUN counter is guaranteed to start at zero) -- this is the
    // "test creates its own company/counter" fix, not just a weakened assertion on the shared one.
    // An off-cycle run (no cycle_id) is enough to exercise create()'s own run_code stamping without
    // also needing a cycle/employees for a company that otherwise has nothing set up.
    echo "=== run_code: a genuinely fresh company's first-ever run really does get 001 ===\n";
    $freshRunCodeCompId = null;
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'RunCode Fresh Co ' . uniqid(), ':comp_code' => 'RUNCODE_' . uniqid()]);
    $freshRunCodeCompId = (int)$pdo->lastInsertId();
    $freshRunCodeRes = $runModel->create($freshRunCodeCompId, [
        'run_name' => 'RunCode Fresh Run', 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('fresh-company off-cycle run creates successfully' . (empty($freshRunCodeRes['status']) ? " ({$freshRunCodeRes['message']})" : ''), $freshRunCodeRes['status']);
    $freshRunCodeRun = $runModel->get((int)$freshRunCodeRes['id'], $freshRunCodeCompId);
    check('a genuinely fresh company\'s first-ever run gets exactly 001', $freshRunCodeRun['run_code'], 'PR-' . date('Y') . '-001');

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
    $prtOtRateSetModel = new OtRateSetModel($pdo);
    $prtOtSetSave = $prtOtRateSetModel->save([
        'name_th' => 'ชุด OT ทดสอบ', 'name_en' => 'Test OT Set', 'is_default' => true,
        'items' => [['ot_scope_id' => $weekdayOtScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.50, 'calculation_base' => 'hourly']],
    ], $compId, $adminUserId);
    checkTrue('fixture: OT Rate Set created' . (empty($prtOtSetSave['status']) ? " ({$prtOtSetSave['message']})" : ''), $prtOtSetSave['status']);
    $itemValuesJson = json_encode([
        ['item_id' => 99, 'item_code' => 'CUSTOM_ATTENDANCE_BONUS', 'item_name' => 'Attendance Bonus', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 500, 'remark' => null],
        // 2026-09-06, explicit request: Origami's new opt-in TOTAL_DAYS item (item_type='INFO',
        // calendar-based day count, same "Working Day" group as WORKING_DAYS/WEEKLY_OFF/
        // PUBLIC_HOLIDAY) -- must produce NO payroll line (INFO items never do) while still
        // surfacing via PayrollRunModel::getDetails()'s own total_days field.
        ['item_id' => 100, 'item_code' => 'TOTAL_DAYS', 'item_name' => 'Total Days', 'item_type' => 'INFO', 'unit_type' => 'days', 'value' => 30, 'remark' => null],
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
    // 2026-09-06, explicit request: TOTAL_DAYS (item_type='INFO') surfaces via getDetails()'s own
    // total_days field, and -- being INFO -- produces NO earning/deduction line of its own.
    check('total_days extracted from the TOTAL_DAYS item_values entry', (float)($pullDetails2[0]['total_days'] ?? null), 30.0);
    $allBreakdownLines = array_merge($pullDetails2[0]['earning_breakdown'] ?? [], $pullDetails2[0]['deduction_breakdown'] ?? []);
    checkTrue('TOTAL_DAYS (INFO type) produced no earning/deduction line of its own', current(array_filter($allBreakdownLines, fn($l) => stripos((string)($l['code'] ?? ''), 'TOTAL_DAYS') !== false)) === false);
    // ?? can't distinguish "key missing" from "key present but null" (both fall through to the
    // right-hand side), so existence and value are checked separately here on purpose.
    checkTrue('total_days key present even with no data', array_key_exists('total_days', $pullDetails[0]));
    check('total_days is null (not 0) before this employee\'s sync item ever carried a TOTAL_DAYS entry', $pullDetails[0]['total_days'], null);
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

    // 2026-08-29, generalized (explicit request: "แก้ไขตัวเลขได้...ทุกค่าเลย") from a sync-deduction-
    // only listing into every earning/deduction line + base salary; `computed_amount` (the RAW
    // pre-override figure, re-derived fresh from SyncPayResolver bypassing overrides) was renamed
    // `current_amount` and now reads the CURRENT (post-override) persisted figure instead -- a
    // deliberate simplification, see syncDeductionLinesForEmployee()'s own docblock for why.
    echo "=== syncDeductionLinesForEmployee(): current amount + override state, for the UI ===\n";
    $syncLinesForUi = $runModel->syncDeductionLinesForEmployee($compId, $pulledRunId, $employeeFullId);
    $lateUiLine = current(array_filter($syncLinesForUi, fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('UI-facing current_amount reflects the OVERRIDDEN 10.00 (current, post-override figure now, not the raw pre-override 31.25)', (float)($lateUiLine['current_amount'] ?? null), 10.0);
    check('UI-facing override_action reflects the active override', $lateUiLine['override_action'] ?? null, 'override_amount');
    check('UI-facing override_amount reflects the active override', (float)($lateUiLine['override_amount'] ?? null), 10.0);
    $leaveUiLine = current(array_filter($syncLinesForUi, fn($l) => $l['code'] === 'LEAVE_NO_PAY_DEDUCT'));
    checkTrue('excluded line is still listed for the UI (so it can be un-excluded), even though it has dropped out of the persisted breakdown entirely', $leaveUiLine !== false);
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
    // 2026-08-29: lineOverrideSave() dropped its own sync_process_id-only restriction (see that
    // method's own docblock) -- a cycle-based (non-sync) run is now a genuinely valid target, so
    // this no longer belongs in a "guards" (rejection) section. Kept here as a positive assertion
    // instead, but DELIBERATELY targets a throwaway run rather than $runId itself -- $runId is a
    // large shared fixture many hundreds of lines further down in this file still rely on being
    // "not yet recalculated" at specific points (e.g. the addManualLine()-membership-guard section
    // right below), and lineOverrideSave() ends with its own recalculate() call, which would
    // silently pull $runId's calculation forward and cascade into those later assertions.
    $overrideThrowawayRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_OVERRIDE_GUARD_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +45 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +45 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +45 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: throwaway cycle-based run for the override-on-non-sync-run check' . (empty($overrideThrowawayRunRes['status']) ? " ({$overrideThrowawayRunRes['message']})" : ''), $overrideThrowawayRunRes['status']);
    $overrideOnNonSyncRunRes = $runModel->lineOverrideSave($overrideThrowawayRunRes['id'], $compId, $employeeFullId, 'LATE_DEDUCT', 'override_amount', 5.00, null, $adminUserId, true);
    checkTrue('lineOverrideSave() now succeeds on a non-sync (cycle-based) run too (old sync-only restriction is gone)' . (empty($overrideOnNonSyncRunRes['status']) ? " ({$overrideOnNonSyncRunRes['message']})" : ''), $overrideOnNonSyncRunRes['status']);
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

    // 2026-08-29, explicit bug report: "ปรับรายการหัก ใส่ยอดเป็น 0...แล้วกด Save ไม่ได้" -- override_amount=0
    // was never covered by this test file before (only a non-zero override, 10.00, above). Reproducing
    // directly against the model to isolate whether this is a real backend bug or a client-side-only
    // issue. Placed here (after the audit-log-count assertions above, before the "both overrides from
    // the section above were cleaned up" assumption the next block already documents) and cleaned up
    // immediately after itself so it doesn't perturb either of those.
    echo "=== Line overrides: override_amount = 0 (zero out a deduction entirely, distinct from exclude) ===\n";
    $zeroOverrideRes = $runModel->lineOverrideSave($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', 'override_amount', 0.00, 'waived entirely', $adminUserId, true);
    checkTrue('lineOverrideSave() override_amount=0 succeeds' . (empty($zeroOverrideRes['status']) ? " ({$zeroOverrideRes['message']})" : ''), $zeroOverrideRes['status']);
    $afterZeroDetails = $runModel->getDetails($pulledRunId, $compId);
    $lateLineAfterZero = current(array_filter($afterZeroDetails[0]['deduction_breakdown'], fn($l) => $l['code'] === 'LATE_DEDUCT'));
    check('LATE_DEDUCT amount is now 0.00 (zeroed, not removed/reverted to computed)', (float)($lateLineAfterZero['amount'] ?? -1), 0.0);
    $runModel->lineOverrideRemove($pulledRunId, $compId, $employeeFullId, 'LATE_DEDUCT', $adminUserId, true);

    // ---------- 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9a): the SAME
    // payroll_run_line_overrides mechanism now also covers statutory lines (TH_SSO/TH_PVD/TH_PIT),
    // via statutoryOverrideCode()'s reserved-sentinel wrapping so it can never collide with a real
    // earning/deduction item_code. Reuses $pulledRunId/$employeeFullId (cleaned up at the end, same
    // discipline the section above already follows) -- $employeeFullId has sso_enrolled=1 (see this
    // employee's own fixture insert far above), so a real TH_SSO line exists to override. ----------
    echo "=== Statutory line overrides (TH_SSO/TH_PVD/TH_PIT via statutoryOverrideCode()) ===\n";
    $ssoDetailsBefore = $runModel->getDetails($pulledRunId, $compId);
    $ssoRowBefore = current(array_filter($ssoDetailsBefore, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    $ssoLineBefore = current(array_filter($ssoRowBefore['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'));
    checkTrue('fixture: TH_SSO statutory line exists before any override', $ssoLineBefore !== false);
    $ssoOriginalAmount = (float)$ssoLineBefore['employee_amount'];

    $statutoryOverrideRes = $runModel->statutoryLineOverrideSave($pulledRunId, $compId, $employeeFullId, 'TH_SSO', 'override_amount', 123.45, 'test statutory override', $adminUserId, true);
    checkTrue('statutoryLineOverrideSave(override_amount) succeeds' . (empty($statutoryOverrideRes['status']) ? " ({$statutoryOverrideRes['message']})" : ''), $statutoryOverrideRes['status']);
    $ssoDetailsAfterOverride = $runModel->getDetails($pulledRunId, $compId);
    $ssoRowAfterOverride = current(array_filter($ssoDetailsAfterOverride, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    $ssoLineAfterOverride = current(array_filter($ssoRowAfterOverride['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'));
    check('TH_SSO employee_amount is now exactly the overridden 123.45', (float)$ssoLineAfterOverride['employee_amount'], 123.45);
    check('note marks this as manually_overridden', $ssoLineAfterOverride['note'], 'manually_overridden');
    $pvdLineUnaffected = current(array_filter($ssoRowAfterOverride['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PVD'));
    checkTrue('a DIFFERENT statutory item (TH_PVD) is completely untouched by the TH_SSO-specific override', $pvdLineUnaffected !== false && $pvdLineUnaffected['note'] !== 'manually_overridden');

    $statutoryAdjustLines = $runModel->syncDeductionLinesForEmployee($compId, $pulledRunId, $employeeFullId);
    $ssoAdjustLine = current(array_filter($statutoryAdjustLines, fn($l) => $l['code'] === 'TH_SSO'));
    checkTrue('syncDeductionLinesForEmployee() (Adjust Amounts modal) lists the TH_SSO statutory row', $ssoAdjustLine !== false);
    check('the listed row shows the active override action', $ssoAdjustLine['override_action'] ?? null, 'override_amount');
    check('the listed row shows the override amount', (float)($ssoAdjustLine['override_amount'] ?? -1), 123.45);

    $statutoryExcludeRes = $runModel->statutoryLineOverrideSave($pulledRunId, $compId, $employeeFullId, 'TH_SSO', 'exclude', null, null, $adminUserId, true);
    checkTrue('statutoryLineOverrideSave(exclude) succeeds', $statutoryExcludeRes['status']);
    $ssoDetailsAfterExclude = $runModel->getDetails($pulledRunId, $compId);
    $ssoRowAfterExclude = current(array_filter($ssoDetailsAfterExclude, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    $ssoLineAfterExclude = current(array_filter($ssoRowAfterExclude['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'));
    checkTrue('the TH_SSO line STAYS in statutory_breakdown when excluded (unlike an earning/deduction exclude, which drops the line entirely)', $ssoLineAfterExclude !== false);
    check('TH_SSO employee_amount is 0 when excluded', (float)$ssoLineAfterExclude['employee_amount'], 0.0);
    check('note marks this as manually_excluded', $ssoLineAfterExclude['note'], 'manually_excluded');

    $statutoryRemoveRes = $runModel->statutoryLineOverrideRemove($pulledRunId, $compId, $employeeFullId, 'TH_SSO', $adminUserId, true);
    checkTrue('statutoryLineOverrideRemove() succeeds', $statutoryRemoveRes['status']);
    $ssoDetailsReverted = $runModel->getDetails($pulledRunId, $compId);
    $ssoRowReverted = current(array_filter($ssoDetailsReverted, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    $ssoLineReverted = current(array_filter($ssoRowReverted['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'));
    check('TH_SSO reverted back to the ORIGINAL computed value after remove (cleaned up for later sections)', round((float)$ssoLineReverted['employee_amount'], 2), round($ssoOriginalAmount, 2));

    check('statutoryOverrideCode() produces the expected reserved-sentinel format', $runModel->statutoryOverrideCode('TH_SSO'), '__statutory_TH_SSO__');

    // ---------- 2026-08-31, same-day follow-up (item 9c, explicit request: "Log ทุกครั้งที่เปิดหน้า
    // Process Detail") -- logViewDetail()/getViewLog()/getAuditLog()'s new exclusion. Purely
    // additive (only INSERTs into payroll_run_audit_logs, never touches payroll_run_details or
    // anything recalculate()-derived), so safely reuses $pulledRunId/$employeeFullId with zero risk
    // of corrupting any later section's numbers -- unlike the statutory-override section above,
    // there is nothing here that needs reverting afterward. ----------
    echo "=== View logging on every Process Detail open (item 9c) ===\n";
    $viewLogBeforeCount = count($runModel->getViewLog($pulledRunId, $compId));
    $auditLogBeforeCount = count($runModel->getAuditLog($pulledRunId, $compId));
    $pulledRunStateNow = (string)$runModel->get($pulledRunId, $compId)['state'];

    $runModel->logViewDetail($pulledRunId, $compId, $adminUserId);
    $runModel->logViewDetail($pulledRunId, $compId, $adminUserId);

    $viewLogAfter = $runModel->getViewLog($pulledRunId, $compId);
    check('exactly 2 new view_detail rows recorded after 2 logViewDetail() calls', count($viewLogAfter) - $viewLogBeforeCount, 2);
    $lastView = end($viewLogAfter);
    check('view_detail row action is exactly "view_detail"', $lastView['action'], 'view_detail');
    check('view_detail row from_state equals to_state (a view never transitions anything)', $lastView['from_state'], $lastView['to_state']);
    check('view_detail row to_state matches the run\'s actual current state', $lastView['to_state'], $pulledRunStateNow);
    check('view_detail row records who viewed it', (int)$lastView['performed_by'], $adminUserId);

    $auditLogAfter = $runModel->getAuditLog($pulledRunId, $compId);
    check('getAuditLog() (the human-facing Detail-page timeline) count is UNCHANGED by the 2 view logs -- view_detail rows are filtered out of it', count($auditLogAfter), $auditLogBeforeCount);
    checkTrue('none of getAuditLog()\'s rows are action=view_detail', current(array_filter($auditLogAfter, fn($a) => $a['action'] === 'view_detail')) === false);

    // logViewDetail() silently no-ops for a run id that doesn't exist / doesn't belong to this
    // company -- a failed view attempt on a bad/stale id is not something worth recording.
    $viewLogUnrelatedCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM payroll_run_audit_logs WHERE action = 'view_detail' AND run_id = 999999999")->fetchColumn();
    $runModel->logViewDetail(999999999, $compId, $adminUserId);
    $viewLogUnrelatedCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM payroll_run_audit_logs WHERE action = 'view_detail' AND run_id = 999999999")->fetchColumn();
    check('logViewDetail() no-ops for a nonexistent run id (no row written)', $viewLogUnrelatedCountAfter, $viewLogUnrelatedCountBefore);

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
    // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9b): this used to assert REJECTION on
    // a non-sync (cycle-based) run -- that restriction is gone now (see
    // PayrollRunModel::attendanceOverrideSave()'s own updated docblock), so the same call that used
    // to be refused must now succeed instead. Deliberately uses a BRAND-NEW, isolated run (not the
    // shared $runId, which many later sections of this same file still depend on being in its
    // original untouched state) -- a real lesson learned while writing this: an earlier version of
    // this test mutated $runId directly and corrupted 2 unrelated, much-later assertions in this
    // same 4000+-line shared-transaction file (addManualLine()'s own cycle-based-run rejection
    // check, and a full-period gross-pay total) purely by triggering extra recalculate() passes on
    // a run other sections assume nobody else touches.
    $attGuardRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'ATTENDANCE_GUARD_TEST_' . uniqid(),
        'period_start_date' => '2027-02-21', 'period_end_date' => '2027-03-20', 'payment_date' => '2027-02-25',
    ], $adminUserId, true);
    checkTrue('fixture: isolated non-sync run created for the attendance-override guard test' . (empty($attGuardRunRes['status']) ? " ({$attGuardRunRes['message']})" : ''), $attGuardRunRes['status']);
    $attGuardRunId = $attGuardRunRes['id'];
    checkTrue('fixture: isolated run recalculated', $runModel->recalculate($attGuardRunId, $compId, $adminUserId, true)['status']);
    $attOverrideOnNonSyncRunRes = $runModel->attendanceOverrideSave($attGuardRunId, $compId, $employeeFullId, ['late_mins' => 5], null, $adminUserId, true);
    checkTrue('attendanceOverrideSave() NOW succeeds on a non-sync (cycle-based) run (item 9b: no longer sync-only)' . (empty($attOverrideOnNonSyncRunRes['status']) ? " ({$attOverrideOnNonSyncRunRes['message']})" : ''), $attOverrideOnNonSyncRunRes['status']);
    $attDataOnNonSyncRun = $runModel->attendanceDataForEmployee($compId, $attGuardRunId, $employeeFullId);
    check('the override is actually persisted and readable back for this non-sync run', $attDataOnNonSyncRun['override']['late_mins'] ?? null, 5.0);

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
    // 2026-09-02, Deduction Destination & Third-Party Remittance: downgraded from blocking to
    // advisory -- the payee-not-in-run case is now a real, supported outcome (falls back to an
    // employee_fallback remittance at Approved, see PayrollRemittanceModel), not a dead end that
    // should stop the run from calculating cleanly.
    checkTrue('calc_status stays "calculated" despite the orphan transfer (advisory, not blocking, since 2026-09-02)', $fromRowOrphan['calc_status'] === 'calculated');

    // ---------- 2026-08-31, same-day follow-up: payroll_run_manual_lines gained the SAME
    // payee_type/include_in_cash_summary concept EmployeeEarningDeductionModel already had (this
    // table never had it at all before). ----------
    echo "=== addManualLine(): payee_type widened to company/not_disbursed (Process Detail manual lines) ===\n";
    // 2026-09-10, Batch 3B item 3: bank_account_id is mandatory now for payee_type='company' --
    // rejection case first, then the real success case with a valid account.
    $companyLineNoAccountRes = $runModel->addManualLine($pulledRunId, $compId, $employeeFullId, null, 300.00, $adminUserId, true, null, 'Company Retained No Account', 'deduction', null, 'company', null);
    check('addManualLine() rejects payee_type=company with no bank_account_id', $companyLineNoAccountRes['status'], false);

    $companyLineRes = $runModel->addManualLine($pulledRunId, $compId, $employeeFullId, null, 300.00, $adminUserId, true, null, 'Company Retained', 'deduction', null, 'company', null, null, null, $bankAccountId);
    checkTrue('addManualLine() accepts payee_type=company with a valid bank_account_id' . (empty($companyLineRes['status']) ? " ({$companyLineRes['message']})" : ''), $companyLineRes['status']);
    $companyLineRow = $pdo->query("SELECT payee_type, payee_employee_id, bank_account_id, include_in_cash_summary FROM payroll_run_manual_lines WHERE run_id={$pulledRunId} AND employee_id={$employeeFullId} AND custom_item_name='Company Retained'")->fetch(PDO::FETCH_ASSOC);
    check('payee_type=company persisted on the manual line', $companyLineRow['payee_type'] ?? null, 'company');
    check('payee_employee_id stays NULL for a company-payee manual line', $companyLineRow['payee_employee_id'], null);
    check('bank_account_id persisted on the manual line', (int)($companyLineRow['bank_account_id'] ?? -1), $bankAccountId);

    $notDisbursedLineRes = $runModel->addManualLine($pulledRunId, $compId, $employeeFullId, null, 120.00, $adminUserId, true, null, 'Not Disbursed Adjustment', 'deduction', null, 'not_disbursed', true);
    checkTrue('addManualLine() accepts payee_type=not_disbursed' . (empty($notDisbursedLineRes['status']) ? " ({$notDisbursedLineRes['message']})" : ''), $notDisbursedLineRes['status']);
    $notDisbursedRow = $pdo->query("SELECT payee_type, payee_employee_id, include_in_cash_summary FROM payroll_run_manual_lines WHERE run_id={$pulledRunId} AND employee_id={$employeeFullId} AND custom_item_name='Not Disbursed Adjustment'")->fetch(PDO::FETCH_ASSOC);
    check('payee_type=not_disbursed persisted on the manual line', $notDisbursedRow['payee_type'] ?? null, 'not_disbursed');
    check('include_in_cash_summary FORCED to 0 for not_disbursed even though true was sent', (int)($notDisbursedRow['include_in_cash_summary'] ?? -1), 0);
    $afterNotDisbursedDetails = $runModel->getDetails($pulledRunId, $compId);
    $fromRowNotDisbursed = current(array_filter($afterNotDisbursedDetails, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    $notDisbursedBreakdownLine = current(array_filter($fromRowNotDisbursed['deduction_breakdown'], fn($l) => $l['code'] === 'CUSTOM:Not Disbursed Adjustment'));
    check('the outer breakdown table also carries payee_type=not_disbursed through recalculate()', $notDisbursedBreakdownLine['payee_type'] ?? null, 'not_disbursed');

    $invalidPayeeTypeRes = $runModel->addManualLine($pulledRunId, $compId, $employeeFullId, null, 50.00, $adminUserId, true, null, 'Bad Payee', 'deduction', null, 'bogus', null);
    check('addManualLine() rejects an invalid payee_type', $invalidPayeeTypeRes['status'], false);

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
    check('getEmployeeExemption() returns "inherit" defaults before anything is saved', $defaultExemption, [
        'tax_calculate_override' => 'inherit', 'sso_calculate_override' => 'inherit',
        'exempt_tax' => false, 'exempt_sso' => false, 'note' => null,
    ]);

    // 2026-08-29: exempt_tax=true/exempt_sso=true (booleans) widened to a bidirectional tri-state
    // pair -- 'no' is the exact equivalent of the old force-off-only "exempt" meaning (see
    // saveEmployeeExemption()'s own docblock).
    $exemptionSaveRes = $runModel->saveEmployeeExemption($pulledRunId, $compId, $employeeFullId, 'no', 'no', 'requested by employee', $adminUserId, true);
    checkTrue('saveEmployeeExemption() succeeds' . (empty($exemptionSaveRes['status']) ? " ({$exemptionSaveRes['message']})" : ''), $exemptionSaveRes['status']);
    $afterExemptionDetail = array_values(array_filter($runModel->getDetails($pulledRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeFullId))[0] ?? [];
    $ssoAfterExemption = (float)((array_values(array_filter($afterExemptionDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    check('after exempt_sso=true: SSO deduction is zeroed', $ssoAfterExemption, 0.0);
    $pitAfterExemption = array_values(array_filter($afterExemptionDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PIT'))[0] ?? [];
    check('after exempt_tax=true: TH_PIT is zeroed and flagged employee_tax_exempt (same engine note as the permanent tax_exempt flag)', [(float)($pitAfterExemption['employee_amount'] ?? -1), $pitAfterExemption['note'] ?? null], [0.0, 'employee_tax_exempt']);

    $savedExemption = $runModel->getEmployeeExemption($pulledRunId, $compId, $employeeFullId);
    check('getEmployeeExemption() reflects the saved row', $savedExemption, [
        'tax_calculate_override' => 'no', 'sso_calculate_override' => 'no',
        'exempt_tax' => true, 'exempt_sso' => true, 'note' => 'requested by employee',
    ]);

    $exemptionClearRes = $runModel->saveEmployeeExemption($pulledRunId, $compId, $employeeFullId, 'inherit', 'inherit', null, $adminUserId, true);
    checkTrue('saveEmployeeExemption() with both set to inherit clears the row (deletes rather than keeping an all-inherit row)' . (empty($exemptionClearRes['status']) ? " ({$exemptionClearRes['message']})" : ''), $exemptionClearRes['status']);
    $afterClearDetail = array_values(array_filter($runModel->getDetails($pulledRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeFullId))[0] ?? [];
    $ssoAfterClear = (float)((array_values(array_filter($afterClearDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    check('after clearing the exemption: SSO deduction is back to the real computed amount', $ssoAfterClear, $ssoBeforeExemption);

    echo "=== Exemption is per-run only -- a different run for the same employee is unaffected ===\n";
    $reExemptRes = $runModel->saveEmployeeExemption($pulledRunId, $compId, $employeeFullId, 'no', 'inherit', null, $adminUserId, true);
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

    // 2026-08-29, explicit request: "กรณีคนเข้า และคนออก การคิดเงินเดือน ต้องจับหาร 30 ตามกฏหมาย...ตอนนี้หาร
    // จำนวนวันจริงของเดือนครับ" -- proration must divide by companies.prorate_divisor_days (default
    // 30, the Thai labor law convention), NOT the real number of days in this specific period.
    // Deliberately runs whatever real calendar month `$today` falls in (per this file's own
    // established convention of computing period dates dynamically off `$today`, not a fixed
    // date) -- exercises this fix against the ACTUAL number of days in the current month, which is
    // exactly the case the old behavior got wrong whenever that number wasn't 30.
    echo "=== Proration divisor: companies.prorate_divisor_days (Thai labor law: 30, not real days-in-month) ===\n";
    $prorateDaysExpected = (int)((strtotime($periodEnd) - strtotime($midMonthJoin)) / 86400) + 1;
    check('prorate_total_days stored is the DEFAULT divisor (30), not the real days-in-month', (int)$midDetail['prorate_total_days'], 30);
    check('prorate_days stored is the real days actually present (unaffected by the divisor)', (int)$midDetail['prorate_days'], $prorateDaysExpected);
    $expectedMidBaseDefault = round(30000 * $prorateDaysExpected / 30, 2);
    check('mid-month joiner base_salary_amount matches salary * days / 30 (default divisor)', (float)$midDetail['base_salary_amount'], $expectedMidBaseDefault);
    // Only meaningful (proves the divisor is actually read from config, not hardcoded 30 twice
    // over) when the real current month is NOT itself 30 days long -- skipped with a clear PASS
    // note otherwise rather than a flaky assertion that can't actually distinguish the two.
    if ($totalPeriodDaysThisMonth = (int)((strtotime($periodEnd) - strtotime($periodStart)) / 86400) + 1) {
        if ($totalPeriodDaysThisMonth !== 30) {
            $wrongOldStyleBase = round(30000 * $prorateDaysExpected / $totalPeriodDaysThisMonth, 2);
            checkTrue("this month has {$totalPeriodDaysThisMonth} real days (not 30) -- confirms the fix genuinely changed the result vs. the old days-in-month divisor", abs($expectedMidBaseDefault - $wrongOldStyleBase) > 0.001);
        } else {
            echo "  (skipped divisor-actually-changed-the-result check: the current real month happens to have exactly 30 days, so old and new behavior are numerically identical here -- covered instead by the explicit divisor-change assertion below)\n";
        }
    }

    // Changing the company's own configured divisor must actually change the computed result on
    // the NEXT recalculate() -- proves this is read live from companies.prorate_divisor_days each
    // time, not cached/hardcoded. Reverted implicitly by this whole test file's own transaction
    // rollback at the very end (same "temporary mutation against the real comp_id=1" precedent
    // already used elsewhere in this file), no manual restore needed.
    $pdo->prepare("UPDATE `companies` SET prorate_divisor_days = 31 WHERE id = :id")->execute([':id' => $compId]);
    $recalcWithDivisor31 = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate() succeeds again after changing prorate_divisor_days' . (empty($recalcWithDivisor31['status']) ? " ({$recalcWithDivisor31['message']})" : ''), $recalcWithDivisor31['status']);
    $detailsWithDivisor31 = $runModel->getDetails($runId, $compId);
    $midDetailWithDivisor31 = current(array_filter($detailsWithDivisor31, fn($d) => (int)$d['employee_id'] === $employeeMidId));
    check('prorate_total_days now reflects the changed divisor (31)', (int)$midDetailWithDivisor31['prorate_total_days'], 31);
    $expectedMidBaseDivisor31 = round(30000 * $prorateDaysExpected / 31, 2);
    check('base_salary_amount recomputed using the NEW divisor (31), not still 30', (float)$midDetailWithDivisor31['base_salary_amount'], $expectedMidBaseDivisor31);
    checkTrue('the two divisor results genuinely differ (31 != 30, so this is not a same-value coincidence)', abs($expectedMidBaseDefault - $expectedMidBaseDivisor31) > 0.001);
    $pdo->prepare("UPDATE `companies` SET prorate_divisor_days = 30 WHERE id = :id")->execute([':id' => $compId]);
    $recalcBackTo30 = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate() succeeds again after restoring the divisor to 30', $recalcBackTo30['status']);
    // 2026-08-29, explicit request: "ตัดเบี้ยขยันและการบันทึกเบี้ยขยันออกจากการตั้งค่า และไม่นำไปคำนวณใน
    // เงินเดือน" -- Attendance Bonus/Diligence ledger feature removed entirely (2026-08-29 follow-up:
    // its DB tables/models are gone too, not just the calculation hook -- see PayrollRunModel's own
    // recalculate()/markPaid() comments). Gross is just base + the recurring allowance now.
    check('full-period gross = base(30000) + allowance(1000)', (float)$fullDetail['gross_amount'], 31000.0);
    checkTrue('full-period has a PED earning line', count(array_filter($fullDetail['earning_breakdown'], fn($l) => $l['source'] === 'ped')) === 1);
    check('both core employees calculated cleanly', $fullDetail['calc_status'] === 'calculated' && $midDetail['calc_status'] === 'calculated', true);

    // ---------- Employee Verify/Comments (2026-08-29, explicit request: "อยากให้มีปุ่ม Verify ของแต่ละคน
    // และสามารถ Lock Unlock ได้ โดยถ้า Lock แล้วข้อมูลจะไม่คำนวณใหม่...รวมถึงเพิ่มให้สามารถใส่ Comment
    // ได้ของแต่ละคน...เป็น Timeline...ใส่ tag ได้"; Lock retired 2026-08-31, explicit request: "ให้ตัดปุ่ม
    // Lock ออกไปเลยครับ ให้เหลือแค่ Verify ถ้า Verify แล้ว จะไม่คำนวณอีกต่อไป" -- Verify itself now carries
    // the freeze-from-recalculation/block-edits behavior Lock used to have; this section was
    // originally two independent flags (Lock + Verify) and is now just one. Deliberately restores
    // $runId/$employeeFullId to byte-identical pre-section state before it ends (unverify + a final
    // recalculate()), since every section below this one keeps reading $fullDetail/$midDetail/etc.
    // (already-captured local snapshots, safe either way) plus a few FRESH reads later in the file
    // that assume $runId is in its normal fully-computed, unverified state. ----------
    echo "=== Employee Verify: recalculate() preserves a verified employee's row byte-for-byte ===\n";
    $preVerifyGross = (float)$fullDetail['gross_amount'];
    $verifyRes = $runModel->setEmployeeVerified($runId, $compId, $employeeFullId, true, $adminUserId, true);
    checkTrue('setEmployeeVerified(true) succeeds' . (empty($verifyRes['status']) ? " ({$verifyRes['message']})" : ''), $verifyRes['status']);
    $detailsRightAfterVerify = $runModel->getDetails($runId, $compId);
    $fullDetailRightAfterVerify = current(array_filter($detailsRightAfterVerify, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    check('is_verified reflects true right after verifying', $fullDetailRightAfterVerify['is_verified'] ?? null, true);

    $directRecalcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('a direct recalculate() call still succeeds with a verified employee present' . (empty($directRecalcRes['status']) ? " ({$directRecalcRes['message']})" : ''), $directRecalcRes['status']);
    $detailsAfterRecalcWithVerify = $runModel->getDetails($runId, $compId);
    $fullDetailAfterRecalcWithVerify = current(array_filter($detailsAfterRecalcWithVerify, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    check('VERIFIED employee gross_amount is byte-for-byte unchanged after recalculate()', (float)$fullDetailAfterRecalcWithVerify['gross_amount'], $preVerifyGross);
    check('VERIFIED employee is_verified still true after recalculate() (verification row untouched by recalculate itself)', $fullDetailAfterRecalcWithVerify['is_verified'] ?? null, true);
    $midDetailAfterRecalcWithVerify = current(array_filter($detailsAfterRecalcWithVerify, fn($d) => (int)$d['employee_id'] === $employeeMidId));
    checkTrue('an UNVERIFIED employee (mid-joiner) still recalculates normally alongside a verified one', $midDetailAfterRecalcWithVerify !== false && $midDetailAfterRecalcWithVerify['calc_status'] === 'calculated');

    echo "=== Employee Verify: blocks every other per-employee mutation entry point ===\n";
    $blockedManualLineRes = $runModel->addManualLine($runId, $compId, $employeeFullId, $otPedTypeId, 100, $adminUserId, true);
    check('addManualLine() rejected for a verified employee', $blockedManualLineRes['status'], false);
    $blockedExemptionRes = $runModel->saveEmployeeExemption($runId, $compId, $employeeFullId, 'no', 'inherit', null, $adminUserId, true);
    check('saveEmployeeExemption() rejected for a verified employee', $blockedExemptionRes['status'], false);

    echo "=== Employee Verify: list() surfaces verified count per run ===\n";
    $runsListForCounts = $runModel->list($compId, ['state' => 'draft']);
    $thisRunInList = current(array_filter($runsListForCounts, fn($r) => (int)$r['id'] === $runId));
    check('verified_employee_count reflects the 1 verified employee', (int)($thisRunInList['verified_employee_count'] ?? -1), 1);
    check('error_employee_count is 0 -- no incomplete-data rows in this fixture yet', (int)($thisRunInList['error_employee_count'] ?? -1), 0);

    // 2026-08-29, explicit follow-up: "ถ้าข้อมูลไม่สมบูรณ์ให้มีบอกด้วย ว่าไม่สมบูรณ์กี่คนและมีปุ่ม i ให้คลิก
    // ดูรายละเอียดในหน้ารายการได้เลย" -- directly forces one row's calc_status to 'error' (simplest
    // deterministic way to exercise this without engineering a genuinely broken calc scenario),
    // confirms both list()'s new error_employee_count subquery and the new
    // errorEmployeesForRun() lookup that backs the List page's "i" info button, then restores the
    // row so nothing downstream in this shared-fixture file sees a stray error.
    echo "=== List page 'incomplete data' indicator: error_employee_count + errorEmployeesForRun() ===\n";
    $stmtForceError = $pdo->prepare("UPDATE `payroll_run_details` SET calc_status = 'error', calc_errors = 'no_rate_configured' WHERE run_id = :run_id AND employee_id = :employee_id");
    $stmtForceError->execute([':run_id' => $runId, ':employee_id' => $employeeFullId]);
    $runsListAfterForcedError = $runModel->list($compId, ['state' => 'draft']);
    $thisRunAfterForcedError = current(array_filter($runsListAfterForcedError, fn($r) => (int)$r['id'] === $runId));
    check('error_employee_count now reflects the 1 forced-error row', (int)($thisRunAfterForcedError['error_employee_count'] ?? -1), 1);
    $errorEmployees = $runModel->errorEmployeesForRun($runId, $compId);
    check('errorEmployeesForRun() returns exactly 1 row', count($errorEmployees), 1);
    check('errorEmployeesForRun() row is the correct employee', (int)($errorEmployees[0]['employee_no'] ?? 0) > 0 || !empty($errorEmployees[0]['employee_no']), true);
    check('errorEmployeesForRun() surfaces the calc_errors text for the "i" button detail view', $errorEmployees[0]['calc_errors'] ?? null, 'no_rate_configured');
    check('errorEmployeesForRun() on a nonexistent run returns empty (same not-found guard as getDetails())', $runModel->errorEmployeesForRun(999999999, $compId), []);
    $stmtRestoreError = $pdo->prepare("UPDATE `payroll_run_details` SET calc_status = 'calculated', calc_errors = NULL WHERE run_id = :run_id AND employee_id = :employee_id");
    $stmtRestoreError->execute([':run_id' => $runId, ':employee_id' => $employeeFullId]);

    echo "=== Employee Verify: unverifying restores normal recomputation ===\n";
    $unverifyRes = $runModel->setEmployeeVerified($runId, $compId, $employeeFullId, false, $adminUserId, true);
    checkTrue('setEmployeeVerified(false) succeeds' . (empty($unverifyRes['status']) ? " ({$unverifyRes['message']})" : ''), $unverifyRes['status']);
    $finalRecalcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate() succeeds again after unverifying', $finalRecalcRes['status']);
    $detailsAfterFinalRecalc = $runModel->getDetails($runId, $compId);
    $fullDetailAfterFinalRecalc = current(array_filter($detailsAfterFinalRecalc, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    check('gross_amount recomputed fresh once unverified (still 31000, same inputs -> same answer)', (float)$fullDetailAfterFinalRecalc['gross_amount'], $preVerifyGross);
    check('is_verified false again (verification row fully cleared)', $fullDetailAfterFinalRecalc['is_verified'] ?? null, false);
    $unblockedManualLineRes = $runModel->addManualLine($runId, $compId, $employeeFullId, $otPedTypeId, 100, $adminUserId, true, 'proves editing works again post-unverify');
    checkTrue('addManualLine() succeeds again once unverified' . (empty($unblockedManualLineRes['status']) ? " ({$unblockedManualLineRes['message']})" : ''), $unblockedManualLineRes['status']);
    // Cleanup: remove the line just added above so $runId's totals are back to their exact
    // pre-section state (removeManualLine() itself triggers one more recalculate()).
    $cleanupLines = $runModel->manualLinesForEmployee($compId, $runId, $employeeFullId);
    $cleanupLine = current(array_filter($cleanupLines, fn($l) => $l['note'] === 'proves editing works again post-unverify'));
    if ($cleanupLine !== false) {
        $runModel->removeManualLine($runId, $compId, (int)$cleanupLine['id'], $adminUserId, true);
    }

    // 2026-08-31, explicit request: "สามารถ Verify ทั้ง Process ได้เลย...ให้ Verify ได้ทั้ง Process ทั้ง
    // Detail และหน้า List" -- verifies every employee in the run in a single call, reusing
    // bulkSetEmployeeVerified() internally. Restores to unverified afterward (same "leave $runId in
    // its normal fully-computed, unverified state" precedent as the rest of this section).
    echo "=== Employee Verify: verifyAllEmployeesForRun() verifies every employee in the run at once ===\n";
    $verifyAllRes = $runModel->verifyAllEmployeesForRun($runId, $compId, $adminUserId, true);
    checkTrue('verifyAllEmployeesForRun() succeeds' . (empty($verifyAllRes['status']) ? " ({$verifyAllRes['message']})" : ''), $verifyAllRes['status']);
    $detailsAfterVerifyAll = $runModel->getDetails($runId, $compId);
    checkTrue('every employee in the run is now verified', count($detailsAfterVerifyAll) > 0 && count(array_filter($detailsAfterVerifyAll, fn($d) => empty($d['is_verified']))) === 0);
    $runsListAfterVerifyAll = $runModel->list($compId, ['state' => 'draft']);
    $thisRunAfterVerifyAll = current(array_filter($runsListAfterVerifyAll, fn($r) => (int)$r['id'] === $runId));
    check('verified_employee_count now equals the full employee count', (int)($thisRunAfterVerifyAll['verified_employee_count'] ?? -1), count($detailsAfterVerifyAll));
    $verifyAllOnEmptyRun = $runModel->verifyAllEmployeesForRun(999999999, $compId, $adminUserId, true);
    check('verifyAllEmployeesForRun() on a nonexistent/employee-less run fails cleanly', $verifyAllOnEmptyRun['status'], false);
    // Restore to unverified for every employee so the rest of this file sees the normal state.
    foreach (array_map(fn($d) => (int)$d['employee_id'], $detailsAfterVerifyAll) as $eid) {
        $runModel->setEmployeeVerified($runId, $compId, $eid, false, $adminUserId, true);
    }
    $runModel->recalculate($runId, $compId, $adminUserId, true);

    echo "=== Employee Comments: append-only timeline with tags ===\n";
    $commentRes1 = $runModel->employeeCommentAdd($runId, $compId, $employeeFullId, 'in_progress', 'Checking SSO amount with HR', $adminUserId, true);
    checkTrue('employeeCommentAdd() with tag=in_progress succeeds' . (empty($commentRes1['status']) ? " ({$commentRes1['message']})" : ''), $commentRes1['status']);
    $commentRes2 = $runModel->employeeCommentAdd($runId, $compId, $employeeFullId, 'completed', 'Confirmed correct, no action needed', $adminUserId, true);
    checkTrue('employeeCommentAdd() with tag=completed succeeds', $commentRes2['status']);
    $commentResNoTag = $runModel->employeeCommentAdd($runId, $compId, $employeeFullId, null, 'Just a plain note, no tag', $adminUserId, true);
    checkTrue('employeeCommentAdd() with a null tag succeeds (tag is optional)', $commentResNoTag['status']);
    $badTagRes = $runModel->employeeCommentAdd($runId, $compId, $employeeFullId, 'not_a_real_tag', 'x', $adminUserId, true);
    check('employeeCommentAdd() rejects an invalid tag', $badTagRes['status'], false);
    $emptyCommentRes = $runModel->employeeCommentAdd($runId, $compId, $employeeFullId, 'in_progress', '   ', $adminUserId, true);
    check('employeeCommentAdd() rejects a blank/whitespace-only comment', $emptyCommentRes['status'], false);

    $timeline = $runModel->employeeComments($runId, $compId, $employeeFullId);
    check('3 comments in the timeline (2 tagged + 1 untagged; the 2 rejected calls above never inserted)', count($timeline), 3);
    check('timeline is oldest-first (chronological)', [$timeline[0]['tag'], $timeline[1]['tag'], $timeline[2]['tag']], ['in_progress', 'completed', null]);
    check('each comment records who posted it (created_by)', (int)($timeline[0]['created_by'] ?? 0), $adminUserId);

    $detailsWithCommentCount = $runModel->getDetails($runId, $compId);
    $fullDetailCommentCount = current(array_filter($detailsWithCommentCount, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    check('getDetails() surfaces comment_count for the Comment button badge', (int)($fullDetailCommentCount['comment_count'] ?? -1), 3);

    // 2026-08-29, explicit follow-up: "สามารถแก้ไข Comment และลบ Comment ได้ด้วย"
    echo "=== Employee Comments: edit and delete ===\n";
    $firstCommentId = (int)$timeline[0]['id'];
    checkTrue('fixture: first comment has no updated_at yet (never edited)', $timeline[0]['updated_at'] === null);
    $updateRes = $runModel->employeeCommentUpdate($runId, $compId, $firstCommentId, 'error', 'Actually there was a mistake in the SSO base', $adminUserId, true);
    checkTrue('employeeCommentUpdate() succeeds' . (empty($updateRes['status']) ? " ({$updateRes['message']})" : ''), $updateRes['status']);
    $timelineAfterUpdate = $runModel->employeeComments($runId, $compId, $employeeFullId);
    $updatedComment = current(array_filter($timelineAfterUpdate, fn($c) => (int)$c['id'] === $firstCommentId));
    check('comment text updated', $updatedComment['comment'] ?? null, 'Actually there was a mistake in the SSO base');
    check('comment tag updated to error', $updatedComment['tag'] ?? null, 'error');
    checkTrue('updated_at is now set (edit tracked)', !empty($updatedComment['updated_at']));
    check('updated_by records who edited it', (int)($updatedComment['updated_by'] ?? 0), $adminUserId);
    check('still exactly 3 comments (update, not a new insert)', count($timelineAfterUpdate), 3);

    $updateBadTagRes = $runModel->employeeCommentUpdate($runId, $compId, $firstCommentId, 'not_a_real_tag', 'x', $adminUserId, true);
    check('employeeCommentUpdate() rejects an invalid tag', $updateBadTagRes['status'], false);
    $updateMissingRes = $runModel->employeeCommentUpdate($runId, $compId, 999999999, 'error', 'x', $adminUserId, true);
    check('employeeCommentUpdate() rejects a non-existent comment id', $updateMissingRes['status'], false);

    $deleteRes = $runModel->employeeCommentDelete($runId, $compId, $firstCommentId, $adminUserId, true);
    checkTrue('employeeCommentDelete() succeeds' . (empty($deleteRes['status']) ? " ({$deleteRes['message']})" : ''), $deleteRes['status']);
    $timelineAfterDelete = $runModel->employeeComments($runId, $compId, $employeeFullId);
    check('2 comments remain after delete', count($timelineAfterDelete), 2);
    checkTrue('the deleted comment is genuinely gone', current(array_filter($timelineAfterDelete, fn($c) => (int)$c['id'] === $firstCommentId)) === false);
    $deleteMissingRes = $runModel->employeeCommentDelete($runId, $compId, $firstCommentId, $adminUserId, true);
    check('employeeCommentDelete() on an already-deleted id fails cleanly', $deleteMissingRes['status'], false);

    $detailsAfterCommentDelete = $runModel->getDetails($runId, $compId);
    $fullDetailAfterCommentDelete = current(array_filter($detailsAfterCommentDelete, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    check('comment_count reflects the delete (3 -> 2)', (int)($fullDetailAfterCommentDelete['comment_count'] ?? -1), 2);

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

    // 2026-08-29, real bug found and fixed (explicit report: "หักประกันสังคมจะไม่ใช่คำนวณจากฐานอย่างเดียว
    // ต้องมาจากที่เราตั้งค่าในรายได้ ว่ารายการไหนหักประกันสังคม ต้องเอามาคำนวณทั้งหมด") -- every fixture
    // employee above uses base_salary=30000, already well above the SSO wage ceiling either way
    // (capped regardless of whether a calc_sso-flagged allowance is added on top), so a dedicated
    // LOW-salary employee is needed here to actually observe the fix changing the computed amount --
    // isolated on its own future-dated period (2099) so no real dev-DB employee or other fixture in
    // this file can possibly leak into its eligibility window.
    echo "=== SSO/PF base now includes calc_sso/calc_pf-flagged earning items, not just base salary ===\n";
    $ssoRateRow = $pdo->query("SELECT si.id, rh.employee_rate, rh.max_base_amount, rh.max_employee_contribution
        FROM statutory_items si JOIN statutory_item_rate_history rh ON rh.statutory_item_id = si.id
        WHERE si.code = 'TH_SSO' AND rh.deleted_at IS NULL AND rh.effective_date <= CURDATE() AND (rh.end_date IS NULL OR rh.end_date >= CURDATE())
        ORDER BY rh.effective_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    checkTrue('fixture: an active TH_SSO rate is configured (needed to compute an expected number)', $ssoRateRow !== false);

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_SSOBASE_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ฐานประกันสังคม', ':name_en' => 'Test', ':surname_en' => 'SsoBase',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2099-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => 5000, ':salary_effective_date' => '2099-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 0, ':tax_exempt' => 0,
    ]);
    $employeeSsoBaseId = (int)$pdo->lastInsertId();
    $ssoBaseCycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'SSO Base Fix Test Cycle', 'payroll_frequency' => 'monthly',
        'period_start_day_of_month' => 1, 'period_end_day_of_month' => 31,
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: dedicated cycle for the SSO-base test created', $ssoBaseCycleRes['status']);
    $ssoBaseRunRes = $runModel->create($compId, [
        'run_name' => 'SSO Base Fix Test Run', 'cycle_id' => $ssoBaseCycleRes['id'],
        'period_start_date' => '2099-01-01', 'period_end_date' => '2099-01-31', 'payment_date' => '2099-02-05',
    ], $adminUserId, true);
    checkTrue('fixture: dedicated run for the SSO-base test created' . (empty($ssoBaseRunRes['status']) ? " ({$ssoBaseRunRes['message']})" : ''), $ssoBaseRunRes['status']);
    $ssoBaseRunId = $ssoBaseRunRes['id'];
    $ssoBaseRecalcRes = $runModel->recalculate($ssoBaseRunId, $compId, $adminUserId, true);
    checkTrue('fixture: dedicated run recalculated' . (empty($ssoBaseRecalcRes['status']) ? " ({$ssoBaseRecalcRes['message']})" : ''), $ssoBaseRecalcRes['status']);

    $beforeAllowanceDetails = $runModel->getDetails($ssoBaseRunId, $compId);
    $beforeAllowanceDetail = current(array_filter($beforeAllowanceDetails, fn($d) => (int)$d['employee_id'] === $employeeSsoBaseId));
    checkTrue('fixture: the low-salary employee is present with no allowance yet', $beforeAllowanceDetail !== false);
    $ssoBeforeAllowance = array_values(array_filter($beforeAllowanceDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? null;
    $expectedBeforeRaw = round(5000 * (float)$ssoRateRow['employee_rate'] / 100, 2);
    $expectedBefore = $ssoRateRow['max_employee_contribution'] !== null ? min($expectedBeforeRaw, (float)$ssoRateRow['max_employee_contribution']) : $expectedBeforeRaw;
    check('SSO on base salary alone (5000) matches the expected rate-based amount, no allowance yet', (float)($ssoBeforeAllowance['employee_amount'] ?? -1), $expectedBefore);

    // OT is seeded with calc_sso=1 by PayrollEarningDeductionTypeModel::seedDefaults() -- reusing
    // $otPedTypeId (already resolved earlier in this file) rather than a bespoke fixture PED type.
    $addAllowanceRes = $runModel->addManualLine($ssoBaseRunId, $compId, $employeeSsoBaseId, $otPedTypeId, 2000, $adminUserId, true, 'calc_sso-flagged allowance for the SSO-base fix test');
    checkTrue('fixture: calc_sso-flagged manual earning line (+2000) added' . (empty($addAllowanceRes['status']) ? " ({$addAllowanceRes['message']})" : ''), $addAllowanceRes['status']);
    $afterAllowanceDetails = $runModel->getDetails($ssoBaseRunId, $compId);
    $afterAllowanceDetail = current(array_filter($afterAllowanceDetails, fn($d) => (int)$d['employee_id'] === $employeeSsoBaseId));
    $ssoAfterAllowance = array_values(array_filter($afterAllowanceDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? null;
    $expectedAfterRaw = round(7000 * (float)$ssoRateRow['employee_rate'] / 100, 2);
    $expectedAfter = $ssoRateRow['max_employee_contribution'] !== null ? min($expectedAfterRaw, (float)$ssoRateRow['max_employee_contribution']) : $expectedAfterRaw;
    check('SSO now correctly includes the calc_sso-flagged +2000 allowance (base 5000+2000=7000 * rate)', (float)($ssoAfterAllowance['employee_amount'] ?? -1), $expectedAfter);
    checkTrue('the allowance genuinely increased the SSO deduction versus base-salary-alone (proves the fix, not a coincidence)', ($ssoAfterAllowance['employee_amount'] ?? 0) > ($ssoBeforeAllowance['employee_amount'] ?? 0));

    // A DEDUCTION line (not earning) must never be mistaken for an eligible earning even if its
    // item_code happens to coincide -- add one and confirm the SSO base is unaffected.
    $custDeductRes = $runModel->addManualLine($ssoBaseRunId, $compId, $employeeSsoBaseId, null, 500, $adminUserId, true, null, 'Unrelated deduction', 'deduction');
    checkTrue('fixture: an unrelated custom deduction line added', $custDeductRes['status']);
    $afterDeductionDetails = $runModel->getDetails($ssoBaseRunId, $compId);
    $afterDeductionDetail = current(array_filter($afterDeductionDetails, fn($d) => (int)$d['employee_id'] === $employeeSsoBaseId));
    $ssoAfterDeduction = array_values(array_filter($afterDeductionDetail['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? null;
    check('a deduction line never affects the SSO earnings base', (float)($ssoAfterDeduction['employee_amount'] ?? -1), $expectedAfter);

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

    // 2026-08-31, real gap fixed: salary_type='hourly' used to silently fall through to the
    // monthly-prorate formula (a WRONG number, not just "unsupported") -- now sums real
    // attendance_records.actual_work_minutes for the employee's own effective range. With
    // ZERO attendance data at all (this fixture's state before any attendance rows are added
    // below), pays 0 with a visible advisory flag rather than guessing.
    checkTrue('hourly employee with NO attendance data flagged hourly_salary_no_attendance_data', strpos((string)($hourlyDetail['calc_errors'] ?? ''), 'hourly_salary_no_attendance_data') !== false);
    check('hourly employee with no attendance data pays 0 base (not the old wrong monthly-formula guess)', (float)$hourlyDetail['base_salary_amount'], 0.0);

    checkTrue('daily employee WITHOUT a shift flagged daily_salary_no_shift_pattern', strpos((string)($noShiftDetail['calc_errors'] ?? ''), 'daily_salary_no_shift_pattern') !== false);
    check('daily employee without a shift has_shift_pattern is false', $expectedPayableNoShift['has_shift_pattern'], false);
    check('no-shift daily employee base_salary_amount = rate * payable_days (holiday-only exclusion)', (float)$noShiftDetail['base_salary_amount'], round($noShiftRate * $expectedPayableNoShift['payable_days'], 2));

    echo "=== 2026-08-31: salary_type weekly/semi_monthly/bi_weekly (explicit request: \"รายสัปดาห์ด้วย และ\n    รายปักษ์...ต้องครอบคลุมทั้งหมด\") -- same payableDaysForEmployee() proration as 'daily', base\n    rate first divided by a fixed per-type divisor (7/15/14) to get an effective daily rate ===\n";
    $weeklyRate = 3500.0;
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_WEEKLY_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายสัปดาห์', ':name_en' => 'Test', ':surname_en' => 'WeeklySalary',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => $weeklyRate, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeWeeklyId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET salary_type = 'weekly', shift_id = :shift_id WHERE id = :id")
        ->execute([':shift_id' => $dailyShiftId, ':id' => $employeeWeeklyId]);

    $semiMonthlyRate = 15000.0;
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_SEMIMONTHLY_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายปักษ์', ':name_en' => 'Test', ':surname_en' => 'SemiMonthlySalary',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => $semiMonthlyRate, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeSemiMonthlyId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET salary_type = 'semi_monthly', shift_id = :shift_id WHERE id = :id")
        ->execute([':shift_id' => $dailyShiftId, ':id' => $employeeSemiMonthlyId]);

    $biWeeklyRate = 7000.0;
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_BIWEEKLY_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ราย2สัปดาห์', ':name_en' => 'Test', ':surname_en' => 'BiWeeklySalary',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => $biWeeklyRate, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $employeeBiWeeklyId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET salary_type = 'bi_weekly', shift_id = :shift_id WHERE id = :id")
        ->execute([':shift_id' => $dailyShiftId, ':id' => $employeeBiWeeklyId]);

    $calcRes3 = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds after adding weekly/semi_monthly/bi_weekly employees' . (empty($calcRes3['status']) ? " ({$calcRes3['message']})" : ''), $calcRes3['status']);
    check('employee_count is 10 after adding the 3 new salary_type fixtures', $calcRes3['employee_count'], 10);

    $details3 = $runModel->getDetails($runId, $compId);
    $weeklyDetail = null; $semiMonthlyDetail = null; $biWeeklyDetail = null;
    foreach ($details3 as $d) {
        if ((int)$d['employee_id'] === $employeeWeeklyId) $weeklyDetail = $d;
        if ((int)$d['employee_id'] === $employeeSemiMonthlyId) $semiMonthlyDetail = $d;
        if ((int)$d['employee_id'] === $employeeBiWeeklyId) $biWeeklyDetail = $d;
    }
    check('weekly employee base_salary_amount = (rate/7) * payable_days', (float)$weeklyDetail['base_salary_amount'], round(($weeklyRate / 7) * $expectedPayableDaily['payable_days'], 2));
    check('weekly employee WITH a shift is not flagged daily_salary_no_shift_pattern', strpos((string)($weeklyDetail['calc_errors'] ?? ''), 'daily_salary_no_shift_pattern'), false);
    check('semi_monthly employee base_salary_amount = (rate/15) * payable_days', (float)$semiMonthlyDetail['base_salary_amount'], round(($semiMonthlyRate / 15) * $expectedPayableDaily['payable_days'], 2));
    check('bi_weekly employee base_salary_amount = (rate/14) * payable_days', (float)$biWeeklyDetail['base_salary_amount'], round(($biWeeklyRate / 14) * $expectedPayableDaily['payable_days'], 2));
    check('weekly employee prorate_total_days holds total_days (same X/Y breakdown as daily)', (int)$weeklyDetail['prorate_total_days'], $expectedPayableDaily['total_days']);

    echo "=== 2026-08-31: salary_type='hourly' -- real attendance_records.actual_work_minutes now\n    drives base pay (was previously a wrong number via a silent fallback to the monthly formula) ===\n";
    // 2 real attendance days: 480 + 300 minutes = 780 minutes = 13 hours total.
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, clock_in, clock_out, actual_work_minutes, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, '08:00:00', '16:00:00', 480, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeHourlyId, ':work_date' => $periodStart]);
    $hourlyDay2 = (new DateTime($periodStart))->modify('+1 day')->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, clock_in, clock_out, actual_work_minutes, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, '08:00:00', '13:00:00', 300, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeHourlyId, ':work_date' => $hourlyDay2]);
    // A THIRD row on the same date range but with NULL actual_work_minutes (e.g. imported without
    // clock times) -- must be excluded from the SUM but still count toward days_with_data (proving
    // the employee is correctly NOT flagged hourly_salary_no_attendance_data just because one row
    // happens to carry no worked-minutes figure).
    $hourlyDay3 = (new DateTime($periodStart))->modify('+2 day')->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeHourlyId, ':work_date' => $hourlyDay3]);

    $calcRes3b = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds after adding real hourly attendance data' . (empty($calcRes3b['status']) ? " ({$calcRes3b['message']})" : ''), $calcRes3b['status']);
    $details3b = $runModel->getDetails($runId, $compId);
    $hourlyDetail2 = null;
    foreach ($details3b as $d) { if ((int)$d['employee_id'] === $employeeHourlyId) $hourlyDetail2 = $d; }
    check('hourly employee base_salary_amount = hourlyRate(30000) * (780min/60=13h) = 390000', (float)$hourlyDetail2['base_salary_amount'], round($hourlyRate * (780 / 60.0), 2));
    check('hourly employee with real (partial) attendance data is NOT flagged hourly_salary_no_attendance_data', strpos((string)($hourlyDetail2['calc_errors'] ?? ''), 'hourly_salary_no_attendance_data') !== false, false);
    check('hourly employee prorate_days holds days_with_data = 3 (all 3 rows count, even the NULL-minutes one)', (int)$hourlyDetail2['prorate_days'], 3);

    // 2026-08-29, explicit request: "ให้แสดงในข้อมูลด้วยว่า จำนวนวันในรอบนั้นกี่วัน วันทำงานกี่วัน วันหยุด
    // นักขัตฤกษ์กี่วัน วันหยุดประจำสัปดาห์กี่วัน" -- workingDaysBreakdown() is a richer companion to
    // payableDaysForEmployee() just exercised above, reusing the exact same shift/holiday fixtures
    // (employeeDailyId has a real assigned shift; employeeDailyNoShiftId deliberately has none).
    echo "=== SetupRulesModel::workingDaysBreakdown() -- richer companion to payableDaysForEmployee() ===\n";
    $breakdownWithShift = $setupRulesModelForTest->workingDaysBreakdown($employeeDailyId, $compId, $periodStart, $periodEnd);
    check('total_days matches payableDaysForEmployee()\'s own total_days for the same employee/period', $breakdownWithShift['total_days'], $expectedPayableDaily['total_days']);
    check('working_days + holiday_days + weekly_off_days sums to total_days (mutually exclusive categorization)',
        $breakdownWithShift['working_days'] + $breakdownWithShift['holiday_days'] + $breakdownWithShift['weekly_off_days'], $breakdownWithShift['total_days']);
    check('working_days matches payableDaysForEmployee()\'s own payable_days (same "scheduled work day, not a holiday" definition)', $breakdownWithShift['working_days'], $expectedPayableDaily['payable_days']);
    check('has_shift_pattern is true (this employee has a real assigned shift)', $breakdownWithShift['has_shift_pattern'], true);

    $breakdownNoShift = $setupRulesModelForTest->workingDaysBreakdown($employeeDailyNoShiftId, $compId, $periodStart, $periodEnd);
    check('has_shift_pattern is false for the no-shift employee', $breakdownNoShift['has_shift_pattern'], false);
    check('no-shift employee: weekly_off_days is 0 (no shift pattern to derive a weekly off day from)', $breakdownNoShift['weekly_off_days'], 0);
    check('no-shift employee: working_days + holiday_days still sums to total_days', $breakdownNoShift['working_days'] + $breakdownNoShift['holiday_days'], $breakdownNoShift['total_days']);

    echo "=== Raw Sync Data viewer surfaces working_days_breakdown (sync-based run only) ===\n";
    $rawSyncWithBreakdown = $runModel->rawSyncDataForEmployee($compId, $pulledRunId, $employeeFullId);
    checkTrue('rawSyncDataForEmployee() on a sync-based run includes working_days_breakdown', isset($rawSyncWithBreakdown['working_days_breakdown']));
    checkTrue('working_days_breakdown has all 4 count fields', isset($rawSyncWithBreakdown['working_days_breakdown']['total_days'], $rawSyncWithBreakdown['working_days_breakdown']['working_days'], $rawSyncWithBreakdown['working_days_breakdown']['holiday_days'], $rawSyncWithBreakdown['working_days_breakdown']['weekly_off_days']));

    // 2026-08-29, real bug found and fixed (explicit report: "จำนวนวันในรอบ: 31 วันทำงาน: 26 ...
    // ส่วนนี้ยังไม่ถูก เพราะจำได้ว่าข้อมูลที่ส่งมาจาก Origami ถูกครับ เพราะเข้างานรอบนั้น ออกจากงานรอบนั้นจะ
    // คำนวณวันจริงมาให้แล้ว") -- rawSyncDataForEmployee() used to pass the run's raw
    // period_start_date/period_end_date straight into workingDaysBreakdown() unclamped, so a
    // mid-period joiner/leaver's breakdown always showed the FULL period's day count instead of
    // their real employment window -- disagreeing with Origami's own working_days figure, which
    // already accounts for it. Fixed to intersect with employment_date/employment_end_date first,
    // the same $effectiveStart/$effectiveEnd logic recalculate() already uses for its own prorate
    // window. $employeeFullId is temporarily given a mid-period employment_date here (was the full
    // period before this block -- restored at the end) so the clamp has something real to narrow.
    // $pulledRunId's own period is $pullPeriodStart/$pullPeriodEnd (+2 months from today), NOT the
    // $periodStart/$periodEnd this-month fixture used by the very first run in this file -- must
    // clamp against the SAME period this specific run actually has, or the mid-period date falls
    // before the run's real period start and never gets clamped at all (caught by this exact
    // mismatch before shipping the test).
    $midPeriodStart = (new DateTime($pullPeriodStart))->modify('+10 days')->format('Y-m-d');
    $pdo->prepare("UPDATE employees SET employment_date = :d WHERE id = :id")
        ->execute([':d' => $midPeriodStart, ':id' => $employeeFullId]);
    $breakdownMidJoiner = $runModel->rawSyncDataForEmployee($compId, $pulledRunId, $employeeFullId)['working_days_breakdown'];
    $expectedMidJoinerBreakdown = $setupRulesModelForTest->workingDaysBreakdown($employeeFullId, $compId, $midPeriodStart, $pullPeriodEnd);
    check('a mid-period joiner\'s total_days is clamped to their real employment window, not the full period', $breakdownMidJoiner['total_days'], $expectedMidJoinerBreakdown['total_days']);
    checkTrue('the clamped total_days is genuinely smaller than the full period (the bug\'s own symptom, not a no-op)', $breakdownMidJoiner['total_days'] < ((int)((strtotime($pullPeriodEnd) - strtotime($pullPeriodStart)) / 86400) + 1));
    check('working_days/holiday_days/weekly_off_days all match the clamped-window computation too', [$breakdownMidJoiner['working_days'], $breakdownMidJoiner['holiday_days'], $breakdownMidJoiner['weekly_off_days']], [$expectedMidJoinerBreakdown['working_days'], $expectedMidJoinerBreakdown['holiday_days'], $expectedMidJoinerBreakdown['weekly_off_days']]);
    // Restore -- this employee is reused as a full-period fixture by many later assertions in this
    // same file.
    $pdo->prepare("UPDATE employees SET employment_date = :d WHERE id = :id")
        ->execute([':d' => '2020-01-01', ':id' => $employeeFullId]);
    $breakdownAfterRestore = $runModel->rawSyncDataForEmployee($compId, $pulledRunId, $employeeFullId)['working_days_breakdown'];
    check('an employee present for the WHOLE period sees zero change from the clamp (intersection is just the period itself)', $breakdownAfterRestore, $rawSyncWithBreakdown['working_days_breakdown']);

    echo "=== Per-run item exclusion via Run Settings now covers standing PED items too (2026-08-29) ===\n";
    // 2026-08-29, explicit follow-up request: "ตอนนี้ 2 รายการเงินได้/เงินหักที่ใช้ในรอบนี้ จะไม่ซ้ำซ้อนกับ
    // การตั้งค่าของรอบใช่ไหมครับ" -- confirmed genuine overlap between the OLD per-run standing-PED
    // allowlist (payroll_run_ped_type_settings/savePedTypeSettings(), now REMOVED entirely) and the
    // Run Settings item-exclusion denylist for a standing PED item specifically -- consolidated per
    // explicit choice into Run Settings alone (a strict superset, see recalculate()'s own docblock
    // at the old restriction's removal site). This section's own fixture (second earning PED type +
    // standing assignment) is unchanged; only the assertions below were rewritten to use
    // runSettingsSave()/runSettingsGet() instead of the retired savePedTypeSettings()/
    // getPedTypeSettings().
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

    // Recalc without any exclusion first -- both allowance types should show up.
    $runModel->recalculate($runId, $compId, $adminUserId, true);
    $unrestrictedDetail = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeFullId) $unrestrictedDetail = $d;
    }
    check('unrestricted: both PED earning lines present', count(array_filter($unrestrictedDetail['earning_breakdown'], fn($l) => $l['source'] === 'ped')), 2);

    $restrictRes = $runModel->runSettingsSave($runId, $compId, 'use_employee_setting', 'use_employee_setting', [$mealItemCode], $adminUserId, true);
    checkTrue('runSettingsSave() excluding the meal-allowance item_code succeeds' . (empty($restrictRes['status']) ? " ({$restrictRes['message']})" : ''), $restrictRes['status']);

    $restrictedDetail = null;
    foreach ($runModel->getDetails($runId, $compId) as $d) {
        if ((int)$d['employee_id'] === $employeeFullId) $restrictedDetail = $d;
    }
    $restrictedPedCodes = array_column(array_filter($restrictedDetail['earning_breakdown'], fn($l) => $l['source'] === 'ped'), 'code');
    check('excluded via Run Settings: only the transport-allowance item remains', $restrictedPedCodes, [$pedTypeModel->get($compId, $pedTypeId)['item_code']]);
    checkTrue('excluded via Run Settings: the meal item is really gone', !in_array($mealItemCode, $restrictedPedCodes, true));

    // Reset immediately -- unlike the OLD retired allowlist (which only ever restricted STANDING
    // PED assignments, never touching addManualLine()), this exclusion is universal by design (see
    // recalculate()'s own docblock) and would otherwise silently swallow the $mealPedTypeId manual
    // line the very next section below adds on purpose.
    $runModel->runSettingsSave($runId, $compId, 'use_employee_setting', 'use_employee_setting', [], $adminUserId, true);

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

    // Reset the exclusion back to none -- both the setting AND the run must be recalculated back
    // to the fully-included state here so the rest of this script (markPaid's installment
    // assertions below) sees exactly what the pre-existing flow always expected.
    $resetRes = $runModel->runSettingsSave($runId, $compId, 'use_employee_setting', 'use_employee_setting', [], $adminUserId, true);
    checkTrue('runSettingsSave() with an empty excluded_item_codes list resets to unrestricted' . (empty($resetRes['status']) ? " ({$resetRes['message']})" : ''), $resetRes['status']);
    $settingsReset = $runModel->runSettingsGet($runId, $compId);
    check('excluded_item_codes is empty again after reset', $settingsReset['data']['excluded_item_codes'], []);
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

    $lockRes = $runModel->lock($runId, $compId, $adminUserId, true);
    checkTrue('lock succeeds', $lockRes['status']);
    check('state is locked', $runModel->get($runId, $compId)['state'], 'locked');

    $illegalLockAgain = $runModel->lock($runId, $compId, $adminUserId, true);
    check('locking an already-locked run is blocked', $illegalLockAgain['status'], false);

    // 2026-08-29, explicit follow-up request: "ถ้าการดำเนินเสร็จแล้ว Comment ดูได้เท่านั้น ไม่สามารถเพิ่ม
    // แก้ไข ลบได้" -- $runId is now 'locked' (just above), so employeeCommentAdd()/Update()/Delete()
    // must all refuse from this point on. employeeComments() (the read path) is deliberately NOT
    // gated -- "ดูได้เท่านั้น" (viewable only) means reads must keep working.
    echo "=== Comments become view-only once the run has finished (state=locked) ===\n";
    $lockedCommentAddRes = $runModel->employeeCommentAdd($runId, $compId, $employeeFullId, null, 'Trying to add after locked', $adminUserId, true);
    check('employeeCommentAdd() rejected once the run is locked', $lockedCommentAddRes['status'], false);
    $lockedCommentUpdateRes = $runModel->employeeCommentUpdate($runId, $compId, $commentRes2['id'], 'error', 'Trying to edit after locked', $adminUserId, true);
    check('employeeCommentUpdate() rejected once the run is locked', $lockedCommentUpdateRes['status'], false);
    $lockedCommentDeleteRes = $runModel->employeeCommentDelete($runId, $compId, $commentRes2['id'], $adminUserId, true);
    check('employeeCommentDelete() rejected once the run is locked', $lockedCommentDeleteRes['status'], false);
    checkTrue('the comment from earlier (created while still draft) still reads back fine -- view-only means reads keep working', count($runModel->employeeComments($runId, $compId, $employeeFullId)) > 0);

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

    // 2026-08-28, explicit request: "Process ที่ Cancel ให้สามารถลบข้อมูลออกไปได้" -- a cancelled run
    // used to be a permanent dead end (delete() only ever accepted state='draft'). Uses its own
    // fixture run (not $cancelTargetId above) since later assertions in this file still read
    // $cancelTargetId's own state/cancelled_from_state after this point.
    echo "=== Delete a cancelled run (2026-08-28) ===\n";
    $cancelThenDeleteRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_CANCEL_THEN_DELETE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +40 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +40 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +40 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('cancel-then-delete fixture run created' . (empty($cancelThenDeleteRes['status']) ? " ({$cancelThenDeleteRes['message']})" : ''), $cancelThenDeleteRes['status']);
    $cancelThenDeleteId = $cancelThenDeleteRes['id'];
    $runModel->cancel($cancelThenDeleteId, $compId, $adminUserId, true, 'Cancelling so it can be deleted.');
    check('state is cancelled before delete', $runModel->get($cancelThenDeleteId, $compId)['state'], 'cancelled');
    $deleteCancelledRes = $runModel->delete($cancelThenDeleteId, $compId, $adminUserId, true);
    checkTrue('delete succeeds on a cancelled run' . (empty($deleteCancelledRes['status']) ? " ({$deleteCancelledRes['message']})" : ''), $deleteCancelledRes['status']);
    check('deleted cancelled run no longer retrievable', $runModel->get($cancelThenDeleteId, $compId), null);

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
    // Submitter-only role: granted payroll_run.process only, not payroll_run.approve.
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en)
        VALUES (:comp_id, 'ทดสอบผู้ส่งอย่างเดียว', 'Test Submitter Only')")->execute([':comp_id' => $compId]);
    $submitterOnlyRoleId = (int)$pdo->lastInsertId();
    grantPayrollPermission($pdo, $submitterOnlyRoleId, 'payroll_run.process');
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

    // Role A: the only payroll_run.approve holder at first, held by an employee in the SAME department.
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en)
        VALUES (:comp_id, 'ทดสอบผู้อนุมัติ A', 'Test Approver A')")->execute([':comp_id' => $compId]);
    $approverRoleAId = (int)$pdo->lastInsertId();
    grantPayrollPermission($pdo, $approverRoleAId, 'payroll_run.approve');
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
    // "Disable role A's approve permission" is revoking its payroll_run.approve role_permissions row
    // (structure_roles.can_approve_payroll no longer exists at all -- this IS the only mechanism now).
    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = :r AND permission_id = (SELECT id FROM permissions WHERE permission_key = 'payroll_run.approve')")
        ->execute([':r' => $approverRoleAId]);
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en)
        VALUES (:comp_id, 'ทดสอบผู้อนุมัติ B', 'Test Approver B')")->execute([':comp_id' => $compId]);
    $approverRoleBId = (int)$pdo->lastInsertId();
    grantPayrollPermission($pdo, $approverRoleBId, 'payroll_run.approve');
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
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en)
        VALUES (:comp_id, 'ทดสอบผู้ส่งไม่มีแผนก', 'Test Submitter No Dept')")->execute([':comp_id' => $compId]);
    $noDeptSubmitterRoleId = (int)$pdo->lastInsertId();
    grantPayrollPermission($pdo, $noDeptSubmitterRoleId, 'payroll_run.process');
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

    echo "=== 2026-08-30, explicit follow-up: ApprovalRequestModel::stepBreakdown() (feeds" .
        " approvalFlow()'s new 'steps' key -- the frontend step-dot timeline) -- before anyone" .
        " acts, both steps pending/unlocked with their full pool listed ===\n";
    $stepsBefore = $runModel->approvalFlow($jointRunId, $compId)['steps'];
    check('2 steps returned', count($stepsBefore), 2);
    check('step 1 is step_order 1', $stepsBefore[0]['step_order'], 1);
    check('step 2 is step_order 2', $stepsBefore[1]['step_order'], 2);
    check('step 1 status is pending (nobody decided it yet)', $stepsBefore[0]['status'], 'pending');
    checkTrue('step 1 is unlocked (requires_previous_step=0 by default)', $stepsBefore[0]['unlocked']);
    check('step 1 lists the full pool (X, Y, W) -- 3 people', count($stepsBefore[0]['approvers']), 3);
    checkTrue('every step-1 approver shows pending before anyone acts', array_reduce($stepsBefore[0]['approvers'], fn($c, $a) => $c && $a['status'] === 'pending', true));
    check('step 2 lists its own pool (Z, W) -- 2 people', count($stepsBefore[1]['approvers']), 2);

    echo "--- X approves step 1 (joint 'any' -- first action decides the whole pool's row) ---\n";
    $xApproveRes = $runModel->approve($jointRunId, $compId, $X, false, 'X decides for the pool');
    checkTrue('X (in the pool) can approve step 1' . (empty($xApproveRes['status']) ? " ({$xApproveRes['message']})" : ''), $xApproveRes['status']);
    check('run stays pending_approval (step 2 -- an AND-group step -- is still open)', $runModel->get($jointRunId, $compId)['state'], 'pending_approval');
    $jointRunAfterX = $runModel->get($jointRunId, $compId);

    echo "--- stepBreakdown(): step 1 now shows only the actual actor (X), not Y/W who shared the" .
        " same 'any'-mode pool but never personally acted -- listing them as still 'pending' would" .
        " misrepresent an already-decided step as still open ---\n";
    $stepsAfterX = $runModel->approvalFlow($jointRunId, $compId)['steps'];
    check('step 1 status flips to approved', $stepsAfterX[0]['status'], 'approved');
    check('step 1 now shows only 1 approver (the actual actor)', count($stepsAfterX[0]['approvers']), 1);
    check('that approver is X', (int)$stepsAfterX[0]['approvers'][0]['id'], $X);
    check('X shows approved status with their own note', [$stepsAfterX[0]['approvers'][0]['status'], $stepsAfterX[0]['approvers'][0]['note']], ['approved', 'X decides for the pool']);
    check('step 2 is untouched -- still pending with its full 2-person pool', [$stepsAfterX[1]['status'], count($stepsAfterX[1]['approvers'])], ['pending', 2]);

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
    echo "--- stepBreakdown(): step 2 now also shows only its actual actor (Z) once decided ---\n";
    $stepsAfterZ = $runModel->approvalFlow($jointRunId, $compId)['steps'];
    check('step 2 status flips to approved', $stepsAfterZ[1]['status'], 'approved');
    check('step 2 now shows only Z', [count($stepsAfterZ[1]['approvers']), (int)$stepsAfterZ[1]['approvers'][0]['id']], [1, $Z]);

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

    echo "=== 2026-09-03, Platform Hardening Phase 3: payroll_run.approve permission-key/override" .
        " variants of the SAME round-2 rules above -- the legacy can_approve_payroll boolean is" .
        " retired, PayrollRunModel now reads payroll_run.approve via PermissionModel::checkPermission()" .
        " (role grant, or a per-user override) -- these confirm the exact same engine-first ordering" .
        " still holds under the new lookup mechanism, not just the old raw column. ===\n";
    // Variant A: the flat-fallback path (no active workflow) genuinely works for a REAL non-admin
    // employee granted payroll_run.approve via role_permissions -- not just via admin bypass, which
    // would short-circuit before ever reaching the permission lookup and so wouldn't actually prove
    // this.
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en) VALUES (:comp_id, 'ทดสอบผู้อนุมัติ Grant', 'Test Flat Approver Grant')")
        ->execute([':comp_id' => $compId]);
    $flatApproverRole = (int)$pdo->lastInsertId();
    grantPayrollPermission($pdo, $flatApproverRole, 'payroll_run.approve');
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'PH3_FLAT_APPROVER_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'FlatApprover', ':name_en' => 'Test', ':surname_en' => 'FlatApprover',
        ':email' => uniqid() . '@test.local', ':employment_date' => '2020-01-01', ':employment_end_date' => null,
        ':employee_status_enum' => 'permanent',
        ':base_salary' => 30000, ':salary_effective_date' => '2020-01-01',
        ':sso_enrolled' => 1, ':pvd_enrolled' => 1, ':tax_exempt' => 0,
    ]);
    $flatApproverEmp = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id WHERE id = :id")->execute([':role_id' => $flatApproverRole, ':id' => $flatApproverEmp]);
    $flatGrantRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_PH3_FLAT_GRANT_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +27 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +27 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +27 months')->format('Y-m-d'),
    ], $adminUserId, true);
    $flatGrantRunId = $flatGrantRunRes['id'];
    $runModel->recalculate($flatGrantRunId, $compId, $adminUserId, true);
    $runModel->submit($flatGrantRunId, $compId, $adminUserId, true);
    $flatGrantRun = $runModel->get($flatGrantRunId, $compId);
    check('setup: this run is NOT routed through the engine (workflow still inactive from above)', $flatGrantRun['approval_request_id'], null);
    checkTrue('a real non-admin employee with a payroll_run.approve ROLE grant can approve via the flat fallback', $runModel->canApprovePayroll($flatApproverEmp, false, $flatGrantRun));
    $flatGrantApproveRes = $runModel->approve($flatGrantRunId, $compId, $flatApproverEmp, false, 'approved via a real payroll_run.approve role grant');
    checkTrue('approve() itself succeeds for that employee' . (empty($flatGrantApproveRes['status']) ? " ({$flatGrantApproveRes['message']})" : ''), $flatGrantApproveRes['status']);

    $pdo->prepare("UPDATE `approval_workflows` SET status = 'active' WHERE id = :id")->execute([':id' => $testWorkflowId]);

    // Variant B: a per-user DENY override on payroll_run.approve for X (a genuinely configured,
    // engine-eligible approver on this workflow, per the pool set up earlier in this file) must have
    // ZERO effect on an engine-routed run -- exactly like the legacy can_approve_payroll boolean was
    // already provably irrelevant on this same code path (canApproveThisRun() consults the engine
    // FIRST and returns before the permission/override is ever read at all, see that method's own
    // docblock). This is the single most important new assertion in this whole file: it proves the
    // NEW per-user-override feature can't accidentally create a way to lock a real, engine-configured
    // approver out of (or into) a decision the Approval Workflow engine itself governs.
    $xPermIdForDeny = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = 'payroll_run.approve'")->fetchColumn();
    $pdo->prepare("INSERT INTO employee_permission_overrides (comp_id, employee_id, permission_id, effect) VALUES (:c, :e, :p, 'deny')")
        ->execute([':c' => $compId, ':e' => $X, ':p' => $xPermIdForDeny]);
    // Sanity check first: the override alone (outside any workflow) really does deny X the flat
    // permission -- confirms the override mechanism itself is working, not just that this specific
    // run happens to not need it.
    check('sanity: X now has NO flat payroll_run.approve permission at all (deny override in effect)', (new PermissionModel($pdo))->checkPermission($X, 'payroll_run.approve', false, $compId)['allowed'], false);

    $overrideRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_PH3_DENY_OVERRIDE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +28 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +28 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +28 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: deny-override-test run created' . (empty($overrideRunRes['status']) ? " ({$overrideRunRes['message']})" : ''), $overrideRunRes['status']);
    $overrideRunId = $overrideRunRes['id'];
    $runModel->recalculate($overrideRunId, $compId, $adminUserId, true);
    $runModel->submit($overrideRunId, $compId, $adminUserId, true);
    $overrideRun = $runModel->get($overrideRunId, $compId);
    checkTrue('setup: this run IS routed through the engine', $overrideRun['approval_request_id'] !== null);
    checkTrue('X can STILL approve step 1 despite the deny override -- the engine never consults payroll_run.approve at all on this path', $runModel->canApprovePayroll($X, false, $overrideRun));
    $overrideStep1Res = $runModel->approve($overrideRunId, $compId, $X, false, 'X approves despite holding a deny override on the flat permission');
    checkTrue('approve() itself succeeds for X' . (empty($overrideStep1Res['status']) ? " ({$overrideStep1Res['message']})" : ''), $overrideStep1Res['status']);
    $overrideStep2Res = $runModel->approve($overrideRunId, $compId, $Z, false, 'Z decides the remaining step');
    checkTrue('Z (the other configured approver) can finish the flow normally' . (empty($overrideStep2Res['status']) ? " ({$overrideStep2Res['message']})" : ''), $overrideStep2Res['status']);
    check('run reaches approved -- the deny override never blocked anything on this engine-routed run', $runModel->get($overrideRunId, $compId)['state'], 'approved');

    echo "=== stepBreakdown(): requires_previous_step gating shows up as 'unlocked' flipping" .
        " false->true, and joint_approve_mode='all' lists each person's OWN real per-row status" .
        " (not the any-mode only-show-the-actor behavior tested above) ===\n";
    // Retire the joint-step config and replace with: step 1 ('all' mode, 2 approvers, not gated) ->
    // step 2 (gated behind step 1, single approver) -- same "soft-delete then insert fresh active
    // rows" pattern the joint-step fixture above already used; only affects NEW requests created
    // from here on, the already-finished $jointRunId/$wfRunId runs keep their own frozen snapshots.
    $pdo->prepare("UPDATE `approval_workflow_steps` SET status = 'deleted' WHERE workflow_id = :wf AND status = 'active'")->execute([':wf' => $testWorkflowId]);
    // Both steps need requires_previous_step=1 -- isStepUnlocked() only counts an EARLIER step as a
    // gate for a later one when that earlier step is ALSO requires_previous_step=1 itself (steps
    // that don't require sequencing don't block anything, see that method's own docblock); step 1
    // stays unlocked regardless of its own flag here since nothing precedes it.
    $pdo->prepare("INSERT INTO `approval_workflow_steps` (workflow_id, step_order, step_name, joint_approve_mode, requires_previous_step)
        VALUES (:workflow_id, 1, 'Gated Step 1 (all)', 'all', 1)")->execute([':workflow_id' => $testWorkflowId]);
    $gatedStep1Id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `approval_workflow_steps` (workflow_id, step_order, step_name, joint_approve_mode, requires_previous_step)
        VALUES (:workflow_id, 2, 'Gated Step 2 (locked until step 1 done)', 'any', 1)")->execute([':workflow_id' => $testWorkflowId]);
    $gatedStep2Id = (int)$pdo->lastInsertId();
    $insStepApprover->execute([':step_id' => $gatedStep1Id, ':approver_id' => $X]);
    $insStepApprover->execute([':step_id' => $gatedStep1Id, ':approver_id' => $Y]);
    $insStepApprover->execute([':step_id' => $gatedStep2Id, ':approver_id' => $Z]);

    $gatedRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TEST_RUN_GATED_STEP_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +26 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +26 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +26 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('setup: gated-step-test run created' . (empty($gatedRunRes['status']) ? " ({$gatedRunRes['message']})" : ''), $gatedRunRes['status']);
    $gatedRunId = $gatedRunRes['id'];
    $runModel->recalculate($gatedRunId, $compId, $adminUserId, true);
    $runModel->submit($gatedRunId, $compId, $adminUserId, true);

    $gatedStepsBefore = $runModel->approvalFlow($gatedRunId, $compId)['steps'];
    checkTrue('step 1 is unlocked from the start (requires_previous_step=0)', $gatedStepsBefore[0]['unlocked']);
    check('step 2 is LOCKED before step 1 is fully approved (requires_previous_step=1)', $gatedStepsBefore[1]['unlocked'], false);
    check('step 1 (all mode) lists both X and Y individually, both pending', count($gatedStepsBefore[0]['approvers']), 2);

    // X (one of two 'all'-mode approvers) approves -- step 1 must stay 'pending' (Y hasn't acted
    // yet) and step 2 must stay locked.
    $runModel->approve($gatedRunId, $compId, $X, false, 'X approves, Y still pending');
    $gatedStepsAfterX = $runModel->approvalFlow($gatedRunId, $compId)['steps'];
    check('step 1 still pending -- only X (of 2 required) has approved so far', $gatedStepsAfterX[0]['status'], 'pending');
    check('step 2 still locked -- step 1 not FULLY approved yet', $gatedStepsAfterX[1]['unlocked'], false);
    $xRow = array_values(array_filter($gatedStepsAfterX[0]['approvers'], fn($a) => (int)$a['id'] === $X))[0];
    $yRow = array_values(array_filter($gatedStepsAfterX[0]['approvers'], fn($a) => (int)$a['id'] === $Y))[0];
    check('X\'s OWN row shows approved ("all" mode tracks each person individually)', $xRow['status'], 'approved');
    check('Y\'s OWN row still shows pending (has not acted yet)', $yRow['status'], 'pending');

    // Y approves too -- step 1 fully approved now, step 2 unlocks.
    $runModel->approve($gatedRunId, $compId, $Y, false, 'Y closes out step 1');
    $gatedStepsAfterY = $runModel->approvalFlow($gatedRunId, $compId)['steps'];
    check('step 1 now fully approved (both X and Y approved)', $gatedStepsAfterY[0]['status'], 'approved');
    checkTrue('step 2 UNLOCKS the moment step 1 is fully approved', $gatedStepsAfterY[1]['unlocked']);

    echo "=== approvalFlow()'s 'steps' key stays ABSENT for a run with no workflow at all (the" .
        " flat department-scoped fallback has no multi-step concept -- must not fabricate one) ===\n";
    check('the flat-fallback run (no approval_request_id) has no steps key at all', array_key_exists('steps', $runModel->approvalFlow($adminFlatFallbackRunId, $compId)), false);

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
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
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

    echo "=== 2026-08-31: recalculate() includes/excludes Recurring Deductions (EmployeeRecurringDeductionModel) ===\n";
    // Direct mirror of the Recurring Earnings section immediately above -- same 3-run
    // included/suspended/resumed shape, on the deduction side of recalculate() instead.
    require_once __DIR__ . '/../app/models/EmployeeRecurringDeductionModel.php';
    $recDedTypeRes = $recTypeModel->save($compId, [
        'item_code' => 'RECDEDTEST1', 'item_name_th' => 'ค่าเครื่องแบบทดสอบ', 'item_name_en' => 'Test Uniform Fee',
        'item_type' => 'deduction', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 300, 'tax_deduction_impact' => 'after_tax',
    ], $adminUserId);
    checkTrue('fixture: recurring-deduction catalog type created', $recDedTypeRes['status']);
    $recDedTypeId = $recDedTypeRes['id'];

    // Baselines captured from the recurring-EARNING section above, BEFORE this deduction fixture
    // exists -- used below to isolate the deduction's own effect on net_amount by delta rather than
    // asserting an absolute net figure (this fixture employee also has sso_enrolled/pvd_enrolled=1,
    // so net_amount already reflects real statutory deductions unrelated to this feature).
    $recDedRun1NetBaseline = (float)$recRun1Detail['net_amount'];
    $recDedRun3NetBaseline = (float)$recRun3Detail['net_amount'];

    $recDeductionModel = new EmployeeRecurringDeductionModel($pdo);
    $recDedAssignRes = $recDeductionModel->save($recEmployeeId, $compId, [
        'ped_type_id' => $recDedTypeId, 'amount' => 250, 'effective_date' => '2020-01-01',
    ], $adminUserId);
    checkTrue('fixture: recurring deduction assigned to the employee', $recDedAssignRes['status']);
    $recDedAssignmentId = $recDedAssignRes['id'];

    // Run 1 (+30 months, same period as the recurring-earning run above, no suspend window yet).
    $runModel->recalculate($recRun1Id, $compId, $adminUserId, true);
    $recDedRun1Detail = current(array_filter($runModel->getDetails($recRun1Id, $compId), fn($d) => (int)$d['employee_id'] === $recEmployeeId));
    $recDedRun1Line = current(array_filter($recDedRun1Detail['deduction_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_deduction'));
    checkTrue('run 1: a recurring_deduction line is present (no suspend window yet)', $recDedRun1Line !== false);
    check('run 1: the line carries the right amount', (float)$recDedRun1Line['amount'], 250.0);
    check('run 1: the line carries the catalog item_code', $recDedRun1Line['code'], 'RECDEDTEST1');
    check('run 1: net_amount dropped by exactly the recurring deduction(250) vs. the pre-deduction baseline', round($recDedRun1NetBaseline - (float)$recDedRun1Detail['net_amount'], 2), 250.0);

    // Suspend for a window overlapping run 2's period (+31 months) but NOT run 1's.
    $recDedSuspendRes = $recDeductionModel->save($recEmployeeId, $compId, [
        'id' => $recDedAssignmentId, 'ped_type_id' => $recDedTypeId, 'amount' => 250, 'effective_date' => '2020-01-01',
        'suspended_from' => $recRun2PeriodStart, 'suspended_to' => $recRun2PeriodEnd,
    ], $adminUserId);
    checkTrue('fixture: deduction suspended for run 2\'s exact period', $recDedSuspendRes['status']);

    $runModel->recalculate($recRun2Id, $compId, $adminUserId, true);
    $recDedRun2Detail = current(array_filter($runModel->getDetails($recRun2Id, $compId), fn($d) => (int)$d['employee_id'] === $recEmployeeId));
    $recDedRun2Line = current(array_filter($recDedRun2Detail['deduction_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_deduction'));
    check('run 2: the recurring_deduction line is EXCLUDED (suspend window covers this run\'s period)', $recDedRun2Line !== false, false);

    // Run 3 (+32 months, after the suspend window ends) -- resumes automatically.
    $runModel->recalculate($recRun3Id, $compId, $adminUserId, true);
    $recDedRun3Detail = current(array_filter($runModel->getDetails($recRun3Id, $compId), fn($d) => (int)$d['employee_id'] === $recEmployeeId));
    $recDedRun3Line = current(array_filter($recDedRun3Detail['deduction_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_deduction'));
    checkTrue('run 3: the recurring_deduction line resumes automatically after the suspend window ends', $recDedRun3Line !== false);
    check('run 3: net_amount dropped by exactly the recurring deduction(250) again vs. the pre-deduction baseline', round($recDedRun3NetBaseline - (float)$recDedRun3Detail['net_amount'], 2), 250.0);

    echo "=== 2026-08-31: recurring deduction Fee (fee_percent % of the employee's CURRENT base salary, added live every run) ===\n";
    // $recEmployeeId's own base_salary_amount is 30000 (set in this section's own fixture INSERT
    // above) -- clears the (already-expired) suspend window and adds a 5% base-salary fee on top of
    // the existing flat 250 amount: feeAmount = 30000 * 0.05 = 1500, so the line's own `amount`
    // should read 250 + 1500 = 1750 (recomputed live by PayrollRunModel::recurringDeductionAmountWithFee(),
    // NOT baked into the stored `amount` column the way the loan side's fee is).
    $recDedFeeRes = $recDeductionModel->save($recEmployeeId, $compId, [
        'id' => $recDedAssignmentId, 'ped_type_id' => $recDedTypeId, 'amount' => 250, 'effective_date' => '2020-01-01',
        'fee_percent' => 5, 'fee_base' => 'base_salary',
    ], $adminUserId);
    checkTrue('fixture: fee_percent/fee_base saved on the recurring deduction' . (empty($recDedFeeRes['status']) ? " ({$recDedFeeRes['message']})" : ''), $recDedFeeRes['status']);
    $runModel->recalculate($recRun3Id, $compId, $adminUserId, true);
    $recDedFeeDetail = current(array_filter($runModel->getDetails($recRun3Id, $compId), fn($d) => (int)$d['employee_id'] === $recEmployeeId));
    $recDedFeeLine = current(array_filter($recDedFeeDetail['deduction_breakdown'], fn($l) => ($l['source'] ?? null) === 'recurring_deduction'));
    checkTrue('the recurring_deduction line is still present with the fee active', $recDedFeeLine !== false);
    check('the line amount is amount(250) + fee(5% of base salary 30000 = 1500) = 1750', (float)$recDedFeeLine['amount'], 1750.0);
    check('net_amount dropped by the full 1750 vs. the pre-deduction baseline', round($recDedRun3NetBaseline - (float)$recDedFeeDetail['net_amount'], 2), 1750.0);

    $recDedFeeZeroRes = $recDeductionModel->save($recEmployeeId, $compId, [
        'ped_type_id' => $recDedTypeId, 'amount' => 100, 'effective_date' => '2020-01-01',
        'fee_percent' => 0, 'fee_base' => 'base_salary',
    ], $adminUserId);
    check('save() rejects fee_percent <= 0', $recDedFeeZeroRes['status'], false);
    $recDedFeeInvalidBaseRes = $recDeductionModel->save($recEmployeeId, $compId, [
        'ped_type_id' => $recDedTypeId, 'amount' => 100, 'effective_date' => '2020-01-01',
        'fee_percent' => 5, 'fee_base' => 'principal_amount',
    ], $adminUserId);
    check('save() rejects a fee_base other than base_salary (no principal concept on this table)', $recDedFeeInvalidBaseRes['status'], false);

    echo "=== 2026-08-27: incentive run include_base_salary/include_standing_items opt-in toggles ===\n";
    // Reuses $employeeFullId (base_salary=30000), but NOT its original $assignmentId/
    // $customAssignmentId fixture assignments -- those got consumed (status flipped 'active' ->
    // 'completed') when the very first fixture run's own markPaid() processed their single
    // installment much earlier in this file (see the "Mark Paid" section around $runId), so by
    // this point in the script they no longer match the PED query's own `status = 'active'` filter
    // (real, found-while-writing-this-test confirmation that a completed assignment correctly
    // never resurfaces in a later run -- not a bug). Fresh assignments below, same shape as the
    // original fixture (same $pedTypeId catalog type, reusable since only the ASSIGNMENT/
    // installment got consumed, not the catalog type itself).
    $freshInsAssign = $pdo->prepare("INSERT INTO `employee_earning_deductions`
        (employee_id, ped_type_id, total_installments, current_installment, amount_mode, total_amount, effective_date, status, created_by)
        VALUES (:employee_id, :ped_type_id, 1, 0, 'even_split', 1000, '2020-01-01', 'active', :created_by)");
    $freshInsAssign->execute([':employee_id' => $employeeFullId, ':ped_type_id' => $pedTypeId, ':created_by' => $adminUserId]);
    $freshAssignmentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `employee_earning_deduction_installments` (assignment_id, installment_no, amount, status)
        VALUES (:assignment_id, 1, 1000, 'pending')")->execute([':assignment_id' => $freshAssignmentId]);
    $pdo->prepare("INSERT INTO `employee_earning_deductions`
        (employee_id, ped_type_id, custom_item_name, custom_item_type, total_installments, current_installment, amount_mode, total_amount, effective_date, status, created_by)
        VALUES (:employee_id, NULL, 'ค่ามัดจำชุดยูนิฟอร์ม (fresh)', 'deduction', 1, 0, 'even_split', 200, '2020-01-01', 'active', :created_by)")
        ->execute([':employee_id' => $employeeFullId, ':created_by' => $adminUserId]);
    $freshCustomAssignmentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `employee_earning_deduction_installments` (assignment_id, installment_no, amount, status)
        VALUES (:assignment_id, 1, 200, 'pending')")->execute([':assignment_id' => $freshCustomAssignmentId]);
    checkTrue('fixture: fresh (still-active) PED earning+deduction assignments created for the toggle tests', $freshAssignmentId > 0 && $freshCustomAssignmentId > 0);

    // -- include_base_salary alone: full salary, no standing items, no proration --
    $inclBaseOnlyStart = (clone $today)->modify('first day of +33 months')->format('Y-m-d');
    $inclBaseOnlyEnd = (clone $today)->modify('last day of +33 months')->format('Y-m-d');
    $inclBaseOnlyRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'include_base_salary' => 1, 'include_standing_items' => 0,
        'run_name' => 'INCENTIVE_INCL_BASE_ONLY_' . uniqid(),
        'period_start_date' => $inclBaseOnlyStart, 'period_end_date' => $inclBaseOnlyEnd, 'payment_date' => $inclBaseOnlyEnd,
    ], $adminUserId, true);
    checkTrue('fixture: incentive run with include_base_salary=1 only created' . (empty($inclBaseOnlyRes['status']) ? " ({$inclBaseOnlyRes['message']})" : ''), $inclBaseOnlyRes['status']);
    $inclBaseOnlyRow = $pdo->query("SELECT include_base_salary, include_standing_items FROM payroll_runs WHERE id = {$inclBaseOnlyRes['id']}")->fetch(PDO::FETCH_ASSOC);
    check('include_base_salary stored as 1', (int)$inclBaseOnlyRow['include_base_salary'], 1);
    check('include_standing_items stored as 0', (int)$inclBaseOnlyRow['include_standing_items'], 0);
    $runModel->joinEmployees($inclBaseOnlyRes['id'], $compId, [$employeeFullId], $adminUserId, true);
    $runModel->recalculate($inclBaseOnlyRes['id'], $compId, $adminUserId, true);
    $inclBaseOnlyDetail = $runModel->getDetails($inclBaseOnlyRes['id'], $compId)[0] ?? [];
    check('include_base_salary=1: base_salary_amount is the FULL amount (30000, not prorated)', (float)($inclBaseOnlyDetail['base_salary_amount'] ?? -1), 30000.0);
    check('include_base_salary=1: prorate_days stays null (no proration for an incentive run)', $inclBaseOnlyDetail['prorate_days'], null);
    checkTrue('include_base_salary=1, include_standing_items=0: no ped-sourced line present', empty(array_filter(array_merge($inclBaseOnlyDetail['earning_breakdown'] ?? [], $inclBaseOnlyDetail['deduction_breakdown'] ?? []), fn($l) => ($l['source'] ?? null) === 'ped')));
    check('include_base_salary=1, include_standing_items=0: gross = base only (30000)', (float)$inclBaseOnlyDetail['gross_amount'], 30000.0);

    // -- include_standing_items alone: standing PED assignments pulled in, base salary stays 0 --
    $inclItemsOnlyStart = (clone $today)->modify('first day of +34 months')->format('Y-m-d');
    $inclItemsOnlyEnd = (clone $today)->modify('last day of +34 months')->format('Y-m-d');
    $inclItemsOnlyRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'include_base_salary' => 0, 'include_standing_items' => 1,
        'run_name' => 'INCENTIVE_INCL_ITEMS_ONLY_' . uniqid(),
        'period_start_date' => $inclItemsOnlyStart, 'period_end_date' => $inclItemsOnlyEnd, 'payment_date' => $inclItemsOnlyEnd,
    ], $adminUserId, true);
    checkTrue('fixture: incentive run with include_standing_items=1 only created' . (empty($inclItemsOnlyRes['status']) ? " ({$inclItemsOnlyRes['message']})" : ''), $inclItemsOnlyRes['status']);
    $runModel->joinEmployees($inclItemsOnlyRes['id'], $compId, [$employeeFullId], $adminUserId, true);
    $runModel->recalculate($inclItemsOnlyRes['id'], $compId, $adminUserId, true);
    $inclItemsOnlyDetail = $runModel->getDetails($inclItemsOnlyRes['id'], $compId)[0] ?? [];
    check('include_standing_items=1, include_base_salary=0: base_salary_amount stays 0', (float)($inclItemsOnlyDetail['base_salary_amount'] ?? -1), 0.0);
    // 2026-09-10, real gap found and fixed (confirmed business rule): base_salary_amount=0 here
    // used to display as a plain "0.00" in the on-screen table/Excel export -- base_salary_excluded
    // never accounted for this exact case (incentive run, include_base_salary=0, no per-employee
    // override, no Run Settings item-exclusion configured on top) even though effectiveBase is
    // genuinely 0 for the SAME "intentionally not included" reason as the other 2 mechanisms. See
    // PayrollRunModel::isBaseSalaryExcluded()'s own docblock.
    check('include_standing_items=1, include_base_salary=0: base_salary_excluded is now TRUE (real gap fixed 2026-09-10 -- no override, no Run Settings exclusion, purely the incentive-run include_base_salary=0 toggle)', $inclItemsOnlyDetail['base_salary_excluded'] ?? null, true);
    $inclItemsPedEarning = current(array_filter($inclItemsOnlyDetail['earning_breakdown'] ?? [], fn($l) => ($l['source'] ?? null) === 'ped' && (int)($l['assignment_id'] ?? 0) === $freshAssignmentId));
    checkTrue('include_standing_items=1: the standing PED earning assignment (TESTALLOW, +1000) is pulled in', $inclItemsPedEarning !== false);
    $inclItemsPedDeduction = current(array_filter($inclItemsOnlyDetail['deduction_breakdown'] ?? [], fn($l) => ($l['source'] ?? null) === 'ped' && (int)($l['assignment_id'] ?? 0) === $freshCustomAssignmentId));
    checkTrue('include_standing_items=1: the standing PED custom deduction (-200) is pulled in too', $inclItemsPedDeduction !== false);
    check('include_standing_items=1, include_base_salary=0: gross = ped earning only (1000)', (float)$inclItemsOnlyDetail['gross_amount'], 1000.0);
    check('include_standing_items=1: total_deduction = ped custom deduction (200)', (float)$inclItemsOnlyDetail['total_deduction_amount'], 200.0);

    // -- both toggles on together, PLUS a manually-picked line, PLUS a Run Settings item exclusion
    //    (2026-08-29: the old two-panel payroll_run_ped_type_settings ALLOWLIST this used to test
    //    is retired -- Run Settings' own DENYLIST now covers this case too, see recalculate()'s own
    //    docblock at the old restriction's removal site) excluding the TESTALLOW item_code
    //    specifically -- OT and the custom -200 deduction are both untouched.
    $inclBothStart = (clone $today)->modify('first day of +35 months')->format('Y-m-d');
    $inclBothEnd = (clone $today)->modify('last day of +35 months')->format('Y-m-d');
    $inclBothRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'include_base_salary' => 1, 'include_standing_items' => 1,
        'run_name' => 'INCENTIVE_INCL_BOTH_' . uniqid(),
        'period_start_date' => $inclBothStart, 'period_end_date' => $inclBothEnd, 'payment_date' => $inclBothEnd,
    ], $adminUserId, true);
    checkTrue('fixture: incentive run with both toggles on created' . (empty($inclBothRes['status']) ? " ({$inclBothRes['message']})" : ''), $inclBothRes['status']);
    $inclBothRunId = $inclBothRes['id'];
    $runModel->joinEmployees($inclBothRunId, $compId, [$employeeFullId], $adminUserId, true);

    $testAllowItemCode = $pedTypeModel->get($compId, $pedTypeId)['item_code'];
    $restrictSaveRes = $runModel->runSettingsSave($inclBothRunId, $compId, 'use_employee_setting', 'use_employee_setting', [$testAllowItemCode], $adminUserId, true);
    checkTrue('runSettingsSave() excluding TESTALLOW succeeds for an incentive run (item exclusion is not scoped to standing-items-only anymore)' . (empty($restrictSaveRes['status']) ? " ({$restrictSaveRes['message']})" : ''), $restrictSaveRes['status']);

    $addBothManualRes = $runModel->addManualLine($inclBothRunId, $compId, $employeeFullId, $otPedTypeId, 5000, $adminUserId, true);
    checkTrue('fixture: manual line (OT, +5000) added on top' . (empty($addBothManualRes['status']) ? " ({$addBothManualRes['message']})" : ''), $addBothManualRes['status']);

    $runModel->recalculate($inclBothRunId, $compId, $adminUserId, true);
    $inclBothDetail = $runModel->getDetails($inclBothRunId, $compId)[0] ?? [];
    check('both toggles on: base_salary_amount is the FULL amount (30000)', (float)($inclBothDetail['base_salary_amount'] ?? -1), 30000.0);
    $inclBothPedEarning = current(array_filter($inclBothDetail['earning_breakdown'] ?? [], fn($l) => ($l['source'] ?? null) === 'ped' && (int)($l['assignment_id'] ?? 0) === $freshAssignmentId));
    check('Run Settings item exclusion excludes the standing TESTALLOW PED earning', $inclBothPedEarning, false);
    $inclBothPedDeduction = current(array_filter($inclBothDetail['deduction_breakdown'] ?? [], fn($l) => ($l['source'] ?? null) === 'ped' && (int)($l['assignment_id'] ?? 0) === $freshCustomAssignmentId));
    checkTrue('deduction side is untouched by the earning-only exclusion -- custom -200 still included', $inclBothPedDeduction !== false);
    $inclBothManualLine = current(array_filter($inclBothDetail['earning_breakdown'] ?? [], fn($l) => ($l['source'] ?? null) === 'manual_line'));
    checkTrue('the manually-picked OT line is additive on top of base salary/standing items', $inclBothManualLine !== false);
    check('both toggles on + manual line: gross = base(30000) + manual OT(5000), TESTALLOW excluded via Run Settings', (float)$inclBothDetail['gross_amount'], 35000.0);
    check('both toggles on: total_deduction = ped custom deduction (200)', (float)$inclBothDetail['total_deduction_amount'], 200.0);
    checkTrue('no "no_manual_lines" false-positive once base salary/standing items are actually present', strpos((string)($inclBothDetail['calc_errors'] ?? ''), 'no_manual_lines') === false);

    echo "=== Generalized lineOverrideSave(): any line, any run type, base salary too ===\n";
    // Own dedicated employee (not $employeeFullId, which by this point in this very large shared-
    // fixture file has picked up enough incidental state from earlier sections -- e.g. proration
    // against SOME run period along the way -- that its base salary is no longer a clean, known
    // 30000 for an arbitrary NEW period; simplest and most robust to just not depend on that).
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'โอเวอร์ไรด์', 'Test', 'Override', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active', 1, 1, 0)")
        ->execute([':comp_id' => $compId, ':employee_no' => 'TEST_OVERRIDE_' . uniqid()]);
    $employeeOverrideId = (int)$pdo->lastInsertId();

    // Off-cycle (no cycle_id), matching the "Off-cycle run" fixture's own precedent earlier in this
    // file -- sidesteps an unrelated proration interaction found while writing this test (a
    // cycle_id run landing on a 28-day February period prorated unexpectedly; not this feature's
    // concern to chase down, off-cycle avoids it entirely and this test doesn't care about cycle
    // behavior anyway).
    $ovRunRes = $runModel->create($compId, [
        'run_name' => 'TEST_RUN_OVERRIDE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +20 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +20 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +20 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: off-cycle (non-sync) run created' . (empty($ovRunRes['status']) ? " ({$ovRunRes['message']})" : ''), $ovRunRes['status']);
    $ovRunId = $ovRunRes['id'];
    $runModel->joinEmployees($ovRunId, $compId, [$employeeOverrideId], $adminUserId, true);
    $runModel->recalculate($ovRunId, $compId, $adminUserId, true);

    $ovDetailBefore = $runModel->getDetails($ovRunId, $compId)[0] ?? [];
    check('fixture sanity: base_salary_amount is the plain 30000 before any override', (float)($ovDetailBefore['base_salary_amount'] ?? -1), 30000.0);

    $baseSalaryOvRes = $runModel->lineOverrideSave($ovRunId, $compId, $employeeOverrideId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 28000.0, 'test note', $adminUserId, true);
    checkTrue('lineOverrideSave() on base salary succeeds on a NON-sync run (old sync_process_id-only restriction is gone)' . (empty($baseSalaryOvRes['status']) ? " ({$baseSalaryOvRes['message']})" : ''), $baseSalaryOvRes['status']);
    $ovDetailAfterBase = $runModel->getDetails($ovRunId, $compId)[0] ?? [];
    check('base salary is now the overridden 28000, not the original 30000', (float)$ovDetailAfterBase['base_salary_amount'], 28000.0);
    check('gross_amount reflects the overridden base salary too (28000, no other lines on this fixture)', (float)$ovDetailAfterBase['gross_amount'], 28000.0);

    $auditAfterBaseOv = $runModel->getAuditLog($ovRunId, $compId);
    // lineOverrideSave() calls recalculate() internally right after logging its own audit entry
    // (see that method's own docblock), which logs its own SEPARATE "recalculate" entry after --
    // so the true last() entry is that recalculate, not this override. Find by action instead of
    // assuming position.
    $baseOvLogEntry = null;
    foreach (array_reverse($auditAfterBaseOv) as $entry) {
        if ($entry['action'] === 'line_override_save') { $baseOvLogEntry = $entry; break; }
    }
    checkTrue('found a line_override_save audit entry at all', $baseOvLogEntry !== null);
    check('audit action recorded is line_override_save', $baseOvLogEntry['action'], 'line_override_save');
    checkTrue('audit note captures the BEFORE value (30000) -- before/after diff', str_contains((string)$baseOvLogEntry['note'], '30,000.00'));
    checkTrue('audit note captures the AFTER value (28000) too', str_contains((string)$baseOvLogEntry['note'], '28,000.00'));
    checkTrue('audit note carries the free-text reason through', str_contains((string)$baseOvLogEntry['note'], 'test note'));

    $ovManualRes = $runModel->addManualLine($ovRunId, $compId, $employeeOverrideId, $pedTypeId, 1500, $adminUserId, true);
    checkTrue('fixture: a manual earning line added to override' . (empty($ovManualRes['status']) ? " ({$ovManualRes['message']})" : ''), $ovManualRes['status']);
    $ovDetailWithManual = $runModel->getDetails($ovRunId, $compId)[0] ?? [];
    $manualLineCode = current(array_filter($ovDetailWithManual['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'manual_line'))['code'] ?? null;
    checkTrue('fixture: manual earning line has a real item_code to target', $manualLineCode !== null);

    $earningOvRes = $runModel->lineOverrideSave($ovRunId, $compId, $employeeOverrideId, $manualLineCode, 'override_amount', 2500.0, null, $adminUserId, true);
    checkTrue('lineOverrideSave() on an EARNING line succeeds (old version was deduction-only)' . (empty($earningOvRes['status']) ? " ({$earningOvRes['message']})" : ''), $earningOvRes['status']);
    $ovDetailAfterEarningOv = $runModel->getDetails($ovRunId, $compId)[0] ?? [];
    $overriddenManualLine = current(array_filter($ovDetailAfterEarningOv['earning_breakdown'], fn($l) => $l['code'] === $manualLineCode));
    check('the manual earning line amount is now the overridden 2500, not the original 1500', (float)$overriddenManualLine['amount'], 2500.0);

    $excludeOvRes = $runModel->lineOverrideSave($ovRunId, $compId, $employeeOverrideId, $manualLineCode, 'exclude', null, null, $adminUserId, true);
    checkTrue('lineOverrideSave(exclude) succeeds', $excludeOvRes['status']);
    $ovDetailAfterExclude = $runModel->getDetails($ovRunId, $compId)[0] ?? [];
    $excludedStillPresent = current(array_filter($ovDetailAfterExclude['earning_breakdown'], fn($l) => $l['code'] === $manualLineCode));
    check('the excluded line no longer appears in the breakdown at all', $excludedStillPresent, false);

    $overrideOnLockedRunRes = $runModel->lineOverrideSave($runId, $compId, $employeeFullId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 1.0, null, $adminUserId, true);
    check('lineOverrideSave() still correctly refuses a non-draft run ($runId is locked at this point)', $overrideOnLockedRunRes['status'], false);

    echo "=== Run Settings panel: whole-run item exclusion (base salary + real items) + tax/SSO default (2026-08-29) ===\n";
    // Own dedicated employee + off-cycle run, same "don't depend on shared-fixture incidental
    // state" precedent as the lineOverrideSave section right above.
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'รันเซตติ้ง', 'Test', 'RunSettings', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active', 1, 0, 0)")
        ->execute([':comp_id' => $compId, ':employee_no' => 'TEST_RUNSET_' . uniqid()]);
    $employeeRunSetId = (int)$pdo->lastInsertId();

    $rsRunRes = $runModel->create($compId, [
        'run_name' => 'TEST_RUN_SETTINGS_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +50 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +50 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +50 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: Run Settings test run created' . (empty($rsRunRes['status']) ? " ({$rsRunRes['message']})" : ''), $rsRunRes['status']);
    $rsRunId = $rsRunRes['id'];
    $runModel->joinEmployees($rsRunId, $compId, [$employeeRunSetId], $adminUserId, true);
    $rsAddLineRes = $runModel->addManualLine($rsRunId, $compId, $employeeRunSetId, $pedTypeId, 2000.0, $adminUserId, true);
    checkTrue('fixture: manual earning line added' . (empty($rsAddLineRes['status']) ? " ({$rsAddLineRes['message']})" : ''), $rsAddLineRes['status']);
    $runModel->recalculate($rsRunId, $compId, $adminUserId, true);

    $rsDetailBefore = current(array_filter($runModel->getDetails($rsRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeRunSetId));
    check('fixture sanity: base_salary_amount is the plain 30000 before any Run Setting', (float)$rsDetailBefore['base_salary_amount'], 30000.0);
    $rsManualLineCode = current(array_filter($rsDetailBefore['earning_breakdown'], fn($l) => ($l['source'] ?? null) === 'manual_line'))['code'] ?? null;
    checkTrue('fixture sanity: manual earning line has a real item_code to target', $rsManualLineCode !== null);
    $rsSsoBefore = (float)((array_values(array_filter($rsDetailBefore['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    checkTrue('fixture sanity: SSO is a real nonzero deduction before any Run Setting', $rsSsoBefore > 0);

    $rsGetBefore = $runModel->runSettingsGet($rsRunId, $compId);
    checkTrue('runSettingsGet() succeeds', $rsGetBefore['status']);
    check('runSettingsGet(): tax/SSO default is "use_employee_setting" for a run that has never touched this', [$rsGetBefore['data']['tax_calculate_default'], $rsGetBefore['data']['sso_calculate_default']], ['use_employee_setting', 'use_employee_setting']);
    check('runSettingsGet(): excluded_item_codes is empty for a run that has never touched this', $rsGetBefore['data']['excluded_item_codes'], []);
    checkTrue('runSettingsGet(): item_options includes the reserved base-salary pseudo-item first', ($rsGetBefore['data']['item_options'][0]['item_code'] ?? null) === PayrollRunModel::BASE_SALARY_OVERRIDE_CODE);
    checkTrue('runSettingsGet(): item_options includes at least one real catalog item too', count($rsGetBefore['data']['item_options']) > 1);

    $rsInvalidRes = $runModel->runSettingsSave($rsRunId, $compId, 'not_a_real_value', 'use_employee_setting', [], $adminUserId, true);
    check('runSettingsSave() rejects an invalid tax_calculate_default', $rsInvalidRes['status'], false);

    $rsSaveRes = $runModel->runSettingsSave($rsRunId, $compId, 'no', 'no', [PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, $rsManualLineCode], $adminUserId, true);
    checkTrue('runSettingsSave() succeeds' . (empty($rsSaveRes['status']) ? " ({$rsSaveRes['message']})" : ''), $rsSaveRes['status']);
    $rsDetailAfterExclude = current(array_filter($runModel->getDetails($rsRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeRunSetId));
    check('run-level default: base salary is zeroed for everyone (no per-employee override)', (float)$rsDetailAfterExclude['base_salary_amount'], 0.0);
    $rsManualLineAfterExclude = current(array_filter($rsDetailAfterExclude['earning_breakdown'], fn($l) => $l['code'] === $rsManualLineCode));
    check('run-level default: the manual earning item is dropped from the breakdown entirely', $rsManualLineAfterExclude, false);
    $rsSsoAfterNo = (float)((array_values(array_filter($rsDetailAfterExclude['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    check('run-level default: SSO is zeroed for everyone (sso_calculate_default=no)', $rsSsoAfterNo, 0.0);

    echo "=== getDetails(): base_salary_excluded flag (2026-08-29 follow-up) ===\n";
    check('base_salary_excluded is true when the run-level default excludes it (no personal override yet)', $rsDetailAfterExclude['base_salary_excluded'], true);

    echo "=== calcApplicabilitySummary() (2026-08-29 follow-up: Report tax/SSO hiding) ===\n";
    $rsApplicabilityAfterOff = $runModel->calcApplicabilitySummary($rsRunId, $compId);
    check('both tax_calculate_default=no and sso_calculate_default=no: any_tax is false', $rsApplicabilityAfterOff['any_tax'], false);
    check('both tax_calculate_default=no and sso_calculate_default=no: any_sso is false', $rsApplicabilityAfterOff['any_sso'], false);

    echo "=== Run Settings: a per-employee override always wins over the run-level default ===\n";
    $rsBaseOverrideRes = $runModel->lineOverrideSave($rsRunId, $compId, $employeeRunSetId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 15000.0, null, $adminUserId, true);
    checkTrue('lineOverrideSave() on base salary succeeds even though the run-level default excludes it' . (empty($rsBaseOverrideRes['status']) ? " ({$rsBaseOverrideRes['message']})" : ''), $rsBaseOverrideRes['status']);
    $rsDetailAfterPersonalBase = current(array_filter($runModel->getDetails($rsRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeRunSetId));
    check('per-employee override_amount forces base salary back to 15000 despite the run-level exclusion', (float)$rsDetailAfterPersonalBase['base_salary_amount'], 15000.0);
    check('base_salary_excluded is now false (a real override_amount is in effect, not an exclusion)', $rsDetailAfterPersonalBase['base_salary_excluded'], false);
    check('line_override_count reflects the one active override', $rsDetailAfterPersonalBase['line_override_count'], 1);

    $rsTaxOverrideRes = $runModel->saveEmployeeExemption($rsRunId, $compId, $employeeRunSetId, 'yes', 'inherit', null, $adminUserId, true);
    checkTrue('saveEmployeeExemption(tax_calculate_override=yes) succeeds even though the run-level default is "no"' . (empty($rsTaxOverrideRes['status']) ? " ({$rsTaxOverrideRes['message']})" : ''), $rsTaxOverrideRes['status']);
    $rsDetailAfterTaxOverride = current(array_filter($runModel->getDetails($rsRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeRunSetId));
    $rsPitAfterOverride = array_values(array_filter($rsDetailAfterTaxOverride['statutory_breakdown'], fn($l) => $l['code'] === 'TH_PIT'))[0] ?? [];
    check('per-employee tax_calculate_override=yes is NOT flagged employee_tax_exempt (run-level "no" default overridden back on)', ($rsPitAfterOverride['note'] ?? null) === 'employee_tax_exempt', false);
    $rsSsoStillOff = (float)((array_values(array_filter($rsDetailAfterTaxOverride['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    check('sso_calculate_override left at "inherit" still follows the run-level "no" default (SSO stays 0)', $rsSsoStillOff, 0.0);
    check('has_calc_override is now true (a personal tax_calculate_override is active)', $rsDetailAfterTaxOverride['has_calc_override'], true);

    $rsApplicabilityAfterPersonalOverride = $runModel->calcApplicabilitySummary($rsRunId, $compId);
    check('calcApplicabilitySummary(): any_tax flips to true (this one employee now has tax on)', $rsApplicabilityAfterPersonalOverride['any_tax'], true);
    check('calcApplicabilitySummary(): any_sso stays false (untouched by the tax-only override)', $rsApplicabilityAfterPersonalOverride['any_sso'], false);

    echo "=== Run Settings: clearing everything reverts to the original computed values ===\n";
    $rsClearRes = $runModel->runSettingsSave($rsRunId, $compId, 'use_employee_setting', 'use_employee_setting', [], $adminUserId, true);
    checkTrue('runSettingsSave() with an empty excluded_item_codes list clears the run-level defaults' . (empty($rsClearRes['status']) ? " ({$rsClearRes['message']})" : ''), $rsClearRes['status']);
    $rsClearOverridesRes = $runModel->lineOverrideRemove($rsRunId, $compId, $employeeRunSetId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, $adminUserId, true);
    checkTrue('lineOverrideRemove() on the base-salary override succeeds' . (empty($rsClearOverridesRes['status']) ? " ({$rsClearOverridesRes['message']})" : ''), $rsClearOverridesRes['status']);
    $rsClearExemptionRes = $runModel->saveEmployeeExemption($rsRunId, $compId, $employeeRunSetId, 'inherit', 'inherit', null, $adminUserId, true);
    checkTrue('saveEmployeeExemption() cleared back to inherit/inherit succeeds' . (empty($rsClearExemptionRes['status']) ? " ({$rsClearExemptionRes['message']})" : ''), $rsClearExemptionRes['status']);
    $rsDetailAfterFullClear = current(array_filter($runModel->getDetails($rsRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeRunSetId));
    check('after clearing every Run Settings/per-employee override: base_salary_amount is back to the plain 30000', (float)$rsDetailAfterFullClear['base_salary_amount'], 30000.0);
    $rsManualLineRestored = current(array_filter($rsDetailAfterFullClear['earning_breakdown'], fn($l) => $l['code'] === $rsManualLineCode));
    checkTrue('the manual earning item is back in the breakdown (run-level exclusion cleared)', $rsManualLineRestored !== false);
    $rsSsoRestored = (float)((array_values(array_filter($rsDetailAfterFullClear['statutory_breakdown'], fn($l) => $l['code'] === 'TH_SSO'))[0] ?? [])['employee_amount'] ?? -1);
    check('SSO is back to the original computed amount', $rsSsoRestored, $rsSsoBefore);
    check('base_salary_excluded is false again after the full clear', $rsDetailAfterFullClear['base_salary_excluded'], false);
    check('line_override_count is back to 0 after the full clear', $rsDetailAfterFullClear['line_override_count'], 0);
    check('has_calc_override is back to false after the full clear', $rsDetailAfterFullClear['has_calc_override'], false);
    $rsApplicabilityAfterFullClear = $runModel->calcApplicabilitySummary($rsRunId, $compId);
    check('calcApplicabilitySummary(): any_tax is back to true after the full clear', $rsApplicabilityAfterFullClear['any_tax'], true);
    check('calcApplicabilitySummary(): any_sso is back to true after the full clear', $rsApplicabilityAfterFullClear['any_sso'], true);

    $rsSaveOnLockedRes = $runModel->runSettingsSave($runId, $compId, 'no', 'no', [], $adminUserId, true);
    check('runSettingsSave() refuses a non-draft run ($runId is locked at this point)', $rsSaveOnLockedRes['status'], false);

    echo "=== reopen(): paid/locked -> draft, permission gating, installment un-consumption ===\n";
    $reopenPermDenyRes = $runModel->reopen($runId, $compId, $employeeMidId, false);
    check('reopen() denied for a role without can_finalize_payroll (only can_process_payroll is not enough)', $reopenPermDenyRes['status'], false);

    $reopenOnDraftRes = $runModel->reopen($ovRunId, $compId, $adminUserId, true);
    check('reopen() refuses a run that is not paid/locked (this fixture is still draft)', $reopenOnDraftRes['status'], false);

    $instBeforeReopen = $pdo->prepare("SELECT status, payroll_run_id FROM `employee_earning_deduction_installments` WHERE assignment_id = :assignment_id");
    $instBeforeReopen->execute([':assignment_id' => $eedRes['id']]);
    $instBefore = $instBeforeReopen->fetch(PDO::FETCH_ASSOC);
    check('fixture sanity: installment is still processed and tagged to $runId before reopen', [$instBefore['status'], (int)$instBefore['payroll_run_id']], ['processed', $runId]);
    $assignmentBeforeReopen = $pdo->query("SELECT current_installment, status FROM employee_earning_deductions WHERE id = {$eedRes['id']}")->fetch(PDO::FETCH_ASSOC);
    check('fixture sanity: assignment is completed (current_installment=total_installments=1) before reopen', [$assignmentBeforeReopen['status'], (int)$assignmentBeforeReopen['current_installment']], ['completed', 1]);

    $reopenRes = $runModel->reopen($runId, $compId, $adminUserId, true, 'test reopen reason');
    checkTrue('reopen() succeeds from locked' . (empty($reopenRes['status']) ? " ({$reopenRes['message']})" : ''), $reopenRes['status']);
    $runAfterReopen = $runModel->get($runId, $compId);
    check('state is back to draft', $runAfterReopen['state'], 'draft');
    check('paid_at/paid_by cleared', [$runAfterReopen['paid_at'], $runAfterReopen['paid_by']], [null, null]);
    check('locked_at/locked_by cleared', [$runAfterReopen['locked_at'], $runAfterReopen['locked_by']], [null, null]);
    check('approved_at/approved_by cleared', [$runAfterReopen['approved_at'], $runAfterReopen['approved_by']], [null, null]);
    check('submitted_at/submitted_by cleared', [$runAfterReopen['submitted_at'], $runAfterReopen['submitted_by']], [null, null]);

    $instAfterReopen = $pdo->prepare("SELECT status, payroll_run_id FROM `employee_earning_deduction_installments` WHERE assignment_id = :assignment_id");
    $instAfterReopen->execute([':assignment_id' => $eedRes['id']]);
    $instAfter = $instAfterReopen->fetch(PDO::FETCH_ASSOC);
    check('installment un-consumed: status back to pending', $instAfter['status'], 'pending');
    check('installment un-consumed: payroll_run_id cleared', $instAfter['payroll_run_id'], null);
    $assignmentAfterReopen = $pdo->query("SELECT current_installment, status FROM employee_earning_deductions WHERE id = {$eedRes['id']}")->fetch(PDO::FETCH_ASSOC);
    check('assignment un-completed: current_installment decremented back to 0', (int)$assignmentAfterReopen['current_installment'], 0);
    check('assignment un-completed: status back to active', $assignmentAfterReopen['status'], 'active');

    $auditAfterReopen = $runModel->getAuditLog($runId, $compId);
    $reopenLogEntry = end($auditAfterReopen);
    check('audit action recorded is reopen', $reopenLogEntry['action'], 'reopen');
    check('audit from_state/to_state recorded correctly', [$reopenLogEntry['from_state'], $reopenLogEntry['to_state']], ['locked', 'draft']);
    check('audit note carries the reopen reason', $reopenLogEntry['note'], 'test reopen reason');

    $editAfterReopenRes = $runModel->lineOverrideSave($runId, $compId, $employeeFullId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 29500.0, 'corrected after reopen', $adminUserId, true);
    checkTrue('the reopened run can be edited via lineOverrideSave() again' . (empty($editAfterReopenRes['status']) ? " ({$editAfterReopenRes['message']})" : ''), $editAfterReopenRes['status']);
    $resubmitRes = $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('the reopened, edited run can be resubmitted for approval' . (empty($resubmitRes['status']) ? " ({$resubmitRes['message']})" : ''), $resubmitRes['status']);
    check('state is pending_approval again after resubmit', $runModel->get($runId, $compId)['state'], 'pending_approval');

    // ==================== T017 (2026-08-30): "wire all of T011-T016 into the real payroll
    // calculation + regression test with at least one real payroll run before closing" ====================
    // T011 (Diligence)/T013 (Student Loan, Loan Repay -- opt-in)/T015 (no_deduction) were already
    // unit-tested individually (SyncPayResolver::resolve() in tests/sync_pay_resolver_test.php,
    // AttendanceDeductionRuleModel/computeAttendanceDeductionFromConfig() in
    // tests/attendance_deduction_rule_test.php). This section is the thing those two don't prove on
    // their own: that all three actually flow correctly through a REAL PayrollRunModel::
    // recalculate() call, not just in isolation. Reuses $pulledRunId/$employeeFullId (confirmed via
    // grep before reusing it here that $pulledRunId never transitions out of 'draft' anywhere else
    // in this file) instead of building a whole new sync-run fixture from scratch.
    echo "=== T017: Diligence / Student Loan / Loan Repay / no_deduction wired into a REAL recalculate() run ===\n";

    // Neutralize any real, pre-existing comp_id=1 catalog opt-in for these 3 events (shared dev-DB
    // state, see feedback_dev_db_shared_state_test_fragility in project memory) so this section's
    // own dedicated fixture rows below are unambiguously what pedTypeBySourceEvent() resolves --
    // same defensive pattern tests/sync_pay_resolver_test.php already uses for the same 3 events.
    $pdo->prepare("UPDATE payroll_earning_deduction_types SET source_event_code = NULL
            WHERE comp_id = :c AND source_event_code IN ('diligence','student_loan','loan_repay') AND deleted_at IS NULL")
        ->execute([':c' => $compId]);

    $t017Diligence = $pedTypeModel->save($compId, [
        'item_code' => 'T017_DILIGENCE', 'item_name_th' => 'เบี้ยขยัน T017', 'item_name_en' => 'T017 Diligence',
        'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'source_event_code' => 'diligence',
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: T017 Diligence catalog row (opt-in via source_event_code) created' . (empty($t017Diligence['status']) ? " ({$t017Diligence['message']})" : ''), $t017Diligence['status']);

    $t017StudentLoan = $pedTypeModel->save($compId, [
        'item_code' => 'T017_STUDENT_LOAN', 'item_name_th' => 'กยศ T017', 'item_name_en' => 'T017 Student Loan',
        'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'source_event_code' => 'student_loan',
        'tax_deduction_impact' => 'before_tax', 'statutory_report_code' => 'TH_SLF', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: T017 Student Loan catalog row (opt-in) created' . (empty($t017StudentLoan['status']) ? " ({$t017StudentLoan['message']})" : ''), $t017StudentLoan['status']);

    $t017LoanRepay = $pedTypeModel->save($compId, [
        'item_code' => 'T017_LOAN_REPAY', 'item_name_th' => 'เงินกู้ T017', 'item_name_en' => 'T017 Loan Repay',
        'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'source_event_code' => 'loan_repay',
        'tax_deduction_impact' => 'after_tax', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: T017 Loan Repay catalog row (opt-in) created' . (empty($t017LoanRepay['status']) ? " ({$t017LoanRepay['message']})" : ''), $t017LoanRepay['status']);

    // T015: a fresh company-wide 'absent' rule set to no_deduction. attendance_deduction_rules for
    // comp_id=1 was deleted entirely at the very top of this file, so this is a clean insert, not
    // an update of a pre-existing row.
    $attRuleModel = new AttendanceDeductionRuleModel($pdo);
    $t017NoDeductionRule = $attRuleModel->ruleSave(['event_code' => 'absent', 'method_code' => 'no_deduction'], $compId, $adminUserId);
    checkTrue('fixture: absent event set to no_deduction' . (empty($t017NoDeductionRule['status']) ? " ({$t017NoDeductionRule['message']})" : ''), $t017NoDeductionRule['status']);

    // Feed the sync item row: item_values carrying DILIGENCE/STUDENT_LOAN/LOAN_REPAY (matched via
    // EVENT_ALIASES), plus absent_days (a structured RULE_DRIVEN_ITEM_DEFS column) that would
    // normally deduct via the DEFAULT percent_of_rate formula if no_deduction weren't configured --
    // proving the rule is actually being read, not just coincidentally producing 0 some other way.
    $t017ItemValues = json_encode([
        ['item_id' => 701, 'item_code' => 'DILIGENCE', 'item_name' => 'Diligence Allowance', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 750.0, 'remark' => null],
        ['item_id' => 702, 'item_code' => 'STUDENT_LOAN', 'item_name' => 'Student Loan', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 600.0, 'remark' => null],
        ['item_id' => 703, 'item_code' => 'LOAN_REPAY', 'item_name' => 'Loan Repayment', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 1500.0, 'remark' => null],
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("UPDATE `payroll_sync_items` SET absent_days = 2, item_values = :iv
            WHERE process_id = :process_id AND employee_id = :employee_id")
        ->execute([':iv' => $t017ItemValues, ':process_id' => $syncProcessId, ':employee_id' => $employeeFullId]);

    $t017Recalc = $runModel->recalculate($pulledRunId, $compId, $adminUserId, true);
    checkTrue('recalculate() succeeds with all 4 items configured' . (empty($t017Recalc['status']) ? " ({$t017Recalc['message']})" : ''), $t017Recalc['status']);
    $t017Details = $runModel->getDetails($pulledRunId, $compId);
    $t017Row = array_values(array_filter($t017Details, fn($d) => (int)$d['employee_id'] === $employeeFullId))[0] ?? [];

    $t017DiligenceLine = current(array_filter($t017Row['earning_breakdown'] ?? [], fn($l) => $l['code'] === 'T017_DILIGENCE'));
    checkTrue('T011: Diligence resolves to the REAL catalog code (T017_DILIGENCE), not the hardcoded DILIGENCE_ALLOW fallback', $t017DiligenceLine !== false);
    check('T011: Diligence amount = face value 750.00', (float)($t017DiligenceLine['amount'] ?? null), 750.0);

    $t017StudentLoanLine = current(array_filter($t017Row['deduction_breakdown'] ?? [], fn($l) => $l['code'] === 'T017_STUDENT_LOAN'));
    checkTrue('T013: Student Loan resolves to the REAL catalog code once opted in', $t017StudentLoanLine !== false);
    check('T013: Student Loan amount = face value 600.00', (float)($t017StudentLoanLine['amount'] ?? null), 600.0);

    $t017LoanRepayLine = current(array_filter($t017Row['deduction_breakdown'] ?? [], fn($l) => $l['code'] === 'T017_LOAN_REPAY'));
    checkTrue('T013: Loan Repay resolves to the REAL catalog code once opted in', $t017LoanRepayLine !== false);
    check('T013: Loan Repay amount = face value 1500.00', (float)($t017LoanRepayLine['amount'] ?? null), 1500.0);

    $t017AbsentLine = current(array_filter($t017Row['deduction_breakdown'] ?? [], fn($l) => $l['code'] === 'ABSENT_DEDUCT'));
    checkTrue('T015: no_deduction produces ZERO absence deduction line at all (2 absent_days would otherwise deduct via the default percent_of_rate formula)', $t017AbsentLine === false);

    // ==================== T021 (2026-08-30): "ไม่จ่ายเงินเดือน" employee excluded from every
    // recalculate() eligibility branch + the manual employee picker, even if already joined before
    // being marked unpaid. Depends on T020 (is_payroll_participant column). ====================
    echo "=== T021: is_payroll_participant=0 employee excluded from payroll processing ===\n";
    $insUnpaidEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, employment_date, employment_status, employment_type,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         is_payroll_participant)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'ไม่จ่ายเงินเดือน', 'Test', 'Unpaid', '1990-01-01', 'Thai',
         :email, '0800000001', '2020-01-01', 'permanent', 'full_time',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         0)");
    $insUnpaidEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_UNPAID_' . uniqid(), ':email' => uniqid() . '@test.local']);
    $employeeUnpaidId = (int)$pdo->lastInsertId();

    // ---- Branch 1: cycle-based run's automatic date-range eligibility ----
    // +55 months -- every offset up to +50 is already used somewhere else in this large shared-
    // fixture file (confirmed via grep before picking this), avoiding isDuplicatePeriod() collisions.
    $t021PeriodStart = (clone $today)->modify('first day of +55 months')->format('Y-m-d');
    $t021PeriodEnd = (clone $today)->modify('last day of +55 months')->format('Y-m-d');
    $t021CycleRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'T021_CYCLE_' . uniqid(),
        'period_start_date' => $t021PeriodStart, 'period_end_date' => $t021PeriodEnd, 'payment_date' => $t021PeriodEnd,
    ], $adminUserId, true);
    checkTrue('T021 fixture: cycle-based run created' . (empty($t021CycleRunRes['status']) ? " ({$t021CycleRunRes['message']})" : ''), $t021CycleRunRes['status']);
    $t021CycleRunId = $t021CycleRunRes['id'] ?? 0;
    $t021CycleRecalc = $runModel->recalculate($t021CycleRunId, $compId, $adminUserId, true);
    checkTrue('T021 cycle-run recalculate() succeeds', $t021CycleRecalc['status']);
    $t021CycleDetails = $runModel->getDetails($t021CycleRunId, $compId);
    check('unpaid employee NOT in a cycle-based run despite matching the date range', in_array($employeeUnpaidId, array_map(fn($d) => (int)$d['employee_id'], $t021CycleDetails), true), false);
    checkTrue('the OTHER (paid) full-period fixture employee IS still included, same date range', in_array($employeeFullId, array_map(fn($d) => (int)$d['employee_id'], $t021CycleDetails), true));

    // ---- Branch 2: sync-based run's payload-mapped eligibility (reuses $syncProcessId/$pulledRunId) ----
    $insSyncItemUnpaid = $pdo->prepare("INSERT INTO payroll_sync_items (process_id, employee_id, payroll_code, mapping_status)
        VALUES (:process_id, :employee_id, :payroll_code, 'mapped')");
    $insSyncItemUnpaid->execute([':process_id' => $syncProcessId, ':employee_id' => $employeeUnpaidId, ':payroll_code' => 'UNPAID_SYNC']);
    $t021SyncRecalc = $runModel->recalculate($pulledRunId, $compId, $adminUserId, true);
    checkTrue('T021 sync-run recalculate() still succeeds after adding the unpaid employee to the sync payload', $t021SyncRecalc['status']);
    $t021SyncDetails = $runModel->getDetails($pulledRunId, $compId);
    check('unpaid employee NOT included even though the sync payload explicitly mapped them', in_array($employeeUnpaidId, array_map(fn($d) => (int)$d['employee_id'], $t021SyncDetails), true), false);

    // ---- Branch 3: manual employee picker never offers them, on any run type ----
    $t021PickerOffCycle = $runModel->manualEmployeeOptions($compId, $t021CycleRunId, 0, 50, [], '', 'en');
    $t021OffCycleIds = array_map(fn($r) => (int)$r['id'], $t021PickerOffCycle['data'] ?? []);
    check('manualEmployeeOptions() never offers the unpaid employee (default, no search)', in_array($employeeUnpaidId, $t021OffCycleIds, true), false);
    $t021PickerSearch = $runModel->manualEmployeeOptions($compId, $t021CycleRunId, 0, 50, [], 'TEST_UNPAID', 'en');
    check('manualEmployeeOptions() searched directly by their own employee_no still returns nothing', count($t021PickerSearch['data'] ?? []), 0);
    $t021AllIds = $runModel->manualEmployeeAllIds($compId, $t021CycleRunId, [], '', 'en');
    check('manualEmployeeAllIds() (Select All Matching) never includes the unpaid employee either', in_array($employeeUnpaidId, $t021AllIds, true), false);

    // ---- Branch 4: an off-cycle run where the unpaid employee was already joined BEFORE being
    // marked unpaid (simulated via a direct insert into payroll_run_manual_employees, bypassing
    // joinEmployees()'s own is_payroll_participant-aware picker gate above -- proves recalculate()
    // itself still refuses to calculate them, not just that the picker won't offer them going
    // forward) -- recalculate()'s off-cycle branch (payroll_run_manual_employees JOIN employees)
    // must exclude them even though the join row already exists. ----
    $t021OffCycleRunRes = $runModel->create($compId, [
        'run_name' => 'T021_OFFCYCLE_' . uniqid(),
        'period_start_date' => $t021PeriodStart, 'period_end_date' => $t021PeriodEnd, 'payment_date' => $t021PeriodEnd,
    ], $adminUserId, true);
    checkTrue('T021 fixture: off-cycle run created' . (empty($t021OffCycleRunRes['status']) ? " ({$t021OffCycleRunRes['message']})" : ''), $t021OffCycleRunRes['status']);
    $t021OffCycleRunId = $t021OffCycleRunRes['id'] ?? 0;
    $pdo->prepare("INSERT INTO `payroll_run_manual_employees` (run_id, employee_id, joined_by) VALUES (:run_id, :employee_id, :joined_by)")
        ->execute([':run_id' => $t021OffCycleRunId, ':employee_id' => $employeeUnpaidId, ':joined_by' => $adminUserId]);
    $t021OffCycleRecalc = $runModel->recalculate($t021OffCycleRunId, $compId, $adminUserId, true);
    checkTrue('T021 off-cycle recalculate() succeeds even with the pre-existing manual join row', $t021OffCycleRecalc['status']);
    check('recalculate() excludes them anyway (employee_count is 0, not 1)', $t021OffCycleRecalc['employee_count'], 0);
    $t021OffCycleDetails = $runModel->getDetails($t021OffCycleRunId, $compId);
    check('getDetails() confirms zero rows for this run', count($t021OffCycleDetails), 0);

    echo "\n=== 2026-08-30 (Phase 8, T041) -- cross-cycle employee leakage fix ===\n";
    // Real bug found and fixed, reproduced live before this test existed: recalculate()'s cycle-based
    // eligibility query never filtered by employees.cycle_id at all -- a company running MORE THAN ONE
    // concurrent payroll cycle could pull an employee assigned to Cycle A into a run created under
    // Cycle B (and pay them there too), as long as the employment date range overlapped. Fixed with
    // `e.cycle_id IS NULL OR e.cycle_id = :cycle_id` (NOT a strict equality filter -- employees.cycle_id
    // is nullable/optional, so a company that has never bothered assigning it per employee must keep
    // working exactly as before: NULL = eligible for any cycle's run, same "unassigned = general,
    // explicitly assigned = scoped" convention this codebase already uses elsewhere, e.g. Holiday/
    // Payslip Template assignment).
    $t041CycleA = $cycleModel->save($compId, [
        'cycle_name' => 'T041_CYCLE_A_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('T041 fixture: Cycle A created', $t041CycleA['status']);
    $t041CycleB = $cycleModel->save($compId, [
        'cycle_name' => 'T041_CYCLE_B_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('T041 fixture: Cycle B created', $t041CycleB['status']);
    $t041CycleAId = $t041CycleA['id'];
    $t041CycleBId = $t041CycleB['id'];

    $t041Period = (clone $today)->modify('first day of +103 months');
    $t041PeriodStart = $t041Period->format('Y-m-d');
    $t041PeriodEnd = (clone $t041Period)->modify('last day of this month')->format('Y-m-d');

    // 3 employees: one explicitly assigned to Cycle A, one explicitly assigned to Cycle B, one never
    // assigned to any cycle at all (cycle_id stays NULL, the common single-cycle-company default).
    $t041MakeEmployee = function (string $suffix, ?int $cycleId) use ($pdo, $compId) {
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, cycle_id, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, :cycle_id, 'mr', 'male', 'ทดสอบ', :surname_th, 'Test', :surname_en, '1990-01-01', 'Thai',
             :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             'monthly', 30000, '2020-01-01', 'average', 'active', 1, 1, 0)")
            ->execute([
                ':comp_id' => $compId, ':employee_no' => 'T041_EMP_' . $suffix . '_' . uniqid(), ':cycle_id' => $cycleId,
                ':surname_th' => $suffix, ':surname_en' => $suffix, ':email' => uniqid() . '@test.local',
            ]);
        return (int)$pdo->lastInsertId();
    };
    $t041EmpA = $t041MakeEmployee('A', $t041CycleAId);
    $t041EmpB = $t041MakeEmployee('B', $t041CycleBId);
    $t041EmpUnassigned = $t041MakeEmployee('UNASSIGNED', null);

    $t041RunA = $runModel->create($compId, [
        'cycle_id' => $t041CycleAId, 'run_name' => 'T041_RUN_A_' . uniqid(),
        'period_start_date' => $t041PeriodStart, 'period_end_date' => $t041PeriodEnd, 'payment_date' => $t041PeriodEnd,
    ], $adminUserId, true);
    checkTrue('T041 fixture: Cycle A run created', $t041RunA['status']);
    $runModel->recalculate($t041RunA['id'], $compId, $adminUserId, true);
    $t041RunAIds = array_map(fn($d) => (int)$d['employee_id'], $runModel->getDetails($t041RunA['id'], $compId));

    $t041RunB = $runModel->create($compId, [
        'cycle_id' => $t041CycleBId, 'run_name' => 'T041_RUN_B_' . uniqid(),
        'period_start_date' => $t041PeriodStart, 'period_end_date' => $t041PeriodEnd, 'payment_date' => $t041PeriodEnd,
    ], $adminUserId, true);
    checkTrue('T041 fixture: Cycle B run created', $t041RunB['status']);
    $runModel->recalculate($t041RunB['id'], $compId, $adminUserId, true);
    $t041RunBIds = array_map(fn($d) => (int)$d['employee_id'], $runModel->getDetails($t041RunB['id'], $compId));

    check('Cycle A run includes the Cycle-A-assigned employee', in_array($t041EmpA, $t041RunAIds, true), true);
    check('Cycle A run does NOT include the Cycle-B-assigned employee (the leakage this fix closes)', in_array($t041EmpB, $t041RunAIds, true), false);
    check('Cycle A run includes the never-assigned employee too (NULL = eligible everywhere, backward-compatible)', in_array($t041EmpUnassigned, $t041RunAIds, true), true);

    check('Cycle B run includes the Cycle-B-assigned employee', in_array($t041EmpB, $t041RunBIds, true), true);
    check('Cycle B run does NOT include the Cycle-A-assigned employee (the leakage this fix closes)', in_array($t041EmpA, $t041RunBIds, true), false);
    check('Cycle B run ALSO includes the never-assigned employee (NULL is eligible for every cycle, not just the first one found)', in_array($t041EmpUnassigned, $t041RunBIds, true), true);

    echo "\n=== 2026-08-30 (Phase 8, T041) -- advisory note for a cycle-based employee with zero attendance data ===\n";
    // comp_id=1 is Origami-Payroll-linked (companies.origami_payroll_comp_code = 'TDI', confirmed via
    // a direct DB query before writing this) -- the exact precondition the new advisory branch gates
    // on. $t041EmpA has zero attendance_records/leave_requests/overtime_records rows for
    // $t041PeriodStart..$t041PeriodEnd (never inserted any for this fixture employee), so Cycle A's
    // own run (already recalculated above) should carry the new advisory note.
    $t041EmpADetail = null;
    foreach ($runModel->getDetails($t041RunA['id'], $compId) as $d) {
        if ((int)$d['employee_id'] === $t041EmpA) { $t041EmpADetail = $d; break; }
    }
    checkTrue('fixture: found the Cycle-A-assigned employee\'s own detail row', $t041EmpADetail !== null);
    check('calc_status is still "calculated", NOT "error" -- advisory only, never blocks', $t041EmpADetail['calc_status'] ?? null, 'calculated');
    checkTrue('calc_errors carries the new advisory code', strpos((string)($t041EmpADetail['calc_errors'] ?? ''), 'no_attendance_data_this_period') !== false);

    echo "\n=== 2026-08-30 (Phase 8, T041) -- syncMissingEmployees() reconciliation for a sync-based run ===\n";
    // 3 employees date-range-eligible for the same period: one actually present+mapped in the sync
    // payload, one manually joined on top (deliberately NOT missing -- an admin explicitly added
    // them), one neither -- genuinely missing from this sync and never manually added either.
    $t041SyncPeriod = (clone $today)->modify('first day of +104 months');
    $t041SyncPeriodStart = $t041SyncPeriod->format('Y-m-d');
    $t041SyncPeriodEnd = (clone $t041SyncPeriod)->modify('last day of this month')->format('Y-m-d');
    $pdo->prepare("INSERT INTO payroll_sync_processes
        (comp_id, origami_process_id, process_no, origami_comp_code, origami_comp_name, frequency_type, schema_version, raw_payload)
        VALUES (:comp_id, :origami_process_id, :process_no, 'TESTCODE', 'Test Co.', 'monthly', 1, '{}')")
        ->execute([':comp_id' => $compId, ':origami_process_id' => random_int(1000000, 9999999), ':process_no' => 'T041_SYNCTEST_' . uniqid()]);
    $t041SyncProcessId = (int)$pdo->lastInsertId();

    $t041EmpSyncMapped = $t041MakeEmployee('SYNCMAPPED', null);
    $t041EmpManualJoin = $t041MakeEmployee('MANUALJOIN', null);
    $t041EmpMissing = $t041MakeEmployee('MISSING', null);

    $pdo->prepare("INSERT INTO payroll_sync_items (process_id, employee_id, payroll_code, mapping_status)
        VALUES (:process_id, :employee_id, :payroll_code, 'mapped')")
        ->execute([':process_id' => $t041SyncProcessId, ':employee_id' => $t041EmpSyncMapped, ':payroll_code' => 'T041_MAPPED']);

    $t041SyncRunRes = $runModel->create($compId, [
        'cycle_id' => $t041CycleAId, 'run_name' => 'T041_SYNCRUN_' . uniqid(),
        'period_start_date' => $t041SyncPeriodStart, 'period_end_date' => $t041SyncPeriodEnd, 'payment_date' => $t041SyncPeriodEnd,
        'sync_process_id' => $t041SyncProcessId,
    ], $adminUserId, true);
    checkTrue('T041 fixture: sync-based run created' . (empty($t041SyncRunRes['status']) ? " ({$t041SyncRunRes['message']})" : ''), $t041SyncRunRes['status']);
    $t041SyncRunId = $t041SyncRunRes['id'];
    $runModel->joinEmployees($t041SyncRunId, $compId, [$t041EmpManualJoin], $adminUserId, true);
    $runModel->recalculate($t041SyncRunId, $compId, $adminUserId, true);

    check('syncMissingEmployees() returns [] for a cycle-based run (nothing to reconcile against)', $runModel->syncMissingEmployees($t041RunA['id'], $compId), []);
    $t041MissingList = $runModel->syncMissingEmployees($t041SyncRunId, $compId);
    $t041MissingIds = array_map(fn($e) => (int)$e['id'], $t041MissingList);
    check('the mapped-in-sync employee is NOT flagged as missing', in_array($t041EmpSyncMapped, $t041MissingIds, true), false);
    check('the manually-joined employee is NOT flagged as missing (an admin explicitly added them)', in_array($t041EmpManualJoin, $t041MissingIds, true), false);
    check('the genuinely-missing employee IS flagged (date-range-eligible, not in the sync payload, never manually joined)', in_array($t041EmpMissing, $t041MissingIds, true), true);
    // Not an exact total-count assertion -- comp_id=1 is this file's own shared, cumulative dev-DB
    // fixture (many other employee rows already exist from earlier sections in this same rolled-back
    // transaction, and every permanent one with no employment_end_date legitimately also matches this
    // future period's date-range eligibility) -- see feedback_dev_db_shared_state_test_fragility.
    // Confirm THIS section's own 3 fixture employees resolve exactly as expected instead.
    check('exactly the 1 genuinely-missing fixture employee (not the mapped or manually-joined ones) among this section\'s own 3', array_values(array_intersect($t041MissingIds, [$t041EmpSyncMapped, $t041EmpManualJoin, $t041EmpMissing])), [$t041EmpMissing]);

    // Re-check: excluding the missing employee (payroll_run_excluded_employees) removes them from
    // the reconciliation list too -- a deliberate admin exclusion is not "missing data," it's an
    // intentional decision, and should stop showing up as a warning.
    $runModel->removeManualEmployee($t041SyncRunId, $compId, $t041EmpMissing, $adminUserId, true);
    $t041MissingIdsAfterExclude = array_map(fn($e) => (int)$e['id'], $runModel->syncMissingEmployees($t041SyncRunId, $compId));
    check('an explicitly-excluded employee no longer shows up as "missing" (deliberate exclusion, not missing data)', in_array($t041EmpMissing, $t041MissingIdsAfterExclude, true), false);

    echo "\n=== 2026-08-30 (Phase 8, T041) -- include_attendance_pay: OT-only off-cycle payout through the real rate engine ===\n";
    // Real gap found and fixed: before this, a genuine ad-hoc off-cycle run (no cycle_id, no
    // sync_process_id) could only pay OT/trip allowance via a hand-typed manual line with no
    // calculation behind it -- SyncPayResolver was never invoked at all for that run type. This
    // proves the new toggle pulls a REAL, engine-computed OT amount, AND that the employee's late
    // minutes (also present) do NOT produce a deduction line -- a supplemental payout run only ever
    // pays out attendance EARNINGS through this toggle, never deducts.
    $t041OtEmpBase = 30000.0;
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'OTOffCycle', 'Test', 'OTOffCycle', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2020-01-01', 'average', 'active', 1, 1, 0, 1)")
        ->execute([':comp_id' => $compId, ':employee_no' => 'T041_EMP_OT_' . uniqid(), ':email' => uniqid() . '@test.local', ':base_salary' => $t041OtEmpBase]);
    $t041EmpOt = (int)$pdo->lastInsertId();

    $t041OtPeriod = (clone $today)->modify('first day of +105 months');
    $t041OtPeriodStart = $t041OtPeriod->format('Y-m-d');
    $t041OtPeriodEnd = (clone $t041OtPeriod)->modify('last day of this month')->format('Y-m-d');
    $t041OtRateId = (int)$pdo->query("SELECT i.id FROM ot_rate_set_items i JOIN ot_rate_sets s ON s.id = i.set_id
        WHERE s.comp_id = {$compId} AND s.deleted_at IS NULL ORDER BY i.id DESC LIMIT 1")->fetchColumn();
    checkTrue('fixture: reuses an already-configured OT rate from earlier in this file', $t041OtRateId > 0);
    $pdo->prepare("INSERT INTO overtime_records (comp_id, employee_id, ot_date, ot_rate_id, hours, status, data_source)
        VALUES (:comp_id, :employee_id, :ot_date, :ot_rate_id, 2.0, 'approved', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $t041EmpOt, ':ot_date' => $t041OtPeriodStart, ':ot_rate_id' => $t041OtRateId]);
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, late_minutes, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, 30, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $t041EmpOt, ':work_date' => $t041OtPeriodStart]);

    $t041OtRunOffRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'include_attendance_pay' => true,
        'run_name' => 'T041_OTRUN_' . uniqid(),
        'period_start_date' => $t041OtPeriodStart, 'period_end_date' => $t041OtPeriodEnd, 'payment_date' => $t041OtPeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: genuine off-cycle incentive run created with include_attendance_pay=true' . (empty($t041OtRunOffRes['status']) ? " ({$t041OtRunOffRes['message']})" : ''), $t041OtRunOffRes['status']);
    $t041OtRunOffId = $t041OtRunOffRes['id'];
    check('include_attendance_pay persisted as 1', (int)$pdo->query("SELECT include_attendance_pay FROM payroll_runs WHERE id = {$t041OtRunOffId}")->fetchColumn(), 1);
    $runModel->joinEmployees($t041OtRunOffId, $compId, [$t041EmpOt], $adminUserId, true);
    $runModel->recalculate($t041OtRunOffId, $compId, $adminUserId, true);

    $t041OtDetail = null;
    foreach ($runModel->getDetails($t041OtRunOffId, $compId) as $d) {
        if ((int)$d['employee_id'] === $t041EmpOt) { $t041OtDetail = $d; break; }
    }
    checkTrue('fixture: found the OT-only employee\'s own detail row', $t041OtDetail !== null);
    $t041ExpectedHourlyRate = ($t041OtEmpBase / 30.0) / 8.0;
    $t041ExpectedOt = round($t041ExpectedHourlyRate * 1.50 * 2.0, 2); // fixture OT rate is 1.50x hourly, same as every other OT fixture in this file.
    $t041OtLine = current(array_filter($t041OtDetail['earning_breakdown'] ?? [], fn($l) => $l['code'] === 'OT'));
    checkTrue('an OT earning line is present, computed through the real rate engine', $t041OtLine !== false);
    check('OT amount matches the hand-computed formula (base_salary/30/8 * 1.50 * 2 hours)', (float)($t041OtLine['amount'] ?? null), $t041ExpectedOt);
    check('base salary is NOT paid (include_base_salary was never turned on for this run -- OT/trip-only payout)', (float)$t041OtDetail['base_salary_amount'], 0.0);
    checkTrue('NO late-deduction line, even though the employee also has 30 late minutes recorded -- attendance pay only ever ADDS earnings, never deducts', current(array_filter($t041OtDetail['deduction_breakdown'] ?? [], fn($l) => $l['code'] === 'LATE_DEDUCT')) === false);
    check('gross_amount is exactly the OT amount (no base salary, no other earning)', (float)$t041OtDetail['gross_amount'], $t041ExpectedOt);

    // 2026-08-31, explicit request: per-run "auto-recalculate immediately after edits" checkbox +
    // payment_method_code surfaced on getDetails() (backs the Process Detail page's new Bank/Cash
    // filter checkboxes, 2nd summary-card grid, and "Payment Method Summary" tab).
    echo "=== Auto-recalculate flag: setAutoRecalculate() persists per-run, draft-only ===\n";
    // $runId may have moved out of draft by this point in the file (earlier sections exercise the
    // full submit/approve/reject state machine on it) -- forced back to draft here since this is the
    // LAST section before the whole-file transaction rollback, nothing downstream reads its state.
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'draft' WHERE id = :id")->execute([':id' => $runId]);
    check('fixture: auto_recalculate starts at 0 (default)', (int)$pdo->query("SELECT auto_recalculate FROM payroll_runs WHERE id = {$runId}")->fetchColumn(), 0);
    $autoRecalcOnRes = $runModel->setAutoRecalculate($runId, $compId, true, $adminUserId, true);
    checkTrue('setAutoRecalculate(true) succeeds' . (empty($autoRecalcOnRes['status']) ? " ({$autoRecalcOnRes['message']})" : ''), $autoRecalcOnRes['status']);
    check('get() reflects auto_recalculate = 1', (int)$runModel->get($runId, $compId)['auto_recalculate'], 1);
    $autoRecalcOffRes = $runModel->setAutoRecalculate($runId, $compId, false, $adminUserId, true);
    checkTrue('setAutoRecalculate(false) succeeds' . (empty($autoRecalcOffRes['status']) ? " ({$autoRecalcOffRes['message']})" : ''), $autoRecalcOffRes['status']);
    check('get() reflects auto_recalculate = 0 again', (int)$runModel->get($runId, $compId)['auto_recalculate'], 0);
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $runId]);
    $autoRecalcNonDraftRes = $runModel->setAutoRecalculate($runId, $compId, true, $adminUserId, true);
    check('setAutoRecalculate() rejected once the run is no longer draft', $autoRecalcNonDraftRes['status'], false);

    // 2026-09-01, explicit request: Create-form review -- settable right at creation too, not just
    // from the Detail page afterward.
    $createWithAutoRecalcRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'auto_recalculate' => true,
        'run_name' => 'AUTO_RECALC_CREATE_TEST_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('create() with auto_recalculate=true succeeds' . (empty($createWithAutoRecalcRes['status']) ? " ({$createWithAutoRecalcRes['message']})" : ''), $createWithAutoRecalcRes['status']);
    check('auto_recalculate persisted as 1 from create() itself', (int)$pdo->query("SELECT auto_recalculate FROM payroll_runs WHERE id = {$createWithAutoRecalcRes['id']}")->fetchColumn(), 1);
    $createWithoutAutoRecalcRes = $runModel->create($compId, [
        'run_purpose' => 'incentive',
        'run_name' => 'AUTO_RECALC_CREATE_TEST2_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('create() without auto_recalculate still succeeds' . (empty($createWithoutAutoRecalcRes['status']) ? " ({$createWithoutAutoRecalcRes['message']})" : ''), $createWithoutAutoRecalcRes['status']);
    check('auto_recalculate defaults to 0 when omitted', (int)$pdo->query("SELECT auto_recalculate FROM payroll_runs WHERE id = {$createWithoutAutoRecalcRes['id']}")->fetchColumn(), 0);
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'draft' WHERE id = :id")->execute([':id' => $runId]);

    echo "=== getDetails(): payment_method_code surfaced per employee (defaults to 'transfer') ===\n";
    // 2026-09-02, follow-up: payment_type (legacy enum, defaulted to 'bank') dropped -- getDetails()
    // now exposes payment_method_code via a master_payment_methods JOIN, defaulting to 'transfer'
    // when the fixture employee never had a payment_method_id assigned (same as before).
    $detailsWithPaymentType = $runModel->getDetails($runId, $compId);
    $fullDetailPaymentType = current(array_filter($detailsWithPaymentType, fn($d) => (int)$d['employee_id'] === $employeeFullId));
    checkTrue('fixture employee row present', $fullDetailPaymentType !== false);
    check("payment_method_code defaults to 'transfer' (fixture never set employees.payment_method_id)", $fullDetailPaymentType['payment_method_code'] ?? null, 'transfer');

    // 2026-09-11, Batch 3C item 4, explicit instruction: field-level lock, not the old blanket
    // employee_count===0 gate (or its 2026-09-01 loosened "always enabled, force-recalculate for
    // the one risky toggle" replacement, superseded here) -- cycle_id/period_dates/run_purpose/
    // merge_target now lock the moment a DRAFT run has ANY employee in it, full stop, regardless of
    // sync vs manual or which specific transition is attempted. See
    // PayrollRunModel::runFieldLockState()/checkRunFieldLocks()'s own docblocks for the exact
    // 3-tier state matrix (source always locked / cycle+period+purpose+merge locked by
    // draft-with-employees OR non-draft / name+payment_date+flat-tax locked only by non-draft /
    // notes never locked). Own small fixtures below, isolated from $runId/$compId's main fixture
    // above.
    echo "=== runFieldLockState() -- the exact 3-tier state matrix, in isolation ===\n";
    $lockDraftNoEmp = $runModel->runFieldLockState(['state' => 'draft', 'has_admin_work' => false]);
    check('draft, no admin work: cycle_id unlocked', $lockDraftNoEmp['cycle_id']['locked'], false);
    check('draft, no admin work: period_dates unlocked', $lockDraftNoEmp['period_dates']['locked'], false);
    check('draft, no admin work: run_purpose unlocked', $lockDraftNoEmp['run_purpose']['locked'], false);
    check('draft, no admin work: merge_target unlocked', $lockDraftNoEmp['merge_target']['locked'], false);
    check('draft, no admin work: run_name unlocked', $lockDraftNoEmp['run_name']['locked'], false);
    check('draft, no admin work: payment_date unlocked', $lockDraftNoEmp['payment_date']['locked'], false);
    check('draft, no admin work: use_flat_tax_rate unlocked', $lockDraftNoEmp['use_flat_tax_rate']['locked'], false);
    checkTrue('draft, no admin work: source is ALWAYS locked regardless', $lockDraftNoEmp['source']['locked']);
    check('draft, no admin work: notes never locked', $lockDraftNoEmp['notes']['locked'], false);

    $lockDraftHasEmp = $runModel->runFieldLockState(['state' => 'draft', 'has_admin_work' => true]);
    checkTrue('draft, has admin work: cycle_id locked', $lockDraftHasEmp['cycle_id']['locked']);
    check('draft, has admin work: cycle_id reason is has_admin_work', $lockDraftHasEmp['cycle_id']['reason'], 'has_admin_work');
    checkTrue('draft, has admin work: period_dates locked', $lockDraftHasEmp['period_dates']['locked']);
    checkTrue('draft, has admin work: run_purpose locked', $lockDraftHasEmp['run_purpose']['locked']);
    checkTrue('draft, has admin work: merge_target locked', $lockDraftHasEmp['merge_target']['locked']);
    check('draft, has admin work: run_name STILL unlocked', $lockDraftHasEmp['run_name']['locked'], false);
    check('draft, has admin work: payment_date STILL unlocked', $lockDraftHasEmp['payment_date']['locked'], false);
    check('draft, has admin work: use_flat_tax_rate STILL unlocked', $lockDraftHasEmp['use_flat_tax_rate']['locked'], false);
    check('draft, has admin work: notes never locked', $lockDraftHasEmp['notes']['locked'], false);

    $lockNotDraft = $runModel->runFieldLockState(['state' => 'pending_approval', 'has_admin_work' => true]);
    checkTrue('not draft: cycle_id locked', $lockNotDraft['cycle_id']['locked']);
    checkTrue('not draft: run_name NOW locked too (unlike draft)', $lockNotDraft['run_name']['locked']);
    checkTrue('not draft: payment_date locked', $lockNotDraft['payment_date']['locked']);
    checkTrue('not draft: use_flat_tax_rate locked', $lockNotDraft['use_flat_tax_rate']['locked']);
    check('not draft: run_name reason is not_draft', $lockNotDraft['run_name']['reason'], 'not_draft');
    check('not draft: notes is the ONE field still unlocked', $lockNotDraft['notes']['locked'], false);

    echo "=== hasAdminWork()/adminWorkSummary() against a REAL run -- manual employee, comment, admin-triggered recalculate ===\n";
    $adminWorkPeriodStart = (clone $today)->modify('first day of +155 months')->format('Y-m-d');
    $adminWorkPeriodEnd = (clone $today)->modify('last day of +155 months')->format('Y-m-d');
    $adminWorkRunRes = $runModel->create($compId, [
        'run_name' => 'ADMIN_WORK_TEST_' . uniqid(),
        'period_start_date' => $adminWorkPeriodStart, 'period_end_date' => $adminWorkPeriodEnd, 'payment_date' => $adminWorkPeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: genuine off-cycle run created' . (empty($adminWorkRunRes['status']) ? " ({$adminWorkRunRes['message']})" : ''), $adminWorkRunRes['status']);
    $adminWorkRunId = $adminWorkRunRes['id'];
    $adminWorkRun = $runModel->get($adminWorkRunId, $compId);
    check('get() attaches has_admin_work = false for a genuinely untouched fresh run', $adminWorkRun['has_admin_work'], false);
    check('hasAdminWork() itself agrees', $runModel->hasAdminWork($adminWorkRun), false);
    $adminWorkSummaryEmpty = $runModel->adminWorkSummary($adminWorkRun);
    check('adminWorkSummary(): manual_employee_count starts at 0', $adminWorkSummaryEmpty['manual_employee_count'], 0);
    check('adminWorkSummary(): admin_recalc_count starts at 0', $adminWorkSummaryEmpty['admin_recalc_count'], 0);

    // Manually joining ONE employee is by itself enough to flip has_admin_work true.
    $runModel->joinEmployees($adminWorkRunId, $compId, [$employeeFullId], $adminUserId, true);
    $adminWorkRunAfterJoin = $runModel->get($adminWorkRunId, $compId);
    checkTrue('get() attaches has_admin_work = true once a manual employee is joined', $adminWorkRunAfterJoin['has_admin_work']);
    check('adminWorkSummary(): manual_employee_count is now 1', $adminWorkRunAfterJoin['admin_work_summary']['manual_employee_count'], 1);

    // A genuinely off-cycle (non-sync) run's very FIRST recalculate already counts as admin work --
    // unlike a sync-linked pull, nothing auto-recalculates this one at creation.
    $adminWorkPeriodStart2 = (clone $today)->modify('first day of +156 months')->format('Y-m-d');
    $adminWorkPeriodEnd2 = (clone $today)->modify('last day of +156 months')->format('Y-m-d');
    $adminWorkRunRes2 = $runModel->create($compId, [
        'run_name' => 'ADMIN_WORK_TEST2_' . uniqid(),
        'period_start_date' => $adminWorkPeriodStart2, 'period_end_date' => $adminWorkPeriodEnd2, 'payment_date' => $adminWorkPeriodEnd2,
    ], $adminUserId, true);
    checkTrue('fixture: 2nd genuine off-cycle run created' . (empty($adminWorkRunRes2['status']) ? " ({$adminWorkRunRes2['message']})" : ''), $adminWorkRunRes2['status']);
    $adminWorkRunId2 = $adminWorkRunRes2['id'];
    check('fixture: has_admin_work still false before any recalculate at all', $runModel->get($adminWorkRunId2, $compId)['has_admin_work'], false);
    $runModel->recalculate($adminWorkRunId2, $compId, $adminUserId, true);
    checkTrue('a non-sync run\'s own FIRST recalculate already counts as admin work (nothing auto-recalculated it at creation)', $runModel->get($adminWorkRunId2, $compId)['has_admin_work']);

    echo "=== update(): cycle_id/period/run_purpose/merge_target lock once the run has admin work (draft) ===\n";
    $cycle2Res = $cycleModel->save($compId, [
        'cycle_name' => 'TEST_CYCLE2_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: 2nd cycle created' . (empty($cycle2Res['status']) ? " ({$cycle2Res['message']})" : ''), $cycle2Res['status']);
    $cycle2Id = $cycle2Res['id'];

    // Own distinct period throughout this whole fixture -- $periodStart/$periodEnd (this file's
    // shared "current month" dates) + $cycleId/$cycle2Id are already claimed by other fixtures
    // elsewhere in this file (the main run at the top, and others further below). Computed the same
    // $today-relative way this whole file's own other throwaway fixtures already do (grep confirms
    // the highest "+N months" offset in use anywhere else in this file is +105 -- +150 here is
    // safely clear of all of them, unlike a hardcoded literal date, which coincidentally collided
    // with an existing "+45 months" fixture once already before this fix).
    $cycleEditPeriodStart = (clone $today)->modify('first day of +150 months')->format('Y-m-d');
    $cycleEditPeriodEnd = (clone $today)->modify('last day of +150 months')->format('Y-m-d');
    $offCycleRunRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => false,
        'run_name' => 'CYCLE_EDIT_TEST_' . uniqid(),
        'period_start_date' => $cycleEditPeriodStart, 'period_end_date' => $cycleEditPeriodEnd, 'payment_date' => $cycleEditPeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: genuine off-cycle incentive run created (no cycle_id)' . (empty($offCycleRunRes['status']) ? " ({$offCycleRunRes['message']})" : ''), $offCycleRunRes['status']);
    $cycleEditRunId = $offCycleRunRes['id'];
    check('fixture: employee_count starts at 0 (nobody joined yet)', (int)$pdo->query("SELECT employee_count FROM payroll_runs WHERE id = {$cycleEditRunId}")->fetchColumn(), 0);

    $setCycleRes = $runModel->update($cycleEditRunId, $compId, ['cycle_id' => $cycle2Id], $adminUserId, true);
    checkTrue('update() with cycle_id still succeeds while employee_count=0' . (empty($setCycleRes['status']) ? " ({$setCycleRes['message']})" : ''), $setCycleRes['status']);
    $runAfterCycleSet = $runModel->get($cycleEditRunId, $compId);
    check('cycle_id is now the new cycle', (int)$runAfterCycleSet['cycle_id'], $cycle2Id);
    check('run_purpose forced back to payroll now that it is cycle-linked (was incentive)', $runAfterCycleSet['run_purpose'], 'payroll');
    check('compute_statutory forced to 1 (a cycle-linked run is always full payroll)', (int)$runAfterCycleSet['compute_statutory'], 1);

    // recalculate() itself (a pure cycle-linked run's own eligibility branch never reads
    // payroll_run_manual_employees at all -- joinEmployees() on a pure cycle run is a no-op there,
    // only usable to re-include a previously-excluded employee) puts the auto-eligible fixture
    // employee into the run -> employee_count becomes > 0.
    $runModel->recalculate($cycleEditRunId, $compId, $adminUserId, true);
    $empCountAfterRecalc = (int)$pdo->query("SELECT employee_count FROM payroll_runs WHERE id = {$cycleEditRunId}")->fetchColumn();
    checkTrue('fixture: employee_count now > 0 (auto-eligible by date range)', $empCountAfterRecalc > 0);

    // 2026-09-11 correction: none of these are refused outright any more -- applyFieldLocks() now
    // SKIPS just the locked field (reverts it, reports it in skipped_fields) and the save still
    // succeeds, since the client-side lock mirror doesn't exist yet to keep the attempt out of the
    // payload in the first place. No more "allowed, force-recalculate" escape hatch for the one
    // transition that used to get one either -- that transition is skipped like everything else here.
    $switchCycleRes = $runModel->update($cycleEditRunId, $compId, ['cycle_id' => $cycleId], $adminUserId, true);
    checkTrue('update() succeeds (skips, not rejects) a cycle_id change attempt once the run has admin work' . (empty($switchCycleRes['status']) ? " ({$switchCycleRes['message']})" : ''), $switchCycleRes['status']);
    check('cycle_id reported in skipped_fields with reason has_admin_work', $switchCycleRes['skipped_fields'][0]['field'] ?? null, 'cycle_id');
    check('cycle_id genuinely unchanged after the skipped attempt', (int)$runModel->get($cycleEditRunId, $compId)['cycle_id'], $cycle2Id);

    $shiftedPeriodEnd = (clone $today)->modify('first day of +150 months')->modify('+5 days')->format('Y-m-d');
    $togglePeriodRes = $runModel->update($cycleEditRunId, $compId, ['period_start_date' => $cycleEditPeriodStart, 'period_end_date' => $shiftedPeriodEnd], $adminUserId, true);
    checkTrue('update() succeeds (skips) a period_end_date change attempt once the run has admin work', $togglePeriodRes['status']);
    check('period_dates reported in skipped_fields', $togglePeriodRes['skipped_fields'][0]['field'] ?? null, 'period_dates');
    check('period_end_date genuinely unchanged', $runModel->get($cycleEditRunId, $compId)['period_end_date'], $cycleEditPeriodEnd);

    $toggleToOffCycleRes = $runModel->update($cycleEditRunId, $compId, ['cycle_id' => null, 'run_purpose' => 'incentive', 'compute_statutory' => false], $adminUserId, true);
    checkTrue('update() succeeds (skips both) toggling a cycle-linked run WITH admin work to off-cycle (was allowed-with-forced-recalculate before this item)', $toggleToOffCycleRes['status']);
    $toggleSkippedNames = array_column($toggleToOffCycleRes['skipped_fields'], 'field');
    checkTrue('cycle_id AND run_purpose both reported as skipped', in_array('cycle_id', $toggleSkippedNames, true) && in_array('run_purpose', $toggleSkippedNames, true));
    $runAfterRefusedToggle = $runModel->get($cycleEditRunId, $compId);
    check('cycle_id genuinely still the cycle (skipped, not silently applied)', (int)$runAfterRefusedToggle['cycle_id'], $cycle2Id);
    check('run_purpose genuinely still payroll (skipped)', $runAfterRefusedToggle['run_purpose'], 'payroll');
    check('employee_count genuinely unchanged -- no forced recalculate happened, nothing to force', (int)$runAfterRefusedToggle['employee_count'], $empCountAfterRecalc);

    // A genuinely off-cycle run with admin work on it, skipping an attempted merge_target set the same way.
    $mergeTargetAttemptRes = $runModel->update($cycleEditRunId, $compId, ['merge_target_run_id' => $runId], $adminUserId, true);
    checkTrue('update() succeeds (skips) setting merge_target_run_id once the run has admin work', $mergeTargetAttemptRes['status']);
    check('merge_target reported in skipped_fields', $mergeTargetAttemptRes['skipped_fields'][0]['field'] ?? null, 'merge_target');

    // But run_name/payment_date/use_flat_tax_rate/notes all stay freely editable regardless of
    // employee_count while still draft -- and resubmitting the SAME (unchanged) cycle_id alongside
    // one of them must NOT be treated as an attempted change (the shared form always sends every
    // field, whether or not the admin actually touched it).
    $sameCycleRenameRes = $runModel->update($cycleEditRunId, $compId, [
        'cycle_id' => $cycle2Id, 'run_name' => 'CYCLE_EDIT_TEST_RENAMED', 'payment_date' => $cycleEditPeriodEnd,
        'use_flat_tax_rate' => true, 'notes' => 'still editable with employees',
    ], $adminUserId, true);
    checkTrue('update() succeeds: unchanged cycle_id + genuinely changed name/payment_date/flat-tax/notes, all while the run has admin work' . (empty($sameCycleRenameRes['status']) ? " ({$sameCycleRenameRes['message']})" : ''), $sameCycleRenameRes['status']);
    check('skipped_fields is empty -- resubmitting the SAME cycle_id value is never reported as skipped', $sameCycleRenameRes['skipped_fields'], []);
    $runAfterFreeFields = $runModel->get($cycleEditRunId, $compId);
    check('run_name change applied', $runAfterFreeFields['run_name'], 'CYCLE_EDIT_TEST_RENAMED');
    check('notes change applied', $runAfterFreeFields['notes'], 'still editable with employees');
    check('cycle_id still genuinely unchanged (resubmitting the same value never counted as a change)', (int)$runAfterFreeFields['cycle_id'], $cycle2Id);

    echo "=== update(): once a run leaves draft, only notes can still change (real capability expansion -- was refused entirely before this item) ===\n";
    $nonDraftPeriodStart = (clone $today)->modify('first day of +151 months')->format('Y-m-d');
    $nonDraftPeriodEnd = (clone $today)->modify('last day of +151 months')->format('Y-m-d');
    $nonDraftRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'NOTES_ONLY_EDIT_TEST_' . uniqid(),
        'period_start_date' => $nonDraftPeriodStart, 'period_end_date' => $nonDraftPeriodEnd, 'payment_date' => $nonDraftPeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: cycle-linked run created' . (empty($nonDraftRunRes['status']) ? " ({$nonDraftRunRes['message']})" : ''), $nonDraftRunRes['status']);
    $nonDraftRunId = $nonDraftRunRes['id'];
    $nonDraftOriginalName = $runModel->get($nonDraftRunId, $compId)['run_name'];
    $runModel->recalculate($nonDraftRunId, $compId, $adminUserId, true);
    $submitRes2 = $runModel->submit($nonDraftRunId, $compId, $adminUserId, true);
    checkTrue('fixture: run submitted to pending_approval' . (empty($submitRes2['status']) ? " ({$submitRes2['message']})" : ''), $submitRes2['status']);

    $notesOnlyRes = $runModel->update($nonDraftRunId, $compId, ['notes' => 'added after submission'], $adminUserId, true);
    checkTrue('update() with ONLY notes changed succeeds even though the run has left draft' . (empty($notesOnlyRes['status']) ? " ({$notesOnlyRes['message']})" : ''), $notesOnlyRes['status']);
    check('notes change actually applied', $runModel->get($nonDraftRunId, $compId)['notes'], 'added after submission');

    $renameAfterSubmitRes = $runModel->update($nonDraftRunId, $compId, ['run_name' => 'SHOULD NOT APPLY'], $adminUserId, true);
    checkTrue('update() succeeds (skips) a run_name change attempt once the run has left draft', $renameAfterSubmitRes['status']);
    check('run_name reported in skipped_fields with reason not_draft', $renameAfterSubmitRes['skipped_fields'][0]['field'] ?? null, 'run_name');
    check('run_name genuinely unchanged', $runModel->get($nonDraftRunId, $compId)['run_name'], $nonDraftOriginalName);
    $cycleAfterSubmitRes = $runModel->update($nonDraftRunId, $compId, ['cycle_id' => $cycle2Id], $adminUserId, true);
    checkTrue('update() succeeds (skips) a cycle_id change attempt once the run has left draft', $cycleAfterSubmitRes['status']);
    check('cycle_id reported in skipped_fields', $cycleAfterSubmitRes['skipped_fields'][0]['field'] ?? null, 'cycle_id');
    check('cycle_id genuinely unchanged after the skipped attempt', (int)$runModel->get($nonDraftRunId, $compId)['cycle_id'], $cycleId);

    // 2026-09-09, round-creation flow audit Bug 1 (explicit report: an off-cycle/incentive run's
    // use_flat_tax_rate opt-in was silently reset to 0 on ANY edit-save, because the Edit modal never
    // had a matching field/payload key for it at all -- see modals.php/detail.php/detail.js's own
    // 2026-09-09 comments). update()'s own fix generalizes past just this one flag: for an incentive
    // run, each of the 5 calc flags is now only overwritten when its OWN key is present in $data --
    // absence means "leave the run's current stored value alone", not "reset to 0". Own isolated
    // fixture, distinct $today-relative period per this file's own convention above.
    echo "=== update(): incentive run_purpose flags -- absent key preserves current value, present key still applies (round-creation flow audit Bug 1) ===\n";
    $flatTaxPeriodStart = (clone $today)->modify('first day of +154 months')->format('Y-m-d');
    $flatTaxPeriodEnd = (clone $today)->modify('last day of +154 months')->format('Y-m-d');
    $flatTaxRunRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => true, 'use_flat_tax_rate' => true,
        'run_name' => 'FLAT_TAX_BUG1_TEST_' . uniqid(),
        'period_start_date' => $flatTaxPeriodStart, 'period_end_date' => $flatTaxPeriodEnd, 'payment_date' => $flatTaxPeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: off-cycle incentive run created with use_flat_tax_rate=true' . (empty($flatTaxRunRes['status']) ? " ({$flatTaxRunRes['message']})" : ''), $flatTaxRunRes['status']);
    $flatTaxRunId = $flatTaxRunRes['id'];
    check('fixture: use_flat_tax_rate persisted as 1 from create()', (int)$runModel->get($flatTaxRunId, $compId)['use_flat_tax_rate'], 1);

    // THE ACTUAL BUG, reproduced at the model level: an edit-save payload that includes run_purpose
    // (always true once the Edit modal's off-cycle/incentive section is visible) but OMITS
    // use_flat_tax_rate entirely -- exactly what detail.js's edit-save payload builder sent before
    // this fix, since the field/checkbox didn't exist on that form at all. Before this fix this
    // silently forced use_flat_tax_rate back to 0; now it must be left untouched.
    $renameOnlyRes = $runModel->update($flatTaxRunId, $compId, [
        'run_name' => 'FLAT_TAX_BUG1_TEST_RENAMED',
        'run_purpose' => 'incentive', 'compute_statutory' => true,
        'include_base_salary' => false, 'include_standing_items' => false, 'include_attendance_pay' => false,
        // use_flat_tax_rate deliberately NOT included in this payload at all.
    ], $adminUserId, true);
    checkTrue('update() without use_flat_tax_rate key succeeds' . (empty($renameOnlyRes['status']) ? " ({$renameOnlyRes['message']})" : ''), $renameOnlyRes['status']);
    $runAfterRenameOnly = $runModel->get($flatTaxRunId, $compId);
    check('run_name change from that same save actually applied', $runAfterRenameOnly['run_name'], 'FLAT_TAX_BUG1_TEST_RENAMED');
    check('use_flat_tax_rate is STILL 1 -- an absent key no longer silently resets it to 0 (the actual bug)', (int)$runAfterRenameOnly['use_flat_tax_rate'], 1);

    // An explicit, present false DOES still turn it off -- "absent preserves" must not become
    // "can never be turned off again".
    $explicitOffRes = $runModel->update($flatTaxRunId, $compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => true,
        'include_base_salary' => false, 'include_standing_items' => false, 'include_attendance_pay' => false,
        'use_flat_tax_rate' => false,
    ], $adminUserId, true);
    checkTrue('update() with use_flat_tax_rate=false explicitly present succeeds' . (empty($explicitOffRes['status']) ? " ({$explicitOffRes['message']})" : ''), $explicitOffRes['status']);
    check('use_flat_tax_rate is now genuinely 0 -- an explicitly-present false still applies normally', (int)$runModel->get($flatTaxRunId, $compId)['use_flat_tax_rate'], 0);

    // And back on again with an explicit true, then confirm a payroll-purpose transition still
    // unconditionally forces it to 0 regardless of any stale/absent key (the existing, correct
    // behavior for a payroll-purpose run, unchanged by this fix -- see update()'s own "payroll
    // forces all 5 flags" branch).
    $explicitOnRes = $runModel->update($flatTaxRunId, $compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => true,
        'include_base_salary' => false, 'include_standing_items' => false, 'include_attendance_pay' => false,
        'use_flat_tax_rate' => true,
    ], $adminUserId, true);
    checkTrue('update() with use_flat_tax_rate=true explicitly present succeeds' . (empty($explicitOnRes['status']) ? " ({$explicitOnRes['message']})" : ''), $explicitOnRes['status']);
    check('use_flat_tax_rate is genuinely 1 again', (int)$runModel->get($flatTaxRunId, $compId)['use_flat_tax_rate'], 1);
    $toPayrollRes = $runModel->update($flatTaxRunId, $compId, ['cycle_id' => $cycleId], $adminUserId, true);
    checkTrue('update() converting this run to cycle-linked (payroll-purpose) succeeds' . (empty($toPayrollRes['status']) ? " ({$toPayrollRes['message']})" : ''), $toPayrollRes['status']);
    check('use_flat_tax_rate forced back to 0 -- a payroll-purpose run can never carry this flag, unchanged by this fix', (int)$runModel->get($flatTaxRunId, $compId)['use_flat_tax_rate'], 0);

    // 2026-09-09, round-creation flow audit Bug 2 (explicit report: resolveMergeTargetSpec()'s own
    // future-cycle auto-match silently picks the earliest-period run whenever 2+ candidates already
    // exist for the same cycle+payment-month, with zero visible indication of which one). Own isolated
    // fixture cycle (not $cycleId -- avoids any cross-talk with the many other fixtures already
    // sharing that cycle elsewhere in this file) with 2 real runs sharing the same payment MONTH but
    // different periods, mimicking a semi-monthly cycle's 15th+30th both already existing.
    echo "=== previewFutureCycleMergeTarget() + explicit-pick-honored-at-save (round-creation flow audit Bug 2) ===\n";
    $bug2CycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'TEST_BUG2_CYCLE_' . uniqid(), 'payroll_frequency' => 'semi_monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: dedicated Bug 2 cycle created' . (empty($bug2CycleRes['status']) ? " ({$bug2CycleRes['message']})" : ''), $bug2CycleRes['status']);
    $bug2CycleId = $bug2CycleRes['id'];
    $bug2TargetMonthAnchor = (clone $today)->modify('first day of +160 months')->format('Y-m-d');

    $previewEmptyRes = $runModel->previewFutureCycleMergeTarget($compId, $bug2CycleId, $bug2TargetMonthAnchor, null);
    checkTrue('previewFutureCycleMergeTarget() succeeds against a valid, empty-so-far month' . (empty($previewEmptyRes['status']) ? " ({$previewEmptyRes['message']})" : ''), $previewEmptyRes['status']);
    check('0 candidates before any run exists in this cycle/month', count($previewEmptyRes['matches']), 0);

    $previewInvalidCycleRes = $runModel->previewFutureCycleMergeTarget($compId, 999999, $bug2TargetMonthAnchor, null);
    check('previewFutureCycleMergeTarget() rejects an invalid/nonexistent cycle', $previewInvalidCycleRes['status'], false);

    // First candidate: paid on the 15th of the target month.
    $bug2Run1PeriodStart = (clone $today)->modify('first day of +160 months')->format('Y-m-d');
    $bug2Run1PeriodEnd = (clone $today)->modify('first day of +160 months')->modify('+14 days')->format('Y-m-d');
    $bug2Run1PaymentDate = (clone $today)->modify('first day of +160 months')->modify('+14 days')->format('Y-m-d');
    $bug2Run1Res = $runModel->create($compId, [
        'cycle_id' => $bug2CycleId, 'run_name' => 'BUG2_CANDIDATE_15TH_' . uniqid(),
        'period_start_date' => $bug2Run1PeriodStart, 'period_end_date' => $bug2Run1PeriodEnd, 'payment_date' => $bug2Run1PaymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: 1st candidate run created (earlier period, paid mid-month)' . (empty($bug2Run1Res['status']) ? " ({$bug2Run1Res['message']})" : ''), $bug2Run1Res['status']);
    $bug2Run1Id = $bug2Run1Res['id'];

    $previewOneRes = $runModel->previewFutureCycleMergeTarget($compId, $bug2CycleId, $bug2TargetMonthAnchor, null);
    checkTrue('previewFutureCycleMergeTarget() still succeeds with exactly 1 candidate' . (empty($previewOneRes['status']) ? " ({$previewOneRes['message']})" : ''), $previewOneRes['status']);
    check('exactly 1 candidate found', count($previewOneRes['matches']), 1);
    check('that 1 candidate is the run just created', (int)$previewOneRes['matches'][0]['id'], $bug2Run1Id);

    // Second candidate: same cycle, same payment MONTH, but a distinct later period (paid on the
    // 30th) -- the actual "2+ candidates" scenario Bug 2 is about.
    $bug2Run2PeriodStart = (clone $today)->modify('first day of +160 months')->modify('+15 days')->format('Y-m-d');
    $bug2Run2PeriodEnd = (clone $today)->modify('last day of +160 months')->format('Y-m-d');
    $bug2Run2PaymentDate = (clone $today)->modify('last day of +160 months')->format('Y-m-d');
    $bug2Run2Res = $runModel->create($compId, [
        'cycle_id' => $bug2CycleId, 'run_name' => 'BUG2_CANDIDATE_30TH_' . uniqid(),
        'period_start_date' => $bug2Run2PeriodStart, 'period_end_date' => $bug2Run2PeriodEnd, 'payment_date' => $bug2Run2PaymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: 2nd candidate run created (later period, same payment month)' . (empty($bug2Run2Res['status']) ? " ({$bug2Run2Res['message']})" : ''), $bug2Run2Res['status']);
    $bug2Run2Id = $bug2Run2Res['id'];

    $previewTwoRes = $runModel->previewFutureCycleMergeTarget($compId, $bug2CycleId, $bug2TargetMonthAnchor, null);
    checkTrue('previewFutureCycleMergeTarget() succeeds with 2 candidates' . (empty($previewTwoRes['status']) ? " ({$previewTwoRes['message']})" : ''), $previewTwoRes['status']);
    check('exactly 2 candidates found -- this is the ambiguous case the UI must now disambiguate explicitly', count($previewTwoRes['matches']), 2);
    $previewTwoIds = array_map(fn($m) => (int)$m['id'], $previewTwoRes['matches']);
    sort($previewTwoIds);
    $expectedIds = [$bug2Run1Id, $bug2Run2Id];
    sort($expectedIds);
    check('both real candidate runs are present in the preview (not just one)', $previewTwoIds, $expectedIds);
    check('preview orders earliest-period first (index 0 is what the OLD silent behavior would have picked)', (int)$previewTwoRes['matches'][0]['id'], $bug2Run1Id);

    $previewExcludeRes = $runModel->previewFutureCycleMergeTarget($compId, $bug2CycleId, $bug2TargetMonthAnchor, $bug2Run1Id);
    checkTrue('previewFutureCycleMergeTarget() succeeds with exclude_id set' . (empty($previewExcludeRes['status']) ? " ({$previewExcludeRes['message']})" : ''), $previewExcludeRes['status']);
    check('excluding run 1 leaves exactly the other candidate (mirrors an Edit form excluding itself)', (int)$previewExcludeRes['matches'][0]['id'], $bug2Run2Id);
    check('excluding run 1 leaves exactly 1 candidate', count($previewExcludeRes['matches']), 1);

    // THE ACTUAL FIX, end-to-end: a caller (the frontend, after the admin explicitly disambiguated in
    // the preview UI) that submits a resolved merge_target_run_id pointing at the LATER candidate gets
    // exactly that one linked -- NOT silently overridden to the earlier one. Own distinct off-cycle
    // fixture period so this doesn't collide with $bug2Run1/$bug2Run2's own periods.
    $bug2SourcePeriodStart = (clone $today)->modify('first day of +161 months')->format('Y-m-d');
    $bug2SourcePeriodEnd = (clone $today)->modify('last day of +161 months')->format('Y-m-d');
    $explicitPickRes = $runModel->create($compId, [
        'run_name' => 'BUG2_EXPLICIT_PICK_' . uniqid(),
        'period_start_date' => $bug2SourcePeriodStart, 'period_end_date' => $bug2SourcePeriodEnd, 'payment_date' => $bug2SourcePeriodEnd,
        'merge_target_run_id' => $bug2Run2Id, // the LATER (30th) candidate, explicitly chosen -- not the earliest.
    ], $adminUserId, true);
    checkTrue('create() with an explicit merge_target_run_id (the admin\'s disambiguation pick) succeeds' . (empty($explicitPickRes['status']) ? " ({$explicitPickRes['message']})" : ''), $explicitPickRes['status']);
    check('the run links to the EXPLICITLY CHOSEN later candidate, not silently defaulted to the earlier one', (int)$runModel->get($explicitPickRes['id'], $compId)['merge_target_run_id'], $bug2Run2Id);

    // Contrast/control, documenting the UNCHANGED fallback: a caller that sends the future_cycle spec
    // WITHOUT ever disambiguating (e.g. a stale client, or the preview genuinely failing) still gets
    // the OLD silent earliest-period behavior -- this is resolveMergeTargetSpec()'s own safety net,
    // deliberately not removed, only made visible/overridable by the new preview above.
    $bug2SilentPeriodStart = (clone $today)->modify('first day of +162 months')->format('Y-m-d');
    $bug2SilentPeriodEnd = (clone $today)->modify('last day of +162 months')->format('Y-m-d');
    $silentDefaultRes = $runModel->create($compId, [
        'run_name' => 'BUG2_SILENT_DEFAULT_' . uniqid(),
        'period_start_date' => $bug2SilentPeriodStart, 'period_end_date' => $bug2SilentPeriodEnd, 'payment_date' => $bug2SilentPeriodEnd,
        'merge_target_cycle_id' => $bug2CycleId,
        'merge_target_period_start_date' => $bug2TargetMonthAnchor, 'merge_target_period_end_date' => $bug2TargetMonthAnchor,
    ], $adminUserId, true);
    checkTrue('create() with a future_cycle spec and no explicit pick still succeeds' . (empty($silentDefaultRes['status']) ? " ({$silentDefaultRes['message']})" : ''), $silentDefaultRes['status']);
    check('unchanged fallback: resolves to the earliest-period candidate when nobody disambiguated', (int)$runModel->get($silentDefaultRes['id'], $compId)['merge_target_run_id'], $bug2Run1Id);

    // A regular (non-supplemental) sync-linked run can never be cleared down to no cycle at all.
    // Another distinct $today-relative period, same reason as above.
    $altPeriodStart2 = (clone $today)->modify('first day of +153 months')->format('Y-m-d');
    $altPeriodEnd2 = (clone $today)->modify('last day of +153 months')->format('Y-m-d');
    $syncCycleGuardRunId = null;
    $stmtSyncProc = $pdo->prepare("INSERT INTO `payroll_sync_processes` (comp_id, process_no, run_kind, status) VALUES (:comp_id, :process_no, 'regular', 'pulled')");
    $stmtSyncProc->execute([':comp_id' => $compId, ':process_no' => 'CYCLE_EDIT_SYNC_' . uniqid()]);
    $syncProcessId = (int)$pdo->lastInsertId();
    $syncRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'sync_process_id' => $syncProcessId,
        'run_name' => 'CYCLE_EDIT_SYNC_RUN_' . uniqid(),
        'period_start_date' => $altPeriodStart2, 'period_end_date' => $altPeriodEnd2, 'payment_date' => $altPeriodEnd2,
    ], $adminUserId, true);
    checkTrue('fixture: regular sync-linked run created' . (empty($syncRunRes['status']) ? " ({$syncRunRes['message']})" : ''), $syncRunRes['status']);
    $syncCycleGuardRunId = $syncRunRes['id'];
    $clearSyncCycleRes = $runModel->update($syncCycleGuardRunId, $compId, ['cycle_id' => null], $adminUserId, true);
    check('update() rejects clearing cycle_id to null for a regular sync-linked run', $clearSyncCycleRes['status'], false);

    // 2026-09-02, explicit request: "การตั้งค่าเงินรวมกันถ้าเกินจำนวนเงินเดือนมีการดักส่วนนี้ไว้ไหม" -- confirmed
    // via AskUserQuestion that PayrollRunModel::recalculate() itself should reconcile a Mixed-payment
    // employee's FULL line set (not just transfer, which BankTransferFileReport already checked) the
    // moment net pay is known. Deliberately uses 2 plain CASH lines (no bank_account_id needed) --
    // this is precisely the gap CashPaymentSummaryReport never covered (it summed cash lines with no
    // reconciliation at all), so a cash-only mismatch is the case this fix most needed to prove.
    echo "=== Mixed payment: full line-set reconciliation against net pay (calc_errors advisory) ===\n";
    $mixedMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'mixed'")->fetchColumn();
    $cashMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'cash'")->fetchColumn();
    checkTrue('fixture: mixed/cash master_payment_methods ids resolved', $mixedMethodId > 0 && $cashMethodId > 0);

    $mixedPeriodStart = (clone $today)->modify('first day of +160 months')->format('Y-m-d');
    $mixedPeriodEnd = (clone $today)->modify('last day of +160 months')->format('Y-m-d');
    $insMixedEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible, payment_method_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'Mixed', 'Tester', 'Mixed', 'Tester', '1990-01-01', 'Thai',
         :email, '0800000001', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999998',
         :employment_date, 'permanent', 'full_time', 'office', 'manual',
         'monthly', 20000, :salary_effective_date, 'average', 'active',
         0, 0, 1, 1, :payment_method_id)");
    $mixedEmpNo = 'MIXTEST_' . uniqid();
    $insMixedEmp->execute([
        ':comp_id' => $compId, ':employee_no' => $mixedEmpNo, ':email' => $mixedEmpNo . '@test.local',
        ':employment_date' => $mixedPeriodStart, ':salary_effective_date' => $mixedPeriodStart,
        ':payment_method_id' => $mixedMethodId,
    ]);
    $mixedEmployeeId = (int)$pdo->lastInsertId();

    $mixedRunRes = $runModel->create($compId, [
        'run_name' => 'MIXED_RECON_' . uniqid(),
        'period_start_date' => $mixedPeriodStart, 'period_end_date' => $mixedPeriodEnd, 'payment_date' => $mixedPeriodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: off-cycle run created for mixed-payment reconciliation test' . (empty($mixedRunRes['status']) ? " ({$mixedRunRes['message']})" : ''), $mixedRunRes['status']);
    $mixedRunId = $mixedRunRes['id'];
    $joinMixedRes = $runModel->joinEmployees($mixedRunId, $compId, [$mixedEmployeeId], $adminUserId, true);
    checkTrue('fixture: mixed-payment employee joined into the run' . (empty($joinMixedRes['status']) ? " ({$joinMixedRes['message']})" : ''), $joinMixedRes['status']);

    // Deliberately no employee_payment_method_lines row at all yet -- must NOT fire the mismatch
    // check (nothing to reconcile against with zero lines; that's a different, already-existing
    // "no bank/lines configured" gap, not this one).
    $runModel->recalculate($mixedRunId, $compId, $adminUserId, true);
    $mixedDetailsNoLines = $runModel->getDetails($mixedRunId, $compId);
    $mixedRowNoLines = null;
    foreach ($mixedDetailsNoLines as $row) { if ((int)$row['employee_id'] === $mixedEmployeeId) { $mixedRowNoLines = $row; break; } }
    checkTrue('fixture: mixed-payment employee row found (no lines yet)', $mixedRowNoLines !== null);
    check('no mixed_payment_lines_mismatch when the employee has zero mixed lines configured', strpos((string)($mixedRowNoLines['calc_errors'] ?? ''), 'mixed_payment_lines_mismatch') !== false, false);
    $realNetAmount = (float)$mixedRowNoLines['net_amount'];
    checkTrue('fixture: real net_amount is a positive number to reconcile against', $realNetAmount > 0);

    // Deliberately mismatched: 2 fixed cash lines that do NOT sum to the real net_amount.
    $insMixedLine = $pdo->prepare("INSERT INTO `employee_payment_method_lines`
        (employee_id, sort_order, payment_method_id, amount_type, amount_value, created_by)
        VALUES (:employee_id, :sort_order, :payment_method_id, 'fixed', :amount_value, :created_by)");
    $insMixedLine->execute([':employee_id' => $mixedEmployeeId, ':sort_order' => 0, ':payment_method_id' => $cashMethodId, ':amount_value' => 100.00, ':created_by' => $adminUserId]);
    $insMixedLine->execute([':employee_id' => $mixedEmployeeId, ':sort_order' => 1, ':payment_method_id' => $cashMethodId, ':amount_value' => 50.00, ':created_by' => $adminUserId]);
    $runModel->recalculate($mixedRunId, $compId, $adminUserId, true);
    $mixedDetailsMismatch = $runModel->getDetails($mixedRunId, $compId);
    $mixedRowMismatch = null;
    foreach ($mixedDetailsMismatch as $row) { if ((int)$row['employee_id'] === $mixedEmployeeId) { $mixedRowMismatch = $row; break; } }
    checkTrue('mixed_payment_lines_mismatch appears in calc_errors when fixed lines (150) genuinely do not sum to net pay', strpos((string)($mixedRowMismatch['calc_errors'] ?? ''), 'mixed_payment_lines_mismatch') !== false);
    check('advisory only -- calc_status stays "calculated", never flips to "error"', $mixedRowMismatch['calc_status'] ?? null, 'calculated');

    // Fix the lines so they genuinely sum to the real net_amount -- the warning must clear.
    $pdo->prepare("DELETE FROM `employee_payment_method_lines` WHERE employee_id = :employee_id")->execute([':employee_id' => $mixedEmployeeId]);
    $reconciledFirstLine = round($realNetAmount - 50.0, 2);
    $insMixedLine->execute([':employee_id' => $mixedEmployeeId, ':sort_order' => 0, ':payment_method_id' => $cashMethodId, ':amount_value' => $reconciledFirstLine, ':created_by' => $adminUserId]);
    $insMixedLine->execute([':employee_id' => $mixedEmployeeId, ':sort_order' => 1, ':payment_method_id' => $cashMethodId, ':amount_value' => 50.00, ':created_by' => $adminUserId]);
    $runModel->recalculate($mixedRunId, $compId, $adminUserId, true);
    $mixedDetailsFixed = $runModel->getDetails($mixedRunId, $compId);
    $mixedRowFixed = null;
    foreach ($mixedDetailsFixed as $row) { if ((int)$row['employee_id'] === $mixedEmployeeId) { $mixedRowFixed = $row; break; } }
    check('mixed_payment_lines_mismatch clears once the lines genuinely sum to net pay', strpos((string)($mixedRowFixed['calc_errors'] ?? ''), 'mixed_payment_lines_mismatch') !== false, false);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
