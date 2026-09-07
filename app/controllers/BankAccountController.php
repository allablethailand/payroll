<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/BankAccountModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
class BankAccountController extends Controller {
    private $model;
    private PermissionModel $permissionModel;
    public function __construct() {
        $this->model = new BankAccountModel();
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

    /** Platform Hardening Phase 6 (batch 2): same capture pattern ManualEntryController::
     *  requestFingerprint() already established, for AuditLogModel::record(). */
    private function requestFingerprint(): array {
        return [
            (string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null,
        ];
    }

    public function list() {
        if (!$this->requirePermission('bank_account.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? (string)$request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? (string)$request['order'][0]['dir'] : 'asc';
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($request['column_filters'] ?? null) ? $request['column_filters'] : [];
        $result = $this->model->list((int)$compId, $start, $length, $search, $colIndex, $orderDir, (string)$lang, $columnFilters);
        foreach ($result['data'] as &$row) {
            $row['is_default'] = isset($row['is_default']) ? (bool)$row['is_default'] : false;
        }
        $this->json($result);
    }
    /** 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout. */
    public function columnValues() {
        if (!$this->requirePermission('bank_account.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'values' => []]);
            return;
        }
        $column = (string)($_POST['column'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $values = $this->model->columnDistinctValues((int)$compId, $column, (string)$lang, $columnFilters, $column);
        $this->json(['status' => true, 'values' => $values]);
    }
    public function save() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // BankAccountModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'bank_account.edit' : 'bank_account.add')) return;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->save((int)$compId, $data, $userId, $ip, $ua);
        $this->json($result);
    }
    public function delete() {
        if (!$this->requirePermission('bank_account.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->delete((int)$compId, $id, $userId, $ip, $ua);
        $this->json($result);
    }
    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch, same shape as
    // every other converted table's own toggle-status dispatcher.
    public function toggleStatus() {
        if (!$this->requirePermission('bank_account.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->toggleStatus((int)$compId, $id, $userId, $ip, $ua);
        $this->json($result);
    }
}
