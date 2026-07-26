<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <a href="<?=BASE_URL?>/payroll-process" class="bc-parent text-decoration-none" data-i18n="payroll_process">Payroll Process</a>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" id="bcRunName">-</span>
        </h5>
    </nav>

    <div class="card-surface p-3 p-md-4 mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1" id="runNameHeading">-</h5>
                <div id="runStateBadge"></div>
                <div class="text-danger small mt-2 d-none" id="rejectReasonBox"></div>
            </div>
            <div class="d-flex gap-2 align-items-start flex-wrap justify-content-end">
                <div id="runActionButtons" class="d-flex gap-2 flex-wrap justify-content-end"></div>
                <a href="<?=BASE_URL?>/payroll-process" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i><span data-i18n="back">Back</span>
                </a>
            </div>
        </div>
        <h6 class="text-secondary fw-bold mb-3">
            <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
            <span data-i18n="run_info">Run Information</span>
        </h6>
        <div class="row g-3">
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="modal_cycle">Payroll Cycle</div>
                <div class="fw-bold" id="infoCycle">-</div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="table_period">Pay Period</div>
                <div class="fw-bold" id="infoPeriod">-</div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="modal_payment_date">Payment Date</div>
                <div class="fw-bold" id="infoPaymentDate">-</div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="table_employee_count">Employees</div>
                <div class="fw-bold" id="infoEmployeeCount">-</div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="table_gross_amount">Gross</div>
                <div class="fw-bold" id="infoGross">-</div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="table_deduction_amount">Deductions</div>
                <div class="fw-bold" id="infoDeduction">-</div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="table_net_pay">Net Pay</div>
                <div class="fw-bold text-primary" id="infoNet">-</div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small" data-i18n="table_created_by">Created By</div>
                <div class="fw-bold" id="infoCreatedBy">-</div>
            </div>
        </div>
    </div>

    <div class="alert alert-danger small d-none" id="validationErrorsBanner"></div>

    <div class="card-surface p-3 p-md-4 mb-4">
        <h6 class="text-secondary fw-bold mb-3">
            <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
            <span data-i18n="employee_breakdown">Employee Breakdown</span>
        </h6>
        <div id="noDetailsYet" class="text-center text-secondary py-4 d-none">
            <i class="fa-solid fa-calculator fa-2x mb-3 d-block text-secondary opacity-50"></i>
            <span data-i18n="no_details_yet">No employees calculated yet. Click "Recalculate" to compute this run.</span>
        </div>
        <table class="table table-hover table-border align-middle w-100" id="tb_run_detail">
            <thead class="table-light text-secondary">
                <tr>
                    <th data-i18n="table_code">Code</th>
                    <th data-i18n="table_name">Name</th>
                    <th class="text-end" data-i18n="table_base_salary">Base Salary</th>
                    <th class="text-end" data-i18n="table_gross_amount">Gross</th>
                    <th class="text-end" data-i18n="table_deduction_amount">Deductions</th>
                    <th class="text-end" data-i18n="table_net_pay">Net Pay</th>
                    <th data-i18n="table_calc_status">Calculation</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div class="card-surface p-3 p-md-4">
        <h6 class="text-secondary fw-bold mb-3">
            <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">3</label>
            <span data-i18n="audit_trail">Audit Trail</span>
        </h6>
        <table class="table table-hover table-border align-middle w-100" id="tb_audit_log">
            <thead class="table-light text-secondary">
                <tr>
                    <th data-i18n="audit_performed_at">Date/Time</th>
                    <th data-i18n="audit_action">Action</th>
                    <th data-i18n="audit_state_change">Status Change</th>
                    <th data-i18n="audit_performed_by">Performed By</th>
                    <th data-i18n="audit_note">Note</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <!-- Edit Run Modal -->
    <div class="modal fade" id="editRunModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="editRunModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="editRunModalLabel">
                        <i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="action_edit">Edit</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editRunForm" novalidate>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_run_name">Run Name</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <input type="text" class="form-control required" id="edit_run_name" name="run_name">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_period_start">Period Start Date</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input type="text" class="form-control required datepicker" id="edit_period_start" name="period_start_date" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                            <div class="col-sm-1 align-self-center text-center text-muted">-</div>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input type="text" class="form-control required datepicker" id="edit_period_end" name="period_end_date" autocomplete="off">
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
                                    <input type="text" class="form-control required datepicker" id="edit_payment_date" name="payment_date" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_notes">Notes</span></label>
                            </div>
                            <div class="col-sm-9">
                                <textarea class="form-control" id="edit_notes" name="notes" rows="2"></textarea>
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

    <!-- Reject Modal -->
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
                    <div class="modal-body">
                        <label class="form-label"><span data-i18n="reject_reason_label">Reject Reason</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="reject_reason" name="reason" rows="3" data-i18n="reject_reason_placeholder" placeholder="Explain what needs to be fixed before resubmitting..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-danger"><span data-i18n="action_reject">Reject</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Mark Paid Modal -->
    <div class="modal fade" id="markPaidModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="markPaidModalLabel">
                        <i class="fa-solid fa-circle-check me-1"></i><span data-i18n="mark_paid_modal_title">Mark as Paid</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="markPaidForm" novalidate>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label"><span data-i18n="modal_payment_date">Payment Date</span> <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control required datepicker" id="paid_payment_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><span data-i18n="payment_method_label">Payment Method</span> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static required" id="paid_payment_method" data-option-keys="payment_method_bank_transfer,payment_method_cash,payment_method_cheque" data-option-values="bank_transfer,cash,cheque"></select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><span data-i18n="payment_reference_label">Payment Reference</span></label>
                            <input type="text" class="form-control" id="paid_payment_reference">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary"><span data-i18n="action_mark_paid">Mark as Paid</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    const PAYROLL_RUN_ID = <?=(int)$runId?>;
</script>
<script src="<?=asset('public/js/payroll/detail.js')?>"></script>
