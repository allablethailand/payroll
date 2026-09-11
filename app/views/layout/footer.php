<script src="<?=BASE_URL?>/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/sweetalert2/dist/sweetalert2.all.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/datatables.net/js/dataTables.js"></script>
<script src="<?=BASE_URL?>/node_modules/datatables.net-bs5/js/dataTables.bootstrap5.js"></script>
<!-- 2026-08-27, explicit request: "ตารางทุกตารางต้องรองรับ responsive ทั้งหมด" -- ~24 DataTable init
     calls across the app already pass `responsive: true`, but this extension was never actually
     installed/loaded, so that option was silently a no-op everywhere it was set. Adding it here
     (loaded once, globally, same as core DataTables/bs5 above) makes every one of those existing
     `responsive: true` options actually work, with zero changes needed in any of those JS files. -->
<script src="<?=BASE_URL?>/node_modules/datatables.net-responsive/js/dataTables.responsive.js"></script>
<script src="<?=BASE_URL?>/node_modules/datatables.net-responsive-bs5/js/responsive.bootstrap5.js"></script>
<!-- 2026-08-31, real bug found and fixed (explicit report: "dataTables.fixedColumns.js:512 Uncaught
     TypeError: Cannot read properties of undefined (reading 's')" firing on EVERY page, including
     ones with no fixedColumns table at all, e.g. /payroll/setup-rules) -- root cause confirmed by
     reading the installed library source directly, not guessed: `datatables.net-fixedcolumns@6.0.0`
     reads `var Dom = DataTable.Dom;` at load time, but the installed `datatables.net@2.3.8` classic
     jQuery UMD build (node_modules/datatables.net/js/dataTables.js) never defines a `DataTable.Dom`
     namespace at all (confirmed via direct grep of that file) -- only a newer/different DataTables
     build exposes it. `Dom` is therefore `undefined`, and fixedColumns.js's own top-level
     `Dom.s(document).on('plugin-init.dt', ...)` call (line 512) throws immediately the INSTANT this
     script loads, on every single page, before any DataTable on that page is even created. This was
     a genuine, real, pre-existing incompatibility between these two npm package versions -- NOT
     something introduced by this app's own code -- confirmed it has silently never worked even on
     the one page that needs it (Annual Income Summary's frozen employee/total columns,
     public/js/reports/annual-summary.js's own `fixedColumns: {...}` option) since it was added
     2026-08-29, just without anyone noticing because the console error alone doesn't break anything
     ELSE on the page (DataTables core still renders fine, just without the frozen-column effect).
     A real fix needs `datatables.net` upgraded to a major version that actually exposes `.Dom`
     (v3.x is available on npm, confirmed) -- too large/risky a change to make here since dozens of
     OTHER DataTables across this entire app would need re-testing against that major version bump,
     well outside the scope of "stop this specific error." Scoped down instead: these 2 scripts moved
     OUT of this global footer (loaded on every page) into the Annual Income Summary view's own
     `<script>` block (app/views/reports/annual-summary.php, right before its own annual-summary.js
     tag) -- every OTHER page stops loading/executing this broken script entirely, and Annual Income
     Summary's own frozen-column feature is left exactly as functional (i.e. still not functional) as
     it already was -- not a new regression, just no longer polluting every unrelated page's console. -->
<script src="<?=BASE_URL?>/node_modules/select2/dist/js/select2.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/locales/bootstrap-datepicker.th.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/sortablejs/Sortable.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/intl-tel-input/dist/js/intlTelInputWithUtils.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/leaflet/dist/leaflet.js"></script>
<!-- 2026-09-07, Announcement CMS rich-text formatting -- Quill's own UMD build (defines window.Quill). -->
<script src="<?=BASE_URL?>/node_modules/quill/dist/quill.js"></script>
<?php
// 2026-08-30, explicit request: "ย้ายทุก modal ไปไว้ที่เดียวกัน" -- every modal's own HTML markup in
// this app now lives in this one shared partial, included unconditionally here (after every page's
// own content has already rendered -- Controller::view() includes header.php, then the page's
// content view, then this file -- so DOM order puts every modal right before </body>; id-based
// lookups/`data-bs-target` work identically regardless of DOM position). See modals.php's own
// docblock for the full investigation/rationale.
include __DIR__ . '/modals.php';
?>
<!-- 2026-09-05, Backlog Phase 13 -- Help Drawer: a persistent trigger button (bottom-right, every
     page) + a slide-in right-side panel, NOT a Bootstrap modal (deliberately -- a drawer stays
     alongside the page content rather than blocking it, so an admin can keep the settings page
     visible while reading the help text next to it). See public/js/setup/help-drawer.js's own
     docblock for how page_key is determined and why an unwritten page shows a placeholder instead
     of hiding the button entirely (a company should always be able to tell help EXISTS as a
     concept, even on a page nobody has written content for yet). -->
<button type="button" id="helpDrawerToggleBtn" class="help-drawer-toggle-btn" aria-label="Help">
    <i class="fa-solid fa-circle-question"></i>
</button>
<div id="helpDrawerPanel" class="help-drawer-panel" aria-hidden="true">
    <div class="help-drawer-panel-header">
        <span class="fw-semibold" data-i18n="help_drawer_title">Help</span>
        <button type="button" id="helpDrawerCloseBtn" class="btn-close" aria-label="Close"></button>
    </div>
    <div class="help-drawer-panel-body" id="helpDrawerPanelBody"></div>
</div>
<script src="<?=asset('public/js/setup/terms-and-conditions.js')?>"></script>
<script src="<?=asset('public/js/setup/help-drawer.js')?>"></script>
<!-- 2026-09-07, real bug found and fixed (explicit report: "ประวัติการเข้าใช้งานระบบ จาก Profile ไม่มี
     ข้อมูลในตาราง") -- this file existed and was fully correct (confirmed the backend endpoint,
     model query, and controller all return real data end-to-end via direct PHP-CLI calls) but was
     never actually included on any page, so its `show.bs.modal` handler for #systemAccessHistoryModal
     (opened from the header Profile dropdown, present on every page) never registered -- the modal
     just opened empty every time, silently, since it was written 2026-09-05 (Backlog Phase 13). -->
<script src="<?=asset('public/js/setup/system-access-history.js')?>"></script>
</body>
</html>