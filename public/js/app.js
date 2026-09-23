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
// ตอนโหลดข้อมูลหน้าหลัก"). 2026-09-14, Phase Design Round 3 item 3c-1 follow-up (§10/§11) --
// REDESIGNED: markup moved to a partial (app/views/layout/page-loader.php, rendered once per page
// via footer.php, hidden by default with `d-none`) instead of this function building/tearing down
// the whole `<div>` tree on every call -- these two functions now just toggle that pre-rendered
// element, same "PHP partial + JS twin that only manipulates it" shape every other §11 shared
// component in this round already uses. Two explicit timing requirements neither can be pure CSS
// (`display` -- what `d-none` toggles -- can't transition):
//  - Appear only after a 200ms delay, so a load that finishes faster than that never flashes the
//    overlay at all. `pageLoaderShowTimer` is the pending setTimeout id; hidePageLoader() cancels
//    it if the load finishes before the delay elapses (nothing ever became visible, nothing to
//    fade back out either).
//  - Fade out over 150ms before actually re-hiding (`d-none` re-added only after that timer, not
//    immediately) -- `.om-page-loader-visible` (style.css) is what the CSS `transition: opacity`
//    is actually keyed off; removing/re-adding `d-none` alone would just snap instantly.
// Both remain idempotent (call while already showing/scheduled/hiding is a safe no-op) --
// preserves the original comment's own note that a caller firing 2 parallel fetches and calling
// this from both must not double-schedule or double-remove.
let pageLoaderShowTimer = null;
let pageLoaderHideTimer = null;
function showPageLoader() {
    const $loader = $('#omPageLoader');
    if (!$loader.length) return;
    clearTimeout(pageLoaderHideTimer);
    pageLoaderHideTimer = null;
    if (pageLoaderShowTimer || $loader.hasClass('om-page-loader-visible')) return;
    pageLoaderShowTimer = setTimeout(function () {
        pageLoaderShowTimer = null;
        $loader.removeClass('d-none');
        void $loader[0].offsetWidth; // force reflow so the opacity transition below actually runs
        $loader.addClass('om-page-loader-visible');
    }, 200);
}
function hidePageLoader() {
    const $loader = $('#omPageLoader');
    if (!$loader.length) return;
    if (pageLoaderShowTimer) {
        // never actually appeared yet (still inside the 200ms delay) -- cancel, nothing to fade
        clearTimeout(pageLoaderShowTimer);
        pageLoaderShowTimer = null;
        return;
    }
    if (!$loader.hasClass('om-page-loader-visible')) return;
    $loader.removeClass('om-page-loader-visible');
    pageLoaderHideTimer = setTimeout(function () {
        pageLoaderHideTimer = null;
        $loader.addClass('d-none');
    }, 150);
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
// Statutory/Payroll Configuration all call this the same way -- Permission Matrix, despite an older
// comment claiming otherwise, actually keeps its own separate bespoke dirty-check, confirmed by
// reading permission-matrix.js directly) AND by the modal dirty-guard mechanism right below this
// function.
//
// `promptOptions` (Round 2 item 7b, optional 4th param -- every existing 3-arg call site above is
// completely unaffected, `promptOptions` defaults to `{}` and every one of its own fields falls back
// to the exact same copy this function already used): lets a caller override the confirm dialog's
// title/message/button text/danger-styling for ITS OWN context, without this function needing a
// competing 2nd implementation. The modal dirty-guard below is the one real caller that uses this --
// see its own docblock for why its copy needs to differ from the page-body default.
function confirmIfDirtyThen($container, baselineSnapshot, onProceed, promptOptions) {
    if (!isFormDirty($container, baselineSnapshot)) {
        onProceed();
        return;
    }
    const opts = promptOptions || {};
    showConfirm({
        title: opts.title || (langData && langData['confirm_discard_changes_title']) || 'Discard unsaved changes?',
        message: opts.message || (langData && langData['confirm_discard_changes_message']) || "You have changes that haven't been saved yet. If you continue, they will be lost.",
        confirmText: opts.confirmText,
        cancelText: opts.cancelText,
        // 2026-09-14, Round 3 item 3c-3, explicit instruction: a caller can now pass `tone` directly
        // (showConfirm()'s own real 'danger'/'warning'/'success' vocabulary) instead of only the
        // coarser `danger:true/false` this took before -- `danger` still works unchanged for the 3
        // existing page-body Cancel-button callers that never pass `tone` at all.
        tone: opts.tone,
        danger: opts.danger,
        onYes: onProceed,
        onNo: opts.onNo,
    });
}

// Modal dirty-guard (Round 2 item 7b, docs/design/rules.md §9) -- a GENERIC, but OPT-IN, modal-level
// dirty-check: only a `.modal` carrying `data-dirty-guard` participates, intercepting its own
// X/Esc/backdrop/any-`data-bs-dismiss` close attempt (all 4 of those are the SAME Bootstrap
// `hide.bs.modal` event under the hood, so one delegated handler below covers all 4 -- a plain
// "Cancel" button using the standard `data-bs-dismiss="modal"` attribute needs no wiring of its own
// at all, it already funnels through here).
//
// This is a deliberate REDESIGN, not a revival, of the mechanism Platform Hardening Phase 1.2 built
// and then 2026-09-09 explicitly removed system-wide ("ปิดทั้งระบบ เอา dirty-check ออกทั้งหมด") for
// being confusing across most forms/modals in the app. Confirmed directly with the user before
// writing this (the alternative was to leave it removed and demo the existing page-body Cancel-
// button pattern instead) -- 3 concrete differences from the removed version address the actual
// complaint instead of just reintroducing it:
//  1. Opt-in via `data-dirty-guard`, not every `.modal:has(form)` -- a view/select/filter modal never
//     participates; round 4 decides per real modal, when it migrates, whether it's a genuine
//     data-editing form worth guarding. The removed version intercepted ALL ~100 modals
//     indiscriminately, which is exactly what made it feel like it was firing everywhere.
//  2. Dirty = a snapshot taken at `shown.bs.modal` compared against a snapshot taken at the moment of
//     close (via the SAME snapshotFormState()/isFormDirty() this file already uses for page-body
//     Cancel buttons), not a "was any change event ever fired" flag -- open then close untouched, or
//     edit a field then edit it back to its original value, never prompts either way.
//  3. Refreshed automatically after a successful save (see refreshDirtyGuard() below) -- a save
//     immediately followed by closing the modal never prompts, since the baseline is already caught
//     up to what was just saved.
// `data-dirty-guard` is not used by any real page yet (no view has been migrated to it this round --
// Round 2 does not touch real page templates, §13); the components.php demo below exercises all 3
// scenarios directly.
$(document).on('shown.bs.modal', '.modal[data-dirty-guard]', function () {
    $(this).data('dirtyGuardBaseline', snapshotFormState($(this)));
});
$(document).on('hide.bs.modal', '.modal[data-dirty-guard]', function (e) {
    const $modal = $(this);
    // Set by the confirm's own "close without saving" branch right below, immediately before it
    // re-triggers .hide() on this SAME modal -- without this guard that 2nd .hide() call would just
    // re-enter this handler and prompt a second time, forever.
    if ($modal.data('dirtyGuardBypass')) {
        $modal.removeData('dirtyGuardBypass');
        return;
    }
    if (!isFormDirty($modal, $modal.data('dirtyGuardBaseline'))) return;
    e.preventDefault();
    confirmIfDirtyThen($modal, $modal.data('dirtyGuardBaseline'), function () {
        $modal.data('dirtyGuardBypass', true);
        const inst = bootstrap.Modal.getInstance($modal[0]);
        if (inst) inst.hide();
    }, {
        title: (langData && langData['confirm_modal_dirty_title']) || 'You have unsaved changes',
        message: (langData && langData['confirm_discard_changes_message']) || "You have changes that haven't been saved yet. If you continue, they will be lost.",
        confirmText: (langData && langData['action_close_without_saving']) || 'Close without saving',
        cancelText: (langData && langData['action_back_to_editing']) || 'Back to editing',
        // 2026-09-14, Round 3 item 3c-3, explicit instruction: "showConfirm tone warning" for the
        // Comments modal specifically -- was hardcoded `danger:true` (red confirm button) for every
        // `data-dirty-guard` modal with no per-modal override. Opt-in via a `data-dirty-guard-tone`
        // attribute on the modal itself (falls back to the original 'danger' when absent, so this
        // stays a no-op for any other modal that migrates to data-dirty-guard later without setting
        // it) -- `tone` (not `danger`) is what showConfirm()/confirmIfDirtyThen() actually reads.
        tone: $modal.attr('data-dirty-guard-tone') || 'danger',
    });
});
// Call this right after a successful save (or a resetForm()) on a `data-dirty-guard` modal that
// STAYS open -- re-captures the baseline against the form's now-current (just-saved) values, so the
// next close attempt compares against what's actually on the server now, not the state from when the
// modal first opened. Never needed for a save flow that closes the modal itself immediately
// afterward, AS LONG AS this is called before that close -- calling `.hide()` first would still see
// the stale (pre-save) baseline and prompt unnecessarily.
function refreshDirtyGuard(modalSelectorOrEl) {
    const $modal = $(modalSelectorOrEl);
    if (!$modal.length) return;
    $modal.data('dirtyGuardBaseline', snapshotFormState($modal));
}
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
// 2026-09-15: a tab row scrolls sideways instead of wrapping (style.css's own `.nav-tabs` rule), so
// on a narrow screen the ACTIVE tab can start out past the right edge -- this brings it into view,
// at load and whenever a tab becomes active later (including a modal's own tabs, which only exist
// once it opens). `inline: 'nearest'` never scrolls the page itself, only the tab strip.
function scrollActiveTabIntoView(root) {
    $(root || document).find('.nav-tabs').each(function () {
        const strip = this;
        if (strip.scrollWidth <= strip.clientWidth + 1) return;
        const active = strip.querySelector('.nav-link.active');
        if (active && active.scrollIntoView) active.scrollIntoView({ inline: 'nearest', block: 'nearest' });
    });
}
$(document).on('shown.bs.tab', function (e) {
    scrollActiveTabIntoView($(e.target).closest('.nav-tabs').parent());
});
$(document).on('shown.bs.modal', function (e) {
    scrollActiveTabIntoView(e.target);
});
$(document).ready(function () {
    scrollActiveTabIntoView(document);
});
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
    initMoneyInputs(document);
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
        if (!$(this).closest('.dataTables_wrapper, .dt-container, .table-responsive').length) return;
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
        // 2026-09-13, Round 3 "เก็บตกรอบ 4", real bug found and fixed (explicit report: "ตัด '…' ท้าย
        // label ออก (placeholder ในช่องพอ)") -- `langData.search` itself keeps its trailing "..."
        // (correct for its OTHER, genuine placeholder consumers app-wide -- table-column-filter.js's
        // own popup, 3 Assign-To scope search boxes in layout/modals.php) but DataTables' own search
        // FEATURE reads 2 SEPARATE language keys for 2 different DOM targets: `search` becomes the
        // visible `<label>` text next to the input, `searchPlaceholder` becomes the actual `<input
        // placeholder>` attribute (confirmed directly from the installed DataTables source --
        // `opts.placeholder = language.sSearchPlaceholder`, a wholly separate property from
        // `opts.text = language.sSearch`). Trailing dots belong on the placeholder (inside the field,
        // where the hint is actually read) not doubled onto the label too -- `.replace(/\.+$/, '')`
        // strips them for the label only, leaving the shared key's own canonical value untouched.
        // 2026-09-16, explicit instruction ("ตัด label 'ค้นหา' หน้าช่อง เหลือ placeholder + aria-label"):
        // the visible <label> is now EMPTY for every table in the app (86 call sites all read this
        // helper) -- the placeholder inside the field already says the same word, and a label that
        // only repeats it costs a control-row slot on a 430px screen for nothing. The word itself is
        // NOT lost: it becomes the input's `aria-label` (wired once for every table in the app by the
        // delegated `init.dt` handler further down, and re-applied on a live language switch by
        // _refreshAllDataTablesLanguageInner()), so screen readers still announce the field.
        // `.dt-search > label:empty` is hidden in style.css so the empty element leaves no gap.
        search: '',
        searchAriaLabel: (langData.search || "Search...").replace(/\.+$/, ''),
        searchPlaceholder: langData.search || "Search...",
        lengthMenu: langData.lengthMenu || "Show _MENU_ entries",
        zeroRecords: langData.zeroRecords || "No matching records found",
        // 2026-09-11, Batch 3C item 6 follow-up: genuinely missing until now (confirmed via grep --
        // no emptyTable key existed anywhere in en.json/th.json, and this function never returned
        // one) -- a DOM-sourced table with truly zero rows (not a search filter finding nothing,
        // that's zeroRecords above) would show DataTables' own unlocalized "No data available in
        // table" instead. This is the shared DEFAULT; initSharedDataTable() callers still override
        // it per-table with a more specific message where one makes sense (e.g. Cash Payments' own
        // "No cash-paying employees in this run.").
        emptyTable: langData.emptyTable || "No data available in table",
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
// 2026-09-11, Batch 3C item 6 -- ONE shared init for a small/medium DOM-sourced table (rows already
// rendered as plain <tr> HTML into the table's own <tbody>, no `data:`/`columns:`/`ajax:` config of
// its own) that just needs pagination/search/sort layered on top -- the common options every such
// table wants (language via getTableLang(), this app's own shared pageLength/lengthMenu constants,
// ordering, hiding the search box entirely when there are too few rows for it to matter) collapsed
// into one call instead of each page repeating the same handful of options. Deliberately NOT a
// retrofit of every existing DataTable in the app (tb_employee, tb_run_detail, tb_join_employees, ...
// each already has its own bespoke columns/ajax/serverSide config a generic helper like this can't
// usefully replace) -- for those, only getTableLang()/refreshAllDataTablesLanguage() are shared.
// `destroy: true` is the one non-negotiable default: a table that gets its data replaced and
// re-rendered on every reload (the shape this helper targets) must destroy its OLD DataTables
// instance before constructing a new one on the same freshly-rendered rows, or DataTables throws
// "Cannot reinitialise DataTable" the second time it's called on the same <table> node.
//
// 2026-09-11 correction: `options.renderRows` (a callback that sets the table's own tbody.html())
// is now REQUIRED for a table being reloaded, and this function calls it itself, in between
// destroying the old instance and constructing the new one -- never left for the caller to
// sequence, because the naive "caller renders rows, then calls this helper" order is a real bug:
// DataTables' own destroy() on a DOM-sourced table (no `data:`/`ajax:` config, exactly this
// function's target shape) restores the tbody from its OWN internal cache captured at last
// construction -- if the caller had already replaced the tbody's rows via jQuery BEFORE calling
// this function, destroy() (called first, inside here) would overwrite those fresh rows right back
// to the OLD ones, and the subsequent .DataTable() construction would then read those stale rows
// instead of what the caller actually meant to show. The only correct order is: clear the old
// instance's own data cache, THEN destroy it (so it has nothing stale left to write back), THEN
// render the new rows, THEN construct fresh -- enforced here so no call site has to get this right
// on its own.
// 2026-09-12, Phase Design Round 2 item 3 (docs/design/rules.md §7) -- reads the marker classes
// (.num/.col-date/.col-money/.col-check/.col-avatar/.col-actions) already sitting on each <thead>
// <th> (written into the view's own static HTML, or built into a `headHtml` string before
// `.DataTable()` construction the same way annual-summary.js already does -- either shape works,
// this only ever reads the DOM, never cares how it got there) and turns them into DataTables
// `columnDefs` targeting that column's INDEX, so every column tagged this way gets the §7-mandated
// alignment/behavior for free, with zero JS per table. A `<th>` with none of these classes is left
// completely alone (default left-align, orderable/searchable per the table's own other settings) --
// this is purely additive, never a behavior change for a column that isn't opted in. `.col-date`
// needs no real columnDef (§7: dates are LEFT-aligned, already the plain HTML/DataTables default)
// but still gets its class explicitly propagated for consistency/documentation, not skipped as a
// "no-op". None of the 8 existing initSharedDataTable() callers (4 in payroll/detail.js, 4 in
// reports/annual-summary.js) have any of these classes on their own <th> markup yet -- confirmed by
// reading both files -- so this is a genuine no-op for every current caller, activating only once
// round 4 adds these classes to a page's own view markup.
const DT_MARKER_CLASSES = {
    'col-date': { className: 'col-date' },
    'col-money': { className: 'num col-money' },
    'num': { className: 'num' },
    'col-check': { className: 'col-check text-center', orderable: false, searchable: false },
    'col-avatar': { className: 'col-avatar text-center', orderable: false, searchable: false },
    'col-actions': { className: 'col-actions text-end', orderable: false, searchable: false },
    // Round 2 item (2) -- §7's own row-switch rule: "switch ในแถว = คอลัมน์แรกหรือคอลัมน์ 'ใช้งาน'
    // กว้างคงที่ กึ่งกลาง ไม่ sort" -- same treatment as .col-check/.col-avatar (fixed width via CSS,
    // centered, not orderable/searchable), just its own marker name since it's a semantically
    // different column (an enable/disable toggle, not a row-selection checkbox or a person's photo).
    'col-toggle': { className: 'col-toggle text-center', orderable: false, searchable: false },
};
function dtColumnDefsFromMarkerClasses($table) {
    const defs = [];
    $table.find('> thead > tr').first().find('> th').each(function (index) {
        const classes = (this.className || '').split(/\s+/);
        // §7's own table pairs .num with .col-money specifically for money columns -- checking
        // BOTH together first (so a <th class="num col-money"> gets the combined "num col-money"
        // className exactly once, not "num" and "num col-money" stacked from 2 separate defs)
        // avoids emitting a redundant/conflicting second columnDef for the same index.
        let matched = null;
        if (classes.includes('col-money')) matched = DT_MARKER_CLASSES['col-money'];
        else if (classes.includes('num')) matched = DT_MARKER_CLASSES['num'];
        else if (classes.includes('col-date')) matched = DT_MARKER_CLASSES['col-date'];
        else if (classes.includes('col-check')) matched = DT_MARKER_CLASSES['col-check'];
        else if (classes.includes('col-avatar')) matched = DT_MARKER_CLASSES['col-avatar'];
        else if (classes.includes('col-actions')) matched = DT_MARKER_CLASSES['col-actions'];
        else if (classes.includes('col-toggle')) matched = DT_MARKER_CLASSES['col-toggle'];
        if (matched) defs.push(Object.assign({ targets: index }, matched));
    });
    return defs;
}
// 2026-09-13, Round 3 "เก็บตก" item 3, real gap found (explicit report: footer cells like "ตรวจสอบแล้ว
// 1/1"/"คำนวณแล้ว 1/1" not left-aligned to match their own column -- §7 badge=left) -- DataTables'
// own columnDefs `className` (dtColumnDefsFromMarkerClasses() above) is a construction-time option
// that only ever applies to `<thead>`/body `<td>` cells; it never reaches a page's own static
// `<tfoot>` markup at all, confirmed by reading dataTables.bootstrap5.css directly (it DOES ship a
// `table.dataTable tfoot th, tfoot td { text-align:left }` default, but a page's own hardcoded class
// on one specific footer cell -- e.g. `class="text-center"`, a real one found on
// payroll/detail.php's own `#rdFootVerifyLock` -- easily overrides that library default with zero
// warning, since Bootstrap's `.text-center` utility carries `!important`). Rather than trust every
// page to hand-write the correct alignment class on its own `<tfoot>` cells (or worse, guess wrong
// the way `#rdFootVerifyLock` did), this reuses the SAME marker-class detection already run against
// `<thead>` and mirrors each match onto the `<tfoot>` cell at the identical column index --
// `.addClass()`, never `.attr('class', ...)`, so a page's own additional footer-only classes (e.g.
// `#rdFootGross`'s own `fw-bold money-gross` running total styling) are always preserved, only
// ADDED to. A table with no `<tfoot>` at all, or a column index with nothing in `<tfoot>` (mismatched
// column count), is a safe no-op either way -- `.find()` on a missing element chain just yields an
// empty jQuery set. Static markup, so this runs once at `initSharedDataTable()`'s own setup, not on
// every draw the way body-row rendering needs to.
function applyTfootMarkerClasses($table, columnDefs) {
    const $tfootCells = $table.find('> tfoot > tr').first().find('> th, > td');
    if (!$tfootCells.length) return;
    columnDefs.forEach(function (def) {
        if (def.className) $tfootCells.eq(def.targets).addClass(def.className);
    });
}
// initRowToggles($table, {onChange}) -- Round 2 item (2), docs/design/rules.md §7's shared
// per-row switch pattern (a DataTable's "ใช้งาน"/enable-disable column -- the pattern
// setup/tax-statutory.js's/company-profile.js's own per-row active/inactive switches already use,
// each with its own bespoke wiring today; this is the ONE shared version those migrate onto in
// round 4, not a parallel mechanism -- §0's own "ซ้ำ=shared" rule). Delegated on `.row-toggle-switch`
// inside $table (survives DataTables redrawing rows on page/sort/search -- a direct `.on('change',
// selector, ...)` binding on the element itself would not).
//
// `onChange(rowId, checked, $switch)` MUST return a thenable (a `$.ajax()` call already is one) --
// this helper awaits ONLY to know success/failure, never reads the response body itself (the
// caller's own `.done()`/`.fail()` on that same promise, if it has one, still runs independently).
// The switch disables itself the instant it's toggled (no double-clicking while a request is still
// in flight) and re-enables on success; on failure (rejection OR a synchronous throw inside
// onChange()) it snaps back to its PRE-click state and re-enables -- a row's on/off state must never
// silently drift from what the server actually holds just because a request failed.
function initRowToggles($table, options) {
    options = options || {};
    const onChange = options.onChange;
    $table.off('change.rowToggle').on('change.rowToggle', '.row-toggle-switch', function () {
        const $switch = $(this);
        const rowId = $switch.data('id');
        const checked = $switch.is(':checked');
        $switch.prop('disabled', true);
        let result;
        try {
            result = onChange ? onChange(rowId, checked, $switch) : null;
        } catch (e) {
            $switch.prop('checked', !checked).prop('disabled', false);
            throw e;
        }
        const promise = (result && typeof result.then === 'function') ? result : Promise.resolve(result);
        promise.then(function () {
            $switch.prop('disabled', false);
        }, function () {
            $switch.prop('checked', !checked).prop('disabled', false);
        });
    });
}
// 2026-09-12, Round 2 item 3 -- §7's "ส่งออก: dropdown secondary ตัวเดียว (Excel/PDF) ต่อจากช่องค้นหา"
// injected into the SAME `.dt-search` container the app's existing "Add" button convention already
// targets (see e.g. employee/detail.js's own initComplete) -- same technique, not a new mechanism.
// Deliberately NOT built on DataTables' own Buttons extension (datatables.net-buttons/buttons.html5/
// jszip/pdfmake) -- confirmed via `node_modules` listing that NONE of those are installed in this
// project, and this app's own established convention for Excel/PDF export everywhere else
// (Reports module, PayrollReportDataModel/PhpSpreadsheet/dompdf) is a SERVER-generated file download,
// not a client-side re-serialization of whatever DataTables currently has in memory -- consistent
// with that, this renders ONLY the dropdown UI; `options.export.onSelect(format)` (format is
// 'excel'/'pdf') is the caller's own hook to trigger its existing download flow. No new dependency
// added -- if a future page genuinely needs client-side table-to-file export with no backend
// endpoint to call, that would need a real library decision, reported before adding it, not silently
// bundled in here.
// `options.filterBar` (optional, a selector): which filter panel belongs to this table. Only needed
// on a page that has MORE THAN ONE `.filter-bar` -- with a single one it is found automatically. It
// is what lets the table's own empty state clear the panel's fields along with its own search and
// column filters (see clearAllTableFilters()).
// 2026-09-15, rules.md 7 "DataTable toolbar" -- the toolbar is the COMPONENT's, not each page's.
// `options.toolbar = { create: html|null, actions: [html...], export: bool }`:
//   create  = the one button that makes a new row (orange, right-most)
//   actions = every other toolbar button (bulk actions, sync, log, ...) in caller order
//   export  = whether the shared export dropdown renders (same as `options.export` being set)
// Rendered order at `sm` and up, one row:  [length][actions] .... [export][search][create]
// Below `sm`, two rows:  row 1 [length][search ~60%] | row 2 [actions] ..... [export][create]
// (the row break is a CSS `::after` line-break inside `.dt-layout-row`, see style.css).
// Pages that pass no `toolbar` are untouched: nothing is inserted and the markup is byte-identical
// to before, which is what keeps every not-yet-migrated page rendering exactly as it did.
function dtRenderToolbarSlot($table, toolbar) {
    if (!toolbar) return;
    const $wrapper = $table.closest('.dataTables_wrapper, .dt-container');
    const $search = $wrapper.find('.dt-search').first();
    if (!$search.length) return;
    const $end = $search.parent();
    $end.find('.dt-toolbar-actions, .dt-toolbar-create').remove();
    const actions = (toolbar.actions || []).filter(Boolean);
    if (actions.length) {
        // Actions live on the LEFT, straight after the length select: they act on the rows already
        // on screen, which is the same half of the toolbar that says how many rows that is.
        const $actions = $('<div class="dt-toolbar-actions"></div>');
        actions.forEach(html => $actions.append(html));
        const $start = $wrapper.find('.dt-layout-start').first();
        if ($start.length) $start.append($actions); else $search.before($actions);
    }
    // The export dropdown is appended INTO `.dt-search` by dtInjectExportDropdown() (which every
    // page uses, toolbar slot or not) -- a toolbar page wants it to the LEFT of the search box, so
    // it is moved here rather than in that shared function, leaving non-toolbar pages untouched.
    const $exportDropdown = $wrapper.find('.dt-export-dropdown');
    if ($exportDropdown.length) $search.before($exportDropdown);
    if (toolbar.create) {
        $search.after($('<div class="dt-toolbar-create"></div>').append(toolbar.create));
    }
    $wrapper.addClass('dt-has-toolbar').toggleClass('dt-toolbar-noactions', actions.length === 0);
    // DataTables' own toolbar row is a plain Bootstrap `.row` with no stable class of its own, so
    // the row that actually holds these cells gets marked here rather than guessed at in CSS.
    $end.parent().addClass('dt-toolbar-row');
    if (typeof applyLanguage === 'function' && typeof currentLang !== 'undefined') {
        updateText($end[0]);
    }
}
function dtInjectExportDropdown($table, exportOptions) {
    const $wrapper = $table.closest('.dataTables_wrapper, .dt-container');
    const $searchDiv = $wrapper.find('.dt-search');
    if (!$searchDiv.length || $searchDiv.find('.dt-export-dropdown').length) return;
    const $dropdown = $(`
        <div class="dropdown dt-export-dropdown ms-1 d-inline-block">
            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fa-solid fa-file-export me-1"></i>${(langData && langData['export']) || 'Export'}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item dt-export-item" href="#" data-format="excel"><i class="fa-solid fa-file-excel file-icon-excel me-2"></i>Excel</a></li>
                <li><a class="dropdown-item dt-export-item" href="#" data-format="pdf"><i class="fa-solid fa-file-pdf file-icon-pdf me-2"></i>PDF</a></li>
            </ul>
        </div>
    `).appendTo($searchDiv);
    $dropdown.find('.dt-export-item').on('click', function (e) {
        e.preventDefault();
        if (typeof exportOptions.onSelect === 'function') {
            exportOptions.onSelect($(this).data('format'));
        }
    });
}
// 2026-09-16, rules.md §7 "control scale": datatables.net-bs5's own integration file builds the
// length select and the search box with `form-select-sm`/`form-control-sm` baked in
// (`DataTable.ext.classes` in node_modules/datatables.net-bs5/js/dataTables.bootstrap5.js), which is
// why those 2 controls used to read a size smaller than every button beside them in the same row
// (measured 10.5px/23.8px vs a normal button's 12px/29px). Overridden ONCE here, before any table is
// constructed, rather than stripped per table or fought with CSS -- this reaches every DataTable in
// the app, including the pages that still build their own with `$().DataTable()` and never call
// initSharedDataTable(). Vendor file untouched.
// Inside a ready handler, not at parse time: this file is loaded from `layout/header.php`, i.e.
// BEFORE footer.php pulls DataTables in -- at parse time `$.fn.dataTable` does not exist yet and the
// override would silently do nothing (confirmed live: the classes were still the `-sm` ones). By
// DOM-ready every library is in, and this file's own ready handler is registered before any page
// script's, so it lands before the first table is constructed.
$(function () {
    const ext = window.jQuery && $.fn.dataTable && $.fn.dataTable.ext;
    if (!ext || !ext.classes) return;
    if (ext.classes.search) ext.classes.search.input = 'form-control';
    if (ext.classes.length) ext.classes.length.select = 'form-select';
});
// 2026-09-16: the search field's accessible name, for EVERY DataTable in the app -- its visible
// <label> is empty now (getTableLang()'s own `search: ''`), so without this the input would have no
// accessible name at all. Delegated on `document` rather than wired per table: `init.dt` bubbles up
// from every table DataTables constructs, including the 14 pages that still build their own with
// `$().DataTable()` and never reach initSharedDataTable() (BACKLOG "รอบ 4"). Placeholder alone is not
// an accessible name -- some screen readers ignore it entirely, and it disappears the moment the
// user types.
$(document).on('init.dt', function (e, settings) {
    if (!$.fn.dataTable || !$.fn.dataTable.Api) return;
    const api = new $.fn.dataTable.Api(settings);
    const lang = api.settings()[0].oLanguage || {};
    const label = lang.searchAriaLabel || (getLangValue('search') || 'Search').replace(/\.+$/, '');
    $(api.table().container()).find('.dt-search > input').attr('aria-label', label);
});
function initSharedDataTable(selector, options) {
    options = options || {};
    const $table = $(selector);
    if ($.fn.DataTable.isDataTable(selector)) {
        $table.DataTable().clear().destroy();
    }
    if (typeof options.renderRows === 'function') {
        options.renderRows();
    }
    // 2026-09-12, Batch 5 item 5 step 3 (step 4 follow-up: read from the ONE place DataTables
    // itself actually uses, not a second copy) -- a data:/columns:-driven table (rows supplied via
    // options.dtOptions.data, not written into the DOM by this function's own renderRows above) has
    // an EMPTY tbody at this exact point -- DataTables itself only populates it once .DataTable()
    // below actually runs -- so counting `tbody tr` here would always read 0 for that shape,
    // wrongly hiding the search box below the threshold regardless of how many rows the table is
    // about to show. `options.dtOptions.data` (when present) is that exact same array reference the
    // `dtOptions` build below hands to `.DataTable()` -- Object.assign() only shallow-copies the key,
    // never clones the array -- so reading it here needs no separate/duplicate `options.data` from
    // the caller. A DOM-sourced caller (renderRows fills the tbody directly -- the 4 existing
    // callers in payroll/detail.js, none of which pass a `data` key in `dtOptions` either) never
    // hits this branch at all, so it falls through to the tbody count exactly as before --
    // unaffected by this change.
    const rowCount = options.dtOptions && Array.isArray(options.dtOptions.data) ? options.dtOptions.data.length : $table.find('tbody tr').length;
    const searchThreshold = options.searchThreshold != null ? options.searchThreshold : 10;
    // `language` is merged one level deep on top of getTableLang() (not just Object.assign'd whole)
    // so a caller passing e.g. { language: { emptyTable: '...' } } (a localized empty-state message
    // for a table with genuinely zero rows -- DataTables' own emptyTable string, NOT the zeroRecords
    // one getTableLang() already covers, which is for a SEARCH filter finding nothing) doesn't wipe
    // out every other language key getTableLang() already provides.
    const dtOptions = Object.assign({
        destroy: true,
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        ordering: true,
        searching: rowCount > searchThreshold,
        // 2026-09-12, Round 2 item 3 follow-up -- real bug found via the components.php demo table
        // (§2's own "ตารางไม่เต็มขอบ" symptom, already logged once in docs/design/audit.md as a
        // systemic finding): DataTables' own default `autoWidth:true` MEASURES each column's content
        // and sets explicit inline pixel widths on <table>/<col> from that measurement -- those
        // inline widths win over the table's own CSS `width:100%` (`.table`/`.w-100` class, or
        // table.dataTable's own width rule in style.css) regardless of how wide the container
        // actually is, so a table with modest content renders narrower than its container instead of
        // stretching to fill it. `autoWidth:false` stops DataTables from setting those inline widths
        // at all, letting plain CSS own the table's width the way this app already intends everywhere
        // else -- a caller can still override back to `autoWidth:true` via its own `dtOptions` if a
        // specific table genuinely needs DataTables' own column-width measurement.
        autoWidth: false,
        // 2026-09-12, Round 2 item 3b, corrected same day (§7's own toolbar layout: length on the
        // LEFT, search+export together on the RIGHT) -- this is actually DataTables' OWN built-in
        // default already (topStart:'pageLength', topEnd:'search'), confirmed by reading its own
        // defaults object directly -- an earlier version of this same line swapped it the OTHER way
        // (search left/length right), which was itself the mistake this correction fixes. Still set
        // explicitly (not left implicit) so a future change to DataTables' own default can't
        // silently change this app's intended layout. The export dropdown
        // (dtInjectExportDropdown() above) targets `.dt-search` specifically wherever it ends up, so
        // it always lands next to search regardless of which side that is. None of the 8 existing
        // callers pass their own `layout` option (confirmed via grep) -- ships automatically via
        // this shared config, no page edit needed, same mechanism as item 1's button/tab recolor.
        layout: { topStart: 'pageLength', topEnd: 'search' },
        // 2026-09-16, explicit instruction ("ค้นหา: พิมพ์ไปค้นไป debounce 300ms client-side, พฤติกรรม
        // เดียวทั้งแอป"). DataTables' own `searchDelay` option, NOT a hand-rolled unbind/rebind of its
        // input handlers: the library already wires `keyup/search/input/paste/cut` through its own
        // `DataTable.util.debounce` when this is set (read from the installed 2.x source directly),
        // so every one of those entry points -- including paste, which a keyup-only rebind would
        // miss -- gets the same single trailing redraw. Default 0 = one full redraw per keystroke,
        // which on these tables drags sticky columns + column filters + the empty-state re-render
        // along with it every time.
        // Only client-side tables reach this today (none of this helper's own tables are serverSide;
        // the app's 6 real serverSide tables still build themselves with `$().DataTable()` --
        // BACKLOG "รอบ 4"). When those migrate they pass their own `searchDelay: 400` here rather
        // than this helper guessing a second value for a mode nothing currently uses.
        searchDelay: 300,
    }, options.dtOptions || {});
    dtOptions.language = Object.assign({}, getTableLang(), dtOptions.language || {});
    // 2026-09-12, Round 2 item 3 -- auto columnDefs from marker classes (§7), prepended so an
    // explicit `dtOptions.columnDefs` the caller already supplies for the SAME column index still
    // wins (DataTables applies columnDefs in array order, later entries' properties override earlier
    // ones for a matching target) -- never overrides caller intent, only fills in what nobody set.
    const autoColumnDefs = dtColumnDefsFromMarkerClasses($table);
    if (autoColumnDefs.length) {
        dtOptions.columnDefs = autoColumnDefs.concat(dtOptions.columnDefs || []);
        applyTfootMarkerClasses($table, autoColumnDefs);
    }
    // 2026-09-12, Round 2 item 3 -- §7's "fix คอลัมน์แรก + หัวตาราง + scroll แนวนอน + ลากเลื่อนได้" (the
    // Employee Recheck pattern) and "ครอบหน้าที่ของ initExcelColumnFilters() ให้เอง" (round 0 decision
    // 8) both become opt-in top-level options here -- `options.stickyColumns`/`options.columnFilters`/
    // `options.export` (+ `options.emptyState`, added item 6e, 2026-09-13, §6) -- rather than every
    // caller repeating the same drawCallback/initComplete wiring `reports/annual-summary.js`'s own 4
    // tables still do by hand today. Composed so a caller's OWN `dtOptions.drawCallback`/`initComplete`
    // (if present) still runs FIRST, unchanged -- none of the existing real callers pass any of these
    // options, so this composition path is never even entered for them; their own manually-written
    // drawCallback/initComplete (annual-summary.js's 4 tables) or complete absence of one
    // (payroll/detail.js's 4 tables) passes through exactly as before, unaffected.
    if (options.toolbar && options.toolbar.export && !options.export) {
        // `toolbar.export: true` is just a friendlier spelling of the existing `options.export`
        // option for a caller that has nothing to configure about it.
        options.export = options.export || {};
    }
    if (options.stickyColumns || options.columnFilters || options.export || options.emptyState || options.toolbar) {
        const userDrawCallback = dtOptions.drawCallback;
        const userInitComplete = dtOptions.initComplete;
        // 2026-09-13, Round 2 item 6e -- `options.emptyState` needs the SAME every-draw hook
        // stickyColumns already uses (not just initComplete, which only fires once) since whether the
        // table is empty -- and why -- can change on any redraw (typing in the search box, applying a
        // column filter, changing page), not just at load. See dtRenderEmptyState()'s own docblock for
        // the recordsTotal/recordsDisplay distinction it renders around.
        if (options.stickyColumns || options.emptyState) {
            dtOptions.drawCallback = function () {
                if (typeof userDrawCallback === 'function') userDrawCallback.apply(this, arguments);
                if (options.stickyColumns) initStickyColumns(selector, options.stickyColumns);
                if (options.emptyState) dtRenderEmptyState(this.api(), options.emptyState, options.filterBar);
            };
        }
        dtOptions.initComplete = function () {
            if (typeof userInitComplete === 'function') userInitComplete.apply(this, arguments);
            const dt = this.api();
            if (options.columnFilters) initExcelColumnFilters(dt, options.columnFilters);
            if (options.stickyColumns) {
                // 2026-09-12, real bug found (header/body column misalignment on the components.php
                // demo): initStickyColumns() measures each frozen column's CURRENT rendered
                // outerWidth() via jQuery -- if that measurement runs before the page's own webfont
                // (Sarabun) has actually swapped in, the offset gets computed against the FALLBACK
                // font's metrics, which can differ from Sarabun's real glyph widths once it loads a
                // moment later -- the header cell then visibly drifts out of alignment with the body
                // cells below it as soon as the swap happens, with nothing re-triggering a recalc.
                // `dt.columns.adjust()` first (DataTables' own column-width recompute, cheap even
                // when it's a no-op under autoWidth:false) then one more initStickyColumns() call
                // once `document.fonts.ready` genuinely resolves -- a no-op immediately if fonts were
                // already loaded (the promise resolves instantly), and the actual fix for the race
                // when they weren't. Kept IN ADDITION to (not instead of) the calls already firing
                // synchronously here and in drawCallback -- this only ever ADDS one more, later,
                // guaranteed-correct recalc, never removes the immediate one a fonts-already-loaded
                // page still needs for its very first paint.
                initStickyColumns(selector, options.stickyColumns);
                initTableDragScroll(selector);
                dt.columns.adjust();
                if (window.document && document.fonts && document.fonts.ready) {
                    document.fonts.ready.then(function () {
                        initStickyColumns(selector, options.stickyColumns);
                    });
                }
            }
            if (options.export) dtInjectExportDropdown($table, options.export);
            if (options.toolbar) dtRenderToolbarSlot($table, options.toolbar);
            if (options.emptyState) dtWatchVisibleWidth($table);
        };
    }
    return $table.DataTable(dtOptions);
}
// 2026-09-12, Phase Design Round 2 item 4 (docs/design/rules.md §6), revised twice since (see this
// function's own git history for the single-toolbar-row shape this superseded) -- pairs with
// app/views/partials/filter-bar.php's 2-part header/body panel (the header/body/footer shape this
// comment used to describe was retired later the same round -- see that partial's own docblock).
// Reads every real <select>
// inside that partial's own `.filter-bar-body` (select2-enhanced or plain -- select2 is just a UI
// layer on the same underlying <select>, .val()/change events work identically either way, no
// special-casing needed) -- NOT a dedicated `.filter-bar-fields` marker div, which the first version
// of this function required; the partial itself no longer wraps the caller's fields in any class of
// its own at all (decided in an earlier round: "ให้ partial ครอบเป็นแค่ wrapper"), so this function
// scopes directly to `.filter-bar-body` instead. Derives everything else (the header's "(N)" count,
// the footer's Clear-button visibility, one removable chip per active filter, the footer's own
// "ไม่ได้กรอง" empty text, and the expanded/collapsed state) purely from CURRENT values/localStorage --
// this function owns no filter state of its own beyond that expand/collapse preference, it only
// reflects what the <select>s already say.
// "Active" = a value that is neither '' nor 'all' -- this app's own 2 established "no filter"
// sentinel values (confirmed against the existing .station-filter convention this partial replaces).
// options.onChange() fires once per actual value change (including a chip's own remove button, or
// the footer's Clear button even when it resets several selects in one click -- see the debounced
// scheduleNotify() below for why that specific case needed one) -- the caller's own reload/filter
// logic is never this function's concern.
//
// 2026-09-13, follow-up revision -- the earlier `options.toolbarTarget` (relocating the toggle/count/
// clear/chips row into e.g. a Status Tabs row) is REMOVED entirely this round ("ยกเลิก option
// toolbarTarget ไม่ต้องยัดปุ่มเข้าแถว status-tabs แล้ว") -- the panel now always renders in normal
// document flow (header, then the collapsible body, then the always-visible footer) wherever the
// partial was included; a page with its own Status Tabs pipeline (e.g. Payroll Process) simply
// places this partial right after it in markup order instead. `$bar.find('.filter-bar-toolbar')`/
// `.filter-bar--toolbar-relocated` no longer exist anywhere in this function or in style.css.
function initFilterBar(bar, options) {
    options = options || {};
    const $bar = $(bar);
    if (!$bar.length) return;
    // 2026-09-13, real bug report: "× บน chip และ 'ล้างตัวกรอง' กดติดบ้างไม่ติดบ้าง" -- investigated
    // fresh (delegated binding was already correct from the previous round's fix, and there was only
    // ONE real call site for #runDetailFilterBar with its own module-level once-guard, so double-init
    // was NOT reproducible on the real page as shipped). Added anyway as a systemic guard rather than
    // trusting every future caller to remember its own once-guard the way payroll/detail.js's
    // `runDetailFilterBarInitialized` does -- a caller that accidentally calls this twice on the same
    // element (e.g. re-running page-init logic after an ajax reload, a mistake this app has hit before
    // elsewhere) would otherwise silently double-bind EVERY handler below (toggle, chip-remove, clear,
    // the change listener) without any visible error, and a doubled toggle handler is a textbook cause
    // of "click sometimes does nothing" -- 2 bound clicks flip `.collapsed` on then immediately back
    // off in the same tick. Scoped to the element itself (jQuery `.data()`), not a module-level flag,
    // so it correctly still allows 2 SEPARATE filter-bar instances on the same page (e.g.
    // components.php's own #cpFilterBarDemo + #cpFullFilterBar) to each init once.
    if ($bar.data('filterBarInitialized')) return;
    $bar.data('filterBarInitialized', true);
    const $fields = $bar.find('.filter-bar-body');
    const $countWrap = $bar.find('.filter-bar-count-wrap');
    const $count = $bar.find('.filter-bar-count');
    const $clearBtn = $bar.find('.filter-bar-clear');
    const $chips = $bar.find('.filter-bar-chips');
    const $toggleBtn = $bar.find('.filter-bar-toggle');
    // 2026-09-13, real live-page feedback (Payroll Detail), explicit instruction: "ตอนกาง ซ่อน chips
    // เหลือแค่ปุ่ม 'ล้างตัวกรอง'...ย้ายไปอยู่แถวหัวขวา ข้าง chevron แล้วตัดแถวท้ายทิ้งตอนกาง" -- the whole
    // footer-relocation dance this function used to do (syncCollapsedLayout(), moving
    // .filter-bar-footer-left between the header and a separate footer row on every expand/collapse)
    // is GONE now -- .filter-bar-chips lives permanently inside .filter-bar-header (filter-bar.php),
    // and its own visibility by collapse state is pure CSS
    // (`.filter-bar:not(.collapsed) .filter-bar-chips { display:none }`) needing zero JS. The Clear
    // button also lives permanently in the header now (no footer left for it to have ever needed
    // moving out of). Simpler and more robust than the relocation approach: nothing here ever gets
    // reparented, so there's nothing that CAN break the way the direct-click-binding bug did.
    //
    // 2026-09-12, Round 2 item 4 revision -- expand/collapse persistence (§6 decision 4). Uses the
    // SAME plain CSS-class collapse mechanism the OLD `.station-filter` already used
    // (`.collapsed` + a max-height transition in style.css) rather than Bootstrap's own `.collapse`
    // component, per "ตอนกาง = grid แบบ .station-filter เดิมเป๊ะ" -- no `data-bs-toggle` wiring needed.
    // `pageKey` (optional, read from the partial's own `data-page-key` attribute) scopes the
    // localStorage key so 2 different filter bars on 2 different pages -- or 2 tabs' worth on the
    // SAME page, each with its own `$id`/`$pageKey` -- never clobber each other's remembered state.
    // No `pageKey` at all = never persisted, always starts collapsed (the partial's own static
    // markup already renders with the `.collapsed` class by default).
    const pageKey = $bar.data('page-key');
    const storageKey = pageKey ? ('filterbar:' + pageKey) : null;
    let saved = null;
    if (storageKey) {
        try { saved = localStorage.getItem(storageKey); } catch (e) {}
    }
    if (saved === 'expanded') $bar.removeClass('collapsed');
    else if (saved === 'collapsed') $bar.addClass('collapsed');
    else {
        // 2026-09-15: nothing remembered for this pageKey yet (or the bar has no pageKey at all, so
        // nothing ever is) -- the FIRST state follows the viewport instead of always starting
        // collapsed: open on a screen wide enough to show the grid without pushing the table off
        // the fold (>= lg, 992px, the same breakpoint the filter grid's own columns use), closed
        // below it. Read once, here: a user resizing mid-session keeps whatever state they are
        // looking at, and the moment they toggle it themselves that choice is what persists.
        $bar.toggleClass('collapsed', !window.matchMedia('(min-width: 992px)').matches);
    }
    // 2026-09-13: the toggle is now a single `.btn-icon` circle (§7's row-action spec, reused here
    // per explicit instruction -- "ปุ่ม .btn-icon วงกลมเดียวกับ row action") whose chevron rotates via
    // a plain CSS rule keyed off `.filter-bar:not(.collapsed) .filter-bar-toggle i` -- no JS needed
    // to flip the icon itself, only the `.collapsed` class toggle below (which the chips' own
    // collapsed-only visibility CSS also keys off of, same class, no separate JS state to track).
    //
    // 2026-09-16: the expand/collapse zone is the WHOLE header bar (rules.md §6), not just the
    // chevron circle + label it was narrowed to in 2026-09-13. What made that narrowing necessary --
    // the header's own chips/Clear button sitting inside the same row -- is handled at the source
    // instead: each of those stops propagation in its own delegated handler below, and the guard
    // here additionally ignores anything originating inside a control zone (including the optional
    // `$header_extra_html` slot, whose contents this component does not own and cannot assume about).
    const $header = $bar.find('.filter-bar-header');
    $header.on('click', function (e) {
        if ($(e.target).closest('.filter-bar-chips, .filter-bar-header-right').length) return;
        $bar.toggleClass('collapsed');
        if (storageKey) {
            try { localStorage.setItem(storageKey, $bar.hasClass('collapsed') ? 'collapsed' : 'expanded'); } catch (e2) {}
        }
    });
    // The toggle circle itself lives inside `.filter-bar-header-right` (a control zone the guard
    // above skips), so it keeps its own binding.
    $bar.find('.filter-bar-toggle').on('click', function () {
        $bar.toggleClass('collapsed');
        if (storageKey) {
            try { localStorage.setItem(storageKey, $bar.hasClass('collapsed') ? 'collapsed' : 'expanded'); } catch (e2) {}
        }
    });

    // 2026-09-13, real bug fixed (explicit repro: select 2 fields -> × on the 2nd (static) works, ×
    // on the 1st (select2-remote, "แผนก") does nothing at all; "ล้างตัวกรอง" only clears the static
    // one; a lone remote selection -> × never works AT ALL) -- root cause confirmed by reading
    // input.js's own initSelect2() ajax branch: a select2-remote field's underlying `<select>` never
    // carries a baked-in "all" placeholder `<option>` the way a static/native field's markup always
    // does (`#rdDepartmentFilter`'s own markup is a bare `<select ...></select>`, zero options) --
    // select2 only ever appends ONE `<option>` dynamically, for whatever value the user actually
    // picked. The OLD resetSelect() (below) always reset via `.find('option').first()` -- for a
    // remote field that's the SAME option that's currently selected, so "reset" just set the value
    // back to itself, a true no-op. This function reads each field's own "no filter" sentinel via
    // defaultValueFor() instead of assuming 'all' everywhere -- an optional `data-filter-default`
    // attribute on the `<select>` wins if present (filter-bar.php's own docblock documents this),
    // else falls back by field TYPE: `.select2-remote` -> '' (matches how a cleared remote field's
    // `.val()` reads once it truly has no options left), everything else -> 'all' (unchanged from
    // before, matches every existing static/native field's own markup convention).
    function defaultValueFor($select) {
        const explicit = $select.attr('data-filter-default');
        if (explicit !== undefined) return explicit;
        return $select.hasClass('select2-remote') ? '' : 'all';
    }
    // 2026-09-23, 3e-3b round B5: `input.form-control` fields (a date-range filter, e.g. Action
    // History's own #auditLogFilterBar -- no `<select>` semantics apply to those at all) branch off
    // FIRST, before any of the `<select>`-only logic below runs; the 3 original lines that follow
    // are otherwise untouched.
    function isActive($select) {
        if (!$select.is('select')) return (($select.val() || '') + '').trim() !== '';
        const val = $select.val();
        if (val === null || val === '') return false;
        return val !== defaultValueFor($select);
    }
    function resetSelect($select) {
        // .trigger('change') (not '.select2') is deliberate -- select2 itself listens for the plain
        // native 'change' event to refresh its own displayed text, the same convention this app's
        // language switcher already relies on elsewhere; it is also what re-fires the delegated
        // handler below, which is the ONE place refresh()/onChange() actually get called from (see
        // scheduleNotify()) -- resetSelect() itself never calls either directly.
        if ($select.hasClass('select2-remote')) {
            // Remove every option select2 appended FIRST, returning the field to the exact same
            // empty-<select> state its own markup started in, THEN clear the value -- doing it in
            // this order (rather than clearing first) means there is never a moment where a stale,
            // no-longer-selected `<option>` is the only thing left in the DOM for some other code to
            // stumble on (e.g. a future `.find('option').first()` elsewhere). `.val(null)` on an
            // option-less `<select>` is the correct select2 v4 API for "nothing selected" (confirmed
            // against this app's own established Select2 v4 convention -- see this file's CLAUDE.md
            // section -- v4 has no separate `.select2('val')` call).
            $select.find('option').remove();
            $select.val(null).trigger('change');
            return;
        }
        const def = defaultValueFor($select);
        const $defaultOption = $select.find('option[value="' + CSS.escape(def) + '"]');
        const $target = $defaultOption.length ? $defaultOption : $select.find('option').first();
        $select.val($target.length ? $target.val() : '').trigger('change');
    }
    // 2026-09-23, 3e-3b round B5: the ONE new branch point for a plain `input.form-control` field --
    // resetSelect() itself is untouched above (still exactly what it was), called from here unchanged
    // for anything that IS a `<select>`. An input has none of resetSelect()'s own option-list
    // machinery to worry about -- blank + the same real `change` event every reset here fires, which
    // is what scheduleNotify() (below) is listening for either kind of field on.
    function resetField($field) {
        if (!$field.is('select')) {
            $field.val('').trigger('change');
            return;
        }
        resetSelect($field);
    }
    // 2026-09-13: chip text widened from value-only to "label: ค่า" -- the field's own <label> (a
    // SIBLING of the <select>, per this partial's own docblock convention every existing
    // `.station-filter-body` field already follows) gives the chip context on its own, without
    // requiring a glance back at which column it came from.
    function fieldLabelFor($select) {
        return (($select.siblings('label').first().text() || '').trim());
    }
    // 2026-09-23, 3e-3b round B5: a select's own chip VALUE text has always come from its selected
    // `<option>` (falling back to the raw `.val()` only when that lookup finds nothing); an input
    // has no options at all, so its own `.val()` -- already exactly what the field displays, e.g.
    // "23/09/2026" from a datepicker -- IS the chip value, directly.
    function fieldValueLabel($select) {
        if (!$select.is('select')) return String($select.val() || '');
        return (($select.find('option:selected').text() || '').trim()) || String($select.val());
    }
    // 2026-09-13, real bug found and fixed (explicit report: "ปุ่ม 'ล้างตัวกรอง' และ × บน chip กดแล้วไม่
    // ทำงาน") -- both were bound DIRECTLY (`$clearBtn.on('click', ...)`, `$chip.find(...).on('click', ...)`)
    // to a specific node reference captured at ONE point in time: `$clearBtn` at `initFilterBar()`'s own
    // init, and each chip's own remove button freshly at every `refresh()` (chips are torn down via
    // `$chips.empty()` and rebuilt from scratch on every filter change). A direct binding is only ever
    // as reliable as "this exact node is still the one in the DOM" -- true for `$clearBtn` itself, but
    // this panel's OWN `syncCollapsedLayout()` above already reparents a SIBLING node
    // (`.filter-bar-footer-left`) on every expand/collapse, and the whole POINT of a shared, reusable
    // helper like this one is that a future caller's markup/JS around it can change in ways this
    // function's own author can't fully predict -- direct bindings are fragile in exactly that
    // scenario. Rebuilt both as DELEGATED bindings on `$bar` itself (the one node in this whole panel
    // that is genuinely never replaced, moved, or recreated by anything here), which keeps working
    // regardless of how many times the chips/footer/header get rebuilt or reparented around it -- this
    // is the standard, correct jQuery pattern for a click target that may not exist yet (or may be
    // replaced later), not a page-specific patch. A chip's own remove button no longer closes over its
    // `$select` (delegation has no per-chip closure to rely on) -- `data-target` on the chip itself
    // (the filter field's own `id`, required from here on for any field used with this partial) is
    // looked up by id instead.
    // 2026-09-23, 3e-3b round B5: `$allSelects` -> `$allFields`, selector widened to also match
    // `input.form-control` -- the ONE combining point this whole function needed; every call below
    // that already just invoked `isActive($(this))`/etc. needed no further change, since those
    // helpers now branch by element type internally.
    function refresh() {
        const $allFields = $fields.find('select, input.form-control');
        const $active = $allFields.filter(function () { return isActive($(this)); });
        const n = $active.length;
        $count.text(n);
        $countWrap.toggleClass('d-none', n === 0);
        $clearBtn.toggleClass('d-none', n === 0);
        // Below `sm` this button is the icon alone, so its accessible name comes from title/
        // aria-label -- updateText() writes `title` from data-i18n-title, and the two are kept in
        // step here rather than adding a second i18n attribute convention for one element.
        $clearBtn.attr('aria-label', $clearBtn.attr('title') || getLangValue('filter_clear') || 'Clear filters');
        // 2026-09-13, explicit instruction: "ช่องที่มีค่า: ขอบ --c-border-strong ให้เห็นว่าไม่ใช่ default
        // โดยไม่ต้องพึ่ง chips" -- chips are now hidden while the panel is EXPANDED (see the CSS this
        // function's own docblock references), i.e. exactly the state where the fields themselves are
        // the only thing visible -- so each field's own wrapping column div (its `<select>`'s direct
        // parent, per this partial's own docblock contract) gets `.filter-bar-field-active` toggled
        // onto it directly, independent of collapse state, so a filled-in field still visibly reads as
        // "not default" even with no chip anywhere to say so.
        $allFields.each(function () {
            $(this).parent().toggleClass('filter-bar-field-active', isActive($(this)));
        });
        $chips.empty();
        $active.each(function () {
            const $select = $(this);
            const fieldLabel = fieldLabelFor($select);
            const valueLabel = fieldValueLabel($select);
            // 2026-09-13, explicit instruction: "chips...ตัวหนังสือ --c-text-muted ค่าเป็น --c-text
            // (label: ค่า)" -- label and value are now 2 separate spans (were one plain text node) so
            // each half can carry its own color via CSS (.filter-bar-chip-label/-value, style.css)
            // instead of the whole chip being one flat color.
            const labelHtml = fieldLabel ? `<span class="filter-bar-chip-label">${escapeHtml(fieldLabel)}: </span>` : '';
            const $chip = $(`<span class="filter-bar-chip" data-target="${escapeAttr($select.attr('id') || '')}">${labelHtml}<span class="filter-bar-chip-value">${escapeHtml(valueLabel)}</span><button type="button" class="filter-bar-chip-remove" aria-label="Remove filter"><i class="fa-solid fa-xmark"></i></button></span>`);
            $chips.append($chip);
        });
    }
    // Debounced via setTimeout(0): the Clear button resets every active <select> in one synchronous
    // loop, each call to resetSelect() firing its own native 'change' event -- without collapsing
    // those into a single tick, options.onChange() (typically "reload the table") would fire once
    // PER select cleared instead of once for the whole Clear action.
    let notifyTimer = null;
    function scheduleNotify() {
        clearTimeout(notifyTimer);
        notifyTimer = setTimeout(function () {
            refresh();
            if (typeof options.onChange === 'function') options.onChange();
        }, 0);
    }
    // 2026-09-23, 3e-3b round B5: widened the same way refresh()'s own selector was, above.
    $fields.on('change', 'select, input.form-control', scheduleNotify);
    // e.stopPropagation() on both -- see the toggle-zone comment above; these buttons sit right next
    // to the widened toggle zone in the header, so a click on either must never also be interpreted
    // as a click on an ancestor toggle target.
    $bar.on('click', '.filter-bar-chip-remove', function (e) {
        e.stopPropagation();
        const targetId = $(this).closest('.filter-bar-chip').data('target');
        if (targetId) resetField($fields.find('#' + CSS.escape(String(targetId))));
    });
    // The same routine the Clear button runs, reachable from outside the panel (the table's own
    // empty state calls it -- see clearAllTableFilters()). Stored on the element, not in a module
    // registry, so it lives and dies with the bar itself.
    function clearAllFields() {
        // 2026-09-13, explicit instruction: snapshot the field list BEFORE iterating (`.toArray()`
        // materializes it once, up front -- jQuery's own `.find()` result is already a static
        // array-like snapshot, not a live NodeList, but made explicit here rather than relying on
        // that implicit guarantee) and never let ONE field's own reset throwing abort the rest of the
        // loop -- a select2-remote field's DOM manipulation (resetSelect()'s own `.find('option')
        // .remove()` branch above) is exactly the kind of operation that could throw on a field in an
        // unexpected state, and one bad field must not leave every field after it in the loop
        // un-cleared. scheduleNotify()'s shared debounce timer still guarantees refresh()/onChange()
        // fire exactly once after the whole loop finishes (every reset call below runs
        // synchronously within this same tick, so only the LAST scheduled setTimeout(0) survives) --
        // unchanged, already correct before this round.
        // 2026-09-23, 3e-3b round B5: `const selects` -> `const fields` (widened selector), `resetSelect`
        // -> `resetField` in the loop -- resetField() itself still calls resetSelect() unchanged for
        // anything that IS a `<select>`, so a select's own clear behaviour here is byte-for-byte the
        // same call it always was, just one level deeper.
        const fields = $fields.find('select, input.form-control').toArray();
        fields.forEach(function (el) {
            try {
                resetField($(el));
            } catch (err) {
                console.error('[filter-bar] resetField() failed for one field during "ล้างตัวกรอง" -- continuing with the rest', el, err);
            }
        });
    }
    $bar.data('filterBarClear', clearAllFields);
    $bar.on('click', '.filter-bar-clear', function (e) {
        e.stopPropagation();
        clearAllFields();
    });
    refresh();
}
// 2026-09-12, Phase Design Round 2 item 4b (docs/design/rules.md §6) -- pairs with
// app/views/partials/status-tabs.php. Replaces the DUPLICATED chevron pipeline markup
// (.station-row/.station-card) Employee List (#employeeStationRow) and Payroll Process (#stationRow)
// both hand-roll today with ONE shared partial+helper -- the chevron VISUAL itself is kept (retokenized,
// not replaced with plain underline tabs -- an earlier version of this function did that, reverted
// after review: "คงรูปแบบ chevron pipeline ตามที่ approve แล้ว").
//
// Investigated first (explicit instruction), not assumed: the 2 pages' CURRENT count sources do NOT
// share one shape or mechanism --
//   - Employee List: 1 server round trip, POST api/employee.station-counts, returns a flat
//     {active, probation, permanent, resigned} object -- required because #tb_employee is
//     serverSide:true, so the client never holds every row to count client-side.
//   - Payroll Process: NO server call for most states at all -- updateStationCounts()
//     (public/js/payroll/index.js) counts client-side from tb_payroll_run's own already-loaded rows
//     (that table is NOT serverSide) into {draft, pending_approval, approved, paid, locked, rejected,
//     need_info, cancelled}; `pending_sync` specifically is populated from a SEPARATE mechanism
//     entirely (loadPendingSyncCount()/its own bulk-pull list), since sync-pending items were never
//     part of tb_payroll_run's own dataset to begin with.
// Proposed single shape (not enforced by changing either page this round -- round 2 doesn't touch
// real pages): both pages' FINAL result already naturally reduces to the exact same thing, a flat
// {key: count} object -- they only differ in HOW they arrive at it (a server aggregate is the only
// option for a serverSide:true table; a client-side tally is strictly cheaper when the table already
// holds every row). This function's own update() method is the ONE shared contract going forward --
// it accepts that same flat shape regardless of which path a given page used to build it, so neither
// page needs to change ITS OWN counting mechanism to adopt this component, only the rendering.
//
// initStatusTabs(el, {onChange}) wires click-to-select (toggles `.active` among every
// `.status-tab-btn` inside `el`) and returns { update(counts) } for the caller to push fresh counts
// into any time (load/redraw/after sync/...). A second, flat "path" visual variant existed alongside
// the chevron shape for an explicit A/B comparison in components.php -- decided, chevron won, 'path'
// removed entirely (status-tabs.php/style.css/components.php) -- this function needed no change for
// that removal since it was already variant-agnostic (reads only data attributes, never a CSS class).
//
// Color rules applied by applyStateClasses() (see status-tabs.php's own docblock for the full
// reasoning) -- shared by BOTH update() and the click handler (see the real bug this fixes, below):
//   - idle + tone neutral/success, OR idle + count===0: plain gray pill, no extra class.
//   - idle + tone warning/danger + count>0: pill becomes a real `.badge.badge-{tone}` (§5) --
//     "something to act on" should stand out even while that step isn't the one being viewed. This
//     is the ONLY place 'tone' drives idle rendering -- 'cancelled' with tone='neutral' NEVER gets a
//     colored idle pill no matter its count, on purpose (nothing left to act on once cancelled).
//   - active + direction 'forward': no tone class added at all -- CSS renders the plain brand-orange
//     "selected" look regardless of this tab's own tone (a forward step never turns red/amber just
//     because it happens to carry a warning tone).
//   - active + direction 'back' (rejected/need-info/cancelled-style exception step): adds
//     `.status-tab-tone-{tone}` to the button, using 'tone' when it's warning/danger, but FALLING
//     BACK to danger when 'tone' is neutral/success (cancelled -> danger even though its own idle
//     'tone' is neutral -- a reversed step you're actively looking at should always read as serious,
//     even one whose idle badge is deliberately muted). 'chevron' variant CSS recolors the WHOLE
//     card to that tone instead of orange; 'path' variant CSS recolors only the count pill (the
//     label text stays plain bold `--c-text`).
//
// Real bug fixed here: an earlier version only ever computed the active-tone class inside update(),
// which runs once when the caller pushes counts -- clicking a DIFFERENT tab afterward only toggled
// `.active` and never re-ran that logic, so a reversed step you just clicked into stayed brand-orange
// instead of turning red/amber until the NEXT update() call happened to fire. Fixed by factoring the
// per-tab class logic into applyStateClasses() (reads a closure-cached `lastCounts`) and calling it
// from both update() AND the click handler, so clicking alone is always enough to reflect the correct
// color immediately.
function initStatusTabs(el, options) {
    options = options || {};
    const $tabs = $(el);
    if (!$tabs.length) return { update: function () {} };
    let lastCounts = {};
    function applyStateClasses() {
        $tabs.find('.status-tab-btn').each(function () {
            const $btn = $(this);
            const key = $btn.data('status-key');
            const tone = $btn.data('tone') || 'neutral';
            const isBack = $btn.data('direction') === 'back';
            const isActive = $btn.hasClass('active');
            const count = lastCounts[key] || 0;
            const $count = $btn.find('.status-tab-count');
            $count.text(count);
            $count.removeClass('badge badge-warning badge-danger');
            $btn.removeClass('status-tab-tone-warning status-tab-tone-danger');
            if (!isActive && count > 0 && (tone === 'warning' || tone === 'danger')) {
                $count.addClass('badge badge-' + tone);
            }
            if (isActive && isBack) {
                const activeTone = (tone === 'warning' || tone === 'danger') ? tone : 'danger';
                $btn.addClass('status-tab-tone-' + activeTone);
            }
        });
    }
    $tabs.find('.status-tab-btn').on('click', function () {
        $tabs.find('.status-tab-btn').removeClass('active');
        $(this).addClass('active');
        applyStateClasses();
        if (typeof options.onChange === 'function') options.onChange($(this).data('status-key'));
    });
    function update(counts) {
        lastCounts = counts || {};
        applyStateClasses();
    }
    return { update: update };
}
// 2026-09-13, Phase Design Round 2 item 5 (docs/design/rules.md §5) -- app/config/status_map.php is
// the ONE AND ONLY source of this data now. An earlier version of this had a full hand-kept JS COPY
// of that file's array here (since JS can't `require` a PHP file, and Round 2's own file scope
// otherwise rules out touching a real page template like layout/header.php) -- reverted in favor of
// this single-source approach the moment an in-scope exception was explicitly approved: header.php
// (the same spot that already bridges BASE_URL/LANG_VERSION from PHP to JS) now injects
// `window.STATUS_MAP = <?php echo json_encode(loadStatusMap()) ?>;` directly from the real
// status_map.php on every page, so there is no second copy left anywhere to drift out of sync.
// A page that doesn't load header.php at all (or loads app.js before that script runs) falls back
// to an empty map -- every lookup then misses, which getStatusMapEntry()/statusBadgeHtml() already
// treat as "render neutral + warn" on their own, so nothing here needs a special empty-map branch
// beyond this one console.warn() flagging WHY every badge on that page is about to look unmapped.
const STATUS_MAP = (typeof window !== 'undefined' && window.STATUS_MAP) ? window.STATUS_MAP : (function () {
    console.warn('STATUS_MAP is missing (window.STATUS_MAP was not set) -- this page likely does not load layout/header.php, or loads app.js before that script runs. Every statusBadgeHtml() call on this page will fall back to a neutral badge with the raw enum as its label.');
    return {};
})();
// Raw lookup -- mirrors PHP's own statusMapEntry(), same reason it exists as its own function
// separate from statusBadgeHtml() below: status-tabs.php's own caller needs the raw tone/direction
// pair to build its $tabs array, not a rendered `<span class="badge">` (its pill is a plain colored
// number, no label text to duplicate).
function getStatusMapEntry(enumValue, context) {
    return (STATUS_MAP[context] && STATUS_MAP[context][enumValue]) || null;
}
// The ONE JS way to render a status badge -- docs/design/rules.md §5. Unlike PHP's statusBadge()
// (which can only ever render a static English fallback -- see that function's own docblock), this
// resolves the CURRENTLY ACTIVE language directly via getLangValue() (already loaded into `langData`
// by the time any caller would run this, same as every other JS-rendered i18n string in this app) --
// still carries `data-i18n` on the span too, purely so a LIVE language switch (no page reload) picks
// it up via updateText()'s own DOM re-scan, consistent with how every other i18n span in this app
// already behaves, not because this function itself needs it to render correctly the first time.
// An enum/context combination not found in STATUS_MAP renders as a plain neutral badge with the RAW
// enum value as its label and a console.warn() so the gap is visible to whoever's looking, without
// throwing and breaking whatever table/card row it was rendering for. `data-badge="status"` (both
// branches) is the marker §12's own lint rule #8 checks for -- present from day one, see PHP's
// statusBadge() own docblock for the full reasoning (identical here).
// 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "'ตรวจสอบแล้ว': badge success + ไอคอน
// ▾ เล็กต่อท้าย (ใน badge เดียวกัน) กดแล้วเปิด dropdown...ทำเป็น option ของ statusBadgeHtml({menu:[...]})
// ไม่เขียนเฉพาะที่นี่" -- `options.menu` (optional, 3rd param) is a caller-supplied raw HTML string of
// `<li>` items (this function stays generic -- it has no idea what "unverify" even means, the SAME
// separation of concerns every other menu-building function in this app already keeps between "how
// to render a dropdown" and "what goes in THIS one"). When present, the badge itself becomes a real
// `<button>` (not a `<span>` -- needs to be focusable/clickable for Bootstrap's own dropdown JS +
// keyboard use) styled with the SAME `.badge.badge-{tone}` classes (button.badge's own CSS reset
// clears the browser's default button chrome so it still reads as a badge, not a button) plus
// `dropdown-toggle` for the small ▾ caret Bootstrap's own CSS already draws via `::after` on that
// class, element-agnostic. No menu = the exact same plain `<span>` badge as before, byte-identical to
// every existing call site.
// 2026-09-15, Round 3 (comment-list restyle item 4) -- 2 additions, both generalizations rather than
// new behavior: `options.outline` renders the SAME badge in outline form (transparent fill +
// `currentColor` border, `.badge-outline` in style.css) for the places a badge is an ENTRY IN A LIST
// OF CHOICES rather than a statement of current state (badgeDropdownHtml()'s own menu items below);
// and `options.menu` now delegates to badgeDropdownHtml() instead of building the dropdown markup
// itself, so the "badge that opens a menu" shape exists in exactly one place (this call's own output
// is byte-identical to what it built inline before -- the verify-status badge in Payroll Detail's
// table, its only caller, is unchanged).
function statusBadgeHtml(enumValue, context, options) {
    options = options || {};
    const entry = getStatusMapEntry(enumValue, context);
    const outlineCls = options.outline ? ' badge-outline' : '';
    if (!entry) {
        console.warn(`status_map: missing enum '${enumValue}' for context '${context}'`);
        return `<span class="badge badge-neutral${outlineCls}" data-badge="status">${escapeHtml(enumValue)}</span>`;
    }
    const tone = entry.tone || 'neutral';
    const label = getLangValue(entry.label_key) || entry.label_key;
    if (options.menu) {
        return badgeDropdownHtml({ enum: enumValue, context: context, outline: options.outline, menuHtml: options.menu });
    }
    return `<span class="badge badge-${tone}${outlineCls}" data-badge="status" data-i18n="${escapeHtml(entry.label_key)}">${escapeHtml(label)}</span>`;
}
// Badge dropdown (rules.md §5's own "Badge dropdown" block) -- a status badge that IS a dropdown
// toggle: the badge shows the current value, a small ▾ (Bootstrap's own `.dropdown-toggle::after`)
// says it can be changed/acted on, and the menu below it holds either ACTIONS or the other VALUES to
// choose from. Extracted 2026-09-15 from statusBadgeHtml()'s own `{menu}` branch (2026-09-13, built
// for Payroll Detail's verify-status badge) so the same shape can serve a second, genuinely
// different caller -- the comment composer's own tag picker -- instead of being copied (§0.4).
//
// Two modes, by which field the caller passes:
//   `menuHtml` (raw `<li>` string)  = ACTION menu. The caller owns the items AND their click
//        handlers entirely; this function has no idea what they do. Payroll Detail's verify badge
//        ("ยกเลิกการตรวจสอบ") is this mode, via statusBadgeHtml({menu}) above.
//   `options: [{value, enum, outline?}]` = VALUE PICKER. Renders one menu row per choice, each row
//        being that choice's own status badge (always OUTLINE -- a menu row is a choice, not a
//        statement of current state) plus a gray ✓ at the end of the row that is the current value.
//        A hidden `<input name>` carries the value so a normal form read (and §9's own
//        snapshotFormState() dirty guard, which keys off name/id) sees it like any other field.
//        `outline: true` on a choice means "when THIS one is current, the toggle itself renders
//        outline too" -- the comment tag picker uses it for its "no tag" entry, so an untagged
//        comment's picker reads as an empty/neutral control rather than a filled gray badge.
//   Behavior for the picker mode lives in initBadgeDropdown() below (this function only renders).
//
// config = { enum (required), context (required), outline?, menuHtml?, options?, value?, name?,
//   id?, toggleClass? }
function badgeDropdownHtml(config) {
    config = config || {};
    const entry = getStatusMapEntry(config.enum, config.context);
    const tone = (entry && entry.tone) || 'neutral';
    const labelKey = entry ? entry.label_key : '';
    // 2026-09-16: `config.label` (a caller-resolved string, usually one holding a count like
    // "แก้ไข 3") replaces the map's own label for a toggle whose text is templated and so cannot come
    // from status_map at all -- when it is used, `data-i18n` is dropped too, since re-sweeping it
    // would overwrite the number with the bare label.
    const label = config.label || (entry ? (getLangValue(entry.label_key) || entry.label_key) : config.enum);
    const outlineCls = config.outline ? ' badge-outline' : '';
    const i18nAttr = (labelKey && !config.label) ? ` data-i18n="${escapeAttr(labelKey)}"` : '';
    const idAttr = config.id ? ` id="${escapeAttr(config.id)}"` : '';
    const toggleClass = config.toggleClass ? ' ' + config.toggleClass : '';
    let menuHtml = config.menuHtml || '';
    let hiddenInputHtml = '';
    if (config.options) {
        const currentValue = config.value === undefined || config.value === null ? '' : String(config.value);
        menuHtml = config.options.map(function (opt) {
            const value = opt.value === undefined || opt.value === null ? '' : String(opt.value);
            const selected = value === currentValue;
            return `<li><button type="button" class="dropdown-item badge-dropdown-item${selected ? ' is-selected' : ''}"`
                + ` data-value="${escapeAttr(value)}" data-outline="${opt.outline ? '1' : '0'}" aria-selected="${selected ? 'true' : 'false'}">`
                + statusBadgeHtml(opt.enum, config.context, { outline: true })
                + '<i class="fa-solid fa-check badge-dropdown-check"></i></button></li>';
        }).join('');
        if (config.name) {
            hiddenInputHtml = `<input type="hidden" name="${escapeAttr(config.name)}" id="${escapeAttr(config.name)}" value="${escapeAttr(currentValue)}">`;
        }
    }
    return `<div class="dropdown d-inline-block badge-dropdown" data-badge-dropdown>
        ${hiddenInputHtml}
        <button type="button"${idAttr} class="badge badge-${tone}${outlineCls} dropdown-toggle badge-dropdown-toggle${toggleClass}" data-badge="status" data-bs-toggle="dropdown" aria-expanded="false"${i18nAttr}>${escapeHtml(label)}</button>
        <ul class="dropdown-menu">${menuHtml}</ul>
    </div>`;
}
// Wires badgeDropdownHtml()'s VALUE-PICKER mode inside `scope` (a selector/element/jQuery object):
// picking a row updates the hidden input, restyles the toggle to that choice's own badge, moves the
// ✓, and (optionally) calls `options.onSelect(value, $dropdown)`.
//
// DELEGATED from `scope`, not bound per dropdown, and guarded so calling it twice on the same scope
// can't stack handlers -- the real caller (#employeeCommentModal) re-renders its composer and its
// whole comment list many times per open, so any per-element binding would be lost on the first
// re-render (the same reasoning initFilterBar()'s own delegated binding documents, which was itself
// the fix for a real "button stops working after a refresh" bug).
//
// The toggle's new look is copied off the chosen row's OWN badge (tone class + label + `data-i18n`)
// rather than re-derived from STATUS_MAP here -- the row was already rendered through
// statusBadgeHtml(), so copying it keeps the two visually identical by construction, and carrying
// `data-i18n` across means a live language switch relabels the toggle too, for free.
//
// Keyboard comes from Bootstrap's own dropdown component (Esc closes and returns focus to the
// toggle, ↑/↓ move between `.dropdown-item`s, Enter/Space activates the focused one) -- which is
// exactly why every row is a real `<button class="dropdown-item">` and not a styled `<div>`/`<a>`.
function initBadgeDropdown(scope, options) {
    options = options || {};
    const $scope = $(scope);
    if (!$scope.length || $scope.data('badgeDropdownInitialized')) return;
    $scope.data('badgeDropdownInitialized', true);
    $scope.on('click', '.badge-dropdown-item', function () {
        const $item = $(this);
        const $dropdown = $item.closest('[data-badge-dropdown]');
        const $toggle = $dropdown.find('.badge-dropdown-toggle').first();
        const $badge = $item.find('.badge').first();
        const value = $item.attr('data-value') || '';
        const toneClass = ($badge.attr('class') || '').split(/\s+/).find(c => c.indexOf('badge-') === 0 && c !== 'badge-outline') || 'badge-neutral';
        $dropdown.find('input[type="hidden"]').val(value);
        $dropdown.find('.badge-dropdown-item').removeClass('is-selected').attr('aria-selected', 'false');
        $item.addClass('is-selected').attr('aria-selected', 'true');
        // Swap ONLY the tone/outline classes -- never `.attr('class', ...)` the whole attribute.
        // 2026-09-15, real bug found in Playwright and fixed here: rewriting the attribute wholesale
        // also wiped the `show` class Bootstrap puts on an OPEN dropdown's toggle, and Bootstrap's own
        // clearMenus() finds open dropdowns by exactly that selector ('[data-bs-toggle="dropdown"].show')
        // -- so after picking a value the menu could never be closed again by any click, anywhere.
        $toggle
            .removeClass('badge-neutral badge-warning badge-danger badge-success badge-outline')
            .addClass(toneClass + ($item.attr('data-outline') === '1' ? ' badge-outline' : ''));
        $toggle.text($badge.text());
        const labelKey = $badge.attr('data-i18n');
        if (labelKey) $toggle.attr('data-i18n', labelKey); else $toggle.removeAttr('data-i18n');
        if (typeof options.onSelect === 'function') options.onSelect(value, $dropdown);
    });
}
// Count badge (§5, Round 3 item 3b) -- a plain NUMBER shown as a small pill, e.g. "how many items were
// adjusted" or "how many comments exist" -- a genuinely different thing from statusBadgeHtml() above
// (which always renders an ENUM value's own fixed label): a count is caller-supplied data, not looked
// up from status_map.php, so this takes the number directly rather than an (enum, context) pair.
// Reuses the SAME `.badge.badge-{tone}` CSS statusBadgeHtml() already defines (§5's own tone
// vocabulary), just with the raw number as content and no data-i18n (there's no translatable label
// here, the digits are the whole content). Default tone is 'neutral' per this app's own decided rule
// ("count badge เป็น neutral เสมอ ยกเว้นระบุ tone เมื่อต้องสนใจ") -- a caller passes `{tone:'warning'}`
// etc. only when the count itself is something that needs attention, not merely informational.
// 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "count badge บนปุ่มวงกลม: neutral (เทา)
// โดย default, tone primary เมื่อมีรายการ 'ใหม่/ยังไม่อ่าน' เท่านั้น" -- for a count badge specifically
// overlaid on a `.btn-circle-action` row-action button (style.css's own `.btn-circle-action-badge`
// overlay position), `{tone:'primary'}` is reserved for "this count includes something new/unread the
// viewer hasn't seen yet," never for "this count is just large" or "this thing has data at all" --
// the row-action ICON itself always stays the single flat `--c-text-muted` §7 already mandates
// regardless of the badge's own tone (the badge, not the icon, carries the "new" signal). A caller
// with no real unread/new CONCEPT yet (e.g. Payroll Detail's own Comments count, see
// commentButtonRd() in payroll/detail.js) has nothing to pass 'primary' for and correctly stays at
// the plain neutral default until that concept exists.
function countBadgeHtml(n, options) {
    options = options || {};
    const tone = options.tone || 'neutral';
    // 2026-09-16: `options.label` is a caller-resolved i18n string containing `{n}` (e.g. "{n} คำเตือน")
    // for the case where the number alone does not say what it counts -- a count badge standing next
    // to a status badge in the same cell, rather than overlaid on a button that already names the
    // thing. Without it the badge stays exactly what it has always been: the bare number.
    const text = options.label ? String(options.label).replace('{n}', String(n)) : String(n);
    return `<span class="badge badge-${tone}" data-badge="count">${escapeHtml(text)}</span>`;
}
// Status stepper (§6, Round 2 item 6, extended 2026-09-13 Round 3 item 3a -- see
// status-stepper.php's own docblock for the full per-step date/tone shape and the branch-state
// caveat) -- JS twin of app/views/partials/status-stepper.php, same 2 arguments, byte-identical
// markup. Deliberately dumb: done/current/next is derived purely from each step's POSITION relative
// to `current` -- no state-machine awareness, no per-step action buttons. That richer logic stays
// exactly where it already lives, this file's own RUN_LIFECYCLE_STEPS/runLifecycleSteps()/
// computeRunLifecycleProgress() further below -- payroll/detail.js's renderProcessTimeline() calls
// THIS function, passing {label, date, tone} per step + a plain current index, same "caller resolves
// display values, this just lays them out" split as always. `tone` (2026-09-13 same-day follow-up,
// explicit instruction: "status-stepper รับ tone ของขั้นปัจจุบันจาก statusMapEntry(run_state)") only
// ever affects the step AT `current` -- a branch state (rejected/need_info/cancelled) overrides that
// one circle's color away from the default orange, looked up by the CALLER via
// getStatusMapEntry(state, 'run_state') (§5), never guessed/hardcoded here.
function renderStatusStepper(steps, current) {
    let html = '<ul class="status-stepper">';
    (steps || []).forEach(function (step, i) {
        const label = typeof step === 'object' && step !== null ? (step.label || '') : step;
        const date = typeof step === 'object' && step !== null ? (step.date || '') : '';
        const tone = typeof step === 'object' && step !== null ? (step.tone || '') : '';
        const isFinal = typeof step === 'object' && step !== null ? !!step.final : false;
        const isLive = typeof step === 'object' && step !== null ? !!step.live : false;
        const stepIcon = typeof step === 'object' && step !== null ? (step.icon || '') : '';
        let stateClass = 'status-stepper-step--next';
        let inner = '';
        if (i < current) {
            stateClass = 'status-stepper-step--done';
            inner = '<i class="fa-solid fa-check"></i>';
        } else if (i === current) {
            stateClass = 'status-stepper-step--current';
            // §6, 2026-09-13, item C follow-up: white 12px icon, bare glyph class prefixed with
            // `fa-solid` HERE (not stored with the prefix already) -- matches index.js's own
            // mini-timeline convention for this exact same RUN_LIFECYCLE_STEPS/
            // RUN_LIFECYCLE_BRANCH_INFO-sourced `icon` field, no mapping of its own in this function.
            if (stepIcon) inner = `<i class="fa-solid ${escapeHtml(stepIcon)} status-stepper-current-icon"></i>`;
        }
        const toneClass = (stateClass === 'status-stepper-step--current' && tone) ? ' status-stepper-tone-' + tone : '';
        const finalClass = (stateClass === 'status-stepper-step--done' && isFinal) ? ' status-stepper-step--final' : '';
        const liveClass = (stateClass === 'status-stepper-step--current' && isLive) ? ' stepper-current-live' : '';
        const dateHtml = (date && i <= current) ? `<span class="status-stepper-date">${escapeHtml(date)}</span>` : '';
        html += `<li class="status-stepper-step ${stateClass}${toneClass}${finalClass}${liveClass}">
            <span class="status-stepper-circle">${inner}</span>
            <span class="status-stepper-label">${escapeHtml(label)}</span>
            ${dateHtml}
        </li>`;
    });
    html += '</ul>';
    return html;
}
// Timeline (§6, Round 2 item (3)/6b) -- JS twin of app/views/partials/timeline.php, same 2 plain
// arguments. A vertical, arbitrary-length activity feed (audit log/approval history) the CALLER has
// already sorted newest-first -- this function never sorts/dedupes/groups beyond the literal
// `groupByDay` option below. NOT the payroll run's own 5-station spine (that's
// status-stepper.php/renderStatusStepper() above, a fixed small N of named milestones) -- a
// combined "stepper on top, timeline below" is exactly how the real Approval Timeline modal
// (payroll/detail.js's renderApprovalTimelineBody()) could look once migrated in round 4; this
// function does not touch that real modal's code at all, only demoed side-by-side in
// components.php.
//
// item = { time (a date/datetime string parseable by `new Date()`), actor?: {name, avatar},
//   title, detail? (1 line), badge?: {enum, context}, tone?: 'neutral'|'warning'|'danger'|'success'
//   (default 'neutral' -- dot color only) }. `time` renders as HH:MM (not date+day -- §6's own
// spec is literally "เวลา", the date is what the optional day-header groups by instead).
// 2026-09-16: both of these now go through formatDisplayDateTime() instead of parsing `value`
// themselves. Their own `new Date('YYYY-MM-DD HH:mm:ss'.replace(' ','T'))` read a stored timestamp as
// LOCAL time, while formatDisplayDateTime() -- the app's single date-display helper (CLAUDE.md, UI
// Convention) -- reads the same string as UTC and converts, so the very same `changed_at` printed one
// time inside a timeline and a different one everywhere else on the same page. One parser, one
// answer; the split day/time strings are just slices of its own 'dd/mm/yyyy HH:mm' output.
function timelineDisplayDateTime(value) {
    return value ? String(formatDisplayDateTime(value)) : '';
}
function timelineTimeOfDay(value) {
    const text = timelineDisplayDateTime(value);
    const m = /(\d{2}:\d{2})$/.exec(text);
    return m ? m[1] : escapeHtml(text);
}
function timelineDayLabel(value) {
    const text = timelineDisplayDateTime(value);
    const m = /^(\d{2}\/\d{2}\/\d{4})/.exec(text);
    return m ? m[1] : text;
}
function renderTimeline(items, options) {
    options = options || {};
    const groupByDay = !!options.groupByDay;
    const list = items || [];
    // How many entries each day header actually covers. Counted as a RUN, not as a total per date:
    // the caller owns the order (this function never sorts), so the same date reaching the list
    // twice is two groups, and each header must say the size of the group it opens -- not the sum of
    // every group that happens to share its date.
    const dayRunLength = {};
    if (groupByDay) {
        let runStart = 0;
        for (let i = 0; i <= list.length; i++) {
            const same = i < list.length && timelineDayLabel(list[i].time) === timelineDayLabel(list[runStart].time);
            if (!same) {
                dayRunLength[runStart] = i - runStart;
                runStart = i;
            }
        }
    }
    const dayCountTpl = getLangValue('timeline_day_count') || '{n} entries';
    let html = '<ul class="timeline">';
    let lastDayLabel = null;
    list.forEach(function (item, index) {
        if (groupByDay) {
            const dayLabel = timelineDayLabel(item.time);
            if (dayLabel !== lastDayLabel) {
                // An entry with no date of its own produces an empty header, hidden in CSS -- so it
                // gets no count either, or the header stops being empty and starts showing.
                const countHtml = dayLabel === ''
                    ? ''
                    : ` <span class="timeline-day-count">· ${escapeHtml(dayCountTpl.replace('{n}', String(dayRunLength[index] || 1)))}</span>`;
                html += `<li class="timeline-day-header">${escapeHtml(dayLabel)}${countHtml}</li>`;
                lastDayLabel = dayLabel;
            }
        }
        const tone = item.tone || 'neutral';
        const actorHtml = item.actor
            ? apvAvatarHtml(item.actor.name, 24, item.actor.avatar) + `<span>${escapeHtml(item.actor.name || '')}</span>`
            : '';
        const badgeHtml = item.badge ? `<div class="mt-1">${statusBadgeHtml(item.badge.enum, item.badge.context)}</div>` : '';
        // 2026-09-16: ONE optional raw-HTML slot per entry, rendered last. This deliberately reopens
        // something §6 closed ("item.actions ไม่มีอีกแล้ว") -- that removal was written when no caller
        // needed a per-entry action at all; the line-override history modal does (each past value has
        // its own "use this value" button), and the only alternative was a second hand-rolled list of
        // the same shape, which §0.4 forbids outright. Kept deliberately dumb: the component neither
        // knows nor binds anything, the caller owns the markup and its handler, exactly like
        // statusBadgeHtml()'s own `{menu}` option. `actionHtml` must be caller-built HTML, never user input.
        const actionHtml = item.actionHtml ? `<div class="timeline-action">${item.actionHtml}</div>` : '';
        const detailHtml = item.detail ? `<div class="timeline-detail">${escapeHtml(item.detail)}</div>` : '';
        html += `<li class="timeline-item">
            <span class="timeline-dot timeline-dot-${tone}"></span>
            <div class="timeline-head">
                <span class="timeline-actor">${actorHtml}</span>
                <span class="timeline-time">${timelineTimeOfDay(item.time)}</span>
            </div>
            <div class="timeline-title">${escapeHtml(item.title)}</div>
            ${detailHtml}
            ${badgeHtml}
            ${actionHtml}
        </li>`;
    });
    html += '</ul>';
    return html;
}
// 2026-09-14, Round 3 -- REVERTED back to its original Round 2 item (3)/6b shape (no `relativeTime`
// option, no `item.actions`, no `item.bodyHtml`/`.timeline-body-content` wrapper). Those 3 pieces
// were added 2026-09-14 for the Comments modal specifically (its own first real caller at the time)
// -- the Comments modal has since moved to its own dedicated shared component
// (renderCommentList(), directly below) that fits its actual shape (avatar+2-line comment, not a
// dot-and-line event log) far better than stretching Timeline to cover both. Confirmed via grep
// (both `public/js/` and `docs/design/components.php`) that NO other caller ever used
// relativeTime/actions/bodyHtml -- this revert is not a breaking change for anything real. See
// rules.md §6's own "Comment list" section (added alongside this component) for exactly where the
// line between the 2 components sits: Timeline = an arbitrary-length EVENT/audit log the caller
// already sorted, Comment list = a specific 2-line "who said what, when" shape with its own
// inline-edit affordance -- never force one component to do both jobs again.
//
// 2026-09-15, Round 3 (comment-list restyle) -- shared COMPOSER box, used by BOTH places a comment
// is ever typed: the always-present "write a new comment" box at the top of the list, and an
// EXISTING comment opened for inline edit (which becomes this exact same box in place, rules.md §6's
// own "Comment list" section, item 4). One helper, not two near-identical markup blobs -- §0.4
// ("ซ้ำ = shared"): before this round the compose form lived as static markup in a page view
// (payroll/detail.php) while the inline-edit form was a second, hand-kept copy of the same shape in
// payroll/detail.js, and the two had already drifted (different wrappers, different label row).
//
// Shape (rules.md §6): a bordered box (`--c-border`, `--radius-lg`) that turns its border
// `--c-primary` on `:focus-within` (never blue -- §3); top row = avatar 28px + the author's own name
// in bold; a borderless, auto-growing textarea (no box of its own -- the composer IS the box); a
// 1px `--c-border` divider; bottom row = tag chips on the left, action button(s) on the right.
//
// config = {
//   idPrefix (required): every id/name this box renders is derived from it -- textarea
//     `${idPrefix}Text`, radio group name `${idPrefix}Tag`, each radio id `${idPrefix}Tag_${enum}`.
//     Callers that can have 2 boxes alive at once (the compose box + one inline edit) MUST pass
//     distinct prefixes, which is also what keeps snapshotFormState()'s own name/id-keyed dirty
//     tracking (§9) able to tell them apart.
//   actor: {name, avatar} -- whoever is writing (the LOGGED-IN user for a new comment; the
//     comment's OWN author when editing one, since editing doesn't change who said it).
//   text: prefilled body (inline edit); omit/'' for an empty compose box.
//   placeholder: caller-supplied, already-localized string (this component never reads langData
//     itself -- same "caller owns its own copy" convention emptyStateHtml()/renderCommentList()'s
//     own emptyState option already follow).
//   tags: [{value, enum, outline?}] -- the choices in the tag BADGE DROPDOWN (badgeDropdownHtml(),
//     §5) that sits at the left of the foot row: `value` is what the caller's own form reads back
//     (through the hidden `${idPrefix}Tag` input that dropdown renders), `enum` is the status_map key
//     supplying each choice's label+tone, `outline: true` marks the choice whose toggle should read
//     as an empty/neutral control (the "no tag" entry). Omit (or pass []) for no tag control at all.
//   tagContext: the status_map context those `enum`s belong to (e.g. 'employee_comment_tag').
//   tag: the currently-selected chip's `value` ('' selects the chip whose own value is '').
//   textareaClass / textareaAttrs: extra class / extra raw attributes on the textarea -- the hook a
//     caller uses for its own delegated handlers (e.g. a per-comment `data-id`). Caller owns the
//     attribute string's own escaping, same contract as `actions` below.
//   actions: raw HTML for the bottom-right button(s) -- caller owns markup/escaping/ids/disabled
//     state entirely (this component has no opinion on how many buttons or what they do).
// }
function commentComposerHtml(config) {
    config = config || {};
    const idPrefix = config.idPrefix || 'commentComposer';
    const actor = config.actor || null;
    // 28px (was 32px, 2026-09-15 restyle item 2) -- one step down alongside the type scale, so the
    // author row stays balanced against its now-smaller name/text.
    const avatarHtml = apvAvatarHtml(actor ? actor.name : '', 28, actor ? actor.avatar : null);
    const nameHtml = actor ? `<span class="comment-composer-name">${escapeHtml(actor.name || '')}</span>` : '';
    const tags = config.tags || [];
    const selectedTag = config.tag === undefined || config.tag === null ? '' : String(config.tag);
    // 2026-09-15, Round 3 (restyle item 4): the 4 always-visible chips are gone -- the tag is now ONE
    // badge dropdown (badgeDropdownHtml(), §5), the same shape Payroll Detail's verify-status badge
    // already uses in its table: the button IS the current tag's badge, the menu holds the choices.
    // `<span>` placeholder when a caller passes no tags at all, purely so the foot row keeps its
    // left/right split (buttons stay right-aligned) instead of collapsing them to the left.
    const currentTag = tags.find(function (t) {
        const v = t.value === undefined || t.value === null ? '' : String(t.value);
        return v === selectedTag;
    }) || tags[0] || null;
    const tagPickerHtml = currentTag ? badgeDropdownHtml({
        enum: currentTag.enum,
        context: config.tagContext,
        outline: !!currentTag.outline,
        options: tags,
        value: selectedTag,
        name: idPrefix + 'Tag',
    }) : '<span></span>';
    const textareaClass = config.textareaClass ? ' ' + config.textareaClass : '';
    const textareaAttrs = config.textareaAttrs ? ' ' + config.textareaAttrs : '';
    // rows="1" -- input.js's own app-wide T002 auto-grow (zero-config, every textarea) sizes this to
    // its real content on render and on every keystroke, so a fixed starting row count would only
    // ever be a too-tall floor for an empty box.
    return `<div class="comment-composer">
        <div class="comment-composer-head">
            <span class="comment-composer-avatar">${avatarHtml}</span>
            ${nameHtml}
        </div>
        <textarea class="comment-composer-text${textareaClass}" id="${escapeAttr(idPrefix + 'Text')}" name="${escapeAttr(idPrefix + 'Text')}" rows="1" placeholder="${escapeAttr(config.placeholder || '')}"${textareaAttrs}>${escapeHtml(config.text || '')}</textarea>
        <div class="comment-composer-foot">
            ${tagPickerHtml}
            <div class="comment-composer-actions">${config.actions || ''}</div>
        </div>
    </div>`;
}
// 2026-09-14, Round 3 -- new shared component, docs/design/rules.md §6's own "Comment list" section.
// A different shape than Timeline on purpose (see that revert note just above for why this exists as
// its own component instead of another Timeline extension): no dot, no connecting line, no card/
// border/divider per item -- a comment isn't a milestone on a log, just "who said what, when".
//
// 2026-09-15, Round 3 (restyle, reference-driven) -- an item is an avatar GUTTER on the left plus a
// content column holding 3 stacked rows:
//   row 1: author name (600) + tag badge
//   row 2: the comment text itself
//   row 3: relative time (+ full date/time tooltip) on the left, edit/delete icons on the right
// Every row of the content column starts at ONE left edge (right of the avatar) -- the avatar is
// purely a gutter and never has text under it. (An earlier pass the same day had the text/foot rows
// start at the AVATAR's own left edge instead; corrected here to the reference's own column.)
// Edit/delete are ALWAYS visible now (they used to appear on hover/:focus-within only, with a
// `pointer:coarse` exception for touch) -- an affordance you have to discover by hovering isn't one,
// and the icons now sit on their own row where they no longer compete with the name/badge for space.
//
// item = { id?, time (parseable date/datetime string), timeSuffix? (plain string rendered muted
//   right after the time, e.g. "(edited)"), actor?: {name, avatar}, text (plain string, escaped --
//   multi-line via `white-space:pre-line` in CSS, NOT manual <br> injection), badge?: {enum,
//   context} (omit/null to hide entirely -- e.g. a comment with no tag), actions? (raw HTML string,
//   e.g. edit/delete icon buttons -- caller owns markup+escaping, omit to hide, e.g. read-only
//   mode), bodyHtml? (raw HTML, REPLACES THE WHOLE ITEM when set -- rows 1/2/3 included) }.
//
// `bodyHtml` replacing the ENTIRE item (not just row 2, as it did before this restyle) is what makes
// inline edit work the way rules.md §6 item 4 specifies: the edited comment becomes a composer box
// in place -- and a composer already renders its own author row and its own buttons, so keeping the
// item's own name row above it (and its time/actions row below it) would just duplicate them. The
// caller passes commentComposerHtml(...) straight through as `bodyHtml`; see payroll/detail.js's own
// employeeCommentInlineEditFormHtml().
//
// Time is ALWAYS relative (formatRelativeTime(), format-helpers.js) with the full absolute
// date+time as a native `title` hover tooltip (formatDisplayDateTime()) -- not an opt-in like
// Timeline's own `options.relativeTime` was, since every real/planned caller of THIS component wants
// exactly this (a comment feed, not an audit log where an absolute HH:MM matters more at a glance).
//
// `options.emptyState` (optional `{icon, title, text?, action?}`, passed straight through to
// emptyStateHtml()) -- see the real-bug note right below for why this exists.
//
// 2026-09-14, real bug found and fixed while reviewing #employeeCommentModal: an EMPTY `items` array
// used to render a valid-but-blank `<ul class="comment-list"></ul>` -- no message, just nothing --
// because the "0 comments" case was handled entirely by the ONE real caller
// (renderEmployeeCommentListFromCache(), payroll/detail.js) checking length BEFORE ever calling this
// function, never inside it. That caller's own guard happened to make the real app behave correctly
// today, but it meant the shared COMPONENT itself had a silent gap any future caller could trip on by
// simply forgetting the same guard. Fixed at the source: this function now owns the empty case
// itself via `options.emptyState` (same `{icon, title, text?, action?}` shape `emptyStateHtml()`
// itself takes, and the same "caller supplies its own copy, no assumed i18n" pattern
// `initSharedDataTable()`'s own `emptyState` option already established -- see dtRenderEmptyState()
// above) -- the caller-side length check in payroll/detail.js is removed now that it's redundant.
function renderCommentList(items, options) {
    options = options || {};
    if (!items || !items.length) {
        return emptyStateHtml(options.emptyState || { icon: 'fa-solid fa-comments', title: 'No comments yet.' });
    }
    let html = '<ul class="comment-list">';
    items.forEach(function (item) {
        if (item.bodyHtml !== undefined) {
            html += `<li class="comment-item comment-item-editing">${item.bodyHtml}</li>`;
            return;
        }
        const actor = item.actor || null;
        const avatarHtml = apvAvatarHtml(actor ? actor.name : '', 28, actor ? actor.avatar : null);
        const nameHtml = actor ? `<span class="comment-item-name">${escapeHtml(actor.name || '')}</span>` : '';
        const badgeHtml = item.badge ? statusBadgeHtml(item.badge.enum, item.badge.context) : '';
        const actionsHtml = item.actions ? `<span class="comment-item-actions">${item.actions}</span>` : '';
        const timeLabel = formatRelativeTime(item.time);
        const timeTitleAttr = ` title="${escapeAttr(formatDisplayDateTime(item.time))}"`;
        // `item.timeSuffix` (optional plain string, e.g. "(edited)") -- rendered muted right after
        // the time, own span so it can be styled/omitted independently of the time itself. Not part
        // of the original spec's item shape, added because an edited-comment marker (pre-existing
        // functionality, 2026-08-29) needed SOMEWHERE to live -- see payroll/detail.js's
        // employeeCommentToListItem() for the one real caller that uses it.
        const timeSuffixHtml = item.timeSuffix ? ` <span class="comment-item-time-suffix">${escapeHtml(item.timeSuffix)}</span>` : '';
        html += `<li class="comment-item">
            <div class="comment-item-avatar">${avatarHtml}</div>
            <div class="comment-item-body">
                <div class="comment-item-head">
                    ${nameHtml}
                    ${badgeHtml}
                </div>
                <div class="comment-item-text">${escapeHtml(item.text || '')}</div>
                <div class="comment-item-foot">
                    <span class="comment-item-time"${timeTitleAttr}>${escapeHtml(timeLabel)}</span>${timeSuffixHtml}
                    ${actionsHtml}
                </div>
            </div>
        </li>`;
    });
    html += '</ul>';
    return html;
}
// Notification bell dropdown (§6, Round 2 item 6d) -- UI ONLY this round, no backend wiring, no
// polling (see rules.md §6's own "Notification" section for the 3 real, deliberate differences from
// the ALREADY-SHIPPED, backend-connected version of this in public/js/notifications.js -- that file
// is untouched by this round). Both functions follow the same "return a string / let the caller
// .html() it, own no target selector" convention renderTimeline()/renderStatusStepper() above already
// use -- neither owns the bell's own markup (button/dropdown shell), only the pieces that change:
// the list's inner content and the badge's own text/visibility.
const NOTIF_TONE_ICON = {
    success: 'fa-check',
    danger: 'fa-xmark',
    warning: 'fa-triangle-exclamation',
    neutral: 'fa-bell',
};
function renderNotifications(items) {
    if (!items || !items.length) {
        // Resolved via getLangValue() directly, NOT a `data-i18n` attribute for a later DOM sweep to
        // pick up -- this HTML is inserted by the CALLER well after applyLanguage()'s own one-time
        // sweep already ran (real bug found and fixed 2026-09-13: a `data-i18n` marker on
        // JS-generated content that gets inserted AFTER that sweep never gets translated, it just
        // shows whatever static fallback text was hardcoded here regardless of language -- the exact
        // same fix already applied to dtRenderEmptyState()'s own filtered-empty text below).
        return `<div class="notif-empty">${escapeHtml(getLangValue('notif_empty') || 'ยังไม่มีการแจ้งเตือน')}</div>`;
    }
    let html = '';
    items.forEach(function (item) {
        const tone = item.tone || 'neutral';
        const icon = NOTIF_TONE_ICON[tone] || NOTIF_TONE_ICON.neutral;
        const unreadCls = item.unread ? ' notif-item-unread' : '';
        const detailHtml = item.detail ? `<span class="notif-item-detail">${escapeHtml(item.detail)}</span>` : '';
        html += `<a href="${escapeHtml(item.link || '#')}" class="notif-item${unreadCls}">
            <span class="notif-item-icon"><i class="fa-solid ${icon}"></i></span>
            <span class="notif-item-body">
                <span class="notif-item-title">${escapeHtml(item.title || '')}</span>
                ${detailHtml}
                <span class="notif-item-time">${escapeHtml(item.time || '')}</span>
            </span>
        </a>`;
    });
    return html;
}
// `el` is the badge element itself (a selector/jQuery/DOM node), not the bell button around it --
// deliberately not hardcoded to one id (e.g. a future real #notifBadge) so this same function serves
// both the components.php demo's own scoped id and, come round 4, the real header.php badge, without
// forking a copy for either. Mirrors notifications.js's own real notifUpdateBadge() 1:1 in shape
// EXCEPT the overflow text -- that real function shows "99+", this one shows ">99" per this round's
// own spec (see rules.md §6 for why the two aren't reconciled to match this round).
function setNotificationCount(el, n) {
    const $badge = $(el);
    const count = Number(n) || 0;
    if (count > 0) {
        $badge.text(count > 99 ? '>99' : count).removeClass('d-none');
    } else {
        $badge.addClass('d-none');
    }
}
// Empty state (§6, Round 2 item 6e) -- JS twin of app/views/partials/empty-state.php, same shape,
// same markup (see that file's own docblock for the 2 meanings that must not share copy, and why
// only initSharedDataTable()'s own `emptyState` option auto-picks between them). `action.onClick` is
// the ONE thing this JS version accepts that the PHP partial can't (a real function) -- needed
// because a caller that re-renders this block repeatedly (a DataTable redraw replaces the whole DOM
// node every time via .html()) would otherwise have its externally-bound `$('#id').on('click', ...)`
// handler silently stop working after the very first redraw, since that DOM node no longer exists.
// `action.id` still works too (for a one-off render that's never rewritten, or just as a CSS/test
// hook) -- both can be set together, neither is required.
function emptyStateHtml(config) {
    config = config || {};
    // 2026-09-17, R1 follow-up: `inline` = one muted line, no icon, no title, no action -- for a
    // slot INSIDE a block (an empty column of a 2-column list) rather than a whole page/table with
    // nothing in it. Same component so the wording and the muted treatment stay in one place;
    // everything the full variant adds is exactly what would be wrong at this size.
    if (config.inline) {
        return `<div class="empty-state empty-state-inline">${escapeHtml(config.text || '')}</div>`;
    }
    const icon = config.icon || 'fa-solid fa-inbox';
    const action = config.action;
    const variant = action && ['primary', 'secondary', 'tertiary'].indexOf(action.variant) !== -1 ? action.variant : 'secondary';
    const btnClass = variant === 'tertiary' ? 'btn btn-link' : (variant === 'primary' ? 'btn btn-primary' : 'btn btn-outline-secondary');
    const actionHtml = action
        ? `<button type="button" class="${btnClass} empty-state-action" id="${escapeHtml(action.id || '')}">${escapeHtml(action.label || '')}</button>`
        : '';
    // `text_id` -- PHP twin parity (empty-state.php, 2026-09-20): an `id` on the text line alone, for
    // a caller that has to replace that one sentence live (a server error message taking the place of
    // the standard one) without re-rendering the block. Omitted = the markup every existing caller
    // already gets, unchanged.
    const textIdAttr = config.text_id ? ` id="${escapeHtml(config.text_id)}"` : '';
    return `<div class="empty-state">
        <i class="empty-state-icon ${escapeHtml(icon)}" aria-hidden="true"></i>
        <div class="empty-state-title">${escapeHtml(config.title || '')}</div>
        <div class="empty-state-text"${textIdAttr}>${escapeHtml(config.text || '')}</div>
        ${actionHtml}
    </div>`;
}
// 2026-09-14, Round 3 item 3c-4, explicit instruction -- a shared helper for a modal's own standard
// [primary][secondary] footer button pair (§9/§4: primary left, secondary/dismiss right -- the
// app-wide `.modal-footer` CSS's own `order` rule, not anything this function needs to position
// itself), built ONCE by construction so the 2 buttons can never independently drift out of sync on
// size/class the way #employeeCommentModal's own old static markup once did (`btn-sm` on one button,
// a plain (non-outline) `btn-secondary` on the other). #employeeCommentModal is the first real
// caller -- called once (detail.js) to populate a modal's own `<div class="modal-footer" id="...">`
// shell, not re-rendered per state change (a footer built this way stays "คงที่ตลอด" for free -- its
// caller toggles `disabled`/`d-none` on the rendered buttons afterward instead of re-calling this).
//
// `primary`/`secondary`: { id, key (a real langData/lang-json key), fallback (English literal),
// dismiss? (secondary only -- adds data-bs-dismiss="modal") }. Either can be omitted (a view-only
// modal might want secondary/[Close] alone) -- omitted means no button rendered, not a broken one.
// `key` is always set as `data-i18n` on the rendered button too (not just used to look up the
// INITIAL text) -- the exact fix a real bug needed 2 rounds ago (a JS-injected footer button with no
// data-i18n marker never updated on a live language switch, see that fix's own comment on
// app.js's `show.bs.modal` handler above) -- this helper bakes that in by construction so a future
// caller can't reintroduce the same gap by forgetting it.
function modalFooterButtonsHtml(config) {
    config = config || {};
    function buttonHtml(spec, extraClass, isPrimary) {
        if (!spec) return '';
        // 2026-09-14, real bug found and fixed while reviewing #employeeCommentModal (this
        // function's own first and, so far, only real caller): both buttons were hardcoded
        // `btn-sm`. rules.md §4 is explicit -- "ปุ่มในหน้า/modal = ขนาดปกติ" (normal size), `.btn-sm`
        // is reserved for table-row/filter-bar/DataTable-toolbar buttons only, never a modal's own
        // footer. Fixed here (the shared helper), not at the call site, so every future modal that
        // adopts this helper gets the correct size automatically -- confirmed via grep this is still
        // the only real caller today, so no other modal's footer changes as a side effect.
        const cls = isPrimary ? 'btn btn-primary' : 'btn btn-outline-secondary';
        const dismissAttr = (!isPrimary && spec.dismiss) ? ' data-bs-dismiss="modal"' : '';
        const idAttr = spec.id ? ` id="${escapeAttr(spec.id)}"` : '';
        const i18nAttr = spec.key ? ` data-i18n="${escapeAttr(spec.key)}"` : '';
        const label = (spec.key && langData && langData[spec.key]) || spec.fallback || '';
        return `<button type="button" class="${cls}${extraClass || ''}"${idAttr}${i18nAttr}${dismissAttr}>${escapeHtml(label)}</button>`;
    }
    // 2026-09-16: an optional LEFT slot (§9) for the one kind of footer action that is neither the
    // modal's main action nor its way out -- one that PREPARES a bulk edit for the main button to
    // save -- which reads wrong sitting next to [บันทึก][ปิด]. `me-auto` on the slot is what pushes
    // those two to the right; anything the caller needs beside it (a progress line, a count) goes in
    // `leftHtml` and shares the same slot.
    const left = config.left || config.leftHtml
        ? `<div class="modal-footer-left me-auto d-flex align-items-center gap-2">${buttonHtml(config.left, '', false)}${config.leftHtml || ''}</div>`
        : '';
    return left + buttonHtml(config.primary, '', true) + buttonHtml(config.secondary, '', false);
}
// initSharedDataTable()'s own `emptyState` option (see that function's own comment on the
// stickyColumns/columnFilters/export composition block, which this hooks into the same way) --
// distinguishes "genuinely no data" from "filtered/searched to zero real rows" using DataTables' OWN
// page.info(): `recordsTotal` is the full dataset size regardless of table mode (client-side: every
// loaded row; server-side: the server's own unfiltered COUNT(*)), `recordsDisplay` is what's left
// after DataTables' global search() OR any $.fn.dataTable.ext.search predicate (initExcelColumnFilters()'s
// own client mode pushes exactly that) -- and for server mode, whatever the backend's own
// recordsFiltered already said. Both filtering mechanisms land in the same 2 numbers, so this needed
// no new cross-module API into table-column-filter.js to detect correctly either way. Runs on every
// draw (search/filter/page change), not just init, since whether the table is empty -- and WHY -- can
// change on any of those.
// 2026-09-16, real bug found and fixed: the empty state's own "ล้างตัวกรอง" button used to run
// `dt.search('').draw()`, which clears the GLOBAL SEARCH BOX and nothing else -- so whenever the
// table was empty because of a filter-bar select or a column-header checklist (the two commonest
// ways to filter a table to zero in this app), pressing it visibly did nothing at all. A table can
// be narrowed from 3 independent places and clearing has to mean all 3:
//   1. the filter panel above it   -- each bar's own clear routine ($bar.data('filterBarClear'))
//   2. the column-header checklists -- clearColumnFilters() (table-column-filter.js)
//   3. the global search box        -- dt.search('')
// `$bar` is resolved from `options.filterBar` when the caller named one, otherwise from the single
// `.filter-bar` on the page (the overwhelmingly common case); a page with 2 bars and no explicit
// option gets none, which is better than clearing the wrong one.
function tableFilterBarFor(dt, filterBarSelector) {
    const $explicit = filterBarSelector ? $(filterBarSelector) : $();
    if ($explicit.length) return $explicit.first();
    const $all = $('.filter-bar');
    return $all.length === 1 ? $all.first() : $();
}
function clearAllTableFilters(dt, filterBarSelector) {
    const $bar = tableFilterBarFor(dt, filterBarSelector);
    const barClear = $bar.data('filterBarClear');
    if (typeof barClear === 'function') barClear();
    const columnsCleared = (typeof clearColumnFilters === 'function') ? clearColumnFilters(dt) : false;
    // The search box is cleared with a draw of its own only when nothing else already redrew --
    // `search('')` alone leaves the table showing its old result set until something draws.
    if (dt.search()) {
        dt.search('').draw();
    } else if (!columnsCleared && typeof barClear !== 'function') {
        dt.draw();
    }
}
function tableHasActiveFilters(dt, filterBarSelector) {
    if (dt.search()) return true;
    if (typeof hasActiveColumnFilters === 'function' && hasActiveColumnFilters(dt)) return true;
    const $bar = tableFilterBarFor(dt, filterBarSelector);
    return $bar.length ? Number($bar.find('.filter-bar-count').text() || 0) > 0 : false;
}
// rules.md §6's 2 variants. Nothing filtered -> the CALLER's own `emptyState` verbatim (its copy,
// its icon, and its own create action if the page has one to offer -- §6 leaves that decision to the
// caller, the component never guesses at it). Narrowed to nothing -> the shared "ไม่พบข้อมูลที่ตรงกัน"
// with its own Clear action; a page's own create button is deliberately NOT offered there, because
// the rows it would create are not what is missing.
// The empty-state row's own cell spans every column, so on a table wide enough to scroll sideways
// its centred content ends up centred in the WHOLE table -- measured at 430px: the message sat past
// the right edge and the table read as empty with no message at all. The cell is already pinned to
// the scroller's left edge (§7's `td.dt-empty-cell`), so the content only needs to be told how wide
// the VISIBLE part is. That width is published as a custom property on the scroller itself rather
// than as an inline style on the row: every draw rebuilds that row (an inline value set during one
// render is gone after the next), while the scroller element survives them all.
function dtPublishVisibleWidth($table) {
    const scroller = $table.closest('.table-responsive, .dt-scroll-body').get(0);
    if (!scroller || !scroller.clientWidth) return;
    scroller.style.setProperty('--dt-visible-width', scroller.clientWidth + 'px');
}
// The width has to be re-published whenever the scroller RESIZES, not only on the draws that happen
// to run while it is visible: a table living in a tab that is not the default one draws once while
// still hidden (the scroller measures 0, there is nothing to publish) and an empty table never draws
// again on its own, so a draw-time hook alone leaves the property unset for good.
function dtWatchVisibleWidth($table) {
    const scroller = $table.closest('.table-responsive, .dt-scroll-body').get(0);
    if (!scroller) return;
    dtPublishVisibleWidth($table);
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(function () { dtPublishVisibleWidth($table); }).observe(scroller);
        return;
    }
    $(window).on('resize.dtVisibleWidth-' + ($table.attr('id') || ''), function () { dtPublishVisibleWidth($table); });
}
function dtRenderEmptyState(dt, emptyState, filterBarSelector) {
    const info = dt.page.info();
    if (info.recordsDisplay !== 0) return;
    // Published here too, not only at init: a table inside a tab that is not the default one
    // initialises while hidden, where the scroller measures 0 and there is nothing to publish yet.
    dtPublishVisibleWidth($(dt.table().node()));
    const $tbody = $(dt.table().node()).find('tbody');
    const colCount = dt.columns(':visible').count() || 1;
    // "narrowed to nothing" = the table HAS rows behind it, or one of the 3 filter sources is on
    // (a server-mode table can legitimately report recordsTotal 0 while a filter is what emptied it).
    const filtered = info.recordsTotal > 0 || tableHasActiveFilters(dt, filterBarSelector);
    // "ไม่พบตามที่กรอง" reuses this app's OWN existing DataTables-language string (`zeroRecords`,
    // already shown for exactly this situation everywhere else) as the title instead of forking a new
    // key with near-identical meaning -- only the supporting "text" line (empty_state_filtered_text)
    // and the auto "ล้างตัวกรอง" action (reusing `clear_filter`, same key filter-bar.php's own Clear
    // button uses) are new.
    const config = filtered ? {
        icon: 'fa-solid fa-filter-circle-xmark',
        title: getLangValue('zeroRecords') || 'ไม่พบข้อมูลที่ตรงกัน',
        text: getLangValue('empty_state_filtered_text') || 'ลองเปลี่ยนคำค้นหาหรือตัวกรอง',
        action: {
            label: getLangValue('clear_filter') || 'ล้างตัวกรอง',
            variant: 'secondary',
            onClick: function () { clearAllTableFilters(dt, filterBarSelector); },
        },
    } : emptyState;
    if (!config) return;
    $tbody.html(`<tr class="dt-empty-row"><td class="dt-empty-cell" colspan="${colCount}">${emptyStateHtml(config)}</td></tr>`);

    if (config.action && typeof config.action.onClick === 'function') {
        $tbody.find('.empty-state-action').on('click', config.action.onClick);
    }
}
// Calendar widget (docs/design/rules.md §14, Round 2 item 9) -- JS twin of
// app/views/partials/calendar-widget.php (see that file's own docblock for the full visual-rule
// spec and the `.calendar-widget-*` class contract both renderers share byte-for-byte). This is the
// LIVE version: owns month-navigation (prev/next buttons + the plain <select>) and day-selection
// entirely client-side, re-rendering itself from the SAME `events` array passed in at call time --
// there is no server round trip here (that's a round-4 decision for whichever real page adopts this,
// see docs/design/audit.md's 2026-09-13 addendum), so navigating to a month outside the given
// `events` data simply renders an empty grid for that month, which is expected/correct.
//
// renderCalendarWidget(el, {month, events, onSelect}):
//   el      - a DOM element or jQuery selector to render into (its entire content is replaced).
//   month   - {year, month} (1-based month) for the initially-displayed month.
//   events  - flat array of {date:'Y-m-d', tone:'danger'|'warning'|'success'|'muted', label}.
//   onSelect - optional function(dateStr|null, dayEvents) fired whenever the selected day changes
//              (including deselection, dateStr === null) -- the widget's OWN detail panel already
//              updates itself regardless, this is only for a caller that wants to react elsewhere.
const CALENDAR_WIDGET_WEEKDAY_FALLBACKS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
function calendarWidgetWeekdayLabels() {
    const keys = ['weekday_short_sun', 'weekday_short_mon', 'weekday_short_tue', 'weekday_short_wed', 'weekday_short_thu', 'weekday_short_fri', 'weekday_short_sat'];
    return keys.map((k, i) => getLangValue(k) || CALENDAR_WIDGET_WEEKDAY_FALLBACKS[i]);
}
function calendarWidgetMonthLabel(year, month) {
    return `${getLangValue('month_' + month) || month} ${year}`;
}
function calendarWidgetDefaultLegend() {
    return [
        { tone: 'danger', label: getLangValue('dash_cal_holiday') || 'Holiday' },
        { tone: 'warning', label: getLangValue('dash_cal_cutoff') || 'Payroll Cutoff' },
        { tone: 'success', label: getLangValue('dash_cal_payment') || 'Payment Date' },
        { tone: 'muted', label: getLangValue('dash_cal_probation') || 'Probation/Internship End' },
    ];
}
function renderCalendarWidget(el, options) {
    options = options || {};
    const $el = $(el);
    const weekdayLabels = options.weekdayLabels || calendarWidgetWeekdayLabels();
    const legend = options.legend || calendarWidgetDefaultLegend();
    const events = options.events || [];
    const onSelect = typeof options.onSelect === 'function' ? options.onSelect : function () {};
    let year = options.month && options.month.year ? Number(options.month.year) : new Date().getFullYear();
    let month = options.month && options.month.month ? Number(options.month.month) : (new Date().getMonth() + 1);
    let selectedDate = null;

    function eventsByDate() {
        const map = {};
        events.forEach(function (ev) { (map[ev.date] = map[ev.date] || []).push(ev); });
        return map;
    }
    function renderDots(dayEvents) {
        if (!dayEvents.length) return '';
        const shown = dayEvents.slice(0, 3);
        return `<div class="calendar-widget-dots">${shown.map(ev => `<span class="calendar-widget-dot calendar-widget-dot-${escapeHtml(ev.tone)}"></span>`).join('')}</div>`;
    }
    function renderDetail(map) {
        const $detail = $el.find('.calendar-widget-detail');
        const dayEvents = selectedDate ? (map[selectedDate] || []) : [];
        if (selectedDate && dayEvents.length) {
            $detail.html(`<div class="calendar-widget-detail-date">${escapeHtml(selectedDate)}</div>` +
                dayEvents.map(ev => `<div class="calendar-widget-detail-row"><span class="calendar-widget-dot calendar-widget-dot-${escapeHtml(ev.tone)}"></span>${escapeHtml(ev.label)}</div>`).join(''));
        } else {
            $detail.html(`<div class="calendar-widget-detail-empty">${escapeHtml(getLangValue('calendar_select_day') || 'เลือกวันที่เพื่อดูรายละเอียด')}</div>`);
        }
    }
    function render() {
        const map = eventsByDate();
        const todayStr = new Date().toISOString().slice(0, 10);
        const daysInMonth = new Date(year, month, 0).getDate();
        const startWeekday = new Date(year, month - 1, 1).getDay();
        const cells = [];
        for (let i = 0; i < startWeekday; i++) cells.push(null);
        for (let d = 1; d <= daysInMonth; d++) cells.push(d);
        while (cells.length % 7 !== 0) cells.push(null);

        let headerRow = '<div class="calendar-widget-row calendar-widget-header-row">' +
            weekdayLabels.map(w => `<div class="calendar-widget-cell calendar-widget-weekday">${escapeHtml(w)}</div>`).join('') + '</div>';
        let bodyRows = '';
        for (let i = 0; i < cells.length; i += 7) {
            bodyRows += '<div class="calendar-widget-row">';
            cells.slice(i, i + 7).forEach(function (d) {
                if (d === null) { bodyRows += '<div class="calendar-widget-cell calendar-widget-cell-empty"></div>'; return; }
                const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const dayEvents = map[dateStr] || [];
                let cls = 'calendar-widget-cell calendar-widget-day';
                if (dateStr === todayStr) cls += ' calendar-widget-today';
                if (dateStr === selectedDate) cls += ' calendar-widget-selected';
                bodyRows += `<div class="${cls}" data-date="${dateStr}"><span class="calendar-widget-day-num">${d}</span>${renderDots(dayEvents)}</div>`;
            });
            bodyRows += '</div>';
        }
        const legendHtml = legend.map(lg => `<span class="calendar-widget-legend-item"><span class="calendar-widget-dot calendar-widget-dot-${escapeHtml(lg.tone)}"></span>${escapeHtml(lg.label)}</span>`).join('');

        $el.html(`<div class="calendar-widget">
            <div class="calendar-widget-nav">
                <button type="button" class="calendar-widget-nav-btn calendar-widget-prev" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
                <span class="calendar-widget-month-select-wrap">
                    <select class="calendar-widget-month-select" aria-label="เลือกเดือน">
                        <option value="0" selected>${escapeHtml(calendarWidgetMonthLabel(year, month))}</option>
                        <option value="-1">${escapeHtml(calendarWidgetMonthLabel(month === 1 ? year - 1 : year, month === 1 ? 12 : month - 1))}</option>
                        <option value="1">${escapeHtml(calendarWidgetMonthLabel(month === 12 ? year + 1 : year, month === 12 ? 1 : month + 1))}</option>
                    </select>
                    <i class="fa-solid fa-chevron-down calendar-widget-month-select-caret"></i>
                </span>
                <button type="button" class="calendar-widget-nav-btn calendar-widget-next" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            <div class="calendar-widget-grid">${headerRow}${bodyRows}</div>
            <div class="calendar-widget-legend">${legendHtml}</div>
            <div class="calendar-widget-detail"></div>
        </div>`);
        renderDetail(map);

        $el.find('.calendar-widget-prev').on('click', function () { month--; if (month < 1) { month = 12; year--; } selectedDate = null; render(); onSelect(null, []); });
        $el.find('.calendar-widget-next').on('click', function () { month++; if (month > 12) { month = 1; year++; } selectedDate = null; render(); onSelect(null, []); });
        $el.find('.calendar-widget-month-select').on('change', function () {
            const delta = Number($(this).val());
            if (!delta) return;
            month += delta; if (month < 1) { month = 12; year--; } else if (month > 12) { month = 1; year++; }
            selectedDate = null; render(); onSelect(null, []);
        });
        $el.find('.calendar-widget-day').on('click', function () {
            const dateStr = $(this).data('date');
            selectedDate = selectedDate === dateStr ? null : dateStr;
            render();
            onSelect(selectedDate, selectedDate ? (map[selectedDate] || []) : []);
        });
    }
    render();
}

