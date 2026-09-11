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
    });
    return `<span class="text-danger small">${escapeHtml(labels.join(' '))}</span>`;
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
    return { cls, html: `<i class="fa-solid ${icon}"></i><span>${escapeHtml(text)}</span>` };
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
//     computeRunLifecycleProgress() (app.js) above): Approve/Request Info/Reject/Revert at pending_approval
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
    // 2026-08-29, explicit request: "ปุ่ม Timeline และ Approve ควรไปอยู่ที่ Station Approved แล้ว" -- was
    // pinned at i===1 (the "Pending Approval"/ส่งอนุมัติ station itself); moved to i===2 ("Approved")
    // to match computeRunLifecycleProgress() (app.js)'s own fix (see that function's own docblock) -- once
    // submitted, "Pending Approval" is a COMPLETED milestone (shows green/done) and "Approved" is
    // the station representing the NEXT thing to happen, which is where View Timeline/Approve/etc.
    // now consistently live.
    if (i === 2 && run.submitted_at) {
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
            // 2026-08-31, explicit request: "ในหน้าทำรอบจ่าย ให้ตัด Process ของปุ่ม Lock ออก ให้เหลือแค่
            // ปุ่ม Verify" -- pure rename/re-wording, NOT a state-machine change: same endpoint
            // (api/payroll-run.lock), same PayrollRunModel::lock() method, same paid->locked
            // transition -- reopen()'s own locked-vs-paid branching and every other 'locked'-state
            // consumer keep working unchanged, this only relabels the button/confirm copy so the
            // operator sees "Verify" (with an explicit "can't recalculate again" warning) instead
            // of the more technical-sounding "Lock". Uses a NEW key (action_verify_run), NOT a
            // repurposed action_lock -- that key is still legitimately used elsewhere for the
            // UNRELATED per-employee QA Lock toggle (see verifyLockButtonsRd()) and the Process
            // List page's own quick-action shortcut for this SAME run-level action (index.js's
            // miniTimelineQuickActionHtml(), updated to match).
            buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary btn-tl-lock" title="${langData['action_verify_run'] || 'Verify'}"><i class="fa-solid fa-check-double me-1"></i>${langData['action_verify_run'] || 'Verify'}</button>`);
            // 2026-08-29, explicit request: "รายการที่ติ๊กว่าทำจ่ายแล้ว หรือปิดรอบไปแล้ว สามารถเปิดให้กลับมา
            // แก้ไขได้และส่งอนุมัติใหม่ได้ครับ" -- see PayrollRunModel::reopen()'s own docblock.
            buttons.push(`<button type="button" class="btn btn-sm btn-outline-danger btn-tl-reopen" title="${langData['action_reopen'] || 'Reopen for Editing'}"><i class="fa-solid fa-unlock me-1"></i>${langData['action_reopen'] || 'Reopen for Editing'}</button>`);
        } else if ((run.state === 'rejected' || run.state === 'need_info') && run.can_process_payroll) {
            buttons.push(`<button type="button" class="btn btn-sm btn-primary btn-tl-pull-back" title="${langData['action_revise'] || 'Revise'}"><i class="fa-solid fa-pen-to-square me-1"></i>${langData['action_revise'] || 'Revise'}</button>`);
        }
    } else if (i === RUN_LIFECYCLE_STEPS.length - 1 && run.state === 'locked' && run.can_finalize_payroll) {
        // 2026-08-29, explicit request: "ปุ่ม Lock ควรไปอยู่ที่ Lock หลังจากกด Lock แล้วให้ Lock เป็นสีเขียว" --
        // "Locked" is the LAST station with nothing further ahead of it, so unlike every other
        // action button above (which now renders one station AHEAD of the state that unlocks it,
        // matching computeRunLifecycleProgress() (app.js)'s own "reachedIdx=idx" fix), Reopen has nowhere ahead
        // to go -- it renders at the terminal station itself, which is also exactly where that fix
        // makes "Locked" show as done/green the moment this state is reached.
        buttons.push(`<button type="button" class="btn btn-sm btn-outline-danger btn-tl-reopen" title="${langData['action_reopen'] || 'Reopen for Editing'}"><i class="fa-solid fa-unlock me-1"></i>${langData['action_reopen'] || 'Reopen for Editing'}</button>`);
    }
    return buttons.length ? `<div class="tl-actions-row">${buttons.join('')}</div>` : '';
}
function renderProcessTimeline(run) {
    const { steps, currentIndex } = runLifecycleSteps(run, { showDates: true });
    let html = '<ul class="process-timeline">';
    for (let i = 0; i < steps.length; i++) {
        const step = steps[i];
        const dateHtml = (step.cls === 'done' || step.cls === 'current') && step.date
            ? `<span class="tl-date"><i class="fa-regular fa-clock"></i> ${toLocalDateOnlyRd(step.date)}</span>`
            : '';
        const actionsHtml = timelineStepActionsHtml(i, run, currentIndex);
        html += `<li class="tl-step ${step.cls}">
            <span class="tl-icon"><i class="fa-solid ${step.icon}"></i></span>
            <span class="tl-label">${escapeHtml(step.label)}</span>
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
        $('#runReportsTableBody').html(rdReportsRows.map(row => {
            const rowIsReady = row.report_type === 'internal' ? true : stateIsReady;
            const disabledAttr = rowIsReady ? '' : 'disabled';
            return `
            <tr>
                <td><div class="d-flex align-items-center">${rdReportIconTileHtml(row)}${escapeHtml(rdReportLabel(row))}</div></td>
                <td class="text-center">${Number(row.download_count) || 0}</td>
                <td>${row.last_downloaded_at ? formatDisplayDateTime(row.last_downloaded_at) : `<span class="text-muted">${langData['report_never_downloaded'] || 'Never'}</span>`}</td>
                <td class="text-center">
                    <div class="d-flex gap-1 justify-content-center">
                        <button type="button" class="btn btn-link btn-circle-action text-primary btn-report-preview" data-code="${row.code}" ${disabledAttr} title="${rowIsReady ? (langData['report_preview_and_download'] || 'Preview & Download') : notReadyTitle}"><i class="fa-solid fa-download"></i></button>
                        <button type="button" class="btn btn-link btn-circle-action text-secondary btn-report-history" data-code="${row.code}" ${disabledAttr} title="${rowIsReady ? (langData['report_view_history'] || 'View Download History') : notReadyTitle}"><i class="fa-solid fa-clock-rotate-left"></i></button>
                    </div>
                </td>
            </tr>
        `;
        }).join(''));
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
        $('#runCashTableBody').html((data.rows || []).map(row => {
            const name = escapeHtml((currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`).trim());
            const isPaid = row.status === 'paid';
            const badge = isPaid
                ? `<span class="badge bg-success-subtle text-success">${langData['status_paid'] || 'Paid'}</span>`
                : `<span class="badge bg-secondary-subtle text-secondary">${langData['status_unpaid'] || 'Unpaid'}</span>`;
            const paidByName = currentLang === 'th' ? row.paid_by_name_th : row.paid_by_name_en;
            const paidAtCell = isPaid ? `${formatDisplayDateTime(row.paid_at)}${paidByName ? `<div class="text-muted small">${escapeHtml(paidByName)}</div>` : ''}` : '-';
            // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
            // ".btn-circle-action" section) replace the old adjacent .btn-group (and its own former
            // .btn-sm, redundant now that .btn-circle-action sets a fixed 32x32 size itself).
            const actionBtn = isPaid
                ? `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-cash-mark-unpaid" data-id="${row.id}" title="${langData['mark_as_unpaid'] || 'Mark as Unpaid'}"><i class="fa-solid fa-rotate-left"></i></button>`
                : `<button type="button" class="btn btn-link btn-circle-action text-success btn-cash-mark-paid" data-id="${row.id}" title="${langData['mark_as_paid'] || 'Mark as Paid'}"><i class="fa-solid fa-check"></i></button>`;
            return `<tr>
                <td>${escapeHtml(row.employee_no)}</td>
                <td>${name}</td>
                <td class="text-end">${fmtNum(row.amount)}</td>
                <td class="text-center">${badge}</td>
                <td>${paidAtCell}</td>
                <td class="text-center"><div class="d-flex gap-1 justify-content-center">${actionBtn}</div></td>
            </tr>`;
        }).join('') || `<tr><td colspan="6" class="text-center text-secondary py-3">${langData['no_cash_payments'] || 'No cash-paying employees in this run.'}</td></tr>`);
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
        }).join('') || `<tr><td colspan="5" class="text-center text-secondary py-3">${langData['no_cash_payments'] || 'No bank-paying employees in this run.'}</td></tr>`);
    });
}
$(document).on('click', '.btn-bank-account-edit', function () {
    const employeeId = $(this).data('employee-id');
    const row = rdBankAccountRows.find(r => Number(r.employee_id) === Number(employeeId));
    if (!row) return;
    $('#bankAccountAssignEmployeeId').val(employeeId);
    const name = (currentLang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`).trim();
    $('#bankAccountAssignEmployeeName').text(`${row.employee_no} - ${name}`);
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
                <td class="text-end">${fmtNum(row.total_amount)}</td>
                <td class="text-center">${badge}${failedNote}</td>
                <td>${transferredAtCell}</td>
                <td class="text-center"><div class="d-flex gap-1 justify-content-center">${actionBtns}</div></td>
            </tr>`;
        }).join('') || `<tr><td colspan="7" class="text-center text-secondary py-3">${langData['no_remittances'] || 'No third-party remittances for this run.'}</td></tr>`);
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
    $('#autoRecalculateWrap').addClass('d-none');
    $('#recalcReminderBanner').addClass('d-none');
    // 2026-09-09: reset here, BEFORE the early return below, same reason as the 2 lines above it --
    // #btnJoinEmployees/#btnRecalculate/#btnBulkVerify/#btnVerifyAllEmployees all live in the
    // DataTable's own .dt-search/.dt-length now (injected once, outside this function entirely --
    // see initRunDetailTable()'s initComplete), so unlike a plain .empty()-then-rebuild wrap these
    // have to be explicitly hidden every call or they'd keep showing whatever visibility a PREVIOUS
    // call left them at once a run leaves draft. #btnBulkVerify's own ENABLED/disabled state (as
    // opposed to shown/hidden) is a separate concern owned by updateRunDetailBulkBar() instead --
    // untouched here.
    $('#btnJoinEmployees, #btnRecalculate, #btnBulkVerify, #btnVerifyAllEmployees').addClass('d-none');
    if (run.state !== 'draft') {
        return;
    }
    $editWrap.append(`<button type="button" id="btnEditRun" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="action_edit">${langData['action_edit'] || 'Edit'}</span></button>`);
    // 2026-09-09, explicit request across 3 follow-up rounds -- final layout: "เอาคำนวณใหม่ไปวางต่อ
    // search แล้วตามด้วย ปุ่ม Add พนักงาน...แล้วเอาปุ่ม Verify All มาไว้ต่อจาก ตรวจสอบแล้ว" --
    // #btnJoinEmployees/#btnRecalculate/#btnBulkVerify/#btnVerifyAllEmployees no longer live in this
    // header cluster or the old standalone Verify-All row at all; all 4 are injected ONCE into the
    // Employee table's own `.dt-search`/`.dt-length` (initRunDetailTable()'s initComplete, see that
    // function's own comment for the exact left-to-right order), matching this app's own established
    // "Add"-button-in-search-bar convention (CLAUDE.md's Table convention) now that this table
    // finally has real search/length controls. Every one of them is shown on EVERY draft run
    // (2026-08-21/2026-08-31 explicit requests) -- already reset to hidden above (before the early
    // return), so this branch (only reached when run.state === 'draft') just un-hides them again.
    $('#btnJoinEmployees, #btnRecalculate, #btnBulkVerify, #btnVerifyAllEmployees').removeClass('d-none');

    // 2026-08-31, explicit request: auto-recalculate checkbox + reminder banner, draft-only (see
    // PayrollRunModel::setAutoRecalculate()'s own docblock). Checkbox always visible once a run is
    // draft; renderRecalcReminder() (called right below) decides the banner's own visibility.
    $('#autoRecalculateWrap').removeClass('d-none');
    $('#chkAutoRecalculate').prop('checked', !!run.auto_recalculate);
    renderRecalcReminder(run);
}

/**
 * 2026-08-31: the reminder banner is a plain, always-the-same-text nudge -- this run has no way to
 * detect an edit made somewhere ELSE (Employee Detail's salary/PED tab, Setup & Rules, Payroll
 * Configuration, ...), so it can't tell "you changed something, go recalculate" apart from "nothing
 * changed" -- it just reminds every time, unless auto-recalculate is on (in which case there's
 * nothing to remind about, see maybeAutoRecalculateOnLoad() below for what that checkbox actually does).
 */
