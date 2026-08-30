<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../services/IdCodec.php';
class PayrollController extends Controller {
    private $model;
    public function __construct(){ $this->model = new PayrollRunModel(); }

    public function index() {
        $this->view('payroll/index');
    }

    public function approvalQueue() {
        $this->view('payroll/approval');
    }

    public function detail($id = null) {
        // {id} in the route is an IdCodec-encoded token (e.g. /payroll-process/AbC12-xY==), not
        // the raw payroll_runs.id -- see IdCodec's own docblock for why. A stale/forged/garbage
        // token just fails to decode and 404s, same as an out-of-range numeric id used to.
        $runId = $id ? IdCodec::decode((string)$id) : null;
        if ($runId === null) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $this->view('payroll/detail', ['runId' => $runId]);
    }

    public function options() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $statesParam = (string)($_POST['states'] ?? '');
        $allowedStates = $statesParam !== '' ? explode(',', $statesParam) : null;
        $data = $this->model->options((int)$compId, $search, $page, $limit, $allowedStates);
        $this->json(['status' => true, 'data' => $data]);
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** Any of the 3 payroll role-flags grants read access -- mutating actions already check the SPECIFIC flag they need inside PayrollRunModel. */
    private function requireViewAccess(): bool {
        if (!$this->model->canView($this->userId(), $this->isAdmin())) {
            $this->json(['status' => false, 'message' => 'You do not have permission to view payroll data.']);
            return false;
        }
        return true;
    }

    public function list() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $filters = [
            'state' => (string)($_GET['state'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
        ];
        // approval_queue=1 (2026-08-24): sent only by the Approval Queue page (approval.js) -- the
        // Process List page (index.js) hits this same endpoint without it and must keep seeing
        // every run regardless of who can approve it. See PayrollRunModel::list()'s own docblock.
        $approvalQueueOnly = (string)($_GET['approval_queue'] ?? '') === '1';
        $rows = $this->model->list((int)$compId, $filters, $this->userId(), $this->isAdmin(), $approvalQueueOnly);
        // public_id is the IdCodec-encoded token used for the /payroll-process/{id} browser URL
        // (row-click navigation, the View action button) -- 'id' itself stays the raw numeric PK,
        // still used as-is for every internal AJAX call (api/payroll-run.get?id=, save/submit/
        // approve/etc. payloads), same as every other list/detail endpoint in this app. Only the
        // URL a user can see/bookmark/share gets obfuscated.
        // can_finalize_payroll (2026-08-27, gates the mini-timeline's own Mark as Paid/Lock quick
        // actions, see index.js's miniTimelineQuickActionHtml()) -- unlike can_approve_payroll,
        // this isn't department-scoped per row, so it's the same value for every row in the
        // response; computed once outside the loop rather than once per row.
        $canFinalize = $this->model->canFinalizePayroll($this->userId(), $this->isAdmin());
        foreach ($rows as &$row) {
            $row['public_id'] = IdCodec::encode((int)$row['id']);
            $row['can_finalize_payroll'] = $canFinalize;
        }
        unset($row);
        $this->json(['status' => true, 'data' => $rows]);
    }

    public function get() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $row['details'] = $this->model->getDetails($id, (int)$compId);
        $row['audit_log'] = $this->model->getAuditLog($id, (int)$compId);
        $row['approval_flow'] = $this->model->approvalFlow($id, (int)$compId);
        $row['can_approve_payroll'] = $this->model->canApprovePayroll($this->userId(), $this->isAdmin(), $row);
        $row['can_process_payroll'] = $this->model->canProcessPayroll($this->userId(), $this->isAdmin());
        $row['can_finalize_payroll'] = $this->model->canFinalizePayroll($this->userId(), $this->isAdmin());
        // 2026-08-29: backs the Print Reports dropdown's own tax/SSO-report hiding -- see
        // PayrollRunModel::calcApplicabilitySummary()'s own docblock.
        $calcApplicability = $this->model->calcApplicabilitySummary($id, (int)$compId);
        $row['any_tax_applicable'] = $calcApplicability['any_tax'];
        $row['any_sso_applicable'] = $calcApplicability['any_sso'];
        $this->json(['status' => true, 'data' => $row]);
    }

