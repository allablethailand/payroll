<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/TaxStatutoryModel.php';
require_once __DIR__ . '/../models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../models/CompanyStatutoryRateVersionModel.php';
require_once __DIR__ . '/../models/PvdEmployerRateLadderModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
class TaxStatutoryController extends Controller {
    private $model;
    private $companySettingModel;
    private $companyRateVersionModel;
    private PvdEmployerRateLadderModel $pvdLadderModel;
    private PermissionModel $permissionModel;
    public function __construct(){
        $this->model = new TaxStatutoryModel();
        $this->companySettingModel = new CompanyStatutorySettingModel();
        $this->companyRateVersionModel = new CompanyStatutoryRateVersionModel();
        $this->pvdLadderModel = new PvdEmployerRateLadderModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** Platform Hardening Phase 6 (batch 2): same capture pattern BankAccountController::
     *  requestFingerprint() already established, for AuditLogModel::record(). */
    private function requestFingerprint(): array {
        return [
            (string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null,
        ];
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

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- resolves a `statutory_item_id`'s own Master/Clone
     * ownership (`comp_id` -- NULL for a master item, a company id for a custom item) so the shared
     * rate-history endpoints below (rateHistoryList/Get/Save/Delete) can branch their permission
     * check correctly: a MASTER item's rate history needs `tax_statutory.promote_master` (it's
     * platform-wide data), a company's OWN custom item's rate history needs only the ordinary
     * `tax_statutory.add`/`.edit`/`.delete` that company already has, and neither should let a
     * caller touch another company's custom item at all. Returns null if the item doesn't exist.
     */
    private function itemOwnerCompId(int $itemId): ?array {
        $stmt = Database::getInstance()->pdo->prepare("SELECT comp_id FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['comp_id' => $row['comp_id'] !== null ? (int)$row['comp_id'] : null];
    }

    /**
     * Permission gate for an action that ultimately touches `statutory_item_id`'s own rate history
     * (or the item itself) -- `$masterPermission` when the item is master-scoped (comp_id IS NULL,
     * platform-wide impact), `$ownPermission` when it's this company's own custom item, and always
     * refused for another company's custom item (never confirms whether that item exists at all,
     * same "not found" framing as every other cross-tenant ownership check in this app).
     */
    private function requireItemScopedPermission(int $itemId, string $masterPermission, string $ownPermission): bool {
        $owner = $this->itemOwnerCompId($itemId);
        if ($owner === null) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return false;
        }
        $compId = (int)getCompId();
        if ($owner['comp_id'] === null) {
            return $this->requirePermission($masterPermission);
        }
        if ($owner['comp_id'] !== $compId) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return false;
        }
        return $this->requirePermission($ownPermission);
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

    // 2026-09-03, Backlog Phase 9, T045 -- gated by `tax_statutory.promote_master` now (was
    // `tax_statutory.view`, same as every other action on this MASTER-scoped endpoint before this
    // permission existed) -- master item definitions are platform-wide data, same reasoning as
    // itemSave()/itemDelete()/itemToggleStatus() below. Also now filters `comp_id IS NULL`
    // explicitly so this can never accidentally return/leak another company's custom item's
    // definition through a guessed id. See customItemGet() below for the company-owned equivalent.
    public function itemGet() {
        if (!$this->requirePermission('tax_statutory.promote_master')) return;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get($id);
        if ($row && $row['comp_id'] === null) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    // 2026-09-03, Backlog Phase 9, T045 -- gated by `tax_statutory.promote_master` now, NOT the
    // ordinary `tax_statutory.edit`/`.add` every company admin already has -- this is exactly the
    // gap T044 removed the whole "Master Rates" tab to close (any company admin could otherwise
    // directly mutate `statutory_items`, a GLOBAL table with no comp_id scoping at all, silently
    // changing what EVERY OTHER company on the platform sees). No UI calls this anymore since T044;
    // it remains reachable only for whatever eventual superadmin master-management screen T045's
    // own design note calls for -- see this app's own permission matrix, `tax_statutory.promote_
    // master` is granted to nobody by default. See customItemSave() below for the company-scoped
    // equivalent every ordinary company admin actually uses now.
    public function itemSave() {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        if (!$this->requirePermission('tax_statutory.promote_master')) return;
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
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->save($data, $userId, null, $ip, $ua);
        $this->json($result);
    }

    // 2026-09-03, Backlog Phase 9, T045 -- see itemSave()'s own comment on why this is now
    // `tax_statutory.promote_master`-gated.
    public function itemDelete() {
        if (!$this->requirePermission('tax_statutory.promote_master')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->delete($id, $userId, null, $ip, $ua);
        $this->json($result);
    }

    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch, same shape as
    // itemDelete() just above.
    // 2026-09-03, Backlog Phase 9, T045 -- see itemSave()'s own comment on why this is now
    // `tax_statutory.promote_master`-gated.
    public function itemToggleStatus() {
        if (!$this->requirePermission('tax_statutory.promote_master')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->toggleStatus($id, $userId, null, $ip, $ua);
        $this->json($result);
    }

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- company-scoped equivalent of itemGet()/itemSave()/
     * itemDelete()/itemToggleStatus() above, for a company's own CUSTOM statutory item (comp_id =
     * the caller's own). Uses the SAME ordinary `tax_statutory.view`/`.add`/`.edit`/`.delete`
     * permissions every company admin already has -- safe, because TaxStatutoryModel::save()/
     * delete()/toggleStatus() all enforce the comp_id ownership scope themselves now (see each
     * method's own docblock), so this can never reach a master item or another company's item no
     * matter what id is passed.
     */
    public function customItemGet() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $compId = (int)getCompId();
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get($id);
        if ($row && $row['comp_id'] !== null && (int)$row['comp_id'] === $compId) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function customItemSave() {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'tax_statutory.edit' : 'tax_statutory.add')) return;
        $compId = (int)getCompId();
        $countryCode = $this->companyCountry($compId);
        if ($countryCode === null) {
            $this->json(['status' => false, 'message' => 'This company has no registered country set yet. Set it in Company Profile first.']);
            return;
        }
        $data['country_code'] = $countryCode;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->save($data, $userId, $compId, $ip, $ua);
        $this->json($result);
    }

    public function customItemDelete() {
        if (!$this->requirePermission('tax_statutory.delete')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $compId = (int)getCompId();
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->delete($id, $userId, $compId, $ip, $ua);
        $this->json($result);
    }

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- "Update as system default" for a company's own CUSTOM
     * item -- see TaxStatutoryModel::promoteToMaster()'s own docblock. `tax_statutory.promote_
     * master`-gated deliberately, NOT the ordinary `tax_statutory.add`/`.edit` this same company
     * already has for its own custom items -- this specific action stops being company-local the
     * instant it succeeds (the item becomes visible to every other company in the same country).
     */
    public function customItemPromote() {
        if (!$this->requirePermission('tax_statutory.promote_master')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $compId = (int)getCompId();
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->promoteToMaster($id, $compId, $userId);
        $this->json($result);
    }

    public function rateHistoryList() {
        $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
        if ($itemId <= 0) {
            if (!$this->requirePermission('tax_statutory.view')) return;
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        if (!$this->requireItemScopedPermission($itemId, 'tax_statutory.promote_master', 'tax_statutory.view')) return;
        $this->json(['status' => true, 'data' => $this->model->rateHistoryList($itemId)]);
    }

    public function rateHistoryGet() {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->rateHistoryGet($id);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        if (!$this->requireItemScopedPermission((int)$row['statutory_item_id'], 'tax_statutory.promote_master', 'tax_statutory.view')) return;
        $this->json(['status' => true, 'data' => $row]);
    }

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- gated per-item now via requireItemScopedPermission()
     * instead of a blanket `tax_statutory.edit`/`.add` -- a rate-history row's own
     * `statutory_item_id` could belong to a MASTER item (needs `tax_statutory.promote_master`,
     * platform-wide impact) or this company's OWN custom item (needs only the ordinary `tax_
     * statutory.add`/`.edit` every company admin already has) -- see that helper's own docblock.
     * This is what actually lets a company set/maintain rate history for its own custom items
     * (T045's whole point) without reopening the master-mutation hole T044 closed.
     */
    public function rateHistorySave() {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $itemId = isset($data['statutory_item_id']) ? (int)$data['statutory_item_id'] : 0;
        if ($itemId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing required field: statutory_item_id']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // TaxStatutoryModel::rateHistorySave() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requireItemScopedPermission($itemId, 'tax_statutory.promote_master', $isEdit ? 'tax_statutory.edit' : 'tax_statutory.add')) return;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->rateHistorySave($data, $userId, $ip, $ua);
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

    // 2026-09-03, Backlog Phase 9, T045 -- see rateHistorySave()'s own comment on why this is
    // per-item scoped now via requireItemScopedPermission() instead of a blanket
    // `tax_statutory.delete`.
    public function rateHistoryDelete() {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $rateRow = $this->model->rateHistoryGet($id);
        if (!$rateRow) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        if (!$this->requireItemScopedPermission((int)$rateRow['statutory_item_id'], 'tax_statutory.promote_master', 'tax_statutory.delete')) return;
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->model->rateHistoryDelete($id, $userId, $ip, $ua);
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

    /** Batch 3A item 7a: PVD employer contribution ladder ("อายุงานตั้งแต่ (ปี) -> % นายจ้าง"). */
    public function pvdEmployerLadderList() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->pvdLadderModel->list((int)$compId)]);
    }

    public function pvdEmployerLadderSave() {
        if (!$this->requirePermission('tax_statutory.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $rows = (is_array($data) && isset($data['rows']) && is_array($data['rows'])) ? $data['rows'] : [];
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Invalid company.']);
            return;
        }
        // Empty rows = "clear the ladder" (opt back out, fall back to the flat company/master rate)
        // -- a distinct, valid action from "save an invalid/empty tier set", so it bypasses save()'s
        // own "at least one tier is required" validation entirely.
        if (empty($rows)) {
            $this->json($this->pvdLadderModel->clear((int)$compId, $this->userId()));
            return;
        }
        $this->json($this->pvdLadderModel->save((int)$compId, $rows, $this->userId()));
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

    // 2026-09-08, Clone+Version redesign -- replaces the old companySettingSave/Reset (flat
    // override) endpoints. Each company's own rate is now a real dated version list, one row per
    // version, sharing the exact same `statutory_item_rate_history` table Master's own dated
    // versions live on -- see CompanyStatutoryRateVersionModel's own docblock.
    public function companyRateVersionList() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $compId = getCompId();
        $itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
        if (!$compId || $itemId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing item_id.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->companyRateVersionModel->list((int)$compId, $itemId)]);
    }

    public function companyRateVersionGet() {
        if (!$this->requirePermission('tax_statutory.view')) return;
        $compId = getCompId();
        $versionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $versionId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->companyRateVersionModel->get((int)$compId, $versionId);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function companyRateVersionSave() {
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
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->companyRateVersionModel->save((int)$compId, $data, $userId, $ip, $ua);
        $this->json($result);
    }

    public function companyRateVersionDelete() {
        if (!$this->requirePermission('tax_statutory.delete')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $versionId = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($versionId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->companyRateVersionModel->delete((int)$compId, $versionId, $userId, $ip, $ua);
        $this->json($result);
    }

    // "ถ้าอยากจะดึง Master ก็สามารถดึงได้ทุกเมื่อที่ต้องการกลับมาใช้" -- pulls Master's own currently-
    // effective version in as a brand-new version of this company's own (source='master_clone').
    public function companyRateVersionPull() {
        if (!$this->requirePermission('tax_statutory.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $itemId = (is_array($data) && isset($data['statutory_item_id'])) ? (int)$data['statutory_item_id'] : 0;
        $effectiveDate = (is_array($data) && !empty($data['effective_date'])) ? (string)$data['effective_date'] : '';
        if ($itemId <= 0 || $effectiveDate === '') {
            $this->json(['status' => false, 'message' => 'Missing statutory_item_id or effective_date.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->companyRateVersionModel->pullFromMaster((int)$compId, $itemId, $effectiveDate, $userId, $ip, $ua);
        $this->json($result);
    }

    /**
     * 2026-09-08, Clone+Version redesign -- replaces the old companySettingPromote() (flat
     * override -> new master version). Now promotes ONE of this company's own explicit versions
     * (by id, not "whatever the current override is") -- see CompanyStatutoryRateVersionModel::
     * promoteToMaster()'s own docblock. Still `tax_statutory.promote_master`-gated, same reasoning
     * as customItemPromote()/the old companySettingPromote(): affects every company at once.
     */
    public function companyRateVersionPromote() {
        if (!$this->requirePermission('tax_statutory.promote_master')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $versionId = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $effectiveDate = (is_array($data) && !empty($data['effective_date'])) ? (string)$data['effective_date'] : '';
        if ($versionId <= 0 || $effectiveDate === '') {
            $this->json(['status' => false, 'message' => 'Missing id or effective_date.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->companyRateVersionModel->promoteToMaster((int)$compId, $versionId, $effectiveDate, $userId);
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
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->companySettingModel->toggleStatus((int)$compId, $itemId, $userId, $ip, $ua);
        $this->json($result);
    }

}
