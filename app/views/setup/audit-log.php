<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Reports', 'href' => null, 'i18n' => 'reports'],
        ['label' => 'Audit Log', 'href' => null, 'i18n' => 'audit_log_menu'],
    ];
    $description = 'Field-level history of changes to Employee, Company Profile, and Payroll Configuration records.';
    $description_i18n = 'audit_log_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <!-- 2026-09-07, explicit report: "/payroll/audit-log filter ไม่เหมือนเพื่อน และส่วนของตารางตัด
         card-surface ออก" -- was a plain always-open `.row` bar with a bare native `<select>`
         (violates this app's own "every dropdown uses Select2" rule) -- now the shared
         filter-bar component every other list page in this app already uses (Employee List/
         Payroll Process/Notifications/...), same collapse-toggle + "Clear Filter" row shape. Still
         Platform Hardening Phase 6 pilot's own top-level (not per-column) filter -- see
         AuditLogController::list()'s own docblock for why this table is intentionally exempt from
         per-column Excel filters (ordering:false, an ever-growing append-only log always sorted by
         time). -->
    <?php ob_start(); ?>
    <div class="row g-2">
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small mb-1" for="filter_al_table_name" data-i18n="audit_log_table">Table</label>
            <select id="filter_al_table_name" class="form-select select2-static"
                    data-option-keys="audit_log_table_all,audit_log_table_employees,audit_log_table_companies,audit_log_table_payroll_earning_deduction_types,audit_log_table_company_payroll_policies,audit_log_table_attendance_deduction_rules"
                    data-option-values="all,employees,companies,payroll_earning_deduction_types,company_payroll_policies,attendance_deduction_rules"></select>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small mb-1" for="filter_al_record_id" data-i18n="audit_log_record_id">Record</label>
            <input type="number" id="filter_al_record_id" class="form-control" min="1">
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small mb-1" for="filter_al_date_from" data-i18n="date_from">From</label>
            <input type="text" class="form-control datepicker" id="filter_al_date_from" autocomplete="off">
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small mb-1" for="filter_al_date_to" data-i18n="date_to">To</label>
            <input type="text" class="form-control datepicker" id="filter_al_date_to" autocomplete="off">
        </div>
    </div>
    <?php
    $filter_fields_html = ob_get_clean();
    $id = 'auditLogFilterBar';
    include __DIR__ . '/../partials/filter-bar.php';
    ?>

    <!-- 2026-09-07: card-surface wrapper removed (explicit request) -- the table now sits directly
         on the page, same as every OTHER list page's own table (Employee List/Payroll Process/...
         none of them wrap their <table> in a card-surface either; this was the one outlier). -->
    <div class="table-responsive">
        <table id="tb_audit_log" class="table table-hover align-middle w-100">
            <thead>
                <tr>
                    <th><span data-i18n="audit_log_performed_at">When</span></th>
                    <th><span data-i18n="audit_log_table">Table</span></th>
                    <th><span data-i18n="audit_log_record_id">Record</span></th>
                    <th><span data-i18n="audit_log_action">Action</span></th>
                    <th><span data-i18n="audit_log_field">Field</span></th>
                    <th><span data-i18n="audit_log_old_value">Old Value</span></th>
                    <th><span data-i18n="audit_log_new_value">New Value</span></th>
                    <th><span data-i18n="audit_log_performed_by">By</span></th>
                    <th><span data-i18n="audit_log_source">Source</span></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<script src="<?=asset('public/js/setup/audit-log.js')?>"></script>
