<style>
/* .station-filter-body's shared max-height (200px, see style.css) fits the Payroll Process page's
   2-field date filter but not this page's 6-field row, which wraps to 3 rows on narrow screens --
   raise it here only. Both the expanded and collapsed variants must be scoped to this page's own
   id so the collapse-to-0 animation (the more specific .collapsed rule) still wins over this. */
#employeeStationFilter .station-filter-body { max-height: 320px; }
#employeeStationFilter.collapsed .station-filter-body { max-height: 0; }

#tb_employee tbody tr { transition: background-color .12s ease; }
.employee-completeness-bar { min-width: 100px; }
.employee-completeness-bar .progress { height: 6px; background-color: #eef0f2; }
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
                            <th></th>
                            <th scope="col" data-i18n="employee_no">Employee No.</th>
                            <th scope="col" data-i18n="name">Name</th>
                            <th scope="col" data-i18n="mobile_no">Mobile No.</th>
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
<script src="<?=asset('public/js/employee/list.js')?>"></script>