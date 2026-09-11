/**
 * 2026-09-07, explicit request: "ทางลัด วางอยู่ล่างเกินไป ใช้งานไม่สะดวกครับ...ปรับเป็นให้อยู่บน header
 * ไปเลย ให้เรียงอยู่ก่อนหน้า notification โดยให้ผู้ใช้เลือกได้ว่าจะโชว์ หรือไม่โชว์เมนูไหน เลือกได้ทั้งเมนู และ
 * sub menu แต่การแสดงผลต้องไม่ล้นจอ ถ้าผู้ใช้เลือกเยอะให้แสดงเท่าที่จะแสดงได้ แล้วที่เหลือเป็นปุ่ม more กดแล้วให้
 * เป็น dropdown ลงมา" -- moves the old Dashboard-only "Quick Links" card into the navbar itself,
 * makes it per-user customizable (any sidebar link, top-level or submenu), and overflows into a
 * "More" dropdown by REAL DOM measurement rather than a guessed item count -- the requirement is
 * "must never overflow the screen" regardless of viewport width, zoom level, font size (this app has
 * a user-adjustable one), or how many items a given employee has selected, and a fixed guess can't
 * guarantee that the way an actual `scrollWidth` vs `clientWidth` comparison can.
 *
 * `QUICK_LINK_CATALOG`/`QUICK_LINK_SELECTED` (layout/header.php's own <script> block, right before
 * this file's own <script> tag) are this employee's server-computed, permission-filtered starting
 * state -- kept in 2 local mutable copies below so a Customize-modal save can update the header
 * in-place without a full page reload.
 *
 * English fallback text mirrors the exact same hardcoded strings header.php's own sidebar markup
 * already carries next to each `data-i18n`/`submenu-text` span -- these buttons are built in JS, not
 * server-rendered HTML, so they need their own copy of that same fallback-until-loadLang()-resolves
 * text (see updateText()'s own `[data-i18n-title]` sweep in app.js, which corrects these the moment
 * the real language file loads, exactly like it already does for the sidebar).
 */
const QUICK_LINK_FALLBACK_EN = {
    dashboard: 'Dashboard',
    employee_list_menu: 'Employee List',
    login_history: 'Login History',
    employee_reports: 'Reports',
    payroll_process: 'Payroll Process',
    payroll_approval: 'Payroll Approval',
    generate_reports: 'Generate Reports',
    annual_income_summary: 'Annual Income Summary',
    payroll_run_audit_menu: 'Payroll Run Audit',
    requests: 'Requests',
    settings: 'Settings',
    setup_and_rules: 'Setup & Rules',
    manual_time_entry: 'Manual Time Entry',
    audit_log_menu: 'Audit Log',
    announcement_menu: 'Announcements',
    company_profile: 'Company Profile',
    data_sync_menu: 'Data Sync',
    payroll_configuration: 'Payroll Configuration',
    tax_and_statutory: 'Tax & Statutory',
    document_and_approval: 'Document & Approval',
    permissions_menu: 'Permissions',
    setup_guide_menu: 'Setup Guide',
    version_menu: 'Version',
    // group headers (Customize modal only)
    employees: 'Employees',
    payroll_menu: 'Payroll',
    reports: 'Reports',
    payslip_menu: 'Payslip & Documents',
    time_and_leave: 'Time & Leave',
    help_menu: 'Help',
};
function qlFallback(key) {
    return QUICK_LINK_FALLBACK_EN[key] || key;
}
function qlLangValue(key) {
    return (typeof langData !== 'undefined' && langData[key]) ? langData[key] : qlFallback(key);
}

// 2026-09-07, real bug found and fixed (explicit report: "ตอนโหลดเมนูที่บันทึกไว้ไม่ขึ้นมาครับ") --
// `QUICK_LINK_CATALOG`/`QUICK_LINK_SELECTED` are declared with `const` in header.php's own <script>
// block -- a top-level `const`/`let` in a plain (non-module) <script> tag creates a binding in the
// shared script-global lexical scope, NOT a property on `window`, unlike `var` or a function
// declaration. Referencing them as `window.QUICK_LINK_CATALOG` was therefore always `undefined`
// regardless of what was actually saved -- this never worked, not just after a save. Every OTHER
// page-global in this app (IS_ORIGAMI_HR_LINKED, IS_ORIGAMI_PAYROLL_LINKED, COMPANY_CURRENCY_CODE,
// ...) is already read the same correct way elsewhere in this codebase: `typeof X !== 'undefined'`
// against the bare identifier, never `window.X` -- this just needed to follow that same convention.
let quickLinksCurrentCatalog = (typeof QUICK_LINK_CATALOG !== 'undefined' && Array.isArray(QUICK_LINK_CATALOG)) ? QUICK_LINK_CATALOG : [];
let quickLinksCurrentSelected = (typeof QUICK_LINK_SELECTED !== 'undefined' && Array.isArray(QUICK_LINK_SELECTED)) ? QUICK_LINK_SELECTED : [];

