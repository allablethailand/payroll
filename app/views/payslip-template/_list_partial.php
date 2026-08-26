<?php
/**
 * Payslip Template list (2026-08-25, explicit request: "ปรับให้การตั้งค่า Slip เงินเดือน Template
 * เป็นเหมือนกับใบรับรอง" -- confirmed via AskUserQuestion: a FULL canvas designer, matching
 * Employment Certificate Template's own List+row-actions+standalone-editor-page format exactly, not
 * just similar page chrome). Client-side DataTable (few templates per company, same tier as
 * Employment Certificate Template's own list) -- included from settings.php's "Payslip Template" tab.
 *
 * 2026-08-25, same-day follow-up ("การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และรูปแบบการทำเหมือนกัน") --
 * the original decision above to have NO pair/TH-EN concept was reversed. This table is now the exact
 * same unified pair-list shape as Employment Certificate Template's own `_list_partial.php` (one row
 * per pair_key, Thai/English readiness columns, a single shared Actions group with dropdown Preview/
 * Delete) -- see that file's own docblock for the full history/reasoning this ports verbatim.
 * `status` (active/inactive) is Payslip-only (Employment Certificate has no such toggle) so its own
 * column stays, and `is_default` genuinely means something here (unlike Employment Certificate's own
 * list, which dropped its star as redundant) but is now set from inside the editor's Template Info
 * card only -- same "no list-level star" simplification Employment Certificate Template's own v10
 * settled on, just reached for a different reason (per-language default, not "no concept at all").
 */
?>
<table class="table table-hover align-middle w-100" id="tb_pst_template">
  <thead class="table-light text-secondary">
    <tr>
      <th data-i18n="template_name">Template Name</th>
      <th data-i18n="ect_page_size">Page Size</th>
      <th class="text-center" data-i18n="template_language_th">Thai</th>
      <th class="text-center" data-i18n="template_language_en">English</th>
      <th data-i18n="ect_last_updated">Last Updated</th>
      <th class="text-center" data-i18n="actions">Actions</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>
