<div class="container container-body">
  <?php
  $title = 'Employment Certificate Template';
  $title_i18n = 'employment_certificate_template';
  $breadcrumb = [
      ['label' => 'Payroll', 'href' => BASE_URL . '/dashboard', 'i18n' => 'payroll'],
      ['label' => 'Employment Certificate', 'href' => null, 'i18n' => 'employment_certificate_menu'],
      ['label' => 'Settings', 'href' => null, 'i18n' => 'settings'],
  ];
  $description = 'Design the certificate layout freely — drag to position, resize, and place company/employee data. The system replaces each data box automatically when a certificate is issued.';
  $description_i18n = 'employment_certificate_template_description';
  include __DIR__ . '/../partials/page-header.php';
  ?>

  <?php include __DIR__ . '/_list_partial.php'; ?>
</div>

<?php include __DIR__ . '/_modals_partial.php'; ?>
<!-- 2026-09-04, Backlog Phase 11, T064 -- shared canvas-designer utilities, must load first (see
     that file's own docblock). This page loads employment-certificate-template.js standalone
     (outside app/views/payslip/settings.php), so it needs its own copy of this script tag too. -->
<script src="<?=asset('public/js/setup/canvas-designer-core.js')?>"></script>
<script src="<?=asset('public/js/setup/employment-certificate-template.js')?>"></script>
