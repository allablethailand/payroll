<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/OrgStructureSyncModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** Interactive "Sync from Origami" picker for Department/Position/Team -- see
 *  OrgStructureSyncModel's own docblock. Gated by the same `company_structure.manage` permission
 *  key CompanyProfileController already uses for every other write action on these 3 entities. */
class OrgStructureSyncController extends Controller {
    private OrgStructureSyncModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new OrgStructureSyncModel();
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

    private function entityTypeFromRequest(): string {
        return (string)($_POST['entity_type'] ?? $_GET['entity_type'] ?? '');
    }

    public function candidates() {
        if (!$this->requirePermission('company_structure.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json($this->model->candidates((int)$compId, $this->entityTypeFromRequest()));
    }

    public function apply() {
        if (!$this->requirePermission('company_structure.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $refIds = is_array($_POST['ref_ids'] ?? null) ? $_POST['ref_ids'] : [];
        $this->json($this->model->apply((int)$compId, $this->entityTypeFromRequest(), $refIds, $this->userId()));
    }

    public function log() {
        if (!$this->requirePermission('company_structure.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->log((int)$compId, $this->entityTypeFromRequest())]);
    }
}
