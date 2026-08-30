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

    /** 2026-08-29: quick-access read of the current setting for the modal (same value years()
     *  already returns, but a dedicated endpoint means opening the settings modal doesn't need to
     *  wait on/depend on the fiscal-years list call). */
    public function fiscalYearSetting() {
        if (!$this->requireView()) return;
        $compId = (int)getCompId();
        $this->json(['status' => true, 'data' => ['fiscal_year_start_month' => $compId ? $this->fiscalStartMonth($compId) : 1]]);
    }

    /** Gated by company_profile.manage, NOT annual_income_summary.view -- this writes
     *  companies.fiscal_year_start_month, the same column/permission Company Profile's own save()
     *  already requires to edit it there; annual_income_summary.view only ever grants read access
     *  to the report itself. See AnnualIncomeSummaryModel::saveFiscalYearStartMonth()'s own docblock. */
    public function saveFiscalYearSetting() {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), 'company_profile.manage', $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to edit this setting.']);
            return;
        }
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $month = (is_array($data) && isset($data['fiscal_year_start_month'])) ? (int)$data['fiscal_year_start_month'] : 0;
        $this->json($this->model->saveFiscalYearStartMonth($compId, $month));
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

    /**
     * 2026-08-30, explicit request: "ในแต่ละช่องถ้ามีข้อมูลให้สามารถกดดู Detail ได้ด้วยครับ" -- one month
     * cell's own line-item breakdown, backing the table's click-to-drill-down.
     */
    public function cellDetail() {
        if (!$this->requireView()) return;
        $compId = (int)getCompId();
        $employeeId = (int)($_GET['employee_id'] ?? 0);
        $year = (int)($_GET['year'] ?? 0);
        $month = (int)($_GET['month'] ?? 0);
        if (!$compId || !$employeeId || !$year || !$month) {
            $this->json(['status' => false, 'message' => 'Missing employee_id/year/month.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->cellDetail($compId, $employeeId, $year, $month)]);
    }
}
