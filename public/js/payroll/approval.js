/**
 * Payroll Approval page (2026-08-22 rebuild, explicit request: match the design of
 * C:\xampp\htdocs\origami\payroll's own approval page -- checkboxes to bulk-approve, a Timeline
 * button per row, and a Filter + Station Status bar). Before this rebuild, nothing anywhere in
 * this app's UI called /api/payroll-run.approve or /api/payroll-run.reject at all (detail.js
 * explicitly removed those buttons from the Detail page in an earlier session) -- this page is
 * now the actual place approve/reject happens, not just a passive list.
 */
let tb_payroll_approval;
let currentApprovalStation = 'pending_approval';
let selectedApprovalRuns = {}; // id => true, for the bulk approve/reject bar

function toDisplayDateAp(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function toIsoDateAp(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function escapeHtmlAp(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function fmtNumAp(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function submitterNameAp(row) {
    return (currentLang === 'th' ? row.submitted_by_name_th : row.submitted_by_name_en) || row.submitted_by_name_th || row.submitted_by_name_en || '-';
}
function stateBadgeAp(state) {
    const map = {
        pending_approval: 'bg-warning-subtle text-warning',
        approved: 'bg-info-subtle text-info',
        rejected: 'bg-danger-subtle text-danger',
        need_info: 'bg-primary-subtle text-primary',
    };
    const cls = map[state] || 'bg-light text-dark';
    const text = langData['state_' + state] || state;
    return `<span class="badge ${cls}">${text}</span>`;
}

/* ---------- Filter + Station Status bar (2026-08-22) -- same .station-filter/.station-row/
   .station-card component already built for the Payroll Process list page
   (public/js/payroll/index.js's registerStationSearchFilter()/updateStationCounts()), trimmed to
   the 3 states relevant to an approver. A permanent base filter (only these 3 states) applies
   regardless of which station card is active, since this page never shows draft/paid/locked/
   cancelled runs at all -- everything is fetched unfiltered from the server (same convention
   index.js already uses) and narrowed entirely client-side. ---------- */
function registerApprovalStationSearchFilter() {
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (settings.nTable.id !== 'tb_payroll_approval') return true;
        if (!rowData || !['pending_approval', 'approved', 'rejected', 'need_info'].includes(rowData.state)) return false;
        if (!currentApprovalStation) return true;
        return rowData.state === currentApprovalStation;
    });
}
function updateApprovalStationCounts() {
    if (!tb_payroll_approval) return;
    const rows = tb_payroll_approval.rows({ search: 'none' }).data().toArray();
    const counts = { pending_approval: 0, approved: 0, rejected: 0, need_info: 0 };
    rows.forEach(r => { if (Object.prototype.hasOwnProperty.call(counts, r.state)) counts[r.state]++; });
    Object.keys(counts).forEach(state => {
        $(`#approvalStationRow .station-card[data-state="${state}"] .station-count`).text(counts[state]);
    });
}
function showApprovalStation(state) {
    currentApprovalStation = state;
    $('#approvalStationRow .station-card').removeClass('active');
    $(`#approvalStationRow .station-card[data-state="${state}"]`).addClass('active');
    if (tb_payroll_approval) tb_payroll_approval.draw();
}

/* ---------- Checkbox multi-select + bulk bar (2026-08-22) -- same object-as-set pattern already
   used twice in this codebase (detail.js's joinSelectedEmployees, index.js's selectedPendingSync).
   Only a pending_approval row gets a checkbox at all -- approved/rejected/need_info rows are shown
   for visibility/history but aren't actionable, matching Origami's own approval page. ---------- */
function updateApprovalBulkBar() {
    const count = Object.keys(selectedApprovalRuns).length;
    $('#approvalBulkCount').text(count);
    $('#approvalBulkBar').toggleClass('d-none', count === 0).toggleClass('d-inline-flex', count > 0);
}
function approvalCheckboxHtml(row) {
    if (row.state !== 'pending_approval') return '';
    // 2026-08-23, follow-up to the department-scoping fix (explicit report: "Approval ตอนนี้ Set
    // ไว้แค่คนเดียว แต่ดึงมาหลายคน") -- can_approve_payroll is per-row now (which department the
    // run's submitter is in), not a blanket "any approver can act on any run" like before, so a
    // row the viewer isn't eligible for no longer gets a checkbox (there was never a point
    // selecting it into a bulk approve/reject that the backend would just refuse anyway).
    if (row.can_approve_payroll === false) return '';
    const checked = selectedApprovalRuns[row.id] ? 'checked' : '';
    return `<input type="checkbox" class="approval-row-checkbox" value="${row.id}" ${checked}>`;
}

/* ---------- Per-row actions, split into 2 columns (2026-08-23, explicit request: "ในหน้า Approve
   ให้แยก Column ระหว่าง ปุ่มอนุมัติ และปุ่มที่กดดูข้อมูลครับ") -- View + Timeline (every row, purely
   informational) in one column, Approve/Request Info/Reject (pending_approval rows only, the
   actual decision) in a separate one, so a glance at the row tells you "can I decide on this" vs.
   "can I just look at it" without reading icon-by-icon inside one shared group. Canonical btn-group
   border rounded-3 bg-white + btn-link pattern used sitewide, same as before the split. ---------- */
function renderApprovalViewActions(row) {
    let html = '<div class="btn-group border rounded-3 bg-white">';
    if (row.public_id) {
        html += `<button type="button" class="btn btn-link text-info btn-view-approval-run" data-public-id="${row.public_id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>`;
    }
    html += `<button type="button" class="btn btn-link text-secondary border-start btn-view-approval-timeline" data-id="${row.id}" title="${langData['action_timeline'] || 'Timeline'}"><i class="fa-solid fa-list-check"></i></button>`;
    html += '</div>';
    return html;
}
function renderApprovalDecisionActions(row) {
    if (row.state !== 'pending_approval') {
        return '';
    }
    // Same department-scoping reasoning as approvalCheckboxHtml() above -- don't show buttons
    // that would just come back "you do not have permission" from the backend.
    if (row.can_approve_payroll === false) {
        return '';
    }
    let html = '<div class="btn-group border rounded-3 bg-white">';
    html += `<button type="button" class="btn btn-link text-success btn-approve-run" data-id="${row.id}" title="${langData['action_approve'] || 'Approve'}"><i class="fa-solid fa-check"></i></button>`;
    html += `<button type="button" class="btn btn-link text-primary border-start btn-request-info-run" data-id="${row.id}" title="${langData['action_request_info'] || 'Request Info'}"><i class="fa-solid fa-circle-info"></i></button>`;
    html += `<button type="button" class="btn btn-link text-danger border-start btn-reject-run" data-id="${row.id}" title="${langData['action_reject'] || 'Reject'}"><i class="fa-solid fa-xmark"></i></button>`;
    html += '</div>';
    return html;
}

/* ---------- Approval Flow timeline modal (2026-08-22, explicit request: "มีปุ่มให้กดดู Timeline
   ของการอนุมัติได้ด้วย"; redesigned 2026-08-23 per explicit request: "ปุ่มการดู Flow การอนุมัต ให้ล้อ
   มาจากรายการอนุมัติของ C:\xampp\htdocs\origami\payroll รวมถึง Design ต่างๆ ให้แสดงผลแบบเดียวกัน" --
   mirrors that reference app's own assets/js/approval-timeline.js: a vertical "stage" spine (Paid
   -> Approval -> Created, newest at the top) with connector-line + colored circular icon markers +
   badges, matching its .apv-* CSS classnames (see style.css's own comment for the full mapping).
   The Approval stage's body is one "substep" card per eligible approver (this app has a flat pool
   of any-one-can-decide approvers, not Origami's literal sequential chain -- see
   PayrollRunModel::approvalFlow()). Followed by the detailed audit-log history (newest first at
   the top, reading bottom-to-top chronologically -- Origami's own approval table doesn't need this
   since it never lets a decision be undone, but this app's revert-from-any-decided-state does, so
   the raw log is what answers "how many times was this approved, what happened each time" per the
   2026-08-23 request). Fetched fresh via AJAX every time it opens (api/payroll-run.approval-timeline)
   since the approver breakdown needs a live query the list endpoint doesn't carry. Approve/Reject/
   Request Info only apply from pending_approval; Revert/Undo now applies from pending_approval OR
   any already-decided state (approved/rejected/need_info) per that same request -- server-checked
   via can_approve_payroll, not just state-gated. Clicking one hides this modal and opens the same
   dedicated modal/form the row-action buttons already use, instead of duplicating that logic. ---------- */
let currentTimelineRun = null;
const APV_COLORS_AP = {
    done: { icon: '#16a34a', badgeBg: '#dcfce7', badgeText: '#15803d' },
    pending: { icon: '#f59e0b', badgeBg: '#fef3c7', badgeText: '#b45309' },
    rejected: { icon: '#ef4444', badgeBg: '#fee2e2', badgeText: '#b91c1c' },
    info: { icon: '#0d6efd', badgeBg: '#cfe2ff', badgeText: '#0a58ca' },
    muted: { icon: '#cbd5e1', badgeBg: '#f1f5f9', badgeText: '#64748b' },
};
function apvBadgeHtmlAp(tone, label) {
    const c = APV_COLORS_AP[tone] || APV_COLORS_AP.muted;
    return `<span class="apv-badge" style="background:${c.badgeBg};color:${c.badgeText};">${escapeHtmlAp(label)}</span>`;
}
function apvIconHtmlAp(tone, icon) {
    const c = APV_COLORS_AP[tone] || APV_COLORS_AP.muted;
    return `<div class="apv-stage-icon" style="background:${c.icon};"><i class="fa-solid ${icon}"></i></div>`;
}
function apvAvatarHtmlAp(name, size) {
    size = size || 26;
    const initial = (name || '?').trim().charAt(0).toUpperCase() || '?';
    return `<span class="apv-person-avatar" style="width:${size}px;height:${size}px;min-width:${size}px;font-size:${Math.round(size * 0.42)}px;">${escapeHtmlAp(initial)}</span>`;
}
function apvPersonLineHtmlAp(name) {
    return `<div style="display:flex;align-items:center;gap:8px;">${apvAvatarHtmlAp(name, 26)}<span class="apv-person-name">${escapeHtmlAp(name || '-')}</span></div>`;
}
function apvApproverToneAp(status) {
    return { approved: 'done', rejected: 'rejected', need_info: 'info', pending: 'pending', not_applicable: 'muted' }[status] || 'muted';
}
function apvApproverLabelAp(status) {
    const key = { approved: 'status_approved', rejected: 'status_rejected', need_info: 'state_need_info', pending: 'status_pending' }[status];
    return (key && langData[key]) || status;
}
function apvApproverSubstepHtmlAp(a) {
    const name = (currentLang === 'th' ? a.name_th : a.name_en) || a.name_th || a.name_en || a.employee_no;
    const tone = apvApproverToneAp(a.status);
    return `<div class="apv-substep">
        <div class="apv-substep-head">
            <span class="apv-substep-label">${apvAvatarHtmlAp(name, 22)}${escapeHtmlAp(name)}</span>
            ${apvBadgeHtmlAp(tone, apvApproverLabelAp(a.status))}
        </div>
        ${a.acted_at ? `<div class="apv-substep-date"><i class="fa-regular fa-calendar"></i> ${escapeHtmlAp(a.acted_at)}</div>` : ''}
        ${a.note ? `<div class="apv-substep-remark">${escapeHtmlAp(a.note)}</div>` : ''}
    </div>`;
}
function apvApprovalStageInfoAp(state) {
    switch (state) {
        case 'pending_approval': return { tone: 'pending', icon: 'fa-hourglass-half', label: langData['state_pending_approval'] || 'Waiting for Approval' };
        case 'need_info': return { tone: 'info', icon: 'fa-circle-info', label: langData['state_need_info'] || 'Need Information' };
        case 'approved': case 'paid': case 'locked': return { tone: 'done', icon: 'fa-check', label: langData['state_approved'] || 'Approved' };
        case 'rejected': return { tone: 'rejected', icon: 'fa-xmark', label: langData['state_rejected'] || 'Not Approved' };
        default: return { tone: 'muted', icon: 'fa-hourglass', label: langData['status_pending'] || 'Not Started' };
    }
}
function apvApprovalStageHtmlAp(run) {
    const info = apvApprovalStageInfoAp(run.state);
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    const bodyHtml = approvers.length
        ? approvers.map(apvApproverSubstepHtmlAp).join('')
        : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`;
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtmlAp(info.tone, info.icon)}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['approval_flow_title'] || 'Approval'}</span>
                    ${apvBadgeHtmlAp(info.tone, info.label)}
                </div>
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
function apvPaidStageHtmlAp(run) {
    const isPaidOrLocked = run.state === 'paid' || run.state === 'locked';
    const tone = isPaidOrLocked ? 'done' : 'muted';
    const label = run.state === 'locked' ? (langData['state_locked'] || 'Locked') : (isPaidOrLocked ? (langData['state_paid'] || 'Paid') : (langData['status_pending'] || 'Pending'));
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtmlAp(tone, isPaidOrLocked ? 'fa-money-check-dollar' : 'fa-flag')}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['state_paid'] || 'Paid'}</span>
                    ${apvBadgeHtmlAp(tone, label)}
                </div>
                ${isPaidOrLocked && run.paid_at ? `<div class="apv-stage-date">${escapeHtmlAp(run.paid_at)}</div>` : ''}
                <div class="apv-stage-body">
                    <span class="apv-muted-text">${isPaidOrLocked ? '' : (langData['waiting_for_approval_to_complete'] || 'Waiting for the approval process to complete.')}</span>
                </div>
            </div>
        </div>
    `;
}
function apvCreatedStageHtmlAp(run) {
    const creator = (currentLang === 'th' ? run.created_by_name_th : run.created_by_name_en) || run.created_by_name_th || run.created_by_name_en || '-';
    return `
        <div class="apv-stage apv-stage-last">
            <div class="apv-stage-marker">${apvIconHtmlAp('done', 'fa-plus')}</div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['stage_created'] || 'Created'}</span>
                    ${apvBadgeHtmlAp('done', langData['stage_created'] || 'Created')}
                </div>
                <div class="apv-stage-date">${escapeHtmlAp(run.created_at || '')}</div>
                <div class="apv-stage-body">${apvPersonLineHtmlAp(creator)}</div>
            </div>
        </div>
    `;
}
function auditActionLabelAp(action) {
    const map = {
        create: 'action_create', update: 'action_edit', recalculate: 'action_recalculate',
        submit: 'action_submit', revert: 'action_revert', approve: 'action_approve',
        reject: 'action_reject', reviseAfterReject: 'action_revise', markPaid: 'action_mark_paid',
        lock: 'action_lock', delete: 'action_delete', cancel: 'action_cancel',
        request_info: 'action_request_info', reviseAfterNeedInfo: 'action_revise',
    };
    const key = map[action];
    return (key && langData[key]) || action;
}
function renderAuditTimelineAp(logs) {
    if (!logs || !logs.length) {
        return `<div class="text-secondary small">${langData['no_history_yet'] || 'No action has been taken on this request yet.'}</div>`;
    }
    const ordered = logs.slice().reverse(); // newest first at the top, oldest at the bottom
    return ordered.map(l => {
        const actor = (currentLang === 'th' ? l.performed_by_name_th : l.performed_by_name_en) || l.performed_by_name_th || l.performed_by_name_en || '-';
        const metaParts = [];
        if (l.ip_address) metaParts.push(`<i class="fa-solid fa-location-dot"></i> ${escapeHtmlAp(l.ip_address)}`);
        if (l.user_agent) metaParts.push(`<i class="fa-solid fa-desktop"></i> ${escapeHtmlAp(l.user_agent)}`);
        return `<div class="apv-log-entry">
            <div class="apv-log-date">${escapeHtmlAp(l.performed_at)}</div>
            <div class="apv-log-action">${escapeHtmlAp(auditActionLabelAp(l.action))} <span class="text-secondary fw-normal">(${escapeHtmlAp(actor)})</span></div>
            ${metaParts.length ? `<div class="apv-log-meta">${metaParts.join(' &nbsp; ')}</div>` : ''}
            ${l.note ? `<div class="apv-log-note">${escapeHtmlAp(l.note)}</div>` : ''}
        </div>`;
    }).join('');
}
/* Target status => {label langData key/fallback, icon, button color} for the "revert a DECIDED
   run to a CHOSEN other status" buttons (2026-08-24, explicit request -- see
   PayrollRunModel::revert()'s own docblock: any of these 3 except the run's CURRENT status). */
const REVERT_TARGET_META_AP = {
    pending_approval: { key: 'action_revert_to_pending', fallback: 'Back to Waiting for Approval', icon: 'fa-hourglass-half', cls: 'btn-outline-secondary' },
    rejected: { key: 'action_revert_to_rejected', fallback: 'Set as Not Approved', icon: 'fa-xmark', cls: 'btn-outline-danger' },
    need_info: { key: 'action_revert_to_need_info', fallback: 'Set as Need Info', icon: 'fa-circle-info', cls: 'btn-outline-primary' },
};
function renderApprovalTimelineModal(data) {
    currentTimelineRun = data;
    $('#approvalTimelineRunName').text(data.run_name || '');
    const revertableStates = ['pending_approval', 'approved', 'rejected', 'need_info'];
    let actionsHtml = '';
    if (data.can_approve_payroll && revertableStates.includes(data.state)) {
        const buttons = [];
        if (data.state === 'pending_approval') {
            buttons.push(`<button type="button" class="btn btn-sm btn-success" id="btnTimelineApprove"><i class="fa-solid fa-check me-1"></i>${langData['action_approve'] || 'Approve'}</button>`);
            buttons.push(`<button type="button" class="btn btn-sm btn-primary" id="btnTimelineRequestInfo"><i class="fa-solid fa-circle-info me-1"></i>${langData['action_request_info'] || 'Request Info'}</button>`);
            buttons.push(`<button type="button" class="btn btn-sm btn-danger" id="btnTimelineReject"><i class="fa-solid fa-xmark me-1"></i>${langData['action_reject'] || 'Reject'}</button>`);
            buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-timeline-revert-to" data-to-state="draft"><i class="fa-solid fa-rotate-left me-1"></i>${langData['action_revert'] || 'Send Back for Revision'}</button>`);
        } else {
            // A DECIDED run (approved/rejected/need_info) -- one button per OTHER valid target
            // status, the approver's choice, never the same status it's already at.
            Object.keys(REVERT_TARGET_META_AP).forEach(target => {
                if (target === data.state) return;
                const meta = REVERT_TARGET_META_AP[target];
                buttons.push(`<button type="button" class="btn btn-sm ${meta.cls} btn-timeline-revert-to" data-to-state="${target}"><i class="fa-solid ${meta.icon} me-1"></i>${langData[meta.key] || meta.fallback}</button>`);
            });
        }
        actionsHtml = buttons.join('');
    }
    // 2026-08-23, explicit request ("ในหน้า Approve Modal Approval Timeline พวกปุ่มที่กด อยากให้มาอยู่ที่
    // Modal Footer") -- action buttons render in the modal FOOTER now, not floated above the stage
    // spine in the body.
    $('#approvalTimelineModalActions').html(actionsHtml);
    $('#approvalTimelineModalBody').html(`
        <div class="apv-timeline">
            ${apvPaidStageHtmlAp(data)}
            ${apvApprovalStageHtmlAp(data)}
            ${apvCreatedStageHtmlAp(data)}
        </div>
        <hr>
        <h6 class="fw-bold small text-uppercase text-secondary">${langData['approval_history'] || 'History'}</h6>
        <div class="apv-timeline-log">${renderAuditTimelineAp(data.audit_log)}</div>
    `);
}
function openApprovalTimeline(id) {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.approval-timeline`,
        method: 'GET',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            renderApprovalTimelineModal(res.data);
            new bootstrap.Modal(document.getElementById('approvalTimelineModal')).show();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
$(document).on('click', '#btnTimelineApprove', function () {
    bootstrap.Modal.getInstance(document.getElementById('approvalTimelineModal')).hide();
    openApproveModal([Number(currentTimelineRun.id)]);
});
$(document).on('click', '#btnTimelineReject', function () {
    bootstrap.Modal.getInstance(document.getElementById('approvalTimelineModal')).hide();
    openRejectModal([Number(currentTimelineRun.id)]);
});
$(document).on('click', '#btnTimelineRequestInfo', function () {
    bootstrap.Modal.getInstance(document.getElementById('approvalTimelineModal')).hide();
    openRequestInfoModal([Number(currentTimelineRun.id)]);
});
$(document).on('click', '.btn-timeline-revert-to', function () {
    const id = currentTimelineRun.id;
    const toState = $(this).data('to-state');
    const isPending = currentTimelineRun.state === 'pending_approval';
    let title, message;
    if (isPending) {
        title = langData['confirm_revert_title'] || 'Send this payroll run back for revision?';
        message = langData['confirm_revert_message'] || 'It will return to draft so the submitter can make changes.';
    } else {
        const meta = REVERT_TARGET_META_AP[toState];
        title = langData['confirm_revert_to_title'] || 'Change this run\'s status?';
        message = (langData['confirm_revert_to_message'] || 'This payroll run will be set to: {status}').replace('{status}', (meta && (langData[meta.key] || meta.fallback)) || toState);
    }
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.revert`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id, to_state: isPending ? undefined : toState }),
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('approvalTimelineModal')).hide();
                    if (tb_payroll_approval) tb_payroll_approval.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
    });
});

