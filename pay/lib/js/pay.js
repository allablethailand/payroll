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
    var start = moment().subtract(29, 'days');
    var end = moment();
	var quarter = moment().quarter();
    $('#filter_date').daterangepicker({
		startDate: start,
        endDate: end,	
		showDropdowns: true,
		autoUpdateInput: false,
		opens: 'right',
		locale: {
			cancelLabel: 'Show all',
			applyLabel: 'Ok',
			format: 'DD/MM/YYYY',
		},
        ranges: {
            'Today': [moment()],
            'This week': [moment().startOf('week'), moment().endOf('week')],
			'This month': [moment().startOf('month'), moment().endOf('month')],
			'This quarter': [moment().quarter(quarter).startOf('quarter'), moment().quarter(quarter).endOf('quarter')],
			'This year': [moment().startOf('year'), moment().endOf('year')],
			'Last week': [moment().subtract(1, 'week').startOf('week'), moment().subtract(1, 'week').endOf('week')],
			'Last month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
			'Last quarter': [moment().subtract(1, 'quarter').startOf('quarter'), moment().subtract(1, 'quarter').endOf('quarter')],
			'Last year': [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')],
			'30 days ago': [moment().subtract(29, 'days'), moment()],
			'60 days ago': [moment().subtract(59, 'days'), moment()],
			'90 days ago': [moment().subtract(89, 'days'), moment()],
			'120 days ago': [moment().subtract(119, 'days'), moment()],
		}
	}, cb);
    cb(start.format('DD/MM/YYYY'), end.format('DD/MM/YYYY'));
	$('#filter_date').on('hide.daterangepicker hideCalendar.daterangepicker ', function(ev, picker) {
		var st = picker.startDate.format('DD/MM/YYYY');
        var ed = picker.endDate.format('DD/MM/YYYY');
        if(st == ed) {
            var dt = st;
        } else {
            var dt = picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY');
        }
		$(this).val(dt);
    });
	$('#filter_date').on('cancel.daterangepicker', function(ev, picker) {
		$(this).val('');
        buildPay();
	});
	$('#filter_date').on('apply.daterangepicker', function(ev, picker) {
		var st = picker.startDate.format('DD/MM/YYYY');
        var ed = picker.endDate.format('DD/MM/YYYY');
        if(st == ed) {
            var dt = st;
        } else {
            var dt = picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY');
        }
		$(this).val(dt);
        buildPay();
	});
    $(".toggleFilter").click(function(){
		$(".filter").toggleClass("active");
		if($(".filter").hasClass("active")) {
			$(".toggleFilter").html('<i class="fas fa-times"></i>');
		} else {
			$(".toggleFilter").html('<i class="fas fa-sliders-h"></i>');
		}
	});
    buildDepartment();
    buildEmployee();
	$(".get-payroll").click(function() {
		var data = $(this).attr("pages");
		$(".get-payroll").removeClass("active");
		$(this).addClass("active");
		$("#pages").val(data);
		if(data == 'approve') {
			$(".ap-button").removeClass("hidden");
			$(".checkbox-ap").removeClass("hidden");
			$('#filter_date').val("");
			buildPay();
		} else {
			$(".ap-button").addClass("hidden");
			$(".checkbox-ap").addClass("hidden");
			cb(start.format('DD/MM/YYYY'), end.format('DD/MM/YYYY'));
			buildPay();
		}
		buildStatus(data);
	});
	$(".migration_all").click(function() {
		if($(this).is(":checked")) {
			$(".payroll_id").prop('checked', true);
        } else {
            $(".payroll_id").prop('checked', false);
        }
	});
	buildStatus('payroll');
	buildPay();
});
function buildStatus(pages) {
	switch(pages) {
		case 'payroll':
			var ap_template = `
				<div class="rows">
					<div class="columns">
						<div class="cards el-payroll el-payroll-all" el="all">
							<span lang="en">All</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-0 active" el="0">
							<span lang="en">New</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-1" el="1">
							<span lang="en">Send to approve</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-2" el="2">
							<span lang="en">In Process</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-3" el="3">
							<span lang="en">Approve</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-6" el="6">
							<span lang="en">Paid</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-5 last-item" el="5">
							<div class="inner">
								<span lang="en">Need Infomation</span>
							</div>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-4 last-item" el="4" style="margin-left:0;">
							<div class="inner">
								<span lang="en">Not Approve</span>
							</div>
						</div>
					</div>
				</div>	
			`;
		break;
		case 'approve':
			var ap_template = `
				<div class="rows">
					<div class="columns">
						<div class="cards el-payroll el-payroll-W active" el="W">
							<span lang="en">Wait</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-I" el="I">
							<span lang="en">Need Information</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-Y" el="Y">
							<span lang="en">Approve</span>
						</div>
					</div>
					<div class="columns">
						<div class="cards el-payroll el-payroll-N last-item" el="N">
							<div class="inner">
								<span lang="en">Not Approve</span>
							</div>
						</div>
					</div>
				</div>
			`;
		break;
	}
	$(".tab_status").html(ap_template);
	$(".el-payroll").click(function() {
        var el = $(this).attr("el");
        $(".el-payroll").removeClass("active");
        $(".el-payroll-"+el).addClass("active");
        $("#filter_status").val(el);
        buildPay();
    });
}
function buildDepartment(){
    $("#filter_department").val("");
	$("#filter_department").select2({
		theme: "bootstrap",
		placeholder: "All department",
		minimumInputLength: -1,
		allowClear: true,
		ajax: {
			url: "/payroll/pay/actions/pay.php",
			dataType: 'json',
			delay: 250,
			cache: false,
			data: function(params) {
				return {
					term: params.term,
					page: params.page || 1,
					action: 'buildDepartment'
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
		},
	});
}
function buildEmployee(){
    $("#filter_employee").val("");
	$("#filter_employee").select2({
		theme: "bootstrap",
		placeholder: "All employee",
		minimumInputLength: -1,
		allowClear: true,
		ajax: {
			url: "/payroll/pay/actions/pay.php",
			dataType: 'json',
			delay: 250,
			cache: false,
			data: function(params) {
				return {
					term: params.term,
					page: params.page || 1,
					action: 'buildEmployee',
                    filter_department: $("#filter_department").val()
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
		},
	});
}
function cb(start, end) {
	var st = start;
	var ed = end;
	if(st == ed) {
		var dt = st;
	} else {
		var dt = start + ' - ' + end;
	}
	$('#filter_date').val(dt);
};
var tb_pay;
function buildPay() {
    var obj = 0;
	$('.filter-object').each(function(){
		if($(this).val()) {
			++obj;
		}
	});
	$(".countFilter").html(obj);
	if(obj > 0) {
		$(".countFilter").addClass("active");
	} else {
		$(".countFilter").removeClass("active");
	}
    if ($.fn.DataTable.isDataTable('#tb_pay')) {
        $('#tb_pay').DataTable().ajax.reload(null, false);
    } else {
		tb_pay = $('#tb_pay').DataTable({
            "processing": true,
        	"serverSide": true,
			"lengthMenu": [[50,100,250,500,1000,-1], [50,100,250,500,1000,"All"]],
			"ajax": {
				url: "/payroll/pay/actions/pay.php",
				"type": "POST",
				"data": function (data) {
                    data.action = "buildPay";
                    data.filter_date = $("#filter_date").val();
                    data.filter_department = $("#filter_department").val();
                    data.filter_employee = $("#filter_employee").val();
                    data.pages = $("#pages").val();
                    data.filter_status = $("#filter_status").val();
				}
			},
			"language": default_language,
			"responsive": true,
			"searchDelay": 1000,
			"deferRender": false,
			"drawCallback": function( settings ) {
				var lang = new Lang();
				lang.dynamic('th', '/js/langpack/th.json?v='+Date.now());
				lang.init({
					defaultLang: 'en'
				});
			},
			"order": [[1,'desc']],
			"columns": [{ 
                "targets": 0,
				"data": "form_id",
				"orderable": false,
				"render": function (data,type,row,meta) {
					var pages_action = row["pages_action"];
					var html = ``;
					if(pages_action == 'approve') {
						var ap_permission_flag = row["ap_permission_flag"];
						var ap_permission_seq = row["ap_permission_seq"];
						var ap_permission_status = row["ap_permission_status"];
						if(ap_permission_flag && ap_permission_seq && ap_permission_status) {
							var permission_flag = ap_permission_flag.split(",");
							var payroll_status = row['payroll_status'];
							if(payroll_status != 6) {
								if(jQuery.inArray("W",permission_flag) == '0') {
									html += `
										<div class="checkbox checkbox-warning">
											<input class="styled payroll_id" id="payroll_id${data}" name="payroll_id[]" type="checkbox" value="${data}">
											<label for="payroll_id${data}"></label>
										</div>
									`;
								}
							}
						}
					}
					return html;
				}
            },{ 
                "targets": 1,
				"className": "dt-click",
				"data": "pay_round"
            },{ 
                "targets": 2,
				"className": "dt-click",
				"data": "paid_day"
            },{ 
                "targets": 3,
				"className": "dt-click",
				"data": "department_data",
                "render": function ( data, type, row, meta ) {
					var mockup = ``;
					var i = 0;
					while(i < data.length) {
						var dept_color = data[i]['dept_color'];
						if(dept_color == 'FFFFFF') {
							dept_color = 'FF9900';
						}
						mockup += `
							<span class="label" style="background-color:#${dept_color}">${data[i]['dept_description']}</span> 
						`;
						++i;
					}
                    return mockup;
                }
            },{ 
                "targets": 4,
				"data": "emp_count",
				"className": "text-right dt-click"
            },{ 
                "targets": 5,
				"className": "dt-click",
				"data": "date_modify"
            },{ 
                "targets": 6,
				"data": "emp_pic",
				"className": "text-center dt-click",
                "render": function ( data, type, row, meta ) {
                    return `
						<div class="avatar">
                            <img src="${data}" onerror="this.src='/images/default.png'">
                        </div>
                    `;
                }
            },{ 
                "targets": 7,
				"data": "payroll_status",
				"className": "dt-click",
                "render": function ( data, type, row, meta ) {
					switch(data) {
						case '0':
							var class_0 = 'text-green';
							var class_1 = 'text-grey';
							var class_2 = 'text-grey';
							var class_3 = 'text-grey';
							var class_4 = 'text-grey';
							var class_3_txt = 'Approve';
						break;
						case '1':
							var class_0 = 'text-green';
							var class_1 = 'text-green';
							var class_2 = 'text-grey';
							var class_3 = 'text-grey';
							var class_4 = 'text-grey';
							var class_3_txt = 'Approve';
						break;
						case '2':
							var class_0 = 'text-green';
							var class_1 = 'text-green';
							var class_2 = 'text-orange';
							var class_3 = 'text-grey';
							var class_4 = 'text-grey';
							var class_3_txt = 'Approve';
						break;
						case '3':
							var class_0 = 'text-green';
							var class_1 = 'text-green';
							var class_2 = 'text-green';
							var class_3 = 'text-green';
							var class_4 = 'text-grey';
							var class_3_txt = 'Approved';
						break;
						case '4':
							var class_0 = 'text-green';
							var class_1 = 'text-green';
							var class_2 = 'text-orange';
							var class_3 = 'text-danger';
							var class_4 = 'text-grey';
							var class_3_txt = 'Not approve';
						break;
						case '5':
							var class_0 = 'text-green';
							var class_1 = 'text-green';
							var class_2 = 'text-green';
							var class_3 = 'text-orange';
							var class_4 = 'text-grey';
							var class_3_txt = 'Need information';
						break;
						case '6':
							var class_0 = 'text-green';
							var class_1 = 'text-green';
							var class_2 = 'text-green';
							var class_3 = 'text-green';
							var class_4 = 'text-green';
							var class_3_txt = 'Approved';
						break;
					}
                    return `
						<div class="nowarp">
							<ol class="progress-step">
								<li class="${class_0}" lang="en">New</li>
								<li class="${class_1}" lang="en">Send to approve</li>
								<li class="${class_2}" lang="en">Approve process</li>
								<li class="${class_3} class_3" lang="en">${class_3_txt}</li>
								<li class="${class_4}" lang="en">Paid</li>
							</ol>
						</div>
                    `;
                }
            },{ 
                "targets": 8,
				"data": "form_id",
                "render": function ( data, type, row, meta ) {
					var payroll_status = row["payroll_status"];
					var pages_action = row["pages_action"];
					if(pages_action == 'approve') {
						var html = `
							<div class="nowarp">
						`;
						var ap_permission_flag = row["ap_permission_flag"];
						var ap_permission_seq = row["ap_permission_seq"];
						var ap_permission_status = row["ap_permission_status"];
						var ap_approve_remark = row["ap_approve_remark"];
						var ap_permission_date = row["ap_permission_date"];
						if(ap_permission_flag && ap_permission_seq && ap_permission_status) {
							var permission_flag = ap_permission_flag.split(",");
							var permission_seq = ap_permission_seq.split(",");
							var permission_status = ap_permission_status.split(",");
							var permission_date = (ap_permission_date) ? ap_permission_date.split(",") : [];
							var approve_remark = (ap_approve_remark) ? ap_approve_remark.split(",") : [];
							var ap_status = permission_status[0];
							var ap_date = permission_date[0];
							var ap_remark = approve_remark[0];
							var payroll_status = row['payroll_status'];
							if(payroll_status != 6) {
								if(jQuery.inArray("W",permission_flag) == '0') {
									switch(ap_status) {
										case 'W':
											html += `
												<button type="button" class="btn btn-circle btn-green" onclick="approveData(${data},'Y');"><i class="fas fa-check"></i></button> 
												<button type="button" class="btn btn-circle btn-orange" onclick="approveData(${data},'I');"><i class="fas fa-info"></i></button> 
												<button type="button" class="btn btn-circle btn-red" onclick="approveData(${data},'N');"><i class="fas fa-times"></i></button> 
											`;
										break;
										case 'Y':
											html += `
												<button type="button" class="btn btn-circle btn-orange" onclick="approveData(${data},'I');"><i class="fas fa-info"></i></button> 
												<button type="button" class="btn btn-circle btn-red" onclick="approveData(${data},'N');"><i class="fas fa-times"></i></button>
												<p class="text-success" style="margin-top:10px; margin-bottom:0;"><i class="fas fa-check"></i> <span lang="en">Approve</span></p>
											`;
										break;
										case 'N':
											html += `
												<button type="button" class="btn btn-circle btn-green" onclick="approveData(${data},'Y');"><i class="fas fa-check"></i></button> 
												<button type="button" class="btn btn-circle btn-orange" onclick="approveData(${data},'I');"><i class="fas fa-info"></i></button> 
												<p class="text-danger" style="margin-top:10px; margin-bottom:0;"><i class="fas fa-times"></i> <span lang="en">Not approve</span></p>
											`;
										break;
										case 'I':
											html += `
												<button type="button" class="btn btn-circle btn-green" onclick="approveData(${data},'Y');"><i class="fas fa-check"></i></button>  
												<button type="button" class="btn btn-circle btn-red" onclick="approveData(${data},'N');"><i class="fas fa-times"></i></button>
												<p class="text-orange" style="margin-top:10px; margin-bottom:0;"><i class="fas fa-info"></i> <span lang="en">Need information</span></p>
											`;
										break;
									}
								}
							}
						}
						html += `
							</div>
						`;
						return html;
					} else {
						return `
							<div class="nowarp">
								${(payroll_status == 0) ? `
									<button class="btn btn-circle btn-orange" onclick="sendToApprove(${data});"><i class="fas fa-share-square"></i></button> 
									<button class="btn btn-circle btn-white" onclick="managePayroll(${data});"><i class="fas fa-pencil-alt"></i></button> 
								` : ``}
								${(payroll_status == 3) ? `
									<button class="btn btn-circle btn-green" onclick="paidData(${data});"><i class="fas fa-hand-holding-usd"></i></button> 
								` : ``}
								<button class="btn btn-circle btn-danger" onclick="delPayroll(${data});"><i class="fas fa-trash-alt"></i></button> 
							</div>
						`;
					}
                }
            }]
        });
        $('div#tb_pay_filter.dataTables_filter label input').remove();
        $('div#tb_pay_filter.dataTables_filter label span').remove();
        var template = `
            <input type="search" class="form-control input-sm search-datatable" placeholder="" autocomplete="off" style="margin-bottom:0px !important;"> 
            <button type="button" class="btn btn-white" onclick="managePayroll('');"><i class="fas fa-plus"></i> <span lang="en">Payroll</span></button>
        `;
        $('div#tb_pay_filter.dataTables_filter input').hide();
        $('div#tb_pay_filter.dataTables_filter label').append(template);
        var searchDataTable = $.fn.dataTable.util.throttle(function (val) {
            if(typeof val != 'undefined'){
                tb_pay.search(val).draw();	
            } 
        },1000);
        $('.search-datatable').on('keyup',function(e){
            if(e.keyCode === 13){
                $('.dataTables_processing.panel').css('top','5%');
                val = e.target.value.trim().replace(/ /g, "");
                searchDataTable(val);
            }
            if(e.target.value == ''){
                tb_pay.search('').draw();
                buildPay();
            }
        });
		$('#tb_pay tbody').on('click', 'tr td.dt-click', function () {
			var row = tb_pay.row(this).data();
			managePayroll(row['form_id'],event);
		});
    }
}
function sendToApprove(payroll_id) {
    event.stopPropagation();
    swal({
        html:true,
        title: window.lang.translate("Are you sure?"),
        text: 'Do you want to send data to approve process? </br> This process cannot be undone.',
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
			$.ajax({
				url: "/payroll/pay/actions/pay.php",
				type: "POST",
				data: {
					action:'sendToApprove',
					payroll_id: payroll_id
				},
				dataType: "JSON",
				type: 'POST',
				success: function(result){
					swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 1500});
					buildPay();
				}
			});
		}
	});
}
function paidData(payroll_id) {
    event.stopPropagation();
    swal({
        html:true,
        title: window.lang.translate("Are you sure?"),
        text: 'Do you want to change status to paid? </br> This process cannot be undone.',
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
			$.ajax({
				url: "/payroll/pay/actions/pay.php",
				type: "POST",
				data: {
					action:'paidData',
					payroll_id: payroll_id
				},
				dataType: "JSON",
				type: 'POST',
				success: function(result){
					swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 1500});
					buildPay();
				}
			});
		}
	});
}
function delPayroll(payroll_id) {
	event.stopPropagation();
    swal({
        html:true,
        title: window.lang.translate("Are you sure?"),
        text: 'Do you want to remove these records? </br> This process cannot be undone.',
        type: "error",
        showCancelButton: true,
        closeOnConfirm: false,
        confirmButtonText: window.lang.translate("Remove"),
        cancelButtonText: window.lang.translate("Cancel"),	
        confirmButtonColor: '#FF6666',
        cancelButtonColor: '#CCCCCC',
        showLoaderOnConfirm: true,
    },
    function(isConfirm){
        if (isConfirm) {
			$.ajax({
				url: "/payroll/pay/actions/pay.php",
				type: "POST",
				data: {
					action:'delPayroll',
					payroll_id: payroll_id
				},
				dataType: "JSON",
				type: 'POST',
				success: function(result){
					swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 1500});
					buildPay();
				}
			});
		}
	});
}
function managePayroll(payroll_id) {
	$.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'getApprovalStep',
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
			if(result.status == true) {
				$.redirect("/payroll/pay/detail", {
					payroll_id: payroll_id,
				}, 'post', '_blank');
			} else {
				$("#payrollModal").modal();
				var footer = `
					<a class="btn btn-orange" href="/approval.php" lang="en">Set approval</a> 
					<button type="button" class="btn btn-white" lang="en" data-dismiss="modal">Cancel</button> 
				`;
				$("#payrollModal .modal-body").html(empty_approval);
				$("#payrollModal .modal-footer").html(footer);
			}
		}
	});
}
var empty_approval = `
	<div class="row">
        <div class="col-sm-4">
            <img src="/images/origami-logo-robot.png" class="img-responsive">
        </div>
        <div class="col-sm-8">
            <h2 class="text-bold text-danger"><span lang="en">Warning!</span></h2>
            <p><b><span lang="en">You have not defined authorization information.</span></b> <span lang="en">Please set approval before making a transaction.</span><br> <span lang="en">You can make settings in the menu.</span> <b>Approval -> Reassignment</b></p>
        </div>
    </div>
`;
$(window).on('storage', function(e) {
	if (e.originalEvent.key === 'reloadPayroll') {
		buildPay();
	}
});
function approveGroup(option) {
	var ids = [];
	$('.payroll_id:checked').each(function() {
		ids.push(this.value); 
	});
	if(ids.length == 0) {
		swal({type: 'error',title: "Warning!",text: "Please select at least one item.",confirmButtonColor: "#fb9678"})
	} else {
		event.stopPropagation();
		var swl_type = '';
		var swl_btn = '';
		var swl_txt = '';
		var swl_title = '';
		switch(option){
			case 'Y':
				swl_type += 'success';
				swl_btn += '#00C292';
				swl_txt += 'Approve';
				swl_title += 'Comment for approve.';
			break;
			case 'N':
				swl_type += 'error';
				swl_btn += '#fb9678';
				swl_txt += 'Not approve';
				swl_title += 'Comment for not approve.';
			break;
			case 'I':
				swl_type += 'warning';
				swl_btn += '#FBC02D';
				swl_txt += 'Need more information';
				swl_title += 'Comment for need more information.';
			break;
		}
		var swl_box = `<textarea class="form-control approve_remark" placeholder="${swl_title}"></textarea>`;
		swal({
			html:true,
			title: window.lang.translate(swl_txt),
			text: swl_box,
			type: swl_type,
			showCancelButton: true,
			closeOnConfirm: false,
			confirmButtonText: window.lang.translate("OK"),
			cancelButtonText: window.lang.translate("Cancel"),	
			confirmButtonColor: swl_btn,
			cancelButtonColor: '#CCCCCC',
			showLoaderOnConfirm: true,
		},
		function(isConfirm){
			if (isConfirm) {
				var approve_remark = $(".approve_remark").val();
				$.ajax({
					url: "/payroll/pay/actions/pay.php",
					type:"POST",
					data: {
						action : 'approveData',
						payroll_id: ids,
						option: option,
						approve_remark: approve_remark
					},
					success: function(result){
						swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 1500});
						buildPay();	
					}
				});
			} else {
				swal.close();
			}
		}); 
	}
}
function approveData(migration_id,option) {
	event.stopPropagation();
	var swl_type = '';
	var swl_btn = '';
	var swl_txt = '';
	var swl_title = '';
	switch(option){
		case 'Y':
			swl_type += 'success';
			swl_btn += '#00C292';
			swl_txt += 'Approve';
			swl_title += 'Comment for approve.';
		break;
		case 'N':
			swl_type += 'error';
			swl_btn += '#fb9678';
			swl_txt += 'Not approve';
			swl_title += 'Comment for not approve.';
		break;
		case 'I':
			swl_type += 'warning';
			swl_btn += '#FBC02D';
			swl_txt += 'Need more information';
			swl_title += 'Comment for need more information.';
		break;
	}
	var swl_box = `<textarea class="form-control approve_remark" placeholder="${swl_title}"></textarea>`;
	swal({
		html:true,
		title: window.lang.translate(swl_txt),
		text: swl_box,
		type: swl_type,
		showCancelButton: true,
		closeOnConfirm: false,
		confirmButtonText: window.lang.translate("OK"),
		cancelButtonText: window.lang.translate("Cancel"),	
		confirmButtonColor: swl_btn,
		cancelButtonColor: '#CCCCCC',
		showLoaderOnConfirm: true,
	},
	function(isConfirm){
		if (isConfirm) {
			var approve_remark = $(".approve_remark").val();
			$.ajax({
				url: "/payroll/pay/actions/pay.php",
				type:"POST",
				data: {
					action : 'approveData',
					payroll_id: migration_id,
					option: option,
					approve_remark: approve_remark
				},
				success: function(result){
					swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 1500});
					buildPay();	
				}
			});
		} else {
			swal.close();
		}
	}); 
}