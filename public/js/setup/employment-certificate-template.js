/**
 * Employment Certificate Template designer.
 *
 * 2026-08-24 v3 (List+Modal restructure, explicit follow-up: "ตัว Template เป็น List ตารางให้กด View
 * แก้ไข Delete และเปิด Modal ในการเพิ่มหรือการจัดการให้เป็น Format เดียวกันทั้งหมด และมีอีกปุ่มที่ดึง
 * template ที่ระบบมีให้มาใช้ โดยที่สามารถกด Preview ดูก่อนที่จะดึงมาได้") -- the canvas is no longer
 * always inline on the page. It now lives inside #ectEditModal (fullscreen), opened by the list's
 * Edit row action or automatically right after creating a new template (resolved via
 * AskUserQuestion: fullscreen modal, not an inline list<->canvas view swap). "Import a system
 * template" is the SAME preset picker already in #ectNewTemplateModal, just with a per-preset
 * Preview button added (also resolved via AskUserQuestion -- no separate import mechanism).
 *
 * State is 3-tier: `templateList` (DataTable's own data, for the list), `currentTemplate` +
 * `elements[]` (the ONE template open in the edit modal, or null), and `dirty` (now scoped to the
 * edit modal's lifetime -- guards against losing canvas work on close, not on switching the outer
 * language tab, since the canvas is no longer visible behind that tab switch any more).
 *
 * 2026-08-25, later update: #ectEditModal itself is GONE -- the editor is now a standalone page
 * (employment-certificate/edit/{pair_key}, opened in a new browser tab, explicit request: "หน้าแก้ไข
 * ให้เปลี่ยนเป็นการเปิด Tab ใหม่ เพื่อให้การจัดการมีพื้นที่มากขึ้น"). Every mention of "the modal"/
 * "#ectEditModal" below this point in the file is describing history at the time it was written --
 * see bootstrapEditorPage()/goBackToList() for the current page-based equivalents.
 *
 * 2026-08-25, TH/EN-tabs unification (explicit request: a pair now edits BOTH languages in ONE
 * modal, "การกดสลับไปมาระหว่างไทยกับอังกฤษต้องสิ่งที่แก้อยู่ต้องไม่หาย"). Rather than refactor every one
 * of the ~50 call sites below that read/write the bare `currentTemplate`/`elements`/`dirty`/
 * `logoPath`/`undoStack`/`redoStack` globals, `pairState` holds a snapshot PER LANGUAGE and
 * switchToLangTab() just swaps which snapshot those globals currently point to -- a shallow
 * reference swap, not a deep clone, so every existing mutation site keeps working completely
 * unchanged (it's still just editing "the current template"), while the language NOT being actively
 * edited keeps its in-progress state parked in `pairState[otherLang]` untouched. updateSaveHint()
 * (already called after every single mutation in this file) writes `dirty` back into
 * `pairState[activeLang].dirty` as its one integration point -- see its own comment below.
 */
