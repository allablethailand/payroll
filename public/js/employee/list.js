// Profile completeness (2026-08-19, explicit request): color follows the same red/orange(brand)/
// green scale used for the payroll run validation states elsewhere in this app -- red under 50%
// (needs real attention), brand orange in the middle (getting there), green once genuinely mostly
// filled in. Shared between the list (this file) and the Detail page's own summary card.
function completenessColor(percent) {
    if (percent >= 80) return '#198754';
    if (percent >= 50) return '#FF9900';
    return '#dc3545';
}
// 2026-09-02, explicit request: "ความสมบูรณ์ของ Profile ช่วยปรับเป็น progress วงกลมได้ไหมครับ" -- was a
// horizontal Bootstrap .progress bar, now a small CSS conic-gradient ring (no chart library needed
// for something this small/repeated-per-row -- Chart.js, added earlier this session for the
// Dashboard, would be real per-row canvas overhead multiplied by every row on the page). Only this
// function's OWN rendering changed -- Employee Detail's summary card has its own separate,
// independent completenessColor()/rendering in detail.js and is untouched (this function is only
// ever called from THIS file's own DataTable column).
function completenessRingHtml(percent) {
    const p = Math.max(0, Math.min(100, Number(percent) || 0));
    const color = completenessColor(p);
    return `<div class="employee-completeness-ring" style="background:conic-gradient(${color} ${p}%, #e9ecef ${p}% 100%);" role="progressbar" aria-valuenow="${p}" aria-valuemin="0" aria-valuemax="100" title="${p}%">
        <span class="employee-completeness-ring-value" style="color:${color};">${p}%</span>
    </div>`;
}
// Reload after coming back from Employee Detail (2026-08-19, explicit request: "บันทึกหน้า Detail
// อยากให้ Reload ตาราง Employee ด้วยครับ") -- a normal link click (sidebar nav, breadcrumb) always
// hits the server fresh already, so the only real staleness case is the browser's own Back button:
// it can restore this exact page from bfcache without re-running any of the script above, showing
// whatever completeness/status/etc the table had BEFORE the edit on Detail. event.persisted is true
// only for that bfcache-restore case, not a normal first load (where initEmployeeTable() below
// already fetches fresh).
window.addEventListener('pageshow', function (e) {
    if (e.persisted && $.fn.DataTable.isDataTable('#tb_employee')) {
        $('#tb_employee').DataTable().ajax.reload(null, false);
    }
});
// 2026-08-28, same request, wider case: Employee Detail opens in a brand-new browser TAB (see
// list.js's own `.manage-employee` handler -- `window.open(editPage, '_blank')`), not a same-tab
// navigation, so the bfcache/pageshow fix above (which only covers the Back-button case) never
// fires for it. Reuses the generic markTabDirty()/watchTabDirty() pair in app.js -- Detail's own
// applyEmployeeSaveSuccess() marks 'employee_list_dirty' after every successful save.
if (typeof watchTabDirty === 'function') {
    watchTabDirty('employee_list_dirty', function () {
        if ($.fn.DataTable.isDataTable('#tb_employee')) {
            $('#tb_employee').DataTable().ajax.reload(null, false);
        }
        // 2026-08-30 (T024) -- an edit elsewhere (Detail page, this Recheck modal) may have changed
        // which station an employee belongs to (e.g. Probation -> Permanent) -- refresh counts too.
        refreshEmployeeStationCounts();
    });
}
// 2026-08-30, explicit request: "/payroll/employees กดเลือก Tab ไหน Refresh แล้วให้อยู่ tab เดิม" --
// same URL-hash + history.replaceState mechanism already built for Employee Detail's own #employeeTabs
// (detail.js's activateEmployeeTabFromHash()/shown.bs.tab handler), applied here to this page's
// top-level #employeeTopTabs instead. A tab switch replaces the URL hash (no new history entry, no
// page reload) with that tab button's own id; a hash present on load re-shows that same tab via
// Bootstrap's own Tab API, which also correctly re-fires shown.bs.tab so each tab's own lazy-init
// (DataTable init, station counts, etc.) still runs exactly as it would on a real click.
function activateEmployeeTopTabFromHash() {
    const hash = (location.hash || '').replace('#', '');
    if (!hash) return;
    const $btn = $('#' + CSS.escape(hash));
    if ($btn.length && $btn.attr('data-bs-toggle') === 'tab' && $btn.closest('#employeeTopTabs').length) {
        bootstrap.Tab.getOrCreateInstance($btn[0]).show();
    }
}
$(document).on('shown.bs.tab', '#employeeTopTabs button[data-bs-toggle="tab"]', function (e) {
    if (history.replaceState) {
        history.replaceState(null, '', '#' + e.target.id);
    }
});
$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    // 2026-08-31, explicit request: "Filter ในหน้า Employee List การจ่ายเงินเดือน ให้เลือกเป็นทำจ่าย
    // เงินเดือนเป็น Default" -- app.js's own generic '.select2-static' sweep (runs earlier, before
    // this page's own ready handler) already initialized this field with no explicit value, which
    // select2 defaults to the FIRST option ("all"/no filter) -- re-init here with selectedValue
    // BEFORE initEmployeeTable() below so the table's own first ajax load already reads '1', not a
    // later reload. "Clear Filter" (below) still resets to the genuinely-unfiltered 'all' state,
    // this only changes what the page shows before any user interaction.
    if (typeof initSelect2 === 'function') {
        initSelect2('#employee_filter_payroll_participant', { mode: 'static', selectedValue: '1' });
    }
    initEmployeeTable();
    loadRcPaymentMethodIds();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#employee_filter_date_from');
        initDatepicker('#employee_filter_date_to');
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#employee_filter_role, #employee_filter_department, #employee_filter_team, #employee_filter_shift, #employee_filter_branch', { mode: 'ajax', allowClear: true });
    }
    updateClearEmployeeFilterVisibility();
    refreshEmployeeStationCounts();
    activateEmployeeTopTabFromHash();
    initRcMobileIti();
    });
});
let tb_employee;
// 2026-08-29, explicit request: "ในหน้า List เพิ่ม checkbox ด้านหน้า เพื่อให้เลือกหลายรายการแล้วกด Sync
// ได้หลายคนพร้อมกัน" -- a plain id Set, not DataTables' own row-selection API, since this table is
// serverSide:true (only the CURRENT page's rows ever exist in the DOM/DataTables' own internal
// data at once) -- selection has to survive a page change on its own, same "object/Set-as-a-
// selection-store" pattern already used elsewhere in this app (payroll/detail.js's
// joinSelectedEmployees, payroll/index.js's selectedPendingSync).
let employeeBulkSyncSelection = new Set();
function currentStatusFilters() {
    const $active = $('.employee-status-tab.active');
    return {
        status: $active.data('filter-status') || '',
        employment_status: $active.data('filter-employment-status') || ''
    };
}
function currentEmployeeExtraFilters() {
    // 2026-08-30 (Phase 3, T022) -- 'all' (the dropdown's own default) maps back to '' (no filter);
    // '1'/'0' pass through as-is. See list.php's own comment on why 'all' is used instead of a
    // genuinely blank data-option-values entry.
    const payrollParticipantRaw = $('#employee_filter_payroll_participant').val() || 'all';
    return {
        created_date_from: toIsoDateEmp($('#employee_filter_date_from').val()),
        created_date_to: toIsoDateEmp($('#employee_filter_date_to').val()),
        role_id: $('#employee_filter_role').val() || '',
        department_id: $('#employee_filter_department').val() || '',
        team_id: $('#employee_filter_team').val() || '',
        shift_id: $('#employee_filter_shift').val() || '',
        branch_id: $('#employee_filter_branch').val() || '',
        is_payroll_participant: payrollParticipantRaw === 'all' ? '' : payrollParticipantRaw
    };
}
function toIsoDateEmp(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function updateClearEmployeeFilterVisibility() {
    const f = currentEmployeeExtraFilters();
    const hasFilter = !!(f.created_date_from || f.created_date_to || f.role_id || f.department_id || f.team_id || f.shift_id || f.branch_id || f.is_payroll_participant !== '');
    $('#employeeFilterClearRow').toggleClass('d-none', !hasFilter);
}
// 2026-08-30 (Phase 3, T024) -- populates the station-card pipeline's own .station-count spans.
// Deliberately does NOT send the free-text search term (station counts represent "how many
// employees are in this station" as a stable navigational aid, not "how many match what I just
// typed") -- only the SAME station-independent filters (department/team/shift/branch/role/date
// range/is_payroll_participant) currentEmployeeExtraFilters() already builds for the main table.
function refreshEmployeeStationCounts() {
    const f = currentEmployeeExtraFilters();
    $.post(`${BASE_URL}/api/employee.station-counts`, {
        role_id: f.role_id, department_id: f.department_id, team_id: f.team_id,
        shift_id: f.shift_id, branch_id: f.branch_id, is_payroll_participant: f.is_payroll_participant,
        created_date_from: f.created_date_from, created_date_to: f.created_date_to
    }, function (res) {
        if (!res || !res.status || !res.data) return;
        $('#tab-emp-active .station-count').text(res.data.active || 0);
        $('#tab-emp-probation .station-count').text(res.data.probation || 0);
        $('#tab-emp-permanent .station-count').text(res.data.permanent || 0);
        $('#tab-emp-resign .station-count').text(res.data.resigned || 0);
    });
}
$(document).on('click', '#employeeStationFilterToggle', function () {
    const $filter = $('#employeeStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
// 'changeDate' alone (not the native 'change' bootstrap-datepicker also fires alongside it) --
// same reasoning as the Payroll Process filter this is modeled on, avoids double-firing reload.
$(document).on('changeDate', '#employee_filter_date_from, #employee_filter_date_to', function () {
    updateClearEmployeeFilterVisibility();
    if (tb_employee) tb_employee.ajax.reload(null, true);
    refreshEmployeeStationCounts();
});
$(document).on('change', '#employee_filter_role, #employee_filter_department, #employee_filter_team, #employee_filter_shift, #employee_filter_branch, #employee_filter_payroll_participant', function () {
    updateClearEmployeeFilterVisibility();
    if (tb_employee) tb_employee.ajax.reload(null, true);
    refreshEmployeeStationCounts();
});
$(document).on('click', '#btnClearEmployeeFilter', function () {
    // Clear every control WITHOUT letting each one's own change handler fire its own
    // ajax.reload() -- 'change.select2' only refreshes the widget's display, and clearDates()'s
    // 'changeDate' event is left to fire on the date fields same as the Process page's own Clear
    // Filter (2 reloads there already, accepted) -- one explicit reload below covers the rest.
    $('#employee_filter_role, #employee_filter_department, #employee_filter_team, #employee_filter_shift, #employee_filter_branch').val(null).trigger('change.select2');
    // 2026-08-30 (T022) -- reset to its own real 'all' option, not null (this dropdown has no blank
    // placeholder option the way the select2-remote ones above do).
    $('#employee_filter_payroll_participant').val('all').trigger('change.select2');
    $('#employee_filter_date_from, #employee_filter_date_to').datepicker('clearDates');
    updateClearEmployeeFilterVisibility();
    if (tb_employee) tb_employee.ajax.reload(null, true);
    refreshEmployeeStationCounts();
});
function initEmployeeTable() {
    if ($.fn.DataTable.isDataTable('#tb_employee')) {
        $('#tb_employee').DataTable().ajax.reload(null, false);
        return;
    }
    tb_employee = $('#tb_employee').DataTable({
        processing: true,
        serverSide: true,
        // 2026-08-27, explicit request: "ปุ่มที่ expand ตารางเพื่อดูข้อมูลของ column ที่ซ่อน ควรแยกมาเป็น
        // column แรก" -- `details.type:'column'` + `target:0` makes the responsive expand toggle its
        // OWN dedicated column (column 0 below) instead of DataTables' default of embedding it into
        // whichever column happens to be first (our avatar column, which then made clicking the
        // avatar ambiguous between "view" and "expand"). Every real data column shifted by +1 to make
        // room -- same "inserting a column shifts every later index" convention as Team's own column
        // addition (see CLAUDE.md's Team section); EmployeeModel::list()'s `sortColumns` map updated
        // to match, and this table's `order`/Excel-filter column indices below too.
        responsive: { details: { type: 'column', target: 0 } },
        order: [[3, 'asc']], // employee_no -- shifted from 2 to 3 by the new checkbox column at index 1
        ajax: {
            url: `${BASE_URL}/api/employee.list`,
            type: "POST",
            data: function (d, settings) {
                const filters = currentStatusFilters();
                d.status = filters.status;
                d.employment_status = filters.employment_status;
                Object.assign(d, currentEmployeeExtraFilters());
                // 2026-08-27, explicit request: Excel-style per-column header filter (server mode --
                // see table-column-filter.js's own docblock for why a server-side table needs this
                // sent to the backend rather than filtered in the browser). Built from `settings`
                // (DataTables' own 2nd arg to ajax.data), NOT the outer `tb_employee` variable --
                // DataTables calls this synchronously to build the FIRST request while
                // `tb_employee = $(...).DataTable({...})` is still constructing, so `tb_employee`
                // itself is still undefined at that exact moment (see getColumnFilterValues()'s own
                // comment on why this isn't just defensive paranoia).
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
        },
        // 2026-08-27, explicit follow-up: "column ขวาสุดอยากให้แสดงปุ่มดำเนินการ และตอนนี้พอเป็น
        // responsive table แล้ว การดำเนินการดูยากขึ้น" -- now that `responsive:true` genuinely works
        // (the extension itself was only just installed, see the git history around 2026-08-27), its
        // DEFAULT behavior hides columns starting from the HIGHEST index first when a row doesn't
        // fit the viewport -- which is exactly backwards for this table, since Actions (the rightmost
        // column) is the one column that must never disappear into the collapsed "+" child row.
        // `responsivePriority` (lower number = kept visible longer) overrides that default -- Actions
        // pinned to the same top priority as Name/Employee No. (the row's own identity), everything
        // else ranked by how useful it is to see at a glance without expanding the row.
        columns: [
            // Dedicated Responsive expand/collapse control column (see the `responsive:{details:...}`
            // option above) -- `dtr-control` is the class DataTables Responsive itself looks for to
            // render the +/- toggle into; empty otherwise (no data, no title).
            { data: null, orderable: false, className: 'dtr-control', defaultContent: '' },
            // 2026-08-29, explicit request: "ในหน้า List เพิ่ม checkbox ด้านหน้า เพื่อให้เลือกหลายรายการ
            // แล้วกด Sync ได้หลายคนพร้อมกัน" -- see employeeBulkSyncSelection (below) for how
            // selection is tracked across pages (this table is serverSide:true, so DataTables only
            // ever holds the CURRENT page's rows -- selection has to be its own id Set, not
            // DataTables' own row-selection API, to survive a page change).
            {
                data: null,
                orderable: false,
                className: 'text-center all',
                responsivePriority: 1,
                render: function (data, type, row) {
                    const checked = employeeBulkSyncSelection.has(row.id) ? 'checked' : '';
                    return `<input type="checkbox" class="employee-row-checkbox" data-id="${row.id}" ${checked}>`;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-center',
                responsivePriority: 8,
                render: function (data, type, row) {
                    // 2026-08-30, real gap found and fixed (explicit report: "Sync รูปมาแล้ว ในหน้า
                    // Employee List ยังไม่แสดง") -- profile_photo_path was never selected by
                    // EmployeeModel::list() at all (see that method's own SELECT list), so this
                    // column always fell back to the plain initial-letter circle even for an
                    // employee with a real synced/uploaded photo on file.
                    if (row.profile_photo_path) {
                        return `<img src="${BASE_URL}/${row.profile_photo_path}" class="employee-list-avatar-img" alt="">`;
                    }
                    const letter = (row.name || '').trim().charAt(0).toUpperCase() || '?';
                    return `<div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 38px; height: 38px; min-width: 38px; background-color: #007aff;">${letter}</div>`;
                }
            },
            { data: "employee_no", responsivePriority: 2 },
            {
                // 2026-08-28, same-day follow-up: "สัญลักษณ์ Sync กับ Manual สร้าง ปรับให้แสดงผลสวยๆ
                // และอยู่ใน Column ที่เป็นระเบียบ" -- was a bare icon squeezed onto the end of the
                // Employee No. column (see git history for that version's own reasoning); the user
                // came back asking for a proper, tidy column instead. `orderable: false` (same as
                // the completeness column further along) means this needs ZERO changes to
                // EmployeeModel::list()'s sortColumns/listColumnExprMap index map -- unlike a
                // sortable column, a display-only one costs nothing to insert anywhere in this
                // array. Same 3-way badge convention (colored bg-*-subtle/text-* pill) already
                // established by Manual Entry's own list (sourceBadgeMe()) and Payroll Run Detail's
                // employee list (dataSourceBadgeRd()), reusing their existing source_manual/
                // source_sync/source_import i18n keys.
                data: "data_source",
                orderable: false,
                responsivePriority: 8,
                render: function (data, type) {
                    if (type !== 'display') return data;
                    const meta = {
                        sync: { icon: 'fa-cloud-arrow-down', cls: 'bg-primary-subtle text-primary' },
                        import: { icon: 'fa-file-import', cls: 'bg-info-subtle text-info' },
                        manual: { icon: 'fa-user-pen', cls: 'bg-light text-dark' },
                    };
                    const m = meta[data] || meta.manual;
                    const label = langData['source_' + (data || 'manual')] || data || '';
                    return `<span class="badge rounded-pill ${m.cls}"><i class="fa-solid ${m.icon} me-1"></i>${escapeHtml(label)}</span>`;
                }
            },
            { data: "name", responsivePriority: 1 },
            { data: "phone", render: d => d || '-', responsivePriority: 9 },
            // 2026-08-29, explicit request: "เพิ่ม Email ในหน้า List ของพนักงานด้วยครับ" -- already
            // selected server-side (EmployeeModel::list()'s own `e.personal_email AS email`), just
            // never rendered as a column here before now.
            { data: "email", render: d => d || '-', responsivePriority: 9 },
            { data: "role", responsivePriority: 6 },
            { data: "position", render: d => d || '-', responsivePriority: 7 },
            { data: "department", responsivePriority: 5 },
            { data: "team", render: d => d || '-', responsivePriority: 10 },
            { data: "shift", render: d => d || '-', responsivePriority: 10 },
            { data: "branch", responsivePriority: 7 },
            // Plain render is safe here (unlike the client-side tables elsewhere in this pass) --
            // this table is serverSide:true, so sorting is done server-side via ORDER BY on the
            // real DB column, entirely unaffected by how the client renders it for display.
            { data: "start_work_date", render: d => formatDisplayDate(d), responsivePriority: 6 },
            {
                data: "status",
                responsivePriority: 4,
                render: function (data) {
                    let badge = data === 'Active' ? 'bg-success' : 'bg-danger';
                    return `<span class="badge ${badge}">${data}</span>`;
                }
            },
            // 2026-08-31, explicit request: "ในตารางให้มีสัญลักษณ์บอกด้วยว่าจ่ายหรือไม่จ่ายเงินเดือน" --
            // object-form render (Table convention: display differs from the raw sort/filter value)
            // reading `payroll_participant_flag` (EmployeeModel::list()'s own deliberately-different
            // alias for `is_payroll_participant`, see that method's own comment on why the bare key
            // would have been stripped before reaching here).
            {
                data: "payroll_participant_flag",
                responsivePriority: 6,
                render: {
                    display: function (d) {
                        return Number(d) === 1
                            ? `<span class="badge bg-success-subtle text-success"><i class="fa-solid fa-money-check-dollar me-1"></i>${escapeHtml(langData['payroll_participant_yes'] || 'Pays Salary')}</span>`
                            : `<span class="badge bg-secondary-subtle text-secondary"><i class="fa-solid fa-ban me-1"></i>${escapeHtml(langData['payroll_participant_no'] || 'No Salary')}</span>`;
                    },
                    sort: d => Number(d) || 0,
                    filter: d => Number(d) || 0,
                }
            },
            {
                data: "completeness",
                orderable: false,
                responsivePriority: 5,
                render: function (data) {
                    return completenessRingHtml(data);
                }
            },
            {
                data: null,
                orderable: false,
                // 2026-08-27, real bug found and fixed (explicit report: "ปุ่มแก้ไขปุ่มลบ หายไปครับ
                // column ท้าย") -- had this backwards: DataTables Responsive's `className: 'never'`
                // does NOT mean "never hidden" -- per its own source (`_classLogic()`), `never` is
                // treated exactly like `className: 'none'`: "never show this column in the table at
                // all, only reachable via the expand row" -- i.e. the OPPOSITE of what was wanted,
                // which is why the buttons vanished outright instead of just staying put. The correct
                // class for "always visible, never collapse into the expand row" is `all`/`dtr-all`
                // (confirmed directly against the extension's own source, not guessed a second time).
                className: 'all',
                responsivePriority: 1,
                render: function (data, type, row) {
                    // 2026-08-28, explicit follow-up: "สามารถกดได้จากในหน้า List" -- the per-employee
                    // Re-Sync/Sync action (previously only reachable from inside Employee Detail's own
                    // profile header, see detail.js's updateOrigamiSyncSummary()) is now also available
                    // right here per-row, so an admin scanning the whole roster doesn't need to open
                    // every employee individually just to sync them. Same endpoint
                    // (api/employee-sync.resync-one), same employee_no fallback matching for a row
                    // with no origami_ref_id yet -- see EmployeeSyncModel::resyncOne()'s own docblock.
                    // Gated on IS_ORIGAMI_HR_LINKED same as the bulk "Sync from Origami"/"Sync Log"
                    // buttons above (a company with no Origami HR link at all has nothing to sync).
                    // 2026-08-29, explicit request: "ปุ่ม Sync ให้เปลี่ยนเป็นสีฟ้าทั้งในหน้า List และ
                    // Detail" -- was text-secondary (linked)/text-warning (not-yet-linked), now
                    // uniformly blue regardless of link state (per the request's own plain wording).
                    // `text-info` specifically, not `text-primary` -- this app's own :root override
                    // (see style.css's ".btn-primary" section) repoints --bs-primary at brand orange,
                    // so `text-primary` would silently render orange here, not blue; --bs-info was
                    // never touched, so it's still Bootstrap's real cyan-blue.
                    let syncBtn = '';
                    if (typeof IS_ORIGAMI_HR_LINKED !== 'undefined' && IS_ORIGAMI_HR_LINKED) {
                        syncBtn = `<button class="btn btn-link btn-circle-action text-info sync-one-employee" data-id="${row.id}" data-i18n-tooltip="employee_sync_list_action_title"><i class="fa-solid fa-rotate"></i></button>`;
                    }
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group -- Employee
                    // List first, per the request's own wording.
                    return `<div class="d-flex gap-1 justify-content-center">
                        <button class="btn btn-link btn-circle-action text-warning manage-employee" data-id="${row.employee_no}" data-i18n-tooltip="edit"><i class="fa-solid fa-pen-to-square"></i></button>
                        ${syncBtn}
                        <button class="btn btn-link btn-circle-action text-danger delete-employee" data-id="${row.id}" data-i18n-tooltip="delete"><i class="fa-solid fa-trash-can"></i></button>
                    </div>`;
                }
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            let self = this.api();
            let $wrapper = $(self.table().container());
            let $searchDiv = $wrapper.find('.dt-search');
            // 2026-08-29, explicit request: "โดยปุ่ม Sync หลายรายการให้อยู่ต่อกับ Show 50 entries" --
            // deliberately injected into `.dt-length` (the "Show N entries" control), NOT `.dt-search`
            // where every other button on this page lives -- the request specifically asked for this
            // one to sit next to that control instead of the usual search-bar button row.
            let $lengthDiv = $wrapper.find('.dt-length');
            if (typeof IS_ORIGAMI_HR_LINKED !== 'undefined' && IS_ORIGAMI_HR_LINKED && $lengthDiv.find('#btnBulkSyncSelected').length === 0) {
                let bulkSyncBtn = `
                    <button class="btn btn-outline-info ms-2" id="btnBulkSyncSelected" type="button" disabled>
                        <i class="fa-solid fa-rotate me-2"></i><span data-i18n="employee_bulk_sync_button">Sync Selected</span>
                        <span class="badge bg-info ms-1" id="employeeBulkSyncCount">0</span>
                    </button>
                `;
                $lengthDiv.append(bulkSyncBtn);
            }
            if ($searchDiv.find('.manage-employee').length === 0) {
                // 2026-08-30, real bug found and fixed (explicit report: "ปุ่ม 'เพิ่มพนักงานใหม่' ไม่เปลี่ยน
                // ภาษา") -- this button is built ONCE by initComplete, which only re-fires on a true
                // DataTables re-init; the `$searchDiv.find('.manage-employee').length === 0` guard
                // above means it's never rebuilt again after that (initEmployeeTable()'s own re-call
                // path just does an ajax.reload(), it doesn't re-run initComplete at all -- see that
                // function's own docblock). Since the label text was plain-templated with no
                // `data-i18n`, it stayed frozen in whatever language was active the first time this
                // table ever initialized. Adding `data-i18n` lets the EXISTING generic
                // updateText(document) sweep (already run on every language change via
                // applyLanguage()) pick it up for free -- no table-rebuild needed at all.
                let btn = `
                    <button class="btn btn-primary manage-employee ms-1" data-id="">
                        <i class="fa-solid fa-plus me-2"></i><span data-i18n="employee">${langData['employee'] || 'Employee'}</span>
                    </button>
                `;
                $searchDiv.append(btn);
            }
            // 2026-08-28, explicit request: "ต้องการปุ่ม Sync ข้อมูล Employee จากระบบ Origami" --
            // see public/js/employee/employee-sync.js for the picker modal this opens.
            // 2026-08-28, same-day follow-up: "ถ้าไม่ใช่บริษัทที่มาจาก Origami ปุ่ม Sync จะไม่ขึ้น" --
            // both Sync buttons below now gated on IS_ORIGAMI_HR_LINKED (set once in
            // layout/header.php from companies.ref_id), not just left to fail with a "not linked"
            // message after the admin already clicked in -- a company with no Origami HR link at
            // all never sees these buttons.
            if (typeof IS_ORIGAMI_HR_LINKED !== 'undefined' && IS_ORIGAMI_HR_LINKED) {
                if ($searchDiv.find('#btnOpenEmployeeSync').length === 0) {
                    // 2026-08-29, explicit request: "ปุ่ม Sync ให้เปลี่ยนเป็นสีฟ้า" -- btn-outline-info,
                    // not btn-outline-primary (this app's --bs-primary override makes that orange,
                    // see the per-row Sync icon's own comment above for the full reasoning).
                    // "Sync Log" right below stays btn-outline-secondary on purpose -- it's a
                    // history VIEWER, not a sync-triggering action, so it's not in scope of "the
                    // Sync button" this request means.
                    let syncBtn = `
                        <button class="btn btn-outline-info ms-1" id="btnOpenEmployeeSync" type="button">
                            <i class="fa-solid fa-rotate me-2"></i><span data-i18n="employee_sync_button">Sync from Origami</span>
                        </button>
                    `;
                    $searchDiv.append(syncBtn);
                }
                // 2026-08-28, explicit request: "ย้ายปุ่มประวัติการ Sync ให้หน่อยครับ ตอนนี้ดูสะเปะสะปะ"
                // (move the Sync Log button, it looks scattered right now) -- was squeezed into the
                // picker modal's own header between the title and the close button. Moved out here
                // as a proper peer button next to Sync from Origami, matching this app's own
                // convention (every action button lives in the DataTable's search bar, not floating
                // inside a modal header). Also fixes a real reachability gap this uncovered: since
                // the picker modal now blocks its own content behind a "Not connected" panel when
                // Origami isn't configured (see employee-sync.js), Sync Log used to be unreachable
                // in that state too -- it opens its own separate modal and reads past history only,
                // so it doesn't need a live connection at all.
                if ($searchDiv.find('#btnOpenEmployeeSyncLog').length === 0) {
                    let syncLogBtn = `
                        <button class="btn btn-outline-secondary ms-1" id="btnOpenEmployeeSyncLog" type="button">
                            <i class="fa-solid fa-clock-rotate-left me-2"></i><span data-i18n="employee_sync_log_button">Sync Log</span>
                        </button>
                    `;
                    $searchDiv.append(syncLogBtn);
                }
            }
            if (typeof updateText === 'function') updateText($searchDiv[0]);
            let $input = $searchDiv.find('input').off('.employeeSearch');
            $input.on('keypress.employeeSearch', function (e) {
                if (e.keyCode === 13) {
                    self.search(this.value).draw();
                }
                if(e.value === "") {
                    self.search(this.value).draw();
                }
            });
            // 2026-08-27, explicit request: Excel-style per-column header filter (proof-of-concept,
            // server mode -- see table-column-filter.js's own docblock). Excludes the avatar/
            // completeness/actions columns (not meaningfully filterable), same "only columns
            // explicitly opted in get a filter" convention that component documents.
            initExcelColumnFilters(self, {
                mode: 'server',
                // 2026-08-28: shifted +1 from index 4 onward -- a new "Source" column (data_source
                // badge) was inserted right after Employee No. It's deliberately absent from this
                // list (no `{ index: 4, key: 'data_source' }` entry) since it isn't a real filter
                // target here -- same "only columns explicitly listed get filter UI" rule this
                // module's own docblock states (avatar/completeness/actions columns are excluded
                // the same way).
                // 2026-08-29: shifted AGAIN -- a checkbox column (index 1, bulk sync selection) and
                // an Email column (index 7, right after Phone) were both inserted. Email itself is
                // included here too (same shape as Phone, genuinely filterable contact info) --
                // EmployeeModel::listColumnExprMap() now has an 'email' entry to match.
                columns: [
                    { index: 3, key: 'employee_no' },
                    { index: 5, key: 'name' },
                    { index: 6, key: 'phone' },
                    { index: 7, key: 'email' },
                    { index: 8, key: 'role' },
                    { index: 9, key: 'position' },
                    { index: 10, key: 'department' },
                    { index: 11, key: 'team' },
                    { index: 12, key: 'shift' },
                    { index: 13, key: 'branch' },
                    { index: 14, key: 'start_work_date' },
                    { index: 15, key: 'status' },
                    // 2026-08-31: new "จ่ายเงินเดือน" badge column, index 16 -- see
                    // EmployeeModel::listColumnExprMap()'s own 'payroll_participant' entry.
                    { index: 16, key: 'payroll_participant' },
                ],
                fetchValues: function (key, done) {
                    const filters = currentStatusFilters();
                    const payload = Object.assign({ column: key, status: filters.status, employment_status: filters.employment_status }, currentEmployeeExtraFilters());
                    payload.column_filters = getColumnFilterValues(tb_employee);
                    $.ajax({
                        url: `${BASE_URL}/api/employee.list-column-values`,
                        method: 'POST',
                        data: payload,
                        dataType: 'json'
                    }).done(function (res) {
                        done((res && res.values) || []);
                    }).fail(function () {
                        done([]);
                    });
                },
                onApply: function () { tb_employee.ajax.reload(null, false); }
            });
        },
        drawCallback: function () {
            getTableLang();
            updateEmployeeBulkSyncUi();
        }
    });
}
// 2026-08-29, explicit request: "ในหน้า List เพิ่ม checkbox ด้านหน้า เพื่อให้เลือกหลายรายการแล้วกด Sync
// ได้หลายคนพร้อมกัน" -- keeps the header "select all" checkbox and the bulk button's
// enabled/disabled state + live count in sync with employeeBulkSyncSelection. Called after every
// draw (drawCallback above, covers pagination/filter/sort/reload) AND after every individual
// checkbox toggle below -- a page redraw doesn't fire on a plain checkbox click, so both triggers
// are needed to keep this correct in every case.
function updateEmployeeBulkSyncUi() {
    const count = employeeBulkSyncSelection.size;
    $('#employeeBulkSyncCount').text(count);
    $('#btnBulkSyncSelected').prop('disabled', count === 0);
    // "Select all" reflects the CURRENT PAGE only (this is a serverSide table -- there is no single
    // DOM state representing "every row across every page" to check it against), same convention
    // DataTables' own row-selection extension uses for a serverSide table.
    const $pageCheckboxes = $('.employee-row-checkbox');
    const allChecked = $pageCheckboxes.length > 0 && $pageCheckboxes.filter(':checked').length === $pageCheckboxes.length;
    $('#employeeSelectAllCheckbox').prop('checked', allChecked);
}
$(document).on('change', '.employee-row-checkbox', function () {
    const id = $(this).data('id');
    if ($(this).is(':checked')) {
        employeeBulkSyncSelection.add(id);
    } else {
        employeeBulkSyncSelection.delete(id);
    }
    updateEmployeeBulkSyncUi();
});
$(document).on('change', '#employeeSelectAllCheckbox', function () {
    const checked = $(this).is(':checked');
    $('.employee-row-checkbox').prop('checked', checked).each(function () {
        const id = $(this).data('id');
        if (checked) {
            employeeBulkSyncSelection.add(id);
        } else {
            employeeBulkSyncSelection.delete(id);
        }
    });
    updateEmployeeBulkSyncUi();
});
// 2026-08-29, explicit request: "การกด Sync ข้อมูลพนักงานใหม่ให้ขึ้น Confirm ก่อนทั้งในหน้า List และ
// Detail" -- confirm before running the bulk sync, same showConfirm() pattern every other
// destructive/consequential action in this app already uses.
$(document).on('click', '#btnBulkSyncSelected', function () {
    const ids = Array.from(employeeBulkSyncSelection);
    if (ids.length === 0) return;
    const title = langData['confirm_sync_title'] || 'Confirm Sync';
    const message = (langData['confirm_bulk_sync_message'] || 'Re-sync {count} selected employee(s) from Origami?').replace('{count}', ids.length);
    showConfirm(title, message, function () {
        const $btn = $('#btnBulkSyncSelected').prop('disabled', true);
        $.ajax({
            url: `${BASE_URL}/api/employee-sync.resync-many`, method: 'POST',
            data: { employee_ids: ids }, dataType: 'json',
            success: function (res) {
                if (!res.status) {
                    $btn.prop('disabled', false);
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                    return;
                }
                const summary = (langData['bulk_sync_result'] || '{success} succeeded, {error} failed.')
                    .replace('{success}', res.success).replace('{error}', res.error);
                if (res.error > 0) {
                    showWarning(summary);
                } else {
                    showSuccess(summary);
                }
                employeeBulkSyncSelection.clear();
                updateEmployeeBulkSyncUi();
                if (tb_employee) tb_employee.ajax.reload(null, false);
            },
            error: function () {
                $btn.prop('disabled', false);
                showWarning(langData['save_failed'] || 'An error occurred while saving.');
            }
        });
    });
});
$(document).on('click', '.manage-employee', function() {
    let employee_no = $(this).data("id");
    let editPage = `${BASE_URL}/employees/`;
    if (employee_no) {
        editPage += employee_no;
    } else {
        editPage += 'create';
    }
    window.open(editPage, '_blank');
});
// 2026-08-28, explicit follow-up: per-row Sync from the List, mirrors detail.js's
// #btnResyncOneEmployee click handler (see that file's docblock) -- same endpoint, same
// ref_id-then-employee_no fallback matching server-side, just reachable without opening the
// employee first.
// 2026-08-29, explicit request: "การกด Sync ข้อมูลพนักงานใหม่ให้ขึ้น Confirm ก่อนทั้งในหน้า List และ
// Detail" -- confirm before running, same as detail.js's own #btnResyncOneEmployee handler now does.
$(document).on('click', '.sync-one-employee', function () {
    const id = $(this).data('id');
    if (!id) return;
    const $btn = $(this);
    const title = langData['confirm_sync_title'] || 'Confirm Sync';
    const message = langData['confirm_sync_one_message'] || 'Re-sync this employee from Origami?';
    showConfirm(title, message, function () {
        $btn.prop('disabled', true);
        $.ajax({
            url: `${BASE_URL}/api/employee-sync.resync-one`, method: 'POST',
            data: { employee_id: id }, dataType: 'json',
            success: function (res) {
                $btn.prop('disabled', false);
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                if (tb_employee) {
                    tb_employee.ajax.reload(null, false);
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                showWarning(langData['save_failed'] || 'An error occurred while saving.');
            }
        });
    });
});
$(document).on('click', '.employee-status-tab', function (e) {
    const $btn = $(this);
    if ($btn.data('unsupported')) {
        e.preventDefault();
        e.stopPropagation();
        showWarning(langData['unsupported_feature'] || 'This feature is not available yet.');
        return;
    }
    $('.employee-status-tab').removeClass('active').attr('aria-selected', 'false');
    $btn.addClass('active').attr('aria-selected', 'true');
    if (tb_employee) {
        tb_employee.ajax.reload();
    }
});
$(document).on('click', '.delete-employee', function () {
    const id = $(this).data('id');
    if (!id) return;
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/employee.delete`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['delete_success'] || 'Deleted successfully.');
                    if (tb_employee) {
                        tb_employee.ajax.reload(null, false);
                    }
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                }
            },
            error: function () {
                showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
            }
        });
    });
});

/* ==================== Recheck ข้อมูล tab (Phase 3, T018/T019, 2026-08-30) ====================
   Server-side (unbounded employee count, same convention as #tb_employee). Lazy-inits on first
   shown.bs.tab (this app's own standing habit -- a DataTable built while its own tab-pane is
   display:none collapses every column to 0 width). Each per-field column is a derived boolean, not
   a raw sortable/filterable value -- no Excel-column-filter/per-column sort here, just the shared
   station filter (role/department/team/shift/branch, same fields as the main Employee tab) + search.
   ==================== */
let tb_employee_recheck;
// 2026-08-31, explicit request: view toggle for the Recheck tab (see EmployeeModel::recheckList()'s
// own $participantMode comment) -- 'participant' (default, is_payroll_participant=1) or 'excluded'
// (is_payroll_participant=0, the "Not in Payroll" list an admin can Add Back from).
let currentEmployeeRecheckView = 'participant';
function recheckFieldIcon(ready) {
    return ready
        ? '<i class="fa-solid fa-circle-check text-success" title="' + (langData['ready'] || 'Ready') + '"></i>'
        : '<i class="fa-solid fa-circle-xmark text-danger" title="' + (langData['not_ready'] || 'Not Ready') + '"></i>';
}
// Identification is always applicable (every employee needs SOME form of ID) -- fieldReadiness()
// only ever returns id_card_no (domestic) XOR tax_id_no+passport_no+work_permit_no (foreigner), so
// which key(s) are actually present tells us which shape this row is.
function recheckIdentificationHtml(fr) {
    const ready = ('id_card_no' in fr) ? !!fr.id_card_no : !!(fr.tax_id_no && fr.passport_no && fr.work_permit_no);
    return recheckFieldIcon(ready);
}
// 2026-08-30, explicit request: "และในข้อมูลบัญชีธนาคาร ให้บอกประเภทการจ่ายเงิน เป็นเงินสุด หรือบัญชี ถ้าบัญชี
// มีเลขบัญชีหรือยัง" -- widened from a bare ready/not-ready icon to also show WHICH payment method this
// employee is actually on. 2026-09-02, follow-up: the old bank/cash-only `payment_type` enum was
// dropped in favor of `payment_method_id` (master_payment_methods, 4 codes: transfer/cash/check/
// mixed) -- recheckList() now exposes the resolved `payment_method_code` per row (computed via the
// same cached lookup save()/get() use), excluded from the raw-column strip list same as employee_no
// is. Cash/check need no bank details at all (own badge, no dash) -- Transfer/Mixed show the same
// ready/not-ready icon as before, now labeled.
function recheckBankDetailsHtml(row) {
    const fr = row.field_readiness || {};
    const code = row.payment_method_code;
    if (code === 'cash' || code === 'check') {
        const label = code === 'cash' ? (langData['payment_type_cash'] || 'Cash') : (langData['payment_method_check'] || 'Check');
        return `<span class="badge bg-secondary-subtle text-secondary">${label}</span>`;
    }
    if (code === 'transfer' || code === 'mixed') {
        const hasAccount = !!(fr.bank_id && fr.bank_account_no);
        const cls = hasAccount ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
        const label = hasAccount ? (langData['payment_type_bank_ready'] || 'Bank: Account set') : (langData['payment_type_bank_missing'] || 'Bank: No account yet');
        return `<span class="badge ${cls}">${label}</span>`;
    }
    // payment_method_id genuinely never set at all -- distinct dash state, same as before.
    return '<span class="text-muted">-</span>';
}
// 2026-08-31, explicit request: "ตรงหน้าตรวจสอบเหมือนยังขาด ประกันสังคม ทั้งตารางและหน้า Form" -- sso_status
// comes pre-computed from EmployeeModel::recheckList() (never_enrolled/enrolled_missing_no/
// enrolled_complete -- see that model's own comment). Deliberately NOT the same red/green ready/
// not-ready binary as the other field_readiness columns -- "not enrolled" is a valid real state (not
// every employee must be SSO-enrolled), only "enrolled but no SSO number recorded" is an actual gap.
function recheckSsoStatusHtml(status) {
    if (status === 'enrolled_complete') {
        return `<span class="badge bg-success-subtle text-success">${langData['sso_status_enrolled'] || 'Enrolled'}</span>`;
    }
    if (status === 'enrolled_missing_no') {
        return `<span class="badge bg-danger-subtle text-danger">${langData['sso_status_missing_no'] || 'Enrolled, No. Missing'}</span>`;
    }
    return `<span class="badge bg-secondary-subtle text-secondary">${langData['sso_status_not_enrolled'] || 'Not Enrolled'}</span>`;
}
// 2026-08-30, explicit request: "เพิ่ม Column OT เพิ่มว่าคิดหรือไม่คิด ถ้าคิดคิด Rate ของ OT แต่ละประเภท" --
// ot_summary comes from EmployeeOtRateModel::summaryForEmployees() (see EmployeeModel::recheckList()).
// A scope with has_rate=false means NEITHER this employee's own override NOR their resolved OT Rate
// Set (2026-08-30 replacement) has that scope configured -- shown as "not set" in the tooltip rather
// than silently omitted, since that's exactly the gap that would make real OT payroll calculation fail for this
// employee (missing_ot_rate_{scope} in SyncPayResolver).
function recheckOtSummaryHtml(otSummary) {
    if (!otSummary || !otSummary.eligible) {
        return `<span class="badge bg-secondary-subtle text-secondary">${langData['ot_not_eligible_short'] || 'Not Eligible'}</span>`;
    }
    const scopeLines = (otSummary.scopes || []).map(function (s) {
        const name = currentLang === 'th' ? s.scope_name_th : s.scope_name_en;
        if (!s.has_rate) {
            return `${name}: ${langData['ot_rate_not_configured'] || 'not configured'}`;
        }
        const rateText = s.calculation_method === 'flat_amount'
            ? `${Number(s.flat_amount_rate || 0).toFixed(2)}/${s.calculation_base === 'daily' ? (langData['ot_base_daily'] || 'Daily') : (langData['ot_base_hourly'] || 'Hourly')}`
            : `${s.multiplier_rate}x`;
        const overrideMark = s.is_override ? ' *' : '';
        return `${name}: ${rateText}${overrideMark}`;
    });
    const title = scopeLines.join(' | ') + (otSummary.rate_source === 'custom' ? ` (${langData['ot_rate_source_custom'] || 'Set Individually per OT Type'}, * = ${langData['ot_rate_override_mark'] || 'custom'})` : '');
    return `<span class="badge bg-success-subtle text-success" title="${escapeHtml(title)}">${langData['ot_eligible_short'] || 'Eligible'}</span>`;
}
function currentEmployeeRecheckFilters() {
    return {
        role_id: $('#employee_recheck_filter_role').val() || '',
        department_id: $('#employee_recheck_filter_department').val() || '',
        team_id: $('#employee_recheck_filter_team').val() || '',
        shift_id: $('#employee_recheck_filter_shift').val() || '',
        branch_id: $('#employee_recheck_filter_branch').val() || '',
        view: currentEmployeeRecheckView
    };
}
// 2026-09-08, explicit follow-up request: "อยู่ในระบบเงิน ควรขึ้นไปอยู่บน Filter เป็น select" -- was a
// `.btn-group` toggle in its own row below the filter card; app.js's own generic '.select2-static'
// sweep already initializes #employee_recheck_filter_view with no explicit value (defaults to the
// FIRST option, 'participant'/"In Payroll") -- re-init with an explicit selectedValue anyway, same
// "before this table's own first ajax load" precedent as #employee_filter_payroll_participant above,
// so this doesn't silently depend on option order alone.
$(document).ready(function () {
    (window.langReady || Promise.resolve()).then(function () {
    if (typeof initSelect2 === 'function') {
        initSelect2('#employee_recheck_filter_view', { mode: 'static', selectedValue: 'participant' });
    }
    });
});
function updateClearEmployeeRecheckFilterVisibility() {
    const f = currentEmployeeRecheckFilters();
    // 2026-09-08: `view` joined this same filter row (was a separate .btn-group toggle before) --
    // 'participant' ("In Payroll") is its default, so only 'excluded' counts as an active filter here,
    // same "non-default state shows Clear Filter" convention the Employee tab's own
    // is_payroll_participant filter already uses (see updateClearEmployeeFilterVisibility() above).
    const hasFilter = !!(f.role_id || f.department_id || f.team_id || f.shift_id || f.branch_id || f.view !== 'participant');
    $('#employeeRecheckFilterClearRow').toggleClass('d-none', !hasFilter);
}
function initEmployeeRecheckTable() {
    if ($.fn.DataTable.isDataTable('#tb_employee_recheck')) {
        tb_employee_recheck.ajax.reload(null, false);
        return;
    }
    tb_employee_recheck = $('#tb_employee_recheck').DataTable({
        serverSide: true,
        processing: true,
        ordering: false,
        // 2026-09-08, explicit follow-up request: "อยากให้ column รหัสพนักงาน และชื่อพนักงาน fixed อยู่กับที่
        // ฝั่งซ้าย...และ column Action อยากให้ fixed อยู่ขวาตลอด ส่วน Column ส่วนกลางๆ อยากให้ใช้เมาส์เลื่อนดู
        // ข้อมูลได้" -- reverts the 2026-08-30 responsive:true/column-collapse choice back to a frozen-
        // column layout. 2026-09-08 same-day follow-up ("ตอนนี้ใช้เมาส์เลื่อนเพื่อลากดู column ไม่ได้") --
        // the FIRST attempt used DataTables' own core `scrollX` option, which needs CSS
        // (`.dataTables_scrollBody { overflow-x:auto; }` etc.) that lives in the BASE `datatables.net`
        // skin's own stylesheet -- this app only ever loads the `datatables.net-bs5` skin on top of
        // it, never that base skin itself, so `scrollX` had nothing to actually create a scrollable
        // container with (confirmed by grepping the installed CSS directly). Rebuilt on this app's own
        // ALREADY-established, ALREADY-working convention instead (see list.php's own comment on this
        // table, and CLAUDE.md/style.css's "no DataTables scrollX, just .table-responsive" note) --
        // the view now wraps this table in a plain `.table-responsive` div, and `initStickyColumns()`
        // (public/js/sticky-table-columns.js, plain CSS position:sticky) freezes columns 1-2 (Employee
        // No.+Employee) on the left and the last column (Actions) on the right directly on this table's
        // own cells -- NOT DataTables' own FixedColumns extension, which is confirmed broken in this
        // app for an unrelated reason (see that file's own docblock). Every field-readiness/
        // Identification/Bank Details/Status column in between scrolls horizontally instead of
        // collapsing into an expand row -- the dtr-control column from the old responsive:true layout
        // is gone, nothing left to expand.
        drawCallback: function () { initStickyColumns('#tb_employee_recheck', { left: 2, right: 1 }); },
        ajax: {
            url: `${BASE_URL}/api/employee.recheck-list`,
            type: 'POST',
            data: function (d) { Object.assign(d, currentEmployeeRecheckFilters()); }
        },
        columns: [
            // 2026-08-31, explicit request: "ตารางพนักงานทุกตาราง แยก code กับชื่อเป็นคนละ Column" -- was
            // one column with employee_no/name stacked as 2 divs, split into 2 real columns (matches
            // the main #tb_employee table's own convention, which already had them separate).
            { data: 'employee_no', render: d => escapeHtml(d || '-') },
            { data: 'name', render: d => escapeHtml(d || '-') },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.title) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.gender) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.name_th) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.name_en) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.date_of_birth) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.nationality) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckIdentificationHtml(row.field_readiness) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.personal_email) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.mobile_no) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.department_id) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.position_id) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.branch_id) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.employment_date) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckOtSummaryHtml(row.ot_summary) },
            { data: 'sso_status', className: 'text-center', render: d => recheckSsoStatusHtml(d) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckBankDetailsHtml(row) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.base_salary_amount) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.salary_effective_date) },
            { data: null, className: 'text-center', render: (d, t, row) => recheckFieldIcon(!!row.field_readiness.tax_calculation_method) },
            { data: 'is_ready', className: 'text-center', render: d => d ? `<span class="badge bg-success-subtle text-success">${langData['ready'] || 'Ready'}</span>` : `<span class="badge bg-danger-subtle text-danger">${langData['not_ready'] || 'Not Ready'}</span>` },
            {
                // 2026-08-31, explicit request: "เพิ่มปุ่มให้นำออกจากการจ่ายเงินเดือน และมีปุ่มเพิ่ม Employee ที่
                // ไม่ทำจ่ายเงินเดือนกลับเข้ามาทำเงินเดือน" -- every row in a given ajax response shares the
                // SAME is_payroll_participant value (recheckList() forces it via $participantMode, see that
                // method's own comment), so branching on the current view toggle (not a per-row field) is
                // correct and avoids needing to select+strip yet another raw column server-side.
                data: null, className: 'text-center', orderable: false, render: (d, t, row) => {
                    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                    // ".btn-circle-action" section) replace the old adjacent .btn-group.
                    const editBtn = `<button type="button" class="btn btn-link btn-circle-action text-secondary btn-recheck-edit" data-employee-no="${escapeHtml(row.employee_no)}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen-to-square"></i></button>`;
                    const toggleBtn = currentEmployeeRecheckView === 'excluded'
                        ? `<button type="button" class="btn btn-link btn-circle-action text-success btn-recheck-add-back" data-id="${row.id}" data-employee-no="${escapeHtml(row.employee_no)}" title="${langData['add_back_to_payroll'] || 'Add Back to Payroll'}"><i class="fa-solid fa-user-plus"></i></button>`
                        : `<button type="button" class="btn btn-link btn-circle-action text-danger btn-recheck-remove" data-id="${row.id}" data-employee-no="${escapeHtml(row.employee_no)}" title="${langData['remove_from_payroll'] || 'Remove from Payroll'}"><i class="fa-solid fa-user-slash"></i></button>`;
                    return `<div class="d-flex gap-1 justify-content-center">${editBtn}${toggleBtn}</div>`;
                }
            },
        ],
        // 2026-08-30, real gap found and fixed (same audit as tb_login_history_overview above) --
        // was missing entirely on this table too, same fix.
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        // 2026-09-08, round 3 follow-up -- fires ONCE, after DataTables has already built its own
        // length/search/info/pagination controls as siblings of the table (see list.php's own comment
        // on this table for why doing this any earlier, e.g. a static wrapper in the view, was wrong).
        // `initTableDragScroll()` (public/js/sticky-table-columns.js) wraps ONLY the `<table>` element
        // itself in `.table-responsive` at this point and adds real click-and-hold-then-drag panning
        // on top of it (plain `overflow-x:auto` alone only ever supports scrollbar-drag/shift+wheel).
        initComplete: function () { initTableDragScroll('#tb_employee_recheck'); },
    });
}
$(document).on('shown.bs.tab', '#employee-recheck-top-tab', function () {
    initEmployeeRecheckTable();
});
$(document).on('click', '#employeeRecheckStationFilterToggle', function () {
    const $filter = $('#employeeRecheckStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('change', '#employee_recheck_filter_role, #employee_recheck_filter_department, #employee_recheck_filter_team, #employee_recheck_filter_shift, #employee_recheck_filter_branch', function () {
    updateClearEmployeeRecheckFilterVisibility();
    if (tb_employee_recheck) tb_employee_recheck.ajax.reload(null, true);
});
$(document).on('click', '#btnClearEmployeeRecheckFilter', function () {
    $('#employee_recheck_filter_role, #employee_recheck_filter_department, #employee_recheck_filter_team, #employee_recheck_filter_shift, #employee_recheck_filter_branch').val(null).trigger('change.select2');
    // 2026-09-08: reset the view select back to its own default ('participant'/"In Payroll") too --
    // it's part of this same filter row now, so Clear Filter should clear it as well, same as every
    // other field here. The 'change.select2' trigger fires the plain `change` handler above (which
    // updates currentEmployeeRecheckView itself), same event-namespacing convention this file's
    // other Clear Filter handlers already rely on.
    $('#employee_recheck_filter_view').val('participant').trigger('change.select2');
    updateClearEmployeeRecheckFilterVisibility();
    if (tb_employee_recheck) tb_employee_recheck.ajax.reload(null, true);
});
// Reuses the same bfcache/cross-tab-open staleness fixes #tb_employee's own init already has above.
if (typeof watchTabDirty === 'function') {
    watchTabDirty('employee_list_dirty', function () {
        if ($.fn.DataTable.isDataTable('#tb_employee_recheck')) {
            $('#tb_employee_recheck').DataTable().ajax.reload(null, false);
        }
    });
}

/* ==================== Recheck quick-edit modal (T019, 2026-08-30) ====================
   CRITICAL: EmployeeModel::save() always overwrites EVERY column from whatever's submitted (an
   absent key is treated as NULL, not "leave unchanged" -- see that method's own docblock). This
   modal only ever shows ~18 of the employee's ~80 columns, so its Save handler fetches the FULL
   existing record first (rcFullData below) and submits that WHOLE object with just this form's own
   fields merged in on top -- never the form's fields alone, which would silently blank out every
   other tab's data (Documents, Family, Contact address, etc.) on this employee. Exactly the same
   "always resubmit everything" discipline collectEmployeeFormData() already follows on the real
   Employee Detail page. ==================== */
let rcFullData = null;
function toDisplayDateRc(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function populateSelect2FieldRc(name, id, textTh, textEn) {
    const $sel = $(`#employeeRecheckEditModal [name="${name}"]`);
    if (!$sel.length || !id) return;
    const label = (currentLang === 'th' ? (textTh || textEn) : (textEn || textTh)) || String(id);
    $sel.empty().append(new Option(label, id, true, true)).trigger('change');
}
function rcApplyIdentificationAndBankVisibility(employeeType, paymentMethodCode) {
    $('#rcDomesticIdWrap').toggleClass('d-none', employeeType === 'foreigner');
    $('#rcForeignerIdWrap').toggleClass('d-none', employeeType !== 'foreigner');
    // 2026-08-30, same-day follow-up (form reorganized into numbered sections) -- hides the WHOLE
    // "5. Payment Information" section (its own numbered header included, #rcPaymentSectionWrap),
    // not just the field row underneath -- a cash-paid employee has no payment info to show at all,
    // and an empty numbered section with no fields under it would read as a rendering glitch.
    // 2026-09-02, follow-up: shown for 'mixed' too, same as Employee Detail's own
    // applyAccountPickerVisibility() -- a mixed employee's own RECEIVING bank_id/bank_account_no is a
    // separate concept from their payment_method_lines split, still worth showing/editing here.
    $('#rcPaymentSectionWrap').toggleClass('d-none', paymentMethodCode !== 'transfer' && paymentMethodCode !== 'mixed');
}
// Resolved once per page (small fixed list, 4 rows) so the radio pair below can write a real
// payment_method_id (the FK, not a code string) into the hidden #rc_payment_type input -- the old
// payment_type enum column this modal used to write directly no longer exists (dropped 2026-09-02,
// see database/migrations/2026-09-02_19_drop_legacy_payment_type.sql).
let rcPaymentMethodIdByCode = {};
function loadRcPaymentMethodIds() {
    $.post(`${BASE_URL}/api/payment-method.options`, { page: 1, limit: 50 }, function (res) {
        if (!res.status) return;
        (res.data.items || []).forEach(function (item) {
            rcPaymentMethodIdByCode[item.code] = item.id;
        });
    }, 'json');
}
// 2026-08-30, explicit request: payment_type is now a real editable field in this modal (was a
// read-only badge) -- live-toggles the bank section the moment the admin switches it, same as the
// real Employee Detail page's own payment_type_radio change handler already does.
// 2026-08-31, explicit request: "ประเภทการจ่ายเงิน ให้เปลี่ยนเป็น radio" -- was a select2-static dropdown,
// now a plain Bootstrap btn-check radio pair + hidden mirror input (#rc_payment_type), matching
// Employee Detail's own payment_type_radio pattern exactly (detail.js:652).
// 2026-09-02, follow-up: radio values are now payment method CODES ('transfer'/'cash', deliberately
// still just these 2 -- see this modal's own markup comment on why check/mixed stay out of its
// scope), resolved through rcPaymentMethodIdByCode into the real id the hidden input actually submits.
$(document).on('change', 'input[name="rc_payment_type_radio"]', function () {
    const code = $(this).val();
    if (rcPaymentMethodIdByCode[code]) {
        $('#rc_payment_type').val(rcPaymentMethodIdByCode[code]);
    }
    rcApplyIdentificationAndBankVisibility($('#rcEditTypeBadge').data('employeeType'), code);
});
// 2026-08-30, explicit request: "รวมถึง Form ในหน้าตรวจสอบด้วยครับ" -- the Recheck modal's OT section
// now mirrors Employee Detail's own Salary-tab OT section (radio source + per-scope override table),
// duplicated here rather than shared across files -- same "each page-specific JS file owns its own
// small helpers" convention this app already follows for e.g. fmtNumRd/fmtNumPr/fmtNumAp.
function rcOtRateOverrideRowHtml(scope) {
    const name = currentLang === 'th' ? scope.scope_name_th : scope.scope_name_en;
    const isFlat = scope.calculation_method === 'flat_amount';
    return `<tr data-scope-id="${scope.ot_scope_id}">
        <td>${escapeHtml(name)}</td>
        <td>
            <select class="form-select form-select-sm select2-static rc-ot-rate-calc-method"
                    data-option-keys="ot_calc_method_multiplier,ot_calc_method_flat_amount" data-option-values="multiplier,flat_amount"></select>
        </td>
        <td>
            <div class="rc-ot-rate-multiplier-wrap ${isFlat ? 'd-none' : ''}">
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm rc-ot-rate-multiplier-input" value="${scope.multiplier_rate}">
            </div>
            <div class="rc-ot-rate-flat-wrap ${isFlat ? '' : 'd-none'}">
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm rc-ot-rate-flat-input" value="${scope.flat_amount_rate !== null && scope.flat_amount_rate !== undefined ? scope.flat_amount_rate : ''}">
            </div>
        </td>
        <td>
            <select class="form-select form-select-sm select2-static rc-ot-rate-calc-base"
                    data-option-keys="ot_base_hourly,ot_base_daily" data-option-values="hourly,daily"></select>
        </td>
    </tr>`;
}
let rcOtRateScopesData = [];
function loadOtRateForRc(employeeId) {
    if (!employeeId) return;
    $.ajax({
        url: `${BASE_URL}/api/employee.ot-rate.get`,
        method: 'GET',
        data: { employee_id: employeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            rcOtRateScopesData = res.scopes || [];
            $(`#rc_ot_rate_source_${res.ot_rate_source === 'custom' ? 'custom' : 'default'}`).prop('checked', true);
            // Gated on BOTH OT Eligible being checked AND source=custom -- loadOtRateForRc() runs
            // async after #rc_ot_eligible's own synchronous change-handler already set the baseline
            // visibility, so this must re-check the checkbox itself rather than assume it's still on.
            $('#rcOtRateOverridesContainer').toggleClass('d-none', !($('#rc_ot_eligible').is(':checked') && res.ot_rate_source === 'custom'));
            $('#rcOtRateOverridesBody').html(rcOtRateScopesData.map(rcOtRateOverrideRowHtml).join(''));
            if (typeof initSelect2 === 'function') {
                initSelect2('#rcOtRateOverridesBody .rc-ot-rate-calc-method', { mode: 'static' });
                initSelect2('#rcOtRateOverridesBody .rc-ot-rate-calc-base', { mode: 'static' });
            }
            rcOtRateScopesData.forEach(function (scope) {
                const $row = $(`#rcOtRateOverridesBody tr[data-scope-id="${scope.ot_scope_id}"]`);
                $row.find('.rc-ot-rate-calc-method').val(scope.calculation_method).trigger('change.select2');
                $row.find('.rc-ot-rate-calc-base').val(scope.calculation_base).trigger('change.select2');
            });
        }
    });
}
$(document).on('change', '#rc_ot_eligible', function () {
    $('#rcOtRateSourceWrap').toggleClass('d-none', !$(this).is(':checked'));
    if (!$(this).is(':checked')) {
        $('#rcOtRateOverridesContainer').addClass('d-none');
    }
});
// 2026-08-31, explicit request: "ตรงหน้าตรวจสอบเหมือนยังขาด ประกันสังคม ทั้งตารางและหน้า Form" -- same
// show/hide-on-checkbox convention as #rc_ot_eligible right above.
$(document).on('change', '#rc_sso_enrolled', function () {
    $('#rcSsoDetailWrap').toggleClass('d-none', !$(this).is(':checked'));
});
$(document).on('change', 'input[name="rc_ot_rate_source_radio"]', function () {
    $('#rcOtRateOverridesContainer').toggleClass('d-none', $(this).val() !== 'custom');
});
$(document).on('change', '.rc-ot-rate-calc-method', function () {
    const $row = $(this).closest('tr');
    const isFlat = $(this).val() === 'flat_amount';
    $row.find('.rc-ot-rate-multiplier-wrap').toggleClass('d-none', isFlat);
    $row.find('.rc-ot-rate-flat-wrap').toggleClass('d-none', !isFlat);
});
// 2026-08-31, explicit request: "ใน Form ตรงที่เป็นเบอร์มือถือ อยากให้รูปแบบเดียวกับใน Employee Detail มี
// Prefix ด้วย" -- same intl-tel-input country flag/dial-code picker as Employee Detail's own
// #mobile_no/initMobileIti()/syncMobileCountryCode() (detail.js), mirrored here under its own
// window.rcMobileIti/#rc_mobile_country_code names (a separate instance -- this modal is never on
// the same page as Employee Detail, but scoped independently rather than reusing the same globals).
function initRcMobileIti() {
    if (typeof intlTelInput !== 'function') return;
    const el = document.getElementById('rc_mobile_no');
    if (!el) return;
    window.rcMobileIti = intlTelInput(el, {
        initialCountry: 'th', separateDialCode: true, numberDisplayFormat: 'NATIONAL',
        // formatAsYouType/strictMode off: mobile_no is validated/stored server-side as plain digits
        // only -- the library's live formatting inserts spaces per country, which would fail that.
        formatAsYouType: false, strictMode: false, countrySearch: true
    });
    syncRcMobileCountryCode();
    el.addEventListener('countrychange', syncRcMobileCountryCode);
}
function syncRcMobileCountryCode() {
    if (!window.rcMobileIti) return;
    const c = window.rcMobileIti.getSelectedCountry();
    $('#rc_mobile_country_code').val(c && c.dialCode ? ('+' + c.dialCode) : '+66');
}
// 2026-08-31, explicit request: "เพิ่มปุ่มให้นำออกจากการจ่ายเงินเดือน และมีปุ่มเพิ่ม Employee ที่ไม่ทำ
// จ่ายเงินเดือนกลับเข้ามาทำเงินเดือน" -- toggles which of the two views (In Payroll / Not in Payroll) the
// Recheck table shows; just re-renders the SAME table against currentEmployeeRecheckFilters()'s new
// `view` key, no separate table instance needed (see EmployeeModel::recheckList()'s own comment).
// 2026-09-08, same-day follow-up: was a `.btn-group` click handler -- now a plain select `change`
// (see the filter-row markup's own comment on why it moved), same effect otherwise.
$(document).on('change', '#employee_recheck_filter_view', function () {
    const view = $(this).val() || 'participant';
    updateClearEmployeeRecheckFilterVisibility();
    if (view === currentEmployeeRecheckView) return;
    currentEmployeeRecheckView = view;
    if (tb_employee_recheck) tb_employee_recheck.ajax.reload(null, true);
});
function toggleEmployeePayrollParticipant($btn, employeeId, participant, confirmTitle, confirmMessage, successMessage) {
    showConfirm(confirmTitle, confirmMessage, function () {
        $btn.prop('disabled', true);
        $.ajax({
            url: `${BASE_URL}/api/employee.payroll-participant.set`, method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: employeeId, is_payroll_participant: participant ? 1 : 0 }),
            dataType: 'json',
            success: function (res) {
                $btn.prop('disabled', false);
                if (!res.status) {
                    showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                    return;
                }
                showSuccess(successMessage);
                if (tb_employee_recheck) tb_employee_recheck.ajax.reload(null, false);
                if (tb_employee) tb_employee.ajax.reload(null, false);
            },
            error: function () {
                $btn.prop('disabled', false);
                showWarning(langData['save_failed'] || 'An error occurred while saving.');
            }
        });
    });
}
$(document).on('click', '.btn-recheck-remove', function () {
    const $btn = $(this);
    const employeeId = $btn.data('id');
    if (!employeeId) return;
    toggleEmployeePayrollParticipant(
        $btn, employeeId, false,
        langData['confirm_remove_from_payroll_title'] || 'Remove from Payroll',
        langData['confirm_remove_from_payroll_message'] || 'This employee will be excluded from every future payroll run until added back. Continue?',
        langData['removed_from_payroll_success'] || 'Employee removed from payroll.'
    );
});
$(document).on('click', '.btn-recheck-add-back', function () {
    const $btn = $(this);
    const employeeId = $btn.data('id');
    if (!employeeId) return;
    toggleEmployeePayrollParticipant(
        $btn, employeeId, true,
        langData['confirm_add_back_to_payroll_title'] || 'Add Back to Payroll',
        langData['confirm_add_back_to_payroll_message'] || 'This employee will be included in payroll runs again. Continue?',
        langData['added_back_to_payroll_success'] || 'Employee added back to payroll.'
    );
});
$(document).on('click', '.btn-recheck-edit', function () {
    const employeeNo = $(this).data('employee-no');
    const $btn = $(this);
    $btn.prop('disabled', true);
    $.getJSON(`${BASE_URL}/api/employee.get`, { employee_no: employeeNo }, function (res) {
        $btn.prop('disabled', false);
        if (!res.status || !res.data) {
            showWarning(res.message || langData['load_failed'] || 'Failed to load data.');
            return;
        }
        rcFullData = res.data;
        const d = res.data;
        $('#rc_id').val(d.id);
        $('#rc_employee_no').val(d.employee_no);
        $('#rcEditEmployeeNoLabel').text(d.employee_no || '');
        $('#rcEditTypeBadge').text(d.employee_type === 'foreigner' ? (langData['foreigner'] || 'Foreigner') : (langData['domestic'] || 'Domestic')).data('employeeType', d.employee_type);
        // 2026-09-02, follow-up: this modal only ever offers transfer/cash (see its own markup
        // comment) -- an employee already on check/mixed gets the radio pair disabled with a note
        // instead of being silently reinterpreted as one of the two this modal DOES support, and the
        // hidden input keeps their REAL existing payment_method_id untouched so an unrelated save
        // through this modal can't accidentally change it away from check/mixed.
        const pmCode = d.payment_method_code;
        const rcPaymentEditable = pmCode === 'transfer' || pmCode === 'cash' || !pmCode;
        $('#rcPaymentTypeRadioGroup input').prop('disabled', !rcPaymentEditable);
        $('#rcPaymentMethodOtherNote').toggleClass('d-none', rcPaymentEditable);
        // Seeded from the employee's REAL existing id first, regardless of branch below -- guards
        // against rcPaymentMethodIdByCode not having finished loading yet by the time this modal is
        // opened (its own change handler is a no-op until that map is populated, see above), so the
        // hidden input is never left blank/stale even in that race.
        $('#rc_payment_type').val(d.payment_method_id || '');
        if (rcPaymentEditable) {
            $(`input[name="rc_payment_type_radio"][value="${pmCode === 'cash' ? 'cash' : 'transfer'}"]`).prop('checked', true).trigger('change');
        } else {
            rcApplyIdentificationAndBankVisibility(d.employee_type, pmCode);
        }
        $('#rc_title').val(d.title || '').trigger('change');
        $('#rc_gender').val(d.gender || 'male').trigger('change');
        $('#rc_date_of_birth').val(toDisplayDateRc(d.date_of_birth));
        if (typeof $.fn.datepicker === 'function') $('#rc_date_of_birth').datepicker('update');
        $('#rc_name_th').val(d.name_th || '');
        $('#rc_name_en').val(d.name_en || '');
        $('#rc_surname_th').val(d.surname_th || '');
        $('#rc_surname_en').val(d.surname_en || '');
        $('#rc_id_card_no').val(d.id_card_no || '');
        $('#rc_tax_id_no').val(d.tax_id_no || '');
        $('#rc_passport_no').val(d.passport_no || '');
        $('#rc_work_permit_no').val(d.work_permit_no || '');
        $('#rc_personal_email').val(d.personal_email || '');
        $('#rc_mobile_no').val(d.mobile_no || '');
        // Generic .val() above already writes the correct raw values -- this only re-selects the
        // flag in the intl-tel-input widget to match the loaded country (same "setNumber() re-
        // formats with spaces, strip back to plain digits after" precedent as detail.js's own
        // identical block).
        if (window.rcMobileIti && d.mobile_no) {
            window.rcMobileIti.setNumber((d.mobile_country_code || '+66') + d.mobile_no);
            const $rcMobileNo = $('#rc_mobile_no');
            $rcMobileNo.val(($rcMobileNo.val() || '').replace(/\D/g, ''));
            syncRcMobileCountryCode();
        }
        $('#rc_employment_date').val(toDisplayDateRc(d.employment_date));
        if (typeof $.fn.datepicker === 'function') $('#rc_employment_date').datepicker('update');
        $('#rc_employment_type').val(d.employment_type || '').trigger('change');
        $('#rc_sso_enrolled').prop('checked', d.sso_enrolled == 1 || d.sso_enrolled === true).trigger('change');
        $('#rc_sso_no').val(d.sso_no || '');
        $('#rc_sso_start_date').val(toDisplayDateRc(d.sso_start_date));
        if (typeof $.fn.datepicker === 'function') $('#rc_sso_start_date').datepicker('update');
        $('#rc_bank_account_no').val(d.bank_account_no || '');
        $('#rc_base_salary_amount').val(d.base_salary_amount || '');
        $('#rc_salary_effective_date').val(toDisplayDateRc(d.salary_effective_date));
        if (typeof $.fn.datepicker === 'function') $('#rc_salary_effective_date').datepicker('update');
        $('#rc_tax_calculation_method').val(d.tax_calculation_method || '').trigger('change');
        $('#rc_ot_eligible').prop('checked', d.ot_eligible == 1 || d.ot_eligible === true).trigger('change');
        // 2026-08-30, explicit request: "เพิ่มปุ่มใน Form ตรวจสอบข้อมูล ให้กดแล้วไปหน้า Profile พนักงานคนนั้น"
        $('#rcGoToFullProfileBtn').attr('href', `${BASE_URL}/employees/${d.employee_no}`);
        loadOtRateForRc(d.id);
        populateSelect2FieldRc('nationality', d.nationality, d.nationality_name_th, d.nationality_name_en);
        populateSelect2FieldRc('department_id', d.department_id, d.department_name_th, d.department_name_en);
        populateSelect2FieldRc('position_id', d.position_id, d.position_name_th, d.position_name_en);
        populateSelect2FieldRc('branch_id', d.branch_id, d.branch_name_th, d.branch_name_en);
        if (d.bank_id) {
            const prefix = d.bank_code ? `${d.bank_code} - ` : '';
            populateSelect2FieldRc('bank_id', d.bank_id, prefix + (d.bank_name_th || ''), prefix + (d.bank_name_en || ''));
        } else {
            $('#employeeRecheckEditModal [name="bank_id"]').empty().trigger('change');
        }
        rcApplyIdentificationAndBankVisibility(d.employee_type, pmCode);
        new bootstrap.Modal(document.getElementById('employeeRecheckEditModal')).show();
    }).fail(function () {
        $btn.prop('disabled', false);
        showWarning(langData['load_failed'] || 'Failed to load data.');
    });
});
function collectRcFormData() {
    const data = {};
    $('#employeeRecheckEditForm [name]').each(function () {
        const $el = $(this);
        const name = $el.attr('name');
        if (!name) return;
        // 2026-08-30, explicit request adding rc_ot_eligible (this modal's first-ever checkbox field
        // -- real bug avoided, not fixed after the fact: without this branch, .val() on a checkbox
        // returns its `value` ATTRIBUTE ("1") regardless of checked state, so ot_eligible would have
        // always saved as true even when the box was left unchecked).
        if ($el.is(':checkbox')) {
            data[name] = $el.is(':checked');
            return;
        }
        if ($el.hasClass('datepicker')) {
            data[name] = toIsoDateEmp($el.val());
            return;
        }
        data[name] = $el.val();
    });
    return data;
}
$(document).on('submit', '#employeeRecheckEditForm', function (e) {
    e.preventDefault();
    if (!rcFullData) return;
    // Merges this form's edited fields ON TOP of the full existing record fetched when the modal
    // opened -- submits the WHOLE merged object, per this section's own top-of-block warning.
    const payload = Object.assign({}, rcFullData, collectRcFormData());
    payload.id = $('#rc_id').val();
    const employeeId = payload.id;
    const $btn = $('#employeeRecheckEditForm button[type="submit"]');
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);

    // 2026-08-30, explicit request: the OT rate override rows now live in this same form -- saved via
    // their own separate api/employee.ot-rate.save call (same reasoning as Employee Detail's own
    // saveSalaryTab(): the override rows live in a different table, api/employee.save has no idea
    // about them). rc_ot_rate_source_radio deliberately has NO `name="ot_rate_source"` attribute, so
    // collectRcFormData() never picks it up into payload above -- api/employee.save preserves
    // whatever ot_rate_source this employee already had (same NOT-NULL safety fix as the Detail page),
    // and THIS call is the one that actually changes it.
    const promises = [$.ajax({
        url: `${BASE_URL}/api/employee.save`, method: 'POST',
        contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload)
    })];
    if ($('#rc_ot_eligible').is(':checked')) {
        const otRateSource = $('input[name="rc_ot_rate_source_radio"]:checked').val() || 'default';
        const overrides = [];
        if (otRateSource === 'custom') {
            $('#rcOtRateOverridesBody tr').each(function () {
                const $tr = $(this);
                overrides.push({
                    ot_scope_id: $tr.data('scope-id'),
                    calculation_method: $tr.find('.rc-ot-rate-calc-method').val(),
                    multiplier_rate: $tr.find('.rc-ot-rate-multiplier-input').val(),
                    flat_amount_rate: $tr.find('.rc-ot-rate-flat-input').val(),
                    calculation_base: $tr.find('.rc-ot-rate-calc-base').val(),
                });
            });
        }
        promises.push($.ajax({
            url: `${BASE_URL}/api/employee.ot-rate.save`, method: 'POST',
            contentType: 'application/json', dataType: 'json',
            data: JSON.stringify({ employee_id: employeeId, ot_rate_source: otRateSource, overrides: overrides })
        }));
    }

    Promise.all(promises).then(function (results) {
        $btn.prop('disabled', false).html(originalHtml);
        const allOk = results.every(r => r && r.status);
        if (allOk) {
            showSuccess(langData['save_success'] || 'Saved successfully.');
            bootstrap.Modal.getInstance(document.getElementById('employeeRecheckEditModal'))?.hide();
            if (tb_employee_recheck) tb_employee_recheck.ajax.reload(null, false);
            if (typeof markTabDirty === 'function') markTabDirty('employee_list_dirty');
        } else {
            const failed = results.find(r => !r || !r.status);
            showWarning((failed && failed.message) || langData['save_failed'] || 'Failed to save data.');
        }
    }).catch(function () {
        $btn.prop('disabled', false).html(originalHtml);
        showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
    });
});
$(document).on('shown.bs.tab', '#employee-recheck-top-tab', function () {
    // Lazy-init this modal's own datepicker/select2 controls once (idempotent -- initDatepicker/
    // initSelect2 both no-op harmlessly if called again on an already-initialized element).
    if (typeof initDatepicker === 'function') {
        initDatepicker('#employeeRecheckEditModal .datepicker');
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#employeeRecheckEditModal .select2-remote', { mode: 'ajax' });
        initSelect2('#employeeRecheckEditModal .select2-native', { mode: 'native' });
        // 2026-08-30, explicit request: the new OT section's ot_rate_source -- a static (fixed
        // 2-choice) enum, same select2-static/data-option-keys convention every other small enum
        // select in this app uses (payment method itself moved off this pattern to a plain radio
        // pair on 2026-08-31, see rc_payment_type_radio above).
        initSelect2('#employeeRecheckEditModal .select2-static', { mode: 'static' });
    }
});
