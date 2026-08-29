let tb_run_detail;
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
// 2026-08-29, real bug found and fixed (explicit report: "เวลาที่ Save ลงใน Database เป็น UTC การ
// แสดงผลให้แปลงเป็น timezone ปัจจุบันของผู้ใช้"). The process timeline below shows just a DATE per
// step (created_at/submitted_at/approved_at/paid_at/locked_at -- all real UTC timestamps), by
// truncating the raw string to its first 10 chars BEFORE any timezone conversion. That's not just
// imprecise, it can show the WRONG CALENDAR DAY: a timestamp like "2026-08-28 23:30:00" UTC is
// already "2026-08-29" in Bangkok (+7), but truncating the raw UTC string still reads "28". Fixed
// by running the full UTC-aware conversion first (formatDisplayDateTime(), same technique as
// app.js's own reference fix -- marks the string as UTC, then reads it back via local-timezone
// Date getters) and keeping only its date portion, instead of truncating the UTC string first.
function toLocalDateOnlyRd(value) {
    if (!value) return '';
    if (typeof formatDisplayDateTime !== 'function') return toDisplayDateRd(String(value).substring(0, 10));
    return formatDisplayDateTime(value).split(' ')[0];
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
        need_info: 'bg-primary-subtle text-primary',
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
        if (code === 'daily_salary_no_shift_pattern') return langData['calc_error_daily_salary_no_shift_pattern'] || 'Daily salary type but no Shift assigned -- paid for every non-holiday day; assign a Shift to exclude weekly off-days.';
        if (code === 'salary_type_hourly_not_supported') return langData['calc_error_salary_type_hourly_not_supported'] || 'Hourly salary type is not yet supported -- calculated using the monthly formula instead.';
        if (code.indexOf('no_rate_configured:') === 0) {
            const item = code.substring('no_rate_configured:'.length);
            const tpl = langData['calc_error_no_rate_configured'] || 'No statutory rate configured for {item}.';
            return tpl.replace('{item}', item);
        }
        if (code.indexOf('transfer_payee_not_in_run:') === 0) {
            const item = code.substring('transfer_payee_not_in_run:'.length);
            const tpl = langData['calc_error_transfer_payee_not_in_run'] || 'The transfer payee for {item} is not part of this run -- the deduction still applies, but nobody was credited.';
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
// Sync/Manual badge (2026-08-21, explicit request: "ต้องมีสัญลักษณ์ว่า ใคร Sync มา เพิ่มเข้ามาแบบ
// Manual") -- own dedicated "Source" column (2026-08-21, explicit request: "แยก Column Manual หรือ
// Sync ออกมาอีก Column" -- was previously appended inline next to the employee name, only on a
// sync-based run). Now that it has its own labeled header, always render the actual data_source
// (a plain cycle/off-cycle run showing "Manual" for every row is correct information, not noise,
// once it has a column of its own). row.data_source reflects payroll_run_details.data_source,
// wired in recalculate() instead of the hard-coded 'manual' literal it used to always be.
function dataSourceBadgeRd(row) {
    const isSync = row.data_source === 'sync';
    const cls = isSync ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary';
    const label = langData[isSync ? 'data_source_sync' : 'data_source_manual'] || (isSync ? 'Sync' : 'Manual');
    return `<span class="badge ${cls}">${label}</span>`;
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
// 2026-08-22, explicit request ("Status ในหน้า Approve มี Waiting Approve Not Approve Need
// Information") -- a REAL third state (confirmed with the user, not just a label), branching off
// pending_approval alongside 'rejected'. Rendered by branchInfo() below, next to
// renderProcessTimeline()'s own icon/label lookup.
const RUN_TIMELINE_BRANCH_INFO = {
    rejected: { icon: 'fa-xmark', labelKey: 'state_rejected' },
    cancelled: { icon: 'fa-ban', labelKey: 'state_cancelled' },
    need_info: { icon: 'fa-circle-question', labelKey: 'state_need_info' },
};
function computeTimelineProgress(run) {
    const state = run.state;
    if (state === 'rejected') {
        // Rejection always happens FROM pending_approval -- draft+pending_approval both actually
        // happened, the "Approved" slot is where the rejection branch shows instead.
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'rejected' } };
    }
    if (state === 'need_info') {
        // Same branch slot/reasoning as rejected above -- also only ever reached FROM pending_approval.
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'need_info' } };
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
// Two independent things render into a step's tl-actions slot:
//  1. "View Timeline" -- pinned PERMANENTLY at step 1 (the Approve station), and only once the run
//     has actually been submitted (run.submitted_at set). 2026-08-23, explicit request ("ปุ่ม
//     Timeline ควรมาอยู่ใน Station ของการ Approve มากกว่านะครับ ต้องส่ง Approve ก่อนค่อยขึ้นมาแสดงผล")
//     -- it used to follow whichever step was "current", which meant it showed at the draft/
//     Created step before anything had ever been sent for approval; now it has one fixed home and
//     stays hidden until there's actually an approval history worth viewing.
//  2. The decision/undo/revise buttons -- still anchor at whichever step is CURRENTLY relevant
//     (i === the current/branch step -- reachedIdx+1, which for a branched state
//     (rejected/need_info/cancelled) always equals branch.atIndex too, see
//     computeTimelineProgress() above): Approve/Request Info/Reject/Revert at pending_approval
//     (step 1 -- the same slot View Timeline lives in, so they render together there),
//     Undo Decision at approved (step 2), or Revise at the rejected/need_info branch (step 2's
//     branch slot). can_approve_payroll/can_process_payroll gate which of these actually show, same
//     as before. Submit is the one exception to both of the above: it moves the run INTO step 1
//     from step 0, so it renders under the destination step regardless of submitted_at (there's
//     nothing to submit yet if there were).
function timelineStepActionsHtml(i, run, currentIndex) {
    if (run.state === 'draft' && i === 1) {
        return `<button type="button" id="btnSubmitRun" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane me-1"></i><span data-i18n="action_submit">${langData['action_submit'] || 'Submit for Approval'}</span></button>`;
    }
    // 2026-08-27, explicit request ("ปุ่มในหน้า timeline ของ Process detail น่าจะมีคำกำหับในปุ่มให้ดู
    // ง่าย") -- every button here used to be icon-only with just a hover `title` tooltip, which
    // isn't discoverable at a glance (especially on a touch device, where hover tooltips don't
    // really exist). Every button below now carries a visible text label too (icon + `me-1` +
    // label span, same shape the standalone #btnSubmitRun button above and the Timeline modal's
    // own footer buttons in renderRunTimelineModal() already used) -- `title` is kept alongside
    // as a redundant a11y/tooltip hint, not the only way to read what the button does anymore.
    // .tl-actions-row's own CSS (style.css) was widened to fit a label, not just an icon.
    const buttons = [];
    if (i === 1 && run.submitted_at) {
        buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-view-timeline" title="${langData['action_timeline'] || 'Timeline'}"><i class="fa-solid fa-list-check me-1"></i>${langData['action_timeline'] || 'Timeline'}</button>`);
    }
    if (i === currentIndex) {
        if (run.state === 'pending_approval') {
            // 2026-08-27, explicit follow-up request ("ปรับ station ตรง Approve ตอนนี้มีหลายปุ่มครับ
            // สำหรับคนที่มีสิทธิ์อนุมัติ") -- an approver used to see 4 buttons stacked here at once
            // (Approve/Request Info/Reject/Send Back for Revision), on top of View Timeline right
            // above -- cluttered, especially once every button gained a text label the same day.
            // Approve stays its own prominent button (the common-case action); the other 3 collapse
            // into one "More" dropdown -- same delegated .btn-tl-request-info/.btn-tl-reject/
            // .btn-tl-revert click handlers still fire either way (class-based, not id-based), so no
            // JS handler changes were needed, only where these 3 buttons physically render.
            if (run.can_approve_payroll) {
                buttons.push(`<button type="button" class="btn btn-sm btn-success btn-tl-approve" title="${langData['action_approve'] || 'Approve'}"><i class="fa-solid fa-check me-1"></i>${langData['action_approve'] || 'Approve'}</button>`);
                buttons.push(`<div class="dropdown d-inline-block">
                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="${langData['action_more'] || 'More'}">
                        <i class="fa-solid fa-ellipsis"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item btn-tl-request-info" href="#"><i class="fa-solid fa-circle-info me-2"></i>${langData['action_request_info'] || 'Request Info'}</a></li>
                        <li><a class="dropdown-item text-danger btn-tl-reject" href="#"><i class="fa-solid fa-xmark me-2"></i>${langData['action_reject'] || 'Reject'}</a></li>
                        <li><a class="dropdown-item btn-tl-revert" href="#"><i class="fa-solid fa-rotate-left me-2"></i>${langData['action_revert'] || 'Send Back for Revision'}</a></li>
                    </ul>
                </div>`);
            } else if (run.can_process_payroll) {
                // 2026-08-23, explicit request ("ในกรณีที่ส่ง Approve แล้วยังไม่มีใคร Approve สามารถดึง
                // Process กลับได้") -- the submitter can pull their own still-undecided submission
                // back too, not just an approver -- see PayrollRunModel::revert()'s own docblock.
                // Only reachable here when can_approve_payroll is false (the branch above already
                // folds this same action into its own dropdown when both permissions are held), so
                // it's a single lone button, not a clutter case.
                buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-revert" title="${langData['action_revert'] || 'Send Back for Revision'}"><i class="fa-solid fa-rotate-left me-1"></i>${langData['action_revert'] || 'Send Back for Revision'}</button>`);
            }
        } else if (run.state === 'approved') {
            // 2026-08-27, explicit request ("จากอนุมัติแล้ว จะย้ายไป Station จ่ายแล้ว กดปุ่มไหน") --
            // this was a real gap: PayrollRunModel::markPaid()/the mark-paid endpoint were fully
            // built already but no button anywhere ever called them. can_finalize_payroll gates
            // this the same way can_approve_payroll gates Undo Decision right below it.
            if (run.can_finalize_payroll) {
                buttons.push(`<button type="button" class="btn btn-sm btn-primary btn-tl-mark-paid" title="${langData['action_mark_paid'] || 'Mark as Paid'}"><i class="fa-solid fa-money-check-dollar me-1"></i>${langData['action_mark_paid'] || 'Mark as Paid'}</button>`);
            }
            if (run.can_approve_payroll) {
                buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-revert" title="${langData['action_undo_decision'] || 'Undo Decision'}"><i class="fa-solid fa-rotate-left me-1"></i>${langData['action_undo_decision'] || 'Undo Decision'}</button>`);
            }
        } else if (run.state === 'paid' && run.can_finalize_payroll) {
            // 2026-08-27, explicit follow-up request ("เพิ่มปุ่ม Lock ให้ด้วยครับ") -- same gap/fix
            // as Mark as Paid right above: PayrollRunModel::lock()/the lock endpoint were already
            // fully built (and already had a quick-action shortcut on the Process LIST page's mini
            // timeline, see index.js's miniTimelineQuickActionHtml()) but the Detail page's own
            // step-by-step timeline never got an equivalent button at the "Paid" step.
            buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-lock" title="${langData['action_lock'] || 'Lock'}"><i class="fa-solid fa-lock me-1"></i>${langData['action_lock'] || 'Lock'}</button>`);
        } else if ((run.state === 'rejected' || run.state === 'need_info') && run.can_process_payroll) {
            buttons.push(`<button type="button" class="btn btn-sm btn-primary btn-tl-pull-back" title="${langData['action_revise'] || 'Revise'}"><i class="fa-solid fa-pen-to-square me-1"></i>${langData['action_revise'] || 'Revise'}</button>`);
        }
    }
    return buttons.length ? `<div class="tl-actions-row">${buttons.join('')}</div>` : '';
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
            const info = RUN_TIMELINE_BRANCH_INFO[branch.type] || { icon: 'fa-ban', labelKey: null };
            icon = info.icon;
            label = (info.labelKey && langData[info.labelKey]) || branch.type;
        } else if (i <= reachedIdx) {
            cls = 'done';
            icon = 'fa-check';
        } else if (i === currentIndex) {
            cls = 'current';
        }
        const dateVal = run[step.dateField];
        const dateHtml = (cls === 'done' || cls === 'current') && dateVal
            ? `<span class="tl-date"><i class="fa-regular fa-clock"></i> ${toLocalDateOnlyRd(dateVal)}</span>`
            : '';
        const actionsHtml = timelineStepActionsHtml(i, run, currentIndex);
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
// 2026-08-29, explicit request: "เพิ่มให้สามารถปริ้น Report จากหน้า Process ได้ ทั้งจากหน้า List และ Detail
// ส่งประกันสังคม ส่งสรรพากร ขึ้นธนาคาร" -- same 3 report shortcuts + same allowed-state gate as the
// List page's own row dropdown (public/js/payroll/index.js's PR_REPORT_SHORTCUTS/renderRunReportsDropdown) --
// kept as its own small copy here rather than a shared cross-file function, since the two pages
// don't share a common included JS file to put it in besides app.js, and this is small/simple
// enough that factoring it out isn't worth the indirection.
const RD_REPORT_SHORTCUTS = [
    { code: 'TH_SSO110', format: 'pdf', icon: 'fa-file-shield', labelKey: 'report_shortcut_sso110' },
    { code: 'TH_PND1', format: 'pdf', icon: 'fa-file-invoice', labelKey: 'report_shortcut_pnd1' },
    { code: 'BANK_TRANSFER_FILE', format: 'csv', icon: 'fa-building-columns', labelKey: 'report_shortcut_bank_transfer' },
];
const RD_REPORT_ALLOWED_STATES = ['approved', 'paid', 'locked'];
function renderRunReportsButtons(run) {
    const $wrap = $('#runReportsButtonWrap').empty();
    if (!RD_REPORT_ALLOWED_STATES.includes(run.state)) {
        $wrap.html(`<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="${langData['reports_available_after_approval'] || 'Reports are available once this run is approved.'}"><i class="fa-solid fa-file-export me-1"></i><span data-i18n="print_reports">${langData['print_reports'] || 'Print Reports'}</span></button>`);
        return;
    }
    const items = RD_REPORT_SHORTCUTS.map(r => `<li><a class="dropdown-item rd-report-btn" href="#" data-code="${r.code}" data-format="${r.format}"><i class="fa-solid ${r.icon} me-2"></i><span data-i18n="${r.labelKey}">${langData[r.labelKey] || r.code}</span></a></li>`).join('');
    $wrap.html(`<div class="dropdown">
        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="fa-solid fa-file-export me-1"></i><span data-i18n="print_reports">${langData['print_reports'] || 'Print Reports'}</span></button>
        <ul class="dropdown-menu dropdown-menu-end">${items}</ul>
    </div>`);
}
$(document).on('click', '.rd-report-btn', function (e) {
    e.preventDefault();
    if (!PAYROLL_RUN_ID) return;
    const params = new URLSearchParams();
    params.set('report_code', $(this).data('code'));
    params.set('format', $(this).data('format'));
    params.set('run_id', PAYROLL_RUN_ID);
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});
function renderSectionButtons(run) {
    const $editWrap = $('#runEditButtonWrap').empty();
    const $recalcWrap = $('#runRecalculateButtonWrap').empty();
    if (run.state !== 'draft') {
        return;
    }
    $editWrap.append(`<button type="button" id="btnEditRun" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="action_edit">${langData['action_edit'] || 'Edit'}</span></button>`);
    $recalcWrap.append(`<button type="button" id="btnRecalculate" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate me-1"></i><span data-i18n="action_recalculate">${langData['action_recalculate'] || 'Recalculate'}</span></button>`);
    // Join Employees is now shown on EVERY draft run (2026-08-21, explicit request -- this is also
    // the undo path for removeEmployeeButtonRd()'s now-universal remove). For an off-cycle/sync-based
    // run it still adds someone to payroll_run_manual_employees exactly as before. For a genuine
    // cycle-only run (membership otherwise fully automatic by date range), it now serves ONLY to
    // re-include a previously-removed employee -- see PayrollRunModel::joinEmployees()'s cycle-only
    // branch and manualEmployeeOptions(), which restricts that run type's picker to just the
    // currently-excluded employees.
    $recalcWrap.append(`<button type="button" id="btnJoinEmployees" class="btn btn-sm btn-outline-primary ms-2"><i class="fa-solid fa-user-plus me-1"></i><span data-i18n="action_join_employees">${langData['action_join_employees'] || 'Join Employees'}</span></button>`);
}

/* ---------- Per-run earning/deduction item selection (section 2): two panels (Earning left /
   Deduction right), always shown for a non-incentive run regardless of state -- an incentive run
   already picks items explicitly per employee and has no use for this table at all, so the whole
   section stays hidden there. Each panel shows every active item of that type, struck through when
   currently excluded -- this IS the "Default" view (nothing configured yet = every item ticked,
   see PayrollRunModel::getPedTypeSettings()'s docblock). The Edit button (and therefore the
   ability to actually change anything) only shows while the run is still draft -- once it can no
   longer be edited, the panels stay visible but are effectively View Mode. */
let pedTypeSettingsData = null;
let pedTypeEditingType = null;
function pedTypeItemLabelRd(item) {
    return (currentLang === 'th' ? item.item_name_th : item.item_name_en) || item.item_name_th || item.item_name_en;
}
function renderPedTypePanel(itemType, data, canEdit) {
    const panelId = itemType === 'earning' ? '#pedTypePanelEarning' : '#pedTypePanelDeduction';
    const items = (data && data.all_items) || [];
    const selected = new Set(((data && data.selected_ids) || []).map(Number));
    if (!items.length) {
        $(panelId).html(`<div class="text-muted small">-</div>`);
    } else {
        $(panelId).html(items.map(item => {
            const isOn = selected.has(Number(item.id));
            const cls = isOn ? 'bg-light text-dark border' : 'bg-light text-muted border text-decoration-line-through';
            return `<span class="badge ${cls} me-1 mb-1"><code>${escapeHtmlRd(item.item_code)}</code> ${escapeHtmlRd(pedTypeItemLabelRd(item))}</span>`;
        }).join(''));
    }
    $(`.btn-edit-ped-type-panel[data-item-type="${itemType}"]`).toggleClass('d-none', !canEdit);
}
function renderPedTypeSettings(run) {
    // 2026-08-27, explicit request ("การทำงานจ่ายนอกรอบ...มีให้ติ๊กเลือกบางรายการที่จะนำมาแก้ไขหรือไม่
    // นำมาแก้ไข") -- this panel used to hide outright for ANY incentive run (standing PED
    // assignments were never a source at all). Now it also shows once that run opted into
    // include_standing_items (new run.include_standing_items flag, set at creation -- see
    // PayrollRunModel::recalculate()'s own docblock) -- same PayrollRunModel::savePedTypeSettings()
    // endpoint this section already used for a normal run, now permitted for an incentive run too
    // under that same condition (see that method's own updated docblock).
    const hidePanel = run.run_purpose === 'incentive' && !run.include_standing_items;
    $('#pedTypeSettingsSection').toggleClass('d-none', hidePanel);
    if (hidePanel) return;
    pedTypeSettingsData = run.ped_type_settings || {};
    const canEdit = run.state === 'draft';
    renderPedTypePanel('earning', pedTypeSettingsData.earning, canEdit);
    renderPedTypePanel('deduction', pedTypeSettingsData.deduction, canEdit);
}
function openPedTypeEditModal(itemType) {
    if (!pedTypeSettingsData || !pedTypeSettingsData[itemType]) return;
    pedTypeEditingType = itemType;
    const data = pedTypeSettingsData[itemType];
    const selected = new Set((data.selected_ids || []).map(Number));
    const titleKey = itemType === 'earning' ? 'breakdown_earnings' : 'table_deduction_amount';
    $('#pedTypeEditModalTitle').text(langData[titleKey] || (itemType === 'earning' ? 'Earnings' : 'Deductions'));
    const items = data.all_items || [];
    if (!items.length) {
        $('#pedTypeEditModalList').html(`<div class="text-muted small text-center py-3">-</div>`);
    } else {
        $('#pedTypeEditModalList').html(items.map(item => {
            const checked = selected.has(Number(item.id)) ? 'checked' : '';
            return `<div class="form-check mb-2">
                <input class="form-check-input ped-type-edit-checkbox" type="checkbox" value="${item.id}" id="pedchk_${item.id}" ${checked}>
                <label class="form-check-label" for="pedchk_${item.id}"><code class="fw-bold text-dark">${escapeHtmlRd(item.item_code)}</code> ${escapeHtmlRd(pedTypeItemLabelRd(item))}</label>
            </div>`;
        }).join(''));
    }
    new bootstrap.Modal(document.getElementById('pedTypeEditModal')).show();
}
$(document).on('click', '.btn-edit-ped-type-panel', function () {
    openPedTypeEditModal($(this).data('item-type'));
});
$(document).on('click', '#btnSavePedTypeEdit', function () {
    const pedTypeIds = $('#pedTypeEditModalList .ped-type-edit-checkbox:checked').map(function () { return Number($(this).val()); }).get();
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save-ped-type-settings`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, item_type: pedTypeEditingType, ped_type_ids: pedTypeIds }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('pedTypeEditModal')).hide();
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

// A run pulled from a cycle or a REGULAR sync process is always full payroll -- editable for a
// genuine off-cycle run OR a sync-linked run pulled from a 'supplemental' Origami process (2026-
// 08-29, see PayrollRunModel::update()'s own matching gate -- PAYROLL_SYNC_API.md's run_kind
// field, "regular"/"supplemental": a standalone/ad-hoc cycle like OT-only or Trip-only is NOT
// real payroll by definition the way a regular cycle-matched pull is). cycle_id/sync_process_id/
// sync_run_kind come back from api/payroll-run.get as either a real value or null/empty string
// depending on how PDO happened to cast that row, so all three are checked loosely on purpose.
function isOffCycleRunRd(run) {
    if (!run.cycle_id && !run.sync_process_id) {
        return true;
    }
    return !!run.sync_process_id && run.sync_run_kind === 'supplemental';
}
function runTypeLabelRd(run) {
    if (run.run_purpose !== 'incentive') {
        return langData['run_purpose_payroll'] || 'Payroll';
    }
    const parts = [];
    if (Number(run.compute_statutory) === 1) parts.push(langData['compute_statutory_short'] || langData['compute_statutory_label'] || 'Tax/SSO/PVD');
    if (Number(run.include_base_salary) === 1) parts.push(langData['include_base_salary_short'] || langData['include_base_salary_label'] || 'Base salary');
    if (Number(run.include_standing_items) === 1) parts.push(langData['include_standing_items_short'] || langData['include_standing_items_label'] || 'Standing items');
    const label = langData['run_purpose_incentive'] || 'Incentive / Other Payment (no base salary)';
    return parts.length ? `${label} (${parts.join(', ')})` : label;
}
function updateEditRunTypeVisibility() {
    const isIncentive = $('#edit_run_purpose').val() === 'incentive';
    $('#edit_run_compute_statutory_row, #edit_run_include_base_salary_row, #edit_run_include_standing_items_row').toggleClass('d-none', !isIncentive);
}
$(document).on('change', '#edit_run_purpose', updateEditRunTypeVisibility);

function renderRunHeader(run) {
    currentRun = run;
    document.title = run.run_name;
    $('#bcRunName').text(run.run_name);
    $('#runNameHeading').text(run.run_name);
    $('#runStateBadge').html(stateBadgeRd(run.state));
    $('#infoCycle').text(run.cycle_name || langData['offcycle_run_short'] || 'Off-schedule');
    $('#infoPeriod').text(`${toDisplayDateRd(run.period_start_date)} - ${toDisplayDateRd(run.period_end_date)}`);
    $('#infoPaymentDate').text(toDisplayDateRd(run.payment_date));
    $('#infoEmployeeCount').text(run.employee_count);
    $('#infoGross').text(fmtNumRd(run.total_gross_amount));
    $('#infoDeduction').text(fmtNumRd(run.total_deduction_amount));
    $('#infoNet').text(fmtNumRd(run.total_net_amount));
    const creatorName = (currentLang === 'th' ? run.created_by_name_th : run.created_by_name_en) || run.created_by_name_th || run.created_by_name_en || '-';
    $('#infoCreatedBy').text(creatorName);
    $('#infoRunType').text(runTypeLabelRd(run));
    if (run.sync_process_id) {
        const kindLabel = run.sync_run_kind === 'supplemental'
            ? (langData['sync_run_kind_supplemental'] || 'Supplemental')
            : (langData['sync_run_kind_regular'] || 'Regular');
        const subject = run.sync_process_subject || (langData['sync_no_subject'] || 'Untitled');
        let range = '';
        if (run.sync_process_start && run.sync_process_end) {
            range = ` (${toDisplayDateRd(run.sync_process_start)} - ${toDisplayDateRd(run.sync_process_end)})`;
        }
        $('#infoSyncSource').text(`${subject}${range} — ${kindLabel}`);
        $('#infoSyncSourceWrap').removeClass('d-none');
    } else {
        $('#infoSyncSourceWrap').addClass('d-none');
    }

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
    renderRunReportsButtons(run);
    renderPedTypeSettings(run);
}

/* ---------- Approval Flow timeline modal (2026-08-22, explicit request: "เพิ่มปุ่ม view เข้าไปในหน้า
   Detail หากมีสิทธิ์อนุมัติ ตรงช่อง Timeline ให้มีปุ่มอนุมัติด้วย และถ้าอนุมัติไปแล้วให้มีปุ่ม Timeline
   กดดู และถ้า Process ยังสามารถถอยอนุมัติได้ ก็ให้กดถอยอนุมัติได้"; redesigned 2026-08-23 per explicit
   request: "ปุ่มการดู Flow การอนุมัต ให้ล้อมาจากรายการอนุมัติของ C:\xampp\htdocs\origami\payroll รวมถึง
   Design ต่างๆ ให้แสดงผลแบบเดียวกัน") -- same .apv-* vertical-stage design as the Approval Queue
   page's own Timeline modal (public/js/payroll/approval.js/style.css), duplicated rather than
   shared (same reasoning as that file's own comments -- this page reuses `currentRun`/
   `auditActionLabel()` already loaded via loadRunDetail() instead of a second AJAX call, since
   PayrollController::get() now returns approval_flow/can_approve_payroll/can_process_payroll
   alongside the audit_log it already returned). Approve/Reject/Request Info only render from
   pending_approval; Revert/Undo now also renders from an already-decided state
   (approved/rejected/need_info), all gated on run.can_approve_payroll (server-checked via
   PayrollRunModel::canApprovePayroll(), not just a state gate) -- this page used to show no action
   buttons at all once a run left draft (explicit request at the time); this reopens exactly that
   one path, scoped to users who can actually act. Separately, a "Pull Back to Edit" button (the
   SUBMITTER's own action, gated on can_process_payroll instead) appears next to the Timeline
   button whenever the run is rejected/need_info, wiring up reviseAfterReject()/
   reviseAfterNeedInfo() -- both existed in PayrollRunModel already but had no UI anywhere until now. */
function apvAvatarHtmlRd(name, size) {
    size = size || 26;
    const initial = (name || '?').trim().charAt(0).toUpperCase() || '?';
    return `<span class="apv-person-avatar" style="width:${size}px;height:${size}px;min-width:${size}px;font-size:${Math.round(size * 0.42)}px;">${escapeHtmlRd(initial)}</span>`;
}
function apvPersonLineHtmlRd(name) {
    return `<div style="display:flex;align-items:center;gap:8px;">${apvAvatarHtmlRd(name, 26)}<span class="apv-person-name">${escapeHtmlRd(name || '-')}</span></div>`;
}
const APV_COLORS_RD = {
    done: { icon: '#16a34a', badgeBg: '#dcfce7', badgeText: '#15803d' },
    pending: { icon: '#f59e0b', badgeBg: '#fef3c7', badgeText: '#b45309' },
    rejected: { icon: '#ef4444', badgeBg: '#fee2e2', badgeText: '#b91c1c' },
    info: { icon: '#0d6efd', badgeBg: '#cfe2ff', badgeText: '#0a58ca' },
    muted: { icon: '#cbd5e1', badgeBg: '#f1f5f9', badgeText: '#64748b' },
};
function apvBadgeHtmlRd(tone, label) {
    const c = APV_COLORS_RD[tone] || APV_COLORS_RD.muted;
    return `<span class="apv-badge" style="background:${c.badgeBg};color:${c.badgeText};">${escapeHtmlRd(label)}</span>`;
}
function apvIconHtmlRd(tone, icon) {
    const c = APV_COLORS_RD[tone] || APV_COLORS_RD.muted;
    return `<div class="apv-stage-icon" style="background:${c.icon};"><i class="fa-solid ${icon}"></i></div>`;
}
function apvApproverToneRd(status) {
    return { approved: 'done', rejected: 'rejected', need_info: 'info', pending: 'pending', not_applicable: 'muted' }[status] || 'muted';
}
function apvApproverLabelRd(status) {
    const key = { approved: 'status_approved', rejected: 'status_rejected', need_info: 'state_need_info', pending: 'status_pending' }[status];
    return (key && langData[key]) || status;
}
function apvApproverSubstepHtmlRd(a) {
    const name = (currentLang === 'th' ? a.name_th : a.name_en) || a.name_th || a.name_en || a.employee_no;
    return `<div class="apv-substep">
        <div class="apv-substep-head">
            <span class="apv-substep-label">${apvAvatarHtmlRd(name, 22)}${escapeHtmlRd(name)}</span>
            ${apvBadgeHtmlRd(apvApproverToneRd(a.status), apvApproverLabelRd(a.status))}
        </div>
        ${a.acted_at ? `<div class="apv-substep-date"><i class="fa-regular fa-calendar"></i> ${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(a.acted_at) : escapeHtmlRd(a.acted_at)}</div>` : ''}
        ${a.note ? `<div class="apv-substep-remark">${escapeHtmlRd(a.note)}</div>` : ''}
    </div>`;
}
function apvApprovalStageInfoRd(state) {
    switch (state) {
        case 'pending_approval': return { tone: 'pending', icon: 'fa-hourglass-half', label: langData['state_pending_approval'] || 'Waiting for Approval' };
        case 'need_info': return { tone: 'info', icon: 'fa-circle-info', label: langData['state_need_info'] || 'Need Information' };
        case 'approved': case 'paid': case 'locked': return { tone: 'done', icon: 'fa-check', label: langData['state_approved'] || 'Approved' };
        case 'rejected': return { tone: 'rejected', icon: 'fa-xmark', label: langData['state_rejected'] || 'Not Approved' };
        default: return { tone: 'muted', icon: 'fa-hourglass', label: langData['status_pending'] || 'Not Started' };
    }
}
function apvApprovalStageHtmlRd(run) {
    const info = apvApprovalStageInfoRd(run.state);
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    const bodyHtml = approvers.length
        ? approvers.map(apvApproverSubstepHtmlRd).join('')
        : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`;
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtmlRd(info.tone, info.icon)}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['approval_flow_title'] || 'Approval'}</span>
                    ${apvBadgeHtmlRd(info.tone, info.label)}
                </div>
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
function apvPaidStageHtmlRd(run) {
    const isPaidOrLocked = run.state === 'paid' || run.state === 'locked';
    const tone = isPaidOrLocked ? 'done' : 'muted';
    const label = run.state === 'locked' ? (langData['state_locked'] || 'Locked') : (isPaidOrLocked ? (langData['state_paid'] || 'Paid') : (langData['status_pending'] || 'Pending'));
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtmlRd(tone, isPaidOrLocked ? 'fa-money-check-dollar' : 'fa-flag')}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['state_paid'] || 'Paid'}</span>
                    ${apvBadgeHtmlRd(tone, label)}
                </div>
                ${isPaidOrLocked && run.paid_at ? `<div class="apv-stage-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(run.paid_at) : escapeHtmlRd(run.paid_at)}</div>` : ''}
                <div class="apv-stage-body">
                    <span class="apv-muted-text">${isPaidOrLocked ? '' : (langData['waiting_for_approval_to_complete'] || 'Waiting for the approval process to complete.')}</span>
                </div>
            </div>
        </div>
    `;
}
function apvCreatedStageHtmlRd(run) {
    const creator = (currentLang === 'th' ? run.created_by_name_th : run.created_by_name_en) || run.created_by_name_th || run.created_by_name_en || '-';
    return `
        <div class="apv-stage apv-stage-last">
            <div class="apv-stage-marker">${apvIconHtmlRd('done', 'fa-plus')}</div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['stage_created'] || 'Created'}</span>
                    ${apvBadgeHtmlRd('done', langData['stage_created'] || 'Created')}
                </div>
                <div class="apv-stage-date">${run.created_at ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(run.created_at) : escapeHtmlRd(run.created_at)) : ''}</div>
                <div class="apv-stage-body">${apvPersonLineHtmlRd(creator)}</div>
            </div>
        </div>
    `;
}
function renderAuditTimelineRd(logs) {
    if (!logs || !logs.length) {
        return `<div class="text-secondary small">${langData['no_history_yet'] || 'No action has been taken on this request yet.'}</div>`;
    }
    const ordered = logs.slice().reverse(); // newest first at the top, oldest at the bottom
    return ordered.map(l => {
        const actor = personDisplayNameRd(l, 'performed_by');
        const metaParts = [];
        if (l.ip_address) metaParts.push(`<i class="fa-solid fa-location-dot"></i> ${escapeHtmlRd(l.ip_address)}`);
        if (l.user_agent) metaParts.push(`<i class="fa-solid fa-desktop"></i> ${escapeHtmlRd(l.user_agent)}`);
        return `<div class="apv-log-entry">
            <div class="apv-log-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(l.performed_at) : escapeHtmlRd(l.performed_at)}</div>
            <div class="apv-log-action">${escapeHtmlRd(auditActionLabel(l.action))} <span class="text-secondary fw-normal">(${escapeHtmlRd(actor)})</span></div>
            ${metaParts.length ? `<div class="apv-log-meta">${metaParts.join(' &nbsp; ')}</div>` : ''}
            ${l.note ? `<div class="apv-log-note">${escapeHtmlRd(l.note)}</div>` : ''}
        </div>`;
    }).join('');
}
/* 2026-08-23, explicit request ("ในหน้า Process Detail ส่วนของปุ่มดำเนินการ หรือกด View อยากให้แสดงใน
   ช่องของ Timeline นั้นๆ เช่นปุ่มดึงกลับหรือปุ่มอนุมัติให้อยู่ตรงกับ Timeline ที่สามารถดำเนินการได้ และหาก
   มีสิทธิ์อนุมัติให้ขึ้นปุ่มอนุมัติที่สามารถกดได้ให้ตรงกับ Timeline เลย") -- the old standalone
   #runTimelineButtonWrap row above the timeline is gone; every action now renders inline inside
   the horizontal .process-timeline's own tl-actions slot, at whichever step it actually applies to
   (see timelineStepActionsHtml() below). Every trigger below is a CLASS, not an id, and the click
   handlers are delegated on that class -- the exact same "Approve" trigger can legitimately exist
   twice in the DOM at once (once inline on the timeline step, once in the Timeline modal's own
   footer, reached via that step's "View Timeline" button) and a class-based delegated handler
   handles that safely where duplicate ids would not. Handlers guard the runTimelineModal .hide()
   call since a click coming from the inline timeline chrome never had that modal open in the first
   place. */
function renderRunTimelineModal(run) {
    const buttons = [];
    if (run.state === 'pending_approval' && run.can_approve_payroll) {
        buttons.push(`<button type="button" class="btn btn-sm btn-success btn-tl-approve"><i class="fa-solid fa-check me-1"></i>${langData['action_approve'] || 'Approve'}</button>`);
        buttons.push(`<button type="button" class="btn btn-sm btn-primary btn-tl-request-info"><i class="fa-solid fa-circle-info me-1"></i>${langData['action_request_info'] || 'Request Info'}</button>`);
        buttons.push(`<button type="button" class="btn btn-sm btn-danger btn-tl-reject"><i class="fa-solid fa-xmark me-1"></i>${langData['action_reject'] || 'Reject'}</button>`);
    }
    // 2026-08-27: same Mark as Paid trigger as the inline timeline step button (see
    // timelineStepActionsHtml()'s own comment) -- this modal's footer is reachable via the
    // permanently-pinned "View Timeline" button at step 1, regardless of the run's current state.
    if (run.state === 'approved' && run.can_finalize_payroll) {
        buttons.push(`<button type="button" class="btn btn-sm btn-primary btn-tl-mark-paid"><i class="fa-solid fa-money-check-dollar me-1"></i>${langData['action_mark_paid'] || 'Mark as Paid'}</button>`);
    }
    if (run.state === 'paid' && run.can_finalize_payroll) {
        buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-lock"><i class="fa-solid fa-lock me-1"></i>${langData['action_lock'] || 'Lock'}</button>`);
    }
    // 2026-08-23, explicit request ("ในกรณีที่ส่ง Approve แล้วยังไม่มีใคร Approve สามารถดึง Process
    // กลับได้") -- pending_approval's own revert is open to the submitter (can_process_payroll) as
    // well as an approver, unlike approved/rejected/need_info's "undo a decision" revert which
    // stays approver-only -- see PayrollRunModel::revert()'s own docblock.
    const canRevertPending = run.state === 'pending_approval' && (run.can_approve_payroll || run.can_process_payroll);
    const canUndoDecision = ['approved', 'rejected', 'need_info'].includes(run.state) && run.can_approve_payroll;
    if (canRevertPending || canUndoDecision) {
        const revertLabel = run.state === 'pending_approval' ? (langData['action_revert'] || 'Send Back for Revision') : (langData['action_undo_decision'] || 'Undo Decision');
        buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-revert"><i class="fa-solid fa-rotate-left me-1"></i>${revertLabel}</button>`);
    }
    if (run.can_process_payroll && (run.state === 'rejected' || run.state === 'need_info')) {
        buttons.push(`<button type="button" class="btn btn-sm btn-primary btn-tl-pull-back"><i class="fa-solid fa-pen-to-square me-1"></i>${langData['action_revise'] || 'Revise'}</button>`);
    }
    // 2026-08-23, explicit request ("ในหน้า Approve Modal Approval Timeline พวกปุ่มที่กด อยากให้มาอยู่ที่
    // Modal Footer") -- same footer relocation as the Approval Queue page's own Timeline modal.
    $('#runTimelineModalActions').html(buttons.join(''));
    $('#runTimelineModalBody').html(`
        <div class="apv-timeline">
            ${apvPaidStageHtmlRd(run)}
            ${apvApprovalStageHtmlRd(run)}
            ${apvCreatedStageHtmlRd(run)}
        </div>
        <hr>
        <h6 class="fw-bold small text-uppercase text-secondary">${langData['approval_history'] || 'History'}</h6>
        <div class="apv-timeline-log">${renderAuditTimelineRd(run.audit_log)}</div>
    `);
}
$(document).on('click', '.btn-tl-view-timeline', function (e) {
    e.stopPropagation();
    if (!currentRun) return;
    renderRunTimelineModal(currentRun);
    new bootstrap.Modal(document.getElementById('runTimelineModal')).show();
});
$(document).on('click', '.btn-tl-pull-back', function (e) {
    e.stopPropagation();
    if (!currentRun) return;
    const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
    if (inst) inst.hide();
    const url = currentRun.state === 'rejected' ? '/api/payroll-run.revise-after-reject' : '/api/payroll-run.revise-after-need-info';
    showConfirm(langData['confirm_pull_back_title'] || 'Pull this payroll run back for editing?', langData['confirm_pull_back_message'] || 'It will return to draft so you can make changes and resubmit.', function () {
        callRunAction(url, {}, langData['save_success']);
    });
});
$(document).on('click', '.btn-tl-approve', function (e) {
    e.stopPropagation();
    const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
    if (inst) inst.hide();
    $('#run_approve_note').val('');
    new bootstrap.Modal(document.getElementById('runApproveModal')).show();
});
$(document).on('click', '.btn-tl-reject', function (e) {
    e.preventDefault(); // 2026-08-27: also rendered as a dropdown-item <a href="#"> now, see timelineStepActionsHtml()'s "More" dropdown
    e.stopPropagation();
    const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
    if (inst) inst.hide();
    $('#run_reject_reason').val('').removeClass('is-invalid');
    new bootstrap.Modal(document.getElementById('runRejectModal')).show();
});
$(document).on('click', '.btn-tl-request-info', function (e) {
    e.preventDefault(); // 2026-08-27: also rendered as a dropdown-item <a href="#"> now, see timelineStepActionsHtml()'s "More" dropdown
    e.stopPropagation();
    const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
    if (inst) inst.hide();
    $('#run_request_info_reason').val('').removeClass('is-invalid');
    new bootstrap.Modal(document.getElementById('runRequestInfoModal')).show();
});
$(document).on('click', '.btn-tl-mark-paid', function (e) {
    e.stopPropagation();
    if (!currentRun) return;
    const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
    if (inst) inst.hide();
    $('#run_mark_paid_method').val('bank_transfer').trigger('change');
    $('#run_mark_paid_reference').val('');
    // Defaults to the run's own scheduled payment_date -- matches PayrollRunModel::markPaid()'s
    // own fallback when payment_date is left blank, just shown up front so it's obvious what will
    // be used if the user doesn't change it. Must follow .val() with .datepicker('update') --
    // otherwise the widget's own internal state (this.dates, set to [] the first time
    // initDatepicker() ran on page load while this field was still empty) never learns about this
    // programmatic value, and clicking into/out of the field with no new pick would silently blank
    // it right back out (see CLAUDE.md's bootstrap-datepicker note for the full mechanism).
    $('#run_mark_paid_date').val(toDisplayDateRd(currentRun.payment_date)).datepicker('update');
    $('.is-invalid', '#runMarkPaidForm').removeClass('is-invalid');
    new bootstrap.Modal(document.getElementById('runMarkPaidModal')).show();
});
// The handlers below funnel through the generic callRunAction() helper (defined further down
// this same file, hoisted so the declaration order doesn't matter) instead of writing their own
// $.ajax blocks -- same id-scoped-to-PAYROLL_RUN_ID/reload-on-success/warn-on-failure shape every
// other action on this page already uses (btnRecalculate, etc.).
$(document).on('click', '.btn-tl-revert', function (e) {
    e.preventDefault(); // 2026-08-27: also rendered as a dropdown-item <a href="#"> now, see timelineStepActionsHtml()'s "More" dropdown
    e.stopPropagation();
    const isPending = currentRun && currentRun.state === 'pending_approval';
    const title = isPending ? (langData['confirm_revert_title'] || 'Send this payroll run back for revision?') : (langData['confirm_undo_decision_title'] || 'Undo this decision?');
    const message = isPending ? (langData['confirm_revert_message'] || 'It will return to draft so the submitter can make changes.') : (langData['confirm_undo_decision_message'] || 'This payroll run will go back to Waiting for Approval.');
    showConfirm(title, message, function () {
        const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
        if (inst) inst.hide();
        callRunAction('/api/payroll-run.revert', {}, langData['save_success']);
    });
});
$(document).on('click', '.btn-tl-lock', function (e) {
    e.stopPropagation();
    showConfirm(langData['confirm_lock_title'] || 'Lock this entry?', langData['confirm_lock_message'] || 'Once locked, this entry can no longer be edited or deleted.', function () {
        const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
        if (inst) inst.hide();
        callRunAction('/api/payroll-run.lock', {}, langData['save_success']);
    });
});
$(document).on('submit', '#runApproveForm', function (e) {
    e.preventDefault();
    bootstrap.Modal.getInstance(document.getElementById('runApproveModal')).hide();
    callRunAction('/api/payroll-run.approve', { note: $('#run_approve_note').val().trim() || null }, langData['save_success']);
});
$(document).on('submit', '#runRejectForm', function (e) {
    e.preventDefault();
    const reason = $('#run_reject_reason').val().trim();
    if (!reason) {
        $('#run_reject_reason').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('runRejectModal')).hide();
    callRunAction('/api/payroll-run.reject', { reason: reason }, langData['save_success']);
});
$(document).on('submit', '#runRequestInfoForm', function (e) {
    e.preventDefault();
    const reason = $('#run_request_info_reason').val().trim();
    if (!reason) {
        $('#run_request_info_reason').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('runRequestInfoModal')).hide();
    callRunAction('/api/payroll-run.request-info', { reason: reason }, langData['save_success']);
});
$(document).on('submit', '#runMarkPaidForm', function (e) {
    e.preventDefault();
    const method = $('#run_mark_paid_method').val();
    if (!method) {
        $('#run_mark_paid_method').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('runMarkPaidModal')).hide();
    callRunAction('/api/payroll-run.mark-paid', {
        payment_method: method,
        payment_reference: $('#run_mark_paid_reference').val().trim() || null,
        payment_date: toIsoDateRd($('#run_mark_paid_date').val()) || null,
    }, langData['save_success']);
});

