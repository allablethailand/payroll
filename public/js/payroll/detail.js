let tb_run_detail;
// 2026-09-11, Batch 3C item 6: Reports/Cash Payments/Bank Account Assignment/Third-Party Remittance
// each get their own DataTables instance now (initSharedDataTable(), app.js) -- kept as page-level
// vars, same convention as tb_run_detail above, so each tab's own shown.bs.tab handler can call
// .columns.adjust() on the CURRENT instance (destroy:true reconstructs a new one on every reload).
let tb_run_reports_dt = null;
let tb_run_cash_dt = null;
let tb_run_bank_account_dt = null;
let tb_run_remittance_dt = null;
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
/* ---------- payroll_run_details.calc_errors is a comma-separated list of machine codes (e.g.
   "profile_incomplete, missing_base_salary") -- this translates ONE code to a readable sentence; an
   unrecognized code (defensive) falls back to showing the raw code rather than hiding it.
   profile_incomplete is what a placeholder employee (auto-created via Origami SSO or a Payroll Sync
   pull) shows -- per explicit request these employees are pulled into the table like anyone else
   rather than being silently excluded, so this message is what tells the admin WHY that row still
   needs attention.
   2026-09-16: these sentences no longer live in the table cell itself (2 wrapped lines per row made
   every row a different height) -- the calculation column shows a "N คำเตือน" badge whose popover
   lists them, and the Calculation Breakdown modal shows them in full as callouts. */
