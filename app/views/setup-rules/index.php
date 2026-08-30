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
    <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
         app/views/payroll/index.php). -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-gears"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="setup_and_rules">Setup & Rules</h5>
            <p class="page-header-card-desc" data-i18n="setup_and_rules_description">Define work shifts, public holidays, leave types, and overtime (OT) calculation rates for employees.</p>
        </div>
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
                            <th data-i18n="working_days">Working Days</th>
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
                        <label class="form-label" data-i18n="working_days">Working Days</label><br>
                        <!-- 2026-08-21, explicit request: weekly working-day pattern per Shift, used by
                             PayrollRunModel::recalculate()'s salary_type='daily' branch (via
                             SetupRulesModel::payableDaysForEmployee()) to know which days are payable.
                             Unlike #eedInterestToggle (single-select), each day here toggles independently
                             -- see toggleShiftWorkDay() in setup-rules.js. Defaults to Mon-Fri active for a
                             brand-new shift, matching the DB column defaults. -->
                        <div class="btn-group btn-group-sm" role="group" id="shiftWorkDaysToggle">
                            <button type="button" class="btn btn-outline-brand active" data-day="monday"><span data-i18n="day_mon_short">Mon</span></button>
                            <button type="button" class="btn btn-outline-brand active" data-day="tuesday"><span data-i18n="day_tue_short">Tue</span></button>
                            <button type="button" class="btn btn-outline-brand active" data-day="wednesday"><span data-i18n="day_wed_short">Wed</span></button>
                            <button type="button" class="btn btn-outline-brand active" data-day="thursday"><span data-i18n="day_thu_short">Thu</span></button>
                            <button type="button" class="btn btn-outline-brand active" data-day="friday"><span data-i18n="day_fri_short">Fri</span></button>
                            <button type="button" class="btn btn-outline-brand" data-day="saturday"><span data-i18n="day_sat_short">Sat</span></button>
                            <button type="button" class="btn btn-outline-brand" data-day="sunday"><span data-i18n="day_sun_short">Sun</span></button>
                        </div>
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
<!-- 2026-08-30, explicit request: "ตัดการ Assign ออกไปเลย เพราะสามารถเพิ่มได้ในฝั่งพนักงานอยู่แล้ว" --
     #shiftAssignModal removed (see setup-rules.js's own comment at the actionBtns() Shift render
     call for the reasoning). -->
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
<!-- 2026-08-30, renamed leaveModal -> leaveTypeModal (modal consolidation): a plain leave-RECORD
     entry modal of the same name already exists on Manual Time Entry (app/views/manual-entry/
     index.php) -- coincidental name reuse for a genuinely different purpose, harmless while each
     page's own markup only ever loaded on its own page, but now that every modal shares one DOM
     (app/views/layout/modals.php, loaded on every page) the two ids would collide live. Renamed
     THIS one (leave-TYPE config) rather than Manual Time Entry's (leave-RECORD entry) to minimize
     churn -- see public/js/setup/setup-rules.js for the matching id updates. -->
<div class="modal fade" id="leaveTypeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="leaveTypeModalTitle"><i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave_type">Leave Type</span></h6>
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
                        <label class="form-label" data-i18n="calculation_base">Calculation Base</label>
                        <select class="form-select select2-static" id="otBase" data-option-keys="ot_base_hourly,ot_base_daily" data-option-values="hourly,daily"></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="ot_calculation_method">Calculation Method</label>
                        <select class="form-select select2-static" id="otCalcMethod" data-option-keys="ot_calc_method_multiplier,ot_calc_method_flat_amount" data-option-values="multiplier,flat_amount"></select>
                    </div>
                    <div class="col-12" id="otMultiplierWrapper">
                        <label class="form-label" data-i18n="multiplier_rate">Multiplier Rate (x)</label>
                        <input type="number" step="0.1" min="0.1" class="form-control" id="otMultiplier" value="1.5">
                    </div>
                    <div class="col-12 d-none" id="otFlatAmountWrapper">
                        <label class="form-label" data-i18n="ot_flat_amount_rate">Flat Amount (per hour/day)</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" id="otFlatAmountRate" placeholder="e.g., 100.00">
                    </div>
                    <div class="col-12 d-flex align-items-center gap-2 mt-1">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="otStatus" checked>
                        </div>
                        <label class="form-label m-0" for="otStatus" data-i18n="enable_this_rate">Enable this rate</label>
                    </div>
                </div>
                <!-- 2026-08-30, explicit request: same calculation-preview feature as Attendance
                     Deduction Rule's own Configure modal (see that modal's own comment for the full
                     "ทำ OT ต่อเลยครับ" context) -- computes against whatever is CURRENTLY typed above,
                     via SetupRulesModel::otRatePreview(), the exact same formula real OT payroll uses. -->
                <hr class="my-3 text-muted opacity-25">
                <div class="calc-preview-box" id="otCalcPreviewBox">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-secondary"><i class="fa-solid fa-calculator me-2 text-brand"></i><span data-i18n="calc_preview_title">Calculation Preview</span></h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnOtCalcPreview"><i class="fa-solid fa-play me-1"></i><span data-i18n="calc_preview_button">Preview</span></button>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small mb-1" data-i18n="calc_preview_sample_base_salary">Sample Base Salary</label>
                            <input type="number" min="1" step="0.01" class="form-control form-control-sm" id="otCalcPreviewBaseSalary" value="30000">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1" data-i18n="calc_preview_sample_ot_hours">Sample OT Hours</label>
                            <input type="number" min="0" step="0.5" class="form-control form-control-sm" id="otCalcPreviewHours" value="2">
                        </div>
                    </div>
                    <div class="calc-preview-result d-none" id="otCalcPreviewResult"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveOt()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<!-- 2026-08-28, explicit request: "เพิ่มให้ Sync ข้อมูลวันหยุดตามประกาศจาก API ที่มี...แต่ต้อง Map
     กับข้อมูลที่มีแล้ว แล้วค่อยมานำตั้งค่าให้พนักงานต่อ" -- picker modal, same 2-tab New/Existing
     review pattern as the Employee Sync picker (Employee List page). See HolidaySyncModel's own
     docblock for the full design; public/js/setup/holiday-sync.js for the JS. -->
