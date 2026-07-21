$(document).ready(function () {
    initEmployeeTable();
});
let tb_employee;
function currentStatusFilters() {
    const $active = $('.employee-status-tab.active');
    return {
        status: $active.data('filter-status') || '',
        employment_status: $active.data('filter-employment-status') || ''
    };
}
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
            { data: "shift", defaultContent: "-" },
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
