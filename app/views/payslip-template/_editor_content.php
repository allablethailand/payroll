<?php
/**
 * Payslip Template EDITOR content -- standalone page body (2026-08-25, explicit request: "ปรับให้
 * การตั้งค่า Slip เงินเดือน Template เป็นเหมือนกับใบรับรอง"), ported from Employment Certificate
 * Template's own `_editor_content.php` (see that file's own docblock for the ribbon/canvas/layers
 * history this inherits unchanged -- pan/zoom, undo/redo, group/ungroup, grid, Insert Table/Shape/
 * Symbol, multi-page, the Symbol-dropdown CSS fix, etc.).
 *
 * 2026-08-25, same-day follow-up ("การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และรูปแบบการทำเหมือนกัน") --
 * the original decision below to have NO language tabs/empty-state was reversed: `#pstLangTabs`/
 * `#pstLangEmptyState` are now direct ports of Employment Certificate Template's own `#ectLangTabs`/
 * `#ectLangEmptyState` (see that file's own comments for the pairState/switchToLangTab() mechanism
 * this shares). The ONE real remaining difference from Employment Certificate Template: a "Template
 * Info" strip (header/footer text + status/is_default toggles) -- fields the old field-list editor
 * already had that Employment Certificate Template's designer has no equivalent of at all. The
 * `language` select that used to live there is GONE -- which language a canvas belongs to is now
 * fixed at creation (via the language tabs / New Template modal), the same as Employment Certificate
 * Template's own `language` column, not an editable field inside the template body.
 */
?>
<div class="pst-editor-topbar">
  <div class="pst-editor-title-group">
    <i class="fa-solid fa-file-invoice pst-editor-title-icon"></i>
    <input type="text" class="pst-editor-title-input" id="pstTemplateNameInput" placeholder="Template Name">
  </div>
</div>

<!-- 2026-08-25, explicit follow-up: "อยากให้เพิ่ม Tab ในหน้า Detail ของ Slip และเอกสารแต่ละตัว" -- the
     Design canvas and the Assign-To picker are now two top-level tabs (same `.setup-tabs`/
     `.setup-menu` convention as every other top-level page tab in this app, e.g. Payslip Settings'
     own tab strip) instead of the assignment picker being buried as one more row inside the
     Template Info card. "Design" stays the DEFAULT active tab deliberately -- the canvas's own zoom/
     sizing math reads live `offsetWidth`/`offsetHeight` once at template-load time, which returns 0
     for a `display:none` (inactive tab) element, so Assign can never be the tab that's active when
     switchToLangTab() first runs. Save/Preview stay OUTSIDE both tab panes (always visible)
     since one Save persists the canvas AND the assignment checkboxes together in a single request,
     regardless of which tab happens to be showing. -->
<!-- Wrapped in one container so switchToLangTab()'s empty-state branch can hide the whole tab
     strip+content with a single toggle, same as Employment Certificate Template's own #ectMainTabsWrap. -->
<div id="pstMainTabsWrap">
<ul class="nav nav-tabs setup-tabs mb-3" id="pstEditorTabs" role="tablist">
  <li class="nav-item"><button class="nav-link setup-menu active" data-bs-toggle="tab" data-bs-target="#pstTabDesign" type="button" role="tab"><i class="fa-solid fa-pen-ruler me-1"></i><span data-i18n="ect_design_tab">Design</span></button></li>
  <li class="nav-item"><button class="nav-link setup-menu" data-bs-toggle="tab" data-bs-target="#pstTabAssign" type="button" role="tab"><i class="fa-solid fa-users-rectangle me-1"></i><span data-i18n="pst_assign_to">Assign To</span></button></li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="pstTabDesign">

<!-- 2026-08-25, follow-up bug report: "Page Setup ไม่อยู่ภายใต้ tab design" -- Page Setup (page size/
     orientation/margin) and Change Layout used to sit in the shared topbar ABOVE both tabs, so they
     stayed visible even while looking at Assign To, where they mean nothing. Moved inside the Design
     tab pane itself, same fix applied to Employment Certificate Template's own editor. -->
<div class="pst-editor-topbar-fields justify-content-end mb-3">
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

<!-- Template Info -- header/footer text + status/is_default: fields the old field-list editor
     already had that Employment Certificate Template's designer has no equivalent of (it has no
     company-wide "default template" or header/footer text concept at all). The `language` select
     that used to live here is GONE (2026-08-25 follow-up) -- language is fixed per canvas now, set
     via the language tabs / New Template modal, not editable inside an existing template's body. -->
