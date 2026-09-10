<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../ExcelRendererTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/PayrollRemittanceModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * Third-Party Remittance Summary (2026-09-02, Deduction Destination & Third-Party Remittance,
 * Phase 5 deliverable: "Excel export ของ tab นี้ -- reuse pattern เดียวกับ Process List export") --
 * a plain snapshot export of the batches PayrollRemittanceModel::generateForRun() already grouped
 * on Approval, one row per (remittance, employee) breakdown line, exactly what the interactive
 * Third-Party Remittance tab on the Payroll Run Detail page shows. This report does NOT create or
 * change any remittance -- it's read-only, same relationship CashPaymentSummaryReport (this file's
 * own direct template) has to PayrollRunCashPaymentModel/the Cash Payments tab.
 */
class ThirdPartyRemittanceSummaryReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'THIRD_PARTY_REMITTANCE_SUMMARY';
    }

    public function reportType(): string {
        return 'payment';
    }

    public function label(): array {
        return ['th' => 'สรุปการโอนเงินให้บุคคลที่สาม', 'en' => 'Third-Party Remittance Summary'];
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

        $remittanceModel = new PayrollRemittanceModel();
        $remittances = $remittanceModel->listForRun($runId, $compId);
        if (empty($remittances)) {
            throw new LocalizedException('This payroll run has no third-party remittances to report.', 'run_no_remittances');
        }

        $statusLabels = [
            'pending' => 'รอดำเนินการ',
            'transferred' => 'โอนแล้ว รอยืนยัน',
            'success' => 'สำเร็จ',
            'failed' => 'ล้มเหลว',
        ];
        $destTypeLabels = [
            'company' => 'บริษัท',
            'other_person' => 'บุคคลที่สาม',
            'employee_fallback' => 'พนักงาน (ไม่อยู่ในรอบนี้)',
        ];

        $headers = ['ปลายทาง', 'ประเภทปลายทาง', 'รหัสพนักงาน', 'ชื่อ-สกุล', 'รายการ', 'ยอดเงิน', 'สถานะ', 'โอนเมื่อ'];
        $rows = [];
        $grandTotal = 0.0;
        foreach ($remittances as $r) {
            $items = $remittanceModel->itemsForRemittance((int)$r['id'], $compId);
            if ($r['destination_type'] === 'company') {
                // 2026-09-10, Batch 3B item 3: shows WHICH company bank account now, instead of the
                // generic "บริษัท" label every company-type row used to get regardless of account --
                // 'ไม่ระบุ' (not the account name left blank) whenever bank_account_id is genuinely
                // unspecified (legacy data, or a line saved before this column existed), never a
                // silent gap in this report.
                $destLabel = !empty($r['is_unspecified_company_account']) ? 'บริษัท (ไม่ระบุบัญชี)' : ('บริษัท - ' . ($r['bank_account_name'] ?? 'ไม่ระบุ'));
            } elseif ($r['destination_type'] === 'employee_fallback') {
                $destLabel = trim(($r['fallback_name_th'] ?? '') . ' ' . ($r['fallback_surname_th'] ?? ''));
            } else {
                $destLabel = $r['destination_account_name'] ?? '-';
            }
            foreach ($items as $item) {
                $grandTotal += (float)$item['amount'];
                $rows[] = [
                    $destLabel,
                    $destTypeLabels[$r['destination_type']] ?? $r['destination_type'],
                    $item['employee_no'] ?? '',
                    trim(($item['name_th'] ?? '') . ' ' . ($item['surname_th'] ?? '')),
                    $item['item_code'] ?? '',
                    (float)$item['amount'],
                    $statusLabels[$r['status']] ?? $r['status'],
                    $r['transferred_at'] ?? '',
                ];
            }
        }
        $rows[] = ['', '', '', '', '', '', '', ''];
        $rows[] = ['รวมทั้งหมด', '', '', '', '', $grandTotal, '', ''];

        $content = $this->renderExcelFromRows($headers, $rows, 'Third-Party Remittance ' . $run['id']);
        return [
            'content' => $content,
            'file_name' => "ThirdPartyRemittance_Run{$runId}.xlsx",
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
