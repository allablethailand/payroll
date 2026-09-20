<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Empty state -- docs/design/rules.md §6, item 6e. One shared "there's nothing to show" block for
 * the 4 real situations this app has: a genuinely empty table, an empty tab/section (e.g. no rate
 * version set up yet), an empty list (notification/timeline), and a search/filter that matched
 * nothing.
 *
 * 2 meanings that must NOT share the same copy (explicit instruction) -- this partial has no opinion
 * about which applies, the CALLER decides by what it passes in:
 *   - "ยังไม่มีข้อมูล" (nothing has been created yet) -- $text should suggest CREATING something,
 *     $action (if any) is that page/tab's own create action.
 *   - "ไม่พบตามที่กรอง" (a search/filter matched zero of REAL existing rows) -- $text should suggest
 *     changing/clearing the filter, $action (if any) is a tertiary "ล้างตัวกรอง".
 * initSharedDataTable()'s own `emptyState` option (app.js) is the ONE place that already auto-picks
 * between the two for a DataTable specifically (via DataTables' own page.info() recordsTotal vs
 * recordsDisplay) -- every OTHER caller of this partial/its JS twin decides for itself which meaning
 * applies, there is no shared auto-detection outside that one table helper.
 *
 * @var string      $icon   Optional. Full Font Awesome class, e.g. 'fa-solid fa-inbox'. Defaults to
 *                          'fa-solid fa-inbox' when omitted.
 * @var string      $title  Required. One line.
 * @var string      $text   Required. One line, says what can be done next.
 * @var string|null $title_i18n Optional. `data-i18n="<key>"` on the title line.
 * @var string|null $text_i18n  Optional. Same, on the text line. A SERVER-rendered empty state has
 *                          no other way to follow a live language switch: this app translates the
 *                          DOM through app.js's `updateText()` sweep over `[data-i18n]`, so a PHP
 *                          include that only echoes a literal stays frozen in whatever language it
 *                          was written in. Same optional-attribute shape `page-header.php`'s own
 *                          breadcrumb `i18n` field already uses -- `$title`/`$text` still carry the
 *                          (English) literal that shows before the sweep runs.
 * @var string|null $text_id Optional. `id` put on the text line itself, for the rare caller that
 *                          has to REPLACE that one line live (e.g. a tab that swaps the standard
 *                          "not ready yet" sentence for a server error message it just received)
 *                          without re-rendering the whole block. Nothing else about the component
 *                          changes; a caller that omits it gets exactly the markup it always got.
 * @var array|null  $action Optional. ['label'=>string, 'id'=>string (for the caller's own JS to bind
 *                          a click handler to -- this partial never wires behavior itself),
 *                          'variant'=>'primary'|'secondary'|'tertiary' (default 'secondary' -- §4:
 *                          primary is reserved for a page/tab that has no OTHER primary action
 *                          already in its own header)].
 *
 * JS twin: emptyStateHtml({icon, title, text, action}) in app.js -- same shape, same markup, for a
 * caller that builds this client-side (initSharedDataTable()'s own `emptyState` option,
 * renderNotifications()'s empty case, ...) instead of server-rendering it. `action.onClick` (a real
 * function, JS-only -- meaningless server-side) is ALSO accepted there for a caller that re-renders
 * this block repeatedly (a DataTable redraw wipes and recreates the DOM node every time, so binding
 * externally via `$('#id').on('click', ...)` once would silently stop working after the first
 * redraw) -- see that function's own docblock.
 *
 * Example call site (round 4, not written yet -- this file has no consumer this round):
 *   $icon = 'fa-solid fa-users-slash';
 *   $title = 'ยังไม่มีพนักงาน';
 *   $text = 'เพิ่มพนักงานคนแรกเพื่อเริ่มต้นใช้งาน';
 *   $action = ['label' => 'เพิ่มพนักงาน', 'id' => 'btnAddEmployeeEmptyState', 'variant' => 'primary'];
 *   include __DIR__ . '/../partials/empty-state.php';
 */
$esIcon = htmlspecialchars($icon ?? 'fa-solid fa-inbox', ENT_QUOTES, 'UTF-8');
$esTitleI18n = !empty($title_i18n) ? ' data-i18n="' . htmlspecialchars($title_i18n, ENT_QUOTES, 'UTF-8') . '"' : '';
$esTextI18n = !empty($text_i18n) ? ' data-i18n="' . htmlspecialchars($text_i18n, ENT_QUOTES, 'UTF-8') . '"' : '';
$esTextId = !empty($text_id) ? ' id="' . htmlspecialchars($text_id, ENT_QUOTES, 'UTF-8') . '"' : '';
$esVariant = in_array($action['variant'] ?? 'secondary', ['primary', 'secondary', 'tertiary'], true) ? ($action['variant'] ?? 'secondary') : 'secondary';
$esBtnClass = $esVariant === 'tertiary' ? 'btn btn-link' : ($esVariant === 'primary' ? 'btn btn-primary' : 'btn btn-outline-secondary');
?>
<div class="empty-state">
    <i class="empty-state-icon <?=$esIcon?>" aria-hidden="true"></i>
    <div class="empty-state-title"<?=$esTitleI18n?>><?=htmlspecialchars($title)?></div>
    <div class="empty-state-text"<?=$esTextId?><?=$esTextI18n?>><?=htmlspecialchars($text)?></div>
    <?php if (!empty($action)): ?>
    <button type="button" class="<?=$esBtnClass?> empty-state-action" id="<?=htmlspecialchars($action['id'] ?? '', ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($action['label'])?></button>
    <?php endif; ?>
</div>
