<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PermissionModel.php';

class PermissionController extends Controller {
    private PermissionModel $model;

    public function __construct() {
        $this->model = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->model->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    // 2026-08-31, explicit request: "สิทธิ์การใช้งาน...อยากให้แยกออกมาเป็นอีก Menu ไปเลย" -- was pill
    // p6 inside Company Profile's Organizational Structure tab, now its own standalone page. The
    // API actions below (matrix()/save()) are unchanged -- only this new page/route is added.
    public function index() {
        $this->view('setup/permissions');
    }

    public function matrix() {
        if (!$this->requirePermission('rbac.view')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->matrix((int)$compId)]);
    }

    public function save() {
        if (!$this->requirePermission('rbac.edit')) return;
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data) || !isset($data['grants']) || !is_array($data['grants'])) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->saveMatrix((int)$compId, $data['grants'], $this->userId()));
    }

    /**
     * 2026-09-03, Platform Hardening Phase 3 Stage 5 -- Employee Detail's "Permission Overrides"
     * tab. Gated by `rbac.*` (the SAME permission that gates the Permission Matrix itself), NOT
     * `employee.view`/`.edit` -- deliberate: this feature controls per-user overrides of EVERY
     * permission in the system, so gating it behind ordinary employee-record edit rights would let
     * any employee-editing user grant themselves (or anyone else) escalated access through the
     * override mechanism. Only someone who can already manage the Permission Matrix can touch this.
     */
    public function employeeOverridesGet() {
        if (!$this->requirePermission('rbac.view')) return;
        $compId = getCompId();
        $employeeId = (int)($_GET['employee_id'] ?? 0);
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing employee_id.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->employeeOverrides((int)$compId, $employeeId)]);
    }

    public function employeeOverridesSave() {
        if (!$this->requirePermission('rbac.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $employeeId = (int)($data['employee_id'] ?? 0);
        $overrides = is_array($data['overrides'] ?? null) ? $data['overrides'] : [];
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing employee_id.']);
            return;
        }
        $this->json($this->model->saveEmployeeOverrides((int)$compId, $employeeId, $overrides, $this->userId()));
    }
}
