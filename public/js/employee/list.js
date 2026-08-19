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
        initSelect2('#employee_filter_role, #employee_filter_department, #employee_filter_shift, #employee_filter_branch', { mode: 'ajax', allowClear: true });
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
    const hasFilter = !!(f.created_date_from || f.created_date_to || f.role_id || f.department_id || f.shift_id || f.branch_id);
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
$(document).on('change', '#employee_filter_role, #employee_filter_department, #employee_filter_shift, #employee_filter_branch', function () {
    updateClearEmployeeFilterVisibility();
    if (tb_employee) tb_employee.ajax.reload(null, true);
});
$(document).on('click', '#btnClearEmployeeFilter', function () {
    // Clear every control WITHOUT letting each one's own change handler fire its own
    // ajax.reload() -- 'change.select2' only refreshes the widget's display, and clearDates()'s
    // 'changeDate' event is left to fire on the date fields same as the Process page's own Clear
    // Filter (2 reloads there already, accepted) -- one explicit reload below covers the rest.
    $('#employee_filter_role, #employee_filter_department, #employee_filter_shift, #employee_filter_branch').val(null).trigger('change.select2');
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
        responsive: true,
        order: [[1, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/employee.list`,
            type: "POST",
            data: function (d) {
                const filters = currentStatusFilters();
                d.status = filters.status;
                d.employment_status = filters.employment_status;
                Object.assign(d, currentEmployeeExtraFilters());
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: function (data, type, row) {
                    const letter = (row.name || '').trim().charAt(0).toUpperCase() || '?';
                    return `<div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 38px; height: 38px; min-width: 38px; background-color: #007aff;">${letter}</div>`;
                }
            },
            { data: "employee_no" },
            { data: "name" },
            { data: "role" },
            { data: "department" },
            { data: "shift", render: d => d || '-' },
            { data: "branch" },
            { data: "start_work_date" },
            {
                data: "status",
                render: function (data) {
                    let badge = data === 'Active' ? 'bg-success' : 'bg-danger';
                    return `<span class="badge ${badge}">${data}</span>`;
                }
            },
            {
                data: "completeness",
                orderable: false,
                render: function (data) {
                    return completenessBarHtml(data);
                }
            },
            {
                data: null,
                orderable: false,
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
            let $input = $searchDiv.find('input').off('.employeeSearch');
            $input.on('keypress.employeeSearch', function (e) {
                if (e.keyCode === 13) {
                    self.search(this.value).draw();
                }
                if(e.value === "") {
                    self.search(this.value).draw();
                }
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
