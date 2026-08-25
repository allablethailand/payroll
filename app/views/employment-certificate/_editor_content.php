<?php
/**
 * Employment Certificate Template EDITOR content (2026-08-25, explicit request: "หน้าแก้ไขให้เปลี่ยน
 * เป็นการเปิด Tab ใหม่ เพื่อให้การจัดการมีพื้นที่มากขึ้น") -- this used to be #ectEditModal's
 * `.modal-content` (a Bootstrap fullscreen modal); it's now the body of a standalone page
 * (`edit.php`, opened in its own browser tab, addressed by `pair_key` in the URL) so the whole
 * ribbon+palette+canvas+layers designer gets the full viewport instead of competing with a modal's
 * own chrome. Text/Image-Library modals still stack on TOP of this page normally (see
 * _modals_partial.php, included separately by edit.php) -- only the outer #ectEditModal wrapper
 * itself is gone; everything that was inside it moved here basically verbatim except where noted.
 *
 * 2026-08-25, same-day follow-up ("Tab Thai กับ Eng อยากให้มีรูปธงด้วย...ให้เอามาไว้ตรงที่บนของกรอบ preview
 * ครับ grid อยู่ฝั่งขวา...ปุ่มย้อนกลับไปข้างหน้าให้เอามาไว้รวมกับ grid ครับ...ขอบกระดาษให้เป็น dropdown เลือกแบบ
 * word") -- the language tabs (now with flag icons, reusing the same public/flags/*.png the system
 * language switcher uses) moved to be the top strip of the CANVAS FRAME itself (`.ect-canvas-wrap`),
 * not a separate toolbar row above the whole designer; Undo/Redo moved out of the ribbon to sit next
 * to Grid in that same strip; the margin number input became a Word-style presets dropdown
 * (Narrow/Normal/Moderate/Wide + Custom, `#ectMarginInput` is still the source of truth underneath,
 * just no longer directly visible unless "Custom" is picked).
 */
?>
<!-- 2026-08-25, explicit follow-up: "ปรับส่วนของ card template name ให้สวยขึ้น" -- Template Name
     reads as a document TITLE (icon + large borderless-until-focus input, Google Docs/Word-title
     convention) instead of a plain labeled form field; Page Size/Orientation/Margin grouped into
     one visually-distinct "Page Setup" cluster instead of 3 separate look-alike form groups.
     "ปุ่ม back to template ตัดออกได้เลย" -- removed; the page now renders through the normal
     breadcrumb/navbar shell (see edit.php), which already covers navigating away.
     window.beforeunload (still wired in the JS) still warns on unsaved changes regardless of how
     the admin actually leaves the page. -->
