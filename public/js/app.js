const pageLength = 50;
const lengthMenu = [[50, 100, 250, 500, 1000, -1], [50, 100, 250, 500, 1000, "All"]];
let currentLang = 'th';
let registered_country = 'TH';
let langData = {};
const langInfo = {
    en: { flag: 'gb', label: 'EN', full: 'English' },
    th: { flag: 'th', label: 'TH', full: 'ไทย' }
};
// 2026-09-02, Platform Hardening Phase 1.2, explicit request: "ปุ่ม Save...มี loading state ระหว่างส่ง
// ข้อมูล (disable + spinner) กัน double-submit". An app-wide audit found this exact
// `.prop('disabled', true).html('<spinner> Saving...')` / restore-on-complete pattern already
// hand-rolled independently in ~7 files (employee/detail.js, company-profile.js, most of
// tax-statutory.js, payroll/index.js, org-structure-sync.js, holiday-sync.js, employee-sync.js) --
// correct, but never centralized, and several OTHER Save handlers (setup-rules.js's 5 modals,
// permission-matrix.js, document-numbering.js, employment-certificate-request.js,
// manual-entry/index.js) had NO loading state at all (real double-submit risk), while a third group
// (payroll/detail.js's 12 handlers, tax-statutory.js's non-resident section,
// payroll-configuration.js's policies section, bulk-entry.js, structure-assign.js,
// payslip-request.js) disabled the button but showed no visible spinner/text change. One shared
// helper here closes both gaps at once and gives every FUTURE save handler this for free by calling
// it instead of hand-rolling the same 3 lines again.
// `$btn.data('originalHtml', ...)` is used (not a module-level variable) so this is safe to call
// concurrently for multiple different buttons on the same page without one save's restore
// clobbering another's.
// 2026-09-02, Platform Hardening Phase 1.1, explicit request: every Active/Inactive status column
// should be an instant-AJAX toggle switch with a confirm before deactivating and a success toast.
// An app-wide audit found 8 tables already had a working instant-AJAX switch (Setup & Rules'
// Shift/Holiday/Work Location/Leave Type/OT Rate Set + Payroll Configuration's PED Types) but NONE
// of them had a confirm-before-deactivate or a success toast -- each was its own hand-rolled
// duplicate (statusSwitch()/statusBadge(), 2 near-identical implementations). The BEST existing
// implementation in the whole app was actually a DIFFERENT concept -- Employment Certificate/
// Payslip Template's "Draft/Public" switch (ectPublishSwitchesHtml()/.ect-publish-switch,
// pstPublishSwitchesHtml()/.pst-publish-switch) -- confirm-only-on-the-risky-direction, a success
// toast, AND revert-on-failure (none of the 8 Active/Inactive switches had that last one either).
// This generalizes THAT pattern into one shared renderer + one shared delegated handler so every
// table (existing and future) gets the full behavior for free just by calling
// renderStatusToggleHtml() in its own column render function -- no per-table toggle-handler
// duplication needed ever again.
// Usage: `render: (d, t, row) => renderStatusToggleHtml(row.id, row.status === 'active', '/api/xxx.toggle-status')`
// then, once, wherever that table's own JS already knows how to reload it:
// `$(document).on('statusToggle:success', '#tb_xxx', function () { tb_xxx.ajax.reload(null, false); });`
// (delegated + scoped to that table's own id -- the switch renders INSIDE that table's own <td>, so
// the event naturally bubbles through it; the table element itself persists across
// `ajax.reload()`, only its rows are redrawn, so this binding survives every reload).
function renderStatusToggleHtml(id, isActive, endpoint, extraAttrs) {
    const safeId = String(endpoint).replace(/[^a-zA-Z0-9]/g, '_') + '_' + id;
    return `<div class="form-check form-switch d-flex justify-content-center m-0">
        <input class="form-check-input status-toggle-switch" type="checkbox" role="switch" id="statusToggle_${safeId}"
            data-id="${id}" data-endpoint="${endpoint}" data-current="${isActive ? 'active' : 'inactive'}" ${isActive ? 'checked' : ''} ${extraAttrs || ''}>
    </div>`;
}
$(document).on('change', '.status-toggle-switch', function () {
    const $cb = $(this);
    const id = $cb.data('id');
    const endpoint = $cb.data('endpoint');
    const current = $cb.data('current');
    const target = $cb.is(':checked') ? 'active' : 'inactive';
    const revert = function () { $cb.prop('checked', current === 'active'); };
    const doToggle = function () {
        $.ajax({
            url: `${BASE_URL}${endpoint}`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ id: id }), dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                    $cb.data('current', target).attr('data-current', target);
                    $cb.trigger('statusToggle:success', [id, target]);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                    revert();
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); revert(); }
        });
    };
    // Activating is always safe/reversible, no confirm needed -- deactivating can immediately hide
    // this row from every dropdown/picker elsewhere in the app, so it gets a confirm first, same
    // "confirm only the risky direction" rule the Draft/Public switches already established.
    if (target === 'inactive') {
        showConfirm(
            langData['confirm_deactivate_title'] || 'Deactivate this item?',
            langData['confirm_deactivate_message'] || 'It will no longer be available for selection elsewhere in the system.',
            doToggle, revert
        );
    } else {
        doToggle();
    }
});
// 2026-09-03, Platform UX review Phase 2, revised same day (see style.css's own ".om-loader"/
// ".om-page-loader" comment for the full 2-tier design rationale). Tier 1 (this function) is the
// small in-place ring -- buttons (setButtonLoading() below) and DataTables' own "processing"
// override (getTableLang() further down) both use it, deliberately the SAME small style for both
// (table reloads were explicitly asked to stay visually distinct from the Tier-2 full-page loader,
// not get a 3rd design of their own).
function originamiLoaderHtml(size) {
    const sizeClass = size === 'md' ? 'om-loader--md' : 'om-loader--sm';
    return `<span class="om-loader ${sizeClass}"></span>`;
}
// Tier 2 -- full-screen centered overlay, reserved for a page's genuine MAIN content load (NOT
// wired into setButtonLoading()/DataTables at all -- explicit request: "ไม่ต้องโหลดทุกการโหลด...เฉพาะ
// ตอนโหลดข้อมูลหน้าหลัก"). The mark itself is static (only the 2 rings around it spin, in opposite
// directions -- see the CSS), so this uses the real brand PNG directly rather than a CSS
// approximation. Idempotent -- calling showPageLoader() while one is already showing just reuses it
// (covers a caller that fires 2 fetches in parallel and calls this from both).
function showPageLoader(text) {
    if ($('#omPageLoader').length) { return; }
    const label = text || (typeof langData !== 'undefined' && langData['processing']) || 'Loading...';
    $('body').append(
        `<div id="omPageLoader" class="om-page-loader">
            <div class="om-page-loader__stage">
                <div class="om-page-loader__ring om-page-loader__ring--outer"></div>
                <div class="om-page-loader__ring om-page-loader__ring--inner"></div>
                <img class="om-page-loader__logo" src="${BASE_URL}/public/images/origami_logo.png" alt="">
            </div>
            <div class="om-page-loader__text">${label}</div>
        </div>`
    );
}
function hidePageLoader() {
    $('#omPageLoader').remove();
}
function setButtonLoading($btn, isLoading, loadingLabel) {
    if (!$btn || !$btn.length) return;
    if (isLoading) {
        if ($btn.data('originalHtml') === undefined) {
            $btn.data('originalHtml', $btn.html());
        }
        $btn.prop('disabled', true).html(`${originamiLoaderHtml('sm')}<span class="ms-2">${loadingLabel || (langData && langData['saving']) || 'Saving...'}</span>`);
    } else {
        $btn.prop('disabled', false);
        const original = $btn.data('originalHtml');
        if (original !== undefined) {
            $btn.html(original);
            $btn.removeData('originalHtml');
            if (typeof updateText === 'function') updateText($btn[0]);
        }
    }
}
// 2026-09-02, Platform Hardening Phase 1.2 -- shared "is this form dirty" helper, used both by
// page-body Cancel buttons (Employee Detail, Tax & Statutory, Payroll Configuration, Permission
// Matrix) and by the generic modal-close dirty-check guard below. Serializes every named/id'd
// input/select/textarea inside $container into one comparable string -- cheap, no deep clone, good
// enough to detect "did any field's value change" without needing per-page bespoke comparison code.
function snapshotFormState($container) {
    if (!$container || !$container.length) return '';
    const parts = [];
    $container.find('input, select, textarea').each(function () {
        const $el = $(this);
        if ($el.is(':disabled')) return;
        const key = $el.attr('name') || $el.attr('id');
        if (!key) return;
        if ($el.is(':checkbox') || $el.is(':radio')) {
            parts.push(key + '=' + ($el.val() || '') + ':' + ($el.is(':checked') ? '1' : '0'));
        } else {
            parts.push(key + '=' + ($el.val() == null ? '' : $el.val()));
        }
    });
    return parts.join('|');
}
function isFormDirty($container, baselineSnapshot) {
    if (baselineSnapshot === undefined || baselineSnapshot === null) return false;
    return snapshotFormState($container) !== baselineSnapshot;
}
// Shared confirm-if-dirty gate: if $container's current state matches baselineSnapshot, runs
// onProceed() immediately (no interruption for a form nobody actually touched); otherwise asks via
// SweetAlert2 first. Reused by every page-body Cancel button below (Employee Detail/Tax &
// Statutory/Payroll Configuration/Permission Matrix all call this the same way).
function confirmIfDirtyThen($container, baselineSnapshot, onProceed) {
    if (!isFormDirty($container, baselineSnapshot)) {
        onProceed();
        return;
    }
    showConfirm(
        (langData && langData['confirm_discard_changes_title']) || 'Discard unsaved changes?',
        (langData && langData['confirm_discard_changes_message']) || "You have changes that haven't been saved yet. If you continue, they will be lost.",
        onProceed
    );
}

