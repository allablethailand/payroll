<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/AuditLogModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/**
 * Platform Hardening Phase 6 (pilot): viewer for AuditLogModel's field-level change log --
 * read-only, no write actions here (writes happen inside the 5 pilot models themselves via
 * AuditLogModel::record(), see that class's own docblock). Gated by the new audit_log.view
 * permission (seeded in database/migrations/2026-09-03_4_audit_logs.sql).
 */
class AuditLogController extends Controller {
    private AuditLogModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new AuditLogModel();
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
        $this->view('setup/audit-log');
    }

    /** Server-side DataTable feed -- no per-column Excel filter (this is an intentionally-exempt
     *  table with a top-level filter bar instead, ordering:false, same "not every table needs
     *  per-column filters" exempt category CLAUDE.md's own Table convention already documents for
     *  Login History -- an ever-growing append-only log, sorted by time, has no real "sort by other
     *  column" use case). */
    public function list() {
        if (!$this->requirePermission('audit_log.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['draw' => intval($_REQUEST['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) && (int)$request['length'] > 0 ? (int)$request['length'] : 50;
        $filters = [];
        if (!empty($request['table_name'])) { $filters['table_name'] = (string)$request['table_name']; }
        if (!empty($request['record_id'])) { $filters['record_id'] = (int)$request['record_id']; }
        if (!empty($request['performed_by'])) { $filters['performed_by'] = (int)$request['performed_by']; }
        if (!empty($request['date_from'])) { $filters['date_from'] = (string)$request['date_from']; }
        if (!empty($request['date_to'])) { $filters['date_to'] = (string)$request['date_to']; }
        $result = $this->model->list((int)$compId, $filters, $start, $length);
        $result['draw'] = intval($request['draw'] ?? 1);
        $this->json($result);
    }
}