(function () {
let currentLanguage = 'th';
let currentTemplate = null; // the template row currently open in #ectEditModal, or null
let elements = [];
let logoPath = null;
// 2026-08-24, explicit request: multi-select (Ctrl+Click), Group/Ungroup, Layers panel -- array of
// currently-selected element keys (was a single `selectedKey`). Selecting/deselecting a grouped
// element always selects/deselects its whole group together -- see selectionGroupKeys(). Font/
// alignment ribbon controls only ever edit when exactly one key is selected (singleSelectedKey());
// Delete/Group/Ungroup work on the whole array.
let selectedKeys = [];
let gridOn = true; // grid toggle (explicit request: "มีปุ่ม เปิด/แสดง ตารางด้วยครับ")
let dirty = false;
let elementKeyCounter = 0;
let textModalKey = null;
let fieldTypesCache = [];
let presetCache = [];
let imageLibraryCache = [];
let chosenPreset = 'blank';
let tb_ect_template;
// `modalMode` decides what #ectCreateTemplateBtn (in the shared preset gallery modal) actually DOES
// once a preset is chosen -- 'create' (list toolbar's "+ Template", brand new unpaired pair),
// 'create-in-pair' (the empty-state's "start from a preset" for a pair's missing language, carries
// the pair_key via #ectNewTemplatePairKey so the new row joins the ALREADY-OPEN pair), or 'replace'
// (#ectChangePresetBtn -- explicit request, resolved via AskUserQuestion: re-applies a different
// preset's layout to what's open right now, destructive, confirmed before overwriting).
let modalMode = 'create';
/** One language's working state inside #ectEditModal -- see the docblock above for why this exists
 *  instead of refactoring every existing global-variable call site. */
function freshLangSlot() {
    return { template: null, elements: [], dirty: false, logoPath: null, undoStack: [], redoStack: [], currentPageNumber: 1, pageCount: 1, assignments: [] };
}
let pairState = { th: freshLangSlot(), en: freshLangSlot() };
let activeLang = 'th'; // which language tab is currently shown/edited inside #ectEditModal
let currentPairKey = null; // the pair currently open in #ectEditModal, or null
// 2026-08-25, explicit request: "รองรับการมีหลายๆหน้า โดยที่มีปุ่มให้เลือกเพิ่มหรือลด" -- which page is
// currently shown/edited, and how many exist, for whichever language is active (swapped in/out of
// pairState[activeLang] on tab switch exactly like elements/dirty above). `pageCount` is NOT
// persisted as its own value server-side -- it's inferred from the highest page_number among loaded
// elements on load, so an intentionally-added trailing BLANK page only survives a reload once at
// least one element has been placed on it (documented limitation, see addPage()'s own comment).
let currentPageNumber = 1;
let pageCount = 1;
// 2026-08-25, explicit request: "เพิ่ม undo redo ด้วยครับ พร้อมทั้ง ctrl Z ctrl shift z" -- these two
// are swapped in/out of `pairState[activeLang]` on tab switch exactly like `elements`/`dirty` above.
let undoStack = [];
let redoStack = [];
const UNDO_LIMIT = 50;
// Ctrl+C/Ctrl+V (one of the "Function อื่นที่ควรเพิ่ม" additions, alongside Ctrl+D duplicate and
// arrow-key nudge) -- deliberately NOT part of pairState/undo: a clipboard is meant to survive
// switching tabs/selection, closing the text modal, etc., same as copy/paste anywhere else.
let clipboardElements = [];
// 2026-08-25, explicit request: "เพิ่ม Function Zoom in Zoom out ได้ โดยเลือกเป็น % และให้ Reset กลับมาที่
// 100% ได้" -- purely a CSS transform:scale() on #ectPage (see applyZoom()), never touches the
// underlying percentage-based element data, so it's independent of pairState/undo entirely.
let zoomPct = 100;
const ZOOM_LEVELS = [25, 50, 75, 100, 125, 150, 200, 300];
// 2026-08-25, explicit request: "การเลือกขอบกระดาษให้เป็น dropdown เลือกแบบ word ครับ เลือกจากตัวอย่าง" --
// named presets matching Word's own Page Layout > Margins picker (mm values chosen to fit this
// project's own default of 15mm as "Normal", the rest scaled reasonably around it).
const MARGIN_PRESETS = [
    { code: 'narrow', mm: 8, labelKey: 'ect_margin_narrow' },
    { code: 'normal', mm: 15, labelKey: 'ect_margin_normal' },
    { code: 'moderate', mm: 20, labelKey: 'ect_margin_moderate' },
    { code: 'wide', mm: 30, labelKey: 'ect_margin_wide' },
];
// 2026-08-25, explicit request: "Company Logo ที่ Upload ในหน้า Template ให้ตัดออกเลยครับ เหลือแค่ Form
// ให้ upload image เพื่อดึงมาใช้งาน" -- the per-template logo upload control is gone from this
// designer; the "Company Logo" canvas element now always resolves from the Company Profile's own
// logo (fetched once here, company-wide, not per-template) unless an OLDER template still has its
// own logo_path set from before this change (see resolveElementImageUrl()'s own priority comment).
let companyLogoPath = null;
// 2026-08-26, explicit request: "เพิ่มให้แนบลายเซ็นต์ Authorized Signatory Name...และเพิ่มใน Item ในการ
// จัดการ Template" -- company-wide only (no per-template override), fetched alongside the logo above.
let companySignaturePath = null;

// 2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" -- MUST stay
// byte-identical to EmploymentCertificateRenderer::PAGE_SIZES_MM (the canvas and the PDF renderer
// share this exact coordinate space for true WYSIWYG, no unit conversion anywhere).
const PAGE_SIZES_MM = {
    A3: [297, 420], A4: [210, 297], A5: [148, 210], B4: [250, 353], B5: [176, 250],
    Letter: [215.9, 279.4], Legal: [215.9, 355.6], Tabloid: [279.4, 431.8],
    Executive: [184.15, 266.7], Statement: [139.7, 215.9],
};
function pageDimensionsMm(pageSize, orientation) {
    const dims = PAGE_SIZES_MM[pageSize] || PAGE_SIZES_MM.A4;
    return orientation === 'landscape' ? [dims[1], dims[0]] : [dims[0], dims[1]];
}

function newElementKey() {
    elementKeyCounter += 1;
    return 'el_' + elementKeyCounter + '_' + Date.now();
}

function escapeHtmlEct(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}

function clampEct(v, min, max) {
    if (max < min) max = min;
    return Math.max(min, Math.min(max, v));
}

function updateSaveHint() {
    // Write-through into pairState -- this is the ONE integration point that keeps the tab dirty-
    // dots and the "any unsaved changes across either language" close-guard correct, since every
    // mutation in this whole file already ends with `dirty = true; updateSaveHint();` (or `dirty =
    // false;` after a successful save) -- no other call site needed to change.
    if (pairState[activeLang]) pairState[activeLang].dirty = dirty;
    updateLangTabsUI();
    // 2026-08-25 follow-up: "หน้า Design กับหน้า Assign To ต้องการให้มีปุ่ม Save แยก Tab" -- there are now
    // TWO footers (one per tab), both using the `.ect-save-hint` CLASS (not a unique id).
    $('.ect-save-hint').text(dirty ? (langData['ect_unsaved_hint'] || 'Unsaved changes — click Save.') : '');
    scheduleAutoSaveIfEnabled();
}
// 2026-08-26, explicit request: "เพิ่มให้ติ๊กได้ว่าต้องการให้ Auto Save" -- direct port of
// PayslipTemplateModel's own scheduleAutoSaveIfEnabled()/savePstTemplate() pairing (see that file's
// own comment for the reasoning).
let ectAutoSaveTimer = null;
function scheduleAutoSaveIfEnabled() {
    if (!currentTemplate || !currentTemplate.id) return;
    if (!$('#ectAutoSaveSwitch').is(':checked')) return;
    if (!dirty) return;
    clearTimeout(ectAutoSaveTimer);
    ectAutoSaveTimer = setTimeout(function () { saveEctTemplate(true); }, 2000);
}

/* ---------- Undo / Redo (explicit request: "เพิ่ม undo redo ด้วยครับ พร้อมทั้ง ctrl Z ctrl shift z") --
   snapshot-based: pushUndo() is called BEFORE a mutation begins (once per discrete action or once per
   drag/resize/continuous-edit gesture, not per mousemove/keystroke tick -- see each call site for how
   that's kept to one push per gesture). ---------- */
function cloneElementsList(arr) {
    return JSON.parse(JSON.stringify(arr));
}
function pushUndo() {
    undoStack.push(cloneElementsList(elements));
    if (undoStack.length > UNDO_LIMIT) undoStack.shift();
    redoStack = [];
    updateUndoRedoUI();
}
function performUndo() {
    if (!undoStack.length) return;
    redoStack.push(cloneElementsList(elements));
    elements = undoStack.pop();
    selectedKeys = selectedKeys.filter(k => elements.some(e => e.key === k));
    renderCanvas();
    dirty = true;
    updateSaveHint();
    updateUndoRedoUI();
}
function performRedo() {
    if (!redoStack.length) return;
    undoStack.push(cloneElementsList(elements));
    elements = redoStack.pop();
    selectedKeys = selectedKeys.filter(k => elements.some(e => e.key === k));
    renderCanvas();
    dirty = true;
    updateSaveHint();
    updateUndoRedoUI();
}
function updateUndoRedoUI() {
    $('#ectUndoBtn').prop('disabled', !undoStack.length);
    $('#ectRedoBtn').prop('disabled', !redoStack.length);
}
$(document).on('click', '#ectUndoBtn', performUndo);
$(document).on('click', '#ectRedoBtn', performRedo);
// Continuous ribbon inputs (font size number, color picker) fire many 'input' events per gesture --
// pushing undo on every one of them would flood the stack with tiny, useless steps. Instead push
// ONCE per gesture: the first 'input'/'mousedown' since the control gained focus or the selection
// changed, tracked by this flag (reset in updateSelectionUI(), which already runs on every
// selection change, and on blur below).
let ribbonEditSessionActive = false;
function ribbonPushUndoOnce() {
    if (!ribbonEditSessionActive) {
        pushUndo();
        ribbonEditSessionActive = true;
    }
}
$(document).on('blur', '#ectPropFontSize, #ectPropFontColor', function () { ribbonEditSessionActive = false; });

/* ---------- Zoom (explicit request: "เพิ่ม Function Zoom in Zoom out ได้ โดยเลือกเป็น % และให้ Reset กลับ
   มาที่ 100% ได้") -- CSS transform:scale() on #ectPage; offsetWidth/offsetHeight reflect the page's
   UNSCALED layout size even while transformed (transform is paint-only, doesn't change layout box
   size), so reading them and multiplying by the scale gives the exact reserved footprint the scroll
   container needs -- no separate px<->mm conversion required. ---------- */
function applyZoom() {
    const $page = $('#ectPage');
    if (!$page.length) return;
    const scale = zoomPct / 100;
    $page.css({ transform: `scale(${scale})`, transformOrigin: 'top left' });
    const naturalW = $page[0].offsetWidth;
    const naturalH = $page[0].offsetHeight;
    $('#ectZoomStage').css({ width: (naturalW * scale) + 'px', height: (naturalH * scale) + 'px' });
    $('#ectZoomSelect').val(String(zoomPct));
}
function setZoom(pct) {
    zoomPct = clampEct(pct, 25, 300);
    applyZoom();
}
$(document).on('change', '#ectZoomSelect', function () { setZoom(parseInt($(this).val(), 10) || 100); });
$(document).on('click', '#ectZoomInBtn', function () {
    const next = ZOOM_LEVELS.find(l => l > zoomPct);
    setZoom(next || 300);
});
$(document).on('click', '#ectZoomOutBtn', function () {
    const prev = ZOOM_LEVELS.slice().reverse().find(l => l < zoomPct);
    setZoom(prev || 25);
});
$(document).on('click', '#ectZoomResetBtn', function () { setZoom(100); });

/* ---------- Pan (explicit request: "ตรงพื้นที่ว่าง ให้สามารถลากเพื่อดูซ้ายขวาได้") -- dragging EMPTY
   canvas area (not an element) drag-scrolls .ect-canvas-scroll. Reuses the existing "click empty
   #ectPage area clears selection" mousedown handler further below (both live on the same
   `e.target === this` branch) rather than adding a second, competing listener. ---------- */
function beginCanvasPan(e) {
    const $scroll = $('.ect-canvas-scroll');
    if (!$scroll.length) return;
    const startX = e.clientX, startY = e.clientY;
    const startLeft = $scroll.scrollLeft(), startTop = $scroll.scrollTop();
    $scroll.addClass('ect-panning');
    function onMove(ev) {
        $scroll.scrollLeft(startLeft - (ev.clientX - startX));
        $scroll.scrollTop(startTop - (ev.clientY - startY));
    }
    function onUp() {
        $(document).off('mousemove.ectPan mouseup.ectPan');
        $scroll.removeClass('ect-panning');
    }
    $(document).on('mousemove.ectPan', onMove).on('mouseup.ectPan', onUp);
}

/* ---------- Page-margin guide (explicit request: "เพิ่มให้ตั้งค่าขอบกระดาษได้ด้วยครับ") -- a VISUAL
   PLACEMENT GUIDE ONLY, never sent to the PDF renderer (see the migration's own comment on
   `margin_mm`) -- purely a dashed inset rectangle, same spirit as the grid overlay. Re-injected into
   #ectPage on every renderCanvas() call since that empties the element out along with everything
   else (see the view markup's own comment on why it isn't declared there). ---------- */
function applyMarginGuide() {
    const $page = $('#ectPage');
    if (!$page.length) return;
    $page.find('#ectMarginGuide').remove();
    const marginMm = parseFloat($('#ectMarginInput').val()) || 0;
    if (marginMm <= 0) return;
    const [pageWmm, pageHmm] = pageDimensionsMm($('#ectPageSizeSelect').val() || 'A4', $('#ectOrientationSelect').val() || 'portrait');
    const leftPct = (marginMm / pageWmm) * 100;
    const topPct = (marginMm / pageHmm) * 100;
    const $guide = $('<div class="ect-margin-guide" id="ectMarginGuide"></div>').css({
        left: leftPct + '%', top: topPct + '%',
        right: leftPct + '%', bottom: topPct + '%'
    });
    $page.prepend($guide);
}
function updateMarginDropdownLabel() {
    const mm = parseFloat($('#ectMarginInput').val()) || 0;
    const preset = MARGIN_PRESETS.find(p => p.mm === mm);
    $('#ectMarginDropdownLabel').text(preset
        ? (langData[preset.labelKey] || preset.code)
        : `${langData['ect_margin_custom'] || 'Custom'} (${mm}mm)`);
    $('.ect-margin-option').removeClass('active');
    if (preset) $(`.ect-margin-option[data-value="${mm}"]`).addClass('active');
}
$(document).on('input', '#ectMarginInput', function () {
    applyMarginGuide();
    updateMarginDropdownLabel();
    dirty = true;
    updateSaveHint();
});
// 2026-08-25, explicit request: "การเลือกขอบกระดาษให้เป็น dropdown เลือกแบบ word ครับ เลือกจากตัวอย่าง" --
// #ectMarginInput (the real, persisted value) stays the single source of truth; picking a preset
// here just sets it and re-fires the same 'input' handler the field already has, so nothing else in
// this file needs to know a dropdown was involved at all.
$(document).on('click', '.ect-margin-option', function (e) {
    e.preventDefault();
    $('#ectMarginInput').val($(this).data('value')).trigger('input');
});

function emptyElementBase() {
    return {
        font_size: 14, font_family: 'th_sarabun_new', font_color: '#000000',
        text_align: 'left', font_weight: 'normal', font_style: 'normal', text_decoration: 'none',
        group_key: null, page_number: currentPageNumber, is_visible: true
    };
}
// 2026-08-25, explicit request: "เพิ่มตัวเลือก font สัก 10 font ครับ" -- CSS stacks for all 7 font
// codes; only TH Sarabun New/DejaVu Sans/DejaVu Sans Mono/DejaVu Serif have real bundled TTFs (see
// the @font-face rules at the top of style.css) -- Helvetica/Times New Roman/Courier fall back to
// the equivalent near-universally-installed SYSTEM font (Arial/Times New Roman/Courier New) since
// there's no license to bundle those files, same reasoning as EmploymentCertificateRenderer's own
// comment on FONT_FAMILY_CSS.
const FONT_FAMILY_CSS_STACK = {
    th_sarabun_new: "'TH Sarabun New', sans-serif",
    dejavu_sans: "'DejaVu Sans', sans-serif",
    dejavu_sans_mono: "'DejaVu Sans Mono', monospace",
    dejavu_serif: "'DejaVu Serif', serif",
    helvetica: "Helvetica, Arial, sans-serif",
    times_new_roman: "'Times New Roman', Times, serif",
    courier: "'Courier New', Courier, monospace"
};

/* ---------- Field palette (click OR native drag-and-drop onto the canvas), grouped by category ---------- */
function paletteIcon(ft) {
    if (ft.element_type === 'image') return 'fa-image';
    if (ft.code === 'static_text') return 'fa-font';
    return 'fa-tag';
}
const PALETTE_GROUP_META = {
    company: { icon: 'fa-building', labelKey: 'ect_group_company' },
    employee: { icon: 'fa-id-card', labelKey: 'ect_group_employee' },
    document: { icon: 'fa-file-lines', labelKey: 'ect_group_document' }
};
const PALETTE_GROUP_ORDER = ['company', 'employee', 'document'];
// 2026-08-25, explicit request: "ส่วน Add to Canvas สามารถปรับให้สวยขึ้นและดูง่ายขึ้นได้อีกไหม" --
// replaced the plain full-width outline buttons (one per row) with a 2-column grid of chip cards
// (icon-in-circle + label), same visual language as .ect-preset-card elsewhere in this designer, so
// the whole left rail reads as one consistent design instead of two different button styles.
function renderPalette() {
    const $wrap = $('#ectFieldPalette').empty();
    const groups = {};
    fieldTypesCache.forEach(ft => {
        const g = ft.field_group || 'document';
        (groups[g] = groups[g] || []).push(ft);
    });
    PALETTE_GROUP_ORDER.filter(g => groups[g] && groups[g].length).forEach(g => {
        const meta = PALETTE_GROUP_META[g] || { icon: 'fa-tag', labelKey: '' };
        const groupLabel = langData[meta.labelKey] || g;
        const $group = $(`
            <div class="ect-palette-group">
                <div class="ect-palette-group-title"><i class="fa-solid ${meta.icon} me-1"></i>${escapeHtmlEct(groupLabel)}</div>
                <div class="ect-palette-grid"></div>
            </div>
        `);
        const $grid = $group.find('.ect-palette-grid');
        groups[g].forEach(ft => {
            const label = currentLang === 'th' ? ft.name_th : ft.name_en;
            $grid.append(`
                <button type="button" draggable="true" class="ect-palette-chip" data-code="${ft.code}" data-element-type="${ft.element_type}" title="${escapeHtmlEct(label)}">
                    <span class="ect-palette-chip-icon"><i class="fa-solid ${paletteIcon(ft)}"></i></span>
                    <span class="ect-palette-chip-label">${escapeHtmlEct(label)}</span>
                </button>
            `);
        });
        $wrap.append($group);
    });
}
function addElementFromPalette(code, elementType, posX, posY) {
    if (!currentTemplate) return;
    // 2026-08-25, real bug found and fixed: "ใน 1 object ใน Add to Canvas ดึงได้แค่ครั้งเดียว ลากรอบที่ 2
    // ไม่ลง ให้ลงได้หลายๆ item ใน item เดียวกัน ซ้ำก็ช่าง" -- company_logo used to look for an ALREADY-
    // PLACED company_logo element ANYWHERE across every page and just re-select it instead of adding
    // a new one, so dragging it a 2nd time (e.g. onto a different page) silently did nothing visible
    // (no new element landed, focus just jumped back to whichever one already existed). No other
    // palette item had this restriction. Removed entirely -- every palette item, including
    // company_logo, now always creates a brand-new element on drop/click, same as every other field;
    // duplicates (even multiple logos on the same page) are explicitly fine per this request.
    let el;
    if (elementType === 'image') {
        el = Object.assign(emptyElementBase(), {
            key: newElementKey(), id: null, element_type: 'image', field_key: code, image_asset_id: null, content: null,
            pos_x_pct: clampEct(posX, 0, 80), pos_y_pct: clampEct(posY, 0, 88), width_pct: 20, height_pct: 10
        });
    } else {
        el = Object.assign(emptyElementBase(), {
            key: newElementKey(), id: null, element_type: 'text', field_key: null, image_asset_id: null,
            content: code === 'static_text' ? '' : `{{${code}}}`,
            pos_x_pct: clampEct(posX, 0, 60), pos_y_pct: clampEct(posY, 0, 94), width_pct: 40, height_pct: 6
        });
    }
    pushUndo();
    elements.push(el);
    renderCanvas();
    selectElement(el.key);
    if (el.element_type === 'text' && el.content === '') {
        openTextModal(el.key);
    }
    dirty = true;
    updateSaveHint();
}
function addImageAssetElement(assetId, posX, posY) {
    if (!currentTemplate) return;
    const el = Object.assign(emptyElementBase(), {
        key: newElementKey(), id: null, element_type: 'image', field_key: null, image_asset_id: Number(assetId), content: null,
        pos_x_pct: clampEct(posX, 0, 80), pos_y_pct: clampEct(posY, 0, 85), width_pct: 20, height_pct: 15
    });
    pushUndo();
    elements.push(el);
    renderCanvas();
    selectElement(el.key);
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '.ect-palette-chip', function () {
    addElementFromPalette($(this).data('code'), $(this).data('element-type'), 10, 10);
});
$(document).on('dragstart', '.ect-palette-chip', function (e) {
    e.originalEvent.dataTransfer.setData('text/plain', JSON.stringify({ code: $(this).data('code'), elementType: $(this).data('element-type') }));
    e.originalEvent.dataTransfer.effectAllowed = 'copy';
});
$('#ectPage').on('dragover', function (e) {
    e.preventDefault();
    if (e.originalEvent.dataTransfer) e.originalEvent.dataTransfer.dropEffect = 'copy';
    $(this).addClass('ect-drop-target');
});
$('#ectPage').on('dragleave', function () {
    $(this).removeClass('ect-drop-target');
});
$('#ectPage').on('drop', function (e) {
    e.preventDefault();
    $(this).removeClass('ect-drop-target');
    const raw = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.getData('text/plain') : '';
    if (!raw) return;
    let payload;
    try { payload = JSON.parse(raw); } catch (err) { return; }
    const pageRect = this.getBoundingClientRect();
    const xPct = (e.originalEvent.clientX - pageRect.left) / pageRect.width * 100;
    const yPct = (e.originalEvent.clientY - pageRect.top) / pageRect.height * 100;
    addElementFromPalette(payload.code, payload.elementType, xPct, yPct);
});

/* ---------- Canvas rendering ---------- */
function findElement(key) {
    return elements.find(e => e.key === key);
}
// 2026-08-25, explicit request: "การ Double Click เพื่อ Edit text ให้แก้ได้เฉพาะ Freetext และ paragraft
// ตัวที่เป็นข้อมูลที่จะต้องดึงมาจากฐานข้อมูลให้ไม่สามารถกดแก้ไขได้" -- content-pattern heuristic, not a
// separately-tracked flag: an element whose content is EXACTLY one `{{token}}` with nothing else
// around it was placed by clicking a specific data field in the palette (addElementFromPalette()
// sets content to exactly that for every non-static_text code) and is locked. Free text/paragraph
// content -- empty, plain text, or a token embedded in a longer sentence (the classic/modern/minimal
// presets' own body paragraphs) -- still passes through untouched and stays editable. Deriving this
// from content alone (rather than a stored flag) means it also works correctly for templates saved
// before this feature existed, with no migration needed.
function isBoundFieldElement(el) {
    if (el.element_type !== 'text') return false;
    return /^\{\{[a-z_]+\}\}$/.test((el.content || '').trim());
}
// 2026-08-25, explicit request: "การลากรูป Logo ไปลงในหน้าตั้งค่า อยากให้ขึ้นเป็นรูป Logo จริงๆ เลยรวมถึง
// การดึงรูปที่ Upload ไปใช้งานด้วย" -- resolves an actual <img> URL for an image element instead of
// always showing the generic icon placeholder: `company_logo` now always comes from the Company
// Profile logo (companyLogoPath, fetched once on modal open -- see the per-template Company Logo
// upload having been removed, "Company Logo ที่ Upload ในหน้า Template ให้ตัดออกเลยครับ"), and a
// reusable-library image resolves from imageLibraryCache by id (populated whenever the edit modal
// opens, not just when the Image Library modal itself has been opened, so this works even for an
// image element that was already on the canvas from a previous save).
function resolveElementImageUrl(el) {
    if (el.element_type !== 'image') return null;
    if (el.field_key === 'company_logo') {
        // Same priority as EmploymentCertificateRenderer::resolveTemplateOrCompanyLogo() server-side
        // -- an OLD template that still has its own logo_path from before the per-template upload
        // was removed keeps showing IT here (matches what the PDF will actually render), otherwise
        // falls back to the Company Profile logo.
        const path = logoPath || companyLogoPath;
        return path ? `${BASE_URL}/${path}` : null;
    }
    if (el.field_key === 'company_signature') {
        return companySignaturePath ? `${BASE_URL}/${companySignaturePath}` : null;
    }
    if (el.image_asset_id) {
        const asset = imageLibraryCache.find(a => Number(a.id) === Number(el.image_asset_id));
        return asset ? `${BASE_URL}/${asset.file_path}` : null;
    }
    return null;
}
// 2026-08-25, explicit request: "เพิ่ม option การเพิ่มตาราง" -- renders the same JSON grid structure
// EmploymentCertificateRenderer::renderElementHtml() renders server-side (rows/cols/border color+
// width/cell text), so the canvas preview and the actual PDF stay visually consistent.
function tableElementBodyHtml(el) {
    let data;
    try { data = JSON.parse(el.content || '{}'); } catch (e) { data = {}; }
    const rows = Number(data.rows) || 1;
    const cols = Number(data.cols) || 1;
    const borderColor = data.border_color || '#000000';
    const borderWidth = Number(data.border_width) || 1;
    const cells = Array.isArray(data.cells) ? data.cells : [];
    let html = `<table style="width:100%;height:100%;border-collapse:collapse;">`;
    for (let r = 0; r < rows; r++) {
        html += '<tr>';
        for (let c = 0; c < cols; c++) {
            const text = escapeHtmlEct((cells[r] && cells[r][c]) || '').replace(/\n/g, '<br>');
            html += `<td style="border:${borderWidth}px solid ${borderColor};padding:2px 4px;">${text}</td>`;
        }
        html += '</tr>';
    }
    return html + '</table>';
}
function elementHtml(el) {
    const isImage = el.element_type === 'image';
    const isShape = el.element_type === 'shape';
    const isTable = el.element_type === 'table';
    let body;
    if (isImage) {
        const imgUrl = resolveElementImageUrl(el);
        body = imgUrl ? `<img src="${imgUrl}" alt="">` : '<i class="fa-solid fa-image"></i>';
    } else if (isShape) {
        body = '';
    } else if (isTable) {
        body = tableElementBodyHtml(el);
    } else {
        body = escapeHtmlEct(el.content).replace(/\n/g, '<br>');
    }
    // 2026-08-27, explicit request: the lock badge icon on bound-field elements was unnecessary
    // clutter -- removed. isBoundFieldElement() itself still gates double-click-to-edit (unchanged),
    // this only drops the visual badge.
    let typeClass = 'ect-el-text';
    if (isImage) typeClass = 'ect-el-image';
    else if (isShape) typeClass = `ect-el-shape ect-el-shape-${el.field_key || 'rectangle'}`;
    else if (isTable) typeClass = 'ect-el-table';
    return `
        <div class="ect-el ${typeClass}" data-key="${el.key}">
            <div class="ect-el-body">${body}</div>
            <div class="ect-quick-delete" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-xmark"></i></div>
            <div class="ect-resize-handle"></div>
        </div>
    `;
}
function applyElementStyle($el, el) {
    $el.css({
        left: el.pos_x_pct + '%', top: el.pos_y_pct + '%',
        width: el.width_pct + '%', height: el.height_pct + '%'
    });
    if (el.element_type === 'shape') {
        // 2026-08-25, explicit request: "สามารถ insert shape ต่างๆ เหมือน Word" -- font_color doubles
        // as fill/border color here (this element type has no separate fill/border fields, same
        // simplification as the server-side renderer); font/text styling doesn't apply to a shape.
        $el.find('.ect-el-body').css({
            backgroundColor: el.font_color || '#000000',
            border: `1px solid ${el.font_color || '#000000'}`,
            boxSizing: 'border-box',
            borderRadius: el.field_key === 'ellipse' ? '50%' : '0'
        });
        return;
    }
    $el.find('.ect-el-body').css({
        fontSize: el.font_size + 'px',
        textAlign: el.text_align,
        fontWeight: el.font_weight === 'bold' ? '700' : '400',
        fontStyle: el.font_style === 'italic' ? 'italic' : 'normal',
        textDecoration: el.text_decoration === 'underline' ? 'underline' : 'none',
        color: el.font_color || '#000000',
        fontFamily: FONT_FAMILY_CSS_STACK[el.font_family] || FONT_FAMILY_CSS_STACK.th_sarabun_new
    });
}
function updateCanvasDimensions() {
    const pageSize = $('#ectPageSizeSelect').val() || 'A4';
    const orientation = $('#ectOrientationSelect').val() || 'portrait';
    const [w, h] = pageDimensionsMm(pageSize, orientation);
    $('#ectPage').css({ width: w + 'mm', height: h + 'mm' });
    applyZoom();
}

/* ---------- Multi-page (explicit request: "รองรับการมีหลายๆหน้า โดยที่มีปุ่มให้เลือกเพิ่มหรือลด") --
   `elements` holds ALL pages at once, each tagged with page_number; these functions just change
   which page_number is "current" (what's shown/edited/saved-as-new-elements-onto) and how many
   pages exist. Position/size math is completely unaffected -- still percentage-of-ONE-page. ---------- */
function syncPageStateToPairState() {
    if (pairState[activeLang]) {
        pairState[activeLang].currentPageNumber = currentPageNumber;
        pairState[activeLang].pageCount = pageCount;
    }
}
function updatePageNavUI() {
    $('#ectPageNavLabel').text(`${langData['ect_page_label'] || 'Page'} ${currentPageNumber} / ${pageCount}`);
    $('#ectPagePrevBtn').prop('disabled', currentPageNumber <= 1);
    $('#ectPageNextBtn').prop('disabled', currentPageNumber >= pageCount);
    $('#ectPageRemoveBtn').prop('disabled', pageCount <= 1);
}
function goToPage(pageNumber) {
    currentPageNumber = clampEct(pageNumber, 1, pageCount);
    selectedKeys = [];
    syncPageStateToPairState();
    renderCanvas();
}
function addPage() {
    pushUndo();
    pageCount += 1;
    goToPage(pageCount);
    dirty = true;
    updateSaveHint();
}
function removeCurrentPage() {
    if (pageCount <= 1) return;
    showConfirm(
        langData['ect_remove_page'] || 'Remove Page',
        langData['ect_remove_page_confirm'] || 'Remove this page and everything on it? This cannot be undone with Ctrl+Z once you leave this page.',
        function () {
            pushUndo();
            const removed = currentPageNumber;
            // Delete this page's elements, then shift every LATER page's elements down by one so
            // page numbers stay contiguous (1..pageCount-1) -- otherwise the gating logic elsewhere
            // that assumes "highest page_number == pageCount" would see a phantom empty page.
            elements = elements.filter(e => (e.page_number || 1) !== removed);
            elements.forEach(e => {
                if ((e.page_number || 1) > removed) e.page_number -= 1;
            });
            pageCount -= 1;
            goToPage(Math.min(removed, pageCount));
            dirty = true;
            updateSaveHint();
        }
    );
}
$(document).on('click', '#ectPagePrevBtn', function () { goToPage(currentPageNumber - 1); });
$(document).on('click', '#ectPageNextBtn', function () { goToPage(currentPageNumber + 1); });
$(document).on('click', '#ectPageAddBtn', addPage);
$(document).on('click', '#ectPageRemoveBtn', removeCurrentPage);
function renderCanvas() {
    const $page = $('#ectPage').empty();
    currentPageElements().forEach(el => {
        // 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว"
        // -- a hidden element is skipped from the canvas entirely (same Photoshop convention: a
        // hidden layer disappears from the canvas, not just dimmed), same as it's skipped from the
        // generated PDF (EmploymentCertificateRenderer::buildHtml()). Still fully listed in the
        // Layers panel below with its eye toggle -- renderLayersPanel() reads currentPageElements()
        // directly, unfiltered, so hiding it here never removes it from that list.
        if (el.is_visible === false) return;
        const $el = $(elementHtml(el));
        $page.append($el);
        applyElementStyle($el, el);
        makeInteractive($el, el);
    });
    applyMarginGuide();
    updateSelectionUI();
    renderLayersPanel();
    updatePageNavUI();
}

/* ---------- Drag / resize (plain mouse events) ---------- */
function makeInteractive($el, el) {
    $el.on('mousedown', function (e) {
        if ($(e.target).hasClass('ect-resize-handle') || $(e.target).closest('.ect-quick-delete').length) return;
        e.preventDefault();
        const additive = e.ctrlKey || e.metaKey;
        if (additive) {
            selectElement(el.key, true);
        } else if (!selectedKeys.includes(el.key)) {
            // Clicking an element that's NOT already part of the current selection replaces the
            // selection (or its whole group) -- clicking one that's ALREADY selected keeps whatever
            // multi-selection is active so it can be dragged together (standard multi-select drag
            // convention: only Ctrl+Click or clicking empty canvas changes what's selected).
            selectElement(el.key, false);
        }
        if (!selectedKeys.length) return;
        const page = document.getElementById('ectPage');
        const pageRect = page.getBoundingClientRect();
        const startX = e.clientX, startY = e.clientY;
        // 2026-08-24, explicit request: dragging one element in a multi-selection (or a group) moves
        // all of them together, each from its OWN recorded start position (not just a shared delta
        // off el, since elements start at different positions).
        const startPositions = {};
        selectedKeys.forEach(k => {
            const se = findElement(k);
            if (se) startPositions[k] = { x: se.pos_x_pct, y: se.pos_y_pct };
        });
        let dragUndoPushed = false;
        function onMove(ev) {
            if (!dragUndoPushed) { pushUndo(); dragUndoPushed = true; }
            const dxPct = (ev.clientX - startX) / pageRect.width * 100;
            const dyPct = (ev.clientY - startY) / pageRect.height * 100;
            selectedKeys.forEach(k => {
                const se = findElement(k);
                const start = startPositions[k];
                if (!se || !start) return;
                se.pos_x_pct = clampEct(start.x + dxPct, 0, 100 - se.width_pct);
                se.pos_y_pct = clampEct(start.y + dyPct, 0, 100 - se.height_pct);
                applyElementStyle($(`.ect-el[data-key="${k}"]`), se);
            });
            dirty = true;
            updateSaveHint();
        }
        function onUp() {
            $(document).off('mousemove.ectDrag mouseup.ectDrag');
        }
        $(document).on('mousemove.ectDrag', onMove).on('mouseup.ectDrag', onUp);
    });

    $el.find('.ect-resize-handle').on('mousedown', function (e) {
        e.stopPropagation();
        e.preventDefault();
        // Resize is always single-element, even if el is part of a group or the current multi-
        // selection -- grabbing a resize handle narrows the selection down to just this one element
        // (bypasses selectElement()'s own group-expansion on purpose).
        selectedKeys = [el.key];
        applySelectionClasses();
        updateSelectionUI();
        renderLayersPanel();
        const page = document.getElementById('ectPage');
        const pageRect = page.getBoundingClientRect();
        const startX = e.clientX, startY = e.clientY;
        const startW = el.width_pct, startH = el.height_pct;
        let resizeUndoPushed = false;
        function onMove(ev) {
            if (!resizeUndoPushed) { pushUndo(); resizeUndoPushed = true; }
            const dwPct = (ev.clientX - startX) / pageRect.width * 100;
            const dhPct = (ev.clientY - startY) / pageRect.height * 100;
            el.width_pct = clampEct(startW + dwPct, 3, 100 - el.pos_x_pct);
            el.height_pct = clampEct(startH + dhPct, 2, 100 - el.pos_y_pct);
            applyElementStyle($el, el);
            dirty = true;
            updateSaveHint();
        }
        function onUp() {
            $(document).off('mousemove.ectResize mouseup.ectResize');
        }
        $(document).on('mousemove.ectResize', onMove).on('mouseup.ectResize', onUp);
    });

    $el.on('dblclick', function () {
        if (el.element_type === 'table') {
            openTableModal(el.key);
            return;
        }
        if (el.element_type !== 'text') return;
        if (isBoundFieldElement(el)) {
            showWarning(langData['ect_field_locked_hint'] || 'This field is bound to data and can\'t be edited directly.');
            return;
        }
        openTextModal(el.key);
    });
}

/* ---------- Selection (multi-select + Group/Ungroup) + ribbon (top toolbar) ---------- */
// Selecting/deselecting a grouped element always acts on its whole group -- once elements are
// Grouped they behave as one unit until Ungrouped (explicit request: "Group รวม layout ได้").
function selectionGroupKeys(key) {
    const el = findElement(key);
    if (!el) return [];
    if (el.group_key) {
        return elements.filter(e => e.group_key === el.group_key).map(e => e.key);
    }
    return [key];
}
function applySelectionClasses() {
    $('.ect-el').removeClass('ect-el-selected');
    selectedKeys.forEach(k => $(`.ect-el[data-key="${k}"]`).addClass('ect-el-selected'));
}
function selectElement(key, additive) {
    const groupKeys = selectionGroupKeys(key);
    if (additive) {
        const allSelected = groupKeys.every(k => selectedKeys.includes(k));
        if (allSelected) {
            selectedKeys = selectedKeys.filter(k => !groupKeys.includes(k));
        } else {
            groupKeys.forEach(k => { if (!selectedKeys.includes(k)) selectedKeys.push(k); });
        }
    } else {
        selectedKeys = groupKeys.slice();
    }
    applySelectionClasses();
    updateSelectionUI();
    renderLayersPanel();
}
function clearSelection() {
    selectedKeys = [];
    applySelectionClasses();
    updateSelectionUI();
    renderLayersPanel();
}
function singleSelectedKey() {
    return selectedKeys.length === 1 ? selectedKeys[0] : null;
}
function updateSelectionUI() {
    const key = singleSelectedKey();
    const el = key ? findElement(key) : null;
    const $fontControls = $('#ectPropFontSize, #ectPropFontFamily, #ectPropFontColor, .ect-align-btn, .ect-style-btn');
    if (!el) {
        $fontControls.prop('disabled', true);
        $('.ect-align-btn, .ect-style-btn').removeClass('active');
        $('#ectRibbonHint').removeClass('d-none');
    } else {
        $fontControls.prop('disabled', false);
        $('#ectRibbonHint').addClass('d-none');
        $('#ectPropFontSize').val(el.font_size);
        $('#ectPropFontFamily').val(el.font_family);
        $('#ectPropFontColor').val(el.font_color);
        $('.ect-align-btn').removeClass('active');
        $(`.ect-align-btn[data-align="${el.text_align}"]`).addClass('active');
        $('.ect-style-btn[data-style="bold"]').toggleClass('active', el.font_weight === 'bold');
        $('.ect-style-btn[data-style="italic"]').toggleClass('active', el.font_style === 'italic');
        $('.ect-style-btn[data-style="underline"]').toggleClass('active', el.text_decoration === 'underline');
    }
    // Delete/Group/Ungroup work off the whole selectedKeys array, independent of the single-element
    // font controls above -- explicit request: "เลือกหลายรายการเพื่อลบ หรือ Group...ungroup ได้".
    $('#ectDeleteElementBtn').prop('disabled', selectedKeys.length === 0);
    $('#ectGroupBtn').prop('disabled', selectedKeys.length < 2);
    const hasGroupedSelection = selectedKeys.some(k => { const e = findElement(k); return e && e.group_key; });
    $('#ectUngroupBtn').prop('disabled', !hasGroupedSelection);
    // A selection change starts a fresh ribbon-edit "session" for the undo-batching described in
    // ribbonPushUndoOnce()'s own comment.
    ribbonEditSessionActive = false;
}
$('#ectPage').on('mousedown', function (e) {
    if (e.target === this) {
        clearSelection();
        beginCanvasPan(e);
    }
});
$(document).on('input', '#ectPropFontSize', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    ribbonPushUndoOnce();
    el.font_size = parseInt($(this).val(), 10) || 14;
    applyElementStyle($(`.ect-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('change', '#ectPropFontFamily', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    pushUndo();
    el.font_family = $(this).val();
    applyElementStyle($(`.ect-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('input', '#ectPropFontColor', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    ribbonPushUndoOnce();
    el.font_color = $(this).val();
    applyElementStyle($(`.ect-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-align-btn', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    pushUndo();
    el.text_align = $(this).data('align');
    $('.ect-align-btn').removeClass('active');
    $(this).addClass('active');
    applyElementStyle($(`.ect-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-style-btn', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    pushUndo();
    const styleType = $(this).data('style');
    if (styleType === 'bold') {
        el.font_weight = el.font_weight === 'bold' ? 'normal' : 'bold';
    } else if (styleType === 'italic') {
        el.font_style = el.font_style === 'italic' ? 'normal' : 'italic';
    } else if (styleType === 'underline') {
        el.text_decoration = el.text_decoration === 'underline' ? 'none' : 'underline';
    }
    $(this).toggleClass('active');
    applyElementStyle($(`.ect-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
function deleteSelectedElements() {
    if (!selectedKeys.length) return;
    pushUndo();
    elements = elements.filter(e => !selectedKeys.includes(e.key));
    selectedKeys = [];
    renderCanvas();
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '#ectDeleteElementBtn', deleteSelectedElements);
$(document).on('click', '.ect-quick-delete', function (e) {
    e.stopPropagation();
    pushUndo();
    const key = $(this).closest('.ect-el').data('key');
    elements = elements.filter(el => el.key !== key);
    selectedKeys = selectedKeys.filter(k => k !== key);
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
/* ---------- Duplicate (Ctrl+D) / Copy+Paste (Ctrl+C/Ctrl+V) / arrow-key nudge -- 2026-08-25,
   explicit request: "มี Function อื่นที่ควรเพิ่ม เพื่อให้ใช้งานสะดวกที่สุดไหมครับ ช่วยเพิ่มให้หน่อยที่คิดว่า
   จำเป็นต้องมี" -- these 3 were judged the highest-value, lowest-risk additions alongside undo/redo:
   Ctrl+D for "duplicate what's selected right now", Ctrl+C/V for "copy now, paste possibly later or
   more than once", arrow keys for pixel-perfect nudging that's awkward to do by mouse-drag alone.
   Both Duplicate and Paste share the same clone-with-fresh-group-keys logic (a grouped source stays
   grouped together in the copy, just as a NEW group distinct from the original). ---------- */
function cloneElementsWithNewGroupKeys(sourceElements, offsetPct) {
    const groupMap = {};
    return sourceElements.map(src => {
        let newGroupKey = null;
        if (src.group_key) {
            if (!groupMap[src.group_key]) groupMap[src.group_key] = generateGroupKey();
            newGroupKey = groupMap[src.group_key];
        }
        return Object.assign({}, src, {
            key: newElementKey(), id: null,
            pos_x_pct: clampEct(src.pos_x_pct + offsetPct, 0, 100 - src.width_pct),
            pos_y_pct: clampEct(src.pos_y_pct + offsetPct, 0, 100 - src.height_pct),
            group_key: newGroupKey
        });
    });
}
function duplicateSelectedElements() {
    if (!selectedKeys.length) return;
    pushUndo();
    const sources = selectedKeys.map(k => findElement(k)).filter(Boolean);
    const copies = cloneElementsWithNewGroupKeys(sources, 3);
    elements = elements.concat(copies);
    selectedKeys = copies.map(e => e.key);
    renderCanvas();
    applySelectionClasses();
    dirty = true;
    updateSaveHint();
}
function copySelectedElements() {
    if (!selectedKeys.length) return;
    clipboardElements = selectedKeys.map(k => findElement(k)).filter(Boolean).map(e => Object.assign({}, e));
}
function pasteClipboardElements() {
    if (!clipboardElements.length || !currentTemplate) return;
    pushUndo();
    const copies = cloneElementsWithNewGroupKeys(clipboardElements, 3);
    elements = elements.concat(copies);
    selectedKeys = copies.map(e => e.key);
    renderCanvas();
    applySelectionClasses();
    dirty = true;
    updateSaveHint();
}
function nudgeSelectedElements(direction, big) {
    if (!selectedKeys.length) return;
    pushUndo();
    const step = big ? 2 : 0.5;
    let dx = 0, dy = 0;
    if (direction === 'ArrowUp') dy = -step;
    else if (direction === 'ArrowDown') dy = step;
    else if (direction === 'ArrowLeft') dx = -step;
    else if (direction === 'ArrowRight') dx = step;
    selectedKeys.forEach(k => {
        const se = findElement(k);
        if (!se) return;
        se.pos_x_pct = clampEct(se.pos_x_pct + dx, 0, 100 - se.width_pct);
        se.pos_y_pct = clampEct(se.pos_y_pct + dy, 0, 100 - se.height_pct);
        applyElementStyle($(`.ect-el[data-key="${k}"]`), se);
    });
    dirty = true;
    updateSaveHint();
}
// Delete/Backspace deletes the whole current selection -- unless the user is typing somewhere (an
// input/textarea/contenteditable), where Backspace must behave normally. Only while the edit
// modal is actually open, so this never hijacks Backspace on the rest of the page. Ctrl+G/Ctrl+
// Shift+G mirror the Group/Ungroup ribbon buttons (explicit request: multi-select+group workflow).
// Ctrl+Z/Ctrl+Shift+Z (undo/redo), Ctrl+D (duplicate), Ctrl+C/Ctrl+V (copy/paste), and arrow keys
// (nudge) are all 2026-08-25 explicit-request additions -- see each function's own comment above.
$(document).on('keydown', function (e) {
    // 2026-08-25: the editor is now its own standalone page (no more #ectEditModal to check
    // .hasClass('show') on) -- #ectPage only exists in that page's markup, so its presence is what
    // used to be "the modal is open."
    if (!$('#ectPage').length) return;
    const tag = (e.target.tagName || '').toLowerCase();
    const isTyping = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;
    if ((e.key === 'Delete' || e.key === 'Backspace') && selectedKeys.length && !isTyping) {
        e.preventDefault();
        deleteSelectedElements();
        return;
    }
    // 2026-08-25, real bug found (not a guess): "Ctrl Z และ Ctrl Shift Z ยังใช้คีย์ลัดไม่ได้" --
    // `e.key` is LAYOUT-DEPENDENT (it reports the character the active keyboard layout produces for
    // that physical key), so on a Thai keyboard layout the physical Z/D/C/V/G keys don't report
    // 'z'/'d'/'c'/'v'/'g' at all even with Ctrl held -- a well-known cross-layout shortcut gotcha,
    // and a very likely thing to hit given this app's own user base. `e.code` reports the PHYSICAL
    // key ('KeyZ' etc.) regardless of layout, so every Ctrl-based shortcut below now checks that
    // instead. Delete/Backspace/Arrow keys are untouched -- those aren't letter keys, `e.key` is
    // already layout-independent for them.
    if ((e.ctrlKey || e.metaKey) && !isTyping && e.code === 'KeyZ') {
        e.preventDefault();
        if (e.shiftKey) { performRedo(); } else { performUndo(); }
        return;
    }
    if ((e.ctrlKey || e.metaKey) && !isTyping && e.code === 'KeyD') {
        e.preventDefault();
        duplicateSelectedElements();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && !isTyping && e.code === 'KeyC') {
        e.preventDefault();
        copySelectedElements();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && !isTyping && e.code === 'KeyV') {
        e.preventDefault();
        pasteClipboardElements();
        return;
    }
    if (!isTyping && selectedKeys.length && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        e.preventDefault();
        nudgeSelectedElements(e.key, e.shiftKey);
        return;
    }
    if ((e.ctrlKey || e.metaKey) && !isTyping && e.code === 'KeyG') {
        e.preventDefault();
        if (e.shiftKey) { ungroupSelectedElements(); } else { groupSelectedElements(); }
    }
});

/* ---------- Group / Ungroup (explicit request: "เลือกหลายรายการ...เพื่อ Group รวม layout ได้ และ
   สามารถ ungroup ได้") ---------- */
function generateGroupKey() {
    return 'grp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
}
function groupSelectedElements() {
    if (selectedKeys.length < 2) return;
    pushUndo();
    const gKey = generateGroupKey();
    elements.forEach(e => { if (selectedKeys.includes(e.key)) e.group_key = gKey; });
    renderCanvas();
    dirty = true;
    updateSaveHint();
}
function ungroupSelectedElements() {
    if (!selectedKeys.length) return;
    pushUndo();
    elements.forEach(e => { if (selectedKeys.includes(e.key)) e.group_key = null; });
    renderCanvas();
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '#ectGroupBtn', groupSelectedElements);
$(document).on('click', '#ectUngroupBtn', ungroupSelectedElements);

/* ---------- Layers panel (explicit request: "โดยมี layer บอกเหมือน photoshop") -- lists every
   element top-to-bottom, grouped elements collapsed into one row. Photoshop convention: the layer
   painted on TOP appears at the TOP of the list -- elements[] appends later entries later (drawn on
   top for any overlapping area), so the panel shows elements[] reversed. ---------- */
function elementLabel(el) {
    if (el.element_type === 'image') {
        if (el.field_key === 'company_logo') return langData['company_logo'] || 'Company Logo';
        if (el.field_key === 'company_signature') return langData['company_signature'] || 'Authorized Signature';
        return langData['ect_image_library'] || 'Image';
    }
    const text = (el.content || '').replace(/\s+/g, ' ').trim();
    if (!text) return '(empty text)';
    return text.length > 28 ? text.slice(0, 28) + '…' : text;
}
function layerIcon(el) {
    return el.element_type === 'image' ? 'fa-image' : 'fa-font';
}
// 2026-08-25, explicit follow-up: "ในตรง layer ให้สามารถลบจากตรงไหนไห้ด้วย และถ้าตัวไหนแก้ไข text ได้ก็ให้
// แก้ไขได้จากส่วนนั้นเลย" -- each row gets its own Delete (x), and text rows that AREN'T locked (see
// isBoundFieldElement()) also get an Edit (pencil) icon that opens the same text modal directly,
// without having to go find the element on the canvas and double-click it.
function layerRowHtml(el) {
    const selected = selectedKeys.includes(el.key);
    const editable = el.element_type === 'text' && !isBoundFieldElement(el);
    const editBtn = editable
        ? `<button type="button" class="btn btn-link btn-sm p-0 ms-1 ect-layer-edit" data-key="${el.key}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>`
        : '';
    // 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว" --
    // Photoshop-style eye toggle, a real alternative to Delete (hidden elements skip BOTH the canvas
    // AND the generated PDF -- see renderCanvas()/EmploymentCertificateRenderer::buildHtml() -- while
    // keeping their position/content/formatting intact for whenever they're shown again).
    const visible = el.is_visible !== false;
    // 2026-08-26, explicit request: "ปุ่มปิดตา layer ให้มาอยู่หน้าสุดของแถว" -- moved from the end
    // (ms-auto) to the very front of the row, ahead of the type icon/label.
    const eyeBtn = `<button type="button" class="btn btn-link btn-sm p-0 me-1 ect-layer-visibility" data-key="${el.key}" title="${langData[visible ? 'ect_layer_hide' : 'ect_layer_show'] || (visible ? 'Hide' : 'Show')}"><i class="fa-solid ${visible ? 'fa-eye' : 'fa-eye-slash text-muted'}"></i></button>`;
    return `
        <div class="ect-layer-row ${selected ? 'ect-layer-selected' : ''} ${visible ? '' : 'ect-layer-hidden'}" data-key="${el.key}">
            ${eyeBtn}
            <i class="fa-solid ${layerIcon(el)} me-1"></i>
            <span class="ect-layer-label">${escapeHtmlEct(elementLabel(el))}</span>
            <div class="ect-layer-actions ms-auto d-flex align-items-center">
                ${editBtn}
                <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger ect-layer-delete" data-key="${el.key}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
    `;
}
// 2026-08-25, explicit request: "รองรับการมีหลายๆหน้า" -- only the elements on the currently-shown
// page (renderCanvas() uses the same helper), so this doesn't list elements from other pages.
function currentPageElements() {
    return elements.filter(e => (e.page_number || 1) === currentPageNumber);
}
function renderLayersPanel() {
    const $panel = $('#ectLayersList');
    if (!$panel.length) return;
    $panel.empty();
    const pageElements = currentPageElements();
    if (!pageElements.length) {
        $panel.append(`<div class="text-secondary small text-center py-3">${langData['ect_layers_empty'] || 'No elements yet.'}</div>`);
        return;
    }
    const reversed = pageElements.slice().reverse();
    const renderedGroups = new Set();
    reversed.forEach(el => {
        if (el.group_key) {
            if (renderedGroups.has(el.group_key)) return;
            renderedGroups.add(el.group_key);
            const members = pageElements.filter(e => e.group_key === el.group_key);
            const groupSelected = members.length > 0 && members.every(m => selectedKeys.includes(m.key));
            // Group-level eye: "visible" only when EVERY member is visible (matches a "select all"
            // checkbox convention) -- clicking it shows all members if any are hidden, else hides all.
            const groupVisible = members.every(m => m.is_visible !== false);
            const $group = $(`
                <div class="ect-layer-group" data-group-key="${el.group_key}">
                    <div class="ect-layer-row ect-layer-group-row ${groupSelected ? 'ect-layer-selected' : ''}">
                        <button type="button" class="btn btn-link btn-sm p-0 me-1 ect-layer-group-visibility" data-group-key="${el.group_key}" title="${langData[groupVisible ? 'ect_layer_hide' : 'ect_layer_show'] || (groupVisible ? 'Hide' : 'Show')}"><i class="fa-solid ${groupVisible ? 'fa-eye' : 'fa-eye-slash text-muted'}"></i></button>
                        <i class="fa-solid fa-folder me-1"></i>
                        <span class="ect-layer-label">${escapeHtmlEct(langData['ect_layer_group_label'] || 'Group')} (${members.length})</span>
                        <button type="button" class="btn btn-link btn-sm p-0 ms-auto text-danger ect-layer-group-delete" data-group-key="${el.group_key}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div class="ect-layer-children"></div>
                </div>
            `);
            const $children = $group.find('.ect-layer-children');
            members.slice().reverse().forEach(m => { $children.append(layerRowHtml(m)); });
            $panel.append($group);
        } else {
            $panel.append(layerRowHtml(el));
        }
    });
}
$(document).on('click', '.ect-layer-row[data-key]', function (e) {
    if ($(e.target).closest('.ect-layer-edit, .ect-layer-delete, .ect-layer-visibility').length) return;
    selectElement($(this).data('key'), e.ctrlKey || e.metaKey);
});
$(document).on('click', '.ect-layer-edit', function (e) {
    e.stopPropagation();
    openTextModal($(this).data('key'));
});
// 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว" --
// toggling visibility does NOT count as a "select" click (stopPropagation, same as Edit/Delete), and
// deselects the element if it's being hidden (a hidden element has no canvas box to interact with,
// same reasoning selectElement()'s own guards use elsewhere).
$(document).on('click', '.ect-layer-visibility', function (e) {
    e.stopPropagation();
    pushUndo();
    const key = $(this).data('key');
    const el = findElement(key);
    if (!el) return;
    el.is_visible = el.is_visible === false;
    if (el.is_visible === false) {
        selectedKeys = selectedKeys.filter(k => k !== key);
    }
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-layer-group-visibility', function (e) {
    e.stopPropagation();
    pushUndo();
    const groupKey = $(this).data('group-key');
    const members = elements.filter(m => m.group_key === groupKey);
    const groupVisible = members.every(m => m.is_visible !== false);
    const nextVisible = !groupVisible;
    members.forEach(m => { m.is_visible = nextVisible; });
    if (!nextVisible) {
        const memberKeys = members.map(m => m.key);
        selectedKeys = selectedKeys.filter(k => !memberKeys.includes(k));
    }
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-layer-delete', function (e) {
    e.stopPropagation();
    pushUndo();
    const key = $(this).data('key');
    elements = elements.filter(el => el.key !== key);
    selectedKeys = selectedKeys.filter(k => k !== key);
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-layer-group-delete', function (e) {
    e.stopPropagation();
    pushUndo();
    const groupKey = $(this).data('group-key');
    const memberKeys = elements.filter(m => m.group_key === groupKey).map(m => m.key);
    elements = elements.filter(el => el.group_key !== groupKey);
    selectedKeys = selectedKeys.filter(k => !memberKeys.includes(k));
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-layer-group-row', function (e) {
    if ($(e.target).closest('.ect-layer-group-delete').length) return;
    const groupKey = $(this).closest('.ect-layer-group').data('group-key');
    const members = elements.filter(m => m.group_key === groupKey).map(m => m.key);
    if (e.ctrlKey || e.metaKey) {
        const allSelected = members.every(k => selectedKeys.includes(k));
        if (allSelected) {
            selectedKeys = selectedKeys.filter(k => !members.includes(k));
        } else {
            members.forEach(k => { if (!selectedKeys.includes(k)) selectedKeys.push(k); });
        }
    } else {
        selectedKeys = members.slice();
    }
    applySelectionClasses();
    updateSelectionUI();
    renderLayersPanel();
});

/* ---------- Grid toggle (explicit request: "อยากให้มีเส้นตารางขึ้นแทนหน้าขาวเปล่าๆ และมีปุ่ม เปิด/
   แสดง ตารางด้วยครับ") -- purely visual placement reference, no snap-to-grid behavior. ---------- */
$(document).on('click', '#ectGridToggle', function () {
    gridOn = !gridOn;
    $('#ectPage').toggleClass('ect-grid-on', gridOn);
    $(this).toggleClass('active', gridOn);
});

/* ---------- Free-text content modal (stacked on top of #ectEditModal) ---------- */
function openTextModal(key) {
    const el = findElement(key);
    // Defense in depth -- the canvas dblclick handler already checks this, but the Layers panel's
    // own Edit icon only ever renders for non-locked rows in the first place (see layerRowHtml()),
    // so this should never actually trip; still worth guarding here since this is the one shared
    // entry point both call.
    if (!el || isBoundFieldElement(el)) return;
    textModalKey = key;
    $('#ectTextModalInput').val(el.content || '');
    new bootstrap.Modal(document.getElementById('ectTextModal')).show();
}
$(document).on('click', '#ectTextModalApplyBtn', function () {
    const el = findElement(textModalKey);
    if (!el) return;
    pushUndo();
    el.content = $('#ectTextModalInput').val();
    renderCanvas();
    selectElement(textModalKey);
    bootstrap.Modal.getInstance(document.getElementById('ectTextModal')).hide();
    dirty = true;
    updateSaveHint();
});

/* ---------- Insert > Shape (explicit request: "สามารถ insert shape ต่างๆ เหมือน Word") -- rectangle/
   ellipse/line, font_color doubles as fill/border (this element type has no separate fields for
   those -- same simplification as the server-side renderer). ---------- */
function addShapeElement(shapeType, posX, posY) {
    if (!currentTemplate) return;
    const el = Object.assign(emptyElementBase(), {
        key: newElementKey(), id: null, element_type: 'shape', field_key: shapeType, image_asset_id: null, content: null,
        pos_x_pct: clampEct(posX, 0, 80), pos_y_pct: clampEct(posY, 0, 85),
        width_pct: shapeType === 'line' ? 30 : 20, height_pct: shapeType === 'line' ? 1 : 15,
        font_color: '#FF9900'
    });
    pushUndo();
    elements.push(el);
    renderCanvas();
    selectElement(el.key);
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '.ect-insert-shape-item', function (e) {
    e.preventDefault();
    addShapeElement($(this).data('shape'), 15, 15);
});

/* ---------- Insert > Symbol (explicit request: "มีให้เพิ่ม Symbol ได้") -- drops the character
   straight onto the canvas as a new free-text element, no text-modal round trip needed. ---------- */
const ECT_SYMBOLS = ['©', '®', '™', '§', '¶', '•', '★', '☆', '✓', '✔', '✗', '➤', '→', '←', '↑', '↓',
    '♥', '♦', '♣', '♠', '☎', '✉', '⚑', '☀', '☁', '☂', '♪', '♫', '∞', '±', '×', '÷', '≈', '≠', '≤', '≥',
    '°', '€', '£', '¥'];
function renderSymbolMenu() {
    const $menu = $('#ectSymbolMenu');
    if ($menu.data('rendered')) return;
    ECT_SYMBOLS.forEach(sym => {
        $menu.append(`<button type="button" class="ect-symbol-item" data-symbol="${sym}">${sym}</button>`);
    });
    $menu.data('rendered', true);
}
$(document).on('click', '#ectInsertSymbolBtn', function () { renderSymbolMenu(); });
function addSymbolElement(symbol, posX, posY) {
    if (!currentTemplate) return;
    const el = Object.assign(emptyElementBase(), {
        key: newElementKey(), id: null, element_type: 'text', field_key: null, image_asset_id: null,
        content: symbol,
        pos_x_pct: clampEct(posX, 0, 90), pos_y_pct: clampEct(posY, 0, 94), width_pct: 8, height_pct: 6,
        font_size: 24, text_align: 'center'
    });
    pushUndo();
    elements.push(el);
    renderCanvas();
    selectElement(el.key);
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '.ect-symbol-item', function (e) {
    e.stopPropagation();
    addSymbolElement($(this).data('symbol'), 15, 15);
    bootstrap.Dropdown.getOrCreateInstance(document.getElementById('ectInsertSymbolBtn')).hide();
});

/* ---------- Insert/edit Table (explicit request: "เพิ่ม option การเพิ่มตาราง ที่สามารถกำหนดเส้นสีเส้นขอบ
   ได้เหมือน word") -- #ectTableInsertModal doubles as BOTH "insert a new table" (ribbon button,
   editingTableKey null) and "edit an existing one" (double-click on the canvas, editingTableKey set)
   -- same modal, same confirm handler, branch on that one variable. ---------- */
let editingTableKey = null;
function rebuildTableCellsGrid(existingCells) {
    const rows = clampEct(parseInt($('#ectTableRowsInput').val(), 10) || 1, 1, 20);
    const cols = clampEct(parseInt($('#ectTableColsInput').val(), 10) || 1, 1, 10);
    const $grid = $('#ectTableCellsGrid').empty();
    $grid.css('grid-template-columns', `repeat(${cols}, 1fr)`);
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const value = existingCells && existingCells[r] && existingCells[r][c] !== undefined ? existingCells[r][c] : '';
            $grid.append(`<input type="text" class="ect-table-cell-input" data-row="${r}" data-col="${c}" value="${escapeHtmlEct(value)}">`);
        }
    }
}
$(document).on('input', '#ectTableRowsInput, #ectTableColsInput', function () {
    // Preserve whatever's already typed when just growing/shrinking the grid, instead of wiping it.
    const current = [];
    $('#ectTableCellsGrid .ect-table-cell-input').each(function () {
        const r = $(this).data('row'), c = $(this).data('col');
        current[r] = current[r] || [];
        current[r][c] = $(this).val();
    });
    rebuildTableCellsGrid(current);
});
function openTableModal(key) {
    const el = key ? findElement(key) : null;
    editingTableKey = key || null;
    let data = { rows: 3, cols: 3, border_color: '#000000', border_width: 1, cells: [] };
    if (el) {
        try { data = Object.assign(data, JSON.parse(el.content || '{}')); } catch (e) { /* keep defaults */ }
    }
    $('#ectTableRowsInput').val(data.rows);
    $('#ectTableColsInput').val(data.cols);
    $('#ectTableBorderColorInput').val(data.border_color);
    $('#ectTableBorderWidthInput').val(data.border_width);
    rebuildTableCellsGrid(data.cells);
    new bootstrap.Modal(document.getElementById('ectTableInsertModal')).show();
}
$(document).on('click', '#ectInsertTableBtn', function () {
    if (!currentTemplate) return;
    openTableModal(null);
});
$(document).on('click', '#ectTableInsertConfirmBtn', function () {
    const rows = clampEct(parseInt($('#ectTableRowsInput').val(), 10) || 1, 1, 20);
    const cols = clampEct(parseInt($('#ectTableColsInput').val(), 10) || 1, 1, 10);
    const cells = [];
    for (let r = 0; r < rows; r++) {
        cells.push([]);
        for (let c = 0; c < cols; c++) {
            cells[r].push($(`.ect-table-cell-input[data-row="${r}"][data-col="${c}"]`).val() || '');
        }
    }
    const content = JSON.stringify({
        rows, cols,
        border_color: $('#ectTableBorderColorInput').val() || '#000000',
        border_width: clampEct(parseInt($('#ectTableBorderWidthInput').val(), 10) || 1, 0, 10),
        cells
    });
    pushUndo();
    if (editingTableKey) {
        const el = findElement(editingTableKey);
        if (el) { el.content = content; }
    } else {
        const el = Object.assign(emptyElementBase(), {
            key: newElementKey(), id: null, element_type: 'table', field_key: null, image_asset_id: null, content,
            pos_x_pct: 15, pos_y_pct: 15, width_pct: 60, height_pct: 30
        });
        elements.push(el);
    }
    renderCanvas();
    if (editingTableKey) selectElement(editingTableKey);
    bootstrap.Modal.getInstance(document.getElementById('ectTableInsertModal')).hide();
    dirty = true;
    updateSaveHint();
});

/* ---------- Reusable image library (stacked on top of #ectEditModal) -- 2026-08-25, explicit
   request: "Company Logo ที่ Upload ในหน้า Template ให้ตัดออกเลยครับ เหลือแค่ Form ให้ upload image
   เพื่อดึงมาใช้งาน" -- the old per-template Company Logo upload control (and its own upload-logo
   endpoint call) is gone; this Image Library is now the ONLY upload entry point in the designer.
   `logoPath` is still read from a loaded template (an older one may still have one set from before
   this change, see resolveElementImageUrl()'s priority comment) but never written to again. ---------- */
function loadImageLibrary(onLoaded) {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.list-images`,
        method: 'GET', dataType: 'json',
        success: function (res) {
            if (res.status) {
                imageLibraryCache = res.data || [];
                renderImageLibrary();
            }
            if (typeof onLoaded === 'function') onLoaded();
        },
        error: function () { if (typeof onLoaded === 'function') onLoaded(); }
    });
}
// 2026-08-25, explicit follow-up: "ตรง image libraly ให้ขึ้นเป็น อีกปุ่มตรง ที่จัดการ font โดยคลิกแล้วมีให้
// เลือกว่าจะ upload ใหม่หรือเลือกจากที่มีอยู่แล้ว ถ้าเลือกจากที่มีอยู่แล้วให้ขึ้น modal ให้เลือก และเลือก insert
// ได้ทีละหลายรูป" -- "Manage Images" moved into the ribbon's "Image" dropdown (Upload New / Choose
// Existing); THIS modal is now purely a multi-select PICKER -- clicking a tile toggles it in/out of
// `selectedLibraryImageIds` instead of inserting immediately, "Insert Selected" drops every one of
// them onto the canvas at once (offset so they don't land exactly on top of each other).
let selectedLibraryImageIds = new Set();
function renderImageLibrary() {
    const $grid = $('#ectImageLibraryGrid').empty();
    imageLibraryCache.forEach(img => {
        const selected = selectedLibraryImageIds.has(Number(img.id));
        $grid.append(`
            <div class="ect-image-grid-item ${selected ? 'selected' : ''}" data-id="${img.id}">
                <img src="${BASE_URL}/${img.thumbnail_path || img.file_path}" alt="">
                <div class="ect-image-selected-badge"><i class="fa-solid fa-check"></i></div>
                <div class="ect-image-delete" data-id="${img.id}"><i class="fa-solid fa-xmark"></i></div>
            </div>
        `);
    });
    $('#ectImageLibraryEmpty').toggleClass('d-none', imageLibraryCache.length > 0);
    updateImageLibrarySelectionUI();
}
function updateImageLibrarySelectionUI() {
    $('#ectImageLibrarySelectedCount').text(selectedLibraryImageIds.size);
    $('#ectImageLibraryInsertBtn').prop('disabled', selectedLibraryImageIds.size === 0);
}
$(document).on('click', '.ect-image-grid-item', function (e) {
    if ($(e.target).closest('.ect-image-delete').length) return;
    const id = Number($(this).data('id'));
    if (selectedLibraryImageIds.has(id)) {
        selectedLibraryImageIds.delete(id);
        $(this).removeClass('selected');
    } else {
        selectedLibraryImageIds.add(id);
        $(this).addClass('selected');
    }
    updateImageLibrarySelectionUI();
});
$(document).on('click', '#ectImageLibraryInsertBtn', function () {
    const ids = Array.from(selectedLibraryImageIds);
    ids.forEach((id, idx) => addImageAssetElement(id, 10 + idx * 3, 10 + idx * 3));
    selectedLibraryImageIds.clear();
    bootstrap.Modal.getInstance(document.getElementById('ectImageLibraryModal')).hide();
});
$('#ectImageLibraryModal').on('hidden.bs.modal', function () {
    selectedLibraryImageIds.clear();
    updateImageLibrarySelectionUI();
});
$(document).on('click', '.ect-image-delete', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    showConfirm(langData['ect_confirm_delete_image'] || 'Delete this image?', '', function () {
        $.ajax({
            url: `${BASE_URL}/api/employment-certificate-template.delete-image`,
            method: 'POST', data: { id }, dataType: 'json',
            success: function (res) {
                if (res.status) {
                    selectedLibraryImageIds.delete(Number(id));
                    loadImageLibrary();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                }
            }
        });
    });
});
/* ---------- Insert > Image (ribbon dropdown: Upload New / Choose Existing) ---------- */
function uploadImagesSequentially(files, onDone) {
    const uploadedIds = [];
    function next(i) {
        if (i >= files.length) { onDone(uploadedIds); return; }
        const formData = new FormData();
        formData.append('file', files[i]);
        $.ajax({
            url: `${BASE_URL}/api/employment-certificate-template.upload-image`,
            method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
            success: function (res) {
                if (res.status) { uploadedIds.push(res.id); } else { showWarning(res.message || langData['save_failed'] || 'Upload failed.'); }
                next(i + 1);
            },
            error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); next(i + 1); }
        });
    }
    next(0);
}
$(document).on('click', '#ectImageUploadNewItem', function (e) {
    e.preventDefault();
    $('#ectRibbonImageUploadInput').val('').trigger('click');
});
$(document).on('change', '#ectRibbonImageUploadInput', function () {
    const files = Array.from(this.files || []);
    if (!files.length) return;
    uploadImagesSequentially(files, function (uploadedIds) {
        loadImageLibrary(function () {
            uploadedIds.forEach((id, idx) => addImageAssetElement(id, 10 + idx * 3, 10 + idx * 3));
        });
    });
    $(this).val('');
});
$(document).on('click', '#ectImageChooseExistingItem', function (e) {
    e.preventDefault();
    loadImageLibrary();
    new bootstrap.Modal(document.getElementById('ectImageLibraryModal')).show();
});

/* ---------- Template LIST (DataTable, client-side -- few templates per company/language) ---------- */
function updateFontFamilyOptions() {
    // DejaVu Sans has NO Thai glyphs at all (confirmed by parsing its cmap table -- see
    // storage/fonts/thsarabun/NOTICE.md) -- only offered on the English tab, where it's a genuine
    // stylistic alternative to TH Sarabun New (which also covers Latin fine either way).
    $('#ectPropFontFamily option[data-en-only]').toggle(currentLanguage === 'en');
}
function pageSizeLabel(row) {
    const orientationLabel = row.orientation === 'landscape' ? (langData['ect_landscape'] || 'Landscape') : (langData['ect_portrait'] || 'Portrait');
    return `${row.page_size || 'A4'} — ${orientationLabel}`;
}
// 2026-08-25, TH/EN unification simplified further (explicit follow-up: "ในหน้าตารางอาจเป็นแค่
// สัญลักษณ์ว่าตั้งค่าแล้ว...ในหน้ารายการจะได้มีปุ่มดำเนินการแค่ชุดเดียว") -- these two per-language cells
// are now JUST a compact ready/not-ready symbol (+ clickable default-star when ready). All the old
// per-language action buttons (View/Edit/Duplicate/Delete/Generate-Auto/Create-Manually) are GONE
// from here -- see ectActionsGroupHtml() for the one shared action group per pair, and
// _modals_partial.php's #ectLangEmptyState for where Generate-Auto/create-manually moved to.
// 2026-08-25, explicit follow-up: "Default ที่เป็นดาวไม่จำเป็นต้องมีครับ" -- the ready/not-ready symbol
// no longer carries a default-star toggle at all (is_default/setDefault() stay in the backend
// untouched -- nothing consumes "which template is default" yet in this phase, see CLAUDE.md, so
// there's no UI anywhere that still needs to set it; removing the column here is purely "don't show
// something with no purpose right now", not a sign the concept is gone for good).
// 2026-08-26, explicit request: "ให้มี Draft Mode และ Public Mode...ในหน้า List สามารถเปิด Draft หรือ
// Public ได้จากหน้านั้นเลย" -- ready/not-ready icon only; the Draft/Public control moved into its own
// dedicated first column, see ectPublishSwitchesHtml() below (explicit follow-up: "ปุ่ม Draft กับ
// Public ให้เป็น Switch ปิดเปิด แล้วแยกมาเป็น Column แรกสุด").
function ectLangStatusHtml(pairRow, lang) {
    const tpl = pairRow[lang];
    if (!tpl) {
        return `<i class="fa-regular fa-circle text-muted" title="${langData['ect_not_ready'] || 'Not ready'}"></i>`;
    }
    return `<i class="fa-solid fa-circle-check text-success" title="${langData['ect_ready'] || 'Ready'}"></i>`;
}
// 2026-08-26, explicit follow-up: direct port of PayslipTemplateModel's own pstPublishSwitchesHtml().
function ectPublishSwitchesHtml(pairRow) {
    const flagFile = { th: 'th', en: 'gb' };
    // 2026-08-26: direct port of PayslipTemplateModel's own pstPublishSwitchesHtml() same-day follow-up
    // (text label dropped to .visually-hidden, both languages' pills wrapped in one flex row).
    const rows = ['th', 'en'].filter(l => pairRow[l]).map(l => {
        const tpl = pairRow[l];
        const isPublic = tpl.publish_status === 'public';
        const cbId = `ectPublishSwitch_${tpl.id}`;
        // 2026-08-26: direct port of PayslipTemplateModel's own pstPublishSwitchesHtml() markup fix
        // (see that file's own comment on why .form-check stays on its own inner wrapper).
        return `<div class="pst-publish-switch-row">
            <img src="${BASE_URL}/public/flags/${flagFile[l]}.png" width="14" class="pst-publish-switch-flag">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input ect-publish-switch" type="checkbox" role="switch" id="${cbId}" data-id="${tpl.id}" data-current="${tpl.publish_status}" ${isPublic ? 'checked' : ''}>
                <label class="form-check-label small visually-hidden" for="${cbId}">${isPublic ? (langData['ect_publish_public'] || 'Public') : (langData['ect_publish_draft'] || 'Draft')}</label>
            </div>
        </div>`;
    }).join('');
    return `<div class="pst-publish-switch-group">${rows}</div>`;
}
$(document).on('change', '.ect-publish-switch', function (e) {
    const $cb = $(this);
    const id = $cb.data('id');
    const current = $cb.data('current');
    const target = $cb.is(':checked') ? 'public' : 'draft';
    const revert = function () { $cb.prop('checked', current === 'public'); };
    const doToggle = function () {
        $.ajax({
            url: `${BASE_URL}/api/employment-certificate-template.publish-toggle`,
            method: 'POST', data: { id, publish_status: target }, dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    $('#tb_ect_template').DataTable().ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                    revert();
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); revert(); }
        });
    };
    // Going Public -> Draft immediately stops real generation from picking it up -- confirm first.
    // Draft -> Public is safe/reversible, no confirm needed.
    if (target === 'draft') {
        showConfirm(langData['ect_confirm_unpublish'] || 'Switch this template back to Draft? It will stop being used for real generation immediately.', '', doToggle, revert);
    } else {
        doToggle();
    }
});
// 2026-08-25, explicit design ask: "การลบแค่ยาวภาษาตรงนี้คิดไม่ออกช่วย Design ให้หน่อยครับ" -- resolved as
// a Delete DROPDOWN listing only the language(s) that actually exist for this pair ("Delete both"
// only appears when both do), so deleting a single language never needs its own separate button
// group -- same split-button spirit for Preview (only lists ready languages). Edit always opens the
// WHOLE pair (both tabs, see openEditModalForPair()); Duplicate always duplicates the whole pair
// (see EmploymentCertificateTemplateModel::duplicatePair()) -- neither needs a language choice.
// 2026-08-25, explicit follow-up: "ตัวปุ่มไม่ใช่รูปแบบที่ตกลงกัน ปรับให้เป็นรูปแบบที่ตกลงกัน และปุ่ม view
// ที่เป็น dropdown เพิ่มการแสดงรูปธงชาติ" -- fixed by using a plain `.dropdown` wrapper for the two
// dropdown-toggle buttons (Bootstrap dropdown positioning doesn't require a `.btn-group` ancestor).
// Preview's dropdown items carry the same flag icons used everywhere else language is shown.
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group/border-start convention this
// comment used to describe as "this app's ONE established action-group convention" -- that's now
// the OLD convention, superseded by .btn-circle-action rolled out across the app's tables.
function ectActionsGroupHtml(pairRow) {
    const readyLangs = ['th', 'en'].filter(l => pairRow[l]);
    const flagFile = { th: 'th', en: 'gb' };
    const previewItems = readyLangs.map(l =>
        `<li><a class="dropdown-item ect-preview-lang-item" href="#" data-id="${pairRow[l].id}"><img src="${BASE_URL}/public/flags/${flagFile[l]}.png" width="14" class="me-2">${langData['template_language_' + l] || l}</a></li>`
    ).join('');
    const deleteItems = [];
    if (pairRow.th && pairRow.en) {
        deleteItems.push(`<li><a class="dropdown-item text-danger ect-delete-pair-item" href="#" data-pair-key="${pairRow.pair_key}">${langData['ect_delete_both'] || 'Delete both (Thai + English)'}</a></li>`);
        deleteItems.push('<li><hr class="dropdown-divider"></li>');
    }
    if (pairRow.th) {
        deleteItems.push(`<li><a class="dropdown-item text-danger ect-delete-lang-item" href="#" data-id="${pairRow.th.id}">${langData['ect_delete_thai_only'] || 'Delete Thai only'}</a></li>`);
    }
    if (pairRow.en) {
        deleteItems.push(`<li><a class="dropdown-item text-danger ect-delete-lang-item" href="#" data-id="${pairRow.en.id}">${langData['ect_delete_english_only'] || 'Delete English only'}</a></li>`);
    }
    return `<div class="d-flex gap-1 justify-content-center ect-actions-group">
        <div class="dropdown">
            <button type="button" class="btn btn-link btn-circle-action text-secondary dropdown-toggle" data-bs-toggle="dropdown" title="${langData['preview'] || 'Preview'}"><i class="fas fa-eye"></i></button>
            <ul class="dropdown-menu">${previewItems}</ul>
        </div>
        <button type="button" class="btn btn-link btn-circle-action text-warning btn-edit-ect" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link btn-circle-action text-primary btn-duplicate-pair-ect" title="${langData['duplicate'] || 'Duplicate'}"><i class="fas fa-copy"></i></button>
        <div class="dropdown">
            <button type="button" class="btn btn-link btn-circle-action text-danger dropdown-toggle" data-bs-toggle="dropdown" title="${langData['delete'] || 'Delete'}"><i class="fas fa-trash-alt"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">${deleteItems.join('')}</ul>
        </div>
    </div>`;
}
// 2026-08-25, explicit request: the list "ดูโล่งๆ" (looked sparse) -- added a Last Updated column,
// sourced from listPaired()'s own latest_updated_at (already computed server-side across whichever
// language(s) exist, no extra query needed).
function formatEctDateTime(str) {
    if (!str) return '';
    const d = new Date(String(str).replace(' ', 'T'));
    if (isNaN(d.getTime())) return escapeHtmlEct(str);
    const pad = n => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function initEctTemplateTable() {
    if ($.fn.DataTable.isDataTable('#tb_ect_template')) {
        $('#tb_ect_template').DataTable().ajax.reload(null, false);
        return;
    }
    tb_ect_template = $('#tb_ect_template').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/employment-certificate-template.paired-list`,
            dataSrc: 'data'
        },
        columns: [
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => ectPublishSwitchesHtml(row) },
            { data: null, render: (d, t, row) => `<strong class="text-dark">${escapeHtmlEct(row.template_name)}</strong>` },
            { data: null, render: (d, t, row) => pageSizeLabel(row) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => ectLangStatusHtml(row, 'th') },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => ectLangStatusHtml(row, 'en') },
            // 2026-08-26, explicit follow-up: direct port of Payslip Template's own object-form
            // render fix for this exact column -- see that file's own comment.
            { data: 'latest_updated_at', render: { display: d => formatEctDateTime(d), sort: d => d, filter: d => d } },
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-center all', render: (d, t, row) => ectActionsGroupHtml(row) }
        ],
        // Edit/Duplicate/Preview/Delete all need the FULL pair row (both languages' ids, pair_key,
        // name) -- stashed on the <tr> itself (idiomatic DataTables pattern) rather than re-deriving
        // it from data-* attributes scattered across the action buttons.
        createdRow: function (row, data) { $(row).data('pairRow', data); },
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-ect').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-ect">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_template">${langData['add_template'] || 'Template'}</span>
                    </button>
                `);
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the publish-switch column (0), the icon-only TH/EN
            // language-status columns (3, 4, no single filterable value), and actions (6).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 1, key: 'template_name' },
                    { index: 2, key: 'page_size' },
                    { index: 5, key: 'updated_at' },
                ]
            });
        }
    });
}
$(document).on('click', '.btn-duplicate-pair-ect', function () {
    const pairRow = $(this).closest('tr').data('pairRow');
    if (!pairRow) return;
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.duplicate-pair`,
        method: 'POST', data: { pair_key: pairRow.pair_key }, dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                $('#tb_ect_template').DataTable().ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        }
    });
});
function deleteTemplateById(id, onDone) {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.delete`,
        method: 'POST', data: { id }, dataType: 'json',
        success: function (res) {
            if (res.status) {
                if (typeof onDone === 'function') onDone();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        }
    });
}
$(document).on('click', '.ect-delete-lang-item', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    showConfirm(langData['ect_confirm_delete_template'] || 'Delete this template? This action cannot be undone.', '', function () {
        deleteTemplateById(id, function () {
            showSuccess(langData['delete_success'] || 'Deleted successfully.');
            $('#tb_ect_template').DataTable().ajax.reload(null, false);
        });
    });
});
$(document).on('click', '.ect-delete-pair-item', function (e) {
    e.preventDefault();
    const pairRow = $(this).closest('tr').data('pairRow');
    if (!pairRow) return;
    const ids = ['th', 'en'].filter(l => pairRow[l]).map(l => pairRow[l].id);
    showConfirm(langData['ect_confirm_delete_pair'] || 'Delete BOTH the Thai and English versions of this template? This action cannot be undone.', '', function () {
        let remaining = ids.length;
        ids.forEach(id => deleteTemplateById(id, function () {
            remaining -= 1;
            if (remaining <= 0) {
                showSuccess(langData['delete_success'] || 'Deleted successfully.');
                $('#tb_ect_template').DataTable().ajax.reload(null, false);
            }
        }));
    });
});

/* ---------- Preview (read-only PDF of the SAVED template -- no canvas, no edit modal) ---------- */
function streamPreviewBlob(url, payload, failMessage) {
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(res => {
        const contentType = res.headers.get('Content-Type') || '';
        if (contentType.indexOf('application/pdf') !== -1) {
            return res.blob().then(blob => {
                window.open(URL.createObjectURL(blob), '_blank');
            });
        }
        return res.json().then(data => { showWarning(data.message || langData['save_failed'] || failMessage); });
    }).catch(() => showWarning(langData['save_failed'] || failMessage));
}
function previewTemplateById(id) {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.get`,
        method: 'GET', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const t = res.data;
            streamPreviewBlob(`${BASE_URL}/api/employment-certificate-template.preview`, {
                language: t.language, logo_path: t.logo_path || null,
                page_size: t.page_size, orientation: t.orientation,
                elements: (t.elements || []).map(e => ({
                    element_type: e.element_type, field_key: e.field_key, image_asset_id: e.image_asset_id, content: e.content,
                    pos_x_pct: e.pos_x_pct, pos_y_pct: e.pos_y_pct, width_pct: e.width_pct, height_pct: e.height_pct,
                    font_size: e.font_size, font_family: e.font_family, font_color: e.font_color,
                    text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration,
                    // 2026-08-26: without this, a hidden element would render in THIS preview path
                    // (View from the list) since the renderer's own skip check only fires when the
                    // key is present and falsy -- an absent key defaults to visible.
                    is_visible: e.is_visible
                })),
                watermark_enabled: false, watermark_text: ''
            }, 'Preview failed.');
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
$(document).on('click', '.ect-preview-lang-item', function (e) {
    e.preventDefault();
    previewTemplateById($(this).data('id'));
});
// 2026-08-25, explicit request: "หน้าแก้ไขให้เปลี่ยนเป็นการเปิด Tab ใหม่ เพื่อให้การจัดการมีพื้นที่มากขึ้น" --
// Edit no longer opens an in-page modal; it navigates to the standalone editor page (edit.php,
// bootstrapEditorPage()) in a NEW browser tab, addressed by this pair's own pair_key in the URL.
$(document).on('click', '.btn-edit-ect', function () {
    const pairRow = $(this).closest('tr').data('pairRow');
    if (pairRow) window.open(`${BASE_URL}/employment-certificate/edit/${encodeURIComponent(pairRow.pair_key)}`, '_blank');
});

/* ---------- Editor page bootstrap ---------- */
/** Fetches ONE template's full data and maps it into a pairState-shaped slot object -- does NOT
 *  touch the bare globals directly, so both languages of a pair can be fetched independently/in
 *  parallel without one overwriting the other while both are in flight (see
 *  bootstrapEditorPage()). */
function fetchTemplateIntoSlot(id, callback) {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.get`,
        method: 'GET', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) { callback(null); return; }
            const t = res.data;
            const mappedElements = (t.elements || []).map(e => ({
                key: newElementKey(), id: Number(e.id), element_type: e.element_type, field_key: e.field_key,
                image_asset_id: e.image_asset_id ? Number(e.image_asset_id) : null, content: e.content,
                pos_x_pct: Number(e.pos_x_pct), pos_y_pct: Number(e.pos_y_pct), width_pct: Number(e.width_pct), height_pct: Number(e.height_pct),
                font_size: Number(e.font_size), font_family: e.font_family, font_color: e.font_color,
                text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration,
                group_key: e.group_key || null, page_number: Number(e.page_number) || 1,
                // 2026-08-26, real bug caught before shipping (same category as v12's page_number
                // miss): without this, loading a SAVED template that has a hidden element back into
                // the editor would silently show it as visible again (e.is_visible is undefined here
                // -> the canvas/Layers "visible unless === false" checks both read that as visible),
                // even though the DB still correctly has it saved as hidden -- only a re-save would
                // have then overwritten the DB's own hidden flag back to visible too.
                is_visible: e.is_visible !== 0 && e.is_visible !== false
            }));
            // 2026-08-25, explicit request: "รองรับการมีหลายๆหน้า" -- pageCount is inferred from the
            // highest page_number actually found (never persisted as its own value -- see the
            // pageCount global's own comment on why an empty trailing page doesn't survive a reload).
            const inferredPageCount = mappedElements.reduce((max, e) => Math.max(max, e.page_number || 1), 1);
            callback({
                template: t,
                logoPath: t.logo_path || null,
                elements: mappedElements,
                dirty: false, undoStack: [], redoStack: [],
                currentPageNumber: 1, pageCount: inferredPageCount,
                assignments: t.assignments || []
            });
        },
        error: function () { callback(null); }
    });
}
/** Copies pairState[lang] INTO the bare globals every other function in this file already reads/
 *  writes (currentTemplate/elements/logoPath/dirty/undoStack/redoStack) -- a shallow reference swap,
 *  not a deep clone (see the top-of-file docblock for why this was chosen over refactoring every
 *  call site). Renders either the normal canvas or the empty-state, depending on whether this
 *  language has been created for the pair yet. */
/* ---------- Assign To (department/team/employee scoping) -- explicit follow-up request (2026-08-25):
   "เพิ่ม Tab...เป็น checkbox ให้เลือก...เลือกได้กับทุกคน ทุกแผนก ทุกทีม แต่ถ้ามีการตั้งค่าซ้ำต้องแจ้ง Error"
   -- replaced the round-1 select2-multi-select UI with full checkbox lists, same pattern as
   PayslipTemplateController's own version (see that file's comment for the shared rationale).
   `assignableOptionsData` (the raw department/team/employee lists) is company-wide, loaded ONCE per
   editor page load -- it does NOT need to be per-language/pairState like elements/logoPath do, only
   the CHECKED state does, so `currentAssignments` is swapped in/out of pairState on tab switch same
   as everything else, and renderAssignChecklists() re-applies checked state against the one shared
   options list every time. ---------- */
const ECT_ASSIGN_SCOPES = [
    { type: 'department', dataKey: 'departments', allId: 'ectAssignAllDepartments', filterId: 'ectAssignDepartmentsFilter', listId: 'ectAssignDepartmentsList' },
    { type: 'team', dataKey: 'teams', allId: 'ectAssignAllTeams', filterId: 'ectAssignTeamsFilter', listId: 'ectAssignTeamsList' },
    { type: 'employee', dataKey: 'employees', allId: 'ectAssignAllEmployees', filterId: 'ectAssignEmployeesFilter', listId: 'ectAssignEmployeesList' }
];
let assignableOptionsData = null; // {departments, teams, employees}, company-wide, loaded once per editor page load
let currentAssignments = []; // active language tab's assignments -- swapped in/out of pairState[lang].assignments on tab switch

function assignItemLabel(item) {
    return (currentLang === 'en' && item.text_en) ? item.text_en : (item.text_th || item.text_en || ('#' + item.id));
}
function loadAssignableOptions(callback) {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.assignable-options`,
        method: 'POST', dataType: 'json',
        success: function (res) {
            assignableOptionsData = (res.status && res.data) ? res.data : { departments: [], teams: [], employees: [] };
            renderAssignChecklists();
            if (typeof callback === 'function') callback();
        },
        error: function () { if (typeof callback === 'function') callback(); }
    });
}
function renderAssignChecklists() {
    if (!assignableOptionsData) return;
    const checkedKeys = {};
    (currentAssignments || []).forEach(a => { checkedKeys[a.scope_type + ':' + a.scope_id] = true; });
    ECT_ASSIGN_SCOPES.forEach(scope => {
        const items = assignableOptionsData[scope.dataKey] || [];
        const $list = $('#' + scope.listId).empty();
        items.forEach(item => {
            const label = assignItemLabel(item);
            const checked = !!checkedKeys[scope.type + ':' + item.id];
            const cbId = `ectAssignCb_${scope.type}_${item.id}`;
            const $row = $('<div>').addClass('ect-assign-item form-check').attr('data-search', label.toLowerCase());
            const $cb = $('<input>').addClass('form-check-input ect-assign-checkbox').attr({
                type: 'checkbox', id: cbId, 'data-scope-type': scope.type, 'data-scope-id': item.id
            }).prop('checked', checked);
            const $label = $('<label>').addClass('form-check-label').attr('for', cbId).text(label);
            $row.append($cb, $label);
            $list.append($row);
        });
        if (!items.length) {
            $list.append($('<div>').addClass('text-secondary small p-2').text(langData['no_results'] || 'No results found'));
        }
        updateAssignSelectAllState(scope);
    });
    updateEctAssignModeUi();
}
// 2026-08-26, explicit request: "ตรง Assign To ช่วยปรับให้ใช้งานง่ายขึ้นไม่ซับซ้อน" -- direct port of
// PayslipTemplateModel's own updatePstAssignModeUi() pairing (see that file's own comment).
function updateEctAssignModeUi() {
    const anyChecked = $('.ect-assign-checkbox:checked').length > 0;
    $('#ectAssignModeEveryone').prop('checked', !anyChecked);
    $('#ectAssignModeSpecific').prop('checked', anyChecked);
    $('#ectAssignColumns, #ectAssignHint').toggleClass('d-none', !anyChecked);
}
$(document).on('change', 'input[name="ectAssignMode"]', function () {
    const specific = $(this).val() === 'specific';
    $('#ectAssignColumns, #ectAssignHint').toggleClass('d-none', !specific);
    if (!specific) {
        $('.ect-assign-checkbox').prop('checked', false);
        ECT_ASSIGN_SCOPES.forEach(scope => updateAssignSelectAllState(scope));
        if (currentTemplate) { dirty = true; updateSaveHint(); }
    }
});
function updateAssignSelectAllState(scope) {
    const $boxes = $('#' + scope.listId + ' .ect-assign-checkbox');
    const total = $boxes.length;
    const checkedCount = $boxes.filter(':checked').length;
    const $all = $('#' + scope.allId);
    $all.prop('checked', total > 0 && checkedCount === total);
    $all.prop('indeterminate', checkedCount > 0 && checkedCount < total);
}
function collectAssignments() {
    const result = [];
    $('.ect-assign-checkbox:checked').each(function () {
        result.push({ scope_type: $(this).data('scope-type'), scope_id: Number($(this).data('scope-id')) });
    });
    return result;
}
$(document).on('change', '.ect-assign-checkbox', function () {
    const scope = ECT_ASSIGN_SCOPES.find(s => s.type === $(this).data('scope-type'));
    if (scope) updateAssignSelectAllState(scope);
    $('#ectAssignModeEveryone').prop('checked', $('.ect-assign-checkbox:checked').length === 0);
    $('#ectAssignModeSpecific').prop('checked', $('.ect-assign-checkbox:checked').length > 0);
    if (!currentTemplate) return;
    dirty = true;
    updateSaveHint();
});
ECT_ASSIGN_SCOPES.forEach(scope => {
    $(document).on('change', '#' + scope.allId, function () {
        const checkAll = $(this).is(':checked');
        $('#' + scope.listId + ' .ect-assign-checkbox').prop('checked', checkAll);
        updateAssignSelectAllState(scope);
        $('#ectAssignModeEveryone').prop('checked', $('.ect-assign-checkbox:checked').length === 0);
        $('#ectAssignModeSpecific').prop('checked', $('.ect-assign-checkbox:checked').length > 0);
        if (currentTemplate) { dirty = true; updateSaveHint(); }
    });
    $(document).on('input', '#' + scope.filterId, function () {
        const term = $(this).val().toLowerCase().trim();
        $('#' + scope.listId + ' .ect-assign-item').each(function () {
            const match = !term || ($(this).attr('data-search') || '').indexOf(term) !== -1;
            $(this).toggleClass('d-none', !match);
        });
    });
});

