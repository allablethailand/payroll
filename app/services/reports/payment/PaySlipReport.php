<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../PdfRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';

/**
 * Pay Slip — one PDF per employee for a given payroll run, showing the earning/deduction/
 * statutory breakdown captured at calculation time. Requires the run to be at least Approved
 * (see PayrollReportDataModel::assertRunState) since a pay slip is an official pay document
 * and must not be issued from numbers that can still change.
 */
class PaySlipReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'PAY_SLIP';
    }

    public function reportType(): string {
        return 'payment';
    }

    public function label(): array {
        return ['th' => 'สลิปเงินเดือน', 'en' => 'Pay Slip'];
    }

    public function isVerified(): bool {
        return true; // this system's own layout, no external form spec claimed
    }

    public function supportedFormats(): array {
        return ['pdf'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int, employee_id: int }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new InvalidArgumentException('comp_id is required.');
        }
        if (!isset($context['run_id']) || !is_numeric($context['run_id']) || (int)$context['run_id'] <= 0) {
            throw new InvalidArgumentException('run_id is required and must be a positive integer.');
        }
        if (!isset($context['employee_id']) || !is_numeric($context['employee_id']) || (int)$context['employee_id'] <= 0) {
            throw new InvalidArgumentException('employee_id is required and must be a positive integer.');
        }
        $runId = (int)$context['run_id'];
        $employeeId = (int)$context['employee_id'];

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new RuntimeException('Payroll run not found.');
        }
        $stateError = $dataModel->assertRunState($run, self::ALLOWED_STATES);
        if ($stateError !== null) {
            throw new RuntimeException($stateError);
        }
        $detail = $dataModel->getRunDetailForEmployee($runId, $employeeId);
        if (!$detail) {
            throw new RuntimeException('This employee is not part of the selected payroll run.');
        }

        $company = $dataModel->getCompany($compId);
        $companyName = $company['local_name'] ?? $company['company_legal_name'] ?? '';

        $earningRows = '';
        foreach ($detail['earning_breakdown'] as $line) {
            $earningRows .= '<tr><td>' . htmlspecialchars($line['name_th'] ?? $line['code']) . '</td><td class="amount">' . number_format((float)$line['amount'], 2) . '</td></tr>';
        }
        $earningRows .= '<tr><td>เงินเดือนพื้นฐาน</td><td class="amount">' . number_format((float)$detail['base_salary_amount'], 2) . '</td></tr>';

        $deductionRows = '';
        foreach ($detail['deduction_breakdown'] as $line) {
            $deductionRows .= '<tr><td>' . htmlspecialchars($line['name_th'] ?? $line['code']) . '</td><td class="amount">' . number_format((float)$line['amount'], 2) . '</td></tr>';
        }
        foreach ($detail['statutory_breakdown'] as $item) {
            if ((float)$item['employee_amount'] > 0) {
                $deductionRows .= '<tr><td>' . htmlspecialchars($item['code']) . '</td><td class="amount">' . number_format((float)$item['employee_amount'], 2) . '</td></tr>';
            }
        }

        $employeeName = $this->employeeDisplayName($detail, 'th');
        $html = $this->buildHtml($companyName, $run, $detail, $employeeName, $earningRows, $deductionRows);
        $content = $this->renderPdfFromHtml($html, 'A5', 'portrait');
        $fileSafeName = preg_replace('/\s+/', '_', $employeeName) ?? 'employee';
        return [
            'content' => $content,
            'file_name' => "PaySlip_{$detail['employee_no']}_{$run['id']}.pdf",
            'mime_type' => 'application/pdf',
        ];
    }

    private function buildHtml(string $companyName, array $run, array $detail, string $employeeName, string $earningRows, string $deductionRows): string {
        $gross = number_format((float)$detail['gross_amount'], 2);
        $deduct = number_format((float)$detail['total_deduction_amount'], 2);
        $net = number_format((float)$detail['net_amount'], 2);
        $period = htmlspecialchars($run['period_start_date'] . ' - ' . $run['period_end_date']);
        $employeeNo = htmlspecialchars($detail['employee_no']);
        $empNameEsc = htmlspecialchars($employeeName);
        $companyEsc = htmlspecialchars($companyName);
        return <<<HTML
<html>
<head><style>
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
h2 { font-size: 14px; margin-bottom: 2px; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.amount { text-align: right; }
.summary td { font-weight: bold; }
</style></head>
<body>
<h2>{$companyEsc}</h2>
<div>สลิปเงินเดือน / Pay Slip — งวด {$period}</div>
<div>รหัสพนักงาน: {$employeeNo} &nbsp; ชื่อ: {$empNameEsc}</div>
<table>
<thead><tr><th colspan="2">รายได้ / Earnings</th></tr></thead>
<tbody>{$earningRows}</tbody>
</table>
<table>
<thead><tr><th colspan="2">รายการหัก / Deductions</th></tr></thead>
<tbody>{$deductionRows}</tbody>
</table>
<table class="summary">
<tr><td>รายได้รวม</td><td class="amount">{$gross}</td></tr>
<tr><td>หักรวม</td><td class="amount">{$deduct}</td></tr>
<tr><td>ยอดจ่ายสุทธิ</td><td class="amount">{$net}</td></tr>
</table>
</body>
</html>
HTML;
    }
}
