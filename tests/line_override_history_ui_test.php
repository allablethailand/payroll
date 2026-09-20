<?php
/**
 * 2026-09-16, "ปรับตัวเลข" tab round 3, rewritten 2026-09-19 (H-ui): the History column, the surface
 * behind it, and the footer's "คืนค่าระบบทั้งหมด" button.
 *
 * What is behind the badge changed shape completely this round -- a 5-row dropdown plus a nested
 * modal became ONE table under the row (docs/decisions/2026-09-19-h-ui-history-table.md) -- so the
 * assertions were re-pointed at the new structure rather than deleted: what they lock is the same
 * set of contracts, which are the ones that are easy to break silently later:
 *
 *  1. the table renders ALL entries, not the first N. Slicing the list is the one regression that
 *     cannot be seen on screen -- a list of 5 rows looks exactly the same whether the line has 5
 *     edits or 40;
 *  2. the panel it opens in is PINNED to the visible width of the scroller it lives in: below `sm`
 *     this table is wider than its host, and content in a full-width cell otherwise slides out of
 *     view with the drag (the same measured failure that moved the 3 totals out of the table);
 *  3. "คืนค่าระบบทั้งหมด" is staged, never sent on click: it marks rows and the EXISTING save path
 *     turns each mark into a `.remove`. A mark that read the field back instead would save the
 *     calculated figure as a brand-new override -- the exact opposite of what the button says;
 *  4. the 2 surfaces this replaced are gone, not merely unused -- their markup, their CSS, their
 *     copy and the Popper machinery that existed only to keep a menu out of the table's clipper;
 *  5. every string either side renders is in BOTH language files, with its placeholders intact.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. No DB access at all.
 * Run with: php tests/line_override_history_ui_test.php
 */
declare(strict_types=1);

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

$thKeys = json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true);
$js = file_get_contents(__DIR__ . '/../public/js/payroll/detail.js');
$appJs = file_get_contents(__DIR__ . '/../public/js/app.js');
$css = file_get_contents(__DIR__ . '/../public/css/style.css');
$view = file_get_contents(__DIR__ . '/../app/views/payroll/detail.php');

echo "=== 1. the table shows every edit ===\n";
$tableStart = (int)strpos($js, 'function lineOverrideHistoryTableHtmlRd(');
$tableBody = substr($js, $tableStart, (int)strpos($js, 'function lineOverrideOccurrencesHtml(') - $tableStart);
checkTrue('it iterates the whole list', strpos($tableBody, 'rows.map(function (row) {') !== false);
checkTrue('it does not slice the list to the first N', strpos($tableBody, 'rows.slice(0') === false);
checkTrue('it has no count threshold of its own either', strpos($tableBody, 'if (rows.length >') === false);
// The badge is what says how many there are, and it counts the SAME list the table then renders --
// one source, so the number on the badge and the rows behind it cannot disagree.
$cellStart = (int)strpos($js, 'function lineOverrideHistoryCellHtml(');
$cellBody = substr($js, $cellStart, 800);
checkTrue('the badge counts the rows the table will show', strpos($cellBody, 'const rows = lineOverrideHistoryFor(line);') !== false
    && strpos($cellBody, "replace('{n}', String(rows.length))") !== false);
checkTrue('nothing recorded -> no badge at all, rather than a badge reading 0',
    strpos($cellBody, "if (!rows.length) return '';") !== false);
// 2026-09-19, H-ui: the badge draws on every row now. An excluded line and a hand-added one both
// really do carry recorded edits, and the cell used to be blanked for both of them.
$rowHtml = substr($js, (int)strpos($js, 'function lineOverrideRowHtml('), 9000);
checkTrue('every rendered row gets the same cell -- no row is blanked in the markup',
    strpos($rowHtml, '<td class="lo-history-cell">${lineOverrideHistoryCellHtml(line)}</td>') !== false);
// 2026-09-19, H-ui: which recorded rows belong to which line. A hand-added line is keyed by its own
// PK, because two of them on one employee can share one item_code.
$forBody = substr($js, (int)strpos($js, 'function lineOverrideHistoryFor('), 700);
checkTrue('a hand-added line is matched by source_type + source_id, never by code alone',
    strpos($forBody, "row.source_type === 'manual_line' && Number(row.source_id) === Number(line.manual_line_id)") !== false);
checkTrue('...and a calculated line never picks up a hand-added entry under the same code',
    strpos($forBody, "row.source_type !== 'manual_line'") !== false);

echo "\n=== 2. where the table opens, and that it survives a 430px screen ===\n";
$toggleStart = (int)strpos($js, "\$(document).on('click', '.lo-mount .lo-history-toggle', function () {");
checkTrue('the badge is the disclosure that opens it', $toggleStart > 0);
$toggleBody = substr($js, $toggleStart, 1400);
checkTrue('it opens as a row of THIS table, directly under the line it belongs to',
    strpos($toggleBody, '$row.after(`<tr class="lo-history-row"><td colspan="${$row.children(\'td\').length}">') !== false);
checkTrue('...and closes again, rather than stacking a second copy',
    strpos($toggleBody, '$open.remove();') !== false);
