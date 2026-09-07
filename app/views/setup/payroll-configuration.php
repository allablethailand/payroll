<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="settings">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="payroll_cycle">Payroll Schedule</span>
        </h5>
    </nav>
    <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
         app/views/payroll/index.php). -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="payroll_configuration">Payroll Configuration</h5>
            <p class="page-header-card-desc" data-i18n="payroll_configuration_description">Set up payroll schedules, income types, and deduction types by employee group or employment type.</p>
        </div>
    </div>
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu active" id="cycle-tab" data-bs-toggle="tab" data-bs-target="#cycle-pane" type="button" role="tab" aria-controls="cycle-pane" aria-selected="true">
                <i class="fa-regular fa-calendar-days me-2"></i><span data-i18n="cycle">Schedule</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="earnings-tab" data-bs-toggle="tab" data-bs-target="#earnings-pane" type="button" role="tab" aria-controls="earnings-pane" aria-selected="false">
                <i class="fa-solid fa-calendar-day me-2"></i><span data-i18n="earnings">Income</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="deductions-tab" data-bs-toggle="tab" data-bs-target="#deductions-pane" type="button" role="tab" aria-controls="deductions-pane" aria-selected="false">
                <i class="fa-regular fa-calendar-check me-2"></i><span data-i18n="deductions">Deductions</span>
            </button>
        </li>
        <!-- 2026-08-21, explicit request: own tab right after Deductions, replacing the old button+shared-
             modal-with-pill-switcher entry point on the Deductions tab. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="attendance-deduction-tab" data-bs-toggle="tab" data-bs-target="#attendance-deduction-pane" type="button" role="tab" aria-controls="attendance-deduction-pane" aria-selected="false">
                <i class="fa-solid fa-clock-rotate-left me-2"></i><span data-i18n="attendance_deduction">Attendance Deduction</span>
            </button>
        </li>
        <!-- 2026-08-29, explicit request: "ตัดเบี้ยขยันและการบันทึกเบี้ยขยันออกจากการตั้งค่า และไม่นำไปคำนวณ
             ในเงินเดือน แต่ใน Income ยังคงมีไว้ เพราะจะเชื่อมมาจาก Origami แทน" -- the Attendance Bonus
             (scheme config) and Ledger (streak recording) tabs are removed entirely, along with
             recalculate()'s own attendance_bonus_ledger pull into payroll lines (see
             PayrollRunModel.php's own comment on this). The DILIGENCE catalog row itself in the
             Income (Earning Types) tab is DELIBERATELY untouched -- it becomes purely a sync target
             going forward (Origami will push it as a regular item_values line, matched by item_code
             the same generic way ค่าเที่ยว/TRIP_ALLOW already is, see SyncPayResolver's own generic
             item-matching loop -- no source_event_code wiring was needed for this, that loop already
             handles any item_code by catalog match).
             2026-08-29 same-day follow-up, explicit: "ถ้ามีลบเพิ่มไฟล์ .sql ให้ด้วยครับ" -- the backend
             (AttendanceBonusSchemeModel/AttendanceBonusLedgerModel, their controller endpoints/
             routes, and the attendance_bonus_schemes/attendance_bonus_ledger tables themselves,
             including the 1+1 real rows that were in this dev DB) is now fully removed too, not just
             left in place unreachable -- see database/migrations/2026-08-29_drop_attendance_bonus_tables.sql. -->
        <!-- 2026-08-30, explicit request: "เพิ่มอีก Tab เป็น Tab ตั้งค่าในหน้าตั้งค่าเงินเดือนเลย เดี๋ยวมีอีก
             หลายหัวข้อครับ" -- a home for company-wide payroll POLICIES/RULES (as opposed to the other
             tabs' own catalog/schedule CONFIGURATION) -- starts with the reopen-window setting, more
             sections land here over time as later requests arrive (see PayrollPolicyModel's own
             docblock). -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="policies-tab" data-bs-toggle="tab" data-bs-target="#policies-pane" type="button" role="tab" aria-controls="policies-pane" aria-selected="false">
                <i class="fa-solid fa-shield-halved me-2"></i><span data-i18n="payroll_policies">Payroll Policies</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
        <div class="tab-pane fade show active" id="cycle-pane" role="tabpanel" aria-labelledby="cycle-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <div class="mt-5 mb-5">
                    <table class="table table-hover table-border align-middle w-100" id="tb_payroll_cycle">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                                <th scope="col" style="width: 18%;" data-i18n="table_cycle_name">Schedule Name</th>
                                <th scope="col" style="width: 10%;" data-i18n="table_frequency">Frequency</th>
                                <!-- 2026-09-02, reply from Origami's own team re: payroll schedule mapping --
                                     see modals.php's own comment on #external_cycle_code for the full context. -->
                                <th scope="col" style="width: 13%;" data-i18n="table_external_cycle_code">External Cycle Code</th>
                                <th scope="col" style="width: 15%;" data-i18n="table_cutoff">Attendance Cut-off</th>
                                <th scope="col" style="width: 15%;" data-i18n="table_payment_day">Payment Day</th>
                                <th scope="col" style="width: 13%;" data-i18n="table_bank_format">Bank Format</th>
                                <th scope="col" style="width: 8%; text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <!-- payrollCycleModal moved to app/views/layout/modals.php (2026-08-30, modal consolidation). -->
            </div>
        </div>
        <div class="tab-pane fade" id="earnings-pane" role="tabpanel" aria-labelledby="earnings-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="tb_earning_type">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                            <th scope="col" style="width: 12%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 23%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 17%;" data-i18n="col_calc_method">Calculation</th>
                            <th scope="col" style="width: 16%;" data-i18n="col_tax_type">Tax Treatment</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_sso">SSO Cal</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_pf">Provident Fund</th>
                            <th scope="col" style="width: 4%; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="deductions-pane" role="tabpanel" aria-labelledby="deductions-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <table class="table table-hover table-border align-middle w-100" id="tb_deduction_type">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th scope="col" style="width: 10%;" data-i18n="col_status">Status</th>
                            <th scope="col" style="width: 15%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 30%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_calc_method">Calculation</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_deduct_type">Tax Deduction Impact</th>
                            <th scope="col" style="width: 5%; text-align: center;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="tab-pane fade" id="attendance-deduction-pane" role="tabpanel" aria-labelledby="attendance-deduction-tab" tabindex="0">
            <div class="mt-5 mb-5">
                <p class="text-muted small mb-4" data-i18n="attendance_deduction_rule_description">Choose how each deduction is calculated, and set your own condition(s) per item. This is used automatically the next time payroll is calculated from synced attendance data.</p>
                <!-- 2026-08-30, explicit follow-up: "กฎการหักตามข้อมูลเข้างาน ก็ให้เป็น Card เหมือนกัน แต่แยกสี
                     ตามกลุ่มการหักครับ" -- reverted from a table (this same day's earlier "อยากให้ปรับให้เป็น
                     ตาราง" request) back to cards, one per EVENT, color-coded by deduction group (same
                     rt-1/rt-3/rt-4/rt-5 palette .row-type-icon already uses for late/absent/
                     unpaid_leave/leave_pending). Each event card's own body lists that event's rule
                     variants (company-wide default + any team/department-scoped overrides added via
                     Clone) as compact rows, not nested cards. See payroll-configuration.js's
                     renderAttendanceDeductionCards()/attendanceDeductionEventCardHtml(). -->
                <div id="attendanceDeductionCardsContainer"></div>
            </div>
        </div>
        <div class="tab-pane fade" id="policies-pane" role="tabpanel" aria-labelledby="policies-tab" tabindex="0">
            <!-- 2026-08-30, explicit request: "นโยบายการทำเงินเดือน Design ดูแปลกๆไม่เป็นระเบียบ" --
                 rewrapped every card from a plain, undifferentiated .card-surface into
                 .settings-info-card (new shared component, style.css -- same one Company Profile's
                 Signatory & Branding tab uses) so each topic has a real gradient-header title+
                 description strip instead of a bare <h6>, fixing the "one lonely small field floating
                 in a big empty card" look the Reopen card had (its own description moved INTO the
                 header, which now carries real visual weight, instead of sitting as a thin caption
                 line above the field). ONE shared Save button for the whole tab (below), not one per
                 card -- PayrollPolicyModel::save() writes every column together on a single row per
                 company, so a per-card Save would silently reset whichever OTHER card's fields it
                 didn't send back to their defaults (same "must preserve the rest of the payload" bug
                 class already caught and fixed once in this same session, see
                 saveAttendanceDeductionRule()'s own comment). -->
            <div class="mt-5 mb-5">
                <!-- 2026-08-30, explicit follow-up: "นโยบายการทำเงินเดือน ปรับให้เป็น card ละแถวเหมือนเดิม
                     ครับ" -- reverted from the 3-column row (col-lg-4 each) back to one full-width
                     card per row, stacked -- same .settings-info-card visual language kept (gradient
                     header etc.), only the LAYOUT (row/col-lg-4 -> plain stacked mb-4) changed. -->
                <div class="settings-info-card mb-4" id="policyReopenCard">
                    <div class="settings-info-card-header">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <div>
                            <p class="settings-info-card-title" data-i18n="policy_reopen_title">Reopen Window</p>
                            <p class="settings-info-card-desc" data-i18n="policy_reopen_description">How many days after a payroll run is closed (locked, or paid if never locked) it can still be reopened for editing. Leave blank for no limit.</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body">
                        <div class="row">
                            <div class="col-sm-5 col-md-4">
                                <label class="form-label mb-1" data-i18n="policy_reopen_days_label">Reopen Window (days)</label>
                                <div class="input-group">
                                    <input type="number" min="0" step="1" class="form-control" id="policyReopenWindowDays" data-i18n="policy_reopen_days_placeholder" placeholder="Unlimited">
                                    <span class="input-group-text" data-i18n="days_suffix">days</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 2026-08-30, explicit follow-up: "พื้นฐานการจ่ายเงินเดือน ที่ตั้งจะนำไปคำนวณแค่พนักงานที่
                     ทดลองงานใช่ไหม ถ้าไม่ใช่ช่วยปรับให้เป็นเงื่อนไขของการทดลองงานเท่านั้น" -- confirmed: it was
                     NOT probation-gated before this (applied to every monthly-rate employee, company-
                     wide) -- now IS, matching every other setting in this card. PayrollRunModel::
                     recalculate() only takes the schedule_based branch when
                     employees.employment_status === 'probation' (same real HR-maintained status gate
                     every other field here already uses) -- see that method's own comment at the
                     $payBasisSettings read site. Since EVERYTHING in this card is now probation-only,
                     collapsed back from 2 labeled sub-sections (Pay Basis / Probation Pay Conditions)
                     into ONE flowing "Probation Pay Conditions" card -- the sub-section split existed
                     specifically to flag that Pay Basis had a DIFFERENT (broader) scope than the rest,
                     which is no longer true, so keeping the split would now be misleading rather than
                     clarifying.
                     Same-message layout request: "ปรับให้ ระยะเวลาทดลองงานมาตรฐาน (วัน) และ อัตราส่วนฐานเงิน
                     เดือนช่วงทดลองงาน เป็น column ซ้ายขวา เพราะฝั่งขวามีพื้นที่ว่าง" -- both fields widened
                     from col-md-3 (leaving half the row empty) to col-md-6 (even left/right split);
                     Base Salary Basis + its sub-options follow the same col-md-6/col-md-6 rhythm right
                     below, for a consistent 2-column layout throughout the whole card. -->
                <div class="settings-info-card mb-4" id="policyProbationCard">
                    <div class="settings-info-card-header">
                        <i class="fa-solid fa-user-clock"></i>
                        <div>
                            <p class="settings-info-card-title" data-i18n="policy_probation_title">Probation Pay Conditions</p>
                            <p class="settings-info-card-desc" data-i18n="policy_probation_description">Applies only to employees whose Employment Status is currently "Probation" -- switches back to normal automatically the moment their status changes.</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body">
                        <!-- 2026-08-30 (Phase 2, T016, explicit request: "เปลี่ยนเป็น radio (2 ตัวเลือก)... ถ้าเลือก
                             option ที่ต้องใส่เงื่อนไข ให้แสดงช่องกรอกเงื่อนไขต่ออีกบรรทัดใต้ radio" -- confirmed with
                             user that the field itself (Base Salary Basis) is correct, radio buttons for the real,
                             current 3 options, not the 2 the ticket text was written against before
                             sync_actual_days existed) -- was a <select>, now 3 radio buttons stacked as their own
                             full-width row; the schedule_based-only sub-options moved from a side-by-side column
                             into their own row directly below the radio group (as asked), rather than sitting
                             beside it. -->
                        <div class="row g-4 mb-3">
                            <div class="col-12">
                                <label class="form-label mb-1" data-i18n="policy_pay_basis_label">Base Salary Basis</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="policyPayBasisRadio" id="policyPayBasisFullMonth" value="full_month">
                                    <label class="form-check-label" for="policyPayBasisFullMonth" data-i18n="policy_pay_basis_full_month">Full Month</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="policyPayBasisRadio" id="policyPayBasisScheduleBased" value="schedule_based">
                                    <label class="form-check-label" for="policyPayBasisScheduleBased" data-i18n="policy_pay_basis_schedule_based">Schedule-based (Shift + Holiday config)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="policyPayBasisRadio" id="policyPayBasisSyncActualDays" value="sync_actual_days">
                                    <label class="form-check-label" for="policyPayBasisSyncActualDays" data-i18n="policy_pay_basis_sync_actual_days">Actual Days (Origami Sync)</label>
                                </div>
                                <div class="form-text mt-2" data-i18n="policy_pay_basis_description">Only takes effect while Employment Status = Probation -- daily/hourly-rate employees are unaffected either way.</div>
                                <!-- 2026-08-30, explicit follow-up: reconciles Origami's own synced PROBATION_WORKING_DAYS
                                     against the schedule-based option above -- a THIRD, separate choice (not a modifier of
                                     "Schedule-based") since it reads real attendance from Origami sync instead of Shift+
                                     Holiday config, and only has data to work with on a sync-pulled run. -->
                                <div class="form-text mt-1" data-i18n="policy_pay_basis_sync_actual_days_hint">"Actual Days (Origami Sync)" only has data on a run pulled from Origami Payroll Sync -- any other run (or a cycle where Origami itself didn't send this figure) pays the full base salary instead, flagged visibly on that employee's calculation row.</div>
                            </div>
                        </div>
                        <div class="row g-4 mb-5">
                            <div class="col-12 d-none" id="policyPayBasisSubOptions">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="policyPayBasisDeductHolidays">
                                    <label class="form-check-label" for="policyPayBasisDeductHolidays" data-i18n="policy_pay_basis_deduct_holidays_label">Exclude holidays from payable days</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="policyPayBasisDeductLeave">
                                    <label class="form-check-label" for="policyPayBasisDeductLeave" data-i18n="policy_pay_basis_deduct_leave_label">Exclude approved unpaid leave from payable days</label>
                                </div>
                                <div class="form-text" data-i18n="policy_pay_basis_hint">Both left off still pays 100% of base salary for a normal period -- these only reduce pay when a holiday/unpaid leave actually falls inside the pay period.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 2026-09-04, Backlog Phase 10, T056 ("Probation setting gains Clone + Assign, using
                     T055's template"): the 9 probation_* fields that used to live directly on THIS
                     card's own form (period days/base salary ratio/defer PVD/defer SSO/defer recurring
                     earning/leave rights/OT-eligible-default/tax-exempt-default) are no longer a single
                     company-wide singleton -- they're now `probation_policy_sets`, multiple named,
                     cloneable Sets with exactly one mandatory Default, each optionally ASSIGNED to
                     specific departments/positions/teams/employees (T055's entity_assignments, via
                     assign-widget.js) -- same architecture as OtRateSetModel/ot_rate_sets, this
                     project's own closest precedent for "one setting -> many assignable Sets". See
                     PayrollPolicyModel::probationSettings()'s own docblock for the full resolution
                     logic. Base Salary Basis (pay_basis, the radio group above) is a SEPARATE,
                     unrelated company-wide singleton -- still gated by the same employment_status=
                     'probation' check at calculation time, but NOT part of this Set architecture, left
                     untouched on the form above. -->
                <div class="settings-info-card mb-4" id="probationSetsCard">
                    <div class="settings-info-card-header">
                        <i class="fa-solid fa-layer-group"></i>
                        <div>
                            <p class="settings-info-card-title" data-i18n="probation_sets_title">Probation Policy Sets</p>
                            <p class="settings-info-card-desc" data-i18n="probation_sets_description">Multiple named probation policies, each optionally assigned to specific departments/positions/teams/employees. Exactly one Set is always the company-wide Default.</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <button type="button" class="btn btn-primary btn-sm" id="btnAddProbationSet">
                                <i class="fa-solid fa-plus me-1"></i><span data-i18n="probation_add_set">Add Set</span>
                            </button>
                        </div>
                        <div id="probationSetsContainer"></div>
                    </div>
                </div>
                <!-- 2026-08-31, explicit request: "ในหน้านโยบายการทำเงินเดือน ก็มีให้ตั้งค่าสำหรับเด็กฝึกงาน
                     และยังไม่ผ่านโปรไว้เหมือนเดิมเป็น Default" -- direct mirror of #policyProbationCard
                     immediately above, own SEPARATE field set (confirmed via AskUserQuestion: NOT
                     reusing the probation_* fields) gated by employment_type='internship' instead of
                     employment_status='probation' -- see PayrollPolicyModel::internSettings()'s own
                     docblock and PayrollRunModel's own precedence comment for what happens when an
                     employee is somehow both. No "Standard Period (days)"/"Base Salary Basis" fields
                     here -- those are Probation-specific concepts (probation_period_days is reference-
                     only display text about a probation window; pay_basis's schedule/sync-actual-days
                     options are ALSO explicitly probation-status-gated, per that field's own docblock
                     -- neither has an internship equivalent asked for in this request). -->
                <div class="settings-info-card mb-4" id="policyInternCard">
                    <div class="settings-info-card-header">
                        <i class="fa-solid fa-user-graduate"></i>
                        <div>
                            <p class="settings-info-card-title" data-i18n="policy_intern_title">Internship Pay Conditions</p>
                            <p class="settings-info-card-desc" data-i18n="policy_intern_description">Applies only to employees whose Employment Type is "Internship" -- switches back to normal automatically the moment their type changes. Can also be overridden per employee on their own Salary tab.</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body">
                        <div class="row g-4 mb-5">
                            <div class="col-md-6">
                                <label class="form-label mb-1" data-i18n="policy_intern_period_days_label">Standard Internship Period (days)</label>
                                <div class="input-group">
                                    <input type="number" min="0" step="1" class="form-control" id="policyInternPeriodDays" data-i18n="policy_probation_period_days_placeholder" placeholder="Not set">
                                    <span class="input-group-text" data-i18n="days_suffix">days</span>
                                </div>
                                <div class="form-text mt-2" data-i18n="policy_intern_period_days_hint">Reference only -- for display/planning. Does not by itself change any calculation below; those are always driven by the employee's actual Employment Type.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label mb-1" data-i18n="policy_intern_base_salary_ratio_label">Base Salary Ratio for Salaried Interns</label>
                                <div class="input-group">
                                    <input type="number" min="1" max="100" step="0.01" class="form-control" id="policyInternBaseSalaryRatio" data-i18n="policy_probation_base_salary_ratio_placeholder" placeholder="100 (no reduction)">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text mt-2" data-i18n="policy_intern_base_salary_ratio_hint">Applied as a further multiplier on top of whatever base pay calculation already produced (same convention as Probation's own ratio) -- for a daily-allowance intern (salary_type=Daily), leave this blank/100% unless you deliberately also want to further reduce their real daily proration by this percentage.</div>
                            </div>
                        </div>
                        <!-- 2026-08-31, explicit follow-up: "เงื่อนไขการจ่ายเงินเด็กฝึกงาน...จ่ายเต็มเดือน หรือจ่าย
                             แค่วันที่มาทำจริง หักลา หักวันหยุดไหม เหมือน Probation" -- direct mirror of
                             #policyPayBasisRadio above, own separate field set
                             (PayrollPolicyModel::internPayBasisSettings()). -->
                        <div class="row g-4 mb-3">
                            <div class="col-12">
                                <label class="form-label mb-1" data-i18n="policy_intern_pay_basis_label">Base Salary Basis</label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="policyInternPayBasisRadio" id="policyInternPayBasisFullMonth" value="full_month">
                                    <label class="form-check-label" for="policyInternPayBasisFullMonth" data-i18n="policy_pay_basis_full_month">Full Month</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="policyInternPayBasisRadio" id="policyInternPayBasisScheduleBased" value="schedule_based">
                                    <label class="form-check-label" for="policyInternPayBasisScheduleBased" data-i18n="policy_pay_basis_schedule_based">Schedule-based (Shift + Holiday config)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="policyInternPayBasisRadio" id="policyInternPayBasisSyncActualDays" value="sync_actual_days">
                                    <label class="form-check-label" for="policyInternPayBasisSyncActualDays" data-i18n="policy_pay_basis_sync_actual_days">Actual Days (Origami Sync)</label>
                                </div>
                                <div class="form-text mt-2" data-i18n="policy_intern_pay_basis_description">Only takes effect while Employment Type = Internship -- daily/hourly-rate interns are unaffected either way.</div>
                                <div class="form-text mt-1" data-i18n="policy_pay_basis_sync_actual_days_hint">"Actual Days (Origami Sync)" only has data on a run pulled from Origami Payroll Sync -- any other run (or a cycle where Origami itself didn't send this figure) pays the full base salary instead, flagged visibly on that employee's calculation row.</div>
                            </div>
                        </div>
                        <div class="row g-4 mb-5">
                            <div class="col-12 d-none" id="policyInternPayBasisSubOptions">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="policyInternPayBasisDeductHolidays">
                                    <label class="form-check-label" for="policyInternPayBasisDeductHolidays" data-i18n="policy_pay_basis_deduct_holidays_label">Exclude holidays from payable days</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="policyInternPayBasisDeductLeave">
                                    <label class="form-check-label" for="policyInternPayBasisDeductLeave" data-i18n="policy_pay_basis_deduct_leave_label">Exclude approved unpaid leave from payable days</label>
                                </div>
                                <div class="form-text" data-i18n="policy_pay_basis_hint">Both left off still pays 100% of base salary for a normal period -- these only reduce pay when a holiday/unpaid leave actually falls inside the pay period.</div>
                            </div>
                        </div>
                        <div class="settings-subgroup">
                            <div class="settings-subgroup-label" data-i18n="policy_probation_additional_conditions_label">Additional Conditions</div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="policyInternDeferPvd">
                                <label class="form-check-label" for="policyInternDeferPvd" data-i18n="policy_intern_defer_pvd_label">Defer Provident Fund (PVD) contribution for interns</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="policyInternDeferSso">
                                <label class="form-check-label" for="policyInternDeferSso" data-i18n="policy_intern_defer_sso_label">Defer Social Security Fund (SSO) contribution for interns</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="policyInternDeferRecurringEarning">
                                <label class="form-check-label" for="policyInternDeferRecurringEarning" data-i18n="policy_intern_defer_recurring_label">Withhold Recurring Allowances (position/car/fuel, etc.) for interns</label>
                            </div>
                        </div>
                        <!-- 2026-09-02, explicit request: extend Internship pay policy with leave/OT
                             rights during the period -- direct mirror of #policyProbationCard's own
                             new subgroup immediately above, own separate field set/columns. -->
                        <div class="settings-subgroup mt-4">
                            <div class="settings-subgroup-label" data-i18n="policy_intern_leave_ot_label">Leave &amp; OT Rights During Internship</div>
                            <div class="row g-4 align-items-start">
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="policyAllowLeaveDuringIntern" checked>
                                        <label class="form-check-label" for="policyAllowLeaveDuringIntern" data-i18n="policy_allow_leave_label">Allow leave requests during probation</label>
                                    </div>
                                    <label class="form-label mb-1" data-i18n="policy_leave_days_limit_label">Leave Days Limit</label>
                                    <div class="input-group">
                                        <input type="number" min="0" step="1" class="form-control" id="policyInternLeaveDaysLimit" data-i18n="policy_leave_days_limit_placeholder" placeholder="No limit">
                                        <span class="input-group-text" data-i18n="days_suffix">days</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label mb-1" data-i18n="policy_ot_eligible_default_label">OT Eligible (Default)</label>
                                    <select class="form-select select2-static" id="policyInternOtEligibleDefault" data-option-keys="policy_ot_default_not_set,policy_ot_default_eligible,policy_ot_default_not_eligible" data-option-values=",1,0"></select>
                                    <div class="form-text mt-2" data-i18n="policy_ot_eligible_default_hint">Only a starting suggestion, applied once when an employee enters this status -- the employee's own OT Eligible checkbox (Salary tab) can always be changed afterward.</div>
                                </div>
                            </div>
                        </div>
                        <div class="settings-subgroup mt-4">
                            <div class="settings-subgroup-label" data-i18n="policy_tax_default_label">Tax Withholding (Default)</div>
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label mb-1" data-i18n="policy_tax_exempt_default_label">Tax Exempt (Default)</label>
                                    <select class="form-select select2-static" id="policyInternTaxExemptDefault" data-option-keys="policy_ot_default_not_set,policy_tax_default_exempt,policy_tax_default_not_exempt" data-option-values=",1,0"></select>
                                    <div class="form-text mt-2" data-i18n="policy_tax_exempt_default_hint">Only a starting suggestion, applied once when an employee is created directly into this status -- the employee's own Tax Exempt checkbox (Salary tab) can always be changed afterward.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 3 of the Origami
                     `attribution` field's own deferred list): a company-configured flat withholding
                     % used ONLY by a supplemental run that explicitly opts in on its own Pull-to-Run
                     screen (payroll_runs.use_flat_tax_rate) -- see PayrollPolicyModel::flatTaxRatePercent()'s
                     own docblock and PayrollRunModel::recalculate()'s own TH_PIT block for what this
                     actually changes. Left blank = the opt-in checkbox is a no-op, normal average/
                     actual PIT calculation still applies. -->
                <div class="settings-info-card mb-4" id="policySupplementalTaxCard">
                    <div class="settings-info-card-header">
                        <i class="fa-solid fa-percent"></i>
                        <div>
                            <p class="settings-info-card-title" data-i18n="policy_flat_tax_title">Supplemental Run Flat Withholding Rate</p>
                            <p class="settings-info-card-desc" data-i18n="policy_flat_tax_description">Used only when a supplemental (off-cycle) run explicitly opts in on its own Pull-to-Run screen, for an Origami-attributed batch marked "Withhold Separately." Left blank, the normal average/actual PIT calculation is used instead.</p>
                        </div>
                    </div>
                    <div class="settings-info-card-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label mb-1" data-i18n="policy_flat_tax_rate_label">Flat Withholding Rate</label>
                                <div class="input-group">
                                    <input type="number" min="0" max="100" step="0.01" class="form-control" id="policySupplementalFlatTaxRate" placeholder="0.00">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text mt-2" data-i18n="policy_flat_tax_rate_hint">Applied against the supplemental run's own taxable gross for that period only -- a standalone lump-sum withholding, not folded into the employee's annual/cumulative tax curve.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-light border btn-sm" id="btnCancelPayrollPolicies"><i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span></button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSavePayrollPolicies"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- itemModal / attendanceDeductionRuleModal moved to app/views/layout/modals.php
     (2026-08-30, modal consolidation). -->
<script src="<?=asset('public/js/setup/payroll-configuration.js')?>"></script>
