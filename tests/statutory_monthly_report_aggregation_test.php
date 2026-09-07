<?php
/**
 * Backlog Phase 10, T060, Step D -- real, currently-shipped gap: PndOneReport/Sso110Report (ภ.ง.ด.1/
 * สปส.1-10, real Thai MONTHLY government filings) only ever accepted `run_id` -- one payroll run =
 * one month's submission. Correct for a `payroll_frequency='monthly'` company (1 run genuinely IS
 * the whole month), but WRONG for an already-shipped non-monthly frequency (weekly/bi_weekly/
 * semi_monthly/daily): a weekly company has 4-5 separate settled runs per calendar month, and the
 * real monthly filing must sum every employee's earnings/withholding across ALL of them, not just
 * report one run's own fraction.
 *
 * Fix: both generate() methods now accept EITHER run_id (existing path, UNCHANGED) or year+month
 * (new path: PayrollReportDataModel::getRunsInMonth() + an accumulation loop keyed by employee_id,
 * direct structural mirror of PndOneKorSummaryReport's own year-aggregation loop, narrowed to a
 * month). Each run's own TH_PIT/TH_SSO employee_amount already correctly reflects ONLY that run's
 * own share of the period (T060 Step A's monthly-ceiling fix for SSO, ThPitCalculator's own
 * periodsPerYear-aware annualization for PIT) -- so summing across a month's settled runs already
 * produces the correct true monthly total, with zero new capping/tax logic needed here.
 *
 * Not PHPUnit. Runs against the real dev DB inside a transaction that is always rolled back. Uses
 * fresh throwaway companies, never comp_id=1.
 * Run with: php tests/statutory_monthly_report_aggregation_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/services/reports/ReportRegistry.php';
require_once __DIR__ . '/../app/services/reports/LocalizedException.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.005 : $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

function makeCompany(PDO $pdo): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', :tax, 'Test Address', 'Test Signatory', 'active', :comp_code)");
    $stmt->execute([':name' => 'ReportAgg Test Co ' . uniqid(), ':tax' => 'TAX' . uniqid(), ':comp_code' => 'RAGG_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeWeeklyCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'RAGG_WEEKLY_' . uniqid(), 'payroll_frequency' => 'weekly',
        'cutoff_day_of_week' => 'sunday', 'payment_day_of_week' => 'monday',
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a weekly cycle: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeMonthlyCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'RAGG_MONTHLY_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a monthly cycle: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeEmployee(PDO $pdo, int $compId, int $cycleId, string $salaryType, float $baseSalary): int {
    $idCardNo = (string)random_int(1000000000000, 9999999999999);
    $enc = EncryptionService::encrypt($idCardNo);
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         id_card_no, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'รวมรายงาน', 'Test', 'ReportAgg', '1990-01-01', 'Thai',
         :id_card_no, :key_version,
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2018-01-01', 'permanent', 'full_time', 'office', 'manual',
         :salary_type, :base_salary, '2018-01-01', 'average', 'active', 1, 0, 0, :cycle_id)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'RAGG_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':id_card_no' => $enc['value'], ':key_version' => $enc['key_version'],
        ':salary_type' => $salaryType, ':base_salary' => $baseSalary, ':cycle_id' => $cycleId,
    ]);
    return (int)$pdo->lastInsertId();
}

function runApprovedAndGetDetails(PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, string $periodStart, string $periodEnd, string $paymentDate): array {
    $res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'RAGG_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $userId, true);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
    }
    $runId = (int)$res['id'];
    $recalc = $runModel->recalculate($runId, $compId, $userId, true);
    if (empty($recalc['status'])) {
        throw new RuntimeException('recalculate() failed: ' . ($recalc['message'] ?? ''));
    }
    $submit = $runModel->submit($runId, $compId, $userId, true);
    if (empty($submit['status'])) {
        throw new RuntimeException('submit() failed: ' . ($submit['message'] ?? ''));
    }
    $approve = $runModel->approve($runId, $compId, $userId, true);
    if (empty($approve['status'])) {
        throw new RuntimeException('approve() failed: ' . ($approve['message'] ?? ''));
    }
    return $runModel->getDetails($runId, $compId);
}

function breakdownLine(array $details, int $employeeId, string $itemCode): ?array {
    $row = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeId));
    if ($row === false) return null;
    $breakdown = is_string($row['statutory_breakdown']) ? json_decode($row['statutory_breakdown'], true) : $row['statutory_breakdown'];
    foreach ((array)$breakdown as $item) {
        if (($item['code'] ?? null) === $itemCode) return $item;
    }
    return null;
}

/** Reads column F (index 5), row 2 of a freshly-generated Excel report's content -- both
 *  PndOneReport/Sso110Report put the summed monetary figure (tax_withheld / contribution) there
 *  for the report's single employee row. */
function readExcelCellF2(string $content): float {
    $tmp = sys_get_temp_dir() . '/ragg_test_' . uniqid() . '.xlsx';
    file_put_contents($tmp, $content);
    $sheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($tmp)->getActiveSheet();
    $val = (float)$sheet->getCell('F2')->getValue();
    unlink($tmp);
    return $val;
}

