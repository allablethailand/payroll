<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/reports/ReportRegistry.php';
require_once __DIR__ . '/../models/ReportExportLogModel.php';
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../models/PayrollReportDataModel.php';

class ReportsController extends Controller {
    private $logModel;
    private PayrollRunModel $payrollRunModel;
    private PayrollReportDataModel $reportDataModel;

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

    public function list() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $data = array_map(function ($report) {
            return [
                'code' => $report->code(),
                'report_type' => $report->reportType(),
                'label' => $report->label(),
                'supported_formats' => $report->supportedFormats(),
            ];
        }, ReportRegistry::all());
        $this->json(['status' => true, 'data' => $data]);
    }

    /**
     * GET (not POST/JSON) on purpose — this response is a binary file download, which the
     * browser can only save natively via a plain navigation/anchor click, not an XHR blob
     * without extra client-side plumbing. All context fields are simple scalars, so a query
     * string is a perfectly natural fit; error responses (JSON) work the same either way.
     */
    public function generate() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
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

        $context = [];
        foreach (['year', 'month', 'run_id', 'employee_id'] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $context[$key] = $_GET[$key];
            }
        }
        $context['comp_id'] = (int)$compId;

        try {
            $result = $report->generate($context, $format);
        } catch (InvalidArgumentException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage()]);
            return;
        } catch (RuntimeException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage()]);
            return;
        }

        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $this->logModel->log(
            (int)$compId,
            $report->reportType(),
            $report->code(),
            $result['file_name'],
            $format,
            isset($context['year']) ? (int)$context['year'] : null,
            isset($context['month']) ? (int)$context['month'] : null,
            isset($context['run_id']) ? (int)$context['run_id'] : null,
            $userId ?: null
        );

        header('Content-Type: ' . $result['mime_type']);
        header('Content-Disposition: attachment; filename="' . $result['file_name'] . '"');
        header('Content-Length: ' . strlen($result['content']));
        echo $result['content'];
        exit;
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

    public function exportLogs() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $filters = [
            'report_type' => (string)($_GET['report_type'] ?? ''),
            'period_year' => isset($_GET['period_year']) ? (int)$_GET['period_year'] : null,
            'period_month' => isset($_GET['period_month']) ? (int)$_GET['period_month'] : null,
            'payroll_run_id' => isset($_GET['payroll_run_id']) ? (int)$_GET['payroll_run_id'] : null,
            'format' => (string)($_GET['format'] ?? ''),
        ];
        $this->json(['status' => true, 'data' => $this->logModel->list((int)$compId, $filters)]);
    }
}
