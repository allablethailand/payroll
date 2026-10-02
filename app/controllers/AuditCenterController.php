<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/**
 * One "Audit & Activity Logs" page (/audit) with 2 sub-tabs: Payroll Run Audit (payroll_run.view)
 * and the field-level System Audit Log (audit_log.view). Page shell only -- the data endpoints stay
 * on ReportsController (api/report.run-audit-*) and AuditLogController (api/audit-log.list), each
 * still enforcing its own permission.
 */
class AuditCenterController extends Controller {
    private PayrollRunModel $payrollRunModel;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->payrollRunModel = new PayrollRunModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private function can(string $permissionKey): bool {
        return $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), (int)getCompId())['allowed'];
    }

    public function index() {
        // Same two-part gate ReportsController::runAudit() used before the merge.
        $canRunAudit = $this->payrollRunModel->canView($this->userId(), $this->isAdmin()) && $this->can('payroll_run.view');
        $canAuditLog = $this->can('audit_log.view');
        if (!$canRunAudit && !$canAuditLog) {
            $this->view('permission');
            return;
        }
        $requested = (string)($_GET['tab'] ?? '');
        $activeTab = ($requested === 'general' && $canAuditLog) ? 'general' : ($canRunAudit ? 'run' : 'general');
        $this->view('audit/index', [
            'canRunAudit' => $canRunAudit,
            'canAuditLog' => $canAuditLog,
            'activeTab' => $activeTab,
        ]);
    }

    /** Old /reports/run-audit URL (bookmarks, saved links). */
    public function legacyRunAudit() {
        $this->redirect(BASE_URL . '/audit?tab=run');
    }

    /** Old /audit-log URL (bookmarks, saved links). */
    public function legacyAuditLog() {
        $this->redirect(BASE_URL . '/audit?tab=general');
    }
}
