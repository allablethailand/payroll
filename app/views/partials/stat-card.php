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
 * REVISED after explicit follow-up -- the original version of this partial (and rules.md §2's
 * original wording) said "no icon at all." That's been loosened: an OPTIONAL icon is now allowed per
 * card, always a single flat color, never a colored background/border on the CARD itself (that
 * constraint is unchanged). Two layout variants ('a': icon top-right, bare; 'b': icon in a 40px
 * `--c-bg-subtle` circle to the left) were compared side by side in components.php -- **decided,
 * variant 'b' won** -- 'a' and its own `$variant` switch were removed entirely, not left as dead
 * code. If a future round wants to revisit that decision, see git history for
 * `docs/design/rules.md`/this file rather than resurrecting it speculatively.
 *
 * Variables the calling view must set BEFORE including this file:
 *
 * @var array $stat Required. Shape:
 *     ['label' => string, 'value' => string|int|float, 'icon' => string|null,
 *      'sub' => string|null, 'badge' => ['label'=>string,'tone'=>'neutral'|'warning'|'danger'|'success']|null,
 *      'link' => ['label'=>string,'href'=>string]|null]
 *   - 'icon' is an optional Font Awesome class string (e.g. 'fa-solid fa-users') -- at most one,
 *     rendered inside a 40px `--c-bg-subtle` circle (`--c-text-muted` icon color) to the left of the
 *     label+value block. **When omitted, no circle is reserved at all** -- the label+value block
 *     sits flush left like any plain card, not indented to leave empty space where a circle would
 *     have been (explicit decision: "เลือกไม่เว้น — ข้อความชิดซ้ายปกติ").
 *   - 'value' is rendered through `.num` (tabular-nums) -- pass it pre-formatted (e.g. already run
 *     through fmtMoney()) if it's money; this partial does not format it itself.
 *   - 'sub' is a plain one-line string, muted gray text -- NOT where a status badge goes (see
 *     'badge' below). Renders inside the same fixed-height bottom slot as 'badge'/'link'.
 *   - 'badge' is how a status that needs a decision (§2: "ถ้าค่าเป็นสถานะที่ต้องตัดสินใจ เช่น 'รออนุมัติ 3'
 *     ใช้ badge...ไม่ใช่เปลี่ยนสีทั้งการ์ด") gets flagged -- `{label, tone}`, rendered as a real
 *     `.badge.badge-{tone}` (§5) INSIDE the bottom slot only, never as a color applied to the card
 *     itself. `tone` should come from `status_map.php` (item 5, not built yet) once it exists -- this
 *     partial has zero dependency on that file existing today, the caller passes tone directly.
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
 *   //     ['label' => 'รออนุมัติ', 'value' => 3, 'icon' => 'fa-solid fa-hourglass-half', 'sub' => null, 'badge' => ['label' => 'ต้องดำเนินการ', 'tone' => 'warning'], 'link' => ['label' => 'ดูทั้งหมด', 'href' => '#']],
 *   // ];
 */
$stIcon = $stat['icon'] ?? null;
$stSub = $stat['sub'] ?? null;
$stBadge = $stat['badge'] ?? null;
$stLink = $stat['link'] ?? null;
$stHasFooter = $stSub || $stBadge || $stLink;
?>
<div class="stat">
    <div class="stat-body">
        <?php if ($stIcon): ?><div class="stat-icon-circle"><i class="<?=htmlspecialchars($stIcon)?>"></i></div><?php endif; ?>
        <div class="stat-text">
            <div class="stat-label"><?=htmlspecialchars($stat['label'])?></div>
            <div class="stat-value num"><?=htmlspecialchars((string)$stat['value'])?></div>
        </div>
    </div>
    <div class="stat-footer<?=$stHasFooter ? '' : ' stat-footer-empty'?>">
        <?php if ($stBadge): ?><span class="badge badge-<?=htmlspecialchars($stBadge['tone'] ?? 'neutral')?>"><?=htmlspecialchars($stBadge['label'])?></span><?php endif; ?>
        <?php if ($stSub): ?><span class="stat-sub"><?=htmlspecialchars($stSub)?></span><?php endif; ?>
        <?php if ($stLink): ?><a href="<?=htmlspecialchars($stLink['href'])?>" class="stat-link"><?=htmlspecialchars($stLink['label'])?></a><?php endif; ?>
    </div>
</div>
