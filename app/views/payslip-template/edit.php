<?php
/**
 * Payslip Template editor -- standalone page (2026-08-25, explicit request: "ปรับให้การตั้งค่า Slip
 * เงินเดือน Template เป็นเหมือนกับใบรับรอง"), ported from Employment Certificate Template's own
 * `edit.php`. Rendered at `payslip-template/edit/{key}` via the NORMAL Controller::view() layout
 * (navbar/sidebar/breadcrumb, same as every other page in this app).
 *
 * 2026-08-25, same-day follow-up ("การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และรูปแบบการทำเหมือนกัน") --
 * `{id}` (a plain `payslip_templates.id`) was replaced with `{key}` (a `pair_key`), reversing the
 * original "no pair/TH-EN-fork concept" decision. `$pair` (PayslipTemplateModel::getPairByKey()'s
 * row, or null for an unknown/stale key) is handed in by PayslipTemplateController::editPage() --
 * same shape/naming as EmploymentCertificateTemplateController's own `$pair`.
 */
?>
<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-parent" data-i18n="payslip_menu">Payslip</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current"><?=htmlspecialchars($pair['template_name'] ?? 'Edit', ENT_QUOTES, 'UTF-8')?></span>
    </h5>
  </nav>

  <?php if ($pair === null): ?>
  <div class="card-surface p-5 text-center text-secondary">
    <i class="fa-solid fa-triangle-exclamation fa-2x mb-3 d-block"></i>
    <p class="mb-3" data-i18n="pst_template_not_found">This template could not be found. It may have been deleted.</p>
    <a href="<?=BASE_URL?>/payslip-documents/settings" class="btn btn-outline-secondary btn-sm">
      <i class="fa-solid fa-arrow-left me-1"></i><span data-i18n="ect_back_to_list">Back to Templates</span>
    </a>
  </div>
  <?php else: ?>
  <script>
    const PST_PAIR_ROW = <?=json_encode($pair, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
    const PST_LIST_URL = "<?=BASE_URL?>/payslip-documents/settings";
  </script>
  <?php include __DIR__ . '/_editor_content.php'; ?>
  <?php endif; ?>
</div>

<?php if ($pair !== null): ?>
<?php include __DIR__ . '/_modals_partial.php'; ?>
<?php endif; ?>
<script src="<?=asset('public/js/setup/payslip-template.js')?>"></script>
