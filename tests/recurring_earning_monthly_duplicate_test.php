<?php
/**
 * Backlog Phase 10, T060, Step C -- real, currently-shipped bug fix: a monthly-cadence Recurring
 * Earning (e.g. Position/Fuel/Housing Allowance -- EmployeeRecurringEarningModel, see that class's
 * own docblock: "รายรับที่ได้ทุกเดือน...ให้เพิ่มส่วนนี้เข้าไปด้วย") was included, in FULL, in EVERY
 * payroll run whose period fell within its effective/non-suspended window -- with zero awareness
 * of whether it had ALREADY been paid earlier the SAME calendar month by a different run. For a
 * `payroll_cycles.payroll_frequency='monthly'` company this was invisible (only ever 1 run per
 * employee per month, so "already paid this month" can never be true) -- but for an already-shipped
 * non-monthly frequency (weekly/semi_monthly/bi_weekly, see project_ot_rate_set_polish_and_
 * salary_type_expansion_2026_08_31 memory), a "monthly" allowance would be paid out once per run,
 * e.g. 4-5x over for a weekly company, the moment anyone actually configures one.
 *
 * Fix mirrors T060 Step A's StatutoryCalculationEngine::monthlyUsagePriorToThisPeriod() pattern
 * exactly (same settled-run states, same calendar-month/strictly-before-this-period boundary), but
 * adapted from "how much of a ceiling was already consumed" (proration) to "was this exact item
 * already paid at all this month" (plain exclusion) -- see
 * EmployeeRecurringEarningModel::alreadyPaidThisMonth()/activeForPeriod()'s own docblocks.
 *
 * Deliberately requires NO explicit payroll_frequency branch anywhere: for the pre-existing MONTHLY
 * case, there is never a second settled run for the same employee in the same calendar month by
 * construction, so the accumulation query naturally returns an empty exclusion set and the fix is a
 * no-op -- confirmed explicitly by Part 3 below.
 *
 * Not PHPUnit. Runs against the real dev DB inside a transaction that is always rolled back. Uses
 * fresh throwaway companies, never comp_id=1.
 * Run with: php tests/recurring_earning_monthly_duplicate_test.php
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
require_once __DIR__ . '/../app/models/EmployeeRecurringEarningModel.php';

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
    $stmt->execute([':name' => 'RecurDup Test Co ' . uniqid(), ':tax' => 'TAX' . uniqid(), ':comp_code' => 'RDUP_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeWeeklyCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'RDUP_WEEKLY_' . uniqid(), 'payroll_frequency' => 'weekly',
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
        'cycle_name' => 'RDUP_MONTHLY_' . uniqid(), 'payroll_frequency' => 'monthly',
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
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'ซ้ำ', 'Test', 'Dup', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2018-01-01', 'permanent', 'full_time', 'office', 'manual',
         :salary_type, :base_salary, '2018-01-01', 'average', 'active', 0, 0, 1, :cycle_id)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'RDUP_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':salary_type' => $salaryType, ':base_salary' => $baseSalary, ':cycle_id' => $cycleId,
    ]);
    return (int)$pdo->lastInsertId();
}

function makePositionAllowanceType(PayrollEarningDeductionTypeModel $pedModel, int $compId, int $userId): int {
    $res = $pedModel->save($compId, [
        'item_code' => 'RDUP' . substr((string)uniqid('', true), -6),
        'item_name_th' => 'ค่าตำแหน่งทดสอบ', 'item_name_en' => 'Test Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 0,
        'tax_treatment' => 'taxable',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a PED type: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeRecurringEarning(EmployeeRecurringEarningModel $recModel, int $employeeId, int $compId, int $pedTypeId, float $amount): int {
    $res = $recModel->save($employeeId, $compId, [
        'ped_type_id' => $pedTypeId, 'amount' => $amount, 'effective_date' => '2026-01-01',
    ], 1);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a recurring earning: ' . $res['message']);
    }
    return (int)$res['id'];
}

/** Creates, recalculates, submits, AND approves a run so it counts as "settled" for the next run's
 *  own monthly-accumulation query. */
function runApprovedAndGetDetails(PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, string $periodStart, string $periodEnd, string $paymentDate): array {
    $res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'RDUP_RUN_' . uniqid(),
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

/** Finds this employee's own recurring_earning line in a run's earning_breakdown. Each test
 *  employee here has exactly one recurring earning configured, so matching on source alone
 *  (ignoring item_code, which is a dynamic uniqid per fixture) is sufficient and simpler. */
function recurringEarningLine(array $details, int $employeeId): ?array {
    $row = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeId));
    if ($row === false) return null;
    $breakdown = is_string($row['earning_breakdown']) ? json_decode($row['earning_breakdown'], true) : $row['earning_breakdown'];
    foreach ((array)$breakdown as $item) {
        if (($item['source'] ?? null) === 'recurring_earning') return $item;
    }
    return null;
}

