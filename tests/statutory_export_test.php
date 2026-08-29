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

echo "\n=== Sso110Exporter (สปส.1-10, rewritten against a real user-supplied sample 2026-08-29) ===\n";
// 2026-08-29 -- field widths/positions below are derived from the real sample the user pasted
// directly (see Sso110Exporter's own docblock for the byte-offset derivation, and its one
// unresolved discrepancy on the header's "total wage to be calculated" field). Header=135 bytes,
// detail=108 bytes (genuinely DIFFERENT row lengths, correcting the prior draft's assumption
// both were 135).
$sso = StatutoryExportRegistry::get('TH_SSO110');
$content = $sso->generate([
    'company' => ['employer_account' => '0007730000', 'branch_seq' => '0001', 'sso_agency_code' => '0769', 'name_th' => 'บริษัท ทดสอบ จำกัด', 'name_en' => 'Test Co., Ltd.'],
    'period' => ['year' => 2026, 'month' => 8],
    'employees' => [
        ['insured_id' => '1012990570210', 'prefix_code' => '03', 'first_name_th' => 'ทิ', 'last_name_th' => 'แซ่โง้ว', 'first_name_en' => 'Thi', 'last_name_en' => 'Saengow', 'wage' => 20800, 'contribution' => 875],
        ['insured_id' => '9876543210987', 'prefix_code' => '05', 'first_name_th' => 'สมหญิง', 'last_name_th' => 'รักงาน', 'first_name_en' => 'Somying', 'last_name_en' => 'Rakngan', 'wage' => 25000, 'contribution' => 625],
    ],
]);
$rows = explode("\r\n", rtrim($content, "\r\n"));
check('1 header + 2 detail rows', count($rows), 3);
check('header row is exactly 135 bytes', strlen($rows[0]), 135);
check('detail row 1 is exactly 108 bytes (genuinely different from the header\'s own 135)', strlen($rows[1]), 108);
check('detail row 2 is exactly 108 bytes', strlen($rows[2]), 108);
check('header starts with record type 11001 (bytes 1-5)', substr($rows[0], 0, 5), '11001');
check('detail starts with record type 25 (bytes 1-2)', substr($rows[1], 0, 2), '25');
check('header employer_account field (bytes 6-15)', substr($rows[0], 5, 10), '0007730000');
check('header branch_seq field (bytes 16-19)', substr($rows[0], 15, 4), '0001');
check('header period MMYY field (bytes 20-23) = 0826 (Aug 2026)', substr($rows[0], 19, 4), '0826');
check('header sso_agency_code field (bytes 24-27)', substr($rows[0], 23, 4), '0769');
check('header insured count field (bytes 73-74) = 02', substr($rows[0], 72, 2), '02');
check('header record count field (bytes 75-82) = 00000002', substr($rows[0], 74, 8), '00000002');
check('header total employee contribution (bytes 111-123, implied 2dp) = 875+625=1500.00', substr($rows[0], 110, 13), '0000000150000');
check('header total employer contribution (bytes 124-135, implied 2dp) = 1500.00', substr($rows[0], 123, 12), '000000150000');
check('detail 1 id_card_no field (bytes 3-15)', substr($rows[1], 2, 13), '1012990570210');
check('detail 1 prefix_code field (bytes 16-17)', substr($rows[1], 15, 2), '03');
check('detail 1 wage field (bytes 83-94, WHOLE integer, no decimal) = 20800', substr($rows[1], 82, 12), '000000020800');
check('detail 1 contribution field (bytes 95-108, implied 2dp) = 875.00', substr($rows[1], 94, 14), '00000000087500');
check('filename uses year+month', $sso->fileName(['period' => ['year' => 2026, 'month' => 8]]), 'SSO110_202608.txt');

// 2026-08-29, explicit request: "รองรับ 2 ภาษาเหมือนกัน" -- employer name + employee first/last
// name follow the requested language; every other field (id_card_no/prefix/amounts/codes) stays
// byte-identical regardless of language.
$contentEn = $sso->generate([
    'company' => ['employer_account' => '0007730000', 'branch_seq' => '0001', 'sso_agency_code' => '0769', 'name_th' => 'บริษัท ทดสอบ จำกัด', 'name_en' => 'Test Co., Ltd.'],
    'period' => ['year' => 2026, 'month' => 8],
    'employees' => [
        ['insured_id' => '1012990570210', 'prefix_code' => '03', 'first_name_th' => 'ทิ', 'last_name_th' => 'แซ่โง้ว', 'first_name_en' => 'Thi', 'last_name_en' => 'Saengow', 'wage' => 20800, 'contribution' => 875],
        ['insured_id' => '9876543210987', 'prefix_code' => '05', 'first_name_th' => 'สมหญิง', 'last_name_th' => 'รักงาน', 'first_name_en' => 'Somying', 'last_name_en' => 'Rakngan', 'wage' => 25000, 'contribution' => 625],
    ],
    'language' => 'en',
]);
$rowsEn = explode("\r\n", rtrim($contentEn, "\r\n"));
check('en language produces a DIFFERENT header row (company name changes)', $rows[0] !== $rowsEn[0], true);
check('en language produces a DIFFERENT detail row (employee name changes)', $rows[1] !== $rowsEn[1], true);
check('en detail id_card_no/prefix/amount fields identical regardless of language', [substr($rows[1], 2, 13), substr($rows[1], 82, 26)], [substr($rowsEn[1], 2, 13), substr($rowsEn[1], 82, 26)]);
check('en company name field decodes to the English name', trim((string)iconv('TIS-620', 'UTF-8', substr($rowsEn[0], 27, 45))), 'Test Co., Ltd.');

// Force a truncation edge case: an over-long company name must not throw, and must not
// break row length — padText() truncates rather than assertLength() ever firing here.
$longName = str_repeat('ก', 100); // 100 Thai chars, well over the 45-byte column width
$edge = $sso->generate([
    'company' => ['employer_account' => '1', 'name_th' => $longName],
    'period' => ['year' => 2026, 'month' => 1],
    'employees' => [],
]);
$edgeRows = explode("\r\n", rtrim($edge, "\r\n"));
check('over-long company name still produces a 135-byte header (truncated, not thrown)', strlen($edgeRows[0]), 135);

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL SHAPE CHECKS PASSED (field content still needs official verification — see class docblocks)\n" : "SOME CHECKS FAILED\n";
exit($failures === 0 ? 0 : 1);
