var pages = 'form';
$(document).ready(function() {
    $(".pay-navbar").click(function() {
        pages = $(this).attr("pages");
        $(".pay-navbar").removeClass("active");
        $(".pay-navbar."+pages).addClass("active");
        buildPage(pages);
        if(payroll_id) {
            $(".navAfterLogin").removeClass("hidden");
            buildTimeline();
        }
    });
    var payroll_id = $("#payroll_id").val();
    if(payroll_id) {
        $(".navAfterLogin").removeClass("hidden");
        buildTimeline();
    }
    $("#dept_all").click(function() {
        if($(this).is(":checked")) {
            $(".dept_id").prop("checked",true);
        } else {
            $(".dept_id").prop("checked",false);
        }
        buildEmployeeTable();
    });
    $("#income_all").click(function() {
        if($(this).is(":checked")) {
            $(".income_id").prop("checked",true);
        } else {
            $(".income_id").prop("checked",false);
        }
    });
    $("#deduction_all").click(function() {
        if($(this).is(":checked")) {
            $(".deduction_id").prop("checked",true);
        } else {
            $(".deduction_id").prop("checked",false);
        }
    });
    buildPage(pages);
});
var payroll_status = 0;
function buildTimeline() {
    $(".timeline").html(`
        <div id="timeline-wrap">
            <div id="timeline"></div>
            <div class="marker mfirst timeline-icon one icon-0">
                <i class="fa fa-pencil"></i>
            </div>
            <div class="marker m2 timeline-icon two icon-1">
                <i class="fas fa-share-square"></i>
            </div>
            <div class="marker m3 timeline-icon three icon-2">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="marker m4 timeline-icon four icon-3">
                <i class="fas fa-check-double"></i>
            </div>
            <div class="marker mlast timeline-icon five icon-4">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
        </div>
    `);
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'buildTimeline',
            payroll_id: $("#payroll_id").val()
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            payroll_status = (result.payroll_status)*1;
            var count_emp = result.count_emp;
            if(count_emp == 0) {
                $(".btn-send-approve").addClass("hidden");
                $(".btn-paid").addClass("hidden");
                $(".btn-excel").addClass("hidden");
            }
            if(payroll_status == 3 || payroll_status == 6) {
                $(".btn-send-approve").addClass("hidden");
                $(".btn-paid").addClass("hidden");
                $("input").attr("disabled",true);
                $("textarea").attr("disabled",true);
                $(".search-datatable").attr("disabled",false);
                $(".btn-save-form").css("display","none");
                $(".btn-join").css("display","none");
                $(".btn-del-emp").css("display","none");
            }
            if(payroll_status == 3) {
                $(".btn-paid").removeClass("hidden");
            }
            var i = 0;
            while(i <= payroll_status) {
                if(payroll_status == 3 || payroll_status == 4 || payroll_status == 5) {
                    $(".icon-0").addClass('active');
                    $(".icon-1").addClass('active');
                    switch(payroll_status) {
                        case 3:
                            $(".icon-3").html('<i class="fas fa-check"></i>');
                            $(".icon-3").addClass('active');
                            $(".icon-2").addClass('active');
                        break;
                        case 4:
                            $(".icon-3").html('<i class="fas fa-times"></i>');
                            $(".icon-3").addClass('active-danger');
                            $(".icon-2").addClass('active-orange');
                        break;
                        case 5:
                            $(".icon-3").html('<i class="fas fa-info"></i>');
                            $(".icon-3").addClass('active-orange');
                            $(".icon-2").addClass('active-orange');
                        break;
                        default:
                            $(".icon-2").addClass('active-orange');
                    }
                } else {
                    $(".icon-"+i).addClass("active");
                }
                ++i;
            }
        }
    });
}
function buildPage(pages) {
    switch(pages) {
        case 'form':
            $(".tab-content").html(form_template);
            buildDepartmentForm();
            buildIncome();
            buildDeduction();
            buildEmployeeTable();
            $(".date").datepicker({
                autoclose: true,
                todayHighlight: true,
                dateFormat: 'dd/mm/yy',
                changeYear: true,
                changeMonth: true
            });	
            buildFormFromID();
        break;
        case 'payroll':
            $(".tab-content").html(table_template);
            buildPayrollTable();
            var isDragging = false;
            var startX;
            var scrollLeft;
            $('.table-container').on('mousedown', function(e) {
                isDragging = true;
                startX = e.pageX - $('.table-scroll').offset().left;
                scrollLeft = $('.table-scroll').position().left;
                $(this).css('cursor', 'grabbing');
            });
            $(document).on('mouseup', function() {
                isDragging = false;
                $('.table-container').css('cursor', 'grab');
            });
            $('.table-container').on('mousemove', function(e) {
                if (!isDragging) return;
                e.preventDefault();
                var x = e.pageX - $('.table-scroll').offset().left;
                var walk = x - startX;
                $('.table-scroll').css('transform', 'translateX(' + (scrollLeft + walk) + 'px)');
            });
        break;
    }
}
var table_template = `
    <p class="text-orange"><i class="fas fa-lightbulb"></i> <span lang="en">Automatically save results after filling in data</span></p>
    <div class="table-container">
        <div class="table-scroll">
            <table class="table table-border table-slider" id="payroll_table">
                <thead>
                    <tr>
                        <th lang="en" class="text-right">No.</th>
                        <th lang="en">Employee</th>
                        <th lang="en" class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
`;
function buildPayrollTable() {
    $(".loader").addClass("active");
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'buildPayrollTable',
            payroll_id: $("#payroll_id").val()
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            $(".loader").removeClass("active");
            var income_data = result.income_data;
            var i = 0;
            while(i < income_data.length) {
                $("#payroll_table thead tr").append(`
                    <th class="text-green text-center">
                        ${income_data[i]['income_name_en']}
                        <div>(${income_data[i]['income_name_th']})</div>
                    </th>    
                `);
                ++i;
            }
            var deduction_data = result.deduction_data;
            var j = 0;
            while(j < deduction_data.length) {
                $("#payroll_table thead tr").append(`
                    <th class="text-danger text-center">
                        ${deduction_data[j]['deduction_name_en']}
                        <div>(${deduction_data[j]['deduction_name_th']})</div>
                    </th>    
                `);
                
                ++j;
            }
            var emp_data = result.emp_data;
            var i = 0;
            while(i < emp_data.length) {
                var data_income = emp_data[i]['data_income'];
                var data_deduction = emp_data[i]['data_deduction'];
                var data_money = emp_data[i]['data_money'];
                var amount = emp_data[i]['amount'];
                $("#payroll_table tbody").append(`
                    <tr class="row-${i}">
                        <td class="text-right">${i+1}</td>
                        <td>
                            <b>${emp_data[i]['emp_name']}</b>
                            <div class="text-grey" style="font-size:10px;"><span lang="en">Employee Code</span> ${emp_data[i]['emp_code']}</div>
                            <input type="hidden" id="emp_id${i}" value="${emp_data[i]['emp_id']}">
                        </td>
                        <td class="text-right">
                            <p class="amount${i}">${(amount) ? amount : '0.00'}</p>
                        </td>
                    </tr>
                `);
                var counter = 0;
                var i_income = 0;
                while(i_income < income_data.length) {
                    var val = addCommas(parseFloat(data_money[counter]).toFixed(2));
                    var input_default = income_data[i_income]['income_default'];
                    var textboxClass = (data_money[counter] > 0) ? '' : 'color:#e5e5e5;';
                    $("#payroll_table tbody tr.row-"+i).append(`
                        <td>
                            <input type="text" class="form-control money${i} numberInput" style="text-align:right; ${textboxClass}" onclick="this.select();" value="${val}" rows="${i}" money_type="income" money_id="${income_data[i_income]['income_id']}" input_default="${input_default}">
                            <input type="hidden" class="money_type${i}" value="income">
                        </td>
                    `);
                    ++i_income;
                    ++counter;
                }
                var i_deduction = 0;
                while(i_deduction < deduction_data.length) {
                    var val = addCommas(parseFloat(data_money[counter]).toFixed(2));
                    var input_default = 'N';
                    var textboxClass = (data_money[counter] > 0) ? '' : 'color:#e5e5e5;';
                    var deduction_flag =  deduction_data[i_deduction]['deduction_flag'];
                    var deduction_default =  deduction_data[i_deduction]['deduction_default'];
                    var s_class = '';
                    if(deduction_flag == 'S') {
                        s_class = 'moneyS'+i;
                    }
                    var is_tax = '';
                    if(deduction_default == 'Y') {
                        is_tax = 'tax'+i;
                    }
                    if(deduction_flag == "O") {
                        var deduction_module = deduction_data[i_deduction]['deduction_module'];
                        var textbox = `
                            <div class="input-group">
                                <input type="text" class="${s_class} ${is_tax} form-control money${i} numberInput" style="text-align:right; ${textboxClass} min-width:100px;" onclick="this.select();" value="${val}" rows="${i}" money_type="deduction" money_id="${deduction_data[i_deduction]['deduction_id']}" input_default="${input_default}">
                                <span class="input-group-addon" onclick="viewDeduction('${deduction_module}',${emp_data[i]['emp_id']})"><i class="fas fa-info"></i></span>
                            </div>
                        `;
                    } else {
                        var textbox = `
                            <input type="text" class="${s_class} ${is_tax} form-control money${i} numberInput" style="text-align:right; ${textboxClass}" onclick="this.select();" value="${val}" rows="${i}" money_type="deduction" money_id="${deduction_data[i_deduction]['deduction_id']}" input_default="${input_default}">
                        `;
                    }
                    $("#payroll_table tbody tr.row-"+i).append(`
                        <td>
                            ${textbox}
                            <input type="hidden" class="money_type${i}" value="deduction">
                        </td>
                    `);
                    ++i_deduction;
                    ++counter;
                }
                ++i;
            }
            $('.numberInput').on('input', function() {
                let value = $(this).val().replace(',','');
                value = value.replace(/[^0-9.]/g, '');
                if (value.split('.').length > 2) {
                    value = value.slice(0, -1);
                }
                $(this).val(value);
                if(value > 0) {
                    $(this).css("color","#555555");
                } else {
                    $(this).css("color","#e5e5e5");
                }
            });
            $('.numberInput').on('blur', function() {
                let value = $(this).val().replace(',','');
                if(value > 0) {
                    $(this).val(addCommas(parseFloat(value).toFixed(2)));
                    $(this).css("color","#555555");
                } else {
                    $(this).val("0.00");
                    $(this).css("color","#e5e5e5");
                }
                var rows = $(this).attr("rows");
                $(".show-detail").removeClass("active");
                $(".show-detail").html("");
                var input_default = $(this).attr("input_default");
                if(input_default == 'Y') {
                    var max = 15000;
                    var base = 0;
                    if(value >= max) {
                        base = max;
                    } else {
                        base = value;
                    }
                    var s_money = base*0.05;
                    s_money = addCommas(parseFloat(s_money).toFixed(2));
                    $(".moneyS"+rows).val(s_money);
                    $(".moneyS"+rows).css("color","#555555");
                    var tax = calcualteTax(value);
                    tax = addCommas(parseFloat(tax).toFixed(2));
                    $(".tax"+rows).val(tax);
                    $(".tax"+rows).css("color","#555555");
                }
                saveMoney(rows);
            });
            $('.numberInput').on('focus', function() {
                $(".show-detail").addClass("active");
                var rows = $(this).attr("rows");
                var emp_id = $("#emp_id"+rows).val();
                var money_type = $(this).attr("money_type");
                var money_id = $(this).attr("money_id");
                showDetailTextbox(emp_id,money_type,money_id);
            });
            if(payroll_status == 3 || payroll_status == 6) {
                $("input").attr("disabled",true);
            }
        }
    });
}
function viewDeduction(deduction_module,emp_ids) {
    var payroll_id = $("#payroll_id").val();
    $(".payrollModal").modal();
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'viewDeduction',
            emp_id: emp_ids,
            payroll_id: payroll_id,
            deduction_module: deduction_module
		},
        dataType: "JSON",
		type: 'POST',
		success: function(result){
            var emp_name = result.emp_name;
            $(".payrollModal .modal-title").html(emp_name);
            $(".payrollModal .modal-footer").html(`
                <button type="button" class="btn btn-white" data-dismiss="modal" lang="en">Close</button>    
            `);
            var round_date = result.round_date;
            var html = ``;
            switch(deduction_module) {
                case 'time':
                    html += `
                        <h5 class="text-center"><b lang="en">Time Stamp</b></h5>
                        <p class="text-center"><i class="far fa-calendar"></i> ${round_date}</p>
                    `;
                    var data = result.table_data;
                    var length = data.length;
                    html += `
                        <p style="font-size:12px;">
                            <i class="fas fa-square" style="color:rgba(255, 0, 0, 0.75);"></i> <span lang="en">Weekend</span>
                            &nbsp;&nbsp;
                            <i class="fas fa-square" style="color:#fb9678;"></i> <span lang="en">Holiday</span>
                            &nbsp;&nbsp;
                            <i class="fas fa-square" style="color:#EE546C;"></i> <span lang="en">Leave</span>
                            &nbsp;&nbsp;
                            <i class="fas fa-square" style="color:#CCCCCC;"></i> <span lang="en">No shift</span>
                        </p>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th lang="en">Stamp Date</th>
                                        <th lang="en" class="text-center">Emp.</th>
                                        <th lang="en" class="text-center">In</th>
                                        <th lang="en" class="text-center">Out</th>
                                        <th lang="en" class="text-center" style="width:12.5%;">Actual Hrs.</th>
                                        <th lang="en" class="text-center">Late (Min)</th>
                                        <th lang="en" class="text-center">Overtime (Min)</th>
                                        <th lang="en" class="text-center">Back Early (Min)</th>
                                        <th lang="en">Remark</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    var i = 0;
                    while(i < length){
                        var day = data[i]['day'];
                        var end_week = data[i]['end_week'];
                        var off_status = data[i]['off_status'];
                        var etc = (data[i]['etc']) ? '<div><span class="badge bg-holiday" style="text-align:left;">'+data[i]['etc']+'</span></div>' : '';
                        var owner_length = data[i]['owner_length'];
                        var ownerList = data[i]['ownerList'];
                        var j = 0;
                        while(j < owner_length){
                            var empPic = ownerList[j]["emp_pic"];
                            var emp_id = ownerList[j]["emp_id"];
                            var date_select = ownerList[j]["date_select"];
                            var time_count = ownerList[j]["time_count"];
                            var check_in = (ownerList[j]["check_in"]) ? ownerList[j]["check_in"] : '';
                            var check_out = (ownerList[j]["check_out"]) ? ownerList[j]["check_out"] : '';
                            var remark = ownerList[j]["remark"];
                            var leave = ownerList[j]["leave"];
                            var trClass = (end_week == 'Sun') ? 'bg-weekend' : (off_status == 'Y') ? 'bg-holiday' : (leave > 0) ? 'bg-leave' : (time_count == 0) ? 'bg-grey' : '';
                            var showDay = (end_week == 'Sun') ? '<span class="badge bg-weekend badge2">'+day+'</span>' : (off_status == 'Y') ? '<span class="badge bg-holiday badge2">'+day+'</span>' : day;
                            var attendance = (check_in && check_out) ? (trClass != "") ? '<span class="badge badge2 '+trClass+'">'+check_in+'-'+check_out+'</span>' : check_in+'-'+check_out : '<span class="badge badge2 '+trClass+'" lang="en">No shift</span>';
                            var weekendClass = (end_week == 'Sun') ? 'bg-weekend' : (off_status == 'Y') ? 'bg-holiday' : '';
                            var time_in = (ownerList[j]["time_in"]) ? (trClass != "") ? '<span class="badge badge2 '+trClass+'">'+ownerList[j]["time_in"]+'</span>' : ownerList[j]["time_in"] : '';
                            var time_out = (ownerList[j]["time_out"]) ? (trClass != "") ? '<span class="badge badge2 '+trClass+'">'+ownerList[j]["time_out"]+'</span>' : ownerList[j]["time_out"] : '';
                            var late_time = (ownerList[j]["late"]) ? (trClass != "") ? '<span class="badge badge2 '+trClass+'">'+ownerList[j]["late"]+'</span>' : '<span style="color:red;">'+ownerList[j]["late"]+'</span>' : '';
                            var early_time = (ownerList[j]["early"]) ? (trClass != "") ? '<span class="badge badge2 '+trClass+'">'+ownerList[j]["early"]+'</span>' : '<span style="color:red;">'+ownerList[j]["early"]+'</span>' : '';
                            var over_time = (ownerList[j]["over_time"]) ? (trClass != "") ? '<span class="badge badge2 '+trClass+'">'+ownerList[j]["over_time"]+'</span>' : '<span style="color:green;">'+ownerList[j]["over_time"]+'</span>' : '';
                            var balance = (ownerList[j]["balance"]) ? (trClass != "") ? '<span class="badge badge2 '+trClass+'">'+ownerList[j]["balance"]+' <a onclick="viewStampHistory('+date_select+','+emp_id+')"><i class="fas fa-info-circle"></i></a>'+'</span>' : ownerList[j]["balance"]+' <a onclick="viewStampHistory('+date_select+','+emp_id+')"><i class="fas fa-info-circle"></i></a>' : '';
                            html += `
                                    <tr>
                                        ${(j == 0) ? `
                                            <td rowspan="${owner_length}" class="${weekendClass}">${showDay}${etc}</td>
                                        ` : ``}
                                        <td class="text-center ${weekendClass} ${trClass}">
                                            <div class="avatar-sm">
                                                <img width="35" id="avatar_h" name="avatar_h" title="Administrator" src="/${empPic}" onerror="this.src='/images/default.png'">
                                            </div>
                                            <p class="font-12">${attendance}</p>
                                        </td>
                                        <td class="text-center ${trClass}">${time_in}</td>
                                        <td class="text-center ${trClass}">${time_out}</td>
                                        <td class="text-center ${trClass}">${balance}</td>
                                        <td class="text-right ${trClass}">${late_time}</td>
                                        <td class="text-right ${trClass}">${over_time}</td>
                                        <td class="text-right ${trClass}">${early_time}</td>
                                        <td class="${trClass}">${remark}</td>
                                    </tr>
                            `;
                            ++j;
                        }
                        ++i;
                    }
                    html += `
                                </tbody>
                            </table>
                        </div>
                    `;
                break;
                case 'work':
                    html += `
                        <h5 class="text-center"><b lang="en">Leave Request</b></h5>
                        <p class="text-center"><i class="far fa-calendar"></i> ${round_date}</p>
                        <div class="table-responsive">
							<table id="table_work" class="table table-bordred table-striped table-hover"  style="width: 100%;">
								<thead>
									<tr>
										<th class="hidden">DateSort</th> 
										<th class="hidden">ID</th> 
										<th class="hidden">ReferenceID</th> 
										<th class="hidden">TypeWork</th> 
										<th lang="en">Type</th>
										<th lang="en">Create</th> 
										<th lang="en">Date & Time</th> 
										<th lang="en">Reason</th> 
										<th lang="en">Total</th> 
										<th class ="dt-center" lang="en">Owner</th>
										<th>Approval</th>
										<th></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
                        <input type="hidden" id="emp_id" value="${emp_ids}">
                    `;
                break;
            }
            $(".payrollModal .modal-body").html(html);
            if(deduction_module == 'work') {
                buildWork();
            }
        }
    });
}
var table_work;
function buildWork(emp_id) {
    table_work = $('#table_work').DataTable( {
		"processing": true,
		"serverSide": true,
		"lengthMenu": [[50,100,250,500,1000,-1], [50,100,250,500,1000,"All"]],
		"ajax": {
            url: "/payroll/pay/actions/pay.php",
            "type": "POST",
            "data": function (data) {
                data.action = "buildWork";
                data.payroll_id = $('#payroll_id').val();
                data.emp_id = $('#emp_id').val();
            }
        },
		"language": default_language,
		"searchDelay": 1000,
		"deferRender": true,
		"drawCallback": function( settings ) {
				var lang = new Lang();
				lang.dynamic('th', '/js/langpack/th.json?v='+Date.now());
				lang.init({
					defaultLang: 'en'
				});
			},
		"order": [[0,'desc']],
		"columns": [{ 
            "targets": 0,
            "data": "date_sort", 
            "visible": false
        },{ 
			"targets": 1,
			"data": "id", 
			"visible": false
		},{ 
			"targets": 2,
			"data": "ref_id", 
			"visible": false
		},{ 
			"targets": 3,
			"data": "type_work", 
			"visible": false
		},{ 
			"targets": 4,
			"className": "dt-click dt-color",
			"data": "type_name", 
			"render": function ( data, type, row, meta ) {
				data = (data) ? '<b lang="en" data-lang-token="'+row['type_name_th']+'">'+data.toUpperCase()+'</b>' : '';
				return data;
			}
		},{ 
			"targets": 5,
			"className": "dt-click",
			"data": "date_create", 
			"render": function ( data, type, row, meta ) {
				data = data.split(']C');
				var start = (data[0]) ? '<i class="far fa-calendar dt-color"></i> '+data[0] : '';
				var end = (data[1])?((data[0])?'</br>':'')+'<i class="far fa-user-circle dt-color"></i> <span class="dt-color"><i>'+data[1]:'</i></span>';
				return start+end;
				
			}
		},{ 
			"targets": 6,
			"className": "dt-click",
			"data": "date_request", 
			"render": function ( data, type, row, meta ) {
				data = data.split(']C');
				var start = (data[0])?'<i class="far fa-calendar dt-color"></i> '+data[0]:'';
				var end = (data[1])?((data[0])?'</br>':'')+'<i class="far fa-calendar dt-color"></i> '+data[1]:'';
				return start+end;
				
			}
		},{ 
			"targets": 7,
			"className": "dt-click",
			"data": "reason", 
		},{ 
			"targets": 8,
			"className": "dt-click dt-color",
			"data": "total",
			"render": function ( data, type, row, meta ) {
				
				data = data.split(']C');
				var type_work = (row['type_work'])?row['type_work']:'';
				var total_time = (data[0])?data[0]:'0';
				var total_date = (data[1])?data[1]:'0';
				var total_date_hour = (data[2])?data[2]:'0';
				var mockup = '';
				if(type_work == 'leave'){
					mockup += total_date+' <span lang="en">Day</span> ';
					mockup += total_date_hour.replace(".", ":")+' <span lang="en">Hour</span></br>';
					mockup += (total_time)?'('+total_time.replace(".", ":")+' <span lang="en">Hrs.</span>)':'';
					}else{
					mockup += (total_time)?total_time.replace(".", ":")+' <span lang="en">Hrs.</span>':'';
				}
				return mockup;
			}
		},{
			"targets": 9,
			"className": "dt-click",
			"data": "owner",
			"render": function ( data, type, row, meta ) {
				data = data.split(']C');
				var emp_pic = (data[0])?data[0]:'';
				var emp_id = (data[1])?data[1]:'';
				return emp_pic
			}
		},
		{ 
			"targets": 10,
			"className": "dt-click dt-center",
			"data": "approval" ,
			"render": function ( data, type, row, meta ) {
				var head_list = data.head_list, 
				head_count = data.head_count,  
				approve_count = data.approve_count, 
				need_count = data.need_count, 
				reject_count = data.reject_count, 
				request_delete = data.request_delete;
				var approve_color = (approve_count > 0)?'#00C851':'#FF9900';					
				var approve_text = approve_count+'/'+head_count;	
				switch(true){
					case (approve_count == head_count) :
						approve_text = 'Approve';
					break;
					case (reject_count > 0) :
						approve_text = 'Not approve';
						approve_color = '#FF0000';
					break;
					case (need_count > 0) :
						approve_text = 'Need information';
						approve_color = '#FF9900';
					break;
					default:
						approve_text = approve_count+'/'+head_count;
				}
				var mockup = '';
				mockup += '<span class="approve-list" style="color:'+approve_color+'" data-template="popup_approval_'+row['id']+'">'+approve_text+'</span>';
				if(head_list.length > 0){						
					mockup += '<div class="popup-template hidden">'+'<div id="popup_approval_'+row['id']+'">'+'<div class="popup-block">'+'<div class="row">'+'<div class="col-sm-12">'+'<small><b>Approval status</b></small>'+'</div>'+'</div>';
					head_list.forEach(head => {
						var status_approve = '';
						var comment_approve = '';
						switch(head.status_approve){
							case 'new':
								status_approve = '<small style="color:#999999">Waiting</small>';
							break;
							case 'need_info':
								status_approve = '<small style="color:#FF9900;">Need information</small>';
								comment_approve = '<small>'+head.comment_approve+'</small>';
							break;
							case 'reject':
								status_approve = '<small style="color:#FF0000">Not approve</small>';
								comment_approve = '<small>'+head.comment_approve+'</small>';
							break;
							case 'approve':
								status_approve = '<small style="color:#00C851">Approve</small>';
								comment_approve = '<small>'+head.comment_approve+'</small>';
							break;
							default:
								status_approve = '<small style="color:#999999">Waiting</small>';
						}
						mockup += '<div class="row" style="padding-top:5px;">'+'<div class="col-xs-5">'+'<small style="text-overflow:ellipsis;overflow:hidden;white-space:nowrap;">'+head.head_name+'</small>'+'</div>'+'<div class="col-xs-7 text-right">'+status_approve+'</div>'+'<div class="col-xs-12 text-right">'+((head.comment_approve != '')?'<i class="fa fa-comment"></i> ':'')+comment_approve+'</div>'+'</div>';
					});						
					mockup += '</div>'+'</div>'+'</div>';
				}
				return mockup;
			}
		},{ 
			"targets": 11,
			"className": "dt-click dt-color",
			"render": function ( data, type, row, meta ) {
				var note = (row['note'])?row['note']:'';
				var attach = (row['attach'])?row['attach']:'';
				var without_pay = (row['without_pay'])?row['without_pay']:'';
				var mockup = '';
				mockup += (note)?'<span data-template="popup_note_'+row['id']+'"><i class="fas fa-sticky-note" style="font-size:16px;"></i></span> ':'';
				mockup += (attach)?'<span data-attach-path="'+attach+'" class="fancybox"><i  style="font-size:16px;'+((note)?'margin-left:5px;':'')+'" class="fas fa-paperclip"></i></span>':'';
				mockup += (without_pay == 'Y')?'<span data-tippy-content="LEAVE WITHOUT PAY" ><i  style="font-size:16px;'+((note || attach)?'margin-left:5px;':'')+'" class="fas fa-exclamation-triangle"></i></span>':'';
				if(note){						
					mockup += '<div class="popup-template hidden">'+'<div id="popup_note_'+row['id']+'">'+'<div class="popup-block">'+'<div class="row">'+'<div class="col-sm-12">'+'<span><b>Note</b></span>'+'</div>'+'</div>';
					mockup += '<div class="row">'+'<div class="col-xs-12">'+'<span>'+note+'</span>'+'</div>'+'</div>';
					mockup += '</div>'+'</div>'+'</div>';
				}
				return mockup;
			}
		}]
	});
}
function calcualteTax(value) {
    var income = parseFloat(value);
    income = income*12;
    var tax = 0;
    if (income <= 150000) {
        tax = 0;
    } else if (income <= 300000) {
        tax = (income - 150000) * 0.05;
    } else if (income <= 500000) {
        tax = (income - 300000) * 0.10 + 150000 * 0.05;
    } else if (income <= 750000) {
        tax = (income - 500000) * 0.15 + 200000 * 0.10 + 150000 * 0.05;
    } else if (income <= 1000000) {
        tax = (income - 750000) * 0.20 + 250000 * 0.15 + 200000 * 0.10 + 150000 * 0.05;
    } else if (income <= 2000000) {
        tax = (income - 1000000) * 0.25 + 250000 * 0.20 + 250000 * 0.15 + 200000 * 0.10 + 150000 * 0.05;
    } else if (income <= 5000000) {
        tax = (income - 2000000) * 0.30 + 1000000 * 0.25 + 250000 * 0.20 + 250000 * 0.15 + 200000 * 0.10 + 150000 * 0.05;
    } else {
        tax = (income - 5000000) * 0.35 + 3000000 * 0.30 + 1000000 * 0.25 + 250000 * 0.20 + 250000 * 0.15 + 200000 * 0.10 + 150000 * 0.05;
    }
    tax = (tax > 0) ? tax/12 : tax;
    return tax;
}
function showDetailTextbox(emp_id,money_type,money_id) {
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'showDetailTextbox',
            emp_id: emp_id,
            money_type: money_type,
            money_id: money_id
		},
        dataType: "JSON",
		type: 'POST',
		success: function(result){
            var emp_data = result.emp_data;
            var emp_code = emp_data.emp_code;
            var emp_name = emp_data.emp_name;
            var money_data = result.money_data;
            var money_name_en = money_data.money_name_en;
            var money_name_th = money_data.money_name_th;
            $(".show-detail").html(`
                <h5><b>${emp_name}</b></h5>    
                <div><span lang="en">Employee Code</span> ${emp_code}</div>
                <h5><i class="fas fa-coins"></i> <b lang="en">${(money_type == 'income') ? 'Income' : 'Deduction'}</b></h5>
                <div>${money_name_en} (${money_name_th})</div>
            `);
        }
    });
}
function saveMoney(rows) {
    var emp_id = $("#emp_id"+rows).val();
    var money_data = [];
    $('.money'+rows).each(function() {
        money_data.push(this.value); 
    });
    var money_type_data = [];
    $('.money_type'+rows).each(function() {
        money_type_data.push(this.value); 
    });
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'saveMoney',
            payroll_id: $("#payroll_id").val(),
            emp_id: emp_id,
            money_data: money_data,
            money_type_data: money_type_data,
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            var amount_val = result.amount_val;
            $(".amount"+rows).html(amount_val);
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
function buildFormFromID() {
    var payroll_id = $("#payroll_id").val();
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'buildFormFromID',
            payroll_id: payroll_id
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            $("#form-payroll #payroll_id").val(payroll_id);
            var payroll_data = result.payroll_data;
            var round_start = payroll_data.round_start;
            var round_end = payroll_data.round_end;
            var paid_day = payroll_data.paid_day;
            var emp_delete = payroll_data.emp_delete;
            var remark = payroll_data.remark;
            var department_data = result.department_data;
            var data_income = result.data_income;
            var data_deduction = result.data_deduction;
            $("#start_date").val(round_start);
            $("#end_date").val(round_end);
            $("#payment_date").val(paid_day);
            $("#remark").val(remark);
            var i_dept = 0;
            while(i_dept < department_data.length) {
                $("#dept_id"+department_data[i_dept]).prop("checked",true);
                ++i_dept;
            }
            var i_income = 0;
            while(i_income < data_income.length) {
                $("#income_id"+data_income[i_income]).prop("checked",true);
                ++i_income;
            }
            var i_deduction = 0;
            while(i_deduction < data_deduction.length) {
                $("#deduction_id"+data_deduction[i_deduction]).prop("checked",true);
                ++i_deduction;
            }
            $("#emp_delete").val(emp_delete);
            buildEmployeeTable();
        }
    });
}
var form_template = `
    <form id="form-payroll">
        <input type="hidden" id="payroll_id" name="payroll_id">
        <div class="form-group row">							
            <div class="col-sm-12">
                <div class="form-group">
                        <div class="col-sm-12 text-left">
                        <span class="label label-head bg-head-first ">1</span>
                        <b lang="en">Information</b>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group row">							
            <div class="col-sm-6">
                <div class="form-group row">
                    <div class="col-sm-4">
                        <label lang="en" class="control-label required-field">Payment round</label>
                    </div>
                    <div class="col-sm-8">
                        <div class="row">
                            <div class="col-sm-6">
                                <input type="text" class="form-control date" id="start_date" name="start_date" placeholder="Start date" onchange="verifyDate();">
                            </div>
                            <div class="col-sm-6">
                                <input type="text" class="form-control date" id="end_date" name="end_date" placeholder="End date" onchange="verifyDate();">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="col-sm-4">
                        <label lang="en" class="control-label required-field">Payment date</label>
                    </div>
                    <div class="col-sm-8">
                        <input type="text" class="form-control date" id="payment_date" name="payment_date" placeholder="Payment date">
                    </div>
                </div>
                <div class="form-group row">
                    <div class="col-sm-4">
                        <label lang="en" class="control-label">Remark</label>
                    </div>
                    <div class="col-sm-8">
                        <textarea class="form-control" id="remark" name="remark" style="height:150px;"></textarea>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="form-group row">
                    <div class="col-sm-4">
                        <label lang="en" class="control-label required-field">Department</label>
                    </div>
                    <div class="col-sm-8">
                        <div style="padding:10px;">
                            <input type="checkbox" id="dept_all"> 
                            <label for="dept_all" style="cursor:pointer; font-size:12px; font-weight:400; margin-left:15px;" lang="en">All department</label>
                        </div> 
                        <div class="box-remark">
                            <div class="department_area" style="height: 240px; overflow-y: auto; overflow-x: hidden;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group row">							
            <div class="col-sm-12">
                <div class="form-group">
                    <div class="col-sm-12 text-left">
                        <span class="label label-head bg-head-first">2</span>
                        <b lang="en">Income</b>/<b lang="en">Employee</b>
                        <p class="text-orange" style="margin-left:40px;"><i class="fas fa-lightbulb"></i> <span lang="en">The employee list will be imported by the system from the department selection.</span></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group row">
            <div class="col-sm-2">&nbsp;</div>
            <div class="col-sm-10">
                <table class="table" id="tb_employee">
                    <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th style="width:100px;"></th>
                            <th lang="en" style="width:100px;">Employee Code</th>
                            <th lang="en">Employee name</th>
                            <th lang="en">Department</th>
                            <th style="width:100px;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>						
        <div class="form-group row">							
            <div class="col-sm-12">
                <div class="form-group">
                    <div class="col-sm-12 text-left">
                        <span class="label label-head bg-head-first">3</span>
                        <b lang="en">Income</b>/<b lang="en">Deduction</b>
                        <p class="text-orange" style="margin-left:40px;"><i class="fas fa-lightbulb"></i> <span lang="en">You must select at least one income and one deduction.</span></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group row">
            <div class="col-sm-2">&nbsp;</div>
            <div class="col-sm-10">
                <div class="row">
                    <div class="col-sm-6">
                        <p class="text-green"><i class="fas fa-coins"></i> <b lang="en">Income</b></p>
                        <div style="padding:10px;">
                            <input type="checkbox" id="income_all"> 
                            <label for="income_all" style="cursor:pointer; font-size:12px; font-weight:400; margin-left:15px;" lang="en">All income</label>
                        </div>
                        <div class="box-remark">
                            <div class="income_area" style="height: 270px; overflow-y: auto; overflow-x: hidden;"></div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <p class="text-red"><i class="fas fa-coins"></i> <b lang="en">Deduction</b></p>
                        <div style="padding:10px;">
                            <input type="checkbox" id="deduction_all"> 
                            <label for="deduction_all" style="cursor:pointer; font-size:12px; font-weight:400; margin-left:15px;" lang="en">All deduction</label>
                        </div>
                        <div class="box-remark">
                            <div class="deduction_area" style="height: 270px; overflow-y: auto; overflow-x: hidden;"></div>
                        </div>
                    </div>
                </div>
            </div
        </div>
        <input type="hidden" id="dept_group">
        <input type="hidden" id="emp_delete" name="emp_delete">
        <div class="col-sm-12 text-right">
            <br><br>
            <button type="button" class="btn btn-orange btn-save-form" lang="en" onclick="saveForm()">Save</button> 
            <button type="button" class="btn btn-white" lang="en" onclick="closeForm()">Close</button> 
        </div>
    </form>
`;
function verifyDate() {
    var start_date = $("#start_date").val();
    var end_date = $("#end_date").val();
    if(start_date == "") {
        start_date = end_date;
    }
    if(end_date == "") {
        end_date = start_date;
    }
    $("#start_date").val(start_date);
    $("#end_date").val(end_date);
    var start= $("#start_date").datepicker("getDate");
    var end= $("#end_date").datepicker("getDate");
    days = (end- start) / (1000 * 60 * 60 * 24);
    if(days < 0) {
        $("#end_date").val(start_date);
    }
}
function saveForm() {
    var start_date = $("#start_date").val();
    var end_date = $("#end_date").val();
    var payment_date = $("#payment_date").val();
    var dept_id = [];
    $('.dept_id:checked').each(function() {
        dept_id.push(this.value); 
    });
    var income_id = [];
    $('.income_id:checked').each(function() {
        income_id.push(this.value); 
    });
    var deduction_id = [];
    $('.deduction_id:checked').each(function() {
        deduction_id.push(this.value); 
    });
    var err = 0;
    err = (start_date) ? err : err+1;
    err = (end_date) ? err : err+1;
    err = (payment_date) ? err : err+1;
    err = (dept_id.length > 0) ? err : err+1;
    err = (income_id.length > 0) ? err : err+1;
    err = (deduction_id.length > 0) ? err : err+1;
    if(err > 0) {
        swal({type: 'warning',title: "Warning...",text: 'Please select all item completely.',showConfirmButton: false,timer: 2000});
    } else {
        $(".loader").addClass("active");
        var fd = new FormData(document.getElementById("form-payroll"));
        $.ajax({
            url: "/payroll/pay/actions/pay.php?action=saveForm",
            type: "POST",
            data: fd,
            processData: false,
            contentType: false,
            dataType: "JSON",
            type: 'POST',
            success: function(result){
                swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 1500});
                $(".loader").removeClass("active");
                setTimeout(function () {
                    managePayroll(result.payroll_id);
                }, 2000);
            }
        });
    }
}
function managePayroll(payroll_id) {
    $.redirect("/payroll/pay/detail", {
		payroll_id: payroll_id,
	}, 'post', '');
}
function closeForm() {
	event.stopPropagation();
    swal({
        html:true,
        title: window.lang.translate("Are you sure?"),
        text: 'Do you want to cancel this action?',
        type: "warning",
        showCancelButton: true,
        closeOnConfirm: false,
        confirmButtonText: window.lang.translate("Ok"),
        cancelButtonText: window.lang.translate("Cancel"),	
        confirmButtonColor: '#FBC02D',
        cancelButtonColor: '#CCCCCC',
        showLoaderOnConfirm: true,
    },
    function(isConfirm){
        if (isConfirm) {
			window.open('', '_self', ''); 
			window.close();
		}
	});
}
function buildDepartmentForm() {
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'buildDepartmentForm'
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            var department_data = result.department_data;
            var department_length = department_data.length;
            var i = 0;
            while(i < department_length) {
                var dept_id = department_data[i]['dept_id'];
                var dept_description = department_data[i]['dept_description'];
                var count_emp = department_data[i]['count_emp'];
                $(".department_area").append(`
                    <div>
                        <input class="dept_id" type="checkbox" id="dept_id${dept_id}" name="dept_id[]" value="${dept_id}"> 
                        <label for="dept_id${dept_id}" style="cursor:pointer; font-size:12px; font-weight:400; margin-left:15px;">${dept_description} (${count_emp})</label>
                    </div>    
                `);
                ++i;
            }
            $(".dept_id").click(function() {
                $('#dept_all').prop('checked', false);
                buildEmployeeTable();
            });
            if(payroll_status == 3 || payroll_status == 6) {
                $("input").attr("disabled",true);
            }
        }
    });
}
function buildIncome() {
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'buildIncome'
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            var income_data = result.income_data;
            var income_length = income_data.length;
            var i = 0;
            while(i < income_length) {
                var income_id = income_data[i]['income_id'];
                var income_name_en = income_data[i]['income_name_en'];
                var income_name_th = income_data[i]['income_name_th'];
                var income_flag = income_data[i]['income_flag'];
                $(".income_area").append(`
                    <div>
                        <input class="income_id" type="checkbox" id="income_id${income_id}" name="income_id[]" value="${income_id}"> 
                        <label for="income_id${income_id}" style="cursor:pointer; font-size:12px; font-weight:400; margin-left:15px;">
                            ${income_name_en} (${income_name_th})
                            ${(income_flag == 'O') ? `
                                <div class="text-orange">Origami</div>    
                            ` : ``}
                        </label>
                    </div>    
                `);
                ++i;
            }
            $(".income_id").click(function() {
                $('#income_all').prop('checked', false);
            });
            if(payroll_status == 3 || payroll_status == 6) {
                $("input").attr("disabled",true);
            }
        }
    });
}
function buildDeduction() {
    $.ajax({
		url: "/payroll/pay/actions/pay.php",
		type: "POST",
		data: {
			action: 'buildDeduction'
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            var deduction_data = result.deduction_data;
            var deduction_length = deduction_data.length;
            var i = 0;
            while(i < deduction_length) {
                var deduction_id = deduction_data[i]['deduction_id'];
                var deduction_name_en = deduction_data[i]['deduction_name_en'];
                var deduction_name_th = deduction_data[i]['deduction_name_th'];
                var deduction_flag = deduction_data[i]['deduction_flag'];
                var deduction_txt = '';
                if(deduction_flag == "O") {
                    deduction_txt += '<span class="text-orange">Origami</span>';
                }
                $(".deduction_area").append(`
                    <div>
                        <input class="deduction_id" type="checkbox" id="deduction_id${deduction_id}" name="deduction_id[]" value="${deduction_id}"> 
                        <label for="deduction_id${deduction_id}" style="cursor:pointer; font-size:12px; font-weight:400; margin-left:15px;">
                            ${deduction_name_en} (${deduction_name_th})
                            ${deduction_txt}
                        </label>
                    </div>    
                `);
                ++i;
            }
            $(".deduction_id").click(function() {
                $('#deduction_all').prop('checked', false);
            });
            if(payroll_status == 3 || payroll_status == 6) {
                $("input").attr("disabled",true);
            }
        }
    });
}
var tb_employee;
function buildEmployeeTable() {
    var ids = [];
    $('.dept_id:checked').each(function() {
        ids.push(this.value); 
    });
    $("#dept_group").val(ids);
    if ($.fn.DataTable.isDataTable('#tb_employee')) {
        $('#tb_employee').DataTable().ajax.reload(null, false);
    } else {
		tb_employee = $('#tb_employee').DataTable({
            "processing": true,
        	"serverSide": true,
			"lengthMenu": [[50,100,250,500,1000,-1], [50,100,250,500,1000,"All"]],
			"ajax": {
				url: "/payroll/pay/actions/pay.php",
				"type": "POST",
				"data": function (data) {
                    data.action = "buildEmployeeTable";
                    data.filter_department = $('#dept_group').val();
                    data.emp_delete = $('#emp_delete').val();
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
                if($("#emp_delete").val()) {
                    $(".btn-join").attr("disabled",false);
                } else {
                    $(".btn-join").attr("disabled",true);
                }
                var emp_delete = $("#emp_delete").val();
                if(emp_delete) {
                    var emp_data = emp_delete.split(",");
                    $(".count_emp").html(emp_data.length);
                }
                if(payroll_status == 3 || payroll_status == 6) {
                    $(".btn-del-emp").css("display","none");
                }
			},
			"order": [[5,'asc'],[7,'asc']],
			"columns": [{ 
                "targets": 0,
                "data": "firstname",
                "visible": false
            },{ 
                "targets": 1,
                "data": "lastname",
                "visible": false
            },{ 
                "targets": 2,
                "data": "firstname_th",
                "visible": false
            },{ 
                "targets": 3,
                "data": "lastname_th",
                "visible": false
            },{ 
                "targets": 4,
                "data": "emp_pic",
                "orderable": false,
                "render": function ( data, type, row, meta ) {
                    return `
                        <div class="avatar">
                            <img src="${data}" onerror="this.src='/images/default.png'">
                        </div>
                    `;
                }
            },{ 
                "targets": 5,
                "data": "emp_code"
            },{ 
                "targets": 6,
                "data": "emp_name"
            },{ 
                "targets": 7,
                "data": "dept_description"
            },{ 
                "targets": 8,
                "data": "emp_id",
                "className": "text-center",
                "orderable": false,
                "render": function ( data, type, row, meta ) {
                    return `
                        <a class="text-red btn-del-emp" onclick="delEmployee(${data});"><i class="fas fa-times fa-2x"></i></a>
                    `;
                }
            }]
        });
        $('div#tb_employee_filter.dataTables_filter label input').remove();
        $('div#tb_employee_filter.dataTables_filter label span').remove();
        var template = `
            <input type="search" class="form-control input-sm search-datatable" placeholder="" autocomplete="off" style="margin-bottom:0px !important;"> 
            <button type="button" class="btn btn-white btn-join" onclick="joinEmployee();" style="margin-top:9px;"><i class="fas fa-plus"></i> <span lang="en">From delete</span> <span class="count_emp"></span></button>
        `;
        $('div#tb_employee_filter.dataTables_filter input').hide();
        $('div#tb_employee_filter.dataTables_filter label').append(template);
        var searchDataTable = $.fn.dataTable.util.throttle(function (val) {
            if(typeof val != 'undefined'){
                tb_employee.search(val).draw();	
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
                buildEmployeeTable();
            }
        });
    }
}
function delEmployee(emp_id) {
    var emp_delete = $("#emp_delete").val();
    if(emp_delete) {
        emp_delete += ','+emp_id;
    } else {
        emp_delete = emp_id;
    }
    $("#emp_delete").val(emp_delete);
    buildEmployeeTable();
    if(emp_delete) {
        $(".btn-join").attr("disabled",false);
    } else {
        $(".btn-join").attr("disabled",true);
    }
    if(emp_delete) {
        var emp_data = emp_delete.split(",");
        $(".count_emp").html(emp_data.length);
    }
}
function joinEmployee() {
    $(".joinModal").modal();
    $(".joinModal .modal-footer").html(`
        <button type="button" class="btn btn-orange join-all" lang="en" onclick="joinAll();">Join all</button> 
        <button type="button" class="btn btn-white" data-dismiss="modal" lang="en">Close</button>    
    `);
    $(".joinModal .modal-body").html(`
        <table class="table" id="tb_join">
            <thead>
                <tr>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th style="width:100px;"></th>
                    <th lang="en" style="width:100px;">Employee Code</th>
                    <th lang="en">Employee name</th>
                    <th lang="en">Department</th>
                    <th style="width:100px;"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    `);
    buildEmployeeDelete();
}
var tb_join;
function buildEmployeeDelete() {
    if ($.fn.DataTable.isDataTable('#tb_join')) {
        $('#tb_join').DataTable().ajax.reload(null, false);
    } else {
		tb_join = $('#tb_join').DataTable({
            "processing": true,
        	"serverSide": true,
			"lengthMenu": [[50,100,250,500,1000,-1], [50,100,250,500,1000,"All"]],
			"ajax": {
				url: "/payroll/pay/actions/pay.php",
				"type": "POST",
				"data": function (data) {
                    data.action = "buildEmployeeDelete";
                    data.emp_delete = $('#emp_delete').val();
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
			"order": [[5,'asc'],[7,'asc']],
			"columns": [{ 
                "targets": 0,
                "data": "firstname",
                "visible": false
            },{ 
                "targets": 1,
                "data": "lastname",
                "visible": false
            },{ 
                "targets": 2,
                "data": "firstname_th",
                "visible": false
            },{ 
                "targets": 3,
                "data": "lastname_th",
                "visible": false
            },{ 
                "targets": 4,
                "data": "emp_pic",
                "orderable": false,
                "render": function ( data, type, row, meta ) {
                    return `
                        <div class="avatar">
                            <img src="${data}" onerror="this.src='/images/default.png'">
                        </div>
                    `;
                }
            },{ 
                "targets": 5,
                "data": "emp_code"
            },{ 
                "targets": 6,
                "data": "emp_name"
            },{ 
                "targets": 7,
                "data": "dept_description"
            },{ 
                "targets": 8,
                "data": "emp_id",
                "className": "text-center",
                "orderable": false,
                "render": function ( data, type, row, meta ) {
                    return `
                        <a class="text-green" onclick="addEmployee(${data});"><i class="fas fa-sign-in-alt fa-2x"></i></a>
                    `;
                }
            }]
        });
        $('div#tb_join_filter.dataTables_filter label input').remove();
        $('div#tb_join_filter.dataTables_filter label span').remove();
        var template = `
            <input type="search" class="form-control input-sm search-datatable" placeholder="" autocomplete="off" style="margin-bottom:0px !important;"> 
        `;
        $('div#tb_join_filter.dataTables_filter input').hide();
        $('div#tb_join_filter.dataTables_filter label').append(template);
        var searchDataTable = $.fn.dataTable.util.throttle(function (val) {
            if(typeof val != 'undefined'){
                tb_join.search(val).draw();	
            } 
        },1000);
        $('.search-datatable').on('keyup',function(e){
            if(e.keyCode === 13){
                $('.dataTables_processing.panel').css('top','5%');
                val = e.target.value.trim().replace(/ /g, "");
                searchDataTable(val);
            }
            if(e.target.value == ''){
                tb_join.search('').draw();
                buildEmployeeDelete();
            }
        });
    }
}
function addEmployee(emp_id) {
    var emp_delete = $("#emp_delete").val();
    emp_val = emp_delete.split(',');
    emp_val = jQuery.grep(emp_val, function(value) {
        return value != emp_id;
    });
    $("#emp_delete").val(emp_val.join(","));
    buildEmployeeDelete();
    buildEmployeeTable();
    if($("#emp_delete").val() == "") {
        $(".join-all").css("display","none");
        $(".count_emp").html("");
    } else {
        $(".join-all").css("display","");
        var emp_data = emp_delete.split(",");
        $(".count_emp").html(emp_data.length);
    }
}   
function joinAll() {
    var emp_delete = $("#emp_delete").val();
    if(emp_delete) {
        $("#emp_delete").val("");
        buildEmployeeTable();
        swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 2000});
        $(".joinModal").modal("hide");
    } else {
        swal({type: 'error',title: "Sorry...",text: "No employees are available to participate",showConfirmButton: false,timer: 3000});
    }
}
function sendToApprove() {
    var payroll_id = $("#payroll_id").val();
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
					buildTimeline();
                    if (localStorage.getItem('reloadPayroll') === null) {
                        localStorage.setItem('reloadPayroll', 'true');
                    }
                    localStorage.removeItem('reloadPayroll');
				}
			});
		}
	});
}
function paidData() {
    var payroll_id = $("#payroll_id").val();
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
					buildTimeline();
                    if (localStorage.getItem('reloadPayroll') === null) {
                        localStorage.setItem('reloadPayroll', 'true');
                    }
                    localStorage.removeItem('reloadPayroll');
				}
			});
		}
	});
}
function exportToExcel() {
    $.redirect("/payroll/export/pay.php",{ 
        'payroll_id': $('#payroll_id').val()
    },'post','_blank');
}