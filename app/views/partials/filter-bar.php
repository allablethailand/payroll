<?php
/**
 * Filter bar -- docs/design/rules.md §6. Replaces the old `.station-filter` pattern (15 files per
 * docs/design/audit.md -- kept working today, NOT itself a violation, just missing §6's chip display
 * and carrying 2 look-and-feel issues §6 explicitly retires: a fieldset/legend-style corner label
 * ("ตัวกรอง" floating over the border) and per-field `<label>` icons, e.g. `.station-filter-body`'s
 * own `<label class="form-label small mb-1"><i class="fa-solid fa-calendar ...">...` -- confirmed
 * ~140 such icon-prefixed field labels across 18 files app-wide, round 4's own migration count).
 *
 * Deliberately a THIN wrapper when expanded (decided this round, after the first version of this
 * file imposed its own `.row.g-2` around the caller's fields) -- `$filter_fields_html` must be the
 * caller's OWN EXISTING `.station-filter-body` inner content copied VERBATIM, including that
 * content's own `<div class="row g-X">...</div>` wrapper and column classes (`col-sm-2` etc.) --
 * this partial adds NO grid/gutter class of its own, so a page's real field markup moves here in
 * round 4 completely unchanged (just minus each label's own icon, and minus the outer
 * `.station-filter-body`/`.station-filter-label`/`.station-filter-clear-row` wrapper markup, which
 * this partial's own shell now owns instead).
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
 *                                  here in round 4, don't carry it over).
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
 *   // Optional: relocate the toggle/count/clear/chips row (`.filter-bar-toolbar`) into an existing
 *   // container elsewhere on the page instead of leaving it in its own row -- e.g. flush right of a
 *   // status-tabs.php pipeline. The collapsible field panel (`.filter-bar-body`) stays wherever this
 *   // partial itself was included -- only the toolbar row physically moves.
 *   initFilterBar('#employeeFilter', { toolbarTarget: '#employeeStatusTabs', onChange: ... });
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
    <div class="filter-bar-toolbar">
        <button type="button" class="btn btn-outline-secondary btn-sm filter-bar-toggle">
            <span data-i18n="label_filter">ตัวกรอง</span> (<span class="filter-bar-count">0</span>)
        </button>
        <button type="button" class="btn btn-link btn-sm filter-bar-clear d-none"><span data-i18n="clear_filter">ล้าง</span></button>
        <div class="filter-bar-chips d-none"></div>
    </div>
    <div class="filter-bar-body">
        <?=$filter_fields_html?>
    </div>
</div>
