<?php
/**
 * Lightweight end-to-end verification for Payroll Policy "pay_basis" (2026-08-30, explicit request:
 * "จ่ายตามวันที่มาทำงาน หักวันหยุด หักวันลาไหม หรือจ่ายเต็มเดือน" -- confirmed via AskUserQuestion:
 * schedule-based, same spirit as salary_type='daily' proration, NOT re-derived from Origami sync
 * attendance data, specifically to avoid double-deducting against Attendance Deduction Rule's own
 * sync-derived absence formulas).
 *
 * 2026-08-30, SAME-DAY follow-up, explicit correction: "พื้นฐานการจ่ายเงินเดือน ที่ตั้งจะนำไปคำนวณแค่
 * พนักงานที่ทดลองงานใช่ไหม ถ้าไม่ใช่ช่วยปรับให้เป็นเงื่อนไขของการทดลองงานเท่านั้น" -- pay_basis was NOT
 * probation-gated when first shipped (applied to every monthly employee company-wide); this file now
 * tests the CORRECTED, probation-gated behavior -- a genuine scope-narrowing, not the original design.
 * See PayrollPolicyModel::payBasisSettings()'s own docblock and PayrollRunModel::recalculate()'s own
 * wiring comments at the base-salary block. Not PHPUnit -- see tests/statutory_engine_test.php for
 * why. Runs against the real dev DB inside a transaction that is always rolled back, so it never
 * leaves any data behind.
 * Run with: php tests/pay_basis_policy_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';
require_once __DIR__ . '/../app/models/SetupRulesModel.php';
require_once __DIR__ . '/../app/models/PayrollSyncModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.01 : $actual === $expected;
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
    $compId = 1;
    $adminUserId = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();

    // Same isolation precedent as tests/probation_pay_policy_test.php's own top-of-file comment --
    // comp_id=1 is the real, shared dev DB. This file builds its own complete fixture set and never
    // depends on any pre-existing employee, so leftover rows are cleared for the duration of this
    // transaction only (rolled back at the end).
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL AND id != :keep")
        ->execute([':comp_id' => $compId, ':keep' => $adminUserId]);

    $policyModel = new PayrollPolicyModel($pdo);
    $setupRulesModel = new SetupRulesModel($pdo);
    $runModel = new PayrollRunModel($pdo);
    $cycleModel = new PayrollCycleModel();

    // Fixed calendar month (August 2026) -- deliberately NOT "this month" dynamically, since the
    // fixture below hand-picks specific holiday/leave dates and their exact day-counts must be
    // hand-verifiable against a fixed calendar regardless of when this test is actually run.
    $periodStart = '2026-08-01';
    $periodEnd = '2026-08-31';
    $paymentDate = $periodEnd;

    // Mon-Fri shift (Sat/Sun off) -- August 2026 has exactly 21 weekdays (confirmed via direct
    // SetupRulesModel::scheduledPayableDaysForEmployee() call before writing this fixture).
    $pdo->prepare("INSERT INTO `shifts` (comp_id, shift_code, shift_name_th, shift_name_en, start_time, end_time,
            works_monday, works_tuesday, works_wednesday, works_thursday, works_friday, works_saturday, works_sunday, status, created_by)
        VALUES (?, 'TESTPBSHIFT', 'กะทดสอบ', 'Test Shift', '09:00:00', '18:00:00', 1,1,1,1,1,0,0, 'active', ?)")
        ->execute([$compId, $adminUserId]);
    $shiftId = (int)$pdo->lastInsertId();

    // employment_status is now a bound param (not hardcoded 'permanent') -- the probation-gating
    // correction needs BOTH a probation and a non-probation monthly employee, same shift/holiday/
    // leave fixture, to prove the setting affects one and NOT the other.
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         shift_id, sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1995-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', NULL, :employment_status, 'full_time', 'office', 'manual',
         :salary_type, :base_salary, '2020-01-01', 'average', 'active',
         :shift_id, 1, 1, 0)");

    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_PAYBASIS_PROB_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ทดลองงาน', ':name_en' => 'Test', ':surname_en' => 'ProbationPayBasis',
        ':email' => uniqid() . '@test.local', ':employment_status' => 'probation',
        ':salary_type' => 'monthly', ':base_salary' => 30000, ':shift_id' => $shiftId,
    ]);
    $probationEmpId = (int)$pdo->lastInsertId();

    // Same salary/shift, but PERMANENT status -- pay_basis must have ZERO effect on this employee
    // (the whole point of this round's correction).
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_PAYBASIS_PERM_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'ประจำ', ':name_en' => 'Test', ':surname_en' => 'PermanentPayBasis',
        ':email' => uniqid() . '@test.local', ':employment_status' => 'permanent',
        ':salary_type' => 'monthly', ':base_salary' => 30000, ':shift_id' => $shiftId,
    ]);
    $permanentEmpId = (int)$pdo->lastInsertId();

    // A daily-rate employee, same shift -- pay_basis must have ZERO effect on this employee (that
    // salary_type already has its own, separate schedule-based proration path) regardless of
    // employment_status.
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'TEST_PAYBASIS_DAILY_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายวัน', ':name_en' => 'Test', ':surname_en' => 'DailyRate',
        ':email' => uniqid() . '@test.local', ':employment_status' => 'probation',
        ':salary_type' => 'daily', ':base_salary' => 1000, ':shift_id' => $shiftId,
    ]);
    $dailyEmpId = (int)$pdo->lastInsertId();

    // A holiday landing on a weekday (Wed, Aug 12 2026) -- company-wide.
    $pdo->prepare("INSERT INTO `holidays` (comp_id, name_th, name_en, holiday_date, country_code, assignment_mode, status, created_by)
        VALUES (?, 'ทดสอบ', 'Test Holiday', '2026-08-12', 'TH', 'exclude', 'active', ?)")->execute([$compId, $adminUserId]);

    // An UNPAID leave type + 2 approved days (Aug 17-18, both weekdays) for BOTH monthly employees
    // (probation and permanent) -- same underlying leave data, so any difference in the resulting
    // base_salary_amount can only come from the employment_status gate, not from different inputs.
    $catId = (int)$pdo->query("SELECT id FROM master_leave_categories LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO `leave_types` (comp_id, category_id, code, name_th, name_en, is_paid, status, created_by)
        VALUES (?, ?, 'TESTPBUNPAID', 'ลาไม่รับเงิน ทดสอบ', 'Test Unpaid', 0, 'active', ?)")->execute([$compId, $catId, $adminUserId]);
    $unpaidLeaveTypeId = (int)$pdo->lastInsertId();
    $insUnpaidLeave = $pdo->prepare("INSERT INTO `leave_requests` (comp_id, employee_id, leave_type_id, start_date, end_date, total_days, status, created_by)
        VALUES (?, ?, ?, '2026-08-17', '2026-08-18', 2, 'approved', ?)");
    $insUnpaidLeave->execute([$compId, $probationEmpId, $unpaidLeaveTypeId, $adminUserId]);
    $insUnpaidLeave->execute([$compId, $permanentEmpId, $unpaidLeaveTypeId, $adminUserId]);

    // A PAID leave type + 1 approved day (Aug 20) -- must NEVER reduce pay regardless of the
    // deduct_leave toggle (only UNPAID leave types should). Probation employee only (already
    // sufficiently covered by the unpaid-leave pair above for the permanent employee).
    $pdo->prepare("INSERT INTO `leave_types` (comp_id, category_id, code, name_th, name_en, is_paid, status, created_by)
        VALUES (?, ?, 'TESTPBPAID', 'ลาพักร้อน ทดสอบ', 'Test Paid', 1, 'active', ?)")->execute([$compId, $catId, $adminUserId]);
    $paidLeaveTypeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `leave_requests` (comp_id, employee_id, leave_type_id, start_date, end_date, total_days, status, created_by)
        VALUES (?, ?, ?, '2026-08-20', '2026-08-20', 1, 'approved', ?)")->execute([$compId, $probationEmpId, $paidLeaveTypeId, $adminUserId]);

    echo "=== SetupRulesModel::scheduledPayableDaysForEmployee() -- direct unit coverage (schedule/config only, no employment_status concept at this layer) ===\n";
    $sched0 = $setupRulesModel->scheduledPayableDaysForEmployee($probationEmpId, $compId, $periodStart, $periodEnd, false, false);
    check('21 scheduled weekdays in August 2026 (Mon-Fri shift)', $sched0['total_scheduled_days'], 21);
    check('deduct_holidays=false, deduct_leave=false: payable_days == total_scheduled_days (100%)', $sched0['payable_days'], 21.0);

    $sched1 = $setupRulesModel->scheduledPayableDaysForEmployee($probationEmpId, $compId, $periodStart, $periodEnd, true, false);
    check('1 holiday correctly detected on a scheduled weekday (Aug 12)', $sched1['holiday_on_scheduled_days'], 1);
    check('deduct_holidays=true: payable_days = 21 - 1 = 20', $sched1['payable_days'], 20.0);

    $sched2 = $setupRulesModel->scheduledPayableDaysForEmployee($probationEmpId, $compId, $periodStart, $periodEnd, true, true);
    check('deduct_holidays=true, deduct_leave=true: payable_days = 21 - 1 holiday - 2 unpaid leave = 18 (1 PAID leave day NOT subtracted)', $sched2['payable_days'], 18.0);

    $sched3 = $setupRulesModel->scheduledPayableDaysForEmployee($probationEmpId, $compId, $periodStart, $periodEnd, false, true);
    check('deduct_holidays=false, deduct_leave=true: payable_days = 21 - 2 unpaid leave = 19', $sched3['payable_days'], 19.0);

    echo "=== End-to-end via PayrollRunModel::recalculate() ===\n";
    $createRun = function () use ($runModel, $cycleModel, $compId, $periodStart, $periodEnd, $paymentDate, $adminUserId) {
        $freshCycleRes = $cycleModel->save($compId, [
            'cycle_name' => 'PAYBASIS_TEST_CYCLE_' . uniqid(),
            'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
            'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
        ], $adminUserId);
        $res = $runModel->create($compId, [
            'cycle_id' => $freshCycleRes['id'], 'run_name' => 'PAYBASIS_TEST_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
        ], $adminUserId, true);
        if (empty($res['status'])) {
            throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
        }
        $runModel->recalculate($res['id'], $compId, $adminUserId, true);
        return $runModel->getDetails($res['id'], $compId);
    };
    $rowFor = function (array $details, int $employeeId) {
        return current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeId));
    };

    echo "--- pay_basis='full_month' (default): ZERO behavior change for anyone ---\n";
    $policyModel->save($compId, ['pay_basis' => 'full_month', 'pay_basis_deduct_holidays' => false, 'pay_basis_deduct_leave' => false], $adminUserId);
    $baselineDetails = $createRun();
    check('full_month: probation employee gets full base salary (30000)', (float)$rowFor($baselineDetails, $probationEmpId)['base_salary_amount'], 30000.0);
    check('full_month: permanent employee gets full base salary (30000)', (float)$rowFor($baselineDetails, $permanentEmpId)['base_salary_amount'], 30000.0);

    echo "--- pay_basis='schedule_based', both sub-toggles OFF: still 100% for anyone (holiday/leave exist but not deducted) ---\n";
    $policyModel->save($compId, ['pay_basis' => 'schedule_based', 'pay_basis_deduct_holidays' => false, 'pay_basis_deduct_leave' => false], $adminUserId);
    $offDetails = $createRun();
    check('schedule_based, both toggles off: probation employee still full 30000 (21/21)', (float)$rowFor($offDetails, $probationEmpId)['base_salary_amount'], 30000.0);
    check('schedule_based, both toggles off: permanent employee still full 30000', (float)$rowFor($offDetails, $permanentEmpId)['base_salary_amount'], 30000.0);

    echo "--- pay_basis='schedule_based', deduct_holidays=true AND deduct_leave=true -- 2026-08-30 correction: PROBATION-GATED ---\n";
    $policyModel->save($compId, ['pay_basis' => 'schedule_based', 'pay_basis_deduct_holidays' => true, 'pay_basis_deduct_leave' => true], $adminUserId);
    $bothDetails = $createRun();
    $probationRow = $rowFor($bothDetails, $probationEmpId);
    $permanentRow = $rowFor($bothDetails, $permanentEmpId);
    $dailyRow = $rowFor($bothDetails, $dailyEmpId);
    check('PROBATION employee IS affected: 30000 * 18/21 = 25714.29 (1 holiday + 2 unpaid leave deducted, 1 paid leave NOT)', (float)$probationRow['base_salary_amount'], 25714.29);
    check('PERMANENT employee is NOT affected (2026-08-30 correction: pay_basis is now probation-only) -- still full 30000 despite the exact same holiday/unpaid-leave fixture', (float)$permanentRow['base_salary_amount'], 30000.0);
    // Daily-rate employees already unconditionally exclude weekly-off + holidays via
    // payableDaysForEmployee() (pre-existing behavior, unrelated to this pay_basis feature) --
    // 21 scheduled weekdays - 1 holiday (Aug 12) = 20 payable days regardless of any pay_basis
    // toggle or employment_status, proving this feature has ZERO effect on salary_type='daily'.
    check('daily-rate employee is COMPLETELY unaffected by pay_basis (own separate proration path, always active): 1000 * 20 = 20000', (float)$dailyRow['base_salary_amount'], 20000.0);

    echo "=== pay_basis='sync_actual_days' (2026-08-30, reconciles Origami's own synced PROBATION_WORKING_DAYS) ===\n";
    $syncModel = new PayrollSyncModel($pdo);
    $probationEmpNo = (string)$pdo->query("SELECT employee_no FROM employees WHERE id = {$probationEmpId}")->fetchColumn();
    $permanentEmpNo = (string)$pdo->query("SELECT employee_no FROM employees WHERE id = {$permanentEmpId}")->fetchColumn();
    $policyModel->save($compId, ['pay_basis' => 'sync_actual_days', 'pay_basis_deduct_holidays' => false, 'pay_basis_deduct_leave' => false], $adminUserId);

    $createSyncRun = function (array $items) use ($runModel, $cycleModel, $syncModel, $compId, $periodStart, $periodEnd, $paymentDate, $adminUserId) {
        $syncProcessId = random_int(100000, 999999);
        $ingestRes = $syncModel->ingest([
            'schema_version' => 1, 'process_id' => $syncProcessId, 'process_no' => 'ORIGAMI-PAYBASIS-SYNC-' . $syncProcessId,
            'report_id' => 7, 'comp_id' => 999, 'comp_code' => 'TDI', 'comp_name' => 'TDI (Origami name)',
            'period_id' => 5, 'period_name' => 'Monthly', 'frequency_type' => 'monthly',
            'items' => $items, 'employee_status' => [],
        ]);
        if (empty($ingestRes['status'])) {
            throw new RuntimeException('Cannot continue without a successful ingest: ' . ($ingestRes['message'] ?? ''));
        }
        $processRowId = $ingestRes['process_row_id'];
        $freshCycleRes = $cycleModel->save($compId, [
            'cycle_name' => 'PAYBASIS_SYNC_TEST_CYCLE_' . uniqid(),
            'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
            'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
        ], $adminUserId);
        $res = $runModel->create($compId, [
            'cycle_id' => $freshCycleRes['id'], 'sync_process_id' => $processRowId,
            'run_name' => 'PAYBASIS_SYNC_TEST_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
        ], $adminUserId, true);
        if (empty($res['status'])) {
            throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
        }
        $runModel->recalculate($res['id'], $compId, $adminUserId, true);
        return $runModel->getDetails($res['id'], $compId);
    };

    echo "--- data present: PROBATION_WORKING_DAYS=15, working_days=20 -> 30000 * 15/20 = 22500.00 ---\n";
    $syncDataDetails = $createSyncRun([
        [
            'report_item_id' => 1, 'payroll_code' => $probationEmpNo, 'working_days' => 20,
            'item_values' => [
                ['item_id' => 21, 'item_code' => 'PROBATION_WORKING_DAYS', 'item_name' => 'Probation Pay', 'item_type' => 'INFO', 'unit_type' => 'days', 'value' => 15, 'remark' => null],
            ],
        ],
        // Permanent employee mapped into the SAME sync process, but must be entirely unaffected --
        // the employment_status==='probation' gate is shared with the schedule_based branch, proven
        // there already; included here mainly so this employee has a mapped row to pull at all.
        ['report_item_id' => 2, 'payroll_code' => $permanentEmpNo, 'working_days' => 20, 'item_values' => []],
    ]);
    $syncProbationRow = $rowFor($syncDataDetails, $probationEmpId);
    $syncPermanentRow = $rowFor($syncDataDetails, $permanentEmpId);
    check('sync_actual_days, data present: probation employee = 30000 * 15/20 = 22500.00', (float)$syncProbationRow['base_salary_amount'], 22500.00);
    checkTrue('sync_actual_days, data present: no sync_actual_days_no_data error for the probation employee', strpos((string)($syncProbationRow['calc_errors'] ?? ''), 'sync_actual_days_no_data') === false);
    check('sync_actual_days: PERMANENT employee unaffected (same probation-only gate as schedule_based) -- still full 30000', (float)$syncPermanentRow['base_salary_amount'], 30000.0);

    echo "--- data absent: sync-based run, but Origami sent no PROBATION_WORKING_DAYS this cycle for this employee -> paid in full, flagged ---\n";
    $syncNoDataDetails = $createSyncRun([
        ['report_item_id' => 1, 'payroll_code' => $probationEmpNo, 'working_days' => 20, 'item_values' => []],
    ]);
    $syncNoDataRow = $rowFor($syncNoDataDetails, $probationEmpId);
    check('sync_actual_days, no data: probation employee paid in FULL (30000), not a guessed prorate', (float)$syncNoDataRow['base_salary_amount'], 30000.0);
    checkTrue('sync_actual_days, no data: sync_actual_days_no_data flag surfaced in calc_errors', strpos((string)($syncNoDataRow['calc_errors'] ?? ''), 'sync_actual_days_no_data') !== false);

    echo "--- non-sync run (cycle-based, no sync_process_id): no sync data exists at all -> paid in full, flagged ---\n";
    $policyModel->save($compId, ['pay_basis' => 'sync_actual_days', 'pay_basis_deduct_holidays' => false, 'pay_basis_deduct_leave' => false], $adminUserId);
    $nonSyncDetails = $createRun();
    $nonSyncRow = $rowFor($nonSyncDetails, $probationEmpId);
    check('sync_actual_days, non-sync run: probation employee paid in FULL (30000)', (float)$nonSyncRow['base_salary_amount'], 30000.0);
    checkTrue('sync_actual_days, non-sync run: sync_actual_days_no_data flag surfaced too (no sync row exists at all, same "no data" outcome)', strpos((string)($nonSyncRow['calc_errors'] ?? ''), 'sync_actual_days_no_data') !== false);

    // Reset back to the safe default so no other test file running after this one in the same
    // process (unlikely, but this project runs each test file as its own standalone process anyway)
    // could ever observe a lingering non-default company_payroll_policies row -- belt-and-suspenders,
    // the transaction rollback already guarantees this, but matches this file's own explicit-cleanup
    // spirit elsewhere.
    $policyModel->save($compId, ['pay_basis' => 'full_month', 'pay_basis_deduct_holidays' => false, 'pay_basis_deduct_leave' => false], $adminUserId);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
