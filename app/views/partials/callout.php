<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Callout -- docs/design/rules.md §15 ("Callout"), new 2026-09-13 (Round 3 item 3a follow-up).
 * Replaces the old bespoke `.next-step-banner`/`.process-next-step` box (Payroll Detail's own
 * "what happens next" line, colored background + left border per tone) -- decided as a real, named
 * component instead of a one-off page-specific class, since "one line of guidance text below a
 * stepper/section, colored by meaning" is a genuinely reusable shape, not specific to payroll runs.
 *
 * Visual rule (§15, no variation): plain `--c-bg-subtle` background, `--radius` corners, 3px LEFT
 * border colored by `$tone` (§3's own documented exception: a callout's own left border is one of
 * the few places outside a badge/status marker that a tone color is allowed on a container, since
 * the WHOLE POINT of a callout is to carry that signal -- a card/stat's own border/background still
 * may NOT do this, §3's general rule is otherwise unchanged). Text is plain `--c-text` at `--fs-base`
 * -- NO icon in front of it (an earlier per-state version of this box had one; explicitly dropped,
 * "ตัดไอคอน ⓘ/✓ หน้าข้อความออก" -- the tone-colored border already carries that signal, a redundant
 * icon glyph on top of it doesn't add real information per §0.3 "ถ้าเอาออกแล้วความหมายไม่เปลี่ยน ให้เอา
 * ออก").
 *
 * Variables the calling view must set BEFORE including this file:
 * @var string $text  Required. The callout's own message, as a RAW HTML STRING the caller has
 *                     already authored/escaped itself -- this partial echoes it verbatim, does NOT
 *                     run htmlspecialchars() on it. This is deliberate, not an oversight: the whole
 *                     point of this component (§15's own explicit spec) is that a caller can bold a
 *                     SPECIFIC action word inside the sentence to match a real button's own label
 *                     (e.g. wrapping "คำนวณ"/"ส่งอนุมัติ" in `<b>` so they visually match
 *                     #btnRecalculate/#btnSubmitRun's own labels, weight 600) -- a plain escaped
 *                     string has no way to do that. $text must therefore ALWAYS be caller-authored
 *                     copy (an i18n string this app itself wrote), NEVER raw end-user input passed
 *                     through unescaped.
 * @var string $tone  Optional, default 'neutral'. One of 'primary'|'success'|'warning'|'danger'|
 *                     'neutral' (§3's own status vocabulary) -- colors ONLY the left border, nothing
 *                     else in the box.
 *
 * Example (real Payroll Detail usage, draft state -- 2 action words bolded to match real buttons):
 *   $text = 'รอบนี้ยังเป็นแบบร่างอยู่ กด <b>คำนวณ</b> เพื่อคำนวณยอด แล้วจึง <b>ส่งอนุมัติ</b> เมื่อพร้อม';
 *   $tone = 'primary';
 *   include __DIR__ . '/../partials/callout.php';
 */
$coTone = $tone ?? 'neutral';
?>
<div class="callout callout-<?=htmlspecialchars($coTone)?>"><?=$text?></div>
