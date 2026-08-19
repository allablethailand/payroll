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

    <div class="card-surface mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h5 class="fw-bold mb-0" id="runNameHeading">-</h5>
                    <span id="runStateBadge"></span>
                </div>
                <div class="text-danger small mt-2 d-none" id="rejectReasonBox"></div>
                <div class="text-muted small mt-2 d-none" id="cancelReasonBox"></div>
            </div>
        </div>
        <div class="process-timeline-wrap" id="runProcessTimeline"></div>
        <div id="nextStepBanner" class="next-step-banner"></div>
    </div>

    <div class="alert alert-danger small d-none" id="validationErrorsBanner"></div>

    <ul class="nav nav-tabs" id="runDetailTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary active" id="run-details-tab" data-bs-toggle="tab" data-bs-target="#run-details-pane" type="button" role="tab" aria-controls="run-details-pane" aria-selected="true">
                <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="tab_run_details">Details</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="run-history-tab" data-bs-toggle="tab" data-bs-target="#run-history-pane" type="button" role="tab" aria-controls="run-history-pane" aria-selected="false">
                <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="tab_action_history">Action History</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5" id="runDetailTabsContent">
        <div class="tab-pane fade show active" id="run-details-pane" role="tabpanel" aria-labelledby="run-details-tab" tabindex="0">
          <div class="detail-section">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h6 class="text-secondary fw-bold mb-0">
                    <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                    <span data-i18n="run_info">Run Information</span>
                </h6>
                <div id="runEditButtonWrap"></div>
            </div>
            <div class="row g-4">
                <div class="col-6 col-md-3">
                    <div class="text-muted small" data-i18n="modal_cycle">Payroll Cycle</div>
                    <div class="fw-bold" id="infoCycle">-</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small" data-i18n="table_period">Pay Period</div>
                    <div class="fw-bold" id="infoPeriod">-</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small" data-i18n="modal_payment_date">Payment Date</div>
                    <div class="fw-bold" id="infoPaymentDate">-</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small" data-i18n="table_created_by">Created By</div>
                    <div class="fw-bold" id="infoCreatedBy">-</div>
                </div>
            </div>
          </div>
          <div class="detail-section d-none" id="pedTypeSettingsSection">
            <div class="mb-3">
                <h6 class="text-secondary fw-bold mb-1">
                    <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                    <span data-i18n="ped_type_settings_title">Earning/Deduction Items Used</span>
                </h6>
                <div class="text-muted small" data-i18n="ped_type_settings_hint">The items currently used to calculate this run. Click "Edit" on either side to tick items in or out.</div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="ped-type-panel border rounded-3 p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="text-success fw-bold mb-0"><i class="fa-solid fa-arrow-trend-up me-1"></i><span data-i18n="breakdown_earnings">Earnings</span></h6>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-ped-type-panel d-none" data-item-type="earning"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="action_edit">Edit</span></button>
                        </div>
                        <div id="pedTypePanelEarning" class="ped-type-chip-list"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="ped-type-panel border rounded-3 p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="text-danger fw-bold mb-0"><i class="fa-solid fa-arrow-trend-down me-1"></i><span data-i18n="table_deduction_amount">Deductions</span></h6>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-ped-type-panel d-none" data-item-type="deduction"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="action_edit">Edit</span></button>
                        </div>
                        <div id="pedTypePanelDeduction" class="ped-type-chip-list"></div>
                    </div>
                </div>
            </div>
          </div>
          <div class="detail-section">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h6 class="text-secondary fw-bold mb-0">
                    <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">3</label>
                    <span data-i18n="employee_breakdown">Employee Breakdown</span>
                </h6>
                <div id="runRecalculateButtonWrap"></div>
            </div>
            <div class="row g-3 mb-5">
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-card-info">
                        <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="table_employee_count">Employees</div>
                            <div class="stat-card-value" id="infoEmployeeCount">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-card-success">
                        <div class="stat-card-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="table_gross_amount">Gross</div>
                            <div class="stat-card-value" id="infoGross">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-card-danger">
                        <div class="stat-card-icon"><i class="fa-solid fa-minus"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="table_deduction_amount">Deductions</div>
                            <div class="stat-card-value" id="infoDeduction">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-card-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="table_net_pay">Net Pay</div>
                            <div class="stat-card-value" id="infoNet">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="noDetailsYet" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-calculator fa-2x mb-3 text-secondary opacity-50"></i>
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
                        <th data-i18n="table_remark">Remark</th>
                        <th class="text-center" data-i18n="table_action">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
          </div>
        </div>
        <div class="tab-pane fade" id="run-history-pane" role="tabpanel" aria-labelledby="run-history-tab" tabindex="0">
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

    <!-- Manage Payment Items Modal: per-employee ad-hoc earning/deduction lines (item + amount),
         picked one at a time. For an Incentive/Other Payment run these are the ONLY items counted
         (no base salary/standing PED/attendance bonus); for any other run they're an additive
         adjustment on top of the normal calculation (2026-08-19, explicit request) -- see
         PayrollRunModel::recalculate()'s $isIncentive branch vs. the manual-lines block appended
         to the normal branch. #manageLinesHint's wording switches between the two accordingly. -->
    <div class="modal fade" id="manageLinesModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="manageLinesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-secondary mb-0" id="manageLinesModalLabel">
                            <i class="fa-solid fa-list-check me-1"></i><span data-i18n="manage_items_title">Manage Payment Items</span>
                        </h5>
                        <div class="text-muted small" id="manageLinesEmployeeName"></div>
                        <div class="text-muted small" id="manageLinesHint"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="add-manual-line-card border rounded-3 p-3 bg-light bg-opacity-50 mb-4">
                        <div class="d-flex justify-content-end mb-2">
                            <div class="btn-group btn-group-sm" role="group" id="manualLineModeToggle">
                                <button type="button" class="btn btn-outline-secondary active" data-mode="catalog"><i class="fa-solid fa-list me-1"></i><span data-i18n="manual_line_mode_catalog">From List</span></button>
                                <button type="button" class="btn btn-outline-secondary" data-mode="custom"><i class="fa-solid fa-pen me-1"></i><span data-i18n="manual_line_mode_custom">Custom Item</span></button>
                            </div>
                        </div>
                        <div class="row g-2 align-items-end" id="manualLineCatalogFields">
                            <div class="col-12">
                                <label class="form-label mb-1 small text-muted" data-i18n="select_item_placeholder">Select an earning/deduction item</label>
                                <select class="form-select select2-remote" id="manualLineItemSelect" data-api="/api/employee.earning-deduction.options"></select>
                            </div>
                        </div>
                        <div class="row g-2 align-items-end d-none" id="manualLineCustomFields">
                            <div class="col-sm-8">
                                <label class="form-label mb-1 small text-muted" data-i18n="modal_custom_item_name">Item Name</label>
                                <input type="text" class="form-control" id="manualLineCustomName" maxlength="150" data-i18n="modal_custom_item_name_placeholder" placeholder="e.g. Uniform deposit refund">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label mb-1 small text-muted" data-i18n="modal_item_type">Type</label>
                                <select class="form-select select2-static" id="manualLineCustomType" data-option-keys="breakdown_earnings,table_deduction_amount" data-option-values="earning,deduction"></select>
                            </div>
                        </div>
                        <div class="row g-2 align-items-end mt-1">
                            <div class="col-sm-6">
                                <label class="form-label mb-1 small text-muted" data-i18n="modal_amount">Amount</label>
                                <input type="number" class="form-control" id="manualLineAmount" min="0.01" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label mb-1 small text-muted" data-i18n="modal_comment">Comment</label>
                                <input type="text" class="form-control" id="manualLineComment" maxlength="255" data-i18n="modal_comment_placeholder" placeholder="e.g. August OT shortfall top-up">
                            </div>
                        </div>
                        <div id="manualLineTypePreview" class="small mt-2 d-none"></div>
                        <div class="text-end mt-2">
                            <button type="button" class="btn btn-primary" id="btnAddManualLine"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_item">Add Item</span></button>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="ped-type-panel border rounded-3 p-3 h-100 d-flex flex-column">
                                <h6 class="text-success fw-bold mb-2"><i class="fa-solid fa-arrow-trend-up me-1"></i><span data-i18n="breakdown_earnings">Earnings</span></h6>
                                <ul class="list-group list-group-flush flex-grow-1" id="manualLinesEarningList"></ul>
                                <div class="d-flex justify-content-between fw-bold text-success border-top pt-2 mt-1">
                                    <span data-i18n="manual_line_subtotal_label">Total</span><span id="manualLinesEarningTotal">0.00</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="ped-type-panel border rounded-3 p-3 h-100 d-flex flex-column">
                                <h6 class="text-danger fw-bold mb-2"><i class="fa-solid fa-arrow-trend-down me-1"></i><span data-i18n="table_deduction_amount">Deductions</span></h6>
                                <ul class="list-group list-group-flush flex-grow-1" id="manualLinesDeductionList"></ul>
                                <div class="d-flex justify-content-between fw-bold text-danger border-top pt-2 mt-1">
                                    <span data-i18n="manual_line_subtotal_label">Total</span><span id="manualLinesDeductionTotal">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center border-top pt-2 mt-3">
                        <span class="fw-bold text-secondary" data-i18n="manual_line_net_total">Net Adjustment</span>
                        <span class="fw-bold fs-6" id="manualLinesNetTotal">0.00</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Earning/Deduction Item Selection Modal: opened from either panel's Edit button in
         section 2 -- lists every active item of just that one item_type with a checkbox each (tick
         in/out), scoped so saving one side never touches the other's selection. -->
    <div class="modal fade" id="pedTypeEditModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="pedTypeEditModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="pedTypeEditModalLabel">
                        <i class="fa-solid fa-list-check me-1"></i><span id="pedTypeEditModalTitle">-</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="pedTypeEditModalList"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnSavePedTypeEdit"><span data-i18n="save">Save</span></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Breakdown Modal: per-employee itemized view for one payroll_run_details row, split into
         clearly-labeled Earnings / Deductions / Statutory sections so it's unambiguous which line
         is income and which is a deduction (the main table only shows totals). -->
    <div class="modal fade" id="runDetailBreakdownModal" tabindex="-1" aria-labelledby="runDetailBreakdownModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-secondary mb-0" id="runDetailBreakdownModalLabel">
                            <i class="fa-solid fa-list-check me-1"></i><span data-i18n="breakdown_title">Calculation Breakdown</span>
                        </h5>
                        <div class="text-muted small" id="breakdownEmployeeName"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="breakdownModalBody"></div>
            </div>
        </div>
    </div>

    <!-- Join Employees Modal: only shown for a genuine off-cycle run (no cycle, no sync process) --
         picks employees to add to payroll_run_manual_employees, filterable by Department/Position,
         one or many at once. -->
    <div class="modal fade" id="joinEmployeesModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="joinEmployeesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="joinEmployeesModalLabel">
                        <i class="fa-solid fa-user-plus me-1"></i><span data-i18n="join_employees_title">Join Employees</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-sm-5">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="joinFilterDepartment" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label mb-1" data-i18n="position">Position</label>
                            <select class="form-select select2-remote" id="joinFilterPosition" data-api="/api/position.get" data-type="position"></select>
                        </div>
                        <div class="col-sm-2 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary w-100" id="btnClearJoinFilter">
                                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                            </button>
                        </div>
                    </div>
                    <table class="table table-hover table-border align-middle w-100" id="tb_join_employees">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th><input type="checkbox" id="joinSelectAll"></th>
                                <th data-i18n="table_code">Code</th>
                                <th data-i18n="table_name">Name</th>
                                <th data-i18n="department">Department</th>
                                <th data-i18n="position">Position</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="modal-footer d-flex justify-content-between align-items-center">
                    <div class="text-muted small" id="joinSelectedCount">0 <span data-i18n="bulk_pull_selected_label">selected</span></div>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btnJoinSelected" disabled>
                            <i class="fa-solid fa-user-plus me-1"></i><span data-i18n="action_join_employees">Join Employees</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<script>
    const PAYROLL_RUN_ID = <?=(int)$runId?>;
</script>
<script src="<?=asset('public/js/payroll/detail.js')?>"></script>
