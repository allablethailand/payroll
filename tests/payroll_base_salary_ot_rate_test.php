<?php
/**
 * Phase 8, T042: "Verify: ติ๊กไม่เอาฐานเงินเดือนมาคำนวณ แต่มี OT ที่ต้องใช้ฐานคิด Rate ต้อง confirm ว่าคำนวณ
 * OT ได้ถูกต้องปกติ แม้ฐานเงินเดือนถูกติ๊กไม่เอาเข้ารอบหลัก (แยก use-case ฐานสำหรับ "จ่ายฐาน" กับฐานสำหรับ
 * "อ้างอิงคำนวณ rate" ออกจากกัน)".
 *
 * Investigated first (not guessed): PayrollRunModel::recalculate() already keeps TWO separate
 * variables per employee -- `$baseSalary` (the employee's real, decrypted base_salary_amount,
 * fetched once and NEVER reassigned by any exclusion/override logic) and `$effectiveBase` (what
 * actually gets PAID as the base salary LINE this run, which the "exclude base salary"
 * run-setting/per-employee-override CAN zero out). SyncPayResolver::resolve() -- the engine behind
 * OT and every attendance-driven deduction (late/absent/unpaid-leave/early-leave) -- is always
 * called with `$baseSalary` (see the exact call site in recalculate(), which runs BEFORE the
 * base-salary-exclusion block later in the same per-employee loop), never `$effectiveBase`. This
 * test PROVES that separation holds end-to-end through a real recalculate() call (not just reading
 * the code) -- exercises BOTH OT (the ticket's own explicit example) and a late-deduction (the same
 * "needs the real rate, not the payable amount" concern applies identically there) to cover the
 * general principle, not just the one named case.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 *
 * Run with: php tests/payroll_base_salary_ot_rate_test.php
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
    $pdo->prepare("UPDATE `ot_rate_sets` SET deleted_at = NOW(), status = 'deleted' WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of +100 months')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of +100 months')->format('Y-m-d');
    $paymentDate = $periodEnd;

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'T042_CYCLE_' . uniqid(),
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
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'ฐานOT', 'Test', 'BaseOtRate', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', :base_salary, '2020-01-01', 'average', 'active', 1, 1, 0, 1)")
        ->execute([':comp_id' => $compId, ':employee_no' => 'T042_EMP_' . uniqid(), ':email' => uniqid() . '@test.local', ':base_salary' => $baseSalary]);
    $employeeId = (int)$pdo->lastInsertId();

    $otRateSetModel = new OtRateSetModel($pdo);
    $weekdayScopeId = (int)$pdo->query("SELECT id FROM master_ot_scope_types WHERE code = 'weekday'")->fetchColumn();
    $setSave = $otRateSetModel->save([
        'name_th' => 'ชุด OT T042', 'name_en' => 'T042 OT Set', 'is_default' => true,
        'items' => [['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.50, 'calculation_base' => 'hourly']],
    ], $compId, $adminUserId);
    checkTrue('fixture: OT Rate Set created' . (empty($setSave['status']) ? " ({$setSave['message']})" : ''), $setSave['status']);
    $stmtItemId = $pdo->prepare("SELECT id FROM ot_rate_set_items WHERE set_id = :set_id AND ot_scope_id = :scope_id");
    $stmtItemId->execute([':set_id' => $setSave['id'], ':scope_id' => $weekdayScopeId]);
    $otRateId = (int)$stmtItemId->fetchColumn();

    // Attendance: late 45 minutes -- exercises the SAME "must use the real rate, not the payable
    // amount" concern the ticket raises for OT, applied to an attendance-driven deduction too.
    $pdo->prepare("INSERT INTO attendance_records (comp_id, employee_id, work_date, late_minutes, status, data_source)
        VALUES (:comp_id, :employee_id, :work_date, 45, 'present', 'manual')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':work_date' => $periodStart]);

    // Overtime: 3 approved hours.
    $pdo->prepare("INSERT INTO overtime_records (comp_id, employee_id, ot_date, ot_rate_id, hours, status, data_source)
        VALUES (:comp_id, :employee_id, :ot_date, :ot_rate_id, 3.0, 'approved', 'import')")
        ->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':ot_date' => $periodStart, ':ot_rate_id' => $otRateId]);

    $runModel = new PayrollRunModel($pdo);
    $hourlyRate = ($baseSalary / 30.0) / 8.0; // the fixed standard rate both OT and late-deduction should use.
    $expectedOt = round($hourlyRate * 1.50 * 3.0, 2);
    $expectedLate = round($hourlyRate / 60.0 * 45.0, 2); // default percent_of_rate @ multiplier 1.00, no rule configured.

    echo "=== Baseline: base salary INCLUDED -- confirms the expected OT/late amounts before touching exclusion at all ===\n";
    $baselineRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'T042_BASELINE_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: baseline run created', $baselineRunRes['status']);
    $baselineRunId = $baselineRunRes['id'];
    $runModel->recalculate($baselineRunId, $compId, $adminUserId, true);
    $baselineDetail = $pdo->prepare("SELECT base_salary_amount, earning_breakdown, deduction_breakdown FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $baselineDetail->execute([':run_id' => $baselineRunId, ':employee_id' => $employeeId]);
    $baseline = $baselineDetail->fetch(PDO::FETCH_ASSOC);
    check('baseline: base_salary_amount paid in full', (float)$baseline['base_salary_amount'], $baseSalary);
    $baselineOt = findLine(json_decode((string)$baseline['earning_breakdown'], true) ?? [], 'OT');
    $baselineLate = findLine(json_decode((string)$baseline['deduction_breakdown'], true) ?? [], 'LATE_DEDUCT');
    check('baseline: OT amount matches the hand-computed formula', (float)($baselineOt['amount'] ?? null), $expectedOt);
    check('baseline: LATE_DEDUCT amount matches the hand-computed formula', (float)($baselineLate['amount'] ?? null), $expectedLate);

    echo "=== T042: base salary EXCLUDED at the run level (\"ติ๊กไม่เอาฐานเงินเดือนมาคำนวณ\") -- OT and the" .
        " late deduction must STILL compute off the employee's real base salary rate, completely" .
        " unaffected by the exclusion ===\n";
    // A second cycle -- create() uniquely constrains (cycle_id, period), so a second run over the
    // SAME period as the baseline needs its own cycle.
    $cycleRes2 = $cycleModel->save($compId, [
        'cycle_name' => 'T042_CYCLE2_' . uniqid(),
        'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: second cycle created', $cycleRes2['status']);
    $excludedRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleRes2['id'], 'run_name' => 'T042_EXCLUDED_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: excluded run created', $excludedRunRes['status']);
    $excludedRunId = $excludedRunRes['id'];
    $settingsRes = $runModel->runSettingsSave($excludedRunId, $compId, 'use_employee_setting', 'use_employee_setting', [PayrollRunModel::BASE_SALARY_OVERRIDE_CODE], $adminUserId, true);
    checkTrue('fixture: run-level base-salary exclusion saved' . (empty($settingsRes['status']) ? " ({$settingsRes['message']})" : ''), $settingsRes['status']);
    $runModel->recalculate($excludedRunId, $compId, $adminUserId, true);

    $excludedDetail = $pdo->prepare("SELECT base_salary_amount, earning_breakdown, deduction_breakdown FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $excludedDetail->execute([':run_id' => $excludedRunId, ':employee_id' => $employeeId]);
    $excluded = $excludedDetail->fetch(PDO::FETCH_ASSOC);
    check('base salary is genuinely excluded from this run\'s payout (0.00, proves the exclusion setting actually took effect)', (float)$excluded['base_salary_amount'], 0.0);

    $excludedOt = findLine(json_decode((string)$excluded['earning_breakdown'], true) ?? [], 'OT');
    checkTrue('OT line still present even with base salary excluded', $excludedOt !== null);
    check('OT amount is IDENTICAL to the baseline -- computed off the real base salary rate, not the (now zero) payable amount', (float)($excludedOt['amount'] ?? null), $expectedOt);

    $excludedLate = findLine(json_decode((string)$excluded['deduction_breakdown'], true) ?? [], 'LATE_DEDUCT');
    checkTrue('LATE_DEDUCT line still present even with base salary excluded', $excludedLate !== null);
    check('LATE_DEDUCT amount is IDENTICAL to the baseline too -- same rate-reference principle applies to every attendance-driven deduction, not just OT', (float)($excludedLate['amount'] ?? null), $expectedLate);

    echo "=== Sanity: gross pay for the excluded run correctly reflects OT+baseline earnings minus deductions, WITHOUT the base salary itself ===\n";
    $grossRow = $pdo->prepare("SELECT gross_amount FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $grossRow->execute([':run_id' => $excludedRunId, ':employee_id' => $employeeId]);
    $expectedGross = round(0.0 + $expectedOt, 2); // effectiveBase(0) + earningTotal(OT only) -- matches recalculate()'s own $grossAmount formula.
    check('gross_amount = 0 (base) + OT earning, exactly (base salary genuinely contributes nothing)', (float)$grossRow->fetchColumn(), $expectedGross);

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
