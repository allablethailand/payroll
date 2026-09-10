<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollConfigurationModel.php';
require_once __DIR__ . '/../models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../models/PayrollCycleModel.php';
require_once __DIR__ . '/../models/AttendanceDeductionRuleModel.php';
require_once __DIR__ . '/../models/PayrollPolicyModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
class PayrollConfigurationController extends Controller {
    private $model;
    private $pedTypeModel;
    private $cycleModel;
    private AttendanceDeductionRuleModel $attendanceDeductionRuleModel;
    private PayrollPolicyModel $policyModel;
    private PermissionModel $permissionModel;
    public function __construct(){
        $this->model = new PayrollConfigurationModel();
        $this->pedTypeModel = new PayrollEarningDeductionTypeModel();
        $this->cycleModel = new PayrollCycleModel();
        $this->attendanceDeductionRuleModel = new AttendanceDeductionRuleModel();
        $this->policyModel = new PayrollPolicyModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** @return array{0:?string,1:?string} [ip_address, user_agent] -- same capture pattern ManualEntryController::requestFingerprint() already established, for AuditLogModel::record(). */
    private function requestFingerprint(): array {
        return [
            (string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null,
        ];
    }

    /** Gates the actual CRUD; dropdown-option lookups (*Options methods) stay ungated -- they're consumed by other forms and carry no PII/financial figures. */
    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    public function index() {
        $this->view('setup/payroll-configuration');
    }

    public function pedSourceEventOptions() {
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $itemType = (string)($_POST['type'] ?? '');
        $data = $this->pedTypeModel->sourceEventOptions($itemType, $search, $page, $limit);
        $this->json(['status' => true, 'data' => $data]);
    }

    public function bankFileFormatOptions() {
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $this->json(['status' => true, 'data' => $this->cycleModel->bankFileFormatOptions($search, $page, $limit)]);
    }

    /** 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย". */
    public function bankAccountOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $this->json(['status' => true, 'data' => $this->cycleModel->bankAccountOptions((int)$compId, $search, $page, $limit)]);
    }

    /** 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) -- feeds BOTH
     *  the cycle form's own "default payment method" picker AND the Employee page's Employment-tab
     *  payment method picker (a single shared endpoint, master_payment_methods is global/company-
     *  agnostic data, no need for a per-controller duplicate). */
    public function paymentMethodOptions() {
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $excludeCode = isset($_POST['exclude_code']) ? (string)$_POST['exclude_code'] : null;
        // 2026-09-10, Batch 3B item 2: only the payroll cycle form's own picker sends this (see
        // that field's own data-include-auto="1" in modals.php) -- never the employee's own
        // payment_method_id/mixed-line pickers, which share this same endpoint unchanged.
        $includeAuto = !empty($_POST['include_auto']);
        $model = new EmployeePaymentMethodModel();
        $this->json(['status' => true, 'data' => $model->methodOptions($search, $page, $limit, $excludeCode, $includeAuto)]);
    }

    public function cycleOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $this->json(['status' => true, 'data' => $this->cycleModel->options((int)$compId, $search, $page, $limit)]);
    }

