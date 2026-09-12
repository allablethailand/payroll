<?php
/**
 * Page header -- docs/design/rules.md §2. Replaces the old "card หัวหน้า + ไอคอน" pattern
 * (`.page-header-card`, 31 files per docs/design/audit.md's own SC1) -- NO card wrapper, NO icon,
 * NO colored background, per §2's own explicit rule. Plain `include`, standard convention this app
 * already uses for every other partial (`_list_partial.php`/`_modals_partial.php` etc. -- PHP's
 * `include` shares the caller's variable scope, so nothing is passed as an array/object).
 *
 * Variables the calling view must set BEFORE including this file:
 *
 * @var string $title                Required. The page's H1. Must NOT repeat the breadcrumb's own
 *                                    last item verbatim (§2: "Breadcrumb กับ H1 ห้ามพูดซ้ำกัน").
 * @var array  $breadcrumb           Required (may be an empty array for a page with no real trail).
 *                                    List of ['label' => string, 'href' => string|null]. The LAST
 *                                    entry is rendered as plain text (the current page), every entry
 *                                    before it as a link when 'href' is set.
 * @var array|null $primary_action   Optional. ONE primary button (§0.2: "1 หน้า/1 modal = 1 action
 *                                    หลัก มีปุ่มส้มได้ตัวเดียว"). Shape:
 *                                    ['label' => string, 'id' => string|null, 'href' => string|null,
 *                                     'icon' => string|null (a Font Awesome class, e.g. 'fa-solid fa-plus')].
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
 */
$breadcrumb = $breadcrumb ?? [];
$primary_action = $primary_action ?? null;
$secondary_actions = $secondary_actions ?? [];
$description = $description ?? null;

// Built as one ordered queue (secondary first, primary last) rather than a helper function --
// deliberately NOT declaring a named function in this file, since a function declared inside an
// `include` throws a fatal "cannot redeclare" if the SAME partial is ever included more than once
// in one request (stat-card.php, right next to this file, is explicitly looped/included multiple
// times per page -- this file staying function-free means it's safe to follow that same pattern
// later without thinking about it).
$phActionQueue = [];
foreach (array_slice($secondary_actions, 0, 2) as $phAction) {
    $phActionQueue[] = ['action' => $phAction, 'class' => 'btn-outline-secondary'];
}
if ($primary_action) {
    $phActionQueue[] = ['action' => $primary_action, 'class' => 'btn-primary'];
}
?>
<div class="ph-header">
    <?php if ($breadcrumb): ?>
    <nav class="ph-breadcrumb" aria-label="breadcrumb">
        <?php foreach ($breadcrumb as $i => $crumb): ?>
            <?php if ($i > 0): ?><span class="ph-breadcrumb-sep">/</span><?php endif; ?>
            <?php if (!empty($crumb['href']) && $i < count($breadcrumb) - 1): ?>
                <a href="<?=htmlspecialchars($crumb['href'])?>" class="ph-breadcrumb-link"><?=htmlspecialchars($crumb['label'])?></a>
            <?php else: ?>
                <span class="ph-breadcrumb-current"><?=htmlspecialchars($crumb['label'])?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="ph-title-row">
        <h1 class="ph-title"><?=htmlspecialchars($title)?></h1>
        <?php if ($phActionQueue): ?>
        <div class="ph-actions">
            <?php foreach ($phActionQueue as $phItem): $phBtnAction = $phItem['action']; $phBtnClass = $phItem['class']; ?>
                <?php if (!empty($phBtnAction['href'])): ?>
                <a href="<?=htmlspecialchars($phBtnAction['href'])?>" class="btn <?=$phBtnClass?> ph-action">
                    <?php if (!empty($phBtnAction['icon'])): ?><i class="<?=htmlspecialchars($phBtnAction['icon'])?> me-1"></i><?php endif; ?>
                    <?=htmlspecialchars($phBtnAction['label'])?>
                </a>
                <?php else: ?>
                <button type="button" id="<?=htmlspecialchars($phBtnAction['id'] ?? '')?>" class="btn <?=$phBtnClass?> ph-action">
                    <?php if (!empty($phBtnAction['icon'])): ?><i class="<?=htmlspecialchars($phBtnAction['icon'])?> me-1"></i><?php endif; ?>
                    <?=htmlspecialchars($phBtnAction['label'])?>
                </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($description): ?>
    <p class="ph-description"><?=htmlspecialchars($description)?></p>
    <?php endif; ?>
</div>