// "Items" (manage per-employee earning/deduction adjustment lines): available on ANY draft run
// now (2026-08-19, explicit request) -- not just an Incentive/Other Payment run. For incentive
// these lines are the only source of pay; for any other run they're an additive one-off adjustment
// on top of the normal calculation (see PayrollRunModel::recalculate()'s manual-lines block, added
// to the non-incentive branch alongside standing PED assignments/attendance bonus).
function manageItemsButtonRd(row) {
    if (!currentRun || currentRun.state !== 'draft') {
        return '';
    }
    return `<button type="button" class="btn btn-link text-primary border-start btn-manage-manual-lines" data-employee-id="${row.employee_id}" title="${langData['action_manage_items'] || 'Items'}"><i class="fa-solid fa-list-check"></i></button>`;
}
// Raw Sync Data viewer (2026-08-21, explicit request: "ถ้าเป็นการ Sync ข้อมูลมาจาก Origami...เพิ่มปุ่ม
// ดูข้อมูลดิบได้") -- only for a row that actually came from the sync payload; a manually-added
// employee on the same sync-based run (row.data_source='manual') has no sync row to show.
function rawSyncDataButtonRd(row) {
    if (!currentRun || !currentRun.sync_process_id || row.data_source !== 'sync') {
        return '';
    }
    return `<button type="button" class="btn btn-link text-secondary border-start btn-raw-sync-data" data-employee-id="${row.employee_id}" title="${langData['action_raw_sync_data'] || 'Raw Sync Data'}"><i class="fa-solid fa-file-code"></i></button>`;
}
// Remove-from-run action, calculation table -- available for EVERY row on any draft run
// (2026-08-21, explicit request: "พนักงานทุกคน สามารถลบข้อมูลออกจากรอบได้ ต่อให้ Sync มาจาก Origami
// เองก็ตาม" -- ALL employees, including a genuinely-synced row). PayrollRunModel::removeManualEmployee()
// now records the removal in payroll_run_excluded_employees so a synced/cycle-automatic row
// actually stays gone across Recalculate instead of silently coming right back (the old
// restriction here existed because it used to). Undo is via the Join Employees picker (now
// available on every run type too, see renderSectionButtons()) -- confirmed with the user before
// firing since removing an automatic row is more consequential than removing a manual one.
function removeEmployeeButtonRd(row) {
    if (!currentRun || currentRun.state !== 'draft') {
        return '';
    }
    // 2026-08-28, explicit request: "ปรับ icon ให้เป็นรูปถังขยะ" -- trash-can, matching the delete-
    // button icon convention already used everywhere else in this app (Employee List, DataTables
    // row actions, etc.) instead of the previous user-minus icon.
    return `<button type="button" class="btn btn-link py-1 text-danger border-start btn-remove-manual-employee" data-employee-id="${row.employee_id}" title="${langData['action_remove'] || 'Remove'}"><i class="fa-solid fa-trash-can"></i></button>`;
}
// Breakdown button always shows (any state) -- it's read-only, unlike the other buttons which only
// make sense while draft. Grouped into one Bootstrap button-group -- same
// .btn-group.border.rounded-3.bg-white + btn-link idiom as every other row-actions table in the app
// (2026-08-21, explicit request: "ปรุงหน้า Process Detail...ให้เป็นรูปแบบเดียวกัน" -- this table was
// converted to a button-group earlier the same day but with a different btn-outline-* sub-style,
// left it the one inconsistent holdout after the sitewide sweep standardized everything else to
// this exact pattern; matched here now).
function runDetailActionsRd(row) {
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-info btn-view-breakdown" data-employee-id="${row.employee_id}" title="${langData['action_view_breakdown'] || 'View Breakdown'}"><i class="fa-solid fa-magnifying-glass-dollar"></i></button>
        ${rawSyncDataButtonRd(row)}
        ${manageItemsButtonRd(row)}
        ${removeEmployeeButtonRd(row)}
    </div>`;
}

/* ---------- Breakdown modal (section 2/3's table doesn't itemize -- it only shows totals): per-
   employee itemized view split into clearly-labeled Earnings / Deductions (Items) / Deductions
   (Statutory) sections, so which line is income vs. a deduction is never ambiguous. ---------- */
function breakdownLineRowsRd(lines) {
    return (lines || []).map(line => {
        const name = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || '';
        const commentHtml = line.note ? `<div class="small text-muted fst-italic"><i class="fa-regular fa-comment me-1"></i>${escapeHtmlRd(line.note)}</div>` : '';
        // Transfer-to-payee (2026-08-21): a 'transfer_in' earning line gets its own badge (not the
        // generic "Custom" one, even though it's technically is_custom too) so it reads distinctly
        // as money credited from another employee, not an ad-hoc typed-in item. A deduction line
        // that FEEDS a transfer instead shows a "-> employee_no" tag alongside its normal code.
        let codeHtml;
        if (line.source === 'transfer_in') {
            codeHtml = `<span class="badge bg-info-subtle text-info"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['transfer_in_badge'] || 'Transfer'}</span>`;
        } else if (line.is_custom) {
            codeHtml = `<span class="badge bg-secondary-subtle text-secondary"><i class="fa-solid fa-pen me-1"></i>${langData['manual_line_custom_badge'] || 'Custom'}</span>`;
        } else {
            codeHtml = `<code class="fw-bold text-dark">${escapeHtmlRd(line.code || '-')}</code>`;
        }
        const payeeHtml = line.payee_employee_id
            ? `<div class="small text-muted"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtmlRd(line.payee_employee_no || ('#' + line.payee_employee_id))}</div>`
            : '';
        return `<tr>
            <td>${codeHtml}</td>
            <td>${escapeHtmlRd(name)}${commentHtml}${payeeHtml}</td>
            <td class="text-end">${fmtNumRd(line.amount)}</td>
        </tr>`;
    }).join('');
}
function emptyRowFallbackRd(rowsHtml) {
    return rowsHtml || `<tr><td colspan="3" class="text-center text-muted small py-2">-</td></tr>`;
}
function statutoryRowsRd(items) {
    // 2026-08-21, real bug fix (explicit report: "แสดงแค่ Code อยากให้มีชื่อด้วย") -- name_th/
    // name_en now come through from StatutoryCalculationEngine::calculateLine(), same pattern as
    // breakdownLineRowsRd() already uses for earning/deduction lines just above.
    return (items || []).map(item => {
        const name = (currentLang === 'th' ? item.name_th : item.name_en) || item.name_th || item.name_en || '';
        const note = item.note ? ` <span class="text-muted small">(${escapeHtmlRd(item.note)})</span>` : '';
        return `<tr>
            <td><code class="fw-bold text-dark">${escapeHtmlRd(item.code || '-')}</code>${note}</td>
            <td>${escapeHtmlRd(name)}</td>
            <td class="text-end">${fmtNumRd(item.employee_amount)}</td>
        </tr>`;
    }).join('');
}
function breakdownSectionHtml(iconCls, colorCls, titleKey, titleFallback, rowsHtml, totalLabel, totalAmount) {
    // 2026-08-21, explicit request ("แต่ละ Column ของแต่ละตารางอยากให้อยู่ในตำแหน่งที่ตรงกัน") -- the 3
    // breakdown tables (Earnings/Deductions/Statutory) are stacked in the same modal and share this
    // exact column structure, but each <table> was sizing its own columns independently based on
    // ITS OWN content (default table-layout: auto), so e.g. a table with long item names pushed its
    // "Amount" column further right than the others. table-layout: fixed + identical percentage
    // widths on every call forces all 3 tables' columns to line up vertically regardless of content.
    return `
        <div class="mb-4">
            <h6 class="fw-bold ${colorCls} mb-2"><i class="fa-solid ${iconCls} me-1"></i>${langData[titleKey] || titleFallback}</h6>
            <table class="table table-sm table-border align-middle mb-0" style="table-layout: fixed;">
                <colgroup><col style="width:20%"><col style="width:55%"><col style="width:25%"></colgroup>
                <thead class="table-light text-secondary">
                    <tr><th data-i18n="table_code">${langData['table_code'] || 'Code'}</th><th data-i18n="table_name">${langData['table_name'] || 'Name'}</th><th class="text-end" data-i18n="modal_amount">${langData['modal_amount'] || 'Amount'}</th></tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
                <tfoot>
                    <tr class="fw-bold border-top ${colorCls}">
                        <td colspan="2">${escapeHtmlRd(totalLabel)}</td>
                        <td class="text-end">${fmtNumRd(totalAmount)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;
}
function renderBreakdownModal(row) {
    $('#breakdownEmployeeName').text(`${row.employee_no} - ${employeeDisplayNameRd(row)}`);

    let earningRowsHtml = '';
    if (Number(row.base_salary_amount) > 0) {
        earningRowsHtml += `<tr>
            <td><code class="fw-bold text-dark">BASE</code></td>
            <td>${escapeHtmlRd(langData['table_base_salary'] || 'Base Salary')}</td>
            <td class="text-end">${fmtNumRd(row.base_salary_amount)}</td>
        </tr>`;
    }
    earningRowsHtml += breakdownLineRowsRd(row.earning_breakdown);

    const statutoryTotal = (row.statutory_breakdown || []).reduce((sum, item) => sum + (Number(item.employee_amount) || 0), 0);

    const html = breakdownSectionHtml('fa-arrow-trend-up', 'text-success', 'breakdown_earnings', 'Earnings', emptyRowFallbackRd(earningRowsHtml), langData['table_gross_amount'] || 'Gross', row.gross_amount)
        + breakdownSectionHtml('fa-arrow-trend-down', 'text-danger', 'breakdown_deductions', 'Deductions (Items)', emptyRowFallbackRd(breakdownLineRowsRd(row.deduction_breakdown)), langData['breakdown_deductions_total'] || 'Deductions (Items) Total', (row.deduction_breakdown || []).reduce((sum, l) => sum + (Number(l.amount) || 0), 0))
        + breakdownSectionHtml('fa-landmark', 'text-danger', 'breakdown_statutory', 'Deductions (Statutory)', emptyRowFallbackRd(statutoryRowsRd(row.statutory_breakdown)), langData['breakdown_statutory_total'] || 'Deductions (Statutory) Total', statutoryTotal);
    $('#breakdownModalBody').html(html);
    // Net Pay lives in the modal-footer now (2026-08-20, explicit request), not the scrollable
    // body -- always visible without scrolling past the itemized sections.
    $('#breakdownModalNetPay').text(fmtNumRd(row.net_amount));
}
$(document).on('click', '.btn-view-breakdown', function () {
    const employeeId = $(this).data('employee-id');
    const rowData = (tb_run_detail ? tb_run_detail.rows().data().toArray() : []).find(r => Number(r.employee_id) === Number(employeeId));
    if (!rowData) return;
    renderBreakdownModal(rowData);
    new bootstrap.Modal(document.getElementById('runDetailBreakdownModal')).show();
});

