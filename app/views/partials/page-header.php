<?php
// design:clean -- docs/design/rules.md §12, Round 2 item 8. Passes scripts/check-design.php with 0 hits.
/**
 * Page header -- docs/design/rules.md §2. Replaces the old "card หัวหน้า + ไอคอน" pattern
 * (`.page-header-card`, 31 files per docs/design/audit.md's own SC1) -- NO card wrapper, NO icon,
 * NO colored background, per §2's own explicit rule. Plain `include`, standard convention this app
 * already uses for every other partial (`_list_partial.php`/`_modals_partial.php` etc. -- PHP's
 * `include` shares the caller's variable scope, so nothing is passed as an array/object).
 *
 * 2026-09-13, Round 3 item 3a (Payroll Detail pilot -- the first REAL page to adopt this partial):
 * every dynamic piece renders with a stable id a page can hook into once its real data loads via an
 * async fetch (this was the one thing the original static-page design never needed) --
 * `#phBreadcrumbCurrent` (the last breadcrumb crumb), `#phTitle`/`#phTitleBadge` (the H1 + an empty
 * sibling slot for a status badge), `#phDescription` (always rendered, `d-none` when `$description`
 * starts empty), and `#phActions` (always rendered, even with an empty action queue -- see
 * `renderPageHeaderActions()` in app.js, this partial's own JS twin for re-rendering
 * primary/secondary/overflow buttons after a state change, the same "PHP partial = first paint, JS
 * twin = live updates" split `renderStatusStepper()`/`renderTimeline()`/`renderCalendarWidget()`
 * already use).
 *
 * Variables the calling view must set BEFORE including this file:
 *
 * @var string $title                Required. The page's H1. Must NOT repeat the breadcrumb's own
 *                                    last item verbatim (§2: "Breadcrumb กับ H1 ห้ามพูดซ้ำกัน").
 * @var array  $breadcrumb           Required (may be an empty array for a page with no real trail).
 *                                    List of ['label' => string, 'href' => string|null,
 *                                    'i18n' => string|null]. The LAST entry is rendered as plain text
 *                                    (the current page), every entry before it as a link when 'href'
 *                                    is set. 'i18n' (2026-09-13, Round 3 item 3a -- the OLD
 *                                    `.payroll-breadcrumb` markup this replaces on Payroll Detail had
 *                                    `data-i18n="payroll"`/`"payroll_process"` on its own static
 *                                    crumbs; dropping that would have been a real i18n regression,
 *                                    not just a style change) adds a matching `data-i18n="..."`
 *                                    attribute so app.js's own updateText() keeps translating a
 *                                    STATIC crumb label same as before -- omit it for a crumb whose
 *                                    label is already resolved server-side or is a real data value
 *                                    (a run name, an employee name) that was never translatable to
 *                                    begin with.
 * @var array|null $primary_action   Optional. ONE primary button (§0.2: "1 หน้า/1 modal = 1 action
 *                                    หลัก มีปุ่มส้มได้ตัวเดียว"). Shape:
 *                                    ['label' => string, 'id' => string|null, 'href' => string|null,
 *                                     'icon' => string|null (a Font Awesome class, e.g. 'fa-solid fa-plus'),
 *                                     'extraClass' => string|null (2026-09-13, Round 3 item 3a -- an
 *                                     extra CSS class appended alongside `id`, for a button/menu item
 *                                     that ALSO needs to keep matching an existing CLASS-based
 *                                     delegated click handler elsewhere, e.g. Payroll Detail's own
 *                                     `.btn-tl-approve` etc. -- `id` alone is not always enough)].
 *                                    Exactly one of 'id' (renders a <button id="...">) or 'href'
 *                                    (renders an <a href="...">) should be set. Omit/null entirely
 *                                    when the page genuinely has no single primary action -- do not
 *                                    invent one just to fill the slot.
 * @var array|null $secondary_actions Optional, at most 2 -- decided this round: page-header actions
 *                                    are page-LEVEL ones only ("สร้าง/ดึงข้อมูล/ประวัติ" -- e.g. Sync
 *                                    from Origami, Import Excel, View History), never a bulk action
 *                                    on selected table rows (those belong in the table's OWN toolbar,
 *                                    left side, after the length control -- see §7). Same shape as
 *                                    $primary_action minus the "exactly one" constraint, rendered
 *                                    `.btn-outline-secondary` (§4's Secondary tier), placed to the
 *                                    LEFT of $primary_action, in array order. Anything past the 2nd
 *                                    entry is silently ignored -- keep it to 2, per the same
 *                                    decision, don't crowd the header.
 *                                    2026-09-13, Round 3 item 3a extension (Payroll Detail pilot's own
 *                                    "ส่งออก ▾ Excel/PDF" need -- confirmed via explicit instruction:
 *                                    "page-header.php ต้องรองรับ secondary เป็น dropdown ได้"): an
 *                                    entry may carry `'items' => [ ['label'=>string,
 *                                    'id'=>string|null, 'href'=>string|null, 'icon'=>string|null,
 *                                    'tone'=>'danger'|null], ... ]` INSTEAD OF `id`/`href` -- renders
 *                                    as a `.btn-group` dropdown-toggle button (same
 *                                    `.btn-outline-secondary` class as a plain secondary action) with
 *                                    those items as its menu, instead of navigating/click-firing
 *                                    directly. Still counts as ONE of the 2 slots.
 * @var array|null $overflow_actions Optional, new 2026-09-13 (Round 3 item 3a, Payroll Detail
 *                                   pilot's own state-transition actions -- "ขอข้อมูลเพิ่ม/ปฏิเสธ/
 *                                   ส่งกลับ/ยกเลิก" -- explicit instruction: "dropdown [อื่นๆ ▾] รวม...
 *                                   (รายการทำลายอยู่ล่างสุด --c-danger)"). A FLAT list of menu items,
 *                                   same item shape as a `$secondary_actions` dropdown's `items`
 *                                   above (`label`/`id`/`href`/`icon`/`tone`). Placed AFTER
 *                                   $secondary_actions and BEFORE $primary_action -- deliberately a
 *                                   SEPARATE slot from $secondary_actions rather than a 3rd item
 *                                   competing for the "at most 2" cap: secondary actions are
 *                                   page-level create/fetch/export actions, this is a distinct
 *                                   category (state-transition / destructive actions on the record
 *                                   itself, incl. the ONE place §0.2's "no ad-hoc destructive action"
 *                                   caution applies) -- conflating the two into one capped list would
 *                                   force a page to choose between showing "Export" or "Cancel run",
 *                                   which was never the intent.
 *                                   **Exactly 1 item (2026-09-13, "เมนูอื่นๆ" follow-up): renders as a
 *                                   PLAIN `.btn-outline-secondary` button** (that one item's own
 *                                   label/id/href/icon/extraClass), NOT a 1-item dropdown -- a menu
 *                                   that can only ever offer one choice isn't a menu. Its own `tone`
 *                                   (if any) is never applied to this button -- the plain-button
 *                                   render path doesn't read `tone` at all, so it's always
 *                                   outline-secondary regardless, matching §4's "destructive color
 *                                   only ever on the eventual confirm button" rule.
 *                                   **2+ items: a real dropdown**, label from $overflow_label
 *                                   (default "อื่นๆ"). Items with `'tone' => 'danger'` render
 *                                   `.dropdown-item.text-danger` and get exactly ONE divider inserted
 *                                   immediately before the FIRST such item -- but ONLY when at least
 *                                   one non-danger item renders ABOVE it (2026-09-13 fix: a menu that
 *                                   is entirely danger items, i.e. the danger group starts at index 0,
 *                                   gets no divider at all -- there's no normal group above it to
 *                                   separate from) -- put dangerous items last in the array.
 * @var string|null $overflow_label  Optional, default "อื่นๆ" -- the overflow dropdown's own trigger
 *                                   label, only rendered when $overflow_actions is non-empty.
 * @var array|null $decision_actions Optional, new 2026-09-13 (Round 3 item 3a, Payroll Detail pilot's
 *                                   own approver-view need; REVISED same-day -- see below). A caller
 *                                   sets EITHER this OR $primary_action, never both -- when non-empty,
 *                                   this ENTIRELY replaces the primary-button slot ($primary_action is
 *                                   silently ignored if also set). Rendered together in one
 *                                   `.ph-decision-group` wrapper, visually separated from
 *                                   $secondary_actions/$overflow_actions by `--sp-3` (wider than the
 *                                   `--sp-2` gap between ordinary actions) -- this is for a genuine
 *                                   DECISION cluster (e.g. "อนุมัติ" / "ขอข้อมูลเพิ่มเติม" / "ไม่อนุมัติ"
 *                                   together, side by side, not buried in an overflow menu) where the
 *                                   reader needs to see every option before picking one, IN THE ORDER
 *                                   THE CALLER GIVES THEM (this partial never reorders -- the caller
 *                                   decides left-to-right order to match its own real priority).
 *                                   Shape: {label, id|href, icon, extraClass, tone}.
 *                                   **`tone` (REQUIRED per item as of this revision -- replaces the
 *                                   original "last item = primary, the rest outline-secondary" rule
 *                                   entirely): one of 'success'|'warning'|'danger'**, picking
 *                                   `.btn-decision-success`/`.btn-decision-warning`/`.btn-decision-danger`
 *                                   (style.css, next to `.ph-decision-group`) -- each item's own tone
 *                                   reflects what CHOOSING it actually does to the record's state, not
 *                                   position in the array. Omitting `tone` on an item falls back to
 *                                   'success' (arbitrary but safe -- every real caller sets it
 *                                   explicitly; this is not a designed default to rely on).
 *                                   This is a DOCUMENTED §4 EXCEPTION (rules.md §4's own exception
 *                                   entry) -- outside a $decision_actions cluster, action buttons still
 *                                   follow the ordinary primary/outline-secondary/link/danger-in-confirm-
 *                                   only hierarchy unchanged; `.btn-decision-*` is never legal there.
 *                                   Example (Payroll Detail, pending_approval + can_approve_payroll):
 *                                     $decision_actions = [
 *                                         ['label' => 'อนุมัติ', 'id' => 'btnApproveRunHeader', 'tone' => 'success'],
 *                                         ['label' => 'ขอข้อมูลเพิ่มเติม', 'id' => 'btnRequestInfoRunHeader', 'tone' => 'warning'],
 *                                         ['label' => 'ไม่อนุมัติ', 'id' => 'btnRejectRunHeader', 'tone' => 'danger'],
 *                                     ];
 * @var string|null $description     Optional, ONE line (§2: "คำอธิบาย 1 บรรทัด (ถ้าจำเป็นจริง)"). Only
 *                                    set this when the title alone genuinely doesn't explain the page.
 *
 * Example call site (round 4, not written yet -- this file has no consumer this round):
 *   $title = 'พนักงาน';
 *   $breadcrumb = [['label' => 'หน้าหลัก', 'href' => BASE_URL . '/dashboard'], ['label' => 'พนักงาน', 'href' => null]];
 *   $secondary_actions = [
 *       ['label' => 'ซิงค์จาก Origami', 'id' => 'btnSyncEmployees', 'icon' => 'fa-solid fa-rotate'],
 *       ['label' => 'นำเข้า Excel', 'id' => 'btnImportEmployees', 'icon' => 'fa-solid fa-file-import'],
 *   ];
 *   $primary_action = ['label' => 'เพิ่มพนักงาน', 'id' => 'btnAddEmployee', 'icon' => 'fa-solid fa-plus'];
 *   $description = null;
 *   include __DIR__ . '/../partials/page-header.php';
 *
 * Example call site with a dropdown secondary action + overflow menu (real, Payroll Detail's own
 * Employee Breakdown tab -- app/views/payroll/detail.php):
 *   $secondary_actions = [
 *       ['label' => 'คำนวณใหม่', 'id' => 'btnRecalcRun', 'icon' => 'fa-solid fa-rotate'],
 *       ['label' => 'ส่งออก', 'icon' => 'fa-solid fa-file-export', 'items' => [
 *           ['label' => 'Excel', 'id' => 'btnExportRunRegister', 'icon' => 'fa-solid fa-file-excel'],
 *           ['label' => 'PDF', 'id' => 'btnPreviewRunRegisterPdf', 'icon' => 'fa-solid fa-file-pdf'],
 *       ]],
 *   ];
 *   $overflow_actions = [
 *       ['label' => 'ขอข้อมูลเพิ่ม', 'id' => 'btnRequestInfo', 'icon' => 'fa-solid fa-circle-question'],
 *       ['label' => 'ปฏิเสธ', 'id' => 'btnRejectRun', 'icon' => 'fa-solid fa-xmark', 'tone' => 'danger'],
 *       ['label' => 'ยกเลิกรอบ', 'id' => 'btnCancelRun', 'icon' => 'fa-solid fa-ban', 'tone' => 'danger'],
 *   ];
 *   $primary_action = ['label' => 'อนุมัติ', 'id' => 'btnApproveRun'];
 *
 * @var string|null $id_prefix Optional, default 'ph'. Prefixes every stable id this partial renders
 *                              (#{prefix}Title/{prefix}TitleBadge/{prefix}Description/{prefix}Actions/
 *                              {prefix}BreadcrumbCurrent). A REAL page never needs to set this --
 *                              there is only ever one page-header.php per real page. Exists ONLY for
 *                              a showcase/demo page that legitimately includes this partial more than
 *                              once (docs/design/components.php does exactly this -- confirmed a real
 *                              bug 2026-09-13: hardcoding these ids unconditionally, without this
 *                              escape hatch, produced duplicate DOM ids the moment a second demo
 *                              section on that same page also included this file for its own
 *                              "full page mockup" illustration).
 */
