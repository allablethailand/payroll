<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/PndOneExporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../../models/StatutoryFormatVersionModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * ภ.ง.ด.1 monthly withholding return — one payroll run = one month's submission (unlike
 * PndOneKorSummaryReport, which aggregates a whole calendar year for ภ.ง.ด.1ก). 'txt' format
 * delegates to PndOneExporter. Requires the run to be at least Approved.
 *
 * 2026-09-05, Phase 12 T071 — PndOneExporter rewritten against a confirmed reference spec (byte-
 * exact sample + PHP reference implementation the user supplied, see that class's own docblock).
 * The new 11-field layout needs NO address fields at all — the pre-2026-09-05 version's own
 * flagged address gap (no structured house-no./moo/building/soi/road columns anywhere in this
 * app, only free-text lines) is now moot, not fixed; the address-building code below was removed
 * accordingly, not left as unreachable dead code.
 *
 * 2026-08-29, explicit follow-up: "รองรับ 2 ภาษาเหมือนกัน...เช็คตรงข้อมูลบริษัทมี Filed เก็บครบหรือยัง" --
 * optional context.language ('th'/'en', default 'th', same convention every other report this
 * project added the same week uses) covers employee first/last name and prefix (employees.title
 * mapped to นาย/นาง/นางสาว or Mr./Mrs./Ms. here in this class, not stored as a separate value).
 */
class PndOneReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use ExcelRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'TH_PND1';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'ภ.ง.ด.1 (รายเดือน)', 'en' => 'PND.1 (Monthly)'];
    }

    // 2026-09-05, Phase 12 T071: was an unconditional `false` -- the underlying `txt` layout is
    // now confirmed against a real reference spec, see PndOneExporter's own docblock.
    public function isVerified(): bool {
        return true;
    }

    public function supportedFormats(): array {
        return ['pdf', 'excel', 'txt'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int } OR { comp_id: int, year: int (พ.ศ.), month: int }
     *        (2026-09-04, Backlog Phase 10, T060 Step D) -- run_id is the ORIGINAL, unchanged path
     *        (one payroll run = one month's submission, correct as-is for a monthly
     *        payroll_frequency company, where 1 run genuinely IS the whole month). year+month is
     *        the NEW path: aggregates EVERY settled run whose period falls in that calendar month
     *        (needed for a non-monthly frequency -- weekly/bi_weekly/semi_monthly/daily -- where a
     *        real Thai monthly PND1 filing must sum every employee's withholding across all of that
     *        month's runs, not just one). Both paths share ONE accumulation loop below, keyed by
     *        employee_id -- for run_id (exactly 1 run), each employee appears in exactly 1 run
     *        detail row, so accumulating produces byte-identical numbers to the pre-T060 direct
     *        per-detail-row build; this is what guarantees the existing run_id path is unaffected.
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        $requestedLanguage = $context['language'] ?? 'th';
        $language = in_array($requestedLanguage, ['th', 'en'], true) ? $requestedLanguage : 'th';
        $dataModel = new PayrollReportDataModel();

        $hasRunId = isset($context['run_id']) && is_numeric($context['run_id']) && (int)$context['run_id'] > 0;
        $hasYearMonth = isset($context['year']) && is_numeric($context['year']) && isset($context['month']) && is_numeric($context['month']);

        if ($hasRunId) {
            $runId = (int)$context['run_id'];
            $run = $dataModel->getRun($runId, $compId);
            if (!$run) {
                throw new LocalizedException('Payroll run not found.', 'run_not_found');
            }
            $dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);
            $runsForDetails = [$run];
            $yearBe = (int)date('Y', strtotime($run['period_start_date'])) + 543;
            $monthNum = (int)date('n', strtotime($run['period_start_date']));
        } elseif ($hasYearMonth) {
            $yearBe = (int)$context['year'];
            $monthNum = (int)$context['month'];
            if ($monthNum < 1 || $monthNum > 12) {
                throw new LocalizedException('month must be between 1 and 12.', 'month_out_of_range');
            }
            $currentYearBe = (int)date('Y') + 543;
            if ($yearBe < 2500 || $yearBe > $currentYearBe + 1) {
                throw new LocalizedException("year must be a valid Buddhist Era year (2500–{$currentYearBe}).", 'year_out_of_range', ['min' => 2500, 'max' => $currentYearBe]);
            }
            $yearAd = $yearBe - 543;
            $runsForDetails = $dataModel->getRunsInMonth($compId, $yearAd, $monthNum, self::ALLOWED_STATES);
            if (empty($runsForDetails)) {
                $allowedLabel = implode('/', self::ALLOWED_STATES);
                throw new LocalizedException("No payroll runs in state {$allowedLabel} were found for {$monthNum}/{$yearBe}.", 'no_runs_in_state_for_month', ['states' => self::ALLOWED_STATES, 'year' => $yearBe, 'month' => $monthNum]);
            }
        } else {
            throw new LocalizedException('Either run_id or year+month is required.', 'run_id_or_year_month_required');
        }

        // 2026-08-29: PND1's own expected prefix TEXT (นาย/นาง/นางสาว), distinct from SSO110's
        // own numeric-code convention for the same employees.title column -- see this class's
        // own docblock.
        $prefixMap = $language === 'en'
            ? ['mr' => 'Mr.', 'mrs' => 'Mrs.', 'ms' => 'Ms.']
            : ['mr' => 'นาย', 'mrs' => 'นาง', 'ms' => 'นางสาว'];

        $employees = []; // keyed by employee_id, accumulated across every run in $runsForDetails
        $lastPaymentDate = null;
        foreach ($runsForDetails as $r) {
            $lastPaymentDate = $r['payment_date'] ?? $lastPaymentDate;
            foreach ($dataModel->getRunDetails((int)$r['id']) as $d) {
                $empId = (int)$d['employee_id'];
                $pit = 0.0;
                foreach ($d['statutory_breakdown'] as $item) {
                    if ($item['code'] === 'TH_PIT') {
                        $pit = (float)$item['employee_amount'];
                    }
                }
                if (!isset($employees[$empId])) {
                    $employees[$empId] = [
                        'id_card_no' => $this->decryptEmployeeField($d, 'id_card_no') ?? '',
                        'prefix' => $prefixMap[$d['title'] ?? ''] ?? '',
                        'first_name' => $language === 'en' ? ($d['name_en'] ?? '') : ($d['name_th'] ?? ''),
                        'last_name' => $language === 'en' ? ($d['surname_en'] ?? '') : ($d['surname_th'] ?? ''),
                        'total_income' => 0.0,
                        'tax_withheld' => 0.0,
                    ];
                }
                $employees[$empId]['total_income'] += (float)$d['gross_amount'];
                $employees[$empId]['tax_withheld'] += $pit;
            }
        }
        foreach ($employees as &$emp) {
            $emp['total_income'] = round($emp['total_income'], 2);
            $emp['tax_withheld'] = round($emp['tax_withheld'], 2);
        }
        unset($emp);
        $employeeList = array_values($employees);
        if (empty($employeeList)) {
            throw new LocalizedException('This payroll run has no calculated employees yet. Recalculate it first.', 'run_no_calculated_employees');
        }

        if ($format === 'txt') {
            // 2026-08-29 -- see Sso110Report's own comment on the same call pattern.
            $versionCode = (new StatutoryFormatVersionModel())->resolveVersionCode($compId, $this->code());
            $exporter = new PndOneExporter();
            // 2026-09-04, T060 Step D: for the month-aggregated path (multiple runs), there is no
            // single canonical payment_date -- uses the LATEST run's own payment_date in that
            // month as the representative value (the most recent real disbursement within the
            // filing period), same value the run_id path already used when there was only 1 run.
            $periodContext = ['tax_year' => $yearBe, 'tax_month' => $monthNum, 'payment_date' => $lastPaymentDate];
            $content = $exporter->generate(['period' => $periodContext, 'employees' => $employeeList, 'version_code' => $versionCode]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['period' => $periodContext]), 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประจำตัวประชาชน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'เงินได้เดือนนี้', 'ภาษีหัก ณ ที่จ่าย'];
            $rows = array_map(fn($e) => [$e['id_card_no'], $e['prefix'], $e['first_name'], $e['last_name'], $e['total_income'], $e['tax_withheld']], $employeeList);
            $content = $this->renderExcelFromRows($headers, $rows, "PND1 {$yearBe}-{$monthNum}");
            return ['content' => $content, 'file_name' => sprintf('PND1_%04d%02d.xlsx', $yearBe, $monthNum), 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $company = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($language === 'en' ? ($company['company_legal_name'] ?? ($company['local_name'] ?? '')) : ($company['local_name'] ?? ($company['company_legal_name'] ?? '')));
        $rowsHtml = '';
        $totalIncome = 0.0;
        $totalTax = 0.0;
        foreach ($employeeList as $e) {
            $totalIncome += $e['total_income'];
            $totalTax += $e['tax_withheld'];
            $rowsHtml .= '<tr><td>' . htmlspecialchars($e['id_card_no']) . '</td><td>' . htmlspecialchars($e['prefix'] . ' ' . $e['first_name'] . ' ' . $e['last_name']) . '</td><td class="amount">' . number_format($e['total_income'], 2) . '</td><td class="amount">' . number_format($e['tax_withheld'], 2) . '</td></tr>';
        }
        $html = <<<HTML
<html><head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.amount { text-align: right; }
tfoot td { font-weight: bold; }
</style></head><body>
<h1>{$companyName}</h1>
<div>ภ.ง.ด.1 ประจำงวด {$monthNum}/{$yearBe}</div>
<table>
<thead><tr><th>เลขประจำตัวประชาชน</th><th>ชื่อ-สกุล</th><th>เงินได้เดือนนี้</th><th>ภาษีหัก ณ ที่จ่าย</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td colspan="2">รวม</td><td class="amount">{$this->fmt($totalIncome)}</td><td class="amount">{$this->fmt($totalTax)}</td></tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return ['content' => $content, 'file_name' => sprintf('PND1_%04d%02d.pdf', $yearBe, $monthNum), 'mime_type' => 'application/pdf'];
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
