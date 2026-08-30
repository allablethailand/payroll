/* ==================== Header notification bell (2026-08-29) ====================
   Explicit request: see NotificationModel's own top-of-file docblock in app/models/ for the full
   notification-type analysis. This file drives BOTH the header bell dropdown (loaded on every page,
   see layout/header.php) and, via the same fetch/render functions, the dedicated "view all" page
   (app/views/notification/index.php includes this same file and calls notifPageInit()).

   "โดยเป็นของใครของมัน คนนึงเห็นแล้วอีกคนไม่เห็นตัวเลขก็จะไม่หาย ต้องไปกดเปิดดูก่อนถึงหาย" -- every
   endpoint here is scoped server-side to the CURRENT session's own employee_id (see
   NotificationController's own docblock), so this file never has to think about "whose" state it's
   showing -- there is only ever one answer, the logged-in user. Opening the dropdown alone never
   marks anything read (see notifRenderList() below); only notifOpenItem() (an actual click on one
   item) does, matching "ต้องไปกดเปิดดูก่อนถึงหาย" literally.
   ==================== */
let notifDropdownOffset = 0;
let notifDropdownHasMore = true;
let notifDropdownLoading = false;
let notifDropdownOpenedOnce = false;
const NOTIF_DROPDOWN_PAGE_SIZE = 10;

function notifEscapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function notifTimeAgo(isoVal) {
    if (!isoVal) return '';
    // Same UTC-stored-timestamp -> local-time conversion every other "ago"/date display in this app
    // already relies on (see payroll/detail.js's own toLocalDateOnlyRd() for the same reasoning) --
    // reuses formatDisplayDateTime()'s own parsing (app.js) rather than re-implementing it, then
    // derives a relative "x minutes/hours/days ago" label from the resulting real Date.
    let isoUtc = String(isoVal).replace(' ', 'T');
    if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(isoUtc)) isoUtc += 'Z';
    const d = new Date(isoUtc);
    if (isNaN(d.getTime())) return String(isoVal);
    const diffMs = Date.now() - d.getTime();
    const mins = Math.floor(diffMs / 60000);
    if (mins < 1) return langData['notif_just_now'] || 'Just now';
    if (mins < 60) return `${mins}${langData['notif_minutes_ago'] || 'm ago'}`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}${langData['notif_hours_ago'] || 'h ago'}`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}${langData['notif_days_ago'] || 'd ago'}`;
    return typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(isoVal).split(' ')[0] : String(isoVal).substring(0, 10);
}
// 2026-08-29, same-day follow-up: "Notification แยก Icon และสีแต่ละการแจ้งเตือน" -- every notification
// row already carries its own `icon` (fa-* class, set per-call in NotificationModel::create()'s
// callers), but every type rendered in the SAME flat gray, so nothing visually distinguished a
// stale-draft nudge from a document-approval request at a glance. One color+icon per `type` (the 5
// real types this app actually creates -- see NotificationModel's own top-of-file docblock analysis
// -- plus a neutral fallback for any future type that doesn't opt into a color yet).
//
// 2026-08-30, explicit request ("ตรง icon ปรับให้เหมือนในหน้า Report ครับ...ทุกตารางที่มี icon ให้เป็น
// รูปแบบเดียวกับ report ทั้งหมดครับ") -- converted from this file's own one-off `.nav-notif-item-icon`
// (a circle, inline-style gradient per type) to the shared `.row-type-icon rt-N` component
// (style.css) every other table's row-category icon already uses. Confirmed via AskUserQuestion:
// applied to BOTH the notification-bell dropdown (notifItemHtml()) AND the /payroll/notifications
// DataTable together, not just the table -- they were deliberately kept visually identical to each
// other (see notifPageTableInit()'s own 2026-08-29 comment), and converting only one would have
// broken that. `rt` picked per type to stay as close as reasonably possible to each type's OLD
// bespoke gradient hue (sync_new_data sky-blue->rt-5, stale_draft amber->rt-1 orange, approved_
// continue green->rt-2, lock_reminder_print purple->rt-3 indigo, document_request_pending pink->rt-4
// red -- the one imperfect match, .row-type-icon has no pink variant) -- uses all 5 existing
// variants, one each, no new variant needed.
const NOTIF_TYPE_META = {
    sync_new_data: { rt: 'rt-5', icon: 'fa-arrows-rotate' },
    stale_draft: { rt: 'rt-1', icon: 'fa-hourglass-half' },
    approved_continue: { rt: 'rt-2', icon: 'fa-check' },
    lock_reminder_print: { rt: 'rt-3', icon: 'fa-lock' },
    document_request_pending: { rt: 'rt-4', icon: 'fa-file-signature' },
};
function notifTypeMeta(item) {
    return NOTIF_TYPE_META[item.type] || { rt: 'rt-3', icon: item.icon || 'fa-bell' };
}
function notifItemHtml(item) {
    const title = currentLang === 'th' ? (item.title_th || item.title_en) : (item.title_en || item.title_th);
    const message = currentLang === 'th' ? (item.message_th || item.message_en) : (item.message_en || item.message_th);
    const unreadCls = Number(item.is_read) === 0 ? ' notif-item-unread' : '';
    const meta = notifTypeMeta(item);
    return `<a href="#" class="nav-notif-item${unreadCls}" data-id="${item.id}" data-link="${notifEscapeHtml(item.link_url || '')}">
        <span class="row-type-icon ${meta.rt}"><i class="fa-solid ${notifEscapeHtml(item.icon || meta.icon)}"></i></span>
        <span class="nav-notif-item-body">
            <span class="nav-notif-item-title">${notifEscapeHtml(title)}</span>
            ${message ? `<span class="nav-notif-item-message">${notifEscapeHtml(message)}</span>` : ''}
            <span class="nav-notif-item-time">${notifTimeAgo(item.created_at)}</span>
        </span>
        ${Number(item.is_read) === 0 ? '<span class="nav-notif-item-dot"></span>' : ''}
    </a>`;
}
function notifUpdateBadge(count) {
    const $badge = $('#notifBadge');
    if (count > 0) {
        $badge.text(count > 99 ? '99+' : count).removeClass('d-none');
    } else {
        $badge.addClass('d-none');
    }
}
function notifRefreshUnreadCount() {
    $.getJSON(`${BASE_URL}/api/notification.unread-count`, function (res) {
        if (res.status) notifUpdateBadge(res.data.count || 0);
    });
}
function notifLoadDropdownPage() {
    if (notifDropdownLoading || !notifDropdownHasMore) return;
    notifDropdownLoading = true;
    $('#notifMenuList .nav-notif-loading').remove();
    $('#notifMenuList').append('<div class="nav-notif-loading"><i class="fa-solid fa-spinner fa-spin"></i></div>');
    $.getJSON(`${BASE_URL}/api/notification.list`, { offset: notifDropdownOffset, limit: NOTIF_DROPDOWN_PAGE_SIZE }, function (res) {
        $('#notifMenuList .nav-notif-loading').remove();
        notifDropdownLoading = false;
        if (!res.status) return;
        const rows = res.data || [];
        notifDropdownHasMore = !!res.has_more;
        notifDropdownOffset += rows.length;
        $('#notifMenuEmpty').toggleClass('d-none', notifDropdownOffset > 0 || rows.length > 0);
        rows.forEach(item => $('#notifMenuList').append(notifItemHtml(item)));
    }).fail(function () {
        notifDropdownLoading = false;
        $('#notifMenuList .nav-notif-loading').remove();
    });
}
function notifOpenDropdown() {
    if (!notifDropdownOpenedOnce) {
        notifDropdownOpenedOnce = true;
        notifDropdownOffset = 0;
        notifDropdownHasMore = true;
        notifLoadDropdownPage();
    }
}
// 2026-08-29: "คลิกจาก item นั้นแล้วไปหน้านั้นได้เลย" -- marks read then navigates. Delegated (not bound
// at render time) since rows are appended dynamically as more pages load.
//
// 2026-08-29, same-day follow-up, real bug found and fixed (explicit report: "ถ้ากดเปิดขึ้นมาแล้วแสดงว่า
// ผ่านตาแล้ว ให้ตัวเลขหายไปและ icon สีส้ม เปิดครั้งที่ 2 ก็หายไปเลย" -- an item's unread state was
// intermittently NOT actually clearing server-side despite being clicked, so reopening the
// dropdown/page later still showed it unread with the badge count never dropping). Root cause: the
// old code fired `$.post(...)` (a normal async XHR) and, on the very next line, immediately called
// `window.location.href = ...` for any item that had a link -- which is almost every item. Browsers
// routinely abort an in-flight XHR the instant the page begins unloading for a same-tab navigation,
// so the mark-read request frequently never reached the server at all; it wasn't a timing
// coincidence, it was the common case. `navigator.sendBeacon()` exists specifically for "fire this
// request even though the page is about to navigate away" -- delivery is guaranteed by the browser
// independent of the unload, unlike a plain XHR/fetch. Only used on the navigate-away path; an item
// with no link_url (rare, but possible) still uses a normal awaited POST since there's no unload
// racing it, and needs the response to update this same page's own badge/dot in place.
$(document).on('click', '.nav-notif-item', function (e) {
    e.preventDefault();
    const $item = $(this);
    const id = $item.data('id');
    const link = $item.data('link');
    const url = `${BASE_URL}/api/notification.mark-read`;
    const payload = JSON.stringify({ id: id });
    if (link) {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
        } else {
            $.post(url, payload);
        }
        window.location.href = `${BASE_URL}${link}`;
        return;
    }
    $.post(url, payload, function () {}, 'json').always(function () {
        $item.removeClass('notif-item-unread');
        $item.find('.nav-notif-item-dot').remove();
        notifRefreshUnreadCount();
    });
});
$(document).on('click', '#notifMarkAllReadBtn', function (e) {
    e.stopPropagation();
    $.post(`${BASE_URL}/api/notification.mark-all-read`, function () {
        $('.nav-notif-item-unread').removeClass('notif-item-unread');
        $('.nav-notif-item-dot').remove();
        notifUpdateBadge(0);
        if (typeof tb_notification !== 'undefined' && tb_notification) tb_notification.ajax.reload(null, false);
    });
});
/* ---------- Dedicated "view all" page (app/views/notification/index.php) ----------
   2026-08-29, same-day follow-up: "หน้า /payroll/notifications ให้แสดงเป็นตาราง Datatable และมี Filter
   วันที่ด้วย" -- was an infinite-scroll card list (notifPageInit()/notifPageLoadNext(), removed
   entirely); now a real server-side DataTable (api/notification.datatable,
   NotificationModel::listDataTable()'s own docblock explains why serverSide here specifically) with
   a collapsible Date filter mirroring Employee List's own `.station-filter`. The header
   dropdown/Dashboard widget above are untouched -- notifItemHtml()/notifOpenDropdown() still back
   those, unaffected by this page's own table. */
