let tb_payroll_run;
let tb_pending_sync;
let currentStation = 'draft'; // matches the station card marked .active in the view by default
let selectedPendingSync = {}; // id => row data, for the Pending Pull bulk-select bar

function toIsoDatePr(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function toDisplayDatePr(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
// 2026-08-29, real bug found and fixed (explicit report: "เวลาที่ Save ลงใน Database เป็น UTC การ
// แสดงผลให้แปลงเป็น timezone ปัจจุบันของผู้ใช้"). Same fix as payroll/detail.js's own
// toLocalDateOnlyRd() (see that file for the full reasoning) -- the mini-timeline dots below show
// just a DATE per step from a real UTC timestamp (created_at/submitted_at/approved_at/paid_at/
// locked_at), truncated to its first 10 chars BEFORE any timezone conversion, which can show the
// wrong calendar day for a viewer far from UTC.
function toLocalDateOnlyPr(value) {
    if (!value) return '';
    if (typeof formatDisplayDateTime !== 'function') return toDisplayDatePr(String(value).substring(0, 10));
    return formatDisplayDateTime(value).split(' ')[0];
}
function escapeHtmlPr(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function fmtNumPr(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function stateBadgePr(state) {
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
    return `<span class="badge ${cls}">${text}</span>`;
}
// 2026-08-28, explicit request: "ใน Process List ให้มีสัญลักษณ์บอกด้วยครับ" (whether this run
// computes full payroll or is an off-cycle Incentive/Other Payment pull -- see
// PayrollRunModel::update()'s own docblock for the run_purpose/compute_statutory/
// include_base_salary/include_standing_items fields this reads). Only a normal 'payroll' run has
// no icon (the common case, nothing to flag); an incentive/off-cycle run gets a gift icon whose
// title lists exactly which of the 3 flags are on, reusing the same i18n strings the Create/Edit
// run modals already use for those checkboxes so there's no new translation to keep in sync.
function runTypeIconPr(row) {
    if (row.run_purpose !== 'incentive') return '';
    const parts = [];
    if (Number(row.compute_statutory) === 1) parts.push(langData['compute_statutory_label'] || 'Compute tax/SSO/PVD');
    if (Number(row.include_base_salary) === 1) parts.push(langData['include_base_salary_label'] || 'Include base salary');
    if (Number(row.include_standing_items) === 1) parts.push(langData['include_standing_items_label'] || 'Include standing items');
    const label = langData['run_purpose_incentive'] || 'Incentive / Other Payment';
    const title = parts.length ? `${label}: ${parts.join(', ')}` : label;
    return `<i class="fa-solid fa-gift text-warning me-1" title="${escapeHtmlPr(title)}"></i>`;
}
function employeeNamePr(row) {
    return (currentLang === 'th' ? row.created_by_name_th : row.created_by_name_en) || row.created_by_name_th || row.created_by_name_en || '-';
}
function updatedByNamePr(row) {
    return (currentLang === 'th' ? row.updated_by_name_th : row.updated_by_name_en) || row.updated_by_name_th || row.updated_by_name_en || '-';
}
function frequencyLabelPr(freq) {
    return (freq && langData['frequency_' + freq]) || freq || '-';
}

/* ---------- Compact per-row "Timeline" column (2026-08-22, explicit request: "หน้า Process List
   อยากให้เพิ่มอีก Column เป็น Timeline ย่อๆ ว่า Process นี้ถึงขั้นตอนไหนแล้ว และมีปุ่มลัดให้กดได้ ...
   แต่ต้องไม่กระทบกับ Function การทำงานหลัก") -- deliberately a SEPARATE, duplicated copy of
   detail.js's RUN_TIMELINE_STEPS/computeTimelineProgress (not shared/refactored) so the
   already-working Payroll Process Detail page's own full-size timeline is completely untouched by
   this change, matching this codebase's existing convention of keeping each page's JS file
   self-contained (escapeHtml.../fmtNum... etc. are already duplicated per page rather than shared).
   Renders into the new .mini-timeline widget (public/css/style.css, right after .process-timeline)
   instead of the full-size spine -- dots + connecting lines only, label/date moved into each dot's
   title tooltip since a table cell has nowhere near the width the full widget needs. */
const MINI_TIMELINE_STEPS = [
    { key: 'draft', labelKey: 'state_draft', dateField: 'created_at', icon: 'fa-file-alt' },
    { key: 'pending_approval', labelKey: 'state_pending_approval', dateField: 'submitted_at', icon: 'fa-paper-plane' },
    { key: 'approved', labelKey: 'state_approved', dateField: 'approved_at', icon: 'fa-check' },
    { key: 'paid', labelKey: 'state_paid', dateField: 'paid_at', icon: 'fa-money-check-dollar' },
    { key: 'locked', labelKey: 'state_locked', dateField: 'locked_at', icon: 'fa-lock' },
];
// row.cancelled_from_state (PayrollRunModel::list()'s new subquery column) stands in for
// detail.js's audit-log-derived cancelledFromState(run) here -- list() rows don't carry the full
// audit log (only get() does), so this small dedicated column is what makes the cancelled branch
// accurate without fetching each row's full history just for this compact widget.
function computeMiniTimelineProgress(row) {
    const state = row.state;
    if (state === 'rejected') {
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'rejected' } };
    }
    // 2026-08-22, explicit request ("Status ในหน้า Approve มี...Need Information") -- a real third
    // state, same branch slot/reasoning as 'rejected' above (also only ever reached FROM
    // pending_approval).
    if (state === 'need_info') {
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'need_info' } };
    }
    if (state === 'cancelled') {
        const fromKey = row.cancelled_from_state || 'draft';
        if (fromKey === 'draft') {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        const effectiveKey = fromKey === 'rejected' ? 'pending_approval' : fromKey;
        const idx = MINI_TIMELINE_STEPS.findIndex(s => s.key === effectiveKey);
        if (idx < 0) {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        return { reachedIdx: idx, branch: { atIndex: idx + 1, type: 'cancelled' } };
    }
    // 2026-08-29, real bug found and fixed (explicit report: "Locked จะเป็นสีเขียวตอนไหนครับ" -- see
    // detail.js's own computeTimelineProgress() docblock for the full explanation, ported here
    // verbatim since this is a duplicated copy of that same logic, same "each page stays self-
    // contained" convention this file's own top-of-file comment already documents). Was
    // `reachedIdx: idx - 1` -- a station only showed done/green once you'd moved PAST it, which
    // meant the LAST station (locked) could never turn green, since there's no state after it.
    const idx = MINI_TIMELINE_STEPS.findIndex(s => s.key === state);
    return { reachedIdx: idx, branch: null };
}
// Quick shortcut buttons (2026-08-22) -- deliberately only for a zero-extra-input transition:
// Submit (draft) and Lock (paid) both call the EXACT SAME existing endpoints detail.js already
// uses, no new backend/business logic at all. pending_approval has no shortcut here on purpose --
// approving now belongs on the rebuilt Payroll Approval page (permission-gated, captures a
// reason).
// 2026-08-27, explicit follow-up request ("ในหน้า Process List ให้แสดงปุ่มเพิ่มด้วยครับ" -- after
// adding the Mark as Paid button+modal to the Detail page's own timeline) -- Mark Paid genuinely
// DOES need payment method/reference/date input, so unlike Submit/Lock it can't be a one-click
// confirm; it opens the exact same #runMarkPaidModal markup/i18n keys the Detail page uses
// (duplicated into this page's own view, same "each page stays self-contained" convention this
// whole file already follows -- see the comment above MINI_TIMELINE_STEPS further up). Gated by
// row.can_finalize_payroll (new flag from PayrollController::list(), same permission
// PayrollRunModel::markPaid()/lock() themselves enforce) -- Lock below is now gated by the same
// flag too, closing a pre-existing gap where it rendered for anyone regardless of permission and
// only failed server-side on click.
// 2026-08-22, bug fix (explicit report: "มันคือปุ่มดำเนินการครับ อยากให้แสดงผลเป็นปุ่มอยู่อีกบรรทัด
// แยกออกจาก Timeline" -> follow-up: "ให้ปุ่มเป็นสีเดียวกับหน้า Detail") -- was a bare icon sitting
// inline right next to the dot chain, which read as an extra timeline dot instead of an action. Now
// a real labeled button (icon + text) on its own line below the dots, rendered by
// renderStatusTimelineCell() into a separate row of the cell -- solid btn-primary (this app's
// brand orange, see style.css's own .btn-primary override), matching the Detail page's own
// #btnSubmitRun button exactly (`btn btn-sm btn-primary`) instead of the outline variant.
function miniTimelineQuickActionHtml(row) {
    if (row.state === 'draft') {
        return `<button type="button" class="btn btn-sm btn-primary mt-quick-action-btn btn-quick-submit-run" data-id="${row.id}"><i class="fa-solid fa-paper-plane me-1"></i>${langData['action_submit'] || 'Submit for Approval'}</button>`;
    }
    if (row.state === 'approved' && row.can_finalize_payroll) {
        return `<button type="button" class="btn btn-sm btn-primary mt-quick-action-btn btn-quick-mark-paid-run" data-id="${row.id}" data-payment-date="${row.payment_date || ''}"><i class="fa-solid fa-money-check-dollar me-1"></i>${langData['action_mark_paid'] || 'Mark as Paid'}</button>`;
    }
    if (row.state === 'paid' && row.can_finalize_payroll) {
        return `<button type="button" class="btn btn-sm btn-primary mt-quick-action-btn btn-quick-lock-run" data-id="${row.id}"><i class="fa-solid fa-lock me-1"></i>${langData['action_lock'] || 'Lock'}</button>`;
    }
    return '';
}
// 2026-08-22, explicit request ("Timeline กับ Status ชื่อซ้ำกัน และมีจุดสุดท้ายที่มี icon...ต่างเพื่อน
// ถ้าเป็น icon ก็เปลี่ยนให้เป็น icon ทั้งหมด") -- two fixes on top of the previous pass: (1) dropped
// the current-step label/date line this widget briefly had, since the Status column right next to
// it already shows that same text -- redundant; (2) EVERY dot now shows a consistent icon (not
// just done/branch ones) -- not-yet-reached and current dots use their own step's icon (same set
// as the full-size .process-timeline's RUN_TIMELINE_STEPS in detail.js: file/paper-plane/check/
// money/lock), done overrides to a plain checkmark same as the full timeline does, branch dots
// keep their existing xmark/ban/question -- so no dot is ever blank next to ones that do have an
// icon.
const MINI_TIMELINE_BRANCH_ICONS = { rejected: 'fa-xmark', cancelled: 'fa-ban', need_info: 'fa-question' };
const MINI_TIMELINE_BRANCH_LABEL_KEYS = { rejected: 'state_rejected', cancelled: 'state_cancelled', need_info: 'state_need_info' };
function renderMiniTimelineDots(row) {
    const { reachedIdx, branch } = computeMiniTimelineProgress(row);
    const currentIndex = reachedIdx + 1;
    let dotsHtml = '<ul class="mini-timeline">';
    for (let i = 0; i < MINI_TIMELINE_STEPS.length; i++) {
        const step = MINI_TIMELINE_STEPS[i];
        let cls = '';
        let label = langData[step.labelKey] || step.key;
        let icon = step.icon;
        const isBranchHere = branch && branch.atIndex === i;
        if (isBranchHere) {
            cls = branch.type;
            label = langData[MINI_TIMELINE_BRANCH_LABEL_KEYS[branch.type]] || branch.type;
            icon = MINI_TIMELINE_BRANCH_ICONS[branch.type] || 'fa-ban';
        } else if (i <= reachedIdx) {
            cls = 'done';
            icon = 'fa-check';
        } else if (i === currentIndex) {
            cls = 'current';
        }
        const dateVal = row[step.dateField];
        const dateText = (cls === 'done' || cls === 'current' || isBranchHere) && dateVal ? toLocalDateOnlyPr(dateVal) : '';
        const title = escapeHtmlPr(`${label}${dateText ? ` (${dateText})` : ''}`);
        dotsHtml += `<li class="mt-step ${cls}"><span class="mt-dot" title="${title}"><i class="fa-solid ${icon}"></i></span></li>`;
        if (i < MINI_TIMELINE_STEPS.length - 1) {
            dotsHtml += `<span class="mt-line ${i <= reachedIdx ? 'done' : ''}"></span>`;
        }
    }
    dotsHtml += '</ul>';
    return dotsHtml;
}
// 2026-08-23, explicit request ("ถ้ามี Comment จากการอนุมัติ ให้นำมาแสดงด้วยใน Column Status แยกอาจ
// ยุบรวม Column Status กับ Column Timeline เนื่องจากมีความสอดคล้องกันในการแสดงผล และในColumn นี้ เพิ่ม
// ปุ่มดำเนินการที่สามารถกดได้ รวมถึงวันที่ Status เข้าไปด้วย") -- Status + Timeline + Last Updated
// collapse into this one cell: badge, mini-timeline dots, the reject/need-info comment when this
// row actually has one, the status date (updated_at -- same "always reflects the current status's
// own timestamp" reasoning as the Approval List's own Last Updated column), then the quick-action
// button on its own line. reject_reason/need_info_reason come straight off payroll_runs (list()
// already SELECTs r.*) -- an approve() note isn't shown here since it isn't a column on
// payroll_runs itself (only payroll_run_audit_logs.note), and pulling that in would mean an extra
// JOIN on every list() call just for this one glance-view; the full approve note is one click away
// via the row's own Timeline.
// 2026-08-23, explicit request ("Column Status ช่วยปรับ Design ให้สวยขึ้นหน่อยครับ ตอนนี้แน่นไปหมด") --
// re-laid-out into distinct rows with real breathing room instead of 4 plain-text lines stacked
// with a 4px gap: badge + date share a row (both compact facts), the dot-chain gets its own row
// with more room to sit in, and a reject/need-info comment renders as a tinted chip (truncated to
// one line with the full text still available via `title`, so one long reason can't blow out the
// row's height) instead of wrapped plain text.
function runCommentHtml(row) {
    let tone = null;
    let text = null;
    if (row.state === 'rejected' && row.reject_reason) {
        tone = 'danger';
        text = row.reject_reason;
    } else if (row.state === 'need_info' && row.need_info_reason) {
        tone = 'info';
        text = row.need_info_reason;
    }
    if (!text) {
        return '';
    }
    return `<div class="stc-comment stc-comment-${tone}" title="${escapeHtmlPr(text)}"><i class="fa-solid fa-comment-dots"></i><span>${escapeHtmlPr(text)}</span></div>`;
}
// 2026-08-23, explicit request ("ในหน้า Process List ถ้าส่ง Approve ไปแล้ว ควรมีปุ่มให้กดดู Workflow
// ของการอนุมัติด้วย") -- once a run has actually been submitted (submitted_at set -- same "must be
// sent for approval first" gate the Detail page's own Timeline button already uses), a small
// "Timeline" button opens the same Approval Flow modal the Approval Queue/Detail pages have,
// right from this row -- no need to open the Detail page just to see who's approved/who's pending.
function workflowTimelineButtonHtml(row) {
    if (!row.submitted_at) {
        return '';
    }
    return `<button type="button" class="btn btn-sm btn-outline-secondary mt-quick-action-btn btn-view-run-workflow" data-id="${row.id}"><i class="fa-solid fa-list-check me-1"></i>${langData['action_timeline'] || 'Timeline'}</button>`;
}
function renderStatusTimelineCell(row) {
    const dateVal = row.updated_at;
    // 2026-08-29, real bug found and fixed: was displaying the raw UTC time straight from the DB
    // string with no timezone conversion at all -- see formatDisplayDateTime()'s own docblock in
    // app.js (the reference fix this now reuses) for the full reasoning.
    const dateHtml = dateVal
        ? `<span class="stc-date"><i class="fa-regular fa-clock"></i>${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(dateVal) : dateVal}</span>`
        : '';
    const quickActionHtml = miniTimelineQuickActionHtml(row);
    const workflowBtnHtml = workflowTimelineButtonHtml(row);
    return `<div class="status-timeline-cell">
        <div class="stc-top">${stateBadgePr(row.state)}${dateHtml}</div>
        <div class="stc-timeline">${renderMiniTimelineDots(row)}</div>
        ${runCommentHtml(row)}
        ${(quickActionHtml || workflowBtnHtml) ? `<div class="stc-action d-flex flex-wrap gap-1">${quickActionHtml}${workflowBtnHtml}</div>` : ''}
    </div>`;
}

/* ---------- Approval Flow timeline modal (2026-08-23, explicit request: "ในหน้า Process List ถ้าส่ง
   Approve ไปแล้ว ควรมีปุ่มให้กดดู Workflow ของการอนุมัติด้วย") -- same .apv-* vertical-stage design as
   the Approval Queue/Detail pages' own Timeline modals (public/js/payroll/approval.js and
   public/js/payroll/detail.js -- see style.css's own comment for the full class mapping),
   duplicated rather than shared per this codebase's established per-page-JS convention. Read-only
   here on purpose -- no Approve/Reject/Revert buttons -- this page shows progress, acting on a run
   stays on the Payroll Approval page/Detail page. ---------- */
const APV_COLORS_PR = {
    done: { icon: '#16a34a', badgeBg: '#dcfce7', badgeText: '#15803d' },
    pending: { icon: '#f59e0b', badgeBg: '#fef3c7', badgeText: '#b45309' },
    rejected: { icon: '#ef4444', badgeBg: '#fee2e2', badgeText: '#b91c1c' },
    info: { icon: '#0d6efd', badgeBg: '#cfe2ff', badgeText: '#0a58ca' },
    muted: { icon: '#cbd5e1', badgeBg: '#f1f5f9', badgeText: '#64748b' },
};
function apvBadgeHtmlPr(tone, label) {
    const c = APV_COLORS_PR[tone] || APV_COLORS_PR.muted;
    return `<span class="apv-badge" style="background:${c.badgeBg};color:${c.badgeText};">${escapeHtmlPr(label)}</span>`;
}
function apvIconHtmlPr(tone, icon) {
    const c = APV_COLORS_PR[tone] || APV_COLORS_PR.muted;
    return `<div class="apv-stage-icon" style="background:${c.icon};"><i class="fa-solid ${icon}"></i></div>`;
}
function apvAvatarHtmlPr(name, size) {
    size = size || 26;
    const initial = (name || '?').trim().charAt(0).toUpperCase() || '?';
    return `<span class="apv-person-avatar" style="width:${size}px;height:${size}px;min-width:${size}px;font-size:${Math.round(size * 0.42)}px;">${escapeHtmlPr(initial)}</span>`;
}
function apvPersonLineHtmlPr(name) {
    return `<div style="display:flex;align-items:center;gap:8px;">${apvAvatarHtmlPr(name, 26)}<span class="apv-person-name">${escapeHtmlPr(name || '-')}</span></div>`;
}
function apvApproverTonePr(status) {
    return { approved: 'done', rejected: 'rejected', need_info: 'info', pending: 'pending', not_applicable: 'muted' }[status] || 'muted';
}
function apvApproverLabelPr(status) {
    const key = { approved: 'status_approved', rejected: 'status_rejected', need_info: 'state_need_info', pending: 'status_pending' }[status];
    return (key && langData[key]) || status;
}
function apvApproverSubstepHtmlPr(a) {
    const name = (currentLang === 'th' ? a.name_th : a.name_en) || a.name_th || a.name_en || a.employee_no;
    return `<div class="apv-substep">
        <div class="apv-substep-head">
            <span class="apv-substep-label">${apvAvatarHtmlPr(name, 22)}${escapeHtmlPr(name)}</span>
            ${apvBadgeHtmlPr(apvApproverTonePr(a.status), apvApproverLabelPr(a.status))}
        </div>
        ${a.acted_at ? `<div class="apv-substep-date"><i class="fa-regular fa-calendar"></i> ${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(a.acted_at) : escapeHtmlPr(a.acted_at)}</div>` : ''}
        ${a.note ? `<div class="apv-substep-remark">${escapeHtmlPr(a.note)}</div>` : ''}
    </div>`;
}
function apvApprovalStageInfoPr(state) {
    switch (state) {
        case 'pending_approval': return { tone: 'pending', icon: 'fa-hourglass-half', label: langData['state_pending_approval'] || 'Waiting for Approval' };
        case 'need_info': return { tone: 'info', icon: 'fa-circle-info', label: langData['state_need_info'] || 'Need Information' };
        case 'approved': case 'paid': case 'locked': return { tone: 'done', icon: 'fa-check', label: langData['state_approved'] || 'Approved' };
        case 'rejected': return { tone: 'rejected', icon: 'fa-xmark', label: langData['state_rejected'] || 'Not Approved' };
        default: return { tone: 'muted', icon: 'fa-hourglass', label: langData['status_pending'] || 'Not Started' };
    }
}
function apvApprovalStageHtmlPr(run) {
    const info = apvApprovalStageInfoPr(run.state);
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    const bodyHtml = approvers.length
        ? approvers.map(apvApproverSubstepHtmlPr).join('')
        : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`;
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtmlPr(info.tone, info.icon)}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['approval_flow_title'] || 'Approval'}</span>
                    ${apvBadgeHtmlPr(info.tone, info.label)}
                </div>
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
function apvPaidStageHtmlPr(run) {
    const isPaidOrLocked = run.state === 'paid' || run.state === 'locked';
    const tone = isPaidOrLocked ? 'done' : 'muted';
    const label = run.state === 'locked' ? (langData['state_locked'] || 'Locked') : (isPaidOrLocked ? (langData['state_paid'] || 'Paid') : (langData['status_pending'] || 'Pending'));
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtmlPr(tone, isPaidOrLocked ? 'fa-money-check-dollar' : 'fa-flag')}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['state_paid'] || 'Paid'}</span>
                    ${apvBadgeHtmlPr(tone, label)}
                </div>
                ${isPaidOrLocked && run.paid_at ? `<div class="apv-stage-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(run.paid_at) : escapeHtmlPr(run.paid_at)}</div>` : ''}
                <div class="apv-stage-body">
                    <span class="apv-muted-text">${isPaidOrLocked ? '' : (langData['waiting_for_approval_to_complete'] || 'Waiting for the approval process to complete.')}</span>
                </div>
            </div>
        </div>
    `;
}
function apvCreatedStageHtmlPr(run) {
    const creator = (currentLang === 'th' ? run.created_by_name_th : run.created_by_name_en) || run.created_by_name_th || run.created_by_name_en || '-';
    return `
        <div class="apv-stage apv-stage-last">
            <div class="apv-stage-marker">${apvIconHtmlPr('done', 'fa-plus')}</div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['stage_created'] || 'Created'}</span>
                    ${apvBadgeHtmlPr('done', langData['stage_created'] || 'Created')}
                </div>
                <div class="apv-stage-date">${run.created_at ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(run.created_at) : escapeHtmlPr(run.created_at)) : ''}</div>
                <div class="apv-stage-body">${apvPersonLineHtmlPr(creator)}</div>
            </div>
        </div>
    `;
}
function auditActionLabelPr(action) {
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
function renderAuditTimelinePr(logs) {
    if (!logs || !logs.length) {
        return `<div class="text-secondary small">${langData['no_history_yet'] || 'No action has been taken on this request yet.'}</div>`;
    }
    const ordered = logs.slice().reverse(); // newest first at the top, oldest at the bottom
    return ordered.map(l => {
        const actor = (currentLang === 'th' ? l.performed_by_name_th : l.performed_by_name_en) || l.performed_by_name_th || l.performed_by_name_en || '-';
        const metaParts = [];
        if (l.ip_address) metaParts.push(`<i class="fa-solid fa-location-dot"></i> ${escapeHtmlPr(l.ip_address)}`);
        if (l.user_agent) metaParts.push(`<i class="fa-solid fa-desktop"></i> ${escapeHtmlPr(l.user_agent)}`);
        return `<div class="apv-log-entry">
            <div class="apv-log-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(l.performed_at) : escapeHtmlPr(l.performed_at)}</div>
            <div class="apv-log-action">${escapeHtmlPr(auditActionLabelPr(l.action))} <span class="text-secondary fw-normal">(${escapeHtmlPr(actor)})</span></div>
            ${metaParts.length ? `<div class="apv-log-meta">${metaParts.join(' &nbsp; ')}</div>` : ''}
            ${l.note ? `<div class="apv-log-note">${escapeHtmlPr(l.note)}</div>` : ''}
        </div>`;
    }).join('');
}
function renderRunWorkflowModal(run) {
    $('#runWorkflowModalRunName').text(run.run_name || '');
    $('#runWorkflowModalBody').html(`
        <div class="apv-timeline">
            ${apvPaidStageHtmlPr(run)}
            ${apvApprovalStageHtmlPr(run)}
            ${apvCreatedStageHtmlPr(run)}
        </div>
        <hr>
        <h6 class="fw-bold small text-uppercase text-secondary">${langData['approval_history'] || 'History'}</h6>
        <div class="apv-timeline-log">${renderAuditTimelinePr(run.audit_log)}</div>
    `);
}
$(document).on('click', '.btn-view-run-workflow', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
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
            renderRunWorkflowModal(res.data);
            new bootstrap.Modal(document.getElementById('runWorkflowModal')).show();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
});
// 2026-08-29, explicit request: "มีปุ่ม i ให้คลิกดูรายละเอียดในหน้ารายการได้เลย" -- the employee-count
// column's red error pill (rendered only when error_employee_count > 0) opens this modal, fetched
// on demand per click (never pre-fetched per row -- see the render function's own comment above).
function renderRunErrorEmployeesModal(rows) {
    if (!rows.length) {
        return `<p class="text-muted mb-0">${langData['no_data_found'] || 'No data found.'}</p>`;
    }
    const items = rows.map(r => {
        const name = currentLang === 'th'
            ? escapeHtmlPr(`${r.name_th || ''} ${r.surname_th || ''}`.trim())
            : escapeHtmlPr(`${r.name_en || r.name_th || ''} ${r.surname_en || r.surname_th || ''}`.trim());
        const errors = (r.calc_errors || '').split(',').map(s => s.trim()).filter(Boolean);
        const errorList = errors.length
            ? `<ul class="mb-0 ps-3 small text-danger">${errors.map(e => `<li>${escapeHtmlPr(e)}</li>`).join('')}</ul>`
            : `<span class="small text-muted">${langData['no_details'] || 'No further details.'}</span>`;
        return `<div class="border rounded-3 p-2 mb-2">
            <div class="fw-semibold">${escapeHtmlPr(r.employee_no)} - ${name}</div>
            ${errorList}
        </div>`;
    }).join('');
    return items;
}
$(document).on('click', '.btn-view-run-errors', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.error-employees`,
        method: 'GET',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            $('#runErrorEmployeesModalBody').html(renderRunErrorEmployeesModal(res.data || []));
            new bootstrap.Modal(document.getElementById('runErrorEmployeesModal')).show();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
});
// Actions column for tb_payroll_run, rendered as one Bootstrap button-group (per explicit
// request). Edit/View are now a SINGLE merged button (per explicit request) -- both just
// navigate to the Detail page in a NEW tab using public_id (the IdCodec-encoded token, see
// PayrollController::list(), never the raw numeric id); only the icon/label/tooltip differ by
// state (pencil "Edit" for draft, eye "View" for anything else), since PayrollRunModel::update()
// itself only allows editing a draft run anyway -- actual editing happens on the Detail page's
// own edit modal, not a separate one here. Delete only makes sense for draft
// (PayrollRunModel::delete() rejects any other state); Cancel for anything not yet paid (matches
// PayrollRunModel::cancel()'s own allowed-state check).
// 2026-08-29, same-day follow-up (supersedes the 2026-08-29 "print report dropdown" note this
// replaced): "ถ้ากดออกรายงาน ให้เปิดหน้า Detail และมาค้างอยู่ที่ Tab Report เลย ไม่ต้อง Download แยก เปลี่ยน icon
// ตา เป็น icon Download" -- the old in-place TH/EN download dropdown (PR_REPORT_SHORTCUTS/
// renderRunReportsDropdown()/.pr-report-btn, all removed) is gone; the row's own View link (the
// eye icon, the only other action already pointing at the Detail page for a non-draft run) now
// doubles as the "export report" shortcut for every non-draft state -- icon swaps to a download
// icon and the link's own hash targets the Detail page's Reports tab id directly
// (payroll/detail.js's activateTabFromHash(), same mechanism a manual tab click + refresh
// persists through). Not gated to only approved/paid/locked here -- the Reports tab itself always
// shows its row set now (2026-08-29 "แต่ยังกดไม่ได้" fix, see loadRunReportsTab()'s own docblock),
// just with actions disabled until ready, so landing there early is a feature, not a dead end.
function renderRunActionsPr(row) {
    const isDraft = row.state === 'draft';
    // 2026-08-28, explicit request: "Process ที่ Cancel ให้สามารถลบข้อมูลออกไปได้" -- delete is now
    // also allowed for a cancelled run, not just draft (see PayrollRunModel::delete()'s own docblock).
    const isDeletable = isDraft || row.state === 'cancelled';
    let html = '<div class="btn-group border rounded-3 bg-white row-actions" role="group">';
    const viewHref = `${BASE_URL}/payroll-process/${row.public_id}${isDraft ? '' : '#run-reports-tab'}`;
    const viewIcon = isDraft ? 'fa-pen-to-square' : 'fa-download';
    const viewTitleKey = isDraft ? 'action_edit' : 'print_reports';
    const viewTitleFallback = isDraft ? 'Edit' : 'Print Reports';
    html += `<a href="${viewHref}" target="_blank" rel="noopener" class="btn ${isDraft ? 'btn-link text-warning' : 'btn-link text-info'}" title="${langData[viewTitleKey] || viewTitleFallback}"><i class="fa-solid ${viewIcon}"></i></a>`;
    if (['draft', 'pending_approval', 'approved', 'rejected'].includes(row.state)) {
        html += `<button type="button" class="btn btn-link text-danger border-start btn-cancel-run" data-id="${row.id}" title="${langData['action_cancel'] || 'Cancel'}"><i class="fa-solid fa-ban"></i></button>`;
    }
    if (isDeletable) {
        html += `<button type="button" class="btn btn-link text-danger border-start btn-delete-run" data-id="${row.id}" title="${langData['action_delete'] || 'Delete'}"><i class="fa-solid fa-trash-alt"></i></button>`;
    }
    html += '</div>';
    return html;
}
// Cancelling/deleting a run pulled from Origami sync returns its source process to the Pending
// Pull station (PayrollRunModel::cancel()/delete() clear sync_process_id) -- refresh that station
// too after either action succeeds, not just the main runs table, so it doesn't look stale.
function refreshAfterRunMutation() {
    if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
    if (tb_pending_sync) {
        tb_pending_sync.ajax.reload(null, false);
    } else {
        loadPendingSyncCount();
    }
}

