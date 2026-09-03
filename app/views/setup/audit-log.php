<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="audit_log_title">Audit Log</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="audit_log_title">Audit Log</h5>
            <p class="page-header-card-desc" data-i18n="audit_log_description">Field-level history of changes to Employee, Company Profile, and Payroll Configuration records.</p>
        </div>
    </div>

    <!-- Platform Hardening Phase 6 pilot: a plain top-level filter row, not per-column Excel
         filters -- see AuditLogController::list()'s own docblock for why this is an intentionally
         exempt table (ordering:false, ever-growing append-only log always sorted by time). -->
    <div class="row g-2 mb-3 align-items-end">
        <div class="col-sm-3">
            <label class="form-label small mb-1" data-i18n="audit_log_table">Table</label>
            <select id="filter_al_table_name" class="form-select form-select-sm">
                <option value="" data-i18n="audit_log_table_all">All Tables</option>
                <option value="employees" data-i18n="audit_log_table_employees">Employees</option>
                <option value="companies" data-i18n="audit_log_table_companies">Company Profile</option>
                <option value="payroll_earning_deduction_types" data-i18n="audit_log_table_payroll_earning_deduction_types">Earning/Deduction Types</option>
                <option value="company_payroll_policies" data-i18n="audit_log_table_company_payroll_policies">Payroll Policies</option>
                <option value="attendance_deduction_rules" data-i18n="audit_log_table_attendance_deduction_rules">Attendance Deduction Rules</option>
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1" data-i18n="audit_log_record_id">Record</label>
            <input type="number" id="filter_al_record_id" class="form-control form-control-sm" min="1">
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1" data-i18n="date_from">From</label>
            <input type="text" id="filter_al_date_from" class="form-control form-control-sm datepicker" autocomplete="off">
        </div>
        <div class="col-sm-2">
            <label class="form-label small mb-1" data-i18n="date_to">To</label>
            <input type="text" id="filter_al_date_to" class="form-control form-control-sm datepicker" autocomplete="off">
        </div>
        <div class="col-sm-2">
            <button type="button" id="btnAuditLogClearFilter" class="btn btn-outline-secondary btn-sm w-100 d-none" data-i18n="clear_filter">Clear Filter</button>
        </div>
    </div>

    <div class="card-surface">
        <div class="table-responsive">
            <table id="tb_audit_log" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th data-i18n="audit_log_performed_at">When</th>
                        <th data-i18n="audit_log_table">Table</th>
                        <th data-i18n="audit_log_record_id">Record</th>
                        <th data-i18n="audit_log_action">Action</th>
                        <th data-i18n="audit_log_field">Field</th>
                        <th data-i18n="audit_log_old_value">Old Value</th>
                        <th data-i18n="audit_log_new_value">New Value</th>
                        <th data-i18n="audit_log_performed_by">By</th>
                        <th data-i18n="audit_log_source">Source</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<script src="<?=BASE_URL?>/public/js/setup/audit-log.js"></script>
