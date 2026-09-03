<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmployeeSyncModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** Interactive "Sync Employee from Origami" picker -- see EmployeeSyncModel's own docblock for the
 *  full design. Gated by the same `employee.*` permission keys EmployeeController already uses
 *  for every other action on this entity (this feature ultimately inserts/updates real employees
 *  rows), no new permission key introduced. 2026-09-03, Phase 3 Stage 3: swapped off the retired
 *  coarse `.manage` onto view/add/edit per action. */
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
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json($this->model->filterOptions((int)$compId));
    }

    public function candidates() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json($this->model->candidates((int)$compId, $this->filtersFromRequest()));
    }

    public function apply() {
        if (!$this->requirePermission('employee.add')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $refIds = is_array($_POST['ref_ids'] ?? null) ? $_POST['ref_ids'] : [];
        $this->json($this->model->apply((int)$compId, $this->filtersFromRequest(), $refIds, $this->userId()));
    }

    /** 2026-08-28, explicit request: per-employee "Re-Sync from Origami" button on Employee Detail. */
    public function resyncOne() {
        if (!$this->requirePermission('employee.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing employee_id.']);
            return;
        }
        $this->json($this->model->resyncOne((int)$compId, $employeeId, $this->userId()));
    }

    /** 2026-08-29, explicit request: checkbox multi-select + bulk "Sync Selected" on Employee List. */
    public function resyncMany() {
        if (!$this->requirePermission('employee.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $employeeIds = is_array($_POST['employee_ids'] ?? null) ? $_POST['employee_ids'] : [];
        $this->json($this->model->resyncMany((int)$compId, $employeeIds, $this->userId()));
    }

    /** 2026-08-28, same request -- "last synced" summary shown on Employee Detail. */
    public function lastSyncSummary() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        $employeeId = (int)($_GET['employee_id'] ?? 0);
        if (!$compId || $employeeId <= 0) {
            $this->json(['status' => true, 'data' => null]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->lastSyncSummary((int)$compId, $employeeId)]);
    }

    public function log() {
        if (!$this->requirePermission('employee.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->log((int)$compId)]);
    }
}