// Client-side filter for the Station bar -- runs are fetched unfiltered-by-state (only the Date
// filter hits the server); clicking a station card just re-draws with this filter instead of a
// new request, and also doubles as the source for each card's live count (see
// updateStationCounts()). Scoped to this one table by id so it never affects other DataTables.
// Registered inside $(document).ready() below (not at parse time) -- this script tag runs before
// footer.php's <script src=".../dataTables.js">, so $.fn.dataTable doesn't exist yet up here.
function registerStationSearchFilter() {
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (settings.nTable.id !== 'tb_payroll_run') return true;
        if (!currentStation || currentStation === 'pending_sync') return true;
        return !!rowData && rowData.state === currentStation;
    });
}

function updateStationCounts() {
    if (!tb_payroll_run) return;
    // { search: 'none' } is required here -- the default row selector only returns rows that pass
    // the *currently active* filter (including our own station search plugin above), so without
    // this every card except the one currently selected would always tally as 0 no matter how many
    // runs actually exist in that state.
    const rows = tb_payroll_run.rows({ search: 'none' }).data().toArray();
    const counts = { draft: 0, pending_approval: 0, approved: 0, paid: 0, locked: 0, rejected: 0, need_info: 0, cancelled: 0 };
    rows.forEach(r => { if (Object.prototype.hasOwnProperty.call(counts, r.state)) counts[r.state]++; });
    Object.keys(counts).forEach(state => {
        $(`.station-card[data-state="${state}"] .station-count`).text(counts[state]);
    });
}