// Chart defaults (docs/design/rules.md §14, Round 2 item 9) -- ONE place every Chart.js instance in
// the app should read its colors/fonts/grid/tooltip styling from, instead of each chart hardcoding
// its own hex values (the app-wide pattern this session's own investigation found across all 8
// existing charts -- see docs/design/audit.md's 2026-09-13 addendum). Infra + demo only this round
// (§13 -- real pages are NOT migrated here, that's round 4); dashboard.js/employee/reports.js are
// unchanged and keep working exactly as before.
//
// chartColor(varName) resolves a CSS custom property to its current computed value (light/dark-aware
// automatically, since it just reads whatever the browser has already resolved --chart-*/--c-* to).
// chartColors() returns the --chart-1..5 ramp as an array, in order, for a multi-dataset chart that
// genuinely needs several colors (see §14's own rule on when that's appropriate vs. a single color).
function chartColor(varName) {
    return (getComputedStyle(document.documentElement).getPropertyValue(varName) || '').trim() || '#94A3B8';
}
function chartColors() {
    return [1, 2, 3, 4, 5].map(n => chartColor('--chart-' + n));
}
function chartDefaults(overrides) {
    const fontFamily = getComputedStyle(document.documentElement).getPropertyValue('--font-sans').trim() || 'Sarabun, system-ui, sans-serif';
    const gridColor = chartColor('--chart-grid');
    const textColor = chartColor('--c-text-muted');
    const radiusLg = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--radius-lg')) || 12;
    const base = {
        responsive: true,
        maintainAspectRatio: false,
        font: { family: fontFamily },
        plugins: {
            legend: { labels: { font: { family: fontFamily }, color: textColor } },
            tooltip: {
                backgroundColor: chartColor('--c-bg'),
                titleColor: chartColor('--c-text'),
                bodyColor: chartColor('--c-text-muted'),
                borderColor: chartColor('--c-border'),
                borderWidth: 1,
                cornerRadius: radiusLg,
                padding: 10,
                titleFont: { family: fontFamily, weight: '600' },
                bodyFont: { family: fontFamily },
            },
        },
        scales: {
            x: { grid: { color: gridColor }, ticks: { font: { family: fontFamily }, color: textColor } },
            y: { grid: { color: gridColor }, ticks: { font: { family: fontFamily }, color: textColor } },
        },
    };
    return $.extend(true, {}, base, overrides || {});
}