function switchToLangTab(lang) {
    activeLang = lang;
    currentLanguage = lang;
    const slot = pairState[lang];
    currentTemplate = slot.template;
    elements = slot.elements;
    logoPath = slot.logoPath;
    dirty = slot.dirty;
    undoStack = slot.undoStack;
    redoStack = slot.redoStack;
    currentPageNumber = slot.currentPageNumber || 1;
    pageCount = slot.pageCount || 1;
    selectedKeys = [];
    updateLangTabsUI();
    updateUndoRedoUI();
    if (!currentTemplate) {
        $('#ectMainTabsWrap').addClass('d-none');
        $('#ectLangEmptyState').removeClass('d-none');
        // These live in the top bar (outside #ectMainTabsWrap), so without clearing them they'd
        // otherwise keep showing whichever OTHER language's values were loaded last -- confusing
        // since there's nothing here to actually save yet.
        $('#ectTemplateNameInput, #ectMarginInput').val('');
        $('#ectPageSizeSelect').val('A4');
        $('#ectOrientationSelect').val('portrait');
        $('#ectIsDefaultSwitch').prop('checked', false);
        updateEctPublishUi();
        currentAssignments = [];
        renderAssignChecklists();
        updateMarginDropdownLabel();
        const otherLang = lang === 'th' ? 'en' : 'th';
        const otherExists = !!pairState[otherLang].template;
        $('#ectLangEmptyGenerateBtn').prop('disabled', !otherExists)
            .attr('title', otherExists ? '' : (langData['ect_generate_auto_needs_other'] || 'The other language needs to exist first.'));
        const langLabel = lang === 'th' ? (langData['template_language_th'] || 'Thai') : (langData['template_language_en'] || 'English');
        $('#ectLangEmptyTitle').text((langData['ect_lang_empty_title'] || 'The {lang} version hasn\'t been created yet.').replace('{lang}', langLabel));
        return;
    }
    $('#ectMainTabsWrap').removeClass('d-none');
    $('#ectLangEmptyState').addClass('d-none');
    $('#ectTemplateNameInput').val(currentTemplate.template_name);
    $('#ectPageSizeSelect').val(currentTemplate.page_size);
    $('#ectOrientationSelect').val(currentTemplate.orientation);
    $('#ectMarginInput').val(currentTemplate.margin_mm);
    $('#ectIsDefaultSwitch').prop('checked', !!Number(currentTemplate.is_default));
    updateEctPublishUi();
    currentAssignments = slot.assignments || [];
    renderAssignChecklists();
    updateMarginDropdownLabel();
    updateFontFamilyOptions();
    updateCanvasDimensions();
    $('#ectPage').toggleClass('ect-grid-on', gridOn);
    $('#ectGridToggle').toggleClass('active', gridOn);
    renderCanvas();
    updateSaveHint();
}
function updateLangTabsUI() {
    ['th', 'en'].forEach(lang => {
        const $tab = $(`.ect-lang-tab[data-lang="${lang}"]`);
        $tab.toggleClass('active', lang === activeLang);
        $tab.find('.ect-lang-tab-dot').toggleClass('d-none', !pairState[lang].dirty);
    });
}
$(document).on('click', '.ect-lang-tab', function () {
    const lang = $(this).data('lang');
    if (lang !== activeLang) switchToLangTab(lang);
});
/** Loads a just-created/just-generated template (by id) into pairState[lang] and switches to it --
 *  shared by both the empty-state's "Generate Auto"/"Start from a preset" success paths below. */
