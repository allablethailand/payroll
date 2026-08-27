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
<!-- 2026-08-26, follow-up correction: "Mode Fullscreen หมายถึงให้การตั้งค่าแสดงใน modal fullscreen ครับ"
     -- the first attempt at this (toggling the browser's native Fullscreen API) was wrong: this page
     can be embedded inside Origami's own shell (an iframe), and browsers block requestFullscreen()
     entirely when the parent frame hasn't granted the `allow="fullscreen"` permission -- so the button
     could silently do nothing depending on how the page is reached. Confirmed via AskUserQuestion:
     wrap the ENTIRE editor content (topbar/tabs/Page Setup/Template Info/ribbon/canvas/layers --
     everything below this comment) in a genuine Bootstrap `.modal-fullscreen`, which is pure CSS/DOM
     and works identically regardless of iframe embedding. `#pstEditorContentAnchor` is a stable,
     always-in-place placeholder marking where `#pstEditorContent` normally lives on the page --
     toggling fullscreen moves the WHOLE `#pstEditorContent` node into the modal body (jQuery
     `.appendTo()`) and shows the modal; closing the modal (`hidden.bs.modal`, fired the same way
     whether closed via the button, Esc, or the backdrop) moves it back with `.insertAfter()`. Every
     handler in payslip-template.js is bound via `$(document).on(...)` delegation, so none of them
     care which DOM parent the content is currently sitting under. -->
<div id="pstEditorContentAnchor"></div>
<!-- 2026-08-26, real bug fixed: this was missing the `.modal-content` wrapper entirely -- Bootstrap's
     own CSS puts the opaque background/border/flex-column layout on `.modal-content`, NOT on
     `.modal-dialog`/`.modal-body` directly, so skipping it left the whole fullscreen surface
     see-through (page content behind it visible through it) and broke the flex sizing the canvas'
     own height/scroll math depends on -- which is what made the fullscreen mode "not actually
     usable" even though the DOM-relocation JS itself was correct. -->
<!-- 2026-08-26, same-day follow-up: "ปุ่ม Save และ Preview อยากให้มาอยู่ที่ modal footer" +
     "การเปิดการตั้งค่าใน fullscreen ปรับให้รองรับเฉพาะหน้า Design" -- a real Bootstrap `.modal-footer`
     (was just empty space at the bottom of `.modal-body` before) is the relocation target for the
     Design tab's own `.pst-editor-footer` (Save+Preview) -- see `#pstDesignFooterAnchor` below marking
     where it normally lives. Fullscreen no longer relocates the WHOLE editor -- only Design's own
     content is shown (the tab nav + Assign To pane are hidden via the `.pst-fullscreen-active` class
     toggled on `#pstEditorContent`, see style.css), matching the "fullscreen supports Design only" ask
     without needing to restructure which DOM node gets moved into the modal. -->
<!-- 2026-08-26, same-day follow-up: "ปุ่มปิด fullscreen อยากให้ design ให้ชัดๆ หรือให้แสดง modal-header
     ด้วย นำ header กับการแก้ชื่อไปใส่ใน modal header" -- a real `.modal-header` replaces the old
     "click the same Fullscreen button again to close" as the primary, unambiguous close affordance
     (a plain Bootstrap `.btn-close`, always in the same top-right spot every other modal in this app
     uses). `#pstEditorTopbarAnchor` marks where `.pst-editor-topbar` (the Template Name title group)
     normally sits, right below this modal, so it can relocate INTO the header on show and back out on
     hide -- same anchor-relocation pattern `#pstEditorContentAnchor`/`#pstDesignFooterAnchor` already
     use. "ตัด pst-editor-footer ออกให้ปุ่มแสดงใน footer เลยจะได้ไม่เปลืองพื้นที่" -- .pst-editor-footer's
     own box styling (background/shadow/padding) is neutralized via a `.modal-footer .pst-editor-footer`
     CSS override (see style.css) once it's relocated in here, so the Save/Preview buttons read as
     sitting directly in the modal's own footer instead of a smaller styled box nested inside it. -->
<div class="modal fade" id="pstFullscreenModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen m-0">
    <div class="modal-content">
      <div class="modal-header" id="pstFullscreenModalHeader">
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3" id="pstFullscreenModalBody"></div>
      <div class="modal-footer" id="pstFullscreenModalFooter"></div>
    </div>
  </div>
</div>
<div id="pstEditorContent">
<div id="pstEditorTopbarAnchor"></div>
<div class="pst-editor-topbar" id="pstEditorTopbar">
  <div class="pst-editor-title-group">
    <i class="fa-solid fa-file-invoice pst-editor-title-icon"></i>
    <input type="text" class="pst-editor-title-input" id="pstTemplateNameInput" placeholder="Template Name">
    <!-- 2026-08-26, explicit request: "ในการตั้งชื่อในหน้าตั้งค่า ให้มีปุ่มดินสอด้วยจะได้รู้ว่าแก้ไขได้" --
         same pencil affordance added to Employment Certificate Template's own title input, see that
         file's own comment for the full reasoning. -->
    <button type="button" class="pst-editor-title-edit-btn" id="pstTemplateNameEditBtn" title="Rename"><i class="fa-solid fa-pen"></i></button>
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

<!-- 2026-08-26, same-day follow-up: "Enable This Template ซ้ำกับ Draft/Public น่าจะต้องตัดออก" --
     removed entirely (status is now hardcoded 'active' in the Save payload, see payslip-template.js's
     own comment -- Draft/Public already gates real-generation eligibility, a second active/inactive
     toggle was genuinely redundant). "ตั้งค่า Template Info คำนี้ให้ตัดทิ้งเลย" -- the card's own title
     label/icon are gone too, it's just a controls strip now, no heading needed.
     "ทั้ง payslip และ เอกสาร ส่วนของ page setup สามารถไปอยู่รวมกับ ปุ่ม Draft/Public และ auto save ได้ไหม
     จะได้แสดงแค่แถวเดียว รวมถึง change layout และ fullscreen ด้วย" -- Page Setup/Change Layout/Fullscreen
     (formerly their own separate `.pst-editor-topbar-fields` row below this card) are now direct
     children of this SAME `.pst-info-card-header`, which is already `display:flex;flex-wrap:wrap`, so
     everything shows as one row that wraps gracefully instead of two stacked rows. -->
<div class="pst-info-card card-surface mb-3">
  <div class="pst-info-card-header">
    <div class="pst-info-toggles">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="pstIsDefaultSwitch">
        <label class="form-check-label small" for="pstIsDefaultSwitch" data-i18n="set_as_default_template">Set as default template</label>
      </div>
      <!-- 2026-08-26, explicit follow-up: "ให้มี switch ปิดเปิด Draft Public เหมือนกัน หรือทำเป็น radio
           switch ให้กดว่า Draft หรือ Public" -- replaced the single checkbox switch with a 2-button
           segmented control (chosen over a plain switch since "Draft"/"Public" are two distinct named
           states, not an on/off concept) -- clicking either button calls
           api/payslip-template.publish-toggle IMMEDIATELY (not gated behind Save), same as the List
           page's own badge. Disabled until the template has a real id (see updatePstPublishUi()). -->
      <div class="btn-group btn-group-sm pst-publish-toggle-group" role="group" id="pstPublishToggleGroup">
        <button type="button" class="btn btn-outline-warning pst-publish-option" data-value="draft" disabled><i class="fa-solid fa-pen me-1"></i><span data-i18n="ect_publish_draft">Draft</span></button>
        <button type="button" class="btn btn-outline-success pst-publish-option" data-value="public" disabled><i class="fa-solid fa-globe me-1"></i><span data-i18n="ect_publish_public">Public</span></button>
      </div>
      <div class="form-check form-switch" data-i18n-title="ect_auto_save_hint" title="Automatically save changes while editing, instead of only on Save.">
        <input class="form-check-input" type="checkbox" id="pstAutoSaveSwitch">
        <label class="form-check-label small" for="pstAutoSaveSwitch" data-i18n="ect_auto_save">Auto Save</label>
      </div>
    </div>
    <div class="pst-page-setup-cluster">
    <span class="pst-page-setup-label" data-i18n="ect_page_setup">Page Setup</span>
    <select class="form-select form-select-sm" id="pstPageSizeSelect">
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
  <!-- 2026-08-27, explicit request: "ปรับให้ Change Layout กับ Fullscreen ไปอยู่ติดกันที่มุมขวาจะสวยกว่า" --
       both grouped into one flex item so `.pst-info-card-header`'s own `justify-content:space-between`
       carries the PAIR to the row's right edge together, instead of each button being its own
       separately-distributed flex item alongside .pst-info-toggles/.pst-page-setup-cluster. -->
  <div class="pst-editor-actions-right">
    <button type="button" class="btn btn-outline-primary btn-sm" id="pstChangePresetBtn">
      <i class="fa-solid fa-shuffle me-1"></i><span data-i18n="ect_change_preset">Change Layout</span>
    </button>
    <!-- 2026-08-27, explicit request: the Fullscreen modal already has its own close (X) button, so
         this toggle button is hidden while the modal is open (see .pst-fullscreen-active rule in
         style.css) -- no redundant second way to close it. -->
    <button type="button" class="btn btn-outline-secondary btn-sm" id="pstFullscreenBtn" title="Fullscreen">
      <i class="fa-solid fa-expand me-1"></i><span data-i18n="ect_fullscreen">Fullscreen</span>
    </button>
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
      <!-- 2026-08-26, explicit request: lock this the same way Employment Certificate Template's own
           font dropdown already does -- only TH Sarabun New has Thai glyphs (see that file's own
           comment), the other 6 all carry data-en-only so updateFontFamilyOptions() (this file's
           own port of that same function) can hide them while editing the Thai-language template. -->
      <select class="form-select form-select-sm" id="pstPropFontFamily" disabled>
        <option value="th_sarabun_new">TH Sarabun New</option>
        <option value="dejavu_sans" data-en-only="1">DejaVu Sans</option>
        <option value="dejavu_sans_mono" data-en-only="1">DejaVu Sans Mono</option>
        <option value="dejavu_serif" data-en-only="1">DejaVu Serif</option>
        <option value="helvetica" data-en-only="1">Helvetica</option>
        <option value="times_new_roman" data-en-only="1">Times New Roman</option>
        <option value="courier" data-en-only="1">Courier</option>
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
        <!-- 2026-08-26, explicit request: "ช่องที่ใส่คำลายน้ำให้ปรับจาก textbox เป็น textarea" -->
        <textarea class="form-control form-control-sm mt-2 d-none" id="pstWatermarkText" rows="2" placeholder="SAMPLE"></textarea>
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
     verbatim on both footers -- no new CSS needed.
     2026-08-26, same-day follow-up: "ปุ่ม Save และ Preview อยากให้มาอยู่ที่ modal footer" --
     `#pstDesignFooterAnchor` marks where this footer normally sits; entering fullscreen relocates THIS
     footer (by its new `#pstDesignFooter` id) into `#pstFullscreenModalFooter`, leaving it back here on
     exit -- same anchor-based relocation pattern `#pstEditorContentAnchor` already uses. -->
<div id="pstDesignFooterAnchor"></div>
<div class="pst-editor-footer" id="pstDesignFooter">
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
    <!-- 2026-08-26, explicit request: "ตรง Assign To ช่วยปรับให้ใช้งานง่ายขึ้นไม่ซับซ้อน" -- the 3
         always-visible checkbox columns (Departments/Teams/Employees, each with its own search box
         and Select-All) stayed exactly as-is UNDERNEATH, but are now hidden by default behind a
         simple 2-choice switch: "Everyone" (the common case -- no scoping at all) vs "Specific
         selection" (reveals the same 3 columns as before). Purely a visibility/UX layer -- the
         underlying checkboxes/collectAssignments()/validateAssignments() are all unchanged; choosing
         "Everyone" just clears every checkbox first so the saved payload is genuinely unscoped
         (assignments: []), the exact same meaning "leave all empty" already had. -->
    <div class="pst-assign-mode-switch mb-3">
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="pstAssignMode" id="pstAssignModeEveryone" value="everyone" checked>
        <label class="form-check-label" for="pstAssignModeEveryone"><i class="fa-solid fa-globe me-1"></i><span data-i18n="pst_assign_mode_everyone">Everyone (company default)</span></label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="pstAssignMode" id="pstAssignModeSpecific" value="specific">
        <label class="form-check-label" for="pstAssignModeSpecific"><i class="fa-solid fa-list-check me-1"></i><span data-i18n="pst_assign_mode_specific">Specific departments/teams/employees</span></label>
      </div>
    </div>
    <div class="text-secondary small pst-assign-hint mb-3 d-none" id="pstAssignHint" data-i18n="pst_assign_hint">If any are selected, this template only applies to that department/team/employee, with the most specific match winning (employee &gt; team &gt; department). Assigning a department/team/employee that's already actively assigned to another template will show an error naming the conflict.</div>
    <div class="pst-assign-columns d-none" id="pstAssignColumns">
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

</div><!-- /#pstEditorContent -->
