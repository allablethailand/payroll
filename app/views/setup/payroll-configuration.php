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
                                <th scope="col" style="width: 22%;" data-i18n="table_cycle_name">Schedule Name</th>
                                <th scope="col" style="width: 13%;" data-i18n="table_frequency">Frequency</th>
                                <th scope="col" style="width: 18%;" data-i18n="table_cutoff">Attendance Cut-off</th>
                                <th scope="col" style="width: 18%;" data-i18n="table_payment_day">Payment Day</th>
                                <th scope="col" style="width: 15%;" data-i18n="table_bank_format">Bank Format</th>
                                <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                                <th scope="col" style="width: 6%; text-align: center;"></th>
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
                            <th scope="col" style="width: 12%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 23%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 17%;" data-i18n="col_calc_method">Calculation</th>
                            <th scope="col" style="width: 16%;" data-i18n="col_tax_type">Tax Treatment</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_sso">SSO Cal</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_pf">Provident Fund</th>
                            <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
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
                            <th scope="col" style="width: 15%;" data-i18n="col_code">Code</th>
                            <th scope="col" style="width: 30%;" data-i18n="col_name">Item Name</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_calc_method">Calculation</th>
                            <th scope="col" style="width: 20%;" data-i18n="col_deduct_type">Tax Deduction Impact</th>
                            <th scope="col" style="width: 10%;" data-i18n="col_status">Status</th>
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
                                <label class="form-label mb-2" data-i18n="policy_reopen_days_label">Reopen Window (days)</label>
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
                        <!-- 2026-08-30, explicit follow-up: "การจัดวางข้อมูลในแต่ละ Card ช่วยปรับให้หน่อยครับ
                             ตอนนี้ดูแน่นไปหมด ไม่เป็นระเบียบ" -- 3 logical field groups now get real
                             breathing room (g-4 gutters, mb-4/mb-5 between groups instead of g-3/mb-3),
                             and the trailing 2 checkboxes (previously loose, felt like an afterthought)
                             are now their own labeled .settings-subgroup panel so the card reads as 3
                             clearly separated sections instead of one dense block. -->
                        <div class="row g-4 mb-5">
                            <div class="col-md-6">
                                <label class="form-label mb-2" data-i18n="policy_probation_period_days_label">Standard Probation Period (days)</label>
                                <div class="input-group">
                                    <input type="number" min="0" step="1" class="form-control" id="policyProbationPeriodDays" data-i18n="policy_probation_period_days_placeholder" placeholder="Not set">
                                    <span class="input-group-text" data-i18n="days_suffix">days</span>
                                </div>
                                <div class="form-text mt-2" data-i18n="policy_probation_period_days_hint">Reference only, e.g. 119 -- for display/planning. Does not by itself change any calculation below; those are always driven by the employee's actual Employment Status.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label mb-2" data-i18n="policy_probation_base_salary_ratio_label">Base Salary Ratio During Probation</label>
                                <div class="input-group">
                                    <input type="number" min="1" max="100" step="0.01" class="form-control" id="policyProbationBaseSalaryRatio" data-i18n="policy_probation_base_salary_ratio_placeholder" placeholder="100 (no reduction)">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>
                        <div class="row g-4 mb-5">
                            <div class="col-md-6">
                                <label class="form-label mb-2" data-i18n="policy_pay_basis_label">Base Salary Basis</label>
                                <select class="form-select select2-static" id="policyPayBasis" data-option-keys="policy_pay_basis_full_month,policy_pay_basis_schedule_based,policy_pay_basis_sync_actual_days" data-option-values="full_month,schedule_based,sync_actual_days"></select>
                                <div class="form-text mt-2" data-i18n="policy_pay_basis_description">Only takes effect while Employment Status = Probation -- daily/hourly-rate employees are unaffected either way.</div>
                                <!-- 2026-08-30, explicit follow-up: reconciles Origami's own synced PROBATION_WORKING_DAYS
                                     against the schedule-based option above -- a THIRD, separate choice (not a modifier of
                                     "Schedule-based") since it reads real attendance from Origami sync instead of Shift+
                                     Holiday config, and only has data to work with on a sync-pulled run. -->
                                <div class="form-text mt-1" data-i18n="policy_pay_basis_sync_actual_days_hint">"Actual Days (Origami Sync)" only has data on a run pulled from Origami Payroll Sync -- any other run (or a cycle where Origami itself didn't send this figure) pays the full base salary instead, flagged visibly on that employee's calculation row.</div>
                            </div>
                            <div class="col-md-6 d-none" id="policyPayBasisSubOptions">
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
                        <div class="settings-subgroup">
                            <div class="settings-subgroup-label" data-i18n="policy_probation_additional_conditions_label">Additional Conditions</div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="policyProbationDeferPvd">
                                <label class="form-check-label" for="policyProbationDeferPvd" data-i18n="policy_probation_defer_pvd_label">Defer Provident Fund (PVD) contribution until probation passes</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="policyProbationDeferRecurringEarning">
                                <label class="form-check-label" for="policyProbationDeferRecurringEarning" data-i18n="policy_probation_defer_recurring_label">Withhold Recurring Allowances (position/car/fuel, etc.) until probation passes</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-end">
                    <button type="button" class="btn btn-primary btn-sm" id="btnSavePayrollPolicies"><i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span></button>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- itemModal / attendanceDeductionRuleModal moved to app/views/layout/modals.php
     (2026-08-30, modal consolidation). -->
<script src="<?=asset('public/js/setup/payroll-configuration.js')?>"></script>
