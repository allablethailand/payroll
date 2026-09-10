<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmployeeModel.php';
require_once __DIR__ . '/../models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../models/EmployeeRecurringEarningModel.php';
require_once __DIR__ . '/../models/EmployeeRecurringDeductionModel.php';
require_once __DIR__ . '/../models/EmployeeOtRateModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../models/PayrollSyncTransactionLogModel.php';
require_once __DIR__ . '/../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../services/ThumbnailGenerator.php';
class EmployeeController extends Controller {
    private $model;
    private $earningDeductionModel;
    private EmployeeRecurringEarningModel $recurringEarningModel;
    private EmployeeRecurringDeductionModel $recurringDeductionModel;
    private EmployeeOtRateModel $otRateModel;
    private PermissionModel $permissionModel;
    private PayrollSyncTransactionLogModel $syncTransactionLogModel;
    private PayrollReportDataModel $reportDataModel;
    public function __construct(){
        $this->model = new EmployeeModel();
        $this->earningDeductionModel = new EmployeeEarningDeductionModel();
        $this->recurringEarningModel = new EmployeeRecurringEarningModel();
        $this->recurringDeductionModel = new EmployeeRecurringDeductionModel();
        $this->otRateModel = new EmployeeOtRateModel();
        $this->permissionModel = new PermissionModel();
        $this->syncTransactionLogModel = new PayrollSyncTransactionLogModel();
        $this->reportDataModel = new PayrollReportDataModel();
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

    /** Full employee PII (salary, bank, national ID, documents) requires employee.view/.manage -- list() stays ungated (no PII in its columns). */
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
        $this->view('employee/list');
    }
    // 2026-09-02, 3-way Employee submenu split -- Login History and Reports were previously 2 of
    // this page's own 4 top-level tabs, now their own standalone pages/routes (see header.php's own
    // Employee submenu). Kept on this same controller/model (not new classes) per this project's
    // own established precedent from the payslip/* -> payslip-documents/* rename: a menu/route
    // restructuring changes the URL and menu label, not the controller class.
    public function loginHistory() {
        $this->view('employee/login-history');
    }
    public function reports() {
        $this->view('employee/reports');
    }
    // 2026-09-03, Platform Hardening Phase 3 Stage 5 -- gates the "Permission Overrides" tab's own
    // <li> at the VIEW level (not just a client-side hide), same `rbac.view` check the Permission
    // Matrix's own menu-visibility gate in header.php uses. Deliberately NOT `employee.view`/
    // `.edit` -- see PermissionController::employeeOverridesGet()'s own docblock on why this stays
    // scoped to whoever can already manage the Permission Matrix.
    private function canManagePermissionOverrides(): bool {
        $compId = (int)getCompId();
        if (!$compId) {
            return false;
        }
        return $this->permissionModel->checkPermission($this->userId(), 'rbac.view', $this->isAdmin(), $compId)['allowed'];
    }

