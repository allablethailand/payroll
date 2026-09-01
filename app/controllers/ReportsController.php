<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/reports/ReportRegistry.php';
require_once __DIR__ . '/../services/reports/LocalizedException.php';
require_once __DIR__ . '/../services/reports/ExcelRendererTrait.php';
require_once __DIR__ . '/../models/ReportExportLogModel.php';
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

class ReportsController extends Controller {
    use ExcelRendererTrait;

    private $logModel;
    private PayrollRunModel $payrollRunModel;
    private PayrollReportDataModel $reportDataModel;
    private PermissionModel $permissionModel;
    private ?bool $companySsoActiveCache = null;

    /**
     * 2026-08-27: the "Per-Cycle Reports" matrix table lists one row per run in any of these
     * states -- the SAME set every individual cycle report generator already gates on via its own
     * ALLOWED_STATES const (see e.g. PndOneReport/Sso110Report/PaySlipReport). Kept as one
     * constant here rather than importing each report's own const, since this is a display-layer
     * concern (which runs even show up as rows) independent of any one report's own generation
     * gate -- a report whose row *is* listed can still individually refuse a state it doesn't
     * accept (defense in depth, not expected to ever actually diverge today).
     */
    private const CYCLE_REPORT_STATES = ['approved', 'paid', 'locked'];

    public function __construct() {
        $this->logModel = new ReportExportLogModel();
        $this->payrollRunModel = new PayrollRunModel();
        $this->reportDataModel = new PayrollReportDataModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** Every registered report exposes salary/bank/statutory PII for the whole run/period -- same gate as viewing a payroll run. */
    private function requireViewAccess(): bool {
        if (!$this->payrollRunModel->canView($this->userId(), $this->isAdmin())) {
            $this->json(['status' => false, 'message' => 'You do not have permission to generate or view reports.']);
            return false;
        }
        return true;
    }

    public function index() {
        $this->view('reports/index');
    }

    /**
     * 2026-08-31, explicit request: "รายการการนำส่งประกันสังคม...ต้องให้ยึดตามสิทธิ์ว่า ถ้าบริษัทไม่หัก
     * ประกันสังคม ก็จะไม่เห็น Report ส่วนนี้" -- a COMPANY-WIDE gate, distinct from the existing
     * per-RUN `any_sso` applicability check (calcApplicabilitySummary()) already used below --
     * `any_sso` can be false just because one particular run happened to have zero SSO-enrolled
     * employees, while this checks whether the company has TH_SSO turned on at all
     * (company_statutory_settings' own effective_status, same fallback-to-default logic
     * CompanyStatutorySettingModel::list() already implements -- not reimplemented here). A non-TH
     * company (no TH_SSO row in its own country's statutory_items at all) is treated the same as
     * "inactive" -- never shows SSO reports. Cached per-request since this is checked from multiple
     * methods below (list()/runReportsSummary()/runCycleReportsSummary()) that can all be hit in
     * the same page load.
     */
    private function companySsoActive(int $compId): bool {
        if ($this->companySsoActiveCache !== null) {
            return $this->companySsoActiveCache;
        }
        $active = false;
        foreach ((new CompanyStatutorySettingModel())->list($compId) as $item) {
            if ($item['code'] === 'TH_SSO') {
                $active = $item['effective_status'] === 'active';
                break;
            }
        }
        $this->companySsoActiveCache = $active;
        return $active;
    }

    // 2026-08-31: SSO report codes gated company-wide, see companySsoActive()'s own docblock --
    // this is the single list() every page's report list (incl. the Annual Reports tab's own
    // client-side REPORT_META filtering) ultimately derives from.
    private const SSO_REPORT_CODES = ['TH_SSO110', 'TH_SSO609'];
    public function list() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $ssoActive = $this->companySsoActive((int)$compId);
        $data = [];
        foreach (ReportRegistry::all() as $report) {
            if (!$ssoActive && in_array($report->code(), self::SSO_REPORT_CODES, true)) {
                continue;
            }
            $data[] = [
                'code' => $report->code(),
                'report_type' => $report->reportType(),
                'label' => $report->label(),
                'supported_formats' => $report->supportedFormats(),
            ];
        }
        $this->json(['status' => true, 'data' => $data]);
    }

