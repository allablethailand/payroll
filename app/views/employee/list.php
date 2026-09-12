<style>
/* .station-filter-body's shared max-height (200px, see style.css) fits the Payroll Process page's
   2-field date filter but not this page's 6-field row, which wraps to 3 rows on narrow screens --
   raise it here only. Both the expanded and collapsed variants must be scoped to this page's own
   id so the collapse-to-0 animation (the more specific .collapsed rule) still wins over this.
   2026-09-02, 3-way Employee submenu split -- the Login History tab's own equivalent rule moved to
   login-history.php's own <style> block along with that page. */
#employeeStationFilter .station-filter-body { max-height: 320px; }
#employeeStationFilter.collapsed .station-filter-body { max-height: 0; }

#tb_employee tbody tr { transition: background-color .12s ease; }
/* 2026-09-02, explicit request: "ความสมบูรณ์ของ Profile ช่วยปรับเป็น progress วงกลมได้ไหมครับ" -- replaces
   the old .employee-completeness-bar (horizontal Bootstrap .progress) with a small CSS
   conic-gradient ring, built inline via completenessRingHtml() in list.js (background set per-row
   via style attribute -- the color/percentage vary per employee, not something a static class can
   express). The white inner circle is a plain nested ::before, not a second stacked element, to
   keep each row's DOM as light as possible across a potentially long employee list. */
