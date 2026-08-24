/**
 * Payslip Requests (Mode B) — Payslip Tracking > "Payslip Requests" tab.
 * HR submits a request on behalf of an employee (no employee self-service portal exists in this
 * codebase yet). Approve/Reject/Cancel reuse the generic approval engine
 * (`/api/approval-request.get|logs|act`) via the requestDetailModal on this same page -- this
 * used to be the standalone "Monitor" tab (removed) which covered every document type, but
 * SLIP_REQUEST_APPROVAL is the only document type actually wired to the engine right now (see
 * ApprovalRequestModel docblock in CLAUDE.md), so the detail/act UI moved here instead of being
 * dropped.
 */
let tb_payslip_request;
let currentDetailRequestId = null;

function escapeHtmlPr(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}

function payslipRequestStatusBadge(status) {
    const map = {
        pending: { cls: 'bg-warning-subtle text-warning', key: 'status_pending', fallback: 'Pending' },
        approved: { cls: 'bg-success-subtle text-success', key: 'status_approved', fallback: 'Approved' },
        rejected: { cls: 'bg-danger-subtle text-danger', key: 'status_rejected', fallback: 'Rejected' },
        cancelled: { cls: 'bg-secondary-subtle text-secondary', key: 'cancelled', fallback: 'Cancelled' },
        sent: { cls: 'bg-success-subtle text-success', key: 'status_sent', fallback: 'Sent' },
        send_failed: { cls: 'bg-danger-subtle text-danger', key: 'status_send_failed', fallback: 'Send Failed' }
    };
    const m = map[status] || { cls: 'bg-secondary-subtle text-secondary', key: '', fallback: status };
    return `<span class="badge ${m.cls}">${langData[m.key] || m.fallback}</span>`;
}

function formatPayPeriod(row) {
    if (!row.period_start_date || !row.period_end_date) return escapeHtmlPr(row.run_name);
    return `${escapeHtmlPr(row.run_name)} <span class="text-secondary small">(${row.period_start_date} - ${row.period_end_date})</span>`;
}

