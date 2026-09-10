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
    <!-- .page-header-card rollout (2026-08-21 origin, see payroll/index.php's own comment) --
         payroll/detail.php was a real, previously-missed gap (T063 design audit, 2026-09-04):
         one of the highest-traffic pages in the app had no standard page header at all. This is
         the STATIC identity header (icon + generic title/description, matching every other
         top-level page) -- the .card-surface block right below it is UNCHANGED, still the
         dynamic run-specific content (run name/status badge/action buttons), not replaced by
         this. #runDetailTabs further down the page is a separate, deliberately-exempted
         component (its own bespoke polish CSS, unrelated to this page header) -- not touched. -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="payroll_run_detail_title">Payroll Run Detail</h5>
            <p class="page-header-card-desc" data-i18n="payroll_run_detail_description">Review, calculate, and manage this payroll run from draft through approval, payment, and closing.</p>
        </div>
    </div>

    <!-- 2026-09-09, explicit request: "ตรง Timeline ในหน้า Process Detail เอา card-surface mb-4 ออกครับ" --
         was the same .card-surface treatment every other content block on this page uses; removed
         here specifically, per this explicit request, leaving a plain unstyled wrapper. -->
    <div>
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h5 class="fw-bold mb-0" id="runNameHeading">-</h5>
                    <span id="runStateBadge"></span>
                </div>
                <div class="text-danger small mt-2 d-none" id="rejectReasonBox"></div>
                <div class="text-muted small mt-2 d-none" id="cancelReasonBox"></div>
            </div>
            <!-- 2026-08-29, same-day follow-up: "ตรงปุ่มออกรายงาน ให้ปรับเป็นเพิ่มอีก Tab ก่อน Action
                 History" -- the dropdown button that used to sit here (renderRunReportsButtons())
                 moved into its own "Reports" tab (#run-reports-pane) instead. The List page's own
                 row dropdown (public/js/payroll/index.js) is UNCHANGED, still a dropdown there --
                 this request was specifically about the Detail page. -->
            <!-- 2026-09-09, real bug found and fixed (explicit report: "ปุ่ม Export Excel และ pdf ตอนนี้
                 ไม่ติดกัน อยากให้อยู่ติดกันและไปอยู่ขวาสุด") -- both buttons used to be direct children
                 of the SAME outer `justify-content-between` flex container as the run-name/badge div
                 above them, making `justify-content-between` distribute all 3 items (name-div, Excel,
                 PDF) with equal space between EACH of them, instead of grouping the two buttons
                 together at the far right. Wrapped them in their own `d-flex gap-2` group so the
                 outer flex only ever sees 2 items again (name-div, button-group) -- now the whole
                 group moves to the right edge as one unit, with the 2 buttons touching via gap-2
                 inside it. -->
            <div class="d-flex gap-2">
            <!-- 2026-08-31, same-day follow-up, explicit request: "ปุ่ม Export Excel ไม่ควรไปรวมอยู่ใน
                 รายงาน ย้ายไปอยู่กับ Timeline ดูตรงการจัดตำแหน่งให้หน่อยครับ ขอสวยๆ" -- this slot sat empty
                 since the dropdown above it was removed; reused here for PAYROLL_REGISTER's own
                 dedicated one-click export (this run's employee-by-employee register), directly
                 above the Timeline it now sits with instead of buried as one row among the
                 statutory/payment reports in the Reports tab. -->
            <button type="button" class="btn btn-outline-success btn-sm" id="btnExportRunRegister">
                <i class="fa-solid fa-file-excel me-1"></i><span data-i18n="export_excel">Export Excel</span>
            </button>
            <!-- 2026-09-02, explicit request: "เพิ่มให้ Export เป็น PDF ได้ด้วย...การ Export กดแล้ว แสดง
                 ตัวอย่าง แล้วค่อยเลือกจะ Download ภาษาไทยหรือภาษาอังกฤษ" -- deliberately additive next to
                 the Excel button above (unchanged, still a direct one-click download) rather than
                 replacing it -- Excel stays the quick-download path, this opens the SAME
                 #reportPreviewModal every other report on this page already uses (PDF preview +
                 Thai/English download buttons), reused as-is with report_code=PAYROLL_REGISTER. -->
            <button type="button" class="btn btn-outline-danger btn-sm" id="btnPreviewRunRegisterPdf">
                <i class="fa-solid fa-file-pdf me-1"></i><span data-i18n="export_pdf">Export PDF</span>
            </button>
            </div>
        </div>
        <div class="process-timeline-wrap" id="runProcessTimeline"></div>
        <div id="nextStepBanner" class="next-step-banner"></div>
    </div>

    <!-- 2026-09-09, explicit request: "ส่วน Card Summary ให้ย้ายไปไว้ด้านบน Tab ใต้ Timeline ของรอบ" --
         moved out of the "Details"/Employee tabs entirely (previously inside the Employee Breakdown
         section, only visible on whichever tab held it) so the run's headline numbers stay visible no
         matter which tab is open. 2026-09-01, explicit follow-up correction (still applies, unchanged
         by the move): "ให้ขึ้นใน card พนักงานครับ มีแค่ 4 Card เหมือนเดิม" -- stays 4 cards total, Bank/
         Cash breakdown as a subtext line inside "Employees" (#infoPaymentBreakdown).
         2026-09-09, real bug found and fixed (explicit report: "วิธีจ่ายเงิน ตอนนี้ติ๊กแล้ว Employee ไม่
         เปลี่ยนตามครับ") -- these 4 values used to be set ONCE from the run's own server-side totals
         (renderRunHeader()) and never touched again, so ticking the Bank/Cash payment-method filter
         (on the Employee tab, right above the table, #paymentMethodFilterWrap) correctly filtered the
         table+footer but left these more prominent cards showing the stale, unfiltered total. Now
         recomputed from the table's own currently-VISIBLE (filtered) rows on every draw -- see
         updateSummaryCardsFromTable() in detail.js. -->
    <?php // mt-4 here, not on #nextStepBanner: detail.js overwrites its class attr ?>
    <div class="row g-3 mt-4 mb-4" id="runSummaryCards">
        <div class="col-6 col-md-3">
            <div class="stat-card stat-card-info">
                <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                <div>
                    <div class="stat-card-label" data-i18n="table_employee_count">Employees</div>
                    <div class="stat-card-value" id="infoEmployeeCount">-</div>
                    <div class="stat-card-sub" id="infoPaymentBreakdown"></div>
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

    <div class="alert alert-danger small d-none" id="validationErrorsBanner"></div>
    <!-- 2026-08-30 (Phase 8, T041): reconciliation warning for a sync-based run -- employees who
         would normally be expected in payroll but weren't in this Origami sync payload and nobody
         manually joined them either. Advisory only (alert-warning, not alert-danger) -- never blocks
         submit, just a prompt to verify before doing so. See PayrollRunModel::syncMissingEmployees(). -->
    <div class="alert alert-warning small d-none d-flex justify-content-between align-items-center flex-wrap gap-2" id="syncMissingEmployeesBanner">
        <span id="syncMissingEmployeesBannerText"></span>
        <button type="button" class="btn btn-sm btn-outline-dark" id="syncMissingEmployeesViewBtn" data-i18n="view_list">View List</button>
    </div>
    <!-- 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรือ
         อ้างอิงถึงรอบ" -- shown whenever this run was created with "อ้างอิงถึงรอบ" ticked
         (payroll_runs.merge_target_run_id set) and is still a draft, off-cycle run (matches
         PayrollRunModel::mergeIntoExistingRun()'s own eligibility check server-side). Build this
         run up normally first (Join Employees/Manage Items below), then click the button here
         when ready -- folds this run's resolved amounts into the target and soft-deletes this one
         (same mechanics/confirmation dance as the Pending-Pull table's own "Merge into Target"
         action, see PayrollRunModel::performRunMerge()'s own docblock). -->
    <div class="alert alert-info small d-none d-flex justify-content-between align-items-center flex-wrap gap-2" id="mergeTargetBanner">
        <span id="mergeTargetBannerText"></span>
        <button type="button" class="btn btn-sm btn-primary" id="btnMergeIntoTarget"><i class="fa-solid fa-code-merge me-1"></i><span data-i18n="btn_merge_sync">Merge into Target</span></button>
    </div>
    <!-- 2026-09-06: the "future cycle" merge-target form -- no button here at all (there is
         nothing to merge into yet), just a status line; see renderMergeTargetBanner()'s own
         docblock for how this and #mergeTargetBanner above stay mutually exclusive. -->
    <div class="alert alert-warning small d-none" id="mergeTargetWaitingBanner">
        <i class="fa-solid fa-hourglass-half me-1"></i><span id="mergeTargetWaitingBannerText"></span>
    </div>

    <ul class="nav nav-tabs" id="runDetailTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary active" id="run-details-tab" data-bs-toggle="tab" data-bs-target="#run-details-pane" type="button" role="tab" aria-controls="run-details-pane" aria-selected="true">
                <i class="fa-solid fa-circle-info me-1"></i><span data-i18n="tab_run_details">Details</span>
            </button>
        </li>
        <!-- 2026-09-09, explicit request: "แยก Employee และการคำนวณไว้อีก Tab ครับ...ใน Tab แรกจะเป็นการ
             ตั้งค่าทั้งหมด" -- the Employee Breakdown table + everything that ACTS on it (auto-recalculate/
             recalc reminder/bulk Verify) moved out of the "Details" tab into this new one; "Details"
             keeps Run Information plus the settings/config that used to compete with the employee
             table for the same vertical space (Run Settings, Payment Method filter) -- see that tab's
             own comments further down. Reuses the SAME "Employee Breakdown" wording/icon this section
             heading already used before the split (data-i18n="employee_breakdown"), no new i18n key
             needed. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="run-employee-tab" data-bs-toggle="tab" data-bs-target="#run-employee-pane" type="button" role="tab" aria-controls="run-employee-pane" aria-selected="false">
                <i class="fa-solid fa-users me-1"></i><span data-i18n="employee_breakdown">Employee Breakdown</span>
            </button>
        </li>
        <!-- 2026-08-29, same-day follow-up: "ตรงปุ่มออกรายงาน ให้ปรับเป็นเพิ่มอีก Tab ก่อน Action History
             และแสดงเป็นตารางรายการไว้" -- was a dropdown button in the page header
             (renderRunReportsButtons(), now removed) -- see loadRunReportsTab() in detail.js. -->
        <!-- 2026-09-09, explicit follow-up: "เอา icon ออกจาก Tab ด้วยครับ" -- the "Tab Report Icon ให้เหมือน
             Menu Report" request just above turned out to be about the ITEMS inside this tab's own list
             (see detail.js's own RD_REPORT_TILE_BY_TYPE/rdReportIconTileHtml()), not this tab button --
             removed entirely here, plain text label like every other place this correction applies. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="run-reports-tab" data-bs-toggle="tab" data-bs-target="#run-reports-pane" type="button" role="tab" aria-controls="run-reports-pane" aria-selected="false">
                <span data-i18n="tab_reports">Reports</span>
            </button>
        </li>
        <!-- 2026-09-02, explicit request: "Tab ที่แสดงผลอยู่ตอนนี้มีส่วนไหนที่ยุบรวมกันได้" -- the
             "Payment Method Summary" tab that used to sit here (added 2026-08-31: a read-only
             Employee/Payment-Method/Base-Salary/Gross/Deduction/Net table with footer totals) was
             removed entirely -- this SAME round added a Payment Method column + Bank/Cash filter
             checkboxes directly onto the Details tab's own #tb_run_detail (which already had Base
             Salary/Gross/Deduction/Net + footer totals from before), making that separate tab a
             100%-redundant duplicate view of the exact same data. The Bank/Cash headcount cards
             that used to live in this tab are still available -- see #infoPaymentBreakdown inside
             the Details tab's own "Employees" stat card. -->
        <!-- 2026-08-31, explicit request: "ถ้าพนักงานรับเงินสด...แยก Report ตามแยก ว่าจ่ายเงินสดเท่าไหร่ โอน
             ผ่านธนาคารเท่าไหร่ และสามารถใส่ Status ว่าจ่ายแล้ว" -- interactive per-employee cash payment
             status, separate from the static Reports tab's own CASH_PAYMENT_SUMMARY export (see that
             report's own docblock on why both exist). -->
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="run-cash-tab" data-bs-toggle="tab" data-bs-target="#run-cash-pane" type="button" role="tab" aria-controls="run-cash-pane" aria-selected="false">
                <i class="fa-solid fa-money-bill-wave me-1"></i><span data-i18n="tab_cash_payments">Cash Payments</span>
            </button>
        </li>
        <!-- 2026-09-02, multi-bank-account payroll, explicit request: "ในหน้า Detail ก็สามารถเลือกได้ว่าใครจะ
             โอนผ่านบัญชีไหนในกลุ่มที่รับเงินผ่านบัญชี...ในหน้า Detail ของ Process เพิ่ม Tab ให้จัดการข้อมูลส่วนนี้ได้
             และมี Report แยกตามบัญชีที่จ่าย" -- one row per bank-paying employee, showing which of the
             company's OWN settlement accounts (bank_accounts) resolves for them (override > employee
             default > cycle pin > company default -- see PayrollRunEmployeeBankAccountModel's own
             docblock) plus a per-run override editor. Same "reports available after approval" posture
             as Cash Payments/Remittance right beside it. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="run-bank-account-tab" data-bs-toggle="tab" data-bs-target="#run-bank-account-pane" type="button" role="tab" aria-controls="run-bank-account-pane" aria-selected="false">
                <i class="fa-solid fa-building-columns me-1"></i><span data-i18n="tab_bank_account_assignment">Bank Account Assignment</span>
            </button>
        </li>
        <!-- 2026-09-02, Deduction Destination & Third-Party Remittance -- deduction lines routed to
             a company account, a third-party bank account, or a fallback employee whose deduction's
             payee wasn't part of this run get grouped into a batch here once the run is Approved
             (PayrollRemittanceModel::generateForRun()). Same "reports available after approval"
             posture as the Cash Payments tab right above (both only have real data once a run's
             numbers are final). -->
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="run-remittance-tab" data-bs-toggle="tab" data-bs-target="#run-remittance-pane" type="button" role="tab" aria-controls="run-remittance-pane" aria-selected="false">
                <i class="fa-solid fa-money-bill-transfer me-1"></i><span data-i18n="tab_remittance">Third-Party Remittance</span>
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
          <!-- 2026-09-09, explicit request: "ใน Tab Information เอา หัวข้อออกมาไว้นอก detail-section ครับ" --
               the section heading (numbered badge + title + any header-row action button) now sits
               ABOVE the bordered .detail-section card instead of inside its own padding, for both
               sections in this tab -- purely a markup/visual reorder, no ids moved, no JS changes
               needed either way. -->
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h6 class="text-secondary fw-bold mb-0">
                    <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                    <span data-i18n="run_info">Run Information</span>
                </h6>
                <div id="runEditButtonWrap"></div>
          </div>
          <div class="detail-section">
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
          <!-- 2026-09-09, explicit follow-up: "การตั้งค่าของรอบ หมายถึง margin จากกรอบของข้อ 1 ครับ ตอนนี้ไป
               ติดข้อ 1" -- clarifies the earlier margin fix targeted the wrong element. Moving the
               section headings OUTSIDE .detail-section (previous round) broke `.detail-section +
               .detail-section`'s own adjacent-sibling margin-top rule (style.css) -- a plain heading
               div now sits between the two .detail-section boxes, so they're no longer direct
               siblings and that rule never fires, leaving section 2's heading sitting flush against
               section 1's box with zero gap. mt-4 here (on the heading wrapper, not the panel inside
               it) is what actually recreates that spacing. -->
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 mt-4">
                <h6 class="text-secondary fw-bold mb-0">
                    <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                    <span data-i18n="run_settings_title">Run Settings</span>
                </h6>
          </div>
          <div class="detail-section">
            <!-- 2026-09-09, explicit request: "แยก Employee และการคำนวณไว้อีก Tab ครับ...ใน Tab แรกจะเป็นการ
                 ตั้งค่าทั้งหมด" -- Run Settings (Tax/SSO defaults + Exclude-from-Calculation) moved here
                 from the old "Employee Breakdown" section, which is now its own "Employee" tab (see
                 #run-employee-pane below) -- this stays with Run Information as config, not something
                 that redraws with the employee table.
                 2026-08-29, explicit request: "เพิ่มให้สามารถเลือกเอาเงินเดือนออกจากการคำนวณได้ หรือค่าอื่นๆที่ไม่
                 นำมาคำนวณ ทั้ง template เลย...และต้องกำหนดได้ด้วยว่าคำนวณภาษี ไม่คำนวณภาษี ส่งประกันสังคมไหม
                 กำหนดแบบทั้งหมด และรายบุคคลได้" -- whole-run defaults (a per-employee override lives in
                 each row's own "Items" button -> "Tax & SSO" tab instead, see manageLinesModal).
                 2026-08-29, same-day follow-up: "ในหน้า Process Detail แบบ View Mode จะต้องบอกรายละเอียด
                 ของการตั้งค่ารอบด้วยครับ" -- was hidden entirely once a run left draft; now ALWAYS
                 visible, read-only (every control disabled + Save hidden) once the run is no longer
                 draft -- see loadRunSettingsPanel()'s own docblock in detail.js.
                 2026-09-09, same-day follow-up: "การตั้งค่าของรอบ ให้ expand ได้เลยไม่ต้องหุบแล้ว เพราะมีพื้นที่
                 ว่างแล้วครับ" -- used to be collapsed by default (a click-to-reveal chevron toggle) since
                 it competed with the Employee table for space on the same tab; now that it's alone on
                 its own tab there's no more space pressure, so it's simply always expanded for a draft
                 run -- the collapse/chevron affordance (and its own click handler) was removed
                 outright, not just defaulted open (see loadRunSettingsPanel()'s own docblock in
                 detail.js for what changed there). -->
            <!-- 2026-09-09, explicit follow-up: "id runSettingsPanel ตัด class ทิ้งไปเลยครับ" -- the
                 border/rounded/padding/margin classes from the previous round were dropped outright
                 (not just the border/rounded part) -- `d-none` is the only class kept, since
                 renderRunSettingsPanel()/loadRunSettingsPanel() in detail.js still need it to hide
                 this whole block until the run's settings have actually loaded. The real top-spacing
                 fix lives on the section heading right above instead (see that div's own comment). -->
            <div class="d-none" id="runSettingsPanel">
                <div class="mt-3" id="runSettingsSummary"></div>
                <div class="d-none mt-3" id="runSettingsBody">
                    <p class="text-muted small mb-3" data-i18n="run_settings_hint">Default settings applied to every employee in this run -- an individual employee can still be adjusted from their own row's "Items" button.</p>
                    <div class="row g-3 mb-3">
                        <!-- 2026-08-29, same-day follow-up: "ปรับ radio group...ให้ดูสวยขึ้น หรือเป็นแค่
                             checkbox เรียงกัน 3 แถวหรือแถวเดียวกันแบบ Basic" -- was pill-styled btn-check
                             buttons (same treatment as the Comment Tag picker); switched to the
                             "Basic" alternative offered -- plain native radio inputs, one row
                             (wraps to multiple on narrow widths), meaning conveyed through
                             icon/label COLOR alone rather than a filled pill background. -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1" data-i18n="run_exemption_tax">Tax Calculation</label>
                            <div class="d-flex flex-wrap gap-3" id="runCalcTaxGroup">
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="runCalcTax" id="runCalcTaxInherit" value="use_employee_setting" checked>
                                    <label class="form-check-label small text-secondary" for="runCalcTaxInherit"><i class="fa-solid fa-users me-1"></i><span data-i18n="calc_default_use_employee">Each Employee's Own Setting</span></label>
                                </div>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="runCalcTax" id="runCalcTaxYes" value="yes">
                                    <label class="form-check-label small text-success fw-semibold" for="runCalcTaxYes"><i class="fa-solid fa-check me-1"></i><span data-i18n="run_calc_tax_yes">Calculate for Everyone</span></label>
                                </div>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="runCalcTax" id="runCalcTaxNo" value="no">
                                    <label class="form-check-label small text-danger fw-semibold" for="runCalcTaxNo"><i class="fa-solid fa-xmark me-1"></i><span data-i18n="run_calc_tax_no">Don't Calculate for Anyone</span></label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1" data-i18n="run_exemption_sso">SSO Contribution</label>
                            <div class="d-flex flex-wrap gap-3" id="runCalcSsoGroup">
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="runCalcSso" id="runCalcSsoInherit" value="use_employee_setting" checked>
                                    <label class="form-check-label small text-secondary" for="runCalcSsoInherit"><i class="fa-solid fa-users me-1"></i><span data-i18n="calc_default_use_employee">Each Employee's Own Setting</span></label>
                                </div>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="runCalcSso" id="runCalcSsoYes" value="yes">
                                    <label class="form-check-label small text-success fw-semibold" for="runCalcSsoYes"><i class="fa-solid fa-check me-1"></i><span data-i18n="run_calc_sso_yes">Send for Everyone</span></label>
                                </div>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="radio" name="runCalcSso" id="runCalcSsoNo" value="no">
                                    <label class="form-check-label small text-danger fw-semibold" for="runCalcSsoNo"><i class="fa-solid fa-xmark me-1"></i><span data-i18n="run_calc_sso_no">Don't Send for Anyone</span></label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold small mb-1" data-i18n="run_settings_excluded_items">Exclude from Calculation</label>
                        <!-- 2026-09-09, explicit request: "รายการที่ติ๊กจะไม่ถูกนำมาคำนวณ...ให้เป็นติ๊ก Default
                             ติ๊กออกคือไม่เอาครับ" -- checkbox meaning flipped: was "ticked = excluded"
                             (nothing ticked by default); now "ticked = included/calculated normally"
                             (every item defaults to ticked), untick an item to exclude it instead --
                             see loadRunSettingsPanel()/the Save click handler's own comment in
                             detail.js for where this is actually computed. Only the hint text below
                             and the checked/collect-on-save logic changed -- the STORED/SUBMITTED
                             `excluded_item_codes` shape is exactly the same as before (still literally
                             "the codes that are excluded"), so nothing else that reads it (the
                             per-employee "Exclude from This Employee's Calculation" checklist,
                             renderRunSettingsSummary()'s View Mode overview, the backend) needed to
                             change at all. -->
                        <div class="text-muted small mb-2" data-i18n="run_settings_excluded_items_hint">Ticked items are calculated normally for every employee in this run. Untick an item to leave it out (base salary and/or any earning/deduction item).</div>
                        <!-- 2026-08-29, same-day follow-up: "รายรับให้เป็นสีเขียว รายจ่ายให้เป็นสีแดง และ
                             แยกกรอบกันอยู่ครับ" -- built by itemChecklistBoxesHtml() in detail.js into 2
                             (or 3, incl. Base Salary) separate bordered boxes (plain white + colored
                             header text, same as this page's own Income/Deductions panels elsewhere)
                             instead of one flat grid. Items inside each box flow into 2-3 CSS
                             columns (.item-checklist-cols) -- no fixed max-height/scroll anymore
                             (same-day follow-up: "ไม่ต้องมี Scroll"), the box just grows to fit. -->
                        <div id="runSettingsItemChecklist"></div>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-sm btn-primary" id="btnSaveRunSettings"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
                    </div>
                </div>
            </div>
          </div>
        </div>

        <!-- 2026-09-09, explicit request: "แยก Employee และการคำนวณไว้อีก Tab ครับ และปรับให้เป็น Datatable" --
             the Employee Breakdown table (already a real client-side DataTable, see
             initRunDetailTable() in detail.js) plus everything that ACTS on it (auto-recalculate/
             recalc reminder banner/bulk Verify/the Payment Method filter) moved here from the
             "Details" tab into their own tab, so config (Details tab) and the actual per-employee
             results/actions (this tab) don't compete for the same screen. -->
        <!-- 2026-09-09, explicit request: "ตัด detail-section ออกไปเลยจาก Tab พนักงาน" -- was wrapped in
             the same .detail-section bordered card as "Details" tab's own sections; that box (border +
             its own 1.5rem padding) doubled up against #runDetailTabsContent's own newly-uniform
             tab-pane padding, boxing this content twice over. Dropped entirely -- content now sits
             directly in the tab-pane's own padding, matching the Cash Payments/Bank Account
             Assignment/Third-Party Remittance tabs, none of which ever used .detail-section either. -->
        <div class="tab-pane fade" id="run-employee-pane" role="tabpanel" aria-labelledby="run-employee-tab" tabindex="0">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h6 class="text-secondary fw-bold mb-0">
                    <span data-i18n="employee_breakdown">Employee Breakdown</span>
                    <!-- 2026-08-29, explicit request: "ตอน View Mode...อยากให้ปรับให้ดูเป็น View อยากเดียว
                         ...จะได้ดูแตกต่างจากตอนสร้างและแก้ไข" -- shown whenever currentRun.state !== 'draft'
                         (applyRunDetailViewMode() in detail.js), the one always-visible cue that this
                         run's Employee Breakdown is read-only, on top of the individual controls
                         (checkboxes, bulk bar, Verify/Lock, Manage Items) that already disable/hide
                         themselves per-control. -->
                    <span class="badge bg-secondary-subtle text-secondary ms-2 d-none" id="runDetailViewModeBadge"><i class="fa-solid fa-eye me-1"></i><span data-i18n="view_mode">View Mode</span></span>
                </h6>
                <!-- 2026-09-09, explicit request: "ย้ายปุ่มคำนวณใหม่...มาแสดงต่อ แสดง 50 รายการ" -- #btnRecalculate
                     no longer renders here; it's injected into the Employee table's own `.dt-length`
                     (initRunDetailTable()'s initComplete in detail.js), next to the "Show 50 entries"
                     control, same as the Join Employees button living in `.dt-search` on the other
                     side of that same row. -->
            </div>
            <!-- 2026-08-31, explicit request: "ต้องการให้มี Block เตือนว่า...ให้กดคำนวณใหม่ทุกครั้ง...และเพิ่ม
                 Function ให้มี checkbox ติ๊กว่าคำนวณอัตโนมัติหลังจากที่แก้ไขข้อมูลทันที...แต่ถ้าติ๊กคำนวณอัตโนมัติ
                 Recommend ให้กดจะไม่แสดง" -- the checkbox itself (persisted per-run, see
                 PayrollRunModel::setAutoRecalculate()) is ALWAYS visible so its current state is
                 never ambiguous; the reminder banner beneath toggles with it (renderRecalcReminder()
                 in detail.js) -- hidden while auto-recalculate is on, shown otherwise. Draft-only
                 (recalculate() itself is only ever meaningful for a draft run), same visibility gate
                 as #runRecalculateButtonWrap's own buttons. -->
            <div class="d-flex align-items-center gap-2 mb-2 d-none" id="autoRecalculateWrap">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="chkAutoRecalculate">
                    <label class="form-check-label small text-secondary" for="chkAutoRecalculate" data-i18n="auto_recalculate_label">Automatically recalculate right after editing data</label>
                </div>
            </div>
            <div class="alert alert-warning small d-none align-items-center gap-2 mb-3" id="recalcReminderBanner">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span data-i18n="recalc_reminder_message">If you've edited employee data or anything related to these numbers, click "Recalculate" every time to keep this run up to date.</span>
            </div>
            <div id="noDetailsYet" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-calculator fa-2x mb-3 text-secondary opacity-50"></i>
                <span data-i18n="no_details_yet">No employees calculated yet. Click "Recalculate" to compute this run.</span>
            </div>
            <!-- 2026-08-31, explicit request: "ก่อนตารางพนักงาน ให้มี checkbox ขึ้นมาเพื่อให้เลือกกรองข้อมูล
                 พนักงานที่รับผ่านบัญชี และเงินสดครับ" -- confirmed via AskUserQuestion: 2 independent
                 checkboxes (not a 3-way radio), both checked by default (= show everyone); unticking
                 one hides that group. Filters #tb_run_detail client-side against its own
                 payment_method_code column (see registerPaymentMethodSearchFilter() in detail.js) --
                 purely a view filter, changes nothing about the underlying data.
                 2026-09-09, explicit follow-up: "วิธีจ่ายเงิน ตัดออกครับ ไม่ใช่การตั้งค่า แต่ให้เพิ่มเป็น filter
                 ใน Tab employee" -- this had briefly moved to the "Details" tab (as its own numbered
                 section) in the same day's earlier tab-split round; moved back here, right above the
                 table it actually filters, since it's a view filter, not a saved run setting. The
                 checkbox ids are unchanged either way -- the Summary Cards above the tabs
                 (#runSummaryCards) still update live from this filter regardless of which tab it
                 lives on (see updateSummaryCardsFromTable()'s own docblock in detail.js). -->
            <!-- 2026-09-02, same-day follow-up: "ให้เลือกทั้งหมดได้ด้วย" -- filterPaymentAll is a plain
                 select-all checkbox (checks/unchecks both Bank and Cash together, see
                 syncPaymentMethodAllCheckbox() in detail.js), NOT a 3rd filter state of its own --
                 the actual filtering still only ever reads filterPaymentBank/filterPaymentCash (same
                 registerPaymentMethodSearchFilter() as before), so this stays a pure client-side
                 .draw() with no ajax/reload either way. -->
            <div class="d-flex align-items-center gap-3 mb-2" id="paymentMethodFilterWrap">
                <span class="small text-muted" data-i18n="table_payment_method">Payment Method</span>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="checkbox" id="filterPaymentAll" checked>
                    <label class="form-check-label small fw-semibold" for="filterPaymentAll" data-i18n="filter_all">All</label>
                </div>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="checkbox" id="filterPaymentBank" checked>
                    <label class="form-check-label small" for="filterPaymentBank" data-i18n="table_payment_bank">Bank Transfer</label>
                </div>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="checkbox" id="filterPaymentCash" checked>
                    <label class="form-check-label small" for="filterPaymentCash" data-i18n="table_payment_cash">Cash</label>
                </div>
            </div>
            <!-- 2026-08-29, explicit request: "สามารถมี checkbox เลือกได้ทีละหลายคนในการ Verify" -- Lock
                 retired 2026-08-31 (Verify itself now freezes recalculation).
                 2026-09-09, explicit follow-up across 3 rounds -- final layout: "เอาคำนวณใหม่ไปวางต่อ
                 search แล้วตามด้วย ปุ่ม Add พนักงาน...แล้วเอาปุ่ม Verify All มาไว้ต่อจาก ตรวจสอบแล้ว...และ
                 ปรับให้ขนาดปุ่มสูงเท่ากับช่อง search" -- every calculation-related button that used to
                 live in this tab as its own standalone row (Recalculate in the section header,
                 #runVerifyAllButtonWrap here, #runDetailBulkBar's amber .bulk-pull-bar box + its own
                 #btnBulkVerify) is now injected together into the Employee table's own
                 `.dt-search`/`.dt-length` control row instead (see initRunDetailTable()'s
                 initComplete in detail.js for the exact order/sizing) -- this whole row is retired,
                 not left as a dead empty wrapper. -->
            <!-- 2026-08-29, explicit request: "ตารางตรงพนักงาน ปรับให้แสดงเป็น 2 แถวแบบไม่ hide column
                 ไหมครับ เพราะ expand ดูไม่สะดวก" -- was 12 separate DataTables Responsive columns
                 (collapsing behind an expand-row toggle on narrower widths, per the user's own
                 report inconvenient). Employee (No.+Name) and Calculation (status+Remark) stay
                 consolidated into 2-line cells; Base Salary/Gross/Deduction/Net were split back into
                 their own columns in a same-day follow-up ("ตรงเงินได้เงินหักสุทธิ์...แยก Column ไปเลย")
                 since the combined version wasn't clear enough. responsive:false in detail.js's own
                 initRunDetailTable() means NOTHING ever hides behind an expand arrow either way; a
                 .table-responsive wrapper below gives a plain horizontal scrollbar as the only
                 narrow-viewport fallback, same as every other wide DataTable in this app.
                 2026-09-09: this table's own tab isn't the default-active one anymore (see the
                 tab-split comment above) -- a `shown.bs.tab` handler on #run-employee-tab calls
                 `.columns.adjust()` (detail.js) so column widths, which DataTables measures at
                 construction/redraw time, are recalculated correctly the first time this tab actually
                 becomes visible instead of staying sized for a 0-width hidden container. -->
            <div class="table-responsive">
            <table class="table table-hover table-border align-middle w-100 rd-detail-table-flush" id="tb_run_detail">
                <thead class="table-light text-secondary">
                    <tr>
                        <th class="text-center"><input type="checkbox" class="form-check-input" id="runDetailSelectAll"></th>
                        <!-- 2026-09-02, explicit request: "ตารางพนักงาน แยก code และชื่อคนละ Column Code
                             อยู่ก่อน" -- was one combined 2-line cell (name bold on top, code muted
                             underneath); split into its own Code column, placed before Name. -->
                        <th class="text-nowrap" data-i18n="employee_no">Employee Code</th>
                        <th class="text-nowrap" data-i18n="table_employee_name">Name</th>
                        <th class="text-nowrap" data-i18n="table_source">Source</th>
                        <!-- 2026-09-02, explicit request: "ในตารางพนักงานให้เพิ่ม Column รับเงินผ่านบัญชี หรือ
                             เงินสด" -- was only visible on the separate "Payment Method Summary" tab;
                             now also its own column here on the main Details table. -->
                        <th class="text-center text-nowrap" data-i18n="table_payment_method">Payment Method</th>
                        <th class="text-end text-nowrap" data-i18n="table_base_salary">Base Salary</th>
                        <th class="text-end text-nowrap" data-i18n="table_gross_amount">Gross</th>
                        <th class="text-end text-nowrap" data-i18n="table_deduction_amount">Deductions</th>
                        <th class="text-end text-nowrap" data-i18n="table_net_pay">Net Pay</th>
                        <th class="text-nowrap" data-i18n="table_calculation">Calculation</th>
                        <th class="text-center text-nowrap" data-i18n="table_verify_lock">Verify / Lock</th>
                        <!-- 2026-08-27, explicit request: blank out any "Action(s)" header, matches
                             the empty-header convention every other Actions column already uses. -->
                        <th class="text-center"></th>
                    </tr>
                </thead>
                <tbody></tbody>
                <!-- 2026-08-29, same-day follow-up: "ตอนนี้เหมือนมี Summary ด้านขวาเล็กๆ ให้ตัดออก...อยากให้มี
                     Summary ของแต่ละ Column ใน Footer" -- the small right-aligned summary strip below
                     the table (Employee/Verified/Locked counts) is retired; a real DataTables <tfoot>
                     now carries the SAME information (Employee count + Verified/Locked, in the
                     columns those concepts actually belong to) plus a running total for every
                     numeric money column (Base Salary/Gross/Deduction/Net), computed by
                     footerCallback in detail.js's own initRunDetailTable() -- respects the table's
                     own search filter (a filtered view sums only what's visible), same convention
                     DataTables' own footer-total examples use.
                     2026-09-02, explicit request: "Footer Column ตรวจสอบแล้ว ไม่เอา icon ให้ขึ้นว่าตรวจสอบแล้ว
                     n/n และ Column การคำนวณ คำนวณแล้ว n/n" -- rdFootVerifyLock dropped its icon in favor
                     of a plain "verified/total" count text, and the Calculation column (previously
                     blank in the footer) gets the same "calculated/total" treatment via the new
                     rdFootCalcStatus id. -->
                <tfoot class="table-light text-secondary">
                    <tr>
                        <th></th>
                        <th id="rdFootEmployeeCount"></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th class="text-end" id="rdFootBaseSalary"></th>
                        <th class="text-end" id="rdFootGross"></th>
                        <th class="text-end" id="rdFootDeduction"></th>
                        <th class="text-end" id="rdFootNet"></th>
                        <th id="rdFootCalcStatus"></th>
                        <th class="text-center" id="rdFootVerifyLock"></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>

        <!-- 2026-08-29, explicit request: "ใส่ Comment ได้ของแต่ละคน กดแล้วเปิดเป็น Modal ให้ใส่ Comment
             เรื่อยๆ เป็น Timeline...ให้มีใส่ tag ได้ว่า กำลังดำเนินการ ดำเนินการเสร็จแล้ว มีข้อผิดพลาด" -- own
             dedicated .apv-comment-* card design now (see renderEmployeeCommentTimeline()'s own
             docblock in detail.js), not the shared .apv-stage used elsewhere on this page.
             2026-08-29 same-day follow-up ("ช่วยปรับปรุง Design ทั้ง Form และ List ให้หน่อยครับ ย้าย Form
             มาไว้ Footer เพื่อถ้า Comment เยอะๆให้ค้างอยู่กับที่ แล้วใน Body ก็เลื่อนได้") -- the form (Tag
             picker + textarea) moved from the (scrollable) modal-body into the (fixed)
             modal-footer, alongside .modal-dialog-scrollable (already present) doing the rest: the
             list above keeps scrolling internally while this form + the action buttons stay pinned
             in view the whole time, even with a long comment history. -->
        <div class="modal fade" id="employeeCommentModal" data-footer="none" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary"><i class="fa-solid fa-comments me-2 text-brand"></i><span data-i18n="employee_comment_timeline_title">Comments</span> - <span id="employeeCommentModalEmployeeName"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="employeeCommentTimeline" class="apv-comment-list"></div>
                        <div id="employeeCommentEmpty" class="text-center text-muted small py-3 d-none" data-i18n="employee_comment_timeline_empty">No comments yet.</div>
                    </div>
                    <div class="modal-footer apv-comment-footer flex-column align-items-stretch">
                        <!-- 2026-08-29, explicit follow-up request: "ถ้าการดำเนินเสร็จแล้ว Comment ดูได้เท่านั้น
                             ไม่สามารถเพิ่ม แก้ไข ลบได้" -- shown instead of the form below once
                             commentsReadOnlyRd() (detail.js) is true, i.e. the run has reached a
                             genuinely finished state (paid/locked/cancelled -- see
                             PayrollRunModel::COMMENT_LOCKED_STATES's own docblock for why that's a
                             different, narrower cutoff than this page's general View Mode). -->
                        <div id="employeeCommentReadOnlyNotice" class="text-center text-muted small py-2 d-none"><i class="fa-solid fa-lock me-1"></i><span data-i18n="employee_comment_read_only">This payroll run has finished processing. Comments are view-only.</span></div>
                        <div id="employeeCommentFormArea">
                        <!-- 2026-08-29, explicit request: "ตรงใส่ Comment Tag ให้กดเลือกเป็น radio" -- was a
                             select2-static dropdown, now Bootstrap's btn-check/btn-outline-* radio-as-
                             button component (real <input type="radio"> underneath, styled as a
                             segmented toggle) so each tag's own color is visible without opening a
                             dropdown first. -->
                        <!-- 2026-08-29, same-day follow-up: "ตรงเลือก Tag ปรับให้สวยขึ้นอีกได้ไหมครับ" --
                             was a plain Bootstrap btn-check/btn-outline-* segmented toggle (flat
                             outline colors unrelated to the list's own tag colors); now icon+label
                             pill chips that share the EXACT same gradient palette as
                             employeeCommentTagBadge()/EMPLOYEE_COMMENT_TAG_META in detail.js, so the
                             picker and the rendered tag pill below it visually agree. Same
                             btn-check/radio ids/values/name -- zero JS changes needed, only the
                             <label> classes/content changed. -->
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1" data-i18n="employee_comment_tag">Tag</label>
                            <div class="apv-comment-tag-picker" id="employeeCommentTagGroup">
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagNone" value="" checked>
                                <label class="apv-comment-tag-option apv-comment-tag-opt-none" for="employeeCommentTagNone"><i class="fa-solid fa-comment-slash"></i><span data-i18n="employee_comment_tag_none">No tag</span></label>
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagInProgress" value="in_progress">
                                <label class="apv-comment-tag-option apv-comment-tag-opt-in_progress" for="employeeCommentTagInProgress"><i class="fa-solid fa-hourglass-half"></i><span data-i18n="employee_comment_tag_in_progress">In Progress</span></label>
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagCompleted" value="completed">
                                <label class="apv-comment-tag-option apv-comment-tag-opt-completed" for="employeeCommentTagCompleted"><i class="fa-solid fa-check"></i><span data-i18n="employee_comment_tag_completed">Completed</span></label>
                                <input type="radio" class="btn-check" name="employeeCommentTag" id="employeeCommentTagError" value="error">
                                <label class="apv-comment-tag-option apv-comment-tag-opt-error" for="employeeCommentTagError"><i class="fa-solid fa-triangle-exclamation"></i><span data-i18n="employee_comment_tag_error">Error</span></label>
                            </div>
                        </div>
                        <div class="mb-2">
                            <textarea class="form-control form-control-sm" id="employeeCommentText" rows="2" data-i18n="employee_comment_placeholder" placeholder="Write a comment..."></textarea>
                        </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-primary btn-sm" id="btnAddEmployeeComment"><i class="fa-solid fa-plus me-1"></i><span id="btnAddEmployeeCommentLabel" data-i18n="employee_comment_add">Add Comment</span></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnCancelEditEmployeeComment" data-i18n="cancel">Cancel</button>
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" data-i18n="close">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- 2026-08-29, same-day follow-up: "ตรงปุ่มออกรายงาน ให้ปรับเป็นเพิ่มอีก Tab ก่อน Action History
             และแสดงเป็นตารางรายการไว้ และบอกด้วยว่า Download แล้วทั้งหมดกี่ครั้ง ครั้งล่าสุด Download ไปเมื่อไหร่"
             -- one row per report shortcut applicable to this run (same TH_SSO110/TH_PND1/
             BANK_TRANSFER_FILE set + tax/SSO hiding the old dropdown already used -- see
             ReportsController::runReportsSummary()'s own docblock), not a DataTable (fixed set of
             at most 3 rows, same "small enough not to need it" precedent as the Manage Items
             modal's own Income/Deduction panels). Populated by loadRunReportsTab() in detail.js. -->
        <div class="tab-pane fade" id="run-reports-pane" role="tabpanel" aria-labelledby="run-reports-tab" tabindex="0">
            <!-- 2026-08-29, same-day follow-up: "ถ้า Process นั้นยังไม่สามารถออกรายงานได้ให้มีหมายเหตุขึ้นที่
                 บนหัวตารางครับ Design ให้สวยๆ" -- distinct from #runReportsNotReady below (that div's
                 own "zero rows at all" condition is effectively unreachable today -- BANK_TRANSFER_FILE
                 has no applicability gate, so the table always has at least one row -- kept as-is for
                 defense in depth). This banner is keyed purely on run state (isReady in
                 loadRunReportsTab()), shown ABOVE the table regardless of row count, reusing
                 .reports-period-bar's own visual language (icon-circle + gradient bar) in a
                 warning/amber tone instead of the brand-orange "pick a context" one, since this is
                 informational, not an action to take. -->
            <div class="reports-not-ready-banner mb-3 d-none" id="runReportsNotReadyBanner">
                <div class="reports-not-ready-banner-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                <div class="reports-not-ready-banner-body">
                    <div class="reports-not-ready-banner-title" data-i18n="reports_not_ready_title">Reports Not Available Yet</div>
                    <div class="reports-not-ready-banner-hint" data-i18n="reports_available_after_approval">Reports are available once this run is approved.</div>
                </div>
            </div>
            <div id="runReportsNotReady" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-file-export fa-2x mb-3 text-secondary opacity-50"></i>
                <span data-i18n="reports_available_after_approval">Reports are available once this run is approved.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100 d-none" id="tb_run_reports">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="report_name">Report</th>
                            <th class="text-center" data-i18n="download_count">Downloaded</th>
                            <th data-i18n="last_downloaded_at">Last Downloaded</th>
                            <th class="text-center"></th>
                        </tr>
                    </thead>
                    <tbody id="runReportsTableBody"></tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="run-cash-pane" role="tabpanel" aria-labelledby="run-cash-tab" tabindex="0">
            <div id="runCashNotReady" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-money-bill-wave fa-2x mb-3 text-secondary opacity-50"></i>
                <span id="runCashNotReadyMessage" data-i18n="reports_available_after_approval">Reports are available once this run is approved.</span>
            </div>
            <div id="runCashContent" class="d-none">
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-info h-100">
                            <div class="stat-card-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                            <div>
                                <div class="stat-card-label" data-i18n="total_cash_payment">Total Cash</div>
                                <div class="stat-card-value" id="runCashTotalCash">-</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-primary h-100">
                            <div class="stat-card-icon"><i class="fa-solid fa-building-columns"></i></div>
                            <div>
                                <div class="stat-card-label" data-i18n="total_bank_payment">Total Bank Transfer</div>
                                <div class="stat-card-value" id="runCashTotalBank">-</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th data-i18n="employee_no">Employee No.</th>
                                <th data-i18n="employee">Employee</th>
                                <th class="text-end" data-i18n="amount">Amount</th>
                                <th class="text-center" data-i18n="status">Status</th>
                                <th data-i18n="table_paid_at">Paid At</th>
                                <th class="text-center"></th>
                            </tr>
                        </thead>
                        <tbody id="runCashTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2026-09-02, multi-bank-account payroll -- see the tab button's own comment above. -->
        <div class="tab-pane fade" id="run-bank-account-pane" role="tabpanel" aria-labelledby="run-bank-account-tab" tabindex="0">
            <div id="runBankAccountNotReady" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-building-columns fa-2x mb-3 text-secondary opacity-50"></i>
                <span id="runBankAccountNotReadyMessage" data-i18n="reports_available_after_approval">Reports are available once this run is approved.</span>
            </div>
            <div id="runBankAccountContent" class="d-none">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="text-muted small" data-i18n="bank_account_assignment_hint">Which of the company's own settlement accounts pays each employee this run. Leave unassigned to use the employee's own default or the pay cycle/company default.</div>
                    <button type="button" class="btn btn-outline-success btn-sm" id="btnExportRunBankAccountSummary">
                        <i class="fa-solid fa-file-excel me-1"></i><span data-i18n="export_excel">Export Excel</span>
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th data-i18n="employee_no">Employee No.</th>
                                <th data-i18n="employee">Employee</th>
                                <th data-i18n="bank_account">Bank Account</th>
                                <th class="text-center" data-i18n="bank_account_source">Source</th>
                                <th class="text-center"></th>
                            </tr>
                        </thead>
                        <tbody id="runBankAccountTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 5 -- deduction lines
             routed to a company account, a saved third-party bank account, or a fallback employee
             not part of this run (PayrollRemittanceModel::generateForRun(), triggered right after
             this run is Approved) get grouped here, one row per destination. "company" rows are
             always already 'success' (no real external transfer -- kept for audit only); the other
             two types are real transfers that need Mark as Transferred (evidence upload) -> Confirm
             Success / Mark as Failed (with a reason, retry-able back to pending). Same layout
             convention as the Cash Payments tab right above (not-ready state + stat cards + table). -->
        <div class="tab-pane fade" id="run-remittance-pane" role="tabpanel" aria-labelledby="run-remittance-tab" tabindex="0">
            <div id="runRemittanceNotReady" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-money-bill-transfer fa-2x mb-3 text-secondary opacity-50"></i>
                <span id="runRemittanceNotReadyMessage" data-i18n="reports_available_after_approval">Reports are available once this run is approved.</span>
            </div>
            <div id="runRemittanceContent" class="d-none">
                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-outline-success btn-sm" id="btnExportRunRemittance">
                        <i class="fa-solid fa-file-excel me-1"></i><span data-i18n="export_excel">Export Excel</span>
                    </button>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-warning h-100">
                            <div class="stat-card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                            <div>
                                <div class="stat-card-label" data-i18n="remittance_status_pending">Pending</div>
                                <div class="stat-card-value" id="runRemittanceTotalPending">-</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-primary h-100">
                            <div class="stat-card-icon"><i class="fa-solid fa-paper-plane"></i></div>
                            <div>
                                <div class="stat-card-label" data-i18n="remittance_status_transferred">Transferred</div>
                                <div class="stat-card-value" id="runRemittanceTotalTransferred">-</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-success h-100">
                            <div class="stat-card-icon"><i class="fa-solid fa-circle-check"></i></div>
                            <div>
                                <div class="stat-card-label" data-i18n="remittance_status_success">Success</div>
                                <div class="stat-card-value" id="runRemittanceTotalSuccess">-</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card stat-card-danger h-100">
                            <div class="stat-card-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                            <div>
                                <div class="stat-card-label" data-i18n="remittance_status_failed">Failed</div>
                                <div class="stat-card-value" id="runRemittanceTotalFailed">-</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th data-i18n="remittance_destination">Destination</th>
                                <th data-i18n="remittance_destination_type">Type</th>
                                <th class="text-center" data-i18n="remittance_employee_count">Employees</th>
                                <th class="text-end" data-i18n="amount">Amount</th>
                                <th class="text-center" data-i18n="status">Status</th>
                                <th data-i18n="remittance_transferred_at">Transferred At</th>
                                <th class="text-center"></th>
                            </tr>
                        </thead>
                        <tbody id="runRemittanceTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Remittance breakdown modal -- lists every (employee, item) line that makes up one
             grouped remittance row's total, opened from that row's own "view breakdown" button. -->
        <!-- 2026-09-02, multi-bank-account payroll -- per-run override editor for one employee's
             paying account (PayrollRunEmployeeBankAccountModel::overrideSave()). Select2 ajax reuses
             the SAME api/payroll-cycle.bank-account.options endpoint Employee Detail's own
             #default_bank_account_id and Payroll Configuration's cycle-level picker already use --
             same company-scoped account list, no new endpoint needed. -->
        <div class="modal fade" id="bankAccountAssignModal" data-footer="form" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary" data-i18n="bank_account_assign_title">Assign Paying Account</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="bankAccountAssignEmployeeId">
                        <div class="mb-2">
                            <div class="text-muted small" data-i18n="employee">Employee</div>
                            <div class="fw-bold" id="bankAccountAssignEmployeeName">-</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label mb-1" data-i18n="bank_account">Bank Account</label>
                            <select class="form-select select2-remote" id="bankAccountAssignSelect" data-api="/api/payroll-cycle.bank-account.options" allow-clear="true"></select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label mb-1" data-i18n="note">Note</label>
                            <textarea class="form-control" id="bankAccountAssignNote" rows="2" data-i18n="notes_placeholder" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btnSaveBankAccountAssign" data-i18n="save">Save</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="remittanceBreakdownModal" data-footer="view" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary" data-i18n="remittance_breakdown_title">Remittance Breakdown</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle w-100">
                                <thead class="table-light text-secondary">
                                    <tr>
                                        <th data-i18n="employee_no">Employee No.</th>
                                        <th data-i18n="employee">Employee</th>
                                        <th data-i18n="item">Item</th>
                                        <th class="text-end" data-i18n="amount">Amount</th>
                                    </tr>
                                </thead>
                                <tbody id="remittanceBreakdownTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="close">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mark as Transferred modal -- required evidence file (jpg/png/pdf, 5MB cap, see
             PayrollRemittanceController::markTransferred()) uploaded via multipart/form-data. -->
        <div class="modal fade" id="remittanceMarkTransferredModal" data-footer="confirm" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary" data-i18n="mark_as_transferred">Mark as Transferred</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="remittanceMarkTransferredId">
                        <label class="form-label mb-1" data-i18n="remittance_evidence_file" for="remittanceEvidenceFile">Transfer Evidence (image or PDF)</label>
                        <input type="file" class="form-control" id="remittanceEvidenceFile" accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="button" class="btn btn-primary" id="btnConfirmMarkTransferred" data-i18n="confirm">Confirm</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mark as Failed modal -- a reason note is required (PayrollRemittanceModel::markFailed()). -->
        <div class="modal fade" id="remittanceMarkFailedModal" data-footer="confirm" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary" data-i18n="mark_as_failed">Mark as Failed</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="remittanceMarkFailedId">
                        <label class="form-label mb-1" data-i18n="remittance_failed_reason" for="remittanceFailedNote">Reason</label>
                        <textarea class="form-control" id="remittanceFailedNote" rows="3" data-i18n="remittance_failed_note_placeholder" placeholder="e.g., Bank rejected — incorrect account number"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="button" class="btn btn-danger" id="btnConfirmMarkFailed" data-i18n="confirm">Confirm</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview & Download modal -- opened from a report row's own button. The iframe only
             loads for a report that supports a PDF preview (row.supports_preview, see
             ReportsController::runReportsSummary()); a CSV-only report like the bank transfer file
             skips straight to the "preview unavailable, download directly" message. Preview itself
             (GET .../report.generate?preview=1) is deliberately NOT logged -- see that endpoint's
             own docblock -- only the Thai/English Download buttons below are real, counted
             downloads. -->
        <!-- 2026-08-29, same-day follow-up: "'ไฟล์ประเภทนี้ดูตัวอย่างไม่ได้' UI ไม่ค่อยสวยครับ และไม่พอดีกับ
             modal สูงเกินไป" -- the dialog itself now switches size (id="reportPreviewDialog", toggled
             in detail.js's own .btn-report-preview handler): modal-xl only while an actual PDF
             preview is loading/shown, a plain (smaller) centered dialog for a report with nothing to
             preview -- so the empty-state card isn't rattling around in an oversized XL modal. The
             card itself (icon-in-a-circle, title + subtext) replaces the old bare
             icon-over-one-line-of-text block. -->
        <div class="modal fade" id="reportPreviewModal" data-footer="view" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered" id="reportPreviewDialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary" id="reportPreviewModalTitle">-</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div id="reportPreviewLoading" class="text-center text-muted py-5"><i class="fa-solid fa-spinner fa-spin fa-2x"></i></div>
                        <iframe id="reportPreviewFrame" class="d-none" style="width:100%; height:70vh; border:0;" title="Report preview"></iframe>
                        <div id="reportPreviewUnavailable" class="text-center d-none py-4 px-4">
                            <div class="report-preview-unavailable-icon mx-auto mb-3">
                                <i class="fa-solid fa-file-circle-exclamation"></i>
                            </div>
                            <div class="fw-semibold text-secondary mb-1" data-i18n="report_preview_unavailable_title">Preview Not Available</div>
                            <div class="text-muted small" data-i18n="report_preview_unavailable">This file type can't be previewed -- download it directly below.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light me-auto" data-bs-dismiss="modal" data-i18n="close">Close</button>
                        <button type="button" class="btn btn-outline-secondary btn-report-download" data-language="th"><img src="<?=BASE_URL?>/public/flags/th.png" width="16" height="16" alt="TH" class="me-1"><span data-i18n="language_th">Thai</span></button>
                        <button type="button" class="btn btn-primary btn-report-download" data-language="en"><img src="<?=BASE_URL?>/public/flags/gb.png" width="16" height="16" alt="EN" class="me-1"><span data-i18n="language_en">English</span></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Download History modal -- opened from a report row's own "History" button. Reuses the
             EXISTING api/report.export-logs endpoint (report_code + payroll_run_id filters, see
             ReportExportLogModel::list()'s own docblock), showing every LOGGED (non-preview)
             download -- when, by whom, language, device/browser (parsed from the request's own
             User-Agent), IP, and source (which screen triggered it). -->
        <div class="modal fade" id="reportHistoryModal" data-footer="view" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title text-secondary" id="reportHistoryModalTitle">-</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- 2026-08-29, same-day follow-up: "ถ้า Tab ไหนมี Filter ช่วยปรับ Design Filter ให้เป็น
                             รูปแบบที่กำหนดไว้ของระบบ" -- was a bespoke d-flex row; now the same
                             .station-filter collapsible component every other filter in this app uses
                             (see Employee List's own Login History tab filter for the identical
                             pattern this was copied from: label + chevron-toggle button + a row of
                             fields, Clear Filter shown separately only once a filter is actually
                             active). The categorical columns (By/Language/Device/Browser/Source)
                             additionally get the system's per-column Excel-style filter
                             (initExcelColumnFilters(), see detail.js) instead of duplicating them here. -->
                        <div class="station-filter mb-2" id="reportHistoryStationFilter">
                            <span class="station-filter-label" data-i18n="label_filter">Filter</span>
                            <button type="button" class="station-filter-toggle" id="reportHistoryStationFilterToggle" title="Toggle filter">
                                <i class="fas fa-chevron-up"></i>
                            </button>
                            <div class="station-filter-body">
                                <div class="row g-2">
                                    <div class="col-6 col-md-4">
                                        <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_from">From</span></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control datepicker" id="reportHistoryDateFrom" autocomplete="off">
                                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label mb-1"><i class="fa-solid fa-calendar-days me-1 text-muted"></i><span data-i18n="filter_date_to">To</span></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control datepicker" id="reportHistoryDateTo" autocomplete="off">
                                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="station-filter-clear-row d-none" id="reportHistoryFilterClearRow">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnReportHistoryClearFilter">
                                <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle w-100" id="tb_report_history">
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
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="close">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2026-08-27, explicit request: "ในหน้า Process Detail Tab Action History ปรับจากตารางเป็น
             Timeline สวยๆ" -- was a plain DataTable (5 columns: Date/Time, Action, Status Change,
             Performed By, Note). Replaced with a vertical icon+connector-line timeline. 2026-08-29
             briefly redesigned into a boustrophedon/snake grid (explicit request), then reverted the
             SAME day back to vertical, briefly gaining a "View Detail" button+modal in that same
             round -- REMOVED again same-day per explicit follow-up ("หน้า ประวัติการดำเนินการ Detail
             ไม่เยอะไม่ต้องมีปุ่มกดดูก็ได้ครับ แสดงใน timeline ได้เลย"): every field that modal used to show
             (state change, note, IP/user-agent) is now rendered directly in each card instead. See
             renderAuditHistoryTimelineRd()/auditHistoryRowHtmlRd()'s own docblock in detail.js. -->
        <div class="tab-pane fade" id="run-history-pane" role="tabpanel" aria-labelledby="run-history-tab" tabindex="0">
            <div id="noAuditYet" class="text-center text-secondary py-4 d-none">
                <i class="fa-solid fa-clock-rotate-left fa-2x mb-3 text-secondary opacity-50"></i>
                <span data-i18n="no_history_yet">No action has been taken on this request yet.</span>
            </div>
            <div id="run_audit_timeline" class="apv-history-timeline"></div>
        </div>
    </div>

    <!-- Edit Run Modal -->
    <div class="modal fade" id="editRunModal" data-footer="form" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="editRunModalLabel" aria-hidden="true">
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
                        <!-- 2026-09-01, explicit request: "ในการดึงข้อมูลมาทำรอบที่ส่งมาจาก Origami รวมถึงการ
                             สร้างเอง ให้สามารถเลือกอ้างอิงรอบได้เหมือนตอน Origami และในหน้า Detail ก็สามารถ
                             แก้ไขเพิ่มได้ Form เหมือนหน้าสร้างเลยครับ" -- the Create form's own Payroll
                             Schedule picker (#run_cycle_id in #payrollRunModal), now also editable from
                             here. Confirmed via AskUserQuestion: cycle_id is a REAL, eligibility-
                             affecting field (recalculate()'s own cycle-based employee-matching keys off
                             it), not a soft reference label.
                             2026-09-01, same-day follow-up (explicit push-back: "เหตุผลอะไรบ้างในหน้า
                             Edit ที่ไม่สามารถแก้ไขได้ ควรเปิดให้แก้ไขได้") -- ALWAYS enabled now, no
                             blanket employee_count lock (see editRunCycleToggleRiskyRd() in detail.js
                             and PayrollRunModel::update()'s own matching comment for the precise
                             remaining risk: only flipping a non-sync run between off-cycle and
                             cycle-linked while it already has employees can silently drop manually-
                             joined ones -- switching between two real cycles, or on a sync-linked
                             run, is exactly as safe as editing period_start/period_end already is
                             with zero gating). #editRunCycleLockedHint now shows/hides live as the
                             admin picks, warning ONLY for that one specific risky transition, instead
                             of disabling the field outright. Clearable (allowClear) -- an empty
                             selection means "off-schedule/no cycle", the same either/or
                             #run_cycle_id itself represents on the Create form. -->
                        <!-- 2026-09-02, same-day follow-up, explicit request: "ยังไม่เหมือนหน้าเพิ่มรอบในหน้า
                             List ครับ ขาด รอบพิเศษนอกรอบเงินเดือน" then "พอมีแค่...ให้ติ๊กออกแล้วค่อยให้เลือก
                             รอบ...ดูงงๆ ช่วยเพิ่มเป็น radio ให้เลือก...ถ้าเลือก option 1 ให้ขึ้นรอบให้เลือก ถ้าเลือก
                             option 2 ไม่ขึ้นให้เลือก" -- mirrors #run_offcycle_row on the Create form 1:1
                             (same 2-option .run-choice-card radio, same i18n labels) instead of a single
                             checkbox whose "unchecked" state doubled as a double-negative AND the trigger
                             to reveal the cycle field. Hidden entirely for a sync-linked run (same
                             reasoning #run_offcycle_row is hidden for a Pull-sync create -- that data is
                             inherently cycle-based, or for a supplemental pull, optionally cycle-linked
                             via a DIFFERENT mechanism -- see updateEditRunTypeSectionRd()'s own
                             isSupplementalSync branch, untouched by this radio).
                             2026-09-02, 4th same-day follow-up, explicit request: "อยากให้แสดงเต็มแถวเลยครับ...
                             ถ้าเลือกตามรอบดูสวย แต่พอเลือกนอกรอบดูแหว่งๆ" -- dropped the col-sm-9/offset-sm-3
                             split here too, same reasoning as #run_offcycle_row on the Create form. -->
                        <div class="mb-3" id="edit_run_offcycle_row">
                            <div class="run-choice-toggle">
                                <label class="run-choice-card" for="edit_run_schedule_choice_cycle">
                                    <input class="form-check-input" type="radio" name="editRunScheduleChoice" id="edit_run_schedule_choice_cycle" value="cycle" checked>
                                    <span class="run-choice-card-icon"><i class="fa-solid fa-calendar-check"></i></span>
                                    <span class="run-choice-card-body">
                                        <span class="run-choice-card-label" data-i18n="run_schedule_choice_cycle">Follow a payroll schedule</span>
                                        <span class="run-choice-card-sub" data-i18n="run_schedule_choice_cycle_sub">Pick from your configured payroll schedules</span>
                                    </span>
                                </label>
                                <label class="run-choice-card" for="edit_run_schedule_choice_offcycle">
                                    <input class="form-check-input" type="radio" name="editRunScheduleChoice" id="edit_run_schedule_choice_offcycle" value="offcycle">
                                    <span class="run-choice-card-icon"><i class="fa-solid fa-money-bill-transfer"></i></span>
                                    <span class="run-choice-card-body">
                                        <span class="run-choice-card-label" data-i18n="offcycle_run_label">Off-schedule run</span>
                                        <span class="run-choice-card-sub" data-i18n="offcycle_run_label_sub">No payroll schedule needed -- e.g. an out-of-schedule payment</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="row mb-3" id="edit_run_cycle_row">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-1"><span data-i18n="modal_cycle">Payroll Schedule</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-remote" id="edit_run_cycle_id" name="cycle_id" data-api="/api/payroll-cycle.options"></select>
                                <div class="form-text small text-warning d-none" id="editRunCycleLockedHint" data-i18n="edit_run_cycle_locked_hint">This run already has calculated employees. Saving this change will automatically recalculate the run right away so the employee list stays accurate.</div>
                            </div>
                        </div>
                        <!-- 2026-09-01/02, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" then "ขาด...
                             เปิดรอบใหม่ อ้างอิงถึงรอบที่มีอยู่ และไม่ติ๊ก Auto" then "พอเป็น Design แบบเดียวกันแล้วดู
                             แปลกๆครับ ช่วย Design Form ให้ใหม่" -- merge_target_run_id (the "อ้างอิงถึงรอบ"
                             radio's own target) was set-once at creation with no way to change/clear it,
                             then a plain always-shown select with no "new vs reference" choice, then a
                             2nd big .run-choice-card block identical to the schedule cards right above it
                             (read as 2 equally-weighted top-level decisions) -- now a genuinely NESTED
                             sub-panel (.run-offcycle-panel, same design/reasoning as #run_offcycle_panel
                             on the Create form) with a compact .run-subchoice-toggle pill control inside,
                             defaulting to "new" (unticked/no reference) unless the run already genuinely
                             has one set (see #btnEditRun's own click handler in detail.js). Same
                             visibility gate as #edit_run_type_section right below (only meaningful for a
                             genuinely off-cycle run, updated live as the cycle dropdown above changes,
                             see updateEditRunTypeSectionRd() in detail.js). Target picker clearable
                             (allowClear) -- an empty selection when "reference" is chosen is invalid
                             (required), same as the Create form's own #run_merge_target_id. -->
                        <div class="run-offcycle-panel d-none" id="edit_run_offcycle_panel">
                            <div class="run-offcycle-panel-title"><i class="fa-solid fa-sliders"></i> <span data-i18n="run_offcycle_panel_title">Off-schedule round options</span></div>
                            <!-- 2026-09-09, round-creation flow audit Phase 3 -- same collapse as the Create
                                 form's own #run_merge_into_row (see that markup's own comment in modals.php):
                                 ONE flat 3-way choice instead of the OLD 2-level editRunMergeChoice(new/
                                 reference) + editRunMergeTargetMode(existing/future_cycle) nesting, driving
                                 the SAME underlying legacy radios below (now hidden) via
                                 syncEditRunMergeIntoUiRd() in detail.js. -->
                            <div class="row mb-3" id="edit_run_merge_into_row">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-1" data-i18n="run_merge_into_label">Fold this round into another one?</label>
                                </div>
                                <div class="col-sm-9">
                                    <div class="run-subchoice-toggle">
                                        <label class="run-subchoice-btn active" for="edit_run_merge_into_standalone">
                                            <input type="radio" name="editRunMergeInto" id="edit_run_merge_into_standalone" value="standalone" checked>
                                            <i class="fa-solid fa-file-circle-plus"></i>
                                            <span data-i18n="run_merge_into_standalone">Keep separate</span>
                                        </label>
                                        <label class="run-subchoice-btn" for="edit_run_merge_into_existing">
                                            <input type="radio" name="editRunMergeInto" id="edit_run_merge_into_existing" value="existing">
                                            <i class="fa-solid fa-link"></i>
                                            <span data-i18n="run_merge_into_existing">Merge into an existing round</span>
                                        </label>
                                        <label class="run-subchoice-btn" for="edit_run_merge_into_future_cycle">
                                            <input type="radio" name="editRunMergeInto" id="edit_run_merge_into_future_cycle" value="future_cycle">
                                            <i class="fa-solid fa-hourglass-half"></i>
                                            <span data-i18n="run_merge_into_future_cycle">Merge into a future round (matched by payment month)</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <!-- Legacy controls -- never shown, driven programmatically. -->
                            <div class="d-none">
                                <input type="radio" name="editRunMergeChoice" id="edit_run_merge_choice_new" value="new" checked>
                                <input type="radio" name="editRunMergeChoice" id="edit_run_merge_choice_reference" value="reference">
                            </div>
                            <div class="row mb-3 d-none" id="edit_run_merge_target_row">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-1" data-i18n="run_merge_target_label">Target Round</label>
                                </div>
                                <div class="col-sm-9">
                                    <div class="d-none">
                                        <input type="radio" name="editRunMergeTargetMode" id="edit_run_merge_target_mode_existing" value="existing" checked>
                                        <input type="radio" name="editRunMergeTargetMode" id="edit_run_merge_target_mode_future_cycle" value="future_cycle">
                                    </div>
                                    <div id="edit_run_merge_target_existing_wrap">
                                        <select class="form-select select2-remote" id="edit_run_merge_target_id" name="merge_target_run_id"
                                                data-api="/api/payroll-run.options" data-states="draft,pending_approval,approved,rejected,need_info,paid,locked" data-exclude-id=""></select>
                                        <div class="form-text small" data-i18n="run_merge_target_hint">Build this round up normally first (Join Employees / Manage Items) -- once ready, use "Merge into Target" above to fold it into the round selected here.</div>
                                    </div>
                                    <div class="d-none" id="edit_run_merge_target_future_cycle_wrap">
                                        <select class="form-select select2-remote mb-2" id="edit_run_merge_target_cycle_id" name="merge_target_cycle_id"
                                                data-api="/api/payroll-cycle.options"></select>
                                        <div class="row g-2">
                                            <!-- 2026-09-09, real bug found and fixed (explicit report: "Date เลือกไม่ได้")
                                                 -- same fix as index.js's own Create form: both fields used to be
                                                 `readonly` with no `.datepicker` class, auto-filled ONLY from picking
                                                 a Target cycle above, with no way to adjust by hand. Now real,
                                                 editable datepickers -- see initDatepicker() calls in detail.js. -->
                                            <div class="col-6">
                                                <label class="form-label mb-1 small" data-i18n="run_merge_target_period_start">Target Period Start</label>
                                                <input type="text" class="form-control datepicker" id="edit_run_merge_target_period_start" name="merge_target_period_start_date">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label mb-1 small" data-i18n="run_merge_target_period_end">Target Period End</label>
                                                <input type="text" class="form-control datepicker" id="edit_run_merge_target_period_end" name="merge_target_period_end_date">
                                            </div>
                                        </div>
                                        <div class="form-text small" data-i18n="run_merge_target_future_cycle_hint">The system will wait for the next round of this Payroll Cycle to be created, then automatically prompt you to merge into it.</div>
                                        <!-- 2026-09-09, round-creation flow audit Bug 2 fix -- same read-only
                                             preview as the Create form's own #run_merge_target_preview_box (see
                                             that markup's own comment in modals.php), refreshed live by
                                             detail.js as the cycle/period above change, re-checked again right
                                             before Save. -->
                                        <div class="d-none mt-2" id="edit_run_merge_target_preview_box">
                                            <div class="alert alert-secondary small mb-2 d-none py-2" id="edit_run_merge_target_preview_none"></div>
                                            <div class="alert alert-info small mb-2 d-none py-2" id="edit_run_merge_target_preview_single"></div>
                                            <div class="d-none" id="edit_run_merge_target_preview_multi">
                                                <label class="form-label mb-1 small text-danger" data-i18n="run_merge_target_preview_multi_label">More than one existing round matches -- pick which one this should merge into:</label>
                                                <select class="form-select form-select-sm" id="edit_run_merge_target_preview_select"></select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-1"><span data-i18n="modal_run_name">Run Name</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <input type="text" class="form-control required" id="edit_run_name" name="run_name" data-i18n="run_name_placeholder" placeholder="e.g., Payroll July 2026">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-1"><span data-i18n="modal_period_start">Period Start Date</span> <span class="text-danger">*</span></label>
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
                                <label class="form-label mb-1"><span data-i18n="modal_payment_date">Payment Date</span> <span class="text-danger">*</span></label>
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
                            <!-- 2026-09-09, round-creation flow audit Phase 3 -- same heavier .run-choice-card
                                 restyle as the Create form's own #run_purpose_choice_row (see that markup's
                                 own comment in modals.php). NO hard-block here though (unlike Create) --
                                 opening Edit always has a REAL, previously-chosen currentRun.run_purpose to
                                 reflect (a genuinely new off-cycle run already went through Create's own
                                 hard-block before it could exist at all), so #btnEditRun's own populate code
                                 in detail.js pre-selects the matching card instead of leaving both unpicked. -->
                            <div class="mb-3" id="edit_run_purpose_choice_row">
                                <label class="form-label mb-2" data-i18n="run_purpose_choice_label">What does this payment cover?</label>
                                <div class="run-choice-toggle">
                                    <label class="run-choice-card" for="edit_run_purpose_choice_payroll">
                                        <input class="form-check-input" type="radio" name="editRunPurposeChoice" id="edit_run_purpose_choice_payroll" value="payroll">
                                        <span class="run-choice-card-icon"><i class="fa-solid fa-sack-dollar"></i></span>
                                        <span class="run-choice-card-body">
                                            <span class="run-choice-card-label" data-i18n="run_purpose_choice_payroll_label">Full payroll payment</span>
                                            <span class="run-choice-card-sub" data-i18n="run_purpose_choice_payroll_sub">Same as normal payroll -- full base salary, statutory, and standing items, just off-schedule</span>
                                        </span>
                                    </label>
                                    <label class="run-choice-card" for="edit_run_purpose_choice_incentive">
                                        <input class="form-check-input" type="radio" name="editRunPurposeChoice" id="edit_run_purpose_choice_incentive" value="incentive">
                                        <span class="run-choice-card-icon"><i class="fa-solid fa-gift"></i></span>
                                        <span class="run-choice-card-body">
                                            <span class="run-choice-card-label" data-i18n="run_purpose_choice_incentive_label">Incentive / partial payment</span>
                                            <span class="run-choice-card-sub" data-i18n="run_purpose_choice_incentive_sub">Base salary, statutory, and standing items are each opt-in below</span>
                                        </span>
                                    </label>
                                </div>
                                <input type="hidden" id="edit_run_purpose" name="run_purpose">
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
                            <div class="row mb-3 d-none" id="edit_run_include_attendance_pay_row">
                                <div class="col-sm-9 offset-sm-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_run_include_attendance_pay">
                                        <label class="form-check-label" for="edit_run_include_attendance_pay" data-i18n="include_attendance_pay_label">Include attendance-driven earnings (OT/trip allowance), calculated automatically</label>
                                    </div>
                                </div>
                            </div>
                            <!-- 2026-09-09, real bug found and fixed (round-creation flow audit, Bug 1)
                                 -- this field existed on the Create form (modals.php's own
                                 #run_use_flat_tax_rate_row) but was never ported here, so a
                                 supplemental run's flat-tax-rate opt-in had no way to even be
                                 DISPLAYED on Edit, let alone resaved -- see #btnEditRun's own populate
                                 code below (reads currentRun.use_flat_tax_rate directly, does NOT
                                 recompute the Create form's own "pre-check when Origami attributed
                                 tax_treatment='separate'" default -- that default only makes sense
                                 the FIRST time this choice is made; Edit must reflect what was
                                 actually saved) and PayrollRunModel::update()'s own matching fix. -->
                            <div class="row mb-3 d-none" id="edit_run_use_flat_tax_rate_row">
                                <div class="col-sm-9 offset-sm-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_run_use_flat_tax_rate">
                                        <label class="form-check-label" for="edit_run_use_flat_tax_rate" data-i18n="use_flat_tax_rate_label">Withhold tax at the company's configured flat rate (Payroll Policy tab), instead of average/actual</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-1"><span data-i18n="modal_notes">Notes</span></label>
                            </div>
                            <div class="col-sm-9">
                                <textarea class="form-control" id="edit_notes" name="notes" rows="2" data-i18n="notes_placeholder" placeholder="Optional notes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
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
    <div class="modal fade" id="manageLinesModal" data-footer="view" data-bs-backdrop="static" tabindex="-1" aria-labelledby="manageLinesModalLabel" aria-hidden="true">
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
                        <!-- 2026-08-29, generalized from sync-only (explicit request: "ในหน้าทำจ่าย
                             น่าจะเปิดให้แก้ไขตัวเลขได้...ทุกค่าเลย") -- no longer toggled d-none for a
                             non-sync run, see detail.js's own openManageLinesModal()-equivalent
                             comment on why. -->
                        <li class="nav-item" id="manageLinesSyncOverrideTabWrap" role="presentation">
                            <button class="nav-link" id="manageLinesSyncOverrideTab" data-bs-toggle="tab" data-bs-target="#manageLinesSyncOverridePane" type="button" role="tab">
                                <i class="fa-solid fa-sliders me-1"></i><span data-i18n="manage_items_tab_adjustments">Deduction Adjustments</span>
                            </button>
                        </li>
                        <!-- 2026-08-29, explicit request: "กำหนดได้สำหรับพนักงานรายบุคคล ติ๊กเอาหรือไม่เอา...
                             และต้องกำหนดได้ด้วยว่าคำนวณภาษี ไม่คำนวณภาษี ส่งประกันสังคมไหม" -- moved here
                             (universal, every draft-run employee row) from the sync-only Raw Sync Data
                             modal's own "This Run's Settings" card, which only ever opened for a
                             data_source='sync' row. -->
                        <!-- 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6 --
                             per-run override of which account a recurring deduction (Employee
                             Detail's own "Recurring Deductions" section) is routed to, without
                             touching that employee's own saved template. Always shown (a run with no
                             recurring deductions for this employee just shows the empty state, same
                             convention as "Deduction Adjustments" above). -->
                        <li class="nav-item" id="manageLinesRecurringDestTabWrap" role="presentation">
                            <button class="nav-link" id="manageLinesRecurringDestTab" data-bs-toggle="tab" data-bs-target="#manageLinesRecurringDestPane" type="button" role="tab">
                                <i class="fa-solid fa-money-bill-transfer me-1"></i><span data-i18n="manage_items_tab_recurring_dest">Recurring Deduction Destination</span>
                            </button>
                        </li>
                        <li class="nav-item" id="manageLinesCalcTabWrap" role="presentation">
                            <button class="nav-link" id="manageLinesCalcTab" data-bs-toggle="tab" data-bs-target="#manageLinesCalcPane" type="button" role="tab">
                                <i class="fa-solid fa-file-invoice-dollar me-1"></i><span data-i18n="manage_items_tab_calc">Tax &amp; SSO</span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom p-3">
                        <div class="tab-pane fade show active" id="manageLinesItemsPane" role="tabpanel">
                            <div class="add-manual-line-card border rounded-3 p-3 bg-light bg-opacity-50 mb-4">
                                <div class="mb-2">
                                    <!-- 2026-09-03, Manual Entry / Platform UX review Phase 6: same
                                         redesign as #eedModal's own mode toggle (see that markup's own
                                         comment + style.css's .mode-select-group docblock) -- these are
                                         the only 2 places this exact "choose from list / specify
                                         manually / other" pattern exists in the app. -->
                                    <div class="mode-select-group" role="group" id="manualLineModeToggle">
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
                                        <!-- 2026-09-02, Deduction Destination & Third-Party Remittance,
                                             Phase 7 -- reuses #manualLineCustomFields' own free-text
                                             input verbatim, same as #eedModal's own "Other" mode (see
                                             that modal's markup comment); is_other=true is the only
                                             difference sent on submit. -->
                                        <button type="button" class="mode-select-btn" data-mode="other">
                                            <i class="fa-solid fa-circle-question"></i>
                                            <span class="mode-select-btn-title" data-i18n="manual_line_mode_other">Other</span>
                                            <span class="mode-select-btn-desc" data-i18n="mode_desc_other">Grouped into "Other Income/Deduction" on reports</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="row g-2 align-items-end" id="manualLineCatalogFields">
                                    <div class="col-12">
                                        <label class="form-label small text-muted mb-1" data-i18n="select_item_placeholder">Select an income/deduction item</label>
                                        <select class="form-select select2-remote" id="manualLineItemSelect" data-api="/api/employee.earning-deduction.options"></select>
                                    </div>
                                </div>
                                <div class="row g-2 align-items-end d-none" id="manualLineCustomFields">
                                    <div class="col-sm-8">
                                        <label class="form-label small text-muted mb-1" data-i18n="modal_custom_item_name">Item Name</label>
                                        <input type="text" class="form-control" id="manualLineCustomName" maxlength="150" data-i18n="modal_custom_item_name_placeholder" placeholder="e.g. Uniform deposit refund">
                                    </div>
                                    <div class="col-sm-4">
                                        <label class="form-label small text-muted mb-1" data-i18n="modal_item_type">Type</label>
                                        <select class="form-select select2-static" id="manualLineCustomType" data-option-keys="breakdown_earnings,table_deduction_amount" data-option-values="earning,deduction"></select>
                                    </div>
                                </div>
                                <div class="row g-2 align-items-end mt-1">
                                    <div class="col-sm-6">
                                        <label class="form-label small text-muted mb-1" data-i18n="modal_amount">Amount</label>
                                        <input type="number" class="form-control" id="manualLineAmount" min="0.01" step="0.01" placeholder="0.00">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label small text-muted mb-1" data-i18n="modal_comment">Comment</label>
                                        <input type="text" class="form-control" id="manualLineComment" maxlength="255" data-i18n="modal_comment_placeholder" placeholder="e.g. August OT shortfall top-up">
                                    </div>
                                </div>
                                <!-- Transfer-to-payee (2026-08-21, explicit request: "หักเพื่อไปจ่ายให้ใคร
                                     โดยเลือกพนักงานได้ว่าจะหักของคนนี้ไปให้คนนี้") -- only meaningful when
                                     the item being added is a deduction, toggled alongside the existing
                                     earning/deduction type preview (updateManualLineTypePreviewRd() in
                                     detail.js).
                                     2026-08-31, same-day follow-up: widened to the SAME 4-way
                                     None/Employee/Company/Not-Disbursed payee_type toggle Employee
                                     Detail's own #eedPayeeTypeToggle already has (this modal never had
                                     any payee-routing concept beyond the bare employee picker until
                                     now). Employee picker reuses /api/employee.report_to.get
                                     (data-exclude-id set to the employee this modal is currently
                                     managing) rather than a new endpoint. -->
                                <div class="row g-2 align-items-end mt-1 d-none" id="manualLinePayeeTypeWrapper">
                                    <div class="col-12">
                                        <label class="form-label small text-muted mb-1" data-i18n="payee_type_label">Deducted Money Goes To</label>
                                        <div class="btn-group btn-group-sm flex-wrap" role="group" id="manualLinePayeeTypeToggle">
                                            <button type="button" class="btn btn-outline-brand active" data-payee-type="none"><span data-i18n="payee_type_none">Employee's Own Net Pay</span></button>
                                            <button type="button" class="btn btn-outline-brand" data-payee-type="employee"><span data-i18n="payee_type_employee">Another Employee</span></button>
                                            <button type="button" class="btn btn-outline-brand" data-payee-type="company"><span data-i18n="payee_type_company">Company Account</span></button>
                                            <!-- 2026-09-02, Deduction Destination & Third-Party Remittance -->
                                            <button type="button" class="btn btn-outline-brand" data-payee-type="other_person"><span data-i18n="payee_type_other_person">Other Person / Third Party</span></button>
                                            <button type="button" class="btn btn-outline-brand" data-payee-type="not_disbursed"><span data-i18n="payee_type_not_disbursed">Deducted, No Cash Movement (Write-off)</span></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-2 align-items-end mt-1 d-none" id="manualLinePayeeWrapper">
                                    <div class="col-12">
                                        <label class="form-label small text-muted mb-1" data-i18n="payee_employee_label">Payee Employee (transfer to)</label>
                                        <select class="form-select select2-remote" id="manualLinePayeeEmployee" data-api="/api/employee.report_to.get" data-type="employee"></select>
                                    </div>
                                </div>
                                <!-- 2026-09-02, Deduction Destination & Third-Party Remittance -- pick an
                                     existing SAVED destination, or leave blank and fill the new-account
                                     fields below (which create a one-off or, with the checkbox, a new
                                     saved destination -- see PaymentDestinationModel::resolveOrCreate()).
                                     This is metadata attached to the deduction line only -- it never
                                     affects Net Pay or the calculation itself (see this feature's own
                                     "Calculation vs Disbursement layer" design note). -->
                                <div class="row g-2 align-items-end mt-1 d-none" id="manualLineDestinationWrapper">
                                    <div class="col-12">
                                        <label class="form-label small text-muted mb-1" data-i18n="destination_saved_label">Select a Saved Destination (optional)</label>
                                        <select class="form-select select2-remote" id="manualLineDestinationSelect" data-api="/api/payment-destination.options" data-type="payment_destination" allow-clear="true"></select>
                                    </div>
                                    <div class="col-12 mt-2" id="manualLineDestinationNewFields">
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small text-muted mb-1" data-i18n="destination_account_name">Account Name</label>
                                                <input type="text" class="form-control form-control-sm" id="manualLineDestAccountName" data-i18n="destination_account_name_placeholder" placeholder="e.g., Somchai Jaidee">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small text-muted mb-1" data-i18n="destination_account_no">Account No.</label>
                                                <input type="text" class="form-control form-control-sm" id="manualLineDestAccountNo" data-i18n="destination_account_no_placeholder" placeholder="e.g., 1234567890">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small text-muted mb-1" data-i18n="destination_bank">Bank</label>
                                                <select class="form-select select2-remote" id="manualLineDestBank" data-api="/api/bank.get" data-type="bank"></select>
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small text-muted mb-1" data-i18n="destination_bank_branch">Branch</label>
                                                <input type="text" class="form-control form-control-sm" id="manualLineDestBankBranch" data-i18n="destination_bank_branch_placeholder" placeholder="e.g., Central World Branch">
                                            </div>
                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input" id="manualLineDestSaveForReuse">
                                                    <label class="form-check-label small" for="manualLineDestSaveForReuse" data-i18n="destination_save_for_reuse">Save this destination for reuse next time</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-2 align-items-end mt-1 d-none" id="manualLineIncludeCashSummaryWrapper">
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="manualLineIncludeCashSummary" checked>
                                            <label class="form-check-label small" for="manualLineIncludeCashSummary" data-i18n="include_in_cash_summary_label">Include in Cash Payment Summary Report</label>
                                        </div>
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
                            <!-- 2026-08-29, explicit follow-up request: "อยากให้มี List รายการและติ๊กเข้าออก
                                 ได้เหมือนตอนที่ Set ทั้ง Template" -- a checklist for THIS employee only,
                                 same visual/interaction pattern as the run-wide "Run Settings" panel's own
                                 checklist (base salary + full catalog, tick to exclude, one Save button)
                                 instead of having to open each item's row individually below. Backed by
                                 the SAME payroll_run_line_overrides 'exclude' mechanism as the per-row
                                 list further down -- this is just a faster, bulk way to set it, not a
                                 separate concern. An item already excluded by the run-level default (Run
                                 Settings panel) shows pre-checked and disabled here, since there's no
                                 "force this one item back in" action distinct from typing a specific
                                 override amount in the per-row list below (see
                                 PayrollRunModel::recalculate()'s own docblock on this known,
                                 accepted simplification). -->
                            <div class="border rounded-3 p-3 bg-light bg-opacity-50 mb-3">
                                <h6 class="text-secondary fw-bold mb-1"><i class="fa-solid fa-list-check me-1"></i><span data-i18n="employee_item_exclusion_title">Exclude from This Employee's Calculation</span></h6>
                                <div class="text-muted small mb-2" data-i18n="employee_item_exclusion_hint">Ticked items are left out of this employee's calculation for this run. Greyed-out items are already excluded by this run's own Run Settings default.</div>
                                <div id="empItemExclusionChecklist"></div>
                                <div class="text-end mt-2">
                                    <button type="button" class="btn btn-sm btn-primary" id="btnSaveEmpItemExclusion"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
                                </div>
                            </div>
                            <hr>
                            <p class="text-muted small mb-2" data-i18n="sync_line_override_hint">Override the computed amount, or exclude it entirely, for this run only.</p>
                            <div id="syncLineOverrideList"></div>
                        </div>
                        <div class="tab-pane fade" id="manageLinesRecurringDestPane" role="tabpanel">
                            <p class="text-muted small mb-2" data-i18n="recurring_dest_override_hint">Override which account a recurring deduction is routed to, for this payroll run only -- the employee's own saved default is never changed.</p>
                            <div id="recurringDestOverrideList"></div>
                            <div class="border rounded-3 p-3 bg-light bg-opacity-50 mt-3 d-none" id="recurringDestEditorCard">
                                <input type="hidden" id="recurringDestEditorRecurringId">
                                <div class="fw-bold text-dark small mb-2" id="recurringDestEditorItemName"></div>
                                <div class="btn-group btn-group-sm flex-wrap mb-2" role="group" id="recurringDestPayeeTypeToggle">
                                    <button type="button" class="btn btn-outline-brand" data-payee-type="employee"><span data-i18n="payee_type_employee">Another Employee</span></button>
                                    <button type="button" class="btn btn-outline-brand" data-payee-type="company"><span data-i18n="payee_type_company">Company Account</span></button>
                                    <button type="button" class="btn btn-outline-brand" data-payee-type="other_person"><span data-i18n="payee_type_other_person">Other Person / Third Party</span></button>
                                    <button type="button" class="btn btn-outline-brand" data-payee-type="not_disbursed"><span data-i18n="payee_type_not_disbursed">Deducted, No Cash Movement (Write-off)</span></button>
                                </div>
                                <div class="row g-2 align-items-end d-none" id="recurringDestEmployeeWrapper">
                                    <div class="col-12">
                                        <label class="form-label small text-muted mb-1" data-i18n="payee_employee_label">Payee Employee (transfer to)</label>
                                        <select class="form-select select2-remote" id="recurringDestPayeeEmployeeSelect" data-api="/api/employee.report_to.get" data-type="employee"></select>
                                    </div>
                                </div>
                                <div class="row g-2 align-items-end d-none" id="recurringDestDestinationWrapper">
                                    <div class="col-12">
                                        <label class="form-label small text-muted mb-1" data-i18n="destination_saved_label">Select a Saved Destination (optional)</label>
                                        <select class="form-select select2-remote" id="recurringDestDestinationSelect" data-api="/api/payment-destination.options" data-type="payment_destination" allow-clear="true"></select>
                                    </div>
                                    <div class="col-12 mt-2" id="recurringDestDestinationNewFields">
                                        <div class="row g-2">
                                            <div class="col-sm-6"><label class="form-label small mb-1" data-i18n="destination_account_name">Account Name</label><input type="text" class="form-control form-control-sm" id="recurringDestAccountName" data-i18n="destination_account_name_placeholder" placeholder="e.g., Somchai Jaidee"></div>
                                            <div class="col-sm-6"><label class="form-label small mb-1" data-i18n="destination_account_no">Account No.</label><input type="text" class="form-control form-control-sm" id="recurringDestAccountNo" data-i18n="destination_account_no_placeholder" placeholder="e.g., 1234567890"></div>
                                            <div class="col-sm-6"><label class="form-label small mb-1" data-i18n="destination_bank">Bank</label><select class="form-select select2-remote" id="recurringDestBank" data-api="/api/bank.get" data-type="bank"></select></div>
                                            <div class="col-sm-6"><label class="form-label small mb-1" data-i18n="destination_bank_branch">Branch</label><input type="text" class="form-control form-control-sm" id="recurringDestBankBranch" data-i18n="destination_bank_branch_placeholder" placeholder="e.g., Central World Branch"></div>
                                            <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="recurringDestSaveForReuse"><label class="form-check-label small" for="recurringDestSaveForReuse" data-i18n="destination_save_for_reuse">Save this destination for reuse next time</label></div></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelRecurringDestEdit" data-i18n="cancel">Cancel</button>
                                    <button type="button" class="btn btn-sm btn-primary" id="btnSaveRecurringDestOverride" data-i18n="save">Save</button>
                                </div>
                            </div>
                        </div>
                        <!-- 2026-08-29: per-employee, per-run tax/SSO calculation override -- see
                             PayrollRunModel::saveEmployeeExemption()'s own docblock. "Follow Run
                             Default" (inherit) is the initial state for every employee until this run's
                             own "Run Settings" panel and/or this control are actually touched. -->
                        <div class="tab-pane fade" id="manageLinesCalcPane" role="tabpanel">
                            <p class="text-muted small mb-3" data-i18n="employee_calc_override_hint">Set whether tax/SSO is calculated for this employee, for this run only -- overrides this run's own default (Run Settings panel) for this one person.</p>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1" data-i18n="run_exemption_tax">Tax Calculation</label>
                                <div class="d-flex flex-wrap gap-3" id="empCalcTaxGroup">
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="empCalcTax" id="empCalcTaxInherit" value="inherit" checked>
                                        <label class="form-check-label small text-secondary" for="empCalcTaxInherit"><i class="fa-solid fa-arrow-rotate-left me-1"></i><span data-i18n="calc_override_inherit">Follow Run Default</span></label>
                                    </div>
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="empCalcTax" id="empCalcTaxYes" value="yes">
                                        <label class="form-check-label small text-success fw-semibold" for="empCalcTaxYes"><i class="fa-solid fa-check me-1"></i><span data-i18n="calc_override_yes">Calculate</span></label>
                                    </div>
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="empCalcTax" id="empCalcTaxNo" value="no">
                                        <label class="form-check-label small text-danger fw-semibold" for="empCalcTaxNo"><i class="fa-solid fa-xmark me-1"></i><span data-i18n="calc_override_no">Don't Calculate</span></label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1" data-i18n="run_exemption_sso">SSO Contribution</label>
                                <div class="d-flex flex-wrap gap-3" id="empCalcSsoGroup">
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="empCalcSso" id="empCalcSsoInherit" value="inherit" checked>
                                        <label class="form-check-label small text-secondary" for="empCalcSsoInherit"><i class="fa-solid fa-arrow-rotate-left me-1"></i><span data-i18n="calc_override_inherit">Follow Run Default</span></label>
                                    </div>
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="empCalcSso" id="empCalcSsoYes" value="yes">
                                        <label class="form-check-label small text-success fw-semibold" for="empCalcSsoYes"><i class="fa-solid fa-check me-1"></i><span data-i18n="sso_override_yes">Send</span></label>
                                    </div>
                                    <div class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="radio" name="empCalcSso" id="empCalcSsoNo" value="no">
                                        <label class="form-check-label small text-danger fw-semibold" for="empCalcSsoNo"><i class="fa-solid fa-xmark me-1"></i><span data-i18n="sso_override_no">Don't Send</span></label>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-sm btn-primary" id="btnSaveEmpCalcOverride"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Breakdown Modal: per-employee itemized view for one payroll_run_details row, split into
         clearly-labeled Earnings / Deductions / Statutory sections so it's unambiguous which line
         is income and which is a deduction (the main table only shows totals). -->
    <div class="modal fade" id="runDetailBreakdownModal" data-footer="view" tabindex="-1" aria-labelledby="runDetailBreakdownModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-secondary mb-0" id="runDetailBreakdownModalLabel">
                            <i class="fa-solid fa-list-check me-1"></i><span data-i18n="breakdown_title">Calculation Breakdown</span>
                        </h5>
                        <div class="text-muted small" id="breakdownEmployeeName"></div>
                        <!-- 2026-09-06, explicit request: display Origami's opt-in TOTAL_DAYS
                             item_values entry (calendar-based day count) when present -- hidden
                             entirely for a run/employee with no data (cycle-based/off-cycle run, or
                             a sync run whose admin never ticked this Report Item on), see
                             PayrollRunModel::getDetails()'s own docblock. -->
                        <div class="text-muted small d-none" id="breakdownTotalDays"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="breakdownModalBody"></div>
                <!-- Net Pay pinned in the footer (2026-08-20, explicit request) -- with
                     modal-dialog-scrollable above, the body scrolls internally while this stays
                     visible, so a long Earnings/Deductions/Statutory list never pushes it out of
                     view.
                     2026-09-09, explicit request: "ย้ายยอดจ่ายสุทธิ มาต่อกัน Net Pay...ไปอยู่ขวาสุด" --
                     was `justify-content-between` (label pinned at the footer's LEFT edge, value at
                     the RIGHT edge, spread across the whole footer width); now `justify-content-end`
                     + `gap-2` groups label+value together as one unit at the far right instead. -->
                <div class="modal-footer d-flex justify-content-end align-items-center gap-2">
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
    <div class="modal fade" id="rawSyncDataModal" data-footer="view" tabindex="-1" aria-labelledby="rawSyncDataModalLabel" aria-hidden="true">
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
                    <!-- 2026-08-29: the per-run tax/SSO Settings card that used to live here moved to
                         the "Tax & SSO" tab of the universal Manage Items modal (this modal's own
                         Items button, .btn-manage-manual-lines) -- it needed to be reachable for
                         EVERY employee, not just sync-sourced rows this modal only ever opens for
                         (see manageLinesModal's own manageLinesCalcPane). This viewer is read-only
                         again, matching its original single purpose. -->
                    <div id="rawSyncDataModalBody"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="close">Close</button>
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
    <div class="modal fade" id="joinEmployeesModal" data-footer="form" data-bs-backdrop="static" tabindex="-1" aria-labelledby="joinEmployeesModalLabel" aria-hidden="true">
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
                            <label class="form-label mb-1"><i class="fa-solid fa-sitemap me-1 text-muted"></i><span data-i18n="department">Department</span></label>
                            <select class="form-select select2-remote" id="joinFilterDepartment" data-api="/api/department.get" data-type="department"></select>
                        </div>
                        <!-- 2026-08-24, explicit request ("ในการดึงพนักงานเข้ามาเพื่อคำนวณเงินเดือน ให้มี
                             Filter ส่วนที่เพิ่มเมื่อสักครู่ด้วยครับ") -- same Team filter just added to
                             Employee List. -->
                        <div class="col-sm-2">
                            <label class="form-label mb-1"><i class="fa-solid fa-people-group me-1 text-muted"></i><span data-i18n="team">Team</span></label>
                            <select class="form-select select2-remote" id="joinFilterTeam" data-api="/api/team.get" data-type="team"></select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label mb-1"><i class="fa-solid fa-briefcase me-1 text-muted"></i><span data-i18n="position">Position</span></label>
                            <select class="form-select select2-remote" id="joinFilterPosition" data-api="/api/position.get" data-type="position"></select>
                        </div>
                        <!-- 2026-08-22, explicit request ("ตรง Join Employee อยากให้เพิ่ม Filter
                             รอบเงินเดือนได้ด้วย") -- filters by the employee's own standing payroll
                             cycle (employees.cycle_id), not this run's own cycle. -->
                        <div class="col-sm-3">
                            <label class="form-label mb-1"><i class="fa-solid fa-calendar-check me-1 text-muted"></i><span data-i18n="payroll_cycle">Payroll Schedule</span></label>
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
                        <button type="button" class="btn btn-primary" id="btnJoinSelected" disabled>
                            <i class="fa-solid fa-user-plus me-1"></i><span data-i18n="action_join_employees">Join Employees</span>
                        </button>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve/Reject/Request Info modals (2026-08-22, explicit request) -- single-run versions
         of the Approval Queue page's own bulk-capable modals (deliberately duplicated, not shared,
         same "keep the already-working page untouched" convention as approval.js's own comments
         explain), scoped to PAYROLL_RUN_ID since this page only ever acts on the one run it's on. -->
    <div class="modal fade" id="runApproveModal" data-footer="confirm" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runApproveModalLabel" aria-hidden="true">
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
                        <label class="form-label mb-1" data-i18n="approve_note_label">Note (optional)</label>
                        <textarea class="form-control" id="run_approve_note" rows="3" data-i18n="approve_note_placeholder" placeholder="Any comment for this approval..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-success"><span data-i18n="approval_confirm_approve">Confirm Approve</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="runRejectModal" data-footer="confirm" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runRejectModalLabel" aria-hidden="true">
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
                        <label class="form-label mb-1"><span data-i18n="reject_reason_label">Reject Reason</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="run_reject_reason" rows="3" data-i18n="reject_reason_placeholder" placeholder="Explain what needs to be fixed before resubmitting..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-danger"><span data-i18n="approval_confirm_reject">Confirm Reject</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="runRequestInfoModal" data-footer="confirm" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runRequestInfoModalLabel" aria-hidden="true">
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
                        <label class="form-label mb-1"><span data-i18n="request_info_reason_label">What information is needed?</span> <span class="text-danger">*</span></label>
                        <textarea class="form-control required" id="run_request_info_reason" rows="3" data-i18n="request_info_reason_placeholder" placeholder="Explain what additional information is needed before this can be decided..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
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
    <div class="modal fade" id="runMarkPaidModal" data-footer="confirm" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="runMarkPaidModalLabel" aria-hidden="true">
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
                            <label class="form-label mb-1"><span data-i18n="payment_method_label">Payment Method</span> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static required" id="run_mark_paid_method" data-option-keys="payment_method_bank_transfer,payment_method_cash,payment_method_cheque" data-option-values="bank_transfer,cash,cheque"></select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label mb-1" data-i18n="payment_reference_label">Payment Reference</label>
                            <input type="text" class="form-control" id="run_mark_paid_reference" autocomplete="off" data-i18n="run_mark_paid_reference_placeholder" placeholder="e.g., Bank transfer batch no.">
                        </div>
                        <div class="mb-1">
                            <label class="form-label mb-1" data-i18n="modal_payment_date">Payment Date</label>
                            <input type="text" class="form-control datepicker" id="run_mark_paid_date" autocomplete="off">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
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
    <div class="modal fade" id="runTimelineModal" data-footer="view" tabindex="-1" aria-labelledby="runTimelineModalLabel" aria-hidden="true">
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
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

</div>
<script>
    const PAYROLL_RUN_ID = <?=(int)$runId?>;
</script>
<script src="<?=asset('public/js/payroll/detail.js')?>"></script>
