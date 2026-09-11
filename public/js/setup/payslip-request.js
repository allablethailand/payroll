/**
 * Payslip Requests (Mode B) — Payslip Tracking > "Payslip Requests" tab.
 * HR submits a request on behalf of an employee (no employee self-service portal exists in this
 * codebase yet). Approve/Reject/Cancel reuse the generic approval engine
 * (`/api/approval-request.get|logs|act`) via the SHARED `#requestDetailModal` (2026-08-26: moved to
 * `approval-request-detail.js` so the new Employment Certificate Requests tab on this same page can
 * reuse the exact same modal/timeline instead of duplicating it -- see that file's own docblock).
 * This used to be the standalone "Monitor" tab (removed) which covered every document type, but
 * SLIP_REQUEST_APPROVAL was the only document type actually wired to the engine at the time, so the
 * detail/act UI moved here instead of being dropped.
 */
let tb_payslip_request;


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
    if (!row.period_start_date || !row.period_end_date) return escapeAttr(row.run_name);
    return `${escapeAttr(row.run_name)} <span class="text-secondary small">(${formatDisplayDate(row.period_start_date)} - ${formatDisplayDate(row.period_end_date)})</span>`;
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
            { data: null, render: (d, t, row) => `<strong class="text-dark">${escapeAttr(row.employee_no)} - ${escapeAttr(currentLang === 'th' ? row.employee_name_th : row.employee_name_en)}</strong>` },
            { data: null, render: (d, t, row) => formatPayPeriod(row) },
            { data: null, render: (d, t, row) => escapeAttr((currentLang === 'th' ? row.requested_by_name_th : row.requested_by_name_en) || '-') },
            { data: 'status', render: d => payslipRequestStatusBadge(d) },
            // object-form render (display only) -- client-side table, defaults to sorting by this
            // exact column (order: [[4,'desc']] below), see reports/index.js's own comment for why
            // 'sort'/'filter' must stay on the raw ISO string.
            { data: 'created_at', render: { display: d => formatDisplayDateTime(d), sort: d => d, filter: d => d } },
            {
                // 2026-08-28: className:'all' keeps this last actions column from collapsing into
                // the Responsive expand row.
                data: null, orderable: false, className: 'text-center all',
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
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-pr').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-pr">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="request_payslip">${langData['request_payslip'] || 'Request Payslip'}</span>
                    </button>
                `);
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode (this table already loads its full dataset into the browser).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'employee' },
                    { index: 1, key: 'pay_period' },
                    { index: 2, key: 'requested_by' },
                    { index: 3, key: 'status' },
                    { index: 4, key: 'created_at' },
                ]
            });
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
    // 2026-09-02, Platform Hardening Phase 1.2 (tier-2 loading state) -- this had NO disable/spinner
    // at all before, not even a plain disable (same gap as structure-assign.js's Pull In/Move Out).
    const $btn = $(this).find('[type="submit"]');
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payslip-request.create`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ run_id: runId, employee_id: employeeId }),
        dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('payslipRequestModal')).hide();
                tb_payslip_request.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

/* ---------- Request detail / approve / reject / cancel -- shared modal, see
   approval-request-detail.js for openApprovalRequestDetail()/the approve-reject-cancel handlers. ---------- */
$(document).on('click', '.btn-view-payslip-request', function () {
    openApprovalRequestDetail($(this).data('id'), () => { if (tb_payslip_request) tb_payslip_request.ajax.reload(null, false); });
});

$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    initPayslipRequestTable();
    $('#payslipRequestTabBtn').on('shown.bs.tab', function () {
        initPayslipRequestTable();
    });
    });
});
