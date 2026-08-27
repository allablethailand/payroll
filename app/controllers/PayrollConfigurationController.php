<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollConfigurationModel.php';
require_once __DIR__ . '/../models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../models/PayrollCycleModel.php';
require_once __DIR__ . '/../models/AttendanceBonusSchemeModel.php';
require_once __DIR__ . '/../models/AttendanceBonusLedgerModel.php';
require_once __DIR__ . '/../models/AttendanceDeductionRuleModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
class PayrollConfigurationController extends Controller {
    private $model;
    private $pedTypeModel;
    private $cycleModel;
    private $attendanceBonusModel;
    private $ledgerModel;
    private AttendanceDeductionRuleModel $attendanceDeductionRuleModel;
    private PermissionModel $permissionModel;
    public function __construct(){
        $this->model = new PayrollConfigurationModel();
        $this->pedTypeModel = new PayrollEarningDeductionTypeModel();
        $this->cycleModel = new PayrollCycleModel();
        $this->attendanceBonusModel = new AttendanceBonusSchemeModel();
        $this->ledgerModel = new AttendanceBonusLedgerModel();
        $this->attendanceDeductionRuleModel = new AttendanceDeductionRuleModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
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

    public function bonusSchemeOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $data = $this->ledgerModel->schemeOptions((int)$compId, $search, $page, $limit);
        $this->json(['status' => true, 'data' => $data]);
    }

    public function bonusLedgerList() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
        $compId = getCompId();
        $schemeId = isset($_GET['scheme_id']) ? (int)$_GET['scheme_id'] : 0;
        $year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
        $month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
        if (!$compId || $schemeId <= 0 || $year <= 0 || $month < 1 || $month > 12) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->ledgerModel->list((int)$compId, $schemeId, $year, $month)]);
    }

    public function bonusLedgerGet() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->ledgerModel->get($id, (int)$compId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function bonusLedgerSave() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->ledgerModel->save((int)$compId, $data, $userId);
        $this->json($result);
    }

    public function bonusLedgerLock() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $result = $this->ledgerModel->lock($id, (int)$compId, $userId);
        $this->json($result);
    }

    public function bonusLedgerDelete() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $result = $this->ledgerModel->delete($id, (int)$compId);
        $this->json($result);
    }

    public function attendanceBonusList() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->attendanceBonusModel->list((int)$compId)]);
    }

    public function attendanceBonusGet() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->attendanceBonusModel->get($id, (int)$compId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function attendanceBonusSave() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->attendanceBonusModel->save((int)$compId, $data, $userId);
        $this->json($result);
    }

    public function attendanceBonusDelete() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $result = $this->attendanceBonusModel->delete((int)$compId, $id, $userId);
        $this->json($result);
    }

    public function bankFileFormatOptions() {
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $this->json(['status' => true, 'data' => $this->cycleModel->bankFileFormatOptions($search, $page, $limit)]);
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
        if (!$this->requirePermission('payroll_configuration.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->cycleModel->list((int)$compId)]);
    }

    public function cycleGet() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->cycleModel->save((int)$compId, $data, $userId);
        $this->json($result);
    }

    public function cycleDelete() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $result = $this->cycleModel->delete((int)$compId, $id, $userId);
        $this->json($result);
    }

    public function pedTypeList() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->pedTypeModel->save((int)$compId, $data, $userId);
        $this->json($result);
    }

    /** "Load Default Items" -- inserts the system's starter set of earning/deduction types for this company, skipping any item_code already present (active or soft-deleted). Idempotent, safe to click more than once. */
    public function pedTypeSeedDefaults() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        if (!$this->requirePermission('payroll_configuration.manage')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->attendanceDeductionRuleModel->ruleGetAll((int)$compId)]);
    }

    public function attendanceDeductionRuleSave() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->attendanceDeductionRuleModel->ruleSave($data, (int)$compId, $this->userId()));
    }

    public function pedTypeDelete() {
        if (!$this->requirePermission('payroll_configuration.manage')) return;
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
        $result = $this->pedTypeModel->delete((int)$compId, $id, $userId);
        $this->json($result);
    }
}
