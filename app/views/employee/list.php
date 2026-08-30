<style>
/* .station-filter-body's shared max-height (200px, see style.css) fits the Payroll Process page's
   2-field date filter but not this page's 6-field row, which wraps to 3 rows on narrow screens --
   raise it here only. Both the expanded and collapsed variants must be scoped to this page's own
   id so the collapse-to-0 animation (the more specific .collapsed rule) still wins over this. */
#employeeStationFilter .station-filter-body,
#employeeLoginHistoryStationFilter .station-filter-body { max-height: 320px; }
#employeeStationFilter.collapsed .station-filter-body,
#employeeLoginHistoryStationFilter.collapsed .station-filter-body { max-height: 0; }

#tb_employee tbody tr { transition: background-color .12s ease; }
.employee-completeness-bar { min-width: 100px; }
.employee-completeness-bar .progress { height: 6px; background-color: #eef0f2; }
/* 2026-08-30, real photo (synced or manually uploaded) shown in place of the initial-letter avatar
   circle once profile_photo_path is set -- object-fit:cover so a non-square upload still fills the
   circle cleanly instead of distorting/letterboxing. */
.employee-list-avatar-img {
    width: 38px; height: 38px; min-width: 38px;
    border-radius: 50%;
    object-fit: cover;
    /* 2026-08-30, explicit follow-up report ("หัวหลุดวงกลม"): default object-position (center) crops
       a typical portrait around the chest, not the face -- bias toward the top of the frame instead.
       Same fix applied everywhere else this app shows a circular profile photo. */
    object-position: center top;
}
</style>
<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="employee">Employee</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon">
            <i class="fa-solid fa-users-gear"></i>
        </div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="employee_management_title">Employee Management</h5>
            <p class="page-header-card-desc small" data-i18n="employee_management_description">Configure and manage employee profiles, tax identifications, and bank accounts for payroll processing.</p>
        </div>
    </div>

    <!-- 2026-08-29, explicit request: "ในหน้า employee list ก็ให้แยกเป็น 2 tab tab employee กับประวัติการ
         เข้าใช้ ดูภาพรวมของทุกคน มี Filter ด้วย" -- top-level page tab (per this project's own Tab
         convention: .setup-tabs/.setup-menu, NOT .structure-tabs -- this switches the ENTIRE page's
         content, same category as Payroll Configuration's Cycle/Earnings/Deductions tabs, not a
         sub-tab within one page). Everything that used to be this page's only content (the
         .station-filter block through #tb_employee's own table) is now the "Employee" pane; the
         existing #employeeTabs status-filter pills (Active/Probation/Permanent/Resign) stay exactly
         as they were, nested one level deeper inside this new pane -- they filter WITHIN the
         Employee tab, they don't switch pages, so they keep using plain .nav-tabs (not this new
         outer level's .setup-tabs) same as before. -->
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs mb-4" id="employeeTopTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active setup-menu" id="employee-top-tab" data-bs-toggle="tab" data-bs-target="#employee-top-pane" type="button" role="tab" aria-controls="employee-top-pane" aria-selected="true"><i class="fa-solid fa-users me-1"></i><span data-i18n="employee">Employee</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="employee-login-history-top-tab" data-bs-toggle="tab" data-bs-target="#employee-login-history-top-pane" type="button" role="tab" aria-controls="employee-login-history-top-pane" aria-selected="false"><i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="login_history">Login History</span></button>
        </li>
    </ul>
    <div class="tab-content" id="employeeTopTabsContent">
    <div class="tab-pane fade show active" id="employee-top-pane" role="tabpanel" aria-labelledby="employee-top-tab" tabindex="0">
    <div class="station-filter" id="employeeStationFilter">
        <span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="employeeStationFilterToggle" title="Toggle filter">
            <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
            <div class="row g-2">
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><span data-i18n="filter_date_from">From</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="employee_filter_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><span data-i18n="filter_date_to">To</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="employee_filter_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="role">Role</label>
                    <select class="form-select select2-remote" id="employee_filter_role" data-api="/api/role.get" data-type="role"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="department">Department</label>
                    <select class="form-select select2-remote" id="employee_filter_department" data-api="/api/department.get" data-type="department"></select>
                </div>
                <!-- 2026-08-24, explicit request: "เพิ่ม Filter ทีมในหน้า list พนักงานด้วย" -->
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="team">Team</label>
                    <select class="form-select select2-remote" id="employee_filter_team" data-api="/api/team.get" data-type="team"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="shift">Shift</label>
                    <select class="form-select select2-remote" id="employee_filter_shift" data-api="/api/shift.options" data-type="shift"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="branch">Branch</label>
                    <select class="form-select select2-remote" id="employee_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearEmployeeFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
    </div>

    <ul class="nav nav-tabs flex-nowrap scrollable-tabs" id="employeeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary active employee-status-tab" id="tab-emp-active" type="button" role="tab" aria-controls="employee" aria-selected="true" data-i18n="active" data-filter-status="active">Active</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary employee-status-tab" id="tab-emp-probation" type="button" role="tab" aria-controls="employee" aria-selected="false" data-i18n="probation" data-filter-status="probation">Probation</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary employee-status-tab" id="tab-emp-permanent" type="button" role="tab" aria-controls="employee" aria-selected="false" data-i18n="permanent" data-filter-employment-status="permanent">Permanent</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary employee-status-tab" id="tab-emp-resign" type="button" role="tab" aria-controls="employee" aria-selected="false" data-i18n="resign" data-filter-status="resigned">Resign</button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-5" id="employeeTabsContent">
        <div class="tab-pane fade show active" id="employee-pane" role="tabpanel" aria-labelledby="employee-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-striped table-hover" id="tb_employee">
                    <thead class="table-light text-secondary">
                        <tr>
                            <!-- 2026-08-27, explicit request: "ปุ่มที่ expand ตารางเพื่อดูข้อมูลของ
                                 column ที่ซ่อน ควรแยกมาเป็น column แรก" -- a dedicated Responsive
                                 "control" column (the +/- expand toggle) instead of it sharing space
                                 with the avatar column. Inserting a column shifts every later index
                                 by one, same convention already established for Team's own column
                                 addition (see CLAUDE.md's Team section) -- EmployeeModel::list()'s
                                 own `sortColumns` map and list.js's column defs were updated to match. -->
                            <th></th>
                            <!-- 2026-08-29, explicit request: "เพิ่ม checkbox ด้านหน้า เพื่อให้เลือกหลาย
                                 รายการแล้วกด Sync ได้หลายคนพร้อมกัน" -- master "select all" checkbox,
                                 see list.js's own bulk-sync-selection code for how selection state is
                                 tracked across pages (this is a serverSide table, so a page change
                                 replaces every row in the DOM). -->
                            <th style="width:36px;"><input type="checkbox" id="employeeSelectAllCheckbox"></th>
                            <th></th>
                            <th scope="col" data-i18n="employee_no">Employee No.</th>
                            <th scope="col" data-i18n="source">Source</th>
                            <th scope="col" data-i18n="name">Name</th>
                            <th scope="col" data-i18n="mobile_no">Mobile No.</th>
                            <!-- 2026-08-29, explicit request: "เพิ่ม Email ในหน้า List ของพนักงานด้วยครับ" --
                                 already selected server-side (EmployeeModel::list()'s own `e.personal_email
                                 AS email`, added long before this for the Detail page/other consumers),
                                 just never rendered as a column here. Reuses the existing personal_email
                                 i18n key (same label already used on Employee Detail's own Contact tab)
                                 rather than adding a new one for the same concept. -->
                            <th scope="col" data-i18n="personal_email">Personal Email Address</th>
                            <th scope="col" data-i18n="role">Role</th>
                            <th scope="col" data-i18n="position">Position</th>
                            <th scope="col" data-i18n="department">Department</th>
                            <th scope="col" data-i18n="team">Team</th>
                            <th scope="col" data-i18n="shift">Shift</th>
                            <th scope="col" data-i18n="branch">Branch</th>
                            <th scope="col" data-i18n="start_work_date">Start Work Date</th>
                            <th scope="col" data-i18n="status">Status</th>
                            <th scope="col" data-i18n="profile_completeness">Completeness</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
    </div>
    <!-- 2026-08-29, explicit request: "ในหน้า employee list ก็ให้แยกเป็น 2 tab tab employee กับประวัติการ
         เข้าใช้ ดูภาพรวมของทุกคน มี Filter ด้วย" -- company-wide overview (every employee), unlike the
         Employee Detail page's own Login History tab which is scoped to one employee -- see
         EmployeeLoginLogModel::listForCompany()'s own docblock. Lazy-inits its DataTable on first
         shown.bs.tab (this app's own standing habit for a DataTable inside a non-default Bootstrap
         tab -- constructing one while its pane is display:none collapses every column to 0 width). -->
    <div class="tab-pane fade" id="employee-login-history-top-pane" role="tabpanel" aria-labelledby="employee-login-history-top-tab" tabindex="0">
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
        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearLoginHistoryOverviewFilter">
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
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    </div>

    <!-- employeeSyncModal / employeeSyncLogModal moved to app/views/layout/modals.php
         (2026-08-30, modal consolidation). -->
</div>
<script src="<?=asset('public/js/employee/list.js')?>"></script>
<script src="<?=asset('public/js/employee/employee-sync.js')?>"></script>