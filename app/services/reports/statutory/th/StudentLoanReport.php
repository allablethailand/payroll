<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../../models/PayrollEarningDeductionTypeModel.php';

/**
 * DRAFT — กยศ. (กองทุนเงินให้กู้ยืมเพื่อการศึกษา / Student Loan Fund) monthly deduction remittance
 * list for a payroll run.
 *
 * Unlike SSO/PIT/PVD, กยศ. deduction is NOT a system-wide statutory calc item — it's a
 * per-employee, court/fund-notice-driven wage deduction with an amount set by the fund's notice
 * to the employer, not a formula. This system already models that shape as an installment-based
 * `payroll_earning_deduction_types` entry (company-defined, via Payroll Configuration), the same
 * mechanism used for salary advances/loans. To let this report find "whichever deduction type the
 * company set up for กยศ." without guessing by item_code or name, `payroll_earning_deduction_types`
 * has a nullable `statutory_report_code` tag column — set it to 'TH_SLF' on the company's กยศ.
 * deduction type(s) and this report will pick up any deduction line using those item_codes.
 *
 * NOT researched against an official กยศ. submission format at all — no field-layout basis
 * exists (same situation as สปส.6-09/กท.20ก.), so only PDF/Excel (human-readable) are offered,
 * no 'txt' electronic-submission format. Before using this for a real remittance, confirm the
 * current reporting method directly with กยศ.
 */
class StudentLoanReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;
    use PdfRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];
    private const REPORT_TAG = 'TH_SLF';

    public function code(): string {
        return 'TH_SLF';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'กยศ. (รายการหักเงินนำส่ง)', 'en' => 'Student Loan Fund (Deduction Remittance)'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['excel', 'pdf'];
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

        $pedTypeModel = new PayrollEarningDeductionTypeModel();
        $mappedCodes = $pedTypeModel->itemCodesByStatutoryReportCode($compId, self::REPORT_TAG);
        if (empty($mappedCodes)) {
            throw new RuntimeException('No deduction type is mapped to the Student Loan Fund (กยศ.) report yet. Set this in Payroll Configuration > Earning-Deduction Types.');
        }
        $mappedCodes = array_flip($mappedCodes);

        $details = $dataModel->getRunDetails($runId);
        $rows = [];
        $assignmentIds = [];
        foreach ($details as $d) {
            $amount = 0.0;
            $assignmentId = null;
            foreach ($d['deduction_breakdown'] as $line) {
                if (isset($mappedCodes[$line['code']])) {
                    $amount += (float)$line['amount'];
                    $assignmentId = $line['assignment_id'] ?? $assignmentId;
                }
            }
            if ($amount <= 0) {
                continue;
            }
            if ($assignmentId !== null) {
                $assignmentIds[] = (int)$assignmentId;
            }
            $rows[] = [
                'employee_no' => $d['employee_no'],
                'tax_id' => $this->decryptEmployeeField($d, 'tax_id_no') ?? '',
                'name' => $this->employeeDisplayName($d, 'th'),
                'department' => $d['department_name_th'] ?? '',
                'assignment_id' => $assignmentId,
                'amount' => round($amount, 2),
            ];
        }
        if (empty($rows)) {
            throw new RuntimeException('No employees had a กยศ. deduction in this payroll run.');
        }

        $refByAssignment = $dataModel->getEarningDeductionReferenceNos($assignmentIds);
        foreach ($rows as &$r) {
            $r['reference_no'] = $r['assignment_id'] !== null ? ($refByAssignment[$r['assignment_id']] ?? '') : '';
        }
        unset($r);

        $period = $run['period_start_date'] . ' - ' . $run['period_end_date'];

        if ($format === 'excel') {
            $headers = ['รหัสพนักงาน', 'เลขประจำตัวผู้เสียภาษี', 'ชื่อ-สกุล', 'แผนก', 'เลขที่สัญญากู้ยืม', 'ยอดหักนำส่ง'];
            $excelRows = array_map(fn($r) => [$r['employee_no'], $r['tax_id'], $r['name'], $r['department'], $r['reference_no'], $r['amount']], $rows);
            $content = $this->renderExcelFromRows($headers, $excelRows, "SLF Run{$runId}");
            return ['content' => $content, 'file_name' => "StudentLoanFund_Run{$runId}.xlsx", 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $company = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($company['local_name'] ?? $company['company_legal_name'] ?? '');
        $rowsHtml = '';
        $total = 0.0;
        foreach ($rows as $r) {
            $total += $r['amount'];
            $rowsHtml .= '<tr>'
                . '<td>' . htmlspecialchars((string)$r['employee_no']) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['tax_id']) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['name']) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['reference_no']) . '</td>'
                . '<td class="amount">' . number_format($r['amount'], 2) . '</td>'
                . '</tr>';
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
<div>รายการหักเงินนำส่งกองทุนเงินให้กู้ยืมเพื่อการศึกษา (กยศ.) งวด {$period}</div>
<table>
<thead><tr><th>รหัสพนักงาน</th><th>เลขประจำตัวผู้เสียภาษี</th><th>ชื่อ-สกุล</th><th>เลขที่สัญญากู้ยืม</th><th>ยอดหักนำส่ง</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td colspan="4">รวม</td><td class="amount">{$this->fmt($total)}</td></tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return ['content' => $content, 'file_name' => "StudentLoanFund_Run{$runId}.pdf", 'mime_type' => 'application/pdf'];
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
