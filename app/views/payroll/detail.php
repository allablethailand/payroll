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
            <!-- 2026-08-29, explicit request: "เพิ่มให้สามารถปริ้น Report จากหน้า Process ได้...จากหน้า List
                 และ Detail" -- same shortcut buttons as the List page's own row dropdown, see
                 renderRunReportsButtons() in detail.js. -->
            <div id="runReportsButtonWrap"></div>
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
                    <div class="text-muted small" data-i18n="modal_cycle">Payroll Schedule</div>
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
                <!-- 2026-08-28, explicit request: "สามารถแก้ไขได้ด้วยว่าคำนวณเงินเดือนหรือรายรับ
                     รายหักอื่นไหม หรือเป็นการดึงมาทำจ่ายแยก" -- read-only summary of run_purpose/
                     compute_statutory/include_base_salary/include_standing_items (editable via
                     #btnEditRun's modal for an off-cycle run only, same forcing rule as create()). -->
                <div class="col-6 col-md-3">
                    <div class="text-muted small" data-i18n="run_type_label">Run Type</div>
                    <div class="fw-bold" id="infoRunType">-</div>
                </div>
                <!-- 2026-08-29, explicit request: referencing PAYROLL_SYNC_API.md -- shown only for
                     a run pulled from an Origami sync process (sync_process_id set), so it's
                     traceable which Origami cycle/dates this run actually came from. -->
                <div class="col-6 col-md-3 d-none" id="infoSyncSourceWrap">
                    <div class="text-muted small" data-i18n="sync_source_label">Origami Source</div>
                    <div class="fw-bold" id="infoSyncSource">-</div>
                </div>
            </div>
          </div>
          <div class="detail-section d-none" id="pedTypeSettingsSection">
            <div class="mb-3">
                <h6 class="text-secondary fw-bold mb-1">
                    <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                    <span data-i18n="ped_type_settings_title">Income/Deduction Items Used</span>
                </h6>
                <div class="text-muted small" data-i18n="ped_type_settings_hint">The items currently used to calculate this run. Click "Edit" on either side to tick items in or out.</div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="ped-type-panel border rounded-3 p-3 h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="text-success fw-bold mb-0"><i class="fa-solid fa-arrow-trend-up me-1"></i><span data-i18n="breakdown_earnings">Income</span></h6>
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
            <!-- 2026-08-29, explicit request: "สามารถมี checkbox เลือกได้ทีละหลายคนในการ Verify และ Lock"
                 -- same visual language as .bulk-pull-bar elsewhere in this app (warning/amber tint),
                 hidden until at least one row checkbox is checked. -->
            <div class="bulk-pull-bar d-none d-inline-flex" id="runDetailBulkBar">
                <span class="bulk-pull-bar-count"><span id="runDetailBulkCount">0</span> <span data-i18n="employees_selected">employee(s) selected</span></span>
                <button type="button" class="btn btn-sm btn-outline-success" id="btnBulkVerify"><i class="fa-solid fa-check-double me-1"></i><span data-i18n="action_verify">Verify</span></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBulkLock"><i class="fa-solid fa-lock me-1"></i><span data-i18n="action_lock">Lock</span></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBulkUnlock"><i class="fa-solid fa-lock-open me-1"></i><span data-i18n="action_unlock">Unlock</span></button>
            </div>
            <table class="table table-hover table-border align-middle w-100" id="tb_run_detail">
                <thead class="table-light text-secondary">
                    <tr>
                        <th class="text-center"><input type="checkbox" class="form-check-input" id="runDetailSelectAll"></th>
                        <th data-i18n="table_code">Code</th>
                        <th data-i18n="table_name">Name</th>
                        <th class="text-center" data-i18n="table_source">Source</th>
                        <th class="text-end" data-i18n="table_base_salary">Base Salary</th>
                        <th class="text-end" data-i18n="table_gross_amount">Gross</th>
                        <th class="text-end" data-i18n="table_deduction_amount">Deductions</th>
                        <th class="text-end" data-i18n="table_net_pay">Net Pay</th>
                        <th data-i18n="table_calc_status">Calculation</th>
                        <th data-i18n="table_remark">Remark</th>
                        <th class="text-center" data-i18n="table_verify_lock">Verify / Lock</th>
                        <!-- 2026-08-27, explicit request: blank out any "Action(s)" header, matches
                             the empty-header convention every other Actions column already uses. -->
                        <th class="text-center"></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
          </div>
        </div>

        <!-- 2026-08-29, explicit request: "ใส่ Comment ได้ของแต่ละคน กดแล้วเปิดเป็น Modal ให้ใส่ Comment
             เรื่อยๆ เป็น Timeline...ให้มีใส่ tag ได้ว่า กำลังดำเนินการ ดำเนินการเสร็จแล้ว มีข้อผิดพลาด" -- same
             .apv-stage timeline component already used for Action History on this same page (see the
             comment above #tb_run_detail), oldest-first (matches employeeComments()'s own ORDER BY). -->
        <div class="modal fade" id="employeeCommentModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fa-solid fa-comments me-2 text-brand"></i><span data-i18n="employee_comment_timeline_title">Comments</span> - <span id="employeeCommentModalEmployeeName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="employeeCommentTimeline" class="apv-timeline mb-3"></div>
                        <div id="employeeCommentEmpty" class="text-center text-muted small py-3 d-none" data-i18n="employee_comment_timeline_empty">No comments yet.</div>
                        <hr>
                        <!-- 2026-08-29, explicit request: "ตรงใส่ Comment Tag ให้กดเลือกเป็น radio" -- was a
                             select2-static dropdown, now Bootstrap's btn-check/btn-outline-* radio-as-
                             button component (real <input type="radio"> underneath, styled as a
                             segmented toggle) so each tag's own color is visible without opening a
                             dropdown first. -->
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1" data-i18n="employee_comment_tag">Tag</label>
                            <div class="btn-group w-100" role="group" id="employeeCommentTagGroup">
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagNone" value="" checked>
                                <label class="btn btn-outline-secondary btn-sm" for="employeeCommentTagNone" data-i18n="employee_comment_tag_none">No tag</label>
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagInProgress" value="in_progress">
                                <label class="btn btn-outline-warning btn-sm" for="employeeCommentTagInProgress" data-i18n="employee_comment_tag_in_progress">In Progress</label>
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagCompleted" value="completed">
                                <label class="btn btn-outline-success btn-sm" for="employeeCommentTagCompleted" data-i18n="employee_comment_tag_completed">Completed</label>
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagError" value="error">
                                <label class="btn btn-outline-danger btn-sm" for="employeeCommentTagError" data-i18n="employee_comment_tag_error">Error</label>
                            </div>
                        </div>
                        <div class="mb-2">
                            <textarea class="form-control form-control-sm" id="employeeCommentText" rows="3" data-i18n="employee_comment_placeholder" placeholder="Write a comment..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnCancelEditEmployeeComment" data-i18n="cancel">Cancel</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" data-i18n="close">Close</button>
                        <button type="button" class="btn btn-primary btn-sm" id="btnAddEmployeeComment"><i class="fa-solid fa-plus me-1"></i><span id="btnAddEmployeeCommentLabel" data-i18n="employee_comment_add">Add Comment</span></button>
                    </div>
                </div>
            </div>
        </div>
        <!-- 2026-08-27, explicit request: "ในหน้า Process Detail Tab Action History ปรับจากตารางเป็น
             Timeline สวยๆ" -- was a plain DataTable (5 columns: Date/Time, Action, Status Change,
             Performed By, Note). Replaced with the SAME `.apv-stage` circular-marker/connector-line
             design this page already uses for its own Timeline modal/status card
             (apvCreatedStageHtmlRd()/apvApprovalStageHtmlRd()/apvPaidStageHtmlRd() in detail.js) --
             reusing an already-established "nice timeline" component on this exact page rather than
             inventing a new visual pattern, see renderAuditHistoryTimelineRd()'s own docblock. -->
        <div class="tab-pane fade" id="run-history-pane" role="tabpanel" aria-labelledby="run-history-tab" tabindex="0">
            <div id="noAuditYet" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-clock-rotate-left fa-2x mb-3 text-secondary opacity-50"></i>
                <span data-i18n="no_history_yet">No action has been taken on this request yet.</span>
            </div>
            <div id="run_audit_timeline" class="apv-timeline"></div>
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
                        <!-- 2026-08-28, explicit request -- only shown/editable for a genuine
                             off-cycle run (no payroll cycle, not pulled from a sync process); a
                             cycle-based/Pending-Pull run is always full payroll and this whole
                             block stays hidden, same gate as PayrollRunModel::update() itself
                             enforces server-side (see edit_run_type_hint below). -->
                        <div class="d-none" id="edit_run_type_section">
                            <div class="row mb-3" id="edit_run_purpose_row">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-0" data-i18n="modal_run_purpose">Run Purpose</label>
                                </div>
                                <div class="col-sm-9">
                                    <select class="form-select select2-static" id="edit_run_purpose" name="run_purpose"
                                            data-option-keys="run_purpose_payroll,run_purpose_incentive" data-option-values="payroll,incentive"></select>
                                </div>
                            </div>
                            <div class="row mb-3 d-none" id="edit_run_compute_statutory_row">
                                <div class="col-sm-9 offset-sm-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_run_compute_statutory">
                                        <label class="form-check-label" for="edit_run_compute_statutory" data-i18n="compute_statutory_label">Compute tax/social security (SSO/PVD) for this payment</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3 d-none" id="edit_run_include_base_salary_row">
                                <div class="col-sm-9 offset-sm-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_run_include_base_salary">
                                        <label class="form-check-label" for="edit_run_include_base_salary" data-i18n="include_base_salary_label">Include base salary (full amount, not prorated)</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3 d-none" id="edit_run_include_standing_items_row">
                                <div class="col-sm-9 offset-sm-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_run_include_standing_items">
                                        <label class="form-check-label" for="edit_run_include_standing_items" data-i18n="include_standing_items_label">Include configured income/deduction items (standing PED assignments + Recurring Allowances)</label>
                                    </div>
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
                    <!-- 2026-08-21, explicit request ("Modal Manage Payment Items อยากให้ปรับรูปแบบให้
                         ใช้งานง่ายขึ้น") -- was 5 sections stacked in one long scroll (heaviest on a
                         sync-based run, which showed all 5). Split into tabs, same nav-tabs/tab-content
                         idiom already used elsewhere in this app (e.g. Setup & Rules' 5-tab layout) --
                         Tab 1 is the core content relevant on every run; Tabs 2/3 are sync-only, their
                         <li> hidden/shown by openManageLinesModal() the same way the sections' d-none
                         used to be toggled, and reset to Tab 1 every time the modal opens. -->
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="manageLinesItemsTab" data-bs-toggle="tab" data-bs-target="#manageLinesItemsPane" type="button" role="tab">
                                <i class="fa-solid fa-list-check me-1"></i><span data-i18n="manage_items_tab_items">Payment Items</span>
                            </button>
                        </li>
                        <li class="nav-item d-none" id="manageLinesAttendanceTabWrap" role="presentation">
                            <button class="nav-link" id="manageLinesAttendanceTab" data-bs-toggle="tab" data-bs-target="#manageLinesAttendancePane" type="button" role="tab">
                                <i class="fa-solid fa-calendar-check me-1"></i><span data-i18n="manage_items_tab_attendance">Attendance Data</span>
                            </button>
                        </li>
                        <li class="nav-item d-none" id="manageLinesSyncOverrideTabWrap" role="presentation">
                            <button class="nav-link" id="manageLinesSyncOverrideTab" data-bs-toggle="tab" data-bs-target="#manageLinesSyncOverridePane" type="button" role="tab">
                                <i class="fa-solid fa-sliders me-1"></i><span data-i18n="manage_items_tab_adjustments">Deduction Adjustments</span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom p-3">
                        <div class="tab-pane fade show active" id="manageLinesItemsPane" role="tabpanel">
                            <div class="add-manual-line-card border rounded-3 p-3 bg-light bg-opacity-50 mb-4">
                                <div class="d-flex justify-content-end mb-2">
                                    <div class="btn-group btn-group-sm" role="group" id="manualLineModeToggle">
                                        <button type="button" class="btn btn-outline-secondary active" data-mode="catalog"><i class="fa-solid fa-list me-1"></i><span data-i18n="manual_line_mode_catalog">From List</span></button>
                                        <button type="button" class="btn btn-outline-secondary" data-mode="custom"><i class="fa-solid fa-pen me-1"></i><span data-i18n="manual_line_mode_custom">Custom Item</span></button>
                                    </div>
                                </div>
                                <div class="row g-2 align-items-end" id="manualLineCatalogFields">
                                    <div class="col-12">
                                        <label class="form-label mb-1 small text-muted" data-i18n="select_item_placeholder">Select an income/deduction item</label>
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
                                <!-- Transfer-to-payee (2026-08-21, explicit request: "หักเพื่อไปจ่ายให้ใคร
                                     โดยเลือกพนักงานได้ว่าจะหักของคนนี้ไปให้คนนี้") -- only meaningful when
                                     the item being added is a deduction, toggled alongside the existing
                                     earning/deduction type preview (updateManualLineTypePreviewRd() in
                                     detail.js). Reuses /api/employee.report_to.get (data-exclude-id set to
                                     the employee this modal is currently managing) rather than a new
                                     endpoint. -->
                                <div class="row g-2 align-items-end mt-1 d-none" id="manualLinePayeeWrapper">
                                    <div class="col-12">
                                        <label class="form-label mb-1 small text-muted" data-i18n="payee_employee_label">Payee Employee (transfer to)</label>
                                        <select class="form-select select2-remote" id="manualLinePayeeEmployee" data-api="/api/employee.report_to.get" data-type="employee"></select>
                                    </div>
                                </div>
                                <div id="manualLineTypePreview" class="small mt-2 d-none"></div>
                                <div class="text-end mt-2">
                                    <button type="button" class="btn btn-primary" id="btnAddManualLine"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_item">Item</span></button>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="ped-type-panel border rounded-3 p-3 h-100 d-flex flex-column">
                                        <h6 class="text-success fw-bold mb-2"><i class="fa-solid fa-arrow-trend-up me-1"></i><span data-i18n="breakdown_earnings">Income</span></h6>
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
                        <!-- Attendance Data (from Sync) (2026-08-21, explicit request: "ต้องการแก้ตัวเลขดิบ
                             ที่ Sync มา ไม่ใช่แค่ยอดเงิน") -- corrects the RAW numbers Origami sent (late
                             minutes, absent days, unpaid leave days, OT hours, trip allowance), which then
                             recompute through the normal calculation on Recalculate. Distinct from "Sync
                             Deduction Adjustments" (next tab), which overrides the resulting BAHT amount
                             instead -- both can be used together. Only shown on a sync-based run
                             (currentRun.sync_process_id, tab wrapper toggled in JS). One combined Save
                             (not per-field) since all 7 fields are one conceptual "corrected timesheet"
                             record, matching payroll_run_sync_item_overrides' one-row-per-employee shape. -->
                        <div class="tab-pane fade" id="manageLinesAttendancePane" role="tabpanel">
                            <p class="text-muted small mb-2" data-i18n="attendance_data_hint">Correct the raw attendance numbers, for this run only -- amounts recompute from your correction.</p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-2">
                                    <thead class="table-light text-secondary small">
                                        <tr>
                                            <th data-i18n="attendance_data_field">Field</th>
                                            <th class="text-end" data-i18n="attendance_data_synced">Synced</th>
                                            <th style="width:140px;" data-i18n="attendance_data_correction">Correction</th>
                                        </tr>
                                    </thead>
                                    <tbody id="attendanceDataRows"></tbody>
                                </table>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1" id="btnResetAttendanceData"><i class="fa-solid fa-rotate-left me-1"></i><span data-i18n="attendance_data_reset_all">Reset All to Synced</span></button>
                                <button type="button" class="btn btn-sm btn-primary" id="btnSaveAttendanceData"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
                            </div>
                        </div>
                        <!-- Sync Deduction Adjustments (2026-08-21, explicit request: "ต้องการปรับค่า สาย
                             ขาดงาน ลาไม่รับเงิน หรือยกเว้นไม่ให้หัก") -- only shown on a sync-based run
                             (currentRun.sync_process_id set, tab wrapper toggled in JS), lists the
                             employee's currently sync-computed deduction lines with an inline
                             override/exclude/reset control per line. Per-run only (confirmed choice),
                             not a standing setting. -->
                        <div class="tab-pane fade" id="manageLinesSyncOverridePane" role="tabpanel">
                            <p class="text-muted small mb-2" data-i18n="sync_line_override_hint">Override the computed amount, or exclude it entirely, for this run only.</p>
                            <div id="syncLineOverrideList"></div>
                        </div>
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
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
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
                <!-- Net Pay pinned in the footer (2026-08-20, explicit request) -- with
                     modal-dialog-scrollable above, the body scrolls internally while this stays
                     visible, so a long Earnings/Deductions/Statutory list never pushes it out of
                     view. -->
                <div class="modal-footer d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-secondary" data-i18n="table_net_pay">Net Pay</span>
                    <span class="fw-bold fs-5" id="breakdownModalNetPay"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Raw Sync Data viewer (2026-08-21, explicit request: "ดูข้อมูลดิบได้...เพื่อทำการ Recheck
         ข้อมูลย้อนหลังได้") -- read-only, shows exactly what Origami sent for this employee
         (PayrollRunModel::RAW_SYNC_DATA_FIELDS -- payroll/attendance fields only, deliberately
         excludes encrypted PII columns also on that row, see that const's own docblock). Only
         opened for a row with data_source='sync' -- a manually-added employee on a sync run has no
         sync row to show here at all. -->
    <div class="modal fade" id="rawSyncDataModal" tabindex="-1" aria-labelledby="rawSyncDataModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-secondary mb-0" id="rawSyncDataModalLabel">
                            <i class="fa-solid fa-file-code me-1"></i><span data-i18n="raw_sync_data_title">Raw Sync Data</span>
                        </h5>
                        <div class="text-muted small" id="rawSyncDataEmployeeName"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- This Run's Settings (2026-08-21, explicit request: "สามารถจัดการได้ว่า คนนี้
                         ไม่ต้องคำนวณภาษี ไม่นำส่งประกันสังคมในรอบนี้") -- per-run, per-employee opt-out,
                         separate from and above the read-only raw data below it since this is the one
                         part of this modal that's actually editable. Same
                         "border rounded-3 p-3 bg-light bg-opacity-50" card idiom as the Manage Items
                         modal's Add Item card. -->
                    <div class="border rounded-3 p-3 bg-light bg-opacity-50 mb-3" id="rawSyncDataExemptionCard">
                        <h6 class="text-secondary fw-bold mb-2"><i class="fa-solid fa-user-shield me-1"></i><span data-i18n="run_exemption_title">This Run's Settings</span></h6>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" id="rawSyncDataExemptTax">
                            </div>
                            <label class="form-label m-0" for="rawSyncDataExemptTax" data-i18n="run_exemption_tax">Exempt from tax calculation this run</label>
                        </div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" id="rawSyncDataExemptSso">
                            </div>
                            <label class="form-label m-0" for="rawSyncDataExemptSso" data-i18n="run_exemption_sso">Exempt from SSO submission this run</label>
                        </div>
                        <div class="text-end mt-2">
                            <button type="button" class="btn btn-sm btn-primary" id="btnSaveRawSyncDataExemption"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
                        </div>
                    </div>
                    <div id="rawSyncDataModalBody"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Join Employees Modal: available on every draft run (2026-08-21, explicit request -- also
         serves as the undo path for the now-universal Remove action). Off-cycle/sync-based run:
         adds an employee to payroll_run_manual_employees, same as always. Genuine cycle-only run
         (membership otherwise fully automatic by date range): the picker (manualEmployeeOptions())
         only ever offers employees this run has previously excluded, so "joining" here always means
         "re-include", never an arbitrary new add -- see PayrollRunModel::joinEmployees(). Picks
         employees, filterable by Department/Position, one or many at once. -->
    <div class="modal fade" id="joinEmployeesModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="joinEmployeesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-secondary mb-0" id="joinEmployeesModalLabel">
                            <i class="fa-solid fa-user-plus me-1"></i><span data-i18n="join_employees_title">Join Employees</span>
                        </h5>
                        <div class="text-muted small" id="joinEmployeesHint"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-sm-3">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="joinFilterDepartment" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <!-- 2026-08-24, explicit request ("ในการดึงพนักงานเข้ามาเพื่อคำนวณเงินเดือน ให้มี
                             Filter ส่วนที่เพิ่มเมื่อสักครู่ด้วยครับ") -- same Team filter just added to
                             Employee List. -->
                        <div class="col-sm-2">
                            <label class="form-label mb-1" data-i18n="team">Team</label>
                            <select class="form-select select2-remote" id="joinFilterTeam" data-api="/api/team.get" data-type="team"></select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label mb-1" data-i18n="position">Position</label>
                            <select class="form-select select2-remote" id="joinFilterPosition" data-api="/api/position.get" data-type="position"></select>
                        </div>
                        <!-- 2026-08-22, explicit request ("ตรง Join Employee อยากให้เพิ่ม Filter
                             รอบเงินเดือนได้ด้วย") -- filters by the employee's own standing payroll
                             cycle (employees.cycle_id), not this run's own cycle. -->
                        <div class="col-sm-3">
                            <label class="form-label mb-1" data-i18n="payroll_cycle">Payroll Schedule</label>
                            <select class="form-select select2-remote" id="joinFilterCycle" data-api="/api/payroll-cycle.options"></select>
                        </div>
                        <div class="col-sm-1 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary w-100" id="btnClearJoinFilter" title="Clear filter">
                                <i class="fa-solid fa-filter-circle-xmark"></i>
                            </button>
                        </div>
                    </div>
                    <!-- 2026-08-24, explicit request ("จัดรูปแบบให้การดึงพนักงานเข้ามาในการคำนวณดำเนินการ
                         ได้ง่ายที่สุด") -- the checkbox-header "select all" below only ever covers the
                         current DataTable page (serverSide:true) -- with a filter narrowed down to
                         (say) one Team, this makes grabbing everyone matching it one click instead of
                         paging through and re-checking the header box on every page. -->
                    <div class="d-flex justify-content-end mb-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnJoinSelectAllMatching">
                            <i class="fa-solid fa-list-check me-1"></i><span data-i18n="select_all_matching">Select All Matching</span>
                            (<span id="joinFilteredCount">0</span>)
                        </button>
                    </div>
                    <table class="table table-hover table-border align-middle w-100" id="tb_join_employees">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th><input type="checkbox" id="joinSelectAll" title="Select all on this page"></th>
                                <th data-i18n="table_code">Code</th>
                                <th data-i18n="table_name">Name</th>
                                <th data-i18n="department">Department</th>
                                <th data-i18n="team">Team</th>
                                <th data-i18n="position">Position</th>
                                <th data-i18n="payroll_cycle">Payroll Schedule</th>
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

    <!-- Approve/Reject/Request Info modals (2026-08-22, explicit request) -- single-run versions
         of the Approval Queue page's own bulk-capable modals (deliberately duplicated, not shared,
         same "keep the already-working page untouched" convention as approval.js's own comments
         explain), scoped to PAYROLL_RUN_ID since this page only ever acts on the one run it's on. -->
    <div class="modal fade" id="runApproveModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runApproveModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="runApproveModalLabel">
                        <i class="fa-solid fa-check me-1"></i><span data-i18n="approve_modal_title">Approve Payroll Run</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="runApproveForm" novalidate>
                    <div class="modal-body">
                        <label class="form-label" data-i18n="approve_note_label">Note (optional)</label>
                        <textarea class="form-control" id="run_approve_note" rows="3" data-i18n="approve_note_placeholder" placeholder="Any comment for this approval..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-success"><span data-i18n="approval_confirm_approve">Confirm Approve</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="runRejectModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runRejectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="runRejectModalLabel">
                        <i class="fa-solid fa-xmark me-1"></i><span data-i18n="reject_modal_title">Reject Payroll Run</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="runRejectForm" novalidate>
                    <div class="modal-body">
                        <label class="form-label"><span data-i18n="reject_reason_label">Reject Reason</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="run_reject_reason" rows="3" data-i18n="reject_reason_placeholder" placeholder="Explain what needs to be fixed before resubmitting..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-danger"><span data-i18n="approval_confirm_reject">Confirm Reject</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="runRequestInfoModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runRequestInfoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="runRequestInfoModalLabel">
                        <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="request_info_modal_title">Request Information</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="runRequestInfoForm" novalidate>
                    <div class="modal-body">
                        <label class="form-label"><span data-i18n="request_info_reason_label">What information is needed?</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="run_request_info_reason" rows="3" data-i18n="request_info_reason_placeholder" placeholder="Explain what additional information is needed before this can be decided..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary"><span data-i18n="approval_confirm_request_info">Confirm</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Mark as Paid modal (2026-08-27, explicit request: "จากอนุมัติแล้ว จะย้ายไป Station จ่ายแล้ว
         กดปุ่มไหน" -- turned out there was NO button anywhere in this app that ever called the
         already-fully-built PayrollRunModel::markPaid()/api/payroll-run.mark-paid; this modal + its
         trigger buttons below are that missing piece). payment_method/payment_reference/
         modal_payment_date i18n keys already existed pre-seeded in en.json/th.json for exactly this
         (unused until now) -- reused as-is. Gated by can_finalize_payroll (new flag, mirrors
         can_approve_payroll/can_process_payroll's own PayrollController::get() pattern), same
         permission PayrollRunModel::markPaid() itself enforces server-side. -->
    <div class="modal fade" id="runMarkPaidModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runMarkPaidModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="runMarkPaidModalLabel">
                        <i class="fa-solid fa-money-check-dollar me-1"></i><span data-i18n="action_mark_paid">Mark as Paid</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="runMarkPaidForm" novalidate>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label"><span data-i18n="payment_method_label">Payment Method</span> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static required" id="run_mark_paid_method" data-option-keys="payment_method_bank_transfer,payment_method_cash,payment_method_cheque" data-option-values="bank_transfer,cash,cheque"></select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" data-i18n="payment_reference_label">Payment Reference</label>
                            <input type="text" class="form-control" id="run_mark_paid_reference" autocomplete="off">
                        </div>
                        <div class="mb-1">
                            <label class="form-label" data-i18n="modal_payment_date">Payment Date</label>
                            <input type="text" class="form-control datepicker" id="run_mark_paid_date" autocomplete="off">
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

    <!-- Approval Timeline modal (2026-08-22, explicit request: same "who needs to approve /
         reversed history / approve-and-revert from here" panel added to the Approval Queue's own
         Timeline modal, also reachable from this page). Approve/Reject/Request Info/Revert only
         render inside when the run is pending_approval AND the viewer actually holds
         can_approve_payroll (see PayrollController::get()'s can_approve_payroll flag) -- this page
         used to show no action buttons at all once a run left draft (explicit request at the
         time); this reopens exactly that one path, scoped to users who can actually act. -->
    <div class="modal fade" id="runTimelineModal" tabindex="-1" aria-labelledby="runTimelineModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary mb-0" id="runTimelineModalLabel">
                        <i class="fa-solid fa-list-check me-1"></i><span data-i18n="approval_timeline_title">Approval Timeline</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="runTimelineModalBody"></div>
                <div class="modal-footer justify-content-between">
                    <div id="runTimelineModalActions" class="d-flex flex-wrap gap-2"></div>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

</div>
<script>
    const PAYROLL_RUN_ID = <?=(int)$runId?>;
</script>
<script src="<?=asset('public/js/payroll/detail.js')?>"></script>
