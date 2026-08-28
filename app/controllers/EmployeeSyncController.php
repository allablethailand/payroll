<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmployeeSyncModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** Interactive "Sync Employee from Origami" picker -- see EmployeeSyncModel's own docblock for the
 *  full design. Gated by the same `employee.manage` permission key EmployeeController already uses
 *  for every other write action on this entity (this feature ultimately inserts/updates real
 *  employees rows), no new permission key introduced. */
class EmployeeSyncController extends Controller {
    private EmployeeSyncModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new EmployeeSyncModel();
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

    private function filtersFromRequest(): array {
        return [
            'department_ref_id' => !empty($_POST['department_ref_id']) ? (int)$_POST['department_ref_id'] : null,
            'position_ref_id' => !empty($_POST['position_ref_id']) ? (int)$_POST['position_ref_id'] : null,
            'team_ref_id' => !empty($_POST['team_ref_id']) ? (int)$_POST['team_ref_id'] : null,
            'type' => !empty($_POST['type']) ? (string)$_POST['type'] : null,
        ];
    }

    /** Department/Position/Team/Type option lists for the picker's own filter dropdowns, sourced
     *  from Origami (mocked) rather than Payroll's own local structure tables -- see
     *  EmployeeSyncModel::filterOptions()'s own docblock. */
    public function filterOptions() {
        if (!$this->requirePermission('employee.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json($this->model->filterOptions((int)$compId));
    }

    public function candidates() {
        if (!$this->requirePermission('employee.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json($this->model->candidates((int)$compId, $this->filtersFromRequest()));
    }

    public function apply() {
        if (!$this->requirePermission('employee.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $refIds = is_array($_POST['ref_ids'] ?? null) ? $_POST['ref_ids'] : [];
        $this->json($this->model->apply((int)$compId, $this->filtersFromRequest(), $refIds, $this->userId()));
    }

    public function log() {
        if (!$this->requirePermission('employee.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->log((int)$compId)]);
    }
}
