<?php
/**
 * Employment Certificate Template editor -- standalone page (2026-08-25, explicit request: "หน้าแก้ไข
 * ให้เปลี่ยนเป็นการเปิด Tab ใหม่ เพื่อให้การจัดการมีพื้นที่มากขึ้น โดยส่ง key ไปต่อ /key"). Rendered at
 * `employment-certificate/edit/{key}` via the NORMAL Controller::view() layout (navbar/sidebar/
 * breadcrumb, same as every other page) -- explicit same-day follow-up: "ไม่ต้องแสดงใน modal ครับ ให้
 * เป็น page ปกติได้เลย มี head ปกติเหมือนหน้า Detail ของพนักงาน" corrected an earlier "bare" (no-nav)
 * version of this page back to the standard app shell. `.ect-editor-topbar` (inside
 * _editor_content.php) is this page's own contextual header -- same precedent as Employee Detail's
 * own `#employeeProfileHeader` card standing in for a generic `.page-header-card`, not a sign the
 * "apply .page-header-card to every page" convention was dropped elsewhere.
 *
 * `$pair` (the EmploymentCertificateTemplateModel::getPairByKey() row, or null for an unknown/stale
 * key) is handed in by EmploymentCertificateTemplateController::editPage().
 */
?>
<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-parent" data-i18n="employment_certificate_menu">Employment Certificate</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current"><?=htmlspecialchars($pair['template_name'] ?? 'Edit', ENT_QUOTES, 'UTF-8')?></span>
    </h5>
  </nav>

  <?php if ($pair === null): ?>
  <div class="card-surface p-5 text-center text-secondary">
    <i class="fa-solid fa-triangle-exclamation fa-2x mb-3 d-block"></i>
    <p class="mb-3" data-i18n="ect_pair_not_found">This template could not be found. It may have been deleted.</p>
    <a href="<?=BASE_URL?>/payslip-documents/settings" class="btn btn-outline-secondary btn-sm">
      <i class="fa-solid fa-arrow-left me-1"></i><span data-i18n="ect_back_to_list">Back to Templates</span>
    </a>
  </div>
  <?php else: ?>
  <script>
    const ECT_PAIR_ROW = <?=json_encode($pair, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
    const ECT_LIST_URL = "<?=BASE_URL?>/payslip-documents/settings";
  </script>
  <?php include __DIR__ . '/_editor_content.php'; ?>
  <?php endif; ?>
</div>

<?php if ($pair !== null): ?>
<?php include __DIR__ . '/_modals_partial.php'; ?>
<?php endif; ?>
<!-- 2026-09-04, Backlog Phase 11, T064 -- shared canvas-designer utilities, must load first (see
     that file's own docblock). This page loads employment-certificate-template.js standalone
     (outside app/views/payslip/settings.php), so it needs its own copy of this script tag too. -->
<script src="<?=asset('public/js/setup/canvas-designer-core.js')?>"></script>
<script src="<?=asset('public/js/setup/employment-certificate-template.js')?>"></script>
