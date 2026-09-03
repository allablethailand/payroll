<?php
/**
 * Payslip Template modals -- ported from Employment Certificate Template's own `_modals_partial.php`
 * (see that file's docblock for the full history). Four genuinely-modal, small, transient dialogs
 * that stack on top of either the list page or the standalone editor page:
 *  - #pstNewTemplateModal: language + name + page size/orientation + starter preset gallery. Same
 *    `modalMode` ('create' / 'create-in-pair' / 'replace') scheme as Employment Certificate
 *    Template's own version, added 2026-08-25 same-day follow-up ("การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของ
 *    เอกสาร และรูปแบบการทำเหมือนกัน" -- Payslip Template gained a language/pair_key fork just like
 *    Employment Certificate Template has, reversing the original "no per-language row" decision).
 *  - #pstTextModal / #pstImageLibraryModal / #pstTableInsertModal: opened from within the editor
 *    page's canvas.
 */
?>
<div class="modal fade" id="pstNewTemplateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-secondary" id="pstNewTemplateModalTitle" data-i18n="ect_new_template">New Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" data-i18n="ect_choose_preset">Start from</label>
        <div class="text-secondary small mb-2" data-i18n="ect_preset_preview_hint">Click a card to choose it, or the eye icon to see the real PDF layout first.</div>
        <div id="pstPresetList" class="pst-preset-gallery"></div>
        <hr class="my-3">
        <!-- Locked+disabled with an explanatory hint (#pstNewTemplatePairHint) when opened from a
             pair's empty-state "start from a preset" action for its missing language --
             #pstNewTemplatePairKey then carries that pair's pair_key so the new row joins it instead
             of starting a fresh, unlinked pair. `modalMode==='replace'` (Change Layout) hides this
             whole `.row.g-3` block entirely -- see #pstCreateTemplateBtn's handler in the JS. -->
        <input type="hidden" id="pstNewTemplatePairKey" value="">
        <div id="pstNewTemplatePairHint" class="alert alert-light border small text-secondary mb-3 d-none"></div>
        <div class="row g-3">
          <div class="col-md-2">
            <label class="form-label" data-i18n="template_language">Language</label>
            <select class="form-select" id="pstNewTemplateLanguageSelect">
              <option value="th" data-i18n="template_language_th">Thai</option>
              <option value="en" data-i18n="template_language_en">English</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label"><span data-i18n="template_name">Template Name</span> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="pstNewTemplateNameInput" maxlength="150" data-i18n="template_name_placeholder" placeholder="e.g., Standard Employment Certificate">
          </div>
          <div class="col-md-2">
            <label class="form-label" data-i18n="ect_page_size">Page Size</label>
            <!-- 2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" --
                 same paper-size set as the editor's own #pstPageSizeSelect. -->
            <select class="form-select" id="pstNewPageSizeSelect">
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
            <label class="form-label" data-i18n="ect_orientation">Orientation</label>
            <select class="form-select" id="pstNewOrientationSelect">
              <option value="portrait" data-i18n="ect_portrait">Portrait</option>
              <option value="landscape" data-i18n="ect_landscape">Landscape</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="pstCreateTemplateBtn"><span id="pstCreateTemplateBtnLabel" data-i18n="create">Create</span></button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="pstTextModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-secondary" data-i18n="ect_edit_text">Edit Text</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <textarea id="pstTextModalInput" class="form-control" rows="5" data-i18n="canvas_text_placeholder" placeholder="e.g., Employee Name, or a full sentence with {{field_key}} tokens embedded"></textarea>
        <div class="text-secondary small mt-2" data-i18n="ect_token_hint">Tip: use {{field_key}} to merge data anywhere in the text, e.g. "This certifies that {{employee_name}} holds the position of {{position}}."</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="pstTextModalApplyBtn" data-i18n="apply">Apply</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="pstImageLibraryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-secondary" data-i18n="ect_image_library">Image Library</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-secondary small mb-3" data-i18n="ect_image_library_hint">Click one or more images to select them, then Insert Selected. Uploaded images can be reused across every template.</div>
        <div id="pstImageLibraryGrid" class="pst-image-grid"></div>
        <div class="text-center text-secondary small py-4 d-none" id="pstImageLibraryEmpty" data-i18n="ect_image_library_empty">No images uploaded yet.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="pstImageLibraryInsertBtn" disabled>
          <i class="fa-solid fa-plus me-1"></i><span data-i18n="ect_insert_selected">Insert Selected</span> (<span id="pstImageLibrarySelectedCount">0</span>)
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="pstTableInsertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-secondary" data-i18n="ect_table">Table</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_rows">Rows</label>
            <input type="number" class="form-control form-control-sm" id="pstTableRowsInput" min="1" max="20" value="3" style="width:80px;" data-i18n="table_rows_placeholder" placeholder="e.g., 3">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_cols">Columns</label>
            <input type="number" class="form-control form-control-sm" id="pstTableColsInput" min="1" max="10" value="3" style="width:80px;" data-i18n="table_cols_placeholder" placeholder="e.g., 3">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_border_color">Border Color</label>
            <input type="color" class="form-control form-control-sm form-control-color" id="pstTableBorderColorInput" value="#000000">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-1" data-i18n="ect_table_border_width">Border Width (px)</label>
            <input type="number" class="form-control form-control-sm" id="pstTableBorderWidthInput" min="0" max="10" value="1" style="width:80px;" data-i18n="border_width_placeholder" placeholder="e.g., 1">
          </div>
        </div>
        <div class="text-secondary small mb-2" data-i18n="ect_table_cells_hint">Enter text for each cell (optional).</div>
        <div id="pstTableCellsGrid" class="pst-table-cells-grid"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="pstTableInsertConfirmBtn" data-i18n="insert">Insert</button>
      </div>
    </div>
  </div>
</div>