function loadIntoPairSlotAndSwitch(lang, id) {
    fetchTemplateIntoSlot(id, function (slot) {
        if (!slot) return;
        pairState[lang] = slot;
        switchToLangTab(lang);
        // This runs on the editor PAGE, not the list -- no #tb_ect_template here to reload directly;
        // nudge any list tab that happens to be open elsewhere the same way a Save does.
        try { localStorage.setItem('ect_list_dirty', String(Date.now())); } catch (e) { /* private browsing etc. */ }
    });
}
/** Bootstraps the standalone editor PAGE for a whole PAIR (2026-08-25, explicit request: "หน้าแก้ไข
 *  ให้เปลี่ยนเป็นการเปิด Tab ใหม่ เพื่อให้การจัดการมีพื้นที่มากขึ้น") -- called once, from
 *  `$(document).ready()`, with the server-resolved `ECT_PAIR_ROW` global (see edit.php ->
 *  EmploymentCertificateTemplateController::editPage() -> getPairByKey()). Fetches whichever
 *  language(s) actually exist up front so switching tabs afterward is instant and never touches the
 *  server again (see the docblock at the top of this file). Unlike the old modal-based version, this
 *  never has to guess at `pair_key` reliability -- the server already resolved it from the URL's own
 *  `{key}` before this page even rendered, so `pairRow.pair_key` is always trustworthy here. */
