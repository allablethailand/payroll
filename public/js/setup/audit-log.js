/**
 * Platform Hardening Phase 6 pilot -- Audit Log viewer. Server-side DataTable, ordering:false with
 * a top-level filter bar instead of per-column Excel filters -- same intentionally-exempt category
 * (an ever-growing append-only log, always sorted newest-first, has no real "sort by other column"
 * use case) CLAUDE.md's own Table convention already documents for Login History.
 */
function toIsoDateAl(displayValue) {
    if (!displayValue) return '';
    const parts = String(displayValue).split('/');
    if (parts.length !== 3) return displayValue;
    return `${parts[2]}-${parts[1]}-${parts[0]}`;
}
function auditLogTableLabel(tableName) {
    return langData['audit_log_table_' + tableName] || tableName;
}
function auditLogActionBadge(action) {
    const map = {
        create: { key: 'audit_log_action_create', cls: 'bg-success-subtle text-success' },
        update: { key: 'audit_log_action_update', cls: 'bg-primary-subtle text-primary' },
        delete: { key: 'audit_log_action_delete', cls: 'bg-danger-subtle text-danger' },
    };
    const m = map[action] || { key: action, cls: 'bg-secondary-subtle text-secondary' };
    return `<span class="badge ${m.cls}">${escapeAttr(langData[m.key] || action)}</span>`;
}
function auditLogByLabel(row) {
    const nameTh = [row.performed_by_name_th, row.performed_by_surname_th].filter(Boolean).join(' ');
    const nameEn = [row.performed_by_name_en, row.performed_by_surname_en].filter(Boolean).join(' ');
    return (typeof currentLang !== 'undefined' && currentLang === 'en' ? nameEn : nameTh) || nameTh || nameEn || '-';
}
function auditLogValueCell(value) {
    if (value === null || value === undefined || value === '') return '<span class="text-muted">-</span>';
    const truncated = String(value).length > 80 ? String(value).substring(0, 80) + '...' : String(value);
    return `<span title="${escapeAttr(value)}">${escapeAttr(truncated)}</span>`;
}
let dtAuditLog = null;
// 2026-09-07 -- `filter_al_table_name` is now select2-static ("All Tables" can't be a genuinely
// blank `data-option-values` entry, see employee/list.js's own comment on this same static-select2
// gotcha), so its "no filter" value is the literal string 'all', remapped to '' here before it ever
// reaches the backend.
function auditLogTableFilterValue() {
    const v = $('#filter_al_table_name').val();
    return (!v || v === 'all') ? '' : v;
}
function updateAuditLogClearFilterVisibility() {
    const active = !!(auditLogTableFilterValue() || $('#filter_al_record_id').val() || $('#filter_al_date_from').val() || $('#filter_al_date_to').val());
    $('#auditLogFilterClearRow').toggleClass('d-none', !active);
}
function renderAuditLogTable() {
    if ($.fn.DataTable.isDataTable('#tb_audit_log')) { dtAuditLog.ajax.reload(null, false); return; }
    dtAuditLog = $('#tb_audit_log').DataTable({
        serverSide: true,
        processing: true,
        ordering: false,
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/audit-log.list`, method: 'GET',
            data: function (d) {
                d.table_name = auditLogTableFilterValue();
                d.record_id = $('#filter_al_record_id').val() || '';
                d.date_from = toIsoDateAl($('#filter_al_date_from').val());
                d.date_to = toIsoDateAl($('#filter_al_date_to').val());
            }
        },
        columns: [
            { data: 'performed_at', render: (v) => v ? String(v).replace('T', ' ').substring(0, 16) : '-' },
            { data: 'table_name', render: (v) => escapeAttr(auditLogTableLabel(v)) },
            { data: 'record_id' },
            { data: 'action', className: 'text-center', render: (v) => auditLogActionBadge(v) },
            { data: 'field_name', render: (v) => v ? escapeAttr(v) : '<span class="text-muted">-</span>' },
            { data: 'old_value', render: (v) => auditLogValueCell(v) },
            { data: 'new_value', render: (v) => auditLogValueCell(v) },
            { data: null, render: (d, t, row) => escapeAttr(auditLogByLabel(row)) },
            { data: 'source', render: (v) => escapeAttr(v || '-') },
        ],
        pageLength: pageLength, lengthMenu: lengthMenu,
        language: { ...getTableLang(), emptyTable: langData['no_data_found'] || 'No records found.' },
    });
}
$(document).on('click', '#auditLogStationFilterToggle', function () {
    const $filter = $('#auditLogStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    if (!$('#tb_audit_log').length) return;
    initDatepicker('#filter_al_date_from, #filter_al_date_to');
    if (typeof initSelect2 === 'function') initSelect2('#filter_al_table_name', { mode: 'static', selectedValue: 'all' });
    renderAuditLogTable();
    $('#filter_al_table_name, #filter_al_record_id').on('change input', function () {
        updateAuditLogClearFilterVisibility();
        dtAuditLog.ajax.reload();
    });
    $('#filter_al_date_from, #filter_al_date_to').on('changeDate', function () {
        updateAuditLogClearFilterVisibility();
        dtAuditLog.ajax.reload();
    });
    $('#btnAuditLogClearFilter').on('click', function () {
        $('#filter_al_table_name').val('all').trigger('change.select2');
        $('#filter_al_record_id').val('');
        $('#filter_al_date_from').val('').datepicker('update');
        $('#filter_al_date_to').val('').datepicker('update');
        updateAuditLogClearFilterVisibility();
        dtAuditLog.ajax.reload();
    });
    });
});
