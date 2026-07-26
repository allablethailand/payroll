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
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="shift-tab" data-bs-toggle="tab" data-bs-target="#shift-pane" type="button" role="tab" aria-controls="shift-pane" aria-selected="true">
                <i class="fa-regular fa-calendar-days me-2"></i><span data-i18n="shift">Shift</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="holiday-tab" data-bs-toggle="tab" data-bs-target="#holiday-pane" type="button" role="tab" aria-controls="holiday-pane" aria-selected="false">
                <i class="fa-solid fa-calendar-day me-2"></i><span data-i18n="holiday">Holiday</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="leave-type-tab" data-bs-toggle="tab" data-bs-target="#leave-type-pane" type="button" role="tab" aria-controls="leave-type-pane" aria-selected="false">
                <i class="fa-regular fa-calendar-check me-2"></i><span data-i18n="leave_type">Leave Type</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="ot-rate-tab" data-bs-toggle="tab" data-bs-target="#ot-rate-pane" type="button" role="tab" aria-controls="ot-rate-pane" aria-selected="false">
                <i class="fa-solid fa-coins me-2"></i><span data-i18n="ot_rate">OT Rate</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="work-location-tab" data-bs-toggle="tab" data-bs-target="#work-location-pane" type="button" role="tab" aria-controls="work-location-pane" aria-selected="false">
                <i class="fa-solid fa-location-dot me-2"></i><span data-i18n="work_location">Work Location</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
        <div class="tab-pane fade show active" id="shift-pane" role="tabpanel" aria-labelledby="shift-tab" tabindex="0">
            <div class="mt-5 mb-5 table-responsive">
                <table class="table" id="tb_shift" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="shift_name">Shift Name</th>
                            <th data-i18n="shift_code">Shift Code</th>
                            <th data-i18n="time">Time</th>
                            <th data-i18n="work_location">Work Location</th>
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
            <div class="mt-5 mb-5 table-responsive">
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
            <div class="mt-5 mb-5 table-responsive">
                <table class="table" id="tb_leave" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="leave_type">Leave Type</th>
                            <th data-i18n="code">Code</th>
                            <th data-i18n="category">Category</th>
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
            <div class="mt-5 mb-5 table-responsive">
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
        <div class="tab-pane fade" id="work-location-pane" role="tabpanel" aria-labelledby="work-location-tab" tabindex="0">
            <div class="mt-5 mb-5 table-responsive">
                <table class="table" id="tb_work_location" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="location_name">Location Name</th>
                            <th data-i18n="code">Code</th>
                            <th data-i18n="address">Address</th>
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
                        <input type="text" class="form-control required" id="shiftName" data-i18n="shift_name_placeholder" placeholder="e.g., Morning Shift">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="shift_code">Shift Code</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="shiftCode" data-i18n="shift_code_placeholder" placeholder="e.g., SH-01">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="time_in">Time In</label>
                        <input type="time" class="form-control" id="shiftStart" value="08:00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="time_out">Time Out</label>
                        <input type="time" class="form-control" id="shiftEnd" value="17:00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="break_minutes">Break (minutes)</label>
                        <input type="number" min="0" class="form-control" id="shiftBreak" value="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="work_location">Work Location</label>
                        <select class="form-select select2-remote" id="shiftWorkLocation" data-api="/api/work-location.options" data-type="location"></select>
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
<div class="modal fade" id="shiftAssignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="shiftAssignModalTitle"><i class="fa-solid fa-user-check"></i> <span data-i18n="assign_employees">Assign Employees</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="shiftAssignId">
                <label class="form-label" data-i18n="applies_to_employees">Applies to Employees</label>
                <select class="form-select select2-remote" id="shiftAssignEmployees" multiple data-api="/api/employee.report_to.get" data-type="employee"></select>
                <p class="text-muted small mb-0 mt-2" data-i18n="assign_employees_hint">Employees selected here will be moved onto this shift. Deselecting someone removes them from this shift only, not from the company.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveShiftAssignment()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="workLocationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="workLocationModalTitle"><i class="fa-solid fa-location-dot"></i> <span data-i18n="work_location">Work Location</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="workLocationId">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label"><span data-i18n="location_name_th">Location Name (Thai)</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="workLocationNameTh" data-i18n="location_name_th_placeholder" placeholder="e.g., สำนักงานใหญ่">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><span data-i18n="code">Code</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="workLocationCode" data-i18n="location_code_placeholder" placeholder="e.g., HQ">
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="location_name_en">Location Name (English)</label>
                        <input type="text" class="form-control" id="workLocationNameEn" data-i18n="location_name_en_placeholder" placeholder="e.g., Head Office">
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="address">Address</label>
                        <textarea class="form-control" id="workLocationAddress" rows="2"></textarea>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="workLocationStatus" checked>
                        </div>
                        <label class="form-label m-0" for="workLocationStatus" data-i18n="enable_this_location">Enable this location</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveWorkLocation()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="holidayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="holidayModalTitle"><i class="fa-solid fa-calendar-day"></i> <span data-i18n="holiday">Holiday</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="holidayId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="holiday_name_th">Holiday Name (Thai)</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="holidayNameTh" data-i18n="holiday_name_th_placeholder" placeholder="e.g., วันขึ้นปีใหม่">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="holiday_name_en">Holiday Name (English)</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="holidayNameEn" data-i18n="holiday_name_en_placeholder" placeholder="e.g., New Year's Day">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="date">Date</span> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control required" id="holidayDate">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="type">Type</label>
                        <select class="form-select select2-static" id="holidayRecurring" data-option-keys="recurring_every_year,one_time_only" data-option-values="1,0">
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="assignment_mode">Assignment Mode</label>
                        <select class="form-select select2-static" id="holidayMode" data-option-keys="include_mode,exclude_mode" data-option-values="include,exclude">
                        </select>
                        <p class="text-muted small mb-0 mt-1" id="holidayModeHint"></p>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="applies_to_shifts">Applies to Shifts</label>
                        <select class="form-select select2-remote" id="holidayScopeShift" multiple data-api="/api/shift.options" data-type="shift"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="applies_to_departments">Applies to Departments</label>
                        <select class="form-select select2-remote" id="holidayScopeDepartment" multiple data-api="/api/department.get" data-type="department"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="applies_to_positions">Applies to Positions</label>
                        <select class="form-select select2-remote" id="holidayScopePosition" multiple data-api="/api/position.get" data-type="position"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="applies_to_employees">Applies to Employees</label>
                        <select class="form-select select2-remote" id="holidayScopeEmployee" multiple data-api="/api/employee.report_to.get" data-type="employee"></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="remark">Remark</label>
                        <textarea class="form-control" id="holidayRemark" rows="2" data-i18n="remark_placeholder" placeholder="Optional notes"></textarea>
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
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="leaveModalTitle"><i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave_type">Leave Type</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leaveId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="leave_type_name">Leave Type Name</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="leaveNameTh" data-i18n="holiday_name_th_placeholder" placeholder="e.g., ลาป่วย">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">&nbsp;</label>
                        <input type="text" class="form-control required" id="leaveNameEn" placeholder="e.g., Sick Leave">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="code">Code</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="leaveCode" data-i18n="leave_code_placeholder" placeholder="e.g., SICK">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="category">Category</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="leaveCategory" data-api="/api/leave-category.options" data-type="leave_category"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="quota_type">Quota Type</label>
                        <select class="form-select select2-static" id="leaveQuotaType" data-option-keys="quota_type_fixed,quota_type_prorate" data-option-values="fixed,prorate"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="quota_amount">Quota Amount</label>
                        <input type="number" min="0" step="0.5" class="form-control" id="leaveQuota" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="unit_type">Unit</label>
                        <select class="form-select select2-static" id="leaveUnitType" data-option-keys="unit_type_day,unit_type_hour,unit_type_half_day" data-option-values="day,hour,half_day"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="pay_type">Pay Type</label>
                        <select class="form-select select2-static" id="leavePayType" data-option-keys="leave_pay_paid,leave_pay_unpaid" data-option-values="1,0"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="gender_restriction">Gender Restriction</label>
                        <select class="form-select select2-static" id="leaveGenderRestriction" data-option-keys="gender_restriction_all,gender_restriction_male,gender_restriction_female" data-option-values="all,male,female"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="min_service_days">Minimum Service (days)</label>
                        <input type="number" min="0" class="form-control" id="leaveMinServiceDays" placeholder="-">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="advance_notice_days">Advance Notice (days)</label>
                        <input type="number" min="0" class="form-control" id="leaveAdvanceNoticeDays" placeholder="-">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" data-i18n="max_consecutive_days">Max Consecutive Days</label>
                        <input type="number" min="0" step="0.5" class="form-control" id="leaveMaxConsecutiveDays" placeholder="-">
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="applicable_employment_statuses">Applicable Employment Status</label>
                        <select class="form-select select2-static" id="leaveApplicableStatuses" multiple
                            data-option-keys="employment_status_probation,employment_status_permanent,employment_status_contract,employment_status_resigned,employment_status_terminated"
                            data-option-values="probation,permanent,contract,resigned,terminated"></select>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="leaveRequiresDocument">
                        </div>
                        <label class="form-label m-0" for="leaveRequiresDocument" data-i18n="requires_document">Requires supporting document</label>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="leaveCountWorkingDaysOnly">
                        </div>
                        <label class="form-label m-0" for="leaveCountWorkingDaysOnly" data-i18n="count_working_days_only">Count working days only (skip weekends/holidays within the leave span)</label>
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
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="ot_name">OT Name</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="otNameTh" data-i18n="ot_name_placeholder" placeholder="e.g., OT วันธรรมดา">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">&nbsp;</label>
                        <input type="text" class="form-control" id="otNameEn" placeholder="e.g., Weekday OT">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="applies_to">Applies To</label>
                        <select class="form-select select2-remote required" id="otScope" data-api="/api/ot-rate.scope-options" data-type="ot_scope"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="multiplier_rate">Multiplier Rate (x)</label>
                        <input type="number" step="0.1" min="0.1" class="form-control" id="otMultiplier" value="1.5">
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="calculation_base">Calculation Base</label>
                        <select class="form-select select2-static" id="otBase" data-option-keys="ot_base_hourly,ot_base_daily" data-option-values="hourly,daily"></select>
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
<script src="<?=asset('public/js/setup/setup-rules.js')?>"></script>
