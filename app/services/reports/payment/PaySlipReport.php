<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../PdfRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/PayslipTemplateModel.php';
require_once __DIR__ . '/../../../models/DocumentNumberingModel.php';
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
 *
 * 2026-09-05, Backlog Phase 12 T073 -- "generate once, persist, reuse" (Option C, chosen over
 * A/background-on-cycle-close and B/regenerate-every-time via AskUserQuestion). Was previously
 * regenerated from scratch on EVERY call regardless of caller (PayslipDeliveryService::deliver(),
 * PayslipController::myDownload(), a manual Reports-page download, a payslip_requests approval --
 * see PayslipDeliveryService's own docblock for those entry points). Now checks
 * `payroll_run_details.payslip_pdf_path` FIRST and serves the already-rendered file if one exists;
 * only the first-ever caller for a given (run, employee) actually renders and persists it, same
 * resolve-once-persist-reuse shape `resolvePayslipNumber()` (T061) already established on this
 * exact row. Safe because this method's own ALLOWED_STATES gate already guarantees the underlying
 * numbers are finalized by the time anyone can reach this point at all -- unlike Option A's own
 * "generate at cycle close" framing, there is no window where a cached file could go stale.
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
        $detail['payslip_number'] = $this->resolvePayslipNumber($compId, $runId, $employeeId, $detail['payslip_number'] ?? null);
        $fileName = "PaySlip_{$detail['employee_no']}_{$run['id']}.pdf";

        if ($format === 'pdf') {
            $cached = $this->readCachedPdf($detail['payslip_pdf_path'] ?? null);
            if ($cached !== null) {
                return ['content' => $cached, 'file_name' => $fileName, 'mime_type' => 'application/pdf'];
            }
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

        if ($format === 'pdf') {
            $this->persistPdf($compId, $runId, $employeeId, $content);
        }

        return [
            'content' => $content,
            'file_name' => $fileName,
            'mime_type' => 'application/pdf',
        ];
    }

    /** Returns the cached PDF's bytes if $relativePath is set AND the file still genuinely exists
     *  on disk, else null -- a null return always falls through to a fresh render, so a file that
     *  was deleted/moved out-of-band (or a path from a differently-configured environment) never
     *  hard-fails the payslip, it just re-renders and re-persists as if this were the first time. */
    private function readCachedPdf(?string $relativePath): ?string {
        if (empty($relativePath)) {
            return null;
        }
        $fullPath = __DIR__ . '/../../../../' . $relativePath;
        if (!is_file($fullPath)) {
            return null;
        }
        $content = @file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    /** Writes the freshly-rendered PDF to disk ONCE and persists its path onto this exact (run,
     *  employee) row -- same file-naming/storage convention as EmploymentCertificateRequestModel::
     *  issuePdf() (a random 32-hex filename under public/uploads/, comp_id-scoped). Best-effort,
     *  same "never let a caching side-effect block the real document" posture as
     *  resolvePayslipNumber() -- a failure here still returns the real, already-rendered content
     *  for THIS call, it just won't be cached yet (self-healing: the next caller renders and tries
     *  to persist again). */
    private function persistPdf(int $compId, int $runId, int $employeeId, string $pdfContent): void {
        try {
            $uploadDir = __DIR__ . "/../../../../public/uploads/payslip_files/{$compId}/";
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return;
            }
            $fileName = bin2hex(random_bytes(16)) . '.pdf';
            $relativePath = "public/uploads/payslip_files/{$compId}/{$fileName}";
            file_put_contents(__DIR__ . '/../../../../' . $relativePath, $pdfContent);
            Database::getInstance()->pdo->prepare(
                "UPDATE `payroll_run_details` SET payslip_pdf_path = :path WHERE run_id = :run_id AND employee_id = :employee_id"
            )->execute([':path' => $relativePath, ':run_id' => $runId, ':employee_id' => $employeeId]);
        } catch (Throwable $e) {
            // Swallow -- see this method's own docblock.
        }
    }

    /**
     * 2026-09-04, Backlog Phase 11, T061 -- a payslip is generated ON DEMAND (every time someone
     * downloads it), not at a one-time "create" event the way a payroll run has -- calling
     * DocumentNumberingModel::generateNext() unconditionally on every generate() call would
     * silently assign a NEW number each time the SAME real payslip is re-downloaded, which is
     * wrong. This stamps the number ONCE, on the first real generation of this exact (run,
     * employee) pair, and persists it to payroll_run_details.payslip_number so every later
     * re-download reuses the identical value. Best-effort/non-blocking, same posture
     * PayrollRunModel::create() already established for PAYROLL_RUN's own run_code -- a numbering
     * failure (null from generateNext(), or the persistence UPDATE itself failing) must never
     * block the payslip from being generated; it just renders with no document number that time,
     * self-healing on the next successful call.
     */
    private function resolvePayslipNumber(int $compId, int $runId, int $employeeId, ?string $existing): ?string {
        if (!empty($existing)) {
            return $existing;
        }
        $code = (new DocumentNumberingModel())->generateNext($compId, 'PAYSLIP');
        if ($code === null) {
            return null;
        }
        try {
            Database::getInstance()->pdo->prepare(
                "UPDATE `payroll_run_details` SET payslip_number = :code WHERE run_id = :run_id AND employee_id = :employee_id"
            )->execute([':code' => $code, ':run_id' => $runId, ':employee_id' => $employeeId]);
        } catch (Throwable $e) {
            // Persistence failed but the code itself was validly generated -- still return it for
            // THIS render (better than nothing), even though it won't be reused on the next
            // download. Same "never let a numbering side-effect block the real document" posture.
        }
        return $code;
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
        // 2026-09-04, Backlog Phase 11, T061 -- DocumentNumberingModel-generated code (see
        // resolvePayslipNumber()), only shown when one was actually assigned (a numbering failure
        // must never block/blank the rest of the payslip -- see that method's own docblock).
        $docNoLine = !empty($detail['payslip_number'])
            ? '<div>เลขที่เอกสาร / Document No.: ' . htmlspecialchars((string)$detail['payslip_number']) . '</div>'
            : '';
        return <<<HTML
<html>
<head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
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
{$docNoLine}
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