$breadcrumb = $breadcrumb ?? [];
$primary_action = $primary_action ?? null;
$secondary_actions = $secondary_actions ?? [];
$id_prefix = $id_prefix ?? 'ph';
$overflow_actions = $overflow_actions ?? [];
$overflow_label = $overflow_label ?? 'อื่นๆ';
$decision_actions = $decision_actions ?? [];
$description = $description ?? null;

// Built as one ordered queue (secondary first, overflow next, primary/decision-group last) rather
// than a helper function -- deliberately NOT declaring a named function in this file, since a
// function declared inside an `include` throws a fatal "cannot redeclare" if the SAME partial is ever
// included more than once in one request (stat-card.php, right next to this file, is explicitly
// looped/included multiple times per page -- this file staying function-free means it's safe to
// follow that same pattern later without thinking about it). Each queue entry's own optional 'group'
// key ('decision' or unset) tells the render loop below which entries to wrap together in one
// `.ph-decision-group` div -- always the tail of the queue, so a single boolean flag during the loop
// is enough to open/close that wrapper correctly with no lookahead needed.
$phActionQueue = [];
foreach (array_slice($secondary_actions, 0, 2) as $phAction) {
    $phActionQueue[] = ['action' => $phAction, 'class' => 'btn-outline-secondary', 'group' => null];
}
if ($overflow_actions) {
    // 2026-09-13, item 3a "เมนูอื่นๆ" follow-up: exactly 1 item left in the overflow list renders as a
    // PLAIN button using that item's own label/id/href/icon/extraClass, not a 1-item dropdown -- a
    // menu that can only ever show one choice isn't a menu, it's just an extra click to reach the same
    // single action. Always `.btn-outline-secondary` REGARDLESS of the item's own `tone` -- the plain-
    // button render branch below never reads `tone` at all (only the dropdown-items branch does), so
    // a lone `tone:'danger'` item is silently, correctly never colored here -- destructive color still
    // only ever belongs on the eventual SweetAlert/modal confirm button (§4), never this trigger.
    if (count($overflow_actions) === 1) {
        $phActionQueue[] = ['action' => $overflow_actions[0], 'class' => 'btn-outline-secondary', 'group' => null];
    } else {
        $phActionQueue[] = ['action' => ['label' => $overflow_label, 'icon' => null, 'items' => $overflow_actions], 'class' => 'btn-outline-secondary', 'group' => null];
    }
}
if ($decision_actions) {
    // 2026-09-13, same-day revision: each item's OWN `tone` (success/warning/danger) picks its class
    // now -- the earlier "last item = primary, everything else outline-secondary" rule is gone
    // entirely, not just superseded in this one caller's data.
    foreach ($decision_actions as $phAction) {
        $phDecisionTone = in_array($phAction['tone'] ?? null, ['success', 'warning', 'danger'], true) ? $phAction['tone'] : 'success';
        $phActionQueue[] = ['action' => $phAction, 'class' => 'btn-decision-' . $phDecisionTone, 'group' => 'decision'];
    }
} elseif ($primary_action) {
    $phActionQueue[] = ['action' => $primary_action, 'class' => 'btn-primary', 'group' => null];
}
?>
<div class="ph-header">
    <?php if ($breadcrumb): ?>
    <nav class="ph-breadcrumb" aria-label="breadcrumb">
        <?php $phCrumbCount = count($breadcrumb); foreach ($breadcrumb as $i => $crumb):
            $phIsLastCrumb = $i === $phCrumbCount - 1;
            $phCrumbI18nAttr = !empty($crumb['i18n']) ? ' data-i18n="' . htmlspecialchars($crumb['i18n']) . '"' : '';
        ?>
            <?php if ($i > 0): ?><span class="ph-breadcrumb-sep">/</span><?php endif; ?>
            <?php if ($phIsLastCrumb): ?>
                <!-- id="phBreadcrumbCurrent" (2026-09-13, Round 3 item 3a) -- a stable hook for a page
                     whose current-crumb text is only known after an async fetch (e.g. Payroll
                     Detail's own run name) to update it client-side, same "starts as a placeholder,
                     JS fills it in" pattern the old .bc-current convention already used. -->
                <span class="ph-breadcrumb-current" id="<?=htmlspecialchars($id_prefix)?>BreadcrumbCurrent"<?=$phCrumbI18nAttr?>><?=htmlspecialchars($crumb['label'])?></span>
            <?php elseif (!empty($crumb['href'])): ?>
                <a href="<?=htmlspecialchars($crumb['href'])?>" class="ph-breadcrumb-link"<?=$phCrumbI18nAttr?>><?=htmlspecialchars($crumb['label'])?></a>
            <?php else: ?>
                <!-- 2026-09-13, Round 3 item 3a, real gap found in this partial's OWN original logic:
                     a non-last crumb with no href (e.g. a plain "Home" label that isn't itself a
                     link, as opposed to "Payroll Process" right after it, which IS one) used to fall
                     into the `else` branch below and incorrectly render with the CURRENT-page's own
                     orange-pill styling -- never caught before because every existing example call
                     site always set `href` on every non-last crumb. Middle crumbs with no href now
                     render as plain, non-clickable text in the SAME muted style as a real link
                     (`.ph-breadcrumb-link` on a `<span>` instead of an `<a>`), matching what the OLD
                     `.bc-root` (Payroll Detail's own non-clickable "Home" crumb) already looked like
                     next to `.bc-parent` (a real link) -- both were visually identical before. -->
                <span class="ph-breadcrumb-link"<?=$phCrumbI18nAttr?>><?=htmlspecialchars($crumb['label'])?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="ph-title-row">
        <!-- id="phTitle"/"phTitleBadge" (2026-09-13, Round 3 item 3a) -- same "stable hook for a
             value only known after an async fetch" reasoning as phBreadcrumbCurrent above (Payroll
             Detail's own run name + state badge, e.g. "อนุมัติแล้ว"). phTitleBadge has no styling of
             its own here -- a page sets whatever badge markup it needs via statusBadge()/
             statusBadgeHtml() (§5) into it, this partial just reserves the slot next to the title. -->
        <div class="ph-title-wrap">
            <h1 class="ph-title" id="<?=htmlspecialchars($id_prefix)?>Title"><?=htmlspecialchars($title)?></h1>
            <span id="<?=htmlspecialchars($id_prefix)?>TitleBadge"></span>
        </div>
        <!-- id="phActions" always rendered, even with an empty queue (2026-09-13, Round 3 item 3a) --
             a page whose actions are only knowable after an async fetch (e.g. Payroll Detail's own
             state-dependent primary/secondary/overflow buttons) needs a stable container to render
             INTO later (renderPageHeaderActions(), app.js) -- an `if ($phActionQueue)`-gated div
             wouldn't exist yet for such a page's very first paint, before anything has loaded. -->
        <div class="ph-actions" id="<?=htmlspecialchars($id_prefix)?>Actions">
            <?php $phInDecisionGroup = false; foreach ($phActionQueue as $phItem): $phBtnAction = $phItem['action']; $phBtnClass = $phItem['class'];
                $phExtraCls = !empty($phBtnAction['extraClass']) ? ' ' . htmlspecialchars($phBtnAction['extraClass']) : '';
                $phIsDecisionItem = ($phItem['group'] ?? null) === 'decision';
                if ($phIsDecisionItem && !$phInDecisionGroup) { $phInDecisionGroup = true; ?>
            <div class="ph-decision-group">
                <?php } ?>
                <?php if (!empty($phBtnAction['items'])): ?>
                <div class="btn-group ph-action-group">
                    <button type="button" class="btn <?=$phBtnClass?> ph-action dropdown-toggle<?=$phExtraCls?>" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($phBtnAction['icon'])): ?><i class="<?=htmlspecialchars($phBtnAction['icon'])?> me-1"></i><?php endif; ?>
                        <?=htmlspecialchars($phBtnAction['label'])?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php $phDangerDividerDone = false; foreach ($phBtnAction['items'] as $phMi => $phMenuItem):
                            $phIsDanger = ($phMenuItem['tone'] ?? null) === 'danger';
                            $phItemExtraCls = !empty($phMenuItem['extraClass']) ? ' ' . htmlspecialchars($phMenuItem['extraClass']) : '';
                            $phItemClass = 'dropdown-item' . ($phIsDanger ? ' text-danger' : '') . $phItemExtraCls;
                        ?>
                            <?php
                            // 2026-09-13, "เมนูอื่นๆ" follow-up: a divider separates a normal group FROM
                            // a danger group -- it only means something when there's at least one
                            // non-danger item genuinely rendered ABOVE it ($phMi > 0). A menu that's
                            // ENTIRELY danger items (the danger group starts at index 0) gets no divider
                            // at all -- there's no "normal group" above it to separate from. Still marks
                            // $phDangerDividerDone so a LATER danger item never tries to insert a 2nd one.
                            if ($phIsDanger && !$phDangerDividerDone):
                                $phDangerDividerDone = true;
                                if ($phMi > 0): ?>
                            <li><hr class="dropdown-divider"></li>
                            <?php endif; endif; ?>
                            <li>
                                <?php if (!empty($phMenuItem['href'])): ?>
                                <a class="<?=$phItemClass?>" href="<?=htmlspecialchars($phMenuItem['href'])?>">
                                    <?php if (!empty($phMenuItem['icon'])): ?><i class="<?=htmlspecialchars($phMenuItem['icon'])?> me-2"></i><?php endif; ?>
                                    <?=htmlspecialchars($phMenuItem['label'])?>
                                </a>
                                <?php else: ?>
                                <button type="button" id="<?=htmlspecialchars($phMenuItem['id'] ?? '')?>" class="<?=$phItemClass?>">
                                    <?php if (!empty($phMenuItem['icon'])): ?><i class="<?=htmlspecialchars($phMenuItem['icon'])?> me-2"></i><?php endif; ?>
                                    <?=htmlspecialchars($phMenuItem['label'])?>
                                </button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php elseif (!empty($phBtnAction['href'])): ?>
                <a href="<?=htmlspecialchars($phBtnAction['href'])?>" class="btn <?=$phBtnClass?> ph-action<?=$phExtraCls?>">
                    <?php if (!empty($phBtnAction['icon'])): ?><i class="<?=htmlspecialchars($phBtnAction['icon'])?> me-1"></i><?php endif; ?>
                    <?=htmlspecialchars($phBtnAction['label'])?>
                </a>
                <?php else: ?>
                <button type="button" id="<?=htmlspecialchars($phBtnAction['id'] ?? '')?>" class="btn <?=$phBtnClass?> ph-action<?=$phExtraCls?>">
                    <?php if (!empty($phBtnAction['icon'])): ?><i class="<?=htmlspecialchars($phBtnAction['icon'])?> me-1"></i><?php endif; ?>
                    <?=htmlspecialchars($phBtnAction['label'])?>
                </button>
                <?php endif; ?>
            <?php endforeach; if ($phInDecisionGroup): ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- id="phDescription" always rendered (2026-09-13, Round 3 item 3a) -- same stable-hook
         reasoning as phTitle/phBreadcrumbCurrent above, for a description only known after an async
         fetch (Payroll Detail's own "งวด · วันจ่าย"). `d-none` when $description starts empty/null,
         a page fetching its real value client-side removes that class itself alongside setting the
         text -- same pattern this app's own reject/cancel-reason boxes already use. -->
    <p class="ph-description<?=$description ? '' : ' d-none'?>" id="<?=htmlspecialchars($id_prefix)?>Description"><?=htmlspecialchars($description ?? '')?></p>
</div>