<div class="pst-info-card card-surface mb-3">
  <div class="pst-info-card-header">
    <div class="pst-info-card-title"><i class="fa-solid fa-sliders"></i><span data-i18n="pst_template_info">Template Info</span></div>
    <div class="pst-info-toggles">
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
  <div class="pst-info-card-body">
    <div class="pst-info-row">
      <label class="pst-info-label" data-i18n="header_text">Header Text</label>
      <div class="pst-info-textareas">
        <div class="pst-info-textarea-group">
          <img src="<?=BASE_URL?>/public/flags/th.png" class="pst-info-lang-flag" alt="TH">
          <textarea class="form-control form-control-sm" id="pstHeaderThInput" rows="2" data-i18n="header_text_th_placeholder" placeholder="ข้อความหัวกระดาษ (ไทย)" maxlength="500"></textarea>
        </div>
        <div class="pst-info-textarea-group">
          <img src="<?=BASE_URL?>/public/flags/gb.png" class="pst-info-lang-flag" alt="EN">
          <textarea class="form-control form-control-sm" id="pstHeaderEnInput" rows="2" placeholder="Header text (English)" maxlength="500"></textarea>
        </div>
      </div>
    </div>
    <div class="pst-info-row">
      <label class="pst-info-label" data-i18n="footer_text">Footer Text</label>
      <div class="pst-info-textareas">
        <div class="pst-info-textarea-group">
          <img src="<?=BASE_URL?>/public/flags/th.png" class="pst-info-lang-flag" alt="TH">
          <textarea class="form-control form-control-sm" id="pstFooterThInput" rows="2" data-i18n="footer_text_th_placeholder" placeholder="ข้อความท้ายกระดาษ (ไทย)" maxlength="500"></textarea>
        </div>
        <div class="pst-info-textarea-group">
          <img src="<?=BASE_URL?>/public/flags/gb.png" class="pst-info-lang-flag" alt="EN">
          <textarea class="form-control form-control-sm" id="pstFooterEnInput" rows="2" placeholder="Footer text (English)" maxlength="500"></textarea>
        </div>
      </div>
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
      <!-- Language tabs + Grid/Undo-Redo/Watermark/Zoom -- direct port of Employment Certificate
           Template's own `.ect-canvas-toolbar`/`#ectLangTabs` (2026-08-25 follow-up, "รูปแบบการทำ
           เหมือนกัน"). Switching language tabs swaps in-memory state only (pairState in the JS),
           never reloads from the server and never discards anything; .pst-lang-tab-dot marks a
           language with unsaved edits. -->
      <div class="pst-canvas-toolbar">
        <div class="pst-lang-tabs" id="pstLangTabs">
          <button type="button" class="pst-lang-tab" data-lang="th">
            <img src="<?=BASE_URL?>/public/flags/th.png" class="pst-lang-tab-flag" alt="">
            <span data-i18n="template_language_th">Thai</span>
            <span class="pst-lang-tab-dot d-none"></span>
          </button>
          <button type="button" class="pst-lang-tab" data-lang="en">
            <img src="<?=BASE_URL?>/public/flags/gb.png" class="pst-lang-tab-flag" alt="">
            <span data-i18n="template_language_en">English</span>
            <span class="pst-lang-tab-dot d-none"></span>
          </button>
        </div>
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

<!-- 2026-08-25, explicit follow-up: "หน้า Design กับหน้า Assign To ต้องการให้มีปุ่ม Save แยก Tab" --
     each tab now has its OWN footer/Save button instead of one shared footer sitting outside both
     tab panes. Both Save buttons share the `.pst-save-btn` class and are handled by the SAME click
     handler, which always submits the full payload (canvas elements AND assignment checkboxes
     together) regardless of which button was clicked -- the backend only ever accepts one atomic
     save() call for both, so this is a visual/UX change (a Save button reachable from wherever you
     currently are), not a split into two independent partial saves that could let one half go stale
     while the other is edited. Preview stays Design-only (previewing "the design" doesn't apply to
     the Assign To tab). `.pst-save-hint`/`.pst-editor-footer` are classes (not ids) so they're reused
     verbatim on both footers -- no new CSS needed. -->
<div class="pst-editor-footer">
  <div class="text-secondary small me-auto pst-save-hint"></div>
  <button type="button" class="btn btn-outline-secondary pst-footer-btn-lg" id="pstPreviewBtn"><i class="fa-solid fa-eye me-1"></i><span data-i18n="preview">Preview</span></button>
  <button type="button" class="btn btn-primary pst-footer-btn-lg pst-save-btn" id="pstSaveBtnDesign"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
</div>

</div><!-- /#pstTabDesign -->

