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

    <!-- 2026-09-07, explicit report: "/payroll/audit-log filter ไม่เหมือนเพื่อน และส่วนของตารางตัด
         card-surface ออก" -- was a plain always-open `.row` bar with a bare native `<select>`
         (violates this app's own "every dropdown uses Select2" rule) -- now the same collapsible
         `.station-filter` component every other list page in this app already uses (Employee List/
         Payroll Process/Notifications/...), same collapse-toggle + "Clear Filter" row shape. Still
         Platform Hardening Phase 6 pilot's own top-level (not per-column) filter -- see
         AuditLogController::list()'s own docblock for why this table is intentionally exempt from
         per-column Excel filters (ordering:false, an ever-growing append-only log always sorted by
         time). -->
    <div class="station-filter" id="auditLogStationFilter">
        <span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="auditLogStationFilterToggle" title="Toggle filter">
            <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
            <div class="row g-2">
                <div class="col-6 col-md-4 col-lg-3">
                    <label class="form-label mb-1"><i class="fa-solid fa-table me-1 text-muted"></i><span data-i18n="audit_log_table">Table</span></label>
                    <select id="filter_al_table_name" class="form-select select2-static"
                            data-option-keys="audit_log_table_all,audit_log_table_employees,audit_log_table_companies,audit_log_table_payroll_earning_deduction_types,audit_log_table_company_payroll_policies,audit_log_table_attendance_deduction_rules"
                            data-option-values="all,employees,companies,payroll_earning_deduction_types,company_payroll_policies,attendance_deduction_rules"></select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-hashtag me-1 text-muted"></i><span data-i18n="audit_log_record_id">Record</span></label>
                    <input type="number" id="filter_al_record_id" class="form-control" min="1">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="date_from">From</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_al_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="date_to">To</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_al_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="station-filter-clear-row d-none" id="auditLogFilterClearRow">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAuditLogClearFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
    </div>

    <!-- 2026-09-07: card-surface wrapper removed (explicit request) -- the table now sits directly
         on the page, same as every OTHER list page's own table (Employee List/Payroll Process/...
         none of them wrap their <table> in a card-surface either; this was the one outlier). -->
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
<script src="<?=asset('public/js/setup/audit-log.js')?>"></script>
