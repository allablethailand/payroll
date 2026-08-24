/**
 * Employment Certificate Template designer.
 *
 * 2026-08-24 v2 (explicit follow-up request: multiple templates + 2-3 standard presets, page
 * size/orientation, a reusable uploaded-image library, a Preview watermark toggle, native
 * drag-and-drop placement + keyboard/quick delete, and full "เหมือน Word" text formatting --
 * font family/color/bold/italic/underline). Builds on phase 1's canvas (drag position + resize,
 * still plain mouse events, no new JS dependency) -- see EmploymentCertificateTemplateModel's own
 * docblock for the backend side of each of these.
 *
 * State is now 2-tier: `templateList` (every template for the current language, for the picker)
 * and `currentTemplate` + `elements[]` (the ONE template currently open on the canvas, exactly
 * like phase 1). Switching the picker or the language tab reloads both from scratch -- no
 * cross-template/cross-language state is kept, same convention as before.
 */
let currentLanguage = 'th';
let currentTemplate = null; // the template row currently open on the canvas, or null if none exist yet
let templateList = [];
let elements = [];
let logoPath = null;
let selectedKey = null;
let dirty = false;
let elementKeyCounter = 0;
let textModalKey = null;
let fieldTypesCache = [];
let presetCache = [];
let imageLibraryCache = [];
let chosenPreset = 'blank';

const PAGE_SIZES_MM = { A4: [210, 297], Letter: [215.9, 279.4], Legal: [215.9, 355.6] };
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
    $('#ectSaveHint').text(dirty ? (langData['ect_unsaved_hint'] || 'Unsaved changes — click Save.') : '');
}

function emptyElementBase() {
    return {
        font_size: 14, font_family: 'th_sarabun_new', font_color: '#000000',
        text_align: 'left', font_weight: 'normal', font_style: 'normal', text_decoration: 'none'
    };
}

/* ---------- Field palette (click OR native drag-and-drop onto the canvas) ---------- */
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
            </div>
        `);
        groups[g].forEach(ft => {
            const label = currentLang === 'th' ? ft.name_th : ft.name_en;
            $group.append(`<button type="button" draggable="true" class="btn btn-outline-secondary btn-sm w-100 mb-1 text-start ect-palette-btn" data-code="${ft.code}" data-element-type="${ft.element_type}"><i class="fa-solid ${paletteIcon(ft)} me-1"></i>${escapeHtmlEct(label)}</button>`);
        });
        $wrap.append($group);
    });
}
function addElementFromPalette(code, elementType, posX, posY) {
    if (!currentTemplate) {
        showWarning(langData['ect_no_templates_yet'] || 'Create a template first.');
        return;
    }
    let el;
    if (elementType === 'image') {
        if (code === 'company_logo') {
            const existing = elements.find(e => e.element_type === 'image' && e.field_key === 'company_logo');
            if (existing) { selectElement(existing.key); return; }
        }
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
    if (!currentTemplate) {
        showWarning(langData['ect_no_templates_yet'] || 'Create a template first.');
        return;
    }
    const el = Object.assign(emptyElementBase(), {
        key: newElementKey(), id: null, element_type: 'image', field_key: null, image_asset_id: Number(assetId), content: null,
        pos_x_pct: clampEct(posX, 0, 80), pos_y_pct: clampEct(posY, 0, 85), width_pct: 20, height_pct: 15
    });
    elements.push(el);
    renderCanvas();
    selectElement(el.key);
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '.ect-palette-btn', function () {
    addElementFromPalette($(this).data('code'), $(this).data('element-type'), 10, 10);
});
$(document).on('dragstart', '.ect-palette-btn', function (e) {
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
function elementHtml(el) {
    const isImage = el.element_type === 'image';
    const body = isImage ? '<i class="fa-solid fa-image"></i>' : escapeHtmlEct(el.content).replace(/\n/g, '<br>');
    return `
        <div class="ect-el ${isImage ? 'ect-el-image' : 'ect-el-text'}" data-key="${el.key}">
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
    $el.find('.ect-el-body').css({
        fontSize: el.font_size + 'px',
        textAlign: el.text_align,
        fontWeight: el.font_weight === 'bold' ? '700' : '400',
        fontStyle: el.font_style === 'italic' ? 'italic' : 'normal',
        textDecoration: el.text_decoration === 'underline' ? 'underline' : 'none',
        color: el.font_color || '#000000',
        fontFamily: el.font_family === 'dejavu_sans' ? "'DejaVu Sans', sans-serif" : "'TH Sarabun New', sans-serif"
    });
}
function updateCanvasDimensions() {
    const pageSize = $('#ectPageSizeSelect').val() || 'A4';
    const orientation = $('#ectOrientationSelect').val() || 'portrait';
    const [w, h] = pageDimensionsMm(pageSize, orientation);
    $('#ectPage').css({ width: w + 'mm', height: h + 'mm' });
}
function renderCanvas() {
    const $page = $('#ectPage').empty();
    elements.forEach(el => {
        const $el = $(elementHtml(el));
        $page.append($el);
        applyElementStyle($el, el);
        makeInteractive($el, el);
    });
    updateSelectionUI();
}