/* ---------- Raw Sync Data viewer (2026-08-21, explicit request: "ดูข้อมูลดิบได้...เพื่อทำการ Recheck
   ข้อมูลย้อนหลังได้") -- read-only, shows exactly what Origami sent (payroll/attendance fields only,
   see PayrollRunModel::RAW_SYNC_DATA_FIELDS for the scoped field list and why PII columns are
   deliberately excluded). Fetched fresh per open, not cached off the row (same "always pull fresh"
   convention as every Edit action in this codebase). ---------- */
const RAW_SYNC_DATA_FIELDS_RD = [
    { key: 'payroll_code', labelKey: 'raw_sync_data_field_payroll_code', fallback: 'Payroll Code' },
    { key: 'emp_code', labelKey: 'raw_sync_data_field_emp_code', fallback: 'Employee Code' },
    { key: 'mapping_status', labelKey: 'raw_sync_data_field_mapping_status', fallback: 'Mapping Status' },
    { key: 'dept_description', labelKey: 'raw_sync_data_field_dept', fallback: 'Department' },
    { key: 'position_name', labelKey: 'raw_sync_data_field_position', fallback: 'Position' },
    { key: 'branch_name', labelKey: 'raw_sync_data_field_branch', fallback: 'Branch' },
    { key: 'shift_working_name', labelKey: 'raw_sync_data_field_shift', fallback: 'Shift' },
    { key: 'pay_type', labelKey: 'raw_sync_data_field_pay_type', fallback: 'Pay Type' },
    { key: 'pay_bank_code', labelKey: 'raw_sync_data_field_pay_bank_code', fallback: 'Bank Code' },
    { key: 'pay_bank_name', labelKey: 'raw_sync_data_field_pay_bank_name', fallback: 'Bank Name' },
    { key: 'working_days', labelKey: 'raw_sync_data_field_working_days', fallback: 'Working Days' },
    { key: 'working_mins', labelKey: 'raw_sync_data_field_working_mins', fallback: 'Working Minutes' },
    { key: 'absent_days', labelKey: 'raw_sync_data_field_absent_days', fallback: 'Absent Days' },
    { key: 'absent_mins', labelKey: 'raw_sync_data_field_absent_mins', fallback: 'Absent Minutes' },
    { key: 'late_mins', labelKey: 'raw_sync_data_field_late_mins', fallback: 'Late Minutes' },
    { key: 'early_mins', labelKey: 'raw_sync_data_field_early_mins', fallback: 'Early Leave Minutes' },
    { key: 'ot_mins', labelKey: 'raw_sync_data_field_ot_mins', fallback: 'OT Minutes' },
    { key: 'ot_req_hrs', labelKey: 'raw_sync_data_field_ot_req_hrs', fallback: 'OT Requested Hours' },
    { key: 'ot_req_working_day_hrs', labelKey: 'raw_sync_data_field_ot_weekday', fallback: 'OT (Weekday, hours)' },
    { key: 'ot_req_weekend_hrs', labelKey: 'raw_sync_data_field_ot_weekend', fallback: 'OT (Weekend, hours)' },
    { key: 'ot_req_holiday_hrs', labelKey: 'raw_sync_data_field_ot_holiday', fallback: 'OT (Holiday, hours)' },
    { key: 'leave_approve_days', labelKey: 'raw_sync_data_field_leave_approve', fallback: 'Leave Approved (days)' },
    { key: 'leave_wait_days', labelKey: 'raw_sync_data_field_leave_wait', fallback: 'Leave Pending (days)' },
    { key: 'leave_without_pay_days', labelKey: 'raw_sync_data_field_leave_no_pay', fallback: 'Unpaid Leave (days)' },
    { key: 'trip_allowance', labelKey: 'raw_sync_data_field_trip_allowance', fallback: 'Trip Allowance' },
    { key: 'pass_pro', labelKey: 'raw_sync_data_field_pass_pro', fallback: 'Passed Probation' },
    { key: 'pass_pro_date', labelKey: 'raw_sync_data_field_pass_pro_date', fallback: 'Probation Pass Date' }
];
// 2026-08-21, explicit request ("ปรับการแสดงผลให้สวยงาม") -- was one long flat label/value table;
// grouped into labeled card sections (same .ped-type-panel/border-rounded-3-p-3 card styling
// already used by the Manage Items modal's Earnings/Deductions panels) instead. Looked up by key
// from RAW_SYNC_DATA_FIELDS_RD below rather than duplicating each field's label/fallback here.
const RAW_SYNC_DATA_SECTIONS_RD = [
    { titleKey: 'raw_sync_data_section_identity', fallback: 'Identity & Organization', icon: 'fa-id-card', fields: ['payroll_code', 'emp_code', 'mapping_status', 'dept_description', 'position_name', 'branch_name', 'shift_working_name'] },
    { titleKey: 'raw_sync_data_section_attendance', fallback: 'Attendance', icon: 'fa-calendar-check', fields: ['working_days', 'working_mins', 'absent_days', 'absent_mins', 'late_mins', 'early_mins'] },
    { titleKey: 'raw_sync_data_section_ot', fallback: 'Overtime', icon: 'fa-clock', fields: ['ot_mins', 'ot_req_hrs', 'ot_req_working_day_hrs', 'ot_req_weekend_hrs', 'ot_req_holiday_hrs'] },
    { titleKey: 'raw_sync_data_section_leave', fallback: 'Leave', icon: 'fa-calendar-day', fields: ['leave_approve_days', 'leave_wait_days', 'leave_without_pay_days'] },
    { titleKey: 'raw_sync_data_section_pay', fallback: 'Pay & Probation', icon: 'fa-sack-dollar', fields: ['pay_type', 'pay_bank_code', 'pay_bank_name', 'trip_allowance', 'pass_pro', 'pass_pro_date'] },
];
function rawSyncDataValueDisplay(value) {
    if (value === null || value === undefined || value === '') return '-';
    return escapeHtmlRd(value);
}
function rawSyncDataItemValuesTableHtml(itemValues) {
    if (!itemValues || !itemValues.length) {
        return `<div class="text-muted small">${langData['raw_sync_data_item_values_empty'] || 'No additional line items sent.'}</div>`;
    }
    const rows = itemValues.map(iv => `<tr>
        <td><code>${escapeHtmlRd(iv.item_code || '-')}</code></td>
        <td>${escapeHtmlRd(iv.item_name || '-')}</td>
        <td>${escapeHtmlRd(iv.item_type || '-')}</td>
        <td>${escapeHtmlRd(iv.unit_type || '-')}</td>
        <td class="text-end">${rawSyncDataValueDisplay(iv.value)}</td>
        <td>${escapeHtmlRd(iv.remark || '-')}</td>
    </tr>`).join('');
    return `<div class="table-responsive">
        <table class="table table-sm table-border align-middle mb-0">
            <thead class="table-light text-secondary small">
                <tr>
                    <th>${langData['raw_sync_data_item_code'] || 'Item Code'}</th>
                    <th>${langData['raw_sync_data_item_name'] || 'Item Name'}</th>
                    <th>${langData['raw_sync_data_item_type'] || 'Type'}</th>
                    <th>${langData['raw_sync_data_item_unit'] || 'Unit'}</th>
                    <th class="text-end">${langData['raw_sync_data_item_value'] || 'Value'}</th>
                    <th>${langData['raw_sync_data_item_remark'] || 'Remark'}</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    </div>`;
}
function rawSyncDataFieldLookupRd(key) {
    return RAW_SYNC_DATA_FIELDS_RD.find(f => f.key === key);
}
function renderRawSyncDataModal(data) {
    const sectionsHtml = RAW_SYNC_DATA_SECTIONS_RD.map(section => {
        const fieldsHtml = section.fields.map(key => {
            const f = rawSyncDataFieldLookupRd(key);
            if (!f) return '';
            return `<div class="col-sm-6">
                <div class="text-muted small">${langData[f.labelKey] || f.fallback}</div>
                <div class="fw-semibold">${rawSyncDataValueDisplay(data[f.key])}</div>
            </div>`;
        }).join('');
        return `<div class="col-md-6">
            <div class="ped-type-panel border rounded-3 p-3 h-100">
                <h6 class="text-secondary fw-bold mb-2"><i class="fa-solid ${section.icon} me-1"></i>${langData[section.titleKey] || section.fallback}</h6>
                <div class="row g-2">${fieldsHtml}</div>
            </div>
        </div>`;
    }).join('');
    $('#rawSyncDataModalBody').html(`
        <div class="row g-3 mb-3">${sectionsHtml}</div>
        <h6 class="text-secondary fw-bold mb-2">${langData['raw_sync_data_item_values_title'] || 'Additional Line Items'}</h6>
        ${rawSyncDataItemValuesTableHtml(data.item_values)}
    `);
}
let rawSyncDataEmployeeId = null;
$(document).on('click', '.btn-raw-sync-data', function () {
    rawSyncDataEmployeeId = $(this).data('employee-id');
    const rowData = (tb_run_detail ? tb_run_detail.rows().data().toArray() : []).find(r => Number(r.employee_id) === Number(rawSyncDataEmployeeId));
    $('#rawSyncDataEmployeeName').text(rowData ? `${rowData.employee_no} - ${employeeDisplayNameRd(rowData)}` : '');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.raw-sync-data-for-employee`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: rawSyncDataEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['load_employee_failed'] || 'Failed to load data.'); return; }
            renderRawSyncDataModal(res.data);
            const ex = res.data.exemption || { exempt_tax: false, exempt_sso: false };
            $('#rawSyncDataExemptTax').prop('checked', !!ex.exempt_tax);
            $('#rawSyncDataExemptSso').prop('checked', !!ex.exempt_sso);
            new bootstrap.Modal(document.getElementById('rawSyncDataModal')).show();
        },
        error: function () { showWarning(langData['load_employee_failed'] || 'Failed to load data.'); }
    });
});
$(document).on('click', '#btnSaveRawSyncDataExemption', function () {
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save-employee-exemption`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({
            id: PAYROLL_RUN_ID,
            employee_id: rawSyncDataEmployeeId,
            exempt_tax: $('#rawSyncDataExemptTax').is(':checked'),
            exempt_sso: $('#rawSyncDataExemptSso').is(':checked'),
        }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { $btn.prop('disabled', false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

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
            { data: null, className: 'text-center', render: (d, t, row) => dataSourceBadgeRd(row) },
            { data: 'base_salary_amount', className: 'text-end', render: d => fmtNumRd(d) },
            { data: 'gross_amount', className: 'text-end text-success fw-semibold', render: d => fmtNumRd(d) },
            { data: 'total_deduction_amount', className: 'text-end text-danger fw-semibold', render: d => fmtNumRd(d) },
            { data: 'net_amount', className: 'text-end fw-bold', render: d => fmtNumRd(d) },
            { data: 'calc_status', render: d => calcStatusBadgeRd(d) },
            { data: 'calc_errors', render: d => calcErrorsRemarkRd(d) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, className: 'all', orderable: false, render: (d, t, row) => runDetailActionsRd(row) },
        ],
        paging: false,
        searching: details.length > 10,
        info: false,
        language: getTableLang(),
        drawCallback: function () { getTableLang(); },
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
        // rollout, client mode (plain `data:` array, no ajax at all). Excludes the actions column (9).
        initComplete: function () {
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 0, key: 'employee_no' },
                    { index: 1, key: 'name' },
                    { index: 2, key: 'data_source' },
                    { index: 3, key: 'base_salary_amount' },
                    { index: 4, key: 'gross_amount' },
                    { index: 5, key: 'total_deduction_amount' },
                    { index: 6, key: 'net_amount' },
                    { index: 7, key: 'calc_status' },
                    { index: 8, key: 'calc_errors' },
                ]
            });
        }
    });
}

