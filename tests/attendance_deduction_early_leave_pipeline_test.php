<?php
/**
 * Phase 8, T043: "payroll-configuration หักตามข้อมูลการเข้างาน: เพิ่ม 'กลับก่อนเวลา' ตั้งค่าได้แบบเดียวกับ
 * 'มาสาย' (rate/เงื่อนไขแบบเดียวกัน)".
 *
 * tests/attendance_deduction_rule_test.php already covers AttendanceDeductionRuleModel::ruleSave()/
 * ruleGetAll() CRUD for 'early_leave' in isolation (including the real DB-enum bug this feature
 * uncovered -- attendance_deduction_rules.event_code is a hard MySQL enum, and this dev DB's
 * sql_mode has no STRICT_TRANS_TABLES, so an unwidened enum silently coerced 'early_leave' to '' on
 * INSERT instead of erroring; fixed via 2026-08-30_19_attendance_deduction_early_leave_event.sql).
 * That test never exercises the real calculation pipeline, though -- this file does: configure a
 * real early_leave rule via the model (same as an admin would through the settings UI), feed
 * attendance_records.early_leave_minutes through TransactionDataPayAdapter -> SyncPayResolver ->
 * PayrollRunModel::recalculate() exactly like production, and confirm the resulting deduction line
 * uses the CONFIGURED rule (not the bare percent_of_rate @ 1.00 default) -- proving the whole chain
 * end to end, not just that the model persists a row correctly. Mirrors
 * tests/payroll_base_salary_ot_rate_test.php's fixture pattern (same shared-dev-DB isolation
 * preamble, same technique for a 2nd run over the same period needing its own cycle).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/attendance_deduction_early_leave_pipeline_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AttendanceDeductionRuleModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
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
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function findLine(array $lines, string $code): ?array {
    foreach ($lines as $l) {
        if (($l['code'] ?? null) === $code) { return $l; }
    }
    return null;
}

try {
    $compId = 1;
    $adminUserId = 1;

    // Same shared-dev-DB isolation precautions tests/payroll_run_test.php already documents.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);
    $pdo->prepare("DELETE FROM `attendance_deduction_rules` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `approval_workflows` w
        JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
        SET w.status = 'inactive'
        WHERE w.comp_id = :comp_id AND awdt.document_type_code = 'PAYROLL_RUN_APPROVAL' AND w.status = 'active'")
        ->execute([':comp_id' => $compId]);

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of +101 months')->format('Y-m-d'); // distinct offset from other test files' fixture periods
    $periodEnd = (clone $today)->modify('last day of +101 months')->format('Y-m-d');
    $paymentDate = $periodEnd;

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'T043_CYCLE_' . uniqid(),
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
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'กลับก่อนเวลา', 'Test', 'EarlyLeave', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2020-01-01', 'average', 'active', 1, 1, 0)")
        ->execute([':comp_id' => $compId, ':employee_no' => 'T043_EMP_' . uniqid(), ':email' => uniqid() . '@test.local', ':base_salary' => $baseSalary]);
    $employeeId = (int)$pdo->lastInsertId();

    // Attendance: left 40 minutes early, on a day with no lateness/absence at all -- isolates the
    // early_leave event cleanly.
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, early_leave_minutes, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, 40, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':work_date' => $periodStart]);

    $runModel = new PayrollRunModel($pdo);
    $hourlyRate = ($baseSalary / 30.0) / 8.0;

    echo "=== Baseline: no early_leave rule configured -- bare default (percent_of_rate @ 1.00 multiplier) ===\n";
    $baselineRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'T043_BASELINE_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: baseline run created', $baselineRunRes['status']);
    $baselineRunId = $baselineRunRes['id'];
    $runModel->recalculate($baselineRunId, $compId, $adminUserId, true);
    $baselineDetail = $pdo->prepare("SELECT deduction_breakdown FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $baselineDetail->execute([':run_id' => $baselineRunId, ':employee_id' => $employeeId]);
    $baseline = $baselineDetail->fetch(PDO::FETCH_ASSOC);
    $baselineEarlyLeave = findLine(json_decode((string)$baseline['deduction_breakdown'], true) ?? [], 'EARLY_LEAVE_DEDUCT');
    checkTrue('baseline: EARLY_LEAVE_DEDUCT line is present even with no rule configured (default still applies)', $baselineEarlyLeave !== null);
    $expectedDefaultAmount = round($hourlyRate / 60.0 * 40.0, 2); // percent_of_rate, multiplier 1.00
    check('baseline: EARLY_LEAVE_DEDUCT matches the bare default formula (percent_of_rate @ 1.00x)', (float)($baselineEarlyLeave['amount'] ?? null), $expectedDefaultAmount);

    echo "=== T043: configure a real early_leave rule via the settings model (flat_amount, 5.00/minute) ===\n";
    $ruleModel = new AttendanceDeductionRuleModel($pdo);
    $saveRule = $ruleModel->ruleSave(['event_code' => 'early_leave', 'method_code' => 'flat_amount', 'rate_unit' => 'minute', 'rate_per_unit' => 5.0], $compId, $adminUserId);
    checkTrue('fixture: early_leave rule saved' . (empty($saveRule['status']) ? " ({$saveRule['message']})" : ''), $saveRule['status']);
    $allRules = $ruleModel->ruleGetAll($compId);
    check('rule round-trips correctly via ruleGetAll() before feeding it through payroll', $allRules['early_leave'][0]['method_code'] ?? null, 'flat_amount');

    // A second cycle -- create() uniquely constrains (cycle_id, period), so a second run over the
    // SAME period as the baseline needs its own cycle (same technique as payroll_base_salary_ot_rate_test.php).
    $cycleRes2 = $cycleModel->save($compId, [
        'cycle_name' => 'T043_CYCLE2_' . uniqid(),
        'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: second cycle created', $cycleRes2['status']);
    $configuredRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleRes2['id'], 'run_name' => 'T043_CONFIGURED_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: configured run created', $configuredRunRes['status']);
    $configuredRunId = $configuredRunRes['id'];
    $runModel->recalculate($configuredRunId, $compId, $adminUserId, true);

    $configuredDetail = $pdo->prepare("SELECT deduction_breakdown FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $configuredDetail->execute([':run_id' => $configuredRunId, ':employee_id' => $employeeId]);
    $configured = $configuredDetail->fetch(PDO::FETCH_ASSOC);
    $configuredEarlyLeave = findLine(json_decode((string)$configured['deduction_breakdown'], true) ?? [], 'EARLY_LEAVE_DEDUCT');
    checkTrue('configured run: EARLY_LEAVE_DEDUCT line present', $configuredEarlyLeave !== null);
    $expectedConfiguredAmount = round(5.0 * 40.0, 2); // flat_amount, 5.00/minute * 40 minutes
    check('configured run: EARLY_LEAVE_DEDUCT amount uses the CONFIGURED flat_amount rule (5.00/min * 40min = 200.00), not the bare default', (float)($configuredEarlyLeave['amount'] ?? null), $expectedConfiguredAmount);
    check('the configured amount genuinely differs from the baseline default (proves the rule actually took effect, not a coincidental match)', (float)($configuredEarlyLeave['amount'] ?? null) !== (float)($baselineEarlyLeave['amount'] ?? null), true);

    echo "=== Label round-trips through the formula-explanation popover's lookup path (detail.js's FORMULA_EVENT_LABELS_RD) ===\n";
    // Not a JS test (no PHP coverage for that file, see project convention) -- confirms the underlying
    // data this UI element depends on (the line's own `note` field, parsed via
    // /^sync_(.+?)_([\d.]+)(minutes|hours|days|money)(_corrected)?$/ in detail.js) is actually shaped
    // the way that regex expects, so the 'early_leave' key it extracts really does match the new
    // FORMULA_EVENT_LABELS_RD['early_leave'] entry added alongside this feature.
    $note = (string)($configuredEarlyLeave['note'] ?? '');
    checkTrue("EARLY_LEAVE_DEDUCT line's note matches detail.js's sync_<event>_<n><unit> pattern (event key = 'early_leave')",
        (bool)preg_match('/^sync_early_leave_[\d.]+(minutes|hours|days|money)(_corrected)?$/', $note));

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