<div class="modal fade" id="holidaySyncModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="holidaySyncModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="holidaySyncModalLabel">
                    <i class="fa-brands fa-google me-1"></i><span data-i18n="holiday_sync_button">Sync from Google Calendar</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5 d-none" id="holidaySyncNotConnected">
                    <i class="fa-solid fa-plug-circle-xmark fa-2x text-danger mb-3 d-block"></i>
                    <div class="fw-bold mb-1" data-i18n="holiday_sync_not_connected_title">Not connected to Google Calendar</div>
                    <div class="text-muted small" id="holidaySyncNotConnectedMessage"></div>
                </div>
                <div id="holidaySyncFilterRow">
                    <div class="alert alert-warning small mb-3" data-i18n="holiday_sync_review_warning">
                        This calendar includes some cultural and government-only observances that may not be official paid holidays for your company (e.g. Chinese New Year, Valentine's Day, Christmas, Royal Ploughing Day). Please review each item before syncing.
                    </div>
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label mb-1" data-i18n="holiday_sync_year">Year</label>
                            <select class="form-select" id="holiday_sync_year"></select>
                        </div>
                        <div class="col-12 col-md-3">
                            <button type="button" class="btn btn-primary w-100" id="btnFetchHolidaySync">
                                <i class="fa-solid fa-magnifying-glass me-1"></i><span data-i18n="employee_sync_fetch_button">Fetch</span>
                            </button>
                        </div>
                    </div>

                    <!-- 2026-08-28, side-by-side layout: same fix as Employee Sync's picker (see
                         that file's own comment for the full root-cause writeup) -- fixes the
                         same display:none-at-DataTable-init-time bug here too. Date+Holiday Name
                         merged into one "calendar date chip" cell (2026-08-28, explicit request:
                         "ปรับข้อมูลตาราง ตรง Sync ให้ดูสวยขึ้น") -- see
                         hsRenderHolidayCell() in holiday-sync.js. -->
                    <div id="holidaySyncResultArea" class="d-none">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="d-flex align-items-center mb-2">
                                    <h6 class="mb-0 text-success"><span data-i18n="employee_sync_tab_new">New</span> <span class="badge bg-success ms-1" id="holidaySyncNewCount">0</span></h6>
                                </div>
                                <div class="border rounded" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle w-100 mb-0" id="tb_holiday_sync_new">
                                        <thead class="table-light text-secondary" style="position: sticky; top: 0; z-index: 1;">
                                            <tr>
                                                <th style="width:3%;"><input type="checkbox" id="holidaySyncNewSelectAll"></th>
                                                <th data-i18n="holiday">Holiday</th>
                                                <th data-i18n="table_last_updated">Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="d-flex align-items-center mb-2">
                                    <h6 class="mb-0 text-secondary"><span data-i18n="employee_sync_tab_existing">Already Exists</span> <span class="badge bg-secondary ms-1" id="holidaySyncExistingCount">0</span></h6>
                                </div>
                                <div class="border rounded" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle w-100 mb-0" id="tb_holiday_sync_existing">
                                        <thead class="table-light text-secondary" style="position: sticky; top: 0; z-index: 1;">
                                            <tr>
                                                <th style="width:3%;"><input type="checkbox" id="holidaySyncExistingSelectAll"></th>
                                                <th data-i18n="holiday">Holiday</th>
                                                <th data-i18n="employee_sync_update_col">Update Available</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-muted small text-center py-4" id="holidaySyncEmptyHint" data-i18n="holiday_sync_empty_hint">Pick a year and click Fetch to browse holidays from Google Calendar.</div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <span class="text-muted small" id="holidaySyncSelectedCountLabel"></span>
                <div>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                    <button type="button" class="btn btn-primary d-none" id="btnApplyHolidaySync">
                        <i class="fa-solid fa-download me-1"></i><span data-i18n="employee_sync_apply_button">Sync Selected</span> (<span id="holidaySyncSelectedCount">0</span>)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Holiday Sync Log -->
<div class="modal fade" id="holidaySyncLogModal" tabindex="-1" aria-labelledby="holidaySyncLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="holidaySyncLogModalLabel">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="holiday_sync_log_title">Holiday Sync Log</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-hover table-sm align-middle w-100" id="tb_holiday_sync_log">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_sync_log_col_date">Date</th>
                            <th data-i18n="employee_sync_log_col_triggered_by">By</th>
                            <th data-i18n="employee_sync_log_col_status">Status</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_total">Total</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_success">Success</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_error">Error</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="<?=asset('public/js/setup/setup-rules.js')?>"></script>
<script src="<?=asset('public/js/setup/holiday-sync.js')?>"></script>
