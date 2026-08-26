/**
 * Document Delivery / Issuance Log — Payslip & Documents > "Delivery Log" tab. Read-only audit
 * trail, UNIFIED across Payslip sends (payslip_delivery_logs) AND Employment Certificate
 * issuances (employment_certificate_requests) via DocumentDeliveryLogModel -- 2026-08-26, explicit
 * request: "ปรับ Filter ให้เหมือนหน้าพนักงาน และมีเพิ่มประเภทเอกสารที่ส่งด้วยครับ" (make the filter look
 * like the Employee page's, and add a document-type filter). The filter box itself is a direct port
 * of Employee List's own collapsible `.station-filter` (see public/js/employee/list.js) instead of
 * the old plain row of 3 dropdowns.
 *
 * A row's own `id` belongs to a DIFFERENT source table depending on `document_type` -- a payslip
 * row's `id` is a payslip_delivery_logs.id (Resend uses it against api/payslip-delivery-log.resend,
 * unchanged), an employment_certificate row's `id` is an employment_certificate_requests.id
 * (Download reuses api/employment-certificate-request.download?id=, same endpoint the Requests tab
 * already uses). Never assume both branches share one id space.
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

function docTypeLabelDlog(documentType) {
    return documentType === 'employment_certificate'
        ? (langData['doc_type_employment_certificate'] || 'Employment Certificate')
        : (langData['doc_type_payslip'] || 'Payslip');
}

function docLanguageLabelDlog(lang) {
    return lang === 'en' ? (langData['template_language_en'] || 'English') : (langData['template_language_th'] || 'Thai');
}

function formatReferenceDlog(row) {
    if (row.document_type === 'employment_certificate') {
        return `<span class="text-secondary small">${docLanguageLabelDlog(row.language)}</span>`;
    }
    if (!row.period_start_date || !row.period_end_date) return escapeHtmlDlog(row.reference_label);
    return `${escapeHtmlDlog(row.reference_label)} <span class="text-secondary small">(${row.period_start_date} - ${row.period_end_date})</span>`;
}

function initPayslipDeliveryLogTable() {
    if ($.fn.DataTable.isDataTable('#tb_payslip_delivery_log')) {
        $('#tb_payslip_delivery_log').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payslip_delivery_log = $('#tb_payslip_delivery_log').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/document-delivery-log.list`,
            dataSrc: 'data',
            data: function (d) {
                d.document_type = $('#dlog_filter_document_type').val() || '';
                d.status = $('#dlog_filter_status').val() || '';
                d.channel_code = $('#dlog_filter_channel').val() || '';
                d.source = $('#dlog_filter_source').val() || '';
            }
        },
        columns: [
            { data: null, render: (d, t, row) => `${escapeHtmlDlog(row.employee_no)} - ${escapeHtmlDlog(currentLang === 'th' ? row.employee_name_th : row.employee_name_en)}` },
            { data: 'document_type', render: d => docTypeLabelDlog(d) },
            { data: null, render: (d, t, row) => formatReferenceDlog(row) },
            { data: 'source', render: d => sourceLabel(d) },
            { data: 'channel_code', render: d => d ? escapeHtmlDlog(d.toUpperCase()) : '-' },
            { data: 'recipient', render: d => escapeHtmlDlog(d || '-') },
            { data: 'status', render: d => deliveryStatusBadge(d) },
            { data: 'sent_at' },
            { data: null, render: (d, t, row) => escapeHtmlDlog((currentLang === 'th' ? row.sent_by_name_th : row.sent_by_name_en) || '-') },
            {
                data: null, orderable: false, className: 'text-center',
                render: (d, t, row) => {
                    if (row.document_type === 'employment_certificate') {
                        return row.status === 'success'
                            ? `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/api/employment-certificate-request.download?id=${row.id}" target="_blank" title="${langData['download'] || 'Download'}"><i class="fa-solid fa-download"></i></a>`
                            : '';
                    }
                    return row.status === 'failed'
                        ? `<button type="button" class="btn btn-sm btn-outline-secondary btn-resend-dlog" data-id="${row.id}"><i class="fa-solid fa-rotate-right"></i></button>`
                        : '';
                }
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[7, 'desc']]
    });
}

function updateClearDlogFilterVisibility() {
    const hasFilter = !!($('#dlog_filter_document_type').val() || $('#dlog_filter_status').val() || $('#dlog_filter_channel').val() || $('#dlog_filter_source').val());
    $('#btnClearDlogFilter').toggleClass('d-none', !hasFilter);
}

$(document).on('click', '#dlogStationFilterToggle', function () {
    const $filter = $('#dlogStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});

$(document).on('change', '#dlog_filter_document_type, #dlog_filter_status, #dlog_filter_channel, #dlog_filter_source', function () {
    updateClearDlogFilterVisibility();
    if (tb_payslip_delivery_log) tb_payslip_delivery_log.ajax.reload(null, true);
});

$(document).on('click', '#btnClearDlogFilter', function () {
    $('#dlog_filter_document_type, #dlog_filter_status, #dlog_filter_channel, #dlog_filter_source').val(null).trigger('change.select2');
    updateClearDlogFilterVisibility();
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
