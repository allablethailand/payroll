<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/HolidaySyncModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** Interactive "Sync Holidays from Google Calendar" picker -- see HolidaySyncModel's own docblock
 *  for the full design. Gated by the same `holiday.view`/`.add` permission keys SetupRulesController
 *  already uses for reads/writes on Holiday (this ultimately inserts real holidays rows).
 *  2026-09-03, Phase 3 Stage 3: swapped off the retired coarse `.manage`. */
class HolidaySyncController extends Controller {
    private HolidaySyncModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new HolidaySyncModel();
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

    private function requestedYear(): int {
        $year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
        // Sane bounds -- a payroll admin realistically only ever needs this year or the next one or
        // two while planning ahead, not an arbitrary year.
        return max(2020, min(2100, $year));
    }

    public function candidates() {
        if (!$this->requirePermission('holiday.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json($this->model->candidates((int)$compId, $this->requestedYear()));
    }

    public function apply() {
        if (!$this->requirePermission('holiday.add')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $dates = is_array($_POST['dates'] ?? null) ? $_POST['dates'] : [];
        $this->json($this->model->apply((int)$compId, $this->requestedYear(), $dates, $this->userId()));
    }

    public function log() {
        if (!$this->requirePermission('holiday.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->log((int)$compId)]);
    }
}
