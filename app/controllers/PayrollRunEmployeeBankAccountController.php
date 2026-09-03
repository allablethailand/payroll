<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRunEmployeeBankAccountModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../services/reports/LocalizedException.php';

/**
 * 2026-09-02, "Bank Account Assignment" tab on Process Detail -- see
 * PayrollRunEmployeeBankAccountModel's own docblock for the full design.
 */
class PayrollRunEmployeeBankAccountController extends Controller {
    private PayrollRunEmployeeBankAccountModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new PayrollRunEmployeeBankAccountModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    public function list() {
        if (!$this->requirePermission('payroll_run.view')) return;
        $compId = getCompId();
        $runId = (int)($_GET['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        try {
            $this->json(['status' => true, 'data' => $this->model->listForRun($runId, (int)$compId)]);
        } catch (LocalizedException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage(), 'error_key' => $e->getErrorKey(), 'error_params' => $e->getParams()]);
        }
    }

    // 2026-09-03, Platform Hardening Phase 3 Stage 3: swapped from the retired coarse
    // `payroll_run.manage` onto `.process`, not `.edit` -- every other true-CRUD-shaped sub-resource
    // override on an existing run (lineOverride*/statutoryLineOverride*/
    // recurringDeductionDestinationOverride*/attendanceOverride*/joinEmployees/etc. in
    // PayrollRunModel) uses `.process` too; `.add`/`.edit`/`.delete` are reserved for the run HEADER
    // itself (PayrollController::save()/delete()), not per-employee overrides within one.
    public function save() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $runId = (int)($data['id'] ?? 0);
        $employeeId = (int)($data['employee_id'] ?? 0);
        $bankAccountId = (int)($data['bank_account_id'] ?? 0);
        $note = isset($data['note']) ? (string)$data['note'] : null;
        if (!$compId || $runId <= 0 || $employeeId <= 0 || $bankAccountId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing required field.']);
            return;
        }
        $this->json($this->model->overrideSave($runId, (int)$compId, $employeeId, $bankAccountId, $note, $this->userId()));
    }

    public function remove() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $runId = (int)($data['id'] ?? 0);
        $employeeId = (int)($data['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing required field.']);
            return;
        }
        $this->json($this->model->overrideRemove($runId, (int)$compId, $employeeId));
    }
}
