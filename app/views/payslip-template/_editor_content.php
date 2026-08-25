<?php
/**
 * Payslip Template EDITOR content -- standalone page body (2026-08-25, explicit request: "ปรับให้
 * การตั้งค่า Slip เงินเดือน Template เป็นเหมือนกับใบรับรอง"), ported from Employment Certificate
 * Template's own `_editor_content.php` (see that file's own docblock for the ribbon/canvas/layers
 * history this inherits unchanged -- pan/zoom, undo/redo, group/ungroup, grid, Insert Table/Shape/
 * Symbol, multi-page, the Symbol-dropdown CSS fix, etc.). Two real differences from the source:
 *
 *  1. NO language tabs / no empty-state -- Payslip Template stays ONE shared canvas per template
 *     (language_mode governs which language(s) the rendered PDF's DATA tokens resolve in, not a
 *     fork into two independent designs -- see PayslipTemplateModel's own docblock). The canvas
 *     toolbar strip therefore only ever shows Grid/Undo-Redo/Watermark/Zoom, no `.pst-lang-tabs`.
 *  2. A "Template Info" strip (language_mode + header/footer text) -- fields the old field-list
 *     editor already had that Employment Certificate Template has no equivalent of at all.
 */
?>
<div class="pst-editor-topbar">
  <div class="pst-editor-title-group">
    <i class="fa-solid fa-file-invoice pst-editor-title-icon"></i>
    <input type="text" class="pst-editor-title-input" id="pstTemplateNameInput" placeholder="Template Name">
  </div>
  <div class="pst-editor-topbar-fields">
    <div class="pst-page-setup-cluster">
      <span class="pst-page-setup-label" data-i18n="ect_page_setup">Page Setup</span>
      <select class="form-select form-select-sm" id="pstPageSizeSelect">
        <option value="A4">A4</option>
        <option value="Letter">Letter</option>
        <option value="Legal">Legal</option>
      </select>
      <select class="form-select form-select-sm" id="pstOrientationSelect">
        <option value="portrait" data-i18n="ect_portrait">Portrait</option>
        <option value="landscape" data-i18n="ect_landscape">Landscape</option>
      </select>
      <div class="dropdown pst-margin-dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="pstMarginDropdownBtn">
          <i class="fa-solid fa-ruler-combined me-1"></i><span id="pstMarginDropdownLabel">Normal</span>
        </button>
        <ul class="dropdown-menu pst-margin-menu" id="pstMarginMenu">
          <li><a class="dropdown-item pst-margin-option" href="#" data-value="8"><span class="pst-margin-preview pst-margin-preview-narrow"></span><span class="pst-margin-option-text"><strong data-i18n="ect_margin_narrow">Narrow</strong><small>8 mm</small></span></a></li>
          <li><a class="dropdown-item pst-margin-option" href="#" data-value="15"><span class="pst-margin-preview pst-margin-preview-normal"></span><span class="pst-margin-option-text"><strong data-i18n="ect_margin_normal">Normal</strong><small>15 mm</small></span></a></li>
          <li><a class="dropdown-item pst-margin-option" href="#" data-value="20"><span class="pst-margin-preview pst-margin-preview-moderate"></span><span class="pst-margin-option-text"><strong data-i18n="ect_margin_moderate">Moderate</strong><small>20 mm</small></span></a></li>
          <li><a class="dropdown-item pst-margin-option" href="#" data-value="30"><span class="pst-margin-preview pst-margin-preview-wide"></span><span class="pst-margin-option-text"><strong data-i18n="ect_margin_wide">Wide</strong><small>30 mm</small></span></a></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <div class="px-3 py-1 d-flex align-items-center gap-2" onclick="event.stopPropagation();">
              <span class="small text-secondary" data-i18n="ect_margin_custom">Custom</span>
              <input type="number" id="pstMarginInput" class="form-control form-control-sm" style="width:70px;" min="0" max="50" step="1">
              <span class="small text-secondary">mm</span>
            </div>
          </li>
        </ul>
      </div>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm" id="pstChangePresetBtn">
      <i class="fa-solid fa-shuffle me-1"></i><span data-i18n="ect_change_preset">Change Layout</span>
    </button>
  </div>