.employee-completeness-ring {
    position: relative;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: none;
}
.employee-completeness-ring::before {
    content: '';
    position: absolute;
    inset: 4px;
    border-radius: 50%;
    background: #fff;
}
.employee-completeness-ring-value {
    position: relative;
    z-index: 1;
    font-size: .6rem;
    font-weight: 700;
}
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
         outer level's .setup-tabs) same as before.
         2026-09-02, 3-way Employee submenu split -- this page used to have 4 top-level tabs
         (Employee/Recheck Data/Reports/Login History); Reports and Login History are now their own
         standalone pages under the Employee submenu (see header.php), leaving just these 2. -->
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs mb-4" id="employeeTopTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active setup-menu" id="employee-top-tab" data-bs-toggle="tab" data-bs-target="#employee-top-pane" type="button" role="tab" aria-controls="employee-top-pane" aria-selected="true"><i class="fa-solid fa-users me-1"></i><span data-i18n="employee">Employee</span></button>
        </li>
        <!-- 2026-08-30 (Phase 3, T018, explicit request: "Tab 'Recheck ข้อมูล'...แสดงเป็น column-by-
             column ว่าข้อมูลจำเป็นสำหรับทำเงินเดือนครบหรือไม่") -- same top-level-page-tab pattern as
             Employee. Moved to 2nd position (same-day follow-up, "ย้ายตรวจสอบข้อมูลมาไว้
             Tab ที่ 2") -- pure DOM reorder of the <li>, id/data-bs-target untouched, same
             "position moves, nothing else does" precedent as Team's own tab reorder earlier this
             project (see CLAUDE.md's Team section). -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="employee-recheck-top-tab" data-bs-toggle="tab" data-bs-target="#employee-recheck-top-pane" type="button" role="tab" aria-controls="employee-recheck-top-pane" aria-selected="false"><i class="fa-solid fa-list-check me-1"></i><span data-i18n="recheck_data">Recheck Data</span></button>
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
                    <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_from">From</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="employee_filter_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_to">To</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="employee_filter_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-user-tag me-1 text-muted"></i><span data-i18n="role">Role</span></label>
                    <select class="form-select select2-remote" id="employee_filter_role" data-api="/api/role.get" data-type="role"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-sitemap me-1 text-muted"></i><span data-i18n="department">Department</span></label>
                    <select class="form-select select2-remote" id="employee_filter_department" data-api="/api/department.get" data-type="department"></select>
                </div>
                <!-- 2026-08-24, explicit request: "เพิ่ม Filter ทีมในหน้า list พนักงานด้วย" -->
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-people-group me-1 text-muted"></i><span data-i18n="team">Team</span></label>
                    <select class="form-select select2-remote" id="employee_filter_team" data-api="/api/team.get" data-type="team"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-clock me-1 text-muted"></i><span data-i18n="shift">Shift</span></label>
                    <select class="form-select select2-remote" id="employee_filter_shift" data-api="/api/shift.options" data-type="shift"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-code-branch me-1 text-muted"></i><span data-i18n="branch">Branch</span></label>
                    <select class="form-select select2-remote" id="employee_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                </div>
                <!-- 2026-08-30 (Phase 3, T022, explicit request: Tab/Filter จ่าย vs ไม่จ่ายเงินเดือน) --
                     static select2 (3 fixed options: All/Pays Salary/No Salary), same pattern as
                     Payroll Configuration's Calculation Method dropdown -- not a master table, this
                     is a closed 2-state toggle plus "All", not an open list. -->
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-money-check-dollar me-1 text-muted"></i><span data-i18n="payroll_participant_label">Payroll Participation</span></label>
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
    <div class="station-filter-clear-row d-none" id="employeeFilterClearRow">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeFilter">
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
                            <!-- 2026-08-31, explicit request: "ในตารางให้มีสัญลักษณ์บอกด้วยว่าจ่ายหรือไม่จ่าย
                                 เงินเดือน" -- inserted right after Status (index 16), before the existing
                                 orderable:false Completeness column; see EmployeeModel::list()'s own
                                 sortColumns map comment for why no other index needed to shift. -->
                            <th scope="col" data-i18n="payroll_participant_label">Payroll Participation</th>
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
    <div class="tab-pane fade" id="employee-recheck-top-pane" role="tabpanel" aria-labelledby="employee-recheck-top-tab" tabindex="0">
        <div class="station-filter" id="employeeRecheckStationFilter">
            <span class="station-filter-label" data-i18n="label_filter">Filter</span>
            <button type="button" class="station-filter-toggle" id="employeeRecheckStationFilterToggle" title="Toggle filter">
                <i class="fas fa-chevron-up"></i>
            </button>
            <div class="station-filter-body">
                <div class="row g-2">
                    <!-- 2026-09-08, explicit follow-up request ("อยู่ในระบบเงิน ควรขึ้นไปอยู่บน Filter เป็น
                         select") -- was a separate .btn-group row BELOW this filter card (see
                         2026-08-31's own comment on why it started as a toggle, not a new tab); moved
                         up into the filter row itself as a plain select2-static (same pattern as the
                         Employee tab's own "Payroll Participation" filter right above this pane) so it
                         reads as one more filter dimension instead of a separate control area.
                         data-option-values carries the real `participant`/`excluded` values
                         EmployeeModel::recheckList()'s own $participantMode expects -- the i18n KEYS
                         used for the label text are unrelated strings (recheck_view_in_payroll/
                         recheck_view_not_in_payroll), so this can't be left to submit the raw key. -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-eye me-1 text-muted"></i><span data-i18n="view">View</span></label>
                        <select class="form-select select2-static" id="employee_recheck_filter_view" data-option-keys="recheck_view_in_payroll,recheck_view_not_in_payroll" data-option-values="participant,excluded"></select>
                    </div>
                    <!-- 2026-09-12, Batch 4 item 4 -- Status/Employment Status/Position/Nationality/
                         Tax Method/Payment Method added; EmployeeModel::buildListWhere() (shared by
                         this tab and the main Employee tab's own list()) already supports
                         status/employment_status, and gained position_id/nationality/
                         tax_calculation_method/payment_method_id this same round -- see that method's
                         own comments. "All" needs the same `data-option-values` sentinel ('all',
                         mapped back to '' before hitting the backend) the Payroll Participation
                         filter above (main Employee tab) already established, since a genuinely
                         blank value in that attribute misaligns keys<->values by index (initSelect2's
                         own static-mode parsing, see that filter's own comment). Option keys reuse
                         the SAME canonical labels the real employee_status/employment_status fields
                         on Employee Detail already use (status_active/status_probation/etc. and
                         probation/permanent/contract/etc. respectively), not the shorter bare keys
                         the station-row pipeline cards use elsewhere on this page.
                         NOTE: a "Ready/Not Ready" (is_payroll_ready) filter was built here too but
                         pulled back OUT of the UI before commit -- buildListWhere()'s own `is_ready`
                         WHERE clause + tests/employee_recheck_filters_test.php stay in place
                         (backend-only, unused by any UI yet) until employees.is_payroll_ready itself
                         is trustworthy for sync-written employees (see BACKLOG.md). -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-toggle-on me-1 text-muted"></i><span data-i18n="status">Status</span></label>
                        <select class="form-select select2-static" id="employee_recheck_filter_status" data-option-keys="filter_all,status_active,status_probation,status_suspended,status_resigned,status_terminated" data-option-values="all,active,probation,suspended,resigned,terminated"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-id-badge me-1 text-muted"></i><span data-i18n="employment_status">Employment Status</span></label>
                        <select class="form-select select2-static" id="employee_recheck_filter_employment_status" data-option-keys="filter_all,probation,permanent,contract,resigned,terminated" data-option-values="all,probation,permanent,contract,resigned,terminated"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-sitemap me-1 text-muted"></i><span data-i18n="department">Department</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_department" data-api="/api/department.get" data-type="department"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-people-group me-1 text-muted"></i><span data-i18n="team">Team</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_team" data-api="/api/team.get" data-type="team"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-briefcase me-1 text-muted"></i><span data-i18n="position">Position</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_position" data-api="/api/position.get" data-type="position"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-code-branch me-1 text-muted"></i><span data-i18n="branch">Branch</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-clock me-1 text-muted"></i><span data-i18n="shift">Shift</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_shift" data-api="/api/shift.options" data-type="shift"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-user-tag me-1 text-muted"></i><span data-i18n="role">Role</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_role" data-api="/api/role.get" data-type="role"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-earth-asia me-1 text-muted"></i><span data-i18n="nationality">Nationality</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_nationality" data-api="/api/nationality.get" data-type="nationality"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-calculator me-1 text-muted"></i><span data-i18n="tax_calculation_method">Tax Calculation Method</span></label>
                        <select class="form-select select2-static" id="employee_recheck_filter_tax_calculation_method" data-option-keys="filter_all,average_method,actual_method" data-option-values="all,average,actual"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-credit-card me-1 text-muted"></i><span data-i18n="payment_type">Payment Type</span></label>
                        <select class="form-select select2-remote" id="employee_recheck_filter_payment_method" data-api="/api/payment-method.options"></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="station-filter-clear-row d-none" id="employeeRecheckFilterClearRow">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeRecheckFilter">
                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
            </button>
        </div>
        <!-- 2026-09-08, explicit follow-up request ("อยากให้ column รหัสพนักงาน และชื่อพนักงาน fixed อยู่กับที่
             ฝั่งซ้าย...และ column Action อยากให้ fixed อยู่ขวาตลอด ส่วน Column ส่วนกลางๆ อยากให้ใช้เมาส์เลื่อนดู
             ข้อมูลได้...ทดลองกับตารางนี้ก่อน แค่จะมีอีกหลายตารางที่ปรับให้เป็นรูปแบบนี้") -- reverts the
             2026-08-30 responsive:true/column-collapse choice back to a frozen-column layout.
             2026-09-08, round 2 follow-up ("ตอนนี้ใช้เมาส์เลื่อนเพื่อลากดู column ไม่ได้") -- the FIRST
             attempt used DataTables' own core `scrollX` option, which needs CSS this app never
             actually loads (`.dataTables_scrollBody { overflow-x:auto; }` etc. live in the BASE
             `datatables.net` skin's own stylesheet, never installed here -- confirmed by grepping the
             installed CSS directly, not guessed).
             2026-09-08, round 3 follow-up ("ยังใช้เมาส์เลื่อนส่วนของ body ไม่ได้...ปุ่มแสดง N รายการ
             pagination เลื่อนไปตามด้วย") -- round 2's fix wrapped the RAW `<table>` in `.table-responsive`
             right here in the static view, BEFORE DataTables ever initializes on it -- DataTables then
             builds its OWN length/search/info/pagination controls as siblings of the table INSIDE
             whatever the table's parent happens to be at init time, so all of those ended up nested
             inside this scrolling div too, scrolling along with the columns (exactly the 2nd complaint)
             -- and plain `overflow-x:auto` only ever supports scrollbar-drag/shift+wheel, not a genuine
             click-and-hold-then-drag gesture anywhere on the table body (the 1st complaint). There is
             NO static wrapper here anymore -- `public/js/employee/list.js`'s own
             `initEmployeeRecheckTable()` now does the wrapping itself, in `initComplete` (fires once,
             AFTER DataTables has already built its length/search/info/pagination controls as siblings
             of the table), so `.table-responsive` ends up wrapping ONLY the `<table>` element itself,
             never those controls -- and `initTableDragScroll()` (public/js/sticky-table-columns.js)
             adds the actual click-and-drag panning on top of that same wrapper (plain overflow-x:auto
             alone never supported that gesture, scrollbar-drag/shift+wheel only).
             `.recheck-fixedcols-table` (style.css) forces `white-space:nowrap` so the table is actually
             WIDER than its container (letting `.table-responsive` do its job) instead of Bootstrap's
             own default auto-shrink-to-fit hiding the overflow, and gives a short header label like
             "Actions" no reason to wrap onto 2 lines either.
             `initStickyColumns()` (plain CSS position:sticky on this SAME table's own cells -- no
             scrollHead/scrollBody split to juggle, this app never uses DataTables' own `scrollX`) does
             the actual column freezing; the `dtr-control` expand column from the old responsive:true
             layout is gone -- nothing left to expand, every column is reachable by scrolling instead. -->
        <table class="table table-striped table-hover recheck-fixedcols-table" id="tb_employee_recheck">
            <thead class="table-light text-secondary">
                <tr>
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
    </div>

    <!-- employeeSyncModal / employeeSyncLogModal moved to app/views/layout/modals.php
         (2026-08-30, modal consolidation). -->
</div>
<script src="<?=asset('public/js/employee/list.js')?>"></script>
<script src="<?=asset('public/js/employee/employee-sync.js')?>"></script>
