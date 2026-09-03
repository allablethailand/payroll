<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="employee_reports">Reports</span>
        </h5>
    </nav>
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon">
            <i class="fa-solid fa-chart-column"></i>
        </div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="employee_reports">Reports</h5>
            <p class="page-header-card-desc small" data-i18n="employee_reports_description">Headcount movement, expiry alerts, probation status, statutory enrollment, structure, tenure, birthdays, and data completeness -- one report per tab.</p>
        </div>
    </div>

    <!-- 2026-09-02, 3-way Employee submenu split -- this used to be the "Reports" tab-pane inside
         /payroll/employees (built across Phases 0-4 of the Employee Reports plan, see each
         EmployeeModel report method's own docblock for backend design); now its own standalone page
         at /payroll/employees/reports under the Employee submenu (see header.php). The 9-report
         .structure-tabs-wrap sub-nav below and every sub-tab pane are unchanged -- pure
         re-parenting, only the outer page shell around them changed.
    -->
        <!-- 2026-09-02, Phase 0 of the Employee Reports plan -- sub-tab shell, same
             .structure-tabs-wrap pill convention Organizational Structure/Bank Accounts already use
             for "sub-tab inside one page" (per this app's own Tab convention doc). Only one real
             sub-tab exists so far (the pre-existing Standing Items Summary content, moved in as-is,
             zero behavior change) -- later phases add a sibling `<button>`/`<div class="tab-pane">`
             pair here per new report, this wrapper itself doesn't change shape. -->
        <div class="bg-light rounded-3 p-2 mb-3 structure-tabs-wrap">
            <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" id="employeeReportsSubTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu active" id="empReportSub-standing-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-standing-pane" type="button" role="tab" aria-controls="empReportSub-standing-pane" aria-selected="true">
                        <i class="fa-solid fa-calculator me-1"></i><span data-i18n="standing_items_summary">Standing Items Summary</span>
                    </button>
                </li>
                <!-- 2026-09-02, Phase 1 of the Employee Reports plan -- "Report คนเข้าคนออกประจำเดือน
                     ประจำปี", the report named explicitly. See EmployeeModel::headcountMovementReport()'s
                     own docblock for the backend design. -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-headcount-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-headcount-pane" type="button" role="tab" aria-controls="empReportSub-headcount-pane" aria-selected="false">
                        <i class="fa-solid fa-people-arrows me-1"></i><span data-i18n="headcount_movement_report">Headcount Movement</span>
                    </button>
                </li>
                <!-- 2026-09-02, Phase 2 of the Employee Reports plan -- 3 quick-win reports, data
                     already sat unused since the 2026-09-02 sync field batch. See each
                     EmployeeModel method's own docblock for the backend design. -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-expiry-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-expiry-pane" type="button" role="tab" aria-controls="empReportSub-expiry-pane" aria-selected="false">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i><span data-i18n="expiry_report">Expiry Alerts</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-probation-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-probation-pane" type="button" role="tab" aria-controls="empReportSub-probation-pane" aria-selected="false">
                        <i class="fa-solid fa-hourglass-half me-1"></i><span data-i18n="probation_report">Probation Status</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-enrollment-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-enrollment-pane" type="button" role="tab" aria-controls="empReportSub-enrollment-pane" aria-selected="false">
                        <i class="fa-solid fa-shield-heart me-1"></i><span data-i18n="statutory_enrollment_report">SSO/PVD Enrollment</span>
                    </button>
                </li>
                <!-- 2026-09-02, Phase 3 of the Employee Reports plan -- structural/analytical
                     reports. See each EmployeeModel method's own docblock for the backend design. -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-structure-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-structure-pane" type="button" role="tab" aria-controls="empReportSub-structure-pane" aria-selected="false">
                        <i class="fa-solid fa-sitemap me-1"></i><span data-i18n="headcount_structure_report">Headcount Structure</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-tenure-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-tenure-pane" type="button" role="tab" aria-controls="empReportSub-tenure-pane" aria-selected="false">
                        <i class="fa-solid fa-award me-1"></i><span data-i18n="tenure_report">Tenure</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-birthday-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-birthday-pane" type="button" role="tab" aria-controls="empReportSub-birthday-pane" aria-selected="false">
                        <i class="fa-solid fa-cake-candles me-1"></i><span data-i18n="birthday_anniversary_report">Birthday &amp; Anniversary</span>
                    </button>
                </li>
                <!-- 2026-09-02, Phase 4 (the final phase) of the Employee Reports plan -- company-wide
                     data completeness overview, aggregating the SAME per-employee % already shown on
                     the List/Recheck tabs, see EmployeeModel::completenessOverviewReport()'s own
                     docblock. -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="empReportSub-completeness-tab" data-bs-toggle="tab" data-bs-target="#empReportSub-completeness-pane" type="button" role="tab" aria-controls="empReportSub-completeness-pane" aria-selected="false">
                        <i class="fa-solid fa-clipboard-check me-1"></i><span data-i18n="completeness_overview_report">Data Completeness</span>
                    </button>
                </li>
            </ul>
        </div>
        <div class="tab-content" id="employeeReportsSubTabsContent">
        <div class="tab-pane fade show active" id="empReportSub-standing-pane" role="tabpanel" aria-labelledby="empReportSub-standing-tab" tabindex="0">
        <div class="station-filter" id="employeeSummaryStationFilter">
            <span class="station-filter-label" data-i18n="label_filter">Filter</span>
            <button type="button" class="station-filter-toggle" id="employeeSummaryStationFilterToggle" title="Toggle filter">
                <i class="fas fa-chevron-up"></i>
            </button>
            <div class="station-filter-body">
                <div class="row g-2">
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="role">Role</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_role" data-api="/api/role.get" data-type="role"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="department">Department</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_department" data-api="/api/department.get" data-type="department"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="team">Team</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_team" data-api="/api/team.get" data-type="team"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="shift">Shift</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_shift" data-api="/api/shift.options" data-type="shift"></select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label mb-1" data-i18n="branch">Branch</label>
                        <select class="form-select select2-remote" id="employee_summary_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                    </div>
                </div>
            </div>
        </div>
        <div class="station-filter-clear-row d-none" id="employeeSummaryFilterClearRow">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeSummaryFilter">
                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
            </button>
        </div>
        <!-- 2026-09-02, explicit request: "อยากให้มี Card Summary อยู่ที่หัวตารางครับ" -- same
             `.stat-card` shape/colors the Dashboard already established (public/js/dashboard.js's
             own DASH_STATE_COLORS block, `#dashStatRow`), reusing numbers that are ALREADY computed
             server-side and returned on every ajax response (EmployeeModel::standingSummaryList()'s
             own `totals`/`filtered` keys -- renderEmployeeSummaryCards() in list.js just reads them,
             zero new backend query). Updates on every filter change / page navigation, same as the
             tfoot totals right below already do -- both read the SAME response object. -->
        <div class="row g-3 mb-4" id="employeeSummaryStatRow">
            <div class="col-6 col-lg-3">
                <div class="stat-card stat-card-info h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="employee_summary_total_employees">Total Employees</div>
                        <div class="stat-card-value" id="empSummaryCardEmployeeCount">-</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card stat-card-success h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-money-bill-trend-up"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_earning">Total Earning</div>
                        <div class="stat-card-value" id="empSummaryCardTotalEarning">-</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-money-bill-transfer"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_deduction">Total Deduction</div>
                        <div class="stat-card-value" id="empSummaryCardTotalDeduction">-</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card stat-card-primary h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="net_total">Net Total</div>
                        <div class="stat-card-value" id="empSummaryCardNetTotal">-</div>
                    </div>
                </div>
            </div>
        </div>
        <!-- 2026-08-31, explicit request: "ตรงสรุปรายได้ประจำ ตารางไม่เต็ม" -- this table has fewer,
             narrower (mostly right-aligned money) columns than #tb_employee/#tb_employee_recheck, so
             DataTables' own default autoWidth left visible empty space on the right instead of
             stretching to the container -- explicit style="width:100%" forces it to fill, same
             convention already used elsewhere in this app (e.g. setup-rules/index.php's #tb_ot). -->
        <table class="table table-striped table-hover" id="tb_employee_summary" style="width:100%">
            <thead class="table-light text-secondary">
                <tr>
                    <th></th>
                    <!-- 2026-08-31, explicit request: "ตารางพนักงานทุกตาราง แยก code กับชื่อเป็นคนละ Column" --
                         was one "Employee" column with employee_no/name stacked, split into 2. -->
                    <th data-i18n="employee_no">Employee No.</th>
                    <th data-i18n="employee">Employee</th>
                    <th class="text-end" data-i18n="base_salary_amount">Base Salary Amount</th>
                    <th class="text-end" data-i18n="recurring_earnings">Recurring Earnings</th>
                    <th class="text-end" data-i18n="recurring_deductions">Recurring Deductions</th>
                    <th class="text-end" data-i18n="pending_ped_earning">Pending Earning (Installment)</th>
                    <th class="text-end" data-i18n="pending_ped_deduction">Pending Deduction (Installment)</th>
                    <th class="text-end" data-i18n="total_earning">Total Earning</th>
                    <th class="text-end" data-i18n="total_deduction">Total Deduction</th>
                    <th class="text-end" data-i18n="net_total">Net Total</th>
                </tr>
            </thead>
            <tbody></tbody>
            <!-- 2026-09-02, real bug found and fixed (explicit report: "ตาราง Body ไม่เท่า Footer") --
                 root cause: this row used to be EMPTY at init time and get its whole innerHTML
                 replaced via jQuery .html() after every ajax response (renderEmployeeSummaryFooter()
                 in list.js). DataTables scans <tfoot> ONCE at .DataTable({...}) construction time
                 (table().footer.structure(), cached into settings.aoFooter) -- an empty tfoot means
                 DataTables registers NO footer cells at all. The Responsive extension configured on
                 this table (`responsive: {...}` below) hides/shows columns by toggling `display` on
                 the SPECIFIC DOM nodes it captured at that same scan time -- replacing the tfoot's
                 innerHTML afterward destroys those nodes and creates brand-new, untracked ones, so
                 Responsive's hide/show never applies to the footer at all: on a narrow viewport where
                 body columns collapse, the footer kept showing all 11 raw cells regardless, visibly
                 misaligned against however many columns the body was actually showing. Fixed by
                 giving every footer cell a real, permanent DOM node here (so DataTables registers
                 them correctly at init and Responsive tracks/hides them exactly like the matching
                 body columns) -- renderEmployeeSummaryFooter() below now updates each cell's TEXT
                 CONTENT in place via these ids, never replaces the row's own DOM nodes again. -->
            <tfoot>
                <tr>
                    <td></td>
                    <td></td>
                    <td class="fw-bold" data-i18n="total">Total</td>
                    <td class="text-end fw-bold" id="empSummaryFootBaseSalary"></td>
                    <td class="text-end fw-bold" id="empSummaryFootRecurring"></td>
                    <td class="text-end fw-bold" id="empSummaryFootRecurringDeduction"></td>
                    <td class="text-end fw-bold" id="empSummaryFootPedEarning"></td>
                    <td class="text-end fw-bold" id="empSummaryFootPedDeduction"></td>
                    <td class="text-end fw-bold" id="empSummaryFootTotalEarning"></td>
                    <td class="text-end fw-bold" id="empSummaryFootTotalDeduction"></td>
                    <td class="text-end fw-bold" id="empSummaryFootNetTotal"></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <!-- 2026-09-02, Phase 1 of the Employee Reports plan -- "Report คนเข้าคนออกประจำเดือน ประจำปี",
             the report named explicitly. Year-scoped (not a separate month/year MODE -- selecting a
             year shows that whole year's monthly hires/exits trend, which already covers both the
             "monthly" and "annual" framing of the original request in one view). Summary cards +
             chart per explicit follow-up request: "อยากให้เพิ่มให้ด้วย...ไม่อยากให้เป็นตารางโล้นๆ". -->
        <div class="tab-pane fade" id="empReportSub-headcount-pane" role="tabpanel" aria-labelledby="empReportSub-headcount-tab" tabindex="0">
            <div class="station-filter" id="employeeHeadcountStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeHeadcountStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="year">Year</label>
                            <select class="form-select select2-native" id="employee_headcount_filter_year">
                                <?php
                                    // Native-populated (not select2-static's i18n-key lookup -- these
                                    // are plain numbers, nothing to translate) -- last 5 years through
                                    // next year, defaulting to the current year.
                                    $hcmCurrentYear = (int)date('Y');
                                    for ($hcmY = $hcmCurrentYear - 5; $hcmY <= $hcmCurrentYear + 1; $hcmY++):
                                ?>
                                    <option value="<?=$hcmY?>" <?=$hcmY === $hcmCurrentYear ? 'selected' : ''?>><?=$hcmY?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="employee_headcount_filter_department" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" id="employee_headcount_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="employeeHeadcountFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeHeadcountFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="row g-3 mb-4" id="employeeHeadcountStatRow">
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-success h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-user-plus"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="headcount_total_hires">Total Hires</div>
                            <div class="stat-card-value" id="empHeadcountCardHires">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-danger h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-user-minus"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="headcount_total_exits">Total Exits</div>
                            <div class="stat-card-value" id="empHeadcountCardExits">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-info h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="headcount_net_change">Net Change</div>
                            <div class="stat-card-value" id="empHeadcountCardNetChange">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-primary h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-gauge-high"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="headcount_turnover_rate">Turnover Rate</div>
                            <div class="stat-card-value" id="empHeadcountCardTurnoverRate">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="dash-section-card mb-4">
                <div class="dash-section-card-header">
                    <h6 class="mb-0"><i class="fa-solid fa-chart-column me-2 text-warning"></i><span data-i18n="headcount_monthly_trend">Monthly Hires vs. Exits</span></h6>
                </div>
                <div class="dash-chart-wrap">
                    <canvas id="employeeHeadcountChart" height="90"></canvas>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tb_employee_headcount_events" style="width:100%">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="date">Date</th>
                            <th data-i18n="employee_no">Employee No.</th>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="department">Department</th>
                            <th data-i18n="branch">Branch</th>
                            <th data-i18n="headcount_movement_type">Movement</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 2026-09-02, Phase 2 -- Contract/Work Permit/Visa/Passport Expiry Alerts. -->
        <div class="tab-pane fade" id="empReportSub-expiry-pane" role="tabpanel" aria-labelledby="empReportSub-expiry-tab" tabindex="0">
            <div class="station-filter" id="employeeExpiryStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeExpiryStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="expiry_within_days">Expiring Within</label>
                            <select class="form-select select2-static" id="employee_expiry_filter_within_days" data-option-keys="expiry_within_30,expiry_within_60,expiry_within_90" data-option-values="30,60,90"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="employee_expiry_filter_department" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" id="employee_expiry_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="employeeExpiryFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeExpiryFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="row g-3 mb-4" id="employeeExpiryStatRow">
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-warning h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-file-signature"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="expiry_contract">Contract</div>
                            <div class="stat-card-value" id="empExpiryCardContract">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-danger h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-id-card"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="expiry_work_permit">Work Permit</div>
                            <div class="stat-card-value" id="empExpiryCardWorkPermit">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-primary h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-passport"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="expiry_visa">Visa</div>
                            <div class="stat-card-value" id="empExpiryCardVisa">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-purple h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-book"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="expiry_passport">Passport</div>
                            <div class="stat-card-value" id="empExpiryCardPassport">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tb_employee_expiry" style="width:100%">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_no">Employee No.</th>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="department">Department</th>
                            <th data-i18n="branch">Branch</th>
                            <th data-i18n="expiry_type">Type</th>
                            <th data-i18n="expiry_date">Expiry Date</th>
                            <th data-i18n="expiry_days_remaining">Days Remaining</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 2026-09-02, Phase 2 -- Probation Status. No "days until due" column -- confirmed via
             AskUserQuestion, no probation-period-length setting exists anywhere in this app yet, see
             EmployeeModel::probationReport()'s own docblock. -->
        <div class="tab-pane fade" id="empReportSub-probation-pane" role="tabpanel" aria-labelledby="empReportSub-probation-tab" tabindex="0">
            <div class="station-filter" id="employeeProbationStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeProbationStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="employee_probation_filter_department" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" id="employee_probation_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="employeeProbationFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeProbationFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="row g-3 mb-4" id="employeeProbationStatRow">
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-warning h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="probation_total_count">On Probation</div>
                            <div class="stat-card-value" id="empProbationCardCount">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tb_employee_probation" style="width:100%">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_no">Employee No.</th>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="department">Department</th>
                            <th data-i18n="branch">Branch</th>
                            <th data-i18n="start_work_date">Start Date</th>
                            <th data-i18n="probation_days_on">Days on Probation</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 2026-09-02, Phase 2 -- SSO/PVD Enrollment ("which people," distinct from the existing
             statutory SSO 1-10 FORM exports). -->
        <div class="tab-pane fade" id="empReportSub-enrollment-pane" role="tabpanel" aria-labelledby="empReportSub-enrollment-tab" tabindex="0">
            <div class="station-filter" id="employeeEnrollmentStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeEnrollmentStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="employee_enrollment_filter_department" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" id="employee_enrollment_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="employeeEnrollmentFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeEnrollmentFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="row g-3 mb-4" id="employeeEnrollmentStatRow">
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-success h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-shield-heart"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="enrollment_sso_enrolled">SSO Enrolled</div>
                            <div class="stat-card-value" id="empEnrollmentCardSsoEnrolled">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-danger h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-shield-halved"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="enrollment_sso_not_enrolled">SSO Not Enrolled</div>
                            <div class="stat-card-value" id="empEnrollmentCardSsoNotEnrolled">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-info h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-piggy-bank"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="enrollment_pvd_enrolled">PVD Enrolled</div>
                            <div class="stat-card-value" id="empEnrollmentCardPvdEnrolled">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-purple h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-piggy-bank"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="enrollment_pvd_not_enrolled">PVD Not Enrolled</div>
                            <div class="stat-card-value" id="empEnrollmentCardPvdNotEnrolled">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="dash-section-card">
                        <div class="dash-section-card-header">
                            <h6 class="mb-0"><span data-i18n="enrollment_sso_enrolled">SSO Enrolled</span></h6>
                        </div>
                        <div class="dash-chart-wrap" style="height:180px;">
                            <canvas id="employeeEnrollmentSsoChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dash-section-card">
                        <div class="dash-section-card-header">
                            <h6 class="mb-0"><span data-i18n="enrollment_pvd_enrolled">PVD Enrolled</span></h6>
                        </div>
                        <div class="dash-chart-wrap" style="height:180px;">
                            <canvas id="employeeEnrollmentPvdChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tb_employee_enrollment" style="width:100%">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_no">Employee No.</th>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="department">Department</th>
                            <th data-i18n="branch">Branch</th>
                            <th data-i18n="enrollment_sso_status">SSO</th>
                            <th data-i18n="enrollment_pvd_status">PVD</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 2026-09-02, Phase 3 -- Headcount Structure, a current (not date-scoped) snapshot grouped
             by one dimension at a time. -->
        <div class="tab-pane fade" id="empReportSub-structure-pane" role="tabpanel" aria-labelledby="empReportSub-structure-tab" tabindex="0">
            <div class="station-filter" id="employeeStructureStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeStructureStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-3">
                            <label class="form-label mb-1" data-i18n="structure_group_by">Group By</label>
                            <select class="form-select select2-static" id="employee_structure_filter_group_by" data-option-keys="structure_group_department,structure_group_position,structure_group_branch,structure_group_employment_type" data-option-values="department,position,branch,employment_type"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-4" id="employeeStructureStatRow">
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-info h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="structure_total_headcount">Total Headcount</div>
                            <div class="stat-card-value" id="empStructureCardTotal">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-primary h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-layer-group"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="structure_group_count">Number of Groups</div>
                            <div class="stat-card-value" id="empStructureCardGroupCount">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-success h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-crown"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="structure_largest_group">Largest Group</div>
                            <div class="stat-card-value" id="empStructureCardLargest" style="font-size:1.1rem;">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="dash-section-card mb-4">
                <div class="dash-chart-wrap">
                    <canvas id="employeeStructureChart" height="90"></canvas>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tb_employee_structure" style="width:100%">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="structure_group_label">Group</th>
                            <th class="text-end" data-i18n="structure_headcount">Headcount</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 2026-09-02, Phase 3 -- Tenure (อายุงาน), bucketed years-of-service. -->
        <div class="tab-pane fade" id="empReportSub-tenure-pane" role="tabpanel" aria-labelledby="empReportSub-tenure-tab" tabindex="0">
            <div class="station-filter" id="employeeTenureStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeTenureStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="employee_tenure_filter_department" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" id="employee_tenure_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="employeeTenureFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeTenureFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="row g-3 mb-4" id="employeeTenureStatRow">
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-info h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="structure_total_headcount">Total Headcount</div>
                            <div class="stat-card-value" id="empTenureCardTotal">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-primary h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-chart-line"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="tenure_average">Average Tenure</div>
                            <div class="stat-card-value" id="empTenureCardAverage">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-success h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-medal"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="tenure_longest">Longest Tenure</div>
                            <div class="stat-card-value" id="empTenureCardLongest">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="dash-section-card mb-4">
                <div class="dash-chart-wrap">
                    <canvas id="employeeTenureChart" height="90"></canvas>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tb_employee_tenure" style="width:100%">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_no">Employee No.</th>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="department">Department</th>
                            <th data-i18n="branch">Branch</th>
                            <th data-i18n="start_work_date">Start Date</th>
                            <th class="text-end" data-i18n="tenure_years">Tenure (Years)</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- 2026-09-02, Phase 3 -- Birthday & Work Anniversary, a simple monthly reminder list (no
             chart -- adds no decision-making value for this kind of report). -->
        <div class="tab-pane fade" id="empReportSub-birthday-pane" role="tabpanel" aria-labelledby="empReportSub-birthday-tab" tabindex="0">
            <div class="station-filter" id="employeeBirthdayStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeBirthdayStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="month">Month</label>
                            <select class="form-select select2-native" id="employee_birthday_filter_month">
                                <?php
                                    $bdayMonthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                    $bdayCurrentMonth = (int)date('n');
                                    for ($bm = 1; $bm <= 12; $bm++):
                                ?>
                                    <option value="<?=$bm?>" <?=$bm === $bdayCurrentMonth ? 'selected' : ''?>><?=$bdayMonthNames[$bm]?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="employee_birthday_filter_department" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" id="employee_birthday_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="employeeBirthdayFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeBirthdayFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="row g-3 mb-4" id="employeeBirthdayStatRow">
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-warning h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-cake-candles"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="birthday_count">Birthdays</div>
                            <div class="stat-card-value" id="empBirthdayCardCount">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-purple h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-award"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="anniversary_count">Work Anniversaries</div>
                            <div class="stat-card-value" id="empAnniversaryCardCount">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-6">
                    <h6 class="text-secondary fw-bold"><i class="fa-solid fa-cake-candles me-1"></i><span data-i18n="birthday_count">Birthdays</span></h6>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="tb_employee_birthday" style="width:100%">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th data-i18n="employee_no">Employee No.</th>
                                    <th data-i18n="employee">Employee</th>
                                    <th data-i18n="department">Department</th>
                                    <th data-i18n="date">Date</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                    <h6 class="text-secondary fw-bold"><i class="fa-solid fa-award me-1"></i><span data-i18n="anniversary_count">Work Anniversaries</span></h6>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="tb_employee_anniversary" style="width:100%">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th data-i18n="employee_no">Employee No.</th>
                                    <th data-i18n="employee">Employee</th>
                                    <th data-i18n="department">Department</th>
                                    <th data-i18n="date">Date</th>
                                    <th data-i18n="tenure_years">Years</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2026-09-02, Phase 4 (the final phase) -- Data Completeness overview, current snapshot
             only (resigned/terminated excluded, same convention as the other Phase 3/4 reports). -->
        <div class="tab-pane fade" id="empReportSub-completeness-pane" role="tabpanel" aria-labelledby="empReportSub-completeness-tab" tabindex="0">
            <div class="station-filter" id="employeeCompletenessStationFilter">
                <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="employeeCompletenessStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="department">Department</label>
                            <select class="form-select select2-remote" id="employee_completeness_filter_department" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="branch">Branch</label>
                            <select class="form-select select2-remote" id="employee_completeness_filter_branch" data-api="/api/branch.get" data-type="branch"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="employeeCompletenessFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmployeeCompletenessFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="row g-3 mb-4" id="employeeCompletenessStatRow">
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-info h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="structure_total_headcount">Total Headcount</div>
                            <div class="stat-card-value" id="empCompletenessCardTotal">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-primary h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-chart-pie"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="completeness_average">Average Completeness</div>
                            <div class="stat-card-value" id="empCompletenessCardAverage">-</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-4">
                    <div class="stat-card stat-card-danger h-100">
                        <div class="stat-card-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        <div>
                            <div class="stat-card-label" data-i18n="completeness_needs_attention">Needs Attention (&lt;50%)</div>
                            <div class="stat-card-value" id="empCompletenessCardNeedsAttention">-</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="dash-section-card mb-4">
                <div class="dash-chart-wrap">
                    <canvas id="employeeCompletenessChart" height="90"></canvas>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="tb_employee_completeness" style="width:100%">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_no">Employee No.</th>
                            <th data-i18n="employee">Employee</th>
                            <th data-i18n="department">Department</th>
                            <th data-i18n="branch">Branch</th>
                            <th class="text-end" data-i18n="completeness_percent">Completeness</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        </div>
</div>
<!-- 2026-09-02, Phase 1 of the Employee Reports plan -- the Headcount Movement sub-tab's own
     monthly hires/exits chart (and the SSO/PVD Enrollment donut charts). Loaded here, not the
     global footer, same "only the one page that needs it" precedent Chart.js already established
     on the Dashboard (see that view's own comment). -->
<script src="<?=BASE_URL?>/node_modules/chart.js/dist/chart.umd.min.js"></script>
<script src="<?=asset('public/js/employee/reports.js')?>"></script>
