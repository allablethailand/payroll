<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../services/IdCodec.php';

/**
 * ingest() receives Origami Payroll's attendance-derived cycle push (PAYROLL_SYNC_API.md).
 * Machine-to-machine only -- no session, authenticated via Authorization: Bearer
 * PAYROLL_SYNC_INGEST_API_KEY (excluded from ensure_login() in helpers.php, scoped to that one
 * route only). Kept thin on purpose: all validation/mapping/upsert logic lives in PayrollSyncModel
 * so it's unit-testable without simulating HTTP, matching this codebase's Controller/Model split
 * everywhere else.
 *
 * pendingList() is the opposite: a normal session-gated read for the logged-in Payroll Process
 * page's "Pending Pull" station, not part of the machine-to-machine contract.
 */
class PayrollSyncController extends Controller {
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

    /**
     * 2026-08-31, real pre-existing bug found and fixed while adding the blocked-updates feature
     * below: reject() (2026-08-31, "เพิ่มให้สามารถตีกลับเอกสาร...") already called
     * `$this->requirePermission('payroll_sync.reject')`, but this method never actually existed
     * anywhere -- not on this controller, not on the base Controller class (confirmed by reading
     * both files directly, not guessed) -- so every call to reject() has been a hard PHP fatal
     * error ("Call to undefined method") since the day it shipped. Added here, same body every
     * other controller's own private requirePermission() uses (SetupRulesController's own copy,
     * for one). Reused (not a new permission key) for the new blocked-update actions below too --
     * same "admin managing the Pending Origami Sync station" scope reject() itself already covers.
     */
    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    public function ingest(): void {
        $header = $this->bearerToken();
        if (PAYROLL_SYNC_INGEST_API_KEY === '' || $header === null || !hash_equals(PAYROLL_SYNC_INGEST_API_KEY, $header)) {
            http_response_code(401);
            $this->json(['status' => 'error', 'message' => 'Invalid or missing bearer token.']);
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            $this->json(['status' => 'error', 'message' => 'Invalid JSON body.']);
        }

        $model = new PayrollSyncModel();
        $result = $model->ingest($payload);

        if (!$result['status']) {
            http_response_code(422);
            $this->json(['status' => 'error', 'message' => $result['message']]);
        }

        http_response_code(200);
        $this->json([
            'status' => 'ok',
            'external_ref' => $result['process_row_id'],
            'unmapped_items' => $result['unmapped_items'],
        ]);
    }

    public function pendingList(): void {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $filters = [
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
        ];
        $model = new PayrollSyncModel();
        $this->json(['status' => true, 'data' => $model->pendingList((int)$compId, $filters)]);
    }

    /** 2026-08-31, explicit request: "เพิ่มให้สามารถตีกลับเอกสารที่ยังไม่ดึงมาทำรอบได้ โดยที่ต้องใส่ Comment
     *  เข้าไปด้วยครับ" -- see PayrollSyncModel::rejectProcess()'s own docblock. */
    public function reject(): void {
        if (!$this->requirePermission('payroll_sync.reject')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $comment = (string)($data['comment'] ?? '');
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $model = new PayrollSyncModel();
        $this->json($model->rejectProcess($id, (int)$compId, $comment, $userId));
    }

    public function pendingGet(): void {
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $model = new PayrollSyncModel();
        $detail = $model->getProcessDetail($id, (int)$compId);
        if (!$detail) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
        $this->json(['status' => true, 'data' => $detail]);
    }

    /* ==================== Blocked sync updates (2026-08-31) ====================
       PayrollSyncModel::ingest() now blocks (rather than silently applying) a re-push for a
       process already linked to a payroll run -- see that method's own docblock. These 3 actions
       are how an admin reviews and resolves what got blocked. */

    public function blockedUpdatesList(): void {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $model = new PayrollSyncModel();
        $rows = $model->blockedUpdatesList((int)$compId);
        // public_run_id: the IdCodec-encoded token the /payroll-process/{id} route expects (same
        // convention as PayrollController::list()'s own public_id) -- the raw linked_run_id itself
        // is not a valid URL segment.
        foreach ($rows as &$row) {
            $row['public_run_id'] = IdCodec::encode((int)$row['linked_run_id']);
        }
        unset($row);
        $this->json(['status' => true, 'data' => $rows]);
    }

    public function blockedUpdateApply(): void {
        if (!$this->requirePermission('payroll_sync.reject')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $model = new PayrollSyncModel();
        $this->json($model->applyBlockedUpdate($id, (int)$compId, $this->userId()));
    }

    public function blockedUpdateDismiss(): void {
        if (!$this->requirePermission('payroll_sync.reject')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $model = new PayrollSyncModel();
        $this->json($model->dismissBlockedUpdate($id, (int)$compId, $this->userId()));
    }

    private function bearerToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? null) : null);
        if ($header === null || stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        return trim(substr($header, 7));
    }
}
