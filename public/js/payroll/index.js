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
// 2026-08-31, explicit request ("สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX"): PayrollController may
// send the literal string "XXXX" instead of a real number for a masked figure -- passed through
// as-is rather than formatted (Number('XXXX') is NaN, which .toLocaleString() would otherwise
// render as the confusing literal text "NaN").
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
// 2026-09-01, explicit request: "ในตาราง Process แสดง List ควรมีสัญลักษณ์หรือ label บอก" -- where a run's
// data actually CAME FROM (pulled from Origami sync vs. tied to a Payroll Schedule/cycle vs. a
// genuine off-schedule/manual run) had no visual cue anywhere on this page before -- sync_process_id/
// cycle_id are already in every row (PayrollRunModel::list()'s own `r.*`), just never surfaced. See
// runOriginBadgePr() below.
// 2026-09-10, explicit report: "ไอคอนหน้าชื่อรอบ (cloud + ของขวัญ) สื่อไม่ชัด" -- was an icon-only
// (hover-tooltip-only) indicator prepended before the run name. Confirmed with the user: a short
// visible text badge, shown ONLY for the exception case (a run pulled from Origami sync) -- a
// cycle-based or manual run gets no badge at all (the badge exists to flag "this one is different",
// not to label every row) -- appended AFTER the name on the same line (not before, so it never pushes
// the name itself out of column alignment), reusing this same table's own neutral chip class (see
// the run_code cell's own `badge bg-light text-dark border`) rather than inventing a new color. The
// long-form label (run_origin_sync) stays as the badge's own tooltip.
function runOriginBadgePr(row) {
    if (!row.sync_process_id) {
        return '';
    }
    return `<span class="badge bg-light text-dark border ms-1" title="${escapeHtml(langData['run_origin_sync'] || 'Pulled from Origami')}">${escapeHtml(langData['run_origin_origami'] || 'Origami')}</span>`;
}
// 2026-09-01: pure classification helper (no markup) -- shared between the badge above and the new
// Origin filter's own client-side DataTables search function, so the 2 never define "what counts as
// sync/cycle/manual" differently from each other.
function runOriginKeyPr(row) {
    if (row.sync_process_id) return 'sync';
    if (row.cycle_id) return 'cycle';
    return 'manual';
}
// 2026-09-10, explicit report: same icon-clarity fix as runOriginBadgePr() above, same reasoning
// (short visible text badge instead of an icon-only tooltip, shown only for the exception case -- a
// normal 'payroll' run gets no badge, appended after the name, reuses the same neutral chip class).
function runTypeIconPr(row) {
    if (row.run_purpose !== 'incentive') return '';
    const parts = [];
    if (Number(row.compute_statutory) === 1) parts.push(langData['compute_statutory_label'] || 'Compute tax/SSO/PVD');
    if (Number(row.include_base_salary) === 1) parts.push(langData['include_base_salary_label'] || 'Include base salary');
    if (Number(row.include_standing_items) === 1) parts.push(langData['include_standing_items_label'] || 'Include standing items');
    if (Number(row.include_attendance_pay) === 1) parts.push(langData['include_attendance_pay_label'] || 'Include attendance pay');
    const label = langData['run_purpose_incentive'] || 'Incentive / Other Payment';
    const title = parts.length ? `${label}: ${parts.join(', ')}` : label;
    return `<span class="badge bg-light text-dark border ms-1" title="${escapeHtml(title)}">${escapeHtml(langData['run_type_special'] || 'Special run')}</span>`;
}
// 2026-09-01, explicit request: "หน้า List page ควรมี indicator บอกด้วยว่ารอบนี้ตั้งค่าไว้ให้ไปรวมกับรอบไหน" --
// this was the 2nd of the 2 known gaps flagged after the Detail-page merge-target-editing feature
// shipped (the 1st, editing it from the Detail page, is done -- see that page's own #edit_run_merge_
// target_id). merge_target_run_id survives past the source run's own soft-delete-on-merge (the row
// itself just stops existing in this list, same as it always has), so this only ever shows on a run
// that HASN'T been merged yet.
// 2026-09-02, same-day follow-up, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ และถ้ามีการอ้างอิงถึง
// รอบก็ให้แสดงด้วยครับ" -- was a hover-only icon prepended to Run Name (mergeTargetIconPr(), now retired);
// moved into the new dedicated Code column as always-visible text instead, right under the run's own
// code, so the reference is readable without hovering anything. Reuses run_merge_reference_inline
// ("{target}" placeholder) -- a short inline label, distinct from #mergeTargetBanner's own longer
// sentence-form copy (merge_target_banner_text) on the Detail page, which stays as-is.
function runCodeCellHtmlPr(row) {
    const ownCode = row.run_code
        ? `<span class="badge bg-light text-dark border font-monospace fw-normal">${escapeHtml(row.run_code)}</span>`
        : '<span class="text-muted">-</span>';
    if (row.merge_target_run_id) {
        const targetLabel = row.merge_target_run_code || row.merge_target_run_name || `#${row.merge_target_run_id}`;
        const tpl = langData['run_merge_reference_inline'] || 'Merges into: {target}';
        const refLine = `<div class="small text-primary mt-1" title="${escapeHtml(targetLabel)}"><i class="fa-solid fa-code-merge me-1"></i>${escapeHtml(tpl.replace('{target}', targetLabel))}</div>`;
        return ownCode + refLine;
    }
    // 2026-09-06: the "future cycle" merge-target form -- merge_target_run_id is still null (no
    // real round to merge into yet), so styled/worded distinctly (amber, hourglass icon) from the
    // "ready" case above rather than implying a real target already exists.
    if (row.merge_target_cycle_id) {
        const cycleLabel = row.merge_target_cycle_name || `#${row.merge_target_cycle_id}`;
        const periodLabel = (row.merge_target_period_start_date && row.merge_target_period_end_date)
            ? `${formatDisplayDate(row.merge_target_period_start_date)} - ${formatDisplayDate(row.merge_target_period_end_date)}`
            : '';
        // 2026-09-06, real gap found and fixed: PayrollRunModel::create() requires the target cycle
        // to be status='active' to create a NEW run against it at all -- if it's since been
        // deactivated/deleted, this spec is a genuine dead end (same category as the
        // Origami-attribution 'target_rejected' status) -- styled distinctly (danger, not just
        // amber) so it doesn't read as "still waiting, will resolve eventually".
        if (row.merge_target_cycle_status && row.merge_target_cycle_status !== 'active') {
            const deadTpl = langData['run_merge_waiting_cycle_inactive_inline'] || '{cycle} is no longer active -- this will never merge';
            const deadLine = `<div class="small text-danger mt-1" title="${escapeHtml(langData['run_merge_waiting_cycle_inactive_tooltip'] || 'The target Payroll Cycle was deactivated or deleted -- edit this run to pick a different merge target.')}"><i class="fa-solid fa-triangle-exclamation me-1"></i>${escapeHtml(deadTpl.replace('{cycle}', cycleLabel))}</div>`;
            return ownCode + deadLine;
        }
        const tpl = langData['run_merge_waiting_cycle_inline'] || 'Waiting for: {cycle} {period}';
        const refLine = `<div class="small text-warning mt-1" title="${escapeHtml(cycleLabel + ' ' + periodLabel)}"><i class="fa-solid fa-hourglass-half me-1"></i>${escapeHtml(tpl.replace('{cycle}', cycleLabel).replace('{period}', periodLabel))}</div>`;
        return ownCode + refLine;
    }
    return ownCode;
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
        // 2026-08-31, explicit request: same run-level Lock->Verify rename as detail.js's own
        // .btn-tl-lock (pure re-wording, same api/payroll-run.lock endpoint underneath) -- see that
        // file's own comment for why this uses action_verify_run, not action_lock.
        return `<button type="button" class="btn btn-sm btn-primary mt-quick-action-btn btn-quick-lock-run" data-id="${row.id}"><i class="fa-solid fa-check-double me-1"></i>${langData['action_verify_run'] || 'Verify'}</button>`;
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
        const title = escapeHtml(`${label}${dateText ? ` (${dateText})` : ''}`);
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
    return `<div class="stc-comment stc-comment-${tone}" title="${escapeHtml(text)}"><i class="fa-solid fa-comment-dots"></i><span>${escapeHtml(text)}</span></div>`;
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
    return `<span class="apv-badge" style="background:${c.badgeBg};color:${c.badgeText};">${escapeHtml(label)}</span>`;
}
function apvIconHtmlPr(tone, icon) {
    const c = APV_COLORS_PR[tone] || APV_COLORS_PR.muted;
    return `<div class="apv-stage-icon" style="background:${c.icon};"><i class="fa-solid ${icon}"></i></div>`;
}
// 2026-09-10, explicit request: show the employee's real photo (same profile_photo_path field/URL
// convention as employee/list.js's own avatar column) in front of an approver's name, falling back
// to the initial-letter circle when there's no photo on file.
// onerror handler for apvAvatarHtmlPr's own <img> below -- reads size/initial back off data-*
// attributes (already escapeAttr()'d, so no re-escaping needed here) rather than embedding the
// fallback markup as a string inside the onerror attribute itself.
function apvAvatarImgErrorPr(img) {
    const size = img.getAttribute('data-size');
    const initial = img.getAttribute('data-initial');
    img.outerHTML = `<span class="apv-person-avatar" style="width:${size}px;height:${size}px;min-width:${size}px;font-size:${Math.round(size * 0.42)}px;">${initial}</span>`;
}
function apvAvatarHtmlPr(name, size, photoPath) {
    size = size || 26;
    const initial = escapeAttr((name || '?').trim().charAt(0).toUpperCase() || '?');
    if (photoPath) {
        return `<img src="${BASE_URL}/${escapeAttr(photoPath)}" alt="" data-size="${size}" data-initial="${initial}" style="width:${size}px;height:${size}px;min-width:${size}px;border-radius:50%;object-fit:cover;object-position:center top;" onerror="apvAvatarImgErrorPr(this)">`;
    }
    return `<span class="apv-person-avatar" style="width:${size}px;height:${size}px;min-width:${size}px;font-size:${Math.round(size * 0.42)}px;">${initial}</span>`;
}
function apvPersonLineHtmlPr(name) {
    return `<div style="display:flex;align-items:center;gap:8px;">${apvAvatarHtmlPr(name, 26)}<span class="apv-person-name">${escapeHtml(name || '-')}</span></div>`;
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
            <span class="apv-substep-label">${apvAvatarHtmlPr(name, 22, a.profile_photo_path)}${escapeHtml(name)}</span>
            ${apvBadgeHtmlPr(apvApproverTonePr(a.status), apvApproverLabelPr(a.status))}
        </div>
        ${a.acted_at ? `<div class="apv-substep-date"><i class="fa-regular fa-calendar"></i> ${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(a.acted_at) : escapeHtml(a.acted_at)}</div>` : ''}
        ${a.note ? `<div class="apv-substep-remark">${escapeHtml(a.note)}</div>` : ''}
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
// 2026-08-30, explicit follow-up ("ยังไม่ได้ปรับ UI...ให้แสดงหลาย step ที่ actionable พร้อมกันแบบจุดๆ ว่า
// ตัวเองอยู่ตำแหน่งไหน และตำแหน่งก่อนหน้านั้นอนุมัติหรือยัง") -- renders approval_flow.steps (new, see
// ApprovalRequestModel::stepBreakdown()) as a dot-per-step mini-stepper + one grouped approver list
// per step, ONLY when present (a run actually routed through the Approval Workflow engine). Absent
// for the flat department-scoped fallback, which has no step concept -- apvApprovalStageHtmlPr()
// below falls back to the original flat .apv-substep pool exactly as before in that case.
function apvStepDotTonePr(step) {
    if (!step.unlocked) return 'apv-step-dot-locked';
    if (step.status === 'approved') return 'apv-step-dot-approved';
    if (step.status === 'rejected') return 'apv-step-dot-rejected';
    return 'apv-step-dot-pending';
}
function apvStepDotsHtmlPr(steps) {
    return `<div class="apv-step-dots">` + steps.map((s, i) => {
        const lockIcon = !s.unlocked ? `<span class="apv-step-dot-lock-icon"><i class="fa-solid fa-lock"></i></span>` : '';
        const icon = s.status === 'approved' ? '<i class="fa-solid fa-check"></i>' : (s.status === 'rejected' ? '<i class="fa-solid fa-xmark"></i>' : s.step_order);
        const connector = i < steps.length - 1 ? `<div class="apv-step-dot-connector${s.status === 'approved' ? ' apv-step-dot-connector-done' : ''}"></div>` : '';
        return `<div class="apv-step-dot-wrap" title="${escapeHtml(s.step_name || '')}">
            <div class="apv-step-dot ${apvStepDotTonePr(s)}">${icon}</div>
            ${lockIcon}
        </div>${connector}`;
    }).join('') + `</div>`;
}
function apvStepGroupHtmlPr(step) {
    const badgeHtml = !step.unlocked
        ? `<span class="apv-badge" style="background:#f1f5f9;color:#64748b;"><i class="fa-solid fa-lock me-1"></i>${langData['step_locked'] || 'Locked'}</span>`
        : apvBadgeHtmlPr(apvApproverTonePr(step.status), apvApproverLabelPr(step.status));
    const stepLabel = (langData['step_label'] || 'Step {n}').replace('{n}', step.step_order);
    const approversHtml = step.approvers.length
        ? step.approvers.map(apvApproverSubstepHtmlPr).join('')
        : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`;
    return `<div class="apv-step-group">
        <div class="apv-step-group-head">
            <span class="apv-step-group-title">${escapeHtml(stepLabel)}${step.step_name ? ': ' + escapeHtml(step.step_name) : ''}</span>
            ${badgeHtml}
        </div>
        <div class="apv-step-group-body">${approversHtml}</div>
    </div>`;
}
function apvApprovalStageHtmlPr(run) {
    const info = apvApprovalStageInfoPr(run.state);
    const steps = (run.approval_flow && run.approval_flow.steps) || null;
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    const bodyHtml = (steps && steps.length)
        ? apvStepDotsHtmlPr(steps) + steps.map(apvStepGroupHtmlPr).join('')
        : (approvers.length
            ? approvers.map(apvApproverSubstepHtmlPr).join('')
            : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`);
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
                ${isPaidOrLocked && run.paid_at ? `<div class="apv-stage-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(run.paid_at) : escapeHtml(run.paid_at)}</div>` : ''}
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
                <div class="apv-stage-date">${run.created_at ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(run.created_at) : escapeHtml(run.created_at)) : ''}</div>
                <div class="apv-stage-body">${apvPersonLineHtmlPr(creator)}</div>
            </div>
        </div>
    `;
}
// 2026-09-10: renderAuditTimelinePr() removed -- this modal's "History" section is gone (duplicated
// the Detail page's own Action History tab, see renderRunWorkflowModal() below); auditActionLabel()
// itself (app.js) is untouched, still used by detail.js's own tab.
function renderRunWorkflowModal(run) {
    $('#runWorkflowModalRunName').text(run.run_name || '');
    $('#runWorkflowModalBody').html(`
        <div class="apv-timeline">
            ${apvPaidStageHtmlPr(run)}
            ${apvApprovalStageHtmlPr(run)}
            ${apvCreatedStageHtmlPr(run)}
        </div>
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
            ? escapeHtml(`${r.name_th || ''} ${r.surname_th || ''}`.trim())
            : escapeHtml(`${r.name_en || r.name_th || ''} ${r.surname_en || r.surname_th || ''}`.trim());
        const errors = (r.calc_errors || '').split(',').map(s => s.trim()).filter(Boolean);
        const errorList = errors.length
            ? `<ul class="mb-0 ps-3 small text-danger">${errors.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>`
            : `<span class="small text-muted">${langData['no_details'] || 'No further details.'}</span>`;
        return `<div class="border rounded-3 p-2 mb-2">
            <div class="fw-semibold">${escapeHtml(r.employee_no)} - ${name}</div>
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
    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
    // ".btn-circle-action" section) replace the old adjacent .btn-group.
    // 2026-09-02, same-day follow-up, explicit request: "ปุ่ม Export ให้เป็นปุ่มเดียว กดแล้วมี Dropdown ให้
    // เลือกว่า Excel หรือ PDF...ปุ่มในตาราง อยากให้แสดงเป็นแถวเดียว" -- the 2 separate circular
    // Excel/PDF buttons below are merged into ONE dropdown-toggle button (same `.dropdown` +
    // `.btn-circle-action.dropdown-toggle` pattern this app's own Employment Certificate Template
    // list already established, see ectActionsGroupHtml()) both to cut the row down to fewer buttons
    // AND because 2 separate export icons was genuinely redundant next to each other. The 2 dropdown
    // items are plain `<button class="dropdown-item ...">` (not `<a href="#">`) reusing the exact
    // same `.btn-export-run-register`/`.btn-preview-run-register-pdf` classes + `data-id` the
    // existing $(document).on('click', ...) handlers below already bind to -- zero handler changes
    // needed, only the trigger markup moved into a dropdown menu. `flex-wrap` on the row's own
    // wrapper is also dropped here (`flex-nowrap` instead) now that this page always fits within one
    // row -- a narrow column scrolls horizontally rather than wrapping onto a second line.
    let html = '<div class="d-flex gap-1 justify-content-center flex-nowrap row-actions">';
    const viewHref = `${BASE_URL}/payroll-process/${row.public_id}${isDraft ? '' : '#run-reports-tab'}`;
    const viewIcon = isDraft ? 'fa-pen-to-square' : 'fa-download';
    const viewTitleKey = isDraft ? 'action_edit' : 'print_reports';
    const viewTitleFallback = isDraft ? 'Edit' : 'Print Reports';
    html += `<a href="${viewHref}" target="_blank" rel="noopener" class="btn btn-circle-action ${isDraft ? 'btn-link text-warning' : 'btn-link text-info'}" title="${langData[viewTitleKey] || viewTitleFallback}"><i class="fa-solid ${viewIcon}"></i></a>`;
    // 2026-08-31, same-day follow-up, explicit request: "Excel ให้ออกมาสรุปเป็น Column By Column
    // พนักงาน...สามารถ Export ได้จากหน้า List เอง...ให้ Export รายงวดเท่านั้น" -- per-row export
    // (PAYROLL_REGISTER, this ONE run's own employee-by-employee breakdown) is deliberately the
    // ONLY export entry point left on this page (the earlier whole-list-summary and occurrence-
    // reconciliation buttons were both removed per explicit follow-up request). Available for every
    // state (internal report, no state gate). PDF (2026-09-02) opens a small preview-then-choose-
    // language modal instead of downloading directly (#runRegisterPdfPreviewModal, this page's own
    // copy of the same pattern Process Detail's #reportPreviewModal already established -- see
    // runRegisterPdfPreview()); Excel stays a direct one-click download.
    html += `<div class="dropdown">
        <button type="button" class="btn btn-link btn-circle-action text-primary dropdown-toggle" data-bs-toggle="dropdown" title="${langData['export'] || 'Export'}"><i class="fa-solid fa-file-export"></i></button>
        <ul class="dropdown-menu">
            <li><button type="button" class="dropdown-item btn-export-run-register" data-id="${row.id}"><i class="fa-solid fa-file-excel text-success me-2"></i>${langData['export_excel'] || 'Export Excel'}</button></li>
            <li><button type="button" class="dropdown-item btn-preview-run-register-pdf" data-id="${row.id}"><i class="fa-solid fa-file-pdf text-danger me-2"></i>${langData['export_pdf'] || 'Export PDF'}</button></li>
        </ul>
    </div>`;
    // 2026-08-31, explicit request: "สามารถ Verify ทั้ง Process ได้เลย...ให้ Verify ได้ทั้ง Process ทั้ง Detail
    // และหน้า List" -- same action as the Detail page's #btnVerifyAllEmployees button, just reachable
    // without opening the run first. Draft-only (PayrollRunModel::setEmployeeVerified() itself
    // refuses any other state), matching the Detail-page button's own visibility gate.
    if (isDraft) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-success btn-verify-all-run" data-id="${row.id}" title="${langData['action_verify_all'] || 'Verify All'}"><i class="fa-solid fa-check-double"></i></button>`;
    }
    if (['draft', 'pending_approval', 'approved', 'rejected'].includes(row.state)) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-danger btn-cancel-run" data-id="${row.id}" title="${langData['action_cancel'] || 'Cancel'}"><i class="fa-solid fa-ban"></i></button>`;
    }
    if (isDeletable) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-danger btn-delete-run" data-id="${row.id}" title="${langData['action_delete'] || 'Delete'}"><i class="fa-solid fa-trash-alt"></i></button>`;
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
// 2026-09-01, explicit request: "ในส่วนของ Filter สามารถเพิ่มอะไรได้อีกไหม" -- Origin/Payroll Schedule/
// Run Purpose all joined the SAME client-side search function the Station cards themselves already
// use (no ajax.reload needed, every field is already in each row's own loaded data) rather than 3
// separate ext.search.push() entries.
function registerStationSearchFilter() {
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (settings.nTable.id !== 'tb_payroll_run' || !rowData) return true;
        if (currentStation && currentStation !== 'pending_sync' && rowData.state !== currentStation) return false;
        const origin = $('#filter_run_origin').val();
        if (origin && origin !== 'all' && runOriginKeyPr(rowData) !== origin) return false;
        const cycleFilter = $('#filter_run_cycle').val();
        if (cycleFilter && String(rowData.cycle_id || '') !== String(cycleFilter)) return false;
        const purpose = $('#filter_run_purpose').val();
        if (purpose && purpose !== 'all' && (rowData.run_purpose || 'payroll') !== purpose) return false;
        return true;
    });
}
$(document).on('change', '#filter_run_origin, #filter_run_cycle, #filter_run_purpose', function () {
    updateClearFilterVisibility();
    if (tb_payroll_run) tb_payroll_run.draw();
});

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
        // Sorts by Pay Period (index 2) descending -- unaffected by the 2026-09-10 column reorder,
        // since Pay Period's own position (Code, Run Name, Pay Period, ...) didn't change.
        order: [[2, 'desc']],
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
            // 2026-09-02, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ และถ้ามีการอ้างอิงถึงรอบก็ให้แสดง
            // ด้วยครับ" -- new dedicated Code column (run_code + a visible reference line when this run
            // has a merge_target_run_id set) -- see runCodeCellHtmlPr()'s own comment for why this
            // replaced the old hover-only icon on Run Name. Object-form render for the same sort-safety
            // reason as run_name below -- sort/filter key off the raw run_code, not the badge HTML.
            { data: 'run_code', render: {
                display: (d, t, row) => runCodeCellHtmlPr(row),
                sort: d => d || '',
                filter: d => d || '',
            } },
            // 2026-09-10, explicit request: "ตัด column ผู้สร้าง ออกจากตาราง...ย้ายไปแสดงเป็น tooltip ที่
            // ชื่อรอบ" -- the Created By column (employeeNamePr(row)) is gone from this table entirely;
            // its own value is now a native `title` attribute on this cell's <strong> instead, shown
            // on hover, reusing the existing table_created_by i18n key (no new key needed). Object-form
            // render unchanged (same sort-safety reason as before) -- only the display branch changed.
            { data: 'run_name', render: {
                display: (d, t, row) => `<strong class="text-dark" title="${escapeHtml((langData['table_created_by'] || 'Created By') + ': ' + employeeNamePr(row))}">${escapeHtml(d)}</strong>${runOriginBadgePr(row)}${runTypeIconPr(row)}`,
                sort: d => d,
                filter: d => d,
            } },
            { data: null, render: (d, t, row) => `${toDisplayDatePr(row.period_start_date)} - ${toDisplayDatePr(row.period_end_date)}` },
            // 2026-09-10, explicit request: "เรียง column ใหม่...สถานะ...จำนวนพนักงาน...ยอดสุทธิรวม" --
            // moved back up to right after Pay Period (was right before Updated By per the 2026-09-02
            // request this one explicitly supersedes) -- orderable:false/no-single-filterable-value,
            // same as before, still excluded from initExcelColumnFilters() below, only its position
            // changed.
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
            // 2026-09-10, explicit report: "แสดง '11 [icon]' บรรทัดหนึ่ง และ '[check] 11' อีกบรรทัด" --
            // consolidated into one line, no icon. Which form shows is keyed on the RUN'S OWN STATE,
            // not the verified count: draft/pending_approval/need_info/rejected (not yet approved --
            // employees can still be verified/unverified on this run) always show
            // "{verify_status_verified} {verified}/{total}", even when verified is 0; approved/paid/
            // locked/cancelled (decided -- verification no longer applies) show just "{total}". The
            // error pill (a real interactive button, not just an icon) is unrelated to this complaint
            // and stays on its own line underneath when there's incomplete data to flag -- unchanged.
            { data: 'employee_count', className: 'text-end', render: {
                display: (d, t, row) => {
                    const total = Number(d || 0);
                    const verified = Number(row.verified_employee_count || 0);
                    const errors = Number(row.error_employee_count || 0);
                    const preApprovalStates = ['draft', 'pending_approval', 'need_info', 'rejected'];
                    // 2026-09-09, round-creation flow copy audit round 3: dedicated key, NOT the
                    // shared 'verified' key (also used by Company Profile/Tax & Statutory for
                    // unrelated "verified" badges where "ยืนยันแล้ว" is the correct word) -- this
                    // app's own Verify-run action is standardized on "ตรวจสอบ" everywhere else.
                    const mainLine = preApprovalStates.includes(row.state)
                        ? escapeHtml(`${langData['verify_status_verified'] || 'Verified'} ${verified}/${total}`)
                        : escapeHtml(String(total));
                    const errorHtml = errors
                        ? `<div class="mt-1"><button type="button" class="badge rounded-pill bg-danger-subtle text-danger border-0 btn-view-run-errors" data-id="${row.id}" title="${langData['incomplete_data'] || 'Incomplete data'}"><i class="fa-solid fa-triangle-exclamation me-1"></i>${errors}<i class="fa-solid fa-circle-info ms-1"></i></button></div>`
                        : '';
                    return `<div class="fw-semibold">${mainLine}</div>${errorHtml}`;
                },
                sort: d => d,
                filter: d => d,
            } },
            // 2026-08-29, real bug found via a system-wide table audit: sort-safety fix -- plain
            // `render: fn` meant client-side sort/filter operated on the formatted string, not the
            // raw numeric amount (same class of bug already documented in CLAUDE.md).
            { data: 'total_net_amount', className: 'text-end', render: { display: d => fmtNum(d), sort: d => Number(d || 0), filter: d => Number(d || 0) } },
            // 2026-08-29, explicit request: "ช่วยเพิ่ม Column ว่า Update ข้อมูลล่าสุดเมื่อไหร่ และใครเป็นคน
            // Update" -- object-form render (sort-safety, same convention as every other formatted-
            // date column in this app) so client-side sort operates on the raw updated_at timestamp,
            // not the dd/mm/yyyy display string.
            { data: 'updated_at', render: { display: (v) => v ? formatDisplayDateTime(v) : '-', sort: (v) => v || '', filter: (v) => v || '' } },
            { data: null, render: (d, t, row) => escapeHtml(updatedByNamePr(row)) },
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
            // rollout, client mode. Excludes the status-timeline widget (a visual component with no
            // single filterable value) and the actions column.
            // 2026-09-10: Created By column removed entirely (moved to a run_name tooltip, see that
            // column's own render comment) and Status moved from index 7 back to index 3, right after
            // Pay Period -- indices below updated to match the new column order exactly: run_code(0)/
            // run_name(1)/period(2)/[status(3), skipped -- same "no single filterable value" reason as
            // before, only its index changed]/employee_count(4)/total_net_amount(5)/updated_at(6)/
            // updated_by(7)/[actions(8), skipped].
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'run_code' },
                    { index: 1, key: 'run_name' },
                    { index: 2, key: 'period' },
                    { index: 4, key: 'employee_count' },
                    { index: 5, key: 'total_net_amount' },
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

