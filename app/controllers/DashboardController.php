<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/DashboardModel.php';
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../models/NotificationModel.php';
require_once __DIR__ . '/../models/EmployeeLoginLogModel.php';
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
    /**
     * 2026-09-06, Dashboard redesign, explicit request: month/year picker so historical data is
     * reviewable. Confirmed via AskUserQuestion: "everything possible" should follow the selection,
     * including headcount. `?year=&month=` are OPTIONAL and BOTH-OR-NEITHER (a lone year or lone
     * month is treated as "not selected" -- ambiguous otherwise) -- omitted entirely reproduces
     * TODAY'S exact default view (live/all-time snapshot), so the page's own first-impression
     * default never regresses. A few widgets are deliberately NEVER historicized even when a past
     * month IS selected, because they represent a "right now" concept with no historical meaning:
     * `pending_my_approval` (today's live approval queue), `upcoming_run` (inherently forward-
     * looking), online users, notifications, and the featured announcement -- see this method's own
     * inline comments at each point below for why.
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

        $yearParam = isset($_GET['year']) ? (int)$_GET['year'] : 0;
        $monthParam = isset($_GET['month']) ? (int)$_GET['month'] : 0;
        $isHistorical = $yearParam > 0 && $monthParam >= 1 && $monthParam <= 12;
        $monthStart = $isHistorical ? sprintf('%04d-%02d-01', $yearParam, $monthParam) : null;
        $monthEnd = $monthStart !== null ? date('Y-m-t', strtotime($monthStart)) : null;

        $data = [
            'employee' => $this->model->currentEmployee($userId, $compId),
            'employee_stats' => $this->model->employeeStats($compId, $monthStart),
            'department_headcount' => $this->model->departmentHeadcount($compId, $monthStart),
            'is_admin' => $isAdmin,
            'is_historical' => $isHistorical,
            'selected_year' => $isHistorical ? $yearParam : (int)date('Y'),
            'selected_month' => $isHistorical ? $monthParam : (int)date('n'),
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

        // 2026-09-06: the historical lens filters the pipeline/recent-runs view down to just the
        // runs that BELONG to the selected month -- turns "counts" from an all-time snapshot into
        // "how did this month's own runs turn out", which is what reviewing a past month actually
        // means. $allRuns itself stays UNFILTERED (needed as-is for cost_trend's own multi-month
        // series below, regardless of which single month is selected).
        // 2026-09-10, real bug found and fixed: this used to match by `payment_date OR
        // period_end_date`, which double-counted/mis-bucketed any run whose period-end and payment
        // date land in DIFFERENT months (e.g. period ending 25/08, paid 05/09 -- would show under
        // BOTH August's and September's historical view). "Which month a run belongs to" must have
        // exactly one answer, and per the same PayrollRunModel::findActiveRunForCyclePaymentMonth()
        // precedent every other report in this app was fixed to match today, that's payment_date --
        // matches the Calendar widget's own SEPARATE "payroll_cutoff" vs "payroll_payment" event dots
        // (DashboardModel::calendarEvents()), which never conflated the two to begin with.
        $runsForView = $allRuns;
        if ($isHistorical) {
            $runsForView = array_values(array_filter($allRuns, function (array $row) use ($monthStart, $monthEnd): bool {
                $paymentDate = $row['payment_date'] ?? null;
                return !empty($paymentDate) && $paymentDate >= $monthStart && $paymentDate <= $monthEnd;
            }));
        }

        $counts = [];
        foreach ($runsForView as $row) {
            $state = (string)($row['state'] ?? '');
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        // Default view: top 5 most recent overall (unchanged). Historical view: every run belonging
        // to that specific month (usually a small, bounded number for one company's own cadence),
        // most recent first -- "recent" reframes to "this month's runs" once a month is picked.
        $recentRuns = $isHistorical ? $runsForView : array_slice($allRuns, 0, 5);
        foreach ($recentRuns as &$row) {
            $row['public_id'] = IdCodec::encode((int)$row['id']);
            if (!$canViewPayroll) {
                $row = $this->redactRunAmounts($row);
            }
        }
        unset($row);

        // upcoming_run is NEVER historicized -- "upcoming" is inherently "relative to today", not a
        // concept a past month can have; always computed from the full $allRuns/today, regardless
        // of what month is currently selected for the rest of the page.
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
        // never a money figure, so it's computed unconditionally, same as before. NEVER historicized
        // either, same "today's live queue" reasoning as upcoming_run above.
        $pendingApprovalRows = $this->runModel->list($compId, ['state' => 'pending_approval'], $userId, $isAdmin, true);

        $data['payroll'] = [
            'counts' => $counts,
            'recent_runs' => array_values($recentRuns),
            'upcoming_run' => $upcomingRun,
            'pending_my_approval' => count($pendingApprovalRows),
        ];

        // 2026-09-02, explicit request: "หน้า Dashboard อยากให้เพิ่มกราฟ" -- a "Payroll Cost Trend" chart,
        // 6 months by payment_date, net pay only. Reuses $allRuns (the SAME array already fetched
        // above) -- zero new query. Only ever computed/returned when $canViewPayroll -- omitted from
        // the response ENTIRELY otherwise (not just left for the frontend to hide), same "nothing to
        // leak" posture as redactRunAmounts() above, since every value in this series IS a money
        // figure. 2026-09-06: re-centers to end at the SELECTED month when historical (still always
        // the 6 months ending there, same window shape as the default "ending at today" -- just
        // shifted, not a different concept), via computeCostTrend()'s own new optional param.
        if ($canViewPayroll) {
            $data['payroll']['cost_trend'] = self::computeCostTrend($allRuns, $monthStart);
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

        // 2026-09-04, Backlog Phase 10, T058: "Dashboard shows currently-online users." Never
        // money -- no permission gate, same "every employee sees the exact same widget structure"
        // posture this method's own docblock already establishes for the payroll widgets above.
        // Capped at 20 for the on-page list; total_online kept separate so the widget can show
        // "+N more" without needing a second round trip if a company ever has more online at once.
        $onlineUsers = (new EmployeeLoginLogModel())->listOnlineForCompany($compId);
        $data['online_users'] = array_slice($onlineUsers, 0, 20);
        $data['online_users_total'] = count($onlineUsers);

        // 2026-09-04, Backlog Phase 10, T057: the ONE admin-picked featured announcement (null if
        // none set) -- same "every employee sees the same widget" posture, never money either.
        // NEVER historicized -- an announcement is a "right now" broadcast, not a historical record.
        require_once __DIR__ . '/../models/AnnouncementModel.php';
        $data['featured_announcement'] = (new AnnouncementModel())->getDashboardFeatured($compId);

        $this->json(['status' => true, 'data' => $data]);
    }

    /**
     * 2026-09-06, Dashboard redesign: Calendar widget data for one calendar month (defaults to the
     * current month when `year`/`month` are omitted, same as the picker's own default). See
     * DashboardModel::calendarEvents()'s own docblock for what it combines (holidays + payroll
     * cutoff/payment dates + probation/internship end dates, confirmed via AskUserQuestion).
     */
    public function calendar() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        if ($month < 1 || $month > 12) {
            $this->json(['status' => false, 'message' => 'Invalid month.']);
            return;
        }
        $events = $this->model->calendarEvents((int)$compId, $year, $month);
        $this->json(['status' => true, 'data' => ['events' => $events]]);
    }

    /**
     * Net pay by payment_date, summed across every run in that month, for the 6 months ENDING at
     * `$endMonthStart` (or the current month when null -- unchanged default). Only final-state runs
     * (approved/paid/locked) count -- same ALLOWED_STATES convention as every disbursement-layer
     * report in this app (PayrollRunCashPaymentModel/BankTransferFileReport/etc.) -- a draft/pending
     * run's own numbers can still change, so showing them as historical cost would be misleading.
     * Pure/static (no DB access, no $this) so it's directly unit-testable without going through
     * summary()'s own json()-and-exit() response (see tests/dashboard_cost_trend_test.php).
     * 2026-09-06: gained the optional `$endMonthStart` ('YYYY-MM-01') so the Dashboard's own
     * month/year picker can re-center this same 6-month window at a past month instead of always
     * ending at today -- omitted (null), this is 100% unchanged from before that feature existed.
     * @param array $allRuns rows from PayrollRunModel::list() (must include state/payment_date/total_net_amount)
     * @param string|null $endMonthStart 'YYYY-MM-01' -- the LAST month the 6-month window should include
     * @return array<int, array{month: string, net_amount: float}> chronologically ascending
     */
    public static function computeCostTrend(array $allRuns, ?string $endMonthStart = null): array {
        $trendByMonth = [];
        foreach ($allRuns as $row) {
            if (!in_array($row['state'] ?? '', ['approved', 'paid', 'locked'], true)) continue;
            if (empty($row['payment_date'])) continue;
            $month = substr((string)$row['payment_date'], 0, 7); // 'YYYY-MM'
            $trendByMonth[$month] = ($trendByMonth[$month] ?? 0.0) + (float)($row['total_net_amount'] ?? 0);
        }
        ksort($trendByMonth);
        // $endMonthStart===null (the default) skips this filter entirely -- byte-identical to this
        // method's own behavior before this param existed. Only when a historical month IS selected
        // does this bound the window to end there instead of wherever the latest real data happens
        // to be -- same sparse "last 6 months that actually have final-state data" semantics either
        // way, NOT zero-padded to force exactly 6 entries (a company with less than 6 months of
        // final-state history returns fewer than 6 -- unchanged from before, see
        // tests/dashboard_cost_trend_test.php's own "only non-final-state runs -> empty array"
        // assertion, which a zero-padding version would have broken).
        if ($endMonthStart !== null) {
            $endMonth = substr($endMonthStart, 0, 7);
            $trendByMonth = array_filter($trendByMonth, fn($month) => $month <= $endMonth, ARRAY_FILTER_USE_KEY);
        }
        $trendByMonth = array_slice($trendByMonth, -6, null, true);
        return array_map(
            fn($month, $amount) => ['month' => $month, 'net_amount' => $amount],
            array_keys($trendByMonth), array_values($trendByMonth)
        );
    }
}
