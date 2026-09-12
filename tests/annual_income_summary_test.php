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
             salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt, department_id)
            VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'AIS', 'Test', 'AIS', '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             :employment_date, 'permanent', 'full_time', 'office', 'manual',
             'monthly', 30000, '2020-01-01', 'average', 'active',
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
    // 2026-08-30 (Phase 3, T021, explicit request: "ไม่จ่ายเงินเดือน...ไม่แสดงใน Report") -- a 4th
    // employee, staff-only (is_payroll_participant=0), with an ELIGIBLE employment_date (2020-01-01,
    // same as emp1/emp2 -- unlike emp3 above, this one isn't excluded by date range at all) so this
    // specifically proves the exclusion is due to is_payroll_participant, not merely "no data yet".
    $insUnpaidAisEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, employment_date, employment_status, employment_type,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         department_id, is_payroll_participant)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'AIS ไม่จ่าย', 'Test', 'AIS Unpaid', '1990-01-01', 'Thai',
         :email, '0812345679', '2020-01-01', 'permanent', 'full_time',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         :dept_id, 0)");
    $insUnpaidAisEmp->execute([':comp_id' => $compId, ':employee_no' => 'AIS_UNPAID_' . uniqid(), ':email' => uniqid() . '@test.local', ':dept_id' => $deptId]);
    $emp4Unpaid = (int)$pdo->lastInsertId();

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
    check('3 employees in the FY2026 summary (incl. the one with zero payroll data this year) -- the 4th, staff-only fixture is correctly excluded', count($result['employees']), 3);
    $emp4Row = null;
    foreach ($result['employees'] as $e) { if ($e['employee_id'] === $emp4Unpaid) $emp4Row = $e; }
    checkTrue('T021: the staff-only (is_payroll_participant=0) employee is NOT in the summary at all, despite an eligible employment_date identical to emp1/emp2', $emp4Row === null);
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

    /* ---------- Phase 4, T026/T027: annualPitSummary()/monthlyPitDetail() ---------- */
    // Independently-derived reference value (same "don't assume a number, derive it separately"
    // discipline $realNetFor() above already uses) -- sums the TH_PIT line out of the REAL
    // statutory_breakdown JSON this fixture's own recalculate() calls actually produced, rather
    // than assuming/guessing a withholding amount for a 30,000/month salary.
    $stmtRealStatutory = $pdo->prepare(
        "SELECT d.statutory_breakdown FROM payroll_run_details d INNER JOIN payroll_runs r ON r.id = d.run_id
         WHERE d.employee_id = :emp AND r.comp_id = :comp AND YEAR(r.period_start_date) = :y AND MONTH(r.period_start_date) = :m"
    );
    $realPitFor = function (int $empId, int $y, int $m) use ($stmtRealStatutory, $compId): float {
        $stmtRealStatutory->execute([':emp' => $empId, ':comp' => $compId, ':y' => $y, ':m' => $m]);
        $raw = $stmtRealStatutory->fetchColumn();
        if ($raw === false) return 0.0;
        $pit = 0.0;
        foreach (json_decode((string)$raw, true) ?? [] as $item) {
            if (($item['code'] ?? null) === 'TH_PIT') { $pit += (float)($item['employee_amount'] ?? 0); }
        }
        return $pit;
    };
    $realPitApril = $realPitFor($emp1, 2026, 4);
    $realPitFeb = $realPitFor($emp1, 2027, 2);

    echo "=== Phase 4, T027: annualPitSummary() ===\n";
    $pitResult = $model->annualPitSummary($compId, 2026, 4, []);
    check('annualPitSummary(): 12 month columns, same shape as summary()', count($pitResult['months']), 12);
    check('annualPitSummary(): 3 employees (T021 staff-only exclusion applies identically to this new method)', count($pitResult['employees']), 3);
    $pitEmp4Row = null;
    foreach ($pitResult['employees'] as $e) { if ($e['employee_id'] === $emp4Unpaid) $pitEmp4Row = $e; }
    checkTrue('T021+T027: the staff-only employee is excluded from the PIT summary too', $pitEmp4Row === null);
    $pitEmp1Row = null;
    foreach ($pitResult['employees'] as $e) { if ($e['employee_id'] === $emp1) $pitEmp1Row = $e; }
    checkTrue('employee 1 found in the PIT summary', $pitEmp1Row !== null);
    checkTrue('employee 1 April 2026 (index 0) tax_withheld matches the real statutory_breakdown value', abs((float)$pitEmp1Row['months'][0] - $realPitApril) < 0.01);
    checkTrue('employee 1 February 2027 (index 10) tax_withheld matches the real statutory_breakdown value', abs((float)$pitEmp1Row['months'][10] - $realPitFeb) < 0.01);
    check('employee 1 May 2026 (index 1, no run that month) tax_withheld = 0', (float)$pitEmp1Row['months'][1], 0.0);
    checkTrue('employee 1 annual_tax_withheld = April + February (only 2 of 3 runs fall inside FY2026)', abs((float)$pitEmp1Row['annual_tax_withheld'] - ($realPitApril + $realPitFeb)) < 0.01);
    checkTrue('company-wide totals.annual_tax_withheld is exactly double one employee\'s figure (2 identical employees)', abs((float)$pitResult['totals']['annual_tax_withheld'] - 2 * $pitEmp1Row['annual_tax_withheld']) < 0.01);

    echo "=== Phase 4, T026: monthlyPitDetail() ===\n";
    $monthlyApril = $model->monthlyPitDetail($compId, 2026, 4, []);
    check('monthlyPitDetail(April 2026): 2 employees paid that month (zero-data emp3/unpaid emp4 both correctly absent)', count($monthlyApril['employees']), 2);
    $monthlyEmp1Row = null;
    foreach ($monthlyApril['employees'] as $e) { if ($e['employee_id'] === $emp1) $monthlyEmp1Row = $e; }
    checkTrue('employee 1 found in the April 2026 monthly detail', $monthlyEmp1Row !== null);
    checkTrue('monthly detail tax_withheld matches the real statutory_breakdown value', abs((float)$monthlyEmp1Row['tax_withheld'] - $realPitApril) < 0.01);
    checkTrue('monthly detail net_amount matches the real calculated value (same figure summary() already verified)', abs((float)$monthlyEmp1Row['net_amount'] - $realNetApril) < 0.01);
    checkTrue('monthly totals.tax_withheld is exactly double one employee\'s figure', abs((float)$monthlyApril['totals']['tax_withheld'] - 2 * $realPitApril) < 0.01);
    $monthlyMay = $model->monthlyPitDetail($compId, 2026, 5, []);
    check('monthlyPitDetail(May 2026, no run that month) returns zero employees -- not an all-zero row per employee', count($monthlyMay['employees']), 0);

    echo "=== Phase 4, T026: availableCalendarYears() ===\n";
    $calYears = $model->availableCalendarYears($compId);
    checkTrue('2026 is among the available calendar years', in_array(2026, $calYears, true));
    checkTrue('2027 is among the available calendar years (the Feb 2027 run)', in_array(2027, $calYears, true));

    /* ---------- Phase 4, T029: a DRAFT run's figures must never reach either new PIT report ---------- */
    echo "=== Phase 4, T029 (\"ทุก Report ใหม่ต้องเช็คเงื่อนไขอนุมัติ/ปิดรอบ\"): draft run excluded from both new methods ===\n";
    $draftCreate = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'AIS_DRAFT_RUN_' . uniqid(),
        'period_start_date' => '2026-06-01', 'period_end_date' => '2026-06-30', 'payment_date' => '2026-06-30',
    ], $userId, true);
    checkTrue('fixture: draft run (June 2026, inside FY2026) created' . (empty($draftCreate['status']) ? " ({$draftCreate['message']})" : ''), $draftCreate['status']);
    $draftRunId = $draftCreate['id'];
    $draftRecalc = $runModel->recalculate($draftRunId, $compId, $userId, true);
    checkTrue('fixture: draft run recalculated (still state=draft -- deliberately never submitted/approved)' . (empty($draftRecalc['status']) ? " ({$draftRecalc['message']})" : ''), $draftRecalc['status']);
    // Sanity check the fixture itself actually produced real, non-zero figures for this draft run --
    // otherwise "the draft run is excluded" would be trivially true for the wrong reason (nothing to
    // exclude), not because the ALLOWED_STATES gate is doing real work.
    $draftRealNet = $realNetFor($emp1, 2026, 6);
    checkTrue('fixture sanity: the draft run has a real, non-zero net figure to potentially leak', $draftRealNet > 0);

    $pitAfterDraft = $model->annualPitSummary($compId, 2026, 4, []);
    $pitEmp1AfterDraft = null;
    foreach ($pitAfterDraft['employees'] as $e) { if ($e['employee_id'] === $emp1) $pitEmp1AfterDraft = $e; }
    check('T029: annualPitSummary() June 2026 (index 2) tax_withheld stays 0 -- the draft run never contributes', (float)$pitEmp1AfterDraft['months'][2], 0.0);
    check('T029: annualPitSummary() annual_tax_withheld is UNCHANGED by the draft run existing', (float)$pitEmp1AfterDraft['annual_tax_withheld'], (float)$pitEmp1Row['annual_tax_withheld']);

    $monthlyJune = $model->monthlyPitDetail($compId, 2026, 6, []);
    check('T029: monthlyPitDetail() for June 2026 (the draft run\'s own month) returns ZERO employees -- the draft run\'s real data never surfaces', count($monthlyJune['employees']), 0);

    /* ---------- 2026-09-10, real bug regression: a run whose PERIOD spans two calendar months must
       bucket into the PAYMENT month, not the period-start month, across summary()/annualPitSummary()/
       monthlyPitDetail() alike. Direct reproduction of the reported bug's own example: period
       26/07-25/08, paid 31/08 -- must land in August (index 4 of FY2026, April-start), never July
       (index 3). Placed LAST (after T029's draft-run check) so this new run's own real contribution
       to the annual total doesn't perturb the "annual total unchanged by the draft run" comparison
       made just above, which was captured before this run existed. ---------- */
    echo "=== 2026-09-10 fix regression: cross-month-boundary run buckets by payment_date ===\n";
    makeAisRun($pdo, $runModel, $compId, $cycleId, $userId, '2026-07-26', '2026-08-25');
    // makeAisRun() itself sets payment_date = period_end_date (see its own definition above) --
    // period_end_date here is 2026-08-25, still August, so this alone wouldn't reproduce the bug.
    // Directly override payment_date to 2026-08-31 (the exact reported example) to genuinely put the
    // period-start month (July) and the payment month (August) in conflict.
    $pdo->prepare("UPDATE payroll_runs SET payment_date = '2026-08-31' WHERE comp_id = :comp_id AND period_start_date = '2026-07-26'")
        ->execute([':comp_id' => $compId]);
    // Independent reference values keyed by payment_date (NOT period_start_date, unlike
    // $realNetFor()/$realPitFor() above) -- this run's own period_start_date is still July, so
    // reusing those existing helpers here would silently look up the wrong month and defeat the
    // point of this regression check.
    $stmtRealNetByPayment = $pdo->prepare(
        "SELECT SUM(d.net_amount) FROM payroll_run_details d INNER JOIN payroll_runs r ON r.id = d.run_id
         WHERE d.employee_id = :emp AND r.comp_id = :comp AND YEAR(r.payment_date) = :y AND MONTH(r.payment_date) = :m"
    );
    $realNetAugust = (function () use ($stmtRealNetByPayment, $emp1, $compId): float {
        $stmtRealNetByPayment->execute([':emp' => $emp1, ':comp' => $compId, ':y' => 2026, ':m' => 8]);
        return (float)($stmtRealNetByPayment->fetchColumn() ?: 0);
    })();
    $stmtRealPitByPayment = $pdo->prepare(
        "SELECT d.statutory_breakdown FROM payroll_run_details d INNER JOIN payroll_runs r ON r.id = d.run_id
         WHERE d.employee_id = :emp AND r.comp_id = :comp AND YEAR(r.payment_date) = :y AND MONTH(r.payment_date) = :m"
    );
    $realPitAugust = (function () use ($stmtRealPitByPayment, $emp1, $compId): float {
        $stmtRealPitByPayment->execute([':emp' => $emp1, ':comp' => $compId, ':y' => 2026, ':m' => 8]);
        $raw = $stmtRealPitByPayment->fetchColumn();
        if ($raw === false) return 0.0;
        $pit = 0.0;
        foreach (json_decode((string)$raw, true) ?? [] as $item) {
            if (($item['code'] ?? null) === 'TH_PIT') { $pit += (float)($item['employee_amount'] ?? 0); }
        }
        return $pit;
    })();
    checkTrue('fixture sanity: the cross-month run has a real, non-zero net figure', $realNetAugust > 0);

    $crossResult = $model->summary($compId, 2026, 4, []);
    $crossEmp1Row = null;
    foreach ($crossResult['employees'] as $e) { if ($e['employee_id'] === $emp1) $crossEmp1Row = $e; }
    check('summary(): July (index 3) net stays 0 -- the run does NOT bucket into its period-start month', (float)$crossEmp1Row['months'][3]['net'], 0.0);
    checkTrue('summary(): August (index 4) net matches the run -- buckets by payment_date instead', abs((float)$crossEmp1Row['months'][4]['net'] - $realNetAugust) < 0.01);

    $crossPit = $model->annualPitSummary($compId, 2026, 4, []);
    $crossPitEmp1 = null;
    foreach ($crossPit['employees'] as $e) { if ($e['employee_id'] === $emp1) $crossPitEmp1 = $e; }
    check('annualPitSummary(): July (index 3) tax_withheld stays 0', (float)$crossPitEmp1['months'][3], 0.0);
    checkTrue('annualPitSummary(): August (index 4) tax_withheld matches the run', abs((float)$crossPitEmp1['months'][4] - $realPitAugust) < 0.01);

    $monthlyJuly = $model->monthlyPitDetail($compId, 2026, 7, []);
    check('monthlyPitDetail(July 2026): the cross-month run does NOT appear here', count($monthlyJuly['employees']), 0);
    $monthlyAugust = $model->monthlyPitDetail($compId, 2026, 8, []);
    $monthlyAugustEmp1 = null;
    foreach ($monthlyAugust['employees'] as $e) { if ($e['employee_id'] === $emp1) $monthlyAugustEmp1 = $e; }
    checkTrue('monthlyPitDetail(August 2026): the cross-month run correctly appears here instead', $monthlyAugustEmp1 !== null);

    /* ---------- 2026-09-12, Batch 5 item 5 step 2: "รอบเงินเดือน" (cycle_id) filter -- one shared
       runFilterClause() reused by summary()/annualPitSummary()/monthlyPitDetail()/cellDetail() alike.
       Re-cycles the cross-month August run (created just above) onto a SECOND payroll cycle via raw
       SQL (this is a filter-behavior test, not a run-creation test -- no need to go through a real
       create()/recalculate() cycle just to change which cycle a run belongs to), leaving every OTHER
       run (April/February/March, all still on the ORIGINAL $cycleId) untouched, so filtering by
       EITHER cycle id has a genuinely different, verifiable employee-1 row shape. ---------- */
    echo "=== 2026-09-12, Batch 5 item 5 step 2: cycle_id filter (\"รอบเงินเดือน\") ===\n";
    $cycleSave2 = $cycleModel->save($compId, [
        'cycle_name' => 'AIS_TEST_CYCLE2_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    checkTrue('fixture: second test cycle created' . (empty($cycleSave2['status']) ? " ({$cycleSave2['message']})" : ''), $cycleSave2['status']);
    $cycleId2 = $cycleSave2['id'];
    $pdo->prepare("UPDATE payroll_runs SET cycle_id = :cycle2 WHERE comp_id = :comp_id AND period_start_date = '2026-07-26'")
        ->execute([':cycle2' => $cycleId2, ':comp_id' => $compId]);

    $cycle1Result = $model->summary($compId, 2026, 4, ['cycle_id' => $cycleId]);
    $cycle1Emp1 = null;
    foreach ($cycle1Result['employees'] as $e) { if ($e['employee_id'] === $emp1) $cycle1Emp1 = $e; }
    check('summary(cycle_id=original): August (index 4) net is 0 -- that run moved to the OTHER cycle', (float)$cycle1Emp1['months'][4]['net'], 0.0);
    checkTrue('summary(cycle_id=original): April (index 0) net is UNCHANGED -- that run is still on this cycle', abs((float)$cycle1Emp1['months'][0]['net'] - $realNetApril) < 0.01);

    $cycle2Result = $model->summary($compId, 2026, 4, ['cycle_id' => $cycleId2]);
    $cycle2Emp1 = null;
    foreach ($cycle2Result['employees'] as $e) { if ($e['employee_id'] === $emp1) $cycle2Emp1 = $e; }
    check('summary(cycle_id=second): April (index 0) net is 0 -- that run is NOT on this cycle', (float)$cycle2Emp1['months'][0]['net'], 0.0);
    checkTrue('summary(cycle_id=second): August (index 4) net matches the re-cycled run', abs((float)$cycle2Emp1['months'][4]['net'] - $realNetAugust) < 0.01);

    // Company-wide month-state coloring (buildMonthMeta(), also filtered) must agree: April is
    // "past_done" for cycle 1 (a finalized run of ITS OWN exists that month) but August is not
    // (its own finalized run moved away) -- and vice versa for cycle 2.
    check('cycle_id=original: April month state is still past_done', $cycle1Result['months'][0]['state'], 'past_done');
    check('cycle_id=original: August month state is now past_missing (its run moved to the other cycle)', $cycle1Result['months'][4]['state'], 'past_missing');
    check('cycle_id=second: April month state is past_missing (no run of ITS OWN that month)', $cycle2Result['months'][0]['state'], 'past_missing');
    check('cycle_id=second: August month state is past_done (the re-cycled run)', $cycle2Result['months'][4]['state'], 'past_done');

    $cyclePit1 = $model->annualPitSummary($compId, 2026, 4, ['cycle_id' => $cycleId]);
    $cyclePit1Emp1 = null;
    foreach ($cyclePit1['employees'] as $e) { if ($e['employee_id'] === $emp1) $cyclePit1Emp1 = $e; }
    check('annualPitSummary(cycle_id=original): August (index 4) tax_withheld is 0', (float)$cyclePit1Emp1['months'][4], 0.0);
    $cyclePit2 = $model->annualPitSummary($compId, 2026, 4, ['cycle_id' => $cycleId2]);
    $cyclePit2Emp1 = null;
    foreach ($cyclePit2['employees'] as $e) { if ($e['employee_id'] === $emp1) $cyclePit2Emp1 = $e; }
    checkTrue('annualPitSummary(cycle_id=second): August (index 4) tax_withheld matches the re-cycled run', abs((float)$cyclePit2Emp1['months'][4] - $realPitAugust) < 0.01);

    $monthlyAugustCycle1 = $model->monthlyPitDetail($compId, 2026, 8, ['cycle_id' => $cycleId]);
    check('monthlyPitDetail(August 2026, cycle_id=original): zero employees -- the run is on the OTHER cycle', count($monthlyAugustCycle1['employees']), 0);
    $monthlyAugustCycle2 = $model->monthlyPitDetail($compId, 2026, 8, ['cycle_id' => $cycleId2]);
    checkTrue('monthlyPitDetail(August 2026, cycle_id=second): employee 1 appears', count(array_filter($monthlyAugustCycle2['employees'], fn($e) => $e['employee_id'] === $emp1)) === 1);

    $cellCycle1 = $model->cellDetail($compId, $emp1, 2026, 8, ['cycle_id' => $cycleId]);
    check('cellDetail(August 2026, cycle_id=original): zero runs -- the run is on the OTHER cycle', count($cellCycle1), 0);
    $cellCycle2 = $model->cellDetail($compId, $emp1, 2026, 8, ['cycle_id' => $cycleId2]);
    check('cellDetail(August 2026, cycle_id=second): exactly 1 run (the re-cycled one)', count($cellCycle2), 1);
    $cellNoFilter = $model->cellDetail($compId, $emp1, 2026, 8);
    check('cellDetail() with NO filters (default param) still works -- backward compatible', count($cellNoFilter), 1);

    /* ---------- 2026-09-12, Batch 5 item 5 step 2: profile_photo_path round-trips into every
       employee row (Employee column's avatar, apvAvatarHtml()/apvPersonLineHtml() in app.js). ---------- */
    echo "=== 2026-09-12, Batch 5 item 5 step 2: profile_photo_path passthrough ===\n";
    $photoPath = 'public/uploads/employee_photos/' . $compId . '/test_photo.jpg';
    $pdo->prepare("UPDATE employees SET profile_photo_path = :p WHERE id = :id")->execute([':p' => $photoPath, ':id' => $emp1]);
    $photoResult = $model->summary($compId, 2026, 4, []);
    $photoEmp1 = null;
    foreach ($photoResult['employees'] as $e) { if ($e['employee_id'] === $emp1) $photoEmp1 = $e; }
    check('summary(): profile_photo_path is carried through onto the employee row', $photoEmp1['profile_photo_path'] ?? null, $photoPath);
    $photoPitResult = $model->annualPitSummary($compId, 2026, 4, []);
    $photoPitEmp1 = null;
    foreach ($photoPitResult['employees'] as $e) { if ($e['employee_id'] === $emp1) $photoPitEmp1 = $e; }
    check('annualPitSummary(): profile_photo_path is carried through too', $photoPitEmp1['profile_photo_path'] ?? null, $photoPath);
    $photoMonthlyResult = $model->monthlyPitDetail($compId, 2026, 4, []);
    $photoMonthlyEmp1 = null;
    foreach ($photoMonthlyResult['employees'] as $e) { if ($e['employee_id'] === $emp1) $photoMonthlyEmp1 = $e; }
    check('monthlyPitDetail(): profile_photo_path is carried through too', $photoMonthlyEmp1['profile_photo_path'] ?? null, $photoPath);
    $emp3RowForPhoto = null;
    foreach ($photoResult['employees'] as $e) { if ($e['employee_id'] === $emp3NoData) $emp3RowForPhoto = $e; }
    // array_key_exists(), not ?? -- the key genuinely being PRESENT with a null value (no photo) is
    // the thing being verified here; ?? can't distinguish that from the key being absent entirely
    // (isset() semantics -- both look "falsy" to ??), which would make this assertion trivially pass
    // for the wrong reason (a genuinely missing key) just as easily as the right one.
    checkTrue('summary(): an employee with no photo still carries the profile_photo_path KEY (value null, not a missing key)', $emp3RowForPhoto !== null && array_key_exists('profile_photo_path', $emp3RowForPhoto));
    check('summary(): ...and that value is genuinely null, not some other falsy placeholder', $emp3RowForPhoto['profile_photo_path'], null);

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
