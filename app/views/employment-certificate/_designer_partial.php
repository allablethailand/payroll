<?php
/**
 * Employment Certificate Template designer body -- no breadcrumb/page-header-card/container of its
 * own, so it can be embedded as a tab-pane inside another page's layout. Included from both:
 *  - app/views/employment-certificate/settings.php (its own standalone route, kept working for any
 *    existing bookmark/deep link)
 *  - app/views/payslip/settings.php (2026-08-24 menu merge, explicit request: "Menu Employment
 *    Ceritficate น่าจะนำไปรวมใน Play Slip แต่เปลี่ยน Menu ส่วนของการตั้งค่าก็เอาไปไว้ด้วยกัน แต่แยก Tab" --
 *    the settings/designer joins Payslip Settings as its own tab; Requests stays separate because
 *    Employment Certificate has no request/issuance flow yet, see CLAUDE.md).
 * The 3 modals at the bottom (#ectTextModal/#ectNewTemplateModal/#ectImageLibraryModal) and the
 * <script> tag for employment-certificate-template.js are NOT included here -- each including page
 * pulls in the script itself exactly once, and modals are appended once per page too (see the
 * bottom of settings.php / payslip/settings.php).
 */
?>
<div class="bg-light rounded-3 p-2 mb-3 structure-tabs-wrap">
  <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" id="ectLanguageTabs">
    <li class="nav-item">
      <button type="button" class="nav-link structure-menu active" data-language="th">
        <i class="fa-solid fa-language me-1"></i><span data-i18n="template_language_th">Thai Layout</span>
      </button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link structure-menu" data-language="en">
        <i class="fa-solid fa-language me-1"></i><span data-i18n="template_language_en">English Layout</span>
      </button>
    </li>
  </ul>
</div>

<!-- Word-style formatting ribbon: always visible, controls disabled until an element is selected -->
<div class="ect-ribbon card-surface mb-3" id="ectRibbon">
  <div class="ect-ribbon-group">
    <label class="ect-ribbon-label" data-i18n="ect_font_size">Font Size</label>
    <input type="number" id="ectPropFontSize" class="form-control form-control-sm" min="6" max="96" disabled>
  </div>
  <div class="ect-ribbon-group">
    <label class="ect-ribbon-label" data-i18n="ect_font_family">Font</label>
    <select class="form-select form-select-sm" id="ectPropFontFamily" disabled>
      <option value="th_sarabun_new">TH Sarabun New</option>
      <option value="dejavu_sans" data-en-only="1">DejaVu Sans</option>
    </select>
  </div>
  <div class="ect-ribbon-group">
    <label class="ect-ribbon-label" data-i18n="ect_font_color">Color</label>
    <input type="color" id="ectPropFontColor" class="form-control form-control-sm form-control-color" disabled>
  </div>
  <div class="ect-ribbon-sep"></div>
  <div class="ect-ribbon-group">
    <div class="btn-group btn-group-sm" role="group">
      <button type="button" class="btn btn-outline-secondary ect-style-btn" data-style="bold" title="Bold" disabled><i class="fa-solid fa-bold"></i></button>
      <button type="button" class="btn btn-outline-secondary ect-style-btn" data-style="italic" title="Italic" disabled><i class="fa-solid fa-italic"></i></button>
      <button type="button" class="btn btn-outline-secondary ect-style-btn" data-style="underline" title="Underline" disabled><i class="fa-solid fa-underline"></i></button>
    </div>
  </div>
  <div class="ect-ribbon-sep"></div>
  <div class="ect-ribbon-group">
    <div class="btn-group btn-group-sm" role="group">
      <button type="button" class="btn btn-outline-secondary ect-align-btn" data-align="left" disabled><i class="fa-solid fa-align-left"></i></button>
      <button type="button" class="btn btn-outline-secondary ect-align-btn" data-align="center" disabled><i class="fa-solid fa-align-center"></i></button>
      <button type="button" class="btn btn-outline-secondary ect-align-btn" data-align="right" disabled><i class="fa-solid fa-align-right"></i></button>
    </div>
  </div>
  <div class="ect-ribbon-sep"></div>
  <div class="ect-ribbon-group">
    <button type="button" class="btn btn-outline-danger btn-sm" id="ectDeleteElementBtn" disabled>
      <i class="fa-solid fa-trash me-1"></i><span data-i18n="delete">Delete</span>
    </button>
  </div>
  <div class="ect-ribbon-hint text-secondary small ms-auto" id="ectRibbonHint" data-i18n="ect_ribbon_select_hint">Select an element on the canvas to format it.</div>
</div>

