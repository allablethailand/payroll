<?php
/**
 * Lightweight verification script for AnnualIncomeSummaryModel (2026-08-29) -- see that model's
 * own docblock for the fiscal-year-bucket/aggregation design. Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that
 * is always rolled back. Uses a fresh throwaway company (not comp_id=1) with a NON-January fiscal
 * year start month specifically, since comp_id=1 only ever exercises the default (January,
 * = plain calendar year) case -- see feedback_dev_db_shared_state_test_fragility.
 * Run with: php tests/annual_income_summary_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AnnualIncomeSummaryModel.php';
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

try {
    $userId = 1;
    // Fiscal year starts in April (month 4) -- exercises the wrap-to-next-calendar-year math a
    // January-start company would never hit.
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, fiscal_year_start_month)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', 4)");
    $insComp->execute([':name' => 'AIS Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $model = new AnnualIncomeSummaryModel($pdo);

    /* ---------- Fiscal year math (April start) ---------- */
    echo "=== Fiscal year bucket math (fiscal_year_start_month=4) ===\n";
    $refl = new ReflectionClass($model);
    $mBounds = $refl->getMethod('fiscalYearBounds'); $mBounds->setAccessible(true);
    $mMonths = $refl->getMethod('monthsInFiscalYear'); $mMonths->setAccessible(true);
    [$start, $end] = $mBounds->invoke($model, 2026, 4);
    check('FY2026 (Apr start) begins 2026-04-01', $start, '2026-04-01');
    check('FY2026 (Apr start) ends 2027-03-31', $end, '2027-03-31');
    $months = $mMonths->invoke($model, 2026, 4);
    check('12 months in the fiscal year', count($months), 12);
    check('first month is 2026-04', $months[0]['year'] . '-' . $months[0]['month'], '2026-4');
    check('last month is 2027-03 (wrapped into the next calendar year)', $months[11]['year'] . '-' . $months[11]['month'], '2027-3');

    /* ---------- Fixture: 2 employees, runs in different months ---------- */
    echo "=== Fixture setup ===\n";
    $cycleModel = new PayrollCycleModel($pdo);
    $cycleSave = $cycleModel->save($compId, [
        'cycle_name' => 'AIS_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    checkTrue('test cycle create succeeds' . (empty($cycleSave['status']) ? " ({$cycleSave['message']})" : ''), $cycleSave['status']);
    $cycleId = $cycleSave['id'];

    $insDept = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status, created_by) VALUES (:comp_id, :code, 'ทดสอบ', 'Test Dept', 'active', :uid)");
    $insDept->execute([':comp_id' => $compId, ':code' => 'AISDEPT_' . substr(uniqid(), -6), ':uid' => $userId]);
    $deptId = (int)$pdo->lastInsertId();

    function insertAisEmployee(PDO $pdo, int $compId, int $deptId, string $employmentDate = '2020-01-01'): int {
        $stmt = $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt, department_id)
            VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'AIS', 'Test', 'AIS', '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             :employment_date, 'permanent', 'full_time', 'office', 'manual',
             'cash', 'monthly', 30000, '2020-01-01', 'average', 'active',
             1, 0, 0, :dept_id)");
        $stmt->execute([':comp_id' => $compId, ':employee_no' => 'AIS_' . uniqid(), ':email' => uniqid() . '@test.local', ':dept_id' => $deptId, ':employment_date' => $employmentDate]);
        return (int)$pdo->lastInsertId();
    }
    $emp1 = insertAisEmployee($pdo, $compId, $deptId);
    $emp2 = insertAisEmployee($pdo, $compId, $deptId);
    // 2026-08-29, explicit follow-up: "สามารถดึงพนักงานทั้งหมดเลยได้ไหมครับ" -- a 3rd employee with NO
    // payroll run at all this fiscal year must still appear (all-zero row), not be excluded.
    // employment_date is deliberately AFTER FY2026 ends (2027-03-31) so PayrollRunModel::
    // recalculate()'s own date-range eligibility (see that model's docblock) naturally never picks
    // them up in any of the 3 fixture runs below -- a genuinely data-less employee, not an
    // artifact of creation order.
    $emp3NoData = insertAisEmployee($pdo, $compId, $deptId, '2027-06-01');

    function makeAisRun(PDO $pdo, PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, string $periodStart, string $periodEnd): int {
        $create = $runModel->create($compId, [
            'cycle_id' => $cycleId, 'run_name' => 'AIS_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
        ], $userId, true);
        if (empty($create['status'])) throw new RuntimeException('run create failed: ' . ($create['message'] ?? ''));
        $runId = $create['id'];
        $recalc = $runModel->recalculate($runId, $compId, $userId, true);
        if (empty($recalc['status'])) throw new RuntimeException('recalculate failed: ' . ($recalc['message'] ?? ''));
        $submit = $runModel->submit($runId, $compId, $userId, true);
        if (empty($submit['status'])) throw new RuntimeException('submit failed: ' . ($submit['message'] ?? ''));
        $approve = $runModel->approve($runId, $compId, $userId, true);
        if (empty($approve['status'])) throw new RuntimeException('approve failed: ' . ($approve['message'] ?? ''));
        return $runId;
    }
    $runModel = new PayrollRunModel($pdo);
    // April 2026 run (both employees) -- first month of FY2026.
    makeAisRun($pdo, $runModel, $compId, $cycleId, $userId, '2026-04-01', '2026-04-30');
    // February 2027 run (both employees) -- last-but-one month of FY2026 (wraps into 2027).
    makeAisRun($pdo, $runModel, $compId, $cycleId, $userId, '2027-02-01', '2027-02-28');
    // A run OUTSIDE the fiscal year (March 2026, before the FY2026 window starts) -- must NOT
    // contribute to FY2026 totals at all.
    makeAisRun($pdo, $runModel, $compId, $cycleId, $userId, '2026-03-01', '2026-03-31');

    /* ---------- availableFiscalYears() ---------- */
    echo "=== availableFiscalYears() ===\n";
    $years = $model->availableFiscalYears($compId, 4);
    check('2 fiscal years found (2025 -- for the March 2026 run -- and 2026)', count($years), 2);
    checkTrue('FY2026 is one of them', in_array(2026, $years, true));
    checkTrue('FY2025 is one of them (March 2026 belongs to FY2025 under an April start)', in_array(2025, $years, true));

    /* ---------- summary() ---------- */
    echo "=== summary() ===\n";
    $result = $model->summary($compId, 2026, 4, []);
    check('3 employees in the FY2026 summary (incl. the one with zero payroll data this year)', count($result['employees']), 3);
    $emp3Row = null;
    foreach ($result['employees'] as $e) { if ($e['employee_id'] === $emp3NoData) $emp3Row = $e; }
    checkTrue('the zero-data employee is included, not filtered out', $emp3Row !== null);
    check('the zero-data employee has annual_net = 0', (float)($emp3Row['annual_net'] ?? -1), 0.0);
    check('12 month columns', count($result['months']), 12);

    $emp1Row = null;
    foreach ($result['employees'] as $e) { if ($e['employee_id'] === $emp1) $emp1Row = $e; }
    checkTrue('employee 1 found in the summary', $emp1Row !== null);
    // Base salary 30000 minus real SSO enrollment deduction (sso_enrolled=1 in the fixture, same
    // ~920.83 deduction seen elsewhere in this suite) -- read back the real calculated net per
    // employee per month instead of assuming a no-deductions figure.
    $stmtRealNet = $pdo->prepare(
        "SELECT SUM(d.net_amount) FROM payroll_run_details d INNER JOIN payroll_runs r ON r.id = d.run_id
         WHERE d.employee_id = :emp AND r.comp_id = :comp AND YEAR(r.period_start_date) = :y AND MONTH(r.period_start_date) = :m"
    );
    $realNetFor = function (int $empId, int $y, int $m) use ($stmtRealNet, $compId): float {
        $stmtRealNet->execute([':emp' => $empId, ':comp' => $compId, ':y' => $y, ':m' => $m]);
        return (float)($stmtRealNet->fetchColumn() ?: 0);
    };
    $realNetApril = $realNetFor($emp1, 2026, 4);
    $realNetFeb = $realNetFor($emp1, 2027, 2);
    checkTrue('real April 2026 net is a sane positive figure under base salary (statutory deducted)', $realNetApril > 0 && $realNetApril < 30000);
    // Float-epsilon comparisons throughout here (not check()'s strict ===) -- both sides are
    // independently-computed SQL SUM()s that can differ by a sub-cent binary-float rounding
    // epsilon even when they represent the same underlying DECIMAL(15,2) value.
    checkTrue('employee 1 April 2026 (index 0) net matches the real calculated value', abs((float)$emp1Row['months'][0]['net'] - $realNetApril) < 0.01);
    checkTrue('employee 1 February 2027 (index 10) net matches the real calculated value', abs((float)$emp1Row['months'][10]['net'] - $realNetFeb) < 0.01);
    check('employee 1 May 2026 (index 1, no run that month) net = 0', (float)$emp1Row['months'][1]['net'], 0.0);
    checkTrue('employee 1 annual_net = April + February real net (only 2 of the 3 runs fall inside FY2026)', abs((float)$emp1Row['annual_net'] - ($realNetApril + $realNetFeb)) < 0.01);

    check('employee_count in totals is 3 (incl. the zero-data employee)', $result['totals']['employee_count'], 3);
    checkTrue('company-wide annual_net is exactly double one employee\'s annual_net (2 identical employees)', abs((float)$result['totals']['annual_net'] - 2 * $emp1Row['annual_net']) < 0.01);

    $aprilMeta = $result['months'][0];
    check('April 2026 month state is past_done (a finalized run exists that month)', $aprilMeta['state'], 'past_done');
    $mayMeta = $result['months'][1];
    check('May 2026 month state is past_missing (no finalized run that month)', $mayMeta['state'], 'past_missing');

    /* ---------- filters ---------- */
    echo "=== filters ===\n";
    $filtered = $model->summary($compId, 2026, 4, ['department_id' => $deptId]);
    check('department_id filter still returns all 3 employees (all in that dept)', count($filtered['employees']), 3);
    $filteredOut = $model->summary($compId, 2026, 4, ['department_id' => 999999]);
    check('a non-matching department_id filter returns zero employees', count($filteredOut['employees']), 0);
    $searched = $model->summary($compId, 2026, 4, ['search' => $emp1Row['employee_no']]);
    check('search by exact employee_no returns exactly 1 employee', count($searched['employees']), 1);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
