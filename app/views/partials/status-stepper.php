<?php
/**
 * Status stepper -- docs/design/rules.md §6 ("Stepper: ไทม์ไลน์ 5 ขั้นของรอบ"), Round 2 item 6.
 *
 * A plain, generic N-step progress indicator: which step is done/current/next is derived purely
 * from each step's POSITION relative to $current -- nothing state-machine-aware here (no per-step
 * action buttons, dates, or branch icons for rejected/need_info/cancelled). That richer logic stays
 * exactly where it already lives -- app.js's own RUN_LIFECYCLE_STEPS/runLifecycleSteps()/
 * computeRunLifecycleProgress() -- this partial does NOT replace it. The real payroll run spine
 * (payroll/detail.js's renderProcessTimeline(), payroll/index.js's mini-timeline) keeps its own
 * bespoke rendering unchanged this round; Round 2 does not touch real page templates (see rules.md
 * §13) -- migrating either of them onto this generic partial, if ever, is a round-4 decision.
 *
 * JS twin: renderStatusStepper(steps, current) in app.js renders the byte-identical markup
 * client-side from the same 2 plain arguments, for a page that builds/updates this without a
 * server include (e.g. after an AJAX response changes which step is current).
 *
 * Visual rule (§6, no variation): done = gray circle + checkmark, label --c-text-muted; current =
 * solid --c-primary circle (no icon), label --c-text + bold; next = empty circle outlined
 * --c-border-strong, label --c-text-muted. No per-step pastel colors, no box per step -- steps are
 * joined by a single-color --c-border connecting line (never recolored by done/not-done state).
 *
 * Variables the calling view must set BEFORE including this file:
 * @var string[] $steps   Required. Ordered list of step labels, already resolved/translated by the
 *                         caller -- this partial has no i18n awareness of its own.
 * @var int      $current Required. 0-based index of the CURRENT step. Every index < $current renders
 *                         done; the index === $current renders current; every index > $current
 *                         renders next.
 *
 * Example:
 *   $steps = ['สร้างรายการ', 'ส่งอนุมัติ', 'อนุมัติ', 'จ่ายเงิน', 'ปิดรอบ'];
 *   $current = 3; // "จ่ายเงิน" is current; the first 3 are done; "ปิดรอบ" is next
 *   include __DIR__ . '/../partials/status-stepper.php';
 */
?>
<ul class="status-stepper">
    <?php foreach ($steps as $ssIndex => $ssLabel):
        if ($ssIndex < $current) {
            $ssStateClass = 'status-stepper-step--done';
        } elseif ($ssIndex === $current) {
            $ssStateClass = 'status-stepper-step--current';
        } else {
            $ssStateClass = 'status-stepper-step--next';
        }
    ?>
    <li class="status-stepper-step <?=$ssStateClass?>">
        <span class="status-stepper-circle"><?php if ($ssIndex < $current): ?><i class="fa-solid fa-check"></i><?php endif; ?></span>
        <span class="status-stepper-label"><?=htmlspecialchars($ssLabel)?></span>
    </li>
    <?php endforeach; ?>
</ul>
