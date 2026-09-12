<?php
/**
 * Stat card -- docs/design/rules.md §2. Renders with the class `.stat` (deliberately NOT
 * `.stat-card`, per rules.md §2's own decision to kill the old colored+iconed class rather than
 * extend it -- the old one always has 7 possible tone colors + an icon; this one is plain
 * white/bordered with an OPTIONAL single-color icon, no colored edge, ever). Renders ONE card per
 * include -- the calling view wraps N includes in its own `.row g-3` (matching how every existing
 * stat-card row in this app is already structured, e.g. Annual Income Summary's own
 * `#aisSummaryCards`), this partial does not own the grid/row itself. The caller's row is expected to
 * be a plain Bootstrap `.row` -- Bootstrap's own grid already stretches every column to the row's
 * tallest child by default, and `.stat` itself fills that stretched height (`height:100%`) so every
 * card in one row is always the same height, regardless of which ones have a sub/badge/link and
 * which don't -- see `.stat-footer`'s own comment in style.css for how the bottom slot stays
 * reserved (and pinned to the bottom edge) either way.
 *
 * REVISED TWICE after explicit follow-ups -- the original version of this partial (and rules.md §2's
 * original wording) said "no icon at all." That's been loosened: an OPTIONAL icon is now allowed per
 * card, always a single flat color, never a colored background/border on the CARD itself (that
 * constraint is unchanged). Two layout variants were compared side by side in components.php --
 * 'a' (icon top-right corner, bare, 20px `--c-text-faint`) and 'b' (icon in a 40px `--c-bg-subtle`
 * circle to the left). First decided 'b', THEN REVERSED back to 'a' after a second look -- 'a' is
 * the one this file now renders, unconditionally, no `$variant` switch left at all. Every 'b'-only
 * CSS rule (`.stat-body`/`.stat-icon-circle`/`.stat-text`) has been deleted outright a second time,
 * not left as dead code either round. If a future round wants to revisit this again, see git history
 * for `docs/design/rules.md`/this file rather than resurrecting either layout speculatively.
 *
 * Variables the calling view must set BEFORE including this file:
 *
 * @var array $stat Required. Shape:
 *     ['label' => string, 'value' => string|int|float, 'icon' => string|null,
 *      'sub' => string|null, 'badge' => ['enum'=>string,'context'=>string]|null,
 *      'link' => ['label'=>string,'href'=>string]|null]
 *   - 'icon' is an optional Font Awesome class string (e.g. 'fa-solid fa-users') -- at most one,
 *     rendered top-right, bare (no circle/background of its own), 20px, `--c-text-faint`. **When
 *     omitted, no space is reserved for it at all** -- the label simply sits alone in that row (a
 *     flex row with only one child never leaves a gap on the side the missing sibling would have
 *     occupied), exactly like a plain card with nothing missing (explicit decision, both times this
 *     was asked: "เลือกไม่เว้น — ข้อความชิดซ้ายปกติ"/"ไม่มีไอคอน = ไม่เว้นที่ (เหมือนเดิม)").
 *   - 'value' is rendered through `.num` (tabular-nums) -- pass it pre-formatted (e.g. already run
 *     through fmtMoney()) if it's money; this partial does not format it itself.
 *   - 'sub' is a plain one-line string, muted gray text -- NOT where a status badge goes (see
 *     'badge' below). Renders inside the same fixed-height bottom slot as 'badge'/'link'.
 *   - 'badge' is how a status that needs a decision (§2: "ถ้าค่าเป็นสถานะที่ต้องตัดสินใจ เช่น 'รออนุมัติ 3'
 *     ใช้ badge...ไม่ใช่เปลี่ยนสีทั้งการ์ด") gets flagged -- `{enum, context}` (item 5): this partial calls
 *     the shared `statusBadge($enum, $context)` (app/helpers/helpers.php, §5) itself and echoes its
 *     return value directly, so every stat-card badge is FORCED to come from
 *     `app/config/status_map.php` -- there is no way to pass an ad-hoc label/color that bypasses the
 *     shared map.
 *   - 'link' is optional (e.g. "ดูทั้งหมด" -- rendered as a tertiary/text link, never a button).
 *   - 'badge' and 'link' (and even 'sub') can all be present at once -- the bottom slot lays out
 *     whichever of the three exist, in that order, and reserves the SAME height whether 0, 1, 2, or
 *     all 3 of them are present, so a card with nothing in its bottom slot is still exactly as tall
 *     as a neighbor that has all three.
 *
 * Example call site (round 4, not written yet -- this file has no consumer this round):
 *   foreach ($stats as $stat) { include __DIR__ . '/../partials/stat-card.php'; }
 *   // $stats = [
 *   //     ['label' => 'พนักงานทั้งหมด', 'value' => 128, 'icon' => 'fa-solid fa-users', 'sub' => null, 'badge' => null, 'link' => null],
 *   //     ['label' => 'รออนุมัติ', 'value' => 3, 'icon' => 'fa-solid fa-hourglass-half', 'sub' => null, 'badge' => ['enum' => 'pending_approval', 'context' => 'run_state'], 'link' => ['label' => 'ดูทั้งหมด', 'href' => '#']],
 *   // ];
 */
$stIcon = $stat['icon'] ?? null;
$stSub = $stat['sub'] ?? null;
$stBadge = $stat['badge'] ?? null;
$stLink = $stat['link'] ?? null;
$stHasFooter = $stSub || $stBadge || $stLink;
?>
<div class="stat">
    <div class="stat-head">
        <div class="stat-label"><?=htmlspecialchars($stat['label'])?></div>
        <?php if ($stIcon): ?><i class="<?=htmlspecialchars($stIcon)?> stat-icon"></i><?php endif; ?>
    </div>
    <div class="stat-value num"><?=htmlspecialchars((string)$stat['value'])?></div>
    <div class="stat-footer<?=$stHasFooter ? '' : ' stat-footer-empty'?>">
        <?php if ($stBadge): ?><?=statusBadge($stBadge['enum'], $stBadge['context'])?><?php endif; ?>
        <?php if ($stSub): ?><span class="stat-sub"><?=htmlspecialchars($stSub)?></span><?php endif; ?>
        <?php if ($stLink): ?><a href="<?=htmlspecialchars($stLink['href'])?>" class="stat-link"><?=htmlspecialchars($stLink['label'])?></a><?php endif; ?>
    </div>
</div>
