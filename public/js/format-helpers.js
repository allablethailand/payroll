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
