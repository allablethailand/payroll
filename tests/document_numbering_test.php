<?php
/**
 * Lightweight verification script for DocumentNumberingModel. Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that
 * is always rolled back, so it never leaves any data behind.
 * Run with: php tests/document_numbering_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/DocumentNumberingModel.php';

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

try {
    $compId = 1;
    $userId = 1;
    // Clear any leftover row for this comp so the "lazy seed" path is genuinely exercised, inside
    // this rolled-back transaction only.
    $pdo->prepare("DELETE FROM `document_numbering_settings` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);

    $model = new DocumentNumberingModel($pdo);

    echo "=== list() lazily seeds all 4 fixed document types on first read ===\n";
    $rows = $model->list($compId);
    check('4 rows seeded', count($rows), 4);
    $codes = array_column($rows, 'document_type_code');
    checkTrue('PAYSLIP present', in_array('PAYSLIP', $codes, true));
    checkTrue('PAYROLL_RUN present', in_array('PAYROLL_RUN', $codes, true));
    checkTrue('WHT_CERT present', in_array('WHT_CERT', $codes, true));
    checkTrue('BANK_TRANSFER present', in_array('BANK_TRANSFER', $codes, true));
    $payslipRow = current(array_filter($rows, fn($r) => $r['document_type_code'] === 'PAYSLIP'));
    check('PAYSLIP default prefix_format', $payslipRow['prefix_format'], 'PS-{YYYY}{MM}-');
    check('PAYSLIP default digit_count', (int)$payslipRow['digit_count'], 4);
    check('PAYSLIP default reset_cycle', $payslipRow['reset_cycle'], 'monthly');

    echo "=== list() does not re-seed / duplicate on a second call ===\n";
    $rows2 = $model->list($compId);
    check('still exactly 4 rows on second list()', count($rows2), 4);

    echo "=== save() validation ===\n";
    $emptyPrefixRes = $model->save($compId, 'PAYSLIP', ['prefix_format' => '   ', 'digit_count' => 4, 'current_number' => 0, 'reset_cycle' => 'monthly'], $userId);
    check('empty prefix_format is rejected', $emptyPrefixRes['status'], false);

    $badDigitsRes = $model->save($compId, 'PAYSLIP', ['prefix_format' => 'PS-', 'digit_count' => 0, 'current_number' => 0, 'reset_cycle' => 'monthly'], $userId);
    check('digit_count=0 is rejected', $badDigitsRes['status'], false);
    $badDigitsRes2 = $model->save($compId, 'PAYSLIP', ['prefix_format' => 'PS-', 'digit_count' => 11, 'current_number' => 0, 'reset_cycle' => 'monthly'], $userId);
    check('digit_count=11 is rejected', $badDigitsRes2['status'], false);

    $negativeCurrentRes = $model->save($compId, 'PAYSLIP', ['prefix_format' => 'PS-', 'digit_count' => 4, 'current_number' => -1, 'reset_cycle' => 'monthly'], $userId);
    check('negative current_number is rejected', $negativeCurrentRes['status'], false);

    $overflowRes = $model->save($compId, 'PAYSLIP', ['prefix_format' => 'PS-', 'digit_count' => 2, 'current_number' => 100, 'reset_cycle' => 'monthly'], $userId);
    check('current_number exceeding what digit_count can hold is rejected', $overflowRes['status'], false);

    $badResetRes = $model->save($compId, 'PAYSLIP', ['prefix_format' => 'PS-', 'digit_count' => 4, 'current_number' => 0, 'reset_cycle' => 'weekly'], $userId);
    check('invalid reset_cycle is rejected', $badResetRes['status'], false);

    $invalidTypeRes = $model->save($compId, 'NOT_A_REAL_TYPE', ['prefix_format' => 'PS-', 'digit_count' => 4, 'current_number' => 0, 'reset_cycle' => 'never'], $userId);
    check('unknown document_type_code is rejected', $invalidTypeRes['status'], false);

    echo "=== save() persists a real update ===\n";
    $saveRes = $model->save($compId, 'PAYSLIP', [
        'prefix_format' => 'SLIP-{YYYY}-', 'digit_count' => 5, 'current_number' => 42, 'reset_cycle' => 'yearly',
    ], $userId);
    checkTrue('save succeeds' . (empty($saveRes['status']) ? " ({$saveRes['message']})" : ''), $saveRes['status']);

    $reloaded = $model->list($compId);
    $updatedPayslip = current(array_filter($reloaded, fn($r) => $r['document_type_code'] === 'PAYSLIP'));
    check('prefix_format persisted', $updatedPayslip['prefix_format'], 'SLIP-{YYYY}-');
    check('digit_count persisted', (int)$updatedPayslip['digit_count'], 5);
    check('current_number persisted', (int)$updatedPayslip['current_number'], 42);
    check('reset_cycle persisted', $updatedPayslip['reset_cycle'], 'yearly');
    check('updated_by recorded', (int)$updatedPayslip['updated_by'], $userId);

    echo "=== save() only touches the targeted document type, others stay at defaults ===\n";
    $runRow = current(array_filter($reloaded, fn($r) => $r['document_type_code'] === 'PAYROLL_RUN'));
    check('PAYROLL_RUN untouched by the PAYSLIP save', $runRow['prefix_format'], 'PR-{YYYY}-');

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
