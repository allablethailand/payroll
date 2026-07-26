<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="time_and_leave">Time & Leave</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="manual_time_entry">Manual Time Entry</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-pen-to-square"></i>
            <span data-i18n="manual_time_entry">Manual Time Entry</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="manual_time_entry_description">Manually record attendance, leave, and overtime for employees when there is no HR system integration.</p>
    </div>
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance-pane" type="button" role="tab" aria-controls="attendance-pane" aria-selected="true">
                <i class="fa-solid fa-clock me-2"></i><span data-i18n="attendance">Attendance</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="leave-tab" data-bs-toggle="tab" data-bs-target="#leave-pane" type="button" role="tab" aria-controls="leave-pane" aria-selected="false">
                <i class="fa-regular fa-calendar-check me-2"></i><span data-i18n="leave">Leave</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="overtime-tab" data-bs-toggle="tab" data-bs-target="#overtime-pane" type="button" role="tab" aria-controls="overtime-pane" aria-selected="false">
                <i class="fa-solid fa-stopwatch me-2"></i><span data-i18n="overtime">Overtime</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
        <div class="tab-pane fade show active" id="attendance-pane" role="tabpanel" aria-labelledby="attendance-tab" tabindex="0">
            <div class="row mt-5 mb-3 g-2">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="employee">Employee</label>
                    <select class="form-select select2-remote" id="filter_att_employee" data-api="/api/employee.report_to.get" data-type=""></select>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="filter_date_from">From</label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_att_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="filter_date_to">To</label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_att_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
            <div class="mb-5 table-responsive">
                <table class="table" id="tb_attendance" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="work_date">Work Date</th>
                            <th data-i18n="shift">Shift</th>
                            <th data-i18n="clock_in">Clock In</th>
                            <th data-i18n="clock_out">Clock Out</th>
                            <th data-i18n="actual_hours">Actual Hours</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th data-i18n="source" class="text-center">Source</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="leave-pane" role="tabpanel" aria-labelledby="leave-tab" tabindex="0">
            <div class="row mt-5 mb-3 g-2">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="employee">Employee</label>
                    <select class="form-select select2-remote" id="filter_leave_employee" data-api="/api/employee.report_to.get" data-type=""></select>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="filter_date_from">From</label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_leave_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="filter_date_to">To</label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_leave_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
            <div class="mb-5 table-responsive">
                <table class="table" id="tb_leave" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="leave_type">Leave Type</th>
                            <th data-i18n="start_date">Start Date</th>
                            <th data-i18n="end_date">End Date</th>
                            <th data-i18n="total_days">Total Days</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th data-i18n="source" class="text-center">Source</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="overtime-pane" role="tabpanel" aria-labelledby="overtime-tab" tabindex="0">
            <div class="row mt-5 mb-3 g-2">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="employee">Employee</label>
                    <select class="form-select select2-remote" id="filter_ot_employee" data-api="/api/employee.report_to.get" data-type=""></select>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="filter_date_from">From</label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_ot_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1" data-i18n="filter_date_to">To</label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="filter_ot_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
            <div class="mb-5 table-responsive">
                <table class="table" id="tb_overtime" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="ot_rate">OT Rate</th>
                            <th data-i18n="ot_date">OT Date</th>
                            <th data-i18n="hours">Hours</th>
                            <th data-i18n="amount">Amount</th>
                            <th data-i18n="status" class="text-center">Status</th>
                            <th data-i18n="source" class="text-center">Source</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="attendanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="attendanceModalTitle"><i class="fa-solid fa-clock"></i> <span data-i18n="attendance">Attendance</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="attendanceId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="attendanceEmployee" data-api="/api/employee.report_to.get" data-type=""></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="work_date">Work Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="attendanceWorkDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="shift">Shift</label>
                        <select class="form-select select2-remote" id="attendanceShift" data-api="/api/shift.options" data-type="shift"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="status">Status</label>
                        <select class="form-select select2-static" id="attendanceStatus" data-option-keys="status_present,status_absent,status_leave,holiday" data-option-values="present,absent,leave,holiday"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="clock_in">Clock In</label>
                        <input type="time" class="form-control" id="attendanceClockIn">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="clock_out">Clock Out</label>
                        <input type="time" class="form-control" id="attendanceClockOut">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="late_minutes">Late (min)</label>
                        <input type="number" min="0" class="form-control" id="attendanceLateMinutes" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="early_leave_minutes">Early Leave (min)</label>
                        <input type="number" min="0" class="form-control" id="attendanceEarlyMinutes" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveAttendance()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="leaveModalTitle"><i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave">Leave</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leaveId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="leaveEmployee" data-api="/api/employee.report_to.get" data-type=""></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="leave_type">Leave Type</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="leaveType" data-api="/api/leave-type.options" data-type="leave_type"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="start_date">Start Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="leaveStartDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="end_date">End Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="leaveEndDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="total_days">Total Days</span> <span class="text-danger">*</span></label>
                        <input type="number" min="0.5" step="0.5" class="form-control required" id="leaveTotalDays">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="status">Status</label>
                        <select class="form-select select2-static" id="leaveStatus" data-option-keys="status_pending,status_approved,status_rejected,cancelled" data-option-values="pending,approved,rejected,cancelled"></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="reason">Reason</label>
                        <textarea class="form-control" id="leaveReason" rows="2"></textarea>
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

<div class="modal fade" id="overtimeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="overtimeModalTitle"><i class="fa-solid fa-stopwatch"></i> <span data-i18n="overtime">Overtime</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="overtimeId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="overtimeEmployee" data-api="/api/employee.report_to.get" data-type=""></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="ot_rate">OT Rate</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="overtimeRate" data-api="/api/ot-rate.options" data-type="ot_rate"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="ot_date">OT Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="overtimeDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="hours">Hours</span> <span class="text-danger">*</span></label>
                        <input type="number" min="0.5" step="0.5" class="form-control required" id="overtimeHours">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="amount">Amount</label>
                        <input type="number" min="0" step="0.01" class="form-control" id="overtimeAmount">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="status">Status</label>
                        <select class="form-select select2-static" id="overtimeStatus" data-option-keys="status_pending,status_approved,status_rejected" data-option-values="pending,approved,rejected"></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveOvertime()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="manualEntryDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center pt-4">
                <div class="confirm-icon"><i class="fa-solid fa-trash"></i></div>
                <h6 class="fw-bold mb-1" data-i18n="confirm_delete_title">Confirm Delete</h6>
                <p class="text-muted small mb-0"><span data-i18n="delete_confirm_question">Do you want to delete</span> "<span id="manualEntryDeleteTargetName"></span>"?<br><span data-i18n="delete_irreversible_note">This action cannot be undone.</span></p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button class="btn btn-light px-3" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-danger px-3" onclick="confirmManualEntryDelete()"><i class="fa-solid fa-trash me-1"></i><span data-i18n="delete">Delete</span></button>
            </div>
        </div>
    </div>
</div>
<script src="<?=asset('public/js/manual-entry/index.js')?>"></script>