checkTrue('the toggle says which state it is in', strpos($toggleBody, "attr('aria-expanded', 'true')") !== false
    && strpos($toggleBody, "attr('aria-expanded', 'false')") !== false
    && strpos($cellBody, 'aria-expanded="false"') !== false);
// A row the run has switched off is read-only here whatever the slip's own mode -- the way back in
// is that row's own switch, and a [use this value] beside it would be a second control for one thing.
checkTrue('a switched-off row opens read-only, in either slip',
    strpos($toggleBody, "const mode = (lineOverrideHostRd.mode === 'view' || \$row.hasClass('lo-row-off')) ? 'view' : 'edit';") !== false);
// The measured one. Below `sm` the table is a horizontal scroller wider than its host, so whatever
// a full-width cell holds slides away with the drag unless it pins.
// The WHOLE rule, not a fixed-length window onto it: a comment added inside the block pushes a
// declaration out of a counted slice and fails an assertion about CSS nobody touched (hit for real,
// 2026-09-20).
$ruleBlock = static function (string $css, string $selector): string {
    $start = (int)strpos($css, $selector);
    $end = strpos($css, "\n}", $start);
    return $end === false ? substr($css, $start) : substr($css, $start, $end - $start + 2);
};
$panelBlock = $ruleBlock($css, '.lo-history-panel {');
checkTrue('the panel pins to the left edge of the scroller', strpos($panelBlock, 'position: sticky') !== false
    && strpos($panelBlock, 'left: 0') !== false);
checkTrue('...and takes the VISIBLE width, not the scrolled one, minus where it starts',
    strpos($panelBlock, 'width: calc(var(--lo-history-panel-w, 100%) - var(--lo-history-indent, 0px))') !== false);
// 2026-09-19: it belongs to ONE line, and starts on that line's own name -- which is a different
// column in each mode, so the offset is measured rather than declared.
checkTrue('...starting on the item name own x', strpos($panelBlock, 'margin-left: var(--lo-history-indent, 0px)') !== false
    && strpos($js, "table.style.setProperty('--lo-history-indent', Math.round(indent) + 'px');") !== false
    && strpos($js, "table.querySelector('thead th.lo-name-col')") !== false);
checkTrue('a sticky box needs a box: it is inline-block, never a bare inline', strpos($panelBlock, 'display: inline-block') !== false);
checkTrue('that width is MEASURED off the scroller, not declared',
    strpos($js, "table.style.setProperty('--lo-history-panel-w', scroller.clientWidth + 'px');") !== false);
checkTrue('...and re-measured whenever the scroller changes size', strpos($js, 'new ResizeObserver(publish).observe(scroller);') !== false);
// `display: flex` on a `<td>` drops it out of the table layout and takes its colspan with it (§7,
// a real bug from D1) -- the flex/sticky work belongs to the box INSIDE the cell.
$rowCssBlock = substr($css, (int)strpos($css, '.lo-history-row > td {'), 140);
checkTrue('the cell itself stays a table cell', strpos($rowCssBlock, 'display:') === false);
// 2026-09-19, seen on the real screen: the panel painted `--c-bg-subtle`, which is the very token
// the group headings above it use -- so an opened panel and its heading read as one block. The cell
// stays part of the table; what separates the panel is its OWN surface plus a border.
checkTrue('the cell keeps the table surface, it does not repaint it',
    strpos($rowCssBlock, 'background: transparent') !== false
    && strpos($rowCssBlock, '--c-bg-subtle') === false);
// 2026-09-20, tiny-G: the space outside the panel is this cell's padding, and it is the same step
// above it as below -- the box used to sit straight on the line's underline and stand clear only of
// what came after, which reads as belonging to the row below rather than to its own line.
checkTrue('the cell insets the panel by the same step above and below',
    strpos($rowCssBlock, 'padding: var(--sp-2) 0;') !== false);
checkTrue('...and the panel is a box of its own instead', strpos($panelBlock, 'background: var(--c-bg);') !== false
    && strpos($panelBlock, 'border: 1px solid var(--c-border)') !== false
    && strpos($panelBlock, 'border-radius: var(--radius)') !== false);
// The group heading is what it must NOT look like, so its own token is named here.
checkTrue('...which is not the token the group headings wear',
    strpos(substr($css, (int)strpos($css, '.lo-table > tbody > tr.lo-group > td {'), 420), 'background: var(--c-bg-subtle)') !== false);
// 2026-09-19: 3 layers, and only the last one scrolls -- the title bar is the baseline every entry
// below is a departure from, and a baseline that scrolls out of view is not one.
$scrollBlock = substr($css, (int)strpos($css, '.lo-history-scroll {'), 220);
checkTrue('the LIST caps its own height and scrolls, not the whole panel',
    strpos($scrollBlock, 'overflow-y: auto') !== false
    && strpos($scrollBlock, 'max-height: var(--lo-history-max-h, none)') !== false
    && strpos($panelBlock, 'max-height') === false);
checkTrue('...and neither of them can add a sideways drag', strpos($scrollBlock, 'overflow-x: hidden') !== false
    && strpos($panelBlock, 'overflow: hidden') !== false);