<div class="ect-designer">
  <!-- Left rail: template picker + field palette + logo -->
  <div class="ect-palette card-surface p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="fw-bold mb-0" data-i18n="ect_templates">Templates</h6>
      <button type="button" class="btn btn-primary btn-sm" id="ectNewTemplateBtn"><i class="fa-solid fa-plus"></i></button>
    </div>
    <select class="form-select form-select-sm mb-2" id="ectTemplateSelect"></select>
    <div class="d-flex gap-1 mb-3">
      <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="ectSetDefaultBtn" title="Set as default"><i class="fa-solid fa-star"></i></button>
      <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="ectDuplicateTemplateBtn" title="Duplicate"><i class="fa-solid fa-copy"></i></button>
      <button type="button" class="btn btn-outline-danger btn-sm flex-fill" id="ectDeleteTemplateBtn" title="Delete"><i class="fa-solid fa-trash"></i></button>
    </div>
    <div class="mb-3">
      <input type="text" class="form-control form-control-sm mb-2" id="ectTemplateNameInput" placeholder="Template Name">
      <div class="row g-2">
        <div class="col-6">
          <label class="form-label small mb-1" data-i18n="ect_page_size">Page Size</label>
          <select class="form-select form-select-sm" id="ectPageSizeSelect">
            <option value="A4">A4</option>
            <option value="Letter">Letter</option>
            <option value="Legal">Legal</option>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label small mb-1" data-i18n="ect_orientation">Orientation</label>
          <select class="form-select form-select-sm" id="ectOrientationSelect">
            <option value="portrait" data-i18n="ect_portrait">Portrait</option>
            <option value="landscape" data-i18n="ect_landscape">Landscape</option>
          </select>
        </div>
      </div>
    </div>

    <hr>
    <h6 class="fw-bold mb-2" data-i18n="ect_add_element">Add to Canvas</h6>
    <div class="text-secondary small mb-2" data-i18n="ect_add_element_hint">Drag a field onto the canvas, or click to drop it at the top — then drag/resize freely.</div>
    <div id="ectFieldPalette" class="ect-palette-list"></div>

    <hr>
    <h6 class="fw-bold mb-2" data-i18n="company_logo">Company Logo</h6>
    <div class="ect-logo-upload">
      <div id="ectLogoPreview" class="ect-logo-preview d-none"><img src="" alt="Logo"></div>
      <label class="btn btn-outline-secondary btn-sm w-100" for="ectLogoInput">
        <i class="fa-solid fa-upload me-1"></i><span data-i18n="upload_logo">Upload Logo</span>
      </label>
      <input type="file" id="ectLogoInput" accept=".jpg,.jpeg,.png,.svg" class="d-none">
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2" id="ectOpenImageLibraryBtn">
      <i class="fa-solid fa-images me-1"></i><span data-i18n="ect_image_library">Image Library</span>
    </button>
  </div>

  <!-- Canvas -->
  <div class="ect-canvas-wrap card-surface p-3">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <div class="text-secondary small" id="ectSaveHint"></div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="form-check form-switch m-0" title="Watermark">
          <input type="checkbox" class="form-check-input" id="ectWatermarkToggle">
          <label class="form-check-label small" for="ectWatermarkToggle" data-i18n="ect_watermark">Watermark</label>
        </div>
        <input type="text" class="form-control form-control-sm d-none" id="ectWatermarkText" style="width:140px;" placeholder="SAMPLE">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ectPreviewBtn"><i class="fa-solid fa-eye me-1"></i><span data-i18n="preview">Preview</span></button>
        <button type="button" class="btn btn-primary btn-sm" id="ectSaveBtn"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
      </div>
    </div>
    <div class="ect-canvas-scroll">
      <div class="ect-page" id="ectPage"></div>
    </div>
  </div>
</div>

<!-- Free-text element content editor (opens on double-click of a text box) -->
<div class="modal fade" id="ectTextModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-secondary" data-i18n="ect_edit_text">Edit Text</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <textarea id="ectTextModalInput" class="form-control" rows="5"></textarea>
        <div class="text-secondary small mt-2" data-i18n="ect_token_hint">Tip: use {{field_key}} to merge data anywhere in the text, e.g. "This certifies that {{employee_name}} holds the position of {{position}}."</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="ectTextModalApplyBtn" data-i18n="apply">Apply</button>
      </div>
    </div>
  </div>
</div>

<!-- New Template (choose a starter preset) -->
<div class="modal fade" id="ectNewTemplateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-secondary" data-i18n="ect_new_template">New Template</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label"><span data-i18n="template_name">Template Name</span> <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="ectNewTemplateNameInput" maxlength="150">
        </div>
        <label class="form-label" data-i18n="ect_choose_preset">Start from</label>
        <div id="ectPresetList" class="ect-preset-list"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="ectCreateTemplateBtn" data-i18n="create">Create</button>
      </div>
    </div>
  </div>
</div>

<!-- Image Library -->
<div class="modal fade" id="ectImageLibraryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold text-secondary" data-i18n="ect_image_library">Image Library</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="btn btn-outline-secondary btn-sm mb-3" for="ectLibraryUploadInput">
          <i class="fa-solid fa-upload me-1"></i><span data-i18n="ect_upload_new_image">Upload New Image</span>
        </label>
        <input type="file" id="ectLibraryUploadInput" accept=".jpg,.jpeg,.png,.svg" class="d-none">
        <div class="text-secondary small mb-3" data-i18n="ect_image_library_hint">Click an image to place it on the canvas. Uploaded images can be reused across every template.</div>
        <div id="ectImageLibraryGrid" class="ect-image-grid"></div>
        <div class="text-center text-secondary small py-4 d-none" id="ectImageLibraryEmpty" data-i18n="ect_image_library_empty">No images uploaded yet.</div>
      </div>
    </div>
  </div>
</div>
