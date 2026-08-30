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
<!-- 2026-08-29, added for the Annual Income Summary page's frozen employee/total columns
     (explicit request: "Column ที่เป็นพนักงาน Fixed อยู่กับที่...ขวาสุดที่เป็นสรุป ให้ fiexd ขวาสุด") --
     loaded globally alongside the other DataTables extensions above, same convention this project
     already follows (e.g. Responsive), even though only this one page uses it so far. -->
<script src="<?=BASE_URL?>/node_modules/datatables.net-fixedcolumns/js/dataTables.fixedColumns.js"></script>
<script src="<?=BASE_URL?>/node_modules/datatables.net-fixedcolumns-bs5/js/fixedColumns.bootstrap5.js"></script>
<script src="<?=BASE_URL?>/node_modules/select2/dist/js/select2.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/locales/bootstrap-datepicker.th.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/sortablejs/Sortable.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/intl-tel-input/dist/js/intlTelInputWithUtils.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/leaflet/dist/leaflet.js"></script>
<?php
// 2026-08-30, explicit request: "ย้ายทุก modal ไปไว้ที่เดียวกัน" -- every modal's own HTML markup in
// this app now lives in this one shared partial, included unconditionally here (after every page's
// own content has already rendered -- Controller::view() includes header.php, then the page's
// content view, then this file -- so DOM order puts every modal right before </body>; id-based
// lookups/`data-bs-target` work identically regardless of DOM position). See modals.php's own
// docblock for the full investigation/rationale.
include __DIR__ . '/modals.php';
?>
</body>
</html>