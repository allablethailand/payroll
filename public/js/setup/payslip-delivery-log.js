/**
 * Payslip Delivery Log — Document & Approval > "Delivery Log" tab. Read-only audit trail
 * (payslip_delivery_logs) with a Resend action on failed attempts. Resend retries the FULL
 * fallback chain again, not just the one channel that failed (see PayslipDeliveryService::resend()).
 */
let tb_payslip_delivery_log;

function escapeHtmlDlog(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}

function deliveryStatusBadge(status) {
    if (status === 'success') {
        return `<span class="badge bg-success-subtle text-success">${langData['status_sent'] || 'Sent'}</span>`;
    }
    return `<span class="badge bg-danger-subtle text-danger">${langData['status_send_failed'] || 'Send Failed'}</span>`;
}

function sourceLabel(source) {
    return source === 'auto' ? (langData['source_auto'] || 'Auto-send') : (langData['source_request'] || 'Request');
}

function formatPayPeriodDlog(row) {
    if (!row.period_start_date || !row.period_end_date) return escapeHtmlDlog(row.run_name);
    return `${escapeHtmlDlog(row.run_name)} <span class="text-secondary small">(${row.period_start_date} - ${row.period_end_date})</span>`;
}

function initPayslipDeliveryLogTable() {
    if ($.fn.DataTable.isDataTable('#tb_payslip_delivery_log')) {
        $('#tb_payslip_delivery_log').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payslip_delivery_log = $('#tb_payslip_delivery_log').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/payslip-delivery-log.list`,
            dataSrc: 'data',
            data: function (d) {
                d.status = $('#dlog_filter_status').val() || '';
                d.channel_code = $('#dlog_filter_channel').val() || '';
                d.source = $('#dlog_filter_source').val() || '';
            }
        },
        columns: [
            { data: null, render: (d, t, row) => `${escapeHtmlDlog(row.employee_no)} - ${escapeHtmlDlog(currentLang === 'th' ? row.employee_name_th : row.employee_name_en)}` },
            { data: null, render: (d, t, row) => formatPayPeriodDlog(row) },
            { data: 'source', render: d => sourceLabel(d) },
            { data: 'channel_code', render: d => escapeHtmlDlog((d || '').toUpperCase()) },
            { data: 'recipient', render: d => escapeHtmlDlog(d || '-') },
            { data: 'status', render: d => deliveryStatusBadge(d) },
            { data: 'sent_at' },
            { data: null, render: (d, t, row) => escapeHtmlDlog((currentLang === 'th' ? row.sent_by_name_th : row.sent_by_name_en) || '-') },
            {
                data: null, orderable: false, className: 'text-center',
                render: (d, t, row) => row.status === 'failed'
                    ? `<button type="button" class="btn btn-sm btn-outline-secondary btn-resend-dlog" data-id="${row.id}"><i class="fa-solid fa-rotate-right"></i></button>`
                    : ''
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[6, 'desc']]
    });
}

$(document).on('change', '#dlog_filter_status, #dlog_filter_channel, #dlog_filter_source', function () {
    if (tb_payslip_delivery_log) tb_payslip_delivery_log.ajax.reload(null, true);
});

$(document).on('click', '.btn-resend-dlog', function () {
    const id = $(this).data('id');
    const title = langData['confirm_resend_title'] || 'Resend Payslip?';
    const message = langData['confirm_resend_message'] || 'This will retry delivery through the full fallback chain again.';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/payslip-delivery-log.resend`,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id }),
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['resend_success'] || 'Resend attempted.');
                } else {
                    showWarning(res.message || langData['resend_failed'] || 'Resend failed.');
                }
                tb_payslip_delivery_log.ajax.reload(null, false);
            },
            error: function () { showWarning(langData['resend_failed'] || 'Resend failed.'); }
        });
    });
});

$(document).ready(function () {
    $('#payslipDeliveryLogTabBtn').on('shown.bs.tab', function () {
        initPayslipDeliveryLogTable();
    });
});
