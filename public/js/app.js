const pageLength = 50;
const lengthMenu = [[50, 100, 250, 500, 1000, -1], [50, 100, 250, 500, 1000, "All"]];
let currentLang = 'th';
let registered_country = 'TH';
let langData = {};
const langInfo = {
    en: { flag: 'gb', label: 'EN', full: 'English' },
    th: { flag: 'th', label: 'TH', full: 'ไทย' }
};
// 2026-08-29, real bug found and fixed (explicit report: "อยากให้แสดง ชื่อ และข้อมูลอื่นๆตามภาษาที่เลือก
// Auto เปลี่ยนโดยไม่ต้อง Reload หน้า") -- this is much bigger than just the Employee List's Name
// column. Several controllers (BankAccountController/CompanyProfileController/
// PayrollConfigurationController/PayrollController/EmployeeController, 17 call sites total) already
// resolve which language to render bilingual SERVER-SIDE data in via
// `$_SESSION['lang'] ?? $_COOKIE['lang'] ?? 'th'` -- but grepping the ENTIRE codebase found that
// `$_SESSION['lang']`/a `lang` cookie is never actually SET anywhere, by anything. That fallback
// chain was permanently dead code -- every one of those 17 call sites always silently fell through
// to the hardcoded 'th' default, regardless of what the language switcher showed, because the
// client never had any way to tell the server what language was selected in the first place (the
// switcher only ever updated localStorage/langData for STATIC i18n text, which is a completely
// separate mechanism from these controllers' own per-request $lang resolution for DYNAMIC data).
// Fixed at the root with ONE change: syncLangCookie() sets a real `lang` cookie matching
// currentLang, sent automatically on every future request (including plain page navigations, not
// just ajax) -- since `$_COOKIE['lang']` was ALREADY the exact fallback every affected controller
// checks, this alone makes all 17 of them start working correctly with zero PHP changes needed.
// Called once on initial load (so the very first request of a fresh page already carries the right
// language) and again every time changeLanguage() runs.
function syncLangCookie(lang) {
    document.cookie = `lang=${lang}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`;
}
// The "Auto เปลี่ยนโดยไม่ต้อง Reload หน้า" (auto-change without reloading the page) half of the same
// request -- setting the cookie only affects FUTURE requests, so an already-rendered DataTable
// wouldn't pick up the new language until its next unrelated reload (pagination, a filter change,
// etc). Reloading every currently-initialized DataTable on the page right after a language change
// makes that happen immediately instead. `$.fn.dataTable.tables({ api: true })` covers every table
// on the page in one call, so this works for any current or future page with no per-page wiring --
// a table with no `ajax` option configured (fully static data) just silently no-ops.
function reloadAllTablesForLanguageChange() {
    if (typeof $.fn.dataTable === 'undefined') return;
    try {
        $.fn.dataTable.tables({ visible: true, api: true }).ajax.reload(null, false);
    } catch (e) { /* no ajax-backed tables on this page -- nothing to reload */ }
}

/** 2026-08-29: moved here from public/js/reports/index.js (unchanged) so any page can trigger a
 *  report download through the existing GET /api/report.generate endpoint -- originally only the
 *  Reports page itself loaded that file, but the Payroll Process List/Detail pages' own "print"
 *  shortcuts (SSO/RD/Bank Transfer for a specific run) need the exact same fetch+blob+download
 *  flow without pulling in the rest of reports/index.js's page-specific state. */