// An inline-block sits on the cell's text baseline, which leaves the font's descender space under
// it: ~4px that is not padding and that no padding can balance.
checkTrue('...and the box is top-aligned, so that padding is all there is around it',
    strpos($panelBlock, 'vertical-align: top;') !== false);
// Padding above a sticky head shows THROUGH it as a strip of background while the list scrolls.
checkTrue('...with no padding above the head it pins', strpos($panelBlock, 'padding: 0 var(--sp-2);') !== false);
$heightBody = substr($js, (int)strpos($js, 'function lineOverridePublishHistoryHeightRd('), 1200);
checkTrue('the cap is MEASURED off the rows that are really there, not assumed from a row height',
    strpos($heightBody, 'rows[4].getBoundingClientRect().bottom') !== false
    && strpos($heightBody, 'head.getBoundingClientRect().top') !== false);
checkTrue('...and a list of 5 or fewer is not capped at all', strpos($heightBody, 'rows.length <= 5') !== false
    && strpos($heightBody, "removeProperty('--lo-history-max-h')") !== false);
checkTrue('...re-measured every time a panel opens', strpos($js, 'lineOverridePublishHistoryHeightRd($row.next(') !== false);
// The title bar sits OUTSIDE that scroll box, so the scrollbar that appears when the list caps takes
// width off the table below it and not off the bar -- which is published so the bar can step in by
// the same amount and keep its [x] on the buttons' own x.
checkTrue('the scrollbar width is published for the title bar to match',
    strpos($heightBody, "panel.style.setProperty('--lo-history-sbw', Math.max(0, box.offsetWidth - box.clientWidth) + 'px');") !== false
    && strpos($css, 'calc(var(--sp-1) + var(--lo-history-sbw, 0px))') !== false);
// 4 unlabelled columns is what a head that scrolls away leaves behind.
$theadBlock = substr($css, (int)strpos($css, '.lo-history-table > thead > tr > th {'), 200);
checkTrue('the column titles stay put while the entries scroll under them',
    strpos($theadBlock, 'position: sticky') !== false && strpos($theadBlock, 'top: 0') !== false);
// Quiet by their COLOUR, not by a band behind them: a second muted surface would make the head read
// as a heading of its own rather than as the panel's column titles.
checkTrue('...on the panel own surface, opaque and right in both themes',
    strpos($theadBlock, 'background: var(--c-bg);') !== false
    && strpos($theadBlock, '--c-bg-subtle') === false
    && strpos($theadBlock, 'z-index: 1') !== false);
// Layer (a): the baseline on the left, the way out on the right.
$barBlock = substr($css, (int)strpos($css, '.lo-history-titlebar {'), 500);
checkTrue('the title bar puts the baseline left and the way out right',
    strpos($barBlock, 'justify-content: space-between') !== false);
checkTrue('...and nothing of the old head-row [Close] is left', strpos($css, '.lo-history-titlebar-label') === false
    && strpos($js, 'titleBar(') === false && strpos($js, 'titleLabel(') === false);
// The way out, reachable from a list that may be scrolled well past the badge that opened it.
$barBody = substr($js, (int)strpos($js, 'function lineOverrideHistoryTitlebarHtmlRd('), 1600);
checkTrue('the title bar carries the way out, in both slips', strpos($barBody, 'lo-history-close') !== false
    && strpos($barBody, "langData['close']") !== false
    && strpos($tableBody, 'lineOverrideHistoryTitlebarHtmlRd(line, rows, isView)') !== false);
// rules.md 7's own 32px neutral circle, not a worded button: it repeats on every open and says
// nothing the icon does not.
checkTrue('...as the app\'s own 32px neutral circle', strpos($barBody, 'class="btn btn-icon lo-history-close"') !== false
    && strpos($barBody, 'fa-xmark') !== false
    && strpos($barBody, 'aria-label="${closeLabel}"') !== false);
// The baseline is NOT an edit: it has no time of its own, and as the list's first row it scrolled
// away -- which is exactly what a baseline must not do.
checkTrue('the calculated value is in the title bar, not a row of the list',
    strpos($js, 'lo-history-computed-line') !== false
    && strpos($js, 'lo-history-computed-row') === false
    && strpos($tableBody, '<tbody>${body}</tbody>') !== false);
checkTrue('...and the read-only slip states it without offering a button',
    strpos($barBody, "\${isView ? '' : `<span class=\"lo-history-computed-action\">") !== false);
checkTrue('...and it needs no new copy of its own', !array_key_exists('line_override_history_close', $thKeys));
$closeBody = substr($js, (int)strpos($js, "\$(document).on('click', '.lo-mount .lo-history-close'"), 400);
checkTrue('pressing it collapses the panel and says so', strpos($closeBody, "\$row.next('tr.lo-history-row').remove();") !== false
    && strpos($closeBody, "attr('aria-expanded', 'false')") !== false);
checkTrue('...and puts the focus back where the reader was', strpos($closeBody, "trigger('focus')") !== false);
$mobileBlock = substr($css, (int)strpos($css, '@media (max-width: 575.98px) {', (int)strpos($css, '.lo-history-panel {')), 900);
checkTrue('below sm the 2 text columns give up their fixed widths and wrap',
    strpos($mobileBlock, 'width: auto') !== false && strpos($mobileBlock, 'white-space: normal') !== false);

