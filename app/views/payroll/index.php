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
            <div class="station-filter-body">
                <div class="row g-2">
                    <div class="col-sm-4 col-md-3">
                        <label class="form-label mb-1"><span data-i18n="filter_date_from">From</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" id="filter_date_from" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-sm-4 col-md-3">
                        <label class="form-label mb-1"><span data-i18n="filter_date_to">To</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker" id="filter_date_to" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearDateFilter">
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
                    <th scope="col" style="width: 15%;" data-i18n="table_run_name">Run Name</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_period">Pay Period</th>
                    <!-- 2026-08-23, explicit request ("ถ้ามี Comment จากการอนุมัติ ให้นำมาแสดงด้วยใน
                         Column Status แยกอาจยุบรวม Column Status กับ Column Timeline...และในColumn นี้
                         เพิ่มปุ่มดำเนินการที่สามารถกดได้ รวมถึงวันที่ Status เข้าไปด้วย"; widened + given
                         real spacing 2026-08-23 per explicit follow-up: "ช่วยปรับ Design ให้สวยขึ้นหน่อย
                         ครับ ตอนนี้แน่นไปหมด") -- Status, Timeline, and Last Updated collapsed into one
                         column: badge + status date on one row, mini-timeline dots on their own row,
                         a reject/need-info comment chip when present, then the quick-action button. -->
                    <th scope="col" style="width: 24%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 7%;" data-i18n="table_employee_count">Employees</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_net_amount">Net Total</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_created_by">Created By</th>
                    <!-- 2026-08-29, explicit request: "ช่วยเพิ่ม Column ว่า Update ข้อมูลล่าสุดเมื่อไหร่ และใคร
                         เป็นคน Update" -- updated_at/updated_by are already wired on every mutating
                         path (PayrollRunModel::list()'s own comment), just never had their own column. -->
                    <th scope="col" style="width: 10%;" data-i18n="table_updated_at">Last Updated</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_updated_by">Updated By</th>
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

</div>
<script src="<?=asset('public/js/payroll/index.js')?>"></script>