function bootstrapEditorPage(pairRow) {
    currentPairKey = pairRow.pair_key;
    pairState = { th: freshLangSlot(), en: freshLangSlot() };
    const langsToLoad = ['th', 'en'].filter(l => pairRow[l]);
    loadImageLibrary(function () {
        let remaining = langsToLoad.length;
        langsToLoad.forEach(lang => {
            fetchTemplateIntoSlot(pairRow[lang].id, function (slot) {
                if (slot) pairState[lang] = slot;
                remaining -= 1;
                if (remaining <= 0) {
                    switchToLangTab(pairRow.th ? 'th' : 'en');
                }
            });
        });
    });
}
/** Any unsaved edits on EITHER language tab -- not just whichever one happens to be active right
 *  now -- block leaving without confirmation (pairState[activeLang].dirty is always kept in sync by
 *  updateSaveHint()'s write-through, so this reads both slots directly rather than the `dirty`
 *  global, which only reflects the currently active one). */
function pairHasAnyUnsavedChanges() {
    return pairState.th.dirty || pairState.en.dirty;
}
// 2026-08-25: the editor is its own page/tab, not a modal -- "closing" it means leaving the page.
// The native beforeunload prompt (browser-generic text, can't be customized in modern browsers)
// covers tab-close/refresh/typed-URL/back-button/breadcrumb-navigation for unsaved changes on
// EITHER language. The dedicated in-app "Back to Templates" button/confirm was removed the same day
// (explicit follow-up: "ปุ่ม back to template ตัดออกได้เลย") -- the page's own breadcrumb (see
// edit.php) covers navigating back now, so beforeunload alone is enough.
window.addEventListener('beforeunload', function (e) {
    if (pairHasAnyUnsavedChanges()) {
        e.preventDefault();
        e.returnValue = '';
    }
});
$(document).on('change', '#ectPageSizeSelect, #ectOrientationSelect', function () {
    updateCanvasDimensions();
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
$(document).on('input', '#ectTemplateNameInput', function () { dirty = true; updateSaveHint(); });
$(document).on('click', '#ectTemplateNameEditBtn', function () {
    $('#ectTemplateNameInput').trigger('focus').select();
});
// 2026-08-26, follow-up correction: "Mode Fullscreen หมายถึงให้การตั้งค่าแสดงใน modal fullscreen ครับ" --
// same mechanism as Payslip Template's own editor, see that file's own top-of-file comment for the
// full reasoning (moved away from the browser's native Fullscreen API, silently blocked when
// embedded in an iframe without allow="fullscreen"). #ectEditorContent relocates into
// #ectFullscreenModal's body (anchored by #ectEditorContentAnchor for the return trip) -- every
// handler in this file is $(document).on(...) delegated, unaffected by the DOM move.
$(document).on('click', '#ectFullscreenBtn', function () {
    // The button itself moves INTO the modal once shown (it's part of #ectEditorContent), so
    // clicking it a 2nd time must CLOSE the modal, not show() it again.
    const modalEl = document.getElementById('ectFullscreenModal');
    const instance = bootstrap.Modal.getOrCreateInstance(modalEl);
    if (modalEl.classList.contains('show')) {
        instance.hide();
    } else {
        instance.show();
    }
});
// 2026-08-26, same-day follow-up: "ปุ่ม Save และ Preview อยากให้มาอยู่ที่ modal footer" +
// "การเปิดการตั้งค่าใน fullscreen ปรับให้รองรับเฉพาะหน้า Design" -- direct port of Payslip Template's
// own fullscreen-footer/Design-only restructure, see that file's own comment for the reasoning.
$('#ectFullscreenModal').on('show.bs.modal', function () {
    $('#ectEditorContent').appendTo('#ectFullscreenModalBody').addClass('pst-fullscreen-active');
    $('#ectDesignFooter').appendTo('#ectFullscreenModalFooter');
    // 2026-08-26: direct port of Payslip Template's own title-group-into-modal-header relocation.
    $('#ectEditorTopbar').prependTo('#ectFullscreenModalHeader');
    $('#ectFullscreenBtn i').removeClass('fa-expand').addClass('fa-compress');
});
$('#ectFullscreenModal').on('shown.bs.modal', function () {
    if (typeof updateCanvasDimensions === 'function') updateCanvasDimensions();
    if (typeof applyZoom === 'function') applyZoom();
});
$('#ectFullscreenModal').on('hidden.bs.modal', function () {
    $('#ectEditorContent').insertAfter('#ectEditorContentAnchor').removeClass('pst-fullscreen-active');
    $('#ectDesignFooter').insertAfter('#ectDesignFooterAnchor');
    $('#ectEditorTopbar').insertAfter('#ectEditorTopbarAnchor');
    $('#ectFullscreenBtn i').removeClass('fa-compress').addClass('fa-expand');
    if (typeof updateCanvasDimensions === 'function') updateCanvasDimensions();
    if (typeof applyZoom === 'function') applyZoom();
});

/* ---------- New Template modal (name + page size/orientation + starter preset) ---------- */
function loadPresets() {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.preset-options`,
        method: 'POST', dataType: 'json',
        success: function (res) {
            if (res.status) {
                presetCache = res.data || [];
                renderPresetList();
            }
        }
    });
}
// 2026-08-25, explicit follow-up: "ตอนกด New Template ให้ขึ้นเป็นตัวอย่าง Template คล้ายๆกับ Google Doc"
// -- a small CSS-drawn sketch of each preset's real element layout (approximate positions, not
// pixel-perfect -- there's no PDF-to-image conversion available in this environment to generate a
// real thumbnail, same limitation noted in storage/fonts/thsarabun/NOTICE.md). The eye-icon Preview
// button on each card still opens the REAL rendered PDF for an exact look before choosing.
const PRESET_MOCKUPS = {
    classic: { bars: [
        { left: 15, top: 8, width: 70, height: 6, color: '#333' },
        { left: 20, top: 16, width: 60, height: 4, color: '#bbb' },
        { left: 10, top: 26, width: 80, height: 8, color: '#333' },
        { left: 14, top: 42, width: 72, height: 4, color: '#ddd' },
        { left: 14, top: 48, width: 72, height: 4, color: '#ddd' },
        { left: 14, top: 54, width: 72, height: 4, color: '#ddd' },
        { left: 14, top: 60, width: 50, height: 4, color: '#ddd' },
        { left: 30, top: 80, width: 40, height: 4, color: '#bbb' },
        { left: 30, top: 88, width: 40, height: 4, color: '#333' },
    ] },
    modern: { bars: [
        { left: 6, top: 6, width: 16, height: 12, color: '#e2e2e2', box: true },
        { left: 26, top: 7, width: 60, height: 5, color: '#333' },
        { left: 26, top: 14, width: 55, height: 3, color: '#bbb' },
        { left: 6, top: 28, width: 88, height: 7, color: '#333' },
        { left: 6, top: 40, width: 88, height: 4, color: '#ddd' },
        { left: 6, top: 46, width: 88, height: 4, color: '#ddd' },
        { left: 6, top: 52, width: 88, height: 4, color: '#ddd' },
        { left: 6, top: 58, width: 60, height: 4, color: '#ddd' },
        { left: 6, top: 82, width: 30, height: 4, color: '#bbb' },
        { left: 58, top: 82, width: 30, height: 4, color: '#333' },
    ] },
    minimal: { bars: [
        { left: 10, top: 10, width: 60, height: 6, color: '#333' },
        { left: 10, top: 22, width: 80, height: 4, color: '#ddd' },
        { left: 10, top: 28, width: 80, height: 4, color: '#ddd' },
        { left: 10, top: 34, width: 80, height: 4, color: '#ddd' },
        { left: 10, top: 40, width: 55, height: 4, color: '#ddd' },
        { left: 10, top: 68, width: 60, height: 4, color: '#bbb' },
    ] },
    formal: { bars: [
        { left: 15, top: 6, width: 70, height: 5, color: '#7a1f1f' },
        { left: 25, top: 13, width: 50, height: 3, color: '#bbb' },
        { left: 20, top: 18, width: 60, height: 2, color: '#bbb' },
        { left: 15, top: 24, width: 70, height: 1.5, color: '#c99' },
        { left: 15, top: 29, width: 70, height: 7, color: '#7a1f1f' },
        { left: 20, top: 43, width: 60, height: 4, color: '#ddd' },
        { left: 20, top: 49, width: 60, height: 4, color: '#ddd' },
        { left: 20, top: 55, width: 60, height: 4, color: '#ddd' },
        { left: 15, top: 76, width: 70, height: 1.5, color: '#c99' },
        { left: 30, top: 81, width: 40, height: 3, color: '#bbb' },
        { left: 30, top: 88, width: 40, height: 4, color: '#333' },
    ] },
    elegant: { bars: [
        { left: 6, top: 5, width: 14, height: 10, color: '#ffd9a3', box: true },
        { left: 24, top: 6, width: 60, height: 5, color: '#FF9900' },
        { left: 24, top: 13, width: 60, height: 6, color: '#FF9900' },
        { left: 8, top: 26, width: 82, height: 4, color: '#ddd' },
        { left: 8, top: 32, width: 82, height: 4, color: '#ddd' },
        { left: 8, top: 38, width: 82, height: 4, color: '#ddd' },
        { left: 8, top: 44, width: 60, height: 4, color: '#ddd' },
        { left: 8, top: 60, width: 70, height: 3, color: '#e6e6e6' },
        { left: 54, top: 80, width: 38, height: 3, color: '#bbb' },
        { left: 54, top: 87, width: 38, height: 4, color: '#333' },
    ] },
};
function renderPresetMockup(code) {
    const cfg = PRESET_MOCKUPS[code];
    if (!cfg) {
        return `<div class="ect-preset-mock ect-preset-mock-blank"><i class="fa-solid fa-plus"></i></div>`;
    }
    const bars = cfg.bars.map(b => {
        const radius = b.box ? '3px' : '1px';
        return `<span style="position:absolute;left:${b.left}%;top:${b.top}%;width:${b.width}%;height:${b.height}%;background:${b.color};border-radius:${radius};"></span>`;
    }).join('');
    return `<div class="ect-preset-mock">${bars}</div>`;
}
function renderPresetList() {
    const $wrap = $('#ectPresetList').empty();
    presetCache.forEach(p => {
        const label = currentLang === 'th' ? p.name_th : p.name_en;
        const previewBtn = p.code !== 'blank'
            ? `<button type="button" class="btn btn-link btn-sm ect-preset-preview-btn" data-code="${p.code}" title="${langData['preview'] || 'Preview'}"><i class="fa-solid fa-eye"></i></button>`
            : '';
        $wrap.append(`
            <div class="ect-preset-card ${chosenPreset === p.code ? 'active' : ''}" data-code="${p.code}">
                ${renderPresetMockup(p.code)}
                <div class="ect-preset-card-footer">
                    <span class="ect-preset-card-label">${escapeHtmlEct(label)}</span>
                    ${previewBtn}
                </div>
            </div>
        `);
    });
}
// 2026-08-25, TH/EN unification: the New Template modal is now opened from 3 different places, all
// distinguished by `modalMode` -- 'create' (list toolbar's plain "+ Template", brand new unpaired
// pair), 'create-in-pair' (a pair's empty-state "start from a preset" for its missing language,
// locked language + carries the pair_key via #ectNewTemplatePairKey), and 'replace'
// (#ectChangePresetBtn's "Change Layout", destructive re-apply to what's already open -- hides the
// name/page-size/orientation fields entirely since nothing new is being created). Generate Auto
// never opens this modal at all -- it's a direct one-click API call from the empty-state, see
// #ectLangEmptyGenerateBtn below.
function openNewTemplateModal(opts) {
    opts = opts || {};
    modalMode = opts.mode || 'create';
    chosenPreset = 'blank';
    $('#ectNewTemplateNameInput').val(opts.templateName || '');
    $('#ectNewPageSizeSelect').val(opts.pageSize || 'A4');
    $('#ectNewOrientationSelect').val(opts.orientation || 'portrait');
    $('#ectNewTemplateLanguageSelect').val(opts.language || 'th').prop('disabled', !!opts.lockLanguage);
    $('#ectNewTemplatePairKey').val(opts.pairKey || '');
    if (opts.pairHint) {
        $('#ectNewTemplatePairHint').text(opts.pairHint).removeClass('d-none');
    } else {
        $('#ectNewTemplatePairHint').addClass('d-none');
    }
    const isReplace = modalMode === 'replace';
    $('#ectNewTemplateModal .row.g-3').toggleClass('d-none', isReplace);
    if (isReplace) {
        $('#ectNewTemplateModalTitle').text(langData['ect_change_preset'] || 'Change Layout').removeAttr('data-i18n');
        $('#ectCreateTemplateBtnLabel').text(langData['apply'] || 'Apply').removeAttr('data-i18n');
    } else {
        $('#ectNewTemplateModalTitle').text(langData['ect_new_template'] || 'New Template').attr('data-i18n', 'ect_new_template');
        $('#ectCreateTemplateBtnLabel').text(langData['create'] || 'Create').attr('data-i18n', 'create');
    }
    loadPresets();
    new bootstrap.Modal(document.getElementById('ectNewTemplateModal')).show();
}
$(document).on('click', '.btn-add-ect', function () {
    openNewTemplateModal({ mode: 'create' });
});
$(document).on('click', '.ect-preset-card', function (e) {
    if ($(e.target).closest('.ect-preset-preview-btn').length) return;
    chosenPreset = $(this).data('code');
    $('.ect-preset-card').removeClass('active');
    $(this).addClass('active');
});
$(document).on('click', '.ect-preset-preview-btn', function (e) {
    e.stopPropagation();
    const preset = $(this).data('code');
    streamPreviewBlob(`${BASE_URL}/api/employment-certificate-template.preset-preview`, {
        language: $('#ectNewTemplateLanguageSelect').val(), preset
    }, 'Preview failed.');
});
$(document).on('click', '#ectCreateTemplateBtn', function () {
    // 'replace' (explicit request, resolved via AskUserQuestion: "เปลี่ยนดีไซน์ตั้งต้นของ Template ที่
    // กำลังแก้") -- fetches ONLY the chosen preset's elements (no new row created anywhere) and, after
    // confirming since it's destructive, replaces the CURRENTLY open canvas's elements client-side.
    // Nothing is persisted here -- the admin still has to hit the normal Save afterward.
    if (modalMode === 'replace') {
        $.ajax({
            url: `${BASE_URL}/api/employment-certificate-template.preset-elements`,
            method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ language: activeLang, preset: chosenPreset }),
            dataType: 'json',
            success: function (res) {
                if (!res.status) {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                    return;
                }
                showConfirm(
                    langData['ect_change_preset'] || 'Change Layout',
                    langData['ect_change_preset_confirm'] || 'This replaces every element currently on the canvas with this layout (Ctrl+Z can still undo it afterward). Continue?',
                    function () {
                        pushUndo();
                        elements = (res.data || []).map(e => Object.assign(emptyElementBase(), {
                            key: newElementKey(), id: null,
                            element_type: e.element_type, field_key: e.field_key || null,
                            image_asset_id: e.image_asset_id ? Number(e.image_asset_id) : null, content: e.content,
                            pos_x_pct: Number(e.pos_x_pct), pos_y_pct: Number(e.pos_y_pct), width_pct: Number(e.width_pct), height_pct: Number(e.height_pct),
                            font_size: Number(e.font_size), font_family: e.font_family, font_color: e.font_color,
                            text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration,
                            group_key: e.group_key || null
                        }));
                        selectedKeys = [];
                        renderCanvas();
                        dirty = true;
                        updateSaveHint();
                        bootstrap.Modal.getInstance(document.getElementById('ectNewTemplateModal')).hide();
                    }
                );
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    const name = $('#ectNewTemplateNameInput').val().trim();
    if (!name) {
        showWarning(langData['ect_select_template_name_required'] || 'Please enter a template name.');
        return;
    }
    const language = $('#ectNewTemplateLanguageSelect').val();
    const pageSize = $('#ectNewPageSizeSelect').val();
    const orientation = $('#ectNewOrientationSelect').val();
    const pairKey = $('#ectNewTemplatePairKey').val() || null;
    const createInPair = modalMode === 'create-in-pair';
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.create-from-preset`,
        method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ language, preset: chosenPreset, template_name: name, pair_key: pairKey }),
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            bootstrap.Modal.getInstance(document.getElementById('ectNewTemplateModal')).hide();
            // What happens once creation (and, below, the page-size/orientation fixup) settles
            // differs by mode: 'create-in-pair' (this modal opened FROM an already-open editor page's
            // empty-state) loads straight into the current page's matching tab -- no navigation.
            // 'create' (the list toolbar's "+ Template", brand new unpaired pair) opens the new
            // standalone editor page in a NEW TAB instead (2026-08-25, explicit request: "เลือก
            // template แล้วกดสร้างก็ให้เปิด Tab ใหม่เหมือนกัน") -- `res.pair_key` comes straight back from
            // save() now (added specifically so this doesn't need an extra round trip first).
            function proceed() {
                if (createInPair) {
                    loadIntoPairSlotAndSwitch(language, res.template_id);
                } else {
                    const newPairKey = pairKey || res.pair_key;
                    window.open(`${BASE_URL}/employment-certificate/edit/${encodeURIComponent(newPairKey)}`, '_blank');
                    $('#tb_ect_template').DataTable().ajax.reload(null, false);
                }
            }
            // Page size/orientation aren't part of createFromPreset()'s own payload (it only takes
            // preset/name) -- apply them with a normal save() right after, before the admin ever
            // sees the canvas, so the New Template modal's fields aren't silently ignored.
            if (pageSize !== 'A4' || orientation !== 'portrait') {
                $.ajax({
                    url: `${BASE_URL}/api/employment-certificate-template.get`,
                    method: 'GET', data: { id: res.template_id }, dataType: 'json',
                    success: function (getRes) {
                        if (!getRes.status) { proceed(); return; }
                        const t = getRes.data;
                        $.ajax({
                            url: `${BASE_URL}/api/employment-certificate-template.save`,
                            method: 'POST', contentType: 'application/json',
                            data: JSON.stringify({
                                id: t.id, language: t.language, template_name: t.template_name,
                                page_size: pageSize, orientation: orientation, margin_mm: t.margin_mm, logo_path: t.logo_path,
                                elements: (t.elements || []).map(e => ({
                                    element_type: e.element_type, field_key: e.field_key, image_asset_id: e.image_asset_id, content: e.content,
                                    pos_x_pct: e.pos_x_pct, pos_y_pct: e.pos_y_pct, width_pct: e.width_pct, height_pct: e.height_pct,
                                    font_size: e.font_size, font_family: e.font_family, font_color: e.font_color,
                                    text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration,
                                    group_key: e.group_key || null, page_number: e.page_number || 1, is_visible: e.is_visible
                                }))
                            }),
                            dataType: 'json',
                            complete: proceed
                        });
                    },
                    error: proceed
                });
            } else {
                proceed();
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});
/* ---------- Empty-state actions (2026-08-25, TH/EN-tabs unification) -- Generate Auto / start from
   a preset for whichever language tab is currently active but hasn't been created yet. ---------- */
