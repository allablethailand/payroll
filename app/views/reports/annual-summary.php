<div class="container container-body">
    <?php
    $title = 'Annual Income & Tax Summary';
    $title_i18n = 'annual_income_summary';
    $breadcrumb = [
        ['label' => 'Payroll', 'href' => BASE_URL . '/dashboard', 'i18n' => 'payroll'],
        ['label' => 'Reports', 'href' => BASE_URL . '/reports', 'i18n' => 'reports'],
    ];
    $description = 'Each employee\'s income, deductions, and withholding tax, month by month across a fiscal year.';
    $description_i18n = 'annual_income_summary_description';
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs mb-4" id="aisTopTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active setup-menu" id="ais-income-tab" data-bs-toggle="tab" data-bs-target="#ais-income-pane" type="button" role="tab" aria-controls="ais-income-pane" aria-selected="true"><span data-i18n="ais_tab_income">Annual Income Summary</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="ais-pit-tab" data-bs-toggle="tab" data-bs-target="#ais-pit-pane" type="button" role="tab" aria-controls="ais-pit-pane" aria-selected="false"><span data-i18n="ais_tab_pit_annual">Annual Withholding Tax Summary</span></button>
        </li>
        <!-- Batch 2, item 6 (2026-09-10) -- positioned right after the tax tab, same structure. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="ais-sso-tab" data-bs-toggle="tab" data-bs-target="#ais-sso-pane" type="button" role="tab" aria-controls="ais-sso-pane" aria-selected="false"><span data-i18n="ais_tab_sso_annual">Annual SSO Contribution Summary</span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="ais-monthly-pit-tab" data-bs-toggle="tab" data-bs-target="#ais-monthly-pit-pane" type="button" role="tab" aria-controls="ais-monthly-pit-pane" aria-selected="false"><span data-i18n="ais_tab_pit_monthly">Monthly Withholding Tax</span></button>
        </li>
    </ul>
    <div class="tab-content" id="aisTopTabsContent">
    <!-- ==================== Tab 1: Annual Income Summary (unchanged content) ==================== -->
    <div class="tab-pane fade show active" id="ais-income-pane" role="tabpanel" aria-labelledby="ais-income-tab" tabindex="0">
        <!-- 2026-08-29, explicit follow-up: "ไม่ต้องมีปุ่ม search เลือก filter แล้ว Reload เลย" -- no Apply
             button; every filter reloads immediately on change (see annual-summary.js). Free-text
             search is DataTable's own built-in search box (in the table card below) instead of a
             separate input here, for the same reason -- it has no button either. -->
        <div class="row g-3 mb-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFiscalYear" data-i18n="fiscal_year">Fiscal Year</label>
                <select class="form-select" id="aisFiscalYear"></select>
            </div>
        </div>
        <?php
        ob_start(); ?>
        <div class="row g-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFilterCycle" data-i18n="payroll_cycle">Payroll Cycle</label>
                <select class="form-select select2-remote" id="aisFilterCycle" data-api="/api/payroll-cycle.options"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFilterDepartment" data-i18n="department">Department</label>
                <select class="form-select select2-remote" id="aisFilterDepartment" data-api="/api/department.get" data-type="department"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFilterTeam" data-i18n="team">Team</label>
                <select class="form-select select2-remote" id="aisFilterTeam" data-api="/api/team.get" data-type="team"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFilterBranch" data-i18n="branch">Branch</label>
                <select class="form-select select2-remote" id="aisFilterBranch" data-api="/api/branch.get" data-type="branch"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFilterRole" data-i18n="role">Role</label>
                <select class="form-select select2-remote" id="aisFilterRole" data-api="/api/role.get" data-type="role"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFilterStatus" data-i18n="status">Status</label>
                <select class="form-select" id="aisFilterStatus" data-option-keys="status_all,status_active,status_probation,status_resigned,status_terminated" data-option-values="all,active,probation,resigned,terminated"></select>
            </div>
        </div>
        <?php
        $filter_fields_html = ob_get_clean();
        $id = 'aisFilterBar';
        include __DIR__ . '/../partials/filter-bar.php';
        ?>

        <div class="row g-3 mb-4" id="aisSummaryCards">
            <div class="col-md-3">
                <div class="stat-card stat-card-info h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_employees">Employees</div>
                        <div class="stat-card-value" id="aisSummaryEmployeeCount">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-primary h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_gross_income">Total Gross Income</div>
                        <div class="stat-card-value" id="aisSummaryGross">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-minus"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_deductions">Total Deductions</div>
                        <div class="stat-card-value" id="aisSummaryDeduction">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-success h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-coins"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_net_income">Total Net Income</div>
                        <div class="stat-card-value" id="aisSummaryNet">-</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2026-09-08, explicit request: "card-surface p-0 mb-5 ไม่เอาครับ ให้แสดงตารางเลย" -- was
             boxed in a `.card-surface` panel (same as every other page's own card treatment); now the
             table (and its legend/empty-state) sit directly in the tab pane, matching Employee
             Recheck Data's own table, which has never used a card wrapper. `mb-5` moved onto the last
             element here (`.ais-table-wrap`) so the spacing before the next section is unchanged. -->
        <div class="ais-legend px-3 pt-3">
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-past_done"></span><span data-i18n="ais_legend_past_done">Processed</span></span>
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-past_missing"></span><span data-i18n="ais_legend_past_missing">Past, not processed</span></span>
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-current"></span><span data-i18n="ais_legend_current">Current month</span></span>
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-future"></span><span data-i18n="ais_legend_future">Upcoming</span></span>
        </div>
        <!-- 2026-09-12, Batch 5 item 6 -- income/deduction display toggle (Tab 1 only). "ทั้งหมด" has
             no state of its own on the server/JS side either -- it's purely derived from the other 2
             checkboxes, see applyAisColumnDisplayToggle()/the checkbox change handlers in
             annual-summary.js. Purely a client-side CSS class toggle -- no reload. Plain Bootstrap
             utility classes here (d-flex/gap/text-secondary/small/fw-semibold) -- no new CSS class,
             per "ไม่แตะ style" for a logic-only task. -->
        <div class="d-flex align-items-center flex-wrap gap-3 px-3 pt-2">
            <span class="text-secondary small fw-semibold" data-i18n="ais_display_columns_label">Show:</span>
            <div class="form-check form-check-inline mb-0">
                <input class="form-check-input" type="checkbox" id="aisShowAll" checked>
                <label class="form-check-label" for="aisShowAll" data-i18n="filter_all">All</label>
            </div>
            <div class="form-check form-check-inline mb-0">
                <input class="form-check-input" type="checkbox" id="aisShowIncome" checked>
                <label class="form-check-label" for="aisShowIncome" data-i18n="breakdown_earnings">Income</label>
            </div>
            <div class="form-check form-check-inline mb-0">
                <input class="form-check-input" type="checkbox" id="aisShowDeduction" checked>
                <label class="form-check-label" for="aisShowDeduction" data-i18n="table_deduction_amount">Deductions</label>
            </div>
        </div>
        <div id="aisTableEmpty" class="text-center text-secondary py-5 d-none">
            <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="ais_no_data">No payroll data found for this fiscal year.</span>
        </div>
        <!-- 2026-09-08, explicit follow-up: "เอา p-3 ออกครับ ความกว้างตารางไม่ตรงกับ header" -- `p-3` (left/
             right padding on top of whatever the page's own container already provides) was insetting
             this wrapper an extra layer beyond the legend/filter/stat-card rows above it, which don't
             have that same extra inset -- dropped, `pt-2` (top spacing only) stays. -->
        <div class="ais-table-wrap pt-2 mb-5">
            <table class="ais-table table table-hover w-100" id="tb_annual_summary">
                <thead></thead>
                <tfoot></tfoot>
            </table>
        </div>
    </div>

    <!-- ==================== Tab 2: Annual Withholding Tax (PIT) Summary (Phase 4, T027) ==================== -->
    <div class="tab-pane fade" id="ais-pit-pane" role="tabpanel" aria-labelledby="ais-pit-tab" tabindex="0">
        <div class="row g-3 mb-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisPitFiscalYear" data-i18n="fiscal_year">Fiscal Year</label>
                <select class="form-select" id="aisPitFiscalYear"></select>
            </div>
        </div>
        <?php
        ob_start(); ?>
        <div class="row g-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisPitFilterCycle" data-i18n="payroll_cycle">Payroll Cycle</label>
                <select class="form-select select2-remote" id="aisPitFilterCycle" data-api="/api/payroll-cycle.options"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisPitFilterDepartment" data-i18n="department">Department</label>
                <select class="form-select select2-remote" id="aisPitFilterDepartment" data-api="/api/department.get" data-type="department"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisPitFilterTeam" data-i18n="team">Team</label>
                <select class="form-select select2-remote" id="aisPitFilterTeam" data-api="/api/team.get" data-type="team"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisPitFilterBranch" data-i18n="branch">Branch</label>
                <select class="form-select select2-remote" id="aisPitFilterBranch" data-api="/api/branch.get" data-type="branch"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisPitFilterRole" data-i18n="role">Role</label>
                <select class="form-select select2-remote" id="aisPitFilterRole" data-api="/api/role.get" data-type="role"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisPitFilterStatus" data-i18n="status">Status</label>
                <select class="form-select" id="aisPitFilterStatus" data-option-keys="status_all,status_active,status_probation,status_resigned,status_terminated" data-option-values="all,active,probation,resigned,terminated"></select>
            </div>
        </div>
        <?php
        $filter_fields_html = ob_get_clean();
        $id = 'aisPitFilterBar';
        include __DIR__ . '/../partials/filter-bar.php';
        ?>

        <!-- 2026-09-08, explicit request: "card summary ให้เป็น 4 card ทั้งหมดครับ ถ้ามีไม่ถึงก็แสดงตามนั้น" --
             was `col-md-4` (a 3-card-wide grid basis) even though this tab only ever has 2 cards --
             switched to `col-md-3`, the SAME 4-card-wide basis Tab 1's #aisSummaryCards and Tab 3's
             #aisMonthlySummaryCards already use, so all 3 tabs' summary rows share one consistent
             grid regardless of how many cards a given tab actually has. -->
        <div class="row g-3 mb-4" id="aisPitSummaryCards">
            <div class="col-md-3">
                <div class="stat-card stat-card-info h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_employees">Employees</div>
                        <div class="stat-card-value" id="aisPitSummaryEmployeeCount">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="ais_total_tax_withheld">Total Tax Withheld</div>
                        <div class="stat-card-value" id="aisPitSummaryTotal">-</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2026-09-08, same "no card wrapper" request as Tab 1 above. -->
        <div id="aisPitTableEmpty" class="text-center text-secondary py-5 d-none">
            <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="ais_no_data">No payroll data found for this fiscal year.</span>
        </div>
        <div class="ais-table-wrap pt-2 mb-5">
            <table class="ais-table table table-hover w-100" id="tb_ais_pit">
                <thead></thead>
                <tfoot></tfoot>
            </table>
        </div>
    </div>

    <!-- ==================== Tab: Annual SSO Contribution Summary (Batch 2, item 6, 2026-09-10) ====================
         Direct structural mirror of the Annual Withholding Tax Summary tab above -- same fiscal-year
         filter set, same 2-stat-card row, same plain (no export) table with sticky columns. -->
    <div class="tab-pane fade" id="ais-sso-pane" role="tabpanel" aria-labelledby="ais-sso-tab" tabindex="0">
        <div class="row g-3 mb-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisSsoFiscalYear" data-i18n="fiscal_year">Fiscal Year</label>
                <select class="form-select" id="aisSsoFiscalYear"></select>
            </div>
        </div>
        <?php
        ob_start(); ?>
        <div class="row g-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisSsoFilterCycle" data-i18n="payroll_cycle">Payroll Cycle</label>
                <select class="form-select select2-remote" id="aisSsoFilterCycle" data-api="/api/payroll-cycle.options"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisSsoFilterDepartment" data-i18n="department">Department</label>
                <select class="form-select select2-remote" id="aisSsoFilterDepartment" data-api="/api/department.get" data-type="department"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisSsoFilterTeam" data-i18n="team">Team</label>
                <select class="form-select select2-remote" id="aisSsoFilterTeam" data-api="/api/team.get" data-type="team"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisSsoFilterBranch" data-i18n="branch">Branch</label>
                <select class="form-select select2-remote" id="aisSsoFilterBranch" data-api="/api/branch.get" data-type="branch"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisSsoFilterRole" data-i18n="role">Role</label>
                <select class="form-select select2-remote" id="aisSsoFilterRole" data-api="/api/role.get" data-type="role"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisSsoFilterStatus" data-i18n="status">Status</label>
                <select class="form-select" id="aisSsoFilterStatus" data-option-keys="status_all,status_active,status_probation,status_resigned,status_terminated" data-option-values="all,active,probation,resigned,terminated"></select>
            </div>
        </div>
        <?php
        $filter_fields_html = ob_get_clean();
        $id = 'aisSsoFilterBar';
        include __DIR__ . '/../partials/filter-bar.php';
        ?>

        <div class="row g-3 mb-4" id="aisSsoSummaryCards">
            <div class="col-md-3">
                <div class="stat-card stat-card-info h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_employees">Employees</div>
                        <div class="stat-card-value" id="aisSsoSummaryEmployeeCount">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-shield-heart"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="ais_total_sso_withheld">Total SSO Contribution</div>
                        <div class="stat-card-value" id="aisSsoSummaryTotal">-</div>
                    </div>
                </div>
            </div>
        </div>

        <div id="aisSsoTableEmpty" class="text-center text-secondary py-5 d-none">
            <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="ais_no_data">No payroll data found for this fiscal year.</span>
        </div>
        <div class="ais-table-wrap pt-2 mb-5">
            <table class="ais-table table table-hover w-100" id="tb_ais_sso">
                <thead></thead>
                <tfoot></tfoot>
            </table>
        </div>
    </div>

    <!-- ==================== Tab 3: Monthly Withholding Tax (Phase 4, T026) ==================== -->
    <div class="tab-pane fade" id="ais-monthly-pit-pane" role="tabpanel" aria-labelledby="ais-monthly-pit-tab" tabindex="0">
        <!-- Plain calendar year+month picker, deliberately NOT the fiscal-year abstraction the other
             2 tabs use -- see AnnualIncomeSummaryModel::monthlyPitDetail()'s own docblock. -->
        <div class="row g-3 mb-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyYear" data-i18n="year">Year</label>
                <select class="form-select" id="aisMonthlyYear"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyMonth" data-i18n="month">Month</label>
                <select class="form-select select2-static" id="aisMonthlyMonth" data-option-keys="month_1,month_2,month_3,month_4,month_5,month_6,month_7,month_8,month_9,month_10,month_11,month_12" data-option-values="1,2,3,4,5,6,7,8,9,10,11,12"></select>
            </div>
        </div>
        <?php
        ob_start(); ?>
        <div class="row g-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyFilterCycle" data-i18n="payroll_cycle">Payroll Cycle</label>
                <select class="form-select select2-remote" id="aisMonthlyFilterCycle" data-api="/api/payroll-cycle.options"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyFilterDepartment" data-i18n="department">Department</label>
                <select class="form-select select2-remote" id="aisMonthlyFilterDepartment" data-api="/api/department.get" data-type="department"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyFilterTeam" data-i18n="team">Team</label>
                <select class="form-select select2-remote" id="aisMonthlyFilterTeam" data-api="/api/team.get" data-type="team"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyFilterBranch" data-i18n="branch">Branch</label>
                <select class="form-select select2-remote" id="aisMonthlyFilterBranch" data-api="/api/branch.get" data-type="branch"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyFilterRole" data-i18n="role">Role</label>
                <select class="form-select select2-remote" id="aisMonthlyFilterRole" data-api="/api/role.get" data-type="role"></select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisMonthlyFilterStatus" data-i18n="status">Status</label>
                <select class="form-select" id="aisMonthlyFilterStatus" data-option-keys="status_all,status_active,status_probation,status_resigned,status_terminated" data-option-values="all,active,probation,resigned,terminated"></select>
            </div>
        </div>
        <?php
        $filter_fields_html = ob_get_clean();
        $id = 'aisMonthlyFilterBar';
        include __DIR__ . '/../partials/filter-bar.php';
        ?>

        <div class="row g-3 mb-4" id="aisMonthlySummaryCards">
            <div class="col-md-3">
                <div class="stat-card stat-card-info h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_employees">Employees</div>
                        <div class="stat-card-value" id="aisMonthlySummaryEmployeeCount">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-primary h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_gross_income">Total Gross Income</div>
                        <div class="stat-card-value" id="aisMonthlySummaryGross">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-minus"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_deductions">Total Deductions</div>
                        <div class="stat-card-value" id="aisMonthlySummaryDeduction">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card stat-card-success h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="ais_total_tax_withheld">Total Tax Withheld</div>
                        <div class="stat-card-value" id="aisMonthlySummaryTax">-</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2026-09-08, same "no card wrapper" request as Tab 1/2 above -- `mb-5` moved onto
             initTableDragScroll()'s own dynamically-created wrapper (JS, 'pt-2 mb-5' param) to
             keep the same spacing before whatever follows. `p-3` (all-sides padding) was dropped
             same-day, same reason as Tab 1/2's own `.ais-table-wrap` above: the extra left/right
             inset it added (beyond what the page's own container already provides) misaligned the
             table against the filter/stat-card rows above it, which don't have that same extra
             inset ("ความกว้างตารางไม่ตรงกับ header").
             This table's own STATIC `.table-responsive` wrapper (used to sit right here in the view)
             had the exact same real bug already found and fixed on Employee Recheck Data: wrapping
             the table BEFORE DataTables initializes on it makes DataTables build its own length/
             search/info/pagination controls as siblings of the table WITHIN that same wrapper,
             scrolling them along with the columns instead of keeping them fixed above/below.
             public/js/reports/annual-summary.js's own aisRenderMonthlyTable() now wraps this table
             itself, from `initComplete` (fires once, after those controls already exist) via the
             shared initTableDragScroll() helper -- see that function's own docblock. -->
        <div id="aisMonthlyTableEmpty" class="text-center text-secondary py-5 d-none">
            <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="ais_no_data_month">No payroll data found for this month.</span>
        </div>
        <table class="table table-striped table-hover w-100" id="tb_ais_monthly">
            <thead class="table-light text-secondary">
                <tr>
                    <!-- 2026-09-08, explicit follow-up request: "แยก code กับ ชื่อพนักงานเป็นคนละ column" --
                         same split as Tab 1/2's own JS-built headers. -->
                    <th data-i18n="employee_no">Employee No.</th>
                    <th data-i18n="employee">Employee</th>
                    <th data-i18n="department">Department</th>
                    <th data-i18n="team">Team</th>
                    <th data-i18n="position">Position</th>
                    <th data-i18n="total_gross_income">Total Gross Income</th>
                    <th data-i18n="total_deductions">Total Deductions</th>
                    <th data-i18n="total_net_income">Total Net Income</th>
                    <th data-i18n="ais_total_tax_withheld">Total Tax Withheld</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    </div>
</div>
<!-- 2026-09-08: the 2 FixedColumns extension scripts that used to load here (moved in 2026-08-31 from
     the global footer.php, since they threw a top-level error on EVERY page otherwise) are gone --
     annual-summary.js's own `fixedColumns: {...}` option, the only real consumer, is gone too (see
     that file's own comment on aisTable's DataTable init for why: confirmed the extension itself
     never actually worked at all, on this page or anywhere else in this app -- `DataTable.Dom` is
     undefined in the installed `datatables.net` core build it depends on). Sticky columns are now
     plain CSS via public/js/sticky-table-columns.js (loaded globally, header.php). -->
<script src="<?=asset('public/js/reports/annual-summary.js')?>"></script>
