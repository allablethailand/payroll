<?php
/**
 * Employee header card -- docs/design/rules.md §9, Round 2 item 6c. Every modal opened from an
 * employee row uses this as its FIRST block (quick-view, calculation breakdown, comments, verify,
 * bank account assignment, etc).
 *
 * PHP counterpart of app.js's own `employeeHeaderCardHtml(emp)` (Batch 3C item 8 -- GENERALIZED
 * this round, not duplicated; that function already had 6 real call sites in payroll/detail.js
 * before this round even started, deliberately left unstyled with an explicit "design phase comes
 * later" comment -- this partial/its CSS is that deferred work). This PHP side is new -- the JS
 * version had no PHP-reachable twin at all before this.
 *
 * @var array $employee Same field shape the JS twin expects: name_th/surname_th/name_en/
 *   surname_en, employee_no, profile_photo_path, department_name_th/en, position_name_th/en,
 *   employee_status (optional -- badge omitted entirely if absent, never renders a broken/unmapped
 *   badge for a field that isn't there).
 */
$ehcEmp = $employee ?? [];
$ehcLang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
$ehcName = trim($ehcLang === 'th'
    ? (($ehcEmp['name_th'] ?? '') . ' ' . ($ehcEmp['surname_th'] ?? ''))
    : (($ehcEmp['name_en'] ?? $ehcEmp['name_th'] ?? '') . ' ' . ($ehcEmp['surname_en'] ?? $ehcEmp['surname_th'] ?? ''))
) ?: '-';
$ehcDept = ($ehcLang === 'th' ? ($ehcEmp['department_name_th'] ?? null) : ($ehcEmp['department_name_en'] ?? null))
    ?? ($ehcEmp['department_name_th'] ?? $ehcEmp['department_name_en'] ?? '-');
$ehcPos = ($ehcLang === 'th' ? ($ehcEmp['position_name_th'] ?? null) : ($ehcEmp['position_name_en'] ?? null))
    ?? ($ehcEmp['position_name_th'] ?? $ehcEmp['position_name_en'] ?? '-');
$ehcInitial = htmlspecialchars(mb_strtoupper(mb_substr(trim($ehcName) !== '' ? trim($ehcName) : '?', 0, 1)));
?>
<div class="emp-header-card">
    <?php if (!empty($ehcEmp['profile_photo_path'])): ?>
        <img src="<?=htmlspecialchars($ehcEmp['profile_photo_path'])?>" alt="" style="width:40px;height:40px;min-width:40px;border-radius:50%;object-fit:cover;">
    <?php else: ?>
        <span class="apv-person-avatar" style="width:40px;height:40px;min-width:40px;font-size:1rem;"><?=$ehcInitial?></span>
    <?php endif; ?>
    <div class="emp-header-card-body">
        <div class="emp-header-card-line1">
            <span class="emp-header-card-name"><?=htmlspecialchars($ehcName)?></span>
            <span class="emp-header-card-code"><?=htmlspecialchars($ehcEmp['employee_no'] ?? '-')?></span>
        </div>
        <div class="emp-header-card-line2"><?=htmlspecialchars($ehcDept)?> &middot; <?=htmlspecialchars($ehcPos)?></div>
    </div>
    <?php if (!empty($ehcEmp['employee_status'])): ?>
        <div class="emp-header-card-badge"><?=statusBadge($ehcEmp['employee_status'], 'employee_status')?></div>
    <?php endif; ?>
</div>
