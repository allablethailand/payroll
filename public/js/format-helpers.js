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