/* ---------- Drag / resize (plain mouse events) ---------- */
function makeInteractive($el, el) {
    $el.on('mousedown', function (e) {
        if ($(e.target).hasClass('ect-resize-handle') || $(e.target).closest('.ect-quick-delete').length) return;
        e.preventDefault();
        selectElement(el.key);
        const page = document.getElementById('ectPage');
        const pageRect = page.getBoundingClientRect();
        const startX = e.clientX, startY = e.clientY;
        const startLeft = el.pos_x_pct, startTop = el.pos_y_pct;
        function onMove(ev) {
            const dxPct = (ev.clientX - startX) / pageRect.width * 100;
            const dyPct = (ev.clientY - startY) / pageRect.height * 100;
            el.pos_x_pct = clampEct(startLeft + dxPct, 0, 100 - el.width_pct);
            el.pos_y_pct = clampEct(startTop + dyPct, 0, 100 - el.height_pct);
            applyElementStyle($el, el);
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
        selectElement(el.key);
        const page = document.getElementById('ectPage');
        const pageRect = page.getBoundingClientRect();
        const startX = e.clientX, startY = e.clientY;
        const startW = el.width_pct, startH = el.height_pct;
        function onMove(ev) {
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
        if (el.element_type === 'text') openTextModal(el.key);
    });
}

/* ---------- Selection + property panel ---------- */
function selectElement(key) {
    selectedKey = key;
    $('.ect-el').removeClass('ect-el-selected');
    $(`.ect-el[data-key="${key}"]`).addClass('ect-el-selected');
    updateSelectionUI();
}
function updateSelectionUI() {
    const el = findElement(selectedKey);
    const $ribbonControls = $('#ectRibbon input, #ectRibbon select, #ectRibbon button');
    if (!el) {
        $ribbonControls.prop('disabled', true);
        $('.ect-align-btn, .ect-style-btn').removeClass('active');
        $('#ectRibbonHint').removeClass('d-none');
        return;
    }
    $ribbonControls.prop('disabled', false);
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
$('#ectPage').on('mousedown', function (e) {
    if (e.target === this) {
        selectedKey = null;
        $('.ect-el').removeClass('ect-el-selected');
        updateSelectionUI();
    }
});
$(document).on('input', '#ectPropFontSize', function () {
    const el = findElement(selectedKey);
    if (!el) return;
    el.font_size = parseInt($(this).val(), 10) || 14;
    applyElementStyle($(`.ect-el[data-key="${selectedKey}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('change', '#ectPropFontFamily', function () {
    const el = findElement(selectedKey);
    if (!el) return;
    el.font_family = $(this).val();
    applyElementStyle($(`.ect-el[data-key="${selectedKey}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('input', '#ectPropFontColor', function () {
    const el = findElement(selectedKey);
    if (!el) return;
    el.font_color = $(this).val();
    applyElementStyle($(`.ect-el[data-key="${selectedKey}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-align-btn', function () {
    const el = findElement(selectedKey);
    if (!el) return;
    el.text_align = $(this).data('align');
    $('.ect-align-btn').removeClass('active');
    $(this).addClass('active');
    applyElementStyle($(`.ect-el[data-key="${selectedKey}"]`), el);
    dirty = true;
    updateSaveHint();
});
$(document).on('click', '.ect-style-btn', function () {
    const el = findElement(selectedKey);
    if (!el) return;
    const styleType = $(this).data('style');
    if (styleType === 'bold') {
        el.font_weight = el.font_weight === 'bold' ? 'normal' : 'bold';
    } else if (styleType === 'italic') {
        el.font_style = el.font_style === 'italic' ? 'normal' : 'italic';
    } else if (styleType === 'underline') {
        el.text_decoration = el.text_decoration === 'underline' ? 'none' : 'underline';
    }
    $(this).toggleClass('active');
    applyElementStyle($(`.ect-el[data-key="${selectedKey}"]`), el);
    dirty = true;
    updateSaveHint();
});
function deleteSelectedElement() {
    if (!selectedKey) return;
    elements = elements.filter(e => e.key !== selectedKey);
    selectedKey = null;
    renderCanvas();
    dirty = true;
    updateSaveHint();
}
$(document).on('click', '#ectDeleteElementBtn', deleteSelectedElement);
$(document).on('click', '.ect-quick-delete', function (e) {
    e.stopPropagation();
    selectedKey = $(this).closest('.ect-el').data('key');
    deleteSelectedElement();
});
// Delete/Backspace deletes the selected element -- unless the user is typing somewhere (an
// input/textarea/contenteditable), where Backspace must behave normally.
$(document).on('keydown', function (e) {
    if ((e.key === 'Delete' || e.key === 'Backspace') && selectedKey) {
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || e.target.isContentEditable) return;
        e.preventDefault();
        deleteSelectedElement();
    }
});

/* ---------- Free-text content modal ---------- */
function openTextModal(key) {
    textModalKey = key;
    const el = findElement(key);
    if (!el) return;
    $('#ectTextModalInput').val(el.content || '');
    new bootstrap.Modal(document.getElementById('ectTextModal')).show();
}
$(document).on('click', '#ectTextModalApplyBtn', function () {
    const el = findElement(textModalKey);
    if (!el) return;
    el.content = $('#ectTextModalInput').val();
    renderCanvas();
    selectElement(textModalKey);
    bootstrap.Modal.getInstance(document.getElementById('ectTextModal')).hide();
    dirty = true;
    updateSaveHint();
});

/* ---------- Logo upload (per-template) ---------- */
function showLogoPreview() {
    if (logoPath) {
        $('#ectLogoPreview img').attr('src', `${BASE_URL}/${logoPath}`);
        $('#ectLogoPreview').removeClass('d-none');
    } else {
        $('#ectLogoPreview').addClass('d-none');
    }
}
$(document).on('change', '#ectLogoInput', function () {
    const file = this.files && this.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.upload-logo`,
        method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) {
                logoPath = res.logo_path;
                showLogoPreview();
                dirty = true;
                updateSaveHint();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Upload failed.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); }
    });
    $(this).val('');
});

/* ---------- Reusable image library ---------- */
function loadImageLibrary() {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.list-images`,
        method: 'GET', dataType: 'json',
        success: function (res) {
            if (res.status) {
                imageLibraryCache = res.data || [];
                renderImageLibrary();
            }
        }
    });
}
function renderImageLibrary() {
    const $grid = $('#ectImageLibraryGrid').empty();
    imageLibraryCache.forEach(img => {
        $grid.append(`
            <div class="ect-image-grid-item" data-id="${img.id}">
                <img src="${BASE_URL}/${img.file_path}" alt="">
                <div class="ect-image-delete" data-id="${img.id}"><i class="fa-solid fa-xmark"></i></div>
            </div>
        `);
    });
    $('#ectImageLibraryEmpty').toggleClass('d-none', imageLibraryCache.length > 0);
}
$(document).on('click', '#ectOpenImageLibraryBtn', function () {
    loadImageLibrary();
    new bootstrap.Modal(document.getElementById('ectImageLibraryModal')).show();
});
$(document).on('click', '.ect-image-grid-item', function (e) {
    if ($(e.target).closest('.ect-image-delete').length) return;
    addImageAssetElement($(this).data('id'), 10, 10);
    bootstrap.Modal.getInstance(document.getElementById('ectImageLibraryModal')).hide();
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
                    loadImageLibrary();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                }
            }
        });
    });
});
$(document).on('change', '#ectLibraryUploadInput', function () {
    const file = this.files && this.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.upload-image`,
        method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) {
                loadImageLibrary();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Upload failed.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); }
    });
    $(this).val('');
});

