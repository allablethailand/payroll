<?php
/**
 * Lightweight verification script for PayrollCycleModel::matchForSyncProcess() -- the 2026-09-02
 * best-effort heuristic that lets "Pull to Run" pre-select the Payroll Schedule dropdown from an
 * incoming Origami sync process, when confident (see that method's own docblock for the full rule
 * set and its real limits -- there is no reliable id-based mapping available today). Pure unit test,
 * no DB needed -- the method takes plain PHP arrays (PayrollCycleModel::list()'s own shape /
 * PayrollSyncModel::pendingList()'s own row shape) and returns either a matched cycle row or null.
 * Run with: php tests/payroll_cycle_sync_match_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';

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
function checkNull(string $label, $actual): void { check($label, $actual, null); }

$model = new PayrollCycleModel(Database::getInstance()->pdo);

function monthlyCycle(int $id, string $name, int $cutoffDay, int $paymentDay): array {
    return [
        'id' => $id, 'status' => 'active', 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => $cutoffDay, 'cutoff_use_last_day' => 0, 'cutoff_day_of_week' => null,
        'payment_day_of_month' => $paymentDay, 'payment_use_last_day' => 0, 'payment_day_of_week' => null,
        'cycle_name' => $name,
    ];
}
function weeklyCycle(int $id, string $name, string $cutoffDow, string $paymentDow): array {
    return [
        'id' => $id, 'status' => 'active', 'payroll_frequency' => 'weekly',
        'cutoff_day_of_month' => null, 'cutoff_use_last_day' => 0, 'cutoff_day_of_week' => $cutoffDow,
        'payment_day_of_month' => null, 'payment_use_last_day' => 0, 'payment_day_of_week' => $paymentDow,
        'cycle_name' => $name,
    ];
}

echo "=== monthly: single unambiguous structural match (frequency + cutoff day + payment day) ===\n";
// User's own worked example: cutoff every 20th, paid on the 25th -- a process ending 2026-08-20,
// paid 2026-08-25, should match this cycle confidently.
$cyclesSingle = [monthlyCycle(1, 'Monthly Cycle A', 20, 25)];
$syncRow = ['frequency_type' => 'monthly', 'process_end' => '2026-08-20', 'process_paid' => '2026-08-25', 'period_name' => 'Anything'];
$m = $model->matchForSyncProcess($cyclesSingle, $syncRow);
check('matched the one structurally-consistent cycle', $m['id'] ?? null, 1);

echo "=== monthly: wrong cutoff day -- no match ===\n";
$syncRowWrongCutoff = $syncRow; $syncRowWrongCutoff['process_end'] = '2026-08-15';
checkNull('no match when process_end day does not match cutoff_day_of_month', $model->matchForSyncProcess($cyclesSingle, $syncRowWrongCutoff));

echo "=== monthly: wrong payment day -- no match ===\n";
$syncRowWrongPay = $syncRow; $syncRowWrongPay['process_paid'] = '2026-08-28';
checkNull('no match when process_paid day does not match payment_day_of_month', $model->matchForSyncProcess($cyclesSingle, $syncRowWrongPay));

echo "=== monthly: frequency mismatch -- no match ===\n";
$syncRowWrongFreq = $syncRow; $syncRowWrongFreq['frequency_type'] = 'weekly';
checkNull('no match when frequency_type does not translate to the same payroll_frequency', $model->matchForSyncProcess($cyclesSingle, $syncRowWrongFreq));

echo "=== monthly: ambiguous (2 structurally-identical cycles), no period_name tiebreak -- no match ===\n";
$cyclesAmbiguous = [monthlyCycle(1, 'Monthly Cycle A', 20, 25), monthlyCycle(2, 'Monthly Cycle B', 20, 25)];
checkNull('refuses to guess between 2 equally-plausible cycles', $model->matchForSyncProcess($cyclesAmbiguous, $syncRow));

echo "=== monthly: ambiguous, but exactly one candidate's cycle_name matches Origami's own period_name -- tiebreak match ===\n";
$syncRowNamed = $syncRow; $syncRowNamed['period_name'] = '  monthly cycle b  '; // trimmed/case-insensitive
$mNamed = $model->matchForSyncProcess($cyclesAmbiguous, $syncRowNamed);
check('name-match tiebreak resolves the ambiguity', $mNamed['id'] ?? null, 2);

echo "=== monthly: cutoff_use_last_day cycle matches a process_end that falls on the real last day of its month ===\n";
$cyclesLastDay = [
    ['id' => 3, 'status' => 'active', 'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => null, 'cutoff_use_last_day' => 1, 'cutoff_day_of_week' => null, 'payment_day_of_month' => 5, 'payment_use_last_day' => 0, 'payment_day_of_week' => null, 'cycle_name' => 'End-of-month Cycle'],
];
$syncRowLastDay = ['frequency_type' => 'monthly', 'process_end' => '2026-02-28', 'process_paid' => '2026-03-05', 'period_name' => ''];
$mLastDay = $model->matchForSyncProcess($cyclesLastDay, $syncRowLastDay);
check('matches a cutoff_use_last_day cycle when process_end is genuinely that month\'s last day', $mLastDay['id'] ?? null, 3);
$syncRowNotLastDay = $syncRowLastDay; $syncRowNotLastDay['process_end'] = '2026-02-27';
checkNull('does NOT match a cutoff_use_last_day cycle when process_end is one day short of month-end', $model->matchForSyncProcess($cyclesLastDay, $syncRowNotLastDay));

echo "=== weekly: matches by weekday, not day-of-month ===\n";
$cyclesWeekly = [weeklyCycle(4, 'Weekly Cycle', 'friday', 'monday')];
// 2026-08-21 is a Friday, 2026-08-24 is the following Monday.
$syncRowWeekly = ['frequency_type' => 'weekly', 'process_end' => '2026-08-21', 'process_paid' => '2026-08-24', 'period_name' => ''];
$mWeekly = $model->matchForSyncProcess($cyclesWeekly, $syncRowWeekly);
check('matches on cutoff_day_of_week/payment_day_of_week', $mWeekly['id'] ?? null, 4);
$syncRowWeeklyWrongDay = $syncRowWeekly; $syncRowWeeklyWrongDay['process_end'] = '2026-08-20'; // Thursday
checkNull('no match when process_end falls on a different weekday than cutoff_day_of_week', $model->matchForSyncProcess($cyclesWeekly, $syncRowWeeklyWrongDay));

echo "=== inactive/other-company cycles are never candidates ===\n";
$cyclesInactive = [
    ['id' => 5, 'status' => 'inactive', 'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 20, 'cutoff_use_last_day' => 0, 'cutoff_day_of_week' => null, 'payment_day_of_month' => 25, 'payment_use_last_day' => 0, 'payment_day_of_week' => null, 'cycle_name' => 'Inactive Cycle'],
];
checkNull('an inactive cycle is never auto-selected even if it structurally matches', $model->matchForSyncProcess($cyclesInactive, $syncRow));

echo "=== missing data -- refuses to guess rather than throw ===\n";
checkNull('no process_end at all -> null, not an error', $model->matchForSyncProcess($cyclesSingle, ['frequency_type' => 'monthly', 'process_end' => '', 'process_paid' => '', 'period_name' => '']));
checkNull('unrecognized frequency_type -> null, not an error', $model->matchForSyncProcess($cyclesSingle, ['frequency_type' => 'quarterly', 'process_end' => '2026-08-20', 'process_paid' => '2026-08-25', 'period_name' => '']));
checkNull('malformed process_end date -> null, not a thrown exception', $model->matchForSyncProcess($cyclesSingle, ['frequency_type' => 'monthly', 'process_end' => 'not-a-date', 'process_paid' => '2026-08-25', 'period_name' => '']));

echo "=== process_paid absent -- still matches on cutoff alone (payment check is skipped, not failed) ===\n";
$syncRowNoPaid = ['frequency_type' => 'monthly', 'process_end' => '2026-08-20', 'process_paid' => '', 'period_name' => ''];
$mNoPaid = $model->matchForSyncProcess($cyclesSingle, $syncRowNoPaid);
check('matches on cutoff day alone when process_paid is not provided', $mNoPaid['id'] ?? null, 1);

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (pure unit test, no DB writes)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
