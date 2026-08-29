<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../PdfRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * Payment Voucher (annual) — one PDF per employee summarizing every payment made to them
 * across a calendar year (one line per payroll run that included them). Requires Approved+
 * runs only, same reasoning as other payment/statutory reports.
 */
class PaymentVoucherReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'PAYMENT_VOUCHER';
    }

    public function reportType(): string {
        return 'payment';
    }

    public function label(): array {
        return ['th' => 'หนังสือรับรองการจ่ายเงินประจำปี', 'en' => 'Annual Payment Voucher'];
    }

    public function isVerified(): bool {
        return true; // this system's own layout, no external form spec claimed
    }

    public function supportedFormats(): array {
        return ['pdf'];
    }

    // Same 3-line helper as EmploymentCertificateRenderer::formatDate()/PayslipTemplateRenderer::
    // formatDate()/PaySlipReport::formatDate() -- 2026-08-26, explicit request: "Format วันที่การแสดงผล
    // ทั้งหมดของระบบให้เป็น dd/mm/yyyy". Gregorian dd/mm/yyyy specifically (not converted to พ.ศ.) --
    // this report's own voucher-number year label is พ.ศ. by explicit separate convention (see
    // $currentYearBe below), but nothing asked for the payment-period DATES themselves to become
    // Buddhist-calendar, only for their day/month/year ORDER to be consistent app-wide.
    private function formatDate(?string $ymd): string {
        if (empty($ymd)) {
            return '-';
        }
        $ts = strtotime($ymd);
        return $ts !== false ? date('d/m/Y', $ts) : $ymd;
    }

    /**
     * @param array $context { comp_id: int, year: int (พ.ศ.), employee_id: int }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['year']) || !is_numeric($context['year'])) {
            throw new LocalizedException('year (พ.ศ.) is required and must be numeric.', 'year_required');
        }
        if (!isset($context['employee_id']) || !is_numeric($context['employee_id']) || (int)$context['employee_id'] <= 0) {
            throw new LocalizedException('employee_id is required and must be a positive integer.', 'employee_id_required');
        }
        $yearBe = (int)$context['year'];
        $currentYearBe = (int)date('Y') + 543;
        if ($yearBe < 2500 || $yearBe > $currentYearBe + 1) {
            throw new LocalizedException("year must be a valid Buddhist Era year (2500–{$currentYearBe}).", 'year_out_of_range', ['min' => 2500, 'max' => $currentYearBe]);
        }
        $employeeId = (int)$context['employee_id'];
        $yearAd = $yearBe - 543;

        $dataModel = new PayrollReportDataModel();
        $runs = $dataModel->getRunsInYear($compId, $yearAd, self::ALLOWED_STATES);
        if (empty($runs)) {
            $allowedLabel = implode('/', self::ALLOWED_STATES);
            throw new LocalizedException("No payroll runs in state {$allowedLabel} were found for B.E. {$yearBe}.", 'no_runs_in_state_for_year', ['states' => self::ALLOWED_STATES, 'year' => $yearBe]);
        }

        $rowsHtml = '';
        $totalGross = 0.0;
        $totalDeduction = 0.0;
        $totalNet = 0.0;
        $employeeDetail = null;
        $rowCount = 0;
        foreach ($runs as $run) {
            $detail = $dataModel->getRunDetailForEmployee((int)$run['id'], $employeeId);
            if (!$detail) {
                continue;
            }
            $employeeDetail = $detail;
            $rowCount++;
            $totalGross += (float)$detail['gross_amount'];
            $totalDeduction += (float)$detail['total_deduction_amount'];
            $totalNet += (float)$detail['net_amount'];
            $rowsHtml .= '<tr>'
                // 2026-08-26, explicit request: "Format วันที่การแสดงผลทั้งหมดของระบบให้เป็น dd/mm/yyyy"
                . '<td>' . htmlspecialchars($this->formatDate($run['period_start_date']) . ' - ' . $this->formatDate($run['period_end_date'])) . '</td>'
                . '<td class="amount">' . number_format((float)$detail['gross_amount'], 2) . '</td>'
                . '<td class="amount">' . number_format((float)$detail['total_deduction_amount'], 2) . '</td>'
                . '<td class="amount">' . number_format((float)$detail['net_amount'], 2) . '</td>'
                . '</tr>';
        }
        if ($rowCount === 0 || $employeeDetail === null) {
            throw new LocalizedException("This employee has no payment records in an approved payroll run for B.E. {$yearBe}.", 'no_payment_records_for_year', ['year' => $yearBe]);
        }

        $company = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($company['local_name'] ?? $company['company_legal_name'] ?? '');
        $employeeName = htmlspecialchars($this->employeeDisplayName($employeeDetail, 'th'));
        $employeeNo = htmlspecialchars($employeeDetail['employee_no']);
        $html = <<<HTML
<html><head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
h1 { font-size: 15px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.amount { text-align: right; }
tfoot td { font-weight: bold; }
</style></head><body>
<h1>{$companyName}</h1>
<div>หนังสือรับรองการจ่ายเงินประจำปี {$yearBe}</div>
<div>รหัสพนักงาน: {$employeeNo} &nbsp; ชื่อ: {$employeeName}</div>
<table>
<thead><tr><th>งวด</th><th>รายได้รวม</th><th>หักรวม</th><th>ยอดจ่ายสุทธิ</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td>รวมทั้งปี</td><td class="amount">{$this->fmt($totalGross)}</td><td class="amount">{$this->fmt($totalDeduction)}</td><td class="amount">{$this->fmt($totalNet)}</td></tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A5', 'portrait');
        return [
            'content' => $content,
            'file_name' => "PaymentVoucher_{$employeeDetail['employee_no']}_{$yearBe}.pdf",
            'mime_type' => 'application/pdf',
        ];
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
