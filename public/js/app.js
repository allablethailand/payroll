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
});
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
        const currentVal = $select.val(); // เก็บค่าที่เลือกไว้อยู่เดิม

        $select.empty(); // ล้าง option เดิมออกก่อน

        // วนลูปสร้าง option ใหม่ตามภาษาปัจจุบัน
        keys.forEach(function(key) {
            const cleanKey = key.trim();
            // ดึงคำแปลจาก langData ถ้าไม่มีให้ใช้ cleanKey เป็นค่าเริ่มต้น
            const translatedText = (typeof langData !== 'undefined' && langData[cleanKey]) 
                ? langData[cleanKey] 
                : cleanKey;

            const newOption = new Option(translatedText, cleanKey);
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