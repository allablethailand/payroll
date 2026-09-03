<?php
/**
 * Lightweight verification script for TransactionDataPayAdapter and its wiring into
 * PayrollRunModel::recalculate() (Phase 5, T032, explicit request: "ถ้าข้อมูล match กับพนักงาน/งวดที่
 * ถูกต้อง ให้นำไปใช้คำนวณ", confirmed via AskUserQuestion that this means REAL payroll-amount impact,
 * not just an import UI with no calculation effect).
 *
 * Proves attendance_records/leave_requests/overtime_records (the SAME 3 tables Sync/Import/Manual
 * Entry all write into) now feed a real cycle-based run's earning/deduction lines through the
 * EXISTING SyncPayResolver engine, with amounts computed independently by hand here (same
 * discipline as tests/payroll_run_test.php's own sync-derived-line assertions), not just "some
 * non-zero line appeared."
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 *
 * Run with: php tests/transaction_data_pay_adapter_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/OtRateSetModel.php';
require_once __DIR__ . '/../app/services/TransactionDataPayAdapter.php';

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
function findLine(array $lines, string $code): ?array {
    foreach ($lines as $l) {
        if (($l['code'] ?? null) === $code) {
            return $l;
        }
    }
    return null;
}

try {
    $compId = 1;
    $adminUserId = 1;

    // Same shared-dev-DB isolation precautions tests/payroll_run_test.php already documents (see
    // feedback_dev_db_shared_state_test_fragility in project memory) -- all rolled back at the end.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);
    $pdo->prepare("DELETE FROM `attendance_deduction_rules` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `approval_workflows` w
        JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
        SET w.status = 'inactive'
        WHERE w.comp_id = :comp_id AND awdt.document_type_code = 'PAYROLL_RUN_APPROVAL' AND w.status = 'active'")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `ot_rate_sets` SET deleted_at = NOW(), status = 'deleted' WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $paymentDate = $periodEnd;

    // ---------- Fixtures ----------
    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'TDPA_CYCLE_' . uniqid(),
        'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $baseSalary = 30000.0;
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'อดัปเตอร์', 'Test', 'Adapter', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2020-01-01', 'average', 'active', 1, 1, 0, 1)")
        ->execute([':comp_id' => $compId, ':employee_no' => 'TDPA_EMP_' . uniqid(), ':email' => uniqid() . '@test.local', ':base_salary' => $baseSalary]);
    $employeeId = (int)$pdo->lastInsertId();

    $tdpaOtRateSetModel = new OtRateSetModel($pdo);
    $tdpaWeekdayScopeId = (int)$pdo->query("SELECT id FROM master_ot_scope_types WHERE code = 'weekday'")->fetchColumn();
    $tdpaSetSave = $tdpaOtRateSetModel->save([
        'name_th' => 'ชุด OT TDPA', 'name_en' => 'TDPA OT Set', 'is_default' => true,
        'items' => [['ot_scope_id' => $tdpaWeekdayScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.50, 'calculation_base' => 'hourly']],
    ], $compId, $adminUserId);
    checkTrue('fixture: OT rate created' . (empty($tdpaSetSave['status']) ? " ({$tdpaSetSave['message']})" : ''), $tdpaSetSave['status']);

    $categoryId = (int)$pdo->query("SELECT id FROM master_leave_categories LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO leave_types (comp_id, category_id, code, name_th, name_en, quota_amount, is_paid, status, data_source)
            VALUES (:comp_id, :category_id, 'TDPA_UNPAID', 'ลาไม่รับเงิน', 'Unpaid Leave', 0, 0, 'active', 'manual')")
        ->execute([':comp_id' => $compId, ':category_id' => $categoryId]);
    $unpaidLeaveTypeId = (int)$pdo->lastInsertId();

    // Attendance: late 30 minutes on one day, present otherwise -- data_source doesn't matter to
    // the adapter (it reads regardless of source, by design -- Sync/Import/Manual Entry are all
    // equally valid inputs to this same pipeline).
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, late_minutes, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, 30, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':work_date' => $periodStart]);

    // Overtime: 2 approved hours on the weekday scope.
    $otRateId = (int)$pdo->query("SELECT i.id FROM ot_rate_set_items i JOIN ot_rate_sets s ON s.id = i.set_id
        WHERE s.comp_id = {$compId} AND s.deleted_at IS NULL ORDER BY i.id DESC LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO overtime_records (comp_id, employee_id, ot_date, ot_rate_id, hours, status, data_source)
        VALUES (:comp_id, :employee_id, :ot_date, :ot_rate_id, 2.0, 'approved', 'import')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':ot_date' => $periodStart, ':ot_rate_id' => $otRateId]);

    // Leave: 1 unpaid day, approved, wholly inside the period.
    $leaveDate = (clone $today)->modify('first day of this month')->modify('+2 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO leave_requests (comp_id, employee_id, leave_type_id, start_date, end_date, total_days, status, data_source)
        VALUES (:comp_id, :employee_id, :leave_type_id, :d, :d, 1.0, 'approved', 'sync')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':leave_type_id' => $unpaidLeaveTypeId, ':d' => $leaveDate]);

    // ---------- TransactionDataPayAdapter unit-level check ----------
    echo "=== TransactionDataPayAdapter::buildSyntheticRows() ===\n";
    $synthetic = TransactionDataPayAdapter::buildSyntheticRows($pdo, $compId, [$employeeId], $periodStart, $periodEnd);
    checkTrue('an entry exists for the employee (has real records)', isset($synthetic[$employeeId]));
    check('late_mins aggregated', $synthetic[$employeeId]['late_mins'], 30.0);
    check('ot_req_working_day_hrs aggregated', $synthetic[$employeeId]['ot_req_working_day_hrs'], 2.0);
    check('leave_without_pay_days aggregated', $synthetic[$employeeId]['leave_without_pay_days'], 1.0);
    check('leave_wait_days is 0 (no pending leave)', $synthetic[$employeeId]['leave_wait_days'], 0.0);
    $syntheticEmpty = TransactionDataPayAdapter::buildSyntheticRows($pdo, $compId, [999999999], $periodStart, $periodEnd);
    check('an employee with zero records gets NO entry at all', $syntheticEmpty, []);

    // ---------- End-to-end through PayrollRunModel::recalculate() ----------
    echo "=== recalculate() on a cycle-based run now computes real lines from these 3 tables ===\n";
    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'TDPA_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: run created' . (empty($createRes['status']) ? " ({$createRes['message']})" : ''), $createRes['status']);
    $runId = $createRes['id'];

    $recalcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate succeeds' . (empty($recalcRes['status']) ? " ({$recalcRes['message']})" : ''), $recalcRes['status']);

    $detailRow = $pdo->prepare("SELECT earning_breakdown, deduction_breakdown, calc_status, calc_errors FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $detailRow->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
    $detail = $detailRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('a calculation row exists for this employee', $detail !== false);
    $earningLines = json_decode((string)($detail['earning_breakdown'] ?? '[]'), true) ?? [];
    $deductionLines = json_decode((string)($detail['deduction_breakdown'] ?? '[]'), true) ?? [];

    // hourlyRate = (baseSalary/30 days) / 8 hours = 125.00/hr (no working_days on the synthetic row -- fixed 30/8 fallback).
    $hourlyRate = ($baseSalary / 30.0) / 8.0;

    $lateLine = findLine($deductionLines, 'LATE_DEDUCT');
    checkTrue('LATE_DEDUCT line present (from attendance_records.late_minutes)', $lateLine !== null);
    if ($lateLine !== null) {
        check('LATE_DEDUCT amount = hourlyRate/60 * 30min * 1.0 (no rule configured, default multiplier)', (float)$lateLine['amount'], round($hourlyRate / 60.0 * 30.0, 2));
        check('LATE_DEDUCT line is marked sync-sourced', $lateLine['source'], 'sync');
    }
    // 2026-09-02, real reachable case for SyncPayResolver's own working_days_fallback_with_
    // attendance_deduction warning (see that class's own 2026-09-02 docblock) -- this synthetic row
    // NEVER carries working_days/working_mins (TransactionDataPayAdapter's own deliberate design,
    // "inventing one here would be a guess"), so the LATE_DEDUCT amount above genuinely used the
    // fixed 30-day fallback divisor -- the warning SHOULD appear here, every time, for every
    // Manual/Import-driven cycle run with a percent_of_rate attendance deduction. Confirmed advisory
    // ONLY -- must never block this run's calc_status, or Manual Entry/Import payroll would be
    // permanently stuck in 'error' with no way to ever provide a real working_days value.
    checkTrue('working_days_fallback_with_attendance_deduction:late warning appears in calc_errors', strpos((string)($detail['calc_errors'] ?? ''), 'working_days_fallback_with_attendance_deduction:late') !== false);
    check('calc_status stays "calculated" despite the warning -- advisory only, never blocks a Manual/Import-driven run', $detail['calc_status'] ?? null, 'calculated');

    $otLine = findLine($earningLines, 'OT');
    checkTrue('OT earning line present (from overtime_records.hours)', $otLine !== null);
    if ($otLine !== null) {
        // computeOtAmountFromConfig: otHourlyRate * multiplier * hours = 125.00 * 1.50 * 2 = 375.00.
        check('OT amount = fixed 30/8 hourly rate * 1.50 multiplier * 2 hours', (float)$otLine['amount'], round($hourlyRate * 1.50 * 2.0, 2));
    }

    $leaveLine = findLine($deductionLines, 'LEAVE_NO_PAY_DEDUCT');
    checkTrue('LEAVE_NO_PAY_DEDUCT line present (from leave_requests, unpaid leave_type)', $leaveLine !== null);
    if ($leaveLine !== null) {
        // 1 day -> 480 minutes (8h standard) -> (hourlyRate/60)*480*1.0 = dailyRate = baseSalary/30 = 1000.00.
        check('LEAVE_NO_PAY_DEDUCT amount = baseSalary/30 (1 full day)', (float)$leaveLine['amount'], round($baseSalary / 30.0, 2));
    }

    // ---------- Off-cycle/incentive runs deliberately stay untouched by this wiring ----------
    echo "=== Off-cycle run: NOT affected (manually-picked items only, same precedent as PED/recurring earnings) ===\n";
    $offStart = (clone $today)->modify('first day of +6 months')->format('Y-m-d');
    $offEnd = (clone $today)->modify('last day of +6 months')->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, late_minutes, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, 45, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':work_date' => $offStart]);
    $offCreateRes = $runModel->create($compId, [
        'run_name' => 'TDPA_OFFCYCLE_' . uniqid(),
        'period_start_date' => $offStart, 'period_end_date' => $offEnd, 'payment_date' => $offEnd,
    ], $adminUserId, true);
    checkTrue('fixture: off-cycle run created', $offCreateRes['status']);
    $joinRes = $runModel->joinEmployees($offCreateRes['id'], $compId, [$employeeId], $adminUserId, true);
    checkTrue('fixture: employee joined to off-cycle run', $joinRes['status']);
    $offRecalcRes = $runModel->recalculate($offCreateRes['id'], $compId, $adminUserId, true);
    checkTrue('off-cycle recalculate succeeds', $offRecalcRes['status']);
    $offDetailRow = $pdo->prepare("SELECT earning_breakdown, deduction_breakdown FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $offDetailRow->execute([':run_id' => $offCreateRes['id'], ':employee_id' => $employeeId]);
    $offDetail = $offDetailRow->fetch(PDO::FETCH_ASSOC);
    $offDeductionLines = json_decode((string)($offDetail['deduction_breakdown'] ?? '[]'), true) ?? [];
    check('off-cycle run has NO LATE_DEDUCT line despite real attendance data existing in its period', findLine($offDeductionLines, 'LATE_DEDUCT'), null);

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
