<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * DRAFT — Bank transfer batch file for paying net salary via bulk bank transfer.
 *
 * NOT researched against any bank's real bulk-transfer file spec (KBank/SCB/BBL/DBS all have
 * their own proprietary fixed-width or delimited formats — none were verified in this
 * environment, same class of limitation as the SSO/RD research documented in the Tax &
 * Statutory export module). This draft always produces the SAME generic CSV shape regardless
 * of the payroll cycle's `bank_file_format_id` — it does NOT yet branch per bank format. That
 * is a known gap, not an oversight: fabricating 4 different fake fixed-width layouts with no
 * real basis would be worse than one honestly-generic CSV. Before using this for a real bank
 * upload, get the counterparty bank's actual file spec and adjust this class (or add a
 * per-bank-format subclass registered separately) accordingly.
 *
 * Requires the run to be Approved+ (transfer files should only be built from finalized
 * numbers) and every included employee to be paid by bank transfer with an account number on
 * file — employees missing bank details are skipped and listed in a warning row at the top
 * rather than silently dropped.
 */
class BankTransferFileReport implements ReportGeneratorInterface {
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'BANK_TRANSFER_FILE';
    }

    public function reportType(): string {
        return 'payment';
    }

    public function label(): array {
        return ['th' => 'ไฟล์โอนเงินธนาคาร (Bank Transfer File)', 'en' => 'Bank Transfer File'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['csv'];
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

        $lines = ['เลขที่บัญชี,ชื่อบัญชี,ธนาคาร,รหัสธนาคาร,จำนวนเงิน,หมายเหตุ'];
        $skipped = [];
        $total = 0.0;
        foreach ($details as $d) {
            if (($d['payment_type'] ?? 'bank') !== 'bank') {
                continue; // paid by cash, not part of a bank transfer batch
            }
            $accountNo = $this->decryptEmployeeField($d, 'bank_account_no');
            if (empty($accountNo) || empty($d['bank_code'])) {
                $skipped[] = $d['employee_no'];
                continue;
            }
            $amount = (float)$d['net_amount'];
            $total += $amount;
            $lines[] = implode(',', [
                $this->csvField($accountNo),
                $this->csvField($d['bank_account_name'] ?? ''),
                $this->csvField($d['bank_name_th'] ?? ''),
                $this->csvField($d['bank_code'] ?? ''),
                number_format($amount, 2, '.', ''),
                $this->csvField($d['employee_no']),
            ]);
        }
        if (!empty($skipped)) {
            array_unshift($lines, '# คำเตือน: พนักงานต่อไปนี้ไม่มีเลขบัญชี/ธนาคารในระบบ ถูกข้ามจากไฟล์นี้: ' . implode(', ', $skipped));
        }
        if ($total <= 0) {
            throw new LocalizedException('No employees with a valid bank account were found to include in the transfer file.', 'bank_transfer_no_valid_accounts');
        }

        $content = implode("\r\n", $lines) . "\r\n";
        return [
            'content' => $content,
            'file_name' => "BankTransfer_Run{$runId}.csv",
            'mime_type' => 'text/csv',
        ];
    }

    private function csvField(string $value): string {
        if (strpbrk($value, ",\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