function auditActionLabel(action) {
    const map = {
        create: 'action_create', update: 'action_edit', recalculate: 'action_recalculate',
        submit: 'action_submit', revert: 'action_revert', approve: 'action_approve',
        reject: 'action_reject', reviseAfterReject: 'action_revise', markPaid: 'action_mark_paid',
        lock: 'action_lock', delete: 'action_delete', cancel: 'action_cancel',
        add_manual_line: 'action_add_manual_line', remove_manual_line: 'action_remove_manual_line',
        line_override_save: 'action_line_override_save', line_override_remove: 'action_line_override_remove',
        attendance_override_save: 'action_attendance_override_save', attendance_override_remove: 'action_attendance_override_remove',
        employee_exemption_save: 'action_employee_exemption_save', employee_exemption_remove: 'action_employee_exemption_remove',
        request_info: 'action_request_info', reviseAfterNeedInfo: 'action_revise',
    };
    const key = map[action];
    return (key && langData[key]) || action;
}
// 2026-08-27, explicit request: "ในหน้า Process Detail Tab Action History ปรับจากตารางเป็น Timeline
// สวยๆ" -- reuses the SAME `.apv-stage` circular-marker/connector-line component this page's own
// Timeline modal/status card already builds with (apvIconHtmlRd()/apvBadgeHtmlRd(), see
// apvCreatedStageHtmlRd() etc. above) instead of inventing a second timeline design on the same
// page. Distinct from the plainer `renderAuditTimelineRd()` (left-border list, `.apv-log-entry`)
// already used inside the Timeline modal's own condensed "History" section further down -- that one
// stays untouched (it's a summary inside a modal, not this tab), this is the full, richer rendering
// for the tab's own dedicated space. Newest first, matching renderAuditTimelineRd()'s own ordering
// convention on this same page.
const AUDIT_TIMELINE_META_RD = {
    create: { tone: 'done', icon: 'fa-plus' },
    submit: { tone: 'info', icon: 'fa-paper-plane' },
    approve: { tone: 'done', icon: 'fa-check' },
    reject: { tone: 'rejected', icon: 'fa-xmark' },
    request_info: { tone: 'info', icon: 'fa-circle-info' },
    revert: { tone: 'pending', icon: 'fa-rotate-left' },
    reviseAfterReject: { tone: 'pending', icon: 'fa-pen' },
    reviseAfterNeedInfo: { tone: 'pending', icon: 'fa-pen' },
    markPaid: { tone: 'done', icon: 'fa-money-check-dollar' },
    lock: { tone: 'muted', icon: 'fa-lock' },
    delete: { tone: 'rejected', icon: 'fa-trash' },
    cancel: { tone: 'muted', icon: 'fa-ban' },
};
function auditTimelineMetaRd(action) {
    return AUDIT_TIMELINE_META_RD[action] || { tone: 'muted', icon: 'fa-pen' };
}
function auditHistoryStageHtmlRd(entry, isLast) {
    const meta = auditTimelineMetaRd(entry.action);
    const stateChangeHtml = entry.from_state
        ? `${stateBadgeRd(entry.from_state)} <i class="fa-solid fa-arrow-right mx-1"></i> ${stateBadgeRd(entry.to_state)}`
        : (entry.to_state ? stateBadgeRd(entry.to_state) : '');
    return `
        <div class="apv-stage${isLast ? ' apv-stage-last' : ''}">
            <div class="apv-stage-marker">${apvIconHtmlRd(meta.tone, meta.icon)}${isLast ? '' : '<div class="apv-stage-line"></div>'}</div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${escapeHtmlRd(auditActionLabel(entry.action))}</span>
                </div>
                <div class="apv-stage-date">${escapeHtmlRd(formatDisplayDateTime(entry.performed_at))}</div>
                <div class="apv-stage-body">
                    ${apvPersonLineHtmlRd(personDisplayNameRd(entry, 'performed_by'))}
                    ${stateChangeHtml ? `<div class="mt-2">${stateChangeHtml}</div>` : ''}
                    ${entry.note ? `<div class="apv-substep-remark">${escapeHtmlRd(entry.note)}</div>` : ''}
                </div>
            </div>
        </div>
    `;
}
function renderAuditHistoryTimelineRd(auditLog) {
    const logs = auditLog || [];
    $('#noAuditYet').toggleClass('d-none', logs.length > 0);
    $('#run_audit_timeline').toggleClass('d-none', logs.length === 0);
    if (!logs.length) {
        $('#run_audit_timeline').empty();
        return;
    }
    const ordered = logs.slice().reverse(); // newest first at the top, oldest at the bottom
    $('#run_audit_timeline').html(ordered.map((entry, i) => auditHistoryStageHtmlRd(entry, i === ordered.length - 1)).join(''));
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
                renderAuditHistoryTimelineRd(res.data.audit_log || []);
                // 2026-08-28, explicit request: Process List/Approval Queue (opened in a SEPARATE
                // browser tab, see index.js/approval.js's own window.open(...'_blank')) should
                // reload once this run's data changes -- every mutating action on this page
                // (submit/approve/reject/recalculate/markPaid/cancel/join employees/line overrides/
                // etc) already funnels back through loadRunDetail() itself, so marking dirty here
                // covers all of them from one place instead of duplicating it at every action's own
                // success handler. See markTabDirty()/watchTabDirty() in app.js.
                if (typeof markTabDirty === 'function') markTabDirty('payroll_run_list_dirty');
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
/* ---------- Manage Payment Items modal: per-employee earning/deduction lines, add one at a time,
   remove any individually. Split into two panels (Earnings/Deductions, same visual language as
   section 2's item-selection panels) with running subtotals + a net-adjustment total, rather than
   one flat mixed table -- makes it immediately obvious what's earning vs. deduction and what the
   combined effect is, without needing to close the modal and check the outer table. ---------- */
let manageLinesEmployeeId = null;
function manualLineTagHtml(line) {
    return line.is_custom
        ? `<span class="badge bg-secondary-subtle text-secondary"><i class="fa-solid fa-pen me-1"></i>${langData['manual_line_custom_badge'] || 'Custom'}</span>`
        : `<code class="fw-bold text-dark">${escapeHtmlRd(line.item_code)}</code>`;
}
function manualLineListItemHtml(line) {
    const name = (currentLang === 'th' ? line.item_name_th : line.item_name_en) || line.item_name_th || line.item_name_en;
    const amtCls = line.item_type === 'earning' ? 'text-success' : 'text-danger';
    const commentHtml = line.note ? `<div class="small text-muted fst-italic mt-1"><i class="fa-regular fa-comment me-1"></i>${escapeHtmlRd(line.note)}</div>` : '';
    const payeeHtml = line.payee_employee_id
        ? `<div class="small text-muted mt-1"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtmlRd(line.payee_employee_no || ('#' + line.payee_employee_id))}</div>`
        : '';
    return `<li class="list-group-item d-flex justify-content-between align-items-start px-0 py-2">
        <div>
            ${manualLineTagHtml(line)}
            <div class="small text-muted">${escapeHtmlRd(name)}</div>
            ${commentHtml}
            ${payeeHtml}
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="fw-semibold ${amtCls}">${fmtNumRd(line.amount)}</span>
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-manual-line" data-line-id="${line.id}" title="${langData['action_remove'] || 'Remove'}"><i class="fa-solid fa-trash-alt"></i></button>
        </div>
    </li>`;
}
function manualLineEmptyItemHtml(key, fallback) {
    return `<li class="list-group-item px-0 py-2 text-center text-muted small border-0">${langData[key] || fallback}</li>`;
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
            const earningLines = lines.filter(l => l.item_type === 'earning');
            const deductionLines = lines.filter(l => l.item_type === 'deduction');
            $('#manualLinesEarningList').html(earningLines.length
                ? earningLines.map(manualLineListItemHtml).join('')
                : manualLineEmptyItemHtml('no_manual_earning_lines', 'No earning items added yet.'));
            $('#manualLinesDeductionList').html(deductionLines.length
                ? deductionLines.map(manualLineListItemHtml).join('')
                : manualLineEmptyItemHtml('no_manual_deduction_lines', 'No deduction items added yet.'));
            const earningTotal = earningLines.reduce((sum, l) => sum + Number(l.amount || 0), 0);
            const deductionTotal = deductionLines.reduce((sum, l) => sum + Number(l.amount || 0), 0);
            $('#manualLinesEarningTotal').text(fmtNumRd(earningTotal));
            $('#manualLinesDeductionTotal').text(fmtNumRd(deductionTotal));
            $('#manualLinesNetTotal').text(fmtNumRd(earningTotal - deductionTotal));
        }
    });
}

