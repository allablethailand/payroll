<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/StudentLoanExporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../../models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * กยศ. (กองทุนเงินให้กู้ยืมเพื่อการศึกษา / Student Loan Fund) monthly deduction remittance list for
 * a payroll run.
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
 * 2026-09-05, Phase 12 T071 — 'txt' format added via the new StudentLoanExporter (see that class's
 * own docblock), against a real reference spec the user supplied for the first time (this report
 * had none before, "no field-layout basis exists" per its own pre-2026-09-05 docblock — same
 * situation สปส.6-09/กท.20ก. were in until this same round, see Sso609Exporter). The spec's own
 * field 2 is explicitly "เลขประจำตัวประชาชน" (national ID card no.) — `employees.id_card_no`, NOT
 * the `tax_id_no` the PDF/Excel formats below already display as "เลขประจำตัวผู้เสียภาษี" (a
 * separate, pre-existing column/purpose, left untouched here) — and field 3 is ONE combined
 * "ชื่อ-นามสกุล" value including the Thai prefix (นาย/นาง/นางสาว), unlike this report's own existing
 * `name` field (first+last only, no prefix, via EmployeePiiTrait::employeeDisplayName()) — built
 * separately for the txt path only, not by changing that shared field's own existing shape.
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

    // 2026-09-05, Phase 12 T071: was an unconditional `false` -- the underlying `txt` layout is
    // now confirmed against a real reference spec, see StudentLoanExporter's own docblock.
    public function isVerified(): bool {
        return true;
    }

    public function supportedFormats(): array {
        return ['excel', 'pdf', 'txt'];
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

        $pedTypeModel = new PayrollEarningDeductionTypeModel();
        $mappedCodes = $pedTypeModel->itemCodesByStatutoryReportCode($compId, self::REPORT_TAG);
        if (empty($mappedCodes)) {
            throw new LocalizedException('No deduction type is mapped to the Student Loan Fund (กยศ.) report yet. Set this in Payroll Configuration > Earning-Deduction Types.', 'student_loan_not_mapped');
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
            // 2026-09-05, Phase 12 T071: id_card_no + a prefix-included full name, built ONLY for
            // the new txt path -- see this class's own top-of-file docblock for why these are
            // separate from the pre-existing tax_id/name fields above.
            $prefixTh = ['mr' => 'นาย', 'mrs' => 'นาง', 'ms' => 'นางสาว'][$d['title'] ?? ''] ?? '';
            $rows[] = [
                'employee_no' => $d['employee_no'],
                'tax_id' => $this->decryptEmployeeField($d, 'tax_id_no') ?? '',
                'name' => $this->employeeDisplayName($d, 'th'),
                'department' => $d['department_name_th'] ?? '',
                'assignment_id' => $assignmentId,
                'amount' => round($amount, 2),
                'id_card_no' => $this->decryptEmployeeField($d, 'id_card_no') ?? '',
                'full_name_with_prefix' => trim($prefixTh . ' ' . $this->employeeDisplayName($d, 'th')),
            ];
        }
        if (empty($rows)) {
            throw new LocalizedException('No employees had a กยศ. deduction in this payroll run.', 'student_loan_no_deductions_in_run');
        }

        $refByAssignment = $dataModel->getEarningDeductionReferenceNos($assignmentIds);
        foreach ($rows as &$r) {
            $r['reference_no'] = $r['assignment_id'] !== null ? ($refByAssignment[$r['assignment_id']] ?? '') : '';
        }
        unset($r);

        $period = $run['period_start_date'] . ' - ' . $run['period_end_date'];

        if ($format === 'txt') {
            $exporter = new StudentLoanExporter();
            $exportRows = array_map(fn($r) => [
                'citizen_id' => $r['id_card_no'],
                'full_name' => $r['full_name_with_prefix'],
                'amount' => $r['amount'],
            ], $rows);
            $content = $exporter->generate(['employees' => $exportRows]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['run_id' => $runId]), 'mime_type' => 'text/plain'];
        }

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
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
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
