<style>
/* 2026-09-02, 3-way Employee submenu split -- extracted from list.php's own shared style block
   (that page's #employeeStationFilter rule stays there; this is the login-history-only half). */
#employeeLoginHistoryStationFilter .station-filter-body { max-height: 320px; }
#employeeLoginHistoryStationFilter.collapsed .station-filter-body { max-height: 0; }
</style>
<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="login_history">Login History</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="login_history">Login History</h5>
            <p class="page-header-card-desc small" data-i18n="employee_login_history_description">Company-wide login and session history across every employee, with filters by employee, date range, device, and browser.</p>
        </div>
    </div>

    <!-- 2026-09-02, 3-way Employee submenu split -- this used to be the "Login History" tab-pane
         inside /payroll/employees (see EmployeeLoginLogModel::listForCompany()'s own docblock for
         the backend design); now its own standalone page at /payroll/employees/login-history under
         the Employee submenu (see header.php). Content below is unchanged from that tab-pane --
         the DataTable no longer needs to lazy-init on shown.bs.tab since it's visible from first
         paint now (see login-history.js's own top-of-file comment).
    -->
        <div class="station-filter" id="employeeLoginHistoryStationFilter">
            <span class="station-filter-label" data-i18n="label_filter">Filter</span>
            <button type="button" class="station-filter-toggle" id="employeeLoginHistoryStationFilterToggle" title="Toggle filter">
                <i class="fas fa-chevron-up"></i>
            </button>
            <div class="station-filter-body">
                <div class="row g-2">
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="employee">Employee</label>
                        <select class="form-select select2-remote" id="loginHistoryOverviewFilterEmployee" data-api="/api/employee.report_to.get" data-type="employee"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><span data-i18n="filter_date_from">From</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" id="loginHistoryOverviewFilterDateFrom" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><span data-i18n="filter_date_to">To</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" id="loginHistoryOverviewFilterDateTo" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="device">Device</label>
                        <select class="form-select select2-native" id="loginHistoryOverviewFilterDevice"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="browser">Browser</label>
                        <select class="form-select select2-native" id="loginHistoryOverviewFilterBrowser"></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="station-filter-clear-row d-none" id="loginHistoryOverviewFilterClearRow">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearLoginHistoryOverviewFilter">
                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover w-100" id="tb_login_history_overview">
                <thead class="table-light text-secondary">
                    <tr>
                        <th data-i18n="employee">Employee</th>
                        <th data-i18n="login_at">Login At</th>
                        <th data-i18n="logout_at">Logout At</th>
                        <th data-i18n="ip_address">IP Address</th>
                        <th data-i18n="location">Location</th>
                        <th data-i18n="timezone">Timezone</th>
                        <th data-i18n="device">Device</th>
                        <th data-i18n="operating_system">OS</th>
                        <th data-i18n="browser">Browser</th>
                        <!-- 2026-08-30, Phase 7 (T037/T038) -- see employee/detail.php's own equivalent comment. -->
                        <th data-i18n="status">Status</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
</div>
<script src="<?=asset('public/js/employee/login-history.js')?>"></script>
