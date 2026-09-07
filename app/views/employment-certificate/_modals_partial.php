<?php
/**
 * Employment Certificate Template modals. Included by BOTH the list page (settings.php, needs only
 * #ectNewTemplateModal for its own "+ Template" button) and the standalone editor page (edit.php,
 * needs all three -- #ectNewTemplateModal is reused there for "Change Layout"/"start from a preset",
 * plus #ectTextModal/#ectImageLibraryModal for the canvas). Harmless to include on both regardless of
 * which buttons on that particular page actually open them.
 *
 * 2026-08-25, explicit request: "หน้าแก้ไขให้เปลี่ยนเป็นการเปิด Tab ใหม่ เพื่อให้การจัดการมีพื้นที่มากขึ้น" --
 * the editor (formerly #ectEditModal, a fullscreen Bootstrap modal) is now its own standalone page
 * (see employment-certificate/edit.php + _editor_content.php) so it gets the whole browser tab
 * instead of a modal's viewport. Three modals remain here, all still genuinely modal (small,
 * transient, stack on top of whichever page opened them):
 *  - #ectNewTemplateModal: name + page size/orientation + starter preset gallery, opened from the
 *    list's "+ Template" (mode='create'), or from the editor page's empty-state "start from a
 *    preset" (mode='create-in-pair') / "Change Layout" button (mode='replace').
 *  - #ectTextModal / #ectImageLibraryModal: opened from within the editor page's canvas.
 */
?>

