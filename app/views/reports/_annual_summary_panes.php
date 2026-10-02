<?php
/** Annual Summary + Monthly Withholding Tax panes -- included as top tabs of /reports (reports/index.php). Needs $activeTab. */
?>
    <!-- Annual Summary: one filter bar + fiscal year + card row; the metric pill switches which table shows. -->
    <div class="tab-pane fade<?= $activeTab === 'annual' ? ' show active' : '' ?>" id="ais-annual-pane" role="tabpanel" aria-labelledby="ais-annual-tab" tabindex="0">
        <div class="bg-light rounded-3 p-2 mb-3 structure-tabs-wrap">
            <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" id="aisMetricTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu active" id="ais-metric-income-tab" data-ais-metric-tab="income" data-bs-toggle="tab" data-bs-target="#ais-metric-income-pane" type="button" role="tab" aria-controls="ais-metric-income-pane" aria-selected="true"><span data-i18n="ais_metric_income">Income</span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="ais-metric-pit-tab" data-ais-metric-tab="pit" data-bs-toggle="tab" data-bs-target="#ais-metric-pit-pane" type="button" role="tab" aria-controls="ais-metric-pit-pane" aria-selected="false"><span data-i18n="ais_metric_tax">Tax</span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="ais-metric-sso-tab" data-ais-metric-tab="sso" data-bs-toggle="tab" data-bs-target="#ais-metric-sso-pane" type="button" role="tab" aria-controls="ais-metric-sso-pane" aria-selected="false"><span data-i18n="ais_metric_sso">Social Security</span></button>
                </li>
            </ul>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-sm-2">
                <label class="form-label small mb-1" for="aisFiscalYear" data-i18n="fiscal_year">Fiscal Year</label>
                <select class="form-select" id="aisFiscalYear"></select>
            </div>
        </div>
        <?php
        ob_start();
        $ais_prefix = 'aisFilter';
        include __DIR__ . '/_ais_filters.php';
        $filter_fields_html = ob_get_clean();
        $id = 'aisFilterBar';
        include __DIR__ . '/../partials/filter-bar.php';
        ?>

        <!-- Employees is shared; the other cards show only for the active metric (data-ais-metric). -->
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
            <div class="col-md-3" data-ais-metric="income">
                <div class="stat-card stat-card-primary h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_gross_income">Total Gross Income</div>
                        <div class="stat-card-value" id="aisSummaryGross">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3" data-ais-metric="income">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-minus"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_deductions">Total Deductions</div>
                        <div class="stat-card-value" id="aisSummaryDeduction">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3" data-ais-metric="income">
                <div class="stat-card stat-card-success h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-coins"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="total_net_income">Total Net Income</div>
                        <div class="stat-card-value" id="aisSummaryNet">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3" data-ais-metric="pit">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="ais_total_tax_withheld">Total Tax Withheld</div>
                        <div class="stat-card-value" id="aisSummaryTax">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3" data-ais-metric="sso">
                <div class="stat-card stat-card-danger h-100">
                    <div class="stat-card-icon"><i class="fa-solid fa-shield-heart"></i></div>
                    <div>
                        <div class="stat-card-label" data-i18n="ais_total_sso_withheld">Total SSO Contribution</div>
                        <div class="stat-card-value" id="aisSummarySso">-</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-content" id="aisMetricContent">
        <div class="tab-pane fade show active" id="ais-metric-income-pane" role="tabpanel" aria-labelledby="ais-metric-income-tab" tabindex="0">
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
        <div class="ais-table-wrap pt-2 mb-5">
            <table class="ais-table table table-hover w-100" id="tb_annual_summary">
                <thead></thead>
                <tfoot></tfoot>
            </table>
        </div>
        </div>
        <div class="tab-pane fade" id="ais-metric-pit-pane" role="tabpanel" aria-labelledby="ais-metric-pit-tab" tabindex="0">
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
        <div class="tab-pane fade" id="ais-metric-sso-pane" role="tabpanel" aria-labelledby="ais-metric-sso-tab" tabindex="0">
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
        </div>
    </div>

    <div class="tab-pane fade<?= $activeTab === 'monthly' ? ' show active' : '' ?>" id="ais-monthly-pit-pane" role="tabpanel" aria-labelledby="ais-monthly-pit-tab" tabindex="0">
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
        ob_start();
        $ais_prefix = 'aisMonthlyFilter';
        include __DIR__ . '/_ais_filters.php';
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