    /**
     * GET (not POST/JSON) on purpose — this response is a binary file download, which the
     * browser can only save natively via a plain navigation/anchor click, not an XHR blob
     * without extra client-side plumbing. All context fields are simple scalars, so a query
     * string is a perfectly natural fit; error responses (JSON) work the same either way.
     *
     * 2026-08-29, explicit request: "มีปุ่มสำหรับกด Download กดแล้วเปิด modal เพื่อ Preview ก่อน" -- a
     * `preview=1` request renders the SAME file inline (Content-Disposition: inline, so the browser
     * shows it in an <iframe> instead of triggering a save dialog) and is deliberately NOT logged --
     * only a real download (Download Thai/English button, no preview flag) counts toward the
     * download history/count this same request added. `source` (which screen triggered this,
     * e.g. 'process_detail') and the request's own IP/User-Agent are captured on every LOGGED
     * (non-preview) download -- see ReportExportLogModel::log()'s own docblock.
     */
    public function generate() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        // 2026-08-31, explicit request: "สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX แต่ยังสามารถคำนวณ
        // เงินเดือน...ได้ตามสิทธิ์" -- unlike Employee/Payroll Process, this gates the WHOLE download
        // rather than masking individual figures: a generated PDF/Excel file is a static artifact,
        // not a live response this app can selectively redact fields from after the fact. Every
        // registered report is inherently a payroll/financial document (statutory/payment/internal
        // -- there is no "money-free" report type in this app), so salary_amount.view_reports gates
        // generate() uniformly. own_only/summary have no meaningful application to a whole-file
        // download (no single "subject employee" the file is about, and no way to redact a PDF's
        // own line items after PhpSpreadsheet/dompdf has already rendered them) -- only a real
        // 'full' grant (or admin) can generate/preview/download ANY report.
        $reportVisibility = $this->permissionModel->resolveSalaryVisibility($this->userId(), 'reports', $this->isAdmin(), (int)$compId);
        if (!$reportVisibility['full']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to view salary amounts in reports.']);
            return;
        }
        $code = (string)($_GET['report_code'] ?? '');
        $format = (string)($_GET['format'] ?? '');
        $report = ReportRegistry::get($code);
        if (!$report) {
            $this->json(['status' => false, 'message' => 'Unknown report_code.']);
            return;
        }
        if (!in_array($format, $report->supportedFormats(), true)) {
            $this->json(['status' => false, 'message' => 'Unsupported format for this report.']);
            return;
        }
        $isPreview = !empty($_GET['preview']);