<!-- Add (New Template: starter-template gallery + name/page-size/orientation, doubles as "pull in a
     system template" -- explicit follow-up: "ตอนกด New Template ให้ขึ้นเป็นตัวอย่าง Template คล้ายๆกับ
     Google Doc แล้วกดเลือกว่าจะเพิ่มจาก Template หรือเอกสารเปล่า" -- redesigned from the old plain icon+
     label list into a wider card gallery with a small CSS-drawn mockup per template (PRESET_MOCKUPS
     in the JS -- there's no PDF-to-image conversion available in this environment to generate real
     thumbnails, see storage/fonts/thsarabun/NOTICE.md's own note on the same limitation, so these are
     a representative sketch of each layout's actual element positions, not a pixel-perfect render;
     the eye-icon Preview button still opens the REAL rendered PDF for an exact look). "Blank
     document" is simply the first card in the same gallery, same as Google Docs' own picker, rather
     than a separate control. -->
<div class="modal fade" id="ectNewTemplateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-secondary" id="ectNewTemplateModalTitle" data-i18n="ect_new_template">New Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label mb-1" data-i18n="ect_choose_preset">Start from</label>
        <div class="text-secondary small mb-2" data-i18n="ect_preset_preview_hint">Click a card to choose it, or the eye icon to see the real PDF layout first.</div>
        <div id="ectPresetList" class="ect-preset-gallery"></div>
        <hr class="my-3">
        <!-- 2026-08-25, TH/EN unification follow-up: this modal no longer sits behind an outer
             #ectLanguageTabs pill (that filter is gone, see _list_partial.php), so it needs its own
             language choice. Locked+disabled with an explanatory hint (#ectNewTemplatePairHint) when
             opened from a pair's empty-state "start from a preset" action for its missing language --
             #ectNewTemplatePairKey then carries that pair's pair_key so the new row joins it instead
             of starting a fresh, unlinked pair (see EmploymentCertificateTemplateModel::save()).
             2026-08-25, same-day follow-up: this same modal ALSO reuses its gallery for
             #ectChangePresetBtn's "Change Layout" (re-apply a different preset to what's already
             open, destructive, resolved via AskUserQuestion) -- `modalMode==='replace'` hides this
             whole `.row.g-3` block entirely (name/page-size/orientation are irrelevant when nothing
             new is being created), see openNewTemplateModal()/#ectCreateTemplateBtn in the JS. -->
        <input type="hidden" id="ectNewTemplatePairKey" value="">
        <div id="ectNewTemplatePairHint" class="alert alert-light border small text-secondary mb-3 d-none"></div>
        <div class="row g-3">
          <div class="col-md-2">
            <label class="form-label mb-1" data-i18n="template_language">Language</label>
            <select class="form-select" id="ectNewTemplateLanguageSelect">
              <option value="th" data-i18n="template_language_th">Thai</option>
              <option value="en" data-i18n="template_language_en">English</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label mb-1"><span data-i18n="template_name">Template Name</span> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="ectNewTemplateNameInput" maxlength="150" data-i18n="template_name_placeholder" placeholder="e.g., Standard Employment Certificate">
          </div>
          <div class="col-md-2">
            <label class="form-label mb-1" data-i18n="ect_page_size">Page Size</label>
            <!-- 2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" --
                 same paper-size set as the editor's own #ectPageSizeSelect. -->
            <select class="form-select" id="ectNewPageSizeSelect">
              <option value="A3">A3</option>
              <option value="A4">A4</option>
              <option value="A5">A5</option>
              <option value="B4">B4</option>
              <option value="B5">B5</option>
              <option value="Letter">Letter</option>
              <option value="Legal">Legal</option>
              <option value="Tabloid">Tabloid</option>
              <option value="Executive">Executive</option>
              <option value="Statement">Statement</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label mb-1" data-i18n="ect_orientation">Orientation</label>
            <select class="form-select" id="ectNewOrientationSelect">
              <option value="portrait" data-i18n="ect_portrait">Portrait</option>
              <option value="landscape" data-i18n="ect_landscape">Landscape</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="ectCreateTemplateBtn"><span id="ectCreateTemplateBtnLabel" data-i18n="create">Create</span></button>
      </div>
    </div>
  </div>
</div>

<!-- Free-text element content editor (opens on double-click of a text box, stacked on top of the editor page) -->
<div class="modal fade" id="ectTextModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-secondary" data-i18n="ect_edit_text">Edit Text</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <textarea id="ectTextModalInput" class="form-control" rows="5" data-i18n="canvas_text_placeholder" placeholder="e.g., Employee Name, or a full sentence with {{field_key}} tokens embedded"></textarea>
        <div class="text-secondary small mt-2" data-i18n="ect_token_hint">Tip: use {{field_key}} to merge data anywhere in the text, e.g. "This certifies that {{employee_name}} holds the position of {{position}}."</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="ectTextModalApplyBtn" data-i18n="apply">Apply</button>
      </div>
    </div>
  </div>
</div>

<!-- Reusable image library -- "Choose Existing" from the ribbon's Image dropdown (stacked on top of
     the editor page). 2026-08-25, explicit follow-up: "ตรง image libraly ให้ขึ้นเป็น อีกปุ่มตรง ที่จัดการ
     font โดยคลิกแล้วมีให้เลือกว่าจะ upload ใหม่หรือเลือกจากที่มีอยู่แล้ว ถ้าเลือกจากที่มีอยู่แล้วให้ขึ้น modal
     ให้เลือก และเลือก insert ได้ทีละหลายรูป" -- upload now happens via the ribbon's OWN "Upload New"
     item (#ectRibbonImageUploadInput), not from inside this modal any more; this modal is now purely
     a multi-select PICKER -- click a tile to check/uncheck it, "Insert Selected" drops every checked
     image onto the canvas at once (each offset slightly so they don't land exactly on top of each
     other). Delete stays here since "manage the library" and "pick images to insert" are still the
     same screen. -->
<div class="modal fade" id="ectImageLibraryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-secondary" data-i18n="ect_image_library">Image Library</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-secondary small mb-3" data-i18n="ect_image_library_hint">Click one or more images to select them, then Insert Selected. Uploaded images can be reused across every template.</div>
        <div id="ectImageLibraryGrid" class="ect-image-grid"></div>
        <div class="text-center text-secondary small py-4 d-none" id="ectImageLibraryEmpty" data-i18n="ect_image_library_empty">No images uploaded yet.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="ectImageLibraryInsertBtn" disabled>
          <i class="fa-solid fa-plus me-1"></i><span data-i18n="ect_insert_selected">Insert Selected</span> (<span id="ectImageLibrarySelectedCount">0</span>)
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Insert/edit Table (explicit request: "เพิ่ม option การเพิ่มตาราง ที่สามารถกำหนดเส้นสีเส้นขอบได้เหมือน
     word") -- opened either from the ribbon's Table button (new) or double-clicking an existing
     table element on the canvas (edit) -- #ectTableInsertConfirmBtn's handler tells the two apart
     via editingTableKey in the JS. Rows/cols/border color/border width + a plain grid of small text
     inputs for each cell's own text (optional). -->
<div class="modal fade" id="ectTableInsertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-secondary" data-i18n="ect_table">Table</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_rows">Rows</label>
            <input type="number" class="form-control form-control-sm" id="ectTableRowsInput" min="1" max="20" value="3" style="width:80px;" data-i18n="table_rows_placeholder" placeholder="e.g., 3">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_cols">Columns</label>
            <input type="number" class="form-control form-control-sm" id="ectTableColsInput" min="1" max="10" value="3" style="width:80px;" data-i18n="table_cols_placeholder" placeholder="e.g., 3">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_border_color">Border Color</label>
            <input type="color" class="form-control form-control-sm form-control-color" id="ectTableBorderColorInput" value="#000000">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_border_width">Border Width (px)</label>
            <input type="number" class="form-control form-control-sm" id="ectTableBorderWidthInput" min="0" max="10" value="1" style="width:80px;" data-i18n="border_width_placeholder" placeholder="e.g., 1">
          </div>
        </div>
        <div class="text-secondary small mb-2" data-i18n="ect_table_cells_hint">Enter text for each cell (optional).</div>
        <div id="ectTableCellsGrid" class="ect-table-cells-grid"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="ectTableInsertConfirmBtn" data-i18n="insert">Insert</button>
      </div>
    </div>
  </div>
</div>
