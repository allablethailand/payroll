// 2026-09-02, 3-way Employee submenu split -- extracted verbatim from list.js's own "Login History
// overview tab (2026-08-29)" section (see app/views/employee/login-history.php's own comment for
// the full backstory). Now the sole content of its own standalone page instead of one of 4 tabs on
// /payroll/employees, so the only functional change is the page-load init at the bottom of this
// file (was a shown.bs.tab lazy-init, see that block's own comment).
/* ==================== Login History overview tab (2026-08-29) ====================
   Explicit request: "ในหน้า employee list ก็ให้แยกเป็น 2 tab tab employee กับประวัติการเข้าใช้ ดูภาพรวมของ
   ทุกคน มี Filter ด้วย" -- company-wide version of the same tab already built on Employee Detail (that
   one is scoped to one employee, see EmployeeLoginLogModel::list()'s own docblock) -- this is every
   employee at once, plus an employee picker to narrow it to one person without leaving this
   overview. Lazy-inits its DataTable on first shown.bs.tab (this app's own standing habit -- a
   DataTable constructed while its own tab-pane is display:none collapses every column to 0 width).
   ==================== */
let tb_login_history_overview;
/* 2026-08-30, explicit request: "ทุกตารางที่มี icon ให้เป็นรูปแบบเดียวกับ report ทั้งหมดครับ" -- reuses
   the shared .row-type-icon gradient badge (style.css), same mapping as
   loginHistoryDeviceIconRd() in employee/detail.js's own Login History table. */
