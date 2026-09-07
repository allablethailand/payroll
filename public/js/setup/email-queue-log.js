/**
 * Email Queue Log -- Document & Approval > "Email Log" tab. Read-only admin view over the
 * email_queue table built in Phase 7 (T040, see EmailQueueModel's own docblock) -- 2026-08-31,
 * explicit request: "สร้าง Cronjob สำหรับการส่งอีเมล และเพิ่มหน้าให้ดู Log การส่งได้ มี Filter และตาราง
 * รวมถึง Summary". Client-side DataTable (list() returns a plain capped-at-200 array, same
 * convention as PayslipDeliveryLogModel::list()) + 3 stat cards fed by a separate summary() call
 * using the SAME filters, so the cards always match whatever the table is currently showing.
 */
let tb_email_queue_log;


function emailQueueStatusBadge(status) {
    if (status === 'sent') {
        return `<span class="badge bg-success-subtle text-success">${langData['email_queue_status_sent'] || 'Sent'}</span>`;
    }
    if (status === 'failed') {
        return `<span class="badge bg-danger-subtle text-danger">${langData['email_queue_status_failed'] || 'Failed'}</span>`;
    }
    return `<span class="badge bg-secondary-subtle text-secondary">${langData['email_queue_status_pending'] || 'Pending'}</span>`;
}

function currentEmailQueueFilters() {
    return {
        status: $('#emailQueueFilterStatus').val() || '',
        date_from: toIsoDateEql($('#emailQueueFilterDateFrom').val()),
        date_to: toIsoDateEql($('#emailQueueFilterDateTo').val()),
        to_address: $('#emailQueueFilterToAddress').val() || ''
    };
}

// datepicker fields on this page store dd/mm/yyyy (this app's own display format, see CLAUDE.md's
// Date Display Format section) -- the backend filter needs YYYY-MM-DD.
function toIsoDateEql(displayVal) {
    if (!displayVal) return '';
    const parts = displayVal.split('/');
    if (parts.length !== 3) return '';
    return `${parts[2]}-${parts[1]}-${parts[0]}`;
}

function fetchEmailQueueSummary() {
    $.get(`${BASE_URL}/api/email-queue.summary`, currentEmailQueueFilters(), function (res) {
        if (!res.status) return;
        $('#emailQueueStatPending').text(res.data.pending);
        $('#emailQueueStatSent').text(res.data.sent);
        $('#emailQueueStatFailed').text(res.data.failed);
    }, 'json');
}

function initEmailQueueLogTable() {
    if ($.fn.DataTable.isDataTable('#tb_email_queue_log')) {
        $('#tb_email_queue_log').DataTable().ajax.reload(null, false);
        fetchEmailQueueSummary();
        return;
    }
    tb_email_queue_log = $('#tb_email_queue_log').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/email-queue.list`,
            dataSrc: 'data',
            data: function (d) { Object.assign(d, currentEmailQueueFilters()); }
        },
        columns: [
            { data: 'to_address', render: d => escapeAttr(d) },
            { data: 'subject', render: d => escapeAttr(d) },
            { data: 'status', render: d => emailQueueStatusBadge(d) },
            { data: 'attempts', className: 'text-center' },
            { data: 'error_message', render: d => d ? `<span class="text-danger small" title="${escapeAttr(d)}">${escapeAttr(d.length > 60 ? d.substring(0, 60) + '...' : d)}</span>` : '-' },
            // object-form render (display only) -- see payslip-delivery-log.js's own identical
            // comment on why: this table's default sort is by this column and has no serverSide:true.
            { data: 'created_at', render: { display: d => formatDisplayDateTime(d), sort: d => d, filter: d => d } },
            { data: 'sent_at', render: { display: d => d ? formatDisplayDateTime(d) : '-', sort: d => d || '', filter: d => d || '' } },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[5, 'desc']],
        initComplete: function () {
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'recipient' },
                    { index: 1, key: 'email_subject' },
                    { index: 2, key: 'status' },
                    { index: 3, key: 'email_attempts' },
                    { index: 4, key: 'email_error' },
                    { index: 5, key: 'created_at' },
                    { index: 6, key: 'email_sent_at' },
                ]
            });
        }
    });
    fetchEmailQueueSummary();
}

function updateClearEmailQueueFilterVisibility() {
    const f = currentEmailQueueFilters();
    const hasFilter = !!(f.status || f.date_from || f.date_to || f.to_address);
    $('#emailQueueFilterClearRow').toggleClass('d-none', !hasFilter);
}

$(document).on('click', '#emailQueueStationFilterToggle', function () {
    const $filter = $('#emailQueueStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});

$(document).on('change', '#emailQueueFilterStatus, #emailQueueFilterDateFrom, #emailQueueFilterDateTo', function () {
    updateClearEmailQueueFilterVisibility();
    if (tb_email_queue_log) { tb_email_queue_log.ajax.reload(null, true); fetchEmailQueueSummary(); }
});
$(document).on('keyup', '#emailQueueFilterToAddress', function () {
    updateClearEmailQueueFilterVisibility();
    if (tb_email_queue_log) { tb_email_queue_log.ajax.reload(null, true); fetchEmailQueueSummary(); }
});

$(document).on('click', '#btnClearEmailQueueFilter', function () {
    $('#emailQueueFilterStatus').val(null).trigger('change.select2');
    $('#emailQueueFilterDateFrom, #emailQueueFilterDateTo').val('');
    if (typeof $.fn.datepicker === 'function') $('#emailQueueFilterDateFrom, #emailQueueFilterDateTo').datepicker('update');
    $('#emailQueueFilterToAddress').val('');
    updateClearEmailQueueFilterVisibility();
    if (tb_email_queue_log) { tb_email_queue_log.ajax.reload(null, true); fetchEmailQueueSummary(); }
});

$(document).ready(function () {
    $('#emailQueueLogTabBtn').on('shown.bs.tab', function () {
        initEmailQueueLogTable();
    });
});
