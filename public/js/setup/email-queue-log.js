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
    return statusBadgeHtml(status === 'sent' || status === 'failed' ? status : 'pending', 'email_queue_status');
}

function currentEmailQueueFilters() {
    return {
        status: ($('#emailQueueFilterStatus').val() === 'all' ? '' : $('#emailQueueFilterStatus').val()) || '',
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
        tb_email_queue_log.ajax.reload(null, false);
        fetchEmailQueueSummary();
        return;
    }
    tb_email_queue_log = initSharedDataTable('#tb_email_queue_log', {
        columnFilters: {
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
        },
        dtOptions: {
            // A client-side table fed by ajax has no rows at construction time, so the helper's
            // row-count threshold would hide the search box; keep it on as before.
            searching: true,
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
            order: [[5, 'desc']],
        },
    });
    fetchEmailQueueSummary();
}

$(document).ready(function () {
    initFilterBar('#emailQueueFilterBar', {
        onChange: function () {
            if (tb_email_queue_log) { tb_email_queue_log.ajax.reload(null, true); fetchEmailQueueSummary(); }
        },
    });
    // The bar listens to change only; the old filter reloaded on every keyup.
    $('#emailQueueFilterToAddress').on('keyup', function () { $(this).trigger('change'); });
    $('#emailQueueLogTabBtn').on('shown.bs.tab', function () {
        initEmailQueueLogTable();
    });
});
