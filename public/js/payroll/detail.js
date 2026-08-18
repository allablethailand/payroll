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
/* ---------- Remark column on the calculation table: payroll_run_details.calc_errors is a
   comma-separated list of machine codes (e.g. "profile_incomplete, missing_base_salary") --
   translate each known code to a readable sentence; an unrecognized code (defensive) falls back
   to showing the raw code rather than hiding it. profile_incomplete is what a placeholder
   employee (auto-created via Origami SSO or a Payroll Sync pull) shows here -- per explicit
   request, these employees are pulled into this table like anyone else rather than being
   silently excluded, so this Remark is what tells the admin WHY that row still needs attention. */
function calcErrorsRemarkRd(calcErrors) {
    if (!calcErrors) return '';
    const codes = String(calcErrors).split(',').map(s => s.trim()).filter(Boolean);
    const labels = codes.map(code => {
        if (code === 'profile_incomplete') return langData['calc_error_profile_incomplete'] || 'Employee profile is incomplete -- complete it via Employee Detail, then recalculate.';
        if (code === 'missing_base_salary') return langData['calc_error_missing_base_salary'] || 'Missing base salary.';
        if (code === 'no_manual_lines') return langData['calc_error_no_manual_lines'] || 'No payment items added yet -- use "Items" to add one.';
        if (code.indexOf('no_rate_configured:') === 0) {
            const item = code.substring('no_rate_configured:'.length);
            const tpl = langData['calc_error_no_rate_configured'] || 'No statutory rate configured for {item}.';
            return tpl.replace('{item}', item);
        }
        return code;
    });
    return `<span class="text-danger small">${escapeHtmlRd(labels.join(' '))}</span>`;
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

/* ---------- Next-step banner: one line below the timeline, telling the user exactly what this
   run needs next in plain language -- separate from the state badge/timeline labels (which just
   name the state) and from the action buttons themselves (which say WHAT to click, not WHY).
   Styled via .process-next-step (see style.css), colored by urgency. */
function nextStepBanner(state) {
    // Non-draft text is purely informational now (no "click X" instructions) -- this page shows no
    // action buttons at all once a run has left draft (per explicit request), so telling the
    // viewer to click something that isn't there would be misleading.
    const map = {
        draft: ['', 'fa-circle-info', 'next_step_draft', 'This run is still a draft. Recalculate to compute amounts, then Submit for Approval when ready.'],
        pending_approval: ['waiting', 'fa-hourglass-half', 'next_step_pending_approval', 'Waiting for approval.'],
        approved: ['', 'fa-money-check-dollar', 'next_step_approved', 'Approved. Waiting to be marked as paid.'],
        paid: ['', 'fa-lock', 'next_step_paid', 'Paid. Waiting to be locked.'],
        locked: ['done', 'fa-circle-check', 'next_step_locked', 'This run is locked and finalized. No further action is needed.'],
        rejected: ['error', 'fa-rotate', 'next_step_rejected', 'Rejected. Review the reason above. Waiting to be revised.'],
        cancelled: ['muted', 'fa-ban', 'next_step_cancelled', 'This run was cancelled and is no longer active.'],
    };
    const [cls, icon, key, fallback] = map[state] || ['muted', 'fa-circle-info', '', ''];
    const text = langData[key] || fallback;
    if (!text) {
        return { cls: '', html: '' };
    }
    return { cls, html: `<i class="fa-solid ${icon}"></i><span>${escapeHtmlRd(text)}</span>` };
}

/* ---------- Process timeline: a horizontal step tracker across the top of the page, mirroring
   the pattern in C:\xampp\htdocs\origami\payroll's own Process Detail page (TIMELINE_STEPS /
   renderChrome() in its assets/js/process-detail.js) per explicit request -- shows exactly which
   step this run is at, with each step's own action button(s) rendered directly underneath it (the
   action that moves the run INTO that step) instead of a separate generic action bar below. Every
   action that used to live in #runActionButtons now renders under the station it belongs to.
   Delete/Cancel are NOT part of this spine at all -- both were removed from the Detail page
   entirely per explicit request and now live only on the Payroll Process list page's row actions,
   since they're "leave the flow" actions rather than a step within it. */
const RUN_TIMELINE_STEPS = [
    { key: 'draft', labelKey: 'state_draft', icon: 'fa-file-alt', dateField: 'created_at' },
    { key: 'pending_approval', labelKey: 'state_pending_approval', icon: 'fa-paper-plane', dateField: 'submitted_at' },
    { key: 'approved', labelKey: 'state_approved', icon: 'fa-check', dateField: 'approved_at' },
    { key: 'paid', labelKey: 'state_paid', icon: 'fa-money-check-dollar', dateField: 'paid_at' },
    { key: 'locked', labelKey: 'state_locked', icon: 'fa-lock', dateField: 'locked_at' },
];
// A cancelled run's audit_log always ends with the 'cancel' action -- its own from_state (the
// last state the run was actually sitting in right before being cancelled) is what tells us how
// far up the spine to mark done vs. where the "Cancelled" branch belongs, without needing a
// dedicated column just for this cosmetic purpose.
function cancelledFromState(run) {
    const log = run.audit_log || [];
    const last = log[log.length - 1];
    return (last && last.action === 'cancel') ? last.from_state : 'draft';
}
function computeTimelineProgress(run) {
    const state = run.state;
    if (state === 'rejected') {
        // Rejection always happens FROM pending_approval -- draft+pending_approval both actually
        // happened, the "Approved" slot is where the rejection branch shows instead.
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'rejected' } };
    }
    if (state === 'cancelled') {
        const fromKey = cancelledFromState(run);
        if (fromKey === 'draft') {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        // 'rejected' isn't a spine step itself (it's a branch off pending_approval) -- treat
        // cancelling-from-rejected the same as cancelling from pending_approval for spine purposes.
        const effectiveKey = fromKey === 'rejected' ? 'pending_approval' : fromKey;
        const idx = RUN_TIMELINE_STEPS.findIndex(s => s.key === effectiveKey);
        if (idx < 0) {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        return { reachedIdx: idx, branch: { atIndex: idx + 1, type: 'cancelled' } };
    }
    const idx = RUN_TIMELINE_STEPS.findIndex(s => s.key === state);
    return { reachedIdx: idx - 1, branch: null };
}
// The action button that moves the run INTO step i -- rendered directly under that step. Only
// shown for a draft run (reached via the List page's "Edit" action) -- everything else is
// reached via "View" and is read-only, per explicit request: once a run has left draft (sent for
// approval, approved, paid, rejected...) this page shows progress only, no action buttons at all.
// Approve/Reject/Revert/Mark Paid/Lock/Revise are therefore unreachable from this page's UI now
// for a non-draft run -- PayrollRunModel's own API endpoints are untouched, this only removes the
// buttons that used to call them from here. Submit (draft -> pending_approval) is the only
// transition that still applies, since it's the one action a draft run itself can take.
function timelineStepActionsHtml(i, run) {
    if (run.state !== 'draft' || i !== 1) {
        return '';
    }
    return `<button type="button" id="btnSubmitRun" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane me-1"></i><span data-i18n="action_submit">${langData['action_submit'] || 'Submit for Approval'}</span></button>`;
}
function renderProcessTimeline(run) {
    const { reachedIdx, branch } = computeTimelineProgress(run);
    const currentIndex = reachedIdx + 1;
    let html = '<ul class="process-timeline">';
    for (let i = 0; i < RUN_TIMELINE_STEPS.length; i++) {
        const step = RUN_TIMELINE_STEPS[i];
        let cls = '';
        let icon = step.icon;
        let label = langData[step.labelKey] || step.key;
        if (branch && branch.atIndex === i) {
            cls = branch.type;
            icon = branch.type === 'rejected' ? 'fa-xmark' : 'fa-ban';
            label = langData[branch.type === 'rejected' ? 'state_rejected' : 'state_cancelled'] || branch.type;
        } else if (i <= reachedIdx) {
            cls = 'done';
            icon = 'fa-check';
        } else if (i === currentIndex) {
            cls = 'current';
        }
        const dateVal = run[step.dateField];
        const dateHtml = (cls === 'done' || cls === 'current') && dateVal
            ? `<span class="tl-date"><i class="fa-regular fa-clock"></i> ${toDisplayDateRd(String(dateVal).substring(0, 10))}</span>`
            : '';
        const actionsHtml = timelineStepActionsHtml(i, run);
        html += `<li class="tl-step ${cls}">
            <span class="tl-icon"><i class="fa-solid ${icon}"></i></span>
            <span class="tl-label">${escapeHtmlRd(label)}</span>
            ${dateHtml}
            ${actionsHtml ? `<span class="tl-actions">${actionsHtml}</span>` : ''}
        </li>`;
    }
    html += '</ul>';
    $('#runProcessTimeline').html(html);
}

/* ---------- Section-scoped buttons: Edit sits at the top-right of "1. Run Information" (the
   section it actually edits), Recalculate sits at the top-right of "2. Employee Breakdown" (the
   section it recomputes) -- both draft-only, same as before, just relocated per explicit request
   so it's obvious which part of the page each button touches. Button ids stay #btnEditRun/
   #btnRecalculate; the existing $(document).on(...) delegated handlers don't care where in the
   DOM they live. */
function renderSectionButtons(run) {
    const $editWrap = $('#runEditButtonWrap').empty();
    const $recalcWrap = $('#runRecalculateButtonWrap').empty();
    if (run.state !== 'draft') {
        return;
    }
    $editWrap.append(`<button type="button" id="btnEditRun" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="action_edit">${langData['action_edit'] || 'Edit'}</span></button>`);
    $recalcWrap.append(`<button type="button" id="btnRecalculate" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate me-1"></i><span data-i18n="action_recalculate">${langData['action_recalculate'] || 'Recalculate'}</span></button>`);
    // Join Employees only makes sense for a genuine off-cycle run -- a cycle-based or Pending-Pull
    // run's membership is derived automatically by recalculate() itself (see PayrollRunModel's
    // docblock), there's nothing to manually curate there.
    if (!run.cycle_id && !run.sync_process_id) {
        $recalcWrap.append(`<button type="button" id="btnJoinEmployees" class="btn btn-sm btn-outline-primary ms-2"><i class="fa-solid fa-user-plus me-1"></i><span data-i18n="action_join_employees">${langData['action_join_employees'] || 'Join Employees'}</span></button>`);
    }
}

function renderRunHeader(run) {
    currentRun = run;
    document.title = run.run_name;
    $('#bcRunName').text(run.run_name);
    $('#runNameHeading').text(run.run_name);
    $('#runStateBadge').html(stateBadgeRd(run.state));
    $('#infoCycle').text(run.cycle_name || langData['offcycle_run_short'] || 'Off-cycle');
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

    const banner = nextStepBanner(run.state);
    $('#nextStepBanner')
        .attr('class', `next-step-banner process-next-step ${banner.cls}`.trim())
        .toggleClass('d-none', !banner.html)
        .html(banner.html);

    if (Number(run.has_validation_errors) === 1) {
        const errCount = (run.details || []).filter(d => d.calc_status === 'error').length;
        const tpl = langData['validation_errors_banner'] || '{count} employee(s) have calculation errors. Recalculate and resolve them before submitting for approval.';
        $('#validationErrorsBanner').removeClass('d-none').text(tpl.replace('{count}', errCount));
    } else {
        $('#validationErrorsBanner').addClass('d-none');
    }

    renderProcessTimeline(run);
    renderSectionButtons(run);
}

/* Remove-from-run action, calculation table -- only for a genuine off-cycle run (no cycle, no
   sync process) still in draft: every row there IS a manually-joined employee (see
   PayrollRunModel::recalculate()'s off-cycle branch), so this is safe to show unconditionally for
   that run type rather than needing a separate "is this row manually joined" flag per row. */
function manualEmployeeRemoveButtonRd(row) {
    if (!currentRun || currentRun.state !== 'draft' || currentRun.cycle_id || currentRun.sync_process_id) {
        return '';
    }
    let html = '';
    // "Items" (manage per-employee earning/deduction lines) only applies to an Incentive/Other
    // Payment run -- a normal off-cycle 'payroll' run still uses standing PED assignments/
    // attendance bonus/base salary automatically, nothing to manually pick here.
    if (currentRun.run_purpose === 'incentive') {
        html += `<button type="button" class="btn btn-sm btn-outline-primary btn-manage-manual-lines me-1" data-employee-id="${row.employee_id}" title="${langData['action_manage_items'] || 'Items'}"><i class="fa-solid fa-list-check"></i></button>`;
    }
    html += `<button type="button" class="btn btn-sm btn-outline-danger btn-remove-manual-employee" data-employee-id="${row.employee_id}" title="${langData['action_remove'] || 'Remove'}"><i class="fa-solid fa-user-minus"></i></button>`;
    return html;
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
            { data: 'calc_errors', render: d => calcErrorsRemarkRd(d) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => manualEmployeeRemoveButtonRd(row) },
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
/* ---------- Manage Payment Items modal (Incentive/Other Payment runs only): per-employee
   earning/deduction lines, add one at a time, remove any individually. ---------- */
let manageLinesEmployeeId = null;
function renderManualLineRowRd(line) {
    const name = (currentLang === 'th' ? line.item_name_th : line.item_name_en) || line.item_name_th || line.item_name_en;
    const amtCls = line.item_type === 'earning' ? 'text-success' : 'text-danger';
    return `<tr>
        <td><code class="fw-bold text-dark">${escapeHtmlRd(line.item_code)}</code></td>
        <td>${escapeHtmlRd(name)}</td>
        <td class="text-end ${amtCls}">${fmtNumRd(line.amount)}</td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-manual-line" data-line-id="${line.id}"><i class="fa-solid fa-trash-alt"></i></button></td>
    </tr>`;
}
function loadManualLinesRd() {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.manual-lines`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const lines = res.data || [];
            $('#tb_manual_lines tbody').html(lines.map(renderManualLineRowRd).join(''));
            $('#noManualLinesYet').toggleClass('d-none', lines.length > 0);
        }
    });
}
$(document).on('click', '.btn-manage-manual-lines', function () {
    manageLinesEmployeeId = $(this).data('employee-id');
    const rowData = (tb_run_detail ? tb_run_detail.rows().data().toArray() : []).find(r => Number(r.employee_id) === Number(manageLinesEmployeeId));
    $('#manageLinesEmployeeName').text(rowData ? `${rowData.employee_no} - ${employeeDisplayNameRd(rowData)}` : '');
    $('#manualLineItemSelect').val(null).trigger('change');
    $('#manualLineAmount').val('');
    new bootstrap.Modal(document.getElementById('manageLinesModal')).show();
    loadManualLinesRd();
});
$(document).on('click', '#btnAddManualLine', function () {
    const pedTypeId = $('#manualLineItemSelect').val();
    const amount = parseFloat($('#manualLineAmount').val());
    if (!pedTypeId || !amount || amount <= 0) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.add-manual-line`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, ped_type_id: pedTypeId, amount: amount }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                $('#manualLineItemSelect').val(null).trigger('change');
                $('#manualLineAmount').val('');
                loadManualLinesRd();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '.btn-remove-manual-line', function () {
    const lineId = $(this).data('line-id');
    const title = langData['action_remove'] || 'Remove';
    const msg = langData['confirm_remove_line_message'] || 'Remove this item?';
    showConfirm(title, msg, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.remove-manual-line`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: PAYROLL_RUN_ID, line_id: lineId }),
            success: function (res) {
                if (res.status) {
                    loadManualLinesRd();
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
});

$(document).on('click', '.btn-remove-manual-employee', function () {
    const employeeId = $(this).data('employee-id');
    const title = langData['confirm_remove_employee_message'] || 'Remove this employee from the run?';
    showConfirm(langData['action_remove'] || 'Remove', title, function () {
        callRunAction('/api/payroll-run.remove-employee', { employee_id: employeeId }, langData['save_success']);
    });
});

/* ---------- Join Employees modal (off-cycle runs only): filter by Department/Position, select
   one or many via checkbox (selection tracked client-side by id so it survives pagination/reload
   the same way the Pending Sync bulk-pull picker does), then Join all at once. ---------- */
let tb_join_employees;
let joinSelectedEmployees = {};

function updateJoinSelectedCountRd() {
    const count = Object.keys(joinSelectedEmployees).length;
    $('#joinSelectedCount').text(`${count} ${langData['bulk_pull_selected_label'] || 'selected'}`);
    $('#btnJoinSelected').prop('disabled', count === 0);
}

function initJoinEmployeesTable() {
    if ($.fn.DataTable.isDataTable('#tb_join_employees')) {
        $('#tb_join_employees').DataTable().ajax.reload();
        return;
    }
    tb_join_employees = $('#tb_join_employees').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: `${BASE_URL}/api/payroll-run.manual-employee-options`,
            type: 'POST',
            data: function (d) {
                d.run_id = PAYROLL_RUN_ID;
                d.department_id = $('#joinFilterDepartment').val() || '';
                d.position_id = $('#joinFilterPosition').val() || '';
            }
        },
        columns: [
            {
                data: null, orderable: false, render: (d, t, row) => {
                    const checked = joinSelectedEmployees[row.id] ? 'checked' : '';
                    return `<input type="checkbox" class="join-emp-checkbox" data-id="${row.id}" data-employee-no="${escapeHtmlRd(row.employee_no)}" ${checked}>`;
                }
            },
            { data: 'employee_no' },
            { data: null, render: (d, t, row) => escapeHtmlRd((currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '-') },
            { data: 'department', render: d => escapeHtmlRd(d || '-') },
            { data: 'position', render: d => escapeHtmlRd(d || '-') },
        ],
        order: [],
        searching: false,
        language: getTableLang(),
        drawCallback: function () {
            getTableLang();
            $('#joinSelectAll').prop('checked', false);
        }
    });
}
$(document).on('click', '#btnJoinEmployees', function () {
    joinSelectedEmployees = {};
    updateJoinSelectedCountRd();
    $('#joinFilterDepartment, #joinFilterPosition').val(null).trigger('change');
    new bootstrap.Modal(document.getElementById('joinEmployeesModal')).show();
    initJoinEmployeesTable();
});
$(document).on('change', '#joinFilterDepartment, #joinFilterPosition', function () {
    if (tb_join_employees) tb_join_employees.ajax.reload(null, false);
});
$(document).on('click', '#btnClearJoinFilter', function () {
    $('#joinFilterDepartment, #joinFilterPosition').val(null).trigger('change');
});
$(document).on('change', '.join-emp-checkbox', function () {
    const id = $(this).data('id');
    if (this.checked) {
        joinSelectedEmployees[id] = true;
    } else {
        delete joinSelectedEmployees[id];
    }
    updateJoinSelectedCountRd();
});
$(document).on('change', '#joinSelectAll', function () {
    const checked = this.checked;
    $('#tb_join_employees tbody .join-emp-checkbox').each(function () {
        if (this.checked !== checked) {
            $(this).prop('checked', checked).trigger('change');
        }
    });
});
$(document).on('click', '#btnJoinSelected', function () {
    const employeeIds = Object.keys(joinSelectedEmployees).map(Number);
    if (employeeIds.length === 0) return;
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.join-employees`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_ids: employeeIds }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('joinEmployeesModal')).hide();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
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
$(document).ready(function () {
    loadRunDetail();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#edit_period_start');
        initDatepicker('#edit_period_end');
        initDatepicker('#edit_payment_date');
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#joinFilterDepartment, #joinFilterPosition', { mode: 'ajax' });
        initSelect2('#manualLineItemSelect', { mode: 'ajax' });
    }
});
