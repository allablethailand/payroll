<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/NotificationModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/**
 * 2026-08-29, explicit request: see NotificationModel's own docblock for the full analysis/request
 * text. No permission gate beyond being logged in -- every endpoint here is already scoped to the
 * CURRENT session's own employee_id/comp_id (never trusted from the request body), so there is
 * nothing for a permission key to additionally restrict; this mirrors EmployeeLoginLogController::
 * recordTimezone()'s own same reasoning.
 */
class NotificationController extends Controller {
    private NotificationModel $model;

    public function __construct() {
        $this->model = new NotificationModel();
    }

    private function employeeId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    /** GET, backs both the header bell dropdown (small page size, lazy/infinite-scroll load) and the dedicated "view all" page (larger page size). */
    public function list() {
        $compId = (int)getCompId();
        $employeeId = $this->employeeId();
        if (!$compId || !$employeeId) {
            $this->json(['status' => false, 'message' => 'Not logged in.']);
            return;
        }
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
        $unreadOnly = !empty($_GET['unread_only']);
        $rows = $this->model->listForEmployee($compId, $employeeId, $offset, $limit, $unreadOnly);
        $this->json(['status' => true, 'data' => $rows, 'has_more' => count($rows) === $limit]);
    }

    /** POST, server-side DataTables source for the dedicated "view all" page's table -- see
     *  NotificationModel::listDataTable()'s own docblock for why this page gets serverSide:true
     *  while the header dropdown/Dashboard widget stay on the small offset/limit list() above. */
    public function listDataTable() {
        $compId = (int)getCompId();
        $employeeId = $this->employeeId();
        if (!$compId || !$employeeId) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = max(0, (int)($_POST['start'] ?? 0));
        $length = (int)($_POST['length'] ?? 20);
        $search = trim((string)($_POST['search']['value'] ?? ''));
        $dateFrom = trim((string)($_POST['date_from'] ?? ''));
        $dateTo = trim((string)($_POST['date_to'] ?? ''));
        $orderCol = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : 2;
        $orderDir = isset($_POST['order'][0]['dir']) ? (string)$_POST['order'][0]['dir'] : 'desc';
        $isRead = trim((string)($_POST['is_read'] ?? ''));
        $res = $this->model->listDataTable($compId, $employeeId, $start, $length, $search, $dateFrom, $dateTo, $orderCol, $orderDir, $isRead);
        $this->json([
            'draw' => (int)($_POST['draw'] ?? 1),
            'recordsTotal' => $res['total'],
            'recordsFiltered' => $res['filtered'],
            'data' => $res['data'],
        ]);
    }

    public function unreadCount() {
        $compId = (int)getCompId();
        $employeeId = $this->employeeId();
        if (!$compId || !$employeeId) {
            $this->json(['status' => true, 'data' => ['count' => 0]]);
            return;
        }
        $this->json(['status' => true, 'data' => ['count' => $this->model->unreadCount($compId, $employeeId)]]);
    }

    /** 2026-08-29: "ต้องไปกดเปิดดูก่อนถึงหาย" -- called the moment ONE item is actually clicked, never
     *  just from opening the dropdown. */
    public function markRead() {
        $compId = (int)getCompId();
        $employeeId = $this->employeeId();
        $data = json_decode(file_get_contents('php://input') ?: '{}', true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || !$employeeId || $id <= 0) {
            $this->json(['status' => false]);
            return;
        }
        $this->json(['status' => $this->model->markRead($id, $compId, $employeeId)]);
    }

    public function markAllRead() {
        $compId = (int)getCompId();
        $employeeId = $this->employeeId();
        if (!$compId || !$employeeId) {
            $this->json(['status' => false]);
            return;
        }
        $this->json(['status' => $this->model->markAllRead($compId, $employeeId)]);
    }

    /** Dedicated full-page "view all" (server-rendered shell, data loaded via list() above same as the dropdown). */
    public function page() {
        $this->view('notification/index');
    }

    /* ==================== Notification Preferences (2026-08-29) ==================== */

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** Personal, current-session employee only -- same "no extra permission gate needed, already
     *  scoped to the session's own employee_id" reasoning as this whole controller's own docblock. */
    public function preferencesGet() {
        $employeeId = $this->employeeId();
        if (!$employeeId) {
            $this->json(['status' => false, 'message' => 'Not logged in.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->getEmployeePreferences($employeeId)]);
    }

    public function preferencesSave() {
        $employeeId = $this->employeeId();
        if (!$employeeId) {
            $this->json(['status' => false, 'message' => 'Not logged in.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input') ?: '{}', true);
        $preferences = (is_array($data) && isset($data['preferences']) && is_array($data['preferences'])) ? $data['preferences'] : [];
        $this->json($this->model->saveEmployeePreferences($employeeId, $preferences, $employeeId));
    }

    /** Admin role-default matrix -- same `rbac.*` permission gate as PermissionController's own
     *  matrix()/save() (this is a role-config concern for the same admin audience). 2026-09-03,
     *  Phase 3 Stage 3: swapped off the retired coarse `.manage`, parameterized so the read
     *  (roleMatrixGet) and write (roleMatrixSave) sides can check `rbac.view`/`rbac.edit`
     *  respectively. */
    private function requireRbacPermission(int $compId, string $permissionKey): bool {
        if ($this->isAdmin()) {
            return true;
        }
        $check = (new PermissionModel())->checkPermission($this->employeeId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to manage roles & permissions.']);
            return false;
        }
        return true;
    }

    public function roleMatrixGet() {
        $compId = (int)getCompId();
        if (!$compId || !$this->requireRbacPermission($compId, 'rbac.view')) return;
        $this->json(['status' => true, 'data' => $this->model->roleMatrix($compId)]);
    }

    public function roleMatrixSave() {
        $compId = (int)getCompId();
        if (!$compId || !$this->requireRbacPermission($compId, 'rbac.edit')) return;
        $data = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($data) || !isset($data['grants']) || !is_array($data['grants'])) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->saveRoleMatrix($compId, $data['grants'], $this->employeeId()));
    }
}
