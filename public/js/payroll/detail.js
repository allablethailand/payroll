let tb_run_detail;
let tb_audit_log;
let currentRun = null;

function toIsoDateRd(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function toDisplayDateRd(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function escapeHtmlRd(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function fmtNumRd(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function stateBadgeRd(state) {
    const map = {
        draft: 'bg-secondary-subtle text-secondary',
        pending_approval: 'bg-warning-subtle text-warning',
        approved: 'bg-info-subtle text-info',
        paid: 'bg-success-subtle text-success',
        locked: 'bg-dark-subtle text-dark',
        rejected: 'bg-danger-subtle text-danger',
        cancelled: 'bg-dark-subtle text-muted',
    };
    const cls = map[state] || 'bg-light text-dark';
    const text = langData['state_' + state] || state;
    return `<span class="badge ${cls} fs-6">${text}</span>`;
}
function calcStatusBadgeRd(status) {
    const map = {
        pending: 'bg-secondary-subtle text-secondary',
        calculated: 'bg-success-subtle text-success',
        error: 'bg-danger-subtle text-danger',
    };
    const cls = map[status] || 'bg-light text-dark';
    const text = langData['calc_status_' + status] || status;
    return `<span class="badge ${cls}">${text}</span>`;
}
function employeeDisplayNameRd(row) {
    const name = currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`;
    return name.trim();
}
function personDisplayNameRd(row, prefix) {
    const th = row[prefix + '_name_th'];
    const en = row[prefix + '_name_en'];
    return (currentLang === 'th' ? th : en) || th || en || '-';
}

/* ---------- Action buttons per state ---------- */
function renderActionButtons(run) {
    const $wrap = $('#runActionButtons').empty();
    function addBtn(cls, icon, i18nKey, defaultLabel) {
        const $btn = $(`<button type="button" class="btn btn-sm ${cls}"><i class="fa-solid ${icon} me-1"></i><span data-i18n="${i18nKey}">${langData[i18nKey] || defaultLabel}</span></button>`);
        $wrap.append($btn);
        return $btn;
    }
    if (run.state === 'draft') {
        addBtn('btn-outline-secondary', 'fa-rotate', 'action_recalculate', 'Recalculate').attr('id', 'btnRecalculate');
        addBtn('btn-outline-secondary', 'fa-pen-to-square', 'action_edit', 'Edit').attr('id', 'btnEditRun');
        addBtn('btn-primary', 'fa-paper-plane', 'action_submit', 'Submit for Approval').attr('id', 'btnSubmitRun');
        addBtn('btn-outline-danger', 'fa-trash-alt', 'action_delete', 'Delete').attr('id', 'btnDeleteRun');
    } else if (run.state === 'pending_approval') {
        addBtn('btn-primary', 'fa-check', 'action_approve', 'Approve').attr('id', 'btnApproveRun');
        addBtn('btn-outline-danger', 'fa-xmark', 'action_reject', 'Reject').attr('id', 'btnRejectRun');
        addBtn('btn-outline-secondary', 'fa-rotate-left', 'action_revert', 'Send Back for Revision').attr('id', 'btnRevertRun');
    } else if (run.state === 'approved') {
        addBtn('btn-primary', 'fa-circle-check', 'action_mark_paid', 'Mark as Paid').attr('id', 'btnMarkPaidRun');
    } else if (run.state === 'paid') {
        addBtn('btn-primary', 'fa-lock', 'action_lock', 'Lock').attr('id', 'btnLockRun');
    } else if (run.state === 'rejected') {
        addBtn('btn-primary', 'fa-rotate', 'action_revise', 'Revise').attr('id', 'btnReviseRun');
    }
    // Cancellable any time before money has moved -- draft/pending_approval/approved/rejected --
    // matches PayrollRunModel::cancel()'s own allowed-state check exactly. Not shown for
    // paid/locked/cancelled.
    if (['draft', 'pending_approval', 'approved', 'rejected'].includes(run.state)) {
        addBtn('btn-outline-dark', 'fa-ban', 'action_cancel', 'Cancel').attr('id', 'btnCancelRun');
    }
}

function renderRunHeader(run) {
    currentRun = run;
    document.title = run.run_name;
    $('#bcRunName').text(run.run_name);
    $('#runNameHeading').text(run.run_name);
    $('#runStateBadge').html(stateBadgeRd(run.state));
    $('#infoCycle').text(run.cycle_name || '-');
    $('#infoPeriod').text(`${toDisplayDateRd(run.period_start_date)} - ${toDisplayDateRd(run.period_end_date)}`);
    $('#infoPaymentDate').text(toDisplayDateRd(run.payment_date));
    $('#infoEmployeeCount').text(run.employee_count);
    $('#infoGross').text(fmtNumRd(run.total_gross_amount));
    $('#infoDeduction').text(fmtNumRd(run.total_deduction_amount));
    $('#infoNet').text(fmtNumRd(run.total_net_amount));
    const creatorName = (currentLang === 'th' ? run.created_by_name_th : run.created_by_name_en) || run.created_by_name_th || run.created_by_name_en || '-';
    $('#infoCreatedBy').text(creatorName);

    if (run.state === 'rejected' && run.reject_reason) {
        $('#rejectReasonBox').removeClass('d-none').html(`<i class="fa-solid fa-circle-exclamation me-1"></i><strong>${langData['reject_reason_display'] || 'Reject Reason'}:</strong> ${escapeHtmlRd(run.reject_reason)}`);
    } else {
        $('#rejectReasonBox').addClass('d-none').html('');
    }
    if (run.state === 'cancelled' && run.cancel_reason) {
        $('#cancelReasonBox').removeClass('d-none').html(`<i class="fa-solid fa-ban me-1"></i><strong>${langData['cancel_reason_display'] || 'Cancel Reason'}:</strong> ${escapeHtmlRd(run.cancel_reason)}`);
    } else {
        $('#cancelReasonBox').addClass('d-none').html('');
    }

    if (Number(run.has_validation_errors) === 1) {
        const errCount = (run.details || []).filter(d => d.calc_status === 'error').length;
        const tpl = langData['validation_errors_banner'] || '{count} employee(s) have calculation errors. Recalculate and resolve them before submitting for approval.';
        $('#validationErrorsBanner').removeClass('d-none').text(tpl.replace('{count}', errCount));
    } else {
        $('#validationErrorsBanner').addClass('d-none');
    }

    renderActionButtons(run);
}

function initRunDetailTable(details) {
    $('#noDetailsYet').toggleClass('d-none', details.length > 0);
    $('#tb_run_detail').toggleClass('d-none', details.length === 0);
    if ($.fn.DataTable.isDataTable('#tb_run_detail')) {
        $('#tb_run_detail').DataTable().clear().rows.add(details).draw();
        return;
    }
    tb_run_detail = $('#tb_run_detail').DataTable({
        responsive: true,
        data: details,
        columns: [
            { data: 'employee_no' },
            { data: null, render: (d, t, row) => escapeHtmlRd(employeeDisplayNameRd(row)) },
            { data: 'base_salary_amount', className: 'text-end', render: d => fmtNumRd(d) },
            { data: 'gross_amount', className: 'text-end', render: d => fmtNumRd(d) },
            { data: 'total_deduction_amount', className: 'text-end', render: d => fmtNumRd(d) },
            { data: 'net_amount', className: 'text-end fw-bold', render: d => fmtNumRd(d) },
            { data: 'calc_status', render: d => calcStatusBadgeRd(d) },
        ],
        paging: false,
        searching: details.length > 10,
        info: false,
        language: getTableLang(),
        drawCallback: function () { getTableLang(); }
    });
}

function auditActionLabel(action) {
    const map = {
        create: 'action_create', update: 'action_edit', recalculate: 'action_recalculate',
        submit: 'action_submit', revert: 'action_revert', approve: 'action_approve',
        reject: 'action_reject', reviseAfterReject: 'action_revise', markPaid: 'action_mark_paid',
        lock: 'action_lock', delete: 'action_delete', cancel: 'action_cancel',
    };
    const key = map[action];
    return (key && langData[key]) || action;
}
function initAuditLogTable(auditLog) {
    if ($.fn.DataTable.isDataTable('#tb_audit_log')) {
        $('#tb_audit_log').DataTable().clear().rows.add(auditLog).draw();
        return;
    }
    tb_audit_log = $('#tb_audit_log').DataTable({
        responsive: true,
        data: auditLog,
        order: [[0, 'desc']],
        columns: [
            { data: 'performed_at' },
            { data: 'action', render: d => escapeHtmlRd(auditActionLabel(d)) },
            { data: null, render: (d, t, row) => row.from_state ? `${stateBadgeRd(row.from_state)} <i class="fa-solid fa-arrow-right mx-1"></i> ${stateBadgeRd(row.to_state)}` : stateBadgeRd(row.to_state) },
            { data: null, render: (d, t, row) => escapeHtmlRd(personDisplayNameRd(row, 'performed_by')) },
            { data: 'note', render: d => escapeHtmlRd(d || '-') },
        ],
        paging: false,
        searching: false,
        info: false,
        language: getTableLang(),
        drawCallback: function () { getTableLang(); }
    });
}

function loadRunDetail() {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.get`,
        method: 'GET',
        data: { id: PAYROLL_RUN_ID },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                renderRunHeader(res.data);
                initRunDetailTable(res.data.details || []);
                initAuditLogTable(res.data.audit_log || []);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
}

/* ---------- Generic action call helper ---------- */
function callRunAction(url, payload, successMessage) {
    $.ajax({
        url: `${BASE_URL}${url}`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(Object.assign({ id: PAYROLL_RUN_ID }, payload || {})),
        success: function (res) {
            if (res.status) {
                showSuccess(successMessage || langData['save_success'] || 'Saved successfully.');
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
}

/* ---------- Action bindings ---------- */
$(document).on('click', '#btnRecalculate', function () {
    const title = langData['confirm_recalculate_message'] || 'This will overwrite the current calculated breakdown for every employee in this run. Continue?';
    showConfirm(langData['action_recalculate'] || 'Recalculate', title, function () {
        callRunAction('/api/payroll-run.recalculate', {}, langData['save_success']);
    });
});
$(document).on('click', '#btnEditRun', function () {
    $('#edit_run_name').val(currentRun.run_name);
    $('#edit_period_start').val(toDisplayDateRd(currentRun.period_start_date));
    $('#edit_period_end').val(toDisplayDateRd(currentRun.period_end_date));
    $('#edit_payment_date').val(toDisplayDateRd(currentRun.payment_date));
    $('#edit_notes').val(currentRun.notes || '');
    $('.is-invalid').removeClass('is-invalid');
    new bootstrap.Modal(document.getElementById('editRunModal')).show();
});
$(document).on('submit', '#editRunForm', function (e) {
    e.preventDefault();
    let firstInvalid = null;
    $('#editRunModal .required').each(function () {
        const $el = $(this);
        if (!($el.val() || '').toString().trim()) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    if (firstInvalid) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        id: PAYROLL_RUN_ID,
        run_name: $('#edit_run_name').val().trim(),
        period_start_date: toIsoDateRd($('#edit_period_start').val()),
        period_end_date: toIsoDateRd($('#edit_period_end').val()),
        payment_date: toIsoDateRd($('#edit_payment_date').val()),
        notes: $('#edit_notes').val().trim(),
    };
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('editRunModal')).hide();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '#btnSubmitRun', function () {
    const title = langData['confirm_submit_message'] || 'Submit this payroll run for approval? You will not be able to edit amounts until it is sent back or rejected.';
    showConfirm(langData['action_submit'] || 'Submit for Approval', title, function () {
        callRunAction('/api/payroll-run.submit', {});
    });
});
$(document).on('click', '#btnDeleteRun', function () {
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_run_message'] || 'Delete this draft payroll run? This cannot be undone.';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.delete`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: PAYROLL_RUN_ID }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['delete_success'] || 'Deleted successfully.');
                    setTimeout(() => { window.location.href = `${BASE_URL}/payroll-process`; }, 800);
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                }
            },
            error: function () {
                showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
            }
        });
    });
});
$(document).on('click', '#btnApproveRun', function () {
    const title = langData['confirm_approve_message'] || 'Approve this payroll run? This moves it forward to payment processing.';
    showConfirm(langData['action_approve'] || 'Approve', title, function () {
        callRunAction('/api/payroll-run.approve', {});
    });
});
$(document).on('click', '#btnRevertRun', function () {
    const title = langData['confirm_revert_message'] || 'Send this payroll run back to draft for revision?';
    showConfirm(langData['action_revert'] || 'Send Back for Revision', title, function () {
        callRunAction('/api/payroll-run.revert', {});
    });
});
$(document).on('click', '#btnRejectRun', function () {
    $('#reject_reason').val('').removeClass('is-invalid').attr('placeholder', langData['reject_reason_placeholder'] || 'Explain what needs to be fixed before resubmitting...');
    new bootstrap.Modal(document.getElementById('rejectRunModal')).show();
});
$(document).on('submit', '#rejectRunForm', function (e) {
    e.preventDefault();
    const reason = $('#reject_reason').val().trim();
    if (!reason) {
        $('#reject_reason').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.reject`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, reason: reason }),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('rejectRunModal')).hide();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '#btnCancelRun', function () {
    $('#cancel_reason').val('').removeClass('is-invalid').attr('placeholder', langData['cancel_reason_placeholder'] || 'Explain why this payroll run is being cancelled...');
    new bootstrap.Modal(document.getElementById('cancelRunModal')).show();
});
$(document).on('submit', '#cancelRunForm', function (e) {
    e.preventDefault();
    const reason = $('#cancel_reason').val().trim();
    if (!reason) {
        $('#cancel_reason').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.cancel`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, reason: reason }),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('cancelRunModal')).hide();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '#btnReviseRun', function () {
    callRunAction('/api/payroll-run.revise-after-reject', {});
});
$(document).on('click', '#btnMarkPaidRun', function () {
    $('#paid_payment_date').val(toDisplayDateRd(currentRun.payment_date));
    $('#paid_payment_reference').val('');
    $('#paid_payment_method').val('bank_transfer').trigger('change');
    $('.is-invalid').removeClass('is-invalid');
    new bootstrap.Modal(document.getElementById('markPaidModal')).show();
});
$(document).on('submit', '#markPaidForm', function (e) {
    e.preventDefault();
    let firstInvalid = null;
    $('#markPaidModal .required').each(function () {
        const $el = $(this);
        if (!($el.val() || '').toString().trim()) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    if (firstInvalid) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        id: PAYROLL_RUN_ID,
        payment_date: toIsoDateRd($('#paid_payment_date').val()),
        payment_method: $('#paid_payment_method').val(),
        payment_reference: $('#paid_payment_reference').val().trim(),
    };
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.mark-paid`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('markPaidModal')).hide();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '#btnLockRun', function () {
    showConfirm(langData['action_lock'] || 'Lock', langData['confirm_lock_message'] || 'Once locked, this entry can no longer be edited or deleted.', function () {
        callRunAction('/api/payroll-run.lock', {});
    });
});

$(document).ready(function () {
    loadRunDetail();
    if (typeof initSelect2 === 'function') {
        initSelect2('#paid_payment_method', { mode: 'static' });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#edit_period_start');
        initDatepicker('#edit_period_end');
        initDatepicker('#edit_payment_date');
        initDatepicker('#paid_payment_date');
    }
});
