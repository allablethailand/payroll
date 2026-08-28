const pageLength = 50;
const lengthMenu = [[50, 100, 250, 500, 1000, -1], [50, 100, 250, 500, 1000, "All"]];
let currentLang = 'th';
let registered_country = 'TH';
let langData = {};
const langInfo = {
    en: { flag: 'gb', label: 'EN', full: 'English' },
    th: { flag: 'th', label: 'TH', full: 'ไทย' }
};
$(document).ready(async function() {
    currentLang = localStorage.getItem('preferred_language') || 'en';
    await loadLang(currentLang);
    buildLanguageMenu();
    $('.nav-lang-btn').on('click', function(e) {
        e.stopPropagation();
        $('#languageMenu').toggleClass('active');
    });
    $(document).on('click', '.dropdown-lang-item', async function(e) {
        e.preventDefault();
        const selectedValue = $(this).data('value');
        await changeLanguage(selectedValue);
        $('#languageMenu').removeClass('active');
    });
    $('.nav-hub-btn').on('click', function(e) {
        e.stopPropagation();
        $('#hubMenu').toggleClass('active');
    });
    $(document).on('click', function() {
        $('#languageMenu').removeClass('active');
        $('#hubMenu').removeClass('active');
    });
    $('.nav-btn-hamberger').on('click', function(e) {
        e.stopPropagation();
        $('#origamiSidebar').toggleClass('active');
        $('#sidebarOverlay').toggleClass('active');
    });
    $('#sidebarOverlay').on('click', function() {
        $('#origamiSidebar').removeClass('active');
        $('#sidebarOverlay').removeClass('active');
    });
    $('.submenu-toggle').on('click', function(e) {
        e.preventDefault();
        const $parent = $(this).parent('.menu-item');
        const $submenu = $(this).next('.submenu');
        $submenu.slideToggle(250);
        $parent.toggleClass('open');
        $parent.siblings('.has-submenu').removeClass('open').find('.submenu').slideUp(200);
    });
    initSelect2Remote('.select2-remote');
    initSelect2('.select2-static', { mode: 'static' });
    initSelect2('.select2-native', { mode: 'native' });
    registerSidebarMenuSearch();
});
// 2026-08-23, explicit request ("ใน Menu อยากให้เพิ่มช่องในการค้นหา Menu ในกรณีที่ Menu เยอะๆ") --
// filters the sidebar as you type, matching against each item's CURRENT-LANGUAGE label (works in
// both TH/EN since .menu-text/.submenu-text are already translated in place by applyLanguage()).
// A top-level item with a matching submenu entry stays visible and force-opens even when its own
// label doesn't match, so searching "Payroll Configuration" finds it without knowing it lives
// under "Settings" -- non-matching sibling submenu entries are hidden too, so only the relevant
// row(s) show once expanded. Clearing the box restores every item to its normal (collapsed) state.
function registerSidebarMenuSearch() {
    const $input = $('#sidebarMenuSearch');
    const $list = $('#sidebarMenuList');
    if (!$input.length || !$list.length) return;
    function norm(str) {
        return (str || '').trim().toLowerCase();
    }
    function resetSidebarMenu() {
        $list.find('.sidebar-menu-no-results').remove();
        $list.children('.menu-item').show();
        $list.find('.submenu > li').show();
        $list.children('.menu-item.has-submenu').removeClass('open').find('.submenu').css('display', '');
    }
    $input.on('input', function () {
        const term = norm($(this).val());
        $list.find('.sidebar-menu-no-results').remove();
        if (!term) {
            resetSidebarMenu();
            return;
        }
        let anyVisible = false;
        $list.children('.menu-item').each(function () {
            const $item = $(this);
            const $submenu = $item.find('.submenu');
            const ownMatch = norm($item.find('.menu-text').first().text()).includes(term);
            if ($submenu.length) {
                let childMatch = false;
                $submenu.children('li').each(function () {
                    const match = norm($(this).find('.submenu-text').text()).includes(term);
                    $(this).toggle(ownMatch || match);
                    if (match) childMatch = true;
                });
                const show = ownMatch || childMatch;
                $item.toggle(show);
                if (show) {
                    $item.addClass('open');
                    $submenu.show();
                    anyVisible = true;
                }
            } else {
                $item.toggle(ownMatch);
                if (ownMatch) anyVisible = true;
            }
        });
        if (!anyVisible) {
            $list.append(`<li class="sidebar-menu-no-results">${(typeof langData !== 'undefined' && langData['menu_no_results']) || 'No matching menu items'}</li>`);
        }
    });
}
function getTableLang() {
    return {
        search: langData.search || "Search",
        lengthMenu: langData.lengthMenu || "Show _MENU_ entries",
        zeroRecords: langData.zeroRecords || "No matching records found",
        info: langData.info || "Showing _START_ to _END_ of _TOTAL_ entries",
        infoEmpty: langData.infoEmpty || "Showing 0 to 0 of 0 entries",
        infoFiltered: langData.infoFiltered || "(filtered from _MAX_ total entries)",
        paginate: {
            first: langData.first || "First",
            last: langData.last || "Last",
            next: langData.next || "Next",
            previous: langData.previous || "Previous"
        }
    };
}
// 2026-08-26, explicit request: "Format วันที่การแสดงผลทั้งหมดของระบบให้เป็น dd/mm/yyyy" (make every date
// display in the system dd/mm/yyyy). Several pages already had their OWN local helper doing exactly
// this (employee/detail.js's own toDisplayDate(), payroll/approval.js's toDisplayDateAp(), payroll/
// index.js's toDisplayDatePr()) -- those are left alone, they already produce dd/mm/yyyy correctly
// and touching working code for no behavioral gain isn't worth the risk. These two are for the
// GENUINELY unformatted spots found during the audit (DataTables columns that rendered a raw ISO
// string straight from the API with no render() at all: reports/index.js's generated_at, payslip-
// delivery-log.js's sent_at, payslip-request.js/employment-certificate-request.js's created_at,
// payroll/detail.js's performed_at, employee/list.js's start_work_date, tax-statutory.js's
// effective_date, approval-request-detail.js's requested_at/acted_at) and for any FUTURE page that
// needs one and doesn't already have a local copy -- named distinctly from every existing
// toDisplayDate*() so loading this file's declaration doesn't jam any of those (a global `function`
// redeclaration is legal but load-order-fragile, same risk class as this project's own documented
// duplicate-top-level-declaration bug in payslip-template.js/employment-certificate-template.js).
//
// formatDisplayDate: a DATE-ONLY value ('YYYY-MM-DD', or the date part of a full timestamp) -> 'DD/MM/YYYY'.
function formatDisplayDate(value) {
    if (!value) return '';
    const datePart = String(value).substring(0, 10);
    const parts = datePart.split('-');
    if (parts.length !== 3) return value;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
// formatDisplayDateTime: a full timestamp ('YYYY-MM-DD HH:mm:ss' or 'YYYY-MM-DDTHH:mm:ss') ->
// 'DD/MM/YYYY HH:mm' (seconds dropped -- matches the existing toDisplayDateAp()/toDisplayDatePr()
// precedent of showing HH:mm only, not HH:mm:ss).
function formatDisplayDateTime(value) {
    if (!value) return '';
    const str = String(value);
    const datePart = formatDisplayDate(str.substring(0, 10));
    const timePart = str.substring(11, 16);
    return timePart ? `${datePart} ${timePart}` : datePart;
}
async function changeLanguage(lang) {
    if (currentLang === lang) return;
    currentLang = lang;
    localStorage.setItem('preferred_language', lang);
    await loadLang(lang);
}
async function loadLang(lang) {
    try {
        const res = await fetch(`${BASE_URL}/public/lang/${lang}.json?v=${Date.now()}`);
        if (!res.ok) throw new Error('Language file missing');
        langData = await res.json();
        applyLanguage(lang);
        const info = langInfo[lang];
        if (info) {
            $('.text-current-lang').text(info.label);
            $('.current-flag').attr('src', `${BASE_URL}/public/flags/${info.flag}.png`);
        } 
        console.log(`[i18n] โหลดภาษาสำเร็จ: ${lang.toUpperCase()}`);
    } catch (e) {
        console.error("Error loading language file:", e);
    }
}
function applyLanguage(lang, root = document) {
    updateText(root);

    // --- [เพิ่มส่วนนี้] สำหรับประมวลผล select ที่ใช้ data-option-keys ---
    $(root).find('select[data-option-keys]').each(function() {
        const $select = $(this);
        const keys = $select.attr('data-option-keys').split(',');
        // 2026-08-21 bug fix: this rebuild ignored data-option-values and always used the raw i18n
        // key as the <option> value. For any field where key !== submit value (data-option-values
        // present -- e.g. attendanceRateUnit's attendance_deduction_rate_unit_minute -> 'minute'),
        // this ran here (via loadLang() at page load) BEFORE initSelect2's own '.select2-static'
        // sweep, planting options valued with the wrong (key) id. initSelect2 then built its own
        // data array with the CORRECT id, and Select2's ArrayAdapter only replaces an existing
        // option when its id matches -- since it didn't, it appended a second, correctly-valued
        // option instead, leaving 6 entries (2 per choice, identical text) in the dropdown. Reading
        // data-option-values here too, the same way initSelect2's static branch already does, makes
        // both agree on the id so Select2 replaces in place instead of duplicating.
        const explicitValues = ($select.attr('data-option-values') || '').split(',').filter(Boolean);
        const currentVal = $select.val(); // เก็บค่าที่เลือกไว้อยู่เดิม

        $select.empty(); // ล้าง option เดิมออกก่อน

        // วนลูปสร้าง option ใหม่ตามภาษาปัจจุบัน
        keys.forEach(function(key, idx) {
            const cleanKey = key.trim();
            const optionValue = explicitValues[idx] !== undefined ? explicitValues[idx].trim() : cleanKey;
            // ดึงคำแปลจาก langData ถ้าไม่มีให้ใช้ cleanKey เป็นค่าเริ่มต้น
            const translatedText = (typeof langData !== 'undefined' && langData[cleanKey])
                ? langData[cleanKey]
                : cleanKey;

            const newOption = new Option(translatedText, optionValue);
            $select.append(newOption);
        });

        // คืนค่าที่เคยเลือกไว้ (ถ้ามี)
        if (currentVal) {
            $select.val(currentVal);
        }
        
        // Trigger หากใช้ Select2
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.trigger('change.select2');
        }
    });
    // -------------------------------------------------------------

    if ($('#search_address').length && typeof currentCompanyAddresses !== 'undefined') {
        const addressText = (lang === 'th') ? currentCompanyAddresses.th : currentCompanyAddresses.en;
        $('#search_address').val(addressText);
    }

    $(root).find('.select2-remote.select2-hidden-accessible').each(function() {
        const $this = $(this);
        const val = $this.val();
        let selectedData = null;
        const select2Data = $this.select2('data');
        if (select2Data && select2Data.length > 0) {
            selectedData = select2Data[0];
            const extraData = $this.find('option:selected').data('data');
            if (extraData) {
                selectedData = $.extend({}, selectedData, extraData);
            }
        }
        initSelect2Remote($this); 
        if (val && selectedData) {
            const newText = (lang === 'th') ? selectedData.text_th : selectedData.text_en;
            if (newText) {
                $this.empty();
                const newOption = new Option(newText, val, true, true);
                selectedData.text = newText;
                $(newOption).data('data', selectedData); 
                $this.append(newOption);
            }
            $this.trigger('change');
            $this.trigger('change.select2');
        }
    });

    $(root).find('.select2-static.select2-hidden-accessible').each(function() {
        const $this = $(this);
        const val = $this.val();
        initSelect2($this, { mode: 'static', selectedValue: val });
    });

    $(root).find('.select2-native.select2-hidden-accessible').each(function() {
        const $this = $(this);
        const val = $this.val();
        initSelect2($this, { mode: 'native' });
        if (val) {
            $this.val(val).trigger('change');
        }
    });

    if (typeof refreshAllTables === 'function') {
        refreshAllTables();
    }
}
function buildLanguageMenu() {
    const langs = ['en', 'th'];
    const menu = $('#languageMenu').empty();
    langs.forEach(lang => {
        const info = langInfo[lang];
        if (!info) return;
        const item = $(`
            <li>
                <a class="dropdown-item dropdown-lang-item" href="javascript:void(0)" data-value="${lang}" data-lang="${info.label}" data-flag="${BASE_URL}/public/flags/${info.flag}.png">
                    <img src="${BASE_URL}/public/flags/${info.flag}.png" width="15" class="me-2" loading="lazy">
                    ${info.full}
                </a>
            </li>
        `);
        menu.append(item);
    });
}
function getLangValue(key) {
    return key.split('.').reduce((acc, part) => {
        return (acc && acc[part] !== undefined) ? acc[part] : undefined;
    }, langData);
}
function updateText(root = document) {
    const $elements = $(root).find('[data-i18n]').add($(root).filter('[data-i18n]'));
    $elements.each(function () {
        const $el = $(this);
        const key = $el.attr('data-i18n'); 
        const value = getLangValue(key);
        if (value !== undefined && value !== null) {
            if ($el.is('input, textarea')) {
                $el.attr('placeholder', value);
            } else if ($el.is('input[type="button"], input[type="submit"]')) {
                $el.val(value);
            } else if ($el.find('> i, > svg').length > 0) {
                const $icon = $el.find('> i, > svg').first();
                $el.html($icon[0].outerHTML + ' ' + value);
            } 
            else {
                $el.text(value);
            }
        }
    });
    $(root).find('[data-i18n-title]').each(function() {
        const $el = $(this);
        const key = $el.attr('data-i18n-title');
        const value = getLangValue(key);
        if (value !== undefined) {
            $el.attr('title', value);
        }
    });
}
function refreshAllTables() {
    const tableMappings = {
        'tb_employee': typeof initEmployeeTable === 'function' ? initEmployeeTable : null,
    };
    $('.dataTable').each(function () {
        const initFn = tableMappings[this.id];
        if (initFn) {
            initFn();
        }
    });
    if (typeof structureTables !== 'undefined' && structureTables) {
        Object.keys(structureTables).forEach(key => {
            const tableInstance = structureTables[key];
            if (tableInstance && $.fn.DataTable.isDataTable(tableInstance.table().node())) {
                tableInstance.rows().invalidate().draw(false);
            }
        });
    }
}