// 2026-09-03, Platform Hardening Phase 1.2 -- a GENERIC modal-level dirty-check (delegated
// `shown.bs.modal`/`hide.bs.modal` handlers intercepting every Bootstrap modal app-wide, ~100 of
// them) used to live here, asking "Discard unsaved changes?" whenever a modal with an edited field
// was closed via X/Cancel/backdrop/Esc.
// 2026-09-09, explicit request: "ปิดทั้งระบบ เอา dirty-check ออกทั้งหมด" -- removed entirely, system-
// wide, after it was reported as confusing across most forms/modals in the app (explicit exception
// named: the Payslip/Employment Certificate Template canvas editors' OWN bespoke unsaved-changes
// handling -- those are standalone pages now, not modals, and were never covered by this mechanism
// anyway, only ever exempted from it by id-prefix while it still existed). The page-BODY version of
// this same idea (confirmIfDirtyThen()/snapshotFormState() above, used by explicit Cancel buttons on
// Employee Detail/Tax & Statutory/Payroll Configuration/Permission Matrix) is UNCHANGED -- this
// request was specifically about the modal-close interception, not that separate, explicit-button
// mechanism. Every Bootstrap modal in the app now closes via X/Cancel/backdrop/Esc exactly as it did
// before Phase 1.2 introduced this, with no confirm interruption.
// 2026-09-02, real bug found and fixed (explicit report: "ใน header กดที่ icon ไหนแล้วมี ui ลงมา ถ้าไปกดตัว
// อื่นตัวเดิมต้อง hide ไป ตอนนี้ขึ้นซ้อนๆกัน") -- the 4 header flyouts (notification bell, hub/switch-app,
// language, profile) each only ever toggled THEIR OWN menu, never closing the other 3 -- so opening
// a second one while a first was still open just stacked both open at once instead of replacing it.
// One shared helper, called by every flyout's own open/toggle handler (below, and notifications.js'
// own .nav-notif-btn handler) right before it toggles itself, closing every OTHER flyout first.
// `exceptId` is deliberately excluded from `.nav-lang-menu` when 'lang' (this button toggles its OWN
// sibling menu right after calling this, so leaving it alone here avoids a close-then-immediately-
// reopen no-op) and likewise for the other 3. 'notif' is a special case -- #notifMenu manages its
// OWN outside-click close in notifications.js (a guard that ignores clicks INSIDE the menu, needed
// for delegated Mark-all-read/item-click handlers to keep firing, see that file's own 2026-08-29
// docblock) -- this helper still closes it whenever a DIFFERENT flyout opens (that's a legitimate
// "something else took over" close, not the inside-click case that guard exists for).
// 2026-09-07 -- 'quicklinks' (the header Quick Links "More" dropdown, public/js/quick-links.js)
// added as a 5th flyout, same convention as the other 4.
function closeNavFlyouts(exceptId) {
    if (exceptId !== 'notif') $('#notifMenu').removeClass('active');
    if (exceptId !== 'hub') $('#hubMenu').removeClass('active');
    if (exceptId !== 'profile') $('#profileMenu').removeClass('active');
    if (exceptId !== 'lang') $('.nav-lang-menu').removeClass('active');
    if (exceptId !== 'quicklinks') $('#navQuickLinksMoreMenu').removeClass('active');
}
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
// 2026-08-31, real bug found and fixed (explicit report: "ตอน session หลุดมี alert แจ้ง error ของ
// datatable ครับ ดูเป็น Bug") -- root cause confirmed by grep: `$.fn.dataTable.ext.errMode` was
// never set anywhere in this app, which leaves DataTables on its own default, `'alert'` -- ANY ajax
// fetch failure on ANY DataTable (a 401 session-timeout response included) pops a native, unstyled
// `alert("DataTables warning: table id=... - Ajax error...")` box. This fires independently of, and
// alongside, session-guard.js's own SweetAlert2 popup (`$(document).ajaxError()` there is jQuery's
// GLOBAL hook, which still runs regardless of what DataTables' own per-call error handling does) --
// the ugly native alert was what actually got reported as "looks like a bug", not the real SweetAlert2
// popup. Set to 'none' here, in a plain `$(document).ready()` (app.js itself loads BEFORE
// dataTables.js, in header.php -- by the time `ready()` fires every synchronous script tag on the
// page, footer.php's dataTables.js included, has already run) so DataTables' own internal ajax-error
// handling goes silent everywhere, leaving session-guard.js's popup as the one and only thing the
// user ever sees for this. A DataTable's own explicit `error:` callback (if a page defines one) is
// unaffected either way -- errMode only governs the DEFAULT path when no such callback exists.
// 2026-09-09, real bug found and fixed (explicit report: same native alert plus the SweetAlert2 popup
// still both firing on every table after a session timeout, "น่าจะเป็นทุกตาราง") -- the fix above was
// applied to `$.fn.dataTable.ext.errorMode`, a property that does not exist anywhere in this
// project's installed DataTables version. Confirmed directly from the library's own source
// (`node_modules/datatables.net/js/dataTables.js`'s `_fnLog()`): it reads `ext.sErrMode || ext.errMode`
// (note: `errMode`, no "or"), so the misspelled assignment silently set an unused property while
// `errMode` stayed at its real default, `'alert'` -- the native alert never actually stopped firing,
// the 2026-08-31 fix never took effect at all. Corrected the property name; behavior/reasoning above
// is otherwise unchanged.
$(document).ready(function () {
    if (window.jQuery && $.fn.dataTable) {
        $.fn.dataTable.ext.errMode = 'none';
    }
});
// The "Auto เปลี่ยนโดยไม่ต้อง Reload หน้า" (auto-change without reloading the page) half of the same
// request -- setting the cookie only affects FUTURE requests, so an already-rendered DataTable
// wouldn't pick up the new language until its next unrelated reload (pagination, a filter change,
// etc). Reloading every currently-initialized DataTable on the page right after a language change
// makes that happen immediately instead. `$.fn.dataTable.tables({ api: true })` covers every table
// on the page in one call, so this works for any current or future page with no per-page wiring --
// a table with no `ajax` option configured (fully static data) just silently no-ops.
// 2026-08-29, real bug found and fixed (explicit report: "ในหน้าทำจ่าย เลือกเปลี่ยนภาษาแล้ว ชื่อพนักงานไม่
// เปลี่ยนตามภาษาที่เลือก ต้องการให้ได้แบบเดียวกันกับหน้า Employee") -- this used to call ONLY
// `.ajax.reload()` on every visible DataTable, which is a silent no-op on a table that was
// constructed with a plain in-memory `data:` array and no `ajax:` option at all (e.g. the Payroll
// Run Detail page's own Employee Breakdown table, `#tb_run_detail` in payroll/detail.js -- its
// name column already correctly branches on `currentLang` inside a `render` callback, same
// convention the Employee page itself uses, but that callback only ever re-runs on the NEXT
// `.draw()`, which `.ajax.reload()` never triggers for a table with nothing to fetch). Now checks
// each visible table individually: ajax-backed tables keep re-fetching fresh data as before
// (`.ajax.reload(null, false)`, so a server-rendered th/en value like a joined lookup name still
// comes back correct too); a plain client-side table instead gets `.draw(false)` -- cheap, no
// network round trip, and sufficient to re-invoke every column's own `render` function against the
// now-current `currentLang`, exactly what a `data_th`/`data_en`-branching render already expects.
// Both paths pass `false` for "don't reset pagination", same as the original behavior.
// 2026-08-30, real bug found and fixed (explicit report: "การแปลยังไม่ครบทั้งหมด ทั้งที่เป็น data table
// select2 และ html บางทีก็แปลบ้างไม่แปลบ้าง") -- `$.fn.dataTable.tables({visible:true, api:true})`
// (still correct for the common case, unchanged above) only ever redraws tables that are visible
// at the EXACT moment changeLanguage() fires -- a table sitting inside a hidden Bootstrap tab pane
// at that instant was silently skipped, with nothing anywhere in the app catching it up
// afterward. Its rows stayed rendered in whatever language was active when it was LAST drawn,
// indefinitely, however many times the page's language got switched while some other tab was
// active -- exactly the "sometimes translates, sometimes doesn't, depends which tab I was on"
// symptom reported. `langChangeEpoch` is a simple version counter stamped onto every table this
// function actually redraws; a delegated 'shown.bs.tab' handler compares each newly-visible pane's
// own tables against it and redraws any that are behind, using the exact same
// ajax-vs-client-side branch this function already uses.
let langChangeEpoch = 0;
function reloadAllTablesForLanguageChange() {
    if (typeof $.fn.dataTable === 'undefined') return;
    langChangeEpoch++;
    // 2026-08-30 (T004), real bug found and fixed: `$.fn.dataTable.tables({api:true})` (the STATIC
    // function, called with no existing Api instance) returns a single multi-table `_Api` object --
    // `.every()` is a method DataTables only registers on the result of the INSTANCE method
    // `api.tables()` (called on an Api you already have), not on this static-function return value,
    // even though both are named "tables". This call therefore threw `TypeError: ...every is not a
    // function` EVERY time this function ran, on every page, on every language switch -- silently
    // swallowed by the try/catch below (written as a defensive "no DataTables on this page" guard,
    // but it was actually catching a real, always-firing bug on every page that DOES have
    // DataTables). Confirmed empirically against this exact bundled DataTables version, not
    // guessed. Net effect before this fix: NOTHING in this function ever ran -- table columns whose
    // render function branches on `currentLang` (e.g. a `data_th`/`data_en` lookup) never actually
    // got re-evaluated on a language switch, on any page, ever. Fixed using the plain, documented
    // iteration pattern for this exact static function (see its own JSDoc example in
    // node_modules/datatables.net/js/dataTables.js): `$.fn.dataTable.tables()` (no `{api:true}`)
    // returns a plain array of `<table>` DOM nodes; `$(node).DataTable()` (no args) returns the
    // EXISTING Api instance for a table already initialized elsewhere, not a new one. Same fix
    // applied to refreshAllDataTablesLanguage() in this same file (verified independently there via
    // a real jsdom + real bundled-DataTables functional test).
    // Second real bug found and fixed in the same pass, verified via a real jsdom + real bundled-
    // DataTables functional test: for a CLIENT-SIDE table (no `ajax:`), DataTables caches each
    // row's already-rendered cell output and reuses it on a plain `.draw()` -- a render() callback
    // that branches on `currentLang` does NOT get re-invoked by `.draw()` alone, confirmed directly
    // (the rendered text stayed in the OLD language even after a successful, non-throwing
    // `.draw(false)` call). `.rows().invalidate()` clears that cache so the next `.draw()` actually
    // re-runs every column's render() fresh -- `structureTables`'s own entry in refreshAllTables()
    // already does this correctly (`.rows().invalidate().draw(false)`), this branch just never
    // matched that existing, working precedent.
    try {
        $.each($.fn.dataTable.tables({ visible: true }), function (i, node) {
            const table = $(node).DataTable();
            table.settings()[0]._langEpoch = langChangeEpoch;
            if (table.ajax.url()) {
                table.ajax.reload(null, false);
            } else {
                table.rows().invalidate().draw(false);
            }
        });
    } catch (e) { /* no DataTables on this page -- nothing to reload */ }
}
// 2026-08-30, real bug found and fixed (explicit report: "ในตอนที่กด expand ตารางเพื่อดูข้อมูลที่ซ่อน บาง
// Field ขึ้น undefined") -- confirmed against the vendored source itself
// (node_modules/datatables.net-responsive/js/dataTables.responsive.js's own `listHidden()`, the
// DEFAULT renderer every `responsive: true` table in this app uses -- none override it): its
// expand-row builder does plain string concatenation of `col.title`/`col.data` with NO
// null/undefined guard at all. Since ~40+ DataTable columns across this app are `data: null` with
// a custom `render` function (this codebase's own dominant column style, see
// table-column-filter.js's own docblock), any render function whose implicit fall-through returns
// `undefined` for some row (or a plain `data:'field'` binding to a key genuinely absent from that
// row's payload) renders as the literal text "undefined" the moment that column collapses into
// the expand row on a narrow viewport -- reproducible on the exact same data that displays fine in
// the main table, since Responsive re-fetches the identical rendered value via DataTables' own
// core `fastData()`, not a different code path. Fixed with ONE global override of the Responsive
// plugin's own default renderer (registered once, here, rather than hunting down every render
// function that can fall through to undefined across dozens of tables) -- otherwise byte-for-byte
// the same as the vendored listHidden() above, with `col.title`/`col.data` each coalesced to '' /
// '-' before concatenating.
$(document).ready(function () {
    if (typeof $.fn.dataTable === 'undefined' || !$.fn.dataTable.Responsive) return;
    // 2026-08-30, real bug found and fixed (explicit report: expand rows render empty, and a
    // long-standing "Cannot read properties of undefined (reading 's')" console error) -- this used
    // to be wrapped in an extra `function () { return function (api, rowIdx, columns) {...}; }`
    // layer, mimicking how the vendored NAMED PRESETS work (Responsive.renderer.listHidden() etc.
    // are zero-arg factories, looked up and INVOKED by the plugin only when `details.renderer` is a
    // STRING). The actual vendored call site (node_modules/datatables.net-responsive/js/
    // dataTables.responsive.js) only unwraps that way for a string value -- for a plain function (an
    // object override like this one), it's used AS-IS and called directly with (dt, rowIdx,
    // detailsObj). The outer wrapper above ignored those arguments and returned the INNER function
    // object itself, not real HTML -- that function object then got handed to the child-row display
    // machinery instead of content, producing a visibly empty expand row (and very likely the source
    // of the "reading 's'" error too, whenever something downstream tried to treat that stray
    // function as a DataTables settings-bearing context instead of markup). Fixed by assigning the
    // renderer function directly, no wrapping factory.
    $.fn.dataTable.Responsive.defaults.details.renderer = function (api, rowIdx, columns) {
        var data = $.map(columns, function (col) {
            if (!col.hidden) return '';
            var klass = col.className ? 'class="' + col.className + '"' : '';
            var title = (col.title === null || col.title === undefined) ? '' : col.title;
            var value = (col.data === null || col.data === undefined) ? '-' : col.data;
            return '<li ' + klass +
                ' data-dtr-index="' + col.columnIndex + '"' +
                ' data-dt-row="' + col.rowIndex + '"' +
                ' data-dt-column="' + col.columnIndex + '">' +
                '<span class="dtr-title">' + title + '</span> ' +
                '<span class="dtr-data">' + value + '</span>' +
                '</li>';
        }).join('');
        return data ? $('<ul data-dtr-index="' + rowIdx + '" class="dtr-details"/>').append(data) : false;
    };
});
$(document).on('shown.bs.tab', function (e) {
    // langChangeEpoch === 0 means the language has never actually been switched this session --
    // nothing could possibly be stale, so skip entirely (avoids a redundant reload/draw on every
    // single tab click in the overwhelmingly common case where a user never touches the switcher).
    if (typeof $.fn.dataTable === 'undefined' || langChangeEpoch === 0) return;
    const targetSel = $(e.target).attr('data-bs-target') || $(e.target).attr('href');
    if (!targetSel) return;
    $(targetSel).find('table.dataTable').each(function () {
        if (!$.fn.DataTable.isDataTable(this)) return;
        const dt = $(this).DataTable();
        const settings = dt.settings()[0];
        if (settings._langEpoch === langChangeEpoch) return; // already current, no-op
        settings._langEpoch = langChangeEpoch;
        if (dt.ajax.url()) {
            dt.ajax.reload(null, false);
        } else {
            // Same fix as reloadAllTablesForLanguageChange() above -- see that function's own
            // comment for why a plain .draw() alone never re-runs a client-side render() callback.
            dt.rows().invalidate().draw(false);
        }
    });
});

