<?php
/**
 * Verification for the attendance summary import (AttendanceSummaryValidator / AttendanceSummaryImportEntity, entity type attendance_summary):
 * per-employee per-run correction figures written to payroll_run_sync_item_overrides, with batch rollback
 * (PayrollRunModel::rollbackAttendanceOverrideImportBatch()). Runs inside a transaction that is always rolled back, on a throwaway company.
 * Requires migration 2026-10-01_6_attendance_override_import_batch.
 *
 * Run with: php tests/attendance_summary_import_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/services/import/ImportService.php';

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
function errorKey(callable $fn): ?string {
    try { $fn(); } catch (ImportRowValidationException $e) { return $e->getErrorKey(); }
    return null;
}

try {
    $userId = 1;
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:n, :n, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')");
    $insComp->execute([':n' => 'ATS Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();
    $insComp->execute([':n' => 'ATS Other Co ' . uniqid()]);
    $otherComp = (int)$pdo->lastInsertId();

    $cycleId = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'ATS_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId)['id'];
    $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status, created_by) VALUES (:c, :code, 'ทดสอบ', 'Test Dept', 'active', :u)")
        ->execute([':c' => $compId, ':code' => 'ATS_' . substr(uniqid(), -6), ':u' => $userId]);
    $deptId = (int)$pdo->lastInsertId();

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :no, 'mr', 'male', 'ทดสอบ', 'ATS', 'Test', 'ATS', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active', 0, 0, 0, :dept)");
    $newEmp = function (int $comp, string $no) use ($insEmp, $pdo, $deptId): int {
        $insEmp->execute([':comp_id' => $comp, ':no' => $no, ':email' => uniqid() . '@test.local', ':dept' => $deptId]);
        return (int)$pdo->lastInsertId();
    };
    $emp1 = $newEmp($compId, 'ATS-1');
    $emp2 = $newEmp($compId, 'ATS-2');
    $emp3 = $newEmp($compId, 'ATS-3');
    $newEmp($otherComp, 'ATS-X');
    // An override only corrects figures that exist, so emp1 gets one attendance day in the period (see the entity's docblock); emp2 has none.
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, status, late_minutes, data_source) VALUES (:c, :e, '2026-03-10', 'present', 0, 'manual')")
        ->execute([':c' => $compId, ':e' => $emp1]);

    $runModel = new PayrollRunModel($pdo);
    $mkRun = function (string $start, string $end) use ($runModel, $compId, $cycleId, $userId): int {
        $create = $runModel->create($compId, ['cycle_id' => $cycleId, 'run_name' => 'ATS_RUN_' . uniqid(), 'period_start_date' => $start, 'period_end_date' => $end, 'payment_date' => $end], $userId, true);
        if (empty($create['status'])) throw new RuntimeException('run create failed: ' . ($create['message'] ?? ''));
        $recalc = $runModel->recalculate($create['id'], $compId, $userId, true);
        if (empty($recalc['status'])) throw new RuntimeException('recalculate failed: ' . ($recalc['message'] ?? ''));
        return $create['id'];
    };
    $runMar = $mkRun('2026-03-01', '2026-03-31');
    $deduction = fn(int $run, int $emp) => (float)$pdo->query("SELECT total_deduction_amount FROM payroll_run_details WHERE run_id = {$run} AND employee_id = {$emp}")->fetchColumn();
    $overrides = fn(?int $batch = null) => (int)$pdo->query("SELECT COUNT(*) FROM payroll_run_sync_item_overrides WHERE run_id IN (SELECT id FROM payroll_runs WHERE comp_id = {$compId})"
        . ($batch !== null ? " AND import_batch_id = {$batch}" : ''))->fetchColumn();
    $baseDeduction = $deduction($runMar, $emp1);
    $baseDeduction2 = $deduction($runMar, $emp2);

    $validator = new AttendanceSummaryValidator($pdo);
    $row = ['employee_code' => 'ATS-1', 'period_month' => '3', 'period_year' => '2026', 'absent_days' => '2', 'late_minutes' => '30', 'ot_working_day_hours' => '4.5'];

    echo "=== template ===\n";
    $svc = new ImportService($pdo);
    check('template columns', array_keys($svc->templateColumns('attendance_summary')),
        ['employee_code', 'period_month', 'period_year', 'run_code', 'late_minutes', 'absent_days', 'unpaid_leave_days', 'ot_working_day_hours', 'ot_weekend_hours', 'ot_holiday_hours']);
    check('the daily attendance entity keeps its own name and template', array_keys($svc->templateColumns('attendance'))[1], 'employee_no');

    echo "=== validator ===\n";
    $ok = $validator->validate($compId, $row);
    check('valid row maps file columns to override columns', [$ok['employee_id'], $ok['run_id'], $ok['fields']], [$emp1, $runMar, ['late_mins' => 30.0, 'absent_days' => 2.0, 'ot_req_working_day_hrs' => 4.5]]);
    check('employee_code required', errorKey(fn() => $validator->validate($compId, ['employee_code' => ''] + $row)), 'employee_code_required');
    check('unknown employee', errorKey(fn() => $validator->validate($compId, ['employee_code' => 'NOPE'] + $row)), 'employee_not_found');
    check('another company\'s employee', errorKey(fn() => $validator->validate($compId, ['employee_code' => 'ATS-X'] + $row)), 'employee_not_found');
    foreach (['late_minutes' => '-1', 'absent_days' => 'abc', 'unpaid_leave_days' => '-0.5', 'ot_weekend_hours' => '-2', 'ot_holiday_hours' => 'x', 'ot_working_day_hours' => '-4.5'] as $col => $bad) {
        check("{$col} = '{$bad}' rejected", errorKey(fn() => $validator->validate($compId, [$col => $bad] + $row)), 'value_invalid');
    }
    check('a figure too large for its column', errorKey(fn() => $validator->validate($compId, ['absent_days' => '10000'] + $row)), 'value_invalid');
    check('0 is allowed', $validator->validate($compId, ['absent_days' => '0', 'late_minutes' => '', 'ot_working_day_hours' => ''] + $row)['fields'], ['absent_days' => 0.0]);
    check('at least one figure is required', errorKey(fn() => $validator->validate($compId, ['absent_days' => '', 'late_minutes' => '', 'ot_working_day_hours' => ''] + $row)), 'no_values');
    check('month out of range', errorKey(fn() => $validator->validate($compId, ['period_month' => '0'] + $row)), 'period_invalid');
    check('no draft run that month', errorKey(fn() => $validator->validate($compId, ['period_month' => '8'] + $row)), 'no_draft_run_for_period');

    $insRun = $pdo->prepare("INSERT INTO payroll_runs (comp_id, cycle_id, run_code, run_name, period_start_date, period_end_date, payment_date, state, status) VALUES (:c, :cy, :code, :n, :ps, :pe, :pe, :st, 'active')");
    $insRun->execute([':c' => $compId, ':cy' => $cycleId, ':code' => 'ATS-A', ':n' => 'ATS multi A', ':ps' => '2026-05-01', ':pe' => '2026-05-31', ':st' => 'draft']);
    $insRun->execute([':c' => $compId, ':cy' => $cycleId, ':code' => 'ATS-B', ':n' => 'ATS multi B', ':ps' => '2026-05-01', ':pe' => '2026-05-31', ':st' => 'draft']);
    $insRun->execute([':c' => $compId, ':cy' => $cycleId, ':code' => 'ATS-C', ':n' => 'ATS approved', ':ps' => '2026-06-01', ':pe' => '2026-06-30', ':st' => 'approved']);
    check('several draft runs, no run_code', errorKey(fn() => $validator->validate($compId, ['period_month' => '5'] + $row)), 'multiple_draft_runs_for_period');
    check('run_code that is not a draft', errorKey(fn() => $validator->validate($compId, ['period_month' => '6', 'run_code' => 'ATS-C'] + $row)), 'invalid_run_status_for_import');
    check('unknown run_code', errorKey(fn() => $validator->validate($compId, ['run_code' => 'ZZZ'] + $row)), 'run_not_found');
    check('employee not in the run the code points at', errorKey(fn() => $validator->validate($compId, ['period_month' => '5', 'run_code' => 'ATS-A'] + $row)), 'employee_not_in_run');

    echo "=== dry run ===\n";
    $prev = $svc->preview($compId, 'attendance_summary', [$row, ['employee_code' => 'NOPE'] + $row, ['employee_code' => 'ATS-1'] + $row], $userId);
    check('preview: 1 ok, the unknown employee and the in-file duplicate are errors', [$prev['success'], $prev['error']], [1, 2]);
    check('the duplicate is the existing-override rule', str_starts_with($prev['errors'][1]['message'], 'existing_override_record:'), true);
    check('preview writes nothing and recalculates nothing', [$overrides(), $deduction($runMar, $emp1)], [0, $baseDeduction]);

    echo "=== commit ===\n";
    $res = $svc->commit($compId, 'attendance_summary', [$row, ['employee_code' => 'ATS-2', 'absent_days' => '1'] + $row, ['absent_days' => '-3'] + $row], $userId);
    $batch = (int)$res['batch_id'];
    check('commit: 2 rows written, the negative one reported', [$res['success'], $res['error']], [2, 1]);
    check('rows are tagged with the batch', $overrides($batch), 2);
    check('the stored figures are the imported ones', array_map('floatval', $pdo->query("SELECT late_mins, absent_days, ot_req_working_day_hrs FROM payroll_run_sync_item_overrides WHERE run_id = {$runMar} AND employee_id = {$emp1}")->fetch(PDO::FETCH_NUM)), [30.0, 2.0, 4.5]);
    check('the run was recalculated: the employee\'s deductions rose', $deduction($runMar, $emp1) > $baseDeduction, true);
    check('known limit: an employee with no attendance, leave or OT data in the period is not affected by an override (emp2)', $deduction($runMar, $emp2), $baseDeduction2);
    $audit = $pdo->query("SELECT COUNT(*) FROM payroll_run_audit_logs WHERE run_id = {$runMar} AND action = 'attendance_override_save'")->fetchColumn();
    check('each row left its audit note', (int)$audit, 2);
    $existing = $svc->preview($compId, 'attendance_summary', [$row], $userId);
    check('importing the same employee again is refused', [$existing['success'], str_starts_with($existing['errors'][0]['message'], 'existing_override_record:')], [0, true]);
    check('the message names the employee in Thai', str_contains($existing['errors'][0]['message'], 'พนักงาน ATS-1 มีข้อมูล override อยู่แล้วในรอบการจ่ายนี้'), true);

    echo "=== a hand-made override is never overwritten ===\n";
    $pdo->prepare("INSERT INTO payroll_run_sync_item_overrides (run_id, employee_id, absent_days, created_by) VALUES (:r, :e, 9, 1)")->execute([':r' => $runMar, ':e' => $emp3]);

    echo "=== rollback ===\n";
    $pdo->prepare("UPDATE payroll_runs SET state = 'pending_approval' WHERE id = ?")->execute([$runMar]);
    $refused = $runModel->rollbackAttendanceOverrideImportBatch($batch, $compId, $userId, true);
    check('refused when the run is no longer a draft, nothing touched', [$refused['status'], $overrides($batch)], [false, 2]);
    $pdo->prepare("UPDATE payroll_runs SET state = 'draft' WHERE id = ?")->execute([$runMar]);
    $other = $runModel->rollbackAttendanceOverrideImportBatch($batch, $otherComp, $userId, true);
    check('another company cannot roll it back', [$other['status'], $overrides($batch)], [false, 2]);
    $done = $runModel->rollbackAttendanceOverrideImportBatch($batch, $compId, $userId, true);
    check('rollback deletes the batch rows and recalculates once', [$done['status'], $done['lines_removed'], $done['runs_recalculated'], $overrides($batch)], [true, 2, 1, 0]);
    check('the deductions are back to what they were', $deduction($runMar, $emp1), $baseDeduction);
    check('the hand-made row (no batch) survived', (int)$pdo->query("SELECT COUNT(*) FROM payroll_run_sync_item_overrides WHERE run_id = {$runMar} AND import_batch_id IS NULL")->fetchColumn(), 1);
    check('rolling back twice says nothing is left', $runModel->rollbackAttendanceOverrideImportBatch($batch, $compId, $userId, true)['status'], false);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    echo $failures > 0 ? "SOME TESTS FAILED\n" : "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
