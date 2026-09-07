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
            <div class="stat-card stat-card-gold h-100">
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
                    <!-- 2026-09-02, explicit request: "อยากให้ดูเป็น Payroll มากขึ้น...ถ้าเพิ่มอะไรได้ก็อยากให้เพิ่ม" --
                         a countdown ("N day(s) left"/"Pay day is today"), computed purely client-side
                         from the same upcoming_run.payment_date the value above already renders (no
                         new backend field) -- see dashboard.js's own renderPayrollWidgets(). -->
                    <div class="stat-card-sub" id="dashUpcomingPayCountdown"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="dash-section-card mb-4" id="dashPipelineSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-diagram-project me-2 text-warning"></i><span data-i18n="dash_payroll_pipeline">Payroll Pipeline</span><span class="dash-period-suffix text-muted fw-normal"></span></h6>
                    <a href="<?=BASE_URL?>/payroll-process" class="dash-section-link" data-i18n="dash_view_all">View All</a>
                </div>
                <!-- 2026-09-07, explicit request: "ภาพรวมกระบวนการเงินเดือน ปรับ Design ให้ใหม่อีกครั้งครับ
                     ตอนนี้เป็น pipeline ยังไม่สวย" -- the old station-row-of-cards + a separate Chart.js
                     donut repeating the exact same 5 numbers in a different shape is replaced with ONE
                     connected flow: 5 stages, each a colored stop with an icon/count/label, joined by
                     connector arrows so it actually reads as a pipeline instead of a plain card list.
                     Same state colors already established elsewhere in this app
                     (#dashStationRow's own former per-state background/text colors,
                     .station-card-sm.active[data-state=...]'s own solid variants for the icon
                     circles) -- reused here, not reinvented, so this still feels like "the same
                     states" rather than a new color language. Still real `<a>` links to the matching
                     Payroll Process station (see payroll/index.js's own showStation()/`location.hash`
                     read), same click-through affordance the 2026-09-02 round already established. -->
                <div class="dash-pipeline-flow" id="dashPipelineFlow">
                    <a href="<?=BASE_URL?>/payroll-process#station-draft" class="dash-pipeline-step" data-state="draft">
                        <span class="dash-pipeline-step-icon"><i class="fa-solid fa-file-alt"></i></span>
                        <span class="dash-pipeline-step-count">0</span>
                        <span class="dash-pipeline-step-label" data-i18n="state_draft">In Progress</span>
                    </a>
                    <span class="dash-pipeline-connector"><i class="fa-solid fa-chevron-right"></i></span>
                    <a href="<?=BASE_URL?>/payroll-process#station-pending_approval" class="dash-pipeline-step" data-state="pending_approval">
                        <span class="dash-pipeline-step-icon"><i class="fa-solid fa-paper-plane"></i></span>
                        <span class="dash-pipeline-step-count">0</span>
                        <span class="dash-pipeline-step-label" data-i18n="state_pending_approval">Pending Approval</span>
                    </a>
                    <span class="dash-pipeline-connector"><i class="fa-solid fa-chevron-right"></i></span>
                    <a href="<?=BASE_URL?>/payroll-process#station-approved" class="dash-pipeline-step" data-state="approved">
                        <span class="dash-pipeline-step-icon"><i class="fa-solid fa-check"></i></span>
                        <span class="dash-pipeline-step-count">0</span>
                        <span class="dash-pipeline-step-label" data-i18n="state_approved">Approved</span>
                    </a>
                    <span class="dash-pipeline-connector"><i class="fa-solid fa-chevron-right"></i></span>
                    <a href="<?=BASE_URL?>/payroll-process#station-paid" class="dash-pipeline-step" data-state="paid">
                        <span class="dash-pipeline-step-icon"><i class="fa-solid fa-money-check-dollar"></i></span>
                        <span class="dash-pipeline-step-count">0</span>
                        <span class="dash-pipeline-step-label" data-i18n="state_paid">Paid</span>
                    </a>
                    <span class="dash-pipeline-connector"><i class="fa-solid fa-chevron-right"></i></span>
                    <a href="<?=BASE_URL?>/payroll-process#station-locked" class="dash-pipeline-step" data-state="locked">
                        <span class="dash-pipeline-step-icon"><i class="fa-solid fa-lock"></i></span>
                        <span class="dash-pipeline-step-count">0</span>
                        <span class="dash-pipeline-step-label" data-i18n="state_locked">Locked</span>
                    </a>
                </div>
            </div>

            <!-- 2026-09-02, explicit request (item 5 of a 5-item follow-up list): probation/
                 internship period-expiry reminder card -- "ทั้งในหน้า Dashboard ถ้าไม่มีไม่ต้องแสดงเลย"
                 (if there's nothing to show, don't display the card at all). Starts d-none, same
                 precedent as #dashCostTrendSection below -- dashboard.js shows it only when
                 data.probation_intern_expiring is a non-empty array (also entirely absent from the
                 response when the acting employee lacks can_process_payroll, see
                 DashboardController::summary()'s own comment). No auto-transition of
                 employment_status/employment_type happens from here -- this is a pure reminder,
                 clicking a row just opens that employee's own profile to adjust manually. -->
            <div class="dash-section-card mb-4 d-none" id="dashProbationInternExpiringSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-hourglass-end me-2 text-warning"></i><span data-i18n="dash_probation_intern_expiring">Probation/Internship Ending Soon</span></h6>
                </div>
                <div id="dashProbationInternExpiringList"></div>
            </div>

            <!-- Hidden unless can_view_payroll (DashboardController::summary() already strips the
                 money fields server-side when false -- this chart is built ONLY from those fields, so
                 there's nothing honest to show at all in that case, not just something to mask). -->
            <div class="dash-section-card mb-4 d-none" id="dashCostTrendSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-chart-column me-2 text-warning"></i><span data-i18n="dash_cost_trend">Payroll Cost Trend</span><span class="dash-period-suffix text-muted fw-normal"></span></h6>
                    <!-- 2026-09-02, explicit request: "ถ้าเพิ่มอะไรได้ก็อยากให้เพิ่ม" -- the sum of the same
                         6 bars already rendered below, computed client-side from the exact same
                         payroll.cost_trend array (no new backend field) -- see dashboard.js's own
                         renderCostTrendChart(). -->
                    <span class="dash-section-link" id="dashCostTrendTotal"></span>
                </div>
                <div class="dash-chart-wrap">
                    <canvas id="dashCostTrendChart" height="90"></canvas>
                </div>
            </div>

            <div class="dash-section-card" id="dashRecentRunsSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i><span data-i18n="dash_recent_payroll_runs">Recent Payroll Runs</span><span class="dash-period-suffix text-muted fw-normal"></span></h6>
                    <a href="<?=BASE_URL?>/payroll-process" class="dash-section-link" data-i18n="dash_view_all">View All</a>
                </div>
                <div id="dashRecentRunsList"></div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- 2026-09-06, explicit request: "อยากให้มี Calendar โชว์ด้วย" -- confirmed via
                 AskUserQuestion: holidays + payroll cutoff/payment dates + probation/internship end
                 dates combined (see DashboardModel::calendarEvents()'s own docblock). Driven by the
                 SAME month/year picker as the rest of the page (#dashPeriodPicker, see the header
                 card above), not its own independent prev/next control, so it can never disagree
                 with every other widget about which month is being reviewed. Placed at the TOP of the sidebar
                 column -- per explicit request "เข้ามาในหน้า Dashboard แล้วเห็นภาพรวมของระบบทันที", this
                 is the one genuinely NEW at-a-glance visual on the page, so it earns the most
                 visible slot rather than being buried below Notifications/Quick Links. -->
            <div class="dash-section-card mb-4" id="dashCalendarSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-calendar-days me-2 text-warning"></i><span data-i18n="dash_calendar">Calendar</span></h6>
                </div>
                <!-- 2026-09-07, explicit request: "ส่วนของปฏิทินในหน้า Dashboard ให้มีเดือนปี กำกับด้วย และ
                     กด < > ไปดูได้ และส่วนที่เลือกปี เดือน มาอยู่ใน calendar จะดูดีกว่าไหมครับ" -- the
                     month/year picker (button + popover, unchanged from its own brief stay in the
                     header card right above -- see .dash-period-picker's own style.css comment)
                     relocates here, flanked by real `<`/`>` one-month-at-a-time step buttons
                     (dashboard.js's own dashStepPeriod()). This is the SAME control driving the SAME
                     whole-page historical lens as before -- only its home moved, a calendar being
                     the more natural place to browse "which month" than a page header. -->
                <div class="dash-calendar-nav">
                    <button type="button" class="dash-calendar-nav-btn" id="dashPeriodPrevBtn" title="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
                    <div class="dash-period-picker" id="dashPeriodPicker">
                        <button type="button" class="dash-period-picker-btn" id="dashPeriodPickerBtn">
                            <span id="dashPeriodPickerLabel">-</span>
                            <span class="dash-period-picker-live-dot" id="dashPeriodLiveDot"></span>
                            <i class="fa-solid fa-chevron-down dash-period-picker-caret"></i>
                        </button>
                        <div class="dash-period-picker-pop d-none" id="dashPeriodPickerPop">
                            <div class="dash-period-picker-year-nav">
                                <button type="button" class="dash-period-picker-year-btn" id="dashPeriodYearPrevBtn"><i class="fa-solid fa-chevron-left"></i></button>
                                <span id="dashPeriodPickerYearLabel">-</span>
                                <button type="button" class="dash-period-picker-year-btn" id="dashPeriodYearNextBtn"><i class="fa-solid fa-chevron-right"></i></button>
                            </div>
                            <div class="dash-period-picker-months" id="dashPeriodPickerMonths"></div>
                            <button type="button" class="dash-period-picker-today-link d-none" id="dashPeriodBackToCurrentBtn">
                                <i class="fa-solid fa-rotate-left me-1"></i><span data-i18n="dash_back_to_current">Back to Current</span>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="dash-calendar-nav-btn" id="dashPeriodNextBtn" title="Next month"><i class="fa-solid fa-chevron-right"></i></button>
                    <!-- 2026-09-07, explicit request: "ให้เพิ่ม Today กดแล้วให้มาเดือนปัจจุบัน" -- the
                         existing "Back to Current" link (#dashPeriodBackToCurrentBtn above) already did
                         exactly this, but only from INSIDE the month-picker popover -- this puts the
                         same jump-to-current-month action directly on the nav row itself, no need to
                         open the picker first. Same is_historical-driven visibility as that link (see
                         dashboard.js's own applyDashboardSummary()) -- hidden whenever already viewing
                         the current month, since there'd be nothing to jump to. -->
                    <button type="button" class="dash-calendar-nav-btn dash-calendar-today-btn d-none" id="dashCalendarTodayBtn" title="Today" data-i18n-title="dash_today">
                        <i class="fa-solid fa-calendar-day"></i>
                    </button>
                </div>
                <div class="dash-calendar-wrap">
                    <div class="dash-calendar-grid" id="dashCalendarGrid"></div>
                    <div class="dash-calendar-legend">
                        <span class="dash-cal-legend-item"><span class="dash-cal-dot dash-cal-dot-holiday"></span><span data-i18n="dash_cal_holiday">Holiday</span></span>
                        <span class="dash-cal-legend-item"><span class="dash-cal-dot dash-cal-dot-cutoff"></span><span data-i18n="dash_cal_cutoff">Payroll Cutoff</span></span>
                        <span class="dash-cal-legend-item"><span class="dash-cal-dot dash-cal-dot-payment"></span><span data-i18n="dash_cal_payment">Payment Date</span></span>
                        <span class="dash-cal-legend-item"><span class="dash-cal-dot dash-cal-dot-probation"></span><span data-i18n="dash_cal_probation">Probation/Internship End</span></span>
                    </div>
                    <div class="dash-calendar-day-detail d-none" id="dashCalendarDayDetail"></div>
                </div>
            </div>

            <!-- 2026-09-06, explicit request: "กราฟที่สามารถเพิ่มได้ แต่ไม่ดูยัดเยียดเกินไป" -- one
                 additional, restrained chart (headcount by department), same live-vs-historical
                 duality as the stat cards above (see DashboardModel::departmentHeadcount()'s own
                 docblock). Starts d-none, same "hide the whole card when there's nothing real to
                 show" precedent as every other conditional Dashboard widget. -->
            <div class="dash-section-card mb-4 d-none" id="dashDeptChartSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-sitemap me-2 text-warning"></i><span data-i18n="dash_headcount_by_dept">Headcount by Department</span></h6>
                </div>
                <div class="dash-chart-wrap dash-chart-wrap-sm">
                    <canvas id="dashDeptChart"></canvas>
                </div>
            </div>

            <!-- 2026-09-07, explicit request: "ตัดการแจ้งเตือนออกจากใน Dashboard ครับ ให้ขึ้นเฉพาะใน header
                 พอครับ" -- the notification summary card (added 2026-08-29) is removed outright; the
                 header bell dropdown (layout/header.php's own .nav-notif-dropdown, notifications.js)
                 is untouched and stays the only place notifications show. -->
            <!-- 2026-09-04, Backlog Phase 10, T058: "Dashboard shows currently-online users."
                 Presence, not money -- always visible, no can_view_payroll-style gate (see
                 DashboardController::summary()'s own comment). Starts d-none, same
                 hide-when-empty precedent as #dashProbationInternExpiringSection above (in
                 practice the acting employee's own session always counts once this AJAX call
                 itself has run, but a company with everyone else already timed out is still a
                 real, valid empty state to design for). -->
            <div class="dash-section-card mb-4 d-none" id="dashOnlineUsersSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-circle-user me-2 text-warning"></i><span data-i18n="dash_online_now">Online Now</span></h6>
                    <span class="badge bg-success-subtle text-success" id="dashOnlineUsersCount">0</span>
                </div>
                <div class="dash-online-users-list" id="dashOnlineUsersList"></div>
            </div>

            <!-- 2026-09-04, Backlog Phase 10, T057: the ONE admin-picked featured announcement. Starts
                 d-none (hidden when the company has never featured one, same precedent as the other
                 conditional widgets on this page). -->
            <div class="dash-section-card mb-4 d-none" id="dashAnnouncementSection">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-bullhorn me-2 text-warning"></i><span data-i18n="announcement_menu">Announcements</span></h6>
                    <a href="<?=BASE_URL?>/announcements" class="small" data-i18n="view_all">View All</a>
                </div>
                <div class="p-3">
                    <!-- 2026-09-07, "สามารถแนบปกได้" -- optional, hidden (d-none) when the featured
                         announcement has no cover_image_path. -->
                    <img src="" alt="" class="ann-cover-banner d-none" id="dashAnnouncementCover">
                    <div class="fw-bold mb-1" id="dashAnnouncementTitle"></div>
                    <div class="text-muted small" id="dashAnnouncementBody"></div>
                </div>
            </div>

            <!-- 2026-09-07, explicit request: "ทางลัด วางอยู่ล่างเกินไป ใช้งานไม่สะดวกครับ...ปรับเป็นให้อยู่บน
                 header ไปเลย" -- the Quick Links card that used to sit here (bottom of the sidebar
                 column, easy to miss) is gone; the same feature now lives in the navbar itself (see
                 layout/header.php's own .nav-quicklinks, right before the notification bell), and is
                 per-user customizable via that bar's own "More" > Customize Quick Links entry. -->
        </div>
    </div>
</div>

<!-- 2026-09-04, Backlog Phase 10, T057: first-login-after-publish click-through modal. Queue/remaining
     -count/accept-vs-dismiss logic all lives in dashboard.js (dashCheckPendingAnnouncements() and
     friends) -- this markup is just the shell it drives. data-bs-backdrop/keyboard are NOT set here;
     dashShowAnnouncementModalStep() sets them per-item (accept_required=1 = genuinely blocking, no
     backdrop/Esc close). -->
<div class="modal fade" id="dashAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary"><i class="fa-solid fa-bullhorn me-2 text-warning"></i><span data-i18n="announcement_menu">Announcement</span></h5>
                <span class="badge bg-secondary-subtle text-secondary" id="dashAnnModalCount"></span>
            </div>
            <div class="modal-body">
                <!-- 2026-09-07, "สามารถแนบปกได้" -->
                <img src="" alt="" class="ann-cover-banner d-none" id="dashAnnModalCover">
                <h6 id="dashAnnModalTitle" class="fw-bold"></h6>
                <!-- was a <p> -- now holds sanitized rich HTML (possibly block-level content like
                     <p>/<ul>/<h1-6>), which is invalid nested inside a <p>; a <div> renders it correctly. -->
                <div id="dashAnnModalBody" class="mb-0"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="dashAnnModalAcceptBtn"></button>
            </div>
        </div>
    </div>
</div>

<!-- 2026-09-02, explicit request: "หน้า Dashboard อยากให้เพิ่มกราฟ และอะไรให้ดูมีความเป็น Payroll" --
     Chart.js is used ONLY on this page (no other view needs charts) -- loaded here, not in the
     global footer, same "don't pollute every unrelated page" precedent this app's own footer.php
     already established for the fixedColumns scripts (see that file's own 2026-08-31 comment). -->
<script src="<?=BASE_URL?>/node_modules/chart.js/dist/chart.umd.min.js"></script>
<script src="<?=asset('public/js/dashboard.js')?>"></script>
