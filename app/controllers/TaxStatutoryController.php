<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/TaxStatutoryModel.php';
require_once __DIR__ . '/../models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
class TaxStatutoryController extends Controller {
    private $model;
    private $companySettingModel;
    private PermissionModel $permissionModel;
    public function __construct(){
        $this->model = new TaxStatutoryModel();
        $this->companySettingModel = new CompanyStatutorySettingModel();
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

    public function index() {
        $this->view('setup/tax-statutory');
    }

    public function itemList() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $countryCode = (string)($_GET['country_code'] ?? '');
        $this->json(['status' => true, 'data' => $this->model->list($countryCode)]);
    }

    public function itemGet() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get($id);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function itemSave() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->save($data, $userId);
        $this->json($result);
    }

    public function itemDelete() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->delete($id, $userId);
        $this->json($result);
    }

    public function rateHistoryList() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
        if ($itemId <= 0) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->rateHistoryList($itemId)]);
    }

    public function rateHistoryGet() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->rateHistoryGet($id);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function rateHistorySave() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->rateHistorySave($data, $userId);
        $this->json($result);
    }

    public function rateHistoryDelete() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->rateHistoryDelete($id, $userId);
        $this->json($result);
    }

    public function companySettingList() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->companySettingModel->list((int)$compId)]);
    }

    public function companySettingGet() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $compId = getCompId();
        $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
        if (!$compId || $itemId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing item_id.']);
            return;
        }
        $row = $this->companySettingModel->get((int)$compId, $itemId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function companySettingSave() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
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
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->companySettingModel->save((int)$compId, $data, $userId);
        $this->json($result);
    }

    public function companySettingReset() {
        if (!$this->requirePermission('tax_statutory.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $itemId = (is_array($data) && isset($data['statutory_item_id'])) ? (int)$data['statutory_item_id'] : 0;
        if ($itemId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid statutory_item_id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->companySettingModel->reset((int)$compId, $itemId, $userId);
        $this->json($result);
    }
}