echo "\n=== 3. one row: when | who | from -> to | use this value ===\n";
checkTrue('4 columns in the editable slip', substr_count($tableBody, '<th class') === 4);
// rules.md §9: the 2 modes differ by NOT RENDERING a column, never by disabling one.
checkTrue('...and the last one is absent in the read-only slip, not disabled',
    substr_count($tableBody, '${isView ? \'\'') >= 1
    && strpos($tableBody, "const useCell = function (cell) {\n        return isView ? '' :") !== false);
checkTrue('the note is a second line inside the change cell, not a 5th column',
    strpos($tableBody, '<div class="lo-history-note">${escapeHtml(row.note)}</div>') !== false);
checkTrue('the two sides of a change go through ONE formatter', strpos($tableBody, "lineOverrideHistorySideRd(row, 'to')") !== false
    && strpos($tableBody, "lineOverrideHistorySideRd(row, 'from')") !== false);
// An entry carries either a figure or a word, and the word entries are the tri-state ones.
$sideBody = substr($js, (int)strpos($js, 'function lineOverrideHistorySideRd('), 400);
checkTrue('a word entry prints the word, a figure entry the figure',
    strpos($sideBody, 'lineOverrideHistoryTextRd(') !== false && strpos($sideBody, 'lineOverrideHistoryValueRd(') !== false);
checkTrue('the word comes from the same copy the rest of the slip uses',
    strpos($js, "return langData['calc_override_' + value] || String(value);") !== false);
// Masked figures arrive as a string ('XXXX') and must pass through untouched -- fmtNum() on them
// would print NaN.
$valueBody = substr($js, (int)strpos($js, 'function lineOverrideHistoryValueRd('), 220);
checkTrue('a masked figure is still passed through as it came', strpos($valueBody, "typeof value === 'number' ? fmtNum(value) : String(value)") !== false);
// "Which entry is in effect" is asked once per KIND: a tri-state row can carry an amount trail and
// an answer trail at the same time, and each has its own current value.
checkTrue('"current" is resolved per kind', strpos($tableBody, 'const matched = {};') !== false
    && strpos($tableBody, "lineOverrideHistorySameValueRd(kind, kind === 'text' ? row.new_text : row.new_value, line, field)") !== false);
checkTrue('...and only the NEWEST match wins -- the same value can be set, changed and set again',
    strpos($tableBody, 'const isCurrent = same && !matched[kind];') !== false);
// 2026-09-19, reported for real (EM009 / LOAN_REPAY): an OLDER entry repeating the live figure was
// pressable, and what it sent was an x -> x the server accepted and recorded nothing for.
checkTrue('every other entry repeating that value is a button that cannot be pressed',
    strpos($tableBody, 'isNoop: same && !isCurrent') !== false
    && strpos($js, "(cell.isNoop ? ' disabled' : '')") !== false);
// Compared on the RAW value: money is a float, and a string compare calls 4000 and 4000.0000001 two
// different figures. A masked amount parses to NaN and is never claimed to be equal -- a reader who
// may not see the figure must not be told what it is by a disabled button.
$sameBody = substr($js, (int)strpos($js, 'function lineOverrideHistorySameValueRd('), 700);
checkTrue('...decided on the raw value, with a tolerance, never on the formatted string',
    strpos($sameBody, 'Math.abs(value - current) < 0.005') !== false
    && strpos($sameBody, 'if (isNaN(current) || isNaN(value)) return false;') !== false);
// "Use the calculated value" DROPS the override row, which is a real change to what is stored even
// where the figures match -- so that row is never one of the no-ops.
checkTrue('the calculated row is never treated as a no-op',
    strpos(substr($js, (int)strpos($js, 'function lineOverrideHistoryComputedRowsRd('), 1400), 'isNoop:') === false);
checkTrue('the pinned calculated row takes part in that, so it cannot double up with an entry',
    strpos($tableBody, 'computed.forEach(function (cell) { if (cell.isCurrent) matched[cell.kind] = true; });') !== false);
// The row in effect has nothing to do: a disabled button would still say "this is where you would
// do it" (rules.md §0.3), so it carries the word instead.
$useBody = substr($js, (int)strpos($js, 'function lineOverrideHistoryUseCellHtml('), 900);
checkTrue('the row in effect carries a word, not a disabled control',
    strpos($useBody, 'class="lo-history-current"') !== false && strpos($useBody, 'disabled') === false);
