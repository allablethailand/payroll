<?php
/**
 * Status tabs -- docs/design/rules.md §6, item 4b. REVISED after live review: the plain nav-tabs
 * underline shape (this file's own first version) was rejected -- "คงรูปแบบ chevron pipeline ตามที่
 * approve แล้ว" -- the chevron pipeline (`.station-row`/`.station-card`, Employee List's
 * `#employeeStationRow` + Payroll Process's `#stationRow`) is KEPT as the visual, this partial's own
 * job is only to make it ONE shared component (was duplicated markup in 2 view files) with its
 * hardcoded hex colors replaced by tokens -- lighter than the old version (36px tall vs 38px, 8px
 * notch depth vs 12px, no border/shadow, 2px gap between steps).
 *
 * A second visual variant ('path' -- flat, no colored card, breadcrumb-style) existed alongside this
 * one for an explicit A/B comparison in components.php -- **decided, chevron won** -- 'path' and its
 * own `$variant` switch were removed entirely, not left as dead code/an unused option. If a future
 * round wants to revisit that decision, see git history for `docs/design/rules.md`/this file rather
 * than resurrecting it speculatively.
 *
 * Variables the calling view must set BEFORE including this file:
 *
 * @var string $id   Required. id for the wrapper (unique per page if more than one status-tabs row
 *                    exists).
 * @var array  $tabs Required. List of:
 *     ['key'=>string, 'label'=>string, 'tone'=>'neutral'|'warning'|'danger'|'success',
 *      'active'=>bool, 'direction'=>'forward'|'back']
 *   Exactly one entry should carry 'active' => true. 'direction' (default 'forward' when omitted)
 *   marks an EXCEPTION/reversed step in the workflow -- rejected/sent-back, need-more-info,
 *   cancelled -- the kind of status the old `.station-card--reject` class singled out with its own
 *   rotated-chevron visual. This value should come from `status_map.php` (item 5) per-status config,
 *   NEVER hardcoded/re-derived in the calling view -- this partial itself has zero opinion about
 *   which keys are 'back', it just renders whatever $tabs says. Order still matters: 'back' tabs
 *   belong at the END of $tabs, after every forward step, same as today's real pages already do it
 *   (order itself comes from `runLifecycleSteps()`/its future PHP equivalent, not this partial).
 *
 *   Visual grouping: every 'back' tab renders in its own group at the tail of the row, separated
 *   from the forward group by one `--sp-4` gap (not between individual back tabs, only before the
 *   FIRST one), and its whole card is rotated 180deg so its notches point LEFT instead of right
 *   ("ลูกศรชี้กลับ" -- reads as "this is a path backward", not a continuation of the forward flow).
 *
 *   Color rules (JS-driven via initStatusTabs()'s own update(), not decided in this PHP file):
 *   - Idle (not active): the pill is plain gray UNLESS 'tone' is warning/danger AND count > 0, in
 *     which case it becomes a real `.badge.badge-{tone}` (§5) -- "something to act on". This is the
 *     ONLY place 'tone' drives idle rendering -- a 'back' tab with 'tone' => 'neutral' (e.g.
 *     cancelled -- nothing left to act on once a run is cancelled) NEVER gets a colored idle pill no
 *     matter how high its count climbs, exactly like any forward tab with a neutral/success tone.
 *   - Active, direction 'forward': always the brand-orange "selected" treatment, regardless of
 *     'tone' -- tone only ever colors the COUNT while idle, never the whole selected step, for a
 *     forward step.
 *   - Active, direction 'back': the whole card takes a TONE color instead of orange -- uses 'tone'
 *     directly when it's warning/danger (rejected -> danger, need_info -> warning), but FALLS BACK
 *     to danger when 'tone' is neutral/success (cancelled -> danger even though its own 'tone' is
 *     neutral) -- a reversed/exception step you're actively looking at should always read as a
 *     serious color, even one whose IDLE badge is deliberately muted because there's nothing left to
 *     act on.
 *
 * JS pairing (see public/js/app.js's own initStatusTabs() docblock for the full API). If this row
 * needs to sit next to a `filter-bar.php` toolbar (e.g. "ตัวกรอง (N)"/chips/"ล้าง" flush right of the
 * pipeline), pass this element's own selector as that filter bar's own `toolbarTarget` option --
 * see initFilterBar()'s own docblock, this partial itself has no filter-bar awareness:
 *   const runTabs = initStatusTabs('#stationRow', { onChange: function (key) { ... } });
 *   runTabs.update({ draft: 12, pending_approval: 5, rejected: 2, ... });
 *   initFilterBar('#stationFilterBar', { toolbarTarget: '#stationRow', onChange: function () {} });
 *
 * Example call site (round 4, not written yet -- this file has no consumer this round):
 *   $id = 'stationRow';
 *   $tabs = [
 *       ['key' => 'draft', 'label' => 'ฉบับร่าง', 'tone' => 'neutral', 'active' => true],
 *       ['key' => 'pending_approval', 'label' => 'รออนุมัติ', 'tone' => 'warning', 'active' => false],
 *       ['key' => 'rejected', 'label' => 'ถูกปฏิเสธ', 'tone' => 'danger', 'active' => false, 'direction' => 'back'],
 *       ['key' => 'need_info', 'label' => 'ขอข้อมูลเพิ่ม', 'tone' => 'warning', 'active' => false, 'direction' => 'back'],
 *       ['key' => 'cancelled', 'label' => 'ยกเลิก', 'tone' => 'neutral', 'active' => false, 'direction' => 'back'],
 *   ];
 *   include __DIR__ . '/../partials/status-tabs.php';
 */
?>
<div class="status-tabs status-tabs-chevron" id="<?=htmlspecialchars($id)?>">
    <?php foreach ($tabs as $stTab):
        $stDirection = ($stTab['direction'] ?? 'forward') === 'back' ? 'back' : 'forward';
        $stBack = $stDirection === 'back';
        $stTone = htmlspecialchars($stTab['tone'] ?? 'neutral');
    ?>
    <div class="status-tab-col<?=$stBack ? ' status-tab-col--back' : ''?>">
        <button type="button"
                class="status-tab-btn status-tab-chevron<?=!empty($stTab['active']) ? ' active' : ''?><?=$stBack ? ' status-tab-btn--back' : ''?>"
                data-status-key="<?=htmlspecialchars($stTab['key'])?>"
                data-tone="<?=$stTone?>"
                data-direction="<?=$stDirection?>">
            <span class="status-tab-btn-inner">
                <?=htmlspecialchars($stTab['label'])?> <span class="status-tab-count">0</span>
            </span>
        </button>
    </div>
    <?php endforeach; ?>
</div>