<div class="ect-editor-topbar">
  <div class="ect-editor-title-group">
    <i class="fa-solid fa-file-shield ect-editor-title-icon"></i>
    <input type="text" class="ect-editor-title-input" id="ectTemplateNameInput" placeholder="Template Name">
  </div>
  <div class="ect-editor-topbar-fields">
    <div class="ect-page-setup-cluster">
      <span class="ect-page-setup-label" data-i18n="ect_page_setup">Page Setup</span>
      <select class="form-select form-select-sm" id="ectPageSizeSelect">
        <option value="A4">A4</option>
        <option value="Letter">Letter</option>
        <option value="Legal">Legal</option>
      </select>
      <select class="form-select form-select-sm" id="ectOrientationSelect">
        <option value="portrait" data-i18n="ect_portrait">Portrait</option>
        <option value="landscape" data-i18n="ect_landscape">Landscape</option>
      </select>
      <!-- 2026-08-25, explicit request: "การเลือกขอบกระดาษให้เป็น dropdown เลือกแบบ word ครับ เลือกจาก
           ตัวอย่าง" -- Word's own Page Layout > Margins picker: named presets with a small visual per
           option, plus a Custom option that reveals an exact mm field. #ectMarginInput (the real,
           persisted value) is still what every other function in the JS reads/writes -- this
           dropdown is just a friendlier way to SET it; picking a preset sets the input's value and
           fires the same 'input' handler the field itself already has (applyMarginGuide()/dirty
           tracking). -->
      <div class="dropdown ect-margin-dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="ectMarginDropdownBtn">
          <i class="fa-solid fa-ruler-combined me-1"></i><span id="ectMarginDropdownLabel">Normal</span>
        </button>
        <ul class="dropdown-menu ect-margin-menu" id="ectMarginMenu">
          <li><a class="dropdown-item ect-margin-option" href="#" data-value="8"><span class="ect-margin-preview ect-margin-preview-narrow"></span><span class="ect-margin-option-text"><strong data-i18n="ect_margin_narrow">Narrow</strong><small>8 mm</small></span></a></li>
          <li><a class="dropdown-item ect-margin-option" href="#" data-value="15"><span class="ect-margin-preview ect-margin-preview-normal"></span><span class="ect-margin-option-text"><strong data-i18n="ect_margin_normal">Normal</strong><small>15 mm</small></span></a></li>
          <li><a class="dropdown-item ect-margin-option" href="#" data-value="20"><span class="ect-margin-preview ect-margin-preview-moderate"></span><span class="ect-margin-option-text"><strong data-i18n="ect_margin_moderate">Moderate</strong><small>20 mm</small></span></a></li>
          <li><a class="dropdown-item ect-margin-option" href="#" data-value="30"><span class="ect-margin-preview ect-margin-preview-wide"></span><span class="ect-margin-option-text"><strong data-i18n="ect_margin_wide">Wide</strong><small>30 mm</small></span></a></li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <div class="px-3 py-1 d-flex align-items-center gap-2" onclick="event.stopPropagation();">
              <span class="small text-secondary" data-i18n="ect_margin_custom">Custom</span>
              <input type="number" id="ectMarginInput" class="form-control form-control-sm" style="width:70px;" min="0" max="50" step="1">
              <span class="small text-secondary">mm</span>
            </div>
          </li>
        </ul>
      </div>
    </div>
    <!-- 2026-08-25, explicit request (resolved via AskUserQuestion): "เปลี่ยนเป็นดีไซน์ตั้งต้นของ
         Template ที่กำลังแก้" -- re-applies a different preset's layout to what's open RIGHT NOW
         (destructive, confirmed before replacing), not a "switch to a different existing template"
         picker. -->
    <button type="button" class="btn btn-outline-primary btn-sm" id="ectChangePresetBtn">
      <i class="fa-solid fa-shuffle me-1"></i><span data-i18n="ect_change_preset">Change Layout</span>
    </button>
  </div>
</div>

