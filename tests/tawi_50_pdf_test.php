<?php
/**
 * 50 Tawi certificate (Tawi50PdfReport, TH_WHT50): figures come from AnnualIncomeSummaryModel::getEmployeeAnnualSummary(),
 * so it must show system runs + the imported opening YTD balance. The PDF itself is compressed, so the content is checked
 * on the HTML the PDF is rendered from (prepare()), and the real generate() is checked for a non-empty %PDF binary.
 * Runs inside a transaction that is always rolled back, on a throwaway company.
 *
 * Run with: php tests/tawi_50_pdf_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/services/reports/ReportRegistry.php';

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
function errorKey(callable $fn): ?string {
    try { $fn(); } catch (LocalizedException $e) { return $e->getErrorKey(); }
    return null;
}

try {
    $newCompany = function (string $name) use ($pdo): int {
        $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, address_line_2, authorized_signatory_name, setup_status)
            VALUES (:n, :n, 'TH', '0105500000001', '99 ถนนทดสอบ', 'กรุงเทพฯ 10110', 'สมชาย ผู้มีอำนาจ', 'active')")->execute([':n' => $name . uniqid()]);
        return (int)$pdo->lastInsertId();
    };
    $compId = $newCompany('W50 Test Co ');
    $otherComp = $newCompany('W50 Other Co ');

    $insEmp = $pdo->prepare("INSERT INTO employees (comp_id, employee_no, title, name_th, surname_th, id_card_no, id_card_no_hash, key_version, address_line_1_register, data_source)
        VALUES (:c, :no, 'mr', :n, 'ทดสอบ', :enc, :h, :kv, '12 หมู่ 3 ต.ทดสอบ', 'import')");
    $newEmp = function (int $comp, string $no, string $name, string $idCard) use ($pdo, $insEmp): int {
        $insEmp->execute([':c' => $comp, ':no' => $no, ':n' => $name, ':enc' => EncryptionService::encrypt($idCard)['value'], ':h' => EncryptionService::hash($idCard), ':kv' => EncryptionService::currentKeyVersion()]);
        return (int)$pdo->lastInsertId();
    };
    $insRun = $pdo->prepare("INSERT INTO payroll_runs (comp_id, run_name, period_start_date, period_end_date, payment_date, state, status) VALUES (:c, :n, :ps, :pe, :pd, 'approved', 'active')");
    $insDetail = $pdo->prepare("INSERT INTO payroll_run_details (run_id, employee_id, gross_amount, taxable_gross_amount, total_deduction_amount, net_amount, statutory_breakdown)
        VALUES (:r, :e, :g, :g, 0, :g, :sb)");
    $addRun = function (int $emp, string $paymentDate, float $gross, float $pit, float $sso, float $pvd) use ($pdo, $insRun, $insDetail, $compId): void {
        $insRun->execute([':c' => $compId, ':n' => 'W50_' . uniqid(), ':ps' => substr($paymentDate, 0, 8) . '01', ':pe' => $paymentDate, ':pd' => $paymentDate]);
        $insDetail->execute([':r' => (int)$pdo->lastInsertId(), ':e' => $emp, ':g' => $gross, ':sb' => json_encode([
            ['code' => 'TH_PIT', 'employee_amount' => $pit], ['code' => 'TH_SSO', 'employee_amount' => $sso], ['code' => 'TH_PVD', 'employee_amount' => $pvd]])]);
    };
    $insOpening = $pdo->prepare("INSERT INTO employee_ytd_opening_balances (comp_id, employee_id, tax_year, as_of_date, periods_paid, ytd_taxable_gross, ytd_pit_withheld, ytd_sso_employee, ytd_pvd_employee)
        VALUES (:c, :e, 2026, :a, :p, :g, :t, :s, :v)");

    $idSys = '1111111111111'; $idMix = '2222222222222'; $idYtd = '3333333333333';
    $empSys = $newEmp($compId, 'W50-1', 'ซิสเต็ม', $idSys);
    $empMix = $newEmp($compId, 'W50-2', 'ผสม', $idMix);
    $empYtd = $newEmp($compId, 'W50-3', 'ยอดเปิด', $idYtd);
    $empNone = $newEmp($compId, 'W50-4', 'ไม่มีข้อมูล', '4444444444444');
    $empOther = $newEmp($otherComp, 'W50-X', 'บริษัทอื่น', '5555555555555');
    $addRun($empSys, '2026-01-31', 30000, 1000, 750, 300);
    $addRun($empSys, '2026-02-28', 30000, 1100, 750, 300);
    $addRun($empMix, '2026-08-31', 30000, 800, 750, 0);
    $insOpening->execute([':c' => $compId, ':e' => $empMix, ':a' => '2026-08-01', ':p' => 7, ':g' => 210000, ':t' => 5000, ':s' => 5250, ':v' => 2100]);
    $insOpening->execute([':c' => $compId, ':e' => $empYtd, ':a' => '2026-07-01', ':p' => 6, ':g' => 100000, ':t' => 2000, ':s' => null, ':v' => null]);

    $report = ReportRegistry::get('TH_WHT50');
    $ctx = fn(int $emp, int $year = 2569, ?int $comp = null) => ['comp_id' => $comp ?? $compId, 'year' => $year, 'employee_id' => $emp];
    $prepare = (function (array $c) { return $this->prepare($c); });
    $html = fn(int $emp) => $prepare->call($report, $ctx($emp))['html'];

    echo "=== registration ===\n";
    check('registered as a statutory PDF report, still a draft', [$report->reportType(), $report->supportedFormats(), $report->isVerified()], ['statutory', ['pdf'], false]);

    echo "=== System-only ===\n";
    $h = $html($empSys);
    check('company and employee identity are on the form', [
        str_contains($h, '0105500000001'), str_contains($h, '1111111111111'), str_contains($h, 'นาย ซิสเต็ม ทดสอบ'), str_contains($h, '12 หมู่ 3 ต.ทดสอบ'), str_contains($h, '99 ถนนทดสอบ'),
    ], [true, true, true, true, true]);
    check('section 1: income and tax from the runs', [str_contains($h, '60,000.00'), str_contains($h, '2,100.00')], [true, true]);
    check('fund lines: provident fund and social security', [str_contains($h, '600.00'), str_contains($h, '1,500.00')], [true, true]);
    check('tax in words', str_contains($h, 'สองพันหนึ่งร้อยบาทถ้วน'), true);
    check('withheld-at-source box is ticked, no YTD footnote', [str_contains($h, '<span class="cb">X</span> (1) หัก ณ ที่จ่าย'), str_contains($h, 'รวมยอดสะสมก่อนเข้าระบบ')], [true, false]);

    echo "=== YTD + System ===\n";
    $h = $html($empMix);
    check('income and tax are opening + run', [str_contains($h, '240,000.00'), str_contains($h, '5,800.00')], [true, true]);
    check('fund lines are opening + run', [str_contains($h, '2,100.00'), str_contains($h, '6,000.00')], [true, true]);
    check('footnote names the opening-balance date (B.E.)', str_contains($h, 'รวมยอดสะสมก่อนเข้าระบบ (YTD) ณ วันที่ 01/08/2569'), true);

    echo "=== YTD-only ===\n";
    $h = $html($empYtd);
    check('income and tax are the opening balance, funds with no sso/pvd are 0.00', [str_contains($h, '100,000.00'), str_contains($h, '2,000.00'), substr_count($h, '0.00') >= 2], [true, true, true]);
    check('footnote present', str_contains($h, 'ณ วันที่ 01/07/2569'), true);

    echo "=== PDF binary ===\n";
    foreach (['system-only' => $empSys, 'YTD + system' => $empMix, 'YTD-only' => $empYtd] as $label => $emp) {
        $r = $report->generate($ctx($emp), 'pdf');
        check("{$label}: non-empty %PDF with a file name", [str_starts_with($r['content'], '%PDF'), strlen($r['content']) > 1000, $r['mime_type'], str_ends_with($r['file_name'], '_2569.pdf')], [true, true, 'application/pdf', true]);
    }

    echo "=== validation ===\n";
    check('other company employee is not found', errorKey(fn() => $report->generate($ctx($empOther), 'pdf')), 'employee_not_found');
    check('unknown employee is not found', errorKey(fn() => $report->generate($ctx(999999999), 'pdf')), 'employee_not_found');
    check('employee with no runs and no opening balance', errorKey(fn() => $report->generate($ctx($empNone), 'pdf')), 'no_payment_records_for_year');
    check('a year with nothing', errorKey(fn() => $report->generate($ctx($empSys, 2570), 'pdf')), 'no_payment_records_for_year');
    check('year out of range', errorKey(fn() => $report->generate($ctx($empSys, 1999), 'pdf')), 'year_out_of_range');
    check('year missing', errorKey(fn() => $report->generate(['comp_id' => $compId, 'employee_id' => $empSys], 'pdf')), 'year_required');
    check('employee missing', errorKey(fn() => $report->generate(['comp_id' => $compId, 'year' => 2569], 'pdf')), 'employee_id_required');
    check('comp missing', errorKey(fn() => $report->generate(['year' => 2569, 'employee_id' => $empSys], 'pdf')), 'comp_id_required');
    check('only pdf', errorKey(fn() => $report->generate($ctx($empSys), 'txt')), 'unsupported_format');

    echo "=== amount in words ===\n";
    $words = (function (float $n) { return $this->thaiBahtText($n); });
    foreach ([[0, 'ศูนย์บาทถ้วน'], [1, 'หนึ่งบาทถ้วน'], [11, 'สิบเอ็ดบาทถ้วน'], [21, 'ยี่สิบเอ็ดบาทถ้วน'], [101, 'หนึ่งร้อยเอ็ดบาทถ้วน'], [2500.5, 'สองพันห้าร้อยบาทห้าสิบสตางค์'],
        [100000, 'หนึ่งแสนบาทถ้วน'], [1000000, 'หนึ่งล้านบาทถ้วน'], [1000001, 'หนึ่งล้านเอ็ดบาทถ้วน'], [12345678.25, 'สิบสองล้านสามแสนสี่หมื่นห้าพันหกร้อยเจ็ดสิบแปดบาทยี่สิบห้าสตางค์']] as [$n, $expected]) {
        check("baht text for {$n}", $words->call($report, (float)$n), $expected);
    }

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    echo $failures > 0 ? "SOME TESTS FAILED\n" : "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
