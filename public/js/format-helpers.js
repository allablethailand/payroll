/**
 * Format Helpers -- Backlog Phase 11, T065 ("consolidate duplicated helper functions (JS/PHP)").
 * Loaded globally via layout/header.php (same convention as input.js/table-column-filter.js) so
 * every page gets these with zero per-page setup.
 *
 * escapeHtml()/escapeAttr() replace ~30 near-identical per-file copies that had accumulated across
 * this app (escapeHtmlPc/escapeHtmlDn/escapeHtmlTs/escapeHtmlBff/escapeHtmlPo/escapeHtmlPr/
 * escapeHtmlRd/escapeHtmlSr/escapeAttrRd/escapeAttrSr/etc.) -- confirmed byte-identical logic
 * across every one of them before merging, this file changes zero real behavior anywhere.
 * escapeAttr() is the composable "plain escape + quote-escape" wrapper (mirrors the
 * escapeHtmlRd+escapeAttrRd / escapeHtmlSr+escapeAttrSr two-function pattern a couple of the
 * original per-file copies already used), not a 3rd fused shape.
 *
 * fmtNum() replaces fmtNumPr/fmtNumRd/fmtNumAp/fmtNumRa/fmtNumTs (payroll/index.js, payroll/
 * detail.js, payroll/approval.js, reports/run-audit.js, tax-statutory.js) -- uses fmtNumRa's own
 * shape (the richest of the 5: 'XXXX' salary-mask passthrough + null/undefined/''->'-' guard),
 * a strict superset of what every other caller needed, so merging changes no existing caller's
 * real output.
 *
 * escapeAttr() also escapes a literal single-quote (-> &#39;), matching the 2 richest existing
 * copies (escapeHtmlAl in audit-log.js, escapeHtmlRa in reports/run-audit.js, both of which used a
 * regex covering &<>"' instead of the jQuery .text()/.html() round-trip + manual ".replace(/"/g...)"
 * pattern most other copies used) -- a strict superset of every weaker copy's own protection, never
 * a reduction, and HTML-entity-safe either way (a browser renders &#39; back to a literal ' when
 * displaying the page, so this is not a visible behavior change for any existing caller).
 */
function escapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
/** 2026-09-07, Announcement CMS rich-text formatting -- body_th/body_en are now real HTML (Quill),
 *  so a plain-text EXCERPT (dashboard card preview, notification text) needs the markup stripped
 *  first rather than truncating raw HTML mid-tag. Uses a detached DOM element (not a regex) so
 *  entity-encoded content decodes correctly (e.g. "&amp;" -> "&") -- the same reasoning escapeHtml()
 *  above already relies on the DOM for. Never insert the RETURN VALUE back as HTML (it's plain text,
 *  from an already-trusted-HTML source at that -- see AnnouncementModel::sanitizeRichHtml()). */