function initPayrollRunTable() {
    if ($.fn.DataTable.isDataTable('#tb_payroll_run')) {
        $('#tb_payroll_run').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payroll_run = $('#tb_payroll_run').DataTable({
        responsive: true,
        order: [[1, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/payroll-run.list`,
            dataSrc: 'data',
            data: function (d) {
                d.state = '';
                d.date_from = toIsoDatePr($('#filter_date_from').val());
                d.date_to = toIsoDatePr($('#filter_date_to').val());
            }
        },
        columns: [
            // Object-form render (not a plain function) so client-side sort/filter still operate on
            // the raw run_name string, not the display HTML with the conditional icon prefixed --
            // same DataTables sort-safety rule CLAUDE.md documents for formatted-date columns.
            { data: 'run_name', render: {
                display: (d, t, row) => `${runTypeIconPr(row)}<strong class="text-dark">${escapeHtmlPr(d)}</strong>`,
                sort: d => d,
                filter: d => d,
            } },
            { data: null, render: (d, t, row) => `${toDisplayDatePr(row.period_start_date)} - ${toDisplayDatePr(row.period_end_date)}` },
            { data: null, orderable: false, render: (d, t, row) => renderStatusTimelineCell(row) },
            // 2026-08-29, explicit request: "ต้องดึงไปแสดงผลในหน้า List ด้วยว่า Verify ไปแล้วกี่คน Lock
            // ข้อมูลแล้วกี่คน" -- object-form render (display/sort/filter split, same DataTables sort-
            // safety convention this app already uses for formatted date/badge columns) so sorting by
            // this column still sorts numerically by the raw employee_count, not by the rendered HTML string.
            // 2026-08-29, explicit follow-up: "ปรับ Column จำนวนพนักงาน Verify Lock ให้ดูง่ายขึ้น และถ้า
            // ข้อมูลไม่สมบูรณ์ให้มีบอกด้วย ว่าไม่สมบูรณ์กี่คนและมีปุ่ม i ให้คลิกดูรายละเอียดในหน้ารายการได้เลย"
            // -- redesigned from the previous inline-badge layout into a clearer stacked block (total
            // count as its own line, verify/lock/error as a wrapped pill row underneath), plus a new
            // red error_employee_count pill with a clickable "i" that opens #runErrorEmployeesModal
            // (fetched on demand via api/payroll-run.error-employees -- never pre-fetched per row).
            { data: 'employee_count', className: 'text-end', render: {
                display: (d, t, row) => {
                    const verified = Number(row.verified_employee_count || 0);
                    const locked = Number(row.locked_employee_count || 0);
                    const errors = Number(row.error_employee_count || 0);
                    const pills = [];
                    if (verified) pills.push(`<span class="badge rounded-pill bg-success-subtle text-success" title="${langData['verified'] || 'Verified'}"><i class="fa-solid fa-check-double me-1"></i>${verified}</span>`);
                    if (locked) pills.push(`<span class="badge rounded-pill bg-secondary-subtle text-secondary" title="${langData['locked'] || 'Locked'}"><i class="fa-solid fa-lock me-1"></i>${locked}</span>`);
                    if (errors) pills.push(`<button type="button" class="badge rounded-pill bg-danger-subtle text-danger border-0 btn-view-run-errors" data-id="${row.id}" title="${langData['incomplete_data'] || 'Incomplete data'}"><i class="fa-solid fa-triangle-exclamation me-1"></i>${errors}<i class="fa-solid fa-circle-info ms-1"></i></button>`);
                    const pillRow = pills.length ? `<div class="d-flex gap-1 justify-content-end flex-wrap mt-1">${pills.join('')}</div>` : '';
                    return `<div class="fw-semibold">${d} <i class="fa-solid fa-users text-muted ms-1 small"></i></div>${pillRow}`;
                },
                sort: d => d,
                filter: d => d,
            } },
            // 2026-08-29, real bug found via a system-wide table audit: sort-safety fix -- plain
            // `render: fn` meant client-side sort/filter operated on the formatted string, not the
            // raw numeric amount (same class of bug already documented in CLAUDE.md).
            { data: 'total_net_amount', className: 'text-end', render: { display: d => fmtNumPr(d), sort: d => Number(d || 0), filter: d => Number(d || 0) } },
            { data: null, render: (d, t, row) => escapeHtmlPr(employeeNamePr(row)) },
            // 2026-08-29, explicit request: "ช่วยเพิ่ม Column ว่า Update ข้อมูลล่าสุดเมื่อไหร่ และใครเป็นคน
            // Update" -- object-form render (sort-safety, same convention as every other formatted-
            // date column in this app) so client-side sort operates on the raw updated_at timestamp,
            // not the dd/mm/yyyy display string.
            { data: 'updated_at', render: { display: (v) => v ? formatDisplayDateTime(v) : '-', sort: (v) => v || '', filter: (v) => v || '' } },
            { data: null, render: (d, t, row) => escapeHtmlPr(updatedByNamePr(row)) },
            // 2026-08-28, explicit request: "Column ท้ายสุดต้องเป็นปุ่มดำเนินการ...hidden ส่วนอื่นเป็น
            // ตัว expand แทน" -- className:'all' (dtr-all) keeps this last, already-actions column
            // from ever collapsing into the Responsive expand row, same fix as employee/list.js's
            // own 2026-08-27 precedent.
            { data: null, className: 'text-center all', orderable: false, render: (d, t, row) => renderRunActionsPr(row) },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-run').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-run">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="payroll_run">${langData['payroll_run'] || 'Payroll Run'}</span>
                    </button>
                `);
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the status-timeline widget (2, a visual component with
            // no single filterable value) and the actions column (6).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'run_name' },
                    { index: 1, key: 'period' },
                    { index: 3, key: 'employee_count' },
                    { index: 4, key: 'total_net_amount' },
                    { index: 5, key: 'employee_name' },
                    { index: 6, key: 'updated_at' },
                    { index: 7, key: 'updated_by' },
                ]
            });
        },
        drawCallback: function () { getTableLang(); updateStationCounts(); }
    });
    // 2026-08-21, real bug fix (explicit report: "คลิกที่ Column ไม่ได้...ไม่ขึ้น Tab ใหม่") --
    // was window.location.href (same-tab navigation), inconsistent with the Actions column's own
    // merged Edit/View button right next to it, which already opens in a new tab (target="_blank",
    // see renderRunActionsPr()'s own comment: "both just navigate to the Detail page in a NEW
    // tab" -- an explicit, already-documented design decision this row click just never matched).
    // Clicking anywhere else in the row now opens the same way.
    $('#tb_payroll_run tbody').off('click', 'tr').on('click', 'tr', function (e) {
        if ($(e.target).closest('.btn-add-run').length) return;
        if ($(e.target).closest('.row-actions').length) return;
        // 2026-08-23, real bug fix (explicit report: "ตรงช่อง Status กดปุ่ม แต่ดันไปเปิดหน้า Detail")
        // -- this handler is delegated on tbody, which sits CLOSER to the click target than the
        // quick-action button's own document-delegated handler (public/js/payroll/index.js's own
        // .btn-quick-submit-run/.btn-quick-lock-run, both of which already call
        // e.stopPropagation()) -- so during the native bubble phase THIS handler always runs
        // first regardless of that stopPropagation() call, and needs its own exclusion here too or
        // it opens the Detail tab before the button's handler ever gets a chance to stop it.
        if ($(e.target).closest('.stc-action').length) return;
        const rowData = tb_payroll_run.row(this).data();
        if (rowData && rowData.public_id) {
            window.open(`${BASE_URL}/payroll-process/${rowData.public_id}`, '_blank', 'noopener');
        }
    });
}