/* ---------- Attendance Data (from Sync) (2026-08-21, explicit request: "ต้องการแก้ตัวเลขดิบที่ Sync
   มา ไม่ใช่แค่ยอดเงิน") -- corrects the RAW numbers Origami sent (not the resulting deduction/earning
   amount -- see the Sync Deduction Adjustments section right below for that), shown only on a
   sync-based run. One combined form/Save for all 7 fields (not per-field) since they represent one
   conceptual "corrected timesheet" record, matching payroll_run_sync_item_overrides' one-row-per-
   employee shape. ---------- */
const ATTENDANCE_DATA_FIELDS_RD = [
    { key: 'ot_req_working_day_hrs', labelKey: 'attendance_data_field_ot_weekday', fallback: 'OT (Weekday, hours)', step: 0.01 },
    { key: 'ot_req_weekend_hrs', labelKey: 'attendance_data_field_ot_weekend', fallback: 'OT (Weekend, hours)', step: 0.01 },
    { key: 'ot_req_holiday_hrs', labelKey: 'attendance_data_field_ot_holiday', fallback: 'OT (Holiday, hours)', step: 0.01 },
    { key: 'trip_allowance', labelKey: 'attendance_data_field_trip_allowance', fallback: 'Trip Allowance', step: 0.01 },
    { key: 'late_mins', labelKey: 'attendance_data_field_late_mins', fallback: 'Late (minutes)', step: 1 },
    { key: 'absent_days', labelKey: 'attendance_data_field_absent_days', fallback: 'Absent (days)', step: 0.5 },
    { key: 'leave_without_pay_days', labelKey: 'attendance_data_field_leave_days', fallback: 'Unpaid Leave (days)', step: 0.5 }
];
function attendanceDataRowHtml(field, synced, override) {
    const hasOverride = override !== null && override !== undefined;
    const effective = hasOverride ? override : (synced !== null && synced !== undefined ? synced : '');
    const label = langData[field.labelKey] || field.fallback;
    const syncedDisplay = (synced !== null && synced !== undefined) ? fmtNumRd(synced) : '-';
    const badge = hasOverride ? ` <span class="badge bg-warning-subtle text-warning">${langData['sync_line_override_overridden_badge'] || 'Overridden'}</span>` : '';
    return `<tr data-field="${field.key}">
        <td>${label}${badge}</td>
        <td class="text-end text-muted">${syncedDisplay}</td>
        <td><input type="number" step="${field.step}" min="0" class="form-control form-control-sm attendance-data-input" value="${effective}"></td>
    </tr>`;
}
function loadAttendanceDataRd() {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.attendance-data-for-employee`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const synced = res.data.synced || {};
            const override = res.data.override || {};
            $('#attendanceDataRows').html(ATTENDANCE_DATA_FIELDS_RD.map(f => attendanceDataRowHtml(f, synced[f.key], override[f.key])).join(''));
        }
    });
}
$(document).on('click', '#btnSaveAttendanceData', function () {
    const fields = {};
    $('#attendanceDataRows tr').each(function () {
        const field = $(this).data('field');
        const val = $(this).find('.attendance-data-input').val();
        fields[field] = (val === '' || val === null) ? null : parseFloat(val);
    });
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.attendance-override.save`,
        method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, fields: fields }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                loadAttendanceDataRd();
                loadSyncLineOverridesRd();
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
$(document).on('click', '#btnResetAttendanceData', function () {
    const title = langData['attendance_data_reset_all'] || 'Reset All to Synced';
    const msg = langData['confirm_reset_attendance_data_message'] || 'Discard all corrections and revert every field back to the synced value?';
    showConfirm(title, msg, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.attendance-override.remove`,
            method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId }),
            success: function (res) {
                if (res.status) {
                    loadAttendanceDataRd();
                    loadSyncLineOverridesRd();
                    loadRunDetail();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
    });
});

/* ---------- Sync Deduction Adjustments (2026-08-21, explicit request: "ต้องการปรับค่า สาย ขาดงาน
   ลาไม่รับเงิน หรือยกเว้นไม่ให้หัก") -- per-run, per-employee, per-item override/exclude on a line
   SyncPayResolver itself computed (Late/Absent/Unpaid Leave etc.), shown only on a sync-based run.
   Each row shows the RAW computed amount (never changes) plus an inline override-amount input +
   exclude checkbox + Save, and a Reset action once an override is active. ---------- */
function syncLineOverrideBadge(line) {
    if (line.override_action === 'exclude') {
        return `<span class="badge bg-danger-subtle text-danger ms-1">${langData['sync_line_override_excluded_badge'] || 'Excluded'}</span>`;
    }
    if (line.override_action === 'override_amount') {
        return `<span class="badge bg-warning-subtle text-warning ms-1">${langData['sync_line_override_overridden_badge'] || 'Overridden'}</span>`;
    }
    return '';
}
function syncLineOverrideRowHtml(line) {
    const name = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || line.code;
    const isExcluded = line.override_action === 'exclude';
    const amountValue = line.override_action === 'override_amount' ? line.override_amount : line.computed_amount;
    const hasOverride = line.override_action !== null;
    return `<div class="border rounded-3 p-2 mb-2" data-item-code="${escapeHtmlRd(line.code)}">
        <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-1">
            <div>
                <code class="fw-bold text-dark">${escapeHtmlRd(line.code)}</code> ${escapeHtmlRd(name)}${syncLineOverrideBadge(line)}
                <div class="small text-muted">${langData['sync_line_override_computed'] || 'Computed'}: ${fmtNumRd(line.computed_amount)}</div>
            </div>
            ${hasOverride ? `<button type="button" class="btn btn-sm btn-outline-secondary btn-sync-line-reset" data-item-code="${escapeHtmlRd(line.code)}">${langData['sync_line_override_reset'] || 'Reset to computed'}</button>` : ''}
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap sync-line-controls">
            <input type="number" step="0.01" min="0" class="form-control form-control-sm sync-line-amount-input" style="max-width:140px;" value="${amountValue}" ${isExcluded ? 'disabled' : ''}>
            <div class="form-check form-check-inline mb-0">
                <input type="checkbox" class="form-check-input sync-line-exclude-check" ${isExcluded ? 'checked' : ''}>
                <label class="form-check-label small">${langData['sync_line_override_action_exclude'] || 'Exclude this run'}</label>
            </div>
            <button type="button" class="btn btn-sm btn-primary btn-sync-line-save" data-item-code="${escapeHtmlRd(line.code)}">${langData['save'] || 'Save'}</button>
        </div>
    </div>`;
}
function loadSyncLineOverridesRd() {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.sync-lines-for-employee`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const lines = res.data || [];
            $('#syncLineOverrideList').html(lines.length
                ? lines.map(syncLineOverrideRowHtml).join('')
                : `<div class="text-center text-muted small py-2">${langData['sync_line_override_empty'] || 'No sync-computed deduction lines for this employee.'}</div>`);
        }
    });
}
$(document).on('change', '.sync-line-exclude-check', function () {
    $(this).closest('.sync-line-controls').find('.sync-line-amount-input').prop('disabled', this.checked);
});
$(document).on('click', '.btn-sync-line-save', function () {
    const $row = $(this).closest('[data-item-code]');
    const itemCode = $row.data('item-code');
    const isExcluded = $row.find('.sync-line-exclude-check').is(':checked');
    const amount = parseFloat($row.find('.sync-line-amount-input').val());
    if (!isExcluded && (isNaN(amount) || amount < 0)) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = {
        id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, item_code: itemCode,
        action: isExcluded ? 'exclude' : 'override_amount',
    };
    if (!isExcluded) { payload.override_amount = amount; }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.line-override.save`,
        method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                loadSyncLineOverridesRd();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});
$(document).on('click', '.btn-sync-line-reset', function () {
    const itemCode = $(this).data('item-code');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.line-override.remove`,
        method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, item_code: itemCode }),
        success: function (res) {
            if (res.status) {
                loadSyncLineOverridesRd();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});

