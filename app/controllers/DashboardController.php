<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/DashboardModel.php';
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../models/NotificationModel.php';
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

    /** Columns on a payroll_runs row (via PayrollRunModel::list()'s `r.*`) that actually hold money
     *  -- stripped from every row this endpoint returns whenever the acting employee can't see
     *  payroll amounts (see summary()'s own docblock for why). */
    private const RUN_MONEY_FIELDS = ['total_gross_amount', 'total_deduction_amount', 'total_net_amount'];

    private function redactRunAmounts(array $row): array {
        foreach (self::RUN_MONEY_FIELDS as $field) {
            unset($row[$field]);
        }
        return $row;
    }

    /**
     * Overview widgets for the landing page. 2026-08-28, explicit request: "ตอนนี้ใน Dashboard บาง
     * สิทธิ์เห็น บางสิทธิ์มองไม่ให้ อยากให้เห็นเหมือนกันทั้งหมด แต่ตรงตัวเลขเงินเดือนให้เป็นไปตาม Role ที่ Set
     * ไว้" -- previously the ENTIRE payroll widget block (run counts by state, Recent Runs list,
     * Upcoming Pay date, Pending My Approval count) was hidden outright for anyone without at
     * least one payroll role flag (PayrollRunModel::canView()), so a plain staff account saw a
     * visibly different, sparser dashboard. Every employee now sees the exact same widget
     * STRUCTURE regardless of role -- only the actual money figures within it (Recent Runs' own
     * amounts) are still gated by that same canView() check, via redactRunAmounts() above, which
     * strips total_gross_amount/total_deduction_amount/total_net_amount server-side (not just
     * hidden client-side) so a disallowed user can't read them out of the raw JSON response
     * either. Run counts/dates/state badges/pending-approval count were never money to begin with,
     * so nothing about them needed gating -- they were only ever hidden as a side effect of the
     * whole block being gated together.
     */
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
        ];

        // Now purely "can this employee see money figures", not "can this employee see the payroll
        // widgets at all" -- kept in the response so the frontend knows whether to render amounts.
        $canViewPayroll = $this->runModel->canView($userId, $isAdmin);
        $data['can_view_payroll'] = $canViewPayroll;

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
            if (!$canViewPayroll) {
                $row = $this->redactRunAmounts($row);
            }
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
            if (!$canViewPayroll) {
                $upcomingRun = $this->redactRunAmounts($upcomingRun);
            }
        }

        // Every employee can be an eligible approver on SOME run regardless of the coarse
        // can_view_payroll flag above (a department-scoped approver, for instance) -- this count is
        // never a money figure, so it's computed unconditionally, same as before.
        $pendingApprovalRows = $this->runModel->list($compId, ['state' => 'pending_approval'], $userId, $isAdmin, true);

        $data['payroll'] = [
            'counts' => $counts,
            'recent_runs' => array_values($recentRuns),
            'upcoming_run' => $upcomingRun,
            'pending_my_approval' => count($pendingApprovalRows),
        ];

        // 2026-09-02, explicit request: "หน้า Dashboard อยากให้เพิ่มกราฟ" -- a "Payroll Cost Trend" chart,
        // last 6 months by payment_date, net pay only. Reuses $allRuns (the SAME array already
        // fetched above for the pipeline counts) -- zero new query. Only ever computed/returned when
        // $canViewPayroll -- omitted from the response ENTIRELY otherwise (not just left for the
        // frontend to hide), same "nothing to leak" posture as redactRunAmounts() above, since every
        // value in this series IS a money figure.
        if ($canViewPayroll) {
            $data['payroll']['cost_trend'] = self::computeCostTrend($allRuns);
        }

        // 2026-09-02, explicit request (item 5 of a 5-item follow-up list): probation/internship
        // period-expiry reminder card -- "ทั้งในหน้า Dashboard ถ้าไม่มีไม่ต้องแสดงเลย" (if there's
        // nothing to show, don't display the card at all). Gated the same way money figures are
        // (can_process_payroll -- the group who'd actually go adjust the employee's status/type) --
        // omitted from the response ENTIRELY when not permitted, not just hidden client-side, same
        // "nothing to leak" posture as cost_trend above (this is employee-status info, not money, but
        // still not everyone's business). Reuses NotificationModel::probationInternExpiringEmployees()
        // -- the SAME query that drives the notification-bell reminder, so the two never disagree.
        if ($this->runModel->canProcessPayroll($userId, $isAdmin)) {
            $expiring = (new NotificationModel())->probationInternExpiringEmployees($compId);
            if (!empty($expiring)) {
                $data['probation_intern_expiring'] = $expiring;
            }
        }

        $this->json(['status' => true, 'data' => $data]);
    }

    /**
     * Last 6 months of net pay by payment_date, summed across every run in that month. Only
     * final-state runs (approved/paid/locked) count -- same ALLOWED_STATES convention as every
     * disbursement-layer report in this app (PayrollRunCashPaymentModel/BankTransferFileReport/
     * etc.) -- a draft/pending run's own numbers can still change, so showing them as historical
     * cost would be misleading. Pure/static (no DB access, no $this) so it's directly unit-testable
     * without going through summary()'s own json()-and-exit() response (see
     * tests/dashboard_cost_trend_test.php).
     * @param array $allRuns rows from PayrollRunModel::list() (must include state/payment_date/total_net_amount)
     * @return array<int, array{month: string, net_amount: float}> chronologically ascending
     */
    public static function computeCostTrend(array $allRuns): array {
        $trendByMonth = [];
        foreach ($allRuns as $row) {
            if (!in_array($row['state'] ?? '', ['approved', 'paid', 'locked'], true)) continue;
            if (empty($row['payment_date'])) continue;
            $month = substr((string)$row['payment_date'], 0, 7); // 'YYYY-MM'
            $trendByMonth[$month] = ($trendByMonth[$month] ?? 0.0) + (float)($row['total_net_amount'] ?? 0);
        }
        ksort($trendByMonth);
        $trendByMonth = array_slice($trendByMonth, -6, null, true);
        return array_map(
            fn($month, $amount) => ['month' => $month, 'net_amount' => $amount],
            array_keys($trendByMonth), array_values($trendByMonth)
        );
    }
}
