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
/* 2026-08-30, same-day follow-up ("ปรับให้เป็น table responsive เหมือนเพื่อนไปเลยครับ") -- the
   scrollX+fixedColumns-specific rules (nowrap cells, custom header background) from the PREVIOUS
   round were removed along with that layout mode; only the identity-block text styling survives,
   still used by the combined Employee No./Name cell either way. */
.employee-recheck-identity-no { font-weight: 700; color: #1e293b; }
.employee-recheck-identity-name { color: #64748b; font-size: .8rem; }
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
        <!-- 2026-08-30 (Phase 3, T018, explicit request: "Tab 'Recheck ข้อมูล'...แสดงเป็น column-by-
             column ว่าข้อมูลจำเป็นสำหรับทำเงินเดือนครบหรือไม่") -- same top-level-page-tab pattern as
             Employee/Login History. Moved to 2nd position (same-day follow-up, "ย้ายตรวจสอบข้อมูลมาไว้
             Tab ที่ 2") -- pure DOM reorder of the <li>, id/data-bs-target untouched, same
             "position moves, nothing else does" precedent as Team's own tab reorder earlier this
             project (see CLAUDE.md's Team section). -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="employee-recheck-top-tab" data-bs-toggle="tab" data-bs-target="#employee-recheck-top-pane" type="button" role="tab" aria-controls="employee-recheck-top-pane" aria-selected="false"><i class="fa-solid fa-list-check me-1"></i><span data-i18n="recheck_data">Recheck Data</span></button>
        </li>
        <!-- 2026-08-30, explicit request: "ต้องการอีก Tab ต่อจาก Tab ตรวจสอบข้อมูล เป็น Tab สรุปรวมรายได้
             รายหักที่ หักหรือได้ประจำ" -- positioned right after Recheck Data. See
             EmployeeModel::standingSummaryList()'s own docblock for the full backend design. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="employee-summary-top-tab" data-bs-toggle="tab" data-bs-target="#employee-summary-top-pane" type="button" role="tab" aria-controls="employee-summary-top-pane" aria-selected="false"><i class="fa-solid fa-calculator me-1"></i><span data-i18n="standing_items_summary">Standing Items Summary</span></button>
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
                <!-- 2026-08-30 (Phase 3, T022, explicit request: Tab/Filter จ่าย vs ไม่จ่ายเงินเดือน) --
                     static select2 (3 fixed options: All/Pays Salary/No Salary), same pattern as
                     Payroll Configuration's Calculation Method dropdown -- not a master table, this
                     is a closed 2-state toggle plus "All", not an open list. -->
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1" data-i18n="payroll_participant_label">Payroll Participation</label>
                    <!-- data-option-values can't use a genuinely blank value for "All" -- initSelect2's
                         static mode does `.split(',').filter(Boolean)` on both attributes, which
                         silently drops an empty segment and misaligns keys<->values by index. Uses
                         the literal string "all" instead; currentEmployeeExtraFilters() below maps it
                         back to '' (no filter) before sending to the backend. -->
                    <select class="form-select select2-static" id="employee_filter_payroll_participant" data-option-keys="filter_all,payroll_participant_yes,payroll_participant_no" data-option-values="all,1,0"></select>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearEmployeeFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
    </div>

    <!-- 2026-08-30 (Phase 3, T024, explicit request: "Station...ปรับเป็น process pipeline UI...
         แสดงจำนวนพนักงานต่อ station ด้วย") -- same .station-row/.station-col/.station-card chevron-
         pipeline pattern as Payroll Process's own #stationRow (app/views/payroll/index.php, the
         CLICK-TO-FILTER variant -- .active is the one currently-selected filter, not the Dashboard's
         own always-on-simultaneous-counts variant, see style.css's own comment on that distinction).
         Keeps the exact same .employee-status-tab class + data-filter-status/data-filter-employment-
         status attributes the existing click handler already reads (public/js/employee/list.js) --
         only the wrapper markup/CSS class changed, the filtering logic itself is untouched. Counts
         come from a new server-side aggregate (EmployeeModel::stationCounts()) since #tb_employee is
         serverSide:true -- client-side row counting (Payroll Process's own updateStationCounts())
         only ever sees the current page's rows here, not the true total per station. NOTE: Active/
         Probation/Resign all read employees.employee_status (which itself has both an 'active' and
         a separate 'probation' value); Permanent alone reads employees.employment_status instead --
         2 different columns treated as one "station" set, a pre-existing quirk this ticket's own
         visual redesign does not change (confirmed against the real pre-existing markup, not
         assumed). -->
    <div class="station-row" id="employeeStationRow">
        <div class="station-col">
            <div class="station-card active employee-status-tab" id="tab-emp-active" data-filter-status="active">
                <span data-i18n="active">Active</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card employee-status-tab" id="tab-emp-probation" data-filter-status="probation">
                <span data-i18n="probation">Probation</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card employee-status-tab" id="tab-emp-permanent" data-filter-employment-status="permanent">
                <span data-i18n="permanent">Permanent</span> <span class="station-count">0</span>
            </div>
        </div>
        <!-- 2026-08-30, same-day follow-up: "Pipeline ลาออกเป็น การย้อนกลับสีแดงครับ" -- Resign
             restyled as a branch-off-the-main-flow card (same .station-card--reject/.station-col--
             reject rotated-chevron + extra left margin treatment Payroll Process's own #stationRow
             already uses for Rejected/Cancelled), red when active -- a resignation is an exit from
             the flow, not the next forward step after Permanent, so it reads visually as a
             branch rather than a 4th station in a straight line. .station-card-inner counter-
             rotates the text/count so they still read normally (see style.css's own comment on this
             mechanism). -->
        <div class="station-col station-col--reject">
            <div class="station-card station-card--reject employee-status-tab" id="tab-emp-resign" data-filter-status="resigned">
                <div class="station-card-inner">
                    <span data-i18n="resign">Resign</span> <span class="station-count">0</span>
                </div>
            </div>
        </div>
    </div>
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
                        <!-- 2026-08-30, Phase 7 (T037/T038) -- see employee/detail.php's own equivalent comment. -->
                        <th data-i18n="status">Status</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <!-- 2026-08-30 (Phase 3, T018) -- server-side (unbounded employee count, same convention as the
         main Employee tab's own #tb_employee). Each row = 1 employee; each ready/not-ready column
         is a derived boolean (not a raw sortable/filterable value), same exemption category this
         app's own DataTables convention already grants widget-only columns (action buttons, status
         badges) -- so no Excel-column-filter/per-column sort here, just the shared station filter
         above (reused, same role/department/team/shift/branch fields) + the search box. -->
    <div class="tab-pane fade" id="employee-recheck-top-pane" role="tabpanel" aria-labelledby="employee-recheck-top-tab" tabindex="0">
        <div class="station-filter" id="employeeRecheckStationFilter">
            <span class="station-filter-label" data-i18n="label_filter">Filter</span>
            <button type="button" class="station-filter-toggle" id="employeeRecheckStationFilterToggle" title="Toggle filter">
                <i class="fas fa-chevron-up"></i>
            </button>
            <div class="station-filter-body">
                <div class="row g-2">
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="role">Role</label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_role" data-api="/api/role.get" data-type="role"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="department">Department</label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_department" data-api="/api/department.get" data-type="department"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="team">Team</label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_team" data-api="/api/team.get" data-type="team"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="shift">Shift</label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_shift" data-api="/api/shift.options" data-type="shift"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="branch">Branch</label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearEmployeeRecheckFilter">
                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
            </button>
        </div>
        <!-- 2026-08-30, same-day follow-up ("ปรับให้เป็น table responsive เหมือนเพื่อนไปเลยครับ ให้ Column
             แรกกับ Column สุดท้าย อยู่ตำแหน่งเดิม แล้วไป expand ส่วนอื่น") -- reverted from the
             scrollX+fixedColumns treatment (previous round) to this app's own STANDARD
             responsive:true pattern instead, matching #tb_employee exactly: a dedicated Responsive
             expand-control column at index 0 (`dtr-control`, see that table's own comment on why a
             SEPARATE column instead of embedding the toggle into the first data column), and
             responsivePriority pinning Employee identity (now index 1) + Actions (last) as the 2
             columns that never collapse -- every field-readiness/Identification/Bank Details/Status
             column in between collapses into the expand row first when space is tight. No
             .table-responsive wrapper needed (that's Bootstrap's own overflow-x mechanism;
             DataTables Responsive is a column-collapse mechanism, not a scroll one) -- same plain
             wrapper #tb_employee itself uses. -->
        <table class="table table-striped table-hover" id="tb_employee_recheck">
            <thead class="table-light text-secondary">
                <tr>
                    <th></th>
                    <!-- 2026-08-31, explicit request: "ตารางพนักงานทุกตาราง แยก code กับชื่อเป็นคนละ Column" --
                         was one "Employee" column with employee_no/name stacked, split into 2 (matches
                         the main #tb_employee table's own convention, which already had them separate). -->
                    <th data-i18n="employee_no">Employee No.</th>
                    <th data-i18n="employee">Employee</th>
                    <th data-i18n="title">Title</th>
                    <th data-i18n="gender">Gender</th>
                    <th data-i18n="name_local">Name (Local)</th>
                    <th data-i18n="name_en">Name (EN)</th>
                    <th data-i18n="date_of_birth">Date of Birth</th>
                    <th data-i18n="nationality">Nationality</th>
                    <th data-i18n="identification">Identification</th>
                    <th data-i18n="personal_email">Personal Email Address</th>
                    <th data-i18n="mobile_no">Mobile No.</th>
                    <th data-i18n="department">Department</th>
                    <th data-i18n="position">Position</th>
                    <th data-i18n="branch">Branch</th>
                    <th data-i18n="employment_date">Employment Date</th>
                    <th data-i18n="ot_rate_recheck_column">OT</th>
                    <th data-i18n="social_security_fund">Social Security Fund (SSO)</th>
                    <th data-i18n="bank_details">Bank Details</th>
                    <th data-i18n="base_salary_amount">Base Salary Amount</th>
                    <th data-i18n="effective_date">Effective Date</th>
                    <th data-i18n="tax_calculation_method">Tax Calculation Method</th>
                    <th data-i18n="status">Status</th>
                    <th data-i18n="actions">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <!-- 2026-08-30, explicit request: "ต้องการอีก Tab ต่อจาก Tab ตรวจสอบข้อมูล เป็น Tab สรุปรวมรายได้รายหักที่
         หักหรือได้ประจำ รวมถึงฐานเงินและ และรายได้ รายหักที่ได้รับเป็นรอบ ให้แสดงตัวเลขในรอบที่รอจ่าย รอหัก และ
         บอกด้วยว่า งวดที่เท่าไหร่จากทั้งหมดกี่งวด และมีสรุปรวมใน Column ท้าย และ Footer ครับ" -- serverSide
         table (same "could be many employees" convention as tb_employee/tb_employee_recheck), server-
         computed totals in a real <tfoot> (same "footer reflects every filtered row, not just the
         current page" precedent AnnualIncomeSummaryModel's own report already established -- see
         EmployeeModel::standingSummaryList()'s own docblock). Recurring Earnings / Pending PED
         Earning / Pending PED Deduction are each a badge (count + subtotal) with a tooltip breakdown
         per item, same pattern as the Recheck tab's own OT summary badge. -->
    <div class="tab-pane fade" id="employee-summary-top-pane" role="tabpanel" aria-labelledby="employee-summary-top-tab" tabindex="0">
        <div class="station-filter" id="employeeSummaryStationFilter">
            <span class="station-filter-label" data-i18n="label_filter">Filter</span>
            <button type="button" class="station-filter-toggle" id="employeeSummaryStationFilterToggle" title="Toggle filter">
                <i class="fas fa-chevron-up"></i>
            </button>
            <div class="station-filter-body">
                <div class="row g-2">
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="role">Role</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_role" data-api="/api/role.get" data-type="role"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="department">Department</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_department" data-api="/api/department.get" data-type="department"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="team">Team</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_team" data-api="/api/team.get" data-type="team"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="shift">Shift</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_shift" data-api="/api/shift.options" data-type="shift"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="branch">Branch</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearEmployeeSummaryFilter">
                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
            </button>
        </div>
        <!-- 2026-08-31, explicit request: "ตรงสรุปรายได้ประจำ ตารางไม่เต็ม" -- this table has fewer,
             narrower (mostly right-aligned money) columns than #tb_employee/#tb_employee_recheck, so
             DataTables' own default autoWidth left visible empty space on the right instead of
             stretching to the container -- explicit style="width:100%" forces it to fill, same
             convention already used elsewhere in this app (e.g. setup-rules/index.php's #tb_ot). -->
        <table class="table table-striped table-hover" id="tb_employee_summary" style="width:100%">
            <thead class="table-light text-secondary">
                <tr>
                    <th></th>
                    <!-- 2026-08-31, explicit request: "ตารางพนักงานทุกตาราง แยก code กับชื่อเป็นคนละ Column" --
                         was one "Employee" column with employee_no/name stacked, split into 2. -->
                    <th data-i18n="employee_no">Employee No.</th>
                    <th data-i18n="employee">Employee</th>
                    <th class="text-end" data-i18n="base_salary_amount">Base Salary Amount</th>
                    <th class="text-end" data-i18n="recurring_earnings">Recurring Earnings</th>
                    <th class="text-end" data-i18n="recurring_deductions">Recurring Deductions</th>
                    <th class="text-end" data-i18n="pending_ped_earning">Pending Earning (Installment)</th>
                    <th class="text-end" data-i18n="pending_ped_deduction">Pending Deduction (Installment)</th>
                    <th class="text-end" data-i18n="total_earning">Total Earning</th>
                    <th class="text-end" data-i18n="total_deduction">Total Deduction</th>
                    <th class="text-end" data-i18n="net_total">Net Total</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot></tfoot>
        </table>
    </div>
    </div>

    <!-- employeeSyncModal / employeeSyncLogModal moved to app/views/layout/modals.php
         (2026-08-30, modal consolidation). -->
</div>
<script src="<?=asset('public/js/employee/list.js')?>"></script>
<script src="<?=asset('public/js/employee/employee-sync.js')?>"></script>