// Page header actions (docs/design/rules.md §2, Round 3 item 3a) -- JS twin of page-header.php's own
// $secondary_actions/$overflow_actions/$primary_action button-queue rendering, byte-equivalent
// markup, for a page whose header actions are only knowable AFTER an async fetch (e.g. Payroll
// Detail's own run-state-dependent buttons -- draft shows Submit, pending_approval shows Approve +
// an overflow menu, etc.) -- same "PHP partial = first paint, JS twin = live re-render on every
// state change" split renderStatusStepper()/renderTimeline()/renderCalendarWidget() already use.
//
// pageHeaderActionButtonHtml(action, btnClass) renders ONE button/dropdown -- exported as its own
// function (not inlined into renderPageHeaderActions()) since it's also the natural unit to reuse if
// a future page needs to render a single ad-hoc action button outside the queue.
// renderPageHeaderActions(container, {primary, secondary, overflow, overflowLabel}) replaces
// `container`'s (typically `#phActions`) entire content with the full queue, in the exact same
// secondary-then-overflow-then-primary order the PHP partial itself builds it in.
//
// `action.extraClass`/`item.extraClass` (2026-09-13, added while wiring Payroll Detail's own
// state-transition buttons here): an OPTIONAL extra CSS class appended to the rendered element, on
// top of `id`. Needed because several of this app's existing action buttons (payroll/detail.js's own
// `.btn-tl-approve`/`.btn-tl-reject`/`.btn-tl-revert`/etc.) are wired via CLASS-based
// `$(document).on('click', '.btn-tl-xxx', ...)` delegation, not id-based -- moving such a button into
// this shared renderer without a way to also carry its own class would have silently detached it
// from its existing click handler (a real bug caught before shipping, not a guess: `id` alone is NOT
// enough for those specific buttons).
function pageHeaderActionButtonHtml(action, btnClass) {
    const iconHtml = action.icon ? `<i class="${escapeHtml(action.icon)} me-1"></i>` : '';
    const extraCls = action.extraClass ? ' ' + action.extraClass : '';
    if (action.items && action.items.length) {
        let dangerDividerDone = false;
        const itemsHtml = action.items.map(function (item, i) {
            const isDanger = item.tone === 'danger';
            const itemExtraCls = item.extraClass ? ' ' + item.extraClass : '';
            const itemClass = 'dropdown-item' + (isDanger ? ' text-danger' : '') + itemExtraCls;
            // 2026-09-13, "เมนูอื่นๆ" follow-up: a divider means "normal group above, danger group
            // below" -- only render it when something genuinely renders above (i > 0). A menu that's
            // ENTIRELY danger items (danger starts at index 0) gets no divider at all.
            const dividerHtml = (isDanger && !dangerDividerDone && i > 0) ? '<li><hr class="dropdown-divider"></li>' : '';
            if (isDanger) dangerDividerDone = true;
            const itemIconHtml = item.icon ? `<i class="${escapeHtml(item.icon)} me-2"></i>` : '';
            const inner = item.href
                ? `<a class="${itemClass}" href="${escapeHtml(item.href)}">${itemIconHtml}${escapeHtml(item.label)}</a>`
                : `<button type="button" id="${escapeHtml(item.id || '')}" class="${itemClass}">${itemIconHtml}${escapeHtml(item.label)}</button>`;
            return `${dividerHtml}<li>${inner}</li>`;
        }).join('');
        return `<div class="btn-group ph-action-group">
            <button type="button" class="btn ${btnClass} ph-action dropdown-toggle${extraCls}" data-bs-toggle="dropdown" aria-expanded="false">${iconHtml}${escapeHtml(action.label)}</button>
            <ul class="dropdown-menu dropdown-menu-end">${itemsHtml}</ul>
        </div>`;
    }
    if (action.href) {
        return `<a href="${escapeHtml(action.href)}" class="btn ${btnClass} ph-action${extraCls}">${iconHtml}${escapeHtml(action.label)}</a>`;
    }
    return `<button type="button" id="${escapeHtml(action.id || '')}" class="btn ${btnClass} ph-action${extraCls}">${iconHtml}${escapeHtml(action.label)}</button>`;
}
// `options.decision` (2026-09-13, item 3a follow-up, REVISED same-day -- JS twin of page-header.php's
// own $decision_actions) -- an array a caller sets INSTEAD OF `options.primary` (silently ignored if
// both are set, same precedence as the PHP partial). Each item's own `tone` ('success'/'warning'/
// 'danger') picks its `.btn-decision-*` class (style.css) -- the earlier "last item = primary, the
// rest outline-secondary" rule is GONE, not just superseded by this caller's data; §4's documented
// decision-set exception (rules.md §4) is what allows tone-colored buttons here at all, still nowhere
// else. Rendered together in one `.ph-decision-group` wrapper, appended after the ordinary queue, IN
// THE ORDER given (never reordered).
function renderPageHeaderActions(container, options) {
    options = options || {};
    const queue = [];
    (options.secondary || []).slice(0, 2).forEach(function (a) { queue.push({ action: a, cls: 'btn-outline-secondary' }); });
    if (options.overflow && options.overflow.length === 1) {
        // 2026-09-13, "เมนูอื่นๆ" follow-up: exactly 1 item -> plain button using that item's own
        // shape, not a 1-item dropdown. Always outline-secondary regardless of the item's own `tone`
        // -- pageHeaderActionButtonHtml()'s plain-button branch never reads `tone`, same as the PHP twin.
        queue.push({ action: options.overflow[0], cls: 'btn-outline-secondary' });
    } else if (options.overflow && options.overflow.length) {
        queue.push({ action: { label: options.overflowLabel || getLangValue('overflow_actions_label') || 'อื่นๆ', icon: null, items: options.overflow }, cls: 'btn-outline-secondary' });
    }
    let decisionHtml = '';
    if (options.decision && options.decision.length) {
        decisionHtml = `<div class="ph-decision-group">${options.decision.map(function (a) {
            const tone = ['success', 'warning', 'danger'].indexOf(a.tone) !== -1 ? a.tone : 'success';
            return pageHeaderActionButtonHtml(a, 'btn-decision-' + tone);
        }).join('')}</div>`;
    } else if (options.primary) {
        queue.push({ action: options.primary, cls: 'btn-primary' });
    }
    $(container).html(queue.map(q => pageHeaderActionButtonHtml(q.action, q.cls)).join('') + decisionHtml);
}