function stripHtml(html) {
    if (html === null || html === undefined) return '';
    const el = document.createElement('div');
    el.innerHTML = String(html);
    return (el.textContent || el.innerText || '').replace(/\s+/g, ' ').trim();
}
function fmtNum(value) {
    if (value === 'XXXX') return 'XXXX';
    if (value === null || value === undefined || value === '') return '-';
    const num = Number(value);
    return isNaN(num) ? '-' : num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
/**
 * Round 2 item 7a (docs/design/rules.md §8) -- the ONE shared way to turn a `.money-input`'s
 * on-screen value (which may carry commas, since initMoneyInputs()/app.js formats it with them on
 * blur) back into a plain number for sending to the server. Deliberately a pure string->number
 * parser with no DOM dependency (usable from a jQuery element's `.val()` OR any other string source)
 * -- returns `null` for anything that isn't a real number after stripping commas, never NaN/''.
 *
 * This app has NO single central form-serializer to "strip the comma in" -- every real page builds
 * its own bespoke `collect*FormData()` (collectRunFormData/collectRcFormData/
 * collectEmployeeFormData/collectEedFormData/collectPedTypeFormData/collectCycleFormData/
 * collectSrDetailsFormData/collectSrRateVersionFormData -- 8 separate per-page functions, confirmed
 * by grepping every `function collect*FormData` in the app, not assumed). Round 2 does not touch
 * real page templates (§13), so none of those 8 are migrated to call this here -- this function is
 * the single shared piece a future collectXxxFormData() calls instead of hand-rolling its own
 * `.replace(/,/g, '')`, once a page's own form actually adopts `.money-input` in round 4.
 */
function parseMoneyInput(str) {
    if (str === null || str === undefined || str === '') return null;
    const num = Number(String(str).replace(/,/g, ''));
    return isNaN(num) ? null : num;
}
/**
 * 2026-09-14, Round 3 item 3c-3 (Comments timeline), explicit instruction: relative time
 * ("N นาทีที่แล้ว") for a comment's timestamp, full absolute date+time as its hover tooltip (the
 * CALLER attaches that via `formatDisplayDateTime()`, app.js -- not duplicated here). JS-only on
 * purpose: this app has no PHP-side `langData`/`getLangValue()` (confirmed -- see this same
 * codebase's other PHP partials' own docblocks on that point), so a translated relative-time string
 * can only ever be produced client-side; `renderTimeline()`'s own PHP twin (timeline.php) is left
 * untouched (still plain HH:MM, its own already-shipped §6 spec) since it has no real page caller
 * that would need this today. Same naive Date-diff shape formatDisplayDateTime() already uses
 * (naive 'YYYY-MM-DD HH:MM:SS' strings from this app's DB are treated as UTC).
 * Buckets: <60s "just now", <60m "N minutes ago", <24h "N hours ago", <7d "N days ago", else falls
 * back to the plain absolute date (formatDisplayDate()) -- a week-plus-old comment reads better as
 * a real date than "9 days ago".
 */
function formatRelativeTime(value) {
    if (!value) return '';
    let isoUtc = String(value).trim().replace(' ', 'T');
    if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(isoUtc)) isoUtc += 'Z';
    const d = new Date(isoUtc);
    if (isNaN(d.getTime())) return String(value);
    const diffSec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
    const ld = (typeof langData !== 'undefined' && langData) ? langData : {};
    if (diffSec < 60) return ld['time_just_now'] || 'Just now';
    if (diffSec < 3600) return (ld['time_minutes_ago'] || '{n} minutes ago').replace('{n}', String(Math.floor(diffSec / 60)));
    if (diffSec < 86400) return (ld['time_hours_ago'] || '{n} hours ago').replace('{n}', String(Math.floor(diffSec / 3600)));
    if (diffSec < 7 * 86400) return (ld['time_days_ago'] || '{n} days ago').replace('{n}', String(Math.floor(diffSec / 86400)));
    return typeof formatDisplayDate === 'function' ? formatDisplayDate(value) : String(value).substring(0, 10);
}