function calcErrorMessageRd(code) {
    if (code === 'profile_incomplete') return langData['calc_error_profile_incomplete'] || 'Employee profile is incomplete -- complete it via Employee Detail, then recalculate.';
    if (code === 'missing_base_salary') return langData['calc_error_missing_base_salary'] || 'Missing base salary.';
    if (code === 'no_manual_lines') return langData['calc_error_no_manual_lines'] || 'No payment items added yet -- use "Items" to add one.';
    if (code === 'daily_salary_no_shift_pattern') return langData['calc_error_daily_salary_no_shift_pattern'] || 'This salary type is paid per day/week/period but no Shift is assigned -- paid for every non-holiday day; assign a Shift to exclude weekly off-days.';
    // 2026-08-31, real hourly formula now exists (was previously flagged unsupported and
    // silently used the monthly formula) -- salary_type_hourly_not_supported itself is retired
    // going forward but kept translatable here in case an older, already-calculated run still
    // carries it in its preserved calc_errors.
    if (code === 'salary_type_hourly_not_supported') return langData['calc_error_salary_type_hourly_not_supported'] || 'Hourly salary type was not yet supported when this was calculated -- used the monthly formula instead. Recalculate to use the real hourly formula.';
    if (code === 'hourly_salary_no_attendance_data') return langData['calc_error_hourly_salary_no_attendance_data'] || 'Hourly salary type but no attendance data (clock in/out) was found for this employee this period -- paid 0 for base salary; verify attendance has been recorded/synced.';
    if (code === 'sync_actual_days_no_data') return langData['calc_error_sync_actual_days_no_data'] || 'Base Salary Basis is "Actual Days (Origami Sync)" but no PROBATION_WORKING_DAYS was available for this employee this cycle -- paid in full instead.';
    if (code === 'no_attendance_data_this_period') return langData['calc_error_no_attendance_data_this_period'] || 'No attendance/OT/leave data found for this employee this period -- verify Origami sync has completed, or confirm this is expected.';
    if (code === 'ot_not_calculated_ineligible') return langData['calc_error_ot_not_calculated_ineligible'] || 'This employee is not marked eligible for OT -- Origami sent OT hours this period, but they were NOT calculated. Verify with the employee/HR whether this is correct.';
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
    // 2026-09-02, advisory-only (never blocks submit -- see PayrollRunModel::recalculate()'s
    // own $blockingErrors filter). Origami confirmed this can never fire from a genuine sync
    // payload (working_days/working_mins share the same umbrella selection flag as Late/Absent,
    // never independently 0) -- it fires in practice for a Manual Entry/Import-driven cycle run,
    // where there's genuinely no scheduled working-day count available at all (see
    // TransactionDataPayAdapter's own docblock). See SyncPayResolver::resolve()'s own 2026-09-02
    // docblock for the full reasoning.
    if (code.indexOf('working_days_fallback_with_attendance_deduction:') === 0) {
        const eventLabel = code.substring('working_days_fallback_with_attendance_deduction:'.length);
        const tpl = langData['calc_error_working_days_fallback_with_attendance_deduction'] || 'The {event} deduction this period was computed using the fixed 30-day standard divisor (no real scheduled working-day count was available for this period) -- this may under- or over-deduct compared to the period\'s actual working days. Review this amount.';
        return tpl.replace('{event}', eventLabel);
    }
    // 2026-09-02, explicit request: "การตั้งค่าเงินรวมกันถ้าเกินจำนวนเงินเดือนมีการดักส่วนนี้ไว้ไหม" --
    // PayrollRunModel::recalculate() now checks a Mixed-payment employee's FULL line set
    // (cash+transfer+check together) against this row's own net pay the moment it's known,
    // instead of the mismatch only ever surfacing later as a silently-skipped row inside an
    // exported Bank Transfer/Cash Payment file. Advisory only (never blocks submit -- same
    // exclusion-list treatment as daily_salary_no_shift_pattern above).
    if (code === 'mixed_payment_lines_mismatch') return langData['calc_error_mixed_payment_lines_mismatch'] || "This employee's Mixed payment lines don't add up to their net pay -- check the Payment tab on Employee Detail.";
    return code;
}
// 2026-09-16: the server already splits calc_errors into calc_warnings/calc_blocking
// (PayrollRunModel::splitCalcErrors(), one advisory list shared with recalculate()) -- these 2 just
// translate whichever list they are handed. Nothing here decides advisory-vs-blocking anymore.
function calcErrorMessagesRd(codes) {
    return (codes || []).map(calcErrorMessageRd);
}
// 2026-09-16, explicit instruction ("คอลัมน์การคำนวณ = statusBadge สถานะ + badge 'N คำเตือน' tone
// warning ไม่มีไอคอน คลิกเปิด popover รายการบรรทัดละข้อ"): advisory notes leave the cell. The badge is
// countBadgeHtml()'s own markup with the count wrapped in the sentence (§5: a non-neutral tone only
// when the number itself needs attention -- a warning is exactly that), and the list opens in the
// app's shared popover (initPopovers(), app.js/§11 -- token-styled, closes on Esc/click-outside,
// one open at a time) rather than the column-filter panel: that panel is a single app-wide instance
// built around a checklist + Clear/Apply footer, nothing of which this read-only list needs.
function calcWarningBadgeRd(row) {
    const messages = calcErrorMessagesRd(row.calc_warnings);
    if (!messages.length) return '';
    const badge = countBadgeHtml(messages.length, { tone: 'warning', label: langData['calc_warning_count'] || '{n} warnings' });
    const content = `<ul class="rd-calc-warning-list">${messages.map(m => `<li>${escapeHtml(m)}</li>`).join('')}</ul>`;
    return `<button type="button" class="btn btn-link p-0 border-0 ms-1 align-baseline rd-calc-warning-btn"
        data-bs-toggle="popover" data-bs-trigger="click" data-bs-html="true" data-bs-placement="left"
        data-bs-title="${escapeAttr(langData['calc_warnings_title'] || 'Warnings')}"
        data-bs-content="${escapeAttr(content)}">${badge}</button>`;
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
// (.btn-view-breakdown/.btn-view-emp-adjustments/.btn-raw-sync-data/.btn-manage-manual-lines' own
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
const RD_REPORT_TILE_BY_TYPE = {
    statutory: { bg: 'rd-report-tile-orange', icon: 'fa-landmark' },
    payment: { bg: 'rd-report-tile-purple', icon: 'fa-money-check-dollar' },
    internal: { bg: 'rd-report-tile-green', icon: 'fa-file-lines' },
};
function rdReportIconTileHtml(row) {
    const tile = RD_REPORT_TILE_BY_TYPE[row.report_type] || RD_REPORT_TILE_BY_TYPE.internal;
    return `<span class="rd-report-tile ${tile.bg} me-2"><i class="fa-solid ${tile.icon}"></i></span>`;
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
        tb_run_reports_dt = initSharedDataTable('#tb_run_reports', {
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
                                <button type="button" class="btn btn-link btn-circle-action text-primary btn-report-preview" data-code="${row.code}" ${disabledAttr} title="${rowIsReady ? (langData['report_preview_and_download'] || 'Preview & Download') : notReadyTitle}"><i class="fa-solid fa-download"></i></button>
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
            dtOptions: { language: { emptyTable: langData['no_cash_payments'] || 'No cash-paying employees in this run.' } },
            renderRows: function () {
                $('#runCashTableBody').html(cashRows.map(row => {
                    const name = escapeHtml((currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`).trim());
                    const isPaid = row.status === 'paid';
                    const badge = isPaid
                        ? `<span class="badge bg-success-subtle text-success">${langData['status_paid'] || 'Paid'}</span>`
                        : `<span class="badge bg-secondary-subtle text-secondary">${langData['status_unpaid'] || 'Unpaid'}</span>`;
                    const paidByName = currentLang === 'th' ? row.paid_by_name_th : row.paid_by_name_en;
                    const paidAtCell = isPaid ? `${formatDisplayDateTime(row.paid_at)}${paidByName ? `<div class="text-muted small">${escapeHtml(paidByName)}</div>` : ''}` : '-';
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group (and its own
                    // former .btn-sm, redundant now that .btn-circle-action sets a fixed 32x32 size).
                    const actionBtn = isPaid
                        ? `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-cash-mark-unpaid" data-id="${row.id}" title="${langData['mark_as_unpaid'] || 'Mark as Unpaid'}"><i class="fa-solid fa-rotate-left"></i></button>`
                        : `<button type="button" class="btn btn-link btn-circle-action text-success btn-cash-mark-paid" data-id="${row.id}" title="${langData['mark_as_paid'] || 'Mark as Paid'}"><i class="fa-solid fa-check"></i></button>`;
                    return `<tr>
                        <td>${escapeHtml(row.employee_no)}</td>
                        <td>${name}</td>
                        <td class="text-end" data-order="${Number(row.amount) || 0}">${fmtNum(row.amount)}</td>
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
            dtOptions: { language: { emptyTable: langData['bank_account_no_employees'] || 'No bank-paying employees in this run.' } },
            renderRows: function () {
                $('#runBankAccountTableBody').html(rdBankAccountRows.map(row => {
                    const name = escapeHtml((currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`).trim());
                    const bankName = currentLang === 'th' ? row.bank_name_th : row.bank_name_en;
                    const accountCell = row.bank_account_id
                        ? escapeHtml(`${bankName || ''} - ${row.bank_account_name || ''}`)
                        : `<span class="text-danger">${langData['bank_account_unassigned'] || 'No account configured'}</span>`;
                    const sourceBadgeClass = row.is_overridden ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary';
                    const sourceLabel = langData[RD_BANK_ACCOUNT_SOURCE_LABEL_KEY[row.source]] || row.source;
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group.
                    let actionBtns = `<button type="button" class="btn btn-link btn-circle-action text-primary btn-bank-account-edit" data-employee-id="${row.employee_id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>`;
                    if (row.is_overridden) {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-bank-account-remove" data-employee-id="${row.employee_id}" title="${langData['bank_account_remove_override'] || 'Remove Override'}"><i class="fa-solid fa-rotate-left"></i></button>`;
                    }
                    return `<tr>
                        <td>${escapeHtml(row.employee_no)}</td>
                        <td>${name}</td>
                        <td>${accountCell}</td>
                        <td class="text-center"><span class="badge ${sourceBadgeClass}">${sourceLabel}</span></td>
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
const RD_REMITTANCE_STATUS_BADGE = {
    pending: 'bg-warning-subtle text-warning',
    transferred: 'bg-primary-subtle text-primary',
    success: 'bg-success-subtle text-success',
    failed: 'bg-danger-subtle text-danger',
};
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
            dtOptions: { language: { emptyTable: langData['no_remittances'] || 'No third-party remittances for this run.' } },
            renderRows: function () {
                $('#runRemittanceTableBody').html(rdRemittanceRows.map(row => {
                    const badgeClass = RD_REMITTANCE_STATUS_BADGE[row.status] || 'bg-secondary-subtle text-secondary';
                    const badge = `<span class="badge ${badgeClass}">${langData[`remittance_status_${row.status}`] || row.status}</span>`;
                    const failedNote = row.status === 'failed' && row.note ? `<div class="text-danger small">${escapeHtml(row.note)}</div>` : '';
                    const transferredAtCell = row.transferred_at ? formatDisplayDateTime(row.transferred_at) : '-';
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group.
                    let actionBtns = `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-remittance-breakdown" data-id="${row.id}" title="${langData['remittance_view_breakdown'] || 'View Breakdown'}"><i class="fa-solid fa-list"></i></button>`;
                    if (row.status === 'pending') {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-primary btn-remittance-mark-transferred" data-id="${row.id}" title="${langData['mark_as_transferred'] || 'Mark as Transferred'}"><i class="fa-solid fa-paper-plane"></i></button>`;
                    } else if (row.status === 'transferred') {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-success btn-remittance-confirm-success" data-id="${row.id}" title="${langData['remittance_confirm_success'] || 'Confirm Success'}"><i class="fa-solid fa-circle-check"></i></button>`;
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-danger btn-remittance-mark-failed" data-id="${row.id}" title="${langData['mark_as_failed'] || 'Mark as Failed'}"><i class="fa-solid fa-circle-xmark"></i></button>`;
                    } else if (row.status === 'failed') {
                        actionBtns += `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-remittance-retry" data-id="${row.id}" title="${langData['retry'] || 'Retry'}"><i class="fa-solid fa-rotate-left"></i></button>`;
                    }
                    return `<tr>
                        <td>${escapeHtml(rdRemittanceDestinationLabel(row))}</td>
                        <td>${escapeHtml(rdRemittanceDestinationTypeLabel(row.destination_type))}</td>
                        <td class="text-center">${Number(row.employee_count) || 0}</td>
                        <td class="text-end" data-order="${Number(row.total_amount) || 0}">${fmtNum(row.total_amount)}</td>
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
                <td class="text-end">${fmtNum(item.amount)}</td>
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
        $('#mergeTargetWaitingBanner').toggleClass('alert-warning', !cycleInactive).toggleClass('alert-danger', cycleInactive);
        $('#mergeTargetWaitingBanner i').toggleClass('fa-hourglass-half', !cycleInactive).toggleClass('fa-triangle-exclamation', cycleInactive);
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
            $('#syncMissingEmployeesBanner').removeClass('d-none').data('list', list);
        },
        error: function () {
            $('#syncMissingEmployeesBanner').addClass('d-none');
        },
    });
}
$(document).on('click', '#syncMissingEmployeesViewBtn', function () {
    const list = $('#syncMissingEmployeesBanner').data('list') || [];
    const listHtml = list.map(e => `<li class="text-start">${escapeHtml(e.employee_no)} — ${escapeHtml((currentLang === 'th' ? `${e.name_th} ${e.surname_th}` : `${e.name_en} ${e.surname_en}`).trim())}</li>`).join('');
    Swal.fire({
        title: langData['sync_missing_employees_title'] || 'Not in This Sync',
        html: `<ul class="ps-3 mb-0">${listHtml}</ul>`,
        icon: 'warning',
        confirmButtonText: langData['close'] || 'Close',
    });
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
    const excluded = itemOptions.filter(item => excludedCodes.includes(item.item_code));
    const chipClass = item => item.item_type === 'base_salary' ? 'text-bg-warning-subtle text-warning-emphasis'
        : item.item_type === 'earning' ? 'text-bg-success-subtle text-success' : 'text-bg-danger-subtle text-danger';
    return `<div>${excluded.map(item => `<span class="badge rounded-pill ${chipClass(item)} me-1 mb-1">${escapeHtml((currentLang === 'th' ? item.item_name_th : item.item_name_en) || item.item_code)}</span>`).join('')}</div>`;
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
// Action History section (auditHistoryRowHtmlRd() below), which is now the ONLY place this run's
// action history renders anywhere in the app.
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

// "Items" (manage per-employee earning/deduction adjustment lines): available on ANY draft run
// now (2026-08-19, explicit request) -- not just an Incentive/Other Payment run. For incentive
// these lines are the only source of pay; for any other run they're an additive one-off adjustment
// on top of the normal calculation (see PayrollRunModel::recalculate()'s manual-lines block, added
// to the non-incentive branch alongside standing PED assignments/attendance bonus).
// 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "row action: โชว์ 3 ปุ่มวงกลม [ดูรายละเอียด
// การคำนวณ] [ความคิดเห็น (count)] [ปรับรายการ] + ⋮ สำหรับที่เหลือ" -- was a dropdown-item; now its own
// standalone .btn-circle-action circle (one of the row's 3, draft-only so effectively 2 outside draft
// -- §7 revised to "≤3 ปุ่ม + ⋮", not always exactly 3). Click handler (.btn-manage-manual-lines)
// unchanged.
// 2026-09-17, D3: the count badge is NOT on this button any more -- it moved to the slip button
// (viewBreakdownButtonRd) because that is now where the adjustments it counts are made. The icon is
// `fa-sliders` (settings), matching what this modal is called now ("ตั้งค่ารายบุคคล"): with the
// "รายการจ่าย"/"ปรับตัวเลข" tabs gone it holds per-employee SETTINGS for this run, not its figures.
function manageItemsButtonRd(row) {
    if (!currentRun || currentRun.state !== 'draft') {
        return '';
    }
    return `<button type="button" class="btn btn-link btn-circle-action text-primary btn-manage-manual-lines" data-employee-id="${row.employee_id}" title="${langData['action_manage_items'] || 'Per-employee settings'}"><i class="fa-solid fa-sliders"></i></button>`;
}
// Raw Sync Data viewer (2026-08-21, explicit request: "ถ้าเป็นการ Sync ข้อมูลมาจาก Origami...เพิ่มปุ่ม
// ดูข้อมูลดิบได้") -- only for a row that actually came from the sync payload; a manually-added
// employee on the same sync-based run (row.data_source='manual') has no sync row to show.
function rawSyncDataButtonRd(row) {
    if (!currentRun || !currentRun.sync_process_id || row.data_source !== 'sync') {
        return '';
    }
    return `<li><button type="button" class="dropdown-item btn-raw-sync-data" data-employee-id="${row.employee_id}"><i class="fa-solid fa-file-code text-secondary me-2"></i>${langData['action_raw_sync_data'] || 'Raw Sync Data'}</button></li>`;
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
    return `<li><button type="button" class="dropdown-item text-danger btn-remove-manual-employee" data-employee-id="${row.employee_id}"><i class="fa-solid fa-trash-can me-2"></i>${langData['action_remove'] || 'Remove'}</button></li>`;
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
// Available on any draft run only (same gating as manageItemsButtonRd()/removeEmployeeButtonRd());
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
// Available on any draft run only (same gating as manageItemsButtonRd()/removeEmployeeButtonRd()); the
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
        <button type="button" class="btn btn-link btn-circle-action text-info btn-view-breakdown" data-employee-id="${row.employee_id}" title="${label}" aria-label="${escapeAttr(label)}"><i class="fa-solid fa-receipt"></i></button>
        ${countBadge}
    </div>`;
}
// §7, revised this round: "≤ 3 ปุ่ม + ⋮" (was "≤2 ปุ่ม inline, >2 พับเป็น ⋮ ทั้งหมด") -- the 3 circles
// above (View Breakdown/Comments/ตั้งค่ารายบุคคล, "≤3" since the settings circle is draft-only so a
// non-draft row shows only 2) always stay inline; everything else (Raw Sync Data, conditional;
// Remove, draft-only) collapses into the ⋮ menu. Unverify is NOT part of this menu anymore -- see
// the Verify column's own badge dropdown instead (verifyLockButtonsRd()).
// 2026-09-16: the read-only "รายการที่ปรับ" viewer (empAdjustmentsModal) used to be reachable only
// through the Employee Code cell's own "ปรับแล้ว N" button, which that instruction removed -- moved
// here so it is still reachable on a non-draft run too, where there is no editing surface at all.
// Same .btn-view-emp-adjustments class, same delegated handler.
function viewAdjustmentsMenuItemRd(row) {
    if (Number(row.adjustment_count || 0) <= 0) {
        return '';
    }
    // ไอคอนเป็น child ตัวแรกของ .dropdown-item ตาม rules.md §6 (CSS กลางบังคับกว้าง 16px จัดกลาง ให้
    // ข้อความทุกแถวในเมนูเดียวกันเริ่มที่ x เดียวกัน) -- glyph เดียวกับหัว modal ที่มันเปิด (§0.5)
    return `<li><button type="button" class="dropdown-item btn-view-emp-adjustments" data-employee-id="${row.employee_id}"><i class="fa-solid fa-pen-to-square text-secondary me-2"></i>${escapeHtml(langData['emp_adjustments_modal_title'] || 'Adjusted Items')}</button></li>`;
}
function runDetailActionsRd(row) {
    const circles = [viewBreakdownButtonRd(row), commentButtonRd(row), manageItemsButtonRd(row)].filter(Boolean).join('');
    const menuItems = [viewAdjustmentsMenuItemRd(row), rawSyncDataButtonRd(row)].filter(Boolean);
    const removeItem = removeEmployeeButtonRd(row);
    // 2026-09-10, explicit request: Remove sits at the bottom with a divider above it, only when
    // there's actually something above it to divide from.
    const divider = (menuItems.length && removeItem) ? '<li><hr class="dropdown-divider"></li>' : '';
    const items = menuItems.join('') + divider + removeItem;
    const menu = items
        ? `<div class="dropdown">
            <button type="button" class="btn btn-link btn-circle-action text-secondary dropdown-toggle" data-bs-toggle="dropdown" title="${langData['action_more'] || 'More'}"><i class="fa-solid fa-ellipsis-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">${items}</ul>
        </div>`
        : '';
    return `<div class="d-flex gap-1 align-items-center justify-content-center flex-nowrap">${circles}${menu}</div>`;
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
function formulaButtonRd(line) {
    const amount = line.amount !== undefined ? line.amount : line.employee_amount;
    const stepsHtml = buildFormulaStepsRd(line.formula);
    let content;
    if (stepsHtml) {
        const title = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || line.code || '';
        content = `<div><strong>${escapeHtml(title)}</strong></div>
            <ol class="ps-3 mb-0 mt-1 small">${stepsHtml}</ol>
            ${formulaResultLineRd(amount)}`;
    } else {
        content = explainLineNoteRd(line.note, amount);
    }
    if (!content) return '';
    const contentAttr = content.replace(/"/g, '&quot;');
    // 2026-09-14, Round 3 item 3c-2 follow-up, explicit instruction: "? ใช้ .btn-icon-ghost 14px
    // --c-text-faint" -- restyled from the old `btn btn-sm btn-link text-brand` (a colored, orange
    // "?" that competed with §0's "1 primary action" rule and §3's "ไอคอน...ห้ามใช้[สี]" for a plain
    // info affordance) to the app's own ghost-icon-button language, sized down for sitting inline in
    // table-row text rather than as a standalone 32px row-action circle (`.btn-icon`/
    // `.btn-icon-ghost`'s own base size) -- `.formula-info-btn` (style.css) supplies the 14px
    // icon/--c-text-faint/small hit-area, `.btn-icon-ghost` supplies the shared transparent-until-
    // hover background behavior so it still reads as the same family of icon control app-wide.
    // 2026-09-14, same follow-up, explicit instruction: shared popover behavior (close on Esc/click-
    // outside/only-1-open-at-a-time, ✕ in the header) -- see initPopovers() (app.js) for all of that,
    // wired centrally, not here. `data-bs-trigger` dropped from "hover click" to plain "click" --
    // hover-to-open doesn't compose with "closes on click outside": a hover-opened popover has no
    // stable notion of "outside" the moment the mouse leaves, so it would just reopen on the next
    // mouse pass, fighting the click-outside/Esc/✕ close affordances this same instruction asked for.
    return `<button type="button" class="btn-icon-ghost formula-info-btn" data-bs-toggle="popover" data-bs-trigger="click" data-bs-html="true" data-bs-placement="top" data-bs-title="${langData['formula_popover_title'] || 'How this was calculated'}" data-bs-content="${contentAttr}"><i class="fa-solid fa-circle-question"></i></button>`;
}
/* ---------- Breakdown modal (section 2/3's table doesn't itemize -- it only shows totals): per-
   employee itemized view split into clearly-labeled Earnings / Deductions (Items) / Deductions
   (Statutory) sections, so which line is income vs. a deduction is never ambiguous. ---------- */
function breakdownLineRowsRd(lines, moneyColorCls) {
    return (lines || []).map(line => {
        const name = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || '';
        // 2026-09-15: the note is the line's own quiet second row -- no icon, not italic, one line
        // with the full text as a native tooltip (`.payslip-line-note`, shared with the adjustments
        // slip's own rows, style.css).
        const commentHtml = line.note ? `<div class="payslip-line-note" title="${escapeAttr(line.note)}">${escapeHtml(line.note)}</div>` : '';
        // 2026-08-30, explicit request: "มีหมายเหตุในกรณีที่ไม่หัก ในการกดดูของพนักงานด้วยในหน้า Process
        // Detail" -- SyncPayResolver still emits a LINE (amount forced to 0) for an attendance
        // deduction this employee is exempt from, rather than dropping it silently, so there's
        // something here to explain instead of the item just quietly not appearing. Distinct from
        // the generic `commentHtml` above (which shows the raw technical `note` string) -- this is a
        // dedicated, human-readable remark keyed off `is_exempted`/`exempted_amount`.
        const exemptedHtml = line.is_exempted
            ? `<div class="small text-warning-emphasis mt-1"><i class="fa-solid fa-user-shield me-1"></i>${(langData['attendance_deduction_exempted_remark'] || 'Exempted from this deduction -- would have been {amount}').replace('{amount}', fmtNum(line.exempted_amount))}</div>`
            : '';
        // Transfer-to-payee (2026-08-21): a 'transfer_in' earning line gets its own badge (not the
        // generic "Custom" one, even though it's technically is_custom too) so it reads distinctly
        // as money credited from another employee, not an ad-hoc typed-in item. A deduction line
        // that FEEDS a transfer instead shows a "-> employee_no" tag alongside its normal code.
        // 2026-09-14, Round 3 item 3c-2 follow-up, explicit instruction: "ซ่อนรหัสรายการ...ย้ายไป title
        // tooltip" -- a PLAIN code (BASE/TH_SSO/...) is no longer printed inline at all; it only ever
        // shows as the name's native `title` attribute (hover tooltip), never visible text. The 3
        // BADGE variants (Transfer/Custom/Other) are NOT "a code" in the same sense -- they're a
        // meaningful visual classification of the line itself, not an internal identifier -- so those
        // stay exactly as visible as before, unaffected by this change.
        // 2026-09-15, rules.md 9: the source badge is a real `statusBadgeHtml()` against the
        // `manual_line_mode` context (neutral + outline, no icon -- the 3 hand-rolled
        // `badge bg-info-subtle`/`bg-secondary-subtle` variants with their own icons are gone, and
        // with them 3 more hits of 12's lint rule 8). It now sits AFTER the name, so every row's
        // name starts at the same x no matter which badge (or none) the row carries.
        // 2026-09-17, R1b: a plain typed-in line ("Custom") carries NO badge any more -- with the form
        // down to one picker there is exactly one way to type a name, so the badge classified nothing
        // the reader could act on. `other` keeps its badge because it is genuinely different: those
        // rows are the retired mode, still bucketed into "Other Income/Deduction" on reports, and the
        // UI can no longer produce another one. A custom line gets no `title` either -- its `code` is
        // the synthetic `CUSTOM:{name}`, which is the name it is already showing.
        let codeHtml = '';
        let nameTitleAttr = '';
        if (line.source === 'transfer_in') {
            codeHtml = statusBadgeHtml('transfer', 'manual_line_mode', { outline: true });
        } else if (line.is_custom) {
            // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7.
            if (line.is_other) codeHtml = statusBadgeHtml('other', 'manual_line_mode', { outline: true });
        } else {
            nameTitleAttr = ` title="${escapeAttr(line.code || '-')}"`;
        }
        // 2026-08-31, same-day follow-up: payee_type widened to 'company'/'not_disbursed' too --
        // same branching as manualLineListItemHtml()'s own payeeHtml.
        let payeeHtml = '';
        if (line.payee_type === 'employee' && line.payee_employee_id) {
            payeeHtml = `<div class="small text-muted"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtml(line.payee_employee_no || ('#' + line.payee_employee_id))}</div>`;
        } else if (!line.payee_type && line.payee_employee_id) {
            // Backward-compat: a row saved before payee_type existed only ever meant 'employee'.
            payeeHtml = `<div class="small text-muted"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtml(line.payee_employee_no || ('#' + line.payee_employee_id))}</div>`;
        } else if (line.payee_type === 'company') {
            // 2026-09-10, Batch 3B item 3: this line shape has no resolved bank_account_name (that
            // JOIN only exists in dedicated per-table listing queries, not the persisted breakdown
            // JSON itself, same limitation this branch's own 'other_person' comment already notes
            // for destination_account_name) -- shows a warning instead of a silent generic label
            // whenever bank_account_id is genuinely unspecified.
            payeeHtml = line.bank_account_id
                ? `<div class="small text-muted"><i class="fa-solid fa-building me-1"></i>${langData['payee_dest_retained'] || 'Retained by company'}</div>`
                : `<div class="small text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['payee_bank_account_needs_review'] || 'Company Account -- bank account not specified, needs review'}</div>`;
        } else if (line.payee_type === 'other_person') {
            // 2026-09-02, Deduction Destination & Third-Party Remittance -- this line shape has no
            // resolved destination_account_name (that LEFT JOIN only exists in
            // manualLinesForEmployee()'s own dedicated query, not the persisted breakdown JSON), so
            // a generic label is shown here, same "no specific detail" treatment 'company' already gets.
            payeeHtml = `<div class="small text-muted"><i class="fa-solid fa-building-columns me-1"></i>${langData['payee_dest_external'] || 'Transfer to an external person or organization'}</div>`;
        } else if (line.payee_type === 'not_disbursed') {
            payeeHtml = `<div class="small text-muted"><i class="fa-solid fa-ban me-1"></i>${langData['payee_type_not_disbursed'] || 'Not Disbursed'}</div>`;
        }
        const exemptBadge = line.is_exempted ? `<span class="badge bg-warning-subtle text-warning-emphasis ms-1">${langData['attendance_deduction_exempted_badge'] || 'Exempted'}</span>` : '';
        return `<tr class="payslip-row${line.is_exempted ? ' text-muted' : ''}">
            <td>
                <div class="payslip-line-head"><span class="payslip-line-name"${nameTitleAttr}>${escapeHtml(name)}</span>${codeHtml}${exemptBadge}${formulaButtonRd(line)}</div>
                ${commentHtml}${exemptedHtml}${payeeHtml}</td>
            <td class="text-end num ${moneyColorCls || ''}">${fmtNum(line.amount)}</td>
        </tr>`;
    }).join('');
}
// 2026-09-14, Round 3 item 3c-2 follow-up, explicit instruction: "รายการที่พนักงานไม่ได้ลงทะเบียน/
// บริษัทปิดใช้...ไม่แสดงแถวเลย" -- StatutoryCalculationEngine::calculateLine() (app/services/
// StatutoryCalculationEngine.php) writes these 2 EXACT note codes ('disabled'/'employee_not_enrolled',
// confirmed by reading the engine source directly) when the item doesn't apply to this employee AT
// ALL -- a genuinely different case from an item that DOES apply and simply computed to ฿0 (e.g. a
// 0%-bracket PIT line), which the engine leaves with note=null/some OTHER note and must keep
// showing per the same instruction ("มีสิทธิ์แต่ยอด 0.00 → แสดงปกติ"). 'employee_tax_exempt' added
// here too (not explicitly named in the instruction) -- same early-return branch in the engine as
// employee_not_enrolled (an employee this tax plainly doesn't apply to), so excluded on the same
// "not entitled" basis, not a guess. Every OTHER note the engine can emit
// (no_rate_configured/no_brackets_configured/unknown_calc_method/unrecognized_calc_base) means a
// MISCONFIGURED item, not a not-entitled one -- deliberately still shown (hiding a config problem
// would be worse than a raw note). No separate `is_enrolled`-style boolean exists on this line shape
// to filter on instead -- these 3 string codes are the only signal the engine gives, so filtering by
// them (not by amount == 0, which would wrongly also hide the legitimate zero-but-entitled case) is
// the correct rule here, not a shortcut.
const STATUTORY_NOT_ENTITLED_NOTES_RD = ['disabled', 'employee_not_enrolled', 'employee_tax_exempt'];
function statutoryRowsRd(items) {
    // 2026-08-21, real bug fix (explicit report: "แสดงแค่ Code อยากให้มีชื่อด้วย") -- name_th/
    // name_en now come through from StatutoryCalculationEngine::calculateLine(), same pattern as
    // breakdownLineRowsRd() already uses for earning/deduction lines just above.
    return (items || [])
        .filter(item => !STATUTORY_NOT_ENTITLED_NOTES_RD.includes(item.note))
        .map(item => {
            const name = (currentLang === 'th' ? item.name_th : item.name_en) || item.name_th || item.name_en || '';
            // 2026-09-14, real bug found and fixed (explicit report, screenshot showed raw
            // "(employee_not_enrolled)"/"(th_pit_average_annual_tax_2050)" next to the item name) --
            // `item.note` is an internal code, never end-user prose (StatutoryCalculationEngine's own
            // source confirms this -- see the const above). It was rendered here as a raw parenthetical
            // with no i18n lookup at all, completely bypassing explainLineNoteRd() (which ALREADY
            // translates the known codes, e.g. 'employee_not_enrolled' -> langData['formula_note_not_
            // enrolled'], and the th_pit_* pattern into real prose) purely because formulaButtonRd()
            // just below happens to call that translator for its OWN popover content. Dropped entirely
            // -- the "?" button is now the only place a note ever surfaces, translated when a mapping
            // exists, silently absent (no button, no raw text) when it doesn't, never a raw code shown
            // to the employee either way. Logged to BACKLOG.md: the unmapped-code case still means an
            // employee sees no explanation at all for that line (out of scope to fully fix here, §0.7).
            // 2026-09-14, same-day follow-up, explicit instruction: "ซ่อนรหัสรายการ...ย้ายไป title
            // tooltip" -- code moved from a visible <code> chip to the name's own `title` attribute.
            return `<tr class="payslip-row">
                <td><span title="${escapeAttr(item.code || '-')}">${escapeHtml(name)}</span>${formulaButtonRd(item)}</td>
                <td class="text-end num money-deduction">${fmtNum(item.employee_amount)}</td>
            </tr>`;
        }).join('');
}
// Shared "-" placeholder row for an EMPTY-but-always-shown payslip column (Earnings/Deductions --
// §9 payslip layout keeps both columns visible for a fixed, symmetric 2-column grid; only the
// wholly-optional Statutory block below them is hidden outright when empty, see renderBreakdownModal()).
function payslipEmptyRowRd() {
    return `<tr class="payslip-row"><td colspan="2" class="text-center text-muted small">-</td></tr>`;
}

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
    $('#breakdownHeaderCard').html(employeeHeaderCardHtml(row));
    renderBreakdownStatusLineRd(row);
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
    $('#breakdownCalcNotes').html(
        calcErrorMessagesRd(row.calc_blocking).map(m => calloutHtml(escapeHtml(m), 'danger')).join('')
        + calcErrorMessagesRd(row.calc_warnings).map(m => calloutHtml(escapeHtml(m), 'warning')).join('')
    );
    const $totalDays = $('#breakdownTotalDays');
    if (row.total_days !== null && row.total_days !== undefined) {
        $totalDays.text(`${langData['total_days'] || 'Total Days'}: ${fmtNum(row.total_days)}`).removeClass('d-none');
    } else {
        $totalDays.addClass('d-none').text('');
    }

    // A row that cannot be edited renders exactly the slip it always did -- same function, same
    // output, byte for byte (tests/breakdown_slip_render_test.js pins it). Only an editable row gets
    // the other layout.
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
    $('#breakdownModalBody').html(breakdownViewSlipHtml(row));
    // 2026-09-14, centralized -- initPopovers() (app.js) now owns per-element init (dispose-then-
    // create, same idempotent pattern this file used to do inline here) AND the shared close-on-Esc/
    // click-outside/single-open-at-a-time/✕ behavior, wired once globally the first time it's called
    // anywhere in the app. Scoped to this modal's own body so re-rendering for a different employee
    // doesn't touch popovers elsewhere on the page.
    if (typeof initPopovers === 'function') initPopovers('#breakdownModalBody');
}
// 2026-09-17, D3: this modal's footer, built per open through the shared helper (§9/§4). A row that
// cannot be edited gets [ปิด] alone -- byte for byte what app.js's own `data-footer="view"` fallback
// used to inject before this modal owned a footer element. An editable row additionally gets §9's
// LEFT slot: "คืนค่าระบบทั้งหมด" (the one action that is neither the way out nor a save, since
// every edit in this modal already writes immediately) plus the sequential-restore progress line,
// both kept away from [ปิด] on the right. Same id/handler/keys it had in the settings modal.
function renderBreakdownFooterRd(canEdit) {
    $('#breakdownModalFooter').html(modalFooterButtonsHtml({
        left: canEdit ? { id: 'btnRestoreAllComputedLineOverrides', key: 'line_override_restore_all_computed', fallback: 'Restore all calculated values' } : null,
        leftHtml: canEdit ? '<span class="text-muted" id="lineOverrideSaveProgress"></span>' : '',
        secondary: { key: 'close', fallback: 'Close', dismiss: true },
    }));
    refreshBreakdownFooterStateRd();
}
// Enabled only when there is something to restore: at least one row really carries an override. That
// is a different question from "has anything been typed", which is why it counts rows rather than
// reading a dirty flag.
function refreshBreakdownFooterStateRd() {
    const $btn = $('#btnRestoreAllComputedLineOverrides');
    if (!$btn.length) return;
    const overrideRowCount = lineOverrideMountRd().find('.lo-row').filter(function () {
        return !!($(this).data('orig-action') || '') && !$(this).find('.lo-include').is(':disabled');
    }).length;
    $btn.prop('disabled', overrideRowCount === 0);
}
// The read-only slip, unchanged -- this is the exact body renderBreakdownModal() built inline before
// the editable layout existed, moved as-is so the 2 sit beside each other instead of nested.
function breakdownViewSlipHtml(row) {
    let earningRowsHtml = '';
    if (Number(row.base_salary_amount) > 0) {
        earningRowsHtml += `<tr class="payslip-row">
            <td><span title="BASE">${escapeHtml(langData['table_base_salary'] || 'Base Salary')}</span></td>
            <td class="text-end num money-gross">${fmtNum(row.base_salary_amount)}</td>
        </tr>`;
    }
    earningRowsHtml += breakdownLineRowsRd(row.earning_breakdown, 'money-gross');
    // 2026-09-14, same-day follow-up: Deductions is now ONE merged column (statutory + item/manual
    // rows, separated by a subheader only when both groups are present) -- payslipViewHtml() (app.js)
    // owns that grouping decision now, so these 2 stay separate strings here instead of being
    // concatenated/emptiness-padded in this file the way the single old "Deductions (Items)" column
    // used to be.
    const deductionItemRowsHtml = breakdownLineRowsRd(row.deduction_breakdown, 'money-deduction');
    const deductionStatutoryRowsHtml = statutoryRowsRd(row.statutory_breakdown);

    // 2026-09-14, Round 3 item 3c-2: renders via the shared payslip-view component (payslipViewHtml(),
    // app.js -- PHP twin app/views/partials/payslip-view.php) instead of this file's own
    // breakdownSectionHtml() (removed, no longer used anywhere), so this modal and a future
    // print/PDF payslip page share one layout. row.total_deduction_amount already combines item +
    // statutory deductions (PayrollRunModel::recalculate(), confirmed) -- no extra sum needed here.
    return payslipViewHtml({
        earningRowsHtml: earningRowsHtml || payslipEmptyRowRd(),
        deductionStatutoryRowsHtml: deductionStatutoryRowsHtml,
        deductionItemRowsHtml: deductionItemRowsHtml,
        grossAmount: row.gross_amount,
        totalDeductionAmount: row.total_deduction_amount,
        netAmount: row.net_amount
    });
}
/* ---------- Calculation Breakdown modal, EDITABLE layout (2026-09-16, D1 "สลิปที่แก้ได้").
   A row that can still be edited (draft run, not verified) is edited HERE, where its figures are
   read: the line-override table (render, switch, inline edit, history dropdown/modal, hidden rows,
   busy lock -- see its own section further down) and the hand-added lines. 2026-09-17, D3: this is
   the only place either one lives now; the 2 tabs they were built in are gone. Everything else is
   read-only and renders exactly the slip it always did. ---------- */
// The gate: manageItemsButtonRd()'s own condition (draft run) plus this row's verify lock. Both are
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
// The slot under the employee header card: one line of status, or nothing. Only the verified-draft
// case has anything to say -- unverifying is a real action the user can take, so naming it beats the
// editing surface simply being absent. Past draft there is no action to point at, so the slot stays
// empty (rules.md §9).
function renderBreakdownStatusLineRd(row) {
    const $slot = $('#breakdownStatusLine');
    if (!!currentRun && currentRun.state === 'draft' && row.is_verified) {
        $slot.html(`<span class="breakdown-status-text">${escapeHtml(langData['breakdown_verified_lock_hint'] || 'Verified -- unverify before editing')}</span>`).removeClass('d-none');
        return;
    }
    $slot.empty().addClass('d-none');
}
// The add-an-item button on a column head -- one per column, which is what decides the new line's
// type: the form never asks "earning or deduction?" because the head that was pressed already said.
// Disabled (reason in its own tooltip) rather than hidden when this employee's figures are frozen --
// the column head then reads the same as always and names what has to happen first, instead of
// quietly missing a control that was there a moment ago.
function manualLineAddButtonHtml(itemType, canEdit) {
    const labelKey = itemType === 'deduction' ? 'manual_line_add_deduction' : 'manual_line_add_earning';
    const label = canEdit
        ? (langData[labelKey] || (itemType === 'deduction' ? 'Add a deduction item' : 'Add an income item'))
        : (langData['manual_line_add_locked'] || 'Items can only be added while the run is a draft and this employee is not verified');
    return `<button type="button" class="btn-icon manual-line-add-btn" data-item-type="${escapeAttr(itemType)}"${canEdit ? '' : ' disabled'} title="${escapeAttr(label)}"><i class="fa-solid fa-plus"></i></button>`;
}
// Section 2: the lines somebody added by hand, in the slip's own 2-column layout so they read as the
// same kind of thing as the calculated ones above. Rows come from manualLineListItemHtml(). No
// totals here: the one figure that matters is the run's own net pay, which section 3 carries.
// An empty column of the block, as a row of its own table so it sits in the same grid the real rows
// do (§7: nothing gets `display` set on a cell). The shared empty state's `inline` variant -- no
// icon, no button: what to do next is the + already in this column's own title.
function manualLineEmptyColumnHtml() {
    const text = langData['manual_line_empty_hint'] || 'No items yet -- press + to add one';
    return `<tr class="payslip-row manual-line-empty-row"><td colspan="2">${emptyStateHtml({ inline: true, text: text })}</td></tr>`;
}
function renderBreakdownManualLinesRd(lines) {
    const all = lines || [];
    const canEdit = employeeRowEditableRd(breakdownRowRd);
    const earningLines = all.filter(l => l.item_type === 'earning');
    const deductionLines = all.filter(l => l.item_type === 'deduction');
    // 2026-09-17, R1: "เงินเพิ่ม"/"เงินหัก" -- what a hand-added line IS, which is not the same word as
    // the slip's own "เงินได้"/"รายการหัก" (those name the whole calculation above). An empty column still
    // renders its head and its + button: that is where adding one starts.
    $('#breakdownManualLines').html(payslipViewHtml({
        earningTitle: langData['manual_line_col_earning'] || 'Additional pay',
        deductionTitle: langData['manual_line_col_deduction'] || 'Additional deduction',
        earningTitleActionHtml: manualLineAddButtonHtml('earning', canEdit),
        deductionTitleActionHtml: manualLineAddButtonHtml('deduction', canEdit),
        earningRowsHtml: earningLines.length
            ? earningLines.map(l => manualLineListItemHtml(l, canEdit)).join('')
            : manualLineEmptyColumnHtml(),
        deductionItemRowsHtml: deductionLines.length
            ? deductionLines.map(l => manualLineListItemHtml(l, canEdit)).join('')
            : manualLineEmptyColumnHtml(),
        showTotals: false,
    }));
}
function loadBreakdownManualLinesRd(employeeId) {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.manual-lines`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: employeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            renderBreakdownManualLinesRd(res.data || []);
        }
    });
}
// Section 3: the run's own net pay for this employee, through the same component band the read-only
// slip ends with. Re-read from api/payroll-run.get after every write, because a single override
// changes what the statutory lines compute to and therefore this figure.
// 2026-09-17, R1: 3 lines, not 1 -- gross and deductions in front of the net band, so the figure at
// the bottom can be read as the result of the 2 above it instead of arriving on its own. All 3 come
// from the SAME row `api/payroll-run.get` returns and refreshBreakdownNetSummaryRd() already
// re-reads after every write; nothing is added up on the client.
function renderBreakdownNetSummaryRd(row) {
    $('#breakdownNetSummary').html(payslipNetSummaryHtml(row.net_amount, null, {
        grossAmount: row.gross_amount,
        totalDeductionAmount: row.total_deduction_amount,
    }));
}
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
            renderBreakdownNetSummaryRd(fresh);
        }
    });
}
function renderBreakdownEditableBodyRd(row) {
    // 2026-09-17, R1: the calculated table has no heading of its own -- it is what this modal is,
    // and its own column heads already say what each column holds. A heading over the first thing
    // under the employee card only repeats the modal's title. The section below still needs one:
    // it is a DIFFERENT kind of line (someone typed it in) and has to say so.
    $('#breakdownModalBody').html(`<div class="breakdown-edit">
        <section class="breakdown-edit-section">
            <div id="breakdownLineOverrideWrap" class="lo-mount"></div>
        </section>
        <section class="breakdown-edit-section">
            <h6 class="breakdown-edit-title">${escapeHtml(langData['breakdown_group_extra_items'] || 'Additional items')}</h6>
            <div id="breakdownManualLines" class="ml-mount"></div>
        </section>
        <div id="breakdownNetSummary"></div>
    </div>`);
    renderBreakdownNetSummaryRd(row);
    renderBreakdownManualLinesRd([]);
    // One host, one employee, one reload path -- see setLineOverrideHostRd()'s own docblock.
    setLineOverrideHostRd('#breakdownLineOverrideWrap', row.employee_id, refreshBreakdownNetSummaryRd);
    loadSyncLineOverridesRd();
    loadBreakdownManualLinesRd(row.employee_id);
}
$(document).on('click', '.btn-view-breakdown', function () {
    const employeeId = $(this).data('employee-id');
    const rowData = runDetailRowByEmployeeId(employeeId);
    if (!rowData) return;
    renderBreakdownModal(rowData);
    new bootstrap.Modal(document.getElementById('runDetailBreakdownModal')).show();
});

/* ---------- Employee Adjustments viewer (2026-09-10, Batch 3A item 5, explicit request: replace
   the fa-sliders icon with a "ปรับแล้ว N" badge + view-only modal listing item/old value/new
   value/who/when) -- sourced entirely from PayrollRunModel::employeeAdjustments() (overrides via
   the same table getDetails()'s own line_override_count counts, enriched with the real edit-chain
   history when one exists; ad-hoc added items via manualLinesForEmployee()) -- no new table, no
   editing here (Manage Items/Sync Line Overrides above remain the only editing surface). ---------- */
function empAdjustmentLineTypeLabelRd(lineType) {
    if (lineType === 'statutory') return langData['sync_line_statutory_badge'] || 'Statutory';
    return langData['breakdown_earnings'] || 'Earning/Deduction';
}
function empAdjustmentOverrideRowHtml(item, historyStartDate) {
    const edits = item.edits || [];
    const lastEdit = edits.length ? edits[edits.length - 1] : null;
    const who = lastEdit
        ? ((currentLang === 'th' ? lastEdit.changed_by_name_th : lastEdit.changed_by_name_en) || lastEdit.changed_by_name_th || lastEdit.changed_by_name_en || '')
        : ((currentLang === 'th' ? item.fallback_changed_by_name_th : item.fallback_changed_by_name_en) || item.fallback_changed_by_name_th || item.fallback_changed_by_name_en || '');
    const when = lastEdit ? lastEdit.changed_at : item.fallback_changed_at;
    const newValueDisplay = item.action === 'exclude'
        ? `<span class="text-danger">${langData['sync_line_override_excluded_badge'] || 'Excluded'}</span>`
        : fmtNum(item.current_value);
    const noHistoryNote = !item.history_available
        ? `<div class="small text-muted mt-1"><i class="fa-solid fa-circle-info me-1"></i>${(langData['emp_adjustments_no_history'] || 'No detailed edit history available (tracking started {date}).').replace('{date}', historyStartDate ? formatDisplayDate(historyStartDate) : '')}</div>`
        : '';
    return `<div class="border rounded-3 p-2 mb-2">
        <div><code class="fw-bold text-dark">${escapeHtml(item.item_code)}</code>
            <span class="badge bg-info-subtle text-info ms-1">${escapeHtml(empAdjustmentLineTypeLabelRd(item.line_type))}</span></div>
        <div class="row small mt-2 gx-2">
            <div class="col-4"><span class="text-muted">${langData['run_audit_original'] || 'Original'}:</span> ${item.original_value !== null ? fmtNum(item.original_value) : '-'}</div>
            <div class="col-4"><span class="text-muted">${langData['run_audit_current'] || 'Current'}:</span> ${newValueDisplay}</div>
            <div class="col-4"><span class="text-muted">${langData['downloaded_by'] || 'By'}:</span> ${who ? escapeHtml(who) : '-'}</div>
        </div>
        <div class="small text-muted mt-1">${when ? formatDisplayDateTime(when) : ''}</div>
        ${item.note ? `<div class="small text-muted mt-1"><i class="fa-solid fa-note-sticky me-1"></i>${escapeHtml(item.note)}</div>` : ''}
        ${noHistoryNote}
    </div>`;
}
function empAdjustmentManualLineRowHtml(item) {
    const name = (currentLang === 'th' ? item.item_name_th : item.item_name_en) || item.item_name_th || item.item_name_en || item.custom_item_name || item.item_code;
    const who = (currentLang === 'th' ? item.created_by_name_th : item.created_by_name_en) || item.created_by_name_th || item.created_by_name_en || '';
    return `<div class="border rounded-3 p-2 mb-2">
        <div><span class="badge bg-success-subtle text-success me-1">${langData['emp_adjustments_added_badge'] || 'Added'}</span>${escapeHtml(name || '')}</div>
        <div class="row small mt-2 gx-2">
            <div class="col-4"><span class="text-muted">${langData['run_audit_current'] || 'Current'}:</span> ${fmtNum(item.amount)}</div>
            <div class="col-4"><span class="text-muted">${langData['downloaded_by'] || 'By'}:</span> ${who ? escapeHtml(who) : '-'}</div>
            <div class="col-4">${item.created_at ? formatDisplayDateTime(item.created_at) : ''}</div>
        </div>
        ${item.note ? `<div class="small text-muted mt-1"><i class="fa-solid fa-note-sticky me-1"></i>${escapeHtml(item.note)}</div>` : ''}
    </div>`;
}
function loadEmpAdjustmentsModal(employeeId) {
    const emptyHtml = `<div class="text-center text-muted small py-2">${langData['emp_adjustments_empty'] || 'None.'}</div>`;
    $('#empAdjustmentsOverrideList, #empAdjustmentsManualLineList').html(emptyHtml);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.employee-adjustments`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: employeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['load_failed'] || 'Failed to load data.'); return; }
            const overrides = res.data.overrides || [];
            const manualLines = res.data.manual_lines || [];
            const historyStartDate = res.data.history_feature_start_date || null;
            $('#empAdjustmentsOverrideList').html(overrides.length ? overrides.map(item => empAdjustmentOverrideRowHtml(item, historyStartDate)).join('') : emptyHtml);
            $('#empAdjustmentsManualLineList').html(manualLines.length ? manualLines.map(empAdjustmentManualLineRowHtml).join('') : emptyHtml);
        },
        error: function () { showWarning(langData['load_failed'] || 'An error occurred while loading data.'); }
    });
}
$(document).on('click', '.btn-view-emp-adjustments', function () {
    const employeeId = $(this).data('employee-id');
    const rowData = runDetailRowByEmployeeId(employeeId);
    // 2026-09-11, Batch 3C item 8: employeeHeaderCardHtml() (app.js) block first, no more employee
    // name in the modal-header (#empAdjustmentsEmployeeName removed from the view).
    $('#empAdjustmentsHeaderCard').html(rowData ? employeeHeaderCardHtml(rowData) : '');
    loadEmpAdjustmentsModal(employeeId);
    new bootstrap.Modal(document.getElementById('empAdjustmentsModal')).show();
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
    const noShiftNote = !breakdown.has_shift_pattern
        ? `<div class="small text-warning mt-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['working_days_breakdown_no_shift'] || 'No shift assigned -- every non-holiday day counted as a working day.'}</div>`
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
function renderRawSyncDataModal(data) {
    const sectionsHtml = RAW_SYNC_DATA_SECTIONS_RD.map(section => {
        if (rawSyncDataSectionIsEmpty(section, data)) {
            return '';
        }
        const fieldsHtml = section.fields.map(key => {
            const f = rawSyncDataFieldLookupRd(key);
            if (!f) return '';
            return `<div class="col-sm-6">
                <div class="text-muted small">${langData[f.labelKey] || f.fallback}</div>
                <div class="fw-semibold">${rawSyncDataValueDisplay(data[f.key])}</div>
            </div>`;
        }).join('');
        const breakdownHtml = section.titleKey === 'raw_sync_data_section_attendance' ? workingDaysBreakdownHtml(data.working_days_breakdown) : '';
        return `<div class="col-md-6">
            <div class="ped-type-panel border rounded-3 p-3 h-100">
                <h6 class="text-secondary fw-bold mb-2"><i class="fa-solid ${section.icon} me-1"></i>${langData[section.titleKey] || section.fallback}</h6>
                <div class="row g-2">${fieldsHtml}</div>
                ${breakdownHtml}
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
// 2026-08-29: the tax/SSO exemption card that used to live in THIS modal moved to the universal
// Manage Items modal's own "Tax & SSO" tab (see manageLinesCalcPane) -- this viewer is read-only
// again, matching its original single purpose (a sync-only row's raw Origami payload).
$(document).on('click', '.btn-raw-sync-data', function () {
    rawSyncDataEmployeeId = $(this).data('employee-id');
    const rowData = runDetailRowByEmployeeId(rawSyncDataEmployeeId);
    // 2026-09-11, Batch 3C item 8: employeeHeaderCardHtml() (app.js) block first, no more employee
    // name in the modal-header (#rawSyncDataEmployeeName removed from the view).
    $('#rawSyncDataHeaderCard').html(rowData ? employeeHeaderCardHtml(rowData) : '');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.raw-sync-data-for-employee`,
        method: 'GET',
        data: { run_id: PAYROLL_RUN_ID, employee_id: rawSyncDataEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['load_employee_failed'] || 'Failed to load data.'); return; }
            renderRawSyncDataModal(res.data);
            new bootstrap.Modal(document.getElementById('rawSyncDataModal')).show();
        },
        error: function () { showWarning(langData['load_employee_failed'] || 'Failed to load data.'); }
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
            // is now the count badge on the row's own Items circle, manageItemsButtonRd(), and the
            // viewer it opened moved to the row's ⋮ menu so it stays reachable on a non-draft run)
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
            { data: 'calc_status', render: {
                display: (d, t, row) => `${statusBadgeHtml(d, 'payroll_calc_status')}${calcWarningBadgeRd(row)}`,
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
            const warningRowCount = visibleRows.filter(r => (r.calc_warnings || []).length > 0).length;
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
   elsewhere in this file (verifyLockButtonsRd(), manageItemsButtonRd(), removeEmployeeButtonRd()) --
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
// app. Swal.fire() called directly (not showConfirm(), which hardcodes Yes/No) for the same reason
// as the single-employee .btn-verify-employee handler above -- still the one central SweetAlert2
// confirm modal, just with a real action label on the confirm button instead of "OK".
function bulkVerifyLockRd(url, payload, confirmTitle, confirmMessage, confirmButtonText) {
    const employeeIds = selectedRunDetailEmployeeIds();
    if (!employeeIds.length) return;
    Swal.fire({
        icon: 'info',
        title: confirmTitle.replace('{count}', employeeIds.length),
        text: confirmMessage,
        showCancelButton: true,
        confirmButtonText: confirmButtonText || (langData.yes || 'Yes'),
        cancelButtonText: langData['cancel'] || 'Cancel'
    }).then(function (result) {
        if (!result.isConfirmed) return;
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
// action (not a generic "OK") -- Swal.fire() called directly rather than through showConfirm() since
// showConfirm()'s own confirmButtonText is hardcoded to Yes/No, and the whole point here is a
// specific action label on that button. Still the SAME central SweetAlert2 confirm modal
// showConfirm() itself wraps, per the "ใช้ modal confirm กลางของระบบ" instruction -- same pattern
// already used elsewhere in this app whenever a confirm needs a custom confirm button label (e.g.
// employee/detail.js's #btnSuspendEmployee).
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
    Swal.fire({
        icon: 'info',
        title,
        text: message,
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText: langData['cancel'] || 'Cancel'
    }).then(function (result) {
        if (!result.isConfirmed) return;
        singleVerifyLockRd('/api/payroll-run.employee-verify.save', employeeId, { verified: nowVerified }, 'save_success');
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

// 2026-09-10: moved to app.js as auditActionLabel() -- shared with index.js/approval.js's own
// Timeline modals so all 3 pages can never drift out of sync on action-code wording again.
// 2026-08-27, explicit request: "ในหน้า Process Detail Tab Action History ปรับจากตารางเป็น Timeline
// สวยๆ" -- reuses the SAME `.apv-stage` circular-marker/connector-line component this page's own
// Timeline modal/status card already builds with (app.js's own apvIconHtml()/apvBadgeHtml()/
// apvCreatedStageHtml()).
// 2026-09-11, Batch 3C item 1: the Timeline modal's own condensed "History" section
// (renderAuditTimelineRd()) is gone entirely now -- this tab is the ONLY place this run's action
// history renders anywhere in the app, not just the "fuller" rendering of a summary that duplicated
// it elsewhere.
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
    reopen: { tone: 'pending', icon: 'fa-unlock' },
    line_override_save: { tone: 'pending', icon: 'fa-sliders' },
    line_override_remove: { tone: 'muted', icon: 'fa-rotate-left' },
    run_settings_save: { tone: 'pending', icon: 'fa-sliders' },
};
function auditTimelineMetaRd(action) {
    return AUDIT_TIMELINE_META_RD[action] || { tone: 'muted', icon: 'fa-pen' };
}
// 2026-08-29, explicit follow-up request: "ปรับ Action History ให้เป็น Timeline แบบเดิมดูดีกว่าครับ แต่เพิ่ม
// ให้กดดู Detail ได้ ช่วย Design ให้สวยๆ" -- reverted the same-day boustrophedon/snake grid redesign
// right back to a single vertical spine (the earlier round's own explicit ask, now un-asked-for) --
// KEEPING the one genuinely new thing that round added: the "View Detail" button + modal (the
// original vertical version before ANY of this showed everything inline in the row itself). Restyled
// beyond a plain revert though ("Design ให้สวยๆ"): each stage is now a real card (white background,
// soft shadow, hover lift) instead of bare icon+text sitting directly on the tab's own background,
// and the connector line/icon markers got a bit more visual weight to read as a proper timeline
// spine at a glance. auditHistoryEntries still holds the CURRENTLY rendered, newest-first-ordered
// array so the detail modal can look an entry up by its plain index.
let auditHistoryEntries = [];
// 2026-08-29, same-day follow-up: "หน้า ประวัติการดำเนินการ Detail ไม่เยอะไม่ต้องมีปุ่มกดดูก็ได้ครับ แสดงใน
// timeline ได้เลย" -- the "View Detail" button + #auditHistoryDetailModal round trip is gone; every
// field that modal used to show (state change badges, note/remark, IP/user-agent) is now rendered
// directly in the card itself, since there's rarely enough audit history on one run to make an
// always-expanded card feel cluttered.
function auditHistoryRowHtmlRd(entry, index, isLast) {
    const meta = auditTimelineMetaRd(entry.action);
    const color = (APV_COLORS[meta.tone] || APV_COLORS.muted).icon;
    // 2026-09-11, Batch 3C item 2, explicit instruction: "ชื่อผู้ทำ -> apvPersonLineHtml (รูป + ชื่อ,
    // คลิก quick-view ได้) แบบเดียวกับไทม์ไลน์" -- same size (26) the Approval Timeline modal's own
    // Created/Paid/Locked stages use (app.js's apvCreatedStageHtml() etc.), same {employeeId} option
    // that wires up the shared .emp-avatar-link click handler.
    const actorName = personDisplayNameRd(entry, 'performed_by');
    const actorHtml = apvPersonLineHtml(actorName, 26, entry.performed_by_profile_photo_path, entry.performed_by ? { employeeId: entry.performed_by } : null);
    // 2026-09-11, Batch 3C item 2, explicit instruction: "from_state -> to_state ถ้าเท่ากัน แสดงครั้ง
    // เดียว ไม่ใช่ 'กำลังทำรอบ  กำลังทำรอบ'" -- an action that doesn't actually change state (e.g. a
    // comment/note logged mid-state) used to always render the arrow-transition shape even when both
    // sides were identical.
    let stateChangeHtml = '';
    if (entry.from_state && entry.to_state && entry.from_state !== entry.to_state) {
        stateChangeHtml = `${stateBadgeRd(entry.from_state)} <i class="fa-solid fa-arrow-right mx-1"></i> ${stateBadgeRd(entry.to_state)}`;
    } else if (entry.to_state) {
        stateChangeHtml = stateBadgeRd(entry.to_state);
    } else if (entry.from_state) {
        stateChangeHtml = stateBadgeRd(entry.from_state);
    }
    // 2026-09-11, Batch 3C item 2, explicit instruction: raw User-Agent parsed into a compact
    // "Windows 10 · Edge 152" summary (app.js's formatUserAgentSummary(), OS · main browser + major
    // version only) with the RAW string kept in a tooltip (title attribute), not shown inline
    // anymore -- IP address moves to its own line right below it, instead of sharing one line.
    const metaLines = [];
    if (entry.user_agent) {
        const uaSummary = formatUserAgentSummary(entry.user_agent) || entry.user_agent;
        metaLines.push(`<div title="${escapeAttr(entry.user_agent)}"><i class="fa-solid fa-desktop me-1"></i>${escapeHtml(uaSummary)}</div>`);
    }
    if (entry.ip_address) {
        metaLines.push(`<div><i class="fa-solid fa-location-dot me-1"></i>${escapeHtml(entry.ip_address)}</div>`);
    }
    return `
        <div class="apv-history-row${isLast ? ' apv-history-row-last' : ''}">
            <div class="apv-history-row-marker">
                <div class="apv-history-row-icon" style="background:${color};"><i class="fa-solid ${meta.icon}"></i></div>
                ${isLast ? '' : '<div class="apv-history-row-line"></div>'}
            </div>
            <div class="apv-history-row-card">
                <div class="apv-history-row-top">
                    <span class="apv-history-row-title">${escapeHtml(auditActionLabel(entry.action))}</span>
                    <span class="apv-history-row-date"><i class="fa-regular fa-clock me-1"></i>${escapeHtml(formatDisplayDateTime(entry.performed_at))}</span>
                </div>
                <div class="apv-history-row-actor">${actorHtml}</div>
                ${stateChangeHtml ? `<div class="mt-2">${stateChangeHtml}</div>` : ''}
                ${entry.note ? `<div class="apv-substep-remark mt-2">${escapeHtml(entry.note)}</div>` : ''}
                ${metaLines.length ? `<div class="small text-muted mt-2">${metaLines.join('')}</div>` : ''}
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
        auditHistoryEntries = [];
        return;
    }
    auditHistoryEntries = logs.slice().reverse(); // newest first at the top, oldest at the bottom -- same ordering convention this tab already had
    $('#run_audit_timeline').html(auditHistoryEntries.map((entry, i) => auditHistoryRowHtmlRd(entry, i, i === auditHistoryEntries.length - 1)).join(''));
}

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
/* ---------- Manage Payment Items modal: per-employee earning/deduction lines, add one at a time,
   remove any individually. Split into two panels (Earnings/Deductions, same visual language as
   section 2's item-selection panels) with running subtotals + a net-adjustment total, rather than
   one flat mixed table -- makes it immediately obvious what's earning vs. deduction and what the
   combined effect is, without needing to close the modal and check the outer table. ---------- */
let manageLinesEmployeeId = null;
// 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- distinct "Other" badge,
// same reasoning as eedItemNameCell()'s own update in employee/detail.js.
// 2026-09-15, batch 2/4: the badge is a real `statusBadgeHtml()` call (§5) against the new
// `manual_line_mode` context in status_map.php -- replaces this function's own hardcoded
// `badge bg-info-subtle`/`bg-secondary-subtle` markup (which also failed §12's lint rule 8). A
// catalog-picked line gets NO badge at all, just its item code as quiet text, exactly as the
// instruction describes ("badge โหมด ... เฉพาะที่ไม่ใช่ เลือกจากรายการ").
// 2026-09-16: a catalog line's plain item_code is no longer printed beside the name -- it is an
// internal identifier, and the name already says what the row is. It survives as the name's own
// `title` (see manualLineListItemHtml()), the same place breakdownLineRowsRd() moved its codes to.
// 2026-09-17, R1b: down to ONE badge, the retired `other` kind -- see breakdownLineRowsRd()'s own
// comment for why a plain typed-in ("Custom") line no longer carries one.
function manualLineTagHtml(line) {
    if (!line.is_other) {
        return '';
    }
    return statusBadgeHtml('other', 'manual_line_mode', { outline: true });
}
// One line of the "added by hand" block -- a `.payslip-row` `<tr>` in the SAME shape the real payslip
// component renders (name cell + right-aligned `.num.money-*` amount), so both live under
// payslipViewHtml() (§9) with no second layout. 2 things are specific to these rows and live in the
// name/amount cells rather than in the component: the mode badge above, and the row's own actions.
// 2026-09-16, D2: the actions are the shared 32px round buttons (§7's `.btn-icon`) at the END of the
// row, shown always instead of on hover -- an affordance that has to be discovered by hovering is
// not one (the same conclusion rules.md §6 reached for the comment list's own row actions). Pressing
// the row anywhere else opens the same form its pencil does.
// `canEdit` is the answer for the whole block (employeeRowEditableRd(), asked once by the caller),
// never per row -- and a row with no `id` of its own (a line stored before manual lines became
// addressable) keeps the slot but gets no buttons, so the figures around it stay on one line.
function manualLineListItemHtml(line, canEdit) {
    const name = (currentLang === 'th' ? line.item_name_th : line.item_name_en) || line.item_name_th || line.item_name_en;
    const moneyCls = line.item_type === 'earning' ? 'money-gross' : 'money-deduction';
    // Long notes clip to one line with the full text as a native tooltip (explicit instruction) --
    // `.payslip-line-note` owns the ellipsis (style.css), shared with the real payslip's own rows.
    const noteHtml = line.note
        ? `<div class="payslip-line-note" title="${escapeAttr(line.note)}">${escapeHtml(line.note)}</div>`
        : '';
    // 2026-08-31, same-day follow-up: payee_type widened to 'company'/'not_disbursed' too (was
    // 'employee' transfer only) -- same branching as Employee Detail's own eedItemNameCell().
    let payeeHtml = '';
    if (line.payee_type === 'employee' && line.payee_employee_id) {
        // 2026-09-17, tiny-M round 3: the person's NAME, in the language on screen -- the row used to
        // print the bare employee_no, which is the same internal code §5/§6 keeps out of a label.
        // Same read-side strip the picker itself uses, so the two never disagree on what to show.
        payeeHtml = `<div class="manual-line-payee">${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtml(manualLinePayeeNameRd(line))}</div>`;
    } else if (line.payee_type === 'company') {
        // 2026-09-10, Batch 3B item 3: manualLinesForEmployee() joins bank_account_name for this
        // exact display -- the real account, or a "needs review" warning when unspecified.
        payeeHtml = line.bank_account_id
            ? `<div class="manual-line-payee">${langData['payee_dest_retained'] || 'Retained by company'} - ${escapeHtml(line.bank_account_name || '')}</div>`
            : `<div class="manual-line-payee manual-line-payee-warn">${langData['payee_bank_account_needs_review'] || 'Company Account -- bank account not specified, needs review'}</div>`;
    } else if (line.payee_type === 'other_person') {
        payeeHtml = `<div class="manual-line-payee">${escapeHtml(line.destination_account_name || (langData['payee_dest_external'] || 'Transfer to an external person or organization'))}</div>`;
    } else if (line.payee_type === 'not_disbursed') {
        payeeHtml = `<div class="manual-line-payee">${langData['payee_type_not_disbursed'] || 'Not Disbursed'}</div>`;
    }
    const editable = !!canEdit && !!line.id;
    // A legacy row inside an otherwise editable block says why it has no buttons, on the row itself
    // -- an empty slot with no explanation reads as a rendering glitch.
    const rowTitle = (canEdit && !line.id)
        ? ` title="${escapeAttr(langData['manual_line_legacy_locked'] || 'Added before items became editable here -- it cannot be edited or removed from this screen')}"`
        : '';
    return `<tr class="payslip-row manual-line-item${editable ? ' manual-line-item-editable' : ''}" data-line-id="${line.id || ''}"${rowTitle}>
        <td>
            <div class="payslip-line-head"><span class="payslip-line-name"${line.is_custom ? '' : ` title="${escapeAttr(line.item_code || '')}"`}>${escapeHtml(name)}</span>${manualLineTagHtml(line)}</div>
            ${noteHtml}
            ${payeeHtml}
        </td>
        <td class="text-end num ${moneyCls}">
            <div class="manual-line-amount-wrap"><span class="manual-line-amount">${fmtNum(line.amount)}</span>${manualLineRowActionsHtml(line, canEdit)}</div>
        </td>
    </tr>`;
}
// The row's own actions (§7: at most 3 round 32px buttons, one size and one colour for every action
// -- delete is grey here and only turns red on the confirm, §3/§4). An empty, same-width slot for a
// row that cannot carry them keeps every figure in the column on one line.
function manualLineRowActionsHtml(line, canEdit) {
    if (!canEdit) return '';
    if (!line.id) return '<span class="manual-line-actions manual-line-actions-empty"></span>';
    return `<span class="manual-line-actions">
        <button type="button" class="btn-icon manual-line-edit-btn" data-line-id="${line.id}" title="${escapeAttr(langData['manual_line_form_edit_title'] || 'Edit item')}"><i class="fa-solid fa-pen"></i></button>
        <button type="button" class="btn-icon manual-line-remove-btn" data-line-id="${line.id}" title="${escapeAttr(langData['action_remove'] || 'Remove')}"><i class="fa-solid fa-trash-can"></i></button>
    </span>`;
}

/* ---------- Attendance Data (from Sync) (2026-08-21, explicit request: "ต้องการแก้ตัวเลขดิบที่ Sync
   มา ไม่ใช่แค่ยอดเงิน") -- corrects the RAW numbers Origami sent (not the resulting deduction/earning
   amount -- that is the line-override table, which lives in the Calculation Breakdown modal), shown
   only on a sync-based run. One combined form/Save for all 7 fields (not per-field) since they represent one
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
    const syncedDisplay = (synced !== null && synced !== undefined) ? fmtNum(synced) : '-';
    const badge = hasOverride ? ` <span class="badge bg-warning-subtle text-warning">${langData['sync_line_override_overridden_badge'] || 'Overridden'}</span>` : '';
    // 2026-09-14, Round 3 item 4 batch 1/4, real gap found via Playwright while verifying the new
    // per-tab dirty-guard: this input had no `id`/`name` at all (only relied on its own
    // .attendance-data-input class, read by data-field on the <tr> instead) -- snapshotFormState()
    // (app.js) silently skips any input with neither, so Tab 2 could never register as dirty. Adding
    // a stable id (does not affect #btnSaveAttendanceData's own read logic, which still reads by
    // class/data-field, unchanged) is what makes the dirty-guard able to see this tab at all.
    return `<tr data-field="${field.key}">
        <td>${label}${badge}</td>
        <td class="text-end text-muted">${syncedDisplay}</td>
        <td><input type="number" id="attendanceDataInput_${field.key}" step="${field.step}" min="0" class="form-control form-control-sm attendance-data-input" value="${effective}"></td>
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
            // 2026-09-14, Round 3 item 4 batch 1/4: this tab's own data just landed (or was just
            // re-saved) -- re-baseline its dirty-guard against what's actually on the server now.
            refreshAdjustmentTabDirtyGuard('manageLinesAttendancePane');
            refreshAdjustmentSaveButtonState();
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
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.attendance-override.save`,
        method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, fields: fields }),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                loadAttendanceDataRd();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            setButtonLoading($btn, false);
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
                    loadRunDetail();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
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
// 'employee_not_enrolled' does not say WHICH enrolment, so the badge is resolved per item code --
// the 2 codes that can produce it are the 2 in StatutoryCalculationEngine's own ITEM_ENROLLMENT_FLAG.
function lineOverrideSkipEnumRd(line) {
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
function lineOverrideIsSkippedRd(line) {
    return !line.override_action && !!lineOverrideSkipEnumRd(line);
}
let lineOverrideRowsRd = [];
/* 2026-09-16, D1: this table had TWO mount points -- the Adjustments modal's "ปรับตัวเลข" tab and the
   Calculation Breakdown modal's editable layout -- and exactly ONE implementation. `lineOverrideHostRd`
   is the whole of the difference between them: where to render, whose lines to fetch, and what else to
   refresh after a write. Every function below reads it instead of naming a container, so no host owns
   the table.
   2026-09-17, D3: the "ปรับตัวเลข" tab is gone and the Breakdown modal is the only host left. The
   indirection stays as the ONE place a host is described (a `.lo-mount` is still resolved through it,
   never named inline) rather than being inlined back into every function -- the alternative is putting
   `#breakdownLineOverrideWrap` in ~10 places again, which is what D1 removed. */
let lineOverrideHostRd = { mount: '#breakdownLineOverrideWrap', employeeId: null, onSaved: null };
function lineOverrideMountRd() {
    return $(lineOverrideHostRd.mount);
}
function lineOverrideEmployeeIdRd() {
    return lineOverrideHostRd.employeeId !== null ? lineOverrideHostRd.employeeId : manageLinesEmployeeId;
}
function setLineOverrideHostRd(mount, employeeId, onSaved) {
    if (lineOverrideHostRd.mount !== mount) {
        $(lineOverrideHostRd.mount).empty();
    }
    lineOverrideHostRd = { mount: mount, employeeId: employeeId, onSaved: onSaved || null };
}
// Edit history for THIS employee, keyed 'line_type|item_code' -- fetched once alongside the table's
// own data (loadSyncLineOverridesRd) because the table has to know at RENDER time which rows even
// have a history badge to draw.
let lineOverrideHistoryRd = { byKey: {}, historyAvailable: true, startDate: null };
function lineOverrideHistoryFor(line) {
    return lineOverrideHistoryRd.byKey[(line.line_type || 'earning_deduction') + '|' + line.code] || null;
}
// A value as the dropdown shows it: masked values arrive as a string ('XXXX') and must pass through
// untouched -- fmtNum() on them would print NaN.
function lineOverrideHistoryValueRd(value) {
    if (value === null || value === undefined) return '';
    return typeof value === 'number' ? fmtNum(value) : String(value);
}
// Every row is the same 1-line shape: the VALUE on the left (it is what the user is choosing
// between), whatever explains it on the right. `metaHtml` is caller-built markup, never user input.
function lineOverrideHistoryItemHtml(valueText, metaHtml, isCurrent, options) {
    options = options || {};
    // The value in effect is shown for orientation, not offered as a choice -- picking it would be a
    // no-op that still marks the row dirty.
    return `<li class="${options.liClass || ''}"><button type="button" class="dropdown-item lo-history-item ${options.itemClass || ''}" data-value="${escapeAttr(valueText)}"
        ${isCurrent ? 'disabled' : ''}>
        <span class="lo-history-value num">${escapeHtml(valueText)}</span>
        <span class="lo-history-meta">${metaHtml}</span>
    </button></li>`;
}
// Short form for the dropdown: dd/mm HH:mm (the year is noise for an edit made inside this run).
function lineOverrideHistoryWhenRd(edit) {
    if (!edit.changed_at) return '';
    const full = formatDisplayDateTime(edit.changed_at);
    return full.length > 5 && full.indexOf('/') > 0 ? full.replace(/^(\d{2}\/\d{2})\/\d{4}/, '$1') : full;
}
function lineOverrideHistoryWhoRd(edit) {
    return (currentLang === 'th' ? edit.changed_by_name_th : edit.changed_by_name_en)
        || edit.changed_by_name_th || edit.changed_by_name_en || '';
}
function lineOverrideHistoryMetaRd(edit) {
    return [lineOverrideHistoryWhenRd(edit), lineOverrideHistoryWhoRd(edit)].filter(Boolean).join(' · ');
}
// Which entry is the one in effect right now: the NEWEST whose value matches the live figure, not
// every entry that happens to share it (the same amount can be set, changed, and set again).
function lineOverrideHistoryCurrentIndexRd(editsNewestFirst, currentText) {
    if (currentText === '') return -1;
    for (let i = 0; i < editsNewestFirst.length; i++) {
        if (lineOverrideHistoryValueRd(editsNewestFirst[i].new_value) === currentText) return i;
    }
    return -1;
}
// The menu is 3 fixed parts: the calculated figure as a sticky HEAD (it is the baseline every row
// below is a departure from -- context, not one of the choices), the full list of edits in the
// middle (all of them, scrolling at about 5 rows -- cutting it at 5 would hide edits with no way to
// tell that anything was missing), and a sticky FOOT into the modal, which is where the note, the
// full date and the from→to of each edit live.
function lineOverrideHistoryMenuHtml(history) {
    const currentText = lineOverrideHistoryValueRd(history.current_value);
    const computedText = lineOverrideHistoryValueRd(history.original_value);
    const currentBadge = countBadgeHtml(0, { label: langData['line_override_history_current'] || 'Current' });
    // 2026-09-17, R1: the head is REFERENCE, not a choice -- it is the figure every row below is a
    // departure from, and the row itself now carries a button for going back to it (the round
    // `.lo-use-system-btn`). A menu row that does the same thing as a button 2 columns away is a
    // second way to do one thing; this one keeps the same [value | what it is] skeleton as the rows
    // under it, without being pressable.
    let html = `<li class="lo-history-head"><div class="lo-history-item lo-history-item-static">
        <span class="lo-history-value num">${escapeHtml(computedText)}</span>
        <span class="lo-history-meta">${escapeHtml(langData['line_override_history_computed'] || 'Calculated value')}</span>
    </div></li>`;
    const edits = (history.edits || []).slice().reverse();
    const currentIdx = lineOverrideHistoryCurrentIndexRd(edits, currentText);
    edits.forEach(function (edit, i) {
        const isCurrent = i === currentIdx;
        // The badge goes IN FRONT of the same meta every other row has -- "which one is live" is an
        // extra fact about the entry, not a replacement for when it was made and by whom.
        const meta = escapeHtml(lineOverrideHistoryMetaRd(edit));
        html += lineOverrideHistoryItemHtml(
            lineOverrideHistoryValueRd(edit.new_value),
            isCurrent ? currentBadge + ' ' + meta : meta,
            isCurrent
        );
    });
    const tpl = langData['line_override_history_view_all'] || 'Full history ({n})';
    html += `<li class="lo-history-foot"><button type="button" class="dropdown-item lo-history-view-all">${escapeHtml(tpl.replace('{n}', String(edits.length)))}</button></li>`;
    return html;
}
// 2026-09-17, R1: the figure the system calculated, as a column of its own -- the same number the
// history dropdown has always shown as its head row, out where it can be compared with the live one
// without opening anything. It comes from 2 places because that is where it really lives:
//   - no override on this row  -> the live figure IS the calculated one (nothing has replaced it)
//   - an override              -> `original_value` of this line's history (the value the FIRST
//                                 recorded edit replaced), which is exactly what the dropdown's own
//                                 head row reads
// An override made before this run's history was ever recorded has neither (the breakdown JSON
// keeps only the overridden amount, never the engine's own) -- that row shows "-" and says why in
// its title rather than printing a number nothing backs up. Measured on the dev DB while building
// this: 0 rows of that kind (1 override, all of it with history).
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
    const history = lineOverrideHistoryFor(line);
    const original = history ? history.original_value : null;
    return (original === null || original === undefined) ? '' : lineOverrideHistoryValueRd(original);
}
function lineOverrideComputedCellHtml(line) {
    const text = lineOverrideComputedTextRd(line);
    if (text === '') {
        return `<span class="lo-computed-unknown" title="${escapeAttr(langData['line_override_computed_unknown'] || 'The calculated value was not recorded for this item')}">-</span>`;
    }
    return `<span class="lo-computed-value">${escapeHtml(text)}</span>`;
}
function lineOverrideHistoryCellHtml(line) {
    const history = lineOverrideHistoryFor(line);
    const editCount = history && history.edits ? history.edits.length : 0;
    if (!editCount) return '';
    const label = (langData['line_override_history_badge'] || '{n} edit(s)').replace('{n}', String(editCount));
    return badgeDropdownHtml({
        enum: 'edited',
        label: label,
        menuHtml: lineOverrideHistoryMenuHtml(history),
        toggleClass: 'lo-history-toggle',
        // This table scrolls inside `.table-responsive`; an absolutely-positioned menu is clipped by
        // that container the moment it opens below the last rows. Popper's fixed strategy takes it
        // out of that clip (§6 -- see badgeDropdownHtml()'s own note).
        fixedStrategy: true,
    });
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
function lineOverrideRowHtml(line, idx, group, runDisabled) {
    const name = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || line.code;
    const origAction = line.override_action || '';
    const included = runDisabled ? false : origAction !== 'exclude';
    const skipEnum = lineOverrideSkipEnumRd(line);
    // Reason first, then what kind of line it is -- "ไม่ได้เข้ากองทุน" answers the question the row
    // itself raises ("why is this 0.00?"), which the user is asking before anything else.
    const skipBadge = (skipEnum && !line.override_action) ? ' ' + statusBadgeHtml(skipEnum, 'payroll_statutory_skip') : '';
    const statutoryBadge = line.line_type === 'statutory' ? ' ' + statusBadgeHtml('statutory', 'payroll_line_type') : '';
    const runDisabledAttr = runDisabled ? ' disabled' : '';
    const title = runDisabled ? ' title="' + escapeAttr(langData['line_override_run_disabled'] || 'Turned off in Run Settings') + '"' : '';
    const hiddenCls = lineOverrideIsSkippedRd(line) ? ' lo-row-skipped d-none' : '';
    const editable = included && !runDisabled;
    // 2026-09-17, R1: the pencil shows on every editable row, not on hover -- an affordance nobody
    // can see until they hover is not one (§7, the same conclusion the comment list reached).
    // 2026-09-17, R1 follow-up: the plain `.btn-icon` circle, not the ghost variant -- §7's ghost is
    // for a circle sitting on a panel that already has a surface of its own, which a table row is not.
    // Same button as the pencil/bin in the "รายการเพิ่มเติม" block below it.
    const pencil = editable
        ? `<button type="button" class="btn btn-icon lo-edit-btn" title="${escapeAttr(langData['line_override_edit_amount'] || 'Edit amount')}" aria-label="${escapeAttr(langData['line_override_edit_amount'] || 'Edit amount')}"><i class="fa-solid fa-pen"></i></button>`
        : '';
    // "Back to what the system calculated", right where the 2 figures disagree -- it only exists on a
    // row where they DO disagree, and it sends exactly what the history dropdown's calculated row
    // sends (drop the override), through the same confirm.
    const amountText = lineOverrideHistoryValueRd(line.current_amount);
    const computedText = lineOverrideComputedTextRd(line);
    const useSystem = (editable && computedText !== '' && computedText !== amountText)
        ? `<button type="button" class="btn btn-icon lo-use-system-btn" title="${escapeAttr(langData['line_override_use_computed'] || 'Use the calculated value')}" aria-label="${escapeAttr(langData['line_override_use_computed'] || 'Use the calculated value')}"><i class="fa-solid fa-rotate-left"></i></button>`
        : '';
    // 2026-09-17, R1 follow-up: the buttons are their OWN column now. A figure and the controls that
    // act on it were sharing a cell, which meant the figure's right edge was wherever the buttons
    // left it -- so it never lined up with the column head above it, and it moved again when the
    // editor opened. Separating them lets both be what they are: a money column that ends on one x,
    // and a fixed-width action column beside it.
    // An excluded row has no amount to show: 0.00 struck through still reads as a figure that counts
    // for something. What is true about it is that it is not in the calculation, so it says that.
    const amountCell = included
        ? `<span class="num ${lineOverrideMoneyClassRd(line, group)}">${fmtNum(line.current_amount)}</span>`
        : `<span class="lo-amount-excluded">${escapeHtml(langData['line_override_excluded_amount'] || 'Not calculated')}</span>`;
    return `<tr class="lo-row${included ? '' : ' lo-row-off'}${hiddenCls}" data-item-code="${escapeAttr(line.code)}" data-line-type="${escapeAttr(line.line_type || 'earning_deduction')}"
        data-orig-action="${escapeAttr(origAction)}" data-item-name="${escapeAttr(name)}" data-amount="${escapeAttr(fmtNum(line.current_amount))}"${title}>
        <td class="col-check tbl-sticky-col"><div class="form-check form-switch mb-0">
            <input class="form-check-input lo-include" type="checkbox" role="switch" id="loInc${idx}" ${included ? 'checked' : ''}${runDisabledAttr}>
        </div></td>
        <td class="lo-name-cell tbl-sticky-col tbl-sticky-col-edge-left">
            <span class="lo-name" title="${escapeAttr(name)} (${escapeAttr(line.code)})">${escapeHtml(name)}</span>${skipBadge}${statutoryBadge}
            ${lineOverrideOccurrencesHtml(line.occurrences)}
        </td>
        <td class="num col-money lo-computed-cell">${lineOverrideComputedCellHtml(line)}</td>
        <td class="num col-money lo-amount-cell"><div class="lo-amount-view">${amountCell}</div></td>
        <td class="lo-action-cell"><div class="lo-actions">${useSystem}${pencil}</div></td>
        <td class="lo-history-cell">${included ? lineOverrideHistoryCellHtml(line) : ''}</td>
    </tr>`;
}
// 2026-09-17, R1 follow-up: the pinned 2nd column starts where the 1st one really ENDS. Its
// declared width is 78px, but what a sticky `left` has to match is the rendered border-box -- borders
// and sub-pixel rounding are not in the declaration, and being off by a fraction is exactly what
// made the pair drift while dragging. Published as a CSS variable so the offset stays in CSS (the
// media query decides WHETHER to pin; this only says WHERE).
// Not dtWatchVisibleWidth(): that one publishes a DataTables scroller's visible width for the empty
// state, a different value on a different element -- same ResizeObserver shape, nothing to share.
function lineOverridePublishStickyOffsetRd($wrap) {
    const table = $wrap.find('table.lo-table').get(0);
    if (!table) return;
    const publish = function () {
        const firstCell = table.querySelector('thead th.col-check');
        if (!firstCell) return;
        table.style.setProperty('--lo-sticky-left-2', firstCell.getBoundingClientRect().width + 'px');
    };
    publish();
    const scroller = $wrap.get(0);
    if (typeof ResizeObserver === 'function' && scroller) {
        new ResizeObserver(publish).observe(scroller);
    }
}
function renderLineOverrideTableRd(lines, runSettings) {
    lineOverrideRowsRd = lines || [];
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
    let idx = 0;
    let body = '';
    let hiddenCount = 0;
    LINE_OVERRIDE_GROUPS_RD.forEach(function (group) {
        const groupLines = lineOverrideRowsRd.filter(l => (l.item_type || 'other') === group.type);
        if (!groupLines.length) return;
        // A group whose every row is hidden has nothing to label -- the heading goes with them, and
        // comes back with them (same `.lo-group-skipped` class the toggle flips).
        const allSkipped = groupLines.every(lineOverrideIsSkippedRd);
        body += `<tr class="lo-group${allSkipped ? ' lo-group-skipped d-none' : ''}"><td colspan="6"><span class="lo-span-sticky">${escapeHtml(langData[group.key] || group.fallback)}</span></td></tr>`;
        groupLines.forEach(function (line) {
            if (lineOverrideIsSkippedRd(line)) hiddenCount++;
            // Disabled only where the run-level exclusion is genuinely in charge: a personal override
            // of any kind already wins over it (PayrollRunModel::recalculate()'s own resolution), so
            // such a row stays editable here.
            const runDisabled = !line.override_action && runExcluded.has(line.code);
            body += lineOverrideRowHtml(line, idx++, group, runDisabled);
        });
    });
    $wrap.html(`<div class="table-responsive"><table class="table align-middle lo-table mb-0">
        <thead>
            <tr>
                <th class="col-check tbl-sticky-col">${escapeHtml(langData['line_override_col_include'] || 'Include')}</th>
                <th class="lo-name-col tbl-sticky-col tbl-sticky-col-edge-left">${escapeHtml(langData['line_override_col_item'] || 'Item')}</th>
                <th class="num col-money lo-computed-col">${escapeHtml(langData['line_override_col_computed'] || 'Calculated')}</th>
                <th class="num col-money lo-amount-col">${escapeHtml(langData['line_override_col_amount'] || 'Amount')}</th>
                <th class="lo-action-col"><span class="visually-hidden">${escapeHtml(langData['action'] || 'Action')}</span></th>
                <th class="lo-history-col">${escapeHtml(langData['line_override_col_history'] || 'History')}</th>
            </tr>
        </thead>
        <tbody>${body}${lineOverrideHiddenRowHtml(hiddenCount)}</tbody>
    </table></div>`);
    lineOverrideEditingCodeRd = null;
    // 2026-09-17, R1 follow-up: the shared scroller wiring (sticky-table-columns.js) -- it is what
    // keeps `.tbl-scrolled-x` in sync with scrollLeft, which is what the frozen columns' own right
    // edge shadow is keyed off (§7). Re-run per render: this markup, wrapper included, is rebuilt
    // every time, so the previous binding went with it.
    if (typeof initTableDragScroll === 'function') initTableDragScroll(lineOverrideHostRd.mount + ' .lo-table');
    lineOverridePublishStickyOffsetRd($wrap.find('.table-responsive').addBack('.table-responsive').first());
    // Delegated once per scope (the wrapper survives every re-render inside it) -- no onSelect here:
    // this table's menus are action menus, the row's own click handler above does the work.
    if (typeof initBadgeDropdown === 'function') initBadgeDropdown($wrap);
}
// The last ROW of the table, not a caption under it -- what it reveals are rows, so it belongs in the
// same column grid they do (§7). Nothing at all when there is nothing hidden.
function lineOverrideHiddenRowHtml(hiddenCount) {
    if (!hiddenCount) return '';
    const text = (langData['line_override_show_hidden_rows'] || 'Show {n} hidden').replace('{n}', String(hiddenCount));
    // WHY those rows are hidden is a footnote to this one button, not something the tab's own hint
    // has to carry for every reader who has no hidden rows at all -- so it rides on the button.
    const why = langData['line_override_hidden_why'] || '';
    return `<tr class="lo-hidden-row" id="lineOverrideHiddenRow"><td colspan="6">
        <span class="lo-span-sticky"><button type="button" class="btn btn-link lo-hidden-toggle" id="btnToggleHiddenLineOverrides"
            data-shown="0" data-count="${hiddenCount}" title="${escapeAttr(why)}">${escapeHtml(text)} <i class="fa-solid fa-chevron-down"></i></button></span>
    </td></tr>`;
}
// Show/hide only ever toggles classes -- no field's value or disabled state changes, so the dirty
// guard (§9, which snapshots field values) correctly sees nothing happening here.
$(document).on('click', '.lo-mount .lo-hidden-toggle', function () {
    const $btn = $(this);
    const show = $btn.attr('data-shown') !== '1';
    const count = $btn.attr('data-count') || '0';
    const label = show
        ? (langData['line_override_hide_rows'] || 'Hide')
        : (langData['line_override_show_hidden_rows'] || 'Show {n} hidden').replace('{n}', count);
    $btn.attr('data-shown', show ? '1' : '0');
    $btn.html(escapeHtml(label) + ` <i class="fa-solid fa-chevron-${show ? 'up' : 'down'}"></i>`);
    lineOverrideMountRd().find('.lo-row-skipped, .lo-group-skipped').toggleClass('d-none', !show);
});
// Picking a value out of a row's own history is a write like any other in this tab: it confirms,
// then sends.
$(document).on('click', '.lo-mount .lo-history-item', function () {
    lineOverrideConfirmApplyHistoryValueRd($(this).closest('tr.lo-row').data('item-code'), $(this).attr('data-value') || '',
        $(this).hasClass('lo-history-computed'));
});
// 2026-09-17, R1: the row's own "back to the calculated value" button -- the SAME confirm and the
// SAME send the dropdown's calculated row used to do (`asComputed` = drop the override), reached
// without opening a menu first.
$(document).on('click', '.lo-mount .lo-use-system-btn', function () {
    lineOverrideConfirmApplyHistoryValueRd($(this).closest('tr.lo-row').data('item-code'), '', true);
});
/* ---------- "ประวัติการแก้ไข" modal (stacked on top of the modal holding the table) -- the full chain for
   ONE line: every past value with when/who/note, and the same "use this value" action the dropdown
   offers, for the entries the 5-row dropdown could not show. Rendered with the shared timeline
   component (§6) -- newest first, grouped by day: the timeline's head only ever prints HH:mm, so
   without the day header two edits made on different days read as the same time. The calculated
   value carries no date at all and gets no header (its empty one is hidden in CSS). */
let lineOverrideHistoryModalCode = null;
function lineOverrideHistoryTimelineItemsRd(history) {
    const currentText = lineOverrideHistoryValueRd(history.current_value);
    const useBtn = function (valueText, isComputed) {
        return `<button type="button" class="btn btn-outline-primary lo-history-use" data-value="${escapeAttr(valueText)}"`
            + (isComputed ? ' data-computed="1"' : '') + '>'
            + escapeHtml(langData['line_override_history_use_value'] || 'Use this value') + '</button>';
    };
    const currentBadge = countBadgeHtml(0, { label: langData['line_override_history_current'] || 'Current' });
    const editsNewestFirst = (history.edits || []).slice().reverse();
    const currentIdx = lineOverrideHistoryCurrentIndexRd(editsNewestFirst, currentText);
    const items = editsNewestFirst.map(function (edit, i) {
        const valueText = lineOverrideHistoryValueRd(edit.new_value);
        const fromText = lineOverrideHistoryValueRd(edit.old_value);
        const isCurrent = i === currentIdx;
        const tpl = langData['line_override_history_from_to'] || 'from {from} → {to}';
        return {
            time: edit.changed_at,
            actor: { name: lineOverrideHistoryWhoRd(edit) },
            title: valueText,
            detail: fromText === '' ? '' : tpl.replace('{from}', fromText).replace('{to}', valueText),
            // The button sits on the value's own line (CSS, .lo-history-timeline) -- the note is a
            // second fact about the entry and stays under it, in the left column.
            actionHtml: (isCurrent ? currentBadge : useBtn(valueText, false))
                + (edit.note ? `<div class="lo-history-note">${escapeHtml(edit.note)}</div>` : ''),
        };
    });
    // The calculated value is not an edit -- it is where the line started, so it is pinned to the top
    // of the list rather than sorted into it by a timestamp it does not have.
    const computedText = lineOverrideHistoryValueRd(history.original_value);
    const computedIsCurrent = currentIdx === -1 && computedText !== '' && computedText === currentText;
    items.unshift({
        time: '',
        title: computedText,
        detail: langData['line_override_history_computed'] || 'Calculated value',
        actionHtml: computedIsCurrent ? currentBadge : useBtn(computedText, true),
    });
    return items;
}
function openLineOverrideHistoryModalRd(itemCode) {
    const line = lineOverrideRowsRd.find(l => l.code === itemCode);
    if (!line) return;
    const history = lineOverrideHistoryFor(line);
    if (!history) return;
    lineOverrideHistoryModalCode = itemCode;
    const name = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || line.code;
    $('#lineOverrideHistoryModalLabel').text(`${langData['line_override_history_modal_title'] || 'Edit history'} · ${name}`);
    $('#lineOverrideHistoryModalBody').html('<div class="lo-history-timeline">'
        + renderTimeline(lineOverrideHistoryTimelineItemsRd(history), { groupByDay: true }) + '</div>');
    const $note = $('#lineOverrideHistoryModalNote');
    if (!lineOverrideHistoryRd.historyAvailable && lineOverrideHistoryRd.startDate) {
        const tpl = langData['line_override_history_since'] || 'History has been recorded since {date}';
        $note.text(tpl.replace('{date}', formatDisplayDate(lineOverrideHistoryRd.startDate))).removeClass('d-none');
    } else {
        $note.addClass('d-none').text('');
    }
    new bootstrap.Modal(document.getElementById('lineOverrideHistoryModal')).show();
}
$(document).on('click', '.lo-mount .lo-history-view-all', function () {
    openLineOverrideHistoryModalRd($(this).closest('tr.lo-row').data('item-code'));
});
$(document).on('click', '#lineOverrideHistoryModal .lo-history-use', function () {
    if (!lineOverrideHistoryModalCode) return;
    lineOverrideConfirmApplyHistoryValueRd(lineOverrideHistoryModalCode, $(this).attr('data-value') || '',
        $(this).attr('data-computed') === '1', function () {
            bootstrap.Modal.getInstance(document.getElementById('lineOverrideHistoryModal')).hide();
        });
});
// Picking a value out of a history list is one click away from replacing a figure someone else set,
// and the two lists sit right under the pointer while scrolling -- so it asks first. The row is
// written the moment it is confirmed (this tab has no Save button to press afterwards).
function lineOverrideConfirmApplyHistoryValueRd(itemCode, value, asComputed, onApplied) {
    const $row = lineOverrideRowByCodeRd(itemCode);
    if (!$row.length || $row.hasClass('lo-row-off')) return;
    // A calculated figure history never recorded has no number to name -- say what it IS instead.
    const valueLabel = value === '' ? (langData['line_override_history_computed'] || 'the calculated value') : value;
    const tpl = langData['line_override_confirm_use_value_message']
        || '{item} will be set to {value} and saved immediately.';
    showConfirm({
        title: langData['line_override_confirm_use_value_title'] || 'Use this value instead of the current one',
        message: tpl.replace('{value}', valueLabel).replace('{item}', String($row.data('item-name') || itemCode)),
        tone: 'info',
        confirmText: langData['line_override_history_use_value'] || 'Use this value',
        cancelText: langData['cancel'] || 'Cancel',
        onYes: function () {
            if (typeof onApplied === 'function') onApplied();
            // The calculated-value row means "drop the override", not "save this number as one".
            if (asComputed) {
                lineOverrideSendRd($row, { action: 'remove' });
            } else {
                const parsed = typeof parseMoneyInput === 'function' ? parseMoneyInput(value) : parseFloat(value);
                if (isNaN(parsed)) return;
                lineOverrideSendRd($row, { action: 'override_amount', amount: parsed });
            }
        },
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
function loadSyncLineOverridesRd() {
    const employeeId = lineOverrideEmployeeIdRd();
    fetchSyncLinesForEmployeeRd(employeeId, function (res) {
        // One extra request per modal open, fired in parallel and rendered together: the table
        // cannot draw its History column without knowing which rows have edits.
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.line-override-history`,
            method: 'GET',
            data: { run_id: PAYROLL_RUN_ID, employee_id: employeeId },
            dataType: 'json',
        }).always(function (historyRes) {
            const payload = (historyRes && historyRes.status && historyRes.data) ? historyRes.data : null;
            lineOverrideHistoryRd = { byKey: {}, historyAvailable: payload ? !!payload.history_available : true, startDate: payload ? payload.history_start_date : null };
            (payload && payload.lines ? payload.lines : []).forEach(function (line) {
                lineOverrideHistoryRd.byKey[line.line_type + '|' + line.item_code] = line;
            });
            renderLineOverrideTableRd(res.data || [], res.run_settings);
            refreshBreakdownFooterStateRd();
        });
    });
}
// The "Tax & SSO" tab's own half of that same response. Its dirty-guard baseline is re-taken here,
// once the radios really hold what the server says -- a baseline from `shown.bs.modal` would be the
// pre-load DOM (see ADJUSTMENT_TAB_CONFIG_RD's own docblock).
function loadEmployeeExemptionRd() {
    fetchSyncLinesForEmployeeRd(manageLinesEmployeeId, function (res) {
        const ex = res.exemption || { tax_calculate_override: 'inherit', sso_calculate_override: 'inherit' };
        $(`#empCalcTaxGroup input[value="${ex.tax_calculate_override || 'inherit'}"]`).prop('checked', true);
        $(`#empCalcSsoGroup input[value="${ex.sso_calculate_override || 'inherit'}"]`).prop('checked', true);
        refreshAdjustmentTabDirtyGuard('manageLinesCalcPane');
        refreshAdjustmentSaveButtonState();
    });
}
function lineOverrideRowByCodeRd(itemCode) {
    return lineOverrideMountRd().find(`tr.lo-row[data-item-code="${itemCode}"]`);
}
// Flipping the switch changes what this employee gets paid, in both directions -- so both directions
// ask, and neither writes anything until the answer is yes. A cancelled confirm puts the switch back
// where it was rather than leaving the control disagreeing with the data behind it.
$(document).on('change', '.lo-mount .lo-include', function () {
    const $row = $(this).closest('tr.lo-row');
    const included = this.checked;
    const name = String($row.data('item-name') || $row.data('item-code'));
    const snapBack = function () { $row.find('.lo-include').prop('checked', !included); };
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
/* One row, one request, sent the moment the user says yes. A statutory row goes to its own endpoint,
   which wraps the item_code itself -- never wrapped here. */
function lineOverrideSaveUrlRd($row, action) {
    const statutory = ($row.data('line-type') || 'earning_deduction') === 'statutory';
    const verb = action === 'remove' ? 'remove' : 'save';
    return statutory
        ? `${BASE_URL}/api/payroll-run.statutory-line-override.${verb}`
        : `${BASE_URL}/api/payroll-run.line-override.${verb}`;
}
// Whole-TABLE lock for the duration of one write: every save recalculates the run internally, so a
// second action started before the first comes back would race it. The rest of the modal stays
// usable -- only this tab writes on every action.
function setLineOverrideTableBusyRd(busy) {
    const $wrap = lineOverrideMountRd();
    $wrap.toggleClass('lo-table-busy', busy);
    $wrap.find('.lo-include, .lo-edit-btn, .lo-history-toggle, .lo-hidden-toggle').each(function () {
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
// The one write path for this tab: switch, pencil, and both "use this value" entry points all end
// here. `$busyBtn` is the control that should carry the spinner (the row's own Save, when there is
// one) -- everything else just locks.
function lineOverrideSendRd($row, plan, $busyBtn) {
    if (!$row || !$row.length || !plan) return;
    const payload = { id: PAYROLL_RUN_ID, employee_id: lineOverrideEmployeeIdRd(), item_code: $row.data('item-code') };
    if (plan.action === 'override_amount') { payload.action = 'override_amount'; payload.override_amount = plan.amount; }
    if (plan.action === 'exclude') { payload.action = 'exclude'; }
    setLineOverrideTableBusyRd(true);
    if ($busyBtn && $busyBtn.length) setButtonLoading($busyBtn, true);
    $.ajax({
        url: lineOverrideSaveUrlRd($row, plan.action),
        method: 'POST', contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) {
            if (!res.status) { lineOverrideSendFailedRd($row, $busyBtn, res.message); return; }
            showSuccess(langData['line_override_saved'] || 'Saved.');
            // A full reload, not a local patch: one override changes what the statutory lines
            // calculate to, so every row's amount (and the run's own totals) can move. The host's
            // own hook is what refreshes anything OUTSIDE this table that moved with it.
            loadSyncLineOverridesRd();
            loadRunDetail();
            if (lineOverrideHostRd.onSaved) lineOverrideHostRd.onSaved();
        },
        error: function () { lineOverrideSendFailedRd($row, $busyBtn); },
    });
}
// A failed write leaves the table exactly as the user left it -- including whatever they typed --
// so they can fix the value and try again rather than start over.
function lineOverrideSendFailedRd($row, $busyBtn, message) {
    setLineOverrideTableBusyRd(false);
    if ($busyBtn && $busyBtn.length) setButtonLoading($busyBtn, false);
    showError(message || langData['save_failed'] || 'Could not save.');
}

/* ---- inline edit of one amount ---------------------------------------------------------------
   The cell becomes the editor: the figure is replaced in place by an input carrying that same
   figure, plus Save and a ghost ✗. One row at a time -- opening a second closes the first, because
   two half-finished edits on one table is a state nobody can read off the screen. */
let lineOverrideEditingCodeRd = null;
function lineOverrideCloseEditorRd() {
    const $wrap = lineOverrideMountRd();
    $wrap.find('tr.lo-row').each(function () {
        const $row = $(this);
        if (!$row.find('.lo-amount-edit').length) return;
        $row.find('.lo-amount-edit').remove();
        $row.find('.lo-amount-view, .lo-actions').removeClass('d-none');
        $row.removeClass('lo-row-editing');
    });
    lineOverrideEditingCodeRd = null;
}
// 2026-09-17, R1 follow-up: the editor stays INSIDE the 2 cells the figure and its buttons already
// occupy -- the input fills the amount cell (so the figure it replaces keeps the same right edge)
// and the 2 controls take the action cell's place. Nothing is inserted that could widen a column,
// which is what used to make the whole table shift the moment a pencil was pressed.
function lineOverrideOpenEditorRd($row) {
    lineOverrideCloseEditorRd();
    const $cell = $row.find('.lo-amount-cell');
    const current = String($row.data('amount') || '');
    $row.addClass('lo-row-editing');
    $cell.find('.lo-amount-view').addClass('d-none');
    $row.find('.lo-actions').addClass('d-none');
    // 2026-09-17, R1: both controls are the same 32px circle as every other icon button in this
    // modal (§7) -- a text "Save" button beside 2 round ones was the only control here with a shape
    // of its own. Icon-only, so each carries its name for screen readers through `aria-label`.
    const saveLabel = escapeAttr(langData['save'] || 'Save');
    const cancelLabel = escapeAttr(langData['cancel'] || 'Cancel');
    $cell.append(`<input type="text" inputmode="decimal" class="form-control money-input lo-edit-input lo-amount-edit" value="${escapeAttr(current)}">`);
    $row.find('.lo-action-cell').append(`<div class="lo-actions lo-amount-edit">
        <button type="button" class="btn btn-icon lo-edit-save" title="${saveLabel}" aria-label="${saveLabel}" disabled><i class="fa-solid fa-check"></i></button>
        <button type="button" class="btn btn-icon lo-edit-cancel" title="${cancelLabel}" aria-label="${cancelLabel}"><i class="fa-solid fa-xmark"></i></button>
    </div>`);
    const $input = $row.find('.lo-edit-input');
    if (typeof initMoneyInputs === 'function') initMoneyInputs($cell);
    lineOverrideEditingCodeRd = String($row.data('item-code'));
    $input.trigger('focus').trigger('select');
}
// Saving the figure that is already there writes nothing and means nothing -- so the button says so
// by being disabled, and Enter does nothing either.
function lineOverrideEditPlanRd($row) {
    const $input = $row.find('.lo-edit-input');
    if (!$input.length) return null;
    const raw = String($input.val() || '').trim();
    if (raw === '') return null;
    const parsed = typeof parseMoneyInput === 'function' ? parseMoneyInput(raw) : parseFloat(raw);
    if (isNaN(parsed) || parsed < 0) return null;
    const current = typeof parseMoneyInput === 'function'
        ? parseMoneyInput(String($row.data('amount') || ''))
        : parseFloat(String($row.data('amount') || ''));
    if (!isNaN(current) && Math.abs(parsed - current) < 0.005) return null;
    return { action: 'override_amount', amount: parsed };
}
function lineOverrideRefreshEditButtonRd($row) {
    $row.find('.lo-edit-save').prop('disabled', !lineOverrideEditPlanRd($row));
}
$(document).on('click', '.lo-mount .lo-edit-btn', function () {
    lineOverrideOpenEditorRd($(this).closest('tr.lo-row'));
});
$(document).on('click', '.lo-mount .lo-edit-cancel', lineOverrideCloseEditorRd);
$(document).on('input change', '.lo-mount .lo-edit-input', function () {
    lineOverrideRefreshEditButtonRd($(this).closest('tr.lo-row'));
});
// Bound INSIDE the modal, not on `document` like every other handler in this file. Bootstrap's own
// modal keydown listener sits on the modal element itself, so an event that reaches `document` has
// already passed through it -- stopPropagation() there is too late, and Esc closed the whole
// modal instead of just this editor (caught in a screenshot; the measurement only checked that the
// editor had closed, which it had). Delegating from a node BELOW the modal runs first, which is what
// makes stopPropagation() mean anything here.
// 2026-09-17, D3: bound to #breakdownModalBody -- the static node the table's only remaining mount
// renders into -- instead of the deleted "ปรับตัวเลข" pane. It had to be a static ancestor either
// way: `.lo-mount` itself is re-created on every open.
$(function () {
    $('#breakdownModalBody').on('keydown', '.lo-edit-input', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); lineOverrideCloseEditorRd(); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        e.stopPropagation();
        const $row = $(this).closest('tr.lo-row');
        const plan = lineOverrideEditPlanRd($row);
        if (plan) lineOverrideSendRd($row, plan, $row.find('.lo-edit-save'));
    });
});
$(document).on('click', '.lo-mount .lo-edit-save', function () {
    const $row = $(this).closest('tr.lo-row');
    const plan = lineOverrideEditPlanRd($row);
    if (plan) lineOverrideSendRd($row, plan, $(this));
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
    if (!rows.length) return;
    const tpl = langData['line_override_confirm_restore_all_message'] || '{n} item(s) will go back to their calculated value. Continue?';
    showConfirm({
        title: langData['line_override_confirm_restore_all_title'] || 'Restore calculated values',
        message: tpl.replace('{n}', String(rows.length)),
        tone: 'warning',
        onYes: function () { runRestoreAllComputedRd(rows); },
    });
}
function runRestoreAllComputedRd(rows) {
    const total = rows.length;
    let saved = 0;
    let failedName = null;
    lineOverrideCloseEditorRd();
    setLineOverrideTableBusyRd(true);
    lineOverrideProgressRd(1, total);
    const calls = rows.map(function ($row, i) {
        return function (next) {
            lineOverrideProgressRd(i + 1, total);
            $.ajax({
                url: lineOverrideSaveUrlRd($row, 'remove'),
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
$(document).on('click', '#btnSaveEmpCalcOverride', function () {
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save-employee-exemption`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({
            id: PAYROLL_RUN_ID,
            employee_id: manageLinesEmployeeId,
            tax_calculate_override: $('#empCalcTaxGroup input:checked').val() || 'inherit',
            sso_calculate_override: $('#empCalcSsoGroup input:checked').val() || 'inherit',
        }),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                loadRunDetail();
                // 2026-09-14, Round 3 item 4 batch 1/4: unlike the other 3 batch-save tabs, nothing
                // else here reloads this pane's own radios afterward -- re-baseline explicitly so the
                // footer Save button goes back to disabled.
                refreshAdjustmentTabDirtyGuard('manageLinesCalcPane');
                refreshAdjustmentSaveButtonState();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
$(document).on('change', '.sync-line-exclude-check', function () {
    $(this).closest('.sync-line-controls').find('.sync-line-amount-input').prop('disabled', this.checked);
});
/* ---------- Recurring Deduction Destination override (2026-09-02, Deduction Destination &
   Third-Party Remittance, Phase 6) -- per-run override of which account a recurring deduction
   (Employee Detail's own "Recurring Deductions" section) is routed to, without ever touching that
   employee's own saved template. One shared editor card (#recurringDestEditorCard) reused across
   every row -- avoids initializing a fresh Select2 instance per row, same reasoning
   payroll-run.line-override's own per-row plain-input approach already established for this exact
   modal, just extended to a shared rich sub-form since a payee needs an employee/bank picker, not
   just a number. ---------- */
let recurringDestRows = [];
/* 2026-09-18, tiny-L3 -- the READ-ONLY half of this tab: the per-installment assignments
   (employee_earning_deductions -- Employee Detail's own Payment Items section) that this run routes
   somewhere. Reported for real: an employee whose deduction destinations all live in that table saw
   an empty tab here, because this tab only ever read recurring deductions.
   Deliberately controlless: an EED destination belongs to the assignment, not to a run, so there is
   nothing to override here and nothing in this markup is focusable or dirty-able -- the dirty guard's
   scope (#recurringDestEditorCard, see ADJUSTMENT_TAB_CONFIG_RD) never reaches it. */
let eedDestRows = [];
function eedDestRowHtml(row) {
    const name = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || row.item_code;
    const dest = recurringDestPayeeSummary(row.destination);
    // A plan of several installments says which one this run pays; a single-installment assignment
    // has no sequence worth showing.
    const installment = row.is_installment_plan
        ? (langData['eed_dest_installment'] || 'Installment {no}/{total}')
            .replace('{no}', row.installment_no).replace('{total}', row.total_installments)
        : '';
    return `<div class="eed-dest-row" data-assignment-id="${row.assignment_id}">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div>
                <div class="fw-bold text-dark">${escapeHtml(name)}</div>
                <div class="small">${escapeHtml(langData['recurring_dest_effective'] || 'Currently routed to')}: <strong>${escapeHtml(dest)}</strong></div>
            </div>
            <div class="small text-muted text-end">
                <div class="num">${fmtNum(row.amount)}</div>
                ${installment ? `<div>${escapeHtml(installment)}</div>` : ''}
            </div>
        </div>
    </div>`;
}
function eedDestGroupHtml(rows) {
    if (!rows.length) return '';
    // §6/rules.md: markup JS builds itself reads langData directly -- a data-i18n sweep has already
    // run by the time this is inserted and would never come back to it.
    const url = rows[0].employee_detail_url || '';
    const link = url
        ? ` <a href="${escapeAttr(url)}" target="_blank" rel="noopener">${escapeHtml(langData['eed_dest_open_employee'] || 'Open Employee Detail')}</a>`
        : '';
    return `<div class="small text-muted mt-3 mb-2">${escapeHtml(langData['eed_dest_group_title'] || 'Destinations set on Employee Detail')}${link}</div>
        ${rows.map(eedDestRowHtml).join('')}`;
}
// Both groups come out of ONE response, so this is also the one place that decides whether the tab
// is genuinely empty -- the inline empty line belongs to the tab, not to the editable list, and must
// not appear while the read-only group has rows.
function renderRecurringDestListsRd() {
    $('#recurringDestOverrideList').html(recurringDestRows.length
        ? recurringDestRows.map(recurringDestRowHtml).join('')
        : (eedDestRows.length ? '' : `<div class="text-center text-muted small py-2">${escapeHtml(langData['recurring_dest_empty'] || 'No recurring deductions active for this employee in this pay period.')}</div>`));
    $('#eedDestList').html(eedDestGroupHtml(eedDestRows));
}
// Language switch: re-render the READ-ONLY group only, from the rows already in hand. Re-running the
// loader would fire a second request and re-init the editor card's 3 Select2s underneath the user
// (applyLanguage() already re-inits them once, which is exactly the pattern these rows must not add
// to) -- and the editable list is left alone for the same reason.
function refreshEedDestLanguageRd() {
    $('#eedDestList').html(eedDestGroupHtml(eedDestRows));
}
/* `p` is a payee descriptor -- PayrollRunModel::payeeDestinationDescriptor(), the same shape for a
   template default and for this run's override. 2026-09-17, tiny-L2: every label it reads is the one
   that row's own picker would show (the code is taken off the employee's name the same way the
   picker takes it off, rules.md §5/§6), so the list line, the editor and the dropdown can no longer
   spell the same account 3 ways. */
function recurringDestPayeeSummary(p) {
    if (!p || !p.payee_type) return langData['payee_dest_retained'] || 'Retained by company';
    if (p.payee_type === 'employee') {
        return payeeNameFromLabelRd(p.payee_employee_label_th, p.payee_employee_label_en, '')
            || (langData['payee_dest_employee'] || 'Transfer to another employee');
    }
    // 2026-09-10, Batch 3B item 3: shows WHICH company bank account now, instead of the generic
    // "Company Account" label every 'company' row used to get regardless of which account was
    // chosen -- falls back to an explicit "not specified" wording (never a silent blank) when
    // bank_account_id is genuinely unspecified (legacy data, or before this column existed).
    if (p.payee_type === 'company') {
        const bankLabel = rowOptionLabelRd(p.bank_account_label_th, p.bank_account_label_en, '');
        return bankLabel ? `${langData['payee_dest_retained'] || 'Retained by company'} - ${bankLabel}` : (langData['payee_type_company_unspecified'] || 'Company Account (not specified)');
    }
    if (p.payee_type === 'other_person') {
        return rowOptionLabelRd(p.destination_label_th, p.destination_label_en, '')
            || (langData['payee_dest_external'] || 'Transfer to an external person or organization');
    }
    if (p.payee_type === 'not_disbursed') return langData['payee_type_not_disbursed'] || 'Not Disbursed';
    return p.payee_type;
}
function recurringDestRowHtml(row) {
    const name = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || row.item_code;
    const templateLabel = recurringDestPayeeSummary(row.template);
    const isOverridden = !!row.override;
    const effectiveLabel = isOverridden ? recurringDestPayeeSummary(row.override) : templateLabel;
    return `<div class="border rounded-3 p-2 mb-2" data-recurring-id="${row.recurring_id}">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div>
                <div class="fw-bold text-dark">${escapeHtml(name)}</div>
                <div class="small text-muted">${langData['recurring_dest_template_default'] || 'Template default'}: ${escapeHtml(templateLabel)}</div>
                <div class="small">${langData['recurring_dest_effective'] || 'Currently routed to'}: <strong>${escapeHtml(effectiveLabel)}</strong>${isOverridden ? ` <span class="badge bg-warning-subtle text-warning">${langData['recurring_dest_overridden_badge'] || 'Overridden for this run'}</span>` : ''}</div>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-primary btn-recurring-dest-edit" data-recurring-id="${row.recurring_id}">${isOverridden ? (langData['recurring_dest_change'] || 'Change Override') : (langData['recurring_dest_override'] || 'Override for this run')}</button>
                ${isOverridden ? `<button type="button" class="btn btn-outline-secondary btn-recurring-dest-reset" data-recurring-id="${row.recurring_id}">${langData['recurring_dest_reset'] || 'Reset to template'}</button>` : ''}
            </div>
        </div>
    </div>`;
}
function loadRecurringDeductionDestinationsRd() {
    $('#recurringDestEditorCard').addClass('d-none');
    // The card is being hidden for a DIFFERENT employee's list -- whatever row it was open on has
    // nothing to do with the rows about to arrive, so its pinned options go with it.
    clearRecurringDestRowPinsRd();
    refreshAdjustmentSaveButtonState();
    $.getJSON(`${BASE_URL}/api/payroll-run.recurring-deduction-destinations-for-employee`, { run_id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId }, function (res) {
        if (!res.status) return;
        recurringDestRows = res.data || [];
        // 2026-09-18, tiny-L3: same response, second (read-only) list -- see renderRecurringDestListsRd().
        eedDestRows = res.eed_rows || [];
        renderRecurringDestListsRd();
        // 2026-09-14, Round 3 item 4 batch 1/4: the editor card is hidden right above -- baseline it
        // empty so a stale open-card snapshot from a previous employee never lingers.
        refreshAdjustmentTabDirtyGuard('manageLinesRecurringDestPane');
    });
}
// Same shared picker as the add/edit line form, minus the sub-question: an override always names a
// real payee (removing it is what Reset does), so "retained by company" means 'company' outright --
// see the partial's own $payee_allow_no_record.
$(function () {
    initPayeeDestination('recurringDest', {
        allowNoRecord: false,
        employeeWrap: '#recurringDestEmployeeWrapper',
        companyWrap: '#recurringDestCompanyAccountWrapper',
        externalWrap: '#recurringDestDestinationWrapper',
        onChange: function (payeeType, dest) {
            if (dest !== 'employee') {
                $('#recurringDestPayeeEmployeeSelect').val(null).trigger('change');
            }
            // 2026-09-10, Batch 3B item 3: level-2 for payee_type='company' -- mandatory, same as the
            // other 3 payee-routing editors in this app.
            if (payeeType !== 'company') {
                $('#recurringDestBankAccountSelect').val(null).trigger('change');
            }
            if (dest !== 'external') {
                $('#recurringDestDestinationSelect').val(null).trigger('change');
                $('#recurringDestAccountName, #recurringDestAccountNo, #recurringDestBankBranch').val('');
                $('#recurringDestBank').val(null).trigger('change');
                $('#recurringDestSaveForReuse').prop('checked', false);
                $('#recurringDestDestinationNewFields').removeClass('d-none');
            } else {
                // Manual Entry / Platform UX review Phase 7 -- see applyFirstSavedDestinationDefault()'s
                // own docblock in app.js.
                applyFirstSavedDestinationDefault('#recurringDestDestinationSelect', '#recurringDestDestinationNewFields');
            }
        },
    });
});
function setRecurringDestPayeeType(type) {
    setPayeeDestination('recurringDest', type);
}
/* 2026-09-17, tiny-L2 -- the destination the ROW this card was opened on already points at, in all 3
   of its pickers. Same 3 faults the add/edit line form had (tiny-M, see payeeRowPinnedOptionsRd()'s
   own docblock), same answer: the row's own option is PINNED through initSelect2 instead of being
   hand-built with `new Option`, so (a) it survives applyLanguage()'s re-init, (b) an ad-hoc
   (is_saved = 0) destination -- which payment-destination.options never returns -- can still be
   cleared and chosen again, and (c) the summary box under the picker is filled by prefill itself,
   which programmatic selection never does via select2:select. */
let recurringDestRowPinsRd = { payeeEmployee: null, bankAccount: null, destination: null };
let recurringDestPayeeEmployeeBlockedRd = false;
function renderRecurringDestPayeeEmployeeDetailRd(data) {
    // Same 3-state renderer the line form uses -- including "this employee has no bank account on
    // file", which for this card blocks its Save exactly as it blocks the line form's Add.
    recurringDestPayeeEmployeeBlockedRd = renderPayeeEmployeeDetailRd('#recurringDestPayeeEmployeeDetail', data);
    refreshAdjustmentSaveButtonState();
}
// True while the card is open on a payee who has nowhere for the money to land. Read by the footer's
// own Save button state and by the payload builder's refusal -- the editor's own Save is `d-none`
// (the footer dispatches to it), so disabling that button alone would stop nothing.
function recurringDestSaveBlockedRd() {
    return recurringDestPayeeEmployeeBlockedRd
        && payeeDestinationType('recurringDest') === 'employee'
        && !$('#recurringDestEditorCard').hasClass('d-none');
}
// Puts the row's own 3 options back and describes each underneath. Called LAST when the card opens,
// after setRecurringDestPayeeType() -- whose onChange fires applyFirstSavedDestinationDefault(), and
// that lookup must never be the thing that decides what is in a field the row already filled (it
// re-checks the field before applying, so either arrival order now ends the same way).
function applyRecurringDestRowPinsRd() {
    if (pinRowOptionRd('#recurringDestPayeeEmployeeSelect', recurringDestRowPinsRd.payeeEmployee)) {
        renderRecurringDestPayeeEmployeeDetailRd(recurringDestRowPinsRd.payeeEmployee.data);
    }
    if (pinRowOptionRd('#recurringDestBankAccountSelect', recurringDestRowPinsRd.bankAccount)) {
        renderPayeeAccountDetailRd('#recurringDestBankAccountDetail', recurringDestRowPinsRd.bankAccount.data);
    }
    if (pinRowOptionRd('#recurringDestDestinationSelect', recurringDestRowPinsRd.destination)) {
        renderPayeeAccountDetailRd('#recurringDestDestinationDetail', recurringDestRowPinsRd.destination.data);
        $('#recurringDestDestinationNewFields').addClass('d-none');
    }
}
// Dropped when the card moves to another row (or closes), so one row's destination can never be
// offered as if it belonged to the next.
function clearRecurringDestRowPinsRd() {
    const SELECTORS = {
        payeeEmployee: '#recurringDestPayeeEmployeeSelect',
        bankAccount: '#recurringDestBankAccountSelect',
        destination: '#recurringDestDestinationSelect',
    };
    Object.keys(SELECTORS).forEach(function (key) {
        if (!recurringDestRowPinsRd[key]) return;
        recurringDestRowPinsRd[key] = null;
        unpinRowOptionRd(SELECTORS[key]);
    });
    renderRecurringDestPayeeEmployeeDetailRd(null);
    renderPayeeAccountDetailRd('#recurringDestBankAccountDetail', null);
    renderPayeeAccountDetailRd('#recurringDestDestinationDetail', null);
}
$(document).on('select2:select', '#recurringDestPayeeEmployeeSelect', function (e) {
    renderRecurringDestPayeeEmployeeDetailRd(e.params.data || {});
});
$(document).on('select2:clear', '#recurringDestPayeeEmployeeSelect', function () {
    renderRecurringDestPayeeEmployeeDetailRd(null);
});
$(document).on('select2:select', '#recurringDestBankAccountSelect', function (e) {
    renderPayeeAccountDetailRd('#recurringDestBankAccountDetail', e.params.data);
});
$(document).on('select2:clear', '#recurringDestBankAccountSelect', function () {
    renderPayeeAccountDetailRd('#recurringDestBankAccountDetail', null);
});
$(document).on('select2:select', '#recurringDestDestinationSelect', function (e) {
    $('#recurringDestDestinationNewFields').addClass('d-none');
    renderPayeeAccountDetailRd('#recurringDestDestinationDetail', e.params.data);
});
// Clearing empties the summary and brings the account fields back, but KEEPS the row's own option
// pinned, so the destination this override already had can be chosen again (same rule as the line
// form's own picker).
$(document).on('select2:clear', '#recurringDestDestinationSelect', function () {
    $('#recurringDestDestinationNewFields').removeClass('d-none');
    renderPayeeAccountDetailRd('#recurringDestDestinationDetail', null);
});
$(document).on('click', '.btn-recurring-dest-edit', function () {
    const recurringId = $(this).data('recurring-id');
    const row = recurringDestRows.find(r => r.recurring_id === recurringId);
    if (!row) return;
    $('#recurringDestEditorRecurringId').val(recurringId);
    const name = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || row.item_code;
    $('#recurringDestEditorItemName').text(name);
    // Whatever the row this card was last opened on left pinned is not about this row.
    clearRecurringDestRowPinsRd();
    // An override can never be 'none'/null (that's what Reset achieves) -- if the template itself
    // had no payee at all, default the editor to Company as a neutral starting point, not a guess
    // at what the admin actually wants.
    const current = row.override || $.extend({}, row.template, { payee_type: row.template.payee_type || 'company' });
    // FIRST, before the payee choice below: its onChange can reach
    // applyFirstSavedDestinationDefault() straight away, and that path has to be looking at a card
    // that already knows what this row holds (same ordering rule as prefillManualLineFormRd()).
    recurringDestRowPinsRd = payeeRowPinnedOptionsRd(current);
    // A value this picker cannot show (a legacy 'not_disbursed', or no payee at all) opens on
    // "retained by company", which for this editor means 'company' -- see setPayeeDestination().
    setRecurringDestPayeeType(current.payee_type);
    // LAST, always: the row's own values win over anything the choice above set off.
    applyRecurringDestRowPinsRd();
    // A refusal left over from the row this card was last opened on is not about this row.
    recurringDestFormErrorRd('');
    $('#recurringDestEditorCard').removeClass('d-none');
    // 2026-09-14, Round 3 item 4 batch 1/4: baseline the editor against what it was just populated
    // with (this row's current override/template values), not an empty pre-open state.
    refreshAdjustmentTabDirtyGuard('manageLinesRecurringDestPane');
    refreshAdjustmentSaveButtonState();
});
// Closing the editor is not just hiding it: the tab's own dirty baseline was taken against the row
// this card was opened on (see .btn-recurring-dest-edit above), so leaving that baseline behind
// leaves the tab holding values nobody is going to save.
function closeRecurringDestEditorRd() {
    $('#recurringDestEditorCard').addClass('d-none');
    clearRecurringDestRowPinsRd();
    recurringDestFormErrorRd('');
    refreshAdjustmentTabDirtyGuard('manageLinesRecurringDestPane');
    refreshAdjustmentSaveButtonState();
}
$(document).on('click', '#btnCancelRecurringDestEdit', closeRecurringDestEditorRd);
// RETURNS the refusal instead of showing one, exactly like manualLineFormPayloadRd() below: the
// caller is what knows the message belongs inside the card (§9), not in a dialog on top of the
// values it is about. Every refusal leaves the card open and fires nothing.
function recurringDestFormPayloadRd() {
    const recurringId = $('#recurringDestEditorRecurringId').val();
    const payeeType = payeeDestinationType('recurringDest');
    const payload = { id: PAYROLL_RUN_ID, recurring_id: recurringId, payee_type: payeeType };
    if (payeeType === 'employee') {
        const payeeEmployeeId = $('#recurringDestPayeeEmployeeSelect').val();
        if (!payeeEmployeeId) {
            return { ok: false, message: langData['payee_employee_select_required'] || 'Please select the payee employee.' };
        }
        // 2026-09-17, tiny-L2: a transfer to an employee is paid into THAT employee's own account, so
        // one with none on file has nowhere for this money to land. The server accepts such a row
        // today (it only checks the employee exists), so this is the client-side stop -- the same one
        // the add/edit line form makes, and the reason the footer's Save is disabled while it holds.
        if (recurringDestPayeeEmployeeBlockedRd) {
            return { ok: false, message: langData['payee_employee_no_bank_account'] || 'This employee has no bank account on file yet' };
        }
        payload.payee_employee_id = payeeEmployeeId;
    } else if (payeeType === 'company') {
        // 2026-09-10, Batch 3B item 3: level-2, mandatory -- PayrollRunModel::
        // recurringDeductionDestinationOverrideSave() itself rejects a missing value.
        const bankAccountId = $('#recurringDestBankAccountSelect').val();
        if (!bankAccountId) {
            return { ok: false, message: langData['bank_account_select_required'] || 'Please select a bank account.' };
        }
        payload.bank_account_id = bankAccountId;
    } else if (payeeType === 'other_person') {
        const savedDestinationId = $('#recurringDestDestinationSelect').val();
        if (savedDestinationId) {
            payload.destination_id = savedDestinationId;
        } else {
            const accountName = $('#recurringDestAccountName').val().trim();
            const accountNo = $('#recurringDestAccountNo').val().trim();
            const bankId = $('#recurringDestBank').val();
            if (!accountName || !accountNo || !bankId) {
                return { ok: false, message: langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.' };
            }
            payload.account_name = accountName;
            payload.account_no = accountNo;
            payload.bank_id = bankId;
            payload.bank_branch = $('#recurringDestBankBranch').val().trim() || undefined;
            payload.is_saved = $('#recurringDestSaveForReuse').is(':checked');
        }
    }
    return { ok: true, payload: payload };
}
$(document).on('click', '#btnSaveRecurringDestOverride', function () {
    // 2026-09-17, tiny-L, real bug: 2 of these 3 refusals used to `return { ok: false, ... }` from
    // this click handler -- a value jQuery throws away -- so an override with no employee/account
    // chosen did nothing at all, with no message anywhere. The third one showed a centre-screen
    // dialog over the field it was about, which §9 rules out too. All 3 are one callout now.
    const built = recurringDestFormPayloadRd();
    if (!built.ok) {
        recurringDestFormErrorRd(built.message);
        return;
    }
    const payload = built.payload;
    recurringDestFormErrorRd('');
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.recurring-deduction-destination-override.save`, method: 'POST',
        contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) {
            setButtonLoading($btn, false);
            // A server refusal is the same kind of refusal as the 3 above: it belongs in the card,
            // on the values that were refused, and the card stays open (§9).
            if (!res.status) { recurringDestFormErrorRd(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            closeRecurringDestEditorRd();
            loadRecurringDeductionDestinationsRd();
            loadRunDetail();
        },
        error: function () { setButtonLoading($btn, false); recurringDestFormErrorRd(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
$(document).on('click', '.btn-recurring-dest-reset', function () {
    const recurringId = $(this).data('recurring-id');
    showConfirm(
        langData['recurring_dest_reset'] || 'Reset to template',
        langData['confirm_recurring_dest_reset_message'] || "Revert this recurring deduction back to its own template default for this run?",
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-run.recurring-deduction-destination-override.remove`, method: 'POST',
                contentType: 'application/json', dataType: 'json', data: JSON.stringify({ id: PAYROLL_RUN_ID, recurring_id: recurringId }),
                success: function (res) {
                    if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                    loadRecurringDeductionDestinationsRd();
                    loadRunDetail();
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
            });
        }
    );
});

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
$(function () {
    initPayeeDestination('manualLine', {
        employeeWrap: '#manualLinePayeeWrapper',
        companyWrap: '#manualLineCompanyAccountWrapper',
        externalWrap: '#manualLineDestinationWrapper',
        onChange: function (payeeType, dest) {
            if (dest !== 'employee') {
                $('#manualLinePayeeEmployee').val(null).trigger('change');
                renderManualLinePayeeEmployeeDetailRd(null);
            }
            // 2026-09-10, Batch 3B item 3: the account is mandatory once the line is recorded against
            // one, so the company's own default account is pre-selected rather than left empty.
            if (payeeType !== 'company') {
                $('#manualLineBankAccount').val(null).trigger('change');
                $('#manualLineBankAccountDetail').empty();
            } else {
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
    });
});
function setManualLinePayeeTypeRd(type) {
    setPayeeDestination('manualLine', type);
}
// A transfer to another employee is paid into THAT employee's own bank account, so an employee with
// none on file has nowhere for this money to land. The server accepts such a line today (it only
// checks the employee exists -- see BACKLOG), so this is a client-side stop: the summary line says
// what is missing and Add stays disabled while that employee is selected.
let manualLinePayeeEmployeeBlockedRd = false;
/* 2026-09-17, tiny-L2: the 2 summary-box renderers below take the box they write into, because the
   recurring-deduction destination card (#recurringDestEditorCard) has the same 3 pickers and the
   same need -- a box filled only by select2:select is empty on every prefilled row. Everything that
   differs between the two forms (which box, what a blocked payee does to that form's Save) stays
   with the caller; what a payee account LOOKS like is decided once, here.
   RETURNS whether the endpoint said this employee has no account on file, so the caller can block
   its own save -- the renderer never touches a button itself. */
function renderPayeeEmployeeDetailRd(boxSelector, data) {
    const $box = $(boxSelector);
    if (!data) {
        $box.empty();
        return false;
    }
    const detail = payeeDetailFromOption(data);
    // 3 states, not 2: the endpoint can say there IS an account (render it), say there is NONE
    // (block the add), or -- until api/employee.report_to.get carries the field at all -- say
    // nothing, which must behave exactly as before rather than accusing every employee of having no
    // account. `has_bank_account` is the explicit signal; account data alone is enough on its own.
    const hasAccount = !!(detail.account_no_masked || detail.account_name) || data.has_bank_account === true;
    const knownMissing = data.has_bank_account === false;
    if (hasAccount) {
        $box.html(payeeDetailHtml(detail));
    } else if (knownMissing) {
        $box.html(`<p class="payee-detail-empty" data-i18n="payee_employee_no_bank_account">${langData['payee_employee_no_bank_account'] || 'This employee has no bank account on file yet'}</p>`);
    } else {
        $box.empty();
    }
    return knownMissing;
}
// The plain account summary (a company account, a saved/ad-hoc destination) -- no block to decide.
function renderPayeeAccountDetailRd(boxSelector, data) {
    const $box = $(boxSelector);
    if (!data) {
        $box.empty();
        return;
    }
    $box.html(payeeDetailHtml(payeeDetailFromOption(data)));
}
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
function pinRowOptionRd(selector, pinned) {
    if (!pinned || !pinned.id) return false;
    initSelect2(selector, {
        pinnedOption: { id: pinned.id, text: pinned.text, data: pinned.data, selected: true },
    });
    return true;
}
function unpinRowOptionRd(selector) {
    initSelect2(selector, { pinnedOption: null });
}
/* 2026-09-17, tiny-L2: the row a payee form opens on, turned into the 3 options that can be pinned
   into its 3 pickers -- one builder, because both forms that do this read the SAME field names
   (PayrollRunModel::manualLinesForEmployee() and payeeDestinationDescriptor() deliberately return
   one shape). `text` is the label that row's own options endpoint would have shown, never composed
   here; `data` is exactly what payeeDetailFromOption() reads, so the summary under the picker comes
   out of the same pair of helpers as a hand-picked option's. A payload with no label at all (an
   older shape) falls back to the account/employee name, never to a blank. */
function payeeRowPinnedOptionsRd(row) {
    return {
        payeeEmployee: (row.payee_type === 'employee' && row.payee_employee_id) ? {
            id: row.payee_employee_id,
            text: rowOptionLabelRd(row.payee_employee_label_th, row.payee_employee_label_en,
                row.payee_employee_no || ('#' + row.payee_employee_id)),
            data: {
                account_name: row.payee_employee_account_name,
                bank_name_th: row.payee_employee_bank_name_th,
                bank_name_en: row.payee_employee_bank_name_en,
                bank_branch: row.payee_employee_bank_branch,
                account_no_masked: row.payee_employee_account_no_masked,
                has_bank_account: row.payee_employee_has_bank_account,
            },
        } : null,
        bankAccount: (row.payee_type === 'company' && row.bank_account_id) ? {
            id: row.bank_account_id,
            text: rowOptionLabelRd(row.bank_account_label_th, row.bank_account_label_en,
                row.bank_account_name || ('#' + row.bank_account_id)),
            data: {
                account_name: row.bank_account_name,
                bank_name_th: row.bank_account_bank_name_th,
                bank_name_en: row.bank_account_bank_name_en,
                bank_branch: row.bank_account_branch,
                account_no_masked: row.bank_account_no_masked,
            },
        } : null,
        destination: (row.payee_type === 'other_person' && row.destination_id) ? {
            id: row.destination_id,
            text: rowOptionLabelRd(row.destination_label_th, row.destination_label_en,
                row.destination_account_name || ('#' + row.destination_id)),
            // A destination may be is_saved = 0, i.e. one its own picker endpoint never returns.
            is_saved: row.destination_is_saved,
            data: {
                account_name: row.destination_account_name,
                bank_name_th: row.destination_bank_name_th,
                bank_name_en: row.destination_bank_name_en,
                bank_branch: row.destination_bank_branch,
                account_no_masked: row.destination_account_no_masked,
            },
        } : null,
    };
}
// Picks the label the picker's own endpoint would have shown for this row, in the language on
// screen -- never re-composed here (both come from that endpoint's own builder, server side).
// The payee's name alone for a manual line row: the picker's own label with its code taken off by
// the shared splitter. Falls back to the employee_no only when the payload carries no label at all
// (a row read through an older payload shape), never to a blank.
function payeeNameFromLabelRd(thLabel, enLabel, fallback) {
    const label = rowOptionLabelRd(thLabel, enLabel, '');
    const name = label ? splitOptionCodePrefix(label, 'dash').text : '';
    return name || fallback || '';
}
function manualLinePayeeNameRd(line) {
    return payeeNameFromLabelRd(line.payee_employee_label_th, line.payee_employee_label_en,
        line.payee_employee_no || ('#' + line.payee_employee_id));
}
function rowOptionLabelRd(thLabel, enLabel, fallback) {
    const preferred = currentLang === 'th' ? thLabel : enLabel;
    return preferred || thLabel || enLabel || fallback || '';
}
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
    $('#btnSaveManualLine')
        .prop('disabled', blocked || !(manualLineHasItemRd() && amount > 0))
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

/* ---------- #manageLinesModal ("ตั้งค่ารายบุคคล") shell: footer dispatcher + per-tab dirty-guard
   (2026-09-14, Round 3 item 4 batch 1/4, explicit instruction, §9/§6) ----------
   Reuses the SAME primitives the generic `.modal[data-dirty-guard]` mechanism (app.js, §9) is built
   on -- snapshotFormState()/isFormDirty()/showConfirm()/refreshDirtyGuard(), and the identical
   bypass-flag pattern that stops a confirmed "discard" from re-entering its own handler -- but NOT
   that generic delegated handler itself, deliberately: it snapshots/compares the WHOLE `.modal` once
   at `shown.bs.modal`, which is wrong for this modal on two counts -- (a) `shown.bs.modal` fires
   before this modal's parallel async tab loads land (openManageLinesModal's own click handler
   below), so a whole-modal baseline would be the pre-load (mostly empty) DOM, making freshly-arrived
   server data look "dirty" the instant it renders; (b) each tab has its own distinct save target --
   one whole-modal flag can't express "only Attendance Data has unsaved input". Each tab's own
   baseline is instead captured once THAT tab's own data has actually landed (each loadXRd()'s own
   success callback below calls refreshAdjustmentTabDirtyGuard()), scoped to that tab's own
   save-relevant container, not the whole modal.
   `scope` is deliberately narrower than the whole pane for Recurring Destination
   (#recurringDestEditorCard only, and only actionable while it's open -- `activeOnly` -- matching
   exactly what #btnSaveRecurringDestOverride itself submits).
   2026-09-17, D3: down to the 3 tabs that are per-employee SETTINGS. The 2 that edited figures
   ("รายการจ่าย"/"ปรับตัวเลข") are gone, and with them the only `immediate` entries and the only
   `restoreAllFn`/`saveFn` -- every entry left owns a real (hidden) save button again, so the
   dispatcher is back to one shape. */
const ADJUSTMENT_TAB_CONFIG_RD = {
    manageLinesAttendancePane: { scope: '#manageLinesAttendancePane', saveSelector: '#btnSaveAttendanceData' },
    // `blockedFn` (2026-09-17, tiny-L2): a tab can be dirty and still have nothing valid to save --
    // here, a payee employee with no bank account on file. The footer button is the only visible Save
    // (each tab's own is `d-none`), so this is where such a state has to show up.
    manageLinesRecurringDestPane: {
        scope: '#recurringDestEditorCard', saveSelector: '#btnSaveRecurringDestOverride', activeOnly: true,
        blockedFn: recurringDestSaveBlockedRd, blockedKey: 'payee_employee_no_bank_account',
        blockedFallback: 'This employee has no bank account on file yet',
    },
    manageLinesCalcPane: { scope: '#manageLinesCalcPane', saveSelector: '#btnSaveEmpCalcOverride' },
};
function adjustmentActiveTabConfig() {
    const paneId = $('#manageLinesModal .tab-pane.active').attr('id');
    return ADJUSTMENT_TAB_CONFIG_RD[paneId] || null;
}
// Call once a tab's own data has actually finished loading (or right after its own save succeeds/its
// form is reset) -- re-captures that tab's baseline so it's compared against what's really on the
// server now, not stale pre-load/pre-save state.
function refreshAdjustmentTabDirtyGuard(paneId) {
    const cfg = ADJUSTMENT_TAB_CONFIG_RD[paneId];
    if (cfg) refreshDirtyGuard(cfg.scope);
}
function adjustmentTabIsDirty(cfg) {
    if (!cfg) return false;
    const $scope = $(cfg.scope);
    // 2026-09-17, tiny-L, real bug: an `activeOnly` scope that is CLOSED has nothing to save, so it
    // cannot be dirty either -- refreshAdjustmentSaveButtonState()/saveActiveAdjustmentTab() both
    // checked this already, this one did not, and it is the one the 2 guards ask. Result: open the
    // recurring-destination editor, type, press Cancel (which only hides the card), and both the
    // tab-switch and the modal-close guard kept asking about changes the user had just abandoned.
    if (cfg.activeOnly && $scope.hasClass('d-none')) return false;
    return isFormDirty($scope, $scope.data('dirtyGuardBaseline'));
}
// Footer's single Save button: disabled unless the active tab is actually dirty (Recurring
// Destination only while its inline editor card is open).
// 2026-09-17, D3: the hide-when-there-is-no-target branch and the left-slot branch both went with
// the 2 removed tabs -- every remaining tab has a save target, and the one left-slot button there
// ever was (the line-override table's "คืนค่าระบบทั้งหมด") lives in the Breakdown modal's own
// footer now, see refreshBreakdownFooterStateRd().
function refreshAdjustmentSaveButtonState() {
    const cfg = adjustmentActiveTabConfig();
    const $btn = $('#btnSaveActiveAdjustmentTab');
    if (!$btn.length) return;
    $btn.attr('title', null);
    if (!cfg || (cfg.activeOnly && $(cfg.scope).hasClass('d-none'))) {
        $btn.prop('disabled', true);
        return;
    }
    if (cfg.blockedFn && cfg.blockedFn()) {
        $btn.prop('disabled', true).attr('title', langData[cfg.blockedKey] || cfg.blockedFallback || null);
        return;
    }
    $btn.prop('disabled', !adjustmentTabIsDirty(cfg));
}
// Dispatcher (explicit instruction: map tab -> its EXISTING handler by triggering that handler's own
// button, never re-implement the save call itself) -- the footer's #btnSaveActiveAdjustmentTab is the
// one real caller. Each target button still exists in the DOM (hidden via `d-none`, not removed --
// see detail.php's own comment on each one) specifically so this keeps working unchanged.
function saveActiveAdjustmentTab() {
    const cfg = adjustmentActiveTabConfig();
    if (!cfg) return;
    if (cfg.activeOnly && $(cfg.scope).hasClass('d-none')) return;
    // A disabled button still fires its handlers through .trigger('click'), so the block is checked
    // here too -- the tab's own form says why (recurringDestFormPayloadRd() refuses as well).
    if (cfg.blockedFn && cfg.blockedFn()) return;
    $(cfg.saveSelector).trigger('click');
}
$(document).on('click', '#btnSaveActiveAdjustmentTab', saveActiveAdjustmentTab);
$(document).on('input change', '#manageLinesModal input, #manageLinesModal select, #manageLinesModal textarea', function () {
    refreshAdjustmentSaveButtonState();
});
$(document).on('shown.bs.tab', '#manageLinesModal [data-bs-toggle="tab"]', function () {
    refreshAdjustmentSaveButtonState();
});

// Tab-switch-while-dirty guard -- explicit instruction: hook `show.bs.tab` in the CAPTURE phase (a
// plain native addEventListener, not jQuery delegation) so this intercepts before any other
// bubble-phase handler can assume the switch already happened. Bootstrap 5's Tab.show() dispatches
// `show.bs.tab` on the INCOMING trigger (e.target) with `relatedTarget` = the OUTGOING (currently
// active) trigger, synchronously, before actually swapping panes -- e.preventDefault() here reliably
// cancels the switch (confirmed from Bootstrap's own source: it checks
// `showEvent.defaultPrevented` right after dispatching, same contract already relied on for
// `hide.bs.modal` above in app.js).
let adjustmentTabSwitchBypassPaneId = null;
document.addEventListener('show.bs.tab', function (e) {
    if (!e.target || !e.target.closest || !e.target.closest('#manageLinesModal')) return;
    const outgoingBtn = e.relatedTarget;
    if (!outgoingBtn) return; // first tab shown on modal open -- nothing to leave dirty yet
    const outgoingPaneId = (outgoingBtn.getAttribute('data-bs-target') || '').replace('#', '');
    // Set right before this SAME switch is re-invoked programmatically after a confirmed "switch
    // without saving" below -- without this guard, that 2nd show.bs.tab would just re-enter this
    // handler and prompt a second time, forever (identical shape to app.js's own dirtyGuardBypass).
    if (adjustmentTabSwitchBypassPaneId === outgoingPaneId) {
        adjustmentTabSwitchBypassPaneId = null;
        return;
    }
    const cfg = ADJUSTMENT_TAB_CONFIG_RD[outgoingPaneId];
    if (!adjustmentTabIsDirty(cfg)) return;
    e.preventDefault();
    const targetBtn = e.target;
    showConfirm({
        title: langData['confirm_modal_dirty_title'] || 'You have unsaved changes',
        message: langData['confirm_discard_changes_message'] || "You have changes that haven't been saved yet. If you continue, they will be lost.",
        confirmText: langData['action_switch_tab_without_saving'] || 'Switch tab without saving',
        cancelText: langData['action_back_to_editing'] || 'Back to editing',
        tone: 'warning',
        onYes: function () {
            adjustmentTabSwitchBypassPaneId = outgoingPaneId;
            bootstrap.Tab.getOrCreateInstance(targetBtn).show();
        },
    });
}, true);

// Modal-close-while-dirty guard -- same shape as app.js's own generic `.modal[data-dirty-guard]`
// handler (bypass flag included), bound directly to #manageLinesModal instead of that generic
// delegated one because the dirty check here must be scoped to whichever tab is ACTIVE at the moment
// of close, not the whole modal (see this block's own docblock above for why).
let adjustmentModalCloseBypass = false;
$(document).on('hide.bs.modal', '#manageLinesModal', function (e) {
    if (adjustmentModalCloseBypass) {
        adjustmentModalCloseBypass = false;
        return;
    }
    const cfg = adjustmentActiveTabConfig();
    if (!adjustmentTabIsDirty(cfg)) return;
    e.preventDefault();
    showConfirm({
        title: langData['confirm_modal_dirty_title'] || 'You have unsaved changes',
        message: langData['confirm_discard_changes_message'] || "You have changes that haven't been saved yet. If you continue, they will be lost.",
        confirmText: langData['action_close_without_saving'] || 'Close without saving',
        cancelText: langData['action_back_to_editing'] || 'Back to editing',
        tone: 'warning',
        onYes: function () {
            adjustmentModalCloseBypass = true;
            const inst = bootstrap.Modal.getInstance(document.getElementById('manageLinesModal'));
            if (inst) inst.hide();
        },
    });
});

$(document).on('click', '.btn-manage-manual-lines', function () {
    // Clear every tab's leftover dirty-guard baseline from whatever employee/tab this modal was last
    // open on -- without this, the forced "always reopen on the first tab" switch a few lines below
    // would compare a NEW employee's not-yet-loaded DOM against a STALE baseline from the previous
    // one, which could spuriously show the tab-switch-dirty confirm the instant this modal reopens.
    Object.keys(ADJUSTMENT_TAB_CONFIG_RD).forEach(function (paneId) {
        $(ADJUSTMENT_TAB_CONFIG_RD[paneId].scope).removeData('dirtyGuardBaseline');
    });
    manageLinesEmployeeId = $(this).data('employee-id');
    const rowData = runDetailRowByEmployeeId(manageLinesEmployeeId);
    // 2026-09-11, Batch 3C item 8: employeeHeaderCardHtml() (app.js) block first, no more employee
    // name in the modal-header (#manageLinesEmployeeName removed from the view).
    $('#manageLinesHeaderCard').html(rowData ? employeeHeaderCardHtml(rowData) : '');
    // 2026-09-14, Round 3 item 4 batch 1/4: footer = modalFooterButtonsHtml() -> [Save][Close outline]
    // (§9/§4), same pattern #employeeCommentModal's own footer already established -- rebuilt fresh on
    // every open (constant shape, no readOnly branching needed here unlike Comments' own footer).
    // 2026-09-17, D3: no left slot any more -- the one button that ever used it went to
    // #runDetailBreakdownModal's footer with the table it acts on.
    $('#manageLinesModalFooter').html(modalFooterButtonsHtml({
        primary: { id: 'btnSaveActiveAdjustmentTab', key: 'save', fallback: 'Save' },
        secondary: { key: 'close', fallback: 'Close', dismiss: true },
    }));
    // Always reopen on the first tab -- a stale tab left active from a previous employee would
    // otherwise show up front-and-center unexpectedly.
    bootstrap.Tab.getOrCreateInstance(document.getElementById('manageLinesAttendanceTab')).show();
    refreshAdjustmentSaveButtonState();
    loadAttendanceDataRd();
    // Reset the "Tax & SSO" tab to a neutral state before the fresh fetch below lands, so a stale
    // previous employee's radios never flash for even a moment.
    $('#empCalcTaxInherit, #empCalcSsoInherit').prop('checked', true);
    loadEmployeeExemptionRd();
    loadRecurringDeductionDestinationsRd();
    new bootstrap.Modal(document.getElementById('manageLinesModal')).show();
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
        // The UI's own 3 destinations map onto `payee_type` here, one place, right before submit.
        const payeeType = payeeDestinationType('manualLine');
        if (payeeType !== 'none') {
            payload.payee_type = payeeType;
            // include_in_cash_summary is deliberately NOT sent: the checkbox is gone from this form
            // (nothing reads the column yet -- see BACKLOG), and an absent key is exactly what makes
            // the model keep the column's own default rather than storing an opted-out 0.
        }
        if (payeeType === 'employee') {
            payload.payee_employee_id = $('#manualLinePayeeEmployee').val() || undefined;
        } else if (payeeType === 'company') {
            // 2026-09-10, Batch 3B item 3: level-2, mandatory -- PayrollRunModel::addManualLine()
            // itself rejects a missing value, this is just the payload wiring.
            payload.bank_account_id = $('#manualLineBankAccount').val() || undefined;
        }
        // 2026-09-02, Deduction Destination & Third-Party Remittance -- either an existing saved
        // destination_id, or the new-account fields (validated/created server-side by
        // PaymentDestinationModel::resolveOrCreate(), see addManualLine()'s own docblock).
        if (payeeType === 'other_person') {
            const useSavedDestination = !$('#manualLineDestSavedFields').hasClass('d-none');
            const savedDestinationId = useSavedDestination ? $('#manualLineDestinationSelect').val() : '';
            if (useSavedDestination) {
                if (!savedDestinationId) {
                    return { ok: false, message: langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.' };
                }
                payload.destination = { destination_id: savedDestinationId };
            } else {
                const accountName = $('#manualLineDestAccountName').val().trim();
                const accountNo = $('#manualLineDestAccountNo').val().trim();
                const bankId = $('#manualLineDestBank').val();
                if (!accountName || !accountNo || !bankId) {
                    return { ok: false, message: langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.' };
                }
                payload.destination = {
                    account_name: accountName, account_no: accountNo, bank_id: bankId,
                    bank_branch: $('#manualLineDestBankBranch').val().trim() || undefined,
                    is_saved: $('#manualLineDestSaveForReuse').is(':checked'),
                };
            }
        }
    }
    return { ok: true, payload: payload };
}

/* ---------- The add/edit form (#manualLineFormModal) -- one form, two hosts (2026-09-16, D2) -------
   The form itself is markup in payroll/detail.php, inside a nested modal; this is everything that
   drives it. It opens from the + on a column head (the type comes from WHICH head) or from a row
   (edit). It has always been the only implementation: the "รายการจ่าย" tab's own inline form WAS
   this markup before D2 moved it into a modal of its own, and that tab is gone entirely now (§0.4). */
// Which block the open form belongs to: whose lines, which row (absent = a new one), where the block
// is, and what has to be reloaded once the write lands.
let manualLineFormCtxRd = null;
// The host is resolved from the mount the click happened in, never from a "current block" variable.
// 2026-09-17, D3: there is one `.ml-mount` left (the settings modal's "รายการจ่าย" tab is gone), but
// the lookup stays -- it is what keeps every handler below delegated on the class rather than naming
// a container, which is what let the block move modals at all.
function manualLineHostForMountRd($mount) {
    const id = $mount.attr('id');
    if (id === 'breakdownManualLines' && breakdownRowRd) {
        return {
            mount: '#breakdownManualLines',
            employeeId: breakdownRowRd.employee_id,
            canEdit: employeeRowEditableRd(breakdownRowRd),
            // The net band is re-read from the server rather than adjusted here: one added line moves
            // the statutory figures with it (see refreshBreakdownNetSummaryRd()'s own comment).
            onSaved: function () {
                loadBreakdownManualLinesRd(breakdownRowRd.employee_id);
                refreshBreakdownNetSummaryRd();
                loadRunDetail();
            },
        };
    }
    return null;
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
function recurringDestFormErrorRd(message) {
    formCalloutErrorRd('#recurringDestEditorError', message);
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
function openManualLineFormRd(ctx) {
    manualLineFormCtxRd = ctx;
    const isEdit = !!ctx.line;
    manualLineFormErrorRd('');
    resetManualLineFormRd();
    // 2026-09-17, R1: 4 titles, not 2 -- with the type control gone the header is the only thing
    // left that can say which column this line belongs to, so it says both that and which job is
    // being done ("เพิ่มเงินเพิ่ม"/"แก้ไขเงินหัก"). The type comes from the row when editing and from the
    // pressed column head when adding -- the same 2 sources setManualLineTypeRd() reads.
    const isDeduction = (isEdit ? ctx.line.item_type : ctx.itemType) === 'deduction';
    const titleKey = (isEdit ? 'manual_line_form_edit_' : 'manual_line_form_add_') + (isDeduction ? 'deduction' : 'earning');
    $('#manualLineFormModalLabel')
        .attr('data-i18n', titleKey)
        .text(langData[titleKey] || (isEdit ? 'Edit Item' : 'Add Item'));
    // Built per open, not toggled: the primary button's LABEL is the difference between adding and
    // saving an edit, and modalFooterButtonsHtml() (§9/§11) is what keeps the pair from drifting on
    // size/class. [เพิ่มรายการ|บันทึก] left, [ปิด] right -- §4's order.
    $('#manualLineFormFooter').html(modalFooterButtonsHtml({
        primary: { id: 'btnSaveManualLine', key: isEdit ? 'save' : 'add_line', fallback: isEdit ? 'Save' : 'Add Line' },
        secondary: { key: 'close', fallback: 'Close', dismiss: true },
    }));
    if (isEdit) {
        prefillManualLineFormRd(ctx.line);
    } else {
        setManualLineTypeRd(ctx.itemType);
    }
    refreshManualLineAddStateRd();
    new bootstrap.Modal(document.getElementById('manualLineFormModal')).show();
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
// Adding and editing differ in 2 places only: the endpoint, and one extra id in the body. Everything
// else -- validation, the busy lock, what happens after -- is deliberately one path.
function submitManualLineFormRd($btn) {
    const ctx = manualLineFormCtxRd;
    if (!ctx) return;
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
            const inst = bootstrap.Modal.getInstance(document.getElementById('manualLineFormModal'));
            if (inst) inst.hide();
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
$(document).on('click', '.ml-mount .manual-line-add-btn', function () {
    const host = manualLineHostForMountRd($(this).closest('.ml-mount'));
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
function openManualLineEditRd($mount, lineId) {
    const host = manualLineHostForMountRd($mount);
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
$(document).on('click', '.ml-mount .manual-line-edit-btn', function (e) {
    e.stopPropagation();
    openManualLineEditRd($(this).closest('.ml-mount'), $(this).data('line-id'));
});
$(document).on('click', '.ml-mount .manual-line-remove-btn', function (e) {
    e.stopPropagation();
    const host = manualLineHostForMountRd($(this).closest('.ml-mount'));
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
                    return `<input type="checkbox" class="join-emp-checkbox" data-id="${row.id}" data-employee-no="${escapeHtml(row.employee_no)}" ${checked}>`;
                }
            },
            { data: 'employee_no' },
            { data: null, render: (d, t, row) => escapeHtml((currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '-') },
            { data: 'department', render: d => escapeHtml(d || '-') },
            { data: 'team', render: d => escapeHtml(d || '-') },
            { data: 'position', render: d => escapeHtml(d || '-') },
            { data: 'cycle_name', render: d => escapeHtml(d || '-') },
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
    const employeeIds = Object.keys(joinSelectedEmployees).map(Number);
    if (employeeIds.length === 0) return;
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.join-employees`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_ids: employeeIds }),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('joinEmployeesModal')).hide();
                loadRunDetail();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            setButtonLoading($btn, false);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
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
// 2026-09-14, Round 3 "เก็บตกรอบ 6" item 1 -- Payroll Detail's own missing changeLanguage() hook (see
// renderRunHeaderText()'s own docblock, further up this file, for the full root-cause explanation).
// Registered in app.js's changeLanguage() alongside the other ~6 per-page `refreshXxxLanguage()` hooks
// this app already has for this exact bug class.
function refreshPayrollDetailLanguage() {
    if (currentRun) {
        renderRunHeaderText(currentRun);
    }
    // The Comments modal's own title is built from a `{count}` template in JS (see
    // updateEmployeeCommentTitle()), so the generic `data-i18n` sweep can't relabel it -- re-render it
    // here, the same way every other JS-templated string on this page is handled.
    if ($('#employeeCommentModal').hasClass('show')) {
        updateEmployeeCommentTitle();
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
    // 2026-09-18, tiny-L3: the Recurring Deduction Destination tab's read-only rows are JS-built
    // from a payload that carries both languages, so no data-i18n sweep ever reaches them.
    refreshEedDestLanguageRd();
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
