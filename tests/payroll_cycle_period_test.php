<?php
/**
 * Lightweight verification script for PayrollCycleModel::suggestNextPeriod() -- the "auto-fill
 * dates when a cycle is picked" feature. Not PHPUnit -- see tests/statutory_engine_test.php for
 * why. Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/payroll_cycle_period_test.php
 *
 * Deliberately exercises the PHP DateTime "+1 month" overflow pitfall (Jan 31 + 1 month = Mar 3,
 * not Feb 28) via month-end cutoffs/semi-monthly second-halves feeding into short months
 * (Feb) and 30-day months (Apr) -- this is exactly the class of bug that's easy to introduce
 * silently in this kind of date arithmetic.
 *
 * 2026-09-04, Backlog Phase 10, T060 Step B ("support daily/weekly/bi-weekly pay frequency") --
 * added: (1) 'daily' as a working payroll_frequency (period_start === period_end, no cutoff-day
 * concept applies at all -- see PayrollCycleModel::nextDailyPeriod()'s own docblock), (2) a
 * save()-level validation section that also proves a real, pre-existing, unrelated bug found and
 * fixed in the same edit -- save() only ever branched on `freq === 'weekly'` for the day-of-week
 * fields, so a 'bi_weekly' cycle could NEVER be saved with cutoff_day_of_week/payment_day_of_week
 * set (it silently required day-of-month fields instead), even though suggestNextPeriod() above
 * has always read bi_weekly's period math from cutoff_day_of_week -- the exact same bug also
 * existed in public/js/setup/payroll-configuration.js's applyFrequencyFields() (fixed alongside),
 * so a bi_weekly cycle's day-of-week fields never even appeared in the form to begin with. (3) a
 * real end-to-end proof through PayrollRunModel::recalculate() that a daily-frequency cycle
 * combined with a salary_type='daily' employee (2026-08-31, already-existing divisor) computes
 * the correct 1-day base salary with zero changes needed to PayrollRunModel.php itself.
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
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void {
    check($label, $actual, true);
}
function checkFalse(string $label, bool $actual): void {
    check($label, $actual, false);
}

try {
    $insComp = $pdo->prepare("INSERT INTO `companies`
        (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES ('Cycle Period Test Co.', 'Cycle Period Test Co.', 'TH', '0000000000000', 'Test Address', 'Test Signatory')");
    $insComp->execute();
    $compId = (int)$pdo->lastInsertId();

    $model = new PayrollCycleModel($pdo);

    function makeCycle(PDO $pdo, int $compId, array $overrides): int {
        $defaults = [
            'cycle_name' => 'Cycle_' . uniqid(), 'payroll_frequency' => 'monthly',
            'cutoff_day_of_month' => null, 'cutoff_use_last_day' => 0, 'cutoff_day_of_week' => null,
            'payment_day_of_month' => null, 'payment_use_last_day' => 0, 'payment_day_of_week' => null,
            'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
        ];
        $data = array_merge($defaults, $overrides);
        $stmt = $pdo->prepare("INSERT INTO payroll_cycles
            (comp_id, cycle_name, payroll_frequency, cutoff_day_of_month, cutoff_use_last_day, cutoff_day_of_week,
             payment_day_of_month, payment_use_last_day, payment_day_of_week, ot_cutoff_type, bank_file_format_id, status)
            VALUES (:comp_id, :cycle_name, :payroll_frequency, :cutoff_day_of_month, :cutoff_use_last_day, :cutoff_day_of_week,
             :payment_day_of_month, :payment_use_last_day, :payment_day_of_week, :ot_cutoff_type, :bank_file_format_id, :status)");
        $stmt->execute([
            ':comp_id' => $compId, ':cycle_name' => $data['cycle_name'], ':payroll_frequency' => $data['payroll_frequency'],
            ':cutoff_day_of_month' => $data['cutoff_day_of_month'], ':cutoff_use_last_day' => $data['cutoff_use_last_day'],
            ':cutoff_day_of_week' => $data['cutoff_day_of_week'],
            ':payment_day_of_month' => $data['payment_day_of_month'], ':payment_use_last_day' => $data['payment_use_last_day'],
            ':payment_day_of_week' => $data['payment_day_of_week'],
            ':ot_cutoff_type' => $data['ot_cutoff_type'], ':bank_file_format_id' => $data['bank_file_format_id'], ':status' => $data['status'],
        ]);
        return (int)$pdo->lastInsertId();
    }
    function makeFixtureRun(PDO $pdo, int $compId, int $cycleId, string $start, string $end, string $payDate): void {
        $stmt = $pdo->prepare("INSERT INTO payroll_runs (comp_id, cycle_id, run_name, period_start_date, period_end_date, payment_date)
            VALUES (:comp_id, :cycle_id, :run_name, :start, :end, :pay)");
        $stmt->execute([
            ':comp_id' => $compId, ':cycle_id' => $cycleId, ':run_name' => 'Fixture_' . uniqid(),
            ':start' => $start, ':end' => $end, ':pay' => $payDate,
        ]);
    }

    // ---------- Monthly, fixed cutoff day (25th) ----------
    echo "=== Monthly, cutoff_day_of_month=25, payment_day_of_month=5 ===\n";
    $cMonthly = makeCycle($pdo, $compId, ['cutoff_day_of_month' => 25, 'payment_day_of_month' => 5]);
    makeFixtureRun($pdo, $compId, $cMonthly, '2026-01-26', '2026-02-25', '2026-03-05');
    $res = $model->suggestNextPeriod($cMonthly, $compId);
    checkTrue('status true', $res['status']);
    check('continues right after the last period (start)', $res['period_start_date'] ?? null, '2026-02-26');
    check('one cutoff month forward (end)', $res['period_end_date'] ?? null, '2026-03-25');
    check('payment in the month AFTER period end, on payment_day', $res['payment_date'] ?? null, '2026-04-05');

    // ---------- Monthly, payment day AFTER cutoff day in the same month (2026-08-28, real bug
    // found and fixed -- explicit report against this exact real dev-DB cycle: cutoff 21, payment
    // 25, named "รอบวันที่ 25"). The section above (cutoff=25, payment=5) only ever exercised the
    // "payment day is NUMERICALLY LESS than cutoff day" case, where rolling into next month is
    // correct -- it never caught this bug because that case happened to already work. Here
    // payment_day_of_month (25) comes AFTER cutoff_day_of_month (21) within the same calendar
    // month, so the suggested payment date must stay in the SAME month the period just ended in,
    // not next month -- the old code's unconditional `modify('first day of next month')` got this
    // wrong (suggested 2026-09-25 for a period ending 2026-08-21, a full month off). See
    // PayrollCycleModel::resolvePaymentDate()'s own docblock for the fix. ----------
    echo "=== Monthly, cutoff_day_of_month=21, payment_day_of_month=25 (payment AFTER cutoff, same-month payment) ===\n";
    $cSameMonthPay = makeCycle($pdo, $compId, ['cutoff_day_of_month' => 21, 'payment_day_of_month' => 25]);
    makeFixtureRun($pdo, $compId, $cSameMonthPay, '2026-06-22', '2026-07-21', '2026-07-25');
    $res = $model->suggestNextPeriod($cSameMonthPay, $compId);
    checkTrue('status true', $res['status']);
    check('next period starts the day after the last one ended', $res['period_start_date'] ?? null, '2026-07-22');
    check('next period ends on this month\'s cutoff day', $res['period_end_date'] ?? null, '2026-08-21');
    check('payment stays in the SAME month as period end (not next month)', $res['payment_date'] ?? null, '2026-08-25');

    // ---------- Monthly, cutoff_use_last_day, crossing Mar(31) -> Apr(30) -- the exact
    // "+1 month" overflow trap (naive PHP: Mar 31 + 1 month = May 1, skipping April) ----------
    echo "=== Monthly, cutoff_use_last_day, Mar(31) -> Apr(30) overflow trap ===\n";
    $cLastDay = makeCycle($pdo, $compId, ['cutoff_use_last_day' => 1, 'payment_day_of_month' => 5]);
    makeFixtureRun($pdo, $compId, $cLastDay, '2026-03-01', '2026-03-31', '2026-04-05');
    $res = $model->suggestNextPeriod($cLastDay, $compId);
    checkTrue('status true', $res['status']);
    check('next period starts Apr 1 (not skipped to May)', $res['period_start_date'] ?? null, '2026-04-01');
    check('next period ends Apr 30 (last day of April, not overflowed)', $res['period_end_date'] ?? null, '2026-04-30');
    check('payment in May (month after April)', $res['payment_date'] ?? null, '2026-05-05');

    // ---------- Monthly, cutoff_use_last_day, crossing Jan(31) -> Feb(28) ----------
    echo "=== Monthly, cutoff_use_last_day, Jan(31) -> Feb(28) overflow trap ===\n";
    $pdo->prepare("DELETE FROM payroll_runs WHERE cycle_id = :id")->execute([':id' => $cLastDay]);
    makeFixtureRun($pdo, $compId, $cLastDay, '2026-01-01', '2026-01-31', '2026-02-05');
    $res = $model->suggestNextPeriod($cLastDay, $compId);
    check('next period ends Feb 28 (2026 is not a leap year, not overflowed to Mar)', $res['period_end_date'] ?? null, '2026-02-28');

    // ---------- Semi-monthly, cutoff=15: second-half (month-end) -> next month's first half,
    // crossing a 31-day month into a 30-day/28-day month ----------
    echo "=== Semi-monthly, cutoff_day_of_month=15 ===\n";
    $cSemi = makeCycle($pdo, $compId, ['payroll_frequency' => 'semi_monthly', 'cutoff_day_of_month' => 15, 'payment_day_of_month' => 5]);
    // Second half of January (16-31) already happened -- next should be first half of February.
    makeFixtureRun($pdo, $compId, $cSemi, '2026-01-16', '2026-01-31', '2026-02-05');
    $res = $model->suggestNextPeriod($cSemi, $compId);
    checkTrue('status true', $res['status']);
    check('next period is first half of Feb (start)', $res['period_start_date'] ?? null, '2026-02-01');
    check('next period is first half of Feb (end = cutoff day)', $res['period_end_date'] ?? null, '2026-02-15');
    check('payment in March (month after Feb)', $res['payment_date'] ?? null, '2026-03-05');

    // First half just happened -- next should be second half of the SAME month.
    $pdo->prepare("DELETE FROM payroll_runs WHERE cycle_id = :id")->execute([':id' => $cSemi]);
    makeFixtureRun($pdo, $compId, $cSemi, '2026-02-01', '2026-02-15', '2026-03-05');
    $res = $model->suggestNextPeriod($cSemi, $compId);
    check('next period is second half of same month (start)', $res['period_start_date'] ?? null, '2026-02-16');
    check('next period is second half of same month (end = last day of Feb)', $res['period_end_date'] ?? null, '2026-02-28');

    // ---------- Weekly ----------
    echo "=== Weekly, cutoff_day_of_week=sunday, payment_day_of_week=friday ===\n";
    $cWeekly = makeCycle($pdo, $compId, [
        'payroll_frequency' => 'weekly', 'cutoff_day_of_month' => null,
        'cutoff_day_of_week' => 'sunday', 'payment_day_of_month' => null, 'payment_day_of_week' => 'friday',
    ]);
    // 2026-02-08 is a Sunday.
    makeFixtureRun($pdo, $compId, $cWeekly, '2026-02-02', '2026-02-08', '2026-02-13');
    $res = $model->suggestNextPeriod($cWeekly, $compId);
    checkTrue('status true', $res['status']);
    check('next 7-day period starts the day after the last one ended', $res['period_start_date'] ?? null, '2026-02-09');
    check('next period ends exactly 7 days later, on a Sunday', $res['period_end_date'] ?? null, '2026-02-15');
    check('payment is the next Friday after period end', $res['payment_date'] ?? null, '2026-02-20');

    // ---------- Bi-weekly ----------
    echo "=== Bi-weekly (14-day period) ===\n";
    $cBiweekly = makeCycle($pdo, $compId, [
        'payroll_frequency' => 'bi_weekly', 'cutoff_day_of_month' => null,
        'cutoff_day_of_week' => 'sunday', 'payment_day_of_month' => null, 'payment_day_of_week' => 'friday',
    ]);
    makeFixtureRun($pdo, $compId, $cBiweekly, '2026-01-26', '2026-02-08', '2026-02-13');
    $res = $model->suggestNextPeriod($cBiweekly, $compId);
    check('next 14-day period starts the day after the last one ended', $res['period_start_date'] ?? null, '2026-02-09');
    check('next period ends exactly 14 days later', $res['period_end_date'] ?? null, '2026-02-22');

    // ---------- Daily (Backlog Phase 10, T060 Step B) ----------
    echo "=== Daily (1-day period) ===\n";
    $cDaily = makeCycle($pdo, $compId, ['payroll_frequency' => 'daily']);
    makeFixtureRun($pdo, $compId, $cDaily, '2026-02-08', '2026-02-08', '2026-02-08');
    $res = $model->suggestNextPeriod($cDaily, $compId);
    checkTrue('status true', $res['status']);
    check('next daily period starts the day after the last one ended', $res['period_start_date'] ?? null, '2026-02-09');
    check('next daily period is exactly 1 day (start === end)', $res['period_end_date'] ?? null, '2026-02-09');
    check('payment defaults to the same day as the period (no configured offset for daily)', $res['payment_date'] ?? null, '2026-02-09');

    // ---------- No prior run at all -- just check invariants, not exact dates (today-dependent) ----------
    echo "=== No prior run (invariants only, not date-dependent) ===\n";
    $cFresh = makeCycle($pdo, $compId, ['cutoff_day_of_month' => 25, 'payment_day_of_month' => 5]);
    $res = $model->suggestNextPeriod($cFresh, $compId);
    checkTrue('status true even with no prior run', $res['status']);
    checkTrue('period_start_date <= period_end_date', $res['period_start_date'] <= $res['period_end_date']);
    checkTrue('payment_date is after period_end_date', $res['payment_date'] > $res['period_end_date']);
    $startDt = new DateTime($res['period_start_date']);
    $endDt = new DateTime($res['period_end_date']);
    check('monthly period is roughly one month long', $startDt->diff($endDt)->days >= 27 && $startDt->diff($endDt)->days <= 31 ? 'ok' : 'bad', 'ok');

    // ---------- Unconfigured cycle -- should fail gracefully, not throw ----------
    echo "=== Unconfigured cycle ===\n";
    $cBroken = makeCycle($pdo, $compId, ['cutoff_day_of_month' => null, 'payment_day_of_month' => null]);
    $res = $model->suggestNextPeriod($cBroken, $compId);
    check('unconfigured monthly cycle fails gracefully (status false, not an exception)', $res['status'], false);

    // ---------- Unknown cycle id ----------
    $res = $model->suggestNextPeriod(999999999, $compId);
    check('unknown cycle id fails gracefully', $res['status'], false);

    // ---------- save() validation (Backlog Phase 10, T060 Step B) ----------
    echo "=== save(): daily accepted with no cutoff fields, bi_weekly day-of-week bug fixed ===\n";
    $saveDaily = $model->save($compId, [
        'cycle_name' => 'Save_Daily_' . uniqid(), 'payroll_frequency' => 'daily',
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1,
    ], $userId = 1);
    checkTrue('save() accepts payroll_frequency=daily with no cutoff_day_of_month/_of_week at all', $saveDaily['status']);

    // Real, pre-existing, unrelated bug: before this fix, save() only branched on 'weekly' for the
    // day-of-week fields, so this bi_weekly save (day-of-week fields set, NO day-of-month fields)
    // would have been wrongly routed into the day-of-month validation branch and REJECTED.
    $saveBiWeekly = $model->save($compId, [
        'cycle_name' => 'Save_BiWeekly_' . uniqid(), 'payroll_frequency' => 'bi_weekly',
        'cutoff_day_of_week' => 'sunday', 'payment_day_of_week' => 'friday',
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1,
    ], $userId);
    checkTrue('save() now accepts bi_weekly with day-of-week fields (real bug fixed, was wrongly rejected before)', $saveBiWeekly['status']);
    $savedBiWeekly = $model->get((int)$saveBiWeekly['id'], $compId);
    check('the saved bi_weekly row really persisted cutoff_day_of_week', $savedBiWeekly['cutoff_day_of_week'] ?? null, 'sunday');

    $saveBiWeeklyMissing = $model->save($compId, [
        'cycle_name' => 'Save_BiWeekly_Bad_' . uniqid(), 'payroll_frequency' => 'bi_weekly',
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1,
    ], $userId);
    checkFalse('save() still rejects bi_weekly with NO day-of-week fields at all', $saveBiWeeklyMissing['status']);

    $saveInvalidFreq = $model->save($compId, [
        'cycle_name' => 'Save_Bad_Freq_' . uniqid(), 'payroll_frequency' => 'yearly',
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1,
    ], $userId);
    checkFalse('save() rejects a genuinely invalid payroll_frequency', $saveInvalidFreq['status']);

    // ---------- Real end-to-end proof through PayrollRunModel::recalculate() ----------
    echo "=== Real end-to-end: daily-frequency cycle + salary_type='daily' employee ===\n";
    $runModel = new PayrollRunModel($pdo);
    $dailyCycleId = (int)$saveDaily['id'];
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'รายวัน', 'Test', 'Daily', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2018-01-01', 'permanent', 'full_time', 'office', 'manual',
         'daily', 1000, '2018-01-01', 'average', 'active', 0, 0, 1, :cycle_id)");
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'DAILY_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':cycle_id' => $dailyCycleId,
    ]);
    $dailyEmployeeId = (int)$pdo->lastInsertId();

    $runRes = $runModel->create($compId, [
        'cycle_id' => $dailyCycleId, 'run_name' => 'Daily_Run_' . uniqid(),
        'period_start_date' => '2026-03-10', 'period_end_date' => '2026-03-10', 'payment_date' => '2026-03-10',
    ], $userId, true);
    checkTrue('run created for the 1-day period', $runRes['status'] ?? false);
    if (!empty($runRes['status'])) {
        $runId = (int)$runRes['id'];
        $recalc = $runModel->recalculate($runId, $compId, $userId, true);
        checkTrue('recalculate() succeeds for a daily-frequency run, zero PayrollRunModel changes needed', $recalc['status'] ?? false);
        $details = $runModel->getDetails($runId, $compId);
        $row = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $dailyEmployeeId));
        checkTrue('the daily employee has a run detail row', $row !== false);
        if ($row !== false) {
            // salary_type='daily' divisor is 1 (base_salary_amount IS the daily rate already) --
            // 1 payable day (2026-03-10 is a Tuesday, no shift assigned so every day counts as a
            // work day, no holidays configured for this fresh company) * 1000 = 1000.00.
            check('base_salary_amount = the daily rate itself for exactly 1 payable day', (float)$row['base_salary_amount'], 1000.00);
        }
    }
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  uncaught exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
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
