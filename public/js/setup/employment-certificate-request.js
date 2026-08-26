/**
 * Employment Certificate Requests -- 2026-08-26, explicit request: "ในส่วนของ Request เพิ่ม Tab
 * สำหรับการ Request ใบรับรองขึ้นมาด้วยคู่กับ Pay slip และการอนุมัติให้เป็นรูปแบบเดียวกับ Approve Process
 * ครับมี timeline ให้กดดู". Direct port of payslip-request.js's own structure -- HR submits a
 * request on behalf of an employee (same "no employee self-service portal yet" precedent), Approve/
 * Reject/Cancel + the timeline view reuse the SAME shared `#requestDetailModal` Payslip Requests
 * already uses (see approval-request-detail.js) rather than duplicating it.
 *
 * The one real difference from Payslip Requests: once approved, the request also auto-generates the
 * actual PDF (EmploymentCertificateRequestModel::syncFromApprovalStatus() -> issuePdf()) -- so this
 * tab's own status column has extra 'issued'/'issue_failed' states Payslip's doesn't, and its own
 * action column gets a Download button once issued.
 */
let tb_ecr_request;

function escapeHtmlEcr(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

function ecrRequestStatusBadge(status) {
    const map = {
        pending: { cls: 'bg-warning-subtle text-warning', key: 'status_pending', fallback: 'Pending' },
        approved: { cls: 'bg-info-subtle text-info', key: 'status_approved', fallback: 'Approved' },
        rejected: { cls: 'bg-danger-subtle text-danger', key: 'status_rejected', fallback: 'Rejected' },
        cancelled: { cls: 'bg-secondary-subtle text-secondary', key: 'cancelled', fallback: 'Cancelled' },
        issued: { cls: 'bg-success-subtle text-success', key: 'ecr_status_issued', fallback: 'Issued' },
        issue_failed: { cls: 'bg-danger-subtle text-danger', key: 'ecr_status_issue_failed', fallback: 'Issue Failed' }
    };
    const m = map[status] || { cls: 'bg-secondary-subtle text-secondary', key: '', fallback: status };
    return `<span class="badge ${m.cls}">${langData[m.key] || m.fallback}</span>`;
}

function ecrLanguageLabel(lang) {
    return lang === 'en' ? (langData['template_language_en'] || 'English') : (langData['template_language_th'] || 'Thai');
}

function initEcrRequestTable() {
    if ($.fn.DataTable.isDataTable('#tb_ecr_request')) {
        $('#tb_ecr_request').DataTable().ajax.reload(null, false);
        return;
    }
    tb_ecr_request = $('#tb_ecr_request').DataTable({
        responsive: true,
        ajax: { url: `${BASE_URL}/api/employment-certificate-request.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<strong class="text-dark">${escapeHtmlEcr(row.employee_no)} - ${escapeHtmlEcr(currentLang === 'th' ? row.employee_name_th : row.employee_name_en)}</strong>` },
            { data: null, render: (d, t, row) => ecrLanguageLabel(row.language) },
            { data: null, render: (d, t, row) => escapeHtmlEcr((currentLang === 'th' ? row.requested_by_name_th : row.requested_by_name_en) || '-') },
            { data: 'status', render: d => ecrRequestStatusBadge(d) },
            // object-form render (display only) -- client-side table, defaults to sorting by this
            // exact column (order: [[4,'desc']] below), see reports/index.js's own comment for why
            // 'sort'/'filter' must stay on the raw ISO string.
            { data: 'created_at', render: { display: d => formatDisplayDateTime(d), sort: d => d, filter: d => d } },
            {
                data: null, orderable: false, className: 'text-center',
                render: (d, t, row) => {
                    let html = '';
                    if (row.approval_request_id) {
                        html += `<button type="button" class="btn btn-sm btn-outline-secondary btn-view-ecr-request" data-id="${row.approval_request_id}" title="${langData['approval_request_detail'] || 'Request Detail'}"><i class="fa-solid fa-eye"></i></button> `;
                    }
                    if (row.status === 'issued') {
                        html += `<a class="btn btn-sm btn-outline-success" href="${BASE_URL}/api/employment-certificate-request.download?id=${row.id}" target="_blank" title="${langData['download'] || 'Download'}"><i class="fa-solid fa-download"></i></a>`;
                    }
                    return html;
                }
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[4, 'desc']],
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-ecr').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-ecr">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="request_employment_certificate">${langData['request_employment_certificate'] || 'Request Employment Certificate'}</span>
                    </button>
                `);
            }
        }
    });
}

function resetEcrRequestForm() {
    $('#ecrRequestForm')[0].reset();
    $('#ecr_employee').val(null).trigger('change');
    $('#ecr_language').val('th').trigger('change');
}

$(document).on('click', '.btn-add-ecr', function () {
    resetEcrRequestForm();
    new bootstrap.Modal(document.getElementById('ecrRequestModal')).show();
});

$(document).on('submit', '#ecrRequestForm', function (e) {
    e.preventDefault();
    const employeeId = $('#ecr_employee').val();
    const language = $('#ecr_language').val();
    if (!employeeId || !language) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-request.create`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ employee_id: employeeId, language: language }),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('ecrRequestModal')).hide();
                tb_ecr_request.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

/* ---------- Request detail (shared modal, see approval-request-detail.js) ---------- */
$(document).on('click', '.btn-view-ecr-request', function () {
    openApprovalRequestDetail($(this).data('id'), () => { if (tb_ecr_request) tb_ecr_request.ajax.reload(null, false); });
});

$(document).ready(function () {
    if ($('#tb_ecr_request').length === 0) return;
    // 2026-08-26: unlike Payslip Requests (the default-active tab on this page), this tab starts
    // HIDDEN -- initializing a `responsive: true` DataTable while its tab-pane is display:none
    // collapses every column to 0 width (the same DataTables-in-a-Bootstrap-tab gotcha every other
    // hidden-tab table in this app already works around), so init ONLY on shown.bs.tab, not here.
    $('#ecrRequestTabBtn').on('shown.bs.tab', function () {
        initEcrRequestTable();
    });
});