/* ---------- Template picker (list / load / new-from-preset / duplicate / delete / set default) ---------- */
function updateFontFamilyOptions() {
    // DejaVu Sans has NO Thai glyphs at all (confirmed by parsing its cmap table -- see
    // storage/fonts/thsarabun/NOTICE.md) -- only offered on the English tab, where it's a genuine
    // stylistic alternative to TH Sarabun New (which also covers Latin fine either way).
    $('#ectPropFontFamily option[data-en-only]').toggle(currentLanguage === 'en');
}
function renderTemplateSelect() {
    const $select = $('#ectTemplateSelect').empty();
    if (!templateList.length) {
        $select.append(`<option value="">${langData['ect_no_templates_yet'] || 'No templates yet'}</option>`);
        return;
    }
    templateList.forEach(t => {
        const star = Number(t.is_default) === 1 ? '★ ' : '';
        const selected = currentTemplate && Number(currentTemplate.id) === Number(t.id) ? 'selected' : '';
        $select.append(`<option value="${t.id}" ${selected}>${star}${escapeHtmlEct(t.template_name)}</option>`);
    });
}
function renderTemplateMeta() {
    $('#ectTemplateNameInput').val(currentTemplate ? currentTemplate.template_name : '');
    $('#ectPageSizeSelect').val(currentTemplate ? currentTemplate.page_size : 'A4');
    $('#ectOrientationSelect').val(currentTemplate ? currentTemplate.orientation : 'portrait');
    const hasTemplate = !!currentTemplate;
    $('#ectSaveBtn, #ectPreviewBtn, #ectSetDefaultBtn, #ectDuplicateTemplateBtn, #ectDeleteTemplateBtn, #ectTemplateNameInput, #ectPageSizeSelect, #ectOrientationSelect')
        .prop('disabled', !hasTemplate);
}
function loadTemplateById(id) {
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.get`,
        method: 'GET', data: { id }, dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            currentTemplate = res.data;
            logoPath = currentTemplate.logo_path || null;
            elements = (currentTemplate.elements || []).map(e => ({
                key: newElementKey(), id: Number(e.id), element_type: e.element_type, field_key: e.field_key,
                image_asset_id: e.image_asset_id ? Number(e.image_asset_id) : null, content: e.content,
                pos_x_pct: Number(e.pos_x_pct), pos_y_pct: Number(e.pos_y_pct), width_pct: Number(e.width_pct), height_pct: Number(e.height_pct),
                font_size: Number(e.font_size), font_family: e.font_family, font_color: e.font_color,
                text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration
            }));
            selectedKey = null;
            renderTemplateSelect();
            renderTemplateMeta();
            showLogoPreview();
            updateCanvasDimensions();
            renderCanvas();
            dirty = false;
            updateSaveHint();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function loadTemplateList(language, selectId) {
    currentLanguage = language;
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.list`,
        method: 'GET', data: { language }, dataType: 'json',
        success: function (res) {
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            templateList = res.data || [];
            if (!templateList.length) {
                currentTemplate = null;
                elements = [];
                logoPath = null;
                selectedKey = null;
                renderTemplateSelect();
                renderTemplateMeta();
                showLogoPreview();
                updateCanvasDimensions();
                renderCanvas();
                dirty = false;
                updateSaveHint();
                return;
            }
            const defaultRow = templateList.find(t => Number(t.is_default) === 1) || templateList[0];
            loadTemplateById(Number(selectId || defaultRow.id));
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}

/* New Template modal (choose a starter preset) */
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
function renderPresetList() {
    const icons = { blank: 'fa-file', classic: 'fa-align-center', modern: 'fa-image', minimal: 'fa-align-left' };
    const $wrap = $('#ectPresetList').empty();
    presetCache.forEach(p => {
        const label = currentLang === 'th' ? p.name_th : p.name_en;
        $wrap.append(`<div class="ect-preset-card ${chosenPreset === p.code ? 'active' : ''}" data-code="${p.code}"><i class="fa-solid ${icons[p.code] || 'fa-file'}"></i>${escapeHtmlEct(label)}</div>`);
    });
}
$(document).on('click', '#ectNewTemplateBtn', function () {
    chosenPreset = 'blank';
    $('#ectNewTemplateNameInput').val('');
    loadPresets();
    new bootstrap.Modal(document.getElementById('ectNewTemplateModal')).show();
});
$(document).on('click', '.ect-preset-card', function () {
    chosenPreset = $(this).data('code');
    $('.ect-preset-card').removeClass('active');
    $(this).addClass('active');
});
$(document).on('click', '#ectCreateTemplateBtn', function () {
    const name = $('#ectNewTemplateNameInput').val().trim();
    if (!name) {
        showWarning(langData['ect_select_template_name_required'] || 'Please enter a template name.');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.create-from-preset`,
        method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ language: currentLanguage, preset: chosenPreset, template_name: name }),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('ectNewTemplateModal')).hide();
                loadTemplateList(currentLanguage, res.template_id);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});

$(document).on('change', '#ectTemplateSelect', function () {
    const id = $(this).val();
    if (id) loadTemplateById(Number(id));
});
$(document).on('click', '#ectSetDefaultBtn', function () {
    if (!currentTemplate) return;
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.set-default`,
        method: 'POST', data: { id: currentTemplate.id }, dataType: 'json',
        success: function (res) {
            if (res.status) {
                loadTemplateList(currentLanguage, currentTemplate.id);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        }
    });
});
$(document).on('click', '#ectDuplicateTemplateBtn', function () {
    if (!currentTemplate) return;
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.duplicate`,
        method: 'POST', data: { id: currentTemplate.id }, dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                loadTemplateList(currentLanguage, res.template_id);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        }
    });
});
$(document).on('click', '#ectDeleteTemplateBtn', function () {
    if (!currentTemplate) return;
    showConfirm(langData['ect_confirm_delete_template'] || 'Delete this template? This action cannot be undone.', '', function () {
        $.ajax({
            url: `${BASE_URL}/api/employment-certificate-template.delete`,
            method: 'POST', data: { id: currentTemplate.id }, dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.');
                    loadTemplateList(currentLanguage);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                }
            }
        });
    });
});
$(document).on('input', '#ectTemplateNameInput', function () { dirty = true; updateSaveHint(); });
$(document).on('change', '#ectPageSizeSelect, #ectOrientationSelect', function () {
    updateCanvasDimensions();
    renderCanvas();
    dirty = true;
    updateSaveHint();
});

/* ---------- Save ---------- */
function elementsPayload() {
    return elements.map(e => ({
        element_type: e.element_type, field_key: e.field_key, image_asset_id: e.image_asset_id, content: e.content,
        pos_x_pct: e.pos_x_pct, pos_y_pct: e.pos_y_pct, width_pct: e.width_pct, height_pct: e.height_pct,
        font_size: e.font_size, font_family: e.font_family, font_color: e.font_color,
        text_align: e.text_align, font_weight: e.font_weight, font_style: e.font_style, text_decoration: e.text_decoration
    }));
}
$(document).on('click', '#ectSaveBtn', function () {
    if (!currentTemplate) return;
    const templateName = $('#ectTemplateNameInput').val().trim();
    if (!templateName) {
        showWarning(langData['ect_select_template_name_required'] || 'Please enter a template name.');
        return;
    }
    const payload = {
        id: currentTemplate.id, language: currentLanguage, template_name: templateName,
        page_size: $('#ectPageSizeSelect').val(), orientation: $('#ectOrientationSelect').val(),
        logo_path: logoPath, elements: elementsPayload()
    };
    $.ajax({
        url: `${BASE_URL}/api/employment-certificate-template.save`,
        method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                loadTemplateList(currentLanguage, res.template_id);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
    });
});

/* ---------- Preview (streams a PDF back -- fetch + Content-Type sniffing, same pattern as
   Payslip Template's own preview, since jQuery ajax can't handle a blob response). Watermark is
   preview-only -- never sent to save(). ---------- */
$(document).on('change', '#ectWatermarkToggle', function () {
    $('#ectWatermarkText').toggleClass('d-none', !this.checked);
});
$(document).on('click', '#ectPreviewBtn', function () {
    if (!elements.length) {
        showWarning(langData['ect_add_element_first'] || 'Add at least one element before previewing.');
        return;
    }
    const payload = {
        language: currentLanguage, logo_path: logoPath,
        page_size: $('#ectPageSizeSelect').val(), orientation: $('#ectOrientationSelect').val(),
        elements: elementsPayload(),
        watermark_enabled: $('#ectWatermarkToggle').is(':checked'),
        watermark_text: $('#ectWatermarkText').val()
    };
    fetch(`${BASE_URL}/api/employment-certificate-template.preview`, {
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
        return res.json().then(data => { showWarning(data.message || langData['save_failed'] || 'Preview failed.'); });
    }).catch(() => showWarning(langData['save_failed'] || 'Preview failed.'));
});

/* ---------- Language pills ---------- */
function switchLanguageTab($btn) {
    $('#ectLanguageTabs .nav-link').removeClass('active');
    $btn.addClass('active');
    selectedKey = null;
    updateFontFamilyOptions();
    loadTemplateList($btn.data('language'));
}
$(document).on('click', '#ectLanguageTabs .nav-link', function () {
    const $btn = $(this);
    if ($btn.hasClass('active')) return;
    if (dirty) {
        showConfirm(
            langData['ect_unsaved_language_switch_message'] || 'You have unsaved changes in this language. Switching tabs now will discard them. Continue anyway?',
            langData['ect_unsaved_language_switch_title'] || 'Unsaved changes',
            function () { switchLanguageTab($btn); }
        );
        return;
    }
    switchLanguageTab($btn);
});

$(document).ready(function () {
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
    updateFontFamilyOptions();
    loadTemplateList(currentLanguage);
});
