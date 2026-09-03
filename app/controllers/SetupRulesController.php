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

    /** 2026-09-03, Platform Hardening Phase 3 Stage 3: extended from Holiday/Leave Type/Approval
     *  Workflow (the original RBAC rollout's agreed scope) to also cover Shift/Work Location/
     *  OT Rate -- a "natural small extension, sits right next to Holiday/Leave Type which are
     *  already being expanded" (see project_platform_hardening_phase3_2026_09_03 memory's own
     *  scope-boundary section). New `shift.*`/`work_location.*`/`ot_rate.*` permission keys, no
     *  role currently holds a non-admin grant for any of them in the real dev DB (checked before
     *  this rollout -- same as holiday/leave_type had zero real grants either), so this is not a
     *  behavior change for any configured role today, only for a future one an admin sets up. */
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
        if (!$this->requirePermission('shift.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->shiftList((int)$compId)]);
    }

    public function shiftGet() {
        if (!$this->requirePermission('shift.view')) return;
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
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // SetupRulesModel::shiftSave() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'shift.edit' : 'shift.add')) return;
        $this->json($this->model->shiftSave($data, (int)$compId, $this->userId()));
    }

    // 2026-09-02, real bug found and fixed: this read $_POST['id'], but the shared frontend switch
    // (app.js's renderStatusToggleHtml()/status-toggle-switch handler) posts a raw JSON body, not
    // form-urlencoded -- $_POST is never populated for a JSON body, so $id always fell through to 0
    // and every toggle click on this table's status switch silently failed with "Record not found"
    // (or whatever the model's own 0-id branch returns). Same fix as
    // PayrollConfigurationController::pedTypeToggleStatus()/cycleToggleStatus() already use.
    public function shiftToggleStatus() {
        if (!$this->requirePermission('shift.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $this->json($this->model->shiftToggleStatus($id, (int)$compId, $this->userId()));
    }

    public function shiftDelete() {
        if (!$this->requirePermission('shift.delete')) return;
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
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // SetupRulesModel::holidaySave() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'holiday.edit' : 'holiday.add')) return;
        $this->json($this->model->holidaySave($data, (int)$compId, $this->userId()));
    }

    public function holidayDelete() {
        if (!$this->requirePermission('holiday.delete')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->holidayDelete($id, (int)$compId, $this->userId()));
    }

    // 2026-09-02, real bug found and fixed -- same "$_POST['id'] never populates for the shared
    // switch's raw JSON body" bug as shiftToggleStatus() above.
    public function holidayToggleStatus() {
        if (!$this->requirePermission('holiday.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $this->json($this->model->holidayToggleStatus($id, (int)$compId, $this->userId()));
    }

    /* ==================== SHIFT ASSIGNMENT ==================== */

    public function shiftAssignedEmployees() {
        if (!$this->requirePermission('shift.view')) return;
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $this->json(['status' => true, 'data' => $this->model->shiftAssignedEmployees($id, (int)$compId)]);
    }

    public function shiftAssignEmployees() {
        if (!$this->requirePermission('shift.edit')) return;
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
     * methods -- see SetupRulesModel::SCOPE_ASSIGN_CONFIG's own docblock. 2026-09-03, Platform
     * Hardening Phase 3 Stage 3: gated -- `type` is always exactly 'shift' or 'work_location'
     * (SCOPE_ASSIGN_CONFIG's own key set), which happens to match this Stage's new permission
     * module_codes 1:1, so scopePermissionModule() just returns `type` itself, defensively refusing
     * anything else. */
    private function scopePermissionModule(string $type): ?string {
        return in_array($type, ['shift', 'work_location'], true) ? $type : null;
    }

    public function scopeEmployeesInRow() {
        $compId = getCompId();
        $type = (string)($_GET['type'] ?? '');
        $module = $this->scopePermissionModule($type);
        if ($module === null) {
            $this->json(['status' => false, 'message' => 'Invalid entity type.']);
            return;
        }
        if (!$this->requirePermission($module . '.view')) return;
        $rowId = (int)($_GET['id'] ?? 0);
        $search = trim((string)($_GET['search'] ?? ''));
        $this->json($this->model->scopeEmployeesInRow($type, $rowId, (int)$compId, $search));
    }

    public function scopeEmployeesOutsideRow() {
        $compId = getCompId();
        $type = (string)($_GET['type'] ?? '');
        $module = $this->scopePermissionModule($type);
        if ($module === null) {
            $this->json(['status' => false, 'message' => 'Invalid entity type.']);
            return;
        }
        if (!$this->requirePermission($module . '.view')) return;
        $rowId = (int)($_GET['id'] ?? 0);
        $search = trim((string)($_GET['search'] ?? ''));
        $this->json($this->model->scopeEmployeesOutsideRow($type, $rowId, (int)$compId, $search));
    }

    public function scopeAssignEmployees() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $type = (string)($data['type'] ?? '');
        $module = $this->scopePermissionModule($type);
        if ($module === null) {
            $this->json(['status' => false, 'message' => 'Invalid entity type.']);
            return;
        }
        if (!$this->requirePermission($module . '.edit')) return;
        $rowId = (int)($data['id'] ?? 0);
        $employeeIds = is_array($data['employee_ids'] ?? null) ? $data['employee_ids'] : [];
        $this->json($this->model->scopeAssignEmployees($type, $rowId, $employeeIds, (int)$compId, $this->userId()));
    }

    public function scopeMoveEmployeesOut() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $type = (string)($data['type'] ?? '');
        $module = $this->scopePermissionModule($type);
        if ($module === null) {
            $this->json(['status' => false, 'message' => 'Invalid entity type.']);
            return;
        }
        if (!$this->requirePermission($module . '.edit')) return;
        $employeeIds = is_array($data['employee_ids'] ?? null) ? $data['employee_ids'] : [];
        $destinationRowId = !empty($data['destination_id']) ? (int)$data['destination_id'] : null;
        $this->json($this->model->scopeMoveEmployeesOut($type, $employeeIds, (int)$compId, $destinationRowId, $this->userId()));
    }

    /* ==================== WORK LOCATION ==================== */

    public function workLocationList() {
        if (!$this->requirePermission('work_location.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->workLocationList((int)$compId)]);
    }

    // 2026-09-03, Platform Hardening Phase 3 Stage 3: deliberately left UNGATED -- `api/work-
    // location.options` is registered TWICE in index.php (this method, and MasterController::
    // getMaster() further down as a generic picker also consumed by structure-assign.js's shared
    // "Assign Employees" modal for other entity types) -- whichever route wins the match, gating
    // this one specific method risks nothing (if shadowed, it's unreachable dead code) but could
    // wrongly lock a generic dropdown picker behind Setup Rules' own admin permission if it turns
    // out to be the one actually reached. Left as-is per this Stage's own "don't newly-gate an
    // ambiguous shared endpoint" caution.
    public function workLocationOptions() {
        $compId = getCompId();
        $search = (string)($_POST['searchTerm'] ?? '');
        $items = $this->model->workLocationOptions((int)$compId, $search);
        $this->json(['status' => true, 'data' => ['items' => $items, 'total_count' => count($items)]]);
    }

    public function workLocationGet() {
        if (!$this->requirePermission('work_location.view')) return;
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
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // SetupRulesModel::workLocationSave() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'work_location.edit' : 'work_location.add')) return;
        $this->json($this->model->workLocationSave($data, (int)$compId, $this->userId()));
    }

    public function workLocationDelete() {
        if (!$this->requirePermission('work_location.delete')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->workLocationDelete($id, (int)$compId, $this->userId()));
    }

    // 2026-09-02, real bug found and fixed -- same "$_POST['id'] never populates for the shared
    // switch's raw JSON body" bug as shiftToggleStatus() above.
    public function workLocationToggleStatus() {
        if (!$this->requirePermission('work_location.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
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
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // SetupRulesModel::leaveTypeSave() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'leave_type.edit' : 'leave_type.add')) return;
        $this->json($this->model->leaveTypeSave($data, (int)$compId, $this->userId()));
    }

    public function leaveTypeDelete() {
        if (!$this->requirePermission('leave_type.delete')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->leaveTypeDelete($id, (int)$compId, $this->userId()));
    }

    // 2026-09-02, real bug found and fixed -- same "$_POST['id'] never populates for the shared
    // switch's raw JSON body" bug as shiftToggleStatus() above.
    public function leaveTypeToggleStatus() {
        if (!$this->requirePermission('leave_type.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $this->json($this->model->leaveTypeToggleStatus($id, (int)$compId, $this->userId()));
    }

    public function leaveTypeApplyDefaults() {
        if (!$this->requirePermission('leave_type.edit')) return;
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
        if (!$this->requirePermission('ot_rate.view')) return;
        $this->json(['status' => true, 'data' => ['items' => $this->model->otScopeOptions(), 'total_count' => 0]]);
    }

    public function otRateList() {
        if (!$this->requirePermission('ot_rate.view')) return;
        $compId = (int)getCompId();
        $this->json(['status' => true, 'data' => (new OtRateSetModel())->list($compId)]);
    }

    public function otRateGet() {
        if (!$this->requirePermission('ot_rate.view')) return;
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
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // OtRateSetModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'ot_rate.edit' : 'ot_rate.add')) return;
        $this->json((new OtRateSetModel())->save($data, $compId, $this->userId()));
    }

    public function otRateDelete() {
        if (!$this->requirePermission('ot_rate.delete')) return;
        $compId = (int)getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json((new OtRateSetModel())->delete($id, $compId, $this->userId()));
    }

    // 2026-09-02, real bug found and fixed -- same "$_POST['id'] never populates for the shared
    // switch's raw JSON body" bug as shiftToggleStatus() above.
    public function otRateToggleStatus() {
        if (!$this->requirePermission('ot_rate.edit')) return;
        $compId = (int)getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $this->json((new OtRateSetModel())->toggleStatus($id, $compId, $this->userId()));
    }

    public function otRateSetDefault() {
        if (!$this->requirePermission('ot_rate.edit')) return;
        $compId = (int)getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json((new OtRateSetModel())->setDefault($id, $compId, $this->userId()));
    }

    // 2026-09-03, Platform Hardening Phase 3 Stage 3: feeds the OT Rate Set editor's OWN department/
    // team/position/employee assignment checkbox list (Setup Rules page itself, unlike
    // otRateSetOptions() below which is a DIFFERENT page's picker) -- safe to gate.
    public function otRateAssignableOptions() {
        if (!$this->requirePermission('ot_rate.view')) return;
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
    // 2026-09-03, Platform Hardening Phase 3 Stage 3: deliberately left UNGATED -- consumed by
    // Employee Detail's own OT Rate Settings card (a different page/permission domain entirely,
    // see this method's own docblock above), not Setup Rules' own admin UI. Gating this behind
    // ot_rate.view would wrongly block any employee-editing user who lacks OT Rate Set management
    // permission from simply picking an existing (already-configured) Set for one employee.
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
        if (!$this->requirePermission('ot_rate.view')) return;
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
