<?php
/**
 * Batch 3A item 7a: PVD employer contribution ladder ("อายุงานตั้งแต่ (ปี) -> % นายจ้าง") +
 * employee-side/employer-side per-employee TH_PVD rate override, wired into
 * PayrollRunModel::recalculate() via the same $employeeRateOverrides channel TH_SSO already uses.
 *
 * Part 1: PvdEmployerRateLadderModel::save() validation (gap/overlap/first-row-not-0/
 *   more-than-one-open-ended/last-row-must-be-open-ended) + list()/clear() round-trip.
 * Part 2: resolveTier() tier-boundary correctness (hand-picked float years, isolated from date
 *   math) + serviceYears() date-math sanity.
 * Part 3: real end-to-end integration through PayrollRunModel::recalculate() -- confirms the full
 *   3-level resolution priority (employee override > ladder > default) actually reaches the
 *   TH_PVD breakdown's real employee_amount/employer_amount, that tenure is counted as of the
 *   RUN'S OWN period_end_date (not "today"), and that the resolved source/tier gets stamped onto
 *   the breakdown item for audit.
 *
 * Not PHPUnit. Runs against the real dev DB inside a transaction that is always rolled back. Uses
 * fresh throwaway companies, never comp_id=1.
 * Run with: php tests/pvd_employer_rate_ladder_test.php
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
require_once __DIR__ . '/../app/models/PvdEmployerRateLadderModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected, float $tolerance = 0.005): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < $tolerance : $actual === $expected;
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
    $stmt->execute([':name' => 'PVD Ladder Test Co ' . uniqid(), ':tax' => 'TAX' . uniqid(), ':comp_code' => 'PVDL_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeMonthlyCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'PVDL_MONTHLY_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a monthly cycle: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeEmployee(PDO $pdo, int $compId, int $cycleId, float $baseSalary, ?string $pvdStartDate, ?float $pvdEmployeeRate, ?float $pvdEmployerRate): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id, pvd_start_date, pvd_employee_rate, pvd_employer_rate)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'กองทุน', 'Test', 'Ladder', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2010-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2010-01-01', 'average', 'active', 0, 1, 0, :cycle_id, :pvd_start_date, :pvd_employee_rate, :pvd_employer_rate)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'PVDL_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':base_salary' => $baseSalary, ':cycle_id' => $cycleId,
        ':pvd_start_date' => $pvdStartDate, ':pvd_employee_rate' => $pvdEmployeeRate, ':pvd_employer_rate' => $pvdEmployerRate,
    ]);
    return (int)$pdo->lastInsertId();
}

function runApprovedAndGetDetails(PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, string $periodStart, string $periodEnd, string $paymentDate): array {
    $res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PVDL_RUN_' . uniqid(),
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

try {
    $userId = 1;
    $cycleModel = new PayrollCycleModel($pdo);
    $runModel = new PayrollRunModel($pdo);
    $ladderModel = new PvdEmployerRateLadderModel($pdo);

    echo "=== Part 1: PvdEmployerRateLadderModel::save() validation ===\n";
    $compV = makeCompany($pdo);

    $r1 = $ladderModel->save($compV, [], $userId);
    checkTrue('empty rows rejected', $r1['status'] === false);

    $r2 = $ladderModel->save($compV, [
        ['min_service_years' => 1, 'max_service_years' => 5, 'rate_percent' => 5],
    ], $userId);
    checkTrue('first row must start at 0 -- rejected', $r2['status'] === false);

    $r3 = $ladderModel->save($compV, [
        ['min_service_years' => 0, 'max_service_years' => 3, 'rate_percent' => 3],
        ['min_service_years' => 4, 'max_service_years' => null, 'rate_percent' => 5],
    ], $userId);
    checkTrue('gap between tiers (3 -> 4) rejected', $r3['status'] === false);

    $r4 = $ladderModel->save($compV, [
        ['min_service_years' => 0, 'max_service_years' => 5, 'rate_percent' => 3],
        ['min_service_years' => 3, 'max_service_years' => null, 'rate_percent' => 5],
    ], $userId);
    checkTrue('overlapping tiers (0-5 and 3-null) rejected', $r4['status'] === false);

    $r5 = $ladderModel->save($compV, [
        ['min_service_years' => 0, 'max_service_years' => null, 'rate_percent' => 3],
        ['min_service_years' => 3, 'max_service_years' => null, 'rate_percent' => 5],
    ], $userId);
    checkTrue('more than one open-ended tier rejected', $r5['status'] === false);

    $r6 = $ladderModel->save($compV, [
        ['min_service_years' => 0, 'max_service_years' => 3, 'rate_percent' => 3],
        ['min_service_years' => 3, 'max_service_years' => 6, 'rate_percent' => 5],
    ], $userId);
    checkTrue('last tier must be open-ended -- rejected when it is not', $r6['status'] === false);

    $r7 = $ladderModel->save($compV, [
        ['min_service_years' => 0, 'max_service_years' => 3, 'rate_percent' => 4],
        ['min_service_years' => 3, 'max_service_years' => 6, 'rate_percent' => 6],
        ['min_service_years' => 6, 'max_service_years' => null, 'rate_percent' => 8],
    ], $userId);
    checkTrue('valid 3-tier ladder saved', $r7['status'] === true);
    $listed = $ladderModel->list($compV);
    check('list() returns exactly 3 tiers', count($listed), 3);
    check('tier 1 rate', $listed[0]['rate_percent'], 4.0);
    check('tier 2 rate', $listed[1]['rate_percent'], 6.0);
    check('tier 3 rate', $listed[2]['rate_percent'], 8.0);
    checkTrue('tier 3 is open-ended (max null)', $listed[2]['max_service_years'] === null);

    $r8 = $ladderModel->save($compV, [
        ['min_service_years' => 0, 'max_service_years' => null, 'rate_percent' => 5],
    ], $userId);
    checkTrue('re-saving with a NEW valid (single-tier) set succeeds', $r8['status'] === true);
    $listed2 = $ladderModel->list($compV);
    check('list() reflects the REPLACED set, not a union with the old 3 tiers (old ones soft-deleted)', count($listed2), 1);

    $clearRes = $ladderModel->clear($compV, $userId);
    checkTrue('clear() succeeds', $clearRes['status'] === true);
    check('list() is empty after clear() (opt-out)', count($ladderModel->list($compV)), 0);

    echo "\n=== Part 2: resolveTier() boundary correctness + serviceYears() date math ===\n";
    $compR = makeCompany($pdo);
    $ladderModel->save($compR, [
        ['min_service_years' => 0, 'max_service_years' => 3, 'rate_percent' => 3],
        ['min_service_years' => 3, 'max_service_years' => 5, 'rate_percent' => 5],
        ['min_service_years' => 5, 'max_service_years' => null, 'rate_percent' => 7],
    ], $userId);

    check('years=0 -> tier 1 (3%)', $ladderModel->resolveTier($compR, 0.0)['rate_percent'], 3.0);
    check('years=2.99 -> tier 1 (3%)', $ladderModel->resolveTier($compR, 2.99)['rate_percent'], 3.0);
    check('years=3.0 (exact lower boundary) -> tier 2 (5%), min is inclusive', $ladderModel->resolveTier($compR, 3.0)['rate_percent'], 5.0);
    check('years=4.99 -> tier 2 (5%)', $ladderModel->resolveTier($compR, 4.99)['rate_percent'], 5.0);
    check('years=5.0 (exact lower boundary) -> tier 3 (7%), max is exclusive', $ladderModel->resolveTier($compR, 5.0)['rate_percent'], 7.0);
    check('years=100 (way past the last tier) -> tier 3 (7%), open-ended', $ladderModel->resolveTier($compR, 100.0)['rate_percent'], 7.0);

    $compNoLadder = makeCompany($pdo);
    checkTrue('resolveTier() returns null when the company has zero active rows (opt-in)', $ladderModel->resolveTier($compNoLadder, 10.0) === null);

    $oneYear = PvdEmployerRateLadderModel::serviceYears('2020-01-01', '2021-01-01');
    check('serviceYears(): ~365 days apart is ~1.0 year', $oneYear, 1.0, 0.02);
    check('serviceYears(): same start/end date is 0.0', PvdEmployerRateLadderModel::serviceYears('2020-01-01', '2020-01-01'), 0.0);
    check('serviceYears(): asOf before start clamps to 0.0 (never negative)', PvdEmployerRateLadderModel::serviceYears('2020-01-01', '2019-01-01'), 0.0);

    echo "\n=== Part 3: real end-to-end integration through PayrollRunModel::recalculate() ===\n";

    echo "-- 3a: no override, no ladder -- unchanged default TH_PVD rate (3%/3%) --\n";
    $compDefault = makeCompany($pdo);
    $cycleDefault = makeMonthlyCycle($cycleModel, $compDefault, $userId);
    $empDefault = makeEmployee($pdo, $compDefault, $cycleDefault, 50000.0, null, null, null);
    $detailsDefault = runApprovedAndGetDetails($runModel, $compDefault, $cycleDefault, $userId, '2026-03-01', '2026-03-31', '2026-04-05');
    $pvdDefault = breakdownLine($detailsDefault, $empDefault, 'TH_PVD');
    checkTrue('3a: TH_PVD line appears', $pvdDefault !== null);
    if ($pvdDefault !== null) {
        check('3a: employer_amount = 50,000 * 3% (unchanged master default) = 1,500.00', (float)$pvdDefault['employer_amount'], 1500.0);
        check('3a: employer_rate_source stamped as "default"', $pvdDefault['employer_rate_source'] ?? null, 'default');
    }

    echo "-- 3b: employee-side per-employee override (pvd_employee_rate) reaches TH_PVD's real employee_amount --\n";
    $compEmpOv = makeCompany($pdo);
    $cycleEmpOv = makeMonthlyCycle($cycleModel, $compEmpOv, $userId);
    $empEmpOv = makeEmployee($pdo, $compEmpOv, $cycleEmpOv, 50000.0, null, 2.5, null);
    $detailsEmpOv = runApprovedAndGetDetails($runModel, $compEmpOv, $cycleEmpOv, $userId, '2026-03-01', '2026-03-31', '2026-04-05');
    $pvdEmpOv = breakdownLine($detailsEmpOv, $empEmpOv, 'TH_PVD');
    checkTrue('3b: TH_PVD line appears', $pvdEmpOv !== null);
    if ($pvdEmpOv !== null) {
        check('3b: employee_amount = 50,000 * 2.5% (employee\'s own override) = 1,250.00, NOT the 3% default (750... i.e. 1,500)', (float)$pvdEmpOv['employee_amount'], 1250.0);
        check('3b: employer side untouched by the employee-side override, still 3% default = 1,500.00', (float)$pvdEmpOv['employer_amount'], 1500.0);
    }

    echo "-- 3c: employer-side ladder tier applies, tenure counted as of the RUN's period_end_date (not \"today\") --\n";
    // TH_PVD's own master rate only became effective 2026-01-01 (confirmed via direct DB query
    // before writing this) -- the run's own period/payment date must be on/after that for the
    // engine to find any rate row at all, so this can't reach as far into the past as a synthetic
    // date would ideally want. Still comfortably far enough before "today" (this test runs well
    // after 2026-09-10) to prove the point: period_end_date=2026-01-31, ~7-8 months before "today".
    $compLadder = makeCompany($pdo);
    $cycleLadder = makeMonthlyCycle($cycleModel, $compLadder, $userId);
    $ladderModel->save($compLadder, [
        ['min_service_years' => 0, 'max_service_years' => 5, 'rate_percent' => 4],
        ['min_service_years' => 5, 'max_service_years' => 7, 'rate_percent' => 6],
        ['min_service_years' => 7, 'max_service_years' => null, 'rate_percent' => 8],
    ], $userId);
    // pvd_start_date = 2021-05-20 -- tenure AT THE RUN'S OWN period_end_date (2026-01-31) is ~4.70
    // years, landing in tier 1 (0-5 yrs, 4%). Tenure AT TODAY (this test executing after
    // 2026-09-10) is ~5.31+ years and only grows from there, landing in tier 2 (5-7 yrs, 6%) or
    // later. If the code wrongly used "today" instead of period_end_date, this assertion would
    // fail with 6%+ (2,500.00+) instead of the correct 4% (2,000.00) -- this IS the proof
    // period_end_date is what's actually used, not whenever the calculation happens to run.
    $empLadder = makeEmployee($pdo, $compLadder, $cycleLadder, 50000.0, '2021-05-20', null, null);
    $detailsLadder = runApprovedAndGetDetails($runModel, $compLadder, $cycleLadder, $userId, '2026-01-01', '2026-01-31', '2026-02-05');
    $pvdLadder = breakdownLine($detailsLadder, $empLadder, 'TH_PVD');
    checkTrue('3c: TH_PVD line appears', $pvdLadder !== null);
    if ($pvdLadder !== null) {
        check('3c: employer_amount = 50,000 * 4% (tier 1, tenure AS OF period_end_date, ~4.7 yrs) = 2,000.00 -- proves period_end_date (not today) drives the tier', (float)$pvdLadder['employer_amount'], 2000.0);
        check('3c: employer_rate_source stamped as "ladder"', $pvdLadder['employer_rate_source'] ?? null, 'ladder');
        check('3c: employer_rate_tier_min_years stamped', (float)($pvdLadder['employer_rate_tier_min_years'] ?? -1), 0.0);
        check('3c: employer_rate_tier_max_years stamped', (float)($pvdLadder['employer_rate_tier_max_years'] ?? -1), 5.0);
    }

    echo "-- 3d: per-employee employer override wins over the ladder outright --\n";
    // A second cycle for the SAME (ladder-configured) company -- a payroll_run can't share the exact
    // same (cycle_id, period_start_date, period_end_date) as 3c's already-created run, same reason
    // tests/statutory_monthly_ceiling_test.php's own employee-isolation part needed a second cycle.
    // Doesn't weaken the proof: the ladder is scoped by comp_id, not cycle_id.
    $cycleLadder2 = makeMonthlyCycle($cycleModel, $compLadder, $userId);
    $empOverrideWinsLadder = makeEmployee($pdo, $compLadder, $cycleLadder2, 50000.0, '2021-05-20', null, 9.0);
    $detailsOverrideWins = runApprovedAndGetDetails($runModel, $compLadder, $cycleLadder2, $userId, '2026-01-01', '2026-01-31', '2026-02-05');
    $pvdOverrideWins = breakdownLine($detailsOverrideWins, $empOverrideWinsLadder, 'TH_PVD');
    checkTrue('3d: TH_PVD line appears', $pvdOverrideWins !== null);
    if ($pvdOverrideWins !== null) {
        check('3d: employer_amount = 50,000 * 9% (employee\'s OWN override) = 4,500.00, NOT the ladder\'s 4% (2,000.00) -- override wins outright', (float)$pvdOverrideWins['employer_amount'], 4500.0);
        check('3d: employer_rate_source stamped as "employee_override" even though a ladder tier would also match', $pvdOverrideWins['employer_rate_source'] ?? null, 'employee_override');
    }

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