function renderRecalcReminder(run) {
    const show = run.state === 'draft' && !run.auto_recalculate;
    $('#recalcReminderBanner').toggleClass('d-none', !show).css('display', show ? 'flex' : '');
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
function updateEditRunTypeVisibility() {
    const isIncentive = $('#edit_run_purpose').val() === 'incentive';
    // 2026-09-09, round-creation flow audit Bug 1 fix: #edit_run_use_flat_tax_rate_row added to this
    // same toggle group -- unlike the Create form's own #run_use_flat_tax_rate_row (only revealed for
    // an Origami-attributed tax_treatment='separate' source, see setSupplementalPullMode()), Edit
    // always shows it alongside the other 4 incentive checkboxes so a previously-saved true value is
    // never hidden from view just because this modal wasn't opened via that specific attribution path.
    $('#edit_run_compute_statutory_row, #edit_run_include_base_salary_row, #edit_run_include_standing_items_row, #edit_run_include_attendance_pay_row, #edit_run_use_flat_tax_rate_row').toggleClass('d-none', !isIncentive);
}
$(document).on('change', '#edit_run_purpose', updateEditRunTypeVisibility);

function renderRunHeader(run) {
    currentRun = run;
    // 2026-09-03, Platform UX review Phase 3: document.title used to be set directly here to JUST
    // run.run_name (losing the "Payroll Process —" breadcrumb prefix and the app suffix entirely) --
    // app.js's own MutationObserver on .payroll-breadcrumb now derives the full title automatically
    // the moment #bcRunName's text changes below, so this no longer needs (or should) set it itself.
    $('#bcRunName').text(run.run_name);
    $('#runNameHeading').text(run.run_name);
    $('#runStateBadge').html(stateBadgeRd(run.state));
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
            <span class="apv-substep-label">${apvAvatarHtml(name, 22, a.profile_photo_path)}${escapeHtml(name)}</span>
            ${apvBadgeHtml(apvApproverToneRd(a.status), apvApproverLabelRd(a.status))}
        </div>
        ${a.acted_at ? `<div class="apv-substep-date"><i class="fa-regular fa-calendar"></i> ${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(a.acted_at) : escapeHtml(a.acted_at)}</div>` : ''}
        ${a.note ? `<div class="apv-substep-remark">${escapeHtml(a.note)}</div>` : ''}
    </div>`;
}
// 2026-09-10, Batch 3A item 2: moved to app.js's own apvApprovalStageInfo() (shared with
// index.js/approval.js's own identical copies).
// 2026-08-30, explicit follow-up ("ยังไม่ได้ปรับ UI...ให้แสดงหลาย step ที่ actionable พร้อมกันแบบจุดๆ ว่า
// ตัวเองอยู่ตำแหน่งไหน และตำแหน่งก่อนหน้านั้นอนุมัติหรือยัง") -- see index.js's own equivalent comment for
// the full reasoning (mirrored here per this file's own "duplicate, don't share across pages" convention).
function apvStepDotToneRd(step) {
    if (!step.unlocked) return 'apv-step-dot-locked';
    if (step.status === 'approved') return 'apv-step-dot-approved';
    if (step.status === 'rejected') return 'apv-step-dot-rejected';
    return 'apv-step-dot-pending';
}
function apvStepDotsHtmlRd(steps) {
    return `<div class="apv-step-dots">` + steps.map((s, i) => {
        const lockIcon = !s.unlocked ? `<span class="apv-step-dot-lock-icon"><i class="fa-solid fa-lock"></i></span>` : '';
        const icon = s.status === 'approved' ? '<i class="fa-solid fa-check"></i>' : (s.status === 'rejected' ? '<i class="fa-solid fa-xmark"></i>' : s.step_order);
        const connector = i < steps.length - 1 ? `<div class="apv-step-dot-connector${s.status === 'approved' ? ' apv-step-dot-connector-done' : ''}"></div>` : '';
        return `<div class="apv-step-dot-wrap" title="${escapeHtml(s.step_name || '')}">
            <div class="apv-step-dot ${apvStepDotToneRd(s)}">${icon}</div>
            ${lockIcon}
        </div>${connector}`;
    }).join('') + `</div>`;
}
function apvStepGroupHtmlRd(step) {
    const badgeHtml = !step.unlocked
        ? `<span class="apv-badge" style="background:#f1f5f9;color:#64748b;"><i class="fa-solid fa-lock me-1"></i>${langData['step_locked'] || 'Locked'}</span>`
        : apvBadgeHtml(apvApproverToneRd(step.status), apvApproverLabelRd(step.status));
    const stepLabel = (langData['step_label'] || 'Step {n}').replace('{n}', step.step_order);
    const approversHtml = step.approvers.length
        ? step.approvers.map(apvApproverSubstepHtmlRd).join('')
        : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`;
    return `<div class="apv-step-group">
        <div class="apv-step-group-head">
            <span class="apv-step-group-title">${escapeHtml(stepLabel)}${step.step_name ? ': ' + escapeHtml(step.step_name) : ''}</span>
            ${badgeHtml}
        </div>
        <div class="apv-step-group-body">${approversHtml}</div>
    </div>`;
}
function apvApprovalStageHtmlRd(run) {
    const info = apvApprovalStageInfo(run.state);
    const steps = (run.approval_flow && run.approval_flow.steps) || null;
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    const bodyHtml = (steps && steps.length)
        ? apvStepDotsHtmlRd(steps) + steps.map(apvStepGroupHtmlRd).join('')
        : (approvers.length
            ? approvers.map(apvApproverSubstepHtmlRd).join('')
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
// 2026-09-10, Batch 3A item 3: apvPaidStageHtmlRd() (a single merged Paid/Locked box that never
// showed who paid/locked) split into app.js's own apvPaidStageHtml()/apvLockedStageHtml(), each
// pulling tone/label/date from runLifecycleSteps() (item 2) instead of re-deriving run.state here.
// apvCreatedStageHtmlRd() also moved to app.js's own apvCreatedStageHtml() -- see
// renderRunTimelineModal() below for the new call sites.
function renderAuditTimelineRd(logs) {
    if (!logs || !logs.length) {
        return `<div class="text-secondary small">${langData['no_history_yet'] || 'No action has been taken on this request yet.'}</div>`;
    }
    const ordered = logs.slice().reverse(); // newest first at the top, oldest at the bottom
    return ordered.map(l => {
        const actor = personDisplayNameRd(l, 'performed_by');
        const metaParts = [];
        if (l.ip_address) metaParts.push(`<i class="fa-solid fa-location-dot"></i> ${escapeHtml(l.ip_address)}`);
        if (l.user_agent) metaParts.push(`<i class="fa-solid fa-desktop"></i> ${escapeHtml(l.user_agent)}`);
        return `<div class="apv-log-entry">
            <div class="apv-log-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(l.performed_at) : escapeHtml(l.performed_at)}</div>
            <div class="apv-log-action">${escapeHtml(auditActionLabel(l.action))} <span class="text-secondary fw-normal">(${escapeHtml(actor)})</span></div>
            ${metaParts.length ? `<div class="apv-log-meta">${metaParts.join(' &nbsp; ')}</div>` : ''}
            ${l.note ? `<div class="apv-log-note">${escapeHtml(l.note)}</div>` : ''}
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
    // 2026-09-10, Batch 3A item 3: 2 new stations (Locked, on top since it's the newest event --
    // same newest-first ordering the other stages already use) -- lifecycle computed ONCE and
    // passed to both Paid/Locked so they don't each re-derive the 5-step progress.
    const lifecycle = runLifecycleSteps(run, { showDates: true });
    $('#runTimelineModalBody').html(`
        <div class="apv-timeline">
            ${apvLockedStageHtml(run, lifecycle)}
            ${apvPaidStageHtml(run, lifecycle)}
            ${apvApprovalStageHtmlRd(run)}
            ${apvCreatedStageHtml(run)}
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
function manageItemsButtonRd(row) {
    if (!currentRun || currentRun.state !== 'draft') {
        return '';
    }
    return `<li><button type="button" class="dropdown-item btn-manage-manual-lines" data-employee-id="${row.employee_id}"><i class="fa-solid fa-list-check text-primary me-2"></i>${langData['action_manage_items'] || 'Items'}</button></li>`;
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
function verifyLockButtonsRd(row) {
    if (!currentRun || currentRun.state !== 'draft') {
        return row.is_verified
            ? `<span class="badge bg-success-subtle text-success" title="${langData['verify_status_verified'] || 'Verified'}"><i class="fa-solid fa-check-double"></i></span>`
            : '<span class="text-muted">-</span>';
    }
    // 2026-09-10, real gap found and fixed (explicit report: "ก่อน/หลัง verify ต่างกันแค่สีไอคอน มองไม่
    // ออก") -- the 2026-09-09 .btn-circle-action version below only ever differed by icon color
    // (text-success/text-secondary), invisible in grayscale/for anyone who can't rely on color alone.
    // This ONE button (not the row's other action buttons) steps back out of .btn-circle-action's
    // icon-only convention to add a real text label + filled-vs-outline shape, both of which survive
    // grayscale: unverified is btn-outline-secondary + "action_verify" ("ตรวจสอบ"), verified is a
    // filled btn-success + "verify_status_verified" ("ตรวจสอบแล้ว", the SAME key the read-only
    // (non-draft) branch above already uses for the identical concept). data-verified/data-employee-id
    // and the .btn-verify-employee click handler are unchanged.
    const verifyTitle = row.is_verified ? (langData['action_unverify'] || 'Unverify') : (langData['action_verify'] || 'Verify');
    const verifyLabel = row.is_verified ? (langData['verify_status_verified'] || 'Verified') : (langData['action_verify'] || 'Verify');
    const verifyBtnCls = row.is_verified ? 'btn-success' : 'btn-outline-secondary';
    // 2026-09-11, Batch 3C item 9: data-employee-name feeds the confirm dialog's own "{name}"
    // placeholder (see the .btn-verify-employee click handler) -- avoids a round trip back through
    // the DataTable row data at click time.
    return `<div class="d-flex gap-1 justify-content-center">
        <button type="button" class="btn btn-sm ${verifyBtnCls} rounded-pill btn-verify-employee" data-employee-id="${row.employee_id}" data-employee-name="${escapeAttr(employeeDisplayNameRd(row))}" data-verified="${row.is_verified ? 'true' : 'false'}" title="${verifyTitle}"><i class="fa-solid fa-check-double me-1"></i>${escapeHtml(verifyLabel)}</button>
    </div>`;
}
// Comment always available (any state) -- same reasoning as the Breakdown button (read-only/non-
// destructive, "ไว้เตือนตัวเอง" -- a reminder note is useful regardless of where the run currently is).
// 2026-08-29: "ถ้ามีการใส่ Comment ไปกี่ Comment แล้วให้แสดงตัวเลขที่ปุ่ม Comment ด้วยเป็นจุดแดงๆเหมือนการ
// แจ้งเตือน" -- a small red notification-dot badge showing the current comment count, read from
// row.comment_count (see initRunDetailTable()'s ajax/data source -- PayrollRunModel::getDetails()
// now includes it per employee). Re-rendered after every add/edit/delete via loadRunDetail(), same
// refresh pattern every other mutating action on this page already uses.
function commentButtonRd(row) {
    const label = `${escapeAttr(row.employee_no)} - ${escapeAttr(employeeDisplayNameRd(row))}`;
    const count = Number(row.comment_count || 0);
    const countBadge = count > 0
        ? `<span class="badge rounded-pill bg-danger ms-2">${count}</span>`
        : '';
    return `<li><button type="button" class="dropdown-item btn-comment-employee" data-employee-id="${row.employee_id}" data-employee-label="${label}"><i class="fa-solid fa-comments text-warning me-2"></i>${langData['action_comments'] || 'Comments'}${countBadge}</button></li>`;
}
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group/border-start convention this
// whole cluster previously followed (2026-08-21/29).
// 2026-09-10, Batch 2 item 7: only View Breakdown stays a standalone button; the other 4
// (conditionally present) collapse into one dropdown menu -- click handlers below still bind by
// the same classes (.btn-manage-manual-lines/.btn-raw-sync-data/.btn-comment-employee/
// .btn-remove-manual-employee) so nothing needed to change there.
function runDetailActionsRd(row) {
    const topItems = [rawSyncDataButtonRd(row), manageItemsButtonRd(row), commentButtonRd(row)].filter(Boolean);
    const removeItem = removeEmployeeButtonRd(row);
    // 2026-09-10, explicit request: Remove sits at the bottom with a divider above it, only when
    // there's actually something above it to divide from.
    const divider = (topItems.length && removeItem) ? '<li><hr class="dropdown-divider"></li>' : '';
    const items = topItems.join('') + divider + removeItem;
    const menu = items
        ? `<div class="dropdown">
            <button type="button" class="btn btn-link btn-circle-action text-secondary dropdown-toggle" data-bs-toggle="dropdown" title="${langData['action_more'] || 'More'}"><i class="fa-solid fa-ellipsis-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">${items}</ul>
        </div>`
        : '';
    // 2026-09-09, explicit request: "ใน column สุดท้ายของแต่ละแถว ปุ่มให้เรียงเป็นแถวเดียวห้ามตกบรรทัด" --
    // flex-nowrap keeps this cluster on one line always -- .rd-detail-table-flush's own min-width +
    // the table's existing .table-responsive wrapper (unchanged) is the fallback that lets the
    // whole table scroll horizontally instead, same "no DataTables scrollX" convention this app
    // already established elsewhere.
    return `<div class="d-flex gap-1 justify-content-center flex-nowrap">
        <button type="button" class="btn btn-link btn-circle-action text-info btn-view-breakdown" data-employee-id="${row.employee_id}" title="${langData['action_view_breakdown'] || 'View Breakdown'}"><i class="fa-solid fa-magnifying-glass-dollar"></i></button>
        ${menu}
    </div>`;
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
    return `<div class="mt-2 pt-2 border-top fw-bold text-brand">${langData['formula_result'] || 'Result'}: ${fmtNum(amount)}</div>`;
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
    return `<button type="button" class="btn btn-sm btn-link p-0 ms-1 text-brand formula-info-btn" data-bs-toggle="popover" data-bs-trigger="hover click" data-bs-html="true" data-bs-placement="top" data-bs-title="${langData['formula_popover_title'] || 'How this was calculated'}" data-bs-content="${contentAttr}"><i class="fa-solid fa-circle-question"></i></button>`;
}
/* ---------- Breakdown modal (section 2/3's table doesn't itemize -- it only shows totals): per-
   employee itemized view split into clearly-labeled Earnings / Deductions (Items) / Deductions
   (Statutory) sections, so which line is income vs. a deduction is never ambiguous. ---------- */
function breakdownLineRowsRd(lines) {
    return (lines || []).map(line => {
        const name = (currentLang === 'th' ? line.name_th : line.name_en) || line.name_th || line.name_en || '';
        const commentHtml = line.note ? `<div class="small text-muted fst-italic"><i class="fa-regular fa-comment me-1"></i>${escapeHtml(line.note)}</div>` : '';
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
        let codeHtml;
        if (line.source === 'transfer_in') {
            codeHtml = `<span class="badge bg-info-subtle text-info"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['transfer_in_badge'] || 'Transfer'}</span>`;
        } else if (line.is_custom && line.is_other) {
            // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7.
            codeHtml = `<span class="badge bg-info-subtle text-info"><i class="fa-solid fa-circle-question me-1"></i>${langData['manual_line_other_badge'] || 'Other'}</span>`;
        } else if (line.is_custom) {
            codeHtml = `<span class="badge bg-secondary-subtle text-secondary"><i class="fa-solid fa-pen me-1"></i>${langData['manual_line_custom_badge'] || 'Custom'}</span>`;
        } else {
            codeHtml = `<code class="fw-bold text-dark">${escapeHtml(line.code || '-')}</code>`;
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
                ? `<div class="small text-muted"><i class="fa-solid fa-building me-1"></i>${langData['payee_type_company'] || 'Company Account'}</div>`
                : `<div class="small text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['payee_bank_account_needs_review'] || 'Company Account -- bank account not specified, needs review'}</div>`;
        } else if (line.payee_type === 'other_person') {
            // 2026-09-02, Deduction Destination & Third-Party Remittance -- this line shape has no
            // resolved destination_account_name (that LEFT JOIN only exists in
            // manualLinesForEmployee()'s own dedicated query, not the persisted breakdown JSON), so
            // a generic label is shown here, same "no specific detail" treatment 'company' already gets.
            payeeHtml = `<div class="small text-muted"><i class="fa-solid fa-building-columns me-1"></i>${langData['payee_type_other_person'] || 'Other Person / Third Party'}</div>`;
        } else if (line.payee_type === 'not_disbursed') {
            payeeHtml = `<div class="small text-muted"><i class="fa-solid fa-ban me-1"></i>${langData['payee_type_not_disbursed'] || 'Not Disbursed'}</div>`;
        }
        const exemptBadge = line.is_exempted ? `<span class="badge bg-warning-subtle text-warning-emphasis ms-1">${langData['attendance_deduction_exempted_badge'] || 'Exempted'}</span>` : '';
        return `<tr class="${line.is_exempted ? 'text-muted' : ''}">
            <td>${codeHtml}</td>
            <td>${escapeHtml(name)}${exemptBadge}${formulaButtonRd(line)}${commentHtml}${exemptedHtml}${payeeHtml}</td>
            <td class="text-end">${fmtNum(line.amount)}</td>
        </tr>`;
    }).join('');
}
function statutoryRowsRd(items) {
    // 2026-08-21, real bug fix (explicit report: "แสดงแค่ Code อยากให้มีชื่อด้วย") -- name_th/
    // name_en now come through from StatutoryCalculationEngine::calculateLine(), same pattern as
    // breakdownLineRowsRd() already uses for earning/deduction lines just above.
    return (items || []).map(item => {
        const name = (currentLang === 'th' ? item.name_th : item.name_en) || item.name_th || item.name_en || '';
        const note = item.note ? ` <span class="text-muted small">(${escapeHtml(item.note)})</span>` : '';
        return `<tr>
            <td><code class="fw-bold text-dark">${escapeHtml(item.code || '-')}</code>${note}</td>
            <td>${escapeHtml(name)}${formulaButtonRd(item)}</td>
            <td class="text-end">${fmtNum(item.employee_amount)}</td>
        </tr>`;
    }).join('');
}
function breakdownSectionHtml(iconCls, colorCls, titleKey, titleFallback, rawRowsHtml, totalLabel, totalAmount, options) {
    // 2026-09-10, Batch 3B item 1, explicit request: a section with ZERO line items (e.g.
    // "Deductions (Items)" when nobody has any ad-hoc deduction this period) hides its WHOLE block
    // -- header, table, AND total row -- instead of showing an empty table with a "-" placeholder
    // row. Earnings/Net Pay are the one deliberate exception (renderBreakdownModal()'s own call
    // passes { alwaysShow: true }) -- the employee must always be able to see "this period
    // genuinely has zero income," never have that section silently vanish and look like a bug.
    const alwaysShow = !!(options && options.alwaysShow);
    if (!rawRowsHtml && !alwaysShow) {
        return '';
    }
    const rowsHtml = rawRowsHtml || `<tr><td colspan="3" class="text-center text-muted small py-2">-</td></tr>`;
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
                        <td colspan="2">${escapeHtml(totalLabel)}</td>
                        <td class="text-end">${fmtNum(totalAmount)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;
}
function renderBreakdownModal(row) {
    $('#breakdownEmployeeName').text(`${row.employee_no} - ${employeeDisplayNameRd(row)}`);
    // 2026-09-06, explicit request: Origami's opt-in TOTAL_DAYS item_values entry (calendar-based
    // day count) -- row.total_days is null (see PayrollRunModel::getDetails()'s own docblock) for
    // every run/employee with no data, never 0, so a plain truthiness-adjacent null check is
    // correct here (0 would be a real, displayable value if it ever happened).
    const $totalDays = $('#breakdownTotalDays');
    if (row.total_days !== null && row.total_days !== undefined) {
        $totalDays.text(`${langData['total_days'] || 'Total Days'}: ${fmtNum(row.total_days)}`).removeClass('d-none');
    } else {
        $totalDays.addClass('d-none').text('');
    }

    let earningRowsHtml = '';
    if (Number(row.base_salary_amount) > 0) {
        earningRowsHtml += `<tr>
            <td><code class="fw-bold text-dark">BASE</code></td>
            <td>${escapeHtml(langData['table_base_salary'] || 'Base Salary')}</td>
            <td class="text-end">${fmtNum(row.base_salary_amount)}</td>
        </tr>`;
    }
    earningRowsHtml += breakdownLineRowsRd(row.earning_breakdown);

    const statutoryTotal = (row.statutory_breakdown || []).reduce((sum, item) => sum + (Number(item.employee_amount) || 0), 0);

    const html = breakdownSectionHtml('fa-arrow-trend-up', 'text-success', 'breakdown_earnings', 'Earnings', earningRowsHtml, langData['table_gross_amount'] || 'Gross', row.gross_amount, { alwaysShow: true })
        + breakdownSectionHtml('fa-arrow-trend-down', 'text-danger', 'breakdown_deductions', 'Deductions (Items)', breakdownLineRowsRd(row.deduction_breakdown), langData['breakdown_deductions_total'] || 'Deductions (Items) Total', (row.deduction_breakdown || []).reduce((sum, l) => sum + (Number(l.amount) || 0), 0))
        + breakdownSectionHtml('fa-landmark', 'text-danger', 'breakdown_statutory', 'Deductions (Statutory)', statutoryRowsRd(row.statutory_breakdown), langData['breakdown_statutory_total'] || 'Deductions (Statutory) Total', statutoryTotal);
    $('#breakdownModalBody').html(html);
    // Net Pay lives in the modal-footer now (2026-08-20, explicit request), not the scrollable
    // body -- always visible without scrolling past the itemized sections.
    $('#breakdownModalNetPay').text(fmtNum(row.net_amount));
    // Bootstrap popovers need explicit per-element initialization (no data-attribute auto-init in
    // this app, see formulaButtonRd()'s own docblock) -- dispose any from a previous employee's
    // render first (the DOM nodes they were attached to are already gone via .html() above, but the
    // Popover instances themselves would otherwise leak) before initializing the fresh set.
    $('#breakdownModalBody .formula-info-btn').each(function () {
        const existing = bootstrap.Popover.getInstance(this);
        if (existing) existing.dispose();
        new bootstrap.Popover(this);
    });
}
$(document).on('click', '.btn-view-breakdown', function () {
    const employeeId = $(this).data('employee-id');
    const rowData = (tb_run_detail ? tb_run_detail.rows().data().toArray() : []).find(r => Number(r.employee_id) === Number(employeeId));
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
    const rowData = (tb_run_detail ? tb_run_detail.rows().data().toArray() : []).find(r => Number(r.employee_id) === Number(employeeId));
    $('#empAdjustmentsEmployeeName').text(rowData ? `${rowData.employee_no} - ${employeeDisplayNameRd(rowData)}` : '');
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
    // line; Bank/Cash now each get the SAME accent color their own badge already uses elsewhere on
    // this page (payment-method column) so the two figures read apart from each other at a glance
    // instead of blending into one plain-text line.
    $('#infoPaymentBreakdown').html(`<span class="text-info-emphasis fw-semibold"><i class="fa-solid fa-building-columns me-1"></i>${bankLabel} ${bankCount}</span><span class="mx-2 text-muted">·</span><span class="text-warning-emphasis fw-semibold"><i class="fa-solid fa-money-bill-wave me-1"></i>${cashLabel} ${cashCount}</span>`);
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
function registerPaymentMethodSearchFilter() {
    if (paymentMethodSearchFilterRegistered) return;
    paymentMethodSearchFilterRegistered = true;
    $.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex, rowData) {
        if (!settings.nTable || settings.nTable.id !== 'tb_run_detail') return true;
        const bankOn = $('#filterPaymentBank').is(':checked');
        const cashOn = $('#filterPaymentCash').is(':checked');
        const code = (rowData && rowData.payment_method_code) || 'transfer';
        // 2026-09-02, follow-up: 'mixed' passes the filter if EITHER checkbox is on (see
        // updatePaymentMethodSummary()'s own comment on why it isn't forced into just one bucket).
        return (isBankishPaymentMethod(code) && bankOn) || (isCashishPaymentMethod(code) && cashOn);
    });
}
// Confirmed via AskUserQuestion: 2 independent checkboxes, both checked by default (show everyone);
// unticking one hides that group; unticking BOTH is disallowed -- falls back to Bank rather than
// letting the table go empty with no visible way back in.
// 2026-09-02, same-day follow-up: "ให้เลือกทั้งหมดได้ด้วย" -- #filterPaymentAll is a plain select-all
// convenience, not a 3rd filter state: checking it ticks both Bank/Cash, unchecking it clears both
// (re-guarded right back to Bank-only by the same "never let both end up unchecked" rule below).
// The actual DataTables search predicate (registerPaymentMethodSearchFilter()) still only ever
// reads filterPaymentBank/filterPaymentCash directly, so this stays a pure client-side .draw() --
// no ajax, no data reload, same as before.
$(document).on('change', '#filterPaymentAll', function () {
    const checked = $(this).is(':checked');
    $('#filterPaymentBank, #filterPaymentCash').prop('checked', checked);
    if (!checked) $('#filterPaymentBank').prop('checked', true);
    if (tb_run_detail) tb_run_detail.draw();
});
$(document).on('change', '#filterPaymentBank, #filterPaymentCash', function () {
    if (!$('#filterPaymentBank').is(':checked') && !$('#filterPaymentCash').is(':checked')) {
        $('#filterPaymentBank').prop('checked', true);
    }
    $('#filterPaymentAll').prop('checked', $('#filterPaymentBank').is(':checked') && $('#filterPaymentCash').is(':checked'));
    if (tb_run_detail) tb_run_detail.draw();
});

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
    // 2026-09-09: no longer called directly here with the FULL, unfiltered `details` array -- see
    // updateSummaryCardsFromTable()'s own docblock (called from drawCallback below instead, which
    // also fires right after this function's own initial construction/reload, so the first paint is
    // unaffected -- only every subsequent filter/redraw now also gets it right).
    $('#noDetailsYet').toggleClass('d-none', details.length > 0);
    $('#tb_run_detail').toggleClass('d-none', details.length === 0);
    // 2026-08-29, real bug found and fixed (explicit report: "checkbox ในกรณีที่ส่งไปอนุมัติแล้วยังขึ้นอยู่
    // ต้องไม่ขึ้น") -- computed HERE, synchronously, from the SAME currentRun that
    // renderRunHeader() always sets immediately before this function runs (see loadRunDetail()),
    // rather than inside drawCallback's own applyRunDetailViewMode() reading the outer
    // `tb_run_detail` variable. Root cause: drawCallback fires synchronously DURING the
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
    // own docblock), NOT the per-employee base_salary_excluded flag -- this hides the WHOLE column
    // for every row on the run, not row-by-row (data_source genuinely doesn't apply to a run that
    // never brings base salary into the calculation at all).
    const showDataSourceColumn = !currentRun || currentRun.run_purpose !== 'incentive' || !!currentRun.include_base_salary;
    if ($.fn.DataTable.isDataTable('#tb_run_detail')) {
        const existingApi = $('#tb_run_detail').DataTable();
        const existingCheckboxColumn = existingApi.column(0);
        if (existingCheckboxColumn.visible() !== showCheckboxColumn) {
            existingCheckboxColumn.visible(showCheckboxColumn, false);
        }
        const existingDataSourceColumn = existingApi.column(3);
        if (existingDataSourceColumn.visible() !== showDataSourceColumn) {
            existingDataSourceColumn.visible(showDataSourceColumn, false);
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
    tb_run_detail = $('#tb_run_detail').DataTable({
        responsive: false,
        data: details,
        columns: [
            // 2026-08-29, explicit request: "สามารถมี checkbox เลือกได้ทีละหลายคนในการ Verify และ Lock"
            // -- 2026-08-29 (View Mode follow-up): the checkbox column has no purpose once nothing on
            // this run can be verified/locked/bulk-actioned anymore -- visible: showCheckboxColumn
            // (computed just above from currentRun.state, see this function's own top-of-function
            // comment for why it's set HERE at construction time and not inside drawCallback).
            { data: null, className: 'text-center', orderable: false, visible: showCheckboxColumn, render: (d, t, row) => `<input type="checkbox" class="form-check-input run-detail-row-check" data-employee-id="${row.employee_id}">` },
            // 2026-08-29, explicit follow-up request (own earlier suggestion, accepted): "มีไอคอน
            // เล็กๆ บนแถวพนักงานที่บอกว่าคนนี้ถูกปรับแต่งอะไรไปแล้วบ้าง" -- shown here (not tied to the
            // "Items" button, which disappears entirely once the run leaves draft -- see
            // manageItemsButtonRd()) so the indicator stays visible for a locked/paid/approved run
            // too, when knowing "was this person customized" matters most. Comment count already
            // gets its own red-dot badge on the Comment button itself (commentButtonRd()) -- not
            // repeated here to avoid saying the same thing twice.
            // 2026-09-02, explicit request: "ตารางพนักงาน แยก code และชื่อคนละ Column Code อยู่ก่อน" --
            // was one combined 2-line cell (name bold on top, code muted underneath); split into its
            // own Code column (badges moved here, since it's the leftmost/anchor column now) and a
            // separate plain Name column right after it.
            { data: 'employee_no', orderable: false, render: (d, t, row) => {
                const badges = [];
                // 2026-09-10, Batch 3A item 5, explicit request: replace the fa-sliders icon (which
                // only ever hinted "something changed," no detail) with a text badge showing HOW
                // MANY items were adjusted (overrides + ad-hoc added items combined -- see
                // PayrollRunModel::employeeAdjustments()'s own docblock), clickable to open a
                // view-only modal listing each one (item/old value/new value/who/when).
                const adjustedCount = Number(row.line_override_count || 0) + Number(row.manual_line_count || 0);
                if (adjustedCount > 0) {
                    badges.push(`<button type="button" class="badge bg-warning-subtle text-warning-emphasis border-0 ms-1 btn-view-emp-adjustments" data-employee-id="${row.employee_id}" title="${langData['row_badge_item_override'] || 'Has item override(s)'}">${(langData['row_badge_adjusted_n'] || 'Adjusted {n}').replace('{n}', adjustedCount)}</button>`);
                }
                if (row.has_calc_override) {
                    badges.push(`<i class="fa-solid fa-file-invoice-dollar text-info ms-1" title="${langData['row_badge_calc_override'] || 'Has tax/SSO override'}"></i>`);
                }
                return `<span class="fw-semibold">${escapeHtml(d)}</span>${badges.join('')}`;
            } },
            // 2026-09-10, Batch 3A item 4: avatar + name (not avatar alone -- this column must stay
            // searchable by name via the table's own global search box). Object-form render (this
            // app's own DataTables sort-safety convention) since display is now HTML -- filter (what
            // the search box actually matches against) stays the plain name string.
            { data: null, orderable: false, render: {
                display: (d, t, row) => apvPersonLineHtml(employeeDisplayNameRd(row), 24, row.profile_photo_path, { employeeId: row.employee_id }),
                filter: (d, t, row) => employeeDisplayNameRd(row),
            } },
            { data: null, className: 'text-center', visible: showDataSourceColumn, render: (d, t, row) => dataSourceBadgeRd(row) },
            // 2026-09-02, explicit request: "ในตารางพนักงานให้เพิ่ม Column รับเงินผ่านบัญชี หรือเงินสด" --
            // same badge markup the (since-removed) Payment Method Summary tab used, reused here for
            // a consistent look.
            // 2026-09-02, follow-up: widened from a bank/cash-only binary to the real 4-code
            // payment_method_code (transfer/cash/check/mixed) -- check gets the same cash-style badge
            // (no bank account involved either), mixed gets its own distinct badge since it's neither.
            { data: 'payment_method_code', className: 'text-center', render: d => {
                if (d === 'cash') return `<span class="badge bg-warning-subtle text-warning-emphasis"><i class="fa-solid fa-money-bill-wave me-1"></i>${langData['table_payment_cash'] || 'Cash'}</span>`;
                if (d === 'check') return `<span class="badge bg-warning-subtle text-warning-emphasis"><i class="fa-solid fa-money-check me-1"></i>${langData['payment_method_check'] || 'Check'}</span>`;
                if (d === 'mixed') return `<span class="badge bg-primary-subtle text-primary-emphasis"><i class="fa-solid fa-shuffle me-1"></i>${langData['payment_method_mixed'] || 'Mixed'}</span>`;
                return `<span class="badge bg-info-subtle text-info-emphasis"><i class="fa-solid fa-building-columns me-1"></i>${langData['table_payment_bank'] || 'Bank Transfer'}</span>`;
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
            { data: 'base_salary_amount', className: 'text-end', render: {
                display: (d, t, row) => row.base_salary_excluded
                    ? `<span class="text-danger fw-semibold small">${langData['base_salary_excluded_label'] || 'Not Calculated'}</span>`
                    : `<span class="text-muted">${fmtNum(d)}</span>`,
                sort: d => d,
                filter: d => d,
            } },
            { data: 'gross_amount', className: 'text-end text-success fw-semibold', render: d => fmtNum(d) },
            { data: 'total_deduction_amount', className: 'text-end text-danger fw-semibold', render: d => fmtNum(d) },
            { data: 'net_amount', className: 'text-end', render: d => `<span class="rd-net-pill">${fmtNum(d)}</span>` },
            { data: 'calc_status', render: {
                display: (d, t, row) => `${calcStatusBadgeRd(d)}<div class="small mt-1">${calcErrorsRemarkRd(row.calc_errors)}</div>`,
                sort: d => d,
                filter: (d, t, row) => `${d} ${row.calc_errors || ''}`,
            } },
            { data: null, className: 'text-center', orderable: false, render: (d, t, row) => verifyLockButtonsRd(row) },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row (kept even with responsive:false, harmless no-op either way).
            { data: null, className: 'all', orderable: false, render: (d, t, row) => runDetailActionsRd(row) },
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
            applyRunDetailViewMode();
            updateSummaryCardsFromTable();
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
            const calculatedCount = visibleRows.filter(r => r.calc_status === 'calculated').length;
            $('#rdFootCalcStatus').text(`${langData['calc_status_calculated'] || 'Calculated'} ${calculatedCount}/${visibleRows.length}`);
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
            // ช่อง search" -- final button placement/order, both `.dt-search` (right of the search
            // box, matching this app's established "Add"-button-in-search-bar convention -- CLAUDE.md's
            // Table convention, `injectAddButton()` in payroll-configuration.js is the same pattern)
            // and `.dt-length` (next to "Show N entries", same pattern employee/list.js already uses
            // for its own "Sync Selected" button) each now hold 2 buttons in a specific left-to-right
            // order: Search input -> Calculate -> + Employee, and Show N entries -> Verify(N) -> Verify
            // All. `btn-sm` on all 4 (previously plain `btn`) matches the search input's own
            // `form-control-sm` height -- Bootstrap's regular `.btn` is taller than `-sm` form
            // controls, which is what read as mismatched heights. Labels shortened: "action_recalculate"
            // itself changed from "คำนวณใหม่"/"Recalculate" down to "คำนวณ"/"Calculate" (th.json/en.json,
            // this key has exactly one caller so changing its VALUE was safe -- no new key needed), and
            // "action_verify" from "ตรวจสอบแล้ว" down to "ตรวจสอบ" (also just the one other caller,
            // verifyLockButtonsRd()'s own per-row tooltip, where "ตรวจสอบ" reads BETTER than the old
            // "ตรวจสอบแล้ว" -- literally "already verified" -- as a prompt on a NOT-yet-verified row's
            // own action button, so this was a genuine improvement there too, not just a side effect).
            // "employee"/"action_verify_all" i18n keys unchanged (still reused as-is, not new keys).
            // initComplete only ever fires ONCE per table instance (a later reload takes
            // initRunDetailTable()'s "already exists" branch and never gets here again), so initial
            // visibility for all 4 is set directly from `currentRun` here -- every later state change
            // is handled by renderSectionButtons()'s own toggle instead (see that function's own
            // comment). #btnBulkVerify additionally starts `disabled` and only re-enables once a row is
            // actually checked (updateRunDetailBulkBar(), unchanged logic, toggles `disabled` not
            // visibility). #btnVerifyAllEmployees moving here retires the now-empty standalone row
            // above the table (#runVerifyAllButtonWrap) it used to live in -- removed from the view
            // entirely rather than left as a dead wrapper.
            const isDraft = !!currentRun && currentRun.state === 'draft';
            const $container = $(this.api().table().container());
            const $searchDiv = $container.find('.dt-search');
            if ($searchDiv.find('#btnRecalculate').length === 0) {
                // 2026-09-09: no more ms-1/ms-2 margin utilities on these -- .dt-search/.dt-length
                // themselves are now real flex containers with their own `gap` (style.css), so a
                // margin utility here would just add EXTRA space on top of that gap redundantly.
                // 2026-09-09, explicit request: "ปุ่มคำนวณไม่ชอบสีดำครับ ช่วยปรับสีใหม่ แต่ต้องเข้ากับธีม
                // ทั้งหมด" -- was btn-dark (plain black, no relation to this app's own color language
                // at all). btn-info reuses the SAME accent this exact page already uses for
                // "informational/primary-but-not-the-main-action" elements (the "Employees" stat
                // card's own .stat-card-info, the Bank Transfer badge's text-info-emphasis) -- distinct
                // from +Employee's brand-orange btn-primary and Verify's btn-outline-success right next
                // to it in this same control row, so all 3 stay visually distinguishable from each
                // other while every one of them is a real color from this app's existing palette,
                // not an arbitrary new one.
                $searchDiv.append(`<button type="button" id="btnRecalculate" class="btn btn-sm btn-info${isDraft ? '' : ' d-none'}"><i class="fa-solid fa-rotate me-1"></i><span data-i18n="action_recalculate">${langData['action_recalculate'] || 'Calculate'}</span></button>`);
                $searchDiv.append(`<button type="button" id="btnJoinEmployees" class="btn btn-sm btn-primary${isDraft ? '' : ' d-none'}"><i class="fa-solid fa-plus me-1"></i><span data-i18n="employee">${langData['employee'] || 'Employee'}</span></button>`);
            }
            const $lengthDiv = $container.find('.dt-length');
            if ($lengthDiv.find('#btnBulkVerify').length === 0) {
                $lengthDiv.append(`<button type="button" id="btnBulkVerify" class="btn btn-sm btn-outline-success${isDraft ? '' : ' d-none'}" disabled><i class="fa-solid fa-check-double me-1"></i><span data-i18n="action_verify">${langData['action_verify'] || 'Verify'}</span> (<span id="runDetailBulkCount">0</span>)</button>`);
                $lengthDiv.append(`<button type="button" id="btnVerifyAllEmployees" class="btn btn-sm btn-outline-success${isDraft ? '' : ' d-none'}"><i class="fa-solid fa-check-double me-1"></i><span data-i18n="action_verify_all">${langData['action_verify_all'] || 'Verify All'}</span></button>`);
            }
            // 2026-09-10, Batch 2 item 7: filter icon restricted to genuinely-filterable columns
            // with multiple discrete values (data source/payment method/calc status/verify status)
            // -- dropped from the 4 numeric amount columns and the name column per explicit request.
            initExcelColumnFilters(this.api(), {
                mode: 'client',
                columns: [
                    { index: 3, key: 'data_source' },
                    { index: 4, key: 'payment_method_code' },
                    { index: 9, key: 'calc_status' },
                    { index: 10, key: 'verify_status' },
                ]
            });
        }
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
function applyRunDetailViewMode() {
    if (!currentRun) return;
    const isViewMode = currentRun.state !== 'draft';
    $('#runDetailViewModeBadge').toggleClass('d-none', !isViewMode);
    // Checkbox column visibility is handled in initRunDetailTable() itself now (both the initial-
    // construction and reload-existing-table paths), not here -- see that function's own comment
    // for why (a real ordering bug: this drawCallback fires before the table's own outer variable
    // assignment completes on first load).
    // 2026-09-09: #btnBulkVerify's own show/hide-by-draft-state is owned by renderSectionButtons()
    // now (same place #btnRecalculate/#btnJoinEmployees are toggled, all 3 live together in
    // .dt-search/.dt-length) -- this function no longer needs to touch it at all, only its
    // enabled/disabled-by-selection state (updateRunDetailBulkBar(), called separately below).
}

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
    $('#runDetailBulkCount').text(count);
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
// 2026-08-29 same-day redesign ("ช่วยปรับปรุง Design ทั้ง Form และ List ให้หน่อยครับ") -- own
// dedicated .apv-comment-* tone/icon mapping (was a plain badge-only distinction before); mirrors
// the tone vocabulary this page's shared .apv-stage component already uses (see app.js's own
// APV_COLORS) without touching that shared map, since it's also used by the unrelated Timeline/
// Action-History components on this same page.
const EMPLOYEE_COMMENT_TAG_META = {
    in_progress: { icon: 'fa-hourglass-half', color: '#f59e0b', bg: 'linear-gradient(135deg,#f59e0b,#d97706)', key: 'employee_comment_tag_in_progress', fallback: 'In Progress' },
    completed: { icon: 'fa-check', color: '#16a34a', bg: 'linear-gradient(135deg,#22c55e,#15803d)', key: 'employee_comment_tag_completed', fallback: 'Completed' },
    error: { icon: 'fa-triangle-exclamation', color: '#dc2626', bg: 'linear-gradient(135deg,#f87171,#dc2626)', key: 'employee_comment_tag_error', fallback: 'Error' },
};
function employeeCommentTagMeta(tag) {
    return EMPLOYEE_COMMENT_TAG_META[tag] || { icon: 'fa-comment', bg: 'linear-gradient(135deg,#9aa3ad,#6b7280)', key: null, fallback: '' };
}
function employeeCommentTagBadge(tag) {
    const meta = employeeCommentTagMeta(tag);
    if (!meta.key) return '';
    return `<span class="apv-comment-tag-pill" style="background:${meta.bg};"><i class="fa-solid ${meta.icon} me-1"></i>${langData[meta.key] || meta.fallback}</span>`;
}
function renderEmployeeCommentTimeline(comments) {
    $('#employeeCommentEmpty').toggleClass('d-none', comments.length > 0);
    if (!comments.length) {
        $('#employeeCommentTimeline').html('');
        return;
    }
    const html = comments.map(function (c, idx) {
        const isLast = idx === comments.length - 1;
        const name = currentLang === 'th' ? (c.created_by_name_th || c.created_by_name_en) : (c.created_by_name_en || c.created_by_name_th);
        const meta = employeeCommentTagMeta(c.tag);
        // 2026-08-29, explicit request: "สามารถแก้ไข Comment และลบ Comment ได้ด้วย" -- a small
        // "(edited)" marker only when updated_at is actually set (see
        // PayrollRunModel::employeeCommentUpdate()'s own docblock -- a never-edited comment keeps
        // both updated_by/updated_at null).
        const editedTag = c.updated_at ? `<span class="apv-comment-edited-tag">(${langData['employee_comment_edited'] || 'edited'})</span>` : '';
        // 2026-08-29, explicit follow-up: "ดูได้เท่านั้น ไม่สามารถเพิ่ม แก้ไข ลบได้" -- edit/delete icons
        // per comment are dropped entirely once the run has finished (commentsReadOnlyRd()), not
        // just disabled, matching the same "view-only means the control isn't there at all" pattern
        // Verify/Lock's own View Mode already uses elsewhere on this page.
        const editDeleteIcons = commentsReadOnlyRd() ? '' : `
                        <button type="button" class="apv-comment-action-btn btn-edit-employee-comment" data-id="${c.id}" data-tag="${c.tag || ''}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>
                        <button type="button" class="apv-comment-action-btn text-danger btn-delete-employee-comment" data-id="${c.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
        return `<div class="apv-comment-item${isLast ? ' apv-comment-item-last' : ''}">
            <div class="apv-comment-marker">
                <div class="apv-comment-icon" style="background:${meta.bg};"><i class="fa-solid ${meta.icon}"></i></div>
                ${isLast ? '' : '<div class="apv-comment-line"></div>'}
            </div>
            <div class="apv-comment-card">
                <div class="apv-comment-head">
                    <span class="apv-comment-author"><i class="fa-solid fa-circle-user me-1"></i>${escapeHtml(name || '-')}</span>
                    ${employeeCommentTagBadge(c.tag)}${editedTag}
                    <span class="apv-comment-spacer"></span>
                    ${editDeleteIcons}
                </div>
                <div class="apv-comment-body" data-raw-comment="${escapeAttr(c.comment)}">${escapeHtml(c.comment).replace(/\n/g, '<br>')}</div>
                <div class="apv-comment-date"><i class="fa-regular fa-clock me-1"></i>${formatDisplayDateTime ? formatDisplayDateTime(c.created_at) : c.created_at}</div>
            </div>
        </div>`;
    }).join('');
    $('#employeeCommentTimeline').html(html);
}
function loadEmployeeComments() {
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.employee-comment.list`, method: 'GET',
        data: { id: PAYROLL_RUN_ID, employee_id: employeeCommentEmployeeId }, dataType: 'json',
        success: function (res) { if (res.status) renderEmployeeCommentTimeline(res.data || []); }
    });
}
function resetEmployeeCommentForm() {
    employeeCommentEditingId = null;
    $('#employeeCommentTagNone').prop('checked', true);
    $('#employeeCommentText').val('');
    $('#btnAddEmployeeCommentLabel').text(langData['employee_comment_add'] || 'Add Comment');
    $('#btnCancelEditEmployeeComment').addClass('d-none');
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
    $('#employeeCommentModalEmployeeName').text($(this).data('employee-label') || '');
    resetEmployeeCommentForm();
    const readOnly = commentsReadOnlyRd();
    $('#employeeCommentFormArea, #btnAddEmployeeComment').toggleClass('d-none', readOnly);
    $('#employeeCommentReadOnlyNotice').toggleClass('d-none', !readOnly);
    loadEmployeeComments();
    new bootstrap.Modal(document.getElementById('employeeCommentModal')).show();
});
$(document).on('hidden.bs.modal', '#employeeCommentModal', function () {
    resetEmployeeCommentForm();
});
$(document).on('click', '.btn-edit-employee-comment', function () {
    employeeCommentEditingId = $(this).data('id');
    const rawComment = $(this).closest('.apv-comment-card').find('.apv-comment-body').attr('data-raw-comment') || '';
    $('#employeeCommentText').val(rawComment).trigger('focus');
    const tag = $(this).data('tag') || '';
    $(`#employeeCommentTagGroup input[value="${tag}"]`).prop('checked', true);
    $('#btnAddEmployeeCommentLabel').text(langData['employee_comment_update'] || 'Update Comment');
    $('#btnCancelEditEmployeeComment').removeClass('d-none');
});
$(document).on('click', '#btnCancelEditEmployeeComment', function () {
    resetEmployeeCommentForm();
});
$(document).on('click', '.btn-delete-employee-comment', function () {
    const commentId = $(this).data('id');
    showConfirm(langData['confirm_delete_title'] || 'Confirm Delete', langData['confirm_delete_message'] || 'Are you sure you want to delete this item?', function () {
        $.ajax({
            url: `${BASE_URL}/api/payroll-run.employee-comment.delete`, method: 'POST', contentType: 'application/json', dataType: 'json',
            data: JSON.stringify({ id: PAYROLL_RUN_ID, comment_id: commentId }),
            success: function (res) {
                if (res.status) {
                    if (employeeCommentEditingId === commentId) resetEmployeeCommentForm();
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
    const tag = $('#employeeCommentTagGroup input:checked').val() || null;
    const $btn = $(this);
    setButtonLoading($btn, true);
    const isEdit = employeeCommentEditingId !== null;
    const url = isEdit ? '/api/payroll-run.employee-comment.update' : '/api/payroll-run.employee-comment.add';
    const payload = isEdit
        ? { id: PAYROLL_RUN_ID, comment_id: employeeCommentEditingId, tag: tag, comment: comment }
        : { id: PAYROLL_RUN_ID, employee_id: employeeCommentEmployeeId, tag: tag, comment: comment };
    $.ajax({
        url: `${BASE_URL}${url}`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                resetEmployeeCommentForm();
                loadEmployeeComments();
                if (!isEdit) loadRunDetail(); // refreshes the comment-count badge on the row's Comment button
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

// 2026-09-10: moved to app.js as auditActionLabel() -- shared with index.js/approval.js's own
// Timeline modals so all 3 pages can never drift out of sync on action-code wording again.
// 2026-08-27, explicit request: "ในหน้า Process Detail Tab Action History ปรับจากตารางเป็น Timeline
// สวยๆ" -- reuses the SAME `.apv-stage` circular-marker/connector-line component this page's own
// Timeline modal/status card already builds with (app.js's own apvIconHtml()/apvBadgeHtml()/
// apvCreatedStageHtml()) instead of inventing a second timeline design on the same page. Distinct from the plainer `renderAuditTimelineRd()` (left-border list, `.apv-log-entry`)
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
    const actor = personDisplayNameRd(entry, 'performed_by');
    const stateChangeHtml = entry.from_state
        ? `${stateBadgeRd(entry.from_state)} <i class="fa-solid fa-arrow-right mx-1"></i> ${stateBadgeRd(entry.to_state)}`
        : (entry.to_state ? stateBadgeRd(entry.to_state) : '');
    const metaParts = [];
    if (entry.ip_address) metaParts.push(`<span class="me-3"><i class="fa-solid fa-location-dot me-1"></i>${escapeHtml(entry.ip_address)}</span>`);
    if (entry.user_agent) metaParts.push(`<span><i class="fa-solid fa-desktop me-1"></i>${escapeHtml(entry.user_agent)}</span>`);
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
                <div class="apv-history-row-actor">${escapeHtml(actor || '-')}</div>
                ${stateChangeHtml ? `<div class="mt-2">${stateChangeHtml}</div>` : ''}
                ${entry.note ? `<div class="apv-substep-remark mt-2">${escapeHtml(entry.note)}</div>` : ''}
                ${metaParts.length ? `<div class="small text-muted mt-2">${metaParts.join('')}</div>` : ''}
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
$(document).on('change', '#chkAutoRecalculate', function () {
    const $chk = $(this);
    const value = $chk.is(':checked');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.auto-recalculate.save`, method: 'POST', contentType: 'application/json', dataType: 'json',
        data: JSON.stringify({ id: PAYROLL_RUN_ID, value: value }),
        success: function (res) {
            if (res.status) {
                if (currentRun) currentRun.auto_recalculate = value ? 1 : 0;
                renderRecalcReminder(currentRun || { state: 'draft', auto_recalculate: value ? 1 : 0 });
            } else {
                $chk.prop('checked', !value);
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $chk.prop('checked', !value);
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
function manualLineTagHtml(line) {
    if (!line.is_custom) {
        return `<code class="fw-bold text-dark">${escapeHtml(line.item_code)}</code>`;
    }
    return line.is_other
        ? `<span class="badge bg-info-subtle text-info"><i class="fa-solid fa-circle-question me-1"></i>${langData['manual_line_other_badge'] || 'Other'}</span>`
        : `<span class="badge bg-secondary-subtle text-secondary"><i class="fa-solid fa-pen me-1"></i>${langData['manual_line_custom_badge'] || 'Custom'}</span>`;
}
function manualLineListItemHtml(line) {
    const name = (currentLang === 'th' ? line.item_name_th : line.item_name_en) || line.item_name_th || line.item_name_en;
    const amtCls = line.item_type === 'earning' ? 'text-success' : 'text-danger';
    const commentHtml = line.note ? `<div class="small text-muted fst-italic mt-1"><i class="fa-regular fa-comment me-1"></i>${escapeHtml(line.note)}</div>` : '';
    // 2026-08-31, same-day follow-up: payee_type widened to 'company'/'not_disbursed' too (was
    // 'employee' transfer only) -- same branching as Employee Detail's own eedItemNameCell().
    let payeeHtml = '';
    if (line.payee_type === 'employee' && line.payee_employee_id) {
        payeeHtml = `<div class="small text-muted mt-1"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtml(line.payee_employee_no || ('#' + line.payee_employee_id))}</div>`;
    } else if (line.payee_type === 'company') {
        // 2026-09-10, Batch 3B item 3: manualLinesForEmployee() joins bank_account_name for this
        // exact display, unlike the persisted-breakdown-JSON render path elsewhere in this file --
        // shows the real account, or a "needs review" warning when genuinely unspecified.
        payeeHtml = line.bank_account_id
            ? `<div class="small text-muted mt-1"><i class="fa-solid fa-building me-1"></i>${langData['payee_type_company'] || 'Company Account'} - ${escapeHtml(line.bank_account_name || '')}</div>`
            : `<div class="small text-warning mt-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>${langData['payee_bank_account_needs_review'] || 'Company Account -- bank account not specified, needs review'}</div>`;
    } else if (line.payee_type === 'other_person') {
        // 2026-09-02, Deduction Destination & Third-Party Remittance -- real gap found while
        // touching this function for Phase 7 (same missing branch already found/fixed in
        // employee/detail.js's own eedItemNameCell()): 'other_person' had no tag here either.
        payeeHtml = `<div class="small text-muted mt-1"><i class="fa-solid fa-building-columns me-1"></i>${escapeHtml(line.destination_account_name || (langData['payee_type_other_person'] || 'Other Person / Third Party'))}</div>`;
    } else if (line.payee_type === 'not_disbursed') {
        payeeHtml = `<div class="small text-muted mt-1"><i class="fa-solid fa-ban me-1"></i>${langData['payee_type_not_disbursed'] || 'Not Disbursed'}</div>`;
    }
    return `<li class="list-group-item d-flex justify-content-between align-items-start px-0 py-2">
        <div>
            ${manualLineTagHtml(line)}
            <div class="small text-muted">${escapeHtml(name)}</div>
            ${commentHtml}
            ${payeeHtml}
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="fw-semibold ${amtCls}">${fmtNum(line.amount)}</span>
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
            $('#manualLinesEarningTotal').text(fmtNum(earningTotal));
            $('#manualLinesDeductionTotal').text(fmtNum(deductionTotal));
            $('#manualLinesNetTotal').text(fmtNum(earningTotal - deductionTotal));
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
    const syncedDisplay = (synced !== null && synced !== undefined) ? fmtNum(synced) : '-';
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
                loadSyncLineOverridesRd();
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
    const amountValue = line.override_action === 'override_amount' ? line.override_amount : line.current_amount;
    const hasOverride = line.override_action !== null;
    // 2026-08-31, same-day follow-up (item 9a): 'statutory' rows must route Save/Reset to
    // api/payroll-run.statutory-line-override.* instead of the general .line-override.* endpoints --
    // the general endpoint has no knowledge of statutoryOverrideCode()'s reserved-sentinel wrapping
    // and would silently save under the wrong (unwrapped) item_code, never actually applied by
    // recalculate()'s statutory-check loop. See PayrollRunModel::syncDeductionLinesForEmployee()'s
    // own line_type tagging.
    const lineType = line.line_type || 'earning_deduction';
    return `<div class="border rounded-3 p-2 mb-2" data-item-code="${escapeHtml(line.code)}" data-line-type="${escapeHtml(lineType)}">
        <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-1">
            <div>
                <code class="fw-bold text-dark">${escapeHtml(line.code)}</code> ${escapeHtml(name)}${syncLineOverrideBadge(line)}
                ${lineType === 'statutory' ? `<span class="badge bg-info-subtle text-info ms-1">${langData['sync_line_statutory_badge'] || 'Statutory'}</span>` : ''}
                <div class="small text-muted">${langData['sync_line_override_computed'] || 'Current'}: ${fmtNum(line.current_amount)}</div>
            </div>
            ${hasOverride ? `<button type="button" class="btn btn-sm btn-outline-secondary btn-sync-line-reset" data-item-code="${escapeHtml(line.code)}" data-line-type="${escapeHtml(lineType)}">${langData['sync_line_override_reset'] || 'Reset to computed'}</button>` : ''}
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap sync-line-controls">
            <input type="number" step="0.01" min="0" class="form-control form-control-sm sync-line-amount-input" style="max-width:140px;" value="${amountValue}" ${isExcluded ? 'disabled' : ''}>
            <!-- 2026-08-29, explicit request: "เพิ่มให้สามารถเลือกเอาเงินเดือนออกจากการคำนวณได้" -- base
                 salary's own row (__base_salary__) used to skip this checkbox entirely ('exclude'
                 was a no-op for it server-side); PayrollRunModel::recalculate() now honors 'exclude'
                 for base salary too (zeroes it, same as dropping a real line), so every row
                 -- base salary included -- gets the same control. -->
            <div class="form-check form-check-inline mb-0">
                <input type="checkbox" class="form-check-input sync-line-exclude-check" ${isExcluded ? 'checked' : ''}>
                <label class="form-check-label small">${langData['sync_line_override_action_exclude'] || 'Exclude this run'}</label>
            </div>
            <!-- 2026-08-29, real bug found and fixed (explicit report: setting amount to 0 or
                 checking "exclude" then clicking Save always failed with the generic "required
                 fields" warning) -- this button used to ALSO carry its own data-item-code
                 attribute (mirroring the Reset button's, which genuinely needs one -- see that
                 button's own click handler reading $(this).data('item-code') directly). But THIS
                 button's own handler reads $(this).closest('[data-item-code]') to find the
                 wrapping row div, and jQuery's .closest() checks the STARTING element itself
                 before walking up to ancestors -- since this button matched the selector on its
                 own, .closest() returned the button itself instead of the row, so
                 $row.find('.sync-line-exclude-check')/'.sync-line-amount-input' searched INSIDE an
                 element with no such children and found nothing, making isExcluded always false
                 and amount always NaN regardless of the row's real state. Confirmed via a direct
                 jQuery/jsdom simulation of the exact click before writing this fix, not guessed --
                 EVERY save on this tab was silently broken, not just the 0/excluded cases the user
                 happened to report. Removing this redundant attribute (never read directly by this
                 button's own handler) lets .closest() correctly skip past it to the real row div. -->
            <button type="button" class="btn btn-sm btn-primary btn-sync-line-save">${langData['save'] || 'Save'}</button>
        </div>
        ${syncLineOccurrenceBreakdownHtml(line.occurrences)}
    </div>`;
}
// 2026-08-31, same-day follow-up (Origami's `scheduled_item_occurrences[]` proposal) -- an
// optional per-installment breakdown of a line's summed total (e.g. "LOAN installment 2 of 12"),
// only present when PayrollRunModel::syncDeductionLinesForEmployee() found real occurrence data for
// this line (see that method's own docblock -- earning/deduction lines from a synced Origami
// process only, never base salary/statutory/manual lines). Read-only display -- occurrences aren't
// individually editable here, only the summed line itself is (via the existing Save/Reset above).
function syncLineOccurrenceBreakdownHtml(occurrences) {
    if (!occurrences || !occurrences.length) return '';
    const rows = occurrences.map(o => `<div class="d-flex justify-content-between small">
        <span>${langData['sync_line_occurrence_installment'] || 'Installment'} ${o.installment_no != null ? escapeHtml(o.installment_no) : '-'}
            ${o.occurrence_code ? `<code class="text-muted ms-1">${escapeHtml(o.occurrence_code)}</code>` : ''}</span>
        <span>${fmtNum(o.amount)}${o.applied_at ? ` <span class="text-muted">(${formatDisplayDate(o.applied_at)})</span>` : ''}</span>
    </div>`).join('');
    return `<div class="mt-2 pt-2 border-top">
        <div class="small text-muted mb-1"><i class="fa-solid fa-list-ol me-1"></i>${langData['sync_line_occurrence_breakdown'] || 'Occurrence breakdown'}</div>
        ${rows}
    </div>`;
}
// 2026-08-29, explicit follow-up request: "อยากให้มี List รายการและติ๊กเข้าออกได้เหมือนตอนที่ Set ทั้ง
// Template" -- checklist version of per-employee item exclusion, sourced from the SAME item catalog
// Run Settings' own panel uses (res.run_settings.item_options), cross-referenced against this
// employee's existing per-item overrides (res.data, same array the per-row list below already
// renders from) to compute each row's checked/disabled state. `data-was-checked` records the
// state AT LOAD TIME so Save only needs to touch what actually changed.
function empItemExclusionCheckedState(item, runExcludedSet, personalOverrideByCode) {
    const personal = personalOverrideByCode[item.item_code] || null;
    if (personal && personal.override_action === 'exclude') return { checked: true, disabled: false };
    if (personal && personal.override_action === 'override_amount') return { checked: false, disabled: false };
    if (runExcludedSet.has(item.item_code)) return { checked: true, disabled: true };
    return { checked: false, disabled: false };
}
function loadEmpItemExclusionChecklist(lines, runSettings) {
    if (!runSettings) { $('#empItemExclusionChecklist').html(''); return; }
    const personalByCode = {};
    (lines || []).forEach(l => { if (l.override_action) personalByCode[l.code] = l; });
    const runExcludedSet = new Set(runSettings.excluded_item_codes || []);
    $('#empItemExclusionChecklist').html(itemChecklistBoxesHtml(runSettings.item_options || [], {
        checkboxClass: 'emp-item-exclusion-check',
        idPrefix: 'empExclItem',
        trackWasChecked: true,
        isChecked: item => empItemExclusionCheckedState(item, runExcludedSet, personalByCode).checked,
        isDisabled: item => empItemExclusionCheckedState(item, runExcludedSet, personalByCode).disabled,
        disabledBadgeHtml: () => ` <span class="badge bg-secondary-subtle text-secondary">${langData['run_default_badge'] || 'Run Default'}</span>`,
    }));
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
                : `<div class="text-center text-muted small py-2">${langData['sync_line_override_empty'] || 'No calculated amounts for this employee yet -- recalculate the run first.'}</div>`);
            loadEmpItemExclusionChecklist(lines, res.run_settings);
            // 2026-08-29: "Tax & SSO" tab -- see PayrollController::syncLinesForEmployee()'s own
            // docblock for why this is bundled into the same fetch instead of a separate one.
            const ex = res.exemption || { tax_calculate_override: 'inherit', sso_calculate_override: 'inherit' };
            $(`#empCalcTaxGroup input[value="${ex.tax_calculate_override || 'inherit'}"]`).prop('checked', true);
            $(`#empCalcSsoGroup input[value="${ex.sso_calculate_override || 'inherit'}"]`).prop('checked', true);
        }
    });
}
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
$(document).on('click', '#btnSaveEmpItemExclusion', function () {
    const $btn = $(this);
    setButtonLoading($btn, true);
    const calls = [];
    $('#empItemExclusionChecklist .emp-item-exclusion-check:not(:disabled)').each(function () {
        const itemCode = $(this).val();
        const wasChecked = $(this).data('was-checked') === '1' || $(this).data('was-checked') === 1;
        const isChecked = this.checked;
        if (wasChecked === isChecked) return; // unchanged, nothing to send
        if (isChecked) {
            calls.push(next => $.ajax({
                url: `${BASE_URL}/api/payroll-run.line-override.save`, method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, item_code: itemCode, action: 'exclude' }),
                success: res => next(!!res.status),
                error: () => next(false),
            }));
        } else {
            calls.push(next => $.ajax({
                url: `${BASE_URL}/api/payroll-run.line-override.remove`, method: 'POST', contentType: 'application/json', dataType: 'json',
                data: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, item_code: itemCode }),
                success: res => next(!!res.status),
                error: () => next(false),
            }));
        }
    });
    if (!calls.length) { setButtonLoading($btn, false); return; }
    runSequentialAjaxRd(calls, function () {
        setButtonLoading($btn, false);
        showSuccess(langData['save_success'] || 'Saved successfully.');
        loadSyncLineOverridesRd();
        loadRunDetail();
    });
});
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
$(document).on('click', '.btn-sync-line-save', function () {
    const $row = $(this).closest('[data-item-code]');
    const itemCode = $row.data('item-code');
    const lineType = $row.data('line-type') || 'earning_deduction';
    const isExcluded = $row.find('.sync-line-exclude-check').is(':checked');
    const amount = parseFloat($row.find('.sync-line-amount-input').val());
    if (!isExcluded && (isNaN(amount) || amount < 0)) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    // 2026-08-31, same-day follow-up (item 9a): a 'statutory' row's itemCode is always the BARE
    // TH_SSO/TH_PVD/TH_PIT/etc. code -- statutoryLineOverrideSave() wraps it via
    // statutoryOverrideCode() internally, so it must never be pre-wrapped here.
    const url = lineType === 'statutory'
        ? `${BASE_URL}/api/payroll-run.statutory-line-override.save`
        : `${BASE_URL}/api/payroll-run.line-override.save`;
    const payload = {
        id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, item_code: itemCode,
        action: isExcluded ? 'exclude' : 'override_amount',
    };
    if (!isExcluded) { payload.override_amount = amount; }
    $.ajax({
        url: url,
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
    const lineType = $(this).data('line-type') || 'earning_deduction';
    const url = lineType === 'statutory'
        ? `${BASE_URL}/api/payroll-run.statutory-line-override.remove`
        : `${BASE_URL}/api/payroll-run.line-override.remove`;
    $.ajax({
        url: url,
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

/* ---------- Recurring Deduction Destination override (2026-09-02, Deduction Destination &
   Third-Party Remittance, Phase 6) -- per-run override of which account a recurring deduction
   (Employee Detail's own "Recurring Deductions" section) is routed to, without ever touching that
   employee's own saved template. One shared editor card (#recurringDestEditorCard) reused across
   every row -- avoids initializing a fresh Select2 instance per row, same reasoning
   payroll-run.line-override's own per-row plain-input approach already established for this exact
   modal, just extended to a shared rich sub-form since a payee needs an employee/bank picker, not
   just a number. ---------- */
let recurringDestRows = [];
function recurringDestPayeeSummary(p) {
    if (!p || !p.payee_type) return langData['payee_type_none'] || "Employee's Own Net Pay";
    if (p.payee_type === 'employee') return p.payee_label || (langData['payee_type_employee'] || 'Another Employee');
    // 2026-09-10, Batch 3B item 3: shows WHICH company bank account now, instead of the generic
    // "Company Account" label every 'company' row used to get regardless of which account was
    // chosen -- falls back to an explicit "not specified" wording (never a silent blank) when
    // bank_account_id is genuinely unspecified (legacy data, or before this column existed).
    if (p.payee_type === 'company') return p.bank_account_label ? `${langData['payee_type_company'] || 'Company Account'} - ${p.bank_account_label}` : (langData['payee_type_company_unspecified'] || 'Company Account (not specified)');
    if (p.payee_type === 'other_person') return p.destination_label || (langData['payee_type_other_person'] || 'Other Person / Third Party');
    if (p.payee_type === 'not_disbursed') return langData['payee_type_not_disbursed'] || 'Not Disbursed';
    return p.payee_type;
}
function recurringDestRowHtml(row) {
    const name = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || row.item_code;
    const templateLabel = recurringDestPayeeSummary({ payee_type: row.template_payee_type, payee_label: row.template_payee_label, destination_label: row.template_destination_label, bank_account_label: row.template_bank_account_label });
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
    $.getJSON(`${BASE_URL}/api/payroll-run.recurring-deduction-destinations-for-employee`, { run_id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId }, function (res) {
        if (!res.status) return;
        recurringDestRows = res.data || [];
        $('#recurringDestOverrideList').html(recurringDestRows.length
            ? recurringDestRows.map(recurringDestRowHtml).join('')
            : `<div class="text-center text-muted small py-2">${langData['recurring_dest_empty'] || 'No recurring deductions active for this employee in this pay period.'}</div>`);
    });
}
function setRecurringDestPayeeType(type) {
    $('#recurringDestPayeeTypeToggle button').removeClass('active').filter(`[data-payee-type="${type}"]`).addClass('active');
    $('#recurringDestEmployeeWrapper').toggleClass('d-none', type !== 'employee');
    if (type !== 'employee') {
        $('#recurringDestPayeeEmployeeSelect').val(null).trigger('change');
    }
    // 2026-09-10, Batch 3B item 3: level-2 for payee_type='company' -- mandatory, same as the other
    // 3 payee-routing editors in this app.
    $('#recurringDestCompanyAccountWrapper').toggleClass('d-none', type !== 'company');
    if (type !== 'company') {
        $('#recurringDestBankAccountSelect').val(null).trigger('change');
    }
    $('#recurringDestDestinationWrapper').toggleClass('d-none', type !== 'other_person');
    if (type !== 'other_person') {
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
}
$(document).on('click', '#recurringDestPayeeTypeToggle button', function () {
    setRecurringDestPayeeType($(this).data('payee-type'));
});
$(document).on('select2:select', '#recurringDestDestinationSelect', function () {
    $('#recurringDestDestinationNewFields').addClass('d-none');
});
$(document).on('select2:clear', '#recurringDestDestinationSelect', function () {
    $('#recurringDestDestinationNewFields').removeClass('d-none');
});
$(document).on('click', '.btn-recurring-dest-edit', function () {
    const recurringId = $(this).data('recurring-id');
    const row = recurringDestRows.find(r => r.recurring_id === recurringId);
    if (!row) return;
    $('#recurringDestEditorRecurringId').val(recurringId);
    const name = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || row.item_code;
    $('#recurringDestEditorItemName').text(name);
    // An override can never be 'none'/null (that's what Reset achieves) -- if the template itself
    // had no payee at all, default the editor to Company as a neutral starting point, not a guess
    // at what the admin actually wants.
    const current = row.override || { payee_type: row.template_payee_type || 'company', payee_employee_id: row.template_payee_employee_id, payee_label: row.template_payee_label, destination_id: row.template_destination_id, destination_label: row.template_destination_label, bank_account_id: row.template_bank_account_id, bank_account_label: row.template_bank_account_label };
    const initialType = current.payee_type || 'company';
    setRecurringDestPayeeType(initialType);
    if (initialType === 'employee' && current.payee_employee_id) {
        const opt = new Option(current.payee_label || '', current.payee_employee_id, true, true);
        $('#recurringDestPayeeEmployeeSelect').empty().append(opt).trigger('change');
    } else if (initialType === 'company' && current.bank_account_id) {
        // 2026-09-10, Batch 3B item 3: same pre-select pattern as the employee/destination branches.
        const opt = new Option(current.bank_account_label || '', current.bank_account_id, true, true);
        $('#recurringDestBankAccountSelect').empty().append(opt).trigger('change');
    } else if (initialType === 'other_person' && current.destination_id) {
        const opt = new Option(current.destination_label || '', current.destination_id, true, true);
        $('#recurringDestDestinationSelect').empty().append(opt).trigger('change');
        $('#recurringDestDestinationNewFields').addClass('d-none');
    }
    $('#recurringDestEditorCard').removeClass('d-none');
});
$(document).on('click', '#btnCancelRecurringDestEdit', function () {
    $('#recurringDestEditorCard').addClass('d-none');
});
$(document).on('click', '#btnSaveRecurringDestOverride', function () {
    const recurringId = $('#recurringDestEditorRecurringId').val();
    const payeeType = $('#recurringDestPayeeTypeToggle button.active').data('payee-type');
    const payload = { id: PAYROLL_RUN_ID, recurring_id: recurringId, payee_type: payeeType };
    if (payeeType === 'employee') {
        const payeeEmployeeId = $('#recurringDestPayeeEmployeeSelect').val();
        if (!payeeEmployeeId) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        payload.payee_employee_id = payeeEmployeeId;
    } else if (payeeType === 'company') {
        // 2026-09-10, Batch 3B item 3: level-2, mandatory -- PayrollRunModel::
        // recurringDeductionDestinationOverrideSave() itself rejects a missing value.
        const bankAccountId = $('#recurringDestBankAccountSelect').val();
        if (!bankAccountId) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
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
                showWarning(langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.');
                return;
            }
            payload.account_name = accountName;
            payload.account_no = accountNo;
            payload.bank_id = bankId;
            payload.bank_branch = $('#recurringDestBankBranch').val().trim() || undefined;
            payload.is_saved = $('#recurringDestSaveForReuse').is(':checked');
        }
    }
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.recurring-deduction-destination-override.save`, method: 'POST',
        contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload),
        success: function (res) {
            setButtonLoading($btn, false);
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            $('#recurringDestEditorCard').addClass('d-none');
            loadRecurringDeductionDestinationsRd();
            loadRunDetail();
        },
        error: function () { setButtonLoading($btn, false); showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
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
// 2026-08-31, same-day follow-up: same 4-way payee_type toggle Employee Detail's own
// setEedPayeeType() manages, ported here since this modal never had the concept before. Single
// source of truth for this toggle's own dependent field visibility.
function setManualLinePayeeTypeRd(type) {
    $('#manualLinePayeeTypeToggle button').removeClass('active').filter(`[data-payee-type="${type}"]`).addClass('active');
    $('#manualLinePayeeWrapper').toggleClass('d-none', type !== 'employee');
    if (type !== 'employee') {
        $('#manualLinePayeeEmployee').val(null).trigger('change');
    }
    // 2026-09-10, Batch 3B item 3: level-2 for payee_type='company' -- mandatory, same as Employee
    // Detail's own setEedPayeeType()/setErdPayeeType().
    $('#manualLineCompanyAccountWrapper').toggleClass('d-none', type !== 'company');
    if (type !== 'company') {
        $('#manualLineBankAccount').val(null).trigger('change');
    }
    // 2026-09-02, Deduction Destination & Third-Party Remittance.
    $('#manualLineDestinationWrapper').toggleClass('d-none', type !== 'other_person');
    if (type !== 'other_person') {
        $('#manualLineDestinationSelect').val(null).trigger('change');
        $('#manualLineDestAccountName, #manualLineDestAccountNo, #manualLineDestBankBranch').val('');
        $('#manualLineDestBank').val(null).trigger('change');
        $('#manualLineDestSaveForReuse').prop('checked', false);
        $('#manualLineDestinationNewFields').removeClass('d-none');
    } else {
        // Manual Entry / Platform UX review Phase 7 -- see applyFirstSavedDestinationDefault()'s
        // own docblock in app.js.
        applyFirstSavedDestinationDefault('#manualLineDestinationSelect', '#manualLineDestinationNewFields');
    }
    // Same "never offered for not_disbursed, forced at the model layer" rule as Employee Detail's
    // own #eedIncludeCashSummaryWrapper.
    $('#manualLineIncludeCashSummaryWrapper').toggleClass('d-none', type === 'none' || type === 'not_disbursed');
}
// 2026-09-02, Deduction Destination & Third-Party Remittance -- picking an existing saved
// destination hides the new-account fields entirely (nothing to fill in); clearing it (allow-clear)
// brings them back so a fresh one can be entered.
$(document).on('select2:select', '#manualLineDestinationSelect', function () {
    $('#manualLineDestinationNewFields').addClass('d-none');
});
$(document).on('select2:clear', '#manualLineDestinationSelect', function () {
    $('#manualLineDestinationNewFields').removeClass('d-none');
});
function updateManualLineTypePreviewRd(itemType) {
    const $preview = $('#manualLineTypePreview');
    // Transfer-to-payee (2026-08-21) only makes sense on a deduction -- toggled alongside this same
    // type preview rather than a parallel visibility mechanism.
    const isDeduction = itemType === 'deduction';
    $('#manualLinePayeeTypeWrapper').toggleClass('d-none', !isDeduction);
    if (!isDeduction) {
        setManualLinePayeeTypeRd('none');
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
$(document).on('click', '#manualLinePayeeTypeToggle button', function () {
    setManualLinePayeeTypeRd($(this).data('payee-type'));
});
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
// 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- "Other" reuses
// #manualLineCustomFields verbatim, same as #eedModal's own "Other" mode (see that modal's
// setEedMode() docblock in employee/detail.js) -- only #btnAddManualLine's own click handler below
// differs (sends is_other=true).
function setManualLineModeRd(mode) {
    manualLineMode = mode;
    $('#manualLineModeToggle button').removeClass('active').filter(`[data-mode="${mode}"]`).addClass('active');
    $('#manualLineCatalogFields').toggleClass('d-none', mode !== 'catalog');
    $('#manualLineCustomFields').toggleClass('d-none', mode === 'catalog');
    updateManualLineTypePreviewRd(mode !== 'catalog' ? ($('#manualLineCustomType').val() || null) : null);
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
    setManualLinePayeeTypeRd('none');
    $('#manualLineIncludeCashSummary').prop('checked', true);
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
    // 2026-08-30 (Phase 8, T041, real gap found and fixed): include_attendance_pay is a 3rd source
    // that can ALSO be on alongside/instead of the two above -- the "partial" branch's condition
    // was missing it entirely, so an OT/trip-only run (include_attendance_pay on, the other two off
    // -- exactly the scenario this toggle was built for) would have wrongly shown the "no base
    // salary, no standing items" hint, silently omitting that attendance pay is ALSO being counted.
    let hint;
    if (!isIncentive) {
        hint = langData['manage_items_hint_adjustment'] || 'Added on top of this employee\'s normal calculation, for this run only.';
    } else if (currentRun.include_base_salary || currentRun.include_standing_items || currentRun.include_attendance_pay) {
        hint = langData['manage_items_hint_incentive_partial'] || 'Added on top of this run\'s own settings (base salary and/or standing earning/deduction items, as configured for this run), for this employee only.';
    } else {
        hint = langData['manage_items_hint_incentive'] || 'These are the only items counted for this employee -- no base salary, no standing income/deduction assignments.';
    }
    $('#manageLinesHint').text(hint);
    resetManualLineFormRd();
    // Always reopen on Tab 1 -- a stale "Attendance Data" tab left active from a previous employee
    // would otherwise show up front-and-center unexpectedly.
    bootstrap.Tab.getOrCreateInstance(document.getElementById('manageLinesItemsTab')).show();
    // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9b): "Attendance Data" used to be
    // sync-only here (PayrollRunModel::attendanceOverrideSave() itself refused any non-sync run) --
    // that backend restriction is gone now (see that method's own updated docblock: the underlying
    // engine already treats sync/manual/import attendance data uniformly via
    // TransactionDataPayAdapter, Phase 5), so this tab is always shown. A run with genuinely no
    // underlying attendance data of any source just shows an empty/zeroed state once opened --
    // same as a sync-based employee absent from the pulled payload already could before this.
    $('#manageLinesAttendanceTabWrap').removeClass('d-none');
    loadAttendanceDataRd();
    // Reset the "Tax & SSO" tab to a neutral state before the fresh fetch below lands, so a stale
    // previous employee's radios never flash for even a moment.
    $('#empCalcTaxInherit, #empCalcSsoInherit').prop('checked', true);
    loadSyncLineOverridesRd();
    loadRecurringDeductionDestinationsRd();
    new bootstrap.Modal(document.getElementById('manageLinesModal')).show();
    loadManualLinesRd();
});
$(document).on('click', '#btnAddManualLine', function () {
    const amount = parseFloat($('#manualLineAmount').val());
    const comment = $('#manualLineComment').val().trim();
    const payload = { id: PAYROLL_RUN_ID, employee_id: manageLinesEmployeeId, amount: amount, note: comment };
    if (manualLineMode === 'custom' || manualLineMode === 'other') {
        const customName = $('#manualLineCustomName').val().trim();
        const customType = $('#manualLineCustomType').val();
        if (!customName || !customType || !amount || amount <= 0) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        payload.custom_item_name = customName;
        payload.custom_item_type = customType;
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7.
        if (manualLineMode === 'other') {
            payload.is_other = true;
        }
    } else {
        const pedTypeId = $('#manualLineItemSelect').val();
        if (!pedTypeId || !amount || amount <= 0) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        payload.ped_type_id = pedTypeId;
    }
    // 2026-08-31, same-day follow-up: same 4-way payee_type toggle as Employee Detail's own EED
    // modal -- only read when the wrapper is actually visible (a deduction), same shape either
    // catalog or custom mode uses now (unified, was split per-branch above before this follow-up).
    if (!$('#manualLinePayeeTypeWrapper').hasClass('d-none')) {
        const payeeType = $('#manualLinePayeeTypeToggle button.active').data('payee-type') || 'none';
        if (payeeType !== 'none') {
            payload.payee_type = payeeType;
            payload.include_in_cash_summary = $('#manualLineIncludeCashSummary').is(':checked');
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
            const savedDestinationId = $('#manualLineDestinationSelect').val();
            if (savedDestinationId) {
                payload.destination = { destination_id: savedDestinationId };
            } else {
                const accountName = $('#manualLineDestAccountName').val().trim();
                const accountNo = $('#manualLineDestAccountNo').val().trim();
                const bankId = $('#manualLineDestBank').val();
                if (!accountName || !accountNo || !bankId) {
                    showWarning(langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.');
                    return;
                }
                payload.destination = {
                    account_name: accountName, account_no: accountNo, bank_id: bankId,
                    bank_branch: $('#manualLineDestBankBranch').val().trim() || undefined,
                    is_saved: $('#manualLineDestSaveForReuse').is(':checked'),
                };
            }
        }
    }
    const $btn = $(this);
    setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.add-manual-line`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            setButtonLoading($btn, false);
            if (res.status) {
                resetManualLineFormRd();
                loadManualLinesRd();
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
    const selectedCycleId = $('#edit_run_cycle_id').val();
    const wasOffCycle = !currentRun.cycle_id;
    const willBeOffCycle = !selectedCycleId;
    return wasOffCycle !== willBeOffCycle && Number(currentRun.employee_count || 0) > 0;
}
// Live-updates the run-type section (Run Purpose/Compute Statutory/.../Use Flat Tax Rate) AND the
// risky-toggle warning as the admin changes the cycle dropdown DURING this same open modal -- not
// just once on open -- since switching cycle<->off-schedule right here is now the whole point of
// this field (see PayrollRunModel::update()'s own forcing logic for a run that just became/stopped
// being cycle-linked, which this mirrors client-side purely for immediate visual feedback).
function updateEditRunTypeSectionRd() {
    const cycleId = $('#edit_run_cycle_id').val();
    // A supplemental sync-linked run stays eligible for Run Purpose regardless of the cycle field
    // (sync_run_kind, not cycle_id, is what makes it supplemental) -- same "off-cycle OR
    // supplemental sync" OR this whole feature already used before cycle became editable here.
    const isSupplementalSync = !!currentRun.sync_process_id && (currentRun.sync_run_kind || 'regular') === 'supplemental';
    const offCycle = !cycleId && !currentRun.sync_process_id;
    $('#edit_run_type_section').toggleClass('d-none', !offCycle && !isSupplementalSync);
    updateEditRunTypeVisibility();
    $('#editRunCycleLockedHint').toggleClass('d-none', !editRunCycleToggleRiskyRd());
    // 2026-09-01/02, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" then "ขาด...เปิดรอบใหม่ อ้างอิงถึงรอบที่
    // มีอยู่" then "พอเป็น Design แบบเดียวกันแล้วดูแปลกๆครับ ช่วย Design Form ให้ใหม่" -- #edit_run_offcycle_panel
    // (mirrors #run_offcycle_panel on the Create form) only applies to a genuinely off-cycle run
    // (matches PayrollRunModel::update()'s own check exactly: cycle_id===null AND
    // sync_process_id===null -- unlike Run Purpose above, a supplemental sync run does NOT qualify
    // for this one). Forced back to "new"/no-target the moment
    // the cycle field stops being off-cycle -- keeps whatever the admin had picked untouched while
    // it's still genuinely off-cycle (e.g. switching which OTHER field changed on the same open
    // modal shouldn't silently wipe a "reference" choice already made).
    $('#edit_run_offcycle_panel').toggleClass('d-none', !offCycle);
    if (!offCycle) {
        // syncEditRunMergeIntoUiRd() itself fires editRunMergeChoice's own change ->
        // setEditMergeChoiceMode('new'), so no separate explicit call is needed here anymore.
        syncEditRunMergeIntoUiRd('standalone');
    }
}
// 2026-09-02, explicit request: "ยังไม่เหมือนหน้าเพิ่มรอบในหน้า List ครับ ขาด รอบพิเศษนอกรอบเงินเดือน" then
// "พอมีแค่...ให้ติ๊กออกแล้วค่อยให้เลือกรอบ...ดูงงๆ ช่วยเพิ่มเป็น radio ให้เลือก" -- mirrors setOffCycleMode() in
// index.js, adapted to this modal's own edit_run_* field ids/radio name (editRunScheduleChoice,
// distinct from the Create form's runScheduleChoice). Only ever shown for a non-sync run (see
// #btnEditRun's own handling of #edit_run_offcycle_row's visibility below) -- a sync-linked run's
// "off-cycle-ness" is governed by sync_run_kind instead, a completely different mechanism this radio
// doesn't touch.
function setEditOffCycleMode(isOffCycle) {
    $('#edit_run_cycle_row').toggleClass('d-none', isOffCycle);
    $('#edit_run_cycle_id').toggleClass('required', !isOffCycle);
    if (isOffCycle) {
        $('#edit_run_cycle_id').val('').trigger('change');
        $('#edit_run_cycle_id').removeClass('is-invalid');
    }
    $('#edit_run_offcycle_row .run-choice-card').removeClass('active');
    $(isOffCycle ? '#edit_run_schedule_choice_offcycle' : '#edit_run_schedule_choice_cycle').closest('.run-choice-card').addClass('active');
}
$(document).on('change', 'input[name="editRunScheduleChoice"]', function () {
    setEditOffCycleMode($(this).val() === 'offcycle');
});
// 2026-09-09, round-creation flow audit Phase 3 -- mirrors syncRunMergeIntoUi()/syncRunPurposeChoiceUi()
// in index.js (see those functions' own docblocks), adapted to this modal's own edit_run_* ids. The
// "new UI drives the old hidden radios" approach means setEditMergeChoiceMode()/setEditMergeTargetMode()
// below (both unchanged) keep doing all the actual show/hide/clear work.
function syncEditRunMergeIntoUiRd(value) {
    $('#edit_run_merge_into_row input[name="editRunMergeInto"]').prop('checked', false);
    $('#edit_run_merge_into_row .run-subchoice-btn').removeClass('active');
    const $radio = $(`input[name="editRunMergeInto"][value="${value}"]`).prop('checked', true);
    $radio.closest('.run-subchoice-btn').addClass('active');
    if (value === 'standalone') {
        $('#edit_run_merge_choice_new').prop('checked', true).trigger('change');
        return;
    }
    // Target sub-mode set silently first (no .trigger()) so editRunMergeChoice's own change handler
    // (setEditMergeChoiceMode('reference')) reads the already-correct sub-mode on its one and only
    // cascade, instead of a stale value it would otherwise have to immediately re-correct.
    $(value === 'future_cycle' ? '#edit_run_merge_target_mode_future_cycle' : '#edit_run_merge_target_mode_existing').prop('checked', true);
    $('#edit_run_merge_choice_reference').prop('checked', true).trigger('change');
}
$(document).on('change', 'input[name="editRunMergeInto"]', function () {
    syncEditRunMergeIntoUiRd($(this).val());
});
// Unlike the Create form's own syncRunPurposeChoiceUi(), `value` is NEVER '' here -- opening Edit
// always has a real currentRun.run_purpose to reflect (see #edit_run_purpose_choice_row's own
// comment in detail.php for why the Create form's hard-block doesn't apply to editing).
function syncEditRunPurposeChoiceUiRd(value) {
    $('#edit_run_purpose').val(value).removeClass('is-invalid');
    $('#edit_run_purpose_choice_row input[name="editRunPurposeChoice"]').prop('checked', false);
    $('#edit_run_purpose_choice_row .run-choice-card').removeClass('active');
    const $radio = $(`input[name="editRunPurposeChoice"][value="${value}"]`).prop('checked', true);
    $radio.closest('.run-choice-card').addClass('active');
    $('#edit_run_purpose').trigger('change');
}
$(document).on('change', 'input[name="editRunPurposeChoice"]', function () {
    syncEditRunPurposeChoiceUiRd($(this).val());
});
// 2026-09-01/02: mirrors setMergeChoiceMode() in index.js, adapted to this modal's own edit_run_*
// field ids/name (editRunMergeChoice, distinct from the Create form's runMergeChoice).
function setEditMergeChoiceMode(choice) {
    const isReference = choice === 'reference';
    $('#edit_run_merge_target_row').toggleClass('d-none', !isReference);
    if (!isReference) {
        $('#edit_run_merge_target_id').val('').trigger('change.select2').removeClass('is-invalid');
        $('#edit_run_merge_target_cycle_id').val('').trigger('change.select2').removeClass('is-invalid');
        $('#edit_run_merge_target_period_start, #edit_run_merge_target_period_end').val('').datepicker('update').removeClass('is-invalid');
    }
    setEditMergeTargetMode($('input[name="editRunMergeTargetMode"]:checked').val() || 'existing');
    // 2026-09-02, 2nd same-day follow-up: .active on the pill <label> -- .run-subchoice-btn (was
    // .run-choice-card until this round's #edit_run_offcycle_panel redesign).
    $('#edit_run_merge_choice_row .run-subchoice-btn').removeClass('active');
    $(isReference ? '#edit_run_merge_choice_reference' : '#edit_run_merge_choice_new').closest('.run-subchoice-btn').addClass('active');
}
$(document).on('change', 'input[name="editRunMergeChoice"]', function () {
    setEditMergeChoiceMode($(this).val());
});
// 2026-09-06: mirrors setMergeTargetMode() in index.js, adapted to this modal's own edit_run_*
// field ids/name (editRunMergeTargetMode, distinct from the Create form's runMergeTargetMode).
function setEditMergeTargetMode(mode) {
    const isFutureCycle = mode === 'future_cycle';
    const targetRowShowing = !$('#edit_run_merge_target_row').hasClass('d-none');
    $('#edit_run_merge_target_existing_wrap').toggleClass('d-none', isFutureCycle);
    $('#edit_run_merge_target_future_cycle_wrap').toggleClass('d-none', !isFutureCycle);
    $('#edit_run_merge_target_id').toggleClass('required', targetRowShowing && !isFutureCycle);
    $('#edit_run_merge_target_cycle_id').toggleClass('required', targetRowShowing && isFutureCycle);
    $('#edit_run_merge_target_mode_row .run-subchoice-btn').removeClass('active');
    $(isFutureCycle ? '#edit_run_merge_target_mode_future_cycle' : '#edit_run_merge_target_mode_existing').closest('.run-subchoice-btn').addClass('active');
}
$(document).on('change', 'input[name="editRunMergeTargetMode"]', function () {
    const mode = $(this).val();
    setEditMergeTargetMode(mode);
    // A user-driven mode switch (not the initial populate-on-open) always clears the OTHER mode's
    // own field(s) -- see collectRunFormData()'s own comment in index.js for why both keys must
    // always be sent together, unambiguously, on every save.
    if (mode === 'future_cycle') {
        $('#edit_run_merge_target_id').val('').trigger('change.select2').removeClass('is-invalid');
        refreshEditRunMergeTargetPreviewRd();
    } else {
        $('#edit_run_merge_target_cycle_id').val('').trigger('change.select2').removeClass('is-invalid');
        $('#edit_run_merge_target_period_start, #edit_run_merge_target_period_end').val('').datepicker('update').removeClass('is-invalid');
        resetEditRunMergeTargetPreviewRd();
    }
});
$(document).on('change', '#edit_run_merge_target_cycle_id', function () {
    const cycleId = $(this).val();
    if (!cycleId) {
        $('#edit_run_merge_target_period_start, #edit_run_merge_target_period_end').val('').datepicker('update');
        resetEditRunMergeTargetPreviewRd();
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-cycle.suggest-period`, method: 'GET', data: { id: cycleId }, dataType: 'json',
        success: function (res) {
            if (!res.status) { return; }
            $('#edit_run_merge_target_period_start').val(toDisplayDateRd(res.period_start_date)).datepicker('update').removeClass('is-invalid');
            $('#edit_run_merge_target_period_end').val(toDisplayDateRd(res.period_end_date)).datepicker('update').removeClass('is-invalid');
            refreshEditRunMergeTargetPreviewRd();
        }
    });
});
// 2026-09-09, round-creation flow audit Bug 2 fix -- same mechanism as index.js's own
// refreshRunMergeTargetPreview()/resolveRunMergeTargetBeforeSubmit() (see those functions' own
// docblocks for the full reasoning), adapted to this modal's own edit_run_* field ids. The one real
// difference: `exclude_id: PAYROLL_RUN_ID` is always sent so this run never lists itself as a
// candidate to merge into.
let editRunMergeTargetPreviewMatches = null;
let editRunMergeTargetPreviewKey = null;
function resetEditRunMergeTargetPreviewRd() {
    editRunMergeTargetPreviewMatches = null;
    editRunMergeTargetPreviewKey = null;
    $('#edit_run_merge_target_preview_box').addClass('d-none');
    $('#edit_run_merge_target_preview_none, #edit_run_merge_target_preview_single, #edit_run_merge_target_preview_multi').addClass('d-none');
    $('#edit_run_merge_target_preview_select').empty().removeClass('is-invalid');
}
function editRunMergeTargetPreviewLabelRd(m) {
    const dateStr = typeof formatDisplayDate === 'function' ? formatDisplayDate(m.payment_date) : m.payment_date;
    return `${m.run_name} (${dateStr})`;
}
function renderEditRunMergeTargetPreviewRd(matches) {
    $('#edit_run_merge_target_preview_box').removeClass('d-none');
    $('#edit_run_merge_target_preview_none, #edit_run_merge_target_preview_single, #edit_run_merge_target_preview_multi').addClass('d-none');
    if (matches.length === 0) {
        $('#edit_run_merge_target_preview_none').removeClass('d-none').text(langData['run_merge_target_preview_none'] || 'No matching round yet -- this will wait until one is created.');
    } else if (matches.length === 1) {
        const tpl = langData['run_merge_target_preview_single'] || 'This will merge into: {name}';
        $('#edit_run_merge_target_preview_single').removeClass('d-none').text(tpl.replace('{name}', editRunMergeTargetPreviewLabelRd(matches[0])));
    } else {
        const $sel = $('#edit_run_merge_target_preview_select').empty().removeClass('is-invalid');
        $sel.append(new Option(langData['select_option'] || '-- Select --', ''));
        matches.forEach(m => $sel.append(new Option(editRunMergeTargetPreviewLabelRd(m), m.id)));
        $('#edit_run_merge_target_preview_multi').removeClass('d-none');
    }
}
function refreshEditRunMergeTargetPreviewRd() {
    const cycleId = $('#edit_run_merge_target_cycle_id').val();
    const periodStart = toIsoDateRd($('#edit_run_merge_target_period_start').val());
    if (!cycleId || !periodStart) {
        resetEditRunMergeTargetPreviewRd();
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.preview-merge-target`, method: 'GET',
        data: { cycle_id: cycleId, period_start_date: periodStart, exclude_id: PAYROLL_RUN_ID }, dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            editRunMergeTargetPreviewMatches = res.matches || [];
            editRunMergeTargetPreviewKey = cycleId + '|' + periodStart;
            renderEditRunMergeTargetPreviewRd(editRunMergeTargetPreviewMatches);
        }
    });
}
$(document).on('changeDate', '#edit_run_merge_target_period_start, #edit_run_merge_target_period_end', function () {
    refreshEditRunMergeTargetPreviewRd();
});
function resolveEditRunMergeTargetBeforeSubmitRd(callback) {
    const isReferenceMode = !$('#edit_run_merge_target_row').hasClass('d-none');
    const targetMode = $('input[name="editRunMergeTargetMode"]:checked').val() || 'existing';
    if (!isReferenceMode || targetMode !== 'future_cycle') {
        callback(true);
        return;
    }
    const cycleId = $('#edit_run_merge_target_cycle_id').val();
    const periodStart = toIsoDateRd($('#edit_run_merge_target_period_start').val());
    if (!cycleId || !periodStart) {
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
            const chosen = $('#edit_run_merge_target_preview_select').val();
            if (!chosen) {
                $('#edit_run_merge_target_preview_select').addClass('is-invalid');
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
    if (editRunMergeTargetPreviewKey === key && editRunMergeTargetPreviewMatches !== null) {
        decide(editRunMergeTargetPreviewMatches);
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.preview-merge-target`, method: 'GET',
        data: { cycle_id: cycleId, period_start_date: periodStart, exclude_id: PAYROLL_RUN_ID }, dataType: 'json',
        success: function (res) {
            const matches = (res.status && res.matches) ? res.matches : [];
            editRunMergeTargetPreviewMatches = matches;
            editRunMergeTargetPreviewKey = key;
            renderEditRunMergeTargetPreviewRd(matches);
            decide(matches);
        },
        error: function () { callback(true); }
    });
}
// 2026-09-02, explicit request: "การเลือกรอบการจ่าย แล้ว Default...ช่วยปรับทั้ง Form ตอนดึง Origami และ Form
// สร้างรอบใหม่ และ Form แก้ไขรอบ" -- same PayrollCycleModel::suggestNextPeriod() endpoint the Create
// form's own applySuggestedPeriod() (index.js) already calls, just missing here until now. Only ever
// fires on a genuine user-driven pick (plain 'change', not the 'change.select2' this modal's own
// initial-population code uses to show the run's REAL existing dates without recomputing anything --
// see #btnEditRun's own handler above) -- so opening Edit on an already-cycle-linked run never
// silently overwrites its real period with a freshly "suggested" one; only actually switching to a
// different cycle during this same edit does.
// 2026-09-09, explicit request: "เปลี่ยนเป็นเลือกรอบแล้ว ถ้าวันที่มีข้อมูลอยู่ไม่ต้องเปลี่ยนค่า ถ้าไม่มีค่าให้ใส่
// อัตโนมัติ" -- this docblock's own earlier reasoning ("only fires on a genuine user-driven pick...
// only actually switching to a different cycle during this same edit does [overwrite]") is now ONE
// LEVEL more precise: switching cycle during an edit no longer overwrites a date field that already
// holds a real value either -- only a genuinely EMPTY field gets auto-filled. Matches index.js's own
// applySuggestedPeriod() for the exact same reason.
function applySuggestedPeriodRd(cycleId) {
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
            if (!$('#edit_period_start').val()) {
                $('#edit_period_start').val(toDisplayDateRd(res.period_start_date)).datepicker('update');
            }
            if (!$('#edit_period_end').val()) {
                $('#edit_period_end').val(toDisplayDateRd(res.period_end_date)).datepicker('update');
            }
            if (!$('#edit_payment_date').val()) {
                $('#edit_payment_date').val(toDisplayDateRd(res.payment_date)).datepicker('update');
            }
            $('#edit_period_start, #edit_period_end, #edit_payment_date').removeClass('is-invalid');
        }
    });
}
$(document).on('change', '#edit_run_cycle_id', updateEditRunTypeSectionRd);
$(document).on('change', '#edit_run_cycle_id', function () {
    applySuggestedPeriodRd($(this).val());
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

    // 2026-09-01, explicit request: cycle reference now editable here too (always enabled -- see
    // editRunCycleToggleRiskyRd()'s own docblock for why a blanket disable was loosened).
    const $cycleSel = $('#edit_run_cycle_id');
    if (currentRun.cycle_id) {
        $cycleSel.empty().append(new Option(currentRun.cycle_name || String(currentRun.cycle_id), currentRun.cycle_id, true, true)).trigger('change.select2');
    } else {
        $cycleSel.val(null).trigger('change.select2');
    }

    // 2026-09-02, explicit request: "ยังไม่เหมือนหน้าเพิ่มรอบในหน้า List ครับ ขาด รอบพิเศษนอกรอบเงินเดือน" --
    // the checkbox is purely a visual affordance mirroring what the cycle field's own emptiness
    // already meant before this existed -- hidden entirely for a sync-linked run (that data is
    // inherently cycle-based/governed by sync_run_kind instead, same reasoning
    // #run_offcycle_row is hidden for a Pull-sync create).
    const currentlyOffCycle = !currentRun.cycle_id && !currentRun.sync_process_id;
    $('#edit_run_offcycle_row').toggleClass('d-none', !!currentRun.sync_process_id);
    $(currentlyOffCycle ? '#edit_run_schedule_choice_offcycle' : '#edit_run_schedule_choice_cycle').prop('checked', true);
    setEditOffCycleMode(currentlyOffCycle);

    // 2026-09-01/02, same-day follow-up, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" then "ขาด...
    // เปิดรอบใหม่ อ้างอิงถึงรอบที่มีอยู่ และไม่ติ๊ก Auto" -- populate the merge-target field with the run's
    // current value (or clear it), and never offer this run as its own merge target. Must run BEFORE
    // updateEditRunTypeSectionRd() below, since that function only ever CLEARS this field (when the
    // run turns out not to be off-cycle) -- it never populates it. Radio defaults to "new"/unticked
    // unless the run genuinely already has a merge target set -- never pre-ticked as "reference"
    // otherwise, per the explicit "ไม่ติ๊ก Auto" instruction.
    $('#edit_run_merge_target_id').attr('data-exclude-id', PAYROLL_RUN_ID);
    const $mergeTargetSel = $('#edit_run_merge_target_id');
    const hasMergeTarget = !!currentRun.merge_target_run_id;
    if (hasMergeTarget) {
        $mergeTargetSel.empty().append(new Option(currentRun.merge_target_run_name || String(currentRun.merge_target_run_id), currentRun.merge_target_run_id, true, true)).trigger('change.select2');
    } else {
        $mergeTargetSel.val(null).trigger('change.select2');
    }
    // 2026-09-06: same populate-on-open treatment for the "future cycle" form -- 'change.select2'
    // (not plain 'change') so opening Edit on an already-waiting run shows its REAL current target
    // cycle/period without re-suggesting/overwriting them (mirrors #edit_run_cycle_id's own
    // reasoning right above -- see applySuggestedPeriodRd()'s own docblock).
    const hasFutureCycleTarget = !hasMergeTarget && !!currentRun.merge_target_cycle_id;
    const $mergeTargetCycleSel = $('#edit_run_merge_target_cycle_id');
    if (hasFutureCycleTarget) {
        $mergeTargetCycleSel.empty().append(new Option(currentRun.merge_target_cycle_name || String(currentRun.merge_target_cycle_id), currentRun.merge_target_cycle_id, true, true)).trigger('change.select2');
        $('#edit_run_merge_target_period_start').val(toDisplayDateRd(currentRun.merge_target_period_start_date));
        $('#edit_run_merge_target_period_end').val(toDisplayDateRd(currentRun.merge_target_period_end_date));
        // .datepicker('update') after programmatic .val() -- see CLAUDE.md's bootstrap-datepicker
        // note (widget state goes stale otherwise, blanking the field on next click-away) -- same
        // real bug ("Date เลือกไม่ได้") this pair of fields was fixed for on 2026-09-09.
        $('#edit_run_merge_target_period_start, #edit_run_merge_target_period_end').datepicker('update');
        // 2026-09-09, round-creation flow audit Bug 2 fix -- shows the current match state
        // immediately on open for a run that's already waiting on a future-cycle target, instead of
        // only appearing after the admin touches the cycle/period fields themselves.
        refreshEditRunMergeTargetPreviewRd();
    } else {
        $mergeTargetCycleSel.val(null).trigger('change.select2');
        $('#edit_run_merge_target_period_start, #edit_run_merge_target_period_end').val('').datepicker('update');
        resetEditRunMergeTargetPreviewRd();
    }
    // 2026-09-09, round-creation flow audit Phase 3: single call replaces the old 4-line
    // set-both-legacy-radios-then-call-both-setters sequence -- syncEditRunMergeIntoUiRd() drives the
    // exact same cascade (see that function's own comment).
    syncEditRunMergeIntoUiRd(hasFutureCycleTarget ? 'future_cycle' : (hasMergeTarget ? 'existing' : 'standalone'));
    updateEditRunTypeSectionRd();

    if (isOffCycleRunRd(currentRun) || (currentRun.sync_process_id && currentRun.sync_run_kind === 'supplemental')) {
        syncEditRunPurposeChoiceUiRd(currentRun.run_purpose || 'payroll');
        $('#edit_run_compute_statutory').prop('checked', Number(currentRun.compute_statutory) === 1);
        $('#edit_run_include_base_salary').prop('checked', Number(currentRun.include_base_salary) === 1);
        $('#edit_run_include_standing_items').prop('checked', Number(currentRun.include_standing_items) === 1);
        $('#edit_run_include_attendance_pay').prop('checked', Number(currentRun.include_attendance_pay) === 1);
        // 2026-09-09, round-creation flow audit Bug 1 fix: reflects the run's CURRENT stored value --
        // deliberately NOT the Create form's own "pre-check when Origami attributed
        // tax_treatment='separate'" default logic (setSupplementalPullMode()), since that default only
        // makes sense the first time this choice is ever made; Edit must show what was actually saved.
        $('#edit_run_use_flat_tax_rate').prop('checked', Number(currentRun.use_flat_tax_rate) === 1);
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
    // 2026-09-09, round-creation flow audit Bug 2 fix -- re-checks the future_cycle auto-match
    // immediately before saving (see resolveEditRunMergeTargetBeforeSubmitRd()'s own docblock),
    // possibly overriding the merge_target_* fields below with a resolved merge_target_run_id.
    resolveEditRunMergeTargetBeforeSubmitRd(function (ok, overrides) {
        if (!ok) return;
        submitEditRunForm(overrides);
    });
});
function submitEditRunForm(mergeTargetOverrides) {
    const payload = {
        id: PAYROLL_RUN_ID,
        run_name: $('#edit_run_name').val().trim(),
        period_start_date: toIsoDateRd($('#edit_period_start').val()),
        period_end_date: toIsoDateRd($('#edit_period_end').val()),
        payment_date: toIsoDateRd($('#edit_payment_date').val()),
        notes: $('#edit_notes').val().trim(),
        // 2026-09-01: always sent (even when the field is disabled/locked -- jQuery .val() still
        // reads a disabled select's current value fine) -- PayrollRunModel::update() itself is the
        // one true gate on whether this can actually change anything (employee_count===0), so
        // sending the unchanged current value when locked is a safe no-op there.
        cycle_id: $('#edit_run_cycle_id').val() || null,
    };
    // 2026-09-01, same-day follow-up, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" -- only sent when
    // the field is actually showing (a genuine off-cycle run), same conditional-inclusion pattern as
    // the run-type fields below -- PayrollRunModel::update() itself also refuses this for any run
    // that's cycle-linked or sync-linked, so this omission just keeps the payload honest.
    if (!$('#edit_run_merge_target_row').hasClass('d-none')) {
        // 2026-09-06: both keys ALWAYS sent together here (one truthy, the other explicitly null,
        // depending on editRunMergeTargetMode) -- PayrollRunModel::resolveMergeTargetSpec() resolves
        // each independently and refuses an ambiguous "both non-null" result, so switching modes
        // without explicitly clearing the other one would otherwise be rejected. See that method's
        // own docblock and collectRunFormData()'s matching comment in index.js.
        const editTargetMode = $('input[name="editRunMergeTargetMode"]:checked').val() || 'existing';
        payload.merge_target_run_id = editTargetMode === 'future_cycle' ? null : ($('#edit_run_merge_target_id').val() || null);
        payload.merge_target_cycle_id = editTargetMode === 'future_cycle' ? ($('#edit_run_merge_target_cycle_id').val() || null) : null;
        payload.merge_target_period_start_date = editTargetMode === 'future_cycle' ? toIsoDateRd($('#edit_run_merge_target_period_start').val()) : null;
        payload.merge_target_period_end_date = editTargetMode === 'future_cycle' ? toIsoDateRd($('#edit_run_merge_target_period_end').val()) : null;
        // 2026-09-09, round-creation flow audit Bug 2 fix -- when resolveEditRunMergeTargetBeforeSubmitRd()
        // resolved the future_cycle spec to a specific existing run (either the single match, or the
        // admin's explicit disambiguation pick among 2+), these override the future_cycle fields set
        // just above with an explicit merge_target_run_id instead.
        Object.assign(payload, mergeTargetOverrides || {});
    }
    // Sent whenever the run-type section is actually showing right now (genuine off-cycle OR
    // supplemental sync) -- reads the LIVE dropdown state (updateEditRunTypeSectionRd()), not just
    // currentRun's state before this edit, since converting cycle<->off-schedule during this same
    // save is now possible (see the cycle_id field above). PayrollRunModel::update() ignores these
    // fields entirely for a run that (after this save) ends up cycle-linked anyway, but omitting
    // them here keeps the payload honest about what's actually visible/being changed.
    if (!$('#edit_run_type_section').hasClass('d-none')) {
        payload.run_purpose = $('#edit_run_purpose').val();
        payload.compute_statutory = $('#edit_run_compute_statutory').is(':checked');
        payload.include_base_salary = $('#edit_run_include_base_salary').is(':checked');
        payload.include_standing_items = $('#edit_run_include_standing_items').is(':checked');
        payload.include_attendance_pay = $('#edit_run_include_attendance_pay').is(':checked');
        // 2026-09-09, round-creation flow audit Bug 1 fix: this key was never sent at all before --
        // PayrollRunModel::update() now only touches use_flat_tax_rate when this key is present
        // (see that method's own fix), but the underlying bug was HERE: without this line, a
        // previously-saved true value had no way to survive any edit-save whatsoever.
        payload.use_flat_tax_rate = $('#edit_run_use_flat_tax_rate').is(':checked');
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                // 2026-09-01: surfaces the backend's own message when it has something more specific
                // to say (e.g. "Updated and recalculated successfully." after the off-cycle<->cycle
                // toggle forces a real Recalculate -- see PayrollRunModel::update()'s own comment)
                // instead of always showing the generic save_success text.
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
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
}
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
function activateTabFromHash() {
    const hash = (location.hash || '').replace('#', '');
    if (!hash) return;
    const $btn = $('#' + CSS.escape(hash));
    if ($btn.length && $btn.attr('data-bs-toggle') === 'tab') {
        bootstrap.Tab.getOrCreateInstance($btn[0]).show();
    }
}
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
        // 2026-09-09, real bug found and fixed (explicit report: "วันเริ่มต้นงวดอ้างอิง และ วันสิ้นสุดงวด
        // อ้างอิง Date เลือกไม่ได้") -- these were plain readonly display fields (no `.datepicker` class,
        // never initDatepicker()'d), auto-filled ONLY by picking a Target cycle above with no way to
        // adjust by hand -- same fix as index.js's own Create form's #run_merge_target_period_start/end.
        initDatepicker('#edit_run_merge_target_period_start');
        initDatepicker('#edit_run_merge_target_period_end');
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#joinFilterDepartment, #joinFilterTeam, #joinFilterPosition, #joinFilterCycle', { mode: 'ajax' });
        initSelect2('#manualLineItemSelect', { mode: 'ajax' });
        initSelect2('#manualLineCustomType', { mode: 'static', selectedValue: 'earning' });
        // Initialized once here, not per-modal-open (2026-08-21 bug fix precedent from the
        // Attendance Deduction rate_unit dropdown -- re-initializing a select2 field on every open
        // can leave stale state/duplicate options behind).
        initSelect2('#manualLinePayeeEmployee', { mode: 'ajax', allowClear: true });
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
        initSelect2('#recurringDestPayeeEmployeeSelect', { mode: 'ajax', allowClear: true });
        initSelect2('#recurringDestDestinationSelect', { mode: 'ajax', allowClear: true });
        initSelect2('#recurringDestBank', { mode: 'ajax' });
        // 2026-09-09, round-creation flow audit Phase 3: #edit_run_purpose is a plain hidden input
        // now (driven by #edit_run_purpose_choice_row's own choice cards), not a <select> -- no
        // select2 init needed/possible anymore.
        // 2026-09-01: allowClear so an empty selection is a real, reachable "off-schedule/no cycle"
        // choice, same either/or #run_cycle_id represents on the Create form.
        initSelect2('#edit_run_cycle_id', { mode: 'ajax', allowClear: true });
        // 2026-09-01, same-day follow-up, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" -- allowClear
        // so an empty selection genuinely means "no merge planned", same as the Create form's own
        // #run_merge_target_id.
        initSelect2('#edit_run_merge_target_id', { mode: 'ajax', allowClear: true });
    }
    });
});