// 2026-08-29, explicit request: "ตอนนี้เก็บ ip location timezone อุปกรณ์ version อุปกรณ์ เบราเซอร์ ครบไหม
// ถ้ายังไม่ครบให้เก็บเพิ่มครับ" -- of that list, `timezone` is the one piece the SERVER genuinely
// cannot know from the initial login request alone (no standard HTTP header carries a browser's
// IANA timezone) -- auth/index.php's own login flow captures everything else (ip/location/device/
// os/browser) synchronously at login and stashes the new row's id in $_SESSION['login_log_id'].
// This fires once per browser session (a sessionStorage flag, not localStorage -- a genuinely NEW
// login in the same browser, e.g. a different user after a Switch-App round trip, must be able to
// record its own timezone again) on every page's first load after that, PATCHing it in via
// api/employee-login-log.record-timezone -- best-effort, silently does nothing if there's no
// active login-log session (already recorded this session, or session/route not reached yet, e.g.
// the public /auth page itself before a session exists at all).
function recordLoginTimezone() {
    if (sessionStorage.getItem('login_timezone_recorded') === '1') return;
    let timezone = '';
    try {
        timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (e) { /* Intl unsupported -- nothing to send */ }
    if (!timezone) return;
    fetch(`${BASE_URL}/api/employee-login-log.record-timezone`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ timezone: timezone }),
    }).then(res => res.json()).then(json => {
        // Only mark "recorded" on a genuine success -- if there's no active login-log session yet
        // (status:false, e.g. an edge case where this runs before auth/index.php's own redirect
        // has completed) this should keep retrying on the next page load instead of giving up
        // silently for the rest of the browser session.
        if (json && json.status) {
            sessionStorage.setItem('login_timezone_recorded', '1');
        }
    }).catch(() => { /* best-effort -- will simply retry on the next page load this session */ });
}
/**
 * 2026-08-30, real bug found and fixed: `app/services/reports/LocalizedException.php` (PHP) has
 * carried an i18n `error_key` + `params` pair for a while -- 9 of the Reports module's 10 report
 * generators already throw it -- and its own docblock has claimed since it was written that "the
 * frontend looks up langData['error_' + errorKey]... see translateApiError() in public/js/app.js".
 * That function never actually existed, AND `ReportsController`'s own catch block only ever sent
 * back `message` (the raw English fallback), never `error_key`/`params` at all -- so the entire
 * mechanism was completely inert; a Thai-locale user hitting a report validation error has always
 * seen a raw English sentence, exactly the original problem this was supposed to fix. Both halves
 * fixed together: the controller now forwards `error_key`/`params`, and this is the actual lookup.
 * `{param}` placeholders are substituted from `params` -- a value that's an array is treated as a
 * list of `payroll_runs.state` codes and translated per-element via the existing `state_{code}` lang
 * keys before joining (same convention the PHP class's own docblock already promised), every other
 * param type substituted as a plain string. Falls back to the raw English `message` when
 * `error_key` is absent or the lang key doesn't exist yet (a new error_key added later degrades
 * gracefully instead of showing "undefined").
 */
