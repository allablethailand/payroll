<?php
/**
 * Lightweight verification script for the statutory export scaffolding (Part 4).
 * Not PHPUnit — see tests/statutory_engine_test.php for why. Pure PHP, no DB needed.
 * Run with: php tests/statutory_export_test.php
 *
 * These checks only prove the SHAPE (delimiter, row length, encoding) is self-consistent —
 * they do NOT prove the field layout matches the real government spec, because that spec
 * could not be confirmed (see the DRAFT disclaimers in each exporter class).
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/services/export/StatutoryExportRegistry.php';

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

echo "=== Registry ===\n";
$thExporters = StatutoryExportRegistry::forCountry('TH');
check('TH has 3 registered export formats', count($thExporters), 3);
check('unknown code returns null', StatutoryExportRegistry::get('XX_NOPE'), null);
check('both TH drafts are flagged unverified', array_reduce($thExporters, fn($carry, $e) => $carry && !$e->isVerified(), true), true);

echo "\n=== PndOneKorExporter (ภ.ง.ด.1ก, draft) ===\n";
$pnd1k = StatutoryExportRegistry::get('TH_PND1K');
$content = $pnd1k->generate([
    'period' => ['tax_year' => 2569],
    'employees' => [
        ['tax_id' => '1-2345-67890-12-3', 'prefix' => 'นาย', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'total_income' => 360000, 'tax_withheld' => 5000.5],
        ['tax_id' => '9876543210987', 'prefix' => 'นาง', 'first_name' => 'สมหญิง', 'last_name' => 'รักงาน', 'total_income' => 480000.25, 'tax_withheld' => 12000],
    ],
]);
$lines = explode("\r\n", rtrim($content, "\r\n"));
check('generates one line per employee', count($lines), 2);
$fields = explode('|', $lines[0]);
check('7 pipe-delimited fields per row', count($fields), 7);
check('tax_id strips dashes and stays 13 digits', $fields[0], '1234567890123');
check('amount formatted with 2 decimals, no thousands separator', $fields[4], '360000.00');
check('tax withheld rounds to 2 decimals', $fields[5], '5000.50');
check('filename uses tax_year', $pnd1k->fileName(['period' => ['tax_year' => 2569]]), 'PND1K_2569.txt');

echo "\n=== Sso110Exporter (สปส.1-10, draft) ===\n";
$sso = StatutoryExportRegistry::get('TH_SSO110');
$content = $sso->generate([
    'company' => ['employer_account' => '1234567890', 'branch_no' => '0', 'name' => 'บริษัท ทดสอบ จำกัด', 'contribution_rate' => 5.0],
    'period' => ['year' => 2026, 'month' => 7, 'payment_date' => '2026-07-15'],
    'employees' => [
        ['insured_id' => '1234567890123', 'prefix_code' => '001', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'wage' => 30000, 'contribution' => 750],
        ['insured_id' => '9876543210987', 'prefix_code' => '002', 'first_name' => 'สมหญิง', 'last_name' => 'รักงาน', 'wage' => 25000, 'contribution' => 625],
    ],
]);
$rows = explode("\r\n", rtrim($content, "\r\n"));
check('1 header + 2 detail rows', count($rows), 3);
check('header row is exactly 135 bytes', strlen($rows[0]), 135);
check('detail row 1 is exactly 135 bytes', strlen($rows[1]), 135);
check('detail row 2 is exactly 135 bytes', strlen($rows[2]), 135);
check('header starts with type=1', substr($rows[0], 0, 1), '1');
check('detail starts with type=2', substr($rows[1], 0, 1), '2');
check('header employer_account field (bytes 2-11)', substr($rows[0], 1, 10), '1234567890');
check('header insured count field = 000002', substr($rows[0], 76, 6), '000002');
check('filename uses year+month', $sso->fileName(['period' => ['year' => 2026, 'month' => 7]]), 'SSO110_202607.txt');

// Force a truncation edge case: an over-long company name must not throw, and must not
// break row length — padText() truncates rather than assertLength() ever firing here.
$longName = str_repeat('ก', 100); // 100 Thai chars, well over the 45-byte column width
$edge = $sso->generate([
    'company' => ['employer_account' => '1', 'name' => $longName, 'contribution_rate' => 5],
    'period' => ['year' => 2026, 'month' => 1, 'payment_date' => '2026-01-01'],
    'employees' => [],
]);
$edgeRows = explode("\r\n", rtrim($edge, "\r\n"));
check('over-long company name still produces a 135-byte header (truncated, not thrown)', strlen($edgeRows[0]), 135);

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL SHAPE CHECKS PASSED (field content still needs official verification — see class docblocks)\n" : "SOME CHECKS FAILED\n";
exit($failures === 0 ? 0 : 1);