    /** Feeds the Approval Timeline modal on the Approval Queue page -- that page's own list
     *  endpoint stays lean (one row per run, no audit log/approver breakdown) since most rows'
     *  timeline never gets opened; this is fetched on demand only when the modal opens. */
    public function approvalTimeline() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $run = $this->model->get($id, (int)$compId);
        if (!$run) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        // Full run row (not just id/run_name/state) -- the Approval Flow modal's Created/Paid
        // stages need created_at/created_by_name_*/paid_at/approved_at/etc. too.
        $run['audit_log'] = $this->model->getAuditLog($id, (int)$compId);
        $run['approval_flow'] = $this->model->approvalFlow($id, (int)$compId);
        $run['can_approve_payroll'] = $this->model->canApprovePayroll($this->userId(), $this->isAdmin(), $run);
        $this->json(['status' => true, 'data' => $run]);
    }

    public function save() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $result = $id
            ? $this->model->update($id, (int)$compId, $data, $this->userId(), $this->isAdmin())
            : $this->model->create((int)$compId, $data, $this->userId(), $this->isAdmin());
        $this->json($result);
    }

    public function delete() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->delete($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function recalculate() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->recalculate($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    /** Server-side DataTable source for the "Join Employees" picker modal on a genuine off-cycle run. */
    public function manualEmployeeOptions() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_POST['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'position_id' => $_POST['position_id'] ?? '',
            'emp_cycle_id' => $_POST['emp_cycle_id'] ?? '',
        ];
        $search = (string)($_POST['search']['value'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $res = $this->model->manualEmployeeOptions((int)$compId, $runId, $start, $length, $filters, $search, (string)$lang, $columnFilters);
        $this->json([
            'draw' => intval($_POST['draw'] ?? 1),
            'recordsTotal' => $res['total'],
            'recordsFiltered' => $res['filtered'],
            'data' => $res['data'],
        ]);
    }
    /** 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout. */
    public function manualEmployeeColumnValues() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_POST['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'values' => []]);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'position_id' => $_POST['position_id'] ?? '',
            'emp_cycle_id' => $_POST['emp_cycle_id'] ?? '',
        ];
        $column = (string)($_POST['column'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $values = $this->model->manualEmployeeColumnValues((int)$compId, $runId, $filters, $column, (string)$lang, $columnFilters);
        $this->json(['status' => true, 'values' => $values]);
    }

    /** 2026-08-24, explicit request ("จัดรูปแบบให้การดึงพนักงานเข้ามาในการคำนวณดำเนินการได้ง่ายที่สุด") --
     *  "Select all N matching" for the Join Employees picker: every id matching the current filter/
     *  search, not just the current DataTable page. Separate endpoint from manualEmployeeOptions()
     *  (that one stays paginated for the table itself) so the picker's live DataTable ajax traffic
     *  is untouched -- this is only called once, when the user clicks "Select All". */
    public function manualEmployeeAllIds() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_POST['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid run.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'position_id' => $_POST['position_id'] ?? '',
            'emp_cycle_id' => $_POST['emp_cycle_id'] ?? '',
        ];
        $search = (string)($_POST['search'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $ids = $this->model->manualEmployeeAllIds((int)$compId, $runId, $filters, $search, (string)$lang, $columnFilters);
        $this->json(['status' => true, 'employee_ids' => $ids]);
    }

    public function joinEmployees() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeIds = (is_array($data) && isset($data['employee_ids']) && is_array($data['employee_ids'])) ? $data['employee_ids'] : [];
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->joinEmployees($id, (int)$compId, $employeeIds, $this->userId(), $this->isAdmin()));
    }

    public function removeEmployee() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->removeManualEmployee($id, (int)$compId, $employeeId, $this->userId(), $this->isAdmin()));
    }

    public function manualLinesForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->manualLinesForEmployee((int)$compId, $runId, $employeeId)]);
    }

    public function addManualLine() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        // ped_type_id is optional now -- omitted (or 0) means a custom, not-in-the-catalog item
        // instead (custom_item_name + custom_item_type), see PayrollRunModel::addManualLine()'s
        // docblock. At least one of the two forms must be present, checked below.
        $pedTypeIdRaw = (is_array($data) && isset($data['ped_type_id'])) ? (int)$data['ped_type_id'] : 0;
        $pedTypeId = $pedTypeIdRaw > 0 ? $pedTypeIdRaw : null;
        $amount = (is_array($data) && isset($data['amount']) && is_numeric($data['amount'])) ? (float)$data['amount'] : 0.0;
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        $customItemName = (is_array($data) && isset($data['custom_item_name'])) ? (string)$data['custom_item_name'] : null;
        $customItemType = (is_array($data) && isset($data['custom_item_type'])) ? (string)$data['custom_item_type'] : null;
        $payeeEmployeeIdRaw = (is_array($data) && isset($data['payee_employee_id'])) ? (int)$data['payee_employee_id'] : 0;
        $payeeEmployeeId = $payeeEmployeeIdRaw > 0 ? $payeeEmployeeIdRaw : null;
        if (!$compId || $id <= 0 || $employeeId <= 0 || ($pedTypeId === null && ($customItemName === null || trim($customItemName) === ''))) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->addManualLine($id, (int)$compId, $employeeId, $pedTypeId, $amount, $this->userId(), $this->isAdmin(), $note, $customItemName, $customItemType, $payeeEmployeeId));
    }

    public function removeManualLine() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $lineId = (is_array($data) && isset($data['line_id'])) ? (int)$data['line_id'] : 0;
        if (!$compId || $id <= 0 || $lineId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->removeManualLine($id, (int)$compId, $lineId, $this->userId(), $this->isAdmin()));
    }

    /* ==================== SYNC DEDUCTION LINE OVERRIDES (2026-08-21) ==================== */

    public function syncLinesForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        // 2026-08-29: bundles this employee's per-run tax/SSO calculation override alongside the
        // line-override list (same "always pull fresh, one fetch per modal open" convention as the
        // Raw Sync Data modal used to have for this same data) -- the "Tax & SSO" tab of the Manage
        // Items modal (universal, not sync-only, per explicit request) reads this `exemption` key.
        // `run_settings` (item_options + excluded_item_codes, from the SAME source Run Settings'
        // own panel uses) additionally backs this tab's own item-exclusion checklist (2026-08-29
        // follow-up: "อยากให้มี List รายการและติ๊กเข้าออกได้เหมือนตอนที่ Set ทั้ง Template").
        $this->json([
            'status' => true,
            'data' => $this->model->syncDeductionLinesForEmployee((int)$compId, $runId, $employeeId),
            'exemption' => $this->model->getEmployeeExemption($runId, (int)$compId, $employeeId),
            'run_settings' => $this->model->runSettingsGet($runId, (int)$compId)['data'] ?? null,
        ]);
    }

    public function lineOverrideSave() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $itemCode = (is_array($data) && isset($data['item_code'])) ? (string)$data['item_code'] : '';
        $action = (is_array($data) && isset($data['action'])) ? (string)$data['action'] : '';
        $overrideAmount = (is_array($data) && isset($data['override_amount']) && is_numeric($data['override_amount'])) ? (float)$data['override_amount'] : null;
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0 || $employeeId <= 0 || $itemCode === '') {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->lineOverrideSave($id, (int)$compId, $employeeId, $itemCode, $action, $overrideAmount, $note, $this->userId(), $this->isAdmin()));
    }

    public function lineOverrideRemove() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $itemCode = (is_array($data) && isset($data['item_code'])) ? (string)$data['item_code'] : '';
        if (!$compId || $id <= 0 || $employeeId <= 0 || $itemCode === '') {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->lineOverrideRemove($id, (int)$compId, $employeeId, $itemCode, $this->userId(), $this->isAdmin()));
    }

    /* ==================== RAW ATTENDANCE DATA OVERRIDES (2026-08-21) ==================== */

    public function attendanceDataForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->attendanceDataForEmployee((int)$compId, $runId, $employeeId)]);
    }

    public function attendanceOverrideSave() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $fields = (is_array($data) && isset($data['fields']) && is_array($data['fields'])) ? $data['fields'] : [];
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->attendanceOverrideSave($id, (int)$compId, $employeeId, $fields, $note, $this->userId(), $this->isAdmin()));
    }

    public function attendanceOverrideRemove() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->attendanceOverrideRemove($id, (int)$compId, $employeeId, $this->userId(), $this->isAdmin()));
    }

    /* ==================== RAW SYNC DATA VIEWER (2026-08-21) ==================== */

    public function rawSyncDataForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $data = $this->model->rawSyncDataForEmployee((int)$compId, $runId, $employeeId);
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'No raw sync data found for this employee on this run.']);
            return;
        }
        $this->json(['status' => true, 'data' => $data]);
    }

    /** 2026-08-29: `tax_calculate_override`/`sso_calculate_override` are tri-state strings
     *  ('inherit'/'yes'/'no') now, widened from the original force-off-only exempt_tax/exempt_sso
     *  booleans -- see PayrollRunModel::saveEmployeeExemption()'s own docblock. */
    public function saveEmployeeExemption() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $taxCalculateOverride = (is_array($data) && isset($data['tax_calculate_override'])) ? (string)$data['tax_calculate_override'] : 'inherit';
        $ssoCalculateOverride = (is_array($data) && isset($data['sso_calculate_override'])) ? (string)$data['sso_calculate_override'] : 'inherit';
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->saveEmployeeExemption($id, (int)$compId, $employeeId, $taxCalculateOverride, $ssoCalculateOverride, $note, $this->userId(), $this->isAdmin()));
    }

    /* ==================== Run Settings panel (2026-08-29) ==================== */

    public function runSettingsGet() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->runSettingsGet($runId, (int)$compId));
    }

    public function runSettingsSave() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $taxCalculateDefault = (is_array($data) && isset($data['tax_calculate_default'])) ? (string)$data['tax_calculate_default'] : 'use_employee_setting';
        $ssoCalculateDefault = (is_array($data) && isset($data['sso_calculate_default'])) ? (string)$data['sso_calculate_default'] : 'use_employee_setting';
        $excludedItemCodes = (is_array($data) && isset($data['excluded_item_codes']) && is_array($data['excluded_item_codes'])) ? $data['excluded_item_codes'] : [];
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->runSettingsSave($id, (int)$compId, $taxCalculateDefault, $ssoCalculateDefault, $excludedItemCodes, $this->userId(), $this->isAdmin()));
    }

    /* ==================== Employee Verify / Lock / Comments (2026-08-29) ==================== */

    public function employeeVerifySave() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $verified = is_array($data) && !empty($data['verified']);
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->setEmployeeVerified($id, (int)$compId, $employeeId, $verified, $this->userId(), $this->isAdmin()));
    }

    public function employeeLockSave() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $locked = is_array($data) && !empty($data['locked']);
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->setEmployeeLocked($id, (int)$compId, $employeeId, $locked, $this->userId(), $this->isAdmin()));
    }

    public function employeeVerifyBulk() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeIds = (is_array($data) && is_array($data['employee_ids'] ?? null)) ? array_map('intval', $data['employee_ids']) : [];
        $verified = is_array($data) && !empty($data['verified']);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->bulkSetEmployeeVerified($id, (int)$compId, $employeeIds, $verified, $this->userId(), $this->isAdmin()));
    }

    public function employeeLockBulk() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeIds = (is_array($data) && is_array($data['employee_ids'] ?? null)) ? array_map('intval', $data['employee_ids']) : [];
        $locked = is_array($data) && !empty($data['locked']);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->bulkSetEmployeeLocked($id, (int)$compId, $employeeIds, $locked, $this->userId(), $this->isAdmin()));
    }

    public function employeeCommentAdd() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $tag = (is_array($data) && !empty($data['tag'])) ? (string)$data['tag'] : null;
        $comment = (is_array($data) && isset($data['comment'])) ? (string)$data['comment'] : '';
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->employeeCommentAdd($id, (int)$compId, $employeeId, $tag, $comment, $this->userId(), $this->isAdmin()));
    }

    public function errorEmployees() {
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->errorEmployeesForRun($id, (int)$compId)]);
    }

    public function employeeCommentList() {
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->employeeComments($id, (int)$compId, $employeeId)]);
    }

    public function employeeCommentUpdate() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $commentId = (is_array($data) && isset($data['comment_id'])) ? (int)$data['comment_id'] : 0;
        $tag = (is_array($data) && !empty($data['tag'])) ? (string)$data['tag'] : null;
        $comment = (is_array($data) && isset($data['comment'])) ? (string)$data['comment'] : '';
        if (!$compId || $id <= 0 || $commentId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->employeeCommentUpdate($id, (int)$compId, $commentId, $tag, $comment, $this->userId(), $this->isAdmin()));
    }

    public function employeeCommentDelete() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $commentId = (is_array($data) && isset($data['comment_id'])) ? (int)$data['comment_id'] : 0;
        if (!$compId || $id <= 0 || $commentId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->employeeCommentDelete($id, (int)$compId, $commentId, $this->userId(), $this->isAdmin()));
    }

    public function submit() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->submit($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function revert() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        // 2026-08-24: optional explicit target status (pending_approval/rejected/need_info) for
        // reverting a DECIDED run -- see PayrollRunModel::revert()'s own docblock. Omitted (or a
        // run still at pending_approval, which has only one possible target anyway) falls back to
        // the model's own default.
        $toState = !empty($data['to_state']) ? (string)$data['to_state'] : null;
        $this->json($this->model->revert($id, (int)$compId, $this->userId(), $this->isAdmin(), $note, $toState));
    }

    public function approve() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        $this->json($this->model->approve($id, (int)$compId, $this->userId(), $this->isAdmin(), $note));
    }

    public function reject() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $this->json($this->model->reject($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason));
    }

    public function bulkApprove() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = (is_array($data) && isset($data['ids']) && is_array($data['ids'])) ? $data['ids'] : [];
        if (!$compId || empty($ids)) {
            $this->json(['status' => false, 'message' => 'No payroll runs selected.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        $this->json($this->model->bulkApprove($ids, (int)$compId, $this->userId(), $this->isAdmin(), $note));
    }

    public function bulkReject() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = (is_array($data) && isset($data['ids']) && is_array($data['ids'])) ? $data['ids'] : [];
        if (!$compId || empty($ids)) {
            $this->json(['status' => false, 'message' => 'No payroll runs selected.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $this->json($this->model->bulkReject($ids, (int)$compId, $this->userId(), $this->isAdmin(), $reason));
    }

    public function cancel() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $this->json($this->model->cancel($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason));
    }

    public function reviseAfterReject() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->reviseAfterReject($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function requestInfo() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $this->json($this->model->requestInfo($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason));
    }

    public function bulkRequestInfo() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = (is_array($data) && isset($data['ids']) && is_array($data['ids'])) ? $data['ids'] : [];
        if (!$compId || empty($ids)) {
            $this->json(['status' => false, 'message' => 'No payroll runs selected.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $this->json($this->model->bulkRequestInfo($ids, (int)$compId, $this->userId(), $this->isAdmin(), $reason));
    }

    public function reviseAfterNeedInfo() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->reviseAfterNeedInfo($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function markPaid() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->markPaid($id, (int)$compId, $this->userId(), $this->isAdmin(), $data));
    }

    public function lock() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->lock($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    /** 2026-08-29, explicit request: "รายการที่ติ๊กว่าทำจ่ายแล้ว หรือปิดรอบไปแล้ว สามารถเปิดให้กลับมาแก้ไขได้
     *  และส่งอนุมัติใหม่ได้ครับ" -- see PayrollRunModel::reopen()'s own docblock. */
    public function reopen() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->reopen($id, (int)$compId, $this->userId(), $this->isAdmin(), $note));
    }
}