function translateApiError(data) {
    if (!data || !data.error_key) {
        return (data && data.message) || null;
    }
    const template = langData['error_' + data.error_key];
    if (!template) {
        return data.message || null;
    }
    let text = template;
    const params = data.params || {};
    Object.keys(params).forEach(key => {
        const value = params[key];
        let substituted;
        if (Array.isArray(value)) {
            substituted = value.map(code => langData['state_' + code] || code).join(', ');
        } else if (key === 'state' || key === 'current_state') {
            // payroll_runs.state codes ('draft'/'approved'/...) also show up as a lone scalar
            // param (e.g. run_state_invalid's own `current_state`), not just inside a `states`
            // array -- same state_{code} lang-key translation either way.
            substituted = langData['state_' + value] || value;
        } else {
            substituted = value;
        }
        text = text.split('{' + key + '}').join(substituted);
    });
    return text;
}
/** 2026-08-29: moved here from public/js/reports/index.js (unchanged) so any page can trigger a
 *  report download through the existing GET /api/report.generate endpoint -- originally only the
 *  Reports page itself loaded that file, but the Payroll Process List/Detail pages' own "print"
 *  shortcuts (SSO/RD/Bank Transfer for a specific run) need the exact same fetch+blob+download
 *  flow without pulling in the rest of reports/index.js's page-specific state. */
