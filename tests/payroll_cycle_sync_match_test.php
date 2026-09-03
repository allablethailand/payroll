<?php
/**
 * Lightweight verification script for PayrollCycleModel::matchForSyncProcess() -- the 2026-09-01
 * best-effort heuristic that lets "Pull to Run" pre-select the Payroll Schedule dropdown from an
 * incoming Origami sync process, when confident (see that method's own docblock for the full rule
 * set and its real limits). Pure unit test, no DB needed -- the method takes plain PHP arrays
 * (PayrollCycleModel::list()'s own shape / PayrollSyncModel::pendingList()'s own row shape) and
 * returns either a matched cycle row or null.
 *
 * 2026-09-02: gained coverage for `external_cycle_code` -- Origami's own reply to the mapping gap
 * this heuristic originally flagged (see matchForSyncProcess()'s own docblock) -- checked FIRST as
 * an exact match, only falling through to the structural heuristic below when absent or unmatched.
 *
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

echo "=== 2026-09-02: external_cycle_code, when present on BOTH sides, matches exactly and bypasses the heuristic entirely ===\n";
// Deliberately structurally AMBIGUOUS (would refuse to guess under the old heuristic alone, see
// "ambiguous, no period_name tiebreak" above) -- an exact code match must still resolve it, since
// it's authoritative, not just another heuristic signal.
$cyclesWithCodes = [
    monthlyCycle(1, 'Monthly Cycle A', 20, 25) + ['external_cycle_code' => 'PR-MTH-20'],
    monthlyCycle(2, 'Monthly Cycle B', 20, 25) + ['external_cycle_code' => 'PR-OTHER'],
];
$syncRowWithCode = $syncRow; $syncRowWithCode['external_cycle_code'] = 'PR-MTH-20'; $syncRowWithCode['period_name'] = 'Something Unrelated';
$mCode = $model->matchForSyncProcess($cyclesWithCodes, $syncRowWithCode);
check('exact code match wins even when structurally ambiguous and period_name would not tiebreak', $mCode['id'] ?? null, 1);

echo "=== external_cycle_code match is case-insensitive/trimmed, same convention as period_name tiebreak ===\n";
$syncRowCodeCase = $syncRow; $syncRowCodeCase['external_cycle_code'] = '  pr-mth-20  ';
$mCodeCase = $model->matchForSyncProcess($cyclesWithCodes, $syncRowCodeCase);
check('code match ignores case/surrounding whitespace', $mCodeCase['id'] ?? null, 1);

echo "=== external_cycle_code set on the sync row but no active cycle has that code yet -- falls back to the heuristic, not null ===\n";
$syncRowUnknownCode = $syncRow; $syncRowUnknownCode['external_cycle_code'] = 'NOT-CONFIGURED-YET';
$mUnknownCode = $model->matchForSyncProcess($cyclesSingle, $syncRowUnknownCode); // cyclesSingle has no external_cycle_code at all
check('unmatched code falls through to the structural heuristic instead of refusing outright', $mUnknownCode['id'] ?? null, 1);

echo "=== external_cycle_code absent on the sync row (Origami admin never set one) -- heuristic runs exactly as before ===\n";
$syncRowNoCode = $syncRow; // no external_cycle_code key at all, same shape every pre-2026-09-02 test row above already used
$mNoCode = $model->matchForSyncProcess($cyclesWithCodes, $syncRowNoCode);
checkNull('with no code sent, 2 candidates sharing the same structural shape are still refused (period_name doesn\'t tiebreak here either)', $mNoCode);

echo "=== external_cycle_code match ignores an inactive cycle, same as the structural heuristic already does ===\n";
$cyclesCodeInactive = [
    ['id' => 6, 'status' => 'inactive', 'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 20, 'cutoff_use_last_day' => 0, 'cutoff_day_of_week' => null, 'payment_day_of_month' => 25, 'payment_use_last_day' => 0, 'payment_day_of_week' => null, 'cycle_name' => 'Inactive Coded Cycle', 'external_cycle_code' => 'PR-MTH-20'],
];
checkNull('an inactive cycle with a matching code is never auto-selected', $model->matchForSyncProcess($cyclesCodeInactive, $syncRowWithCode));

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (pure unit test, no DB writes)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
