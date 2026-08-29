<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/BankFileFormatModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/**
 * 2026-08-29 -- reuses the existing `bank_account.manage` permission (this feature lives as a
 * sub-tab of the same Bank Accounts settings page, see company-profile.js/BankAccountController's
 * own gate) rather than seeding a brand-new permission key for what's functionally part of the
 * same settings area.
 */
class BankFileFormatController extends Controller {
    private BankFileFormatModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new BankFileFormatModel();
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

    private function compId(): ?int {
        $compId = getCompId();
        return $compId ? (int)$compId : null;
    }

    public function list() {
        if (!$this->requirePermission('bank_account.manage')) return;
        $compId = $this->compId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => [], 'default_format_id' => null]);
            return;
        }
        $this->json([
            'status' => true,
            'data' => $this->model->listFormats($compId),
            'default_format_id' => $this->model->defaultFormatId($compId),
        ]);
    }

    public function get() {
        if (!$this->requirePermission('bank_account.manage')) return;
        $compId = $this->compId();
        $formatId = (int)($_GET['bank_file_format_id'] ?? $_POST['bank_file_format_id'] ?? 0);
        if (!$compId || $formatId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing bank_file_format_id.']);
            return;
        }
        $detail = $this->model->getFormatDetail($compId, $formatId);
        if ($detail === null) {
            $this->json(['status' => false, 'message' => 'Bank file format not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $detail]);
    }

    public function saveConfig() {
        if (!$this->requirePermission('bank_account.manage')) return;
        $compId = $this->compId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $formatId = (int)($data['bank_file_format_id'] ?? 0);
        if ($formatId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing bank_file_format_id.']);
            return;
        }
        $this->json($this->model->saveConfig($compId, $formatId, $data, $this->userId() ?: null));
    }

    public function saveField() {
        if (!$this->requirePermission('bank_account.manage')) return;
        $compId = $this->compId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $formatId = (int)($data['bank_file_format_id'] ?? 0);
        if ($formatId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing bank_file_format_id.']);
            return;
        }
        $this->json($this->model->saveField($compId, $formatId, $data, $this->userId() ?: null));
    }

    public function deleteField() {
        if (!$this->requirePermission('bank_account.manage')) return;
        $compId = $this->compId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        $formatId = is_array($data) ? (int)($data['bank_file_format_id'] ?? 0) : 0;
        $fieldId = is_array($data) ? (int)($data['id'] ?? 0) : 0;
        if ($formatId <= 0 || $fieldId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing bank_file_format_id or id.']);
            return;
        }
        $this->json($this->model->deleteField($compId, $formatId, $fieldId, $this->userId() ?: null));
    }

    public function resetToDefault() {
        if (!$this->requirePermission('bank_account.manage')) return;
        $compId = $this->compId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        $formatId = is_array($data) ? (int)($data['bank_file_format_id'] ?? 0) : 0;
        if ($formatId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing bank_file_format_id.']);
            return;
        }
        $this->json($this->model->resetToDefault($compId, $formatId, $this->userId() ?: null));
    }

    public function editLogs() {
        if (!$this->requirePermission('bank_account.manage')) return;
        $compId = $this->compId();
        $formatId = (int)($_GET['bank_file_format_id'] ?? 0);
        if (!$compId || $formatId <= 0) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->editLogs($compId, $formatId)]);
    }
}
