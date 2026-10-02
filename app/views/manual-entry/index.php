<div class="container container-body">
    <?php
    $breadcrumb = [
        ['label' => 'Time & Leave', 'href' => null, 'i18n' => 'time_and_leave'],
        ['label' => 'Manual Time Entry', 'href' => null, 'i18n' => 'manual_time_entry'],
    ];
    $description = 'Manually record attendance, leave, and overtime for employees when there is no HR system integration.';
    $description_i18n = 'manual_time_entry_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance-pane" type="button" role="tab" aria-controls="attendance-pane" aria-selected="true">
                <span data-i18n="attendance">Attendance</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="leave-tab" data-bs-toggle="tab" data-bs-target="#leave-pane" type="button" role="tab" aria-controls="leave-pane" aria-selected="false">
                <span data-i18n="leave">Leave</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="overtime-tab" data-bs-toggle="tab" data-bs-target="#overtime-pane" type="button" role="tab" aria-controls="overtime-pane" aria-selected="false">
                <span data-i18n="overtime">Overtime</span>
            </button>
        </li>
        <!-- 2026-09-02, explicit request ("จะมีอีก Tab ที่เป็น Tab import โดยตรง ถ้าพิจารณาแล้วว่าเป็นการทำงาน
             ซ้ำซ้อนลบออกได้เลย") -- this standalone Import tab (2026-08-30, Phase 5 T030-T035) is now
             genuinely superseded: every one of its own steps (download template, attach file, preview
             row-by-row before committing) is reachable from the new "Import" button on each of the 3
             other tabs (openBulkImportModal(), see bulk-entry.js), which additionally offers choosing
             "Save Directly" vs "Load into Grid to Edit" -- something this old tab never had. Removed
             outright rather than left as a dead-code duplicate path. History tab (below) is UNCHANGED
             -- it's a genuinely different concern (audit trail across all 3 entity types), not
             superseded by anything this round added. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="import-history-tab" data-bs-toggle="tab" data-bs-target="#import-history-pane" type="button" role="tab" aria-controls="import-history-pane" aria-selected="false">
                <span data-i18n="import_history_tab">History</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0 rounded-top-0">
        <div class="tab-pane fade show active" id="attendance-pane" role="tabpanel" aria-labelledby="attendance-tab" tabindex="0">
            <!-- 2026-08-29, same-day follow-up: system-wide page-level filter audit -- was a bare
                 `row mt-5 mb-3 g-2` with no collapse/Clear Filter, now the same .station-filter
                 component every other page's own filter uses (see Employee List's Login History
                 tab for the canonical shape this was copied from). -->
            <?php ob_start(); ?>
            <div class="row g-2">
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_att_employee" data-i18n="employee">Employee</label>
                <select class="form-select select2-remote" id="filter_att_employee" data-api="/api/employee.report_to.get" data-type=""></select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_att_date_from" data-i18n="filter_date_from">From</label>
                <input type="text" class="form-control datepicker" id="filter_att_date_from" autocomplete="off">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_att_date_to" data-i18n="filter_date_to">To</label>
                <input type="text" class="form-control datepicker" id="filter_att_date_to" autocomplete="off">
            </div>
            </div>
            <?php
            $filter_fields_html = ob_get_clean();
            $id = 'attendanceFilterBar';
            include __DIR__ . '/../partials/filter-bar.php';
            ?>
            <div class="mb-5 table-responsive">
                <table class="table table-striped table-hover align-middle" id="tb_attendance" style="width:100%">
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
            <?php ob_start(); ?>
            <div class="row g-2">
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_leave_employee" data-i18n="employee">Employee</label>
                <select class="form-select select2-remote" id="filter_leave_employee" data-api="/api/employee.report_to.get" data-type=""></select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_leave_date_from" data-i18n="filter_date_from">From</label>
                <input type="text" class="form-control datepicker" id="filter_leave_date_from" autocomplete="off">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_leave_date_to" data-i18n="filter_date_to">To</label>
                <input type="text" class="form-control datepicker" id="filter_leave_date_to" autocomplete="off">
            </div>
            </div>
            <?php
            $filter_fields_html = ob_get_clean();
            $id = 'leaveFilterBar';
            include __DIR__ . '/../partials/filter-bar.php';
            ?>
            <div class="mb-5 table-responsive">
                <table class="table table-striped table-hover align-middle" id="tb_leave" style="width:100%">
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
            <?php ob_start(); ?>
            <div class="row g-2">
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_ot_employee" data-i18n="employee">Employee</label>
                <select class="form-select select2-remote" id="filter_ot_employee" data-api="/api/employee.report_to.get" data-type=""></select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_ot_date_from" data-i18n="filter_date_from">From</label>
                <input type="text" class="form-control datepicker" id="filter_ot_date_from" autocomplete="off">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_ot_date_to" data-i18n="filter_date_to">To</label>
                <input type="text" class="form-control datepicker" id="filter_ot_date_to" autocomplete="off">
            </div>
            </div>
            <?php
            $filter_fields_html = ob_get_clean();
            $id = 'overtimeFilterBar';
            include __DIR__ . '/../partials/filter-bar.php';
            ?>
            <div class="mb-5 table-responsive">
                <table class="table table-striped table-hover align-middle" id="tb_overtime" style="width:100%">
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
        <!-- 2026-08-30, explicit follow-up request: "เก็บประวัติการ Download ข้อมูลออกจากระบบ และการ Import
             ข้อมูลเข้าระบบ...เพิ่ม Tab ในการดูประวัติการ Download Upload ด้วยครับ" -- a 5th tab, unifying BOTH
             the Download Template action (import_template_download_logs, new) and every Import round
             (sync_batches, source='import' -- previously shown as its own "Import History" card
             inside the Import tab above; consolidated in here instead of duplicating the same data
             in two places) into ONE audit trail via ImportActivityLogModel's own UNION ALL. Same
             audit-field convention (who/when/device/IP/browser/source) report_export_logs already
             established for Reports' own Download History. -->
        <div class="tab-pane fade" id="import-history-pane" role="tabpanel" aria-labelledby="import-history-tab" tabindex="0">
            <?php ob_start(); ?>
            <div class="row g-2">
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_ih_event_type" data-i18n="event_type">Event</label>
                <select class="form-select" id="filter_ih_event_type" data-option-keys="filter_all,download,import" data-option-values="all,download,import"></select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_ih_entity_type" data-i18n="entity_type">Data Type</label>
                <select class="form-select" id="filter_ih_entity_type" data-option-keys="filter_all,attendance,leave,overtime" data-option-values="all,attendance,leave,overtime"></select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_ih_date_from" data-i18n="filter_date_from">From</label>
                <input type="text" class="form-control datepicker" id="filter_ih_date_from" autocomplete="off">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small mb-1" for="filter_ih_date_to" data-i18n="filter_date_to">To</label>
                <input type="text" class="form-control datepicker" id="filter_ih_date_to" autocomplete="off">
            </div>
            </div>
            <?php
            $filter_fields_html = ob_get_clean();
            $id = 'importHistoryFilterBar';
            include __DIR__ . '/../partials/filter-bar.php';
            ?>
            <div class="mb-5 table-responsive">
                <table class="table table-striped table-hover align-middle" id="tb_import_history" style="width:100%">
                    <thead>
                        <tr>
                            <th data-i18n="started_at">Date/Time</th>
                            <th data-i18n="event_type">Event</th>
                            <th data-i18n="entity_type">Data Type</th>
                            <th data-i18n="by">By</th>
                            <th data-i18n="device">Device</th>
                            <th data-i18n="browser">Browser</th>
                            <th data-i18n="ip_address">IP Address</th>
                            <th data-i18n="status" class="text-center">Result</th>
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
<script src="<?=asset('public/js/manual-entry/bulk-entry.js')?>"></script>
