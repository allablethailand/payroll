<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/NonResidentTaxSettingModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** 2026-09-02 -- surfaced in Tax & Statutory settings as its own tab, reuses that page's existing
 *  `tax_statutory.view`/`.edit` keys rather than seeding a new permission -- same precedent as
 *  StatutoryFormatVersionController's own docblock. 2026-09-03, Phase 3 Stage 3: swapped off the
 *  retired coarse `.manage`. */
class NonResidentTaxSettingController extends Controller {
    private NonResidentTaxSettingModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new NonResidentTaxSettingModel();
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

    public function get() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->get((int)$compId)]);
    }

    public function save() {
        if (!$this->requirePermission('tax_statutory.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->save((int)$compId, $data, $this->userId()));
    }
}
