<?php
/**
 * Backlog Phase 10, T060, Step A -- real, currently-shipped bug fix: StatutoryCalculationEngine's
 * flat_rate items (TH_SSO/TH_PVD) apply their max_base_amount/max_employee_contribution/
 * max_employer_contribution ceilings (real Thai monthly figures -- 15,000 THB SSO wage base /
 * 750 THB SSO contribution) PER RUN, with zero awareness that a non-monthly payroll_frequency
 * (already shipped: weekly/semi_monthly/bi_weekly, see project_ot_rate_set_polish_and_
 * salary_type_expansion_2026_08_31 memory) produces MULTIPLE settled runs within the same
 * calendar month for the same employee. Confirmed via direct dev-DB query that zero companies
 * currently use a non-monthly frequency, so this is a live-but-unexercised defect, not something
 * that has caused real financial harm yet -- but it WOULD 4-5x over-withhold SSO/PVD the moment
 * anyone turns these already-shipped frequencies on with statutory items enabled.
 *
 * Fix mirrors ThPitCalculator's own YTD-accumulation pattern (see that class's
 * ytdGrossPriorToThisPeriod()/ytdPitWithheldPriorToThisPeriod()) but scoped to a CALENDAR MONTH
 * instead of a YEAR: StatutoryCalculationEngine::monthlyUsagePriorToThisPeriod() sums a flat_rate
 * item's base_amount/employee_amount/employer_amount, as actually recorded, across every SETTLED
 * run (approved/paid/locked) for the same employee whose period_start_date falls in the SAME
 * calendar month, strictly before the current period. computeFlatRate() then treats
 * max_base_amount/max_employee_contribution/max_employer_contribution as "the ceiling minus
 * whatever was already used this month" instead of a flat per-run cap.
 *
 * Deliberately requires NO explicit payroll_frequency branch anywhere: for the pre-existing
 * MONTHLY case, there is never a second settled run for the same employee in the same calendar
 * month by construction, so the accumulation query naturally returns zero prior usage and the fix
 * is a mathematical no-op -- confirmed explicitly by Part 3 below, and implicitly by the full
 * project regression suite (850 pre-existing assertions in tests/payroll_run_test.php, all
 * monthly-frequency fixtures) staying green with zero changes after this fix.
 *
 * Part 4 covers a SEPARATE, unrelated bug found and fixed in the same edit (PayrollRunModel.php's
 * one call site to the engine was passing a literal `[]` for $employeeRateOverrides even though
 * the real per-employee TH_SSO rate override, employees.sso_contribution_rate/
 * sso_employer_contribution_rate (2026-09-02), was already computed a few lines above and simply
 * never threaded through -- silently dead since it shipped).
 *
 * Not PHPUnit. Runs against the real dev DB inside a transaction that is always rolled back. Uses
 * fresh throwaway companies, never comp_id=1.
 * Run with: php tests/statutory_monthly_ceiling_test.php
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
    $stmt->execute([':name' => 'Ceiling Test Co ' . uniqid(), ':tax' => 'TAX' . uniqid(), ':comp_code' => 'CEIL_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeWeeklyCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'CEIL_WEEKLY_' . uniqid(), 'payroll_frequency' => 'weekly',
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
        'cycle_name' => 'CEIL_MONTHLY_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a monthly cycle: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeEmployee(PDO $pdo, int $compId, int $cycleId, string $salaryType, float $baseSalary, bool $ssoEnrolled): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'เพดาน', 'Test', 'Ceiling', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2018-01-01', 'permanent', 'full_time', 'office', 'manual',
         :salary_type, :base_salary, '2018-01-01', 'average', 'active', :sso, 0, 1, :cycle_id)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'CEIL_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':salary_type' => $salaryType, ':base_salary' => $baseSalary, ':sso' => $ssoEnrolled ? 1 : 0, ':cycle_id' => $cycleId,
    ]);
    return (int)$pdo->lastInsertId();
}

/** Creates, recalculates, submits, AND approves a run so it counts as "settled" for the next
 *  run's own monthly-accumulation query -- unlike tests/statutory_master_clone_payroll_run_test.php's
 *  own runAndGetDetails() helper, which deliberately leaves runs in draft (not needed there). */
