<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i><span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <a href="<?=BASE_URL?>/reports" class="bc-parent text-decoration-none" data-i18n="reports">Reports</a>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="annual_income_summary">Annual Income Summary</span>
        </h5>
    </nav>
    <!-- 2026-08-29, explicit request: "ต้องการอีกหน้าคล้ายๆหน้าของ Employee เป็นข้อมูลสรุปรอบตามปี" -- own
         interactive page (live filter + horizontally-scrolling table with frozen columns), not a
         generate-and-download document card like the rest of the Reports module -- see
         AnnualIncomeSummaryModel's own docblock for the data/aggregation design. -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-chart-column"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="annual_income_summary">Annual Income Summary</h5>
            <p class="page-header-card-desc" data-i18n="annual_income_summary_description">Each employee's income, deductions, and net pay, month by month across a fiscal year, with an annual total.</p>
        </div>
        <!-- 2026-08-29, explicit follow-up: "ยังไม่มีหน้าตั้งค่าการตัดรอบปี ที่เอาไปเป็นเงื่อนไขในการแสดงผล
             Report ประจำปี" -- fiscal_year_start_month already lived in Company Profile's own
             "Company Information" section (see that view's own comment), but wasn't reachable from
             anywhere near the one report that actually uses it as a condition. Quick-access gear
             button right on this page's own header, opening a single-field modal -- see
             AnnualIncomeSummaryModel::saveFiscalYearStartMonth()'s own docblock for why this is a
             separate, narrow save path rather than routing through Company Profile's full save(). -->
        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" id="btnAisFiscalYearSettings" data-bs-toggle="modal" data-bs-target="#aisFiscalYearSettingsModal">
            <i class="fa-solid fa-gear me-1"></i><span data-i18n="ais_fiscal_year_settings">Fiscal Year Settings</span>
        </button>
    </div>

    <div class="modal fade" id="aisFiscalYearSettingsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-gear me-2 text-brand"></i><span data-i18n="ais_fiscal_year_settings">Fiscal Year Settings</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" data-i18n="fiscal_year_start_month">Fiscal Year Start Month</label>
                    <select class="form-select select2-static" id="aisFiscalYearStartMonth" data-option-keys="month_1,month_2,month_3,month_4,month_5,month_6,month_7,month_8,month_9,month_10,month_11,month_12" data-option-values="1,2,3,4,5,6,7,8,9,10,11,12"></select>
                    <p class="text-muted small mt-2 mb-0" data-i18n="ais_fiscal_year_settings_hint">Sets which calendar month a fiscal year starts on for this report (1 = January is a plain calendar year). Applies company-wide.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" data-i18n="close">Close</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSaveAisFiscalYearSetting"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
                </div>
            </div>
        </div>
    </div>

    <!-- 2026-08-29, explicit follow-up: "ไม่ต้องมีปุ่ม search เลือก filter แล้ว Reload เลย" -- no Apply
         button; every filter reloads immediately on change (see annual-summary.js). Free-text
         search is DataTable's own built-in search box (in the table card below) instead of a
         separate input here, for the same reason -- it has no button either. -->
    <div class="station-filter" id="aisStationFilter">
        <span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="aisStationFilterToggle" title="Toggle filter">
            <i class="fa-solid fa-chevron-up"></i>
        </button>
        <button type="button" class="btn btn-link btn-sm d-none" id="aisClearFilterBtn" data-i18n="clear_filter">Clear Filter</button>
        <div class="station-filter-body">
            <div class="row g-3">
                <div class="col-sm-2">
                    <label class="form-label small" data-i18n="fiscal_year">Fiscal Year</label>
                    <select class="form-select" id="aisFiscalYear"></select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small" data-i18n="department">Department</label>
                    <select class="form-select select2-remote" id="aisFilterDepartment" data-api="/api/department.get" data-type="department"></select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small" data-i18n="team">Team</label>
                    <select class="form-select select2-remote" id="aisFilterTeam" data-api="/api/team.get" data-type="team"></select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small" data-i18n="branch">Branch</label>
                    <select class="form-select select2-remote" id="aisFilterBranch" data-api="/api/branch.get" data-type="branch"></select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small" data-i18n="role">Role</label>
                    <select class="form-select select2-remote" id="aisFilterRole" data-api="/api/role.get" data-type="role"></select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small" data-i18n="status">Status</label>
                    <select class="form-select" id="aisFilterStatus" data-option-keys="status_all,status_active,status_probation,status_resigned,status_terminated" data-option-values=",active,probation,resigned,terminated"></select>
                </div>
            </div>
        </div>
    </div>

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

    <div class="card-surface p-0 mb-5">
        <div class="ais-legend px-3 pt-3">
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-past_done"></span><span data-i18n="ais_legend_past_done">Processed</span></span>
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-past_missing"></span><span data-i18n="ais_legend_past_missing">Past, not processed</span></span>
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-current"></span><span data-i18n="ais_legend_current">Current month</span></span>
            <span class="ais-legend-item"><span class="ais-legend-swatch ais-month-future"></span><span data-i18n="ais_legend_future">Upcoming</span></span>
        </div>
        <div id="aisTableEmpty" class="text-center text-secondary py-5 d-none">
            <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="ais_no_data">No payroll data found for this fiscal year.</span>
        </div>
        <div class="ais-table-wrap p-3 pt-2">
            <table class="ais-table table table-hover w-100" id="tb_annual_summary">
                <thead></thead>
                <tfoot></tfoot>
            </table>
        </div>
    </div>
</div>
<script src="<?=asset('public/js/reports/annual-summary.js')?>"></script>
