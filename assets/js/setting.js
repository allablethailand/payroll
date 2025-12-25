let page = 'period';
$(document).ready(function() {
    buildPage();
    $(".origami-nav li a").click(function() {
        page = $(this).attr("data-page");
        buildPage();
    });
});
function buildPage() {
    let html = ``;
    switch(page) {
        case 'period':
            html = `
                <table class="table table-border" id="tb_period">
                    <thead>
                        <tr>
                            <th></th>
                            <th lang="en">Close/Open</th>
                            <th lang="en">Period</th>
                            <th lang="en">Cut-off Date</th>
                            <th lang="en">Payment Date</th>
                            <th lang="en">Date Update</th>
                            <th lang="en">Employee Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            `;
            $(".payroll-container").html(html);
            loadPeriodList();
            break;
        case 'revenue':
            html = `
                <table class="table table-border" id="tb_revenue">
                    <thead>
                        <tr>
                            <th lang="en">Close/Open</th>
                            <th lang="en">Revenue (EN)</th>
                            <th lang="en">Revenue (TH)</th>
                            <th lang="en">Date Update</th>
                            <th lang="en">Employee Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            `;
            $(".payroll-container").html(html);
            break;
        case 'deductions':
            html = `
                <table class="table table-border" id="tb_deductions">
                    <thead>
                        <tr>
                            <th lang="en">Close/Open</th>
                            <th lang="en">Deductions (EN)</th>
                            <th lang="en">Deductions (TH)</th>
                            <th lang="en">Date Update</th>
                            <th lang="en">Employee Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            `;
            $(".payroll-container").html(html);
            break;
    }
}
let tb_period;
function loadPeriodList() {
    if ($.fn.DataTable.isDataTable('#tb_period')) {
        $('#tb_period').DataTable().ajax.reload(null, false);
    } else {
		tb_period = $('#tb_period').DataTable({
            "processing": true,
        	"serverSide": true,
			"lengthMenu": [[50, 100, 250, 500, 1000, -1], [50, 100, 250, 500, 1000, "All"]],
			"ajax": {
				"url": "/payroll/models/setting.php",
				"type": "POST",
				"data": function (data) {
                    data.action = "loadPeriodList";
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
			"order": [[0,'desc']],
			"columns": [{ 
                "targets": 0,
                "data": "period_id",
                "visible": false,
            },{ 
                "targets": 1,
                "data": "status",
                "className": "text-center",
                "render": function (data,type,row,meta) {	
					return `
                        <a onclick="switchPeriod(${row['period_id']},'${data == 0 ? 'off' : 'on'}');"><i class="fas fa-toggle-${data == 0 ? 'on' : 'off'} text-${data == 0 ? 'green' : 'grey'} fa-2x"></i></a>
                    `;
                }
            },{ 
                "targets": 2,
                "data": "period_name"
            },{ 
                "targets": 3,
                "data": "cutoff_date",
                "render": function (data,type,row,meta) {	
					return `
                        ${data == 0 ? `Last day of month` : data}
                    `;
                }
            },{ 
                "targets": 4,
                "data": "payment_date",
                "render": function (data,type,row,meta) {	
					return `
                        ${data == 0 ? `Last day of month` : data}
                    `;
                }
            },{ 
                "targets": 5,
                "data": "date_modify"
            },{ 
                "targets": 6,
                "data": "emp_name",
            },{ 
                "targets": 7,
                "data": "period_id",
                "className": "text-center",
                "render": function (data,type,row,meta) {	
					return `
                        <div class="nowarp">
                            <button type="button" class="btn btn-circle btn-orange" onclick="managePeriod(${data})"><i class="fas fa-pencil-alt"></i></button>
                            <button type="button" class="btn btn-circle btn-red" onclick="delPeriod(${data})"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    `;
                }
            }]
        });
        $('div#tb_period_filter.dataTables_filter label input').remove();
        $('div#tb_period_filter.dataTables_filter label span').remove();
        var template = `
            <input type="search" class="form-control input-sm search-datatable" placeholder="" autocomplete="off" style="margin-bottom:0px !important;"> 
            <button type="button" class="btn btn-green" onclick="managePeriod('')"><i class="fas fa-plus"></i> <span lang="en">Period</span></button>
        `;
        $('div#tb_period_filter.dataTables_filter input').hide();
        $('div#tb_period_filter.dataTables_filter label').append(template);
        var searchDataTable = $.fn.dataTable.util.throttle(function (val) {
            if(typeof val != 'undefined') {
                tb_period.search(val).draw();	
            } 
        },1000);
        $('.search-datatable').on('keyup',function(e) {
            if(e.keyCode === 13) {
                $('.dataTables_processing.panel').css('top','5%');
                val = e.target.value.trim().replace(/ /g, "");
                searchDataTable(val);
            }
            if(e.target.value == '') {
                tb_period.search('').draw();
                loadPeriodList();
            }
        });
    }
}
function managePeriod(period_id) {
    $(".systemModal").modal();
    $(".systemModal .modal-header").html(`
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h5 class="modal-title">Manage Period</h5>   
    `);
    $(".systemModal .modal-footer").html(`
        <button type="button" class="btn btn-orange btn-save-period" lang="en" onclick="savePeriod();">Save</button>    
        <button type="button" class="btn btn-white" data-dismiss="modal" lang="en">Close</button>    
    `);
    $(".systemModal .modal-body").html(`
        <input type="hidden" id="period_id" value="${period_id || ''}">
        <p style="margin:10px auto;"><span lang="en">Period Name</span> <code>*</code></p>
        <input type="text" class="form-control require-obj" id="period_name">
        <div class="row">
            <div class="col-sm-6">
                <p style="margin:10px auto;"><span lang="en">Cut-Off Date</span> <code>*</code></p>
                <select class="form-control require-obj" id="cutoff_date"></select>
            </div>
            <div class="col-sm-6">
                <p style="margin:10px auto;"><span lang="en">Payment Date</span> <code>*</code></p>
                <select class="form-control require-obj" id="payment_date"></select>
            </div>
        </div>
        <p class="text-muted" style="margin:10px auto;">
            <i class="fas fa-info-circle"></i>
            Dates will be applied monthly. If the selected date exceeds the number of days in a month, the last day will be used automatically.
        </p>
    `);
    buildDaySelect('#cutoff_date');
    buildDaySelect('#payment_date');
    if(period_id) {
        $.ajax({
			url: '/payroll/models/setting.php',
			type: "POST",
			data: {
				action: 'periodData',
                period_id: period_id
			},
			async: true,
			dataType: "JSON",
			success: function(result) {
                if(result.status) {
                    let p = result.period_data;
                    $("#period_name").val(p.period_name || '');
                    buildDaySelect('#cutoff_date', parseInt(p.cutoff_date));
                    buildDaySelect('#payment_date', parseInt(p.payment_date));
                } else {
                    swal({
                        type: 'warning',
                        title: "Warning...",
                        text: result.message,
                        showConfirmButton: false,
                        timer: 2500
                    });
                }
            }
        });
    }
    $('#cutoff_date, #payment_date').on('change', function () {
        let cutoff  = parseInt($('#cutoff_date').val());
        let payment = parseInt($('#payment_date').val());
        if (cutoff > 0 && payment > 0 && payment < cutoff) {
            swal('Warning', 'Payment date must be after cut-off date.', 'warning');
            $('#payment_date').val('');
        }
    });
}
function buildDaySelect(selector, selectedValue) {
    const $select = $(selector);
    $select.empty();
    $select.append('<option value="">-- Select day --</option>');
    $select.append('<option value="0">Last day of month</option>');
    for (let i = 1; i <= 31; i++) {
        let selected = (selectedValue == i) ? 'selected' : '';
        $select.append(`<option value="${i}" ${selected}>${i}</option>`);
    }
    if (selectedValue === 0) {
        $select.val("0");
    }
}
function savePeriod() {
    let period_id    = $('#period_id').val();
    let period_name  = $('#period_name').val().trim();
    let cutoff_date  = $('#cutoff_date').val();
    let payment_date = $('#payment_date').val();
    if (!period_name) {
        swal('Warning', 'Please enter period name.', 'warning');
        $('#period_name').focus();
        return;
    }
    if (cutoff_date === '') {
        swal('Warning', 'Please select cut-off date.', 'warning');
        $('#cutoff_date').focus();
        return;
    }
    if (payment_date === '') {
        swal('Warning', 'Please select payment date.', 'warning');
        $('#payment_date').focus();
        return;
    }
    cutoff_date  = parseInt(cutoff_date);
    payment_date = parseInt(payment_date);
    if (cutoff_date > 0 && payment_date > 0 && payment_date < cutoff_date) {
        swal('Warning', 'Payment date must be after cut-off date.', 'warning');
        return;
    }
    $.ajax({
        url: '/payroll/models/setting.php',
        type: 'POST',
        dataType: 'JSON',
        data: {
            action: 'savePeriod',
            period_id: period_id,
            period_name: period_name,
            cutoff_date: cutoff_date,
            payment_date: payment_date
        },
        beforeSend: function () {
            $('.btn-save-period').prop('disabled', true);
        },
        success: function (result) {
            if (result.status) {
                swal({
                    type: 'success',
                    title: 'Success',
                    text: result.message,
                    timer: 2000,
                    showConfirmButton: false
                });
                $('.systemModal').modal('hide');
                if (typeof loadPeriodList === 'function') {
                    loadPeriodList();
                }
            } else {
                swal('Error', result.message, 'error');
            }
        },
        complete: function () {
            $('.btn-save-period').prop('disabled', false);
        },
        error: function () {
            swal('Error', 'System error. Please try again.', 'error');
            $('.btn-save-period').prop('disabled', false);
        }
    });
}
function delPeriod(period_id) {
    event.stopPropagation();
    swal({
        html:true,
        title: window.lang.translate("Are you sure?"),
        text: 'Do you want to delete these records? </br> This process cannot be undone.',
        type: "error",
        showCancelButton: true,
        closeOnConfirm: false,
        confirmButtonText: window.lang.translate("Delete"),
        cancelButtonText: window.lang.translate("Cancel"),	
        confirmButtonColor: '#FF6666',
        cancelButtonColor: '#CCCCCC',
        showLoaderOnConfirm: true,
    },
    function(isConfirm){
        if (isConfirm) {
            $.ajax({
                url: '/payroll/models/setting.php',
                type: "POST",
                data: {
                    action:'delPeriod',
                    period_id: period_id
                },
                dataType: "JSON",
                type: 'POST',
                success: function(result){
                    if(result.status === true){			
                        swal({type: 'success', title: "Successfully", text: "", showConfirmButton: false, timer: 1500});
                        loadPeriodList();
                    }else{
                        swal({type: 'warning',title: "Warning...",text: result.message,timer: 2000});
                    }
                }
            });
        } else {
            swal.close();
        }
    });
}
function switchPeriod(period_id,option) {
    if(option == 'off'){
		var message = 'Deactivate?';
		var type_color = 'error';
		var button_color = '#FF6666';
	}else{
		var message = 'Activate?';
		var type_color = 'info';
		var button_color = '#5bc0de';
	}
	event.stopPropagation();
	swal({ 
		html:true,
		title: window.lang.translate(message),
		text: '',
		type: type_color,
		showCancelButton: true,
		closeOnConfirm: false,
		confirmButtonText: window.lang.translate("Yes"),
		cancelButtonText: window.lang.translate("Cancel"),	
		confirmButtonColor: button_color,
		cancelButtonColor: '#CCCCCC',
		showLoaderOnConfirm: true,
	},
	function(isConfirm){
		if (isConfirm) {
			$.ajax({
                url: '/payroll/models/setting.php',
                type: "POST",
                data: {
                    action:'switchPeriod',
                    period_id: period_id,
                    option: option
                },
                dataType: "JSON",
                type: 'POST',
                success: function(result) {
                    if(result.status === true) {			
                        swal({type: 'success', title: "Successfully", text: "", showConfirmButton: false, timer: 1500});
                        loadPeriodList();
                    } else {
                        swal({type: 'warning',title: "Warning...",text: result.message,timer: 2000});
                    }
                }
            });
		}else{
			swal.close();
		}
	});	
}