<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../ExcelRendererTrait.php';
require_once __DIR__ . '/../PdfRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * 2026-09-07, explicit request: "ส่วนของรายงานการหัก ตอนนี้หักเยอะมาก อยากให้แต่ละรอบกดแล้ว ให้ติ๊กได้ว่า
 * ต้องการรายงานการหักอะไรบ้าง โดย Default คือเลือกทั้งหมดจากที่มีการหักในรอบนั้นๆ และ export เลือกได้ว่า
 * excel หรือ pdf ยกตัวอย่างเช่น รอบนี้ต้องการรายงานการหักเงินเข้างานสาย เพื่อ Export ส่งให้บัญชีไปทำงานต่อ" --
 * a per-run deduction export where the ADMIN, not the report, decides which deduction types are
 * included (e.g. only "late arrival" for a given run, to hand accounting a focused list), unlike
 * every other deduction-shaped report in this app (StudentLoanReport, PayrollRegisterReport's own
 * deduction columns) which always includes every deduction line unconditionally.
 *
 * `internal` report type (same as PayrollRegisterReport) -- deliberately NOT `statutory`/`payment`:
 * this has no government form/bank spec to match, it's an ad-hoc accounting extract, so it's
 * allowed on any of the SAME states every other cycle report in this app requires (approved/paid/
 * locked -- see ALLOWED_STATES) rather than PayrollRegisterReport's own "any state including draft"
 * (a still-editable draft's deduction figures aren't final enough to hand to accounting yet).
 *
 * Column set is DATA-DRIVEN, same "one column per distinct code actually found" shape
 * PayrollRegisterReport already established for its own earning/deduction columns -- except here
 * the caller (ReportsController::deductionTypesForRun(), the config picker's own data source) can
 * additionally narrow which of those discovered codes actually get INCLUDED via
 * `context['deduction_codes']` (comma-string or array; empty/absent = every code found in the run,
 * matching the explicit "Default คือเลือกทั้งหมด" requirement). An employee with zero amount across
 * every SELECTED code is dropped from the output entirely (not shown as an all-zero row) -- the
 * whole point of this report is a focused list for accounting, not a full-roster dump.
 */
class DeductionBreakdownReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;
    use PdfRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'DEDUCTION_BREAKDOWN';
    }

    public function reportType(): string {
        return 'internal';
    }

    public function label(): array {
        return ['th' => 'รายงานรายการหักเงิน (เลือกประเภทได้)', 'en' => 'Deduction Breakdown Report'];
    }

    public function isVerified(): bool {
        return true;
    }

    public function supportedFormats(): array {
        return ['excel', 'pdf'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int, deduction_codes?: string|string[], language?: 'th'|'en' }
     *   deduction_codes omitted/empty = every deduction code found in the run.
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['run_id']) || !is_numeric($context['run_id']) || (int)$context['run_id'] <= 0) {
            throw new LocalizedException('run_id is required and must be a positive integer.', 'run_id_required');
        }
        $runId = (int)$context['run_id'];

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);

        $requestedCodes = $context['deduction_codes'] ?? [];
        if (is_string($requestedCodes)) {
            $requestedCodes = array_filter(array_map('trim', explode(',', $requestedCodes)), fn($c) => $c !== '');
        }
        $requestedSet = !empty($requestedCodes) ? array_flip($requestedCodes) : null; // null = every code found

        $lang = ($context['language'] ?? 'th') === 'en' ? 'en' : 'th';
        $details = $dataModel->getRunDetails($runId);

        $codeMeta = []; // code => ['name_th'=>.., 'name_en'=>..], in first-encounter order
        $employeeRows = [];
        foreach ($details as $d) {
            $perCode = [];
            foreach (($d['deduction_breakdown'] ?? []) as $line) {
                $code = $line['code'] ?? null;
                if ($code === null) continue;
                if ($requestedSet !== null && !isset($requestedSet[$code])) continue;
                $amount = (float)($line['amount'] ?? 0);
                if ($amount == 0.0) continue;
                if (!isset($codeMeta[$code])) {
                    $codeMeta[$code] = ['name_th' => $line['name_th'] ?? $code, 'name_en' => $line['name_en'] ?? $code];
                }
                $perCode[$code] = ($perCode[$code] ?? 0.0) + $amount;
            }
            if (empty($perCode)) {
                continue;
            }
            $employeeRows[] = [
                'employee_no' => $d['employee_no'],
                'name' => $this->employeeDisplayName($d, $lang),
                'department' => ($lang === 'en' ? ($d['department_name_en'] ?? $d['department_name_th']) : $d['department_name_th']) ?? '',
                'amounts' => $perCode,
                'total' => array_sum($perCode),
            ];
        }
        if (empty($employeeRows)) {
            throw new LocalizedException('No employees had any of the selected deductions in this payroll run.', 'deduction_breakdown_no_data');
        }

        $codes = array_keys($codeMeta);
        $labelFor = function (string $code) use ($codeMeta, $lang): string {
            return $codeMeta[$code]['name_' . $lang] ?? $codeMeta[$code]['name_th'] ?? $code;
        };

        if ($format === 'excel') {
            $headers = array_merge(
                [
                    $lang === 'en' ? 'Employee No.' : 'รหัสพนักงาน',
                    $lang === 'en' ? 'Name' : 'ชื่อ-สกุล',
                    $lang === 'en' ? 'Department' : 'แผนก',
                ],
                array_map($labelFor, $codes),
                [$lang === 'en' ? 'Total' : 'รวม']
            );
            $excelRows = [];
            foreach ($employeeRows as $r) {
                $row = [$r['employee_no'], $r['name'], $r['department']];
                foreach ($codes as $code) {
                    $row[] = round($r['amounts'][$code] ?? 0.0, 2);
                }
                $row[] = round($r['total'], 2);
                $excelRows[] = $row;
            }
            $content = $this->renderExcelFromRows($headers, $excelRows, "Deductions Run{$runId}");
            return [
                'content' => $content,
                'file_name' => "DeductionBreakdown_Run{$runId}.xlsx",
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        // pdf
        $company = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($company['local_name'] ?? $company['company_legal_name'] ?? '');
        $period = $run['period_start_date'] . ' - ' . $run['period_end_date'];

        $headerCells = '<th>' . ($lang === 'en' ? 'Employee No.' : 'รหัสพนักงาน') . '</th><th>' . ($lang === 'en' ? 'Name' : 'ชื่อ-สกุล') . '</th>';
        foreach ($codes as $code) {
            $headerCells .= '<th>' . htmlspecialchars($labelFor($code)) . '</th>';
        }
        $headerCells .= '<th>' . ($lang === 'en' ? 'Total' : 'รวม') . '</th>';

        $rowsHtml = '';
        $colTotals = array_fill_keys($codes, 0.0);
        $grandTotal = 0.0;
        foreach ($employeeRows as $r) {
            $rowsHtml .= '<tr><td>' . htmlspecialchars((string)$r['employee_no']) . '</td><td>' . htmlspecialchars($r['name']) . '</td>';
            foreach ($codes as $code) {
                $amt = $r['amounts'][$code] ?? 0.0;
                $colTotals[$code] += $amt;
                $rowsHtml .= '<td class="amount">' . ($amt > 0 ? number_format($amt, 2) : '-') . '</td>';
            }
            $grandTotal += $r['total'];
            $rowsHtml .= '<td class="amount">' . number_format($r['total'], 2) . '</td></tr>';
        }
        $footerCells = '<td colspan="2">' . ($lang === 'en' ? 'Total' : 'รวม') . '</td>';
        foreach ($codes as $code) {
            $footerCells .= '<td class="amount">' . number_format($colTotals[$code], 2) . '</td>';
        }
        $footerCells .= '<td class="amount">' . number_format($grandTotal, 2) . '</td>';

        $title = $lang === 'en' ? 'Deduction Breakdown Report' : 'รายงานรายการหักเงิน';
        $html = <<<HTML
<html><head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 13px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.amount { text-align: right; }
tfoot td { font-weight: bold; }
</style></head><body>
<h1>{$companyName}</h1>
<div>{$title} &mdash; {$period}</div>
<table>
<thead><tr>{$headerCells}</tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr>{$footerCells}</tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return [
            'content' => $content,
            'file_name' => "DeductionBreakdown_Run{$runId}.pdf",
            'mime_type' => 'application/pdf',
        ];
    }
}
