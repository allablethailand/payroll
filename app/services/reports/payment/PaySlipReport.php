<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../PdfRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/PayslipTemplateModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * Pay Slip — one PDF per employee for a given payroll run, showing the earning/deduction/
 * statutory breakdown captured at calculation time. Requires the run to be at least Approved
 * (see PayrollReportDataModel::assertRunState) since a pay slip is an official pay document
 * and must not be issued from numbers that can still change.
 *
 * Template-aware: if the company has an active default Payslip Template (Document & Approval >
 * "เทมเพลตสลิปเงินเดือน"), renders through buildTemplatedHtml() using that template's field
 * selection/order/logo/header-footer text. If no default template exists (the common case for
 * any company that hasn't set one up yet), falls back to the original fixed buildHtml() layout
 * unchanged -- this class's behavior is 100% backward compatible until an admin opts in.
 */
class PaySlipReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    /** field_key => which rendering block it belongs to, so adjacent same-kind fields share one table. */
    private const FIELD_KINDS = [
        'employee_no' => 'info', 'employee_name' => 'info', 'department' => 'info', 'position' => 'info',
        'pay_period' => 'info', 'payment_date' => 'info', 'bank_account_masked' => 'info',
        'company_name' => 'company_header', 'company_address' => 'company_header',
        'company_tax_id' => 'company_header', 'company_signatory' => 'company_header',
        'company_logo' => 'logo',
        'basic_salary' => 'earning', 'earning_lines_all' => 'earning',
        'deduction_lines_all' => 'deduction',
        'statutory_lines_all' => 'statutory',
        'gross_amount' => 'summary', 'total_deduction_amount' => 'summary',
        'net_amount' => 'summary', 'ytd_summary' => 'summary',
    ];

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
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['run_id']) || !is_numeric($context['run_id']) || (int)$context['run_id'] <= 0) {
            throw new LocalizedException('run_id is required and must be a positive integer.', 'run_id_required');
        }
        if (!isset($context['employee_id']) || !is_numeric($context['employee_id']) || (int)$context['employee_id'] <= 0) {
            throw new LocalizedException('employee_id is required and must be a positive integer.', 'employee_id_required');
        }
        $runId = (int)$context['run_id'];
        $employeeId = (int)$context['employee_id'];

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);
        $detail = $dataModel->getRunDetailForEmployee($runId, $employeeId);
        if (!$detail) {
            throw new LocalizedException('This employee is not part of the selected payroll run.', 'employee_not_in_run');
        }

        $company = $dataModel->getCompany($compId);
        $companyName = $company['local_name'] ?? $company['company_legal_name'] ?? '';
        $employeeName = $this->employeeDisplayName($detail, 'th');

        $templateModel = new PayslipTemplateModel();
        $template = $templateModel->getDefaultForCompany($compId);

        if ($template !== null) {
            $html = $this->buildTemplatedHtml($template, $company, $run, $detail, $dataModel);
            $paperSize = 'A4';
        } else {
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
            $html = $this->buildHtml($companyName, $run, $detail, $employeeName, $earningRows, $deductionRows);
            $paperSize = 'A5';
        }

        $content = $this->renderPdfFromHtml($html, $paperSize, 'portrait');
        $fileSafeName = preg_replace('/\s+/', '_', $employeeName) ?? 'employee';
        return [
            'content' => $content,
            'file_name' => "PaySlip_{$detail['employee_no']}_{$run['id']}.pdf",
            'mime_type' => 'application/pdf',
        ];
    }

    /**
     * Renders the payslip modal's CURRENT unsaved form state (not what's persisted in the DB)
     * against fabricated mock employee/run data, so an admin can preview before clicking Save.
     * Company info is real (the actual company row) since that part isn't being edited here.
     *
     * @param array $draftTemplate { language_mode, header_text_th/en, footer_text_th/en, logo_path,
     *   fields: [{field_key, custom_label_th?, custom_label_en?}] }
     * @throws LocalizedException on an invalid field_key, an empty field list, or a missing company
     */
    public function generatePreview(array $draftTemplate, int $compId): string {
        $dataModel = new PayrollReportDataModel();
        $company = $dataModel->getCompany($compId);
        if (!$company) {
            throw new LocalizedException('Company not found.', 'company_not_found');
        }

        $templateModel = new PayslipTemplateModel();
        try {
            $resolvedFields = $templateModel->resolveFieldsForPreview(is_array($draftTemplate['fields'] ?? null) ? $draftTemplate['fields'] : []);
        } catch (InvalidArgumentException $e) {
            throw new LocalizedException($e->getMessage(), 'invalid_field_selection', ['detail' => $e->getMessage()]);
        }
        if (empty($resolvedFields)) {
            throw new LocalizedException('Select at least one field to preview.', 'select_at_least_one_field');
        }

        $logoPath = !empty($draftTemplate['logo_path']) ? (string)$draftTemplate['logo_path'] : null;
        if (!PayslipTemplateModel::isValidLogoPath($logoPath, $compId)) {
            throw new LocalizedException('Invalid logo_path.', 'invalid_field_selection');
        }

        $template = [
            'language_mode' => in_array($draftTemplate['language_mode'] ?? '', ['th', 'en', 'both'], true) ? $draftTemplate['language_mode'] : 'both',
            'header_text_th' => (string)($draftTemplate['header_text_th'] ?? ''),
            'header_text_en' => (string)($draftTemplate['header_text_en'] ?? ''),
            'footer_text_th' => (string)($draftTemplate['footer_text_th'] ?? ''),
            'footer_text_en' => (string)($draftTemplate['footer_text_en'] ?? ''),
            'logo_path' => $logoPath,
            'country_code' => (string)($company['registered_country'] ?? ''),
            'fields' => $resolvedFields,
        ];

        [$run, $detail] = $this->buildMockRunAndDetail($compId, $company);
        $html = $this->buildTemplatedHtml($template, $company, $run, $detail, $dataModel);
        return $this->renderPdfFromHtml($html, 'A4', 'portrait');
    }

    /** Fabricated employee/run data for Preview, shaped exactly like a real getRunDetailForEmployee() row. */
    private function buildMockRunAndDetail(int $compId, array $company): array {
        $countryCode = (string)($company['registered_country'] ?? 'TH');
        $pdo = Database::getInstance()->pdo;
        $stmt = $pdo->prepare("SELECT code FROM statutory_items WHERE country_code = :cc ORDER BY sort_order ASC LIMIT 2");
        $stmt->execute([':cc' => $countryCode]);
        $statutoryCodes = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'code');
        if (empty($statutoryCodes)) {
            $statutoryCodes = ['SAMPLE_STATUTORY'];
        }

        $run = [
            'id' => 0, 'comp_id' => $compId,
            'period_start_date' => date('Y-m-01'), 'period_end_date' => date('Y-m-t'), 'payment_date' => date('Y-m-d'),
        ];

        $basicSalary = 30000.00;
        $earningBreakdown = [
            ['code' => 'OT', 'name_th' => 'ค่าล่วงเวลา (ตัวอย่าง)', 'amount' => 1500.00],
            ['code' => 'TRIP', 'name_th' => 'ค่าเที่ยว (ตัวอย่าง)', 'amount' => 800.00],
        ];
        $deductionBreakdown = [
            ['code' => 'LOAN', 'name_th' => 'เงินกู้พนักงาน (ตัวอย่าง)', 'amount' => 500.00],
        ];
        $statutoryBreakdown = [];
        $statutoryTotal = 0.0;
        foreach ($statutoryCodes as $i => $code) {
            $amount = $i === 0 ? 750.00 : 200.00;
            $statutoryBreakdown[] = ['code' => $code, 'employee_amount' => $amount];
            $statutoryTotal += $amount;
        }
        $grossAmount = $basicSalary + array_sum(array_column($earningBreakdown, 'amount'));
        $totalDeduction = array_sum(array_column($deductionBreakdown, 'amount')) + $statutoryTotal;
        $netAmount = $grossAmount - $totalDeduction;

        $encryptedBank = EncryptionService::encrypt('1234567890');

        $detail = [
            'comp_id' => $compId, 'employee_id' => 0, 'employee_no' => 'EMP-0001',
            'name_th' => 'สมชาย', 'surname_th' => 'ใจดี', 'name_en' => 'Somchai', 'surname_en' => 'Jaidee',
            'department_name_th' => 'ฝ่ายทรัพยากรบุคคล', 'department_name_en' => 'Human Resources',
            'position_name_th' => 'เจ้าหน้าที่อาวุโส', 'position_name_en' => 'Senior Officer',
            'base_salary_amount' => $basicSalary,
            'earning_breakdown' => $earningBreakdown, 'deduction_breakdown' => $deductionBreakdown, 'statutory_breakdown' => $statutoryBreakdown,
            'gross_amount' => $grossAmount, 'total_deduction_amount' => $totalDeduction, 'net_amount' => $netAmount,
            'bank_account_no' => $encryptedBank['value'] ?? null, 'key_version' => $encryptedBank['key_version'] ?? null,
        ];
        return [$run, $detail];
    }

    /* ==================== Original fixed layout (fallback when no template is set) ==================== */

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

    /* ==================== Template-driven layout ==================== */

    private function pick(string $th, string $en, string $languageMode): string {
        if ($languageMode === 'th') return $th;
        if ($languageMode === 'en') return $en;
        $th = trim($th);
        $en = trim($en);
        if ($th === '') return $en;
        if ($en === '') return $th;
        return "{$th} / {$en}";
    }

    private function statutoryLabelMap(string $countryCode): array {
        $pdo = Database::getInstance()->pdo;
        $stmt = $pdo->prepare("SELECT code, name_th, name_en FROM statutory_items WHERE country_code = :cc");
        $stmt->execute([':cc' => $countryCode]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['code']] = ['th' => $row['name_th'], 'en' => $row['name_en']];
        }
        return $map;
    }

    private function buildTemplatedHtml(array $template, ?array $company, array $run, array $detail, PayrollReportDataModel $dataModel): string {
        $mode = $template['language_mode'] ?? 'both';
        $company = $company ?? [];
        $compId = (int)($detail['comp_id'] ?? $run['comp_id'] ?? 0);

        $employeeNameTh = $this->employeeDisplayName($detail, 'th');
        $employeeNameEn = $this->employeeDisplayName($detail, 'en');
        $statutoryLabels = $this->statutoryLabelMap((string)$template['country_code']);

        $ytd = null;
        if (in_array('ytd_summary', array_column($template['fields'], 'field_key'), true)) {
            $ytd = $dataModel->getYtdTotals($compId, (int)$detail['employee_id'], (string)$run['period_start_date'], self::ALLOWED_STATES);
        }

        $bankAccountMasked = '-';
        $rawBank = $this->decryptEmployeeField($detail, 'bank_account_no');
        if ($rawBank) {
            $rawBank = preg_replace('/\D/', '', $rawBank) ?? $rawBank;
            $bankAccountMasked = strlen($rawBank) > 4 ? str_repeat('•', strlen($rawBank) - 4) . substr($rawBank, -4) : $rawBank;
        }

        $sections = [];
        $currentKind = null;
        $buffer = '';
        $flush = function () use (&$sections, &$currentKind, &$buffer) {
            if ($currentKind !== null && $buffer !== '') {
                $sections[] = ['kind' => $currentKind, 'html' => $buffer];
            }
            $buffer = '';
        };

        foreach ($template['fields'] as $field) {
            $fieldKey = $field['field_key'];
            $kind = self::FIELD_KINDS[$fieldKey] ?? 'info';
            if ($kind !== $currentKind) {
                $flush();
                $currentKind = $kind;
            }

            $labelTh = $field['custom_label_th'] ?: $field['default_label_th'];
            $labelEn = $field['custom_label_en'] ?: $field['default_label_en'];
            $label = $this->pick((string)$labelTh, (string)$labelEn, $mode);

            switch ($fieldKey) {
                case 'employee_no':
                    $buffer .= $this->infoRow($label, htmlspecialchars($detail['employee_no']));
                    break;
                case 'employee_name':
                    $buffer .= $this->infoRow($label, htmlspecialchars($this->pick($employeeNameTh, $employeeNameEn, $mode)));
                    break;
                case 'department':
                    $buffer .= $this->infoRow($label, htmlspecialchars($this->pick((string)($detail['department_name_th'] ?? ''), (string)($detail['department_name_en'] ?? ''), $mode) ?: '-'));
                    break;
                case 'position':
                    $buffer .= $this->infoRow($label, htmlspecialchars($this->pick((string)($detail['position_name_th'] ?? ''), (string)($detail['position_name_en'] ?? ''), $mode) ?: '-'));
                    break;
                case 'pay_period':
                    $buffer .= $this->infoRow($label, htmlspecialchars($run['period_start_date'] . ' - ' . $run['period_end_date']));
                    break;
                case 'payment_date':
                    $buffer .= $this->infoRow($label, htmlspecialchars((string)($run['payment_date'] ?? '-')));
                    break;
                case 'bank_account_masked':
                    $buffer .= $this->infoRow($label, htmlspecialchars($bankAccountMasked));
                    break;
                case 'company_name':
                    $buffer .= '<h2>' . htmlspecialchars($company['local_name'] ?? $company['company_legal_name'] ?? '') . '</h2>';
                    break;
                case 'company_address':
                    $addr = trim(($company['address_line_1'] ?? '') . ' ' . ($company['address_line_2'] ?? ''));
                    $buffer .= '<div class="company-line">' . htmlspecialchars($addr) . '</div>';
                    break;
                case 'company_tax_id':
                    $buffer .= '<div class="company-line">' . htmlspecialchars($label) . ': ' . htmlspecialchars((string)($company['global_tax_id'] ?? '-')) . '</div>';
                    break;
                case 'company_signatory':
                    $buffer .= '<div class="company-line">' . htmlspecialchars($label) . ': ' . htmlspecialchars((string)($company['authorized_signatory_name'] ?? '-')) . '</div>';
                    break;
                case 'company_logo':
                    if (!empty($template['logo_path'])) {
                        // Defense-in-depth beyond the regex check at the input boundaries (save()/generatePreview()):
                        // confine the resolved path inside the uploads root regardless of how logo_path got here.
                        $uploadsRoot = realpath(dirname(__DIR__, 4) . '/public/uploads/payslip_logos');
                        $logoAbsPath = realpath(dirname(__DIR__, 4) . '/' . ltrim((string)$template['logo_path'], '/'));
                        if ($uploadsRoot !== false && $logoAbsPath !== false && strpos($logoAbsPath, $uploadsRoot) === 0 && is_file($logoAbsPath)) {
                            $buffer .= '<div class="logo-wrap"><img src="' . htmlspecialchars($logoAbsPath) . '" style="max-height:60px;"></div>';
                        }
                    }
                    break;
                case 'basic_salary':
                    $buffer .= $this->amountRow($label, (float)$detail['base_salary_amount']);
                    break;
                case 'earning_lines_all':
                    foreach ($detail['earning_breakdown'] as $line) {
                        $lineLabel = $this->pick((string)($line['name_th'] ?? $line['code']), (string)($line['name_th'] ?? $line['code']), $mode);
                        $buffer .= $this->amountRow($lineLabel, (float)$line['amount']);
                    }
                    break;
                case 'deduction_lines_all':
                    foreach ($detail['deduction_breakdown'] as $line) {
                        $lineLabel = $this->pick((string)($line['name_th'] ?? $line['code']), (string)($line['name_th'] ?? $line['code']), $mode);
                        $buffer .= $this->amountRow($lineLabel, (float)$line['amount']);
                    }
                    break;
                case 'statutory_lines_all':
                    foreach ($detail['statutory_breakdown'] as $item) {
                        if ((float)$item['employee_amount'] <= 0) continue;
                        $names = $statutoryLabels[$item['code']] ?? ['th' => $item['code'], 'en' => $item['code']];
                        $lineLabel = $this->pick($names['th'], $names['en'], $mode);
                        $buffer .= $this->amountRow($lineLabel, (float)$item['employee_amount']);
                    }
                    break;
                case 'gross_amount':
                    $buffer .= $this->amountRow($label, (float)$detail['gross_amount']);
                    break;
                case 'total_deduction_amount':
                    $buffer .= $this->amountRow($label, (float)$detail['total_deduction_amount']);
                    break;
                case 'net_amount':
                    $buffer .= $this->amountRow($label, (float)$detail['net_amount']);
                    break;
                case 'ytd_summary':
                    if ($ytd !== null) {
                        $ytdGrossLabel = $this->pick('รายได้สะสม', 'YTD Gross', $mode);
                        $ytdDeductLabel = $this->pick('หักสะสม', 'YTD Deduction', $mode);
                        $ytdNetLabel = $this->pick('สุทธิสะสม', 'YTD Net', $mode);
                        $buffer .= $this->amountRow($ytdGrossLabel, $ytd['ytd_gross']);
                        $buffer .= $this->amountRow($ytdDeductLabel, $ytd['ytd_deduction']);
                        $buffer .= $this->amountRow($ytdNetLabel, $ytd['ytd_net']);
                    }
                    break;
            }
        }
        $flush();

        $bodyHtml = '';
        foreach ($sections as $section) {
            switch ($section['kind']) {
                case 'company_header':
                case 'logo':
                    $bodyHtml .= '<div class="company-block">' . $section['html'] . '</div>';
                    break;
                case 'info':
                    $bodyHtml .= '<table class="info-table"><tbody>' . $section['html'] . '</tbody></table>';
                    break;
                case 'earning':
                    $bodyHtml .= '<table><thead><tr><th colspan="2">' . htmlspecialchars($this->pick('รายได้', 'Earnings', $mode)) . '</th></tr></thead><tbody>' . $section['html'] . '</tbody></table>';
                    break;
                case 'deduction':
                    $bodyHtml .= '<table><thead><tr><th colspan="2">' . htmlspecialchars($this->pick('รายการหัก', 'Deductions', $mode)) . '</th></tr></thead><tbody>' . $section['html'] . '</tbody></table>';
                    break;
                case 'statutory':
                    $bodyHtml .= '<table><thead><tr><th colspan="2">' . htmlspecialchars($this->pick('หักตามกฎหมาย', 'Statutory Deductions', $mode)) . '</th></tr></thead><tbody>' . $section['html'] . '</tbody></table>';
                    break;
                case 'summary':
                    $bodyHtml .= '<table class="summary"><tbody>' . $section['html'] . '</tbody></table>';
                    break;
            }
        }

        $headerText = htmlspecialchars((string)$this->pick((string)($template['header_text_th'] ?? ''), (string)($template['header_text_en'] ?? ''), $mode));
        $footerText = htmlspecialchars((string)$this->pick((string)($template['footer_text_th'] ?? ''), (string)($template['footer_text_en'] ?? ''), $mode));

        return <<<HTML
<html>
<head><style>
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
h2 { font-size: 14px; margin-bottom: 2px; }
.company-block { margin-bottom: 6px; }
.company-line { font-size: 10px; color: #333; }
.logo-wrap { margin-bottom: 4px; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.info-table td { border: none; padding: 2px 6px; }
.amount { text-align: right; }
.summary td { font-weight: bold; }
.pdf-header, .pdf-footer { font-size: 10px; color: #555; margin-bottom: 6px; }
</style></head>
<body>
{$headerText}<div class="pdf-header"></div>
{$bodyHtml}
<div class="pdf-footer">{$footerText}</div>
</body>
</html>
HTML;
    }

    private function infoRow(string $label, string $value): string {
        return '<tr><td>' . htmlspecialchars($label) . '</td><td>' . $value . '</td></tr>';
    }

    private function amountRow(string $label, float $amount): string {
        return '<tr><td>' . htmlspecialchars($label) . '</td><td class="amount">' . number_format($amount, 2) . '</td></tr>';
    }
}