// 2026-08-29, same-day follow-up: the new "Reports" tab's own download-count/last-downloaded
// columns need to refresh right after a real download completes -- `onSuccess` is optional
// (existing callers that don't pass it are unaffected) and only fires once the download actually
// went through, not on a JSON error response.
function generateReport(url, onSuccess) {
    fetch(url, { method: 'GET' })
        .then(async res => {
            const contentType = res.headers.get('Content-Type') || '';
            if (contentType.indexOf('application/json') !== -1) {
                const data = await res.json();
                showWarning(translateApiError(data) || langData['generate_failed'] || 'Failed to generate the report.');
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
            // 2026-08-29, explicit request: "ตอนกดออก Report สำเร็จ ให้ alert ปิดเองอัตโนมัติ" -- 1.5s,
            // enough to register "it worked" without needing a click, same spirit as the file
            // download itself already happening with no further action needed.
            showSuccess(langData['generate_success'] || 'Report generated successfully.', true, 1500);
            if (typeof onSuccess === 'function') onSuccess();
        })
        .catch(function () {
            showWarning(langData['generate_failed'] || 'Failed to generate the report.');
        });
}
$(document).ready(async function() {
    currentLang = localStorage.getItem('preferred_language') || 'en';
    syncLangCookie(currentLang);
    // window.langReady: exposes this in-flight promise so other pages can defer initial render until langData is populated (never rejects).
    window.langReady = loadLang(currentLang);
    await window.langReady;
    buildLanguageMenu();
    // 2026-08-29, explicit request: per-user Font Size, applied from localStorage immediately (no
    // network round trip needed for first paint) -- reconciled against the server-saved value
    // (which wins if different, e.g. on a brand-new device/browser) by loadUserPreferences() below,
    // same "fast local default, then server reconciles" pattern the language switcher already used.
    applyFontSize(localStorage.getItem('preferred_font_size') || 'm');
    // 2026-09-04, T069 Step 1 -- deliberately NOT mirroring the applyFontSize() line just above with
    // an equivalent applyTheme(localStorage...) call here, even though it looks like the same
    // pattern. Theme (unlike font size) is ALREADY correctly stamped server-side on <html> by
    // header.php before this script ever runs (that's the whole point of doing it server-side --
    // no flash, no JS needed for a correct FIRST paint). Blindly re-applying a possibly-stale
    // localStorage value here (e.g. a shared browser last used by a different employee, or
    // localStorage cleared independently of the session) would OVERWRITE that correct server value
    // with a wrong one for a moment, which is exactly the flash-of-wrong-theme bug this whole step
    // exists to prevent. loadUserPreferences() below still reconciles localStorage to match the
    // server (fixing any staleness for NEXT time) -- it just doesn't need to touch the DOM to do it
    // in the normal case, since the SSR-stamped attribute is already correct.
    loadUserPreferences();
    recordLoginTimezone();
    // 2026-08-30, explicit request: "Design การเปลี่ยนภาษาใน modal ให้เป็น design เดียวกับ header" -- was
    // a single non-delegated .on('click') bound only to the ONE nav button that existed at page
    // load, toggling the ONE #languageMenu by id. Delegated ($(document).on(...)) so it also covers
    // every .nav-lang-btn a modal gets (see the show.bs.modal handler below, which injects the
    // exact same .nav-lang-dropdown/.nav-lang-btn/.nav-lang-menu markup this nav button already
    // uses) -- and scoped to THIS button's own sibling menu (id can't repeat across N open modals +
    // the nav itself, so every instance now uses a shared CLASS instead).
    $(document).on('click', '.nav-lang-btn', function(e) {
        e.stopPropagation();
        const $menu = $(this).siblings('.nav-lang-menu');
        const willOpen = !$menu.hasClass('active');
        closeNavFlyouts('lang');
        $menu.toggleClass('active', willOpen);
    });
    $(document).on('click', '.dropdown-lang-item', async function(e) {
        e.preventDefault();
        const selectedValue = $(this).data('value');
        await changeLanguage(selectedValue);
        $('.nav-lang-menu').removeClass('active');
    });
    $('.nav-hub-btn').on('click', function(e) {
        e.stopPropagation();
        const willOpen = !$('#hubMenu').hasClass('active');
        closeNavFlyouts('hub');
        $('#hubMenu').toggleClass('active', willOpen);
    });
    $('.nav-profile-btn').on('click', function(e) {
        e.stopPropagation();
        const willOpen = !$('#profileMenu').hasClass('active');
        closeNavFlyouts('profile');
        $('#profileMenu').toggleClass('active', willOpen);
    });
    $(document).on('click', function() {
        // 'notif' is deliberately excluded here -- #notifMenu manages its own outside-click close
        // in notifications.js (a smarter guard that ignores clicks INSIDE the menu, needed so
        // Mark-all-read/item-click delegated handlers keep firing, see that file's own 2026-08-29
        // docblock). Closing it unconditionally on every document click here would reintroduce that
        // exact bug. closeNavFlyouts('hub'/'lang'/'profile' calls above already close notif whenever
        // one of THOSE flyouts is opened instead, via this same shared helper.
        closeNavFlyouts('notif');
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
// 2026-09-10, Batch 3A item 1 (explicit report: "Dropdown ใน DataTable โดนตัดเมื่อแถวน้อย" -- same
// root cause already found and fixed per-table before this (reports/index.js's own tb_cycle_matrix,
// payroll/detail.js's own tb_run_detail): a `.dropdown-toggle` inside `.table-responsive`/
// `.dataTables_wrapper` (overflow-x:auto, which forces overflow-y:auto too per the CSS spec) gets
// clipped by that scrolling ancestor under Bootstrap's default Popper `absolute` strategy;
// `strategy:'fixed'` positions relative to the viewport instead, never clipped by an ancestor's
// overflow. Fixed HERE, globally, instead of per-table -- `draw.dt` fires for EVERY DataTable on
// every redraw (delegated at the document level, scoped per-call to the table that actually just
// drew via `e.target`), so a table with a dropdown never needs its own drawCallback for this again.
// The 2 pre-existing per-table drawCallback copies of this exact fix (tb_cycle_matrix/tb_run_detail)
// were removed in favor of this one shared function (see CLAUDE.md's "generalize instead of
// mirror-copy" rule) -- see this session's own grep/report for the full list of tables this covers.
function applyFixedStrategyToTableDropdowns(root) {
    $(root || document).find('.dropdown-toggle[data-bs-toggle="dropdown"]').each(function () {
        if (!$(this).closest('.dataTables_wrapper, .table-responsive').length) return;
        bootstrap.Dropdown.getOrCreateInstance(this, {
            popperConfig: (defaultConfig) => Object.assign({}, defaultConfig, { strategy: 'fixed' })
        });
    });
}
$(document).on('draw.dt', function (e) {
    applyFixedStrategyToTableDropdowns(e.target);
});
$(document).ready(function () {
    applyFixedStrategyToTableDropdowns(document);
});
function getTableLang() {
    return {
        search: langData.search || "Search",
        lengthMenu: langData.lengthMenu || "Show _MENU_ entries",
        zeroRecords: langData.zeroRecords || "No matching records found",
        info: langData.info || "Showing _START_ to _END_ of _TOTAL_ entries",
        infoEmpty: langData.infoEmpty || "Showing 0 to 0 of 0 entries",
        infoFiltered: langData.infoFiltered || "(filtered from _MAX_ total entries)",
        // 2026-09-08, explicit request: "Design Loading ของ Datatable อยากให้ปรับใหม่...เป็นแบบเดิมก็ได้
        // Default ของ Datatable แต่เปลี่ยนเป็นสีส้ม" -- the 2026-09-03/2026-09-07 custom card+origami-
        // mark design (both retired here) kept running into positioning/clipping fights with this
        // app's own scrolling table wrappers (sticky columns, drag-scroll) -- exactly the "ซ่อนไปใน
        // Datatable" (hidden inside the table) complaint this fixes. No `processing` override here
        // at all anymore -- DataTables' own bs5 renderer already gives a clean, correctly-centered
        // `.dt-processing.card` box (white surface, border, shadow) with its native 4-dot bounce
        // animation, sized/positioned exactly the way the library itself expects, which is the
        // "Default ของ Datatable" the request asked to keep -- only the dots' own color is
        // recolored to brand orange, scoped narrowly via a CSS custom-property override on
        // `div.dt-processing` itself (see style.css), not a global one.
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
    persistUserPreferences(lang, localStorage.getItem('preferred_font_size') || 'm', localStorage.getItem('preferred_theme') || 'light');
    await loadLang(lang);
    reloadAllTablesForLanguageChange();
    // Dashboard's greeting title/description are JS-templated (employee name + today's date
    // interpolated into a langData string) and no longer carry data-i18n for exactly that reason
    // -- only defined when dashboard.js is loaded (dashboard page only), so this is a no-op
    // everywhere else. See dashboard.js's own renderDashboard() docblock.
    if (typeof loadDashboardSummary === 'function') loadDashboardSummary();
    // 2026-08-30, same pattern: the Employee Sync modal's filter dropdowns are plain <option> tags
    // rendered once from a fetched response, with no data-i18n path -- only defined when
    // employee-sync.js is loaded (Employee List page only). See that file's own docblock.
    if (typeof esRefreshFilterOptionsLanguage === 'function') esRefreshFilterOptionsLanguage();
    // 2026-08-30 (T007), same pattern: the Earning/Deduction modal's title depends on which action
    // opened it (add/edit/view) + the item type, so it can't carry a plain data-i18n -- only defined
    // when employee/detail.js is loaded (Employee Detail page only).
    if (typeof refreshEedModalTitleLanguage === 'function') refreshEedModalTitleLanguage();
    // 2026-08-30 (T007), same pattern: the Organizational Structure Sync/Sync Log modal titles
    // interpolate the entity type -- only defined when org-structure-sync.js is loaded (Organizational
    // Structure tab of Company Profile only).
    if (typeof orgSyncRefreshModalTitleLanguage === 'function') orgSyncRefreshModalTitleLanguage();
    // 2026-08-30 (T017b, leftover from T008's modal audit -- the Earning/Deduction TYPE item modal,
    // #itemModal, was touched extensively for T013b/T014 this same day): its title badge
    // (#pedTypeModalBadge, "Income"/"Deduction") is plain JS-set text with no data-i18n, same shape
    // as the T007 fixes above -- only defined when payroll-configuration.js is loaded.
    if (typeof refreshPedTypeModalBadgeLanguage === 'function') refreshPedTypeModalBadgeLanguage();
    // 2026-09-05, Backlog Phase 13 -- same "only defined when that page's own script is loaded"
    // pattern as every hook above. Setup Guide/Version render th/en text server-fetched into plain
    // divs (no DataTable, so reloadAllTablesForLanguageChange() above doesn't cover them) and the
    // Help Drawer's own currently-open content needs the same re-fetch.
    if (typeof sgRefreshChecklistLanguage === 'function') sgRefreshChecklistLanguage();
    if (typeof changelogRefreshLanguage === 'function') changelogRefreshLanguage();
    if (typeof helpDrawerRefreshLanguage === 'function') helpDrawerRefreshLanguage();
    // 2026-09-09, same pattern: Terms and Conditions modal content (#termsModalContent) is plain
    // server-fetched HTML with no data-i18n, so applyLanguage() above never touches it -- see
    // terms-and-conditions.js's own docblock on termsRefreshLanguage() for the real bug this fixes.
    if (typeof termsRefreshLanguage === 'function') termsRefreshLanguage();
}
// 2026-08-29, explicit request: per-user Font Size (S/M/L) + Language, persisted server-side (see
// UserPreferenceModel's own docblock) -- FONT_SIZE_STEPS maps the Settings modal's 0-2 slider
// position to the 3 saved values; `html[data-font-size]` drives the actual CSS scaling (see
// style.css's own comment on the `html, body { font-size: 12px }` rule this overrides).
const FONT_SIZE_STEPS = ['s', 'm', 'l'];
function applyFontSize(size) {
    document.documentElement.setAttribute('data-font-size', FONT_SIZE_STEPS.includes(size) ? size : 'm');
}
// 2026-09-04, Backlog Phase 11, T069 Step 1 -- 'light'/'dark' stamp the SAME data-bs-theme
// attribute header.php already stamps server-side (see that file's own comment -- this is the
// CLIENT-SIDE mirror of the exact same 2-selector logic, kept in sync deliberately: whichever one
// runs last always wins, and both always agree because both read from the same source of truth,
// just at different points in the page lifecycle -- header.php at initial render from the session,
// this function at live-preview/save time in the browser). 'system' (or anything else) REMOVES the
// attribute entirely rather than setting it to some 3rd value -- with no attribute present, the
// @media(prefers-color-scheme) block in style.css is the only rule left standing, which is exactly
// "follow the OS" (see style.css's own :root:not([data-bs-theme="light"]) guard for why an absent
// attribute, not a 'system' value, is what makes that selector fire).
function applyTheme(theme) {
    if (theme === 'dark' || theme === 'light') {
        document.documentElement.setAttribute('data-bs-theme', theme);
    } else {
        document.documentElement.removeAttribute('data-bs-theme');
    }
}
// Always sends ALL THREE values together, never just the one that changed -- UserPreferenceModel::save()
// is a full replace of all 3 columns per call, so persisting only `language` (leaving `ui_font_size`/
// `ui_theme` undefined -> the controller's own defaults) would silently reset a user's saved font
// size/theme back to Medium/System the next time they merely switched language. Every call site
// above/below reads the OTHER values fresh from localStorage first for exactly this reason.
// `theme` is 'light'/'dark'/'system' on the JS side (matches the 3 buttons in the Settings modal).
// 2026-09-05 -- 'system' is now sent to the server AS 'system', a real literal value, not collapsed
// to '' anymore (see UserPreferenceModel's own docblock for why null/'' was overloaded to mean
// "explicitly follow the OS" too, which was the actual bug: it made that the DEFAULT for anyone who
// had never touched Settings at all, not just for someone who deliberately picked System).
async function persistUserPreferences(language, fontSize, theme) {
    try {
        await fetch(`${BASE_URL}/api/user-preference.save`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ui_language: language, ui_font_size: fontSize, ui_theme: theme }),
        });
    } catch (e) { /* best-effort -- localStorage already has both values as a fallback */ }
}
// Reconciles this device's local defaults against whatever was last saved server-side -- the
// server wins when it differs (e.g. a brand-new browser/device with empty localStorage, or the
// user changed a preference somewhere else since), so switching devices/browsers now actually
// carries the preference over instead of always falling back to English/Medium/System.
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
        // 2026-09-05 -- server's null (never configured) now maps to 'light', NOT 'system' (real bug
        // fix: the old mapping made "follow the OS" the default for every never-configured employee,
        // not just for someone who explicitly picked System -- see UserPreferenceModel's own
        // docblock). 'system' from the server is now a real explicit choice, passed through as-is.
        const savedTheme = (pref.ui_theme === 'dark' || pref.ui_theme === 'light' || pref.ui_theme === 'system') ? pref.ui_theme : 'light';
        if (savedTheme !== (localStorage.getItem('preferred_theme') || 'light')) {
            localStorage.setItem('preferred_theme', savedTheme);
            applyTheme(savedTheme);
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
// 2026-09-04, T069 Step 1 -- same live-preview/revert-on-close-unless-saved discipline as
// userSettingsOriginalFontSize immediately above, mirrored for theme.
let userSettingsOriginalTheme = 'system';
let userSettingsJustSaved = false;
// Delegated via $(document).on(event, selector, fn) rather than $('#userSettingsModal').on(...) --
// this script tag loads near the very top of <body>, before the modal markup further down the
// page has been parsed, so a direct element lookup here would silently bind to nothing (real bug
// caught before shipping, not guessed -- same class of gotcha this file's own $(document).ready()
// block already exists to avoid for everything inside it, but these 2 lines were originally written
// outside that block). Bootstrap's own modal events bubble up to document just like a native DOM
// event, so delegation works identically to direct binding once the element does exist.
// 2026-08-29, explicit follow-up request: "ทำ Notification Settings ก่อนเลยครับ -- ให้ user เลือกเปิด/ปิด
// รับแจ้งเตือนได้เป็นราย category (5 ประเภทที่มีอยู่)" -- own section inside this same modal, loaded
// fresh every time it opens (same "always pull fresh" convention as everything else in this app),
// not persisted to localStorage the way font size is (server is the only source of truth here,
// since it's also affected by the role-level default an admin can set elsewhere).
function userSettingsNotifPrefItemHtml(pref) {
    const label = currentLang === 'th' ? (pref.label_th || pref.label_en) : (pref.label_en || pref.label_th);
    return `<div class="form-check form-switch">
        <input class="form-check-input user-settings-notif-pref-check" type="checkbox" data-type="${$('<div>').text(pref.type).html()}" id="notifPref_${pref.type}" ${pref.enabled ? 'checked' : ''}>
        <label class="form-check-label" for="notifPref_${pref.type}">${$('<div>').text(label).html()}</label>
    </div>`;
}
function loadUserSettingsNotifPrefs() {
    $('#userSettingsNotifPrefsList').html(`<div class="text-center text-muted py-2"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
    $.getJSON(`${BASE_URL}/api/notification.preferences-get`, function (res) {
        if (!res.status) { $('#userSettingsNotifPrefsList').html(''); return; }
        $('#userSettingsNotifPrefsList').html((res.data || []).map(userSettingsNotifPrefItemHtml).join(''));
    }).fail(function () { $('#userSettingsNotifPrefsList').html(''); });
}
function saveUserSettingsNotifPrefs() {
    const preferences = $('.user-settings-notif-pref-check').map(function () {
        return { type: $(this).data('type'), enabled: this.checked };
    }).get();
    $.ajax({
        url: `${BASE_URL}/api/notification.preferences-save`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ preferences }), dataType: 'json',
    });
}
// 2026-09-04, T069 Step 1 -- reflects which of the 3 theme buttons is "selected" via an .active
// class (the buttons are plain <button>s, not a radio group, since there's no native HTML control
// shaped like a labeled icon-button row -- .active is this control's own equivalent of :checked).
function setActiveThemeOption(theme) {
    $('.user-settings-theme-option').removeClass('active');
    $(`.user-settings-theme-option[data-theme-option="${theme}"]`).addClass('active');
}
$(document).on('show.bs.modal', '#userSettingsModal', function () {
    userSettingsJustSaved = false;
    const current = localStorage.getItem('preferred_font_size') || 'm';
    userSettingsOriginalFontSize = current;
    const idx = FONT_SIZE_STEPS.indexOf(current);
    $('#userSettingsFontSizeSlider').val(idx >= 0 ? idx : 1);
    const currentTheme = localStorage.getItem('preferred_theme') || 'light';
    userSettingsOriginalTheme = currentTheme;
    setActiveThemeOption(currentTheme);
    loadUserSettingsNotifPrefs();
});
$(document).on('hidden.bs.modal', '#userSettingsModal', function () {
    if (!userSettingsJustSaved) {
        applyFontSize(userSettingsOriginalFontSize);
        applyTheme(userSettingsOriginalTheme);
    }
});
$(document).on('input', '#userSettingsFontSizeSlider', function () {
    applyFontSize(FONT_SIZE_STEPS[Number($(this).val())] || 'm');
});
$(document).on('click', '.user-settings-theme-option', function () {
    const theme = $(this).data('theme-option');
    setActiveThemeOption(theme);
    applyTheme(theme);
});
// 2026-08-29, same-day follow-up: "ตัวเปลี่ยนภาษาตัดออกจากใน modal setting ครับ เพราะมีใน header อยู่
// แล้ว" -- the language picker that used to live in this modal (.user-settings-lang-option click
// handler) was removed; the top-right nav-lang-dropdown switcher (.dropdown-lang-item, above) is
// the only language control now. Save below still sends `currentLang` alongside the font size/theme --
// UserPreferenceModel::save() persists all 3 columns together on every call (see its own
// docblock), so this Save button still correctly keeps whatever language is currently active,
// it just never CHANGES it anymore.
$(document).on('click', '#btnSaveUserSettings', function () {
    const size = FONT_SIZE_STEPS[Number($('#userSettingsFontSizeSlider').val())] || 'm';
    localStorage.setItem('preferred_font_size', size);
    applyFontSize(size);
    // 2026-09-04, T069 Step 1 -- reads the .active button rather than a separate tracked variable,
    // same source-of-truth-is-the-DOM approach the font-size slider's own $(this).val() uses.
    const theme = $('.user-settings-theme-option.active').data('theme-option') || 'light';
    localStorage.setItem('preferred_theme', theme);
    applyTheme(theme);
    persistUserPreferences(currentLang, size, theme);
    saveUserSettingsNotifPrefs();
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
// 2026-09-03, Platform UX review Phase 3 (explicit request: every page currently shares ONE
// hardcoded <title> -- "Payroll • ORIGAMI PLATFORM" -- browser tabs are indistinguishable). Confirmed
// via AskUserQuestion: suffix is "Origami Payroll". Derives the title from the SAME breadcrumb
// markup every page already renders (`.bc-parent` -- 0 or 1 per page, confirmed by scanning every
// view -- then `.bc-current`) instead of hand-writing 26+ separate title strings that could drift
// out of sync with the breadcrumb itself -- one source of truth, and it's already localized via the
// same data-i18n mechanism updateText() below applies. `.bc-current` starts as a literal "-"
// placeholder on detail pages (payroll run/employee/etc. -- see payroll/detail.php's own markup)
// until an async fetch fills in the real name; skipped here as "not a real value yet" rather than
// shipping a title like "Payroll Process — - | Origami Payroll" during that flash.
function updateDocumentTitleFromBreadcrumb() {
    const parts = [];
    $('.payroll-breadcrumb .bc-parent').each(function () {
        const t = $(this).text().trim();
        if (t) parts.push(t);
    });
    const currentText = $('.payroll-breadcrumb .bc-current').first().text().trim();
    if (currentText && currentText !== '-') parts.push(currentText);
    document.title = parts.length ? `${parts.join(' — ')} | Origami Payroll` : 'Origami Payroll';
}
// Covers pages where `.bc-current`'s real value only appears after an async fetch (e.g.
// payroll/detail.js's renderRunHeader() setting #bcRunName once the run loads) -- fires the same
// derivation above automatically whenever that text actually changes, instead of requiring every
// such page to remember to call it manually. One observer, delegated at the document level, set up
// once on first load (harmless no-op if `.payroll-breadcrumb` doesn't exist on a page, e.g.
// error404.php/permission.php).
$(function () {
    const breadcrumbEl = document.querySelector('.payroll-breadcrumb');
    if (breadcrumbEl && typeof MutationObserver !== 'undefined') {
        new MutationObserver(updateDocumentTitleFromBreadcrumb).observe(breadcrumbEl, { characterData: true, childList: true, subtree: true });
    }
});
// 2026-09-03, Manual Entry / Platform UX review Phase 5 (fee currency), Option A -- fills every
// `.currency-code-label` span (input-group badge next to a monetary amount field) with the
// company's own COMPANY_CURRENCY_CODE (see header.php's own docblock on that global). Deliberately
// NOT driven by updateText()/data-i18n -- a currency CODE isn't translated text, it's live company
// data, so these spans carry no data-i18n attribute (a static "THB" fallback only, for the brief
// window before this runs). Called from applyLanguage() (already re-run on every page load, language
// switch, and dynamically-swapped `root` content -- e.g. Employee Detail's tab panes, Payroll
// Configuration's modals -- so a badge inside markup injected after initial page load still gets
// filled without any extra per-page wiring), not a route/page-specific init, so a currency-labeled
// field added anywhere in the future needs zero JS changes beyond adding the span itself:
// `<span class="input-group-text currency-code-label">THB</span>`.
// 2026-09-03, Manual Entry / Platform UX review Phase 7 -- confirmed via AskUserQuestion: "default
// to first account" means the "Select a Saved Destination" dropdown shown for payee_type=
// 'other_person' (#eed_destination_select and its 3 siblings -- #erd_destination_select,
// #manualLineDestinationSelect, #recurringDestDestinationSelect -- see each caller's own
// setXxxPayeeType() toggle function). When that dropdown becomes visible with nothing chosen yet,
// pre-select the first saved destination (alphabetical by account_name, the same order
// PaymentDestinationModel::listSaved() already returns) instead of leaving it empty -- same
// "suggest a sensible starting choice instead of an empty required field" convenience already
// applied to Payroll Cycle's own default bank account. Guarded against a real async race with the
// caller's own populate-from-existing-record code (which runs synchronously right after the toggle
// function this is called from, and always wins if it sets a real value first) by re-checking the
// select is STILL empty at ajax-response time before applying -- an edit-mode record that already
// has a real destination is never overwritten. A brand new company with zero saved destinations
// yet is a normal no-op (nothing to default to).
function applyFirstSavedDestinationDefault(selectId, newFieldsWrapperId) {
    const $select = $(selectId);
    if (!$select.length || $select.val()) return;
    $.post(`${BASE_URL}/api/payment-destination.options`, { searchTerm: '', limit: 1 }, function (res) {
        if ($select.val()) return;
        const item = res && res.status && res.data && res.data.items && res.data.items[0];
        if (!item) return;
        const text = (typeof currentLang !== 'undefined' && currentLang === 'th') ? item.text_th : item.text_en;
        const opt = new Option(text, item.id, true, true);
        $select.empty().append(opt).trigger('change');
        if (newFieldsWrapperId) {
            $(newFieldsWrapperId).addClass('d-none');
        }
    }, 'json');
}
function applyCurrencyLabel(root = document) {
    const code = (typeof COMPANY_CURRENCY_CODE !== 'undefined' && COMPANY_CURRENCY_CODE) ? COMPANY_CURRENCY_CODE : 'THB';
    $(root).find('.currency-code-label').text(code);
}
function applyLanguage(lang, root = document) {
    updateText(root);
    updateDocumentTitleFromBreadcrumb();
    applyCurrencyLabel(root);

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
// 2026-08-30: `$scope` lets a caller populate just ONE freshly-injected modal's own
// `.nav-lang-menu` (see the show.bs.modal handler below) without re-touching the nav's own
// already-built menu -- defaults to every `.nav-lang-menu` on the page (the original, whole-page
// call site further down still works unchanged).
function buildLanguageMenu($scope) {
    const langs = ['en', 'th'];
    const $menus = $scope ? $scope.find('.nav-lang-menu') : $('.nav-lang-menu');
    $menus.each(function () {
        const menu = $(this).empty();
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
    });
}
// 2026-08-30, explicit bug report: "ทุก modal ที่เปิด จะต้องมี header และ footer เสมอ footer มีปุ่มปิด
// เป็น Default และมุมซ้ายสุดของ header ให้เป็นปุ่มเปลี่ยนภาษา เพราะตอนนี้ปัญหาคือพอมีการเปิด modal จะกลับไป
// เปลี่ยนภาษาไม่ได้" -- root cause confirmed by reading the markup: the top nav's own language
// switcher (.nav-lang-dropdown) sits in the page header, and Bootstrap's modal backdrop (higher
// z-index, by design) sits above it, so it becomes genuinely unclickable the moment ANY modal is
// open -- not a CSS mistake to fix, backdrops are supposed to block the page behind them. The fix
// has to put a language control INSIDE the modal itself.
//
// Applied GENERICALLY on every modal's own 'show.bs.modal' event, rather than hand-editing every
// modal's markup across the whole app (there are far too many, and any modal added later would
// need the same treatment) -- this is the one place that guarantees the invariant everywhere,
// including modals written after this comment. Idempotent (checks for its own marker classes
// before injecting) so it's safe to fire on every single modal open, repeatedly.
//
// 2026-08-30, same-day follow-up (explicit request: "Design การเปลี่ยนภาษาใน modal ให้เป็น design เดียวกับ
// header และถ้าเลือกเปลี่ยนแล้วให้ผูกไปถึง header และการแปลในหน้าหลักด้วย") -- was a simplified single-click
// toggle button (swap directly to the other language, no menu); now the EXACT same
// .nav-lang-dropdown/.nav-lang-btn/.nav-lang-menu markup the header's own switcher uses, injected
// fresh per modal. Every instance shares the SAME .dropdown-lang-item click handler (already
// delegated, see above) that already calls the one global changeLanguage() -- which was already
// reaching every open element via loadLang()'s own `$('.text-current-lang')`/`$('.current-flag')`
// class-based updates (not id-based), so "changing in the modal also updates the header and the
// page behind it" was already true the moment this reused those same classes -- no extra binding
// needed for that half of the request, only the visual redesign to match.
function modalLangDropdownHtml() {
    const info = langInfo[currentLang] || langInfo.en;
    return `<div class="nav-lang-dropdown modal-lang-dropdown">
        <button class="nav-lang-btn" type="button" title="${(langData && langData['switch_language']) || 'Switch language'}">
            <img class="current-flag" src="${BASE_URL}/public/flags/${info.flag}.png" width="15" alt="">
            <span class="lang-text text-current-lang">${info.label}</span>
        </button>
        <ul class="nav-lang-menu"></ul>
    </div>`;
}
// 2026-09-10, real bug fix (explicit report: modal แบบฟอร์มทุกตัว (เช่น เงินได้/#eedModal,
// สร้างรอบ/#payrollRunModal) render footer 2 ชั้นซ้อนกัน) -- root cause was THIS handler's own
// footer-detection selector, `.find('> .modal-footer')` (direct-child of .modal-content only).
// Many of this app's own form modals wrap `.modal-body`+`.modal-footer` inside a `<form>` element
// (e.g. `<div class="modal-content"><div class="modal-header">...</div><form>...<div
// class="modal-footer">Cancel/Save</div></form></div>`), which makes their own `.modal-footer` a
// GRANDCHILD of `.modal-content`, not a direct child -- the old selector never found it, so this
// handler always concluded "this modal has no footer at all" and appended a brand-new EMPTY
// `.modal-footer` div straight onto `.modal-content` (a sibling after the `<form>`), then injected
// a generic "Close" button into that new one -- stacking a second footer bar underneath the form's
// own real Cancel/Save footer that was there the whole time.
// Fix has 2 parts, confirmed with the user rather than guessed:
//   1. `.find('.modal-footer')` (no `>`) finds an existing footer regardless of nesting depth.
//   2. Once ANY `.modal-footer` is found anywhere in the modal, this NEVER injects anything into
//      or alongside it -- not even a dismiss-button-presence check (the old code's OWN guard, which
//      is what let it "safely" auto-add a Close button into a footer it treated as brand new) --
//      because several modals in this app close via a plain onclick handler that calls
//      `.modal('hide')` directly instead of the `data-bs-dismiss` HTML attribute, and a presence
//      check keyed on that attribute alone would have kept re-injecting a redundant Close button
//      into those every time this fires. A brand-new default footer is only ever created when NO
//      `.modal-footer` element exists anywhere in the modal at all, AND the modal is the default
//      `data-footer="view"` type (see modalFooterTypeOf() below) -- a modal explicitly marked
//      form/confirm/none is expected to bring its own controls (or none at all for "none"), so a
//      genuinely missing footer there is left alone rather than papered over with a generic button.
function modalFooterTypeOf($modal) {
    const type = String($modal.data('footer') || '').trim();
    return ['form', 'view', 'confirm', 'none'].indexOf(type) !== -1 ? type : 'view';
}
$(document).on('show.bs.modal', '.modal', function () {
    const $modal = $(this);
    const $content = $modal.find('> .modal-dialog > .modal-content').first();
    if (!$content.length) return;

    let $header = $content.find('> .modal-header').first();
    if (!$header.length) {
        $header = $('<div class="modal-header"></div>').prependTo($content);
    }
    if (!$header.find('.modal-lang-dropdown').length) {
        const $dropdown = $(modalLangDropdownHtml()).prependTo($header);
        buildLanguageMenu($dropdown);
    }

    const $existingFooter = $content.find('.modal-footer').first();
    if (!$existingFooter.length && modalFooterTypeOf($modal) === 'view') {
        // langData reflects whichever language is currently active at the moment this modal opens
        // (not hardcoded English) -- same `langData['close']` key every other Close button in this
        // app already uses, falling back to the English literal only if the key itself is missing.
        $('<div class="modal-footer"></div>')
            .append(`<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">${(langData && langData['close']) || 'Close'}</button>`)
            .appendTo($content);
    }
});
// 2026-09-10, real bug found and fixed (explicit report: raw action codes like "employee_verified"
// showing in Payroll Process's own Approval Timeline modal "History" list) -- was 3 separate, drifted
// copies of this same lookup (detail.js/index.js/approval.js each had their own, none with the same
// key set -- approval.js's own copy even mapped 'lock' to the OLD "Lock" wording where the other 2
// already use "Verify", a real cross-page wording mismatch). One shared table here instead, covering
// every action code PayrollRunModel::logAudit() can actually write (grepped from every call site, not
// just what happened to already be on screen). Unknown code -> raw fallback + console.warn so a
// future new action code fails loudly during dev instead of silently showing a raw key in prod.
const AUDIT_ACTION_LABEL_KEYS = {
    create: 'action_create', update: 'action_edit', recalculate: 'action_recalculate',
    view_detail: 'action_view_detail', submit: 'action_submit', revert: 'action_revert',
    approve: 'action_approve', approve_step: 'action_approve_step',
    reject: 'action_reject', reject_step: 'action_reject_step',
    reviseAfterReject: 'action_revise', reviseAfterNeedInfo: 'action_revise',
    request_info: 'action_request_info', markPaid: 'action_mark_paid',
    lock: 'action_verify_run', reopen: 'action_reopen',
    delete: 'action_delete', cancel: 'action_cancel',
    add_manual_line: 'action_add_manual_line', remove_manual_line: 'action_remove_manual_line',
    merge_supplemental: 'action_merge_supplemental', merge_run: 'action_merge_run',
    line_override_save: 'action_line_override_save', line_override_remove: 'action_line_override_remove',
    recurring_deduction_destination_override_save: 'action_recurring_deduction_destination_override_save',
    recurring_deduction_destination_override_remove: 'action_recurring_deduction_destination_override_remove',
    attendance_override_save: 'action_attendance_override_save', attendance_override_remove: 'action_attendance_override_remove',
    employee_exemption_save: 'action_employee_exemption_save', employee_exemption_remove: 'action_employee_exemption_remove',
    run_settings_save: 'action_run_settings_save',
    employee_verified: 'action_employee_verified', employee_unverified: 'action_employee_unverified',
};
function auditActionLabel(action) {
    const key = AUDIT_ACTION_LABEL_KEYS[action];
    if (!key) {
        console.warn('[auditActionLabel] missing i18n mapping for action:', action);
        return action;
    }
    return (langData && langData[key]) || action;
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
// own DataTable in place (e.g. `() => tb_employee.ajax.reload(null, false)`). Three independent
// signals, same as the pattern this generalizes: the 'storage' event (fires immediately, but only
// while this tab is in the background/inactive in some browsers) plus a 'visibilitychange' fallback
// (catches the case of coming back to this tab after the editor tab already saved and closed).
// 2026-08-30, explicit report of the reload not happening ("List เหมือนจะยังไม่ Reload") -- couldn't
// pin an exact root cause by reading the code (both existing signals look structurally correct for
// their intended cases), so added window's own 'focus' event too as a genuinely independent third
// signal: some browser/window-manager combinations fire it more reliably than 'visibilitychange'
// when switching back to this tab/window. Harmless if redundant with the other two -- reloadFn()
// itself is a cheap in-place DataTables refresh, not destructive to re-run more than strictly needed.
function watchTabDirty(key, reloadFn) {
    window.addEventListener('storage', function (e) {
        if (e.key === key) reloadFn();
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') reloadFn();
    });
    window.addEventListener('focus', reloadFn);
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
    refreshAllDataTablesLanguage();
}
// 2026-08-30 (T004), real bug found and fixed (explicit report: "DataTable pagination/search
// wording ไม่เปลี่ยนภาษา...ต้อง re-init หรือ bind language object ใหม่ตอน toggle ภาษา ไม่ใช่แค่ตอน reload
// หน้า") -- DataTables' own rendered UI strings (Search label, Show-N-entries label, First/Previous/
// Next/Last, "Showing X to Y of Z entries") are ONLY ever set from the `language:` option passed at
// `.DataTable({...})` construction time; nothing in this app ever re-passed it after that. A full
// destroy-and-recreate per table was considered and rejected -- most pages keep their own page-level
// variable (`tb_employee`, `tb_holiday`, ...) that OTHER code on that page calls methods on
// afterward (`.ajax.reload()` etc.); destroying a table and creating a NEW DataTables instance via a
// generic app.js-level sweep would silently orphan every such variable (it would still point at the
// OLD, now-destroyed instance), breaking that page's Add/Edit/Delete/reload buttons until a full
// page refresh -- a worse regression than the language bug this is fixing. This is a SAFE, purely
// cosmetic fix instead: it never destroys/recreates anything or touches DataTables' functional
// state, only the VISIBLE text of 3 things, each verified against this exact bundled DataTables
// version's own source (node_modules/datatables.net/js/dataTables.js) rather than guessed:
//   1. Pagination buttons (First/Previous/Next/Last) + the zero-records/processing/search strings --
//      DataTables' `_pagingButtonInfo()` reads `settings.oLanguage.oPaginate` FRESH on every single
//      draw (a live reference read off the shared `settings` object, not a value captured once) --
//      confirmed by reading that function directly. So mutating `settings.oLanguage` in place, using
//      the internal "Hungarian" property names (`sSearch`/`sLengthMenu`/`oPaginate.sFirst`/etc, NOT
//      the camelCase `search`/`lengthMenu`/`paginate.first` getTableLang() itself returns --
//      DataTables only converts camelCase->Hungarian ONCE, at init, via its own internal
//      `_fnCamelToHungarian()`, so a later direct mutation has to already be in the Hungarian shape
//      to take effect) + calling `table.draw(false)` genuinely re-renders these correctly.
//   2. The Search box's "Search:" label and the "Show _MENU_ entries" length-menu label are NOT
//      covered by the above -- both are built ONCE, into static DOM text, the FIRST time each
//      feature is constructed (`DataTable.feature.register('search', ...)`/`('pageLength', ...)`),
//      and never touched again by any redraw -- confirmed directly in the source, not assumed.
//      Patched by finding the actual rendered `.dt-search > label`/`.dt-length > label` elements and
//      replacing their text (the length-menu label wraps a live `<select>` inside two surrounding
//      TEXT NODES -- only those text nodes are touched, the `<select>` element itself is left
//      completely alone so its bound change handler / current value survive untouched).
//   3. The "Showing X to Y of Z entries" info text is the one piece that's genuinely unreachable
//      even via #1's settings-mutation trick -- DataTables' `info` feature captures its own `opts`
//      snapshot in a closure at construction time and every redraw re-reads THAT closure, never
//      `settings.oLanguage` again (also confirmed directly in the source) -- there is no public way
//      to reach into that closure from outside. Rendered independently instead, using DataTables'
//      own PUBLIC `table.page.info()` API (start/end/recordsTotal/recordsDisplay) combined with
//      getTableLang()'s already-correct camelCase template strings -- this bypasses the closure
//      entirely rather than trying to patch it.
// 2026-09-02, real bug found and fixed (explicit report: "RangeError: Maximum call stack size
// exceeded" on the Data Sync page's history table) -- root cause: this function's own
// `table.draw(false)` a few lines down re-fires that table's `drawCallback`; a page whose
// drawCallback calls back into applyLanguage()/refreshAllTables() (as data-sync.js's history table
// did) re-enters THIS function synchronously, which calls `table.draw(false)` again, which re-fires
// drawCallback again -- unbounded synchronous self-recursion within one call stack, not an async
// loop. The actual misuse site (data-sync.js calling applyLanguage() from inside a drawCallback at
// all) is fixed at its own call site, but this guard is added here too as defense-in-depth: a
// genuine language switch only ever needs this to run once, and nothing legitimate depends on it
// being re-entrant, so refusing to re-enter is safe and closes off this entire bug class for any
// other page that makes the same mistake in the future.
let _refreshingAllDataTablesLanguage = false;
function refreshAllDataTablesLanguage() {
    if (!$.fn.dataTable || typeof $.fn.dataTable.tables !== 'function') {
        return;
    }
    if (_refreshingAllDataTablesLanguage) {
        return;
    }
    _refreshingAllDataTablesLanguage = true;
    try {
        _refreshAllDataTablesLanguageInner();
    } finally {
        _refreshingAllDataTablesLanguage = false;
    }
}
function _refreshAllDataTablesLanguageInner() {
    const lang = getTableLang();
    // $.fn.dataTable.tables() (no `{api:true}`) returns a plain array of <table> DOM nodes -- the
    // DataTables-documented way to iterate every table on the page one at a time. `{api:true}`
    // instead wraps ALL of them into a single multi-table Api context (no per-table `.every()`), so
    // that form doesn't fit what this needs.
    $.each($.fn.dataTable.tables(), function (i, node) {
        const table = $(node).DataTable();
        const settings = table.settings()[0];
        if (!settings) {
            return;
        }
        $.extend(true, settings.oLanguage, {
            sSearch: lang.search,
            sLengthMenu: lang.lengthMenu,
            sZeroRecords: lang.zeroRecords,
            sInfo: lang.info,
            sInfoEmpty: lang.infoEmpty,
            sInfoFiltered: lang.infoFiltered,
            oPaginate: {
                sFirst: lang.paginate.first,
                sLast: lang.paginate.last,
                sNext: lang.paginate.next,
                sPrevious: lang.paginate.previous,
            },
        });
        table.draw(false);

        const $wrapper = $(table.table().container());
        const searchLabelText = (lang.search || 'Search:').replace('_INPUT_', '').trim();
        $wrapper.find('.dt-search > label').text(searchLabelText);

        const menuTemplate = lang.lengthMenu || 'Show _MENU_ entries';
        const menuIdx = menuTemplate.indexOf('_MENU_');
        const menuBefore = menuIdx >= 0 ? menuTemplate.slice(0, menuIdx) : menuTemplate;
        const menuAfter = menuIdx >= 0 ? menuTemplate.slice(menuIdx + '_MENU_'.length) : '';
        $wrapper.find('.dt-length > label').each(function () {
            const textNodes = Array.prototype.filter.call(this.childNodes, n => n.nodeType === 3);
            if (textNodes.length >= 2) {
                textNodes[0].textContent = menuBefore;
                textNodes[textNodes.length - 1].textContent = menuAfter;
            } else if (textNodes.length === 1) {
                textNodes[0].textContent = menuBefore + menuAfter;
            }
        });

        const info = table.page.info();
        const $infoNode = $wrapper.find('.dt-info');
        if ($infoNode.length) {
            let text;
            if (info.recordsDisplay === 0) {
                text = lang.infoEmpty || 'Showing 0 to 0 of 0 entries';
            } else {
                text = (lang.info || 'Showing _START_ to _END_ of _TOTAL_ entries')
                    .replace('_START_', info.start + 1)
                    .replace('_END_', info.end)
                    .replace('_TOTAL_', info.recordsTotal);
                if (info.recordsDisplay !== info.recordsTotal) {
                    text += ' ' + (lang.infoFiltered || '(filtered from _MAX_ total entries)').replace('_MAX_', info.recordsTotal);
                }
            }
            $infoNode.text(text);
        }
    });
}