// 2026-08-31, same-day follow-up -- see #blockedSyncUpdatesCard's own comment in index.php. Reused
// after apply/dismiss the same way loadPendingSyncCount() is reused after reject/pull actions.
function blockedSyncUpdateRowHtml(row) {
    const subtitle = row.process_subject ? ` - ${escapeHtml(row.process_subject)}` : '';
    const runLink = `<a href="${BASE_URL}/payroll-process/${row.public_run_id}" target="_blank">${escapeHtml(row.run_name)}</a>`;
    return `<div class="d-flex justify-content-between align-items-center border rounded-3 p-2 mb-2 flex-wrap gap-2" data-id="${row.id}">
        <div>
            <div class="fw-semibold">${escapeHtml(row.process_no)}${subtitle}</div>
            <div class="small text-muted">
                <span data-i18n="blocked_update_linked_run">${langData['blocked_update_linked_run'] || 'Linked run'}</span>: ${runLink}
                (${escapeHtml(row.run_state)}) &middot;
                <span data-i18n="table_received_at">${langData['table_received_at'] || 'Received'}</span>: ${row.received_at ? formatDisplayDateTime(row.received_at) : '-'}
            </div>
        </div>
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-primary btn-apply-blocked-update" data-id="${row.id}">
                <i class="fa-solid fa-check me-1"></i><span data-i18n="btn_apply_update">${langData['btn_apply_update'] || 'Apply'}</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-dismiss-blocked-update" data-id="${row.id}">
                <span data-i18n="btn_dismiss_update">${langData['btn_dismiss_update'] || 'Dismiss'}</span>
            </button>
        </div>
    </div>`;
}
function loadBlockedSyncUpdates() {
    $.getJSON(`${BASE_URL}/api/payroll-sync.blocked-updates-list`, function (res) {
        const rows = (res && res.data) || [];
        $('#blockedSyncUpdatesCard').toggleClass('d-none', rows.length === 0);
        $('#blockedSyncUpdatesList').html(rows.map(blockedSyncUpdateRowHtml).join(''));
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
                    // 2026-08-31, PAYROLL_SYNC_API.md `attribution` revision -- a second badge on a
                    // supplemental row showing its routing intent BEFORE an admin pulls it, so
                    // "→ Merge into ORIGAMI-2026-00024" or "→ Separate" is visible at a glance
                    // instead of only surfacing after the fact. Only ever set on a supplemental row
                    // (see PayrollSyncModel::normalizeAttribution()'s own docblock).
                    //
                    // 2026-09-06: confirmed with Origami that a "merge" attribution's own target
                    // regular process can legitimately not exist on our side YET (no guaranteed send
                    // order between the two payloads -- e.g. a mid-month trip-allowance batch
                    // attributed to "next month's regular cycle" arrives before that cycle's own
                    // payload does). row.attribution_target_status (see PayrollSyncModel::
                    // attributionTargetStatus()) now distinguishes that from "ready to merge right
                    // now" so the badge/button reflect reality up front instead of the admin only
                    // finding out by clicking Merge and getting a refusal.
                    let attrBadge = '';
                    if (isSupplemental && row.attribution_tax_treatment === 'merge') {
                        // 2026-09-08, Origami email exchange (2 rounds) -- 'pending_fold_in' means
                        // Origami itself hasn't chosen a target AT ALL yet (no target id/no to show
                        // at all, unlike 'waiting_unknown' below which at least has a target NAME,
                        // just not received yet) -- resolves only via a future attribution_update
                        // event, checked first since there's no target to build the other badges'
                        // own {target} text from.
                        if (row.attribution_target_status === 'pending_fold_in') {
                            attrBadge = `<span class="badge bg-secondary-subtle text-secondary ms-1" title="${langData['sync_attribution_pending_fold_in_tooltip'] || 'Origami has not chosen a target regular cycle for this item yet. It will notify us automatically once a target is chosen.'}">${langData['sync_attribution_pending_fold_in'] || '→ Waiting for Origami to choose a target'}</span>`;
                            return `<strong class="text-dark">${escapeHtml(d)}</strong>${badge}${attrBadge}`;
                        }
                        const target = escapeHtml(row.attribution_target_process_no || `#${row.attribution_target_origami_process_id}`);
                        if (row.attribution_target_status === 'ready') {
                            attrBadge = `<span class="badge bg-info-subtle text-info ms-1">${(langData['sync_attribution_merge_into'] || '→ Merge into {target}').replace('{target}', target)}</span>`;
                        } else if (row.attribution_target_status === 'waiting_known') {
                            attrBadge = `<span class="badge bg-warning-subtle text-warning ms-1" title="${langData['sync_attribution_waiting_known_tooltip'] || 'The target regular cycle has been received from Origami but not pulled into a run yet.'}">${(langData['sync_attribution_waiting_known'] || '→ Waiting: {target} not pulled yet').replace('{target}', target)}</span>`;
                        } else if (row.attribution_target_status === 'target_rejected') {
                            // 2026-09-06: the target regular process was received but has since been
                            // REJECTED at Pending Pull -- a real dead end (no un-reject action exists),
                            // deliberately styled/worded differently from "waiting" so this doesn't read
                            // as "will become ready eventually" -- it never will on its own.
                            attrBadge = `<span class="badge bg-danger-subtle text-danger ms-1" title="${langData['sync_attribution_target_rejected_tooltip'] || 'The target regular cycle was rejected and will never be pulled into a run. Pull this as its own standalone run instead, or ask Origami to re-attribute it.'}">${(langData['sync_attribution_target_rejected'] || '→ {target} was rejected').replace('{target}', target)}</span>`;
                        } else {
                            attrBadge = `<span class="badge bg-secondary-subtle text-secondary ms-1" title="${langData['sync_attribution_waiting_unknown_tooltip'] || 'The target regular cycle has not been received from Origami yet.'}">${(langData['sync_attribution_waiting_unknown'] || '→ Waiting for {target}').replace('{target}', target)}</span>`;
                        }
                    } else if (isSupplemental && row.attribution_tax_treatment === 'separate') {
                        attrBadge = `<span class="badge bg-secondary-subtle text-secondary ms-1">${langData['sync_attribution_separate'] || '→ Separate'}</span>`;
                    }
                    return `<strong class="text-dark">${escapeHtml(d)}</strong>${badge}${attrBadge}`;
                }
            },
            { data: 'period_name', render: d => escapeHtml(d || '-') },
            { data: 'frequency_type', render: d => escapeHtml(frequencyLabelPr(d)) },
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
                render: (d, t, row) => {
                    // 2026-08-31, PAYROLL_SYNC_API.md `attribution` revision -- a supplemental row
                    // attributed tax_treatment='merge' gets an extra "Merge into Target" action
                    // alongside the existing "Pull to Run" (which still pulls it as its own
                    // standalone run -- always left available, e.g. for when the target run isn't
                    // draft anymore, see PayrollRunModel::mergeSupplementalIntoRun()'s own refusal
                    // paths).
                    // 2026-09-02, explicit request: "ไม่ต้องมี Word ก็ได้มันต่างเพื่อน" -- Pull to
                    // Run/Merge into Target used to carry their own text label while their sibling
                    // View/Reject buttons in the SAME group were already icon-only, making the group
                    // look inconsistent. Dropped the text (title="" tooltip still carries the label)
                    // so every button in this row-actions group is icon-only, matching its neighbors.
                    // 2026-09-06: disabled (not hidden) with an explanatory tooltip while
                    // attribution_target_status isn't 'ready' -- clicking used to just round-trip to
                    // the server and come back with PayrollRunModel::mergeSupplementalIntoRun()'s own
                    // refusal message; now the admin sees WHY up front (matches the badge above).
                    const mergeReady = row.attribution_target_status === 'ready';
                    const mergeBtn = (row.run_kind === 'supplemental' && row.attribution_tax_treatment === 'merge')
                        ? `<button type="button" class="btn btn-info btn-merge-sync" data-id="${row.id}"
                            data-label="${escapeHtml(row.process_subject || row.process_no)}"
                            data-target="${escapeHtml(row.attribution_target_process_no || ('#' + row.attribution_target_origami_process_id))}"
                            ${mergeReady ? '' : 'disabled'}
                            title="${mergeReady ? (langData['btn_merge_sync'] || 'Merge into Target') : (langData['btn_merge_sync_not_ready'] || 'The target round is not ready yet -- see the badge above. You can still use "Pull to Run" to pull this as its own standalone round instead.')}"><i class="fa-solid fa-code-merge"></i></button>`
                        : '';
                    return `
                    <div class="btn-group rounded-3 row-actions" role="group">
                        <button type="button" class="btn btn-warning btn-pull-sync" data-id="${row.id}"
                            data-label="${escapeHtml(row.process_subject || row.process_no)}"
                            data-subject="${escapeHtml(row.process_subject || '')}"
                            data-description="${escapeHtml(row.process_description || '')}"
                            data-start="${row.process_start || ''}" data-end="${row.process_end || ''}" data-paid="${row.process_paid || ''}"
                            data-run-kind="${row.run_kind || 'regular'}"
                            data-tax-treatment="${row.attribution_tax_treatment || ''}"
                            data-matched-cycle-id="${row.matched_cycle_id || ''}"
                            data-matched-cycle-name="${escapeHtml(row.matched_cycle_name || '')}"
                            title="${langData['btn_pull_to_run'] || 'Pull to Run'}"><i class="fa-solid fa-arrow-right-to-bracket"></i></button>
                        ${mergeBtn}
                        <button type="button" class="btn btn-outline-info btn-view-sync" data-id="${row.id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>
                        <button type="button" class="btn btn-outline-danger btn-reject-sync" data-id="${row.id}" data-label="${escapeHtml(row.process_subject || row.process_no)}" title="${langData['btn_reject_sync'] || 'Reject'}"><i class="fa-solid fa-reply"></i></button>
                    </div>
                `;
                }
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
        ? `<span class="fw-semibold">${escapeHtml(item.matched_employee_no)}</span> <span class="text-muted">— ${escapeHtml((currentLang === 'th' ? `${item.matched_name_th} ${item.matched_surname_th}` : `${item.matched_name_en} ${item.matched_surname_en}`).trim())}</span>`
        : `<span class="text-muted">${escapeHtml(item.payroll_code)}</span>` + (item.emp_name ? ` <span class="text-muted">— ${escapeHtml(item.emp_name)}</span>` : '');
    const values = (item.item_values || [])
        .filter(v => Number(v.value) !== 0)
        .map(v => {
            const unitLabel = syncUnitLabelPr(v.unit_type);
            return `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(v.item_code)}: ${escapeHtml(v.value)}${unitLabel ? ` ${escapeHtml(unitLabel)}` : ''}</span>`;
        }).join('');
    const otBreakdown = [
        ['sync_ot_working_day', 'Working Day', item.ot_req_working_day_hrs],
        ['sync_ot_day_off', 'Day Off', item.ot_req_weekend_hrs],
        ['sync_ot_holiday', 'Holiday', item.ot_req_holiday_hrs],
    ]
        .filter(([, , hrs]) => Number(hrs || 0) !== 0)
        .map(([key, fallback, hrs]) => `${langData[key] || fallback} ${escapeHtml(hrs)}h`)
        .join(' · ') || (item.ot_mins ? `${escapeHtml(item.ot_mins)} ${langData['sync_unit_minutes'] || 'minute(s)'}` : '-');
    return `
        <div class="sync-emp-card${isMapped ? '' : ' sync-emp-card-unmapped'}">
            <div class="sync-emp-card-header">
                <div class="sync-emp-card-identity">
                    ${mappingStatusBadgePr(isMapped)}
                    <span class="sync-emp-card-name">${nameLine}</span>
                </div>
                <div class="sync-emp-card-dept">${escapeHtml(item.dept_description || '-')} <span class="text-muted">/ ${escapeHtml(item.position_name || '-')}</span></div>
            </div>
            <div class="sync-emp-stats">
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_working_days'] || 'Working Days'}</span><span class="sync-emp-stat-value">${escapeHtml(item.working_days ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_absent_days'] || 'Absent Days'}</span><span class="sync-emp-stat-value">${escapeHtml(item.absent_days ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_late_mins'] || 'Late (min)'}</span><span class="sync-emp-stat-value">${escapeHtml(item.late_mins ?? '-')}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_ot_breakdown'] || 'OT (hrs)'}</span><span class="sync-emp-stat-value">${otBreakdown}</span></div>
                <div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData['table_trip_allowance'] || 'Trip Allowance'}</span><span class="sync-emp-stat-value">${escapeHtml(item.trip_allowance ?? '-')}</span></div>
                ${syncEmpExtraStatsPr(item)}
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
// 2026-09-02, explicit request: "หน้าต่างตอนกดดูรายละเอียดของรอบที่ส่ง ช่วยปรับให้แสดงข้อมูลครบ" --
// PayrollSyncModel::getProcessDetail() already sends early_mins/leave_approve_days/leave_wait_days/
// leave_without_pay_days per item, but the card never rendered them at all. Shown only when the
// value is genuinely present and non-zero (same "don't clutter with nothing" filtering the
// item_values badges above already use), appended after Trip Allowance.
function syncEmpExtraStatsPr(item) {
    const stats = [
        ['table_early_mins', 'Early Leave (min)', item.early_mins],
        ['table_leave_approve_days', 'Leave Approved (days)', item.leave_approve_days],
        ['table_leave_wait_days', 'Leave Pending (days)', item.leave_wait_days],
        ['table_leave_without_pay_days', 'Unpaid Leave (days)', item.leave_without_pay_days],
    ].filter(([, , v]) => v !== null && v !== undefined && Number(v) !== 0);
    return stats.map(([key, fallback, v]) => `<div class="sync-emp-stat"><span class="sync-emp-stat-label">${langData[key] || fallback}</span><span class="sync-emp-stat-value">${escapeHtml(v)}</span></div>`).join('');
}
function renderIdCardCellPr(item) {
    if (!item.id_card_no_masked) {
        return `<span class="text-muted">-</span>`;
    }
    const expire = item.id_card_expire_date
        ? ` <span class="text-muted">(${langData['id_card_expire'] || 'ID Card Expire Date'}: ${toDisplayDatePr(item.id_card_expire_date)})</span>`
        : '';
    return `<span><i class="fa-solid fa-id-card text-muted me-1"></i>${escapeHtml(item.id_card_no_masked)}</span>${expire}`;
}
function renderPaymentSsoCellPr(item) {
    let payLine;
    if (item.pay_type === 'transfer') {
        const bankLabel = item.pay_bank_name ? escapeHtml(item.pay_bank_name) : (langData['sync_pay_transfer'] || 'Transfer');
        const maskedNo = item.pay_bank_no_masked ? ` (${escapeHtml(item.pay_bank_no_masked)})` : '';
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
            <td>${escapeHtml(row.payroll_code)}</td>
            <td>${escapeHtml(row.emp_name || '-')}</td>
            <td>${escapeHtml(row.dept_description || '-')}<br><span class="text-muted small">${escapeHtml(row.position_name || '-')}</span></td>
            <td>${row.emp_start_date ? toDisplayDatePr(row.emp_start_date) : '-'}</td>
            <td>${row.emp_resign_date ? toDisplayDatePr(row.emp_resign_date) : '-'}</td>
            <td class="text-center">${Number(row.is_new_hire) === 1 ? '<i class="fa-solid fa-circle-check text-success"></i>' : '<span class="text-muted">-</span>'}</td>
            <td class="text-center">${Number(row.is_resigned_this_period) === 1 ? '<i class="fa-solid fa-circle-check text-danger"></i>' : '<span class="text-muted">-</span>'}</td>
            <td>${escapeHtml(row.status_text || '-')}</td>
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
            ${syncSummaryFieldPr('fa-hashtag', 'table_process_no', 'Process No', escapeHtml(data.process_no))}
            ${syncSummaryFieldPr('fa-building', 'table_comp_name', 'Company', escapeHtml(data.origami_comp_name))}
            ${syncSummaryFieldPr('fa-calendar-days', 'table_period', 'Pay Period', escapeHtml(data.period_name || '-'))}
            <!-- 2026-09-02, reply from Origami's own team re: payroll schedule mapping -- shown here
                 (not just used silently by matchForSyncProcess()) so an admin can see/verify exactly
                 what code Origami sent, e.g. when troubleshooting why a document didn't auto-match a
                 Payroll Schedule. -->
            ${syncSummaryFieldPr('fa-key', 'table_external_cycle_code', 'External Cycle Code', data.external_cycle_code ? `<code>${escapeHtml(data.external_cycle_code)}</code>` : `<span class="text-muted">-</span>`)}
            ${syncSummaryFieldPr('fa-repeat', 'table_frequency', 'Frequency', escapeHtml(frequencyLabelPr(data.frequency_type)))}
            ${syncSummaryFieldPr('fa-users', 'table_employee_count', 'Employees', escapeHtml(data.item_count))}
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

// 2026-09-09, round-creation flow audit Phase 3 -- shared by resetRunForm(), setOffCycleMode(), and
// #run_purpose_choice_row's own click handler so every place that used to write directly to the old
// <select>'s .val() stays in sync with the new choice-card UI's checked/active state instead of just
// the hidden #run_purpose field alone. `value` may be '' (genuinely no card selected -- the hard-block
// state) or 'payroll'/'incentive'.
function syncRunPurposeChoiceUi(value) {
    $('#run_purpose').val(value).removeClass('is-invalid');
    $('#run_purpose_choice_error').addClass('d-none');
    $('#run_purpose_choice_row input[name="runPurposeChoice"]').prop('checked', false);
    $('#run_purpose_choice_row .run-choice-card').removeClass('active');
    if (value) {
        const $radio = $(`input[name="runPurposeChoice"][value="${value}"]`).prop('checked', true);
        $radio.closest('.run-choice-card').addClass('active');
    }
    // Always fires #run_purpose's own 'change' (updateComputeStatutoryVisibility()'s existing
    // binding) regardless of caller -- every one of the old direct `.val(...).trigger('change')`
    // call sites this function replaces relied on that same cascade running every time.
    $('#run_purpose').trigger('change');
}
$(document).on('change', 'input[name="runPurposeChoice"]', function () {
    syncRunPurposeChoiceUi($(this).val());
});
// 2026-09-09, round-creation flow audit Phase 3 -- same "new UI drives the old hidden radios"
// approach as syncRunPurposeChoiceUi() above, for the collapsed 3-way "fold into another round?"
// choice. setMergeChoiceMode()/setMergeTargetMode() (both unchanged) do all the actual show/hide/
// clear work once the legacy radios below are set -- this function's only job is picking WHICH of
// them to set and in what order (target sub-mode set silently first, so setMergeChoiceMode('reference')
// reads the already-correct sub-mode on its one and only cascade instead of reading a stale value and
// immediately re-correcting itself).
function syncRunMergeIntoUi(value) {
    $('#run_merge_into_row input[name="runMergeInto"]').prop('checked', false);
    $('#run_merge_into_row .run-subchoice-btn').removeClass('active');
    const $radio = $(`input[name="runMergeInto"][value="${value}"]`).prop('checked', true);
    $radio.closest('.run-subchoice-btn').addClass('active');
    if (value === 'standalone') {
        $('#run_merge_choice_new').prop('checked', true).trigger('change');
        return;
    }
    $(value === 'future_cycle' ? '#run_merge_target_mode_future_cycle' : '#run_merge_target_mode_existing').prop('checked', true);
    $('#run_merge_choice_reference').prop('checked', true).trigger('change');
}
$(document).on('change', 'input[name="runMergeInto"]', function () {
    syncRunMergeIntoUi($(this).val());
});
function resetRunForm() {
    $('#payrollRunForm')[0].reset();
    $('.is-invalid').removeClass('is-invalid');
    $('#run_cycle_id').val('').trigger('change');
    $('#run_sync_process_id').val('');
    $('#run_schedule_choice_cycle').prop('checked', true);
    $('#run_offcycle_row').removeClass('d-none');
    // 2026-09-09, same-day follow-up, explicit request: "ขอให้ checked default ครับ" -- 'payroll' is
    // the default again (was '' for one iteration -- see #run_purpose_choice_row's own comment in
    // modals.php).
    syncRunPurposeChoiceUi('payroll');
    $('#run_compute_statutory').prop('checked', true);
    $('#run_include_base_salary').prop('checked', false);
    $('#run_include_standing_items').prop('checked', false);
    $('#run_include_attendance_pay').prop('checked', false);
    // 2026-08-31, same-day follow-up (Origami `attribution` plan's item 3).
    $('#run_use_flat_tax_rate').prop('checked', false);
    $('#run_use_flat_tax_rate_row').addClass('d-none');
    // 2026-09-01: "เปิดรอบใหม่ / อ้างอิงถึงรอบ" radio -- see setMergeChoiceMode()'s own comment.
    // #run_merge_choice_row itself no longer needs its own d-none reset -- it lives inside
    // #run_offcycle_panel now, whose visibility setOffCycleMode(false) below already owns.
    $('#run_merge_choice_new').prop('checked', true);
    $('#run_merge_target_id').val('').trigger('change');
    // 2026-09-06: the new "existing round / future cycle period" sub-toggle -- see
    // setMergeTargetMode()'s own comment.
    $('#run_merge_target_mode_existing').prop('checked', true);
    $('#run_merge_target_cycle_id').val('').trigger('change');
    $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update');
    syncRunMergeIntoUi('standalone');
    setMergeChoiceMode('new');
    setMergeTargetMode('existing');
    setOffCycleMode(false);
}
// 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึง
// รอบ" -- shows/requires the target picker only when "reference" is chosen. #run_merge_choice_row
// itself is hidden entirely for a Pull-sync create (see .btn-pull-sync's own handler), same gate
// #run_offcycle_row already uses -- this function is simply never called with anything but 'new' on
// that flow, so it's a no-op there either way.
function setMergeChoiceMode(choice) {
    const isReference = choice === 'reference';
    $('#run_merge_target_row').toggleClass('d-none', !isReference);
    if (!isReference) {
        $('#run_merge_target_id').val('').trigger('change').removeClass('is-invalid');
        $('#run_merge_target_cycle_id').val('').trigger('change').removeClass('is-invalid');
        $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update').removeClass('is-invalid');
    }
    // required class on whichever picker the CURRENT sub-mode actually shows -- see
    // setMergeTargetMode() below, called right after so it always reflects the current isReference.
    setMergeTargetMode($('input[name="runMergeTargetMode"]:checked').val() || 'existing');
    // 2026-09-02, 2nd same-day follow-up: .active on the pill <label> itself -- see
    // .run-subchoice-btn in style.css (was .run-choice-card until this round's panel redesign).
    $('#run_merge_choice_row .run-subchoice-btn').removeClass('active');
    $(isReference ? '#run_merge_choice_reference' : '#run_merge_choice_new').closest('.run-subchoice-btn').addClass('active');
}
$(document).on('change', 'input[name="runMergeChoice"]', function () {
    setMergeChoiceMode($(this).val());
});
// 2026-09-06, explicit request: "ปรับ Process ที่มีการสร้างรอบเองในฝั่ง Payroll ให้เป็นไปในแนวทางเดียวกัน" --
// the "อ้างอิงถึงรอบ" (reference a round) choice's own 2nd-level sub-toggle: an existing round
// (unchanged #run_merge_target_id picker) vs. a FUTURE round of a recurring Payroll Cycle that
// hasn't been created yet (PayrollRunModel::resolveMergeTargetSpec()'s own docblock) -- only
// meaningful while #run_merge_target_row itself is showing (isReference true); a no-op call while
// it's hidden just leaves both wraps hidden, which is already the correct state either way.
function setMergeTargetMode(mode) {
    const isFutureCycle = mode === 'future_cycle';
    const targetRowShowing = !$('#run_merge_target_row').hasClass('d-none');
    $('#run_merge_target_existing_wrap').toggleClass('d-none', isFutureCycle);
    $('#run_merge_target_future_cycle_wrap').toggleClass('d-none', !isFutureCycle);
    $('#run_merge_target_id').toggleClass('required', targetRowShowing && !isFutureCycle);
    $('#run_merge_target_cycle_id').toggleClass('required', targetRowShowing && isFutureCycle);
    if (isFutureCycle) {
        $('#run_merge_target_id').val('').trigger('change').removeClass('is-invalid');
        // Covers reopening/reselecting this mode while a cycle/period were already picked earlier in
        // this same session -- a no-op (hides the box) if either field is still empty.
        refreshRunMergeTargetPreview();
    } else {
        $('#run_merge_target_cycle_id').val('').trigger('change').removeClass('is-invalid');
        $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update').removeClass('is-invalid');
        resetRunMergeTargetPreview();
    }
    $('#run_merge_target_mode_row .run-subchoice-btn').removeClass('active');
    $(isFutureCycle ? '#run_merge_target_mode_future_cycle' : '#run_merge_target_mode_existing').closest('.run-subchoice-btn').addClass('active');
}
$(document).on('change', 'input[name="runMergeTargetMode"]', function () {
    setMergeTargetMode($(this).val());
});
// Auto-suggests the target period the same way picking a cycle for the run's OWN period already
// does (applySuggestedPeriod()) -- reuses the exact same api/payroll-cycle.suggest-period endpoint,
// since "the next period of this cycle" is exactly the key a future round will be created with.
// 2026-09-09, real bug found and fixed (explicit report: "Date เลือกไม่ได้") -- these used to be
// `readonly` display-only fields (dd/mm/yyyy, same convention as every other date field on this
// form), auto-filled ONLY from picking a Target cycle above with no way to adjust by hand. Now real,
// editable .datepicker inputs (see initDatepicker() calls near this file's own #run_mark_paid_date
// init) -- collectRunFormData() still converts back to ISO on submit via toIsoDatePr() either way.
$(document).on('change', '#run_merge_target_cycle_id', function () {
    const cycleId = $(this).val();
    if (!cycleId) {
        $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update');
        resetRunMergeTargetPreview();
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-cycle.suggest-period`, method: 'GET', data: { id: cycleId }, dataType: 'json',
        success: function (res) {
            if (!res.status) { return; }
            $('#run_merge_target_period_start').val(toDisplayDatePr(res.period_start_date)).datepicker('update').removeClass('is-invalid');
            $('#run_merge_target_period_end').val(toDisplayDatePr(res.period_end_date)).datepicker('update').removeClass('is-invalid');
            refreshRunMergeTargetPreview();
        }
    });
});
// 2026-09-09, round-creation flow audit Bug 2 fix (explicit report: resolveMergeTargetSpec() picks
// silently among 2+ existing candidate runs -- same cycle, payment_date in the same month -- with no
// visible indication of which one, e.g. a semi-monthly cycle whose 15th AND 30th runs both already
// exist for the target month). This block adds a live, read-only preview of that same lookup so the
// admin sees (and, when ambiguous, explicitly picks) the actual target BEFORE clicking Save, instead
// of finding out afterward by opening the new run's own Detail page. `runMergeTargetPreviewMatches`/
// `runMergeTargetPreviewKey` cache the last fetch so re-triggering this on every keystroke isn't
// needed AND so the submit handler below can detect staleness (cycle/period changed since the last
// fetch) and re-check rather than trusting a possibly-outdated cached result.
let runMergeTargetPreviewMatches = null;
let runMergeTargetPreviewKey = null;
function resetRunMergeTargetPreview() {
    runMergeTargetPreviewMatches = null;
    runMergeTargetPreviewKey = null;
    $('#run_merge_target_preview_box').addClass('d-none');
    $('#run_merge_target_preview_none, #run_merge_target_preview_single, #run_merge_target_preview_multi').addClass('d-none');
    $('#run_merge_target_preview_select').empty().removeClass('is-invalid');
}
function runMergeTargetPreviewLabel(m) {
    const dateStr = typeof formatDisplayDate === 'function' ? formatDisplayDate(m.payment_date) : m.payment_date;
    return `${m.run_name} (${dateStr})`;
}
function renderRunMergeTargetPreview(matches) {
    $('#run_merge_target_preview_box').removeClass('d-none');
    $('#run_merge_target_preview_none, #run_merge_target_preview_single, #run_merge_target_preview_multi').addClass('d-none');
    if (matches.length === 0) {
        $('#run_merge_target_preview_none').removeClass('d-none').text(langData['run_merge_target_preview_none'] || 'No matching round yet -- this will wait until one is created.');
    } else if (matches.length === 1) {
        const tpl = langData['run_merge_target_preview_single'] || 'This will merge into: {name}';
        $('#run_merge_target_preview_single').removeClass('d-none').text(tpl.replace('{name}', runMergeTargetPreviewLabel(matches[0])));
    } else {
        const $sel = $('#run_merge_target_preview_select').empty().removeClass('is-invalid');
        $sel.append(new Option(langData['select_option'] || '-- Select --', ''));
        matches.forEach(m => $sel.append(new Option(runMergeTargetPreviewLabel(m), m.id)));
        $('#run_merge_target_preview_multi').removeClass('d-none');
    }
}
// Live preview only -- purely informational, never blocks anything itself (the submit-time
// re-check in resolveRunMergeTargetBeforeSubmit() below is the one that actually gates Save). A
// failed lookup here just leaves the box in whatever state it was already in; the submit-time
// re-check has its own independent error handling.
function refreshRunMergeTargetPreview() {
    const cycleId = $('#run_merge_target_cycle_id').val();
    const periodStart = toIsoDatePr($('#run_merge_target_period_start').val());
    if (!cycleId || !periodStart) {
        resetRunMergeTargetPreview();
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.preview-merge-target`, method: 'GET',
        data: { cycle_id: cycleId, period_start_date: periodStart }, dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            runMergeTargetPreviewMatches = res.matches || [];
            runMergeTargetPreviewKey = cycleId + '|' + periodStart;
            renderRunMergeTargetPreview(runMergeTargetPreviewMatches);
        }
    });
}
$(document).on('changeDate', '#run_merge_target_period_start, #run_merge_target_period_end', function () {
    refreshRunMergeTargetPreview();
});
// Gate before the actual save AJAX call (called from the form's own submit handler below) --
// re-checks the SAME lookup fresh whenever the cached preview doesn't match the current cycle/period
// values (covers a stale cache from an earlier pick), then decides what collectRunFormData()'s own
// merge_target_* fields should actually become: 0 matches keeps the future_cycle spec as-is (still
// genuinely waiting), exactly 1 match resolves it to that run's id (same outcome the backend would
// reach silently on its own -- just made explicit here), 2+ matches REQUIRES the admin to have picked
// one via #run_merge_target_preview_select (blocks Save with a warning if not, rather than silently
// defaulting to the earliest period the old behavior did). `callback(ok, overrides)` -- `overrides`
// (when present) get merged onto collectRunFormData()'s own payload, replacing its
// merge_target_cycle_id/period fields with a resolved merge_target_run_id instead.
function resolveRunMergeTargetBeforeSubmit(callback) {
    const isReferenceMode = !$('#run_merge_target_row').hasClass('d-none');
    const targetMode = $('input[name="runMergeTargetMode"]:checked').val() || 'existing';
    if (!isReferenceMode || targetMode !== 'future_cycle') {
        callback(true);
        return;
    }
    const cycleId = $('#run_merge_target_cycle_id').val();
    const periodStart = toIsoDatePr($('#run_merge_target_period_start').val());
    if (!cycleId || !periodStart) {
        // Required-field validation (validateRunForm()) already catches this before this function
        // is ever reached -- guarded here too so this function is safe to call standalone.
        callback(true);
        return;
    }
    const key = cycleId + '|' + periodStart;
    function decide(matches) {
        if (matches.length === 0) {
            callback(true);
        } else if (matches.length === 1) {
            callback(true, {
                merge_target_run_id: matches[0].id,
                merge_target_cycle_id: null, merge_target_period_start_date: null, merge_target_period_end_date: null,
            });
        } else {
            const chosen = $('#run_merge_target_preview_select').val();
            if (!chosen) {
                $('#run_merge_target_preview_select').addClass('is-invalid');
                showWarning(langData['run_merge_target_preview_pick_required'] || 'More than one existing round matches -- please pick which one before saving.');
                callback(false);
                return;
            }
            callback(true, {
                merge_target_run_id: parseInt(chosen, 10),
                merge_target_cycle_id: null, merge_target_period_start_date: null, merge_target_period_end_date: null,
            });
        }
    }
    if (runMergeTargetPreviewKey === key && runMergeTargetPreviewMatches !== null) {
        decide(runMergeTargetPreviewMatches);
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.preview-merge-target`, method: 'GET',
        data: { cycle_id: cycleId, period_start_date: periodStart }, dataType: 'json',
        success: function (res) {
            const matches = (res.status && res.matches) ? res.matches : [];
            runMergeTargetPreviewMatches = matches;
            runMergeTargetPreviewKey = key;
            renderRunMergeTargetPreview(matches);
            decide(matches);
        },
        // Network/preview failure -- fail OPEN, not closed: let the save proceed with the
        // future_cycle spec as-is. resolveMergeTargetSpec() itself still resolves this correctly
        // server-side (its own single-pick fallback, unchanged) -- this preview is a UX improvement
        // on top of that, not a replacement for it, so a lookup failure here must never block a
        // save that would otherwise succeed.
        error: function () { callback(true); }
    });
}
// Off-cycle runs (e.g. an out-of-cycle payment) skip the Payroll Cycle field entirely -- per
// explicit request. Only offered on the standalone "Add" flow; Pull-to-run hides the toggle
// entirely (that data is inherently cycle-based) via #run_offcycle_row.addClass('d-none').
// 2026-09-02, same-day follow-up: was a single checkbox (unchecked = off-cycle) -- "ให้ติ๊กออกแล้วค่อยให้
// เลือกรอบ...ดูงงๆ" (unchecking something to REVEAL a field reads backwards) -- now a 2-option radio
// (#run_schedule_choice_cycle/#run_schedule_choice_offcycle), this function's own bool param
// unchanged so every existing caller (resetRunForm(), the change handler below) needed no rework.
function setOffCycleMode(isOffCycle) {
    $('#run_cycle_row').toggleClass('d-none', isOffCycle);
    $('#run_cycle_id').toggleClass('required', !isOffCycle);
    if (isOffCycle) {
        $('#run_cycle_id').val('').trigger('change');
        $('#run_cycle_id').removeClass('is-invalid');
    }
    $('#run_offcycle_row .run-choice-card').removeClass('active');
    $(isOffCycle ? '#run_schedule_choice_offcycle' : '#run_schedule_choice_cycle').closest('.run-choice-card').addClass('active');
    // 2026-09-02, 2nd same-day follow-up: #run_offcycle_panel (merge choice + its target picker)
    // only ever makes sense together with off-schedule -- PayrollRunModel::create() itself refuses
    // a merge target the instant cycle_id is set, so this ALSO closes a real gap where the merge
    // choice used to stay reachable (and its selection submittable) even with a cycle picked,
    // guaranteeing a backend rejection on save. Forced back to "new"/no-target the moment off-cycle
    // is turned off, same reasoning updateEditRunTypeSectionRd()'s own equivalent reset already
    // uses on the Edit form.
    $('#run_offcycle_panel').toggleClass('d-none', !isOffCycle);
    if (!isOffCycle) {
        // syncRunMergeIntoUi() itself fires runMergeChoice's own change -> setMergeChoiceMode('new'),
        // so no separate explicit call is needed here anymore.
        syncRunMergeIntoUi('standalone');
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
    $('#run_purpose_choice_row').toggleClass('d-none', !isOffCycle);
    // .required only while genuinely shown -- same pattern #run_merge_target_id/_cycle_id already
    // use (see setMergeTargetMode()) -- validateRunForm()'s generic loop would otherwise block Save
    // over a hidden, irrelevant field.
    $('#run_purpose').toggleClass('required', isOffCycle);
    if (!isOffCycle) {
        // 2026-09-09, same-day follow-up, explicit request: "ขอให้ checked default ครับ" -- reset back
        // to the 'payroll' default (was '' for one iteration), same as resetRunForm()'s own comment.
        syncRunPurposeChoiceUi('payroll');
    }
}
// Compute Statutory/Include Base Salary/Include Standing Items only matter (and only show) once
// Incentive/Other Payment is actually selected -- a normal Payroll run always includes all three,
// no choice to offer.
function updateComputeStatutoryVisibility() {
    const isIncentive = $('#run_purpose').val() === 'incentive';
    $('#run_compute_statutory_row, #run_include_base_salary_row, #run_include_standing_items_row, #run_include_attendance_pay_row').toggleClass('d-none', !isIncentive);
    // #run_use_flat_tax_rate_row is only ever SHOWN by setSupplementalPullMode() (needs
    // taxTreatment==='separate', not just isIncentive) -- but it must still hide the moment the
    // admin flips run_purpose back to 'payroll' on this same open modal, same as the other
    // incentive-only rows above.
    if (!isIncentive) {
        $('#run_use_flat_tax_rate_row').addClass('d-none');
        $('#run_use_flat_tax_rate').prop('checked', false);
    }
}
// Auto-fills Period Start/End/Payment Date from the selected cycle's own configured cutoff/
// payment day settings, per explicit request -- pure convenience default, every field stays
// editable afterward. Silently does nothing on failure (cycle not fully configured, network
// error, etc.) so manual entry always still works as a fallback.
// 2026-09-09, explicit request: "เปลี่ยนเป็นเลือกรอบแล้ว ถ้าวันที่มีข้อมูลอยู่ไม่ต้องเปลี่ยนค่า ถ้าไม่มีค่าให้ใส่
// อัตโนมัติ" -- was an unconditional overwrite of all 3 date fields on every cycle change, even when
// they already held real values (e.g. a value the admin already typed/adjusted by hand before
// picking a cycle). Now only fills a field that's genuinely EMPTY; a field that already has a value
// is left completely untouched, matching detail.js's own applySuggestedPeriodRd() -- which already
// had a version of this same "don't clobber real data" reasoning for the Edit form (see that
// function's own docblock), just not this exact per-field empty-check yet.
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
            // .datepicker('update') after each programmatic .val() -- see CLAUDE.md's
            // bootstrap-datepicker note (widget state goes stale otherwise, blanking the field on
            // next click-away).
            if (!$('#run_period_start').val()) {
                $('#run_period_start').val(toDisplayDatePr(res.period_start_date)).datepicker('update');
            }
            if (!$('#run_period_end').val()) {
                $('#run_period_end').val(toDisplayDatePr(res.period_end_date)).datepicker('update');
            }
            if (!$('#run_payment_date').val()) {
                $('#run_payment_date').val(toDisplayDatePr(res.payment_date)).datepicker('update');
            }
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
    // 2026-09-09, round-creation flow audit Phase 3: #run_purpose is a hidden input now (driven by
    // #run_purpose_choice_row's cards) -- .is-invalid on a hidden element has no visible red border
    // of its own, so mirror it onto a small text hint next to the cards instead.
    $('#run_purpose_choice_error').toggleClass('d-none', !$('#run_purpose').hasClass('is-invalid'));
    return firstInvalid;
}
function collectRunFormData() {
    const isOffCycle = $('input[name="runScheduleChoice"]:checked').val() === 'offcycle';
    const isReferenceMode = !$('#run_merge_target_row').hasClass('d-none');
    const targetMode = $('input[name="runMergeTargetMode"]:checked').val() || 'existing';
    // 2026-08-29: run_purpose used to be forced to 'payroll' whenever the manual off-cycle
    // checkbox wasn't ticked -- but setSupplementalPullMode() now also shows #run_purpose_choice_row
    // (Payroll/Incentive-Other-Payment) for a supplemental sync pull, which never touches that
    // checkbox at all. Read the select whenever its row is actually visible, not just for the
    // manual off-cycle path, so a chosen "Incentive/Other Payment" on a supplemental pull is
    // actually submitted instead of silently reverting to 'payroll'.
    const purposeSelectable = isOffCycle || !$('#run_purpose_choice_row').hasClass('d-none');
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
        include_attendance_pay: runPurpose === 'incentive' && $('#run_include_attendance_pay').is(':checked') ? 1 : 0,
        // 2026-08-31, same-day follow-up (Origami `attribution` plan's item 3) -- only ever
        // meaningful/visible for a supplemental pull attributed tax_treatment='separate', but the
        // backend itself also forces this to 0 for any non-incentive run (create()/update()'s own
        // comments), so reading it unconditionally here is safe either way.
        use_flat_tax_rate: runPurpose === 'incentive' && $('#run_use_flat_tax_rate').is(':checked') ? 1 : 0,
        run_name: $('#run_name').val().trim(),
        period_start_date: toIsoDatePr($('#run_period_start').val()),
        period_end_date: toIsoDatePr($('#run_period_end').val()),
        payment_date: toIsoDatePr($('#run_payment_date').val()),
        notes: $('#run_notes').val().trim(),
        sync_process_id: $('#run_sync_process_id').val() || null,
        // 2026-09-01, explicit request: "เปิดรอบใหม่ / อ้างอิงถึงรอบ" radio -- only sent when the row is
        // actually showing (mirrors the run-type-fields pattern right above: honest about what's
        // actually visible/being set, same as this whole form's other conditional fields).
        //
        // 2026-09-06: both merge_target_run_id AND merge_target_cycle_id are ALWAYS sent together
        // (one truthy, the other explicitly null depending on runMergeTargetMode) -- never omit
        // either one here. PayrollRunModel::resolveMergeTargetSpec() resolves each key independently
        // and refuses if both end up non-null, so sending only one while silently omitting the
        // other would be ambiguous for an edit later (this function itself is create()-only, so
        // there's no "current state" to preserve, but detail.js's own collector for update() follows
        // the exact same always-send-both rule for that same reason).
        merge_target_run_id: (isReferenceMode && targetMode !== 'future_cycle') ? ($('#run_merge_target_id').val() || null) : null,
        merge_target_cycle_id: (isReferenceMode && targetMode === 'future_cycle') ? ($('#run_merge_target_cycle_id').val() || null) : null,
        merge_target_period_start_date: (isReferenceMode && targetMode === 'future_cycle') ? toIsoDatePr($('#run_merge_target_period_start').val()) : null,
        merge_target_period_end_date: (isReferenceMode && targetMode === 'future_cycle') ? toIsoDatePr($('#run_merge_target_period_end').val()) : null,
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
    // 2026-09-01: widened to cover the 3 new filters too, not just the date range -- one shared
    // "Clear Filter" button/visibility rule for the whole station-filter box, same convention this
    // app's own .station-filter component uses elsewhere (e.g. Employee List). Origin/Run Purpose
    // are select2-static with a literal "all" option as their own neutral/no-op value (same
    // "all" convention #employee_filter_payroll_participant already established) -- excluded here
    // the same way, or the button would show permanently from page load.
    const origin = $('#filter_run_origin').val();
    const purpose = $('#filter_run_purpose').val();
    const hasFilter = !!($('#filter_date_from').val() || $('#filter_date_to').val()
        || (origin && origin !== 'all') || $('#filter_run_cycle').val() || (purpose && purpose !== 'all'));
    $('#dateFilterClearRow').toggleClass('d-none', !hasFilter);
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
    // 2026-09-01: clears the 3 new filters too -- 'all' is Origin/Run Purpose's own neutral value
    // (see updateClearFilterVisibility()'s own comment), an empty selection is Payroll Schedule's.
    // .trigger('change') fires the '#filter_run_origin, #filter_run_cycle, #filter_run_purpose'
    // handler above, which redraws the table -- no separate reload call needed here either.
    $('#filter_run_origin').val('all').trigger('change');
    $('#filter_run_cycle').val(null).trigger('change');
    $('#filter_run_purpose').val('all').trigger('change');
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
// NOT touch #run_cycle_row's own visibility or the runScheduleChoice radio's checked state -- a supplemental
// sync pull is still sync-linked (sync_process_id set), never truly "off-cycle" the way the
// standalone Add-flow toggle means it; the cycle field just stops being mandatory.
// taxTreatment ('merge'/'separate'/'') -- 2026-08-31 same-day follow-up (Origami `attribution`
// plan's item 3): only a 'separate'-attributed supplemental process ever shows the flat-tax-rate
// opt-in row at all (a 'merge'-attributed one doesn't withhold on its own -- its items get folded
// into the target run's own tax calc instead; a plain unattributed supplemental pull has no
// Origami-side hint either way, so it stays hidden and the admin can still tick
// #run_compute_statutory for the normal average/actual calculation same as before). Pre-checked
// (not just shown) when Origami explicitly said "separate", per the same "use what Origami already
// sent instead of re-entering by hand" precedent #run_period_start/.../#run_payment_date already
// established -- still fully editable/un-tickable afterward.
function setSupplementalPullMode(isSupplemental, taxTreatment) {
    $('#run_cycle_id').toggleClass('required', !isSupplemental);
    $('#run_purpose_choice_row').toggleClass('d-none', !isSupplemental);
    $('#run_purpose').toggleClass('required', isSupplemental);
    if (!isSupplemental) {
        syncRunPurposeChoiceUi('payroll');
    } else {
        // 2026-09-09, real bug found and fixed (explicit report: "ทาง origami ส่งค่าเที่ยวมา...ใน select
        // ยังขึ้นเงินเดือนปกติอยู่") -- confirmed against the real dev DB, not guessed: Origami's own
        // trip-fee batches (TDI-2026-00016/00018, "ค่าเที่ยว...") already arrive with the correct
        // `run_kind: "supplemental"` per PAYROLL_SYNC_API.md's own confirmed spec -- nothing missing
        // on Origami's side. The row becoming visible here never actually SELECTED anything for the
        // admin though -- resetRunForm()'s native `.reset()` call just above (in .btn-pull-sync's own
        // handler) leaves #run_purpose sitting on its first/default <option> ("payroll"/"Normal
        // Payroll"), and this function only ever forced it back to 'payroll' for the NON-supplemental
        // branch, never forced anything for this one. A supplemental run is *by definition* never
        // "regular payroll" (that's exactly what run_kind='regular' already means) -- pre-select
        // 'incentive' here instead, still fully editable afterward same as every other Pull-derived
        // field on this form (period dates, flat-tax-rate checkbox below).
        // 2026-09-09, round-creation flow audit Phase 3: uses syncRunPurposeChoiceUi() (not a bare
        // .val().trigger('change')) so the visible choice-card actually shows as selected too -- this
        // is real, known data from Origami's own run_kind, not an unexamined default, so pre-selecting
        // here does NOT reintroduce the "quiet trap" the standalone Add flow's own hard-block exists
        // to prevent (see #run_purpose_choice_row's own comment in modals.php).
        syncRunPurposeChoiceUi('incentive');
    }
    const showFlatTax = isSupplemental && taxTreatment === 'separate';
    $('#run_use_flat_tax_rate_row').toggleClass('d-none', !showFlatTax);
    $('#run_use_flat_tax_rate').prop('checked', showFlatTax);
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
    // 2026-09-02, explicit request (same message): "ตอนกดก็อยากให้เลือกได้เหมือนกันว่าเปิดรอบใหม่ หรืออ้างอิงถึง
    // รอบไหน" -- investigated enabling this for Pull too, found a real backend conflict, and kept it
    // hidden here rather than ship a UI path that always fails on save: PayrollRunModel::create()
    // rejects merge_target_run_id outright whenever sync_process_id is set (line ~1342, "A merge
    // target only applies to a genuine off-schedule run"), and mergeIntoExistingRun() itself refuses
    // any sync-linked source too. Widening BOTH would mean building a second, competing "merge intent"
    // signal on top of the one that already exists for sync pulls specifically -- Origami's own
    // attribution_target_origami_process_id/tax_treatment='merge' (surfaced as the separate
    // .btn-merge-sync button on a supplemental row right in this same Pending Pull table). Flagged to
    // the user rather than silently deciding either way -- see the chat reply for the actual question.
    // (#run_merge_choice_row's own parent, #run_offcycle_panel, is already hidden by resetRunForm()'s
    // setOffCycleMode(false) call above -- nothing further to hide here since this round's panel
    // redesign.)
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
    // .datepicker('update') after each programmatic .val() -- see CLAUDE.md's bootstrap-datepicker
    // note (widget state goes stale otherwise, blanking the field on next click-away).
    if (start && end) {
        $('#run_period_start').val(toDisplayDatePr(start)).datepicker('update');
        $('#run_period_end').val(toDisplayDatePr(end)).datepicker('update');
        $('#run_period_start, #run_period_end').removeClass('is-invalid');
    }
    if (paid) {
        $('#run_payment_date').val(toDisplayDatePr(paid)).datepicker('update');
        $('#run_payment_date').removeClass('is-invalid');
    } else if (end) {
        $('#run_payment_date').val(toDisplayDatePr(end)).datepicker('update');
    }

    // 2026-09-01, same-day follow-up, explicit request: "ข้อมูลรอบ และวันที่ ต่างๆ ถูกส่งมาอยู่แล้ว อยากให้กดแล้ว
    // Default ค่าที่ส่งมา Origami เลยโดยที่ไม่ต้องเลือกใหม่...ถ้า Map ได้ ถ้า Map ไม่ได้ก็ไม่ต้อง Default เลือก" --
    // matched-cycle-id/name (PayrollSyncModel::pendingList()'s own new best-effort match, see
    // PayrollCycleModel::matchForSyncProcess()'s docblock for the heuristic and its real limits)
    // pre-selects the Payroll Schedule dropdown too, when confident. .trigger('change.select2') only
    // (NOT plain 'change') -- updates the select2 widget's own display without firing
    // applySuggestedPeriod()'s handler below, which would otherwise silently overwrite the more
    // precise period/payment dates Origami itself already sent (just above) with a generically-
    // computed "suggested" period instead. Left blank (no auto-select) whenever nothing confidently
    // matched -- the admin picks manually, same as before this feature existed.
    const matchedCycleId = ($btn.data('matched-cycle-id') || '').toString();
    if (matchedCycleId) {
        const matchedCycleName = ($btn.data('matched-cycle-name') || '').toString();
        $('#run_cycle_id').empty().append(new Option(matchedCycleName || ('#' + matchedCycleId), matchedCycleId, true, true)).trigger('change.select2');
    }

    setSupplementalPullMode(runKind === 'supplemental', ($btn.data('tax-treatment') || '').toString());
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
$(document).on('change', 'input[name="runScheduleChoice"]', function () {
    setOffCycleMode($(this).val() === 'offcycle');
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
                $('#pendingSyncViewBody').html(`<div class="text-danger">${escapeHtml(res.message || 'Error')}</div>`);
            }
        })
        .fail(function () {
            $('#pendingSyncViewBody').html(`<div class="text-danger">${langData['save_failed'] || 'An error occurred while saving the data.'}</div>`);
        });
});
// 2026-08-31, explicit request: "เพิ่มให้สามารถตีกลับเอกสารที่ยังไม่ดึงมาทำรอบได้ โดยที่ต้องใส่ Comment เข้าไป
// ด้วยครับ" -- SweetAlert2's own built-in `input: 'textarea'` (a required comment, enforced via
// inputValidator, not a separate custom form) -- see PayrollSyncModel::rejectProcess()'s own
// docblock for the backend contract (also pushes the rejection to Origami, best-effort).
$(document).on('click', '.btn-reject-sync', function () {
    const id = $(this).data('id');
    const label = $(this).data('label');
    Swal.fire({
        icon: 'warning',
        title: (langData['confirm_reject_sync_title'] || 'Reject "{label}"?').replace('{label}', label),
        input: 'textarea',
        inputPlaceholder: langData['reject_sync_comment_placeholder'] || 'Reason for rejecting this document...',
        inputValidator: (value) => {
            if (!value || !value.trim()) {
                return langData['reject_sync_comment_required'] || 'A comment is required.';
            }
        },
        showCancelButton: true,
        confirmButtonText: langData['btn_reject_sync'] || 'Reject',
        cancelButtonText: langData['cancel'] || 'Cancel',
        confirmButtonColor: '#dc3545',
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $.ajax({
            url: `${BASE_URL}/api/payroll-sync.reject`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ id, comment: result.value }), dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                showSuccess(langData['save_success'] || 'Saved successfully.');
                if (tb_pending_sync) tb_pending_sync.ajax.reload(null, false);
                loadPendingSyncCount();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
        });
    });
});

