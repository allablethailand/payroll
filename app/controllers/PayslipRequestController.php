<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayslipRequestModel.php';

/**
 * Mode B (employee-initiated, HR-proxied for now) payslip request creation + listing. Approve
 * /Reject/Cancel deliberately live on the existing generic Approval Monitor tab
 * (`/api/approval-request.act`) -- see PayslipRequestModel docblock. No permission gate here,
 * matching PayslipTemplateController's precedent (RBAC in this project is scoped to Holiday/
 * Leave Type/Approval Workflow only, per CLAUDE.md).
 */
class PayslipRequestController extends Controller {
    private PayslipRequestModel $model;

    public function __construct() {
        $this->model = new PayslipRequestModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    public function list() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId)]);
    }

    public function get() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->get((int)$compId, $id);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function runOptions() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => ['items' => $this->model->eligibleRuns((int)$compId), 'total_count' => 0]]);
    }

    public function employeeOptions() {
        $compId = getCompId();
        $runId = (int)($_POST['run_id'] ?? 0);
        if ($runId <= 0) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $this->json(['status' => true, 'data' => ['items' => $this->model->eligibleEmployeesForRun((int)$compId, $runId), 'total_count' => 0]]);
    }

    public function create() {
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $employeeId = (int)($data['employee_id'] ?? 0);
        $runId = (int)($data['run_id'] ?? 0);
        $this->json($this->model->create((int)$compId, $employeeId, $runId, $this->userId()));
    }
}
