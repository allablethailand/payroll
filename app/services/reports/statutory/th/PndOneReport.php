<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/PndOneExporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * ภ.ง.ด.1 monthly withholding return — one payroll run = one month's submission (unlike
 * PndOneKorSummaryReport, which aggregates a whole calendar year for ภ.ง.ด.1ก). 'txt' format
 * delegates to the DRAFT/unverified PndOneExporter. Requires the run to be at least Approved.
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

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['pdf', 'excel', 'txt'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int }
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
        $details = $dataModel->getRunDetails($runId);

        $employeeList = [];
        foreach ($details as $d) {
            $pit = 0.0;
            foreach ($d['statutory_breakdown'] as $item) {
                if ($item['code'] === 'TH_PIT') {
                    $pit = (float)$item['employee_amount'];
                }
            }
            $employeeList[] = [
                'tax_id' => $this->decryptEmployeeField($d, 'tax_id_no') ?? '',
                'prefix' => $d['title'] ?? '',
                'first_name' => $d['name_th'] ?? '',
                'last_name' => $d['surname_th'] ?? '',
                'total_income' => round((float)$d['gross_amount'], 2),
                'tax_withheld' => round($pit, 2),
            ];
        }
        if (empty($employeeList)) {
            throw new LocalizedException('This payroll run has no calculated employees yet. Recalculate it first.', 'run_no_calculated_employees');
        }

        $yearBe = (int)date('Y', strtotime($run['period_start_date'])) + 543;
        $monthNum = (int)date('n', strtotime($run['period_start_date']));

        if ($format === 'txt') {
            $exporter = new PndOneExporter();
            $content = $exporter->generate(['period' => ['tax_year' => $yearBe, 'tax_month' => $monthNum], 'employees' => $employeeList]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['period' => ['tax_year' => $yearBe, 'tax_month' => $monthNum]]), 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประจำตัวผู้เสียภาษี', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'เงินได้เดือนนี้', 'ภาษีหัก ณ ที่จ่าย'];
            $rows = array_map(fn($e) => [$e['tax_id'], $e['prefix'], $e['first_name'], $e['last_name'], $e['total_income'], $e['tax_withheld']], $employeeList);
            $content = $this->renderExcelFromRows($headers, $rows, "PND1 {$yearBe}-{$monthNum}");
            return ['content' => $content, 'file_name' => sprintf('PND1_%04d%02d.xlsx', $yearBe, $monthNum), 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $company = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($company['local_name'] ?? $company['company_legal_name'] ?? '');
        $rowsHtml = '';
        $totalIncome = 0.0;
        $totalTax = 0.0;
        foreach ($employeeList as $e) {
            $totalIncome += $e['total_income'];
            $totalTax += $e['tax_withheld'];
            $rowsHtml .= '<tr><td>' . htmlspecialchars($e['tax_id']) . '</td><td>' . htmlspecialchars($e['prefix'] . ' ' . $e['first_name'] . ' ' . $e['last_name']) . '</td><td class="amount">' . number_format($e['total_income'], 2) . '</td><td class="amount">' . number_format($e['tax_withheld'], 2) . '</td></tr>';
        }
        $html = <<<HTML
<html><head><style>
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.amount { text-align: right; }
tfoot td { font-weight: bold; }
</style></head><body>
<h1>{$companyName}</h1>
<div>ภ.ง.ด.1 ประจำงวด {$monthNum}/{$yearBe}</div>
<table>
<thead><tr><th>เลขประจำตัวผู้เสียภาษี</th><th>ชื่อ-สกุล</th><th>เงินได้เดือนนี้</th><th>ภาษีหัก ณ ที่จ่าย</th></tr></thead>
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