function initPayslipRequestTable() {
    if ($.fn.DataTable.isDataTable('#tb_payslip_request')) {
        $('#tb_payslip_request').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payslip_request = $('#tb_payslip_request').DataTable({
        responsive: true,
        ajax: { url: `${BASE_URL}/api/payslip-request.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<strong class="text-dark">${escapeHtmlPr(row.employee_no)} - ${escapeHtmlPr(currentLang === 'th' ? row.employee_name_th : row.employee_name_en)}</strong>` },
            { data: null, render: (d, t, row) => formatPayPeriod(row) },
            { data: null, render: (d, t, row) => escapeHtmlPr((currentLang === 'th' ? row.requested_by_name_th : row.requested_by_name_en) || '-') },
            { data: 'status', render: d => payslipRequestStatusBadge(d) },
            { data: 'created_at' },
            {
                data: null, orderable: false, className: 'text-center',
                render: (d, t, row) => row.approval_request_id
                    ? `<button type="button" class="btn btn-sm btn-outline-secondary btn-view-payslip-request" data-id="${row.approval_request_id}"><i class="fa-solid fa-eye"></i></button>`
                    : ''
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        order: [[4, 'desc']],
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-pr').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-pr">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="request_payslip">${langData['request_payslip'] || 'Request Payslip'}</span>
                    </button>
                `);
            }
        }
    });
}

function resetPayslipRequestForm() {
    $('#payslipRequestForm')[0].reset();
    $('#pr_run').val(null).trigger('change');
    $('#pr_employee').empty().prop('disabled', true).trigger('change');
}

$(document).on('click', '.btn-add-pr', function () {
    resetPayslipRequestForm();
    new bootstrap.Modal(document.getElementById('payslipRequestModal')).show();
});

$(document).on('change', '#pr_run', function () {
    const runId = $(this).val();
    const $employee = $('#pr_employee');
    $employee.empty().trigger('change');
    if (!runId) {
        $employee.prop('disabled', true);
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payslip-request.employee-options`,
        method: 'POST',
        data: { run_id: runId },
        dataType: 'json',
        success: function (res) {
            const items = (res.data && res.data.items) || [];
            items.forEach(item => {
                const label = (currentLang === 'th' ? item.text_th : item.text_en) || item.text_th || item.text_en;
                $employee.append(new Option(label, item.id, false, false));
            });
            $employee.prop('disabled', items.length === 0).trigger('change');
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
});

$(document).on('submit', '#payslipRequestForm', function (e) {
    e.preventDefault();
    const runId = $('#pr_run').val();
    const employeeId = $('#pr_employee').val();
    if (!runId || !employeeId) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payslip-request.create`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ run_id: runId, employee_id: employeeId }),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('payslipRequestModal')).hide();
                tb_payslip_request.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

/* ---------- Request detail / approve / reject / cancel ---------- */
function renderRequestSummary(req) {
    const requesterName = (currentLang === 'th' ? req.requested_by_name_th : req.requested_by_name_en) || req.requested_by_name_th || req.requested_by_name_en || '-';
    const docTypeName = (currentLang === 'th' ? req.document_type_name_th : req.document_type_name_en) || req.document_type_name_th || req.document_type_name_en || '';
    $('#requestDetailSummary').html(`
        <div class="row g-2 small">
            <div class="col-sm-6"><strong>${langData['document_types'] || 'Document Type'}:</strong> ${escapeHtmlPr(docTypeName)}</div>
            <div class="col-sm-6"><strong>${langData['reference'] || 'Reference'}:</strong> ${escapeHtmlPr(req.reference_label || '-')}</div>
            <div class="col-sm-6"><strong>${langData['workflow_name'] || 'Workflow'}:</strong> ${escapeHtmlPr(req.workflow_name)}</div>
            <div class="col-sm-6"><strong>${langData['status'] || 'Status'}:</strong> ${payslipRequestStatusBadge(req.status)}</div>
            <div class="col-sm-6"><strong>${langData['requested_by'] || 'Requested By'}:</strong> ${escapeHtmlPr(requesterName)}</div>
            <div class="col-sm-6"><strong>${langData['requested_at'] || 'Requested At'}:</strong> ${escapeHtmlPr(req.requested_at)}</div>
        </div>
    `);
}

function renderRequestTimeline(logs) {
    const $wrap = $('#requestDetailTimeline').empty();
    if (logs.length === 0) {
        $wrap.append(`<div class="text-secondary small">${langData['no_history_yet'] || 'No action has been taken on this request yet.'}</div>`);
        return;
    }
    logs.forEach(l => {
        const actorName = (currentLang === 'th' ? l.acted_by_name_th : l.acted_by_name_en) || l.acted_by_name_th || l.acted_by_name_en || '-';
        const actionKey = { approve: 'approve', reject: 'reject', cancel: 'cancel_request' }[l.action] || l.action;
        $wrap.append(`
            <div class="border-start ps-3 pb-3" style="border-color:#dee2e6 !important;">
                <div class="small text-secondary">${escapeHtmlPr(l.acted_at)}</div>
                <div><strong>${escapeHtmlPr(l.step_name_snapshot || '')}</strong> — ${langData[actionKey] || l.action} (${escapeHtmlPr(actorName)})</div>
                ${l.note ? `<div class="small text-secondary">${escapeHtmlPr(l.note)}</div>` : ''}
            </div>
        `);
    });
}

function openRequestDetail(id) {
    currentDetailRequestId = id;
    $.ajax({
        url: `${BASE_URL}/api/approval-request.get`,
        method: 'GET',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            renderRequestSummary(res.data);
            $('#requestActionArea').toggleClass('d-none', res.data.status !== 'pending');
            $('#requestActionNote').val('');
            new bootstrap.Modal(document.getElementById('requestDetailModal')).show();
        }
    });
    $.ajax({
        url: `${BASE_URL}/api/approval-request.logs`,
        method: 'GET',
        data: { request_id: id },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                renderRequestTimeline(res.data);
            }
        }
    });
}

function actOnCurrentRequest(action) {
    if (!currentDetailRequestId) return;
    $.ajax({
        url: `${BASE_URL}/api/approval-request.act`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ request_id: currentDetailRequestId, action, note: $('#requestActionNote').val().trim() }),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Success.');
                openRequestDetail(currentDetailRequestId);
                if (tb_payslip_request) tb_payslip_request.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred.'); }
    });
}

$(document).on('click', '.btn-view-payslip-request', function () {
    openRequestDetail($(this).data('id'));
});
$(document).on('click', '#btnApproveRequest', function () {
    actOnCurrentRequest('approve');
});
$(document).on('click', '#btnRejectRequest', function () {
    showConfirm(langData['confirm_reject_request'] || 'Reject this request?', '', function () {
        actOnCurrentRequest('reject');
    });
});
$(document).on('click', '#btnCancelRequest', function () {
    showConfirm(langData['confirm_cancel_request'] || 'Cancel this request?', '', function () {
        actOnCurrentRequest('cancel');
    });
});

$(document).ready(function () {
    initPayslipRequestTable();
    $('#payslipRequestTabBtn').on('shown.bs.tab', function () {
        initPayslipRequestTable();
    });
});
