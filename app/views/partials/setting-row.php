<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Setting row -- docs/design/rules.md §9/§11 ("Setting row"), new 2026-09-14 (Round 3 "เก็บตกรอบ 6"),
 * 2 variants added same day ("เก็บตกรอบ 7"). An inline settings row: a label + a description that
 * changes depending on whether its switch is on/off -- the exact shape Payroll Detail's own
 * "auto-recalculate" toggle already needed, factored out as a real, named, reusable component instead
 * of staying a page-local block (§0.4 "ซ้ำ = shared").
 *
 * 2 variants, §9's own rule for picking one: **1-2 settings on a page/section -> `plain` (default)**,
 * **3+ stacked together -> `card`** (a lone `plain` row reads fine on bare page background; several
 * stacked plain rows in a row start reading as loose, un-grouped text -- a shared card surface signals
 * "these belong together" the way it already does for `.filter-bar`/other panel-shaped components).
 *
 * - **`plain`** (default, `$variant` omitted or `'plain'`) -- NO box/background/padding at all, one
 *   flat line: `[switch] label · description`, ~24px tall (the switch's own natural height, no
 *   padding added on top of it), flush LEFT (no inset -- lines up with `.filter-bar`'s own left edge
 *   directly above/below it). Switch comes FIRST (left), then the bold label (`--fs-sm`/600/
 *   `--c-text`, a real `<label for="...">` so clicking it toggles the switch same as clicking the
 *   switch itself), then a muted "&middot;" separator, then the description (`--fs-xs`/
 *   `--c-text-muted`) -- all inline, single line, ellipsis if it overflows.
 * - **`card`** (`$variant = 'card'`) -- the original 2026-09-14 shape: flat `--c-bg-subtle`
 *   background, `--radius` corners, no border, `--sp-3 --sp-4` padding. Text block LEFT (label on its
 *   OWN line, description on the line under it), switch RIGHT, `align-items:center` on the row so it
 *   stays centered against the 2-line text block regardless of its exact rendered height.
 *
 * Both variants own their own bottom margin (`--sp-3`, style.css) the same way `.filter-bar` already
 * owns its own `--sp-4` -- a caller stacking this directly above a filter-bar (the first real usage)
 * needs no extra spacing of its own.
 *
 * The description SWAPS between `$desc_on`/`$desc_off` automatically the instant the switch is
 * toggled, in EITHER variant -- app.js's own delegated `change` handler on
 * `.setting-row .form-check-input` (always-on, no init call needed, same "auto-wired, zero per-page
 * setup" convention `.btn-icon`/every other pure CSS+minimal-JS component in this app already follows)
 * reads `data-desc-on`/`data-desc-off` straight off the description element itself (`.setting-row-desc`,
 * present in both variants' own markup) and swaps `.html()` to match -- no page-specific JS needed for
 * this at all, and no variant-specific JS either. A caller that changes the checkbox's own
 * `.prop('checked', ...)` PROGRAMMATICALLY (syncing from freshly-loaded server data, or reverting
 * after a failed save -- not a real user click) must resync the description itself too, since
 * `.prop()` never fires `change`:
 *   - `.trigger('change')` works ONLY if nothing else is also listening for `change` on that same
 *     element with its own side effects (e.g. an id-scoped save-on-toggle handler) -- re-firing THAT
 *     handler by accident is a real trap, not hypothetical (Payroll Detail's own
 *     `#chkAutoRecalculate` already has exactly this shape).
 *   - `syncSettingRowDesc($switchInput)` (app.js, exported alongside `settingRowHtml()`) instead
 *     updates ONLY the description, with no risk of re-triggering an unrelated handler -- prefer this
 *     whenever the switch might have its own business-logic `change` listener too.
 *
 * Variables the calling view must set BEFORE including this file:
 * @var string      $id        Required. The switch `<input>`'s own id (also the label's `for` target).
 * @var string      $label     Required. The row's own bold title -- RAW HTML/text the caller has
 *                              already authored, same "caller-authored copy, not escaped twice"
 *                              convention as callout.php's own `$text` (see that partial's docblock
 *                              for the full reasoning) -- this partial does not run htmlspecialchars()
 *                              on it.
 * @var string      $desc_on   Required. Description shown while the switch IS checked -- RAW HTML/
 *                              text, same convention (a caller may bold one specific word via `<b>`
 *                              to match a real button's own label, §15's own already-established
 *                              pattern for this).
 * @var string      $desc_off  Required. Description shown while the switch is NOT checked.
 * @var bool        $checked   Optional, default false. The switch's own initial checked state --
 *                              also decides which of `$desc_on`/`$desc_off` renders first.
 * @var string|null $variant   Optional, default `'plain'`. `'plain'` or `'card'` -- see above.
 *
 * Example (Payroll Detail's own real usage is JS-rendered instead, via settingRowHtml() -- the run's
 * own auto_recalculate value isn't known at PHP-render time, only after an async fetch -- this is the
 * PHP entry point for a page that DOES already know its own value server-side):
 *   $id = 'chkAutoRecalculate';
 *   $label = 'Automatically recalculate right after editing data';
 *   $desc_on = 'The system will calculate right away whenever data is edited.';
 *   $desc_off = 'If you edit data, click <b>Recalculate</b> yourself every time.';
 *   $checked = false;
 *   // $variant omitted -- plain, this page only has this one setting.
 *   include __DIR__ . '/../partials/setting-row.php';
 */
$srChecked = !empty($checked);
$srDescNow = $srChecked ? $desc_on : $desc_off;
$srVariant = ($variant ?? 'plain') === 'card' ? 'card' : 'plain';
$srSwitchHtml = '<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="' . htmlspecialchars($id) . '"' . ($srChecked ? ' checked' : '') . '></div>';
$srDescHtml = '<span class="setting-row-desc" data-desc-on="' . htmlspecialchars($desc_on) . '" data-desc-off="' . htmlspecialchars($desc_off) . '">' . $srDescNow . '</span>';
?>
<?php if ($srVariant === 'card'): ?>
<div class="setting-row setting-row-card">
    <div class="setting-row-text">
        <div class="setting-row-label"><?=$label?></div>
        <?=$srDescHtml?>
    </div>
    <?=$srSwitchHtml?>
</div>
<?php else: ?>
<div class="setting-row setting-row-plain">
    <?=$srSwitchHtml?>
    <label class="setting-row-plain-label" for="<?=htmlspecialchars($id)?>"><?=$label?></label>
    <span class="setting-row-sep" aria-hidden="true">&middot;</span>
    <?=$srDescHtml?>
</div>
<?php endif; ?>
