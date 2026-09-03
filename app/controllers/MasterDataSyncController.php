<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../models/SyncBatchModel.php';
require_once __DIR__ . '/../services/sync/MasterDataSyncOrchestrator.php';
require_once __DIR__ . '/../services/sync/MasterDataSyncRegistry.php';
require_once __DIR__ . '/../services/sync/OrigamiSyncClient.php';

/**
 * 2026-09-02, "Data Sync" page -- the UI trigger for MasterDataSyncOrchestrator, which has been
 * fully built and tested since 2026-07-24 (department/position/shift/branch/team/holiday) but
 * never had a browser-reachable entry point (same "Not started: Data Sync UI" gap this whole
 * engine has always had, see project_origami_hr_sync_status memory) -- OrigamiSyncClient itself
 * was ALSO a pure stub until this same day, so before now this page would have had nothing real
 * to call anyway.
 *
 * `employee` is DELIBERATELY EXCLUDED from the per-type sync buttons this page renders -- its own
 * bulk fetch (OrigamiSyncClient::fetchEmployees()) remains an unimplemented stub on purpose (no
 * real Origami endpoint for bulk employee pull exists, only the interactive, filtered
 * candidates.php picker does -- see OrigamiSyncClient's own docblock). The real employee sync
 * entry point is the Employee List page's own "Sync Employee from Origami" picker
 * (EmployeeSyncModel), unrelated to this page. "Sync All" (syncAllMasterData()) still technically
 * attempts employee internally (it iterates the WHOLE registry) and will report a clean per-type
 * failure for it in the results -- shown as-is in the history table, not hidden, since that's
 * genuinely accurate (not implemented yet) rather than a bug to mask.
 *
 * Permission: reuses `company_structure.view`/`.edit` (same keys Department/Position/Branch/Team/Rank
 * CRUD in Company Profile's Organizational Structure tab already use) -- no new permission key,
 * since this page manages the same underlying entities via a different data source. 2026-09-03,
 * Platform Hardening Phase 3 Stage 3: swapped from the old coarse `.manage` onto `.edit` (both
 * syncOne()/syncAll() upsert existing rows, no real "add-only" concept here).
 */
class MasterDataSyncController extends Controller {
    private PermissionModel $permissionModel;

    public function __construct() {
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

    /** Entity types shown as individual "Sync Now" cards -- deliberately excludes 'employee', see
     *  this class's own top docblock. Order matches MasterDataSyncRegistry's dependency order. */
    private const UI_ENTITY_TYPES = ['department', 'position', 'shift', 'branch', 'team', 'holiday'];

    public function index() {
        $this->view('setup/data-sync');
    }

    /** @return array{status:bool, linked:bool, configured:bool, last_sync_at: array<string,array{last_sync_at:string,triggered_by:?int,triggered_by_name_th:?string,triggered_by_surname_th:?string,triggered_by_name_en:?string,triggered_by_surname_en:?string}>} */
    public function status() {
        $compId = (int)getCompId();
        $pdo = Database::getInstance()->pdo;
        $refId = $pdo->prepare("SELECT ref_id FROM companies WHERE id = :id");
        $refId->execute([':id' => $compId]);
        $linked = $refId->fetchColumn();
        $batchModel = new SyncBatchModel($pdo);
        $this->json([
            'status' => true,
            'linked' => !empty($linked),
            'configured' => OrigamiSyncClient::isConfigured(),
            'entity_types' => self::UI_ENTITY_TYPES,
            'last_sync_at' => $batchModel->lastSyncTimes($compId),
        ]);
    }

    public function syncOne() {
        if (!$this->requirePermission('company_structure.edit')) return;
        $entityType = (string)($_POST['entity_type'] ?? '');
        if (!in_array($entityType, self::UI_ENTITY_TYPES, true)) {
            $this->json(['status' => false, 'message' => 'Unknown entity type.']);
            return;
        }
        $compId = (int)getCompId();
        $orch = new MasterDataSyncOrchestrator();
        $result = $orch->syncEntity($compId, $entityType, $this->userId());
        $this->json($result);
    }

    public function syncAll() {
        if (!$this->requirePermission('company_structure.edit')) return;
        $compId = (int)getCompId();
        $orch = new MasterDataSyncOrchestrator();
        $results = $orch->syncAllMasterData($compId, $this->userId());
        $this->json(['status' => true, 'results' => $results]);
    }

    /** Client-side DataTable (SyncBatchModel::list() is already bounded to LIMIT 200, no
     *  server-side pagination needed -- same convention as this app's own Table docs).
     *  2026-09-02, redesign into Sync/History tabs, explicit request: "ในประวัติให้มี Filter ด้วย" --
     *  status/date_from/date_to added alongside the pre-existing entity_type filter. */
    public function history() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = (int)getCompId();
        $filters = ['source' => 'sync'];
        foreach (['entity_type', 'status', 'date_from', 'date_to'] as $key) {
            if (!empty($_POST[$key])) {
                $filters[$key] = (string)$_POST[$key];
            }
        }
        $rows = (new SyncBatchModel())->list($compId, $filters);
        $this->json(['status' => true, 'data' => $rows]);
    }
}
