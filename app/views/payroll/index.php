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
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-money-check-dollar me-2"></i>
            <span data-i18n="payroll_process">Payroll Process</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="payroll_process_description">Manage payroll runs from draft through approval, payment, and closing. Click a row to open its management page.</p>
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
                    <th scope="col" style="width: 20%;" data-i18n="table_run_name">Run Name</th>
                    <th scope="col" style="width: 15%;" data-i18n="table_period">Pay Period</th>
                    <th scope="col" style="width: 9%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 9%;" data-i18n="table_employee_count">Employees</th>
                    <th scope="col" style="width: 12%;" data-i18n="table_net_amount">Net Total</th>
                    <th scope="col" style="width: 13%;" data-i18n="table_created_by">Created By</th>
                    <th scope="col" style="width: 12%;" data-i18n="table_updated_at">Last Updated</th>
                    <th scope="col" style="width: 10%;" class="text-center" data-i18n="col_actions">Actions</th>
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

    <!-- Pending Sync View Modal -->
    <div class="modal fade" id="pendingSyncViewModal" tabindex="-1" aria-labelledby="pendingSyncViewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="pendingSyncViewModalLabel">
                        <i class="fa-solid fa-file-lines me-1"></i><span data-i18n="modal_view_sync_title">Sync Data Detail</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="pendingSyncViewBody">
                    <div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i> <span data-i18n="loading">Loading...</span></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Pull Modal -->
    <div class="modal fade" id="bulkPullModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="bulkPullModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="bulkPullModalLabel">
                        <i class="fa-solid fa-arrow-right-to-bracket me-1"></i><span data-i18n="bulk_pull_modal_title">Pull Selected to Runs</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small" data-i18n="bulk_pull_modal_description">Each item below becomes its own separate payroll run -- set the cycle and period for each one.</p>
                    <div id="bulkPullRows"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnBulkPullSubmit"><span data-i18n="save">Save</span></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Payroll Run Modal -->
    <div class="modal fade" id="payrollRunModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="payrollRunModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="payrollRunModalLabel">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="payroll_run">Payroll Run</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="payrollRunForm" novalidate>
                    <input type="hidden" id="run_sync_process_id" name="sync_process_id" value="">
                    <div class="modal-body">
                        <div class="row mb-3" id="run_offcycle_row">
                            <div class="col-sm-9 offset-sm-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="run_is_offcycle">
                                    <label class="form-check-label" for="run_is_offcycle" data-i18n="offcycle_run_label">Off-cycle run (no payroll cycle needed -- e.g. an out-of-cycle payment)</label>
                                </div>
                            </div>
                        </div>
        <div class="row mb-3 d-none" id="run_purpose_row">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="modal_run_purpose">Run Purpose</label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="run_purpose" name="run_purpose"
                                        data-option-keys="run_purpose_payroll,run_purpose_incentive" data-option-values="payroll,incentive"></select>
                            </div>
                        </div>
                        <div class="row mb-3 d-none" id="run_compute_statutory_row">
                            <div class="col-sm-9 offset-sm-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="run_compute_statutory" checked>
                                    <label class="form-check-label" for="run_compute_statutory" data-i18n="compute_statutory_label">Compute tax/social security (SSO/PVD) for this payment</label>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3" id="run_cycle_row">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_cycle">Payroll Cycle</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-remote required" id="run_cycle_id" name="cycle_id" data-api="/api/payroll-cycle.options"></select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_run_name">Run Name</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <input type="text" class="form-control required" id="run_name" name="run_name" data-i18n="run_name_placeholder" placeholder="e.g., Payroll July 2026">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_period_start">Period Start Date</span> <span class="text-danger" id="run_period_required_mark">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input type="text" class="form-control required datepicker" id="run_period_start" name="period_start_date" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                            <div class="col-sm-1 align-self-center text-center text-muted">-</div>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input type="text" class="form-control required datepicker" id="run_period_end" name="period_end_date" data-i18n="modal_period_end" placeholder="Period End" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_payment_date">Payment Date</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input type="text" class="form-control required datepicker" id="run_payment_date" name="payment_date" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_notes">Notes</span></label>
                            </div>
                            <div class="col-sm-9">
                                <textarea class="form-control" id="run_notes" name="notes" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary"><span data-i18n="save">Save</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Run Modal (row action -- same reason-required flow as the Detail page's own cancel
         modal; needs a hidden run id here since this page has many rows, not one) -->
    <div class="modal fade" id="cancelRunModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="cancelRunModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="cancelRunModalLabel">
                        <i class="fa-solid fa-ban me-1"></i><span data-i18n="cancel_modal_title">Cancel Payroll Run</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="cancelRunForm" novalidate>
                    <input type="hidden" id="cancel_run_id" value="">
                    <div class="modal-body">
                        <label class="form-label"><span data-i18n="cancel_reason_label">Cancel Reason</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="cancel_reason" name="reason" rows="3" data-i18n="cancel_reason_placeholder" placeholder="Explain why this payroll run is being cancelled..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-danger"><span data-i18n="confirm_cancel_run">Confirm Cancellation</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
<script src="<?=asset('public/js/payroll/index.js')?>"></script>
