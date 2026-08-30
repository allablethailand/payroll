<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="dashboard">Dashboard</span>
        </h5>
    </nav>

    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-gauge-high"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" id="dashGreetingTitle">Welcome</h5>
            <p class="page-header-card-desc small" id="dashGreetingDesc">Here is an overview of your payroll workspace.</p>
        </div>
    </div>

    <div class="row g-3 mb-4" id="dashStatRow">
        <div class="col-6 col-lg-3">
            <div class="stat-card stat-card-info h-100">
                <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                <div>
                    <div class="stat-card-label" data-i18n="dash_active_employees">Active Employees</div>
                    <div class="stat-card-value" id="dashActiveEmployees">-</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card stat-card-success h-100">
                <div class="stat-card-icon"><i class="fa-solid fa-user-plus"></i></div>
                <div>
                    <div class="stat-card-label" data-i18n="dash_new_hires_month">New Hires This Month</div>
                    <div class="stat-card-value" id="dashNewHires">-</div>
                </div>
            </div>
        </div>
        <!-- 2026-08-28, explicit request: "อยากให้เห็นเหมือนกันทั้งหมด แต่ตรงตัวเลขเงินเดือนให้เป็นไปตาม
             Role ที่ Set ไว้" -- this whole widget block used to start d-none and only get shown by
             dashboard.js when the acting employee had a payroll role at all; now always visible for
             everyone, the money figure inside Recent Runs is the only part still role-gated (see
             DashboardController::summary()'s own docblock). -->
        <div class="col-6 col-lg-3" id="dashStatPendingApprovalCol">
            <a href="<?=BASE_URL?>/payroll-approval" class="text-decoration-none">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="dash_pending_my_approval">Pending My Approval</div>
                        <div class="stat-card-value" id="dashPendingApproval">-</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3" id="dashStatUpcomingPayCol">
            <div class="stat-card stat-card-primary h-100">
                <div class="stat-card-icon"><i class="fa-solid fa-calendar-day"></i></div>
                <div>
                    <div class="stat-card-label" data-i18n="dash_upcoming_pay_date">Upcoming Pay Date</div>
                    <div class="stat-card-value" id="dashUpcomingPayDate">-</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="dash-section-card mb-4" id="dashPipelineSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-diagram-project me-2 text-warning"></i><span data-i18n="dash_payroll_pipeline">Payroll Pipeline</span></h6>
                    <a href="<?=BASE_URL?>/payroll-process" class="dash-section-link" data-i18n="dash_view_all">View All</a>
                </div>
                <div class="station-row" id="dashStationRow">
                    <div class="station-col"><div class="station-card" data-state="draft"><span data-i18n="state_draft">In Progress</span> <span class="station-count">0</span></div></div>
                    <div class="station-col"><div class="station-card" data-state="pending_approval"><span data-i18n="state_pending_approval">Pending Approval</span> <span class="station-count">0</span></div></div>
                    <div class="station-col"><div class="station-card" data-state="approved"><span data-i18n="state_approved">Approved</span> <span class="station-count">0</span></div></div>
                    <div class="station-col"><div class="station-card" data-state="paid"><span data-i18n="state_paid">Paid</span> <span class="station-count">0</span></div></div>
                    <div class="station-col"><div class="station-card" data-state="locked"><span data-i18n="state_locked">Locked</span> <span class="station-count">0</span></div></div>
                </div>
            </div>

            <div class="dash-section-card" id="dashRecentRunsSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i><span data-i18n="dash_recent_payroll_runs">Recent Payroll Runs</span></h6>
                    <a href="<?=BASE_URL?>/payroll-process" class="dash-section-link" data-i18n="dash_view_all">View All</a>
                </div>
                <div id="dashRecentRunsList"></div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- 2026-08-29, explicit request: "และตรงการใส่ Comments...และสามารถเพิ่มอะไรได้อีกในหน้า
                 Dashboard ไหมครับ" -> "สนใจครับ" (confirmed the notification-summary-card suggestion) --
                 latest few notifications right on the dashboard, not just reachable via the header
                 bell. Reuses notifItemHtml()/BASE_URL/api/notification.list from notifications.js
                 (loaded globally, see layout/header.php) rather than duplicating that markup here. -->
            <div class="dash-section-card mb-4" id="dashNotifSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-bell me-2 text-warning"></i><span data-i18n="notifications">Notifications</span></h6>
                    <a href="<?=BASE_URL?>/notifications" class="dash-section-link" data-i18n="notif_view_all">View All</a>
                </div>
                <div class="dash-notif-list" id="dashNotifList">
                    <div class="nav-notif-empty d-none" id="dashNotifEmpty" data-i18n="notif_empty">No notifications yet.</div>
                </div>
            </div>
            <div class="dash-section-card">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-bolt me-2 text-warning"></i><span data-i18n="dash_quick_links">Quick Links</span></h6>
                </div>
                <div class="dash-quick-links">
                    <a class="dash-quick-link" href="<?=BASE_URL?>/employees">
                        <span class="dash-quick-link-icon"><img src="<?=BASE_URL?>/public/images/menu/EMPLOYEE.SVG" alt=""></span>
                        <span data-i18n="employees">Employees</span>
                    </a>
                    <a class="dash-quick-link" href="<?=BASE_URL?>/payroll-process">
                        <span class="dash-quick-link-icon"><img src="<?=BASE_URL?>/public/images/menu/PAYROLL.SVG" alt=""></span>
                        <span data-i18n="payroll_process">Payroll Process</span>
                    </a>
                    <a class="dash-quick-link" href="<?=BASE_URL?>/payroll-approval">
                        <span class="dash-quick-link-icon"><img src="<?=BASE_URL?>/public/images/menu/APPROVAL.SVG" alt=""></span>
                        <span data-i18n="payroll_approval">Payroll Approval</span>
                    </a>
                    <a class="dash-quick-link" href="<?=BASE_URL?>/reports">
                        <span class="dash-quick-link-icon"><img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt=""></span>
                        <span data-i18n="reports">Reports</span>
                    </a>
                    <a class="dash-quick-link" href="<?=BASE_URL?>/payslip-documents/requests">
                        <span class="dash-quick-link-icon"><img src="<?=BASE_URL?>/public/images/menu/REPORT.SVG" alt=""></span>
                        <span data-i18n="payslip_menu">Payslip & Documents</span>
                    </a>
                    <a class="dash-quick-link" href="<?=BASE_URL?>/setup-rules">
                        <span class="dash-quick-link-icon"><img src="<?=BASE_URL?>/public/images/menu/TIME.SVG" alt=""></span>
                        <span data-i18n="time_and_leave">Time & Leave</span>
                    </a>
                    <a class="dash-quick-link" href="<?=BASE_URL?>/setup/company-profile">
                        <span class="dash-quick-link-icon"><img src="<?=BASE_URL?>/public/images/menu/SETTINGS.SVG" alt=""></span>
                        <span data-i18n="settings">Settings</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?=asset('public/js/dashboard.js')?>"></script>
