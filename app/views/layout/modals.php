<?php
/**
 * 2026-08-30, explicit request: "ย้ายทุก modal ไปไว้ที่เดียวกัน และถ้า modal ไหนรวมกันได้ให้รวมกันครับ และ
 * ทุกที่ ที่เรียกใช้งาน modal ต้องไม่มีปัญหาการใช้งาน" -- every modal's own HTML markup in this app, moved
 * out of its individual page view file into this ONE shared partial (confirmed via AskUserQuestion:
 * a physical markup move into one file, not just a design-consistency pass). Included once, from
 * footer.php, on every page -- unconditional, same as footer.php's own pre-existing #systemModal
 * (also moved down here, see the "Shared / global" section below).
 *
 * Investigated first (read-only survey, see this session's own history) before moving anything:
 * - 75 modals across 20 files, every single one already has a real `id`.
 * - Zero modals read page-specific PHP variables in their own markup -- every one populates itself
 *   via JS/AJAX after the page loads, so none were structurally blocked from moving here.
 * - Exactly 2 id collisions existed: `leaveModal` (manual-entry's own leave-RECORD modal vs.
 *   setup-rules' own leave-TYPE config modal -- coincidental name reuse, genuinely different
 *   purposes) and `runMarkPaidModal` (payroll/detail.php vs. payroll/index.php -- confirmed
 *   byte-for-byte identical markup). Both fixed as part of this same move: setup-rules' own copy
 *   renamed `leaveModal` -> `leaveTypeModal` (own JS updated to match, low blast radius -- single-
 *   purpose page, its own script only); `runMarkPaidModal` kept as ONE copy here, both
 *   `payroll/detail.js`/`payroll/index.js` already only ever look it up by id (no ancestor/
 *   proximity selectors), so a single shared copy works for both pages unchanged.
 *
 * Every modal below is grouped by which page/feature originally owned it (comment header per
 * group, same order as this app's own page structure) purely for readability -- physical location
 * has zero effect on behavior, `id`-based lookups work identically regardless of where in the DOM
 * an element lives. The global `show.bs.modal` handler (app.js, header/footer injection + the
 * language-switch corner button) is itself already selector-based (`.modal`, not page-scoped), so
 * it needed no changes at all for this move and keeps working identically for every modal here.
 */
?>

