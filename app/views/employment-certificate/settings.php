<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-parent" data-i18n="employment_certificate_menu">Employment Certificate</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current" data-i18n="settings">Settings</span>
    </h5>
  </nav>
  <div class="page-header-card mb-4">
    <div class="page-header-card-icon"><i class="fa-solid fa-file-shield"></i></div>
    <div class="page-header-card-body">
      <h5 class="page-header-card-title" data-i18n="employment_certificate_template">Employment Certificate Template</h5>
      <p class="page-header-card-desc" data-i18n="employment_certificate_template_description">Design the certificate layout freely — drag to position, resize, and place company/employee data. The system replaces each data box automatically when a certificate is issued.</p>
    </div>
  </div>

  <?php include __DIR__ . '/_list_partial.php'; ?>
</div>

<?php include __DIR__ . '/_modals_partial.php'; ?>
<!-- 2026-09-04, Backlog Phase 11, T064 -- shared canvas-designer utilities, must load first (see
     that file's own docblock). This page loads employment-certificate-template.js standalone
     (outside app/views/payslip/settings.php), so it needs its own copy of this script tag too. -->
<script src="<?=asset('public/js/setup/canvas-designer-core.js')?>"></script>
<script src="<?=asset('public/js/setup/employment-certificate-template.js')?>"></script>
