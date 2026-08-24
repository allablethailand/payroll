<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="settings">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="payroll_cycle">Payroll Cycle</span>
        </h5>
    </nav>
    <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
         app/views/payroll/index.php). -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="payroll_configuration">Payroll Configuration</h5>
            <p class="page-header-card-desc" data-i18n="payroll_configuration_description">Set up payroll cycles, earning types, and deduction types by employee group or employment type.</p>
        </div>
    </div>
    <ul class="nav nav-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="cycle-tab" data-bs-toggle="tab" data-bs-target="#cycle-pane" type="button" role="tab" aria-controls="cycle-pane" aria-selected="true">
                <i class="fa-regular fa-calendar-days me-2"></i><span data-i18n="cycle">Cycle</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="earnings-tab" data-bs-toggle="tab" data-bs-target="#earnings-pane" type="button" role="tab" aria-controls="earnings-pane" aria-selected="false">
                <i class="fa-solid fa-calendar-day me-2"></i><span data-i18n="earnings">Earnings</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="deductions-tab" data-bs-toggle="tab" data-bs-target="#deductions-pane" type="button" role="tab" aria-controls="deductions-pane" aria-selected="false">
                <i class="fa-regular fa-calendar-check me-2"></i><span data-i18n="deductions">Deductions</span>
            </button>
        </li>
        <!-- 2026-08-21, explicit request: own tab right after Deductions, replacing the old button+shared-
             modal-with-pill-switcher entry point on the Deductions tab. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="attendance-deduction-tab" data-bs-toggle="tab" data-bs-target="#attendance-deduction-pane" type="button" role="tab" aria-controls="attendance-deduction-pane" aria-selected="false">
                <i class="fa-solid fa-clock-rotate-left me-2"></i><span data-i18n="attendance_deduction">Attendance Deduction</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="attendance-bonus-tab" data-bs-toggle="tab" data-bs-target="#attendance-bonus-pane" type="button" role="tab" aria-controls="attendance-bonus-pane" aria-selected="false">
                <i class="fa-solid fa-medal me-2"></i><span data-i18n="attendance_bonus">Attendance Bonus</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="bonus-ledger-tab" data-bs-toggle="tab" data-bs-target="#bonus-ledger-pane" type="button" role="tab" aria-controls="bonus-ledger-pane" aria-selected="false">
                <i class="fa-solid fa-list-check me-2"></i><span data-i18n="bonus_ledger">Ledger</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
        <div class="tab-pane fade show active" id="cycle-pane" role="tabpanel" aria-labelledby="cycle-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <div class="mt-5 mb-5">
                    <table class="table table-hover table-border align-middle w-100" id="tb_payroll_cycle">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th scope="col" style="width: 22%;" data-i18n="table_cycle_name">Cycle Name</th>
                                <th scope="col" style="width: 13%;" data-i18n="table_frequency">Frequency</th>
                                <th scope="col" style="width: 18%;" data-i18n="table_cutoff">Attendance Cut-off</th>
                                <th scope="col" style="width: 18%;" data-i18n="table_payment_day">Payment Day</th>
                                <th scope="col" style="width: 15%;" data-i18n="table_bank_format">Bank Format</th>
                                <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                                <th scope="col" style="width: 6%; text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="modal fade" id="payrollCycleModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="payrollCycleModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <div class="modal-header">
                                <h5 class="modal-title text-secondary">
                                    <i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="cycle">Cycle</span>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form id="payrollCycleForm" novalidate>
                                <input type="hidden" name="id" id="cycle_id">
                                <div class="modal-body">
                                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                                        <span data-i18n="modal_sec_general">Cycle Information</span>
                                    </h6>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_cycle_name">Cycle Name</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <input type="text" class="form-control required" id="cycle_name" name="cycle_name" data-i18n="cycle_name_placeholder" placeholder="e.g., Office Staff Cycle / Part-time Weekly">
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_frequency">Payroll Frequency</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select select2-static required" id="payroll_frequency" name="payroll_frequency" data-option-keys="freq_monthly,freq_semi_monthly,freq_weekly,freq_bi_weekly" data-option-values="monthly,semi_monthly,weekly,bi_weekly"></select>
                                        </div>
                                    </div>
                                    <hr class="my-4 text-muted opacity-25">
                                    <h6 class="text-secondary fw-bold mb-3">
                                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                                        <span data-i18n="modal_sec_dates">Cut-off & Payment Settings</span>
                                    </h6>
                                    <div class="row mb-3" id="cutoff_dom_wrapper">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_attendance_cutoff">Attendance Cut-off Day</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-3">
                                            <input type="number" min="1" max="28" class="form-control required" id="cutoff_day_of_month" name="cutoff_day_of_month">
                                        </div>
                                        <div class="col-sm-6 pt-2">
                                            <input type="checkbox" class="me-2" id="cutoff_use_last_day" name="cutoff_use_last_day"><span data-i18n="use_last_day_of_month">Use last day of the month</span>
                                        </div>
                                    </div>
                                    <div class="row mb-3 d-none" id="cutoff_dow_wrapper">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_attendance_cutoff">Attendance Cut-off Day</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select select2-static" id="cutoff_day_of_week" name="cutoff_day_of_week" data-option-keys="dow_monday,dow_tuesday,dow_wednesday,dow_thursday,dow_friday,dow_saturday,dow_sunday" data-option-values="monday,tuesday,wednesday,thursday,friday,saturday,sunday"></select>
                                        </div>
                                    </div>
                                    <p class="text-muted small ms-0 mb-3" data-i18n="day_of_month_hint">*Day must be between 1-28 so it exists in every month, or use "last day of the month".</p>
                                    <div class="row mb-3" id="payment_dom_wrapper">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_payment_day">Payment Day</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-3">
                                            <input type="number" min="1" max="28" class="form-control required" id="payment_day_of_month" name="payment_day_of_month">
                                        </div>
                                        <div class="col-sm-6 pt-2">
                                            <input type="checkbox" class="me-2" id="payment_use_last_day" name="payment_use_last_day"><span data-i18n="use_last_day_of_month">Use last day of the month</span>
                                        </div>
                                    </div>
                                    <div class="row mb-3 d-none" id="payment_dow_wrapper">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_payment_day">Payment Day</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select select2-static" id="payment_day_of_week" name="payment_day_of_week" data-option-keys="dow_monday,dow_tuesday,dow_wednesday,dow_thursday,dow_friday,dow_saturday,dow_sunday" data-option-values="monday,tuesday,wednesday,thursday,friday,saturday,sunday"></select>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-3">
                                            <label class="form-label pt-1"><span data-i18n="modal_ot_cutoff">OT Cut-off Type</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <div class="form-check form-check-inline mt-1">
                                                <input class="form-check-input" type="radio" name="ot_cutoff_type" id="ot_same" value="same_as_attendance" checked>
                                                <label class="form-check-label" for="ot_same" data-i18n="same_as_attendance">Same as Attendance</label>
                                            </div>
                                            <div class="form-check form-check-inline mt-1">
                                                <input class="form-check-input" type="radio" name="ot_cutoff_type" id="ot_custom" value="custom">
                                                <label class="form-check-label" for="ot_custom" data-i18n="custom_definition">Custom Definition</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mb-3 d-none" id="ot_custom_wrapper">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_ot_cutoff_day">OT Cut-off Day</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-3">
                                            <input type="number" min="1" max="28" class="form-control" id="ot_cutoff_day_of_month" name="ot_cutoff_day_of_month">
                                        </div>
                                        <div class="col-sm-6 pt-2">
                                            <input type="checkbox" class="me-2" id="ot_cutoff_use_last_day" name="ot_cutoff_use_last_day"><span data-i18n="use_last_day_of_month">Use last day of the month</span>
                                        </div>
                                    </div>
                                    <hr class="my-4 text-muted opacity-25">
                                    <h6 class="text-secondary fw-bold mb-3">
                                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">3</label>
                                        <span data-i18n="modal_sec_bank">Bank File Configuration</span>
                                    </h6>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0"><span data-i18n="modal_bank_format">Bank Text Format</span> <span class="text-danger">*</span></label>
                                        </div>
                                        <div class="col-sm-9">
                                            <select class="form-select select2-remote required" id="bank_file_format_id" name="bank_file_format_id" data-api="/api/bank-file-format.options"></select>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-sm-3 align-self-center">
                                            <label class="form-label mb-0" data-i18n="status">Status</label>
                                        </div>
                                        <div class="col-sm-3">
                                            <select class="form-select select2-static" id="cycle_status" name="status" data-option-keys="active,inactive"></select>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-warning px-4" data-i18n="save">Save</button>
                                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="earnings-pane" role="tabpanel" aria-labelledby="earnings-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="tb_earning_type">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 12%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 23%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 17%;" data-i18n="col_calc_method">Calculation</th>
                            <th scope="col" style="width: 16%;" data-i18n="col_tax_type">Tax Treatment</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_sso">SSO Cal</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_pf">Provident Fund</th>
                            <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                            <th scope="col" style="width: 4%; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="deductions-pane" role="tabpanel" aria-labelledby="deductions-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="tb_deduction_type">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 15%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 30%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_calc_method">Calculation</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_deduct_type">Tax Deduction Impact</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_status">Status</th>
                            <th scope="col" style="width: 5%; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="attendance-deduction-pane" role="tabpanel" aria-labelledby="attendance-deduction-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <p class="text-muted small mb-4" data-i18n="attendance_deduction_rule_description">Choose how each deduction is calculated, and set your own condition(s) per item. This is used automatically the next time payroll is calculated from synced attendance data.</p>
                <div class="row g-3" id="attendanceDeductionCards"></div>
            </div>
        </div>
        <div class="tab-pane fade" id="attendance-bonus-pane" role="tabpanel" aria-labelledby="attendance-bonus-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="tb_attendance_bonus">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 20%;" data-i18n="table_scheme_name">Scheme Name</th>
                            <th scope="col" style="width: 22%;" data-i18n="table_conditions">Conditions</th>
                            <th scope="col" style="width: 18%;" data-i18n="table_amount">Amount</th>
                            <th scope="col" style="width: 18%;" data-i18n="table_reset_cycle">Reset Cycle</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_status">Status</th>
                            <th scope="col" style="width: 12%; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="bonus-ledger-pane" role="tabpanel" aria-labelledby="bonus-ledger-tab" tabindex="0">
            <div class="mt-5 mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label mb-1" data-i18n="attendance_bonus">Scheme</label>
                        <select class="form-select select2-remote" id="ledger_filter_scheme" data-api="/api/attendance-bonus.scheme-options" data-type=""></select>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1" data-i18n="period_year">Year</label>
                        <input type="number" class="form-control" id="ledger_filter_year" value="<?=date('Y')?>">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1" data-i18n="period_month">Month</label>
                        <select class="form-select select2-static" id="ledger_filter_month" data-option-keys="month_1,month_2,month_3,month_4,month_5,month_6,month_7,month_8,month_9,month_10,month_11,month_12" data-option-values="1,2,3,4,5,6,7,8,9,10,11,12"></select>
                    </div>
                </div>
            </div>
            <div class="mt-3 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="tb_bonus_ledger">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 30%;" data-i18n="table_employee">Employee</th>
                            <th scope="col" style="width: 12%;" data-i18n="col_status">Status</th>
                            <th scope="col" style="width: 10%;" data-i18n="table_streak">Streak</th>
                            <th scope="col" style="width: 10%;" data-i18n="table_cycle_no">Cycle</th>
                            <th scope="col" style="width: 15%;" data-i18n="table_amount">Amount</th>
                            <th scope="col" style="width: 10%;" data-i18n="table_locked">Locked</th>
                            <th scope="col" style="width: 13%; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="ledgerEntryModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="ledgerEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="ledgerEntryModalLabel">
                    <span data-i18n="add_ledger_entry">Ledger Entry</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="ledgerEntryForm" novalidate>
                <input type="hidden" name="id" id="ledger_id">
                <input type="hidden" name="scheme_id" id="ledger_scheme_id">
                <input type="hidden" name="period_year" id="ledger_period_year">
                <input type="hidden" name="period_month" id="ledger_period_month">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="table_employee">Employee</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote required" id="ledger_employee_id" name="employee_id" data-api="/api/employee.report_to.get" data-type=""></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="modal_scheme_period">Scheme / Period</label>
                        </div>
                        <div class="col-sm-9 pt-2 text-muted" id="ledger_period_display"></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3">
                            <label class="form-label pt-1"><span data-i18n="col_status">Status</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="ledger_status" id="ledger_status_passed" value="passed" checked>
                                <label class="form-check-label" for="ledger_status_passed" data-i18n="status_passed">Passed</label>
                            </div>
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="ledger_status" id="ledger_status_failed" value="failed">
                                <label class="form-check-label" for="ledger_status_failed" data-i18n="status_failed">Failed</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="ledger_fail_reasons_wrapper">
                        <div class="col-sm-3">
                            <label class="form-label pt-1" data-i18n="fail_reasons">Reason (not met)</label>
                        </div>
                        <div class="col-sm-9">
                            <div class="mb-2">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input fail-reason-check" type="checkbox" id="fail_reason_absent" data-label-key="condition_no_absent">
                                    <label class="form-check-label" for="fail_reason_absent" data-i18n="condition_no_absent">No absences</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input fail-reason-check" type="checkbox" id="fail_reason_late" data-label-key="condition_no_late">
                                    <label class="form-check-label" for="fail_reason_late" data-i18n="condition_no_late">No late arrivals</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input fail-reason-check" type="checkbox" id="fail_reason_leave" data-label-key="condition_no_leave">
                                    <label class="form-check-label" for="fail_reason_leave" data-i18n="condition_no_leave">No leave taken</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input fail-reason-check" type="checkbox" id="fail_reason_time_adjust" data-label-key="condition_no_time_adjust">
                                    <label class="form-check-label" for="fail_reason_time_adjust" data-i18n="condition_no_time_adjust">No time clock adjustments</label>
                                </div>
                            </div>
                            <textarea class="form-control" id="fail_reasons" name="fail_reasons" rows="2" data-i18n="fail_reasons_placeholder" placeholder="e.g., Late 2 times"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #FF9900; border-color: #FF9900;" data-i18n="save_item">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="itemModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="pedTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
               <h5 class="modal-title fw-bold text-secondary" id="pedTypeModalLabel">
                    <i class="fa-solid fa-pen-to-square me-2" id="pedTypeModalIcon"></i>
                    <span data-i18n="earning_type">Earning Type</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="pedTypeForm" novalidate>
                <input type="hidden" id="ped_type_id" name="id">
                <div class="modal-body">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <span data-i18n="sec_general_info">General Information</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_type">Item Type</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="item_type" id="type_earning" value="earning" checked>
                                <label class="form-check-label" for="type_earning" data-i18n="earning_singular">Earning</label>
                            </div>
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="item_type" id="type_deduction" value="deduction">
                                <label class="form-check-label" for="type_deduction" data-i18n="deduction_singular">Deduction</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_code">Item Code</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="text" class="form-control required" id="item_code" name="item_code" data-i18n="item_code_placeholder" placeholder="E003 / D002">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name_en">Item Name (EN)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="item_name_en" name="item_name_en">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name_th">Item Name (TH)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="item_name_th" name="item_name_th">
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <span data-i18n="sec_calculation_rules">Calculation & Legal Settings</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="calculation_method">Calculation Method</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static required" id="calculation_method" name="calculation_method" data-option-keys="fixed_amount,percent_of_base_salary,manual_entry"></select>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="fixed_amount_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="fixed_amount">Fixed Amount</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="fixed_amount" name="fixed_amount">
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="percent_rate_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="percent_rate">Percent of Base Salary</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="percent_rate" name="percent_rate">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div id="earnings_fields_wrapper">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="tax_treatment">Tax Treatment</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="tax_treatment" name="tax_treatment" data-option-keys="taxable,non_taxable" data-option-values="taxable,non_taxable"></select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3">
                                <label class="form-label pt-1" data-i18n="statutory_calculations">Statutory Calculations</label>
                            </div>
                            <div class="col-sm-9">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="calc_sso" name="calc_sso" value="1">
                                    <label class="form-check-label" for="calc_sso" data-i18n="calc_sso_label">Include in SSO contribution base</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="calc_pf" name="calc_pf" value="1">
                                    <label class="form-check-label" for="calc_pf" data-i18n="calc_pf_label">Include in Provident Fund base</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="deductions_fields_wrapper" class="d-none">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="tax_deduction_impact">Tax Deduction Impact</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="tax_deduction_impact" name="tax_deduction_impact" data-option-keys="impact_before_tax,impact_after_tax" data-option-values="before_tax,after_tax"></select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="statutory_report_code">Statutory Report Mapping</label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="statutory_report_code" name="statutory_report_code" data-option-keys="statutory_report_th_slf" data-option-values="TH_SLF"></select>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="country_scope">Country Scope</label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="country_code" name="country_code" data-api="/api/country.get" data-type="country"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="source_event_code">Linked Attendance Event</label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="source_event_code" name="source_event_code" data-api="/api/ped-type.source-event-options" data-type="earning"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="status">Status</label>
                        </div>
                        <div class="col-sm-3">
                            <select class="form-select select2-static" id="ped_status" name="status" data-option-keys="active,inactive"></select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-warning px-4" data-i18n="save">Save</button> 
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="attendanceBonusModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="attendanceBonusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="attendanceBonusModalLabel">
                    <span data-i18n="add_attendance_bonus">Attendance Bonus Scheme</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="attendanceBonusForm" novalidate>
                <input type="hidden" name="id" id="bonus_id">
                <div class="modal-body">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <span data-i18n="modal_sec_general">General Information</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_scheme_name">Scheme Name</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="scheme_name" name="scheme_name" data-i18n="scheme_name_placeholder" placeholder="e.g., Office Staff Attendance Bonus">
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <span data-i18n="modal_sec_conditions">Eligibility Conditions</span>
                    </h6>
                    <p class="text-muted small mb-2" data-i18n="conditions_hint">*Select at least one condition. All selected conditions must be met in the month to earn the bonus.</p>
                    <div class="row mb-3">
                        <div class="col-sm-12">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="condition_no_absent" name="condition_no_absent">
                                <label class="form-check-label" for="condition_no_absent" data-i18n="condition_no_absent">No absences</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="condition_no_late" name="condition_no_late">
                                <label class="form-check-label" for="condition_no_late" data-i18n="condition_no_late">No late arrivals</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="condition_no_leave" name="condition_no_leave">
                                <label class="form-check-label" for="condition_no_leave" data-i18n="condition_no_leave">No leave taken</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="condition_no_time_adjust" name="condition_no_time_adjust">
                                <label class="form-check-label" for="condition_no_time_adjust" data-i18n="condition_no_time_adjust">No time clock adjustments</label>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">3</label>
                        <span data-i18n="modal_sec_amount">Amount & Escalation</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="starting_amount">Starting Amount</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control required" id="starting_amount" name="starting_amount">
                        </div>
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="increment_amount">Monthly Increment</label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="increment_amount" name="increment_amount" value="0">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="max_amount">Maximum Cap</label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="max_amount" name="max_amount">
                        </div>
                        <div class="col-sm-6 pt-2 text-muted small" data-i18n="max_amount_hint">*Leave blank for no cap.</div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">4</label>
                        <span data-i18n="modal_sec_reset_cycle">Reset Cycle</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="reset_cycle_months">Reset Every (Months)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="1" min="1" max="60" class="form-control required" id="reset_cycle_months" name="reset_cycle_months" value="12">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3">
                            <label class="form-label pt-1" data-i18n="reset_cycle_basis">Reset Cycle Basis</label>
                        </div>
                        <div class="col-sm-9">
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="reset_cycle_basis" id="basis_anniversary" value="employee_anniversary" checked>
                                <label class="form-check-label" for="basis_anniversary" data-i18n="basis_employee_anniversary">From employee's first eligible month</label>
                            </div>
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="reset_cycle_basis" id="basis_fixed" value="fixed_month">
                                <label class="form-check-label" for="basis_fixed" data-i18n="basis_fixed_month">Fixed calendar month</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="reset_start_month_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="reset_cycle_start_month">Reset Month</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static" id="reset_cycle_start_month" name="reset_cycle_start_month" data-option-keys="month_1,month_2,month_3,month_4,month_5,month_6,month_7,month_8,month_9,month_10,month_11,month_12" data-option-values="1,2,3,4,5,6,7,8,9,10,11,12"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="status">Status</label>
                        </div>
                        <div class="col-sm-3">
                            <select class="form-select select2-static" id="bonus_status" name="status" data-option-keys="active,inactive"></select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #FF9900; border-color: #FF9900;" data-i18n="save_item">Save Scheme</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Attendance Deduction Rules (2026-08-20, explicit request -- relocated here from Time & Leave >
     Setup & Rules the same day, generalized from Late-only to also cover Absent/Unpaid Leave; moved
     again 2026-08-21 from a button+shared-modal-with-pill-switcher on the Deductions tab to its own
     tab -- see #attendance-deduction-pane's 3 cards above, "Configure" opens this modal already
     scoped to that one event, so the old event switcher is gone). Late/Absent/Unpaid Leave are all
     item_type=deduction concepts, so this still lives next to Deductions, not Earnings, where
     เบี้ยขยัน/DILIGENCE (item_type=earning) lives.
     rate_unit (2026-08-21, "นาทีละกี่บาท ชั่วโมงละกี่บาท") is freely choosable per rule regardless of
     event_code (not fixed per event like before) -- only shown for flat_amount/tiered_bracket,
     ignored by percent_of_rate (see SyncPayResolver::computeAttendanceDeductionAmount() docblock). -->