function quickLinkButtonHtml(item) {
    return `<a href="${BASE_URL}${item.url}" class="nav-quicklink-btn" data-key="${item.key}"
        title="${escapeAttr(qlLangValue(item.label))}" data-i18n-title="${item.label}">
        <img src="${BASE_URL}/public/images/menu/${item.icon}" alt="">
    </a>`;
}
function quickLinkMoreItemHtml(item) {
    return `<li><a href="${BASE_URL}${item.url}">
        <img src="${BASE_URL}/public/images/menu/${item.icon}" alt="">
        <span data-i18n="${item.label}">${escapeHtml(qlLangValue(item.label))}</span>
    </a></li>`;
}

/**
 * The measure-then-overflow algorithm: render EVERY selected item as a button first, then, while
 * the bar's real (post-layout) content width exceeds its allotted box, pop the LAST button into the
 * "More" menu and re-check -- `.nav-quicklinks` has `min-width:0; overflow:hidden` (style.css) so
 * its `clientWidth` reports the actual space the flexbox layout gave it (which can be smaller than
 * its children's combined width) while `scrollWidth` reports the full, un-clipped content width;
 * comparing the two is the standard, layout-agnostic way to detect "this doesn't fit" without ever
 * having to guess a pixel budget per icon.
 */
function layoutQuickLinks() {
    const catalogByKey = {};
    quickLinksCurrentCatalog.forEach(function (item) { catalogByKey[item.key] = item; });
    const items = quickLinksCurrentSelected.map(function (k) { return catalogByKey[k]; }).filter(Boolean);

    const $bar = $('#navQuickLinksBar');
    if (!$bar.length) return;
    $bar.empty();
    items.forEach(function (item) { $bar.append(quickLinkButtonHtml(item)); });

    const overflow = [];
    // +1px tolerance against sub-pixel rounding falsely tripping the loop forever.
    while ($bar[0].scrollWidth > $bar[0].clientWidth + 1 && $bar.children().length > 0) {
        const $last = $bar.children().last();
        const item = catalogByKey[$last.data('key')];
        if (item) overflow.unshift(item);
        $last.remove();
    }
    renderQuickLinksMoreMenu(overflow);
}

function renderQuickLinksMoreMenu(overflowItems) {
    const $menu = $('#navQuickLinksMoreMenu');
    if (!$menu.length) return;
    $menu.empty();
    overflowItems.forEach(function (item) { $menu.append(quickLinkMoreItemHtml(item)); });
    if (overflowItems.length) {
        $menu.append('<li><div class="nav-quicklinks-more-menu-divider"></div></li>');
    }
    $menu.append(`<li><button type="button" id="navQuickLinksCustomizeBtn">
        <i class="fa-solid fa-sliders"></i><span data-i18n="quick_links_customize_title">${escapeHtml(qlLangValue('quick_links_customize_title'))}</span>
    </button></li>`);
}

$(document).on('click', '#navQuickLinksMoreBtn', function (e) {
    e.stopPropagation();
    const $menu = $('#navQuickLinksMoreMenu');
    const willOpen = !$menu.hasClass('active');
    if (typeof closeNavFlyouts === 'function') closeNavFlyouts('quicklinks');
    $menu.toggleClass('active', willOpen);
});
$(document).on('click', '#navQuickLinksMoreMenu', function (e) { e.stopPropagation(); });

