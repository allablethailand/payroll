<style>
#tb_payroll_run tbody tr { cursor: pointer; }
</style>
<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="payroll_process">Payroll Process</span>
        </h5>
    </nav>
    <!-- .page-header-card rollout (2026-08-21, explicit request: "ช่วยปรับให้ header แต่ละ Page
         เป็นรูปแบบเดียวกัน เฉพาะหน้าหลัก") -- same standing convention Employee List already uses,
         per style.css's own comment on .page-header-card. -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-money-check-dollar"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="payroll_process">Payroll Process</h5>
            <p class="page-header-card-desc" data-i18n="payroll_process_description">Manage payroll runs from draft through approval, payment, and closing. Click a row to open its management page.</p>
        </div>
    </div>

        <div class="station-filter" id="stationFilter">
            <span class="station-filter-label" data-i18n="label_filter">Filter</span>
            <button type="button" class="station-filter-toggle" id="stationFilterToggle" title="Toggle filter">
                <i class="fas fa-chevron-up"></i>
            </button>
            <!-- 2026-09-02, explicit request: "Filter ปรับให้เป็นแถวละ 6 Column" -- col-6 col-md-4 col-lg-2
                 is this app's own established 6-per-row .station-filter grid (2/3/6 fields per row at
                 sm/md/lg, same class combo Employee List's own 4-6-field filters already use, e.g.
                 #employeeRecheckStationFilter/#employeeSummaryStationFilter) -- was col-sm-4 col-md-3
                 (4 per row) before this. -->
            <div class="station-filter-body">
                <div class="row g-2">
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_from">From</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" id="filter_date_from" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_to">To</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" id="filter_date_to" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <!-- 2026-09-01, explicit request: "ในส่วนของ Filter สามารถเพิ่มอะไรได้อีกไหม ตามความ
                         เหมาะสมสามารถเพิ่มได้เลยครับ" -- 3 new filters, same run-level data every row
                         already carries (PayrollRunModel::list()'s own `r.*`), pure client-side
                         (no ajax.reload needed, same mechanism the Station cards themselves already
                         use -- see registerStationSearchFilter() in index.js). -->
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-route me-1 text-muted"></i><span data-i18n="run_origin_label">Origin</span></label>
                        <select class="form-select select2-static" id="filter_run_origin" data-option-keys="filter_all,run_origin_sync,run_origin_cycle,run_origin_manual" data-option-values="all,sync,cycle,manual"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-calendar-check me-1 text-muted"></i><span data-i18n="modal_cycle">Payroll Schedule</span></label>
                        <select class="form-select select2-remote" id="filter_run_cycle" data-api="/api/payroll-cycle.options"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1"><i class="fa-solid fa-bullseye me-1 text-muted"></i><span data-i18n="modal_run_purpose">Payment Type</span></label>
                        <select class="form-select select2-static" id="filter_run_purpose" data-option-keys="filter_all,run_purpose_payroll,run_purpose_incentive" data-option-values="all,payroll,incentive"></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="station-filter-clear-row d-none" id="dateFilterClearRow">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearDateFilter">
                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
            </button>
        </div>

        <div class="station-row" id="stationRow">
            <div class="station-col">
                <div class="station-card" data-state="pending_sync">
                    <span data-i18n="state_pending_sync">Pending Pull</span> <span class="station-count">0</span>
                </div>
            </div>
            <div class="station-col">
                <div class="station-card active" data-state="draft">
                    <span data-i18n="state_draft">In Progress</span> <span class="station-count">0</span>
                </div>
            </div>
            <div class="station-col">
                <div class="station-card" data-state="pending_approval">
                    <span data-i18n="state_pending_approval">Pending Approval</span> <span class="station-count">0</span>
                </div>
            </div>
            <div class="station-col">
                <div class="station-card" data-state="approved">
                    <span data-i18n="state_approved">Approved</span> <span class="station-count">0</span>
                </div>
            </div>
            <div class="station-col">
                <div class="station-card" data-state="paid">
                    <span data-i18n="state_paid">Paid</span> <span class="station-count">0</span>
                </div>
            </div>
            <div class="station-col">
                <div class="station-card" data-state="locked">
                    <span data-i18n="state_locked">Locked</span> <span class="station-count">0</span>
                </div>
            </div>
            <div class="station-col station-col--reject">
                <div class="station-card station-card--reject" data-state="rejected">
                    <div class="station-card-inner">
                        <span data-i18n="state_rejected">Rejected (Sent Back)</span> <span class="station-count">0</span>
                    </div>
                </div>
            </div>
            <!-- 2026-08-22, explicit request ("Status ในหน้า Approve มี...Need Information") -- a
                 real third branch off pending_approval, alongside Rejected, added here too so a
                 need_info run isn't invisible/uncounted on the Process List's own station bar. -->
            <div class="station-col station-col--reject">
                <div class="station-card station-card--reject station-card--needinfo" data-state="need_info">
                    <div class="station-card-inner">
                        <span data-i18n="state_need_info">Need Information</span> <span class="station-count">0</span>
                    </div>
                </div>
            </div>
            <div class="station-col station-col--reject">
                <div class="station-card station-card--reject station-card--cancel" data-state="cancelled">
                    <div class="station-card-inner">
                        <span data-i18n="state_cancelled">Cancelled</span> <span class="station-count">0</span>
                    </div>
                </div>
            </div>
        </div>

        <table class="table table-hover table-border align-middle w-100" id="tb_payroll_run">
            <thead class="table-light text-secondary">
                <tr>
                    <!-- 2026-09-02, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ และถ้ามีการอ้างอิงถึง
                         รอบก็ให้แสดงด้วยครับ" -- payroll_runs.run_code (first real consumer of the Document
                         Numbering settings' PAYROLL_RUN row, see DocumentNumberingModel::generateNext())
                         plus, when this run references another (merge_target_run_id), that target's own
                         code/name right underneath -- see runCodeCellHtmlPr() in index.js (replaced the
                         old hover-only icon on Run Name with this always-visible text instead). -->
                    <th scope="col" style="width: 8%;" data-i18n="table_run_code">Code</th>
                    <th scope="col" style="width: 12%;" data-i18n="table_run_name">Run Name</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_period">Pay Period</th>
                    <th scope="col" style="width: 7%;" data-i18n="table_employee_count">Employees</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_net_amount">Net Total</th>
                    <th scope="col" style="width: 8%;" data-i18n="table_created_by">Created By</th>
                    <!-- 2026-08-29, explicit request: "ช่วยเพิ่ม Column ว่า Update ข้อมูลล่าสุดเมื่อไหร่ และใคร
                         เป็นคน Update" -- updated_at/updated_by are already wired on every mutating
                         path (PayrollRunModel::list()'s own comment), just never had their own column. -->
                    <th scope="col" style="width: 9%;" data-i18n="table_updated_at">Last Updated</th>
                    <!-- 2026-08-23, explicit request ("ถ้ามี Comment จากการอนุมัติ ให้นำมาแสดงด้วยใน
                         Column Status แยกอาจยุบรวม Column Status กับ Column Timeline...และในColumn นี้
                         เพิ่มปุ่มดำเนินการที่สามารถกดได้ รวมถึงวันที่ Status เข้าไปด้วย"; widened + given
                         real spacing 2026-08-23 per explicit follow-up: "ช่วยปรับ Design ให้สวยขึ้นหน่อย
                         ครับ ตอนนี้แน่นไปหมด") -- Status, Timeline, and Last Updated collapsed into one
                         column: badge + status date on one row, mini-timeline dots on their own row,
                         a reject/need-info comment chip when present, then the quick-action button.
                         2026-09-02, explicit request: "Column Status ควรมาอยู่รองสุดท้าย" -- moved from
                         right after Pay Period to right before Updated By (the last real data column
                         before the unlabeled Actions column). -->
                    <th scope="col" style="width: 24%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 7%;" data-i18n="table_updated_by">Updated By</th>
                    <!-- 2026-08-27, explicit request: "th ของทุกตาราง ถ้ามีคำว่า Action ให้ตัดออกให้เป็น
                         th เปล่าๆ" -- matches the empty-header convention every other Actions column in
                         this app already uses (e.g. Company Setup's structure tables). -->
                    <th scope="col" style="width: 7%;" class="text-center"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <div class="d-none align-items-center mb-2 bulk-pull-bar" id="bulkPullBar">
            <span class="bulk-pull-bar-count"><strong id="bulkPullCount">0</strong> <span data-i18n="bulk_pull_selected_label">selected</span></span>
            <button type="button" class="btn btn-sm btn-warning" id="btnBulkPull">
                <i class="fa-solid fa-arrow-right-to-bracket me-1"></i><span data-i18n="btn_pull_to_run">Pull to Run</span>
            </button>
        </div>

        <!-- 2026-08-31, same-day follow-up -- PayrollSyncModel::ingest() now blocks (rather than
             silently applying) a re-push from Origami for a process already linked to a payroll run
             on our side, since that could otherwise silently change an in-progress run's own numbers
             on its next recalculate. This card surfaces anything blocked so an admin can review and
             explicitly Apply or Dismiss it -- hidden entirely when there's nothing pending. -->
        <div class="card-surface p-3 mb-3 d-none" id="blockedSyncUpdatesCard">
            <div class="d-flex align-items-center mb-2">
                <i class="fa-solid fa-triangle-exclamation text-warning me-2"></i>
                <span class="fw-semibold" data-i18n="blocked_sync_updates_title">Blocked Sync Updates</span>
            </div>
            <p class="small text-muted mb-3" data-i18n="blocked_sync_updates_description">Origami tried to update a process that's already linked to a payroll run on our side. Review before applying, so the run's numbers don't change unexpectedly.</p>
            <div id="blockedSyncUpdatesList"></div>
        </div>

        <table class="table table-hover table-border align-middle w-100 d-none" id="tb_pending_sync">
            <thead class="table-light text-secondary">
                <tr>
                    <th scope="col" style="width: 3%;"><input type="checkbox" id="pendingSyncSelectAll"></th>
                    <th scope="col" style="width: 20%;" data-i18n="table_process_no">Process No</th>
                    <th scope="col" style="width: 18%;" data-i18n="table_period">Pay Period</th>
                    <th scope="col" style="width: 12%;" data-i18n="table_frequency">Frequency</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_employee_count">Employees</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_unmapped">Unmapped</th>
                    <th scope="col" style="width: 14%;" data-i18n="table_received_at">Received</th>
                    <th scope="col" style="width: 13%;"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

    <!-- pendingSyncViewModal / bulkPullModal / payrollRunModal / cancelRunModal / runWorkflowModal /
         runErrorEmployeesModal moved to app/views/layout/modals.php (2026-08-30, modal
         consolidation). runMarkPaidModal (was duplicated verbatim here AND on payroll/detail.php)
         is now a SINGLE shared copy there -- both this page's own index.js and detail.js already
         only ever look it up by id (#runMarkPaidModal/#runMarkPaidForm), no ancestor/proximity
         selectors, so one shared copy works for both pages unchanged. -->

    <!-- 2026-09-02, explicit request: "เพิ่มให้ Export เป็น PDF ได้ด้วย...การ Export กดแล้ว แสดงตัวอย่าง
         แล้วค่อยเลือกจะ Download ภาษาไทยหรือภาษาอังกฤษ" -- this page's own small preview-then-choose-
         language modal for the per-row PAYROLL_REGISTER PDF export, same pattern Process Detail's
         own #reportPreviewModal already established (kept as a SEPARATE local copy rather than
         moved into layout/modals.php, since this page's own PDF preview only ever targets ONE
         report/format, not a generic "preview whichever report row was clicked" mechanism the way
         Detail's own modal is -- see runRegisterPdfPreview() in index.js). -->
    <div class="modal fade" id="runRegisterPdfPreviewModal" data-footer="view" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" data-i18n="payroll_register_report">ทะเบียนรายได้-รายหักพนักงาน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="runRegisterPdfPreviewLoading" class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin fa-2x"></i></div>
                    <iframe id="runRegisterPdfPreviewFrame" class="d-none" style="width:100%; height:70vh; border:0;" title="Payroll Register preview"></iframe>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light me-auto" data-bs-dismiss="modal" data-i18n="close">Close</button>
                    <button type="button" class="btn btn-outline-secondary btn-run-register-pdf-download" data-language="th"><img src="<?=BASE_URL?>/public/flags/th.png" width="16" height="16" alt="TH" class="me-1"><span data-i18n="language_th">Thai</span></button>
                    <button type="button" class="btn btn-primary btn-run-register-pdf-download" data-language="en"><img src="<?=BASE_URL?>/public/flags/gb.png" width="16" height="16" alt="EN" class="me-1"><span data-i18n="language_en">English</span></button>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="<?=asset('public/js/payroll/index.js')?>"></script>