// Lightweight count-only fetch for the Pending Pull card -- used on initial page load and after
// any action that might change it, WITHOUT touching the #tb_pending_sync DataTable itself (which
// stays lazily initialized on first click of that station -- initializing a DataTable while its
// table is still display:none, as it is until then, miscalculates column widths).
function loadPendingSyncCount() {
    $.getJSON(`${BASE_URL}/api/payroll-sync.pending-list`, function (res) {
        const rows = (res && res.data) || [];
        $('.station-card[data-state="pending_sync"] .station-count').text(rows.length);
    });
}

function updateBulkPullBar() {
    const count = Object.keys(selectedPendingSync).length;
    $('#bulkPullCount').text(count);
    $('#bulkPullBar').toggleClass('d-none', count === 0).toggleClass('d-inline-flex', count > 0);
}

function initPendingSyncTable() {
    if ($.fn.DataTable.isDataTable('#tb_pending_sync')) {
        $('#tb_pending_sync').DataTable().ajax.reload(null, false);
        return;
    }
    tb_pending_sync = $('#tb_pending_sync').DataTable({
        responsive: true,
        order: [[7, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/payroll-sync.pending-list`,
            data: function (d) {
                d.date_from = toIsoDatePr($('#filter_date_from').val());
                d.date_to = toIsoDatePr($('#filter_date_to').val());
            },
            dataSrc: function (json) {
                const rows = json.data || [];
                $('.station-card[data-state="pending_sync"] .station-count').text(rows.length);
                return rows;
            }
        },
        columns: [
            { data: 'id', orderable: false, className: 'text-center', render: d => `<input type="checkbox" class="pending-sync-checkbox" value="${d}">` },
            {
                // 2026-08-29, see PAYROLL_SYNC_API.md's own run_kind field -- small badge next to
                // the process number so it's obvious at a glance which pending items are a normal
                // period-matched pull vs a standalone/ad-hoc one (e.g. OT-only, Trip-only).
                data: 'process_no', render: (d, t, row) => {
                    const isSupplemental = row.run_kind === 'supplemental';
                    const badge = isSupplemental
                        ? `<span class="badge bg-warning-subtle text-warning ms-1">${langData['sync_run_kind_supplemental'] || 'Supplemental'}</span>`
                        : '';
                    return `<strong class="text-dark">${escapeHtmlPr(d)}</strong>${badge}`;
                }
            },
            { data: 'period_name', render: d => escapeHtmlPr(d || '-') },
            { data: 'frequency_type', render: d => escapeHtmlPr(frequencyLabelPr(d)) },
            { data: 'item_count', className: 'text-end' },
            { data: 'unmapped_item_count', className: 'text-end', render: d => Number(d) > 0 ? `<span class="text-danger fw-semibold">${d}</span>` : d },
            // 2026-08-29, real bug found and fixed: raw UTC time with no timezone conversion, see
            // renderStatusTimelineCell()'s own comment above for the full reasoning.
            // 2026-08-29, real bug found via a system-wide table audit: sort-safety fix -- sort/
            // filter now key off the raw ISO datetime (sorts correctly as a string) instead of the
            // dd/mm/yyyy display string.
            { data: 'received_at', render: { display: d => d ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d) : '-', sort: d => d || '', filter: d => d || '' } },
            {
                // 2026-08-28: className:'all' keeps this last actions column from collapsing into
                // the Responsive expand row (dtr-all convention).
                data: null, orderable: false, className: 'text-center all',
                // 2026-08-29, see PAYROLL_SYNC_API.md -- data-subject/start/end/paid/run-kind carry
                // this row's own Origami cycle identity through to .btn-pull-sync's click handler,
                // which pre-fills (regular) or unlocks run_purpose (supplemental) from them --
                // explicit request: use what Origami already sent instead of re-entering by hand.
                render: (d, t, row) => `
                    <div class="btn-group rounded-3 row-actions" role="group">
                        <button type="button" class="btn btn-warning btn-pull-sync" data-id="${row.id}"
                            data-label="${escapeHtmlPr(row.process_subject || row.process_no)}"
                            data-subject="${escapeHtmlPr(row.process_subject || '')}"
                            data-description="${escapeHtmlPr(row.process_description || '')}"
                            data-start="${row.process_start || ''}" data-end="${row.process_end || ''}" data-paid="${row.process_paid || ''}"
                            data-run-kind="${row.run_kind || 'regular'}"
                            title="${langData['btn_pull_to_run'] || 'Pull to Run'}"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i><span data-i18n="btn_pull_to_run">${langData['btn_pull_to_run'] || 'Pull to Run'}</span></button>
                        <button type="button" class="btn btn-outline-info btn-view-sync" data-id="${row.id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>
                    </div>
                `
            },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            // Relocate the bulk-pull bar (static markup above the table) into the DataTables
            // length control row so the selection count/button sit next to "Show N entries"
            // instead of on their own line -- the bar keeps its d-none/d-inline-flex toggling
            // untouched since this only moves the existing DOM node, not a copy.
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $lengthDiv = $wrapper.find('.dt-length');
            if ($lengthDiv.length && $('#bulkPullBar').closest('.dt-length').length === 0) {
                $lengthDiv.append($('#bulkPullBar'));
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the row-select checkbox (0) and actions column (7).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 1, key: 'process_no' },
                    { index: 2, key: 'period_name' },
                    { index: 3, key: 'frequency_type' },
                    { index: 4, key: 'item_count' },
                    { index: 5, key: 'unmapped_item_count' },
                    { index: 6, key: 'received_at' },
                ]
            });
        },
        drawCallback: function () {
            getTableLang();
            // Restore checked state across redraws (page/search/reload) from the tracked
            // selection, and keep the header checkbox in sync with the current page's rows.
            const $rowBoxes = $('#tb_pending_sync tbody .pending-sync-checkbox');
            $rowBoxes.each(function () {
                $(this).prop('checked', Object.prototype.hasOwnProperty.call(selectedPendingSync, $(this).val()));
            });
            $('#pendingSyncSelectAll').prop('checked', $rowBoxes.length > 0 && $rowBoxes.filter(':not(:checked)').length === 0);
        }
    });
}

function mappingStatusBadgePr(isMapped) {
    return isMapped
        ? `<span class="badge rounded-pill bg-success-subtle text-success"><i class="fa-solid fa-check me-1"></i>${langData['sync_detail_mapped'] || 'Mapped'}</span>`
        : `<span class="badge rounded-pill bg-danger-subtle text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['sync_detail_unmapped'] || 'Unmapped'}</span>`;
}
function syncUnitLabelPr(unitType) {
    if (!unitType) return '';
    const map = {
        count: langData['sync_unit_count'] || 'time(s)',
        hours: langData['sync_unit_hours'] || 'hour(s)',
        days: langData['sync_unit_days'] || 'day(s)',
        minutes: langData['sync_unit_minutes'] || 'minute(s)',
    };
    return map[unitType] || unitType;
}
function syncDetailSectionHeaderPr(num, i18nKey, fallback) {
    return `
        <h6 class="text-secondary fw-bold mb-3 mt-1">
            <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">${num}</label>
            <span>${langData[i18nKey] || fallback}</span>
        </h6>
    `;
}
// Per-employee CARD, not a table row -- 11 columns of mixed badges/stacked-lines/small-text
// squeezed into one wide table row was the core complaint ("too dense, too many columns, no
// clear direction"), and no amount of border/stripe styling on a table fixes a structural
// density problem. A card per employee lets each attribute get its own labeled slot instead of
// fighting for horizontal space, and color is now used ONLY for status meaning (mapped/unmapped,
// SSO) -- every purely decorative icon (bank, id-card) stays neutral text-muted so color always
// means something specific instead of just decorating.
function renderSyncItemCardPr(item) {
    const isMapped = !!item.matched_employee_no;
    // 2026-08-30 rev 2: items[].emp_name (PAYROLL_SYNC_API.md) -- for an UNMAPPED row this used to
    // show nothing but the bare payroll_code, with no way to tell which real person it belongs to
    // without opening the raw payload. emp_name is display/verification only (payroll_code is still
    // the actual mapping key), so it's shown here but never used for the "matched" branch, which
    // already has a real, confirmed name from the employees table it resolved to.
    const nameLine = isMapped
        ? `<span class="fw-semibold">${escapeHtmlPr(item.matched_employee_no)}</span> <span class="text-muted">— ${escapeHtmlPr((currentLang === 'th' ? `${item.matched_name_th} ${item.matched_surname_th}` : `${item.matched_name_en} ${item.matched_surname_en}`).trim())}</span>`
        : `<span class="text-muted">${escapeHtmlPr(item.payroll_code)}</span>` + (item.emp_name ? ` <span class="text-muted">— ${escapeHtmlPr(item.emp_name)}</span>` : '');
    const values = (item.item_values || [])
        .filter(v => Number(v.value) !== 0)
        .map(v => {
            const unitLabel = syncUnitLabelPr(v.unit_type);
            return `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtmlPr(v.item_code)}: ${escapeHtmlPr(v.value)}${unitLabel ? ` ${escapeHtmlPr(unitLabel)}` : ''}</span>`;
        }).join('');
    const otBreakdown = [
        ['sync_ot_working_day', 'Working Day', item.ot_req_working_day_hrs],
        ['sync_ot_day_off', 'Day Off', item.ot_req_weekend_hrs],
        ['sync_ot_holiday', 'Holiday', item.ot_req_holiday_hrs],
    ]
        .filter(([, , hrs]) => Number(hrs || 0) !== 0)
        .map(([key, fallback, hrs]) => `${langData[key] || fallback} ${escapeHtmlPr(hrs)}h`)
        .join(' · ') || (item.ot_mins ? `${escapeHtmlPr(item.ot_mins)} ${langData['sync_unit_minutes'] || 'minute(s)'}` : '-');
    return `
        <div class="sync-emp-card${isMapped ? '' : ' sync-emp-card-unmapped'}">
            <div class="sync-emp-card-header">
                <div class="sync-emp-card-identity">
                    ${mappingStatusBadgePr(isMapped)}
                    <span class="sync-emp-card-name">${nameLine}</span>
                </div>
                <div class="sync-emp-card-dept">${escapeHtmlPr(item.dept_description || '-')} <span class="text-muted">/ ${escapeHtmlPr(item.position_name || '-')}</span></div>
            </div>
            <div class="sync-emp-stats">
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_working_days'] || 'Working Days'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.working_days ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_absent_days'] || 'Absent Days'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.absent_days ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_late_mins'] || 'Late (min)'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.late_mins ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_ot_breakdown'] || 'OT (hrs)'}</span><span class="sync-emp-stat-value">${otBreakdown}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_trip_allowance'] || 'Trip Allowance'}</span><span class="sync-emp-stat-value">${escapeHtmlPr(item.trip_allowance ?? '-')}</span></div>
            </div>
            <div class="sync-emp-card-footer">
                <span class="sync-emp-card-payment">${renderPaymentSsoCellPr(item)}</span>
                <span class="sync-emp-card-idcard">${renderIdCardCellPr(item)}</span>
                ${renderProbationStatusCellPr(item)}
                ${values ? `<span class="sync-emp-card-items">${values}</span>` : ''}
            </div>
        </div>
    `;
}
// Derived 3-state probation status (2026-08-19, explicit request) -- PayrollSyncModel::
// getProcessDetail() attaches item.probation_status ('on_probation'/'failed'/'passed'/null, null
// when pass_pro was never sent/not on file for this employee, nothing to show then).
function renderProbationStatusCellPr(item) {
    const map = {
        on_probation: ['bg-warning-subtle text-warning', 'sync_probation_on_probation', 'On Probation'],
        failed: ['bg-danger-subtle text-danger', 'sync_probation_failed', 'Did Not Pass Probation'],
        passed: ['bg-success-subtle text-success', 'sync_probation_passed', 'Passed Probation'],
    };
    const entry = map[item.probation_status];
    if (!entry) return '';
    const [cls, key, fallback] = entry;
    return `<span class="badge rounded-pill ${cls}">${langData[key] || fallback}</span>`;
}
function renderIdCardCellPr(item) {
    if (!item.id_card_no_masked) {
        return `<span class="text-muted">-</span>`;
    }
    const expire = item.id_card_expire_date
        ? ` <span class="text-muted">(${langData['id_card_expire'] || 'ID Card Expire Date'}: ${toDisplayDatePr(item.id_card_expire_date)})</span>`
        : '';
    return `<span><i class="fa-solid fa-id-card text-muted me-1"></i>${escapeHtmlPr(item.id_card_no_masked)}</span>${expire}`;
}
function renderPaymentSsoCellPr(item) {
    let payLine;
    if (item.pay_type === 'transfer') {
        const bankLabel = item.pay_bank_name ? escapeHtmlPr(item.pay_bank_name) : (langData['sync_pay_transfer'] || 'Transfer');
        const maskedNo = item.pay_bank_no_masked ? ` (${escapeHtmlPr(item.pay_bank_no_masked)})` : '';
        payLine = `<i class="fa-solid fa-building-columns text-muted me-1"></i>${bankLabel}${maskedNo}`;
    } else if (item.pay_type === 'cash') {
        payLine = `<i class="fa-solid fa-money-bill text-muted me-1"></i>${langData['sync_pay_cash'] || 'Cash'}`;
    } else {
        payLine = `<span class="text-muted">-</span>`;
    }
    let ssoBadge;
    if (item.deduct_sso === null || item.deduct_sso === undefined) {
        ssoBadge = `<span class="badge rounded-pill bg-light text-muted border">${langData['sync_sso_not_set'] || 'SSO: Not Set'}</span>`;
    } else if (Number(item.deduct_sso) === 1) {
        ssoBadge = `<span class="badge rounded-pill bg-info-subtle text-info">${langData['sync_sso_deduct'] || 'SSO: Deduct'}</span>`;
    } else {
        ssoBadge = `<span class="badge rounded-pill bg-light text-secondary border">${langData['sync_sso_no_deduct'] || 'SSO: No Deduct'}</span>`;
    }
    return `<span>${payLine}</span> ${ssoBadge}`;
}
function renderSyncStatusRowPr(row) {
    return `
        <tr>
            <td>${escapeHtmlPr(row.payroll_code)}</td>
            <td>${escapeHtmlPr(row.emp_name || '-')}</td>
            <td>${escapeHtmlPr(row.dept_description || '-')}<br><span class="text-muted small">${escapeHtmlPr(row.position_name || '-')}</span></td>
            <td>${row.emp_start_date ? toDisplayDatePr(row.emp_start_date) : '-'}</td>
            <td>${row.emp_resign_date ? toDisplayDatePr(row.emp_resign_date) : '-'}</td>
            <td class="text-center">${Number(row.is_new_hire) === 1 ? '<i class="fa-solid fa-circle-check text-success"></i>' : '<span class="text-muted">-</span>'}</td>
            <td class="text-center">${Number(row.is_resigned_this_period) === 1 ? '<i class="fa-solid fa-circle-check text-danger"></i>' : '<span class="text-muted">-</span>'}</td>
            <td>${escapeHtmlPr(row.status_text || '-')}</td>
        </tr>
    `;
}
function syncSummaryFieldPr(icon, i18nKey, fallback, value) {
    return `
        <div class="col-sm-4 col-lg-3">
            <div class="sync-summary-field">
                <div class="sync-summary-icon"><i class="fa-solid ${icon}"></i></div>
                <div>
                    <div class="text-muted small">${langData[i18nKey] || fallback}</div>
                    <div class="fw-bold">${value}</div>
                </div>
            </div>
        </div>
    `;
}
function renderSyncDetail(data) {
    const items = data.items || [];
    const statusRows = data.employee_status || [];
    // 2026-08-29, real bug found and fixed: raw UTC time with no timezone conversion, see
    // renderStatusTimelineCell()'s own comment for the full reasoning.
    const receivedAt = data.received_at ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(data.received_at) : data.received_at) : '-';
    const unmapped = Number(data.unmapped_item_count) || 0;
    const html = `
        <div class="sync-summary-card row g-3 mb-4">
            ${syncSummaryFieldPr('fa-hashtag', 'table_process_no', 'Process No', escapeHtmlPr(data.process_no))}
            ${syncSummaryFieldPr('fa-building', 'table_comp_name', 'Company', escapeHtmlPr(data.origami_comp_name))}
            ${syncSummaryFieldPr('fa-calendar-days', 'table_period', 'Pay Period', escapeHtmlPr(data.period_name || '-'))}
            ${syncSummaryFieldPr('fa-repeat', 'table_frequency', 'Frequency', escapeHtmlPr(frequencyLabelPr(data.frequency_type)))}
            ${syncSummaryFieldPr('fa-users', 'table_employee_count', 'Employees', escapeHtmlPr(data.item_count))}
            ${syncSummaryFieldPr('fa-triangle-exclamation', 'table_unmapped', 'Unmapped', unmapped > 0 ? `<span class="text-danger">${unmapped}</span>` : unmapped)}
            ${syncSummaryFieldPr('fa-clock', 'table_received_at', 'Received', receivedAt)}
        </div>
        <div class="detail-section mb-4">
            ${syncDetailSectionHeaderPr(1, 'sync_detail_items_section', 'Employee Attendance Data')}
            <div class="sync-emp-card-list">
                ${items.length ? items.map(renderSyncItemCardPr).join('') : `<div class="text-center text-muted py-3">-</div>`}
            </div>
        </div>
        <div class="detail-section">
            ${syncDetailSectionHeaderPr(2, 'sync_detail_status_section', 'Employee Status Snapshot')}
            <div class="table-responsive sync-detail-table">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>${langData['table_payroll_code'] || 'Payroll Code'}</th>
                            <th>${langData['table_matched_employee'] || 'Matched Employee'}</th>
                            <th>${langData['table_dept_position'] || 'Dept / Position'}</th>
                            <th>${langData['table_start_date'] || 'Start Date'}</th>
                            <th>${langData['table_resign_date'] || 'Resign Date'}</th>
                            <th class="text-center">${langData['table_new_hire'] || 'New Hire'}</th>
                            <th class="text-center">${langData['table_resigned_this_period'] || 'Resigned'}</th>
                            <th>${langData['table_status_text'] || 'Status'}</th>
                        </tr>
                    </thead>
                    <tbody>${statusRows.length ? statusRows.map(renderSyncStatusRowPr).join('') : `<tr><td colspan="8" class="text-center text-muted py-3">-</td></tr>`}</tbody>
                </table>
            </div>
        </div>
    `;
    $('#pendingSyncViewBody').html(html);
}

function resetRunForm() {
    $('#payrollRunForm')[0].reset();
    $('.is-invalid').removeClass('is-invalid');
    $('#run_cycle_id').val('').trigger('change');
    $('#run_sync_process_id').val('');
    $('#run_is_offcycle').prop('checked', false);
    $('#run_offcycle_row').removeClass('d-none');
    $('#run_purpose').val('payroll').trigger('change');
    $('#run_compute_statutory').prop('checked', true);
    $('#run_include_base_salary').prop('checked', false);
    $('#run_include_standing_items').prop('checked', false);
    setOffCycleMode(false);
}
// Off-cycle runs (e.g. an out-of-cycle payment) skip the Payroll Cycle field entirely -- per
// explicit request. Only offered on the standalone "Add" flow; Pull-to-run hides the toggle
// entirely (that data is inherently cycle-based) via #run_offcycle_row.addClass('d-none').
function setOffCycleMode(isOffCycle) {
    $('#run_cycle_row').toggleClass('d-none', isOffCycle);
    $('#run_cycle_id').toggleClass('required', !isOffCycle);
    if (isOffCycle) {
        $('#run_cycle_id').val('').trigger('change');
        $('#run_cycle_id').removeClass('is-invalid');
    }
    // Period Start/End are only required for a cycle-based run -- an off-cycle run (e.g. a
    // special bonus payout) doesn't always have a meaningful attendance period, per explicit
    // request. Payment Date stays required either way -- toggled independently, never touched
    // here. #run_period_required_mark is the red "*" next to the Period Start/End label only
    // (Payment Date has its own separate, always-shown "*").
    $('#run_period_start, #run_period_end').toggleClass('required', !isOffCycle);
    $('#run_period_required_mark').toggleClass('d-none', isOffCycle);
    if (isOffCycle) {
        $('#run_period_start, #run_period_end').removeClass('is-invalid');
    }
    // Run Purpose (Payroll / Incentive-Other Payment) only makes sense for a genuine off-cycle
    // run, per explicit request (2026-08-19) -- PayrollRunModel::create() rejects run_purpose=
    // 'incentive' outright whenever a cycle is selected, so hiding it here just keeps the form
    // from offering a choice the backend would reject anyway.
    $('#run_purpose_row').toggleClass('d-none', !isOffCycle);
    if (!isOffCycle) {
        $('#run_purpose').val('payroll').trigger('change');
    }
}
// Compute Statutory/Include Base Salary/Include Standing Items only matter (and only show) once
// Incentive/Other Payment is actually selected -- a normal Payroll run always includes all three,
// no choice to offer.
function updateComputeStatutoryVisibility() {
    const isIncentive = $('#run_purpose').val() === 'incentive';
    $('#run_compute_statutory_row, #run_include_base_salary_row, #run_include_standing_items_row').toggleClass('d-none', !isIncentive);
}
// Auto-fills Period Start/End/Payment Date from the selected cycle's own configured cutoff/
// payment day settings, per explicit request -- pure convenience default, every field stays
// editable afterward. Silently does nothing on failure (cycle not fully configured, network
// error, etc.) so manual entry always still works as a fallback.
function applySuggestedPeriod(cycleId) {
    if (!cycleId) {
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-cycle.suggest-period`,
        method: 'GET',
        data: { id: cycleId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                return;
            }
            $('#run_period_start').val(toDisplayDatePr(res.period_start_date));
            $('#run_period_end').val(toDisplayDatePr(res.period_end_date));
            $('#run_payment_date').val(toDisplayDatePr(res.payment_date));
            $('#run_period_start, #run_period_end, #run_payment_date').removeClass('is-invalid');
        }
    });
}
function validateRunForm() {
    let firstInvalid = null;
    $('#payrollRunModal .required').each(function () {
        const $el = $(this);
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    return firstInvalid;
}
function collectRunFormData() {
    const isOffCycle = $('#run_is_offcycle').is(':checked');
    // 2026-08-29: run_purpose used to be forced to 'payroll' whenever the manual off-cycle
    // checkbox wasn't ticked -- but setSupplementalPullMode() now also shows #run_purpose_row
    // (Payroll/Incentive-Other-Payment) for a supplemental sync pull, which never touches that
    // checkbox at all. Read the select whenever its row is actually visible, not just for the
    // manual off-cycle path, so a chosen "Incentive/Other Payment" on a supplemental pull is
    // actually submitted instead of silently reverting to 'payroll'.
    const purposeSelectable = isOffCycle || !$('#run_purpose_row').hasClass('d-none');
    const runPurpose = purposeSelectable ? ($('#run_purpose').val() || 'payroll') : 'payroll';
    return {
        // #run_cycle_row itself is only ever hidden for the manual off-cycle path -- a
        // supplemental sync pull keeps it visible (cycle becomes optional there, not gone), so
        // reading its value directly (rather than forcing null off isOffCycle alone) is correct
        // for both a normal add and a supplemental pull; only the manual off-cycle path needs the
        // explicit null (its own field is hidden and may hold a stale prior selection).
        cycle_id: $('#run_cycle_row').hasClass('d-none') ? null : ($('#run_cycle_id').val() || null),
        run_purpose: runPurpose,
        compute_statutory: runPurpose === 'incentive' && $('#run_compute_statutory').is(':checked') ? 1 : 0,
        include_base_salary: runPurpose === 'incentive' && $('#run_include_base_salary').is(':checked') ? 1 : 0,
        include_standing_items: runPurpose === 'incentive' && $('#run_include_standing_items').is(':checked') ? 1 : 0,
        run_name: $('#run_name').val().trim(),
        period_start_date: toIsoDatePr($('#run_period_start').val()),
        period_end_date: toIsoDatePr($('#run_period_end').val()),
        payment_date: toIsoDatePr($('#run_payment_date').val()),
        notes: $('#run_notes').val().trim(),
        sync_process_id: $('#run_sync_process_id').val() || null,
    };
}

// 2026-08-29, same-day follow-up: "อยากให้เลือก Station ไหนอยู่ ถ้า Refresh แล้ว ให้อยู่ Station เดิม" --
// persisted the exact same way Process Detail's own active-tab persistence works (URL hash +
// history.replaceState, see payroll/detail.js's own activateTabFromHash()/shown.bs.tab handler) so
// a browser refresh keeps whichever station card was selected instead of always resetting to Draft.
function showStation(state, opts) {
    currentStation = state;
    $('.station-card').removeClass('active');
    $(`.station-card[data-state="${state}"]`).addClass('active');
    if (!opts || !opts.skipHashUpdate) {
        if (history.replaceState) {
            history.replaceState(null, '', '#station-' + state);
        }
    }
    if (state === 'pending_sync') {
        $('#tb_payroll_run_wrapper').addClass('d-none');
        // The <table> itself starts with d-none in the markup (hidden until first shown) -- once
        // DataTables wraps it, the wrapper controls visibility, but the table's own d-none never
        // gets cleared unless we do it here explicitly (a hidden wrapper's visible child is still
        // hidden, but a visible wrapper's d-none child stays hidden too).
        $('#tb_pending_sync').removeClass('d-none');
        $('#tb_pending_sync_wrapper').removeClass('d-none');
        initPendingSyncTable();
    } else {
        $('#tb_pending_sync_wrapper').addClass('d-none');
        $('#tb_payroll_run_wrapper').removeClass('d-none');
        if (tb_payroll_run) tb_payroll_run.draw();
    }
}

$(document).on('click', '.station-card', function () {
    showStation($(this).data('state') || '');
});
$(document).on('click', '#stationFilterToggle', function () {
    const $filter = $('#stationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
// bootstrap-datepicker's core _setDate() fires BOTH 'changeDate' and the native 'change' event
// together, unconditionally, for every date-picked interaction (confirmed in the bundled
// library's own source) -- binding to both (an earlier fix here) double-fired this handler,
// causing two back-to-back ajax.reload() calls per pick (visible in Network as one cancelled
// request immediately followed by one 200). 'changeDate' alone is reliable on its own since it's
// the one _setDate() always fires regardless of code path (clicking a day, clearDates(), etc.).
// Clear Filter only makes sense (and only shows) once at least one of the two fields actually has
// a value -- per explicit request, hidden by default rather than always visible.
function updateClearFilterVisibility() {
    const hasFilter = !!($('#filter_date_from').val() || $('#filter_date_to').val());
    $('#btnClearDateFilter').toggleClass('d-none', !hasFilter);
}
$(document).on('changeDate', '#filter_date_from, #filter_date_to', function () {
    updateClearFilterVisibility();
    if (tb_payroll_run) tb_payroll_run.ajax.reload(null, true);
    // Also reload the Pending Pull ("Wait") table -- its own ajax now sends the same date_from/
    // date_to (filtered on received_at, its only real date field -- payroll_sync_processes has no
    // period_start/end of its own). Blindly reloading it unfiltered here used to make a genuinely
    // empty result on the main table look like "the filter gave up and fetched everything", since
    // this table would always come back full regardless of the date picked -- per explicit
    // feedback, a filter that matches nothing should just show nothing, not fall back to showing
    // everything.
    if (tb_pending_sync) tb_pending_sync.ajax.reload(null, true);
});
$(document).on('click', '#btnClearDateFilter', function () {
    // .datepicker('clearDates') goes through the same library API used to set them, so it fires
    // 'changeDate' itself and the handler above reloads both tables (and re-hides this button)
    // automatically -- no need to duplicate that here.
    $('#filter_date_from, #filter_date_to').datepicker('clearDates');
});
$(document).on('click', '.btn-add-run', function () {
    resetRunForm();
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
// 2026-08-29, explicit request referencing PAYROLL_SYNC_API.md's own run_kind field
// ("regular"/"supplemental", 2026-08-28 revision there): a supplemental sync process (a
// standalone/ad-hoc Origami cycle -- e.g. OT-only or Trip-only) is NOT tied to a period
// auto-match the way a regular sync-matched pull is, so run_purpose becomes choosable (same
// Payroll/Incentive-Other-Payment choice a genuine off-cycle run already offers, confirmed via
// AskUserQuestion) and the payroll cycle becomes optional rather than required. Deliberately does
// NOT touch #run_cycle_row's own visibility or #run_is_offcycle's checked state -- a supplemental
// sync pull is still sync-linked (sync_process_id set), never truly "off-cycle" the way the
// standalone Add-flow toggle means it; the cycle field just stops being mandatory.
function setSupplementalPullMode(isSupplemental) {
    $('#run_cycle_id').toggleClass('required', !isSupplemental);
    $('#run_purpose_row').toggleClass('d-none', !isSupplemental);
    if (!isSupplemental) {
        $('#run_purpose').val('payroll').trigger('change');
    }
    updateComputeStatutoryVisibility();
}
$(document).on('click', '.btn-pull-sync', function () {
    resetRunForm();
    const $btn = $(this);
    const runKind = $btn.data('run-kind') || 'regular';
    const subject = ($btn.data('subject') || '').toString();
    const description = ($btn.data('description') || '').toString();
    const start = ($btn.data('start') || '').toString();
    const end = ($btn.data('end') || '').toString();
    const paid = ($btn.data('paid') || '').toString();

    // Pulling from a sync process is inherently cycle-based data -- the manual off-cycle toggle
    // (a DIFFERENT concept, see setSupplementalPullMode()'s own comment) doesn't apply here, so
    // hide it entirely rather than just leaving it unchecked -- unchanged from before.
    $('#run_offcycle_row').addClass('d-none');
    $('#run_sync_process_id').val($btn.data('id'));
    $('#run_name').val(subject || $btn.data('label'));
    if (description) {
        $('#run_notes').val(description);
    }

    // A REGULAR sync process now carries its own real period dates (process_start/process_end/
    // process_paid) -- pre-fill them directly (still editable afterward, confirmed via
    // AskUserQuestion) instead of leaving the admin to re-enter what Origami already sent. A
    // SUPPLEMENTAL sync process has no period to auto-match, so there's nothing to pre-fill here --
    // setSupplementalPullMode() below is what makes that case usable instead.
    if (start && end) {
        $('#run_period_start').val(toDisplayDatePr(start));
        $('#run_period_end').val(toDisplayDatePr(end));
        $('#run_period_start, #run_period_end').removeClass('is-invalid');
    }
    if (paid) {
        $('#run_payment_date').val(toDisplayDatePr(paid));
        $('#run_payment_date').removeClass('is-invalid');
    } else if (end) {
        $('#run_payment_date').val(toDisplayDatePr(end));
    }

    setSupplementalPullMode(runKind === 'supplemental');
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
$(document).on('change', '#run_is_offcycle', function () {
    setOffCycleMode($(this).is(':checked'));
});
$(document).on('change', '#run_cycle_id', function () {
    applySuggestedPeriod($(this).val());
});
$(document).on('change', '#run_purpose', function () {
    updateComputeStatutoryVisibility();
});
$(document).on('click', '.btn-view-sync', function () {
    $('#pendingSyncViewBody').html(`<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['loading'] || 'Loading...'}</span></div>`);
    new bootstrap.Modal(document.getElementById('pendingSyncViewModal')).show();
    $.getJSON(`${BASE_URL}/api/payroll-sync.pending-get`, { id: $(this).data('id') })
        .done(function (res) {
            if (res.status) {
                renderSyncDetail(res.data);
            } else {
                $('#pendingSyncViewBody').html(`<div class="text-danger">${escapeHtmlPr(res.message || 'Error')}</div>`);
            }
        })
        .fail(function () {
            $('#pendingSyncViewBody').html(`<div class="text-danger">${langData['save_failed'] || 'An error occurred while saving the data.'}</div>`);
        });
});
$(document).on('change', '.pending-sync-checkbox', function () {
    const id = $(this).val();
    if (this.checked) {
        selectedPendingSync[id] = tb_pending_sync.row($(this).closest('tr')).data();
    } else {
        delete selectedPendingSync[id];
    }
    const $boxes = $('#tb_pending_sync tbody .pending-sync-checkbox');
    $('#pendingSyncSelectAll').prop('checked', $boxes.length > 0 && $boxes.filter(':not(:checked)').length === 0);
    updateBulkPullBar();
});
$(document).on('change', '#pendingSyncSelectAll', function () {
    $('#tb_pending_sync tbody .pending-sync-checkbox').prop('checked', this.checked).trigger('change');
});
$(document).on('click', '#btnBulkPull', function () {
    const ids = Object.keys(selectedPendingSync);
    if (ids.length === 0) return;
    const $rows = $('#bulkPullRows').empty();
    ids.forEach(function (id) {
        const row = selectedPendingSync[id];
        $rows.append(`
            <div class="border rounded p-3 mb-3 bulk-pull-row" data-process-id="${id}">
                <div class="fw-bold mb-2">${escapeHtmlPr(row.process_no)} <span class="text-muted small">(${escapeHtmlPr(row.period_name || '-')})</span></div>
                <div class="row g-2">
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_cycle'] || 'Payroll Schedule'} <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote bulk-cycle-select required" data-api="/api/payroll-cycle.options"></select>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label mb-1">${langData['modal_run_name'] || 'Run Name'} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bulk-run-name required" value="${escapeHtmlPr(row.process_no)}">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_period_start'] || 'Period Start Date'} <span class="text-danger">*</span></label>
                        <div class="input-group"><input type="text" class="form-control datepicker bulk-period-start required" autocomplete="off"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_period_end'] || 'Period End Date'} <span class="text-danger">*</span></label>
                        <div class="input-group"><input type="text" class="form-control datepicker bulk-period-end required" autocomplete="off"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_payment_date'] || 'Payment Date'} <span class="text-danger">*</span></label>
                        <div class="input-group"><input type="text" class="form-control datepicker bulk-payment-date required" autocomplete="off"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                    </div>
                </div>
                <div class="bulk-pull-row-status mt-2"></div>
            </div>
        `);
    });
    if (typeof initSelect2 === 'function') initSelect2('.bulk-cycle-select', { mode: 'ajax' });
    if (typeof initDatepicker === 'function') initDatepicker('.bulk-period-start, .bulk-period-end, .bulk-payment-date');
    new bootstrap.Modal(document.getElementById('bulkPullModal')).show();
});
$(document).on('click', '#btnBulkPullSubmit', function () {
    const $rows = $('.bulk-pull-row');
    let hasInvalid = false;
    $rows.find('.required').each(function () {
        const $el = $(this);
        const val = ($el.val() || '').toString().trim();
        $el.toggleClass('is-invalid', !val);
        if (!val) hasInvalid = true;
    });
    if (hasInvalid) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const $btn = $(this).prop('disabled', true);
    const rowEls = $rows.toArray();
    let successCount = 0;
    let failCount = 0;
    let remappedTotal = 0;
    let placeholdersTotal = 0;
    let pedTypesTotal = 0;
    function processNext(i) {
        if (i >= rowEls.length) {
            $btn.prop('disabled', false);
            let summary = `${successCount} ${langData['bulk_pull_result_success'] || 'created'}, ${failCount} ${langData['bulk_pull_result_failed'] || 'failed'}`;
            const notes = [];
            if (remappedTotal > 0) notes.push(`${remappedTotal} ${langData['sync_remapped_employees'] || 'employee(s) newly matched via auto-sync'}`);
            if (placeholdersTotal > 0) notes.push(`${placeholdersTotal} ${langData['sync_placeholders_created'] || 'placeholder employee(s) created from sync data -- please complete their profiles'}`);
            if (pedTypesTotal > 0) notes.push(`${pedTypesTotal} ${langData['sync_ped_types_created'] || 'new earning/deduction item(s) added to the catalog -- please review their tax settings'}`);
            if (notes.length > 0) {
                summary += ` (${notes.join(', ')})`;
            }
            if (failCount === 0) {
                showSuccess(summary);
                bootstrap.Modal.getInstance(document.getElementById('bulkPullModal')).hide();
            } else {
                showWarning(summary);
            }
            selectedPendingSync = {};
            updateBulkPullBar();
            if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
            if (tb_pending_sync) {
                tb_pending_sync.ajax.reload(null, false);
            } else {
                loadPendingSyncCount();
            }
            return;
        }
        const $row = $(rowEls[i]);
        const payload = {
            cycle_id: $row.find('.bulk-cycle-select').val(),
            run_name: $row.find('.bulk-run-name').val().trim(),
            period_start_date: toIsoDatePr($row.find('.bulk-period-start').val()),
            period_end_date: toIsoDatePr($row.find('.bulk-period-end').val()),
            payment_date: toIsoDatePr($row.find('.bulk-payment-date').val()),
            sync_process_id: $row.data('process-id'),
        };
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                if (res.status) {
                    successCount++;
                    remappedTotal += Number(res.sync_summary?.remapped_count) || 0;
                    placeholdersTotal += Number(res.sync_summary?.placeholders_created) || 0;
                    pedTypesTotal += Number(res.sync_summary?.ped_types_created) || 0;
                    $row.find('.bulk-pull-row-status').html(`<span class="text-success small"><i class="fa-solid fa-check me-1"></i>${langData['bulk_pull_result_success'] || 'created'}</span>`);
                } else {
                    failCount++;
                    $row.find('.bulk-pull-row-status').html(`<span class="text-danger small"><i class="fa-solid fa-xmark me-1"></i>${escapeHtmlPr(res.message || 'failed')}</span>`);
                }
                processNext(i + 1);
            },
            error: function () {
                failCount++;
                $row.find('.bulk-pull-row-status').html(`<span class="text-danger small">${langData['save_failed'] || 'An error occurred.'}</span>`);
                processNext(i + 1);
            }
        });
    }
    processNext(0);
});
$(document).on('submit', '#payrollRunForm', function (e) {
    e.preventDefault();
    const invalidEl = validateRunForm();
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = collectRunFormData();
    const $btn = $('#payrollRunForm button[type="submit"]');
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                const remapped = Number(res.sync_summary?.remapped_count) || 0;
                const placeholders = Number(res.sync_summary?.placeholders_created) || 0;
                const pedTypesCreated = Number(res.sync_summary?.ped_types_created) || 0;
                const notes = [];
                if (remapped > 0) notes.push(`${remapped} ${langData['sync_remapped_employees'] || 'employee(s) newly matched via auto-sync'}`);
                if (placeholders > 0) notes.push(`${placeholders} ${langData['sync_placeholders_created'] || 'placeholder employee(s) created from sync data -- please complete their profiles'}`);
                if (pedTypesCreated > 0) notes.push(`${pedTypesCreated} ${langData['sync_ped_types_created'] || 'new earning/deduction item(s) added to the catalog -- please review their tax settings'}`);
                const successMsg = notes.length > 0
                    ? `${langData['save_success'] || 'Saved successfully.'} (${notes.join(', ')})`
                    : (langData['save_success'] || 'Saved successfully.');
                showSuccess(successMsg);
                bootstrap.Modal.getInstance(document.getElementById('payrollRunModal')).hide();
                if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
                if (tb_pending_sync) {
                    tb_pending_sync.ajax.reload(null, false);
                } else {
                    loadPendingSyncCount();
                }
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});

/* ---------- Row "Cancel" action ---------- */
$(document).on('click', '.btn-cancel-run', function (e) {
    e.stopPropagation();
    $('#cancel_run_id').val($(this).data('id'));
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
        data: JSON.stringify({ id: $('#cancel_run_id').val(), reason: reason }),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('cancelRunModal')).hide();
                refreshAfterRunMutation();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});

/* ---------- Row "Delete" action (draft or cancelled, see PayrollRunModel::delete()) ---------- */
$(document).on('click', '.btn-delete-run', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_run_message'] || 'Delete this payroll run? This cannot be undone.';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.delete`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['delete_success'] || 'Deleted successfully.');
                    refreshAfterRunMutation();
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

/* ---------- Mini-timeline quick actions (2026-08-22) -- both call the EXACT SAME existing
   endpoints detail.js's own Submit/Lock buttons already use, wrapped in the same showConfirm()
   pattern as every other row action on this page. Zero new backend/business logic -- purely a
   shortcut so a draft/paid run doesn't need a full page navigation for a zero-input transition. */
$(document).on('click', '.btn-quick-submit-run', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const title = langData['confirm_submit_message'] || 'Submit this payroll run for approval? You will not be able to edit amounts until it is sent back or rejected.';
    showConfirm(langData['action_submit'] || 'Submit for Approval', title, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.submit`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    refreshAfterRunMutation();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
    });
});
// 2026-08-27: Mark Paid needs input (payment method/reference/date), so unlike Submit/Lock right
// above/below it opens a small modal instead of a plain showConfirm(). #runMarkPaidQuickPaymentDate
// carries the id of the row currently being marked paid across that click -> submit round trip
// (module-scoped, since there's no `currentRun` object on this page the way detail.js has one).
let quickMarkPaidRunId = null;
$(document).on('click', '.btn-quick-mark-paid-run', function (e) {
    e.stopPropagation();
    quickMarkPaidRunId = $(this).data('id');
    $('#run_mark_paid_method').val('bank_transfer').trigger('change');
    $('#run_mark_paid_reference').val('');
    // Defaults to the run's own scheduled payment_date (carried on the button itself via
    // data-payment-date, set from row.payment_date -- the list's own row data already has it, no
    // extra AJAX round trip needed) -- must follow .val() with .datepicker('update'), see
    // detail.js's own btn-tl-mark-paid handler for the full bootstrap-datepicker desync mechanism
    // this guards against.
    $('#run_mark_paid_date').val(toDisplayDatePr($(this).data('payment-date'))).datepicker('update');
    $('.is-invalid', '#runMarkPaidForm').removeClass('is-invalid');
    new bootstrap.Modal(document.getElementById('runMarkPaidModal')).show();
});
$(document).on('submit', '#runMarkPaidForm', function (e) {
    e.preventDefault();
    if (!quickMarkPaidRunId) return;
    const method = $('#run_mark_paid_method').val();
    if (!method) {
        $('#run_mark_paid_method').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    bootstrap.Modal.getInstance(document.getElementById('runMarkPaidModal')).hide();
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.mark-paid`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({
            id: quickMarkPaidRunId,
            payment_method: method,
            payment_reference: $('#run_mark_paid_reference').val().trim() || null,
            payment_date: toIsoDatePr($('#run_mark_paid_date').val()) || null,
        }),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                refreshAfterRunMutation();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});
$(document).on('click', '.btn-quick-lock-run', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const title = langData['confirm_lock_title'] || 'Lock this entry?';
    const message = langData['confirm_lock_message'] || 'Once locked, this entry can no longer be edited or deleted.';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.lock`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    refreshAfterRunMutation();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
    });
});

// 2026-08-28, explicit request: "รวมถึงใน Process ด้วย จะไม่มีข้อมูลรอบที่ดึงมา" (including in
// Process too, there shouldn't be any pulled-round data) -- for a company with no Origami Payroll
// link at all (`companies.origami_payroll_comp_code IS NULL`, IS_ORIGAMI_PAYROLL_LINKED set once
// in layout/header.php), there will never be a real payroll_sync_processes row to pull from, so
// the "Pending Pull" station is hidden entirely rather than sitting there permanently at 0.
// Deliberately a SEPARATE flag from IS_ORIGAMI_HR_LINKED (used to gate the Employee/Holiday/
// Department/Position/Team Sync buttons elsewhere) -- these are two genuinely different Origami
// integrations with independent id spaces (ref_id vs. origami_payroll_comp_code), see
// header.php's own comment for the full reasoning.
function applyOrigamiPayrollLinkGating() {
    if (typeof IS_ORIGAMI_PAYROLL_LINKED !== 'undefined' && !IS_ORIGAMI_PAYROLL_LINKED) {
        $('.station-card[data-state="pending_sync"]').closest('.station-col').addClass('d-none');
    }
}

// 2026-08-28, explicit request: reload this Process List once Process Detail (opened in a
// separate browser tab via .btn-view-run/etc's window.open(...'_blank')) changes the run -- see
// markTabDirty()/watchTabDirty() in app.js and detail.js's own loadRunDetail() which marks dirty.
if (typeof watchTabDirty === 'function') {
    watchTabDirty('payroll_run_list_dirty', function () {
        if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
    });
}
function restoreStationFromHash() {
    const hash = (location.hash || '').replace('#station-', '');
    if (!hash) return;
    const $card = $(`.station-card[data-state="${hash}"]`);
    // Only honor the hash if that station card genuinely exists AND isn't hidden by
    // applyOrigamiPayrollLinkGating() (e.g. a stale #station-pending_sync from before the company's
    // Origami Payroll link was removed) -- falls back to the default 'draft' station otherwise,
    // same as a fresh visit with no hash at all.
    if ($card.length && $card.closest('.station-col').is(':visible')) {
        showStation(hash, { skipHashUpdate: true });
    }
}
$(document).ready(function () {
    applyOrigamiPayrollLinkGating();
    registerStationSearchFilter();
    initPayrollRunTable();
    restoreStationFromHash();
    if (typeof IS_ORIGAMI_PAYROLL_LINKED === 'undefined' || IS_ORIGAMI_PAYROLL_LINKED) {
        loadPendingSyncCount();
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#run_cycle_id', { mode: 'ajax' });
        initSelect2('#run_purpose', { mode: 'static' });
    }
    updateComputeStatutoryVisibility();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#filter_date_from');
        initDatepicker('#filter_date_to');
        initDatepicker('#run_period_start');
        initDatepicker('#run_period_end');
        initDatepicker('#run_payment_date');
        initDatepicker('#run_mark_paid_date');
    }
    updateClearFilterVisibility();
});
