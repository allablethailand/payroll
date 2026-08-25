<?php
/**
 * Employment Certificate Template list (2026-08-24, List+Modal restructure -- explicit request:
 * "ตัว Template เป็น List ตารางให้กด View แก้ไข Delete และเปิด Modal ในการเพิ่มหรือการจัดการให้เป็น
 * Format เดียวกันทั้งหมด" -- matches Payslip Template's own DataTable+row-actions convention exactly
 * (client-side ajax+dataSrc, since templates per company/language are few -- same tier as Payslip
 * Cycle, not server-side like Employee/PED Types). Same embedding story as the old
 * _designer_partial.php it replaces: no breadcrumb/page-header-card of its own, included from both
 * settings.php (standalone route) and payslip/settings.php (merged tab). See _modals_partial.php
 * for the Add/Edit/Preview/Image-Library modals this table's row actions open.
 *
 * 2026-08-25, explicit follow-up: "ตรงตาราง Template ในขั้นตอนการจัดการ ให้มี th กับ eng ในการจัดการเลย
 * ไม่ต้องแยกเป็น Tab เหมือนเดิม...แล้วในตารางแสดงผลก็ว่า template นี้ th eng พร้อมใช้งานทั้ง 2 ไหม" -- the
 * #ectLanguageTabs pill pair that used to filter this table is GONE. One row now represents a
 * "pair" (`pair_key`, see EmploymentCertificateTemplateModel::listPaired()).
 *
 * 2026-08-25, same-day follow-up ("ในหน้าตารางอาจเป็นแค่สัญลักษณ์ว่าตั้งค่าแล้ว...ในหน้ารายการจะได้มีปุ่ม
 * ดำเนินการแค่ชุดเดียว แต่การลบแค่ยาวภาษาตรงนี้คิดไม่ออกช่วย Design ให้หน่อยครับ") -- simplified further:
 * the Thai/English columns are now JUST a compact ready/not-ready symbol (a clickable default-star
 * when ready) -- the old per-language View/Edit/Duplicate/Delete/Generate-Auto/Create-Manually
 * button groups are GONE from these cells entirely. Every row now has exactly ONE shared Actions
 * group (Preview/Edit/Duplicate/Delete) -- Edit opens the pair in one modal with TH/EN as internal
 * tabs (see _modals_partial.php's #ectLangTabs), where a not-yet-created language shows an inline
 * "create this language" empty state instead of the canvas (Generate Auto / start from preset moved
 * THERE from the list). Preview and Delete are dropdowns listing only the language(s) that actually
 * exist for that pair -- this is the answer to "ลบแค่ภาษาเดียวตรงนี้คิดไม่ออก": "Delete" opens a small
 * menu ("Delete both" + "Delete Thai only" + "Delete English only", the latter two only shown when
 * that language actually exists) instead of needing a second button group per language. Also added
 * a "Last Updated" column since the table "ดูโล่งๆ" (looked sparse) with just 4 columns.
 */
?>
<div class="card-surface p-3 p-md-4">
  <table class="table table-hover align-middle w-100" id="tb_ect_template">
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
</div>
