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
        order: [[2, 'asc']],
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
            {
                data: null,
                orderable: false,
                className: 'text-center',
                responsivePriority: 8,
                render: function (data, type, row) {
                    const letter = (row.name || '').trim().charAt(0).toUpperCase() || '?';
                    return `<div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 38px; height: 38px; min-width: 38px; background-color: #007aff;">${letter}</div>`;
                }
            },
            { data: "employee_no", responsivePriority: 2 },
            { data: "name", responsivePriority: 1 },
            { data: "phone", render: d => d || '-', responsivePriority: 9 },
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
                    return `<div class="btn-group border rounded-3 bg-white">
                        <button class="btn btn-link text-warning manage-employee" data-id="${row.employee_no}" data-i18n-tooltip="edit"><i class="fa-solid fa-pen-to-square"></i></button>
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
            if ($searchDiv.find('#btnOpenEmployeeSync').length === 0) {
                let syncBtn = `
                    <button class="btn btn-outline-secondary ms-1" id="btnOpenEmployeeSync" type="button">
                        <i class="fa-solid fa-rotate me-2"></i><span data-i18n="employee_sync_button">Sync from Origami</span>
                    </button>
                `;
                $searchDiv.append(syncBtn);
                if (typeof updateText === 'function') updateText($searchDiv[0]);
            }
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
                columns: [
                    { index: 2, key: 'employee_no' },
                    { index: 3, key: 'name' },
                    { index: 4, key: 'phone' },
                    { index: 5, key: 'role' },
                    { index: 6, key: 'position' },
                    { index: 7, key: 'department' },
                    { index: 8, key: 'team' },
                    { index: 9, key: 'shift' },
                    { index: 10, key: 'branch' },
                    { index: 11, key: 'start_work_date' },
                    { index: 12, key: 'status' },
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
        }
    });
}
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
