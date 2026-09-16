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
 * 2026-09-13, restructured AGAIN after live-page feedback (Payroll Detail) -- the FOOTER row is gone
 * entirely now. Confirmed reasoning ("ตอนกาง ซ่อน chips เหลือแค่ปุ่ม 'ล้างตัวกรอง'...ย้ายไปอยู่แถวหัวขวา ข้าง
 * chevron แล้วตัดแถวท้ายทิ้งตอนกาง"): chips are now a PERMANENT child of `.filter-bar-header` itself
 * (never relocated via JS anymore -- pure CSS shows/hides them by collapse state, see below), and the
 * "ล้างตัวกรอง" button moved into the header too, permanently, next to the toggle -- once both of the
 * footer's only 2 possible contents live in the header instead, the footer row itself has nothing left
 * to ever show, in either state, so it's removed rather than kept as a permanently-empty wrapper. See
 * this file's own git history for the earlier "3-part panel, footer always visible" shape this
 * supersedes.
 *
 * Panel shape (2 parts now, HEADER always visible + collapsible BODY):
 *   - HEADER (`.filter-bar-header`, always visible), left to right:
 *     1. "ตัวกรอง (N)" label (count only shown when N > 0) -- `fa-filter` icon in front, single flat
 *        `--c-text-muted` color (§6's own documented exception, scoped to just this header).
 *     2. `.filter-bar-chips` -- one chip per active filter ("label: ค่า ×"), **visible ONLY while
 *        COLLAPSED** (`.filter-bar:not(.collapsed) .filter-bar-chips { display:none }`, pure CSS, no
 *        JS relocation needed anymore) -- while EXPANDED, the fields themselves are visible in the
 *        body below and carry their own "has a value" signal instead (`.filter-bar-field-active`,
 *        border `--c-border-strong` -- see `$filter_fields_html`'s own contract note below), so the
 *        chips would be redundant there and are hidden to save vertical space.
 *     3. optional `$header_extra_html` slot (see its own var doc below).
 *     4. the tertiary "ล้างตัวกรอง" button (`.btn.btn-link` per rules.md §4's own Tertiary row),
 *        hidden when N=0 -- visible in EITHER collapse state now (unlike chips), since clearing is
 *        just as meaningful with the fields visible (expanded) as with only chips visible (collapsed).
 *     5. the toggle -- one `.btn-icon` circle (the SAME row-action circle spec every other part of the
 *        app uses, §7) whose chevron rotates to reflect expanded/collapsed state (CSS-only, keyed off
 *        `.filter-bar:not(.collapsed) .filter-bar-toggle i`) -- this is the ONLY expand/collapse
 *        control.
 *     Items 2-5 together sit in `.filter-bar-header-right` (one flex wrapper, `margin-left:auto`) so
 *     the label packs left and everything else packs right as a group, regardless of how many of the
 *     optional pieces (chips/header-extra/clear) are actually present at a given moment -- simpler
 *     than the earlier revision's per-element sibling-selector auto-margin juggling, since there's
 *     only ONE thing that needs `margin-left:auto` now, not N of them.
 *   - BODY (`.filter-bar-body`, collapsible): the caller's own field grid, unchanged mechanism.
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
 *                                  label text is read from `$select.siblings('label')`, and that same
 *                                  parent div is what gets `.filter-bar-field-active` toggled onto it
 *                                  (2026-09-13 follow-up: a `--c-border-strong` border on the field
 *                                  itself while it holds a non-default value, "โดยไม่ต้องพึ่ง chips" --
 *                                  chips alone can't signal this anymore now that they're hidden while
 *                                  expanded, i.e. exactly when the fields are the only thing visible).
 *                                  Each `<select>` must also carry its own unique `id` (2026-09-13,
 *                                  real bug fix -- see initFilterBar()'s own docblock in app.js) -- a
 *                                  chip's own × button is looked up back to its field by that id, not
 *                                  by a closure reference, since the chip-remove/Clear buttons are
 *                                  delegated bindings. A field's own "no filter" sentinel value is
 *                                  inferred by TYPE (2026-09-13, real bug fix -- `.select2-remote`
 *                                  fields, which never carry a baked-in "all" `<option>` in their own
 *                                  markup, default to `''`; every other field defaults to `'all'`,
 *                                  matching this app's own long-standing static/native convention) --
 *                                  set an explicit `data-filter-default="..."` attribute on a
 *                                  `<select>` to override that inference for a field whose own "no
 *                                  filter" value genuinely differs (initFilterBar()'s own
 *                                  `defaultValueFor()` reads it first, before falling back by type).
 * @var string|null $pageKey       Optional. When set, this bar's own expanded/collapsed state
 *                                  persists in `localStorage['filterbar:' + pageKey]` across page
 *                                  reloads (initFilterBar() reads/writes it) -- omit entirely for a
 *                                  bar that should never remember its state (e.g. one that should
 *                                  always start collapsed).
 * @var string|null $header_extra_html  Optional, added 2026-09-13 (Round 3 item 3b follow-up,
 *                                  Payroll Detail's own auto-recalculate switch was the first real
 *                                  consumer, later reverted back to its own standalone spot on that
 *                                  ONE page -- the slot itself stays, genuinely reusable). Raw HTML
 *                                  rendered inside `.filter-bar-header-right` (see the panel-shape
 *                                  note above), between the chips and the "ล้างตัวกรอง" button -- for a
 *                                  SMALL control that genuinely belongs next to the filter bar but
 *                                  isn't itself a filter field (so it doesn't belong in
 *                                  `$filter_fields_html`'s own body grid, and doesn't need a chip).
 *                                  Omit entirely (or pass '') for a bar with no such control.
 *
 * JS pairing (see public/js/app.js's own initFilterBar() docblock for the full API):
 *   initFilterBar('#employeeFilter', {
 *       onChange: function () { reloadEmployeeTable(); }  // caller's own reload logic
 *   });
 *   -- the earlier `toolbarTarget` option (relocating the toolbar row into a Status Tabs row) is gone
 *   (removed an earlier round) -- the panel (header+body, both of it) always renders in normal
 *   document flow wherever this partial was included, e.g. directly under a status-tabs.php pipeline
 *   for the Payroll Process page's own full layout.
 * @note initFilterBar() is safe to call more than once on the same element (a $bar.data() guard,
 *       2026-09-13) -- it only ever fully initializes once, so an accidental double-call from a
 *       careless caller can't double-bind the toggle/chip-remove/Clear handlers. Still call it
 *       exactly once per real usage though; the guard is a safety net, not a license to skip a
 *       caller's own once-guard where one is cheap to keep (see payroll/detail.js's own
 *       `runDetailFilterBarInitialized` for that pattern).
 *
 * Example call site (round 4, not written yet -- this file has no consumer this round):
 *   ob_start(); ?>
 *     <!-- Column WIDTHS are the component's, not the caller's: 1 field per row below `sm`, 2 from
 *          `sm`, 3 from `md`, 6 from `lg`, whatever col-* class each field happens to carry
 *          (style.css, `.filter-bar-body > .row > [class*="col-"]`). -->
 *     <div class="row g-3">
 *       <div class="col-sm-2"><label class="form-label small mb-1" for="fDept">แผนก</label><select id="fDept" ...></div>
 *       <div class="col-sm-2"><label class="form-label small mb-1" for="fStatus">สถานะ</label><select id="fStatus" ...></div>
 *     </div>
 *   <?php $filter_fields_html = ob_get_clean();
 *   $id = 'employeeFilter';
 *   $pageKey = 'employee-list';
 *   include __DIR__ . '/../partials/filter-bar.php';
 */
$pageKeyAttr = !empty($pageKey) ? ' data-page-key="' . htmlspecialchars($pageKey) . '"' : '';
?>
<div class="filter-bar collapsed" id="<?=htmlspecialchars($id)?>"<?=$pageKeyAttr?>>
    <!-- 2026-09-16: the header is 2 ROWS at every width (rules.md §6) -- row 1 is the label plus the
         Clear button and the caret, row 2 is the chips strip and exists only while collapsed with at
         least one active filter. No button ever sits in row 2. -->
    <div class="filter-bar-header">
      <div class="filter-bar-header-top">
        <span class="filter-bar-label">
            <!-- 2026-09-13, explicit instruction: "ไอคอน fa-filter สีเดียว --c-text-muted หน้า 'ตัวกรอง'
                 (ข้อยกเว้น §6 เฉพาะหัว filter-bar)" -- §6's own general rule ("ตัดไอคอนหน้า label ของ filter
                 field ออก") still applies to every FIELD's own <label> inside the body below; this is
                 the panel's own header identifying itself as a filter panel, a different thing --
                 single flat color, no tone, not a per-field icon. -->
            <i class="fa-solid fa-filter filter-bar-label-icon" aria-hidden="true"></i>
            <span data-i18n="filter_title">ตัวกรอง</span><span class="filter-bar-count-wrap d-none"> (<span class="filter-bar-count">0</span>)</span>
        </span>
        <div class="filter-bar-header-right">
            <?php if (!empty($header_extra_html)): ?><div class="filter-bar-header-extra"><?=$header_extra_html?></div><?php endif; ?>
            <!-- Always the top-right corner of row 1, in both collapse states, hidden only while
                 nothing is filtered. Below `sm` it is the icon alone (the row has no width to spare
                 there) -- `title`/`aria-label` carry the same words the text would have said. -->
            <button type="button" class="btn btn-link filter-bar-clear d-none" data-i18n-title="filter_clear" title="Clear filters" aria-label="Clear filters"><i class="fa-solid fa-filter-circle-xmark filter-bar-clear-icon" aria-hidden="true"></i><span class="filter-bar-clear-text" data-i18n="filter_clear">ล้างตัวกรอง</span></button>
            <button type="button" class="btn-icon btn-icon-ghost filter-bar-toggle" aria-label="Toggle filter">
                <i class="fa-solid fa-chevron-down"></i>
            </button>
        </div>
      </div>
      <!-- Row 2: one horizontal strip, never wraps -- it scrolls sideways and fades at its right
           edge when there are more chips than fit. -->
      <div class="filter-bar-chips scroll-thin"></div>
    </div>
    <div class="filter-bar-body">
        <?=$filter_fields_html?>
    </div>
</div>