try {
    $userId = 1;
    $cycleModel = new PayrollCycleModel($pdo);
    $runModel = new PayrollRunModel($pdo);
    $pndReport = ReportRegistry::get('TH_PND1');
    $ssoReport = ReportRegistry::get('TH_SSO110');

    echo "=== Part 1: weekly frequency company, 4 sequential weekly runs, all in March 2026 ===\n";
    $compA = makeCompany($pdo);
    $weeklyCycleA = makeWeeklyCycle($cycleModel, $compA, $userId);
    // 20,000/week (same fixture salary T060 Step A's own test used) -- crosses the 15,000 THB SSO
    // monthly wage-base ceiling within week 1, so weeks 2-4 correctly contribute 0 SSO (Step A),
    // while PIT continues to apply on every week's own real gross (no ceiling on PIT).
    $empA = makeEmployee($pdo, $compA, $weeklyCycleA, 'weekly', 20000.0);

    $week1 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-01', '2026-03-07', '2026-03-09');
    $week2 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-08', '2026-03-14', '2026-03-16');
    $week3 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-15', '2026-03-21', '2026-03-23');
    $week4 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-22', '2026-03-28', '2026-03-30');

    $expectedPitSum = 0.0;
    $expectedSsoSum = 0.0;
    foreach ([$week1, $week2, $week3, $week4] as $wk) {
        $pitLine = breakdownLine($wk, $empA, 'TH_PIT');
        $ssoLine = breakdownLine($wk, $empA, 'TH_SSO');
        $expectedPitSum += (float)($pitLine['employee_amount'] ?? 0);
        $expectedSsoSum += (float)($ssoLine['employee_amount'] ?? 0);
    }
    checkTrue('fixture sanity: expected PIT sum across 4 weeks is a real positive number', $expectedPitSum > 0);
    checkTrue('fixture sanity: expected SSO sum across 4 weeks equals the full 750 ceiling (Step A), not 4x', abs($expectedSsoSum - 750.0) < 0.01);

    $yearBe = 2026 + 543;
    $pnd1Excel = $pndReport->generate(['comp_id' => $compA, 'year' => $yearBe, 'month' => 3], 'excel');
    $pnd1TaxWithheld = readExcelCellF2($pnd1Excel['content']);
    check('PND1 month-aggregated report: tax_withheld = SUM of all 4 weekly runs\' own TH_PIT (not just 1 week\'s worth)', $pnd1TaxWithheld, $expectedPitSum);

    $sso110Excel = $ssoReport->generate(['comp_id' => $compA, 'year' => $yearBe, 'month' => 3], 'excel');
    $sso110Contribution = readExcelCellF2($sso110Excel['content']);
    check('SSO110 month-aggregated report: contribution = SUM of all 4 weekly runs\' own TH_SSO (correctly 750.00, not 4x/3000.00)', $sso110Contribution, $expectedSsoSum);

    echo "\n=== Part 2: run_id path is byte-identical to before T060 Step D (monthly-frequency company, single run) ===\n";
    $compB = makeCompany($pdo);
    $monthlyCycleB = makeMonthlyCycle($cycleModel, $compB, $userId);
    $empB = makeEmployee($pdo, $compB, $monthlyCycleB, 'monthly', 50000.0);
    $monthB = runApprovedAndGetDetails($runModel, $compB, $monthlyCycleB, $userId, '2026-03-01', '2026-03-31', '2026-04-05');
    $runIdB = (int)current(array_filter($monthB, fn($d) => (int)$d['employee_id'] === $empB))['run_id'];
    $pitLineB = breakdownLine($monthB, $empB, 'TH_PIT');
    $ssoLineB = breakdownLine($monthB, $empB, 'TH_SSO');

    $pnd1RunIdExcel = $pndReport->generate(['comp_id' => $compB, 'run_id' => $runIdB], 'excel');
    check('PND1 run_id path (unchanged): tax_withheld matches this single run\'s own TH_PIT exactly', readExcelCellF2($pnd1RunIdExcel['content']), (float)$pitLineB['employee_amount']);

    $sso110RunIdExcel = $ssoReport->generate(['comp_id' => $compB, 'run_id' => $runIdB], 'excel');
    check('SSO110 run_id path (unchanged): contribution matches this single run\'s own TH_SSO exactly', readExcelCellF2($sso110RunIdExcel['content']), (float)$ssoLineB['employee_amount']);

    // Also prove the run_id path produces the SAME result as the month-scoped path would for this
    // SAME single-run month (both must agree, since 1 run in a monthly-frequency month IS the
    // whole month) -- confirms the 2 code paths aren't silently diverging.
    $pnd1MonthBExcel = $pndReport->generate(['comp_id' => $compB, 'year' => $yearBe, 'month' => 3], 'excel');
    check('PND1: run_id path and year+month path AGREE for a single-run monthly-frequency month', readExcelCellF2($pnd1MonthBExcel['content']), readExcelCellF2($pnd1RunIdExcel['content']));

    echo "\n=== Part 3: no settled runs in the requested month throws a clear error, not an empty report ===\n";
    $noDataThrown = false;
    $errorKey = null;
    try {
        $pndReport->generate(['comp_id' => $compA, 'year' => $yearBe, 'month' => 6], 'excel'); // June: no runs exist there
    } catch (LocalizedException $e) {
        $noDataThrown = true;
        $errorKey = $e->getErrorKey();
    }
    checkTrue('PND1: a month with zero settled runs throws LocalizedException, not an empty/silent report', $noDataThrown);
    check('PND1: the error key clearly identifies "no runs in state for month"', $errorKey, 'no_runs_in_state_for_month');

    $neitherContextThrown = false;
    try {
        $pndReport->generate(['comp_id' => $compA], 'excel'); // no run_id AND no year/month
    } catch (LocalizedException $e) {
        $neitherContextThrown = true;
    }
    checkTrue('PND1: neither run_id nor year+month present throws a clear error', $neitherContextThrown);

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
