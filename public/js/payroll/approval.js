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
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group/border-start convention.
function renderApprovalViewActions(row) {
    let html = '<div class="d-flex gap-1 justify-content-center">';
    if (row.public_id) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-info btn-view-approval-run" data-public-id="${row.public_id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>`;
    }
    html += `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-view-approval-timeline" data-id="${row.id}" title="${langData['action_timeline'] || 'Timeline'}"><i class="fa-solid fa-list-check"></i></button>`;
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
    let html = '<div class="d-flex gap-1 justify-content-center">';
    html += `<button type="button" class="btn btn-link btn-circle-action text-success btn-approve-run" data-id="${row.id}" title="${langData['action_approve'] || 'Approve'}"><i class="fa-solid fa-check"></i></button>`;
    html += `<button type="button" class="btn btn-link btn-circle-action text-primary btn-request-info-run" data-id="${row.id}" title="${langData['action_request_info'] || 'Request Info'}"><i class="fa-solid fa-circle-info"></i></button>`;
    html += `<button type="button" class="btn btn-link btn-circle-action text-danger btn-reject-run" data-id="${row.id}" title="${langData['action_reject'] || 'Reject'}"><i class="fa-solid fa-xmark"></i></button>`;
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
// 2026-09-10, Batch 3A item 3: APV_COLORS_AP/apvBadgeHtmlAp/apvIconHtmlAp/apvAvatarImgErrorAp/
// apvAvatarHtmlAp/apvPersonLineHtmlAp moved to app.js's own APV_COLORS/apvBadgeHtml()/
// apvIconHtml()/apvAvatarImgError()/apvAvatarHtml()/apvPersonLineHtml() -- confirmed byte-identical
// across index.js/detail.js/approval.js before merging.
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
            <span class="apv-substep-label">${apvAvatarHtml(name, 22, a.profile_photo_path)}${escapeHtml(name)}</span>
            ${apvBadgeHtml(tone, apvApproverLabelAp(a.status))}
        </div>
        ${a.acted_at ? `<div class="apv-substep-date"><i class="fa-regular fa-calendar"></i> ${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(a.acted_at) : escapeHtml(a.acted_at)}</div>` : ''}
        ${a.note ? `<div class="apv-substep-remark">${escapeHtml(a.note)}</div>` : ''}
    </div>`;
}
// 2026-09-10, Batch 3A item 2: moved to app.js's own apvApprovalStageInfo() (shared with
// index.js/detail.js's own identical copies).
// 2026-08-30, explicit follow-up ("ยังไม่ได้ปรับ UI...ให้แสดงหลาย step ที่ actionable พร้อมกันแบบจุดๆ ว่า
// ตัวเองอยู่ตำแหน่งไหน และตำแหน่งก่อนหน้านั้นอนุมัติหรือยัง") -- see index.js's own equivalent comment for
// the full reasoning (mirrored here per this file's own "duplicate, don't share across pages" convention).
function apvStepDotToneAp(step) {
    if (!step.unlocked) return 'apv-step-dot-locked';
    if (step.status === 'approved') return 'apv-step-dot-approved';
    if (step.status === 'rejected') return 'apv-step-dot-rejected';
    return 'apv-step-dot-pending';
}
function apvStepDotsHtmlAp(steps) {
    return `<div class="apv-step-dots">` + steps.map((s, i) => {
        const lockIcon = !s.unlocked ? `<span class="apv-step-dot-lock-icon"><i class="fa-solid fa-lock"></i></span>` : '';
        const icon = s.status === 'approved' ? '<i class="fa-solid fa-check"></i>' : (s.status === 'rejected' ? '<i class="fa-solid fa-xmark"></i>' : s.step_order);
        const connector = i < steps.length - 1 ? `<div class="apv-step-dot-connector${s.status === 'approved' ? ' apv-step-dot-connector-done' : ''}"></div>` : '';
        return `<div class="apv-step-dot-wrap" title="${escapeHtml(s.step_name || '')}">
            <div class="apv-step-dot ${apvStepDotToneAp(s)}">${icon}</div>
            ${lockIcon}
        </div>${connector}`;
    }).join('') + `</div>`;
}
function apvStepGroupHtmlAp(step) {
    const badgeHtml = !step.unlocked
        ? `<span class="apv-badge" style="background:#f1f5f9;color:#64748b;"><i class="fa-solid fa-lock me-1"></i>${langData['step_locked'] || 'Locked'}</span>`
        : apvBadgeHtml(apvApproverToneAp(step.status), apvApproverLabelAp(step.status));
    const stepLabel = (langData['step_label'] || 'Step {n}').replace('{n}', step.step_order);
    const approversHtml = step.approvers.length
        ? step.approvers.map(apvApproverSubstepHtmlAp).join('')
        : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`;
    return `<div class="apv-step-group">
        <div class="apv-step-group-head">
            <span class="apv-step-group-title">${escapeHtml(stepLabel)}${step.step_name ? ': ' + escapeHtml(step.step_name) : ''}</span>
            ${badgeHtml}
        </div>
        <div class="apv-step-group-body">${approversHtml}</div>
    </div>`;
}
function apvApprovalStageHtmlAp(run) {
    const info = apvApprovalStageInfo(run.state);
    const steps = (run.approval_flow && run.approval_flow.steps) || null;
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    const bodyHtml = (steps && steps.length)
        ? apvStepDotsHtmlAp(steps) + steps.map(apvStepGroupHtmlAp).join('')
        : (approvers.length
            ? approvers.map(apvApproverSubstepHtmlAp).join('')
            : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`);
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtml(info.tone, info.icon)}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['approval_flow_title'] || 'Approval'}</span>
                    ${apvBadgeHtml(info.tone, info.label)}
                </div>
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
// 2026-09-10, Batch 3A item 3: apvPaidStageHtmlAp()/apvCreatedStageHtmlAp() moved to app.js's own
// apvPaidStageHtml()/apvLockedStageHtml() (the old merged Paid/Locked box split in 2) and
// apvCreatedStageHtml() -- see renderApprovalTimelineModal() below for the new call sites.
// 2026-09-10: moved to app.js as auditActionLabel() -- shared with detail.js/index.js's own Timeline
// modals. Real bug fixed in the process: this file's own copy mapped 'lock' to 'action_lock' ("Lock")
// while detail.js/index.js already used 'action_verify_run' ("Verify") for the SAME action code since
// the 2026-08-31 rename -- the shared table now uses 'action_verify_run' everywhere, matching the
// other 2 pages.
// 2026-09-10: renderAuditTimelineAp() removed -- this modal's "History" section is gone (duplicated
// the Detail page's own Action History tab, see renderApprovalTimelineModal() below); auditActionLabel()
// itself (app.js) is untouched, still used by detail.js's own tab.
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
    const lifecycle = runLifecycleSteps(data, { showDates: true });
    $('#approvalTimelineModalBody').html(`
        <div class="apv-timeline">
            ${apvLockedStageHtml(data, lifecycle)}
            ${apvPaidStageHtml(data, lifecycle)}
            ${apvApprovalStageHtmlAp(data)}
            ${apvCreatedStageHtml(data)}
        </div>
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
            { data: 'run_name', render: d => `<strong class="text-dark">${escapeHtml(d)}</strong>` },
            { data: null, render: (d, t, row) => `${toDisplayDateAp(row.period_start_date)} - ${toDisplayDateAp(row.period_end_date)}` },
            { data: 'state', render: d => stateBadgeAp(d) },
            { data: 'employee_count', className: 'text-end' },
            // 2026-08-29, real bug found via a system-wide table audit: sort-safety fix -- plain
            // `render: fn` meant client-side sort/filter operated on the formatted "1,234.56"
            // string, not the raw numeric amount (same class of bug already documented in CLAUDE.md).
            { data: 'total_net_amount', className: 'text-end', render: { display: d => fmtNum(d), sort: d => Number(d || 0), filter: d => Number(d || 0) } },
            { data: null, render: (d, t, row) => escapeHtml(submitterNameAp(row)) },
            // 2026-08-29, real bug found and fixed (explicit report: "เวลาที่ Save ลงใน Database เป็น
            // UTC การแสดงผลให้แปลงเป็น timezone ปัจจุบันของผู้ใช้") -- was displaying the raw UTC time
            // straight from the DB string with no timezone conversion at all. Reuses
            // formatDisplayDateTime() (app.js) -- already UTC-aware, no need for a local copy of
            // the same technique here.
            // 2026-08-29, same-day: object-form render added (sort-safety audit) -- sort/filter now
            // key off the raw ISO datetime (still sorts correctly as a string) instead of the
            // dd/mm/yyyy display string.
            { data: 'submitted_at', render: { display: d => d ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d) : '-', sort: d => d || '', filter: d => d || '' } },
            // "Last Updated" = updated_at, same column/semantics as the Process List page's own
            // (2026-08-23, explicit request: "หน้า Process List และ Approval List ให้แสดงวันที่ของ
            // Status ล่าสุดด้วย") -- every state transition (submit/approve/reject/request-info/
            // revert/markPaid/lock/cancel) explicitly sets updated_at in its own UPDATE, and a run
            // is otherwise immutable once it leaves draft, so this always reflects exactly when the
            // CURRENT status was reached, not just "last touched" (which for a draft run tracks the
            // last edit/recalculate -- also correct, since a draft doesn't have a "status date" of
            // its own beyond that).
            { data: 'updated_at', render: { display: d => d ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d) : '-', sort: d => d || '', filter: d => d || '' } },
            // 2026-08-28, explicit request: "ตาราง Responsive ทุกตาราง Column ท้ายสุดต้องเป็นปุ่ม
            // ดำเนินการ แล้วไป hidden ส่วนอื่นเป็นตัว expand แทน" -- these 2 action-button columns
            // are already positioned last; `className: 'all'` (per DataTables Responsive's own
            // dtr-all convention, NOT 'never' -- see employee/list.js's own 2026-08-27 fix for why)
            // keeps them from ever collapsing into the expand row on a narrow viewport, letting
            // every OTHER column collapse there instead.
            { data: null, className: 'text-center all', orderable: false, render: (d, t, row) => renderApprovalViewActions(row) },
            { data: null, className: 'text-center all', orderable: false, render: (d, t, row) => renderApprovalDecisionActions(row) },
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
    $('#approvalDateFilterClearRow').toggleClass('d-none', !hasFilter);
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

// 2026-08-28, explicit request: reload this Approval Queue once Process Detail (opened in a
// separate browser tab) changes the run -- see markTabDirty()/watchTabDirty() in app.js.
if (typeof watchTabDirty === 'function') {
    watchTabDirty('payroll_run_list_dirty', function () {
        if (tb_payroll_approval) tb_payroll_approval.ajax.reload(null, false);
    });
}
// 2026-09-10, real bug fix -- see window.langReady's own docblock in app.js: deferred so this page's
// own initPayrollApprovalTable() renders real translated state badges on first load, not a raw enum
// fallback that never gets re-rendered afterward.
$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    registerApprovalStationSearchFilter();
    initPayrollApprovalTable();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#approval_filter_date_from');
        initDatepicker('#approval_filter_date_to');
    }
    });
});