try {
    $userId = 1;
    $cycleModel = new PayrollCycleModel($pdo);
    $runModel = new PayrollRunModel($pdo);
    $pedModel = new PayrollEarningDeductionTypeModel($pdo);
    $recModel = new EmployeeRecurringEarningModel($pdo);

    $compA = makeCompany($pdo);
    $weeklyCycleA = makeWeeklyCycle($cycleModel, $compA, $userId);
    $pedTypeA = makePositionAllowanceType($pedModel, $compA, $userId);
    $empA = makeEmployee($pdo, $compA, $weeklyCycleA, 'weekly', 20000.0);
    makeRecurringEarning($recModel, $empA, $compA, $pedTypeA, 3000.0);

    echo "=== Part 1: weekly frequency, employee A -- 4 sequential weekly runs, all in March 2026 ===\n";
    $week1 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-01', '2026-03-07', '2026-03-09');
    $lineWeek1 = recurringEarningLine($week1, $empA);
    checkTrue('week 1: recurring earning line appears', $lineWeek1 !== null);
    if ($lineWeek1 !== null) {
        check('week 1: amount = 3000.00 (first run of the month, paid in full)', (float)$lineWeek1['amount'], 3000.0);
    }

    $week2 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-08', '2026-03-14', '2026-03-16');
    $lineWeek2 = recurringEarningLine($week2, $empA);
    checkTrue('week 2: recurring earning line is ABSENT (THIS IS THE BUG FIX -- was duplicated to 3000.00 again before)', $lineWeek2 === null);

    $week3 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-15', '2026-03-21', '2026-03-23');
    $lineWeek3 = recurringEarningLine($week3, $empA);
    checkTrue('week 3: recurring earning line still ABSENT (already paid this month)', $lineWeek3 === null);

    $week4 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-03-22', '2026-03-28', '2026-03-30');
    $lineWeek4 = recurringEarningLine($week4, $empA);
    checkTrue('week 4: recurring earning line still ABSENT (already paid this month)', $lineWeek4 === null);

    $totalMarch = (float)($lineWeek1['amount'] ?? 0) + (float)($lineWeek2['amount'] ?? 0) + (float)($lineWeek3['amount'] ?? 0) + (float)($lineWeek4['amount'] ?? 0);
    check('THE CORE PROOF: 4 weekly runs summed = 3000.00 (paid ONCE this month), NOT 12000.00 (the pre-fix 4x duplication bug)', $totalMarch, 3000.0);

    echo "\n=== Part 2: a NEW calendar month (April) pays the allowance again, fresh ===\n";
    $week5 = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA, $userId, '2026-04-05', '2026-04-11', '2026-04-13');
    $lineWeek5 = recurringEarningLine($week5, $empA);
    checkTrue('week 5 (April): recurring earning line appears again (new month, not blocked by March)', $lineWeek5 !== null);
    if ($lineWeek5 !== null) {
        check('week 5 (April): amount = 3000.00 again (fresh month)', (float)$lineWeek5['amount'], 3000.0);
    }

    echo "\n=== Part 3: sanity -- the pre-existing MONTHLY case is unaffected (no explicit frequency branch needed) ===\n";
    $compE = makeCompany($pdo);
    $monthlyCycleE = makeMonthlyCycle($cycleModel, $compE, $userId);
    $pedTypeE = makePositionAllowanceType($pedModel, $compE, $userId);
    $empE = makeEmployee($pdo, $compE, $monthlyCycleE, 'monthly', 50000.0);
    makeRecurringEarning($recModel, $empE, $compE, $pedTypeE, 3000.0);
    $monthE = runApprovedAndGetDetails($runModel, $compE, $monthlyCycleE, $userId, '2026-03-01', '2026-03-31', '2026-04-05');
    $lineE = recurringEarningLine($monthE, $empE);
    checkTrue('monthly-frequency employee: recurring earning line appears', $lineE !== null);
    if ($lineE !== null) {
        check('monthly case: amount = 3000.00, exactly as before this fix (byte-identical, no prior-month usage possible by construction)', (float)$lineE['amount'], 3000.0);
    }
    $monthE2 = runApprovedAndGetDetails($runModel, $compE, $monthlyCycleE, $userId, '2026-04-01', '2026-04-30', '2026-05-05');
    $lineE2 = recurringEarningLine($monthE2, $empE);
    checkTrue('monthly-frequency employee, a SECOND month later: recurring earning line appears', $lineE2 !== null);
    if ($lineE2 !== null) {
        check('monthly case, month 2: STILL 3000.00 (a different calendar month, correctly not blocked)', (float)$lineE2['amount'], 3000.0);
    }

    echo "\n=== Part 4: employee isolation -- employee B's own recurring earning is unaffected by employee A's exhausted-this-month state ===\n";
    $weeklyCycleA2 = makeWeeklyCycle($cycleModel, $compA, $userId);
    $pedTypeB = makePositionAllowanceType($pedModel, $compA, $userId);
    $empB = makeEmployee($pdo, $compA, $weeklyCycleA2, 'weekly', 20000.0);
    makeRecurringEarning($recModel, $empB, $compA, $pedTypeB, 3000.0);
    $week1B = runApprovedAndGetDetails($runModel, $compA, $weeklyCycleA2, $userId, '2026-03-01', '2026-03-07', '2026-03-09');
    $lineB = recurringEarningLine($week1B, $empB);
    checkTrue('employee B, week 1 (same March as employee A): recurring earning line appears', $lineB !== null);
    if ($lineB !== null) {
        check('employee B is a DIFFERENT employee -- gets the FULL 3000.00, not blocked by employee A\'s own already-paid state', (float)$lineB['amount'], 3000.0);
    }

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
