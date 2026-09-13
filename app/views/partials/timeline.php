<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Timeline -- docs/design/rules.md §6, Round 2 item (3)/6b.
 *
 * A plain vertical activity feed (audit log, approval history) -- the caller has already sorted
 * $items newest-first; this partial never sorts/dedupes beyond the literal $groupByDay option below.
 * NOT the payroll run's own 5-station spine (that's status-stepper.php, a fixed small N of named
 * milestones) -- this is for an arbitrary-length list of past events. A combined "stepper on top,
 * timeline below" is exactly how the real Approval Timeline modal (payroll/detail.js's
 * renderApprovalTimelineBody()) could look once migrated in round 4 -- this partial does not touch
 * that real modal's code at all, only demoed side-by-side in docs/design/components.php.
 *
 * JS twin: renderTimeline(items, options) in app.js renders the byte-equivalent markup client-side
 * from the same 2 plain arguments.
 *
 * @var array    $items       Required. Ordered list (newest-first) of:
 *     ['time' => string (a date/datetime string PHP's strtotime() can parse),
 *      'actor' => ['name' => string, 'avatar' => string|null]|null,
 *      'title' => string,
 *      'detail' => string|null (1 line),
 *      'badge' => ['enum' => string, 'context' => string]|null,
 *      'tone' => 'neutral'|'warning'|'danger'|'success'|null (default 'neutral' -- dot color only)]
 *   'time' renders as HH:MM only (§6's own spec is literally "เวลา" -- the date is what the optional
 *   day-header groups by instead, not repeated next to the time itself).
 * @var bool     $groupByDay  Optional, default false. When true, inserts a "dd/mm/yyyy" divider
 *                            <li> before the first item of each new calendar day.
 *
 * Avatar: `apvAvatarHtml()` is a JS-only function (app.js) -- this partial cannot call it
 * server-side, so it reuses the SAME `.apv-person-avatar` CSS class that function's own markup
 * already depends on (defined once in style.css, shared regardless of which language renders the
 * span), reproducing just its basic visual (initial-letter circle, or a real `<img>` if `avatar` is
 * set) -- not a port of apvAvatarHtml()'s full feature set (no employee-quick-view click behavior,
 * not needed for a timeline actor).
 *
 * Example:
 *   $items = [
 *       ['time' => '2026-09-10 14:32:00', 'actor' => ['name' => 'สมชาย ใจดี', 'avatar' => null],
 *        'title' => 'อนุมัติรายการ', 'tone' => 'success',
 *        'badge' => ['enum' => 'approved', 'context' => 'approval_status']],
 *       ...
 *   ];
 *   $groupByDay = true;
 *   include __DIR__ . '/../partials/timeline.php';
 */
$tlGroupByDay = $groupByDay ?? false;
$tlLastDayLabel = null;
?>
<ul class="timeline">
<?php foreach ($items as $tlItem):
    $tlTs = strtotime((string)($tlItem['time'] ?? ''));
    if ($tlGroupByDay) {
        $tlDayLabel = $tlTs ? date('d/m/Y', $tlTs) : (string)($tlItem['time'] ?? '');
        if ($tlDayLabel !== $tlLastDayLabel) {
            echo '<li class="timeline-day-header">' . htmlspecialchars($tlDayLabel) . '</li>';
            $tlLastDayLabel = $tlDayLabel;
        }
    }
    $tlTone = htmlspecialchars($tlItem['tone'] ?? 'neutral');
    $tlTimeLabel = $tlTs ? date('H:i', $tlTs) : htmlspecialchars((string)($tlItem['time'] ?? ''));
    $tlActor = $tlItem['actor'] ?? null;
?>
    <li class="timeline-item">
        <span class="timeline-dot timeline-dot-<?=$tlTone?>"></span>
        <div class="timeline-head">
            <span class="timeline-actor">
                <?php if ($tlActor):
                    $tlName = (string)($tlActor['name'] ?? '');
                    $tlInitial = htmlspecialchars(mb_strtoupper(mb_substr(trim($tlName) ?: '?', 0, 1)));
                    $tlAvatarPath = $tlActor['avatar'] ?? null;
                ?>
                    <?php if ($tlAvatarPath): ?>
                        <img src="<?=htmlspecialchars($tlAvatarPath)?>" alt="" style="width:24px;height:24px;min-width:24px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <span class="apv-person-avatar" style="width:24px;height:24px;min-width:24px;font-size:.75rem;"><?=$tlInitial?></span>
                    <?php endif; ?>
                    <span><?=htmlspecialchars($tlName)?></span>
                <?php endif; ?>
            </span>
            <span class="timeline-time"><?=$tlTimeLabel?></span>
        </div>
        <div class="timeline-title"><?=htmlspecialchars($tlItem['title'] ?? '')?></div>
        <?php if (!empty($tlItem['detail'])): ?><div class="timeline-detail"><?=htmlspecialchars($tlItem['detail'])?></div><?php endif; ?>
        <?php if (!empty($tlItem['badge'])): ?><div class="mt-1"><?=statusBadge($tlItem['badge']['enum'], $tlItem['badge']['context'])?></div><?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