// Live preview under the Add form once an item is picked -- tells the admin whether it's about to
// land in the Earnings or Deductions panel before they commit, since the dropdown mixes both types
// together (unlike section 2's per-type panels/modal). item_type rides along on the select2 option
// data already (see EmployeeEarningDeductionModel::activeOptions()'s SELECT).
function updateManualLineTypePreviewRd(itemType) {
    const $preview = $('#manualLineTypePreview');
    // Transfer-to-payee (2026-08-21) only makes sense on a deduction -- toggled alongside this same
    // type preview rather than a parallel visibility mechanism.
    const isDeduction = itemType === 'deduction';
    $('#manualLinePayeeWrapper').toggleClass('d-none', !isDeduction);
    if (!isDeduction) {
        $('#manualLinePayeeEmployee').val(null).trigger('change');
    }
    if (!itemType) {
        $preview.addClass('d-none').removeClass('text-success text-danger').text('');
        return;
    }
    const isEarning = itemType === 'earning';
    const label = langData[isEarning ? 'breakdown_earnings' : 'table_deduction_amount'] || (isEarning ? 'Earnings' : 'Deductions');
    const icon = isEarning ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
    $preview.removeClass('d-none text-success text-danger').addClass(isEarning ? 'text-success' : 'text-danger')
        .html(`<i class="fa-solid ${icon} me-1"></i>${langData['manual_line_type_preview'] || 'Will be added as'}: <strong>${label}</strong>`);
}
$(document).on('select2:select', '#manualLineItemSelect', function (e) {
    updateManualLineTypePreviewRd(e.params.data.item_type);
});
$(document).on('select2:clear', '#manualLineItemSelect', function () {
    updateManualLineTypePreviewRd(null);
});
$(document).on('change', '#manualLineCustomType', function () {
    updateManualLineTypePreviewRd($(this).val() || null);
});
// Toggle between picking a catalog item and typing a custom, not-in-the-catalog one (2026-08-19,
// explicit request) -- catalog mode is the default since it's still the common case.
let manualLineMode = 'catalog';
function setManualLineModeRd(mode) {
    manualLineMode = mode;
    $('#manualLineModeToggle button').removeClass('active').filter(`[data-mode="${mode}"]`).addClass('active');
    $('#manualLineCatalogFields').toggleClass('d-none', mode !== 'catalog');
    $('#manualLineCustomFields').toggleClass('d-none', mode !== 'custom');
    updateManualLineTypePreviewRd(mode === 'custom' ? ($('#manualLineCustomType').val() || null) : null);
}
function resetManualLineFormRd() {
    setManualLineModeRd('catalog');
    $('#manualLineItemSelect').val(null).trigger('change');
    $('#manualLineCustomName').val('');
    $('#manualLineCustomType').val('earning').trigger('change.select2');
    $('#manualLineAmount').val('');
    $('#manualLineComment').val('');
    // An employee can't be their own transfer payee -- excluded the same way #eed_payee_employee_id
    // excludes self on the Employee Detail page (data-exclude-id, read fresh on every ajax search).
    $('#manualLinePayeeEmployee').attr('data-exclude-id', manageLinesEmployeeId || '').val(null).trigger('change');
    updateManualLineTypePreviewRd(null);
}
$(document).on('click', '#manualLineModeToggle button', function () {
    setManualLineModeRd($(this).data('mode'));
});
$(document).on('click', '.btn-manage-manual-lines', function () {
    manageLinesEmployeeId = $(this).data('employee-id');
    const rowData = (tb_run_detail ? tb_run_detail.rows().data().toArray() : []).find(r => Number(r.employee_id) === Number(manageLinesEmployeeId));
    $('#manageLinesEmployeeName').text(rowData ? `${rowData.employee_no} - ${employeeDisplayNameRd(rowData)}` : '');
    const isIncentive = currentRun && currentRun.run_purpose === 'incentive';
    // 2026-08-27: an incentive run's manual lines are no longer necessarily the ONLY thing
    // counted -- once include_base_salary/include_standing_items is on for this run (see
    // PayrollRunModel::recalculate()'s own docblock), manual lines here are additive on top of
    // those, same spirit (if not the exact same wording) as a normal run's own hint.
    let hint;
    if (!isIncentive) {
        hint = langData['manage_items_hint_adjustment'] || 'Added on top of this employee\'s normal calculation, for this run only.';
    } else if (currentRun.include_base_salary || currentRun.include_standing_items) {
        hint = langData['manage_items_hint_incentive_partial'] || 'Added on top of this run\'s own settings (base salary and/or standing earning/deduction items, as configured for this run), for this employee only.';
    } else {
        hint = langData['manage_items_hint_incentive'] || 'These are the only items counted for this employee -- no base salary, no standing income/deduction assignments.';
    }
    $('#manageLinesHint').text(hint);
    resetManualLineFormRd();
    // Always reopen on Tab 1 -- a stale "Attendance Data" tab left active from a previous employee
    // would otherwise show up front-and-center for someone this run isn't even sync-based for.
    bootstrap.Tab.getOrCreateInstance(document.getElementById('manageLinesItemsTab')).show();
    // Attendance Data (from Sync) + Sync Deduction Adjustments (2026-08-21) -- both only meaningful
    // on a sync-based run, where SyncPayResolver actually has raw numbers/computed lines to correct.
    const isSyncRun = currentRun && currentRun.sync_process_id;
    $('#manageLinesAttendanceTabWrap, #manageLinesSyncOverrideTabWrap').toggleClass('d-none', !isSyncRun);
    if (isSyncRun) {
        loadAttendanceDataRd();
        loadSyncLineOverridesRd();
    }
    new bootstrap.Modal(document.getElementById('manageLinesModal')).show();
    loadManualLinesRd();
});
$(document).on('click', '#btnAddManualLine', function () {
    const amount = parseFloat($('#manualLineAmount').val());
    const comment = $('#manualLineComment').val().trim();
    const payload = { id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, amount: amount, note: comment };
    if (manualLineMode === 'custom') {
        const customName = $('#manualLineCustomName').val().trim();
        const customType = $('#manualLineCustomType').val();
        if (!customName || !customType || !amount || amount <= 0) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        payload.custom_item_name = customName;
        payload.custom_item_type = customType;
        if (customType === 'deduction') {
            payload.payee_employee_id = $('#manualLinePayeeEmployee').val() || undefined;
        }
    } else {
        const pedTypeId = $('#manualLineItemSelect').val();
        if (!pedTypeId || !amount || amount <= 0) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        payload.ped_type_id = pedTypeId;
        if (!$('#manualLinePayeeWrapper').hasClass('d-none')) {
            payload.payee_employee_id = $('#manualLinePayeeEmployee').val() || undefined;
        }
    }
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.add-manual-line`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                resetManualLineFormRd();
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
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: `${BASE_URL}/api/payroll-run.manual-employee-options`,
            type: 'POST',
            data: function (d, settings) {
                d.run_id = PAYROLL_RUN_ID;
                d.department_id = $('#joinFilterDepartment').val() || '';
                d.team_id = $('#joinFilterTeam').val() || '';
                d.position_id = $('#joinFilterPosition').val() || '';
                d.emp_cycle_id = $('#joinFilterCycle').val() || '';
                // Built from `settings` (not the outer `tb_join_employees` variable) -- see
                // table-column-filter.js's getColumnFilterValues() docblock for why.
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
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
            { data: 'team', render: d => escapeHtmlRd(d || '-') },
            { data: 'position', render: d => escapeHtmlRd(d || '-') },
            { data: 'cycle_name', render: d => escapeHtmlRd(d || '-') },
        ],
        order: [],
        searching: false,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, server mode. Excludes the row-select checkbox (0) -- no actions column on
            // this picker.
            initExcelColumnFilters(self, {
                mode: 'server',
                columns: [
                    { index: 1, key: 'employee_no' },
                    { index: 2, key: 'name' },
                    { index: 3, key: 'department' },
                    { index: 4, key: 'team' },
                    { index: 5, key: 'position' },
                    { index: 6, key: 'cycle_name' },
                ],
                fetchValues: function (key, done) {
                    $.ajax({
                        url: `${BASE_URL}/api/payroll-run.manual-employee-column-values`,
                        method: 'POST',
                        data: {
                            run_id: PAYROLL_RUN_ID,
                            department_id: $('#joinFilterDepartment').val() || '',
                            team_id: $('#joinFilterTeam').val() || '',
                            position_id: $('#joinFilterPosition').val() || '',
                            emp_cycle_id: $('#joinFilterCycle').val() || '',
                            column: key,
                            column_filters: getColumnFilterValues(self)
                        },
                        dataType: 'json'
                    }).done(function (res) {
                        done((res && res.values) || []);
                    }).fail(function () {
                        done([]);
                    });
                },
                onApply: function () { self.ajax.reload(null, false); }
            });
        },
        drawCallback: function () {
            getTableLang();
            $('#joinSelectAll').prop('checked', false);
            // 2026-08-24, explicit request ("จัดรูปแบบให้การดึงพนักงานเข้ามาในการคำนวณดำเนินการได้ง่าย
            // ที่สุด") -- recordsDisplay (post-filter, pre-pagination total) is exactly what "Select
            // All Matching" would select, shown right next to that button so the number it acts on
            // is never a guess.
            $('#joinFilteredCount').text(this.api().page.info().recordsDisplay);
        }
    });
}
$(document).on('click', '#btnJoinEmployees', function () {
    joinSelectedEmployees = {};
    updateJoinSelectedCountRd();
    $('#joinFilterDepartment, #joinFilterTeam, #joinFilterPosition, #joinFilterCycle').val(null).trigger('change');
    // Cycle-only run: this modal can only ever re-include a previously-removed employee (see
    // manualEmployeeOptions()'s cycle-only branch server-side) -- say so, since "Join Employees"
    // otherwise implies adding someone brand new.
    const isPureCycleRun = currentRun && currentRun.cycle_id && !currentRun.sync_process_id;
    $('#joinEmployeesHint').text(isPureCycleRun
        ? (langData['join_employees_hint_cycle_only'] || 'This run\'s membership is automatic by employment date -- only employees previously removed from it are shown here.')
        : '');
    new bootstrap.Modal(document.getElementById('joinEmployeesModal')).show();
    initJoinEmployeesTable();
});
$(document).on('change', '#joinFilterDepartment, #joinFilterTeam, #joinFilterPosition, #joinFilterCycle', function () {
    if (tb_join_employees) tb_join_employees.ajax.reload(null, false);
});
$(document).on('click', '#btnClearJoinFilter', function () {
    $('#joinFilterDepartment, #joinFilterTeam, #joinFilterPosition, #joinFilterCycle').val(null).trigger('change');
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
// 2026-08-24, explicit request ("จัดรูปแบบให้การดึงพนักงานเข้ามาในการคำนวณดำเนินการได้ง่ายที่สุด") --
// unlike #joinSelectAll above (current DataTable page only, since this table is serverSide:true),
// this fetches every id matching the current filter/search with no pagination and adds them all to
// the selection in one click, then redraws so any checkboxes on the current page reflect it.
$(document).on('click', '#btnJoinSelectAllMatching', function () {
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.manual-employee-all-ids`,
        method: 'POST',
        data: {
            run_id: PAYROLL_RUN_ID,
            department_id: $('#joinFilterDepartment').val() || '',
            team_id: $('#joinFilterTeam').val() || '',
            position_id: $('#joinFilterPosition').val() || '',
            emp_cycle_id: $('#joinFilterCycle').val() || '',
            search: tb_join_employees ? tb_join_employees.search() : '',
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- "Select All Matching" now
            // also honors whatever Excel-style column filters are currently checked, not just the
            // pre-existing department/team/position/cycle dropdowns.
            column_filters: tb_join_employees ? getColumnFilterValues(tb_join_employees) : {}
        },
        dataType: 'json',
        success: function (res) {
            $btn.prop('disabled', false);
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            (res.employee_ids || []).forEach(id => { joinSelectedEmployees[id] = true; });
            updateJoinSelectedCountRd();
            if (tb_join_employees) tb_join_employees.draw(false);
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['save_failed'] || 'An error occurred.');
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
    // .datepicker('update') after programmatic .val() -- see CLAUDE.md's bootstrap-datepicker note
    // (widget state goes stale otherwise, blanking the field on next click-away).
    $('#edit_period_start, #edit_period_end, #edit_payment_date').datepicker('update');

    const offCycle = isOffCycleRunRd(currentRun);
    $('#edit_run_type_section').toggleClass('d-none', !offCycle);
    if (offCycle) {
        $('#edit_run_purpose').val(currentRun.run_purpose || 'payroll').trigger('change');
        $('#edit_run_compute_statutory').prop('checked', Number(currentRun.compute_statutory) === 1);
        $('#edit_run_include_base_salary').prop('checked', Number(currentRun.include_base_salary) === 1);
        $('#edit_run_include_standing_items').prop('checked', Number(currentRun.include_standing_items) === 1);
        updateEditRunTypeVisibility();
    }
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
    // Only sent for a genuine off-cycle run -- PayrollRunModel::update() ignores these fields
    // entirely for a cycle-based/Pending-Pull run anyway, but omitting them here keeps the
    // payload honest about what this specific save is actually allowed to change.
    if (isOffCycleRunRd(currentRun)) {
        payload.run_purpose = $('#edit_run_purpose').val();
        payload.compute_statutory = $('#edit_run_compute_statutory').is(':checked');
        payload.include_base_salary = $('#edit_run_include_base_salary').is(':checked');
        payload.include_standing_items = $('#edit_run_include_standing_items').is(':checked');
    }
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
        // 2026-08-27, real bug found while adding the equivalent quick-action modal to the Process
        // List page (this field was added earlier the same day but this call was missed) --
        // without initDatepicker() ever running on it, the .btn-tl-mark-paid handler's own
        // `.val(...).datepicker('update')` call would throw (`.datepicker` is not a function on an
        // un-initialized field), since bootstrap-datepicker only attaches that API once `.datepicker()`
        // has been called on the element at least once.
        initDatepicker('#run_mark_paid_date');
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#joinFilterDepartment, #joinFilterTeam, #joinFilterPosition, #joinFilterCycle', { mode: 'ajax' });
        initSelect2('#manualLineItemSelect', { mode: 'ajax' });
        initSelect2('#manualLineCustomType', { mode: 'static', selectedValue: 'earning' });
        // Initialized once here, not per-modal-open (2026-08-21 bug fix precedent from the
        // Attendance Deduction rate_unit dropdown -- re-initializing a select2 field on every open
        // can leave stale state/duplicate options behind).
        initSelect2('#manualLinePayeeEmployee', { mode: 'ajax', allowClear: true });
        initSelect2('#edit_run_purpose', { mode: 'static' });
    }
});
