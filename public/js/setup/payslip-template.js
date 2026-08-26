/**
 * Payslip Template canvas designer -- 2026-08-25, explicit request: "ปรับให้การตั้งค่า Slip เงินเดือน
 * Template เป็นเหมือนกับใบรับรอง" (make it like the Employment Certificate Template designer),
 * confirmed via AskUserQuestion: a FULL canvas designer (free drag/resize/ribbon/layers/multi-page/
 * Insert Table-Shape-Symbol/Image Library/presets/standalone editor page), not just matching page
 * chrome. This file is ported directly from `employment-certificate-template.js` -- every generic
 * canvas-interaction mechanism (drag/resize, multi-select+Group/Ungroup, Layers panel, Undo/Redo,
 * pan/zoom, grid, margin guide, Insert Table/Shape/Symbol, Image Library multi-select) carries over
 * UNCHANGED, including every bug fix that module already went through (page_number actually being
 * sent to the server, company_logo droppable more than once, the Symbol-dropdown CSS specificity-tie
 * fix, e.code-not-e.key keyboard shortcuts, etc. -- see CLAUDE.md's Employment Certificate Template
 * v1-v12 sections for the full history of what each of those fixed and why).
 *
 * 2026-08-25, same-day follow-up ("การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และรูปแบบการทำเหมือนกัน") --
 * the original "NO pair_key / TH-EN-tabs machinery at all" decision documented below was REVERSED.
 * `pairState`/`freshLangSlot()`/`activeLang`/`currentPairKey`/`switchToLangTab()` are now direct
 * ports of Employment Certificate Template's own mechanism (see that file's own top-of-file docblock
 * for the full "shallow reference swap, not a deep clone" reasoning -- not repeated here). The ONE
 * real remaining difference: `is_default`/`header_text_*`/`footer_text_*`/`status` are real, KEPT
 * fields (Employment Certificate Template has no equivalent of any of them) -- see the "Template
 * Info" strip in `_editor_content.php` and the extra fields in the Save payload/list table below.
 * `language_mode` itself is GONE -- replaced by the same `language`/`pair_key` pair Employment
 * Certificate Template already has.
 */
