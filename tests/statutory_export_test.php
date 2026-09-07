<?php
/**
 * Lightweight verification script for the statutory export scaffolding (Part 4).
 * Not PHPUnit — see tests/statutory_engine_test.php for why. Pure PHP, no DB needed.
 * Run with: php tests/statutory_export_test.php
 *
 * 2026-09-05, REWRITTEN (Phase 12, T071) — every exporter here was rebuilt against a real
 * reference spec the user supplied (Payroll_Government_Export_Specs.pdf/.xlsx,
 * GovernmentExporters.php, and 2 byte-exact .txt samples), adopted wholesale per explicit
 * confirmation (AskUserQuestion, "ยึด spec ใหม่ทั้งหมด (แนะนำ)"). Every expected value below that
 * comes from the reference sample itself was cross-checked by decoding that sample's own raw
 * TIS-620 bytes via `iconv('TIS-620','UTF-8', ...)` and reading the real Thai names back out
 * (confirmed "นาย สมชาย ใจดี" etc.), not assumed. All 5 registered exporters now return
 * isVerified()=true for their field LAYOUT (StatutoryExportInterface's own docblock: this flag is
 * about the layout, not any one call's data) — this file's own checks now also assert
 * isVerified()===true, replacing the pre-2026-09-05 "both TH drafts are flagged unverified" check.
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
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

echo "=== Registry ===\n";
$thExporters = StatutoryExportRegistry::forCountry('TH');
check('TH has 5 registered export formats', count($thExporters), 5);
check('unknown code returns null', StatutoryExportRegistry::get('XX_NOPE'), null);
checkTrue('every TH exporter now reports isVerified()=true (field layout confirmed)', array_reduce($thExporters, fn($carry, $e) => $carry && $e->isVerified(), true));

echo "\n=== PndOneExporter (ภ.ง.ด.1, 11-field pipe format) ===\n";
$pnd1 = StatutoryExportRegistry::get('TH_PND1');
$content = $pnd1->generate([
    'period' => ['tax_year' => 2567, 'tax_month' => 1, 'payment_date' => '2024-01-31'],
    'employees' => [
        ['id_card_no' => '1100200300405', 'prefix' => 'นาย', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'total_income' => 35000, 'tax_withheld' => 1250.5],
        ['id_card_no' => '3100500600708', 'prefix' => 'นางสาว', 'first_name' => 'วิภา', 'last_name' => 'มีสุข', 'total_income' => 28000, 'tax_withheld' => 650],
    ],
]);
$lines = explode("\r\n", rtrim($content, "\r\n"));
check('generates one line per employee', count($lines), 2);
$decoded = iconv('TIS-620', 'UTF-8', $lines[0]);
check('11 pipe-delimited fields per row', count(explode('|', $decoded)), 11);
check('matches the reference sample byte-for-byte (row 1)', $decoded, '1|1100200300405|นาย|สมชาย|ใจดี|31012567|1|0.00|35000.00|1250.50|1');
check('matches the reference sample byte-for-byte (row 2)', iconv('TIS-620', 'UTF-8', $lines[1]), '2|3100500600708|นางสาว|วิภา|มีสุข|31012567|1|0.00|28000.00|650.00|1');
check('filename uses tax_year+tax_month', $pnd1->fileName(['period' => ['tax_year' => 2567, 'tax_month' => 1]]), 'PND1_256701.txt');
checkTrue('isVerified() is true (layout confirmed)', $pnd1->isVerified());
try {
    $pnd1->generate(['period' => [], 'employees' => [['id_card_no' => '123', 'prefix' => 'นาย', 'first_name' => 'x', 'last_name' => 'y', 'total_income' => 1, 'tax_withheld' => 1]]]);
    check('a malformed (non-13-digit) id_card_no throws', 'no exception thrown', 'exception');
} catch (RuntimeException $e) {
    checkTrue('a malformed (non-13-digit) id_card_no throws', true);
}

echo "\n=== PndOneKorExporter (ภ.ง.ด.1ก, same 11-field layout, annual) ===\n";
$pnd1k = StatutoryExportRegistry::get('TH_PND1K');
$content = $pnd1k->generate([
    'period' => ['tax_year' => 2569],
    'employees' => [
        ['id_card_no' => '1234567890123', 'prefix' => 'นาย', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'total_income' => 360000, 'tax_withheld' => 5000.5],
        ['id_card_no' => '9876543210987', 'prefix' => 'นาง', 'first_name' => 'สมหญิง', 'last_name' => 'รักงาน', 'total_income' => 480000.25, 'tax_withheld' => 12000],
    ],
]);
$lines = explode("\r\n", rtrim($content, "\r\n"));
check('generates one line per employee', count($lines), 2);
$fields = explode('|', iconv('TIS-620', 'UTF-8', $lines[0]));
check('11 pipe-delimited fields per row (same layout as PndOneExporter)', count($fields), 11);
check('id_card_no stays 13 digits', $fields[1], '1234567890123');
check('representative pay_date is 31 Dec of the tax year (BE)', $fields[5], '31122569');
check('amount formatted with 2 decimals, no thousands separator', $fields[8], '360000.00');
check('tax withheld rounds to 2 decimals', $fields[9], '5000.50');
check('filename uses tax_year', $pnd1k->fileName(['period' => ['tax_year' => 2569]]), 'PND1K_2569.txt');
checkTrue('isVerified() is true (layout confirmed)', $pnd1k->isVerified());

echo "\n=== Sso110Exporter (สปส.1-10, 7-field pipe format, no header row) ===\n";
$sso = StatutoryExportRegistry::get('TH_SSO110');
$content = $sso->generate([
    'period' => ['year' => 2026, 'month' => 8],
    'employees' => [
        ['insured_id' => '1100200300405', 'prefix' => 'นาย', 'first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'wage' => 15000, 'contribution' => 750],
        ['insured_id' => '3100500600708', 'prefix' => 'นางสาว', 'first_name' => 'วิภา', 'last_name' => 'มีสุข', 'wage' => 12000, 'contribution' => 600],
    ],
]);
$rows = explode("\r\n", rtrim($content, "\r\n"));
check('2 detail rows, no header row', count($rows), 2);
$decoded1 = iconv('TIS-620', 'UTF-8', $rows[0]);
check('matches the reference sample byte-for-byte (row 1)', $decoded1, '00001|1100200300405|นาย|สมชาย|ใจดี|15000.00|750.00');
check('matches the reference sample byte-for-byte (row 2)', iconv('TIS-620', 'UTF-8', $rows[1]), '00002|3100500600708|นางสาว|วิภา|มีสุข|12000.00|600.00');
check('filename uses year+month', $sso->fileName(['period' => ['year' => 2026, 'month' => 8]]), 'SSO110_202608.txt');
checkTrue('isVerified() is true (layout confirmed)', $sso->isVerified());
try {
    $sso->generate(['employees' => [['insured_id' => '1100200300405', 'prefix' => 'นาย', 'first_name' => 'x', 'last_name' => 'y', 'wage' => 20000, 'contribution' => 750]]]);
    check('a wage above the 15,000 SSO ceiling throws', 'no exception thrown', 'exception');
} catch (RuntimeException $e) {
    checkTrue('a wage above the 15,000 SSO ceiling throws', true);
}

echo "\n=== Sso609Exporter (สปส.6-09, NEW -- termination notice) ===\n";
$sso609 = StatutoryExportRegistry::get('TH_SSO609');
$content = $sso609->generate([
    'employees' => [
        ['citizen_id' => '1100200300405', 'full_name' => 'นาย สมชาย ใจดี', 'leave_date' => '2024-01-15', 'reason' => 'ลาออกเอง'],
        ['citizen_id' => '3100500600708', 'full_name' => 'นางสาว วิภา มีสุข', 'leave_date' => '2024-01-31', 'reason' => 'เลิกจ้างตามผลประกอบการ'],
    ],
]);
$rows = explode("\r\n", rtrim($content, "\r\n"));
check('2 rows', count($rows), 2);
check('row 1 matches the reference sample shape', iconv('TIS-620', 'UTF-8', $rows[0]), '1|1100200300405|นาย สมชาย ใจดี|15012567|01');
$fields2 = explode('|', iconv('TIS-620', 'UTF-8', $rows[1]));
check('row 2 leave_date converts to Buddhist-year DDMMYYYY', $fields2[3], '31012567');
check('reason "เลิกจ้าง..." maps to code 02', $fields2[4], '02');
checkTrue('isVerified() is true (layout confirmed)', $sso609->isVerified());

echo "\n=== StudentLoanExporter (กยศ., NEW -- deduction remittance) ===\n";
$slf = StatutoryExportRegistry::get('TH_SLF');
$content = $slf->generate([
    'employees' => [
        ['citizen_id' => '1100200300405', 'full_name' => 'นาย สมชาย ใจดี', 'amount' => 1500],
        ['citizen_id' => '3100500600708', 'full_name' => 'นางสาว วิภา มีสุข', 'amount' => 800],
    ],
]);
$rows = explode("\r\n", rtrim($content, "\r\n"));
check('2 rows', count($rows), 2);
check('row 1 matches the reference sample byte-for-byte', iconv('TIS-620', 'UTF-8', $rows[0]), '1|1100200300405|นาย สมชาย ใจดี|1500.00');
check('row 2 matches the reference sample byte-for-byte', iconv('TIS-620', 'UTF-8', $rows[1]), '2|3100500600708|นางสาว วิภา มีสุข|800.00');
check('filename uses run_id', $slf->fileName(['run_id' => 42]), 'SLF_Run42.txt');
checkTrue('isVerified() is true (layout confirmed)', $slf->isVerified());

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