</div>

<!-- Template Info -- language_mode/header-footer text: fields the old field-list editor already had
     that Employment Certificate Template's designer has no equivalent of (it has no company-wide
     "default template" or header/footer text concept at all). Compact single-row strip so it doesn't
     compete with the ribbon+canvas for vertical space. -->
<div class="pst-info-strip card-surface p-2 px-3 mb-3">
  <div class="pst-info-field">
    <label class="pst-info-label" data-i18n="language">Language</label>
    <select class="form-select form-select-sm" id="pstLanguageModeSelect">
      <option value="th" data-i18n="language_th">Thai</option>
      <option value="en" data-i18n="language_en">English</option>
      <option value="both" data-i18n="language_both">Both</option>
    </select>
  </div>
  <div class="pst-info-field pst-info-field-grow">
    <label class="pst-info-label" data-i18n="header_text">Header Text</label>
    <input type="text" class="form-control form-control-sm mb-1" id="pstHeaderThInput" data-i18n="header_text_th_placeholder" placeholder="ข้อความหัวกระดาษ (ไทย)" maxlength="500">
    <input type="text" class="form-control form-control-sm" id="pstHeaderEnInput" placeholder="Header text (English)" maxlength="500">
  </div>
  <div class="pst-info-field pst-info-field-grow">
    <label class="pst-info-label" data-i18n="footer_text">Footer Text</label>
    <input type="text" class="form-control form-control-sm mb-1" id="pstFooterThInput" data-i18n="footer_text_th_placeholder" placeholder="ข้อความท้ายกระดาษ (ไทย)" maxlength="500">
    <input type="text" class="form-control form-control-sm" id="pstFooterEnInput" placeholder="Footer text (English)" maxlength="500">
  </div>
  <div class="pst-info-field pst-info-field-switch">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="pstStatusSwitch" checked>
      <label class="form-check-label small" for="pstStatusSwitch" data-i18n="enable_this_template">Enable this template</label>
    </div>
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="pstIsDefaultSwitch">
      <label class="form-check-label small" for="pstIsDefaultSwitch" data-i18n="set_as_default_template">Set as default template</label>
    </div>
  </div>
</div>