(function () {
let currentLanguage = 'th';
let currentTemplate = null; // the template row currently open in the editor page, or null
let elements = [];
let logoPath = null;
let selectedKeys = [];
let gridOn = true;
let dirty = false;
let elementKeyCounter = 0;
let textModalKey = null;
let fieldTypesCache = [];
let presetCache = [];
let imageLibraryCache = [];
let chosenPreset = 'blank';
let tb_pst_template;
// 'create' (list toolbar's "+ Template", brand new unpaired pair), 'create-in-pair' (the empty-
// state's "start from a preset" for a pair's missing language, carries the pair_key via
// #pstNewTemplatePairKey so the new row joins the ALREADY-OPEN pair), or 'replace'
// (#pstChangePresetBtn -- re-applies a different preset's layout to what's open right now,
// destructive, confirmed before overwriting). Direct port of Employment Certificate Template's own
// `modalMode` scheme.
let modalMode = 'create';
/** One language's working state -- see the top-of-file docblock for why this exists instead of
 *  refactoring every existing global-variable call site. Direct port of Employment Certificate
 *  Template's own freshLangSlot(). */
function freshLangSlot() {
    return { template: null, elements: [], dirty: false, logoPath: null, undoStack: [], redoStack: [], currentPageNumber: 1, pageCount: 1, assignments: [] };
}
let pairState = { th: freshLangSlot(), en: freshLangSlot() };
let activeLang = 'th'; // which language tab is currently shown/edited
let currentPairKey = null; // the pair currently open in the editor page, or null
let currentPageNumber = 1;
let pageCount = 1;
let undoStack = [];
let redoStack = [];
const UNDO_LIMIT = 50;
let clipboardElements = [];
let zoomPct = 100;
const ZOOM_LEVELS = [25, 50, 75, 100, 125, 150, 200, 300];
const MARGIN_PRESETS = [
    { code: 'narrow', mm: 8, labelKey: 'ect_margin_narrow' },
    { code: 'normal', mm: 15, labelKey: 'ect_margin_normal' },
    { code: 'moderate', mm: 20, labelKey: 'ect_margin_moderate' },
    { code: 'wide', mm: 30, labelKey: 'ect_margin_wide' },
];
// Company Profile's own logo, fetched once, company-wide -- same fallback priority as Employment
// Certificate Template's own companyLogoPath (template's own logo_path first, this as fallback).
let companyLogoPath = null;
// 2026-08-26, explicit request: "เพิ่มให้แนบลายเซ็นต์ Authorized Signatory Name...และเพิ่มใน Item ในการ
// จัดการ Template" -- company-wide only (no per-template override), fetched alongside the logo above.
let companySignaturePath = null;

// 2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" -- MUST stay
// byte-identical to PayslipTemplateRenderer::PAGE_SIZES_MM (the canvas and the PDF renderer share
// this exact coordinate space for true WYSIWYG, no unit conversion anywhere).
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
function escapeHtmlPst(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function clampPst(v, min, max) {
    if (max < min) max = min;
    return Math.max(min, Math.min(max, v));
}

function updateSaveHint() {
    // Write-through into pairState -- this is the ONE integration point that keeps the tab dirty-
    // dots and the "any unsaved changes across either language" close-guard correct, since every
    // mutation in this whole file already ends with `dirty = true; updateSaveHint();` (or `dirty =
    // false;` after a successful save) -- no other call site needed to change. Direct port of
    // Employment Certificate Template's own updateSaveHint().
    if (pairState[activeLang]) pairState[activeLang].dirty = dirty;
    updateLangTabsUI();
    // 2026-08-25 follow-up: "หน้า Design กับหน้า Assign To ต้องการให้มีปุ่ม Save แยก Tab" -- there are now
    // TWO footers (one per tab), both using the `.pst-save-hint` CLASS (not a unique id), so this
    // updates both at once -- whichever tab you're on always shows the right hint.
    $('.pst-save-hint').text(dirty ? (langData['ect_unsaved_hint'] || 'Unsaved changes — click Save.') : '');
    scheduleAutoSaveIfEnabled();
}
// 2026-08-26, explicit request: "เพิ่มให้ติ๊กได้ว่าต้องการให้ Auto Save" -- debounced (waits for a pause
// in editing, not one save per keystroke/drag-tick) silent save, reusing the exact same save logic
// the Save button itself calls (savePstTemplate()) so autosave can never drift out of sync with a
// manual save. Only runs once the template already has an id (a brand-new, not-yet-created template
// is always created explicitly via a preset/Generate Auto first, see the empty-state handlers below).
let pstAutoSaveTimer = null;
function scheduleAutoSaveIfEnabled() {
    if (!currentTemplate || !currentTemplate.id) return;
    if (!$('#pstAutoSaveSwitch').is(':checked')) return;
    if (!dirty) return;
    clearTimeout(pstAutoSaveTimer);
    pstAutoSaveTimer = setTimeout(function () { savePstTemplate(true); }, 2000);
}
function updateLangTabsUI() {
    ['th', 'en'].forEach(lang => {
        const $tab = $(`.pst-lang-tab[data-lang="${lang}"]`);
        $tab.toggleClass('active', lang === activeLang);
        $tab.find('.pst-lang-tab-dot').toggleClass('d-none', !pairState[lang].dirty);
    });
}

/* ---------- Undo / Redo ---------- */
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
    $('#pstUndoBtn').prop('disabled', !undoStack.length);
    $('#pstRedoBtn').prop('disabled', !redoStack.length);
}
$(document).on('click', '#pstUndoBtn', performUndo);
$(document).on('click', '#pstRedoBtn', performRedo);
let ribbonEditSessionActive = false;
function ribbonPushUndoOnce() {
    if (!ribbonEditSessionActive) {
        pushUndo();
        ribbonEditSessionActive = true;
    }
}
$(document).on('blur', '#pstPropFontSize, #pstPropFontColor', function () { ribbonEditSessionActive = false; });

/* ---------- Zoom ---------- */
function applyZoom() {
    const $page = $('#pstPage');
    if (!$page.length) return;
    const scale = zoomPct / 100;
    $page.css({ transform: `scale(${scale})`, transformOrigin: 'top left' });
    const naturalW = $page[0].offsetWidth;
    const naturalH = $page[0].offsetHeight;
    $('#pstZoomStage').css({ width: (naturalW * scale) + 'px', height: (naturalH * scale) + 'px' });
    $('#pstZoomSelect').val(String(zoomPct));
}
function setZoom(pct) {
    zoomPct = clampPst(pct, 25, 300);
    applyZoom();
}
$(document).on('change', '#pstZoomSelect', function () { setZoom(parseInt($(this).val(), 10) || 100); });
$(document).on('click', '#pstZoomInBtn', function () {
    const next = ZOOM_LEVELS.find(l => l > zoomPct);
    setZoom(next || 300);
});
$(document).on('click', '#pstZoomOutBtn', function () {
    const prev = ZOOM_LEVELS.slice().reverse().find(l => l < zoomPct);
    setZoom(prev || 25);
});
$(document).on('click', '#pstZoomResetBtn', function () { setZoom(100); });

/* ---------- Pan ---------- */
function beginCanvasPan(e) {
    const $scroll = $('.pst-canvas-scroll');
    if (!$scroll.length) return;
    const startX = e.clientX, startY = e.clientY;
    const startLeft = $scroll.scrollLeft(), startTop = $scroll.scrollTop();
    $scroll.addClass('pst-panning');
    function onMove(ev) {
        $scroll.scrollLeft(startLeft - (ev.clientX - startX));
        $scroll.scrollTop(startTop - (ev.clientY - startY));
    }
    function onUp() {
        $(document).off('mousemove.pstPan mouseup.pstPan');
        $scroll.removeClass('pst-panning');
    }
    $(document).on('mousemove.pstPan', onMove).on('mouseup.pstPan', onUp);
}

/* ---------- Page-margin guide ---------- */
function applyMarginGuide() {
    const $page = $('#pstPage');
    if (!$page.length) return;
    $page.find('#pstMarginGuide').remove();
    const marginMm = parseFloat($('#pstMarginInput').val()) || 0;
    if (marginMm <= 0) return;
    const [pageWmm, pageHmm] = pageDimensionsMm($('#pstPageSizeSelect').val() || 'A4', $('#pstOrientationSelect').val() || 'portrait');
    const leftPct = (marginMm / pageWmm) * 100;
    const topPct = (marginMm / pageHmm) * 100;
    const $guide = $('<div class="pst-margin-guide" id="pstMarginGuide"></div>').css({
        left: leftPct + '%', top: topPct + '%',
        right: leftPct + '%', bottom: topPct + '%'
    });
    $page.prepend($guide);
}
function updateMarginDropdownLabel() {
    const mm = parseFloat($('#pstMarginInput').val()) || 0;
    const preset = MARGIN_PRESETS.find(p => p.mm === mm);
    $('#pstMarginDropdownLabel').text(preset
        ? (langData[preset.labelKey] || preset.code)
        : `${langData['ect_margin_custom'] || 'Custom'} (${mm}mm)`);
    $('.pst-margin-option').removeClass('active');
    if (preset) $(`.pst-margin-option[data-value="${mm}"]`).addClass('active');
}
$(document).on('input', '#pstMarginInput', function () {
    applyMarginGuide();
    updateMarginDropdownLabel();
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.pst-margin-option', function (e) {
    e.preventDefault();
    $('#pstMarginInput').val($(this).data('value')).trigger('input');
});

function emptyElementBase() {
    return {
        font_size: 14, font_family: 'th_sarabun_new', font_color: '#000000',
        text_align: 'left', font_weight: 'normal', font_style: 'normal', text_decoration: 'none',
        group_key: null, page_number: currentPageNumber, is_visible: true
    };
}
const FONT_FAMILY_CSS_STACK = {
    th_sarabun_new: "'TH Sarabun New', sans-serif",
    dejavu_sans: "'DejaVu Sans', sans-serif",
    dejavu_sans_mono: "'DejaVu Sans Mono', monospace",
    dejavu_serif: "'DejaVu Serif', serif",
    helvetica: "Helvetica, Arial, sans-serif",
    times_new_roman: "'Times New Roman', Times, serif",
    courier: "'Courier New', Courier, monospace"
};

/* ---------- Field palette (click OR native drag-and-drop onto the canvas), grouped by category --
   master_payslip_field_types.field_group has 7 values (vs Employment Certificate's 3) -- one extra
   group ("document") added specifically for the new static_text field this feature introduced. ---------- */
function paletteIcon(ft) {
    if (ft.element_type === 'image') return 'fa-image';
    if (ft.code === 'static_text') return 'fa-font';
    if (['earning_lines_all', 'deduction_lines_all', 'statutory_lines_all'].includes(ft.code)) return 'fa-list';
    return 'fa-tag';
}
const PALETTE_GROUP_META = {
    employee_info: { icon: 'fa-id-card', labelKey: 'pst_group_employee_info' },
    company_info: { icon: 'fa-building', labelKey: 'pst_group_company_info' },
    earning: { icon: 'fa-sack-dollar', labelKey: 'pst_group_earning' },
    deduction: { icon: 'fa-minus-circle', labelKey: 'pst_group_deduction' },
    statutory: { icon: 'fa-landmark', labelKey: 'pst_group_statutory' },
    summary: { icon: 'fa-calculator', labelKey: 'pst_group_summary' },
    document: { icon: 'fa-file-lines', labelKey: 'ect_group_document' }
};
const PALETTE_GROUP_ORDER = ['employee_info', 'company_info', 'earning', 'deduction', 'statutory', 'summary', 'document'];
function renderPalette() {
    const $wrap = $('#pstFieldPalette').empty();
    const groups = {};
    fieldTypesCache.forEach(ft => {
        const g = ft.field_group || 'document';
        (groups[g] = groups[g] || []).push(ft);
    });
    PALETTE_GROUP_ORDER.filter(g => groups[g] && groups[g].length).forEach(g => {
        const meta = PALETTE_GROUP_META[g] || { icon: 'fa-tag', labelKey: '' };
        const groupLabel = langData[meta.labelKey] || g;
        const $group = $(`
            <div class="pst-palette-group">
                <div class="pst-palette-group-title"><i class="fa-solid ${meta.icon} me-1"></i>${escapeHtmlPst(groupLabel)}</div>
                <div class="pst-palette-grid"></div>
            </div>
        `);
        const $grid = $group.find('.pst-palette-grid');
        groups[g].forEach(ft => {
            const label = currentLang === 'th' ? ft.name_th : ft.name_en;
            $grid.append(`
                <button type="button" draggable="true" class="pst-palette-chip" data-code="${ft.code}" data-element-type="${ft.element_type}" title="${escapeHtmlPst(label)}">
                    <span class="pst-palette-chip-icon"><i class="fa-solid ${paletteIcon(ft)}"></i></span>
                    <span class="pst-palette-chip-label">${escapeHtmlPst(label)}</span>
                </button>
            `);
        });
        $wrap.append($group);
    });
}
function addElementFromPalette(code, elementType, posX, posY) {
    if (!currentTemplate) return;
    let el;
    if (elementType === 'image') {
        el = Object.assign(emptyElementBase(), {
            key: newElementKey(), id: null, element_type: 'image', field_key: code, image_asset_id: null, content: null,
            pos_x_pct: clampPst(posX, 0, 80), pos_y_pct: clampPst(posY, 0, 88), width_pct: 20, height_pct: 10
        });
    } else {
        // The 3 block fields (earning_lines_all/deduction_lines_all/statutory_lines_all) get a
        // generously tall default box since their rendered row-count is unknown at design time --
        // PayslipTemplateRenderer expands them into a real itemized table at generate time; on the
        // canvas they're an ordinary bound {{token}} text element like any other, no special JS
        // handling needed (isBoundFieldElement() already locks them the same way).
        const isBlockField = ['earning_lines_all', 'deduction_lines_all', 'statutory_lines_all'].includes(code);
        el = Object.assign(emptyElementBase(), {
            key: newElementKey(), id: null, element_type: 'text', field_key: null, image_asset_id: null,
            content: code === 'static_text' ? '' : `{{${code}}}`,
            pos_x_pct: clampPst(posX, 0, 60), pos_y_pct: clampPst(posY, 0, 94),
            width_pct: isBlockField ? 60 : 40, height_pct: isBlockField ? 18 : 6
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
        pos_x_pct: clampPst(posX, 0, 80), pos_y_pct: clampPst(posY, 0, 85), width_pct: 20, height_pct: 15
    });
    pushUndo();
    elements.push(el);
    renderCanvas();
    selectElement(el.key);
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '.pst-palette-chip', function () {
    addElementFromPalette($(this).data('code'), $(this).data('element-type'), 10, 10);
});
$(document).on('dragstart', '.pst-palette-chip', function (e) {
    e.originalEvent.dataTransfer.setData('text/plain', JSON.stringify({ code: $(this).data('code'), elementType: $(this).data('element-type') }));
    e.originalEvent.dataTransfer.effectAllowed = 'copy';
});
$('#pstPage').on('dragover', function (e) {
    e.preventDefault();
    if (e.originalEvent.dataTransfer) e.originalEvent.dataTransfer.dropEffect = 'copy';
    $(this).addClass('pst-drop-target');
});
$('#pstPage').on('dragleave', function () {
    $(this).removeClass('pst-drop-target');
});
$('#pstPage').on('drop', function (e) {
    e.preventDefault();
    $(this).removeClass('pst-drop-target');
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
function isBoundFieldElement(el) {
    if (el.element_type !== 'text') return false;
    return /^\{\{[a-z_]+\}\}$/.test((el.content || '').trim());
}
function resolveElementImageUrl(el) {
    if (el.element_type !== 'image') return null;
    if (el.field_key === 'company_logo') {
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
            const text = escapeHtmlPst((cells[r] && cells[r][c]) || '').replace(/\n/g, '<br>');
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
        body = escapeHtmlPst(el.content).replace(/\n/g, '<br>');
    }
    const lockBadge = isBoundFieldElement(el)
        ? `<div class="pst-el-lock" title="${langData['ect_field_locked_hint'] || 'Bound to data — position/size only, double-click to edit is disabled.'}"><i class="fa-solid fa-lock"></i></div>`
        : '';
    let typeClass = 'pst-el-text';
    if (isImage) typeClass = 'pst-el-image';
    else if (isShape) typeClass = `pst-el-shape pst-el-shape-${el.field_key || 'rectangle'}`;
    else if (isTable) typeClass = 'pst-el-table';
    return `
        <div class="pst-el ${typeClass}" data-key="${el.key}">
            <div class="pst-el-body">${body}</div>
            ${lockBadge}
            <div class="pst-quick-delete" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-xmark"></i></div>
            <div class="pst-resize-handle"></div>
        </div>
    `;
}
function applyElementStyle($el, el) {
    $el.css({
        left: el.pos_x_pct + '%', top: el.pos_y_pct + '%',
        width: el.width_pct + '%', height: el.height_pct + '%'
    });
    if (el.element_type === 'shape') {
        $el.find('.pst-el-body').css({
            backgroundColor: el.font_color || '#000000',
            border: `1px solid ${el.font_color || '#000000'}`,
            boxSizing: 'border-box',
            borderRadius: el.field_key === 'ellipse' ? '50%' : '0'
        });
        return;
    }
    $el.find('.pst-el-body').css({
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
    const pageSize = $('#pstPageSizeSelect').val() || 'A4';
    const orientation = $('#pstOrientationSelect').val() || 'portrait';
    const [w, h] = pageDimensionsMm(pageSize, orientation);
    $('#pstPage').css({ width: w + 'mm', height: h + 'mm' });
    applyZoom();
}

/* ---------- Multi-page ---------- */
function syncPageStateToPairState() {
    if (pairState[activeLang]) {
        pairState[activeLang].currentPageNumber = currentPageNumber;
        pairState[activeLang].pageCount = pageCount;
    }
}
function updatePageNavUI() {
    $('#pstPageNavLabel').text(`${langData['ect_page_label'] || 'Page'} ${currentPageNumber} / ${pageCount}`);
    $('#pstPagePrevBtn').prop('disabled', currentPageNumber <= 1);
    $('#pstPageNextBtn').prop('disabled', currentPageNumber >= pageCount);
    $('#pstPageRemoveBtn').prop('disabled', pageCount <= 1);
}
function goToPage(pageNumber) {
    currentPageNumber = clampPst(pageNumber, 1, pageCount);
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
$(document).on('click', '#pstPagePrevBtn', function () { goToPage(currentPageNumber - 1); });
$(document).on('click', '#pstPageNextBtn', function () { goToPage(currentPageNumber + 1); });
$(document).on('click', '#pstPageAddBtn', addPage);
$(document).on('click', '#pstPageRemoveBtn', removeCurrentPage);
function currentPageElements() {
    return elements.filter(e => (e.page_number || 1) === currentPageNumber);
}
function renderCanvas() {
    const $page = $('#pstPage').empty();
    currentPageElements().forEach(el => {
        // 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว"
        // -- same as Employment Certificate Template's own fix: a hidden element is skipped from the
        // canvas entirely (Photoshop convention) and from the generated PDF
        // (PayslipTemplateRenderer::buildHtml()), but still listed in the Layers panel with its eye
        // toggle -- renderLayersPanel() reads currentPageElements() directly, unfiltered.
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
        if ($(e.target).hasClass('pst-resize-handle') || $(e.target).closest('.pst-quick-delete').length) return;
        e.preventDefault();
        const additive = e.ctrlKey || e.metaKey;
        if (additive) {
            selectElement(el.key, true);
        } else if (!selectedKeys.includes(el.key)) {
            selectElement(el.key, false);
        }
        if (!selectedKeys.length) return;
        const page = document.getElementById('pstPage');
        const pageRect = page.getBoundingClientRect();
        const startX = e.clientX, startY = e.clientY;
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
                se.pos_x_pct = clampPst(start.x + dxPct, 0, 100 - se.width_pct);
                se.pos_y_pct = clampPst(start.y + dyPct, 0, 100 - se.height_pct);
                applyElementStyle($(`.pst-el[data-key="${k}"]`), se);
            });
            dirty = true;
            updateSaveHint();
        }
        function onUp() {
            $(document).off('mousemove.pstDrag mouseup.pstDrag');
        }
        $(document).on('mousemove.pstDrag', onMove).on('mouseup.pstDrag', onUp);
    });

    $el.find('.pst-resize-handle').on('mousedown', function (e) {
        e.stopPropagation();
        e.preventDefault();
        selectedKeys = [el.key];
        applySelectionClasses();
        updateSelectionUI();
        renderLayersPanel();
        const page = document.getElementById('pstPage');
        const pageRect = page.getBoundingClientRect();
        const startX = e.clientX, startY = e.clientY;
        const startW = el.width_pct, startH = el.height_pct;
        let resizeUndoPushed = false;
        function onMove(ev) {
            if (!resizeUndoPushed) { pushUndo(); resizeUndoPushed = true; }
            const dwPct = (ev.clientX - startX) / pageRect.width * 100;
            const dhPct = (ev.clientY - startY) / pageRect.height * 100;
            el.width_pct = clampPst(startW + dwPct, 3, 100 - el.pos_x_pct);
            el.height_pct = clampPst(startH + dhPct, 2, 100 - el.pos_y_pct);
            applyElementStyle($el, el);
            dirty = true;
            updateSaveHint();
        }
        function onUp() {
            $(document).off('mousemove.pstResize mouseup.pstResize');
        }
        $(document).on('mousemove.pstResize', onMove).on('mouseup.pstResize', onUp);
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

/* ---------- Selection (multi-select + Group/Ungroup) + ribbon ---------- */
function selectionGroupKeys(key) {
    const el = findElement(key);
    if (!el) return [];
    if (el.group_key) {
        return elements.filter(e => e.group_key === el.group_key).map(e => e.key);
    }
    return [key];
}
function applySelectionClasses() {
    $('.pst-el').removeClass('pst-el-selected');
    selectedKeys.forEach(k => $(`.pst-el[data-key="${k}"]`).addClass('pst-el-selected'));
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
    const $fontControls = $('#pstPropFontSize, #pstPropFontFamily, #pstPropFontColor, .pst-align-btn, .pst-style-btn');
    if (!el) {
        $fontControls.prop('disabled', true);
        $('.pst-align-btn, .pst-style-btn').removeClass('active');
        $('#pstRibbonHint').removeClass('d-none');
    } else {
        $fontControls.prop('disabled', false);
        $('#pstRibbonHint').addClass('d-none');
        $('#pstPropFontSize').val(el.font_size);
        $('#pstPropFontFamily').val(el.font_family);
        $('#pstPropFontColor').val(el.font_color);
        $('.pst-align-btn').removeClass('active');
        $(`.pst-align-btn[data-align="${el.text_align}"]`).addClass('active');
        $('.pst-style-btn[data-style="bold"]').toggleClass('active', el.font_weight === 'bold');
        $('.pst-style-btn[data-style="italic"]').toggleClass('active', el.font_style === 'italic');
        $('.pst-style-btn[data-style="underline"]').toggleClass('active', el.text_decoration === 'underline');
    }
    $('#pstDeleteElementBtn').prop('disabled', selectedKeys.length === 0);
    $('#pstGroupBtn').prop('disabled', selectedKeys.length < 2);
    const hasGroupedSelection = selectedKeys.some(k => { const e = findElement(k); return e && e.group_key; });
    $('#pstUngroupBtn').prop('disabled', !hasGroupedSelection);
    ribbonEditSessionActive = false;
}
$('#pstPage').on('mousedown', function (e) {
    if (e.target === this) {
        clearSelection();
        beginCanvasPan(e);
    }
});
$(document).on('input', '#pstPropFontSize', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    ribbonPushUndoOnce();
    el.font_size = parseInt($(this).val(), 10) || 14;
    applyElementStyle($(`.pst-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('change', '#pstPropFontFamily', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    pushUndo();
    el.font_family = $(this).val();
    applyElementStyle($(`.pst-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('input', '#pstPropFontColor', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    ribbonPushUndoOnce();
    el.font_color = $(this).val();
    applyElementStyle($(`.pst-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.pst-align-btn', function () {
    const el = findElement(singleSelectedKey());
    if (!el) return;
    pushUndo();
    el.text_align = $(this).data('align');
    $('.pst-align-btn').removeClass('active');
    $(this).addClass('active');
    applyElementStyle($(`.pst-el[data-key="${el.key}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.pst-style-btn', function () {
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
    applyElementStyle($(`.pst-el[data-key="${el.key}"]`), el);
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
$(document).on('click', '#pstDeleteElementBtn', deleteSelectedElements);
$(document).on('click', '.pst-quick-delete', function (e) {
    e.stopPropagation();
    pushUndo();
    const key = $(this).closest('.pst-el').data('key');
    elements = elements.filter(el => el.key !== key);
    selectedKeys = selectedKeys.filter(k => k !== key);
    renderCanvas();
    dirty = true;
    updateSaveHint();
});

/* ---------- Duplicate (Ctrl+D) / Copy+Paste (Ctrl+C/Ctrl+V) / arrow-key nudge ---------- */
function generateGroupKey() {
    return 'grp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
}
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
            pos_x_pct: clampPst(src.pos_x_pct + offsetPct, 0, 100 - src.width_pct),
            pos_y_pct: clampPst(src.pos_y_pct + offsetPct, 0, 100 - src.height_pct),
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
        se.pos_x_pct = clampPst(se.pos_x_pct + dx, 0, 100 - se.width_pct);
        se.pos_y_pct = clampPst(se.pos_y_pct + dy, 0, 100 - se.height_pct);
        applyElementStyle($(`.pst-el[data-key="${k}"]`), se);
    });
    dirty = true;
    updateSaveHint();
}
// #pstPage only exists on the editor page's own markup -- guards every shortcut here to that page,
// same as Employment Certificate Template's own fix for this (see that module's v8/v9 history: the
// guard used to check a now-deleted modal's .show class, which silently broke every shortcut once
// the editor stopped being a modal -- checking for #pstPage's presence avoids repeating that bug).
$(document).on('keydown', function (e) {
    if (!$('#pstPage').length) return;
    const tag = (e.target.tagName || '').toLowerCase();
    const isTyping = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;
    if ((e.key === 'Delete' || e.key === 'Backspace') && selectedKeys.length && !isTyping) {
        e.preventDefault();
        deleteSelectedElements();
        return;
    }
    // e.code (physical key), not e.key (layout-dependent) -- real bug already found/fixed once in
    // Employment Certificate Template (Thai keyboard layout breaks e.key==='z' etc. entirely).
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

/* ---------- Group / Ungroup ---------- */
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
$(document).on('click', '#pstGroupBtn', groupSelectedElements);
$(document).on('click', '#pstUngroupBtn', ungroupSelectedElements);

/* ---------- Layers panel ---------- */
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
function layerRowHtml(el) {
    const selected = selectedKeys.includes(el.key);
    const editable = el.element_type === 'text' && !isBoundFieldElement(el);
    const editBtn = editable
        ? `<button type="button" class="btn btn-link btn-sm p-0 ms-1 pst-layer-edit" data-key="${el.key}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>`
        : '';
    // 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว" --
    // same Photoshop-style eye toggle as Employment Certificate Template's own Layers panel.
    const visible = el.is_visible !== false;
    // 2026-08-26, explicit request: "ปุ่มปิดตา layer ให้มาอยู่หน้าสุดของแถว" -- moved from the end
    // (ms-auto) to the very front of the row, ahead of the type icon/label.
    const eyeBtn = `<button type="button" class="btn btn-link btn-sm p-0 me-1 pst-layer-visibility" data-key="${el.key}" title="${langData[visible ? 'ect_layer_hide' : 'ect_layer_show'] || (visible ? 'Hide' : 'Show')}"><i class="fa-solid ${visible ? 'fa-eye' : 'fa-eye-slash text-muted'}"></i></button>`;
    return `
        <div class="pst-layer-row ${selected ? 'pst-layer-selected' : ''} ${visible ? '' : 'pst-layer-hidden'}" data-key="${el.key}">
            ${eyeBtn}
            <i class="fa-solid ${layerIcon(el)} me-1"></i>
            <span class="pst-layer-label">${escapeHtmlPst(elementLabel(el))}</span>
            ${editBtn}
            <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger pst-layer-delete" data-key="${el.key}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-xmark"></i></button>
        </div>
    `;
}
function renderLayersPanel() {
    const $panel = $('#pstLayersList');
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
            // Group-level eye: "visible" only when EVERY member is visible (select-all-checkbox
            // convention) -- same as Employment Certificate Template's own group row.
            const groupVisible = members.every(m => m.is_visible !== false);
            const $group = $(`
                <div class="pst-layer-group" data-group-key="${el.group_key}">
                    <div class="pst-layer-row pst-layer-group-row ${groupSelected ? 'pst-layer-selected' : ''}">
                        <button type="button" class="btn btn-link btn-sm p-0 me-1 pst-layer-group-visibility" data-group-key="${el.group_key}" title="${langData[groupVisible ? 'ect_layer_hide' : 'ect_layer_show'] || (groupVisible ? 'Hide' : 'Show')}"><i class="fa-solid ${groupVisible ? 'fa-eye' : 'fa-eye-slash text-muted'}"></i></button>
                        <i class="fa-solid fa-folder me-1"></i>
                        <span class="pst-layer-label">${escapeHtmlPst(langData['ect_layer_group_label'] || 'Group')} (${members.length})</span>
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger pst-layer-group-delete" data-group-key="${el.group_key}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div class="pst-layer-children"></div>
                </div>
            `);
            const $children = $group.find('.pst-layer-children');
            members.slice().reverse().forEach(m => { $children.append(layerRowHtml(m)); });
            $panel.append($group);
        } else {
            $panel.append(layerRowHtml(el));
        }
    });
}
$(document).on('click', '.pst-layer-row[data-key]', function (e) {
    if ($(e.target).closest('.pst-layer-edit, .pst-layer-delete, .pst-layer-visibility').length) return;
    selectElement($(this).data('key'), e.ctrlKey || e.metaKey);
});
$(document).on('click', '.pst-layer-edit', function (e) {
    e.stopPropagation();
    openTextModal($(this).data('key'));
});
// 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว" --
// same as Employment Certificate Template's own toggle: not a "select" click, and deselects the
// element when hiding it (no canvas box left to interact with once hidden).
$(document).on('click', '.pst-layer-visibility', function (e) {
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
$(document).on('click', '.pst-layer-group-visibility', function (e) {
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
$(document).on('click', '.pst-layer-delete', function (e) {
    e.stopPropagation();
    pushUndo();
    const key = $(this).data('key');
    elements = elements.filter(el => el.key !== key);
    selectedKeys = selectedKeys.filter(k => k !== key);
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.pst-layer-group-delete', function (e) {
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
$(document).on('click', '.pst-layer-group-row', function (e) {
    if ($(e.target).closest('.pst-layer-group-delete, .pst-layer-group-visibility').length) return;
    const groupKey = $(this).closest('.pst-layer-group').data('group-key');
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

/* ---------- Grid toggle ---------- */
$(document).on('click', '#pstGridToggle', function () {
    gridOn = !gridOn;
    $('#pstPage').toggleClass('pst-grid-on', gridOn);
    $(this).toggleClass('active', gridOn);
});

/* ---------- Free-text content modal ---------- */
function openTextModal(key) {
    const el = findElement(key);
    if (!el || isBoundFieldElement(el)) return;
    textModalKey = key;
    $('#pstTextModalInput').val(el.content || '');
    new bootstrap.Modal(document.getElementById('pstTextModal')).show();
}
$(document).on('click', '#pstTextModalApplyBtn', function () {
    const el = findElement(textModalKey);
    if (!el) return;
    pushUndo();
    el.content = $('#pstTextModalInput').val();
    renderCanvas();
    selectElement(textModalKey);
    bootstrap.Modal.getInstance(document.getElementById('pstTextModal')).hide();
    dirty = true;
    updateSaveHint();
});

/* ---------- Insert > Shape ---------- */
function addShapeElement(shapeType, posX, posY) {
    if (!currentTemplate) return;
    const el = Object.assign(emptyElementBase(), {
        key: newElementKey(), id: null, element_type: 'shape', field_key: shapeType, image_asset_id: null, content: null,
        pos_x_pct: clampPst(posX, 0, 80), pos_y_pct: clampPst(posY, 0, 85),
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
$(document).on('click', '.pst-insert-shape-item', function (e) {
    e.preventDefault();
    addShapeElement($(this).data('shape'), 15, 15);
});

/* ---------- Insert > Symbol ---------- */
const PST_SYMBOLS = ['©', '®', '™', '§', '¶', '•', '★', '☆', '✓', '✔', '✗', '➤', '→', '←', '↑', '↓',
    '♥', '♦', '♣', '♠', '☎', '✉', '⚑', '☀', '☁', '☂', '♪', '♫', '∞', '±', '×', '÷', '≈', '≠', '≤', '≥',
    '°', '€', '£', '¥'];
function renderSymbolMenu() {
    const $menu = $('#pstSymbolMenu');
    if ($menu.data('rendered')) return;
    PST_SYMBOLS.forEach(sym => {
        $menu.append(`<button type="button" class="pst-symbol-item" data-symbol="${sym}">${sym}</button>`);
    });
    $menu.data('rendered', true);
}
$(document).on('click', '#pstInsertSymbolBtn', function () { renderSymbolMenu(); });
function addSymbolElement(symbol, posX, posY) {
    if (!currentTemplate) return;
    const el = Object.assign(emptyElementBase(), {
        key: newElementKey(), id: null, element_type: 'text', field_key: null, image_asset_id: null,
        content: symbol,
        pos_x_pct: clampPst(posX, 0, 90), pos_y_pct: clampPst(posY, 0, 94), width_pct: 8, height_pct: 6,
        font_size: 24, text_align: 'center'
    });
    pushUndo();
    elements.push(el);
    renderCanvas();
    selectElement(el.key);
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '.pst-symbol-item', function (e) {
    e.stopPropagation();
    addSymbolElement($(this).data('symbol'), 15, 15);
    bootstrap.Dropdown.getOrCreateInstance(document.getElementById('pstInsertSymbolBtn')).hide();
});

/* ---------- Insert/edit Table ---------- */
let editingTableKey = null;
function rebuildTableCellsGrid(existingCells) {
    const rows = clampPst(parseInt($('#pstTableRowsInput').val(), 10) || 1, 1, 20);
    const cols = clampPst(parseInt($('#pstTableColsInput').val(), 10) || 1, 1, 10);
    const $grid = $('#pstTableCellsGrid').empty();
    $grid.css('grid-template-columns', `repeat(${cols}, 1fr)`);
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const value = existingCells && existingCells[r] && existingCells[r][c] !== undefined ? existingCells[r][c] : '';
            $grid.append(`<input type="text" class="pst-table-cell-input" data-row="${r}" data-col="${c}" value="${escapeHtmlPst(value)}">`);
        }
    }
}
$(document).on('input', '#pstTableRowsInput, #pstTableColsInput', function () {
    const current = [];
    $('#pstTableCellsGrid .pst-table-cell-input').each(function () {
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
    $('#pstTableRowsInput').val(data.rows);
    $('#pstTableColsInput').val(data.cols);
    $('#pstTableBorderColorInput').val(data.border_color);
    $('#pstTableBorderWidthInput').val(data.border_width);
    rebuildTableCellsGrid(data.cells);
    new bootstrap.Modal(document.getElementById('pstTableInsertModal')).show();
}
$(document).on('click', '#pstInsertTableBtn', function () {
    if (!currentTemplate) return;
    openTableModal(null);
});
$(document).on('click', '#pstTableInsertConfirmBtn', function () {
    const rows = clampPst(parseInt($('#pstTableRowsInput').val(), 10) || 1, 1, 20);
    const cols = clampPst(parseInt($('#pstTableColsInput').val(), 10) || 1, 1, 10);
    const cells = [];
    for (let r = 0; r < rows; r++) {
        cells.push([]);
        for (let c = 0; c < cols; c++) {
            cells[r].push($(`.pst-table-cell-input[data-row="${r}"][data-col="${c}"]`).val() || '');
        }
    }
    const content = JSON.stringify({
        rows, cols,
        border_color: $('#pstTableBorderColorInput').val() || '#000000',
        border_width: clampPst(parseInt($('#pstTableBorderWidthInput').val(), 10) || 1, 0, 10),
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
    bootstrap.Modal.getInstance(document.getElementById('pstTableInsertModal')).hide();
    dirty = true;
    updateSaveHint();
});

/* ---------- Reusable image library ---------- */
function loadImageLibrary(onLoaded) {
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.list-images`,
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
let selectedLibraryImageIds = new Set();
function renderImageLibrary() {
    const $grid = $('#pstImageLibraryGrid').empty();
    imageLibraryCache.forEach(img => {
        const selected = selectedLibraryImageIds.has(Number(img.id));
        $grid.append(`
            <div class="pst-image-grid-item ${selected ? 'selected' : ''}" data-id="${img.id}">
                <img src="${BASE_URL}/${img.file_path}" alt="">
                <div class="pst-image-selected-badge"><i class="fa-solid fa-check"></i></div>
                <div class="pst-image-delete" data-id="${img.id}"><i class="fa-solid fa-xmark"></i></div>
            </div>
        `);
    });
    $('#pstImageLibraryEmpty').toggleClass('d-none', imageLibraryCache.length > 0);
    updateImageLibrarySelectionUI();
}
function updateImageLibrarySelectionUI() {
    $('#pstImageLibrarySelectedCount').text(selectedLibraryImageIds.size);
    $('#pstImageLibraryInsertBtn').prop('disabled', selectedLibraryImageIds.size === 0);
}
$(document).on('click', '.pst-image-grid-item', function (e) {
    if ($(e.target).closest('.pst-image-delete').length) return;
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
$(document).on('click', '#pstImageLibraryInsertBtn', function () {
    const ids = Array.from(selectedLibraryImageIds);
    ids.forEach((id, idx) => addImageAssetElement(id, 10 + idx * 3, 10 + idx * 3));
    selectedLibraryImageIds.clear();
    bootstrap.Modal.getInstance(document.getElementById('pstImageLibraryModal')).hide();
});
$('#pstImageLibraryModal').on('hidden.bs.modal', function () {
    selectedLibraryImageIds.clear();
    updateImageLibrarySelectionUI();
});
$(document).on('click', '.pst-image-delete', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    showConfirm(langData['ect_confirm_delete_image'] || 'Delete this image?', '', function () {
        $.ajax({
            url: `${BASE_URL}/api/payslip-template.delete-image`,
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
function uploadImagesSequentially(files, onDone) {
    const uploadedIds = [];
    function next(i) {
        if (i >= files.length) { onDone(uploadedIds); return; }
        const formData = new FormData();
        formData.append('file', files[i]);
        $.ajax({
            url: `${BASE_URL}/api/payslip-template.upload-image`,
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
$(document).on('click', '#pstImageUploadNewItem', function (e) {
    e.preventDefault();
    $('#pstRibbonImageUploadInput').val('').trigger('click');
});
$(document).on('change', '#pstRibbonImageUploadInput', function () {
    const files = Array.from(this.files || []);
    if (!files.length) return;
    uploadImagesSequentially(files, function (uploadedIds) {
        loadImageLibrary(function () {
            uploadedIds.forEach((id, idx) => addImageAssetElement(id, 10 + idx * 3, 10 + idx * 3));
        });
    });
    $(this).val('');
});
$(document).on('click', '#pstImageChooseExistingItem', function (e) {
    e.preventDefault();
    loadImageLibrary();
    new bootstrap.Modal(document.getElementById('pstImageLibraryModal')).show();
});

/* ---------- Template LIST (DataTable, client-side) -- 2026-08-25 follow-up ("รูปแบบการทำเหมือนกัน"):
   unified TH/EN pair list, direct port of Employment Certificate Template's own list JS (see that
   file's own comments for the full "one row per pair_key"/"one shared Actions group" reasoning). The
   old per-language star/status-toggle action button is GONE from the list -- is_default/status
   (Payslip-only fields, unlike Employment Certificate Template) are now set/toggled from inside the
   editor's own Template Info card only, same "no list-level star" simplification Employment
   Certificate Template's own v10 reached (for a different reason -- per-language default, not "no
   concept at all"). ---------- */
function pageSizeLabel(row) {
    const orientationLabel = row.orientation === 'landscape' ? (langData['ect_landscape'] || 'Landscape') : (langData['ect_portrait'] || 'Portrait');
    return `${row.page_size || 'A4'} — ${orientationLabel}`;
}
function formatEctDateTime(str) {
    if (!str) return '';
    const d = new Date(String(str).replace(' ', 'T'));
    if (isNaN(d.getTime())) return escapeHtmlPst(str);
    const pad = n => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
// 2026-08-26, explicit request: "ให้มี Draft Mode และ Public Mode...ในหน้า List สามารถเปิด Draft หรือ
// Public ได้จากหน้านั้นเลย" -- direct port of ectLangStatusHtml()'s own clickable badge.
function pstLangStatusHtml(pairRow, lang) {
    const tpl = pairRow[lang];
    if (!tpl) {
        return `<i class="fa-regular fa-circle text-muted" title="${langData['ect_not_ready'] || 'Not ready'}"></i>`;
    }
    const isPublic = tpl.publish_status === 'public';
    const badge = `<button type="button" class="btn btn-sm ect-publish-badge ${isPublic ? 'ect-publish-public' : 'ect-publish-draft'} pst-publish-toggle" data-id="${tpl.id}" data-current="${tpl.publish_status}" title="${langData['ect_publish_toggle_hint'] || 'Click to toggle Draft/Public'}">${isPublic ? (langData['ect_publish_public'] || 'Public') : (langData['ect_publish_draft'] || 'Draft')}</button>`;
    return `<i class="fa-solid fa-circle-check text-success me-1" title="${langData['ect_ready'] || 'Ready'}"></i>${badge}`;
}
// 2026-08-26, explicit request: "ให้มี Draft Mode และ Public Mode...ตั้งต้นเป็น Draft mode ก่อน แล้วค่อย
// Public" -- reflects currentTemplate.publish_status/auto_save into the editor's own switches
// whenever a template loads (switchToLangTab()) -- disabled until the template has a real id (a
// brand-new, never-saved template is always created via a preset/Generate Auto first, so this only
// ever matters for the empty-state branch).
function updatePstPublishUi() {
    const hasId = !!(currentTemplate && currentTemplate.id);
    $('#pstPublishSwitch').prop('disabled', !hasId);
    $('#pstAutoSaveSwitch').prop('checked', hasId ? !!currentTemplate.auto_save : false);
    const isPublic = hasId && currentTemplate.publish_status === 'public';
    $('#pstPublishSwitch').prop('checked', isPublic);
    $('#pstPublishSwitchLabel').text(isPublic ? (langData['ect_publish_public'] || 'Public') : (langData['ect_publish_draft'] || 'Draft'));
}
$(document).on('change', '#pstPublishSwitch', function () {
    if (!currentTemplate || !currentTemplate.id) return;
    const $sw = $(this);
    const target = $sw.is(':checked') ? 'public' : 'draft';
    const revert = function () { $sw.prop('checked', target === 'draft'); };
    const doToggle = function () {
        $.ajax({
            url: `${BASE_URL}/api/payslip-template.publish-toggle`,
            method: 'POST', data: { id: currentTemplate.id, publish_status: target }, dataType: 'json',
            success: function (res) {
                if (res.status) {
                    currentTemplate.publish_status = target;
                    $('#pstPublishSwitchLabel').text(target === 'public' ? (langData['ect_publish_public'] || 'Public') : (langData['ect_publish_draft'] || 'Draft'));
                    try { localStorage.setItem('pst_list_dirty', String(Date.now())); } catch (e) { /* private browsing etc. */ }
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                    revert();
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); revert(); }
        });
    };
    if (target === 'draft') {
        showConfirm(langData['ect_confirm_unpublish'] || 'Switch this template back to Draft? It will stop being used for real generation immediately.', '', doToggle, revert);
    } else {
        doToggle();
    }
});
$(document).on('click', '.pst-publish-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();
    const $btn = $(this);
    const id = $btn.data('id');
    const current = $btn.data('current');
    const target = current === 'public' ? 'draft' : 'public';
    const doToggle = function () {
        $.ajax({
            url: `${BASE_URL}/api/payslip-template.publish-toggle`,
            method: 'POST', data: { id, publish_status: target }, dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    $('#tb_pst_template').DataTable().ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                }
            }
        });
    };
    if (target === 'draft') {
        showConfirm(langData['ect_confirm_unpublish'] || 'Switch this template back to Draft? It will stop being used for real generation immediately.', '', doToggle);
    } else {
        doToggle();
    }
});
function pstActionsGroupHtml(pairRow) {
    const readyLangs = ['th', 'en'].filter(l => pairRow[l]);
    const flagFile = { th: 'th', en: 'gb' };
    const previewItems = readyLangs.map(l =>
        `<li><a class="dropdown-item pst-preview-lang-item" href="#" data-id="${pairRow[l].id}"><img src="${BASE_URL}/public/flags/${flagFile[l]}.png" width="14" class="me-2">${langData['template_language_' + l] || l}</a></li>`
    ).join('');
    const deleteItems = [];
    if (pairRow.th && pairRow.en) {
        deleteItems.push(`<li><a class="dropdown-item text-danger pst-delete-pair-item" href="#" data-pair-key="${pairRow.pair_key}">${langData['ect_delete_both'] || 'Delete both (Thai + English)'}</a></li>`);
        deleteItems.push('<li><hr class="dropdown-divider"></li>');
    }
    if (pairRow.th) {
        deleteItems.push(`<li><a class="dropdown-item text-danger pst-delete-lang-item" href="#" data-id="${pairRow.th.id}">${langData['ect_delete_thai_only'] || 'Delete Thai only'}</a></li>`);
    }
    if (pairRow.en) {
        deleteItems.push(`<li><a class="dropdown-item text-danger pst-delete-lang-item" href="#" data-id="${pairRow.en.id}">${langData['ect_delete_english_only'] || 'Delete English only'}</a></li>`);
    }
    return `<div class="btn-group border rounded-3 bg-white pst-actions-group">
        <div class="dropdown">
            <button type="button" class="btn btn-link text-secondary dropdown-toggle" data-bs-toggle="dropdown" title="${langData['preview'] || 'Preview'}"><i class="fas fa-eye"></i></button>
            <ul class="dropdown-menu">${previewItems}</ul>
        </div>
        <button type="button" class="btn btn-link text-warning border-start btn-edit-pst" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link text-primary border-start btn-duplicate-pair-pst" title="${langData['duplicate'] || 'Duplicate'}"><i class="fas fa-copy"></i></button>
        <div class="dropdown">
            <button type="button" class="btn btn-link py-1 text-danger border-start dropdown-toggle" data-bs-toggle="dropdown" title="${langData['delete'] || 'Delete'}"><i class="fas fa-trash-alt"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">${deleteItems.join('')}</ul>
        </div>
    </div>`;
}
function initPstTemplateTable() {
    if ($.fn.DataTable.isDataTable('#tb_pst_template')) {
        $('#tb_pst_template').DataTable().ajax.reload(null, false);
        return;
    }
    tb_pst_template = $('#tb_pst_template').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/payslip-template.paired-list`,
            dataSrc: 'data'
        },
        columns: [
            { data: null, render: (d, t, row) => `<strong class="text-dark">${escapeHtmlPst(row.template_name)}</strong>` },
            { data: null, render: (d, t, row) => pageSizeLabel(row) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => pstLangStatusHtml(row, 'th') },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => pstLangStatusHtml(row, 'en') },
            { data: null, render: (d, t, row) => formatEctDateTime(row.latest_updated_at) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => pstActionsGroupHtml(row) }
        ],
        // Edit/Duplicate/Preview/Delete all need the FULL pair row (both languages' ids, pair_key,
        // name) -- stashed on the <tr> itself rather than re-derived from data-* attributes.
        createdRow: function (row, data) { $(row).data('pairRow', data); },
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-pst').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-pst">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_template">${langData['add_template'] || 'Template'}</span>
                    </button>
                `);
            }
        }
    });
}
$(document).on('click', '.btn-duplicate-pair-pst', function () {
    const pairRow = $(this).closest('tr').data('pairRow');
    if (!pairRow) return;
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.duplicate-pair`,
        method: 'POST', data: { pair_key: pairRow.pair_key }, dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                $('#tb_pst_template').DataTable().ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        }
    });
});
function deleteTemplateById(id, onDone) {
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.delete`,
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
$(document).on('click', '.pst-delete-lang-item', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    showConfirm(langData['ect_confirm_delete_template'] || 'Delete this template? This action cannot be undone.', '', function () {
        deleteTemplateById(id, function () {
            showSuccess(langData['delete_success'] || 'Deleted successfully.');
            $('#tb_pst_template').DataTable().ajax.reload(null, false);
        });
    });
});
$(document).on('click', '.pst-delete-pair-item', function (e) {
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
                $('#tb_pst_template').DataTable().ajax.reload(null, false);
            }
        }));
    });
});

/* ---------- Preview (read-only PDF of the SAVED template -- no canvas, no editor page) ---------- */
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
// 2026-08-26: is_visible added here too, per the v12-era lesson documented in Employment Certificate
// Template's own elementsPayload() (this function has no schema-driven fallback -- every element
// property has to be listed by hand here, easy to add a new one to emptyElementBase()/the model
// without remembering this one central serialization point also needs updating).
function elementsForPayload(list) {
    return (list || []).map(e => ({
        element_type: e.element_type, field_key: e.field_key, image_asset_id: e.image_asset_id, content: e.content,
        pos_x_pct: e.pos_x_pct, pos_y_pct: e.pos_y_pct, width_pct: e.width_pct, height_pct: e.height_pct,
        font_size: e.font_size, font_family: e.font_family, font_color: e.font_color,
        text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration,
        group_key: e.group_key || null, page_number: e.page_number || 1, is_visible: e.is_visible !== false
    }));
}
function previewTemplateById(id) {
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.get`,
        method: 'GET', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const t = res.data;
            streamPreviewBlob(`${BASE_URL}/api/payslip-template.preview`, {
                language: t.language, logo_path: t.logo_path || null,
                page_size: t.page_size, orientation: t.orientation,
                elements: elementsForPayload(t.elements),
                watermark_enabled: false, watermark_text: ''
            }, 'Preview failed.');
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
$(document).on('click', '.pst-preview-lang-item', function (e) {
    e.preventDefault();
    previewTemplateById($(this).data('id'));
});
$(document).on('click', '.btn-edit-pst', function () {
    const pairRow = $(this).closest('tr').data('pairRow');
    if (pairRow) window.open(`${BASE_URL}/payslip-template/edit/${encodeURIComponent(pairRow.pair_key)}`, '_blank');
});

/* ---------- Assign To (department/team/employee scoping) -- explicit request (2026-08-25 follow-up):
   "เพิ่ม Tab...เป็น checkbox ให้เลือก...เลือกได้กับทุกคน ทุกแผนก ทุกทีม แต่ถ้ามีการตั้งค่าซ้ำต้องแจ้ง Error"
   -- replaced the round-1 select2-multi-select UI with full checkbox lists (every department/team/
   employee always listed, so "select all" is trivial) + a search filter per column. The duplicate-
   assignment error itself is enforced server-side (PayslipTemplateModel::validateAssignments()) --
   this UI only has to let the admin see/pick freely and surface whatever error save() returns. ---------- */
const PST_ASSIGN_SCOPES = [
    { type: 'department', dataKey: 'departments', allId: 'pstAssignAllDepartments', filterId: 'pstAssignDepartmentsFilter', listId: 'pstAssignDepartmentsList' },
    { type: 'team', dataKey: 'teams', allId: 'pstAssignAllTeams', filterId: 'pstAssignTeamsFilter', listId: 'pstAssignTeamsList' },
    { type: 'employee', dataKey: 'employees', allId: 'pstAssignAllEmployees', filterId: 'pstAssignEmployeesFilter', listId: 'pstAssignEmployeesList' }
];
let assignableOptionsData = null; // {departments, teams, employees}, loaded once per editor page load
let currentAssignments = []; // assignments as loaded from the template -- applied once the checklists render

function assignItemLabel(item) {
    return (currentLang === 'en' && item.text_en) ? item.text_en : (item.text_th || item.text_en || ('#' + item.id));
}
function loadAssignableOptions() {
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.assignable-options`,
        method: 'POST', dataType: 'json',
        success: function (res) {
            assignableOptionsData = (res.status && res.data) ? res.data : { departments: [], teams: [], employees: [] };
            renderAssignChecklists();
        }
    });
}
function renderAssignChecklists() {
    if (!assignableOptionsData) return;
    const checkedKeys = {};
    (currentAssignments || []).forEach(a => { checkedKeys[a.scope_type + ':' + a.scope_id] = true; });
    PST_ASSIGN_SCOPES.forEach(scope => {
        const items = assignableOptionsData[scope.dataKey] || [];
        const $list = $('#' + scope.listId).empty();
        items.forEach(item => {
            const label = assignItemLabel(item);
            const checked = !!checkedKeys[scope.type + ':' + item.id];
            const cbId = `pstAssignCb_${scope.type}_${item.id}`;
            const $row = $('<div>').addClass('pst-assign-item form-check').attr('data-search', label.toLowerCase());
            const $cb = $('<input>').addClass('form-check-input pst-assign-checkbox').attr({
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
    updatePstAssignModeUi();
}
// 2026-08-26, explicit request: "ตรง Assign To ช่วยปรับให้ใช้งานง่ายขึ้นไม่ซับซ้อน" -- reflects whether
// any checkbox is currently checked into the simple Everyone/Specific radio switch, and shows/hides
// the 3 columns accordingly. Purely a visibility layer -- collectAssignments() is untouched.
function updatePstAssignModeUi() {
    const anyChecked = $('.pst-assign-checkbox:checked').length > 0;
    $('#pstAssignModeEveryone').prop('checked', !anyChecked);
    $('#pstAssignModeSpecific').prop('checked', anyChecked);
    $('#pstAssignColumns, #pstAssignHint').toggleClass('d-none', !anyChecked);
}
$(document).on('change', 'input[name="pstAssignMode"]', function () {
    const specific = $(this).val() === 'specific';
    $('#pstAssignColumns, #pstAssignHint').toggleClass('d-none', !specific);
    if (!specific) {
        // Switching back to "Everyone" clears every checkbox -- same meaning "leave all empty"
        // already had, just reachable with one click instead of manually unchecking each column.
        $('.pst-assign-checkbox').prop('checked', false);
        PST_ASSIGN_SCOPES.forEach(scope => updateAssignSelectAllState(scope));
        if (currentTemplate) { dirty = true; updateSaveHint(); }
    }
});
function updateAssignSelectAllState(scope) {
    const $boxes = $('#' + scope.listId + ' .pst-assign-checkbox');
    const total = $boxes.length;
    const checkedCount = $boxes.filter(':checked').length;
    const $all = $('#' + scope.allId);
    $all.prop('checked', total > 0 && checkedCount === total);
    $all.prop('indeterminate', checkedCount > 0 && checkedCount < total);
}
function collectAssignments() {
    const result = [];
    $('.pst-assign-checkbox:checked').each(function () {
        result.push({ scope_type: $(this).data('scope-type'), scope_id: Number($(this).data('scope-id')) });
    });
    return result;
}
$(document).on('change', '.pst-assign-checkbox', function () {
    const scope = PST_ASSIGN_SCOPES.find(s => s.type === $(this).data('scope-type'));
    if (scope) updateAssignSelectAllState(scope);
    // Keep the Everyone/Specific radio in sync if the admin unchecks the very last box directly.
    $('#pstAssignModeEveryone').prop('checked', $('.pst-assign-checkbox:checked').length === 0);
    $('#pstAssignModeSpecific').prop('checked', $('.pst-assign-checkbox:checked').length > 0);
    if (!currentTemplate) return;
    dirty = true;
    updateSaveHint();
});
PST_ASSIGN_SCOPES.forEach(scope => {
    $(document).on('change', '#' + scope.allId, function () {
        const checkAll = $(this).is(':checked');
        $('#' + scope.listId + ' .pst-assign-checkbox').prop('checked', checkAll);
        updateAssignSelectAllState(scope);
        $('#pstAssignModeEveryone').prop('checked', $('.pst-assign-checkbox:checked').length === 0);
        $('#pstAssignModeSpecific').prop('checked', $('.pst-assign-checkbox:checked').length > 0);
        if (currentTemplate) { dirty = true; updateSaveHint(); }
    });
    $(document).on('input', '#' + scope.filterId, function () {
        const term = $(this).val().toLowerCase().trim();
        $('#' + scope.listId + ' .pst-assign-item').each(function () {
            const match = !term || ($(this).attr('data-search') || '').indexOf(term) !== -1;
            $(this).toggleClass('d-none', !match);
        });
    });
});

/** Fetches ONE template's full data and maps it into a pairState-shaped slot object -- does NOT
 *  touch the bare globals directly, so both languages of a pair can be fetched independently/in
 *  parallel without one overwriting the other while both are in flight. Direct port of Employment
 *  Certificate Template's own fetchTemplateIntoSlot(). */
function fetchTemplateIntoSlot(id, callback) {
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.get`,
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
                // 2026-08-26, real bug caught before shipping -- same category as v12's page_number
                // miss (see CLAUDE.md): without this, loading a SAVED template with a hidden element
                // back into the editor would silently show it as visible again.
                is_visible: e.is_visible !== 0 && e.is_visible !== false
            }));
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
 *  writes (currentTemplate/elements/logoPath/dirty/undoStack/redoStack) -- a shallow reference swap.
 *  Renders either the normal canvas or the empty-state, depending on whether this language has been
 *  created for the pair yet. Direct port of Employment Certificate Template's own switchToLangTab(). */
// 2026-08-26, explicit request: lock this Font dropdown the same way Employment Certificate
// Template's own already does -- direct port of that file's updateFontFamilyOptions(). Only TH
// Sarabun New has Thai glyphs (see storage/fonts/thsarabun/NOTICE.md); the other 6 are only offered
// while editing the English-language template, where they're a genuine stylistic alternative.
function updateFontFamilyOptions() {
    $('#pstPropFontFamily option[data-en-only]').toggle(currentLanguage === 'en');
}
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
        $('#pstMainTabsWrap').addClass('d-none');
        $('#pstLangEmptyState').removeClass('d-none');
        // These live in the top bar (outside #pstMainTabsWrap), so without clearing them they'd
        // otherwise keep showing whichever OTHER language's values were loaded last.
        $('#pstTemplateNameInput, #pstMarginInput').val('');
        $('#pstPageSizeSelect').val('A4');
        $('#pstOrientationSelect').val('portrait');
        $('#pstHeaderThInput, #pstHeaderEnInput, #pstFooterThInput, #pstFooterEnInput').val('');
        $('#pstStatusSwitch').prop('checked', true);
        $('#pstIsDefaultSwitch').prop('checked', false);
        updatePstPublishUi();
        currentAssignments = [];
        renderAssignChecklists();
        updateMarginDropdownLabel();
        const otherLang = lang === 'th' ? 'en' : 'th';
        const otherExists = !!pairState[otherLang].template;
        $('#pstLangEmptyGenerateBtn').prop('disabled', !otherExists)
            .attr('title', otherExists ? '' : (langData['ect_generate_auto_needs_other'] || 'The other language needs to exist first.'));
        const langLabel = lang === 'th' ? (langData['template_language_th'] || 'Thai') : (langData['template_language_en'] || 'English');
        $('#pstLangEmptyTitle').text((langData['ect_lang_empty_title'] || 'The {lang} version hasn\'t been created yet.').replace('{lang}', langLabel));
        return;
    }
    $('#pstMainTabsWrap').removeClass('d-none');
    $('#pstLangEmptyState').addClass('d-none');
    $('#pstTemplateNameInput').val(currentTemplate.template_name);
    $('#pstPageSizeSelect').val(currentTemplate.page_size);
    $('#pstOrientationSelect').val(currentTemplate.orientation);
    $('#pstMarginInput').val(currentTemplate.margin_mm);
    $('#pstHeaderThInput').val(currentTemplate.header_text_th || '');
    $('#pstHeaderEnInput').val(currentTemplate.header_text_en || '');
    $('#pstFooterThInput').val(currentTemplate.footer_text_th || '');
    $('#pstFooterEnInput').val(currentTemplate.footer_text_en || '');
    $('#pstStatusSwitch').prop('checked', currentTemplate.status === 'active');
    $('#pstIsDefaultSwitch').prop('checked', !!Number(currentTemplate.is_default));
    updatePstPublishUi();
    currentAssignments = slot.assignments || [];
    renderAssignChecklists();
    updateMarginDropdownLabel();
    updateFontFamilyOptions();
    updateCanvasDimensions();
    $('#pstPage').toggleClass('pst-grid-on', gridOn);
    $('#pstGridToggle').toggleClass('active', gridOn);
    renderCanvas();
    updateSaveHint();
}
$(document).on('click', '.pst-lang-tab', function () {
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
        // This runs on the editor PAGE, not the list -- no #tb_pst_template here to reload directly;
        // nudge any list tab that happens to be open elsewhere the same way a Save does.
        try { localStorage.setItem('pst_list_dirty', String(Date.now())); } catch (e) { /* private browsing etc. */ }
    });
}
/** Bootstraps the standalone editor PAGE for a whole PAIR -- called once, from $(document).ready(),
 *  with the server-resolved `PST_PAIR_ROW` global (see edit.php -> PayslipTemplateController::
 *  editPage() -> getPairByKey()). Direct port of Employment Certificate Template's own
 *  bootstrapEditorPage(). */
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
/** Any unsaved edits on EITHER language tab block leaving without confirmation. Direct port of
 *  Employment Certificate Template's own pairHasAnyUnsavedChanges(). */
function pairHasAnyUnsavedChanges() {
    return pairState.th.dirty || pairState.en.dirty;
}
window.addEventListener('beforeunload', function (e) {
    if (pairHasAnyUnsavedChanges()) {
        e.preventDefault();
        e.returnValue = '';
    }
});
$(document).on('change', '#pstPageSizeSelect, #pstOrientationSelect', function () {
    updateCanvasDimensions();
    renderCanvas();
    dirty = true;
    updateSaveHint();
});
$(document).on('input', '#pstTemplateNameInput, #pstHeaderThInput, #pstHeaderEnInput, #pstFooterThInput, #pstFooterEnInput', function () { dirty = true; updateSaveHint(); });
$(document).on('click', '#pstTemplateNameEditBtn', function () {
    $('#pstTemplateNameInput').trigger('focus').select();
});
// 2026-08-26, follow-up correction: "Mode Fullscreen หมายถึงให้การตั้งค่าแสดงใน modal fullscreen ครับ" --
// see _editor_content.php's own top-of-file comment for why this moved away from the browser's native
// Fullscreen API (silently blocked when embedded in an iframe without allow="fullscreen", e.g.
// Origami's own shell). #pstEditorContent is the ENTIRE editor (topbar/tabs/Page Setup/Template
// Info/ribbon/canvas/layers) -- toggling this button relocates that whole node into the fullscreen
// modal's body (or back to its normal spot, anchored by #pstEditorContentAnchor) via jQuery
// .appendTo()/.insertAfter(), then shows/relies on Bootstrap's own modal hide to restore it. Every
// click/change handler in this file is $(document).on(...) delegated, so relocating the DOM node
// doesn't break any of them.
$(document).on('click', '#pstFullscreenBtn', function () {
    // The button itself moves INTO the modal once shown (it's part of #pstEditorContent), so
    // clicking it a 2nd time must CLOSE the modal, not show() it again -- toggle based on the
    // modal's actual current state rather than assuming "click = open".
    const modalEl = document.getElementById('pstFullscreenModal');
    const instance = bootstrap.Modal.getOrCreateInstance(modalEl);
    if (modalEl.classList.contains('show')) {
        instance.hide();
    } else {
        instance.show();
    }
});
$('#pstFullscreenModal').on('show.bs.modal', function () {
    $('#pstEditorContent').appendTo('#pstFullscreenModalBody');
    $('#pstFullscreenBtn i').removeClass('fa-expand').addClass('fa-compress');
});
$('#pstFullscreenModal').on('shown.bs.modal', function () {
    // The canvas/zoom math reads live offsetWidth/offsetHeight against whatever container it's
    // CURRENTLY inside -- re-run once the modal has actually finished animating open (not on
    // show.bs.modal, which fires before the modal is done sizing itself).
    if (typeof updateCanvasDimensions === 'function') updateCanvasDimensions();
    if (typeof applyZoom === 'function') applyZoom();
});
$('#pstFullscreenModal').on('hidden.bs.modal', function () {
    $('#pstEditorContent').insertAfter('#pstEditorContentAnchor');
    $('#pstFullscreenBtn i').removeClass('fa-compress').addClass('fa-expand');
    if (typeof updateCanvasDimensions === 'function') updateCanvasDimensions();
    if (typeof applyZoom === 'function') applyZoom();
});
$(document).on('change', '#pstStatusSwitch, #pstIsDefaultSwitch, #pstAutoSaveSwitch', function () { dirty = true; updateSaveHint(); });

/* ---------- New Template modal ---------- */
function loadPresets() {
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.preset-options`,
        method: 'POST', dataType: 'json',
        success: function (res) {
            if (res.status) {
                presetCache = res.data || [];
                renderPresetList();
            }
        }
    });
}
const PRESET_MOCKUPS = {
    classic: { bars: [
        { left: 8, top: 3, width: 60, height: 6, color: '#333' },
        { left: 8, top: 18, width: 40, height: 4, color: '#bbb' },
        { left: 50, top: 18, width: 42, height: 4, color: '#bbb' },
        { left: 8, top: 30, width: 84, height: 3, color: '#ddd' },
        { left: 8, top: 38, width: 40, height: 22, color: '#eee', box: true },
        { left: 52, top: 38, width: 40, height: 22, color: '#eee', box: true },
        { left: 8, top: 62, width: 84, height: 15, color: '#eee', box: true },
        { left: 8, top: 80, width: 40, height: 5, color: '#333' },
        { left: 52, top: 80, width: 40, height: 5, color: '#333' },
    ] },
    modern: { bars: [
        { left: 6, top: 5, width: 16, height: 10, color: '#e2e2e2', box: true },
        { left: 26, top: 6, width: 60, height: 6, color: '#333' },
        { left: 26, top: 12, width: 55, height: 3, color: '#bbb' },
        { left: 6, top: 19, width: 88, height: 1, color: '#FF9900' },
        { left: 6, top: 22, width: 88, height: 5, color: '#333' },
        { left: 6, top: 40, width: 88, height: 18, color: '#eee', box: true },
        { left: 6, top: 60, width: 88, height: 12, color: '#eee', box: true },
        { left: 6, top: 74, width: 88, height: 12, color: '#eee', box: true },
        { left: 6, top: 89, width: 88, height: 6, color: '#FF9900' },
    ] },
    minimal: { bars: [
        { left: 6, top: 6, width: 88, height: 6, color: '#333' },
        { left: 6, top: 14, width: 60, height: 4, color: '#bbb' },
        { left: 6, top: 24, width: 88, height: 20, color: '#eee', box: true },
        { left: 6, top: 46, width: 88, height: 14, color: '#eee', box: true },
        { left: 6, top: 62, width: 88, height: 14, color: '#eee', box: true },
        { left: 6, top: 80, width: 88, height: 6, color: '#333' },
    ] },
};
function renderPresetMockup(code) {
    const cfg = PRESET_MOCKUPS[code];
    if (!cfg) {
        return `<div class="pst-preset-mock pst-preset-mock-blank"><i class="fa-solid fa-plus"></i></div>`;
    }
    const bars = cfg.bars.map(b => {
        const radius = b.box ? '3px' : '1px';
        return `<span style="position:absolute;left:${b.left}%;top:${b.top}%;width:${b.width}%;height:${b.height}%;background:${b.color};border-radius:${radius};"></span>`;
    }).join('');
    return `<div class="pst-preset-mock">${bars}</div>`;
}
function renderPresetList() {
    const $wrap = $('#pstPresetList').empty();
    presetCache.forEach(p => {
        const label = currentLang === 'th' ? p.name_th : p.name_en;
        const previewBtn = p.code !== 'blank'
            ? `<button type="button" class="btn btn-link btn-sm pst-preset-preview-btn" data-code="${p.code}" title="${langData['preview'] || 'Preview'}"><i class="fa-solid fa-eye"></i></button>`
            : '';
        $wrap.append(`
            <div class="pst-preset-card ${chosenPreset === p.code ? 'active' : ''}" data-code="${p.code}">
                ${renderPresetMockup(p.code)}
                <div class="pst-preset-card-footer">
                    <span class="pst-preset-card-label">${escapeHtmlPst(label)}</span>
                    ${previewBtn}
                </div>
            </div>
        `);
    });
}
// modalMode: 'create' (list toolbar's "+ Template", brand new unpaired pair), 'create-in-pair' (a
// pair's empty-state "start from a preset" for its missing language, locked language + carries the
// pair_key via #pstNewTemplatePairKey), and 'replace' (#pstChangePresetBtn's "Change Layout",
// destructive re-apply to what's already open). Generate Auto never opens this modal at all -- direct
// one-click API call from the empty-state, see #pstLangEmptyGenerateBtn below. Direct port of
// Employment Certificate Template's own openNewTemplateModal().
function openNewTemplateModal(opts) {
    opts = opts || {};
    modalMode = opts.mode || 'create';
    chosenPreset = 'blank';
    $('#pstNewTemplateNameInput').val(opts.templateName || '');
    $('#pstNewPageSizeSelect').val(opts.pageSize || 'A4');
    $('#pstNewOrientationSelect').val(opts.orientation || 'portrait');
    $('#pstNewTemplateLanguageSelect').val(opts.language || 'th').prop('disabled', !!opts.lockLanguage);
    $('#pstNewTemplatePairKey').val(opts.pairKey || '');
    if (opts.pairHint) {
        $('#pstNewTemplatePairHint').text(opts.pairHint).removeClass('d-none');
    } else {
        $('#pstNewTemplatePairHint').addClass('d-none');
    }
    const isReplace = modalMode === 'replace';
    $('#pstNewTemplateModal .row.g-3').toggleClass('d-none', isReplace);
    if (isReplace) {
        $('#pstNewTemplateModalTitle').text(langData['ect_change_preset'] || 'Change Layout').removeAttr('data-i18n');
        $('#pstCreateTemplateBtnLabel').text(langData['apply'] || 'Apply').removeAttr('data-i18n');
    } else {
        $('#pstNewTemplateModalTitle').text(langData['ect_new_template'] || 'New Template').attr('data-i18n', 'ect_new_template');
        $('#pstCreateTemplateBtnLabel').text(langData['create'] || 'Create').attr('data-i18n', 'create');
    }
    loadPresets();
    new bootstrap.Modal(document.getElementById('pstNewTemplateModal')).show();
}
$(document).on('click', '.btn-add-pst', function () {
    openNewTemplateModal({ mode: 'create' });
});
$(document).on('click', '.pst-preset-card', function (e) {
    if ($(e.target).closest('.pst-preset-preview-btn').length) return;
    chosenPreset = $(this).data('code');
    $('.pst-preset-card').removeClass('active');
    $(this).addClass('active');
});
$(document).on('click', '.pst-preset-preview-btn', function (e) {
    e.stopPropagation();
    const preset = $(this).data('code');
    streamPreviewBlob(`${BASE_URL}/api/payslip-template.preset-preview`, {
        language: $('#pstNewTemplateLanguageSelect').val(), preset
    }, 'Preview failed.');
});
$(document).on('click', '#pstCreateTemplateBtn', function () {
    if (modalMode === 'replace') {
        $.ajax({
            url: `${BASE_URL}/api/payslip-template.preset-elements`,
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
                        bootstrap.Modal.getInstance(document.getElementById('pstNewTemplateModal')).hide();
                    }
                );
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
        });
        return;
    }
    const name = $('#pstNewTemplateNameInput').val().trim();
    if (!name) {
        showWarning(langData['ect_select_template_name_required'] || 'Please enter a template name.');
        return;
    }
    const language = $('#pstNewTemplateLanguageSelect').val();
    const pageSize = $('#pstNewPageSizeSelect').val();
    const orientation = $('#pstNewOrientationSelect').val();
    const pairKey = $('#pstNewTemplatePairKey').val() || null;
    const createInPair = modalMode === 'create-in-pair';
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.create-from-preset`,
        method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ language, preset: chosenPreset, template_name: name, pair_key: pairKey }),
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            bootstrap.Modal.getInstance(document.getElementById('pstNewTemplateModal')).hide();
            // 'create-in-pair' (opened FROM an already-open editor page's empty-state) loads straight
            // into the current page's matching tab -- no navigation. 'create' (the list toolbar's
            // "+ Template", brand new unpaired pair) opens the new standalone editor page in a NEW
            // TAB instead -- `res.pair_key` comes straight back from save() so this doesn't need an
            // extra round trip.
            function proceed() {
                if (createInPair) {
                    loadIntoPairSlotAndSwitch(language, res.template_id);
                } else {
                    const newPairKey = pairKey || res.pair_key;
                    window.open(`${BASE_URL}/payslip-template/edit/${encodeURIComponent(newPairKey)}`, '_blank');
                    $('#tb_pst_template').DataTable().ajax.reload(null, false);
                }
            }
            if (pageSize !== 'A4' || orientation !== 'portrait') {
                $.ajax({
                    url: `${BASE_URL}/api/payslip-template.get`,
                    method: 'GET', data: { id: res.template_id }, dataType: 'json',
                    success: function (getRes) {
                        if (!getRes.status) { proceed(); return; }
                        const t = getRes.data;
                        $.ajax({
                            url: `${BASE_URL}/api/payslip-template.save`,
                            method: 'POST', contentType: 'application/json',
                            data: JSON.stringify({
                                id: t.id, language: t.language, template_name: t.template_name,
                                header_text_th: t.header_text_th, header_text_en: t.header_text_en,
                                footer_text_th: t.footer_text_th, footer_text_en: t.footer_text_en,
                                is_default: !!Number(t.is_default), status: t.status,
                                page_size: pageSize, orientation: orientation, margin_mm: t.margin_mm, logo_path: t.logo_path,
                                elements: elementsForPayload(t.elements)
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
/* ---------- Empty-state actions -- Generate Auto / start from a preset for whichever language tab
   is currently active but hasn't been created yet. Direct port of Employment Certificate Template's
   own #ectLangEmptyGenerateBtn/#ectLangEmptyPresetBtn handlers. ---------- */
$(document).on('click', '#pstLangEmptyGenerateBtn', function () {
    const otherLang = activeLang === 'th' ? 'en' : 'th';
    const sourceTpl = pairState[otherLang].template;
    if (!sourceTpl) return;
    showConfirm(
        langData['ect_generate_auto'] || 'Generate Auto',
        langData['ect_generate_auto_confirm'] || 'This will clone the other language\'s layout (positions, sizes, fonts) as a starting point. You can edit it afterward. Continue?',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/payslip-template.generate-other-language`,
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
$(document).on('click', '#pstLangEmptyPresetBtn', function () {
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
$(document).on('click', '#pstChangePresetBtn', function () {
    if (!currentTemplate) return;
    openNewTemplateModal({ mode: 'replace', language: activeLang, lockLanguage: true });
});

/* ---------- Save ---------- */
function elementsPayload() {
    return elementsForPayload(elements);
}
// 2026-08-26, explicit request: "เพิ่มให้ติ๊กได้ว่าต้องการให้ Auto Save" -- extracted into a named
// function so both the Save button click AND the debounced autosave (scheduleAutoSaveIfEnabled()
// above) call the exact same logic. `silent=true` (autosave) skips the success toast (a toast every
// couple seconds while typing would be noisy) but still surfaces a failure, since a silently-failing
// autosave would be worse than no autosave at all.
function savePstTemplate(silent) {
    if (!currentTemplate) return;
    const templateName = $('#pstTemplateNameInput').val().trim();
    if (!templateName) {
        if (!silent) showWarning(langData['ect_select_template_name_required'] || 'Please enter a template name.');
        return;
    }
    const pageSize = $('#pstPageSizeSelect').val();
    const orientation = $('#pstOrientationSelect').val();
    const marginMm = parseFloat($('#pstMarginInput').val()) || 0;
    // 2026-08-25 follow-up ("รูปแบบการทำเหมือนกัน") -- Save now saves ONLY the currently active
    // language tab, same as Employment Certificate Template's own #ectSaveBtn: `language` identifies
    // WHICH row this is (immutable after creation), not an editable field on the payload anymore.
    const payload = {
        id: currentTemplate.id, language: currentLanguage, template_name: templateName,
        header_text_th: $('#pstHeaderThInput').val(), header_text_en: $('#pstHeaderEnInput').val(),
        footer_text_th: $('#pstFooterThInput').val(), footer_text_en: $('#pstFooterEnInput').val(),
        is_default: $('#pstIsDefaultSwitch').is(':checked'),
        status: $('#pstStatusSwitch').is(':checked') ? 'active' : 'inactive',
        auto_save: $('#pstAutoSaveSwitch').is(':checked'),
        page_size: pageSize, orientation: orientation, margin_mm: marginMm,
        logo_path: logoPath, elements: elementsPayload(), assignments: collectAssignments()
    };
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.save`,
        method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                if (!silent) showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                currentTemplate.template_name = templateName;
                currentTemplate.page_size = pageSize;
                currentTemplate.orientation = orientation;
                currentTemplate.margin_mm = marginMm;
                if (pairState[activeLang]) pairState[activeLang].assignments = payload.assignments;
                dirty = false;
                updateSaveHint();
                try { localStorage.setItem('pst_list_dirty', String(Date.now())); } catch (e) { /* private browsing etc. */ }
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
}
// Both the Design tab's and the Assign To tab's own Save buttons share this one class -- see the
// view's own comment on why this is a visual/UX change, not a split into two independent partial saves.
$(document).on('click', '.pst-save-btn', function () {
    savePstTemplate(false);
});

/* ---------- Preview (live unsaved canvas state) ---------- */
$(document).on('change', '#pstWatermarkToggle', function () {
    const checked = this.checked;
    $('#pstWatermarkText').toggleClass('d-none', !checked);
    if (checked && !$('#pstWatermarkText').val().trim()) {
        $('#pstWatermarkText').val('SAMPLE');
    }
});
$(document).on('click', '#pstPreviewBtn', function () {
    if (!elements.length) {
        showWarning(langData['ect_add_element_first'] || 'Add at least one element before previewing.');
        return;
    }
    streamPreviewBlob(`${BASE_URL}/api/payslip-template.preview`, {
        language: currentLanguage, logo_path: logoPath,
        page_size: $('#pstPageSizeSelect').val(), orientation: $('#pstOrientationSelect').val(),
        elements: elementsPayload(),
        watermark_enabled: $('#pstWatermarkToggle').is(':checked'),
        watermark_text: $('#pstWatermarkText').val()
    }, 'Preview failed.');
});

/* ---------- Page init -- this file serves 2 host pages: the list (Payslip Settings' 1st tab,
   `#tb_pst_template`) and the standalone editor page (`#pstPage`, opened in its own tab). ---------- */
$(document).ready(function () {
    if ($('#pstPage').length) {
        $.ajax({
            url: `${BASE_URL}/api/payslip-template.field-options`,
            method: 'POST', dataType: 'json',
            success: function (res) {
                if (res.status) {
                    fieldTypesCache = res.data || [];
                    renderPalette();
                }
            }
        });
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
        if (typeof PST_PAIR_ROW !== 'undefined') {
            bootstrapEditorPage(PST_PAIR_ROW);
        }
    }
    if ($('#tb_pst_template').length) {
        initPstTemplateTable();
        window.addEventListener('storage', function (e) {
            if (e.key === 'pst_list_dirty' && $.fn.DataTable.isDataTable('#tb_pst_template')) {
                $('#tb_pst_template').DataTable().ajax.reload(null, false);
            }
        });
    }
});
})();
