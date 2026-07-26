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

    <div class="card-surface p-3 p-md-4">
        <div class="row mb-3 g-2">
            <div class="col-sm-4 col-md-3">
                <label class="form-label mb-1"><span data-i18n="filter_state">Status</span></label>
                <select class="form-select select2-static" id="filter_state" data-option-keys="state_draft,state_pending_approval,state_approved,state_paid,state_locked,state_rejected" data-option-values="draft,pending_approval,approved,paid,locked,rejected"></select>
            </div>
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
        <table class="table table-hover table-border align-middle w-100" id="tb_payroll_run">
            <thead class="table-light text-secondary">
                <tr>
                    <th scope="col" style="width: 22%;" data-i18n="table_run_name">Run Name</th>
                    <th scope="col" style="width: 16%;" data-i18n="table_period">Pay Period</th>
                    <th scope="col" style="width: 10%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_employee_count">Employees</th>
                    <th scope="col" style="width: 13%;" data-i18n="table_net_amount">Net Total</th>
                    <th scope="col" style="width: 14%;" data-i18n="table_created_by">Created By</th>
                    <th scope="col" style="width: 15%;" data-i18n="table_updated_at">Last Updated</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
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
                    <div class="modal-body">
                        <div class="row mb-3">
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
                                <label class="form-label mb-0"><span data-i18n="modal_period_start">Period Start Date</span> <span class="text-danger">*</span></label>
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
</div>
<script src="<?=asset('public/js/payroll/index.js')?>"></script>
