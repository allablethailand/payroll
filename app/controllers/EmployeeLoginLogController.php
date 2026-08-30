<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmployeeLoginLogModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/**
 * 2026-08-29, explicit request: see EmployeeLoginLogModel's own docblock for the full request text
 * and rationale -- this controller is the Employee Detail page's new "Login History" tab data
 * source (list, server-side DataTable) plus the tiny client-side timezone-capture beacon
 * (recordTimezone(), fired once per browser session after a successful login, see
 * public/js/app.js's own recordLoginTimezone()).
 */
class EmployeeLoginLogController extends Controller {
    private EmployeeLoginLogModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new EmployeeLoginLogModel();
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
        if (!$this->requirePermission('employee_login_log.view')) return;
        $compId = (int)getCompId();
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        if (!$compId || !$employeeId) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = (int)($_POST['start'] ?? 0);
        $length = (int)($_POST['length'] ?? 10);
        $search = (string)($_POST['search']['value'] ?? '');
        $colIndex = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : 0;
        $orderDir = isset($_POST['order'][0]['dir']) && $_POST['order'][0]['dir'] === 'asc' ? 'asc' : 'desc';
        $filters = [
            'date_from' => (string)($_POST['date_from'] ?? ''),
            'date_to' => (string)($_POST['date_to'] ?? ''),
            'device_type' => (string)($_POST['device_type'] ?? ''),
            'browser_name' => (string)($_POST['browser_name'] ?? ''),
        ];
        $res = $this->model->list($employeeId, $compId, $start, $length, $filters, $search, $colIndex, $orderDir);
        $this->json([
            'draw' => (int)($_POST['draw'] ?? 1),
            'recordsTotal' => $res['recordsTotal'],
            'recordsFiltered' => $res['recordsFiltered'],
            'data' => $res['data'],
        ]);
    }

    /** 2026-08-29, explicit request: "ในหน้า employee list ก็ให้แยกเป็น 2 tab tab employee กับประวัติการเข้าใช้
     *  ดูภาพรวมของทุกคน มี Filter ด้วย" -- company-wide overview, see EmployeeLoginLogModel::
     *  listForCompany()'s own docblock. */
    public function listCompanyWide() {
        if (!$this->requirePermission('employee_login_log.view')) return;
        $compId = (int)getCompId();
        if (!$compId) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = (int)($_POST['start'] ?? 0);
        $length = (int)($_POST['length'] ?? 10);
        $search = (string)($_POST['search']['value'] ?? '');
        $colIndex = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : 0;
        $orderDir = isset($_POST['order'][0]['dir']) && $_POST['order'][0]['dir'] === 'asc' ? 'asc' : 'desc';
        $filters = [
            'employee_id' => (int)($_POST['employee_id'] ?? 0),
            'date_from' => (string)($_POST['date_from'] ?? ''),
            'date_to' => (string)($_POST['date_to'] ?? ''),
            'device_type' => (string)($_POST['device_type'] ?? ''),
            'browser_name' => (string)($_POST['browser_name'] ?? ''),
        ];
        $res = $this->model->listForCompany($compId, $start, $length, $filters, $search, $colIndex, $orderDir);
        $this->json([
            'draw' => (int)($_POST['draw'] ?? 1),
            'recordsTotal' => $res['recordsTotal'],
            'recordsFiltered' => $res['recordsFiltered'],
            'data' => $res['data'],
        ]);
    }

    public function filterOptionsCompanyWide() {
        if (!$this->requirePermission('employee_login_log.view')) return;
        $compId = (int)getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['device_types' => [], 'browser_names' => []]]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->distinctFilterValuesForCompany($compId)]);
    }

    public function filterOptions() {
        if (!$this->requirePermission('employee_login_log.view')) return;
        $compId = (int)getCompId();
        $employeeId = (int)($_GET['employee_id'] ?? 0);
        if (!$compId || !$employeeId) {
            $this->json(['status' => true, 'data' => ['device_types' => [], 'browser_names' => []]]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->distinctFilterValues($employeeId, $compId)]);
    }

    /** No permission gate beyond being logged in at all -- this only ever patches the CURRENT
     *  session's own just-created login row (id/employee_id/comp_id all read from $_SESSION, not
     *  trusted from the request body), so there's nothing here for employee_login_log.view to gate. */
    public function recordTimezone() {
        $employeeId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $compId = (int)($_SESSION['user']['company_id'] ?? 0);
        $loginLogId = (int)($_SESSION['login_log_id'] ?? 0);
        if (!$employeeId || !$compId || !$loginLogId) {
            $this->json(['status' => false, 'message' => 'No active login session to update.']);
            return;
        }
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $timezone = is_array($body) ? (string)($body['timezone'] ?? '') : '';
        $ok = $this->model->recordTimezone($loginLogId, $compId, $employeeId, $timezone);
        $this->json(['status' => $ok]);
    }
}
