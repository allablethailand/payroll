<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/PndOneKorExporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * ภ.ง.ด.1ก annual summary — aggregates TH_PIT withheld per employee across every payroll run
 * in a calendar year. PDF/Excel formats are a human-readable summary of this system's own
 * data (fully our own layout, not an official form reproduction). The 'txt' format delegates
 * to PndOneKorExporter (2026-09-05, Phase 12 T071, rewritten against a real reference spec — see
 * that class's own docblock).
 *
 * 2026-09-05, Phase 12 T071 — 2 real correctness fixes made alongside the exporter rewrite (not
 * just a mechanical field-name update):
 *   1. `prefix` used to be the raw `employees.title` CODE ('mr'/'mrs'/'ms') passed straight
 *      through with no text mapping — meaning this Thai-language report's own PDF/Excel/txt
 *      outputs were ALL silently rendering literal English codes ("mr สมชาย ใจดี") instead of
 *      "นาย สมชาย ใจดี". Now mapped to Thai prefix text, same $prefixMap PndOneReport's own
 *      2026-08-29 build already established for the sibling monthly form — this report was the
 *      one holdout that never got that same fix.
 *   2. `tax_id` switched from `employees.tax_id_no` to `id_card_no` — the spec's own field 2 is
 *      explicitly "เลขประจำตัวประชาชน" (national ID card no.), the SAME column PndOneExporter/
 *      Sso110Exporter both use for this same phrase, not a separate taxpayer-ID concept.
 *
 * The PDF/Excel output IS the "ใบแนบ ภ.ง.ด.1ก" content: ภ.ง.ด.1ก itself is just a totals cover
 * page filed with the Revenue Department; the per-employee schedule (tax_id, name, total
 * income, tax withheld) that must accompany it is exactly the table this report already
 * produces. There is no separate ใบแนบ report/format — this is intentional, not an omission;
 * see the label below, which names this explicitly so it's discoverable in the Reports UI.
 *
 * Only includes runs in state approved/paid/locked (see PayrollReportDataModel::assertRunState)
 * — a draft run's numbers can still change and must not appear in a statutory summary.
 */
class PndOneKorSummaryReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use ExcelRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'TH_PND1K_SUMMARY';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'ภ.ง.ด.1ก สรุปประจำปี (พร้อมใบแนบรายบุคคล)', 'en' => 'PND.1K Annual Summary (incl. per-employee attachment)'];
    }

    // 2026-09-05, Phase 12 T071: was an unconditional `false` -- the underlying `txt` layout is
    // now confirmed against a real reference spec, see PndOneKorExporter's own docblock.
    public function isVerified(): bool {
        return true;
    }

    public function supportedFormats(): array {
        return ['pdf', 'excel', 'txt'];
    }

    /**
     * @param array $context { comp_id: int, year: int (พ.ศ., Thai Buddhist year) }
     */
    public function generate(array $context, string $format): array {
        // 2026-08-30, real bug found and fixed: this method was the last holdout in the Reports
        // module still throwing a plain InvalidArgumentException/RuntimeException -- every sibling
        // report (Kor20KorReport, PaymentVoucherReport, etc.) already migrated to LocalizedException
        // for the SAME 4 validations, so the exact same error_key names are reused here rather than
        // inventing new ones.
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['year']) || !is_numeric($context['year'])) {
            throw new LocalizedException('year (พ.ศ.) is required and must be numeric.', 'year_required');
        }
        $yearBe = (int)$context['year'];
        $currentYearBe = (int)date('Y') + 543;
        if ($yearBe < 2500 || $yearBe > $currentYearBe + 1) {
            throw new LocalizedException("year must be a valid Buddhist Era year (2500–{$currentYearBe}).", 'year_out_of_range', ['min' => 2500, 'max' => $currentYearBe]);
        }
        $yearAd = $yearBe - 543;

        $dataModel = new PayrollReportDataModel();
        $runs = $dataModel->getRunsInYear($compId, $yearAd, self::ALLOWED_STATES);
        if (empty($runs)) {
            $allowedLabel = implode('/', self::ALLOWED_STATES);
            throw new LocalizedException("No payroll runs in state {$allowedLabel} were found for B.E. {$yearBe}.", 'no_runs_in_state_for_year', ['states' => self::ALLOWED_STATES, 'year' => $yearBe]);
        }

        // 2026-09-05, Phase 12 T071: Thai prefix TEXT, not the raw title code -- see this class's
        // own top-of-file docblock for the real display bug this fixes.
        $prefixMap = ['mr' => 'นาย', 'mrs' => 'นาง', 'ms' => 'นางสาว'];
        $employees = []; // keyed by employee_id, accumulated across runs
        foreach ($runs as $run) {
            foreach ($dataModel->getRunDetails((int)$run['id']) as $detail) {
                $empId = (int)$detail['employee_id'];
                if (!isset($employees[$empId])) {
                    $employees[$empId] = [
                        'tax_id' => $this->decryptEmployeeField($detail, 'id_card_no') ?? '',
                        'prefix' => $prefixMap[$detail['title'] ?? ''] ?? '',
                        'first_name' => $detail['name_th'] ?? '',
                        'last_name' => $detail['surname_th'] ?? '',
                        'total_income' => 0.0,
                        'tax_withheld' => 0.0,
                    ];
                }
                $employees[$empId]['total_income'] += (float)$detail['gross_amount'];
                foreach ($detail['statutory_breakdown'] as $sItem) {
                    if ($sItem['code'] === 'TH_PIT') {
                        $employees[$empId]['tax_withheld'] += (float)$sItem['employee_amount'];
                    }
                }
            }
        }
        foreach ($employees as &$emp) {
            $emp['total_income'] = round($emp['total_income'], 2);
            $emp['tax_withheld'] = round($emp['tax_withheld'], 2);
        }
        unset($emp);

        $employeeList = array_values($employees);

        if ($format === 'txt') {
            $exporter = new PndOneKorExporter();
            $content = $exporter->generate(['period' => ['tax_year' => $yearBe], 'employees' => $employeeList]);
            return ['content' => $content, 'file_name' => "PND1K_Summary_{$yearBe}.txt", 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประจำตัวผู้เสียภาษี', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'เงินได้รวมทั้งปี', 'ภาษีหัก ณ ที่จ่ายรวม'];
            $rows = array_map(fn($e) => [$e['tax_id'], $e['prefix'], $e['first_name'], $e['last_name'], $e['total_income'], $e['tax_withheld']], $employeeList);
            $content = $this->renderExcelFromRows($headers, $rows, "PND1K {$yearBe}");
            return ['content' => $content, 'file_name' => "PND1K_Summary_{$yearBe}.xlsx", 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $company = $dataModel->getCompany($compId);
        $companyName = $company['local_name'] ?? $company['company_legal_name'] ?? '';
        $rowsHtml = '';
        $totalIncome = 0.0;
        $totalTax = 0.0;
        foreach ($employeeList as $e) {
            $totalIncome += $e['total_income'];
            $totalTax += $e['tax_withheld'];
            $rowsHtml .= '<tr>'
                . '<td>' . htmlspecialchars($e['tax_id']) . '</td>'
                . '<td>' . htmlspecialchars($e['prefix'] . ' ' . $e['first_name'] . ' ' . $e['last_name']) . '</td>'
                . '<td class="amount">' . number_format($e['total_income'], 2) . '</td>'
                . '<td class="amount">' . number_format($e['tax_withheld'], 2) . '</td>'
                . '</tr>';
        }
        $html = $this->buildPdfHtml($companyName, $yearBe, $rowsHtml, $totalIncome, $totalTax);
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return ['content' => $content, 'file_name' => "PND1K_Summary_{$yearBe}.pdf", 'mime_type' => 'application/pdf'];
    }

    private function buildPdfHtml(string $companyName, int $yearBe, string $rowsHtml, float $totalIncome, float $totalTax): string {
        return <<<HTML
<html>
<head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
h1 { font-size: 16px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.amount { text-align: right; }
tfoot td { font-weight: bold; }
</style></head>
<body>
<h1>{$companyName}</h1>
<div>สรุป ภ.ง.ด.1ก ประจำปี {$yearBe}</div>
<table>
<thead><tr><th>เลขประจำตัวผู้เสียภาษี</th><th>ชื่อ-สกุล</th><th>เงินได้รวมทั้งปี</th><th>ภาษีหัก ณ ที่จ่ายรวม</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td colspan="2">รวม</td><td class="amount">{$this->fmt($totalIncome)}</td><td class="amount">{$this->fmt($totalTax)}</td></tr></tfoot>
</table>
</body>
</html>
HTML;
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
