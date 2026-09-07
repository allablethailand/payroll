<?php
/**
 * Backlog Phase 10, T060, Step C follow-up -- the same real, currently-shipped duplicate-payment
 * bug found (and fixed, see tests/recurring_earning_monthly_duplicate_test.php) on the EARNING side
 * also existed on the DEDUCTION side: EmployeeRecurringDeductionModel::activeForPeriod() (a monthly-
 * cadence recurring deduction, e.g. a flat insurance-premium withholding) had zero awareness of
 * whether it had ALREADY been withheld earlier the SAME calendar month by a different run -- a
 * weekly-frequency company would deduct a "monthly" fee 4-5x over. Confirmed and fixed the same day,
 * user explicitly asked to fix this companion bug immediately rather than defer it.
 *
 * Fix is a direct structural mirror of EmployeeRecurringEarningModel's own fix (itself mirroring
 * T060 Step A's StatutoryCalculationEngine::monthlyUsagePriorToThisPeriod()) -- same settled-run
 * states, same calendar-month/strictly-before-this-period boundary, same plain-exclusion semantics
 * (a recurring monthly deduction either gets applied once that month or it doesn't -- no partial/
 * remaining-capacity concept). See EmployeeRecurringDeductionModel::alreadyPaidThisMonth()/
 * activeForPeriod()'s own docblocks.
 *
 * Deliberately requires NO explicit payroll_frequency branch anywhere: for the pre-existing MONTHLY
 * case, there is never a second settled run for the same employee in the same calendar month by
 * construction, so the accumulation query naturally returns an empty exclusion set and the fix is a
 * no-op -- confirmed explicitly by Part 3 below.
 *
 * Not PHPUnit. Runs against the real dev DB inside a transaction that is always rolled back. Uses
 * fresh throwaway companies, never comp_id=1.
 * Run with: php tests/recurring_deduction_monthly_duplicate_test.php
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
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringDeductionModel.php';

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
    $stmt->execute([':name' => 'RecurDedDup Test Co ' . uniqid(), ':tax' => 'TAX' . uniqid(), ':comp_code' => 'RDDUP_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeWeeklyCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'RDDUP_WEEKLY_' . uniqid(), 'payroll_frequency' => 'weekly',
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
        'cycle_name' => 'RDDUP_MONTHLY_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a monthly cycle: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeEmployee(PDO $pdo, int $compId, int $cycleId, string $salaryType, float $baseSalary): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'ซ้ำหัก', 'Test', 'DedDup', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2018-01-01', 'permanent', 'full_time', 'office', 'manual',
         :salary_type, :base_salary, '2018-01-01', 'average', 'active', 0, 0, 1, :cycle_id)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'RDDUP_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':salary_type' => $salaryType, ':base_salary' => $baseSalary, ':cycle_id' => $cycleId,
    ]);
    return (int)$pdo->lastInsertId();
}

function makeInsuranceDeductionType(PayrollEarningDeductionTypeModel $pedModel, int $compId, int $userId): int {
    $res = $pedModel->save($compId, [
        'item_code' => 'RDDUP' . substr((string)uniqid('', true), -6),
        'item_name_th' => 'หักประกันทดสอบ', 'item_name_en' => 'Test Insurance Deduction',
        'item_type' => 'deduction', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 0,
        'tax_deduction_impact' => 'after_tax',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a PED type: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeRecurringDeduction(EmployeeRecurringDeductionModel $recModel, int $employeeId, int $compId, int $pedTypeId, float $amount): int {
    $res = $recModel->save($employeeId, $compId, [
        'ped_type_id' => $pedTypeId, 'amount' => $amount, 'effective_date' => '2026-01-01',
    ], 1);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a recurring deduction: ' . $res['message']);
    }
    return (int)$res['id'];
}

/** Creates, recalculates, submits, AND approves a run so it counts as "settled" for the next run's
 *  own monthly-accumulation query. */
function runApprovedAndGetDetails(PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, string $periodStart, string $periodEnd, string $paymentDate): array {
    $res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'RDDUP_RUN_' . uniqid(),
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

/** Finds this employee's own recurring_deduction line in a run's deduction_breakdown. Each test
 *  employee here has exactly one recurring deduction configured, so matching on source alone
 *  (ignoring item_code, which is a dynamic uniqid per fixture) is sufficient and simpler. */
function recurringDeductionLine(array $details, int $employeeId): ?array {
    $row = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeId));
    if ($row === false) return null;
    $breakdown = is_string($row['deduction_breakdown']) ? json_decode($row['deduction_breakdown'], true) : $row['deduction_breakdown'];
    foreach ((array)$breakdown as $item) {
        if (($item['source'] ?? null) === 'recurring_deduction') return $item;
    }
    return null;
}