// Callout (§15, new 2026-09-13, Round 3 item 3a follow-up) -- JS twin of
// app/views/partials/callout.php, same 2 arguments, byte-identical markup. Replaces the old bespoke
// `.next-step-banner`/`.process-next-step` box -- see that partial's own docblock for the full
// visual-rule spec (plain --c-bg-subtle box, 3px tone-colored LEFT border only, no icon).
// `text` is a RAW HTML string the caller has already authored (may bold a specific action word to
// match a real button's own label) -- this function does NOT escapeHtml() it, same "caller-authored
// copy only, never end-user input" contract the PHP partial documents.
function calloutHtml(text, tone) {
    return `<div class="callout callout-${escapeHtml(tone || 'neutral')}">${text}</div>`;
}

// Setting row (§9/§11, new 2026-09-14, 2 variants added same day "เก็บตกรอบ 7") -- see
// app/views/partials/setting-row.php's own docblock for the full spec/example/variant-picking rule;
// this is its JS twin, same signature, byte-identical markup per variant. `label`/`desc_on`/
// `desc_off` are RAW HTML/text the caller has already authored (same "caller-authored copy, not
// escaped twice" contract as calloutHtml() above) -- `id` IS escaped (an attribute value the caller
// may build from data, not authored copy). `options.variant` -- `'plain'` (default, no box, one flat
// `[switch] label · description` line) or `'card'` (the original boxed shape, label+description
// stacked left / switch right) -- §9's own rule: 1-2 settings -> plain, 3+ stacked -> card.
function settingRowHtml(options) {
    options = options || {};
    const checked = !!options.checked;
    const descNow = checked ? (options.desc_on || '') : (options.desc_off || '');
    const id = escapeAttr(options.id || '');
    const switchHtml = `<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="${id}"${checked ? ' checked' : ''}></div>`;
    const descHtml = `<span class="setting-row-desc" data-desc-on="${escapeAttr(options.desc_on || '')}" data-desc-off="${escapeAttr(options.desc_off || '')}">${descNow}</span>`;
    if (options.variant === 'card') {
        return `<div class="setting-row setting-row-card">
            <div class="setting-row-text">
                <div class="setting-row-label">${options.label || ''}</div>
                ${descHtml}
            </div>
            ${switchHtml}
        </div>`;
    }
    return `<div class="setting-row setting-row-plain">
        ${switchHtml}
        <label class="setting-row-plain-label" for="${id}">${options.label || ''}</label>
        <span class="setting-row-sep" aria-hidden="true">&middot;</span>
        ${descHtml}
    </div>`;
}
// Public, exported alongside settingRowHtml() -- see setting-row.php's own docblock ("A caller that
// changes the checkbox's own .prop('checked', ...) PROGRAMMATICALLY") for when to call this directly
// instead of `.trigger('change')`: a switch that ALSO carries its own id-scoped business-logic
// `change` handler (e.g. save-on-toggle) would have that handler re-fire unintentionally on a
// synthetic trigger -- this updates ONLY the description, with no such risk.
function syncSettingRowDesc($switchInput) {
    const $desc = $switchInput.closest('.setting-row').find('.setting-row-desc');
    $desc.html($switchInput.is(':checked') ? $desc.attr('data-desc-on') : $desc.attr('data-desc-off'));
}
// Always-on, delegated on document (no init call needed, no page has to wire this itself) -- the
// normal path: a real user click on the switch fires native 'change', this catches it and calls the
// same sync logic syncSettingRowDesc() exposes for the programmatic case above.
$(document).on('change', '.setting-row .form-check-input', function () {
    syncSettingRowDesc($(this));
});

