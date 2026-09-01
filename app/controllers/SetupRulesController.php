<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/SetupRulesModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../models/OtRateSetModel.php';

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

    /* ==================== SCOPE ASSIGN, generic (2026-08-31, explicit request) ====================
     * Shift/Work Location's own equivalent of CompanyProfileController's own structureEmployees*
     * methods -- see SetupRulesModel::SCOPE_ASSIGN_CONFIG's own docblock. Ungated (no
     * requirePermission()) same as every other Shift endpoint on this controller -- Shift/Work
     * Location are explicitly OUT of this app's RBAC scope (see CLAUDE.md's own note: "Scope...
     * Holiday, Leave Type, Approval Workflow เท่านั้น -- ไม่รวม Shift/Work Location"). */
    public function scopeEmployeesInRow() {
        $compId = getCompId();
        $type = (string)($_GET['type'] ?? '');
        $rowId = (int)($_GET['id'] ?? 0);
        $search = trim((string)($_GET['search'] ?? ''));
        $this->json($this->model->scopeEmployeesInRow($type, $rowId, (int)$compId, $search));
    }

    public function scopeEmployeesOutsideRow() {
        $compId = getCompId();
        $type = (string)($_GET['type'] ?? '');
        $rowId = (int)($_GET['id'] ?? 0);
        $search = trim((string)($_GET['search'] ?? ''));
        $this->json($this->model->scopeEmployeesOutsideRow($type, $rowId, (int)$compId, $search));
    }

    public function scopeAssignEmployees() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $type = (string)($data['type'] ?? '');
        $rowId = (int)($data['id'] ?? 0);
        $employeeIds = is_array($data['employee_ids'] ?? null) ? $data['employee_ids'] : [];
        $this->json($this->model->scopeAssignEmployees($type, $rowId, $employeeIds, (int)$compId, $this->userId()));
    }

    public function scopeMoveEmployeesOut() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $type = (string)($data['type'] ?? '');
        $employeeIds = is_array($data['employee_ids'] ?? null) ? $data['employee_ids'] : [];
        $destinationRowId = !empty($data['destination_id']) ? (int)$data['destination_id'] : null;
        $this->json($this->model->scopeMoveEmployeesOut($type, $employeeIds, (int)$compId, $destinationRowId, $this->userId()));
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

    public function leaveTypeApplyDefaults() {
        if (!$this->requirePermission('leave_type.manage')) return;
        $compId = getCompId();
        $this->json($this->model->leaveTypeApplyDefaults((int)$compId, $this->userId()));
    }

    /* ==================== OT RATE SET ====================
     * 2026-08-30: rebuilt around OtRateSetModel (one Set bundles ALL OT types as independently-
     * configurable sub-rows + department/team/position/employee assignment + a mandatory
     * company-wide Default) -- replaces the old flat one-row-per-scope `otRate*` CRUD entirely, see
     * OtRateSetModel's own docblock. Method names kept as `otRate*`/route paths kept as
     * `api/ot-rate.*` for continuity (still "the OT Rate settings page" to the frontend/URLs), but
     * every method now delegates to OtRateSetModel instead of SetupRulesModel.
     */

    public function otScopeOptions() {
        $this->json(['status' => true, 'data' => ['items' => $this->model->otScopeOptions(), 'total_count' => 0]]);
    }

    public function otRateList() {
        $compId = (int)getCompId();
        $this->json(['status' => true, 'data' => (new OtRateSetModel())->list($compId)]);
    }

    public function otRateGet() {
        $compId = (int)getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = (new OtRateSetModel())->get($id, $compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function otRateSave() {
        $compId = (int)getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json((new OtRateSetModel())->save($data, $compId, $this->userId()));
    }

    public function otRateDelete() {
        $compId = (int)getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json((new OtRateSetModel())->delete($id, $compId, $this->userId()));
    }

    public function otRateToggleStatus() {
        $compId = (int)getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json((new OtRateSetModel())->toggleStatus($id, $compId, $this->userId()));
    }

    public function otRateSetDefault() {
        $compId = (int)getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json((new OtRateSetModel())->setDefault($id, $compId, $this->userId()));
    }

    public function otRateAssignableOptions() {
        $compId = (int)getCompId();
        $this->json(['status' => true, 'data' => (new OtRateSetModel())->assignableOptions($compId)]);
    }

    /**
     * select2-remote ajax source for "which OT Rate Set" (Employee Detail's OT Rate Settings card,
     * 2026-08-30: "ถ้าเลือกจาก OT ของระบบ จะมีให้เลือกเพิ่มว่า OT ไหน") -- standard
     * `{status, data:{items:[{id,text_th,text_en}], total_count}}` shape every select2-remote in this
     * app expects (see input.js's own initSelect2()). Only ACTIVE Sets are offered -- an employee
     * should never be able to explicitly pick a Set that's currently deactivated.
     */
    public function otRateSetOptions() {
        $compId = (int)getCompId();
        $search = trim((string)($_POST['searchTerm'] ?? ''));
        $sets = array_values(array_filter((new OtRateSetModel())->list($compId), fn($s) => $s['status'] === 'active'));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $sets = array_values(array_filter($sets, fn($s) => strpos(mb_strtolower($s['name_th']), $needle) !== false || strpos(mb_strtolower($s['name_en']), $needle) !== false));
        }
        $items = array_map(fn($s) => ['id' => $s['id'], 'text_th' => $s['name_th'], 'text_en' => $s['name_en']], $sets);
        $this->json(['status' => true, 'data' => ['items' => $items, 'total_count' => count($items)]]);
    }

    public function otRatePreview() {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $sampleBaseSalary = isset($data['sample_base_salary']) && is_numeric($data['sample_base_salary']) ? (float)$data['sample_base_salary'] : 30000.0;
        $sampleHours = isset($data['sample_hours']) && is_numeric($data['sample_hours']) ? (float)$data['sample_hours'] : 2.0;
        $this->json((new OtRateSetModel())->previewCalculation($data, $sampleBaseSalary, $sampleHours));
    }
}