/* ==================== Customize Quick Links modal ==================== */
function qlGroupItemRowHtml(item, isChecked) {
    const domId = 'qlcItem_' + item.key.replace(/[^a-zA-Z0-9]/g, '_');
    return `<div class="qlc-item form-check">
        <input class="form-check-input qlc-checkbox" type="checkbox" id="${domId}" data-key="${item.key}" ${isChecked ? 'checked' : ''}>
        <img src="${BASE_URL}/public/images/menu/${item.icon}" alt="">
        <label class="form-check-label" for="${domId}" data-i18n="${item.label}">${escapeHtml(qlLangValue(item.label))}</label>
    </div>`;
}
// 2026-09-07, explicit request: "ปรับแต่งทางลัด Modal โล่งๆนะครับ ตรง Sub Menu น่าจะแสดงผลเป็น Grid จะสวย
// กว่า" -- items used to stack one per row (a single narrow column looked sparse/empty for a modal
// this wide); now a real grid (`.qlc-group-items`, see style.css) per group -- the group TITLE
// stays its own full-width block above the grid, only the checkbox rows themselves wrap into columns.
function renderQuickLinksCustomizeList(catalog, selectedKeys) {
    const selectedSet = {};
    (selectedKeys || []).forEach(function (k) { selectedSet[k] = true; });
    const $list = $('#quickLinksCustomizeList').empty();

    const ungrouped = catalog.filter(function (i) { return !i.group; });
    if (ungrouped.length) {
        const $g = $('<div class="qlc-group"></div>');
        const $items = $('<div class="qlc-group-items"></div>');
        ungrouped.forEach(function (item) { $items.append(qlGroupItemRowHtml(item, !!selectedSet[item.key])); });
        $g.append($items);
        $list.append($g);
    }
    const groupOrder = [];
    catalog.forEach(function (item) {
        if (item.group && groupOrder.indexOf(item.group) === -1) groupOrder.push(item.group);
    });
    groupOrder.forEach(function (groupKey) {
        const $g = $('<div class="qlc-group"></div>');
        $g.append(`<div class="qlc-group-title" data-i18n="${groupKey}">${escapeHtml(qlLangValue(groupKey))}</div>`);
        const $items = $('<div class="qlc-group-items"></div>');
        catalog.filter(function (i) { return i.group === groupKey; })
            .forEach(function (item) { $items.append(qlGroupItemRowHtml(item, !!selectedSet[item.key])); });
        $g.append($items);
        $list.append($g);
    });
}

function openQuickLinksCustomizeModal() {
    // Fetches a fresh catalog+selection (not just the page's own already-embedded globals) so a
    // permission change made earlier in this same session, or a selection saved from another
    // browser tab, is never shown stale inside the very modal meant to edit it.
    $.getJSON(`${BASE_URL}/api/user-preference.quick-links-get`, function (res) {
        if (!res || !res.status) return;
        quickLinksCurrentCatalog = res.data.catalog || [];
        renderQuickLinksCustomizeList(quickLinksCurrentCatalog, res.data.selected || []);
        const modalEl = document.getElementById('quickLinksCustomizeModal');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
}
$(document).on('click', '#navQuickLinksCustomizeBtn', function () {
    $('#navQuickLinksMoreMenu').removeClass('active');
    openQuickLinksCustomizeModal();
});
$(document).on('click', '#btnSaveQuickLinks', function () {
    const $btn = $(this);
    const keys = $('#quickLinksCustomizeList .qlc-checkbox:checked').map(function () { return $(this).data('key'); }).get();
    if (typeof setButtonLoading === 'function') setButtonLoading($btn, true);
    $.ajax({
        url: `${BASE_URL}/api/user-preference.quick-links-save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify({ keys: keys }),
        success: function (res) {
            if (!res || !res.status) {
                if (typeof showWarning === 'function') showWarning((res && res.message) || (langData['save_failed'] || 'An error occurred.'));
                return;
            }
            quickLinksCurrentSelected = res.data || keys;
            layoutQuickLinks();
            const modalEl = document.getElementById('quickLinksCustomizeModal');
            bootstrap.Modal.getInstance(modalEl)?.hide();
            if (typeof showSuccess === 'function') showSuccess(langData['save_success'] || 'Saved successfully.');
        },
        error: function () {
            if (typeof showWarning === 'function') showWarning(langData['save_failed'] || 'An error occurred while saving.');
        },
        complete: function () {
            if (typeof setButtonLoading === 'function') setButtonLoading($btn, false);
        }
    });
});

$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    layoutQuickLinks();
    });
});
// Image intrinsic sizes/webfont metrics can still shift .nav-left's real width slightly after
// `ready()` fires -- one more pass once everything (including images) has actually finished loading
// catches that; a resize (rotate, browser window resize, sidebar toggle changing available width on
// a narrow layout) re-runs the exact same measurement from scratch.
$(window).on('load', layoutQuickLinks);
$(window).on('resize', typeof debounce === 'function' ? debounce(layoutQuickLinks, 150) : layoutQuickLinks);
