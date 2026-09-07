<?php
/**
 * Verifies DashboardController::computeCostTrend() (2026-09-02, "หน้า Dashboard อยากให้เพิ่มกราฟ" --
 * the "Payroll Cost Trend" chart's own aggregation). Pure/static, no DB access -- fixture rows are
 * plain in-memory arrays shaped like PayrollRunModel::list()'s own rows, not a real DB fixture.
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/dashboard_cost_trend_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';

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

function run(string $state, string $paymentDate, float $net): array {
    return ['state' => $state, 'payment_date' => $paymentDate, 'total_net_amount' => $net];
}

echo "=== computeCostTrend(): sums same-month runs, ignores non-final states ===\n";
$rows = [
    run('approved', '2026-06-05', 100000.0),
    run('paid', '2026-06-25', 50000.0),      // same month as above -> summed
    run('draft', '2026-06-10', 999999.0),    // draft -- must NOT count
    run('pending_approval', '2026-06-11', 888888.0), // must NOT count
    run('rejected', '2026-06-12', 777777.0), // must NOT count
    run('locked', '2026-07-05', 200000.0),
];
$trend = DashboardController::computeCostTrend($rows);
check('2 distinct months in the trend (June + July, not 6 rows)', count($trend), 2);
check('June is first (chronological order)', $trend[0]['month'], '2026-06');
check('June net_amount is the SUM of the 2 final-state June runs (100000+50000), not just one', $trend[0]['net_amount'], 150000.0);
check('July is second', $trend[1]['month'], '2026-07');
check('July net_amount matches its own single run', $trend[1]['net_amount'], 200000.0);

echo "=== computeCostTrend(): only the last 6 months are kept, chronologically ===\n";
$manyMonths = [];
for ($m = 1; $m <= 9; $m++) {
    $manyMonths[] = run('paid', sprintf('2026-%02d-15', $m), (float)($m * 1000));
}
$trendMany = DashboardController::computeCostTrend($manyMonths);
check('exactly 6 months kept out of 9', count($trendMany), 6);
check('the oldest kept month is April (month 4), not January', $trendMany[0]['month'], '2026-04');
check('the newest kept month is September (month 9)', $trendMany[5]['month'], '2026-09');
check('ascending order (oldest first)', $trendMany[0]['net_amount'] < $trendMany[5]['net_amount'], true);

echo "=== computeCostTrend(): edge cases ===\n";
check('empty input -> empty trend, not an error', DashboardController::computeCostTrend([]), []);
$noPaymentDate = [['state' => 'approved', 'payment_date' => null, 'total_net_amount' => 5000.0]];
check('a run with no payment_date is skipped, not crashed on', DashboardController::computeCostTrend($noPaymentDate), []);
$allDraft = [run('draft', '2026-06-05', 100000.0), run('pending_approval', '2026-06-06', 200000.0)];
check('a company with ONLY non-final-state runs returns an empty trend (nothing final to show yet)', DashboardController::computeCostTrend($allDraft), []);

echo "=== computeCostTrend(\$endMonthStart): re-centers the 6-month window at a past month (Dashboard's own month/year picker, 2026-09-06) ===\n";
$trendHistorical = DashboardController::computeCostTrend($manyMonths, '2026-06-01');
check('exactly 6 months kept (Jan-Jun are <= the selected June end month, exactly fills the window)', count($trendHistorical), 6);
check('the oldest kept month is January (all 6 real months at/before June fit within the window)', $trendHistorical[0]['month'], '2026-01');
check('the newest kept month is June (the selected end month), NOT July/August/September', $trendHistorical[5]['month'], '2026-06');

// A cutoff with MORE than 6 real months before it -- the "last 6, dropping the earliest" slicing
// still applies WITHIN the historical window, not just against "today".
$trendHistoricalCapped = DashboardController::computeCostTrend($manyMonths, '2026-08-01');
check('capped at 6 even within a historical window (Jan-Aug = 8 real months, keeps only the last 6)', count($trendHistoricalCapped), 6);
check('the oldest kept month is March (Jan/Feb dropped by the 6-month cap)', $trendHistoricalCapped[0]['month'], '2026-03');
check('the newest kept month is August (the selected end month), NOT September', $trendHistoricalCapped[5]['month'], '2026-08');

$trendHistoricalFull = DashboardController::computeCostTrend($manyMonths, '2026-09-01');
check('endMonthStart equal to the latest real data reproduces the exact same 6-month window as the default (today) case', $trendHistoricalFull, $trendMany);

check('null endMonthStart (the default) is byte-identical to calling with no 2nd arg at all', DashboardController::computeCostTrend($rows, null), DashboardController::computeCostTrend($rows));

$trendHistoricalEmpty = DashboardController::computeCostTrend($allDraft, '2026-06-01');
check('a historical window with zero final-state data still returns an empty array, not zero-padded', $trendHistoricalEmpty, []);

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
