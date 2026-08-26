<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../PdfRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/PayslipTemplateModel.php';
require_once __DIR__ . '/../../PayslipTemplateRenderer.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * Pay Slip — one PDF per employee for a given payroll run, showing the earning/deduction/
 * statutory breakdown captured at calculation time. Requires the run to be at least Approved
 * (see PayrollReportDataModel::assertRunState) since a pay slip is an official pay document
 * and must not be issued from numbers that can still change.
 *
 * Template-aware: if the company has an active default Payslip Template (Document & Approval >
 * "เทมเพลตสลิปเงินเดือน"), renders through PayslipTemplateRenderer::renderForRun() using that
 * template's free-form canvas elements (2026-08-25, explicit request: "ปรับให้การตั้งค่า Slip
 * เงินเดือน Template เป็นเหมือนกับใบรับรอง" -- the old ordered field-list rendering
 * (buildTemplatedHtml()) was retired along with `payslip_template_fields`, see
 * PayslipTemplateModel's own docblock). If no default template exists (the common case for any
 * company that hasn't set one up yet), falls back to the original fixed buildHtml() layout
 * unchanged -- this class's behavior is 100% backward compatible until an admin opts in.
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
     * @param array $context { comp_id: int, run_id: int, employee_id: int, language?: 'th'|'en' }
     *   2026-08-25 follow-up ("รูปแบบการทำเหมือนกัน") -- PayslipTemplateModel dropped `language_mode`
     *   in favor of a real per-language `language`/`pair_key` pair like Employment Certificate
     *   Template, so a payslip now has to pick exactly ONE language to render in. Defaults to 'th'
     *   (matching where the one real pre-existing template landed during the migration) when the
     *   caller doesn't specify -- same as EmploymentCertificateTemplateController's own endpoints,
     *   which always require an explicit language rather than guessing, but this report has no admin
     *   picking a language per-request today, so a safe default keeps every existing call site working
     *   unchanged.
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

        $requestedLanguage = (string)($context['language'] ?? 'th');
        $language = in_array($requestedLanguage, ['th', 'en'], true) ? $requestedLanguage : 'th';
        $templateModel = new PayslipTemplateModel();
        // 2026-08-25, explicit request: "สามารถ Assign ตั้งค่าให้พนักงาน เป็นรายแผนก รายทีม หรือรายคน
        // หรือใช้งานร่วมกันทั้งหมดก็ได้" -- resolves the employee-specific/team/department-scoped
        // template if one is assigned, falling back to the company's unscoped is_default template for
        // this language (getDefault()'s own existing behavior, unchanged) when nothing more specific
        // matches this employee.
        $template = $templateModel->resolveTemplateForEmployee($compId, $employeeId, $language);

        if ($template !== null && !empty($template['elements'])) {
            $ytd = null;
            foreach ($template['elements'] as $el) {
                if (($el['element_type'] ?? '') === 'text' && trim((string)($el['content'] ?? '')) === '{{ytd_summary}}') {
                    $ytd = $dataModel->getYtdTotals($compId, (int)$detail['employee_id'], (string)$run['period_start_date'], self::ALLOWED_STATES);
                    break;
                }
            }
            $content = (new PayslipTemplateRenderer())->renderForRun($compId, $template, $template['elements'], $company ?? [], $run, $detail, $ytd);
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
            $content = $this->renderPdfFromHtml($html, 'A5', 'portrait');
        }

        return [
            'content' => $content,
            'file_name' => "PaySlip_{$detail['employee_no']}_{$run['id']}.pdf",
            'mime_type' => 'application/pdf',
        ];
    }

    /* ==================== Original fixed layout (fallback when no template is set) ==================== */

    // Same 3-line helper as EmploymentCertificateRenderer::formatDate()/PayslipTemplateRenderer::
    // formatDate() -- not worth extracting into a shared trait for something this small with no
    // state dependency.
    private function formatDate(?string $ymd): string {
        if (empty($ymd)) {
            return '-';
        }
        $ts = strtotime($ymd);
        return $ts !== false ? date('d/m/Y', $ts) : $ymd;
    }

    private function buildHtml(string $companyName, array $run, array $detail, string $employeeName, string $earningRows, string $deductionRows): string {
        $gross = number_format((float)$detail['gross_amount'], 2);
        $deduct = number_format((float)$detail['total_deduction_amount'], 2);
        $net = number_format((float)$detail['net_amount'], 2);
        // 2026-08-26, explicit request: "Format วันที่การแสดงผลทั้งหมดของระบบให้เป็น dd/mm/yyyy" -- this
        // was embedding the raw ISO ('YYYY-MM-DD') pay period straight into the fallback payslip PDF
        // (the layout used whenever a company hasn't set up a canvas template yet -- see this class's
        // own generate() docblock, the common case).
        $period = htmlspecialchars($this->formatDate($run['period_start_date']) . ' - ' . $this->formatDate($run['period_end_date']));
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
