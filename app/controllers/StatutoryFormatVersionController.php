<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/StatutoryFormatVersionModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** 2026-08-29 -- surfaced in Tax & Statutory settings, reuses that page's existing single
 *  permission key (`tax_statutory.manage`, gates every action on that page including reads --
 *  see TaxStatutoryController for the same pattern) rather than seeding a new permission. */
class StatutoryFormatVersionController extends Controller {
    private StatutoryFormatVersionModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new StatutoryFormatVersionModel();
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

    public function settings() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->settingsForCompany((int)$compId)]);
    }

    public function save() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
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
        $formCode = (string)($data['form_code'] ?? '');
        $versionId = (int)($data['version_id'] ?? 0);
        if ($formCode === '' || $versionId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing form_code or version_id.']);
            return;
        }
        $this->json($this->model->saveSelection((int)$compId, $formCode, $versionId, $this->userId() ?: null));
    }
}
