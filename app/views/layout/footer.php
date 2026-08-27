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
<script src="<?=BASE_URL?>/node_modules/select2/dist/js/select2.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/bootstrap-datepicker/dist/locales/bootstrap-datepicker.th.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/sortablejs/Sortable.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/intl-tel-input/dist/js/intlTelInputWithUtils.min.js"></script>
<script src="<?=BASE_URL?>/node_modules/leaflet/dist/leaflet.js"></script>
<div class="modal fade" id="systemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"></div>
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    </div>
</div>
</body>
</html>