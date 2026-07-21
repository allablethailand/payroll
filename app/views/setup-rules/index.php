<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="time_and_leave">Time & Leave</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="setup_and_rules">Setup & Rules</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-gears"></i>
            <span data-i18n="setup_and_rules">Setup & Rules</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="setup_and_rules_description">Define work shifts, public holidays, leave types, and overtime (OT) calculation rates for employees.</p>
    </div>
    <ul class="nav nav-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold" id="shift-tab" data-bs-toggle="tab" data-bs-target="#shift-pane" type="button" role="tab" aria-controls="shift-pane" aria-selected="true">
                <i class="fa-regular fa-calendar-days me-2"></i><span data-i18n="shift">Shift</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="holiday-tab" data-bs-toggle="tab" data-bs-target="#holiday-pane" type="button" role="tab" aria-controls="holiday-pane" aria-selected="false">
                <i class="fa-solid fa-calendar-day me-2"></i><span data-i18n="holiday">Holiday</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="leave-type-tab" data-bs-toggle="tab" data-bs-target="#leave-type-pane" type="button" role="tab" aria-controls="leave-type-pane" aria-selected="false">
                <i class="fa-regular fa-calendar-check me-2"></i><span data-i18n="leave_type">Leave Type</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold" id="ot-rate-tab" data-bs-toggle="tab" data-bs-target="#ot-rate-pane" type="button" role="tab" aria-controls="ot-rate-pane" aria-selected="false">
                <i class="fa-solid fa-coins me-2"></i><span data-i18n="ot_rate">OT Rate</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
        <div class="tab-pane fade show active" id="shift-pane" role="tabpanel" aria-labelledby="shift-tab" tabindex="0">
            <div class="panel-toolbar">
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="shiftSearch" class="form-control form-control-sm" data-i18n="shift_search_placeholder" placeholder="Search shifts...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openShiftModal()"><i class="fa-solid fa-plus"></i><span data-i18n="add_shift">Add Shift</span></button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_shift" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="shift_name">Shift Name</th>
                            <th data-i18n="shift_code">Shift Code</th>
                            <th data-i18n="description">Description</th>
                            <th data-i18n="time">Time</th>
                            <th data-i18n="last_modified">Last Modified</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="holiday-pane" role="tabpanel" aria-labelledby="holiday-tab" tabindex="0">
            <div class="panel-toolbar">
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="holidaySearch" class="form-control form-control-sm" data-i18n="holiday_search_placeholder" placeholder="Search holidays...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openHolidayModal()"><i class="fa-solid fa-plus"></i><span data-i18n="add_holiday">Add Holiday</span></button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_holiday" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="holiday_name">Holiday Name</th>
                            <th data-i18n="date">Date</th>
                            <th data-i18n="type">Type</th>
                            <th data-i18n="applies_to">Applies To</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="leave-type-pane" role="tabpanel" aria-labelledby="leave-type-tab" tabindex="0">
            <div class="panel-toolbar">
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="leaveSearch" class="form-control form-control-sm" data-i18n="leave_search_placeholder" placeholder="Search leave types...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openLeaveModal()"><i class="fa-solid fa-plus"></i><span data-i18n="add_leave_type">Add Leave Type</span></button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_leave" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="leave_type">Leave Type</th>
                            <th data-i18n="code">Code</th>
                            <th data-i18n="quota_days_per_year">Quota (days/yr)</th>
                            <th data-i18n="pay_type">Pay Type</th>
                            <th data-i18n="carry_over">Carry Over</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="ot-rate-pane" role="tabpanel" aria-labelledby="ot-rate-tab" tabindex="0">
            <div class="panel-toolbar">
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="otSearch" class="form-control form-control-sm" data-i18n="ot_search_placeholder" placeholder="Search OT rates...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openOtModal()"><i class="fa-solid fa-plus"></i><span data-i18n="add_ot_rate">Add OT Rate</span></button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_ot" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="ot_name">OT Name</th>
                            <th data-i18n="applies_to">Applies To</th>
                            <th data-i18n="multiplier">Multiplier</th>
                            <th data-i18n="calculation_base">Calculation Base</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="shiftModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="shiftModalTitle"><i class="fa-regular fa-calendar-days"></i> <span data-i18n="shift">Shift</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="shiftId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="shift_name">Shift Name</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="shiftName" data-i18n="shift_name_placeholder" placeholder="e.g., Morning Shift">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="shift_code">Shift Code</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="shiftCode" data-i18n="shift_code_placeholder" placeholder="e.g., SH-01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="time_in">Time In</label>
                        <input type="time" class="form-control" id="shiftStart" value="08:00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="time_out">Time Out</label>
                        <input type="time" class="form-control" id="shiftEnd" value="17:00">
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="description">Description</label>
                        <textarea class="form-control" id="shiftDesc" rows="2" data-i18n="shift_desc_placeholder" placeholder="Additional details"></textarea>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="shiftStatus" checked>
                        </div>
                        <label class="form-label m-0" for="shiftStatus" data-i18n="enable_this_shift">Enable this shift</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveShift()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="holidayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="holidayModalTitle"><i class="fa-solid fa-calendar-day"></i> <span data-i18n="holiday">Holiday</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="holidayId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label"><span data-i18n="holiday_name">Holiday Name</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="holidayName" data-i18n="holiday_name_placeholder" placeholder="e.g., Songkran Festival">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="date">Date</span> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="holidayDate">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="type">Type</label>
                        <select class="form-select" id="holidayType">
                            <option value="Annual" data-i18n="holiday_type_annual">Annual</option>
                            <option value="One-time" data-i18n="holiday_type_onetime">One-time</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="applies_to">Applies To</label>
                        <select class="form-select" id="holidayScope">
                            <option value="All Branches" data-i18n="scope_all_branches">All Branches</option>
                            <option value="Head Office" data-i18n="scope_head_office">Head Office</option>
                            <option value="Selected Branch" data-i18n="scope_selected_branch">Selected Branch</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="holidayStatus" checked>
                        </div>
                        <label class="form-label m-0" for="holidayStatus" data-i18n="enable_this_holiday">Enable this holiday</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveHoliday()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="leaveModalTitle"><i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave_type">Leave Type</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leaveId">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label"><span data-i18n="leave_type_name">Leave Type Name</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="leaveName" data-i18n="leave_name_placeholder" placeholder="e.g., Sick Leave">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><span data-i18n="code">Code</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="leaveCode" data-i18n="leave_code_placeholder" placeholder="e.g., SICK">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="quota_days_per_year">Quota (days/yr)</label>
                        <input type="number" min="0" class="form-control" id="leaveQuota" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="pay_type">Pay Type</label>
                        <select class="form-select" id="leavePayType">
                            <option value="Paid" data-i18n="leave_pay_paid">Paid</option>
                            <option value="Unpaid" data-i18n="leave_pay_unpaid">Unpaid</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="leaveCarryOver">
                        </div>
                        <label class="form-label m-0" for="leaveCarryOver" data-i18n="allow_carry_over">Allow carrying over unused days to next year</label>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="leaveStatus" checked>
                        </div>
                        <label class="form-label m-0" for="leaveStatus" data-i18n="enable_this_leave_type">Enable this leave type</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveLeave()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="otModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="otModalTitle"><i class="fa-solid fa-coins"></i> <span data-i18n="ot_rate">OT Rate</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="otId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label"><span data-i18n="ot_name">OT Name</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="otName" data-i18n="ot_name_placeholder" placeholder="e.g., Weekday OT">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="applies_to">Applies To</label>
                        <select class="form-select" id="otScope">
                            <option value="Weekday" data-i18n="ot_scope_weekday">Weekday</option>
                            <option value="Weekend" data-i18n="ot_scope_weekend">Weekend</option>
                            <option value="Holiday" data-i18n="holiday">Holiday</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="multiplier_rate">Multiplier Rate (x)</label>
                        <input type="number" step="0.1" min="1" class="form-control" id="otMultiplier" value="1.5">
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="calculation_base">Calculation Base</label>
                        <select class="form-select" id="otBase">
                            <option value="Hourly" data-i18n="ot_base_hourly">Hourly</option>
                            <option value="Daily" data-i18n="ot_base_daily">Daily</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="otStatus" checked>
                        </div>
                        <label class="form-label m-0" for="otStatus" data-i18n="enable_this_rate">Enable this rate</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveOt()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center pt-4">
                <div class="confirm-icon"><i class="fa-solid fa-trash"></i></div>
                <h6 class="fw-bold mb-1" data-i18n="confirm_delete_title">Confirm Delete</h6>
                <p class="text-muted small mb-0"><span data-i18n="delete_confirm_question">Do you want to delete</span> "<span id="deleteTargetName"></span>"?<br><span data-i18n="delete_irreversible_note">This action cannot be undone.</span></p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button class="btn btn-light px-3" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-danger px-3" onclick="confirmDelete()"><i class="fa-solid fa-trash me-1"></i><span data-i18n="delete">Delete</span></button>
            </div>
        </div>
    </div>