<div id="ectEditorArea">
  <!-- Word-style formatting ribbon: always visible, controls disabled until an element is selected.
       2026-08-25 follow-up: Undo/Redo moved OUT of here (next to Grid on the canvas frame's own top
       strip below) to de-clutter this row -- explicit request: "ปุ่มย้อนกลับไปข้างหน้าให้เอามาไว้รวมกับ
       grid ครับ...ตอนนี้ดูแน่นไปหมด". -->
  <div class="ect-ribbon card-surface mb-3" id="ectRibbon">
    <div class="ect-ribbon-group">
      <label class="ect-ribbon-label" data-i18n="ect_font_size">Font Size</label>
      <input type="number" id="ectPropFontSize" class="form-control form-control-sm" min="6" max="96" disabled>
    </div>
    <div class="ect-ribbon-group">
      <label class="ect-ribbon-label" data-i18n="ect_font_family">Font</label>
      <!-- 2026-08-25, explicit request: "เพิ่มตัวเลือก font สัก 10 font ครับ" -- 7 real, embeddable
           fonts (see EmploymentCertificateRenderer::FONT_FAMILY_CSS's own comment for why not 10).
           Only TH Sarabun New has Thai glyphs -- the other 6 all carry data-en-only, same restriction
           DejaVu Sans already had, now applied consistently to the rest. -->
      <select class="form-select form-select-sm" id="ectPropFontFamily" disabled>
        <option value="th_sarabun_new">TH Sarabun New</option>
        <option value="dejavu_sans" data-en-only="1">DejaVu Sans</option>
        <option value="dejavu_sans_mono" data-en-only="1">DejaVu Sans Mono</option>
        <option value="dejavu_serif" data-en-only="1">DejaVu Serif</option>
        <option value="helvetica" data-en-only="1">Helvetica</option>
        <option value="times_new_roman" data-en-only="1">Times New Roman</option>
        <option value="courier" data-en-only="1">Courier</option>
      </select>
    </div>
    <div class="ect-ribbon-group">
      <label class="ect-ribbon-label" data-i18n="ect_font_color">Color</label>
      <input type="color" id="ectPropFontColor" class="form-control form-control-sm form-control-color" disabled>
    </div>
    <div class="ect-ribbon-sep"></div>
    <!-- 2026-08-25, explicit follow-up: "ตรง image libraly ให้ขึ้นเป็น อีกปุ่มตรง ที่จัดการ font โดยคลิก
         แล้วมีให้เลือกว่าจะ upload ใหม่หรือเลือกจากที่มีอยู่แล้ว...และเพิ่ม option การเพิ่มตาราง...และมีให้เพิ่ม
         Symbol ได้ และสามารถ insert shape ต่างๆ เหมือน Word" -- an "Insert" section, always enabled
         (unlike the rest of this ribbon, none of these depend on a selection -- they ADD something
         new). Image Library moved here from the left-rail palette entirely. -->
    <div class="ect-ribbon-group">
      <div class="dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="ectInsertImageBtn">
          <i class="fa-solid fa-image me-1"></i><span data-i18n="ect_image">Image</span>
        </button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" href="#" id="ectImageUploadNewItem"><i class="fa-solid fa-upload me-2"></i><span data-i18n="ect_upload_new_image">Upload New</span></a></li>
          <li><a class="dropdown-item" href="#" id="ectImageChooseExistingItem"><i class="fa-solid fa-images me-2"></i><span data-i18n="ect_choose_existing_image">Choose Existing</span></a></li>
        </ul>
      </div>
      <input type="file" id="ectRibbonImageUploadInput" accept=".jpg,.jpeg,.png,.svg" class="d-none" multiple>
    </div>
    <div class="ect-ribbon-group">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="ectInsertTableBtn" title="Insert Table">
        <i class="fa-solid fa-table me-1"></i><span data-i18n="ect_table">Table</span>
      </button>
    </div>
    <div class="ect-ribbon-group">
      <div class="dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="ectInsertSymbolBtn">
          <i class="fa-solid fa-icons me-1"></i><span data-i18n="ect_symbol">Symbol</span>
        </button>
        <div class="dropdown-menu ect-symbol-menu p-2" id="ectSymbolMenu"></div>
      </div>
    </div>
    <div class="ect-ribbon-group">
      <div class="dropdown">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" id="ectInsertShapeBtn">
          <i class="fa-solid fa-shapes me-1"></i><span data-i18n="ect_shape">Shape</span>
        </button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item ect-insert-shape-item" href="#" data-shape="rectangle"><i class="fa-regular fa-square me-2"></i><span data-i18n="ect_shape_rectangle">Rectangle</span></a></li>
          <li><a class="dropdown-item ect-insert-shape-item" href="#" data-shape="ellipse"><i class="fa-regular fa-circle me-2"></i><span data-i18n="ect_shape_ellipse">Ellipse</span></a></li>
          <li><a class="dropdown-item ect-insert-shape-item" href="#" data-shape="line"><i class="fa-solid fa-minus me-2"></i><span data-i18n="ect_shape_line">Line</span></a></li>
        </ul>
      </div>
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
    <!-- 2026-08-24, explicit request: multi-select (Ctrl+Click) + Group/Ungroup ("เลือกหลาย
         รายการเพื่อลบ หรือ Group รวม layout ได้ และสามารถ ungroup ได้"). Group needs 2+ selected;
         Ungroup needs at least one selected element that's currently in a group -- both toggled
         in updateSelectionUI(). Delete now works on the whole current (possibly multi/group)
         selection, not just a single element. -->
    <div class="ect-ribbon-group">
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary" id="ectGroupBtn" title="Group (Ctrl+G)" disabled><i class="fa-solid fa-object-group"></i></button>
        <button type="button" class="btn btn-outline-secondary" id="ectUngroupBtn" title="Ungroup (Ctrl+Shift+G)" disabled><i class="fa-solid fa-object-ungroup"></i></button>
      </div>
    </div>
    <div class="ect-ribbon-group">
      <button type="button" class="btn btn-outline-danger btn-sm" id="ectDeleteElementBtn" disabled>
        <i class="fa-solid fa-trash me-1"></i><span data-i18n="delete">Delete</span>
      </button>
    </div>
    <div class="ect-ribbon-hint text-secondary small ms-auto" id="ectRibbonHint" data-i18n="ect_ribbon_select_hint">Select an element on the canvas to format it.</div>
  </div>
  <!-- How-to hint (explicit request: "แต่ต้องมี how to บอกด้วย") -- always visible, not tied to
       selection state, since it's teaching an interaction the user might not discover otherwise. -->
  <div class="text-secondary ect-multiselect-hint mb-2">
    <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="ect_multiselect_howto">Tip: Ctrl+Click to select multiple elements — drag to move them together, or use Group/Ungroup and Delete above. Click a layer on the right to select it too.</span>
  </div>

  <div class="ect-designer">
    <!-- Left rail: field palette + logo (template metadata/CRUD moved to the top bar / list row actions) -->
    <div class="ect-palette card-surface p-3">
      <h6 class="fw-bold mb-2" data-i18n="ect_add_element">Add to Canvas</h6>
      <div class="text-secondary small mb-2" data-i18n="ect_add_element_hint">Drag a field onto the canvas, or click to drop it at the top — then drag/resize freely.</div>
      <div id="ectFieldPalette" class="ect-palette-list"></div>
      <!-- 2026-08-25, explicit follow-up: "ตรง image libraly ให้ขึ้นเป็น อีกปุ่มตรง ที่จัดการ font" -- the
           Image Library section that used to live here (below the field palette) moved into the
           ribbon's "Image" dropdown instead (Upload New / Choose Existing). "Company Logo" as a
           canvas element still always resolves from the Company Profile's own logo (see Company
           Profile > Logo, and companyLogoPath in employment-certificate-template.js). -->
    </div>

    <!-- Canvas -->
    <div class="ect-canvas-wrap card-surface p-3">
      <!-- Language tabs + Grid/Undo-Redo/Watermark/Zoom (2026-08-25, explicit follow-up: "ให้เอามาไว้
           ตรงที่บนของกรอบ preview ครับ grid อยู่ฝั่งขวา...ปุ่มย้อนกลับไปข้างหน้าให้เอามาไว้รวมกับ grid ครับ")
           -- this strip is the top edge of the CANVAS FRAME itself, not a separate toolbar row above
           the whole designer -- reads visually like tabs on a window, with Grid/Undo/Redo/Watermark/
           Zoom grouped on the right via .ect-toolbar-right. Switching language tabs swaps in-memory
           state only (pairState in the JS), never reloads from the server and never discards
           anything; .ect-lang-tab-dot marks a language with unsaved edits. Flags reuse the exact
           same public/flags/*.png the system language switcher uses (explicit request: "อยากให้มี
           รูปธงด้วยครับเหมือนตัวเปลี่ยนภาษา"). -->
      <div class="ect-canvas-toolbar">
        <div class="ect-lang-tabs" id="ectLangTabs">
          <button type="button" class="ect-lang-tab" data-lang="th">
            <img src="<?=BASE_URL?>/public/flags/th.png" class="ect-lang-tab-flag" alt="">
            <span data-i18n="template_language_th">Thai</span>
            <span class="ect-lang-tab-dot d-none"></span>
          </button>
          <button type="button" class="ect-lang-tab" data-lang="en">
            <img src="<?=BASE_URL?>/public/flags/gb.png" class="ect-lang-tab-flag" alt="">
            <span data-i18n="template_language_en">English</span>
            <span class="ect-lang-tab-dot d-none"></span>
          </button>
        </div>
        <div class="ect-toolbar-right">
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" id="ectUndoBtn" title="Undo (Ctrl+Z)" disabled><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" class="btn btn-outline-secondary" id="ectRedoBtn" title="Redo (Ctrl+Shift+Z)" disabled><i class="fa-solid fa-rotate-right"></i></button>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm active" id="ectGridToggle" title="Toggle grid">
            <i class="fa-solid fa-table-cells me-1"></i><span data-i18n="ect_toggle_grid">Grid</span>
          </button>
          <div class="ect-zoom-control">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="ectZoomOutBtn" title="Zoom Out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <select class="form-select form-select-sm" id="ectZoomSelect">
              <option value="50">50%</option>
              <option value="75">75%</option>
              <option value="100" selected>100%</option>
              <option value="125">125%</option>
              <option value="150">150%</option>
              <option value="200">200%</option>
            </select>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="ectZoomInBtn" title="Zoom In"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="ectZoomResetBtn" title="Reset to 100%"><i class="fa-solid fa-compress"></i></button>
          </div>
        </div>
      </div>
      <div class="ect-canvas-scroll ect-pannable">
        <!-- 2026-08-25, explicit request: "ตรงพื้นที่ว่าง ให้สามารถลากเพื่อดูซ้ายขวาได้" -- dragging
             empty canvas area (not an element) pans via scrollLeft/scrollTop, see the JS.
             #ectMarginGuide is NOT declared here -- renderCanvas() empties this element on
             every single edit, so the JS (re-)injects the guide div itself every render
             instead of relying on markup that would get wiped out immediately.
             #ectZoomStage reserves the SCALED footprint of #ectPage (see applyZoom()) so
             scrolling/panning works correctly at any zoom level -- transform:scale() alone
             doesn't change #ectPage's own layout box size, only how it paints. -->
        <div id="ectZoomStage">
          <div class="ect-page ect-grid-on" id="ectPage"></div>
        </div>
      </div>
      <!-- 2026-08-25, explicit request: "รองรับการมีหลายๆหน้า โดยที่มีปุ่มให้เลือกเพิ่มหรือลด" -- one
           template's elements can span multiple pages, each still using the same percentage-of-ONE-
           page coordinate space (see EmploymentCertificateModel/Renderer). This strip only changes
           which page_number is currently shown/edited and how many exist -- nothing about
           positioning changes. -->
      <div class="ect-page-nav">
        <button type="button" class="btn btn-link btn-sm" id="ectPagePrevBtn" title="Previous page"><i class="fa-solid fa-chevron-left"></i></button>
        <span class="ect-page-nav-label" id="ectPageNavLabel">Page 1 / 1</span>
        <button type="button" class="btn btn-link btn-sm" id="ectPageNextBtn" title="Next page"><i class="fa-solid fa-chevron-right"></i></button>
        <span class="ect-page-nav-sep"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ectPageAddBtn" title="Add page"><i class="fa-solid fa-plus me-1"></i><span data-i18n="ect_add_page">Add Page</span></button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="ectPageRemoveBtn" title="Remove this page"><i class="fa-solid fa-trash me-1"></i><span data-i18n="ect_remove_page">Remove Page</span></button>
      </div>
    </div>

    <!-- Right column: Watermark card + Layers panel, stacked (2026-08-25, explicit follow-up:
         "ย้าย water mark ไปเป็นอีก card บน Layer" -- moved out of the crowded canvas toolbar strip
         into its own dedicated card sitting above Layers, instead of sharing a row with Grid/Undo/
         Redo/Zoom). -->
    <div class="ect-layers-column">
      <div class="ect-watermark-card card-surface p-3">
        <h6 class="fw-bold mb-2" data-i18n="ect_watermark_title">Watermark</h6>
        <div class="text-secondary small mb-2" data-i18n="ect_watermark_hint">Preview only — never appears in the saved document.</div>
        <div class="form-check form-switch">
          <input type="checkbox" class="form-check-input" id="ectWatermarkToggle">
          <label class="form-check-label small" for="ectWatermarkToggle" data-i18n="ect_watermark_enable">Enable</label>
        </div>
        <input type="text" class="form-control form-control-sm mt-2 d-none" id="ectWatermarkText" placeholder="SAMPLE">
      </div>
      <!-- Layers panel (explicit request: "โดยมี layer บอกเหมือน photoshop") -->
      <div class="ect-layers card-surface p-3">
        <h6 class="fw-bold mb-2" data-i18n="ect_layers">Layers</h6>
        <div id="ectLayersList" class="ect-layers-list"></div>
      </div>
    </div>
  </div>
</div>

<!-- Empty state for a language tab that hasn't been created yet for this pair (2026-08-25,
     TH/EN-tabs unification) -- Generate Auto (clones the OTHER language's layout verbatim, same
     semantics as the list's old per-language action, see
     EmploymentCertificateTemplateModel::generateOtherLanguage()) or start from a preset, without
     leaving this page or losing the other tab's in-progress edits. -->
<div id="ectLangEmptyState" class="ect-lang-empty-state d-none">
  <i class="fa-solid fa-file-circle-plus ect-lang-empty-icon"></i>
  <div class="fw-bold text-secondary" id="ectLangEmptyTitle"></div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-primary" id="ectLangEmptyGenerateBtn">
      <i class="fa-solid fa-wand-magic-sparkles me-1"></i><span data-i18n="ect_generate_auto">Generate Auto</span>
    </button>
    <button type="button" class="btn btn-primary" id="ectLangEmptyPresetBtn">
      <i class="fa-solid fa-layer-group me-1"></i><span data-i18n="ect_start_from_preset">Start from a preset</span>
    </button>
  </div>
</div>

<div class="ect-editor-footer">
  <div class="text-secondary small me-auto" id="ectSaveHint"></div>
  <button type="button" class="btn btn-outline-secondary ect-footer-btn-lg" id="ectPreviewBtn"><i class="fa-solid fa-eye me-1"></i><span data-i18n="preview">Preview</span></button>
  <button type="button" class="btn btn-primary ect-footer-btn-lg" id="ectSaveBtn"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
</div>
