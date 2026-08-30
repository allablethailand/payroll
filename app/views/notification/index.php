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
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="notifications">Notifications</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon">
            <i class="fa-solid fa-bell"></i>
        </div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="notifications">Notifications</h5>
            <p class="page-header-card-desc small" data-i18n="notif_page_description">All your notifications in one place -- click any item to jump straight to it.</p>
        </div>
    </div>

    <div class="station-filter" id="notifStationFilter">
        <span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="notifStationFilterToggle" title="Toggle filter">
            <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small"><span data-i18n="filter_date_from">From</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="notif_filter_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small"><span data-i18n="filter_date_to">To</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="notif_filter_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <!-- 2026-08-29, same-day follow-up: system-wide table audit punch-list item -- the
                     Status column (Read/Unread) had no filter dimension at all. -->
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small" data-i18n="status">Status</label>
                    <select class="form-select select2-static" id="notif_filter_is_read" data-option-keys="notif_status_read,notif_status_unread" data-option-values="1,0"></select>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearNotifFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
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
    if (typeof notifPageTableInit === 'function') notifPageTableInit();
});
</script>