function loginHistoryOverviewDeviceIcon(deviceType) {
    const map = { desktop: { icon: 'fa-desktop', rt: 'rt-3' }, mobile: { icon: 'fa-mobile-screen', rt: 'rt-1' }, tablet: { icon: 'fa-tablet-screen-button', rt: 'rt-5' }, bot: { icon: 'fa-robot', rt: 'rt-4' } };
    return map[deviceType] || { icon: 'fa-question', rt: 'rt-2' };
}
function loginHistoryOverviewEmployeeName(row) {
    const first = currentLang === 'th' ? (row.name_th || row.name_en) : (row.name_en || row.name_th);
    const last = currentLang === 'th' ? (row.surname_th || row.surname_en) : (row.surname_en || row.surname_th);
    return [first, last].filter(Boolean).join(' ') || row.employee_no;
}
function loginHistoryOverviewCurrentFilters() {
    return {
        employee_id: $('#loginHistoryOverviewFilterEmployee').val() || '',
        date_from: $('#loginHistoryOverviewFilterDateFrom').val() || '',
        date_to: $('#loginHistoryOverviewFilterDateTo').val() || '',
        device_type: $('#loginHistoryOverviewFilterDevice').val() || '',
        browser_name: $('#loginHistoryOverviewFilterBrowser').val() || '',
    };
}
function updateClearLoginHistoryOverviewFilterVisibility() {
    const f = loginHistoryOverviewCurrentFilters();
    const hasFilter = !!(f.employee_id || f.date_from || f.date_to || f.device_type || f.browser_name);
    $('#loginHistoryOverviewFilterClearRow').toggleClass('d-none', !hasFilter);
}
function loadLoginHistoryOverviewFilterOptions() {
    $.getJSON(`${BASE_URL}/api/employee-login-log.filter-options-company-wide`, function (res) {
        if (!res.status) return;
        const $device = $('#loginHistoryOverviewFilterDevice').empty().append(`<option value="">${langData['select_option'] || 'All'}</option>`);
        (res.data.device_types || []).forEach(v => $device.append(`<option value="${v}">${v}</option>`));
        const $browser = $('#loginHistoryOverviewFilterBrowser').empty().append(`<option value="">${langData['select_option'] || 'All'}</option>`);
        (res.data.browser_names || []).forEach(v => $browser.append(`<option value="${v}">${v}</option>`));
        $device.trigger('change');
        $browser.trigger('change');
    });
}
// 2026-08-30, Phase 7 (T037/T038 follow-up) -- see employee/detail.js's own equivalent comment.
function loginHistoryOverviewStatusBadge(row) {
    if (Number(row.is_active) === 1) {
        return `<span class="badge bg-success-subtle text-success">${langData['session_status_active'] || 'Active'}</span>`;
    }
    const reasonKey = { new_login: 'session_reason_new_login', switch_app: 'session_reason_switch_app', timeout: 'session_reason_timeout' }[row.ended_reason];
    const label = (reasonKey && langData[reasonKey]) || langData['session_status_ended'] || 'Ended';
    const tone = row.ended_reason === 'timeout' ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary';
    return `<span class="badge ${tone}">${$('<div>').text(label).html()}</span>`;
}
function initLoginHistoryOverviewTable() {
    if ($.fn.DataTable.isDataTable('#tb_login_history_overview')) {
        tb_login_history_overview.ajax.reload();
        return;
    }
    tb_login_history_overview = $('#tb_login_history_overview').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        order: [[1, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/employee-login-log.list-company-wide`,
            type: 'POST',
            data: function (d) { Object.assign(d, loginHistoryOverviewCurrentFilters()); }
        },
        columns: [
            { data: null, render: (d, t, row) => `<div class="fw-semibold">${$('<div>').text(loginHistoryOverviewEmployeeName(row)).html()}</div><div class="small text-muted">${$('<div>').text(row.employee_no || '').html()}</div>` },
            { data: 'login_at', render: d => $('<div>').text(typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : (d || '-')).html() },
            { data: 'logout_at', render: d => $('<div>').text(d && typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : '-').html() },
            { data: 'ip_address', render: d => $('<div>').text(d || '-').html() },
            { data: null, render: (d, t, row) => $('<div>').text([row.location_city, row.location_country].filter(Boolean).join(', ') || '-').html() },
            { data: 'timezone', render: d => $('<div>').text(d || '-').html() },
            { data: 'device_type', render: d => { const m = loginHistoryOverviewDeviceIcon(d); return `<span class="row-type-icon ${m.rt}"><i class="fa-solid ${m.icon}"></i></span>${$('<div>').text(d || '-').html()}`; } },
            { data: null, render: (d, t, row) => $('<div>').text([row.os_name, row.os_version].filter(Boolean).join(' ') || '-').html() },
            { data: null, render: (d, t, row) => $('<div>').text([row.browser_name, row.browser_version].filter(Boolean).join(' ') || '-').html() },
            { data: null, orderable: false, render: (d, t, row) => loginHistoryOverviewStatusBadge(row) },
        ],
        // 2026-08-30, real gap found and fixed (explicit request: "จำนวนแสดงต่อหน้า 50 รายการเป็น
        // Default...มีตารางอื่นที่ยังไม่ใช้ Format เดียวกันอีกไหมครับ") -- was missing entirely, silently
        // falling back to DataTables' own built-in default of 10 instead of this app's real system-
        // wide default (see app.js's own `const pageLength = 50`/`lengthMenu`, loaded on every page).
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
    });
}
// 2026-09-02, 3-way Employee submenu split -- this used to be a shown.bs.tab lazy-init (this app's
// own standing habit for a DataTable inside a non-default Bootstrap tab, since building one while
// its pane is display:none collapses every column to 0 width). Now the whole content of its own
// standalone page (/payroll/employees/login-history), visible from first paint, so that concern no
// longer applies -- inits directly on page load instead.
$(document).ready(function () {
    loadLoginHistoryOverviewFilterOptions();
    initLoginHistoryOverviewTable();
});
$(document).on('click', '#employeeLoginHistoryStationFilterToggle', function () {
    const $filter = $('#employeeLoginHistoryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('changeDate', '#loginHistoryOverviewFilterDateFrom, #loginHistoryOverviewFilterDateTo', function () {
    updateClearLoginHistoryOverviewFilterVisibility();
    if (tb_login_history_overview) tb_login_history_overview.ajax.reload();
});
$(document).on('change', '#loginHistoryOverviewFilterEmployee, #loginHistoryOverviewFilterDevice, #loginHistoryOverviewFilterBrowser', function () {
    updateClearLoginHistoryOverviewFilterVisibility();
    if (tb_login_history_overview) tb_login_history_overview.ajax.reload();
});
$(document).on('click', '#btnClearLoginHistoryOverviewFilter', function () {
    $('#loginHistoryOverviewFilterEmployee').val(null).trigger('change');
    $('#loginHistoryOverviewFilterDateFrom').val('');
    if (typeof $.fn.datepicker === 'function') $('#loginHistoryOverviewFilterDateFrom').datepicker('update');
    $('#loginHistoryOverviewFilterDateTo').val('');
    if (typeof $.fn.datepicker === 'function') $('#loginHistoryOverviewFilterDateTo').datepicker('update');
    $('#loginHistoryOverviewFilterDevice').val(null).trigger('change');
    $('#loginHistoryOverviewFilterBrowser').val(null).trigger('change');
});

