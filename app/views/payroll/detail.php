<div class="container container-body">
    <!-- 2026-09-13, Phase Design Round 3 item 3a (Payroll Detail pilot, rules.md §2) -- page-header.php
         replaces the OLD 3-piece header (a plain `.payroll-breadcrumb` <h5>, a separate STATIC
         `.page-header-card` identity block, and a bespoke run-name/status/export-buttons row) with
         ONE shared component. Real consequences of this, not just a markup swap:
         - The static identity header's own generic title/description
           (payroll_run_detail_title/_description) is GONE -- $title is now the run's own name (a
           real value, not a category label), matching the decided spec "H1 = ชื่อรอบ". The 2 i18n
           keys themselves are left in th.json/en.json (harmless if unused; not deleted, in case a
           future page still wants that generic wording).
         - $title/breadcrumb-current/description/actions are all UNKNOWN at server-render time (this
           page's real data loads via api/payroll-run.get, not server-side PHP) -- every one of them
           starts as a placeholder here and gets filled by renderRunHeader()/renderRunHeaderActions()
           (detail.js) once that fetch resolves, via the stable ids page-header.php now documents
           its own docblock (#phBreadcrumbCurrent/#phTitle/#phTitleBadge/#phDescription/#phActions).
         - The state-dependent action buttons that used to render INSIDE the process-timeline itself
           (timelineStepActionsHtml(), now renderRunHeaderActions()) moved here instead -- confirmed
           decision: "stepper เป็น 'สถานะ' ล้วน ไม่มีปุ่มฝังอีก" (§2/§6, applies to every future page with
           a stepper, not just this one). #btnExportRunRegister/#btnPreviewRunRegisterPdf (unchanged
           ids/click handlers, both delegated on `document`) now live inside the header's own
           "ส่งออก ▾" secondary dropdown instead of as 2 standalone buttons.
         - Breadcrumb's old non-link "Home" crumb (`.bc-root`, icon+label, never a real link) has NO
           equivalent icon slot in page-header.php (§2: "ไม่มีไอคอนหน้า" applies to breadcrumbs too, not
           just page titles) -- rendered here as a plain, non-clickable label only, matching every
           other real page's own breadcrumb convention already established elsewhere in the app
           (page-header.php was never designed with a "Home" icon crumb in mind to begin with; no
           other Round-4 candidate page's breadcrumb has one either, confirmed via grep). -->
    <?php
    $title = '-';
    // 2026-09-13, §2 REVISED (supersedes the previous "crumb สุดท้าย = ชนิดหน้า" decision entirely, not
    // just this page's own use of it) -- the last crumb is now the entity's own CODE (here, the run's
    // `run_code`), not a generic static page-type label -- $title (the H1) is the run's own DISPLAY
    // NAME instead. Both are unknowable until api/payroll-run.get resolves (same as before), so this
    // crumb is a placeholder here too now, filled in by renderRunHeader() (detail.js) alongside $title
    // -- see that function's own comment for the exact fallback chain on each.
    $breadcrumb = [
        ['label' => 'Payroll', 'href' => null, 'i18n' => 'payroll'],
        ['label' => 'Payroll Process', 'href' => BASE_URL . '/payroll-process', 'i18n' => 'payroll_process'],
        ['label' => '-', 'href' => null],
    ];
    // $primary_action/$secondary_actions/$overflow_actions deliberately omitted (null/empty) --
    // entirely state-dependent, unknowable until api/payroll-run.get resolves. renderRunHeaderActions()
    // (detail.js) populates #phActions via renderPageHeaderActions() (app.js) once it does, and again
    // after every state-changing action (submit/approve/reject/mark paid/lock/reopen/...).
    $description = null;
    include __DIR__ . '/../partials/page-header.php';
    ?>

    <!-- 2026-09-09, explicit request: "ตรง Timeline ในหน้า Process Detail เอา card-surface mb-4 ออกครับ" --
         was the same .card-surface treatment every other content block on this page uses; removed
         here specifically, per this explicit request, leaving a plain unstyled wrapper.
         2026-09-13, Round 3 item 3a: #rejectReasonBox/#cancelReasonBox (were siblings of the old
         run-name heading) moved to sit here instead, directly under the stepper -- page-header.php
         has no slot for an inline reason/warning box (nor should it grow one just for this; a status
         reason belongs with the status stepper below the title, not the page identity above it). -->
    <div>
        <div class="text-danger small mb-2 d-none" id="rejectReasonBox"></div>
        <div class="text-muted small mb-2 d-none" id="cancelReasonBox"></div>
        <!-- 2026-09-13, Round 3 item 3a follow-up fix -- `.process-timeline-wrap` (card border/
             background/padding) removed: a real miss caught in review, this page's own stepper still
             looked like the OLD bespoke `.process-timeline` design (card-wrapped) even after
             renderProcessTimeline() (detail.js) was fixed to call the real status-stepper.php/
             renderStatusStepper() component, which is explicitly card-free (§6). No wrapper class at
             all now -- #runProcessTimeline is just a plain container renderStatusStepper() fills. -->
        <div id="runProcessTimeline"></div>
        <!-- §15, item B (2026-09-13): a real callout.php-shaped box (not a page-specific banner class
             anymore) -- renderRunHeader() (detail.js) sets both its class (`callout callout-{tone}`,
             via calloutHtml()) and its content, or leaves it `d-none` with empty content when the
             current state's map entry gives back no text at all. -->
        <div id="nextStepBanner" class="d-none"></div>
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
    <!-- 2026-09-13, Round 3 item 3a -- markup switched from the old colored-edge `.stat-card`/
         `.stat-card-{tone}` classes to stat-card.php's plain `.stat`/`.stat-head`/`.stat-icon`/
         `.stat-value`/`.stat-footer`/`.stat-sub` shape (§2: "ไม่มีขอบสี, ไอคอนเดี่ยวสีเดียว"). NOT
         rendered via an `include stat-card.php` loop, though -- confirmed gap, reported separately:
         that partial replaces a card's ENTIRE content from one `$stat` array per render, but these 4
         values update INDIVIDUALLY and live (updateSummaryCardsFromTable(), on every table redraw,
         NOT a full page reload) via direct `.text()` calls on each value's own stable id -- forcing
         that through a whole-array re-render would mean either rebuilding all 4 array literals in JS
         just to change one number, or adding several id-passthrough params to the partial for a
         single, narrow caller. Hand-written here with the EXACT SAME CSS classes the partial itself
         outputs instead, so the visual result is identical either way -- ids preserved unchanged,
         `.num` added to each value span per §8 ("ตัวเลขทุกที่ใช้ .num") -- the OLD markup never had it
         on these 3 money values, `fmtNum()`'s own tabular-nums alignment was simply missing here
         before. `mt-4`/`mb-4` here = --sp-5 (item A.1: callout->stat and stat->tabs are both --sp-5 --
         Bootstrap's own 4-scale spacer happens to equal that token exactly, 1.5rem = 24px = --sp-5, so
         no bespoke class was needed here).
         2026-09-13, item D -- `.money-gross`/`.money-deduction`/`.money-net` (§8) added to the 3 money
         values below (Employees isn't a money value, gets neither). -->
    <div class="row g-3 mt-4 mb-4" id="runSummaryCards">
        <div class="col-6 col-md-3">
            <div class="stat">
                <div class="stat-head">
                    <div class="stat-label" data-i18n="table_employee_count">Employees</div>
                    <i class="fa-solid fa-users stat-icon"></i>
                </div>
                <div class="stat-value num" id="infoEmployeeCount">-</div>
                <div class="stat-footer">
                    <span class="stat-sub" id="infoPaymentBreakdown"></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat">
                <div class="stat-head">
                    <div class="stat-label" data-i18n="table_gross_amount">Gross</div>
                    <i class="fa-solid fa-sack-dollar stat-icon"></i>
                </div>
                <div class="stat-value num money-gross" id="infoGross">-</div>
                <div class="stat-footer stat-footer-empty"></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat">
                <div class="stat-head">
                    <div class="stat-label" data-i18n="table_deduction_amount">Deductions</div>
                    <i class="fa-solid fa-minus stat-icon"></i>
                </div>
                <div class="stat-value num money-deduction" id="infoDeduction">-</div>
                <div class="stat-footer stat-footer-empty"></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat">
                <div class="stat-head">
                    <div class="stat-label" data-i18n="table_net_pay">Net Pay</div>
                    <i class="fa-solid fa-hand-holding-dollar stat-icon"></i>
                </div>
                <div class="stat-value num money-net" id="infoNet">-</div>
                <div class="stat-footer stat-footer-empty"></div>
            </div>
        </div>
    </div>

    <!-- 2026-09-20, 3e-1 round 1 (rules.md 15): every run-level announcement in this header region is
         a callout now, not a solid `.alert` tile -- the "next step" box right above (#nextStepBanner)
         has been one since 3a, and two boxes that say the same KIND of thing must not be two
         different shapes. Left border carries the tone; the copy, the ids and every handler below are
         untouched. JS toggles `d-none` on these (not attr('class')), so the tone class can live here
         in the markup. -->
    <!-- 2026-09-21, 3e-2a: the 4 run-level boxes below were siblings with no spacing of their own --
         two that happened to be visible at once rendered as ONE box with a 2-coloured left edge, and the last one sat flush against the tab bar. The
         wrapper owns the rhythm (column flex + gap), NOT .callout, which is shared with every other
         page. A `d-none` child is display:none, so it is not a flex item at all and its gap
         disappears with it -- nothing here reserves space for a box that is not on screen, and a run
         with no banners keeps exactly the spacing it had before this wrapper existed. ids, classes,
         tone classes and every handler are untouched. -->
    <div class="rd-run-banners">
    <div class="callout callout-danger d-none" id="validationErrorsBanner"></div>
    <!-- 2026-08-30 (Phase 8, T041): reconciliation warning for a sync-based run -- employees who
         would normally be expected in payroll but weren't in this Origami sync payload and nobody
         manually joined them either. Advisory only (alert-warning, not alert-danger) -- never blocks
         submit, just a prompt to verify before doing so. See PayrollRunModel::syncMissingEmployees(). -->
    <div class="callout callout-warning d-none" id="syncMissingEmployeesBanner">
        <!-- The flex row moves INSIDE the callout: `calloutHtml()`/callout.php own the box (padding,
             radius, left border), the caller owns what sits in it -- the same "caller-authored
             content" contract the partial documents. No shared component changed. The button is
             `btn-sm` neutral (rules.md 4): it opens a list, it is not the decision on this screen. -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span id="syncMissingEmployeesBannerText"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="syncMissingEmployeesViewBtn" data-i18n="view_list">View List</button>
        </div>
    </div>
    <!-- 2026-09-23, B1: the mirror-image case `#syncMissingEmployeesBanner` above can never contain --
         employees Origami DID send in this run's sync payload but who never reached the run because
         they're marked not a payroll participant. See PayrollRunModel::syncMappedNotParticipants().
         `neutral` tone (rules.md §15 has no `info` tone -- confirmed with the user 2026-09-23): this
         is advisory information, not a warning, and using `warning` here would read as the same
         severity as the missing-employees box right above it, which is a real reconciliation concern
         while this one is just a fact about roster setup -- see docs/decisions/2026-09-23-sync-not-
         participant-banner.md. Same caller-authored-content contract as the box above, no shared
         component changed. -->
    <div class="callout callout-neutral d-none" id="syncNotParticipantBanner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span id="syncNotParticipantBannerText"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="syncNotParticipantViewBtn" data-i18n="view_list">View List</button>
        </div>
    </div>
    <!-- 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรือ
         อ้างอิงถึงรอบ" -- shown whenever this run was created with "อ้างอิงถึงรอบ" ticked
         (payroll_runs.merge_target_run_id set) and is still a draft, off-cycle run (matches
         PayrollRunModel::mergeIntoExistingRun()'s own eligibility check server-side). Build this
         run up normally first (Join Employees/Manage Items below), then click the button here
         when ready -- folds this run's resolved amounts into the target and soft-deletes this one
         (same mechanics/confirmation dance as the Pending-Pull table's own "Merge into Target"
         action, see PayrollRunModel::performRunMerge()'s own docblock). -->
    <!-- Same conversion as the 2 boxes above. `alert-info` was also the last BLUE surface in this
         region (rules.md 3: blue is not used) -- this box announces the next step for a run that was
         created against a merge target, so `primary` is its tone, the same one #nextStepBanner uses
         for "still moving forward". #btnMergeIntoTarget keeps its own look for now (it is a real
         primary action, and its icon is a separate rules.md 4 question -- see BACKLOG).
         2026-09-21, 3e-2b: `primary` -> `neutral`. This box states a FACT about how the run was
         created (it has a merge target), and the thing to do about it is the button inside it --
         which is already the orange one. An orange left edge behind an orange button is the same
         signal twice, and it left #nextStepBanner, the box that really does say "this is the next
         step", competing with it for the one meaning primary carries (§15/§0.1). -->
    <div class="callout callout-neutral d-none" id="mergeTargetBanner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span id="mergeTargetBannerText"></span>
            <button type="button" class="btn btn-sm btn-primary" id="btnMergeIntoTarget"><i class="fa-solid fa-code-merge me-1"></i><span data-i18n="btn_merge_sync">Merge into Target</span></button>
        </div>
    </div>
    <!-- 2026-09-06: the "future cycle" merge-target form -- no button here at all (there is
         nothing to merge into yet), just a status line; see renderMergeTargetBanner()'s own
         docblock for how this and #mergeTargetBanner above stay mutually exclusive. -->
    <!-- The icon goes with the `.alert` (rules.md 15: a callout has no icon, the left border is the
         signal). renderMergeTargetBanner() still swaps this box between its warning and danger tone,
         now by swapping `callout-warning`/`callout-danger` instead of `alert-*`. -->
    <div class="callout callout-warning d-none" id="mergeTargetWaitingBanner">
        <span id="mergeTargetWaitingBannerText"></span>
    </div>
    </div>

    <!-- 2026-09-13, Round 3 item 3a (§6: "Tabs ไม่มีไอคอน") -- icons stripped from the 5 tab buttons
         that still had one (Details/Employee Breakdown/Cash Payments/Bank Account Assignment/Third-
         Party Remittance/Action History; Reports already had none, see its own 2026-09-09 comment
         below). Labels/ids/data-bs-target/tab-pane CONTENT are all untouched -- every tab still
         opens exactly as before (§0.7: this round only touches how the tab BAR looks, not what's
         inside any tab-pane other than Employee Breakdown's own, done separately in 3b/3c). -->
    <ul class="nav nav-tabs" id="runDetailTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary active" id="run-details-tab" data-bs-toggle="tab" data-bs-target="#run-details-pane" type="button" role="tab" aria-controls="run-details-pane" aria-selected="true">
                <span data-i18n="tab_run_details">Details</span>
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
                <span data-i18n="employee_breakdown">Employee Breakdown</span>
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
                <span data-i18n="tab_cash_payments">Cash Payments</span>
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
                <span data-i18n="tab_bank_account_assignment">Bank Account Assignment</span>
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
                <span data-i18n="tab_remittance">Third-Party Remittance</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-secondary" id="run-history-tab" data-bs-toggle="tab" data-bs-target="#run-history-pane" type="button" role="tab" aria-controls="run-history-pane" aria-selected="false">
                <span data-i18n="tab_action_history">Action History</span>
            </button>
        </li>
    </ul>
    <!-- 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "ตัด card/ขอบที่ครอบ .tab-content
         ของ Detail ออกทุก tab" -- `border-top-0 bg-white rounded-bottom` (the utility-class half of the
         card look, its other half was a #runDetailTabsContent CSS rule in style.css, also retired)
         dropped; only `mb-5` (bottom margin before whatever follows this tab group) stays, unrelated
         to the card styling itself. See style.css's own comment on #runDetailTabsContent/the per-pane
         padding rules that replace this for the full reasoning + §6's new rule. -->
    <div class="tab-content mb-5" id="runDetailTabsContent">
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
                 each row's own "ตั้งค่ารายบุคคล" button -> "Tax & SSO" tab instead).
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
                                    <label class="form-check-label small fw-semibold" for="runCalcTaxYes"><i class="fa-solid fa-check me-1"></i><span data-i18n="run_calc_tax_yes">Calculate for Everyone</span></label>
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
                                    <label class="form-check-label small fw-semibold" for="runCalcSsoYes"><i class="fa-solid fa-check me-1"></i><span data-i18n="run_calc_sso_yes">Send for Everyone</span></label>
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
            <!-- 2026-09-13, Round 3 item 3b, explicit instruction: "ตัดหัวข้อซ้ำออก (tab บอกแล้ว)" -- the
                 "Employee Breakdown" h6 heading was a duplicate of the tab button's own label right
                 above it; removed entirely. #btnRecalculate itself is NOT here -- injected into the
                 Employee table's own `.dt-length` (initRunDetailTable()'s initComplete in detail.js),
                 next to "Show 50 entries", same as the Join Employees button.
                 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "ตัด callout 'โหมดดูอย่างเดียว'
                 ออกทั้งหมด (สถานะรอบ + ปุ่มที่หายไปบอกอยู่แล้ว)" -- the callout this comment used to
                 describe (#runDetailViewModeCallout, added earlier this same round replacing an even
                 older badge) is gone entirely now, not replaced by anything -- the run's own status
                 (stepper/badge in the page header) plus each individual control's own per-control
                 disable/hide (checkboxes, bulk bar, Verify/Lock, Manage Items all already gate on
                 currentRun.state !== 'draft' on their own) already say "this is read-only" without a
                 redundant banner repeating it. applyRunDetailViewMode() in detail.js no longer touches
                 any callout -- see that function's own comment. -->
            <!-- 2026-08-31, explicit request: "ต้องการให้มี Block เตือนว่า...ให้กดคำนวณใหม่ทุกครั้ง...และเพิ่ม
                 Function ให้มี checkbox ติ๊กว่าคำนวณอัตโนมัติหลังจากที่แก้ไขข้อมูลทันที...แต่ถ้าติ๊กคำนวณอัตโนมัติ
                 Recommend ให้กดจะไม่แสดง" -- the checkbox itself (persisted per-run, see
                 PayrollRunModel::setAutoRecalculate()) is ALWAYS visible so its current state is
                 never ambiguous; the reminder banner beneath toggles with it (renderRecalcReminder()
                 in detail.js) -- hidden while auto-recalculate is on, shown otherwise. Draft-only
                 (recalculate() itself is only ever meaningful for a draft run), same visibility gate
                 as #runRecalculateButtonWrap's own buttons.
                 2026-09-13, Round 3 item 3b, 3rd placement this round (reported in the task response
                 each time) -- tried "right of the filter-bar header" (via filter-bar.php's own new
                 $header_extra_html slot) and "under the table" before this; user's own explicit final
                 choice is back HERE, its original spot: its own standalone line, left-aligned, above
                 the filter-bar. filter-bar.php's $header_extra_html slot itself is NOT removed (kept,
                 documented, unused by this page now) -- it's a genuine reusable capability of that
                 shared component now, independent of whether this ONE page ends up using it. -->
            <!-- 2026-09-14, Round 3 "เก็บตกรอบ 6", explicit instruction: new shared component
                 `setting-row.php`/`settingRowHtml()` (§9/§11) -- supersedes the previous 2 "เก็บตก"
                 rounds' own page-local attempts at this exact shape (a static switch + a separate
                 #recalcReminderBanner div, styled/positioned by hand each round). #autoRecalculateWrap
                 stays as the OUTER draft-only show/hide wrapper (renderSectionButtons() in detail.js,
                 unchanged gating) but is now EMPTY here -- the switch + label + state-dependent
                 description all come from ONE settingRowHtml() call in detail.js instead (the run's own
                 auto_recalculate value isn't known at PHP-render time, same reason #nextStepBanner right
                 above this tab is also JS-rendered into an empty shell rather than included via PHP
                 directly). See setting-row.php's own docblock for the full component spec. -->
            <div class="d-none" id="autoRecalculateWrap"></div>
            <!-- 2026-09-13, Round 3 item 3b: #noDetailsYet (a standalone "no employees yet" block
                 outside the table, manually toggled by initRunDetailTable() itself) is retired --
                 initSharedDataTable()'s own `emptyState` option (§6) now renders this INSIDE the
                 table's own tbody instead, auto-picking between this "genuinely no data yet" copy and
                 a "filtered to zero results" variant depending on WHY the table is empty (see
                 initRunDetailTable()'s own emptyState config in detail.js) -- the table itself no
                 longer hides as a whole when there's nothing to show, it shows its own empty-state row
                 with the toolbar/filters still usable above it, same as every other table using this
                 option. -->
            <!-- 2026-09-13, Round 3 item 3b, explicit instruction: "checkbox วิธีจ่าย + segmented แหล่งที่มา
                 ย้ายเข้า filter-bar.php (2 ช่อง select)" -- the independent Bank/Cash checkboxes AND the
                 Source radio-pill group both retired in favor of ONE shared filter-bar.php panel (§6)
                 holding real `<select>` fields (this app's own mandatory Select2 convention, CLAUDE.md
                 -- initSelect2(...), wired in initRunDetailFilterBarOnce() in detail.js). Payment
                 Method's 3 options (All/Bank Transfer/Cash) reach the exact same 2 reachable filter
                 states the old 2-checkbox pair did (both boolean flags
                 registerPaymentMethodSearchFilter() already computed from bank/cash-on booleans -- now
                 derived from this ONE select's value instead of 2 checkboxes, same predicate, no logic
                 change). #rdDataSourceFilterWrap keeps its id (now the Source field's own column div)
                 so its existing run-level d-none toggle in initRunDetailTable() (a run that never
                 brings base salary into the calculation) still works unchanged.
                 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "เพิ่มช่อง 'แผนก'
                 (select2-remote /api/department.get เหมือน Employee list) เป็นช่องแรก" -- same
                 markup/data-api/data-type convention as Employee List's own #employee_filter_department
                 (app/views/employee/list.php), filtered client-side against row.department_id (already
                 selected by PayrollRunModel::getDetails()'s own SQL -- confirmed via grep, just never
                 read by this file's JS before now -- see registerDepartmentSearchFilter() in detail.js).
                 "grid 6 ช่อง/แถว = col-lg-2 ต่อช่องเสมอ" -- all 3 fields now col-lg-2 (was col-sm-3, which
                 read too stretched at 3-up); the other 3 of the 6 grid slots are simply left empty. -->
            <?php
            ob_start(); ?>
            <div class="row g-2">
                <div class="col-lg-2">
                    <label class="form-label small mb-1" for="rdDepartmentFilter" data-i18n="department">Department</label>
                    <select class="form-select select2-remote" id="rdDepartmentFilter" data-api="/api/department.get" data-type="department"></select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label small mb-1" for="rdPaymentMethodFilter" data-i18n="table_payment_method">Payment Method</label>
                    <select class="form-select select2-static" id="rdPaymentMethodFilter" data-option-keys="filter_all,table_payment_bank,table_payment_cash" data-option-values="all,bank,cash"></select>
                </div>
                <div class="col-lg-2" id="rdDataSourceFilterWrap">
                    <label class="form-label small mb-1" for="rdSourceFilter" data-i18n="table_source">Source</label>
                    <select class="form-select select2-static" id="rdSourceFilter" data-option-keys="filter_all,data_source_sync,data_source_manual" data-option-values="all,sync,manual"></select>
                </div>
            </div>
            <?php
            $filter_fields_html = ob_get_clean();
            $id = 'runDetailFilterBar';
            include __DIR__ . '/../partials/filter-bar.php';
            ?>
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
                        <!-- 2026-09-13, Round 3 item 3b (§7): `col-check` marker class -- initSharedDataTable()'s
                             own DT_MARKER_CLASSES auto-derives this column's `columnDefs` (fixed-width,
                             centered, not orderable/searchable) from this class alone. -->
                        <th class="col-check"><input type="checkbox" class="form-check-input" id="runDetailSelectAll"></th>
                        <!-- 2026-09-14, Round 3 "เก็บตกรอบ 7", REAL root cause found and fixed (explicit
                             report: Employee Code/Name/Base Salary/Gross/Deductions/Net Pay stayed Thai
                             on th->en, while Department/Payment Method/Calculation/Verify-Lock -- the 4
                             columns table-column-filter.js's own initExcelColumnFilters() rebuilds --
                             translated fine). Confirmed directly from the installed DataTables source
                             (node_modules/datatables.net/js/dataTables.js, the header-detection routine
                             every `<th>` passes through at construction): it ALWAYS moves a header
                             cell's existing child nodes into a fresh `<span class="dt-column-title">`
                             wrapper (`.append(cell.childNodes)`), for every column, sortable or not --
                             not just the 4 that initExcelColumnFilters() also happens to touch. A plain
                             `<th data-i18n="key">Label</th>` therefore ends up as `<th data-i18n="key">
                             <div class="dt-column-header"><span class="dt-column-title">Label</span>...
                             </div></th>` after DataTables runs -- the `<th>` now has a child ELEMENT, so
                             app.js's own updateText() sweep (its own documented child-guard, added
                             2026-09-13 for a different but related bug) correctly refuses to `.text()`
                             it (that would destroy the wrapper DataTables just built) and just warns
                             instead, while `data-i18n` is left stranded on the outer `<th>`, nowhere
                             near the actual visible text node 2 levels deeper. The 4 tcf-managed columns
                             only ever looked fine because initExcelColumnFilters() empties and fully
                             rebuilds the `<th>` itself, incidentally moving `data-i18n` onto a genuine
                             leaf span of its own in the process -- not because this table's markup
                             pattern was actually correct. Fixed at the true source, for EVERY column
                             here uniformly (not just the 4): `data-i18n` now lives on a plain inner
                             `<span>`, never the `<th>` itself. DataTables' own wrapping still moves that
                             span deeper (into `.dt-column-title`), but the span is still a genuine leaf
                             wherever it ends up, so updateText()'s plain `$(root).find('[data-i18n]')`
                             sweep (which searches descendants, not just direct attributes) finds and
                             updates it correctly regardless of nesting depth. table-column-filter.js's
                             own initExcelColumnFilters() updated to match (looks for `data-i18n` on a
                             descendant now, not just the `<th>` attribute, so Department/Payment Method/
                             Calculation/Verify-Lock keep translating too) -- see that file's own
                             docblock. detail.js's old page-specific "defensive re-sync" backstop (added
                             2026-09-14 "เก็บตกรอบ 6" while this exact bug was still unsolved) is removed
                             below in favor of this real fix; `dt.columns.adjust()` after the sweep
                             replaces it instead, since header content changing width on a language
                             switch is the only thing that still needs a page-specific hook.
                             2026-09-02, explicit request: "ตารางพนักงาน แยก code และชื่อคนละ Column Code
                             อยู่ก่อน" -- was one combined 2-line cell (name bold on top, code muted
                             underneath); split into its own Code column, placed before Name. -->
                        <th class="text-nowrap"><span data-i18n="employee_no">Employee Code</span></th>
                        <th class="text-nowrap"><span data-i18n="table_employee_name">Name</span></th>
                        <!-- 2026-09-11, Batch 3C item 7, explicit instruction: "เพิ่มคอลัมน์ แผนก ถัดจากชื่อ"
                             -- replaces the old "Source" column in this same slot (see the filter pill
                             above the table instead, #rdDataSourceFilterWrap). -->
                        <th class="text-nowrap"><span data-i18n="department">Department</span></th>
                        <!-- 2026-09-02, explicit request: "ในตารางพนักงานให้เพิ่ม Column รับเงินผ่านบัญชี หรือ
                             เงินสด" -- was only visible on the separate "Payment Method Summary" tab;
                             now also its own column here on the main Details table.
                             2026-09-13, Round 3 item 3b follow-up, explicit instruction: "badge = ซ้าย"
                             (§7) -- was text-center (a leftover from before this column routed through
                             statusBadgeHtml()); left-aligned now, matching every other badge column. -->
                        <th class="text-nowrap"><span data-i18n="table_payment_method">Payment Method</span></th>
                        <!-- 2026-09-13, Round 3 item 3b (§7/§8): `col-money` marker on all 4 money
                             columns -- DT_MARKER_CLASSES auto-applies `num col-money` (tabular-nums,
                             right-align) to each; Gross/Deductions/Net's OWN `.money-gross`/
                             `.money-deduction`/`.money-net` color class is set directly in detail.js's
                             own `columns:` config instead (see that file's own comment -- concatenates
                             with, doesn't replace, this marker's className). Base Salary carries no
                             money-color class -- §8 only covers gross/deduction/net, not the base
                             figure itself. -->
                        <th class="col-money text-nowrap"><span data-i18n="table_base_salary">Base Salary</span></th>
                        <th class="col-money text-nowrap"><span data-i18n="table_gross_amount">Gross</span></th>
                        <th class="col-money text-nowrap"><span data-i18n="table_deduction_amount">Deductions</span></th>
                        <th class="col-money text-nowrap"><span data-i18n="table_net_pay">Net Pay</span></th>
                        <th class="text-nowrap"><span data-i18n="table_calculation">Calculation</span></th>
                        <!-- 2026-09-13, Round 3 item 3b follow-up, explicit instruction: "badge = ซ้าย"
                             (§7) -- was text-center. -->
                        <th class="text-nowrap"><span data-i18n="table_verify_lock">Verify / Lock</span></th>
                        <!-- 2026-08-27, explicit request: blank out any "Action(s)" header, matches
                             the empty-header convention every other Actions column already uses.
                             2026-09-13, Round 3 item 3b (§7): `col-actions` marker class (fixed-width,
                             right-aligned, not orderable/searchable via DT_MARKER_CLASSES). -->
                        <th class="col-actions"></th>
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
                <!-- 2026-09-13, Round 3 item 3b, explicit instruction: "แถวรวมท้ายตาราง (footer) ใช้ .num
                     ตัวหนา สีเงินเดียวกับคอลัมน์" -- each money total now carries `.num` (tabular digits) +
                     `fw-bold` + the SAME `.money-gross`/`.money-deduction`/`.money-net` class its own
                     column uses (§8: the color/weight lives on the number itself, not a wrapping badge)
                     -- Base Salary's own footer total stays plain `.num.fw-bold` with no money-color
                     class, matching its column (§8 doesn't cover it). Text content itself is still set
                     by footerCallback() in detail.js -- only the static class list changed here.
                     2026-09-13, Round 3 "เก็บตก" item 3, real bug found and fixed (explicit report:
                     "ตรวจสอบแล้ว 1/1" ไม่ชิดซ้ายตามคอลัมน์) -- `#rdFootVerifyLock`'s own hardcoded
                     `text-center` (removed) was overriding DataTables' own default left-aligned
                     footer cell (`!important` on Bootstrap's `.text-center` utility beats the
                     library's plain `text-align:left`) -- its own column ("Verify / Lock") is a badge
                     column, left per §7, matching its `<thead>` `<th>` right above it (no `.text-
                     center` there either). `applyTfootMarkerClasses()` (app.js, new this round) now
                     mirrors any §7 marker class from a column's own `<thead>` `<th>` onto its `<tfoot>`
                     cell automatically for every `initSharedDataTable()` caller -- this specific
                     column has no marker class upstream (it's not money/date/checkbox/etc, just a
                     plain left-aligned badge column), so simply deleting the wrong hardcoded class was
                     enough here; the new helper exists so a FUTURE table's marker-classed footer cells
                     never need a page to hand-guess the right class at all. -->
                <tfoot class="table-light text-secondary">
                    <tr>
                        <th></th>
                        <th id="rdFootEmployeeCount"></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th class="num fw-bold" id="rdFootBaseSalary"></th>
                        <th class="num fw-bold money-gross" id="rdFootGross"></th>
                        <th class="num fw-bold money-deduction" id="rdFootDeduction"></th>
                        <th class="num fw-bold money-net" id="rdFootNet"></th>
                        <th id="rdFootCalcStatus"></th>
                        <th id="rdFootVerifyLock"></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>

        <!-- 2026-08-29, explicit request: "ใส่ Comment ได้ของแต่ละคน กดแล้วเปิดเป็น Modal ให้ใส่ Comment
             เรื่อยๆ เป็น Timeline...ให้มีใส่ tag ได้ว่า กำลังดำเนินการ ดำเนินการเสร็จแล้ว มีข้อผิดพลาด"
             2026-09-14, Round 3 item 3c-3, explicit instruction: the list itself was first migrated
             onto the shared Timeline component (renderTimeline(), app.js) instead of its own bespoke
             .apv-comment-* markup.
             2026-09-14, Round 3 (later same day): moved OFF Timeline onto a dedicated
             renderCommentList() (app.js -- see renderEmployeeCommentListFromCache()'s own docblock in
             detail.js) instead -- a comment's own avatar+2-line shape (with inline-edit) fits that
             new, purpose-built component far better than continuing to stretch Timeline's
             dot-and-connecting-line event-log shape to cover it too. See rules.md §6's own "Comment
             list" section for exactly where the line between the 2 components sits now.
             `data-dirty-guard data-dirty-guard-tone="warning"` (§9, app.js's own generic mechanism --
             this modal is its first real caller): typing in the compose textarea/picking a tag, OR
             having an inline edit open, then closing this modal any way (×/Esc/backdrop) prompts via
             showConfirm() with the 'warning' tone (not the mechanism's own 'danger' default) before
             discarding it -- see detail.js's own refreshDirtyGuard() call sites for exactly when the
             baseline re-captures (entering/leaving inline edit, successful save/add).
             2026-09-14, Round 3 item 3c-4 (review follow-up): the 2026-08-29 "form lives in the
             footer, pinned while the list scrolls" layout was reverted -- the compose form moved back
             into the (scrollable) modal-body.
             2026-09-15, Round 3 (comment-list restyle), explicit instruction: the compose form moved
             again, this time ABOVE the list (composer first, newest comment right below it) and onto
             the shared composer component (commentComposerHtml(), app.js -- see #employeeCommentComposer
             below), and the footer lost its own submit button entirely: the composer owns its own
             [บันทึก] button now, so the footer is just [ปิด] (rules.md §6/§9, still rendered through
             modalFooterButtonsHtml()). -->
        <div class="modal fade" id="employeeCommentModal" data-footer="none" data-dirty-guard data-dirty-guard-tone="warning" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <!-- 2026-09-14, Round 3 item 3c-4, explicit instruction: header = title + ×
                             only, icon removed (matches every other modal already migrated to this
                             rule this round). -->
                        <!-- 2026-09-15, explicit instruction, item 3: the title carries the live comment
                             count ("คอมเมนต์ (N)") -- built in JS from the `{count}` template key
                             `employee_comment_timeline_title_count` (detail.js's own
                             updateEmployeeCommentTitle(), re-run on every add/delete AND on a live
                             language switch), so NO `data-i18n` here: the generic sweep would
                             overwrite it with the countless label the moment the language changed. -->
                        <h5 class="modal-title text-secondary" id="employeeCommentModalTitle">Comments</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- 2026-09-11, Batch 3C item 8, explicit instruction: employeeHeaderCardHtml()
                             (app.js) as the first block in modal-body. -->
                        <div id="employeeCommentHeaderCard"></div>
                        <!-- 2026-09-15, Round 3 (comment-list restyle), explicit instruction: the
                             COMPOSER moves to the TOP (right under the employee header card, above
                             the list) and is no longer static markup here at all -- detail.js
                             renders the shared commentComposerHtml() (app.js, rules.md §6) into this
                             empty div on every modal open. One composer component now serves both
                             this box and an inline edit of an existing comment (§0.4's "ซ้ำ = shared":
                             the static markup that used to live here and detail.js's own inline-edit
                             form were 2 hand-kept copies of the same shape, and had already drifted).
                             Why JS-rendered and not a PHP partial: the composer's own author row
                             shows WHO is writing (avatar + name of the logged-in user), which the
                             list's own items also need, and both come from the same window.SESSION_USER
                             + apvAvatarHtml() pair on the client -- a PHP twin would duplicate that
                             for no second caller (same reasoning renderCommentList() itself has no
                             PHP partner). -->
                        <div id="employeeCommentComposer"></div>
                        <!-- 2026-08-29, explicit follow-up request: "ถ้าการดำเนินเสร็จแล้ว Comment ดูได้เท่านั้น
                             ไม่สามารถเพิ่ม แก้ไข ลบได้" -- shown INSTEAD of the composer above once
                             commentsReadOnlyRd() (detail.js) is true, i.e. the run has reached a
                             genuinely finished state (paid/locked/cancelled -- see
                             PayrollRunModel::COMMENT_LOCKED_STATES's own docblock for why that's a
                             different, narrower cutoff than this page's general View Mode). Exactly
                             one of the two is ever visible, which is why both carry the same `--sp-6`
                             gap down to the list (style.css). -->
                        <div id="employeeCommentReadOnlyNotice" class="text-center text-muted small d-none"><i class="fa-solid fa-lock me-1"></i><span data-i18n="employee_comment_read_only">This payroll run has finished processing. Comments are view-only.</span></div>
                        <!-- 2026-09-14, Round 3: one container -- renderEmployeeCommentListFromCache()
                             (detail.js) renders EITHER the shared comment list (renderCommentList(),
                             app.js) OR the shared empty-state (emptyStateHtml()) into this same div,
                             which is why the empty state appears right under the composer, where the
                             first comment would otherwise be. No card wrapper around it (explicit
                             instruction -- "ไม่มี card ครอบ tab-content"). -->
                        <div id="employeeCommentList"></div>
                    </div>
                    <!-- 2026-09-14, Round 3 item 3c-4: "footer คงที่ตลอด" -- a plain empty shell here,
                         populated once via modalFooterButtonsHtml() (app.js, from detail.js's own
                         .btn-comment-employee click handler).
                         2026-09-15, Round 3 (comment-list restyle), explicit instruction: it holds
                         exactly ONE button now, [ปิด] -- the submit button moved into the composer box
                         itself (#employeeCommentComposer above), right next to the text it submits, so
                         there is no longer any footer button whose label/enabled state has to track
                         the view-only/editing state at all. -->
                    <div class="modal-footer" id="employeeCommentModalFooter"></div>
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
            <!-- 2026-09-20, 3e-1: the bespoke `.reports-not-ready-banner` (icon-circle + gradient bar,
                 4 CSS rules of its own) is retired HERE for the shared callout (§15/§11) -- the CSS
                 itself stays, because `app/views/reports/index.php:126,158` still uses it and that
                 page is not in this round; deleting it now would break a page nobody touched. --
                 this block is an announcement standing NEXT TO content the user can still see (the
                 report list is right below it, rows and all, just disabled), which is exactly what a
                 callout is for; an empty-state would claim there is nothing here. Tone `neutral`
                 (not the old amber): "approve the run first" is a standing fact about where this run
                 is in its own lifecycle, not something to act on in this tab (§0.1). -->
            <div class="mb-3 d-none" id="runReportsNotReadyBanner">
                <?php
                $text = '<b><span data-i18n="reports_not_ready_title">Reports Not Available Yet</span></b> <span data-i18n="reports_available_after_approval">Reports are available once this run is approved.</span>';
                $tone = 'neutral';
                include __DIR__ . '/../partials/callout.php';
                ?>
            </div>
            <div id="runReportsNotReady" class="d-none">
                <?php
                $icon = 'fa-solid fa-file-export'; $action = null; $text_id = null;
                $title = 'Reports Not Available Yet'; $title_i18n = 'reports_not_ready_title';
                $text = 'Reports are available once this run is approved.'; $text_i18n = 'reports_available_after_approval';
                include __DIR__ . '/../partials/empty-state.php';
                ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100 d-none" id="tb_run_reports">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th><span data-i18n="report_name">Report</span></th>
                            <th class="text-center"><span data-i18n="download_count">Downloaded</span></th>
                            <th><span data-i18n="last_downloaded_at">Last Downloaded</span></th>
                            <th class="text-center"></th>
                        </tr>
                    </thead>
                    <tbody id="runReportsTableBody"></tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="run-cash-pane" role="tabpanel" aria-labelledby="run-cash-tab" tabindex="0">
            <!-- 2026-09-20, 3e-1: the hand-written "not ready" block (centred text + a fa-2x icon)
                 is the shared empty-state (§6/§11) -- 32px `--c-text-faint` icon, title + one line.
                 The wrapper keeps `#runCashNotReady` because that is what loadRunCashTab() toggles,
                 and `$text_id` keeps `#runCashNotReadyMessage` on the line that same function
                 replaces with a server error message on a failed load. Same 3 lines below for Bank
                 Account Assignment and Third-Party Remittance. -->
            <div id="runCashNotReady" class="d-none">
                <?php
                $icon = 'fa-solid fa-money-bill-wave'; $action = null; $text_id = 'runCashNotReadyMessage';
                $title = 'Reports Not Available Yet'; $title_i18n = 'reports_not_ready_title';
                $text = 'Reports are available once this run is approved.'; $text_i18n = 'reports_available_after_approval';
                include __DIR__ . '/../partials/empty-state.php';
                ?>
            </div>
            <div id="runCashContent" class="d-none">
                <!-- 2026-09-20, 3e-1: `.stat-card`/`.stat-card-{tone}` (coloured edge + icon tile)
                     replaced by `stat-card.php` (§2: plain card, no tone colour, optional flat icon).
                     `value_id` (§2's own planned field, implemented this round) keeps the ids
                     loadRunCashTab() writes the numbers into. -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <?php $stat = ['label' => 'Total Cash', 'label_i18n' => 'total_cash_payment', 'value' => '-', 'value_id' => 'runCashTotalCash', 'icon' => 'fa-solid fa-money-bill-wave', 'value_class' => 'money-net']; include __DIR__ . '/../partials/stat-card.php'; ?>
                    </div>
                    <div class="col-6 col-md-3">
                        <?php $stat = ['label' => 'Total Bank Transfer', 'label_i18n' => 'total_bank_payment', 'value' => '-', 'value_id' => 'runCashTotalBank', 'icon' => 'fa-solid fa-building-columns', 'value_class' => 'money-net']; include __DIR__ . '/../partials/stat-card.php'; ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="tb_run_cash">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th><span data-i18n="employee_no">Employee No.</span></th>
                                <th><span data-i18n="employee">Employee</span></th>
                                <th class="col-money"><span data-i18n="amount">Amount</span></th>
                                <th class="text-center"><span data-i18n="status">Status</span></th>
                                <th><span data-i18n="table_paid_at">Paid At</span></th>
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
            <div id="runBankAccountNotReady" class="d-none">
                <?php
                $icon = 'fa-solid fa-building-columns'; $action = null; $text_id = 'runBankAccountNotReadyMessage';
                $title = 'Reports Not Available Yet'; $title_i18n = 'reports_not_ready_title';
                $text = 'Reports are available once this run is approved.'; $text_i18n = 'reports_available_after_approval';
                include __DIR__ . '/../partials/empty-state.php';
                ?>
            </div>
            <div id="runBankAccountContent" class="d-none">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div class="text-muted small" data-i18n="bank_account_assignment_hint">Which of the company's own settlement accounts pays each employee this run. Leave unassigned to use the employee's own default or the pay cycle/company default.</div>
                    <!-- 2026-09-20, 3e-1 (§4): a text button carries no icon, and `btn-outline-success`
                         said nothing `btn-outline-secondary` does not (§12 rule 3). Same for
                         #btnExportRunRemittance below. id/handler unchanged. -->
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnExportRunBankAccountSummary"><span data-i18n="export_excel">Export Excel</span></button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="tb_run_bank_account">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th><span data-i18n="employee_no">Employee No.</span></th>
                                <th><span data-i18n="employee">Employee</span></th>
                                <th><span data-i18n="bank_account">Bank Account</span></th>
                                <th class="text-center"><span data-i18n="bank_account_source">Source</span></th>
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
            <div id="runRemittanceNotReady" class="d-none">
                <?php
                $icon = 'fa-solid fa-money-bill-transfer'; $action = null; $text_id = 'runRemittanceNotReadyMessage';
                $title = 'Reports Not Available Yet'; $title_i18n = 'reports_not_ready_title';
                $text = 'Reports are available once this run is approved.'; $text_i18n = 'reports_available_after_approval';
                include __DIR__ . '/../partials/empty-state.php';
                ?>
            </div>
            <div id="runRemittanceContent" class="d-none">
                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnExportRunRemittance"><span data-i18n="export_excel">Export Excel</span></button>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <?php $stat = ['label' => 'Pending', 'label_i18n' => 'remittance_status_pending', 'value' => '-', 'value_id' => 'runRemittanceTotalPending', 'icon' => 'fa-solid fa-hourglass-half', 'value_class' => 'money-net']; include __DIR__ . '/../partials/stat-card.php'; ?>
                    </div>
                    <div class="col-6 col-md-3">
                        <?php $stat = ['label' => 'Transferred', 'label_i18n' => 'remittance_status_transferred', 'value' => '-', 'value_id' => 'runRemittanceTotalTransferred', 'icon' => 'fa-solid fa-paper-plane', 'value_class' => 'money-net']; include __DIR__ . '/../partials/stat-card.php'; ?>
                    </div>
                    <div class="col-6 col-md-3">
                        <?php $stat = ['label' => 'Success', 'label_i18n' => 'remittance_status_success', 'value' => '-', 'value_id' => 'runRemittanceTotalSuccess', 'icon' => 'fa-solid fa-circle-check', 'value_class' => 'money-net']; include __DIR__ . '/../partials/stat-card.php'; ?>
                    </div>
                    <div class="col-6 col-md-3">
                        <?php $stat = ['label' => 'Failed', 'label_i18n' => 'remittance_status_failed', 'value' => '-', 'value_id' => 'runRemittanceTotalFailed', 'icon' => 'fa-solid fa-circle-xmark', 'value_class' => 'money-net']; include __DIR__ . '/../partials/stat-card.php'; ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="tb_run_remittance">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th><span data-i18n="remittance_destination">Destination</span></th>
                                <th><span data-i18n="remittance_destination_type">Type</span></th>
                                <th class="text-center"><span data-i18n="remittance_employee_count">Employees</span></th>
                                <th class="col-money"><span data-i18n="amount">Amount</span></th>
                                <th class="text-center"><span data-i18n="status">Status</span></th>
                                <th><span data-i18n="remittance_transferred_at">Transferred At</span></th>
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
                        <!-- 2026-09-11, Batch 3C item 8, explicit instruction: employeeHeaderCardHtml()
                             (app.js) as the first block in modal-body -- replaces the old plain
                             "Employee: {name}" line (#bankAccountAssignEmployeeName), now redundant
                             since the card shows the name too. -->
                        <div id="bankAccountAssignHeaderCard" class="mb-2"></div>
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
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
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
                                        <th><span data-i18n="employee_no">Employee No.</span></th>
                                        <th><span data-i18n="employee">Employee</span></th>
                                        <th><span data-i18n="item">Item</span></th>
                                        <th class="num col-money"><span data-i18n="amount">Amount</span></th>
                                    </tr>
                                </thead>
                                <tbody id="remittanceBreakdownTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
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
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
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
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
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
                        <button type="button" class="btn btn-outline-secondary me-auto" data-bs-dismiss="modal" data-i18n="close">Close</button>
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
                                        <th><span data-i18n="downloaded_at">Date/Time</span></th>
                                        <th><span data-i18n="downloaded_by">By</span></th>
                                        <th><span data-i18n="language">Language</span></th>
                                        <th><span data-i18n="device">Device</span></th>
                                        <th><span data-i18n="browser">Browser</span></th>
                                        <th>IP</th>
                                        <th><span data-i18n="source">Source</span></th>
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

        <!-- 2026-09-22, 3e-3 round B1, explicit instruction/user confirmation (2026-09-22): the
             vertical icon+connector-line Timeline (2026-08-27 -- 2026-08-29 history, see git log) is
             replaced with a real DataTable -- rules.md §6 now allows this for a feed that grows
             unbounded (run 752 in dev carries 1571 rows; Timeline's own spec is for a feed short
             enough to show in full). Column headers carry NO `data-i18n` here, unlike every other
             `<th>` on this page -- every column's content is language-bound at render time
             (actor name/action label/state badge), so `columns[].title` is set from langData in JS
             at construction instead (initAuditLogTableRd(), detail.js) and kept in sync on a live
             switch by refreshAuditLogTableLanguage(). Empty state (both "no rows yet" and "filtered
             to zero") is the table's own `emptyState`/`dtRenderEmptyState()` (§6), not a static
             `empty-state.php` block -- same pattern tb_run_detail already uses.
             2026-09-22, round B2: the `.rd-audit-log-wrap` marker this div carried was a test-only
             hook (no CSS of its own) for m3e1_tabs_shared.js's own c13 anchor selector -- removed
             along with that selector once it turned out to be the wrong kind of anchor for that
             check (an ancestor of the table, not a sibling block beside it -- see that file's own
             docblock on the fix). No consumer left anywhere (grep confirmed), so the dead class goes
             too rather than staying as markup with no meaning (rules.md §0.3). -->
        <div class="tab-pane fade" id="run-history-pane" role="tabpanel" aria-labelledby="run-history-tab" tabindex="0">
            <!-- 2026-09-23, 3e-3b round B4: date-range filter for `performed_at` -- the ONE column
                 the header checklists (table-column-filter.js) can't reach. Round B1/B2 used
                 `.station-filter` (reports/index.php), B3 replaced it with a plain flex row on the
                 theory that rules.md §6 named no box at all -- BOTH wrong: §6 (rules.md:846-877)
                 DOES name one decided component, `.filter-bar` (this exact partial, `design:clean`),
                 already in real use on THIS SAME PAGE for #tb_run_detail's own filter fields
                 (Department/Payment Method/Source, above). Byte-identical include here: same
                 `$id`/`$filter_fields_html` contract, same `row g-2`/`col-lg-2` grid, only the field
                 markup itself differs (2 bare `.form-control.datepicker` inputs instead of selects --
                 no icon/input-group addon, matching this app's OWN dominant datepicker convention,
                 employee/detail.php: 18 bare fields, 0 with an icon addon -- opens on focus). No
                 `$pageKey` set, matching #runDetailFilterBar exactly (that bar doesn't persist its
                 collapse state either -- initFilterBar()'s own viewport-width fallback runs every
                 load for both). initFilterBar()'s own `clearAllFields()` only ever resets `<select>`
                 fields (app.js, confirmed by reading it) -- a real gap for a date-input filter bar,
                 not a page-specific choice -- so detail.js supplements the shared `.filter-bar-clear`
                 button (never a 2nd button of our own) with its own delegated handler for these 2
                 fields specifically; see that handler's own comment for the reasoning. -->
            <?php
            ob_start(); ?>
            <div class="row g-2">
                <div class="col-lg-2">
                    <label class="form-label small mb-1" for="auditLogDateFrom" data-i18n="date_from">From</label>
                    <input type="text" class="form-control datepicker" id="auditLogDateFrom" autocomplete="off">
                </div>
                <div class="col-lg-2">
                    <label class="form-label small mb-1" for="auditLogDateTo" data-i18n="date_to">To</label>
                    <input type="text" class="form-control datepicker" id="auditLogDateTo" autocomplete="off">
                </div>
            </div>
            <?php
            $filter_fields_html = ob_get_clean();
            $id = 'auditLogFilterBar';
            include __DIR__ . '/../partials/filter-bar.php';
            ?>
            <div class="callout callout-warning d-none mt-2" id="auditLogDateRangeInvalidCallout" data-i18n="date_range_invalid">Start date must not be later than end date.</div>
            <div class="table-responsive mt-2">
                <table class="table table-hover align-middle w-100" id="tb_run_audit_log">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th class="text-nowrap"></th>
                            <th class="text-nowrap"></th>
                            <th class="text-nowrap"></th>
                            <th class="text-nowrap"></th>
                            <th></th>
                            <th class="text-nowrap"></th>
                            <th class="text-nowrap"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 2026-09-11, Batch 3C item 4 sub-step 4a: #editRunModal/#editRunForm (a byte-for-byte-drifted
         duplicate of #payrollRunModal/#payrollRunForm in layout/modals.php, which is included
         globally via layout/footer.php and therefore already present on this page) deleted entirely.
         #btnEditRun's own click handler (detail.js) now populates that ONE shared modal directly --
         see app.js's "Payroll Run form (shared Create/Edit)" section. -->

    <!-- Breakdown Modal: per-employee itemized view for one payroll_run_details row. 2026-09-18,
         4a-1/4a-2: ONE table (renderLineOverrideTableRd(), payroll/detail.js) in both modes -- every
         line grouped by what it is (base salary / income / deductions / statutory / the 2 hand-added
         groups), then Gross/Total Deductions/Net Pay as its last 3 rows. `mode: 'view'` decides only
         which columns exist at all. See docs/decisions/2026-09-18-slip-single-place.md.
         2026-09-14, Round 3 item 3c-2: header reduced to title + × only (§9 "Header = ชื่อ + ×
         เท่านั้น") -- #breakdownEmployeeName was already removed (2026-09-11, employee name lives in
         the header card below instead); the icon and the pinned Net-Pay footer are now also gone --
         Net Pay moved into the payslip summary itself, and the footer reverts to the plain [Close]
         `data-footer="view"` auto-injects (app.js's own show.bs.modal handler). -->
    <div class="modal fade" id="runDetailBreakdownModal" data-footer="view" tabindex="-1" aria-labelledby="runDetailBreakdownModalLabel" aria-hidden="true">
        <!-- 2026-09-22, slip2-b: `modal-xl`, not `modal-lg`. Two reasons, and the rule already said
             both: §9 reserves `modal-xl` for a dialog that CARRIES A TABLE, which this one does; and
             the line form that opens on top of it stays `modal-lg`, so the child is now 340px
             narrower than its parent instead of exactly as wide (measured: 800 vs 800 before, 1140
             vs 800 after) and reads as a window on top rather than a replacement of the one below.
             Below `lg` both collapse to the same full width, so nothing changes at 430. -->
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary mb-0" id="runDetailBreakdownModalLabel" data-i18n="breakdown_title">Calculation Breakdown</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 2026-09-11, Batch 3C item 8, explicit instruction: employeeHeaderCardHtml()
                         (app.js) as the first block -- a static sibling of #breakdownModalBody (that
                         div's own content is still fully replaced via .html() on every open, see
                         renderBreakdownModal() in detail.js) so this card doesn't get wiped along
                         with it. -->
                    <div id="breakdownHeaderCard"></div>
                    <!-- 2026-09-21, 3e-2b: what Origami sent for this employee, read-only, opened
                         from the disclosure button in the header card's own right slot above.
                         Static sibling for the same reason that card is one (#breakdownModalBody's
                         content is replaced wholesale on every open), and a PANEL rather than a
                         second modal: the slip must stay the one open modal. Filled/emptied by
                         renderBreakdownModal()/the toggle handler in detail.js -- hidden and empty
                         on every open, so it can never carry the previous employee's payload. -->
                    <div id="rawSyncPanel" class="rd-sync-panel d-none"></div>
                    <!-- 2026-09-16: the calculation notes for this row (calc_blocking as danger
                         callouts, calc_warnings as warning ones -- see renderBreakdownModal()). The
                         table cell itself only shows the count now, so this is where the full text
                         is read. Static sibling of #breakdownModalBody for the same reason the
                         header card above is one: that div's content is replaced wholesale on every
                         open. -->
                    <div id="breakdownCalcNotes" class="rd-calc-notes"></div>
                    <!-- 2026-09-06, explicit request: display Origami's opt-in TOTAL_DAYS
                         item_values entry (calendar-based day count) when present -- hidden
                         entirely for a run/employee with no data (cycle-based/off-cycle run, or a
                         sync run whose admin never ticked this Report Item on), see
                         PayrollRunModel::getDetails()'s own docblock. Moved out of the modal-header
                         2026-09-14 (§9) -- still ≤1 line, right under the header card. -->
                    <div class="text-muted small mb-2 d-none" id="breakdownTotalDays"></div>
                    <div id="breakdownModalBody"></div>
                </div>
                <!-- 2026-09-17, D3: this modal used to have NO footer element, so app.js's own
                     `data-footer="view"` fallback injected a bare [Close] on first open. It owns one
                     now because "คืนค่าระบบทั้งหมด" moved here with the line-override table: §9's
                     footer LEFT slot is where that button already lived (it came from the per-employee
                     settings modal, deleted 4b),
                     and it is still neither this modal's main action nor its way out. Filled per open
                     by renderBreakdownFooterRd() (detail.js) -- read-only rows get [ปิด] alone, i.e.
                     exactly what the fallback used to inject. -->
                <div class="modal-footer" id="breakdownModalFooter"></div>
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
                        <!-- 2026-09-22, n: the title's own `data-i18n` is SWAPPED at runtime (this
                             one modal serves 2 modes, see applyJoinEmployeesModeRd() in detail.js).
                             It lives on this leaf span, never on the <h5>, so app.js's generic sweep
                             keeps owning it after the swap and cannot wipe the icon beside it. -->
                        <h5 class="modal-title text-secondary mb-0" id="joinEmployeesModalLabel">
                            <i class="fa-solid fa-user-plus me-1"></i><span id="joinEmployeesModalTitleText" data-i18n="join_employees_title">Join Employees</span>
                        </h5>
                        <div class="text-muted small" id="joinEmployeesHint"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- 2026-09-22, n: `missing` mode only -- one neutral sentence saying what this
                         narrowed list is (rules.md 15: tone carries the meaning, no icon). Neutral,
                         not warning: the banner that opens this already carries the warning, and
                         repeating it here would make the same fact shout twice. -->
                    <div class="callout callout-neutral d-none mb-3" id="joinEmployeesMissingCallout" data-i18n="sync_missing_pull_hint">These are the employees expected in this run but absent from the Origami sync -- pick who you need and pull them in from here.</div>
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
                                <th><span data-i18n="table_code">Code</span></th>
                                <th><span data-i18n="table_name">Name</span></th>
                                <th><span data-i18n="department">Department</span></th>
                                <th><span data-i18n="team">Team</span></th>
                                <th><span data-i18n="position">Position</span></th>
                                <th><span data-i18n="payroll_cycle">Payroll Schedule</span></th>
                                <!-- Column 7, `missing` mode only (DataTables column visibility, see
                                     detail.js). Deliberately unlabelled: it is an action column (7).
                                     If it ever needs a label, the `data-i18n` goes on a <span> leaf
                                     inside, never on this <th> -- m3e1_tabs_shared.js's c12 asserts
                                     `#joinEmployeesModal th[data-i18n]` is 0. -->
                                <th></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="modal-footer d-flex justify-content-between align-items-center">
                    <div class="text-muted small" id="joinSelectedCount">0 <span data-i18n="bulk_pull_selected_label">selected</span></div>
                    <div>
                        <!-- The label span carries an id because `missing` mode rewrites it with a
                             {count} template and drops its `data-i18n` while that lasts -- see
                             renderJoinPrimaryLabelRd() in detail.js for why the marker has to go. -->
                        <button type="button" class="btn btn-primary" id="btnJoinSelected" disabled>
                            <i class="fa-solid fa-user-plus me-1"></i><span id="btnJoinSelectedLabel" data-i18n="action_join_employees">Join Employees</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
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
                    <!-- 2026-09-13, decision-set follow-up (§4 exception, rules.md §4): was plain
                         Bootstrap `.btn-success` -- a real §4 violation (banned everywhere per §12
                         lint rule 3) found while wiring this up, not limited to the header buttons
                         that opened this modal. `.btn-decision-success` matches #btnApproveRunHeader's
                         own tone so the color stays consistent from trigger to actual confirmation. -->
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-decision-success"><span data-i18n="approval_confirm_approve">Confirm Approve</span></button>
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
                    <!-- 2026-09-13, decision-set follow-up: was plain Bootstrap `.btn-danger` -- same
                         §4-violation note as the Approve modal above; `.btn-decision-danger` (outline,
                         not solid) matches #btnRejectRunHeader's own tone. -->
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-decision-danger"><span data-i18n="approval_confirm_reject">Confirm Reject</span></button>
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
                    <!-- 2026-09-13, decision-set follow-up: was plain `.btn-primary` (orange, no
                         warning signal at all before this) -- `.btn-decision-warning` (outline) now
                         matches #btnRequestInfoRunHeader's own tone. -->
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-decision-warning"><span data-i18n="approval_confirm_request_info">Confirm</span></button>
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
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 2026-09-23, 3e-3b round B1: read-only detail for ONE #tb_run_audit_log row (opened via
         openAuditLogDetailRd(), detail.js -- entry comes from dt.row($tr).data(), never a live
         re-fetch). rules.md §9 "modal record-only" -- data-footer="view" same as #runTimelineModal
         above, no primary action (backend has no edit/delete for an audit row), never opened on top
         of another modal. -->
    <div class="modal fade" id="auditLogDetailModal" data-footer="view" tabindex="-1" aria-labelledby="auditLogDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary mb-0" id="auditLogDetailModalLabel" data-i18n="audit_detail_title">Action Detail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="auditLogDetailModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 2026-09-23, B1: read-only roster for #syncNotParticipantBanner above (opened via the
         syncNotParticipantViewBtn click handler, detail.js -- rows come from the SAME response the
         banner already holds, never a live re-fetch). rules.md §9 "modal record-only" -- data-footer
         ="view" same as #auditLogDetailModal above, footer has only [Close], no primary action (this
         screen has nothing to change -- the fix is on the employee's own Payroll Participation
         card). Each row is its own link to the employee's profile in a new tab. -->
    <div class="modal fade" id="syncNotParticipantModal" data-footer="view" tabindex="-1" aria-labelledby="syncNotParticipantModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary mb-0" id="syncNotParticipantModalLabel" data-i18n="sync_not_participant_modal_title">Employees not set as payroll participants</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" data-i18n="sync_not_participant_modal_hint">Change this on the Payroll Participation card on the employee's own page.</p>
                    <div id="syncNotParticipantModalList"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 2026-09-16, D2 ("สลิปที่แก้ได้" -- the added-by-hand block works for real): the add/edit
         form for one manual line, as a nested modal (§1's --z-modal-nested scale, applied by
         app.js's own stacked-modal handler -- nothing here sets a z-index). This is the ONLY copy
         of that form in the app: the "Payment Items" tab used to carry it inline and now opens
         this same modal through the + on its own column heads, exactly like the Calculation
         Breakdown modal's "Added manually" block does (§0.4 -- markup that appears in 2 places is
         one partial/one node, never a second copy). Every field below keeps its own id, so every
         handler that already read them (detail.js's payee picker wiring, the mode toggle, the
         money input) keeps working unchanged.
         Footer is built by modalFooterButtonsHtml() on each open (detail.js) -- the primary
         button's LABEL differs between adding and editing, which is a build-time difference, not
         a `disabled`/`d-none` one. -->
    <!-- 2026-09-18, tiny-L6a: this is now the ONE form for editing any line of a run, not just a
         hand-added one -- the pencil on a calculated row opens it too, and the sections it shows are
         decided per open from the line's own `source` (rules.md §9, "ฟอร์มเดียวกันที่เปิดได้จาก 2 ที่
         ขึ้นไป = modal ซ้อน 1 ตัว มาร์กอัปชุดเดียว"). Every field keeps its id, so every handler that
         already read them keeps working. `data-dirty-guard` opts it into app.js's own generic
         close-while-dirty confirm (§9): the form is fully prefilled BEFORE .show(), so the baseline
         that handler takes at `shown.bs.modal` is the real one. -->
    <div class="modal fade" id="manualLineFormModal" data-footer="form" data-dirty-guard data-dirty-guard-tone="warning" data-bs-backdrop="static" tabindex="-1" aria-labelledby="manualLineFormModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary mb-0" id="manualLineFormModalLabel" data-i18n="manual_line_form_add_earning">Add additional pay</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body form-compact">
                    <!-- A refused save says why HERE and leaves the form open (the modal is where the
                         values that were refused still are) -- calloutHtml(msg, 'danger'), §15. -->
                    <div id="manualLineFormError" class="d-none"></div>
                    <!-- Outside `.manual-line-form` on purpose: that block's own spacing rules key
                         off its FIRST CHILD, and a hidden input sitting in that slot would push the
                         first visible row down by one gap. -->
                    <input type="hidden" id="manualLineCustomType" value="earning">
                    <div id="manualLineFormFields" class="manual-line-form">
                    <!-- 2026-09-17, R1b: the 3-mode segmented picker (From List / Custom Item /
                         Other) is gone. "Which item is this" was being asked twice -- once as a mode,
                         then again as the field the mode revealed -- and 2 of the 3 modes led to the
                         same single text box. It is now ONE select: the catalog rows for the column
                         that was pressed, plus a pinned last option ("Other (enter a name)") under a
                         divider. Picking that one reveals the name box below and nothing else; the
                         old `other` mode has no way in from the UI any more (the enum still exists
                         server-side, unused-from-here like `not_disbursed`).
                         See docs/decisions/2026-09-17-manual-line-item-picker.md. -->
                    <div class="row g-2 align-items-end manual-line-form-row">
                        <!-- The catalog picker filters itself to the chosen type through the
                             `data-type` attribute this endpoint already honours (input.js re-reads it
                             on every search, see its own docblock) -- the same wiring #eedModal's own
                             catalog picker uses. Its label no longer swaps per type: the modal title
                             ("Add additional pay"/"Add deduction") already says which side this is. -->
                        <!-- 2026-09-18, tiny-L6a: hidden for a calculated line -- which item it is
                             was answered by the row whose pencil was pressed, and the header says so.
                             The amount column then takes the whole row rather than sitting as a
                             third of one with nothing beside it. -->
                        <div class="col-lg-8" id="manualLineItemCol">
                            <label class="form-label" for="manualLineItemSelect" data-i18n="manual_line_item_label">Item</label>
                            <select class="form-select select2-remote" id="manualLineItemSelect" data-api="/api/employee.earning-deduction.options" data-type="earning"></select>
                        </div>
                        <div class="col-lg-4" id="manualLineAmountCol">
                            <label class="form-label" for="manualLineAmount" data-i18n="modal_amount">Amount</label>
                            <!-- 8: money-input + initMoneyInputs() (comma/2-decimal on blur, raw
                                 value mirrored to data-raw-value) instead of a bare number
                                 field -- read back through parseMoneyInput() in detail.js. -->
                            <input type="text" class="form-control money-input" id="manualLineAmount" inputmode="decimal" placeholder="0.00">
                            <!-- The figure the engine itself produced, as context under the field
                                 that replaces it (rules.md §9: ค่าระบบเป็น context, ค่าจริงคือตัวที่อ่าน).
                                 Absent entirely when this line has no recorded calculated value. -->
                            <p class="manual-line-field-hint d-none" id="manualLineComputedHint"></p>
                        </div>
                    </div>
                    <!-- Only reachable by picking the pinned "Other" option above, and full-width on
                         its own row rather than swapped into the picker's column: the picker stays
                         visible so the choice that opened this box can be changed back. -->
                    <div class="row g-2 d-none" id="manualLineCustomFields">
                        <div class="col-12">
                            <label class="form-label" for="manualLineCustomName" data-i18n="modal_custom_item_name">Item Name</label>
                            <input type="text" class="form-control" id="manualLineCustomName" maxlength="150" data-i18n="modal_custom_item_name_placeholder" placeholder="e.g. Uniform deposit refund">
                        </div>
                    </div>
                    <div class="row g-2 manual-line-note-row">
                        <div class="col-12">
                            <label class="form-label" for="manualLineComment" data-i18n="modal_comment">Comment</label>
                            <textarea class="form-control" id="manualLineComment" rows="2" maxlength="255" data-i18n="modal_comment_placeholder" placeholder="e.g. August OT shortfall top-up"></textarea>
                        </div>
                    </div>
                    <!-- Transfer-to-payee (2026-08-21) -- only meaningful when the item being
                         added is a deduction, toggled by syncManualLineTypeDependentsRd() in
                         detail.js.
                         The choice is a segmented control (the confirmed "segmented that is a
                         form's primary choice" exception in rules.md 4 -- selected one is
                         `.btn-primary`), with one gray line under it saying what the chosen
                         routing actually DOES, and every per-choice sub-form indented inside
                         ONE callout block so it reads as belonging to the chosen option. -->
                    <div class="manual-line-payee-block d-none" id="manualLinePayeeTypeWrapper">
                        <!-- 2026-09-16: the choice is 3 destinations (what happens to the
                             money) with one sub-question under the first, not a flat list of
                             payee_type values -- shared markup/behaviour with the other 3
                             payee pickers, see partials/payee-destination.php and
                             docs/decisions/2026-09-16-payee-three-destinations.md.
                             The 3 sub-forms below are this tab's own (they keep their ids,
                             and the component only shows/hides them). -->
                        <?php ob_start(); ?>
                            <div class="d-none" id="manualLinePayeeWrapper">
                                <label class="form-label" for="manualLinePayeeEmployee" data-i18n="payee_employee_label">Payee Employee (transfer to)</label>
                                <select class="form-select select2-remote" id="manualLinePayeeEmployee" data-api="/api/employee.report_to.get" data-type="employee"></select>
                                <div id="manualLinePayeeEmployeeDetail"></div>
                            </div>
                            <!-- 2026-09-10, Batch 3B item 3: level-2 for payee_type='company'. -->
                            <div class="d-none" id="manualLineCompanyAccountWrapper">
                                <label class="form-label" for="manualLineBankAccount" data-i18n="payee_bank_account_label">Company Bank Account</label>
                                <select class="form-select select2-remote" id="manualLineBankAccount" data-api="/api/payroll-cycle.bank-account.options" data-placeholder-key="payee_record_no"></select>
                                <div id="manualLineBankAccountDetail"></div>
                            </div>
                            <!-- 2026-09-02, Deduction Destination and Third-Party Remittance.
                                 2026-09-15: the old "pick a saved one OR leave blank and fill
                                 the fields below" pairing is now an explicit 2-way segmented
                                 choice -- the two paths were never meant to be filled at the
                                 same time. The saved half disappears entirely (with a gray
                                 line in its place) for a company that has none yet. -->
                            <div class="d-none" id="manualLineDestinationWrapper">
                                <!-- 2026-09-15: with nothing saved yet there is no choice to
                                     offer, so the whole segmented row (and the "none saved"
                                     line that used to stand in for it) is absent and the
                                     new-destination form is simply what this block IS. -->
                                <div class="segmented d-none" id="manualLineDestModeToggle">
                                    <input type="radio" name="manualLineDestMode" id="manualLineDestModeSaved" value="saved" checked>
                                    <label for="manualLineDestModeSaved" data-i18n="destination_mode_saved">Choose a saved destination</label>
                                    <input type="radio" name="manualLineDestMode" id="manualLineDestModeNew" value="new">
                                    <label for="manualLineDestModeNew" data-i18n="destination_mode_new">Enter a new one</label>
                                </div>
                                <div id="manualLineDestSavedFields">
                                    <label class="form-label" for="manualLineDestinationSelect" data-i18n="destination_saved_pick_label">Saved destination</label>
                                    <select class="form-select select2-remote" id="manualLineDestinationSelect" data-api="/api/payment-destination.options" data-type="payment_destination" allow-clear="true"></select>
                                    <div id="manualLineDestinationDetail"></div>
                                </div>
                                <div class="d-none" id="manualLineDestinationNewFields">
                                    <div class="row g-2">
                                        <div class="col-sm-6">
                                            <label class="form-label" for="manualLineDestAccountName" data-i18n="destination_account_name">Account Name</label>
                                            <input type="text" class="form-control" id="manualLineDestAccountName" data-i18n="destination_account_name_placeholder" placeholder="e.g., Somchai Jaidee">
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label" for="manualLineDestAccountNo" data-i18n="destination_account_no">Account No.</label>
                                            <input type="text" class="form-control" id="manualLineDestAccountNo" data-i18n="destination_account_no_placeholder" placeholder="e.g., 1234567890">
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label" for="manualLineDestBank" data-i18n="destination_bank">Bank</label>
                                            <select class="form-select select2-remote" id="manualLineDestBank" data-api="/api/bank.get" data-type="bank"></select>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label" for="manualLineDestBankBranch" data-i18n="destination_bank_branch">Branch</label>
                                            <input type="text" class="form-control" id="manualLineDestBankBranch" data-i18n="destination_bank_branch_placeholder" placeholder="e.g., Central World Branch">
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="manualLineDestSaveForReuse">
                                                <label class="form-check-label" for="manualLineDestSaveForReuse" data-i18n="destination_save_for_reuse">Save this destination for reuse next time</label>
                                            </div>
                                            <p class="manual-line-field-hint" data-i18n="destination_save_for_reuse_hint">Saved destinations are shared across the whole company; any employee can pick them.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php
                        $payee_slot = ob_get_clean();
                        $payee_prefix = 'manualLine';
                        include __DIR__ . '/../partials/payee-destination.php';
                        ?>
                    </div>
                    <!-- 2026-09-18, tiny-L6a: a standing per-installment assignment's destination
                         belongs to the assignment itself and is changed on Employee Detail, not per
                         run -- there is no per-run override table for it. So it is stated here, in
                         the same descriptor every other surface uses, with the link to where it CAN
                         be changed, instead of a control that would not write anywhere. -->
                    <div class="manual-line-payee-block d-none" id="manualLinePayeeReadonly">
                        <label class="form-label payee-dest-label" data-i18n="payee_type_label">Send deducted amount to</label>
                        <p class="payee-dest-desc" id="manualLinePayeeReadonlyText"></p>
                        <a href="#" target="_blank" rel="noopener" id="manualLinePayeeReadonlyLink" class="btn btn-link p-0" data-i18n="line_form_payee_open_employee">Change it on the employee's own page</a>
                    </div>
                    </div>
                </div>
                <div class="modal-footer" id="manualLineFormFooter"></div>
            </div>
        </div>
    </div>

</div>
<script>
    const PAYROLL_RUN_ID = <?=(int)$runId?>;
</script>
<script src="<?=asset('public/js/payroll/detail.js')?>"></script>
