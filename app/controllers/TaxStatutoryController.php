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

    /** 2026-08-28, explicit request: "แสดงผลเฉพาะตามประเทศที่ตัวเองตั้งค่า" (show only according to the
     *  company's own configured country) -- statutory_items/statutory_item_rate_history are GLOBAL
     *  tables with no comp_id column at all (shared master data across every company on this
     *  platform, confirmed by reading the schema directly), so this Master Rates tab previously had
     *  NO country scoping whatsoever: #filter_country_code defaulted to blank, and
     *  TaxStatutoryModel::list('') skips its own country filter entirely -- every company saw all
     *  4 seeded countries' items mixed together (confirmed live: TH:3, SG:1, MY:3, US:3 rows).
     *  Resolved here, server-side, rather than only defaulting the UI filter -- itemList()/
     *  itemSave() below now always use THIS, ignoring whatever a request claims, so the scoping
     *  can't be bypassed by editing the request. */
    private function companyCountry(int $compId): ?string {
        $stmt = Database::getInstance()->pdo->prepare("SELECT registered_country FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $country = $stmt->fetchColumn();
        return ($country === false || $country === null || $country === '') ? null : (string)$country;
    }

    public function index() {
        $this->view('setup/tax-statutory');
    }

    public function itemList() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $compId = (int)getCompId();
        $countryCode = $this->companyCountry($compId);
        if ($countryCode === null) {
            $this->json(['status' => true, 'data' => [], 'message' => 'This company has no registered country set yet. Set it in Company Profile first.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list($countryCode)]);
    }

    public function itemGet() {
        if (!$this->requirePermission('tax_statutory.view')) return;
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
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // TaxStatutoryModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'tax_statutory.edit' : 'tax_statutory.add')) return;
        // Country is never taken from the request -- always the acting company's own, so a new/
        // edited item can never end up under a different country than the one this whole page is
        // now locked to (see companyCountry()'s own docblock).
        $compId = (int)getCompId();
        $countryCode = $this->companyCountry($compId);
        if ($countryCode === null) {
            $this->json(['status' => false, 'message' => 'This company has no registered country set yet. Set it in Company Profile first.']);
            return;
        }
        $data['country_code'] = $countryCode;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->save($data, $userId);
        $this->json($result);
    }

    public function itemDelete() {
        if (!$this->requirePermission('tax_statutory.delete')) return;
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

    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch, same shape as
    // itemDelete() just above.
    public function itemToggleStatus() {
        if (!$this->requirePermission('tax_statutory.edit')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->toggleStatus($id, $userId);
        $this->json($result);
    }

    public function rateHistoryList() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
        if ($itemId <= 0) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->rateHistoryList($itemId)]);
    }

    public function rateHistoryGet() {
        if (!$this->requirePermission('tax_statutory.view')) return;
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
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // TaxStatutoryModel::rateHistorySave() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'tax_statutory.edit' : 'tax_statutory.add')) return;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->rateHistorySave($data, $userId);
        $this->json($result);
    }

    public function rateVersionPreview() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $sampleBase = isset($data['sample_base_amount']) && is_numeric($data['sample_base_amount']) ? (float)$data['sample_base_amount'] : 30000.0;
        $this->json($this->model->previewRateVersion($data, $sampleBase));
    }

    public function rateHistoryDelete() {
        if (!$this->requirePermission('tax_statutory.delete')) return;
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
        if (!$this->requirePermission('tax_statutory.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->companySettingModel->list((int)$compId)]);
    }

    public function companySettingGet() {
        if (!$this->requirePermission('tax_statutory.view')) return;
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
        if (!$this->requirePermission('tax_statutory.edit')) return;
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
        if (!$this->requirePermission('tax_statutory.edit')) return;
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

    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch, keyed by
    // statutory_item_id (a company may not have a settings row for this item yet at all, see
    // CompanyStatutorySettingModel::toggleStatus()'s own docblock). Reads `id` (not
    // `statutory_item_id`, unlike companySettingReset() above) because the shared frontend switch
    // (app.js's renderStatusToggleHtml()/status-toggle-switch handler) ALWAYS posts `{id: ...}` --
    // it's a generic component with a fixed payload shape, not something this one endpoint can
    // customize -- the table's own render call passes `row.statutory_item_id` as that `id` value.
    public function companySettingToggleStatus() {
        if (!$this->requirePermission('tax_statutory.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $itemId = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($itemId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid statutory_item_id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->companySettingModel->toggleStatus((int)$compId, $itemId, $userId);
        $this->json($result);
    }
}