<!-- Assign To tab -- explicit request: "อยากให้เพิ่ม Tab...เป็น checkbox ให้เลือก ว่าจะ Assign ไปที่ไหนบ้าง
     และสามารถเลือกใช้ได้กับทุกคน ทุกแผนก ทุกทีม แต่ถ้ามีการตั้งค่าซ้ำต้องแจ้ง Error ว่ามีการ Assign ซ้ำใคร"
     -- replaced the earlier select2-remote-search multi-selects with real checkbox lists (a search
     picker only ever shows a handful of matches at a time; a checkbox list needs every department/
     team/employee visible up front so "assign to everyone" is just leaving them all unchecked, or
     ticking every box, both obviously equivalent at a glance). Each list gets its own "Select All"
     master checkbox (indeterminate-aware) plus a client-side search filter box -- departments/teams
     are typically short lists, but this app's own domain (an outsourcing/staffing firm, see the Team
     feature's own docblock) can mean hundreds of employees, so the employee list specifically NEEDS
     a filter to stay usable even though every row is fetched once up front, no server-side paging. -->
<div class="tab-pane fade" id="pstTabAssign">
  <div class="pst-assign-card card-surface p-3 mb-3">
    <div class="text-secondary small pst-assign-hint mb-3" data-i18n="pst_assign_hint">Leave all empty to apply to every employee (the company default). If any are selected, this template only applies to that department/team/employee, with the most specific match winning (employee &gt; team &gt; department). Assigning a department/team/employee that's already actively assigned to another template will show an error naming the conflict.</div>
    <div class="pst-assign-columns">
      <div class="pst-assign-column">
        <div class="pst-assign-column-header">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pstAssignAllDepartments">
            <label class="form-check-label fw-bold" for="pstAssignAllDepartments"><i class="fa-solid fa-building me-1"></i><span data-i18n="scope_departments">Departments</span></label>
          </div>
        </div>
        <input type="text" class="form-control form-control-sm pst-assign-filter" id="pstAssignDepartmentsFilter" data-i18n="pst_assign_search_placeholder" placeholder="Search...">
        <div class="pst-assign-checklist" id="pstAssignDepartmentsList"></div>
      </div>
      <div class="pst-assign-column">
        <div class="pst-assign-column-header">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pstAssignAllTeams">
            <label class="form-check-label fw-bold" for="pstAssignAllTeams"><i class="fa-solid fa-people-group me-1"></i><span data-i18n="pst_assign_teams">Teams</span></label>
          </div>
        </div>
        <input type="text" class="form-control form-control-sm pst-assign-filter" id="pstAssignTeamsFilter" data-i18n="pst_assign_search_placeholder" placeholder="Search...">
        <div class="pst-assign-checklist" id="pstAssignTeamsList"></div>
      </div>
      <div class="pst-assign-column">
        <div class="pst-assign-column-header">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pstAssignAllEmployees">
            <label class="form-check-label fw-bold" for="pstAssignAllEmployees"><i class="fa-solid fa-user me-1"></i><span data-i18n="pst_assign_employees">Employees</span></label>
          </div>
        </div>
        <input type="text" class="form-control form-control-sm pst-assign-filter" id="pstAssignEmployeesFilter" data-i18n="pst_assign_search_placeholder" placeholder="Search...">
        <div class="pst-assign-checklist" id="pstAssignEmployeesList"></div>
      </div>
    </div>
  </div>

  <!-- own Save button for this tab, see the Design tab's own comment on this same change -->
  <div class="pst-editor-footer">
    <div class="text-secondary small me-auto pst-save-hint"></div>
    <button type="button" class="btn btn-primary pst-footer-btn-lg pst-save-btn" id="pstSaveBtnAssign"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
  </div>
</div>

</div><!-- /.tab-content -->
</div><!-- /#pstMainTabsWrap -->

<!-- Empty state for a language tab that hasn't been created yet for this pair -- direct port of
     Employment Certificate Template's own `#ectLangEmptyState` (2026-08-25 follow-up,
     "รูปแบบการทำเหมือนกัน"). Generate Auto (clones the OTHER language's layout verbatim, same
     semantics as PayslipTemplateModel::generateOtherLanguage()) or start from a preset, without
     leaving this page or losing the other tab's in-progress edits. -->
<div id="pstLangEmptyState" class="pst-lang-empty-state d-none">
  <i class="fa-solid fa-file-circle-plus pst-lang-empty-icon"></i>
  <div class="fw-bold text-secondary" id="pstLangEmptyTitle"></div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-primary" id="pstLangEmptyGenerateBtn">
      <i class="fa-solid fa-wand-magic-sparkles me-1"></i><span data-i18n="ect_generate_auto">Generate Auto</span>
    </button>
    <button type="button" class="btn btn-primary" id="pstLangEmptyPresetBtn">
      <i class="fa-solid fa-layer-group me-1"></i><span data-i18n="ect_start_from_preset">Start from a preset</span>
    </button>
  </div>
</div>
