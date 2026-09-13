<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Status stepper -- docs/design/rules.md §6 ("Stepper: ไทม์ไลน์ 5 ขั้นของรอบ"), Round 2 item 6, extended
 * 2026-09-13 (Round 3 item 3a, Payroll Detail pilot -- the FIRST real page to actually adopt this
 * component).
 *
 * A plain, generic N-step progress indicator: which step is done/current/next is derived purely
 * from each step's POSITION relative to $current -- nothing state-machine-aware here (no per-step
 * action buttons). That richer logic stays exactly where it already lives -- app.js's own
 * RUN_LIFECYCLE_STEPS/runLifecycleSteps()/computeRunLifecycleProgress() -- this partial does NOT
 * replace it; the caller (payroll/detail.js's renderProcessTimeline()) still computes progress/
 * labels/branch-state itself, then hands this partial's JS twin only the plain {label, date, tone}
 * + current index shape below.
 *
 * A branch state (rejected/need_info/cancelled) has no icon/shape of its own in this component (it's
 * still a plain circle, no branch-specific glyph) -- it renders as the "current" step, with that
 * branch's own label text substituted in (e.g. "ไม่อนุมัติ / ส่งกลับแก้ไข" instead of "อนุมัติ") AND,
 * 2026-09-13 same-day follow-up (explicit instruction: "status-stepper รับ tone ของขั้นปัจจุบันจาก
 * statusMapEntry(run_state)"), the circle's own COLOR can be overridden via `tone` -- see below.
 *
 * JS twin: renderStatusStepper(steps, current) in app.js renders the byte-identical markup
 * client-side from the same 2 arguments, for a page that builds/updates this without a server
 * include (e.g. after an AJAX response changes which step is current).
 *
 * Visual rule (§6, revised 2026-09-13 "แยก 3 สถานะชัด", then again for the current step's own icon):
 * done = soft-success circle (--c-success-soft fill, --c-success checkmark), label --c-text (full
 * strength, not muted); current = solid --c-primary circle **+ that step's own WHITE 12px icon**
 * (`icon`, see below) UNLESS overridden by `tone` (which also swaps the icon to that branch's own,
 * still white, still 12px -- see below), label --c-text + bold 600; next = empty circle outlined
 * --c-border-strong, label --c-text-faint (fainter than done, not the same muted tone). No box/card
 * per step. The connecting line is --c-border by default, but turns --c-success for the segment
 * immediately after a DONE step (every segment before the current step reads green, everything from
 * current onward stays gray). An optional date renders BELOW the label, `--fs-xs`/`--c-text-faint`,
 * plain text -- NO icon (the real payroll spine's own old `.tl-date` had a clock icon; explicitly
 * dropped here, "ไม่มีไอคอนนาฬิกา") -- and is never shown on a "next" step.
 *
 * Variables the calling view must set BEFORE including this file:
 * @var array $steps   Required. Ordered list of steps, already resolved/translated by the caller --
 *                      this partial has no i18n awareness of its own. Each entry is EITHER a plain
 *                      string (just a label, no date/tone/final/live/icon -- the original Round 2
 *                      shape, still fully supported) OR an array (2026-09-13 extension):
 *                      ['label' => string, 'date' => string|null, 'tone' => string|null,
 *                       'final' => bool, 'live' => bool, 'icon' => string|null].
 *                        - `date` is a caller-FORMATTED display string (e.g. already run through
 *                          toDisplayDate()/toLocalDateOnlyRd()) -- this partial does no date parsing/
 *                          formatting itself. A null/empty/omitted `date` renders nothing for that
 *                          step (most commonly a "next"/not-yet-reached step).
 *                        - `tone` (one of 'danger'/'warning'/'success'/'neutral', matching
 *                          status_map.php's own tone vocabulary -- §5) overrides the CURRENT step's
 *                          circle color away from the default --c-primary orange. Only has any effect
 *                          on the step at index === $current -- a `tone` on a done/next step is
 *                          silently ignored (done/next never take a tone override; they have their
 *                          own fixed gray/empty look regardless). Omit entirely for the normal
 *                          forward-flow case (draft/pending_approval/approved/paid/locked all stay
 *                          plain orange) -- the caller is expected to look this up itself (e.g. via
 *                          `statusMapEntry($run['state'], 'run_state')`/`getStatusMapEntry()`, §5)
 *                          only for a genuine branch/exception state, not pass it unconditionally.
 *                        - `final` (bool, default false, 2026-09-13 addition), only meaningful on a
 *                          DONE step (index < $current) -- marks the process as FULLY, terminally
 *                          complete (e.g. a payroll run's "locked" state), rendering that one step's
 *                          circle as solid --c-success with a WHITE checkmark instead of the ordinary
 *                          soft-done treatment every earlier done step gets. The caller decides when
 *                          this is true (e.g. only on the run's own last step, only when
 *                          `run['state'] === 'locked'`) -- this partial never infers "is everything
 *                          done" on its own from $current alone.
 *                        - `live` (bool, default false, 2026-09-13 item 3a "เก็บตก" item 2), only
 *                          meaningful on the CURRENT step (silently ignored on done/next, same rule as
 *                          `tone`) -- renders a soft pulsing ring (`.status-stepper-pulse-ring`
 *                          keyframe, style.css) expanding outward from the circle, `--c-primary`
 *                          opacity .35→0 scale 1→1.9 every 2.4s, looping -- the circle's OWN fill never
 *                          animates (only the ring does), and the rule respects
 *                          `prefers-reduced-motion: reduce` (ring is simply not rendered/animated).
 *                          The CALLER decides when this is true, same "this partial never infers
 *                          state-machine meaning" pattern as `final`/`tone` -- the real rule (§6):
 *                          only when the CURRENT VIEWER has something clickable waiting on THIS step
 *                          (a `$decision_actions` cluster or a `$primary_action` in page-header.php,
 *                          i.e. "ถึงตาผู้ใช้คนนี้") AND the step has no `tone` override (a branch state
 *                          -- rejected/need_info -- reads as settled/waiting, not "act now", so it
 *                          never pulses even if some other viewer role could still act on it).
 *                        - `icon` (string|null, a BARE Font Awesome glyph class with no weight prefix,
 *                          e.g. `'fa-calculator'` -- this partial prepends `fa-solid` itself at render
 *                          time, 2026-09-13 item C follow-up), only meaningful on the CURRENT step
 *                          (silently ignored on done/next -- a done step always shows its own fixed ✓
 *                          instead, a next step shows nothing, same rule as `tone`/`live`) -- renders
 *                          white, 12px, centered in the circle. **This partial does NOT hardcode any
 *                          step->icon mapping of its own** -- the caller looks it up (e.g. Payroll
 *                          Detail's `renderProcessTimeline()` reads `step.icon` straight off
 *                          `runLifecycleSteps()`'s own return value, which is itself sourced from
 *                          `RUN_LIFECYCLE_STEPS`/`RUN_LIFECYCLE_BRANCH_INFO`, app.js -- the ONE shared
 *                          place this mapping lives, also consumed by index.js's own mini-timeline).
 *                          A branch-state step (rejected/need_info) naturally carries its OWN icon
 *                          already via that same source (`RUN_LIFECYCLE_BRANCH_INFO[type].icon`), not
 *                          a special case here -- this partial only ever asks "does this step have an
 *                          `icon` string", never "is this step a branch".
 * @var int   $current Required. 0-based index of the CURRENT step. Every index < $current renders
 *                      done; the index === $current renders current; every index > $current renders
 *                      next.
 *
 * Example (real Payroll Detail usage, rejected branch -- current step's circle turns --c-danger, icon
 * swaps to the branch's own fa-xmark, white):
 *   $steps = [
 *       ['label' => 'สร้างรายการ', 'date' => '13/09/2026'],
 *       ['label' => 'ส่งอนุมัติ', 'date' => '13/09/2026'],
 *       ['label' => 'ไม่อนุมัติ / ส่งกลับแก้ไข', 'date' => null, 'tone' => 'danger', 'icon' => 'fa-xmark'],
 *       ['label' => 'จ่ายเงิน', 'date' => null],
 *       ['label' => 'ปิดรอบ', 'date' => null],
 *   ];
 *   $current = 2;
 *   include __DIR__ . '/../partials/status-stepper.php';
 *
 * Example (locked/fully-complete run -- last step renders solid --c-success + white checkmark):
 *   $steps = [
 *       ['label' => 'สร้างรายการ', 'date' => '01/09/2026'],
 *       ['label' => 'ส่งอนุมัติ', 'date' => '02/09/2026'],
 *       ['label' => 'อนุมัติ', 'date' => '03/09/2026'],
 *       ['label' => 'จ่ายเงิน', 'date' => '05/09/2026'],
 *       ['label' => 'ปิดรอบ', 'date' => '06/09/2026', 'final' => true],
 *   ];
 *   $current = 5; // past the last index -- every step is done, the last one also final
 *   include __DIR__ . '/../partials/status-stepper.php';
 *
 * Example (plain labels, no dates/tone/final -- original Round 2 shape, unchanged):
 *   $steps = ['สร้างรายการ', 'ส่งอนุมัติ', 'อนุมัติ', 'จ่ายเงิน', 'ปิดรอบ'];
 *   $current = 3;
 *   include __DIR__ . '/../partials/status-stepper.php';
 */
?>
<ul class="status-stepper">
    <?php foreach ($steps as $ssIndex => $ssStep):
        $ssLabel = is_array($ssStep) ? ($ssStep['label'] ?? '') : $ssStep;
        $ssDate = is_array($ssStep) ? ($ssStep['date'] ?? null) : null;
        $ssTone = is_array($ssStep) ? ($ssStep['tone'] ?? null) : null;
        $ssFinal = is_array($ssStep) ? !empty($ssStep['final']) : false;
        $ssLive = is_array($ssStep) ? !empty($ssStep['live']) : false;
        $ssIcon = is_array($ssStep) ? ($ssStep['icon'] ?? null) : null;
        if ($ssIndex < $current) {
            $ssStateClass = 'status-stepper-step--done';
        } elseif ($ssIndex === $current) {
            $ssStateClass = 'status-stepper-step--current';
        } else {
            $ssStateClass = 'status-stepper-step--next';
        }
        $ssToneClass = ($ssStateClass === 'status-stepper-step--current' && $ssTone)
            ? ' status-stepper-tone-' . htmlspecialchars($ssTone) : '';
        $ssFinalClass = ($ssStateClass === 'status-stepper-step--done' && $ssFinal)
            ? ' status-stepper-step--final' : '';
        $ssLiveClass = ($ssStateClass === 'status-stepper-step--current' && $ssLive)
            ? ' stepper-current-live' : '';
    ?>
    <li class="status-stepper-step <?=$ssStateClass?><?=$ssToneClass?><?=$ssFinalClass?><?=$ssLiveClass?>">
        <span class="status-stepper-circle"><?php if ($ssIndex < $current): ?><i class="fa-solid fa-check"></i><?php elseif ($ssIndex === $current && $ssIcon): ?><i class="fa-solid <?=htmlspecialchars($ssIcon)?> status-stepper-current-icon"></i><?php endif; ?></span>
        <span class="status-stepper-label"><?=htmlspecialchars($ssLabel)?></span>
        <?php if ($ssDate && $ssIndex <= $current): ?><span class="status-stepper-date"><?=htmlspecialchars($ssDate)?></span><?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