</div>
<script>
    let shifts = [
        {id:1, name:"กะเช้า", code:"SH-01", desc:"กะทำงานปกติเวลาเช้า", start:"08:00", end:"17:00", modified:"2026-07-01", status:1},
        {id:2, name:"กะบ่าย", code:"SH-02", desc:"กะทำงานช่วงบ่ายถึงค่ำ", start:"14:00", end:"23:00", modified:"2026-06-28", status:1},
        {id:3, name:"กะดึก", code:"SH-03", desc:"กะทำงานกลางคืน", start:"23:00", end:"08:00", modified:"2026-06-15", status:0},
    ];
    let holidays = [
        {id:1, name:"วันขึ้นปีใหม่", date:"2026-01-01", type:"Annual", scope:"All Branches", status:1},
        {id:2, name:"วันสงกรานต์", date:"2026-04-13", type:"Annual", scope:"All Branches", status:1},
        {id:3, name:"วันหยุดพิเศษบริษัท", date:"2026-08-12", type:"One-time", scope:"Head Office", status:1},
    ];
    let leaveTypes = [
        {id:1, name:"ลาป่วย", code:"SICK", quota:30, payType:"Paid", carryOver:0, status:1},
        {id:2, name:"ลาพักร้อน", code:"ANNUAL", quota:10, payType:"Paid", carryOver:1, status:1},
        {id:3, name:"ลากิจ", code:"PERSONAL", quota:5, payType:"Unpaid", carryOver:0, status:1},
    ];
    let otRates = [
        {id:1, name:"OT วันธรรมดา", scope:"Weekday", multiplier:1.5, base:"Hourly", status:1},
        {id:2, name:"OT วันหยุดสุดสัปดาห์", scope:"Weekend", multiplier:2.0, base:"Hourly", status:1},
        {id:3, name:"OT วันหยุดนักขัตฤกษ์", scope:"Holiday", multiplier:3.0, base:"Daily", status:1},
    ];
    let nextId = {shift:4, holiday:4, leave:4, ot:4};
    let deleteContext = null;
    function statusSwitch(checked, onchange){
        return `<div class="form-check form-switch d-flex justify-content-center m-0">
            <input class="form-check-input" type="checkbox" ${checked ? 'checked':''} onchange="${onchange}">
        </div>`;
    }
    function actionBtns(editFn, delFn){
        return `
        <div class="btn-group border rounded-3 bg-white">
            <button class="btn btn-link text-warning" onclick="${editFn}"><i class="fa-solid fa-pen-to-square"></i></button>
            <button class="btn btn-link py-1 text-danger border-start" onclick="${delFn}"><i class="fa-solid fa-trash-can"></i></button>
        </div>`;
    }
    function askDelete(type, id, name){
        deleteContext = {type, id, name};
        $('#deleteTargetName').text(name);
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
    function confirmDelete(){
        if(!deleteContext) return;
        const {type, id} = deleteContext;
        if(type==='shift'){ shifts = shifts.filter(s=>s.id!==id); renderShift(); }
        if(type==='holiday'){ holidays = holidays.filter(s=>s.id!==id); renderHoliday(); }
        if(type==='leave'){ leaveTypes = leaveTypes.filter(s=>s.id!==id); renderLeave(); }
        if(type==='ot'){ otRates = otRates.filter(s=>s.id!==id); renderOt(); }
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        showSuccess(langData['delete_success'] || 'Deleted successfully.');
        deleteContext = null;
    }
    function fmtDate(d){
        const dt = new Date(d+"T00:00:00");
        return dt.toLocaleDateString(currentLang === 'th' ? 'th-TH' : 'en-US', {year:'numeric', month:'short', day:'numeric'});
    }
    let dtShift, dtHoliday, dtLeave, dtOt;
    function renderShift(){
        const rows = shifts.map(s => [
            `<div class="row-name">${s.name}</div>`,
            `<span class="row-code">${s.code}</span>`,
            `<span class="text-faint">${s.desc || '-'}</span>`,
            `<span class="text-faint"><i class="fa-regular fa-clock me-1"></i>${s.start} - ${s.end}</span>`,
            `<span class="text-faint">${fmtDate(s.modified)}</span>`,
            statusSwitch(s.status, `toggleShiftStatus(${s.id})`),
            actionBtns(`openShiftModal(${s.id})`, `askDelete('shift', ${s.id}, '${s.name.replace(/'/g,"\\'")}')`)
        ]);
        if(dtShift) dtShift.destroy();
        dtShift = $('#tb_shift').DataTable({
            data: rows,
            columns:[{},{},{},{},{},{className:"text-center"},{className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{emptyTable: langData['no_shifts_yet'] || 'No shifts have been added yet.'},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#shiftSearch').off('keyup').on('keyup', function(){ dtShift.search(this.value).draw(); });
    }
    function toggleShiftStatus(id){
        const s = shifts.find(x=>x.id===id); s.status = s.status ? 0 : 1;
        renderShift();
    }
    function openShiftModal(id){
        $('#shiftModalTitle').html(`<i class="fa-regular fa-calendar-days"></i> <span data-i18n="shift">${langData['shift'] || 'Shift'}</span>`);
        if(id){
            const s = shifts.find(x=>x.id===id);
            $('#shiftId').val(s.id); $('#shiftName').val(s.name); $('#shiftCode').val(s.code);
            $('#shiftDesc').val(s.desc); $('#shiftStart').val(s.start); $('#shiftEnd').val(s.end);
            $('#shiftStatus').prop('checked', !!s.status);
        } else {
            $('#shiftId').val(''); $('#shiftName').val(''); $('#shiftCode').val('');
            $('#shiftDesc').val(''); $('#shiftStart').val('08:00'); $('#shiftEnd').val('17:00');
            $('#shiftStatus').prop('checked', true);
        }
        new bootstrap.Modal(document.getElementById('shiftModal')).show();
    }
    function saveShift(){
        const name = $('#shiftName').val().trim(), code = $('#shiftCode').val().trim();
        if(!name || !code){ showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
        const id = $('#shiftId').val();
        const payload = {name, code, desc:$('#shiftDesc').val().trim(), start:$('#shiftStart').val(), end:$('#shiftEnd').val(), status:$('#shiftStatus').is(':checked')?1:0, modified:new Date().toISOString().slice(0,10)};
        if(id){
            const s = shifts.find(x=>x.id==id); Object.assign(s, payload);
        } else {
            shifts.push({id: nextId.shift++, ...payload});
        }
        showSuccess(langData['save_success'] || 'Saved successfully.');
        bootstrap.Modal.getInstance(document.getElementById('shiftModal')).hide();
        renderShift();
    }
    function holidayTypeLabel(type){
        return type==='Annual' ? (langData['holiday_type_annual'] || 'Annual') : (langData['holiday_type_onetime'] || 'One-time');
    }
    function holidayScopeLabel(scope){
        if(scope==='All Branches') return langData['scope_all_branches'] || 'All Branches';
        if(scope==='Head Office') return langData['scope_head_office'] || 'Head Office';
        return langData['scope_selected_branch'] || 'Selected Branch';
    }
    function renderHoliday(){
        const rows = holidays.map(h => [
            `<div class="row-name">${h.name}</div>`,
            `<span class="text-faint"><i class="fa-regular fa-calendar me-1"></i>${fmtDate(h.date)}</span>`,
            h.type==='Annual' ? `<span class="badge-soft badge-paid">${holidayTypeLabel(h.type)}</span>` : `<span class="badge-soft badge-unpaid">${holidayTypeLabel(h.type)}</span>`,
            `<span class="text-faint">${holidayScopeLabel(h.scope)}</span>`,
            statusSwitch(h.status, `toggleHolidayStatus(${h.id})`),
            actionBtns(`openHolidayModal(${h.id})`, `askDelete('holiday', ${h.id}, '${h.name.replace(/'/g,"\\'")}')`)
        ]);
        if(dtHoliday) dtHoliday.destroy();
        dtHoliday = $('#tb_holiday').DataTable({
            data: rows,
            columns:[{},{},{},{},{className:"text-center"},{className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{emptyTable: langData['no_holidays_yet'] || 'No holidays have been added yet.'},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#holidaySearch').off('keyup').on('keyup', function(){ dtHoliday.search(this.value).draw(); });
    }
    function toggleHolidayStatus(id){
        const h = holidays.find(x=>x.id===id); h.status = h.status ? 0 : 1;
        renderHoliday();
    }
    function openHolidayModal(id){
        $('#holidayModalTitle').html(`<i class="fa-solid fa-calendar-day"></i> <span data-i18n="holiday">${langData['holiday'] || 'Holiday'}</span>`);
        if(id){
            const h = holidays.find(x=>x.id===id);
            $('#holidayId').val(h.id); $('#holidayName').val(h.name); $('#holidayDate').val(h.date);
            $('#holidayType').val(h.type); $('#holidayScope').val(h.scope);
            $('#holidayStatus').prop('checked', !!h.status);
        } else {
            $('#holidayId').val(''); $('#holidayName').val(''); $('#holidayDate').val('');
            $('#holidayType').val('Annual'); $('#holidayScope').val('All Branches');
            $('#holidayStatus').prop('checked', true);
        }
        new bootstrap.Modal(document.getElementById('holidayModal')).show();
    }
    function saveHoliday(){
        const name = $('#holidayName').val().trim(), date = $('#holidayDate').val();
        if(!name || !date){ showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
        const id = $('#holidayId').val();
        const payload = {name, date, type:$('#holidayType').val(), scope:$('#holidayScope').val(), status:$('#holidayStatus').is(':checked')?1:0};
        if(id){
            const h = holidays.find(x=>x.id==id); Object.assign(h, payload);
        } else {
            holidays.push({id: nextId.holiday++, ...payload});
        }
        showSuccess(langData['save_success'] || 'Saved successfully.');
        bootstrap.Modal.getInstance(document.getElementById('holidayModal')).hide();
        renderHoliday();
    }
    function renderLeave(){
        const rows = leaveTypes.map(l => [
            `<div class="row-name">${l.name}</div>`,
            `<span class="row-code">${l.code}</span>`,
            `<span class="text-faint">${l.quota} ${langData['days_unit'] || 'days'}</span>`,
            l.payType==='Paid' ? `<span class="badge-soft badge-paid">${langData['leave_pay_paid'] || 'Paid'}</span>` : `<span class="badge-soft badge-unpaid">${langData['leave_pay_unpaid'] || 'Unpaid'}</span>`,
            l.carryOver ? `<span class="text-faint"><i class="fa-solid fa-check text-success me-1"></i>${langData['allowed'] || 'Allowed'}</span>` : `<span class="text-faint">-</span>`,
            statusSwitch(l.status, `toggleLeaveStatus(${l.id})`),
            actionBtns(`openLeaveModal(${l.id})`, `askDelete('leave', ${l.id}, '${l.name.replace(/'/g,"\\'")}')`)
        ]);
        if(dtLeave) dtLeave.destroy();
        dtLeave = $('#tb_leave').DataTable({
            data: rows,
            columns:[{},{},{},{},{},{className:"text-center"},{className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{emptyTable: langData['no_leave_types_yet'] || 'No leave types have been added yet.'},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#leaveSearch').off('keyup').on('keyup', function(){ dtLeave.search(this.value).draw(); });
    }
    function toggleLeaveStatus(id){
        const l = leaveTypes.find(x=>x.id===id); l.status = l.status ? 0 : 1;
        renderLeave();
    }
    function openLeaveModal(id){
        $('#leaveModalTitle').html(`<i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave_type">${langData['leave_type'] || 'Leave Type'}</span>`);
        if(id){
            const l = leaveTypes.find(x=>x.id===id);
            $('#leaveId').val(l.id); $('#leaveName').val(l.name); $('#leaveCode').val(l.code);
            $('#leaveQuota').val(l.quota); $('#leavePayType').val(l.payType);
            $('#leaveCarryOver').prop('checked', !!l.carryOver);
            $('#leaveStatus').prop('checked', !!l.status);
        } else {
            $('#leaveId').val(''); $('#leaveName').val(''); $('#leaveCode').val('');
            $('#leaveQuota').val(0); $('#leavePayType').val('Paid');
            $('#leaveCarryOver').prop('checked', false);
            $('#leaveStatus').prop('checked', true);
        }
        new bootstrap.Modal(document.getElementById('leaveModal')).show();
    }
    function saveLeave(){
        const name = $('#leaveName').val().trim(), code = $('#leaveCode').val().trim();
        if(!name || !code){ showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
        const id = $('#leaveId').val();
        const payload = {name, code, quota:parseInt($('#leaveQuota').val())||0, payType:$('#leavePayType').val(), carryOver:$('#leaveCarryOver').is(':checked')?1:0, status:$('#leaveStatus').is(':checked')?1:0};
        if(id){
            const l = leaveTypes.find(x=>x.id==id); Object.assign(l, payload);
        } else {
            leaveTypes.push({id: nextId.leave++, ...payload});
        }
        showSuccess(langData['save_success'] || 'Saved successfully.');
        bootstrap.Modal.getInstance(document.getElementById('leaveModal')).hide();
        renderLeave();
    }
    function otScopeLabel(scope){
        if(scope==='Weekday') return langData['ot_scope_weekday'] || 'Weekday';
        if(scope==='Weekend') return langData['ot_scope_weekend'] || 'Weekend';
        return langData['holiday'] || 'Holiday';
    }
    function scopeBadge(scope){
        const cls = scope==='Weekday' ? 'badge-weekday' : (scope==='Weekend' ? 'badge-weekend' : 'badge-holiday');
        return `<span class="badge-soft ${cls}">${otScopeLabel(scope)}</span>`;
    }
    function renderOt(){
        const rows = otRates.map(o => [
            `<div class="row-name">${o.name}</div>`,
            scopeBadge(o.scope),
            `<span class="row-code">${o.multiplier.toFixed(1)}x</span>`,
            `<span class="text-faint">${o.base === 'Hourly' ? (langData['ot_base_hourly'] || 'Hourly') : (langData['ot_base_daily'] || 'Daily')}</span>`,
            statusSwitch(o.status, `toggleOtStatus(${o.id})`),
            actionBtns(`openOtModal(${o.id})`, `askDelete('ot', ${o.id}, '${o.name.replace(/'/g,"\\'")}')`)
        ]);
        if(dtOt) dtOt.destroy();
        dtOt = $('#tb_ot').DataTable({
            data: rows,
            columns:[{},{},{},{},{className:"text-center"},{className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{emptyTable: langData['no_ot_rates_yet'] || 'No OT rates have been added yet.'},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#otSearch').off('keyup').on('keyup', function(){ dtOt.search(this.value).draw(); });
    }
    function toggleOtStatus(id){
        const o = otRates.find(x=>x.id===id); o.status = o.status ? 0 : 1;
        renderOt();
    }
    function openOtModal(id){
        $('#otModalTitle').html(`<i class="fa-solid fa-coins"></i> <span data-i18n="ot_rate">${langData['ot_rate'] || 'OT Rate'}</span>`);
        if(id){
            const o = otRates.find(x=>x.id===id);
            $('#otId').val(o.id); $('#otName').val(o.name); $('#otScope').val(o.scope);
            $('#otMultiplier').val(o.multiplier); $('#otBase').val(o.base);
            $('#otStatus').prop('checked', !!o.status);
        } else {
            $('#otId').val(''); $('#otName').val(''); $('#otScope').val('Weekday');
            $('#otMultiplier').val(1.5); $('#otBase').val('Hourly');
            $('#otStatus').prop('checked', true);
        }
        new bootstrap.Modal(document.getElementById('otModal')).show();
    }
    function saveOt(){
        const name = $('#otName').val().trim();
        if(!name){ showWarning(langData['required_star_message'] || 'Please fill all fields marked with *'); return; }
        const id = $('#otId').val();
        const payload = {name, scope:$('#otScope').val(), multiplier:parseFloat($('#otMultiplier').val())||1, base:$('#otBase').val(), status:$('#otStatus').is(':checked')?1:0};
        if(id){
            const o = otRates.find(x=>x.id==id); Object.assign(o, payload);
        } else {
            otRates.push({id: nextId.ot++, ...payload});
        }
        showSuccess(langData['save_success'] || 'Saved successfully.');
        bootstrap.Modal.getInstance(document.getElementById('otModal')).hide();
        renderOt();
    }
    $(function(){
        renderShift();
        renderHoliday();
        renderLeave();
        renderOt();
    });
</script>