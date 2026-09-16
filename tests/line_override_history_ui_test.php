<?php
/**
 * 2026-09-16, "ปรับตัวเลข" tab round 3: the History dropdown, the nested full-history modal, and the
 * footer's "คืนค่าระบบทั้งหมด" button.
 *
 * All three are pure client-side UI over the read-only history endpoint (locked separately in
 * tests/line_override_history_endpoint_test.php) -- there is no new server behaviour to assert, so
 * what this file locks is the small set of contracts that are easy to break silently later:
 *
 *  1. the dropdown renders ALL edits, not the first N. Slicing the list is the one regression that
 *     cannot be seen on screen -- a menu of 5 rows looks exactly the same whether the line has 5
 *     edits or 40;
 *  2. its head and foot are their own sticky elements, and the CSS that makes them stick (and the
 *     list scroll between them) is actually present -- lose the `position: sticky` and the head just
 *     scrolls away, which looks like a normal menu, not like a bug;
 *  3. "คืนค่าระบบทั้งหมด" is staged, never sent on click: it marks rows and the EXISTING save path
 *     turns each mark into a `.remove`. A mark that read the field back instead would save the
 *     calculated figure as a brand-new override -- the exact opposite of what the button says;
 *  4. the nested modal exists in the view with the ids the renderer fills, so the "ดูรายละเอียด
 *     ทั้งหมด" foot has somewhere to go;
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

$js = file_get_contents(__DIR__ . '/../public/js/payroll/detail.js');
$appJs = file_get_contents(__DIR__ . '/../public/js/app.js');
$css = file_get_contents(__DIR__ . '/../public/css/style.css');
$view = file_get_contents(__DIR__ . '/../app/views/payroll/detail.php');

echo "=== 1. the dropdown shows every edit ===\n";
$menuStart = strpos($js, 'function lineOverrideHistoryMenuHtml(');
$menuBody = substr($js, $menuStart, strpos($js, 'function lineOverrideHistoryCellHtml(') - $menuStart);
checkTrue('it iterates the whole list', strpos($menuBody, 'edits.forEach(') !== false);
checkTrue('it does not slice the list to the first N', strpos($menuBody, 'edits.slice(0') === false);
// The count in the foot has to be the real number of edits, or the list quietly disagrees with the
// link that is supposed to lead to the rest of it.
checkTrue('the foot reports the real edit count', strpos($menuBody, 'String(edits.length)') !== false);
checkTrue('the foot is always rendered (no count threshold)', strpos($menuBody, 'if (edits.length >') === false);

echo "\n=== 2. head / list / foot ===\n";
checkTrue('the head is its own element', strpos($menuBody, "liClass: 'lo-history-head'") !== false);
checkTrue('the foot is its own element', strpos($menuBody, 'class="lo-history-foot"') !== false);
// The head is the SAME one-line row as the rest (built by the same function) and is pickable --
// "back to the calculated figure" is a choice here, it just resolves to dropping the override.
checkTrue('the head is built by the shared row builder', strpos($menuBody, 'let html = lineOverrideHistoryItemHtml(') !== false);
checkTrue('the head carries its own marker class', strpos($menuBody, "itemClass: 'lo-history-computed'") !== false);
checkTrue('picking it means "drop the override", not "save this figure"',
    strpos($js, "\$(this).hasClass('lo-history-computed')") !== false);
checkTrue('the modal\'s calculated row means the same thing', strpos($js, "\$(this).attr('data-computed') === '1'") !== false);
foreach (['.lo-history-head', '.lo-history-foot'] as $sel) {
    checkTrue("{$sel} exists in CSS", strpos($css, $sel) !== false);
}
$stickyBlock = substr($css, (int)strpos($css, '.lo-history-head,'), 400);
checkTrue('both ends are position: sticky', strpos($stickyBlock, 'position: sticky') !== false);
$menuBlock = substr($css, (int)strpos($css, '.lo-history-cell .dropdown-menu {'), 400);
checkTrue('the menu has a fixed width', strpos($menuBlock, 'width: 320px') !== false);
checkTrue('the menu is the scroll container', strpos($menuBlock, 'overflow-y: auto') !== false);
checkTrue('the menu caps its height', strpos($menuBlock, 'max-height') !== false);
// Padding on the scroll container shows ABOVE a sticky head as a strip of background.
checkTrue('the menu drops its own padding', strpos($menuBlock, 'padding: 0') !== false);
$footBlock = substr($css, (int)strpos($css, '.lo-history-foot .lo-history-view-all {'), 200);
// `justify-content`, not `text-align`: the menu's items are flex boxes already, which makes
// text-align inert on them -- a real miss caught by measuring where the text actually landed.
checkTrue('the foot link sits right, at --fs-sm', strpos($footBlock, 'justify-content: flex-end') !== false
    && strpos($footBlock, 'font-size: var(--fs-sm)') !== false);

echo "\n=== 3. one-line rows, and which one is 'current' ===\n";
$itemStart = strpos($js, 'function lineOverrideHistoryItemHtml(');
$itemBody = substr($js, $itemStart, 600);
checkTrue('value and meta are the only 2 parts of a row', substr_count($itemBody, '<span class="lo-history-') === 2);
checkTrue('the row in effect is disabled', strpos($itemBody, "isCurrent ? 'disabled' : ''") !== false);
// Badge + the SAME meta, not badge INSTEAD of it -- when/who has to stay readable on that row too.
checkTrue('the current row keeps its when/who', strpos($menuBody, "currentBadge + ' ' + meta") !== false);
checkTrue('"current" is resolved once, newest-first', strpos($js, 'function lineOverrideHistoryCurrentIndexRd(') !== false);
checkTrue('the menu uses that resolution', strpos($menuBody, 'lineOverrideHistoryCurrentIndexRd(') !== false);
checkTrue('so does the modal', strpos($js, 'const currentIdx = lineOverrideHistoryCurrentIndexRd(editsNewestFirst, currentText);') !== false);

echo "\n=== 4. the nested modal ===\n";
foreach (['lineOverrideHistoryModal', 'lineOverrideHistoryModalLabel', 'lineOverrideHistoryModalBody', 'lineOverrideHistoryModalNote'] as $id) {
    checkTrue("#{$id} exists in the view", strpos($view, 'id="' . $id . '"') !== false);
}
checkTrue('it is built on the shared timeline', strpos($js, 'renderTimeline(lineOverrideHistoryTimelineItemsRd(history)') !== false);
checkTrue("through the timeline's own per-item action slot", strpos($js, 'actionHtml:') !== false);
checkTrue('renderTimeline() actually renders that slot', strpos($appJs, 'item.actionHtml') !== false);
checkTrue('the foot opens it', strpos($js, "'#lineOverrideTableWrap .lo-history-view-all'") !== false);
checkTrue('"use this value" is an outline-primary button, not a text link', strpos($js, 'class="btn btn-outline-primary lo-history-use"') !== false);
// Bootstrap hardcodes #0d6efd on .btn-outline-primary the same way it does on .btn-primary, so
// without a per-component override every one of these buttons renders Bootstrap blue, off-palette.
$outlineBlock = substr($css, (int)strpos($css, '.btn-outline-primary {'), 500);
checkTrue('.btn-outline-primary is bound to --c-primary', strpos($outlineBlock, '--bs-btn-color: var(--c-primary)') !== false
    && strpos($outlineBlock, '--bs-btn-hover-bg: var(--c-primary)') !== false);
$lint = file_get_contents(__DIR__ . '/../scripts/check-design.php');
checkTrue('and the lint allows exactly outline-secondary + outline-primary',
    strpos($lint, 'btn-outline-(?!secondary$|primary$)') !== false);
// It has to sit on the value's own line -- CSS, scoped to this modal, not to the shared component.
checkTrue('the modal scopes its own timeline layout', strpos($js, "'<div class=\"lo-history-timeline\">'") !== false);
$timelineBlock = substr($css, (int)strpos($css, '.lo-history-timeline .timeline-item {'), 900);
checkTrue('each entry lays out as a grid', strpos($timelineBlock, 'display: grid') !== false);
checkTrue('the action wrapper does not box its children in', strpos($timelineBlock, 'display: contents') !== false);
$dayBlock = substr($css, (int)strpos($css, '.timeline-day-header {'), 600);
checkTrue('the day header is a --fs-xs 600 band on --c-bg-subtle', strpos($dayBlock, 'font-weight: 600') !== false
    && strpos($dayBlock, 'background: var(--c-bg-subtle)') !== false);
checkTrue('section spacing above, less below', strpos($dayBlock, 'margin: var(--sp-4) 0 var(--sp-2)') !== false);
checkTrue('the first band has no space above it', strpos($dayBlock, '.timeline-day-header:first-child') !== false);
// The band is meant to BREAK the connecting line, not sit beside it.
checkTrue('the connecting line stops at a day boundary', strpos($css, '.timeline-item:has(+ .timeline-day-header)::before') !== false);
checkTrue('each header says how big its group is', strpos($appJs, 'class="timeline-day-count"') !== false);
// Counted as a run, not as a total per date -- the caller owns the order, so one date can open two
// separate groups and each header must describe the one it opens.
checkTrue('the count is per group, not per date', strpos($appJs, 'const dayRunLength = {};') !== false);
checkTrue('an undated entry still produces an empty (hidden) header', strpos($appJs, "dayLabel === ''") !== false);
// Both the dropdown and the modal fill the field through ONE function -- two copies is how the two
// paths drift into setting different attributes on the row.
checkTrue('both paths go through one confirm and one applier',
    substr_count($js, 'lineOverrideConfirmApplyHistoryValueRd(') === 3
    // declaration + the single call inside the confirm's onYes, and nowhere else
    && substr_count($js, 'lineOverrideApplyHistoryValueRd(itemCode, value, asComputed)') === 2);

echo "\n=== 4b. picking a value asks first ===\n";
// Both lists sit under the pointer while scrolling, and one click would otherwise replace a figure
// someone else set. The confirm names the value, the item, and that nothing is saved yet.
checkTrue('the dropdown goes through the confirm', strpos($js, "lineOverrideConfirmApplyHistoryValueRd(\$(this).closest('tr.lo-row')") !== false);
checkTrue('so does the modal', strpos($js, 'lineOverrideConfirmApplyHistoryValueRd(lineOverrideHistoryModalCode') !== false);
$confirmStart = (int)strpos($js, 'function lineOverrideConfirmApplyHistoryValueRd(');
$confirmBody = substr($js, $confirmStart, (int)strpos($js, '// One place that puts a chosen value into a row') - $confirmStart);
checkTrue('it is an info-tone confirm', strpos($confirmBody, "tone: 'info'") !== false);
checkTrue('its buttons are [use this value][cancel]', strpos($confirmBody, "confirmText: langData['line_override_history_use_value']") !== false
    && strpos($confirmBody, "cancelText: langData['cancel']") !== false);
checkTrue('the message names the value and the item', strpos($confirmBody, "replace('{value}', valueLabel).replace('{item}'") !== false);
// Nothing may move before the user says yes.
checkTrue('the field is only filled inside onYes', strpos($confirmBody, 'onYes: function () {') < strpos($confirmBody, 'lineOverrideApplyHistoryValueRd(itemCode, value, asComputed);'));
checkTrue('and the history modal closes only then', strpos($confirmBody, 'onApplied') !== false);
// Asking again at save time would be the same question twice about the same click.
checkTrue('save no longer re-asks about a picked value', strpos($js, 'line_override_confirm_revert_message') === false);
$thKeys = json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true);
checkTrue('and the old revert-confirm keys are gone', !array_key_exists('line_override_confirm_revert_title', $thKeys)
    && !array_key_exists('line_override_confirm_revert_message', $thKeys));

echo "\n=== 5. restore-all stages, it does not send ===\n";
$restoreStart = strpos($js, 'function restoreAllComputedLineOverridesRd(');
$restoreBody = substr($js, $restoreStart, strpos($js, "\$(document).on('click', '#btnRestoreAllComputedLineOverrides'") - $restoreStart);
checkTrue('it marks rows', strpos($restoreBody, "attr('data-force-remove', '1')") !== false);
checkTrue('it never calls the save/remove endpoints itself', strpos($restoreBody, 'line-override') === false);
checkTrue('it skips rows the run itself turned off', strpos($restoreBody, "\$check.is(':disabled')") !== false);
$planStart = strpos($js, 'function lineOverrideRowPlanRd(');
$planBody = substr($js, $planStart, strpos($js, 'function lineOverrideSaveUrlRd(') - $planStart);
checkTrue('a marked row becomes a .remove, not an override', strpos($planBody, "{ action: 'remove' }") !== false);
checkTrue('the mark is read BEFORE the field is', strpos($planBody, 'data-force-remove') < strpos($planBody, '.lo-new-amount'));
checkTrue('a marked row with no override at all is a no-op', strpos($planBody, ': null;') !== false);
checkTrue('the save step confirms, with the count', strpos($js, 'line_override_confirm_restore_all_message') !== false);
checkTrue('typing over a mark clears it', strpos($js, "removeAttr('data-from-history').removeAttr('data-force-remove')") !== false);
checkTrue('a marked row counts as dirty on its own', strpos($js, '.lo-row[data-force-remove="1"]') !== false);
checkTrue('the button is enabled by having overrides, not by being dirty', strpos($js, 'overrideRowCount === 0') !== false);
// The footer's left slot holds exactly one button, and it is this one -- a second "undo what I
// typed" button there would only repeat what the close/tab-switch dirty guard already asks.
checkTrue('it is the footer\'s left-slot button', strpos($js, "left: { id: 'btnRestoreAllComputedLineOverrides'") !== false);
checkTrue('no "cancel edits" button is left anywhere', strpos($js, 'btnCancelLineOverrideEdits') === false);
checkTrue('and its i18n key is gone with it', !array_key_exists('line_override_cancel_edits', json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true)));

echo "\n=== 6. one timestamp, one answer ===\n";
// The dropdown formats through formatDisplayDateTime() and the modal through the timeline's own
// helpers -- if those two parse a stored datetime differently, the SAME edit reads as two different
// times in two views of the same history.
checkTrue('the timeline formats through formatDisplayDateTime()', strpos($appJs, 'function timelineDisplayDateTime(value) {') !== false);
checkTrue('timelineTimeOfDay() no longer parses on its own', strpos($appJs, "function timelineTimeOfDay(value) {\n    const text = timelineDisplayDateTime(value);") !== false);
checkTrue('timelineDayLabel() no longer parses on its own', strpos($appJs, "function timelineDayLabel(value) {\n    const text = timelineDisplayDateTime(value);") !== false);
checkTrue('the dropdown uses the same source', strpos($js, 'const full = formatDisplayDateTime(edit.changed_at);') !== false);

echo "\n=== 7. i18n ===\n";
$th = json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true);
$en = json_decode(file_get_contents(__DIR__ . '/../public/lang/en.json'), true);
$keys = [
    'line_override_history_view_all' => '{n}',
    'line_override_history_badge' => '{n}',
    'line_override_confirm_use_value_title' => null,
    'line_override_confirm_use_value_message' => '{value}',
    'timeline_day_count' => '{n}',
    'line_override_history_modal_title' => null,
    'line_override_history_use_value' => null,
    'line_override_history_from_to' => '{from}',
    'line_override_restore_all_computed' => null,
    'line_override_confirm_restore_all_title' => null,
    'line_override_confirm_restore_all_message' => '{n}',
    'line_override_history_computed' => null,
    'line_override_history_current' => null,
    'line_override_hidden_why' => null,
    'line_override_hint' => null,
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
// The hidden-rows explanation moved OUT of the tab hint and onto the toggle it actually explains.
checkTrue('the tab hint no longer carries the hidden-rows explanation', strpos((string)$th['line_override_hint'], 'ซ่อน') === false);
checkTrue('the toggle carries it instead', strpos($js, "langData['line_override_hidden_why']") !== false);
// A trailing ellipsis on a button reads as "this opens something that is still loading".
checkTrue('the foot copy has no ellipsis', strpos((string)$th['line_override_history_view_all'], '…') === false
    && strpos((string)$en['line_override_history_view_all'], '…') === false);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