$(document).on('click', '#ectLangEmptyGenerateBtn', function () {
    const otherLang = activeLang === 'th' ? 'en' : 'th';
    const sourceTpl = pairState[otherLang].template;
    if (!sourceTpl) return;
    showConfirm(
        langData['ect_generate_auto'] || 'Generate Auto',
        langData['ect_generate_auto_confirm'] || 'This will clone the other language\'s layout (positions, sizes, fonts) as a starting point. You can edit it afterward. Continue?',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/employment-certificate-template.generate-other-language`,
                method: 'POST', contentType: 'application/json',
                data: JSON.stringify({ id: sourceTpl.id }),
                dataType: 'json',
                success: function (res) {
                    if (!res.status) {
                        showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                        return;
                    }
                    loadIntoPairSlotAndSwitch(activeLang, res.template_id);
                    showSuccess(langData['ect_generate_auto_done'] || 'Generated. You can now edit it.');
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
            });
        }
    );
});
$(document).on('click', '#ectLangEmptyPresetBtn', function () {
    const otherLang = activeLang === 'th' ? 'en' : 'th';
    const otherTpl = pairState[otherLang].template;
    openNewTemplateModal({
        mode: 'create-in-pair',
        language: activeLang,
        lockLanguage: true,
        pairKey: currentPairKey,
        templateName: otherTpl ? otherTpl.template_name : '',
        pageSize: otherTpl ? otherTpl.page_size : 'A4',
        orientation: otherTpl ? otherTpl.orientation : 'portrait'
    });
});
/* ---------- "Change Layout" (explicit request, resolved via AskUserQuestion: re-applies a
   different preset's ELEMENTS to what's open right now, not a "switch to a different existing
   template" picker) ---------- */
$(document).on('click', '#ectChangePresetBtn', function () {
    if (!currentTemplate) return;
    openNewTemplateModal({
        mode: 'replace',
        language: activeLang,
        lockLanguage: true
    });
});

/* ---------- Save (inside #ectEditModal) ---------- */
function elementsPayload() {
    // 2026-08-25, real bug found and fixed: this mapping never included `page_number` at all, so
    // EVERY element -- no matter which page it was actually placed on in the editor -- silently
    // reset to the server's own default of page 1 the moment it was serialized for Save or Preview
    // (EmploymentCertificateTemplateModel::validateElements() falls back to 1 when the field is
    // absent). Placing something on page 2/3 and then hitting Preview looked exactly like "the new
    // pages don't show up" -- they weren't missing, everything was just silently collapsing back
    // onto page 1 before it ever reached the server. Fixed by forwarding the in-memory value.
    // 2026-08-26: is_visible added here too, per v12's own lesson above (this function has no
    // schema-driven fallback -- every element property has to be listed by hand, easy to add a new
    // one to emptyElementBase()/the model without remembering this one central serialization point
    // also needs updating).
    return elements.map(e => ({
        element_type: e.element_type, field_key: e.field_key, image_asset_id: e.image_asset_id, content: e.content,
        pos_x_pct: e.pos_x_pct, pos_y_pct: e.pos_y_pct, width_pct: e.width_pct, height_pct: e.height_pct,
        font_size: e.font_size, font_family: e.font_family, font_color: e.font_color,
        text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration,
        group_key: e.group_key || null, page_number: e.page_number || 1, is_visible: e.is_visible !== false
    }));
}
// 2026-08-25, TH/EN-tabs unification: Save now saves ONLY the currently active language tab and
// deliberately does NOT close #ectEditModal afterward -- with two independent tabs open at once,
// closing on save would silently discard whatever the OTHER tab still has unsaved (defeating the
// whole "switch tabs without losing work" point). The admin closes the modal explicitly (X button)
// once both tabs (or however many they're using) are saved, at which point the "any unsaved changes
// on either language" close-guard (pairHasAnyUnsavedChanges()) protects them either way.
// 2026-08-26, explicit request: "เพิ่มให้ติ๊กได้ว่าต้องการให้ Auto Save" -- extracted into a named
// function so both the Save button click AND the debounced autosave call the exact same logic.
function saveEctTemplate(silent) {
    if (!currentTemplate) return;
    const templateName = $('#ectTemplateNameInput').val().trim();
    if (!templateName) {
        if (!silent) showWarning(langData['ect_select_template_name_required'] || 'Please enter a template name.');
        return;
    }
    const pageSize = $('#ectPageSizeSelect').val();
    const orientation = $('#ectOrientationSelect').val();
    const marginMm = parseFloat($('#ectMarginInput').val()) || 0;
    const payload = {
        id: currentTemplate.id, language: currentLanguage, template_name: templateName,
        page_size: pageSize, orientation: orientation, margin_mm: marginMm,
        auto_save: $('#ectAutoSaveSwitch').is(':checked'),
        // 2026-08-26, explicit request: "เพิ่ม Set as default template ใน เอกสารด้วย" -- must be sent
        // explicitly on every save (see EmploymentCertificateTemplateModel::save()'s own comment on
        // this being a real, bidirectional field now, not something that survives being omitted).
        is_default: $('#ectIsDefaultSwitch').is(':checked'),
        logo_path: logoPath, elements: elementsPayload(), assignments: collectAssignments()
    };
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.save`,
        method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                if (!silent) showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                currentTemplate.template_name = templateName;
                currentTemplate.page_size = pageSize;
                currentTemplate.orientation = orientation;
                currentTemplate.margin_mm = marginMm;
                currentTemplate.is_default = payload.is_default;
                if (pairState[activeLang]) pairState[activeLang].assignments = payload.assignments;
                dirty = false;
                updateSaveHint();
                // The list now lives in a DIFFERENT browser tab -- see the 'storage' event listener
                // in $(document).ready() for why this is how it finds out to refresh itself.
                try { localStorage.setItem('ect_list_dirty', String(Date.now())); } catch (e) { /* private browsing etc. -- list just won't auto-refresh */ }
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
}
// Both the Design tab's and the Assign To tab's own Save buttons share this one class -- see the
// view's own comment on why this is a visual/UX change, not a split into two independent partial saves.
$(document).on('click', '.ect-save-btn', function () {
    saveEctTemplate(false);
});
$(document).on('change', '#ectAutoSaveSwitch, #ectIsDefaultSwitch', function () { dirty = true; updateSaveHint(); });
// 2026-08-26, explicit follow-up: "ให้มี switch ปิดเปิด Draft Public เหมือนกัน หรือทำเป็น radio switch
// ให้กดว่า Draft หรือ Public" -- 2-button segmented control, direct port of PayslipTemplateModel's
// own updatePstPublishUi()/#pstPublishToggleGroup pairing.
function updateEctPublishUi() {
    const hasId = !!(currentTemplate && currentTemplate.id);
    $('#ectPublishToggleGroup .pst-publish-option').prop('disabled', !hasId);
    $('#ectAutoSaveSwitch').prop('checked', hasId ? !!currentTemplate.auto_save : false);
    const status = hasId ? (currentTemplate.publish_status || 'draft') : 'draft';
    $('#ectPublishToggleGroup .pst-publish-option').each(function () {
        $(this).toggleClass('active', $(this).data('value') === status);
    });
}
$(document).on('click', '#ectPublishToggleGroup .pst-publish-option', function () {
    if (!currentTemplate || !currentTemplate.id) return;
    const $btn = $(this);
    const target = $btn.data('value');
    const current = currentTemplate.publish_status || 'draft';
    if (target === current) return;
    const doToggle = function () {
        $.ajax({
            url: `${BASE_URL}/api/employment-certificate-template.publish-toggle`,
            method: 'POST', data: { id: currentTemplate.id, publish_status: target }, dataType: 'json',
            success: function (res) {
                if (res.status) {
                    currentTemplate.publish_status = target;
                    updateEctPublishUi();
                    try { localStorage.setItem('ect_list_dirty', String(Date.now())); } catch (e) { /* private browsing etc. */ }
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
    };
    if (target === 'draft') {
        showConfirm(langData['ect_confirm_unpublish'] || 'Switch this template back to Draft? It will stop being used for real generation immediately.', '', doToggle);
    } else {
        doToggle();
    }
});

/* ---------- Preview (streams a PDF back -- fetch + Content-Type sniffing, since jQuery ajax can't
   handle a blob response). Renders the LIVE unsaved canvas state inside the editor page. Watermark is
   preview-only -- never sent to save(). ---------- */
// 2026-08-25, real bug found (not just relocated): "Water Mark เหมือนยังไม่ทำงานครับ" -- turning the
// toggle ON revealed the text input with "SAMPLE" as its PLACEHOLDER only, never an actual value.
// The renderer correctly treats blank/whitespace watermark text as "render nothing" (by design, see
// EmploymentCertificateRenderer::buildHtml()), so toggling on with an empty field looked exactly
// like the feature doing nothing. Now auto-fills a real "SAMPLE" value the first time it's turned on
// with nothing typed yet, so the toggle alone produces a visible watermark.
$(document).on('change', '#ectWatermarkToggle', function () {
    const checked = this.checked;
    $('#ectWatermarkText').toggleClass('d-none', !checked);
    if (checked && !$('#ectWatermarkText').val().trim()) {
        $('#ectWatermarkText').val('SAMPLE');
    }
});
$(document).on('click', '#ectPreviewBtn', function () {
    if (!elements.length) {
        showWarning(langData['ect_add_element_first'] || 'Add at least one element before previewing.');
        return;
    }
    streamPreviewBlob(`${BASE_URL}/api/employment-certificate-template.preview`, {
        language: currentLanguage, logo_path: logoPath,
        page_size: $('#ectPageSizeSelect').val(), orientation: $('#ectOrientationSelect').val(),
        elements: elementsPayload(),
        watermark_enabled: $('#ectWatermarkToggle').is(':checked'),
        watermark_text: $('#ectWatermarkText').val()
    }, 'Preview failed.');
});

// 2026-08-25: this one shared JS file now serves THREE different host pages -- the list (either
// standalone or Payslip Settings' 3rd tab, both with `#tb_ect_template`), and the standalone editor
// page (`#ectPage`/`window.ECT_PAIR_ROW`, opened in its own tab). Everything in $(document).ready()
// below is guarded by which of those elements/globals actually exist, so loading this file on
// either page only runs that page's own init.
$(document).ready(function () {
    if ($('#ectPage').length) {
        $.ajax({
            url: `${BASE_URL}/api/employment-certificate-template.field-options`,
            method: 'POST', dataType: 'json',
            success: function (res) {
                if (res.status) {
                    fieldTypesCache = res.data || [];
                    renderPalette();
                }
            }
        });
        // Company-wide, fetched once -- see companyLogoPath's own declaration comment for why this
        // replaced the per-template logo upload for the "Company Logo" canvas element's preview.
        $.ajax({
            url: `${BASE_URL}/api/company.get`,
            method: 'POST', dataType: 'json',
            success: function (res) {
                if (res.status && res.data) {
                    companyLogoPath = res.data.logo_path || null;
                    companySignaturePath = res.data.signature_path || null;
                }
            }
        });
        updateFontFamilyOptions();
        loadAssignableOptions();
        if (typeof ECT_PAIR_ROW !== 'undefined') {
            bootstrapEditorPage(ECT_PAIR_ROW);
        }
    }
    if ($('#tb_ect_template').length) {
        // Two host pages: the standalone route (table always visible, init now) and Payslip
        // Settings' 3rd tab (table starts hidden -- initializing a `responsive: true` DataTable
        // while hidden collapses every column to 0 width, same DataTables-in-a-Bootstrap-tab gotcha
        // every other hidden-tab table on this page already works around, see payslip-template.js/
        // payslip-distribution.js's own shown.bs.tab handlers). $('#employmentCertificateTemplateTabBtn')
        // only exists in the merged-page DOM, so its presence is what tells the two cases apart.
        const $ectTabBtn = $('#employmentCertificateTemplateTabBtn');
        if ($ectTabBtn.length) {
            $ectTabBtn.on('shown.bs.tab', function () {
                initEctTemplateTable();
            });
        } else {
            initEctTemplateTable();
        }
        // 2026-08-25: editing now happens in a SEPARATE browser tab, so this list tab has no direct
        // way to know when a save happens over there -- the `.ect-save-btn` click handler's success
        // callback writes a
        // localStorage key on every successful save, which fires a native 'storage' event in every
        // OTHER tab of the same origin (never in the tab that wrote it), letting this list quietly
        // refresh itself instead of showing stale data until manually reloaded.
        window.addEventListener('storage', function (e) {
            if (e.key === 'ect_list_dirty' && $.fn.DataTable.isDataTable('#tb_ect_template')) {
                $('#tb_ect_template').DataTable().ajax.reload(null, false);
            }
        });
        // 2026-08-26, explicit follow-up: "การจัดการถ้ามีการเปิด Tab ใหม่ จะต้อง Reload ตารางหลังจาก
        // Save" -- direct port of Payslip Template's own visibilitychange fallback (see that file's
        // own comment), a second, more robust path that doesn't depend on the storage event alone.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && $.fn.DataTable.isDataTable('#tb_ect_template')) {
                $('#tb_ect_template').DataTable().ajax.reload(null, false);
            }
        });
    }
});
})();
