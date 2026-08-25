<?php
/**
 * Payslip Template list (2026-08-25, explicit request: "ปรับให้การตั้งค่า Slip เงินเดือน Template
 * เป็นเหมือนกับใบรับรอง" -- confirmed via AskUserQuestion: a FULL canvas designer, matching
 * Employment Certificate Template's own List+row-actions+standalone-editor-page format exactly, not
 * just similar page chrome). Client-side DataTable (few templates per company, same tier as
 * Employment Certificate Template's own list) -- included from settings.php's "Payslip Template" tab.
 *
 * Unlike Employment Certificate Template's list, there is no pair/TH-EN concept here at all (see
 * PayslipTemplateModel's own docblock for why) -- one row per template, plain `id`-addressed Edit
 * link, and `is_default` genuinely means something here (which template PaySlipReport::generate()
 * picks), so the star toggle Employment Certificate Template removed as redundant is KEPT.
 */
?>
<div class="card-surface p-3 p-md-4">
  <table class="table table-hover align-middle w-100" id="tb_pst_template">
    <thead class="table-light text-secondary">
      <tr>
        <th data-i18n="template_name">Template Name</th>
        <th class="text-center" data-i18n="default">Default</th>
        <th data-i18n="language">Language</th>
        <th data-i18n="ect_page_size">Page Size</th>
        <th data-i18n="status">Status</th>
        <th class="text-center" data-i18n="actions">Actions</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</div>
