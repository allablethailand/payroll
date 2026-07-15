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
                    <input type="text" id="shiftSearch" class="form-control form-control-sm" placeholder="ค้นหากะการทำงาน...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openShiftModal()"><i class="fa-solid fa-plus"></i>เพิ่มกะการทำงาน</button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_shift" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="shift_name">Shift Name</th>
                            <th data-i18n="shift_code">Shift Code</th>
                            <th data-i18n="description">Description</th>
                            <th data-i18n="shift">Time</th>
                            <th data-i18n="last_modified">Last Modified</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th class="text-end">Actions</th>
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
                    <input type="text" id="holidaySearch" class="form-control form-control-sm" placeholder="ค้นหาวันหยุด...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openHolidayModal()"><i class="fa-solid fa-plus"></i>เพิ่มวันหยุด</button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_holiday" style="width:100%">
                    <thead>
                        <tr>
                            <th>Holiday Name</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Applies To</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
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
                    <input type="text" id="leaveSearch" class="form-control form-control-sm" placeholder="ค้นหาประเภทการลา...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openLeaveModal()"><i class="fa-solid fa-plus"></i>เพิ่มประเภทการลา</button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_leave" style="width:100%">
                    <thead>
                        <tr>
                            <th>Leave Type</th>
                            <th>Code</th>
                            <th>Quota (days/yr)</th>
                            <th>Pay Type</th>
                            <th>Carry Over</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
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
                    <input type="text" id="otSearch" class="form-control form-control-sm" placeholder="ค้นหาอัตรา OT...">
                </div>
                <button class="btn btn-primary btn-sm" onclick="openOtModal()"><i class="fa-solid fa-plus"></i>เพิ่มอัตรา OT</button>
            </div>
            <div class="table-responsive">
                <table class="table" id="tb_ot" style="width:100%">
                    <thead>
                        <tr>
                            <th>OT Name</th>
                            <th>Applies To</th>
                            <th>Multiplier</th>
                            <th>Calculation Base</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
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
                <h6 class="modal-title" id="shiftModalTitle"><i class="fa-regular fa-calendar-days"></i>เพิ่มกะการทำงาน</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="shiftId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">ชื่อกะ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="shiftName" placeholder="เช่น กะเช้า">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">รหัสกะ <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="shiftCode" placeholder="เช่น SH-01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">เวลาเข้างาน</label>
                        <input type="time" class="form-control" id="shiftStart" value="08:00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">เวลาออกงาน</label>
                        <input type="time" class="form-control" id="shiftEnd" value="17:00">
                    </div>
                    <div class="col-12">
                        <label class="form-label">คำอธิบาย</label>
                        <textarea class="form-control" id="shiftDesc" rows="2" placeholder="รายละเอียดเพิ่มเติม"></textarea>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="shiftStatus" checked>
                        </div>
                        <label class="form-label m-0" for="shiftStatus">เปิดใช้งานกะนี้</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveShift()"><i class="fa-solid fa-check"></i>บันทึก</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="holidayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="holidayModalTitle"><i class="fa-solid fa-calendar-day"></i>เพิ่มวันหยุด</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="holidayId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">ชื่อวันหยุด <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="holidayName" placeholder="เช่น วันสงกรานต์">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">วันที่ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="holidayDate">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ประเภท</label>
                        <select class="form-select" id="holidayType">
                            <option value="Annual">ประจำปี (Annual)</option>
                            <option value="One-time">ครั้งเดียว (One-time)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">มีผลกับ</label>
                        <select class="form-select" id="holidayScope">
                            <option value="All Branches">ทุกสาขา</option>
                            <option value="Head Office">สำนักงานใหญ่</option>
                            <option value="Selected Branch">สาขาที่เลือก</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="holidayStatus" checked>
                        </div>
                        <label class="form-label m-0" for="holidayStatus">เปิดใช้งานวันหยุดนี้</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveHoliday()"><i class="fa-solid fa-check"></i>บันทึก</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="leaveModalTitle"><i class="fa-regular fa-calendar-check"></i>เพิ่มประเภทการลา</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leaveId">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label">ชื่อประเภทการลา <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="leaveName" placeholder="เช่น ลาป่วย">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">รหัส <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="leaveCode" placeholder="เช่น SICK">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">โควต้า (วัน/ปี)</label>
                        <input type="number" min="0" class="form-control" id="leaveQuota" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ประเภทค่าจ้าง</label>
                        <select class="form-select" id="leavePayType">
                            <option value="Paid">ลาแบบได้รับค่าจ้าง</option>
                            <option value="Unpaid">ลาแบบไม่ได้รับค่าจ้าง</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="leaveCarryOver">
                        </div>
                        <label class="form-label m-0" for="leaveCarryOver">อนุญาตให้ยกยอดวันลาไปปีถัดไป</label>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="leaveStatus" checked>
                        </div>
                        <label class="form-label m-0" for="leaveStatus">เปิดใช้งานประเภทการลานี้</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveLeave()"><i class="fa-solid fa-check"></i>บันทึก</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="otModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="otModalTitle"><i class="fa-solid fa-coins"></i>เพิ่มอัตรา OT</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="otId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">ชื่ออัตรา OT <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="otName" placeholder="เช่น OT วันธรรมดา">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">มีผลกับ</label>
                        <select class="form-select" id="otScope">
                            <option value="Weekday">วันธรรมดา</option>
                            <option value="Weekend">วันหยุดสุดสัปดาห์</option>
                            <option value="Holiday">วันหยุดนักขัตฤกษ์</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">อัตราคูณ (x)</label>
                        <input type="number" step="0.1" min="1" class="form-control" id="otMultiplier" value="1.5">
                    </div>
                    <div class="col-12">
                        <label class="form-label">ฐานคำนวณ</label>
                        <select class="form-select" id="otBase">
                            <option value="Hourly">รายชั่วโมง</option>
                            <option value="Daily">รายวัน</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="otStatus" checked>
                        </div>
                        <label class="form-label m-0" for="otStatus">เปิดใช้งานอัตรานี้</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-primary" onclick="saveOt()"><i class="fa-solid fa-check"></i>บันทึก</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center pt-4">
                <div class="confirm-icon"><i class="fa-solid fa-trash"></i></div>
                <h6 class="fw-bold mb-1">ยืนยันการลบ</h6>
                <p class="text-muted small mb-0">คุณต้องการลบ "<span id="deleteTargetName"></span>" ใช่หรือไม่?<br>การลบไม่สามารถย้อนกลับได้</p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button class="btn btn-light px-3" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-danger px-3" onclick="confirmDelete()"><i class="fa-solid fa-trash me-1"></i>ลบ</button>
            </div>
        </div>
    </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-4">
    <div id="opToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body fw-semibold"><i class="fa-solid fa-circle-check text-success me-2"></i><span id="opToastMsg">Saved</span></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
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
    function toast(msg){
        $('#opToastMsg').text(msg);
        new bootstrap.Toast(document.getElementById('opToast'), {delay:2200}).show();
    }
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
        new bootstrap.Modal('#deleteModal').show();
    }
    function confirmDelete(){
        if(!deleteContext) return;
        const {type, id, name} = deleteContext;
        if(type==='shift'){ shifts = shifts.filter(s=>s.id!==id); renderShift(); }
        if(type==='holiday'){ holidays = holidays.filter(s=>s.id!==id); renderHoliday(); }
        if(type==='leave'){ leaveTypes = leaveTypes.filter(s=>s.id!==id); renderLeave(); }
        if(type==='ot'){ otRates = otRates.filter(s=>s.id!==id); renderOt(); }
        bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
        toast(`ลบ "${name}" เรียบร้อยแล้ว`);
        deleteContext = null;
    }
    function fmtDate(d){
        const dt = new Date(d+"T00:00:00");
        return dt.toLocaleDateString('th-TH', {year:'numeric', month:'short', day:'numeric'});
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
            columns:[{title:"Shift Name"},{title:"Code"},{title:"Description"},{title:"Time"},{title:"Last Modified"},{title:"Status",className:"text-center"},{title:"Actions",className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{search:"", searchPlaceholder:"Search...", emptyTable:"ยังไม่มีข้อมูลกะการทำงาน"},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#shiftSearch').off('keyup').on('keyup', function(){ dtShift.search(this.value).draw(); });
    }
    function toggleShiftStatus(id){
        const s = shifts.find(x=>x.id===id); s.status = s.status ? 0 : 1;
        toast(`${s.status ? 'เปิด' : 'ปิด'}ใช้งาน "${s.name}" แล้ว`);
        renderShift();
    }
    function openShiftModal(id){
        if(id){
            const s = shifts.find(x=>x.id===id);
            $('#shiftModalTitle').html('<i class="fa-regular fa-calendar-days"></i>แก้ไขกะการทำงาน');
            $('#shiftId').val(s.id); $('#shiftName').val(s.name); $('#shiftCode').val(s.code);
            $('#shiftDesc').val(s.desc); $('#shiftStart').val(s.start); $('#shiftEnd').val(s.end);
            $('#shiftStatus').prop('checked', !!s.status);
        } else {
            $('#shiftModalTitle').html('<i class="fa-regular fa-calendar-days"></i>เพิ่มกะการทำงาน');
            $('#shiftId').val(''); $('#shiftName').val(''); $('#shiftCode').val('');
            $('#shiftDesc').val(''); $('#shiftStart').val('08:00'); $('#shiftEnd').val('17:00');
            $('#shiftStatus').prop('checked', true);
        }
        new bootstrap.Modal('#shiftModal').show();
    }
    function saveShift(){
        const name = $('#shiftName').val().trim(), code = $('#shiftCode').val().trim();
        if(!name || !code){ toast('กรุณากรอกชื่อกะและรหัสกะ'); return; }
        const id = $('#shiftId').val();
        const payload = {name, code, desc:$('#shiftDesc').val().trim(), start:$('#shiftStart').val(), end:$('#shiftEnd').val(), status:$('#shiftStatus').is(':checked')?1:0, modified:new Date().toISOString().slice(0,10)};
        if(id){
            const s = shifts.find(x=>x.id==id); Object.assign(s, payload);
            toast('บันทึกการแก้ไขกะการทำงานแล้ว');
        } else {
            shifts.push({id: nextId.shift++, ...payload});
            toast('เพิ่มกะการทำงานใหม่แล้ว');
        }
        bootstrap.Modal.getInstance(document.getElementById('shiftModal')).hide();
        renderShift();
    }
    function renderHoliday(){
        const rows = holidays.map(h => [
            `<div class="row-name">${h.name}</div>`,
            `<span class="text-faint"><i class="fa-regular fa-calendar me-1"></i>${fmtDate(h.date)}</span>`,
            h.type==='Annual' ? `<span class="badge-soft badge-paid">ประจำปี</span>` : `<span class="badge-soft badge-unpaid">ครั้งเดียว</span>`,
            `<span class="text-faint">${h.scope}</span>`,
            statusSwitch(h.status, `toggleHolidayStatus(${h.id})`),
            actionBtns(`openHolidayModal(${h.id})`, `askDelete('holiday', ${h.id}, '${h.name.replace(/'/g,"\\'")}')`)
        ]);
        if(dtHoliday) dtHoliday.destroy();
        dtHoliday = $('#tb_holiday').DataTable({
            data: rows,
            columns:[{title:"Name"},{title:"Date"},{title:"Type"},{title:"Applies To"},{title:"Status",className:"text-center"},{title:"Actions",className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{search:"", searchPlaceholder:"Search...", emptyTable:"ยังไม่มีข้อมูลวันหยุด"},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#holidaySearch').off('keyup').on('keyup', function(){ dtHoliday.search(this.value).draw(); });
    }
    function toggleHolidayStatus(id){
        const h = holidays.find(x=>x.id===id); h.status = h.status ? 0 : 1;
        toast(`${h.status ? 'เปิด' : 'ปิด'}ใช้งาน "${h.name}" แล้ว`);
        renderHoliday();
    }
    function openHolidayModal(id){
        if(id){
            const h = holidays.find(x=>x.id===id);
            $('#holidayModalTitle').html('<i class="fa-solid fa-calendar-day"></i>แก้ไขวันหยุด');
            $('#holidayId').val(h.id); $('#holidayName').val(h.name); $('#holidayDate').val(h.date);
            $('#holidayType').val(h.type); $('#holidayScope').val(h.scope);
            $('#holidayStatus').prop('checked', !!h.status);
        } else {
            $('#holidayModalTitle').html('<i class="fa-solid fa-calendar-day"></i>เพิ่มวันหยุด');
            $('#holidayId').val(''); $('#holidayName').val(''); $('#holidayDate').val('');
            $('#holidayType').val('Annual'); $('#holidayScope').val('All Branches');
            $('#holidayStatus').prop('checked', true);
        }
        new bootstrap.Modal('#holidayModal').show();
    }
    function saveHoliday(){
        const name = $('#holidayName').val().trim(), date = $('#holidayDate').val();
        if(!name || !date){ toast('กรุณากรอกชื่อและวันที่ของวันหยุด'); return; }
        const id = $('#holidayId').val();
        const payload = {name, date, type:$('#holidayType').val(), scope:$('#holidayScope').val(), status:$('#holidayStatus').is(':checked')?1:0};
        if(id){
            const h = holidays.find(x=>x.id==id); Object.assign(h, payload);
            toast('บันทึกการแก้ไขวันหยุดแล้ว');
        } else {
            holidays.push({id: nextId.holiday++, ...payload});
            toast('เพิ่มวันหยุดใหม่แล้ว');
        }
        bootstrap.Modal.getInstance(document.getElementById('holidayModal')).hide();
        renderHoliday();
    }
    function renderLeave(){
        const rows = leaveTypes.map(l => [
            `<div class="row-name">${l.name}</div>`,
            `<span class="row-code">${l.code}</span>`,
            `<span class="text-faint">${l.quota} วัน</span>`,
            l.payType==='Paid' ? `<span class="badge-soft badge-paid">Paid</span>` : `<span class="badge-soft badge-unpaid">Unpaid</span>`,
            l.carryOver ? `<span class="text-faint"><i class="fa-solid fa-check text-success me-1"></i>อนุญาต</span>` : `<span class="text-faint">-</span>`,
            statusSwitch(l.status, `toggleLeaveStatus(${l.id})`),
            actionBtns(`openLeaveModal(${l.id})`, `askDelete('leave', ${l.id}, '${l.name.replace(/'/g,"\\'")}')`)
        ]);
        if(dtLeave) dtLeave.destroy();
        dtLeave = $('#tb_leave').DataTable({
            data: rows,
            columns:[{title:"Leave Type"},{title:"Code"},{title:"Quota"},{title:"Pay Type"},{title:"Carry Over"},{title:"Status",className:"text-center"},{title:"Actions",className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{search:"", searchPlaceholder:"Search...", emptyTable:"ยังไม่มีข้อมูลประเภทการลา"},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#leaveSearch').off('keyup').on('keyup', function(){ dtLeave.search(this.value).draw(); });
    }
    function toggleLeaveStatus(id){
        const l = leaveTypes.find(x=>x.id===id); l.status = l.status ? 0 : 1;
        toast(`${l.status ? 'เปิด' : 'ปิด'}ใช้งาน "${l.name}" แล้ว`);
        renderLeave();
    }
    function openLeaveModal(id){
        if(id){
            const l = leaveTypes.find(x=>x.id===id);
            $('#leaveModalTitle').html('<i class="fa-regular fa-calendar-check"></i>แก้ไขประเภทการลา');
            $('#leaveId').val(l.id); $('#leaveName').val(l.name); $('#leaveCode').val(l.code);
            $('#leaveQuota').val(l.quota); $('#leavePayType').val(l.payType);
            $('#leaveCarryOver').prop('checked', !!l.carryOver);
            $('#leaveStatus').prop('checked', !!l.status);
        } else {
            $('#leaveModalTitle').html('<i class="fa-regular fa-calendar-check"></i>เพิ่มประเภทการลา');
            $('#leaveId').val(''); $('#leaveName').val(''); $('#leaveCode').val('');
            $('#leaveQuota').val(0); $('#leavePayType').val('Paid');
            $('#leaveCarryOver').prop('checked', false);
            $('#leaveStatus').prop('checked', true);
        }
        new bootstrap.Modal('#leaveModal').show();
    }
    function saveLeave(){
        const name = $('#leaveName').val().trim(), code = $('#leaveCode').val().trim();
        if(!name || !code){ toast('กรุณากรอกชื่อและรหัสประเภทการลา'); return; }
        const id = $('#leaveId').val();
        const payload = {name, code, quota:parseInt($('#leaveQuota').val())||0, payType:$('#leavePayType').val(), carryOver:$('#leaveCarryOver').is(':checked')?1:0, status:$('#leaveStatus').is(':checked')?1:0};
        if(id){
            const l = leaveTypes.find(x=>x.id==id); Object.assign(l, payload);
            toast('บันทึกการแก้ไขประเภทการลาแล้ว');
        } else {
            leaveTypes.push({id: nextId.leave++, ...payload});
            toast('เพิ่มประเภทการลาใหม่แล้ว');
        }
        bootstrap.Modal.getInstance(document.getElementById('leaveModal')).hide();
        renderLeave();
    }
    function scopeBadge(scope){
        if(scope==='Weekday') return `<span class="badge-soft badge-weekday">Weekday</span>`;
        if(scope==='Weekend') return `<span class="badge-soft badge-weekend">Weekend</span>`;
        return `<span class="badge-soft badge-holiday">Holiday</span>`;
    }
    function renderOt(){
        const rows = otRates.map(o => [
            `<div class="row-name">${o.name}</div>`,
            scopeBadge(o.scope),
            `<span class="row-code">${o.multiplier.toFixed(1)}x</span>`,
            `<span class="text-faint">${o.base}</span>`,
            statusSwitch(o.status, `toggleOtStatus(${o.id})`),
            actionBtns(`openOtModal(${o.id})`, `askDelete('ot', ${o.id}, '${o.name.replace(/'/g,"\\'")}')`)
        ]);
        if(dtOt) dtOt.destroy();
        dtOt = $('#tb_ot').DataTable({
            data: rows,
            columns:[{title:"OT Name"},{title:"Applies To"},{title:"Multiplier"},{title:"Base"},{title:"Status",className:"text-center"},{title:"Actions",className:"text-end"}],
            ordering:false, lengthChange:false, pageLength:10,
            language:{search:"", searchPlaceholder:"Search...", emptyTable:"ยังไม่มีข้อมูลอัตรา OT"},
            dom:'t<"d-flex justify-content-between align-items-center mt-3"ip>'
        });
        $('#otSearch').off('keyup').on('keyup', function(){ dtOt.search(this.value).draw(); });
        }
        function toggleOtStatus(id){
        const o = otRates.find(x=>x.id===id); o.status = o.status ? 0 : 1;
        toast(`${o.status ? 'เปิด' : 'ปิด'}ใช้งาน "${o.name}" แล้ว`);
        renderOt();
    }
    function openOtModal(id){
        if(id){
            const o = otRates.find(x=>x.id===id);
            $('#otModalTitle').html('<i class="fa-solid fa-coins"></i>แก้ไขอัตรา OT');
            $('#otId').val(o.id); $('#otName').val(o.name); $('#otScope').val(o.scope);
            $('#otMultiplier').val(o.multiplier); $('#otBase').val(o.base);
            $('#otStatus').prop('checked', !!o.status);
        } else {
            $('#otModalTitle').html('<i class="fa-solid fa-coins"></i>เพิ่มอัตรา OT');
            $('#otId').val(''); $('#otName').val(''); $('#otScope').val('Weekday');
            $('#otMultiplier').val(1.5); $('#otBase').val('Hourly');
            $('#otStatus').prop('checked', true);
        }
        new bootstrap.Modal('#otModal').show();
    }
    function saveOt(){
        const name = $('#otName').val().trim();
        if(!name){ toast('กรุณากรอกชื่ออัตรา OT'); return; }
        const id = $('#otId').val();
        const payload = {name, scope:$('#otScope').val(), multiplier:parseFloat($('#otMultiplier').val())||1, base:$('#otBase').val(), status:$('#otStatus').is(':checked')?1:0};
        if(id){
            const o = otRates.find(x=>x.id==id); Object.assign(o, payload);
            toast('บันทึกการแก้ไขอัตรา OT แล้ว');
        } else {
            otRates.push({id: nextId.ot++, ...payload});
            toast('เพิ่มอัตรา OT ใหม่แล้ว');
        }
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