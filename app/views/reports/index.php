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
            <p class="page-header-card-desc" data-i18n="reports_description">Split into per-cycle reports (pulled from a payroll run) and annual reports (issued once a year) -- pick the period once, then generate whichever reports you need for it.</p>
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
         once-a-year report. -->
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" id="reportsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu active" id="cycle-tab" data-bs-toggle="tab" data-bs-target="#cycle-pane" type="button" role="tab" aria-controls="cycle-pane" aria-selected="true">
                <i class="fa-solid fa-calendar-check me-2"></i><span data-i18n="tab_cycle_reports">Per-Cycle Reports</span>
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
        <div class="tab-pane fade show active p-3 p-md-4" id="cycle-pane" role="tabpanel" aria-labelledby="cycle-tab" tabindex="0">
            <div class="bg-light rounded-3 p-2 mb-3 structure-tabs-wrap">
                <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" id="cycleReportTypeTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link structure-menu active" id="cycleType-statutory-tab" data-bs-toggle="tab" data-bs-target="#cycleType-statutory-pane" type="button" role="tab" aria-controls="cycleType-statutory-pane" aria-selected="true" data-report-type="statutory">
                            <i class="fa-solid fa-landmark me-2"></i><span data-i18n="report_type_statutory">Statutory</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link structure-menu" id="cycleType-payment-tab" data-bs-toggle="tab" data-bs-target="#cycleType-payment-pane" type="button" role="tab" aria-controls="cycleType-payment-pane" aria-selected="false" data-report-type="payment">
                            <i class="fa-solid fa-money-check-dollar me-2"></i><span data-i18n="report_type_payment">Payment</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link structure-menu" id="cycleType-internal-tab" data-bs-toggle="tab" data-bs-target="#cycleType-internal-pane" type="button" role="tab" aria-controls="cycleType-internal-pane" aria-selected="false" data-report-type="internal">
                            <i class="fa-solid fa-building me-2"></i><span data-i18n="report_type_internal">Internal</span>
                        </button>
                    </li>
                </ul>
            </div>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="cycleType-statutory-pane" role="tabpanel" aria-labelledby="cycleType-statutory-tab" tabindex="0">
                    <div class="table-responsive"><table class="table table-hover table-border align-middle w-100" id="tb_cycle_statutory"></table></div>
                    <div class="text-center text-secondary py-4 d-none" id="noCycleReports_statutory"><span></span></div>
                </div>
                <div class="tab-pane fade" id="cycleType-payment-pane" role="tabpanel" aria-labelledby="cycleType-payment-tab" tabindex="0">
                    <div class="table-responsive"><table class="table table-hover table-border align-middle w-100" id="tb_cycle_payment"></table></div>
                    <div class="text-center text-secondary py-4 d-none" id="noCycleReports_payment"><span></span></div>
                </div>
                <div class="tab-pane fade" id="cycleType-internal-pane" role="tabpanel" aria-labelledby="cycleType-internal-tab" tabindex="0">
                    <div class="table-responsive"><table class="table table-hover table-border align-middle w-100" id="tb_cycle_internal"></table></div>
                    <div class="text-center text-secondary py-4 d-none" id="noCycleReports_internal"><span></span></div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade p-3 p-md-4" id="annual-pane" role="tabpanel" aria-labelledby="annual-tab" tabindex="0">
            <div class="reports-period-bar mb-4">
                <div class="reports-period-bar-icon"><i class="fa-solid fa-calendar-days"></i></div>
                <div class="reports-period-bar-body">
                    <div class="reports-period-bar-hint" data-i18n="reports_annual_hint">Select the year, then click Generate on whichever annual reports you need.</div>
                    <input type="number" class="form-control form-control-sm reports-period-year" id="reportsPeriodYear" min="2500" max="2700" style="max-width:160px;" placeholder="พ.ศ.">
                </div>
            </div>
            <div class="reports-type-section" data-report-type-section="statutory">
                <h6 class="reports-type-section-title"><i class="fa-solid fa-landmark me-2"></i><span data-i18n="report_type_statutory">Statutory</span></h6>
                <div class="row g-3" id="reportCards_annual_statutory"></div>
            </div>
            <div class="reports-type-section" data-report-type-section="payment">
                <h6 class="reports-type-section-title"><i class="fa-solid fa-money-check-dollar me-2"></i><span data-i18n="report_type_payment">Payment</span></h6>
                <div class="row g-3" id="reportCards_annual_payment"></div>
            </div>
            <div class="reports-type-section" data-report-type-section="internal">
                <h6 class="reports-type-section-title"><i class="fa-solid fa-building me-2"></i><span data-i18n="report_type_internal">Internal</span></h6>
                <div class="row g-3" id="reportCards_annual_internal"></div>
            </div>
            <div class="text-center text-secondary py-4 d-none" id="noReports_annual"><span data-i18n="no_reports_available">No reports are registered in this category yet.</span></div>
        </div>
        <div class="tab-pane fade p-3 p-md-4" id="history-pane" role="tabpanel" aria-labelledby="history-tab" tabindex="0">
            <div class="row mb-3">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label mb-1"><span data-i18n="filter_report_type">Report Type</span></label>
                    <select class="form-select select2-static" id="filter_export_report_type" data-option-keys="report_type_statutory,report_type_payment,report_type_internal" data-option-values="statutory,payment,internal"></select>
                </div>
            </div>
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

    <!-- Report card template (cloned per report by JS) -- used by Annual Reports only now (Per-Cycle
         Reports moved to the run x report-type matrix table above, 2026-08-27). Year is shared via
         the .reports-period-bar control on the Annual tab; only the genuinely per-report extra
         fields (SSO 6-09's own month, Payment Voucher's own employee) remain here. -->
    <template id="reportCardTemplate">
        <div class="col-sm-6 col-lg-4">
            <div class="card-surface p-3 h-100 d-flex flex-column">
                <h6 class="fw-bold mb-1 report-card-label"></h6>
                <form class="report-generate-form mt-2 flex-grow-1 d-flex flex-column">
                    <div class="mb-2 field-month d-none">
                        <label class="form-label mb-1 small"><span data-i18n="period_month">Month</span></label>
                        <select class="form-select form-select-sm select2-static required field-month-input" data-option-keys="month_1,month_2,month_3,month_4,month_5,month_6,month_7,month_8,month_9,month_10,month_11,month_12" data-option-values="1,2,3,4,5,6,7,8,9,10,11,12"></select>
                    </div>
                    <div class="mb-2 field-employee d-none">
                        <label class="form-label mb-1 small"><span data-i18n="input_employee">Employee</span></label>
                        <select class="form-select form-select-sm select2-remote required field-employee-input" data-api="/api/employee.report_to.get"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label mb-1 small"><span data-i18n="report_format_label">Format</span></label>
                        <select class="form-select form-select-sm select2-static required field-format-input"></select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-auto">
                        <i class="fa-solid fa-file-export me-1"></i><span data-i18n="btn_generate">Generate</span>
                    </button>
                </form>
            </div>
        </div>
    </template>

    <!-- Per-Cycle Reports matrix, 2026-08-27: a cell for a report that's scoped to one specific
         employee (Pay Slip -- there's no "run-level" version of a pay slip to export) opens this
         small picker instead of downloading directly. -->
    <div class="modal fade" id="cycleExportEmployeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="cycleExportEmployeeModalTitle"></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label mb-1"><span data-i18n="input_employee">Employee</span></label>
                    <select class="form-select select2-remote" id="cycleExportEmployeeSelect" data-api="/api/employee.report_to.get"></select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><span data-i18n="cancel">Cancel</span></button>
                    <button type="button" class="btn btn-primary" id="cycleExportEmployeeConfirmBtn">
                        <i class="fa-solid fa-file-export me-1"></i><span data-i18n="btn_generate">Generate</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Per-Cycle Reports matrix, 2026-08-27: per-run audit of everything already exported for that
         run (across every report type, not just the tab currently open) -- reuses the same
         report_export_logs data the Export History tab already shows, filtered to one run. -->
    <div class="modal fade" id="cycleAuditLogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        <span data-i18n="export_audit_log">Export Audit Log</span>
                        <span id="cycleAuditLogRunLabel" class="text-secondary fw-normal small ms-1"></span>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="cycleAuditLogBody"></div>
            </div>
        </div>
    </div>
</div>
<script src="<?=asset('public/js/reports/index.js')?>"></script>