<div id="pstEditorArea">
  <div class="pst-ribbon card-surface mb-3" id="pstRibbon">
    <div class="pst-ribbon-group">
      <label class="pst-ribbon-label" data-i18n="ect_font_size">Font Size</label>
      <input type="number" id="pstPropFontSize" class="form-control form-control-sm" min="6" max="96" disabled>
    </div>
    <div class="pst-ribbon-group">
      <label class="pst-ribbon-label" data-i18n="ect_font_family">Font</label>
      <select class="form-select form-select-sm" id="pstPropFontFamily" disabled>
        <option value="th_sarabun_new">TH Sarabun New</option>
        <option value="dejavu_sans">DejaVu Sans</option>
        <option value="dejavu_sans_mono">DejaVu Sans Mono</option>
        <option value="dejavu_serif">DejaVu Serif</option>
        <option value="helvetica">Helvetica</option>
        <option value="times_new_roman">Times New Roman</option>
        <option value="courier">Courier</option>
      </select>
    </div>
    <div class="pst-ribbon-group">
      <label class="pst-ribbon-label" data-i18n="ect_font_color">Color</label>
      <input type="color" id="pstPropFontColor" class="form-control form-control-sm form-control-color" disabled>
    </div>
    <div class="pst-ribbon-sep"></div>
    <div class="pst-ribbon-group">
      <div class="dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="pstInsertImageBtn">
          <i class="fa-solid fa-image me-1"></i><span data-i18n="ect_image">Image</span>
        </button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" href="#" id="pstImageUploadNewItem"><i class="fa-solid fa-upload me-2"></i><span data-i18n="ect_upload_new_image">Upload New</span></a></li>
          <li><a class="dropdown-item" href="#" id="pstImageChooseExistingItem"><i class="fa-solid fa-images me-2"></i><span data-i18n="ect_choose_existing_image">Choose Existing</span></a></li>
        </ul>
      </div>
      <input type="file" id="pstRibbonImageUploadInput" accept=".jpg,.jpeg,.png,.svg" class="d-none" multiple>
    </div>
    <div class="pst-ribbon-group">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="pstInsertTableBtn" title="Insert Table">
        <i class="fa-solid fa-table me-1"></i><span data-i18n="ect_table">Table</span>
      </button>
    </div>
    <div class="pst-ribbon-group">
      <div class="dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="pstInsertSymbolBtn">
          <i class="fa-solid fa-icons me-1"></i><span data-i18n="ect_symbol">Symbol</span>
        </button>
        <div class="dropdown-menu pst-symbol-menu p-2" id="pstSymbolMenu"></div>
      </div>
    </div>
    <div class="pst-ribbon-group">
      <div class="dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="pstInsertShapeBtn">
          <i class="fa-solid fa-shapes me-1"></i><span data-i18n="ect_shape">Shape</span>
        </button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item pst-insert-shape-item" href="#" data-shape="rectangle"><i class="fa-regular fa-square me-2"></i><span data-i18n="ect_shape_rectangle">Rectangle</span></a></li>
          <li><a class="dropdown-item pst-insert-shape-item" href="#" data-shape="ellipse"><i class="fa-regular fa-circle me-2"></i><span data-i18n="ect_shape_ellipse">Ellipse</span></a></li>
          <li><a class="dropdown-item pst-insert-shape-item" href="#" data-shape="line"><i class="fa-solid fa-minus me-2"></i><span data-i18n="ect_shape_line">Line</span></a></li>
        </ul>
      </div>
    </div>
    <div class="pst-ribbon-sep"></div>
    <div class="pst-ribbon-group">
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary pst-style-btn" data-style="bold" title="Bold" disabled><i class="fa-solid fa-bold"></i></button>
        <button type="button" class="btn btn-outline-secondary pst-style-btn" data-style="italic" title="Italic" disabled><i class="fa-solid fa-italic"></i></button>
        <button type="button" class="btn btn-outline-secondary pst-style-btn" data-style="underline" title="Underline" disabled><i class="fa-solid fa-underline"></i></button>
      </div>
    </div>
    <div class="pst-ribbon-sep"></div>
    <div class="pst-ribbon-group">
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary pst-align-btn" data-align="left" disabled><i class="fa-solid fa-align-left"></i></button>
        <button type="button" class="btn btn-outline-secondary pst-align-btn" data-align="center" disabled><i class="fa-solid fa-align-center"></i></button>
        <button type="button" class="btn btn-outline-secondary pst-align-btn" data-align="right" disabled><i class="fa-solid fa-align-right"></i></button>
      </div>
    </div>
    <div class="pst-ribbon-sep"></div>
    <div class="pst-ribbon-group">
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary" id="pstGroupBtn" title="Group (Ctrl+G)" disabled><i class="fa-solid fa-object-group"></i></button>
        <button type="button" class="btn btn-outline-secondary" id="pstUngroupBtn" title="Ungroup (Ctrl+Shift+G)" disabled><i class="fa-solid fa-object-ungroup"></i></button>
      </div>
    </div>
    <div class="pst-ribbon-group">
      <button type="button" class="btn btn-outline-danger btn-sm" id="pstDeleteElementBtn" disabled>
        <i class="fa-solid fa-trash me-1"></i><span data-i18n="delete">Delete</span>
      </button>
    </div>
    <div class="pst-ribbon-hint text-secondary small ms-auto" id="pstRibbonHint" data-i18n="ect_ribbon_select_hint">Select an element on the canvas to format it.</div>
  </div>
  <div class="text-secondary pst-multiselect-hint mb-2">
    <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="ect_multiselect_howto">Tip: Ctrl+Click to select multiple elements — drag to move them together, or use Group/Ungroup and Delete above. Click a layer on the right to select it too.</span>
  </div>

  <div class="pst-designer">
    <div class="pst-palette card-surface p-3">
      <h6 class="fw-bold mb-2" data-i18n="ect_add_element">Add to Canvas</h6>
      <div class="text-secondary small mb-2" data-i18n="ect_add_element_hint">Drag a field onto the canvas, or click to drop it at the top — then drag/resize freely.</div>
      <div id="pstFieldPalette" class="pst-palette-list"></div>
    </div>

    <div class="pst-canvas-wrap card-surface p-3">
      <div class="pst-canvas-toolbar pst-canvas-toolbar-solo">
        <div class="pst-toolbar-right">
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" id="pstUndoBtn" title="Undo (Ctrl+Z)" disabled><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" class="btn btn-outline-secondary" id="pstRedoBtn" title="Redo (Ctrl+Shift+Z)" disabled><i class="fa-solid fa-rotate-right"></i></button>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm active" id="pstGridToggle" title="Toggle grid">
            <i class="fa-solid fa-table-cells me-1"></i><span data-i18n="ect_toggle_grid">Grid</span>
          </button>
          <div class="pst-zoom-control">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="pstZoomOutBtn" title="Zoom Out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <select class="form-select form-select-sm" id="pstZoomSelect">
              <option value="50">50%</option>
              <option value="75">75%</option>
              <option value="100" selected>100%</option>
              <option value="125">125%</option>
              <option value="150">150%</option>
              <option value="200">200%</option>
            </select>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="pstZoomInBtn" title="Zoom In"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="pstZoomResetBtn" title="Reset to 100%"><i class="fa-solid fa-compress"></i></button>
          </div>
        </div>
      </div>
      <div class="pst-canvas-scroll pst-pannable">
        <div id="pstZoomStage">
          <div class="pst-page pst-grid-on" id="pstPage"></div>
        </div>
      </div>
      <div class="pst-page-nav">
        <button type="button" class="btn btn-link btn-sm" id="pstPagePrevBtn" title="Previous page"><i class="fa-solid fa-chevron-left"></i></button>
        <span class="pst-page-nav-label" id="pstPageNavLabel">Page 1 / 1</span>
        <button type="button" class="btn btn-link btn-sm" id="pstPageNextBtn" title="Next page"><i class="fa-solid fa-chevron-right"></i></button>
        <span class="pst-page-nav-sep"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="pstPageAddBtn" title="Add page"><i class="fa-solid fa-plus me-1"></i><span data-i18n="ect_add_page">Add Page</span></button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="pstPageRemoveBtn" title="Remove this page"><i class="fa-solid fa-trash me-1"></i><span data-i18n="ect_remove_page">Remove Page</span></button>
      </div>
    </div>

    <div class="pst-layers-column">
      <div class="pst-watermark-card card-surface p-3">
        <h6 class="fw-bold mb-2" data-i18n="ect_watermark_title">Watermark</h6>
        <div class="text-secondary small mb-2" data-i18n="ect_watermark_hint">Preview only — never appears in the saved document.</div>
        <div class="form-check form-switch">
          <input type="checkbox" class="form-check-input" id="pstWatermarkToggle">
          <label class="form-check-label small" for="pstWatermarkToggle" data-i18n="ect_watermark_enable">Enable</label>
        </div>
        <input type="text" class="form-control form-control-sm mt-2 d-none" id="pstWatermarkText" placeholder="SAMPLE">
      </div>
      <div class="pst-layers card-surface p-3">
        <h6 class="fw-bold mb-2" data-i18n="ect_layers">Layers</h6>
        <div id="pstLayersList" class="pst-layers-list"></div>
      </div>
    </div>
  </div>
</div>

<div class="pst-editor-footer">
  <div class="text-secondary small me-auto" id="pstSaveHint"></div>
  <button type="button" class="btn btn-outline-secondary pst-footer-btn-lg" id="pstPreviewBtn"><i class="fa-solid fa-eye me-1"></i><span data-i18n="preview">Preview</span></button>
  <button type="button" class="btn btn-primary pst-footer-btn-lg" id="pstSaveBtn"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
</div>