function generateReport(url) {
    fetch(url, { method: 'GET' })
        .then(async res => {
            const contentType = res.headers.get('Content-Type') || '';
            if (contentType.indexOf('application/json') !== -1) {
                const data = await res.json();
                showWarning(data.message || langData['generate_failed'] || 'Failed to generate the report.');
                return;
            }
            const disposition = res.headers.get('Content-Disposition') || '';
            const match = disposition.match(/filename="?([^"]+)"?/);
            const fileName = match ? match[1] : 'report';
            const blob = await res.blob();
            const blobUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(blobUrl);
            showSuccess(langData['generate_success'] || 'Report generated successfully.');
        })
        .catch(function () {
            showWarning(langData['generate_failed'] || 'Failed to generate the report.');
        });
}
$(document).ready(async function() {
    currentLang = localStorage.getItem('preferred_language') || 'en';
    syncLangCookie(currentLang);
    await loadLang(currentLang);
    buildLanguageMenu();
    // 2026-08-29, explicit request: per-user Font Size, applied from localStorage immediately (no
    // network round trip needed for first paint) -- reconciled against the server-saved value
    // (which wins if different, e.g. on a brand-new device/browser) by loadUserPreferences() below,
    // same "fast local default, then server reconciles" pattern the language switcher already used.
    applyFontSize(localStorage.getItem('preferred_font_size') || 'm');
    loadUserPreferences();
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
    $('.nav-profile-btn').on('click', function(e) {
        e.stopPropagation();
        $('#profileMenu').toggleClass('active');
    });
    $(document).on('click', function() {
        $('#languageMenu').removeClass('active');
        $('#hubMenu').removeClass('active');
        $('#profileMenu').removeClass('active');
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
//
// 2026-08-29, real bug found and fixed (explicit report: "เวลาที่ Save ลงใน Database เป็น UTC การ
// แสดงผลให้แปลงเป็น timezone ปัจจุบันของผู้ใช้") -- confirmed the premise first, not assumed:
// index.php calls date_default_timezone_set('UTC') and Database.php's PDO init command runs
// `SET time_zone = '+00:00'` on every connection, and a live query against this dev DB confirmed a
// real employees.updated_at row matches MySQL's own UTC_TIMESTAMP() exactly (not the +7 Bangkok
// offset it would show if the DB were actually storing local time) -- every DATETIME/TIMESTAMP
// value this app returns really is UTC. This function was doing PURE STRING SLICING with no
// timezone awareness at all, so a UTC timestamp was displayed VERBATIM as if it were already the
// viewer's local time -- correct only for a viewer whose own clock happens to be UTC+0, wrong (by
// exactly their UTC offset) for everyone else, e.g. Bangkok (+7) always saw times 7 hours behind
// reality. Fixed by explicitly marking the string as UTC before parsing it (`Date` parses a bare
// 'YYYY-MM-DD HH:mm:ss' as LOCAL time otherwise, which would silently re-introduce this exact bug
// -- the 'Z' suffix is what makes the difference) and reading it back via the normal local-timezone
// getters, which is what actually performs the UTC->local conversion.
//
// Deliberately does NOT touch formatDisplayDate() above -- a DATE-ONLY value (employment_date,
// period_start_date, date_of_birth, ...) has no time-of-day/timezone component to begin with (it's
// a calendar date, the same one everywhere on Earth), so converting it through a timezone would be
// WRONG, not a fix -- could shift it a day in either direction depending on the viewer's offset.
// This function only ever applies the conversion when a real time component is present.
function formatDisplayDateTime(value) {
    if (!value) return '';
    const str = String(value).trim();
    if (str.length <= 10) {
        return formatDisplayDate(str); // date-only value -- no time component, nothing to convert
    }
    let isoUtc = str.replace(' ', 'T');
    if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(isoUtc)) {
        isoUtc += 'Z'; // no timezone marker already present -- mark explicitly as UTC before parsing
    }
    const d = new Date(isoUtc);
    if (isNaN(d.getTime())) {
        return value; // unparseable -- fail safe with the raw value rather than showing 'Invalid Date'
    }
    const pad = n => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
async function changeLanguage(lang) {
    if (currentLang === lang) return;
    currentLang = lang;
    localStorage.setItem('preferred_language', lang);
    syncLangCookie(lang);
    // 2026-08-29, explicit request: "ภาษาล่าสุดที่ใช้งานก็ต้องเก็บเหมือนกัน" (the language last used
    // must be saved the same way [as font size, server-side]) -- fires from EVERY language change
    // regardless of which control triggered it (the top-right switcher's .dropdown-lang-item
    // handler, or the Settings modal's own language buttons both call this same function), so
    // there's exactly one place this needs to be wired in. Best-effort/fire-and-forget: localStorage
    // above already has it as the fast-path fallback if this request fails.
    persistUserPreferences(lang, localStorage.getItem('preferred_font_size') || 'm');
    await loadLang(lang);
    reloadAllTablesForLanguageChange();
    // Dashboard's greeting title/description are JS-templated (employee name + today's date
    // interpolated into a langData string) and no longer carry data-i18n for exactly that reason
    // -- only defined when dashboard.js is loaded (dashboard page only), so this is a no-op
    // everywhere else. See dashboard.js's own renderDashboard() docblock.
    if (typeof loadDashboardSummary === 'function') loadDashboardSummary();
}
// 2026-08-29, explicit request: per-user Font Size (S/M/L) + Language, persisted server-side (see
// UserPreferenceModel's own docblock) -- FONT_SIZE_STEPS maps the Settings modal's 0-2 slider
// position to the 3 saved values; `html[data-font-size]` drives the actual CSS scaling (see
// style.css's own comment on the `html, body { font-size: 12px }` rule this overrides).
const FONT_SIZE_STEPS = ['s', 'm', 'l'];
function applyFontSize(size) {
    document.documentElement.setAttribute('data-font-size', FONT_SIZE_STEPS.includes(size) ? size : 'm');
}
// Always sends BOTH values together, never just the one that changed -- UserPreferenceModel::save()
// is a full replace of both columns per call, so persisting only `language` (leaving `ui_font_size`
// undefined -> the controller's own 'm' default) would silently reset a user's saved font size back
// to Medium the next time they merely switched language. Every call site above/below reads the
// OTHER value fresh from localStorage first for exactly this reason.
async function persistUserPreferences(language, fontSize) {
    try {
        await fetch(`${BASE_URL}/api/user-preference.save`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ui_language: language, ui_font_size: fontSize }),
        });
    } catch (e) { /* best-effort -- localStorage already has both values as a fallback */ }
}
// Reconciles this device's local defaults against whatever was last saved server-side -- the
// server wins when it differs (e.g. a brand-new browser/device with empty localStorage, or the
// user changed a preference somewhere else since), so switching devices/browsers now actually
// carries the preference over instead of always falling back to English/Medium.
async function loadUserPreferences() {
    try {
        const res = await fetch(`${BASE_URL}/api/user-preference.get`);
        const json = await res.json();
        if (!json || !json.status || !json.data) return;
        const pref = json.data;
        if (pref.ui_language && pref.ui_language !== currentLang) {
            await changeLanguage(pref.ui_language);
        }
        const savedFontSize = pref.ui_font_size || 'm';
        if (savedFontSize !== (localStorage.getItem('preferred_font_size') || 'm')) {
            localStorage.setItem('preferred_font_size', savedFontSize);
            applyFontSize(savedFontSize);
        }
    } catch (e) { /* not logged in yet (public page) or a transient network error -- local defaults stand */ }
}
// Settings modal (profile icon -> Settings) -- Font Size only (Language was removed from this
// modal same-day, see the comment right above the Save handler further down for why). Live-
// previews the WHOLE page as the slider is dragged (applyFontSize() sets the
// attribute on <html>, which every page's CSS already scales from), same as changing it would
// look once actually saved; a Cancel/X/Esc/backdrop close reverts back to whatever was active
// when the modal opened (userSettingsJustSaved distinguishes "closing because Save was just
// clicked" from every other way the modal can close, all of which fire the same
// 'hidden.bs.modal' event) -- a slider benefits from this deliberate confirm step so dragging
// through several ticks doesn't fire a save per tick.
let userSettingsOriginalFontSize = 'm';
let userSettingsJustSaved = false;
// Delegated via $(document).on(event, selector, fn) rather than $('#userSettingsModal').on(...) --
// this script tag loads near the very top of <body>, before the modal markup further down the
// page has been parsed, so a direct element lookup here would silently bind to nothing (real bug
// caught before shipping, not guessed -- same class of gotcha this file's own $(document).ready()
// block already exists to avoid for everything inside it, but these 2 lines were originally written
// outside that block). Bootstrap's own modal events bubble up to document just like a native DOM
// event, so delegation works identically to direct binding once the element does exist.
$(document).on('show.bs.modal', '#userSettingsModal', function () {
    userSettingsJustSaved = false;
    const current = localStorage.getItem('preferred_font_size') || 'm';
    userSettingsOriginalFontSize = current;
    const idx = FONT_SIZE_STEPS.indexOf(current);
    $('#userSettingsFontSizeSlider').val(idx >= 0 ? idx : 1);
});
$(document).on('hidden.bs.modal', '#userSettingsModal', function () {
    if (!userSettingsJustSaved) {
        applyFontSize(userSettingsOriginalFontSize);
    }
});
$(document).on('input', '#userSettingsFontSizeSlider', function () {
    applyFontSize(FONT_SIZE_STEPS[Number($(this).val())] || 'm');
});
// 2026-08-29, same-day follow-up: "ตัวเปลี่ยนภาษาตัดออกจากใน modal setting ครับ เพราะมีใน header อยู่
// แล้ว" -- the language picker that used to live in this modal (.user-settings-lang-option click
// handler) was removed; the top-right nav-lang-dropdown switcher (.dropdown-lang-item, above) is
// the only language control now. Save below still sends `currentLang` alongside the font size --
// UserPreferenceModel::save() persists both columns together on every call (see its own
// docblock), so this Save button still correctly keeps whatever language is currently active,
// it just never CHANGES it anymore.
$(document).on('click', '#btnSaveUserSettings', function () {
    const size = FONT_SIZE_STEPS[Number($('#userSettingsFontSizeSlider').val())] || 'm';
    localStorage.setItem('preferred_font_size', size);
    applyFontSize(size);
    persistUserPreferences(currentLang, size);
    userSettingsJustSaved = true;
    if (typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('userSettingsModal')).hide();
    }
    if (typeof showSuccess === 'function') {
        showSuccess(langData['save_success'] || 'Saved successfully.');
    }
});
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
// 2026-08-28, explicit request: "หน้า Employee มีการแก้ไขในหน้า Detail แต่ใน List ไม่ Reload เอง...
// ให้เป็นกับทุกตารางที่มีการเปิดเข้าไปแก้ไขอีก Tab ได้" -- generalizes the localStorage cross-tab
// "dirty" signal Payslip Template/Employment Certificate Template's own canvas editors already
// established (see employment-certificate-template.js's own docblock on this) into 2 shared
// helpers, so every OTHER list-that-opens-its-editor-in-a-new-tab pair (Employee List <->
// Employee Detail, Payroll Process List/Approval Queue <-> Process Detail) can reuse the exact
// same mechanism instead of re-deriving it. A `key` is just an arbitrary localStorage key shared
// by one list+editor pair (e.g. 'employee_list_dirty') -- pick one unique per pair so unrelated
// tabs don't cross-trigger each other's reloads.
// markTabDirty(): call from the EDITOR tab right after a save actually succeeds. Writing to
// localStorage fires a native 'storage' event in every OTHER tab of the same origin (never in the
// tab that wrote it) -- wrapped in try/catch since some contexts (private browsing, storage
// blocked) throw on write; the list just won't auto-refresh in that case, not a hard failure.
function markTabDirty(key) {
    try { localStorage.setItem(key, String(Date.now())); } catch (e) { /* private browsing etc. */ }
}
// watchTabDirty(): call from the LIST tab once, at page init. `reloadFn` should reload that list's
// own DataTable in place (e.g. `() => tb_employee.ajax.reload(null, false)`). Two independent
// signals, same as the pattern this generalizes: the 'storage' event (fires immediately, but only
// while this tab is in the background/inactive in some browsers) plus a 'visibilitychange' fallback
// (catches the case of coming back to this tab after the editor tab already saved and closed).
function watchTabDirty(key, reloadFn) {
    window.addEventListener('storage', function (e) {
        if (e.key === key) reloadFn();
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') reloadFn();
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