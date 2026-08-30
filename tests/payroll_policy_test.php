<?php
/**
 * Lightweight verification for PayrollPolicyModel (Payroll Configuration's new "Payroll Policies"
 * tab) and PayrollRunModel::reopen()'s own reopen-window enforcement built on top of it
 * (2026-08-30, explicit request: "หลังจากปิดรอบต้องกี่วันถึงจะสามารถดึงกลับมาได้").
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/payroll_policy_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

$passes = 0;
$failures = 0;
function check($label, $actual, $expected) {
    global $passes, $failures;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue($label, $actual) {
    global $passes, $failures;
    if ($actual) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . "\n";
    }
}

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

try {
    $compId = 1;
    $adminUserId = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($adminUserId <= 0) {
        throw new RuntimeException('No employee row found for comp_id=1 to use as the test actor -- fixture assumption broken.');
    }
    $policyModel = new PayrollPolicyModel($pdo);
    $runModel = new PayrollRunModel($pdo);

    echo "=== PayrollPolicyModel::get()/save() ===\n";
    // comp_id=1 has no company_payroll_policies row yet in real dev data (brand-new table, this
    // migration never ran before this feature) -- get() must return sane, backward-compatible
    // defaults (unlimited reopen window) rather than error.
    $defaults = $policyModel->get($compId);
    check('get() with no saved row returns reopen_window_days=null (unlimited, unchanged default behavior)', $defaults['reopen_window_days'], null);

    $saveRes = $policyModel->save($compId, ['reopen_window_days' => 7], $adminUserId);
    checkTrue('save() with reopen_window_days=7 succeeds' . (empty($saveRes['status']) ? " ({$saveRes['message']})" : ''), $saveRes['status']);
    $after7 = $policyModel->get($compId);
    check('get() reflects the saved value 7', $after7['reopen_window_days'], 7);

    $resaveRes = $policyModel->save($compId, ['reopen_window_days' => 30], $adminUserId);
    checkTrue('re-save (upsert, not duplicate row) succeeds', $resaveRes['status']);
    $after30 = $policyModel->get($compId);
    check('get() reflects the re-saved value 30 (ON DUPLICATE KEY UPDATE, one row per company)', $after30['reopen_window_days'], 30);

    $clearRes = $policyModel->save($compId, ['reopen_window_days' => null], $adminUserId);
    checkTrue('saving null clears it back to unlimited', $clearRes['status']);
    check('get() reflects null again', $policyModel->get($compId)['reopen_window_days'], null);

    $invalidRes = $policyModel->save($compId, ['reopen_window_days' => -5], $adminUserId);
    check('save() rejects a negative reopen_window_days', $invalidRes['status'], false);

    echo "=== PayrollRunModel::reopen(): reopen-window enforcement ===\n";
    // Minimal synthetic runs -- direct INSERT rather than the full submit/approve/markPaid/lock
    // chain, since reopen()'s own state-transition/installment-reversal mechanics are already
    // covered by tests/payroll_run_test.php's own reopen() section; this file only needs to
    // exercise the NEW day-window arithmetic added on top of it.
    $insertRun = function (string $lockedAtSql) use ($pdo, $compId, $adminUserId): int {
        $stmt = $pdo->prepare(
            "INSERT INTO `payroll_runs` (comp_id, run_name, period_start_date, period_end_date, payment_date, state, locked_at, created_by)
             VALUES (:comp_id, 'Reopen Window Test Run', '2026-01-01', '2026-01-31', '2026-02-05', 'locked', {$lockedAtSql}, :created_by)"
        );
        $stmt->execute([':comp_id' => $compId, ':created_by' => $adminUserId]);
        return (int)$pdo->lastInsertId();
    };

    $policyModel->save($compId, ['reopen_window_days' => 5], $adminUserId);

    $runOldId = $insertRun('DATE_SUB(NOW(), INTERVAL 10 DAY)');
    $reopenOldRes = $runModel->reopen($runOldId, $compId, $adminUserId, true, 'test');
    check('reopen() refused: locked 10 days ago, past the 5-day window', $reopenOldRes['status'], false);
    checkTrue('refusal message names the elapsed days and the configured window', str_contains($reopenOldRes['message'] ?? '', '10') && str_contains($reopenOldRes['message'] ?? '', '5'));

    $runRecentId = $insertRun('DATE_SUB(NOW(), INTERVAL 2 DAY)');
    $reopenRecentRes = $runModel->reopen($runRecentId, $compId, $adminUserId, true, 'test');
    checkTrue('reopen() allowed: locked 2 days ago, within the 5-day window' . (empty($reopenRecentRes['status']) ? " ({$reopenRecentRes['message']})" : ''), $reopenRecentRes['status']);

    $runExactBoundaryId = $insertRun('DATE_SUB(NOW(), INTERVAL 5 DAY)');
    $reopenBoundaryRes = $runModel->reopen($runExactBoundaryId, $compId, $adminUserId, true, 'test');
    checkTrue('reopen() allowed exactly at the boundary (5 days elapsed, window is 5 -- not "past" it)' . (empty($reopenBoundaryRes['status']) ? " ({$reopenBoundaryRes['message']})" : ''), $reopenBoundaryRes['status']);

    $policyModel->save($compId, ['reopen_window_days' => null], $adminUserId);
    $runVeryOldId = $insertRun('DATE_SUB(NOW(), INTERVAL 400 DAY)');
    $reopenUnlimitedRes = $runModel->reopen($runVeryOldId, $compId, $adminUserId, true, 'test');
    checkTrue('reopen() allowed regardless of age when the policy is unset/null (unlimited, unchanged default)' . (empty($reopenUnlimitedRes['status']) ? " ({$reopenUnlimitedRes['message']})" : ''), $reopenUnlimitedRes['status']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
