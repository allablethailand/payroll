<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="employee">Employee</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-users-gear"></i>
            <span data-i18n="employee_management_title">Employee Management</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="employee_management_description">Configure and manage employee profiles, tax identifications, and bank accounts for payroll processing.</p>
    </div>
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs" id="employeeTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary active employee-status-tab" id="tab-emp-active" type="button" role="tab" aria-controls="employee" aria-selected="true" data-i18n="active" data-filter-status="active">Active</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary employee-status-tab" id="tab-emp-start-soon" type="button" role="tab" aria-controls="employee" aria-selected="false" data-i18n="start_soon" data-unsupported="true">Start Soon</button>
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
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary employee-status-tab" id="tab-emp-invite-accepted" type="button" role="tab" aria-controls="employee" aria-selected="false" data-i18n="invite_accepted" data-unsupported="true">Invite Accepted</button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-5" id="employeeTabsContent">
        <div class="tab-pane fade show active" id="employee-pane" role="tabpanel" aria-labelledby="employee-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-striped table-hover" id="tb_employee">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th></th>
                            <th scope="col" data-i18n="employee_no">Employee No.</th>
                            <th scope="col" data-i18n="name">Name</th>
                            <th scope="col" data-i18n="role">Role</th>
                            <th scope="col" data-i18n="department">Department</th>
                            <th scope="col" data-i18n="shift">Shift</th>
                            <th scope="col" data-i18n="branch">Branch</th>
                            <th scope="col" data-i18n="start_work_date">Start Work Date</th>
                            <th scope="col" data-i18n="status">Status</th>
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