// Money input (§8, Round 2 item 7a) -- `<input class="money-input">` + initMoneyInputs($scope),
// auto-wired below both from $(document).ready() (every field already on the page at load) and from
// a delegated shown.bs.modal handler (every field inside a modal that just opened -- same pattern
// this file already uses for other per-modal setup, see the shown.bs.modal listener above this one).
// A field wired twice (e.g. still in the DOM the next time its modal is shown) is a no-op --
// `.data('moneyInputWired')` guards against attaching duplicate event handlers.
//
// Behavior: typing filters to digits + at most one dot (no comma, no 2nd dot) as you type; blur
// formats the field's own value WITH commas + exactly 2 decimals via fmtNum() (this file's own
// canonical formatter, so a money-input's blurred display is always identical to how the same value
// renders as read-only text elsewhere); focus strips the commas back off so the plain number is easy
// to edit again. The field's raw numeric value (never comma-formatted) is ALSO kept in sync on a
// `data-raw-value` attribute at every step (typing/blur/initial load) -- see format-helpers.js's own
// parseMoneyInput() docblock for why this attribute exists: this app has no single central
// form-serializer to strip commas in, so `data-raw-value` (or calling parseMoneyInput($el.val())
// directly) is the ONE shared access point a future collectXxxFormData() reads from instead of
// hand-rolling its own comma-strip, once a real form actually adopts `.money-input` (round 4 -- no
// real page uses this class yet, Round 2 does not touch real page templates, §13).
function initMoneyInputs($scope) {
    const $root = $scope ? $($scope) : $(document);
    $root.find('.money-input').addBack('.money-input').each(function () {
        const $el = $(this);
        if ($el.data('moneyInputWired')) return;
        $el.data('moneyInputWired', true);
        function syncRawValue() {
            const raw = parseMoneyInput($el.val());
            $el.attr('data-raw-value', raw === null ? '' : raw);
            return raw;
        }
        $el.on('input', function () {
            let digits = $el.val().replace(/[^\d.]/g, '');
            const firstDot = digits.indexOf('.');
            if (firstDot !== -1) {
                digits = digits.slice(0, firstDot + 1) + digits.slice(firstDot + 1).replace(/\./g, '');
            }
            $el.val(digits);
            syncRawValue();
        });
        $el.on('focus', function () {
            const raw = syncRawValue();
            $el.val(raw === null ? '' : String(raw));
        });
        $el.on('blur', function () {
            const raw = syncRawValue();
            $el.val(raw === null ? '' : fmtNum(raw));
        });
        // A field that already has a value when this runs (server-rendered on page load, or
        // populated by a modal's own edit-fetch before shown.bs.modal fires) gets formatted right
        // away too, not just after the next blur.
        const initRaw = syncRawValue();
        if (initRaw !== null) $el.val(fmtNum(initRaw));
    });
}
$(document).on('shown.bs.modal', '.modal', function () {
    initMoneyInputs(this);
});
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
// 2026-09-11, Batch 3C item 4 sub-step 4a: the reverse of formatDisplayDate() above ('DD/MM/YYYY' ->
// 'YYYY-MM-DD') -- added because the shared Payroll Run form functions further down this file need
// one, and this is the first NEW shared (not page-local) code to need it. Page-local
// toIsoDatePr()/toIsoDateRd() (payroll/index.js, payroll/detail.js) stay exactly as they are --
// established, working, per-page aliases this app deliberately doesn't consolidate (same convention
// as toDisplayDatePr()/toDisplayDateRd() documented in CLAUDE.md) -- this generic version is only for
// code that's genuinely shared across pages, same reasoning formatDisplayDate() itself was added for.
function toIsoDate(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
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
// 2026-09-11, Batch 3C item 2: mirrors EmployeeLoginLogModel::parseUserAgent()'s own PHP regex
// logic (same check order, same OS/browser labels) -- Edge/Opera MUST be checked before Chrome/
// Safari (both embed "Chrome"/"Safari" tokens in their own UA string), same well-known UA-sniffing
// gotcha the PHP version's own docblock already documents. A SEPARATE client-side copy exists here
// (not shared code with that PHP method) because this page renders raw `user_agent` strings sent
// straight from PayrollRunModel::getAuditLog() (never parsed server-side for this particular
// table), unlike Employee Login History, which parses once at WRITE time and stores structured
// columns instead -- kept in lockstep with the PHP version's own regex/labels deliberately, so the
// same real UA never reads "Chrome" on one page and "Edge" on another.
function parseUserAgent(ua) {
    ua = ua || '';
    const result = { device_type: 'unknown', os_name: null, os_version: null, browser_name: null, browser_version: null };
    if (!ua) return result;
    if (/bot|crawl|spider|slurp/i.test(ua)) result.device_type = 'bot';
    else if (/tablet|ipad/i.test(ua)) result.device_type = 'tablet';
    else if (/mobile|android|iphone/i.test(ua)) result.device_type = 'mobile';
    else result.device_type = 'desktop';

    let m;
    if ((m = ua.match(/Windows NT ([\d.]+)/i))) {
        const winVersions = { '10.0': '10/11', '6.3': '8.1', '6.2': '8', '6.1': '7' };
        result.os_name = 'Windows';
        result.os_version = winVersions[m[1]] || m[1];
    } else if ((m = ua.match(/Mac OS X ([\d_]+)/i))) {
        result.os_name = 'macOS';
        result.os_version = m[1].replace(/_/g, '.');
    } else if ((m = ua.match(/Android ([\d.]+)/i))) {
        result.os_name = 'Android';
        result.os_version = m[1];
    } else if ((m = ua.match(/OS ([\d_]+) like Mac OS X/i))) {
        result.os_name = 'iOS';
        result.os_version = m[1].replace(/_/g, '.');
    } else if (/Linux/i.test(ua)) {
        result.os_name = 'Linux';
    }

    if ((m = ua.match(/Edg\/([\d.]+)/i))) { result.browser_name = 'Edge'; result.browser_version = m[1]; }
    else if ((m = ua.match(/OPR\/([\d.]+)/i))) { result.browser_name = 'Opera'; result.browser_version = m[1]; }
    else if ((m = ua.match(/Firefox\/([\d.]+)/i))) { result.browser_name = 'Firefox'; result.browser_version = m[1]; }
    else if ((m = ua.match(/CriOS\/([\d.]+)/i))) { result.browser_name = 'Chrome'; result.browser_version = m[1]; }
    else if ((m = ua.match(/Chrome\/([\d.]+)/i))) { result.browser_name = 'Chrome'; result.browser_version = m[1]; }
    else if ((m = ua.match(/Version\/([\d.]+).*Safari/i))) { result.browser_name = 'Safari'; result.browser_version = m[1]; }
    return result;
}
// 2026-09-11, Batch 3C item 2, explicit instruction: raw IP/User-Agent in the Action History tab's
// own timeline should read as "Windows 10 · Edge 152" (OS · main browser + its MAJOR version only,
// not the full build string) -- built on top of parseUserAgent() above. The raw UA itself is never
// shown inline (still available via a tooltip at the call site) -- only this compact summary.
function formatUserAgentSummary(ua) {
    const p = parseUserAgent(ua);
    const osLabel = p.os_name ? (p.os_version ? `${p.os_name} ${p.os_version}` : p.os_name) : '';
    const browserMajor = p.browser_version ? p.browser_version.split('.')[0] : '';
    const browserLabel = p.browser_name ? (browserMajor ? `${p.browser_name} ${browserMajor}` : p.browser_name) : '';
    return [osLabel, browserLabel].filter(Boolean).join(' · ');
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
    // 2026-09-14, real bug found and fixed (explicit report: "theme light/dark หลุดเอง") -- the theme
    // fallback here used to be the literal `|| 'light'`. `UserPreferenceModel::save()` does a full
    // 3-column replace on every call (this comment's own next line already explains why ALL 3 values
    // are always sent together) -- so on a BRAND-NEW browser/device, `localStorage.getItem
    // ('preferred_theme')` is null (nothing written there yet) at the exact moment this fires from
    // loadUserPreferences()'s own language-reconciliation branch (`changeLanguage()` called from
    // there, BEFORE that same function reaches ITS OWN line that would have populated this cache --
    // see its own comment) -- so this fell back to the LITERAL STRING 'light' and POSTED it,
    // silently overwriting the employee's real saved theme (dark/system/whatever it actually was)
    // with 'light', permanently, the very first time a language sync ever needed to fire on a device
    // that hadn't cached a theme locally yet -- reproduced live via Playwright (dark -> loaded a
    // fresh browser context -> server ui_theme silently became 'light'). Now falls back to the live
    // DOM attribute (currentDomTheme(), just above -- always correct, no race) instead of a hardcoded
    // guess.
    persistUserPreferences(lang, localStorage.getItem('preferred_font_size') || 'm', localStorage.getItem('preferred_theme') || currentDomTheme());
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
    // 2026-09-14, Round 3 "เก็บตกรอบ 6" item 1, same pattern: Payroll Detail's run-header text
    // (stepper labels, "next step" callout) is JS-templated via langData[key]||fallback with no
    // data-i18n path -- only defined when payroll/detail.js is loaded. See that file's own
    // refreshPayrollDetailLanguage()/renderRunHeaderText() docblocks for the full root cause.
    if (typeof refreshPayrollDetailLanguage === 'function') refreshPayrollDetailLanguage();
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
// 2026-09-14, real bug found and fixed (explicit report: "theme light/dark หลุดเอง", 3rd real
// instance of the same root cause found this round -- see the 2 other fixes' own comments just
// below and on #userSettingsModal's show.bs.modal handler) -- the ONE reliable way to know "what
// theme is ACTUALLY active right now" is the live `data-bs-theme` attribute (always correct, stamped
// server-side by header.php before any JS runs), never localStorage (can legitimately be empty/stale
// -- a brand-new browser/device has none at all). Extracted as its own function because this exact
// 3-line normalization (attribute -> 'dark'/'light'/'system') was about to be written a 3rd time
// inline (changeLanguage()'s own theme fallback, just below) -- CLAUDE.md's own "generalize, don't
// mirror-copy" rule.
function currentDomTheme() {
    const attr = document.documentElement.getAttribute('data-bs-theme');
    return attr === 'dark' ? 'dark' : (attr === 'light' ? 'light' : 'system');
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
// 2026-09-14, real bug found and fixed (explicit report: "components.php กดสลับ Light/Dark/System
// ไม่ได้ ค้าง dark") -- that page used to run its OWN small, separate DOM+localStorage-only toggle
// (its own comment explained why: "this page DOES load the real app.js now...but that function
// reads a real user session's saved theme preference, which this standalone dev page has none of"),
// deliberately isolated from the real `preferred_theme` localStorage key via its own `cp_theme_
// preview` key. That isolation assumption breaks the moment whoever is previewing the page is ALSO
// logged into a real session in the same browser (routine for anyone doing this design work) --
// loadUserPreferences() (this file's own ready-handler) still runs on every page including this one
// and would fetch/reconcile against that REAL session's real saved theme, competing with the demo
// page's own separate toggle. Rather than trying to out-guess every such interaction with a 2nd
// isolated mechanism, this is now THE one function anything that lets a person "choose a theme"
// calls -- the Settings modal's Save button (below) and components.php's own demo buttons both call
// this, neither keeps its own logic. Updates all 3 places theme lives, in this fixed order, every
// time: DOM attribute (immediate visual effect) -> localStorage (this device's own fast-path cache
// for next load) -> server, best-effort, via the SAME persistUserPreferences() this file already
// had (not a 2nd reimplementation of that POST -- CLAUDE.md's own "generalize, don't mirror-copy"
// rule) -- which already silently no-ops on a page with no real session (components.php with nobody
// logged in), and genuinely persists when one exists (components.php with a real session IS now a
// real, live control over that employee's actual saved theme, same as Settings -- an intentional
// consequence of there being exactly one mechanism, not a separate accepted risk).
async function setTheme(theme) {
    applyTheme(theme);
    localStorage.setItem('preferred_theme', theme);
    const lang = (typeof currentLang !== 'undefined' && currentLang) ? currentLang : (localStorage.getItem('preferred_language') || 'en');
    const fontSize = localStorage.getItem('preferred_font_size') || 'm';
    await persistUserPreferences(lang, fontSize, theme);
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
        // 2026-09-14, real bug found and fixed (explicit report: "theme light/dark หลุดเอง") -- theme
        // (unlike font size just above) is ALREADY correctly stamped server-side on <html> by
        // header.php before this script ever runs (see this file's own ready-handler comment on why
        // it deliberately never re-applies theme from localStorage at boot either, same reasoning).
        // This block used to compare `savedTheme` against STALE localStorage and, on any mismatch
        // (trivially true on a fresh browser/session with empty localStorage), call applyTheme() --
        // touching the DOM again was mostly harmless by itself, but it also meant localStorage's own
        // cache didn't always get refreshed promptly, and worse, `#userSettingsModal`'s own
        // `show.bs.modal` handler was reading localStorage AS IF it were live DOM state to capture
        // "the theme before I possibly change it" -- opening Settings and closing it WITHOUT saving
        // would then `applyTheme()` that stale captured value, visibly flipping an already-correct
        // page to a wrong theme with no save action at all. Root-caused by reading the actual code
        // path end-to-end, not guessed. Fixed at 2 points: this function now ONLY refreshes
        // localStorage's cache (never touches the DOM -- the DOM is always already correct for THIS
        // session, kept in sync with the server by UserPreferenceController::save() on every save
        // FROM this session), and the Settings modal (below) now reads the live DOM attribute instead
        // of localStorage. A genuine mismatch between the DOM (this session's own ui_theme) and the
        // server's current value CAN still happen (e.g. the preference was changed from a DIFFERENT
        // device/session since this one last logged in -- session ui_theme only refreshes via THIS
        // device's own save, never on a plain page load) -- flagged via console.warn so it's
        // discoverable, not silently "fixed" by flashing the live page to a different theme, which is
        // exactly the bug being removed here.
        localStorage.setItem('preferred_theme', savedTheme);
        const domTheme = currentDomTheme();
        if (domTheme !== savedTheme) {
            console.warn(`[theme] DOM theme (${domTheme}) and server-saved preference (${savedTheme}) disagree -- this session's ui_theme is stale (likely changed from another device/browser). Open Settings and Save here to refresh it.`);
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
    // 2026-09-14, real bug found and fixed (explicit report: "theme light/dark หลุดเอง") -- was
    // `localStorage.getItem('preferred_theme') || 'light'`, which trusts localStorage as if it were
    // live DOM state. localStorage can legitimately be stale/absent at this exact moment (a fresh
    // browser/session, or simply because loadUserPreferences()'s own async fetch -- called with no
    // `await` from the ready handler -- hasn't resolved yet if Settings is opened quickly after page
    // load) even though the DOM's `data-bs-theme` is ALREADY correct (header.php stamps it
    // server-side before any JS runs). Reading the wrong "original" theme here didn't just mis-select
    // the modal's own button -- `hidden.bs.modal` below restores THIS captured value on close-without-
    // save, so simply opening Settings and closing it again (no click at all) could silently flip an
    // already-correct page to a stale wrong theme. Now reads the live attribute directly -- the one
    // value that's actually guaranteed current at this point in the page lifecycle. Absent attribute
    // = 'system' (same 3-way mapping header.php's own stamp/no-stamp logic uses). Uses the shared
    // currentDomTheme() (same function just above applyTheme() in this file) -- this exact
    // normalization was written inline here first, then needed again verbatim in 2 more places
    // (loadUserPreferences(), changeLanguage()'s own theme-persist fallback) while chasing the same
    // bug family, so it was extracted rather than copied a 3rd time.
    const currentTheme = currentDomTheme();
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
    // Live-preview only while the modal is open -- DOM only, no localStorage/server write yet (same
    // "preview, commit on Save" pattern the font-size slider's own `input` handler above uses).
    // hidden.bs.modal (above) reverts this via applyTheme(userSettingsOriginalTheme) if closed
    // without saving; #btnSaveUserSettings (below) is what actually commits via setTheme().
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
    // 2026-09-14 -- commits via the shared setTheme() (DOM + localStorage + server, see its own
    // docblock) instead of doing the same 3 steps inline here a 2nd time; `preferred_font_size` was
    // already refreshed in localStorage just above, so setTheme()'s own combined server save picks
    // up this SAME fresh `size` alongside the theme, in one POST, not a separate 2nd one.
    const theme = $('.user-settings-theme-option.active').data('theme-option') || 'light';
    setTheme(theme);
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
        // 2026-09-11, real bug fixed: was `?v=${Date.now()}` -- a fresh, never-cacheable value on
        // EVERY single call, defeating browser caching entirely on every page load/language switch.
        // LANG_VERSION (header.php, filemtime()-based, same mechanism as this app's own asset()
        // helper) only changes when that language file's own content actually changes -- the
        // browser can now cache this fetch indefinitely in between. Falls back to Date.now() only
        // if LANG_VERSION is somehow missing (e.g. a page that doesn't load header.php), so this
        // never regresses to "never busts cache at all" in that edge case.
        const langVersion = (typeof LANG_VERSION !== 'undefined' && LANG_VERSION[lang]) ? LANG_VERSION[lang] : Date.now();
        const res = await fetch(`${BASE_URL}/public/lang/${lang}.json?v=${langVersion}`);
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
// 2026-09-13, Phase Design Round 3 item 3a (Payroll Detail pilot, first real page to adopt
// page-header.php) -- extended to ALSO recognize `page-header.php`'s own breadcrumb markup
// (`.ph-breadcrumb .ph-breadcrumb-link`/`.ph-breadcrumb-current`), not just the old
// `.payroll-breadcrumb .bc-parent`/`.bc-current` shape every pre-Round-2 page still uses. Genuinely
// 2 different class sets rather than dual-classing page-header.php's own elements with `.bc-parent`/
// `.bc-current` too, because `.bc-current` (style.css) carries its own real visual identity (an
// orange pill background/padding/radius) that page-header.php's plain-text breadcrumb deliberately
// does NOT want -- adding that class for this mechanism's sake alone would silently reintroduce the
// old pill look. Both old and new pages keep working from this one function -- no page needs to
// change which classes IT renders, this just widens what the function itself looks for.
//
// 2026-09-13, 3a follow-up: also appends `#phTitle`'s own text when present. §2's new convention for
// a page-header.php-based detail page is "crumb สุดท้าย = ชนิดหน้า, H1 = ชื่อของสิ่งนั้น" (e.g. Payroll
// Detail's own last crumb is now the static "รายละเอียดรอบ", the SPECIFIC run name lives in the H1
// instead) -- without this, the browser tab title would lose the one piece of text that actually
// tells 2 open tabs apart (which payroll run, which employee, ...), since the breadcrumb's own last
// crumb no longer carries it. Old `.payroll-breadcrumb` pages have no `#phTitle` at all, so this is a
// pure no-op for them; a page-header.php page whose current-crumb genuinely IS the specific value
// (no `#phTitle`, or one that duplicates the crumb) simply gets no 2nd entry appended.
function updateDocumentTitleFromBreadcrumb() {
    const parts = [];
    $('.payroll-breadcrumb .bc-parent, .ph-breadcrumb .ph-breadcrumb-link').each(function () {
        const t = $(this).text().trim();
        if (t) parts.push(t);
    });
    const currentText = $('.payroll-breadcrumb .bc-current, .ph-breadcrumb .ph-breadcrumb-current').first().text().trim();
    if (currentText && currentText !== '-') parts.push(currentText);
    const phTitleText = $('#phTitle').first().text().trim();
    if (phTitleText && phTitleText !== '-' && phTitleText !== currentText) parts.push(phTitleText);
    document.title = parts.length ? `${parts.join(' — ')} | Origami Payroll` : 'Origami Payroll';
}
// Covers pages where the current-crumb's/#phTitle's real value only appears after an async fetch
// (e.g. payroll/detail.js's renderRunHeader() setting #phTitle once the run loads) -- fires the same
// derivation above automatically whenever that text actually changes, instead of requiring every
// such page to remember to call it manually. One observer, delegated at the document level, set up
// once on first load (harmless no-op if none of these containers exist on a page, e.g.
// error404.php/permission.php) -- watches whichever containers a page happens to render (never all
// 3 at once in practice, but observing whichever exists costs nothing extra). `.ph-header` (not just
// `.ph-breadcrumb`) is watched for page-header.php pages specifically because `#phTitle` is a
// SIBLING of `.ph-breadcrumb`, not a descendant of it -- a `.ph-breadcrumb`-only observer would never
// see #phTitle's own text change at all.
$(function () {
    const breadcrumbEls = document.querySelectorAll('.payroll-breadcrumb, .ph-header');
    if (breadcrumbEls.length && typeof MutationObserver !== 'undefined') {
        breadcrumbEls.forEach(function (el) {
            new MutationObserver(updateDocumentTitleFromBreadcrumb).observe(el, { characterData: true, childList: true, subtree: true });
        });
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
/* ---------- Payee destination (partials/payee-destination.php, rules.md §9/§15) ----------
   The behaviour half of the shared payee picker: 3 destinations that describe what happens to the
   money, plus one sub-question under "retained by company" that decides whether the deduction also
   leaves an audit row against a specific company account. 4 call sites in 2 page scripts, so it
   lives here (§0.4).

   UI value -> `payee_type` sent to the server (mapped on the client, right before submit -- the
   enum, the 4 write paths and every read path are untouched):
     company_retained, no account chosen -> (key omitted)  = payee_type NULL
     company_retained, account chosen    -> 'company'      + bank_account_id
     employee                            -> 'employee'     + payee_employee_id
     external                            -> 'other_person' + destination
   2026-09-19, 4c: "No record / Record" used to be a segmented sub-question of its own, answered
   ABOVE the account picker it decided the fate of -- two controls for one fact, and the only way to
   tell them apart was to read both. The picker IS the answer now: empty means no record, and its own
   placeholder says so (`data-placeholder-key`, input.js).
   A row stored with the retired 'not_disbursed' (or anything else this control cannot show) opens
   on "retained + no record", which is what it always computed as anyway -- see
   docs/decisions/2026-09-16-payee-three-destinations.md.

   `options`: { allowNoRecord (default true -- false for an editor whose backend has no "no payee"
   value at all, i.e. one whose account picker may not be left empty), companyAccount (the company
   bank-account <select> whose emptiness decides NULL vs 'company'), employeeWrap/companyWrap/
   externalWrap (selectors this control shows and hides), onChange(payeeType, dest) (the caller's own
   clearing/prefilling, run after every change) }. */
// 2026-09-19, 4c: no entry for `company_retained` any more -- its help line restated the segment's
// own label and the account picker right under it, three ways of saying one thing (§0.3).
const PAYEE_DEST_DESC = {
    employee: { key: 'payee_dest_desc_employee', fallback: 'The recipient receives it as taxable income in the same run' },
    external: { key: 'payee_dest_desc_external', fallback: 'e.g. Legal Execution Dept., co-op, court-ordered creditors — destination account required' },
};
const PAYEE_DEST_REGISTRY = {};
/* 2026-09-19, 4c fix: REAL infinite recursion, reproduced and measured (21+ nested calls before the
   stack blew, every frame entering through this file's own delegated `change` handler below).
   syncPayeeDestination() calls the caller's onChange, and every caller's onChange clears the fields
   of the branch that was just left -- including the company-account <select>, which since 4c is
   itself bound to `change` -> syncPayeeDestination. Clearing it therefore called the thing that had
   just called the clear. Guarded here, in the one function all 3 callers route through, rather than
   in each onChange: a nested call has nothing to add anyway, since the outer one is mid-flight and
   will finish with the very state the nested one would have read. */
const PAYEE_DEST_SYNCING = {};
function initPayeeDestination(prefix, options) {
    const opts = $.extend({ allowNoRecord: true }, options || {});
    const first = !PAYEE_DEST_REGISTRY[prefix];
    PAYEE_DEST_REGISTRY[prefix] = opts;
    if (first) {
        // Delegated + bound once per prefix: these controls live inside modals that re-render their
        // own contents, and a direct binding would be lost on the first re-render.
        $(document).on('change', `#${prefix}PayeeDest input[type="radio"]`, function () {
            syncPayeeDestination(prefix);
        });
        // The company-account picker is now part of the ANSWER, not just a field under it: choosing
        // or clearing it flips payee_type between 'company' and NULL, so it has to re-sync too.
        if (opts.companyAccount) {
            $(document).on('change', opts.companyAccount, function () {
                syncPayeeDestination(prefix);
            });
        }
    }
    syncPayeeDestination(prefix);
}
function payeeDestinationChoice(prefix) {
    return $(`#${prefix}PayeeDest input[type="radio"]:checked`).val() || 'company_retained';
}
function payeeDestinationRecords(prefix) {
    const opts = PAYEE_DEST_REGISTRY[prefix] || {};
    if (opts.allowNoRecord === false) return true;
    return !!(opts.companyAccount && $(opts.companyAccount).val());
}
// The one place the UI's own vocabulary becomes the column's.
function payeeDestinationType(prefix) {
    const dest = payeeDestinationChoice(prefix);
    if (dest === 'employee') return 'employee';
    if (dest === 'external') return 'other_person';
    return payeeDestinationRecords(prefix) ? 'company' : 'none';
}
function setPayeeDestination(prefix, payeeType) {
    const dest = payeeType === 'employee' ? 'employee' : (payeeType === 'other_person' ? 'external' : 'company_retained');
    $(`#${prefix}PayeeDest input[type="radio"][value="${dest}"]`).prop('checked', true);
    // Nothing to set for 'company' vs NULL: the account picker itself carries that, and the caller
    // fills it (or leaves it empty) right after this.
    syncPayeeDestination(prefix);
}
function syncPayeeDestination(prefix) {
    if (PAYEE_DEST_SYNCING[prefix]) return;
    PAYEE_DEST_SYNCING[prefix] = true;
    try {
        syncPayeeDestinationInner(prefix);
    } finally {
        PAYEE_DEST_SYNCING[prefix] = false;
    }
}
function syncPayeeDestinationInner(prefix) {
    const opts = PAYEE_DEST_REGISTRY[prefix] || {};
    const dest = payeeDestinationChoice(prefix);
    const payeeType = payeeDestinationType(prefix);
    const desc = PAYEE_DEST_DESC[dest] || null;
    // Read straight out of langData here rather than leaving a `data-i18n` for the sweep: this text
    // is swapped on every change, long after the sweep last ran (rules.md §6's own note on
    // JS-built markup) -- the attribute is still set so a live language switch repaints it too.
    $(`#${prefix}PayeeDestDesc`)
        .text(desc ? (getLangValue(desc.key) || desc.fallback) : '')
        .attr('data-i18n', desc ? desc.key : null);
    if (opts.employeeWrap) $(opts.employeeWrap).toggleClass('d-none', dest !== 'employee');
    // Follows the SEGMENT, not the resolved payee_type: the picker has to be on screen while it is
    // still empty -- being empty is how the user says "no record", and a control that appears only
    // once it is filled can never be filled.
    if (opts.companyWrap) $(opts.companyWrap).toggleClass('d-none', dest !== 'company_retained');
    if (opts.externalWrap) $(opts.externalWrap).toggleClass('d-none', dest !== 'external');
    // The callout always has something in it now (the sub-question itself, when nothing else), so
    // unlike the previous 4-choice version there is no "empty indented box" case to hide.
    if (typeof opts.onChange === 'function') opts.onChange(payeeType, dest);
}
/* ---------- Option label: "[CODE] Name" -> name, code kept aside (2026-09-17, R1b) ----------
   Several catalog endpoints hand a select2 option its label already prefixed with the row's own code
   (`CONCAT('[', item_code, '] ', item_name_th)`), from before this app settled on "an internal code
   is never printed inline, it is the element's own `title`" (rules.md §5/§6). This splits that label
   back apart on the READ side so a picker can show the name alone without the endpoint -- shared by
   other, untouched pickers -- having to change what it returns. SEARCHING is unaffected: these
   endpoints match the term against item_code AND both names in SQL, so a code the user types still
   finds its row even though no visible option spells it out.
   A label with no `[...]` prefix comes back unchanged, with an empty code.

   2026-09-17, tiny-M round 3: a second prefix SHAPE, `style: 'dash'` -- "CODE - Name", which is what
   the employee pickers' own endpoint composes (`CONCAT(employee_no, ' - ', name, ' ', surname)`).
   Same read-side-only contract as the bracket shape: the endpoint keeps returning what it always
   returned, and its WHERE still matches the code, so typing a code still finds the row. The split is
   on the FIRST ' - ' only and the code half must look like a code (no spaces) -- a name that itself
   contains ' - ' therefore survives intact, which a greedy split would have mangled. */
function splitOptionCodePrefix(text, style) {
    const raw = (text === null || text === undefined) ? '' : String(text);
    if (style === 'dash') {
        const m = raw.match(/^(\S+)\s+-\s+([\s\S]+)$/);
        if (!m) return { code: '', text: raw };
        return { code: m[1], text: m[2] };
    }
    const m = raw.match(/^\[([^\]]*)\]\s*([\s\S]*)$/);
    if (!m || m[2] === '') return { code: '', text: raw };
    return { code: m[1], text: m[2] };
}
// Every payee picker's endpoint hands back the same 4 optional fields on its option data (the
// payee employee's own account, one of the company's accounts, a saved third-party destination);
// anything an endpoint does not send simply does not show up in the summary (payeeDetailHtml()
// drops blanks). Shared so the 4 pickers cannot drift on what they read.
function payeeDetailFromOption(data) {
    const d = data || {};
    return {
        account_name: d.account_name,
        bank_name: (typeof currentLang !== 'undefined' && currentLang === 'th' ? d.bank_name_th : d.bank_name_en) || d.bank_name_th || d.bank_name_en || d.bank_name,
        account_no_masked: d.account_no_masked,
        branch: d.bank_branch || d.branch,
    };
}
// "Record this deduction against a company account" makes the account mandatory, so the company's
// own default account is offered rather than an empty required field -- same convenience (and the
// same re-check-before-applying guard against the caller's own populate-from-record code) as
// applyFirstSavedDestinationDefault() right below. `detailId` is optional: a picker that shows an
// account summary passes its container, one that does not simply omits it.
function applyDefaultCompanyBankAccount(selectId, detailId) {
    const $select = $(selectId);
    if (!$select.length || $select.val()) return;
    $.post(`${BASE_URL}/api/payroll-cycle.bank-account.options`, { searchTerm: '', page: 1, limit: 20 }, function (res) {
        if ($select.val()) return;
        const items = (res && res.status && res.data && res.data.items) || [];
        const primary = items.find(x => x.is_default);
        if (!primary) return;
        const text = (typeof currentLang !== 'undefined' && currentLang === 'th') ? primary.text_th : primary.text_en;
        $select.empty().append(new Option(text, primary.id, true, true)).trigger('change');
        if (detailId) {
            $(detailId).html(payeeDetailHtml(payeeDetailFromOption(primary)));
        }
    }, 'json');
}
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
// Account summary block shown under whichever picker just resolved to a real bank account --
// the payee employee's own account, one of the company's accounts, or a saved third-party
// destination (3 call sites, payroll/detail.js). Two quiet lines on the subtle surface: the account
// name, then bank + masked number + branch. The masked number is whatever the endpoint hands over
// (same 'all but the last 4 digits' shape the employee quick-view already renders) -- this function
// never sees, decrypts or masks a real account number itself.
// Renders NOTHING (empty string) when there is no account to describe, so a caller can drop its
// return value straight into a container without checking first.
function payeeDetailHtml(detail) {
    const d = detail || {};
    const name = (d.account_name || '').trim();
    const meta = [d.bank_name, d.account_no_masked, d.branch]
        .map(x => (x === null || x === undefined) ? '' : String(x).trim())
        .filter(Boolean);
    if (!name && !meta.length) return '';
    return `<div class="payee-detail">
        ${name ? `<div class="payee-detail-name">${escapeHtml(name)}</div>` : ''}
        ${meta.length ? `<div class="payee-detail-meta">${meta.map(escapeHtml).join(' &middot; ')}</div>` : ''}
    </div>`;
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
// 2026-08-30 through 2026-09-13: this app used to auto-inject a language switch (flag dropdown,
// then a plain "TH | EN" text switch) into every modal's own header -- see git history on this
// file for the full "modal backdrop blocks the page header's own switcher" bug story if that ever
// needs revisiting. REMOVED entirely 2026-09-14 (Phase Design Round 3 item 3c-1, explicit
// instruction, twice: "header = ชื่อ + ×" -- no exception for the language switch) -- header is now
// ONLY the title + × across the whole app, no per-modal markup changes needed (this was global
// injection, so removing it here removes it everywhere at once). The 2026-08-30 bug this used to
// paper over (page header's own language switcher unreachable while any modal is open, since the
// backdrop sits above it) is REOPENED by this removal -- not fixed some other way, just accepted as
// the tradeoff for this instruction. Flagged to the user in this round's own report.
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
// 2026-09-11, real bug found and fixed (explicit report: Tax & Statutory's statutoryRateModal --
// "Add Custom Item" showed a complete form the FIRST time, but only header + tab nav (no form at
// all) every time after). Root cause confirmed by reading the actual bundled bootstrap.js source
// (node_modules/bootstrap/js/dist/tab.js), not a guess: `Tab.show()` checks `_elemIsActive()` on
// the TRIGGER BUTTON (`this._element`, the `<button data-bs-toggle="tab">`), not on the tab-pane --
// if that button already carries `.active` from an EARLIER open (nothing ever removes it when a
// modal is closed), `.show()` returns immediately as a no-op, so a pane that some OTHER code had
// already manually stripped `.active`/`.show` from (as tax-statutory.js's own openStatutoryRateModal()
// used to do, directly on `#sr-details-pane`/`#sr-history-pane`, never touching the matching button)
// is never re-activated -- it just stays `display:none` forever after the first open.
// This is a real class of bug ANY modal with Bootstrap tabs could hit the same way (manually
// stripping a tab-pane's own classes without also stripping its trigger button's), so it belongs
// here as a shared helper rather than inline in one page's own JS -- resets EVERY tab trigger
// button AND its own tab-pane together, always as a pair, inside the given modal/container.
// Callers should call this (if a reset is genuinely needed before deciding which tab to show) and
// then let `bootstrap.Tab.getOrCreateInstance(...).show()` do the actual activation -- never strip
// only the pane's own classes by hand.
function resetModalTabs($modal) {
    $modal.find('[data-bs-toggle="tab"]').removeClass('active').attr('aria-selected', 'false');
    $modal.find('.tab-pane').removeClass('show active');
}
// 2026-09-14, Round 3 item 3c-2 follow-up, explicit instruction: a central popover component (§9/
// §11) -- "ทำเป็นกฎ popover กลาง...ใช้ทุกที่ที่มี ? ไม่เฉพาะ payslip". Written as a genuinely shared
// mechanism, the same way emp-header-card/apvAvatarHtml are -- any badge or "?" info button anywhere
// calls THIS, not its own `new bootstrap.Popover(...)`.
// 2026-09-21, 3e-2a: this docblock used to name payroll/detail.js's formulaButtonRd() as "the one
// real call site today". That function was deleted in 4a-1 (tests/line_override_row_render_test.js
// asserts it is gone) and the comment was never updated -- the real call sites today are the
// Calculation column's 2 badges (payroll/detail.js's calcPopoverBadgeRd()).
//
// initPopovers(root = document): (re)initializes every `[data-bs-toggle="popover"]` under `root` --
// dispose-then-create, same idempotent pattern a caller re-rendering its own container (e.g. a
// modal body replaced via .html() on every open) already needs. The 3 shared BEHAVIORS below (only
// 1 open at a time / Esc closes / click outside closes) are wired ONCE globally the first time this
// runs anywhere (guarded by `popoverGlobalHandlersWired`), not per-call -- calling initPopovers()
// many times (once per render) never double-binds them.
//
// STYLING (bg --c-bg / border --c-border / shadow --shadow-soft / radius --radius-lg / header
// --c-bg-subtle --fs-sm 600 / body --fs-sm, dark-mode-safe since every value is a --c-* token) lives
// in style.css's own `.popover` rule, via Bootstrap's OWN `--bs-popover-*` CSS custom properties
// (confirmed the exact names by reading the compiled bootstrap.min.css directly) -- overriding those
// instead of fighting Bootstrap's popover.js with a hand-rolled positioned box means the arrow stays
// correctly colored/positioned for free (it reads those same variables internally).
//
// The ✕ CLOSE BUTTON is injected via a custom `template` -- deliberately a SIBLING of
// `.popover-header`, never a child placed INSIDE it: Bootstrap's own `setContent()` replaces
// `.popover-header`'s entire innerHTML/textContent on every show (confirmed by reading popover.js),
// which would silently delete a close button living inside that element. Positioned via CSS instead
// (`.popover-close-btn`, style.css) so it visually sits in the header's top-right corner regardless.
// 2026-09-14, same-day follow-up, explicit instruction: the ✕ was sitting crooked/heavy against the
// header text -- `.popover-head-row` wraps `.popover-header` + the ✕ in one flex row (`align-items:
// center`) so they share a real vertical center line, instead of the ✕ being absolutely positioned
// by a guessed pixel offset against the WHOLE popover box. Bootstrap's TemplateFactory finds
// `.popover-header`/`.popover-body` via `querySelector()` (searches all descendants, not just direct
// children), so nesting `.popover-header` one level deeper here doesn't break its own content-fill
// logic. The header's own background/border-bottom/border-radius (previously on `.popover-header`
// itself via the `--bs-popover-header-*` vars) move to this wrapper instead (style.css) -- otherwise
// only the text side of the row would carry that background/line, leaving a visible gap under the ✕.
const POPOVER_TEMPLATE_RD = '<div class="popover" role="tooltip"><div class="popover-arrow"></div><div class="popover-head-row"><h3 class="popover-header"></h3><button type="button" class="btn-icon-ghost popover-close-btn" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><div class="popover-body"></div></div>';
let popoverGlobalHandlersWired = false;
function initPopovers(root = document) {
    if (typeof bootstrap === 'undefined' || !bootstrap.Popover) return;
    // 2026-09-14, real bug found and fixed (explicit report: the ✕ never actually rendered -- popover
    // showed with no close button at all) -- Bootstrap's Tooltip/Popover `template` option is run
    // through its own XSS sanitizer by default, which strips any tag not in its `Default.allowList`
    // (confirmed by inspecting the rendered tip's actual HTML directly: the `<button>` was silently
    // gone even though `inst._config.template` still showed it correctly configured) -- `button` is
    // not one of the allowlisted tags out of the box. Extending the list (not disabling sanitize
    // entirely, which would also stop sanitizing the CONTENT every real caller passes in via
    // `data-bs-content`/`data-bs-html="true"`) with exactly the 2 tags/attributes this one static
    // template needs.
    // 2026-09-21, 3e-2a, real bug found by measurement (not reasoning): a caller passing
    // `<li data-code="...">` through data-bs-content got its `<li>` rendered and the attribute
    // SILENTLY REMOVED -- Bootstrap's allowList is per-tag AND per-attribute, and `li` ships with no
    // attributes of its own at all. The whole point of data-code (rules.md: the raw machine code
    // never reads as text, but stays findable when someone reports a row) was therefore lost the
    // moment it went through a popover, while the identical markup rendered outside one -- the
    // Calculation Breakdown modal's callouts -- kept it. Caught by the round's own Playwright cell
    // reading the attribute back out of the live tip; every `<li>` came back null.
    const popoverAllowList = Object.assign({}, bootstrap.Popover.Default.allowList, {
        button: ['type', 'class', 'aria-label'],
        i: (bootstrap.Popover.Default.allowList.i || []).concat(['class']),
        li: (bootstrap.Popover.Default.allowList.li || []).concat(['data-code']),
        span: (bootstrap.Popover.Default.allowList.span || []).concat(['data-code']),
    });
    $(root).find('[data-bs-toggle="popover"]').each(function () {
        const existing = bootstrap.Popover.getInstance(this);
        if (existing) existing.dispose();
        // The resting half of the aria-expanded pair wired at the bottom of this function: a
        // disclosure control has to announce itself as one BEFORE it is ever pressed, not only once
        // it has been. Set here rather than in each caller's markup so no caller can forget it.
        this.setAttribute('aria-expanded', 'false');
        // 2026-09-21, 3e-2a: `strategy: 'fixed'` as the default for every popover in the app. The
        // triggers that exist today live inside a DataTable cell, and Popper's own default
        // ('absolute') positions the tip against the nearest positioned ancestor -- which for a
        // table inside `.table-responsive` is a scroll container that CLIPS it. Fixed positions
        // against the viewport instead, so a tip opened on the last visible row is never cut off.
        // Merged onto whatever default Popper hands in, never replacing it: the modifiers Bootstrap
        // itself installs (arrow, offset, flip, preventOverflow) all have to survive this.
        new bootstrap.Popover(this, {
            template: POPOVER_TEMPLATE_RD,
            trigger: 'click',
            allowList: popoverAllowList,
            popperConfig: (defaultConfig) => Object.assign({}, defaultConfig, { strategy: 'fixed' }),
        });
    });
    if (popoverGlobalHandlersWired) return;
    popoverGlobalHandlersWired = true;
    // Only 1 open at a time -- right as any popover is ABOUT to show, hide every other currently-open
    // one first (checked by its own trigger still carrying `aria-describedby`, the same attribute
    // Bootstrap itself sets on a trigger while its popover tip is in the DOM).
    document.addEventListener('show.bs.popover', function (e) {
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
            if (el === e.target || !el.getAttribute('aria-describedby')) return;
            const inst = bootstrap.Popover.getInstance(el);
            if (inst) inst.hide();
        });
    });
    // Esc closes whichever popover(s) are currently open.
    // 2026-09-14, real bug found and fixed while testing this (not explicitly reported, found during
    // verification of the focus-return fix just below): a popover living inside a modal, closed via
    // Esc, was closing the WHOLE MODAL too, not just the popover. Root cause -- Bootstrap's own Modal
    // has its own Escape-dismiss listener attached directly on the modal element (bubble phase); this
    // handler was ALSO on bubble phase, but on `document` -- the modal element sits BETWEEN the
    // keydown's real target (whatever has focus, a descendant of the modal) and `document`, so in the
    // bubble phase Bootstrap's own modal listener always ran FIRST, before this one ever got a chance
    // to stop it. Moved to the CAPTURE phase (3rd arg `true`) so it runs on the way DOWN, before the
    // event ever reaches the modal element, and calls `stopPropagation()` there -- halting delivery
    // to every listener still ahead of it (the modal's own bubble-phase one included) -- but only
    // when a popover is ACTUALLY open (an Esc press with none open must still reach the modal
    // normally, e.g. to close the modal itself).
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const openPopovers = document.querySelectorAll('[data-bs-toggle="popover"][aria-describedby]');
        if (!openPopovers.length) return;
        e.stopPropagation();
        openPopovers.forEach(function (el) {
            const inst = bootstrap.Popover.getInstance(el);
            if (inst) inst.hide();
        });
    }, true);
    // The injected ✕ button: it lives INSIDE the tip, so the "click outside" handler just below
    // deliberately does nothing for a click on it (it's not outside) -- this is the one place that
    // actually closes it.
    document.addEventListener('click', function (e) {
        const closeBtn = e.target.closest('.popover-close-btn');
        if (!closeBtn) return;
        const tip = closeBtn.closest('.popover');
        if (!tip || !tip.id) return;
        const trigger = document.querySelector(`[aria-describedby="${tip.id}"]`);
        const inst = trigger && bootstrap.Popover.getInstance(trigger);
        if (inst) inst.hide();
    });
    // Click outside both the tip AND its own trigger closes it (Bootstrap's own `trigger:'click'`
    // only toggles on the TRIGGER's own click -- it does not, by itself, dismiss on an outside click
    // the way `trigger:'focus'` would via blur -- confirmed by reading Bootstrap's own tooltip.js/
    // popover.js source, not assumed).
    document.addEventListener('click', function (e) {
        document.querySelectorAll('[data-bs-toggle="popover"][aria-describedby]').forEach(function (el) {
            const inst = bootstrap.Popover.getInstance(el);
            if (!inst) return;
            const tip = document.getElementById(el.getAttribute('aria-describedby'));
            if (el.contains(e.target) || (tip && tip.contains(e.target))) return;
            inst.hide();
        });
    });
    // 2026-09-14, real bug found and fixed (explicit report: "ปิดแล้ว focus ต้องไม่กระโดดไปปุ่ม × ของ
    // modal") -- closing a popover (any of the 3 ways above, or the trigger's own toggle click)
    // removes the tip -- including the ✕ button living inside it -- from the DOM. When the element
    // that currently holds focus is removed, the browser moves focus to `document.body`; inside an
    // open Bootstrap Modal (which runs its own focus trap while shown), that in turn gets redirected
    // to the modal's own first focusable element -- its `.btn-close` -- which is what "jumped to the
    // modal's ×" actually was. `hidden.bs.popover` fires on the TRIGGER element itself (confirmed by
    // reading popover.js -- Bootstrap dispatches its own events on the element the instance is
    // attached to, not the tip), so returning focus to it here, as the LAST step of every close path,
    // reliably wins that race regardless of which of the 4 ways the popover was closed.
    document.addEventListener('hidden.bs.popover', function (e) {
        if (e.target && typeof e.target.focus === 'function') {
            e.target.focus({ preventScroll: true });
        }
    });
    // 2026-09-21, 3e-2a: Bootstrap sets `aria-describedby` on the trigger while the tip is open, which
    // is how a screen reader finds the CONTENT -- but it never sets `aria-expanded`, which is how one
    // announces that the control is a disclosure at all, open or shut. Wired here, next to the focus
    // return, so every popover in the app gets it from the one place that already owns open/close --
    // never per caller. Set on show (not shown) so the attribute is already correct by the time the
    // tip appears, and on hidden so it is only cleared once the tip is really gone.
    document.addEventListener('show.bs.popover', function (e) {
        if (e.target && e.target.setAttribute) e.target.setAttribute('aria-expanded', 'true');
    });
    document.addEventListener('hidden.bs.popover', function (e) {
        if (e.target && e.target.setAttribute) e.target.setAttribute('aria-expanded', 'false');
    });
}
// 2026-09-13, §1 follow-up, explicit instruction -- consolidates 3 near-identical per-page functions
// that all did exactly this (payroll/detail.js's own activateTabFromHash(), employee/list.js's own
// activateEmployeeTopTabFromHash(), employee/detail.js's own activateEmployeeTabFromHash() -- this
// app's own "ซ้ำ=shared"/"ห้าม mirror-copy" rule, not previously applied here) into ONE shared helper,
// each page's own call site now just passes its own container scope (or nothing, for an unscoped
// page like Payroll Detail).
//
// Also fixes a real bug found while doing this (explicit report: "focus ring ฟ้า...ตอน restore tab
// จาก hash หลัง refresh"): confirmed by reading Bootstrap's own bundled tab.js source directly --
// `Tab.show()` itself never calls `.focus()` on the newly-activated button (it only `.blur()`s the
// one being DEactivated) -- so the stray ring was never coming from Bootstrap's own JS here. The real
// source is the BROWSER'S OWN native URL-fragment behavior: on an actual page load (not a client-side
// tab click), the browser itself tries to focus whatever element matches the current URL's #hash, if
// that element exists and is focusable, completely independent of any JS -- and it does this BEFORE
// this function even runs. An explicit `.blur()` right after `.show()` clears that regardless of
// exactly which mechanism focused it (harmless no-op if nothing was actually focused).
//
// `containerSelector` (optional) scopes the match the same way employee/list.js's own
// #employeeTopTabs-scoped version already did (so a hash matching some OTHER tab-toggle button
// elsewhere on the page, e.g. inside a modal, is never mistakenly activated) -- omit it for an
// unscoped page (payroll/detail.js's own usage, which only ever had one tab group to begin with).
function activateTabFromHash(containerSelector) {
    const hash = (location.hash || '').replace('#', '');
    if (!hash) return;
    const $btn = $('#' + CSS.escape(hash));
    if (!$btn.length || $btn.attr('data-bs-toggle') !== 'tab') return;
    if (containerSelector && !$btn.closest(containerSelector).length) return;
    bootstrap.Tab.getOrCreateInstance($btn[0]).show();
    $btn.trigger('blur');
}
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
    const $existingFooter = $content.find('.modal-footer').first();
    if (!$existingFooter.length && modalFooterTypeOf($modal) === 'view') {
        // langData reflects whichever language is currently active at the moment this modal opens
        // (not hardcoded English) -- same `langData['close']` key every other Close button in this
        // app already uses, falling back to the English literal only if the key itself is missing.
        // 2026-09-14, real bug found and fixed (explicit report: "ปุ่ม 'ปิด/Close' ใน footer modal
        // ไม่เปลี่ยนภาษาตอนสลับ ต้อง refresh") -- this button's text was a plain string baked in ONCE
        // at injection time with no `data-i18n` marker at all, so updateText()'s app-wide language
        // sweep (which matches on `[data-i18n]`, see its own docblock) could never find it again to
        // update it. Once injected, this `<div class="modal-footer">` stays in the DOM permanently
        // (Bootstrap only hides a modal on close, never removes it) -- so `!$existingFooter.length`
        // above is only ever true on a modal's FIRST open, meaning every later language switch left
        // this exact button frozen in whichever language was active that first time, for the rest of
        // the page's life, on every `data-footer="view"` modal app-wide (empAdjustmentsModal,
        // runDetailBreakdownModal, ...). `data-i18n="close"` here is the actual
        // fix; a full page reload "fixed" it before only because that re-runs this same injection
        // from scratch with fresh langData, not because anything was truly in sync.
        $('<div class="modal-footer"></div>')
            .append(`<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">${(langData && langData['close']) || 'Close'}</button>`)
            .appendTo($content);
    }
});
// 2026-09-14, same bug fix, defensive companion: re-sweep THIS modal's own [data-i18n] elements on
// every real open (not just the very first), scoped to the modal itself (cheap -- one small subtree,
// not the whole document) rather than relying solely on whatever the LAST page-wide changeLanguage()
// call happened to cover. Guards the same failure shape for any future case where a modal's markup
// (static OR JS-rendered once into a container, e.g. a future "build once, re-show" pattern) carries
// `data-i18n` but the element didn't exist in the DOM yet the last time updateText() ran page-wide.
$(document).on('shown.bs.modal', '.modal', function () {
    if (typeof updateText === 'function') updateText(this);
});
// Bootstrap 5's own _hideModal() unconditionally strips `modal-open`/overflow/scrollbar padding from
// <body> on every modal close, with no check for another still-open modal underneath (verified in
// bootstrap.bundle.js) -- stacking a 2nd modal (e.g. Employee Quick View) on top of a 1st (e.g.
// Approval Timeline) and closing only the top one broke the bottom one's scroll lock/backdrop padding.
// Re-apply what Bootstrap tore down whenever another .modal.show still remains, using the same
// scrollbar-width formula Bootstrap itself uses -- deliberately not touching any bootstrap._-prefixed
// internal API for forward-compatibility.
$(document).on('hidden.bs.modal', '.modal', function () {
    const remaining = document.querySelectorAll('.modal.show');
    if (!remaining.length) return;
    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';
    const scrollbarWidth = Math.abs(window.innerWidth - document.documentElement.clientWidth);
    if (scrollbarWidth > 0) {
        document.body.style.paddingRight = `${scrollbarWidth}px`;
    }
});
// 2026-09-11, Batch 4 item 2c -- companion fix to the stacked-modal scroll-lock re-apply just above
// (same "more than one real Bootstrap Modal instance open at once" problem family, so it lives right
// alongside it): Bootstrap 5's own CSS gives EVERY `.modal`/`.modal-backdrop` the exact same fixed
// z-index (1055/1050, confirmed in the bundled bootstrap.css) regardless of how many are open --
// there is no built-in per-instance increment for two genuinely separate, independently-dismissible
// Modal instances stacked on top of each other (as opposed to e.g. a SweetAlert2 confirm on top of a
// Bootstrap modal, which already works fine since SweetAlert2 manages its own, much higher z-index
// range). Without this, whichever of the two modals happens to sit LATER in the page's static HTML
// source order wins the z-index tie by DOM order alone -- fragile, and wrong whenever the visually
// "inner" modal's own markup happens to sit earlier in modals.php than the "outer" one it's meant to
// stack on top of. Generic on purpose (not scoped to any one modal pair) -- bumps whichever modal is
// NOT the first one open, plus its own just-appended backdrop, using the same technique Bootstrap's
// own docs have long recommended for nested modals. A single modal opening alone (the normal case,
// ~100+ other modals in this app) hits the `stackLevel <= 0` guard and is untouched.
// 2026-09-17, R1 follow-up, real bug found by measuring: this ran on `shown.bs.modal`, which fires
// AFTER the fade-in has finished -- so a stacked modal played its whole entrance at the base level
// (losing the tie to the modal underneath by DOM order) and only then jumped in front. Everything
// that decides the stacking now happens BEFORE anything is visible:
//   - `show.bs.modal` fires at the very start of Modal.show(), before the transition -- the class
//     goes on there, and the level comes from a CSS rule, not from a style written by JS.
//   - the modal element is moved to the END of <body> in the same breath, so DOM order agrees with
//     the z-index instead of fighting it.
//   - the nested BACKDROP cannot be touched here at all: Bootstrap creates it later inside show().
//     It is raised by a CSS rule that matches any backdrop preceded by another one, which applies
//     the instant it is inserted -- no callback to be late.
// The levels themselves still live in tokens.css (the scale is what says a stacked modal sits BELOW
// a popover, a SweetAlert dialog and a toast); this file no longer writes any of them.
$(document).on('show.bs.modal', '.modal', function () {
    const stackLevel = document.querySelectorAll('.modal.show').length;
    if (stackLevel <= 0) return;
    this.classList.add('modal-nested');
    if (this.parentElement === document.body && document.body.lastElementChild !== this) {
        document.body.appendChild(this);
    }
});
$(document).on('hidden.bs.modal', '.modal', function () {
    this.classList.remove('modal-nested');
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
    add_manual_line: 'action_add_manual_line', update_manual_line: 'action_update_manual_line', remove_manual_line: 'action_remove_manual_line',
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
// 2026-09-10, Batch 3A item 2 (explicit report: "step แสดง 'รออนุมัติ' เป็นขั้นที่ผ่านแล้วแม้รอบอนุมัติ
// แล้ว ทำให้อ่านสับสน") -- ONE shared run-lifecycle state->step/label/tone mapping, same
// auditActionLabel() consolidation precedent above. Previously duplicated as detail.js's own
// RUN_TIMELINE_STEPS/computeTimelineProgress() (full-size spine, Detail page) and index.js's own
// MINI_TIMELINE_STEPS/computeMiniTimelineProgress() (dot-chain, List page) -- each independently
// re-derived the SAME reachedIdx/branch progress logic and, critically, used a single STATIC
// label per step regardless of whether that step was already done, currently active, or not yet
// reached -- e.g. step 3 ("การอนุมัติ") always showed the plain state_pending_approval text
// ("รออนุมัติ") even once the run had actually moved on to approved/paid/locked, reading as if the
// run were still stuck waiting. Every step below now resolves ONE of 2 labels (pending vs done) --
// or, for step 3 specifically, one of its real branch outcomes (rejected/need_info/cancelled) --
// per this same distinction the CSS tone classes (done/current/rejected/need_info/cancelled) were
// already computing correctly; only the LABEL TEXT was ever wrong, colors were already fine.
// 2026-09-13, §6 item C follow-up ("ไอคอนขาวของขั้นปัจจุบัน"): `icon` here is the ONE place this
// mapping lives -- status-stepper.php/renderStatusStepper() never hardcode a step->icon table of
// their own, they just render whatever `icon` string a step object carries (see that partial's own
// docblock). Values are BARE glyph classes (no `fa-solid`/weight prefix) -- matches the ALREADY-
// EXISTING consumer of this exact field, index.js's own mini-timeline
// (`<i class="fa-solid ${step.icon}">`, prepends the weight class itself at render time) -- changing
// these values therefore also changes the List page's own mini-timeline dots, not just Payroll
// Detail's stepper; this function is the single shared source for both, by design (see this file's
// own consolidation comment above), not something this round scoped down to one page.
const RUN_LIFECYCLE_STEPS = [
    { key: 'draft', icon: 'fa-calculator', pendingKey: 'state_draft', doneKey: 'step_draft_done', dateField: 'created_at' },
    { key: 'pending_approval', icon: 'fa-paper-plane', pendingKey: 'step_submit_pending', doneKey: 'step_submit_done', dateField: 'submitted_at' },
    { key: 'approved', icon: 'fa-list-check', pendingKey: 'state_pending_approval', doneKey: 'state_approved', dateField: 'approved_at' },
    { key: 'paid', icon: 'fa-money-bill', pendingKey: 'step_paid_pending', doneKey: 'state_paid', dateField: 'paid_at' },
    { key: 'locked', icon: 'fa-lock', pendingKey: 'step_locked_pending', doneKey: 'state_locked', dateField: 'locked_at' },
];
const RUN_LIFECYCLE_BRANCH_INFO = {
    rejected: { icon: 'fa-xmark', labelKey: 'step_approval_rejected' },
    need_info: { icon: 'fa-circle-info', labelKey: 'step_approval_need_info' },
    cancelled: { icon: 'fa-ban', labelKey: 'state_cancelled' },
};
// A cancelled run's own audit_log always ends with the 'cancel' action -- its from_state (the last
// state it actually sat in right before being cancelled) says how far up the spine to mark done.
// List rows don't carry the full audit_log (only run.get() does, see runLifecycleSteps()'s own
// `showDates` param below), so PayrollRunModel::list() precomputes the same fact into a
// `cancelled_from_state` column instead -- this reads whichever of the two is present.
function runLifecycleCancelledFromState(run) {
    const log = run.audit_log;
    if (log && log.length) {
        const last = log[log.length - 1];
        if (last && last.action === 'cancel') return last.from_state;
    }
    return run.cancelled_from_state || 'draft';
}
function computeRunLifecycleProgress(run) {
    const state = run.state;
    if (state === 'rejected') {
        // Rejection always happens FROM pending_approval -- draft+pending_approval both actually
        // happened, the "Approved" slot is where the rejection branch shows instead.
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'rejected' } };
    }
    if (state === 'need_info') {
        return { reachedIdx: 1, branch: { atIndex: 2, type: 'need_info' } };
    }
    if (state === 'cancelled') {
        const fromKey = runLifecycleCancelledFromState(run);
        if (fromKey === 'draft') {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        // 'rejected' isn't a spine step itself (it's a branch off pending_approval) -- treat
        // cancelling-from-rejected the same as cancelling from pending_approval for spine purposes.
        const effectiveKey = fromKey === 'rejected' ? 'pending_approval' : fromKey;
        const idx = RUN_LIFECYCLE_STEPS.findIndex(s => s.key === effectiveKey);
        if (idx < 0) {
            return { reachedIdx: -1, branch: { atIndex: 0, type: 'cancelled' } };
        }
        return { reachedIdx: idx, branch: { atIndex: idx + 1, type: 'cancelled' } };
    }
    const idx = RUN_LIFECYCLE_STEPS.findIndex(s => s.key === state);
    return { reachedIdx: idx, branch: null };
}
// Reads the LAST matching audit_log entry so a re-approve after a revert-then-redo cycle shows the
// latest occurrence, not a stale earlier one. Same action codes AUDIT_ACTION_LABEL_KEYS above
// already maps (markPaid, not mark_paid).
// 2026-09-10, Batch 3A item 3, real bug caught before shipping: this is called by MORE than just
// Detail's own full spine now -- the Approval Timeline modal's Paid/Locked stages (this same item)
// need a date on ALL 3 pages that open it, but List/Approval Queue's copies fetch the run via
// api/payroll-run.approval-timeline, whose own audit_log was intentionally stripped in item 1 (its
// "History" section was cut) -- with no fallback this would have silently shown NO date there,
// a regression from the old apvPaidStageHtmlPr/Ap's own `run.paid_at` read. Falls back to the
// run's own timestamp column (step.dateField) whenever audit_log isn't present -- correct in the
// common case (no revert-then-redo for that step) and never reached at all for List's own 5-step
// spine above, which still passes showDates:false.
const RUN_LIFECYCLE_AUDIT_ACTIONS = { pending_approval: 'submit', approved: 'approve', paid: 'markPaid', locked: 'lock' };
function runLifecycleStepDate(run, step) {
    if (step.key === 'draft') return run.created_at || null;
    const action = RUN_LIFECYCLE_AUDIT_ACTIONS[step.key];
    const log = run.audit_log;
    if (log && log.length) {
        for (let i = log.length - 1; i >= 0; i--) {
            if (log[i].action === action) return log[i].performed_at || null;
        }
    }
    return run[step.dateField] || null;
}
// The one function both pages call. `options.showDates` (Detail: true, List: false) is the ONLY
// difference between the two call sites -- everything else (steps/labels/tones/branch) is
// identical. Returns { steps: [{key,cls,icon,label,date}], reachedIdx, currentIndex, branch }.
function runLifecycleSteps(run, options) {
    const showDates = !!(options && options.showDates);
    const { reachedIdx, branch } = computeRunLifecycleProgress(run);
    const currentIndex = reachedIdx + 1;
    const steps = RUN_LIFECYCLE_STEPS.map(function (step, i) {
        let cls = '';
        let icon = step.icon;
        let label = langData[step.pendingKey] || step.key;
        if (branch && branch.atIndex === i) {
            cls = branch.type;
            const info = RUN_LIFECYCLE_BRANCH_INFO[branch.type] || { icon: 'fa-ban', labelKey: null };
            icon = info.icon;
            label = (info.labelKey && langData[info.labelKey]) || branch.type;
        } else if (i <= reachedIdx) {
            cls = 'done';
            icon = 'fa-check';
            label = langData[step.doneKey] || step.key;
        } else if (i === currentIndex) {
            cls = 'current';
        }
        const date = showDates ? runLifecycleStepDate(run, step) : null;
        return { key: step.key, cls, icon, label, date };
    });
    return { steps, reachedIdx, currentIndex, branch };
}
// 2026-09-10, Batch 3A item 2: the 3rd literal duplicate the user named ("List/Detail/modal ใช้
// ร่วมกัน...render แยก 3 ที่") -- byte-identical apvApprovalStageInfoPr()/Rd()/Ap() in
// index.js/detail.js/approval.js, one per copy of the Approval Timeline modal's own "Approval"
// stage box (tone/icon/label for that ONE station, not the whole 5-step spine above). Same
// auditActionLabel() consolidation precedent. Item 3 (adding "จ่ายเงิน"/"ปิดรอบ" stations to this
// same modal) extends THIS single function next, not 3 copies of it.
function apvApprovalStageInfo(state) {
    switch (state) {
        case 'pending_approval': return { tone: 'pending', icon: 'fa-hourglass-half', label: langData['state_pending_approval'] || 'Waiting for Approval' };
        case 'need_info': return { tone: 'info', icon: 'fa-circle-info', label: langData['state_need_info'] || 'Need Information' };
        case 'approved': case 'paid': case 'locked': return { tone: 'done', icon: 'fa-check', label: langData['state_approved'] || 'Approved' };
        case 'rejected': return { tone: 'rejected', icon: 'fa-xmark', label: langData['state_rejected'] || 'Not Approved' };
        default: return { tone: 'muted', icon: 'fa-hourglass', label: langData['status_pending'] || 'Not Started' };
    }
}
// 2026-09-10, Batch 3A item 3 (explicit request: "ใช้ avatar function ตัวเดียว...ทำ backlog 'รวม
// apvAvatarHtml Rd/Pr/Ap เป็นตัวเดียวใน app.js' ในข้อนี้เลย") -- byte-identical
// APV_COLORS_RD/PR/AP, apvIconHtml{Rd,Pr,Ap}, apvBadgeHtml{Rd,Pr,Ap}, apvAvatarImgError{Rd,Pr,Ap},
// apvAvatarHtml{Rd,Pr,Ap} across index.js/detail.js/approval.js -- confirmed byte-for-byte
// identical before merging (same verification standard as apvApprovalStageInfo() above), not just
// assumed similar. Consolidated here since this item ALSO needs apvPersonLineHtml()/
// apvCreatedStageHtml() and the new apvPaidStageHtml()/apvLockedStageHtml() split below, all of
// which build on these -- writing that fix 3x instead of once would repeat exactly the mirror-copy
// pattern CLAUDE.md now says not to.
const APV_COLORS = {
    done: { icon: '#16a34a', badgeBg: '#dcfce7', badgeText: '#15803d' },
    pending: { icon: '#f59e0b', badgeBg: '#fef3c7', badgeText: '#b45309' },
    rejected: { icon: '#ef4444', badgeBg: '#fee2e2', badgeText: '#b91c1c' },
    info: { icon: '#0d6efd', badgeBg: '#cfe2ff', badgeText: '#0a58ca' },
    muted: { icon: '#cbd5e1', badgeBg: '#f1f5f9', badgeText: '#64748b' },
};
function apvBadgeHtml(tone, label) {
    const c = APV_COLORS[tone] || APV_COLORS.muted;
    return `<span class="apv-badge" style="background:${c.badgeBg};color:${c.badgeText};">${escapeHtml(label)}</span>`;
}
function apvIconHtml(tone, icon) {
    const c = APV_COLORS[tone] || APV_COLORS.muted;
    return `<div class="apv-stage-icon" style="background:${c.icon};"><i class="fa-solid ${icon}"></i></div>`;
}
function apvAvatarImgError(img) {
    const size = img.getAttribute('data-size');
    const initial = img.getAttribute('data-initial');
    const employeeId = img.getAttribute('data-employee-id');
    const clickAttr = employeeId ? ` data-employee-id="${employeeId}"` : '';
    const clickClass = employeeId ? ' emp-avatar-link' : '';
    const clickStyle = employeeId ? 'cursor:pointer;' : '';
    img.outerHTML = `<span class="apv-person-avatar${clickClass}"${clickAttr} style="width:${size}px;height:${size}px;min-width:${size}px;font-size:${Math.round(size * 0.42)}px;${clickStyle}">${initial}</span>`;
}
// 2026-09-10, Batch 3A item 4 (explicit instruction: "ต่อยอดจาก apvAvatarHtml ที่เพิ่งรวม ไม่สร้าง
// avatar function ตัวที่สอง...ให้เพิ่มเป็น option ของตัวเดิม") -- `options.employeeId` is the ONLY
// addition: when present, the avatar gets a ring border + pointer cursor + `.emp-avatar-link` class
// + `data-employee-id` (the delegated click handler further down opens the quick-view modal). Every
// pre-existing call site (Timeline stages/approver rows, none of which pass a 4th argument) renders
// byte-identical to before -- `options` defaults to `{}` so nothing about their look changed.
// 2026-09-14, Round 3 item 3c-1 follow-up, explicit instruction -- the clickable-avatar "ring" used
// to be an INLINE `border:2px solid #fff` + `box-shadow:0 0 0 1px rgba(0,0,0,.12)`, a hardcoded
// white ring that made no sense once this app started rendering on dark surfaces too (a white ring
// sitting inside/against a dark row reads as an odd, disconnected halo, not "the same surface
// bleeding through around the circle" the effect is meant to convey). Replaced with a plain CSS
// class (`.apv-person-avatar--clickable`, style.css) instead of just swapping the inline hex for a
// var() -- `cursor:pointer` moved there too, so this function's own `style=""` attribute carries
// NOTHING employeeId-conditional anymore, only the always-present sizing that was already there
// for every avatar regardless of clickability. Resting ring = --c-bg (matches whatever surface the
// avatar sits on, light or dark, "blends into the row" rather than a fixed white halo); hover ring
// = --c-primary-soft (same brand-accent-at-low-opacity language this app already uses for "this is
// interactive" elsewhere, §3).
function apvAvatarHtml(name, size, photoPath, options) {
    options = options || {};
    size = size || 26;
    const initial = escapeAttr((name || '?').trim().charAt(0).toUpperCase() || '?');
    const employeeId = options.employeeId;
    const clickAttr = employeeId ? ` data-employee-id="${escapeAttr(employeeId)}"` : '';
    const clickClass = employeeId ? ' emp-avatar-link apv-person-avatar--clickable' : '';
    if (photoPath) {
        return `<img src="${BASE_URL}/${escapeAttr(photoPath)}" alt="" data-size="${size}" data-initial="${initial}"${clickAttr} class="${clickClass.trim()}" style="width:${size}px;height:${size}px;min-width:${size}px;border-radius:50%;object-fit:cover;object-position:center top;" onerror="apvAvatarImgError(this)">`;
    }
    return `<span class="apv-person-avatar${clickClass}"${clickAttr} style="width:${size}px;height:${size}px;min-width:${size}px;font-size:${Math.round(size * 0.42)}px;">${initial}</span>`;
}
function apvPersonLineHtml(name, size, photoPath, options) {
    return `<div style="display:flex;align-items:center;gap:8px;">${apvAvatarHtml(name, size, photoPath, options)}<span class="apv-person-name">${escapeHtml(name || '-')}</span></div>`;
}
// 2026-09-10, Batch 3A item 4 -- app-wide employee quick-view modal, opened by clicking ANY avatar
// rendered via apvAvatarHtml(..., {employeeId}) (Process List's Updated By column, Process Detail's
// employee table, the Approval Timeline modal's Created/Paid/Locked stages -- once those pass an
// employeeId too). One shared modal/handler here instead of a per-page copy, same consolidation
// precedent as everything else in this file.
// 2026-09-14, Phase Design Round 3 item 3c-1 (docs/design/rules.md §9 "Quick-view พนักงาน") --
// header block delegated to employeeHeaderCardHtml() (same twin used by all 6 payroll/detail.js
// modals, §11) instead of this function's own hand-rolled avatar/name markup, so name/code/
// department/position/status-badge render identically everywhere. Body fills the 6 fields NOT
// already covered by the header card -- a missing value renders "-" (never hides its row, per the
// modal's own layout comment in layout/modals.php) so the 2x3 grid never reflows.
function renderEmployeeQuickViewModal(emp) {
    $('#empQuickViewHeaderCard').html(employeeHeaderCardHtml(emp));
    $('#empQuickViewBranch').text((currentLang === 'th' ? emp.branch_name_th : emp.branch_name_en) || emp.branch_name_th || emp.branch_name_en || '-');
    $('#empQuickViewEmploymentType').text((currentLang === 'th' ? emp.employment_type_name_th : emp.employment_type_name_en) || emp.employment_type_name_th || emp.employment_type_name_en || '-');
    $('#empQuickViewHireDate').text(emp.employment_date ? formatDisplayDate(emp.employment_date) : '-');
    $('#empQuickViewPaymentMethod').html(empQuickViewPaymentMethodHtml(emp));
    $('#empQuickViewPhone').text(emp.mobile_no || '-');
    $('#empQuickViewEmail').text(emp.personal_email || '-');
    $('#empQuickViewGoToProfile').attr('href', `${BASE_URL}/employees/${emp.employee_no}`);
}
// Payment method name + (transfer only) bank name & masked account number, e.g. "โอนเข้าบัญชี ·
// กรุงไทย ••••1234" -- the server (EmployeeModel::quickView()) only ever returns the MASKED account
// number, never the decrypted full value, so there's nothing further to redact client-side.
function empQuickViewPaymentMethodHtml(emp) {
    const methodName = (currentLang === 'th' ? emp.payment_method_name_th : emp.payment_method_name_en) || emp.payment_method_name_th || emp.payment_method_name_en;
    if (!methodName) return '-';
    if (emp.payment_method_code === 'transfer' && emp.bank_account_no_masked) {
        const bankName = (currentLang === 'th' ? emp.bank_name_th : emp.bank_name_en) || emp.bank_name_th || emp.bank_name_en || '';
        const bankPart = bankName ? `${escapeHtml(bankName)} ${escapeHtml(emp.bank_account_no_masked)}` : escapeHtml(emp.bank_account_no_masked);
        return `${escapeHtml(methodName)} &middot; ${bankPart}`;
    }
    return escapeHtml(methodName);
}
// 2026-09-11, Batch 3C item 8, explicit instruction: shared header card for the FIRST block of
// every modal-body opened from an employee row (Detail's Calculation Breakdown/Raw Sync Data/
// Manage Items/Comments/Adjustments/Bank Account Assignment) -- avatar (clickable through to the
// same employee quick-view modal every other avatar on this page already opens), name, code,
// department, position. Field names match renderEmployeeQuickViewModal() just above (same
// name_th/surname_th/.../profile_photo_path/department_name_th/en/position_name_th/en convention)
// so a caller can pass a PayrollRunModel::getDetails() row straight through with no reshaping.
// Markup/class only originally, no styling pass -- that comment explicitly said "design phase comes
// later" (ยังไม่จัดสไตล์การ์ด).
//
// 2026-09-13, Round 2 item 6c -- THAT deferred design pass, done here: GENERALIZED this existing
// function in place (same name, same signature, same 6 real call sites in payroll/detail.js keep
// working completely unchanged and just render with the new styling automatically -- not renamed,
// not duplicated, per explicit instruction). 2 real content changes: avatar 48px -> 40px (this
// round's own decided size), and a 2nd line split off (name+code stays line 1, department/position
// moves to its own line 2) to make room for a new status-badge slot on the right --
// `emp.employee_status` is genuinely OPTIONAL here (none of the 6 existing real call sites'
// underlying queries were audited/changed to guarantee they populate it -- real-page/backend work,
// out of scope this round) -- the badge is only rendered `if (emp.employee_status)`, so a caller
// missing that field renders exactly the same as before (no badge slot at all), never a broken
// "undefined" badge. PHP twin: app/views/partials/emp-header-card.php (new, not previously
// PHP-reachable at all -- this function was JS-only before).
//
// 2026-09-21, 3e-2b: `options.actionHtml` -- raw HTML the caller owns, rendered in the card's own
// RIGHT slot after the status badge. Same shape/contract as `renderTimeline()`'s `item.actionHtml`
// and `statusBadgeHtml()`'s `{menu}` (§6/§5): this function does not know what the control means and
// binds no handler for it. JS-only, like `emptyStateHtml()`'s own `action.onClick` -- the PHP twin
// (emp-header-card.php) has no caller that needs it, so it is not mirrored there.
function employeeHeaderCardHtml(employee, options) {
    const emp = employee || {};
    const actionHtml = (options && options.actionHtml) || '';
    const name = (currentLang === 'th' ? `${emp.name_th || ''} ${emp.surname_th || ''}` : `${emp.name_en || emp.name_th || ''} ${emp.surname_en || emp.surname_th || ''}`).trim() || '-';
    const department = (currentLang === 'th' ? emp.department_name_th : emp.department_name_en) || emp.department_name_th || emp.department_name_en || '-';
    const position = (currentLang === 'th' ? emp.position_name_th : emp.position_name_en) || emp.position_name_th || emp.position_name_en || '-';
    const badgeHtml = emp.employee_status ? statusBadgeHtml(emp.employee_status, 'employee_status') : '';
    return `<div class="emp-header-card">
        ${apvAvatarHtml(name, 40, emp.profile_photo_path, emp.employee_id ? { employeeId: emp.employee_id } : null)}
        <div class="emp-header-card-body">
            <div class="emp-header-card-line1">
                <span class="emp-header-card-name">${escapeHtml(name)}</span>
                <span class="emp-header-card-code">${escapeHtml(emp.employee_no || '-')}</span>
            </div>
            <div class="emp-header-card-line2">${escapeHtml(department)} &middot; ${escapeHtml(position)}</div>
        </div>
        ${badgeHtml ? `<div class="emp-header-card-badge">${badgeHtml}</div>` : ''}${actionHtml ? `<div class="emp-header-card-action">${actionHtml}</div>` : ''}
    </div>`;
}
// 2026-09-11, Batch 3C item 3, explicit instruction: "ห้าม trigger row click ไปหน้า Detail
// (stopPropagation ใน handler กลางของ .emp-avatar-link ไม่ใช่แก้รายหน้า)" -- a plain jQuery
// `$(document).on('click', '.emp-avatar-link', ...)` attaches its real native listener on
// `document` itself, in the BUBBLE phase. A row-click handler delegated on a closer ancestor
// (e.g. `#tb_payroll_run tbody`) is physically CLOSER to the click target, so during the native
// bubble phase it always fires FIRST, regardless of source-code order -- calling
// `stopPropagation()` from this handler would be too late to stop it (same class of bug already
// documented/fixed per-page for `.stc-action`/`.btn-quick-submit-run` in payroll/index.js's own
// row-click handler, which needed its OWN exclusion added there since it fires before this one
// ever runs). Using the native CAPTURE phase here instead (`addEventListener(..., true)`) makes
// this the FIRST handler to see the click on ITS way down to the target, before any bubble-phase
// row-click handler on any page gets a chance -- `stopPropagation()` during capture halts the
// entire dispatch, bubble phase included, so no per-page row handler needs its own exclusion at
// all. This is the one central place a `.emp-avatar-link` click is handled anywhere in the app.
document.addEventListener('click', function (e) {
    const $link = $(e.target).closest('.emp-avatar-link');
    if (!$link.length) return;
    e.stopPropagation();
    const employeeId = $link.data('employee-id');
    if (!employeeId) return;
    $.ajax({
        url: `${BASE_URL}/api/employee.quick-view`,
        method: 'GET',
        data: { id: employeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                if (typeof showWarning === 'function') showWarning(res.message || 'An error occurred.');
                return;
            }
            renderEmployeeQuickViewModal(res.data);
            new bootstrap.Modal(document.getElementById('employeeQuickViewModal')).show();
        },
        error: function () {
            if (typeof showWarning === 'function') showWarning((langData && langData['save_failed']) || 'An error occurred while loading the data.');
        }
    });
}, true);
// "Created" stage -- always done (a run exists the moment it's created, nothing to wait for), so
// unlike Paid/Locked below it has no pending state to render.
function apvCreatedStageHtml(run) {
    const creator = (currentLang === 'th' ? run.created_by_name_th : run.created_by_name_en) || run.created_by_name_th || run.created_by_name_en || '-';
    return `
        <div class="apv-stage apv-stage-last">
            <div class="apv-stage-marker">${apvIconHtml('done', 'fa-plus')}</div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['stage_created'] || 'Created'}</span>
                    ${apvBadgeHtml('done', langData['stage_created'] || 'Created')}
                </div>
                <div class="apv-stage-date">${run.created_at ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(run.created_at) : escapeHtml(run.created_at)) : ''}</div>
                <div class="apv-stage-body">${apvPersonLineHtml(creator, 26, run.created_by_profile_photo_path, { employeeId: run.created_by })}</div>
            </div>
        </div>
    `;
}
// "Paid"/"Locked" stages (2026-09-10, Batch 3A item 3 -- were ONE merged box, apvPaidStageHtmlRd(),
// that never showed who paid/locked at all, only a date, and never had a separate station for
// Locked). Split into 2, each pulling its own tone/label/date from runLifecycleSteps()'s own
// 'paid'/'locked' entries (passed in as `lifecycle`, computed ONCE by the caller so this doesn't
// re-derive the whole 5-step progress twice per modal render) instead of re-deriving state -> tone/
// label here -- exactly the "use runLifecycleSteps()/apvApprovalStageInfo() from item 2, don't
// build a new mapping" instruction. Who+photo come from PayrollRunModel::get()'s own new
// paid_by_name_*/paid_by_profile_photo_path (locked_by_* likewise) -- neither existed before this
// item; the old merged box could never have shown a person even if it had wanted to.
function apvStageByKey(lifecycle, key) {
    return lifecycle.steps.find(function (s) { return s.key === key; }) || { cls: '', label: '' };
}
// 2026-09-11, Batch 3C item 1, explicit instruction: "เพิ่ม station 'ส่งอนุมัติ' ระหว่าง สร้างรายการ
// กับ การอนุมัติ: ใครส่ง + เมื่อไหร่ (จาก audit action submit หรือ submitted_by/submitted_at) ใช้
// runLifecycleSteps ที่มี ไม่สร้าง mapping ใหม่" -- runLifecycleSteps() already carries this exact
// station (its 'pending_approval' entry -- confusingly named after the STATE that follows it, but
// its own labels/dateField are genuinely about the SUBMIT action: step_submit_pending/
// step_submit_done/submitted_at, and runLifecycleStepDate() already reads the 'submit' audit action
// first when present) for the HORIZONTAL mini-timeline/process-timeline -- this just gives the
// VERTICAL apv-stage modal the same station those already show, via the exact same shared
// `lifecycle` object every other stage here already reads from (apvStageByKey(), same pattern as
// apvPaidStageHtml()/apvLockedStageHtml() below) -- no new date/action mapping anywhere.
function apvSubmittedStageHtml(run, lifecycle) {
    const step = apvStageByKey(lifecycle, 'pending_approval');
    const done = step.cls === 'done';
    const tone = done ? 'done' : 'muted';
    const submitter = (currentLang === 'th' ? run.submitted_by_name_th : run.submitted_by_name_en) || run.submitted_by_name_th || run.submitted_by_name_en || '';
    const bodyHtml = done
        ? apvPersonLineHtml(submitter, 26, run.submitted_by_profile_photo_path, { employeeId: run.submitted_by })
        : `<span class="apv-muted-text">${langData['waiting_to_be_submitted'] || 'Not yet submitted for approval.'}</span>`;
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtml(tone, done ? 'fa-paper-plane' : 'fa-flag')}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['stage_submitted'] || 'Submitted'}</span>
                    ${apvBadgeHtml(tone, step.label)}
                </div>
                ${done && step.date ? `<div class="apv-stage-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(step.date) : escapeHtml(step.date)}</div>` : ''}
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
function apvPaidStageHtml(run, lifecycle) {
    const step = apvStageByKey(lifecycle, 'paid');
    const done = step.cls === 'done';
    const tone = done ? 'done' : 'muted';
    const payer = (currentLang === 'th' ? run.paid_by_name_th : run.paid_by_name_en) || run.paid_by_name_th || run.paid_by_name_en || '';
    const bodyHtml = done
        ? apvPersonLineHtml(payer, 26, run.paid_by_profile_photo_path, { employeeId: run.paid_by })
        : `<span class="apv-muted-text">${langData['waiting_for_approval_to_complete'] || 'Waiting for the approval process to complete.'}</span>`;
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtml(tone, done ? 'fa-money-check-dollar' : 'fa-flag')}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['state_paid'] || 'Paid'}</span>
                    ${apvBadgeHtml(tone, step.label)}
                </div>
                ${done && step.date ? `<div class="apv-stage-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(step.date) : escapeHtml(step.date)}</div>` : ''}
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
function apvLockedStageHtml(run, lifecycle) {
    const step = apvStageByKey(lifecycle, 'locked');
    const done = step.cls === 'done';
    const tone = done ? 'done' : 'muted';
    const locker = (currentLang === 'th' ? run.locked_by_name_th : run.locked_by_name_en) || run.locked_by_name_th || run.locked_by_name_en || '';
    const bodyHtml = done
        ? apvPersonLineHtml(locker, 26, run.locked_by_profile_photo_path, { employeeId: run.locked_by })
        : `<span class="apv-muted-text">${langData['waiting_for_payment_to_complete'] || 'Waiting for the payment process to complete.'}</span>`;
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtml(tone, done ? 'fa-lock' : 'fa-flag')}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['state_locked'] || 'Locked'}</span>
                    ${apvBadgeHtml(tone, step.label)}
                </div>
                ${done && step.date ? `<div class="apv-stage-date">${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(step.date) : escapeHtml(step.date)}</div>` : ''}
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
// 2026-09-11, Batch 3C item 1, explicit instruction: "รวม render ทั้งหมดเป็น function เดียวใน app.js
// ที่ทุกหน้าเรียก" -- apvApproverTone{Rd,Ap,Pr}()/apvApproverLabel{Rd,Ap,Pr}()/
// apvApproverSubstepHtml{Rd,Ap,Pr}()/apvStepDotTone{Rd,Ap,Pr}()/apvStepDotsHtml{Rd,Ap,Pr}()/
// apvStepGroupHtml{Rd,Ap,Pr}()/apvApprovalStageHtml{Rd,Ap,Pr}() were confirmed byte-for-byte
// identical across index.js/detail.js/approval.js (aside from the suffix itself) before merging --
// same verification standard as apvApprovalStageInfo()/apvAvatarHtml() above, not just assumed
// similar. Resolves the BACKLOG.md entry "Consolidate apvApproverSubstepHtml* (Rd/Pr/Ap) into
// app.js" (Batch 2 item 2) as a side effect -- that item's own scope turned out to be a strict
// subset of what this one needed anyway.
function apvApproverTone(status) {
    return { approved: 'done', rejected: 'rejected', need_info: 'info', pending: 'pending', not_applicable: 'muted' }[status] || 'muted';
}
function apvApproverLabel(status) {
    const key = { approved: 'status_approved', rejected: 'status_rejected', need_info: 'state_need_info', pending: 'status_pending' }[status];
    return (key && langData[key]) || status;
}
function apvApproverSubstepHtml(a) {
    const name = (currentLang === 'th' ? a.name_th : a.name_en) || a.name_th || a.name_en || a.employee_no;
    return `<div class="apv-substep">
        <div class="apv-substep-head">
            <span class="apv-substep-label">${apvAvatarHtml(name, 22, a.profile_photo_path)}${escapeHtml(name)}</span>
            ${apvBadgeHtml(apvApproverTone(a.status), apvApproverLabel(a.status))}
        </div>
        ${a.acted_at ? `<div class="apv-substep-date"><i class="fa-regular fa-calendar"></i> ${typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(a.acted_at) : escapeHtml(a.acted_at)}</div>` : ''}
        ${a.note ? `<div class="apv-substep-remark">${escapeHtml(a.note)}</div>` : ''}
    </div>`;
}
function apvStepDotTone(step) {
    if (!step.unlocked) return 'apv-step-dot-locked';
    if (step.status === 'approved') return 'apv-step-dot-approved';
    if (step.status === 'rejected') return 'apv-step-dot-rejected';
    return 'apv-step-dot-pending';
}
function apvStepDotsHtml(steps) {
    return `<div class="apv-step-dots">` + steps.map((s, i) => {
        const lockIcon = !s.unlocked ? `<span class="apv-step-dot-lock-icon"><i class="fa-solid fa-lock"></i></span>` : '';
        const icon = s.status === 'approved' ? '<i class="fa-solid fa-check"></i>' : (s.status === 'rejected' ? '<i class="fa-solid fa-xmark"></i>' : s.step_order);
        const connector = i < steps.length - 1 ? `<div class="apv-step-dot-connector${s.status === 'approved' ? ' apv-step-dot-connector-done' : ''}"></div>` : '';
        return `<div class="apv-step-dot-wrap" title="${escapeHtml(s.step_name || '')}">
            <div class="apv-step-dot ${apvStepDotTone(s)}">${icon}</div>
            ${lockIcon}
        </div>${connector}`;
    }).join('') + `</div>`;
}
function apvStepGroupHtml(step) {
    const badgeHtml = !step.unlocked
        ? `<span class="apv-badge" style="background:#f1f5f9;color:#64748b;"><i class="fa-solid fa-lock me-1"></i>${langData['step_locked'] || 'Locked'}</span>`
        : apvBadgeHtml(apvApproverTone(step.status), apvApproverLabel(step.status));
    const stepLabel = (langData['step_label'] || 'Step {n}').replace('{n}', step.step_order);
    const approversHtml = step.approvers.length
        ? step.approvers.map(apvApproverSubstepHtml).join('')
        : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`;
    return `<div class="apv-step-group">
        <div class="apv-step-group-head">
            <span class="apv-step-group-title">${escapeHtml(stepLabel)}${step.step_name ? ': ' + escapeHtml(step.step_name) : ''}</span>
            ${badgeHtml}
        </div>
        <div class="apv-step-group-body">${approversHtml}</div>
    </div>`;
}
function apvApprovalStageHtml(run) {
    const info = apvApprovalStageInfo(run.state);
    const steps = (run.approval_flow && run.approval_flow.steps) || null;
    const approvers = (run.approval_flow && run.approval_flow.approvers) || [];
    const bodyHtml = (steps && steps.length)
        ? apvStepDotsHtml(steps) + steps.map(apvStepGroupHtml).join('')
        : (approvers.length
            ? approvers.map(apvApproverSubstepHtml).join('')
            : `<span class="apv-muted-text">${langData['no_approvers_configured'] || 'No employee currently holds approval permission for payroll runs.'}</span>`);
    return `
        <div class="apv-stage">
            <div class="apv-stage-marker">${apvIconHtml(info.tone, info.icon)}<div class="apv-stage-line"></div></div>
            <div class="apv-stage-content">
                <div class="apv-stage-head">
                    <span class="apv-stage-title">${langData['approval_flow_title'] || 'Approval'}</span>
                    ${apvBadgeHtml(info.tone, info.label)}
                </div>
                <div class="apv-stage-body">${bodyHtml}</div>
            </div>
        </div>
    `;
}
// 2026-09-11, Batch 3C item 1, explicit instruction: "ให้รวม render ทั้งหมดเป็น function เดียวใน app.js
// ที่ทุกหน้าเรียก" -- the ONE function Detail/Approval Queue/Process List's own Approval Timeline
// modals all call for their `.apv-timeline` body now (replacing 3 near-identical inline template
// literals). Order top-to-bottom in the DOM is newest-first (matches every other reversed-log
// convention on this page) -- "ลำดับ station จากล่างขึ้นบน: สร้าง -> ส่งอนุมัติ -> การอนุมัติ -> จ่ายเงิน ->
// ปิดรอบ" (bottom-to-top) means Created renders LAST (bottom, apv-stage-last) and Locked renders
// FIRST (top) in this same string. `lifecycle` is computed ONCE by the caller (runLifecycleSteps(),
// showDates:true) and passed through to Locked/Paid/Submitted, exactly the existing convention those
// 3 already followed before this consolidation -- Approval/Created don't need it (their own
// tone/date logic reads run.approval_flow/run.created_at directly, unchanged).
function renderApprovalTimelineBody(run) {
    const lifecycle = runLifecycleSteps(run, { showDates: true });
    return `
        <div class="apv-timeline">
            ${apvLockedStageHtml(run, lifecycle)}
            ${apvPaidStageHtml(run, lifecycle)}
            ${apvApprovalStageHtml(run)}
            ${apvSubmittedStageHtml(run, lifecycle)}
            ${apvCreatedStageHtml(run)}
        </div>
    `;
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
            // 2026-09-13, Round 3 "เก็บตกรอบ 4", defensive backstop added alongside the REAL fix (see
            // table-column-filter.js's own initExcelColumnFilters(), which now moves data-i18n off a
            // `<th>` onto its own leaf title span instead of leaving it on the `<th>` after rebuilding
            // that cell's children) -- explicit report: switching language destroyed the sort-arrow/
            // filter-button DOM that function injects into a column header, because a plain
            // `.text(value)` on an element -- same as `.html()` -- wipes out EVERY child node first,
            // and the `<th>` still carried its OWN original `data-i18n` attribute after being
            // restructured by other code. That specific case is fixed at its real source now (an
            // element's own `data-i18n` marker should always live on the true leaf that holds just its
            // label, not on a container something else also manages), but this sweep is the ONE place
            // in the whole app any `[data-i18n]` element passes through -- hardened here too so a
            // FUTURE mistake of the same shape (some other mechanism injects real child elements into
            // a container that still carries its own `data-i18n`) degrades to "this one label's text
            // didn't update" instead of "silently deletes whatever real DOM was living inside it".
            // `$el.children().length` (real ELEMENT children, e.g. the icon-prefix case above already
            // handles the ONE legitimate reason for those) is 0 for the overwhelming majority of this
            // app's data-i18n consumers (a plain `<span data-i18n="...">label</span>`) -- those still
            // take the plain `.text(value)` branch below, byte-identical to before this change.
            else if ($el.children().length > 0) {
                console.warn('[updateText] [data-i18n="' + key + '"] has child elements -- skipping .text() to avoid destroying them. Move the data-i18n marker onto a leaf element instead.', $el[0]);
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
            sSearchPlaceholder: lang.searchPlaceholder,
            sLengthMenu: lang.lengthMenu,
            sZeroRecords: lang.zeroRecords,
            // 2026-09-19, 4c: genuinely missing here (getTableLang() has returned `emptyTable` since
            // 2026-09-11, this refresh never copied it). langData is fetched async, so every table
            // built before that fetch resolves took DataTables' own English "No data available in
            // table" and, unlike every other string in this list, never got it replaced -- which is
            // what an empty table on a Thai page has been reading in English ever since.
            sEmptyTable: lang.emptyTable,
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
        // NOT `lang.search || 'Search'`: the label is deliberately an EMPTY string now (getTableLang(),
        // 2026-09-16) and `'' || 'Search'` puts the English word back on every language refresh --
        // seen live, the toolbar read "Search" again the moment applyLanguage() ran.
        const searchLabelText = String(lang.search === undefined || lang.search === null ? '' : lang.search).replace('_INPUT_', '').trim();
        $wrapper.find('.dt-search > label').text(searchLabelText);
        // 2026-09-13, same fix as the label above, same reason -- the input's own `placeholder`
        // attribute is ALSO written once at construction time and never re-read from
        // settings.oLanguage on a later redraw (confirmed directly from the DataTables source, same
        // as the label/length-menu text this comment block already documents) -- patched here too so
        // a live language switch updates it instead of leaving it stuck in whatever language was
        // active the first time this table was ever built.
        $wrapper.find('.dt-search > input').attr('placeholder', lang.searchPlaceholder || '');
        // 2026-09-16: the label is empty now (see getTableLang()), so the accessible name lives on
        // this attribute -- it has to follow the language switch just like the placeholder above.
        $wrapper.find('.dt-search > input').attr('aria-label', lang.searchAriaLabel || lang.searchPlaceholder || '');

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

// ============================================================================
// Payroll Run form (shared Create/Edit) -- Batch 3C item 4, sub-step 4a
// ============================================================================
// #payrollRunModal/#payrollRunForm (layout/modals.php) is now the ONE form used by both the
// Process List's "Add"/"Pull from Origami" flow (public/js/payroll/index.js) and Process Detail's
// "Edit" flow (public/js/payroll/detail.js) -- previously two independently-hand-maintained modals
// (#payrollRunModal + #editRunModal) whose field sets and JS had already drifted apart in several
// places by the time this was consolidated (confirmed via a full grep/read comparison first -- see
// this item's own commit history). Every function below is id-scoped to the `run_*`/`runXxx` names
// that used to belong to the Create form alone; the Edit-only `edit_run_*`/`editRunXxx` markup and
// its own near-duplicate JS (`...Rd()`/`setEdit...()`) were deleted rather than kept as a second copy
// -- per this project's own standing rule ("mirror-by-copy is not acceptable... generalize the
// existing function instead").
//
// `#run_id` (empty = creating; a real id = editing) is the ONE signal every function below branches
// on when Create's and Edit's own pre-existing behavior genuinely differs (currently only
// updateComputeStatutoryVisibility()'s use_flat_tax_rate-row rule -- see that function's own
// docblock; Decision 1 of this same item, still pending, will replace that branch with one unified
// rule instead of removing the branch point itself).
//
// What did NOT need a branch at all, because the two forms' own existing behavior already agreed
// once fed through the exact same functions: setOffCycleMode()/setSupplementalPullMode() themselves
// (Edit's `#btnEditRun` populate code below now calls whichever ONE of the two Create's own two
// entry flows would have called, exactly mirroring "a manual off-cycle Add" vs "a Pull-sync create"),
// every merge-target preview/resolve function (Edit's own extra `exclude_id` need is now read
// generically off `#run_merge_target_id`'s own `data-exclude-id` attribute, set/cleared on open --
// see setRunFormExcludeId() below -- rather than a second copy of each function with one extra key
// in its ajax `data`).
//
// What genuinely could NOT be merged, because it depends on an ALREADY-EXISTING run's own live
// state that Create has no equivalent of at all, stayed in detail.js as Edit-only orchestration:
// editRunCycleToggleRiskyRd() (a risk warning specific to editing a run that already has employees)
// and updateEditRunTypeSectionRd() (recomputes offCycle-or-supplemental live as the cycle dropdown
// changes DURING an edit -- Create's own two flows are each populated once and never need to
// re-derive this mid-session, since neither the sync process nor its run_kind can change after a
// Pull, and the manual Add flow's schedule choice already fully determines it via the radio alone).

// Sets (or clears) #run_merge_target_id's own data-exclude-id -- the ONE difference every merge-
// target preview/resolve function below needs to know about between the two modes: an existing run
// being edited must never be offered as its own merge target; a brand-new run being created has no
// id yet to exclude.
function setRunFormExcludeId(runId) {
    $('#run_merge_target_id').attr('data-exclude-id', runId || '');
}

function resetRunForm() {
    $('#payrollRunForm')[0].reset();
    $('.is-invalid').removeClass('is-invalid');
    $('#run_id').val('');
    $('#run_sync_run_kind').val('');
    $('#run_attribution_tax_treatment').val('');
    $('#run_cycle_id').val('').trigger('change');
    $('#run_sync_process_id').val('');
    setRunFormExcludeId(null);
    $('#run_schedule_choice_cycle').prop('checked', true);
    $('#run_offcycle_row').removeClass('d-none');
    // 2026-09-09, same-day follow-up, explicit request: "ขอให้ checked default ครับ" -- 'payroll' is
    // the default again (was '' for one iteration -- see #run_purpose_choice_row's own comment in
    // modals.php).
    syncRunPurposeChoiceUi('payroll');
    $('#run_compute_statutory').prop('checked', true);
    $('#run_include_base_salary').prop('checked', false);
    $('#run_include_standing_items').prop('checked', false);
    $('#run_include_attendance_pay').prop('checked', false);
    // 2026-08-31, same-day follow-up (Origami `attribution` plan's item 3).
    $('#run_use_flat_tax_rate').prop('checked', false);
    $('#run_use_flat_tax_rate_row').addClass('d-none');
    // 2026-09-01: "เปิดรอบใหม่ / อ้างอิงถึงรอบ" radio -- see setMergeChoiceMode()'s own comment.
    // #run_merge_choice_row itself no longer needs its own d-none reset -- it lives inside
    // #run_offcycle_panel now, whose visibility setOffCycleMode(false) below already owns.
    $('#run_merge_choice_new').prop('checked', true);
    $('#run_merge_target_id').val('').trigger('change');
    // 2026-09-06: the new "existing round / future cycle period" sub-toggle -- see
    // setMergeTargetMode()'s own comment.
    $('#run_merge_target_mode_existing').prop('checked', true);
    $('#run_merge_target_cycle_id').val('').trigger('change');
    $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update');
    syncRunMergeIntoUi('standalone');
    setMergeChoiceMode('new');
    setMergeTargetMode('existing');
    setOffCycleMode(false);
    // 2026-09-11, Batch 3C item 4 sub-step 4b: a fresh Create/Pull never has any locked field (no
    // run exists yet to have admin work on it, or to have left draft) -- re-enables everything and
    // hides the Source row/lock summary left over from a previous Edit session on the same modal.
    clearRunFieldLockUi();
}
// 2026-09-11, Batch 3C item 4 sub-step 4b -- JS mirror of PayrollRunModel::runFieldLockState().
// MUST stay in lockstep with that method any time the rule changes there -- see this project's own
// standing rule against copy-drift (CLAUDE.md's "generalize instead of duplicate"); this one really
// can't be "generalized away" since PHP and JS can't share one function body, so keeping the two
// docblocks pointing at each other is the best available substitute. Client-side render only --
// checkRunFieldLocks()/applyFieldLocks() on the server is the actual enforcement; this never trusts
// itself as authoritative (a stale/tampered client could send anything regardless of what's disabled
// here, which is exactly why the server-side check exists independently).
function runFieldLockState(run) {
    const isDraft = (run.state || null) === 'draft';
    const hasAdminWork = !!run.has_admin_work;
    const lockedNotDraft = !isDraft;
    const lockedAdminWork = isDraft && hasAdminWork;
    const tier2Locked = lockedNotDraft || lockedAdminWork;
    const tier2Reason = lockedNotDraft ? 'not_draft' : (lockedAdminWork ? 'has_admin_work' : null);
    const tier3Locked = lockedNotDraft;
    const tier3Reason = lockedNotDraft ? 'not_draft' : null;
    return {
        source: { locked: true, reason: 'immutable' },
        cycle_id: { locked: tier2Locked, reason: tier2Reason },
        period_dates: { locked: tier2Locked, reason: tier2Reason },
        run_purpose: { locked: tier2Locked, reason: tier2Reason },
        merge_target: { locked: tier2Locked, reason: tier2Reason },
        run_name: { locked: tier3Locked, reason: tier3Reason },
        payment_date: { locked: tier3Locked, reason: tier3Reason },
        use_flat_tax_rate: { locked: tier3Locked, reason: tier3Reason },
        notes: { locked: false, reason: null },
    };
}
// Which actual form control(s) each lock group above disables -- `source` has no selector here
// (it's a plain read-only display, always disabled in markup already, never toggled).
const RUN_FIELD_LOCK_GROUP_SELECTORS = {
    // input[name="runScheduleChoice"] (the "Follow a schedule"/"Off-schedule" radio) is included
    // here too -- it's the toggle that changes cycle_id between a real cycle and null, so locking
    // cycle_id itself without also locking this radio would leave the radio clickable while the
    // select it drives sits disabled underneath, a confusing intermediate state for no benefit
    // (server-side checkRunFieldLocks() would revert any resulting change on save regardless).
    cycle_id: ['#run_cycle_id', 'input[name="runScheduleChoice"]'],
    period_dates: ['#run_period_start', '#run_period_end'],
    run_purpose: [
        'input[name="runPurposeChoice"]', '#run_compute_statutory', '#run_include_base_salary',
        '#run_include_standing_items', '#run_include_attendance_pay',
    ],
    merge_target: [
        'input[name="runMergeInto"]', '#run_merge_target_id', '#run_merge_target_cycle_id',
        '#run_merge_target_period_start', '#run_merge_target_period_end',
    ],
    run_name: ['#run_name'],
    payment_date: ['#run_payment_date'],
    use_flat_tax_rate: ['#run_use_flat_tax_rate'],
};
// select2-driven fields need their own widget refreshed after toggling the underlying <select>'s
// `disabled` prop -- Select2 doesn't repaint itself automatically on a plain jQuery .prop() call.
const RUN_FIELD_LOCK_SELECT2_SELECTORS = ['#run_cycle_id', '#run_merge_target_id', '#run_merge_target_cycle_id'];
function refreshRunFieldLockSelect2() {
    RUN_FIELD_LOCK_SELECT2_SELECTORS.forEach(function (sel) {
        if ($(sel).hasClass('select2-hidden-accessible')) {
            $(sel).trigger('change.select2');
        }
    });
}
// Disables every field runFieldLockState(run) says is locked, shows ONE combined summary explaining
// why (not a separate hint per field, per this sub-step's own design choice -- the admin_work_summary
// breakdown the server already computes is richer than repeating the same generic sentence under
// every disabled row), and shows/fills the read-only Source row (Edit-only -- `run.id` distinguishes
// editing an existing run from creating a new one, same signal updateComputeStatutoryVisibility()
// already uses).
function applyRunFieldLockUi(run) {
    const lockState = runFieldLockState(run);
    const reasonMessages = {
        immutable: langData['run_field_lock_reason_immutable'] || 'The source of this payroll run cannot be changed after it was created.',
        has_admin_work: langData['run_field_lock_reason_has_admin_work'] || 'This field cannot be changed because this run already has admin work on it. Undo that first.',
        not_draft: langData['run_field_lock_reason_not_draft'] || 'This field can no longer be changed once the run has left draft.',
    };
    const lockedMessages = [];
    Object.keys(RUN_FIELD_LOCK_GROUP_SELECTORS).forEach(function (group) {
        const info = lockState[group];
        RUN_FIELD_LOCK_GROUP_SELECTORS[group].forEach(function (sel) {
            $(sel).prop('disabled', !!info.locked);
        });
        if (info.locked) {
            const msg = reasonMessages[info.reason] || reasonMessages.not_draft;
            if (lockedMessages.indexOf(msg) === -1) lockedMessages.push(msg);
        }
    });
    refreshRunFieldLockSelect2();
    const $summary = $('#runLockSummary');
    if (lockedMessages.length > 0) {
        $summary.html(lockedMessages.map(function (m) { return `<div>${escapeHtml(m)}</div>`; }).join('')).removeClass('d-none');
    } else {
        $summary.addClass('d-none').empty();
    }
    const isEditing = !!run.id;
    $('#run_source_row').toggleClass('d-none', !isEditing);
    if (isEditing) {
        const sourceText = run.sync_process_id
            ? (langData['run_source_origami'] || 'Origami sync process #{id}').replace('{id}', run.sync_process_id)
            : (langData['run_source_manual'] || 'Created manually');
        $('#run_source_display').val(sourceText);
    }
}
// Re-enables everything + hides the Source row/lock summary -- called by resetRunForm() (a fresh
// Create/Pull never has anything locked) so a previous Edit session's disabled state/summary never
// leaks onto the next Create.
function clearRunFieldLockUi() {
    Object.keys(RUN_FIELD_LOCK_GROUP_SELECTORS).forEach(function (group) {
        RUN_FIELD_LOCK_GROUP_SELECTORS[group].forEach(function (sel) {
            $(sel).prop('disabled', false);
        });
    });
    refreshRunFieldLockSelect2();
    $('#runLockSummary').addClass('d-none').empty();
    $('#run_source_row').addClass('d-none');
}
// 2026-09-09, round-creation flow audit Phase 3 -- shared by resetRunForm(), setOffCycleMode(), and
// #run_purpose_choice_row's own click handler so every place that used to write directly to the old
// <select>'s .val() stays in sync with the new choice-card UI's checked/active state instead of just
// the hidden #run_purpose field alone. `value` may be '' (genuinely no card selected -- the hard-block
// state) or 'payroll'/'incentive'.
function syncRunPurposeChoiceUi(value) {
    $('#run_purpose').val(value).removeClass('is-invalid');
    $('#run_purpose_choice_error').addClass('d-none');
    $('#run_purpose_choice_row input[name="runPurposeChoice"]').prop('checked', false);
    $('#run_purpose_choice_row .run-choice-card').removeClass('active');
    if (value) {
        const $radio = $(`input[name="runPurposeChoice"][value="${value}"]`).prop('checked', true);
        $radio.closest('.run-choice-card').addClass('active');
    }
    // Always fires #run_purpose's own 'change' (updateComputeStatutoryVisibility()'s existing
    // binding) regardless of caller -- every one of the old direct `.val(...).trigger('change')`
    // call sites this function replaces relied on that same cascade running every time.
    $('#run_purpose').trigger('change');
}
$(document).on('change', 'input[name="runPurposeChoice"]', function () {
    syncRunPurposeChoiceUi($(this).val());
});
// 2026-09-09, round-creation flow audit Phase 3 -- same "new UI drives the old hidden radios"
// approach as syncRunPurposeChoiceUi() above, for the collapsed 3-way "fold into another round?"
// choice. setMergeChoiceMode()/setMergeTargetMode() (both unchanged) do all the actual show/hide/
// clear work once the legacy radios below are set -- this function's only job is picking WHICH of
// them to set and in what order (target sub-mode set silently first, so setMergeChoiceMode('reference')
// reads the already-correct sub-mode on its one and only cascade instead of reading a stale value and
// immediately re-correcting itself).
function syncRunMergeIntoUi(value) {
    $('#run_merge_into_row input[name="runMergeInto"]').prop('checked', false);
    $('#run_merge_into_row .run-subchoice-btn').removeClass('active');
    const $radio = $(`input[name="runMergeInto"][value="${value}"]`).prop('checked', true);
    $radio.closest('.run-subchoice-btn').addClass('active');
    if (value === 'standalone') {
        $('#run_merge_choice_new').prop('checked', true).trigger('change');
        return;
    }
    $(value === 'future_cycle' ? '#run_merge_target_mode_future_cycle' : '#run_merge_target_mode_existing').prop('checked', true);
    $('#run_merge_choice_reference').prop('checked', true).trigger('change');
}
$(document).on('change', 'input[name="runMergeInto"]', function () {
    syncRunMergeIntoUi($(this).val());
});
// 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึง
// รอบ" -- shows/requires the target picker only when "reference" is chosen. #run_merge_choice_row
// itself is hidden entirely for a Pull-sync create (see .btn-pull-sync's own handler in index.js),
// same gate #run_offcycle_row already uses -- this function is simply never called with anything but
// 'new' on that flow, so it's a no-op there either way.
function setMergeChoiceMode(choice) {
    const isReference = choice === 'reference';
    $('#run_merge_target_row').toggleClass('d-none', !isReference);
    if (!isReference) {
        $('#run_merge_target_id').val('').trigger('change').removeClass('is-invalid');
        $('#run_merge_target_cycle_id').val('').trigger('change').removeClass('is-invalid');
        $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update').removeClass('is-invalid');
    }
    // required class on whichever picker the CURRENT sub-mode actually shows -- see
    // setMergeTargetMode() below, called right after so it always reflects the current isReference.
    setMergeTargetMode($('input[name="runMergeTargetMode"]:checked').val() || 'existing');
    $('#run_merge_choice_row .run-subchoice-btn').removeClass('active');
    $(isReference ? '#run_merge_choice_reference' : '#run_merge_choice_new').closest('.run-subchoice-btn').addClass('active');
}
$(document).on('change', 'input[name="runMergeChoice"]', function () {
    setMergeChoiceMode($(this).val());
});
// 2026-09-06, explicit request: "ปรับ Process ที่มีการสร้างรอบเองในฝั่ง Payroll ให้เป็นไปในแนวทางเดียวกัน" --
// the "อ้างอิงถึงรอบ" (reference a round) choice's own 2nd-level sub-toggle: an existing round
// (unchanged #run_merge_target_id picker) vs. a FUTURE round of a recurring Payroll Cycle that
// hasn't been created yet (PayrollRunModel::resolveMergeTargetSpec()'s own docblock) -- only
// meaningful while #run_merge_target_row itself is showing (isReference true); a no-op call while
// it's hidden just leaves both wraps hidden, which is already the correct state either way.
function setMergeTargetMode(mode) {
    const isFutureCycle = mode === 'future_cycle';
    const targetRowShowing = !$('#run_merge_target_row').hasClass('d-none');
    $('#run_merge_target_existing_wrap').toggleClass('d-none', isFutureCycle);
    $('#run_merge_target_future_cycle_wrap').toggleClass('d-none', !isFutureCycle);
    $('#run_merge_target_id').toggleClass('required', targetRowShowing && !isFutureCycle);
    $('#run_merge_target_cycle_id').toggleClass('required', targetRowShowing && isFutureCycle);
    if (isFutureCycle) {
        $('#run_merge_target_id').val('').trigger('change').removeClass('is-invalid');
        // Covers reopening/reselecting this mode while a cycle/period were already picked earlier in
        // this same session -- a no-op (hides the box) if either field is still empty.
        refreshRunMergeTargetPreview();
    } else {
        $('#run_merge_target_cycle_id').val('').trigger('change').removeClass('is-invalid');
        $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update').removeClass('is-invalid');
        resetRunMergeTargetPreview();
    }
    $('#run_merge_target_mode_row .run-subchoice-btn').removeClass('active');
    $(isFutureCycle ? '#run_merge_target_mode_future_cycle' : '#run_merge_target_mode_existing').closest('.run-subchoice-btn').addClass('active');
}
$(document).on('change', 'input[name="runMergeTargetMode"]', function () {
    setMergeTargetMode($(this).val());
});
// Auto-suggests the target period the same way picking a cycle for the run's OWN period already
// does (applySuggestedPeriod()) -- reuses the exact same api/payroll-cycle.suggest-period endpoint,
// since "the next period of this cycle" is exactly the key a future round will be created with.
$(document).on('change', '#run_merge_target_cycle_id', function () {
    const cycleId = $(this).val();
    if (!cycleId) {
        $('#run_merge_target_period_start, #run_merge_target_period_end').val('').datepicker('update');
        resetRunMergeTargetPreview();
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-cycle.suggest-period`, method: 'GET', data: { id: cycleId }, dataType: 'json',
        success: function (res) {
            if (!res.status) { return; }
            $('#run_merge_target_period_start').val(formatDisplayDate(res.period_start_date)).datepicker('update').removeClass('is-invalid');
            $('#run_merge_target_period_end').val(formatDisplayDate(res.period_end_date)).datepicker('update').removeClass('is-invalid');
            refreshRunMergeTargetPreview();
        }
    });
});
// 2026-09-09, round-creation flow audit Bug 2 fix (explicit report: resolveMergeTargetSpec() picks
// silently among 2+ existing candidate runs -- same cycle, payment_date in the same month -- with no
// visible indication of which one, e.g. a semi-monthly cycle whose 15th AND 30th runs both already
// exist for the target month). This block adds a live, read-only preview of that same lookup so the
// admin sees (and, when ambiguous, explicitly picks) the actual target BEFORE clicking Save, instead
// of finding out afterward by opening the new run's own Detail page. `runMergeTargetPreviewMatches`/
// `runMergeTargetPreviewKey` cache the last fetch so re-triggering this on every keystroke isn't
// needed AND so the submit handler below can detect staleness (cycle/period changed since the last
// fetch) and re-check rather than trusting a possibly-outdated cached result.
let runMergeTargetPreviewMatches = null;
let runMergeTargetPreviewKey = null;
function resetRunMergeTargetPreview() {
    runMergeTargetPreviewMatches = null;
    runMergeTargetPreviewKey = null;
    $('#run_merge_target_preview_box').addClass('d-none');
    $('#run_merge_target_preview_none, #run_merge_target_preview_single, #run_merge_target_preview_multi').addClass('d-none');
    $('#run_merge_target_preview_select').empty().removeClass('is-invalid');
}
function runMergeTargetPreviewLabel(m) {
    const dateStr = typeof formatDisplayDate === 'function' ? formatDisplayDate(m.payment_date) : m.payment_date;
    return `${m.run_name} (${dateStr})`;
}
function renderRunMergeTargetPreview(matches) {
    $('#run_merge_target_preview_box').removeClass('d-none');
    $('#run_merge_target_preview_none, #run_merge_target_preview_single, #run_merge_target_preview_multi').addClass('d-none');
    if (matches.length === 0) {
        $('#run_merge_target_preview_none').removeClass('d-none').text(langData['run_merge_target_preview_none'] || 'No matching round yet -- this will wait until one is created.');
    } else if (matches.length === 1) {
        const tpl = langData['run_merge_target_preview_single'] || 'This will merge into: {name}';
        $('#run_merge_target_preview_single').removeClass('d-none').text(tpl.replace('{name}', runMergeTargetPreviewLabel(matches[0])));
    } else {
        const $sel = $('#run_merge_target_preview_select').empty().removeClass('is-invalid');
        $sel.append(new Option(langData['select_option'] || '-- Select --', ''));
        matches.forEach(m => $sel.append(new Option(runMergeTargetPreviewLabel(m), m.id)));
        $('#run_merge_target_preview_multi').removeClass('d-none');
    }
}
// Live preview only -- purely informational, never blocks anything itself (the submit-time
// re-check in resolveRunMergeTargetBeforeSubmit() below is the one that actually gates Save). A
// failed lookup here just leaves the box in whatever state it was already in; the submit-time
// re-check has its own independent error handling.
//
// 2026-09-11, Batch 3C item 4 sub-step 4a: `exclude_id` (Edit-only -- a run must never list itself
// as its own merge target) is now read generically off #run_merge_target_id's own data-exclude-id
// attribute (set/cleared by setRunFormExcludeId() on modal open) instead of a second, Edit-only copy
// of this whole function with one extra hardcoded key in its ajax `data`.
function refreshRunMergeTargetPreview() {
    const cycleId = $('#run_merge_target_cycle_id').val();
    const periodStart = toIsoDate($('#run_merge_target_period_start').val());
    if (!cycleId || !periodStart) {
        resetRunMergeTargetPreview();
        return;
    }
    const excludeId = $('#run_merge_target_id').attr('data-exclude-id') || null;
    const data = { cycle_id: cycleId, period_start_date: periodStart };
    if (excludeId) data.exclude_id = excludeId;
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.preview-merge-target`, method: 'GET', data: data, dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            runMergeTargetPreviewMatches = res.matches || [];
            runMergeTargetPreviewKey = cycleId + '|' + periodStart;
            renderRunMergeTargetPreview(runMergeTargetPreviewMatches);
        }
    });
}
$(document).on('changeDate', '#run_merge_target_period_start, #run_merge_target_period_end', function () {
    refreshRunMergeTargetPreview();
});
// Gate before the actual save AJAX call (called from the form's own submit handler below) --
// re-checks the SAME lookup fresh whenever the cached preview doesn't match the current cycle/period
// values (covers a stale cache from an earlier pick), then decides what collectRunFormData()'s own
// merge_target_* fields should actually become: 0 matches keeps the future_cycle spec as-is (still
// genuinely waiting), exactly 1 match resolves it to that run's id (same outcome the backend would
// reach silently on its own -- just made explicit here), 2+ matches REQUIRES the admin to have picked
// one via #run_merge_target_preview_select (blocks Save with a warning if not, rather than silently
// defaulting to the earliest period the old behavior did). `callback(ok, overrides)` -- `overrides`
// (when present) get merged onto collectRunFormData()'s own payload, replacing its
// merge_target_cycle_id/period fields with a resolved merge_target_run_id instead.
function resolveRunMergeTargetBeforeSubmit(callback) {
    const isReferenceMode = !$('#run_merge_target_row').hasClass('d-none');
    const targetMode = $('input[name="runMergeTargetMode"]:checked').val() || 'existing';
    if (!isReferenceMode || targetMode !== 'future_cycle') {
        callback(true);
        return;
    }
    const cycleId = $('#run_merge_target_cycle_id').val();
    const periodStart = toIsoDate($('#run_merge_target_period_start').val());
    if (!cycleId || !periodStart) {
        // Required-field validation (validateRunForm()) already catches this before this function
        // is ever reached -- guarded here too so this function is safe to call standalone.
        callback(true);
        return;
    }
    const key = cycleId + '|' + periodStart;
    function decide(matches) {
        if (matches.length === 0) {
            callback(true);
        } else if (matches.length === 1) {
            callback(true, {
                merge_target_run_id: matches[0].id,
                merge_target_cycle_id: null, merge_target_period_start_date: null, merge_target_period_end_date: null,
            });
        } else {
            const chosen = $('#run_merge_target_preview_select').val();
            if (!chosen) {
                $('#run_merge_target_preview_select').addClass('is-invalid');
                showWarning(langData['run_merge_target_preview_pick_required'] || 'More than one existing round matches -- please pick which one before saving.');
                callback(false);
                return;
            }
            callback(true, {
                merge_target_run_id: parseInt(chosen, 10),
                merge_target_cycle_id: null, merge_target_period_start_date: null, merge_target_period_end_date: null,
            });
        }
    }
    if (runMergeTargetPreviewKey === key && runMergeTargetPreviewMatches !== null) {
        decide(runMergeTargetPreviewMatches);
        return;
    }
    const excludeId = $('#run_merge_target_id').attr('data-exclude-id') || null;
    const data = { cycle_id: cycleId, period_start_date: periodStart };
    if (excludeId) data.exclude_id = excludeId;
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.preview-merge-target`, method: 'GET', data: data, dataType: 'json',
        success: function (res) {
            const matches = (res.status && res.matches) ? res.matches : [];
            runMergeTargetPreviewMatches = matches;
            runMergeTargetPreviewKey = key;
            renderRunMergeTargetPreview(matches);
            decide(matches);
        },
        // Network/preview failure -- fail OPEN, not closed: let the save proceed with the
        // future_cycle spec as-is. resolveMergeTargetSpec() itself still resolves this correctly
        // server-side (its own single-pick fallback, unchanged) -- this preview is a UX improvement
        // on top of that, not a replacement for it, so a lookup failure here must never block a
        // save that would otherwise succeed.
        error: function () { callback(true); }
    });
}
// Off-cycle runs (e.g. an out-of-cycle payment) skip the Payroll Cycle field entirely -- per
// explicit request. Only offered on the standalone "Add" flow; Pull-to-run/a supplemental sync
// process uses setSupplementalPullMode() instead (a genuinely different concept -- see that
// function's own docblock).
function setOffCycleMode(isOffCycle) {
    $('#run_cycle_row').toggleClass('d-none', isOffCycle);
    $('#run_cycle_id').toggleClass('required', !isOffCycle);
    if (isOffCycle) {
        $('#run_cycle_id').val('').trigger('change');
        $('#run_cycle_id').removeClass('is-invalid');
    }
    $('#run_offcycle_row .run-choice-card').removeClass('active');
    $(isOffCycle ? '#run_schedule_choice_offcycle' : '#run_schedule_choice_cycle').closest('.run-choice-card').addClass('active');
    // 2026-09-02, 2nd same-day follow-up: #run_offcycle_panel (merge choice + its target picker)
    // only ever makes sense together with off-schedule -- PayrollRunModel::create() itself refuses
    // a merge target the instant cycle_id is set, so this ALSO closes a real gap where the merge
    // choice used to stay reachable (and its selection submittable) even with a cycle picked,
    // guaranteeing a backend rejection on save.
    $('#run_offcycle_panel').toggleClass('d-none', !isOffCycle);
    if (!isOffCycle) {
        // syncRunMergeIntoUi() itself fires runMergeChoice's own change -> setMergeChoiceMode('new'),
        // so no separate explicit call is needed here anymore.
        syncRunMergeIntoUi('standalone');
    }
    // Period Start/End are only required for a cycle-based run -- an off-cycle run (e.g. a
    // special bonus payout) doesn't always have a meaningful attendance period, per explicit
    // request. Payment Date stays required either way -- toggled independently, never touched
    // here. #run_period_required_mark is the red "*" next to the Period Start/End label only
    // (Payment Date has its own separate, always-shown "*").
    $('#run_period_start, #run_period_end').toggleClass('required', !isOffCycle);
    $('#run_period_required_mark').toggleClass('d-none', isOffCycle);
    if (isOffCycle) {
        $('#run_period_start, #run_period_end').removeClass('is-invalid');
    }
    // Run Purpose (Payroll / Incentive-Other Payment) only makes sense for a genuine off-cycle
    // run, per explicit request (2026-08-19) -- PayrollRunModel::create()/update() reject
    // run_purpose='incentive' outright whenever a cycle is selected, so hiding it here just keeps
    // the form from offering a choice the backend would reject anyway.
    $('#run_purpose_choice_row').toggleClass('d-none', !isOffCycle);
    // .required only while genuinely shown -- same pattern #run_merge_target_id/_cycle_id already
    // use (see setMergeTargetMode()) -- validateRunForm()'s generic loop would otherwise block Save
    // over a hidden, irrelevant field.
    $('#run_purpose').toggleClass('required', isOffCycle);
    if (!isOffCycle) {
        // 2026-09-09, same-day follow-up, explicit request: "ขอให้ checked default ครับ" -- reset back
        // to the 'payroll' default (was '' for one iteration), same as resetRunForm()'s own comment.
        syncRunPurposeChoiceUi('payroll');
    }
}
$(document).on('change', 'input[name="runScheduleChoice"]', function () {
    setOffCycleMode($(this).val() === 'offcycle');
});
// 2026-08-29, explicit request referencing PAYROLL_SYNC_API.md's own run_kind field
// ("regular"/"supplemental", 2026-08-28 revision there): a supplemental sync process (a
// standalone/ad-hoc Origami cycle -- e.g. OT-only or Trip-only) is NOT tied to a period
// auto-match the way a regular sync-matched pull is, so run_purpose becomes choosable (same
// Payroll/Incentive-Other-Payment choice a genuine off-cycle run already offers) and the payroll
// cycle becomes optional rather than required. Deliberately does NOT touch #run_cycle_row's own
// visibility or the runScheduleChoice radio's checked state -- a supplemental sync pull is still
// sync-linked (sync_process_id set), never truly "off-cycle" the way the standalone Add flow's
// toggle means it; the cycle field just stops being mandatory.
// taxTreatment ('merge'/'separate'/'') -- only a 'separate'-attributed supplemental process ever
// shows the flat-tax-rate opt-in row at all (see #run_use_flat_tax_rate_row's own comment in
// modals.php). Pre-checked (not just shown) when Origami explicitly said "separate".
// `taxTreatment` is the sync process's own real attributed value ('merge'/'separate'/'') -- the
// caller (.btn-pull-sync's own handler) must set #run_attribution_tax_treatment to this SAME value
// BEFORE calling this function, since syncRunPurposeChoiceUi('incentive') below fires #run_purpose's
// own 'change' -> updateComputeStatutoryVisibility() (app.js), which reads that hidden field to
// compute visibility (see that function's own docblock, Decision 1). This function's own remaining
// job on top of that shared visibility computation is the ONE thing it doesn't do on its own: a
// one-time pre-check of use_flat_tax_rate specifically when Origami said 'separate' -- pre-checked
// (not just shown) per the same "use what Origami already sent instead of re-entering by hand"
// precedent #run_period_start/etc. already established. updateComputeStatutoryVisibility() itself
// deliberately never auto-CHECKS this box (only ever un-checks+hides), so a later manual uncheck
// during this same session is never silently re-ticked just because run_purpose gets toggled again.
function setSupplementalPullMode(isSupplemental, taxTreatment) {
    $('#run_cycle_id').toggleClass('required', !isSupplemental);
    $('#run_purpose_choice_row').toggleClass('d-none', !isSupplemental);
    $('#run_purpose').toggleClass('required', isSupplemental);
    if (!isSupplemental) {
        syncRunPurposeChoiceUi('payroll');
    } else {
        // A supplemental run is *by definition* never "regular payroll" -- pre-select 'incentive'
        // here, still fully editable afterward same as every other Pull-derived field on this form.
        syncRunPurposeChoiceUi('incentive');
    }
    if (isSupplemental && taxTreatment === 'separate') {
        $('#run_use_flat_tax_rate').prop('checked', true);
    }
}
// Compute Statutory/Include Base Salary/Include Standing Items only matter (and only show) once
// Incentive/Other Payment is actually selected -- a normal Payroll run always includes all three,
// no choice to offer.
//
// 2026-09-11, Batch 3C item 4c (Decision 1), explicit instruction: "โชว์เมื่อ run เป็น Incentive/
// partial payment และ tax_treatment = 'separate' ไม่สนที่มา...ทั้ง Create และ Edit ใช้กฎเดียวกัน" --
// ONE rule now, replacing the create-vs-edit branch this function used to need (that branch existed
// only because the two forms' PRE-EXISTING rules genuinely disagreed -- see sub-step 4a's own
// docblock here, now removed since there's nothing left to disagree about). Mirrors
// PayrollRunModel::useFlatTaxRateAllowed() exactly -- keep the two in lockstep; see that method's own
// docblock for why the effective treatment defaults to 'separate' when there's no concrete Origami
// attribution value at all (a manual run, or a plain unattributed sync pull). Never trusts itself as
// authoritative -- create()/update() reject the value server-side regardless of what this shows/hides.
function updateComputeStatutoryVisibility() {
    const isIncentive = $('#run_purpose').val() === 'incentive';
    $('#run_compute_statutory_row, #run_include_base_salary_row, #run_include_standing_items_row, #run_include_attendance_pay_row').toggleClass('d-none', !isIncentive);
    const attributionTaxTreatment = $('#run_attribution_tax_treatment').val() || '';
    const effectiveTaxTreatment = attributionTaxTreatment || 'separate';
    const showFlatTax = isIncentive && effectiveTaxTreatment !== 'merge';
    $('#run_use_flat_tax_rate_row').toggleClass('d-none', !showFlatTax);
    // "ถ้าเปลี่ยน tax_treatment ไปเป็นรวมคำนวณ ให้ซ่อนและ reset use_flat_tax_rate = 0" -- hiding always
    // resets to unchecked; this function never auto-CHECKS it on its own (only ever un-checks), so a
    // later manual uncheck during the same session is never silently re-ticked just because this ran
    // again (e.g. toggling run_purpose back and forth) -- pre-checking on first reveal is
    // setSupplementalPullMode()'s own one-time job instead, see that function's own comment.
    if (!showFlatTax) {
        $('#run_use_flat_tax_rate').prop('checked', false);
    }
}
$(document).on('change', '#run_purpose', updateComputeStatutoryVisibility);
// Auto-fills Period Start/End/Payment Date from the selected cycle's own configured cutoff/
// payment day settings, per explicit request -- pure convenience default, every field stays
// editable afterward. Silently does nothing on failure (cycle not fully configured, network
// error, etc.) so manual entry always still works as a fallback. Only fills a field that's
// genuinely EMPTY; a field that already has a real value (typed by hand, or already saved on an
// existing run being edited) is left completely untouched.
function applySuggestedPeriod(cycleId) {
    if (!cycleId) {
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/payroll-cycle.suggest-period`,
        method: 'GET',
        data: { id: cycleId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                return;
            }
            // .datepicker('update') after each programmatic .val() -- see CLAUDE.md's
            // bootstrap-datepicker note (widget state goes stale otherwise, blanking the field on
            // next click-away).
            if (!$('#run_period_start').val()) {
                $('#run_period_start').val(formatDisplayDate(res.period_start_date)).datepicker('update');
            }
            if (!$('#run_period_end').val()) {
                $('#run_period_end').val(formatDisplayDate(res.period_end_date)).datepicker('update');
            }
            if (!$('#run_payment_date').val()) {
                $('#run_payment_date').val(formatDisplayDate(res.payment_date)).datepicker('update');
            }
            $('#run_period_start, #run_period_end, #run_payment_date').removeClass('is-invalid');
        }
    });
}
$(document).on('change', '#run_cycle_id', function () {
    applySuggestedPeriod($(this).val());
});
function validateRunForm() {
    let firstInvalid = null;
    $('#payrollRunModal .required').each(function () {
        const $el = $(this);
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    // 2026-09-09, round-creation flow audit Phase 3: #run_purpose is a hidden input now (driven by
    // #run_purpose_choice_row's cards) -- .is-invalid on a hidden element has no visible red border
    // of its own, so mirror it onto a small text hint next to the cards instead.
    $('#run_purpose_choice_error').toggleClass('d-none', !$('#run_purpose').hasClass('is-invalid'));
    return firstInvalid;
}
function collectRunFormData() {
    const isOffCycle = $('input[name="runScheduleChoice"]:checked').val() === 'offcycle';
    const isReferenceMode = !$('#run_merge_target_row').hasClass('d-none');
    const targetMode = $('input[name="runMergeTargetMode"]:checked').val() || 'existing';
    // run_purpose used to be forced to 'payroll' whenever the manual off-cycle radio wasn't picked
    // -- but setSupplementalPullMode() also shows #run_purpose_choice_row for a supplemental sync
    // pull/edit, which never touches that radio at all. Read the hidden field whenever its row is
    // actually visible, not just for the off-cycle path.
    const purposeSelectable = isOffCycle || !$('#run_purpose_choice_row').hasClass('d-none');
    const runPurpose = purposeSelectable ? ($('#run_purpose').val() || 'payroll') : 'payroll';
    return {
        // #run_id is empty on a brand-new run (create()) or a real id on an existing one (update())
        // -- PayrollController::save() branches on this single key.
        id: $('#run_id').val() || null,
        // #run_cycle_row itself is only ever hidden for the manual off-cycle path -- a supplemental
        // sync pull/edit keeps it visible (cycle becomes optional there, not gone), so reading its
        // value directly (rather than forcing null off isOffCycle alone) is correct for every mode.
        cycle_id: $('#run_cycle_row').hasClass('d-none') ? null : ($('#run_cycle_id').val() || null),
        run_purpose: runPurpose,
        compute_statutory: runPurpose === 'incentive' && $('#run_compute_statutory').is(':checked') ? 1 : 0,
        include_base_salary: runPurpose === 'incentive' && $('#run_include_base_salary').is(':checked') ? 1 : 0,
        include_standing_items: runPurpose === 'incentive' && $('#run_include_standing_items').is(':checked') ? 1 : 0,
        include_attendance_pay: runPurpose === 'incentive' && $('#run_include_attendance_pay').is(':checked') ? 1 : 0,
        use_flat_tax_rate: runPurpose === 'incentive' && $('#run_use_flat_tax_rate').is(':checked') ? 1 : 0,
        run_name: $('#run_name').val().trim(),
        period_start_date: toIsoDate($('#run_period_start').val()),
        period_end_date: toIsoDate($('#run_period_end').val()),
        payment_date: toIsoDate($('#run_payment_date').val()),
        notes: $('#run_notes').val().trim(),
        sync_process_id: $('#run_sync_process_id').val() || null,
        // Both merge_target_run_id AND merge_target_cycle_id are ALWAYS sent together (one truthy,
        // the other explicitly null depending on runMergeTargetMode) -- PayrollRunModel::
        // resolveMergeTargetSpec() resolves each key independently and refuses if both end up
        // non-null, so sending only one while silently omitting the other would be ambiguous.
        merge_target_run_id: (isReferenceMode && targetMode !== 'future_cycle') ? ($('#run_merge_target_id').val() || null) : null,
        merge_target_cycle_id: (isReferenceMode && targetMode === 'future_cycle') ? ($('#run_merge_target_cycle_id').val() || null) : null,
        merge_target_period_start_date: (isReferenceMode && targetMode === 'future_cycle') ? toIsoDate($('#run_merge_target_period_start').val()) : null,
        merge_target_period_end_date: (isReferenceMode && targetMode === 'future_cycle') ? toIsoDate($('#run_merge_target_period_end').val()) : null,
    };
}
$(document).on('submit', '#payrollRunForm', function (e) {
    e.preventDefault();
    const invalidEl = validateRunForm();
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    // 2026-09-09, round-creation flow audit Bug 2 fix -- re-checks the future_cycle auto-match
    // immediately before saving (see resolveRunMergeTargetBeforeSubmit()'s own docblock), possibly
    // overriding collectRunFormData()'s own merge_target_* fields with a resolved merge_target_run_id.
    // Blocks entirely (no ajax call at all) if 2+ candidates matched and the admin hasn't picked one.
    resolveRunMergeTargetBeforeSubmit(function (ok, overrides) {
        if (!ok) return;
        submitRunForm(overrides);
    });
});
// On success, hides the modal and fires 'payrollRun:saved' on document with the backend's own
// response -- each PAGE that actually has this form reachable (payroll/index.js's Process List,
// payroll/detail.js's Process Detail) registers its OWN listener for that event to do its own
// page-specific follow-up (reload a DataTable vs. reload the Detail page's own data) -- this
// function itself has no idea which page it's running on, and doesn't need to.
function submitRunForm(mergeTargetOverrides) {
    const payload = Object.assign(collectRunFormData(), mergeTargetOverrides || {});
    const $btn = $('#payrollRunForm button[type="submit"]');
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                bootstrap.Modal.getInstance(document.getElementById('payrollRunModal')).hide();
                // 2026-09-11, Batch 3C item 4 sub-step 4b, explicit instruction: PayrollRunModel::
                // applyFieldLocks() SKIPS a locked field's attempted change rather than rejecting the
                // whole save (see that method's own docblock) -- report it here so the admin knows
                // WHICH of their changes, if any, didn't apply and why, since the shared form always
                // sends every field regardless of lock state. Shown BEFORE each page's own
                // 'payrollRun:saved' listener runs its own success toast -- two separate alerts back
                // to back, not a replacement for that toast.
                if ((res.skipped_fields || []).length > 0) {
                    // Prefers the i18n-translated reason (run_field_lock_reason_<reason>, the same
                    // keys applyRunFieldLockUi()'s own lock summary already uses) over the server's
                    // own hardcoded English message, which stays as a fallback for any reason code
                    // without a translated key yet.
                    const items = res.skipped_fields.map(function (f) {
                        const i18nMsg = f.reason ? langData['run_field_lock_reason_' + f.reason] : null;
                        return `<li>${escapeHtml(i18nMsg || f.message || f.field)}</li>`;
                    }).join('');
                    Swal.fire({
                        icon: 'warning',
                        title: langData['run_save_partial_title'] || 'Saved, but some fields were not changed',
                        html: `<ul class="text-start small mb-0">${items}</ul>`,
                    });
                }
                $(document).trigger('payrollRun:saved', [res]);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
}
// #payrollRunModal's own select2/datepicker widget init -- moved here from payroll/index.js's own
// ready() block (which used to be the only page that initialized them) now that the modal is shared
// with Process Detail's own Edit flow too (previously duplicated as a SECOND init of the Edit-only
// #edit_run_cycle_id/#edit_run_merge_target_id/etc. in payroll/detail.js, with allowClear:true only
// on that copy) -- one shared init, on every page, covers both flows; allowClear:true now applies
// to both (Create never NEEDED it since it always hides rather than clears these fields, but
// having it doesn't change Create's own behavior either).
$(document).ready(function () {
    if (typeof initSelect2 === 'function') {
        initSelect2('#run_cycle_id', { mode: 'ajax', allowClear: true });
        initSelect2('#run_merge_target_id', { mode: 'ajax', allowClear: true });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#run_period_start');
        initDatepicker('#run_period_end');
        initDatepicker('#run_payment_date');
        initDatepicker('#run_merge_target_period_start');
        initDatepicker('#run_merge_target_period_end');
    }
});