<style>
#tb_payroll_approval tbody tr { cursor: pointer; }
</style>
<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="payroll_approval">Payroll Approval</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-clipboard-check"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="payroll_approval">Payroll Approval</h5>
            <p class="page-header-card-desc" data-i18n="payroll_approval_description">Payroll runs waiting for your approval. Click a row to review, or use the checkboxes to approve/reject several at once.</p>
        </div>
    </div>

    <!-- 2026-08-22, explicit request ("ในหน้า Approve ให้มี Filter และมี Station Status เพื่อให้
         กรองข้อมูลได้ด้วย รูปแบบการแสดงผลให้เหมือน [Origami]") -- exact same .station-filter/
         .station-row/.station-card component already built for the Payroll Process list page
         (app/views/payroll/index.php), trimmed to only the 3 states relevant to an approver. Wired
         the same way in approval.js: registerApprovalStationFilter()/updateApprovalStationCounts(). -->
    <div class="station-filter" id="approvalStationFilter">
        <span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="approvalStationFilterToggle" title="Toggle filter">
            <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
            <div class="row g-2">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1"><span data-i18n="filter_date_from">From</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="approval_filter_date_from" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1"><span data-i18n="filter_date_to">To</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control datepicker" id="approval_filter_date_to" autocomplete="off">
                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnClearApprovalDateFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
    </div>

    <!-- Card labels use page-scoped lang keys (approval_station_*), not the global state_* keys
         used elsewhere in the app (status badges, Detail page timeline, Process List's own station
         bar) -- explicit request was scoped to "ในหน้า Approve" (on the Approve page specifically),
         so "Waiting Approve"/"Not Approve" stay local to this page's cards instead of an unreviewed
         sitewide relabel of "Pending Approval"/"Rejected". -->
    <div class="station-row" id="approvalStationRow">
        <div class="station-col">
            <div class="station-card active" data-state="pending_approval">
                <span data-i18n="approval_station_pending_approval">Waiting Approve</span> <span class="station-count">0</span>
            </div>
        </div>
        <!-- 2026-08-22, explicit request ("ย้าย Need Info มาไว้ต่อจาก wait") -- moved right after
             Waiting Approve, in the main flow rather than grouped with the Not Approve branch card
             below (a run in need_info is a temporary hold, not a terminal outcome the way
             rejected/cancelled are) -- so it drops the rotated "branch-off" shape too
             (station-card--reject). Follow-up ("Need Information ให้เป็นสีเดียวกันกับ waiting เลย")
             -- also dropped station-card--needinfo's blue accent here, now plain .station-card so
             its active color matches Waiting Approve's default orange exactly (Process List's own
             station bar keeps the blue-accented rotated version -- only this page's card changed). -->
        <div class="station-col">
            <div class="station-card" data-state="need_info">
                <span data-i18n="approval_station_need_info">Need Information</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col">
            <div class="station-card" data-state="approved">
                <span data-i18n="state_approved">Approved</span> <span class="station-count">0</span>
            </div>
        </div>
        <div class="station-col station-col--reject">
            <div class="station-card station-card--reject" data-state="rejected">
                <div class="station-card-inner">
                    <span data-i18n="approval_station_rejected">Not Approve</span> <span class="station-count">0</span>
                </div>
            </div>
        </div>
    </div>

        <!-- Bulk action bar (2026-08-22, explicit request: "การอนุมุติให้มี checkbox เลือกอนุมุติได้
             หลายรายการพร้อมกัน") -- same .bulk-pull-bar idiom already used by this page's own
             Pending Sync picker on the Process list, reused here for consistency. -->
        <div class="d-none align-items-center mb-3 bulk-pull-bar" id="approvalBulkBar">
            <span class="bulk-pull-bar-count"><strong id="approvalBulkCount">0</strong> <span data-i18n="bulk_pull_selected_label">selected</span></span>
            <button type="button" class="btn btn-sm btn-success" id="btnBulkApprove">
                <i class="fa-solid fa-check me-1"></i><span data-i18n="approval_bulk_approve">Approve Selected</span>
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="btnBulkRequestInfo">
                <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="approval_bulk_request_info">Request Info</span>
            </button>
            <button type="button" class="btn btn-sm btn-danger" id="btnBulkReject">
                <i class="fa-solid fa-xmark me-1"></i><span data-i18n="approval_bulk_reject">Reject Selected</span>
            </button>
        </div>
        <!-- 2026-08-22, bug fix (explicit report: "ตอนนี้ไม่มีข้อมูลไม่เห็นตาราง" -- the table used
             to hide itself entirely when zero rows matched, leaving only a placeholder message).
             Table now stays visible always, same as the Process List page's own table, relying on
             DataTables' native zeroRecords/emptyTable text via the shared getTableLang() helper. -->
        <table class="table table-hover table-border align-middle w-100" id="tb_payroll_approval">
            <thead class="table-light text-secondary">
                <tr>
                    <th scope="col" style="width: 3%;"><input type="checkbox" id="approvalSelectAll"></th>
                    <th scope="col" style="width: 15%;" data-i18n="table_run_name">Run Name</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_period">Pay Period</th>
                    <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 8%;" data-i18n="table_employee_count">Employees</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_net_amount">Net Total</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_submitted_by">Submitted By</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_submitted_at">Submitted At</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_updated_at">Last Updated</th>
                    <!-- 2026-08-23, explicit request ("ในหน้า Approve ให้แยก Column ระหว่าง ปุ่มอนุมัติ
                         และปุ่มที่กดดูข้อมูลครับ") -- View/Timeline (informational, every row) and
                         Approve/Reject/Request Info (the actual decision, pending_approval rows
                         only) now sit in their own columns instead of one shared button group. -->
                    <th scope="col" style="width: 6%;" class="text-center" data-i18n="view">View</th>
                    <!-- 2026-08-27, explicit request: blank out any "Action(s)" header, matches the
                         empty-header convention every other Actions column in this app already uses. -->
                    <th scope="col" style="width: 8%;" class="text-center"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

    <!-- Approve modal (2026-08-22): note is optional, matches PayrollRunModel::approve()'s
         optional $note param. The same modal/form serves both a single-row approve (id array of 1)
         and the bulk-approve action -- #approve_run_ids holds the target ids as a JSON array. -->
    <div class="modal fade" id="approveRunModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="approveRunModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="approveRunModalLabel">
                        <i class="fa-solid fa-check me-1"></i><span data-i18n="approve_modal_title">Approve Payroll Run</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="approveRunForm" novalidate>
                    <input type="hidden" id="approve_run_ids" value="[]">
                    <div class="modal-body">
                        <p class="text-muted small" id="approveRunSummary"></p>
                        <label class="form-label" data-i18n="approve_note_label">Note (optional)</label>
                        <textarea class="form-control" id="approve_note" name="note" rows="3" data-i18n="approve_note_placeholder" placeholder="Any comment for this approval..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-success"><span data-i18n="approval_confirm_approve">Confirm Approve</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject modal (2026-08-22) -- required reason, same required-textarea validation as
         index.php's own cancelRunForm/cancel_reason (mirrored here on purpose for consistency). -->
    <div class="modal fade" id="rejectRunModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="rejectRunModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="rejectRunModalLabel">
                        <i class="fa-solid fa-xmark me-1"></i><span data-i18n="reject_modal_title">Reject Payroll Run</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="rejectRunForm" novalidate>
                    <input type="hidden" id="reject_run_ids" value="[]">
                    <div class="modal-body">
                        <p class="text-muted small" id="rejectRunSummary"></p>
                        <label class="form-label"><span data-i18n="reject_reason_label">Reject Reason</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="reject_reason" name="reason" rows="3" data-i18n="reject_reason_placeholder" placeholder="Explain what needs to be fixed before resubmitting..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-danger"><span data-i18n="approval_confirm_reject">Confirm Reject</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Request Info modal (2026-08-22, explicit request: "Need Information" as a real third
         state) -- required reason, exact same shape/validation as the Reject modal above. -->
    <div class="modal fade" id="requestInfoRunModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="requestInfoRunModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="requestInfoRunModalLabel">
                        <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="request_info_modal_title">Request Information</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="requestInfoRunForm" novalidate>
                    <input type="hidden" id="request_info_run_ids" value="[]">
                    <div class="modal-body">
                        <p class="text-muted small" id="requestInfoRunSummary"></p>
                        <label class="form-label"><span data-i18n="request_info_reason_label">What information is needed?</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="request_info_reason" name="reason" rows="3" data-i18n="request_info_reason_placeholder" placeholder="Explain what additional information is needed before this can be decided..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary"><span data-i18n="approval_confirm_request_info">Confirm</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Approval Timeline modal (2026-08-22, explicit request: "มีปุ่มให้กดดู Timeline ของการ
         อนุมัติได้ด้วย") -- reuses the SAME .process-timeline/.tl-step visual language already
         built for the Payroll Process Detail page (public/css/style.css, adapted from Origami's
         own timeline), rendered read-only (no step action buttons) from the row data already
         loaded in this page's own table -- no extra AJAX call needed. -->
    <div class="modal fade" id="approvalTimelineModal" tabindex="-1" aria-labelledby="approvalTimelineModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-secondary mb-0" id="approvalTimelineModalLabel">
                            <i class="fa-solid fa-list-check me-1"></i><span data-i18n="approval_timeline_title">Approval Timeline</span>
                        </h5>
                        <div class="text-muted small" id="approvalTimelineRunName"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="process-timeline-wrap" id="approvalTimelineModalBody"></div>
                </div>
                <div class="modal-footer justify-content-between">
                    <div id="approvalTimelineModalActions" class="d-flex flex-wrap gap-2"></div>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="<?=asset('public/js/payroll/approval.js')?>"></script>