function runApprovedAndGetDetails(PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, string $periodStart, string $periodEnd, string $paymentDate): array {
    $res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'CEIL_RUN_' . uniqid(),
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

try {
    $userId = 1;
    $cycleModel = new PayrollCycleModel($pdo);
    $runModel = new PayrollRunModel($pdo);

    $compA = makeCompany($pdo);
    $weeklyCycleA = makeWeeklyCycle($cycleModel, $compA, $userId);

    echo "=== Part 1: weekly frequency, employee A -- 4 sequential weekly runs, all in March 2026 ===\n";
    // salary_type='weekly' with base_salary_amount=20000 means each 7-day period's own gross is a
    // clean, predictable 20,000 THB (the divisor/payableDays math cancels out to exactly the
    // configured amount for a full, unattenuated week -- confirmed via the existing salary_type
    // expansion's own tests). Comfortably above the 15,000 THB SSO ceiling every single week, so
    // by week 2 the monthly ceiling should already be fully consumed.
    $empA = makeEmployee($pdo, $compA, $weeklyCycleA, 'weekly', 20000.0, true);

    $week1 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-01', '2026-03-07', '2026-03-09');
    $ssoWeek1 = breakdownLine($week1, $empA, 'TH_SSO');
    checkTrue('week 1: TH_SSO line appears', $ssoWeek1 !== null);
    if ($ssoWeek1 !== null) {
        check('week 1: base_amount capped at the FULL 15,000 ceiling (nothing prior this month)', (float)$ssoWeek1['base_amount'], 15000.0);
        check('week 1: employee_amount = 15,000 * 5% = 750.00 (the full monthly cap, first run of the month)', (float)$ssoWeek1['employee_amount'], 750.0);
    }

    $week2 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-08', '2026-03-14', '2026-03-16');
    $ssoWeek2 = breakdownLine($week2, $empA, 'TH_SSO');
    checkTrue('week 2: TH_SSO line appears', $ssoWeek2 !== null);
    if ($ssoWeek2 !== null) {
        check('week 2: base_amount = 0.00 (the FULL monthly base was already consumed by week 1)', (float)$ssoWeek2['base_amount'], 0.0);
        check('week 2: employee_amount = 0.00 (monthly ceiling already exhausted -- THIS IS THE BUG FIX, was ~750 again before)', (float)$ssoWeek2['employee_amount'], 0.0);
    }

    $week3 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-15', '2026-03-21', '2026-03-23');
    $ssoWeek3 = breakdownLine($week3, $empA, 'TH_SSO');
    checkTrue('week 3: TH_SSO line appears', $ssoWeek3 !== null);
    if ($ssoWeek3 !== null) {
        check('week 3: employee_amount = 0.00 (still exhausted this month)', (float)$ssoWeek3['employee_amount'], 0.0);
    }

    $week4 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-22', '2026-03-28', '2026-03-30');
    $ssoWeek4 = breakdownLine($week4, $empA, 'TH_SSO');
    checkTrue('week 4: TH_SSO line appears', $ssoWeek4 !== null);
    if ($ssoWeek4 !== null) {
        check('week 4: employee_amount = 0.00 (still exhausted this month)', (float)$ssoWeek4['employee_amount'], 0.0);
    }

    $totalMarch = (float)($ssoWeek1['employee_amount'] ?? 0) + (float)($ssoWeek2['employee_amount'] ?? 0)
        + (float)($ssoWeek3['employee_amount'] ?? 0) + (float)($ssoWeek4['employee_amount'] ?? 0);
    check('THE CORE PROOF: 4 weekly runs summed = 750.00, matching what ONE monthly run against the SAME total monthly gross (80,000, capped at the 15,000 base) would have produced -- NOT 4x (3,000.00, the pre-fix bug)', $totalMarch, 750.0);

    echo "\n=== Part 2: a NEW calendar month (April) resets correctly, no bleed-over from March ===\n";
    $week5 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-04-05', '2026-04-11', '2026-04-13');
    $ssoWeek5 = breakdownLine($week5, $empA, 'TH_SSO');
    checkTrue('week 5 (April): TH_SSO line appears', $ssoWeek5 !== null);
    if ($ssoWeek5 !== null) {
        check('week 5 (April): base_amount = 15,000 again (fresh month, March\'s usage does not carry over)', (float)$ssoWeek5['base_amount'], 15000.0);
        check('week 5 (April): employee_amount = 750.00 again (fresh monthly ceiling)', (float)$ssoWeek5['employee_amount'], 750.0);
    }

    echo "\n=== Part 3: employee isolation -- employee B's own March week 1 is UNAFFECTED by employee A's exhausted ceiling ===\n";
    // A second weekly cycle for the SAME company -- a payroll_run can't share the exact same
    // (cycle_id, period_start_date, period_end_date) as an existing one, so employee B needs a
    // cycle of its own to run the SAME March 1-7 week employee A already used. This doesn't weaken
    // the isolation proof at all -- monthlyUsagePriorToThisPeriod() scopes by (comp_id,
    // employee_id) only, never cycle_id, so the real thing under test (does employee B's own calc
    // leak employee A's accumulated usage) is unaffected by which cycle each employee is on.
    $weeklyCycleA2 = makeWeeklyCycle($cycleModel, $compA, $userId);
    $empB = makeEmployee($pdo, $compA, $weeklyCycleA2, 'weekly', 20000.0, true);
    $week1B = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA2, $userId, '2026-03-01', '2026-03-07', '2026-03-09');
    $ssoWeek1B = breakdownLine($week1B, $empB, 'TH_SSO');
    checkTrue('employee B, week 1 (same March as employee A): TH_SSO line appears', $ssoWeek1B !== null);
    if ($ssoWeek1B !== null) {
        check('employee B is a DIFFERENT employee -- gets the FULL 750.00 ceiling, not 0.00 (no cross-employee leakage)', (float)$ssoWeek1B['employee_amount'], 750.0);
    }

    echo "\n=== Part 4 (separate real bug, found+fixed in the same edit): per-employee TH_SSO rate override was computed but never actually threaded into the engine ===\n";
    $compD = makeCompany($pdo);
    $monthlyCycleD = makeMonthlyCycle($cycleModel, $compD, $userId);
    $empD = makeEmployee($pdo, $compD, $monthlyCycleD, 'monthly', 50000.0, true);
    $pdo->prepare("UPDATE employees SET sso_contribution_rate = '3.00', sso_employer_contribution_rate = '3.00' WHERE id = :id")->execute([':id' => $empD]);
    $monthD = runApprovedAndGetDetails($runModel, $compD, $monthlyCycleD, $userId, '2026-03-01', '2026-03-31', '2026-04-05');
    $ssoD = breakdownLine($monthD, $empD, 'TH_SSO');
    checkTrue('employee D (own 3% SSO rate override): TH_SSO line appears', $ssoD !== null);
    if ($ssoD !== null) {
        // 50000 is still base-CAPPED to the 15,000 wage-base ceiling regardless of the rate
        // override (min/max_base clamping happens before the rate is applied) -- so the real
        // proof is 15,000 * 3% (the OWN override rate) = 450.00, NOT 15,000 * 5% (the default
        // rate) = 750.00. If the override were still dead, this would come back as 750.00 instead.
        check('employee_amount = 15,000 (base-capped) * 3% (the employee\'s OWN override rate) = 450.00, NOT the 5% default of 750.00 -- proves the override is genuinely applied now', (float)$ssoD['employee_amount'], 450.0);
    }

    echo "\n=== Part 5: sanity -- the pre-existing MONTHLY case is unaffected (no explicit frequency branch needed) ===\n";
    $compE = makeCompany($pdo);
    $monthlyCycleE = makeMonthlyCycle($cycleModel, $compE, $userId);
    $empE = makeEmployee($pdo, $compE, $monthlyCycleE, 'monthly', 50000.0, true);
    $monthE = runApprovedAndGetDetails($runModel, $compE, $monthlyCycleE, $userId, '2026-03-01', '2026-03-31', '2026-04-05');
    $ssoE = breakdownLine($monthE, $empE, 'TH_SSO');
    checkTrue('monthly-frequency employee: TH_SSO line appears', $ssoE !== null);
    if ($ssoE !== null) {
        check('monthly case: employee_amount = 15,000 * 5% = 750.00, exactly the same flat-cap result as before this fix (byte-identical, no prior-month usage possible by construction)', (float)$ssoE['employee_amount'], 750.0);
    }
    $monthE2 = runApprovedAndGetDetails($runModel, $compE, $monthlyCycleE, $userId, '2026-04-01', '2026-04-30', '2026-05-05');
    $ssoE2 = breakdownLine($monthE2, $empE, 'TH_SSO');
    checkTrue('monthly-frequency employee, a SECOND month later: TH_SSO line appears', $ssoE2 !== null);
    if ($ssoE2 !== null) {
        check('monthly case, month 2: STILL 750.00 (a different calendar month, and even the SAME month\'s own single prior run is the current one being calculated -- no double counting)', (float)$ssoE2['employee_amount'], 750.0);
    }

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
