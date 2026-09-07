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

    // ---------- generateNext() (2026-09-02, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ") ----------
    // First real consumer of prefix_format/digit_count/current_number/reset_cycle -- was flagged in
    // this model's own docblock since 2026-08-23 as never wired to anything.
    echo "=== generateNext(): sequential numbering + prefix placeholder substitution ===\n";
    $model->save($compId, 'PAYROLL_RUN', ['prefix_format' => 'PR-{YYYY}-', 'digit_count' => 3, 'current_number' => 0, 'reset_cycle' => 'never'], $userId);
    $expectedPrefix = 'PR-' . date('Y') . '-';
    $code1 = $model->generateNext($compId, 'PAYROLL_RUN');
    check('first code is 001', $code1, $expectedPrefix . '001');
    $code2 = $model->generateNext($compId, 'PAYROLL_RUN');
    check('second code increments to 002', $code2, $expectedPrefix . '002');
    $code3 = $model->generateNext($compId, 'PAYROLL_RUN');
    check('third code increments to 003', $code3, $expectedPrefix . '003');
    $afterGen = current(array_filter($model->list($compId), fn($r) => $r['document_type_code'] === 'PAYROLL_RUN'));
    check('current_number persisted at 3 after 3 calls', (int)$afterGen['current_number'], 3);

    echo "=== generateNext(): unknown document_type_code returns null, never throws ===\n";
    check('unknown type returns null', $model->generateNext($compId, 'NOT_A_REAL_TYPE'), null);

    echo "=== generateNext(): digit_count padding respected ===\n";
    $model->save($compId, 'BANK_TRANSFER', ['prefix_format' => 'BT-{YYYYMMDD}-', 'digit_count' => 2, 'current_number' => 0, 'reset_cycle' => 'never'], $userId);
    $btCode = $model->generateNext($compId, 'BANK_TRANSFER');
    check('BT code padded to 2 digits', $btCode, 'BT-' . date('Ymd') . '-01');

    echo "=== generateNext(): reset_cycle='yearly' resets to 1 when last_reset_key is a different year ===\n";
    $model->save($compId, 'WHT_CERT', ['prefix_format' => 'WHT-{YYYY}-', 'digit_count' => 3, 'current_number' => 50, 'reset_cycle' => 'yearly'], $userId);
    // Simulate "last generated in a prior year" directly -- generateNext() itself has no way to
    // fast-forward the wall clock, so this is the only way to exercise the reset branch without
    // waiting for an actual year boundary.
    $pdo->prepare("UPDATE document_numbering_settings SET last_reset_key = '2020' WHERE comp_id = :c AND document_type_code = 'WHT_CERT'")->execute([':c' => $compId]);
    $whtResetCode = $model->generateNext($compId, 'WHT_CERT');
    check('yearly reset: counter resets to 001 despite current_number being 50', $whtResetCode, 'WHT-' . date('Y') . '-001');
    $whtRow = current(array_filter($model->list($compId), fn($r) => $r['document_type_code'] === 'WHT_CERT'));
    check('last_reset_key updated to the current year', $whtRow['last_reset_key'], date('Y'));
    $whtNextCode = $model->generateNext($compId, 'WHT_CERT');
    check('a 2nd call in the SAME year continues from 002, not resetting again', $whtNextCode, 'WHT-' . date('Y') . '-002');

    echo "=== generateNext(): reset_cycle='monthly' uses YYYY-MM as its reset key ===\n";
    $model->save($compId, 'PAYSLIP', ['prefix_format' => 'PS-{YYYY}{MM}-', 'digit_count' => 4, 'current_number' => 10, 'reset_cycle' => 'monthly'], $userId);
    $pdo->prepare("UPDATE document_numbering_settings SET last_reset_key = '2020-01' WHERE comp_id = :c AND document_type_code = 'PAYSLIP'")->execute([':c' => $compId]);
    $slipResetCode = $model->generateNext($compId, 'PAYSLIP');
    check('monthly reset: counter resets to 0001', $slipResetCode, 'PS-' . date('Y') . date('m') . '-0001');
    $slipRow = current(array_filter($model->list($compId), fn($r) => $r['document_type_code'] === 'PAYSLIP'));
    check('last_reset_key updated to YYYY-MM', $slipRow['last_reset_key'], date('Y-m'));

    echo "=== generateNext(): a fresh company gets auto-seeded (ensureSeeded()) so it never fails on a first call ===\n";
    $freshCompCode = 'DOCNUM_' . uniqid();
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)");
    $insComp->execute([':name' => 'DocNum Test Co ' . uniqid(), ':comp_code' => $freshCompCode]);
    $freshCompId = (int)$pdo->lastInsertId();
    $freshCode = $model->generateNext($freshCompId, 'PAYROLL_RUN');
    check('fresh, never-touched company still gets a real code on the first call', $freshCode, 'PR-' . date('Y') . '-001');

    echo "=== generateNext(): {COMP_CODE} placeholder substitution (Backlog Phase 11, T061) ===\n";
    // Reuses $freshCompId's own real origami_payroll_comp_code ($freshCompCode) set above.
    $model->save($freshCompId, 'PAYSLIP', ['prefix_format' => 'PS-{COMP_CODE}-{YYYY}-', 'digit_count' => 3, 'current_number' => 0, 'reset_cycle' => 'never'], $userId);
    $compCodeInPrefixCode = $model->generateNext($freshCompId, 'PAYSLIP');
    check('{COMP_CODE} substitutes the real origami_payroll_comp_code', $compCodeInPrefixCode, 'PS-' . $freshCompCode . '-' . date('Y') . '-001');

    echo "=== generateNext(): {COMP_CODE} with a NULL comp code degrades to an empty substitution, never errors ===\n";
    $noCodeComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, 'TH', '1234567890124', 'Test Address', 'Tester', 'active')");
    $noCodeComp->execute([':name' => 'DocNum NoCode Co ' . uniqid()]);
    $noCodeCompId = (int)$pdo->lastInsertId();
    $model->save($noCodeCompId, 'PAYSLIP', ['prefix_format' => 'PS-{COMP_CODE}-{YYYY}-', 'digit_count' => 3, 'current_number' => 0, 'reset_cycle' => 'never'], $userId);
    $noCodeGenerated = $model->generateNext($noCodeCompId, 'PAYSLIP');
    check('a company with NO origami_payroll_comp_code (null) substitutes an empty string, not "null"/an error', $noCodeGenerated, 'PS--' . date('Y') . '-001');

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
