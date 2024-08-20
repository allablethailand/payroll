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
    buildPage();
});
function buildPage() {
    var pages = $("#pages").val();
    switch(pages) {
        case 'permission':
            $(".setting_tab").html(permission_template);
            buildPermission();
        break;
    }
}
var permission_template = `
    <form id="permission_form">
        <table class="table" id="tb_permission">
            <thead>
                <tr>
                    <th></th>
                    <th lang="en">Employee</th>
                    <th><span lang="en">Special permission</span><br><span lang="en" class="text-orange">Specially Allowed means that this employee does not need to verify their identity to access the site.</span></th>
                    <th></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </form>
    <div class="text-right">
        <br><br>
        <button type="button" class="btn btn-orange" lang="en" onclick="savePermission()">Save</button>
    </div> 
`;
var tb_permission;
function buildPermission() {
    if ($.fn.DataTable.isDataTable('#tb_permission')) {
        $('#tb_permission').DataTable().ajax.reload(null, false);
    } else {
		tb_permission = $('#tb_permission').DataTable({
			"processing": true,
			"lengthMenu": [[50, 100, 150,200,250,300, -1], [50, 100, 150,200,250,300, "All"]],
			"ajax": {
				url: "/payroll/setting/actions/setting.php",
				"type": "POST",
				"data": function (data) {
					data.action = "buildPermission";
				}
			},
			"language": default_language,
			"responsive": true,
			"searchDelay": 1000,
			"deferRender": false,
            "createdRow": function(row,data,dataIndex,meta) {
                $(row).addClass('target'+dataIndex);
                $(row).addClass('target-tr');
            },
			"drawCallback": function( settings ) {
				var lang = new Lang();
				lang.dynamic('th', '/js/langpack/th.json?v='+Date.now());
				lang.init({
					defaultLang: 'en'
				});
                $(".loader").removeClass("active");
                $('.emp_id').select2({
                    theme: "bootstrap",
                    placeholder: "Choose employee",
                    minimumInputLength: -1,
                    allowClear: true,
                    ajax: {
                        url: "/payroll/setting/actions/setting.php",
                        dataType: 'json',
                        delay: 250,
                        cache: false,
                        data: function(params) {
                            return {
                                term: params.term,
                                page: params.page || 1,
                                action: 'buildEmployee',
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
			},
			"order": [[0,'asc']],
			"columns": [{ 
				"targets": 0,
                "data": "permission_id",
                "render": function (data,type,row,meta) {
                    var permission_id = (data) ? data :'';
                    var rows = meta.row + meta.settings._iDisplayStart;
                    return `
                        <input type="hidden" id="permission_id${rows}" name="permission_id${rows}" value="${permission_id}">
                        <input type="hidden" name="permission_row[]" value="${rows}">
                    `;
                }
			},{ 
				"targets": 1,
                "data": "emp_id",
				"render": function (data,type,row,meta) {
                    var rows = meta.row + meta.settings._iDisplayStart;
                    return `
                        <select class="form-control emp_id" id="emp_id${rows}" name="emp_id${rows}" style="width:100%;">
                            ${(data) ? `<option value="${data}">${row["emp_name"]}</option>` : ``}
                        </select>
                    `;
				}
			},{ 
				"targets": 2,
                "data": "special_permission",
				"render": function (data,type,row,meta) {
                    var rows = meta.row + meta.settings._iDisplayStart;
                    if(row["special_permission"]) {
                        var checked_data = (row["special_permission"] == 'Y') ? 'checked' : '';
                    } else {
                        var checked_data = '';
                    }
                    var mockup = `
                        <div class="material-switch">
                            <input name="special_permission${rows}" id="special_permission_${rows}" type="checkbox" ${checked_data} value="Y" autocomplete="off"> 
                            <label for="special_permission_${rows}" class="label-success"></label>
                        </div>
                    `;
					return mockup;
				}
			},{ 
				"targets": 3,
                "className": "text-center",
				"render": function (data,type,row,meta) {
                    var rows = meta.row + meta.settings._iDisplayStart;
					return `<a class="text-red" onclick="delRows(${rows})"><i class="fas fa-times fa-2x"></i></a>`;
				}
			}],
		});
        $('div#tb_permission_filter.dataTables_filter label input').remove();
        $('div#tb_permission_filter.dataTables_filter label span').remove();
        var template = `
            <input type="search" class="form-control input-sm search-datatable" placeholder="Search..." autocomplete="off"> 
            <button type="button" class="btn btn-primary add-rows" style="font-size:11px;"><i class="fas fa-plus"></i> <span lang="en">Permission</span></button>
        `;
        $('div#tb_permission_filter.dataTables_filter input').hide();
        $('div#tb_permission_filter.dataTables_filter label').append(template);
        var searchDataTable = $.fn.dataTable.util.throttle(
        function (val) {
            if(typeof val != 'undefined'){
                tb_permission.search( val ).draw();	
            } 
        },1000);
        $('.search-datatable').on('keyup',function(e){
            if(e.keyCode === 13){
                $('.dataTables_processing.panel').css('top','5%');
                val = e.target.value.trim().replace(/ /g, "");
                searchDataTable(val);
            }
        });
        var rowCount = $('#tb_permission tbody tr').length;
        $(".add-rows").on( 'click', function () {
            tb_permission.row.add(['','','','']).node().id = 'rowTarget'+rowCount;
            tb_permission.draw(false);
            rowCount++;
        });
	}
}
function delRows(item){
	tb_permission.row(".target"+item).remove().draw();
}
function savePermission() {
    var err = 0;
    $('.emp_id').each(function() {
        err = (!this.value) ? 1 : 0;
    });
    if(err > 0) {
        swal({type: 'warning',title: "Warning...",text: 'Please select all employee item completely.',showConfirmButton: false,timer: 3000});
    } else {
        $(".loader").addClass("active");
        var fd = new FormData(document.getElementById("permission_form"));
        console.log(fd);
        $.ajax({
            url: "/payroll/setting/actions/setting.php?action=savePermission",
            type: "POST",
            data: fd,
            processData: false,
            contentType: false,
            dataType: "JSON",
            type: 'POST',
            success: function(result){
                swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 1500});
                $(".loader").removeClass("active");
                buildPermission();
            }
        });
    }
}