/* ==========================================================================
   payroll_run_details.calc_errors -- machine code -> readable sentence.
   2026-09-21, 3e-2a: moved here VERBATIM from public/js/payroll/detail.js (lines 63-123 there) so
   the Payroll LIST page can show the same sentences the Detail page already did -- index.js was
   printing raw codes to the admin because the only translator in the app lived inside a file it
   does not load (see docs/decisions/2026-09-21-3e2a-calc-badges.md). Same names, same behavior for
   every existing caller; the 3 additions are documented at their own lines below.
   ========================================================================== */
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
    // 2026-09-21, 3e-2a: prorate_days === 0 has no engine code of its own (it is derived client-side
    // by calcAdvisoryCodesRd() below, see its docblock) -- this is where that derived code gets its
    // sentence, so it reads identically to a real one everywhere both are shown.
    if (code.indexOf('prorate_zero_days:') === 0) {
        const parts = code.substring('prorate_zero_days:'.length).split('/');
        const tpl = langData['calc_error_prorate_zero_days'] || 'Base salary for this period works out to {days} of {total} days -- check the employment start/end dates, the assigned shift and holidays, and any leave in this period.';
        return tpl.replace('{days}', parts[0] || '0').replace('{total}', parts[1] || '?');
    }
    // 2026-09-21, 3e-2a: SyncPayResolver::resolve() pushes missing_ot_rate_{scope} (SyncPayResolver.php:438)
    // for a scope that has real OT hours but no rate resolvable from either source in its own priority
    // chain -- the employee's per-scope custom rate, then the OT Rate Set they resolve to. Origami's 3
    // scopes (weekday/weekend/holiday, SyncPayResolver.php:78-80) each get their own sentence so the
    // scope name reads naturally in Thai; any 4th scope Origami ever adds falls to the {scope} template
    // rather than back to the raw code.
    if (code.indexOf('missing_ot_rate_') === 0) {
        const scope = code.substring('missing_ot_rate_'.length);
        const perScope = langData['calc_error_missing_ot_rate_' + scope];
        if (perScope) return perScope;
        const tpl = langData['calc_error_missing_ot_rate'] || 'No OT rate is configured for "{scope}", although OT hours were sent for it -- set the rate on the OT Rate Set this employee uses (Time & Leave > Setup & Rules > OT Rate), or per-employee on their OT tab.';
        return tpl.replace('{scope}', scope);
    }
    // 2026-09-21, 3e-2a: an unrecognized code used to be shown RAW to the admin (`return code`), which
    // is a machine identifier leaking into the screen -- it now reads as one neutral sentence, and the
    // code itself survives only in the caller's own data-code attribute (calcErrorItemsRd() below).
    return langData['calc_error_unknown'] || 'This row has a note the app does not recognize -- report it if it keeps appearing.';
}
// 2026-09-16: the server already splits calc_errors into calc_warnings/calc_blocking
// (PayrollRunModel::splitCalcErrors(), one advisory list shared with recalculate()) -- these 2 just
// translate whichever list they are handed. Nothing here decides advisory-vs-blocking anymore.
function calcErrorMessagesRd(codes) {
    return (codes || []).map(calcErrorMessageRd);
}
/* 2026-09-21, 3e-2a: the same list of codes is rendered in 2 places that must not drift -- the
   Calculation column's own popovers and the Calculation Breakdown modal's callouts -- and BOTH now
   have to carry the raw code in a `data-code` attribute (rules.md: a machine code never reaches the
   screen as text, but it must stay findable when someone reports a row). Returning {code, message}
   pairs is what lets each caller build its own element without re-deriving either half.
   calcErrorMessagesRd() above keeps its exact signature/return shape -- it is now this function's
   thinnest possible caller, so no existing call site had to change to gain the new sentences. */
function calcErrorItemsRd(codes) {
    return (codes || []).map(code => ({ code: code, message: calcErrorMessageRd(code) }));
}
/* 2026-09-21, 3e-2a, explicit instruction ("advisory prorate 0"): the ONE place that decides which
   advisory notes a row has. `payroll_run_details.prorate_days === 0` means this row's base salary
   was prorated down to nothing -- a real thing to check before paying, which the engine has no code
   for (it is a legitimate result of 4 different branches of recalculate(), not an error in any of
   them, see docs/decisions/2026-09-21-3e2a-calc-badges.md). Derived here rather than pushed by the
   engine so this stays a display concern: nothing about calc_status or submit eligibility changes.

   "Not prorated at all" and "prorated to nothing" are different statements, and only the second one
   is worth saying. NULL (every incentive/off-cycle run, and every branch of recalculate() that never
   prorates), undefined and '' are therefore all silent, and they are ruled out BEFORE any numeric
   coercion -- Number(null) and Number('') are both 0, so coercing first would have made the note
   fire on every run that never prorated anything.

   Everything that survives that guard goes through Number(): PayrollRunModel::getDetails() does not
   cast this column (it casts line_override_count/manual_line_count/adjustment_count and leaves this
   one alone), so PDO hands the client the STRING "0", not 0. A `typeof === 'number'` test here read
   as correct and fired on nothing at all in the real app -- found by this round's own Playwright
   cell reporting expectedAdvisory 0 for the fixture row built specifically to have prorate_days = 0.
   prorate_total_days rides along in the code string so the sentence can say 0 of WHAT.

   Suppressed when the row already carries hourly_salary_no_attendance_data: that code is pushed by
   the very branch (PayrollRunModel.php:3529-3544) whose own 0-days result this would be restating,
   and it already names the real cause. Two notes for one fact reads as two things to fix. */
function calcAdvisoryCodesRd(row) {
    const codes = (row && row.calc_warnings) ? row.calc_warnings.slice() : [];
    const days = row ? row.prorate_days : null;
    const isZero = days !== null && days !== undefined && days !== '' && Number(days) === 0;
    if (isZero && codes.indexOf('hourly_salary_no_attendance_data') === -1) {
        const total = (row && (row.prorate_total_days || row.prorate_total_days === 0)) ? row.prorate_total_days : '?';
        codes.push('prorate_zero_days:0/' + total);
    }
    return codes;
}