// An action that repeats once per row is not the surface's one main action (rules.md §4) -- a
// 38-entry chain would otherwise print the view's primary button 38 times.
checkTrue('the button is the small NEUTRAL one', strpos($useBody, 'class="btn btn-sm btn-outline-secondary lo-history-use"') !== false);
checkTrue('...and never a primary one', strpos($useBody, 'btn-outline-primary') === false && strpos($useBody, 'btn-primary') === false);
// Bootstrap hardcodes #0d6efd on .btn-outline-secondary's siblings the same way it does on
// .btn-primary, so the app's own binding to the palette has to still be there.
// Bootstrap hardcodes its own greys on .btn-outline-secondary the same way it hardcodes #0d6efd
// on .btn-primary, so without the app's own override every one of these buttons renders off-palette.
$outlineBlock = substr($css, (int)strpos($css, ".btn-outline-secondary,
.btn-outline-brand {"), 700);
checkTrue('.btn-outline-secondary is bound to the app tokens', strpos($outlineBlock, '--bs-btn-color: var(--c-text)') !== false
    && strpos($outlineBlock, '--bs-btn-border-color: var(--c-border-strong)') !== false);
$lint = file_get_contents(__DIR__ . '/../scripts/check-design.php');
checkTrue('and the lint allows exactly outline-secondary + outline-primary',
    strpos($lint, 'btn-outline-(?!secondary$|primary$)') !== false);

echo "\n=== 3b. the pinned \"calculated value\" rows ===\n";
$computedBody = substr($js, (int)strpos($js, 'function lineOverrideHistoryComputedRowsRd('), 1200);
// A hand-added line replaced nothing, so a "the system said x" row there would state a figure that
// never existed -- the same rule lineOverrideComputedTextRd()'s own note is fenced by.
checkTrue('a hand-added line gets no calculated row',
    strpos($computedBody, "if ((line.line_type || 'earning_deduction') !== 'manual_line'") !== false);
checkTrue('...and neither does a line with no amount trail at all',
    strpos($computedBody, "rows.some(r => lineOverrideHistoryRowKindRd(r) === 'amount')") !== false);
checkTrue('a tri-state line gets the ANSWER its inherit resolves to, as a word',
    strpos($computedBody, 'lineOverrideHistoryTextRd(statutoryExemptionInheritRd(field))') !== false
    && strpos($computedBody, "applyValue: 'inherit'") !== false);
checkTrue('...only where that line really has an answer trail',
    strpos($computedBody, "rows.some(r => lineOverrideHistoryRowKindRd(r) === 'text')") !== false);
checkTrue('absent, never blank: no recorded figure means no row', strpos($computedBody, "if (text !== '') out.push(") !== false);
checkTrue('both are marked as the calculated kind, which is what the write path keys off',
    substr_count($computedBody, 'isComputed: true') === 2);

echo "\n=== 4. the 2 surfaces this replaced are gone, not merely unused ===\n";
foreach (['lineOverrideHistoryMenuHtml', 'lineOverrideHistoryItemHtml', 'lineOverrideHistoryCurrentIndexRd',
          'lineOverrideHistoryWhenRd', 'lineOverrideHistoryWhoRd', 'lineOverrideHistoryMetaRd',
          'lineOverrideHistoryTimelineItemsRd', 'openLineOverrideHistoryModalRd', 'lineOverrideHistoryModalCode',
          'lo-history-view-all', 'lo-history-item', 'lo-history-head', 'lo-history-foot', 'lo-history-meta',
          'lineOverrideRowByCodeRd', 'lineOverrideRestoreRowRd'] as $dead) {
    checkTrue("{$dead} is gone from detail.js", strpos($js, $dead) === false);
}
foreach (['lineOverrideHistoryModal', 'lineOverrideHistoryModalLabel', 'lineOverrideHistoryModalBody', 'lineOverrideHistoryModalNote'] as $id) {
    checkTrue("#{$id} is gone from the view", strpos($view, 'id="' . $id . '"') === false);
}
foreach (['.lo-history-head', '.lo-history-foot', '.lo-history-item', '.lo-history-meta',
          '.lo-history-timeline', '.lo-history-footnote', '--lo-history-row-h', '--lo-menu-max-w'] as $sel) {
    checkTrue("{$sel} is gone from the CSS", strpos($css, $sel) === false);
}
// The Popper machinery existed for exactly one menu: one that opened from inside `.table-responsive`
// and would otherwise be clipped by it. With that menu gone it has no caller left, and a shared
// helper carrying a branch nobody takes is how the next reader learns a rule that is not true.
foreach (['fixedStrategy', 'data-lo-fixed-strategy', 'lo-menu-max-w'] as $dead) {
    checkTrue("{$dead} is gone from app.js", strpos($appJs, $dead) === false);
}
checkTrue('...and detail.js asks for none of it either', strpos($js, 'fixedStrategy') === false);
// This table has no badge dropdown left at all, so it no longer wires one.
checkTrue('the table stops wiring a dropdown it does not render',
    strpos(substr($js, (int)strpos($js, 'function renderLineOverrideTableRd('), 4000), 'initBadgeDropdown') === false);
// The shared Timeline component itself STAYS (rules.md §11) -- it is generic, and this was only its
// last caller. It is listed in BACKLOG.md as consumer=0 rather than deleted in a design round.
checkTrue('the shared timeline component is left intact', strpos($appJs, 'function renderTimeline(items, options) {') !== false
    && file_exists(__DIR__ . '/../app/views/partials/timeline.php'));

echo "\n=== 4b. picking a value asks first, then writes ===\n";
// One click would otherwise replace a figure someone else set, and the list sits right under the
// pointer while scrolling. The confirm names the value and the item -- and since 2026-09-16 this
// tab writes immediately, so it says so rather than promising a Save step that does not exist.
checkTrue('there is exactly one way in: the button in the table', substr_count($js, 'lineOverrideConfirmApplyHistoryValueRd(') === 2);
checkTrue('...and it is wired to that button', strpos($js, "\$(document).on('click', '.lo-mount .lo-history-use', function () {") !== false);
$confirmStart = (int)strpos($js, 'function lineOverrideConfirmApplyHistoryValueRd(');
$confirmBody = substr($js, $confirmStart, (int)strpos($js, 'function lineOverrideApplyHistoryValueRd(') - $confirmStart);
checkTrue('it is an info-tone confirm', strpos($confirmBody, "tone: 'info'") !== false);
checkTrue('its buttons are [use this value][cancel]', strpos($confirmBody, "confirmText: langData['line_override_history_use_value']") !== false
    && strpos($confirmBody, "cancelText: langData['cancel']") !== false);
checkTrue('the message names the value and the item', strpos($confirmBody, "replace('{value}', plan.label).replace('{item}'") !== false);
// Nothing may be sent before the user says yes.
checkTrue('the write happens only inside onYes', strpos($confirmBody, 'onYes: function () { lineOverrideApplyHistoryValueRd(plan); }') !== false);
checkTrue('a switched-off row writes nothing at all', strpos($confirmBody, "\$row.hasClass('lo-row-off')") !== false);
// One pick, 4 possible writes -- decided by what the ENTRY is, in one dispatcher, because there is
// only one place left to click it from.
$applyStart = (int)strpos($js, 'function lineOverrideApplyHistoryValueRd(');
$applyBody = substr($js, $applyStart, (int)strpos($js, 'function manualLineAmountOnlyUpdateRd(') - $applyStart);
checkTrue('an answer goes to the tri-state endpoint, that field alone',
    strpos($applyBody, "statutoryExemptionSendRd(plan.\$row, statutoryExemptionFieldRd(line), plan.value);") !== false);
checkTrue('a hand-added line is rewritten through its own endpoint', strpos($applyBody, 'manualLineAmountOnlyUpdateRd(line, amount);') !== false);
checkTrue('the calculated figure DROPS the override, it does not save itself as one',
    strpos($applyBody, "lineOverrideSendRd(plan.\$row, { action: 'remove' });") !== false);
checkTrue('any other figure is saved as an override', strpos($applyBody, "lineOverrideSendRd(plan.\$row, { action: 'override_amount', amount: amount });") !== false);
// The tri-state endpoint writes the PAIR, so the half that did not move has to be sent back at the
// value the server already holds -- which is what its own request builder does.
checkTrue('the untouched half of the tri-state pair is sent back unchanged',
    strpos($js, "tax_calculate_override: changes.tax || statutoryExemptionStateRd('tax'),") !== false
    && strpos($js, "sso_calculate_override: changes.sso || statutoryExemptionStateRd('sso'),") !== false);
// update-manual-line takes the WHOLE row: a field left out is a field cleared, not a field kept.
$manualBody = substr($js, (int)strpos($js, 'function manualLineAmountOnlyUpdateRd('), 1600);
checkTrue('the hand-added rewrite sends every other field back as it was',
    strpos($manualBody, 'const raw = manualLineRawByIdRd[String(line.manual_line_id)];') !== false
    && strpos($manualBody, 'payload.custom_item_name') !== false
    && strpos($manualBody, 'payload.payee_type = raw.payee_type;') !== false
    && strpos($manualBody, 'payload.destination = { destination_id: raw.destination_id };') !== false);
checkTrue('...and the raw rows it reads are kept by the one loader', strpos($js, 'manualLineRawByIdRd = {};') !== false
    && strpos($js, 'manual.forEach(function (line) { manualLineRawByIdRd[String(line.id)] = line; });') !== false);
// Asking again at save time is impossible now -- there is no save step to ask at.
checkTrue('no save-time revert confirm is left', strpos($js, 'line_override_confirm_revert_message') === false);
$thKeys = json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true);
checkTrue('and the old revert-confirm keys are gone', !array_key_exists('line_override_confirm_revert_title', $thKeys)
    && !array_key_exists('line_override_confirm_revert_message', $thKeys));

echo "\n=== 4c. the badge reads the endpoint that knows about all 3 kinds of edit ===\n";
// The old endpoint answers a narrower question (overrides only, grouped oldest-first) and the
// Payroll Run Audit report is built on that shape, so it is left exactly as it is rather than
// widened -- the slip moved instead.
$loaderBody = substr($js, (int)strpos($js, 'function loadSyncLineOverridesRd('), 2200);
checkTrue('the slip reads api/payroll-run.line-history', strpos($loaderBody, "lineOverrideSideRequestRd('api/payroll-run.line-history', employeeId)") !== false);
// No line named in the request itself: ONE call answers for every row of the slip, and the
// grouping is done here. A per-row call would be one request per badge on a 40-row slip.
checkTrue('...with no line named, so one request answers for every row',
    strpos($js, "lineOverrideSideRequestRd('api/payroll-run.line-history', employeeId, ") === false
    && strpos(substr($js, (int)strpos($js, 'function lineOverrideSideRequestRd('), 400), 'data: { run_id: PAYROLL_RUN_ID, employee_id: employeeId },') !== false);
checkTrue('...and the old endpoint is no longer read from here', strpos($js, 'api/payroll-run.line-override-history') === false);
checkTrue('the rows are grouped by the key the table itself is keyed on',
    strpos($loaderBody, 'const key = lineOverrideHistoryKeyRd(row.line_type, row.item_code);') !== false);
checkTrue('the 2 facts the old envelope carried ride along on the new one',
    strpos($loaderBody, 'historyAvailable: payload ? !!payload.history_available : true') !== false
    && strpos($loaderBody, 'startDate: payload ? payload.history_start_date : null') !== false);
$controller = file_get_contents(__DIR__ . '/../app/controllers/PayrollController.php');
$endpointBody = substr($controller, (int)strpos($controller, 'public function lineHistory()'));
$endpointBody = substr($endpointBody, 0, (int)strpos($endpointBody, 'public function index()'));
checkTrue('...and the endpoint really sends them', strpos($endpointBody, "'history_available' =>") !== false
    && strpos($endpointBody, "'history_start_date' => PayrollRunModel::LINE_OVERRIDE_HISTORY_FEATURE_START_DATE") !== false);
checkTrue('the rows key stayed exactly where it was', strpos($endpointBody, "'rows' => \$rows,") !== false);

echo "\n=== 5. restore-all is the one bulk action left ===\n";
// Every other control in this tab handles one line; this is the only thing that touches rows the
// user never opened, which is why it is the only thing that still counts before it asks.
$restoreStart = (int)strpos($js, 'function restoreAllComputedLineOverridesRd(');
$restoreBody = substr($js, $restoreStart, (int)strpos($js, 'function runRestoreAllComputedRd(') - $restoreStart);
checkTrue('it collects only rows that carry an override', strpos($restoreBody, "!(\$row.data('orig-action') || '')") !== false);
checkTrue('it skips rows the run itself turned off', strpos($restoreBody, "\$row.find('.lo-include').is(':disabled')") !== false);
// The count includes the tri-state answers, which are not override rows -- one extra request, one
// extra item (2026-09-18, 4b).
checkTrue('it asks first, with the count', strpos($restoreBody, "replace('{n}', String(total))") !== false
    && strpos($restoreBody, "const total = rows.length + (resetExemption ? 1 : 0);") !== false
    && strpos($restoreBody, "tone: 'warning'") !== false);
checkTrue('nothing is sent from the collector itself', strpos($restoreBody, 'lineOverrideEndpointRd') === false);
checkTrue('the sending happens only on yes', strpos($restoreBody, 'onYes: function () { runRestoreAllComputedRd(rows, resetExemption); }') !== false);
// One request per row, in order: each one recalculates the whole run internally, so two in flight
// would race each other.
$runStart = (int)strpos($js, 'function runRestoreAllComputedRd(');
$runBody = substr($js, $runStart, 2600);
checkTrue('it sends one .remove per row, sequentially', strpos($runBody, "lineOverrideEndpointRd(\$row.data('line-type'), 'remove')") !== false
    && strpos($runBody, 'runSequentialAjaxRd(calls,') !== false);
checkTrue('it reports progress while it runs', strpos($runBody, 'lineOverrideProgressRd(i + 1, total)') !== false);
checkTrue('it reloads the table and the run at the end', strpos($runBody, 'loadSyncLineOverridesRd();') !== false
    && strpos($runBody, 'loadRunDetail();') !== false);
// It is the footer's left-slot button, and the only one there -- 2026-09-17 (D3) in the Calculation
// Breakdown modal's own footer, beside the table it acts on, and only for a row that can be edited.
checkTrue('it is the footer\'s left-slot button', strpos($js, "? { id: 'btnRestoreAllComputedLineOverrides'") !== false);
checkTrue('no "cancel edits" button is left anywhere', strpos($js, 'btnCancelLineOverrideEdits') === false);
checkTrue('and its i18n key is gone with it', !array_key_exists('line_override_cancel_edits', $thKeys));
// 2026-09-19, H-ui: the LINE form's own left slot is gone, though -- it offered the one action the
// history table's top row now offers, from a surface 2 clicks away behind a pencil.
checkTrue('the line form has no second way back', strpos($js, 'btnLineFormUseComputed') === false
    && strpos($js, 'sections.useComputed') === false
    && strpos(substr($js, (int)strpos($js, 'function lineFormSectionsRd('), 1200), 'useComputed:') === false);
// ...but it still SAYS what the system calculated, under the field that replaces it.
checkTrue('the form still states the calculated figure', strpos($js, 'function renderLineFormComputedHintRd(') !== false
    && strpos($js, "langData['line_form_computed_hint']") !== false);

echo "\n=== 6. one timestamp, one answer ===\n";
// The table formats through formatDisplayDateTime(); so does the shared timeline component. If the
// two parsed a stored datetime differently, the SAME edit would read as two different times.
checkTrue('the history table formats through formatDisplayDateTime()',
    strpos($tableBody, 'escapeHtml(formatDisplayDateTime(row.changed_at))') !== false);
checkTrue('date AND time in one column -- an edit made today and one made last month must not read alike',
    substr_count($tableBody, 'lo-history-when">${escapeHtml(formatDisplayDateTime') === 1);
checkTrue('the timeline formats through formatDisplayDateTime()', strpos($appJs, 'function timelineDisplayDateTime(value) {') !== false);
checkTrue('timelineTimeOfDay() no longer parses on its own', strpos($appJs, "function timelineTimeOfDay(value) {\n    const text = timelineDisplayDateTime(value);") !== false);
checkTrue('timelineDayLabel() no longer parses on its own', strpos($appJs, "function timelineDayLabel(value) {\n    const text = timelineDisplayDateTime(value);") !== false);

echo "\n=== 7. i18n ===\n";
$th = json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true);
$en = json_decode(file_get_contents(__DIR__ . '/../public/lang/en.json'), true);
$keys = [
    'line_override_history_badge' => '{n}',
    'line_override_confirm_use_value_title' => null,
    'line_override_confirm_use_value_message' => '{value}',
    'line_override_history_use_value' => null,
    'line_override_history_from_to' => '{from}',
    'line_override_restore_all_computed' => null,
    'line_override_confirm_restore_all_title' => null,
    'line_override_confirm_restore_all_message' => '{n}',
    'line_override_history_computed' => null,
    'line_override_history_current' => null,
    // 2026-09-19, H-ui: the table's own 2 new column titles and the read-only slip's 2 marks. The
    // "when" column reuses the generic `time`, which already says exactly that in both files.
    'line_override_history_col_who' => null,
    'line_override_history_col_change' => null,
    'line_override_row_tag_edited' => null,
    'line_override_row_tag_added' => null,
    'time' => null,
    'calc_override_inherit' => null,
    'calc_override_yes' => null,
    'calc_override_no' => null,
];
foreach ($keys as $key => $placeholder) {
    checkTrue("{$key} exists in th", isset($th[$key]) && $th[$key] !== '');
    checkTrue("{$key} exists in en", isset($en[$key]) && $en[$key] !== '');
    if ($placeholder !== null) {
        checkTrue("{$key} keeps {$placeholder} in th", strpos((string)($th[$key] ?? ''), $placeholder) !== false);
        checkTrue("{$key} keeps {$placeholder} in en", strpos((string)($en[$key] ?? ''), $placeholder) !== false);
    }
}
checkTrue('line_override_confirm_use_value_message keeps {item} in both',
    strpos((string)$th['line_override_confirm_use_value_message'], '{item}') !== false
    && strpos((string)$en['line_override_confirm_use_value_message'], '{item}') !== false);
checkTrue('line_override_history_from_to keeps {to} in both', strpos((string)$th['line_override_history_from_to'], '{to}') !== false
    && strpos((string)$en['line_override_history_from_to'], '{to}') !== false);
// 2026-09-20, tiny-G: the column head says what this column is; a row that opened with the word
// "from" said it again, once per entry, 38 times over on a real line. What is left is the 2 values
// and the arrow between them -- which is the same string in both files, because it holds no word.
checkTrue('the change reads as value -> value, with nothing worded in front of it',
    strpos((string)$th['line_override_history_from_to'], '{from}') === 0
    && strpos((string)$en['line_override_history_from_to'], '{from}') === 0);
checkTrue('...and it is therefore the same string in both files',
    $th['line_override_history_from_to'] === $en['line_override_history_from_to']);
// The 2 marks say different things and must not drift into the same word: a line added by hand was
// never calculated at all, which is not the same as a calculated figure somebody replaced.
checkTrue('the 2 read-only marks are different words in both languages',
    $th['line_override_row_tag_edited'] !== $th['line_override_row_tag_added']
    && $en['line_override_row_tag_edited'] !== $en['line_override_row_tag_added']);
// The tab hint went with its tab, 2026-09-17 (D3). 2026-09-18 (4a-1): so did the hidden-rows
// collapse itself -- a skipped row is shown, with its reason on the row, so there is nothing left to
// explain on a toggle and no copy left to keep.
checkTrue('the tab hint is gone', !array_key_exists('line_override_hint', $th) && !array_key_exists('line_override_hint', $en));
foreach (['line_override_hidden_why', 'line_override_show_hidden_rows', 'line_override_hide_rows'] as $retired) {
    checkTrue("the retired collapse copy {$retired} is gone from both files",
        !array_key_exists($retired, $th) && !array_key_exists($retired, $en));
}
// 2026-09-19, H-ui: and so did the dropdown's foot, the modal's title and its "history starts at"
// footnote -- there is no dropdown foot and no modal for them to sit in.
foreach (['line_override_history_view_all', 'line_override_history_modal_title', 'line_override_history_since',
          'line_override_use_computed'] as $retired) {
    checkTrue("the retired copy {$retired} is gone from both files",
        !array_key_exists($retired, $th) && !array_key_exists($retired, $en));
}
check('both files still carry exactly the same key set', array_diff_key($th, $en) + array_diff_key($en, $th), []);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
