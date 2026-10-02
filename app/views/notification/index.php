<!-- 2026-08-29, same-day follow-up: "หน้า /payroll/notifications ให้แสดงเป็นตาราง Datatable และมี Filter
     วันที่ด้วย" -- was a card/infinite-scroll list (notifPageInit()/notifPageLoadNext() in
     notifications.js); rebuilt as a real server-side DataTable per this project's own Table
     convention (a per-employee notification history can grow unbounded over months, same
     "serverSide:true for lists with no natural size cap" reasoning as Employee List) with a Date
     filter mirroring Employee List's own collapsible .station-filter. The header bell dropdown and
     Dashboard's notification widget are UNCHANGED -- they still use the small offset/limit
     `api/notification.list` endpoint (notifOpenDropdown()/loadDashboardNotifications()); this page
     alone now calls the new `api/notification.datatable` endpoint. -->
<div class="container container-body">
    <?php
    $title = 'Notifications';
    $title_i18n = 'notifications';
    $breadcrumb = [
        ['label' => 'Payroll', 'href' => BASE_URL . '/dashboard', 'i18n' => 'payroll'],
    ];
    $description = 'All your notifications in one place -- click any item to jump straight to it.';
    $description_i18n = 'notif_page_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <?php
    ob_start(); ?>
    <div class="row g-2">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="notif_filter_date_from" data-i18n="filter_date_from">From</label>
            <input type="text" class="form-control datepicker" id="notif_filter_date_from" autocomplete="off">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="notif_filter_date_to" data-i18n="filter_date_to">To</label>
            <input type="text" class="form-control datepicker" id="notif_filter_date_to" autocomplete="off">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="notif_filter_is_read" data-i18n="status">Status</label>
            <select class="form-select select2-static" id="notif_filter_is_read" data-option-keys="filter_all,notif_status_read,notif_status_unread" data-option-values="all,1,0"></select>
        </div>
    </div>
    <?php
    $filter_fields_html = ob_get_clean();
    $id = 'notifFilterBar';
    include __DIR__ . '/../partials/filter-bar.php';
    ?>
    <div class="d-flex justify-content-end mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="notifPageMarkAllReadBtn">
            <i class="fa-solid fa-check-double me-1"></i><span data-i18n="notif_mark_all_read">Mark all as read</span>
        </button>
    </div>

    <table class="table table-hover align-middle w-100" id="tb_notification">
        <thead class="table-light text-secondary">
            <tr>
                <th data-i18n="type">Type</th>
                <th data-i18n="notif_column_message">Notification</th>
                <th data-i18n="date">Date</th>
                <th data-i18n="status">Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
<script>
$(document).ready(function () {
    // 2026-09-25, dtlang round B -- was calling notifPageTableInit() directly, unlike every other
    // standalone-page DataTable in the app (e.g. public/js/employee/login-history.js's own
    // `(window.langReady || Promise.resolve()).then(...)` wrap). That let #tb_notification construct
    // before loadLang()'s own fetch resolved, so its `language: getTableLang()` option (notifications.js)
    // could capture the English fallback strings instead of the real ones -- see
    // docs/decisions/2026-09-25-dtlang-visible-double-fetch.md for why this now matters: this page's
    // table is visible from first paint (no tab hides it), so app.js's own language-switch guard no
    // longer re-draws it as a safety net once #tb_notification is already constructed and visible.
    (window.langReady || Promise.resolve()).then(function () {
        if (typeof notifPageTableInit === 'function') notifPageTableInit();
    });
});
</script>
