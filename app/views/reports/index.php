<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="reports">Reports</span>
        </h5>
    </nav>
    <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
         app/views/payroll/index.php). -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-file-invoice"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="reports">Reports</h5>
            <p class="page-header-card-desc" data-i18n="reports_description">Split into per-schedule reports (pulled from a payroll run) and annual reports (issued once a year) -- pick the period once, then generate whichever reports you need for it.</p>
        </div>
    </div>

    <!-- 2026-08-27, explicit request: "ปรับ Design และโครงสร้างให้หน่อยครับ...แบ่งเป็น Report ที่ต้องดึง
         จากรอบการจ่าย และ Report ประจำปีที่ต้องออก ให้รูปแบบการใช้งานดูง่าย และรู้ว่าต้องทำอะไร" -- Phase 1
         of 2 (confirmed via AskUserQuestion; Phase 2 is a new per-employee PND/50-ทวิ certificate +
         request flow, deferred). Previously grouped ONLY by report type (Statutory/Payment/Internal),
         each card repeating its own year/run picker -- now the PRIMARY split is frequency (this tab
         structure). Annual Reports keeps the report-type sections + shared-year-picker + card layout
         from that first pass.
         Per-Cycle Reports was rebuilt again the SAME DAY (2nd explicit request: "ปรับให้เป็นตาราง เลย
         เป็นแถวละ 1 รอบที่เสร็จแล้ว และมีปุ่มให้กด export โดยแยกเป็น column ละ 1 ปุ่ม แยก Tab สำหรับรายงาน
         แต่ละประเภท ถ้าประเภทเดียวกันให้อยู่ใน tab เดียวกัน และบันทึก Log ว่า Export ไปแล้ว มี Audit Log
         ด้วย ว่าแต่ละรอบที่ Export ได้ข้อมูลอะไรไปบ้าง โดยเรียงจากรอบล่าสุดขึ้นหัวตาราง") into a run x
         report-type MATRIX table -- see the #cycle-pane markup below and public/js/reports/index.js's
         "Per-Cycle Reports tab" section for the full design. The shared run/year picker + Generate
         form is gone from THIS tab entirely (every completed run is just a row now, no picking one
         first); Annual Reports' own picker+card flow is untouched, since the 2nd request only ever
         talked about "รอบที่เสร็จแล้ว" (completed runs) -- there's no equivalent concept for a
         once-a-year report.
         Rebuilt AGAIN 2026-08-29 (explicit request: "ปรับ Design ให้ใหม่ทั้งหมด...ใช้หลักการ Download แบบ
         เดียวกับหน้า Process") -- the run x report-type MATRIX above is retired in favor of the SAME
         pattern Payroll Process Detail's own "Reports" tab already established: pick ONE run via a
         .reports-period-bar picker, then a plain row-list per report type (Report | Downloads | Last
         Downloaded | Actions), each row's Download going through the SAME preview-first modal
         (#reportsPreviewModal, see below) and a History button opening a per-report download log --
         reusing runCycleReportsSummary() (ReportsController) instead of the matrix's own per-cell
         format/language dropdown. Pay Slip's row (scoped to one employee, not the whole run) opens an
         employee-roster picker instead of downloading directly -- see #payslipRosterModal further
         down and its own docblock for why (explicit request: "ปรับให้ขึ้นเป็นรายชื่อพนักงานมาเลย"). -->
    <style>
        /* 2026-08-29, "ปรับ Design ให้ใหม่ทั้งหมด...ให้รูปแบบดูง่ายและสวยงาม" -- small page-scoped polish
           for the row-list tables/report cards this redesign introduced; nothing here is reused
           elsewhere so it stays local rather than in the global stylesheet. */
        .reports-row-report-name { font-weight: 600; color: #344054; }
        .reports-row-report-type-icon { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; margin-right: .6rem; font-size: .9rem; color: #fff; flex: none; }
        .reports-row-report-type-icon.rt-statutory { background: linear-gradient(135deg, #FF9900, #ffb84d); }
        .reports-row-report-type-icon.rt-payment { background: linear-gradient(135deg, #12b76a, #6ee7b7); }
        .reports-row-report-type-icon.rt-internal { background: linear-gradient(135deg, #6366f1, #a5b4fc); }
        .report-card-annual { transition: box-shadow .15s ease, transform .15s ease; }
        .report-card-annual:hover { box-shadow: 0 8px 20px rgba(16, 24, 40, .08); transform: translateY(-2px); }
        /* 2026-09-08, explicit request: "Column ของทั้ง 3 ปุ่มอยากให้ลดความกว้างลงให้เท่ากัน และไปรวมอยู่ฝั่งขวา
           ของตาราง" -- pins the 3 report-group dropdown columns (public/js/reports/index.js's own
           renderCycleMatrixTable()) to a small, equal width so the info columns beside them (which
           have no explicit width) absorb any leftover table width instead -- see that function's own
           comment for why this is what actually pulls the 3 buttons into a tight cluster. */
        .reports-matrix-group-col { width: 64px; }
    </style>
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" id="reportsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu active" id="cycle-tab" data-bs-toggle="tab" data-bs-target="#cycle-pane" type="button" role="tab" aria-controls="cycle-pane" aria-selected="true">
                <i class="fa-solid fa-calendar-check me-2"></i><span data-i18n="tab_cycle_reports">Per-Schedule Reports</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="annual-tab" data-bs-toggle="tab" data-bs-target="#annual-pane" type="button" role="tab" aria-controls="annual-pane" aria-selected="false">
                <i class="fa-solid fa-calendar-days me-2"></i><span data-i18n="tab_annual_reports">Annual Reports</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-pane" type="button" role="tab" aria-controls="history-pane" aria-selected="false">
                <i class="fa-solid fa-clock-rotate-left me-2"></i><span data-i18n="tab_export_history">Export History</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
        <div class="tab-pane fade show active" id="cycle-pane" role="tabpanel" aria-labelledby="cycle-tab" tabindex="0">
            <!-- 2026-09-07, explicit request: "Menu สร้างรายงาน ถ้าเปลี่ยนเป็น ตารางแสดงรอบที่สามารถพิมพ์ได้
                 แล้วให้มี column พิมพ์ตามแบบที่พิมพ์ได้ น่าจะใช้งานง่ายกว่าครับ และ filter ก็ต้องปรับให้รองรับ
                 การทำงานใหม่" -- reverts the 2026-08-29 "pick ONE run first" redesign back to the
                 run x report-type MATRIX this tab briefly had 2026-08-27 (see
                 PayrollReportDataModel::getCompletedRuns()'s own docblock for that full lineage) --
                 one ROW per completed run, one COLUMN per applicable report, a single print button
                 per cell. The old filter (a single-run <select>, since there was only ever one run
                 "selected" at a time) no longer fits a table showing MANY runs at once -- replaced
                 with a date-range filter narrowing WHICH runs appear as rows, same
                 .station-filter shape every other list page's filter already uses. Columns
                 themselves are entirely JS-driven (public/js/reports/index.js's own
                 renderCycleMatrixTable()) since which reports apply can vary per run (TH_PND1 needs
                 taxable employees, TH_SSO110 needs SSO-active ones) -- no static <thead> here. -->
            <div class="station-filter" id="cycleReportPeriodBar">
                <i class="fa-solid fa-filter me-1"></i><span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="cycleReportPeriodBarToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="text-muted small mb-2" data-i18n="reports_cycle_hint">Each row is a completed payroll run -- click a report's icon to preview and download it for that run.</div>
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="date_from">From</label>
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="cycleReportDateFrom" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="date_to">To</label>
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="cycleReportDateTo" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="cycleReportFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCycleReportClearFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="reports-not-ready-banner mb-3 d-none" id="cycleReportsNoRunBanner">
                <div class="reports-not-ready-banner-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div class="reports-not-ready-banner-body">
                    <div class="reports-not-ready-banner-title" data-i18n="reports_cycle_no_run_title">No Completed Payroll Run Yet</div>
                    <div class="reports-not-ready-banner-hint" data-i18n="no_completed_runs">No completed payroll runs yet.</div>
                </div>
            </div>
            <div class="table-responsive d-none" id="cycleMatrixTableWrap">
                <table class="table table-hover align-middle w-100" id="tb_cycle_matrix"></table>
            </div>
        </div>
        <div class="tab-pane fade" id="annual-pane" role="tabpanel" aria-labelledby="annual-tab" tabindex="0">
            <!-- 2026-08-30, explicit follow-up: "filter 2 tab แรกไม่เป็นไปตามระบบที่ออกไปแบบ...filter ปีให้
                 เลือกจากปีที่มีข้อมูลจริง" -- was a custom .reports-period-bar with a free-typed number
                 input (any year, including ones with zero data); now .station-filter (matching the
                 system standard) with a dropdown populated from ReportsController::availableYears()
                 (only years with a real, usable-state run). -->
            <div class="station-filter" id="annualReportPeriodBar">
                <i class="fa-solid fa-filter me-1"></i><span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="annualReportPeriodBarToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="text-muted small mb-2" data-i18n="reports_annual_hint">Select the year, then click Generate on whichever annual reports you need.</div>
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1" data-i18n="period_year">Year</label>
                            <select class="form-select form-select-sm select2-native" id="reportsPeriodYear"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="reports-not-ready-banner mb-3 d-none" id="annualReportsNoYearBanner">
                <div class="reports-not-ready-banner-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div class="reports-not-ready-banner-body">
                    <div class="reports-not-ready-banner-title" data-i18n="reports_cycle_no_run_title">No Completed Payroll Run Yet</div>
                    <div class="reports-not-ready-banner-hint" data-i18n="no_completed_runs">No completed payroll runs yet.</div>
                </div>
            </div>
            <!-- 2026-08-30, explicit request: "รายงานประจำปี อยากให้เป็นตารางครับ และกดกด Download ให้เป็น
                 แบบเดียวกันคือมี preview ก่อน แล้วให้มี Dropdown เลือกว่าประเภทไหน และปุ่ม th en" -- was a
                 card grid per report-type section, each with its own inline form; now the same
                 row-list table shape as Per-Cycle Reports (Report | Downloads | Last Downloaded |
                 Actions). A report needing extra input before it can preview (format choice always;
                 SSO 6-09 also needs its own month; Payment Voucher also needs its own employee)
                 opens #annualReportConfigModal first to collect just that, THEN routes through the
                 exact same #reportsPreviewModal (openReportsPreview()) every other report on this
                 page already uses -- which is itself where the TH/EN download buttons live, so
                 they don't need to be duplicated in the config step. -->
            <div id="annualReportBody" class="d-none">
                <div class="table-responsive"><table class="table table-hover align-middle w-100" id="tb_annual_reports">
                    <thead class="table-light text-secondary"><tr>
                        <th data-i18n="report_name">Report</th>
                        <th class="text-center" data-i18n="download_count">Downloaded</th>
                        <th data-i18n="last_downloaded_at">Last Downloaded</th>
                        <th class="text-center"></th>
                    </tr></thead>
                    <tbody></tbody>
                </table></div>
            </div>
            <div class="text-center text-secondary py-4 d-none" id="noReports_annual"><span data-i18n="no_reports_available">No reports are registered in this category yet.</span></div>
        </div>
        <div class="tab-pane fade" id="history-pane" role="tabpanel" aria-labelledby="history-tab" tabindex="0">
            <!-- 2026-08-29, explicit request: "ประวัติการ Export ให้เป็น Datatable เพิ่ม Filter ช่วงวันที่ได้"
                 -- #tb_export_history was already a real DataTable with a working report_type filter +
                 Excel-style per-column filters (see initExportHistoryTable()'s own comment); the one
                 genuine gap was a date-RANGE filter, which an Excel-style discrete-value filter can't
                 express. Restyled the report-type filter into the same .station-filter component used
                 everywhere else in this app (per the same-day "ปรับ Design Filter ให้เป็นรูปแบบที่กำหนดไว้
                 ของระบบ" request) alongside the new date range. -->
            <div class="station-filter mb-2" id="exportHistoryStationFilter">
                <i class="fa-solid fa-filter me-1"></i><span class="station-filter-label" data-i18n="label_filter">Filter</span>
                <button type="button" class="station-filter-toggle" id="exportHistoryStationFilterToggle" title="Toggle filter">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <div class="station-filter-body">
                    <div class="row g-2">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1"><span data-i18n="filter_report_type">Report Type</span></label>
                            <select class="form-select select2-static" id="filter_export_report_type" data-option-keys="report_type_statutory,report_type_payment,report_type_internal" data-option-values="statutory,payment,internal"></select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1"><span data-i18n="filter_date_from">From</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="exportHistoryDateFrom" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label mb-1"><span data-i18n="filter_date_to">To</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control datepicker" id="exportHistoryDateTo" autocomplete="off">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="station-filter-clear-row d-none" id="exportHistoryFilterClearRow">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnExportHistoryClearFilter">
                    <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-border align-middle w-100" id="tb_export_history">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="table_generated_at">Generated At</th>
                            <th data-i18n="table_report_name">Report</th>
                            <th data-i18n="table_format">Format</th>
                            <th data-i18n="table_file_name">File Name</th>
                            <th data-i18n="table_generated_by">Generated By</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- payslipRosterModal / annualReportConfigModal / reportsPreviewModal / cycleReportHistoryModal
         moved to app/views/layout/modals.php (2026-08-30, modal consolidation). -->
</div>
<script src="<?=asset('public/js/reports/index.js')?>"></script>
