$(document).ready(function () {
    initEmployeeTable();
});
let tb_employee;
function initEmployeeTable() {
    if ($.fn.DataTable.isDataTable('#tb_employee')) {
        $('#tb_employee').DataTable().ajax.reload(null, false);
        return;
    }
    tb_employee = $('#tb_employee').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[6, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/employee.list`,
            type: "POST",
            data: function (d) {
                d.status = $('#filter_status').val();
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: function (data, type, row) {
                    return `<div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 38px; height: 38px; min-width: 38px; background-color: #007aff;">A</div>`;
                }
            },
            { data: "employee_no" },
            { data: "name" },
            { data: "role" },
            { data: "department" },
            { data: "shift" },
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
                        <button class="btn btn-link text-warning manage-employee" data-id="${row.employee_no}"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button class="btn btn-link py-1 text-danger border-start delete-employee" data-id="${row.id}"><i class="fa-solid fa-trash-can"></i></button> 
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