<div class="modal fade" id="attendanceDeductionRuleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fa-solid fa-clock-rotate-left"></i> <span id="attendanceDeductionRuleModalEvent"></span> <span class="text-muted small ms-1" data-i18n="attendance_deduction_rule_title">Attendance Deduction Rule</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" data-i18n="attendance_deduction_method">Deduction Method</label>
                    <select class="form-select select2-remote" id="attendanceDeductionMethod" data-api="/api/attendance-deduction-rule.method-options" data-type="attendance_deduction_method"></select>
                </div>
                <div id="attendanceRateUnitWrapper" class="mb-3 d-none">
                    <label class="form-label" data-i18n="attendance_deduction_rate_unit">Rate Unit</label>
                    <select class="form-select select2-static" id="attendanceRateUnit" data-option-keys="attendance_deduction_rate_unit_minute,attendance_deduction_rate_unit_hour,attendance_deduction_rate_unit_day" data-option-values="minute,hour,day"></select>
                </div>
                <div id="attendanceFlatSection" class="mb-3 d-none">
                    <label class="form-label" id="attendanceFlatLabel">Deduction Amount per Unit</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="attendanceRatePerUnit" placeholder="e.g., 1.00">
                </div>
                <div id="attendancePercentSection" class="mb-3 d-none">
                    <label class="form-label" data-i18n="attendance_deduction_multiplier">Multiplier (x of the salary-derived rate)</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="attendanceMultiplierRate" value="1.00">
                </div>
                <div id="attendanceBracketSection" class="mb-3 d-none">
                    <label class="form-label d-block" data-i18n="attendance_deduction_brackets">Brackets</label>
                    <div class="table-responsive">
                        <table class="table table-sm table-border align-middle mb-2">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th id="attendanceBracketMinLabel">From</th>
                                    <th id="attendanceBracketMaxLabel">To</th>
                                    <th data-i18n="attendance_deduction_bracket_amount">Deduction Amount</th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody id="attendanceBracketRows"></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addAttendanceBracketRow()"><i class="fa-solid fa-plus me-1"></i><span data-i18n="attendance_deduction_bracket_add_row">Row</span></button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-warning px-4 text-white" style="background-color: #FF9900; border-color: #FF9900;" onclick="saveAttendanceDeductionRule()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<script src="<?=asset('public/js/setup/payroll-configuration.js')?>"></script>