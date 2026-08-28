<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/DashboardModel.php';
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../services/IdCodec.php';

class DashboardController extends Controller {
    private $model;
    private $runModel;

    public function __construct() {
        $this->model = new DashboardModel();
        $this->runModel = new PayrollRunModel();
    }

    public function index() {
        $this->view('dashboard');
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** Overview widgets for the landing page. Payroll-run widgets are only computed when the
     *  acting employee holds at least one payroll role flag (PayrollRunModel::canView()) -- same
     *  gate the Payroll Process/Approval pages themselves already use, so a plain staff account
     *  with no payroll role just doesn't see payroll data here either, rather than inventing a
     *  new permission concept for this one page. */
    public function summary() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $compId = (int)$compId;
        $userId = $this->userId();
        $isAdmin = $this->isAdmin();

        $data = [
            'employee' => $this->model->currentEmployee($userId, $compId),
            'employee_stats' => $this->model->employeeStats($compId),
            'is_admin' => $isAdmin,
            'can_view_payroll' => false,
            'payroll' => null,
        ];

        $canViewPayroll = $this->runModel->canView($userId, $isAdmin);
        $data['can_view_payroll'] = $canViewPayroll;
        if ($canViewPayroll) {
            // Reuses PayrollRunModel::list() (the exact same source the Payroll Process page's own
            // station counts/table are built from) instead of adding a parallel count-by-state
            // query -- this app's payroll-run volume per company is small enough that the Process
            // page already pulls the same unfiltered list client-side for its own DataTable.
            $allRuns = $this->runModel->list($compId);

            $counts = [];
            foreach ($allRuns as $row) {
                $state = (string)($row['state'] ?? '');
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }

            $recentRuns = array_slice($allRuns, 0, 5);
            foreach ($recentRuns as &$row) {
                $row['public_id'] = IdCodec::encode((int)$row['id']);
            }
            unset($row);

            $today = date('Y-m-d');
            $upcoming = array_values(array_filter($allRuns, function (array $row) use ($today): bool {
                return !empty($row['payment_date']) && $row['payment_date'] >= $today
                    && !in_array($row['state'], ['cancelled', 'rejected'], true);
            }));
            usort($upcoming, function (array $a, array $b): int {
                return strcmp((string)$a['payment_date'], (string)$b['payment_date']);
            });
            $upcomingRun = $upcoming[0] ?? null;
            if ($upcomingRun) {
                $upcomingRun['public_id'] = IdCodec::encode((int)$upcomingRun['id']);
            }

            $pendingApprovalRows = $this->runModel->list($compId, ['state' => 'pending_approval'], $userId, $isAdmin, true);

            $data['payroll'] = [
                'counts' => $counts,
                'recent_runs' => array_values($recentRuns),
                'upcoming_run' => $upcomingRun,
                'pending_my_approval' => count($pendingApprovalRows),
            ];
        }

        $this->json(['status' => true, 'data' => $data]);
    }
}
