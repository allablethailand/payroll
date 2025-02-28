var default_language = {
    "decimal": "",
    "emptyTable": "<span lang='en'>No data available in table</span>",
    "info": "<span lang='en'>Showing</span> _START_ <span lang='en'>to</span> _END_ <span lang='en'>of</span> _TOTAL_ <span lang='en'>entries</span>",
    "infoEmpty": "<span lang='en'><span lang='en'>Showing</span> 0 <span lang='en'>to</span> 0 <span lang='en'>of</span> 0 <span lang='en'>entries</span>",
    "infoFiltered": "(<span lang='en'>filtered from</span> _MAX_ <span lang='en'>total entries</span>)",
    "infoPostFix": "",
    "thousands": ",",
    "lengthMenu": "<span lang='en'>Show</span> _MENU_ <span lang='en'>entries</span>",
    "loadingRecords": "<span lang='en'>Loading...</span>",
    "processing": "<span lang='en'>Processing...</span>",
    "search": "",
    "zeroRecords": "<span lang='en'>No matching records found</span>",
    "paginate": {
        "first": "<span lang='en'>First</span>",
        "last": "<span lang='en'>Last</span>",
        "next": "<span lang='en'>Next</span>",
        "previous": "<span lang='en'>Previous</span>"
    },
    "aria": {
        "sortAscending": ": <span lang='en'>activate to sort column ascending</span>",
        "sortDescending": ": <span lang='en'>activate to sort column descending</span>"
    }
};
$(document).ready(function() {
    buildEmployee(); 
});
var tb_employee;
function buildEmployee() {
    if ($.fn.DataTable.isDataTable('#tb_employee')) {
        $('#tb_employee').DataTable().ajax.reload(null, false);
    } else {
		tb_employee = $('#tb_employee').DataTable({
			"processing": true,
			"serverSide": true,
			"lengthMenu": [[50,100,250,500,1000,-1], [50,100,250,500,1000,"All"]],
			"ajax": {
				"url": "/payroll/employee/actions/employee.php",
				"type": "POST",
				"data": function (data) {
					data.action = "buildEmployee";
				}
			},
			"language": default_language,
			"responsive": true,
			"searchDelay": 1000,
			"deferRender": false,
			"drawCallback": function(settings) {
				var lang = new Lang();
				lang.dynamic('th', '../js/langpack/th.json?v='+Date.now());
				lang.init({
					defaultLang: 'en'
				});
			},
			"order": [[2,'asc']],
			"columns": [{ 
				"targets": 0,
                "data": "emp_key_name",
                "visible": false
			},{ 
				"targets": 1,
                "data": "emp_pic",
                "orderable": false,
                "className": "dt-click",
                "render": function (data,type,row,meta) {
					return `
                        <div class="avatar">
							<img src="${data}" onerror="this.src='/images/default.png'">
						</div>
                    `;
				}
			},{ 
				"targets": 2,
                "className": "dt-click",
                "data": "emp_code"
			},{ 
				"targets": 4,
                "className": "dt-click",
                "data": "emp_name"
			},{ 
				"targets": 5,
                "className": "dt-click",
                "data": "acrp_name"
			},{ 
				"targets": 5,
                "className": "dt-click",
                "data": "emp_type_name"
			},{ 
				"targets": 6,
                "className": "dt-click",
                "data": "dept_description"
			},{ 
				"targets": 7,
                "className": "dt-click",
                "data": "posi_description"
			},{ 
				"targets": 8,
                "className": "dt-click",
                "data": "status_name"
			},{ 
				"targets": 9,
                "className": "dt-click",
                "data": "emp_start_date"
			},{ 
				"targets": 10,
                "className": "dt-click",
				"render": function (data,type,row,meta) {
					return ``;
				}
			},{ 
				"targets": 11,
				"data": "emp_id",
                "orderable": false,
                "render": function (data,type,row,meta) {
					return `
                        <button type="button" class="btn btn-circle btn-white" onclick="editMember(${data})"><i class="fas fa-users-cog"></i></button>
                    `;
				}
			}],
		});
		$('#tb_employee tbody').on('click', 'tr td.dt-click', function () {
			var row = tb_employee.row(this).data();
			editMember(row['emp_id']);
		});
		$('div#tb_employee_filter.dataTables_filter label input').remove();
		$('div#tb_employee_filter.dataTables_filter label span').remove();
		var template = `<input type="search" class="form-control input-sm search-datatable" placeholder="" autocomplete="off" style="margin-bottom:0px !important;"> `;
		$('div#tb_employee_filter.dataTables_filter input').hide();
		$('div#tb_employee_filter.dataTables_filter label').append(template);
		var searchDataTable = $.fn.dataTable.util.throttle(
		function (val) {
			if(typeof val != 'undefined'){
				tb_employee.search( val ).draw();	
			} 
		},1000);
		$('.search-datatable').on('keyup',function(e){
			if(e.keyCode === 13){
				$('.dataTables_processing.panel').css('top','5%');
				val = e.target.value.trim().replace(/ /g, "");
				searchDataTable(val);
			}
			if(e.target.value == ''){
				tb_employee.search('').draw();
				buildEmployee();
			}
		});
    }
}
function editMember(emp_id) {
    $("#employeeModal").modal();
    $("#employeeModal .modal-footer").html(`
        <button type="button" class="btn btn-orange btn-save-member" lang="en">Save</button> 
        <button type="button" class="btn btn-white" lang="en" onclick="closeModal('employeeModal');">Cancel</button> 
    `);
    $.ajax({
        url: "/payroll/employee/actions/employee.php",
        type: "POST",
        data: {
            action:'memberData',
            emp_id: emp_id
        },
        dataType: "JSON",
        type: 'POST',
        success: function(result){
            var emp_data = result.emp_data;
            $("#employeeModal .modal-header .modal-title").html(`
                ${(emp_data.emp_code) ? `[${emp_data.emp_code}]` : ``}
                ${emp_data.emp_name} 
                ${(emp_data.nickname) ? `(${emp_data.nickname})` : ``}
            `);
        }
    });
    $("#employeeModal .modal-body").html(emp_form);
    $("#emp_id").val(emp_id);
    buildEmployeeTab('salary');
}
var emp_form = `
    <input type="hidden" id="emp_id">
    <div class="row-overflow">
        <a href=".employee_tab" class="active employee-tab employee-salary" data-toggle="tab" onclick="buildEmployeeTab('salary');">
            <i class="fas fa-hand-holding-usd"></i>
            <span lang="en">Saraly</span>
        </a>
        <a href=".employee_tab" class="employee-tab employee-tax" data-toggle="tab" onclick="buildEmployeeTab('tax');">
            <i class="fas fa-coins"></i>
            <span lang="en">Tax</span>
        </a>
        <a href=".employee_tab" class="employee-tab employee-social" data-toggle="tab" onclick="buildEmployeeTab('social');">
            <i class="fas fa-hospital"></i>
            <span lang="en">Social Security</span>
        </a>
        <a href=".employee_tab" class="employee-tab employee-allowance" data-toggle="tab" onclick="buildEmployeeTab('allowance');">
            <i class="fas fa-ellipsis-h"></i>
            <span lang="en">Allowance</span>
        </a>
    </div>
    <div class="tab-content">
        <div class="employee_tab tab-pane fade in active"></div>
    </div>
`;
function buildEmployeeTab(pages) {
    $(".employee-tab").removeClass("active");
    $(".employee-"+pages).addClass("active");
    switch(pages) {
        case 'salary':
            buildEmpSalary();
        break;
        case 'tax':
        break;
        case 'social':
        break;
        case 'allowance':
        break;
    }
}
function buildEmpSalary() {
    var emp_id = $("#emp_id").val();
    $.ajax({
        url: "/payroll/employee/actions/employee.php",
        type: "POST",
        data: {
            action:'buildEmpSalary',
            emp_id: emp_id
        },
        dataType: "JSON",
        type: 'POST',
        success: function(result){
            var emp_data = result.emp_data;
            if(emp_data && emp_data.pay_type) {
                var pay_type = emp_data.pay_type;
                var bank_id = emp_data.bank_id;
                var bank_name = emp_data.bank_name;
                var bank_no = (emp_data.bank_no) ? emp_data.bank_no : '';
                var salary_val = emp_data.salary_val;
                salary_val = (salary_val) ? addCommas(Number(salary_val).toFixed(2)) : 0;
                $(".employee_tab").html(`
                    <p class="text-bold" style="margin:10px auto;"><span lang="en">Payment Method</span> <code>*</code></p> 
                    <p>   
                        <div class="checkbox checkbox-warning">
                            <input class="styled payment_method" id="payment_method_1" name="payment_method" type="radio" value="1">
                            <label for="payment_method_1" lang="en">Cash</label>
                        </div>
                        <div class="checkbox checkbox-warning">
                            <input class="styled payment_method" id="payment_method_2" name="payment_method" type="radio" value="2">
                            <label for="payment_method_2" lang="en">Bank</label>
                        </div>
                    </p>
                    <div class="for_bank">
                        <div class="row">
                            <div class="col-sm-6">
                                <p class="text-bold" style="margin:10px auto;"><span lang="en">Bank Account</span> <code>*</code></p> 
                                <select class="form-control" id="bank_id"></select>
                            </div>
                            <div class="col-sm-6">
                                <p class="text-bold" style="margin:10px auto;"><span lang="en">Bank Account No</span> <code>*</code></p> 
                                <input type="text" class="form-control" id="bank_no" onclick="this.select();" value="${bank_no}" autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h5 class="text-orange text-bold">
                        <i class="fas fa-coins"></i> <span lang="en">Income</span>
                    </h5>
                    <table class="table">
                        <tr>
                            <td lang="en">Income</td>
                            <td lang="en">Value</td>
                            <td lang="en"></td>
                        </tr>
                    </table>
                    <div class="row">
                        <div class="col-sm-3">
                            <p class="text-bold" style="margin:10px auto;"><span lang="en">Salary</span> <code>*</code></p>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="emp_salary" onclick="this.select();" autocomplete="off" style="text-align:right;">
                        </div>
                    </div>
                `);
                $("#payment_method_"+pay_type).prop("checked",true);
                if(pay_type == 2) {
                    $(".for_bank").removeClass("hidden");
                } else {
                    $(".for_bank").addClass("hidden");
                }
                $(".payment_method").click(function() {
                    if($(this).val() == 2) {
                        $(".for_bank").removeClass("hidden");
                    } else {
                        $(".for_bank").addClass("hidden");
                    }
                });
                $('#bank_id').append($('<option>', {value: bank_id,text: bank_name}));
                buildBank();
            } else {
                $(".employee_tab").html(`
                    <div style="margin:35px auto;">
                        <p class="text-grey text-center"><i class="fas fa-comments-dollar fa-5x"></i></p>
                        <p class="text-grey text-center" lang="en">Payment method has not been set up.</p>
                        <p class="text-grey text-center" lang="en">Please set up the payment in the Employee Benefits Settings menu.</p>
                    </div>
                `);
                $(".btn-save-member").addClass("hidden");
            }
        }
    });
}
function buildBank() {
    $("#bank_id").select2({
		theme: "bootstrap",
		placeholder: "Choose bank",
		minimumInputLength: -1,
		allowClear: true,
		ajax: {
			url: "/payroll/employee/actions/employee.php",
			dataType: 'json',
			delay: 250,
			cache: false,
			data: function(params) {
				return {
					term: params.term,
					page: params.page || 1,
					action: 'buildBank',
				};
			},
			processResults: function(data, params) {
				var page = params.page || 1;
				return {
					results: $.map(data, function(item) {
						return {
							id: item.id,
							text: item.col,
							code: item.code,
							desc: item.desc,
						}
					}),
					pagination: {
						more: (page * 10) <= data[0].total_count
					}
				};
			},
		},
		templateSelection: function(data) {
			return data.text;
		}
	});
}
function closeModal(object) {
    event.stopPropagation();
    swal({
        html:true,
        title: window.lang.translate("Are you sure?"),
        text: 'Do you want cancel this action? Edited data will not be saved.',
        type: "warning",
        showCancelButton: true,
        closeOnConfirm: false,
        confirmButtonText: window.lang.translate("Yes"),
        cancelButtonText: window.lang.translate("Cancel"),	
        confirmButtonColor: '#FF9900',
        cancelButtonColor: '#CCCCCC',
        showLoaderOnConfirm: true,
    },
    function(isConfirm){
        if (isConfirm) {
            $("#"+object).modal("hide");
            swal.close();
        } else {
            swal.close();
        }
    });
}
function addCommas(nStr){
    nStr += '';
    x = nStr.split('.');
    x1 = x[0];
    x2 = x.length > 1 ? '.' + x[1] : '';
    var rgx = /(\d+)(\d{3})/;
    while (rgx.test(x1)) {
        x1 = x1.replace(rgx, '$1' + ',' + '$2');
    }
    return x1 + x2;
}