        $context = [];
        // 2026-08-29, explicit request: "ตอน Export ให้เลือกเพิ่มเติมได้ว่าเอาภาษาไทยหรือภาษาอังกฤษ ข้อมูลที่
        // ออกมาจะตามนั้น" -- 'language' whitelisted here so BankTransferFileReport (currently the
        // only consumer, see its own generate()) can pick th/en for the employee name field; any
        // report that doesn't read context['language'] simply ignores it, same as an unused
        // year/month/employee_id already does today.
        // 2026-08-31: 'state'/'date_from'/'date_to' whitelisted for PAYROLL_RUN_LIST_SUMMARY (the
        // ONLY consumer today) -- mirrors the exact same filter shape the Process List page's own
        // PayrollRunModel::list() already accepts, so the export reflects whatever the admin is
        // currently looking at, same "unused key is simply ignored" tolerance as every other
        // report not reading a given context key.
        foreach (['year', 'month', 'run_id', 'employee_id', 'language', 'state', 'date_from', 'date_to'] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $context[$key] = $_GET[$key];
            }
        }
        $context['comp_id'] = (int)$compId;

        try {
            $result = $report->generate($context, $format);
        } catch (LocalizedException $e) {
            // 2026-08-30, real bug found and fixed: LocalizedException (error_key + params for
            // frontend i18n translation, see its own class docblock) has been thrown by 9 of the 10
            // report generators for a while, but this catch block only ever sent back
            // $e->getMessage() -- the raw English fallback -- never error_key/params. The whole
            // mechanism was inert: translateApiError() (app.js) had nothing to translate against
            // since the response never carried an error_key at all. Caught BEFORE the generic
            // RuntimeException below since LocalizedException extends it.
            $this->json(['status' => false, 'message' => $e->getMessage(), 'error_key' => $e->getErrorKey(), 'params' => $e->getParams()]);
            return;
        } catch (InvalidArgumentException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage()]);
            return;
        } catch (RuntimeException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage()]);
            return;
        }

        if (!$isPreview) {
            $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
            // Same trusted-source-only convention every other IP/UA capture in this app follows
            // (see EmployeeLoginLogModel's own docblock) -- read straight off the request, never
            // accepted as a client-suppliable field. `source` is a short free-form label the caller
            // passes (e.g. 'process_detail') describing which screen triggered this, whitelisted to
            // a short length only to keep the column bounded, not for any access-control reason.
            $source = isset($_GET['source']) ? substr((string)$_GET['source'], 0, 30) : null;
            $this->logModel->log(
                (int)$compId,
                $report->reportType(),
                $report->code(),
                $result['file_name'],
                $format,
                isset($context['year']) ? (int)$context['year'] : null,
                isset($context['month']) ? (int)$context['month'] : null,
                isset($context['run_id']) ? (int)$context['run_id'] : null,
                $userId ?: null,
                (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                isset($context['language']) ? (string)$context['language'] : null,
                $source,
                // 2026-08-29: attributes this download to the employee it was FOR (PAY_SLIP/
                // PAYMENT_VOUCHER) so a per-employee download count is possible -- see
                // ReportExportLogModel::perEmployeeSummaryForRun()'s own docblock. Every other
                // report simply has no employee_id in its context, same "unused key" tolerance.
                isset($context['employee_id']) ? (int)$context['employee_id'] : null
            );
        }

        header('Content-Type: ' . $result['mime_type']);
        header('Content-Disposition: ' . ($isPreview ? 'inline' : 'attachment') . '; filename="' . $result['file_name'] . '"');
        header('Content-Length: ' . strlen($result['content']));
        echo $result['content'];
        exit;
    }

    /**
     * 2026-08-29, explicit request: backs the Process Detail page's own new "Reports" tab table --
     * one row per report shortcut applicable to this run (same TH_SSO110/TH_PND1/BANK_TRANSFER_FILE
     * set + any_tax/any_sso hiding the Print Reports dropdown already used, see
     * PayrollRunModel::calcApplicabilitySummary()'s own docblock), each carrying its own
     * download_count/last_downloaded_at from ReportExportLogModel::summaryForRun().
     */
    private const RUN_REPORT_SHORTCUTS = [
        ['code' => 'TH_SSO110', 'format' => 'pdf', 'requires' => 'sso'],
        ['code' => 'TH_PND1', 'format' => 'pdf', 'requires' => 'tax'],
        ['code' => 'BANK_TRANSFER_FILE', 'format' => 'csv', 'requires' => null],
        // 2026-08-31, explicit request: "ในหน้า List และ Detail ของการทำรอบ อยากให้มีการ Export Excel ได้
        // ไม่ว่าจะสถานะไหน...เป็นตารางเพื่อดึงแต่ละค่า รวมถึงยอดสรุป" -- PAYROLL_REGISTER already exists
        // (internal report, no assertRunStateOrThrow() call at all -- allowed on ANY state including
        // draft, exactly what "ไม่ว่าจะสถานะไหน" asks for) and already produces a full itemized
        // per-employee breakdown; just needed surfacing here so it's directly downloadable from the
        // Detail page's own Reports tab, not only reachable via the separate Reports page.
        ['code' => 'PAYROLL_REGISTER', 'format' => 'excel', 'requires' => null],
    ];
    public function runReportsSummary() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        $applicability = $this->payrollRunModel->calcApplicabilitySummary($runId, (int)$compId);
        $downloadSummary = $this->logModel->summaryForRun((int)$compId, $runId);
        $rows = [];
        $ssoActive = $this->companySsoActive((int)$compId);
        foreach (self::RUN_REPORT_SHORTCUTS as $shortcut) {
            if ($shortcut['requires'] === 'tax' && !$applicability['any_tax']) continue;
            // 2026-08-31: company-wide gate (companySsoActive()) alongside the existing per-run
            // `any_sso` -- either one being false hides the row.
            if ($shortcut['requires'] === 'sso' && (!$applicability['any_sso'] || !$ssoActive)) continue;
            $report = ReportRegistry::get($shortcut['code']);
            if (!$report) continue;
            $summary = $downloadSummary[$shortcut['code']] ?? ['download_count' => 0, 'last_downloaded_at' => null];
            $rows[] = [
                'code' => $shortcut['code'],
                'format' => $shortcut['format'],
                'supports_preview' => $shortcut['format'] === 'pdf',
                'label' => $report->label(),
                'download_count' => $summary['download_count'],
                'last_downloaded_at' => $summary['last_downloaded_at'],
                // 2026-08-31: lets the frontend gate readiness PER ROW instead of one blanket
                // approved/paid/locked check for the whole table -- PAYROLL_REGISTER (internal,
                // no assertRunStateOrThrow() call) is downloadable on ANY state including draft,
                // unlike the statutory/payment shortcuts above it in RUN_REPORT_SHORTCUTS.
                'report_type' => $report->reportType(),
            ];
        }
        $this->json(['status' => true, 'data' => $rows]);
    }

    /**
     * 2026-08-29, explicit request: "ใน /payroll/reports...ใช้หลักการ Download แบบเดียวกับหน้า Process"
     * -- the Reports page's own "Per-Cycle Reports" tab needs EVERY cycle-frequency report (not just
     * the 3 shortcuts runReportsSummary() above serves the Process Detail page's own Reports tab),
     * one row per report with the same download_count/last_downloaded_at/supports_preview shape so
     * the SAME preview-first Download UI can be reused verbatim. This list mirrors
     * public/js/reports/index.js's own REPORT_META `frequency:'cycle'` set exactly -- keep both in
     * sync if a report's frequency ever changes (no server-side frequency() method on
     * ReportGeneratorInterface exists to derive this from; adding one would mean touching every
     * report class for a purely display-layer concern, not worth it for a 6-entry list).
     */
    private const CYCLE_REPORT_CODES = ['TH_PND1', 'TH_SSO110', 'TH_SLF', 'PAY_SLIP', 'BANK_TRANSFER_FILE', 'PAYROLL_REGISTER'];
    public function runCycleReportsSummary() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        $applicability = $this->payrollRunModel->calcApplicabilitySummary($runId, (int)$compId);
        $downloadSummary = $this->logModel->summaryForRun((int)$compId, $runId);
        $rows = [];
        $ssoActive = $this->companySsoActive((int)$compId);
        foreach (self::CYCLE_REPORT_CODES as $code) {
            if ($code === 'TH_PND1' && !$applicability['any_tax']) continue;
            // 2026-08-31: company-wide gate (companySsoActive()) alongside the existing per-run
            // `any_sso` -- either one being false hides the row.
            if ($code === 'TH_SSO110' && (!$applicability['any_sso'] || !$ssoActive)) continue;
            $report = ReportRegistry::get($code);
            if (!$report) continue;
            $formats = $report->supportedFormats();
            $format = in_array('pdf', $formats, true) ? 'pdf' : ($formats[0] ?? 'pdf');
            $summary = $downloadSummary[$code] ?? ['download_count' => 0, 'last_downloaded_at' => null];
            $rows[] = [
                'code' => $code,
                'report_type' => $report->reportType(),
                'format' => $format,
                'supports_preview' => $format === 'pdf',
                // 2026-08-29: signals the frontend to open the employee-roster picker (see
                // payslipRoster() below) instead of previewing/downloading directly -- PAY_SLIP is
                // the only cycle report scoped to one employee at a time, not the whole run.
                'per_employee' => $code === 'PAY_SLIP',
                'label' => $report->label(),
                'download_count' => $summary['download_count'],
                'last_downloaded_at' => $summary['last_downloaded_at'],
            ];
        }
        $this->json(['status' => true, 'data' => $rows]);
    }

    /**
     * 2026-08-29, explicit request: "ตรงที่ปริ้น Slip ของพนักงาน ปรับให้ขึ้นเป็นรายชื่อพนักงานมาเลย และ emp
     * code ด้วย แผนกตำแหน่งทีม และมีปุ่มให้กด Download และแสดงด้วยว่า Download ไปแล้วกี่ครั้ง" -- combines
     * PayrollRunModel::employeeRosterForReports() with per-employee download counts for whichever
     * per-employee report the picker is opened for (PAY_SLIP today; `report_code` is a real param,
     * not hardcoded, so this same endpoint already works for a future per-employee report without
     * changes).
     */
    public function payslipRoster() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        $reportCode = (string)($_GET['report_code'] ?? 'PAY_SLIP');
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        $roster = $this->payrollRunModel->employeeRosterForReports($runId, (int)$compId);
        $counts = $this->logModel->perEmployeeSummaryForRun((int)$compId, $runId, $reportCode);
        foreach ($roster as &$row) {
            $c = $counts[(int)$row['employee_id']] ?? ['download_count' => 0, 'last_downloaded_at' => null];
            $row['download_count'] = $c['download_count'];
            $row['last_downloaded_at'] = $c['last_downloaded_at'];
        }
        unset($row);
        $this->json(['status' => true, 'data' => $roster]);
    }

    /**
     * 2026-08-27, explicit request: "ปรับให้เป็นตาราง เลย เป็นแถวละ 1 รอบที่เสร็จแล้ว...โดยเรียงจาก
     * รอบล่าสุดขึ้นหัวตาราง" -- backs the Per-Cycle Reports matrix table's rows (one completed
     * payroll run per row, newest pay period first).
     */
    public function cycleRuns() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->reportDataModel->getCompletedRuns((int)$compId, self::CYCLE_REPORT_STATES)]);
    }

    /**
     * 2026-08-30, explicit request: "filter ปีให้เลือกจากปีที่มีข้อมูลจริง" -- backs the Annual Reports
     * tab's year dropdown (was a free-typed number input). Same CYCLE_REPORT_STATES gate as
     * cycleRuns()/runCycleReportsSummary() above -- a year is only offered if it has at least one
     * run in a state annual reports are actually allowed to read from.
     */
    /**
     * 2026-08-30, explicit request: "รายงานประจำปี อยากให้เป็นตารางครับ" -- backs the Annual Reports
     * table's own Downloads/Last Downloaded columns for whichever year is currently selected.
     * `year` here is the SAME Buddhist-Era value the year dropdown already holds/sends to
     * report.generate (report_export_logs.period_year is stored in B.E., not converted -- see
     * generate()'s own log() call above, which just persists $context['year'] verbatim).
     */
    public function annualReportsSummary() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
        if (!$compId || $year <= 0) {
            $this->json(['status' => false, 'message' => 'Missing year.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->logModel->summaryForYear((int)$compId, $year)]);
    }

    public function availableYears() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $years = $this->reportDataModel->availableReportYears((int)$compId, self::CYCLE_REPORT_STATES);
        $this->json(['status' => true, 'data' => array_map(fn($y) => $y + 543, $years)]);
    }

    public function exportLogs() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $filters = [
            'report_type' => (string)($_GET['report_type'] ?? ''),
            'report_code' => (string)($_GET['report_code'] ?? ''),
            'period_year' => isset($_GET['period_year']) ? (int)$_GET['period_year'] : null,
            'period_month' => isset($_GET['period_month']) ? (int)$_GET['period_month'] : null,
            'payroll_run_id' => isset($_GET['payroll_run_id']) ? (int)$_GET['payroll_run_id'] : null,
            'format' => (string)($_GET['format'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
        ];
        $this->json(['status' => true, 'data' => $this->logModel->list((int)$compId, $filters)]);
    }

    /* ==================== Payroll Run Audit (2026-08-31, item 10, "Design ให้หน่อยครับ No Idea")
       ====================
       A drill-down diff report over payroll_run_line_override_history (see PayrollRunModel's own
       runAuditList()/lineOverrideAuditDiff()) -- interactive list+detail page, not a
       generate-and-download document, so it's a plain page action + JSON endpoints here rather than
       a ReportGeneratorInterface/ReportRegistry entry (same "interactive page" precedent
       AnnualIncomeSummaryController already established). Reuses requireViewAccess() (same gate
       every other report in this controller already uses) rather than inventing a new permission
       key nothing in this batch's request asked for. */

    public function runAudit() {
        if (!$this->payrollRunModel->canView($this->userId(), $this->isAdmin())) {
            $this->view('permission');
            return;
        }
        $this->view('reports/run-audit');
    }

    public function runAuditList() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $dateFrom = (string)($_GET['date_from'] ?? '');
        $dateTo = (string)($_GET['date_to'] ?? '');
        $this->json(['status' => true, 'data' => $this->payrollRunModel->runAuditList((int)$compId, $dateFrom ?: null, $dateTo ?: null)]);
    }

    /**
     * 2026-08-31: masks every itemized old_value/new_value/original_value/current_value with
     * PermissionModel::MASK_VALUE when the caller doesn't have FULL salary_amount.view_payroll_process
     * visibility -- this report is entirely itemized payroll figures, so anything short of 'full'
     * (masked outright, or 'summary' detail_level which only ever means totals/net figures are
     * visible, never a line-by-line breakdown) must not see the real numbers here. Same
     * response-layer-only masking convention as PayrollController::maskRunMonetaryFields() -- never
     * touches the underlying model/calculation.
     */
    private function maskAuditDiffLines(array $lines, int $compId): array {
        $visibility = $this->permissionModel->resolveSalaryVisibility($this->userId(), 'payroll_process', $this->isAdmin(), $compId);
        if ($visibility['full']) {
            return $lines;
        }
        foreach ($lines as &$line) {
            $line['original_value'] = $line['original_value'] !== null ? PermissionModel::MASK_VALUE : null;
            $line['current_value'] = $line['current_value'] !== null ? PermissionModel::MASK_VALUE : null;
            foreach ($line['edits'] as &$edit) {
                $edit['old_value'] = $edit['old_value'] !== null ? PermissionModel::MASK_VALUE : null;
                $edit['new_value'] = $edit['new_value'] !== null ? PermissionModel::MASK_VALUE : null;
            }
            unset($edit);
        }
        unset($line);
        return $lines;
    }

    public function runAuditDiff() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        $result = $this->payrollRunModel->lineOverrideAuditDiff($runId, (int)$compId);
        $result['lines'] = $this->maskAuditDiffLines($result['lines'], (int)$compId);
        $this->json(['status' => true, 'data' => $result]);
    }

    /** Excel export of the currently-viewed diff table (one row per employee/item, one column per edit). */
    public function runAuditExport() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        $run = $this->payrollRunModel->get($runId, (int)$compId);
        if (!$run) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $result = $this->payrollRunModel->lineOverrideAuditDiff($runId, (int)$compId);
        $lines = $this->maskAuditDiffLines($result['lines'], (int)$compId);

        $maxEdits = 0;
        foreach ($lines as $l) {
            $maxEdits = max($maxEdits, count($l['edits']));
        }
        $headers = ['Employee No', 'Employee Name', 'Line Type', 'Item Code', 'Original'];
        for ($i = 1; $i <= $maxEdits; $i++) {
            $headers[] = "Edit {$i}";
        }
        $headers[] = 'Current';

        $rows = [];
        foreach ($lines as $l) {
            $row = [
                $l['employee_no'], $l['employee_name_th'], $l['line_type'], $l['item_code'],
                $l['original_value'],
            ];
            for ($i = 0; $i < $maxEdits; $i++) {
                $edit = $l['edits'][$i] ?? null;
                $row[] = $edit ? ($edit['new_value'] . ' (' . ($edit['changed_by_name_th'] ?? '') . ', ' . $edit['changed_at'] . ')') : '';
            }
            $row[] = $l['current_value'];
            $rows[] = $row;
        }

        $bytes = $this->renderExcelFromRows($headers, $rows, 'Run Audit');
        $filename = 'payroll_run_audit_' . $runId . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }
}
