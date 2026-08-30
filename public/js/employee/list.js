function escapeHtmlList(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
// Profile completeness (2026-08-19, explicit request): color follows the same red/orange(brand)/
// green scale used for the payroll run validation states elsewhere in this app -- red under 50%
// (needs real attention), brand orange in the middle (getting there), green once genuinely mostly
// filled in. Shared between the list (this file) and the Detail page's own summary card.
function completenessColor(percent) {
    if (percent >= 80) return '#198754';
    if (percent >= 50) return '#FF9900';
    return '#dc3545';
}
function completenessBarHtml(percent) {
    const p = Number(percent) || 0;
    const color = completenessColor(p);
    return `<div class="employee-completeness-bar d-flex align-items-center gap-2">
        <div class="progress flex-grow-1">
            <div class="progress-bar" role="progressbar" style="width:${p}%; background-color:${color};" aria-valuenow="${p}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <span class="small fw-semibold" style="color:${color}; min-width:2.5em;">${p}%</span>
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
    });
}
$(document).ready(function () {
    initEmployeeTable();
    if (typeof initDatepicker === 'function') {
        initDatepicker('#employee_filter_date_from');
        initDatepicker('#employee_filter_date_to');
    }
    if (typeof initSelect2 === 'function') {
        initSelect2('#employee_filter_role, #employee_filter_department, #employee_filter_team, #employee_filter_shift, #employee_filter_branch', { mode: 'ajax', allowClear: true });
    }
    updateClearEmployeeFilterVisibility();
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
    return {
        created_date_from: toIsoDateEmp($('#employee_filter_date_from').val()),
        created_date_to: toIsoDateEmp($('#employee_filter_date_to').val()),
        role_id: $('#employee_filter_role').val() || '',
        department_id: $('#employee_filter_department').val() || '',
        team_id: $('#employee_filter_team').val() || '',
        shift_id: $('#employee_filter_shift').val() || '',
        branch_id: $('#employee_filter_branch').val() || ''
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
    const hasFilter = !!(f.created_date_from || f.created_date_to || f.role_id || f.department_id || f.team_id || f.shift_id || f.branch_id);
    $('#btnClearEmployeeFilter').toggleClass('d-none', !hasFilter);
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
});
$(document).on('change', '#employee_filter_role, #employee_filter_department, #employee_filter_team, #employee_filter_shift, #employee_filter_branch', function () {
    updateClearEmployeeFilterVisibility();
    if (tb_employee) tb_employee.ajax.reload(null, true);
});
$(document).on('click', '#btnClearEmployeeFilter', function () {
    // Clear every control WITHOUT letting each one's own change handler fire its own
    // ajax.reload() -- 'change.select2' only refreshes the widget's display, and clearDates()'s
    // 'changeDate' event is left to fire on the date fields same as the Process page's own Clear
    // Filter (2 reloads there already, accepted) -- one explicit reload below covers the rest.
    $('#employee_filter_role, #employee_filter_department, #employee_filter_team, #employee_filter_shift, #employee_filter_branch').val(null).trigger('change.select2');
    $('#employee_filter_date_from, #employee_filter_date_to').datepicker('clearDates');
    updateClearEmployeeFilterVisibility();
    if (tb_employee) tb_employee.ajax.reload(null, true);
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
                    return `<span class="badge rounded-pill ${m.cls}"><i class="fa-solid ${m.icon} me-1"></i>${escapeHtmlList(label)}</span>`;
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
            {
                data: "completeness",
                orderable: false,
                responsivePriority: 5,
                render: function (data) {
                    return completenessBarHtml(data);
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
                        syncBtn = `<button class="btn btn-link py-1 text-info border-start sync-one-employee" data-id="${row.id}" data-i18n-tooltip="employee_sync_list_action_title"><i class="fa-solid fa-rotate"></i></button>`;
                    }
                    return `<div class="btn-group border rounded-3 bg-white">
                        <button class="btn btn-link text-warning manage-employee" data-id="${row.employee_no}" data-i18n-tooltip="edit"><i class="fa-solid fa-pen-to-square"></i></button>
                        ${syncBtn}
                        <button class="btn btn-link py-1 text-danger border-start delete-employee" data-id="${row.id}" data-i18n-tooltip="delete"><i class="fa-solid fa-trash-can"></i></button>
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
                let btn = `
                    <button class="btn btn-primary manage-employee ms-1" data-id="">
                        <i class="fa-solid fa-plus me-2"></i><span>${langData['employee'] || 'Employee'}</span>
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
    $('#btnClearLoginHistoryOverviewFilter').toggleClass('d-none', !hasFilter);
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
        ],
        language: getTableLang(),
    });
}
$(document).on('shown.bs.tab', '#employee-login-history-top-tab', function () {
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