try {
    $userId = 1;
    $cycleModel = new PayrollCycleModel($pdo);
    $runModel = new PayrollRunModel($pdo);
    $pedModel = new PayrollEarningDeductionTypeModel($pdo);
    $recModel = new EmployeeRecurringDeductionModel($pdo);

    $compA = makeCompany($pdo);
    $weeklyCycleA = makeWeeklyCycle($cycleModel, $compA, $userId);
    $pedTypeA = makeInsuranceDeductionType($pedModel, $compA, $userId);
    $empA = makeEmployee($pdo, $compA, $weeklyCycleA, 'weekly', 20000.0);
    makeRecurringDeduction($recModel, $empA, $compA, $pedTypeA, 500.0);

    echo "=== Part 1: weekly frequency, employee A -- 4 sequential weekly runs, all in March 2026 ===\n";
    $week1 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-01', '2026-03-07', '2026-03-09');
    $lineWeek1 = recurringDeductionLine($week1, $empA);
    checkTrue('week 1: recurring deduction line appears', $lineWeek1 !== null);
    if ($lineWeek1 !== null) {
        check('week 1: amount = 500.00 (first run of the month, applied in full)', (float)$lineWeek1['amount'], 500.0);
    }

    $week2 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-08', '2026-03-14', '2026-03-16');
    $lineWeek2 = recurringDeductionLine($week2, $empA);
    checkTrue('week 2: recurring deduction line is ABSENT (THIS IS THE BUG FIX -- was duplicated to 500.00 again before)', $lineWeek2 === null);

    $week3 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-15', '2026-03-21', '2026-03-23');
    $lineWeek3 = recurringDeductionLine($week3, $empA);
    checkTrue('week 3: recurring deduction line still ABSENT (already applied this month)', $lineWeek3 === null);

    $week4 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-22', '2026-03-28', '2026-03-30');
    $lineWeek4 = recurringDeductionLine($week4, $empA);
    checkTrue('week 4: recurring deduction line still ABSENT (already applied this month)', $lineWeek4 === null);

    $totalMarch = (float)($lineWeek1['amount'] ?? 0) + (float)($lineWeek2['amount'] ?? 0) + (float)($lineWeek3['amount'] ?? 0) + (float)($lineWeek4['amount'] ?? 0);
    check('THE CORE PROOF: 4 weekly runs summed = 500.00 (applied ONCE this month), NOT 2000.00 (the pre-fix 4x duplication bug)', $totalMarch, 500.0);

    echo "\n=== Part 2: a NEW calendar month (April) applies the deduction again, fresh ===\n";
    $week5 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-04-05', '2026-04-11', '2026-04-13');
    $lineWeek5 = recurringDeductionLine($week5, $empA);
    checkTrue('week 5 (April): recurring deduction line appears again (new month, not blocked by March)', $lineWeek5 !== null);
    if ($lineWeek5 !== null) {
        check('week 5 (April): amount = 500.00 again (fresh month)', (float)$lineWeek5['amount'], 500.0);
    }

    echo "\n=== Part 3: sanity -- the pre-existing MONTHLY case is unaffected (no explicit frequency branch needed) ===\n";
    $compE = makeCompany($pdo);
    $monthlyCycleE = makeMonthlyCycle($cycleModel, $compE, $userId);
    $pedTypeE = makeInsuranceDeductionType($pedModel, $compE, $userId);
    $empE = makeEmployee($pdo, $compE, $monthlyCycleE, 'monthly', 50000.0);
    makeRecurringDeduction($recModel, $empE, $compE, $pedTypeE, 500.0);
    $monthE = runApprovedAndGetDetails($runModel, $compE, $monthlyCycleE, $userId, '2026-03-01', '2026-03-31', '2026-04-05');
    $lineE = recurringDeductionLine($monthE, $empE);
    checkTrue('monthly-frequency employee: recurring deduction line appears', $lineE !== null);
    if ($lineE !== null) {
        check('monthly case: amount = 500.00, exactly as before this fix (byte-identical, no prior-month usage possible by construction)', (float)$lineE['amount'], 500.0);
    }
    $monthE2 = runApprovedAndGetDetails($runModel, $compE, $monthlyCycleE, $userId, '2026-04-01', '2026-04-30', '2026-05-05');
    $lineE2 = recurringDeductionLine($monthE2, $empE);
    checkTrue('monthly-frequency employee, a SECOND month later: recurring deduction line appears', $lineE2 !== null);
    if ($lineE2 !== null) {
        check('monthly case, month 2: STILL 500.00 (a different calendar month, correctly not blocked)', (float)$lineE2['amount'], 500.0);
    }

    echo "\n=== Part 4: employee isolation -- employee B's own recurring deduction is unaffected by employee A's exhausted-this-month state ===\n";
    $weeklyCycleA2 = makeWeeklyCycle($cycleModel, $compA, $userId);
    $pedTypeB = makeInsuranceDeductionType($pedModel, $compA, $userId);
    $empB = makeEmployee($pdo, $compA, $weeklyCycleA2, 'weekly', 20000.0);
    makeRecurringDeduction($recModel, $empB, $compA, $pedTypeB, 500.0);
    $week1B = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA2, $userId, '2026-03-01', '2026-03-07', '2026-03-09');
    $lineB = recurringDeductionLine($week1B, $empB);
    checkTrue('employee B, week 1 (same March as employee A): recurring deduction line appears', $lineB !== null);
    if ($lineB !== null) {
        check('employee B is a DIFFERENT employee -- gets the FULL 500.00, not blocked by employee A\'s own already-applied state', (float)$lineB['amount'], 500.0);
    }

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
