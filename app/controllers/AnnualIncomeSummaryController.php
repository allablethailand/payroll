<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/AnnualIncomeSummaryModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** 2026-08-29 -- gated behind the new `annual_income_summary.view` permission (explicit request:
 *  "ต้องมีการเพิ่มสิทธิ์ให้เห็น Report นี้ด้วย"). */
class AnnualIncomeSummaryController extends Controller {
    private AnnualIncomeSummaryModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new AnnualIncomeSummaryModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private function requireView(): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), 'annual_income_summary.view', $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to view this report.']);
            return false;
        }
        return true;
    }

    public function index() {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), 'annual_income_summary.view', $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->view('permission');
            return;
        }
        $this->view('reports/annual-summary');
    }

    private function fiscalStartMonth(int $compId): int {
        $stmt = Database::getInstance()->pdo->prepare("SELECT fiscal_year_start_month FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $val = (int)$stmt->fetchColumn();
        return ($val >= 1 && $val <= 12) ? $val : 1;
    }

    public function years() {
        if (!$this->requireView()) return;
        $compId = (int)getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => [], 'fiscal_year_start_month' => 1]);
            return;
        }
        $fsm = $this->fiscalStartMonth($compId);
        $this->json(['status' => true, 'data' => $this->model->availableFiscalYears($compId, $fsm), 'fiscal_year_start_month' => $fsm]);
    }

    public function summary() {
        if (!$this->requireView()) return;
        $compId = (int)getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $fiscalYear = (int)($_GET['fiscal_year'] ?? 0);
        if ($fiscalYear <= 0) {
            $this->json(['status' => false, 'message' => 'fiscal_year is required.']);
            return;
        }
        $fsm = $this->fiscalStartMonth($compId);
        $filters = [
            'department_id' => $_GET['department_id'] ?? null,
            'team_id' => $_GET['team_id'] ?? null,
            'branch_id' => $_GET['branch_id'] ?? null,
            'role_id' => $_GET['role_id'] ?? null,
            'employee_status' => $_GET['employee_status'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];
        $this->json(['status' => true, 'data' => $this->model->summary($compId, $fiscalYear, $fsm, $filters)]);
    }
}