    /** Not gated behind payroll_configuration.manage -- used by the Payroll Run create form (any
     * user who can create a run, not just those managing cycle setup), same exposure level as
     * cycleOptions() above which feeds the same form's cycle dropdown. */
    public function cycleSuggestPeriod() {
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->cycleModel->suggestNextPeriod($id, (int)$compId));
    }

    public function cycleList() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->cycleModel->list((int)$compId)]);
    }

    public function cycleGet() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->cycleModel->get($id, (int)$compId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function cycleSave() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // PayrollCycleModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'payroll_configuration.edit' : 'payroll_configuration.add')) return;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->cycleModel->save((int)$compId, $data, $userId, $ip, $ua);
        $this->json($result);
    }

    /** 2026-09-02, multi-bank-account payroll -- the cycle edit form's new multi-account picker
     *  reads the current set via cycleGet() (which now joins bank_accounts via getBankAccounts()),
     *  this endpoint is the SAVE side. */
    public function cycleSaveBankAccounts() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $cycleId = (is_array($data) && isset($data['cycle_id'])) ? (int)$data['cycle_id'] : 0;
        $rows = (is_array($data) && isset($data['accounts']) && is_array($data['accounts'])) ? $data['accounts'] : [];
        if ($cycleId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing cycle_id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->cycleModel->saveBankAccounts($cycleId, (int)$compId, $rows, $userId);
        $this->json($result);
    }

    public function cycleDelete() {
        if (!$this->requirePermission('payroll_configuration.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->cycleModel->delete((int)$compId, $id, $userId, $ip, $ua);
        $this->json($result);
    }

    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch, same shape as
    // CompanyProfileController's 6 structure-entity toggle-status dispatchers.
    public function cycleToggleStatus() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        // The shared frontend switch (app.js's renderStatusToggleHtml()/status-toggle-switch
        // handler) posts a raw JSON body, not form-urlencoded -- $_POST is never populated for that,
        // same pattern cycleDelete() above already uses.
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->cycleModel->toggleStatus((int)$compId, $id, $userId, $ip, $ua);
        $this->json($result);
    }

    public function pedTypeList() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $itemType = (string)($_POST['item_type'] ?? '');
        if (!in_array($itemType, ['earning', 'deduction'], true)) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $search = (string)($_POST['search']['value'] ?? '');
        $colIndex = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : 0;
        $orderDir = isset($_POST['order'][0]['dir']) && $_POST['order'][0]['dir'] === 'desc' ? 'desc' : 'asc';
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $res = $this->pedTypeModel->list((int)$compId, $start, $length, $itemType, $search, $colIndex, $orderDir, (string)$lang, $columnFilters);
        $this->json([
            'draw' => intval($_POST['draw'] ?? 1),
            'recordsTotal' => $res['recordsTotal'],
            'recordsFiltered' => $res['recordsFiltered'],
            'data' => $res['data'],
        ]);
    }
    /** 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout,
     *  shared by both the Earning and Deduction tabs (see PayrollEarningDeductionTypeModel::
     *  columnDistinctValues()'s own docblock on why the result is scoped to the requesting tab's
     *  own item_type). */
    public function pedTypeColumnValues() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'values' => []]);
            return;
        }
        $itemType = (string)($_POST['item_type'] ?? '');
        if (!in_array($itemType, ['earning', 'deduction'], true)) {
            $this->json(['status' => true, 'values' => []]);
            return;
        }
        $column = (string)($_POST['column'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $values = $this->pedTypeModel->columnDistinctValues((int)$compId, $itemType, $column, (string)$lang, $columnFilters, $column);
        $this->json(['status' => true, 'values' => $values]);
    }

    public function pedTypeGet() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->pedTypeModel->get((int)$compId, $id);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function pedTypeSave() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // PayrollEarningDeductionTypeModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'payroll_configuration.edit' : 'payroll_configuration.add')) return;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->pedTypeModel->save((int)$compId, $data, $userId, $ip, $ua);
        $this->json($result);
    }

    /** "Load Default Items" -- inserts the system's starter set of earning/deduction types for this company, skipping any item_code already present (active or soft-deleted). Idempotent, safe to click more than once. */
    public function pedTypeSeedDefaults() {
        if (!$this->requirePermission('payroll_configuration.add')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $res = $this->pedTypeModel->seedDefaults((int)$compId, $userId);
        $this->json(['status' => true, 'message' => 'Loaded default items.', 'inserted' => $res['inserted'], 'skipped' => $res['skipped']]);
    }

    /* ==================== ATTENDANCE DEDUCTION RULES (Late / Absent / Unpaid Leave) ==================== */

    public function attendanceDeductionMethodOptions() {
        $this->json(['status' => true, 'data' => ['items' => $this->attendanceDeductionRuleModel->methodOptions(), 'total_count' => 0]]);
    }

    public function attendanceDeductionRuleGetAll() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->attendanceDeductionRuleModel->ruleGetAll((int)$compId)]);
    }

    public function attendanceDeductionRuleSave() {
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // AttendanceDeductionRuleModel::ruleSave() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'payroll_configuration.edit' : 'payroll_configuration.add')) return;
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->attendanceDeductionRuleModel->ruleSave($data, (int)$compId, $this->userId(), $ip, $ua));
    }

    public function attendanceDeductionRuleAssignableOptions() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->attendanceDeductionRuleModel->assignableOptions((int)$compId)]);
    }

    /** 2026-08-30, multi-scope rollout -- deletes a team/department-scoped rule variant (the
     *  company-wide default is never deletable, see AttendanceDeductionRuleModel::ruleDelete()). */
    public function attendanceDeductionRuleDelete() {
        if (!$this->requirePermission('payroll_configuration.delete')) return;
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->attendanceDeductionRuleModel->ruleDelete($id, (int)$compId, $this->userId(), $ip, $ua));
    }

    public function attendanceDeductionRulePreview() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $sampleBaseSalary = isset($data['sample_base_salary']) && is_numeric($data['sample_base_salary']) ? (float)$data['sample_base_salary'] : 30000.0;
        $sampleMinutes = isset($data['sample_minutes']) && is_numeric($data['sample_minutes']) ? (float)$data['sample_minutes'] : 30.0;
        $this->json($this->attendanceDeductionRuleModel->previewCalculation($data, $sampleBaseSalary, $sampleMinutes));
    }

    public function pedTypeDelete() {
        if (!$this->requirePermission('payroll_configuration.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->pedTypeModel->delete((int)$compId, $id, $userId, $ip, $ua);
        $this->json($result);
    }

    /**
     * 2026-08-30 (Phase 2, T014, explicit request: "ย้าย 'สถานะ' ออกจาก modal ไปไว้ที่แถวในตาราง") --
     * a lightweight row-level active/inactive flip, same shape as SetupRulesController's own
     * holidayToggleStatus()/leaveTypeToggleStatus()/etc -- the Add/Edit modal no longer has a Status
     * field at all (see the view/JS changes this same round), so this is now the ONLY way to change
     * an item's status.
     */
    public function pedTypeToggleStatus() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->pedTypeModel->toggleStatus((int)$compId, $id, $this->userId(), $ip, $ua);
        $this->json($result);
    }

    /* ==================== PAYROLL POLICIES (2026-08-30, new tab) ==================== */

    public function policyGet() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->policyModel->get((int)$compId)]);
    }

    public function policySave() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->policyModel->save((int)$compId, $data, $this->userId(), $ip, $ua));
    }

    /* ==================== PROBATION SETS (2026-09-04, Backlog Phase 10, T056) ====================
     * Same permission pair as the rest of Payroll Configuration -- no new permission key, this is
     * still "Payroll Policies" tab territory, just Set-shaped now instead of a single form. Assign
     * uses T055's own EntityAssignmentModel directly (this controller's own request/transaction,
     * same "no shared generic public endpoint" scope boundary that model's docblock documents). */

    public function probationSetList() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->policyModel->probationSetList((int)$compId)]);
    }

    public function probationSetGet() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $set = $this->policyModel->probationSetGet((int)$compId, $id);
        if ($set === null) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $set]);
    }

    public function probationSetSave() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->policyModel->probationSetSave((int)$compId, $data, $this->userId()));
    }

    public function probationSetDelete() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->policyModel->probationSetDelete((int)$compId, $id, $this->userId()));
    }

    public function probationSetToggleStatus() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->policyModel->probationSetToggleStatus((int)$compId, $id, $this->userId()));
    }

    public function probationSetSetDefault() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->policyModel->probationSetSetDefault((int)$compId, $id, $this->userId()));
    }

    public function probationSetDuplicate() {
        if (!$this->requirePermission('payroll_configuration.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->policyModel->probationSetDuplicate((int)$compId, $id, $this->userId()));
    }

    public function probationSetAssignableOptions() {
        if (!$this->requirePermission('payroll_configuration.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => (new EntityAssignmentModel())->assignableOptions((int)$compId)]);
    }
}
