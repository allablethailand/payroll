<?php
/**
 * Integration test: the P.N.D.1 Kor annual summary (TH_PND1K_SUMMARY: txt / Excel / PDF) reads
 * AnnualIncomeSummaryModel::getCompanyAnnualSummary(), so every output shows system runs + the imported opening YTD balance
 * as ONE row per employee. There is no 50 Tawi generator in this codebase to test (WHT_CERT is config-only).
 * Runs inside a transaction that is always rolled back, on a throwaway company.
 *
 * Run with: php tests/tax_exporter_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/services/reports/ReportRegistry.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

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

try {
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:n, :n, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')")->execute([':n' => 'TXE Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $insEmp = $pdo->prepare("INSERT INTO employees (comp_id, employee_no, title, name_th, surname_th, id_card_no, id_card_no_hash, key_version, data_source)
        VALUES (:c, :no, :t, :n, :s, :enc, :h, :kv, 'import')");
    $newEmp = function (string $no, string $title, string $name, string $idCard) use ($pdo, $insEmp, $compId): int {
        $insEmp->execute([':c' => $compId, ':no' => $no, ':t' => $title, ':n' => $name, ':s' => 'ทดสอบ', ':enc' => EncryptionService::encrypt($idCard)['value'],
            ':h' => EncryptionService::hash($idCard), ':kv' => EncryptionService::currentKeyVersion()]);
        return (int)$pdo->lastInsertId();
    };
    $insRun = $pdo->prepare("INSERT INTO payroll_runs (comp_id, run_name, period_start_date, period_end_date, payment_date, state, status) VALUES (:c, :n, :ps, :pe, :pd, :st, 'active')");
    $insDetail = $pdo->prepare("INSERT INTO payroll_run_details (run_id, employee_id, gross_amount, taxable_gross_amount, total_deduction_amount, net_amount, statutory_breakdown)
        VALUES (:r, :e, :g, :tg, 0, :g, :sb)");
    $addRun = function (int $empId, string $paymentDate, string $state, float $gross, float $taxable, float $pit) use ($pdo, $insRun, $insDetail, $compId): void {
        $insRun->execute([':c' => $compId, ':n' => 'TXE_' . uniqid(), ':ps' => substr($paymentDate, 0, 8) . '01', ':pe' => $paymentDate, ':pd' => $paymentDate, ':st' => $state]);
        $insDetail->execute([':r' => (int)$pdo->lastInsertId(), ':e' => $empId, ':g' => $gross, ':tg' => $taxable, ':sb' => json_encode([['code' => 'TH_PIT', 'employee_amount' => $pit]])]);
    };
    $insOpening = $pdo->prepare("INSERT INTO employee_ytd_opening_balances (comp_id, employee_id, tax_year, as_of_date, periods_paid, ytd_taxable_gross, ytd_pit_withheld)
        VALUES (:c, :e, :y, :a, :p, :g, :t)");

    $idSys = '1111111111111'; $idMix = '2222222222222'; $idYtd = '3333333333333';
    $empSys = $newEmp('TXE-1', 'mr', 'ซิสเต็ม', $idSys);
    $empMix = $newEmp('TXE-2', 'ms', 'ผสม', $idMix);
    $empYtd = $newEmp('TXE-3', 'mrs', 'ยอดเปิด', $idYtd);

    $addRun($empSys, '2026-01-31', 'approved', 30000, 28000, 1000);
    $addRun($empSys, '2026-02-28', 'paid', 30000, 30000, 1100);
    $addRun($empSys, '2026-03-31', 'draft', 99999, 99999, 9999);                 // never counted
    $addRun($empMix, '2026-08-31', 'approved', 30000, 30000, 800);
    $insOpening->execute([':c' => $compId, ':e' => $empMix, ':y' => 2026, ':a' => '2026-08-01', ':p' => 7, ':g' => 210000, ':t' => 5000]);
    $insOpening->execute([':c' => $compId, ':e' => $empYtd, ':y' => 2026, ':a' => '2026-07-01', ':p' => 6, ':g' => 100000, ':t' => 2000]);

    $report = ReportRegistry::get('TH_PND1K_SUMMARY');
    $ctx = ['comp_id' => $compId, 'year' => 2026 + 543];

    echo "=== txt ===\n";
    $txt = $report->generate($ctx, 'txt');
    $byId = [];
    $lineCount = 0;
    foreach (explode("\r\n", rtrim($txt['content'], "\r\n")) as $line) {
        $f = explode('|', $line);
        $byId[$f[1]] = $f;
        $lineCount++;
    }
    check('one line per employee, YTD-only employee included', [$lineCount, count($byId)], [3, 3]);
    check('system-only: taxable income and tax from the finalized runs only', [$byId[$idSys][8], $byId[$idSys][9]], ['58000.00', '2100.00']);
    check('YTD + system: opening balance added to the run', [$byId[$idMix][8], $byId[$idMix][9]], ['240000.00', '5800.00']);
    check('YTD-only: just the opening balance', [$byId[$idYtd][8], $byId[$idYtd][9]], ['100000.00', '2000.00']);
    check('Thai prefix text, not the title code', [iconv('TIS-620', 'UTF-8', $byId[$idMix][2]), iconv('TIS-620', 'UTF-8', $byId[$idYtd][2])], ['นางสาว', 'นาง']);

    echo "=== Excel ===\n";
    $xlsx = $report->generate($ctx, 'excel');
    $tmp = tempnam(sys_get_temp_dir(), 'txe');
    file_put_contents($tmp, $xlsx['content']);
    $rows = IOFactory::load($tmp)->getActiveSheet()->toArray(null, true, true, false);
    unlink($tmp);
    $dataRows = array_slice($rows, 1);
    $excelById = [];
    foreach ($dataRows as $r) { $excelById[(string)$r[0]] = $r; }
    check('Excel: one row per employee', [count($dataRows), count($excelById)], [3, 3]);
    check('Excel: consolidated income / tax per employee', [
        round((float)$excelById[$idMix][4], 2), round((float)$excelById[$idMix][5], 2),
        round((float)$excelById[$idYtd][4], 2), round((float)$excelById[$idSys][5], 2),
    ], [240000.0, 5800.0, 100000.0, 2100.0]);

    echo "=== PDF ===\n";
    $pdf = $report->generate($ctx, 'pdf');
    check('PDF is generated', str_starts_with($pdf['content'], '%PDF'), true);
    $html = (function () { return $this->buildPdfHtml('Co', 2569, '<tr></tr>', 1.0, 1.0, '<div>* NOTE</div>'); })->call($report);
    check('PDF template carries the YTD audit note slot', str_contains($html, '* NOTE'), true);

    echo "=== company with no opening balance ===\n";
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:n, :n, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')")->execute([':n' => 'TXE Plain Co ' . uniqid()]);
    $plainComp = (int)$pdo->lastInsertId();
    $threw = null;
    try { $report->generate(['comp_id' => $plainComp, 'year' => 2569], 'txt'); } catch (LocalizedException $e) { $threw = $e->getErrorKey(); }
    check('no runs and no opening balance still fails with the existing error key', $threw, 'no_runs_in_state_for_year');
    check('other years are separate', (function () use ($report, $compId) {
        try { $report->generate(['comp_id' => $compId, 'year' => 2027 + 543], 'txt'); return 'generated'; } catch (LocalizedException $e) { return $e->getErrorKey(); }
    })(), 'no_runs_in_state_for_year');

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
