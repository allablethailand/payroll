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
    <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
         app/views/payroll/index.php). -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-pen-to-square"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="manual_time_entry">Manual Time Entry</h5>
            <p class="page-header-card-desc" data-i18n="manual_time_entry_description">Manually record attendance, leave, and overtime for employees when there is no HR system integration.</p>
        </div>
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
            <!-- 2026-08-29, same-day follow-up: system-wide page-level filter audit -- was a bare
                 `row mt-5 mb-3 g-2` with no collapse/Clear Filter, now the same .station-filter
                 component every other page's own filter uses (see Employee List's Login History
                 tab for the canonical shape this was copied from). -->
            <div class="station-filter" id="attendanceStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="attendanceStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
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
                </div>
            </div>
            <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnAttendanceClearFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
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
            <div class="station-filter" id="leaveStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="leaveStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
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
                </div>
            </div>
            <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnLeaveClearFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
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
            <div class="station-filter" id="overtimeStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="overtimeStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
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
                </div>
            </div>
            <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnOvertimeClearFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
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

<!-- attendanceModal / leaveModal / overtimeModal / manualEntryDeleteModal moved to
     app/views/layout/modals.php (2026-08-30, modal consolidation). -->
<script src="<?=asset('public/js/manual-entry/index.js')?>"></script>