    public function create() {
        $this->view('employee/detail', ['employee_no' => null, 'canManagePermissionOverrides' => $this->canManagePermissionOverrides()]);
    }
    public function detail($data = null) {
        $employee_no = $data;
        if (!$employee_no) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $this->view('employee/detail', ['employee_no' => $employee_no, 'canManagePermissionOverrides' => $this->canManagePermissionOverrides()]);
    }
    public function list(){
        $compId = getCompId();
        if (!$compId) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $filters = [
            'status' => $_POST['status'] ?? '',
            'employment_status' => $_POST['employment_status'] ?? '',
            'role_id' => $_POST['role_id'] ?? '',
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'shift_id' => $_POST['shift_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
            // 2026-08-30 (Phase 3, T022) -- '' (All), '1' (Pays Salary), '0' (No Salary).
            'is_payroll_participant' => $_POST['is_payroll_participant'] ?? '',
            'created_date_from' => $_POST['created_date_from'] ?? '',
            'created_date_to' => $_POST['created_date_to'] ?? '',
            // 2026-08-27, explicit request: Excel-style per-column header filter (proof-of-concept
            // on this table first) -- see EmployeeModel::buildListWhere()'s own docblock. Sent by
            // jQuery as nested `column_filters[colKey][]=value` form fields, which PHP already
            // parses into this exact shape.
            'column_filters' => is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [],
        ];
        $search = (string)($_POST['search']['value'] ?? '');
        $colIndex = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : 0;
        $orderDir = isset($_POST['order'][0]['dir']) && $_POST['order'][0]['dir'] === 'desc' ? 'desc' : 'asc';
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $res = $this->model->list((int)$compId, $start, $length, $filters, $search, $colIndex, $orderDir, (string)$lang);
        $this->json([
            "draw" => intval($_POST['draw'] ?? 1),
            "recordsTotal" => $res['total'],
            "recordsFiltered" => $res['filtered'],
            "data" => $res['data']
        ]);
    }
    /** 2026-08-30 (Phase 3, T018) -- server-side DataTables source for the "Recheck ข้อมูล" tab.
     *  Reuses the SAME station filters (department/team/shift/branch/role/date range) as list()
     *  itself; unlike list(), there's no per-column sort here (see recheckList()'s own docblock --
     *  each field_readiness column is a derived boolean, not a raw sortable value, same exemption
     *  category CLAUDE.md's own DataTables convention already grants widget-only columns). */
    // Explicit request: "ต้องการอีก Tab ต่อจาก Tab ตรวจสอบข้อมูล เป็น Tab สรุปรวมรายได้รายหักที่ หักหรือได้
    // ประจำ" -- see EmployeeModel::standingSummaryList()'s own docblock for the full design. Same
    // $_POST/lang/no-explicit-permission-gate convention as recheckList() just below (list()'s own
    // docblock: "Full employee PII...requires employee.view/.manage -- list() stays ungated" -- this
    // tab has no more PII exposure than recheckList() already does, matched for consistency).
    public function standingSummaryList() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'totals' => []]);
            return;
        }
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $filters = [
            'role_id' => $_POST['role_id'] ?? '',
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'shift_id' => $_POST['shift_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $search = (string)($_POST['search']['value'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $res = $this->model->standingSummaryList((int)$compId, $start, $length, $filters, $search, (string)$lang);
        $this->json([
            'draw' => intval($_POST['draw'] ?? 1),
            'recordsTotal' => $res['total'],
            'recordsFiltered' => $res['filtered'],
            'data' => $res['data'],
            'totals' => $res['totals'],
        ]);
    }
    /** Phase 1 of the Employee Reports plan -- headcount movement (hires/exits) for one calendar
     *  year, see EmployeeModel::headcountMovementReport()'s own docblock. Same ungated convention as
     *  standingSummaryList()/recheckList() above (no requirePermission() gate -- this whole page has
     *  none for its own list/summary endpoints). */
    public function headcountMovementReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $year = intval($_POST['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $this->json(['status' => false, 'message' => 'Invalid year.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $res = $this->model->headcountMovementReport((int)$compId, $year, $filters);
        $this->json(['status' => true, 'data' => $res]);
    }
    /** Phase 2 of the Employee Reports plan -- 3 quick-win reports, same ungated convention as the
     *  rest of this page's own list/summary endpoints. */
    public function expiryReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $withinDays = intval($_POST['within_days'] ?? 90);
        if (!in_array($withinDays, [30, 60, 90], true)) {
            $this->json(['status' => false, 'message' => 'Invalid within_days.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $res = $this->model->expiryReport((int)$compId, $withinDays, $filters);
        $this->json(['status' => true, 'data' => $res]);
    }
    public function probationReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $res = $this->model->probationReport((int)$compId, $filters);
        $this->json(['status' => true, 'data' => $res]);
    }
    public function statutoryEnrollmentReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $res = $this->model->statutoryEnrollmentReport((int)$compId, $filters);
        $this->json(['status' => true, 'data' => $res]);
    }
    /** Phase 3 of the Employee Reports plan -- structural/analytical reports, same ungated
     *  convention as the rest of this page's own list/summary endpoints. */
    public function headcountStructureReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $groupBy = (string)($_POST['group_by'] ?? 'department');
        if (!in_array($groupBy, ['department', 'position', 'branch', 'employment_type'], true)) {
            $this->json(['status' => false, 'message' => 'Invalid group_by.']);
            return;
        }
        $res = $this->model->headcountStructureReport((int)$compId, $groupBy);
        $this->json(['status' => true, 'data' => $res]);
    }
    public function tenureReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $res = $this->model->tenureReport((int)$compId, $filters);
        $this->json(['status' => true, 'data' => $res]);
    }
    public function birthdayAnniversaryReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $month = intval($_POST['month'] ?? date('n'));
        if ($month < 1 || $month > 12) {
            $this->json(['status' => false, 'message' => 'Invalid month.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $res = $this->model->birthdayAnniversaryReport((int)$compId, $month, $filters);
        $this->json(['status' => true, 'data' => $res]);
    }
    /** Phase 4 (the final phase) of the Employee Reports plan -- company-wide data completeness
     *  overview, same ungated convention as the rest of this page's own list/summary endpoints. */
    public function completenessOverviewReport() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $res = $this->model->completenessOverviewReport((int)$compId, $filters);
        $this->json(['status' => true, 'data' => $res]);
    }
    public function recheckList() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $filters = [
            'role_id' => $_POST['role_id'] ?? '',
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'shift_id' => $_POST['shift_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
        ];
        $search = (string)($_POST['search']['value'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $participantMode = ($_POST['view'] ?? '') === 'excluded' ? 'excluded' : 'participant';
        $res = $this->model->recheckList((int)$compId, $start, $length, $filters, $search, (string)$lang, $participantMode);
        $this->json([
            "draw" => intval($_POST['draw'] ?? 1),
            "recordsTotal" => $res['total'],
            "recordsFiltered" => $res['filtered'],
            "data" => $res['data']
        ]);
    }
    /** 2026-08-31, explicit request -- Recheck tab's "Remove from Payroll"/"Add Back" row action.
     *  Reads a plain JSON body ({id, is_payroll_participant}) same as save()'s own convention on
     *  this controller. Gated by employee.manage (same permission save() itself requires) since this
     *  changes a real payroll-eligibility flag, not just a display filter. */
    public function setPayrollParticipant() {
        if (!$this->requirePermission('employee.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $employeeId = (int)($data['id'] ?? 0);
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid employee id.']);
            return;
        }
        $participant = !empty($data['is_payroll_participant']);
        $ok = $this->model->setPayrollParticipant($employeeId, (int)$compId, $participant);
        $this->json(['status' => $ok]);
    }
    /** 2026-08-30 (Phase 3, T024) -- powers the Employee tab's own station-card pipeline counts.
     *  Same station filters (department/team/shift/branch/role/date range/is_payroll_participant)
     *  as list() itself, minus status/employment_status (blanked internally by
     *  EmployeeModel::stationCounts() regardless -- those ARE the station selector, never sent by
     *  the frontend's own currentStationCountsFilters() either). */
    public function stationCounts() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['active' => 0, 'probation' => 0, 'permanent' => 0, 'resigned' => 0]]);
            return;
        }
        $filters = [
            'role_id' => $_POST['role_id'] ?? '',
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'shift_id' => $_POST['shift_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
            'is_payroll_participant' => $_POST['is_payroll_participant'] ?? '',
            'created_date_from' => $_POST['created_date_from'] ?? '',
            'created_date_to' => $_POST['created_date_to'] ?? '',
        ];
        $search = (string)($_POST['search'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $data = $this->model->stationCounts((int)$compId, $filters, $search, (string)$lang);
        $this->json(['status' => true, 'data' => $data]);
    }
    /** 2026-08-27, explicit request: "ในตารางทุกตาราง...เพิ่มให้สามารถ Filter ได้...เหมือนกับ Excel" --
     *  proof-of-concept on this table first (server-side, so the checkbox list can't be computed
     *  from the browser's own already-loaded rows the way a client-side table's filter can). Powers
     *  one column's filter dropdown -- excludes that column's OWN current selection from the WHERE
     *  clause (see EmployeeModel::buildListWhere()) so opening it shows every value it could hold,
     *  not just the ones already checked. */
    public function listColumnValues() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'values' => []]);
            return;
        }
        $column = (string)($_POST['column'] ?? '');
        $filters = [
            'status' => $_POST['status'] ?? '',
            'employment_status' => $_POST['employment_status'] ?? '',
            'role_id' => $_POST['role_id'] ?? '',
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'shift_id' => $_POST['shift_id'] ?? '',
            'branch_id' => $_POST['branch_id'] ?? '',
            'is_payroll_participant' => $_POST['is_payroll_participant'] ?? '',
            'created_date_from' => $_POST['created_date_from'] ?? '',
            'created_date_to' => $_POST['created_date_to'] ?? '',
            'column_filters' => is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [],
        ];
        $search = (string)($_POST['search'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $values = $this->model->listColumnValues((int)$compId, $column, $filters, $search, (string)$lang);
        $this->json(['status' => true, 'values' => $values]);
    }
    /** Batch 3A item 4 -- backs the app.js employee-avatar quick-view modal (click any avatar
     *  rendered via apvAvatarHtml(..., {employeeId}) app-wide). Same permission gate as get(). */
    public function quickView() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $employee = $this->model->quickView((int)$compId, $employeeId);
        if ($employee) {
            $this->json(['status' => true, 'data' => $employee]);
        } else {
            $this->json(['status' => false, 'message' => 'Employee not found.']);
        }
    }
    public function get() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeNo = isset($_GET['employee_no']) ? trim((string)$_GET['employee_no']) : '';
        if (!$compId || $employeeNo === '') {
            $this->json(['status' => false, 'message' => 'Missing employee_no.']);
            return;
        }
        $employee = $this->model->get((int)$compId, $employeeNo);
        if ($employee) {
            // 2026-08-31, explicit request: "สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX แต่ยังสามารถ
            // คำนวณเงินเดือน...ได้ตามสิทธิ์" -- masking ONLY happens here, at the response-shaping
            // layer, never inside EmployeeModel::get() itself (that method's decryption/return
            // value stays correct for every OTHER caller, e.g. payroll calculation, which never
            // goes through this permission check at all -- see PermissionModel's own docblock).
            // own_only scope: subject = the employee whose profile this IS (row itself), compared
            // against the ACTING user's own id.
            $visibility = $this->permissionModel->resolveSalaryVisibility(
                $this->userId(), 'employee', $this->isAdmin(), (int)$compId, (int)($employee['id'] ?? 0)
            );
            if (!$visibility['full']) {
                $employee['base_salary_amount'] = PermissionModel::MASK_VALUE;
            }
            $this->json(['status' => true, 'data' => $employee]);
        } else {
            $this->json(['status' => false, 'message' => 'Employee not found.']);
        }
    }
    public function reportToOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $excludeId = isset($_POST['exclude_id']) && $_POST['exclude_id'] !== '' ? (int)$_POST['exclude_id'] : null;
        $payrollParticipantsOnly = !empty($_POST['payroll_participants_only']);
        $data = $this->model->reportToOptions((int)$compId, $excludeId, $search, $page, $limit, $payrollParticipantsOnly);
        $this->json(['status' => true, 'data' => $data]);
    }
    /** 2026-09-02, explicit request: Probation/Internship "use company policy" read-only info card
     *  (Salary tab) -- needs the EFFECTIVE company policy values to display, but gating this behind
     *  payroll_configuration.manage (like PayrollConfigurationController::policyGet()) would hide it
     *  from anyone who can edit an employee but not payroll config itself. Gated at employee.view
     *  instead (the same permission the Salary tab's own get() already requires) since this is
     *  read-only reference data, not a mutation. */
    public function payrollPolicySettings() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $policyModel = new PayrollPolicyModel();
        $this->json(['status' => true, 'data' => [
            'probation' => $policyModel->probationSettings((int)$compId),
            'intern' => $policyModel->internSettings((int)$compId),
        ]]);
    }
    /** 2026-09-02, explicit request: "เลือกต่อได้ว่าจะใช้บัญชีไหนของรอบนั้น" -- the Salary tab's
     *  default_bank_account_id picker, scoped to whichever cycle the employee currently has
     *  selected (falls back to the company's own default account when that cycle has none
     *  configured -- see EmployeePaymentMethodModel::scopedBankAccountOptions()'s own docblock). */
    public function paymentAccountOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $cycleId = isset($_POST['cycle_id']) ? (int)$_POST['cycle_id'] : 0;
        if ($cycleId <= 0) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $model = new EmployeePaymentMethodModel();
        $this->json(['status' => true, 'data' => $model->scopedBankAccountOptions((int)$compId, $cycleId, $search, $page, $limit)]);
    }
    /** Mixed-payment line breakdown for one employee (Employment tab's repeatable-row form, only
     *  populated while payment_method_id resolves to 'mixed') -- get() below already returns the
     *  employee's own scalar columns, this is the separate 1:many child list. */
    public function paymentMethodLines() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0 || !$this->model->employeeBelongsToComp($employeeId, (int)$compId)) {
            $this->json(['status' => false, 'message' => 'Employee not found.']);
            return;
        }
        $model = new EmployeePaymentMethodModel();
        $this->json(['status' => true, 'data' => $model->getLines($employeeId)]);
    }
    public function save() {
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
        // EmployeeModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'employee.edit' : 'employee.add')) return;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->save((int)$compId, $data, $userId, $ip, $ua);
        $this->json($result);
    }
    /** 2026-08-26, explicit request: "ในการจัดการพนักงาน เพิ่มการเก็บลายเซ็นต์ของพนักงานแต่ละคนได้" --
     *  identical pattern/validation to CompanyProfileController::uploadSignature() (finfo MIME check,
     *  2MB limit, jpg/png/svg only, random 32-hex filename), one folder per company (not per
     *  employee -- see EmployeeModel::isValidSignaturePath()'s own comment on why). Same dual input
     *  method too: a live-drawn signature reaches here as a normal multipart file upload (the
     *  browser's canvas is exported to a PNG Blob client-side), no separate endpoint needed. */
    public function uploadSignature() {
        if (!$this->requirePermission('employee.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'File upload failed.']);
            return;
        }
        $file = $_FILES['file'];
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 2MB limit.']);
            return;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or SVG.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../public/uploads/employee_signatures/' . (int)$compId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->json(['status' => false, 'message' => 'Failed to prepare storage directory.']);
            return;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['status' => false, 'message' => 'Failed to save file.']);
            return;
        }
        $relativePath = 'public/uploads/employee_signatures/' . (int)$compId . '/' . $safeName;
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'signature_path' => $relativePath, 'file_size' => (int)$file['size']]);
    }

    /** 2026-08-29, real bug found and fixed (explicit report: "ใส่รูปพนักงาน กดบันทึกแล้ว ไม่มาแสดงผล") --
     *  `employees.profile_photo_path` already existed as a plain passthrough column in
     *  EmployeeModel::allColumns() (same as signature_path), and the view already had a file input +
     *  local preview, but NO upload endpoint was ever wired up to actually get the file to the
     *  server -- the file input's selection never left the browser (JSON.stringify can't carry a
     *  File object), so the photo always reverted to nothing after a real save/reload. Identical
     *  pattern to uploadSignature() above, own folder. */
    public function uploadPhoto() {
        if (!$this->requirePermission('employee.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'File upload failed.']);
            return;
        }
        $file = $_FILES['file'];
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 2MB limit.']);
            return;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or SVG.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../public/uploads/employee_photos/' . (int)$compId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->json(['status' => false, 'message' => 'Failed to prepare storage directory.']);
            return;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['status' => false, 'message' => 'Failed to save file.']);
            return;
        }
        $relativePath = 'public/uploads/employee_photos/' . (int)$compId . '/' . $safeName;
        // Platform Hardening Phase 5B: real thumbnail for the profile photo (jpg/png only -- GD can't
        // rasterize SVG, so an SVG upload here simply gets no thumbnail, same as ThumbnailGenerator's
        // own docblock describes). A generation failure never fails the upload itself.
        $thumbnailRelativePath = null;
        if (ThumbnailGenerator::isSupportedMime($detectedMime)) {
            $thumbName = 'thumb_' . $safeName;
            if (ThumbnailGenerator::generate($destPath, $uploadDir . $thumbName)) {
                $thumbnailRelativePath = 'public/uploads/employee_photos/' . (int)$compId . '/' . $thumbName;
            }
        }
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'profile_photo_path' => $relativePath,
            'file_size' => (int)$file['size'], 'thumbnail_path' => $thumbnailRelativePath]);
    }

    public function delete() {
        if (!$this->requirePermission('employee.delete')) return;
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
        $result = $this->model->delete((int)$compId, $id, $userId);
        $this->json($result);
    }
    public function dependentList() { $this->handleChildList('dependent'); }
    public function dependentSave() { $this->handleChildSave('dependent'); }
    public function dependentDelete() { $this->handleChildDelete('dependent'); }
    public function parentList() { $this->handleChildList('parent'); }
    public function parentSave() { $this->handleChildSave('parent'); }
    public function parentDelete() { $this->handleChildDelete('parent'); }
    public function earningDeductionOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        // 'type' comes through automatically for a select2-remote field with data-type="earning"/
        // "deduction" set (2026-08-19, explicit request: Add Earning/Add Deduction each pre-filter
        // the catalog dropdown to their own item_type).
        $itemType = (string)($_POST['type'] ?? '');
        $data = $this->earningDeductionModel->activeOptions((int)$compId, $search, $page, $limit, $itemType !== '' ? $itemType : null);
        $this->json(['status' => true, 'data' => $data]);
    }
    public function earningDeductionList() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $itemType = isset($_GET['item_type']) ? (string)$_GET['item_type'] : '';
        $data = $this->earningDeductionModel->list($employeeId, (int)$compId, $itemType !== '' ? $itemType : null);
        $this->json(['status' => true, 'data' => $data]);
    }
    public function earningDeductionGet() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->earningDeductionModel->get($id, (int)$compId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }
    // 2026-09-04, Backlog Phase 9->10, T051 -- read-only Sync History sub-section on the Income &
    // Deductions tab. Same permission gate/response shape as earningDeductionList() above; both
    // endpoints only ever read, nothing here is editable from the UI.
    public function syncTransactionLogList() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $data = $this->syncTransactionLogModel->listForEmployee((int)$compId, $employeeId);
        $this->json(['status' => true, 'data' => $data]);
    }
    public function scheduledItemOccurrenceList() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $data = $this->reportDataModel->scheduledItemOccurrences((int)$compId, null, null, $employeeId);
        $this->json(['status' => true, 'data' => $data]);
    }
    /** Pure calculation preview (2026-08-20, explicit request) -- lets the modal show/auto-fill
     *  the per-installment schedule live as principal/installments/interest settings change,
     *  without duplicating the amortization math in JS. Mirrors POST /api/payslip-template.preview's
     *  shape (server computes, client just renders). Not permission-gated: it touches no employee
     *  data, just runs arithmetic on whatever numbers are passed in. */
    public function earningDeductionPreviewInstallments() {
        $principal = isset($_GET['principal']) && is_numeric($_GET['principal']) ? (float)$_GET['principal'] : 0.0;
        $totalInstallments = isset($_GET['total_installments']) ? (int)$_GET['total_installments'] : 0;
        $interestType = isset($_GET['interest_type']) ? (string)$_GET['interest_type'] : 'none';
        $interestRate = isset($_GET['interest_rate']) && is_numeric($_GET['interest_rate']) ? (float)$_GET['interest_rate'] : null;
        // 2026-08-31, explicit request: Fee (% of a selectable base) -- fee_base='base_salary' needs
        // a real number to compute against; the modal already has the employee's own base salary
        // in plain text on the page (the Salary tab's own #base_salary_amount input, same value the
        // employee already sees), so the frontend just passes it straight through as a plain GET
        // param -- no server-side employee lookup/decryption needed for what's purely a live preview.
        $feePercent = isset($_GET['fee_percent']) && is_numeric($_GET['fee_percent']) ? (float)$_GET['fee_percent'] : null;
        $feeBase = isset($_GET['fee_base']) ? (string)$_GET['fee_base'] : null;
        $baseSalaryForFee = isset($_GET['base_salary_for_fee']) && is_numeric($_GET['base_salary_for_fee']) ? (float)$_GET['base_salary_for_fee'] : null;
        try {
            $amounts = $this->earningDeductionModel->computeInstallmentSchedule($principal, $totalInstallments, $interestType, $interestRate, $feePercent, $feeBase, $baseSalaryForFee);
            $this->json(['status' => true, 'data' => ['amounts' => $amounts]]);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $this->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    public function earningDeductionSave() {
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
        // EmployeeEarningDeductionModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'employee.edit' : 'employee.add')) return;
        $employeeId = isset($data['employee_id']) ? (int)$data['employee_id'] : 0;
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing employee_id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->earningDeductionModel->save($employeeId, (int)$compId, $data, $userId, $ip, $ua);
        $this->json($result);
    }
    public function earningDeductionStatus() {
        if (!$this->requirePermission('employee.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $newStatus = (is_array($data) && isset($data['status'])) ? (string)$data['status'] : '';
        if ($id <= 0 || $employeeId <= 0 || $newStatus === '') {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->earningDeductionModel->updateStatus($id, (int)$compId, $employeeId, $newStatus, $userId, $ip, $ua);
        $this->json($result);
    }
    public function earningDeductionDelete() {
        if (!$this->requirePermission('employee.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($employeeId <= 0 || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->earningDeductionModel->delete($id, (int)$compId, $employeeId, $userId, $ip, $ua);
        $this->json($result);
    }

    /* ==================== Recurring Earnings (Salary tab's own new section) --
       2026-08-26, explicit request: "รายรับที่ได้ทุกเดือนเช่นพวกค่าตำแหน่ง ค่ารถ ค่าน้ำมัน...ให้เพิ่มส่วนนี้
       เข้าไปด้วย และระงับการจ่ายได้" -- see EmployeeRecurringEarningModel's own docblock for why this is
       a separate table/section from Earning-Deduction (loans/installments). ==================== */

    /** Dropdown options for the allowance-type picker -- a dedicated, pre-filtered wrapper around
     *  the SAME catalog query the Earning-Deduction tab's own #eed_ped_type_id already uses, fixed
     *  to item_type=earning + calculation_method=fixed_amount server-side (no client-passed filter
     *  needed, so initSelect2's generic ajax data-builder didn't need touching for a 3rd filter). */
    public function recurringEarningTypeOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $data = $this->earningDeductionModel->activeOptions((int)$compId, $search, $page, $limit, 'earning', 'fixed_amount');
        $this->json(['status' => true, 'data' => $data]);
    }
    public function recurringEarningList() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->recurringEarningModel->list($employeeId, (int)$compId)]);
    }
    public function recurringEarningGet() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->recurringEarningModel->get($id, (int)$compId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }
    public function recurringEarningSave() {
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
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // EmployeeRecurringEarningModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'employee.edit' : 'employee.add')) return;
        $employeeId = isset($data['employee_id']) ? (int)$data['employee_id'] : 0;
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing employee_id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->recurringEarningModel->save($employeeId, (int)$compId, $data, $userId, $ip, $ua));
    }
    public function recurringEarningDelete() {
        if (!$this->requirePermission('employee.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($employeeId <= 0 || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->recurringEarningModel->delete($id, (int)$compId, $employeeId, $userId, $ip, $ua));
    }

    /* ==================== Recurring Deductions (Salary tab, 2026-08-31, explicit request: "หน้า
       Employee Detail เพิ่มรายหักประจำด้วยครับ") -- exact mirror of Recurring Earnings above, restricted
       to item_type='deduction' instead of 'earning'. See EmployeeRecurringDeductionModel's own
       docblock. ==================== */

    public function recurringDeductionTypeOptions() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $data = $this->earningDeductionModel->activeOptions((int)$compId, $search, $page, $limit, 'deduction', 'fixed_amount');
        $this->json(['status' => true, 'data' => $data]);
    }
    public function recurringDeductionList() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->recurringDeductionModel->list($employeeId, (int)$compId)]);
    }
    public function recurringDeductionGet() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->recurringDeductionModel->get($id, (int)$compId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }
    public function recurringDeductionSave() {
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
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // EmployeeRecurringDeductionModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'employee.edit' : 'employee.add')) return;
        $employeeId = isset($data['employee_id']) ? (int)$data['employee_id'] : 0;
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing employee_id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->recurringDeductionModel->save($employeeId, (int)$compId, $data, $userId, $ip, $ua));
    }
    public function recurringDeductionDelete() {
        if (!$this->requirePermission('employee.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($employeeId <= 0 || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->recurringDeductionModel->delete($id, (int)$compId, $employeeId, $userId, $ip, $ua));
    }

    // Explicit request: "OT Rate เพิ่มให้สามารถ Assing รายบุคคลได้ด้วย...ให้ไป Set แยก ใน Employee" -- see
    // EmployeeOtRateModel's own docblock for the full feature design.
    public function otRateGet() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $this->json($this->otRateModel->getForEmployee($employeeId, (int)$compId));
    }
    public function otRateSave() {
        if (!$this->requirePermission('employee.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $otRateSource = (is_array($data) && isset($data['ot_rate_source'])) ? (string)$data['ot_rate_source'] : 'default';
        $overrides = (is_array($data) && is_array($data['overrides'] ?? null)) ? $data['overrides'] : [];
        $assignedOtRateSetId = (is_array($data) && !empty($data['assigned_ot_rate_set_id']) && is_numeric($data['assigned_ot_rate_set_id'])) ? (int)$data['assigned_ot_rate_set_id'] : null;
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $this->json($this->otRateModel->save($employeeId, (int)$compId, $otRateSource, $overrides, $userId, $assignedOtRateSetId));
    }

    private function handleChildList(string $type): void {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $data = $this->model->listChildren($type, $employeeId, (int)$compId);
        $this->json(['status' => true, 'data' => $data]);
    }
    private function handleChildSave(string $type): void {
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
        // EmployeeModel::saveChild() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'employee.edit' : 'employee.add')) return;
        $employeeId = isset($data['employee_id']) ? (int)$data['employee_id'] : 0;
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing employee_id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->saveChild($type, $employeeId, (int)$compId, $data, $userId);
        $this->json($result);
    }
    private function handleChildDelete(string $type): void {
        if (!$this->requirePermission('employee.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($employeeId <= 0 || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->deleteChild($type, $employeeId, (int)$compId, $id, $userId);
        $this->json($result);
    }
    public function documentList() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $data = $this->model->listDocuments($employeeId, (int)$compId);
        $this->json(['status' => true, 'data' => $data]);
    }
    public function documentUpload() {
        if (!$this->requirePermission('employee.add')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $documentType = isset($_POST['document_type']) ? trim((string)$_POST['document_type']) : '';
        if ($employeeId <= 0 || $documentType === '') {
            $this->json(['status' => false, 'message' => 'Missing employee_id or document_type.']);
            return;
        }
        if (!$this->model->employeeBelongsToComp($employeeId, (int)$compId)) {
            $this->json(['status' => false, 'message' => 'Employee not found.']);
            return;
        }
        if (!in_array($documentType, $this->model->documentTypes(), true)) {
            $this->json(['status' => false, 'message' => 'Invalid document_type.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'File upload failed.']);
            return;
        }
        $file = $_FILES['file'];
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 10MB limit.']);
            return;
        }
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../storage/uploads/employees/' . $employeeId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            $this->json(['status' => false, 'message' => 'Failed to prepare storage directory.']);
            return;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['status' => false, 'message' => 'Failed to save file.']);
            return;
        }

        $originalName = basename((string)$file['name']);
        $relativePath = 'storage/uploads/employees/' . $employeeId . '/' . $safeName;
        // Platform Hardening Phase 5B: thumbnail only for image-mime (jpg/png) rows -- pdf/doc/docx
        // stay icon-only, per the confirmed policy. A generation failure never fails the upload.
        $thumbnailRelativePath = null;
        if (ThumbnailGenerator::isSupportedMime($detectedMime)) {
            $thumbName = 'thumb_' . $safeName;
            if (ThumbnailGenerator::generate($destPath, $uploadDir . $thumbName)) {
                $thumbnailRelativePath = 'storage/uploads/employees/' . $employeeId . '/' . $thumbName;
            }
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->saveDocument($employeeId, (int)$compId, $documentType, $originalName, $relativePath, $userId, (int)$file['size'], $thumbnailRelativePath);
        if (!$result['status']) {
            @unlink($destPath);
        }
        $this->json($result);
    }
    public function documentView() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $doc = $this->model->getDocument($id, (int)$compId);
        if (!$doc) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $fullPath = __DIR__ . '/../../' . $doc['file_path'];
        $realPath = realpath($fullPath);
        $storageRoot = realpath(__DIR__ . '/../../storage/uploads/employees');
        if ($realPath === false || $storageRoot === false || strpos($realPath, $storageRoot) !== 0 || !is_file($realPath)) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($realPath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename((string)$doc['file_name']) . '"');
        header('Content-Length: ' . (string)filesize($realPath));
        header('X-Content-Type-Options: nosniff');
        readfile($realPath);
        exit;
    }
    public function documentDelete() {
        if (!$this->requirePermission('employee.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($employeeId <= 0 || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->deleteDocument($id, $employeeId, (int)$compId, $userId);
        $this->json($result);
    }
}