<!-- ===== Global / generic (app/views/layout/footer.php's own pre-existing shell) ===== -->
<div class="modal fade" id="systemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"></div>
            <div class="modal-body"></div>
            <div class="modal-footer"></div>
        </div>
    </div>
</div>

<!-- ===== Annual Income Summary (app/views/reports/annual-summary.php) ===== -->
<!-- 2026-08-30 (Phase 4, T028, explicit request: "ตัดปุ่ม 'ตั้งค่าการตัดรอบปี' ออกจากหน้าสรุปรายได้ประจำปี
     ใช้ค่าจาก Company Profile แทน") -- #aisFiscalYearSettingsModal removed. companies.
     fiscal_year_start_month is still read here (AnnualIncomeSummaryController::fiscalStartMonth())
     but is edited from Company Profile's own "Company Information" section only now -- that's
     already its canonical home (see CompanyProfileModel::save()), this was a redundant second save
     path. -->
<div class="modal fade" id="aisCellDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="aisCellDetailModalTitle">-</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="aisCellDetailBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Global (app/views/layout/header.php) ===== -->
<div class="modal fade" id="userSettingsModal" tabindex="-1" aria-labelledby="userSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="userSettingsModalLabel">
                    <i class="fa-solid fa-gear me-2"></i><span data-i18n="user_settings_menu">Settings</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <label class="form-label fw-semibold" data-i18n="user_settings_font_size_label">Font Size</label>
                    <div class="user-settings-font-slider-wrap">
                        <input type="range" class="form-range" id="userSettingsFontSizeSlider" min="0" max="2" step="1" value="1">
                        <div class="user-settings-font-slider-labels">
                            <span data-font-size-option="s" data-i18n="user_settings_font_size_small">Small</span>
                            <span data-font-size-option="m" data-i18n="user_settings_font_size_medium">Medium</span>
                            <span data-font-size-option="l" data-i18n="user_settings_font_size_large">Large</span>
                        </div>
                    </div>
                    <div class="user-settings-font-preview" id="userSettingsFontPreview" data-i18n="user_settings_font_size_preview">The quick brown fox jumps over the lazy dog.</div>
                </div>
                <div id="userSettingsNotifPrefsWrap">
                    <label class="form-label fw-semibold" data-i18n="notification_preferences_label">Notification Preferences</label>
                    <div id="userSettingsNotifPrefsList" class="d-flex flex-column gap-2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveUserSettings" data-i18n="save">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Reports (app/views/reports/index.php) ===== -->
<div class="modal fade" id="payslipRosterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title mb-0" id="payslipRosterModalTitle">Pay Slip</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle w-100" id="tb_payslip_roster">
                        <thead class="table-light text-secondary small">
                            <tr>
                                <th data-i18n="employee_no">Employee No.</th>
                                <th data-i18n="name">Name</th>
                                <th data-i18n="department">Department</th>
                                <th data-i18n="position">Position</th>
                                <th data-i18n="team">Team</th>
                                <th class="text-center" data-i18n="download_count">Downloaded</th>
                                <th class="text-center"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- annualReportConfigModal removed 2026-08-30 (superseded by reportsPreviewModal's own new
     footer controls -- see that modal's own comment) -- this was the old 2-step flow's first step. -->
<!-- 2026-08-30, explicit request: "ตอนกด Download ให้ขึ้นมา modal เดียวเลย แล้วมี dropdown ให้เลือกใน
     footer ว่า type อะไร แล้วขึ้นปุ่ม download th en ใน body ก็โชว์ Detail ที่โชว์ได้ ไม่ต้อง 2 step" --
     was a 2-step flow (a separate #annualReportConfigModal collected format/month/employee, THEN
     opened this modal) for Annual Reports specifically; Per-Cycle Reports/Pay Slip roster never
     needed that step at all (their format is fixed server-side per row). Now ONE modal handles both:
     the format/month/employee controls live in the footer here, hidden (d-none) unless the report
     actually needs them (openReportsPreview()'s own `extra` config toggles them) -- changing any of
     them live-refreshes the preview in the body instead of requiring a separate confirm step. -->
<div class="modal fade" id="reportsPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" id="reportsPreviewDialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="reportsPreviewModalTitle">-</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="reportsPreviewLoading" class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin fa-2x"></i></div>
                <iframe id="reportsPreviewFrame" class="d-none" style="width:100%; height:70vh; border:0;" title="Report preview"></iframe>
                <div id="reportsPreviewUnavailable" class="text-center d-none py-4 px-4">
                    <div class="report-preview-unavailable-icon mx-auto mb-3">
                        <i class="fa-solid fa-file-circle-exclamation"></i>
                    </div>
                    <div class="fw-semibold text-secondary mb-1" data-i18n="report_preview_unavailable_title">Preview Not Available</div>
                    <div class="text-muted small" data-i18n="report_preview_unavailable">This file type can't be previewed -- download it directly below.</div>
                </div>
                <!-- 2026-08-30: a SEPARATE block from #reportsPreviewUnavailable above (rather than
                     reusing it with JS-mutated text) so its own data-i18n'd copy never risks being
                     silently overwritten back to the generic "unavailable" wording by a language
                     switch re-applying [data-i18n] across the page mid-modal. -->
                <div id="reportsPreviewSelectEmployee" class="text-center d-none py-4 px-4">
                    <div class="report-preview-unavailable-icon mx-auto mb-3">
                        <i class="fa-solid fa-user-clock"></i>
                    </div>
                    <div class="fw-semibold text-secondary mb-1" data-i18n="report_preview_select_employee">Select an employee above to preview.</div>
                </div>
            </div>
            <div class="modal-footer flex-wrap justify-content-between">
                <div class="d-flex flex-wrap gap-2 align-items-center" id="reportsPreviewExtraFields">
                    <div class="d-none" id="reportsPreviewFormatWrap">
                        <select class="form-select form-select-sm" id="reportsPreviewFormatSelect" style="min-width:130px;"></select>
                    </div>
                    <div class="d-none" id="reportsPreviewMonthWrap">
                        <select class="form-select form-select-sm select2-static" id="reportsPreviewMonthSelect" style="min-width:130px;"
                            data-option-keys="month_1,month_2,month_3,month_4,month_5,month_6,month_7,month_8,month_9,month_10,month_11,month_12" data-option-values="1,2,3,4,5,6,7,8,9,10,11,12"></select>
                    </div>
                    <div class="d-none" id="reportsPreviewEmployeeWrap">
                        <select class="form-select form-select-sm select2-remote" id="reportsPreviewEmployeeSelect" style="min-width:200px;" data-api="/api/employee.report_to.get"></select>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                    <button type="button" class="btn btn-outline-primary reports-preview-download-btn" data-language="th"><img src="<?=asset('public/flags/th.png')?>" width="16" height="16" alt="TH" class="me-1"><span data-i18n="language_th">Thai</span></button>
                    <button type="button" class="btn btn-primary reports-preview-download-btn" data-language="en"><img src="<?=asset('public/flags/gb.png')?>" width="16" height="16" alt="EN" class="me-1"><span data-i18n="language_en">English</span></button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cycleReportHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="cycleReportHistoryModalTitle">-</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="station-filter mb-2" id="cycleReportHistoryStationFilter">
                    <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                    <button type="button" class="station-filter-toggle" id="cycleReportHistoryStationFilterToggle" title="Toggle filter">
                        <i class="fas fa-chevron-up"></i>
                    </button>
                    <div class="station-filter-body">
                        <div class="row g-2">
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1"><span data-i18n="filter_date_from">From</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control datepicker" id="cycleReportHistoryDateFrom" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label mb-1"><span data-i18n="filter_date_to">To</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control datepicker" id="cycleReportHistoryDateTo" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="station-filter-clear-row d-none" id="cycleReportHistoryFilterClearRow">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCycleReportHistoryClearFilter">
                        <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle w-100" id="tb_cycle_report_history">
                        <thead class="table-light text-secondary small">
                            <tr>
                                <th data-i18n="downloaded_at">Date/Time</th>
                                <th data-i18n="downloaded_by">By</th>
                                <th data-i18n="language">Language</th>
                                <th data-i18n="device">Device</th>
                                <th data-i18n="browser">Browser</th>
                                <th>IP</th>
                                <th data-i18n="source">Source</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Employee List (app/views/employee/list.php) ===== -->
<div class="modal fade" id="employeeSyncModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="employeeSyncModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="employeeSyncModalLabel">
                    <i class="fa-solid fa-rotate me-1"></i><span data-i18n="employee_sync_button">Sync from Origami</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5 d-none" id="employeeSyncNotConnected">
                    <i class="fa-solid fa-plug-circle-xmark fa-2x text-danger mb-3"></i>
                    <div class="fw-bold mb-1" data-i18n="employee_sync_not_connected_title">Not connected to Origami</div>
                    <div class="text-muted small" id="employeeSyncNotConnectedMessage" data-i18n="employee_sync_not_connected_message">The connection to Origami has not been configured yet. Please contact your system administrator.</div>
                </div>
                <div id="employeeSyncFilterRow" class="d-none">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1" data-i18n="department">Department</label>
                        <select class="form-select" id="sync_filter_department"></select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label mb-1" data-i18n="position">Position</label>
                        <select class="form-select" id="sync_filter_position"></select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1" data-i18n="employee_sync_filter_type">Type</label>
                        <select class="form-select" id="sync_filter_type"></select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1" data-i18n="employee_sync_filter_team">Team (Origami)</label>
                        <select class="form-select" id="sync_filter_team"></select>
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="button" class="btn btn-primary w-100" id="btnFetchSyncCandidates">
                            <i class="fa-solid fa-magnifying-glass me-1"></i><span data-i18n="employee_sync_fetch_button">Fetch</span>
                        </button>
                    </div>
                </div>

                <div id="employeeSyncResultArea" class="d-none">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="es-sync-panel-header es-sync-panel-header-new">
                                <div class="es-sync-panel-header-icon"><i class="fa-solid fa-user-plus"></i></div>
                                <div class="es-sync-panel-header-body">
                                    <h6 class="mb-0"><span data-i18n="employee_sync_tab_new">New</span></h6>
                                    <div class="text-muted small" data-i18n="employee_sync_tab_new_hint">Not in this system yet</div>
                                </div>
                                <span class="badge rounded-pill bg-success" id="syncNewCount">0</span>
                            </div>
                            <div class="es-sync-scroll-box">
                                <table class="table table-hover align-middle w-100 mb-0 es-sync-table" id="tb_sync_new">
                                    <thead class="table-light text-secondary">
                                        <tr>
                                            <th class="es-sync-th-check"><input type="checkbox" id="syncNewSelectAll"></th>
                                            <th data-i18n="employee">Employee</th>
                                            <th data-i18n="employee_sync_dept_position">Department / Position</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="es-sync-panel-header es-sync-panel-header-existing">
                                <div class="es-sync-panel-header-icon"><i class="fa-solid fa-user-check"></i></div>
                                <div class="es-sync-panel-header-body">
                                    <h6 class="mb-0"><span data-i18n="employee_sync_tab_existing">Already Exists</span></h6>
                                    <div class="text-muted small" data-i18n="employee_sync_tab_existing_hint">Already in this system</div>
                                </div>
                                <span class="badge rounded-pill bg-secondary" id="syncExistingCount">0</span>
                            </div>
                            <div class="es-sync-scroll-box">
                                <table class="table table-hover align-middle w-100 mb-0 es-sync-table" id="tb_sync_existing">
                                    <thead class="table-light text-secondary">
                                        <tr>
                                            <th class="es-sync-th-check"><input type="checkbox" id="syncExistingSelectAll"></th>
                                            <th data-i18n="employee">Employee</th>
                                            <th data-i18n="department">Department</th>
                                            <th data-i18n="employee_sync_update_col">Update Available</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-muted small text-center py-4" id="employeeSyncEmptyHint" data-i18n="employee_sync_empty_hint">Set filters (optional) and click Fetch to browse candidates from Origami.</div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <span class="text-muted small" id="syncSelectedCountLabel"></span>
                <div>
                    <button type="button" class="btn btn-primary d-none" id="btnApplyEmployeeSync">
                        <i class="fa-solid fa-download me-1"></i><span data-i18n="employee_sync_apply_button">Sync Selected</span> (<span id="syncSelectedCount">0</span>)
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="employeeSyncLogModal" tabindex="-1" aria-labelledby="employeeSyncLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="employeeSyncLogModalLabel">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="employee_sync_log_title">Employee Sync Log</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="syncLogCards"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Document & Approval (app/views/setup/document-approval.php) ===== -->
<!-- 2026-08-31, explicit request: "เพิ่มปุ่มให้สามารถ Assign ได้ โดยเปิดเป็น Modal ขึ้นมา มีรายละเอียด Master
     Data แล้วแบ่งเป็น 2 Card คือพนักงานที่อยู่ Master อื่น และพนักงานที่อยู่ Master นี้...และมีอีกปุ่มสำหรับกด View
     เพื่อดูเฉพาะพนักงานที่อยู่ใน Master นั้น" -- ONE shared modal (public/js/setup/structure-assign.js)
     driven entirely by data-type/data-id passed from whichever row's Assign/View button opened it
     (company-profile.js's getActionButtons()/setup-rules.js's structureAssignExtraBtns(), both cover
     all 8 assignable master types with zero per-type markup here). #saOutsideCard is hidden in View
     mode (only the "employees in this Master" card + Move Out shown) -- see openStructureAssignModal()'s
     own docblock. -->
<div class="modal fade" id="structureAssignModal" tabindex="-1" aria-labelledby="structureAssignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="structureAssignModalLabel">
                    <i class="fa-solid fa-users me-2"></i><span id="structureAssignModalTitle"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6" id="saOutsideCard">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <span class="fw-bold small" data-i18n="sa_employees_outside">Employees in Other Masters</span>
                            </div>
                            <div class="card-body p-2">
                                <input type="text" class="form-control form-control-sm mb-2" id="saOutsideSearch" placeholder="Search...">
                                <div class="sa-list" id="saOutsideList" style="max-height:340px;overflow-y:auto;"></div>
                            </div>
                            <div class="card-footer text-end bg-white">
                                <button type="button" class="btn btn-primary btn-sm" id="btnSaPullIn" disabled>
                                    <i class="fa-solid fa-arrow-left me-1"></i><span data-i18n="sa_pull_in">Pull In</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="saInCard">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <span class="fw-bold small" data-i18n="sa_employees_in">Employees in This Master</span>
                            </div>
                            <div class="card-body p-2">
                                <input type="text" class="form-control form-control-sm mb-2" id="saInSearch" placeholder="Search...">
                                <div class="sa-list" id="saInList" style="max-height:340px;overflow-y:auto;"></div>
                            </div>
                            <div class="card-footer text-end bg-white">
                                <button type="button" class="btn btn-outline-danger btn-sm" id="btnSaMoveOut" disabled>
                                    <i class="fa-solid fa-arrow-right me-1"></i><span data-i18n="sa_move_out">Move Out</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="documentNumberingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="documentNumberingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="documentNumberingModalLabel">
                    <i class="fa-solid fa-hashtag me-2"></i><span id="documentNumberingModalTypeLabel"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="documentNumberingForm">
                <input type="hidden" id="dn_document_type_code">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><span data-i18n="doc_numbering_prefix">Format (Prefix)</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control required" id="dn_prefix_format" maxlength="50" data-i18n="document_number_prefix_placeholder" placeholder="e.g., INV-{YYYY}-">
                        <div class="text-secondary small mt-1" data-i18n="doc_numbering_prefix_hint">Placeholders: {YYYY} = year, {MM} = month, {YYYYMMDD} = full date.</div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label"><span data-i18n="doc_numbering_digits">Digits</span> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control required" id="dn_digit_count" min="1" max="10" step="1" data-i18n="document_number_digit_count_placeholder" placeholder="e.g., 5">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label"><span data-i18n="doc_numbering_current">Current Number</span> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control required" id="dn_current_number" min="0" step="1" data-i18n="document_number_current_number_placeholder" placeholder="e.g., 1">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" data-i18n="doc_numbering_reset">Reset</label>
                        <select class="form-select select2-static" id="dn_reset_cycle" data-option-keys="reset_cycle_never,reset_cycle_yearly,reset_cycle_monthly" data-option-values="never,yearly,monthly"></select>
                    </div>
                    <div class="text-secondary small" id="documentNumberingPreview"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Payroll Configuration (app/views/setup/payroll-configuration.php) ===== -->
<div class="modal fade" id="payrollCycleModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="payrollCycleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary">
                    <i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="cycle">Schedule</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="payrollCycleForm" novalidate>
                <input type="hidden" name="id" id="cycle_id">
                <div class="modal-body">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <span data-i18n="modal_sec_general">Schedule Information</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_cycle_name">Schedule Name</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="cycle_name" name="cycle_name" data-i18n="cycle_name_placeholder" placeholder="e.g., Office Staff Schedule / Part-time Weekly">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_frequency">Payroll Frequency</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static required" id="payroll_frequency" name="payroll_frequency" data-option-keys="freq_monthly,freq_semi_monthly,freq_weekly,freq_bi_weekly" data-option-values="monthly,semi_monthly,weekly,bi_weekly"></select>
                        </div>
                    </div>
                    <!-- 2026-09-02, reply from Origami's own team re: "Map รอบการจ่ายเงินเดือน" -- an
                         optional exact-match key against Origami's own payroll_period.external_cycle_code
                         (PAYROLL_SYNC_API.md, 2026-09-01 revision), so PayrollCycleModel::
                         matchForSyncProcess() can join a Pending Pull document to the right schedule
                         with certainty instead of guessing from frequency+cutoff+payment-day (which
                         breaks down whenever 2 schedules share the same frequency/dates). Re-enter the
                         SAME code the company's Origami admin set on their own Setup > Period screen. -->
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="modal_external_cycle_code">External Cycle Code</label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="external_cycle_code" name="external_cycle_code" maxlength="100" data-i18n="modal_external_cycle_code_placeholder" placeholder="e.g., PR-MTH-20">
                            <div class="form-text" data-i18n="modal_external_cycle_code_hint">Optional. Must exactly match the code your Origami admin set for this period on their own Setup &gt; Period screen, so incoming documents can be matched to this schedule automatically.</div>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <span data-i18n="modal_sec_dates">Cut-off & Payment Settings</span>
                    </h6>
                    <div class="row mb-3" id="cutoff_dom_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_attendance_cutoff">Attendance Cut-off Day</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" min="1" max="28" class="form-control required" id="cutoff_day_of_month" name="cutoff_day_of_month" data-i18n="day_of_month_placeholder" placeholder="1-28">
                        </div>
                        <div class="col-sm-6 pt-2">
                            <input type="checkbox" class="me-2" id="cutoff_use_last_day" name="cutoff_use_last_day"><span data-i18n="use_last_day_of_month">Use last day of the month</span>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="cutoff_dow_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_attendance_cutoff">Attendance Cut-off Day</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static" id="cutoff_day_of_week" name="cutoff_day_of_week" data-option-keys="dow_monday,dow_tuesday,dow_wednesday,dow_thursday,dow_friday,dow_saturday,dow_sunday" data-option-values="monday,tuesday,wednesday,thursday,friday,saturday,sunday"></select>
                        </div>
                    </div>
                    <p class="text-muted small ms-0 mb-3" data-i18n="day_of_month_hint">*Day must be between 1-28 so it exists in every month, or use "last day of the month".</p>
                    <div class="row mb-3" id="payment_dom_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_payment_day">Payment Day</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" min="1" max="28" class="form-control required" id="payment_day_of_month" name="payment_day_of_month" data-i18n="day_of_month_placeholder" placeholder="1-28">
                        </div>
                        <div class="col-sm-6 pt-2">
                            <input type="checkbox" class="me-2" id="payment_use_last_day" name="payment_use_last_day"><span data-i18n="use_last_day_of_month">Use last day of the month</span>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="payment_dow_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_payment_day">Payment Day</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static" id="payment_day_of_week" name="payment_day_of_week" data-option-keys="dow_monday,dow_tuesday,dow_wednesday,dow_thursday,dow_friday,dow_saturday,dow_sunday" data-option-values="monday,tuesday,wednesday,thursday,friday,saturday,sunday"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3">
                            <label class="form-label pt-1"><span data-i18n="modal_ot_cutoff">OT Cut-off Type</span></label>
                        </div>
                        <div class="col-sm-9">
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="ot_cutoff_type" id="ot_same" value="same_as_attendance" checked>
                                <label class="form-check-label" for="ot_same" data-i18n="same_as_attendance">Same as Attendance</label>
                            </div>
                            <div class="form-check form-check-inline mt-1">
                                <input class="form-check-input" type="radio" name="ot_cutoff_type" id="ot_custom" value="custom">
                                <label class="form-check-label" for="ot_custom" data-i18n="custom_definition">Custom Definition</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="ot_custom_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_ot_cutoff_day">OT Cut-off Day</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" min="1" max="28" class="form-control" id="ot_cutoff_day_of_month" name="ot_cutoff_day_of_month" data-i18n="day_of_month_placeholder" placeholder="1-28">
                        </div>
                        <div class="col-sm-6 pt-2">
                            <input type="checkbox" class="me-2" id="ot_cutoff_use_last_day" name="ot_cutoff_use_last_day"><span data-i18n="use_last_day_of_month">Use last day of the month</span>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">3</label>
                        <span data-i18n="modal_sec_bank">Bank File Configuration</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_bank_format">Bank Text Format</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote required" id="bank_file_format_id" name="bank_file_format_id" data-api="/api/bank-file-format.options"></select>
                        </div>
                    </div>
                    <!-- 2026-09-02, explicit request: "หน้านี้รองรับการเพิ่มมากกว่า 1 บัญชีธนาคารต่อ 1 รอบจ่าย
                         เงินเดือนอยู่แล้วหรือไม่...ถ้ายังไม่มีให้เพิ่ม" -- confirmed real gap, replaces the old
                         single select with a checkbox list (this cycle can offer 2+ accounts) + one
                         "Default" radio scoped to only the CHECKED accounts (exactly one required
                         whenever the list isn't empty -- see PayrollCycleModel::saveBankAccounts()'s
                         own app-layer invariant). An empty list is valid (falls back to the company's
                         own default account, same as before this feature existed). -->
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="modal_cycle_bank_account">Bank Accounts</label>
                        </div>
                        <div class="col-sm-9">
                            <div id="cycleBankAccountsList" class="border rounded-3 p-2" style="max-height:180px;overflow-y:auto;">
                                <div class="text-muted small" data-i18n="loading">Loading...</div>
                            </div>
                            <div class="form-text" data-i18n="modal_cycle_bank_account_hint">Leave every account unchecked to use the company's default bank account.</div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="default_payment_method_label">Default Payment Method</label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="cycle_default_payment_method_id" name="default_payment_method_id" data-api="/api/payment-method.options" allow-clear="true"></select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2026-08-30, explicit request: "ตรงที่เปิดเป็น form จัดการเงินได้เงินหัก ตรง ประเภท * น่าจะตัดออกเพราะกดมา
     จากคนละ Tab หรือเปลี่ยนเป็นแค่แสดงคำเฉยๆ เป็นสีเขียวกับสีแดง เป็นหัวข้อว่ากำลังตั้งค่าอะไร" -- confirmed
     via investigation: item_type was ALREADY locked/disabled by JS before this modal ever became
     visible (resetPedTypeForm()/populatePedTypeForm() in payroll-configuration.js both disable the
     radio group right after checking the right one, since the Add button on the Earning tab vs the
     Deduction tab already fixes it) -- the old *-marked "required" radio group in the body was
     always non-interactive, it just LOOKED like editable required data entry. Removed entirely; the
     modal title is now JUST a colored badge (green=Income, red=Deduction) -- IS the "heading of what
     is being configured" the request asked for, not an addition alongside a separate generic title.
     A hidden #ped_item_type input (in the form body) keeps carrying the real value into the save
     payload -- see payroll-configuration.js's resetPedTypeForm()/populatePedTypeForm() for where the
     badge text/color and hidden value both get set together. -->
<div class="modal fade" id="itemModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="pedTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="pedTypeModalLabel">
                    <i class="fa-solid fa-pen-to-square text-secondary" id="pedTypeModalIcon"></i>
                    <span class="badge fs-6" id="pedTypeModalBadge"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="pedTypeForm" novalidate>
                <input type="hidden" id="ped_type_id" name="id">
                <input type="hidden" id="ped_item_type" name="item_type" value="earning">
                <div class="modal-body">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <span data-i18n="sec_general_info">General Information</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_code">Item Code</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="text" class="form-control required" id="item_code" name="item_code" data-i18n="item_code_placeholder" placeholder="E003 / D002">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name_en">Item Name (EN)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="item_name_en" name="item_name_en" data-i18n="ped_item_name_en_placeholder" placeholder="e.g., Base Salary">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name_th">Item Name (TH)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="item_name_th" name="item_name_th" data-i18n="ped_item_name_th_placeholder" placeholder="e.g., เงินเดือนพื้นฐาน">
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <span data-i18n="sec_calculation_rules">Calculation & Legal Settings</span>
                    </h6>
                    <!-- 2026-08-30 (Phase 2, T013b, full redesign confirmed with user) -- `amount_source`
                         is a UI-ONLY field (not sent to the backend directly, has no DB column of its
                         own) that replaces the old bare `calculation_method` dropdown as the primary
                         "how does this item get its value" choice. It resolves what used to be a real,
                         confirmed source of confusion: `calculation_method`/`fixed_amount`/
                         `percent_rate` NEVER drive automatic calculation for ANY item (an earlier
                         audit this same day confirmed this -- the real per-employee amount always
                         comes from a separate assignment, employee_earning_deductions/
                         employee_recurring_earnings, entered independently) -- these 3 fields only
                         ever matter as a SUGGESTED starting value pre-filled when HR assigns this item
                         to an employee. For an item LINKED to an Origami event, even that suggestion
                         is meaningless (the value is 100% automatic, every cycle, no assignment ever
                         needed) -- collectPedTypeFormData()/populatePedTypeForm() (payroll-
                         configuration.js) translate between this selector and the real
                         calculation_method/source_event_code columns underneath. -->
                    <div class="row mb-2">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="amount_source">How is the amount determined?</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static required" id="amount_source" name="amount_source" data-option-keys="amount_source_event_linked,amount_source_fixed_amount,amount_source_percent_of_base_salary,amount_source_manual_entry" data-option-values="event_linked,fixed_amount,percent_of_base_salary,manual_entry"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3"></div>
                        <div class="col-sm-9">
                            <p class="text-muted small mb-0" id="amountSourceHint" data-i18n="amount_source_hint">Fixed Amount/Percent of Base Salary are only a suggested starting value shown when HR assigns this item to an employee -- they don't calculate anything automatically. An Origami-linked item needs no assignment at all; its amount is pulled automatically every payroll cycle.</p>
                        </div>
                    </div>
                    <input type="hidden" id="calculation_method" name="calculation_method">
                    <div class="row mb-3 d-none" id="source_event_code_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="source_event_code">Linked Attendance Event</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="source_event_code" name="source_event_code" data-api="/api/ped-type.source-event-options" data-type="earning"></select>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="fixed_amount_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="fixed_amount">Fixed Amount</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="fixed_amount" name="fixed_amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="percent_rate_wrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="percent_rate">Percent of Base Salary</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="percent_rate" name="percent_rate" data-i18n="percent_rate_placeholder" placeholder="e.g., 1.5">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <div id="earnings_fields_wrapper">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="tax_treatment">Tax Treatment</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="tax_treatment" name="tax_treatment" data-option-keys="taxable,non_taxable" data-option-values="taxable,non_taxable"></select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3">
                                <label class="form-label pt-1" data-i18n="statutory_calculations">Statutory Calculations</label>
                            </div>
                            <div class="col-sm-9">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="calc_sso" name="calc_sso" value="1">
                                    <label class="form-check-label" for="calc_sso" data-i18n="calc_sso_label">Include in SSO contribution base</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="calc_pf" name="calc_pf" value="1">
                                    <label class="form-check-label" for="calc_pf" data-i18n="calc_pf_label">Include in Provident Fund base</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="deductions_fields_wrapper" class="d-none">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="tax_deduction_impact">Tax Deduction Impact</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="tax_deduction_impact" name="tax_deduction_impact" data-option-keys="impact_before_tax,impact_after_tax" data-option-values="before_tax,after_tax"></select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="statutory_report_code">Statutory Report Mapping</label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="statutory_report_code" name="statutory_report_code" data-option-keys="statutory_report_th_slf" data-option-values="TH_SLF"></select>
                            </div>
                        </div>
                        <!-- 2026-09-03, Manual Entry / Employee Salary tab review Phase 1B (explicit
                             request: "เมื่อเลือก PED Type ในฟอร์มเงินกู้/ผ่อนชำระ ให้ auto-fill ดอกเบี้ย/
                             เงื่อนไข default จาก catalog") -- a SUGGESTED starting interest/fee/
                             interest/fee configuration for a new employee_earning_deductions
                             assignment of this item, same non-binding "suggestion only" role Fixed
                             Amount/Percent of Base Salary already play above for the plain Amount
                             field. Optional -- leaving "No default set" means the assignment form's
                             own existing defaults (interest_type='none', amount_mode='even_split')
                             apply exactly as they already do today. -->
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="default_interest_type">Default Interest/Fee</label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="default_interest_type" name="default_interest_type" allowClear="true" data-option-keys="interest_none,interest_fixed,interest_reducing_balance,fee_has" data-option-values="none,fixed,reducing_balance,fee"></select>
                            </div>
                        </div>
                        <div class="row mb-3 d-none" id="default_interest_rate_wrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="default_interest_rate">Default Interest Rate (% per installment)</label>
                            </div>
                            <div class="col-sm-3">
                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="default_interest_rate" name="default_interest_rate" data-i18n="percent_rate_placeholder" placeholder="e.g., 1.5">
                            </div>
                        </div>
                        <div class="row mb-3 d-none" id="default_fee_wrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="default_fee_percent">Default Fee</label>
                            </div>
                            <div class="col-sm-3">
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100" class="form-control" id="default_fee_percent" name="default_fee_percent" data-i18n="percent_rate_placeholder" placeholder="e.g., 2.0">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-sm-3">
                                <select class="form-select select2-static" id="default_fee_base" name="default_fee_base" data-option-keys="fee_base_option_principal,fee_base_option_base_salary" data-option-values="principal_amount,base_salary"></select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2026-08-30, explicit request: "ตั้งค่าได้ต่อว่า หักหรือไม่หักกับแผนกไหน ทีมไหน หรือเจาะจงรายคน...Design
     หน้าจอการ Assign ให้ใช้งานง่ายและสะดวกที่สุด" -- checkbox-per-scope picker, same convention as
     Payslip/Employment Certificate Template's own "Assign To" tab (see CLAUDE.md's "Assign To became
     its own tab with checkboxes" section) -- select-all per column + a client-side search filter,
     fed by AttendanceDeductionRuleModel::assignableOptions() (all active rows, no pagination). A
     checked box = that department/team/employee is EXEMPT from this one event's deduction --
     deliberately no include/exclude mode toggle (unlike Holiday's own assignment table) since
     nothing asked for a "blacklist everyone except" case here. -->
<div class="modal fade" id="attendanceDeductionAssignModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fa-solid fa-user-shield me-2 text-brand"></i><span data-i18n="attendance_deduction_assign_title">Exempt Departments / Teams / Employees</span> - <span id="attendanceDeductionAssignEvent" class="text-muted small"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small" data-i18n="attendance_deduction_assign_hint">Check any department, team, or individual employee that should NOT have this deduction applied. Leave everything unchecked to apply it to everyone as usual.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0 fw-semibold small" data-i18n="department">Department</label>
                            <div class="form-check form-check-sm mb-0"><input class="form-check-input ada-select-all" type="checkbox" data-scope="department" id="adaSelectAllDept"><label class="form-check-label small" for="adaSelectAllDept" data-i18n="select_all">Select All</label></div>
                        </div>
                        <input type="text" class="form-control form-control-sm mb-2 ada-scope-search" data-scope="department" data-i18n="search" placeholder="Search...">
                        <div class="ada-scope-list border rounded-2 p-2" id="adaScopeListDepartment" style="max-height:280px;overflow-y:auto;"></div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0 fw-semibold small" data-i18n="team">Team</label>
                            <div class="form-check form-check-sm mb-0"><input class="form-check-input ada-select-all" type="checkbox" data-scope="team" id="adaSelectAllTeam"><label class="form-check-label small" for="adaSelectAllTeam" data-i18n="select_all">Select All</label></div>
                        </div>
                        <input type="text" class="form-control form-control-sm mb-2 ada-scope-search" data-scope="team" data-i18n="search" placeholder="Search...">
                        <div class="ada-scope-list border rounded-2 p-2" id="adaScopeListTeam" style="max-height:280px;overflow-y:auto;"></div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0 fw-semibold small" data-i18n="employee">Employee</label>
                            <div class="form-check form-check-sm mb-0"><input class="form-check-input ada-select-all" type="checkbox" data-scope="employee" id="adaSelectAllEmployee"><label class="form-check-label small" for="adaSelectAllEmployee" data-i18n="select_all">Select All</label></div>
                        </div>
                        <input type="text" class="form-control form-control-sm mb-2 ada-scope-search" data-scope="employee" data-i18n="search" placeholder="Search...">
                        <div class="ada-scope-list border rounded-2 p-2" id="adaScopeListEmployee" style="max-height:280px;overflow-y:auto;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveAttendanceDeductionAssign"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="attendanceDeductionRuleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fa-solid fa-clock-rotate-left"></i> <span id="attendanceDeductionRuleModalEvent"></span> <span class="text-muted small ms-1" data-i18n="attendance_deduction_rule_title">Attendance Deduction Rule</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="attendanceRuleId">
                <input type="hidden" id="attendanceRuleEventCode">
                <!-- 2026-08-30, multi-scope rollout: "ในกรณีที่มีการคำนวณประเภทเดียวกันแต่หลายทีม ให้เพิ่มปุ่ม
                     Clone ขึ้นมาและใส่รายละเอียดเพิ่มเข้าไปในส่วนของรายการด้วยมาใช้กับอะไร" -- scope is fixed
                     at creation time (Add Variant / Clone), never reassignable while editing an existing
                     row, to keep the duplicate-scope validation UX simple: a read-only badge shows an
                     EXISTING row's scope, while the editable team/department picker only appears when
                     creating a brand-new variant (see payroll-configuration.js's openAttendanceDeductionRuleModal()). -->
                <div class="mb-3" id="attendanceRuleScopeBadgeWrapper">
                    <label class="form-label small text-muted mb-1" data-i18n="attendance_deduction_scope">Applies To</label>
                    <div><span class="badge bg-secondary-subtle text-secondary" id="attendanceRuleScopeBadge"></span></div>
                </div>
                <div class="row g-2 mb-3 d-none" id="attendanceRuleScopePickerWrapper">
                    <div class="col-5">
                        <label class="form-label small" data-i18n="attendance_deduction_scope">Applies To</label>
                        <select class="form-select select2-static" id="attendanceRuleScopeType" data-option-keys="attendance_deduction_scope_team,attendance_deduction_scope_department" data-option-values="team,department"></select>
                    </div>
                    <div class="col-7">
                        <label class="form-label small" id="attendanceRuleScopeTargetLabel"></label>
                        <!-- 2026-08-30, real bug found and fixed (explicit report: "เลือกทีม แต่ select ของ
                             Department ขึ้นมาด้วย") -- select2 renders its own widget as a SEPARATE
                             sibling DOM node, so toggling .d-none on the raw <select> itself (the old
                             markup here) never actually hid the rendered dropdown. Each <select> now
                             has its OWN wrapping div that JS toggles .d-none on instead -- see
                             style.css's own comment on this same fix for the full explanation. -->
                        <div class="adr-scope-target-wrap" id="attendanceRuleScopeTeamWrap">
                            <select class="form-select select2-remote" id="attendanceRuleScopeTeamId" data-api="/api/team.get" data-type="team"></select>
                        </div>
                        <div class="adr-scope-target-wrap d-none" id="attendanceRuleScopeDepartmentWrap">
                            <select class="form-select select2-remote" id="attendanceRuleScopeDepartmentId" data-api="/api/department.get" data-type="department"></select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" data-i18n="attendance_deduction_label">Note (used for what?)</label>
                    <input type="text" class="form-control" id="attendanceRuleLabel" maxlength="150" placeholder="e.g., Warehouse team - stricter late policy">
                </div>
                <!-- 2026-09-01, explicit request: "ให้เลือกก่อนว่าหัก หรือไม่หัก เป็น radio จากนั้นค่อยแสดงหรือ
                     ซ่อน Form ที่เหลือ" -- 'no_deduction' already existed as just another option buried
                     inside the Deduction Method dropdown; this promotes it to an up-front radio choice
                     so the rest of the form (method/rate/amount/preview) only ever shows when it's
                     actually relevant. See payroll-configuration.js's attendanceRuleDeductChoice handler
                     -- it still just drives #attendanceDeductionMethod's own value under the hood, so
                     saveAttendanceDeductionRule()/AttendanceDeductionRuleModel needed zero changes. -->
                <div class="mb-3">
                    <label class="form-label" data-i18n="attendance_deduction_apply">Apply Deduction?</label>
                    <div>
                        <div class="form-check form-check-inline mt-1">
                            <input class="form-check-input" type="radio" name="attendanceRuleDeductChoice" id="attendanceRuleDeductYes" value="deduct" checked>
                            <label class="form-check-label" for="attendanceRuleDeductYes" data-i18n="attendance_deduction_apply_yes">Deduct</label>
                        </div>
                        <div class="form-check form-check-inline mt-1">
                            <input class="form-check-input" type="radio" name="attendanceRuleDeductChoice" id="attendanceRuleDeductNo" value="no_deduction">
                            <label class="form-check-label" for="attendanceRuleDeductNo" data-i18n="attendance_deduction_apply_no">No Deduction</label>
                        </div>
                    </div>
                </div>
                <div id="attendanceRuleDeductFieldsWrapper">
                <div class="mb-3">
                    <label class="form-label" data-i18n="attendance_deduction_method">Deduction Method</label>
                    <select class="form-select select2-remote" id="attendanceDeductionMethod" data-api="/api/attendance-deduction-rule.method-options" data-type="attendance_deduction_method"></select>
                </div>
                <div id="attendanceRateUnitWrapper" class="mb-3 d-none">
                    <label class="form-label" data-i18n="attendance_deduction_rate_unit">Rate Unit</label>
                    <select class="form-select select2-static" id="attendanceRateUnit" data-option-keys="attendance_deduction_rate_unit_minute,attendance_deduction_rate_unit_hour,attendance_deduction_rate_unit_day" data-option-values="minute,hour,day"></select>
                </div>
                <div id="attendanceFlatSection" class="mb-3 d-none">
                    <label class="form-label" id="attendanceFlatLabel">Deduction Amount per Unit</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="attendanceRatePerUnit" placeholder="e.g., 1.00">
                </div>
                <div id="attendancePercentSection" class="mb-3 d-none">
                    <label class="form-label" data-i18n="attendance_deduction_multiplier">Multiplier (x of the salary-derived rate)</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="attendanceMultiplierRate" value="1.00" data-i18n="multiplier_rate_placeholder" placeholder="e.g., 1.50">
                </div>
                <div id="attendanceBracketSection" class="mb-3 d-none">
                    <label class="form-label d-block" data-i18n="attendance_deduction_brackets">Brackets</label>
                    <div class="table-responsive">
                        <table class="table table-sm table-border align-middle mb-2">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th id="attendanceBracketMinLabel">From</th>
                                    <th id="attendanceBracketMaxLabel">To</th>
                                    <th data-i18n="attendance_deduction_bracket_amount">Deduction Amount</th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody id="attendanceBracketRows"></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addAttendanceBracketRow()"><i class="fa-solid fa-plus me-1"></i><span data-i18n="attendance_deduction_bracket_add_row">Row</span></button>
                </div>
                <!-- 2026-08-30, explicit request: "อยากให้เพิ่มปุ่มแสดงตัวอย่างการคำนวณจากการตั้งค่าที่เลือก...
                     ก็อยากให้มี Area แสดงตัวอย่างการคำนวณครับ" -- computes against whatever is CURRENTLY
                     typed into the form above (not yet saved), via AttendanceDeductionRuleModel::
                     previewCalculation() -- the exact same formula real payroll uses (see that
                     method's own docblock), so this can never show a different number than what
                     Save would actually produce. Sample inputs are editable so an admin can try their
                     own numbers, not just the default scenario. -->
                <hr class="my-3 text-muted opacity-25">
                <div class="calc-preview-box" id="attendanceCalcPreviewBox">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-secondary"><i class="fa-solid fa-calculator me-2 text-brand"></i><span data-i18n="calc_preview_title">Calculation Preview</span></h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAttendanceCalcPreview"><i class="fa-solid fa-play me-1"></i><span data-i18n="calc_preview_button">Preview</span></button>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small mb-1" data-i18n="calc_preview_sample_base_salary">Sample Base Salary</label>
                            <input type="number" min="1" step="0.01" class="form-control form-control-sm" id="attendanceCalcPreviewBaseSalary" value="30000" data-i18n="base_salary_amount_placeholder" placeholder="e.g., 30000">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-1" id="attendanceCalcPreviewMinutesLabel" data-i18n="calc_preview_sample_minutes">Sample Minutes Late/Absent</label>
                            <input type="number" min="0" step="1" class="form-control form-control-sm" id="attendanceCalcPreviewMinutes" value="30" data-i18n="minutes_placeholder" placeholder="e.g., 30">
                        </div>
                    </div>
                    <div class="calc-preview-result d-none" id="attendanceCalcPreviewResult"></div>
                </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary px-4" onclick="saveAttendanceDeductionRule()"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Payslip & Documents / Requests (app/views/payslip/requests.php) ===== -->
<div class="modal fade" id="payslipRequestModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="payslipRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="payslipRequestModalLabel">
                    <i class="fa-solid fa-inbox me-2"></i><span data-i18n="request_payslip">Request Payslip</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="payslipRequestForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><span data-i18n="pay_period">Pay Period</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="pr_run" data-api="/api/payslip-request.run-options"></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-native required" id="pr_employee" disabled></select>
                        <div class="text-secondary small mt-1" data-i18n="select_run_first">Select a pay period first.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" data-i18n="submit_request">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="ecrRequestModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="ecrRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="ecrRequestModalLabel">
                    <i class="fa-solid fa-file-shield me-2"></i><span data-i18n="request_employment_certificate">Request Employment Certificate</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="ecrRequestForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="ecr_employee" data-api="/api/employee.report_to.get"></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><span data-i18n="language">Language</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static required" id="ecr_language" data-option-keys="template_language_th,template_language_en" data-option-values="th,en"></select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" data-i18n="submit_request">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="requestDetailModal" tabindex="-1" aria-labelledby="requestDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="requestDetailModalLabel">
                    <i class="fa-solid fa-list-check me-2"></i><span data-i18n="approval_request_detail">Request Detail</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="requestDetailSummary" class="mb-3"></div>
                <h6 class="fw-bold" data-i18n="approval_history">History</h6>
                <div id="requestDetailTimeline"></div>
                <div id="requestActionArea" class="mt-3 d-none">
                    <hr>
                    <div class="mb-2">
                        <label class="form-label small" data-i18n="note_optional">Note (optional)</label>
                        <textarea id="requestActionNote" class="form-control" rows="2" maxlength="500" data-i18n="approve_note_placeholder" placeholder="Any comment for this approval..."></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success btn-sm" id="btnApproveRequest"><i class="fa-solid fa-check me-1"></i><span data-i18n="approve">Approve</span></button>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnRejectRequest"><i class="fa-solid fa-xmark me-1"></i><span data-i18n="reject">Reject</span></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCancelRequest"><i class="fa-solid fa-ban me-1"></i><span data-i18n="cancel_request">Cancel Request</span></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== Tax & Statutory (app/views/setup/tax-statutory.php) ===== -->
<div class="modal fade" id="statutoryItemModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="statutoryItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="statutoryItemModalLabel">
                    <i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="statutory_item">Statutory Item</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="statutoryItemForm" novalidate>
                <input type="hidden" name="id" id="item_id">
                <div class="modal-body">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <span data-i18n="modal_sec_item_info">Item Information</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_code">Code</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="si_item_code" name="code" data-i18n="statutory_item_code_placeholder" placeholder="e.g. TH_SSO">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_name_th">Name (Thai)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="si_item_name_th" name="name_th" data-i18n="statutory_item_name_th_placeholder" placeholder="e.g., ภาษีเงินได้บุคคลธรรมดา">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_name_en">Name (English)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control required" id="si_item_name_en" name="name_en" data-i18n="statutory_item_name_en_placeholder" placeholder="e.g., Personal Income Tax">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_category">Category</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static required" id="item_category" name="category" data-option-keys="category_tax,category_social_insurance,category_provident_fund,category_other" data-option-values="tax,social_insurance,provident_fund,other"></select>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <span data-i18n="modal_sec_calc_config">Calculation Configuration</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_calc_method">Calculation Method</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static required" id="item_calc_method" name="calc_method" data-option-keys="calc_method_flat_rate,calc_method_progressive_bracket,calc_method_fixed_amount,calc_method_formula" data-option-values="flat_rate,progressive_bracket,fixed_amount,formula"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_calc_base">Calculation Base</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static required" id="item_calc_base" name="calc_base" data-option-keys="calc_base_basic_salary,calc_base_gross_salary,calc_base_taxable_income,calc_base_net_income,calc_base_sso_eligible_earnings,calc_base_pf_eligible_earnings,calc_base_custom" data-option-values="basic_salary,gross_salary,taxable_income,net_income,sso_eligible_earnings,pf_eligible_earnings,custom"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_rounding_mode">Rounding</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-static" id="item_rounding_mode" name="rounding_mode" data-option-keys="rounding_mode_round,rounding_mode_up,rounding_mode_down,rounding_mode_none" data-option-values="round,up,down,none"></select>
                            <div class="form-text" data-i18n="modal_rounding_mode_hint">How to handle decimals left over after calculation.</div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_decimal_places">Decimal Places</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" min="0" max="4" class="form-control" id="item_decimal_places" name="decimal_places" value="2" data-i18n="decimal_places_placeholder" placeholder="0-4">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_sort_order">Sort Order</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" min="0" class="form-control" id="item_sort_order" name="sort_order" value="0" data-i18n="count_placeholder" placeholder="0">
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <input type="checkbox" class="me-2" id="item_is_employee_applicable" name="is_employee_applicable" checked><span data-i18n="modal_employee_applicable">Applies to Employee</span>
                        </div>
                        <div class="col-sm-6">
                            <input type="checkbox" class="me-2" id="item_is_employer_applicable" name="is_employer_applicable" checked><span data-i18n="modal_employer_applicable">Applies to Employer</span>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <input type="checkbox" class="me-2" id="item_default_is_active" name="default_is_active" checked><span data-i18n="modal_default_active">Active by Default for New Companies</span>
                        </div>
                        <div class="col-sm-6">
                            <input type="checkbox" class="me-2" id="item_is_company_rate_editable" name="is_company_rate_editable"><span data-i18n="modal_company_rate_editable">Company May Adjust Rate</span>
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

<div class="modal fade" id="rateHistoryModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="rateHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="rateHistoryModalLabel">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="rate_history">Rate History</span>
                    <span class="text-muted small ms-1" id="rateHistoryItemName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small" data-i18n="rate_history_description">Statutory rates change over time. Add a new version instead of editing the current one to preserve history.</p>
                <div class="d-flex justify-content-end mb-2">
                    <button type="button" class="btn btn-primary btn-sm" id="btnAddRateVersion">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="rate_version">Rate Version</span>
                    </button>
                </div>
                <table class="table table-hover table-border align-middle w-100" id="tb_rate_history">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" data-i18n="table_effective_date">Effective Date</th>
                            <th scope="col" data-i18n="table_end_date">End Date</th>
                            <th scope="col" data-i18n="table_rate_summary">Rate</th>
                            <th scope="col" data-i18n="table_last_updated">Last Updated</th>
                            <th scope="col" style="text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="rateVersionModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="rateVersionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="rateVersionModalLabel">
                    <i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="rate_version">Rate Version</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="rateVersionForm" novalidate>
                <input type="hidden" name="id" id="rate_id">
                <input type="hidden" name="statutory_item_id" id="rate_statutory_item_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4">
                            <div class="input-group">
                                <input type="text" class="form-control required datepicker" id="rate_effective_date" name="effective_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_end_date">End Date</span></label>
                        </div>
                        <div class="col-sm-4">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="rate_end_date" name="end_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                        <div class="col-sm-5 pt-2">
                            <span class="text-muted small" data-i18n="end_date_optional_hint">Leave blank if this rate is still in effect (open-ended).</span>
                        </div>
                    </div>
                    <hr class="my-3 text-muted opacity-25">

                    <div id="rate_flat_fields">
                        <div class="row mb-3" id="rate_employee_rate_wrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_employee_rate">Employee Rate (%)</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <input type="number" step="0.0001" min="0" class="form-control" id="rate_employee_rate" name="employee_rate" data-i18n="statutory_rate_placeholder" placeholder="e.g., 5.00">
                            </div>
                        </div>
                        <div class="row mb-3" id="rate_employer_rate_wrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_employer_rate">Employer Rate (%)</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <input type="number" step="0.0001" min="0" class="form-control" id="rate_employer_rate" name="employer_rate" data-i18n="statutory_rate_placeholder" placeholder="e.g., 5.00">
                            </div>
                        </div>
                    </div>

                    <div id="rate_amount_fields" class="d-none">
                        <div class="row mb-3" id="rate_employee_amount_wrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_employee_amount">Employee Amount</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <input type="number" step="0.01" min="0" class="form-control" id="rate_employee_amount" name="employee_amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                            </div>
                        </div>
                        <div class="row mb-3" id="rate_employer_amount_wrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_employer_amount">Employer Amount</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <input type="number" step="0.01" min="0" class="form-control" id="rate_employer_amount" name="employer_amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                            </div>
                        </div>
                    </div>

                    <div id="rate_bracket_fields" class="d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0"><span data-i18n="tax_brackets">Tax Brackets</span></h6>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddBracketRow"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_bracket">Bracket</span></button>
                        </div>
                        <div class="table-responsive">
                            <table class="table pl-table mb-0">
                                <thead>
                                    <tr>
                                        <th data-i18n="bracket_from">From</th>
                                        <th data-i18n="bracket_to">To</th>
                                        <th data-i18n="bracket_rate">Rate (%)</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="bracketBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div id="rate_formula_fields" class="d-none">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_formula_config">Formula Config (JSON)</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <textarea class="form-control" id="rate_formula_config" name="formula_config" rows="4" data-i18n="formula_config_json_example" placeholder='{"base_rate": 1.45, "additional_rate": 0.9, "additional_threshold": 200000}'></textarea>
                                <p class="text-muted small mt-1 mb-0" data-i18n="formula_config_hint">Reserved for future formula-based calculations, e.g. threshold-based extra rates. Enter a valid JSON object.</p>
                            </div>
                        </div>
                    </div>

                    <hr class="my-3 text-muted opacity-25">
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_min_base">Minimum Base Amount</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="rate_min_base_amount" name="min_base_amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                        </div>
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_max_base">Maximum Base Amount</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="rate_max_base_amount" name="max_base_amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_max_employee_contribution">Max Employee Contribution</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="rate_max_employee_contribution" name="max_employee_contribution" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                        </div>
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_max_employer_contribution">Max Employer Contribution</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="0.01" min="0" class="form-control" id="rate_max_employer_contribution" name="max_employer_contribution" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_remark">Remark</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="rate_remark" name="remark" data-i18n="remark_placeholder" placeholder="Optional notes">
                        </div>
                    </div>

                    <hr class="my-3 text-muted opacity-25">
                    <div class="calc-preview-box" id="rateVersionCalcPreviewBox">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0 text-secondary"><i class="fa-solid fa-calculator me-2 text-brand"></i><span data-i18n="calc_preview_title">Calculation Preview</span></h6>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRateVersionCalcPreview"><i class="fa-solid fa-play me-1"></i><span data-i18n="calc_preview_button">Preview</span></button>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small mb-1" data-i18n="calc_preview_sample_base_amount">Sample Base Amount</label>
                                <input type="number" min="0" step="0.01" class="form-control form-control-sm" id="rateVersionCalcPreviewBase" value="30000" data-i18n="base_salary_amount_placeholder" placeholder="e.g., 30000">
                            </div>
                        </div>
                        <div class="calc-preview-result d-none" id="rateVersionCalcPreviewResult"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><span data-i18n="save">Save</span></button>
                    <button type="button" class="btn btn-outline-secondary" id="btnBackToRateHistory" data-i18n="back">Back</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="companySettingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="companySettingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="companySettingModalLabel">
                    <i class="fa-solid fa-building me-1"></i><span data-i18n="tab_company_setting">Company Settings</span>
                    <span class="text-muted small ms-1" id="companySettingItemName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="companySettingForm" novalidate>
                <input type="hidden" name="statutory_item_id" id="cs_statutory_item_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-12">
                            <input type="checkbox" class="me-2" id="cs_is_active" name="is_active" checked>
                            <span data-i18n="modal_company_enable_item">Enable this statutory item for our company</span>
                        </div>
                    </div>
                    <div id="cs_override_wrapper">
                        <hr class="my-3 text-muted opacity-25">
                        <p class="text-muted small" id="cs_master_default_hint"></p>
                        <div id="cs_rate_fields" class="d-none">
                            <div class="row mb-3">
                                <div class="col-sm-4 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employee_rate">Employee Rate (%)</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.0001" min="0" class="form-control" id="cs_employee_rate_override" name="employee_rate_override" data-i18n="default" placeholder="Default">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-sm-4 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employer_rate">Employer Rate (%)</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.0001" min="0" class="form-control" id="cs_employer_rate_override" name="employer_rate_override" data-i18n="default" placeholder="Default">
                                </div>
                            </div>
                        </div>
                        <div id="cs_amount_fields" class="d-none">
                            <div class="row mb-3">
                                <div class="col-sm-4 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employee_amount">Employee Amount</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.01" min="0" class="form-control" id="cs_employee_amount_override" name="employee_amount_override" data-i18n="default" placeholder="Default">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-sm-4 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employer_amount">Employer Amount</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.01" min="0" class="form-control" id="cs_employer_amount_override" name="employer_amount_override" data-i18n="default" placeholder="Default">
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-4 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_remark">Remark</span></label>
                            </div>
                            <div class="col-sm-8">
                                <input type="text" class="form-control" id="cs_remark" name="remark" data-i18n="remark_placeholder" placeholder="Optional notes">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-outline-danger" id="btnResetCompanySetting"><i class="fa-solid fa-rotate-left me-1"></i><span data-i18n="reset_to_default">Reset to Default</span></button>
                    <div>
                        <button type="submit" class="btn btn-primary"><span data-i18n="save">Save</span></button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== Manual Time Entry (app/views/manual-entry/index.php) ===== -->
<div class="modal fade" id="attendanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="attendanceModalTitle"><i class="fa-solid fa-clock"></i> <span data-i18n="attendance">Attendance</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="attendanceId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="attendanceEmployee" data-api="/api/employee.report_to.get" data-type=""></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="work_date">Work Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="attendanceWorkDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="shift">Shift</label>
                        <select class="form-select select2-remote" id="attendanceShift" data-api="/api/shift.options" data-type="shift"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="status">Status</label>
                        <select class="form-select select2-static" id="attendanceStatus" data-option-keys="status_present,status_absent,status_leave,holiday" data-option-values="present,absent,leave,holiday"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="clock_in">Clock In</label>
                        <input type="time" class="form-control" id="attendanceClockIn">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="clock_out">Clock Out</label>
                        <input type="time" class="form-control" id="attendanceClockOut">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="late_minutes">Late (min)</label>
                        <input type="number" min="0" class="form-control" id="attendanceLateMinutes" value="0" data-i18n="minutes_placeholder" placeholder="e.g., 30">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="early_leave_minutes">Early Leave (min)</label>
                        <input type="number" min="0" class="form-control" id="attendanceEarlyMinutes" value="0" data-i18n="minutes_placeholder" placeholder="e.g., 30">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveAttendance(this)"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="leaveModalTitle"><i class="fa-regular fa-calendar-check"></i> <span data-i18n="leave">Leave</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="leaveId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="leaveEmployee" data-api="/api/employee.report_to.get" data-type=""></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="leave_type">Leave Type</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="leaveType" data-api="/api/leave-type.options" data-type="leave_type"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="start_date">Start Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="leaveStartDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="end_date">End Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="leaveEndDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="total_days">Total Days</span> <span class="text-danger">*</span></label>
                        <input type="number" min="0.5" step="0.5" class="form-control required" id="leaveTotalDays" data-i18n="days_placeholder" placeholder="e.g., 1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="status">Status</label>
                        <select class="form-select select2-static" id="leaveStatus" data-option-keys="status_pending,status_approved,status_rejected,cancelled" data-option-values="pending,approved,rejected,cancelled"></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" data-i18n="reason">Reason</label>
                        <textarea class="form-control" id="leaveReason" rows="2" data-i18n="leave_reason_placeholder" placeholder="e.g., Annual leave for family trip"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveLeave(this)"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="overtimeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="overtimeModalTitle"><i class="fa-solid fa-stopwatch"></i> <span data-i18n="overtime">Overtime</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="overtimeId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="employee">Employee</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="overtimeEmployee" data-api="/api/employee.report_to.get" data-type=""></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="ot_rate">OT Rate</span> <span class="text-danger">*</span></label>
                        <select class="form-select select2-remote required" id="overtimeRate" data-api="/api/ot-rate.options" data-type="ot_rate"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="ot_date">OT Date</span> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control datepicker required" id="overtimeDate" autocomplete="off">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><span data-i18n="hours">Hours</span> <span class="text-danger">*</span></label>
                        <input type="number" min="0.5" step="0.5" class="form-control required" id="overtimeHours" data-i18n="hours_placeholder" placeholder="e.g., 2">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="amount">Amount</label>
                        <input type="number" min="0" step="0.01" class="form-control" id="overtimeAmount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" data-i18n="status">Status</label>
                        <select class="form-select select2-static" id="overtimeStatus" data-option-keys="status_pending,status_approved,status_rejected" data-option-values="pending,approved,rejected"></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary" onclick="saveOvertime(this)"><i class="fa-solid fa-check"></i> <span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="manualEntryDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center pt-4">
                <div class="confirm-icon"><i class="fa-solid fa-trash"></i></div>
                <h6 class="fw-bold mb-1" data-i18n="confirm_delete_title">Confirm Delete</h6>
                <p class="text-muted small mb-0"><span data-i18n="delete_confirm_question">Delete</span> "<span id="manualEntryDeleteTargetName"></span>"?<br><span data-i18n="delete_irreversible_note">This action cannot be undone.</span></p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button class="btn btn-light px-3" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-danger px-3" onclick="confirmManualEntryDelete()"><i class="fa-solid fa-trash me-1"></i><span data-i18n="delete">Delete</span></button>
            </div>
        </div>
    </div>
</div>


<!-- 2026-09-02, explicit request: "การเพิ่มแบบ Manual ตอนนี้เพิ่มได้แบบ 1 ต่อ 1 อยากให้เพิ่ม ให้เพิ่มได้ทีละ
     หลายรายการ เป็นเหมือนหน้า Excel ในการจัดการ และเพิ่มให้ Import ได้ในหน้า Form นั้นเลย...การจัดการเหมือน
     Excel แล้วกด Save ทีเดียว เป็น modal fullscreen ก็ได้ครับ" -- ONE generic fullscreen grid, reused for
     all 3 entity types (Attendance/Leave/Overtime) via public/js/manual-entry/bulk-entry.js's own
     per-entity column config, rather than 3 near-identical modals. Two ways rows get INTO the grid,
     both editable/deletable before the ONE final Save:
       1. "+ Add Row" -- a blank row with the SAME field types (select2 Employee/Shift/Leave Type/OT
          Rate, native date/time inputs, Status) the existing single-record attendanceModal/
          leaveModal/overtimeModal already use -- saved via the new .../bulk-save endpoint
          (AttendanceRecordModel::bulkSave() etc.), data_source='manual', same as today's single Add.
       2. "Import File" -- reuses the EXISTING api/manual-import.preview endpoint as-is (same
          template/header-mapping/validation this page's own Import tab already has) to populate the
          grid with parsed rows instead of showing them in a separate read-only preview table --
          THESE rows keep the import pipeline's own employee_no/shift_code/leave_type_code/
          ot_rate_name text-code shape (plain text inputs, not select2 IDs) since that's what
          api/manual-import.commit expects, and are saved via THAT same existing commit endpoint on
          Save All (data_source='import', a real sync_batches audit row) -- kept genuinely distinct
          from manually-typed rows rather than collapsing both into one path, since which of the two
          actually happened is real, meaningful audit information this app's own data_source column
          exists to preserve (see AttendanceRecordModel's own class docblock).
     Manual rows and imported rows can coexist in the SAME grid session -- Save All splits them by
     origin and calls each row's own correct backend path, then reports one combined result. -->
<div class="modal fade" id="bulkEntryModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="bulkEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="bulkEntryModalLabel">
                    <i class="fa-solid fa-table-cells me-1" id="bulkEntryModalIcon"></i><span id="bulkEntryModalTitle">-</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3" id="bulkEntrySummaryCards"></div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnBulkEntryAddRow">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="bulk_entry_add_row">Add Row</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnBulkEntryImport">
                        <i class="fa-solid fa-file-import me-1"></i><span data-i18n="bulk_entry_import_file">Import File</span>
                    </button>
                </div>
                <div class="table-responsive bulk-entry-grid-wrap">
                    <table class="table table-bordered align-middle mb-0 bulk-entry-grid" id="tb_bulk_entry">
                        <thead class="table-light" id="bulkEntryThead"></thead>
                        <tbody id="bulkEntryTbody"></tbody>
                    </table>
                </div>
                <div class="text-center text-secondary py-5 d-none" id="bulkEntryEmptyState">
                    <i class="fa-solid fa-table-cells fa-2x mb-3 opacity-50"></i>
                    <p class="mb-0" data-i18n="bulk_entry_empty_hint">No rows yet -- click "Add Row" or "Import File" to get started.</p>
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto" data-i18n="bulk_entry_footer_hint">Add or import as many rows as you like, then Save once.</span>
                <button class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button class="btn btn-primary px-4" id="btnBulkEntrySaveAll"><i class="fa-solid fa-check me-1"></i><span data-i18n="bulk_entry_save_all">Save All</span></button>
            </div>
        </div>
    </div>
</div>

<!-- 2026-09-02, explicit follow-up request -- the "Import File" flow from #bulkEntryModal above gets
     its own proper wizard modal instead of a silent file-picker click: instructions + Download
     Template + attach form (step 1, "Attach"), THEN a summary + row-by-row preview BEFORE anything
     is persisted (step 2, "Review", built by bulk-entry.js's own openBulkImportModal()/
     bulkImportRunPreview() from the EXISTING api/manual-import.preview response -- unchanged backend,
     new presentation). This is also the modal that replaced the old standalone Import tab (removed
     this same round, see manual-entry/index.php's own comment on that) -- reachable both from inside
     #bulkEntryModal's own "Import File" button (stacked on top of it) AND directly from a new
     top-level "Import" button on each of the 3 tabs (grid never opened at all in that case).
     Confirming (step 2's own footer) offers a REAL choice the old tab never had: "Save Directly"
     (commits immediately via the EXISTING api/manual-import.commit, same as the old tab's own
     Confirm Import always did) or "Load into Grid to Edit" (drops the parsed rows into
     #bulkEntryModal's own editable grid instead, opening it fresh if it wasn't already open). -->
<div class="modal fade" id="bulkImportModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="bulkImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="bulkImportModalLabel">
                    <i class="fa-solid fa-file-import me-1"></i><span id="bulkImportModalTitle">-</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="bulkImportAttachStep">
                    <div class="alert alert-info small d-flex gap-2 align-items-start mb-4" role="alert">
                        <i class="fa-solid fa-circle-info mt-1"></i>
                        <div data-i18n="bulk_import_instructions">Download the template below, fill in one row per record using the SAME column order, then attach the completed file here. You'll see a full row-by-row summary before anything is saved.</div>
                    </div>
                    <div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
                        <button type="button" class="btn btn-outline-primary" id="btnBulkImportDownloadTemplate">
                            <i class="fa-solid fa-download me-1"></i><span data-i18n="download_template">Download Template</span>
                        </button>
                        <a href="javascript:void(0);" class="ms-auto small" id="btnBulkImportViewHistory">
                            <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="bulk_import_view_history">View Import History</span>
                        </a>
                    </div>
                    <label class="form-label fw-semibold mb-2" data-i18n="bulk_import_attach_file">Attach File</label>
                    <!-- 2026-09-02, explicit request: "ปรับหน้าตา Form ให้ดูสวยขึ้นและใช้งานง่ายขึ้น" -- the
                         real `<input type="file">` stays (browsers won't let a custom element trigger
                         a file picker with a real native dialog on its own), just visually hidden and
                         wrapped by a clickable styled dropzone card instead of shown as a bare native
                         control -- clicking anywhere in the card opens the same picker
                         (bulk-entry.js's own click-passthrough + filename-echo handler). -->
                    <label class="bulk-import-dropzone w-100" for="bulkImportFileInput" id="bulkImportDropzone">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span data-i18n="bulk_import_dropzone_hint">Click to choose a file, or drag it here</span>
                        <span class="small text-muted" id="bulkImportDropzoneFilename"></span>
                    </label>
                    <input type="file" class="d-none" id="bulkImportFileInput" accept=".csv,.xlsx,.xls">
                </div>
                <div id="bulkImportReviewStep" class="d-none">
                    <div class="row g-2 mb-3" id="bulkImportSummaryCards"></div>
                    <div class="alert alert-warning d-none" id="bulkImportUnmappedAlert"></div>
                    <div class="table-responsive" style="max-height:340px;overflow-y:auto;">
                        <table class="table table-sm" id="tb_bulk_import_preview">
                            <thead class="table-light">
                                <tr>
                                    <th data-i18n="row">Row</th>
                                    <th data-i18n="status">Status</th>
                                    <th data-i18n="action">Action</th>
                                    <th data-i18n="message">Message</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="bulkImportAttachFooter">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary px-4" id="btnBulkImportRunPreview"><i class="fa-solid fa-file-import me-1"></i><span data-i18n="bulk_import_run">Import</span></button>
            </div>
            <div class="modal-footer d-none" id="bulkImportReviewFooter">
                <button type="button" class="btn btn-light me-auto" id="btnBulkImportBack"><i class="fa-solid fa-arrow-left me-1"></i><span data-i18n="back">Back</span></button>
                <button type="button" class="btn btn-outline-primary" id="btnBulkImportLoadToGrid"><i class="fa-solid fa-table-cells me-1"></i><span data-i18n="bulk_import_load_to_grid">Load into Grid to Edit</span></button>
                <button type="button" class="btn btn-success px-4" id="btnBulkImportSaveDirect"><i class="fa-solid fa-check me-1"></i><span data-i18n="bulk_import_save_direct">Save Directly</span></button>
            </div>
        </div>
    </div>
</div>

<!-- 2026-08-30 (Phase 5, T034) -- drill-down for one import batch's own records (manual-entry/index.php's History tab). Edit/Delete row buttons reuse openAttendanceModal()/openLeaveModal()/openOvertimeModal()/askDeleteMe() already defined for the other 3 tabs -- no new edit surface, see ManualEntryController's own docblock. -->
<div class="modal fade" id="importBatchDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fa-solid fa-file-import"></i> <span data-i18n="import_batches">Import History</span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm" id="tb_import_batch_detail" style="width:100%">
                        <thead></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-light px-3" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Payroll Approval (app/views/payroll/approval.php) ===== -->
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

<!-- ===== Employee Detail (app/views/employee/detail.php) ===== -->
<div class="modal fade" id="eedModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="eedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="eedModalLabel">
                    <span data-i18n="add_earning_deduction">Add Income / Deduction</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="eedForm" novalidate>
                <input type="hidden" id="eed_id" name="id">
                <div class="modal-body">
                    <h6 class="text-secondary fw-bold mb-3 mt-2">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <span data-i18n="sec_general_info">General Information</span>
                    </h6>
                    <div class="mb-3">
                        <!-- 2026-09-03, Manual Entry / Platform UX review Phase 6: redesigned from a
                             plain btn-group -- see style.css's own docblock on .mode-select-group for
                             why (short version: "Custom Item" vs "Other" used to look identical with
                             no explanation, the per-button description below is the actual fix). -->
                        <div class="mode-select-group" role="group" id="eedModeToggle">
                            <button type="button" class="mode-select-btn active" data-mode="catalog">
                                <i class="fa-solid fa-list"></i>
                                <span class="mode-select-btn-title" data-i18n="manual_line_mode_catalog">From List</span>
                                <span class="mode-select-btn-desc" data-i18n="mode_desc_catalog">Pick from your saved item types</span>
                            </button>
                            <button type="button" class="mode-select-btn" data-mode="custom">
                                <i class="fa-solid fa-pen"></i>
                                <span class="mode-select-btn-title" data-i18n="manual_line_mode_custom">Custom Item</span>
                                <span class="mode-select-btn-desc" data-i18n="mode_desc_custom">One-time item with its own name</span>
                            </button>
                            <!-- 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 --
                                 reuses #eedCustomFields' own free-text input verbatim (see
                                 setEedMode()'s own docblock in detail.js); the only difference from
                                 "Custom Item" is is_other=true sent on submit, which maps this specific
                                 entry into the shared "Other Income"/"Other Deduction" aggregation
                                 bucket instead of its own one-off report column -- see this button's
                                 own description below, which is the whole point of this redesign. -->
                            <button type="button" class="mode-select-btn" data-mode="other">
                                <i class="fa-solid fa-circle-question"></i>
                                <span class="mode-select-btn-title" data-i18n="manual_line_mode_other">Other</span>
                                <span class="mode-select-btn-desc" data-i18n="mode_desc_other">Grouped into "Other Income/Deduction" on reports</span>
                            </button>
                        </div>
                    </div>
                    <div class="row mb-3" id="eedCatalogFields">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name">Item</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="eed_ped_type_id" name="ped_type_id" data-api="/api/employee.earning-deduction.options" data-type=""></select>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="eedCustomFields">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_custom_item_name">Item Name</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="eed_custom_item_name" maxlength="150" data-i18n="modal_custom_item_name_placeholder" placeholder="e.g. Uniform deposit refund">
                        </div>
                        <input type="hidden" id="eed_custom_item_type" name="custom_item_type">
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-9">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker required" id="eed_effective_date" name="effective_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <span data-i18n="sec_installment_settings">Installment Settings</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="total_installments">Total Installments</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-3">
                            <input type="number" step="1" min="1" class="form-control required" id="eed_total_installments" name="total_installments" value="1" data-i18n="installments_placeholder" placeholder="e.g., 12">
                        </div>
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" id="eed_principal_amount_label">
                                <span data-i18n="total_amount">Total Amount</span>
                                <span data-i18n="principal_amount_label" class="d-none">Principal Amount</span>
                                <span class="text-danger">*</span>
                            </label>
                        </div>
                        <div class="col-sm-3">
                            <!-- 2026-09-03, Manual Entry / Platform UX review Phase 5 (fee currency):
                                 this field had NO currency indicator at all before -- unlike
                                 base_salary_amount/ere_amount/erd_amount (which hardcoded "THB"), this
                                 one is genuinely new, not a hardcoded-wrong-value fix. Same
                                 currency-code-label mechanism, see those fields' own comments. -->
                            <div class="input-group">
                                <input type="number" step="0.01" min="0.01" class="form-control required" id="eed_principal_amount" name="principal_amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                                <span class="input-group-text currency-code-label">THB</span>
                            </div>
                        </div>
                    </div>
                    <!-- 2026-08-31, explicit request: "Form ที่เป็นรายการหัก ทุก Form ให้เพิ่มว่า คิดดอกเบี้ย
                         ค่าธรรมเนียม หรือไม่มี" -- widened from a 2-state (none/has_interest) toggle to a
                         3-state one (none/interest/fee); the interest sub-detail (fixed/reducing_balance
                         + rate) is UNCHANGED from before, "Fee" is a new sibling, mutually-exclusive
                         charge mode with its own sub-detail (#eedFeeDetailWrapper) -- never both set on
                         the same assignment. -->
                    <div id="eedInterestSection">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="interest_label">Interest / Fee</label>
                            </div>
                            <div class="col-sm-9">
                                <div class="btn-group btn-group-sm" role="group" id="eedInterestToggle">
                                    <button type="button" class="btn btn-outline-brand active" data-value="none"><span data-i18n="interest_none">None</span></button>
                                    <button type="button" class="btn btn-outline-brand" data-value="interest"><span data-i18n="interest_has">Interest</span></button>
                                    <button type="button" class="btn btn-outline-brand" data-value="fee"><span data-i18n="fee_has">Fee</span></button>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3 d-none" id="eedInterestDetailWrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="interest_type">Interest Type</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <div class="d-flex align-items-center flex-wrap gap-2">
                                    <div class="btn-group btn-group-sm" role="group" id="eedInterestTypeToggle">
                                        <button type="button" class="btn btn-outline-brand active" data-value="fixed"><span data-i18n="interest_fixed">Flat</span></button>
                                        <button type="button" class="btn btn-outline-brand" data-value="reducing_balance"><span data-i18n="interest_reducing_balance">Reducing Balance</span></button>
                                    </div>
                                    <div class="input-group input-group-sm" style="max-width:180px;">
                                        <input type="number" step="0.01" min="0.01" class="form-control" id="eed_interest_rate" name="interest_rate" placeholder="0.00">
                                        <span class="input-group-text" data-i18n="interest_rate_suffix">% / installment</span>
                                    </div>
                                </div>
                                <!-- 2026-09-03, Platform UX review Phase 4 (explicit finding: "% ต่องวด" gives no
                                     sense of what one installment period actually spans, since an assignment
                                     genuinely isn't tied to a specific payroll_cycles.payroll_frequency at entry
                                     time -- confirmed via investigation, not guessed. Fix chosen (Option 2, user-
                                     confirmed): a plain calculator that converts a familiar "X% per year" figure
                                     into the per-installment rate this field actually needs, WITHOUT changing the
                                     field/data model at all -- purely a fill-in-for-me helper, the admin still
                                     picks the payroll frequency themselves since nothing here can know it for
                                     certain. -->
                                <div class="mt-2">
                                    <button type="button" class="btn btn-link btn-sm p-0" id="eedRateHelperToggle" data-i18n="rate_helper_toggle">Convert from an annual rate</button>
                                    <div class="d-none mt-2 p-2 bg-light rounded-2 d-flex align-items-center gap-2 flex-wrap" id="eedRateHelperBody">
                                        <div class="input-group input-group-sm" style="max-width:130px;">
                                            <input type="number" step="0.01" min="0" class="form-control" id="eedRateHelperAnnual" data-i18n="rate_helper_annual_placeholder" placeholder="5.00">
                                            <span class="input-group-text">%</span>
                                        </div>
                                        <span class="small text-secondary" data-i18n="rate_helper_per_year">per year, paid</span>
                                        <select class="form-select form-select-sm select2-static" style="max-width:170px;" id="eedRateHelperFrequency"
                                                data-option-keys="rate_helper_freq_monthly,rate_helper_freq_semi_monthly,rate_helper_freq_bi_weekly,rate_helper_freq_weekly"
                                                data-option-values="12,24,26,52"></select>
                                        <button type="button" class="btn btn-outline-brand btn-sm" id="eedRateHelperApply" data-i18n="rate_helper_apply">Use this rate</button>
                                        <span class="small text-muted" id="eedRateHelperResult"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3 d-none" id="eedFeeDetailWrapper">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="fee_percent_label">Fee</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9 d-flex align-items-center flex-wrap gap-2">
                                <div class="input-group input-group-sm" style="max-width:140px;">
                                    <input type="number" step="0.01" min="0.01" class="form-control" id="eed_fee_percent" name="fee_percent" placeholder="0.00">
                                    <span class="input-group-text">%</span>
                                </div>
                                <span class="text-secondary small" data-i18n="fee_of">of</span>
                                <select class="form-select form-select-sm select2-static" style="max-width:220px;" id="eed_fee_base" name="fee_base"
                                        data-option-keys="fee_base_option_principal,fee_base_option_base_salary" data-option-values="principal_amount,base_salary"></select>
                            </div>
                        </div>
                    </div>
                    <!-- 2026-09-03, Platform UX review Phase 4 (explicit finding: the Amount field's own
                         label silently swaps between "Total Amount" and "Principal Amount" depending on
                         charge type, with the SAME number meaning something different underneath --
                         confirmed as the clearest real match for "wrong calc base" confusion. Fix chosen
                         (Option 2, user-confirmed): don't restructure the field (would need a reverse-solve
                         calculation with real risk of its own) -- instead always show, in plain numbers,
                         what the entered principal actually resolves to once interest/fee is added, sourced
                         from the SAME real preview total the installment table below already computes (never
                         a second, hand-rolled copy of the formula) so it can never drift out of sync with
                         what actually gets saved. Hidden entirely when charge type is 'none' -- the amount
                         already IS the total then, nothing to clarify. -->
                    <div class="row mb-3 d-none" id="eedAmountBreakdownRow">
                        <div class="col-sm-3"></div>
                        <div class="col-sm-9">
                            <div class="alert alert-light border py-2 px-3 mb-0 small" id="eedAmountBreakdownText"></div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3">
                            <label class="form-label mb-0" data-i18n="installment_amounts">Amount per Installment</label>
                        </div>
                        <div class="col-sm-9">
                            <div class="table-responsive eed-installment-table-wrap">
                                <table class="table table-sm table-striped align-middle mb-0" id="eedInstallmentTable">
                                    <thead>
                                        <tr>
                                            <th class="text-muted small" style="width:15%;" data-i18n="installment_no_col">#</th>
                                            <th class="text-muted small" data-i18n="installment_amount_col">Amount</th>
                                            <th class="text-muted small d-none" id="eedInstallmentStatusHeader" data-i18n="installment_status_col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="eedInstallmentTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="external_reference_no">Reference / Contract No.</label>
                        </div>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" id="eed_external_reference_no" name="external_reference_no" maxlength="100" data-i18n="external_reference_no_placeholder" placeholder="e.g., Loan contract no.">
                        </div>
                    </div>
                    <!-- 2026-08-31, explicit request: "หักไปจ่ายใคร หรือจ่ายเข้าบัญชีบริษัท" -- widened
                         from "always another employee" to a real 3-way choice (None/Employee/Company
                         Account), same .btn-group toggle convention as #eedModeToggle above. See
                         EmployeeEarningDeductionModel::save()'s own docblock for the payee_type
                         schema.
                         2026-08-31, same-day follow-up: 4th option "not_disbursed" -- "หักเพื่อไม่ทำ
                         จ่ายเฉยๆ โดยเงินไม่ออกจากกองทุน" (withheld but no money moves anywhere at all,
                         distinct from "none" above which still reduces the employee's own net pay --
                         a real outflow from the fund, just untracked-by-payee). Never shows the
                         Include-in-Cash-Summary checkbox below -- forced excluded at the model layer
                         (EmployeeEarningDeductionModel::save()'s own comment), so offering it as a
                         toggle here would be misleading. -->
                    <div class="row mb-3 d-none" id="eedPayeeWrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="payee_type_label">Deducted Money Goes To</label>
                        </div>
                        <div class="col-sm-9">
                            <div class="btn-group btn-group-sm flex-wrap" role="group" id="eedPayeeTypeToggle">
                                <button type="button" class="btn btn-outline-brand active" data-payee-type="none"><span data-i18n="payee_type_none">Employee's Own Net Pay</span></button>
                                <button type="button" class="btn btn-outline-brand" data-payee-type="employee"><span data-i18n="payee_type_employee">Another Employee</span></button>
                                <button type="button" class="btn btn-outline-brand" data-payee-type="company"><span data-i18n="payee_type_company">Company Account</span></button>
                                <!-- 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 --
                                     this modal never got the 'other_person' option when Phase 2 first
                                     built the destination-picker flow (Phase 2 only touched Process
                                     Detail's own manual-line modal) -- added now since "Other Deduction"
                                     genuinely needs it, and it applies to any deduction here (catalog or
                                     custom), same as the rest of this toggle. -->
                                <button type="button" class="btn btn-outline-brand" data-payee-type="other_person"><span data-i18n="payee_type_other_person">Other Person / Third Party</span></button>
                                <button type="button" class="btn btn-outline-brand" data-payee-type="not_disbursed"><span data-i18n="payee_type_not_disbursed">Deducted, No Cash Movement (Write-off)</span></button>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="eedPayeeEmployeeWrapper">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="payee_employee_label">Payee Employee (transfer to)</label>
                        </div>
                        <div class="col-sm-9">
                            <select class="form-select select2-remote" id="eed_payee_employee_id" name="payee_employee_id" data-api="/api/employee.report_to.get" data-type="employee"></select>
                        </div>
                    </div>
                    <div class="row g-2 align-items-end mb-3 d-none" id="eedDestinationWrapper">
                        <div class="col-sm-3"></div>
                        <div class="col-sm-9">
                            <label class="form-label mb-1 small text-muted" data-i18n="destination_saved_label">Select a Saved Destination (optional)</label>
                            <select class="form-select select2-remote" id="eed_destination_select" data-api="/api/payment-destination.options" data-type="payment_destination" allow-clear="true"></select>
                        </div>
                        <div class="col-sm-9 offset-sm-3 mt-2" id="eedDestinationNewFields">
                            <div class="row g-2">
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_account_name">Account Name</label><input type="text" class="form-control form-control-sm" id="eed_dest_account_name" data-i18n="destination_account_name_placeholder" placeholder="e.g., Somchai Jaidee"></div>
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_account_no">Account No.</label><input type="text" class="form-control form-control-sm" id="eed_dest_account_no" data-i18n="destination_account_no_placeholder" placeholder="e.g., 1234567890"></div>
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_bank">Bank</label><select class="form-select select2-remote" id="eed_dest_bank" data-api="/api/bank.get" data-type="bank"></select></div>
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_bank_branch">Branch</label><input type="text" class="form-control form-control-sm" id="eed_dest_bank_branch" data-i18n="destination_bank_branch_placeholder" placeholder="e.g., Central World Branch"></div>
                                <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="eed_dest_save_for_reuse"><label class="form-check-label small" for="eed_dest_save_for_reuse" data-i18n="destination_save_for_reuse">Save this destination for reuse next time</label></div></div>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="eedIncludeCashSummaryWrapper">
                        <div class="col-sm-3"></div>
                        <div class="col-sm-9">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="eed_include_in_cash_summary" name="include_in_cash_summary" checked>
                                <label class="form-check-label" for="eed_include_in_cash_summary" data-i18n="include_in_cash_summary_label">Include in Cash Payment Summary Report</label>
                            </div>
                            <div class="form-text" data-i18n="include_in_cash_summary_hint">*Uncheck to track this amount as its own separate line instead of folding it into the report's aggregate total.</div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0" data-i18n="notes">Notes</label>
                        </div>
                        <div class="col-sm-9">
                            <textarea class="form-control" id="eed_notes" name="notes" rows="2" data-i18n="notes_placeholder" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="eedSaveBtn" data-i18n="save_item">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="recurringEarningModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="recurringEarningModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="recurringEarningModalLabel">
                    <span data-i18n="add_recurring_earning">Add Recurring Allowance</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="recurringEarningForm" novalidate>
                <input type="hidden" id="ere_id" name="id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name">Item</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <select class="form-select select2-remote required" id="ere_ped_type_id" name="ped_type_id" data-api="/api/employee.recurring-earning.type-options"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="amount">Amount</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="number" step="0.01" min="0.01" class="form-control text-end required" id="ere_amount" name="amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                                <!-- 2026-09-03, Manual Entry / Platform UX review Phase 5 (fee currency):
                                     was hardcoded "THB" -- see base_salary_amount's own comment in
                                     employee/detail.php for the full explanation. -->
                                <span class="input-group-text currency-code-label">THB</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker required" id="ere_effective_date" name="effective_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-2"><span data-i18n="suspend_period">Suspend Period</span></h6>
                    <p class="text-secondary small mb-3" data-i18n="suspend_period_hint">*Optional. While set, this allowance is skipped in any payroll run whose pay period overlaps this range, then resumes automatically afterward.</p>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="suspend_from">Suspend From</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="ere_suspended_from" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="suspend_to">Suspend To</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="ere_suspended_to" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0" data-i18n="notes">Notes</label>
                        </div>
                        <div class="col-sm-8">
                            <textarea class="form-control" id="ere_notes" rows="2" maxlength="255" data-i18n="notes_placeholder" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="ereSaveBtn" data-i18n="save_item">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2026-08-31, direct mirror of #recurringEarningModal immediately above -- `erd` prefix instead
     of `ere`, posts to api/employee.recurring-deduction.* instead of .recurring-earning.* -->
<div class="modal fade" id="recurringDeductionModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="recurringDeductionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary" id="recurringDeductionModalLabel">
                    <span data-i18n="add_recurring_deduction">Add Recurring Deduction</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="recurringDeductionForm" novalidate>
                <input type="hidden" id="erd_id" name="id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="item_name">Item</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <select class="form-select select2-remote required" id="erd_ped_type_id" name="ped_type_id" data-api="/api/employee.recurring-deduction.type-options"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="amount">Amount</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="number" step="0.01" min="0.01" class="form-control text-end required" id="erd_amount" name="amount" data-i18n="amount_placeholder" placeholder="e.g., 500.00">
                                <!-- 2026-09-03, Manual Entry / Platform UX review Phase 5 (fee currency):
                                     was hardcoded "THB" -- see base_salary_amount's own comment in
                                     employee/detail.php for the full explanation. -->
                                <span class="input-group-text currency-code-label">THB</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker required" id="erd_effective_date" name="effective_date" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <!-- 2026-08-31, explicit request: "Form ที่เป็นรายการหัก ทุก Form ให้เพิ่มว่า คิดดอกเบี้ย
                         ค่าธรรมเนียม หรือไม่มี" -- "Interest" has NO equivalent here (no principal/
                         installment-schedule concept exists on this table at all, see
                         EmployeeRecurringDeductionModel's own migration comment), only "Fee" does: an
                         ONGOING % of the employee's base salary, ADDED on top of `amount` fresh every
                         payroll run (not baked in once) -- see PayrollRunModel::recurringDeductionAmountWithFee().
                         Only one fee_base ever validates for this table (base_salary -- no
                         'principal_amount' equivalent), so this is a plain fixed label, not a
                         dropdown like #eedModal's own 2-option one. -->
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0" data-i18n="fee_percent_label">Fee</label>
                        </div>
                        <div class="col-sm-8">
                            <div class="btn-group btn-group-sm" role="group" id="erdFeeToggle">
                                <button type="button" class="btn btn-outline-brand active" data-value="none"><span data-i18n="interest_none">None</span></button>
                                <button type="button" class="btn btn-outline-brand" data-value="fee"><span data-i18n="fee_has">Fee</span></button>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="erdFeeDetailWrapper">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="fee_percent_label">Fee</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-8 d-flex align-items-center gap-2">
                            <div class="input-group input-group-sm" style="max-width:140px;">
                                <input type="number" step="0.01" min="0.01" class="form-control" id="erd_fee_percent" name="fee_percent" placeholder="0.00">
                                <span class="input-group-text">%</span>
                            </div>
                            <span class="text-secondary small" data-i18n="fee_of">of</span>
                            <span class="text-secondary small" data-i18n="fee_base_option_base_salary">Base Salary</span>
                            <input type="hidden" id="erd_fee_base" name="fee_base" value="base_salary">
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <!-- 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6 -- same
                         payee_type toggle convention as #eedModal's own #eedPayeeTypeToggle above,
                         PLUS the 'other_person' option (a saved/new third-party bank account, via
                         PaymentDestinationModel -- see that model's own docblock). This is the
                         TEMPLATE-level default: inherited by every payroll run this recurring
                         deduction is active in, unless that one run has its own override (Process
                         Detail's own "Recurring Deduction Destination" panel, this run only, never
                         written back here). -->
                    <h6 class="text-secondary fw-bold mb-2"><span data-i18n="payee_type_label">Deducted Money Goes To</span></h6>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center"></div>
                        <div class="col-sm-8">
                            <div class="btn-group btn-group-sm flex-wrap" role="group" id="erdPayeeTypeToggle">
                                <button type="button" class="btn btn-outline-brand active" data-payee-type="none"><span data-i18n="payee_type_none">Employee's Own Net Pay</span></button>
                                <button type="button" class="btn btn-outline-brand" data-payee-type="employee"><span data-i18n="payee_type_employee">Another Employee</span></button>
                                <button type="button" class="btn btn-outline-brand" data-payee-type="company"><span data-i18n="payee_type_company">Company Account</span></button>
                                <button type="button" class="btn btn-outline-brand" data-payee-type="other_person"><span data-i18n="payee_type_other_person">Other Person / Third Party</span></button>
                                <button type="button" class="btn btn-outline-brand" data-payee-type="not_disbursed"><span data-i18n="payee_type_not_disbursed">Deducted, No Cash Movement (Write-off)</span></button>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="erdPayeeEmployeeWrapper">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0" data-i18n="payee_employee_label">Payee Employee (transfer to)</label>
                        </div>
                        <div class="col-sm-8">
                            <select class="form-select select2-remote" id="erd_payee_employee_id" data-api="/api/employee.report_to.get" data-type="employee"></select>
                        </div>
                    </div>
                    <div class="row g-2 align-items-end mb-3 d-none" id="erdDestinationWrapper">
                        <div class="col-12">
                            <label class="form-label mb-1 small text-muted" data-i18n="destination_saved_label">Select a Saved Destination (optional)</label>
                            <select class="form-select select2-remote" id="erd_destination_select" data-api="/api/payment-destination.options" data-type="payment_destination" allow-clear="true"></select>
                        </div>
                        <div class="col-12 mt-2" id="erdDestinationNewFields">
                            <div class="row g-2">
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_account_name">Account Name</label><input type="text" class="form-control form-control-sm" id="erd_dest_account_name" data-i18n="destination_account_name_placeholder" placeholder="e.g., Somchai Jaidee"></div>
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_account_no">Account No.</label><input type="text" class="form-control form-control-sm" id="erd_dest_account_no" data-i18n="destination_account_no_placeholder" placeholder="e.g., 1234567890"></div>
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_bank">Bank</label><select class="form-select select2-remote" id="erd_dest_bank" data-api="/api/bank.get" data-type="bank"></select></div>
                                <div class="col-sm-6"><label class="form-label mb-1 small" data-i18n="destination_bank_branch">Branch</label><input type="text" class="form-control form-control-sm" id="erd_dest_bank_branch" data-i18n="destination_bank_branch_placeholder" placeholder="e.g., Central World Branch"></div>
                                <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="erd_dest_save_for_reuse"><label class="form-check-label small" for="erd_dest_save_for_reuse" data-i18n="destination_save_for_reuse">Save this destination for reuse next time</label></div></div>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4 text-muted opacity-25">
                    <h6 class="text-secondary fw-bold mb-2"><span data-i18n="suspend_period">Suspend Period</span></h6>
                    <p class="text-secondary small mb-3" data-i18n="suspend_period_hint">*Optional. While set, this allowance is skipped in any payroll run whose pay period overlaps this range, then resumes automatically afterward.</p>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="suspend_from">Suspend From</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="erd_suspended_from" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="suspend_to">Suspend To</span></label>
                        </div>
                        <div class="col-sm-8">
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="erd_suspended_to" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-4 align-self-center">
                            <label class="form-label mb-0" data-i18n="notes">Notes</label>
                        </div>
                        <div class="col-sm-8">
                            <textarea class="form-control" id="erd_notes" rows="2" maxlength="255" data-i18n="notes_placeholder" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="erdSaveBtn" data-i18n="save_item">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="empSignaturePadModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary"><i class="fa-solid fa-pen-nib me-2"></i><span data-i18n="draw_signature">Draw Signature</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <canvas id="empSignaturePadCanvas" class="cp-signature-pad-canvas" width="500" height="220"></canvas>
                <p class="text-muted small mt-2 mb-0" data-i18n="draw_signature_hint">Draw with your mouse or finger, then click Save.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="empSignaturePadClearBtn"><i class="fa-solid fa-eraser me-1"></i><span data-i18n="clear">Clear</span></button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="empSignaturePadSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="empMapPinModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary"><i class="fa-solid fa-map-location-dot me-2"></i><span data-i18n="pin_location_on_map">Pin Location on Map</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-2" id="empMapSearchInput" data-i18n="map_search_placeholder" placeholder="Search for an address...">
                <div id="empMapPinContainer" style="width:100%;height:360px;border-radius:8px;overflow:hidden;"></div>
                <p class="text-muted small mt-2 mb-0" data-i18n="map_pin_hint">Click anywhere on the map, or drag the marker, to set the location.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="empMapPinSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Company Profile (app/views/setup/company-profile.php) ===== -->
<div class="modal fade" id="bffFieldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" data-i18n="add_field">Add Field</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bffFieldId">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" data-i18n="field_label_th">Label (Thai)</label>
                        <input type="text" class="form-control required" id="bffFieldLabelTh" data-i18n="bff_field_label_th_placeholder" placeholder="e.g., ชื่อบัญชี">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="field_label_en">Label (English)</label>
                        <input type="text" class="form-control required" id="bffFieldLabelEn" data-i18n="bff_field_label_en_placeholder" placeholder="e.g., Account Name">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="row_type">Row</label>
                        <select class="form-select" id="bffFieldRowType">
                            <option value="detail" data-i18n="row_type_detail">Detail (per employee)</option>
                            <option value="header" data-i18n="row_type_header">Header</option>
                            <option value="trailer" data-i18n="row_type_trailer">Trailer</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="order">Order</label>
                        <input type="number" class="form-control" id="bffFieldSortOrder" min="0" value="0" data-i18n="count_placeholder" placeholder="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="source_type">Source Type</label>
                        <select class="form-select" id="bffFieldSourceType">
                            <option value="employee_field" data-i18n="source_type_employee_field">Payroll Field</option>
                            <option value="constant" data-i18n="source_type_constant">Fixed Value</option>
                            <option value="blank" data-i18n="source_type_blank">Blank</option>
                        </select>
                    </div>
                    <div class="col-6" id="bffFieldSourceFieldWrap">
                        <label class="form-label" data-i18n="source">Source</label>
                        <select class="form-select" id="bffFieldSourceField"></select>
                    </div>
                    <div class="col-6 d-none" id="bffFieldConstantWrap">
                        <label class="form-label" data-i18n="constant_value">Fixed Value</label>
                        <input type="text" class="form-control" id="bffFieldConstantValue" data-i18n="bff_field_constant_value_placeholder" placeholder="e.g., 01">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="data_type">Data Type</label>
                        <select class="form-select" id="bffFieldDataType">
                            <option value="text" data-i18n="data_type_text">Text</option>
                            <option value="number" data-i18n="data_type_number">Number</option>
                            <option value="date" data-i18n="data_type_date">Date</option>
                        </select>
                    </div>
                    <div class="col-6" id="bffFieldDecimalWrap">
                        <label class="form-label" data-i18n="decimal_places">Decimal Places</label>
                        <input type="number" class="form-control" id="bffFieldDecimalPlaces" min="0" max="6" value="2" data-i18n="decimal_places_placeholder" placeholder="0-4">
                    </div>
                    <div class="col-6 d-none" id="bffFieldDateFormatWrap">
                        <label class="form-label" data-i18n="date_format">Date Format</label>
                        <input type="text" class="form-control" id="bffFieldDateFormat" value="Ymd" placeholder="Ymd">
                    </div>
                    <div class="col-4">
                        <label class="form-label" data-i18n="width">Width</label>
                        <input type="number" class="form-control" id="bffFieldWidth" min="1" data-i18n="field_width_placeholder" placeholder="e.g., 10">
                    </div>
                    <div class="col-4">
                        <label class="form-label" data-i18n="pad_char">Pad Char</label>
                        <input type="text" class="form-control" id="bffFieldPadChar" maxlength="1" value=" " data-i18n="bff_field_pad_char_placeholder" placeholder="e.g., 0">
                    </div>
                    <div class="col-4">
                        <label class="form-label" data-i18n="pad_direction">Pad Direction</label>
                        <select class="form-select" id="bffFieldPadDirection">
                            <option value="right" data-i18n="pad_direction_right">Right</option>
                            <option value="left" data-i18n="pad_direction_left">Left</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="bffFieldSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bffLogModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" data-i18n="edit_log">Edit Log</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bffLogModalBody"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="cpSignaturePadModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary"><i class="fa-solid fa-pen-nib me-2"></i><span data-i18n="draw_signature">Draw Signature</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <canvas id="cpSignaturePadCanvas" class="cp-signature-pad-canvas" width="500" height="220"></canvas>
                <p class="text-muted small mt-2 mb-0" data-i18n="draw_signature_hint">Draw with your mouse or finger, then click Save.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="cpSignaturePadClearBtn"><i class="fa-solid fa-eraser me-1"></i><span data-i18n="clear">Clear</span></button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="cpSignaturePadSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="orgStructureSyncModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="orgStructureSyncModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="orgStructureSyncModalLabel">
                    <i class="fa-solid fa-rotate me-1"></i><span id="orgStructureSyncModalLabelText">Sync from Origami</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5 d-none" id="orgStructureSyncNotConnected">
                    <i class="fa-solid fa-plug-circle-xmark fa-2x text-danger mb-3"></i>
                    <div class="fw-bold mb-1" data-i18n="employee_sync_not_connected_title">Not connected to Origami</div>
                    <div class="text-muted small" id="orgStructureSyncNotConnectedMessage" data-i18n="employee_sync_not_connected_message">The connection to Origami has not been configured yet. Please contact your system administrator.</div>
                </div>
                <div id="orgStructureSyncBody" class="d-none">
                    <div id="orgStructureSyncResultArea" class="d-none">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="d-flex align-items-center mb-2">
                                    <h6 class="mb-0 text-success"><span data-i18n="employee_sync_tab_new">New</span> <span class="badge bg-success ms-1" id="orgSyncNewCount">0</span></h6>
                                </div>
                                <div class="border rounded" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle w-100 mb-0" id="tb_org_sync_new">
                                        <thead class="table-light text-secondary" style="position: sticky; top: 0; z-index: 1;">
                                            <tr>
                                                <th style="width:3%;"><input type="checkbox" id="orgSyncNewSelectAll"></th>
                                                <th data-i18n="name">Name</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="d-flex align-items-center mb-2">
                                    <h6 class="mb-0 text-secondary"><span data-i18n="employee_sync_tab_existing">Already Exists</span> <span class="badge bg-secondary ms-1" id="orgSyncExistingCount">0</span></h6>
                                </div>
                                <div class="border rounded" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle w-100 mb-0" id="tb_org_sync_existing">
                                        <thead class="table-light text-secondary" style="position: sticky; top: 0; z-index: 1;">
                                            <tr>
                                                <th style="width:3%;"><input type="checkbox" id="orgSyncExistingSelectAll"></th>
                                                <th data-i18n="name">Name</th>
                                                <th data-i18n="employee_sync_update_col">Update Available</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-muted small text-center py-4" id="orgStructureSyncLoadingHint">
                        <i class="fa-solid fa-spinner fa-spin me-1"></i><span data-i18n="loading">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <span class="text-muted small" id="orgSyncSelectedCountLabel"></span>
                <div>
                    <button type="button" class="btn btn-primary d-none" id="btnApplyOrgStructureSync">
                        <i class="fa-solid fa-download me-1"></i><span data-i18n="employee_sync_apply_button">Sync Selected</span> (<span id="orgSyncSelectedCount">0</span>)
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="orgStructureSyncLogModal" tabindex="-1" aria-labelledby="orgStructureSyncLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="orgStructureSyncLogModalLabel">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i><span id="orgStructureSyncLogModalLabelText">Sync Log</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-hover table-sm align-middle w-100" id="tb_org_structure_sync_log">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_sync_log_col_date">Date</th>
                            <th data-i18n="employee_sync_log_col_triggered_by">By</th>
                            <th data-i18n="employee_sync_log_col_status">Status</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_total">Total</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_success">Success</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_error">Error</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Payroll Process List (app/views/payroll/index.php) ===== -->
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
                    <!-- 2026-09-02, same-day follow-up, explicit request: "พอมีแค่...ให้ติ๊กออกแล้วค่อยให้เลือก
                         รอบ...ดูงงๆ ช่วยเพิ่มเป็น radio ให้เลือก...ถ้าเลือก option 1 ให้ขึ้นรอบให้เลือก ถ้าเลือก
                         option 2 ไม่ขึ้นให้เลือก" -- the single checkbox (unchecked = "no schedule needed"
                         being an implicit double-negative, and unchecking to REVEAL a field reads as
                         backwards) replaced with an explicit 2-option .run-choice-card radio -- option 1
                         (cycle, default) shows the Payroll Schedule picker, option 2 (offcycle) reveals
                         #run_offcycle_panel below (a genuinely different, smaller-scale sub-decision, see
                         that panel's own comment for why it no longer uses this same card design).
                         2026-09-02, 4th same-day follow-up, explicit request: "อยากให้แสดงเต็มแถวเลยครับ...
                         ถ้าเลือกตามรอบดูสวย แต่พอเลือกนอกรอบดูแหว่งๆ" -- dropped the col-sm-9/offset-sm-3 split
                         (this is a top-level type selector, not a "label: control" field like everything
                         below it, so the blank offset-sm-3 gutter never had real content to balance
                         against -- looked fine only by coincidence when "Follow a schedule" was active,
                         since the Payroll Schedule field right below it happens to share that same
                         label-left/control-right shape; picking off-schedule instead revealed the
                         bordered #run_offcycle_panel starting flush left, right under a card row that
                         wasn't). Cards now span the modal's full width. -->
                    <div class="mb-3" id="run_offcycle_row">
                        <div class="run-choice-toggle">
                            <label class="run-choice-card" for="run_schedule_choice_cycle">
                                <input class="form-check-input" type="radio" name="runScheduleChoice" id="run_schedule_choice_cycle" value="cycle" checked>
                                <span class="run-choice-card-icon"><i class="fa-solid fa-calendar-check"></i></span>
                                <span class="run-choice-card-body">
                                    <span class="run-choice-card-label" data-i18n="run_schedule_choice_cycle">Follow a payroll schedule</span>
                                    <span class="run-choice-card-sub" data-i18n="run_schedule_choice_cycle_sub">Pick from your configured payroll schedules</span>
                                </span>
                            </label>
                            <label class="run-choice-card" for="run_schedule_choice_offcycle">
                                <input class="form-check-input" type="radio" name="runScheduleChoice" id="run_schedule_choice_offcycle" value="offcycle">
                                <span class="run-choice-card-icon"><i class="fa-solid fa-money-bill-transfer"></i></span>
                                <span class="run-choice-card-body">
                                    <span class="run-choice-card-label" data-i18n="offcycle_run_label">Off-schedule run</span>
                                    <span class="run-choice-card-sub" data-i18n="offcycle_run_label_sub">No payroll schedule needed -- e.g. an out-of-schedule payment</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <!-- 2026-09-02, 2nd same-day follow-up, explicit request: "พอเป็น Design แบบเดียวกันแล้วดู
                         แปลกๆครับ ช่วย Design Form ให้ใหม่" -- was 2 big .run-choice-card blocks stacked back
                         to back, reading as two equally-weighted top-level decisions when the merge
                         choice only ever means anything AFTER "off-schedule" is picked above. Now a
                         genuinely NESTED sub-panel (.run-offcycle-panel, dashed brand-orange border) that
                         only appears together with the off-schedule option -- see setOffCycleMode() in
                         index.js, which also fixes a real validation gap this redesign surfaced: before
                         this, the merge choice stayed reachable even while "Follow a payroll schedule"
                         was selected, letting an admin pick BOTH a cycle and a merge target and hit a
                         guaranteed backend rejection on save (PayrollRunModel::create() refuses a merge
                         target whenever cycle_id is set) -- now impossible, the panel (and everything in
                         it) is only in the DOM's visible tree at all when off-schedule is chosen.
                         2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบ
                         ใหม่ หรืออ้างอิงถึงรอบ" -- confirmed via AskUserQuestion: this whole block (radio +
                         target picker) is scoped ONLY to the standalone "Add" flow (hidden entirely for a
                         Pull-sync/Origami create -- that flow stays exactly as-is, still driven solely by
                         Origami's own attribution, see PayrollRunModel::mergeSupplementalIntoRun()).
                         Choosing "reference" doesn't change what create() does right now -- the run is
                         still created and built up normally (Join Employees/Manage Items); this just
                         tags where it should eventually fold into, surfaced as a "Merge into Target"
                         action on the new run's own Detail page once it's ready (PayrollRunModel::
                         mergeIntoExistingRun()). Target picker covers every state except cancelled
                         (confirmed via AskUserQuestion) -- approved/paid/locked targets are reachable
                         too, requiring the same revert/reopen confirmation mergeSupplementalIntoRun()'s
                         own equivalent button already asks for. -->
                    <div class="run-offcycle-panel d-none" id="run_offcycle_panel">
                        <div class="run-offcycle-panel-title"><i class="fa-solid fa-sliders"></i> <span data-i18n="run_offcycle_panel_title">Off-schedule round options</span></div>
                        <div class="row mb-3" id="run_merge_choice_row">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0" data-i18n="run_merge_choice_label">Create this round as</label>
                            </div>
                            <div class="col-sm-9">
                                <div class="run-subchoice-toggle">
                                    <label class="run-subchoice-btn active" for="run_merge_choice_new">
                                        <input type="radio" name="runMergeChoice" id="run_merge_choice_new" value="new" checked>
                                        <i class="fa-solid fa-file-circle-plus"></i>
                                        <span data-i18n="run_merge_choice_new">Open a new round</span>
                                    </label>
                                    <label class="run-subchoice-btn" for="run_merge_choice_reference">
                                        <input type="radio" name="runMergeChoice" id="run_merge_choice_reference" value="reference">
                                        <i class="fa-solid fa-link"></i>
                                        <span data-i18n="run_merge_choice_reference">Reference an existing round</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3 d-none" id="run_merge_target_row">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="run_merge_target_label">Target Round</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-remote" id="run_merge_target_id" name="merge_target_run_id"
                                        data-api="/api/payroll-run.options" data-states="draft,pending_approval,approved,rejected,need_info,paid,locked"></select>
                                <div class="form-text small" data-i18n="run_merge_target_hint">Build this round up normally first (Join Employees / Manage Items) -- once ready, use "Merge into Target" on its own Detail page to fold it into the round selected here.</div>
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
                    <div class="row mb-3 d-none" id="run_include_base_salary_row">
                        <div class="col-sm-9 offset-sm-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="run_include_base_salary">
                                <label class="form-check-label" for="run_include_base_salary" data-i18n="include_base_salary_label">Include base salary (full amount, not prorated)</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="run_include_standing_items_row">
                        <div class="col-sm-9 offset-sm-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="run_include_standing_items">
                                <label class="form-check-label" for="run_include_standing_items" data-i18n="include_standing_items_label">Include configured income/deduction items (standing PED assignments + Recurring Allowances)</label>
                            </div>
                        </div>
                    </div>
                    <!-- 2026-08-30 (Phase 8, T041) -- pulls sync-derived attendance EARNING lines
                         only (OT/trip allowance/item_values), never the deduction side, computed
                         through the real rate engine instead of a hand-typed manual amount. See
                         PayrollRunModel::recalculate()'s own include_attendance_pay comment. -->
                    <div class="row mb-3 d-none" id="run_include_attendance_pay_row">
                        <div class="col-sm-9 offset-sm-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="run_include_attendance_pay">
                                <label class="form-check-label" for="run_include_attendance_pay" data-i18n="include_attendance_pay_label">Include attendance-driven earnings (OT/trip allowance), calculated automatically</label>
                            </div>
                        </div>
                    </div>
                    <!-- 2026-08-31, same-day follow-up (Origami `attribution` plan's item 3) -- only
                         ever shown for a supplemental sync process Origami attributed
                         tax_treatment='separate' (see public/js/payroll/index.js's own
                         setSupplementalPullMode() docblock), pre-checked when shown. Uses the
                         Payroll Policy tab's own company-configured
                         supplemental_flat_tax_rate_percent -- if that's never been set, this flag is
                         a silent no-op and the normal average/actual PIT calculation runs instead
                         (PayrollRunModel::recalculate()'s own TH_PIT block never invents a rate). -->
                    <div class="row mb-3 d-none" id="run_use_flat_tax_rate_row">
                        <div class="col-sm-9 offset-sm-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="run_use_flat_tax_rate">
                                <label class="form-check-label" for="run_use_flat_tax_rate" data-i18n="use_flat_tax_rate_label">Withhold tax at the company's configured flat rate (Payroll Policy tab), instead of average/actual</label>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3" id="run_cycle_row">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0"><span data-i18n="modal_cycle">Payroll Schedule</span> <span class="text-danger">*</span></label>
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
                            <textarea class="form-control" id="run_notes" name="notes" rows="2" data-i18n="notes_placeholder" placeholder="Optional notes"></textarea>
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

<div class="modal fade" id="runWorkflowModal" tabindex="-1" aria-labelledby="runWorkflowModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-secondary mb-0" id="runWorkflowModalLabel">
                        <i class="fa-solid fa-list-check me-1"></i><span data-i18n="approval_timeline_title">Approval Timeline</span>
                    </h5>
                    <div class="text-muted small" id="runWorkflowModalRunName"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="runWorkflowModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="runErrorEmployeesModal" tabindex="-1" aria-labelledby="runErrorEmployeesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-danger" id="runErrorEmployeesModalLabel">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i><span data-i18n="incomplete_data">Incomplete data</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="runErrorEmployeesModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- 2026-08-30 (Phase 3, T019, explicit request: "แก้ไขข้อมูลผ่าน Modal ได้จากหน้า List เลย", depends on
     T018's Recheck tab) -- a quick-edit modal covering ONLY the fields the Recheck tab's own columns
     check, so an admin can fix a gap without leaving the List page for the full Detail page.
     IMPORTANT: EmployeeModel::save() always overwrites EVERY column from whatever's submitted (see
     that method's own docblock) -- public/js/employee/list.js's own save handler for this modal
     fetches the employee's FULL existing record first (GET api/employee.get), merges just this
     form's fields into it, and submits the WHOLE merged object, exactly the same "always resubmit
     everything" discipline collectEmployeeFormData() already follows on the real Detail page. Never
     submits this form's fields alone -- doing so would silently blank out every OTHER field on the
     employee (Documents, Family, etc. all untouched by this modal). employee_type/payment_type
     themselves are shown read-only (badges) -- they decide WHICH identification/bank fields apply,
     but changing either is a bigger structural edit better done on the full Detail page. -->
<div class="modal fade" id="employeeRecheckEditModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="employeeRecheckEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="employeeRecheckEditModalLabel" data-i18n="recheck_data">Recheck Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="employeeRecheckEditForm" novalidate>
                <input type="hidden" id="rc_id" name="id">
                <input type="hidden" id="rc_employee_no" name="employee_no">
                <div class="modal-body">
                    <div class="d-flex align-items-center gap-2 mb-4">
                        <span class="fw-bold" id="rcEditEmployeeNoLabel">-</span>
                        <span class="badge bg-secondary-subtle text-secondary" id="rcEditTypeBadge"></span>
                    </div>
                    <!-- 2026-08-30, same-day follow-up ("Form จัดใหม่ ให้แยกตามประเภท และเติม Icon ลงไปด้วย")
                         -- grouped into the same numbered-section convention every other form in this
                         app uses (label.label-head.bg-head-first, see T025's own color fix on that
                         shared class), each section's icon reused from its own corresponding TAB
                         icon on the real Employee Detail page for visual continuity (Personal
                         Information/Contact/Employment/Salary all mirror info-tab/contact-tab/
                         employment-tab/salary-tab's own <i> icons exactly). -->
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                        <i class="fa-solid fa-circle-user text-secondary mx-1"></i>
                        <span data-i18n="personal_information">Personal Information</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-3">
                            <label class="form-label mb-0"><span data-i18n="title">Title</span></label>
                            <select class="form-select select2-native" name="title" id="rc_title">
                                <option value="" data-i18n="please_choose">Select an option</option>
                                <option value="mr" data-i18n="title_mr">Mr.</option>
                                <option value="mrs" data-i18n="title_mrs">Mrs.</option>
                                <option value="ms" data-i18n="title_ms">Ms.</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label mb-0" data-i18n="gender">Gender</label>
                            <select class="form-select select2-native" name="gender" id="rc_gender">
                                <option value="male" data-i18n="male">Male</option>
                                <option value="female" data-i18n="female">Female</option>
                                <option value="other" data-i18n="other">Other</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label mb-0" data-i18n="date_of_birth">Date of Birth</label>
                            <input type="text" class="form-control datepicker" name="date_of_birth" id="rc_date_of_birth" autocomplete="off">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label mb-0" data-i18n="nationality">Nationality</label>
                            <select class="form-select select2-remote" name="nationality" id="rc_nationality" data-api="/api/nationality.get" data-type="nationality"></select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-6">
                            <label class="form-label mb-0" data-i18n="name_local">Name (Local)</label>
                            <input type="text" class="form-control" name="name_th" id="rc_name_th" data-i18n="name_th_placeholder" placeholder="e.g., สมชาย">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label mb-0" data-i18n="name_en">Name (EN)</label>
                            <input type="text" class="form-control" name="name_en" id="rc_name_en" data-i18n="name_en_placeholder" placeholder="e.g., Somchai">
                        </div>
                    </div>
                    <!-- 2026-08-30, explicit request: "เพิ่มนามสกุล ไทย อังกฤษ ด้วยครับ แต่ไม่ Require Field"
                         -- deliberately NOT .required (surname stopped being a required field entirely
                         in T023, see EmployeeModel::requiredColumns()'s own docblock). -->
                    <div class="row mb-4">
                        <div class="col-sm-6">
                            <label class="form-label mb-0" data-i18n="surname_local">Surname (Local)</label>
                            <input type="text" class="form-control" name="surname_th" id="rc_surname_th" data-i18n="surname_th_placeholder" placeholder="e.g., ใจดี">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label mb-0" data-i18n="surname_en">Surname (EN)</label>
                            <input type="text" class="form-control" name="surname_en" id="rc_surname_en" data-i18n="surname_en_placeholder" placeholder="e.g., Jaidee">
                        </div>
                    </div>
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                        <i class="fa-solid fa-id-card text-secondary mx-1"></i>
                        <span data-i18n="identification">Identification</span>
                    </h6>
                    <div class="row mb-4" id="rcDomesticIdWrap">
                        <div class="col-sm-6">
                            <label class="form-label mb-0" data-i18n="id_card_no">ID Card No.</label>
                            <input type="text" class="form-control" name="id_card_no" id="rc_id_card_no" maxlength="13" data-i18n="id_card_no_placeholder" placeholder="13-digit national ID number">
                        </div>
                    </div>
                    <div class="row mb-4 d-none" id="rcForeignerIdWrap">
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="tax_id_no">Tax ID No.</label>
                            <input type="text" class="form-control" name="tax_id_no" id="rc_tax_id_no" data-i18n="tax_id_placeholder" placeholder="e.g., 1234567890123">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="passport_no">Passport No.</label>
                            <input type="text" class="form-control" name="passport_no" id="rc_passport_no" data-i18n="passport_no_placeholder" placeholder="e.g., AA1234567">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="work_permit_no">Work Permit No.</label>
                            <input type="text" class="form-control" name="work_permit_no" id="rc_work_permit_no" data-i18n="work_permit_no_placeholder" placeholder="e.g., WP-1234567">
                        </div>
                    </div>
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">3</label>
                        <i class="fa-solid fa-address-book text-secondary mx-1"></i>
                        <span data-i18n="contact">Contact</span>
                    </h6>
                    <div class="row mb-4">
                        <div class="col-sm-6">
                            <label class="form-label mb-0" data-i18n="personal_email">Personal Email Address</label>
                            <input type="email" class="form-control" name="personal_email" id="rc_personal_email" data-i18n="personal_email_placeholder" placeholder="e.g., name@email.com">
                        </div>
                        <!-- 2026-08-31, explicit request: "ใน Form ตรงที่เป็นเบอร์มือถือ อยากให้รูปแบบเดียวกับ
                             ใน Employee Detail มี Prefix ด้วย" -- was a plain text input with no country-
                             code concept; now uses the SAME intl-tel-input country flag/dial-code
                             picker as Employee Detail's own #mobile_no (see detail.js's own
                             initMobileIti()/syncMobileCountryCode(), mirrored here as
                             initRcMobileIti()/syncRcMobileCountryCode() in list.js -- a separate
                             instance since this modal is never on the same page as Employee Detail,
                             but scoped independently rather than reusing window.mobileIti to avoid any
                             cross-page global collision). rc_mobile_country_code mirrors
                             mobile_country_code's own hidden-input shape 1:1. -->
                        <div class="col-sm-6" id="rc_mobile_no_wrap">
                            <label class="form-label mb-0" data-i18n="mobile_no">Mobile No.</label>
                            <input type="tel" class="form-control" name="mobile_no" id="rc_mobile_no" maxlength="15" data-i18n="phone_no_placeholder" placeholder="e.g., 0812345678">
                            <input type="hidden" name="mobile_country_code" id="rc_mobile_country_code" value="+66">
                        </div>
                    </div>
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">4</label>
                        <i class="fa-solid fa-building-user text-secondary mx-1"></i>
                        <span data-i18n="employment">Employment</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" name="department_id" id="rc_department_id" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="position">Position</label>
                            <select class="form-select select2-remote" name="position_id" id="rc_position_id" data-api="/api/position.get" data-type="position"></select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" name="branch_id" id="rc_branch_id" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                    <!-- 2026-08-31, explicit request: "ใน Form ตรงที่เป็น...ยังขาด ประเภทการจ้างงาน ด้วยนะครับ" --
                         employment_type was tracked as a required field in EmployeeModel::
                         requiredColumns()/fieldReadiness() already, but had no actual editable input
                         anywhere in this modal to fix it from -- same select2-native shape as Employee
                         Detail's own #employment_type (detail.php). employee_type (foreigner/domestic)
                         stays read-only (#rcEditTypeBadge, unchanged) -- only employment_type
                         (full-time/part-time/daily/internship) was missing, a different field. -->
                    <div class="row mb-4">
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="employment_date">Employment Date</label>
                            <input type="text" class="form-control datepicker" name="employment_date" id="rc_employment_date" autocomplete="off">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="employment_type">Employment Type</label>
                            <select class="form-select select2-native" name="employment_type" id="rc_employment_type">
                                <option value="" data-i18n="please_choose">Select an option</option>
                                <option value="full_time" data-i18n="full_time">Full-time</option>
                                <option value="part_time" data-i18n="part_time">Part-time</option>
                                <option value="daily" data-i18n="daily">Daily wage</option>
                                <option value="internship" data-i18n="internship">Internship</option>
                            </select>
                        </div>
                    </div>
                    <!-- 2026-08-30, explicit request: "เพิ่มส่วนของ...ประเภทการจ่ายเงินเดือนให้เลือกด้วยครับ" --
                         payment_type upgraded from a read-only badge (T019's original "changing this
                         is a bigger structural edit left to the full Detail page" call) to a real,
                         editable field, since the user explicitly asked for it selectable here.
                         employee_type stays read-only (#rcEditTypeBadge, unchanged) -- only payment_type
                         was named in this request.
                         2026-09-02, follow-up: the underlying payment_type enum (bank/cash only) was
                         replaced by payment_method_id (master_payment_methods: transfer/cash/check/
                         mixed, see EmployeePaymentMethodModel) -- this quick modal deliberately keeps
                         its ORIGINAL transfer-vs-cash-only scope (mixed-line configuration needs the
                         full line-item editor this modal never had; check was never offered here
                         either) rather than growing new scope of its own. The radio pair now writes
                         the resolved method's real id (via rcPaymentMethodIdByCode, populated once
                         from /api/payment-method.options) into the hidden #rc_payment_method_id input.
                         An employee already on check/mixed gets the radios disabled with an inline
                         note pointing at the full Employee Detail page instead of silently
                         reinterpreting their method as plain bank/cash. -->
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">5</label>
                        <i class="fa-solid fa-building-columns text-secondary mx-1"></i>
                        <span data-i18n="payment_information">Payment Information</span>
                    </h6>
                    <!-- 2026-08-31, explicit request: "ตรง ตรวจสอบข้อมูล Form ในหน้าตรวจสอบ ประเภทการจ่ายเงิน
                         ให้เปลี่ยนเป็น radio ครับ" -- matches Employee Detail's own payment_type_radio
                         btn-check pair + hidden mirror input exactly (see detail.php's own Payment
                         Information section). -->
                    <div class="row mb-3">
                        <div class="col-sm-6">
                            <label class="form-label mb-0" data-i18n="payment_type">Payment Type</label>
                            <div class="btn-group d-block" role="group" aria-label="Payment type" id="rcPaymentTypeRadioGroup">
                                <input type="radio" class="btn-check" name="rc_payment_type_radio" id="rc_payment_bank" value="transfer" checked>
                                <label class="btn btn-outline-brand" for="rc_payment_bank" data-i18n="bank">Bank</label>
                                <input type="radio" class="btn-check" name="rc_payment_type_radio" id="rc_payment_cash" value="cash">
                                <label class="btn btn-outline-brand" for="rc_payment_cash" data-i18n="cash">Cash</label>
                            </div>
                            <div class="form-text text-warning d-none" id="rcPaymentMethodOtherNote" data-i18n="recheck_payment_method_edit_in_profile">This employee uses Check/Mixed payment -- edit it from the full Employee Detail page.</div>
                            <input type="hidden" name="payment_method_id" id="rc_payment_type" value="">
                        </div>
                    </div>
                    <div id="rcPaymentSectionWrap">
                        <!-- 2026-08-30: d-none moved to the PARENT #rcPaymentSectionWrap (hides the
                             bank fields, see rcApplyIdentificationAndBankVisibility()). -->
                        <div class="row mb-4" id="rcBankWrap">
                            <div class="col-sm-6">
                                <label class="form-label mb-0" data-i18n="bank_name">Bank</label>
                                <select class="form-select select2-remote" name="bank_id" id="rc_bank_id" data-api="/api/bank.get" data-type="bank"></select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label mb-0" data-i18n="bank_account_no">Bank Account No.</label>
                                <input type="text" class="form-control" name="bank_account_no" id="rc_bank_account_no" data-i18n="destination_account_no_placeholder" placeholder="e.g., 1234567890">
                            </div>
                        </div>
                    </div>
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">6</label>
                        <i class="fa-solid fa-file-invoice-dollar text-secondary mx-1"></i>
                        <span data-i18n="salary">Salary</span>
                    </h6>
                    <div class="row mb-4">
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="base_salary_amount">Base Salary Amount</label>
                            <input type="number" step="0.01" class="form-control text-end" name="base_salary_amount" id="rc_base_salary_amount" data-i18n="base_salary_amount_placeholder" placeholder="e.g., 30000">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="effective_date">Effective Date</label>
                            <input type="text" class="form-control datepicker" name="salary_effective_date" id="rc_salary_effective_date" autocomplete="off">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="tax_calculation_method">Tax Calculation Method</label>
                            <select class="form-select select2-native" name="tax_calculation_method" id="rc_tax_calculation_method">
                                <option value="" data-i18n="please_choose">Select an option</option>
                                <option value="average" data-i18n="average_method">Average</option>
                                <option value="actual" data-i18n="actual_method">Actual</option>
                            </select>
                        </div>
                    </div>
                    <!-- 2026-08-30, same-day follow-up: "รวมถึง Form ในหน้าตรวจสอบด้วยครับ" -- reversed
                         the earlier "kept simple" call; the full per-OT-type override table now lives
                         here too, same shape/behavior as Employee Detail's own Salary-tab OT section
                         (radio source, table shown only for Custom, every row pre-filled from the
                         this employee's own resolved OT Rate Set default when there's no override yet).
                         Saved via its own separate api/employee.ot-rate.save call, folded into this
                         form's own submit handler (Promise.all, one combined message) -- see
                         list.js's own submit handler for #employeeRecheckEditForm. -->
                    <h6 class="text-secondary fw-bold mb-3">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">7</label>
                        <i class="fa-solid fa-clock text-secondary mx-1"></i>
                        <span data-i18n="ot_rate_settings">OT Rate Settings</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-4">
                            <label class="form-label mb-0 d-block"><span data-i18n="ot_eligible">OT Eligible</span></label>
                            <input type="checkbox" class="me-2" name="ot_eligible" id="rc_ot_eligible" value="1"><span data-i18n="eligible_for_overtime">Eligible for overtime pay</span>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="rcOtRateSourceWrap">
                        <div class="col-sm-6">
                            <label class="form-label mb-1 d-block"><span data-i18n="ot_rate_source">OT Rate Source</span></label>
                            <div class="btn-group d-block" role="group">
                                <input type="radio" class="btn-check" name="rc_ot_rate_source_radio" id="rc_ot_rate_source_default" value="default" checked>
                                <label class="btn btn-outline-brand" for="rc_ot_rate_source_default" data-i18n="ot_rate_source_default">Use Company Default</label>
                                <input type="radio" class="btn-check" name="rc_ot_rate_source_radio" id="rc_ot_rate_source_custom" value="custom">
                                <label class="btn btn-outline-brand" for="rc_ot_rate_source_custom" data-i18n="ot_rate_source_custom">Set Individually per OT Type</label>
                            </div>
                        </div>
                    </div>
                    <div id="rcOtRateOverridesContainer" class="d-none">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th data-i18n="ot_scope">OT Type</th>
                                        <th data-i18n="calculation_method">Calculation Method</th>
                                        <th data-i18n="rate">Rate</th>
                                        <th data-i18n="calculation_base">Base</th>
                                    </tr>
                                </thead>
                                <tbody id="rcOtRateOverridesBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <!-- 2026-08-31, explicit request: "ตรงหน้าตรวจสอบเหมือนยังขาด ประกันสังคม ทั้งตารางและหน้า
                         Form" -- same shape/behavior as Employee Detail's own Social Security Fund
                         section (detail.php's social-pane), simplified to this modal's own plain-
                         checkbox convention (matching #rc_ot_eligible right above) instead of Employee
                         Detail's fancier Yes/No button toggle -- detail fields shown only while
                         Enrolled is checked. -->
                    <h6 class="text-secondary fw-bold mb-3 mt-4">
                        <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">8</label>
                        <i class="fa-solid fa-shield-halved text-secondary mx-1"></i>
                        <span data-i18n="social_security_fund">Social Security Fund (SSO)</span>
                    </h6>
                    <div class="row mb-3">
                        <div class="col-sm-4">
                            <label class="form-label mb-0 d-block"><span data-i18n="enrolled_in_sso">Enrolled in Social Security Fund</span></label>
                            <input type="checkbox" class="me-2" name="sso_enrolled" id="rc_sso_enrolled" value="1"><span data-i18n="yes">Yes</span>
                        </div>
                    </div>
                    <div class="row mb-3 d-none" id="rcSsoDetailWrap">
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="sso_no">Social Security No.</label>
                            <input type="text" class="form-control" name="sso_no" id="rc_sso_no" maxlength="13" data-i18n="sso_no_placeholder" placeholder="13-digit social security number">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label mb-0" data-i18n="sso_start_date">SSO Start Date</label>
                            <input type="text" class="form-control datepicker" name="sso_start_date" id="rc_sso_start_date" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <!-- 2026-08-30, explicit request: "เพิ่มปุ่มใน Form ตรวจสอบข้อมูล ให้กดแล้วไปหน้า Profile
                         พนักงานคนนั้นเพื่อเข้าไปแก้ไขแบบเต็มได้เลย" -- opens in a NEW tab so the admin
                         doesn't lose their place (current filter/scroll position) on the List page's
                         own Recheck tab underneath. href set fresh each time the modal opens (see
                         list.js's own .btn-recheck-edit click handler). -->
                    <a href="#" class="btn btn-outline-secondary px-3" id="rcGoToFullProfileBtn" target="_blank" rel="noopener">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i><span data-i18n="go_to_full_profile">Go to Full Profile</span>
                    </a>
                    <div>
                        <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
