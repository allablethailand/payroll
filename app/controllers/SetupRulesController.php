<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/SetupRulesModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

class SetupRulesController extends Controller {
    private SetupRulesModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new SetupRulesModel();
        $this->permissionModel = new PermissionModel();
    }

    public function index() {
        $this->view('setup-rules/index');
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** Holiday/Leave Type only (the RBAC rollout's agreed scope) -- Shift/Work Location stay ungated. */
    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    /* ==================== SHIFT ==================== */

    public function shiftList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->shiftList((int)$compId)]);
    }

    public function shiftGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->shiftGet($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function shiftSave() {
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->shiftSave($data, (int)$compId, $this->userId()));
    }

    public function shiftToggleStatus() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->shiftToggleStatus($id, (int)$compId, $this->userId()));
    }

    public function shiftDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->shiftDelete($id, (int)$compId, $this->userId()));
    }

    /* ==================== HOLIDAY ==================== */

    public function holidayList() {
        if (!$this->requirePermission('holiday.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->holidayList((int)$compId)]);
    }

    public function holidayGet() {
        if (!$this->requirePermission('holiday.view')) return;
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->holidayGet($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function holidaySave() {
        if (!$this->requirePermission('holiday.manage')) return;
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->holidaySave($data, (int)$compId, $this->userId()));
    }

    public function holidayDelete() {
        if (!$this->requirePermission('holiday.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->holidayDelete($id, (int)$compId, $this->userId()));
    }

    public function holidayToggleStatus() {
        if (!$this->requirePermission('holiday.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->holidayToggleStatus($id, (int)$compId, $this->userId()));
    }

    /* ==================== SHIFT ASSIGNMENT ==================== */

    public function shiftAssignedEmployees() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $this->json(['status' => true, 'data' => $this->model->shiftAssignedEmployees($id, (int)$compId)]);
    }

    public function shiftAssignEmployees() {
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $id = (int)($data['shift_id'] ?? 0);
        $employeeIds = is_array($data['employee_ids'] ?? null) ? $data['employee_ids'] : [];
        $this->json($this->model->shiftAssignEmployees($id, $employeeIds, (int)$compId, $this->userId()));
    }

    /* ==================== WORK LOCATION ==================== */

    public function workLocationList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->workLocationList((int)$compId)]);
    }

    public function workLocationOptions() {
        $compId = getCompId();
        $search = (string)($_POST['searchTerm'] ?? '');
        $items = $this->model->workLocationOptions((int)$compId, $search);
        $this->json(['status' => true, 'data' => ['items' => $items, 'total_count' => count($items)]]);
    }

    public function workLocationGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->workLocationGet($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function workLocationSave() {
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->workLocationSave($data, (int)$compId, $this->userId()));
    }

    public function workLocationDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->workLocationDelete($id, (int)$compId, $this->userId()));
    }

    public function workLocationToggleStatus() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->workLocationToggleStatus($id, (int)$compId, $this->userId()));
    }

    /* ==================== LEAVE TYPE ==================== */

    public function leaveCategoryOptions() {
        $search = (string)($_POST['searchTerm'] ?? '');
        $items = $this->model->leaveCategoryOptions($search);
        $this->json(['status' => true, 'data' => ['items' => $items, 'total_count' => count($items)]]);
    }

    public function leaveTypeList() {
        if (!$this->requirePermission('leave_type.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->leaveTypeList((int)$compId)]);
    }

    public function leaveTypeGet() {
        if (!$this->requirePermission('leave_type.view')) return;
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->leaveTypeGet($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function leaveTypeSave() {
        if (!$this->requirePermission('leave_type.manage')) return;
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->leaveTypeSave($data, (int)$compId, $this->userId()));
    }

    public function leaveTypeDelete() {
        if (!$this->requirePermission('leave_type.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->leaveTypeDelete($id, (int)$compId, $this->userId()));
    }

    public function leaveTypeToggleStatus() {
        if (!$this->requirePermission('leave_type.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->leaveTypeToggleStatus($id, (int)$compId, $this->userId()));
    }
}
