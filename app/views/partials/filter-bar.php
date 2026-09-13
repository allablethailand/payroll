<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Filter bar -- docs/design/rules.md §6. Replaces the old `.station-filter` pattern (15 files per
 * docs/design/audit.md -- kept working today, NOT itself a violation, just missing §6's chip display
 * and carrying 2 look-and-feel issues §6 explicitly retires: a fieldset/legend-style corner label
 * ("ตัวกรอง" floating over the border) and per-field `<label>` icons, e.g. `.station-filter-body`'s
 * own `<label class="form-label small mb-1"><i class="fa-solid fa-calendar ...">...` -- confirmed
 * ~140 such icon-prefixed field labels across 18 files app-wide, round 4's own migration count).
 *
 * 2026-09-13, revised again after explicit feedback into a 3-part panel (header / collapsible body /
 * always-visible footer) -- see this file's own git history for the earlier single-toolbar-row shape.
 * Deliberately a THIN wrapper when expanded (unchanged from the earlier revision) -- `$filter_fields_html`
 * must be the caller's OWN EXISTING `.station-filter-body` inner content copied VERBATIM, including
 * that content's own `<div class="row g-X">...</div>` wrapper and column classes (`col-sm-2` etc.) --
 * this partial adds NO grid/gutter class of its own, so a page's real field markup moves here in
 * round 4 completely unchanged (just minus each label's own icon, and minus the outer
 * `.station-filter-body`/`.station-filter-label`/`.station-filter-clear-row` wrapper markup, which
 * this partial's own shell now owns instead).
 *
 * Panel shape (3 parts, header/footer independent of the collapse state):
 *   - HEADER (`.filter-bar-header`, always visible): left = "ตัวกรอง (N)" label (count only shown
 *     when N>0), right = one `.btn-icon` circle (the SAME row-action circle spec every other part of
 *     the app uses, §7) whose chevron rotates to reflect expanded/collapsed state -- this is the
 *     ONLY expand/collapse control now; the old text "ตัวกรอง (N)" button is gone.
 *   - BODY (`.filter-bar-body`, collapsible): the caller's own field grid, unchanged mechanism.
 *   - FOOTER (`.filter-bar-footer`, ALWAYS visible regardless of collapse state -- decided this
 *     round, "ติดล่างของแผงเสมอ"): left = one chip per active filter, each now "label: ค่า ×" (the
 *     field's own <label> text + its selected option's text -- widened from the earlier revision's
 *     value-only chip, which gave no context for what was being filtered without also glancing at
 *     the header count), or a plain muted "ไม่ได้กรอง" string when N=0 (`filter_bar_empty` i18n key,
 *     new this round) so the footer is never a blank strip; right = the tertiary "ล้างตัวกรอง"
 *     button (`.btn.btn-link` per rules.md §4's own Tertiary row), hidden when N=0.
 *
 * Variables the calling view must set BEFORE including this file:
 *
 * @var string $id                 Required. Unique id prefix for this bar's DOM (e.g. 'employeeFilter')
 *                                  -- must be unique per page if a page has more than one filter bar
 *                                  (e.g. one per tab, same as annual-summary.php's own 4 separate
 *                                  .station-filter instances today).
 * @var string $filter_fields_html Required. The caller's own pre-rendered filter field markup,
 *                                  copied verbatim from what it already builds for
 *                                  `.station-filter-body` today (own `.row`/column classes and all)
 *                                  -- captured via output buffering. No icon in front of any field's
 *                                  own <label> (§6 -- strip it when migrating a page's real markup
 *                                  here in round 4, don't carry it over). Each field's `<label>` must
 *                                  stay a SIBLING of its `<select>` (e.g. both direct children of the
 *                                  same `.col-sm-2` div, this app's existing convention) -- the chip
 *                                  label text is read from `$select.siblings('label')`.
 * @var string|null $pageKey       Optional. When set, this bar's own expanded/collapsed state
 *                                  persists in `localStorage['filterbar:' + pageKey]` across page
 *                                  reloads (initFilterBar() reads/writes it) -- omit entirely for a
 *                                  bar that should never remember its state (e.g. one that should
 *                                  always start collapsed).
 *
 * JS pairing (see public/js/app.js's own initFilterBar() docblock for the full API):
 *   initFilterBar('#employeeFilter', {
 *       onChange: function () { reloadEmployeeTable(); }  // caller's own reload logic
 *   });
 *   -- the earlier `toolbarTarget` option (relocating the toolbar row into a Status Tabs row) has
 *   been REMOVED this round ("ยกเลิก option toolbarTarget") -- the panel (header+body+footer, all of
 *   it) now always renders in normal document flow wherever this partial was included, e.g. directly
 *   under a status-tabs.php pipeline for the Payroll Process page's own full layout.
 *
 * Example call site (round 4, not written yet -- this file has no consumer this round):
 *   ob_start(); ?>
 *     <div class="row g-3">
 *       <div class="col-sm-2"><label class="form-label small mb-1">แผนก</label><select ...></div>
 *       <div class="col-sm-2"><label class="form-label small mb-1">สถานะ</label><select ...></div>
 *     </div>
 *   <?php $filter_fields_html = ob_get_clean();
 *   $id = 'employeeFilter';
 *   $pageKey = 'employee-list';
 *   include __DIR__ . '/../partials/filter-bar.php';
 */
$pageKeyAttr = !empty($pageKey) ? ' data-page-key="' . htmlspecialchars($pageKey) . '"' : '';
?>
<div class="filter-bar collapsed" id="<?=htmlspecialchars($id)?>"<?=$pageKeyAttr?>>
    <div class="filter-bar-header">
        <span class="filter-bar-label">
            <span data-i18n="label_filter">ตัวกรอง</span><span class="filter-bar-count-wrap d-none"> (<span class="filter-bar-count">0</span>)</span>
        </span>
        <button type="button" class="btn-icon filter-bar-toggle" aria-label="Toggle filter">
            <i class="fa-solid fa-chevron-down"></i>
        </button>
    </div>
    <div class="filter-bar-body">
        <?=$filter_fields_html?>
    </div>
    <div class="filter-bar-footer">
        <div class="filter-bar-footer-left">
            <div class="filter-bar-chips"></div>
            <span class="filter-bar-empty-text" data-i18n="filter_bar_empty">ไม่ได้กรอง</span>
        </div>
        <button type="button" class="btn btn-link btn-sm filter-bar-clear d-none"><span data-i18n="clear_filter">ล้างตัวกรอง</span></button>
    </div>
</div>
