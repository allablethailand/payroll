<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../ExcelRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/PayrollRunCashPaymentModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * Cash Payment Summary (2026-08-31, explicit request: "ถ้าพนักงานรับเงินสด ต้องไม่ดึงไปใน Report Payroll
 * ขึ้นธนาคาร แต่แยก Report ตามแยก ว่าจ่ายเงินสดเท่าไหร่ โอนผ่านธนาคารเท่าไหร่ และสามารถใส่ Status ว่าจ่ายแล้ว")
 * -- a per-run breakdown of every employee by payment_type (cash vs bank), with the cash side's own
 * paid/unpaid status (PayrollRunCashPaymentModel). This is a plain EXPORT snapshot of that same
 * data -- the interactive "mark as paid" action itself lives on the Payroll Run Detail page's own
 * Cash Payments tab (api/payroll-run-cash-payment.*), not here; this report exists so the same
 * breakdown can be downloaded/archived like every other payment-type report. Same ALLOWED_STATES
 * gate (approved/paid/locked) as PaymentVoucherReport/BankTransferFileReport.
 */
class CashPaymentSummaryReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'CASH_PAYMENT_SUMMARY';
    }

    public function reportType(): string {
        return 'payment';
    }

    public function label(): array {
        return ['th' => 'สรุปยอดจ่ายเงินสด/โอนธนาคาร', 'en' => 'Cash vs Bank Payment Summary'];
    }

    public function isVerified(): bool {
        return true; // this system's own layout, no external form spec claimed
    }

    public function supportedFormats(): array {
        return ['excel'];
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
        if (empty($details)) {
            throw new LocalizedException('This payroll run has no calculated employees yet. Recalculate it first.', 'run_no_calculated_employees');
        }

        // Reuses the exact same tracked rows the interactive Cash Payments tab shows/edits (not a
        // separate query) -- this export always reflects the real, current paid/unpaid status.
        $cashModel = new PayrollRunCashPaymentModel();
        $cashByEmployee = [];
        foreach ($cashModel->listForRun($runId, $compId)['rows'] as $row) {
            $cashByEmployee[(int)$row['employee_id']] = $row;
        }

        $headers = ['รหัสพนักงาน', 'ชื่อ-สกุล', 'ช่องทางจ่าย', 'ยอดจ่ายสุทธิ', 'สถานะ (เฉพาะเงินสด)'];
        $rows = [];
        $totalCash = 0.0;
        $totalBank = 0.0;
        foreach ($details as $d) {
            $isCash = ($d['payment_type'] ?? 'bank') === 'cash';
            $net = (float)$d['net_amount'];
            if ($isCash) {
                $totalCash += $net;
            } else {
                $totalBank += $net;
            }
            $cashRow = $cashByEmployee[(int)$d['employee_id']] ?? null;
            $statusLabel = $isCash ? (($cashRow['status'] ?? 'unpaid') === 'paid' ? 'จ่ายแล้ว' : 'ยังไม่จ่าย') : '';
            $rows[] = [
                $d['employee_no'],
                $this->employeeDisplayName($d, 'th'),
                $isCash ? 'เงินสด' : 'โอนธนาคาร',
                $net,
                $statusLabel,
            ];
        }
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', 'รวมจ่ายเงินสด', '', $totalCash, ''];
        $rows[] = ['', 'รวมโอนธนาคาร', '', $totalBank, ''];

        $content = $this->renderExcelFromRows($headers, $rows, 'Cash Payment Summary ' . $run['id']);
        return [
            'content' => $content,
            'file_name' => "CashPaymentSummary_Run{$runId}.xlsx",
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
