<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/reports/ReportRegistry.php';
require_once __DIR__ . '/../models/ReportExportLogModel.php';

class ReportsController extends Controller {
    private $logModel;

    public function __construct() {
        $this->logModel = new ReportExportLogModel();
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

    public function exportLogs() {
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