function initPayrollApprovalTable() {
    if ($.fn.DataTable.isDataTable('#tb_payroll_approval')) {
        $('#tb_payroll_approval').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payroll_approval = $('#tb_payroll_approval').DataTable({
        responsive: true,
        order: [[6, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/payroll-run.list`,
            // 2026-08-22, bug fix (explicit report: "ตอนนี้ไม่มีข้อมูลไม่เห็นตาราง") -- the table
            // used to hide itself entirely when zero rows matched. Now it always stays visible,
            // same as the Process List page's own table, relying on DataTables' native
            // zeroRecords/emptyTable text instead of a custom placeholder.
            dataSrc: function (res) {
                // approval_queue=1 (below) already asks the server to drop any pending_approval row
                // this viewer has no currently-actionable step on (see PayrollRunModel::list()'s own
                // docblock, 2026-08-24) -- this client-side filter only narrows to the 4 states this
                // page ever shows, it's not the access-control boundary.
                return (res.data || []).filter(r => ['pending_approval', 'approved', 'rejected', 'need_info'].includes(r.state));
            },
            data: function (d) {
                d.state = '';
                d.approval_queue = 1;
                d.date_from = toIsoDateAp($('#approval_filter_date_from').val());
                d.date_to = toIsoDateAp($('#approval_filter_date_to').val());
            }
        },
        columns: [
            { data: null, orderable: false, render: (d, t, row) => approvalCheckboxHtml(row) },
            { data: 'run_name', render: d => `<strong class="text-dark">${escapeHtmlAp(d)}</strong>` },
            { data: null, render: (d, t, row) => `${toDisplayDateAp(row.period_start_date)} - ${toDisplayDateAp(row.period_end_date)}` },
            { data: 'state', render: d => stateBadgeAp(d) },
            { data: 'employee_count', className: 'text-end' },
            { data: 'total_net_amount', className: 'text-end', render: d => fmtNumAp(d) },
            { data: null, render: (d, t, row) => escapeHtmlAp(submitterNameAp(row)) },
            { data: 'submitted_at', render: d => d ? toDisplayDateAp(d.substring(0, 10)) + ' ' + d.substring(11, 16) : '-' },
            // "Last Updated" = updated_at, same column/semantics as the Process List page's own
            // (2026-08-23, explicit request: "หน้า Process List และ Approval List ให้แสดงวันที่ของ
            // Status ล่าสุดด้วย") -- every state transition (submit/approve/reject/request-info/
            // revert/markPaid/lock/cancel) explicitly sets updated_at in its own UPDATE, and a run
            // is otherwise immutable once it leaves draft, so this always reflects exactly when the
            // CURRENT status was reached, not just "last touched" (which for a draft run tracks the
            // last edit/recalculate -- also correct, since a draft doesn't have a "status date" of
            // its own beyond that).
            { data: 'updated_at', render: d => d ? toDisplayDateAp(d.substring(0, 10)) + ' ' + d.substring(11, 16) : '-' },
            { data: null, className: 'text-center', orderable: false, render: (d, t, row) => renderApprovalViewActions(row) },
            { data: null, className: 'text-center', orderable: false, render: (d, t, row) => renderApprovalDecisionActions(row) },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            // Relocate the bulk action bar (static markup above the table) into the DataTables
            // length control row so it sits right after "Show N entries" instead of on its own
            // line -- same idiom as index.js's #bulkPullBar. Only moves the existing DOM node
            // (keeps its d-none/d-inline-flex toggling untouched), not a copy.
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $lengthDiv = $wrapper.find('.dt-length');
            if ($lengthDiv.length && $('#approvalBulkBar').closest('.dt-length').length === 0) {
                $lengthDiv.append($('#approvalBulkBar'));
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the row-select checkbox (0) and the two action-button
            // columns (9, 10).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 1, key: 'run_name' },
                    { index: 2, key: 'period' },
                    { index: 3, key: 'state' },
                    { index: 4, key: 'employee_count' },
                    { index: 5, key: 'total_net_amount' },
                    { index: 6, key: 'submitter' },
                    { index: 7, key: 'submitted_at' },
                    { index: 8, key: 'updated_at' },
                ]
            });
        },
        drawCallback: function () {
            getTableLang();
            updateApprovalStationCounts();
            const $rowBoxes = $('#tb_payroll_approval tbody .approval-row-checkbox');
            $rowBoxes.each(function () {
                $(this).prop('checked', Object.prototype.hasOwnProperty.call(selectedApprovalRuns, $(this).val()));
            });
            $('#approvalSelectAll').prop('checked', $rowBoxes.length > 0 && $rowBoxes.filter(':not(:checked)').length === 0);
        }
    });
    $(document).off('click', '.btn-view-approval-run').on('click', '.btn-view-approval-run', function (e) {
        e.stopPropagation();
        const publicId = $(this).data('public-id');
        if (publicId) window.open(`${BASE_URL}/payroll-process/${publicId}`, '_blank', 'noopener');
    });
    $('#tb_payroll_approval tbody').off('click', 'tr').on('click', 'tr', function (e) {
        if ($(e.target).closest('.approval-row-checkbox, .btn-group').length) return;
        const rowData = tb_payroll_approval.row(this).data();
        if (rowData && rowData.public_id) {
            window.open(`${BASE_URL}/payroll-process/${rowData.public_id}`, '_blank', 'noopener');
        }
    });
}

/* ---------- Checkbox selection ---------- */
$(document).on('change', '.approval-row-checkbox', function () {
    const id = $(this).val();
    if (this.checked) {
        selectedApprovalRuns[id] = true;
    } else {
        delete selectedApprovalRuns[id];
    }
    const $boxes = $('#tb_payroll_approval tbody .approval-row-checkbox');
    $('#approvalSelectAll').prop('checked', $boxes.length > 0 && $boxes.filter(':not(:checked)').length === 0);
    updateApprovalBulkBar();
});
$(document).on('change', '#approvalSelectAll', function () {
    $('#tb_payroll_approval tbody .approval-row-checkbox').prop('checked', this.checked).trigger('change');
});

/* ---------- Station cards + filter toggle/date-filter (mirrors index.js's own wiring) ---------- */
$(document).on('click', '#approvalStationRow .station-card', function () {
    showApprovalStation($(this).data('state') || '');
});
$(document).on('click', '#approvalStationFilterToggle', function () {
    const $filter = $('#approvalStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
function updateApprovalClearFilterVisibility() {
    const hasFilter = !!($('#approval_filter_date_from').val() || $('#approval_filter_date_to').val());
    $('#btnClearApprovalDateFilter').toggleClass('d-none', !hasFilter);
}
$(document).on('changeDate', '#approval_filter_date_from, #approval_filter_date_to', function () {
    updateApprovalClearFilterVisibility();
    if (tb_payroll_approval) tb_payroll_approval.ajax.reload(null, true);
});
$(document).on('click', '#btnClearApprovalDateFilter', function () {
    $('#approval_filter_date_from, #approval_filter_date_to').datepicker('clearDates');
});

/* ---------- Timeline button ---------- */
$(document).on('click', '.btn-view-approval-timeline', function (e) {
    e.stopPropagation();
    openApprovalTimeline($(this).data('id'));
});

/* ---------- Approve modal (single row via one button, or bulk via the selected checkboxes) --
   both funnel into the same #approveRunForm/#approve_run_ids, and always POST to
   payroll-run.bulk-approve (an array of 1 id for the single-row case) -- one code path, matching
   PayrollRunModel::bulkApprove()'s own design (a thin loop over the single-run approve()). ---------- */
function openApproveModal(ids) {
    $('#approve_run_ids').val(JSON.stringify(ids));
    $('#approve_note').val('');
    const summary = ids.length > 1
        ? (langData['approve_summary_bulk'] || `Approve ${ids.length} selected payroll runs?`).replace('{count}', ids.length)
        : '';
    $('#approveRunSummary').text(summary);
    new bootstrap.Modal(document.getElementById('approveRunModal')).show();
}
function openRejectModal(ids) {
    $('#reject_run_ids').val(JSON.stringify(ids));
    $('#reject_reason').val('').removeClass('is-invalid');
    const summary = ids.length > 1
        ? (langData['reject_summary_bulk'] || `Reject ${ids.length} selected payroll runs?`).replace('{count}', ids.length)
        : '';
    $('#rejectRunSummary').text(summary);
    new bootstrap.Modal(document.getElementById('rejectRunModal')).show();
}
// 2026-08-22, explicit request ("Need Information" as a real third state) -- same
// single-row-or-bulk unified pattern as approve/reject above.
function openRequestInfoModal(ids) {
    $('#request_info_run_ids').val(JSON.stringify(ids));
    $('#request_info_reason').val('').removeClass('is-invalid');
    const summary = ids.length > 1
        ? (langData['request_info_summary_bulk'] || `Request information on ${ids.length} selected payroll runs?`).replace('{count}', ids.length)
        : '';
    $('#requestInfoRunSummary').text(summary);
    new bootstrap.Modal(document.getElementById('requestInfoRunModal')).show();
}
$(document).on('click', '.btn-approve-run', function (e) {
    e.stopPropagation();
    openApproveModal([Number($(this).data('id'))]);
});
$(document).on('click', '.btn-reject-run', function (e) {
    e.stopPropagation();
    openRejectModal([Number($(this).data('id'))]);
});
$(document).on('click', '.btn-request-info-run', function (e) {
    e.stopPropagation();
    openRequestInfoModal([Number($(this).data('id'))]);
});
$(document).on('click', '#btnBulkApprove', function () {
    openApproveModal(Object.keys(selectedApprovalRuns).map(Number));
});
$(document).on('click', '#btnBulkReject', function () {
    openRejectModal(Object.keys(selectedApprovalRuns).map(Number));
});
$(document).on('click', '#btnBulkRequestInfo', function () {
    openRequestInfoModal(Object.keys(selectedApprovalRuns).map(Number));
});
function clearApprovalSelection() {
    selectedApprovalRuns = {};
    updateApprovalBulkBar();
}
$(document).on('submit', '#approveRunForm', function (e) {
    e.preventDefault();
    const ids = JSON.parse($('#approve_run_ids').val() || '[]');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.bulk-approve`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ ids: ids, note: $('#approve_note').val().trim() || null }),
        success: function (res) {
            bootstrap.Modal.getInstance(document.getElementById('approveRunModal')).hide();
            if (res.status) {
                const msg = res.total > 1
                    ? (langData['bulk_action_partial_result'] || '{succeeded} of {total} processed successfully.').replace('{succeeded}', res.succeeded).replace('{total}', res.total)
                    : (langData['save_success'] || 'Saved successfully.');
                if (res.succeeded === res.total) { showSuccess(msg); } else { showWarning(msg); }
                clearApprovalSelection();
                tb_payroll_approval.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});
$(document).on('submit', '#rejectRunForm', function (e) {
    e.preventDefault();
    const reason = $('#reject_reason').val().trim();
    if (!reason) {
        $('#reject_reason').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const ids = JSON.parse($('#reject_run_ids').val() || '[]');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.bulk-reject`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ ids: ids, reason: reason }),
        success: function (res) {
            bootstrap.Modal.getInstance(document.getElementById('rejectRunModal')).hide();
            if (res.status) {
                const msg = res.total > 1
                    ? (langData['bulk_action_partial_result'] || '{succeeded} of {total} processed successfully.').replace('{succeeded}', res.succeeded).replace('{total}', res.total)
                    : (langData['save_success'] || 'Saved successfully.');
                if (res.succeeded === res.total) { showSuccess(msg); } else { showWarning(msg); }
                clearApprovalSelection();
                tb_payroll_approval.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});
$(document).on('submit', '#requestInfoRunForm', function (e) {
    e.preventDefault();
    const reason = $('#request_info_reason').val().trim();
    if (!reason) {
        $('#request_info_reason').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const ids = JSON.parse($('#request_info_run_ids').val() || '[]');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.bulk-request-info`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ ids: ids, reason: reason }),
        success: function (res) {
            bootstrap.Modal.getInstance(document.getElementById('requestInfoRunModal')).hide();
            if (res.status) {
                const msg = res.total > 1
                    ? (langData['bulk_action_partial_result'] || '{succeeded} of {total} processed successfully.').replace('{succeeded}', res.succeeded).replace('{total}', res.total)
                    : (langData['save_success'] || 'Saved successfully.');
                if (res.succeeded === res.total) { showSuccess(msg); } else { showWarning(msg); }
                clearApprovalSelection();
                tb_payroll_approval.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});

$(document).ready(function () {
    registerApprovalStationSearchFilter();
    initPayrollApprovalTable();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#approval_filter_date_from');
        initDatepicker('#approval_filter_date_to');
    }
});
