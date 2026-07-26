<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * DRAFT — กท.20ก. (annual provident fund contribution report), aggregating TH_PVD employee
 * + employer contributions per employee across every payroll run in a calendar year.
 * NOT researched against any official PVD fund-manager submission format — PDF/Excel only,
 * a human-readable summary of this system's own data, not a form reproduction. Requires the
 * run to be at least Approved (same as other statutory reports).
 */
class Kor20KorReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use ExcelRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'TH_KOR20KOR';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'กท.20ก. สรุปกองทุนสำรองเลี้ยงชีพประจำปี', 'en' => 'Kor.20Kor Annual PVD Summary'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['pdf', 'excel'];
    }

    /**
     * @param array $context { comp_id: int, year: int (พ.ศ.) }
     */
    public function generate(array $context, string $format): array {
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

        $employees = [];
        foreach ($runs as $run) {
            foreach ($dataModel->getRunDetails((int)$run['id']) as $detail) {
                $empId = (int)$detail['employee_id'];
                if (!isset($employees[$empId])) {
                    $employees[$empId] = [
                        'employee_no' => $detail['employee_no'],
                        'name' => trim(($detail['title'] ?? '') . ' ' . ($detail['name_th'] ?? '') . ' ' . ($detail['surname_th'] ?? '')),
                        'employee_contribution' => 0.0,
                        'employer_contribution' => 0.0,
                    ];
                }
                foreach ($detail['statutory_breakdown'] as $item) {
                    if ($item['code'] === 'TH_PVD') {
                        $employees[$empId]['employee_contribution'] += (float)$item['employee_amount'];
                        $employees[$empId]['employer_contribution'] += (float)$item['employer_amount'];
                    }
                }
            }
        }
        // Only employees who actually had a PVD contribution belong in this report.
        $employeeList = array_values(array_filter($employees, fn($e) => $e['employee_contribution'] > 0 || $e['employer_contribution'] > 0));
        if (empty($employeeList)) {
            throw new RuntimeException("No employees had a provident fund contribution in B.E. {$yearBe}.");
        }
        foreach ($employeeList as &$e) {
            $e['employee_contribution'] = round($e['employee_contribution'], 2);
            $e['employer_contribution'] = round($e['employer_contribution'], 2);
            $e['total'] = round($e['employee_contribution'] + $e['employer_contribution'], 2);
        }
        unset($e);

        if ($format === 'excel') {
            $headers = ['รหัสพนักงาน', 'ชื่อ-สกุล', 'เงินสะสมลูกจ้าง', 'เงินสมทบนายจ้าง', 'รวม'];
            $rows = array_map(fn($e) => [$e['employee_no'], $e['name'], $e['employee_contribution'], $e['employer_contribution'], $e['total']], $employeeList);
            $content = $this->renderExcelFromRows($headers, $rows, "Kor20Kor {$yearBe}");
            return ['content' => $content, 'file_name' => "Kor20Kor_{$yearBe}.xlsx", 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $company = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($company['local_name'] ?? $company['company_legal_name'] ?? '');
        $rowsHtml = '';
        $totalEmp = 0.0;
        $totalEr = 0.0;
        foreach ($employeeList as $e) {
            $totalEmp += $e['employee_contribution'];
            $totalEr += $e['employer_contribution'];
            $rowsHtml .= '<tr><td>' . htmlspecialchars($e['employee_no']) . '</td><td>' . htmlspecialchars($e['name']) . '</td><td class="amount">' . number_format($e['employee_contribution'], 2) . '</td><td class="amount">' . number_format($e['employer_contribution'], 2) . '</td><td class="amount">' . number_format($e['total'], 2) . '</td></tr>';
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
<div>กท.20ก. สรุปกองทุนสำรองเลี้ยงชีพประจำปี {$yearBe}</div>
<table>
<thead><tr><th>รหัสพนักงาน</th><th>ชื่อ-สกุล</th><th>เงินสะสมลูกจ้าง</th><th>เงินสมทบนายจ้าง</th><th>รวม</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td colspan="2">รวม</td><td class="amount">{$this->fmt($totalEmp)}</td><td class="amount">{$this->fmt($totalEr)}</td><td class="amount">{$this->fmt($totalEmp + $totalEr)}</td></tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return ['content' => $content, 'file_name' => "Kor20Kor_{$yearBe}.pdf", 'mime_type' => 'application/pdf'];
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
