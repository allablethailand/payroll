<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/Sso110Exporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';

/**
 * สปส.1-10 monthly contribution report — one payroll run = one month's submission. Delegates
 * 'txt' format to the DRAFT/unverified Sso110Exporter from the Tax & Statutory export module
 * (see that class's docblock: field layout from a third-party blog, not the official SSO
 * spec, and SSO changed this form's layout 2026-01-01 which may supersede it entirely).
 * 'pdf'/'excel' are a human-readable summary of this system's own data, not a form
 * reproduction. Requires the run to be Approved/Paid/Locked, same as other statutory reports.
 */
class Sso110Report implements ReportGeneratorInterface {
    use ExcelRendererTrait;
    use PdfRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'TH_SSO110';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'สปส.1-10 (นำส่งเงินสมทบ)', 'en' => 'SSO 1-10 (Monthly Contribution)'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['txt', 'excel', 'pdf'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new InvalidArgumentException('comp_id is required.');
        }
        if (!isset($context['run_id']) || !is_numeric($context['run_id']) || (int)$context['run_id'] <= 0) {
            throw new InvalidArgumentException('run_id is required and must be a positive integer.');
        }
        $runId = (int)$context['run_id'];

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new RuntimeException('Payroll run not found.');
        }
        $stateError = $dataModel->assertRunState($run, self::ALLOWED_STATES);
        if ($stateError !== null) {
            throw new RuntimeException($stateError);
        }
        $details = $dataModel->getRunDetails($runId);

        $employees = [];
        foreach ($details as $d) {
            $ssoAmount = 0.0;
            foreach ($d['statutory_breakdown'] as $item) {
                if ($item['code'] === 'TH_SSO') {
                    $ssoAmount = (float)$item['employee_amount'];
                }
            }
            if ($ssoAmount <= 0) {
                continue; // not SSO-enrolled this period, excluded from the submission
            }
            $employees[] = [
                'insured_id' => $this->decryptEmployeeField($d, 'sso_no') ?? '',
                'prefix_code' => $d['title'] ?? '',
                'first_name' => $d['name_th'] ?? '',
                'last_name' => $d['surname_th'] ?? '',
                'wage' => (float)$d['base_salary_amount'],
                'contribution' => $ssoAmount,
            ];
        }
        if (empty($employees)) {
            throw new RuntimeException('No SSO-enrolled employees with a contribution were found in this payroll run.');
        }

        $company = $dataModel->getCompany($compId);
        $statutoryData = json_decode((string)($company['statutory_data'] ?? '{}'), true) ?: [];
        $companyContext = [
            'employer_account' => $statutoryData['th_sso_id'] ?? '',
            'branch_no' => $statutoryData['th_branch_code'] ?? '0',
            'name' => $company['local_name'] ?? $company['company_legal_name'] ?? '',
            'contribution_rate' => 5.0,
        ];
        $periodContext = [
            'year' => (int)date('Y', strtotime($run['period_start_date'])),
            'month' => (int)date('n', strtotime($run['period_start_date'])),
            'payment_date' => $run['payment_date'],
        ];

        if ($format === 'txt') {
            $exporter = new Sso110Exporter();
            $content = $exporter->generate(['company' => $companyContext, 'period' => $periodContext, 'employees' => $employees]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['period' => $periodContext]), 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประกันสังคม', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ค่าจ้าง', 'เงินสมทบ'];
            $rows = array_map(fn($e) => [$e['insured_id'], $e['prefix_code'], $e['first_name'], $e['last_name'], $e['wage'], $e['contribution']], $employees);
            $content = $this->renderExcelFromRows($headers, $rows, "SSO110 {$periodContext['year']}-{$periodContext['month']}");
            return ['content' => $content, 'file_name' => "SSO110_Summary_{$periodContext['year']}{$periodContext['month']}.xlsx", 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $rowsHtml = '';
        $totalWage = 0.0;
        $totalContribution = 0.0;
        foreach ($employees as $e) {
            $totalWage += $e['wage'];
            $totalContribution += $e['contribution'];
            $rowsHtml .= '<tr><td>' . htmlspecialchars($e['insured_id']) . '</td><td>' . htmlspecialchars($e['prefix_code'] . ' ' . $e['first_name'] . ' ' . $e['last_name']) . '</td><td class="amount">' . number_format($e['wage'], 2) . '</td><td class="amount">' . number_format($e['contribution'], 2) . '</td></tr>';
        }
        $companyName = htmlspecialchars($companyContext['name']);
        $periodLabel = "{$periodContext['month']}/{$periodContext['year']}";
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
<div>สปส.1-10 สรุปเงินสมทบ งวด {$periodLabel}</div>
<table>
<thead><tr><th>เลขประกันสังคม</th><th>ชื่อ-สกุล</th><th>ค่าจ้าง</th><th>เงินสมทบ</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td colspan="2">รวม</td><td class="amount">{$this->fmt($totalWage)}</td><td class="amount">{$this->fmt($totalContribution)}</td></tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return ['content' => $content, 'file_name' => "SSO110_Summary_{$periodContext['year']}{$periodContext['month']}.pdf", 'mime_type' => 'application/pdf'];
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
