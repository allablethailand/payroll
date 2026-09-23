let tb_run_detail;
// 2026-09-11, Batch 3C item 6: Reports/Cash Payments/Bank Account Assignment/Third-Party Remittance
// each get their own DataTables instance now (initSharedDataTable(), app.js) -- kept as page-level
// vars, same convention as tb_run_detail above, so each tab's own shown.bs.tab handler can call
// .columns.adjust() on the CURRENT instance (destroy:true reconstructs a new one on every reload).
let tb_run_reports_dt = null;
let tb_run_cash_dt = null;
let tb_run_bank_account_dt = null;
let tb_run_remittance_dt = null;
// 2026-09-22, 3e-3 round B1: Action History's own instance -- same page-level-var convention as the
// 4 above (initSharedDataTable(), reused via `$.fn.DataTable.isDataTable()` on every reload instead
// of destroying/rebuilding, same pattern tb_run_detail's own construction already uses). See
// initAuditLogTableRd()'s own docblock further down this file.
let tb_run_audit_log = null;
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
// 2026-09-04, Backlog Phase 11, T065 -- escapeHtml()/escapeAttr()/fmtNum() moved to the shared
// public/js/format-helpers.js (loaded globally via header.php); this file's own former
// escapeHtmlRd/escapeAttrRd/fmtNumRd were confirmed byte-identical/behavior-preserving before the
// merge, see that file's own docblock.
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
// 2026-09-13, Round 3 item 3b: calcStatusBadgeRd() (its own hardcoded pending/calculated/error map)
// retired -- its one caller (initRunDetailTable()'s calc_status column) now routes through the shared
// statusBadgeHtml() + status_map.php's existing 'payroll_calc_status' context instead (see that
// column's own comment).
// 2026-09-21, 3e-2a: calcErrorMessageRd()/calcErrorMessagesRd() moved VERBATIM to
// public/js/format-helpers.js (loaded on every page, layout/header.php) -- the Payroll List page
// needs the same sentences and cannot load this file. calcErrorItemsRd()/calcAdvisoryCodesRd()
// live there too; this file calls all 4 unchanged.
// 2026-09-16, explicit instruction ("คอลัมน์การคำนวณ = statusBadge สถานะ + badge 'N คำเตือน' tone
// warning ไม่มีไอคอน คลิกเปิด popover รายการบรรทัดละข้อ"): advisory notes leave the cell. The badge is
// countBadgeHtml()'s own markup with the count wrapped in the sentence (§5: a non-neutral tone only
// when the number itself needs attention -- a warning is exactly that), and the list opens in the
// app's shared popover (initPopovers(), app.js/§11 -- token-styled, closes on Esc/click-outside,
// one open at a time) rather than the column-filter panel: that panel is a single app-wide instance
// built around a checklist + Clear/Apply footer, nothing of which this read-only list needs.
// 2026-09-21, 3e-2a: the warning badge and the NEW error badge are the same control -- a badge that
// opens its own list -- so they are built by one function and differ only by (class, title, codes,
// what the badge itself is). Written as a shared builder the moment the second one existed, rather
// than copying 9 lines and changing 3 of them (CLAUDE.md: "generalize, don't mirror-copy").
// Every `<li>` carries `data-code` (rules.md: the raw code never reads as text, but stays findable
// for a bug report) -- calcErrorItemsRd() (format-helpers.js) is what supplies both halves.
function calcPopoverBadgeRd(codes, badgeHtml, btnClass, titleKey, titleFallback) {
    const items = calcErrorItemsRd(codes);
    if (!items.length) return '';
    const content = `<ul class="rd-calc-warning-list">${items.map(it => `<li data-code="${escapeAttr(it.code)}">${escapeHtml(it.message)}</li>`).join('')}</ul>`;
    return `<button type="button" class="btn btn-link p-0 border-0 ms-1 align-baseline ${btnClass}"
        data-bs-toggle="popover" data-bs-trigger="click" data-bs-html="true" data-bs-placement="left"
        data-bs-title="${escapeAttr(langData[titleKey] || titleFallback)}"
        data-bs-content="${escapeAttr(content)}">${badgeHtml}</button>`;
}
function calcWarningBadgeRd(row) {
    // 2026-09-21, 3e-2a: the advisory list is calcAdvisoryCodesRd()'s to decide now (it adds the
    // prorate-0 note the engine has no code for) -- this reads whatever that returns, same as before.
    const codes = calcAdvisoryCodesRd(row);
    if (!codes.length) return '';
    const badge = countBadgeHtml(codes.length, { tone: 'warning', label: langData['calc_warning_count'] || '{n} warnings' });
    return calcPopoverBadgeRd(codes, badge, 'rd-calc-warning-btn', 'calc_warnings_title', 'Warnings');
}
// 2026-09-21, 3e-2a, explicit instruction ("badge error = popover แบบเดียวกับ badge คำเตือน"): the red
// status badge said only THAT the row failed; why it failed was one modal away. It is now the same
// shape as the warning badge next to it -- press it, read the blocking list. A row whose calc_status
// is 'error' with an EMPTY calc_blocking (possible: the state is stored, the codes are not) keeps
// the plain, unpressable badge rather than gaining a button that opens nothing.
function calcErrorBadgeRd(d, row) {
    const badge = statusBadgeHtml(d, 'payroll_calc_status');
    const blocking = (row && row.calc_blocking) || [];
    if (d !== 'error' || !blocking.length) return badge;
    return calcPopoverBadgeRd(blocking, badge, 'rd-calc-error-btn', 'calc_errors_title', 'Why this row failed');
}
// 2026-09-14, Round 3 item 3c-1 follow-up, real bug fix (explicit report: "column filter popup
// แสดงค่าดิบ 'calculated' แทน 'คำนวณแล้ว'") -- a status_map-backed badge column's own `render.filter`
// must return the SAME translated label text the badge itself shows, not the raw enum value --
// table-column-filter.js's own popup lists whatever `.render('filter')` returns per row as that
// column's distinct filterable values, so a raw code leaks straight into the popup otherwise (the
// same class of bug "เก็บตกรอบ5" already fixed once for the Verify column's own badge -- this closes
// the other 2 badge columns on this same table that still had it). Shared here since 2 columns
// below need the identical (enum, context) -> translated label lookup.
function statusMapFilterLabelRd(enumValue, context) {
    const entry = (typeof getStatusMapEntry === 'function') ? getStatusMapEntry(enumValue, context) : null;
    if (!entry) return enumValue;
    return getLangValue(entry.label_key) || entry.label_key;
}
function employeeDisplayNameRd(row) {
    const name = currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`;
    return name.trim();
}
// 2026-09-11, Batch 3C item 7 -- same th/en-pick-with-fallback convention used throughout this file
// (employeeDisplayNameRd() above, etc.). '-' for an employee with no department set, same convention
// as the Employee Quick View modal's own #empQuickViewDepartment.
function departmentNameRd(row) {
    return (currentLang === 'th' ? row.department_name_th : row.department_name_en) || row.department_name_th || row.department_name_en || '-';
}
// 2026-09-11, Batch 3C item 8 -- generalized out of 4 identical copies of this exact expression
// (.btn-view-breakdown/.btn-comment-employee/.btn-remove-manual-employee' own
// click handlers, each independently re-deriving "find this employee's row in the currently-loaded
// table data") rather than adding a 5th copy for .btn-comment-employee -- per this project's own
// "generalize, don't mirror-copy" convention.
function runDetailRowByEmployeeId(employeeId) {
    return (tb_run_detail ? tb_run_detail.rows().data().toArray() : []).find(r => Number(r.employee_id) === Number(employeeId));
}
// 2026-09-11, Batch 3C item 7, explicit instruction: "ตัดคอลัมน์ แหล่งที่มา ออก (ย้ายไปเป็น filter pill)"
// -- the dedicated "Source" column (2026-08-21) is retired, replaced by a filter pill above the
// table (see registerDataSourceSearchFilter()/#rdDataSourceFilterWrap). dataSourceBadgeRd() (the old
// per-row badge renderer this column used) is gone with it -- it had no other caller.
function personDisplayNameRd(row, prefix) {
    const th = row[prefix + '_name_th'];
    const en = row[prefix + '_name_en'];
    return (currentLang === 'th' ? th : en) || th || en || '-';
}

/* ---------- Next-step callout: one line below the stepper, telling the user exactly what this run
   needs next in plain language -- separate from the state badge/stepper labels (which just name the
   state) and from the action buttons themselves (which say WHAT to click, not WHY). §15/item B
   (2026-09-13): rendered via the shared callout.php/calloutHtml() component instead of a bespoke
   page-local box -- see style.css's own `.callout*` rules and callout.php's docblock.
   Tone-per-state is a JUDGMENT CALL (flagged, not something the task's own instruction spelled out
   for every state -- only locked=success/rejected=danger/need_info=warning were explicit): every
   normal forward-flow state (draft/pending_approval/approved/paid) reads as 'primary' (still moving
   toward completion, matches the stepper's own current-step orange), 'cancelled' reads as 'neutral'
   (nothing left to do, but not a success either). */
// 2026-09-13, item 3a follow-up (item 3): `run.approval_flow.approvers` (PayrollRunModel::
// approvalFlow(), unchanged -- the SAME flattened "who can act right now" list the Approval Timeline
// modal's own apvApprovalStageHtml() already renders) is the one real source for "who is this run
// waiting on" -- `status === 'pending'` is exactly the ones who haven't acted yet, real data, not a
// guess. Names fall back th->en->employee_no, same convention as apvApproverSubstepHtml()'s own.
function pendingApproverNamesRd(run) {
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    return approvers
        .filter(function (a) { return a.status === 'pending'; })
        .map(function (a) { return (currentLang === 'th' ? a.name_th : a.name_en) || a.name_th || a.name_en || a.employee_no; })
        .filter(Boolean);
}
function nextStepBanner(run) {
    const state = run.state;
    // Non-draft text is purely informational now (no "click X" instructions) except draft's own text
    // and pending_approval's approver-view text, which DO name real actions -- see the <b> tags,
    // matching real button labels word-for-word, weight 600 via .callout's own `b`/`strong` rule
    // (item B: "ตรงกับ label ปุ่มจริงและหนา 600").
    if (state === 'pending_approval') {
        // 2026-09-13, item 3: 2 genuinely different messages, not one generic "รอการอนุมัติ" for
        // everyone -- the approver (can_approve_payroll) sees what to DO (matches the 3
        // decision-cluster buttons in the header, computeRunHeaderActions() above, word-for-word);
        // everyone else sees WHO they're waiting on, by real name pulled from run.approval_flow, not
        // a static "someone is approving this" placeholder.
        if (run.can_approve_payroll) {
            const approverText = langData['next_step_pending_approval_approver']
                || 'Review the employee details, then click <b>Approve</b> / <b>Reject</b> / <b>Request Info</b>';
            return { tone: 'primary', html: approverText };
        }
        const names = pendingApproverNamesRd(run);
        if (names.length) {
            const namedTpl = langData['next_step_pending_approval_named'] || 'Waiting for approval from {names}.';
            return { tone: 'primary', html: namedTpl.replace('{names}', escapeHtml(names.join(', '))) };
        }
        return { tone: 'primary', html: langData['next_step_pending_approval'] || 'Waiting for approval.' };
    }
    const map = {
        draft: ['primary', 'next_step_draft', 'This run is still a draft. <b>Recalculate</b> to compute amounts, then <b>Submit for Approval</b> when ready.'],
        approved: ['primary', 'next_step_approved', 'Approved. Waiting to be marked as paid.'],
        paid: ['primary', 'next_step_paid', 'Paid. Waiting to be locked.'],
        locked: ['success', 'next_step_locked', 'This run is locked and finalized. No further action is needed.'],
        rejected: ['danger', 'next_step_rejected', 'Rejected. Review the reason above. Waiting to be revised.'],
        need_info: ['warning', 'next_step_need_info', 'More information was requested. Review the note above, then revise and resubmit.'],
        cancelled: ['neutral', 'next_step_cancelled', 'This run was cancelled and is no longer active.'],
    };
    const [tone, key, fallback] = map[state] || ['neutral', '', ''];
    const text = langData[key] || fallback;
    if (!text) {
        return { tone: '', html: '' };
    }
    return { tone, html: text };
}

/* ---------- Process timeline: a horizontal step tracker across the top of the page, mirroring
   the pattern in C:\xampp\htdocs\origami\payroll's own Process Detail page (TIMELINE_STEPS /
   renderChrome() in its assets/js/process-detail.js) per explicit request -- shows exactly which
   step this run is at, with each step's own action button(s) rendered directly underneath it (the
   action that moves the run INTO that step) instead of a separate generic action bar below. Every
   action that used to live in #runActionButtons now renders under the station it belongs to.
   Delete/Cancel are NOT part of this spine at all -- both were removed from the Detail page
   entirely per explicit request and now live only on the Payroll Process list page's row actions,
   since they're "leave the flow" actions rather than a step within it.
   2026-09-10, Batch 3A item 2: the step definitions/progress computation (was RUN_TIMELINE_STEPS/
   computeTimelineProgress()/cancelledFromState() here) moved to app.js's own
   RUN_LIFECYCLE_STEPS/runLifecycleSteps() -- shared with index.js's mini-timeline, which used to
   duplicate this exact same logic under its own MINI_TIMELINE_STEPS/computeMiniTimelineProgress().
   renderProcessTimeline() below now just calls runLifecycleSteps(run, {showDates:true}). */
// 2026-09-13, Phase Design Round 3 item 3a, explicit decision: "stepper เป็น 'สถานะ' ล้วน ไม่มีปุ่มฝัง
// อีก" (§2/§6, applies to every future page with a stepper, not just this one) -- every action
// button that used to render INSIDE a `tl-actions-row` under whichever station was current (the OLD
// timelineStepActionsHtml(), removed outright, see git history for its own per-station docblock/
// history if ever needed again) now renders in page-header.php's own #phActions instead
// (renderPageHeaderActions(), app.js), via computeRunHeaderActions() below. renderProcessTimeline()
// itself (further down) no longer renders any actions at all -- just icon/label/date per station.
//
// This is a straight port of the OLD function's exact same state/permission logic into the
// {primary, secondary, overflow} shape page-header.php's own $primary_action/$secondary_actions/
// $overflow_actions expect (docs/design/rules.md §2) -- every button KEEPS its original
// `.btn-tl-*`/`#btnSubmitRun` class or id unchanged, so every existing `$(document).on('click',
// '.btn-tl-xxx', ...)` delegated handler (all of them ARE delegated on `document`, confirmed before
// making this change) keeps firing correctly regardless of where in the DOM the button now lives --
// no click-handler code needed to change at all, only where the button's OWN html string is built.
//
// Judgment calls made while porting (flagged in the round-3 report, not silently decided):
// - Approve/Mark-as-Paid/Verify were previously colored `.btn-success`/`.btn-primary`/
//   `.btn-outline-secondary` respectively -- ALL become the primary slot now (forced `.btn-primary`,
//   orange, by page-header.php's own queue-building), consistent with "primary = the one
//   recommended next action for this state" regardless of what tone it happened to have before.
// - "View Timeline" (a plain informational view, not a state transition) is now `secondary` (visible
//   directly, not one level deep in a menu) -- see computeRunHeaderActions()'s own comment further
//   down (2026-09-13, item 3a follow-up: "decision set" moved). "Undo Decision"/"Reopen" (a REVERSAL
//   of a decision, not a forward step, nor one of the 3 literal approve-time choices) stay in
//   `$overflow_actions` (Reopen tagged tone:'danger', matching its own previous `.btn-outline-danger`
//   styling).
// - "ยกเลิก" (Cancel run) was NOT ported -- this page's own code comment (right below,
//   RUN_LIFECYCLE_BRANCH_INFO's neighbor) already states Cancel/Delete were deliberately removed
//   from this page entirely and only exist on the Process LIST page's row actions; wiring a NEW
//   cancel capability onto Detail would be adding real functionality, not just re-skinning existing
//   UI, which is out of this round's "view/CSS/JS-render only" scope (§0.7) -- flagged for
//   confirmation before wiring, not guessed.
function computeRunHeaderActions(run) {
    const t = (key, fallback) => langData[key] || fallback;
    let primary = null;
    let decision = null;
    const overflow = [];
    // Every state-transition action below carries `extraClass` set to its ORIGINAL `.btn-tl-*` class
    // (unchanged from the OLD per-station buttons) -- the existing `$(document).on('click',
    // '.btn-tl-xxx', ...)` delegated handlers key off that class, not the `id` given here (a plain
    // stable id, new, not read by any existing handler -- present only so a future need to target
    // one of these individually, e.g. disabling it, has something to select on).
    if (run.state === 'draft') {
        primary = { label: t('action_submit', 'Submit for Approval'), id: 'btnSubmitRun', icon: 'fa-solid fa-paper-plane' };
    } else if (run.state === 'pending_approval') {
        // 2026-09-13, item 3a follow-up ("decision set"): the approver's own 3 real choices (ขอข้อมูล
        // เพิ่ม/ไม่อนุมัติ/อนุมัติ) now render together as page-header.php's own `$decision_actions`
        // cluster, visibly separated (--sp-3) from Timeline/Export/"อื่นๆ" -- NOT buried one level deep
        // inside the overflow dropdown like an earlier cut of this same task had them.
        // 2026-09-13, SAME-DAY revision of this same cluster: order is now [อนุมัติ][ขอข้อมูลเพิ่มเติม]
        // [ไม่อนุมัติ] (was request-info/reject/approve) and each item carries its OWN `tone` --
        // 'success' (solid, white text)/'warning' (outline)/'danger' (outline) -- picking
        // page-header.php's new `.btn-decision-*` classes (§4's documented decision-set exception,
        // rules.md §4) instead of the earlier "every item outline-secondary except the last" rule.
        // "Send Back for Revision" stays in `overflow` (`.ph-decision-group` cluster is ONLY the 3
        // literal decision choices, nothing else) -- for a viewer with NEITHER can_approve_payroll NOR
        // can_process_payroll, neither `decision` nor `overflow` gets anything at all: the header shows
        // just Timeline+Export, and nextStepBanner()'s own callout explains who still needs to act
        // (see the `pending_approval` case there, item 3).
        if (run.can_approve_payroll) {
            overflow.push({ label: t('action_revert', 'Send Back for Revision'), id: 'btnRevertRunHeader', icon: 'fa-solid fa-rotate-left', extraClass: 'btn-tl-revert' });
            decision = [
                { label: t('action_approve', 'Approve'), id: 'btnApproveRunHeader', icon: 'fa-solid fa-check', extraClass: 'btn-tl-approve', tone: 'success' },
                { label: t('action_request_info', 'Request Info'), id: 'btnRequestInfoRunHeader', icon: 'fa-solid fa-circle-info', extraClass: 'btn-tl-request-info', tone: 'warning' },
                { label: t('action_reject', 'Reject'), id: 'btnRejectRunHeader', icon: 'fa-solid fa-xmark', extraClass: 'btn-tl-reject', tone: 'danger' },
            ];
        } else if (run.can_process_payroll) {
            overflow.push({ label: t('action_revert', 'Send Back for Revision'), id: 'btnRevertRunHeader', icon: 'fa-solid fa-rotate-left', extraClass: 'btn-tl-revert' });
        }
    } else if (run.state === 'approved') {
        if (run.can_finalize_payroll) {
            primary = { label: t('action_mark_paid', 'Mark as Paid'), id: 'btnMarkPaidRunHeader', icon: 'fa-solid fa-money-check-dollar', extraClass: 'btn-tl-mark-paid' };
        }
        if (run.can_approve_payroll) {
            overflow.push({ label: t('action_undo_decision', 'Undo Decision'), id: 'btnRevertRunHeader', icon: 'fa-solid fa-rotate-left', extraClass: 'btn-tl-revert' });
        }
    } else if (run.state === 'paid' && run.can_finalize_payroll) {
        primary = { label: t('action_verify_run', 'Verify'), id: 'btnLockRunHeader', icon: 'fa-solid fa-check-double', extraClass: 'btn-tl-lock' };
        overflow.push({ label: t('action_reopen', 'Reopen for Editing'), id: 'btnReopenRunHeader', icon: 'fa-solid fa-unlock', tone: 'danger', extraClass: 'btn-tl-reopen' });
    } else if ((run.state === 'rejected' || run.state === 'need_info') && run.can_process_payroll) {
        primary = { label: t('action_revise', 'Revise'), id: 'btnPullBackRunHeader', icon: 'fa-solid fa-pen-to-square', extraClass: 'btn-tl-pull-back' };
    } else if (run.state === 'locked' && run.can_finalize_payroll) {
        overflow.push({ label: t('action_reopen', 'Reopen for Editing'), id: 'btnReopenRunHeader', icon: 'fa-solid fa-unlock', tone: 'danger', extraClass: 'btn-tl-reopen' });
    }
    // Recalculate: unchanged draft-only gating from the OLD table-toolbar button it replaces
    // (#btnRecalculate, was `d-none` unless draft -- see initRunDetailTable()'s own initComplete/
    // renderSectionButtons()'s history) -- moved here per explicit decision ("secondary = [คำนวณใหม่]
    // [ส่งออก ▾]"), same id so its existing delegated click handler needs no change.
    // Timeline: 2026-09-13, item 3a follow-up -- moved OUT of `overflow` (was hidden one level deep
    // inside "อื่นๆ") into `secondary`, visible directly next to Export ("[ไทม์ไลน์อนุมัติ] [ส่งออก ▾]")
    // -- same `run.submitted_at` gate as before, just a different slot; applies to every post-submit
    // state (not only pending_approval) for consistency, since it's a plain informational view action
    // in every one of them, not specific to the approval decision itself.
    const secondary = [];
    if (run.state === 'draft') {
        secondary.push({ label: t('action_recalculate', 'Calculate'), id: 'btnRecalculate', icon: 'fa-solid fa-rotate' });
    } else if (run.submitted_at) {
        secondary.push({ label: t('action_timeline', 'Timeline'), icon: 'fa-solid fa-list-check', id: 'btnViewRunTimeline', extraClass: 'btn-tl-view-timeline' });
    }
    // Export: unchanged ids/delegated click handlers (#btnExportRunRegister/#btnPreviewRunRegisterPdf)
    // -- was 2 standalone always-visible buttons beside the run-name heading, now 1 secondary
    // dropdown ("ส่งออก ▾") per explicit decision, same 2 targets inside it.
    // 2026-09-13, item 3a "เก็บตก" item 1: file-type icons colored via `.file-icon-excel`/
    // `.file-icon-pdf` (style.css, §1's own documented exception) -- appended straight into the
    // `icon` class string itself (page-header.php's own dropdown-item renderer just dumps this string
    // verbatim into `<i class="...">`, no new field needed on the partial's own contract).
    secondary.push({
        label: t('export_label', 'ส่งออก'), icon: 'fa-solid fa-file-export', items: [
            { label: t('export_excel', 'Export Excel'), id: 'btnExportRunRegister', icon: 'fa-solid fa-file-excel file-icon-excel' },
            { label: t('export_pdf', 'Export PDF'), id: 'btnPreviewRunRegisterPdf', icon: 'fa-solid fa-file-pdf file-icon-pdf' },
        ]
    });
    // Verify All: 2026-09-13, 3a follow-up decision -- moved back to the Employee table's own
    // `.dt-length` toolbar (initRunDetailTable()'s initComplete, further down this file), next to
    // "ตรวจสอบที่เลือก (N)" -- both are genuinely table-scoped actions (one acts on every row, the
    // other on the selected ones), unlike the header's overflow menu which is now state-transition
    // actions on the RUN ITSELF only (Send Back/Undo Decision/Reopen). A first cut of this change had
    // moved it into the header overflow menu instead; reverted after review.
    return { primary, secondary, overflow, decision };
}
function renderRunHeaderActions(run) {
    renderPageHeaderActions('#phActions', computeRunHeaderActions(run));
}
// 2026-09-13, Round 3 item 3a follow-up fix: this function's FIRST cut only removed the per-station
// action buttons but kept rendering the OLD `.process-timeline`/`.tl-*` markup underneath (card
// wrapper, a differently-colored/gradient icon per step, a clock icon on each date) -- a real miss
// caught in review against a live screenshot, not the actual §6 status-stepper.php/renderStatusStepper()
// component the task asked for. Now genuinely calls the shared component: `.process-timeline`/
// `.tl-*`'s own CSS is NOT deleted (payroll/index.js's mini-timeline and the Approval Timeline modal
// in layout/modals.php still use it) -- only THIS function stopped generating that markup.
// runLifecycleSteps()'s own branch-state handling (rejected/need_info/cancelled) is preserved as-is
// (still the one source of truth for progress/labels) -- a branch step renders as the "current" step
// with that branch's own label substituted in (e.g. "ไม่อนุมัติ / ส่งกลับแก้ไข" instead of "อนุมัติ").
//
// 2026-09-13, SAME-DAY follow-up: the current step's own CIRCLE now also takes its color from
// statusMapEntry(run.state, 'run_state') (§5) whenever the run is actually in a branch state --
// `step.cls` at the branch's own index is ALREADY exactly that branch's run_state enum value
// ('rejected'/'need_info'/'cancelled', see computeRunLifecycleProgress()'s own RUN_LIFECYCLE_BRANCH_INFO
// keys), so it can be looked up directly with no extra mapping table. A normal forward-flow state
// (draft/pending_approval/approved/paid/locked) never reaches this branch at all -- `step.cls` for
// the CURRENT step in that case is the literal string 'current' (set by runLifecycleSteps() itself,
// not a run_state enum value), which status_map.php's own `run_state` context has no entry for, so
// `getStatusMapEntry()` correctly returns null and the step gets no tone override -- stays plain
// orange exactly as before, not a special-cased skip.
function renderProcessTimeline(run) {
    const { steps, currentIndex } = runLifecycleSteps(run, { showDates: true });
    const isLocked = run && run.state === 'locked';
    // §6, 2026-09-13, item 3a "เก็บตก" item 2: "live" pulse on the current step only when the CURRENT
    // VIEWER genuinely has something clickable waiting -- reuses computeRunHeaderActions() (the exact
    // same function the header itself renders from) rather than re-deriving permission logic here, so
    // this can never drift out of sync with what buttons are actually showing. Cheap/pure (no side
    // effects, just building plain arrays) -- calling it a 2nd time per render (renderRunHeaderActions()
    // above already calls it once for the header itself) is negligible cost, not worth threading the
    // result through as a parameter.
    const viewerActions = computeRunHeaderActions(run);
    const isActionableForViewer = !!(viewerActions.decision || viewerActions.primary);
    const stepperSteps = steps.map(function (step, i) {
        const showDate = (step.cls === 'done' || step.cls === 'current') && step.date;
        const stepObj = { label: step.label, date: showDate ? toLocalDateOnlyRd(step.date) : null };
        const toneEntry = getStatusMapEntry(step.cls, 'run_state');
        if (toneEntry && toneEntry.tone) stepObj.tone = toneEntry.tone;
        // §6, 2026-09-13: the run's own LAST station renders as the terminal "fully complete" circle
        // (solid --c-success + white check) only once the run has actually reached `locked` -- never
        // inferred from currentIndex alone, since a run mid-flow (e.g. currentIndex past the last real
        // station transiently) is not the same thing as genuinely locked.
        if (isLocked && i === steps.length - 1) stepObj.final = true;
        // A branch state (rejected/need_info) already set `stepObj.tone` above -- those read as
        // settled/waiting, not "act now", so they deliberately never pulse even when some viewer role
        // could still act on the underlying run.
        if (i === currentIndex && !stepObj.tone && isActionableForViewer) stepObj.live = true;
        // §6, 2026-09-13, item C follow-up: the current step's own icon (white, 12px) -- `step.icon`
        // is ALREADY the right value here for the current index (runLifecycleSteps() never overrides
        // it away from RUN_LIFECYCLE_STEPS[i].icon/RUN_LIFECYCLE_BRANCH_INFO[type].icon except for a
        // DONE step, which always becomes 'fa-check' instead -- see that function's own mapping). Only
        // passed at all for the current step; a done/next step's own icon is status-stepper.php's own
        // fixed ✓/nothing, never this per-station one.
        if (i === currentIndex && step.icon) stepObj.icon = step.icon;
        return stepObj;
    });
    $('#runProcessTimeline').html(renderStatusStepper(stepperSteps, currentIndex));
}

/* ---------- Section-scoped buttons: Edit sits at the top-right of "1. Run Information" (the
   section it actually edits) -- draft-only, same as before. Button id stays #btnEditRun; the
   existing $(document).on(...) delegated handler doesn't care where in the DOM it lives.
   2026-09-13, Round 3 item 3a: Recalculate (#btnRecalculate) no longer lives here -- it moved to
   page-header.php's own #phActions (computeRunHeaderActions(), further up this file), per explicit
   decision that page-level state-transition/utility actions belong in the header now, not scattered
   next to individual section headings. */
// 2026-08-29, same-day follow-up: "ตรงปุ่มออกรายงาน ให้ปรับเป็นเพิ่มอีก Tab ก่อน Action History และแสดงเป็น
// ตารางรายการไว้ และบอกด้วยว่า Download แล้วทั้งหมดกี่ครั้ง ครั้งล่าสุด Download ไปเมื่อไหร่...มีปุ่มสำหรับกด
// Download กดแล้วเปิด modal เพื่อ Preview ก่อน...มีอีกปุ่มเพื่อกดดูประวัติการ Download" -- was a header
// dropdown (renderRunReportsButtons(), removed) offering the SAME 3 shortcuts the List page's own
// row action used to have (public/js/payroll/index.js's since-removed PR_REPORT_SHORTCUTS dropdown
// -- that page now just deep-links straight into THIS tab instead, see renderRunActionsPr()'s own
// docblock); now its own tab with a small table (server-authoritative row set + tax/SSO hiding both
// come from ReportsController::runReportsSummary(), not recomputed here).
const RD_REPORT_ALLOWED_STATES = ['approved', 'paid', 'locked'];
let rdReportsRows = [];
let rdReportPreviewContext = null; // { code, format } for whichever row's modal is currently open
// ReportGeneratorInterface::label() returns {th, en} (same convention public/js/reports/index.js's
// own reportLabel() already reads) -- row.label here is that same bilingual object, not a string.
function rdReportLabel(row) {
    return (currentLang === 'th' ? row.label.th : row.label.en) || row.label.th || row.label.en || row.code;
}
// 2026-09-09, explicit follow-up correction (2nd pass, w/ reference screenshot): "หมายถึง icon แบบนี้ครับ
// ไม่ใช่ svg" -- a solid-colored rounded-square TILE with a white fa-solid glyph inside (the reference
// screenshot showed orange tiles for statutory forms, purple for a payment-type one, green for
// internal/summary reports), not a plain inline image/fa icon like the first two passes tried. Only 2
// report_types can ever actually reach this tab's own row set (RUN_REPORT_SHORTCUTS server-side is
// TH_SSO110/TH_PND1[statutory]/BANK_TRANSFER_FILE[payment] -- PAYROLL_REGISTER[internal] is filtered
// out above, it has its own dedicated button) but `internal` is still mapped here for consistency/
// future-proofing, same reasoning reports/index.js's own REPORT_TYPE_ICONS map already uses.
// 2026-09-20, 3e-1 round 1: the `bg` half of each entry (rd-report-tile-orange/-purple/-green) is
// gone -- a report's TYPE is a category, and rules.md 0.1 gives colour only 2 jobs, neither of which
// is "this row is statutory rather than internal". The tile is one neutral swatch for every row now
// (style.css); the per-type ICON stays, since an icon may carry a category (rules.md 7).
const RD_REPORT_TILE_BY_TYPE = {
    statutory: { icon: 'fa-landmark' },
    payment: { icon: 'fa-money-check-dollar' },
    internal: { icon: 'fa-file-lines' },
};
function rdReportIconTileHtml(row) {
    const tile = RD_REPORT_TILE_BY_TYPE[row.report_type] || RD_REPORT_TILE_BY_TYPE.internal;
    return `<span class="rd-report-tile me-2"><i class="fa-solid ${tile.icon}"></i></span>`;
}
// 2026-08-29, same-day follow-up: "ที่โชว์ในตารางประวัติการ Download มีเก็บครบหรือยังถ้ายังไม่ครบเก็บเพิ่มให้
// ครบครับ" -- os_name/browser_version are now captured too (see the migration's own header comment),
// folded into the same 2 columns ("Device"/"Browser") rather than adding 2 more columns, e.g.
// "Desktop (Windows)" / "Chrome 119" instead of a bare "Desktop" / "Chrome".
function rdReportDeviceLabel(row) {
    if (!row.device_type) return '-';
    return row.os_name ? `${row.device_type} (${row.os_name})` : row.device_type;
}
function rdReportBrowserLabel(row) {
    if (!row.browser_name) return '-';
    return row.browser_version ? `${row.browser_name} ${row.browser_version}` : row.browser_name;
}
// 2026-08-29, same-day follow-up: "จะสามารถพิมพ์รายงานได้เมื่องวดนี้ได้รับการอนุมัติแล้ว ให้ขึ้นรายการ Report
// ไว้เลย แต่ยังกดไม่ได้" -- the row set itself is now ALWAYS shown (even for a draft/pending_approval
// run -- calcApplicabilitySummary() already fails open to "show everything" before any employee has
// been calculated yet, see that method's own docblock, so this never has to special-case "nothing
// calculated" here), only the action buttons are disabled until the run reaches an allowed state.
function loadRunReportsTab() {
    if (!PAYROLL_RUN_ID || !currentRun) return;
    const stateIsReady = RD_REPORT_ALLOWED_STATES.includes(currentRun.state);
    // 2026-08-31, explicit request: "ในหน้า List และ Detail ของการทำรอบ อยากให้มีการ Export Excel ได้
    // ไม่ว่าจะสถานะไหน" -- an `internal` report (PAYROLL_REGISTER) has no state gate at all
    // server-side, so it must never be blocked by this table's own state-based disable either;
    // only the banner (a generic "not everything is ready yet" hint) still reflects the OVERALL
    // state, since most rows in this table genuinely do still require approved/paid/locked.
    $('#runReportsNotReadyBanner').toggleClass('d-none', stateIsReady);
    $.getJSON(`${BASE_URL}/api/report.run-summary`, { run_id: PAYROLL_RUN_ID }, function (res) {
        if (!res.status) return;
        // 2026-08-31, explicit request: "ปุ่ม Export Excel ไม่ควรไปรวมอยู่ในรายงาน ย้ายไปอยู่กับ Timeline" --
        // PAYROLL_REGISTER (this run's own employee-by-employee register) is deliberately excluded
        // from this generic list -- it now has its own dedicated button next to the Timeline (see
        // #btnExportRunRegister), not mixed in among the statutory/payment reports here. Still the
        // exact same download (same api/report.generate call, same report_export_logs tracking) --
        // only WHERE the trigger lives on this page changed.
        rdReportsRows = (res.data || []).filter(row => row.code !== 'PAYROLL_REGISTER');
        $('#runReportsNotReady').toggleClass('d-none', rdReportsRows.length > 0);
        $('#tb_run_reports').toggleClass('d-none', rdReportsRows.length === 0);
        const notReadyTitle = langData['reports_available_after_approval'] || 'Reports are available once this run is approved.';
        // 2026-09-11, Batch 3C item 6: was a plain <table>, no pagination/search/sort --
        // initSharedDataTable() (app.js) now OWNS the destroy/render/construct order itself (see that
        // function's own docblock for why the order matters) -- this row-building logic itself is
        // unchanged, just handed to it as `renderRows` instead of called directly here first.
        // "Last Downloaded" carries data-order (the raw ISO timestamp, or '' for "Never") so
        // DataTables' own HTML5 data-attribute auto-detection sorts chronologically, not as the
        // localized dd/mm/yyyy display string (see CLAUDE.md's own Table convention on this exact
        // class of bug). #run-reports-tab's own shown.bs.tab handler below re-measures column widths
        // the first time this tab is actually visible (this tab isn't the default-active one, so this
        // call itself usually runs while the tab-pane is still display:none -- same gotcha
        // #tb_run_detail's own Employee-tab handler already exists for).
        // 2026-09-20, 3e-1 round 1: this table is a FIXED catalogue of the reports this run can
        // produce (one row per registered generator, never paginated in practice), so DataTables'
        // pageLength select, "Showing 1 to N of N" line and pagination bar are three controls that
        // can never do anything. Passed through `dtOptions` -- a per-table decision, nothing about
        // initSharedDataTable() itself changes, and every other table keeps its controls.
        tb_run_reports_dt = initSharedDataTable('#tb_run_reports', {
            dtOptions: { paging: false, info: false, lengthChange: false },
            searchThreshold: 5,
            renderRows: function () {
                $('#runReportsTableBody').html(rdReportsRows.map(row => {
                    const rowIsReady = row.report_type === 'internal' ? true : stateIsReady;
                    const disabledAttr = rowIsReady ? '' : 'disabled';
                    return `
                    <tr>
                        <td><div class="d-flex align-items-center">${rdReportIconTileHtml(row)}${escapeHtml(rdReportLabel(row))}</div></td>
                        <td class="text-center">${Number(row.download_count) || 0}</td>
                        <td data-order="${row.last_downloaded_at || ''}">${row.last_downloaded_at ? formatDisplayDateTime(row.last_downloaded_at) : `<span class="text-muted">${langData['report_never_downloaded'] || 'Never'}</span>`}</td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <button type="button" class="btn btn-link btn-circle-action btn-report-preview" data-code="${row.code}" ${disabledAttr} title="${rowIsReady ? (langData['report_preview_and_download'] || 'Preview & Download') : notReadyTitle}"><i class="fa-solid fa-download"></i></button>
                                <button type="button" class="btn btn-link btn-circle-action text-secondary btn-report-history" data-code="${row.code}" ${disabledAttr} title="${rowIsReady ? (langData['report_view_history'] || 'View Download History') : notReadyTitle}"><i class="fa-solid fa-clock-rotate-left"></i></button>
                            </div>
                        </td>
                    </tr>
                `;
                }).join(''));
            },
        });
    });
}
// 2026-08-31, same-day follow-up -- see #btnExportRunRegister's own comment in detail.php. Direct
// download, same convention public/js/payroll/index.js's own per-row .btn-export-run-register
// button already uses (no preview modal -- Excel has no inline preview path anyway).
$(document).on('click', '#btnExportRunRegister', function () {
    if (!PAYROLL_RUN_ID) return;
    const params = new URLSearchParams();
    params.set('report_code', 'PAYROLL_REGISTER');
    params.set('format', 'excel');
    params.set('run_id', PAYROLL_RUN_ID);
    params.set('source', 'payroll_process_detail');
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});
// 2026-08-31, explicit request: "ถ้าพนักงานรับเงินสด...แยก Report ตามแยก ว่าจ่ายเงินสดเท่าไหร่ โอนผ่าน
// ธนาคารเท่าไหร่ และสามารถใส่ Status ว่าจ่ายแล้ว" -- interactive per-employee cash payment status.
// Same ALLOWED_STATES gate as the Reports tab (PayrollRunCashPaymentModel enforces this
// server-side too -- the client-side check here is purely to show the right empty-state message
// without an extra round trip).
function loadRunCashTab() {
    if (!PAYROLL_RUN_ID || !currentRun) return;
    const isReady = RD_REPORT_ALLOWED_STATES.includes(currentRun.state);
    $('#runCashNotReady').toggleClass('d-none', isReady);
    $('#runCashContent').toggleClass('d-none', !isReady);
    if (!isReady) return;
    $.getJSON(`${BASE_URL}/api/payroll-run-cash-payment.list`, { run_id: PAYROLL_RUN_ID }, function (res) {
        if (!res.status) {
            $('#runCashNotReady').removeClass('d-none').find('#runCashNotReadyMessage').text(res.message || '');
            $('#runCashContent').addClass('d-none');
            return;
        }
        const data = res.data;
        $('#runCashTotalCash').text(fmtNum(data.total_cash));
        $('#runCashTotalBank').text(fmtNum(data.total_bank));
        const cashRows = data.rows || [];
        // 2026-09-11, Batch 3C item 6: the old inline "no data" <tr> (colspan placeholder) is gone --
        // an empty tbody + DataTables' own language.emptyTable (below) is the correct way to show
        // this now; a placeholder <tr> would otherwise get counted as a real data row (pagination
        // info would read "showing 1 to 1 of 1 entries" for an empty table). Row-rendering itself is
        // unchanged, just handed to initSharedDataTable() as `renderRows` instead of called directly
        // (see that function's own docblock for why the destroy/render/construct order matters) --
        // Amount/Paid At now carry data-order (raw amount / raw ISO timestamp) so DataTables' own
        // HTML5 data-attribute auto-detection sorts numerically/chronologically instead of on the
        // comma-formatted/localized display text (CLAUDE.md's own Table convention on this exact bug).
        tb_run_cash_dt = initSharedDataTable('#tb_run_cash', {
            searchThreshold: 5,
            // 2026-09-20, 3e-1 (§6): DataTables' own one-line `language.emptyTable` replaced by
            // initSharedDataTable()'s `emptyState`, which renders the shared empty-state component
            // AND tells the two meanings apart on its own -- "nothing here at all" (this config) vs
            // "your filter matched none of the rows that ARE here" (the helper's own fixed copy plus
            // a Clear action). The one-line version could only ever say the first, even when the
            // second was what had happened. Title only: the sentence this tab already had IS the
            // whole message, and there is no create action to offer on a run's own cash list.
            emptyState: { icon: 'fa-solid fa-money-bill-wave', title: langData['no_cash_payments'] || 'No cash-paying employees in this run.' },
            renderRows: function () {
                $('#runCashTableBody').html(cashRows.map(row => {
                    const name = escapeHtml((currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`).trim());
                    const isPaid = row.status === 'paid';
                    // 2026-09-20, 3e-1 (rules.md §5: one helper, map in status_map.php, never in a
                    // view/JS): the hand-written subtle-class pair is the shared badge now, via the
                    // new 'cash_payment_status' context -- same 2 label keys, same 2 tones, so the
                    // rendered badge is unchanged; what changes is that it now carries the
                    // `data-badge="status"` marker and follows a live language switch like every
                    // other badge in the app.
                    const badge = statusBadgeHtml(row.status, 'cash_payment_status');
                    const paidByName = currentLang === 'th' ? row.paid_by_name_th : row.paid_by_name_en;
                    const paidAtCell = isPaid ? `${formatDisplayDateTime(row.paid_at)}${paidByName ? `<div class="text-muted small">${escapeHtml(paidByName)}</div>` : ''}` : '-';
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group (and its own
                    // former .btn-sm, redundant now that .btn-circle-action sets a fixed 32x32 size).
                    const actionBtn = isPaid
                        ? `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-cash-mark-unpaid" data-id="${row.id}" title="${langData['mark_as_unpaid'] || 'Mark as Unpaid'}"><i class="fa-solid fa-rotate-left"></i></button>`
                        : `<button type="button" class="btn btn-link btn-circle-action btn-cash-mark-paid" data-id="${row.id}" title="${langData['mark_as_paid'] || 'Mark as Paid'}"><i class="fa-solid fa-check"></i></button>`;
                    return `<tr>
                        <td>${escapeHtml(row.employee_no)}</td>
                        <td>${name}</td>
                        <td data-order="${Number(row.amount) || 0}">${fmtNum(row.amount)}</td>
                        <td class="text-center">${badge}</td>
                        <td data-order="${isPaid ? row.paid_at : ''}">${paidAtCell}</td>
                        <td class="text-center"><div class="d-flex gap-1 justify-content-center">${actionBtn}</div></td>
                    </tr>`;
                }).join(''));
            },
        });
    });
}
function setRunCashPaymentStatus(id, status) {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run-cash-payment.set-status`, method: 'POST',
        contentType: 'application/json', data: JSON.stringify({ id, status }), dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            loadRunCashTab();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}
$(document).on('click', '.btn-cash-mark-paid', function () {
    const id = $(this).data('id');
    showConfirm(
        langData['confirm_mark_paid_title'] || 'Mark as Paid',
        langData['confirm_mark_paid_message'] || 'Confirm this cash payment has been handed over to the employee?',
        function () { setRunCashPaymentStatus(id, 'paid'); }
    );
});
$(document).on('click', '.btn-cash-mark-unpaid', function () {
    const id = $(this).data('id');
    showConfirm(
        langData['confirm_mark_unpaid_title'] || 'Mark as Unpaid',
        langData['confirm_mark_unpaid_message'] || 'Revert this back to unpaid?',
        function () { setRunCashPaymentStatus(id, 'unpaid'); }
    );
});
// 2026-09-02, multi-bank-account payroll, explicit request: "ในหน้า Detail ก็สามารถเลือกได้ว่าใครจะโอนผ่าน
// บัญชีไหน...ในหน้า Detail ของ Process เพิ่ม Tab ให้จัดการข้อมูลส่วนนี้ได้". Same ALLOWED_STATES gate/pattern
// as loadRunCashTab() above (this is a disbursement concern, only meaningful once a run's numbers
// are final -- see PayrollRunEmployeeBankAccountModel's own docblock).
let rdBankAccountRows = [];
const RD_BANK_ACCOUNT_SOURCE_LABEL_KEY = {
    override: 'bank_account_source_override',
    employee_default: 'bank_account_source_employee_default',
    cycle: 'bank_account_source_cycle',
    company_default: 'bank_account_source_company_default',
};
function loadRunBankAccountTab() {
    if (!PAYROLL_RUN_ID || !currentRun) return;
    const isReady = RD_REPORT_ALLOWED_STATES.includes(currentRun.state);
    $('#runBankAccountNotReady').toggleClass('d-none', isReady);
    $('#runBankAccountContent').toggleClass('d-none', !isReady);
    if (!isReady) return;
    $.getJSON(`${BASE_URL}/api/payroll-run-employee-bank-account.list`, { run_id: PAYROLL_RUN_ID }, function (res) {
        if (!res.status) {
            $('#runBankAccountNotReady').removeClass('d-none').find('#runBankAccountNotReadyMessage').text(res.message || '');
            $('#runBankAccountContent').addClass('d-none');
            return;
        }
        rdBankAccountRows = res.data || [];
        // 2026-09-11, Batch 3C item 6: see loadRunCashTab()'s own comment -- empty tbody + DataTables'
        // own language.emptyTable, not an inline placeholder <tr> counted as real data; row-rendering
        // handed to initSharedDataTable() as `renderRows` (destroy/render/construct order owned by
        // that function now, see its own docblock). No money/date columns here -- Bank Account/
        // Source are both plain text/badge, no data-order needed.
        tb_run_bank_account_dt = initSharedDataTable('#tb_run_bank_account', {
            searchThreshold: 5,
            emptyState: { icon: 'fa-solid fa-building-columns', title: langData['bank_account_no_employees'] || 'No bank-paying employees in this run.' },
            renderRows: function () {
                $('#runBankAccountTableBody').html(rdBankAccountRows.map(row => {
                    const name = escapeHtml((currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`).trim());
                    const bankName = currentLang === 'th' ? row.bank_name_th : row.bank_name_en;
                    const accountCell = row.bank_account_id
                        ? escapeHtml(`${bankName || ''} - ${row.bank_account_name || ''}`)
                        : `<span class="text-danger">${langData['bank_account_unassigned'] || 'No account configured'}</span>`;
                    // 2026-09-20, 3e-1 (rules.md §5: "Badge = สถานะเท่านั้น ไม่ใช่ label ทั่วไป (ประเภท,
                    // หมวด, ที่มา -> เป็นข้อความธรรมดาหรือคอลัมน์)"). WHERE this employee's paying
                    // account was resolved from is provenance, not a state that can be acted on, so
                    // it cannot go through statusBadgeHtml() either -- the rule's own answer for this
                    // case is plain text. The override case stays distinguishable by weight, not by
                    // colour: it is the only one of the 4 a human set deliberately on this run.
                    const sourceLabel = langData[RD_BANK_ACCOUNT_SOURCE_LABEL_KEY[row.source]] || row.source;
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group.
                    let actionBtns = `<button type="button" class="btn btn-link btn-circle-action btn-bank-account-edit" data-employee-id="${row.employee_id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>`;
                    if (row.is_overridden) {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-bank-account-remove" data-employee-id="${row.employee_id}" title="${langData['bank_account_remove_override'] || 'Remove Override'}"><i class="fa-solid fa-rotate-left"></i></button>`;
                    }
                    return `<tr>
                        <td>${escapeHtml(row.employee_no)}</td>
                        <td>${name}</td>
                        <td>${accountCell}</td>
                        <td class="text-center"><span class="${row.is_overridden ? 'fw-semibold' : 'text-muted'}">${escapeHtml(sourceLabel)}</span></td>
                        <td class="text-center"><div class="d-flex gap-1 justify-content-center">${actionBtns}</div></td>
                    </tr>`;
                }).join(''));
            },
        });
    });
}
$(document).on('click', '.btn-bank-account-edit', function () {
    const employeeId = $(this).data('employee-id');
    const row = rdBankAccountRows.find(r => Number(r.employee_id) === Number(employeeId));
    if (!row) return;
    $('#bankAccountAssignEmployeeId').val(employeeId);
    // 2026-09-11, Batch 3C item 8: employeeHeaderCardHtml() (app.js) replaces the old plain
    // "Employee: {name}" line -- rdBankAccountRows' own rows already carry profile_photo_path/
    // department_name_*/position_name_* (PayrollRunEmployeeBankAccountModel::listForRun()).
    $('#bankAccountAssignHeaderCard').html(employeeHeaderCardHtml(row));
    $('#bankAccountAssignNote').val('');
    const $select = $('#bankAccountAssignSelect').empty();
    if (row.bank_account_id) {
        const bankName = currentLang === 'th' ? row.bank_name_th : row.bank_name_en;
        const option = new Option(`${bankName || ''} - ${row.bank_account_name || ''}`, row.bank_account_id, true, true);
        $select.append(option);
    }
    $select.trigger('change');
    new bootstrap.Modal(document.getElementById('bankAccountAssignModal')).show();
});
$(document).on('click', '#btnSaveBankAccountAssign', function () {
    const employeeId = $('#bankAccountAssignEmployeeId').val();
    const bankAccountId = $('#bankAccountAssignSelect').val();
    if (!bankAccountId) {
        showWarning(langData['bank_account_select_required'] || 'Please select a bank account.');
        return;
    }
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run-employee-bank-account.save`, method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: employeeId, bank_account_id: bankAccountId, note: $('#bankAccountAssignNote').val() }),
        dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            bootstrap.Modal.getInstance(document.getElementById('bankAccountAssignModal')).hide();
            loadRunBankAccountTab();
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
$(document).on('click', '.btn-bank-account-remove', function () {
    const employeeId = $(this).data('employee-id');
    showConfirm(
        langData['bank_account_remove_override'] || 'Remove Override',
        langData['confirm_bank_account_remove_message'] || 'Revert this employee back to the default paying account for this run?',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-run-employee-bank-account.remove`, method: 'POST',
                contentType: 'application/json', data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: employeeId }), dataType: 'json',
                success: function (res) {
                    if (!res.status) {
                        showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                        return;
                    }
                    loadRunBankAccountTab();
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
            });
        }
    );
});
$(document).on('click', '#btnExportRunBankAccountSummary', function () {
    if (!PAYROLL_RUN_ID) return;
    const params = new URLSearchParams();
    params.set('report_code', 'BANK_ACCOUNT_PAYMENT_SUMMARY');
    params.set('format', 'excel');
    params.set('run_id', PAYROLL_RUN_ID);
    params.set('source', 'payroll_process_detail');
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});
// 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 5 -- one grouped row per
// destination (PayrollRemittanceModel::generateForRun(), created right after this run is Approved).
// Same ALLOWED_STATES gate/pattern as loadRunCashTab() right above -- see that function's own
// comment for why the client-side state check exists at all (purely to show the right empty-state
// message without a round trip; the server enforces this independently on every mutating action).
let rdRemittanceRows = [];
function rdRemittanceDestinationLabel(row) {
    if (row.destination_type === 'company') {
        return langData['remittance_destination_company'] || 'Company';
    }
    if (row.destination_type === 'employee_fallback') {
        const name = (currentLang === 'th' ? `${row.fallback_name_th || ''} ${row.fallback_surname_th || ''}` : `${row.fallback_name_en || ''} ${row.fallback_surname_en || ''}`).trim();
        return `${name}${row.fallback_employee_no ? ` (${row.fallback_employee_no})` : ''}`;
    }
    const bankName = currentLang === 'th' ? row.bank_name_th : row.bank_name_en;
    return `${row.destination_account_name || '-'}${bankName ? ` - ${bankName}` : ''}`;
}
function rdRemittanceDestinationTypeLabel(type) {
    return langData[`remittance_destination_type_${type}`] || type;
}
function loadRunRemittanceTab() {
    if (!PAYROLL_RUN_ID || !currentRun) return;
    const isReady = RD_REPORT_ALLOWED_STATES.includes(currentRun.state);
    $('#runRemittanceNotReady').toggleClass('d-none', isReady);
    $('#runRemittanceContent').toggleClass('d-none', !isReady);
    if (!isReady) return;
    $.getJSON(`${BASE_URL}/api/payroll-remittance.list`, { run_id: PAYROLL_RUN_ID }, function (res) {
        if (!res.status) {
            $('#runRemittanceNotReady').removeClass('d-none').find('#runRemittanceNotReadyMessage').text(res.message || '');
            $('#runRemittanceContent').addClass('d-none');
            return;
        }
        rdRemittanceRows = res.data || [];
        const totals = { pending: 0, transferred: 0, success: 0, failed: 0 };
        rdRemittanceRows.forEach(row => { totals[row.status] = (totals[row.status] || 0) + Number(row.total_amount); });
        $('#runRemittanceTotalPending').text(fmtNum(totals.pending));
        $('#runRemittanceTotalTransferred').text(fmtNum(totals.transferred));
        $('#runRemittanceTotalSuccess').text(fmtNum(totals.success));
        $('#runRemittanceTotalFailed').text(fmtNum(totals.failed));
        // 2026-09-11, Batch 3C item 6: see loadRunCashTab()'s own comment -- empty tbody + DataTables'
        // own language.emptyTable, not an inline placeholder <tr> counted as real data; row-rendering
        // handed to initSharedDataTable() as `renderRows` (destroy/render/construct order owned by
        // that function now). Amount/Transferred At carry data-order (raw amount / raw ISO
        // timestamp), same reason as Cash Payments' own Amount/Paid At columns.
        tb_run_remittance_dt = initSharedDataTable('#tb_run_remittance', {
            searchThreshold: 5,
            emptyState: { icon: 'fa-solid fa-money-bill-transfer', title: langData['no_remittances'] || 'No third-party remittances for this run.' },
            renderRows: function () {
                $('#runRemittanceTableBody').html(rdRemittanceRows.map(row => {
                    // 2026-09-20, 3e-1 (§5): RD_REMITTANCE_STATUS_BADGE (a class map living in this
                    // file, exactly what §5 forbids) retired in favour of status_map.php's own
                    // 'remittance_status' context, which already carried these 4 values and the same
                    // 4 label keys. One tone really changes: 'transferred' was blue
                    // (`bg-primary-subtle`), the map says warning -- §3 does not use blue at all, and
                    // "transferred, not yet confirmed" is genuinely still waiting on someone.
                    const badge = statusBadgeHtml(row.status, 'remittance_status');
                    const failedNote = row.status === 'failed' && row.note ? `<div class="text-danger small">${escapeHtml(row.note)}</div>` : '';
                    const transferredAtCell = row.transferred_at ? formatDisplayDateTime(row.transferred_at) : '-';
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group.
                    let actionBtns = `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-remittance-breakdown" data-id="${row.id}" title="${langData['remittance_view_breakdown'] || 'View Breakdown'}"><i class="fa-solid fa-list"></i></button>`;
                    if (row.status === 'pending') {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action btn-remittance-mark-transferred" data-id="${row.id}" title="${langData['mark_as_transferred'] || 'Mark as Transferred'}"><i class="fa-solid fa-paper-plane"></i></button>`;
                    } else if (row.status === 'transferred') {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action btn-remittance-confirm-success" data-id="${row.id}" title="${langData['remittance_confirm_success'] || 'Confirm Success'}"><i class="fa-solid fa-circle-check"></i></button>`;
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-danger btn-remittance-mark-failed" data-id="${row.id}" title="${langData['mark_as_failed'] || 'Mark as Failed'}"><i class="fa-solid fa-circle-xmark"></i></button>`;
                    } else if (row.status === 'failed') {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-remittance-retry" data-id="${row.id}" title="${langData['retry'] || 'Retry'}"><i class="fa-solid fa-rotate-left"></i></button>`;
                    }
                    return `<tr>
                        <td>${escapeHtml(rdRemittanceDestinationLabel(row))}</td>
                        <td>${escapeHtml(rdRemittanceDestinationTypeLabel(row.destination_type))}</td>
                        <td class="text-center">${Number(row.employee_count) || 0}</td>
                        <td data-order="${Number(row.total_amount) || 0}">${fmtNum(row.total_amount)}</td>
                        <td class="text-center">${badge}${failedNote}</td>
                        <td data-order="${row.transferred_at || ''}">${transferredAtCell}</td>
                        <td class="text-center"><div class="d-flex gap-1 justify-content-center">${actionBtns}</div></td>
                    </tr>`;
                }).join(''));
            },
        });
    });
}
$(document).on('click', '#btnExportRunRemittance', function () {
    if (!PAYROLL_RUN_ID) return;
    const params = new URLSearchParams();
    params.set('report_code', 'THIRD_PARTY_REMITTANCE_SUMMARY');
    params.set('format', 'excel');
    params.set('run_id', PAYROLL_RUN_ID);
    params.set('source', 'payroll_process_detail');
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`);
});
$(document).on('click', '.btn-remittance-breakdown', function () {
    const id = $(this).data('id');
    $.getJSON(`${BASE_URL}/api/payroll-remittance.items`, { id }, function (res) {
        if (!res.status) return;
        $('#remittanceBreakdownTableBody').html((res.data || []).map(item => {
            const name = escapeHtml((currentLang === 'th' ? `${item.name_th} ${item.surname_th}` : `${item.name_en} ${item.surname_en}`).trim());
            return `<tr>
                <td>${escapeHtml(item.employee_no)}</td>
                <td>${name}</td>
                <td>${escapeHtml(item.item_code)}</td>
                <td class="num col-money">${fmtNum(item.amount)}</td>
            </tr>`;
        }).join(''));
        new bootstrap.Modal(document.getElementById('remittanceBreakdownModal')).show();
    });
});
$(document).on('click', '.btn-remittance-mark-transferred', function () {
    $('#remittanceMarkTransferredId').val($(this).data('id'));
    $('#remittanceEvidenceFile').val('');
    new bootstrap.Modal(document.getElementById('remittanceMarkTransferredModal')).show();
});
$(document).on('click', '#btnConfirmMarkTransferred', function () {
    const id = $('#remittanceMarkTransferredId').val();
    const fileInput = document.getElementById('remittanceEvidenceFile');
    if (!fileInput.files.length) {
        showWarning(langData['remittance_evidence_required'] || 'Please attach transfer evidence.');
        return;
    }
    const formData = new FormData();
    formData.append('id', id);
    formData.append('evidence', fileInput.files[0]);
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-remittance.mark-transferred`, method: 'POST',
        data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            bootstrap.Modal.getInstance(document.getElementById('remittanceMarkTransferredModal')).hide();
            loadRunRemittanceTab();
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
$(document).on('click', '.btn-remittance-confirm-success', function () {
    const id = $(this).data('id');
    showConfirm(
        langData['remittance_confirm_success'] || 'Confirm Success',
        langData['confirm_remittance_success_message'] || 'Confirm this transfer completed successfully?',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-remittance.confirm-success`, method: 'POST',
                contentType: 'application/json', data: JSON.stringify({ id }), dataType: 'json',
                success: function (res) {
                    if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                    loadRunRemittanceTab();
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
            });
        }
    );
});
$(document).on('click', '.btn-remittance-mark-failed', function () {
    $('#remittanceMarkFailedId').val($(this).data('id'));
    $('#remittanceFailedNote').val('');
    new bootstrap.Modal(document.getElementById('remittanceMarkFailedModal')).show();
});
$(document).on('click', '#btnConfirmMarkFailed', function () {
    const id = $('#remittanceMarkFailedId').val();
    const note = $('#remittanceFailedNote').val().trim();
    if (!note) {
        showWarning(langData['remittance_failed_reason_required'] || 'A reason is required.');
        return;
    }
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-remittance.mark-failed`, method: 'POST',
        contentType: 'application/json', data: JSON.stringify({ id, note }), dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            bootstrap.Modal.getInstance(document.getElementById('remittanceMarkFailedModal')).hide();
            loadRunRemittanceTab();
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
$(document).on('click', '.btn-remittance-retry', function () {
    const id = $(this).data('id');
    showConfirm(
        langData['retry'] || 'Retry',
        langData['confirm_remittance_retry_message'] || 'Revert this back to pending so it can be retried?',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-remittance.retry`, method: 'POST',
                contentType: 'application/json', data: JSON.stringify({ id }), dataType: 'json',
                success: function (res) {
                    if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                    loadRunRemittanceTab();
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
            });
        }
    );
});
// 2026-09-02, explicit request: "เพิ่มให้ Export เป็น PDF ได้ด้วย...การ Export กดแล้ว แสดงตัวอย่าง แล้ว
// ค่อยเลือกจะ Download ภาษาไทยหรือภาษาอังกฤษ" -- extracted out of the `.btn-report-preview` handler
// below (unchanged in every other way) so PAYROLL_REGISTER's own dedicated "Export PDF" button
// (#btnPreviewRunRegisterPdf) can reuse the EXACT same preview-then-choose-language-download modal
// every other report on this page already uses, without needing to be a row inside the generic
// Reports-tab table (rdReportsRows) at all -- see this file's own historical comment on why
// PAYROLL_REGISTER was deliberately excluded from that table in the first place (it already has its
// own dedicated Export Excel button next to the Timeline; this PDF button sits right beside it).
function rdOpenReportPreview(row) {
    rdReportPreviewContext = { code: row.code, format: row.format };
    $('#reportPreviewModalTitle').text(rdReportLabel(row));
    // 2026-08-29, same-day follow-up, real bug found and fixed (explicit report: "เหมือนมี iframe
    // แสดงอยู่ด้วยทำให้ Word ถูกดันลงมา" -- an iframe seems to be showing too, pushing [the
    // "can't preview"] text down). Root cause: the iframe's own 'load' handler (below, in the
    // supports_preview branch) was only ever rebound with .off('load').on('load', ...) on a
    // SUPPORTED preview -- clicking an unsupported report right after a supported one left that
    // PRIOR handler still attached. Clearing the iframe's src to '' (a couple lines below) still
    // navigates it to about:blank, which fires its own 'load' event -- the stale handler then ran
    // $frame.removeClass('d-none'), silently un-hiding the now-empty iframe at the same time the
    // "can't preview" card rendered underneath it, pushing that card down exactly as reported.
    // Fixed by unbinding unconditionally, every open, regardless of which branch runs next.
    const $frame = $('#reportPreviewFrame').off('load').addClass('d-none').attr('src', '');
    const $loading = $('#reportPreviewLoading').removeClass('d-none');
    const $unavailable = $('#reportPreviewUnavailable').addClass('d-none');
    // 2026-08-29, same-day follow-up: "ไม่พอดีกับ modal สูงเกินไป" -- modal-xl is only meaningful
    // while there's an actual PDF to show at 70vh tall; a report with nothing to preview gets the
    // plain (smaller) dialog size instead, so the empty-state card isn't dwarfed by an oversized
    // modal.
    $('#reportPreviewDialog').toggleClass('modal-xl', row.supports_preview);
    new bootstrap.Modal(document.getElementById('reportPreviewModal')).show();
    if (!row.supports_preview) {
        $loading.addClass('d-none');
        $unavailable.removeClass('d-none');
        return;
    }
    const params = new URLSearchParams();
    params.set('report_code', row.code);
    params.set('format', row.format);
    params.set('run_id', PAYROLL_RUN_ID);
    params.set('language', currentLang === 'en' ? 'en' : 'th');
    params.set('preview', '1');
    $frame.on('load', function () {
        $loading.addClass('d-none');
        $frame.removeClass('d-none');
    });
    $frame.attr('src', `${BASE_URL}/api/report.generate?${params.toString()}`);
}
$(document).on('click', '.btn-report-preview', function () {
    const row = rdReportsRows.find(r => r.code === $(this).data('code'));
    if (!row) return;
    rdOpenReportPreview(row);
});
$(document).on('click', '#btnPreviewRunRegisterPdf', function () {
    rdOpenReportPreview({
        code: 'PAYROLL_REGISTER',
        format: 'pdf',
        supports_preview: true,
        label: { th: 'ทะเบียนรายได้-รายหักพนักงาน', en: 'Payroll Register' },
    });
});
$(document).on('click', '.btn-report-download', function () {
    if (!rdReportPreviewContext) return;
    const params = new URLSearchParams();
    params.set('report_code', rdReportPreviewContext.code);
    params.set('format', rdReportPreviewContext.format);
    params.set('run_id', PAYROLL_RUN_ID);
    params.set('language', $(this).data('language') || 'th');
    // 2026-08-29: "Download จากที่ไหน" -- which screen triggered this download, purely descriptive
    // (see ReportExportLogModel::log()'s own docblock), not an access-control signal.
    params.set('source', 'process_detail');
    generateReport(`${BASE_URL}/api/report.generate?${params.toString()}`, loadRunReportsTab);
});
// 2026-08-29, same-day follow-up: "ประวัติการ Download ให้เป็น Datatable และ Filter ช่วงวันที่ได้" -- was a
// plain hand-rendered <tbody>, now a real DataTable (client-side ajax+dataSrc, same convention this
// project already uses for a small run-scoped list e.g. manual-entry's own attendance/leave/overtime
// tables) filterable by date range (on generated_at, see ReportExportLogModel::list()'s own new
// date_from/date_to filter). One report row's history at a time -- destroyed+recreated on every open
// since the report_code filter itself changes per row, not just reloaded in place.
let dtReportHistory = null;
let rdReportHistoryCode = null;
function rdReportByLabel(l) {
    return (currentLang === 'th' ? l.generated_by_name_th : l.generated_by_name_en) || l.generated_by_name_th || l.generated_by_name_en || '-';
}
function rdReportLanguageLabel(l) {
    if (l.language === 'en') return langData['language_en'] || 'English';
    if (l.language === 'th') return langData['language_th'] || 'Thai';
    return '-';
}
function reloadReportHistoryTable() {
    if (dtReportHistory) { dtReportHistory.ajax.reload(null, false); }
}
function updateReportHistoryClearFilterVisibility() {
    const active = !!($('#reportHistoryDateFrom').val() || $('#reportHistoryDateTo').val());
    $('#reportHistoryFilterClearRow').toggleClass('d-none', !active);
}
$(document).on('click', '.btn-report-history', function () {
    const row = rdReportsRows.find(r => r.code === $(this).data('code'));
    if (!row) return;
    rdReportHistoryCode = row.code;
    $('#reportHistoryModalTitle').text(`${langData['report_view_history'] || 'View Download History'} - ${rdReportLabel(row)}`);
    $('#reportHistoryDateFrom, #reportHistoryDateTo').val('');
    updateReportHistoryClearFilterVisibility();
    initDatepicker('#reportHistoryDateFrom');
    initDatepicker('#reportHistoryDateTo');
    new bootstrap.Modal(document.getElementById('reportHistoryModal')).show();
    if (dtReportHistory) { dtReportHistory.destroy(); dtReportHistory = null; }
    dtReportHistory = $('#tb_report_history').DataTable({
        responsive: true,
        order: [[0, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/report.export-logs`,
            dataSrc: 'data',
            data: function (d) {
                d.report_code = rdReportHistoryCode;
                d.payroll_run_id = PAYROLL_RUN_ID;
                d.date_from = toIsoDateRd($('#reportHistoryDateFrom').val());
                d.date_to = toIsoDateRd($('#reportHistoryDateTo').val());
            },
        },
        columns: [
            // object-form render: client-side sort/filter must use the raw ISO datetime (sorts
            // correctly as a string already), not the dd/mm/yyyy display string -- same gotcha
            // documented in this project's own date-format-audit history.
            { data: 'generated_at', render: { display: (v) => formatDisplayDateTime(v), sort: (v) => v, filter: (v) => v } },
            { data: null, render: (l) => escapeHtml(rdReportByLabel(l)) },
            { data: null, render: (l) => escapeHtml(rdReportLanguageLabel(l)) },
            { data: null, render: (l) => escapeHtml(rdReportDeviceLabel(l)) },
            { data: null, render: (l) => escapeHtml(rdReportBrowserLabel(l)) },
            { data: 'ip_address', render: (v) => escapeHtml(v || '-') },
            { data: 'source', render: (v) => escapeHtml(v || '-') },
        ],
        // 2026-08-30, real gap found and fixed (full-codebase pageLength audit) -- was missing
        // entirely, silently falling back to DataTables' own built-in default of 10.
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        initComplete: function () {
            // 2026-08-29, same-day follow-up: system-wide table audit punch-list item -- every
            // categorical column here (By/Language/Device/Browser/IP/Source) is a real filter
            // candidate that had none at all; the date-range fields above already cover Date/Time.
            // mode:'client' since this table is loaded whole (not serverSide:true) even though the
            // date range itself is filtered server-side -- the Excel-filter operates on whatever
            // rows are currently loaded, same as tb_payroll_run's own client-side station filter.
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 1, key: 'downloaded_by' },
                    { index: 2, key: 'language' },
                    { index: 3, key: 'device' },
                    { index: 4, key: 'browser' },
                    { index: 5, key: 'ip_address' },
                    { index: 6, key: 'source' },
                ]
            });
        },
    });
});
$(document).on('click', '#reportHistoryStationFilterToggle', function () {
    const $filter = $('#reportHistoryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#reportHistoryDateFrom, #reportHistoryDateTo', function () {
    updateReportHistoryClearFilterVisibility();
    reloadReportHistoryTable();
});
$(document).on('click', '#btnReportHistoryClearFilter', function () {
    $('#reportHistoryDateFrom, #reportHistoryDateTo').val('');
    updateReportHistoryClearFilterVisibility();
    reloadReportHistoryTable();
});
function renderSectionButtons(run) {
    const $editWrap = $('#runEditButtonWrap').empty();
    // 2026-09-14, Round 3 "เก็บตกรอบ 6": #autoRecalculateWrap now holds ONE settingRowHtml() render
    // (see the draft-only branch below) instead of static switch+banner markup -- .empty() clears it
    // out on every reset, same as before, just one call covering the whole thing now.
    $('#autoRecalculateWrap').addClass('d-none').empty();
    // 2026-09-09: reset here, BEFORE the early return below, same reason as the 2 lines above it --
    // #btnJoinEmployees/#btnBulkVerify/#btnVerifyAllEmployees live in the DataTable's own
    // .dt-search/.dt-length (injected once, outside this function entirely -- see
    // initRunDetailTable()'s initComplete), so unlike a plain .empty()-then-rebuild wrap these have
    // to be explicitly hidden every call or they'd keep showing whatever visibility a PREVIOUS call
    // left them at once a run leaves draft. #btnBulkVerify's own ENABLED/disabled state (as opposed
    // to shown/hidden) is a separate concern owned by updateRunDetailBulkBar() instead -- untouched
    // here. 2026-09-13, Round 3 item 3a: #btnRecalculate moved OUT of this table toolbar into
    // page-header.php's own #phActions (secondary "ส่งออก ▾") -- its visibility is now controlled
    // entirely by whether computeRunHeaderActions() includes it for the current state, not by a
    // d-none toggle here anymore. #btnVerifyAllEmployees was ALSO tried in the header (overflow "อื่นๆ
    // ▾") in a first cut of this change, then moved back here after review -- it's a table-scoped
    // bulk action like #btnBulkVerify right next to it, not a run-level state transition.
    $('#btnJoinEmployees, #btnBulkVerify, #btnVerifyAllEmployees').addClass('d-none');
    // 2026-09-11, Batch 3C item 4 sub-step 4d, explicit instruction: #btnEditRun is no longer
    // draft-only -- applyRunFieldLockUi() (app.js) already handles a non-draft run correctly (locks
    // everything except notes, shows a summary explaining why), so there was never a reason a
    // non-draft run should have NO way at all to reach that one still-editable field. Moved above the
    // early return below -- everything else in this function (Join/Recalculate/Bulk Verify/Verify
    // All, the auto-recalculate checkbox/reminder banner) stays draft-only, unchanged.
    $editWrap.append(`<button type="button" id="btnEditRun" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="action_edit">${langData['action_edit'] || 'Edit'}</span></button>`);
    if (run.state !== 'draft') {
        return;
    }
    // 2026-09-09, explicit request across 3 follow-up rounds -- final layout: "เอาคำนวณใหม่ไปวางต่อ
    // search แล้วตามด้วย ปุ่ม Add พนักงาน...แล้วเอาปุ่ม Verify All มาไว้ต่อจาก ตรวจสอบแล้ว" -- #btnJoinEmployees/
    // #btnBulkVerify/#btnVerifyAllEmployees are injected ONCE into the Employee table's own
    // `.dt-search`/`.dt-length` (initRunDetailTable()'s initComplete, see that function's own comment
    // for the exact left-to-right order), matching this app's own established
    // "Add"-button-in-search-bar convention (CLAUDE.md's Table convention) now that this table
    // finally has real search/length controls. Shown on EVERY draft run (2026-08-21/2026-08-31
    // explicit requests) -- already reset to hidden above (before the early return), so this branch
    // (only reached when run.state === 'draft') just un-hides them again. (#btnRecalculate: see this
    // function's own 2026-09-13 comment above -- no longer toggled here, lives in the header now.)
    $('#btnJoinEmployees, #btnBulkVerify, #btnVerifyAllEmployees').removeClass('d-none');

    // 2026-08-31, explicit request: auto-recalculate checkbox + description, draft-only (see
    // PayrollRunModel::setAutoRecalculate()'s own docblock). Checkbox always visible once a run is
    // draft.
    // 2026-09-14, Round 3 "เก็บตกรอบ 6": rebuilt via the new shared settingRowHtml() (§9/§11,
    // setting-row.php) instead of static switch markup + a separately-toggled reminder div -- the
    // description now ALWAYS shows one of desc_on/desc_off (never hidden entirely the way the old
    // reminder-banner-only-while-off design worked), matching the component's own spec. `checked:
    // !!run.auto_recalculate` decides BOTH the switch's initial state and which description renders
    // first -- no separate .prop('checked', ...) + reminder-render call needed anymore, one function
    // builds the whole row consistently.
    // 2026-09-14, same day "เก็บตกรอบ 7": `variant` left UNSET on purpose -- this page has exactly
    // ONE setting here, so it gets the (now-default) `plain` treatment automatically (no box, one
    // flat `[switch] label · description` line flush against the filter-bar's own left edge below
    // it) per §9's own "1-2 settings -> plain, 3+ stacked -> card" rule -- pass
    // `variant: 'card'` explicitly if this section ever grows to 3+ stacked settings.
    $('#autoRecalculateWrap').removeClass('d-none').html(settingRowHtml({
        id: 'chkAutoRecalculate',
        label: langData['auto_recalculate_label'] || 'Automatically recalculate right after editing data',
        desc_on: langData['recalc_desc_on'] || 'The system will calculate right away whenever data is edited.',
        desc_off: langData['recalc_desc_off'] || 'If you edit data, click <b>Recalculate</b> yourself every time.',
        checked: !!run.auto_recalculate,
    }));
}

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
    if (Number(run.include_attendance_pay) === 1) parts.push(langData['include_attendance_pay_short'] || langData['include_attendance_pay_label'] || 'Attendance pay');
    const label = langData['run_purpose_incentive'] || 'Incentive / Other Payment (no base salary)';
    return parts.length ? `${label} (${parts.join(', ')})` : label;
}
// 2026-09-11, Batch 3C item 4 sub-step 4a: updateEditRunTypeVisibility() itself is gone -- merged
// into app.js's shared updateComputeStatutoryVisibility() (its own `isEditing` branch reproduces
// this function's exact prior behavior: #run_use_flat_tax_rate_row shown whenever incentive is
// selected, unlike Create's own narrower rule). That shared function is already bound to
// #run_purpose's own 'change' event in app.js -- no separate binding needed here.

// 2026-09-11, Batch 3C item 5, explicit instruction: hide the Cash Payments/Bank Account Assignment/
// Third-Party Remittance tabs entirely when the run has nothing for them to show, rather than
// leaving them showing an empty "not ready"/"no rows" state -- computed straight off the SAME
// api/payroll-run.get response renderRunHeader() already has in hand: run.details' own
// payment_method_code per employee (already sent, no new field needed -- isCashishPaymentMethod()/
// isBankishPaymentMethod() below are the same 2 helpers registerPaymentMethodSearchFilter() already
// uses, so "cash-ish"/"bank-ish" can never drift between the filter and this visibility check) for
// the first two, and the new run.remittance_count field (PayrollRunModel::get(), a real COUNT()
// query -- remittance rows live in a wholly separate table not reachable from run.details at all)
// for the third. Called from renderRunHeader() itself, which runs on every full run reload
// (recalculate/verify/approve/etc. all funnel back through loadRunDetail() -> renderRunHeader() per
// this page's own established pattern) -- "ประเมินใหม่หลัง recalculate" falls out for free with no
// extra wiring needed. If the tab that's currently active gets hidden this way, switches to the
// Employee Breakdown tab (there's always at least one employee row once a run has anything to show
// at all, so that tab is never itself a candidate for hiding).
function updateRunDetailTabVisibility(run) {
    const details = run.details || [];
    const cashCount = details.filter(d => isCashishPaymentMethod(d.payment_method_code || 'transfer')).length;
    const transferCount = details.filter(d => isBankishPaymentMethod(d.payment_method_code || 'transfer')).length;
    const remittanceCount = Number(run.remittance_count || 0);
    const visibility = [
        { tabId: 'run-cash-tab', show: cashCount > 0 },
        { tabId: 'run-bank-account-tab', show: transferCount > 0 },
        { tabId: 'run-remittance-tab', show: remittanceCount > 0 },
    ];
    let activeTabHidden = false;
    visibility.forEach(function (v) {
        const $tabBtn = $('#' + v.tabId);
        $tabBtn.closest('li').toggleClass('d-none', !v.show);
        if (!v.show && $tabBtn.hasClass('active')) activeTabHidden = true;
    });
    if (activeTabHidden) {
        const $employeeTab = document.getElementById('run-employee-tab');
        if ($employeeTab) bootstrap.Tab.getOrCreateInstance($employeeTab).show();
    }
}
// 2026-09-14, Round 3 "เก็บตกรอบ 6" item 1, real bug found and fixed (explicit report: switching to
// EN left the stepper labels + "next step" callout stuck in Thai) -- root cause confirmed by reading
// the actual render path, not guessed: `renderRunHeaderText()` below (extracted verbatim out of
// `renderRunHeader()`, zero behavior change) builds ALL of this text as plain JS template strings via
// `langData[key] || fallback` -- e.g. `nextStepBanner()`'s own returned HTML is written directly into
// `#nextStepBanner` with NO `data-i18n` marker anywhere (confirmed: grepping this whole function finds
// none), same for the stepper (`renderProcessTimeline()`, built from `runLifecycleSteps()`'s own
// labels). `updateText()` (app.js's central language sweep, runs on every `changeLanguage()` call) can
// only ever find and fix a genuine `[data-i18n]` element -- it has no way to reach text that was
// already baked into a template string at some EARLIER point and never re-hooked. Since `run` data
// loads ONCE (via `loadRunDetail()`, gated behind the page's OWN initial `langReady` so the FIRST
// render is always correct) and nothing previously called `renderRunHeader()`/this text-only subset of
// it AGAIN on a later language switch, everything built here stayed frozen in whatever language was
// active the moment the run first loaded -- exactly the symptom reported. Same root cause, same
// established fix pattern this app already uses on ~6 other pages for this exact class of bug (see
// `changeLanguage()`'s own series of `if (typeof someRefreshLanguage === 'function') ...` hooks,
// app.js) -- `refreshPayrollDetailLanguage()` (bottom of this file) is Payroll Detail's own missing
// hook, calling this function again (pure/no side effects -- no AJAX, no data mutation, safe to
// re-run any number of times) plus a defensive re-sync of the Employee Breakdown table's own column
// headers (see that function's own docblock for why that 2nd part exists even though these ARE plain
// `data-i18n` `<th>` elements that should already be covered by the generic sweep).
function renderRunHeaderText(run) {
    // 2026-09-03, Platform UX review Phase 3: document.title used to be set directly here to JUST
    // run.run_name (losing the "Payroll Process —" breadcrumb prefix and the app suffix entirely) --
    // app.js's own MutationObserver/updateDocumentTitleFromBreadcrumb() now derives the full title
    // automatically (breadcrumb parts + #phTitle's own text) the moment either one's text changes
    // below, so this no longer needs (or should) set it itself.
    // 2026-09-13, §2 REVISED AGAIN (supersedes the earlier "crumb สุดท้าย = ชนิดหน้า, static label,
    // never touched by JS" decision entirely -- that one is gone, not just this page's own use of it):
    // the last breadcrumb crumb is now the entity's own CODE (#phBreadcrumbCurrent = run.run_code),
    // and $title/#phTitle is the entity's own DISPLAY NAME (run.run_name) -- 2 genuinely different
    // pieces of information again, not a duplicate-avoidance trick. Both get the SAME defensive
    // fallback chain the run's own Code column (payroll/index.js's runCodeCellHtmlPr()) already
    // established for a possibly-null run_code (payroll_runs.run_code is nullable by design, confirmed
    // in PayrollRunModel's own comment -- "a null run_code here is a completely normal, harmless
    // outcome"): code falls back to `#{id}` if genuinely missing; the H1 falls back to the CODE itself
    // if `run_name` is somehow empty (accepted duplication in that one edge case only, per explicit
    // instruction -- "ถ้าไม่มีชื่อ H1 = รหัส ยอมซ้ำได้").
    const runCodeOrFallback = run.run_code || ('#' + run.id);
    $('#phBreadcrumbCurrent').text(runCodeOrFallback);
    $('#phTitle').text(run.run_name || runCodeOrFallback);
    $('#phTitleBadge').html(stateBadgeRd(run.state));
    // #phDescription (2026-09-13, item 3a follow-up item 5, revises the FIRST cut's "งวด · วันจ่าย"
    // decision) -- §2's own rule: "description ของ page header = ข้อมูล 1 ชิ้นที่สำคัญสุดพร้อมคำนำหน้า
    // ไม่ใช่รายการตัวเลข" -- the period range was dropped (it already lives in the Details tab's own
    // #infoPeriod, and in the stat cards' context, not a second place this needs repeating), leaving
    // ONE labeled value: the payment date, using the same toDisplayDateRd() formatting #infoPaymentDate
    // (Details tab, unchanged, still set below) already uses.
    const phDescText = `${langData['run_payment_date_label'] || 'Payment date'} ${toDisplayDateRd(run.payment_date)}`;
    $('#phDescription').text(phDescText).removeClass('d-none');
    $('#infoCycle').text(run.cycle_name || langData['offcycle_run_short'] || 'Off-schedule');
    $('#infoPeriod').text(`${toDisplayDateRd(run.period_start_date)} - ${toDisplayDateRd(run.period_end_date)}`);
    $('#infoPaymentDate').text(toDisplayDateRd(run.payment_date));
    $('#infoEmployeeCount').text(run.employee_count);
    $('#infoGross').text(fmtNum(run.total_gross_amount));
    $('#infoDeduction').text(fmtNum(run.total_deduction_amount));
    $('#infoNet').text(fmtNum(run.total_net_amount));
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
        $('#rejectReasonBox').removeClass('d-none').html(`<i class="fa-solid fa-circle-exclamation me-1"></i><strong>${langData['reject_reason_display'] || 'Reject Reason'}:</strong> ${escapeHtml(run.reject_reason)}`);
    } else {
        $('#rejectReasonBox').addClass('d-none').html('');
    }
    if (run.state === 'cancelled' && run.cancel_reason) {
        $('#cancelReasonBox').removeClass('d-none').html(`<i class="fa-solid fa-ban me-1"></i><strong>${langData['cancel_reason_display'] || 'Cancel Reason'}:</strong> ${escapeHtml(run.cancel_reason)}`);
    } else {
        $('#cancelReasonBox').addClass('d-none').html('');
    }

    const banner = nextStepBanner(run);
    if (banner.html) {
        $('#nextStepBanner').attr('class', `callout callout-${banner.tone}`).html(banner.html);
    } else {
        $('#nextStepBanner').attr('class', 'd-none').html('');
    }

    if (Number(run.has_validation_errors) === 1) {
        const errCount = (run.details || []).filter(d => d.calc_status === 'error').length;
        const tpl = langData['validation_errors_banner'] || '{count} employee(s) have calculation errors. Recalculate and resolve them before submitting for approval.';
        $('#validationErrorsBanner').removeClass('d-none').text(tpl.replace('{count}', errCount));
    } else {
        $('#validationErrorsBanner').addClass('d-none');
    }

    renderProcessTimeline(run);
    renderRunHeaderActions(run);
    renderSectionButtons(run);
}
// The original renderRunHeader(run) -- sets currentRun, calls the pure text-render subset above, then
// everything else (AJAX-driven sub-tab loads, settings panel) that must run once per real data load,
// NOT on every language switch (see renderRunHeaderText()'s own docblock for why the split).
function renderRunHeader(run) {
    currentRun = run;
    renderRunHeaderText(run);
    updateRunDetailTabVisibility(run);
    loadRunReportsTab();
    loadRunCashTab();
    loadRunBankAccountTab();
    loadRunRemittanceTab();
    renderRunSettingsPanel(run);
    loadSyncMissingEmployeesBanner(run);
    renderMergeTargetBanner(run);
}

// 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึง
// รอบ" -- shown whenever this run was created with a merge target set and is still eligible to
// merge (draft, genuinely off-cycle -- matches PayrollRunModel::mergeIntoExistingRun()'s own
// server-side check exactly, so the button never appears somewhere the backend would just refuse
// anyway). No AJAX round trip needed -- merge_target_run_id/_name/_state all come back on the
// normal api/payroll-run.get payload already (see PayrollRunModel::get()'s own LEFT JOIN).
function renderMergeTargetBanner(run) {
    const eligible = !!run.merge_target_run_id && run.state === 'draft' && !run.cycle_id && !run.sync_process_id;
    // 2026-09-06: the "future cycle" merge-target form -- merge_target_cycle_id is set instead of
    // merge_target_run_id while genuinely waiting (see PayrollRunModel::resolveMergeTargetSpec()'s
    // own docblock) -- the two are mutually exclusive at the DB layer, so at most one banner ever
    // shows. No action button here (there's nothing to click yet -- PayrollRunModel::create()'s own
    // auto-detect resolves this into the "ready" banner above automatically once the real round
    // gets created, no admin action needed to notice it happened).
    const waiting = !eligible && !!run.merge_target_cycle_id && run.state === 'draft' && !run.cycle_id && !run.sync_process_id;
    $('#mergeTargetBanner').toggleClass('d-none', !eligible);
    $('#mergeTargetWaitingBanner').toggleClass('d-none', !waiting);
    if (eligible) {
        const tpl = langData['merge_target_banner_text'] || 'This run is set to merge into "{target}" once ready.';
        $('#mergeTargetBannerText').text(tpl.replace('{target}', run.merge_target_run_name || `#${run.merge_target_run_id}`));
    }
    if (waiting) {
        const cycleLabel = run.merge_target_cycle_name || `#${run.merge_target_cycle_id}`;
        const periodLabel = (run.merge_target_period_start_date && run.merge_target_period_end_date)
            ? `${formatDisplayDate(run.merge_target_period_start_date)} - ${formatDisplayDate(run.merge_target_period_end_date)}`
            : '';
        // 2026-09-06, real gap found and fixed: the target cycle may have been deactivated/deleted
        // since this spec was set -- create() requires status='active' to create a new run against
        // it, so this is a genuine dead end, same category as the sync-side 'target_rejected'
        // status -- swapped to a danger-styled alert with no "it'll resolve on its own" implication.
        const cycleInactive = run.merge_target_cycle_status && run.merge_target_cycle_status !== 'active';
        // 2026-09-20, 3e-1 round 1: the box is a callout now (see its markup in detail.php), so the
        // tone swap is `callout-warning`/`callout-danger`. The icon line that went with it is gone --
        // rules.md 15: a callout carries its meaning in the left border and has no icon at all.
        $('#mergeTargetWaitingBanner').toggleClass('callout-warning', !cycleInactive).toggleClass('callout-danger', cycleInactive);
        const tpl = cycleInactive
            ? (langData['merge_target_waiting_banner_inactive_text'] || 'The target Payroll Cycle "{cycle}" was deactivated or deleted -- this will never merge automatically. Edit this run to pick a different merge target.')
            : (langData['merge_target_waiting_banner_text'] || 'This run is waiting to merge into the next round of "{cycle}" ({period}), once it\'s created.');
        $('#mergeTargetWaitingBannerText').text(tpl.replace('{cycle}', cycleLabel).replace('{period}', periodLabel));
    }
}

/* 2026-08-30 (Phase 8, T041) -- reconciliation warning for a sync-based run. Draft-only (same
   convention as the Run Settings panel just below -- once submitted, the roster is effectively
   locked in for this run; the warning would just be stale noise past that point) and sync-only
   (api/payroll-run.sync-missing-employees itself returns [] for anything else, but skipping the
   fetch entirely for a cycle-based/off-cycle run avoids a pointless round trip on every page load). */
function loadSyncMissingEmployeesBanner(run) {
    if (!run.sync_process_id || run.state !== 'draft') {
        $('#syncMissingEmployeesBanner').addClass('d-none');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.sync-missing-employees`,
        method: 'GET',
        data: { id: run.id },
        success: function (res) {
            const list = (res && res.status && Array.isArray(res.data)) ? res.data : [];
            if (list.length === 0) {
                $('#syncMissingEmployeesBanner').addClass('d-none');
                return;
            }
            const tpl = langData['sync_missing_employees_banner'] || '{count} employee(s) who would normally be expected in this payroll were NOT in this Origami sync -- verify whether their data has arrived yet before submitting.';
            $('#syncMissingEmployeesBannerText').text(tpl.replace('{count}', list.length));
            // 2026-09-22, n: the `.data('list', list)` this line used to also stash was read by
            // exactly one thing -- the Swal list below, now gone (the button opens the real picker
            // instead, which fetches its own rows server-side and paginates/filters them). Dropped
            // rather than left behind: a cached copy nothing reads is a second source of truth for
            // who is missing, and it would go stale the moment anyone is pulled in.
            $('#syncMissingEmployeesBanner').removeClass('d-none');
        },
        error: function () {
            $('#syncMissingEmployeesBanner').addClass('d-none');
        },
    });
}
// 2026-09-22, n: "ดูรายชื่อ" used to open a read-only Swal list and stop there -- seeing who the sync
// left out and doing something about it were two separate screens, and the second one (Join
// Employees) offered the WHOLE company with no way to narrow it to the missing few. It now opens the
// same picker in `missing` mode instead: one list, filterable/paginated like every other table in
// the app, with the pull action on the rows themselves. See openJoinEmployeesModalRd().
$(document).on('click', '#syncMissingEmployeesViewBtn', function () {
    openJoinEmployeesModalRd('missing');
});

/* ---------- "Run Settings" panel (2026-08-29) -- see PayrollRunModel::runSettingsGet()'s own
   docblock. Draft-only (hidden entirely once a run has moved on, same convention as the bulk
   Verify/Lock bar) -- the per-employee override lives in each row's own "Items" -> "Tax & SSO"
   tab instead (manageLinesCalcPane above). ---------- */
// 2026-08-29, same-day follow-up: "รายรับให้เป็นสีเขียว รายจ่ายให้เป็นสีแดง และแยกกรอบกันอยู่ครับ" -- shared
// between the Run Settings panel's own checklist AND the per-employee "Exclude from This Employee's
// Calculation" checklist (empItemExclusionRowHtml used to duplicate this same shape) so both stay
// visually consistent. `opts.isDisabled`/`opts.disabledBadgeHtml` are optional -- only the
// per-employee checklist uses them (an item already excluded by the run-level default).
function itemChecklistRowHtml(item, opts) {
    const name = currentLang === 'th' ? (item.item_name_th || item.item_name_en) : (item.item_name_en || item.item_name_th);
    const checked = opts.isChecked(item);
    const disabled = !!(opts.isDisabled && opts.isDisabled(item));
    const badge = disabled && opts.disabledBadgeHtml ? opts.disabledBadgeHtml(item) : '';
    const safeId = `${opts.idPrefix}_${item.item_code}`.replace(/[^a-zA-Z0-9_-]/g, '_');
    const wasCheckedAttr = opts.trackWasChecked ? ` data-was-checked="${checked ? '1' : '0'}"` : '';
    return `<div class="form-check mb-1">
        <input class="form-check-input ${opts.checkboxClass}" type="checkbox" value="${escapeHtml(item.item_code)}"${wasCheckedAttr} id="${safeId}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
        <label class="form-check-label small" for="${safeId}">${escapeHtml(name)}${badge}</label>
    </div>`;
}
// 2026-08-29, same-day follow-up: "เป็น item 2 column หรือ 3 column ก็ได้ครับ และไม่ต้องมี Scroll และปรับ
// Design ให้ไม่โดดไปจากหน้าเท่าไหร่ได้ไหม" -- two changes from the previous round: (1) items within
// each box now flow into 2-3 CSS columns (.item-checklist-cols, see style.css) instead of one long
// vertical list, so a normal-sized catalog fits without scrolling at all -- the outer scroll
// container was removed too (see the view's own #runSettingsItemChecklist/#empItemExclusionChecklist
// markup). (2) the saturated bg-success-subtle/bg-danger-subtle/bg-warning-subtle fill from the
// PREVIOUS round was toned down to a plain white box + colored header text/icon only, matching this
// SAME page's own pre-existing Income/Deductions panels in the Manage Items modal (.ped-type-panel,
// border rounded-3 h-100, no background tint) -- keeps the green/red distinction the user asked for
// without introducing a bolder color treatment than the rest of this page already uses elsewhere.
function itemChecklistBoxesHtml(itemOptions, opts) {
    const baseSalaryItems = itemOptions.filter(i => i.item_type === 'base_salary');
    const earningItems = itemOptions.filter(i => i.item_type === 'earning');
    const deductionItems = itemOptions.filter(i => i.item_type === 'deduction');
    const rowsHtml = items => items.length
        ? `<div class="item-checklist-cols">${items.map(item => itemChecklistRowHtml(item, opts)).join('')}</div>`
        : `<div class="text-muted small">-</div>`;
    const baseSalaryHtml = baseSalaryItems.length ? `<div class="border rounded-3 p-2 mb-2">
        <div class="fw-bold small text-warning-emphasis mb-1"><i class="fa-solid fa-sack-dollar me-1"></i>${langData['table_base_salary'] || 'Base Salary'}</div>
        ${rowsHtml(baseSalaryItems)}
    </div>` : '';
    return `${baseSalaryHtml}<div class="row g-2">
        <div class="col-md-6">
            <div class="border rounded-3 p-2 h-100">
                <div class="fw-bold small text-success mb-1"><i class="fa-solid fa-arrow-trend-up me-1"></i>${langData['breakdown_earnings'] || 'Income'}</div>
                ${rowsHtml(earningItems)}
            </div>
        </div>
        <div class="col-md-6">
            <div class="border rounded-3 p-2 h-100">
                <div class="fw-bold small text-danger mb-1"><i class="fa-solid fa-arrow-trend-down me-1"></i>${langData['table_deduction_amount'] || 'Deductions'}</div>
                ${rowsHtml(deductionItems)}
            </div>
        </div>
    </div>`;
}
// 2026-08-29, same-day follow-up: "ในหน้า Process Detail แบบ View Mode จะต้องบอกรายละเอียด ของการตั้งค่ารอบ
// ด้วยครับ" -- this panel used to be hidden ENTIRELY once a run left draft; now stays visible
// (read-only -- every control disabled, Save hidden) so an approved/paid/locked run's own Run
// Settings are still visible for reference, matching this page's general "View Mode disables
// controls, doesn't hide them" convention.
// 2026-08-29, same-day follow-up: "ให้แสดงเป็นภาพรวมเลยครับ โดยที่ไม่ต้องเปิด toggle มาดู และให้ขึ้นเฉพาะรายการที่
// เลือก ถ้าไม่เลือกก็ให้แสดงคำให้ถูกต้องครับว่าเงื่อนไขเป็นแบบไหน" -- plain text for whichever ONE Tax/SSO
// condition was actually picked (never all 3 radio choices, never blank -- "Each Employee's Own
// Setting" is itself a real, correctly-worded condition, not an absence of one).
function runSettingsConditionHtml(value, yesKey, yesFallback, noKey, noFallback) {
    if (value === 'yes') return `<span class="text-success fw-semibold"><i class="fa-solid fa-check me-1"></i>${langData[yesKey] || yesFallback}</span>`;
    if (value === 'no') return `<span class="text-danger fw-semibold"><i class="fa-solid fa-xmark me-1"></i>${langData[noKey] || noFallback}</span>`;
    return `<span class="text-secondary"><i class="fa-solid fa-users me-1"></i>${langData['calc_default_use_employee'] || "Each Employee's Own Setting"}</span>`;
}
// "ให้ขึ้นเฉพาะรายการที่เลือก" -- only the items genuinely ticked as excluded, not the full checklist;
// "ถ้าไม่เลือกก็ให้แสดงคำให้ถูกต้อง" -- a correct sentence (not a blank box) when nothing is excluded.
function runSettingsExcludedItemsSummaryHtml(itemOptions, excludedCodes) {
    if (!excludedCodes.length) {
        return `<div class="text-muted small"><i class="fa-solid fa-circle-check me-1 text-success"></i>${langData['run_settings_no_excluded_items'] || "Nothing is excluded -- every item is included in this run's calculation."}</div>`;
    }
    // 2026-09-20, 3e-1 (rules.md §5: "Badge = สถานะเท่านั้น ไม่ใช่ label ทั่วไป (ประเภท, หมวด, ที่มา
    // -> เป็นข้อความธรรมดาหรือคอลัมน์)"). These pills carried an ITEM NAME coloured by its item_type --
    // a category, and one that statusBadgeHtml() structurally cannot render either (that helper draws
    // the label from status_map.php, and the label here is a row of real data). §5's own answer for
    // this case is plain text, so that is what this is now: the same names, in the same order, read
    // as the list they always were. Nothing here was ever a state anyone could act on.
    const excluded = itemOptions.filter(item => excludedCodes.includes(item.item_code));
    const names = excluded.map(item => escapeHtml((currentLang === 'th' ? item.item_name_th : item.item_name_en) || item.item_code));
    return `<div class="small">${names.join(', ')}</div>`;
}
function renderRunSettingsSummary(d) {
    const excludedCodes = d.excluded_item_codes || [];
    $('#runSettingsSummary').html(`
        <div class="row g-3">
            <div class="col-md-6">
                <div class="text-muted small mb-1">${langData['run_exemption_tax'] || 'Tax Calculation'}</div>
                ${runSettingsConditionHtml(d.tax_calculate_default, 'run_calc_tax_yes', 'Calculate for Everyone', 'run_calc_tax_no', "Don't Calculate for Anyone")}
            </div>
            <div class="col-md-6">
                <div class="text-muted small mb-1">${langData['run_exemption_sso'] || 'SSO Contribution'}</div>
                ${runSettingsConditionHtml(d.sso_calculate_default, 'run_calc_sso_yes', 'Send for Everyone', 'run_calc_sso_no', "Don't Send for Anyone")}
            </div>
        </div>
        <div class="mt-3">
            <div class="text-muted small mb-1">${langData['run_settings_excluded_items'] || 'Exclude from Calculation'}</div>
            ${runSettingsExcludedItemsSummaryHtml(d.item_options || [], excludedCodes)}
        </div>
    `);
}
function loadRunSettingsPanel() {
    const isDraft = !!currentRun && currentRun.state === 'draft';
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.run-settings-get`,
        method: 'GET',
        data: { id: PAYROLL_RUN_ID },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            const d = res.data || {};
            // 2026-09-09, explicit request: "การตั้งค่าของรอบ ให้ expand ได้เลยไม่ต้องหุบแล้ว เพราะมีพื้นที่
            // ว่างแล้วครับ" -- #runSettingsBody used to always start collapsed (a click on
            // #runSettingsToggle's chevron was needed to see it, both ids now removed from the view
            // entirely) because this panel used to compete with the Employee table for space on the
            // same tab; now that Run Settings has its own "Details" tab all to itself (see
            // app/views/payroll/detail.php's own tab-split comment), it's simply always expanded for
            // a draft run instead. Non-draft still shows the read-only overview (renderRunSettingsSummary())
            // and never populates/shows this editable form at all -- unchanged.
            $('#runSettingsSummary').toggleClass('d-none', isDraft);
            $('#runSettingsBody').toggleClass('d-none', !isDraft);
            if (!isDraft) {
                renderRunSettingsSummary(d);
                return;
            }
            $(`#runCalcTaxGroup input[value="${d.tax_calculate_default || 'use_employee_setting'}"]`).prop('checked', true);
            $(`#runCalcSsoGroup input[value="${d.sso_calculate_default || 'use_employee_setting'}"]`).prop('checked', true);
            $('#runCalcTaxGroup input, #runCalcSsoGroup input').prop('disabled', false);
            const excludedCodes = d.excluded_item_codes || [];
            // 2026-09-09, explicit request: "รายการที่ติ๊กจะไม่ถูกนำมาคำนวณ...ให้เป็นติ๊ก Default ติ๊กออกคือ
            // ไม่เอาครับ" -- checkbox meaning flipped from "ticked = excluded" to "ticked = included/
            // calculated normally" (every item defaults to ticked unless it's already in the saved
            // excluded_item_codes list, in which case it correctly renders UNticked under the new
            // meaning) -- see the Save handler below for the matching flip on collection. The
            // underlying `excluded_item_codes` VALUE is unchanged (still literally "codes that are
            // excluded"), so `itemChecklistBoxesHtml()` itself and every OTHER caller of it (the
            // per-employee "Exclude from This Employee's Calculation" checklist at
            // loadEmpItemExclusionChecklist(), which intentionally keeps its own "ticked = excluded"
            // meaning) needed zero changes.
            $('#runSettingsItemChecklist').html(itemChecklistBoxesHtml(d.item_options || [], {
                checkboxClass: 'run-settings-item-check',
                idPrefix: 'rsItem',
                isChecked: item => !excludedCodes.includes(item.item_code),
            }));
            $('#btnSaveRunSettings').removeClass('d-none');
        }
    });
}
function renderRunSettingsPanel() {
    $('#runSettingsPanel').removeClass('d-none');
    loadRunSettingsPanel();
}
$(document).on('click', '#btnSaveRunSettings', function () {
    const $btn = $(this);
    setButtonLoading($btn, true);
    // 2026-09-09: matches the "ticked = included" flip in loadRunSettingsPanel() above -- the codes
    // to actually send as excluded are now whichever boxes are NOT checked, not the checked ones.
    const excludedItemCodes = $('.run-settings-item-check').not(':checked').map(function () { return $(this).val(); }).get();
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.run-settings-save`,
        method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({
            id: PAYROLL_RUN_ID,
            tax_calculate_default: $('#runCalcTaxGroup input:checked').val() || 'use_employee_setting',
            sso_calculate_default: $('#runCalcSsoGroup input:checked').val() || 'use_employee_setting',
            excluded_item_codes: excludedItemCodes,
        }),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

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
// 2026-09-10, Batch 3A item 3: apvAvatarImgErrorRd/apvAvatarHtmlRd/apvPersonLineHtmlRd/
// APV_COLORS_RD/apvBadgeHtmlRd/apvIconHtmlRd moved to app.js's own apvAvatarImgError()/
// apvAvatarHtml()/apvPersonLineHtml()/APV_COLORS/apvBadgeHtml()/apvIconHtml() -- confirmed
// byte-identical across index.js/detail.js/approval.js before merging.
// 2026-09-11, Batch 3C item 1, explicit instruction: "รวม render ทั้งหมดเป็น function เดียวใน app.js
// ที่ทุกหน้าเรียก" -- apvApproverToneRd()/apvApproverLabelRd()/apvApproverSubstepHtmlRd()/
// apvStepDotToneRd()/apvStepDotsHtmlRd()/apvStepGroupHtmlRd()/apvApprovalStageHtmlRd() (the Ap/Pr
// twins in approval.js/index.js were confirmed byte-identical before merging) moved to app.js's own
// unsuffixed apvApproverTone()/apvApproverLabel()/apvApproverSubstepHtml()/apvStepDotTone()/
// apvStepDotsHtml()/apvStepGroupHtml()/apvApprovalStageHtml() -- see renderRunTimelineModal() below,
// now built entirely from app.js's own renderApprovalTimelineBody(). renderAuditTimelineRd() (this
// modal's own condensed "History" section) is gone too, per the same instruction ("ตัด section
// ประวัติออกจากทุกที่ (ประวัติอยู่ใน tab ของ Detail ที่เดียว)") -- it duplicated this exact tab's own
// Action History section, which is now the ONLY place this run's action history renders anywhere
// in the app (2026-09-22, 3e-3 round B1: that section is initAuditLogTableRd()'s own DataTable now,
// not auditHistoryRowHtmlRd() -- see that function's own docblock further down this file).
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
        buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-lock"><i class="fa-solid fa-check-double me-1"></i>${langData['action_verify_run'] || 'Verify'}</button>`);
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
    // 2026-09-11, Batch 3C item 1: body is now app.js's own shared renderApprovalTimelineBody() --
    // see that function's own docblock for the station order/lifecycle-reuse reasoning. The
    // "History" section that used to follow it (renderAuditTimelineRd()) is gone -- see this file's
    // own comment above apvApproverTone() for why.
    $('#runTimelineModalBody').html(renderApprovalTimelineBody(run));
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
    const message = isPending ? (langData['confirm_revert_to_draft_message'] || 'It will return to draft so the submitter can make changes.') : (langData['confirm_undo_decision_message'] || 'This payroll run will go back to Waiting for Approval.');
    showConfirm(title, message, function () {
        const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
        if (inst) inst.hide();
        callRunAction('/api/payroll-run.revert', {}, langData['save_success']);
    });
});
$(document).on('click', '.btn-tl-lock', function (e) {
    e.stopPropagation();
    showConfirm(langData['confirm_verify_run_title'] || 'Verify this payroll run?', langData['confirm_verify_run_message'] || 'Once verified, this run can no longer be recalculated.', function () {
        const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
        if (inst) inst.hide();
        callRunAction('/api/payroll-run.lock', {}, langData['save_success']);
    });
});
// 2026-08-29, explicit request: "รายการที่ติ๊กว่าทำจ่ายแล้ว หรือปิดรอบไปแล้ว สามารถเปิดให้กลับมาแก้ไขได้และ
// ส่งอนุมัติใหม่ได้ครับ" -- see PayrollRunModel::reopen()'s own docblock for why this is a real,
// sensitive state change (undoes markPaid()'s own installment-consumption side effect too), hence
// the stronger warning-style confirm text rather than the plain confirm Lock above uses.
$(document).on('click', '.btn-tl-reopen', function (e) {
    e.stopPropagation();
    showConfirm(
        langData['confirm_reopen_title'] || 'Reopen this payroll run?',
        langData['confirm_reopen_message'] || 'This will move the run back to Draft so its numbers can be corrected. Any linked loan/installment deductions this run already consumed will be un-consumed. You will need to submit it for approval again.',
        function () {
            const inst = bootstrap.Modal.getInstance(document.getElementById('runTimelineModal'));
            if (inst) inst.hide();
            callRunAction('/api/payroll-run.reopen', {}, langData['save_success']);
        }
    );
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
    // 2026-09-18, 4b: one of the row's 3 circles, not a ⋮ item -- this table has 3 actions left and
    // §7's "≤3 ปุ่ม + ⋮" therefore has nothing to fold. NO tone class: §5 gives red to STATUS, not to an
    // action, and §7 keeps every row-action icon neutral anyway -- a `.text-danger` here would have
    // been markup that says one thing while the page renders another. The trash glyph and the confirm
    // are what say this is destructive.
    const label = langData['action_remove'] || 'Remove';
    return `<button type="button" class="btn btn-link btn-circle-action btn-remove-manual-employee" data-employee-id="${row.employee_id}" title="${escapeAttr(label)}" aria-label="${escapeAttr(label)}"><i class="fa-solid fa-trash-can"></i></button>`;
}
// Breakdown button always shows (any state) -- it's read-only, unlike the other buttons which only
// make sense while draft. Grouped into one Bootstrap button-group -- same
// .btn-group.border.rounded-3.bg-white + btn-link idiom as every other row-actions table in the app
// (2026-08-21, explicit request: "ปรุงหน้า Process Detail...ให้เป็นรูปแบบเดียวกัน" -- this table was
// converted to a button-group earlier the same day but with a different btn-outline-* sub-style,
// left it the one inconsistent holdout after the sitewide sweep standardized everything else to
// this exact pattern; matched here now).
// 2026-08-29, explicit request: "อยากให้มีปุ่ม Verify ของแต่ละคน และสามารถ Lock Unlock ได้", then split
// out the same day: "ปุ่ม verify กับ Lock แยกออกมาอีก 1 Column ครับ" -- own button in the dedicated
// "Verify" column (initRunDetailTable()'s column 10), not bundled into the general Actions group.
// 2026-08-31, explicit follow-up: "ตัดปุ่ม Lock ออกไปเลยครับ ให้เหลือแค่ Verify ถ้า Verify แล้ว จะไม่คำนวณ
// อีกต่อไป" -- Lock removed entirely; Verify itself now carries the "freeze from recalculation,
// refuse further edits" behavior Lock used to have (see PayrollRunModel::isEmployeeVerifiedForRun()).
// Available on any draft run only (same gating as removeEmployeeButtonRd());
// the button's own current-state is read back off `data-*` by the click handler (.btn-verify-
// employee) so a toggle click always flips whatever the row is CURRENTLY showing, not a stale value
// captured at render time. Read-only when the run isn't draft -- shows a plain badge instead.
// 2026-09-13, Round 3 item 3b follow-up -- the ⋮ menu's own "Unverify" item (verified draft rows
// only); rendered now as the DROPDOWN MENU CONTENT OF THE BADGE ITSELF (statusBadgeHtml()'s own new
// {menu:...} option, see verifyLockButtonsRd() below and app.js's own docblock on that option) rather
// than a row of the row-action ⋮ menu -- reuses the EXACT same .btn-verify-employee class +
// data-employee-id/-name/-verified attrs the column's own button used to carry when showing the
// verified state, so the existing delegated click handler needs no change at all to serve this new
// location -- it already reads data-verified off whichever element was clicked.
function verifyMenuItemRd(row, currentlyVerified) {
    const label = currentlyVerified
        ? (langData['action_unverify'] || 'Unverify')
        : (langData['action_verify'] || 'Verify');
    return `<li><button type="button" class="dropdown-item btn-verify-employee" data-employee-id="${row.employee_id}" data-employee-name="${escapeAttr(employeeDisplayNameRd(row))}" data-verified="${currentlyVerified ? 'true' : 'false'}">${escapeHtml(label)}</button></li>`;
}
// Available on any draft run only (same gating as removeEmployeeButtonRd()); the
// button's own current-state is read back off `data-*` by the click handler (.btn-verify-employee) so
// a toggle click always flips whatever the row is CURRENTLY showing, not a stale value captured at
// render time. Read-only when the run isn't draft -- shows a plain badge instead.
// 2026-09-13, Round 3 item 3b, real fix (explicit instruction: "'ตรวจสอบแล้ว': badge success + ไอคอน ▾
// เล็กต่อท้าย...กดแล้วเปิด dropdown รายการ 'ยกเลิกการตรวจสอบ' (draft เท่านั้น; non-draft ไม่มี ▾)") -- the
// verified badge now passes verifyMenuItemRd(row, true)'s own HTML as statusBadgeHtml()'s {menu} option ONLY
// while draft (the ternary below is what
// decides whether the ▾/dropdown-toggle machinery renders AT ALL -- a non-draft verified row gets the
// exact same plain, non-interactive badge as before, no menu option passed).
function verifyLockButtonsRd(row) {
    // 2026-09-15, rules.md 7: a status column whose value can be CHANGED from the table is a badge
    // with a caret (badgeDropdownHtml(), via statusBadgeHtml()'s own {menu} option) in BOTH states --
    // never a badge for one state and a button for the other. Read-only (non-draft run) renders the
    // same 2 badges without the caret.
    if (!currentRun || currentRun.state !== 'draft') {
        return row.is_verified
            ? statusBadgeHtml('verified', 'verify_status')
            : statusBadgeHtml('unverified', 'verify_status', { outline: true });
    }
    if (row.is_verified) {
        return statusBadgeHtml('verified', 'verify_status', { menu: verifyMenuItemRd(row, true) });
    }
    return statusBadgeHtml('unverified', 'verify_status', { outline: true, menu: verifyMenuItemRd(row, false) });
}
// 2026-09-14, Round 3 "เก็บตกรอบ 5" item 2, real bug found and fixed (explicit report: this column's
// own Excel-style filter list showed "ยกเลิกการตรวจสอบ" -- the hidden ⋮-menu item's OWN text, not a
// real filter value) -- root cause: this column's own DataTables columnDef (initRunDetailTable()'s
// `columns[10]`) was a PLAIN render function (`render: (d,t,row) => verifyLockButtonsRd(row)`), not
// the object-form `{display, filter}` CLAUDE.md's own Table convention already mandates for any
// column whose displayed HTML differs from its filter/sort value -- table-column-filter.js's own
// `renderedCellText()` calls `dt.cell().render('filter')`, and DataTables falls back to the SAME
// single function for EVERY render type when no object-form is given, so 'filter' returned the exact
// same HTML `verifyLockButtonsRd()` builds for a verified+draft row: statusBadgeHtml({menu:...})'s own
// output, which embeds BOTH the visible badge label AND the hidden <ul class="dropdown-menu"> markup
// (verifyMenuItemRd()'s own "ยกเลิกการตรวจสอบ" <li>) as ONE HTML string (see that function's own
// docblock) -- stripping HTML off THAT string pulls the menu item's text in right along with the
// real label. Fixed by giving this column its own dedicated `filter` renderer (below) that returns
// ONLY the plain label text for each of the 4 states verifyLockButtonsRd() itself branches on --
// never the menu HTML -- and switching the column's own render option to object-form
// `{display, filter}` (initRunDetailTable()) so `.render('filter')` actually reaches this function
// instead of falling back to the display one. statusBadgeHtml() itself is UNCHANGED -- its own
// {menu} option's return shape (one HTML string, used as the CELL'S DISPLAY content by every one of
// its many other callers across the app) stays exactly as documented; the fix lives entirely in this
// column's own render split, not in the shared badge helper.
function verifyLockFilterTextRd(row) {
    return row.is_verified
        ? (langData['verify_status_verified'] || 'Verified')
        : (langData['verify_status_unverified'] || 'Not verified');
}
// Comment always available (any state) -- same reasoning as the Breakdown button (read-only/non-
// destructive, "ไว้เตือนตัวเอง" -- a reminder note is useful regardless of where the run currently is).
// 2026-08-29: "ถ้ามีการใส่ Comment ไปกี่ Comment แล้วให้แสดงตัวเลขที่ปุ่ม Comment ด้วยเป็นจุดแดงๆเหมือนการ
// แจ้งเตือน" -- a small count badge showing the current comment count, read from row.comment_count (see
// initRunDetailTable()'s ajax/data source -- PayrollRunModel::getDetails() now includes it per
// employee). Re-rendered after every add/edit/delete via loadRunDetail(), same refresh pattern every
// other mutating action on this page already uses.
// 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "row action: โชว์ 3 ปุ่มวงกลม...
// [ความคิดเห็น (count)]..." -- was a dropdown-item; now one of the row's 3 standalone .btn-circle-action
// circles, count rendered as countBadgeHtml() (§5's own neutral-count-badge rule) overlaid on the
// circle's own top-right corner (.btn-circle-action-badge, style.css) rather than inline text, since a
// 32px icon-only circle has no room for a text label next to the number. Click handler
// (.btn-comment-employee) unchanged.
// 2026-09-13, same-day follow-up, explicit instruction: "tone primary เมื่อมีรายการ 'ใหม่/ยังไม่อ่าน'
// เท่านั้น...comment ยังไม่มี flag ยังไม่อ่าน → เทาไว้ก่อน" -- deliberately still the plain default call
// (no `{tone:'primary'}`) -- row.comment_count has no unread/read distinction anywhere in this run's
// data today (PayrollRunModel::getDetails() only ever returns a total count), so there is nothing to
// base a "new" tone on yet; passing 'primary' here now would just mean "always primary whenever
// count>0," not genuinely "unread," which is the opposite of what was asked. See BACKLOG.md ("Comment
// count badge has no unread/new tracking (Employee Breakdown row action)") for what unlocks this.
// The icon itself is NOT part of this decision -- it stays the single flat --c-text-muted §7 already
// mandates for every row-action icon regardless of the badge's own tone.
function commentButtonRd(row) {
    const count = Number(row.comment_count || 0);
    const countBadge = count > 0 ? `<span class="btn-circle-action-badge">${countBadgeHtml(count)}</span>` : '';
    return `<div class="position-relative d-inline-block">
        <button type="button" class="btn btn-link btn-circle-action text-warning btn-comment-employee" data-employee-id="${row.employee_id}" title="${langData['action_comments'] || 'Comments'}"><i class="fa-solid fa-comments"></i></button>
        ${countBadge}
    </div>`;
}
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group/border-start convention this
// whole cluster previously followed (2026-08-21/29).
// 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "row action: โชว์ 3 ปุ่มวงกลม [ดูรายละเอียด
// การคำนวณ] [ความคิดเห็น (count)] [ปรับรายการ] + ⋮ สำหรับที่เหลือ" -- was folded into the ⋮ menu's own
// first item earlier this same round (the "รวมเข้า ⋮" instruction), now un-folded back out as its own
// standalone circle -- unconditional (always available regardless of run state), same .btn-view-
// breakdown class the existing delegated click handler already binds to, unchanged.
// 2026-09-17, D3: carries the `adjustment_count` badge that used to sit on the Items circle. The
// badge belongs on the button that opens the place those adjustments are now MADE (this modal holds
// both the line-override table and the hand-added lines since D1/D2) -- it counts every
// per-employee adjustment on this run across all 5 tables (PayrollRunModel::getDetails()), which
// still includes the 3 that the settings modal's remaining tabs write. Neutral tone: §5 reserves
// `primary` for genuinely new/unread items, and "this employee has adjustments" is a standing fact.
function viewBreakdownButtonRd(row) {
    const count = Number(row.adjustment_count || 0);
    const countBadge = count > 0 ? `<span class="btn-circle-action-badge">${countBadgeHtml(count)}</span>` : '';
    // 2026-09-17, R1: `fa-receipt` -- what this opens is the employee's slip for this run, which is
    // what a receipt glyph says; the magnifier said "look something up" (§7: the icon has to mean
    // the thing, and it carries a tooltip either way).
    const label = langData['action_view_breakdown'] || 'View Breakdown';
    return `<div class="position-relative d-inline-block">
        <button type="button" class="btn btn-link btn-circle-action btn-view-breakdown" data-employee-id="${row.employee_id}" title="${label}" aria-label="${escapeAttr(label)}"><i class="fa-solid fa-receipt"></i></button>
        ${countBadge}
    </div>`;
}
/* 2026-09-18, 4b: 3 circles, no ⋮. The menu held 3 entries and each one left with what it opened:
   the per-employee settings modal and the read-only "รายการที่ปรับ" viewer are both gone (the slip is
   where an adjustment is made AND read now), and Raw Sync Data is a panel inside the slip itself
   now (3e-2b, see rawSyncPanelAvailableRd()) rather than an entry anywhere in this menu.
   What is left -- slip / comments / remove -- is exactly §7's "≤3 ปุ่ม", so there is nothing to fold
   and a ⋮ holding one item is a second click in front of one action. Remove is draft-only and simply
   absent otherwise; a non-draft row shows 2. */
function runDetailActionsRd(row) {
    const circles = [viewBreakdownButtonRd(row), commentButtonRd(row), removeEmployeeButtonRd(row)].filter(Boolean).join('');
    return `<div class="d-flex gap-1 align-items-center justify-content-center flex-nowrap">${circles}</div>`;
}

/* ---------- Formula popover (2026-08-29, explicit request: "ถ้าส่วนไหนที่เป็นสูตรการคำนวณให้มีปุ่มกดดูได้
   ว่าคำนวณจากอะไรเป็นอะไร แสดงผลสวยๆเป็น popup hover ตอนชี้และกด" -- extended the same day: "ประกันสังคม
   อยากให้เห็นสูตรคำนวณด้วยครับ และ OT ก็ให้เห็นสูตรคำนวณเลยว่า คำนวณจากอะไร ฐานเงินเดือนเท่าไหร่ / กี่วัน และ
   คูณกับอะไร ผลลัพธ์ออกมาเท่าไหร่ ให้เป็น Format นี้ทุกสูตรการคำนวณที่แสดงผล") -- a small info button next
   to any earning/deduction/statutory line, opening a Bootstrap popover (trigger "hover click" per
   the explicit request for both) with a numbered step-by-step breakdown. PRIMARY source is the
   line's own structured `.formula` field -- SyncPayResolver (OT/Late/Absent/Unpaid Leave/Leave
   Pending/Trip Allowance) and StatutoryCalculationEngine's computeFlatRate() (TH_SSO/TH_PVD) now
   attach this directly at computation time with the REAL numbers used (base salary, divisors, rate,
   hours, caps, etc.), not reverse-engineered from a terse note string. FALLBACK is the older
   note-string parser below, still used for anything without a structured formula yet (TH_PIT's
   own placeholder-vs-ThPitCalculator-corrected note, a few static engine notes) -- a line with
   neither gets no button at all rather than a misleading/empty popover. ---------- */
const FORMULA_EVENT_LABELS_RD = {
    // 2026-08-30, Phase 8 (T043) -- early_leave notes (e.g. "sync_early_leave_45minutes") have been
    // producible since SyncPayResolver's own early_leave fix, but had no translated label here at
    // all -- fell back to the raw, untranslated "early_leave" event key in this popover. Real bug,
    // found and fixed as part of this same round, not a UI addition tied to the new settings tab.
    late: 'formula_event_late', early_leave: 'formula_event_early_leave', absent: 'formula_event_absent',
    unpaid_leave: 'formula_event_unpaid_leave', leave_pending: 'formula_event_leave_pending',
    trip_allowance: 'formula_event_trip_allowance',
    weekday: 'formula_event_ot_weekday', weekend: 'formula_event_ot_weekend', holiday: 'formula_event_ot_holiday',
};
const FORMULA_UNIT_LABELS_RD = { minute: 'formula_unit_minutes', hour: 'formula_unit_hours', day: 'formula_unit_days' };
function formulaStepRd(text) {
    return `<li class="mb-1">${text}</li>`;
}
function formulaResultLineRd(amount) {
    // 2026-09-14, real bug found and fixed while centralizing popover styling (explicit instruction
    // covered "เนื้อ...ตัวเลข .num") -- `text-brand` colored the entire line orange, a §3 violation
    // (orange reserved for the 1 primary action/selected-state per screen, never a plain
    // informational number) and never used `.num`/a §8 money class at all. Now plain `--c-text` +
    // bold (`.money-net`'s own look -- this IS a bottom-line computed result, same semantic as a Net
    // Pay line) with the amount itself properly tagged `.num`.
    return `<div class="mt-2 pt-2 border-top fw-bold">${langData['formula_result'] || 'Result'}: <span class="num money-net">${fmtNum(amount)}</span></div>`;
}
/** @return string|null HTML step list (without the outer wrapper/title) or null if this formula type isn't recognized. */
function buildFormulaStepsRd(formula) {
    if (!formula) return null;
    const steps = [];
    switch (formula.type) {
        case 'ot_multiplier': {
            const scopeLabel = langData[FORMULA_EVENT_LABELS_RD[formula.scope]] || formula.scope;
            if (formula.is_daily_base) {
                steps.push(formulaStepRd(`${langData['formula_base_salary'] || 'Base salary'} ${fmtNum(formula.base_salary)} ÷ ${formula.days_divisor} ${langData['formula_unit_days'] || 'days'} = ${fmtNum(formula.unit_rate)} ${langData['formula_per_day'] || 'per day'}`));
                steps.push(formulaStepRd(`${fmtNum(formula.unit_rate)} × ${formula.multiplier} (${scopeLabel}) = ${fmtNum(formula.unit_rate * formula.multiplier)} ${langData['formula_per_day'] || 'per day'}`));
                steps.push(formulaStepRd(`${fmtNum(formula.unit_rate * formula.multiplier)} × (${formula.hours} ÷ ${formula.hours_divisor}) = ${fmtNum(formula.result)}`));
            } else {
                steps.push(formulaStepRd(`${langData['formula_base_salary'] || 'Base salary'} ${fmtNum(formula.base_salary)} ÷ ${formula.days_divisor} ${langData['formula_unit_days'] || 'days'} ÷ ${formula.hours_divisor} ${langData['formula_unit_hours'] || 'hours'} = ${fmtNum(formula.unit_rate)} ${langData['formula_per_hour'] || 'per hour'}`));
                steps.push(formulaStepRd(`${fmtNum(formula.unit_rate)} × ${formula.multiplier} (${scopeLabel}) = ${fmtNum(formula.unit_rate * formula.multiplier)} ${langData['formula_per_hour'] || 'per hour'}`));
                steps.push(formulaStepRd(`${fmtNum(formula.unit_rate * formula.multiplier)} × ${formula.hours} ${langData['formula_unit_hours'] || 'hours'} = ${fmtNum(formula.result)}`));
            }
            return steps.join('');
        }
        case 'ot_flat': {
            const scopeLabel = langData[FORMULA_EVENT_LABELS_RD[formula.scope]] || formula.scope;
            const qty = formula.is_daily_base ? (formula.hours / formula.hours_divisor) : formula.hours;
            const qtyUnit = formula.is_daily_base ? (langData['formula_unit_days'] || 'days') : (langData['formula_unit_hours'] || 'hours');
            steps.push(formulaStepRd(`${langData['formula_flat_rate'] || 'Flat rate'} (${scopeLabel}) ${fmtNum(formula.flat_rate)} × ${fmtNum(qty)} ${qtyUnit} = ${fmtNum(formula.result)}`));
            return steps.join('');
        }
        case 'flat_rate': {
            steps.push(formulaStepRd(`${langData['formula_eligible_base'] || 'Eligible base'} = ${fmtNum(formula.raw_base)}`));
            if ((formula.min_base !== null && formula.effective_base > formula.raw_base) || (formula.max_base !== null && formula.effective_base < formula.raw_base)) {
                steps.push(formulaStepRd(`${langData['formula_base_clamped'] || 'Clamped to configured min/max base'} (${langData['formula_min'] || 'min'} ${fmtNum(formula.min_base ?? 0)} / ${langData['formula_max'] || 'max'} ${formula.max_base !== null ? fmtNum(formula.max_base) : '-'}) = ${fmtNum(formula.effective_base)}`));
            }
            steps.push(formulaStepRd(`${fmtNum(formula.effective_base)} × ${formula.employee_rate}% = ${fmtNum(formula.employee_raw_amount)}`));
            if (formula.employee_capped) {
                steps.push(formulaStepRd(`${langData['formula_capped_at'] || 'Capped at the configured maximum contribution'} ${fmtNum(formula.max_employee_contribution)}`));
            }
            return steps.join('');
        }
        case 'attendance_percent': {
            steps.push(formulaStepRd(`${fmtNum(formula.hourly_rate)} ÷ 60 × ${formula.minutes} ${langData['formula_unit_minutes'] || 'minutes'} × ${formula.multiplier} = ${fmtNum(formula.result)}`));
            return steps.join('');
        }
        case 'attendance_flat': {
            const unitLabel = langData[FORMULA_UNIT_LABELS_RD[formula.rate_unit]] || formula.rate_unit;
            steps.push(formulaStepRd(`${fmtNum(formula.rate_per_unit)} / ${unitLabel} × ${fmtNum(formula.quantity_in_rate_unit)} ${unitLabel} = ${fmtNum(formula.result)}`));
            return steps.join('');
        }
        case 'attendance_bracket': {
            const unitLabel = langData[FORMULA_UNIT_LABELS_RD[formula.rate_unit]] || formula.rate_unit;
            steps.push(formulaStepRd(`${fmtNum(formula.quantity_in_rate_unit)} ${unitLabel} ${langData['formula_falls_in_bracket'] || 'falls in bracket'} ${formula.bracket_min}-${formula.bracket_max !== null ? formula.bracket_max : '∞'} = ${fmtNum(formula.result)}`));
            return steps.join('');
        }
        case 'passthrough': {
            steps.push(formulaStepRd(`${langData['formula_reported_value'] || 'Reported value'}: ${fmtNum(formula.raw_value)}${formula.unit ? ' ' + (langData[FORMULA_UNIT_LABELS_RD[formula.unit] || ''] || formula.unit) : ''} = ${fmtNum(formula.result)}`));
            return steps.join('');
        }
        // 2026-08-30 (T015, "เพิ่มตัวเลือก 'ไม่หัก'") -- in practice a no_deduction result (amount=0)
        // never actually reaches this modal today (the RULE_DRIVEN_ITEM_DEFS loop only adds a
        // deduction line when amount>0 or the employee is exempt, so a plain no_deduction
        // configuration produces no line to explain at all) -- added anyway for the same defensive
        // completeness every other formula type here already has, in case that gating logic is ever
        // extended to surface a zero-amount line for this method specifically.
        case 'attendance_no_deduction': {
            steps.push(formulaStepRd(langData['formula_no_deduction'] || 'This method always deducts 0.'));
            return steps.join('');
        }
        default:
            return null;
    }
}
function explainLineNoteRd(note, amount) {
    if (!note) return null;
    const syncMatch = note.match(/^sync_(.+?)_([\d.]+)(minutes|hours|days|money)(_corrected)?$/);
    if (syncMatch) {
        const [, eventKey, value, unit, corrected] = syncMatch;
        const eventLabelKey = FORMULA_EVENT_LABELS_RD[eventKey];
        const eventLabel = eventLabelKey ? (langData[eventLabelKey] || eventKey) : eventKey;
        const unitLabelKey = unit === 'money' ? null : (FORMULA_UNIT_LABELS_RD[unit.replace(/s$/, '')] || null);
        const unitLabel = unitLabelKey ? (langData[unitLabelKey] || unit) : '';
        const fromText = unit === 'money' ? fmtNum(value) : `${value} ${unitLabel}`;
        const correctedNote = corrected ? `<div class="small text-warning mt-1"><i class="fa-solid fa-pen me-1"></i>${langData['formula_manually_corrected'] || 'Manually corrected from the originally synced value'}</div>` : '';
        return `<div><strong>${eventLabel}</strong></div>
            <ol class="ps-3 mb-0 mt-1 small">${formulaStepRd(`${langData['formula_from'] || 'From'}: ${escapeHtml(fromText)}`)}</ol>
            ${formulaResultLineRd(amount)}
            ${correctedNote}`;
    }
    const knownNotes = {
        employee_not_enrolled: 'formula_note_not_enrolled',
        no_rate_configured: 'formula_note_no_rate_configured',
        no_rate_ever_configured: 'formula_note_no_rate_ever_configured',
    };
    if (knownNotes[note]) {
        return `<div class="small text-muted">${langData[knownNotes[note]] || note}</div>`;
    }
    const pitMatch = note.match(/^th_pit_(average|cumulative)_annual_tax_([\d.]+)$/);
    if (pitMatch) {
        const methodLabel = pitMatch[1] === 'average' ? (langData['formula_pit_method_average'] || 'Average method (est. annual tax spread evenly)') : (langData['formula_pit_method_cumulative'] || 'Cumulative method (actual tax-to-date)');
        return `<div><strong>${langData['formula_event_pit'] || 'Personal Income Tax'}</strong></div>
            <ol class="ps-3 mb-0 mt-1 small">
                ${formulaStepRd(methodLabel)}
                ${formulaStepRd(`${langData['formula_pit_annual_estimate'] || 'Estimated annual tax'}: ${fmtNum(pitMatch[2])}`)}
            </ol>
            ${formulaResultLineRd(amount)}`;
    }
    return null;
}
// 2026-09-19, 4c: the payee descriptor renderer that was here moved to public/js/payee-descriptor.js
// VERBATIM -- Employee Detail's own forms and tables show the same routing and could not reuse a
// renderer living inside this file. Same names, loaded before this script (layout/header.php).

// The row the Calculation Breakdown modal is currently showing -- kept module-level because the
// editable table below it reloads on its own (after a save) and has to know whose lines it is
// showing without the click that opened the modal still being on the stack.
let breakdownRowRd = null;
function renderBreakdownModal(row) {
    // 2026-09-16, D1: the row every later refresh reads from -- set before anything renders, so a
    // reload triggered by a save can never run against the previous employee's figures.
    breakdownRowRd = row;
    // 2026-09-11, Batch 3C item 8: employeeHeaderCardHtml() (app.js) block first, no more employee
    // name in the modal-header (#breakdownEmployeeName removed from the view -- see this modal's
    // own markup comment).
    // 2026-09-21, 3e-2b: the raw-sync disclosure lives in the card's own right slot. Reset FIRST,
    // then render -- the button is rebuilt with the card on every open, so the panel's own state has
    // to be cleared against the row that is arriving, never the one that just left.
    resetRawSyncPanelRd();
    $('#breakdownHeaderCard').html(employeeHeaderCardHtml(row, {
        actionHtml: rawSyncPanelAvailableRd(row) ? rawSyncPanelToggleHtmlRd() : '',
    }));
    // 2026-09-06, explicit request: Origami's opt-in TOTAL_DAYS item_values entry (calendar-based
    // day count) -- row.total_days is null (see PayrollRunModel::getDetails()'s own docblock) for
    // every run/employee with no data, never 0, so a plain truthiness-adjacent null check is
    // correct here (0 would be a real, displayable value if it ever happened).
    // 2026-09-14, Round 3 item 3c-2: moved out of the modal-header into the body (§9 "Header = ชื่อ
    // + × เท่านั้น") -- still ≤1 line, right under the employee header card.
    // 2026-09-16, explicit instruction ("quick-view แสดง calc_blocking เป็น callout danger /
    // calc_warnings เป็น callout warning ส่วนบน ข้อความเต็ม"): the row's own calculation view is where
    // the full sentences live now that the table cell only carries the count -- one callout per
    // message (§15: a callout is one statement; 3 stacked notes read as 3 things to act on, a single
    // callout holding 3 sentences reads as one). Blocking first: it is why the row says 'error'.
    // 2026-09-21, 3e-2a: both lists now come from the SAME 2 helpers the table cell's popovers use
    // (calcErrorItemsRd + calcAdvisoryCodesRd, format-helpers.js) -- the sentence in a callout here
    // and the sentence in the popover for the same row are the same string, by construction rather
    // than by two copies staying in step. data-code rides along for the same reason it does there.
    const calcNoteHtmlRd = (items, tone) => items.map(it =>
        calloutHtml(`<span data-code="${escapeAttr(it.code)}">${escapeHtml(it.message)}</span>`, tone)).join('');
    $('#breakdownCalcNotes').html(
        calcNoteHtmlRd(calcErrorItemsRd(row.calc_blocking), 'danger')
        + calcNoteHtmlRd(calcErrorItemsRd(calcAdvisoryCodesRd(row)), 'warning')
    );
    const $totalDays = $('#breakdownTotalDays');
    if (row.total_days !== null && row.total_days !== undefined) {
        $totalDays.text(`${langData['total_days'] || 'Total Days'}: ${fmtNum(row.total_days)}`).removeClass('d-none');
    } else {
        $totalDays.addClass('d-none').text('');
    }

    // 2026-09-18, 4a-1: both layouts are now the SAME table, from the same payload -- 'view' only
    // decides which columns are rendered at all (docs/decisions/2026-09-18-slip-single-place.md).
    // Height follows the content, in BOTH layouts. `.modal-tabbed`
    // (docs/decisions/2026-09-15-modal-tabbed-height.md) was tried here and removed: it pinned the
    // body at one height so a modal could not resize between tabs, but this modal has no tabs -- what
    // it bought was a stable height across saves, at the price that doc itself names for short
    // content (an employee with few lines left ~140px of empty modal under the net band, measured).
    // That class is retired app-wide as of 2026-09-17 (D3), so there is nothing left to opt out of.
    renderBreakdownFooterRd(employeeRowEditableRd(row));
    if (employeeRowEditableRd(row)) {
        renderBreakdownEditableBodyRd(row);
        return;
    }
    renderBreakdownViewBodyRd(row);
}
// 2026-09-17, D3: this modal's footer, built per open through the shared helper (§9/§4). A row that
// cannot be edited gets [ปิด] alone -- byte for byte what app.js's own `data-footer="view"` fallback
// used to inject before this modal owned a footer element. An editable row additionally gets §9's
// LEFT slot: "คืนค่าระบบทั้งหมด" (the one action that is neither the way out nor a save, since
// every edit in this modal already writes immediately) plus the sequential-restore progress line,
// both kept away from [ปิด] on the right. Same id/handler/keys it had in the settings modal.
// 2026-09-18, 4b: the read-only slip's own LEFT slot. A row is read-only for 2 different reasons and
// only one of them can be undone from here: a VERIFIED row on a DRAFT run is read-only because
// somebody froze it, and unfreezing it is what turns this slip back into the editable one. A run past
// draft is read-only because of where the RUN is, which no button in this modal can change -- so that
// case still gets [ปิด] alone. Same endpoint/confirm wording as the Verify column's own toggle.
function breakdownCanUnverifyRd() {
    return !!breakdownRowRd && !!currentRun && currentRun.state === 'draft' && !!breakdownRowRd.is_verified;
}
function renderBreakdownFooterRd(canEdit) {
    const canUnverify = !canEdit && breakdownCanUnverifyRd();
    $('#breakdownModalFooter').html(modalFooterButtonsHtml({
        left: canEdit
            ? { id: 'btnRestoreAllComputedLineOverrides', key: 'line_override_restore_all_computed', fallback: 'Restore all calculated values' }
            : (canUnverify ? { id: 'btnBreakdownUnverify', key: 'action_unverify', fallback: 'Unverify' } : null),
        leftHtml: canEdit ? '<span class="text-muted" id="lineOverrideSaveProgress"></span>' : '',
        secondary: { key: 'close', fallback: 'Close', dismiss: true },
    }));
    refreshBreakdownFooterStateRd();
}
// A refusal belongs where the action was taken: this modal stays open on it, so the message goes in
// the modal's own callout strip (the same one the row's blocking/warning notes use) rather than a
// toast behind it. Cleared by the next renderBreakdownModal().
function breakdownCalloutErrorRd(message) {
    $('#breakdownCalcNotes').html(calloutHtml(escapeHtml(message || langData['save_failed'] || 'Could not save.'), 'danger'));
}
// Re-renders IN PLACE -- the modal is never hidden and shown again. renderBreakdownModal() rebuilds
// every part of it (header card, notes, footer, body) from the row it is handed, and the row's own
// is_verified is the single thing that decides which body and which footer that is.
$(document).on('click', '#btnBreakdownUnverify', function () {
    if (!breakdownRowRd) return;
    const $btn = $(this);
    const employeeId = breakdownRowRd.employee_id;
    const name = employeeDisplayNameRd(breakdownRowRd);
    Swal.fire({
        icon: 'info',
        title: (langData['confirm_unverify_employee_title'] || 'Unverify {name}').replace('{name}', name),
        text: langData['confirm_unverify_employee_message'] || 'This employee will resume normal recalculation and can be edited again.',
        showCancelButton: true,
        confirmButtonText: langData['action_unverify'] || 'Unverify',
        cancelButtonText: langData['cancel'] || 'Cancel',
    }).then(function (result) {
        if (!result.isConfirmed) return;
        setButtonLoading($btn, true);
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.employee-verify.save`,
            method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: employeeId, verified: false }),
            success: function (res) {
                setButtonLoading($btn, false);
                if (!res.status) { breakdownCalloutErrorRd(res.message); return; }
                breakdownRowRd.is_verified = false;
                renderBreakdownModal(breakdownRowRd);
                loadRunDetail();
            },
            error: function () { setButtonLoading($btn, false); breakdownCalloutErrorRd(null); },
        });
    });
});
// Enabled only when there is something to restore: at least one row really carries an override. That
// is a different question from "has anything been typed", which is why it counts rows rather than
// reading a dirty flag.
function refreshBreakdownFooterStateRd() {
    const $btn = $('#btnRestoreAllComputedLineOverrides');
    if (!$btn.length) return;
    const overrideRowCount = lineOverrideMountRd().find('.lo-row').filter(function () {
        const $row = $(this);
        if ($row.find('.lo-include').is(':disabled')) return false;
        // A tri-state row counts on its own answer, not on an override row it may not have.
        return !!($row.data('orig-action') || '') || !!$row.attr('data-exemption-changed');
    }).length;
    $btn.prop('disabled', overrideRowCount === 0);
}
/* ---------- Calculation Breakdown modal, EDITABLE layout (2026-09-16, D1 "สลิปที่แก้ได้").
   A row that can still be edited (draft run, not verified) is edited HERE, where its figures are
   read: the line-override table (render, switch, inline edit, history dropdown/modal, hidden rows,
   busy lock -- see its own section further down) and the hand-added lines. 2026-09-17, D3: this is
   the only place either one lives now; the 2 tabs they were built in are gone. Everything else is
   read-only and renders exactly the slip it always did. ---------- */
// The gate: a draft run, plus this row's verify lock. Both are
// real server-side rules, not styling -- lineOverrideSave() refuses a non-draft run AND a verified
// employee (isEmployeeVerifiedForRun()), so showing the controls in either case would only produce
// errors the user cannot act on.
// 2026-09-16, D2: renamed from breakdownCanEditRd() because it is no longer only the Calculation
// Breakdown modal's question -- assertManualLinesEditable() (PayrollRunModel) refuses exactly the
// same 2 cases for adding/editing/removing a hand-added line, so the "Added manually" block asks it
// too, in BOTH the places that block is shown. One predicate, not a second one that can drift.
function employeeRowEditableRd(row) {
    return !!row && !!currentRun && currentRun.state === 'draft' && !row.is_verified;
}
// The 3 totals are re-read from the server rather than adjusted here: one edited line moves the
// statutory figures with it, so all 3 change together and none of them can be worked out on the
// client. 2026-09-18, 4a-2: they are the table's own last rows now, so refreshing them means
// redrawing the table -- from the lines and run settings it already holds, not a second fetch of the
// identical list (loadSyncLineOverridesRd() is the one that re-reads those, on the same write).
function refreshBreakdownNetSummaryRd() {
    if (!breakdownRowRd) return;
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.get`,
        method: 'GET',
        data: { id: PAYROLL_RUN_ID },
        dataType: 'json',
        success: function (res) {
            if (!res.status || !res.data) return;
            const fresh = (res.data.details || []).find(d => Number(d.employee_id) === Number(breakdownRowRd.employee_id));
            if (!fresh) return;
            breakdownRowRd = fresh;
            if (lineOverrideRowsRd.length) {
                renderLineOverrideTableRd(lineOverrideRowsRd, lineOverrideRunSettingsRd, lineOverrideHostRd.mode);
            }
        }
    });
}
function renderBreakdownEditableBodyRd(row) {
    // 2026-09-17, R1: the calculated table has no heading of its own -- it is what this modal is,
    // and its own column heads already say what each column holds. A heading over the first thing
    // under the employee card only repeats the modal's title.
    // 2026-09-18, 4a-2: and there is only one thing under it now. The hand-added lines are rows of
    // that same table (their own 2 groups), and the 3 totals are its last rows -- so the second
    // section, which existed only to hold them, is gone with them.
    $('#breakdownModalBody').html(`<div class="breakdown-edit">
        <section class="breakdown-edit-section">
            <div id="breakdownLineOverrideWrap" class="lo-mount"></div>
        </section>
    </div>`);
    // One host, one employee, one reload path -- see setLineOverrideHostRd()'s own docblock.
    setLineOverrideHostRd('#breakdownLineOverrideWrap', row.employee_id, refreshBreakdownNetSummaryRd, 'edit');
    loadSyncLineOverridesRd();
}
/* 2026-09-18, 4a-1 ("สลิปเป็นที่เดียว"): the read-only slip is the SAME table as the editable one,
   from the SAME payload -- `mode: 'view'` is the whole of the difference, and all it decides is
   which columns are rendered at all (never `d-none`: a column nobody can use is not a column).
   It reads api/payroll-run.sync-lines-for-employee like the editable slip does, because that is the
   only payload that has every row: an excluded line is dropped from the persisted breakdown JSON
   entirely, so a slip rendered from that JSON could never show one. See
   docs/decisions/2026-09-18-slip-single-place.md. */
function renderBreakdownViewBodyRd(row) {
    $('#breakdownModalBody').html(`<div class="breakdown-edit">
        <section class="breakdown-edit-section">
            <div id="breakdownLineOverrideWrap" class="lo-mount"></div>
        </section>
    </div>`);
    setLineOverrideHostRd('#breakdownLineOverrideWrap', row.employee_id, null, 'view');
    // A hand-added line is NOT in the sync-lines payload (it is filtered out server-side -- an
    // override keyed by item_code could never target it), so loadSyncLineOverridesRd() fetches it
    // alongside and folds it in as rows of this same table (2026-09-18, 4a-2). Without it the
    // read-only slip would silently drop lines this employee is really paid.
    loadSyncLineOverridesRd();
}
$(document).on('click', '.btn-view-breakdown', function () {
    const employeeId = $(this).data('employee-id');
    const rowData = runDetailRowByEmployeeId(employeeId);
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
    return escapeHtml(value);
}
function rawSyncDataItemValuesTableHtml(itemValues) {
    if (!itemValues || !itemValues.length) {
        return `<div class="text-muted small">${langData['raw_sync_data_item_values_empty'] || 'No additional line items sent.'}</div>`;
    }
    const rows = itemValues.map(iv => `<tr>
        <td><code>${escapeHtml(iv.item_code || '-')}</code></td>
        <td>${escapeHtml(iv.item_name || '-')}</td>
        <td>${escapeHtml(iv.item_type || '-')}</td>
        <td>${escapeHtml(iv.unit_type || '-')}</td>
        <td class="text-end">${rawSyncDataValueDisplay(iv.value)}</td>
        <td>${escapeHtml(iv.remark || '-')}</td>
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
// 2026-09-10, Batch 3B item 1, explicit request: a card whose EVERY field is 0/blank/null carries
// no real synced data at all -- 0 counts as "empty" here on purpose (per the request's own wording),
// not just null/''. Checked ONLY against `section.fields` (the raw synced values) -- the Attendance
// card's own workingDaysBreakdownHtml() add-on (this company's OWN calendar config, a different
// data source from Origami's synced attendance numbers) is intentionally NOT part of this check;
// if a run has zero synced attendance but a real calendar breakdown, the card still hides -- a
// known, accepted simplification, not asked to be handled specially.
function rawSyncDataValueIsEmpty(value) {
    if (value === null || value === undefined || value === '') return true;
    const num = Number(value);
    return !Number.isNaN(num) && num === 0;
}
function rawSyncDataSectionIsEmpty(section, data) {
    return section.fields.every(key => rawSyncDataValueIsEmpty(data[key]));
}
// 2026-08-29, explicit request: "การคิดจำนวนวันทำงาน ตอนนี้มีส่งมาจาก Origami ว่าทำงานทั้งหมดกี่วัน ให้แสดง
// ในข้อมูลด้วยว่า จำนวนวันในรอบนั้นกี่วัน วันทำงานกี่วัน วันหยุดนักขัตฤกษ์กี่วัน วันหยุดประจำสัปดาห์กี่วัน" --
// computed from this company's own shift/holiday config (PayrollRunModel::rawSyncDataForEmployee()'s
// new working_days_breakdown, see SetupRulesModel::workingDaysBreakdown()), shown as a small summary
// line right under the Attendance section's own field grid, next to Origami's own reported
// working_days number above it -- so an admin can see both side by side.
function workingDaysBreakdownHtml(breakdown) {
    if (!breakdown) return '';
    // 2026-09-21, 3e-2b: was `.small.text-warning` + a triangle icon -- i.e. Bootstrap's own #ffc107
    // (measured: Bootstrap's own warning yellow, a colour that is in no token at all) on a line of text, plus an icon
    // repeating what the colour already said. It is a statement about this employee's data sitting
    // under the block it qualifies, which is exactly §15's callout: tone lives on the left edge, the
    // text stays --c-text, and a callout has no icon. Same i18n key, unchanged.
    const noShiftNote = !breakdown.has_shift_pattern
        ? calloutHtml(escapeHtml(langData['working_days_breakdown_no_shift'] || 'No shift assigned -- every non-holiday day counted as a working day.'), 'warning')
        : '';
    return `<div class="small mt-2 pt-2 border-top">
        <div class="text-muted mb-1">${langData['working_days_breakdown_title'] || "This company's own calendar (holidays/shift)"}:</div>
        <div class="d-flex flex-wrap gap-3">
            <span>${langData['working_days_breakdown_total'] || 'Total days'}: <strong>${breakdown.total_days}</strong></span>
            <span>${langData['working_days_breakdown_working'] || 'Working days'}: <strong>${breakdown.working_days}</strong></span>
            <span>${langData['working_days_breakdown_holiday'] || 'Public holidays'}: <strong>${breakdown.holiday_days}</strong></span>
            <span>${langData['working_days_breakdown_weekly_off'] || 'Weekly off days'}: <strong>${breakdown.weekly_off_days}</strong></span>
        </div>
        ${noShiftNote}
    </div>`;
}
// 2026-09-21, 3e-2b: `target` (optional) -- where to put the rendered block. The slip's own panel
// passes '#rawSyncPanel', so the exact same renderer serves it rather than a second copy of this
// markup being written for the panel. The default is kept at the id this function always wrote to,
// which the deleted modal owned: there is no element by that name any more, so the no-target call
// is a no-op, not a second render path -- the one real caller always names its host.
function renderRawSyncDataModal(data, target) {
    const sectionsHtml = RAW_SYNC_DATA_SECTIONS_RD.map(section => {
        if (rawSyncDataSectionIsEmpty(section, data)) {
            return '';
        }
        // 2026-09-21, 3e-2b: `col-sm-6`/`col-md-6` answered the VIEWPORT, but this block lives inside
        // a modal-lg -- measured 776px of panel at a 1400px viewport, where `col-md-6` still put 2
        // cards per row and left the third alone on a line of its own. Both levels are CSS grid with
        // `auto-fit`/`minmax` now (see `.rd-sync-sections`/`.rd-sync-fields`, style.css): they wrap
        // against the width they actually have, so the same markup is 3-up in the panel, 2-up and
        // 1-up as it narrows, with no breakpoint named here at all.
        // The card also loses its border/background: it is already inside the panel's own box, and a
        // box inside a box says nothing the heading + spacing does not (§0.3). `.ped-type-panel` is
        // dropped with it -- this renderer was its last markup user (grep: 0 left).
        const fieldsHtml = section.fields.map(key => {
            const f = rawSyncDataFieldLookupRd(key);
            if (!f) return '';
            return `<div class="rd-sync-field">
                <div class="rd-sync-field-label">${langData[f.labelKey] || f.fallback}</div>
                <div class="rd-sync-field-value">${rawSyncDataValueDisplay(data[f.key])}</div>
            </div>`;
        }).join('');
        const breakdownHtml = section.titleKey === 'raw_sync_data_section_attendance' ? workingDaysBreakdownHtml(data.working_days_breakdown) : '';
        return `<div class="rd-sync-section">
            <h6 class="rd-sync-section-title"><i class="fa-solid ${section.icon} me-1"></i>${langData[section.titleKey] || section.fallback}</h6>
            <div class="rd-sync-fields">${fieldsHtml}</div>
            ${breakdownHtml}
        </div>`;
    }).join('');
    /* 2026-09-22, slip2-b: one CARD per heading, "Additional Line Items" included, and the whole grid
       inside a box of its own height.
       The card: the panel rule of §5 -- `--c-bg` + 1px `--c-border` + `--radius` -- the same one
       `.lo-history-panel` wears, because it is the same kind of thing (a box inside a dialog). 3e-2b
       had deliberately taken it AWAY on the grounds that a box inside a box says nothing; what that
       round did not have was a THIRD box, and the answer to 3 nested frames is to drop the OUTER one
       (see `.rd-sync-panel`), not to leave the sections unbounded.
       The item table is a card too -- it is one more thing Origami sent -- but it is a SIBLING of the
       grid, not a member of it. Reported, and measured: as a `grid-column: 1 / -1` member it occupied
       every track, and `auto-fit` collapses only tracks that are EMPTY, so nothing ever collapsed --
       `grid-template-columns` computed to `270px 270px 270px 270px` whether there were 3 field cards
       or 2, and a slip with a hidden section showed 2 cards of 270 with the right half of the panel
       blank. Outside the grid it still takes the full width (it is a block), and the field cards can
       finally share the row they are on.
       The scroll box is what keeps the panel from pushing the slip's own first line off screen
       (measured before: the table's first row ended 57.7px below the fold with the panel open). */
    const scrollLabel = escapeAttr(rawSyncPanelToggleLabelRd(true));
    $(target || '#rawSyncDataModalBody').html(`
        <div class="rd-sync-scroll" tabindex="0" role="group" aria-label="${scrollLabel}">
            <div class="rd-sync-sections">${sectionsHtml}</div>
            <div class="rd-sync-section rd-sync-section-wide">
                <h6 class="rd-sync-section-title">${langData['raw_sync_data_item_values_title'] || 'Additional Line Items'}</h6>
                ${rawSyncDataItemValuesTableHtml(data.item_values)}
            </div>
        </div>
    `);
    rawSyncPanelPublishHeightRd($(target || '#rawSyncDataModalBody'));
}
/* How tall the panel may be, measured off the cards that are really there (2026-09-22, slip2-b).
   Same shape as lineOverridePublishHistoryHeightRd(): the cap is "one row of cards", read from the
   FIRST row's own rendered height rather than assumed from a card height, because a card carrying a
   callout is taller than one that does not -- and the first row is what the reader needs to see
   without scrolling the dialog.
   `min(..., 45vh)` is the second half: a single very tall card (a phone, where a row IS one card)
   would otherwise become the cap and take the whole dialog with it.
   Re-published on resize, because which cards share the first row is decided by the panel's width. */
function rawSyncPanelPublishHeightRd($panel) {
    const panel = $panel.get(0);
    if (!panel) return;
    const box = panel.querySelector('.rd-sync-scroll');
    const cards = panel.querySelectorAll('.rd-sync-section');
    if (!box || !cards.length) return;
    const publish = function () {
        const firstTop = cards[0].getBoundingClientRect().top;
        let bottom = 0;
        for (const card of cards) {
            const r = card.getBoundingClientRect();
            // A card that starts lower than the first one is on the NEXT row -- stop there.
            if (r.top > firstTop + 1) break;
            bottom = Math.max(bottom, r.bottom);
        }
        const rowHeight = Math.round(bottom - firstTop);
        if (rowHeight > 0) box.style.setProperty('--rd-sync-max-h', rowHeight + 'px');
        else box.style.removeProperty('--rd-sync-max-h');
    };
    publish();
    if (typeof ResizeObserver === 'function') new ResizeObserver(publish).observe(panel);
}

/* ---------- The raw-sync panel inside the slip (2026-09-21, 3e-2b) ----------
   The payload above used to open a SECOND modal on top of the slip. It is a disclosure panel under
   the slip's own header card now: one modal stays one modal, and the numbers a reader is checking
   stay on screen next to what Origami sent. Everything it renders is the same 8 functions above,
   reused verbatim through renderRawSyncDataModal()'s new `target` argument -- nothing was copied.

   Shown ONLY when both halves are true: the RUN came from a sync (currentRun.sync_process_id) and
   THIS ROW did (row.data_source === 'sync'). Either alone means there is no payload to show --
   PayrollRunModel::rawSyncDataForEmployee() returns null for both cases -- so the button is not
   rendered at all rather than rendered and then apologising (§0.3).

   Fetched on the first open PER SLIP and kept in rawSyncPanelCacheRd until the slip closes; opening
   the same panel again re-shows what is already in the DOM without a second request. */
let rawSyncPanelCacheRd = null;
function rawSyncPanelAvailableRd(row) {
    return !!(currentRun && currentRun.sync_process_id) && !!row && row.data_source === 'sync';
}
// The disclosure itself: a text button, no icon and no caret -- the panel opening below it is the
// state, and `aria-expanded` is what carries that to a screen reader.
// 2026-09-21, 3e-2b follow-up (user report: "ดูไม่เหมือนของที่กดได้"): it now carries the SAME CSS as
// the slip's own group-head links (`lineOverrideAddLinkHtmlRd()` :4759 / `.lo-add-line-btn`,
// style.css's shared selector list) -- one more selector on that rule, not a second copy of it
// (§0.4). Same tier, same page, same kind of control: a worded link that acts on the block beside it.
// The word changes with the state ("ข้อมูลดิบจาก Origami" <-> "ซ่อนข้อมูลดิบ") because the panel it
// opens is long enough to push the button off screen -- the label has to say what pressing it does
// now, not what it did once. `data-i18n` is deliberately NOT used: the central sweep would re-write
// it with the closed label while the panel is open.
function rawSyncPanelToggleLabelRd(expanded) {
    return expanded
        ? (langData['raw_sync_panel_hide'] || 'Hide raw data')
        : (langData['raw_sync_panel_link'] || 'Raw data from Origami');
}
function rawSyncPanelToggleHtmlRd() {
    return `<button type="button" class="btn btn-link btn-raw-sync-toggle" id="btnRawSyncPanel"
        aria-expanded="false" aria-controls="rawSyncPanel">${escapeHtml(rawSyncPanelToggleLabelRd(false))}</button>`;
}
// Called from renderBreakdownModal() on EVERY open: closed, empty, cache dropped. Without this the
// next employee's slip would open showing the previous employee's payload -- the exact "ค้างจากแถวก่อน"
// bug class this file has hit before with modal-scoped state.
function rawSyncPanelSetExpandedRd($btn, expanded) {
    $btn.attr('aria-expanded', expanded ? 'true' : 'false').text(rawSyncPanelToggleLabelRd(expanded));
}
function resetRawSyncPanelRd() {
    rawSyncPanelCacheRd = null;
    $('#rawSyncPanel').addClass('d-none').empty();
    const $btn = $('#btnRawSyncPanel');
    if ($btn.length) rawSyncPanelSetExpandedRd($btn, false);
}
$(document).on('click', '.btn-raw-sync-toggle', function () {
    const $btn = $(this);
    const $panel = $('#rawSyncPanel');
    if ($btn.attr('aria-expanded') === 'true') {
        $panel.addClass('d-none');
        rawSyncPanelSetExpandedRd($btn, false);
        return;
    }
    rawSyncPanelSetExpandedRd($btn, true);
    $panel.removeClass('d-none');
    if (rawSyncPanelCacheRd) { return; }
    $panel.html(`<div class="text-muted small">${escapeHtml(langData['loading'] || 'Loading...')}</div>`);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.raw-sync-data-for-employee`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: breakdownRowRd ? breakdownRowRd.employee_id : null },
        dataType: 'json',
        // A refusal belongs in the panel that was opened, not in a toast over the slip (§9): the
        // whole point of moving this inline was to stop putting things on top of the figures.
        success: function (res) {
            if (!res.status) {
                $panel.html(`<div class="text-muted small">${escapeHtml(langData['raw_sync_panel_unavailable'] || 'Origami sent no data for this employee in this run.')}</div>`);
                return;
            }
            rawSyncPanelCacheRd = res.data;
            renderRawSyncDataModal(res.data, '#rawSyncPanel');
        },
        error: function () {
            $panel.html(`<div class="text-muted small">${escapeHtml(langData['raw_sync_panel_unavailable'] || 'Origami sent no data for this employee in this run.')}</div>`);
        }
    });
});

// 2026-08-31, explicit request: "ใน Sumary card ของพนักงานแยกเป็น 2 grid ตรงตัวเลขครับ" (2nd grid = Bank/
// Cash counts) -- computed client-side from the SAME details array already loaded for
// #tb_run_detail (payment_type added to PayrollRunModel::getDetails() this same round), no separate
// request needed. Called from both branches of initRunDetailTable() (rebuild and reload-existing).
// 2026-09-02, follow-up: payment_type (bank/cash-only enum) replaced by payment_method_code
// (transfer/cash/check/mixed) -- this summary stays a simple 2-bucket Bank/Cash view (matching what
// was actually asked for here), 'check' buckets with Cash (neither needs a bank account), and
// 'mixed' counts in BOTH buckets (it genuinely involves both a transfer portion and a cash/check
// portion) rather than picking one and hiding the other.
function isBankishPaymentMethod(code) { return code === 'transfer' || code === 'mixed'; }
function isCashishPaymentMethod(code) { return code === 'cash' || code === 'check' || code === 'mixed'; }
function updatePaymentMethodSummary(details) {
    const bankCount = details.filter(d => isBankishPaymentMethod(d.payment_method_code || 'transfer')).length;
    const cashCount = details.filter(d => isCashishPaymentMethod(d.payment_method_code)).length;
    // 2026-09-01, explicit correction: "ให้ขึ้นใน card พนักงานครับ มีแค่ 4 Card เหมือนเดิม" -- no longer
    // its own 2-card grid; a compact subtext line inside the existing "Employees" card instead.
    const bankLabel = langData['table_payment_bank'] || 'Bank Transfer';
    const cashLabel = langData['table_payment_cash'] || 'Cash';
    // 2026-09-02, explicit request: "Card ผ่านบัญชีและเงินสดปรับให้ font คนละสี" -- was one plain-colored
    // line, each half its own accent color + icon. 2026-09-13, Round 3 item 3a follow-up (explicit
    // decision, §2's stat-card spec): a stat card's own subtext line is plain `--c-text-muted` text
    // ONLY -- no icon, no color, matching `.stat-sub`'s own CSS exactly (this element already inherits
    // that class from the markup, only the CONTENT built here needed to stop overriding it with its
    // own inline color/icon spans).
    $('#infoPaymentBreakdown').text(`${bankLabel} ${bankCount} · ${cashLabel} ${cashCount}`);
}
// 2026-09-09, real bug found and fixed (explicit report: "วิธีจ่ายเงิน ตอนนี้ติ๊กแล้ว Employee ไม่เปลี่ยนตาม
// ครับ") -- the Bank/Cash payment-method filter checkboxes already correctly filtered #tb_run_detail's
// own ROWS (registerPaymentMethodSearchFilter() below) and its own <tfoot> totals (footerCallback(),
// {search:'applied'}) -- but the 4 big Summary Cards above the tabs (#infoEmployeeCount/#infoGross/
// #infoDeduction/#infoNet, moved there 2026-09-09 -- see app/views/payroll/detail.php's own comment)
// were only ever set ONCE, from renderRunHeader()'s own run-level totals (run.employee_count/
// total_gross_amount/...), and never touched again -- so the single most prominent numbers on the
// page kept showing the FULL, unfiltered run total no matter what was ticked/unticked, which is what
// actually got reported as "Employee doesn't update." Recomputed here from the table's own CURRENTLY
// VISIBLE (filtered) rows instead, called from drawCallback so it stays correct on every filter
// change, recalculate, verify, etc. -- exactly the same {search:'applied'} rows footerCallback()
// already sums, just surfaced one level up too (updatePaymentMethodSummary()'s own Bank/Cash subtext
// now reflects the same filtered set, for the same reason). Guarded to no-op while there are zero
// details at all (run not yet calculated) so it never regresses the correct run-level placeholder
// renderRunHeader() already set in that case.
function updateSummaryCardsFromTable() {
    if (!tb_run_detail || !currentRunDetails.length) return;
    const visibleRows = tb_run_detail.rows({ search: 'applied' }).data().toArray();
    const sum = key => visibleRows.reduce((a, r) => a + (parseFloat(r[key]) || 0), 0);
    $('#infoEmployeeCount').text(visibleRows.length);
    $('#infoGross').text(fmtNum(sum('gross_amount')));
    $('#infoDeduction').text(fmtNum(sum('total_deduction_amount')));
    $('#infoNet').text(fmtNum(sum('net_amount')));
    updatePaymentMethodSummary(visibleRows);
}
// 2026-08-31, explicit request: "ก่อนตารางพนักงาน ให้มี checkbox ขึ้นมาเพื่อให้เลือกกรองข้อมูล พนักงานที่รับผ่าน
// บัญชี และเงินสด" -- registered ONCE (guarded the same way registerStationSearchFilter() in
// payroll/index.js is, scoped to this one table's id so it never affects any other DataTable on the
// page) rather than re-pushed every time initRunDetailTable() runs.
let paymentMethodSearchFilterRegistered = false;
// 2026-09-13, Round 3 item 3b: reads the new #rdPaymentMethodFilter SELECT's own value (filter-bar.php,
// see initRunDetailFilterBarOnce() below) instead of 2 checkboxes -- same 2 reachable boolean states as
// before (bankOn/cashOn), just derived from ONE value now: 'all' means both on, 'bank'/'cash' means
// only that one. No change to the predicate itself (still isBankishPaymentMethod()/isCashishPaymentMethod()
// against payment_method_code, still lets 'mixed' pass if EITHER is on).
function registerPaymentMethodSearchFilter() {
    if (paymentMethodSearchFilterRegistered) return;
    paymentMethodSearchFilterRegistered = true;
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (!settings.nTable || settings.nTable.id !== 'tb_run_detail') return true;
        const val = $('#rdPaymentMethodFilter').val() || 'all';
        const bankOn = val === 'all' || val === 'bank';
        const cashOn = val === 'all' || val === 'cash';
        const code = (rowData && rowData.payment_method_code) || 'transfer';
        return (isBankishPaymentMethod(code) && bankOn) || (isCashishPaymentMethod(code) && cashOn);
    });
}

// 2026-09-11, Batch 3C item 7, explicit instruction: "ตัดคอลัมน์ แหล่งที่มา ออก (ย้ายไปเป็น filter pill
// 'ที่มา: ทั้งหมด/Sync/เพิ่มเอง' เหนือตาราง ถ้ายังต้องกรอง)" -- same registered-once-per-table-id guard as
// registerPaymentMethodSearchFilter() above, filtering on row.data_source ('sync'/'manual', same
// field the old Source column's badge used to render). #rdDataSourceFilterWrap's own visibility
// (hidden for a run that never brings base salary into the calculation, since data_source doesn't
// apply there either) is still owned by initRunDetailTable() -- see its own showDataSourceFilter
// comment. 2026-09-13, Round 3 item 3b: reads the new #rdSourceFilter SELECT instead of a 3-way
// radio-pill group (same 3 values -- all/sync/manual -- same predicate, view-only change).
let dataSourceSearchFilterRegistered = false;
function registerDataSourceSearchFilter() {
    if (dataSourceSearchFilterRegistered) return;
    dataSourceSearchFilterRegistered = true;
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (!settings.nTable || settings.nTable.id !== 'tb_run_detail') return true;
        const val = $('#rdSourceFilter').val() || 'all';
        if (val === 'all') return true;
        return (rowData && rowData.data_source) === val;
    });
}
// 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "เพิ่มช่อง 'แผนก' (select2-remote
// /api/department.get เหมือน Employee list) เป็นช่องแรก" -- filters on row.department_id, the SAME
// column PayrollRunModel::getDetails()'s own SQL already SELECTs (`e.department_id`, confirmed via
// grep -- it just had no reader in this file before now, departmentNameRd() only ever read the
// display-name columns). Number()-coerced on both sides since select2's own `.val()` returns a
// string, while row.department_id (JSON-decoded from a SQL integer column) is already a number.
let departmentSearchFilterRegistered = false;
function registerDepartmentSearchFilter() {
    if (departmentSearchFilterRegistered) return;
    departmentSearchFilterRegistered = true;
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (!settings.nTable || settings.nTable.id !== 'tb_run_detail') return true;
        const val = $('#rdDepartmentFilter').val();
        if (!val) return true;
        return Number(rowData && rowData.department_id) === Number(val);
    });
}
// 2026-09-13, Round 3 item 3b -- registered ONCE (same guard shape as the 3 search-filter registrars
// above), separate from them since this wires the shared filter-bar.php shell itself (chevron/count/
// chips/Clear -- see initFilterBar()'s own docblock in app.js), not a DataTables search predicate.
// #rdPaymentMethodFilter/#rdSourceFilter go through initSelect2(..., {mode:'static'}) per this app's
// own mandatory Select2 convention (CLAUDE.md) -- 'select2-static' is already on each <select>'s own
// class in detail.php, so this only needs to explicitly set each one's default value to 'all'
// afterward (this app's own established select2-static convention leaves a freshly-initialized static
// select on its EMPTY placeholder by default, not its first real option -- see employee/reports.js's
// own #employee_structure_filter_group_by for the same pattern) so both filters visibly start as "show
// everyone", matching the OLD checkbox/radio defaults exactly. #rdDepartmentFilter is a genuine
// select2-remote (ajax mode, data-api/data-type already on its own <select>, exactly Employee List's
// #employee_filter_department convention) -- its own resting empty value already means "no filter" via
// initFilterBar()'s own isActive() check, no explicit default-value step needed the way the 2 static
// selects above do.
let runDetailFilterBarInitialized = false;
function initRunDetailFilterBarOnce() {
    if (runDetailFilterBarInitialized) return;
    runDetailFilterBarInitialized = true;
    initSelect2('#rdDepartmentFilter');
    initSelect2('#rdPaymentMethodFilter, #rdSourceFilter', { mode: 'static' });
    $('#rdPaymentMethodFilter').val('all').trigger('change');
    $('#rdSourceFilter').val('all').trigger('change');
    initFilterBar('#runDetailFilterBar', {
        onChange: function () { if (tb_run_detail) tb_run_detail.draw(); },
    });
}

// 2026-08-31: raw per-employee rows kept module-level (was also read by the now-removed Payment
// Method Summary tab, see the 2026-09-02 removal note above initRunDetailTable()).
let currentRunDetails = [];
// 2026-09-02, explicit request: "Tab ที่แสดงผลอยู่ตอนนี้มีส่วนไหนที่ยุบรวมกันได้" -- the "Payment Method
// Summary" tab (buildPaymentSummaryTable()/refreshPaymentSummaryTable(), tb_run_payment_summary)
// was a plain read-only Employee/Payment-Method/Base-Salary/Gross/Deduction/Net table with footer
// totals, built off this SAME currentRunDetails array -- it became 100% redundant the moment this
// same round added a Payment Method column + Bank/Cash filter checkboxes directly onto
// #tb_run_detail's own Details tab (which already had Base Salary/Gross/Deduction/Net + footer
// totals from before). Removed entirely rather than left as a duplicate view of the same data --
// see app/views/payroll/detail.php's own removal comment for the tab nav/pane markup.
function initRunDetailTable(details) {
    currentRunDetails = details;
    registerPaymentMethodSearchFilter();
    registerDataSourceSearchFilter();
    registerDepartmentSearchFilter();
    initRunDetailFilterBarOnce();
    // 2026-09-09: no longer called directly here with the FULL, unfiltered `details` array -- see
    // updateSummaryCardsFromTable()'s own docblock (called from drawCallback below instead, which
    // also fires right after this function's own initial construction/reload, so the first paint is
    // unaffected -- only every subsequent filter/redraw now also gets it right).
    // 2026-09-13, Round 3 item 3b: the manual #noDetailsYet/#tb_run_detail show-hide pair is retired --
    // see initSharedDataTable()'s own `emptyState` option below (the table itself always stays visible
    // now, its own tbody shows the empty-state row instead).
    // 2026-08-29, real bug found and fixed (explicit report: "checkbox ในกรณีที่ส่งไปอนุมัติแล้วยังขึ้นอยู่
    // ต้องไม่ขึ้น") -- computed HERE, synchronously, from the SAME currentRun that
    // renderRunHeader() always sets immediately before this function runs (see loadRunDetail()),
    // rather than inside drawCallback itself reading the outer `tb_run_detail` variable (the function
    // that used to do that read there, applyRunDetailViewMode(), was retired 2026-09-13 along with the
    // View Mode callout it existed to toggle -- this comment's own underlying reasoning about WHY the
    // computation has to happen here, synchronously, still applies regardless). Root cause: drawCallback fires synchronously DURING the
    // `$(...).DataTable({...})` constructor call below, i.e. BEFORE the `tb_run_detail = ...`
    // assignment on that call has actually completed -- so on the very FIRST load of a run that is
    // already non-draft (e.g. opening a run that's already pending_approval), that first
    // drawCallback saw `tb_run_detail` as still undefined and silently skipped hiding the checkbox
    // column. It only ever hid correctly on a SECOND reload, once `tb_run_detail` had a real value
    // from a prior successful assignment -- exactly matching the reported symptom.
    const showCheckboxColumn = !currentRun || currentRun.state === 'draft';
    // 2026-09-10, explicit request: "ซ่อน column แหล่งที่มา...เมื่อรอบไม่นำฐานเงินเดือนมาคำนวณ" -- a
    // RUN-LEVEL condition (same run_purpose='incentive' + include_base_salary=0 flag item 2's own
    // base_salary_excluded is derived from at calc time, see PayrollRunModel::isBaseSalaryExcluded()'s
    // own docblock), NOT the per-employee base_salary_excluded flag -- data_source genuinely doesn't
    // apply to a run that never brings base salary into the calculation at all.
    // 2026-09-11, Batch 3C item 7: the column this used to gate is gone (see the retirement comment
    // above dataSourceBadgeRd()'s old location) -- this same condition now gates the FILTER PILL's
    // own visibility instead, right below.
    const showDataSourceFilter = !currentRun || currentRun.run_purpose !== 'incentive' || !!currentRun.include_base_salary;
    $('#rdDataSourceFilterWrap').toggleClass('d-none', !showDataSourceFilter);
    if ($.fn.DataTable.isDataTable('#tb_run_detail')) {
        const existingApi = $('#tb_run_detail').DataTable();
        const existingCheckboxColumn = existingApi.column(0);
        if (existingCheckboxColumn.visible() !== showCheckboxColumn) {
            existingCheckboxColumn.visible(showCheckboxColumn, false);
        }
        existingApi.clear().rows.add(details).draw();
        return;
    }
    // 2026-08-29, explicit request: "ตารางตรงพนักงาน ปรับให้แสดงเป็น 2 แถวแบบไม่ hide column ไหมครับ
    // เพราะ expand ดูไม่สะดวก" -- Employee (No.+Name) and Calculation (status+Remark) combine
    // related fields into 2-line cells; Base Salary/Gross/Deduction/Net are their own columns again
    // as of a same-day follow-up (see the .rd-net-pill comment below). responsive:false (was true)
    // means nothing ever collapses behind an expand-row arrow -- app/views/payroll/detail.php's own
    // .table-responsive wrapper gives a plain horizontal scrollbar as the only narrow-viewport
    // fallback instead, matching every other wide DataTable in this app.
    // 2026-09-13, Round 3 item 3b, explicit instruction: "initSharedDataTable() เต็ม §7" -- was a
    // direct `$(...).DataTable({...})` call, the one real remaining §7 violation on this page (this
    // page's OTHER 4 tables -- Reports/Cash/Bank Account/Remittance -- already route through
    // initSharedDataTable(), see each one's own comment). Only the CONSTRUCTOR call changes here --
    // the "already exists -> clear().rows.add().draw()" branch above (which is what actually runs on
    // every reload after the first) is untouched, so this table keeps preserving the user's current
    // page/sort/search across a data refresh exactly as before; initSharedDataTable()'s own internal
    // destroy-and-rebuild logic only ever runs the ONE time this branch is reached, on first
    // construction, same as the raw call it replaces.
    tb_run_detail = initSharedDataTable('#tb_run_detail', {
        // §7: "sticky คอลัมน์ชื่อ" -- freezes the first 3 columns (checkbox+Code+Name) together, not
        // Name alone: initStickyColumns()'s own `left` option freezes N columns counting from column 0,
        // there's no way to pin a single column out of sequence, and un-pinning checkbox/Code while
        // pinning Name would leave those 2 scrolling independently underneath a floating frozen column
        // -- freezing all 3 identity columns together is what actually keeps "who is this row" legible
        // while scrolling. Known gap, not fixed here (a shared-function limitation, not specific to
        // this table): initStickyColumns()'s own footHasRealColumns check only recognizes `<td>`
        // footer cells, but this table's own <tfoot> (detail.php) uses `<th>` (matching its header/
        // §7 convention) -- so the footer totals row does NOT get frozen along with the header/body.
        stickyColumns: { left: 3 },
        // 2026-09-15, rules.md 7 "DataTable toolbar": these 3 buttons used to be appended straight into
        // `.dt-search`/`.dt-length` from this table's own initComplete. They are handed to the shared
        // toolbar slot instead -- same ids, same classes, same delegated handlers, the component just
        // decides WHERE they sit (and how the row reflows below `sm`).
        toolbar: {
            create: `<button type="button" id="btnJoinEmployees" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i><span data-i18n="employee">${langData['employee'] || 'Employee'}</span></button>`,
            actions: [
                `<button type="button" id="btnBulkVerify" class="btn btn-outline-secondary" disabled><span data-i18n="action_verify_selected">${langData['action_verify_selected'] || 'Verify Selected'}</span> <span id="runDetailBulkCount">${countBadgeHtml(0)}</span></button>`,
                `<button type="button" id="btnVerifyAllEmployees" class="btn btn-outline-secondary"><span data-i18n="action_verify_all">${langData['action_verify_all'] || 'Verify All'}</span></button>`,
            ],
        },
        // §7: per-column Excel-style filter -- moved here from a manual initExcelColumnFilters() call
        // inside this table's own initComplete below (initSharedDataTable() now owns wiring it in
        // automatically per §7's own "ครอบหน้าที่ของ initExcelColumnFilters() ให้เอง" decision). Same 4
        // columns/keys as before, unchanged.
        columnFilters: {
            mode: 'client',
            columns: [
                { index: 3, key: 'department' },
                { index: 4, key: 'payment_method_code' },
                { index: 9, key: 'calc_status' },
                { index: 10, key: 'verify_status' },
            ],
        },
        // 2026-09-23, 3e-3b round B4: named explicitly now that #auditLogFilterBar (Action History
        // tab) makes this page's 2nd `.filter-bar` -- tableFilterBarFor() (app.js) only auto-picks
        // "the single `.filter-bar` on the page" when there is exactly one; without this, this
        // table's own empty-state Clear button would silently stop resetting
        // #rdDepartmentFilter/#rdPaymentMethodFilter/#rdSourceFilter (real regression, caught before
        // it shipped by tracing tableFilterBarFor()'s own fallback while adding the 2nd bar).
        filterBar: '#runDetailFilterBar',
        // §6: "empty state 2 แบบ" -- this config is the "genuinely no data yet" variant (reuses the
        // exact copy/icon #noDetailsYet used to show); dtRenderEmptyState() (app.js) auto-swaps to its
        // OWN built-in "filtered to zero results" variant instead whenever the table has real rows but
        // the CURRENT search/filter hides all of them -- no separate config needed for that 2nd case.
        // Known gap, not fixed here (a shared-function limitation, affects every initSharedDataTable()
        // caller, not specific to this table): that built-in filtered-state "Clear Filter" action only
        // clears the DataTables global search box (`dt.search('').draw()`), not this table's OWN
        // #rdPaymentMethodFilter/#rdSourceFilter selects (custom ext.search predicates, a different
        // mechanism) -- a user who filtered to zero via those selects and clicks "Clear Filter" would
        // see the search box clear but the select-driven filter stay active.
        emptyState: {
            icon: 'fa-solid fa-calculator',
            title: getLangValue('no_details_yet') || 'No employees calculated yet. Click "Recalculate" to compute this run.',
            // rules.md §6's "no data yet" variant may offer the page's own create action -- the same
            // button, the same words as the toolbar's own "+ พนักงาน", behind the same condition that
            // decides whether that button is shown at all (a run that is no longer a draft cannot
            // take new employees, so there is nothing to offer).
            // Labelled with the SAME words as the modal action it opens (`action_join_employees`)
            // rather than the toolbar button's own bare "พนักงาน" -- that button carries a `+` icon
            // which this one does not, and a noun alone says nothing about what pressing it does (§0.5).
            action: showCheckboxColumn ? {
                label: getLangValue('action_join_employees') || 'Join Employees',
                variant: 'secondary',
                onClick: function () { $('#btnJoinEmployees').trigger('click'); },
            } : undefined,
        },
        dtOptions: {
        responsive: false,
        data: details,
        columns: [
            // 2026-08-29, explicit request: "สามารถมี checkbox เลือกได้ทีละหลายคนในการ Verify และ Lock"
            // -- 2026-08-29 (View Mode follow-up): the checkbox column has no purpose once nothing on
            // this run can be verified/locked/bulk-actioned anymore -- visible: showCheckboxColumn
            // (computed just above from currentRun.state, see this function's own top-of-function
            // comment for why it's set HERE at construction time and not inside drawCallback).
            { data: null, orderable: false, visible: showCheckboxColumn, render: (d, t, row) => `<input type="checkbox" class="form-check-input run-detail-row-check" data-employee-id="${row.employee_id}">` },
            // 2026-09-02, explicit request: "ตารางพนักงาน แยก code และชื่อคนละ Column Code อยู่ก่อน" --
            // was one combined 2-line cell (name bold on top, code muted underneath); split into its
            // own Code column and a separate plain Name column right after it.
            // 2026-09-16, explicit instruction ("คอลัมน์รหัสพนักงาน = รหัสอย่างเดียว"): the 2 extra
            // markers this cell used to carry are gone from it -- the "Adjusted N" button (its count
            // is now the count badge on the row's own slip circle, viewBreakdownButtonRd(), and the
            // viewer it opened is gone entirely: the slip itself reads those adjustments, 2026-09-18 4b)
            // and the blue fa-file-invoice-dollar tax/SSO-override icon (§3 kills blue outright, and
            // a tax/SSO override is one of the adjustments the same count now covers).
            { data: 'employee_no', orderable: false, render: (d) => `<span class="fw-semibold">${escapeHtml(d)}</span>` },
            // 2026-09-10, Batch 3A item 4: avatar + name (not avatar alone -- this column must stay
            // searchable by name via the table's own global search box). Object-form render (this
            // app's own DataTables sort-safety convention) since display is now HTML -- filter (what
            // the search box actually matches against) stays the plain name string.
            { data: null, orderable: false, render: {
                display: (d, t, row) => apvPersonLineHtml(employeeDisplayNameRd(row), 24, row.profile_photo_path, { employeeId: row.employee_id }),
                filter: (d, t, row) => employeeDisplayNameRd(row),
            } },
            // 2026-09-11, Batch 3C item 7, explicit instruction: "เพิ่มคอลัมน์ แผนก ถัดจากชื่อ...แผนกมาจาก
            // employee record ณ ตอนดึงเข้ารอบ" -- department_name_th/en come from a LEFT JOIN onto the
            // employee's CURRENT structure_departments row (PayrollRunModel::getDetails(), same "live
            // employee record" source every other employee-identity column on this row already reads
            // from -- there's no separate department snapshot table for run rows to freeze against).
            { data: null, render: {
                display: (d, t, row) => escapeHtml(departmentNameRd(row)),
                sort: (d, t, row) => departmentNameRd(row),
                filter: (d, t, row) => departmentNameRd(row),
            } },
            // 2026-09-02, explicit request: "ในตารางพนักงานให้เพิ่ม Column รับเงินผ่านบัญชี หรือเงินสด" --
            // same badge markup the (since-removed) Payment Method Summary tab used, reused here for
            // a consistent look.
            // 2026-09-02, follow-up: widened from a bank/cash-only binary to the real 4-code
            // payment_method_code (transfer/cash/check/mixed) -- check gets the same cash-style badge
            // (no bank account involved either), mixed gets its own distinct badge since it's neither.
            // 2026-09-13, Round 3 item 3b: was 4 hardcoded per-value badge strings (own ad-hoc colors,
            // one of them blue -- §3 kills blue outright); routed through the shared statusBadgeHtml()
            // (§5) + status_map.php's new 'payment_method' context instead. Real, flagged trade-off:
            // statusBadgeHtml() has no icon slot, so the per-value icon (money-bill-wave/money-check/
            // shuffle/building-columns) is lost -- every other statusBadgeHtml() badge in this app is
            // already icon-less, so this brings Payment Method in line with that convention rather than
            // being a one-off regression.
            // 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "badge = ซ้าย" (§7) -- was
            // className:'text-center', a leftover from before this column routed through
            // statusBadgeHtml(); dropped so it falls back to the default left alignment every other
            // badge column already uses.
            // 2026-09-14, Round 3 item 3c-1 follow-up, real bug fix -- was a plain function render
            // (CLAUDE.md's own Table convention already required object-form here: the badge HTML
            // display differs from the raw payment_method_code value) -- `.render('filter')` for a
            // plain function just re-invokes the SAME function and strips its HTML tags, which
            // happened to still read as the translated label text for THIS column specifically (no
            // real user-facing bug here, unlike calc_status below), but left the column non-
            // compliant with the documented convention and one accidental step away from the same
            // class of bug -- split explicitly via the shared statusMapFilterLabelRd() helper.
            { data: 'payment_method_code', render: {
                display: d => statusBadgeHtml(d || 'transfer', 'payment_method'),
                filter: d => statusMapFilterLabelRd(d || 'transfer', 'payment_method'),
            } },
            // 2026-08-29, explicit follow-up request: "ตรงเงินได้เงินหักสุทธิ์ ปรับการแสดงผลให้ชัดขึ้น หรือแยก
            // Column ไปเลย" -- the combined "Amounts" cell from the previous round packed Base
            // Salary/Gross/Deduction/Net into one cell and wasn't clear enough; split back into their
            // own columns. Net gets its own strong pill styling (rd-net-pill) since it's the figure
            // people scan for first, distinct from the plain-text Base Salary/Gross/Deduction cells.
            // 2026-08-29, explicit follow-up request: "สมมุติถ้าเลือกไม่เอาเงินเดือนมาคำนวณ ตรงช่องเงินเดือน
            // ในตารางพนักงานให้ขึ้นคำว่าไม่นำมาคำนวณสีแดงๆ แทน 0" -- row.base_salary_excluded (see
            // PayrollRunModel::getDetails()'s own docblock) distinguishes "genuinely 0 this period"
            // from "excluded from calculation entirely" -- only the latter gets this red label.
            // Object-form render (this project's own DataTables convention, see CLAUDE.md) since
            // this column is still sortable -- sort/filter stay on the raw numeric value regardless
            // of which text the display side renders, so a client-side sort never turns into
            // lexicographic string ordering for the excluded rows.
            // 2026-09-13, Round 3 item 3b (§8 money-color system, "ที่ยังไม่ทำ" list closed out): Base
            // Salary itself is NOT one of the 3 money-color classes (§8 only defines gross/deduction/
            // net -- a base figure is neither an income nor a deduction nor a total) so it keeps its own
            // text-muted/text-danger-excluded rendering unchanged; only the `.num`/`.col-money` marker
            // class moved from this column's own `className` here onto its `<th>` in detail.php (§7:
            // alignment/tabular-nums driven by the `<th>`'s own class, not repeated per-column in JS).
            { data: 'base_salary_amount', render: {
                display: (d, t, row) => row.base_salary_excluded
                    ? `<span class="text-danger fw-semibold small">${langData['base_salary_excluded_label'] || 'Not Calculated'}</span>`
                    : `<span class="text-muted">${fmtNum(d)}</span>`,
                sort: d => d,
                filter: d => d,
            } },
            // 2026-09-13, Round 3 item 3b (§8): was a plain function render (sortable column, comma-
            // formatted display used for sort too -- the exact lexicographic-sort bug CLAUDE.md's own
            // Table convention warns about) with a raw `text-success fw-semibold` className (§12's lint
            // now forbids `text-success`/`text-danger` combined with `.num` outright). Object-form
            // render (raw number for sort/filter) + `.money-gross`/`.money-deduction` class (paired with
            // `.num`/`.col-money` from the `<th>` marker, same as Base Salary above) fixes both at once.
            { data: 'gross_amount', className: 'money-gross', render: { display: d => fmtNum(d), sort: d => d, filter: d => d } },
            { data: 'total_deduction_amount', className: 'money-deduction', render: { display: d => fmtNum(d), sort: d => d, filter: d => d } },
            // `.rd-net-pill` (own orange-tinted pill background) retired per explicit instruction ("ตัด
            // ...พื้นส้มของสุทธิออก") -- `.money-net` (§8: bold 600, --c-text, no color -- a total isn't
            // "good/bad") is now what makes Net Pay read as the headline figure instead.
            { data: 'net_amount', className: 'money-net', render: { display: d => fmtNum(d), sort: d => d, filter: d => d } },
            // 2026-09-13, Round 3 item 3b: calcStatusBadgeRd() (a local hardcoded pending/calculated/
            // error map) retired -- status_map.php already had an identical 'payroll_calc_status'
            // context (same 3 values, same tones) from an earlier round with no consumer yet; this is
            // its first real one.
            // 2026-09-14, Round 3 item 3c-1 follow-up, real bug fix (explicit report: column filter
            // popup showed the raw enum "calculated" instead of the translated "คำนวณแล้ว") -- filter
            // used to be `${d} ${row.calc_errors || ''}` (the raw status code plus raw machine error
            // codes, neither translated) -- switched to statusMapFilterLabelRd() (same translated
            // label the badge itself shows). The raw calc_errors codes are dropped from filter
            // entirely, not translated-and-kept: they're free-text, per-employee remarks (see
            // calcErrorsRemarkRd()), not a small set of distinct values an Excel-style column filter
            // checklist makes sense for -- concatenating them back in would just reproduce the same
            // "raw data leaking into the popup" problem one level down.
            // 2026-09-16, explicit instruction: the cell is 1 line again -- status badge + (only when
            // there are advisory notes) a "N คำเตือน" badge that opens them in a popover. Red stays
            // exclusively the calc_status='error' badge's own job; the full text of both lists lives
            // in the Calculation Breakdown modal (renderBreakdownModal()).
            // 2026-09-21, 3e-2a: `display` only -- calcErrorBadgeRd() wraps the SAME statusBadgeHtml()
            // in a button when there is a blocking list to show, so `sort`/`filter` below are
            // untouched and the column still sorts/filters on the raw enum + its translated label,
            // never on the button markup.
            { data: 'calc_status', render: {
                display: (d, t, row) => `${calcErrorBadgeRd(d, row)}${calcWarningBadgeRd(row)}`,
                sort: d => d,
                filter: d => statusMapFilterLabelRd(d, 'payroll_calc_status'),
            } },
            // 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "badge = ซ้าย" (§7) -- was
            // className:'text-center'.
            // 2026-09-14, Round 3 "เก็บตกรอบ 5" item 2, real bug fix -- object-form `render:
            // {display, filter}` (was a plain function) so the Excel-style column filter reads
            // verifyLockFilterTextRd()'s own plain-label text instead of accidentally picking up the
            // verified badge's own hidden dropdown-menu markup -- see that function's own docblock.
            { data: null, orderable: false, render: {
                display: (d, t, row) => verifyLockButtonsRd(row),
                filter: (d, t, row) => verifyLockFilterTextRd(row),
            } },
            // 2026-09-13, Round 3 item 3b: className:'all' (a Responsive-extension-only marker, dead
            // weight since responsive:false) replaced by the `col-actions` marker class on this column's
            // own `<th>` in detail.php instead (§7) -- DT_MARKER_CLASSES' own columnDef already supplies
            // `className:'col-actions text-end', orderable:false, searchable:false` for it.
            { data: null, render: (d, t, row) => runDetailActionsRd(row) },
        ],
        // 2026-09-09, explicit request: "ตาราง Employee ใน Tab Employee ให้เป็น Datatable ครับ" -- this
        // table was already DataTables-initialized (sort/footer totals/Excel-column-filter all
        // already wired), but with paging/info always off and the search box only appearing past 10
        // rows, it visually read as a plain table for most runs. Turned on the standard DataTables
        // chrome (search box always visible, "Showing X to Y of Z entries" info line, real pagination)
        // so it reads as one at a glance regardless of employee count -- pageLength reuses the SAME
        // shared 50-entry standard every other paginated table in this app already uses (app.js).
        paging: true,
        pageLength: pageLength,
        searching: true,
        info: true,
        language: getTableLang(),
        // 2026-08-29, explicit follow-up request: "ตรง Column แรกไม่ต้องให้ Sort ได้ และให้ตารางเรียงจาก
        // emp code จากน้อยไปหามากครับเป็น Default" -- the Employee column (index 1, orderable:false
        // above) is no longer click-to-sort, but still gets used as the table's own default/initial
        // order here -- DataTables applies an `order` target regardless of that column's own
        // orderable flag, it only blocks the USER from re-triggering it via the header. The data
        // already arrives pre-sorted by employee_no ASC from PayrollRunModel::getDetails()'s own
        // SQL, so this is a belt-and-braces guarantee it stays that way across every later
        // rows.add().draw() reload too (recalculate/verify/etc.), not just the first render.
        order: [[1, 'asc']],
        // 2026-08-29, explicit follow-up request: "รายการให้แสดงให้ต่างกับรายการที่ยังไม่ Verify" -- a
        // verified row gets its own background tint (rd-row-verified, see style.css) so it reads as
        // visually distinct from a plain not-yet-actioned row at a glance, not just via the Verify
        // column's own button state. createdRow fires once per row (including on rows.add() during a
        // later reload), so this stays correct across recalculate()/verify round trips with no extra
        // wiring. rd-row-locked retired 2026-08-31 along with Lock itself.
        createdRow: function (row, data) {
            $(row).toggleClass('rd-row-verified', !!data.is_verified);
        },
        // 2026-09-10, Batch 3A item 1 -- this table's own dropdown-clipping fix (`.table-responsive`
        // forcing overflow-y:auto, catching the "More" dropdown-menu) is now handled globally by
        // app.js's own applyFixedStrategyToTableDropdowns() on every `draw.dt`, superseding the
        // per-table fix that used to live here.
        drawCallback: function () {
            getTableLang();
            updateRunDetailBulkBar();
            updateSummaryCardsFromTable();
            // Every draw rebuilds the cells, so the "N คำเตือน" triggers are new DOM nodes each time
            // -- initPopovers() is idempotent per element (disposes an existing instance first).
            if (typeof initPopovers === 'function') initPopovers('#tb_run_detail');
        },
        // 2026-08-29, same-day follow-up: "ตอนนี้เหมือนมี Summary ด้านขวาเล็กๆ ให้ตัดออก...อยากให้มี Summary
        // ของแต่ละ Column ใน Footer" -- replaces the old updateRunDetailVerifyLockSummaryRd() side
        // strip. Fires on every draw (search/sort/reload) automatically, same as drawCallback --
        // {search:'applied'} means a filtered view sums/counts only what's currently visible, not
        // the whole table, matching DataTables' own footer-total convention.
        // 2026-09-02, explicit request: "Footer Column ตรวจสอบแล้ว ไม่เอา icon ให้ขึ้นว่าตรวจสอบแล้ว n/n และ
        // Column การคำนวณ คำนวณแล้ว n/n" -- rdFootVerifyLock drops its icon for a plain "label n/total"
        // count text; the Calculation column (previously blank in the footer) gets the same
        // treatment counting calc_status === 'calculated' rows. Column indices below shifted by 2
        // (Code+Name split into 2 columns, +1 new Payment Method column) from the previous round.
        footerCallback: function () {
            const api = this.api();
            const sumColRd = idx => api.column(idx, { search: 'applied' }).data().toArray().reduce((a, b) => a + (parseFloat(b) || 0), 0);
            const visibleRows = api.rows({ search: 'applied' }).data().toArray();
            $('#rdFootEmployeeCount').text(`${langData['table_employee'] || 'Employee'}: ${visibleRows.length}`);
            $('#rdFootBaseSalary').text(fmtNum(sumColRd(5)));
            $('#rdFootGross').text(fmtNum(sumColRd(6)));
            $('#rdFootDeduction').text(fmtNum(sumColRd(7)));
            $('#rdFootNet').text(fmtNum(sumColRd(8)));
            // 2026-09-16, explicit instruction ("แถวสรุปท้าย 'คำนวณแล้ว a/b · คำเตือน c'"): c counts
            // EMPLOYEES with at least one advisory note, not total notes -- it sits next to a/b,
            // which are employee counts too, so mixing units in one line would misread.
            const calculatedCount = visibleRows.filter(r => r.calc_status === 'calculated').length;
            const warningRowCount = visibleRows.filter(r => calcAdvisoryCodesRd(r).length > 0).length;
            const calcFootParts = [`${langData['calc_status_calculated'] || 'Calculated'} ${calculatedCount}/${visibleRows.length}`];
            if (warningRowCount > 0) {
                calcFootParts.push((langData['calc_warning_count'] || '{n} warnings').replace('{n}', String(warningRowCount)));
            }
            $('#rdFootCalcStatus').text(calcFootParts.join(' · '));
            const verifiedCount = visibleRows.filter(r => r.is_verified).length;
            $('#rdFootVerifyLock').text(`${langData['verify_status_verified'] || 'Verified'} ${verifiedCount}/${visibleRows.length}`);
        },
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
        // rollout, client mode (plain `data:` array, no ajax at all). employee_no/name stay excluded
        // (covered by the global search box instead, see searching: true above); base_salary/gross/
        // deduction/net/payment_type each get their own filter. Indices shifted +2 from the previous
        // round (see footerCallback's own comment above for why).
        initComplete: function () {
            // 2026-09-09, explicit request (final layout, after 2 follow-up rounds): "เอาคำนวณใหม่ไป
            // วางต่อ search แล้วตามด้วย ปุ่ม Add พนักงาน และตัดให้เหลือแค่คำว่าคำนวณ แล้วเอาปุ่ม Verify All
            // มาไว้ต่อจาก ตรวจสอบแล้ว และเปลี่ยนคำว่าตรวจสอบแล้ว เป็นแค่คำว่าตรวจสอบ และปรับให้ขนาดปุ่มสูงเท่ากับ
            // ช่อง search" -- `.dt-search` (right of the search box, matching this app's established
            // "Add"-button-in-search-bar convention -- CLAUDE.md's Table convention,
            // `injectAddButton()` in payroll-configuration.js is the same pattern) gets "+ Employee";
            // `.dt-length` (next to "Show N entries", same pattern employee/list.js already uses for
            // its own "Sync Selected" button) gets "Verify(N)". `btn-sm` matches the search input's
            // own `form-control-sm` height -- Bootstrap's regular `.btn` is taller than `-sm` form
            // controls, which is what read as mismatched heights.
            // 2026-09-13, Round 3 item 3a: Calculate (#btnRecalculate) moved OUT of this toolbar
            // entirely, into page-header.php's own #phActions (secondary "ส่งออก ▾",
            // computeRunHeaderActions()) per explicit decision -- it's a run-level utility action, not
            // scoped to this table. #btnVerifyAllEmployees was ALSO tried in the header (overflow
            // "อื่นๆ ▾") in a first cut, then moved back here after review: unlike Calculate, it's
            // genuinely table-scoped (acts on every row in THIS table), same category as
            // #btnBulkVerify right next to it (§7's own "bulk action ↔ table's own toolbar"
            // convention, page-header.php's docblock also documents this distinction). Reorganizing
            // this toolbar onto initSharedDataTable() itself (sticky columns, class-driven columnDefs,
            // etc.) is 3b's own separate sub-step, not done here.
            // initComplete only ever fires ONCE per table instance (a later reload takes
            // initRunDetailTable()'s "already exists" branch and never gets here again), so initial
            // visibility for all 3 is set directly from `currentRun` here -- every later state change
            // is handled by renderSectionButtons()'s own toggle instead (see that function's own
            // comment). #btnBulkVerify additionally starts `disabled` and only re-enables once a row is
            // actually checked (updateRunDetailBulkBar(), unchanged logic, toggles `disabled` not
            // visibility).
            // 2026-09-13, Round 3 item 3b follow-up, explicit toolbar layout (2nd revision, reported
            // as a real §2/§4 exception): "'+ พนักงาน': ย้ายไปขวา ต่อจากช่องค้นหา เป็น .btn-primary (ส้ม) --
            // ข้อยกเว้น: 'ปุ่มสร้างรายการในตาราง เป็น primary ได้ เมื่อ page header primary เป็น state
            // action' ... toolbar ซ้ายเหลือ [แสดง N][ตรวจสอบที่เลือก (N)][ตรวจสอบทั้งหมด]" -- #btnJoinEmployees
            // moves from `.dt-length` (left) to `.dt-search` (right, after the search box), restyled
            // back to `btn-primary`. The exception this reverses the PREVIOUS round's own reasoning
            // (§2's "1 หน้า = ปุ่มส้มได้ตัวเดียว" already spoken for by the page header) -- the resolved
            // reading: the page header's own primary button is a STATE action (Recalculate/Submit/
            // Approve -- moves the RUN forward), while "+ Employee" is a genuinely different kind of
            // action (CREATES a row in a table), so both being orange doesn't create 2 competing "the
            // one thing to do here" signals -- see §2/§4's own newly-documented exception text.
            // 2026-09-15: the 3 toolbar buttons are rendered by the shared slot now (see this
            // table's own `toolbar` option above) -- all that is left here is the draft-only
            // visibility they always had, applied to whatever the slot rendered.
            const isDraft = !!currentRun && currentRun.state === 'draft';
            $('#btnJoinEmployees, #btnBulkVerify, #btnVerifyAllEmployees').toggleClass('d-none', !isDraft);
            // 2026-09-13, Round 3 item 3b: initExcelColumnFilters() itself no longer called here --
            // initSharedDataTable()'s own `columnFilters` option (passed at the top of this call)
            // wires it in automatically now (§7's own "ครอบหน้าที่ของ initExcelColumnFilters() ให้เอง").
        },
        },
    });
}

/* ==================== View Mode (2026-08-29) ====================
   Explicit request: "ตอน View Mode ในกรณีที่แก้ไขหรือทำอะไรไม่ได้แล้ว ส่วนของการแสดงผล อยากให้ปรับให้ดูเป็น
   View อยากเดียว แต่สามารถกดดูรายละเอียดเท่าที่ดูได้ครับ จะได้ดูแตกต่างจากตอนสร้างและแก้ไข" -- whenever the
   run is not draft (nothing editable anymore -- pending_approval/approved/paid/locked/rejected/
   cancelled/need_info), the Employee Breakdown table visually reads as pure View: no checkbox
   column (bulk verify/lock is a draft-only concept), no bulk action bar, and a small "View Mode"
   pill next to the section heading so it's obviously different from the create/edit (draft)
   experience at a glance -- clicking through to View Details/formula popovers/comments still all
   work exactly as before, only the MUTATING affordances (checkboxes, bulk bar) disappear. Verify/
   Lock buttons and Manage Items/Remove already individually gate on currentRun.state !== 'draft'
   elsewhere in this file (verifyLockButtonsRd(), removeEmployeeButtonRd()) --
   this just adds the section-level visual cue on top of those existing per-control gates. */
// 2026-09-09, real bug avoided (found while wiring up the explicit request "ตาราง Employee ใน Tab
// Employee ให้เป็น Datatable ครับ", which turned on real pagination -- see initRunDetailTable()'s own
// comment): before pagination existed on this table, EVERY row's checkbox was always physically
// present in the DOM at once, so a plain `$('.run-detail-row-check')` jQuery selector already saw
// every employee. With paging on, DataTables only ever inserts the CURRENT PAGE's <tr> nodes into
// the visible DOM (other pages' rows live only in its own internal row cache) -- a plain DOM
// selector would have silently started seeing only whichever page happens to be showing, which would
// have made "Select All"/bulk Verify quietly skip every employee not on the current page (a real,
// payroll-affecting data-loss-shaped bug, not just a display glitch). Every place below that used to
// query `$('.run-detail-row-check...')` directly now goes through this instead, which asks the
// DataTable API for every matching row's own node (`.rows({search:'applied'}).nodes()`, respecting
// the Bank/Cash filter the exact same way the footer/summary cards already do) regardless of which
// page is currently visible.
function allRunDetailRowCheckboxes() {
    if (!tb_run_detail) return $();
    return tb_run_detail.rows({ search: 'applied' }).nodes().to$().find('.run-detail-row-check');
}
// 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "ตัด callout 'โหมดดูอย่างเดียว' ออกทั้งหมด
// (สถานะรอบ + ปุ่มที่หายไปบอกอยู่แล้ว)" -- applyRunDetailViewMode() (the function that used to toggle
// #runDetailViewModeCallout, itself a same-round replacement of an even older badge) is retired
// entirely -- it had nothing left to do once the callout it existed for was removed (checkbox column
// visibility already lives in initRunDetailTable() itself, #btnBulkVerify's own show/hide already
// lives in renderSectionButtons() -- both moved out of this function in earlier rounds, confirmed by
// re-reading its own body before deleting it, not assumed). Its one call site (drawCallback, above)
// was removed along with it.

/* ==================== Employee Verify / Lock / Comments (2026-08-29) ====================
   Explicit request: per-employee Verify + Lock/Unlock (single-row buttons + multi-select checkbox
   bulk actions), surfaced verified/locked counts, and a per-employee comment timeline modal with a
   status tag. Mirrors this page's own established .btn-group/border/rounded-3/bg-white action-row
   idiom (runDetailActionsRd()) and showConfirm()/callRunAction() patterns already used for every
   other mutating action here. ==================== */
// 2026-09-09, explicit request: "ให้ขึ้นแสดงผลเลย แต่ Disabled ไว้ก่อน ต้องเลือก checkbox ก่อนค่อยเปิดให้ก่อน"
// -- #btnBulkVerify used to live inside a whole bar (#runDetailBulkBar) that was itself hidden until
// something was checked; now the button is always visible (once draft, see renderSectionButtons())
// and instead toggles its own native `disabled` state based on the same selection count.
function updateRunDetailBulkBar() {
    const $all = allRunDetailRowCheckboxes();
    const count = $all.filter(':checked').length;
    // 2026-09-13, Round 3 item 3b: was plain .text(count) inside a literal "(N)" -- now countBadgeHtml()
    // (see #btnBulkVerify's own markup comment in initComplete above).
    $('#runDetailBulkCount').html(countBadgeHtml(count));
    $('#btnBulkVerify').prop('disabled', count === 0);
    const total = $all.length;
    $('#runDetailSelectAll').prop('checked', total > 0 && count === total)
        .prop('indeterminate', count > 0 && count < total);
}
$(document).on('change', '#runDetailSelectAll', function () {
    allRunDetailRowCheckboxes().prop('checked', $(this).is(':checked'));
    updateRunDetailBulkBar();
});
$(document).on('change', '.run-detail-row-check', function () {
    updateRunDetailBulkBar();
});
function selectedRunDetailEmployeeIds() {
    return allRunDetailRowCheckboxes().filter(':checked').map(function () { return Number($(this).data('employee-id')); }).get();
}
// 2026-09-11, Batch 3C item 9 follow-up, explicit instruction: "เพิ่ม confirm ให้ #btnBulkVerify ด้วย
// ข้อความเดียวกับ verify all แต่ใช้จำนวนที่เลือก...ปุ่มยืนยัน 'ตรวจสอบแล้ว'" -- confirmTitle carries a
// "{count}" placeholder (see confirm_bulk_verify_title), filled in here from the ACTUAL selection
// size once known, same {count}/{name} template-replace convention already used throughout this
// app.
// 2026-09-20, 3e-1 (§12 rule 6 / §11: no direct SweetAlert2 call outside app.js/alert.js): this used to
// call the dialog itself, on the reasoning that showConfirm() hardcoded Yes/No. It does not -- it
// has taken `confirmText`/`cancelText` for a while now, so the custom action label that justified
// going around it is a plain option, and every other property this call passed maps one-for-one
// (icon 'info' is showConfirm()'s own default tone, `text` -> `message`, the isConfirmed branch ->
// `onYes`). Same central dialog either way; now it is reached the same way as everywhere else.
function bulkVerifyLockRd(url, payload, confirmTitle, confirmMessage, confirmButtonText) {
    const employeeIds = selectedRunDetailEmployeeIds();
    if (!employeeIds.length) return;
    showConfirm({
        title: confirmTitle.replace('{count}', employeeIds.length),
        message: confirmMessage,
        confirmText: confirmButtonText || (langData.yes || 'Yes'),
        cancelText: langData['cancel'] || 'Cancel',
        onYes: function () {
        $.ajax({
            url: `${BASE_URL}${url}`, method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify(Object.assign({ id: PAYROLL_RUN_ID, employee_ids: employeeIds }, payload)),
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                    loadRunDetail();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
        });
        },
    });
}
// 2026-08-31: Verify now carries the freeze-from-recalculation behavior Lock used to have (Lock
// itself was retired entirely -- see PayrollRunModel::isEmployeeVerifiedForRun()/recalculate()) so
// every path that turns verification ON must confirm first ("ก่อนกดให้มี Confirm Sweet2 ก่อน").
// Turning it back OFF (un-verify) needs no confirm -- it only restores normal recalculation, the
// same low-stakes direction Lock's own "Unlock" never required a confirm for either.
$(document).on('click', '#btnBulkVerify', function () {
    bulkVerifyLockRd('/api/payroll-run.employee-verify.bulk', { verified: true },
        langData['confirm_bulk_verify_title'] || 'Verify {count} selected employee(s)?',
        langData['confirm_bulk_verify_message'] || 'Verified employees will no longer be recalculated and cannot be edited until unverified.',
        langData['verify_status_verified'] || 'Verified');
});
function singleVerifyLockRd(url, employeeId, payload, successMsgKey) {
    $.ajax({
        url: `${BASE_URL}${url}`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify(Object.assign({ id: PAYROLL_RUN_ID, employee_id: employeeId }, payload)),
        success: function (res) {
            if (res.status) {
                showSuccess(langData[successMsgKey] || res.message || langData['save_success'] || 'Saved successfully.');
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}
// 2026-09-11, Batch 3C item 9, explicit instruction: "กดแล้ว confirm ก่อนทุกครั้ง" -- unverify used to
// skip confirm entirely (see the 2026-08-31 comment above bulkVerifyLockRd(), now superseded). Both
// directions confirm now, each with its own wording that names the employee and states the actual
// action (not a generic "OK").
// 2026-09-20, 3e-1 (§12 rule 6): goes through showConfirm() like every other confirm in this app --
// see bulkVerifyLockRd()'s own note above for why the "showConfirm hardcodes Yes/No" reasoning this
// call was written on no longer holds (`confirmText`/`cancelText` are real options).
$(document).on('click', '.btn-verify-employee', function () {
    const employeeId = $(this).data('employee-id');
    const employeeName = $(this).data('employee-name') || '';
    const nowVerified = $(this).data('verified') !== true && $(this).data('verified') !== 'true';
    const title = (nowVerified
        ? (langData['confirm_verify_employee_title'] || 'Confirm that {name}\'s data in this run has been verified')
        : (langData['confirm_unverify_employee_title'] || 'Unverify {name}')
    ).replace('{name}', employeeName);
    const message = nowVerified
        ? (langData['confirm_verify_employee_message'] || 'This employee will no longer be recalculated and cannot be edited until unverified.')
        : (langData['confirm_unverify_employee_message'] || 'This employee will resume normal recalculation and can be edited again.');
    const confirmButtonText = nowVerified
        ? (langData['verify_status_verified'] || 'Verified')
        : (langData['action_unverify'] || 'Unverify');
    showConfirm({
        title: title,
        message: message,
        confirmText: confirmButtonText,
        cancelText: langData['cancel'] || 'Cancel',
        onYes: function () {
            singleVerifyLockRd('/api/payroll-run.employee-verify.save', employeeId, { verified: nowVerified }, 'save_success');
        },
    });
});
// 2026-08-31, explicit request: "สามารถ Verify ทั้ง Process ได้เลย...ให้ Verify ได้ทั้ง Process ทั้ง Detail
// และหน้า List" -- verifies every employee currently in the run in one action. Section-header button
// (see renderSectionButtons()), not part of the selection-scoped bulk bar, so it always needs its own
// confirm regardless of what (if anything) is currently checked.
$(document).on('click', '#btnVerifyAllEmployees', function () {
    // 2026-09-11, Batch 3C item 9, explicit instruction: "ให้ confirm พร้อมจำนวนคน" -- currentRun's
    // own employee_count (loaded whole client-side, not paginated -- see initRunDetailTable()'s own
    // comment) is the true total, not just however many rows the DataTable happens to have rendered.
    const empCount = (currentRun && currentRun.employee_count) || 0;
    showConfirm((langData['confirm_verify_all_title'] || 'Verify all {count} employee(s) in this run?').replace('{count}', empCount),
        langData['confirm_verify_all_message'] || 'Every employee in this run will no longer be recalculated and cannot be edited until unverified.',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-run.employee-verify.all`, method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ id: PAYROLL_RUN_ID }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                        loadRunDetail();
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                    }
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
            });
        });
});

let employeeCommentEmployeeId = null;
// 2026-08-29: set while editing an existing comment (null = the form is in "add new" mode). Reset
// on modal close/cancel/successful add so reopening the modal for a different employee, or for the
// same one later, always starts fresh in "add" mode.
let employeeCommentEditingId = null;
// 2026-09-14, Round 3 -- migrated off this modal's own bespoke .apv-comment-* markup/tag-meta-map
// (the hardcoded gradient pills/per-item icon-circle marker are GONE, this was the only real call
// site), first onto the shared Timeline component (renderTimeline()), then LATER THE SAME DAY onto
// its own dedicated renderCommentList() (app.js) instead -- a comment's own avatar+2-line shape
// (with inline-edit) fits that purpose-built component far better than continuing to stretch
// Timeline's dot-and-connecting-line event-log shape to cover it too. See rules.md §6's own "Comment
// list" section for exactly where the line between the 2 components sits now, and app.js's own
// comment on renderTimeline()'s revert for what got removed from THAT component as a result.
// A comment's tag renders through the CENTRAL status_map system instead of its own bespoke color
// map -- app/config/status_map.php's 'employee_comment_tag' context supplies the badge. The
// compose-time TAG PICKER (Round 3 Phase B: statusBadgeHtml()-based outline/filled chips, not the
// old gradient pills) is a separate, already-documented change (that file's own comment).
//
// employeeCommentsCache holds the CURRENTLY loaded list (server order = newest-first, see
// PayrollRunModel::employeeComments()'s own docblock) -- kept in memory (not just re-fetched every
// time) so a just-added comment can be unshifted onto it and the list re-rendered locally, no 2nd
// network round trip (explicit instruction, item 4: "ส่งแล้ว append เข้า timeline ทันทีโดยไม่ reload").
// Also lets .btn-edit-employee-comment below read back the RAW (un-escaped) comment text from this
// cache directly instead of scraping a `data-raw-comment` DOM attribute the old bespoke markup used
// to carry (the shared comment-list component's own markup has no such attribute, and shouldn't
// need one just for this).
let employeeCommentsCache = [];
// The 4 tag chips every comment composer in this modal offers, in display order. `value` is what the
// API stores/reads back (empty string = no tag at all), `enum` is the app/config/status_map.php key
// ('employee_comment_tag' context) that supplies each chip's own label + tone -- 'none' exists in
// that map ONLY for this picker (an already-posted comment with no tag renders no badge at all, see
// employeeCommentToListItem() below). One array, used by BOTH the compose box and every inline-edit
// box, so the two can never offer different chips.
const EMPLOYEE_COMMENT_TAGS = [
    // `outline` = "when THIS choice is the current one, the dropdown's own toggle renders as an
    // outline badge" (badgeDropdownHtml(), §5) -- an untagged comment's picker should read as an
    // empty control, not as a filled gray badge asserting "no tag" as if it were a real status.
    { value: '', enum: 'none', outline: true },
    { value: 'in_progress', enum: 'in_progress' },
    { value: 'completed', enum: 'completed' },
    { value: 'error', enum: 'error' },
];
// A comment's own author name in whichever language is active, falling back to the other one rather
// than rendering an empty name (some employee records only ever have one of the two filled in).
function employeeCommentActorName(c) {
    return (currentLang === 'th'
        ? (c.created_by_name_th || c.created_by_name_en)
        : (c.created_by_name_en || c.created_by_name_th)) || '-';
}
// Every id/name inside an inline-edit composer derives from this prefix -- per-comment-id so an open
// inline edit and the always-present compose box (prefix 'employeeComment') can never collide in the
// DOM while both exist, which is also what lets snapshotFormState() (§9's dirty guard) treat them as
// 2 separate fields instead of silently merging them.
function employeeCommentEditIdPrefix(commentId) {
    return `employeeCommentEdit${commentId}`;
}
// 2026-09-14, Round 3 item 3c-4, explicit instruction, item 1: "กดดินสอแล้วรายการนั้นเปลี่ยนเป็น textarea
// (ข้อความเดิม) + tag picker + ปุ่ม [บันทึก][ยกเลิก] ... ภายในรายการ".
// 2026-09-15, Round 3 (comment-list restyle), explicit instruction: that form is now literally the
// SAME composer component the compose box at the top of the list is (commentComposerHtml(), app.js --
// rules.md §6's own "Comment list" section, item 4: "รายการนั้นเปลี่ยนเป็น composer โครงเดียวกับข้อ 1"),
// not a second hand-kept copy of a similar shape. Only 3 things differ from the compose box, all of
// them arguments: the author shown is the COMMENT'S OWN author (editing doesn't change who said it),
// the textarea/tag chips start prefilled with what's already saved, and the buttons are
// [บันทึก][ยกเลิก] rather than a single [บันทึก]. Button sizes are normal (not `btn-sm`) per §4 --
// these are modal buttons, and `btn-sm` is reserved for table-row/toolbar/filter-bar buttons.
// The whole <li> becomes this box (renderCommentList()'s own `bodyHtml` now replaces the entire item,
// not just its text row) -- the composer already renders its own author row and its own buttons, so
// keeping the item's name row above it and its time/actions row below it would only duplicate them.
function employeeCommentInlineEditFormHtml(c) {
    const idPrefix = employeeCommentEditIdPrefix(c.id);
    return commentComposerHtml({
        idPrefix: idPrefix,
        actor: { name: employeeCommentActorName(c), avatar: c.created_by_photo || null },
        text: c.comment || '',
        tag: c.tag || '',
        placeholder: langData['employee_comment_placeholder'] || 'Write a comment...',
        tags: EMPLOYEE_COMMENT_TAGS,
        tagContext: 'employee_comment_tag',
        // The class is this modal's own delegated-handler hook (input/Ctrl+Enter, further below);
        // `data-id` is how those handlers know WHICH comment's box fired, since several could in
        // principle exist in the DOM at once even though only 1 is ever actually open.
        textareaClass: 'employee-comment-inline-edit-text',
        textareaAttrs: `data-id="${escapeAttr(c.id)}"`,
        actions: `<button type="button" class="btn btn-primary btn-save-inline-comment-edit" data-id="${escapeAttr(c.id)}" data-i18n="save">${escapeHtml(langData['save'] || 'Save')}</button>`
            + `<button type="button" class="btn btn-outline-secondary btn-cancel-inline-comment-edit" data-id="${escapeAttr(c.id)}" data-i18n="cancel">${escapeHtml(langData['cancel'] || 'Cancel')}</button>`,
    });
}
function employeeCommentToListItem(c) {
    const isEditing = employeeCommentEditingId !== null && Number(employeeCommentEditingId) === Number(c.id);
    if (isEditing) {
        // 2026-09-15, Round 3 (comment-list restyle): the whole item becomes the composer box
        // (renderCommentList()'s `bodyHtml` now replaces the entire <li>, not just its text row) --
        // so nothing else on the item needs suppressing one field at a time any more. The composer
        // already shows this comment's own author, its current text and its current tag (editable),
        // and owns its own [บันทึก][ยกเลิก] buttons; a name row/time row/edit-delete icons around
        // it would only duplicate what it already renders, on a row you're actively editing.
        return { bodyHtml: employeeCommentInlineEditFormHtml(c) };
    }
    // 2026-08-29, explicit request: "สามารถแก้ไข Comment และลบ Comment ได้ด้วย" -- a small "(edited)"
    // marker only when updated_at is actually set (a never-edited comment keeps both updated_by/
    // updated_at null, see PayrollRunModel::employeeCommentUpdate()'s own docblock).
    // 2026-09-14, Round 3, explicit instruction: renderCommentList() has no separate "detail" slot
    // the old Timeline-based item shape had to fold this into -- `item.timeSuffix` (a small,
    // deliberate addition to renderCommentList()'s own item shape, see app.js's own comment on that
    // field) is where it lives instead: rendered muted right after the relative time (the item's own
    // bottom row), not mixed into the comment's own text.
    const editedSuffix = c.updated_at ? `(${langData['employee_comment_edited'] || 'edited'})` : null;
    // 2026-08-29, explicit follow-up: "ดูได้เท่านั้น ไม่สามารถเพิ่ม แก้ไข ลบได้" -- edit/delete icons per
    // comment are dropped entirely once the run has finished (commentsReadOnlyRd()), not just
    // disabled, matching the same "view-only means the control isn't there at all" pattern Verify/
    // Lock's own View Mode already uses elsewhere on this page.
    // Both icons are the same resting gray (.btn-icon-ghost/.comment-item-icon-btn) -- delete opts
    // into .comment-item-icon-btn-danger (style.css) instead of an always-red class, so it only
    // turns --c-danger on hover.
    const actions = commentsReadOnlyRd() ? '' : `
        <button type="button" class="btn-icon-ghost comment-item-icon-btn btn-edit-employee-comment" data-id="${c.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>
        <button type="button" class="btn-icon-ghost comment-item-icon-btn comment-item-icon-btn-danger btn-delete-employee-comment" data-id="${c.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
    return {
        time: c.created_at,
        timeSuffix: editedSuffix,
        actor: { name: employeeCommentActorName(c), avatar: c.created_by_photo || null },
        text: c.comment,
        // A comment with no tag renders no badge at all (renderCommentList() skips it entirely when
        // `item.badge` is falsy) -- not a bespoke gray "no tag" badge of its own; that visual only
        // exists in the compose/inline-edit TAG PICKER (Phase B), never on an already-posted comment.
        badge: c.tag ? { enum: c.tag, context: 'employee_comment_tag' } : null,
        actions: actions,
    };
}
// Shared empty-state (§6 item 6e, app.js's emptyStateHtml()) -- explicit instruction, item 5. This
// is the "ยังไม่มีข้อมูล" meaning (nothing posted yet, not a filtered-zero-results case) -- no
// `action` button needed, the compose form is already visible right below in the body, unlike a
// table's own separate "Add" trigger.
// 2026-09-14, Round 3 Phase B, explicit instruction: subline dropped -- single heading only. No
// `text` passed at all (emptyStateHtml()'s own `config.text || ''` already renders nothing visible
// either way, but omitting it here is the source of truth, not a blank string happening to look
// empty) -- the now-orphaned `employee_comment_timeline_empty_hint` key (confirmed via grep: this
// was its only call site anywhere in the app) is removed from both lang files.
// 2026-09-14, real bug found and fixed: this function used to check `employeeCommentsCache.length`
// itself and call emptyStateHtml() directly, BEFORE renderCommentList() knew how to handle an empty
// array at all -- renderCommentList() now owns that case itself (app.js's own comment on it), so
// this is just a plain map+render again, no branch needed here. Still supplies its own `emptyState`
// config (icon + the real i18n-driven title) via the new `options` param -- renderCommentList()'s
// own built-in default is a bare, non-localized fallback, never meant to be what a real caller
// actually shows.
// 2026-09-15, explicit instruction, item 3: the modal's own title carries the live count --
// "คอมเมนต์ (N)" -- so it is no longer a plain `data-i18n` label a DOM sweep can translate on its
// own (the count is data, not copy). The `{count}` placeholder convention is this app's existing one
// (`.replace('{count}', n)`, same as confirm_bulk_verify_title and ~8 others), and
// refreshPayrollDetailLanguage() (this file, registered in changeLanguage()) calls this again on a
// live language switch so the title still relabels without a reload -- the exact pattern that hook
// already exists for.
function updateEmployeeCommentTitle() {
    const n = employeeCommentsCache.length;
    const tpl = langData['employee_comment_timeline_title_count'] || 'Comments ({count})';
    $('#employeeCommentModalTitle').text(tpl.replace('{count}', n));
}
function renderEmployeeCommentListFromCache() {
    updateEmployeeCommentTitle();
    const items = employeeCommentsCache.map(employeeCommentToListItem);
    $('#employeeCommentList').html(renderCommentList(items, {
        emptyState: {
            icon: 'fa-solid fa-comments',
            title: langData['employee_comment_timeline_empty'] || 'No comments yet.',
        },
    }));
}
function loadEmployeeComments() {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.employee-comment.list`, method: 'GET',
        data: { id: PAYROLL_RUN_ID, employee_id: employeeCommentEmployeeId }, dataType: 'json',
        success: function (res) {
            if (res.status) {
                employeeCommentsCache = res.data || [];
                renderEmployeeCommentListFromCache();
            }
        }
    });
}
// 2026-09-15, Round 3 (comment-list restyle), rules.md §6 item 1: the compose box is the shared
// composer component rendered at the TOP of the modal body, above the list -- the logged-in user's
// own avatar+name, an auto-growing borderless textarea, the 4 tag chips and a single [บันทึก]
// button. Re-rendered from scratch on every open/reset rather than field-by-field cleared: the box's
// whole state is 2 values (text + selected tag) and re-rendering is the only way that can't drift
// out of sync with what commentComposerHtml() itself considers a fresh box.
// `window.SESSION_USER` (layout/header.php, injected app-wide alongside window.STATUS_MAP) is the
// only source for "who is writing" -- guarded, so a page that somehow renders this without the
// layout still gets a working composer (just an initial-less avatar), never a crash.
function sessionUserCommentActor() {
    const u = window.SESSION_USER || {};
    const name = (currentLang === 'th' ? (u.name_th || u.name_en) : (u.name_en || u.name_th)) || '';
    return { name: name, avatar: u.photo || null };
}
function renderEmployeeCommentComposer() {
    $('#employeeCommentComposer').html(commentComposerHtml({
        idPrefix: 'employeeComment',
        actor: sessionUserCommentActor(),
        placeholder: langData['employee_comment_placeholder'] || 'Write a comment...',
        tags: EMPLOYEE_COMMENT_TAGS,
        tagContext: 'employee_comment_tag',
        // Normal size, not `btn-sm` (§4: `btn-sm` is for table-row/toolbar/filter-bar buttons only).
        // Starts `disabled` -- there is nothing to save in a box that was just rendered empty;
        // refreshEmployeeCommentSubmitState() below is what ever enables it.
        actions: `<button type="button" class="btn btn-primary" id="btnAddEmployeeComment" data-i18n="save" disabled>${escapeHtml(langData['save'] || 'Save')}</button>`,
    }));
}
// 2026-09-14, Round 3 item 3c-4, explicit instruction, item 2: submit button disabled until there's
// real text, OR while an inline edit is open elsewhere in the list (item 1: "ฟอร์มเพิ่ม...disabled
// ระหว่างแก้") -- reused on every place the compose textarea's value (or the editing state) can
// change, so the button's state never lags behind either.
function refreshEmployeeCommentSubmitState() {
    const hasText = (($('#employeeCommentText').val() || '') + '').trim() !== '';
    $('#btnAddEmployeeComment').prop('disabled', !hasText || employeeCommentEditingId !== null);
}
// 2026-09-14, Round 3 item 3c-4, explicit instruction, item 1: "composer บนสุด disabled ระหว่างแก้"
// -- disables the compose box's own fields (4 tag radios + textarea) while ANY item in the list is
// open for inline edit, re-enabling them the moment that edit exits (save or cancel). Reused by
// resetEmployeeCommentForm() (modal open/close) and enter/exitEmployeeCommentInlineEdit() below --
// the single source of truth for this disabled state, so it can never drift between call sites.
function refreshEmployeeCommentAddFormDisabledState() {
    const editing = employeeCommentEditingId !== null;
    // `.badge-dropdown-toggle` is a <button>, not an input -- the tag control stopped being a set of
    // radios when it became a badge dropdown (2026-09-15), so disabling `input, textarea` alone would
    // have left the tag still changeable while an inline edit is open.
    $('#employeeCommentComposer').find('input, textarea, .badge-dropdown-toggle').prop('disabled', editing);
    refreshEmployeeCommentSubmitState();
}
function resetEmployeeCommentForm() {
    employeeCommentEditingId = null;
    renderEmployeeCommentComposer();
    refreshEmployeeCommentAddFormDisabledState();
}
// 2026-09-14, Round 3 item 3c-4, explicit instruction, item 1: "กดดินสอแล้วรายการนั้นเปลี่ยนเป็น textarea
// ...แก้ได้ทีละรายการ" -- enter/exit are the only 2 places employeeCommentEditingId ever changes once
// the modal is open (resetEmployeeCommentForm(), called on modal open/close, is the 3rd). Re-rendering
// the WHOLE list from cache on every enter/exit (rather than patching just the 1 affected <li>) keeps
// employeeCommentToListItem() the single place that decides "is THIS item the one being edited" --
// simpler than 2 divergent render paths, and this list is never long enough for a full re-render to
// be a real perf concern.
function enterEmployeeCommentInlineEdit(id) {
    employeeCommentEditingId = Number(id);
    refreshEmployeeCommentAddFormDisabledState();
    renderEmployeeCommentListFromCache();
    const $textarea = $(`#${employeeCommentEditIdPrefix(id)}Text`);
    $textarea.trigger('focus');
    // The inline textarea is injected already pre-filled with the existing comment text -- input.js's
    // own T002 auto-grow only fires on a real `input` event, so a freshly-injected multi-line value
    // needs this one explicit call to size correctly from the start instead of showing a clipped
    // 1-row box until the user's first keystroke.
    if ($textarea.length) autoExpandTextarea($textarea[0]);
    syncEmployeeCommentDirtyBaseline();
}
function exitEmployeeCommentInlineEdit() {
    employeeCommentEditingId = null;
    refreshEmployeeCommentAddFormDisabledState();
    renderEmployeeCommentListFromCache();
    syncEmployeeCommentDirtyBaseline();
}
// 2026-09-15, Round 3 (comment-list restyle), rules.md §6 item 4 ("dirty-guard ครอบทั้ง composer
// และรายการที่กำลังแก้"): entering/leaving an inline edit changes which fields exist in the modal at
// all (the edit box appears/disappears; the compose box's own fields switch disabled on/off, and
// snapshotFormState() ignores disabled fields entirely) -- so without re-capturing the baseline at
// those 2 moments, merely OPENING an edit would count as "unsaved changes" and prompt on close even
// if nothing was typed. Re-capturing means the guard asks about REAL content the user typed, in
// either box, which is what the rule actually wants covered. Cancelling an edit is an explicit
// discard, so re-capturing on the way out is correct too.
function syncEmployeeCommentDirtyBaseline() {
    if (typeof refreshDirtyGuard === 'function') refreshDirtyGuard('#employeeCommentModal');
}
// Mirrors refreshEmployeeCommentSubmitState() above, for whichever item's own inline Save button
// this is -- `id` scopes both the textarea read and the button written to, since several comments
// could in principle each carry their own (currently-disabled, per item 1's "ทีละรายการ") Save button
// in the DOM at once, even though only 1 is ever actually enabled/visible-as-a-form at a time.
function refreshInlineEditSaveState(id) {
    const hasText = (($(`#${employeeCommentEditIdPrefix(id)}Text`).val() || '') + '').trim() !== '';
    $(`.btn-save-inline-comment-edit[data-id="${id}"]`).prop('disabled', !hasText);
}
// 2026-08-29, explicit follow-up request: "ถ้าการดำเนินเสร็จแล้ว Comment ดูได้เท่านั้น ไม่สามารถเพิ่ม แก้ไข
// ลบได้" -- deliberately a NARROWER cutoff than isViewMode (currentRun.state !== 'draft') used
// elsewhere on this page; see PayrollRunModel::COMMENT_LOCKED_STATES's own docblock for why
// comments stay editable through pending_approval/approved/rejected/need_info (still "in
// progress") and only lock once the run has genuinely finished. Server-side enforcement lives in
// that same constant, checked in employeeCommentAdd()/Update()/Delete() -- this client-side gate
// is purely so the form controls don't even appear, not the actual authorization boundary.
const COMMENT_LOCKED_STATES_RD = ['paid', 'locked', 'cancelled'];
function commentsReadOnlyRd() {
    return !!currentRun && COMMENT_LOCKED_STATES_RD.includes(currentRun.state);
}
$(document).on('click', '.btn-comment-employee', function () {
    employeeCommentEmployeeId = $(this).data('employee-id');
    // 2026-09-11, Batch 3C item 8: employeeHeaderCardHtml() (app.js) block first, no more employee
    // name in the modal-header (#employeeCommentModalEmployeeName removed from the view).
    const rowData = runDetailRowByEmployeeId(employeeCommentEmployeeId);
    $('#employeeCommentHeaderCard').html(rowData ? employeeHeaderCardHtml(rowData) : '');
    const readOnly = commentsReadOnlyRd();
    // 2026-09-14, Round 3 item 3c-4: footer rendered through the shared modalFooterButtonsHtml()
    // (app.js).
    // 2026-09-15, Round 3 (comment-list restyle), explicit instruction: it is now [ปิด] ALONE. The
    // submit button moved into the composer box itself (renderEmployeeCommentComposer() above, where
    // what it submits is actually visible right next to it), so the footer no longer has a primary
    // button to keep in sync with the view-only/editing state at all -- which also means this html()
    // call is now genuinely constant and could be hoisted, but it stays here so the whole modal is
    // still populated from one place on open.
    $('#employeeCommentModalFooter').html(modalFooterButtonsHtml({
        secondary: { key: 'close', fallback: 'Close', dismiss: true },
    }));
    // One delegated init for EVERY badge dropdown inside this modal -- the composer's own tag picker,
    // and whichever inline-edit box happens to be open -- guarded inside initBadgeDropdown() so
    // reopening the modal can't stack handlers (app.js's own once-per-scope flag).
    initBadgeDropdown('#employeeCommentModal');
    // Zero it out up front -- loadEmployeeComments() fills the real number in a moment, and this way
    // the title never shows the PREVIOUS employee's count while that request is in flight.
    employeeCommentsCache = [];
    updateEmployeeCommentTitle();
    resetEmployeeCommentForm();
    $('#employeeCommentComposer').toggleClass('d-none', readOnly);
    $('#employeeCommentReadOnlyNotice').toggleClass('d-none', !readOnly);
    loadEmployeeComments();
    new bootstrap.Modal(document.getElementById('employeeCommentModal')).show();
});
$(document).on('hidden.bs.modal', '#employeeCommentModal', function () {
    resetEmployeeCommentForm();
});
$(document).on('click', '.btn-edit-employee-comment', function () {
    enterEmployeeCommentInlineEdit($(this).data('id'));
});
$(document).on('click', '.btn-cancel-inline-comment-edit', function () {
    exitEmployeeCommentInlineEdit();
});
$(document).on('click', '.btn-save-inline-comment-edit', function () {
    const id = $(this).data('id');
    const comment = ($(`#${employeeCommentEditIdPrefix(id)}Text`).val() || '').trim();
    if (!comment) {
        showWarning(langData['employee_comment_required'] || 'Please write a comment first.');
        return;
    }
    const tag = $(`input[name="${employeeCommentEditIdPrefix(id)}Tag"]`).val() || null;
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.employee-comment.update`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, comment_id: id, tag: tag, comment: comment }),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                // res.comment is the full updated row (PayrollRunModel::employeeCommentUpdate()'s own
                // widened response, same shape employeeComments() itself returns) -- patched straight
                // into the cache in place so the just-saved `updated_at`/"(edited)" marker shows up
                // without a 2nd network round trip, same "no reload" pattern item 4 of the previous
                // round already established for a new comment.
                if (res.comment) {
                    const idx = employeeCommentsCache.findIndex(function (c) { return Number(c.id) === Number(id); });
                    if (idx !== -1) employeeCommentsCache[idx] = res.comment;
                }
                exitEmployeeCommentInlineEdit();
                // Re-captures the dirty-guard baseline -- without this, closing the modal right after
                // a successful inline save would still compare against the PRE-save baseline (which
                // had this item NOT in edit mode, i.e. matches the post-exit state anyway here), but
                // this is the correct general pattern (see refreshDirtyGuard()'s own docblock, app.js).
                if (typeof refreshDirtyGuard === 'function') refreshDirtyGuard('#employeeCommentModal');
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
$(document).on('click', '.btn-delete-employee-comment', function () {
    const commentId = $(this).data('id');
    showConfirm(langData['confirm_delete_title'] || 'Confirm Delete', langData['confirm_delete_message'] || 'Are you sure you want to delete this item?', function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.employee-comment.delete`, method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify({ id: PAYROLL_RUN_ID, comment_id: commentId }),
            success: function (res) {
                if (res.status) {
                    if (employeeCommentEditingId === commentId) exitEmployeeCommentInlineEdit();
                    loadEmployeeComments();
                    loadRunDetail(); // refreshes the comment-count badge on the row's Comment button
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                }
            },
            error: function () { showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.'); }
        });
    });
});
$(document).on('click', '#btnAddEmployeeComment', function () {
    const comment = ($('#employeeCommentText').val() || '').trim();
    if (!comment) {
        showWarning(langData['employee_comment_required'] || 'Please write a comment first.');
        return;
    }
    const tag = $('input[name="employeeCommentTag"]').val() || null;
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.employee-comment.add`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: employeeCommentEmployeeId, tag: tag, comment: comment }),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                // 2026-09-14, Round 3 item 3c-3, explicit instruction, item 4: a NEW comment appends
                // straight into the in-memory cache/re-renders locally -- no 2nd network round trip.
                // res.comment is the full new row (PayrollRunModel::employeeCommentAdd()'s own
                // widened response, same shape employeeComments() itself returns) -- unshift, not
                // push, since the list is newest-first now.
                if (res.comment) {
                    employeeCommentsCache.unshift(res.comment);
                    renderEmployeeCommentListFromCache();
                } else {
                    loadEmployeeComments();
                }
                resetEmployeeCommentForm();
                // Re-captures the dirty-guard baseline against the now-cleared form -- without this,
                // closing the modal right after a successful submit would incorrectly still compare
                // against the PRE-submit (empty) baseline and never prompt anyway in THIS specific
                // case (empty -> empty is never dirty), but this is the correct general pattern any
                // future save-that-keeps-the-modal-open flow needs (see refreshDirtyGuard()'s own
                // docblock, app.js).
                if (typeof refreshDirtyGuard === 'function') refreshDirtyGuard('#employeeCommentModal');
                loadRunDetail(); // refreshes the comment-count badge on the row's Comment button
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
// 2026-09-14, Round 3 item 3c-3, explicit instruction, item 2: submit button disabled until there's
// real text (typing/pasting/clearing all keep this in sync -- see refreshEmployeeCommentSubmitState()'s
// own docblock for the other call sites that also need it).
$(document).on('input', '#employeeCommentText', function () {
    refreshEmployeeCommentSubmitState();
});
// Ctrl/Cmd+Enter submits (explicit instruction, item 2) -- only when the button isn't already
// disabled (empty text) or mid-request (setButtonLoading() above already disables it while an
// add/update is in flight), same guard a real click on the button gets for free from its own
// `disabled` attribute.
$(document).on('keydown', '#employeeCommentText', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        if (!$('#btnAddEmployeeComment').prop('disabled')) {
            $('#btnAddEmployeeComment').trigger('click');
        }
    }
});
// Same 2 conveniences (submit-state sync + Ctrl/Cmd+Enter), delegated for whichever comment's own
// inline-edit textarea is currently in the DOM -- mirrors the compose textarea's own pair above.
$(document).on('input', '.employee-comment-inline-edit-text', function () {
    refreshInlineEditSaveState($(this).data('id'));
});
$(document).on('keydown', '.employee-comment-inline-edit-text', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        const id = $(this).data('id');
        const $saveBtn = $(`.btn-save-inline-comment-edit[data-id="${id}"]`);
        if (!$saveBtn.prop('disabled')) $saveBtn.trigger('click');
    }
});
// 2026-09-14, Round 3 item 3c-4, explicit instruction, item 1: "Esc = ยกเลิกแก้ (ไม่ปิด modal -- ใช้
// capture-phase แบบ popover)" -- same pattern app.js's own popover Esc handler already uses
// (document-level, CAPTURE phase so this runs on the way DOWN before the event reaches the modal's
// own Esc-closes-modal behavior, stopPropagation() there to stop delivery to everything still ahead
// of it including that handler) -- but only intercepts when an inline edit is actually open; an Esc
// press with none open must still reach the modal normally (e.g. to close the modal itself).
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (employeeCommentEditingId === null) return;
    // 2026-09-15: an OPEN badge dropdown (the tag picker, §5) owns Escape first -- Bootstrap's own
    // dropdown handler closes it and returns focus to the toggle. Swallowing the key here instead
    // would close the whole inline edit out from under a user who only meant to dismiss the menu.
    if (document.querySelector('#employeeCommentModal .dropdown-menu.show')) return;
    e.stopPropagation();
    exitEmployeeCommentInlineEdit();
}, true);

// 2026-09-22, 3e-3 round B1, explicit instruction/user confirmation (2026-09-22): the vertical
// Timeline card list this tab used to render (AUDIT_TIMELINE_META_RD/auditTimelineMetaRd()/
// auditHistoryRowHtmlRd()/renderAuditHistoryTimelineRd()/auditHistoryEntries -- all deleted here,
// 0 consumers outside that block per 3e-3 round A's own grep) is replaced with a real DataTable --
// rules.md §6 now carries a line for this
// exact case ("feed ที่โตไม่จำกัด = DataTable, Timeline = feed สั้นที่ตัดยอดได้"): run 752 in dev alone
// carries 1571 rows (round A's own COUNT), far past anything a card-per-row list can show without
// its own pagination, which is exactly what DataTables already does for free. Same data source as
// before (payroll-run.get's own `audit_log`, PayrollRunModel::getAuditLog() -- unmodified, backend
// untouched), same tab, same call site (loadRunDetail()) -- only the renderer changed.
// Column titles are NOT static `<th>` text here, unlike every other table on this page -- every
// column's content is language-bound at RENDER time (auditActionLabel()/statusBadgeHtml()/the
// actor's own th/en name), so `columns[].title` is set from langData in JS at construction, and
// refreshAuditLogTableLanguage() (called from refreshPayrollDetailLanguage()) rewrites both the
// header cells and every row's rendered output on a live language switch.
// §5.2 "machine code ห้ามเป็นข้อความบนจอ" -- auditActionLabel() (app.js) falls back to the RAW action
// code itself for a code with no i18n mapping (its own documented behaviour, correct for every
// OTHER caller). A label that comes back byte-identical to the `action` it was given means that
// fallback fired; this substitutes the one shared `action_unknown` key instead and keeps the real
// code recoverable via `data-code` rather than shown as text.
function auditActionLabelInfoRd(action) {
    const label = auditActionLabel(action);
    if (label === action) {
        return { label: getLangValue('action_unknown') || 'Unknown action', known: false };
    }
    return { label: label, known: true };
}
function auditActionCellHtmlRd(action) {
    const info = auditActionLabelInfoRd(action);
    return info.known ? escapeHtml(info.label) : `<span data-code="${escapeAttr(action)}">${escapeHtml(info.label)}</span>`;
}
// "สถานะรอบ" shows `to_state` only (the state THIS action left the run in) -- `from_state` is not
// rendered, per the decided column spec. Shared with both the display badge and the Excel-filter's
// own filter/sort value (a label string, never the badge's HTML -- §5.1's own filter/sort rule).
function auditLogStateFilterTextRd(state) {
    const entry = getStatusMapEntry(state, 'run_state');
    return (entry && (getLangValue(entry.label_key) || entry.label_key)) || state || '';
}
// Note cell: single-line truncate (§7 "ทุกแถวต้องสูงเท่ากัน" -- round A found notes up to 283 chars in
// dev data) via the `.rd-audit-note-cell` CSS class (style.css). Raw English system note, unmodified
// (Batch 5 error-code i18n is separate work).
// 2026-09-23, 3e-3b round B1: the tooltip that used to show the untruncated text here (and the
// `data-full-note` attribute it read) is gone -- openAuditLogDetailRd()'s modal is the one place the
// full note is read now, straight from the row data, not from a DOM attribute.
function auditNoteCellHtmlRd(note) {
    if (!note) return '';
    return `<span class="rd-audit-note-cell">${escapeHtml(note)}</span>`;
}
// Device/IP cell -- same "OS · Browser N" summary + raw-string tooltip + separate IP line the old
// Timeline card rendered, just inside a table cell now. Both empty (every non-view_detail row
// written from a CLI/cron context, e.g. tests/ui/mksession.php, has neither) renders a plain '-',
// matching this file's own personDisplayNameRd() empty-value convention -- no dedicated lang key
// exists for a bare placeholder dash and this one is a symbol, not language content.
function auditDeviceIpCellHtmlRd(row) {
    const uaSummary = row.user_agent ? (formatUserAgentSummary(row.user_agent) || row.user_agent) : '';
    const ip = row.ip_address || '';
    if (!uaSummary && !ip) return '-';
    const lines = [];
    if (uaSummary) lines.push(`<div title="${escapeAttr(row.user_agent)}"><i class="fa-solid fa-desktop me-1"></i>${escapeHtml(uaSummary)}</div>`);
    if (ip) lines.push(`<div><i class="fa-solid fa-location-dot me-1"></i>${escapeHtml(ip)}</div>`);
    return `<div class="small text-muted">${lines.join('')}</div>`;
}
// Column titles read fresh from langData every time (construction AND refreshAuditLogTableLanguage()
// share this one function) so the 2 call sites can never drift on wording.
function auditLogColumnTitlesRd() {
    return [
        getLangValue('audit_performed_at') || 'Date/Time',
        getLangValue('audit_performed_by') || 'Performed By',
        getLangValue('audit_log_action') || 'Action',
        getLangValue('table_status') || 'Status',
        getLangValue('audit_note') || 'Note',
        getLangValue('audit_device_ip') || 'Device · IP',
        '', // 2026-09-23, 3e-3b round B1: button-only column ("ดูรายละเอียด") -- never has header text
    ];
}
// Built lazily inside initAuditLogTableRd()'s own construction branch, NOT as a module-level const
// evaluated at parse time -- this script runs synchronously as the page loads, well before
// window.langReady resolves (loadRunDetail(), this file's own call site, is deliberately deferred
// behind that promise; a plain top-level `const` here is not), so an eager getLangValue() call would
// have baked in whatever langData held before the real fetch completed -- empty, on a fresh load --
// permanently, since the same object reference is reused (only mutated in place by
// refreshAuditLogTableLanguage()) rather than rebuilt on every call.
let AUDIT_LOG_EMPTY_STATE_RD = null;
// 2026-09-23, 3e-3b round B1: the note cell's own tooltip (and the auditLogNoteTooltipsRd array /
// auditLogRefreshNoteTooltipsRd() drawCallback that rebuilt it on every draw) is gone -- grep
// confirmed 0 consumers left of either once the column-7 "ดูรายละเอียด" button + modal became the
// one way to read a note in full (rules.md §0.3). The cell itself still truncates
// (`.rd-audit-note-cell`, style.css) -- only the hover affordance is removed.
// ---------- Date-range filter above the table (2026-09-23, 3e-3b round B1) ----------
// `performed_at` is the ONE column the header checklists can't reach (a continuous range, not a
// closed set of values) -- everything else stays a column filter per round A's own decided fact.
// True whenever the range itself makes sense to filter by: either field empty (nothing to compare),
// or from <= to. Shared by the predicate (which must not narrow anything while the range is
// nonsensical) and the callout toggle (which must show/hide from the SAME truth, not a second
// re-derivation of it that could drift from the first).
function auditLogDateRangeValidRd() {
    const from = toIsoDateRd($('#auditLogDateFrom').val());
    const to = toIsoDateRd($('#auditLogDateTo').val());
    return !(from && to && from > to);
}
function updateAuditLogDateRangeCalloutRd() {
    $('#auditLogDateRangeInvalidCallout').toggleClass('d-none', auditLogDateRangeValidRd());
}
// 2026-09-23, 3e-3b round B5: `updateAuditLogFilterBarClearVisibilityRd()` (round B4 -- toggled
// `.filter-bar-clear` manually, since initFilterBar()'s own `refresh()` only ever counted
// `<select>` fields, always 0 in this bar) is gone -- `refresh()` itself is input-aware now
// (app.js), so the shared button's own visibility already reflects these 2 date fields correctly.
// `performed_at` is a raw MySQL DATETIME string with no timezone attached (Batch 5's own lesson --
// CLAUDE.md -- applies here too) -- compared as a STRING against the datepicker's own ISO value via
// toIsoDateRd(), never through a `Date` parse that would silently apply the browser's local offset
// to a value that was never UTC in the first place. Registered once (module-level guard, same shape
// as registerPaymentMethodSearchFilter() etc. above) and scoped to this one table's id so it can
// never affect any other DataTable on this page.
let auditLogDateRangeSearchFilterRegistered = false;
function registerAuditLogDateRangeSearchFilter() {
    if (auditLogDateRangeSearchFilterRegistered) return;
    auditLogDateRangeSearchFilterRegistered = true;
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (!settings.nTable || settings.nTable.id !== 'tb_run_audit_log') return true;
        if (!auditLogDateRangeValidRd()) return true; // nonsensical range -- warned via callout, don't narrow yet
        const from = toIsoDateRd($('#auditLogDateFrom').val());
        const to = toIsoDateRd($('#auditLogDateTo').val());
        if (!from && !to) return true;
        const day = String((rowData && rowData.performed_at) || '').slice(0, 10);
        if (from && day < from) return false;
        if (to && day > to) return false;
        return true;
    });
}
// ---------- #auditLogDetailModal (2026-09-23, 3e-3b round B1) ----------
// rules.md §9 "modal record-only" -- one #tb_run_audit_log row, read-only, no primary action. Kept
// so refreshAuditLogTableLanguage() can re-render the SAME entry after a live language switch while
// this modal is still open, instead of it freezing in whatever language it was opened in.
let auditLogDetailEntryRd = null;
function auditDetailValueDisplayRd(value) {
    return (value === null || value === undefined || value === '') ? '—' : escapeHtml(value);
}
function auditDetailFieldHtmlRd(labelKey, labelFallback, valueHtml) {
    return `<div class="rd-sync-field mb-3">
        <div class="rd-sync-field-label">${escapeHtml(getLangValue(labelKey) || labelFallback)}</div>
        <div class="rd-sync-field-value">${valueHtml}</div>
    </div>`;
}
// `toDisplayDateRd()` only converts a bare ISO DATE (its own docblock's contract) -- `performed_at`
// is a full "YYYY-MM-DD HH:MM:SS" DATETIME, so the time half is split off first and appended as-is
// rather than handed to a helper that was never built to parse it.
function auditDetailTimeHtmlRd(performedAt) {
    const parts = String(performedAt || '').split(' ');
    return escapeHtml(toDisplayDateRd(parts[0]) + (parts[1] ? ' ' + parts[1] : ''));
}
function auditDetailStatusHtmlRd(entry) {
    if (entry.from_state && entry.from_state !== entry.to_state) {
        return `${statusBadgeHtml(entry.from_state, 'run_state')}<span class="text-muted mx-1">&rarr;</span>${statusBadgeHtml(entry.to_state, 'run_state')}`;
    }
    return statusBadgeHtml(entry.to_state, 'run_state');
}
// Full text, line breaks kept (`.rd-audit-detail-note`, style.css: `white-space: pre-wrap`) --
// unlike the table cell's own single-line `.rd-audit-note-cell` truncate, this is the one place the
// note is shown in full now that the cell's tooltip is gone.
function auditDetailNoteHtmlRd(note) {
    return note ? `<span class="rd-audit-detail-note">${escapeHtml(note)}</span>` : '—';
}
function renderAuditLogDetailModalBody(entry) {
    $('#auditLogDetailModalBody').html([
        auditDetailFieldHtmlRd('audit_performed_at', 'Date/Time', auditDetailTimeHtmlRd(entry.performed_at)),
        auditDetailFieldHtmlRd('audit_performed_by', 'Performed By', apvPersonLineHtml(personDisplayNameRd(entry, 'performed_by'), 24, entry.performed_by_profile_photo_path, { employeeId: null })),
        auditDetailFieldHtmlRd('audit_log_action', 'Action', auditActionCellHtmlRd(entry.action)),
        auditDetailFieldHtmlRd('table_status', 'Status', auditDetailStatusHtmlRd(entry)),
        auditDetailFieldHtmlRd('audit_note', 'Note', auditDetailNoteHtmlRd(entry.note)),
        auditDetailFieldHtmlRd('device', 'Device', auditDetailValueDisplayRd(entry.user_agent)),
        auditDetailFieldHtmlRd('ip_address', 'IP', auditDetailValueDisplayRd(entry.ip_address)),
    ].join(''));
}
function openAuditLogDetailRd(entry) {
    auditLogDetailEntryRd = entry;
    renderAuditLogDetailModalBody(entry);
    new bootstrap.Modal(document.getElementById('auditLogDetailModal')).show();
}
// Constructed once; every subsequent call (a fresh loadRunDetail() after any mutating action) just
// swaps the data -- same `$.fn.DataTable.isDataTable()` reuse check tb_run_detail's own construction
// already uses, rather than destroying/rebuilding the table (and its column filters/tooltips) on
// every single reload.
function initAuditLogTableRd(entries) {
    const rows = entries || [];
    if ($.fn.DataTable.isDataTable('#tb_run_audit_log')) {
        $('#tb_run_audit_log').DataTable().clear().rows.add(rows).draw();
        return;
    }
    const titles = auditLogColumnTitlesRd();
    AUDIT_LOG_EMPTY_STATE_RD = {
        icon: 'fa-solid fa-clock-rotate-left',
        title: getLangValue('no_history_yet') || 'No action has been taken on this request yet.',
    };
    // Both fit inside this same reuse-guarded branch -- construction runs exactly once per page
    // load, same as the column filters below, so neither needs its own separate once-guard.
    initDatepicker('#auditLogDateFrom');
    initDatepicker('#auditLogDateTo');
    registerAuditLogDateRangeSearchFilter();
    // 2026-09-23, 3e-3b round B5: initFilterBar() now listens on `input.form-control` too (app.js),
    // so these 2 date fields genuinely fire `onChange` (debounced via its own scheduleNotify(),
    // same as a select's own change would) -- round B4's separate page-level `change` handler on
    // #auditLogDateFrom/To is gone, its 2 jobs (hide/show the callout, redraw the table) both moved
    // in here instead of running twice per change.
    initFilterBar('#auditLogFilterBar', {
        onChange: function () {
            updateAuditLogDateRangeCalloutRd();
            if (tb_run_audit_log) tb_run_audit_log.draw();
        },
    });
    tb_run_audit_log = initSharedDataTable('#tb_run_audit_log', {
        // §7 per-column Excel filter -- 4 of the 6 columns per the decided spec (not "เวลา", which
        // sorts instead, and not "หมายเหตุ", covered by the table's own global search box).
        columnFilters: {
            mode: 'client',
            columns: [
                { index: 1, key: 'audit_performed_by' },
                { index: 2, key: 'audit_action' },
                { index: 3, key: 'audit_state' },
                { index: 5, key: 'audit_device_ip' },
            ],
        },
        // 2026-09-23, 3e-3b round B4: now that #auditLogFilterBar exists, this page carries 2
        // `.filter-bar` instances (the other is #runDetailFilterBar, Employee tab) -- tableFilterBarFor()
        // (app.js) only auto-picks "the single `.filter-bar` on the page" when there is EXACTLY one,
        // so both tables now need this named explicitly (#tb_run_detail's own initSharedDataTable()
        // call gained the matching `filterBar: '#runDetailFilterBar'` in this same round, or its own
        // empty-state Clear button would have silently stopped clearing its selects).
        filterBar: '#auditLogFilterBar',
        emptyState: AUDIT_LOG_EMPTY_STATE_RD,
        dtOptions: {
            responsive: false,
            data: rows,
            order: [[0, 'desc']], // newest first, same convention the old Timeline card list used
            columns: [
                { data: 'performed_at', title: titles[0], render: {
                    display: (d) => escapeHtml(formatDisplayDateTime(d)),
                    sort: (d) => d, // raw MySQL timestamp string -- sorts correctly lexicographically; the display format does not
                    filter: (d) => d,
                } },
                { data: null, title: titles[1], render: {
                    display: (d, t, row) => apvPersonLineHtml(personDisplayNameRd(row, 'performed_by'), 24, row.performed_by_profile_photo_path, { employeeId: null }),
                    filter: (d, t, row) => personDisplayNameRd(row, 'performed_by'),
                    sort: (d, t, row) => personDisplayNameRd(row, 'performed_by'),
                } },
                { data: null, title: titles[2], render: {
                    display: (d, t, row) => auditActionCellHtmlRd(row.action),
                    filter: (d, t, row) => auditActionLabelInfoRd(row.action).label,
                    sort: (d, t, row) => auditActionLabelInfoRd(row.action).label,
                } },
                { data: null, title: titles[3], render: {
                    display: (d, t, row) => statusBadgeHtml(row.to_state, 'run_state'),
                    filter: (d, t, row) => auditLogStateFilterTextRd(row.to_state),
                    sort: (d, t, row) => auditLogStateFilterTextRd(row.to_state),
                } },
                // No Excel column filter on this one (search box covers it instead, per the decided
                // spec) -- object-form render is still needed so the search box matches the RAW note
                // text, not the truncated cell's own HTML (a bare function-form render is used for
                // every purpose alike, display included, which would make a plain-text search box
                // query have to contain literal markup to match anything).
                { data: 'note', orderable: false, title: titles[4], render: {
                    display: (d) => auditNoteCellHtmlRd(d),
                    filter: (d) => d || '',
                } },
                { data: null, title: titles[5], render: {
                    display: (d, t, row) => auditDeviceIpCellHtmlRd(row),
                    filter: (d, t, row) => row.ip_address || '',
                    sort: (d, t, row) => row.ip_address || '',
                } },
                // 2026-09-23, 3e-3b round B1: "ดูรายละเอียด" -- the ONE action this row has, so no
                // .btn-group/dropdown, just the one ghost circle (§7). `searchable:false` keeps the
                // global search box from ever matching this cell's own title attribute text.
                { data: null, orderable: false, searchable: false, responsivePriority: 1, title: titles[6], render: {
                    display: () => {
                        const label = escapeAttr(getLangValue('action_view_detail') || 'View Detail');
                        return `<button type="button" class="btn btn-icon btn-icon-ghost audit-log-view-detail-btn" title="${label}" aria-label="${label}"><i class="fa-solid fa-eye"></i></button>`;
                    },
                } },
            ],
        },
    });
}
// Registered in refreshPayrollDetailLanguage() (bottom of this file). Header text + the empty-state
// title are re-read from langData directly; row content (actor name/action label/state badge) needs
// `rows().invalidate()` first since a client-side DataTable caches each cell's already-rendered
// output and reuses it on a plain `.draw()` -- same fix class documented on app.js's own
// reloadAllTablesForLanguageChange(). `settings().oLanguage.sEmptyTable` is set directly before that
// draw (2026-09-22 lesson, this same round): the app-wide sync for that string
// (refreshAllDataTablesLanguage(), app.js) only runs from applyLanguage()'s own tail call, not
// synchronously inside this per-page hook, so setting it here too is what guarantees this table's
// own "no rows" message is never one draw cycle behind a fast language switch.
function refreshAuditLogTableLanguage() {
    if (!tb_run_audit_log) return;
    const titles = auditLogColumnTitlesRd();
    tb_run_audit_log.columns().every(function (idx) {
        const $th = $(this.header());
        const $titleEl = $th.find('.dt-column-title');
        ($titleEl.length ? $titleEl : $th).text(titles[idx]);
    });
    AUDIT_LOG_EMPTY_STATE_RD.title = getLangValue('no_history_yet') || 'No action has been taken on this request yet.';
    const settings = tb_run_audit_log.settings()[0];
    if (settings) settings.oLanguage.sEmptyTable = getLangValue('emptyTable') || settings.oLanguage.sEmptyTable;
    tb_run_audit_log.rows().invalidate().draw(false);
    // 2026-09-23, 3e-3b round B1: #auditLogDetailModal's own body is plain HTML built once at open
    // time (getLangValue() baked into strings, not live data-i18n spans) -- a language switch while
    // it's still open needs this explicit re-render from the SAME stored entry, or it would freeze
    // in whatever language it opened in. The filter bar's labels/callout need no such call: they are
    // data-i18n spans the app-wide switch already walks on its own.
    if (auditLogDetailEntryRd && $('#auditLogDetailModal').hasClass('show')) {
        renderAuditLogDetailModalBody(auditLogDetailEntryRd);
    }
}
// 2026-09-23, 3e-3b round B5: 2 of the 3 page-level handlers this comment used to retire really are
// gone for good --
//   1. the collapse toggle was always #auditLogFilterBar's OWN (`.filter-bar-toggle`, wired by
//      initFilterBar() itself), never bound here.
//   2. the `change` handler on #auditLogDateFrom/To -- initFilterBar()'s own `onChange` (this
//      table's own initAuditLogTableRd(), above) now does both of its jobs (callout, draw), since
//      app.js's own `refresh()`/`scheduleNotify()` finally listen on `input.form-control` too.
// The 3rd one was a REAL bug, caught by actually running o_history_dt.js (round B5's own measure
// pass) rather than reasoned about from the code alone: `.filter-bar-clear`'s OWN click handler
// (initFilterBar(), app.js) only ever calls `clearAllFields()` -- this bar's own fields, nothing
// else. It was NEVER the thing that cleared the search box / column-header checklists on this
// table; `#btnAuditLogClearFilter` (the page-local button rounds B1-B3 had) was, because ITS OWN
// handler explicitly called `clearAllTableFilters()`. Merging that button INTO the shared
// `.filter-bar-clear` button (B4) merged the ELEMENT but not that call -- so a real click on the
// bar's own Clear button stopped resetting search/column filters at all (verified failing: c14's
// own "search box empty"/"no column filter left checked" assertions, 2026-09-23). Re-added as the
// ONE thing this button still needs supplementing -- `dtRenderEmptyState()`'s own auto-clear button
// (empty-state row) already calls this same function directly and was never affected.
// 2026-09-23, real bug found running this the 2nd time: a `$(document).on('click', '#auditLogFilterBar
// .filter-bar-clear', ...)` delegated binding here NEVER fired -- initFilterBar()'s OWN handler on
// this exact button (app.js) calls `e.stopPropagation()`, which halts native bubbling before it
// ever reaches a listener bound on `document` (an ancestor). Bound directly on `#auditLogFilterBar`
// itself instead -- the SAME node initFilterBar()'s own `$bar.on(...)` uses -- so both are sibling
// listeners on that one node; `stopPropagation()` only blocks bubbling PAST a node, never other
// listeners already bound to it (`stopImmediatePropagation()` would, but that's not what's called
// here). `#auditLogFilterBar` is static PHP markup already in the DOM by the time this script runs,
// same as every other direct element-id binding in this file.
$('#auditLogFilterBar').on('click', '.filter-bar-clear', function () {
    if (tb_run_audit_log) clearAllTableFilters(tb_run_audit_log, '#auditLogFilterBar');
});
$(document).on('click', '.audit-log-view-detail-btn', function () {
    if (!tb_run_audit_log) return;
    const entry = tb_run_audit_log.row($(this).closest('tr')).data();
    if (entry) openAuditLogDetailRd(entry);
});

// 2026-08-31: fires the auto-recalculate-on-load check exactly once per page session (see
// loadRunDetail()'s own use of it) -- every mutation this page's own actions make already
// recalculate internally and then call loadRunDetail() again themselves, so without this guard a
// draft run with auto-recalculate on would silently re-trigger recalculate() -> loadRunDetail() ->
// recalculate() forever.
let autoRecalcOnLoadChecked = false;
// 2026-09-03, Platform UX review Phase 2 (revised): loadRunDetail() is reused for BOTH the page's
// true initial load AND every subsequent refresh after a mutating action (submit/approve/reject/
// recalculate/markPaid/...  -- see callRunAction()'s own docblock) -- the full-page loader must only
// ever show on the FIRST of those (explicit request: "ไม่ต้องโหลดทุกการโหลด"), never on a routine
// post-action refresh, so this flag gates it instead of wiring showPageLoader() into every one of
// the ~20 call sites individually.
let runDetailInitialLoadPending = true;
// Cleared at the true "real content is now on screen" point below (or on a failed initial load) --
// NOT in a naive ajax `complete` callback, which would fire (and hide the loader) even when the
// success handler is about to recurse into the auto-recalculate-on-load branch below and call
// loadRunDetail() a 2nd time before anything has actually rendered yet -- would have hidden the
// loader early, then left a real gap of network activity with nothing showing before the real
// render finally happened. Caught by tracing that recursive call before shipping, not by observing
// a flash live.
function loadRunDetail() {
    if (runDetailInitialLoadPending && typeof showPageLoader === 'function') showPageLoader();
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.get`,
        method: 'GET',
        data: { id: PAYROLL_RUN_ID },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                // 2026-08-31, explicit request: auto-recalculate checkbox -- when on, silently
                // recalculate BEFORE rendering the first time this run's data loads in this page
                // session, so a returning admin always sees numbers that reflect anything edited
                // elsewhere (Employee Detail's salary/PED tab, Setup & Rules, ...) since this run's
                // last calculation, without an extra manual click. See
                // PayrollRunModel::setAutoRecalculate()'s own docblock for why this page can never
                // detect an out-of-page edit directly and this is the closest practical substitute.
                if (!autoRecalcOnLoadChecked) {
                    autoRecalcOnLoadChecked = true;
                    if (res.data.state === 'draft' && Number(res.data.auto_recalculate) === 1) {
                        $.ajax({
                            url: `${BASE_URL}/api/payroll-run.recalculate`, method: 'POST',
                            contentType: 'application/json', dataType: 'json',
                            data: JSON.stringify({ id: PAYROLL_RUN_ID }),
                        }).always(function () { loadRunDetail(); });
                        return;
                    }
                }
                if (runDetailInitialLoadPending) {
                    runDetailInitialLoadPending = false;
                    if (typeof hidePageLoader === 'function') hidePageLoader();
                }
                renderRunHeader(res.data);
                initRunDetailTable(res.data.details || []);
                initAuditLogTableRd(res.data.audit_log || []);
                // 2026-08-28, explicit request: Process List/Approval Queue (opened in a SEPARATE
                // browser tab, see index.js/approval.js's own window.open(...'_blank')) should
                // reload once this run's data changes -- every mutating action on this page
                // (submit/approve/reject/recalculate/markPaid/cancel/join employees/line overrides/
                // etc) already funnels back through loadRunDetail() itself, so marking dirty here
                // covers all of them from one place instead of duplicating it at every action's own
                // success handler. See markTabDirty()/watchTabDirty() in app.js.
                if (typeof markTabDirty === 'function') markTabDirty('payroll_run_list_dirty');
            } else {
                if (runDetailInitialLoadPending) { runDetailInitialLoadPending = false; if (typeof hidePageLoader === 'function') hidePageLoader(); }
                showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
            }
        },
        error: function () {
            if (runDetailInitialLoadPending) { runDetailInitialLoadPending = false; if (typeof hidePageLoader === 'function') hidePageLoader(); }
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
// 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึง
// รอบ" -- same confirm/needs_revert_confirmation/needs_reopen_confirmation escalation dance as
// payroll/index.js's own .btn-merge-sync handler for the Origami-driven equivalent (deliberately
// mirrored, not shared -- this page has no access to that file's own module-scope helpers).
function requestMergeIntoTarget(allowRevert, allowReopen, onDone) {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.merge-into-existing`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({
            source_run_id: PAYROLL_RUN_ID, target_run_id: currentRun.merge_target_run_id,
            allow_revert_non_draft_target: !!allowRevert, allow_reopen_paid_target: !!allowReopen,
        }),
        success: function (res) { onDone(res); },
        error: function () { onDone({ status: false, message: langData['save_failed'] || 'An error occurred while saving.' }); }
    });
}
function handleMergeIntoTargetResult(res) {
    const target = currentRun.merge_target_run_name || `#${currentRun.merge_target_run_id}`;
    if (res.status) {
        let msg = (langData['merge_sync_success'] || 'Merged {count} line(s) into the target run.').replace('{count}', res.merged_line_count || 0);
        if ((res.skipped_employee_ids || []).length > 0) {
            msg += ' ' + (langData['merge_sync_skipped_note'] || '{count} employee(s) were skipped (not part of the target run).').replace('{count}', res.skipped_employee_ids.length);
        }
        showSuccess(msg);
        // This run was just soft-deleted by the merge -- nothing left here to reload; the target
        // run is where the merged amounts now live. Same "notify the other tab" mechanism the List
        // page's own dirty-reload already uses elsewhere on this page.
        if (typeof markTabDirty === 'function') markTabDirty('payroll_run_list_dirty');
        window.location.href = `${BASE_URL}/payroll-process/${currentRun.merge_target_run_id}`;
        return;
    }
    if (res.needs_revert_confirmation) {
        const title = langData['confirm_revert_merge_title'] || 'This Will Undo an Existing Decision';
        const message = (langData['confirm_revert_merge_message'] || 'The target run "{target}" is already {state}. Merging will REVERT that decision back to draft, requiring a fresh submit and approval. Continue?')
            .replace('{target}', target).replace('{state}', res.target_state || '');
        showConfirm(title, message, function () {
            requestMergeIntoTarget(true, false, handleMergeIntoTargetResult);
        });
        return;
    }
    if (res.needs_reopen_confirmation) {
        const title = langData['confirm_reopen_merge_title'] || 'This Will Reopen an Already-Paid Run';
        const message = (langData['confirm_reopen_merge_message'] || 'The target run "{target}" is already {state} -- money may have already moved. Merging will REOPEN it back to draft (clearing its paid/locked/approval status), requiring a fresh recalculate, submit, approve, and pay cycle. This is a higher-risk action -- continue?')
            .replace('{target}', target).replace('{state}', res.target_state || '');
        showConfirm(title, message, function () {
            requestMergeIntoTarget(false, true, handleMergeIntoTargetResult);
        });
        return;
    }
    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
}
$(document).on('click', '#btnMergeIntoTarget', function () {
    const target = currentRun.merge_target_run_name || `#${currentRun.merge_target_run_id}`;
    const title = langData['confirm_merge_sync_title'] || 'Merge into Target?';
    const message = (langData['confirm_merge_into_target_message'] || 'Merge this run into "{target}"? This will add its amounts to that run\'s own gross pay before withholding, and this run will be closed.').replace('{target}', target);
    showConfirm(title, message, function () {
        requestMergeIntoTarget(false, false, handleMergeIntoTargetResult);
    });
});
// 2026-08-31, explicit request: auto-recalculate checkbox saves instantly on toggle (same "no
// separate Save button for a single switch" convention this app uses elsewhere) -- reverts the
// checkbox visually on failure since currentRun.auto_recalculate would otherwise disagree with what
// the box shows.
// 2026-09-14, Round 3 "เก็บตกรอบ 6": #chkAutoRecalculate now lives inside a .setting-row
// (setting-row.php/settingRowHtml(), §9/§11) whose own description auto-swaps on the SAME native
// 'change' event via app.js's always-on delegated handler -- that handler and this one both fire off
// the same real click, so a SUCCESSFUL save needs no extra work here, the description is already
// showing the right text by the time this callback runs. On FAILURE, `.prop('checked', !value)`
// reverts the box WITHOUT firing 'change' (jQuery's .prop() never does) -- `syncSettingRowDesc()`
// (app.js, exported alongside settingRowHtml() for exactly this) re-syncs the description to match,
// WITHOUT using `.trigger('change')` -- that would re-invoke THIS SAME id-scoped handler again
// (jQuery fires every matching delegated handler on a real 'change', including this one), triggering
// a duplicate save attempt.
$(document).on('change', '#chkAutoRecalculate', function () {
    const $chk = $(this);
    const value = $chk.is(':checked');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.auto-recalculate.save`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, value: value }),
        success: function (res) {
            if (res.status) {
                if (currentRun) currentRun.auto_recalculate = value ? 1 : 0;
            } else {
                $chk.prop('checked', !value);
                syncSettingRowDesc($chk);
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $chk.prop('checked', !value);
            syncSettingRowDesc($chk);
            showWarning(langData['save_failed'] || 'An error occurred while saving.');
        }
    });
});
/* ---------- The line-override table -- ONE table (2026-09-16, batch 3/4, rules.md 7/8/9; it was the
   "ปรับตัวเลข" tab's own until D3 left it with a single host, the Calculation Breakdown modal).
   Every row is a line this employee's own last calculation actually produced (base salary +
   earning/deduction/statutory breakdowns, plus any line an 'exclude' override dropped out of them),
   so the list is never a catalog of things that do not apply to this person --
   PayrollRunModel::syncDeductionLinesForEmployee() is the single source, and it now also tags each
   row with `item_type`, which is what groups this table.

   Checkbox meaning is POSITIVE throughout: ticked = included in the calculation. The stored field is
   unchanged and still means the opposite (a `payroll_run_line_overrides` row with action='exclude'),
   so the inversion happens at the edge, where the switch's own change handler turns "off" into a
   stored `exclude` and "on" into removing one.
   ---------- */
const LINE_OVERRIDE_GROUPS_RD = [
    // 2026-09-17, R1: base salary is money coming IN, so it takes the slip's own income colour (§8's
    // `.money-gross`) rather than staying the only uncoloured figure in a column of coloured ones.
    { type: 'base_salary', key: 'table_base_salary', fallback: 'Base Salary', money: 'money-gross' },
    { type: 'earning', key: 'breakdown_earnings', fallback: 'Income', money: 'money-gross' },
    { type: 'deduction', key: 'table_deduction_amount', fallback: 'Deductions', money: 'money-deduction' },
    { type: 'statutory', key: 'line_override_group_statutory', fallback: 'Statutory', money: 'money-deduction' },
    // 2026-09-18, 4a-2: the lines somebody typed in, after everything the engine produced -- they are
    // the last thing added to this employee's pay, and reading them last is reading them in the order
    // they happened. `manualType` marks a group as one of the 2 (it is also the type a new line gets
    // when its own "add" row is pressed); no other group has it.
    { type: 'manual_earning', key: 'line_override_group_manual_earning', fallback: 'Additional earnings', money: 'money-gross', manualType: 'earning' },
    { type: 'manual_deduction', key: 'line_override_group_manual_deduction', fallback: 'Additional deductions', money: 'money-deduction', manualType: 'deduction' },
    // Last on purpose: a row whose item_code has no catalog row at all (retired item, CUSTOM: line).
    { type: 'other', key: 'line_override_group_other', fallback: 'Other', money: '' },
];
// The engine's own notes for "this line was deliberately skipped for THIS employee" -- the table
// hides these rows until asked, because the thing to change is the setting they come from (Employee
// Detail / Payroll Configuration), not a number here. Every other note stays visible on purpose:
// no_rate_configured/no_rate_ever_configured/unrecognized_calc_base mean something is NOT SET UP
// (hiding those buries a real problem), and manually_overridden/manually_excluded mean someone has
// already acted on this very row.
const LINE_OVERRIDE_SKIP_NOTES_RD = ['employee_not_enrolled', 'employee_tax_exempt', 'disabled'];
/* ---- TH_PIT / TH_SSO are a 3-state question, not a 2-state one (2026-09-18, 4b) --------------
   Neither row carries an exclusion any more: tiny-E closed that write, because an exclusion row only
   ever zeroed the EMPLOYEE half and left the employer contribution computing in full, so it never
   meant "do not tax / do not send SSO for this person". What does mean that is
   payroll_run_employee_exemptions' own tri-state, which reaches the engine's $employeeFlags -- so the
   switch on these 2 rows writes THAT endpoint, and the row's own restore writes its third value,
   'inherit', which no switch position can express. Every other statutory code is unchanged. */
const STATUTORY_EXEMPTION_FIELD_RD = { TH_PIT: 'tax', TH_SSO: 'sso' };
/* The server's last word on this employee, re-read with the table (loadSyncLineOverridesRd) --
   including the 2 read-only `*_inherit_effective` keys: what 'inherit' really resolves to for THIS
   employee on THIS run (the run default, else their own permanent flag). That answer is NOT derivable
   from the lines -- an override replaces the very note the flag would have produced -- which is why
   it is carried in the response rather than inferred here. */
let lineOverrideExemptionRd = null;
function statutoryExemptionFieldRd(line) {
    if (!line || (line.line_type || 'earning_deduction') !== 'statutory') return null;
    return STATUTORY_EXEMPTION_FIELD_RD[String(line.code || '').toUpperCase()] || null;
}
function statutoryExemptionStateRd(field) {
    if (!field || !lineOverrideExemptionRd) return 'inherit';
    return lineOverrideExemptionRd[field + '_calculate_override'] || 'inherit';
}
function statutoryExemptionInheritRd(field) {
    if (!field || !lineOverrideExemptionRd) return 'yes';
    return lineOverrideExemptionRd[field + '_inherit_effective'] === 'no' ? 'no' : 'yes';
}
// What is in force right now: the stored answer when there is one, otherwise what inherit gives.
function statutoryExemptionEffectiveRd(field) {
    const state = statutoryExemptionStateRd(field);
    return state === 'inherit' ? statutoryExemptionInheritRd(field) : state;
}
// "Somebody answered this row themselves" -- the same question lineOverrideIsChangedRd() asks of
// every other row, for the one kind of change that is not stored as an override at all.
function statutoryExemptionChangedRd(line) {
    const field = statutoryExemptionFieldRd(line);
    return !!field && statutoryExemptionStateRd(field) !== 'inherit';
}
// Both fields, always: the endpoint takes the pair and writes the row as a pair, so sending only the
// one that moved would silently reset the other to 'inherit'. `changes` is what this write really
// says -- {tax:...} / {sso:...} for one row, both for "restore everything" -- and whatever it leaves
// out is sent back at the value the server already holds.
function statutoryExemptionRequestRd(changes, done) {
    const payload = {
        id: PAYROLL_RUN_ID,
        employee_id: lineOverrideEmployeeIdRd(),
        tax_calculate_override: changes.tax || statutoryExemptionStateRd('tax'),
        sso_calculate_override: changes.sso || statutoryExemptionStateRd('sso'),
    };
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save-employee-exemption`,
        method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) { done(!!res.status, res.message); },
        error: function () { done(false, null); },
    });
}
// 'employee_not_enrolled' does not say WHICH enrolment, so the badge is resolved per item code --
// the 2 codes that can produce it are the 2 in StatutoryCalculationEngine's own ITEM_ENROLLMENT_FLAG.
function lineOverrideSkipEnumRd(line) {
    // The 2 tri-state rows never carry one: their participation is a control ON the row now, so a
    // badge saying why they were left out would be explaining a state the row itself can change.
    if (statutoryExemptionFieldRd(line)) return null;
    if (line.note === 'employee_not_enrolled') {
        if (line.code === 'TH_SSO') return 'employee_not_enrolled_sso';
        if (line.code === 'TH_PVD') return 'employee_not_enrolled_pvd';
        return null;
    }
    return LINE_OVERRIDE_SKIP_NOTES_RD.indexOf(line.note) >= 0 ? line.note : null;
}
// Hidden only when the user has not already acted on the row: a personal override wins over the
// enrolment setting (recalculate() applies it regardless of the note), so such a row must stay
// visible or its own override would be unreachable.
function lineOverrideIsSkippedRd(line, mode) {
    // The 2 tri-state rows are the exception, in both directions. In the editable slip the row is the
    // ONLY way to set that answer to "yes", so it is always rendered -- at 0, and when the engine says
    // this employee is not enrolled at all, which is precisely when it is needed. The read-only slip
    // has nothing to set, so it shows the row only when it says something: a figure, or an answer
    // somebody chose.
    if (statutoryExemptionFieldRd(line)) {
        if (mode !== 'view') return false;
        return Number(line.current_amount || 0) === 0 && !line.override_action && !statutoryExemptionChangedRd(line);
    }
    return !line.override_action && !!lineOverrideSkipEnumRd(line);
}
// 2026-09-18, 4a-2b: "somebody changed this row" -- an override of any kind (including an exclude),
// or a line that was not calculated at all but added by hand. The read-only slip's second tab shows
// exactly these, and its count is the same predicate, so there is one definition of both.
function lineOverrideIsChangedRd(line) {
    return !!line.override_action || line.line_type === 'manual_line' || statutoryExemptionChangedRd(line);
}
let lineOverrideRowsRd = [];
let lineOverrideRunSettingsRd = null;
// Which of the read-only slip's 2 tabs is open. Client state only, reset per open (see
// setLineOverrideHostRd) -- a filter that survived a reopen would hide rows nobody asked to hide.
let lineOverrideViewFilterRd = 'all';
/* 2026-09-16, D1: this table had TWO mount points -- the Adjustments modal's "ปรับตัวเลข" tab and the
   Calculation Breakdown modal's editable layout -- and exactly ONE implementation. `lineOverrideHostRd`
   is the whole of the difference between them: where to render, whose lines to fetch, and what else to
   refresh after a write. Every function below reads it instead of naming a container, so no host owns
   the table.
   2026-09-17, D3: the "ปรับตัวเลข" tab is gone and the Breakdown modal is the only host left. The
   indirection stays as the ONE place a host is described (a `.lo-mount` is still resolved through it,
   never named inline) rather than being inlined back into every function -- the alternative is putting
   `#breakdownLineOverrideWrap` in ~10 places again, which is what D1 removed. */
let lineOverrideHostRd = { mount: '#breakdownLineOverrideWrap', employeeId: null, onSaved: null, mode: 'edit' };
function lineOverrideMountRd() {
    return $(lineOverrideHostRd.mount);
}
function lineOverrideEmployeeIdRd() {
    return lineOverrideHostRd.employeeId;
}
function setLineOverrideHostRd(mount, employeeId, onSaved, mode) {
    if (lineOverrideHostRd.mount !== mount) {
        $(lineOverrideHostRd.mount).empty();
    }
    lineOverrideHostRd = { mount: mount, employeeId: employeeId, onSaved: onSaved || null, mode: mode || 'edit' };
    lineOverrideViewFilterRd = 'all';
    // Dropped with the host: it is one employee's answer, and a render triggered before the new
    // employee's fetch lands must not read the previous one's.
    lineOverrideExemptionRd = null;
}
/* Edit history for THIS employee, keyed 'line_type|item_code' exactly as the history table stores it
   -- fetched once alongside the table's own data (loadSyncLineOverridesRd) because the table has to
   know at RENDER time which rows even have a history badge to draw.
   2026-09-19, H-ui: each entry is the raw LIST of that key's rows, newest first, straight off
   api/payroll-run.line-history -- all 3 kinds of edit, not overrides alone. The grouped oldest-first
   shape the old endpoint returns only ever fitted the dropdown this replaced. */
let lineOverrideHistoryRd = { byKey: {}, historyAvailable: true, startDate: null };
// The raw hand-added lines, by their own PK: a pick out of one's history rewrites the WHOLE row
// through update-manual-line, and the table's own row shape does not carry every field that takes.
let manualLineRawByIdRd = {};
function lineOverrideHistoryKeyRd(lineType, itemCode) {
    return (lineType || 'earning_deduction') + '|' + itemCode;
}
/* A hand-added line is recorded under 'earning_deduction|{resolved code}' (recordManualLineHistory()
   resolves the same code the slip addresses it by) and is told apart from a calculated line that
   happens to share that code by source_type + source_id -- two hand-added lines on one employee can
   share one item_code, which is exactly why the PK is what identifies them. */
function lineOverrideHistoryFor(line) {
    const isManual = (line.line_type || 'earning_deduction') === 'manual_line';
    const rows = lineOverrideHistoryRd.byKey[lineOverrideHistoryKeyRd(isManual ? 'earning_deduction' : line.line_type, line.code)] || [];
    return rows.filter(function (row) {
        return isManual
            ? row.source_type === 'manual_line' && Number(row.source_id) === Number(line.manual_line_id)
            : row.source_type !== 'manual_line';
    });
}
// A value as the dropdown shows it: masked values arrive as a string ('XXXX') and must pass through
// untouched -- fmtNum() on them would print NaN.
function lineOverrideHistoryValueRd(value) {
    if (value === null || value === undefined) return '';
    return typeof value === 'number' ? fmtNum(value) : String(value);
}
/* ---------- one row's history, as a TABLE under the row (2026-09-19, H-ui) --------------------
   It replaces a 5-row dropdown behind the badge AND the nested modal that dropdown linked to: two
   surfaces for one list, neither of which could show the 2 kinds of edit H-backend started
   recording. See docs/decisions/2026-09-19-h-ui-history-table.md.
   Columns: when | who | from -> to | use this value. The last one is NOT RENDERED in the read-only
   slip (rules.md 9: the 2 modes differ by leaving a column out, never by disabling one). */
// An entry carries either a figure or a word -- `new_text` is set only by the tri-state writer.
function lineOverrideHistoryRowKindRd(row) {
    return (row.new_text !== null && row.new_text !== undefined && row.new_text !== '') ? 'text' : 'amount';
}
// A stored tri-state answer as the word the rest of the slip says it with. Anything unrecognised is
// printed as it came rather than silently blanked.
function lineOverrideHistoryTextRd(value) {
    if (!value) return '';
    return langData['calc_override_' + value] || String(value);
}
function lineOverrideHistorySideRd(row, which) {
    return lineOverrideHistoryRowKindRd(row) === 'text'
        ? lineOverrideHistoryTextRd(which === 'from' ? row.old_text : row.new_text)
        : lineOverrideHistoryValueRd(which === 'from' ? row.old_value : row.new_value);
}
/* The fixed "what the system said" rows, pinned above the list: the value the line STARTED at, which
   is not an edit and has no timestamp to be sorted by. A line can need one of these, both or none:
     amount -- the engine's own figure, only where this line really has an amount trail; a hand-added
               line never has one, because nothing calculated it (setting one would print a figure
               that never existed -- see lineOverrideComputedTextRd()'s own note)
     text   -- what 'inherit' resolves to for THIS employee on THIS run, for the 2 tri-state rows
   Absent, never blank: a line with no recorded calculated value prints no row at all. */
function lineOverrideHistoryComputedRowsRd(line, rows) {
    const out = [];
    if ((line.line_type || 'earning_deduction') !== 'manual_line'
        && rows.some(r => lineOverrideHistoryRowKindRd(r) === 'amount')) {
        const text = lineOverrideComputedTextRd(line);
        // Never `isNoop`: "use the calculated value" DROPS the override row, which is a real change
        // to what is stored even on a line whose override happens to hold the same figure.
        if (text !== '') out.push({ kind: 'amount', value: text, isCurrent: !line.override_action, isComputed: true });
    }
    const field = statutoryExemptionFieldRd(line);
    if (field && rows.some(r => lineOverrideHistoryRowKindRd(r) === 'text')) {
        out.push({
            kind: 'text',
            value: lineOverrideHistoryTextRd(statutoryExemptionInheritRd(field)),
            applyValue: 'inherit',
            isCurrent: statutoryExemptionStateRd(field) === 'inherit',
            isComputed: true,
        });
    }
    return out;
}
/* "This entry's value is the one the line already holds." Compared on the RAW value, never on the
   formatted string: money is a float and 4000 vs 4000.0000001 is the same figure to everyone but a
   string compare. A masked figure ('XXXX') parses to NaN and is never claimed to be equal -- a
   reader who may not see the amount must not be told what it is by a disabled button. */
function lineOverrideHistorySameValueRd(kind, rawValue, line, field) {
    if (kind === 'text') {
        return !!field && rawValue !== null && rawValue !== undefined && rawValue !== ''
            && rawValue === statutoryExemptionStateRd(field);
    }
    const current = Number(line.current_amount);
    const value = Number(rawValue);
    if (isNaN(current) || isNaN(value)) return false;
    return Math.abs(value - current) < 0.005;
}
/* One button, one look, on every row of every history table: a small NEUTRAL button. Not the view's
   solid orange and not outline-primary either (rules.md 4) -- an action that repeats once per row is
   not the surface's one main action, and a 38-entry chain would otherwise print it 38 times.
   The entry whose value is already in force carries the word instead: there is nothing to do there,
   and a disabled button would still read as "this is where you would do it" (rules.md 0.3). */
function lineOverrideHistoryUseCellHtml(cell) {
    if (cell.isCurrent) {
        return `<span class="lo-history-current">${escapeHtml(langData['line_override_history_current'] || 'Current')}</span>`;
    }
    const applyValue = cell.applyValue !== undefined ? cell.applyValue : cell.value;
    // 2026-09-19, reported for real (EM009 / LOAN_REPAY): an older entry whose figure happens to
    // equal the one in force was still pressable, and the write it sent was an x -> x no-op the
    // server accepted and recorded nothing for. The word "Current" belongs to the ONE entry that is
    // the live one; every other entry that merely repeats its figure stays an entry -- a button, so
    // the column keeps one shape -- but a button that cannot be pressed.
    return `<button type="button" class="btn btn-sm btn-outline-secondary lo-history-use"
        data-kind="${escapeAttr(cell.kind)}" data-value="${escapeAttr(applyValue)}" data-label="${escapeAttr(cell.value)}"`
        + (cell.isComputed ? ' data-computed="1"' : '') + (cell.isNoop ? ' disabled' : '') + '>'
        + escapeHtml(langData['line_override_history_use_value'] || 'Use this value') + '</button>';
}
/* 2026-09-19: 3 layers, and the first 2 stay put while the third scrolls.
     (a) the title bar -- what the SYSTEM said for this line, and the way out. It is not an edit and
         has no time of its own, so it was never a row of the list; as the list's first row it also
         scrolled away, which is exactly what a baseline must not do.
     (b) the column titles
     (c) the edits, and nothing else.
   A tri-state line can carry 2 baselines (a figure and an answer) and prints both; a hand-added line
   has none at all, and its left side is simply empty. */
function lineOverrideHistoryTitlebarHtmlRd(line, rows, isView) {
    const computed = lineOverrideHistoryComputedRowsRd(line, rows);
    const lines = computed.map(function (cell) {
        return `<div class="lo-history-computed-line">
            <span class="lo-history-computed-label">${escapeHtml(langData['line_override_history_computed'] || 'Calculated value')}</span>
            <span class="num">${escapeHtml(cell.value)}</span>
            ${isView ? '' : `<span class="lo-history-computed-action">${lineOverrideHistoryUseCellHtml(cell)}</span>`}
        </div>`;
    }).join('');
    /* 2026-09-22, slip2-a: the app's own modal ✕ (`.btn-close`), not a bordered circle. This panel is
       a box that opens and closes inside a dialog, so the way out of it should be the way out of
       every other box in the app -- reported as exactly that ("เอาวงกลมออก เหลือกากบาทแบบเดียวกับปุ่ม
       ปิด modal"). The 32px target survives: `.modal-header .btn-close` gets there with padding, and
       `.lo-history-close` does the same (see its rule in style.css). Dark mode comes free --
       Bootstrap 5.3 ships `[data-bs-theme=dark] .btn-close { filter: ... }`. */
    const closeLabel = escapeAttr(langData['close'] || 'Close');
    return `<div class="lo-history-titlebar">
        <div class="lo-history-computed">${lines}</div>
        <button type="button" class="btn-close lo-history-close" title="${closeLabel}" aria-label="${closeLabel}"></button>
    </div>`;
}
function lineOverrideHistoryTableHtmlRd(line, rows, mode) {
    const isView = mode === 'view';
    const field = statutoryExemptionFieldRd(line);
    const lineName = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || line.code;
    const historyScrollLabel = `${lineName} -- ${langData['line_override_col_history'] || 'History'}`;
    // "Which entry is the one in effect" is asked once per KIND: a tri-state row can carry an amount
    // trail and an answer trail at the same time, and each has its own current value. Resolved
    // newest-first and only ONCE per kind -- the same figure can be set, changed and set again, and
    // only the latest of those is the one in force. Every OTHER entry that repeats it is a no-op.
    const matched = {};
    const computed = lineOverrideHistoryComputedRowsRd(line, rows);
    computed.forEach(function (cell) { if (cell.isCurrent) matched[cell.kind] = true; });
    const useCell = function (cell) {
        return isView ? '' : `<td class="lo-history-use-cell">${lineOverrideHistoryUseCellHtml(cell)}</td>`;
    };
    const body = rows.map(function (row) {
        const kind = lineOverrideHistoryRowKindRd(row);
        const to = lineOverrideHistorySideRd(row, 'to');
        const from = lineOverrideHistorySideRd(row, 'from');
        const same = lineOverrideHistorySameValueRd(kind, kind === 'text' ? row.new_text : row.new_value, line, field);
        const isCurrent = same && !matched[kind];
        if (isCurrent) matched[kind] = true;
        const change = from === ''
            ? escapeHtml(to)
            : escapeHtml((langData['line_override_history_from_to'] || '{from} → {to}')
                .replace('{from}', from).replace('{to}', to));
        const who = (currentLang === 'th' ? row.changed_by_name_th : row.changed_by_name_en)
            || row.changed_by_name_th || row.changed_by_name_en || '';
        // The note is a second fact ABOUT the change, so it sits under it in the same cell rather
        // than spending a column of its own on something most entries do not carry.
        return `<tr>
            <td class="lo-history-when">${escapeHtml(formatDisplayDateTime(row.changed_at))}</td>
            <td class="lo-history-who">${escapeHtml(who)}</td>
            <td class="lo-history-change">${change}${row.note ? `<div class="lo-history-note">${escapeHtml(row.note)}</div>` : ''}</td>
            ${useCell({ kind: kind, value: to, isCurrent: isCurrent, isNoop: same && !isCurrent })}
        </tr>`;
    }).join('');
    return lineOverrideHistoryTitlebarHtmlRd(line, rows, isView)
        /* 2026-09-22, slip2-b: the same 3 attributes the raw-sync panel's box got. A box that caps
           its own height and scrolls is only reachable from a keyboard if it is focusable -- the
           rows inside it are tabbable, but the SCROLLING is not, so a reader who cannot use a mouse
           could not get past the 5th entry. The name is this row's own item plus the word this
           column has always been called, so a screen reader says which line's history it is. */
        + `<div class="lo-history-scroll" tabindex="0" role="group" aria-label="${escapeAttr(historyScrollLabel)}"><table class="table table-sm lo-history-table mb-0">
        <thead><tr>
            <th class="lo-history-when">${escapeHtml(langData['time'] || 'Time')}</th>
            <th class="lo-history-who">${escapeHtml(langData['line_override_history_col_who'] || 'Changed by')}</th>
            <th class="lo-history-change">${escapeHtml(langData['line_override_history_col_change'] || 'Change')}</th>
            ${isView ? '' : `<th class="lo-history-use-cell"><span class="visually-hidden">${escapeHtml(langData['line_override_history_use_value'] || 'Use this value')}</span></th>`}
        </tr></thead>
        <tbody>${body}</tbody>
    </table></div>`;
}
// 2026-09-17, R1: the figure the system calculated -- the same number the history dropdown has
// always shown as its head row, out where it can be compared with the live one without opening
// anything. It comes from 2 places because that is where it really lives:
//   - no override on this row  -> the live figure IS the calculated one (nothing has replaced it)
//   - an override              -> `original_value` of this line's history (the value the FIRST
//                                 recorded edit replaced), which is exactly what the dropdown's own
//                                 head row reads
// 2026-09-18, tiny-C: a THIRD source now comes first -- `computed_amount`, the engine's own figure
// persisted by recalculate() at the moment the override replaced it. That is the real answer to the
// stand-in problem tiny-L6b (B3) documented below, so it is asked first and the history is only
// consulted when it has nothing (a run last calculated before this was persisted).
// 2026-09-18, tiny-L6b (B3): `original_value` is a STAND-IN for the engine's figure, not the figure
// itself -- the breakdown JSON kept only the amount after an override, so a row whose override
// predates its history has an older OVERRIDE sitting in `original_value` and this would print it as
// if the system had calculated it ("ค่าระบบ x หลอก"). Kept as the fallback for exactly those older
// runs; a row with neither still says nothing at all -- no dash, no title, no hint in the form --
// rather than a number nothing backs up (an excluded line is still one of those: BACKLOG).
// 2026-09-17, R1 follow-up: a group that names a side (base salary/income = green, deduction/
// statutory = red) decides for its rows. The "other" group cannot: a row lands there precisely
// because its item_code has no catalog row left to say which side it is (a retired item), so its own
// `item_type` is the only thing left to ask -- and when that is 'other' too there is genuinely no
// side recorded anywhere, and the figure stays uncoloured rather than guessing one.
function lineOverrideMoneyClassRd(line, group) {
    if (group.money) return group.money;
    if (line.item_type === 'earning') return 'money-gross';
    if (line.item_type === 'deduction') return 'money-deduction';
    return '';
}
function lineOverrideComputedTextRd(line) {
    if (!line.override_action) return lineOverrideHistoryValueRd(line.current_amount);
    if (line.computed_amount !== null && line.computed_amount !== undefined) return lineOverrideHistoryValueRd(line.computed_amount);
    // Both halves of "is there a recorded history for this line": the run's period has to be inside
    // the window the feature has existed for at all, AND this particular line has to have a row in
    // it (byKey only ever holds lines that do). Either one missing means no trustworthy figure.
    // 2026-09-19, H-ui: read off the rows themselves now -- `old_value` of the OLDEST amount entry,
    // which is exactly the number the grouped endpoint used to hand over as `original_value`. The
    // list arrives newest first, so the oldest of it is the last.
    const rows = lineOverrideHistoryRd.historyAvailable ? lineOverrideHistoryFor(line) : [];
    const amounts = rows.filter(r => lineOverrideHistoryRowKindRd(r) === 'amount');
    // The OLDEST amount entry, whatever it holds -- never "the oldest one that happens to carry a
    // figure". Skipping a null would hand back a LATER override's `old_value` as if the engine had
    // calculated it, which is the whole bug this fallback is fenced against.
    const original = amounts.length ? amounts[amounts.length - 1].old_value : null;
    return (original === null || original === undefined) ? '' : lineOverrideHistoryValueRd(original);
}
// 2026-09-18, 4a-1: the 3 quiet sub-lines a row can carry, in ONE shape -- `.payslip-line-tag`
// (--fs-xs/--c-text-muted, and --c-text-faint on an excluded row). Order is fixed and is the same in
// both slips: instalment + destination, then why the figure is what it is, then the note somebody
// typed when they changed it.
// Shown in FULL, wrapping inside the item column -- not clipped to one line with the rest in a
// `title`. A tooltip is not readable on a phone at all, and these lines are the answer to "why is
// this figure what it is", which is not an optional extra.
function lineOverrideTagHtmlRd(text) {
    if (!text) return '';
    return `<div class="payslip-line-tag">${escapeHtml(text)}</div>`;
}
// The calculation steps that used to open in a "?" popover (removed 4a-1: a hover-only affordance is
// not one, rules.md 7 -- and one popover per row in a table that already scrolls is noise). Flattened
// from the SAME 2 builders rather than re-formatted: a second formatter for the same steps is how the
// two would start disagreeing. Falls back to the line's own note, which is what the popover did too.
function formulaTagTextRd(line) {
    const amount = line.current_amount !== undefined ? line.current_amount : line.amount;
    const html = buildFormulaStepsRd(line.formula) || explainLineNoteRd(line.note, amount);
    if (!html) return '';
    return html.replace(/<\/(li|div)>/g, ' · ').replace(/<[^>]*>/g, ' ')
        .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
        .replace(/\s+/g, ' ').replace(/(\s*·\s*)+$/, '').trim();
}
// SyncPayResolver still emits a LINE (amount forced to 0) for an attendance deduction this employee
// is exempt from, rather than dropping it silently -- so there is something here to explain instead
// of the item just quietly not appearing (2026-08-30, explicit request). The figure it WOULD have
// been is masked like any other (maskAdjustLines()), so it goes through the same value formatter.
function lineOverrideExemptTextRd(line) {
    if (!line.is_exempted) return '';
    return (langData['attendance_deduction_exempted_remark'] || 'Exempted from this deduction -- would have been {amount}')
        .replace('{amount}', lineOverrideHistoryValueRd(line.exempted_amount));
}
// What somebody typed when they changed this figure -- the override's OWN note, never the engine's
// internal `note` code (that one explains the calculation and rides in the formula tag above).
function lineOverrideNoteTextRd(line) {
    if (!line.override_note) return '';
    return `${langData['note'] || 'Note'}: ${line.override_note}`;
}
// 2026-09-18, tiny-L6b (B3): no longer a column of its own. A fixed 120px that is blank on every row
// except the handful carrying an override -- in a table that already scrolls sideways on a phone --
// spends width on nothing (rules.md §0.3). As a sub-line it appears only where it has something to
// say, directly under the figure it is there to be compared with, in the same quiet `.payslip-line-tag`
// the row's payee/installment line already uses (§11, tiny-L5).
function lineOverrideComputedTagHtml(line) {
    if (!line.override_action) return '';
    const text = lineOverrideComputedTextRd(line);
    if (text === '') return '';
    const tpl = langData['line_override_computed_inline'] || 'System: {amount}';
    return `<div class="payslip-line-tag">${escapeHtml(tpl.replace('{amount}', text))}</div>`;
}
// 2026-09-18, 4b: the same tag, for the answer the 2 tri-state rows carry instead of a figure. It
// prints a WORD ("ระบบ: คำนวณ"), not an amount: what was replaced on this row is a yes/no, and the
// figure that answer produced is already in the cell above it. Same template, same position, so a row
// that carries both an amount override and an answer prints both, in the order they were made.
function statutoryExemptionTagHtmlRd(line) {
    const field = statutoryExemptionFieldRd(line);
    if (!field || statutoryExemptionStateRd(field) === 'inherit') return '';
    const inherits = statutoryExemptionInheritRd(field);
    const label = langData['calc_override_' + inherits] || (inherits === 'yes' ? 'Calculate' : "Don't Calculate");
    const tpl = langData['line_override_computed_inline'] || 'System: {amount}';
    return `<div class="payslip-line-tag">${escapeHtml(tpl.replace('{amount}', label))}</div>`;
}
/* 2026-09-19, H-ui: a plain disclosure toggle -- no caret and no menu behind it, the whole history
   opens as a table under the row (see the click handler further down). It sits inside a `<button>`
   because a disclosure has to be focusable and pressable, which a `<span>` is not.
   Drawn on EVERY row that has something recorded -- an excluded one and a hand-added one included,
   because both really do carry edits (excluding a line IS a recorded edit, and a hand-added line has
   had an amount trail since H-backend). Nothing recorded = no badge: there is nothing to go back to.
   2026-09-22, slip2-a: the count is QUIET TEXT, not `countBadgeHtml()`'s pill. A soft-filled,
   coloured pill on every edited row answers neither of §0.1's two questions -- how many times a
   figure was changed is not "what to do next" and not "what to decide" -- and the tab above this
   very table already says its own count as a grey number for exactly that reason
   (`lineOverrideTabsHtmlRd()`). Still the same `<button aria-expanded>`, same handler, same word:
   only the paint is gone. `countBadgeHtml()` itself is untouched and keeps its other callers. */
function lineOverrideHistoryToggleHtml(line) {
    const rows = lineOverrideHistoryFor(line);
    if (!rows.length) return '';
    const label = (langData['line_override_history_badge'] || '{n} edit(s)').replace('{n}', String(rows.length));
    return `<button type="button" class="lo-history-toggle" aria-expanded="false"><span class="lo-history-count">${escapeHtml(label)}</span></button>`;
}
// 2026-09-19, H-ui: the read-only slip's own mark for a line somebody touched -- the quiet
// `.payslip-line-tag` every other sub-line already wears (rules.md 9), last of the fixed order, and
// a different WORD for the 2 different facts: a line that was added by hand was never calculated at
// all, which is not the same as a calculated figure somebody replaced.
function lineOverrideChangeTagTextRd(line) {
    if ((line.line_type || 'earning_deduction') === 'manual_line') {
        return langData['line_override_row_tag_added'] || 'Added by hand';
    }
    return lineOverrideIsChangedRd(line) ? (langData['line_override_row_tag_edited'] || 'Edited') : '';
}
function lineOverrideOccurrencesHtml(occurrences) {
    if (!occurrences || !occurrences.length) return '';
    const rows = occurrences.map(o => `<div class="d-flex justify-content-between gap-3 lo-occurrence">
        <span>${langData['sync_line_occurrence_installment'] || 'Installment'} ${o.installment_no != null ? escapeHtml(o.installment_no) : '-'}</span>
        <span class="num">${fmtNum(o.amount)}${o.applied_at ? ` (${formatDisplayDate(o.applied_at)})` : ''}</span>
    </div>`).join('');
    return `<div class="lo-occurrences">${rows}</div>`;
}
// 2026-09-16, round 6: this table edits ONE line at a time and sends it immediately -- so it has no
// "New value" column and no Save button of its own (rules.md §9: a surface that writes on every
// action has no save step to show). What used to be a
// staged form is now: a switch that asks before it fires, and a pencil that opens the amount for
// editing in place. See docs/decisions/2026-09-16-line-override-table.md for why the staged version
// was abandoned -- it kept growing rules ("empty means…", "unticking parks…") that only existed to
// describe a batch that was never sent as a batch anyway.
function lineOverrideRowHtml(line, idx, group, runDisabled, mode) {
    const isView = mode === 'view';
    const name = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || line.code;
    // Set on TH_PIT/TH_SSO only: which half of payroll_run_employee_exemptions this row's switch
    // writes. Every reader of the row (the change handler, restore, the footer count) asks the row
    // for it rather than re-testing the code.
    const exemptionField = statutoryExemptionFieldRd(line);
    const origAction = line.override_action || '';
    const included = runDisabled ? false : origAction !== 'exclude';
    // 2026-09-18, 4a-2 follow-up: a skipped row never reaches here -- renderLineOverrideTableRd()
    // drops it. A line that contributes nothing to any of the 3 totals is not a line of this slip, and
    // a badge explaining its absence still spent a row on an item that is not part of this pay.
    const statutoryBadge = line.line_type === 'statutory' ? ' ' + statusBadgeHtml('statutory', 'payroll_line_type') : '';
    // A hand-added line: a row of this table since 4a-2, mapped into this shape by
    // manualLineToTableRowRd(). It has no switch (nothing calculated it, so there is nothing to put
    // back), no history, and its own 2 actions instead of the override pencil.
    const isManual = line.line_type === 'manual_line';
    // 2026-09-18, 4a-1: the 2 classifications the read-only slip carried and this table did not. They
    // are not "a code" (those live in the name's own title): money credited FROM another employee, and
    // the retired "Other Income/Deduction" bucket reports still group by.
    let sourceBadge = '';
    if (line.source === 'transfer_in') {
        sourceBadge = ' ' + statusBadgeHtml('transfer', 'manual_line_mode', { outline: true });
    } else if (line.is_other) {
        sourceBadge = ' ' + statusBadgeHtml('other', 'manual_line_mode', { outline: true });
    }
    const runDisabledAttr = runDisabled ? ' disabled' : '';
    const title = runDisabled ? ' title="' + escapeAttr(langData['line_override_run_disabled'] || 'Turned off in Run Settings') + '"' : '';
    const editable = included && !runDisabled;
    // 2026-09-17, R1: the pencil shows on every editable row, not on hover -- an affordance nobody
    // can see until they hover is not one (§7, the same conclusion the comment list reached).
    // 2026-09-22, slip2-a: the GHOST variant (`.btn-icon .btn-icon-ghost`, §7). A control that
    // repeats on every row of a list is not a chip floating on the surface -- the ring and the fill
    // the base variant draws at rest were 2 more boxes per row in a table that already has 3 columns
    // and a sub-line under most figures. The hit area stays 32px: what got lighter is the style, not
    // the target (§7's own "ลดน้ำหนักด้วยสไตล์ ไม่ใช่ลดขนาดพื้นที่กด").
    const pencil = (editable && !isManual)
        ? `<button type="button" class="btn btn-icon btn-icon-ghost lo-edit-btn" title="${escapeAttr(langData['line_override_edit_amount'] || 'Edit amount')}" aria-label="${escapeAttr(langData['line_override_edit_amount'] || 'Edit amount')}"><i class="fa-solid fa-pen"></i></button>`
        : '';
    /* Slot 2, one per row and always the same width (§7: "ช่องปุ่มต้องกว้างคงที่เสมอ แม้แถวนั้นจะไม่มีปุ่ม").
       What goes in it is decided by what the row IS:
         a hand-added line -> the bin. Nothing calculated it, so there is no system value to go back
                              to; removing it is the only thing left to do to it.
         a calculated line somebody changed -> "back to what the system calculated". Same action the
                              history panel's top row performs and the footer's [คืนค่าระบบทั้งหมด]
                              performs in bulk -- a second way IN to one write, not a second write
                              (rules.md §0.4).
         anything else     -> an empty slot that still takes its width, so the pencil of every row
                              starts on one x and the figures beside them line up.
       No row can want both: `lineOverrideLineIsRestorableRd()` is false for a hand-added line by
       construction (it has no override and no tri-state answer). */
    const manualEdit = (isManual && !isView && line.manual_line_id)
        ? `<button type="button" class="btn btn-icon btn-icon-ghost manual-line-edit-btn" data-line-id="${escapeAttr(line.manual_line_id)}" title="${escapeAttr(langData['manual_line_form_edit_title'] || 'Edit item')}" aria-label="${escapeAttr(langData['manual_line_form_edit_title'] || 'Edit item')}"><i class="fa-solid fa-pen"></i></button>`
        : '';
    const removeLabel = escapeAttr(langData['action_remove'] || 'Remove');
    const restoreLabel = escapeAttr(langData['line_override_group_restore'] || 'Restore calculated values');
    let slotTwo = '';
    if (isView) {
        slotTwo = '';
    } else if (isManual && line.manual_line_id) {
        slotTwo = `<button type="button" class="btn btn-icon btn-icon-ghost manual-line-remove-btn" data-line-id="${escapeAttr(line.manual_line_id)}" title="${removeLabel}" aria-label="${removeLabel}"><i class="fa-solid fa-trash-can"></i></button>`;
    } else if (editable && lineOverrideLineIsRestorableRd(line)) {
        slotTwo = `<button type="button" class="btn btn-icon btn-icon-ghost lo-row-restore-btn" title="${restoreLabel}" aria-label="${restoreLabel}"><i class="fa-solid fa-rotate-left"></i></button>`;
    } else {
        slotTwo = '<span class="lo-slot-empty" aria-hidden="true"></span>';
    }
    // The disclosure for this row's own edit history -- drawn only where there is something recorded
    // (see its own builder). It is the one control the read-only slip keeps, so it is built for both.
    const historyToggle = lineOverrideHistoryToggleHtml(line);
    // An excluded row has no amount to show: 0.00 struck through still reads as a figure that counts
    // for something. What is true about it is that it is not in the calculation, so it says that.
    const amountCell = included
        ? `<span class="num ${lineOverrideMoneyClassRd(line, group)}">${fmtNum(line.current_amount)}</span>`
        : `<span class="lo-amount-excluded">${escapeHtml(langData['line_override_excluded_amount'] || 'Not calculated')}</span>`;
    // A manual row keeps the cell and leaves it empty: there is no calculated value behind it to put
    // back, so a switch there would offer an action that means nothing -- and dropping the cell
    // would take the row out of the grid every other row is in.
    // On a tri-state row the switch does not mean "include this line", it means "does this person get
    // taxed / sent to SSO on this run" -- so it shows what is IN FORCE (the stored answer, or what
    // inherit gives when there is none), never the line's own include state.
    const switchOn = exemptionField ? statutoryExemptionEffectiveRd(exemptionField) === 'yes' : included;
    const checkCell = isView ? '' : `<td class="col-check tbl-sticky-col">${isManual ? ''
        : `<div class="form-check form-switch mb-0"><input class="form-check-input lo-include" type="checkbox" role="switch" id="loInc${idx}" ${switchOn ? 'checked' : ''}${runDisabledAttr}></div>`}</td>`;
    /* 2026-09-22, slip2-a: the row's controls live in the ITEM cell now, not in 2 columns of their
       own. What forced it was the figure: with an action column and a history column after it, a
       row's amount ended 176px (measured, 1400) to the left of the same run's total under the table,
       so the 2 figures a reader compares never shared an x. The money column is the LAST one now and
       takes the table's own `--payslip-inset` as its right padding, which is the very inset
       `.lo-totals` uses -- one declaration, not a number repeated in 2 places.
       The block always ENDS on the cell's own right edge and the 2 button slots are its last items,
       so the pencil of every row still starts on one x -- the alignment the old column bought,
       without spending a column on it. (Its total width is not constant: the count in front of the
       buttons is text, and it grows leftwards where it moves nothing.) */
    // Slot 1 is the pencil in both cases -- the override form on a calculated line, the hand-added
    // line's own form on a manual one -- so a pencil means the same thing on every row (§7).
    const slotOne = isManual ? manualEdit : pencil;
    /* The count comes FIRST and the 2 button slots after it, and that order is load-bearing: the
       block is right-aligned in the cell, so whatever sits on its right keeps a fixed x and whatever
       sits on its left grows leftwards. The count is TEXT of a width nobody controls ("แก้ไข 3" vs
       "12 edits"), so with it on the right the pencil moved by ~39px between a row that had a
       history and one that did not (measured). With it on the left, the 2 slots are always the last
       64px + gap of the block and every row's pencil starts on one x -- which is the alignment this
       round is about; the count reads as a quiet label in front of the controls it belongs to. */
    const actionsBlock = isView && !historyToggle ? '' : `<span class="lo-row-actions">${historyToggle}${isView ? '' : slotOne + slotTwo}</span>`;
    return `<tr class="lo-row${included ? '' : ' lo-row-off'}${isManual ? ' lo-row-manual' : ''}" data-item-code="${escapeAttr(line.code)}" data-line-type="${escapeAttr(line.line_type || 'earning_deduction')}"
        data-group-type="${escapeAttr(group.type || '')}" data-manual-line-id="${escapeAttr(line.manual_line_id || '')}"
        data-exemption-field="${escapeAttr(exemptionField || '')}" data-exemption-changed="${statutoryExemptionChangedRd(line) ? '1' : ''}"
        data-orig-action="${escapeAttr(origAction)}" data-item-name="${escapeAttr(name)}" data-amount="${escapeAttr(fmtNum(line.current_amount))}"${title}>
        ${checkCell}<td class="lo-name-cell tbl-sticky-col tbl-sticky-col-edge-left">
            <span class="lo-name-line"><span class="lo-name" title="${escapeAttr(name)} (${escapeAttr(line.code)})">${escapeHtml(name)}</span>${sourceBadge}${statutoryBadge}${actionsBlock}</span>
            ${payeeDescriptorHtmlRd(line.payee, { variant: 'tag', installment: line.installment })}
            ${lineOverrideTagHtmlRd(lineOverrideExemptTextRd(line))}
            ${lineOverrideTagHtmlRd(formulaTagTextRd(line))}
            ${lineOverrideTagHtmlRd(lineOverrideNoteTextRd(line))}
            ${isView ? lineOverrideTagHtmlRd(lineOverrideChangeTagTextRd(line)) : ''}
            ${lineOverrideOccurrencesHtml(line.occurrences)}
        </td>
        <td class="num col-money lo-amount-cell"><div class="lo-amount-view">${amountCell}</div>${lineOverrideComputedTagHtml(line)}${statutoryExemptionTagHtmlRd(line)}</td>
    </tr>`;
}
// The way to add the next line, on the head of the group it would be added to -- the heading names
// the group and the link beside it acts on that same group, so the pair reads as one statement
// instead of a row of its own that repeated what the heading above it already said.
// A worded text link, not a solid button: it repeats once per manual group, and this view's solid
// orange belongs to its one main action (rules.md §4's "action ที่ซ้ำหลายตัว" row, §0.2). It carries
// the same word the form's own submit button carries (`add_line`, §0.5) and no icon: the word is the
// label. Rendered in the editable slip only, where `employeeRowEditableRd()` is already true.
function lineOverrideAddLinkHtmlRd(group) {
    const titleKey = group.manualType === 'deduction' ? 'manual_line_add_deduction' : 'manual_line_add_earning';
    const title = langData[titleKey] || (group.manualType === 'deduction' ? 'Add a deduction item' : 'Add an income item');
    const label = langData['add_line'] || 'Add Line';
    return `<button type="button" class="btn btn-link lo-add-line-btn" data-item-type="${escapeAttr(group.manualType)}" title="${escapeAttr(title)}">${escapeHtml(label)}</button>`;
}
/* 2026-09-19, explicit request: the system groups get the link their head was missing. Same slot,
   same shape and the same CSS as the manual groups' "เพิ่มรายการ" (rules.md §9 -- one link per group
   head, never both: a manual group has nothing calculated to put back, a system group has nothing to
   add). Rendered ONLY when the group really holds something to restore -- a link that is there but
   does nothing is not information (§0.3), which is why this is absence, not `disabled`.
   `group-name` rides along so the confirm can name the group in the words the head shows. */
function lineOverrideGroupRestoreLinkHtmlRd(group, groupName) {
    const label = langData['line_override_group_restore'] || 'Restore calculated values';
    return `<button type="button" class="btn btn-link lo-group-restore-btn" data-group-type="${escapeAttr(group.type)}" data-group-name="${escapeAttr(groupName)}" title="${escapeAttr(label)}">${escapeHtml(label)}</button>`;
}
// "Is there anything in this group to put back" -- the SAME predicate the footer's own button counts
// with, asked of one group's lines instead of every row on screen.
function lineOverrideLineIsRestorableRd(line) {
    return !!line.override_action || statutoryExemptionChangedRd(line);
}
// One group, the same write path [คืนค่าระบบทั้งหมด] takes: one .remove per overridden row in
// order, plus ONE exemption write when a tri-state answer in this group is not 'inherit' (both
// TH_PIT and TH_SSO live in the statutory group, and that endpoint writes the pair).
function lineOverrideGroupRestoreRd($btn) {
    const groupType = String($btn.data('group-type') || '');
    const groupName = String($btn.data('group-name') || '');
    const rows = [];
    let resetExemption = false;
    lineOverrideMountRd().find('.lo-row[data-group-type="' + groupType + '"]').each(function () {
        const $row = $(this);
        if ($row.find('.lo-include').is(':disabled')) return;
        if ($row.attr('data-exemption-changed')) resetExemption = true;
        if ($row.data('orig-action') || '') rows.push($row);
    });
    if (!rows.length && !resetExemption) return;
    const tpl = langData['confirm_group_restore_message'] || 'Every changed item in "{group}" goes back to its calculated value. Continue?';
    showConfirm({
        title: langData['confirm_group_restore_title'] || 'Restore this group',
        message: tpl.replace('{group}', groupName),
        tone: 'warning',
        onYes: function () { runRestoreAllComputedRd(rows, resetExemption); },
    });
}
$(document).on('click', '.lo-mount .lo-group-restore-btn', function () {
    lineOverrideGroupRestoreRd($(this));
});
/* 2026-09-22, slip2-a: the same action, on the row it acts on. [คืนค่าระบบทั้งหมด] in the footer and
   [ใช้ค่านี้] on the history panel's top row both already do this; what was missing was the way in
   from the row itself, which is where a reader who has just compared "ระบบ: x" with the figure above
   it is looking (reported). A second way IN to one write, never a second write -- it goes through
   runRestoreAllComputedRd() with a list of ONE, which is what makes the tri-state case right for
   free: such a row can carry an amount override AND an answer, and that function is the only place
   that already knows to send both, in order.
   Rendered only where `lineOverrideLineIsRestorableRd()` is true (see the row builder), so it never
   appears on a row with nothing to put back -- absence, not `disabled` (§0.3). */
function lineOverrideRowRestoreRd($btn) {
    const $row = $btn.closest('tr.lo-row');
    if (!$row.length || $row.find('.lo-include').is(':disabled')) return;
    const line = lineOverrideLineByRowRd($row);
    if (!line || !lineOverrideLineIsRestorableRd(line)) return;
    const resetExemption = !!$row.attr('data-exemption-changed');
    const rows = ($row.data('orig-action') || '') ? [$row] : [];
    if (!rows.length && !resetExemption) return;
    const tpl = langData['confirm_row_restore_message'] || '"{item}" goes back to its calculated value. Continue?';
    showConfirm({
        title: langData['confirm_row_restore_title'] || 'Restore the calculated value',
        message: tpl.replace('{item}', String($row.data('item-name') || '')),
        tone: 'warning',
        onYes: function () { runRestoreAllComputedRd(rows, resetExemption); },
    });
}
$(document).on('click', '.lo-mount .lo-row-restore-btn', function () {
    lineOverrideRowRestoreRd($(this));
});
// 2026-09-17, R1 follow-up: the pinned 2nd column starts where the 1st one really ENDS. Its
// declared width is 78px, but what a sticky `left` has to match is the rendered border-box -- borders
// and sub-pixel rounding are not in the declaration, and being off by a fraction is exactly what
// made the pair drift while dragging. Published as a CSS variable so the offset stays in CSS (the
// media query decides WHETHER to pin; this only says WHERE).
// Not dtWatchVisibleWidth(): that one publishes a DataTables scroller's visible width for the empty
// state, a different value on a different element -- same ResizeObserver shape, nothing to share.
/* 2026-09-19, H-ui: the width an opened history panel may take. Below `sm` this table is a
   horizontal SCROLLER wider than its host, so whatever a full-width cell holds slides out of view
   when it is dragged -- measured for real on the 3 totals (see their own builder). The panel pins at
   left: 0 and takes the VISIBLE width, so it always shows all of itself.
   Published from 2 places because the width really does change at 2 moments: when the table is
   (re)drawn or the scroller resizes, and when a panel OPENS -- a tall one gives the dialog a
   vertical scrollbar, which takes the scroller's own width down with it. */
function lineOverridePublishPanelWidthRd(table, scroller) {
    if (!table || !scroller) return;
    table.style.setProperty('--lo-history-panel-w', scroller.clientWidth + 'px');
    // ...and where it starts: the x the ITEM NAME column's own text begins on, which is a different
    // column in each mode (the editable slip has a toggle column in front of it, the read-only one
    // does not). Measured off the head, so a width change moves the panel with it.
    const nameTh = table.querySelector('thead th.lo-name-col');
    const firstTh = table.querySelector('thead > tr > th');
    const indent = (nameTh && firstTh)
        ? Math.max(0, nameTh.getBoundingClientRect().left - firstTh.getBoundingClientRect().left
            + (parseFloat(getComputedStyle(nameTh).paddingLeft) || 0))
        : 0;
    table.style.setProperty('--lo-history-indent', Math.round(indent) + 'px');
}
/* How tall an opened panel may be: its title bar, its column titles and 5 entries -- measured off
   the rows that are really there rather than assumed from a row height, because an entry carrying a
   note is taller than one that does not. Past that the LIST scrolls (the title bar and the column
   titles sit outside it), so a 38-entry chain cannot push the slip's own table to 10 screens.
   The scrollbar that appears when it does cap takes width off the table inside, which is why its
   width is published too: the title bar sits outside that box and has to step in by the same amount
   for its [x] to stay on the same x as the buttons below it. */
function lineOverridePublishHistoryHeightRd($panel) {
    const panel = $panel.get(0);
    if (!panel) return;
    const box = panel.querySelector('.lo-history-scroll');
    const head = panel.querySelector('thead');
    const rows = panel.querySelectorAll('tbody > tr');
    if (!box) return;
    if (!head || rows.length <= 5) {
        box.style.removeProperty('--lo-history-max-h');
    } else {
        const top = head.getBoundingClientRect().top;
        const bottom = rows[4].getBoundingClientRect().bottom;
        box.style.setProperty('--lo-history-max-h', Math.round(bottom - top) + 'px');
    }
    panel.style.setProperty('--lo-history-sbw', Math.max(0, box.offsetWidth - box.clientWidth) + 'px');
}
function lineOverridePublishStickyOffsetRd($wrap) {
    const table = $wrap.find('table.lo-table').get(0);
    const scroller = $wrap.get(0);
    if (!table) return;
    const publish = function () {
        // 2026-09-18, 4a-1, real gap found by measurement at 430px: the read-only slip renders no
        // toggle column at all, so there is no first cell to measure and the offset was never
        // published -- leaving `left: var(--lo-sticky-left-2)` invalid and the name column not
        // pinned at all on a phone. With no column in front of it, it starts at 0.
        const firstCell = table.querySelector('thead th.col-check');
        table.style.setProperty('--lo-sticky-left-2', (firstCell ? firstCell.getBoundingClientRect().width : 0) + 'px');
        lineOverridePublishPanelWidthRd(table, scroller);
    };
    publish();
    if (typeof ResizeObserver === 'function' && scroller) {
        new ResizeObserver(publish).observe(scroller);
    }
}
/* 2026-09-18, 4a-2b: the read-only slip's own 2 tabs, above the table (rules.md §9). They filter the
   table that is already on screen -- no request, no second payload, no `d-none` row left in the DOM.
   The count is a grey number in brackets, never a coloured badge (§6): it says how much there is,
   not that something needs doing. With nothing changed there is nothing to switch between, so the
   row is not rendered at all rather than rendered and disabled. */
function lineOverrideTabsHtmlRd(changedCount) {
    if (!changedCount) return '';
    const tabHtml = function (filter, key, fallback, suffix) {
        const active = lineOverrideViewFilterRd === filter ? ' active' : '';
        return `<li class="nav-item" role="presentation"><button class="nav-link${active}" type="button" role="tab" data-lo-filter="${filter}">${escapeHtml(langData[key] || fallback)}${suffix}</button></li>`;
    };
    return `<ul class="nav nav-tabs lo-tabs" role="tablist">`
        + tabHtml('all', 'line_override_tab_all', 'Details', '')
        + tabHtml('changed', 'line_override_tab_changed', 'Changed items', ` <span class="text-muted">(${changedCount})</span>`)
        + '</ul>';
}
function renderLineOverrideTableRd(lines, runSettings, mode) {
    mode = mode || 'edit';
    const isView = mode === 'view';
    lineOverrideRowsRd = lines || [];
    // 2026-09-18, 4a-2: held so a totals-only refresh (the run's own row changed, the lines did not)
    // can redraw this table from what it already has, instead of re-fetching the identical list.
    lineOverrideRunSettingsRd = runSettings || null;
    const $wrap = lineOverrideMountRd();
    // The lock taken when a write started is released HERE, not when the request came back: it has to
    // hold across the reload too, or the user can act on rows that are about to be replaced. Only the
    // wrapper's own class is cleared -- every control below is brand-new markup that already carries
    // the right disabled state (re-enabling them by hand would switch a Run-Settings row back on).
    $wrap.removeClass('lo-table-busy');
    if (!lineOverrideRowsRd.length) {
        $wrap.html(`<div class="text-center text-muted py-3">${escapeHtml(langData['sync_line_override_empty'] || 'No calculated amounts for this employee yet -- recalculate the run first.')}</div>`);
        return;
    }
    const runExcluded = new Set((runSettings && runSettings.excluded_item_codes) || []);
    // Counted over the rows that really render (a skipped one is not a row of this slip at all), so
    // the number on the tab and the rows behind it can never disagree.
    const changedCount = isView
        ? lineOverrideRowsRd.filter(l => !lineOverrideIsSkippedRd(l, mode) && lineOverrideIsChangedRd(l)).length
        : 0;
    const changedOnly = changedCount > 0 && lineOverrideViewFilterRd === 'changed';
    // 2026-09-22, slip2-a: 3 columns in the editable slip, 2 in the read-only one. The action and
    // history columns are gone -- both controls sit in the item cell now (lineOverrideRowHtml()).
    const colCount = isView ? 2 : 3;
    let idx = 0;
    let body = '';
    LINE_OVERRIDE_GROUPS_RD.forEach(function (group) {
        // 2026-09-18, 4a-2 follow-up: a row this employee is not enrolled in / is exempt from / that is
        // switched off contributes nothing to any of the 3 totals, so it is not rendered at all, in
        // either mode. (It was hidden behind a toggle, then shown with a reason badge; both spent the
        // slip's scarcest resource -- a row -- on an item that is not part of this pay.) A personal
        // override still wins: lineOverrideIsSkippedRd() lets such a row through.
        const groupLines = lineOverrideRowsRd.filter(l => (l.item_type || 'other') === group.type
            && !lineOverrideIsSkippedRd(l, mode)
            && (!changedOnly || lineOverrideIsChangedRd(l)));
        // 2026-09-18, 4a-2: an EMPTY manual group still renders in the editable slip -- its head and
        // its "add a line" row are where adding the first one starts, and a group that appears only
        // once something is already in it can never be the way in. The read-only slip has nothing to
        // offer there, so an empty one is simply absent, like any other empty group.
        const manualOpen = !!group.manualType && !isView;
        if (!groupLines.length && !manualOpen) return;
        // 2026-09-19: the system groups' own head link. Editable slip only, and only when this group
        // really holds something to put back.
        const groupName = langData[group.key] || group.fallback;
        const groupRestorable = !group.manualType && !isView && groupLines.some(lineOverrideLineIsRestorableRd);
        // Still ONE row with one full-width cell -- the flex sits INSIDE the cell, never on the `<td>`
        // itself (rules.md §7: display:flex on a cell kills its table-cell behaviour and its colspan).
        body += `<tr class="lo-group"><td colspan="${colCount}"><div class="lo-group-head"><span class="lo-span-sticky">${escapeHtml(groupName)}</span>${manualOpen ? lineOverrideAddLinkHtmlRd(group) : ''}${groupRestorable ? lineOverrideGroupRestoreLinkHtmlRd(group, groupName) : ''}</div></td></tr>`;
        /* 2026-09-22, slip2-a: an empty manual group says so, in a row of its own. Its head and its
           "เพิ่มรายการ" link are the way in (4a-2), but a heading with nothing under it reads as a
           divider rather than as a group that is waiting to be filled -- reported as exactly that.
           `emptyStateHtml({inline: true})` is the shared component for a slot INSIDE a block (§6,
           §11): one muted line, no icon, no title, no button of its own -- everything the full
           variant adds would be wrong at the size of a table row, and the button it would add is
           already on the head above. Editable slip only: the read-only one never renders an empty
           group at all, so there is nothing there to explain. */
        if (!groupLines.length && manualOpen) {
            /* No `.lo-span-sticky` here, unlike the heading above it: that span is `nowrap` so it can
               stay put while a wide table is dragged, and this table no longer scrolls sideways at
               any width (min-width 348 in a 394 host). A sentence under nowrap would simply clip.
               2026-09-22, slip2-b follow-up: the line sits in a REAL item cell -- an empty toggle
               cell in front of it and `.lo-name-cell` carrying the rest -- rather than in one cell
               spanning everything. It stands in for the rows that are not there yet, so it starts on
               the x their names start on; building it out of the same cells is what makes that exact
               at every width, instead of a padding that re-states the toggle column's width and the
               cell padding as numbers of its own (reported: the line was centred). */
            const emptyCheck = isView ? '' : '<td class="col-check tbl-sticky-col"></td>';
            body += `<tr class="lo-group-empty">${emptyCheck}<td class="lo-name-cell tbl-sticky-col tbl-sticky-col-edge-left" colspan="${isView ? colCount : colCount - 1}">`
                + emptyStateHtml({ inline: true, text: langData['line_override_group_empty'] || 'No items yet -- press "Add Line" to start.' })
                + '</td></tr>';
        }
        groupLines.forEach(function (line) {
            // Disabled only where the run-level exclusion is genuinely in charge: a personal override
            // of any kind already wins over it (PayrollRunModel::recalculate()'s own resolution), so
            // such a row stays editable here.
            const runDisabled = !line.override_action && runExcluded.has(line.code);
            body += lineOverrideRowHtml(line, idx++, group, runDisabled, mode);
        });
    });
    // The 3 totals are read off `breakdownRowRd` (the server's own row), never summed from the rows
    // above them -- so the filtered table still ends on this employee's FULL pay, which is the only
    // figure that is true. They are appended AFTER the scroller closes (see their own builder).
    $wrap.html(lineOverrideTabsHtmlRd(changedCount) + `<div class="table-responsive"><table class="table align-middle lo-table mb-0">
        <thead>
            <tr>
                ${isView ? '' : `<th class="col-check tbl-sticky-col">${escapeHtml(langData['line_override_col_include'] || 'Include')}</th>`}<th class="lo-name-col tbl-sticky-col tbl-sticky-col-edge-left">${escapeHtml(langData['line_override_col_item'] || 'Item')}</th>
                <th class="num col-money lo-amount-col">${escapeHtml(langData['line_override_col_amount'] || 'Amount')}</th>
            </tr>
        </thead>
        <tbody>${body}</tbody>
    </table></div>` + lineOverrideTotalsHtmlRd(breakdownRowRd));
    // 2026-09-17, R1 follow-up: the shared scroller wiring (sticky-table-columns.js) -- it is what
    // keeps `.tbl-scrolled-x` in sync with scrollLeft, which is what the frozen columns' own right
    // edge shadow is keyed off (§7). Re-run per render: this markup, wrapper included, is rebuilt
    // every time, so the previous binding went with it.
    if (typeof initTableDragScroll === 'function') initTableDragScroll(lineOverrideHostRd.mount + ' .lo-table');
    lineOverridePublishStickyOffsetRd($wrap.find('.table-responsive').addBack('.table-responsive').first());
}
// Switching tabs draws the SAME rows again through the SAME renderer, from what the table already
// holds -- the identical call refreshBreakdownNetSummaryRd() makes. Nothing is fetched: both tabs
// are views of one payload that is already here.
$(document).on('click', '.lo-mount .lo-tabs .nav-link', function () {
    const filter = $(this).data('lo-filter');
    if (!filter || filter === lineOverrideViewFilterRd) return;
    lineOverrideViewFilterRd = filter;
    renderLineOverrideTableRd(lineOverrideRowsRd, lineOverrideRunSettingsRd, lineOverrideHostRd.mode);
});
/* The slip's own 3 summary figures. 4a-1 parked them in a block under the table, 4a-2 made them the
   table's own last rows, and 2026-09-19 (tiny-4b-fix1 v2) put them back in a block -- deliberately,
   and for a reason neither of those rounds had measured: below `sm` the table is a horizontal
   SCROLLER, and a row of it is 530px wide inside a 394px host. Whatever cells such a row is built
   from, its figures live in that scrolled content and slide out of view with it; the colspan version
   also pinned to nothing at all, so its labels ran off the left edge on every drag (measured: the
   full 120px of a 120px drag). A total is not a line of the slip -- it is the slip's bottom line --
   so it belongs OUTSIDE the scroller, where it always shows the whole of itself.
   One shape in both modes: nothing here depends on how many columns the table has, which is the
   whole point. The label sits on `--payslip-inset`, the same x the group headings start on; the
   figure ends on it, the same x the "add a line" links end on. */
function lineOverrideTotalsHtmlRd(row) {
    if (!row) return '';
    const totals = [
        { label: langData['payslip_total_earnings'] || 'Total Income', amount: row.gross_amount, cls: 'money-gross', rowCls: '' },
        { label: langData['payslip_total_deductions'] || 'Total Deductions', amount: row.total_deduction_amount, cls: 'money-deduction', rowCls: '' },
        { label: langData['table_net_pay'] || 'Net Pay', amount: row.net_amount, cls: 'money-net', rowCls: ' lo-totals-row-net' },
    ];
    return `<div class="lo-totals">` + totals.map(t => `<div class="lo-totals-row${t.rowCls}">
            <span class="lo-totals-label">${escapeHtml(t.label)}</span>
            <span class="num ${t.cls}">${fmtNum(t.amount)}</span>
        </div>`).join('') + `</div>`;
}
/* The badge under the History column is a disclosure, not a menu: it opens this row's whole history
   as a table directly under the row, and closes it again. Built on demand out of what the slip
   already holds -- every entry is client-side already (byKey), so there is nothing to fetch. */
$(document).on('click', '.lo-mount .lo-history-toggle', function () {
    const $btn = $(this);
    const $row = $btn.closest('tr.lo-row');
    const $open = $row.next('tr.lo-history-row');
    if ($open.length) {
        $open.remove();
        $btn.attr('aria-expanded', 'false');
        return;
    }
    const line = lineOverrideLineByRowRd($row);
    if (!line) return;
    // A row that is switched off is read-only here whatever the slip's own mode: the way back in is
    // that row's own switch, and a [use this value] beside it would be a second control for the one
    // thing (rules.md 0.4).
    const mode = (lineOverrideHostRd.mode === 'view' || $row.hasClass('lo-row-off')) ? 'view' : 'edit';
    $row.after(`<tr class="lo-history-row"><td colspan="${$row.children('td').length}"><div class="lo-history-panel">`
        + lineOverrideHistoryTableHtmlRd(line, lineOverrideHistoryFor(line), mode) + '</div></td></tr>');
    $btn.attr('aria-expanded', 'true');
    // After the browser has laid the new rows out, never before it: a tall panel is what makes the
    // dialog scroll vertically, and that scrollbar is what changes the width being published. TWO
    // frames, not one -- the first is where the rows land, the second is where the scrollbar that
    // appeared because of them has already taken its width out of the scroller.
    const $scroller = $row.closest('.table-responsive');
    const $table = $row.closest('table.lo-table');
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            lineOverridePublishPanelWidthRd($table.get(0), $scroller.get(0));
            lineOverridePublishHistoryHeightRd($row.next('tr.lo-history-row').find('.lo-history-panel'));
        });
    });
});
// The header's own [Close]. Same thing the badge does, from the other end of a list that may be
// scrolled well past it -- and the focus goes back to the badge, which is where the reader was.
$(document).on('click', '.lo-mount .lo-history-close', function () {
    const $row = $(this).closest('tr.lo-history-row').prev('tr.lo-row');
    $row.next('tr.lo-history-row').remove();
    $row.find('.lo-history-toggle').attr('aria-expanded', 'false').trigger('focus');
});
// The one way back to any value this line has ever held, including the one the system calculated.
$(document).on('click', '.lo-mount .lo-history-use', function () {
    const $btn = $(this);
    const $row = $btn.closest('tr.lo-history-row').prev('tr.lo-row');
    const line = lineOverrideLineByRowRd($row);
    if (!line) return;
    lineOverrideConfirmApplyHistoryValueRd({
        $row: $row,
        line: line,
        kind: $btn.attr('data-kind') || 'amount',
        value: $btn.attr('data-value') || '',
        label: $btn.attr('data-label') || $btn.attr('data-value') || '',
        isComputed: $btn.attr('data-computed') === '1',
    });
});
// Picking a value out of a history list is one click away from replacing a figure someone else set,
// and the list sits right under the pointer while scrolling -- so it asks first. The row is written
// the moment it is confirmed (this tab has no Save button to press afterwards).
function lineOverrideConfirmApplyHistoryValueRd(plan) {
    const $row = plan.$row;
    if (!$row || !$row.length || $row.hasClass('lo-row-off')) return;
    const tpl = langData['line_override_confirm_use_value_message']
        || '{item} will be set to {value} and saved immediately.';
    showConfirm({
        title: langData['line_override_confirm_use_value_title'] || 'Use this value instead of the current one',
        message: tpl.replace('{value}', plan.label).replace('{item}', String($row.data('item-name') || '')),
        tone: 'info',
        confirmText: langData['line_override_history_use_value'] || 'Use this value',
        cancelText: langData['cancel'] || 'Cancel',
        onYes: function () { lineOverrideApplyHistoryValueRd(plan); },
    });
}
/* 2026-09-19, H-ui: one pick, 4 possible writes -- which one is decided by what the entry IS, not by
   where it was clicked, because there is only one place left to click it:
     the answer word (tri-state) -> save-employee-exemption, that field only (the other half is sent
                                   back at the value the server already holds -- the endpoint writes
                                   the pair, so leaving it out would reset it)
     a hand-added line          -> update-manual-line, amount only
     the calculated figure      -> line-override.remove / statutory-line-override.remove; it means
                                   "drop what somebody put here", never "save this number as one"
     any other figure           -> line-override.save with that figure */
function lineOverrideApplyHistoryValueRd(plan) {
    const line = plan.line;
    if (plan.kind === 'text') {
        statutoryExemptionSendRd(plan.$row, statutoryExemptionFieldRd(line), plan.value);
        return;
    }
    const amount = typeof parseMoneyInput === 'function' ? parseMoneyInput(plan.value) : parseFloat(plan.value);
    if ((line.line_type || 'earning_deduction') === 'manual_line') {
        manualLineAmountOnlyUpdateRd(line, amount);
        return;
    }
    if (plan.isComputed) {
        lineOverrideSendRd(plan.$row, { action: 'remove' });
        return;
    }
    if (isNaN(amount)) return;
    lineOverrideSendRd(plan.$row, { action: 'override_amount', amount: amount });
}
/* A pick out of a hand-added line's history changes exactly ONE thing: the amount. Everything else
   is sent back at the value the row already holds, read off the raw manual line the loader kept --
   update-manual-line takes the whole row, so a field left out is a field cleared, not one kept. */
function manualLineAmountOnlyUpdateRd(line, amount) {
    const raw = manualLineRawByIdRd[String(line.manual_line_id)];
    if (!raw || isNaN(amount)) return;
    const payload = {
        id: PAYROLL_RUN_ID,
        employee_id: lineOverrideEmployeeIdRd(),
        line_id: raw.id,
        amount: amount,
        note: raw.note || '',
    };
    if (raw.is_custom) {
        payload.custom_item_name = raw.item_name_th || raw.item_name_en || '';
        payload.custom_item_type = raw.item_type;
    } else {
        payload.ped_type_id = raw.ped_type_id;
    }
    if (raw.payee_type && raw.payee_type !== 'none') {
        payload.payee_type = raw.payee_type;
        if (raw.payee_type === 'employee' && raw.payee_employee_id) payload.payee_employee_id = raw.payee_employee_id;
        if (raw.payee_type === 'company' && raw.bank_account_id) payload.bank_account_id = raw.bank_account_id;
        if (raw.payee_type === 'other_person' && raw.destination_id) payload.destination = { destination_id: raw.destination_id };
    }
    setLineOverrideTableBusyRd(true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.update-manual-line`,
        method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) {
            if (!res.status) { lineOverrideSendFailedRd(null, null, res.message); return; }
            showSuccess(langData['line_override_saved'] || 'Saved.');
            lineOverrideAfterWriteRd();
        },
        error: function () { lineOverrideSendFailedRd(null, null, null); },
    });
}
// `api/payroll-run.sync-lines-for-employee` answers 2 unrelated questions in one response (see
// PayrollController::syncLinesForEmployee()'s own docblock): this employee's calculated lines, and
// this employee's per-run Tax/SSO override. 2026-09-17, D3: those 2 readers now sit in different
// modals -- the table in the Calculation Breakdown modal, the radios in the per-employee settings
// modal -- and never open at the same time, so each says which half it came for. The request itself
// is written once, here, rather than in each of them.
function fetchSyncLinesForEmployeeRd(employeeId, onLoaded) {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.sync-lines-for-employee`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: employeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            onLoaded(res);
        }
    });
}
/* 2026-09-18, 4a-2: one hand-added line, in the shape every other row of this table is in, so ONE
   row builder renders both (rules.md §0.4 -- the card this replaced was a second layout for the same
   kind of thing). Nothing is computed here: the fields are renamed, not changed.
   `note` becomes `override_note` because that is the key the row's "หมายเหตุ: …" tag reads, and it
   lands under the payee tag, which is the order the card showed them in.
   `override_action` is left unset ON PURPOSE: it is what marks a figure as "somebody replaced what
   the system calculated", and a hand-added line replaced nothing -- setting it would print a
   "ระบบ: x" tag under a figure that never had a calculated value. */
function manualLineToTableRowRd(line) {
    return {
        code: line.item_code,
        name_th: line.item_name_th,
        name_en: line.item_name_en,
        current_amount: line.amount,
        computed_amount: null,
        line_type: 'manual_line',
        item_type: line.item_type === 'deduction' ? 'manual_deduction' : 'manual_earning',
        override_action: null,
        override_amount: null,
        override_note: line.note,
        note: null,
        source: null,
        formula: null,
        is_exempted: false,
        exempted_amount: null,
        is_custom: line.is_custom,
        is_other: line.is_other,
        payee: line.payee,
        installment: line.installment,
        occurrences: null,
        // The 2 ids the row's own buttons need: which line to address, and which catalog item to put
        // back into the edit form's picker.
        manual_line_id: line.id,
        ped_type_id: line.ped_type_id,
    };
}
// Never rejects: one failed side-request must not stop the table from drawing what it does have.
function lineOverrideSideRequestRd(endpoint, employeeId) {
    return $.ajax({
        url: `${BASE_URL}/${endpoint}`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: employeeId },
        dataType: 'json',
    }).then(function (res) { return res; }, function () { return null; });
}
function loadSyncLineOverridesRd() {
    const employeeId = lineOverrideEmployeeIdRd();
    fetchSyncLinesForEmployeeRd(employeeId, function (res) {
        // Two more requests per modal open, fired in parallel and rendered TOGETHER: the table cannot
        // draw its History column without knowing which rows have edits, and since 4a-2 the
        // hand-added lines are rows of it too. Rendering as each one lands would redraw the whole
        // table 2 extra times, which is visible as a flicker on every open.
        // 2026-09-19, H-ui: `line-history` with no line named, not `line-override-history` -- the
        // badge has to count what the table can SHOW, which since H-backend is overrides plus the
        // hand-added lines plus the tri-state answer. The old endpoint answers a narrower question
        // (overrides only, grouped oldest-first) and the Payroll Run Audit report is built on that
        // shape, so it is left exactly as it is rather than widened.
        $.when(
            lineOverrideSideRequestRd('api/payroll-run.line-history', employeeId),
            lineOverrideSideRequestRd('api/payroll-run.manual-lines', employeeId)
        ).done(function (historyRes, manualRes) {
            const payload = (historyRes && historyRes.status && historyRes.data) ? historyRes.data : null;
            lineOverrideHistoryRd = { byKey: {}, historyAvailable: payload ? !!payload.history_available : true, startDate: payload ? payload.history_start_date : null };
            (payload && payload.rows ? payload.rows : []).forEach(function (row) {
                const key = lineOverrideHistoryKeyRd(row.line_type, row.item_code);
                (lineOverrideHistoryRd.byKey[key] || (lineOverrideHistoryRd.byKey[key] = [])).push(row);
            });
            const manual = (manualRes && manualRes.status && manualRes.data) ? manualRes.data : [];
            // Same response, second half (PayrollController::syncLinesForEmployee) -- the TH_PIT/TH_SSO
            // rows are rendered from it, so it is set BEFORE the table draws, never after.
            lineOverrideExemptionRd = res.exemption || null;
            // Held raw as well: a pick out of a hand-added line's history rewrites that line through
            // update-manual-line, which takes the WHOLE row -- so the fields the table's own shape
            // drops (custom name, destination, bank account) have to still be reachable.
            manualLineRawByIdRd = {};
            manual.forEach(function (line) { manualLineRawByIdRd[String(line.id)] = line; });
            renderLineOverrideTableRd((res.data || []).concat(manual.map(manualLineToTableRowRd)), res.run_settings, lineOverrideHostRd.mode);
            refreshBreakdownFooterStateRd();
        });
    });
}
// Flipping the switch changes what this employee gets paid, in both directions -- so both directions
// ask, and neither writes anything until the answer is yes. A cancelled confirm puts the switch back
// where it was rather than leaving the control disagreeing with the data behind it.
$(document).on('change', '.lo-mount .lo-include', function () {
    const $row = $(this).closest('tr.lo-row');
    const included = this.checked;
    const name = String($row.data('item-name') || $row.data('item-code'));
    const snapBack = function () { $row.find('.lo-include').prop('checked', !included); };
    // 2026-09-18, 4b: the same control, a different question and a different endpoint on the 2
    // tri-state rows -- and its own confirm, because "leave this item out of the run" is not what is
    // being asked there (line_override_confirm_exclude_* would state something untrue).
    const exemptionField = String($row.data('exemption-field') || '');
    if (exemptionField) {
        const stateLabel = langData['calc_override_' + (included ? 'yes' : 'no')] || (included ? 'Calculate' : 'Do not calculate');
        showConfirm({
            title: langData['statutory_toggle_confirm_title'] || 'Change how this item is calculated',
            message: (langData['statutory_toggle_confirm_message'] || 'Set {item} to "{state}" for this employee in this run?')
                .replace('{item}', name).replace('{state}', stateLabel),
            tone: included ? 'info' : 'warning',
            onYes: function () { statutoryExemptionSendRd($row, exemptionField, included ? 'yes' : 'no'); },
            onNo: snapBack,
        });
        return;
    }
    const tpl = included
        ? (langData['line_override_confirm_include_message'] || 'Include {item} in this run again?')
        : (langData['line_override_confirm_exclude_message'] || 'Leave {item} out of this run?');
    showConfirm({
        title: included
            ? (langData['line_override_confirm_include_title'] || 'Include this item')
            : (langData['line_override_confirm_exclude_title'] || 'Exclude this item'),
        message: tpl.replace('{item}', name),
        tone: included ? 'info' : 'warning',
        onYes: function () {
            // Turning it back ON is an undo of the stored exclusion, not a new value.
            lineOverrideSendRd($row, included ? { action: 'remove' } : { action: 'exclude' });
        },
        onNo: snapBack,
    });
});
// Sequential (not parallel) on purpose -- each lineOverrideSave()/lineOverrideRemove() call
// recalculates the whole run internally; firing several at once risks two overlapping
// recalculate() writes racing each other.
function runSequentialAjaxRd(calls, onDone) {
    if (!calls.length) { onDone(); return; }
    const call = calls.shift();
    call(function (ok) {
        if (!ok) { onDone(); return; }
        runSequentialAjaxRd(calls, onDone);
    });
}
/* One row, one request. A statutory row goes to its own endpoint, which wraps the item_code itself
   -- never wrapped here. Takes the line TYPE rather than the row, because 2026-09-18's tiny-L6a form
   sends for a line it holds as data, with no row of its own to read. */
function lineOverrideEndpointRd(lineType, action) {
    const verb = action === 'remove' ? 'remove' : 'save';
    return (lineType || 'earning_deduction') === 'statutory'
        ? `${BASE_URL}/api/payroll-run.statutory-line-override.${verb}`
        : `${BASE_URL}/api/payroll-run.line-override.${verb}`;
}
// The write itself, with no opinion about what happens next: `done(ok, message)`. Both callers (the
// row's own switch/restore-all, and the line form) need the same request and disagree only about
// where a refusal is shown -- a toast for the first, a callout inside the form for the second.
function lineOverrideRequestRd(lineType, payload, action, done) {
    $.ajax({
        url: lineOverrideEndpointRd(lineType, action),
        method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) { done(!!res.status, res.message); },
        error: function () { done(false, null); },
    });
}
// Whole-TABLE lock for the duration of one write: every save recalculates the run internally, so a
// second action started before the first comes back would race it. The rest of the modal stays
// usable -- only this tab writes on every action.
function setLineOverrideTableBusyRd(busy) {
    const $wrap = lineOverrideMountRd();
    $wrap.toggleClass('lo-table-busy', busy);
    $wrap.find('.lo-include, .lo-edit-btn, .lo-row-restore-btn, .lo-history-toggle, .lo-group-restore-btn, .lo-add-line-btn').each(function () {
        const $el = $(this);
        if (busy) {
            if ($el.is(':disabled')) $el.attr('data-was-disabled', '1');
            $el.prop('disabled', true);
        } else if ($el.attr('data-was-disabled') === '1') {
            $el.removeAttr('data-was-disabled');
        } else {
            $el.prop('disabled', false);
        }
    });
    $('#btnRestoreAllComputedLineOverrides').prop('disabled', busy);
}
// The write path for the row's OWN controls: the include switch and both "use this value" entry
// points. `$busyBtn` is the control that should carry the spinner -- everything else just locks.
// (The form has its own path: it may send 2 requests in order, and shows a refusal in its callout
// rather than a toast -- but through the same lineOverrideRequestRd() below.)
// `plan.note` rides along when the caller has one: the column has always been there and the
// endpoint has always accepted it (2026-09-18, tiny-L6a -- the form is the first sender).
function lineOverrideSendRd($row, plan, $busyBtn) {
    if (!$row || !$row.length || !plan) return;
    setLineOverrideTableBusyRd(true);
    if ($busyBtn && $busyBtn.length) setButtonLoading($busyBtn, true);
    lineOverrideRequestRd($row.data('line-type'),
        lineOverridePayloadRd($row.data('item-code'), plan), plan.action, function (ok, message) {
            if (!ok) { lineOverrideSendFailedRd($row, $busyBtn, message); return; }
            showSuccess(langData['line_override_saved'] || 'Saved.');
            // A full reload, not a local patch: one override changes what the statutory lines
            // calculate to, so every row's amount (and the run's own totals) can move. The host's
            // own hook is what refreshes anything OUTSIDE this table that moved with it.
            lineOverrideAfterWriteRd();
        });
}
// The tri-state write, with the SAME lock/refresh contract as lineOverrideSendRd() above -- the
// endpoint recalculates the run internally exactly as the override endpoints do, so a second action
// started before it returns would race it.
function statutoryExemptionSendRd($row, field, value, $busyBtn) {
    const changes = {};
    changes[field] = value;
    setLineOverrideTableBusyRd(true);
    if ($busyBtn && $busyBtn.length) setButtonLoading($busyBtn, true);
    statutoryExemptionRequestRd(changes, function (ok, message) {
        if (!ok) { lineOverrideSendFailedRd($row, $busyBtn, message); return; }
        showSuccess(langData['line_override_saved'] || 'Saved.');
        lineOverrideAfterWriteRd();
    });
}
// One body for both senders, so an added field cannot reach only one of them.
function lineOverridePayloadRd(itemCode, plan) {
    const payload = { id: PAYROLL_RUN_ID, employee_id: lineOverrideEmployeeIdRd(), item_code: itemCode };
    if (plan.action === 'override_amount') { payload.action = 'override_amount'; payload.override_amount = plan.amount; }
    if (plan.action === 'exclude') { payload.action = 'exclude'; }
    if (plan.note !== undefined) { payload.note = plan.note; }
    return payload;
}
// A failed write leaves the table exactly as the user left it -- including whatever they typed --
// so they can fix the value and try again rather than start over.
function lineOverrideSendFailedRd($row, $busyBtn, message) {
    setLineOverrideTableBusyRd(false);
    if ($busyBtn && $busyBtn.length) setButtonLoading($busyBtn, false);
    showError(message || langData['save_failed'] || 'Could not save.');
}

/* ---- the pencil: one row -> the one line form (2026-09-18, tiny-L6a) -------------------------
   The cell used to become the editor (an input in place of the figure plus 2 round buttons). That
   surface could only ever hold the amount, so everything else about a line -- its note, and where
   its money goes -- had no way in from the row it belongs to. It is replaced by the SAME modal form
   a hand-added line is edited in (rules.md §9: one form, one markup, opened from more than one
   place), which is also the only surface that can carry those other 2 fields. */
function lineOverrideLineByRowRd($row) {
    const code = String($row.data('item-code'));
    const lineType = String($row.data('line-type') || 'earning_deduction');
    // Both halves of the key: a statutory row and an earning/deduction row may carry the same bare
    // code, and they route to different endpoints.
    return lineOverrideRowsRd.find(function (l) {
        return String(l.code) === code && (l.line_type || 'earning_deduction') === lineType;
    }) || null;
}
// Everything the table (and what sits around it) has to re-read once a write lands -- the same set
// lineOverrideSendRd() refreshes, so the form and the row's own actions agree on what "saved" means.
function lineOverrideAfterWriteRd() {
    loadSyncLineOverridesRd();
    loadRunDetail();
    if (lineOverrideHostRd.onSaved) lineOverrideHostRd.onSaved();
}
function openLineOverrideFormRd($row) {
    if (!$row.length || $row.hasClass('lo-row-off')) return;
    const line = lineOverrideLineByRowRd($row);
    if (!line) return;
    openManualLineFormRd({
        kind: 'override',
        overrideLine: line,
        employeeId: lineOverrideEmployeeIdRd(),
        mount: lineOverrideHostRd.mount,
        onSaved: lineOverrideAfterWriteRd,
    });
}
$(document).on('click', '.lo-mount .lo-edit-btn', function () {
    openLineOverrideFormRd($(this).closest('tr.lo-row'));
});

function lineOverrideProgressRd(i, n) {
    const tpl = langData['line_override_saving_progress'] || 'Saving {i}/{n}…';
    $('#lineOverrideSaveProgress').text(tpl.replace('{i}', String(i)).replace('{n}', String(n)));
}
// Sequential (not parallel) on purpose -- each call recalculates the whole run internally; firing
// several at once risks two overlapping recalculate() writes racing each other.
function runSequentialAjaxRd(calls, onDone) {
    if (!calls.length) { onDone(); return; }
    const call = calls.shift();
    call(function (ok) {
        if (!ok) { onDone(); return; }
        runSequentialAjaxRd(calls, onDone);
    });
}
// The footer's only action beside [ปิด]: drop EVERY override this employee carries, in one go. The
// per-row controls each handle one line; this is the one thing that touches rows the user never
// opened, which is why it is the one thing that still counts before it asks.
// 2026-09-17, D3: it moved with the table it acts on, from #manageLinesModal's footer to
// #runDetailBreakdownModal's -- same §9 left slot, same id, same handler.
function restoreAllComputedLineOverridesRd() {
    const rows = [];
    lineOverrideMountRd().find('.lo-row').each(function () {
        const $row = $(this);
        if ($row.find('.lo-include').is(':disabled') || !($row.data('orig-action') || '')) return;
        rows.push($row);
    });
    // 2026-09-18, 4b: "every override this employee carries" includes the tri-state answers, which are
    // not override rows and so are not in `rows` at all. They reset as ONE extra request (the endpoint
    // takes and writes the pair), added only when there is really something to reset -- and counted as
    // one item, because one request is what it costs and one line of progress is what it shows.
    const resetExemption = statutoryExemptionStateRd('tax') !== 'inherit' || statutoryExemptionStateRd('sso') !== 'inherit';
    const total = rows.length + (resetExemption ? 1 : 0);
    if (!total) return;
    const tpl = langData['line_override_confirm_restore_all_message'] || '{n} item(s) will go back to their calculated value. Continue?';
    showConfirm({
        title: langData['line_override_confirm_restore_all_title'] || 'Restore calculated values',
        message: tpl.replace('{n}', String(total)),
        tone: 'warning',
        onYes: function () { runRestoreAllComputedRd(rows, resetExemption); },
    });
}
function runRestoreAllComputedRd(rows, resetExemption) {
    const total = rows.length + (resetExemption ? 1 : 0);
    let saved = 0;
    let failedName = null;
    setLineOverrideTableBusyRd(true);
    lineOverrideProgressRd(1, total);
    const calls = rows.map(function ($row, i) {
        return function (next) {
            lineOverrideProgressRd(i + 1, total);
            $.ajax({
                url: lineOverrideEndpointRd($row.data('line-type'), 'remove'),
                method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: lineOverrideEmployeeIdRd(), item_code: $row.data('item-code') }),
                success: function (res) {
                    if (res.status) { saved++; next(true); return; }
                    failedName = $row.data('item-name');
                    next(false);
                },
                error: function () { failedName = $row.data('item-name'); next(false); },
            });
        };
    });
    if (resetExemption) {
        // Last, and as a pair: both halves go back to 'inherit' in the one write the endpoint takes.
        calls.push(function (next) {
            lineOverrideProgressRd(total, total);
            statutoryExemptionRequestRd({ tax: 'inherit', sso: 'inherit' }, function (ok) {
                if (ok) { saved++; next(true); return; }
                failedName = langData['run_exemption_title'] || 'Tax / SSO';
                next(false);
            });
        });
    }
    runSequentialAjaxRd(calls, function () {
        setLineOverrideTableBusyRd(false);
        $('#lineOverrideSaveProgress').text('');
        if (failedName) {
            const tpl = langData['line_override_save_failed_at'] || 'Could not save "{item}" -- {n} item(s) saved before it.';
            showError(tpl.replace('{item}', failedName).replace('{n}', String(saved)));
        } else {
            const tpl = langData['line_override_saved_count'] || '{n} item(s) saved.';
            showSuccess(tpl.replace('{n}', String(saved)));
        }
        // One refresh at the end whatever happened -- the table has to show what the server really
        // holds now, including the rows that did get through before a failure.
        loadSyncLineOverridesRd();
        loadRunDetail();
        if (lineOverrideHostRd.onSaved) lineOverrideHostRd.onSaved();
    });
}
$(document).on('click', '#btnRestoreAllComputedLineOverrides', restoreAllComputedLineOverridesRd);
/* `p` is a payee descriptor -- PayrollRunModel::payeeDestinationDescriptor(), the same shape for a
   template default and for this run's override. 2026-09-17, tiny-L2: every label it reads is the one
   that row's own picker would show (the code is taken off the employee's name the same way the
   picker takes it off, rules.md §5/§6), so the list line, the editor and the dropdown can no longer
   spell the same account 3 ways. */
/* 2026-09-18, tiny-L4: down to a wrapper. The 4 per-kind spellings that used to live here are now
   payeeDescriptorTextRd()'s, shared with the slip's own 2 renderers, so the card, the slip and the
   editable slip can no longer describe one payee three ways. Kept (rather than deleted) because this
   card asks a question the other 2 do not: a recurring TEMPLATE with no payee_type at all means
   "stays with the company", where a slip line with no payee means "nothing to say". That one default
   is the whole of what is left. */
function recurringDestPayeeSummary(p) {
    return payeeDescriptorTextRd(p) || (langData['payee_dest_retained'] || 'Retained by company');
}
// Live preview under the Add form once an item is picked -- tells the admin whether it's about to
// land in the Earnings or Deductions panel before they commit, since the dropdown mixes both types
// together (unlike section 2's per-type panels/modal). item_type rides along on the select2 option
// data already (see EmployeeEarningDeductionModel::activeOptions()'s SELECT).
// 2026-09-16: the picker itself (3 destinations + the "record it?" sub-question, and the mapping
// from those onto `payee_type`) is the shared component -- initPayeeDestination()/
// payeeDestinationType()/setPayeeDestination() in app.js, markup from
// partials/payee-destination.php. What stays here is only what is genuinely this tab's own: which
// fields to clear, and which defaults to fetch, when the choice changes.
let manualLineDestHasSavedRd = null; // null = not looked up yet this modal session
/* 2026-09-18, tiny-L6a: named, because this form is now opened for more than one kind of line and
   ONE of its options differs per open -- `allowNoRecord` is false while it edits a recurring
   deduction's per-run destination, whose endpoint has no "no payee at all" value to store. Everything
   else about the picker is the same whoever opened it, so it is declared once and re-registered with
   that one override (openManualLineFormRd()); initPayeeDestination() is re-callable by design. */
const MANUAL_LINE_PAYEE_OPTS_RD = {
    employeeWrap: '#manualLinePayeeWrapper',
    companyWrap: '#manualLineCompanyAccountWrapper',
    companyAccount: '#manualLineBankAccount',
    externalWrap: '#manualLineDestinationWrapper',
    onChange: function (payeeType, dest) {
        if (dest !== 'employee') {
            $('#manualLinePayeeEmployee').val(null).trigger('change');
            renderManualLinePayeeEmployeeDetailRd(null);
        }
        // 2026-09-19, 4c: the account picker IS the "record it?" answer now, so an empty one is a
        // real answer and is never pre-filled over. The per-run override editor is the one caller
        // that has no NULL to store (allowNoRecord: false) -- it still gets the default filled in.
        if (dest !== 'company_retained' && $('#manualLineBankAccount').val()) {
            $('#manualLineBankAccount').val(null).trigger('change');
            $('#manualLineBankAccountDetail').empty();
        } else if (payeeDestinationRecords('manualLine') === true
            && (PAYEE_DEST_REGISTRY['manualLine'] || {}).allowNoRecord === false
            && !$('#manualLineBankAccount').val()) {
            applyDefaultCompanyBankAccount('#manualLineBankAccount', '#manualLineBankAccountDetail');
        }
        // 2026-09-02, Deduction Destination & Third-Party Remittance.
        if (dest !== 'external') {
            clearManualLineDestinationFieldsRd();
        } else {
            refreshManualLineSavedDestinationsRd();
        }
        refreshManualLineAddStateRd();
    },
};
$(function () {
    initPayeeDestination('manualLine', MANUAL_LINE_PAYEE_OPTS_RD);
});
function setManualLinePayeeTypeRd(type) {
    setPayeeDestination('manualLine', type);
}
// A transfer to another employee is paid into THAT employee's own bank account, so an employee with
// none on file has nowhere for this money to land. The server accepts such a line today (it only
// checks the employee exists -- see BACKLOG), so this is a client-side stop: the summary line says
// what is missing and Add stays disabled while that employee is selected.
let manualLinePayeeEmployeeBlockedRd = false;
// 2026-09-19, 4c round 2: the payee summary-box renderers, the option pinner and the row->option
// builder moved to public/js/payee-descriptor.js VERBATIM -- Employee Detail's own 2 forms prefill
// the same 3 pickers from the same descriptor and could not reuse them from inside this file.
function renderManualLinePayeeEmployeeDetailRd(data) {
    manualLinePayeeEmployeeBlockedRd = renderPayeeEmployeeDetailRd('#manualLinePayeeEmployeeDetail', data);
    refreshManualLineAddStateRd();
}
// The company's PRIMARY account (bank_accounts.is_default) is preselected when this choice opens
// with nothing picked yet -- a company that has no primary flagged simply starts empty and the field
// stays mandatory (PayrollRunModel::addManualLine() rejects a missing bank_account_id either way).
// Same "re-check it is still empty when the response lands" guard applyFirstSavedDestinationDefault()
// uses, so a user who picks something while the request is in flight is never overwritten.
function clearManualLineDestinationFieldsRd() {
    $('#manualLineDestinationSelect').val(null).trigger('change');
    $('#manualLineDestinationDetail').empty();
    $('#manualLineDestAccountName, #manualLineDestAccountNo, #manualLineDestBankBranch').val('');
    $('#manualLineDestBank').val(null).trigger('change');
    $('#manualLineDestSaveForReuse').prop('checked', false);
}
// Saved vs. new is an explicit either/or now -- only the chosen half is on screen, so the two can
// never be half-filled at the same time (which used to be possible, and left the server to guess).
function setManualLineDestModeRd(mode) {
    const useSaved = mode === 'saved';
    $(`#manualLineDestModeToggle input[value="${useSaved ? 'saved' : 'new'}"]`).prop('checked', true);
    $('#manualLineDestSavedFields').toggleClass('d-none', !useSaved);
    $('#manualLineDestinationNewFields').toggleClass('d-none', useSaved);
    if (useSaved) {
        $('#manualLineDestAccountName, #manualLineDestAccountNo, #manualLineDestBankBranch').val('');
        $('#manualLineDestBank').val(null).trigger('change');
        $('#manualLineDestSaveForReuse').prop('checked', false);
    } else {
        $('#manualLineDestinationSelect').val(null).trigger('change');
    }
}
// A company with no saved destination yet has nothing to offer in the "saved" half, so that half is
// removed (not disabled) and the choice collapses to "enter a new one" with one gray line saying
// why. Answer cached per modal session and invalidated whenever a line is added with the "save this
// destination" box ticked (that add is exactly what creates the first one).
function refreshManualLineSavedDestinationsRd() {
    if (manualLineDestHasSavedRd !== null) {
        applyManualLineDestAvailabilityRd(manualLineDestHasSavedRd);
        return;
    }
    $.post(`${BASE_URL}/api/payment-destination.options`, { searchTerm: '', limit: 1 }, function (res) {
        const items = (res && res.status && res.data && res.data.items) || [];
        manualLineDestHasSavedRd = items.length > 0;
        applyManualLineDestAvailabilityRd(manualLineDestHasSavedRd);
    }, 'json');
}
/* 2026-09-17, tiny-M -- the destination the ROW being edited already points at.
   Two real bugs came from this not existing (both measured in a browser, both on the same line):
   (a) this lookup is async and its answer used to be the last word, so on the FIRST open of the
       form in a page load it landed ~124ms AFTER prefill and wiped the destination prefill had just
       put in -- the line still pointed at it, the form no longer did; on every later open the cache
       made the same call run INSIDE prefill, so prefill won instead and the form looked different
       for the same row. One order must not produce a different form from the other.
   (b) `payment-destination.options` only ever returns is_saved = 1 rows, so a line pointing at an
       ad-hoc destination had nothing in the picker to represent it at all -- it could not even be
       re-selected after being cleared.
   Both are answered by the same fact: what the row holds is pinned into the picker (initSelect2's
   own `pinnedOption`, input.js) and counts as "there is something to choose from" on its own.
   Availability therefore never moves the mode or clears the field while a row destination is
   pinned -- the same "check what is there before writing over it" rule
   applyFirstSavedDestinationDefault() already follows. */
let manualLineRowDestinationRd = null;
function manualLineHasPinnedDestinationRd() {
    return !!(manualLineRowDestinationRd && manualLineRowDestinationRd.id);
}
/* 2026-09-17, tiny-M round 3: the other 2 payee pickers (the company's bank account, and the payee
   employee) had the SAME two faults as the destination one -- the summary box under them was filled
   only by select2:select, which programmatic selection never fires, and their option was hand-built
   from whatever single field the read payload happened to carry (the employee's `employee_no`),
   so the same row read "CEO - ชื่อ" when picked by hand and "CEO" when prefilled. Same answer for all
   3: pin the row's own option through initSelect2, and render the summary with the same helper the
   user-driven path uses. These 2 are simpler than the destination picker -- no saved/new mode to
   protect -- so they share the pin/unpin pair and nothing else. */
let manualLineRowPayeeEmployeeRd = null;
let manualLineRowBankAccountRd = null;
function applyManualLineRowPayeeEmployeeRd() {
    if (!pinRowOptionRd('#manualLinePayeeEmployee', manualLineRowPayeeEmployeeRd)) return;
    // The same renderer select2:select uses -- it also decides the "no bank account on file" block,
    // which a prefilled row has to be subject to exactly as a hand-picked one is.
    renderManualLinePayeeEmployeeDetailRd(manualLineRowPayeeEmployeeRd.data);
}
function applyManualLineRowBankAccountRd() {
    if (!pinRowOptionRd('#manualLineBankAccount', manualLineRowBankAccountRd)) return;
    renderManualLineBankAccountDetailRd(manualLineRowBankAccountRd.data);
}
// One place decides whether the saved/new choice is offered at all: a saved destination exists, or
// this row brought its own.
function syncManualLineDestModeToggleRd() {
    $('#manualLineDestModeToggle').toggleClass('d-none', !(manualLineDestHasSavedRd || manualLineHasPinnedDestinationRd()));
}
function applyManualLineDestAvailabilityRd(hasSaved) {
    syncManualLineDestModeToggleRd();
    // A pinned row destination is already IN the field -- whichever way round this and prefill run,
    // the answer is the same and the value is never touched.
    if (manualLineHasPinnedDestinationRd()) {
        setManualLineDestModeRd('saved');
        return;
    }
    setManualLineDestModeRd(hasSaved ? 'saved' : 'new');
}
// Puts the row's own destination into the picker and describes it underneath, without waiting for a
// select2:select that programmatic selection never fires (that missing event is why the summary box
// was empty in edit mode from the day it was added).
function applyManualLineRowDestinationRd() {
    const dest = manualLineRowDestinationRd;
    if (!pinRowOptionRd('#manualLineDestinationSelect', dest)) return;
    setManualLineDestModeRd('saved');
    syncManualLineDestModeToggleRd();
    renderPayeeAccountDetailRd('#manualLineDestinationDetail', dest.data);
}
// Dropped when the form moves on to another line (or to a fresh Add), so a previous row's
// destination can never be offered as if it belonged to this one.
function clearManualLineRowDestinationRd() {
    if (manualLineHasPinnedDestinationRd()) {
        manualLineRowDestinationRd = null;
        unpinRowOptionRd('#manualLineDestinationSelect');
    }
    if (manualLineRowPayeeEmployeeRd) {
        manualLineRowPayeeEmployeeRd = null;
        unpinRowOptionRd('#manualLinePayeeEmployee');
    }
    if (manualLineRowBankAccountRd) {
        manualLineRowBankAccountRd = null;
        unpinRowOptionRd('#manualLineBankAccount');
    }
}
// 2026-09-15, batch 2/4 follow-up: the "Will be added as: Income/Deduction" hint this used to render
// under the form is gone -- it restated the Type field sitting right above it and broke two rules at
// once (an icon on a form label, and money green/red on a label instead of on a number). What is left
// is the one thing the hint was never about: transfer-to-payee (2026-08-21) only applies to a
// deduction, so the type still drives that block's visibility.
function syncManualLineTypeDependentsRd(itemType) {
    const isDeduction = itemType === 'deduction';
    $('#manualLinePayeeTypeWrapper').toggleClass('d-none', !isDeduction);
    if (!isDeduction) {
        setManualLinePayeeTypeRd('none');
    }
}
// The single Type field now drives all 3 modes (it used to live inside the custom-only block, so
// catalog mode had no type control at all). For catalog mode it also narrows the catalog picker:
// `data-type` is a param api/employee.earning-deduction.options already accepts and input.js re-reads
// on every search -- NOT client-side filtering of a fetched page, which would be wrong here because
// that endpoint pages 10 rows at a time (a page could legitimately contain no row of the chosen type
// while more exist further down). Any already-picked item is cleared, since it belonged to the type
// that was just switched away from.
function applyManualLineItemTypeRd(itemType) {
    const type = itemType === 'deduction' ? 'deduction' : 'earning';
    // 2026-09-17, R1b: the picker's label no longer swaps per type -- it reads "Item" either way,
    // because the modal's own title is what says which column the line belongs to.
    const $item = $('#manualLineItemSelect');
    if ($item.attr('data-type') !== type) {
        $item.attr('data-type', type);
        if ($item.val()) $item.val(null).trigger('change');
    }
    syncManualLineTypeDependentsRd(type);
    refreshManualLineAddStateRd();
}
// Every picker inside the payee callout paints its own account summary the moment it resolves to a
// real account, and clears it when the field is cleared (payeeDetailHtml(), app.js). The fields come
// from the option data the endpoint already returns -- see payeeDetailFromOption().
$(document).on('select2:select', '#manualLinePayeeEmployee', function (e) {
    renderManualLinePayeeEmployeeDetailRd(e.params.data || {});
});
$(document).on('select2:clear', '#manualLinePayeeEmployee', function () {
    renderManualLinePayeeEmployeeDetailRd(null);
});
// One renderer for both ways this box gets filled (a user picking an account, and a row being
// prefilled) -- the pair drifting apart is what left an edited row with no account summary at all.
function renderManualLineBankAccountDetailRd(data) {
    renderPayeeAccountDetailRd('#manualLineBankAccountDetail', data);
}
$(document).on('select2:select', '#manualLineBankAccount', function (e) {
    renderManualLineBankAccountDetailRd(e.params.data);
});
// Clearing empties the summary but KEEPS the row's own option pinned, so the account this line
// already pointed at can be chosen again (same rule as the destination picker).
$(document).on('select2:clear', '#manualLineBankAccount', function () {
    renderManualLineBankAccountDetailRd(null);
});
$(document).on('select2:select', '#manualLineDestinationSelect', function (e) {
    renderPayeeAccountDetailRd('#manualLineDestinationDetail', e.params.data);
});
// 2026-09-17, tiny-M: clearing used to leave the form with an empty saved-picker and no way back --
// the 4 account fields stayed hidden and the saved/new choice was hidden too whenever the company
// had nothing saved. Now it behaves like the other 2 payee forms: the account fields come back.
// The row's own destination stays PINNED in the picker on purpose, so switching back to "saved"
// can re-select the very destination this line already had (id and all) instead of forcing a new one.
$(document).on('select2:clear', '#manualLineDestinationSelect', function () {
    $('#manualLineDestinationDetail').empty();
    syncManualLineDestModeToggleRd();
    setManualLineDestModeRd('new');
});
$(document).on('change', '#manualLineDestModeToggle input[type="radio"]', function () {
    setManualLineDestModeRd($(this).val());
});
// 2026-09-17, R1b: there is no "mode" control any more. The item picker holds the whole answer --
// a real catalog id, or the one pinned option that means "not in the catalog, I'll type the name".
// The mode is therefore READ from the picker, never stored: a second variable holding the same fact
// is a second thing that can disagree with what the user is looking at.
// The old third mode (`other`, which sent is_other=true and made reports bucket the line into
// "Other Income/Deduction") has no way in from the UI now -- see prefillManualLineFormRd() for what
// happens to a row that still carries it.
const MANUAL_LINE_CUSTOM_OPTION_ID_RD = '__custom__';
function manualLineIsCustomRd() {
    return $('#manualLineItemSelect').val() === MANUAL_LINE_CUSTOM_OPTION_ID_RD;
}
// The name box exists only while the pinned option is the selection. Clearing it on the way out
// matters: a name left behind would be submitted the next time the pinned option is picked.
function syncManualLineCustomFieldRd() {
    const isCustom = manualLineIsCustomRd();
    $('#manualLineCustomFields').toggleClass('d-none', !isCustom);
    if (!isCustom) $('#manualLineCustomName').val('');
}
// The Add button stays disabled until the row genuinely has both halves of an item: a chosen/typed
// item AND a positive amount (explicit instruction). Amount is read through parseMoneyInput()
// (format-helpers.js) because the field is a `.money-input` now (§8) -- its visible value carries
// thousands separators once blurred.
function manualLineAmountValueRd() {
    return parseMoneyInput($('#manualLineAmount').val());
}
function manualLineHasItemRd() {
    if (!$('#manualLineItemSelect').val()) return false;
    return manualLineIsCustomRd() ? ($('#manualLineCustomName').val() || '').trim() !== '' : true;
}
function refreshManualLineAddStateRd() {
    const amount = manualLineAmountValueRd();
    const blocked = manualLinePayeeEmployeeBlockedRd && payeeDestinationType('manualLine') === 'employee';
    // A calculated line has no item half to fill in (the row said which item it is), and 0 is a
    // legitimate override of one -- so its only requirement is that the field holds a real, non-
    // negative number. An empty field reads back as null and fails that, same as it always did.
    const ready = lineFormIsOverrideRd()
        ? (amount !== null && amount >= 0)
        : (manualLineHasItemRd() && amount > 0);
    $('#btnSaveManualLine')
        .prop('disabled', blocked || !ready)
        .attr('title', blocked ? (langData['payee_employee_no_bank_account'] || 'This employee has no bank account on file yet') : null);
}
function resetManualLineFormRd() {
    $('#manualLineItemSelect').val(null).trigger('change');
    $('#manualLineCustomName').val('');
    syncManualLineCustomFieldRd();
    $('#manualLineCustomType').val('earning');
    applyManualLineItemTypeRd('earning');
    $('#manualLineAmount').val('');
    // `trigger('input')` so T002's auto-grow (input.js) shrinks the note box back to 2 rows -- a
    // programmatic .val('') alone leaves it at whatever height the previous note had grown it to.
    $('#manualLineComment').val('').trigger('input');
    // An employee can't be their own transfer payee -- excluded the same way #eed_payee_employee_id
    // excludes self on the Employee Detail page (data-exclude-id, read fresh on every ajax search).
    // 2026-09-16, D2: whose form this is comes from the open context (the block the + or the pencil
    // was pressed in), never from a "current employee" variable.
    $('#manualLinePayeeEmployee')
        .attr('data-exclude-id', (manualLineFormCtxRd && manualLineFormCtxRd.employeeId) || '')
        .val(null).trigger('change');
    // Before the payee reset below, which walks the same availability path: a destination pinned for
    // the row this form was last opened on must not still count as "this row has one".
    clearManualLineRowDestinationRd();
    // 2026-09-19, 4c: cleared by the reset itself. The picker's onChange only clears the account when
    // the destination SEGMENT leaves "retained by company", and a fresh open never leaves it -- so
    // without this the previous row's account would still be in the box, and an account in the box now
    // MEANS payee_type='company'. (The per-run override editor refills its default right after.)
    $('#manualLineBankAccount').val(null).trigger('change');
    $('#manualLineBankAccountDetail').empty();
    setManualLinePayeeTypeRd('none');
    refreshManualLineAddStateRd();
}
// Show/hide is driven by `change` so it also covers the programmatic .val()+trigger('change') that
// reset and prefill use. The FOCUS is bound to select2:select instead, because it must only happen
// when a person picked the option -- prefilling an existing row must not pull the caret out of the
// field the user was about to read. setTimeout(0) lets Select2 finish closing (and returning focus
// to its own container) before the name box takes it.
$(document).on('change', '#manualLineItemSelect', function () {
    syncManualLineCustomFieldRd();
});
$(document).on('select2:select', '#manualLineItemSelect', function () {
    if (!manualLineIsCustomRd()) return;
    setTimeout(() => $('#manualLineCustomName').trigger('focus'), 0);
});
// Keep the Add button's enabled state in sync with whatever the 2 required fields currently hold --
// `change` covers select2 (which fires it on the underlying <select>), `input` covers typing.
$(document).on('input change', '#manualLineAmount, #manualLineCustomName, #manualLineItemSelect', function () {
    refreshManualLineAddStateRd();
});
// Enter in the amount field = press Add (explicit instruction) -- guarded by the button's own
// disabled state, exactly like a real click would be.
$(document).on('keydown', '#manualLineAmount', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (!$('#btnSaveManualLine').prop('disabled')) $('#btnSaveManualLine').trigger('click');
});

// The payload for one manual line out of whatever the form currently holds -- ONE builder for both
// endpoints, because add-manual-line and update-manual-line take exactly the same field set (see
// PayrollController::manualLinePayload()'s own docblock on why the two must never drift apart).
// It RETURNS a refusal instead of showing one: the caller is what knows where it belongs (inside the
// form, which stays open), and a dialog on top of the form would hide the very values it is about.
function manualLineFormPayloadRd() {
    const ctx = manualLineFormCtxRd || {};
    const amount = manualLineAmountValueRd();
    const comment = $('#manualLineComment').val().trim();
    const payload = { id: PAYROLL_RUN_ID, employee_id: ctx.employeeId, amount: amount, note: comment };
    if (manualLineIsCustomRd()) {
        const customName = $('#manualLineCustomName').val().trim();
        const customType = $('#manualLineCustomType').val();
        if (!customName || !customType || !amount || amount <= 0) {
            return { ok: false, message: langData['required_star_message'] || 'Please fill all fields marked with *' };
        }
        // Always a plain custom line (stored as `CUSTOM:{name}`) -- `is_other` is never sent from
        // here any more, so a legacy `other` row saved through this form comes back as custom.
        payload.custom_item_name = customName;
        payload.custom_item_type = customType;
    } else {
        const pedTypeId = $('#manualLineItemSelect').val();
        if (!pedTypeId || !amount || amount <= 0) {
            return { ok: false, message: langData['required_star_message'] || 'Please fill all fields marked with *' };
        }
        payload.ped_type_id = pedTypeId;
    }
    // 2026-08-31, same-day follow-up: same 4-way payee_type toggle as Employee Detail's own EED
    // modal -- only read when the wrapper is actually visible (a deduction), same shape either
    // catalog or custom mode uses now (unified, was split per-branch above before this follow-up).
    if (!$('#manualLinePayeeTypeWrapper').hasClass('d-none')) {
        const choice = manualLinePayeeChoiceRd();
        if (!choice.ok) return choice;
        const payeeType = choice.payeeType;
        if (payeeType !== 'none') {
            payload.payee_type = payeeType;
            // include_in_cash_summary is deliberately NOT sent: the checkbox is gone from this form
            // (nothing reads the column yet -- see BACKLOG), and an absent key is exactly what makes
            // the model keep the column's own default rather than storing an opted-out 0.
        }
        if (payeeType === 'employee') {
            payload.payee_employee_id = choice.payeeEmployeeId || undefined;
        } else if (payeeType === 'company') {
            payload.bank_account_id = choice.bankAccountId || undefined;
        }
        // 2026-09-02, Deduction Destination & Third-Party Remittance -- either an existing saved
        // destination_id, or the new-account fields (validated/created server-side by
        // PaymentDestinationModel::resolveOrCreate(), see addManualLine()'s own docblock).
        if (payeeType === 'other_person') {
            payload.destination = choice.destination;
        }
    }
    return { ok: true, payload: payload };
}
/* The payee half of what the form holds, read ONCE for both write paths (2026-09-18, tiny-L6a).
   A manual line and a recurring deduction's per-run destination take the same 4 facts and differ
   only in how they are spelled on the wire -- the manual endpoint nests the external account under
   `destination`, the recurring one takes those same fields flat (see
   PayrollRunModel::recurringDeductionDestinationOverrideSave(), which hands $data straight to
   PaymentDestinationModel::resolveOrCreate()). Reading them twice is what would let the 2 forms
   drift apart, so the reading is here and only the shaping is per endpoint.
   RETURNS a refusal instead of showing one, same contract as manualLineFormPayloadRd() itself. */
function manualLinePayeeChoiceRd() {
    const payeeType = payeeDestinationType('manualLine');
    const choice = { ok: true, payeeType: payeeType, payeeEmployeeId: null, bankAccountId: null, destination: null };
    if (payeeType === 'employee') {
        choice.payeeEmployeeId = $('#manualLinePayeeEmployee').val() || null;
        if (!choice.payeeEmployeeId) {
            return { ok: false, message: langData['payee_employee_select_required'] || 'Please select the payee employee.' };
        }
        // A transfer to an employee is paid into THAT employee's own account, so one with none on
        // file has nowhere for this money to land -- the same client-side stop the Save button's own
        // disabled state already shows, said in words for the path that reaches here anyway.
        if (manualLinePayeeEmployeeBlockedRd) {
            return { ok: false, message: langData['payee_employee_no_bank_account'] || 'This employee has no bank account on file yet' };
        }
    } else if (payeeType === 'company') {
        // 2026-09-10, Batch 3B item 3: level-2, mandatory -- both models reject a missing value.
        choice.bankAccountId = $('#manualLineBankAccount').val() || null;
        if (!choice.bankAccountId) {
            return { ok: false, message: langData['bank_account_select_required'] || 'Please select a bank account.' };
        }
    } else if (payeeType === 'other_person') {
        const useSavedDestination = !$('#manualLineDestSavedFields').hasClass('d-none');
        const savedDestinationId = useSavedDestination ? $('#manualLineDestinationSelect').val() : '';
        const refused = { ok: false, message: langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.' };
        if (useSavedDestination) {
            if (!savedDestinationId) return refused;
            choice.destination = { destination_id: savedDestinationId };
        } else {
            const accountName = $('#manualLineDestAccountName').val().trim();
            const accountNo = $('#manualLineDestAccountNo').val().trim();
            const bankId = $('#manualLineDestBank').val();
            if (!accountName || !accountNo || !bankId) return refused;
            choice.destination = {
                account_name: accountName, account_no: accountNo, bank_id: bankId,
                bank_branch: $('#manualLineDestBankBranch').val().trim() || undefined,
                is_saved: $('#manualLineDestSaveForReuse').is(':checked'),
            };
        }
    }
    return choice;
}

/* ---------- The add/edit form (#manualLineFormModal) -- one form, two hosts (2026-09-16, D2) -------
   The form itself is markup in payroll/detail.php, inside a nested modal; this is everything that
   drives it. It opens from the + on a column head (the type comes from WHICH head) or from a row
   (edit). It has always been the only implementation: the "รายการจ่าย" tab's own inline form WAS
   this markup before D2 moved it into a modal of its own, and that tab is gone entirely now (§0.4). */
// Which block the open form belongs to: whose lines, which row (absent = a new one), where the block
// is, and what has to be reloaded once the write lands.
let manualLineFormCtxRd = null;
// 2026-09-18, 4a-2: a hand-added line is a ROW of the line-override table now, so its own host IS
// that table's host -- there is no second block to resolve a click into any more. Writing one takes
// the SAME path an override does (`lineOverrideAfterWriteRd`): one added line moves the statutory
// figures and all 3 totals with it, so the whole table is re-read, never patched row by row.
function lineOverrideManualHostRd() {
    if (!breakdownRowRd) return null;
    return {
        mount: lineOverrideHostRd.mount,
        employeeId: breakdownRowRd.employee_id,
        canEdit: employeeRowEditableRd(breakdownRowRd),
        onSaved: lineOverrideAfterWriteRd,
    };
}
// While a write is in flight the whole block is inert -- a second action would race the recalculate
// the first one is already running (the same rule, and the same `.block-busy`, as the line-override
// table above).
function manualLineBlockBusyRd(mountSelector, busy) {
    const $mount = $(mountSelector);
    $mount.toggleClass('block-busy', !!busy);
    $mount.find('button').prop('disabled', !!busy);
}
// A refusal belongs where the values that caused it still are: inside the form, which stays open
// (§9) -- one box per form, `message` falsy clears it.
// 2026-09-17, tiny-L: generalized from #manualLineFormError-only to take the box, so the recurring
// destination editor's own refusals render the identical callout instead of a second copy of this
// (CLAUDE.md's "mirror-by-copy is not acceptable" -- the 2 callers differ only by which box).
function formCalloutErrorRd(boxSelector, message) {
    const $box = $(boxSelector);
    if (!message) {
        $box.empty().addClass('d-none');
        return;
    }
    $box.html(calloutHtml(escapeHtml(message), 'danger')).removeClass('d-none');
}
function manualLineFormErrorRd(message) {
    formCalloutErrorRd('#manualLineFormError', message);
}
// The type is never a choice in this form: it comes from the column head that was pressed, or from
// the row being edited. The control still shows it, read-only, so the form says which column the
// line belongs to.
// 2026-09-17, R1: the type has no control of its own any more -- the column whose + was pressed (or
// the row being edited) already answered it, and the modal's own title now says which side this is.
// It stays in the form as a hidden field so every reader of it (payload builder, catalog filter)
// keeps reading the same id it always did.
function setManualLineTypeRd(itemType) {
    const type = itemType === 'deduction' ? 'deduction' : 'earning';
    $('#manualLineCustomType').val(type);
    applyManualLineItemTypeRd(type);
}
/* ---------- which sections this form shows, per open (2026-09-18, tiny-L6a) ---------------------
   One form, 4 kinds of line, and what may be edited differs per kind because the STORE behind each
   one does. Nothing is disabled: a section the line has no editable store for is simply not part of
   the form for that open (rules.md §0.3 -- a control that cannot do anything is not information).
     manual (payroll_run_manual_lines)  -> item, amount, note, destination (deductions only)
     recurring_deduction                -> amount, note, destination for THIS RUN (its own override)
     ped  (employee_earning_deductions) -> amount, note; destination stated read-only + where to
                                           change it, because it belongs to the assignment not the run
     everything else (base salary, statutory, recurring_earning, transfer_in, sync-derived)
                                        -> amount, note
   `payee` is a 3-way answer, not a boolean, which is exactly why it is not a `disabled` flag. */
function lineFormIsOverrideRd() {
    return !!manualLineFormCtxRd && manualLineFormCtxRd.kind === 'override';
}
function lineFormSectionsRd(ctx) {
    if (!ctx || ctx.kind !== 'override') {
        // The manual form as it always was: the deduction/earning split still decides the payee
        // block (syncManualLineTypeDependentsRd), so this says "editable" and lets that decide.
        return { item: true, computed: false, payee: 'edit' };
    }
    const line = ctx.overrideLine || {};
    const source = line.source || '';
    let payee = 'none';
    if (source === 'recurring_deduction') {
        payee = 'edit';
    } else if (source === 'ped' && line.payee && line.payee.payee_type) {
        payee = 'readonly';
    }
    // 2026-09-19, H-ui: no `useComputed` slot any more. "Back to what the system calculated" is a row
    // of the line's own history table (its top one), which is where every other value this line has
    // ever held is already listed -- one way back, not two.
    return { item: false, computed: true, payee: payee };
}
function applyLineFormSectionsRd(sections, ctx) {
    $('#manualLineItemCol').toggleClass('d-none', !sections.item);
    // The amount field takes the whole row once the item picker beside it is gone.
    $('#manualLineAmountCol').toggleClass('col-lg-4', sections.item).toggleClass('col-lg-12', !sections.item);
    if (!sections.item) $('#manualLineCustomFields').addClass('d-none');
    $('#manualLinePayeeTypeWrapper').toggleClass('d-none', sections.payee !== 'edit');
    $('#manualLinePayeeReadonly').toggleClass('d-none', sections.payee !== 'readonly');
    if (sections.payee === 'readonly') {
        renderLineFormPayeeReadonlyRd(ctx);
    }
}
// The destination this line already routes to, in the same words every other surface uses for it,
// plus the page where it can actually be changed.
function renderLineFormPayeeReadonlyRd(ctx) {
    const line = (ctx && ctx.overrideLine) || {};
    $('#manualLinePayeeReadonlyText').text(payeeDescriptorTextRd(line.payee));
    // 2026-09-19, 4c: /employees/{employee_no}, not {id} -- that route matches on employee_no (see
    // PayrollRunModel's own `employee_detail_url`), so the internal id this link used to put there
    // landed on the wrong employee or on nothing at all. Plus the tab: the destination this line is
    // talking about is edited on ONE tab of a 10-tab page, and a link that lands on the first tab
    // asks the reader to go find it.
    const row = runDetailRowByEmployeeId(ctx && ctx.employeeId);
    const employeeNo = row && row.employee_no;
    $('#manualLinePayeeReadonlyLink')
        .attr('href', employeeNo ? `${BASE_URL}/employees/${encodeURIComponent(employeeNo)}#earningDeduction-tab` : null)
        .toggleClass('d-none', !employeeNo);
}
// The calculated figure, under the field that replaces it. Absent -- not blank -- for a line whose
// own calculated value was never recorded (see lineOverrideComputedTextRd()).
function renderLineFormComputedHintRd(ctx) {
    const $hint = $('#manualLineComputedHint');
    const text = (ctx && ctx.kind === 'override') ? lineOverrideComputedTextRd(ctx.overrideLine) : '';
    if (!text) { $hint.text('').addClass('d-none'); return; }
    $hint.text((langData['line_form_computed_hint'] || 'Calculated {amount}').replace('{amount}', text))
        .removeClass('d-none');
}
function openManualLineFormRd(ctx) {
    manualLineFormCtxRd = ctx;
    const isOverride = ctx.kind === 'override';
    const isEdit = isOverride || !!ctx.line;
    manualLineFormErrorRd('');
    const sections = lineFormSectionsRd(ctx);
    // Before the reset: it walks the payee path, and that path has to already know whether this open
    // offers the "record it against a company account?" sub-question at all (the recurring
    // destination endpoint has no "no payee" value to store -- see its own model method).
    initPayeeDestination('manualLine', $.extend({}, MANUAL_LINE_PAYEE_OPTS_RD, {
        allowNoRecord: !(isOverride && sections.payee === 'edit'),
    }));
    resetManualLineFormRd();
    applyLineFormSectionsRd(sections, ctx);
    lineFormApplyTitleRd(ctx, isEdit);
    // Built per open, not toggled: the primary button's LABEL is the difference between adding and
    // saving an edit, and modalFooterButtonsHtml() (§9/§11) is what keeps the pair from drifting on
    // size/class. Primary left, [ปิด] right -- §4's order.
    $('#manualLineFormFooter').html(modalFooterButtonsHtml({
        primary: { id: 'btnSaveManualLine', key: isEdit ? 'save' : 'add_line', fallback: isEdit ? 'Save' : 'Add Line' },
        secondary: { key: 'close', fallback: 'Close', dismiss: true },
    }));
    if (isOverride) {
        prefillLineOverrideFormRd(ctx.overrideLine);
    } else if (ctx.line) {
        prefillManualLineFormRd(ctx.line);
    } else {
        setManualLineTypeRd(ctx.itemType);
    }
    renderLineFormComputedHintRd(ctx);
    refreshManualLineAddStateRd();
    new bootstrap.Modal(document.getElementById('manualLineFormModal')).show();
}
/* The header. 2026-09-17, R1 gave the manual form 4 titles because the type control was gone and the
   header was the only thing left that could say which column a NEW line belongs to. That is still
   true for adding; for an existing line of any kind the header says WHICH LINE this is, which the 4
   generic titles never did (2026-09-18, tiny-L6a -- rules.md §9, header = ชื่อ + ×).
   `data-i18n` is removed along with it, or the next language sweep would paint the key back over
   the item's own name. */
function lineFormApplyTitleRd(ctx, isEdit) {
    const $label = $('#manualLineFormModalLabel');
    const name = lineFormItemNameRd(ctx);
    if (isEdit && name) {
        $label.removeAttr('data-i18n').text(name);
        return;
    }
    const isDeduction = (ctx.line ? ctx.line.item_type : ctx.itemType) === 'deduction';
    const titleKey = (isEdit ? 'manual_line_form_edit_' : 'manual_line_form_add_') + (isDeduction ? 'deduction' : 'earning');
    $label.attr('data-i18n', titleKey).text(langData[titleKey] || (isEdit ? 'Edit Item' : 'Add Item'));
}
function lineFormItemNameRd(ctx) {
    const line = ctx.kind === 'override' ? ctx.overrideLine : ctx.line;
    if (!line) return '';
    const th = line.name_th !== undefined ? line.name_th : line.item_name_th;
    const en = line.name_en !== undefined ? line.name_en : line.item_name_en;
    return rowOptionLabelRd(th, en, line.code || line.item_code || '');
}
// Every field of an existing line, back into the form it was created with. The catalog picker is a
// select2-remote (no options in the markup at all), so its current value has to be appended as a
// real option first -- the same populate-a-remote-select step Employee Detail's own
// populateSelect2Field() does, and the same silent data loss if it is skipped.
function prefillManualLineFormRd(line) {
    // FIRST, before anything can ask "is there a destination to choose from": setPayeeDestination()
    // below reaches refreshManualLineSavedDestinationsRd() synchronously once its answer is cached,
    // and that path must already be able to see what this row holds (see
    // applyManualLineDestAvailabilityRd()'s own docblock for the 2 bugs this ordering fixes).
    // Same for the other 2 pickers: set before the payee choice below, because its onChange can
    // reach applyDefaultCompanyBankAccount() straight away.
    const pins = payeeRowPinnedOptionsRd(line);
    manualLineRowPayeeEmployeeRd = pins.payeeEmployee;
    manualLineRowBankAccountRd = pins.bankAccount;
    manualLineRowDestinationRd = pins.destination;
    setManualLineTypeRd(line.item_type);
    const label = (currentLang === 'th' ? line.item_name_th : line.item_name_en) || line.item_name_th || line.item_name_en || '';
    // 2026-09-17, R1b: both kinds of hand-typed line -- plain custom AND the retired `other` -- open
    // on the pinned option with the stored name in the box, because that is the only way in the UI
    // now. Saving such a row back therefore drops `is_other` (see manualLineFormPayloadRd()); the
    // column and its enum are untouched server-side.
    if (line.is_custom) {
        $('#manualLineItemSelect').empty()
            .append(new Option(langData['manual_line_item_custom_option'] || 'Other (enter a name)', MANUAL_LINE_CUSTOM_OPTION_ID_RD, true, true))
            .trigger('change');
        $('#manualLineCustomName').val(label);
    } else {
        $('#manualLineItemSelect').empty().append(new Option(label, line.ped_type_id, true, true)).trigger('change');
    }
    $('#manualLineAmount').val(fmtNum(line.amount)).attr('data-raw-value', line.amount);
    $('#manualLineComment').val(line.note || '').trigger('input');
    // The destination choice first (it shows/hides the 3 sub-forms and can fetch a default company
    // account), then this line's own values on top -- applyDefaultCompanyBankAccount() re-checks the
    // field before applying, so its in-flight request cannot overwrite what is set here.
    setPayeeDestination('manualLine', line.payee_type || 'none');
    // All 3 destinations go back the same way now: the row's own option, pinned, plus the same
    // summary the user-driven path renders.
    if (line.payee_type === 'employee' && line.payee_employee_id) {
        applyManualLineRowPayeeEmployeeRd();
    } else if (line.payee_type === 'company' && line.bank_account_id) {
        applyManualLineRowBankAccountRd();
    } else if (line.payee_type === 'other_person' && line.destination_id) {
        // The destination this line already points at, put back through the shared pinned-option
        // mechanism (it may be an is_saved = 0 row the picker's own endpoint will never return) and
        // described underneath from the fields the read payload now carries.
        applyManualLineRowDestinationRd();
    }
}
/* A calculated line, into the same fields a hand-added one uses (2026-09-18, tiny-L6a).
   The amount is what the run really pays today (already through any override); the note is the
   OVERRIDE's own note, which is the only note this line can carry -- an engine note (`line.note`,
   statutory "why is this 0") is not something the user wrote and must not come back as their text.
   The payee pickers are filled from `line.payee`, the descriptor every read path already builds:
   it carries exactly the field names payeeRowPinnedOptionsRd() reads, so nothing is re-composed. */
function prefillLineOverrideFormRd(line) {
    const payee = line.payee || null;
    const pins = payeeRowPinnedOptionsRd(payee || {});
    manualLineRowPayeeEmployeeRd = pins.payeeEmployee;
    manualLineRowBankAccountRd = pins.bankAccount;
    manualLineRowDestinationRd = pins.destination;
    // The hidden type still drives the catalog filter and nothing else here -- there is no picker
    // for it to filter, but every reader of it keeps reading the same id it always did.
    $('#manualLineCustomType').val(line.item_type === 'earning' ? 'earning' : 'deduction');
    $('#manualLineAmount').val(fmtNum(line.current_amount)).attr('data-raw-value', line.current_amount);
    $('#manualLineComment').val(line.override_note || '').trigger('input');
    if (!$('#manualLinePayeeTypeWrapper').hasClass('d-none')) {
        setPayeeDestination('manualLine', (payee && payee.payee_type) || 'none');
        if (payee && payee.payee_type === 'employee' && payee.payee_employee_id) {
            applyManualLineRowPayeeEmployeeRd();
        } else if (payee && payee.payee_type === 'company' && payee.bank_account_id) {
            applyManualLineRowBankAccountRd();
        } else if (payee && payee.payee_type === 'other_person' && payee.destination_id) {
            applyManualLineRowDestinationRd();
        }
    }
}
/* ---------- saving a calculated line (2026-09-18, tiny-L6a) -------------------------------------
   Up to TWO writes, because the amount and the destination of a recurring deduction live in two
   different tables with two different keys: the amount is an override keyed by (run, employee,
   item_code) and the destination is an override keyed by (run, recurring_id). Only what really
   changed is sent, so the ordinary "just fix the number" case is still one request.
   ORDER, and why: amount first. Both endpoints recalculate() the whole run internally, so they must
   never overlap (runSequentialAjaxRd's own rule) -- and the destination call is the one with a side
   effect OUTSIDE the run (an external payee may create a payment_destinations row through
   PaymentDestinationModel::resolveOrCreate()). Running it second means a refusal on the simpler
   write leaves nothing behind at all.
   If the second one fails, the first one has landed: the table is reloaded so the saved amount is
   visible behind the form, the refusal is shown in the form, and the dirty baseline is re-taken --
   otherwise closing would ask about changes that were saved. */
function lineOverrideFormPlanRd(ctx) {
    const line = ctx.overrideLine;
    const amount = manualLineAmountValueRd();
    if (amount === null || amount < 0) {
        return { ok: false, message: langData['required_star_message'] || 'Please fill all fields marked with *' };
    }
    const note = $('#manualLineComment').val().trim();
    const plan = { ok: true, amount: amount, note: note, sendAmount: false, payee: null };
    const currentAmount = Number(line.current_amount);
    const amountMoved = isNaN(currentAmount) || Math.abs(amount - currentAmount) >= 0.005;
    plan.sendAmount = amountMoved || note !== (line.override_note || '');
    if (!$('#manualLinePayeeTypeWrapper').hasClass('d-none')) {
        const choice = manualLinePayeeChoiceRd();
        if (!choice.ok) return choice;
        if (lineOverridePayeeChangedRd(line, choice)) plan.payee = choice;
    }
    return plan;
}
// Did the destination really move? A re-send of the same destination would write an identical
// override row and recalculate the run for nothing -- and, for an ad-hoc external account, create a
// SECOND payment_destinations row saying the same thing.
function lineOverridePayeeChangedRd(line, choice) {
    const payee = line.payee || {};
    const before = payee.payee_type || 'none';
    if (choice.payeeType !== before) return true;
    if (choice.payeeType === 'employee') return String(choice.payeeEmployeeId || '') !== String(payee.payee_employee_id || '');
    if (choice.payeeType === 'company') return String(choice.bankAccountId || '') !== String(payee.bank_account_id || '');
    if (choice.payeeType === 'other_person') {
        const dest = choice.destination || {};
        // A newly typed account is always a change; a picked saved one only when it is a different id.
        return !dest.destination_id || String(dest.destination_id) !== String(payee.destination_id || '');
    }
    return false;
}
// The recurring endpoint takes the external account's fields FLAT, where the manual-line one nests
// them under `destination` -- the only difference between the two, and the reason this shaper exists
// rather than a second reader of the form.
function recurringDestOverridePayloadRd(recurringId, choice, note) {
    const payload = { id: PAYROLL_RUN_ID, recurring_id: recurringId, payee_type: choice.payeeType, note: note };
    if (choice.payeeType === 'employee') payload.payee_employee_id = choice.payeeEmployeeId;
    if (choice.payeeType === 'company') payload.bank_account_id = choice.bankAccountId;
    if (choice.payeeType === 'other_person') $.extend(payload, choice.destination);
    return payload;
}
function submitLineOverrideFormRd($btn, ctx) {
    const line = ctx.overrideLine;
    const plan = lineOverrideFormPlanRd(ctx);
    if (!plan.ok) {
        manualLineFormErrorRd(plan.message);
        return;
    }
    if (!plan.sendAmount && !plan.payee) {
        // Nothing to write. Closing is the honest outcome -- a "saved" toast for a request that was
        // never sent would say something that did not happen.
        lineFormCloseRd();
        return;
    }
    let failMessage = null;
    let failed = false;
    const calls = [];
    if (plan.sendAmount) {
        calls.push(function (next) {
            lineOverrideRequestRd(line.line_type,
                lineOverridePayloadRd(line.code, { action: 'override_amount', amount: plan.amount, note: plan.note }),
                'save',
                function (ok, message) { if (!ok) { failed = true; failMessage = message; } next(ok); });
        });
    }
    if (plan.payee) {
        calls.push(function (next) {
            $.ajax({
                url: `${BASE_URL}/api/payroll-run.recurring-deduction-destination-override.save`,
                method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify(recurringDestOverridePayloadRd(line.recurring_id, plan.payee, plan.note)),
                success: function (res) { if (!res.status) { failed = true; failMessage = res.message; } next(!!res.status); },
                error: function () { failed = true; next(false); },
            });
        });
    }
    manualLineFormErrorRd('');
    setButtonLoading($btn, true);
    setLineOverrideTableBusyRd(true);
    runSequentialAjaxRd(calls, function () {
        setButtonLoading($btn, false);
        // Reloaded either way: on success because one override moves every other figure with it, and
        // on a half-done failure because what DID save has to be visible behind the form.
        lineOverrideAfterWriteRd();
        if (failed) {
            manualLineFormErrorRd(failMessage || langData['save_failed'] || 'Could not save.');
            // The form stays open on the values that were refused -- so the baseline it is compared
            // against has to become those values, or the close guard would ask again about the half
            // that already landed.
            refreshDirtyGuard('#manualLineFormModal');
            return;
        }
        showSuccess(langData['line_override_saved'] || 'Saved.');
        lineFormCloseRd();
    });
}
// Closing from code, past the dirty guard: the reason for closing is that there is nothing unsaved
// left (or that the user confirmed dropping the override), which is exactly what that guard asks.
function lineFormCloseRd() {
    const el = document.getElementById('manualLineFormModal');
    const inst = bootstrap.Modal.getInstance(el);
    if (!inst) return;
    $(el).data('dirtyGuardBypass', true);
    inst.hide();
}
// Adding and editing differ in 2 places only: the endpoint, and one extra id in the body. Everything
// else -- validation, the busy lock, what happens after -- is deliberately one path.
function submitManualLineFormRd($btn) {
    const ctx = manualLineFormCtxRd;
    if (!ctx) return;
    if (ctx.kind === 'override') {
        submitLineOverrideFormRd($btn, ctx);
        return;
    }
    const built = manualLineFormPayloadRd();
    if (!built.ok) {
        manualLineFormErrorRd(built.message);
        return;
    }
    const payload = built.payload;
    const isEdit = !!ctx.line;
    if (isEdit) payload.line_id = ctx.line.id;
    manualLineFormErrorRd('');
    setButtonLoading($btn, true);
    manualLineBlockBusyRd(ctx.mount, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.${isEdit ? 'update' : 'add'}-manual-line`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            setButtonLoading($btn, false);
            manualLineBlockBusyRd(ctx.mount, false);
            if (!res.status) {
                // Stays open, with the values that were refused still in it.
                manualLineFormErrorRd(res.message || langData['save_failed'] || 'Failed to save data.');
                return;
            }
            if (payload.destination && payload.destination.is_saved) {
                manualLineDestHasSavedRd = null; // this write just created the first/next saved one
            }
            // Past the dirty guard: what it asks about was just written (§9's "หลัง save สำเร็จ").
            lineFormCloseRd();
            ctx.onSaved();
        },
        error: function () {
            setButtonLoading($btn, false);
            manualLineBlockBusyRd(ctx.mount, false);
            manualLineFormErrorRd(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
}
$(document).on('click', '#btnSaveManualLine', function () {
    submitManualLineFormRd($(this));
});
// 2026-09-17, R1: nothing to re-enable on the way out any more -- the type is a hidden input now,
// not a disabled select2 that would stay disabled until told otherwise.
$(document).on('hidden.bs.modal', '#manualLineFormModal', function () {
    manualLineFormCtxRd = null;
});
// 2026-09-18, 4a-2: the last row of a manual group, not a button on a card head. Same form, and the
// row's own `data-item-type` still says which group it was pressed under.
$(document).on('click', '.lo-mount .lo-add-line-btn', function () {
    const host = lineOverrideManualHostRd();
    if (!host || !host.canEdit) return;
    openManualLineFormRd({
        mount: host.mount,
        employeeId: host.employeeId,
        itemType: $(this).data('item-type'),
        onSaved: host.onSaved,
    });
});
// Edit reads the line back from the server before filling the form in, never out of the rendered
// row: what is on screen is as old as the last load of the block, and this form writes every column
// of that row back (updateManualLine() sets them all, including the ones left empty).
function openManualLineEditRd(lineId) {
    const host = lineOverrideManualHostRd();
    if (!host || !host.canEdit || !lineId) return;
    manualLineBlockBusyRd(host.mount, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.manual-lines`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: host.employeeId },
        dataType: 'json',
        success: function (res) {
            manualLineBlockBusyRd(host.mount, false);
            const line = ((res && res.data) || []).find(l => Number(l.id) === Number(lineId));
            if (!line) {
                showWarning(langData['load_failed'] || 'Failed to load data.');
                return;
            }
            openManualLineFormRd({
                mount: host.mount,
                employeeId: host.employeeId,
                itemType: line.item_type,
                line: line,
                onSaved: host.onSaved,
            });
        },
        error: function () {
            manualLineBlockBusyRd(host.mount, false);
            showWarning(langData['load_failed'] || 'Failed to load data.');
        }
    });
}
// 2026-09-17, R1 follow-up: the pencil is the ONLY way in. Pressing the row itself used to open the
// same form -- which made every name, note and figure in the block a control, with no way to select
// or even read a row without starting an edit. `.manual-line-item-editable` still marks a row that
// HAS actions (the class the pencil/bin are rendered under), it just is not a press target.
$(document).on('click', '.lo-mount .manual-line-edit-btn', function (e) {
    e.stopPropagation();
    openManualLineEditRd($(this).data('line-id'));
});
$(document).on('click', '.lo-mount .manual-line-remove-btn', function (e) {
    e.stopPropagation();
    const host = lineOverrideManualHostRd();
    const lineId = $(this).data('line-id');
    if (!host || !host.canEdit || !lineId) return;
    // §10: object form with `tone: 'danger'` -- removing a line is destructive and writes
    // immediately (there is no Save step to undo it before), so the confirm reads as the red one.
    // One confirm, then the write -- never a second question.
    showConfirm({
        title: langData['action_remove'] || 'Remove',
        message: langData['confirm_remove_line_message'] || 'Remove this item?',
        confirmText: langData['action_remove'] || 'Remove',
        tone: 'danger',
        onYes: function () {
            manualLineBlockBusyRd(host.mount, true);
            $.ajax({
                url: `${BASE_URL}/api/payroll-run.remove-manual-line`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: PAYROLL_RUN_ID, line_id: lineId }),
                success: function (res) {
                    manualLineBlockBusyRd(host.mount, false);
                    if (!res.status) {
                        showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                        return;
                    }
                    host.onSaved();
                },
                error: function () {
                    manualLineBlockBusyRd(host.mount, false);
                    showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
                }
            });
        },
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
/* 2026-09-22, n: ONE mode variable for the whole picker, never a second table or a second modal.
   'all'     = the pre-existing Join Employees behaviour (every employee this run could still take).
   'missing' = only the ones this run's Origami sync left out, i.e. exactly what
               #syncMissingEmployeesBanner counts (api/payroll-run.sync-missing-employees and the
               picker's own `missing_only` share one server-side WHERE --
               PayrollRunModel::buildManualEmployeeWhere(), tiny-1 -- so the two can never disagree).
   Read by all 3 of the picker's endpoints, by the title/callout/label/column swaps, and reset by
   openJoinEmployeesModalRd() on EVERY open so a mode can never leak into the next opening. */
let joinEmployeesMode = 'all';
const joinEmployeesModeIsMissingRd = () => joinEmployeesMode === 'missing';

function updateJoinSelectedCountRd() {
    const count = Object.keys(joinSelectedEmployees).length;
    $('#joinSelectedCount').text(`${count} ${langData['bulk_pull_selected_label'] || 'selected'}`);
    $('#btnJoinSelected').prop('disabled', count === 0);
    renderJoinPrimaryLabelRd();
}

/* 2026-09-22, n: the footer primary's label. In `all` mode it is a plain i18n string and KEEPS its
   own `data-i18n` marker, so app.js's generic sweep owns it exactly as before. In `missing` mode it
   is a `{count}` TEMPLATE, which that sweep cannot fill -- so the marker is removed for as long as
   that mode lasts (leaving it would paint the literal "{count}" onto the button on the next language
   switch) and refreshPayrollDetailLanguage() calls this instead, the same contract every other
   JS-templated string on this page already follows. */
function renderJoinPrimaryLabelRd() {
    const $label = $('#btnJoinSelectedLabel');
    if (!joinEmployeesModeIsMissingRd()) {
        $label.attr('data-i18n', 'action_join_employees').text(langData['action_join_employees'] || 'Join Employees');
        return;
    }
    const count = Object.keys(joinSelectedEmployees).length;
    $label.removeAttr('data-i18n')
        .text((langData['action_pull_selected'] || 'Pull Selected ({count})').replace('{count}', count));
}

/* The picker's own empty state, as a CONFIG for the shared renderer (§11) -- not HTML handed to
   DataTables' own `language.emptyTable`, which is read once at construction and would freeze both
   the mode and the language into it. `missing` mode has a real thing to say when it comes back empty
   (nobody is missing any more -- the banner that opened this modal is about to disappear on the next
   loadRunDetail()), which is not the same sentence as an ordinary picker with nothing left to offer.
   Rebuilt on every draw, so mode and language are always the current ones. */
function joinEmptyStateRd() {
    const missing = joinEmployeesModeIsMissingRd();
    return {
        icon: missing ? 'fa-solid fa-user-check' : 'fa-solid fa-users',
        title: missing
            ? (langData['sync_missing_empty_state'] || 'No employee is missing from this sync')
            : (langData['emptyTable'] || 'No data available in table'),
    };
}

/* Everything that differs between the 2 modes, in one place. Called BEFORE the table is (re)loaded
   so the empty state is already right when the reply lands. The `tb_join_employees` guard is for the
   very first open, where the table does not exist yet -- initJoinEmployeesTable() builds it with the
   same two values (`language.emptyTable`, column 7's `visible`) read off this same mode. */
function applyJoinEmployeesModeRd() {
    const missing = joinEmployeesModeIsMissingRd();
    const titleKey = missing ? 'sync_missing_pull_title' : 'join_employees_title';
    $('#joinEmployeesModalTitleText').attr('data-i18n', titleKey)
        .text(langData[titleKey] || (missing ? 'Pull Employees Missing from This Sync' : 'Join Employees'));
    $('#joinEmployeesMissingCallout').toggleClass('d-none', !missing);
    renderJoinPrimaryLabelRd();
    if (tb_join_employees) {
        // `false` = don't recalculate column widths yet; the adjust() right after does it once.
        tb_join_employees.column(7).visible(missing, false);
        tb_join_employees.columns.adjust();
    }
}

/* The ONE way this modal is opened -- both the toolbar's #btnJoinEmployees and the sync-missing
   banner's own "ดูรายชื่อ" go through here, so resetting the selection/filters/mode can never be
   forgotten by one of them. */
function openJoinEmployeesModalRd(mode) {
    joinEmployeesMode = mode === 'missing' ? 'missing' : 'all';
    joinSelectedEmployees = {};
    updateJoinSelectedCountRd();
    $('#joinFilterDepartment, #joinFilterTeam, #joinFilterPosition, #joinFilterCycle').val(null).trigger('change');
    // Cycle-only run: this modal can only ever re-include a previously-removed employee (see
    // manualEmployeeOptions()'s cycle-only branch server-side) -- say so, since "Join Employees"
    // otherwise implies adding someone brand new. Never true in `missing` mode (that mode only
    // exists on a sync-based run, and a sync run is not a pure cycle run) -- left unconditional
    // anyway so the one hint has one owner.
    const isPureCycleRun = currentRun && currentRun.cycle_id && !currentRun.sync_process_id;
    $('#joinEmployeesHint').text(isPureCycleRun
        ? (langData['join_employees_hint_cycle_only'] || 'This run\'s membership is automatic by employment date -- only employees previously removed from it are shown here.')
        : '');
    applyJoinEmployeesModeRd();
    new bootstrap.Modal(document.getElementById('joinEmployeesModal')).show();
    initJoinEmployeesTable();
}

/* 2026-09-22, n: ONE request path for both ways of pulling employees in -- the footer's "pull
   selected" and each row's own single-employee button, which hands it an array of one. Split out of
   #btnJoinSelected's own handler rather than copied into the new button (the no-mirror-copy rule),
   so the two can never drift apart on what they do after the reply lands. */
function joinEmployeesRequestRd(employeeIds, $btn) {
    if (!employeeIds.length) return;
    // setButtonLoading() replaces a button's contents with a spinner AND a "Saving..." label -- right
    // for the footer's wide primary, wrong for a 32px `.btn-icon` circle, which has no room for a
    // word and would be blown out of shape by one. A row action just goes disabled while in flight,
    // the same as every other row button in the app.
    const busy = (on) => {
        if ($btn.hasClass('btn-icon')) $btn.prop('disabled', on);
        else setButtonLoading($btn, on);
    };
    busy(true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.join-employees`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_ids: employeeIds }),
        success: function (res) {
            busy(false);
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                return;
            }
            // joinEmployees() silently drops any id that is not a payroll participant and names them
            // back in `skipped_employee_ids` (PayrollRunModel::joinEmployees()). The run really did
            // change, so the modal still closes and the page still reloads either way -- but a plain
            // "saved" toast would hide that part of what was ticked never went in, so the warning
            // REPLACES the success toast rather than stacking on top of it.
            const skipped = (res.skipped_employee_ids || []).length;
            if (skipped > 0) {
                showWarning((langData['sync_missing_pull_skipped'] || '{joined} pulled in, {count} skipped (not paid through payroll).')
                    .replace('{joined}', employeeIds.length - skipped).replace('{count}', skipped));
            } else {
                showSuccess(langData['save_success'] || 'Saved successfully.');
            }
            bootstrap.Modal.getInstance(document.getElementById('joinEmployeesModal')).hide();
            // loadRunDetail() -> renderRunHeader() -> loadSyncMissingEmployeesBanner() already
            // re-counts the banner, so it is NOT called again here.
            loadRunDetail();
        },
        error: function () {
            busy(false);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
}

/* The picker's own display name. Its endpoint already returns name_th/name_en as one concatenated
   string each (PayrollRunModel::manualEmployeeOptions()), so this is not employeeDisplayNameRd()'s
   run-row shape and cannot reuse it. */
function joinEmployeeNameRd(row) {
    return (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '-';
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
                // 2026-09-22, n: the one flag that turns this picker into the sync-missing list.
                // Sent on all 3 of its endpoints (here, all-ids, column-values) so paging, "Select
                // All Matching" and the Excel-style column filters all agree on the same population.
                d.missing_only = joinEmployeesModeIsMissingRd() ? 1 : 0;
                // Built from `settings` (not the outer `tb_join_employees` variable) -- see
                // table-column-filter.js's getColumnFilterValues() docblock for why.
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
        },
        columns: [
            {
                data: null, orderable: false, render: (d, t, row) => {
                    const checked = joinSelectedEmployees[row.id] ? 'checked' : '';
                    return `<input type="checkbox" class="join-emp-checkbox" data-id="${row.id}" data-employee-no="${escapeHtml(row.employee_no)}" ${checked}>`;
                }
            },
            { data: 'employee_no' },
            // 2026-09-22, n: avatar + name, the same line every other employee list in the app shows
            // (apvPersonLineHtml(), app.js) -- in BOTH modes, not just the new one, so the picker
            // does not look like two different tables. `employeeId: null` deliberately: a clickable
            // avatar here would stack the app-wide quick-view modal on top of an open picker, which
            // is a separate decision (see BACKLOG). Object-form render (this app's DataTables
            // sort-safety convention) since display is now HTML -- filter stays the plain name.
            { data: null, render: {
                display: (d, t, row) => apvPersonLineHtml(joinEmployeeNameRd(row), 24, row.profile_photo_path, { employeeId: null }),
                filter: (d, t, row) => joinEmployeeNameRd(row),
            } },
            { data: 'department', render: d => escapeHtml(d || '-') },
            { data: 'team', render: d => escapeHtml(d || '-') },
            { data: 'position', render: d => escapeHtml(d || '-') },
            { data: 'cycle_name', render: d => escapeHtml(d || '-') },
            // Column 7 -- `missing` mode only (hidden by DataTables' own column visibility in `all`
            // mode, not by a second table). One row = one employee this run is missing, so "pull this
            // one in" belongs on the row; the footer button stays for pulling several at once.
            {
                data: null, orderable: false, className: 'text-center',
                visible: joinEmployeesModeIsMissingRd(),
                // Responsive drops columns right-to-left, so this one -- the rightmost -- would be the
                // first to fold into a child row on a phone. An action you have to expand a row to
                // reach is not reachable; it keeps its place at every width.
                responsivePriority: 1,
                render: function (d, t, row) {
                    const label = escapeAttr(langData['action_pull_one'] || 'Pull this employee into the run');
                    return `<button type="button" class="btn-icon btn-icon-ghost join-emp-pull-one" data-id="${row.id}" title="${label}" aria-label="${label}"><i class="fa-solid fa-arrow-right-to-bracket"></i></button>`;
                }
            },
        ],
        order: [],
        searching: false,
        // 2026-08-30, real gap found and fixed (full-codebase pageLength audit) -- was missing
        // entirely, silently falling back to DataTables' own built-in default of 10.
        pageLength: pageLength,
        lengthMenu: lengthMenu,
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
                            missing_only: joinEmployeesModeIsMissingRd() ? 1 : 0,
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
            // 2026-09-22, n: the shared empty state (11), on every draw rather than once at
            // construction -- so it follows the mode AND the language with nothing to invalidate. It
            // no-ops unless the table really is empty, and falls back to the app-wide "narrowed to
            // nothing + clear filter" copy by itself when a filter is what emptied it.
            dtRenderEmptyState(this.api(), joinEmptyStateRd());
        }
    });
}
$(document).on('click', '#btnJoinEmployees', function () {
    openJoinEmployeesModalRd('all');
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
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.manual-employee-all-ids`,
        method: 'POST',
        data: {
            run_id: PAYROLL_RUN_ID,
            department_id: $('#joinFilterDepartment').val() || '',
            team_id: $('#joinFilterTeam').val() || '',
            position_id: $('#joinFilterPosition').val() || '',
            emp_cycle_id: $('#joinFilterCycle').val() || '',
            missing_only: joinEmployeesModeIsMissingRd() ? 1 : 0,
            search: tb_join_employees ? tb_join_employees.search() : '',
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- "Select All Matching" now
            // also honors whatever Excel-style column filters are currently checked, not just the
            // pre-existing department/team/position/cycle dropdowns.
            column_filters: tb_join_employees ? getColumnFilterValues(tb_join_employees) : {}
        },
        dataType: 'json',
        success: function (res) {
            setButtonLoading($btn, false);
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            (res.employee_ids || []).forEach(id => { joinSelectedEmployees[id] = true; });
            updateJoinSelectedCountRd();
            if (tb_join_employees) tb_join_employees.draw(false);
        },
        error: function () {
            setButtonLoading($btn, false);
            showWarning(langData['save_failed'] || 'An error occurred.');
        }
    });
});
$(document).on('click', '#btnJoinSelected', function () {
    joinEmployeesRequestRd(Object.keys(joinSelectedEmployees).map(Number), $(this));
});
// 2026-09-22, n: `missing` mode's per-row button -- the same request as the footer's, with an array
// of one. No confirm step, for the same reason the footer button has never had one: it adds an
// employee to a draft run, and removing them again is one click away in the run's own table.
$(document).on('click', '.join-emp-pull-one', function () {
    joinEmployeesRequestRd([Number($(this).data('id'))], $(this));
});
// 2026-09-01, same-day follow-up (explicit push-back: "เหตุผลอะไรบ้างในหน้า Edit ที่ไม่สามารถแก้ไขได้
// ควรเปิดให้แก้ไขได้") -- the field itself is now ALWAYS editable (never disabled); re-examining
// recalculate()'s own 3 eligibility branches found the real risk is narrower than a blanket
// employee_count===0 lock: switching between two DIFFERENT real cycles, or changing cycle_id on a
// sync-linked run, is exactly as safe as editing period_start/period_end already is (zero gating
// there despite the identical "changes who's eligible next Recalculate" effect) -- ONLY flipping a
// non-sync run between off-cycle (no cycle) and cycle-linked (a real cycle) while it already has
// employees in it risks silently dropping manually-joined ones, since recalculate()'s off-cycle vs.
// cycle-based branches read completely different employee sources. See
// PayrollRunModel::update()'s own matching comment for the full reasoning -- this is a pure client-
// side MIRROR of that same rule, purely to warn before a save that would otherwise be rejected, not
// a gate of its own.
function editRunCycleToggleRiskyRd() {
    if (currentRun.sync_process_id) return false;
    const selectedCycleId = $('#run_cycle_id').val();
    const wasOffCycle = !currentRun.cycle_id;
    const willBeOffCycle = !selectedCycleId;
    return wasOffCycle !== willBeOffCycle && Number(currentRun.employee_count || 0) > 0;
}
// 2026-09-11, Batch 3C item 4 sub-step 4a: kept as Detail-page-only orchestration (unlike every
// other function this sub-step consolidated) -- Create has no equivalent at all, since neither of
// its own two flows (a plain Add, a Pull-sync) ever needs to re-derive "off-cycle or supplemental"
// live off a CHANGING cycle dropdown mid-session; each is populated once from a known starting
// state. Editing an EXISTING run genuinely can change cycle_id while sync_process_id/sync_run_kind
// stay fixed, so this recomputes what setOffCycleMode()/setSupplementalPullMode() (app.js) each
// only ever derive from ONE of the two conditions on their own, plus the risk warning above (also
// Edit-only -- Create has no "already has employees" concept for a run that doesn't exist yet).
// Ids updated to the shared run_* names; syncRunMergeIntoUi()/updateComputeStatutoryVisibility()
// (app.js) replace the deleted syncEditRunMergeIntoUiRd()/updateEditRunTypeVisibility() duplicates.
function updateEditRunTypeSectionRd() {
    const cycleId = $('#run_cycle_id').val();
    const isSupplementalSync = !!currentRun.sync_process_id && (currentRun.sync_run_kind || 'regular') === 'supplemental';
    const offCycle = !cycleId && !currentRun.sync_process_id;
    $('#run_purpose_choice_row').toggleClass('d-none', !offCycle && !isSupplementalSync);
    $('#run_purpose').toggleClass('required', offCycle || isSupplementalSync);
    updateComputeStatutoryVisibility();
    $('#runCycleLockedHint').toggleClass('d-none', !editRunCycleToggleRiskyRd());
    // #run_offcycle_panel (merge-into) only applies to a genuinely off-cycle run (matches
    // PayrollRunModel::update()'s own check: cycle_id===null AND sync_process_id===null) -- a
    // supplemental sync run does NOT qualify, unlike Run Purpose above.
    $('#run_offcycle_panel').toggleClass('d-none', !offCycle);
    if (!offCycle) {
        syncRunMergeIntoUi('standalone');
    }
}
// 2026-09-11, Batch 3C item 4 sub-step 4a: setEditOffCycleMode()/syncEditRunMergeIntoUiRd()/
// syncEditRunPurposeChoiceUiRd()/setEditMergeChoiceMode()/setEditMergeTargetMode() all deleted --
// superseded by app.js's shared setOffCycleMode()/syncRunMergeIntoUi()/syncRunPurposeChoiceUi()/
// setMergeChoiceMode()/setMergeTargetMode() now that ids match. #btnEditRun's own populate code
// below calls these shared functions directly; the editRunScheduleChoice/editRunMergeInto/
// editRunMergeChoice/editRunMergeTargetMode radio names themselves are gone too (merged markup uses
// the Create form's own runScheduleChoice/runMergeInto/runMergeChoice/runMergeTargetMode, already
// bound in app.js).
// 2026-09-11, Batch 3C item 4 sub-step 4a: the merge-target-preview functions (reset/label/render/
// refresh/resolve) and applySuggestedPeriodRd() all deleted -- superseded by app.js's shared
// resetRunMergeTargetPreview()/runMergeTargetPreviewLabel()/renderRunMergeTargetPreview()/
// refreshRunMergeTargetPreview()/resolveRunMergeTargetBeforeSubmit()/applySuggestedPeriod(). The one
// real difference Edit needed (`exclude_id` so a run never lists itself as its own merge target) is
// now handled generically via setRunFormExcludeId()/#run_merge_target_id's own data-exclude-id
// attribute (see app.js's own docblock) instead of a second copy of each function with one extra
// hardcoded ajax key.
//
// Edit-only extra binding alongside app.js's own shared #run_cycle_id change handlers
// (applySuggestedPeriod()) -- reacts live to the admin changing the cycle dropdown DURING an
// already-open Edit session, which Create never needs (see updateEditRunTypeSectionRd()'s own
// docblock above).
$(document).on('change', '#run_cycle_id', updateEditRunTypeSectionRd);
$(document).on('click', '#btnEditRun', function () {
    $('#run_id').val(currentRun.id);
    $('#run_sync_process_id').val(currentRun.sync_process_id || '');
    $('#run_sync_run_kind').val(currentRun.sync_run_kind || '');
    // Must be set before syncRunPurposeChoiceUi()/updateEditRunTypeSectionRd() below trigger updateComputeStatutoryVisibility() (app.js), which reads this field.
    $('#run_attribution_tax_treatment').val(currentRun.sync_attribution_tax_treatment || '');
    $('#run_name').val(currentRun.run_name);
    $('#run_period_start').val(toDisplayDateRd(currentRun.period_start_date));
    $('#run_period_end').val(toDisplayDateRd(currentRun.period_end_date));
    $('#run_payment_date').val(toDisplayDateRd(currentRun.payment_date));
    $('#run_notes').val(currentRun.notes || '');
    // .datepicker('update') after programmatic .val() -- see CLAUDE.md's bootstrap-datepicker note
    // (widget state goes stale otherwise, blanking the field on next click-away).
    $('#run_period_start, #run_period_end, #run_payment_date').datepicker('update');

    // 2026-09-01, explicit request: cycle reference now editable here too (always enabled -- see
    // editRunCycleToggleRiskyRd()'s own docblock for why a blanket disable was loosened).
    const $cycleSel = $('#run_cycle_id');
    if (currentRun.cycle_id) {
        $cycleSel.empty().append(new Option(currentRun.cycle_name || String(currentRun.cycle_id), currentRun.cycle_id, true, true)).trigger('change.select2');
    } else {
        $cycleSel.val(null).trigger('change.select2');
    }

    // 2026-09-02, explicit request: "ยังไม่เหมือนหน้าเพิ่มรอบในหน้า List ครับ ขาด รอบพิเศษนอกรอบเงินเดือน" --
    // the radio is purely a visual affordance mirroring what the cycle field's own emptiness already
    // meant before this existed -- hidden entirely for a sync-linked run (that data is inherently
    // cycle-based/governed by sync_run_kind instead, same reasoning #run_offcycle_row is hidden for
    // a Pull-sync create). setOffCycleMode() (app.js) is the SAME function the Create flow's own
    // radio calls -- ids now match, no separate Edit copy needed.
    const currentlyOffCycle = !currentRun.cycle_id && !currentRun.sync_process_id;
    $('#run_offcycle_row').toggleClass('d-none', !!currentRun.sync_process_id);
    $(currentlyOffCycle ? '#run_schedule_choice_offcycle' : '#run_schedule_choice_cycle').prop('checked', true);
    setOffCycleMode(currentlyOffCycle);

    // 2026-09-01/02, same-day follow-up, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" then "ขาด...
    // เปิดรอบใหม่ อ้างอิงถึงรอบที่มีอยู่ และไม่ติ๊ก Auto" -- populate the merge-target field with the run's
    // current value (or clear it), and never offer this run as its own merge target
    // (setRunFormExcludeId(), app.js). Radio defaults to "new"/unticked unless the run genuinely
    // already has a merge target set -- never pre-ticked as "reference" otherwise, per the explicit
    // "ไม่ติ๊ก Auto" instruction.
    setRunFormExcludeId(currentRun.id);
    const $mergeTargetSel = $('#run_merge_target_id');
    const hasMergeTarget = !!currentRun.merge_target_run_id;
    if (hasMergeTarget) {
        $mergeTargetSel.empty().append(new Option(currentRun.merge_target_run_name || String(currentRun.merge_target_run_id), currentRun.merge_target_run_id, true, true)).trigger('change.select2');
    } else {
        $mergeTargetSel.val(null).trigger('change.select2');
    }
    // 2026-09-06: same populate-on-open treatment for the "future cycle" form -- 'change.select2'
    // (not plain 'change') so opening Edit on an already-waiting run shows its REAL current target
    // cycle/period without re-suggesting/overwriting them (mirrors #run_cycle_id's own reasoning
    // right above).
    const hasFutureCycleTarget = !hasMergeTarget && !!currentRun.merge_target_cycle_id;
    const $mergeTargetCycleSel = $('#run_merge_target_cycle_id');
    if (hasFutureCycleTarget) {
        $mergeTargetCycleSel.empty().append(new Option(currentRun.merge_target_cycle_name || String(currentRun.merge_target_cycle_id), currentRun.merge_target_cycle_id, true, true)).trigger('change.select2');
        $('#run_merge_target_period_start').val(toDisplayDateRd(currentRun.merge_target_period_start_date));
        $('#run_merge_target_period_end').val(toDisplayDateRd(currentRun.merge_target_period_end_date));
        // .datepicker('update') after programmatic .val() -- see CLAUDE.md's bootstrap-datepicker
        // note (widget state goes stale otherwise, blanking the field on next click-away) -- same
        // real bug ("Date เลือกไม่ได้") this pair of fields was fixed for on 2026-09-09.
        $('#run_merge_target_period_start, #run_merge_target_period_end').datepicker('update');
        // 2026-09-09, round-creation flow audit Bug 2 fix -- shows the current match state
        // immediately on open for a run that's already waiting on a future-cycle target, instead of
        // only appearing after the admin touches the cycle/period fields themselves.
        refreshRunMergeTargetPreview();
    } else {
        $mergeTargetCycleSel.val(null).trigger('change.select2');
        $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update');
        resetRunMergeTargetPreview();
    }
    // 2026-09-09, round-creation flow audit Phase 3: single call replaces the old 4-line
    // set-both-legacy-radios-then-call-both-setters sequence -- syncRunMergeIntoUi() (app.js) drives
    // the exact same cascade the Create form's own version already does, ids now shared.
    syncRunMergeIntoUi(hasFutureCycleTarget ? 'future_cycle' : (hasMergeTarget ? 'existing' : 'standalone'));
    updateEditRunTypeSectionRd();

    if (isOffCycleRunRd(currentRun) || (currentRun.sync_process_id && currentRun.sync_run_kind === 'supplemental')) {
        // 2026-09-09, round-creation flow audit Bug 1 fix: reflects the run's CURRENT stored value --
        // deliberately NOT the Create form's own "pre-select incentive"/"pre-check when Origami
        // attributed tax_treatment='separate'" defaults (setSupplementalPullMode()), since those only
        // make sense the FIRST time a choice is ever made; Edit must show what was actually saved
        // (updateEditRunTypeSectionRd() above already made this row/its required state visible for
        // both the off-cycle and supplemental-sync cases -- this just fills in the real values).
        syncRunPurposeChoiceUi(currentRun.run_purpose || 'payroll');
        $('#run_compute_statutory').prop('checked', Number(currentRun.compute_statutory) === 1);
        $('#run_include_base_salary').prop('checked', Number(currentRun.include_base_salary) === 1);
        $('#run_include_standing_items').prop('checked', Number(currentRun.include_standing_items) === 1);
        $('#run_include_attendance_pay').prop('checked', Number(currentRun.include_attendance_pay) === 1);
        $('#run_use_flat_tax_rate').prop('checked', Number(currentRun.use_flat_tax_rate) === 1);
    }
    // 2026-09-11, Batch 3C item 4 sub-step 4b: disable every field runFieldLockState() (app.js) says
    // is locked for THIS run + show the Source row/lock summary -- must run after every value above
    // is already populated (disabling a select2 field before setting its value can leave the wrong
    // option displayed on some browsers), and after #run_id is set (updateComputeStatutoryVisibility()
    // itself already reads it, but applyRunFieldLockUi() also uses it for the Source row).
    applyRunFieldLockUi(currentRun);
    $('.is-invalid').removeClass('is-invalid');
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
// 2026-09-11, Batch 3C item 4 sub-step 4a: the #editRunForm submit handler + submitEditRunForm()
// itself moved to app.js (shared #payrollRunForm submit handler + submitRunForm()) -- this page's
// own post-save reaction (reload Process Detail's own data) listens for the 'payrollRun:saved'
// event that shared function fires on success instead.
$(document).on('payrollRun:saved', function (e, res) {
    showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
    // 2026-09-11, Batch 3C item 4 sub-step 4b (widened at 4d, explicit instruction) -- warn (never
    // auto-recalculate -- see BACKLOG.md's own entry for why update() itself doesn't) when
    // cycle_id/period dates/run_purpose/its 4 calc flags genuinely changed on a still-draft run that
    // already has employee_count>0 -- the exact scenario the hasAdminWork() correction leaves
    // editable without any admin-work lock (all 3 groups share the same tier2 lock, see
    // PayrollRunModel::runFieldLockState()'s own docblock, so they're the same set of fields this
    // warning needs to watch). Compares the JUST-SUBMITTED field values (still sitting in the form's
    // own DOM at this point -- the modal is hidden, not reset, until the next open) against
    // `currentRun`, which is still the PRE-save snapshot here (loadRunDetail() below hasn't
    // refetched it yet).
    if (currentRun && currentRun.state === 'draft' && Number(currentRun.employee_count || 0) > 0) {
        const normId = function (v) { return (v === null || v === undefined || v === '') ? '' : String(v); };
        const submittedCycleId = $('#run_cycle_row').hasClass('d-none') ? '' : normId($('#run_cycle_id').val());
        const cycleChanged = submittedCycleId !== normId(currentRun.cycle_id);
        const periodChanged = toIsoDateRd($('#run_period_start').val()) !== (currentRun.period_start_date || '')
            || toIsoDateRd($('#run_period_end').val()) !== (currentRun.period_end_date || '');
        const submittedPurpose = $('#run_purpose_choice_row').hasClass('d-none') ? 'payroll' : ($('#run_purpose').val() || 'payroll');
        const purposeOrFlagsChanged = submittedPurpose !== (currentRun.run_purpose || 'payroll')
            || (submittedPurpose === 'incentive' && [
                ['compute_statutory', '#run_compute_statutory'],
                ['include_base_salary', '#run_include_base_salary'],
                ['include_standing_items', '#run_include_standing_items'],
                ['include_attendance_pay', '#run_include_attendance_pay'],
            ].some(function (pair) {
                return ($(pair[1]).is(':checked') ? 1 : 0) !== Number(currentRun[pair[0]] || 0);
            }));
        if (cycleChanged || periodChanged || purposeOrFlagsChanged) {
            showWarning(langData['run_recalc_needed_after_calc_change'] || 'You changed how this run is calculated -- click Recalculate to update the numbers.');
        }
    }
    loadRunDetail();
});
$(document).on('click', '#btnSubmitRun', function () {
    const title = langData['confirm_submit_message'] || 'Submit this payroll run for approval? You will not be able to edit amounts until it is sent back or rejected.';
    showConfirm(langData['action_submit'] || 'Submit for Approval', title, function () {
        callRunAction('/api/payroll-run.submit', {});
    });
});
// 2026-08-29, same-day follow-up: "การทำงานในหน้า Detail ถ้าคลิก Tab ไหนแล้ว Refresh ให้ยังคงค้างอยู่ที่ Tab
// นั้น" -- persist the active tab across a refresh via the URL hash (the tab button's own id, e.g.
// "#run-reports-tab"), and double as the deep-link target for Process List's own "export report"
// action (see renderRunActionsPr() in payroll/index.js, which now links straight to
// "payroll-process/{id}#run-reports-tab" instead of downloading in place). history.replaceState (not
// location.hash=...) so switching tabs neither scrolls the page nor spams browser history with one
// entry per click.
$(document).on('shown.bs.tab', '#runDetailTabs button[data-bs-toggle="tab"]', function (e) {
    if (history.replaceState) {
        history.replaceState(null, '', '#' + e.target.id);
    }
});
// 2026-09-09: #tb_run_detail's own tab ("Employee") isn't the default-active tab anymore now that it
// no longer shares "Details" with Run Settings (see app/views/payroll/detail.php's own tab-split
// comment) -- DataTables measures column widths at construction/redraw time, and a table built (or
// last redrawn) while its Bootstrap tab-pane was `display:none` ends up with wrong/collapsed widths
// (a well-known DataTables gotcha, same one this app's own Employment Certificate Requests tab/Payslip
// Distribution already had to guard against elsewhere) -- `.columns.adjust()` recalculates them
// correctly the moment this tab actually becomes visible. Harmless no-op if the table hasn't been
// built yet (run still loading) or if it was already sized correctly.
$(document).on('shown.bs.tab', '#run-employee-tab', function () {
    if (tb_run_detail) tb_run_detail.columns.adjust();
});
// 2026-09-11, Batch 3C item 6: same fix, same reason, for the 4 tables newly converted to
// DataTables (initSharedDataTable(), app.js) -- none of these 4 tabs are the default-active one
// either, so their own table is very likely constructed while still display:none the first time
// loadRunDetail() runs (all 4 load functions fire eagerly together, not on-tab-shown).
$(document).on('shown.bs.tab', '#run-reports-tab', function () {
    if (tb_run_reports_dt) tb_run_reports_dt.columns.adjust();
});
$(document).on('shown.bs.tab', '#run-cash-tab', function () {
    if (tb_run_cash_dt) tb_run_cash_dt.columns.adjust();
});
$(document).on('shown.bs.tab', '#run-bank-account-tab', function () {
    if (tb_run_bank_account_dt) tb_run_bank_account_dt.columns.adjust();
});
$(document).on('shown.bs.tab', '#run-remittance-tab', function () {
    if (tb_run_remittance_dt) tb_run_remittance_dt.columns.adjust();
});
// 2026-09-22, 3e-3 round B1: same fix, same reason, for the Action History tab's own new DataTable
// (initAuditLogTableRd()) -- its pane is not the default-active one either.
$(document).on('shown.bs.tab', '#run-history-tab', function () {
    if (tb_run_audit_log) tb_run_audit_log.columns.adjust();
});
// 2026-09-14, Round 3 "เก็บตกรอบ 6" item 1 -- Payroll Detail's own missing changeLanguage() hook (see
// renderRunHeaderText()'s own docblock, further up this file, for the full root-cause explanation).
// Registered in app.js's changeLanguage() alongside the other ~6 per-page `refreshXxxLanguage()` hooks
// this app already has for this exact bug class.
function refreshPayrollDetailLanguage() {
    if (currentRun) {
        renderRunHeaderText(currentRun);
    }
    // 2026-09-20, 3e-1 round 1, real gap found while measuring c10 in en: the 3 tab tables hand
    // initSharedDataTable() an `emptyState` whose copy is read out of langData ONCE, when the tab
    // loads -- so an empty table kept whichever language was active at first load for the rest of
    // the page's life. Same shape as the `language.emptyTable` string these replaced, i.e. not new,
    // but now it is this page's own hook that can fix it: re-running the 3 loaders rebuilds each
    // table (and its empty state, and its row badges) against the language now in force. Guarded on
    // currentRun because they all read it.
    if (currentRun) {
        loadRunCashTab();
        loadRunBankAccountTab();
        loadRunRemittanceTab();
    }
    // The Comments modal's own title is built from a `{count}` template in JS (see
    // updateEmployeeCommentTitle()), so the generic `data-i18n` sweep can't relabel it -- re-render it
    // here, the same way every other JS-templated string on this page is handled.
    if ($('#employeeCommentModal').hasClass('show')) {
        updateEmployeeCommentTitle();
    }
    // 2026-09-22, n: the Join/Pull picker carries 3 strings the generic sweep cannot own, for 3
    // different reasons: the footer primary's `{count}` template (its `data-i18n` marker is
    // deliberately absent in `missing` mode), the selected-count line (`.text()` on a container that
    // HAD a `data-i18n` span inside it -- the first render replaces it, so nothing is left for the
    // sweep to find, a pre-existing gap this now closes too), and the empty state (rebuilt by the
    // table's own drawCallback, which is why the redraw below is all it needs). Guarded on the
    // table existing at all, i.e. the picker has
    // been opened at least once this page session. The redraw is only worth a round trip while the
    // modal is actually open -- the rows themselves are language-scoped server-side (`lang` is a
    // parameter of manualEmployeeOptions()), so it re-fetches rather than repainting a stale list.
    if (tb_join_employees) {
        updateJoinSelectedCountRd();
        if ($('#joinEmployeesModal').hasClass('show')) {
            tb_join_employees.draw(false);
        }
    }
    // 2026-09-14, Round 3 "เก็บตกรอบ 7" -- the "defensive re-sync" this block used to contain (added
    // 2026-09-14 "เก็บตกรอบ 6", while the real bug below was still unsolved) is REMOVED: it only ever
    // handled 2 shapes -- a `.tcf-header-title` span (the 4 tcf-managed columns), or a `<th>` with
    // ZERO children -- and every one of the 6 still-broken columns (Employee Code/Name/Base Salary/
    // Gross/Deductions/Net Pay) actually had ONE child by the time this ran (DataTables' own
    // `.dt-column-header`/`.dt-column-title` wrapper, built at construction for every `<th>`, no
    // exceptions -- confirmed directly from `node_modules/datatables.net/js/dataTables.js`, not
    // guessed), so this backstop's own `else if` branch silently did nothing for exactly the columns
    // it existed to fix. REAL root cause + fix is in detail.php's own `<thead>` docblock (`data-i18n`
    // now lives on a plain inner `<span>` in every column here, never the `<th>` itself, so app.js's
    // generic `updateText()` sweep -- `$(root).find('[data-i18n]')`, which finds a marker at ANY
    // nesting depth -- updates it correctly no matter how DataTables wraps it) and
    // table-column-filter.js's own initExcelColumnFilters() (now also looks for `data-i18n` on a
    // descendant, not just the `<th>` attribute, so the 4 tcf-managed columns keep working under the
    // same corrected markup). Nothing page-specific is needed to re-sync header TEXT anymore.
    // `columns.adjust()` is the one thing that genuinely still belongs here: header label width can
    // change between th/en (different string lengths), and DataTables only recalculates column widths
    // on an explicit `.adjust()` call, not automatically when a header's text content changes underneath
    // it -- without this, switching language could leave columns visibly misaligned until the next
    // resize/redraw for an unrelated reason.
    if (tb_run_detail) tb_run_detail.columns.adjust();
    // 2026-09-22, 3e-3 round B1: Action History's own DataTable -- header titles, row content
    // (actor name/action label/state badge) and its empty-state title are all language-bound, none
    // of them carry a `data-i18n` the generic sweep could relabel on their own. See
    // refreshAuditLogTableLanguage()'s own docblock.
    refreshAuditLogTableLanguage();
}
// 2026-09-13, §1 follow-up: activateTabFromHash() itself moved to app.js (shared with employee/list.js
// and employee/detail.js's own near-identical versions -- see that function's own docblock) -- the
// call site below is unchanged, since this page never scoped it to a container to begin with.
// 2026-09-10, real bug fix -- was `$(document).ready(function () { loadRunDetail(); ... })` directly,
// which ran before app.js's own `langData` fetch had necessarily resolved (see window.langReady's
// own docblock in app.js). Deferred to `window.langReady.then(...)` so the FIRST render of the run
// header/stepper/badges always has real translated text, not a raw enum fallback that then never
// re-renders. Safe even if this line runs after langReady already resolved (jQuery ready callbacks
// fire in registration order, and app.js's own script tag -- and therefore its ready handler -- is
// always registered first, but `.then()` on an already-settled Promise still fires correctly either
// way, so there is no "attached the handler too late" failure mode here).
$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    loadRunDetail();
    activateTabFromHash();
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
        // 2026-09-11, Batch 3C item 4 sub-step 4a: #run_merge_target_period_start/_end (renamed from
        // #edit_run_merge_target_period_start/_end) init moved to app.js's own shared ready() block
        // along with #run_period_start/_end/#run_payment_date -- #payrollRunModal is shared with the
        // Create flow now, initialized once per page-load there instead of duplicated per page.
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#joinFilterDepartment, #joinFilterTeam, #joinFilterPosition, #joinFilterCycle', { mode: 'ajax' });
        // The pinned last option is this picker's whole "not in the catalog" path, and
        // stripCodePrefix drops the "[CODE] " the catalog endpoint puts in front of every label --
        // see initSelect2()'s own docblocks for both in input.js. Typing a code still finds the row
        // (the endpoint's own WHERE matches item_code as well as both names); it just isn't printed.
        initSelect2('#manualLineItemSelect', {
            mode: 'ajax',
            stripCodePrefix: true,
            pinnedOption: { id: MANUAL_LINE_CUSTOM_OPTION_ID_RD, key: 'manual_line_item_custom_option', fallback: 'Other (enter a name)' },
        });
        // Initialized once here, not per-modal-open (2026-08-21 bug fix precedent from the
        // Attendance Deduction rate_unit dropdown -- re-initializing a select2 field on every open
        // can leave stale state/duplicate options behind).
        // 2026-09-17, tiny-M round 3: the employee picker's own endpoint labels every option
        // "CODE - ชื่อ นามสกุล"; rules.md §5/§6 wants the code as the option's `title`, not printed
        // inline, so the same read-side strip the catalog picker uses takes it off -- 'dash' for this
        // label shape. Searching is untouched: api/employee.report_to.get matches the term against
        // employee_no AND both th/en names in SQL, so typing a code still finds its row.
        initSelect2('#manualLinePayeeEmployee', { mode: 'ajax', allowClear: true, stripCodePrefix: 'dash' });
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 2 -- real bug found and
        // fixed while wiring Phase 6's own equivalent fields into this SAME explicit init list
        // (these two were added to the modal markup but never added here, so the destination
        // picker never actually initialized as a real Select2 -- see CLAUDE.md's own Dropdown
        // convention: nothing auto-scans the page for `.select2-remote`, every field needs its own
        // initSelect2() call somewhere).
        initSelect2('#manualLineDestinationSelect', { mode: 'ajax', allowClear: true });
        initSelect2('#manualLineDestBank', { mode: 'ajax' });
        // Phase 6: run-level recurring-deduction destination override editor (single shared
        // instance reused across every row -- see recurringDest*() functions below).
        // 2026-09-17, tiny-L2: same 'dash' strip as the line form's own payee picker -- the endpoint
        // labels every option "CODE - name" and rules.md 5/6 wants that code as the option's `title`,
        // not printed inline. Searching is untouched (api/employee.report_to.get matches the term
        // against employee_no AND both th/en names in SQL), so typing a code still finds its row.
        initSelect2('#recurringDestPayeeEmployeeSelect', { mode: 'ajax', allowClear: true, stripCodePrefix: 'dash' });
        initSelect2('#recurringDestDestinationSelect', { mode: 'ajax', allowClear: true });
        initSelect2('#recurringDestBank', { mode: 'ajax' });
        // 2026-09-11, Batch 3C item 4 sub-step 4a: #run_cycle_id/#run_merge_target_id (renamed from
        // #edit_run_cycle_id/#edit_run_merge_target_id) init moved to app.js's own shared ready()
        // block -- both fields are shared with the Create flow now, initialized once per page-load
        // there (WITH allowClear:true, which Edit always needed and Create now inherits too) instead
        // of a second copy here.
    }
    });
});
