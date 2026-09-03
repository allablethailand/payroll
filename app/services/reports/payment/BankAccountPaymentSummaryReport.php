<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../ExcelRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/PayrollRunEmployeeBankAccountModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * Payment Summary by Bank Account (2026-09-02, explicit request: "และมี Report แยกตามบัญชีที่จ่าย" --
 * part of the same multi-bank-account payroll request as PayrollRunEmployeeBankAccountModel, see
 * that class's own docblock for the resolution chain this reuses). One row per bank-paying
 * employee, grouped under whichever `bank_accounts` row PayrollRunEmployeeBankAccountModel
 * resolved for them (override > employee default > cycle pin > company default), with a subtotal
 * per account and a grand total -- this is the "how much moves out of which of OUR OWN settlement
 * accounts" view; NOT to be confused with BankTransferFileReport (the actual Cashlink-format file
 * that also now splits one-file-per-account for the same reason, see that class's own docblock).
 *
 * Same ALLOWED_STATES gate as CashPaymentSummaryReport/BankTransferFileReport (a disbursement
 * concern, only meaningful once a run's numbers are final) -- and Thai-only/single-sheet Excel,
 * same simpler precedent as CashPaymentSummaryReport (this is a plain export snapshot, not the
 * bilingual/PDF-preview treatment PayrollRegisterReport got, since that upgrade was specifically
 * about the pre-existing register/reports this batch's item 2 named, not a brand-new report).
 */
class BankAccountPaymentSummaryReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'BANK_ACCOUNT_PAYMENT_SUMMARY';
    }

    public function reportType(): string {
        return 'payment';
    }

    public function label(): array {
        return ['th' => 'สรุปยอดจ่ายแยกตามบัญชีธนาคาร', 'en' => 'Payment Summary by Bank Account'];
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
        $netByEmployee = [];
        foreach ($details as $d) {
            $netByEmployee[(int)$d['employee_id']] = $d;
        }

        // Reuses the SAME resolution the interactive "Bank Account Assignment" tab and
        // BankTransferFileReport's own per-account split both read -- this export always reflects
        // whatever is actually configured (override/employee-default/cycle/company-default) right now.
        $bankAccountModel = new PayrollRunEmployeeBankAccountModel();
        $assignments = $bankAccountModel->listForRun($runId, $compId);

        // Group by resolved account (null bucket = genuinely unconfigured -- no override, no
        // employee default, no cycle pin, and no company default account at all -- surfaced
        // explicitly as its own group rather than silently dropped, since that's a real gap someone
        // needs to fix before this run can actually be paid out).
        $groups = []; // account_id(or 'none') => ['label' => string, 'rows' => [...], 'subtotal' => float]
        foreach ($assignments as $a) {
            $key = $a['bank_account_id'] !== null ? (string)$a['bank_account_id'] : 'none';
            if (!isset($groups[$key])) {
                $label = $a['bank_account_id'] !== null
                    ? trim(($a['bank_name_th'] ?? '') . ' - ' . ($a['bank_account_name'] ?? '')) : 'ไม่ได้กำหนดบัญชี';
                $groups[$key] = ['label' => $label, 'rows' => [], 'subtotal' => 0.0];
            }
            $net = (float)($netByEmployee[$a['employee_id']]['net_amount'] ?? 0.0);
            $groups[$key]['rows'][] = [
                $groups[$key]['label'],
                $a['employee_no'],
                $this->employeeDisplayName($netByEmployee[$a['employee_id']] ?? $a, 'th'),
                $net,
            ];
            $groups[$key]['subtotal'] += $net;
        }
        // "unassigned" group (if any) always last, others alphabetically by label -- stable output
        // regardless of employee/account insertion order.
        uksort($groups, function ($a, $b) use ($groups) {
            if ($a === 'none') return 1;
            if ($b === 'none') return -1;
            return strcmp($groups[$a]['label'], $groups[$b]['label']);
        });

        $headers = ['บัญชีที่จ่าย', 'รหัสพนักงาน', 'ชื่อ-สกุล', 'ยอดจ่ายสุทธิ'];
        $rows = [];
        $grandTotal = 0.0;
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $rows[] = $row;
            }
            $rows[] = ['', '', 'รวม - ' . $group['label'], $group['subtotal']];
            $rows[] = ['', '', '', ''];
            $grandTotal += $group['subtotal'];
        }
        $rows[] = ['', '', 'รวมทั้งสิ้น', $grandTotal];

        $content = $this->renderExcelFromRows($headers, $rows, 'Bank Account Summary ' . $run['id']);
        return [
            'content' => $content,
            'file_name' => "BankAccountPaymentSummary_Run{$runId}.xlsx",
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