let tb_notification;
function notifToIsoDate(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function notifStatusBadge(isRead) {
    return Number(isRead) === 0
        ? `<span class="badge bg-warning-subtle text-warning">${langData['notif_status_unread'] || 'Unread'}</span>`
        : `<span class="badge bg-secondary-subtle text-secondary">${langData['notif_status_read'] || 'Read'}</span>`;
}
function notifPageCurrentFilters() {
    return {
        date_from: notifToIsoDate($('#notif_filter_date_from').val()),
        date_to: notifToIsoDate($('#notif_filter_date_to').val()),
        is_read: $('#notif_filter_is_read').val() || '',
    };
}
function updateClearNotifFilterVisibility() {
    const f = notifPageCurrentFilters();
    $('#btnClearNotifFilter').toggleClass('d-none', !(f.date_from || f.date_to || f.is_read));
}
function notifPageTableInit() {
    if ($.fn.DataTable.isDataTable('#tb_notification')) {
        $('#tb_notification').DataTable().ajax.reload(null, false);
        return;
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#notif_filter_date_from');
        initDatepicker('#notif_filter_date_to');
    }
    tb_notification = $('#tb_notification').DataTable({
        serverSide: true,
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/notification.datatable`,
            type: 'POST',
            data: function (d) { Object.assign(d, notifPageCurrentFilters()); }
        },
        columns: [
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => { const m = notifTypeMeta(row); return `<span class="row-type-icon ${m.rt}" style="margin-right:0;"><i class="fa-solid ${notifEscapeHtml(row.icon || m.icon)}"></i></span>`; } },
            {
                data: null,
                render: (d, t, row) => {
                    const title = currentLang === 'th' ? (row.title_th || row.title_en) : (row.title_en || row.title_th);
                    const message = currentLang === 'th' ? (row.message_th || row.message_en) : (row.message_en || row.message_th);
                    const unread = Number(row.is_read) === 0 ? '<span class="nav-notif-item-dot ms-1"></span>' : '';
                    return `<div class="fw-semibold">${notifEscapeHtml(title)}${unread}</div>${message ? `<div class="small text-muted">${notifEscapeHtml(message)}</div>` : ''}`;
                }
            },
            { data: 'created_at', render: d => typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : d },
            { data: 'is_read', render: d => notifStatusBadge(d) },
            {
                data: null, orderable: false, className: 'text-center all',
                render: (d, t, row) => `<button type="button" class="btn btn-sm btn-outline-secondary btn-open-notif-row" data-id="${row.id}" data-link="${notifEscapeHtml(row.link_url || '')}" title="${langData['notif_action_open'] || 'Open'}"><i class="fa-solid fa-arrow-up-right-from-square"></i></button>`
            }
        ],
        order: [[2, 'desc']],
        createdRow: function (rowEl, row) {
            if (Number(row.is_read) === 0) $(rowEl).addClass('notif-row-unread');
        },
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
    });
}
$(document).on('click', '#notifStationFilterToggle', function () {
    const $filter = $('#notifStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
// 'changeDate' alone (not the native 'change' bootstrap-datepicker also fires alongside it) -- same
// reasoning as Employee List's own identical date-filter wiring, avoids a double reload.
$(document).on('changeDate', '#notif_filter_date_from, #notif_filter_date_to', function () {
    updateClearNotifFilterVisibility();
    if (tb_notification) tb_notification.ajax.reload(null, true);
});
$(document).on('change', '#notif_filter_is_read', function () {
    updateClearNotifFilterVisibility();
    if (tb_notification) tb_notification.ajax.reload(null, true);
});
$(document).on('click', '#btnClearNotifFilter', function () {
    $('#notif_filter_date_from, #notif_filter_date_to').datepicker('clearDates');
    $('#notif_filter_is_read').val(null).trigger('change');
    updateClearNotifFilterVisibility();
    if (tb_notification) tb_notification.ajax.reload(null, true);
});
// Same click-to-mark-read-then-navigate as .nav-notif-item (dropdown/dashboard), reusing the same
// sendBeacon-before-unload fix -- see that handler's own docblock above for the full explanation.
$(document).on('click', '.btn-open-notif-row', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const link = $(this).data('link');
    const url = `${BASE_URL}/api/notification.mark-read`;
    const payload = JSON.stringify({ id: id });
    if (link) {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
        } else {
            $.post(url, payload);
        }
        window.location.href = `${BASE_URL}${link}`;
        return;
    }
    $.post(url, payload, function () {}, 'json').always(function () {
        notifRefreshUnreadCount();
        if (tb_notification) tb_notification.ajax.reload(null, false);
    });
});
$(document).ready(function () {
    if ($('#notifBellBtn').length === 0) return; // public/unauthenticated page (e.g. /auth) -- header not loaded
    notifRefreshUnreadCount();
    $('.nav-notif-btn').on('click', function (e) {
        e.stopPropagation();
        const willOpen = !$('#notifMenu').hasClass('active');
        $('#notifMenu').toggleClass('active');
        if (willOpen) notifOpenDropdown();
    });
    // 2026-08-29, real bug found and fixed (explicit report: "ปุ่ม Mark all as read กดแล้วไม่มี Action") --
    // `#notifMenu`'s own direct click handler used to call e.stopPropagation() unconditionally to
    // stop clicking INSIDE the dropdown from also closing it (bubbling up to the document handler
    // right below). But that stopPropagation() also silently blocked every DELEGATED
    // `$(document).on('click', '<selector>', ...)` handler for anything inside the dropdown from
    // ever firing at all -- delegated handlers only run once the event actually reaches `document`
    // during the bubble phase, which stopPropagation() prevents. That's not just Mark-all-read --
    // .nav-notif-item's own click-to-open handler was silently broken the exact same way, just
    // never reported since it fails by doing nothing rather than throwing. Fixed by making the
    // "close on outside click" check smarter instead (only close if the click target is truly
    // outside both the menu and the bell button) rather than blanket-blocking propagation from
    // inside the menu at all -- delegated handlers on document now fire normally.
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#notifMenu, .nav-notif-btn').length) {
            $('#notifMenu').removeClass('active');
        }
    });
    // "Slide ลงมาสุดท้ายแล้วค่อยๆทยอยโหลด" -- infinite scroll within the dropdown's own scrollable list.
    $('#notifMenuList').on('scroll', function () {
        const el = this;
        if (el.scrollTop + el.clientHeight >= el.scrollHeight - 40) {
            notifLoadDropdownPage();
        }
    });
});
$(document).on('click', '#notifPageMarkAllReadBtn', function () {
    $.post(`${BASE_URL}/api/notification.mark-all-read`, function () {
        notifUpdateBadge(0);
        if (tb_notification) tb_notification.ajax.reload(null, false);
    });
});