// 2026-08-31, same-day follow-up -- see #blockedSyncUpdatesCard's own comment in index.php.
// Apply is deliberately a warning-tone confirm (not just an info toast) -- it changes the linked
// run's own SOURCE data, and the run itself still needs a separate, explicit Recalculate afterward
// (this action never recalculates automatically, see PayrollSyncModel::applyBlockedUpdate()'s own
// docblock).
$(document).on('click', '.btn-apply-blocked-update', function () {
    const id = $(this).data('id');
    Swal.fire({
        icon: 'warning',
        title: langData['confirm_apply_blocked_update_title'] || 'Apply this update?',
        text: langData['confirm_apply_blocked_update_message'] || 'This will overwrite the sync data for the linked run with what Origami sent. The run itself will NOT be recalculated automatically -- do that separately afterward.',
        showCancelButton: true,
        confirmButtonText: langData['btn_apply_update'] || 'Apply',
        cancelButtonText: langData['cancel'] || 'Cancel',
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $.ajax({
            url: `${BASE_URL}/api/payroll-sync.blocked-update-apply`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ id }), dataType: 'json',
            success: function (res) {
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                showSuccess(langData['save_success'] || 'Saved successfully.');
                loadBlockedSyncUpdates();
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
        });
    });
});
$(document).on('click', '.btn-dismiss-blocked-update', function () {
    const id = $(this).data('id');
    showConfirm(
        langData['confirm_dismiss_blocked_update_title'] || 'Dismiss this update?',
        langData['confirm_dismiss_blocked_update_message'] || 'The attempted update will be discarded without being applied. The linked run keeps its current data unchanged.',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-sync.blocked-update-dismiss`, method: 'POST', contentType: 'application/json',
                data: JSON.stringify({ id }), dataType: 'json',
                success: function (res) {
                    if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    loadBlockedSyncUpdates();
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
            });
        }
    );
});
// 2026-08-31, PAYROLL_SYNC_API.md `attribution` revision -- "Merge into Target" action, see
// PayrollRunModel::mergeSupplementalIntoRun()'s own docblock for the mechanism. Reports
// merged_line_count/skipped_employee_ids from the result so an admin sees exactly what happened,
// not just a bare success message.
function requestMergeSupplemental(id, allowRevert, allowReopen, onDone) {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.merge-supplemental`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ sync_process_id: id, allow_revert_non_draft_target: !!allowRevert, allow_reopen_paid_target: !!allowReopen }), dataType: 'json',
        success: function (res) { onDone(res); },
        error: function () { onDone({ status: false, message: langData['save_failed'] || 'An error occurred while saving.' }); }
    });
}
function handleMergeSyncResult(id, label, target, res) {
    if (res.status) {
        let msg = (langData['merge_sync_success'] || 'Merged {count} line(s) into the target run.').replace('{count}', res.merged_line_count || 0);
        if ((res.skipped_employee_ids || []).length > 0) {
            msg += ' ' + (langData['merge_sync_skipped_note'] || '{count} employee(s) were skipped (not part of the target run).').replace('{count}', res.skipped_employee_ids.length);
        }
        showSuccess(msg);
        if (tb_pending_sync) tb_pending_sync.ajax.reload(null, false);
        loadPendingSyncCount();
        return;
    }
    // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย") -- the target run isn't draft yet; offer a
    // SECOND, more serious confirmation naming exactly what will be undone before retrying with the
    // opt-in flag. Never auto-retries silently -- reverting someone else's approval decision always
    // needs its own explicit click.
    if (res.needs_revert_confirmation) {
        const title = langData['confirm_revert_merge_title'] || 'This Will Undo an Existing Decision';
        const message = (langData['confirm_revert_merge_message'] || 'The target run "{target}" is already {state}. Merging will REVERT that decision back to draft, requiring a fresh submit and approval. Continue?')
            .replace('{target}', target).replace('{state}', res.target_state || '');
        showConfirm(title, message, function () {
            requestMergeSupplemental(id, true, false, function (retryRes) { handleMergeSyncResult(id, label, target, retryRes); });
        });
        return;
    }
    // Item 2 of the same follow-up: a paid/locked target needs its OWN, even more serious
    // confirmation (money may have already moved) before reopening -- a genuinely different risk
    // level than an undecided/decided-but-unpaid target above, so a separate dialog/wording, never
    // silently folded into the same confirm as needs_revert_confirmation.
    if (res.needs_reopen_confirmation) {
        const title = langData['confirm_reopen_merge_title'] || 'This Will Reopen an Already-Paid Run';
        const message = (langData['confirm_reopen_merge_message'] || 'The target run "{target}" is already {state} -- money may have already moved. Merging will REOPEN it back to draft (clearing its paid/locked/approval status), requiring a fresh recalculate, submit, approve, and pay cycle. This is a higher-risk action -- continue?')
            .replace('{target}', target).replace('{state}', res.target_state || '');
        showConfirm(title, message, function () {
            requestMergeSupplemental(id, false, true, function (retryRes) { handleMergeSyncResult(id, label, target, retryRes); });
        });
        return;
    }
    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
}
$(document).on('click', '.btn-merge-sync', function () {
    const id = $(this).data('id');
    const label = $(this).data('label');
    const target = $(this).data('target');
    const title = langData['confirm_merge_sync_title'] || 'Merge into Target Cycle?';
    const message = (langData['confirm_merge_sync_message'] || 'Merge "{label}" into "{target}"? This will add its amounts to that run\'s own gross pay before withholding.').replace('{label}', label).replace('{target}', target);
    showConfirm(title, message, function () {
        requestMergeSupplemental(id, false, false, function (res) { handleMergeSyncResult(id, label, target, res); });
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
// 2026-08-31, explicit request: "ปุ่ม Export กระทบยอดรายการตามงวด ตัดออกได้เลยครับ และปุ่ม Export งวด
// ทังหมดตัดออก ให้ Export รายงวดเท่านั้น" -- the whole-list summary export (.btn-export-run-list,
// PAYROLL_RUN_LIST_SUMMARY) and the occurrence-reconciliation export
// (#btnExportOccurrenceReconciliation, SCHEDULED_ITEM_OCCURRENCE_RECONCILIATION) are both removed
// from this page -- only the per-row export just below (one specific run/period at a time) stays.
// Neither report itself was deleted (still registered, still reachable via their own tests/API),
// just these 2 trigger buttons.
$(document).on('click', '.btn-export-run-register', function () {
    const params = new URLSearchParams();
    params.set('report_code', 'PAYROLL_REGISTER');
    params.set('format', 'excel');
    params.set('run_id', $(this).data('id'));
    params.set('source', 'payroll_process_list_row');
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});
// 2026-09-02, explicit request: "เพิ่มให้ Export เป็น PDF ได้ด้วย และรองรับ 2 ภาษา...การ Export กดแล้ว
// แสดงตัวอย่าง แล้วค่อยเลือกจะ Download ภาษาไทยหรือภาษาอังกฤษ" -- additive next to the Excel button
// above (unchanged). #runRegisterPdfPreviewRunId remembers which run this modal is currently open
// for, read by the Thai/English download buttons below.
let runRegisterPdfPreviewRunId = null;
$(document).on('click', '.btn-preview-run-register-pdf', function () {
    runRegisterPdfPreviewRunId = $(this).data('id');
    const $frame = $('#runRegisterPdfPreviewFrame').off('load').addClass('d-none').attr('src', '');
    const $loading = $('#runRegisterPdfPreviewLoading').removeClass('d-none');
    new bootstrap.Modal(document.getElementById('runRegisterPdfPreviewModal')).show();
    const params = new URLSearchParams();
    params.set('report_code', 'PAYROLL_REGISTER');
    params.set('format', 'pdf');
    params.set('run_id', runRegisterPdfPreviewRunId);
    params.set('language', currentLang === 'en' ? 'en' : 'th');
    params.set('preview', '1');
    $frame.on('load', function () {
        $loading.addClass('d-none');
        $frame.removeClass('d-none');
    });
    $frame.attr('src', `${BASE_URL}/api/report.generate?${params.toString()}`);
});
$(document).on('click', '.btn-run-register-pdf-download', function () {
    if (!runRegisterPdfPreviewRunId) return;
    const params = new URLSearchParams();
    params.set('report_code', 'PAYROLL_REGISTER');
    params.set('format', 'pdf');
    params.set('run_id', runRegisterPdfPreviewRunId);
    params.set('language', $(this).data('language') || 'th');
    params.set('source', 'payroll_process_list_row');
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});
// 2026-08-31, explicit request: "สามารถ Verify ทั้ง Process ได้เลย...ให้ Verify ได้ทั้ง Process ทั้ง Detail
// และหน้า List" -- List page's own entry point for the same api/payroll-run.employee-verify.all
// endpoint the Detail page's #btnVerifyAllEmployees button calls, so a whole run can be verified
// without opening it first.
$(document).on('click', '.btn-verify-all-run', function () {
    const id = $(this).data('id');
    showConfirm(langData['confirm_verify_all_title'] || 'Verify all employees in this run?',
        langData['confirm_verify_all_message'] || 'Every employee in this run will no longer be recalculated and cannot be edited until unverified.',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-run.employee-verify.all`, method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                        if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                    }
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
            });
        });
});
$(document).on('click', '#btnBulkPull', function () {
    const ids = Object.keys(selectedPendingSync);
    if (ids.length === 0) return;
    const $rows = $('#bulkPullRows').empty();
    ids.forEach(function (id) {
        const row = selectedPendingSync[id];
        $rows.append(`
            <div class="border rounded p-3 mb-3 bulk-pull-row" data-process-id="${id}">
                <div class="fw-bold mb-2">${escapeHtml(row.process_no)} <span class="text-muted small">(${escapeHtml(row.period_name || '-')})</span></div>
                <div class="row g-2">
                    <div class="col-sm-4">
                        <label class="form-label mb-1">${langData['modal_cycle'] || 'Payroll Schedule'} <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote bulk-cycle-select required" data-api="/api/payroll-cycle.options"></select>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label mb-1">${langData['modal_run_name'] || 'Run Name'} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control bulk-run-name required" value="${escapeHtml(row.process_no)}">
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
    let pendingMergesAll = [];
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
            // 2026-09-06: same pending_merges_ready mechanism as the single Pull-to-Run flow --
            // shown AFTER the bulk summary (not interleaved mid-loop, since several rows in the
            // same batch could each surface their own waiting supplemental(s)) via the exact same
            // shared prompt/merge function.
            if (pendingMergesAll.length > 0) {
                promptPendingMergesReady(pendingMergesAll);
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
                    if ((res.pending_merges_ready || []).length > 0) {
                        pendingMergesAll = pendingMergesAll.concat(res.pending_merges_ready);
                    }
                    $row.find('.bulk-pull-row-status').html(`<span class="text-success small"><i class="fa-solid fa-check me-1"></i>${langData['bulk_pull_result_success'] || 'created'}</span>`);
                } else {
                    failCount++;
                    $row.find('.bulk-pull-row-status').html(`<span class="text-danger small"><i class="fa-solid fa-xmark me-1"></i>${escapeHtml(res.message || 'failed')}</span>`);
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
    // 2026-09-09, round-creation flow audit Bug 2 fix -- re-checks the future_cycle auto-match
    // immediately before saving (see resolveRunMergeTargetBeforeSubmit()'s own docblock), possibly
    // overriding collectRunFormData()'s own merge_target_* fields with a resolved merge_target_run_id.
    // Blocks entirely (no ajax call at all) if 2+ candidates matched and the admin hasn't picked one.
    resolveRunMergeTargetBeforeSubmit(function (ok, overrides) {
        if (!ok) return;
        submitRunForm(overrides);
    });
});
function submitRunForm(mergeTargetOverrides) {
    const payload = Object.assign(collectRunFormData(), mergeTargetOverrides || {});
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
                // 2026-09-06, explicit request: pulling a REGULAR sync process into a run is exactly
                // the moment a supplemental process attributed to merge into THIS SAME Origami
                // process can finally go through -- see PayrollRunModel::create()'s own
                // pending_merges_ready docblock. Confirmed via AskUserQuestion: always prompt for an
                // explicit confirm here, never auto-merge silently (it changes this run's own gross
                // pay/tax). Offered one at a time via requestMergeSupplemental()/
                // handleMergeSyncResult() -- the SAME functions the row-level "Merge into Target"
                // button already uses -- so a revert/reopen-confirmation escalation on any one of
                // them behaves identically either way.
                if ((res.pending_merges_ready || []).length > 0) {
                    promptPendingMergesReady(res.pending_merges_ready);
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
}
// 2026-09-06: shared by both the single Pull-to-Run flow and the bulk-pull flow -- lists every
// waiting supplemental process by name and lets the admin merge them one at a time right here
// (reusing requestMergeSupplemental()/handleMergeSyncResult()) instead of having to go find them
// again in the table below by their (now "ready") badge.
// 2026-09-06: shared by BOTH kinds of "waiting merge target just became available" auto-detect --
// a supplemental sync process attributed to THIS Origami process (type='sync') AND a manually
// created off-cycle run whose future-cycle target was just auto-resolved to THIS run (type='manual')
// -- see PayrollRunModel::create()'s own docblock for the unified `pending_merges_ready` shape.
function requestMergeIntoExisting(sourceRunId, targetRunId, allowRevert, allowReopen, onDone) {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.merge-into-existing`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ source_run_id: sourceRunId, target_run_id: targetRunId, allow_revert_non_draft_target: !!allowRevert, allow_reopen_paid_target: !!allowReopen }), dataType: 'json',
        success: function (res) { onDone(res); },
        error: function () { onDone({ status: false, message: langData['save_failed'] || 'An error occurred while saving.' }); }
    });
}
function promptPendingMergesReady(items) {
    const listHtml = items.map(it => `<li>${escapeHtml(it.label)}</li>`).join('');
    Swal.fire({
        icon: 'info',
        title: langData['pending_merges_ready_title'] || 'Waiting Supplemental Item(s) Found',
        html: `<p>${(langData['pending_merges_ready_message'] || 'The following supplemental item(s) were waiting for this round and can now be merged:').replace('{count}', items.length)}</p><ul class="text-start">${listHtml}</ul>`,
        showCancelButton: true,
        confirmButtonText: langData['pending_merges_ready_confirm'] || 'Merge Now',
        cancelButtonText: langData['pending_merges_ready_later'] || 'Later',
    }).then(function (result) {
        if (!result.isConfirmed) return;
        let i = 0;
        function mergeNext() {
            if (i >= items.length) {
                if (tb_pending_sync) tb_pending_sync.ajax.reload(null, false);
                loadPendingSyncCount();
                if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
                return;
            }
            const item = items[i];
            const onMergeDone = function (res) {
                if (!res.status) {
                    showWarning((langData['pending_merges_ready_item_failed'] || 'Could not merge "{label}": {message}').replace('{label}', item.label).replace('{message}', res.message || ''));
                }
                i++;
                mergeNext();
            };
            // The target here was ALWAYS just created fresh by the same create() call that surfaced
            // this prompt (state='draft') -- resolveMergeTargetRun()'s own revert/reopen escalation
            // can never actually trigger, so no allow_* flags are needed either way.
            if (item.type === 'manual') {
                requestMergeIntoExisting(item.id, item.target_run_id, false, false, onMergeDone);
            } else {
                requestMergeSupplemental(item.id, false, false, onMergeDone);
            }
        }
        mergeNext();
    });
}

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
    const title = langData['confirm_verify_run_title'] || 'Verify this payroll run?';
    const message = langData['confirm_verify_run_message'] || 'Once verified, this run can no longer be recalculated.';
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
// 2026-09-10, real bug fix -- see window.langReady's own docblock in app.js: deferred so the run-state
// badges/stepper this page's own initPayrollRunTable() renders always have real translated text on
// first load instead of a raw enum fallback that never gets re-rendered afterward.
$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    applyOrigamiPayrollLinkGating();
    registerStationSearchFilter();
    initPayrollRunTable();
    restoreStationFromHash();
    if (typeof IS_ORIGAMI_PAYROLL_LINKED === 'undefined' || IS_ORIGAMI_PAYROLL_LINKED) {
        loadPendingSyncCount();
        loadBlockedSyncUpdates();
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#run_cycle_id', { mode: 'ajax' });
        // 2026-09-09, round-creation flow audit Phase 3: #run_purpose is a plain hidden input now
        // (driven by #run_purpose_choice_row's own choice cards), not a <select> -- no select2 init
        // needed/possible anymore.
        // 2026-09-01, explicit request: "เปิดรอบใหม่ / อ้างอิงถึงรอบ" radio's own target picker.
        initSelect2('#run_merge_target_id', { mode: 'ajax' });
        // 2026-09-01, explicit request: 3 new filters (Origin/Payroll Schedule/Run Purpose).
        initSelect2('#filter_run_origin', { mode: 'static' });
        initSelect2('#filter_run_cycle', { mode: 'ajax', allowClear: true });
        initSelect2('#filter_run_purpose', { mode: 'static' });
    }
    updateComputeStatutoryVisibility();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#filter_date_from');
        initDatepicker('#filter_date_to');
        initDatepicker('#run_period_start');
        initDatepicker('#run_period_end');
        initDatepicker('#run_payment_date');
        initDatepicker('#run_mark_paid_date');
        // 2026-09-09, real bug found and fixed (explicit report: "Date เลือกไม่ได้") -- these 2 fields
        // used to be plain `readonly` inputs with no datepicker init at all (see this form's own
        // #run_merge_target_period_start/_end markup comment).
        initDatepicker('#run_merge_target_period_start');
        initDatepicker('#run_merge_target_period_end');
    }
    updateClearFilterVisibility();
    });
});
