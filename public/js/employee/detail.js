let currentEmployeeId = null;
let childTablesLoaded = false;
// 2026-09-02, Platform Hardening Phase 1.2 -- baseline snapshot for cancelEmployeeEdit()'s
// dirty-check (see app.js's snapshotFormState()/confirmIfDirtyThen()). Re-taken every time the
// WHOLE form is repopulated from the server (initial load, and after cancelEmployeeEdit() itself
// re-fetches) and after every successful per-tab save, so a Cancel click always compares against
// "the last state we know is saved," not the page's very first load.
let employeeFormBaselineSnapshot = null;
function refreshEmployeeFormBaseline() {
    if (typeof snapshotFormState === 'function') {
        employeeFormBaselineSnapshot = snapshotFormState($('#employeeTabsContent'));
    }
}
// Shared Cancel handler for all 6 tab Save buttons. An existing employee: dirty-check, then
// re-fetch via the SAME GET /api/employee.get the initial page load uses and repopulate the WHOLE
// form (mirrors company-profile.js's own .cancel-company-profile reference implementation -- AJAX
// re-fetch + repopulate, not a hard page reload) -- deliberately does NOT re-run
// loadEmployeeIfEditing()'s one-time side effects (loadAllChildTables()/loadDocumentList()/
// activateEmployeeTabFromHash()), since those already ran once and child-table data wasn't part of
// what the user was editing on this form. A brand-new, not-yet-saved employee has nothing on the
// server to revert to -- per the playbook's own "reset to blank/default" rule for that case, a full
// page reload IS the reset (an unsaved new-employee form has no persisted data to lose).
function cancelEmployeeEdit() {
    if (!currentEmployeeId) {
        confirmIfDirtyThen($('#employeeTabsContent'), employeeFormBaselineSnapshot, function () {
            window.location.reload();
        });
        return;
    }
    confirmIfDirtyThen($('#employeeTabsContent'), employeeFormBaselineSnapshot, function () {
        const employeeNo = $('#employee_no').val();
        $.ajax({
            url: `${BASE_URL}/api/employee.get`,
            method: 'GET',
            data: { employee_no: employeeNo },
            dataType: 'json',
            success: function (res) {
                if (res.status && res.data) {
                    populateEmployeeForm(res.data);
                    renderProfileHeader(res.data);
                    refreshEmployeeFormBaseline();
                } else {
                    showWarning(res.message || langData['load_employee_failed'] || 'Failed to load employee data.');
                }
            },
            error: function () {
                showWarning(langData['load_employee_failed'] || 'Failed to load employee data.');
            }
        });
    });
}
const TAB_BUTTON_BY_PANE = {
    'info-pane': 'info-tab',
    'contact-pane': 'contact-tab',
    'employment-pane': 'employment-tab',
    'salary-pane': 'salary-tab',
    'earningDeduction-pane': 'earningDeduction-tab',
    'social-pane': 'social-tab',
    'family-pane': 'family-tab',
    'documents-pane': 'documents-tab'
};
function toIsoDate(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function toDisplayDate(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function isValidThaiID(id) {
    if (!/^\d{13}$/.test(id)) return false;
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += parseInt(id.charAt(i), 10) * (13 - i);
    }
    const check = (11 - (sum % 11)) % 10;
    return check === parseInt(id.charAt(12), 10);
}
function initMobileIti() {
    if (typeof intlTelInput !== 'function') return;
    const el = document.getElementById('mobile_no');
    if (!el) return;
    window.mobileIti = intlTelInput(el, {
        initialCountry: 'th',
        separateDialCode: true,
        numberDisplayFormat: 'NATIONAL',
        // formatAsYouType/strictMode off: mobile_no is validated/stored server-side as plain
        // digits only (EmployeeModel::save(), /^\d{9,10}$/ TH / /^\d{7,15}$/ elsewhere) -- the
        // library's live formatting inserts spaces per country, which would fail that regex.
        formatAsYouType: false,
        strictMode: false,
        countrySearch: true
    });
    syncMobileCountryCode();
    el.addEventListener('countrychange', syncMobileCountryCode);
}
function syncMobileCountryCode() {
    if (!window.mobileIti) return;
    const c = window.mobileIti.getSelectedCountry();
    $('#mobile_country_code').val(c && c.dialCode ? ('+' + c.dialCode) : '+66');
}
function applyEmployeeTypeRequired(type) {
    $('#id_card_no').toggleClass('required', type === 'domestic').removeClass('is-invalid');
    $('#tax_id_no, #passport_no, #work_permit_no, #date_work_permit_issue, #date_work_permit_expire')
        .toggleClass('required', type === 'foreigner').removeClass('is-invalid');
}
// 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) -- replaces the
// old radio-driven applyPaymentTypeRequired(bank/cash). `code` is master_payment_methods' own code
// (transfer/cash/check/mixed), read from #payment_method_code (kept in sync by the select2:select
// handler below and by populateEmployeeForm() on load).
function applyPaymentMethodVisibility(code) {
    $('#payment_method_code').val(code || '');
    // 2026-09-02, real gap found and fixed BEFORE shipping (not guessed -- caught while wiring the
    // payroll engine's own BankTransferFileReport read of this same data): a mixed line's own
    // bank_account_id (employee_payment_method_lines) is which COMPANY account PAYS that line, the
    // exact same concept as default_bank_account_id -- it is NOT the employee's own RECEIVING bank
    // account number, which only ever lives in this section's employees.bank_id/bank_account_no
    // fields. A mixed set with any transfer line still needs those filled in (there's only ONE
    // place money is actually deposited for an employee, regardless of how many lines route
    // through transfer) -- shown (not required, see below -- a mixed set might have zero transfer
    // lines) whenever code is 'transfer' OR 'mixed'.
    $('#sectionBankPayment').toggleClass('d-none', code !== 'transfer' && code !== 'mixed');
    $('#sectionMixedPayment').toggleClass('d-none', code !== 'mixed');
    // 2026-08-30 (T020): bank details are never required for a staff-only (is_payroll_participant=0)
    // employee regardless of which Payment Type happens to be selected underneath -- checked here
    // (not just in applyPayrollParticipantVisibility() below) so this stays correct even when the
    // user changes Payment Type WHILE already set to "No Salary".
    const isParticipant = $('#is_payroll_participant').val() !== '0';
    $('#bank_id, #bank_account_no').toggleClass('required', isParticipant && code === 'transfer').removeClass('is-invalid');
    applyAccountPickerVisibility();
}
// 2026-09-02, explicit request: "ตัวเลือกบัญชีในส่วนนี้ต้องสอดคล้องกับประเภทการจ่ายเงินที่เลือกใน Tab การจ้างงาน"
// -- the Salary tab's own #sectionCycleBankAccount (default_bank_account_id, moved there from
// Employment) is only relevant while the resolved payment method involves a bank transfer at all
// (transfer itself, or a mixed line that might use one) -- reads #payment_method_code CROSS-TAB,
// same established "read a field that lives on another tab of the same form" pattern
// applyInternPolicyVisibility() already uses for #employment_type.
function applyAccountPickerVisibility() {
    const code = $('#payment_method_code').val();
    $('#sectionCycleBankAccount').toggleClass('d-none', code !== 'transfer' && code !== 'mixed');
}
// 2026-08-30 (Phase 3, T020, explicit request: field "จ่าย/ไม่จ่ายเงินเดือน", default = จ่าย) --
// hides every payroll-specific tab/section for a staff-only employee. Employment tab's own org
// placement fields (department/position/branch/employment_date/etc.) stay visible either way.
// Social Security/Family-Tax Allowance tabs have no .required fields of their own to strip
// (already fully optional per calculateCompleteness()'s own conditional checks), so only Salary's
// 4 required fields need it.
// 2026-09-02, explicit request: "ย้ายข้อมูลการจ่ายเงิน ไปไว้ Tab เงินเดือน" -- Payment Information
// (payment_type/bank details) moved from the Employment tab into the Salary tab (see that
// section's own comment in detail.php), so the separate `#employmentPaymentSection` hide this
// function used to do is gone -- the whole Salary tab is ALREADY in PAYROLL_ONLY_TAB_BUTTON_IDS
// below and gets its nav-item hidden entirely for a staff-only employee, which now covers Payment
// too without a second, redundant toggle.
const PAYROLL_ONLY_TAB_BUTTON_IDS = ['salary-tab', 'earningDeduction-tab', 'social-tab', 'family-tab'];
function applyPayrollParticipantVisibility(isParticipant) {
    // Sets the hidden field itself (not just left to whichever caller happens to have already set
    // it) -- applyPaymentTypeRequired() below reads #is_payroll_participant directly, so this
    // function must be self-contained/correct on its own regardless of call order, not rely on a
    // caller (the radio's own change handler, populateEmployeeForm(), the post-save re-apply) having
    // synced it first.
    $('#is_payroll_participant').val(isParticipant ? '1' : '0');
    const $payrollTabItems = $(PAYROLL_ONLY_TAB_BUTTON_IDS.map(id => `#${id}`).join(',')).closest('.nav-item');
    if (isParticipant) {
        // Only reveal if the new-employee progressive reveal has already unlocked these tabs at all
        // (a not-yet-created employee has nothing on Salary/Social/Family to show either way) --
        // mirrors the exact condition saveEmployee() itself checks before clearing
        // .employee-secondary-tab's own d-none.
        if ($('#employee_no').val()) {
            $payrollTabItems.removeClass('d-none');
        }
    } else {
        $payrollTabItems.addClass('d-none');
    }
    $('#salary_type, #base_salary_amount, #salary_effective_date, #tax_calculation_method')
        .toggleClass('required', isParticipant).removeClass('is-invalid');
    applyPaymentMethodVisibility($('#payment_method_code').val());
    // Re-apply on top of the blanket required-toggle just above -- if Tax Exempt is also checked,
    // Tax Calculation Method must stay hidden/non-required regardless of participant status.
    applyTaxExemptVisibility();
}
// 2026-08-21, explicit request: resignation/termination fields (effective date, last date for
// reports, reason) only make sense once Employment Status is actually Resigned/Terminated -- same
// toggle-visibility convention as applyEmployeeTypeRequired()/applyMilitaryStatusVisibility() right
// below. None of the 3 fields are ever marked .required (see the markup comment on
// #employmentEndFields), so there's no required-toggling to do here, just show/hide.
function applyEmploymentEndFieldsVisibility() {
    const status = $('#employment_status').val();
    $('#employmentEndFields').toggleClass('d-none', status !== 'resigned' && status !== 'terminated');
}
// 2026-08-31, explicit request: "ใน Tab เงินเดือน...ตอนเลือกประเภท Type ให้เลือก Set ได้จากตรงนั้น เห็น Form
// แยกกันไปเลย" -- #employment_type itself lives on the Employment tab, not this one, so
// #internPolicySection (Salary tab) is shown/hidden purely by reading that field's live value, same
// "read a field that lives on another tab of the same form" precedent
// applyPayrollParticipantVisibility() already established for #is_payroll_participant.
function applyInternPolicyVisibility() {
    const shown = $('#employment_type').val() === 'internship';
    $('#internPolicySection').toggleClass('d-none', !shown);
    if (shown) fetchPayrollPolicySettings(function (settings) { renderPolicyInfoCard($('#internPolicyInfoCardBody'), settings.intern); });
}
// 2026-09-02, explicit request: "สถานะการจ้างงาน กับ ประเภทการจ้างงาน ข้อมูลเหมือนไม่สัมพันธ์กัน ถ้า
// ประเภทการจ้างงาน คือนักศึกษาฝึกงาน สถานะการจ้างงาน ควรเลือกอะไร" -- confirmed via AskUserQuestion:
// auto-lock Employment Status to Probation whenever Employment Type is ACTIVELY switched to
// Internship (there is no dedicated "intern" employment_status value, and the payroll engine
// already treats internship as taking precedence over probation wherever both matter -- this just
// removes the ambiguity of what the admin should pick). `isUserAction` gates the actual VALUE
// overwrite -- true only when this fires from a genuine `change` event with `e.originalEvent` set
// (a real user interaction), never from populateEmployeeForm()'s own programmatic
// `.val(...).trigger('change')` on page load -- otherwise loading an EXISTING intern record saved
// with some other status from before this lock existed (e.g. one already resigned/terminated)
// would silently flip it back to Probation the instant the page opens, before the admin touches
// anything. The DISABLE/lock-note half is safe to apply unconditionally either way (disabling
// doesn't change the stored value, just blocks editing while Internship is the current type) --
// same `.prop('disabled', ...)` on a select2-native field this app already uses elsewhere (see
// setEedReadOnly()'s own comment: no special select2 handling needed).
function applyEmploymentTypeInternLock(isUserAction) {
    const isIntern = $('#employment_type').val() === 'internship';
    const $status = $('#employment_status');
    if (isIntern && isUserAction) {
        $status.val('probation').trigger('change');
    }
    $status.prop('disabled', isIntern);
    $('#employmentStatusInternLockNote').toggleClass('d-none', !isIntern);
}
// 2026-09-02, explicit request: Probation gained the same per-employee override section
// Internship already had -- direct mirror of applyInternPolicyVisibility() immediately above,
// reads #employment_status (Employment tab) instead of #employment_type.
function applyProbationPolicyVisibility() {
    const shown = $('#employment_status').val() === 'probation';
    $('#probationPolicySection').toggleClass('d-none', !shown);
    if (shown) fetchPayrollPolicySettings(function (settings) { renderPolicyInfoCard($('#probationPolicyInfoCardBody'), settings.probation); });
}
// 2026-09-02, explicit request: "ดึงค่าจาก 4.1 มาแสดง read-only...ดีไซน์ให้สวยงาม" -- shared by both
// info cards above. Cached for the lifetime of this page load (company policy doesn't change while
// editing one employee) -- api/employee.payroll-policy-settings.
let payrollPolicySettingsCache = null;
function fetchPayrollPolicySettings(callback) {
    if (payrollPolicySettingsCache) {
        callback(payrollPolicySettingsCache);
        return;
    }
    $.getJSON(`${BASE_URL}/api/employee.payroll-policy-settings`, function (res) {
        if (res.status && res.data) {
            payrollPolicySettingsCache = res.data;
            callback(res.data);
        }
    });
}
function policyInfoBadge(label, value) {
    return `<span class="badge bg-light text-dark border me-2 mb-2 py-2 px-3"><i class="fa-solid fa-check text-success me-1"></i>${escapeHtml(label)}: <strong>${escapeHtml(value)}</strong></span>`;
}
// Renders the effective-policy badge row for ONE settings object (probation or intern, same shape
// -- base_salary_ratio/defer_pvd/defer_recurring_earning/leave_days_limit/allow_leave/
// ot_eligible_default, see PayrollPolicyModel::probationSettings()/internSettings()).
function renderPolicyInfoCard($body, settings) {
    settings = settings || {};
    const yesNo = (v) => (v ? (langData['yes'] || 'Yes') : (langData['no'] || 'No'));
    const ratioText = settings.base_salary_ratio !== null && settings.base_salary_ratio !== undefined
        ? `${settings.base_salary_ratio}%` : (langData['policy_probation_base_salary_ratio_placeholder'] || '100 (no reduction)');
    const otText = settings.ot_eligible_default === null || settings.ot_eligible_default === undefined
        ? (langData['policy_ot_default_not_set'] || 'Not Set (No Default)')
        : (settings.ot_eligible_default ? (langData['policy_ot_default_eligible'] || 'Eligible') : (langData['policy_ot_default_not_eligible'] || 'Not Eligible'));
    const leaveText = settings.allow_leave === false
        ? (langData['no'] || 'No')
        : (settings.leave_days_limit !== null && settings.leave_days_limit !== undefined ? `${settings.leave_days_limit} ${langData['days_suffix'] || 'days'}` : (langData['policy_leave_days_limit_placeholder'] || 'No limit'));
    // 2026-09-02, follow-up to close a review-flagged gap: SSO deferral + tax-exempt default badges,
    // same tri-state display convention as otText above.
    const taxText = settings.tax_exempt_default === null || settings.tax_exempt_default === undefined
        ? (langData['policy_ot_default_not_set'] || 'Not Set (No Default)')
        : (settings.tax_exempt_default ? (langData['policy_tax_default_exempt'] || 'Exempt') : (langData['policy_tax_default_not_exempt'] || 'Not Exempt'));
    $body.html(
        policyInfoBadge(langData['policy_probation_base_salary_ratio_label'] || 'Base Salary Ratio', ratioText) +
        policyInfoBadge(langData['policy_probation_defer_pvd_label'] || 'Defer PVD', yesNo(settings.defer_pvd)) +
        policyInfoBadge(langData['policy_probation_defer_sso_label'] || 'Defer SSO', yesNo(settings.defer_sso)) +
        policyInfoBadge(langData['policy_probation_defer_recurring_label'] || 'Defer Recurring Allowances', yesNo(settings.defer_recurring_earning)) +
        policyInfoBadge(langData['policy_leave_days_limit_label'] || 'Leave Days Limit', leaveText) +
        policyInfoBadge(langData['policy_ot_eligible_default_label'] || 'OT Eligible (Default)', otText) +
        policyInfoBadge(langData['policy_tax_exempt_default_label'] || 'Tax Exempt (Default)', taxText)
    );
    if (typeof updateText === 'function') updateText($body[0]);
}
// 2026-08-31, explicit request: "ตรงหัวข้อภาษี ถ้าเลือก ยกเว้นภาษีไม่ต้องให้เลือก วิธีคำนวณภาษี ซ่อนไปเลย" --
// Tax Calculation Method has nothing to mean once Tax Exempt is checked, so hide it entirely rather
// than just leave it dead/disabled. Also strips its own `.required` class while hidden (same
// reasoning as applyPayrollParticipantVisibility()'s own required-toggle on this field) so a hidden
// required field can never silently block Save -- restored on uncheck only when this employee is
// still a payroll participant, matching whatever applyPayrollParticipantVisibility() would have set.
function applyTaxExemptVisibility() {
    const exempt = $('#tax_exempt').is(':checked');
    $('.tax-calc-method-toggle').toggleClass('d-none', exempt);
    if (exempt) {
        $('#tax_calculation_method').removeClass('required').removeClass('is-invalid');
    } else if ($('#is_payroll_participant').val() !== '0') {
        $('#tax_calculation_method').addClass('required');
    }
}
function applyMilitaryStatusVisibility() {
    const isDomestic = $('input[name="employee_type_radio"]:checked').val() === 'domestic';
    const isMale = $('input[name="gender_radio"]:checked').val() === 'male';
    $('#militaryStatusGroup').toggleClass('d-none', !(isDomestic && isMale));
}
function populateSelect2Field(name, id, textTh, textEn) {
    const $sel = $(`#employeeTabsContent [name="${name}"]`);
    if (!$sel.length || !id) return;
    const label = (currentLang === 'th' ? (textTh || textEn) : (textEn || textTh)) || String(id);
    const opt = new Option(label, id, true, true);
    $sel.empty().append(opt).trigger('change');
}
// 2026-09-02, explicit request: mixed payment lines -- same "repeatable card, plain classes (not
// name attributes, so collectEmployeeFormData()'s generic [name] loop never sees them individually
// and can't collide across rows)" shape as dependentCardHtml()/collectDependentCardData() above,
// just simpler since nothing here has its own save/delete endpoint -- the whole line set is only
// ever persisted as one atomic batch inside the main employee save (EmployeePaymentMethodModel::
// saveLines(), delete+reinsert), so a row removed here just isn't sent next Save, no confirm needed.
function paymentMethodLineRowHtml(line) {
    line = line || {};
    return `
        <div class="payment-method-line card-surface p-3 mb-2">
            <div class="row g-2 align-items-end">
                <div class="col-sm-3">
                    <label class="form-label mb-1 small text-muted"><span data-i18n="payment_method">Method</span> <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm select2-remote payment-line-method required" data-api="/api/payment-method.options" data-exclude-code="mixed"></select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label mb-1 small text-muted" data-i18n="amount_type">Amount Type</label>
                    <select class="form-select form-select-sm payment-line-amount-type">
                        <option value="fixed" data-i18n="fixed_amount">Fixed Amount</option>
                        <option value="percent" data-i18n="percent_of_net">% of Net Pay</option>
                    </select>
                </div>
                <div class="col-sm-2">
                    <label class="form-label mb-1 small text-muted"><span data-i18n="amount">Amount</span> <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" class="form-control form-control-sm payment-line-amount required" value="${line.amount_value !== undefined && line.amount_value !== null ? line.amount_value : ''}">
                </div>
                <div class="col-sm-4 payment-line-bank-account-wrap d-none">
                    <label class="form-label mb-1 small text-muted"><span data-i18n="bank_account">Bank Account</span></label>
                    <select class="form-select form-select-sm select2-remote payment-line-bank-account" data-api="/api/employee.payment-account-options"></select>
                </div>
                <div class="col-sm-1">
                    <button type="button" class="btn btn-sm btn-link text-danger btn-remove-payment-line" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </div>
        </div>`;
}
function initPaymentMethodLineWidgets($row, line) {
    line = line || {};
    if (typeof initSelect2 === 'function') {
        initSelect2($row.find('.payment-line-method'), { mode: 'ajax' });
        initSelect2($row.find('.payment-line-bank-account'), { mode: 'ajax' });
    }
    $row.find('.payment-line-bank-account').attr('data-cycle-id', $('#cycle_id').val() || '');
    if (line.payment_method_id) {
        const label = (currentLang === 'th' ? (line.payment_method_name_th || line.payment_method_name_en) : (line.payment_method_name_en || line.payment_method_name_th)) || String(line.payment_method_id);
        const opt = new Option(label, line.payment_method_id, true, true);
        $row.find('.payment-line-method').empty().append(opt).trigger('change');
    }
    $row.find('.payment-line-amount-type').val(line.amount_type || 'fixed');
    const isTransfer = line.payment_method_code === 'transfer';
    $row.find('.payment-line-bank-account-wrap').toggleClass('d-none', !isTransfer);
    if (line.bank_account_id) {
        const baLabel = line.bank_account_name || String(line.bank_account_id);
        const baOpt = new Option(baLabel, line.bank_account_id, true, true);
        $row.find('.payment-line-bank-account').empty().append(baOpt).trigger('change');
    }
}
function addPaymentMethodLineRow(line) {
    const $wrap = $('#paymentMethodLinesWrap');
    $wrap.append(paymentMethodLineRowHtml(line));
    initPaymentMethodLineWidgets($wrap.find('.payment-method-line').last(), line || {});
}
function collectPaymentMethodLines() {
    const lines = [];
    $('#paymentMethodLinesWrap .payment-method-line').each(function () {
        const $row = $(this);
        lines.push({
            payment_method_id: $row.find('.payment-line-method').val(),
            amount_type: $row.find('.payment-line-amount-type').val(),
            amount_value: $row.find('.payment-line-amount').val(),
            bank_account_id: $row.find('.payment-line-bank-account').val() || null,
        });
    });
    return lines;
}
function collectEmployeeFormData() {
    const data = {};
    $('#employeeTabsContent [name]').each(function () {
        const $el = $(this);
        const name = $el.attr('name');
        if (!name || name.indexOf('_radio') !== -1) return;
        if ($el.attr('type') === 'file') return;
        if ($el.is(':radio')) return;
        if ($el.is(':checkbox')) {
            data[name] = $el.is(':checked');
            return;
        }
        if ($el.hasClass('datepicker')) {
            data[name] = toIsoDate($el.val());
            return;
        }
        data[name] = $el.val();
    });
    // 2026-08-31: send the mask marker back verbatim while masked (the readonly input itself is
    // blank, see applyEmployeeSalaryMaskUi()) -- EmployeeModel::save() specifically detects this
    // literal string and preserves the existing encrypted value instead of overwriting it, so
    // saving an unrelated tab while masked can never zero out the real salary.
    if (employeeSalaryMasked) {
        data.base_salary_amount = 'XXXX';
    }
    data.payment_method_lines = collectPaymentMethodLines();
    return data;
}
// 2026-08-31, explicit request: "สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX แต่ยังสามารถคำนวณเงินเดือน...
// ได้ตามสิทธิ์" -- EmployeeController::get() replaces base_salary_amount with the literal string
// "XXXX" (PermissionModel::MASK_VALUE) when the viewer's grant doesn't cover this employee. A
// plain generic-loop `.val('XXXX')` onto #base_salary_amount would silently fail (it's a real
// type="number" input -- the browser rejects non-numeric text and just leaves it BLANK, not
// showing "XXXX" at all, and collectEmployeeFormData() would then read back an EMPTY string on
// save, NOT the mask marker EmployeeModel::save()'s own preservation guard looks for -- corrupting
// the real salary the moment ANY tab gets saved). Tracked via a module-level flag + the input's
// own readonly state (readonly, not disabled, so it still submits/serializes via jQuery .val()).
let employeeSalaryMasked = false;
// 2026-09-02, real gotcha caught before shipping applyEmploymentTypeInternLock() below: Select2
// (even in 'native' mode, see input.js's own isNative branch) fires its OWN selection change
// through a synthetic jQuery `.trigger('change')` internally too -- so `e.originalEvent` does NOT
// reliably distinguish a genuine user pick from populateEmployeeForm()'s own programmatic
// `.val(...).trigger('change')` on page load (both end up looking the same to a change handler).
// This flag is the actual distinguishing signal instead: true for the ENTIRE synchronous duration
// of populateEmployeeForm() (set at its very start, cleared at its very end -- that function has
// only one async tail, the mixed-payment-lines fetch, which never touches #employment_type), so any
// #employment_type `change` handler that must NOT fire its "real user action" branch during a page
// load can just check `!isLoadingEmployeeForm`.
let isLoadingEmployeeForm = false;
function applyEmployeeSalaryMaskUi(masked) {
    employeeSalaryMasked = masked;
    const $input = $('#base_salary_amount');
    $input.prop('readonly', masked);
    if (masked) {
        $input.val('').attr('placeholder', langData['salary_amount_masked_placeholder'] || 'No permission to view');
    } else {
        $input.removeAttr('placeholder');
    }
}
// 2026-09-02, explicit request: "ถ้ามีส่งมาให้ให้ Admin Match เอง ต้องมีอะไรบอก และแสดงข้อมูลที่ Sync มาเพื่อให้
// Admin รู้" -- address_line_1_{scope}/address_line_2_{scope} can be populated (from Origami sync
// or manual entry) while master_address_id_{scope} stays empty, since this app deliberately never
// auto-matches free-text province/district/sub-district names to `master_addresses` (real risk of a
// wrong silent match, see this project's own address-sync notes). Condition is source-agnostic --
// "there's text but no verified match" -- not "this specifically came from sync", so it also covers
// a manually-typed address that was never searched/picked. See detail.php's own comment on the 2
// alert blocks this toggles.
const ADDRESS_MATCH_SCOPES = ['register', 'contact'];
function updateAddressMatchIndicator(scope) {
    const text = ($(`#address_line_1_${scope}`).val() || '').trim();
    const matched = ($(`#master_address_id_${scope}`).val() || '').trim() !== '';
    const $alert = $(`#addressUnmatchedAlert${scope.charAt(0).toUpperCase()}${scope.slice(1)}`);
    if (text !== '' && !matched) {
        $(`#addressUnmatchedText${scope.charAt(0).toUpperCase()}${scope.slice(1)}`).text(text);
        $alert.removeClass('d-none');
    } else {
        $alert.addClass('d-none');
    }
}
function updateAllAddressMatchIndicators() {
    ADDRESS_MATCH_SCOPES.forEach(updateAddressMatchIndicator);
}
$(document).on('input', '#address_line_1_register', function () { updateAddressMatchIndicator('register'); });
$(document).on('input', '#address_line_1_contact', function () { updateAddressMatchIndicator('contact'); });
// Fires AFTER input.js's own delegated `.select-address-item` click handler (public/js/input.js,
// loaded earlier in header.php -- jQuery runs delegated handlers for the same event in bind order),
// which is what actually writes the real match into `.master-address-id-field` -- by the time this
// one runs, the field this reads is already up to date.
$(document).on('click', '.select-address-item', function () { updateAllAddressMatchIndicators(); });

function populateEmployeeForm(data) {
    isLoadingEmployeeForm = true;
    const remoteFields = ['department_id', 'team_id', 'role_id', 'position_id', 'branch_id', 'bank_id', 'default_bank_account_id', 'payment_method_id', 'report_to_id', 'nationality', 'religion', 'cycle_id', 'work_location_id', 'shift_id', 'employment_type_id'];
    applyEmployeeSalaryMaskUi(data.base_salary_amount === 'XXXX');
    Object.keys(data).forEach(function (key) {
        if (remoteFields.indexOf(key) !== -1) return;
        // Masked case handled explicitly by applyEmployeeSalaryMaskUi() above instead -- the
        // generic .val() write below would silently fail on this field's real type="number" input
        // when the value is the non-numeric "XXXX" mask marker (browser rejects it, field goes
        // blank with no placeholder). When NOT masked, fall through to the normal generic write
        // below exactly as before -- only the masked case needs different handling.
        if (key === 'base_salary_amount' && employeeSalaryMasked) return;
        const $el = $(`#employeeTabsContent [name="${key}"]`);
        if (!$el.length) return;
        if ($el.is(':checkbox')) {
            $el.prop('checked', data[key] == 1 || data[key] === true).trigger('change');
            return;
        }
        if ($el.hasClass('datepicker')) {
            // Setting .val() alone leaves bootstrap-datepicker's own internal `dates` array
            // (populated once, at initDatepicker() time on an EMPTY field, before this employee's
            // data has even loaded) out of sync with what's now visibly in the input. Clicking the
            // field afterward without picking a new date, then clicking away, calls the widget's
            // own hide()->setValue(), which writes `dates` (still empty) back into the input --
            // silently blanking a field the user never touched. .datepicker('update') re-parses
            // the input's current text into `dates` so that round-trip is a no-op instead.
            $el.val(toDisplayDate(data[key]));
            $el.datepicker('update');
            return;
        }
        $el.val(data[key] !== null && data[key] !== undefined ? data[key] : '').trigger('change');
    });
    if (data.employee_type) {
        $(`input[name="employee_type_radio"][value="${data.employee_type}"]`).prop('checked', true).trigger('change');
    }
    if (data.gender) {
        $(`input[name="gender_radio"][value="${data.gender}"]`).prop('checked', true).trigger('change');
    }
    // 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) -- replaces the
    // old payment_type_radio sync. #payment_method_code is set directly from the server's own
    // resolved code (EmployeeModel::get()'s new payment_method_code join) rather than waiting on
    // the select2 population below to fire its own select2:select event, so
    // applyPaymentMethodVisibility()/applyAccountPickerVisibility() are correct immediately even
    // before that remote option has actually loaded.
    applyPaymentMethodVisibility(data.payment_method_code || '');
    if (data.payment_method_id) {
        const pmTextTh = data.payment_method_name_th || '';
        const pmTextEn = data.payment_method_name_en || '';
        populateSelect2Field('payment_method_id', data.payment_method_id, pmTextTh, pmTextEn);
    }
    // 2026-08-31 -- the generic loop above already wrote the raw values into every *_override field
    // (ordinary named inputs/selects, not excluded via remoteFields/checkbox/datepicker), this just
    // syncs the UI-only override toggle + field visibility to match whatever actually loaded.
    // 2026-09-02, follow-up to close a real bug found while expanding this to 7 fields: checking
    // ONLY the ratio field to decide the toggle's state would falsely report "no override" (and
    // then WIPE every other already-loaded override field via the toggle's own clear-on-uncheck
    // handler) for an employee whose override lives in a DIFFERENT field (e.g. defer_pvd_override
    // set but ratio_override left blank) -- now checks ALL 7 fields.
    const hasAnyOverride = (data, ids) => ids.some(function (id) {
        const v = data[id];
        return v !== null && v !== undefined && v !== '';
    });
    const hasInternRatioOverride = hasAnyOverride(data, ['intern_base_salary_ratio_override', 'intern_defer_pvd_override',
        'intern_defer_sso_override', 'intern_defer_recurring_earning_override', 'intern_leave_days_limit_override',
        'intern_allow_leave_override', 'intern_period_days_override']);
    $('#internRatioOverrideToggle').prop('checked', hasInternRatioOverride).trigger('change');
    // 2026-09-02, explicit request: Probation gained the same per-employee ratio override
    // Internship already had -- direct mirror of the toggle sync immediately above.
    const hasProbationRatioOverride = hasAnyOverride(data, ['probation_base_salary_ratio_override', 'probation_defer_pvd_override',
        'probation_defer_sso_override', 'probation_defer_recurring_earning_override', 'probation_leave_days_limit_override',
        'probation_allow_leave_override', 'probation_period_days_override']);
    $('#probationRatioOverrideToggle').prop('checked', hasProbationRatioOverride).trigger('change');
    // 2026-08-30 (T020) -- data.is_payroll_participant is a DB tinyint (0/1, possibly returned as a
    // numeric string), so compare loosely; defaults to paid (matches the DB column's own DEFAULT 1)
    // when the key is genuinely absent from a get() response that predates this field somehow.
    $(`input[name="is_payroll_participant_radio"][value="${(data.is_payroll_participant == 0) ? '0' : '1'}"]`).prop('checked', true).trigger('change');
    // Generic loop above already wrote the correct raw values into mobile_no (national digits)
    // and the mobile_country_code hidden input directly, so submission is correct even without
    // this -- this block only re-selects the flag in the intl-tel-input widget to match the
    // loaded country. setNumber() re-formats the input with spaces per its NATIONAL display
    // format as a side effect, so strip back to plain digits immediately after (see
    // initMobileIti()'s comment on why formatting must not survive into the field's value).
    if (window.mobileIti && data.mobile_no) {
        window.mobileIti.setNumber((data.mobile_country_code || '+66') + data.mobile_no);
        const $mobileNo = $('#mobile_no');
        $mobileNo.val(($mobileNo.val() || '').replace(/\D/g, ''));
        syncMobileCountryCode();
    }
    populateSelect2Field('nationality', data.nationality, data.nationality_name_th, data.nationality_name_en);
    populateSelect2Field('religion', data.religion, data.religion_name_th, data.religion_name_en);
    populateSelect2Field('department_id', data.department_id, data.department_name_th, data.department_name_en);
    // 2026-08-24, explicit request: added Team -- MUST stay in remoteFields above + get an explicit
    // populateSelect2Field() call here, or it falls into the exact silent-nulling bug documented
    // below for work_location_id/shift_id (a select2-remote with no <option> preloaded yet just
    // ignores a plain .val(id), so the field reads as empty and overwrites the real value on save).
    populateSelect2Field('team_id', data.team_id, data.team_name_th, data.team_name_en);
    // 2026-09-02, Origami candidates.php field batch: same silent-data-loss precedent as Team above.
    populateSelect2Field('employment_type_id', data.employment_type_id, data.employment_type_name_th, data.employment_type_name_en);
    populateSelect2Field('role_id', data.role_id, data.role_name_th, data.role_name_en);
    populateSelect2Field('position_id', data.position_id, data.position_name_th, data.position_name_en);
    populateSelect2Field('branch_id', data.branch_id, data.branch_name_th, data.branch_name_en);
    if (data.bank_id) {
        const prefix = data.bank_code ? `${data.bank_code} - ` : '';
        populateSelect2Field('bank_id', data.bank_id, prefix + (data.bank_name_th || ''), prefix + (data.bank_name_en || ''));
    }
    // 2026-09-02, explicit request: cycle-scoped account picker (moved here from Employment tab) --
    // data-cycle-id set BEFORE population (not via the #cycle_id change handler, which deliberately
    // does NOT fire on this programmatic populateSelect2Field('cycle_id', ...) call below -- see
    // that handler's own comment on why: it would otherwise wipe the value being set right here).
    $('#default_bank_account_id').attr('data-cycle-id', data.cycle_id || '');
    if (data.default_bank_account_id) {
        const dbaTextTh = (data.default_bank_account_bank_name_th || '') + ' - ' + (data.default_bank_account_name || '') + (data.default_bank_account_company_code ? ` (${data.default_bank_account_company_code})` : '');
        const dbaTextEn = (data.default_bank_account_bank_name_en || '') + ' - ' + (data.default_bank_account_name || '') + (data.default_bank_account_company_code ? ` (${data.default_bank_account_company_code})` : '');
        populateSelect2Field('default_bank_account_id', data.default_bank_account_id, dbaTextTh, dbaTextEn);
    }
    populateSelect2Field('report_to_id', data.report_to_id, data.report_to_name_th, data.report_to_name_en);
    populateSelect2Field('cycle_id', data.cycle_id, data.cycle_name, data.cycle_name);
    // 2026-09-02, explicit request: mixed payment lines -- only fetched for an existing employee
    // whose payment method actually resolves to 'mixed' (a brand-new/unsaved employee has nothing
    // to fetch yet either way).
    $('#paymentMethodLinesWrap').empty();
    if (data.payment_method_code === 'mixed' && data.id) {
        $.getJSON(`${BASE_URL}/api/employee.payment-method-lines`, { employee_id: data.id }, function (res) {
            if (res.status && Array.isArray(res.data)) {
                res.data.forEach(addPaymentMethodLineRow);
            }
        });
    }
    // Real bug fix (2026-08-19, reported as "Employment tab data disappears after save"): these two
    // were never in remoteFields nor explicitly populated, so on every reload they fell through the
    // generic loop's plain .val(id) -- which does nothing on a select2-remote with no matching
    // <option> loaded yet (ajax mode only loads options on search). The select LOOKED empty, and the
    // next save then submitted that empty value, silently overwriting the real saved value with NULL.
    populateSelect2Field('work_location_id', data.work_location_id, data.location_name_th, data.location_name_en);
    populateSelect2Field('shift_id', data.shift_id, data.shift_name_th, data.shift_name_en);
    $('#search_address_register').val((currentLang === 'th' ? data.address_display_th_register : data.address_display_en_register) || '');
    $('#search_address_contact').val((currentLang === 'th' ? data.address_display_th_contact : data.address_display_en_contact) || '');
    updateAllAddressMatchIndicators();
    isLoadingEmployeeForm = false;
}
// Scoped to the tab-pane the Save button lives in (2026-08-19, explicit request: each tab must be
// saveable independently) -- used to validate ALL of #employeeTabsContent, so saving e.g. the Info
// tab was blocked by empty required fields on Employment/Salary that the user hadn't gotten to yet.
// employee_no is checked regardless of scope -- it's the one field EmployeeModel::save() still
// hard-requires on every save (see its own comment), and it happens to live on the Employment tab,
// so a save from any other tab still needs to know it's missing rather than fail silently server-side.
function validateEmployeeForm($scope) {
    const $target = ($scope && $scope.length) ? $scope : $('#employeeTabsContent');
    let firstInvalid = null;
    $target.find('.required').each(function () {
        const $el = $(this);
        let value = $el.is(':checkbox') ? ($el.is(':checked') ? '1' : '') : $el.val();
        value = value ? String(value).trim() : '';
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    const $idCard = $target.find('#id_card_no');
    if ($idCard.length && $idCard.hasClass('required')) {
        const idCardVal = $idCard.val();
        if (idCardVal && !isValidThaiID(idCardVal)) {
            $idCard.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $idCard;
        }
    }
    const $empNo = $('#employee_no_input');
    if (!String($empNo.val() || '').trim()) {
        $empNo.addClass('is-invalid');
        if (!firstInvalid) firstInvalid = $empNo;
    } else {
        $empNo.removeClass('is-invalid');
    }
    return firstInvalid;
}
function jumpToField($el) {
    const $pane = $el.closest('.tab-pane');
    if ($pane.length) {
        const btnId = TAB_BUTTON_BY_PANE[$pane.attr('id')];
        const btnEl = btnId ? document.getElementById(btnId) : null;
        if (btnEl && typeof bootstrap !== 'undefined') {
            bootstrap.Tab.getOrCreateInstance(btnEl).show();
        }
    }
    setTimeout(function () { $el.trigger('focus'); }, 200);
}
// Shared by saveEmployee() (every other tab's plain per-tab Save) and saveFamilyTab() (the Family
// tab's consolidated Save, 2026-08-20) -- applies a successful /api/employee.save response to page
// state (currentEmployeeId, breadcrumb, profile header, first-save tab unlock). Extracted so both
// call sites stay in sync instead of duplicating this bookkeeping.
function applyEmployeeSaveSuccess(res, wasNew) {
    // 2026-08-28, explicit request: Employee List should reload itself once a save happens over
    // here in Detail (opened in a separate browser tab, see list.js's own watchTabDirty() call) --
    // see markTabDirty()/watchTabDirty() in app.js for the shared cross-tab mechanism this reuses.
    markTabDirty('employee_list_dirty');
    if (res.id) {
        currentEmployeeId = res.id;
        $('#report_to_id').attr('data-exclude-id', currentEmployeeId);
        if (!childTablesLoaded) {
            childTablesLoaded = true;
            loadAllChildTables();
            loadDocumentList();
        }
    }
    if (res.employee_no) {
        $('#employee_no').val(res.employee_no);
        const newPath = `${BASE_URL}/employees/${res.employee_no}`.replace(/^https?:\/\/[^/]+/, '');
        if (window.location.pathname !== newPath) {
            history.replaceState(null, '', newPath);
        }
        $('#bcCurrent').text(res.employee_no);
        refreshProfileHeader();
    }
    // New-employee flow (2026-08-19, explicit request): the FIRST save that actually creates the
    // record unlocks the rest of the tabs + the 3rd breadcrumb level -- every save (this one
    // included) stays on whichever tab's Save button was clicked, it never auto-navigates anywhere.
    if (wasNew && res.id) {
        $('.employee-secondary-tab').removeClass('d-none');
        $('#bcSeparatorCurrent, #bcCurrent').removeClass('d-none');
        // 2026-08-30 (T020) -- the blanket reveal just above would incorrectly re-show the payroll-
        // specific tabs (Salary/Income & Deductions/Social Security/Family-Tax Allowance) even when
        // this brand-new employee was created as "No Salary" -- re-apply right after so they stay
        // hidden in that case.
        applyPayrollParticipantVisibility($('#is_payroll_participant').val() !== '0');
    }
    refreshEmployeeFormBaseline();
}
function saveEmployee($btn) {
    const invalidEl = validateEmployeeForm($btn.closest('.tab-pane'));
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        jumpToField(invalidEl);
        return;
    }
    // 2026-09-02, explicit request: mixed payment -- client-side mirror of
    // EmployeePaymentMethodModel::validateMixedLines()'s own percent-only-sums-to-100 rule (a set
    // containing any fixed-amount line defers its sum check to payroll-run time, same as server
    // side, since net pay isn't known here either). Every OTHER save button on this page also runs
    // through this same function (collectEmployeeFormData() always resends the whole form
    // regardless of which tab's button was clicked), so this check applies no matter which tab was
    // actually being edited when Save was pressed -- consistent with is_payroll_participant/
    // employment_type's own cross-tab-visible fields.
    if (!$('#sectionMixedPayment').hasClass('d-none')) {
        const lines = collectPaymentMethodLines();
        if (lines.length === 0) {
            showWarning(langData['mixed_payment_lines_required'] || 'At least one payment line is required for a mixed payment method.');
            return;
        }
        const hasFixed = lines.some(function (l) { return l.amount_type === 'fixed'; });
        if (!hasFixed) {
            const sum = lines.reduce(function (s, l) { return s + (parseFloat(l.amount_value) || 0); }, 0);
            if (Math.abs(sum - 100) > 0.01) {
                showWarning((langData['mixed_payment_percent_sum_error'] || 'Percent lines must sum to exactly 100 (currently {sum}).').replace('{sum}', String(sum)));
                return;
            }
        }
    }
    const payload = collectEmployeeFormData();
    const wasNew = !currentEmployeeId;
    if (currentEmployeeId) {
        payload.id = currentEmployeeId;
    }
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    $.ajax({
        url: `${BASE_URL}/api/employee.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                applyEmployeeSaveSuccess(res, wasNew);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
}
// One Save button for the whole Family/Tax Allowance tab (2026-08-20, explicit request: "ตัดให้
// เหลือปุ่ม Save แค่ปุ่มเดียว" -- was 3 separate buttons: Save Father, Save Mother, and this tab's
// own bottom Save). Validates with the exact same validateEmployeeForm() every other tab's button
// uses -- works for free because father/mother's Name input and each dependent card's Name/
// Relationship inputs carry .required themselves (toggled on the parent slots, always-on for
// dependent cards), so nothing here needs its own bespoke validation pass. On success, fires the
// main employee record, every "yes"-toggled parent slot, and every currently-rendered dependent
// card together (Promise.all -- jQuery's jqXHR is Promise-compatible), and shows exactly one
// combined success/error message instead of one per sub-save.
function saveFamilyTab($btn) {
    const invalidEl = validateEmployeeForm($btn.closest('.tab-pane'));
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        jumpToField(invalidEl);
        return;
    }
    if (!currentEmployeeId) {
        showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
        return;
    }
    const payload = collectEmployeeFormData();
    payload.id = currentEmployeeId;
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);

    const promises = [$.ajax({
        url: `${BASE_URL}/api/employee.save`, method: 'POST',
        contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload)
    })];
    Object.keys(PARENT_SLOTS).forEach(function (relationship) {
        const cfg = PARENT_SLOTS[relationship];
        if ($(`${cfg.toggleId} button.active`).data('value') !== 'yes') return;
        const parentPayload = {
            employee_id: currentEmployeeId,
            name: $(cfg.nameInput).val().trim(),
            id_card_no: $(cfg.idCardInput).val().trim(),
            relationship: relationship,
        };
        const existingId = $(cfg.idInput).val();
        if (existingId) parentPayload.id = existingId;
        promises.push($.ajax({
            url: `${BASE_URL}/api/employee.parent.save`, method: 'POST',
            contentType: 'application/json', dataType: 'json', data: JSON.stringify(parentPayload)
        }));
    });
    $('#dependentCardsContainer .dependent-card').each(function () {
        promises.push($.ajax({
            url: `${BASE_URL}/api/employee.dependent.save`, method: 'POST',
            contentType: 'application/json', dataType: 'json', data: JSON.stringify(collectDependentCardData($(this)))
        }));
    });

    Promise.all(promises).then(function (results) {
        $btn.prop('disabled', false).html(originalHtml);
        if (typeof updateText === 'function') updateText($btn[0]);
        const failed = results.find(function (r) { return !r.status; });
        if (failed) {
            showWarning(failed.message || langData['save_failed'] || 'Failed to save data.');
            return;
        }
        showSuccess(langData['save_success'] || 'Saved successfully.');
        applyEmployeeSaveSuccess(results[0], false);
        loadAllChildTables();
    }).catch(function () {
        $btn.prop('disabled', false).html(originalHtml);
        if (typeof updateText === 'function') updateText($btn[0]);
        showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
    });
}
// Profile completeness (2026-08-19, explicit request): color follows the same red/orange(brand)/
// green scale used elsewhere in this app for validation-style status (payroll run banners etc.) --
// red under 50% (needs real attention), brand orange in the middle, green once mostly filled in.
function completenessColor(percent) {
    if (percent >= 80) return '#198754';
    if (percent >= 50) return '#FF9900';
    return '#dc3545';
}
// Renders the profile header card + per-tab badges from whatever api/employee.get last returned --
// called on initial load AND (via refreshProfileHeader()) after every tab's Save, WITHOUT touching
// the rest of the form (populateEmployeeForm() is not re-run on save -- would re-render every
// select2/datepicker mid-edit for a purely cosmetic refresh, not worth the disruption).
function renderProfileHeader(data) {
    if (!data || !data.completeness) {
        $('#employeeProfileHeader').addClass('d-none');
        return;
    }
    const name = (currentLang === 'th' ? `${data.name_th || ''} ${data.surname_th || ''}` : `${data.name_en || ''} ${data.surname_en || ''}`).trim() || data.employee_no || '-';
    // 2026-08-30, real gap found and fixed (explicit report: "รูปพนักงานที่ Sync มาแล้วต้องนำไปแสดงบน
    // header ด้วยครับ") -- this header card's own avatar always rendered just the initial letter,
    // even though `data.profile_photo_path` (plain e.* passthrough from api/employee.get) has been
    // available here the whole time; only the separate upload-widget preview inside the Personal tab
    // (#profilePreview, fixed 2026-08-29) ever actually showed the real photo.
    if (data.profile_photo_path) {
        $('#profileHeaderAvatar').html(`<img src="${BASE_URL}/${data.profile_photo_path}" alt="">`).addClass('has-photo');
    } else {
        $('#profileHeaderAvatar').text((name.charAt(0) || '?').toUpperCase()).removeClass('has-photo');
    }
    $('#profileHeaderName').text(name);
    const positionLabel = (currentLang === 'th' ? data.position_name_th : data.position_name_en) || data.position_name_th || data.position_name_en;
    $('#profileHeaderMeta').text([data.employee_no, positionLabel].filter(Boolean).join(' · ') || '-');
    const statusColorMap = { active: 'bg-success', probation: 'bg-warning text-dark', suspended: 'bg-secondary', resigned: 'bg-danger', terminated: 'bg-dark' };
    const statusCls = statusColorMap[data.employee_status] || 'bg-secondary';
    const statusLabel = langData['status_' + data.employee_status] || data.employee_status || '-';
    $('#profileHeaderStatusBadge').html(`<span class="badge ${statusCls}">${escapeHtml(statusLabel)}</span>`);

    const percent = data.completeness.percent || 0;
    const color = completenessColor(percent);
    $('#profileCompletenessBar').css({ width: percent + '%', 'background-color': color });
    $('#profileCompletenessPercent').text(percent + '%').css('color', color);

    // Verify Status (2026-08-19, explicit request): distinct from the completeness % above -- this
    // reflects employees.is_payroll_ready (EmployeeModel::verifyStatus()), which only checks fields
    // actually needed to run payroll (narrower than completeness, e.g. ignores LINE ID). Missing-tab
    // list is deliberately tab-level, not per-field, to stay a short/glanceable tooltip.
    const verify = data.verify_status || { ready: false, missing_tabs: [] };
    const tabLangKey = { info: 'employee_info', contact: 'contact', employment: 'employment', salary: 'salary' };
    const $verifyBadge = $('#profileVerifyStatusBadge');
    if (verify.ready) {
        $verifyBadge.attr('class', 'badge bg-success')
            .html('<i class="fa-solid fa-circle-check me-1"></i>' + escapeHtml(langData['verify_status_ready'] || 'Ready for Payroll'))
            .attr('title', '');
    } else {
        const missingLabels = (verify.missing_tabs || []).map(function (key) {
            return langData[tabLangKey[key] || key] || key;
        }).join(', ');
        $verifyBadge.attr('class', 'badge bg-danger')
            .html('<i class="fa-solid fa-circle-exclamation me-1"></i>' + escapeHtml(langData['verify_status_not_ready'] || 'Not Ready for Payroll'))
            .attr('title', ((langData['verify_status_missing_prefix'] || 'Missing info on:') + ' ' + missingLabels).trim());
    }

    Object.keys(data.completeness.tabs || {}).forEach(function (key) {
        const tabPercent = data.completeness.tabs[key].percent;
        $(`.completeness-tab-badge[data-tab-key="${key}"]`)
            .removeClass('d-none')
            .text(tabPercent + '%')
            .css('background-color', completenessColor(tabPercent));
    });

    updateOrigamiSyncSummary(data);

    $('#employeeProfileHeader').removeClass('d-none');
}
// 2026-08-28, explicit request: "เพิ่มปุ่ม Re Sync รายบุคคลของพนักงาน และมีประวัติการ Sync โชว์ในหน้า
// พนักงานด้วย" -- called from renderProfileHeader() so it stays in sync everywhere that already
// runs (initial load AND after every tab's Save).
//
// Same-day follow-up ("ถ้าบางคนเป็นการ Manual สร้างจะไม่มีปุ่ม Sync เกิดขึ้น...หรือสามารถส่ง emp code
// ไปเช็คในฝั่ง origami ได้ไหม จะได้มีปุ่มทุกคน") -- previously ALSO required data.origami_ref_id to be
// truthy, so a manually-created employee never got a Sync button at all. Now shown for EVERY
// employee once this company is Origami-HR-linked (IS_ORIGAMI_HR_LINKED, set once in
// layout/header.php, same gating convention already used for the Sync buttons on Employee List/
// Organizational Structure) -- an employee with no origami_ref_id yet gets a "Sync from Origami"
// button that matches by employee_no instead (see EmployeeSyncModel::resyncOne()'s own 2026-08-28
// fallback), which links them (writes origami_ref_id) on first success.
function updateOrigamiSyncSummary(data) {
    const $section = $('#employeeOrigamiSyncSummary');
    if (typeof IS_ORIGAMI_HR_LINKED === 'undefined' || !IS_ORIGAMI_HR_LINKED) {
        $section.addClass('d-none');
        return;
    }
    $section.removeClass('d-none');
    const $label = $('#btnResyncOneEmployeeLabel');
    if (!data.origami_ref_id) {
        $label.text(langData['employee_sync_link_one_button'] || 'Sync from Origami');
        $('#profileLastSyncedText').text(langData['employee_sync_not_linked_hint'] || 'Not linked to Origami HR yet -- click Sync to match by employee number');
        return;
    }
    $label.text(langData['employee_sync_resync_one_button'] || 'Re-Sync from Origami');
    $('#profileLastSyncedText').text(langData['loading'] || 'Loading...');
    $.getJSON(`${BASE_URL}/api/employee-sync.last-sync-summary`, { employee_id: data.id }, function (res) {
        const summary = (res && res.status) ? res.data : null;
        if (summary && summary.started_at) {
            const dateStr = typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(summary.started_at) : summary.started_at;
            const statusCls = summary.status === 'completed' ? 'text-success' : (summary.status === 'failed' ? 'text-danger' : 'text-warning');
            $('#profileLastSyncedText').html(`<span class="${statusCls}">${escapeHtml(dateStr)}</span>`);
        } else {
            $('#profileLastSyncedText').text(langData['employee_sync_never_synced'] || 'Never synced from Origami');
        }
    }).fail(function () {
        $('#profileLastSyncedText').text(langData['employee_sync_never_synced'] || 'Never synced from Origami');
    });
}
// 2026-08-29, explicit request: "การกด Sync ข้อมูลพนักงานใหม่ให้ขึ้น Confirm ก่อนทั้งในหน้า List และ
// Detail" -- confirm before running, same showConfirm() pattern as list.js's own
// .sync-one-employee handler.
$(document).on('click', '#btnResyncOneEmployee', function () {
    if (!currentEmployeeId) return;
    const $btn = $(this);
    const title = langData['confirm_sync_title'] || 'Confirm Sync';
    const message = langData['confirm_sync_one_message'] || 'Re-sync this employee from Origami?';
    showConfirm(title, message, function () {
        $btn.prop('disabled', true);
        $.ajax({
            url: `${BASE_URL}/api/employee-sync.resync-one`, method: 'POST',
            data: { employee_id: currentEmployeeId }, dataType: 'json',
            success: function (res) {
                $btn.prop('disabled', false);
                if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                // Re-syncing may have changed HR-owned fields (name/DOB/gender/email/mobile/
                // employment date+status) -- full reload, same as opening this employee fresh, so
                // the form isn't left showing stale values next to a "just synced" success toast.
                loadEmployeeIfEditing();
            },
            error: function () {
                $btn.prop('disabled', false);
                showWarning(langData['save_failed'] || 'An error occurred while saving.');
            }
        });
    });
});
function refreshProfileHeader() {
    const employeeNo = $('#employee_no').val();
    if (!employeeNo) return;
    $.getJSON(`${BASE_URL}/api/employee.get`, { employee_no: employeeNo }, function (res) {
        if (res.status && res.data) renderProfileHeader(res.data);
    });
}
function loadEmployeeIfEditing() {
    const employeeNo = $('#employee_no').val();
    if (!employeeNo) {
        // 2026-08-30, real bug found and fixed (explicit report: "เข้าใช้งานใน tab ที่เป็น data
        // table refresh แล้ว data table ไม่ทำงาน") -- a brand-new employee (this branch) has
        // nothing async to wait for, so the URL-hash tab restore is safe to run immediately.
        activateEmployeeTabFromHash();
        refreshEmployeeFormBaseline();
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/employee.get`,
        method: 'GET',
        data: { employee_no: employeeNo },
        dataType: 'json',
        success: function (res) {
            if (res.status && res.data) {
                currentEmployeeId = parseInt(res.data.id, 10);
                $('#report_to_id').attr('data-exclude-id', currentEmployeeId);
                populateEmployeeForm(res.data);
                renderProfileHeader(res.data);
                if (!childTablesLoaded) {
                    childTablesLoaded = true;
                    loadAllChildTables();
                    loadDocumentList();
                }
                // 2026-08-29: Login History has nothing to show for a brand-new employee (no
                // login has ever happened yet) -- only revealed once a real, existing employee
                // has actually loaded. The table itself is lazy-initialized on first tab show
                // (see the shown.bs.tab handler above), not here.
                $('#loginHistoryTabItem').removeClass('d-none');
                // 2026-09-03, Platform Hardening Phase 3 Stage 5 -- same "nothing to override on a
                // not-yet-saved employee" reasoning; this <li> only even exists in the DOM at all
                // when the server already confirmed the acting user holds rbac.view (see
                // detail.php's own PHP-level gate), so this jQuery call is a harmless no-op
                // (selects nothing) for anyone who can't manage overrides.
                $('#permissionOverridesTabItem').removeClass('d-none');
            } else {
                showWarning(res.message || langData['employee_not_found'] || 'Employee not found.');
            }
            refreshEmployeeFormBaseline();
            // 2026-08-30, real bug found and fixed (explicit report: "เข้าใช้งานใน tab ที่เป็น
            // data table refresh แล้ว data table ไม่ทำงาน") -- root cause: the URL-hash tab
            // restore used to fire unconditionally at the end of the page's own $(function(){...})
            // init block, synchronously, well BEFORE this async response ever comes back. Landing
            // directly on the Login History tab via that early restore fired its own
            // shown.bs.tab-triggered lazy init (initLoginHistoryTable(), which reads
            // currentEmployeeId) while currentEmployeeId was still null/undefined -- a genuine
            // user click always happened long after this response settled, so this race never
            // showed up before hash-restore existed. Moved here, after currentEmployeeId is
            // definitely set (or the fetch has definitely failed), so restoring a DataTable-
            // bearing tab on refresh now sees the same state a real click always would.
            activateEmployeeTabFromHash();
        },
        error: function () {
            showWarning(langData['load_employee_failed'] || 'Failed to load employee data.');
            activateEmployeeTabFromHash();
        }
    });
}
$(function () {
    if (typeof initSelect2 === 'function') {
        initSelect2('.select2-remote', { mode: 'ajax' });
        initSelect2('.select2-native', { mode: 'native' });
    }
    initMobileIti();
    $('input[name="employee_type_radio"]').on('change', function () {
        const type = $(this).val();
        $('#employee_type').val(type);
        $('#sectionDomestic').toggleClass('d-none', type !== 'domestic');
        $('#sectionForeigner').toggleClass('d-none', type !== 'foreigner');
        applyEmployeeTypeRequired(type);
        applyMilitaryStatusVisibility();
    }).filter(':checked').trigger('change');
    $('input[name="gender_radio"]').on('change', function () {
        $('#gender').val($(this).val());
        applyMilitaryStatusVisibility();
    }).filter(':checked').trigger('change');
    $('#employment_status').on('change', applyEmploymentEndFieldsVisibility).trigger('change');
    $('#employment_status').on('change', applyProbationPolicyVisibility).trigger('change');
    $('#tax_exempt').on('change', applyTaxExemptVisibility).trigger('change');
    $('#employment_type').on('change', applyInternPolicyVisibility).trigger('change');
    $('#employment_type').on('change', function (e) { applyEmploymentTypeInternLock(!!e.originalEvent); }).trigger('change');
    // 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) -- select2:select
    // (not plain 'change') is the one event whose payload actually carries the picked option's full
    // item data (including `code`, per input.js's own processResults() spread) -- the page-LOAD path
    // sets #payment_method_code directly from the server's own resolved value instead (see
    // populateEmployeeForm()'s own call to applyPaymentMethodVisibility()), since
    // populateSelect2Field()'s plain `new Option(...)` never carries that extra data through.
    $(document).on('select2:select', '#payment_method_id', function (e) {
        applyPaymentMethodVisibility(e.params.data.code || '');
    });
    $(document).on('select2:clear', '#payment_method_id', function () {
        applyPaymentMethodVisibility('');
    });
    // 2026-09-02, explicit request: cycle-scoped account picker -- only updates the data-cycle-id
    // attribute (read fresh on every select2 search, see input.js's own extraData reader) rather
    // than also clearing the current selection, since this same 'change' event also fires from
    // populateSelect2Field('cycle_id', ...)'s own programmatic .trigger('change') on page load --
    // clearing there would wipe the just-loaded default_bank_account_id value before the user ever
    // touched anything.
    $('#cycle_id').on('change', function () {
        const cycleId = $(this).val() || '';
        $('#default_bank_account_id').attr('data-cycle-id', cycleId);
        $('.payment-line-bank-account').attr('data-cycle-id', cycleId);
    });
    $(document).on('click', '#btnAddPaymentMethodLine', function () {
        addPaymentMethodLineRow({});
    });
    $(document).on('click', '.btn-remove-payment-line', function () {
        $(this).closest('.payment-method-line').remove();
    });
    // Toggles just THIS row's own bank-account picker, independent of the top-level
    // applyPaymentMethodVisibility() -- a mixed set can freely combine transfer/cash/check lines.
    $(document).on('select2:select', '.payment-line-method', function (e) {
        const code = e.params.data.code || '';
        $(this).closest('.payment-method-line').find('.payment-line-bank-account-wrap').toggleClass('d-none', code !== 'transfer');
    });
    $(document).on('select2:clear', '.payment-line-method', function () {
        $(this).closest('.payment-method-line').find('.payment-line-bank-account-wrap').addClass('d-none');
    });
    // 2026-09-02, redesigned into the "use company policy (read-only info card) vs custom" pattern
    // -- see #internPolicySection's own comment in the view for the full reasoning. Unchecking
    // clears the value so a save correctly submits null (generic empty-string-to-null coercion in
    // EmployeeModel::save()) instead of silently keeping a stale hidden value; the info card
    // reappears the moment "custom" is unchecked, showing the company default that's back in effect.
    // 2026-09-02, follow-up to close a review-flagged gap: expanded from 1 field (ratio) to ALL 7
    // intern_*_override columns -- unchecking must clear EVERY one of them, not just the ratio, or
    // collectEmployeeFormData()'s generic [name] loop would still read+submit a stale hidden value
    // for a field the user can no longer even see.
    const INTERN_OVERRIDE_FIELD_IDS = ['intern_base_salary_ratio_override', 'intern_defer_pvd_override',
        'intern_defer_sso_override', 'intern_defer_recurring_earning_override', 'intern_leave_days_limit_override',
        'intern_allow_leave_override', 'intern_period_days_override'];
    const PROBATION_OVERRIDE_FIELD_IDS = ['probation_base_salary_ratio_override', 'probation_defer_pvd_override',
        'probation_defer_sso_override', 'probation_defer_recurring_earning_override', 'probation_leave_days_limit_override',
        'probation_allow_leave_override', 'probation_period_days_override'];
    $('#internRatioOverrideToggle').on('change', function () {
        const checked = $(this).is(':checked');
        $('#internRatioOverrideFieldsRow').toggleClass('d-none', !checked);
        $('#internPolicyInfoCard').toggleClass('d-none', checked);
        if (!checked) {
            INTERN_OVERRIDE_FIELD_IDS.forEach(function (id) { $(`#${id}`).val('').trigger('change'); });
        }
    });
    // 2026-09-02, Probation gained the same per-employee override Internship already had -- direct
    // mirror of the toggle handler immediately above.
    $('#probationRatioOverrideToggle').on('change', function () {
        const checked = $(this).is(':checked');
        $('#probationRatioOverrideFieldsRow').toggleClass('d-none', !checked);
        $('#probationPolicyInfoCard').toggleClass('d-none', checked);
        if (!checked) {
            PROBATION_OVERRIDE_FIELD_IDS.forEach(function (id) { $(`#${id}`).val('').trigger('change'); });
        }
    });
    $('#use_register_address').on('change', function () {
        const checked = $(this).is(':checked');
        const pairs = [
            ['address_line_1_register', 'address_line_1_contact'],
            ['address_line_2_register', 'address_line_2_contact'],
            ['master_address_id_register', 'master_address_id_contact'],
            ['search_address_register', 'search_address_contact']
        ];
        pairs.forEach(function ([fromId, toId]) {
            if (checked) {
                $(`#${toId}`).val($(`#${fromId}`).val()).removeClass('is-invalid').prop('readonly', true).addClass('bg-light');
            } else {
                $(`#${toId}`).prop('readonly', false).removeClass('bg-light');
            }
        });
        // Mirroring above can change master_address_id_contact (matched<->unmatched) either way --
        // re-check both, same "not yet matched" indicator as populateEmployeeForm()'s own call.
        updateAllAddressMatchIndicators();
    });
    // 2026-08-30 (T020) -- applyPayrollParticipantVisibility() itself sets #is_payroll_participant.
    $('input[name="is_payroll_participant_radio"]').on('change', function () {
        applyPayrollParticipantVisibility($(this).val() === '1');
    }).filter(':checked').trigger('change');
    // 2026-08-19, explicit request: SSO detail fields (sso_no/sso_start_date) only show once
    // "Enrolled" is checked -- populateEmployeeForm()'s generic checkbox handling already calls
    // .trigger('change') after setting sso_enrolled from loaded data, so this re-fires correctly on
    // every employee load too, not just when the user clicks it. Also keeps the visible Yes/No
    // toggle (#ssoEnrolledToggle) in sync with the real (now hidden) checkbox, in both directions --
    // this handler reacts to the checkbox changing (data load, or the toggle click below), and the
    // toggle's own click handler is what actually changes the checkbox in the first place.
    // 2026-08-20, explicit request ("Tab ประกันสังคม ถ้าตอบใช่ให้บังคับกรอก"): SSO No./Start Date
    // become .required exactly when the detail fields become visible -- same toggle-the-class-
    // on/off pattern applyEmployeeTypeRequired() already uses (validateEmployeeForm() has no
    // .d-none skip, so a hidden-but-still-.required field would otherwise wrongly block Save).
    // is-invalid is cleared on hide so a previously-flagged field doesn't stay marked invalid
    // once it's no longer required.
    $('#sso_enrolled').on('change', function () {
        const checked = $(this).is(':checked');
        $('#ssoDetailFields').toggleClass('d-none', !checked);
        $('#ssoEnrolledToggle button').removeClass('active').filter(`[data-value="${checked ? 'yes' : 'no'}"]`).addClass('active');
        $('#sso_no, #sso_start_date').toggleClass('required', checked);
        if (!checked) $('#sso_no, #sso_start_date').removeClass('is-invalid');
    }).trigger('change');
    $('#ssoEnrolledToggle button').on('click', function () {
        $('#sso_enrolled').prop('checked', $(this).data('value') === 'yes').trigger('change');
    });
    // Same Yes/No toggle pattern for PVD -- no detail-fields visibility tied to it (that section is
    // entirely hidden already, see the Provident Fund column's own "Hidden 2026-08-19" comment), just
    // keeping the toggle and the real checkbox in sync.
    $('#pvd_enrolled').on('change', function () {
        const checked = $(this).is(':checked');
        $('#pvdEnrolledToggle button').removeClass('active').filter(`[data-value="${checked ? 'yes' : 'no'}"]`).addClass('active');
    }).trigger('change');
    $('#pvdEnrolledToggle button').on('click', function () {
        $('#pvd_enrolled').prop('checked', $(this).data('value') === 'yes').trigger('change');
    });
    // Same Yes/No toggle pattern applied to the Family tab's Spouse question too (2026-08-19,
    // explicit request, same reasoning as SSO/PVD) -- also toggles #spouseDetailFields visibility.
    $('#has_spouse').on('change', function () {
        const checked = $(this).is(':checked');
        $('#spouseDetailFields').toggleClass('d-none', !checked);
        $('#hasSpouseToggle button').removeClass('active').filter(`[data-value="${checked ? 'yes' : 'no'}"]`).addClass('active');
    }).trigger('change');
    $('#hasSpouseToggle button').on('click', function () {
        $('#has_spouse').prop('checked', $(this).data('value') === 'yes').trigger('change');
    });
    $('#profile_photo_input').on('change', function (e) {
        const file = e.target.files[0];
        if (!file) return;
        // Instant local preview (unchanged) while the real upload below is in flight.
        const reader = new FileReader();
        reader.onload = function (ev) {
            $('#profilePreview').attr('src', ev.target.result).removeClass('d-none');
            $('#profilePlaceholder').addClass('d-none');
        };
        reader.readAsDataURL(file);
        uploadEmpPhotoBlob(file);
        $(this).val('');
    });
    $('#id_card_no').on('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 13);
    }).on('blur', function () {
        const val = $(this).val();
        if (val.length === 0) {
            $(this).removeClass('is-invalid');
            return;
        }
        $(this).toggleClass('is-invalid', !isValidThaiID(val));
    });
    initDatepicker();
    // Every tab's Save button just saves -- 2026-08-19, explicit request: stay on whichever tab you
    // were on, never auto-navigate to the next one (previously named after the destination tab
    // because they used to jump there on success; ids kept as-is, only the jump was removed).
    $('#btnNextContact').on('click', function () { saveEmployee($(this)); });
    $('#btnNextEmployment').on('click', function () { saveEmployee($(this)); });
    $('#btnNextSalary').on('click', function () { saveSalaryTab($(this)); });
    $('#btnNextSocial').on('click', function () { saveEmployee($(this)); });
    $('#btnNextFamily').on('click', function () { saveEmployee($(this)); });
    // 2026-08-20, explicit request ("ตัดให้เหลือปุ่ม Save แค่ปุ่มเดียว"): the Family tab's own
    // button (physically inside #family-pane, historical id aside -- see the 2026-08-19 comment
    // above) now saves spouse + father/mother + every dependent card together -- see
    // saveFamilyTab(). Every other tab's button is untouched, still the plain per-tab saveEmployee().
    $('#btnNextDocuments').on('click', function () { saveFamilyTab($(this)); });
    $('.btn-cancel-employee-tab').on('click', cancelEmployeeEdit);
    loadEmployeeIfEditing();
    initChildTables();
    initDocumentUpload();
    initEedUI();
    initRecurringEarningUI();
    initRecurringDeductionUI();
    initOtRateUI();
});

// 2026-08-29, explicit request: "ในหน้า Employee Detail ก็อยากให้คลิกที่ Tab ไหน ถ้า Refresh ให้อยู่ที่ Tab
// นั้น" -- same URL-hash + history.replaceState mechanism already built for Payroll Process
// Detail/List (payroll/detail.js's own activateTabFromHash()/shown.bs.tab handler) -- persist
// whichever tab is active across a refresh instead of always resetting to Employee Info.
// 2026-09-07: also re-runs the tab-bar overflow layout below on every tab switch (whichever tab just
// became active must never be one of the ones tucked into "More").
$(document).on('shown.bs.tab', '#employeeTabs button[data-bs-toggle="tab"]', function (e) {
    if (history.replaceState) {
        history.replaceState(null, '', '#' + e.target.id);
    }
    layoutEmployeeTabs();
});
function activateEmployeeTabFromHash() {
    const hash = (location.hash || '').replace('#', '');
    if (!hash) return;
    const $btn = $('#' + CSS.escape(hash));
    if ($btn.length && $btn.attr('data-bs-toggle') === 'tab' && $btn.closest('#employeeTabs').length) {
        bootstrap.Tab.getOrCreateInstance($btn[0]).show();
    }
}

/* ==================== Employee Detail tab-bar overflow ("More" dropdown) ====================
 * 2026-09-07, explicit request: "อยากให้ปรับส่วนของ tab ให้ดูสวยขึ้น และถ้าเลยจอการแสดงผลให้ขึ้น more กับ
 * ตัวเลข กดแล้วเป็น dropdown ลงมา" -- up to 10 real top-level tabs on this page (the most any tab bar
 * in this app has). Measures #employeeTabs the same way the header's own Quick Links overflow does
 * (public/js/quick-links.js's own layoutQuickLinks(): compare `scrollWidth` vs `clientWidth` on a
 * flex row) and hides whichever tabs don't fit, starting from the END and always skipping the
 * CURRENTLY ACTIVE tab (never hide the one thing already on screen). Every hidden tab's real
 * `<button>` stays exactly where it was in the DOM the whole time (just `display:none` via
 * `.edt-tab-overflow-hidden`, see detail.php's own CSS) -- nothing is moved, cloned, or removed, so
 * every OTHER shown.bs.tab listener/completeness-badge selector already scattered across this file
 * keeps working completely unchanged no matter how many tabs are currently tucked into the dropdown.
 *
 * `edtTabsObserver` exists to catch this page's OWN later reveals of previously-`d-none` tabs
 * (`.employee-secondary-tab` en masse once the first save creates a real employee_no,
 * `#loginHistoryTabItem`/`#permissionOverridesTabItem` individually) WITHOUT needing to sprinkle an
 * explicit layoutEmployeeTabs() call after each of those several, scattered reveal points -- it just
 * re-runs this function whenever any child's `class` attribute changes. That includes changes THIS
 * SAME FUNCTION makes to `.edt-tab-overflow-hidden` -- disconnecting the observer before doing any
 * of its own DOM writes and reconnecting only once it's done (not a simple in-progress boolean flag,
 * which would NOT work here: MutationObserver callbacks are delivered as a microtask AFTER the
 * function that caused them has already returned and reset any such flag, so a plain flag can't
 * actually prevent the observer from re-triggering on the function's own writes) is what keeps this
 * from re-triggering itself forever. */
let edtTabsObserver = null;
function layoutEmployeeTabs() {
    const tabsEl = document.getElementById('employeeTabs');
    if (!tabsEl) return;
    if (edtTabsObserver) edtTabsObserver.disconnect();
    try {
        const $tabs = $(tabsEl);
        const $moreItem = $('#employeeTabsMoreItem');
        const $allItems = $tabs.children('.nav-item').not($moreItem);
        // Reset to whatever this page's OWN business logic (new-employee progressive reveal,
        // permission gates) currently allows, before deciding what genuinely doesn't fit.
        $allItems.removeClass('edt-tab-overflow-hidden');
        $moreItem.addClass('d-none');

        const $candidates = $allItems.filter(function () { return !$(this).hasClass('d-none'); });
        if ($candidates.length < 2) return;

        const hidden = [];
        // +2px tolerance against sub-pixel rounding falsely tripping the loop forever.
        while (tabsEl.scrollWidth > tabsEl.clientWidth + 2) {
            const $stillVisible = $candidates.filter(function () {
                return !$(this).hasClass('edt-tab-overflow-hidden') && !$(this).find('.nav-link').hasClass('active');
            });
            if (!$stillVisible.length) break;
            const $last = $stillVisible.last();
            $last.addClass('edt-tab-overflow-hidden');
            hidden.unshift($last);
            $moreItem.removeClass('d-none');
        }
        renderEmployeeTabsMoreMenu(hidden);
    } finally {
        if (edtTabsObserver) {
            edtTabsObserver.observe(tabsEl, { attributes: true, attributeFilter: ['class'], subtree: true });
        }
    }
}
function renderEmployeeTabsMoreMenu(hiddenItems) {
    const $menu = $('#employeeTabsMoreMenu').empty();
    $('#employeeTabsMoreCount').text(hiddenItems.length);
    hiddenItems.forEach(function ($li) {
        const $btn = $li.find('.nav-link');
        const isActive = $btn.hasClass('active');
        const $clone = $btn.clone();
        $clone.find('.completeness-tab-badge').remove();
        const label = $clone.text().trim();
        const $icon = $btn.find('> i').first();
        const iconHtml = $icon.length ? $icon[0].outerHTML : '';
        const targetId = $btn.attr('id');
        const $item = $(`<button type="button" class="dropdown-item${isActive ? ' active' : ''}"></button>`)
            .html(`${iconHtml}<span>${escapeHtml(label)}</span>`)
            .on('click', function () {
                const el = document.getElementById(targetId);
                if (el) bootstrap.Tab.getOrCreateInstance(el).show();
            });
        $menu.append($('<li></li>').append($item));
    });
}
$(window).on('resize', typeof debounce === 'function' ? debounce(layoutEmployeeTabs, 150) : layoutEmployeeTabs);
$(document).ready(function () {
    layoutEmployeeTabs();
    const tabsEl = document.getElementById('employeeTabs');
    if (tabsEl && typeof MutationObserver !== 'undefined') {
        // 2026-09-07, real bug found and fixed (explicit report: "more กดไม่ได้ครับ") -- Bootstrap's
        // own Dropdown component (node_modules/bootstrap/js/src/dropdown.js's show()) adds a `.show`
        // class to BOTH the toggle <a> and the .dropdown-menu when opened -- both live inside
        // #employeeTabsMoreItem, itself inside #employeeTabs, so clicking "More" was ALSO a `class`
        // mutation this observer watches. That immediately re-ran layoutEmployeeTabs(), whose own
        // reset step (`$moreItem.addClass('d-none')`) re-hid the More <li> -- and the just-opened
        // dropdown-menu along with it, since it's one of that <li>'s own children -- a fraction of a
        // second after Bootstrap opened it, so it never stayed open long enough to use. Fixed by
        // ignoring any mutation whose target is inside #employeeTabsMoreItem entirely -- that
        // element's own class changes are always Bootstrap's dropdown open/close state, never a
        // reason to recompute which tabs fit.
        edtTabsObserver = new MutationObserver(function (mutations) {
            const relevant = mutations.some(function (m) {
                return !$(m.target).closest('#employeeTabsMoreItem').length;
            });
            if (relevant) layoutEmployeeTabs();
        });
        edtTabsObserver.observe(tabsEl, { attributes: true, attributeFilter: ['class'], subtree: true });
    }
});

const DOCUMENT_INPUT_MAP = {
    doc_id_card_copy: 'id_card_copy',
    doc_house_registration_copy: 'house_registration_copy',
    doc_work_permit_copy: 'work_permit_copy',
    doc_employment_contract: 'employment_contract',
    doc_bank_book_copy: 'bank_book_copy',
    doc_resume: 'resume',
    doc_education_certificate: 'education_certificate',
    // 2026-09-03, alongside EmployeeSyncer's own document-scan sync (see EmployeeModel::
    // documentTypes()'s own docblock) -- work_permit_copy above already covers a synced work
    // permit scan too, no 3rd input needed for that one.
    doc_passport_copy: 'passport_copy',
    doc_visa_copy: 'visa_copy',
    doc_other: 'other'
};
const DOCUMENT_TYPE_LABEL_KEY = {
    id_card_copy: 'id_card_copy',
    house_registration_copy: 'house_registration_copy',
    work_permit_copy: 'work_permit_copy',
    employment_contract: 'employment_contract',
    bank_book_copy: 'bank_book_copy',
    resume: 'resume',
    education_certificate: 'education_certificate',
    passport_copy: 'passport_copy',
    visa_copy: 'visa_copy',
    other: 'other_documents'
};
const ALLOWED_DOC_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
const MAX_DOC_SIZE = 10 * 1024 * 1024;
// 2026-09-03, alongside EmployeeSyncer's own document-scan sync -- a plain badge (not editable,
// not a link) distinguishing a row a human uploaded here from one EmployeeSyncer wrote from an
// Origami document_url, same "manual vs synced" visual convention this app already uses elsewhere
// for sync-derived data.
function documentSourceBadge(source) {
    if (source === 'sync') {
        return `<span class="badge bg-info-subtle text-info" data-i18n="document_source_sync">${escapeHtml(langData['document_source_sync'] || 'Synced from Origami')}</span>`;
    }
    return `<span class="badge bg-secondary-subtle text-secondary" data-i18n="document_source_manual">${escapeHtml(langData['document_source_manual'] || 'Manual')}</span>`;
}
function addDocumentRow(doc) {
    const labelKey = DOCUMENT_TYPE_LABEL_KEY[doc.document_type] || doc.document_type;
    const typeLabel = langData[labelKey] || doc.document_type;
    // Platform Hardening Phase 5B: a small preview thumbnail for image-mime rows (jpg/png only --
    // pdf/doc/docx never get one, see ThumbnailGenerator's own docblock) beside the filename, falling
    // back to plain filename text when thumbnail_path is NULL (non-image doc, or a row uploaded
    // before this column existed).
    const thumbHtml = doc.thumbnail_path
        ? `<img src="${BASE_URL}/${doc.thumbnail_path}" alt="" class="doc-row-thumb me-2">`
        : '';
    const $tr = $(
        '<tr>' +
        `<td>${thumbHtml}${escapeHtml(doc.file_name)}</td>` +
        `<td data-i18n="${labelKey}">${escapeHtml(typeLabel)}</td>` +
        `<td>${escapeHtml(doc.uploaded_at || '')}</td>` +
        `<td class="text-center">${documentSourceBadge(doc.source)}</td>` +
        '<td class="text-center">' +
        // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
        // ".btn-circle-action" section) replace the old adjacent .btn-group.
        '<div class="d-flex gap-1 justify-content-center">' +
        `<a href="${BASE_URL}/api/employee.document.view?id=${encodeURIComponent(doc.id)}" target="_blank" class="btn btn-link btn-circle-action text-info"><i class="fa-solid fa-eye"></i></a>` +
        '<button type="button" class="btn btn-link btn-circle-action text-danger btn-delete-document"><i class="fa-solid fa-trash-can"></i></button>' +
        '</div>' +
        '</td>' +
        '</tr>'
    );
    $tr.attr('data-id', doc.id).data('id', doc.id);
    $('#tableDocumentList tbody').append($tr);
}
/* ==================== Login History tab (2026-08-29) ====================
   Explicit request: "ต้องการอีก Tab ใน Employee เพื่อดูประวัติการเข้าใช้งานระบบโดยแสดงข้อมูลแบบละเอียดตามที่
   เก็บ...และสามารถ Filter ได้" -- server-side DataTable, scoped to this one employee
   (EmployeeLoginLogController::list()'s own employee_id param), device/browser filter dropdowns
   populated from whatever values actually exist for this employee (EmployeeLoginLogModel::
   distinctFilterValues(), not a hardcoded list -- a brand-new employee with only ever logged in
   from Chrome has no reason to see a Firefox/Safari option). ==================== */
let tb_login_history;
/* 2026-08-30, explicit request: "ทุกตารางที่มี icon ให้เป็นรูปแบบเดียวกับ report ทั้งหมดครับ" -- reuses
   the shared .row-type-icon gradient badge (style.css, promoted from reports/index.js's own
   report-type icon) instead of a bare colored <i>, same device->color mapping as
   loginHistoryOverviewDeviceIcon() in employee/list.js's own Login History Overview table. */
function loginHistoryDeviceIconRd(deviceType) {
    const map = { desktop: { icon: 'fa-desktop', rt: 'rt-3' }, mobile: { icon: 'fa-mobile-screen', rt: 'rt-1' }, tablet: { icon: 'fa-tablet-screen-button', rt: 'rt-5' }, bot: { icon: 'fa-robot', rt: 'rt-4' } };
    return map[deviceType] || { icon: 'fa-question', rt: 'rt-2' };
}
function loadLoginHistoryFilterOptions() {
    if (!currentEmployeeId) return;
    $.getJSON(`${BASE_URL}/api/employee-login-log.filter-options`, { employee_id: currentEmployeeId }, function (res) {
        if (!res.status) return;
        const $device = $('#loginHistoryFilterDevice').empty().append(`<option value="">${langData['select_option'] || 'All'}</option>`);
        (res.data.device_types || []).forEach(v => $device.append(`<option value="${v}">${v}</option>`));
        const $browser = $('#loginHistoryFilterBrowser').empty().append(`<option value="">${langData['select_option'] || 'All'}</option>`);
        (res.data.browser_names || []).forEach(v => $browser.append(`<option value="${v}">${v}</option>`));
        $device.trigger('change');
        $browser.trigger('change');
    });
}
// 2026-08-30, Phase 7 (T037/T038 follow-up: surfacing is_active/ended_reason in the existing Login
// History audit table, which previously had no visual representation of either at all despite the
// backend now tracking both). is_active can come back as a string "1"/"0" or a real int depending
// on PDO's fetch mode -- Number(...) normalizes either.
function loginHistoryStatusBadgeRd(row) {
    if (Number(row.is_active) === 1) {
        return `<span class="badge bg-success-subtle text-success">${langData['session_status_active'] || 'Active'}</span>`;
    }
    const reasonKey = { new_login: 'session_reason_new_login', switch_app: 'session_reason_switch_app', timeout: 'session_reason_timeout' }[row.ended_reason];
    const label = (reasonKey && langData[reasonKey]) || langData['session_status_ended'] || 'Ended';
    const tone = row.ended_reason === 'timeout' ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary';
    return `<span class="badge ${tone}">${escapeHtml(label)}</span>`;
}
function initLoginHistoryTable() {
    if (!currentEmployeeId) return;
    if ($.fn.DataTable.isDataTable('#tableLoginHistory')) {
        $('#tableLoginHistory').DataTable().ajax.reload();
        return;
    }
    tb_login_history = $('#tableLoginHistory').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        order: [[0, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/employee-login-log.list`,
            type: 'POST',
            data: function (d) {
                d.employee_id = currentEmployeeId;
                d.date_from = $('#loginHistoryFilterDateFrom').val() || '';
                d.date_to = $('#loginHistoryFilterDateTo').val() || '';
                d.device_type = $('#loginHistoryFilterDevice').val() || '';
                d.browser_name = $('#loginHistoryFilterBrowser').val() || '';
            }
        },
        columns: [
            { data: 'login_at', render: d => escapeHtml(typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : (d || '-')) },
            // 2026-08-29: logout_at is only ever set by auth/switch.php's own "Switch App away from
            // Payroll" capture (see that file's own docblock) -- null is the normal, expected state
            // for a session that ended any other way (tab closed, browser closed, session expired),
            // not a sign anything is broken.
            { data: 'logout_at', render: d => escapeHtml(d && typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(d) : '-') },
            { data: 'ip_address', render: d => escapeHtml(d || '-') },
            { data: null, render: (d, t, row) => escapeHtml([row.location_city, row.location_country].filter(Boolean).join(', ') || '-') },
            { data: 'timezone', render: d => escapeHtml(d || '-') },
            { data: 'device_type', render: d => { const m = loginHistoryDeviceIconRd(d); return `<span class="row-type-icon ${m.rt}"><i class="fa-solid ${m.icon}"></i></span>${escapeHtml(d || '-')}`; } },
            { data: null, render: (d, t, row) => escapeHtml([row.os_name, row.os_version].filter(Boolean).join(' ') || '-') },
            { data: null, render: (d, t, row) => escapeHtml([row.browser_name, row.browser_version].filter(Boolean).join(' ') || '-') },
            { data: null, orderable: false, render: (d, t, row) => loginHistoryStatusBadgeRd(row) },
        ],
        // 2026-08-30, real gap found and fixed (explicit request: "จำนวนแสดงต่อหน้า 50 รายการเป็น
        // Default...มีตารางอื่นที่ยังไม่ใช้ Format เดียวกันอีกไหมครับ", found via a full-codebase audit) --
        // was missing entirely, silently falling back to DataTables' own built-in default of 10.
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
    });
}
function updateClearLoginHistoryFilterVisibility() {
    const hasFilter = !!($('#loginHistoryFilterDateFrom').val() || $('#loginHistoryFilterDateTo').val() || $('#loginHistoryFilterDevice').val() || $('#loginHistoryFilterBrowser').val());
    $('#loginHistoryFilterClearRow').toggleClass('d-none', !hasFilter);
}
$(document).on('change', '#loginHistoryFilterDateFrom, #loginHistoryFilterDateTo, #loginHistoryFilterDevice, #loginHistoryFilterBrowser', function () {
    updateClearLoginHistoryFilterVisibility();
    if ($.fn.DataTable.isDataTable('#tableLoginHistory')) {
        $('#tableLoginHistory').DataTable().ajax.reload();
    }
});
// 2026-08-30, same-day follow-up ("Tab ประวัติการเข้าใช้งานใน Employee Detail ยังไม่ใช่ Filter มาตรฐาน")
// -- the standard .station-filter toggle/clear pair, same idiom as Employee List's own station
// filters.
$(document).on('click', '#loginHistoryStationFilterToggle', function () {
    const $filter = $('#loginHistoryStationFilter').toggleClass('collapsed');
    const collapsed = $filter.hasClass('collapsed');
    $(this).find('i').toggleClass('fa-chevron-up', !collapsed).toggleClass('fa-chevron-down', collapsed);
});
$(document).on('click', '#btnClearLoginHistoryFilter', function () {
    $('#loginHistoryFilterDateFrom, #loginHistoryFilterDateTo').val('');
    if (typeof $.fn.datepicker === 'function') {
        $('#loginHistoryFilterDateFrom, #loginHistoryFilterDateTo').datepicker('update');
    }
    // 'change' (not 'change.select2') -- matches the exact same select2-native device/browser
    // clear-pattern list.js's own Login History OVERVIEW tab already uses successfully.
    $('#loginHistoryFilterDevice, #loginHistoryFilterBrowser').val(null).trigger('change');
    updateClearLoginHistoryFilterVisibility();
    if ($.fn.DataTable.isDataTable('#tableLoginHistory')) {
        $('#tableLoginHistory').DataTable().ajax.reload();
    }
});
// Lazy-init on first tab show -- a DataTable constructed while its own tab-pane is `display:none`
// collapses every column to 0 width (this app's own well-known DataTables+Bootstrap-tab gotcha,
// hit and fixed the same way in several other places already, e.g. payslip-template.js).
$(document).on('shown.bs.tab', '#login-history-tab', function () {
    loadLoginHistoryFilterOptions();
    initLoginHistoryTable();
});

/**
 * 2026-09-03, Platform Hardening Phase 3 Stage 5 -- "Permission Overrides" tab. Not a DataTable
 * (a fixed permission-list x ONE-employee grid, same reasoning as the Permission Matrix's own plain
 * <table>). `poState` is the single source of truth for every row's current choice, mirroring
 * permission-matrix.js's own `pmState` pattern (never read fresh from the DOM at Save time) --
 * keyed by permission_id, value is `{effect: 'grant'|'deny'|null, allow_scope, detail_level}`
 * (`effect: null` means Inherit). `poBaselineSnapshot` backs a simple unsaved-changes guard on tab
 * switch, matching the Permission Matrix's own Cancel-button dirty-check.
 *
 * Module labels duplicate permission-matrix.js's own `permissionModuleLabel()` map on purpose,
 * not shared via a common helper -- keep both in sync when a new module_code is added to
 * `permissions` (that file's own docblock already documents having hit this exact "forgot to
 * update the map" bug twice; this is the SAME map, just present in a second file now that there
 * are 2 real consumers of it).
 */
let poState = {};
let poRows = [];
let poBaselineSnapshot = null;

function permissionOverrideModuleLabel(code) {
    const map = {
        holiday: langData['holiday'] || 'Holiday',
        leave_type: langData['leave_type'] || 'Leave Type',
        approval_workflow: langData['approval_workflow'] || 'Approval Workflow',
        approval_request: langData['approval_monitor'] || 'Approval Monitor',
        rbac: langData['permissions_menu'] || langData['permissions'] || 'Permissions',
        employee: langData['employee'] || 'Employee',
        company_structure: langData['organization_structure'] || 'Organization Structure',
        bank_account: langData['bank_account'] || 'Bank Account',
        payslip_template: langData['payslip_template'] || 'Payslip Template',
        payroll_configuration: langData['payroll_configuration'] || 'Payroll Configuration',
        tax_statutory: langData['local_statutory_and_tax_settings'] || 'Local Statutory & Tax Settings',
        company_profile: langData['company_profile'] || 'Company Profile',
        employment_certificate_template: langData['employment_certificate_template'] || 'Employment Certificate Template',
        email_queue: langData['email_queue_log'] || 'Email Queue Log',
        employee_login_log: langData['login_history'] || 'Login History',
        payroll_run_cash_payment: langData['tab_cash_payments'] || 'Cash Payments',
        payroll_sync: langData['origami_sync_summary_title'] || 'Origami Sync',
        reports: langData['reports'] || 'Reports',
        salary_amount: langData['permission_module_salary_amount'] || 'Salary Amount Visibility',
        payroll_run: langData['payroll_process'] || 'Payroll Process',
        shift: langData['shift'] || 'Shift',
        work_location: langData['work_location'] || 'Work Location',
        ot_rate: langData['ot_rate'] || 'OT Rate',
    };
    return map[code] || code;
}


/* ---------- Employee access suspension (2026-09-04, Backlog Phase 10, T059) ---------- */
function loadSuspensionStatus() {
    if (!currentEmployeeId) return;
    // Self-suspend is refused server-side regardless, but there's no reason to show a button that
    // would always be rejected for the viewer's own record.
    if (typeof SESSION_EMPLOYEE_ID !== 'undefined' && currentEmployeeId === SESSION_EMPLOYEE_ID) {
        $('#employeeSuspensionCard').addClass('d-none');
        return;
    }
    $('#employeeSuspensionCard').removeClass('d-none');
    $.ajax({
        url: `${BASE_URL}/api/permission-employee-suspension.get`,
        method: 'GET',
        data: { employee_id: currentEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            renderSuspensionStatus(res.data);
        }
    });
}
function renderSuspensionStatus(data) {
    const $text = $('#employeeSuspensionStatusText');
    const $suspendBtn = $('#btnSuspendEmployee');
    const $unsuspendBtn = $('#btnUnsuspendEmployee');
    if (data) {
        const byName = (currentLang === 'th' ? data.suspended_by_name_th : data.suspended_by_name_en) || '-';
        const when = typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(data.suspended_at) : data.suspended_at;
        $text.html(`<span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i>${(langData['suspended_since'] || 'Suspended since')} ${escapeAttr(when)} ${(langData['suspended_by_label'] || 'by')} ${escapeAttr(byName)}${data.reason ? ' — ' + escapeAttr(data.reason) : ''}</span>`);
        $suspendBtn.addClass('d-none');
        $unsuspendBtn.removeClass('d-none');
    } else {
        $text.html(`<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i>${(langData['access_active'] || 'Access active')}</span>`);
        $suspendBtn.removeClass('d-none');
        $unsuspendBtn.addClass('d-none');
    }
}
$(document).on('click', '#btnSuspendEmployee', function () {
    Swal.fire({
        title: langData['confirm_suspend_title'] || 'Suspend this employee\'s access?',
        html: `<p>${langData['confirm_suspend_message'] || 'They will be unable to access the system at all until restored.'}</p>
               <textarea id="swalSuspendReason" class="form-control mt-2" rows="2" placeholder="${langData['suspend_reason_placeholder'] || 'Reason for suspension (required)'}"></textarea>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: langData['suspend_access'] || 'Suspend Access',
        confirmButtonColor: '#dc3545',
        cancelButtonText: langData['cancel'] || 'Cancel',
        preConfirm: () => {
            const reason = $('#swalSuspendReason').val().trim();
            if (!reason) {
                Swal.showValidationMessage(langData['suspend_reason_required'] || 'A reason is required.');
                return false;
            }
            return reason;
        }
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $.ajax({
            url: `${BASE_URL}/api/permission-employee-suspension.suspend`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ employee_id: currentEmployeeId, reason: result.value }),
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                    loadSuspensionStatus();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
        });
    });
});
$(document).on('click', '#btnUnsuspendEmployee', function () {
    showConfirm(
        langData['confirm_unsuspend_title'] || 'Restore this employee\'s access?',
        langData['confirm_unsuspend_message'] || 'They will immediately regain access according to their role/overrides.',
        function () {
            $.ajax({
                url: `${BASE_URL}/api/permission-employee-suspension.unsuspend`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ employee_id: currentEmployeeId }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                        loadSuspensionStatus();
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                    }
                },
                error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving the data.'); }
            });
        }
    );
});

function initPermissionOverridesTab() {
    if (!currentEmployeeId) return;
    loadSuspensionStatus();
    const $body = $('#permissionOverridesTableBody');
    $body.html(`<tr><td colspan="4" class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin"></i></td></tr>`);
    $.ajax({
        url: `${BASE_URL}/api/permission-employee-overrides.get`,
        method: 'GET',
        data: { employee_id: currentEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) {
                $body.html(`<tr><td colspan="4" class="text-center text-muted py-4">${escapeAttr(res.message || langData['load_employee_failed'] || 'Failed to load.')}</td></tr>`);
                return;
            }
            poRows = res.data || [];
            poState = {};
            poRows.forEach(r => {
                poState[r.permission_id] = { effect: r.override_effect, allow_scope: r.allow_scope, detail_level: r.detail_level };
            });
            renderPermissionOverridesTable();
            poBaselineSnapshot = JSON.stringify(poState);
        },
        error: function () {
            $body.html(`<tr><td colspan="4" class="text-center text-muted py-4">${escapeAttr(langData['load_employee_failed'] || 'Failed to load.')}</td></tr>`);
        }
    });
}

function renderPermissionOverridesTable() {
    const $body = $('#permissionOverridesTableBody');
    if (!poRows.length) {
        $body.html(`<tr><td colspan="4" class="text-center text-muted py-4">-</td></tr>`);
        return;
    }
    let html = '';
    let lastModule = null;
    poRows.forEach(p => {
        if (p.module_code !== lastModule) {
            lastModule = p.module_code;
            html += `<tr class="table-light"><td colspan="4"><strong>${escapeAttr(permissionOverrideModuleLabel(p.module_code))}</strong></td></tr>`;
        }
        const state = poState[p.permission_id] || { effect: null, allow_scope: 'all', detail_level: 'full' };
        const effect = state.effect || 'inherit';
        const isApprovalAct = p.permission_key === 'approval_request.act';
        const isSalaryAmount = p.permission_key.indexOf('salary_amount.') === 0;
        const showScope = effect === 'grant' && (isApprovalAct || isSalaryAmount);
        html += `<tr data-permission-id="${p.permission_id}">
            <td>${escapeAttr(currentLang === 'th' ? p.name_th : p.name_en)}</td>
            <td class="text-center">
                ${p.role_granted
                    ? `<i class="fa-solid fa-check text-success" title="${escapeAttr(langData['inherited'] || 'Inherited')}"></i>`
                    : `<i class="fa-solid fa-minus text-muted" title="${escapeAttr(langData['inherited'] || 'Inherited')}"></i>`}
            </td>
            <td class="text-center">
                <div class="btn-group btn-group-sm po-effect-group" role="group">
                    <input type="radio" class="btn-check po-effect-radio" name="po-effect-${p.permission_id}" id="po-inherit-${p.permission_id}" value="" ${effect === 'inherit' ? 'checked' : ''}>
                    <label class="btn btn-outline-secondary" for="po-inherit-${p.permission_id}">${escapeAttr(langData['override_inherit'] || 'Inherit')}</label>
                    <input type="radio" class="btn-check po-effect-radio" name="po-effect-${p.permission_id}" id="po-grant-${p.permission_id}" value="grant" ${effect === 'grant' ? 'checked' : ''}>
                    <label class="btn btn-outline-success" for="po-grant-${p.permission_id}">${escapeAttr(langData['override_grant'] || 'Grant')}</label>
                    <input type="radio" class="btn-check po-effect-radio" name="po-effect-${p.permission_id}" id="po-deny-${p.permission_id}" value="deny" ${effect === 'deny' ? 'checked' : ''}>
                    <label class="btn btn-outline-danger" for="po-deny-${p.permission_id}">${escapeAttr(langData['override_deny'] || 'Deny')}</label>
                </div>
            </td>
            <td class="text-center">`;
        if (isApprovalAct) {
            html += `<select class="form-select form-select-sm po-scope-select ${showScope ? '' : 'd-none'}" style="width:auto;margin:0 auto;">
                    <option value="all" ${state.allow_scope === 'own_department' ? '' : 'selected'}>${langData['scope_all'] || 'All'}</option>
                    <option value="own_department" ${state.allow_scope === 'own_department' ? 'selected' : ''}>${langData['scope_own_department'] || 'Own Dept.'}</option>
                </select>`;
        } else if (isSalaryAmount) {
            html += `<select class="form-select form-select-sm po-scope-select ${showScope ? '' : 'd-none'}" style="width:auto;margin:0 auto;">
                    <option value="all" ${state.allow_scope === 'all' ? 'selected' : ''}>${langData['scope_all'] || 'All'}</option>
                    <option value="own_only" ${state.allow_scope === 'own_only' ? 'selected' : ''}>${langData['scope_own_only'] || 'Own Only'}</option>
                </select>`;
        } else {
            html += '-';
        }
        html += `</td></tr>`;
    });
    $body.html(html);
}

$(document).on('change', '.po-effect-radio', function () {
    const $row = $(this).closest('tr');
    const permissionId = $row.data('permission-id');
    const effect = $(this).val() || null;
    const prevState = poState[permissionId] || { allow_scope: 'all', detail_level: 'full' };
    poState[permissionId] = { effect: effect, allow_scope: prevState.allow_scope || 'all', detail_level: prevState.detail_level || 'full' };
    const p = poRows.find(r => String(r.permission_id) === String(permissionId));
    const isApprovalAct = p && p.permission_key === 'approval_request.act';
    const isSalaryAmount = p && p.permission_key.indexOf('salary_amount.') === 0;
    const showScope = effect === 'grant' && (isApprovalAct || isSalaryAmount);
    $row.find('.po-scope-select').toggleClass('d-none', !showScope);
});
$(document).on('change', '.po-scope-select', function () {
    const $row = $(this).closest('tr');
    const permissionId = $row.data('permission-id');
    if (!poState[permissionId]) return;
    poState[permissionId].allow_scope = $(this).val();
});

function permissionOverridesHasUnsavedChanges() {
    return poBaselineSnapshot !== null && JSON.stringify(poState) !== poBaselineSnapshot;
}

$(document).on('click', '#btnSavePermissionOverrides', function () {
    if (!currentEmployeeId) return;
    const overrides = Object.keys(poState)
        .filter(permId => poState[permId].effect)
        .map(permId => ({
            permission_id: parseInt(permId, 10),
            effect: poState[permId].effect,
            allow_scope: poState[permId].allow_scope || 'all',
            detail_level: poState[permId].detail_level || 'full',
        }));
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/permission-employee-overrides.save`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ employee_id: currentEmployeeId, overrides: overrides }),
        dataType: 'json',
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                poBaselineSnapshot = JSON.stringify(poState);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['save_failed'] || 'An error occurred while saving.');
        }
    });
});

// Lazy-init on first tab show, same DataTables-inside-a-hidden-tab caution as every other lazy tab
// on this page even though this one isn't a DataTable -- no point fetching before the pane is
// visible. Re-fetches every time the tab is shown again (cheap, always-fresh, same precedent as
// initLoginHistoryTable()'s own ajax.reload() on a repeat visit) UNLESS there are unsaved changes,
// in which case switching away and back must not silently discard an in-progress edit.
$(document).on('shown.bs.tab', '#permission-overrides-tab', function () {
    if (permissionOverridesHasUnsavedChanges()) return;
    initPermissionOverridesTab();
});

function loadDocumentList() {
    if (!currentEmployeeId) return;
    $('#tableDocumentList tbody').empty();
    $.ajax({
        url: `${BASE_URL}/api/employee.document.list`,
        method: 'GET',
        data: { employee_id: currentEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (res.status && res.data) {
                res.data.forEach(function (doc) { addDocumentRow(doc); });
            }
        }
    });
}
function uploadDocumentFile(documentType, file) {
    if (!currentEmployeeId) {
        showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
        return;
    }
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (ALLOWED_DOC_EXTENSIONS.indexOf(ext) === -1) {
        showWarning(langData['unsupported_file_type'] || 'Unsupported file type.');
        return;
    }
    if (file.size > MAX_DOC_SIZE) {
        showWarning(langData['file_too_large'] || 'File size exceeds 10MB limit.');
        return;
    }
    const formData = new FormData();
    formData.append('employee_id', currentEmployeeId);
    formData.append('document_type', documentType);
    formData.append('file', file);
    $.ajax({
        url: `${BASE_URL}/api/employee.document.upload`,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                loadDocumentList();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
}
function initDocumentUpload() {
    Object.keys(DOCUMENT_INPUT_MAP).forEach(function (inputId) {
        const documentType = DOCUMENT_INPUT_MAP[inputId];
        $(`#${inputId}`).on('change', function (e) {
            const files = e.target.files;
            if (!files || !files.length) return;
            for (let i = 0; i < files.length; i++) {
                uploadDocumentFile(documentType, files[i]);
            }
            $(this).val('');
        });
    });
    $(document).on('click', '.btn-delete-document', function () {
        const $tr = $(this).closest('tr');
        const id = $tr.data('id');
        if (!id) return;
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/employee.document.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ employee_id: currentEmployeeId, id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        $tr.remove();
                    } else {
                        showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                    }
                },
                error: function () {
                    showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
                }
            });
        });
    });
}

// Card layout + has-children/has-parents gate (2026-08-19, explicit request) -- replaces the old
// inline-editable table rows. Each entity type's relationship dropdown gets its own option set
// (children: legitimate/adopted; parents: father/mother/spouse's father/spouse's mother) since the
// underlying DB column is a plain uncontrolled varchar shared by both tables, never read by any
// calc/report -- nothing schema-side forces one shared list.
// Dependents: count-driven inline cards, no modal (2026-08-20, replaces the old count+Add-button+
// modal flow -- explicit request: "ปรับเป็นใส่จำนวน แล้วแสดง Card ลูกให้กรอก Auto 1 คน 1 แถว ไม่ต้อง
// กดปุ่มเพิ่ม"). Parents already became its own fixed Father/Mother 2-slot form earlier (2026-08-19,
// see PARENT_SLOTS below) -- this file used to have a generic multi-entity CHILD_ENTITY_CONFIG
// abstraction shared by both, but 'dependent' was its only remaining member, so it's flattened away
// here. The DOM is the source of truth for dependents now (no parallel JS array to keep in sync):
// each rendered .dependent-card carries data-id when it's a real saved employee_dependent row, and
// has none when it's a fresh blank card not yet saved. Saving happens only via the Family tab's one
// Save button (saveFamilyTab()) -- every visible card's current values get (re)saved together with
// the rest of the tab; only deleting a card is an immediate, confirmed action (same convention as
// the Father/Mother trash-icon buttons).
const DEPENDENT_RELATIONSHIP_KEYS = ['relationship_child_legitimate', 'relationship_child_adopted'];
const DEPENDENT_RELATIONSHIP_VALUES = ['child_legitimate', 'child_adopted'];
// Cached from the last loadAllChildTables() call. 'parent' cached here too (by loadParentSlots()).
let childRealRows = { dependent: [], parent: [] };
// Fixed Father/Mother slots (2026-08-19, explicit request) -- still backed by employee_parents /
// EmployeeModel::saveChild('parent', ...), just with `relationship` fixed per slot instead of a
// dropdown choice, and the UI as 2 inline forms instead of an open-ended card list.
const PARENT_SLOTS = {
    father: {
        toggleId: '#useFatherToggle', fieldsId: '#fatherDetailFields', idInput: '#parent_father_id',
        nameInput: '#parent_father_name', idCardInput: '#parent_father_id_card_no',
        deleteBtnId: '#btnDeleteFather',
    },
    mother: {
        toggleId: '#useMotherToggle', fieldsId: '#motherDetailFields', idInput: '#parent_mother_id',
        nameInput: '#parent_mother_name', idCardInput: '#parent_mother_id_card_no',
        deleteBtnId: '#btnDeleteMother',
    }
};
// Only ever ADVANCES a slot to "Yes"+populated when real saved data is found -- never forcibly resets
// a slot to "No"/cleared, since loadAllChildTables() (which calls this) fires after ANY child save on
// the whole tab (e.g. saving Father also triggers this for Mother) -- clobbering an in-progress
// unsaved "Yes, still typing" state on the OTHER slot would be a real data-loss-feeling bug.
function renderParentSlots() {
    const rows = childRealRows.parent || [];
    Object.keys(PARENT_SLOTS).forEach(function (relationship) {
        const cfg = PARENT_SLOTS[relationship];
        const row = rows.find(r => r.relationship === relationship);
        if (!row) return;
        $(cfg.idInput).val(row.id);
        $(cfg.nameInput).val(row.name || '');
        $(cfg.idCardInput).val(row.id_card_no || '');
        $(`${cfg.toggleId} button`).removeClass('active').filter('[data-value="yes"]').addClass('active');
        $(cfg.fieldsId).removeClass('d-none');
        $(cfg.nameInput).addClass('required');
        $(cfg.deleteBtnId).removeClass('d-none');
    });
}
function loadParentSlots() {
    $.ajax({
        url: `${BASE_URL}/api/employee.parent.list`,
        method: 'GET',
        data: { employee_id: currentEmployeeId },
        dataType: 'json',
        success: function (res) {
            childRealRows.parent = (res.status && res.data) ? res.data : [];
            renderParentSlots();
        }
    });
}

// "Nicer card" (2026-08-20, explicit request) -- .card-surface + a small orange-gradient icon
// badge (same treatment as the profile avatar/page-header-card icon elsewhere on this page,
// reused instead of a one-off color) + fields laid out in a row instead of the old
// summary-text + Edit-icon-opens-a-modal card. One full-width row per dependent (explicit
// request: "1 คน 1 แถว"), not the old 3-per-row grid.
function dependentCardHtml(row) {
    row = row || {};
    const isStudying = row.studying == 1 || row.studying === true;
    return `
        <div class="dependent-card card-surface p-3 mb-3"${row.id ? ` data-id="${row.id}"` : ''}>
            <div class="d-flex align-items-start gap-3">
                <div class="dependent-card-icon"><i class="fa-solid fa-child-reaching"></i></div>
                <div class="flex-grow-1 row g-2">
                    <div class="col-sm-3">
                        <label class="form-label mb-1 small text-muted"><span data-i18n="name">Name</span> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm dependent-name required" value="${escapeHtml(row.name || '')}">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label mb-1 small text-muted" data-i18n="id_card_no">ID Card No.</label>
                        <input type="text" class="form-control form-control-sm dependent-id-card" maxlength="13" value="${escapeHtml(row.id_card_no || '')}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1 small text-muted" data-i18n="date_of_birth">Date of Birth</label>
                        <input type="text" class="form-control form-control-sm datepicker dependent-dob" autocomplete="off" value="${row.date_of_birth ? toDisplayDate(row.date_of_birth) : ''}">
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label mb-1 small text-muted"><span data-i18n="relationship">Relationship</span> <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm select2-static dependent-relationship required" data-option-keys="${DEPENDENT_RELATIONSHIP_KEYS.join(',')}" data-option-values="${DEPENDENT_RELATIONSHIP_VALUES.join(',')}"></select>
                    </div>
                    <div class="col-sm-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-1">
                            <input type="checkbox" class="form-check-input dependent-studying" role="switch"${isStudying ? ' checked' : ''}>
                            <label class="form-check-label small" data-i18n="studying">Studying</label>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-link text-danger btn-delete-dependent-card" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>
            </div>
        </div>`;
}
function initDependentCardWidgets($cards) {
    if (typeof initSelect2 === 'function') initSelect2($cards.find('.dependent-relationship'), { mode: 'static' });
    initDatepicker($cards.find('.datepicker'));
    if (typeof updateText === 'function') updateText($('#dependentCardsContainer')[0]);
}
// Initial render from whatever's actually saved (called on load / after any save or delete) --
// growing/shrinking the count afterwards is handled separately by syncDependentCardCount() so a
// reload never clobbers blank cards the user is still filling in.
function renderDependentCards() {
    const rows = childRealRows.dependent || [];
    const $container = $('#dependentCardsContainer');
    $container.html(rows.map(dependentCardHtml).join(''));
    $('#dependentCount').val(rows.length);
    $('#dependentEmptyHint').toggleClass('d-none', rows.length > 0);
    const $cards = $container.find('.dependent-card');
    initDependentCardWidgets($cards);
    $cards.each(function (idx) {
        $(this).find('.dependent-relationship').val(rows[idx] ? rows[idx].relationship : null).trigger('change');
    });
    // Auto-flip "Does this employee have children?" to Yes + reveal the section once any real
    // dependent exists -- an employee who already has dependents on file shouldn't load with the
    // section collapsed behind a "No" that doesn't match their actual data (unchanged from before).
    if (rows.length > 0) {
        $('#hasChildrenToggle button').removeClass('active').filter('[data-value="yes"]').addClass('active');
        $('#childrenSection').removeClass('d-none');
    }
}
function appendBlankDependentCards(count) {
    const $container = $('#dependentCardsContainer');
    let html = '';
    for (let i = 0; i < count; i++) html += dependentCardHtml({});
    $container.append(html);
    initDependentCardWidgets($container.find('.dependent-card').slice(-count));
    $('#dependentEmptyHint').addClass('d-none');
}
function dependentCardHasData($card) {
    if ($card.attr('data-id')) return true; // a real saved row is always "has data"
    return !!(
        $card.find('.dependent-name').val().trim() ||
        $card.find('.dependent-id-card').val().trim() ||
        $card.find('.dependent-dob').val().trim() ||
        $card.find('.dependent-relationship').val()
    );
}
// Shared by both the count-shrink path and the per-card trash-icon button. Deleting a real saved
// row (data-id present) fires /api/employee.dependent.delete immediately on confirm -- deletion
// stays an immediate, confirmed action (same convention as the Father/Mother trash-icon buttons),
// never deferred to the batched Family-tab Save. Cards with no data at all (never touched) are
// removed without asking; anything else prompts a SweetAlert2 confirm first (explicit request).
// 2026-09-03, Manual Entry / Platform UX review Phase 10 -- gained `alreadyConfirmed` so
// syncDependentCardCount()'s own count=0 confirm (see that function's own comment) can skip
// straight to doRemove() instead of stacking a 2nd confirm on top of the one it already showed.
function removeDependentCards($toRemove, onCancelled, alreadyConfirmed) {
    if ($toRemove.length === 0) return;
    const anyHasData = $toRemove.toArray().some(el => dependentCardHasData($(el)));
    const doRemove = function () {
        $toRemove.each(function () {
            const id = $(this).attr('data-id');
            if (!id) return;
            $.ajax({
                url: `${BASE_URL}/api/employee.dependent.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ employee_id: currentEmployeeId, id: id }),
                success: function (res) {
                    if (!res.status) showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                },
                error: function () { showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.'); }
            });
            childRealRows.dependent = (childRealRows.dependent || []).filter(r => String(r.id) !== String(id));
        });
        $toRemove.remove();
        $('#dependentCount').val($('#dependentCardsContainer .dependent-card').length);
        $('#dependentEmptyHint').toggleClass('d-none', $('#dependentCardsContainer .dependent-card').length > 0);
    };
    if (alreadyConfirmed) {
        doRemove();
    } else if (anyHasData) {
        showConfirm(
            langData['confirm_delete_title'] || 'Confirm Delete',
            langData['confirm_delete_message'] || 'Are you sure you want to delete this item?',
            doRemove,
            onCancelled
        );
    } else {
        doRemove();
    }
}
function syncDependentCardCount() {
    const target = Math.max(0, parseInt($('#dependentCount').val() || '0', 10));
    const $cards = $('#dependentCardsContainer .dependent-card');
    const current = $cards.length;
    if (target > current) {
        appendBlankDependentCards(target - current);
    } else if (target < current) {
        if (target === 0) {
            // 2026-09-03, Manual Entry / Platform UX review Phase 10, explicit request: always
            // confirm when the count is set down to exactly 0 -- distinct from
            // removeDependentCards()'s own generic "only confirm if there's real data to lose" rule
            // (still used below for a partial reduction, e.g. 3->2). Landing on 0 specifically means
            // "this employee now has NO dependents at all," a business fact worth a deliberate
            // confirm even when every existing card happens to be empty -- a stray Enter/backspace
            // shouldn't silently wipe the whole section. `alreadyConfirmed=true` skips
            // removeDependentCards()'s own confirm so there's exactly one dialog total either way.
            showConfirm(
                langData['confirm_zero_dependents_title'] || 'Set Dependents to 0?',
                langData['confirm_zero_dependents_message'] || 'This will remove all dependent records for this employee.',
                function () { removeDependentCards($cards, null, true); },
                function () { $('#dependentCount').val(current); }
            );
        } else {
            removeDependentCards($cards.slice(-(current - target)), function () {
                $('#dependentCount').val(current);
            });
        }
    }
}
function collectDependentCardData($card) {
    const data = {
        employee_id: currentEmployeeId,
        name: $card.find('.dependent-name').val().trim(),
        id_card_no: $card.find('.dependent-id-card').val().trim(),
        relationship: $card.find('.dependent-relationship').val(),
        date_of_birth: toIsoDate($card.find('.dependent-dob').val()),
        studying: $card.find('.dependent-studying').is(':checked'),
    };
    const id = $card.attr('data-id');
    if (id) data.id = id;
    return data;
}
function loadAllChildTables() {
    $.ajax({
        url: `${BASE_URL}/api/employee.dependent.list`,
        method: 'GET',
        data: { employee_id: currentEmployeeId },
        dataType: 'json',
        success: function (res) {
            childRealRows.dependent = (res.status && res.data) ? res.data : [];
            renderDependentCards();
        }
    });
    loadParentSlots();
    loadEarningDeductions();
    loadOtRateForEmployee(currentEmployeeId);
    // 2026-08-29, real bug found and fixed (explicit urgent report: a newly-added Recurring
    // Allowance would disappear again shortly after saving, and reliably came back empty on a fresh
    // page load even though the rows genuinely existed in the DB) -- tbRecurringEarning's own
    // DataTable (initRecurringEarningUI(), called at page load before this async employee-load
    // response ever comes back) fires an automatic FIRST ajax fetch with `employee_id` still null/
    // undefined at that point (currentEmployeeId is only set inside THIS success callback). That
    // stale, wrongly-parameterized request can resolve AFTER a later, correctly-parameterized
    // .ajax.reload() (e.g. right after adding an allowance), silently clobbering the correct data
    // with the stale request's empty result -- and on a fresh page load, nothing ever re-triggered a
    // corrective reload afterward at all, same as tbEarning/tbDeduction would have had this same bug
    // if loadEarningDeductions() above didn't already exist for exactly this reason. Adding the same
    // reload here closes the gap: one deliberate, correctly-parameterized reload once
    // currentEmployeeId is genuinely known, same pattern as every other child table on this page.
    if ($.fn.DataTable.isDataTable('#tableRecurringEarning')) $('#tableRecurringEarning').DataTable().ajax.reload(null, false);
    // Same race/fix as tableRecurringEarning immediately above, mirrored for Recurring Deductions.
    if ($.fn.DataTable.isDataTable('#tableRecurringDeduction')) $('#tableRecurringDeduction').DataTable().ajax.reload(null, false);
}
function initChildTables() {
    $('#hasChildrenToggle button').on('click', function () {
        const val = $(this).data('value');
        $('#hasChildrenToggle button').removeClass('active').filter(`[data-value="${val}"]`).addClass('active');
        // Just collapses the section from view -- never deletes existing dependent rows, which live
        // entirely in their own table and are untouched by this toggle either way.
        $('#childrenSection').toggleClass('d-none', val !== 'yes');
    });
    // Typing a number directly drives the card count (2026-08-20, explicit request: "ไม่ต้องกดปุ่ม
    // เพิ่ม") -- change fires on blur/spinner-click, not mid-keystroke, so a 2-digit count doesn't
    // flash through intermediate card counts while typing.
    $(document).on('change', '#dependentCount', function () {
        if (!currentEmployeeId) {
            showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
            $(this).val($('#dependentCardsContainer .dependent-card').length);
            return;
        }
        syncDependentCardCount();
    });
    // 2026-09-03, Manual Entry / Platform UX review Phase 10, explicit request: "reveal rows on
    // Enter" -- a plain number input's native 'change' event only fires on blur or a spinner-arrow
    // click, NOT on typing a value and pressing Enter while still focused (this field sits outside
    // any <form>, so Enter doesn't even trigger an implicit submit that might have blurred it) --
    // typing "3" then hitting Enter did nothing at all until the admin clicked elsewhere, which read
    // as broken/unresponsive. Explicitly blurs the field first so this always fires the SAME `change`
    // handler above (not a parallel code path), keeping exactly one place that owns "what happens
    // when the count is committed."
    $(document).on('keydown', '#dependentCount', function (e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            $(this).trigger('blur');
        }
    });
    $(document).on('click', '.btn-delete-dependent-card', function () {
        removeDependentCards($(this).closest('.dependent-card'));
    });
    // Father/Mother fixed slots (2026-08-19, explicit request; Save consolidated into the Family
    // tab's one button on 2026-08-20 -- see saveFamilyTab()) -- toggling "Yes" reveals the fields
    // and marks Name as .required (validateEmployeeForm() has no .d-none skip, so this needs to be
    // toggled explicitly, same pattern as SSO above); toggling "No" just hides them again, same
    // non-destructive-toggle convention as every other Yes/No question on this tab -- an already-
    // saved father/mother isn't deleted just by hiding the section, only the trash-icon button is.
    Object.keys(PARENT_SLOTS).forEach(function (relationship) {
        const cfg = PARENT_SLOTS[relationship];
        $(cfg.toggleId + ' button').on('click', function () {
            const val = $(this).data('value');
            const isYes = val === 'yes';
            $(cfg.toggleId + ' button').removeClass('active').filter(`[data-value="${val}"]`).addClass('active');
            $(cfg.fieldsId).toggleClass('d-none', !isYes);
            $(cfg.nameInput).toggleClass('required', isYes);
            if (!isYes) $(cfg.nameInput).removeClass('is-invalid');
        });
        $(cfg.deleteBtnId).on('click', function () {
            const id = $(cfg.idInput).val();
            if (!id) return;
            const title = langData['confirm_delete_title'] || 'Confirm Delete';
            const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
            showConfirm(title, message, function () {
                $.ajax({
                    url: `${BASE_URL}/api/employee.parent.delete`,
                    method: 'POST',
                    contentType: 'application/json',
                    dataType: 'json',
                    data: JSON.stringify({ employee_id: currentEmployeeId, id: id }),
                    success: function (res) {
                        if (res.status) {
                            showSuccess(langData['delete_success'] || 'Deleted successfully.');
                            $(cfg.idInput).val('');
                            $(cfg.nameInput).val('');
                            $(cfg.idCardInput).val('');
                            $(cfg.deleteBtnId).addClass('d-none');
                        } else {
                            showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                        }
                    },
                    error: function () {
                        showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
                    }
                });
            });
        });
    });
}
function eedStatusBadge(row) {
    const map = {
        active: { cls: 'bg-success-subtle text-success', key: 'active', fallback: 'Active' },
        paused: { cls: 'bg-warning-subtle text-warning', key: 'paused', fallback: 'Paused' },
        completed: { cls: 'bg-primary-subtle text-primary', key: 'completed', fallback: 'Completed' },
        cancelled: { cls: 'bg-secondary-subtle text-secondary', key: 'cancelled', fallback: 'Cancelled' }
    };
    const cfg = map[row.status] || map.active;
    return `<span class="badge ${cfg.cls}">${langData[cfg.key] || cfg.fallback}</span>`;
}
function eedActionButtons(row) {
    const notStarted = Number(row.current_installment) === 0;
    const isOpen = row.status === 'active' || row.status === 'paused';
    // 2026-09-02, explicit request: circular row-action buttons (see style.css's own
    // ".btn-circle-action" section) replace the old adjacent .btn-group/border-start convention
    // this section previously followed (2026-08-21).
    let html = '<div class="d-flex gap-1 justify-content-center">';
    // View is always available, Edit only while nothing has been paid yet (2026-08-20, explicit
    // request: "Status ของแต่ละงวดการจ่าย...จ่ายแล้วหรือรอจ่าย") -- once current_installment > 0
    // save() permanently blocks edits (see EmployeeEarningDeductionModel::save()), so this is the
    // only way to see the per-installment paid/pending schedule for an assignment already in
    // progress or finished. Same modal, populateEedForm(row, true) just disables everything.
    html += `<button type="button" class="btn btn-link btn-circle-action text-info btn-view-eed" data-id="${row.id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>`;
    if (isOpen && notStarted) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-warning btn-edit-eed" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen-to-square"></i></button>`;
    }
    if (isOpen) {
        if (row.status === 'active') {
            html += `<button type="button" class="btn btn-link btn-circle-action text-warning btn-eed-status" data-id="${row.id}" data-status="paused" title="${langData['pause_item'] || 'Pause'}"><i class="fa-solid fa-pause"></i></button>`;
        } else {
            html += `<button type="button" class="btn btn-link btn-circle-action text-success btn-eed-status" data-id="${row.id}" data-status="active" title="${langData['resume_item'] || 'Resume'}"><i class="fa-solid fa-play"></i></button>`;
        }
        html += `<button type="button" class="btn btn-link btn-circle-action text-danger btn-eed-status" data-id="${row.id}" data-status="cancelled" title="${langData['cancel_item'] || 'Cancel'}"><i class="fa-solid fa-ban"></i></button>`;
    }
    if (isOpen && notStarted) {
        html += `<button type="button" class="btn btn-link btn-circle-action text-danger btn-delete-eed" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
    }
    html += '</div>';
    return html;
}
function eedInterestSubLabel(row) {
    if (!row.interest_type || row.interest_type === 'none') return '';
    // 2026-08-31: 'fee' is a 3rd sibling of 'fixed'/'reducing_balance' -- own sub-label shape (a %
    // of a selectable base, not a per-installment rate) instead of the interest ones below.
    if (row.interest_type === 'fee') {
        const feeBaseLabel = row.fee_base === 'base_salary'
            ? (langData['fee_base_option_base_salary'] || 'Base Salary')
            : (langData['fee_base_option_principal'] || 'Principal Amount');
        const feePct = parseFloat(row.fee_percent || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return ` <span class="text-muted small">(${langData['fee_has'] || 'Fee'} ${feePct}% ${langData['fee_of'] || 'of'} ${feeBaseLabel})</span>`;
    }
    const typeLabel = row.interest_type === 'fixed'
        ? (langData['interest_fixed'] || 'Flat')
        : (langData['interest_reducing_balance'] || 'Reducing Balance');
    const rate = parseFloat(row.interest_rate || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return ` <span class="text-muted small">(${typeLabel} ${rate}%/${langData['installment_label'] || 'installment'})</span>`;
}
function eedAmountSummary(row) {
    const total = parseFloat(row.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const interestSub = eedInterestSubLabel(row);
    if (row.amount_mode === 'custom_per_installment') {
        return `${total} <span class="text-muted small">(${langData['custom_per_installment'] || 'custom'})</span>${interestSub}`;
    }
    const per = (parseFloat(row.total_amount || 0) / Math.max(1, parseInt(row.total_installments || 1, 10))).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${total} <span class="text-muted small">(${per} x ${row.total_installments})</span>${interestSub}`;
}
// Item-name cell no longer shows the earning/deduction word (2026-08-19: the two tables are split by
// type now, so it would just repeat the table's own heading on every row) -- shows the "Custom" badge
// instead when the row has no ped_type_id (custom item, see EmployeeEarningDeductionModel::save()).
function eedItemNameCell(row) {
    const label = escapeHtml((currentLang === 'th' ? row.item_name_th : row.item_name_en) || '');
    // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- distinct badge for an
    // "Other" bucket item (is_other=1) vs a genuinely one-off custom item, so it's visually obvious
    // this label is aggregated into the shared Other Income/Other Deduction report bucket, not its
    // own unique line.
    const badge = row.ped_type_id
        ? ''
        : (row.is_other
            ? ` <span class="badge bg-info-subtle text-info">${langData['manual_line_other_badge'] || 'Other'}</span>`
            : ` <span class="badge bg-secondary-subtle text-secondary">${langData['manual_line_custom_badge'] || 'Custom'}</span>`);
    const codeLine = row.item_code ? escapeHtml(row.item_code) : '';
    // 2026-08-31: payee_type widened beyond "always another employee" -- 'company' has no
    // payee_employee_id at all (see EmployeeEarningDeductionModel::save()'s own docblock), so this
    // now branches on payee_type first rather than assuming a non-null payee_employee_id.
    let payeeTag = '';
    if (row.payee_type === 'employee' && row.payee_employee_id) {
        payeeTag = `<div class="text-muted small"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtml(row.payee_employee_no || ('#' + row.payee_employee_id))}</div>`;
    } else if (row.payee_type === 'company') {
        payeeTag = `<div class="text-muted small"><i class="fa-solid fa-building me-1"></i>${langData['payee_type_company'] || 'Company Account'}</div>`;
    } else if (row.payee_type === 'other_person') {
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- real gap found
        // while adding 'other_person' to this modal: this cell already tagged 'employee'/'company'/
        // 'not_disbursed' but had no branch at all for 'other_person', so a deduction already routed
        // to a third party (possible via the backend since Phase 1/4, just never reachable through
        // THIS modal's own UI until this round) would have shown no payee tag whatsoever here.
        payeeTag = `<div class="text-muted small"><i class="fa-solid fa-building-columns me-1"></i>${escapeHtml(row.destination_account_name || (langData['payee_type_other_person'] || 'Other Person / Third Party'))}</div>`;
    } else if (row.payee_type === 'not_disbursed') {
        // 2026-08-31, same-day follow-up.
        payeeTag = `<div class="text-muted small"><i class="fa-solid fa-ban me-1"></i>${langData['payee_type_not_disbursed'] || 'Not Disbursed'}</div>`;
    }
    return `<div><strong>${label}</strong>${badge}</div><div class="text-muted small">${codeLine}</div>${payeeTag}`;
}
// Progress bar instead of plain "N/M" text (2026-08-20, table redesign request) -- reuses the same
// .progress/.progress-bar component already used for the profile completeness bar elsewhere on this
// page, brand-orange fill, so a repayment's progress reads at a glance.
function eedInstallmentProgressCell(row) {
    const total = Math.max(1, parseInt(row.total_installments || 1, 10));
    const current = Math.min(total, parseInt(row.current_installment || 0, 10));
    const pct = Math.round((current / total) * 100);
    return `
        <div class="d-flex flex-column align-items-center" style="min-width:90px;">
            <div class="progress w-100" style="height:6px;">
                <div class="progress-bar" role="progressbar" style="width:${pct}%;background-color:#FF9900;"></div>
            </div>
            <span class="text-muted small mt-1">${current}/${total}</span>
        </div>
    `;
}
function eedTableColumns() {
    return [
        { data: null, render: (d, t, row) => eedItemNameCell(row) },
        { data: null, className: 'text-end', render: (d, t, row) => eedAmountSummary(row) },
        { data: null, className: 'text-center', render: (d, t, row) => eedInstallmentProgressCell(row) },
        // 2026-08-29, real bug found via a system-wide table audit: sort-safety fix -- plain
        // `render: fn` meant client-side sort/filter operated on the dd/mm/yyyy DISPLAY string, not
        // the raw ISO date, same class of bug this project's own CLAUDE.md already documents for
        // every other formatted-date column.
        { data: 'effective_date', render: { display: d => toDisplayDate(d), sort: d => d || '', filter: d => d || '' } },
        { data: null, render: (d, t, row) => eedStatusBadge(row) },
        // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
        // Responsive expand row.
        { data: null, orderable: false, className: 'text-center all', render: (d, t, row) => eedActionButtons(row) }
    ];
}
let tbEarning, tbDeduction;
// Two client-side DataTables (2026-08-19, explicit request: "แยกเป็น 2 ตาราง" + "Format ของ
// Datatable") -- per-employee item count is always small, matching this project's client-side
// DataTable convention. Add button injected into .dt-search via initComplete, same as every other
// DataTable in this app. Filtered server-side by item_type (EmployeeEarningDeductionModel::list()).
function initEedTable(tableSelector, itemType, addBtnClass, addLangKey, addLangFallback) {
    return $(tableSelector).DataTable({
        responsive: true,
        // 2026-08-29: deferLoading:0 -- see tbRecurringEarning's own comment on this exact race
        // (loadAllChildTables()). This table happened not to get reported as broken (loadEarningDeductions()
        // already re-triggers a correct reload once currentEmployeeId is known), but the underlying
        // race -- this table's automatic FIRST ajax fetch firing before currentEmployeeId is set, then
        // possibly resolving AFTER that later correct reload and clobbering it with stale/empty data
        // -- is identical, so it gets the same real fix here rather than just relying on timing luck.
        deferLoading: 0,
        ajax: {
            url: `${BASE_URL}/api/employee.earning-deduction.list`,
            data: function (d) { d.employee_id = currentEmployeeId; d.item_type = itemType; },
            dataSrc: 'data'
        },
        columns: eedTableColumns(),
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find(`.${addBtnClass}`).length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary btn-sm ms-1 ${addBtnClass}">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="${addLangKey}">${langData[addLangKey] || addLangFallback}</span>
                    </button>
                `);
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the installment progress bar (2, no single filterable
            // value) and the actions column (5).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'item_name' },
                    { index: 1, key: 'amount' },
                    { index: 3, key: 'effective_date' },
                    { index: 4, key: 'status' },
                ]
            });
        }
    });
}
function loadEarningDeductions() {
    if ($.fn.DataTable.isDataTable('#tableEarning')) $('#tableEarning').DataTable().ajax.reload(null, false);
    if ($.fn.DataTable.isDataTable('#tableDeduction')) $('#tableDeduction').DataTable().ajax.reload(null, false);
    if ($.fn.DataTable.isDataTable('#tableSyncTransactionLog')) $('#tableSyncTransactionLog').DataTable().ajax.reload(null, false);
    if ($.fn.DataTable.isDataTable('#tableScheduledItemOccurrence')) $('#tableScheduledItemOccurrence').DataTable().ajax.reload(null, false);
}
// 2026-09-04, Backlog Phase 9->10, T051 -- 2 read-only client-side DataTables on the new "Sync
// History" sub-pill (no Add/Edit/Delete affordances at all -- pure reporting). Same
// deferLoading:0/currentEmployeeId-in-ajax-data pattern as initEedTable() above, and the same
// hidden-tab-at-init column-width gotcha applies (this sub-pill is never the active one on load).
function syncTxItemNameCell(row) {
    const name = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || row.item_name_th || row.item_name_en || row.item_code || '';
    return escapeHtml(name);
}
function syncTxFmtAmount(v) {
    return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function syncTxTypeBadge(itemType) {
    if (itemType === 'earning') {
        return `<span class="badge bg-success-subtle text-success" data-i18n="earning_singular">${langData['earning_singular'] || 'Income'}</span>`;
    }
    return `<span class="badge bg-danger-subtle text-danger" data-i18n="deduction_singular">${langData['deduction_singular'] || 'Deduction'}</span>`;
}
function initSyncTransactionLogTable() {
    return $('#tableSyncTransactionLog').DataTable({
        responsive: true,
        deferLoading: 0,
        ordering: false,
        ajax: {
            url: `${BASE_URL}/api/employee.sync-transaction-log.list`,
            data: function (d) { d.employee_id = currentEmployeeId; },
            dataSrc: 'data'
        },
        columns: [
            { data: null, render: (d, t, row) => `${toDisplayDate(row.pay_period_start)} - ${toDisplayDate(row.pay_period_end)}` },
            { data: null, render: (d, t, row) => syncTxItemNameCell(row) },
            { data: 'item_type', className: 'text-center', render: (d, t, row) => syncTxTypeBadge(row.item_type) },
            { data: null, className: 'text-end', render: (d, t, row) => syncTxFmtAmount(row.amount) },
            { data: 'remark', render: d => escapeHtml(d || '-') }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang()
    });
}
function initScheduledItemOccurrenceTable() {
    return $('#tableScheduledItemOccurrence').DataTable({
        responsive: true,
        deferLoading: 0,
        ordering: false,
        ajax: {
            url: `${BASE_URL}/api/employee.scheduled-item-occurrence.list`,
            data: function (d) { d.employee_id = currentEmployeeId; },
            dataSrc: 'data'
        },
        columns: [
            { data: null, render: (d, t, row) => toDisplayDate(row.applied_at) },
            { data: null, render: (d, t, row) => escapeHtml(row.item_ref_code || row.item_code || '-') },
            { data: 'installment_no', className: 'text-center', render: d => d !== null && d !== undefined ? d : '-' },
            { data: null, className: 'text-end', render: (d, t, row) => syncTxFmtAmount(row.amount) },
            { data: null, render: (d, t, row) => row.run_name ? escapeHtml(row.run_name) : (row.process_no ? escapeHtml(row.process_no) : '-') }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang()
    });
}
// Per-installment status badge (2026-08-20, explicit request: "Status ของแต่ละงวดการจ่าย...จ่ายแล้ว
// หรือรอจ่าย") -- statuses come straight from employee_earning_deduction_installments.status, set by
// PayrollRunModel::markPaid() when a run is actually paid (never by anything in this file).
function eedInstallmentStatusBadge(status, processedAt) {
    const map = {
        pending: { cls: 'bg-secondary-subtle text-secondary', key: 'installment_status_pending', fallback: 'Pending' },
        processed: { cls: 'bg-success-subtle text-success', key: 'installment_status_processed', fallback: 'Paid' },
        skipped: { cls: 'bg-warning-subtle text-warning', key: 'installment_status_skipped', fallback: 'Skipped' }
    };
    const cfg = map[status] || map.pending;
    // 2026-08-29, real bug found and fixed (explicit report: "เวลาที่ Save ลงใน Database เป็น UTC การ
    // แสดงผลให้แปลงเป็น timezone ปัจจุบันของผู้ใช้") -- processedAt is a real UTC timestamp
    // (employee_earning_deduction_installments.processed_at), but this only ever shows its DATE,
    // truncated from the raw string BEFORE any timezone conversion -- can show the wrong calendar
    // day for a viewer far from UTC (e.g. a payment processed at 23:xx UTC is already the next day
    // in Bangkok). Fixed by converting first (formatDisplayDateTime(), same UTC-aware technique as
    // app.js's own reference fix) and keeping only its date portion.
    const processedDateOnly = processedAt
        ? (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(processedAt).split(' ')[0] : toDisplayDate(String(processedAt).substring(0, 10)))
        : '';
    const dateSuffix = (status === 'processed' && processedAt) ? ` <span class="text-muted small">${processedDateOnly}</span>` : '';
    return `<span class="badge ${cfg.cls}">${langData[cfg.key] || cfg.fallback}</span>${dateSuffix}`;
}
// Always-visible, always-editable installment schedule table (2026-08-20, replaces the old
// even_split/custom_per_installment radio pair + regenerateCustomAmountInputs()) -- $amounts pre-
// fills every row (auto-computed via the preview endpoint, or the assignment's already-saved amounts
// on Edit/View), and every cell stays a plain editable <input> unless $readOnly. $installmentsData
// (the real employee_earning_deduction_installments rows, only present on Edit/View) additionally
// overlays a Status column; a brand-new Add has no installments yet so that column is hidden.
function renderInstallmentTable(amounts, installmentsData, readOnly) {
    const $body = $('#eedInstallmentTableBody');
    $body.empty();
    const hasStatus = Array.isArray(installmentsData) && installmentsData.length > 0;
    $('#eedInstallmentStatusHeader').toggleClass('d-none', !hasStatus);
    (amounts || []).forEach(function (amount, idx) {
        const inst = hasStatus ? installmentsData[idx] : null;
        const statusCell = hasStatus ? `<td>${eedInstallmentStatusBadge(inst ? inst.status : 'pending', inst ? inst.processed_at : null)}</td>` : '';
        $body.append(`
            <tr>
                <td class="text-muted">${idx + 1}</td>
                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm eed-installment-amount required" value="${amount !== '' && amount !== undefined ? amount : ''}"${readOnly ? ' disabled' : ''}></td>
                ${statusCell}
            </tr>
        `);
    });
    updateEedAmountBreakdown(amounts);
}
// 2026-09-03, Platform UX review Phase 4 -- see modals.php's own comment on #eedAmountBreakdownRow
// for the full rationale. Sums the SAME amounts renderInstallmentTable() just rendered (the real
// preview/saved total, never a second copy of the interest/fee formula) and shows it against the
// entered principal so "what does this number actually mean" is answered in plain numbers instead
// of a swapping label alone. Hidden for chargeType='none' (principal IS the total then).
function updateEedAmountBreakdown(amounts) {
    const { chargeType } = eedInterestState();
    const $row = $('#eedAmountBreakdownRow');
    if (chargeType === 'none') { $row.addClass('d-none'); return; }
    const principal = parseFloat($('#eed_principal_amount').val() || '0');
    const validAmounts = (amounts || []).map(a => parseFloat(a)).filter(n => !isNaN(n));
    if (!(principal > 0) || validAmounts.length === 0 || validAmounts.length !== (amounts || []).length) {
        $row.addClass('d-none');
        return;
    }
    const total = validAmounts.reduce((sum, n) => sum + n, 0);
    const added = total - principal;
    const fmt = n => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const template = langData['amount_breakdown_hint'] || 'Principal {principal} + {addedLabel} {added} = total deducted {total}, across {n} installment(s).';
    const addedLabelKey = chargeType === 'fee' ? 'fee_percent_label' : 'amount_breakdown_interest_noun';
    const text = template
        .replace('{principal}', fmt(principal))
        .replace('{addedLabel}', (langData[addedLabelKey] || (chargeType === 'fee' ? 'fee' : 'interest')).toLowerCase())
        .replace('{added}', fmt(added))
        .replace('{total}', fmt(total))
        .replace('{n}', String(validAmounts.length));
    $('#eedAmountBreakdownText').text(text);
    $row.removeClass('d-none');
}
let eedReadOnly = false;
let eedPreviewTimer = null;
// 2026-08-31, widened from the old 2-state hasInterest/interestType shape to a 3-state
// chargeType ('none'/'interest'/'fee') -- interestType/interestRate stay meaningful only while
// chargeType='interest' (unchanged fixed/reducing_balance sub-toggle), feePercent/feeBase only
// while chargeType='fee'. `interest_type` on the wire is still the SAME single column server-side
// ('none'/'fixed'/'reducing_balance'/'fee' -- see EmployeeEarningDeductionModel's own docblock for
// why 'fee' was added as a sibling value there rather than a parallel column).
function eedInterestState() {
    const chargeType = $('#eedInterestToggle button.active').data('value') || 'none';
    return {
        chargeType: chargeType,
        interestType: chargeType === 'interest' ? ($('#eedInterestTypeToggle button.active').data('value') || 'fixed') : (chargeType === 'fee' ? 'fee' : 'none'),
        interestRate: chargeType === 'interest' ? parseFloat($('#eed_interest_rate').val() || '0') : null,
        feePercent: chargeType === 'fee' ? parseFloat($('#eed_fee_percent').val() || '0') : null,
        feeBase: chargeType === 'fee' ? ($('#eed_fee_base').val() || null) : null
    };
}
// Debounced (2026-08-20) so the preview endpoint isn't hit on every single keystroke while typing
// the principal/rate -- fires ~400ms after the last change to principal/installments/interest
// toggle/type/rate. Never runs in read-only view mode: an in-progress/finished assignment's table
// always shows exactly what was actually saved, never a fresh recompute.
function scheduleEedPreviewFetch() {
    if (eedReadOnly) return;
    clearTimeout(eedPreviewTimer);
    eedPreviewTimer = setTimeout(fetchEedInstallmentPreview, 400);
}
function fetchEedInstallmentPreview() {
    const principal = parseFloat($('#eed_principal_amount').val() || '0');
    const totalInstallments = Math.max(1, parseInt($('#eed_total_installments').val() || '1', 10));
    if (!(principal > 0)) {
        renderInstallmentTable(new Array(totalInstallments).fill(''), null, false);
        return;
    }
    const { interestType, interestRate, feePercent, feeBase } = eedInterestState();
    if (interestType === 'fixed' || interestType === 'reducing_balance') {
        if (!(interestRate > 0)) return; // wait for a valid rate rather than previewing a misleading split
    } else if (interestType === 'fee') {
        if (!(feePercent > 0) || !feeBase) return; // wait for valid fee inputs, same reasoning
    }
    // fee_base='base_salary' needs a real number to compute against -- the Salary tab's own
    // #base_salary_amount input already holds the plaintext value on this same page (see
    // EmployeeController::earningDeductionPreviewInstallments()'s own comment on why this is passed
    // straight through rather than re-fetched/decrypted server-side).
    const baseSalaryForFee = feeBase === 'base_salary' ? parseFloat($('#base_salary_amount').val() || '0') : undefined;
    $.ajax({
        url: `${BASE_URL}/api/employee.earning-deduction.preview-installments`,
        method: 'GET',
        data: {
            principal: principal, total_installments: totalInstallments, interest_type: interestType, interest_rate: interestRate,
            fee_percent: feePercent, fee_base: feeBase, base_salary_for_fee: baseSalaryForFee
        },
        dataType: 'json',
        success: function (res) {
            if (res.status && res.data && res.data.amounts) {
                renderInstallmentTable(res.data.amounts, null, false);
            }
        }
    });
}
// 2026-08-31, replaces the old boolean setEedInterestOn(on) -- 3-state now (none/interest/fee).
function setEedChargeType(type) {
    if (type !== 'interest' && type !== 'fee') type = 'none';
    $('#eedInterestToggle button').removeClass('active').filter(`[data-value="${type}"]`).addClass('active');
    $('#eedInterestDetailWrapper').toggleClass('d-none', type !== 'interest');
    $('#eed_interest_rate').toggleClass('required', type === 'interest');
    $('#eedFeeDetailWrapper').toggleClass('d-none', type !== 'fee');
    $('#eed_fee_percent, #eed_fee_base').toggleClass('required', type === 'fee');
    // Instant hide on 'none' (principal IS the total then, nothing to clarify) -- the debounced
    // preview fetch below still handles showing/refreshing it correctly once interest/fee is active.
    if (type === 'none') { $('#eedAmountBreakdownRow').addClass('d-none'); }
    const chargeOn = type !== 'none';
    $('#eed_principal_amount_label [data-i18n="total_amount"]').toggleClass('d-none', chargeOn);
    $('#eed_principal_amount_label [data-i18n="principal_amount_label"]').toggleClass('d-none', !chargeOn);
    scheduleEedPreviewFetch();
}
function setEedInterestType(type) {
    $('#eedInterestTypeToggle button').removeClass('active').filter(`[data-value="${type}"]`).addClass('active');
    scheduleEedPreviewFetch();
}
// 2026-08-21, explicit request ("รายรับให้ตัดเรื่องดอกเบี้ยไปเลย มีแค่รายหักที่บอกว่าคิดหรือไม่คิด
// ดอกเบี้ย") -- interest only ever makes sense for a deduction (a loan/salary deduction), never an
// earning, so the whole section is hidden for earnings. #eed_custom_item_type is the single source
// of truth for "what type is this modal session" regardless of catalog/custom mode (see the markup
// comment above #eedCustomFields) -- kept in sync by resetEedForm()/populateEedForm(), read fresh
// here rather than re-derived per call site.
function applyEedInterestVisibility() {
    const isDeduction = $('#eed_custom_item_type').val() === 'deduction';
    $('#eedInterestSection').toggleClass('d-none', !isDeduction);
    if (!isDeduction) {
        setEedChargeType('none');
    }
    // Transfer-to-payee (2026-08-21) piggybacks on the same deduction-only toggle point as interest
    // above, rather than a parallel visibility mechanism -- both only make sense on a deduction.
    $('#eedPayeeWrapper').toggleClass('d-none', !isDeduction);
    if (!isDeduction) {
        setEedPayeeType('none');
    }
}
// 2026-08-31, explicit request: "หักไปจ่ายใคร หรือจ่ายเข้าบัญชีบริษัท ให้ติ๊กเพิ่มได้ว่า รวมไปใน cashlink
// หรือแยก cash link" -- single source of truth for the payee-type toggle's own dependent field
// visibility (employee picker only for 'employee', the cash-summary checkbox for either non-'none'
// choice), mirroring setEedChargeType()'s own toggle-button + dependent-fields pattern.
function setEedPayeeType(type) {
    $('#eedPayeeTypeToggle button').removeClass('active').filter(`[data-payee-type="${type}"]`).addClass('active');
    $('#eedPayeeEmployeeWrapper').toggleClass('d-none', type !== 'employee');
    $('#eed_payee_employee_id').toggleClass('required', type === 'employee');
    if (type !== 'employee') {
        $('#eed_payee_employee_id').val(null).trigger('change');
    }
    // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- same destination
    // sub-form pattern as #erdDestinationWrapper (Phase 6)/#manualLineDestinationWrapper (Phase 2).
    $('#eedDestinationWrapper').toggleClass('d-none', type !== 'other_person');
    if (type !== 'other_person') {
        $('#eed_destination_select').val(null).trigger('change');
        $('#eed_dest_account_name, #eed_dest_account_no, #eed_dest_bank_branch').val('');
        $('#eed_dest_bank').val(null).trigger('change');
        $('#eed_dest_save_for_reuse').prop('checked', false);
        $('#eedDestinationNewFields').removeClass('d-none');
    } else {
        // Manual Entry / Platform UX review Phase 7 -- see applyFirstSavedDestinationDefault()'s
        // own docblock in app.js. No-op if populateEedForm() is about to (or just did) set a real
        // saved destination for an existing record -- that guard lives inside the helper itself.
        applyFirstSavedDestinationDefault('#eed_destination_select', '#eedDestinationNewFields');
    }
    // 2026-08-31, same-day follow-up: 'not_disbursed' never shows this checkbox -- forced excluded
    // at the model layer (EmployeeEarningDeductionModel::save()'s own comment), a toggle here would
    // be misleading since unchecking/checking it would have no actual effect.
    $('#eedIncludeCashSummaryWrapper').toggleClass('d-none', type === 'none' || type === 'not_disbursed');
}
// Catalog vs custom item toggle (2026-08-19, explicit request). #eed_ped_type_id stays required only
// in catalog mode, the custom pair only in custom mode -- validateEedForm() already skips anything
// inside a .d-none ancestor, so toggling both visibility and .required here is enough, no change
// needed there.
// 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6 -- same toggle-button +
// dependent-fields pattern as setEedPayeeType() above, plus the 'other_person' destination
// sub-form (mirrors payroll/detail.js's own #manualLineDestinationWrapper handling for the
// Process Detail manual-line flow -- see that file's own comment for the full reasoning, not
// repeated here). This is the TEMPLATE-level payee/destination (employee_recurring_deductions);
// a specific payroll run can still override it for itself only, via that run's own Manage Items
// modal -- never written back to this form.
function setErdPayeeType(type) {
    $('#erdPayeeTypeToggle button').removeClass('active').filter(`[data-payee-type="${type}"]`).addClass('active');
    $('#erdPayeeEmployeeWrapper').toggleClass('d-none', type !== 'employee');
    $('#erd_payee_employee_id').toggleClass('required', type === 'employee');
    if (type !== 'employee') {
        $('#erd_payee_employee_id').val(null).trigger('change');
    }
    $('#erdDestinationWrapper').toggleClass('d-none', type !== 'other_person');
    if (type !== 'other_person') {
        $('#erd_destination_select').val(null).trigger('change');
        $('#erd_dest_account_name, #erd_dest_account_no, #erd_dest_bank_branch').val('');
        $('#erd_dest_bank').val(null).trigger('change');
        $('#erd_dest_save_for_reuse').prop('checked', false);
        $('#erdDestinationNewFields').removeClass('d-none');
    } else {
        // Manual Entry / Platform UX review Phase 7 -- see setEedPayeeType()'s own comment above /
        // applyFirstSavedDestinationDefault()'s own docblock in app.js.
        applyFirstSavedDestinationDefault('#erd_destination_select', '#erdDestinationNewFields');
    }
}
// 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- a 3rd mode, "Other"
// ('other'), reuses #eedCustomFields' own free-text #eed_custom_item_name input VERBATIM (no new
// DOM) -- the only difference from plain "Custom Item" is that submit() below additionally sends
// is_other=true, which PayrollRunModel::resolveManualLineRow() uses to derive a FIXED
// OTHER_INCOME/OTHER_DEDUCTION aggregation code instead of a per-name CUSTOM: one (see that
// method's own docblock) -- the employee-typed label itself ("ค่าปรับผิดสัญญาจ้าง" etc.) is
// unchanged and still shown as-is everywhere a custom item's name already shows.
function setEedMode(mode) {
    $('#eedModeToggle button').removeClass('active').filter(`[data-mode="${mode}"]`).addClass('active');
    $('#eedCatalogFields').toggleClass('d-none', mode !== 'catalog');
    $('#eedCustomFields').toggleClass('d-none', mode === 'catalog');
    $('#eed_ped_type_id').toggleClass('required', mode === 'catalog');
    $('#eed_custom_item_name').toggleClass('required', mode !== 'catalog');
}
// Toggles the whole modal between editable (Add / not-yet-started Edit) and read-only (View, for an
// assignment that already has paid/skipped installments -- save() permanently blocks editing those,
// see eedActionButtons()) -- one code path for both instead of a separate view-only modal.
function setEedReadOnly(readOnly) {
    eedReadOnly = readOnly;
    // #eedModal select already covers #eed_fee_base (a plain <select>, select2-static-initialized) --
    // no separate handling needed, same as every other select2 field in this modal.
    $('#eedModal .required, #eedModal select, #eedModal input, #eedModal textarea, #eedModeToggle button, #eedInterestToggle button, #eedInterestTypeToggle button')
        .prop('disabled', readOnly);
    $('#eedSaveBtn').toggleClass('d-none', readOnly);
}
// $context: 'earning'/'deduction' when opened from that table's own Add button (2026-08-19, explicit
// request) -- pre-filters the catalog dropdown to that item_type (via #eed_ped_type_id's data-type,
// read fresh on every select2 ajax search) and pre-selects the same type in custom mode, so which
// table the new item will show up in is obvious from which Add button was clicked, not a separate
// choice buried inside the modal.
// 2026-08-21, explicit request ("ใน modal header ก็ด้วยครับ" -- the header should say which type
// too, not just the catalog dropdown filtering to it). #eed_custom_item_type is already the single
// source of truth for "what type is this modal session" (see the markup comment above
// #eedCustomFields), so reuse it here instead of tracking type separately for the title.
// add_earning_item/add_deduction_item already existed (used for the two tables' own Add-button
// labels) and read exactly right as a modal title too, reused verbatim rather than adding
// duplicate keys; edit_*/view_* are new.
function eedModalTitle(action) {
    const type = $('#eed_custom_item_type').val() === 'deduction' ? 'deduction' : 'earning';
    const keys = {
        add: { earning: ['add_earning_item', 'Earning'], deduction: ['add_deduction_item', 'Deduction'] },
        edit: { earning: ['edit_earning_item', 'Edit Earning'], deduction: ['edit_deduction_item', 'Edit Deduction'] },
        view: { earning: ['view_earning_item', 'View Earning'], deduction: ['view_deduction_item', 'View Deduction'] }
    };
    const [key, fallback] = keys[action][type];
    return langData[key] || fallback;
}
// 2026-08-30 (T007), real bug found and fixed (modal title audit) -- unlike the recurring-earning
// titles above, this one can't just carry a static `data-i18n` (its result depends on BOTH which
// action opened it AND #eed_custom_item_type's current value, so a plain key lookup at
// updateText()-sweep time couldn't reproduce it). Remembers the last action this modal was opened
// with so a language change can re-derive the same title fresh, without needing to re-open the
// modal.
let eedLastModalAction = null;
function setEedModalTitle(action) {
    eedLastModalAction = action;
    $('#eedModalLabel').text(eedModalTitle(action));
}
function refreshEedModalTitleLanguage() {
    if (eedLastModalAction && $('#eedModal').hasClass('show')) {
        $('#eedModalLabel').text(eedModalTitle(eedLastModalAction));
    }
}
function resetEedForm(context) {
    $('#eedForm')[0].reset();
    $('#eed_id').val('');
    setEedMode('catalog');
    setEedReadOnly(false);
    $('#eed_ped_type_id').attr('data-type', context || '').val(null).trigger('change');
    // #eed_custom_item_type is the fixed session type regardless of catalog/custom mode (2026-08-21
    // -- see the markup comment above #eedCustomFields); plain hidden field now, no select2 left on
    // it to notify.
    $('#eed_custom_item_type').val(context || 'earning');
    $('.is-invalid').removeClass('is-invalid');
    setEedChargeType('none');
    setEedInterestType('fixed');
    $('#eed_interest_rate').val('');
    $('#eed_fee_percent').val('');
    $('#eed_fee_base').val(null).trigger('change');
    // 2026-09-03, Platform UX review Phase 4 -- fresh Add shouldn't carry over a previous session's
    // annual-rate helper inputs.
    $('#eedRateHelperAnnual').val('');
    $('#eedRateHelperResult').text('');
    $('#eedRateHelperBody').addClass('d-none');
    // An employee can't be their own transfer payee -- excluded from the picker's own results the
    // same way #report_to_id already excludes self elsewhere (data-exclude-id, read fresh on every
    // ajax search by initSelect2's shared 'ajax' mode).
    $('#eed_payee_employee_id').attr('data-exclude-id', currentEmployeeId || '').val(null).trigger('change');
    $('#eed_include_in_cash_summary').prop('checked', true);
    setEedPayeeType('none');
    applyEedInterestVisibility();
    renderInstallmentTable([], null, false);
    setEedModalTitle('add');
}
function populateEedForm(row, readOnly) {
    $('#eed_id').val(row.id);
    // #eed_custom_item_type carries the fixed session type regardless of catalog/custom mode
    // (2026-08-21 -- see the markup comment above #eedCustomFields), so set it from the row here
    // either way, not just in the custom branch.
    $('#eed_custom_item_type').val(row.item_type);
    if (row.ped_type_id) {
        setEedMode('catalog');
        const label = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || '';
        const opt = new Option(`[${row.item_code}] ${label}`, row.ped_type_id, true, true);
        $('#eed_ped_type_id').empty().append(opt).trigger('change');
    } else {
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- row.is_other picks
        // which mode button re-activates; the free-text field itself is populated identically
        // either way (see setEedMode()'s own docblock -- "Other" reuses #eedCustomFields verbatim).
        setEedMode(row.is_other ? 'other' : 'custom');
        $('#eed_custom_item_name').val(row.item_name_th || row.item_name_en || '');
    }
    // Same datepicker-state-desync bug/fix as populateEmployeeForm() above -- this modal's date
    // field is initialized once (empty) on page load, so a plain .val() here would leave the
    // widget's internal `dates` empty until re-synced.
    $('#eed_effective_date').val(toDisplayDate(row.effective_date));
    $('#eed_effective_date').datepicker('update');
    $('#eed_total_installments').val(row.total_installments);
    $('#eed_principal_amount').val(row.principal_amount != null ? row.principal_amount : row.total_amount);
    $('#eed_notes').val(row.notes || '');
    $('#eed_external_reference_no').val(row.external_reference_no || '');
    if (row.payee_type === 'employee' && row.payee_employee_id) {
        const payeeLabel = row.payee_employee_no || `#${row.payee_employee_id}`;
        $('#eed_payee_employee_id').empty().append(new Option(payeeLabel, row.payee_employee_id, true, true)).trigger('change');
        setEedPayeeType('employee');
    } else if (row.payee_type === 'company') {
        setEedPayeeType('company');
    } else if (row.payee_type === 'other_person' && row.destination_id) {
        setEedPayeeType('other_person');
        const destOpt = new Option(row.destination_account_name || '', row.destination_id, true, true);
        $('#eed_destination_select').empty().append(destOpt).trigger('change');
        $('#eedDestinationNewFields').addClass('d-none');
    } else if (row.payee_type === 'not_disbursed') {
        // 2026-08-31, same-day follow-up -- without this branch an existing not_disbursed row
        // would silently fall into the 'else' below and reset to 'none' every time it's reopened.
        $('#eed_payee_employee_id').val(null).trigger('change');
        setEedPayeeType('not_disbursed');
    } else {
        $('#eed_payee_employee_id').val(null).trigger('change');
        setEedPayeeType('none');
    }
    $('#eed_include_in_cash_summary').prop('checked', row.include_in_cash_summary === undefined ? true : !!row.include_in_cash_summary);
    // 2026-08-31: row.interest_type is now one of 4 values ('none'/'fixed'/'reducing_balance'/'fee')
    // -- 'fixed'/'reducing_balance' both mean chargeType='interest' (their own sub-toggle), 'fee'
    // means chargeType='fee', anything else means 'none'.
    const isFeeCharge = row.interest_type === 'fee';
    const isInterestCharge = row.interest_type === 'fixed' || row.interest_type === 'reducing_balance';
    setEedChargeType(isFeeCharge ? 'fee' : (isInterestCharge ? 'interest' : 'none'));
    setEedInterestType(isInterestCharge ? row.interest_type : 'fixed');
    $('#eed_interest_rate').val(row.interest_rate || '');
    $('#eed_fee_percent').val(row.fee_percent || '');
    if (row.fee_base) {
        $('#eed_fee_base').val(row.fee_base).trigger('change');
    } else {
        $('#eed_fee_base').val(null).trigger('change');
    }
    applyEedInterestVisibility();
    const amounts = (row.installments || []).map(i => i.amount);
    renderInstallmentTable(amounts, row.installments || [], !!readOnly);
    setEedReadOnly(!!readOnly);
    setEedModalTitle(readOnly ? 'view' : 'edit');
}
function validateEedForm() {
    let firstInvalid = null;
    $('#eedModal .required').each(function () {
        const $el = $(this);
        if ($el.closest('.d-none').length > 0) return;
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    return firstInvalid;
}
function collectEedFormData() {
    const mode = $('#eedModeToggle button.active').data('mode') || 'catalog';
    // amount_mode is always 'custom_per_installment' now (2026-08-20) -- the modal always shows
    // the editable per-installment table (auto-filled by the preview endpoint, hand-editable
    // after), whether interest is on or not, so save()'s existing custom_per_installment path
    // (take these exact amounts verbatim) is always the right one. 'even_split' stays supported
    // server-side for any other caller, just no longer sent from this modal.
    const { chargeType, interestType, interestRate, feePercent, feeBase } = eedInterestState();
    const data = {
        id: $('#eed_id').val() || undefined,
        employee_id: currentEmployeeId,
        effective_date: toIsoDate($('#eed_effective_date').val()),
        total_installments: $('#eed_total_installments').val(),
        amount_mode: 'custom_per_installment',
        installment_amounts: $('.eed-installment-amount').map(function () { return $(this).val(); }).get(),
        principal_amount: $('#eed_principal_amount').val(),
        interest_type: interestType,
        notes: $('#eed_notes').val().trim(),
        external_reference_no: $('#eed_external_reference_no').val().trim(),
        payee_type: $('#eedPayeeTypeToggle button.active').data('payee-type') || 'none',
        payee_employee_id: $('#eed_payee_employee_id').val() || undefined,
        include_in_cash_summary: $('#eed_include_in_cash_summary').is(':checked')
    };
    if (data.payee_type === 'none') {
        delete data.payee_type;
    }
    if (chargeType === 'interest') {
        data.interest_rate = interestRate;
    } else if (chargeType === 'fee') {
        data.fee_percent = feePercent;
        data.fee_base = feeBase;
    }
    if (mode === 'custom' || mode === 'other') {
        data.custom_item_name = $('#eed_custom_item_name').val().trim();
        data.custom_item_type = $('#eed_custom_item_type').val();
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- see setEedMode()'s
        // own docblock: "Other" reuses the exact same custom-item fields, this flag is the only
        // difference sent to the backend.
        if (mode === 'other') {
            data.is_other = true;
        }
    } else {
        data.ped_type_id = $('#eed_ped_type_id').val();
    }
    if (data.payee_type === 'other_person') {
        const savedDestinationId = $('#eed_destination_select').val();
        if (savedDestinationId) {
            data.destination_id = savedDestinationId;
        } else {
            data.account_name = $('#eed_dest_account_name').val().trim();
            data.account_no = $('#eed_dest_account_no').val().trim();
            data.bank_id = $('#eed_dest_bank').val();
            data.bank_branch = $('#eed_dest_bank_branch').val().trim() || undefined;
            data.is_saved = $('#eed_dest_save_for_reuse').is(':checked');
        }
    }
    return data;
}
let tbSyncTransactionLog, tbScheduledItemOccurrence;
function initEedUI() {
    tbEarning = initEedTable('#tableEarning', 'earning', 'btn-add-earning', 'add_earning_item', 'Earning');
    tbDeduction = initEedTable('#tableDeduction', 'deduction', 'btn-add-deduction', 'add_deduction_item', 'Deduction');
    // 2026-09-04, T051 -- same deferLoading:0 pattern, read-only, no Add button.
    tbSyncTransactionLog = initSyncTransactionLogTable();
    tbScheduledItemOccurrence = initScheduledItemOccurrenceTable();
    // Hidden-tab-at-init width gotcha: none of these are the active tab/sub-tab on page load, so
    // every table above computes its column widths against a zero-width container -- readjust once
    // actually visible (cheap/idempotent, DataTables no-ops if nothing changed). Multiple triggers
    // needed since #tableDeduction/the Sync History tables each sit inside their own nested pill
    // sub-tab (2026-08-19: Earning/Deduction split out into their own tab with sub-tabs) --
    // becoming visible requires BOTH the outer tab AND the relevant inner pill to have been shown.
    document.getElementById('earningDeduction-tab').addEventListener('shown.bs.tab', function () {
        if (tbEarning) tbEarning.columns.adjust();
        if (tbDeduction) tbDeduction.columns.adjust();
        if (tbSyncTransactionLog) tbSyncTransactionLog.columns.adjust();
        if (tbScheduledItemOccurrence) tbScheduledItemOccurrence.columns.adjust();
    });
    document.getElementById('eedDeductionSub-tab').addEventListener('shown.bs.tab', function () {
        if (tbDeduction) tbDeduction.columns.adjust();
    });
    document.getElementById('eedSyncHistorySub-tab').addEventListener('shown.bs.tab', function () {
        if (tbSyncTransactionLog) tbSyncTransactionLog.columns.adjust();
        if (tbScheduledItemOccurrence) tbScheduledItemOccurrence.columns.adjust();
    });
    if (typeof initSelect2 === 'function') {
        initSelect2('#eed_ped_type_id', { mode: 'ajax' });
        // Initialized once here, not per-modal-open (2026-08-21 bug fix precedent from the
        // Attendance Deduction rate_unit dropdown: re-initializing a select2 field every time a
        // modal opens can leave stale state/duplicate options behind -- matches #eed_ped_type_id's
        // own established once-at-page-load pattern directly above).
        initSelect2('#eed_payee_employee_id', { mode: 'ajax', allowClear: true });
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7.
        initSelect2('#eed_destination_select', { mode: 'ajax', allowClear: true });
        // 2026-09-03, Platform UX review Phase 4.
        initSelect2('#eedRateHelperFrequency', { mode: 'static' });
    }
    // 2026-08-30, explicit request: "ทำให้ fixed_amount/percent_rate เป็นค่าเริ่มต้นอัตโนมัติตอน
    // assign ให้พนักงาน" -- catalog fixed_amount/percent_rate are unused for automatic calculation
    // (real amounts always come from this per-employee assignment's own principal_amount, entered
    // separately -- see CLAUDE.md's own PED convention note), but a real value entered on the
    // catalog item IS a genuinely useful SUGGESTED starting point here. Only fires from an actual
    // user pick (select2:select), never from populateEedForm()'s own new Option(...) pre-select when
    // opening an existing assignment for edit/view -- and only ever fills an EMPTY amount field, so
    // it can never silently overwrite a value the admin already typed or a saved assignment's own
    // real amount.
    $(document).on('select2:select', '#eed_ped_type_id', function (e) {
        const item = e.params && e.params.data;
        if (!item) return;
        const $amount = $('#eed_principal_amount');
        if (($amount.val() || '').toString().trim() !== '') return;
        let suggested = null;
        if (item.calculation_method === 'fixed_amount' && parseFloat(item.fixed_amount) > 0) {
            suggested = parseFloat(item.fixed_amount);
        } else if (item.calculation_method === 'percent_of_base_salary' && parseFloat(item.percent_rate) > 0) {
            const baseSalary = parseFloat($('#base_salary_amount').val() || '0');
            if (baseSalary > 0) {
                suggested = Math.round(baseSalary * parseFloat(item.percent_rate) / 100 * 100) / 100;
            }
        }
        if (suggested !== null && suggested > 0) {
            $amount.val(suggested);
        }
        // 2026-09-03, Manual Entry / Employee Salary tab review Phase 1B (explicit request: "เมื่อ
        // เลือก PED Type ในฟอร์มเงินกู้/ผ่อนชำระ ให้ auto-fill ดอกเบี้ย/เงื่อนไข default จาก catalog") --
        // same "only ever fills an untouched field, never overwrites an admin's own choice" rule as
        // the Amount suggestion immediately above, adapted for this toggle-button UI: the guard is
        // "charge type is still at its own default ('none')" rather than "field is blank," since
        // that IS this form's own untouched/neutral state (see setEedChargeType()'s own default-
        // active-button markup). Only fires for a deduction item (default_interest_type is always
        // NULL on an earning row anyway, per PayrollEarningDeductionTypeModel::save()'s own
        // deduction-only gate, so this is a defensive check, not load-bearing).
        if (item.item_type === 'deduction' && item.default_interest_type && $('#eedInterestToggle button.active').data('value') === 'none') {
            if (item.default_interest_type === 'fee') {
                setEedChargeType('fee');
                if (item.default_fee_percent !== null && item.default_fee_percent !== undefined && item.default_fee_percent !== '') {
                    $('#eed_fee_percent').val(item.default_fee_percent);
                }
                if (item.default_fee_base) {
                    $('#eed_fee_base').val(item.default_fee_base).trigger('change');
                }
            } else if (item.default_interest_type === 'fixed' || item.default_interest_type === 'reducing_balance') {
                setEedChargeType('interest');
                setEedInterestType(item.default_interest_type);
                if (item.default_interest_rate !== null && item.default_interest_rate !== undefined && item.default_interest_rate !== '') {
                    $('#eed_interest_rate').val(item.default_interest_rate);
                }
            }
            // default_interest_type === 'none' needs no action -- setEedChargeType('none') is
            // already this form's own starting state.
        }
    });
    $(document).on('click', '#eedModeToggle button', function () {
        setEedMode($(this).data('mode'));
    });
    $(document).on('click', '#eedPayeeTypeToggle button', function () {
        setEedPayeeType($(this).data('payee-type'));
    });
    $(document).on('select2:select', '#eed_destination_select', function () {
        $('#eedDestinationNewFields').addClass('d-none');
    });
    $(document).on('select2:clear', '#eed_destination_select', function () {
        $('#eedDestinationNewFields').removeClass('d-none');
    });
    $(document).on('click', '.btn-add-earning', function () {
        if (!currentEmployeeId) {
            showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
            return;
        }
        resetEedForm('earning');
        new bootstrap.Modal(document.getElementById('eedModal')).show();
    });
    $(document).on('click', '.btn-add-deduction', function () {
        if (!currentEmployeeId) {
            showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
            return;
        }
        resetEedForm('deduction');
        new bootstrap.Modal(document.getElementById('eedModal')).show();
    });
    // Fetched fresh from the API, not read off the clicked row's cached DOM data -- DataTables may
    // have re-rendered the row (page/sort/search) by the time this fires, so any cache would be stale.
    function openEedModalForId(id, readOnly) {
        $.ajax({
            url: `${BASE_URL}/api/employee.earning-deduction.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status && res.data) {
                    resetEedForm();
                    populateEedForm(res.data, readOnly);
                    new bootstrap.Modal(document.getElementById('eedModal')).show();
                } else {
                    showWarning(res.message || langData['load_employee_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['load_employee_failed'] || 'Failed to load data.');
            }
        });
    }
    $(document).on('click', '.btn-edit-eed', function () {
        openEedModalForId($(this).data('id'), false);
    });
    $(document).on('click', '.btn-view-eed', function () {
        openEedModalForId($(this).data('id'), true);
    });
    $(document).on('click', '#eedInterestToggle button', function () {
        setEedChargeType($(this).data('value'));
    });
    $(document).on('click', '#eedInterestTypeToggle button', function () {
        setEedInterestType($(this).data('value'));
    });
    // 2026-09-03, Platform UX review Phase 4 -- see modals.php's own comment on #eedRateHelperToggle
    // for the full rationale. Purely a fill-in-for-me calculator: computes annual% / periods-per-year
    // and writes the result into #eed_interest_rate on demand -- never auto-applies, and doesn't
    // change what that field itself means or how save()/computeInstallmentSchedule() read it.
    $(document).on('click', '#eedRateHelperToggle', function () {
        $('#eedRateHelperBody').toggleClass('d-none');
    });
    $(document).on('input change', '#eedRateHelperAnnual, #eedRateHelperFrequency', function () {
        const annual = parseFloat($('#eedRateHelperAnnual').val() || '0');
        const periodsPerYear = parseFloat($('#eedRateHelperFrequency').val() || '12');
        if (annual > 0 && periodsPerYear > 0) {
            const perPeriod = annual / periodsPerYear;
            const template = langData['rate_helper_result'] || '≈ {rate}% per installment';
            $('#eedRateHelperResult').text(template.replace('{rate}', perPeriod.toFixed(3)));
        } else {
            $('#eedRateHelperResult').text('');
        }
    });
    $(document).on('click', '#eedRateHelperApply', function () {
        const annual = parseFloat($('#eedRateHelperAnnual').val() || '0');
        const periodsPerYear = parseFloat($('#eedRateHelperFrequency').val() || '12');
        if (!(annual > 0) || !(periodsPerYear > 0)) return;
        $('#eed_interest_rate').val((annual / periodsPerYear).toFixed(3)).trigger('change');
    });
    $(document).on('input change', '#eed_total_installments, #eed_principal_amount, #eed_interest_rate, #eed_fee_percent, #eed_fee_base', function () {
        scheduleEedPreviewFetch();
    });
    $(document).on('submit', '#eedForm', function (e) {
        e.preventDefault();
        const invalidEl = validateEedForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- same either/or
        // check as the Process Detail manual-line flow's own #manualLineDestinationWrapper (Phase 2).
        if ($('#eedPayeeTypeToggle button.active').data('payee-type') === 'other_person' && !$('#eed_destination_select').val()) {
            const hasNewFields = $('#eed_dest_account_name').val().trim() && $('#eed_dest_account_no').val().trim() && $('#eed_dest_bank').val();
            if (!hasNewFields) {
                showWarning(langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.');
                return;
            }
        }
        const payload = collectEedFormData();
        const $btn = $('#eedForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/employee.earning-deduction.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('eedModal')).hide();
                    loadEarningDeductions();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
            }
        });
    });
    $(document).on('click', '.btn-eed-status', function () {
        const id = $(this).data('id');
        const newStatus = $(this).data('status');
        const titles = {
            paused: ['confirm_pause_title', 'Pause this item?', 'confirm_pause_message', 'No further installments will be deducted until resumed.'],
            active: ['confirm_resume_title', 'Resume this item?', 'confirm_resume_message', 'This item will be included in the next payroll run again.'],
            cancelled: ['confirm_cancel_title', 'Cancel this item?', 'confirm_cancel_message', 'This cannot be undone.']
        };
        const chosen = titles[newStatus] || titles.paused;
        showConfirm(langData[chosen[0]] || chosen[1], langData[chosen[2]] || chosen[3], function () {
            $.ajax({
                url: `${BASE_URL}/api/employee.earning-deduction.status`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id, employee_id: currentEmployeeId, status: newStatus }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['save_success'] || 'Saved successfully.');
                        loadEarningDeductions();
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to update status.');
                    }
                },
                error: function () {
                    showWarning(langData['save_failed'] || 'An error occurred.');
                }
            });
        });
    });
    $(document).on('click', '.btn-delete-eed', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/employee.earning-deduction.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id, employee_id: currentEmployeeId }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        loadEarningDeductions();
                    } else {
                        showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                    }
                },
                error: function () {
                    showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
                }
            });
        });
    });
}

/* ==================== Recurring Allowances (Salary tab's own new section) -- 2026-08-26, explicit
   request: "รายรับที่ได้ทุกเดือนเช่นพวกค่าตำแหน่ง ค่ารถ ค่าน้ำมัน...ให้เพิ่มส่วนนี้เข้าไปด้วย และระงับการจ่ายได้"
   -- see EmployeeRecurringEarningModel's own docblock for why this is a separate table/section from
   Earning-Deduction (loans/installments) above. Mirrors initEedUI()'s own DataTable-list-+-modal
   shape, simplified: no catalog/custom toggle (catalog-only), no installment schedule, no interest. ==================== */
let tbRecurringEarning;
// 2026-08-31, direct mirror of tbRecurringEarning immediately above -- explicit request: "หน้า
// Employee Detail เพิ่มรายหักประจำด้วยครับ". See EmployeeRecurringDeductionModel's own docblock.
let tbRecurringDeduction;
/* ==================== OT Rate Settings (Salary tab) -- explicit request: "OT Rate เพิ่มให้สามารถ
   Assing รายบุคคลได้ด้วย...ให้ไป Set แยก ใน Employee ใน Tab ที่มีการติ๊กว่า ได้รับ OT ไหม". Shown only
   while #ot_eligible is checked; the per-scope override rows only actionable once
   ot_rate_source='custom'. See EmployeeOtRateModel's own docblock for the backend design.
   ==================== */
let otRateScopesData = [];
function otRateOverrideRowHtml(scope) {
    const name = currentLang === 'th' ? scope.scope_name_th : scope.scope_name_en;
    const isFlat = scope.calculation_method === 'flat_amount';
    return `<tr data-scope-id="${scope.ot_scope_id}">
        <td>${escapeHtml(name)}</td>
        <td>
            <select class="form-select form-select-sm select2-static ot-rate-calc-method"
                    data-option-keys="ot_calc_method_multiplier,ot_calc_method_flat_amount" data-option-values="multiplier,flat_amount"></select>
        </td>
        <td>
            <div class="ot-rate-multiplier-wrap ${isFlat ? 'd-none' : ''}">
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm ot-rate-multiplier-input" value="${scope.multiplier_rate}">
            </div>
            <div class="ot-rate-flat-wrap ${isFlat ? '' : 'd-none'}">
                <input type="number" step="0.01" min="0.01" class="form-control form-control-sm ot-rate-flat-input" value="${scope.flat_amount_rate !== null && scope.flat_amount_rate !== undefined ? scope.flat_amount_rate : ''}">
            </div>
        </td>
        <td>
            <select class="form-select form-select-sm select2-static ot-rate-calc-base"
                    data-option-keys="ot_base_hourly,ot_base_daily" data-option-values="hourly,daily"></select>
        </td>
    </tr>`;
}
// 2026-08-30, explicit request: "และตรง From ที่ให้ใส่ input ขึ้นตามประเภทที่เลือกของแต่ละประเภท OT โดยค่าเริ่ม
// ให้ให้ดึงของที่บริษัทกำหนดมาถ้ายังไม่เคยใส่" -- every row's inputs are already pre-filled from the
// RESOLVED value the backend returns (EmployeeOtRateModel::getForEmployee()'s own `source = override
// ?? default`), so a scope with no override for this employee shows this employee's own resolved OT
// Rate Set value (2026-08-30 replacement) with nothing extra needed here -- this function just
// renders whatever the API already resolved.
// 2026-08-31, real bug avoided proactively (not found live, same class as work_location_id/shift_id/
// team_id's own documented gotcha): #ot_rate_set_id is select2-remote -- setting .val(id) with no
// <option> preloaded yet silently no-ops, so the field would read empty and (per EmployeeOtRateModel
// ::save()'s own "always write exactly what's passed, including null" contract) WIPE OUT a real saved
// pick on next save. Always preload a real Option (id + localized text) before .val()+trigger.
function populateOtRateSetPicker(id, nameObj) {
    const $sel = $('#ot_rate_set_id');
    $sel.empty();
    if (id && nameObj) {
        const text = currentLang === 'th' ? nameObj.name_th : nameObj.name_en;
        $sel.append(new Option(text, id, true, true));
    }
    $sel.trigger('change.select2');
}
function updateOtRateSetRecommendHint(res) {
    const $hint = $('#otRateSetRecommendHint');
    if (!res.recommended_ot_rate_set_id || !res.recommended_ot_rate_set_name) {
        $hint.text(langData['ot_rate_set_recommend_none'] || 'No matching OT Rate Set could be recommended yet.');
        return;
    }
    const name = currentLang === 'th' ? res.recommended_ot_rate_set_name.name_th : res.recommended_ot_rate_set_name.name_en;
    $hint.text(`${langData['ot_rate_set_recommend_prefix'] || 'Recommended:'} ${name}`);
}
function loadOtRateForEmployee(employeeId) {
    if (!employeeId) return;
    $.ajax({
        url: `${BASE_URL}/api/employee.ot-rate.get`,
        method: 'GET',
        data: { employee_id: employeeId },
        dataType: 'json',
        success: function (res) {
            if (!res.status) return;
            otRateScopesData = res.scopes || [];
            $(`#ot_rate_source_${res.ot_rate_source === 'custom' ? 'custom' : 'default'}`).prop('checked', true);
            $('#otRateOverridesContainer').toggleClass('d-none', res.ot_rate_source !== 'custom');
            // Explicit toggle, same as the line above -- setting a radio's checked prop programmatically
            // does NOT fire 'change', so the handler in initOtRateUI() alone would leave this stale on load.
            // .ot-rate-set-picker-toggle (2026-08-31) covers BOTH the label and field columns together
            // (split into col-sm-2/col-sm-4 to match the rest of this tab's row shape).
            $('.ot-rate-set-picker-toggle').toggleClass('d-none', res.ot_rate_source === 'custom');
            $('#otRateOverridesBody').html(otRateScopesData.map(otRateOverrideRowHtml).join(''));
            initSelect2('#otRateOverridesBody .ot-rate-calc-method', { mode: 'static' });
            initSelect2('#otRateOverridesBody .ot-rate-calc-base', { mode: 'static' });
            otRateScopesData.forEach(function (scope) {
                const $row = $(`#otRateOverridesBody tr[data-scope-id="${scope.ot_scope_id}"]`);
                $row.find('.ot-rate-calc-method').val(scope.calculation_method).trigger('change.select2');
                $row.find('.ot-rate-calc-base').val(scope.calculation_base).trigger('change.select2');
            });
            populateOtRateSetPicker(res.assigned_ot_rate_set_id, res.assigned_ot_rate_set_name);
            updateOtRateSetRecommendHint(res);
        }
    });
}
function initOtRateUI() {
    initSelect2('#ot_rate_set_id', { mode: 'ajax' });
    // 2026-08-31, "ให้เอาสิทธิ์การได้รับ OT มาไว้ใน card ของ ตั้งค่าอัตรา OT เลย จะได้เห็นว่าเป็นชุดเดียวกัน" --
    // the OT Eligible checkbox now lives INSIDE #otRateSection (always visible), so hiding the whole
    // card on uncheck would also hide the checkbox that controls it -- only the DEPENDENT sub-parts
    // (source radio / Set picker / per-scope table) toggle now, via #otRateDependentWrap.
    $(document).on('change', '#ot_eligible', function () {
        $('#otRateDependentWrap').toggleClass('d-none', !$(this).is(':checked'));
    });
    // 2026-08-30, explicit request: "แหล่งที่มาอัตรา OT ปรับให้เป็น radio" -- was a select2-static
    // dropdown, now a plain Bootstrap btn-check radio pair (matches this app's own precedent for a
    // simple 2-choice toggle, e.g. is_payroll_participant's own radio pair).
    $(document).on('change', 'input[name="ot_rate_source_radio"]', function () {
        const isCustom = $(this).val() === 'custom';
        $('#otRateOverridesContainer').toggleClass('d-none', !isCustom);
        // 2026-08-31, "ถ้าเลือกจาก OT ของระบบ จะมีให้เลือกเพิ่มว่า OT ไหน" -- the Set picker only makes
        // sense while source=default (custom means every scope is hand-typed on this employee, no
        // Set to pick from). .ot-rate-set-picker-toggle covers both the label and field columns.
        $('.ot-rate-set-picker-toggle').toggleClass('d-none', isCustom);
    });
    $(document).on('change', '.ot-rate-calc-method', function () {
        const $row = $(this).closest('tr');
        const isFlat = $(this).val() === 'flat_amount';
        $row.find('.ot-rate-multiplier-wrap').toggleClass('d-none', isFlat);
        $row.find('.ot-rate-flat-wrap').toggleClass('d-none', !isFlat);
    });
}
// 2026-08-30, explicit request: "ปุ่ม Save ให้ตัดออกไปรวมกับ Save ด้านล่าง" -- the OT Rate Settings
// card's own standalone Save button is gone; saving now folds into the Salary tab's own
// #btnNextSalary click, same "one Save button fires multiple related ajax calls together via
// Promise.all, one combined message" pattern saveFamilyTab() already established for the Family/Tax
// Allowance tab. OT rate is only saved when this employee already has an id (a brand-new employee's
// first save has none yet -- same "save basic info first" precedent as Recurring Allowances) AND
// OT Eligible is checked (nothing meaningful to save otherwise, the section is hidden).
// 2026-09-02, explicit request: "ตั้งค่าอัตรา OT น่าจะมาอยู่ที่การจ้างงานมากกว่า...ย้ายข้อมูลการจ่ายเงิน ไปไว้
// Tab เงินเดือน" -- #btnNextSalary is physically the Save button at the bottom of the EMPLOYMENT tab
// pane (this function's own name is a "reveals the Salary tab next" label, not "saves Salary tab's
// own fields" -- see the button-naming convention comment where every #btnNextX handler is wired
// up). Payment Information (incl. #sectionMixedPayment) has now moved OUT of this tab into Salary,
// so the mixed-payment-lines guard that used to live here (duplicated from saveEmployee()'s own
// copy, back when this WAS the most likely save path to catch it on) was removed -- saveEmployee()'s
// copy already covers it for every other Save button, #btnNextSocial (Salary tab's own real closing
// Save button) included, now that the section it guards lives there.
function saveSalaryTab($btn) {
    const invalidEl = validateEmployeeForm($btn.closest('.tab-pane'));
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        jumpToField(invalidEl);
        return;
    }
    const payload = collectEmployeeFormData();
    const wasNew = !currentEmployeeId;
    if (currentEmployeeId) {
        payload.id = currentEmployeeId;
    }
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);

    const promises = [$.ajax({
        url: `${BASE_URL}/api/employee.save`, method: 'POST',
        contentType: 'application/json', dataType: 'json', data: JSON.stringify(payload)
    })];

    if (currentEmployeeId && $('#ot_eligible').is(':checked')) {
        const otRateSource = $('input[name="ot_rate_source_radio"]:checked').val() || 'default';
        const overrides = [];
        if (otRateSource === 'custom') {
            $('#otRateOverridesBody tr').each(function () {
                const $tr = $(this);
                overrides.push({
                    ot_scope_id: $tr.data('scope-id'),
                    calculation_method: $tr.find('.ot-rate-calc-method').val(),
                    multiplier_rate: $tr.find('.ot-rate-multiplier-input').val(),
                    flat_amount_rate: $tr.find('.ot-rate-flat-input').val(),
                    calculation_base: $tr.find('.ot-rate-calc-base').val(),
                });
            });
        }
        // assigned_ot_rate_set_id only meaningful while source=default -- omitted (null) for custom
        // so switching to custom doesn't silently keep a stale explicit pick around unused.
        const assignedOtRateSetId = otRateSource === 'default' ? ($('#ot_rate_set_id').val() || null) : null;
        promises.push($.ajax({
            url: `${BASE_URL}/api/employee.ot-rate.save`, method: 'POST',
            contentType: 'application/json', dataType: 'json',
            data: JSON.stringify({ employee_id: currentEmployeeId, ot_rate_source: otRateSource, overrides: overrides, assigned_ot_rate_set_id: assignedOtRateSetId })
        }));
    }

    Promise.all(promises).then(function (results) {
        $btn.prop('disabled', false).html(originalHtml);
        if (typeof updateText === 'function') updateText($btn[0]);
        const allOk = results.every(r => r && r.status);
        if (allOk) {
            showSuccess(langData['save_success'] || 'Saved successfully.');
            applyEmployeeSaveSuccess(results[0], wasNew);
            if (currentEmployeeId) loadOtRateForEmployee(currentEmployeeId);
        } else {
            const failed = results.find(r => !r || !r.status);
            showWarning((failed && failed.message) || langData['save_failed'] || 'Failed to save data.');
        }
    }).catch(function () {
        $btn.prop('disabled', false).html(originalHtml);
        if (typeof updateText === 'function') updateText($btn[0]);
        showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
    });
}
function recurringEarningStatusBadge(row) {
    if (row.is_suspended_now) {
        return `<span class="badge bg-warning-subtle text-warning">${langData['status_suspended'] || 'Suspended'}</span>`;
    }
    return `<span class="badge bg-success-subtle text-success">${langData['status_active'] || 'Active'}</span>`;
}
function recurringEarningSuspendPeriodCell(row) {
    if (!row.suspended_from || !row.suspended_to) return '-';
    return `${toDisplayDate(row.suspended_from)} - ${toDisplayDate(row.suspended_to)}`;
}
function initRecurringEarningUI() {
    tbRecurringEarning = $('#tableRecurringEarning').DataTable({
        responsive: true,
        // 2026-08-29, real bug found and fixed (explicit urgent report -- a newly-added allowance
        // would disappear again shortly after saving, and reliably came back empty on a fresh page
        // load) -- this table used to fetch automatically on init, but currentEmployeeId is only set
        // later, inside loadEmployeeIfEditing()'s async success callback (initRecurringEarningUI()
        // runs synchronously well before that resolves). That first, wrongly-parameterized (null
        // employee_id) request could resolve AFTER a later, correctly-parameterized .ajax.reload()
        // (e.g. right after adding an allowance), silently clobbering the correct data with an empty
        // result. deferLoading:0 tells DataTables to skip that automatic first fetch entirely -- no
        // stale request is ever sent, so it can never race a later, deliberate reload. The ONE real
        // fetch now happens only via the explicit .ajax.reload() calls (loadAllChildTables(), and
        // every add/edit/delete success handler below), always AFTER currentEmployeeId is genuinely
        // known.
        deferLoading: 0,
        ajax: {
            url: `${BASE_URL}/api/employee.recurring-earning.list`,
            data: function (d) { d.employee_id = currentEmployeeId; },
            dataSrc: 'data'
        },
        columns: [
            { data: null, render: (d, t, row) => escapeHtml((currentLang === 'th' ? row.item_name_th : row.item_name_en) || '') },
            // 2026-08-29, real bugs found via a system-wide table audit: sort-safety fixes -- both
            // used a plain `render: fn`, so client-side sort/filter operated on the FORMATTED
            // string (amount: "1,234.56" sorts before "999.00" lexicographically; date: dd/mm/yyyy
            // doesn't sort chronologically), not the raw underlying value.
            { data: 'amount', className: 'text-end', render: { display: d => Number(d || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }), sort: d => Number(d || 0), filter: d => Number(d || 0) } },
            { data: 'effective_date', render: { display: d => toDisplayDate(d), sort: d => d || '', filter: d => d || '' } },
            { data: null, render: (d, t, row) => recurringEarningSuspendPeriodCell(row) },
            { data: null, render: (d, t, row) => recurringEarningStatusBadge(row) },
            {
                // 2026-08-28: className:'all' keeps this last actions column from collapsing into
                // the Responsive expand row.
                data: null, orderable: false, className: 'text-center all',
                render: (d, t, row) => `
                    <button type="button" class="btn btn-sm btn-link text-primary btn-edit-recurring-earning" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>
                    <button type="button" class="btn btn-sm btn-link text-danger btn-delete-recurring-earning" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>
                `
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-recurring-earning').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary btn-sm ms-1 btn-add-recurring-earning">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_recurring_earning">${langData['add_recurring_earning'] || 'Add Recurring Allowance'}</span>
                    </button>
                `);
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes the actions column (5).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'item_name' },
                    { index: 1, key: 'amount' },
                    { index: 2, key: 'effective_date' },
                    { index: 3, key: 'suspend_period' },
                    { index: 4, key: 'status' },
                ]
            });
        }
    });
    // Same hidden-tab-at-init width gotcha as tableEarning/tableDeduction above -- this table lives
    // on the Salary tab, which also isn't the default-active tab on page load.
    document.getElementById('salary-tab').addEventListener('shown.bs.tab', function () {
        if (tbRecurringEarning) tbRecurringEarning.columns.adjust();
    });
    if (typeof initSelect2 === 'function') {
        initSelect2('#ere_ped_type_id', { mode: 'ajax' });
    }
    // Same suggested-default precedent as #eed_ped_type_id's own handler above -- this dropdown is
    // always pre-filtered server-side to calculation_method='fixed_amount' items only (see
    // EmployeeController::recurringEarningTypeOptions()), so no percent_of_base_salary branch is
    // needed here.
    $(document).on('select2:select', '#ere_ped_type_id', function (e) {
        const item = e.params && e.params.data;
        if (!item) return;
        const $amount = $('#ere_amount');
        if (($amount.val() || '').toString().trim() !== '') return;
        if (item.calculation_method === 'fixed_amount' && parseFloat(item.fixed_amount) > 0) {
            $amount.val(parseFloat(item.fixed_amount));
        }
    });
    $(document).on('click', '.btn-add-recurring-earning', function () {
        if (!currentEmployeeId) {
            showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
            return;
        }
        resetRecurringEarningForm();
        new bootstrap.Modal(document.getElementById('recurringEarningModal')).show();
    });
    $(document).on('click', '.btn-edit-recurring-earning', function () {
        const id = $(this).data('id');
        // Fetched fresh from the API, not read off the clicked row's cached DOM data -- same
        // reasoning as openEedModalForId() above (DataTables may have re-rendered the row by now).
        $.ajax({
            url: `${BASE_URL}/api/employee.recurring-earning.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status && res.data) {
                    resetRecurringEarningForm();
                    populateRecurringEarningForm(res.data);
                    new bootstrap.Modal(document.getElementById('recurringEarningModal')).show();
                } else {
                    showWarning(res.message || langData['load_employee_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['load_employee_failed'] || 'Failed to load data.');
            }
        });
    });
    $(document).on('submit', '#recurringEarningForm', function (e) {
        e.preventDefault();
        const invalidEl = validateRecurringEarningForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const suspendedFrom = toIsoDate($('#ere_suspended_from').val());
        const suspendedTo = toIsoDate($('#ere_suspended_to').val());
        if (!!suspendedFrom !== !!suspendedTo) {
            showWarning(langData['suspend_period_both_required'] || 'Enter both a suspend start date and end date, or leave both blank.');
            return;
        }
        const payload = {
            id: $('#ere_id').val() || undefined,
            employee_id: currentEmployeeId,
            ped_type_id: $('#ere_ped_type_id').val(),
            amount: $('#ere_amount').val(),
            effective_date: toIsoDate($('#ere_effective_date').val()),
            suspended_from: suspendedFrom || undefined,
            suspended_to: suspendedTo || undefined,
            notes: $('#ere_notes').val().trim()
        };
        const $btn = $('#ereSaveBtn');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/employee.recurring-earning.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('recurringEarningModal')).hide();
                    if (tbRecurringEarning) tbRecurringEarning.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
            }
        });
    });
    $(document).on('click', '.btn-delete-recurring-earning', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/employee.recurring-earning.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id, employee_id: currentEmployeeId }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        if (tbRecurringEarning) tbRecurringEarning.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                    }
                },
                error: function () {
                    showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
                }
            });
        });
    });
}
function resetRecurringEarningForm() {
    $('#recurringEarningForm')[0].reset();
    $('#ere_id').val('');
    $('#ere_ped_type_id').val(null).trigger('change');
    $('.is-invalid', '#recurringEarningModal').removeClass('is-invalid');
    // 2026-08-30 (T007), real bug found and fixed (modal title audit: "Modal header ไม่เปลี่ยนภาษา
    // ตอนเปลี่ยนภาษาขณะ modal เปิดอยู่") -- setting `data-i18n` alongside .text() (matching the
    // existing correct pattern already used by company-profile.js's own #bffFieldModal title) lets
    // the generic updateText(document) sweep (already run on every language change) keep this in
    // sync for free -- no per-modal re-render hook needed since this title is a plain static string,
    // not interpolated with any dynamic data.
    $('#recurringEarningModalLabel span').attr('data-i18n', 'add_recurring_earning').text(langData['add_recurring_earning'] || 'Add Recurring Allowance');
}
function populateRecurringEarningForm(row) {
    $('#ere_id').val(row.id);
    const label = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || '';
    const opt = new Option(`[${row.item_code}] ${label}`, row.ped_type_id, true, true);
    $('#ere_ped_type_id').empty().append(opt).trigger('change');
    $('#ere_amount').val(row.amount);
    // Same datepicker-state-desync bug/fix as populateEmployeeForm() -- see that function's own
    // comment: a plain .val() leaves bootstrap-datepicker's internal `dates` empty until re-synced.
    $('#ere_effective_date').val(toDisplayDate(row.effective_date));
    $('#ere_effective_date').datepicker('update');
    $('#ere_suspended_from').val(row.suspended_from ? toDisplayDate(row.suspended_from) : '');
    $('#ere_suspended_from').datepicker('update');
    $('#ere_suspended_to').val(row.suspended_to ? toDisplayDate(row.suspended_to) : '');
    $('#ere_suspended_to').datepicker('update');
    $('#ere_notes').val(row.notes || '');
    $('#recurringEarningModalLabel span').attr('data-i18n', 'edit_recurring_earning').text(langData['edit_recurring_earning'] || 'Edit Recurring Allowance');
}
function validateRecurringEarningForm() {
    let firstInvalid = null;
    $('#recurringEarningModal .required').each(function () {
        const $el = $(this);
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    return firstInvalid;
}

/* ==================== Recurring Deductions (Salary tab) -- 2026-08-31, explicit request: "หน้า
   Employee Detail เพิ่มรายหักประจำด้วยครับ และนำไปเพิ่มใน ตรงสรุปรายได้ประจำ ด้วย" -- direct mirror of the
   Recurring Allowances block immediately above (own #tableRecurringDeduction DataTable +
   #recurringDeductionModal, `erd` id prefix instead of `ere`, api/employee.recurring-deduction.*
   instead of .recurring-earning.*). See EmployeeRecurringDeductionModel's own docblock for the
   backend design (near-identical to EmployeeRecurringEarningModel, item_type='deduction' instead
   of 'earning'). ==================== */
function recurringDeductionStatusBadge(row) {
    if (row.is_suspended_now) {
        return `<span class="badge bg-warning-subtle text-warning">${langData['status_suspended'] || 'Suspended'}</span>`;
    }
    return `<span class="badge bg-success-subtle text-success">${langData['status_active'] || 'Active'}</span>`;
}
function recurringDeductionSuspendPeriodCell(row) {
    if (!row.suspended_from || !row.suspended_to) return '-';
    return `${toDisplayDate(row.suspended_from)} - ${toDisplayDate(row.suspended_to)}`;
}
// 2026-08-31, mirrors eedInterestSubLabel()'s own fee sub-label shape -- shown next to the flat
// `amount`, since the ACTUAL deducted amount each run is `amount` + this fee (recomputed live
// against the employee's current base salary, see PayrollRunModel::recurringDeductionAmountWithFee()),
// not a static number this table's own `amount` column alone would represent.
function recurringDeductionFeeSubLabel(row) {
    if (!row.fee_percent) return '';
    const pct = parseFloat(row.fee_percent).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return ` <span class="text-muted small">(+${pct}% ${langData['fee_of'] || 'of'} ${langData['fee_base_option_base_salary'] || 'Base Salary'})</span>`;
}
function initRecurringDeductionUI() {
    tbRecurringDeduction = $('#tableRecurringDeduction').DataTable({
        responsive: true,
        // Same deferLoading:0 fix as tbRecurringEarning's own comment explains (currentEmployeeId
        // isn't known yet when this table initializes synchronously at page load).
        deferLoading: 0,
        ajax: {
            url: `${BASE_URL}/api/employee.recurring-deduction.list`,
            data: function (d) { d.employee_id = currentEmployeeId; },
            dataSrc: 'data'
        },
        columns: [
            { data: null, render: (d, t, row) => escapeHtml((currentLang === 'th' ? row.item_name_th : row.item_name_en) || '') },
            { data: 'amount', className: 'text-end', render: { display: (d, t, row) => Number(d || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + recurringDeductionFeeSubLabel(row), sort: d => Number(d || 0), filter: d => Number(d || 0) } },
            { data: 'effective_date', render: { display: d => toDisplayDate(d), sort: d => d || '', filter: d => d || '' } },
            { data: null, render: (d, t, row) => recurringDeductionSuspendPeriodCell(row) },
            { data: null, render: (d, t, row) => recurringDeductionStatusBadge(row) },
            {
                data: null, orderable: false, className: 'text-center all',
                render: (d, t, row) => `
                    <button type="button" class="btn btn-sm btn-link text-primary btn-edit-recurring-deduction" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen"></i></button>
                    <button type="button" class="btn btn-sm btn-link text-danger btn-delete-recurring-deduction" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>
                `
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-recurring-deduction').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary btn-sm ms-1 btn-add-recurring-deduction">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_recurring_deduction">${langData['add_recurring_deduction'] || 'Add Recurring Deduction'}</span>
                    </button>
                `);
            }
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'item_name' },
                    { index: 1, key: 'amount' },
                    { index: 2, key: 'effective_date' },
                    { index: 3, key: 'suspend_period' },
                    { index: 4, key: 'status' },
                ]
            });
        }
    });
    // Same hidden-tab-at-init width gotcha as tbRecurringEarning -- this table also lives on the
    // Salary tab, which isn't the default-active tab on page load.
    document.getElementById('salary-tab').addEventListener('shown.bs.tab', function () {
        if (tbRecurringDeduction) tbRecurringDeduction.columns.adjust();
    });
    if (typeof initSelect2 === 'function') {
        initSelect2('#erd_ped_type_id', { mode: 'ajax' });
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6 -- allowClear override
        // on top of the generic '.select2-remote' sweep above (same "explicit follow-up call only
        // for non-default options" precedent #eed_payee_employee_id's own comment documents).
        initSelect2('#erd_payee_employee_id', { mode: 'ajax', allowClear: true });
        initSelect2('#erd_destination_select', { mode: 'ajax', allowClear: true });
    }
    $(document).on('select2:select', '#erd_ped_type_id', function (e) {
        const item = e.params && e.params.data;
        if (!item) return;
        const $amount = $('#erd_amount');
        if (($amount.val() || '').toString().trim() !== '') return;
        if (item.calculation_method === 'fixed_amount' && parseFloat(item.fixed_amount) > 0) {
            $amount.val(parseFloat(item.fixed_amount));
        }
    });
    $(document).on('click', '#erdFeeToggle button', function () {
        setErdFeeOn($(this).data('value') === 'fee');
    });
    $(document).on('click', '#erdPayeeTypeToggle button', function () {
        setErdPayeeType($(this).data('payee-type'));
    });
    $(document).on('select2:select', '#erd_destination_select', function () {
        $('#erdDestinationNewFields').addClass('d-none');
    });
    $(document).on('select2:clear', '#erd_destination_select', function () {
        $('#erdDestinationNewFields').removeClass('d-none');
    });
    $(document).on('click', '.btn-add-recurring-deduction', function () {
        if (!currentEmployeeId) {
            showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
            return;
        }
        resetRecurringDeductionForm();
        new bootstrap.Modal(document.getElementById('recurringDeductionModal')).show();
    });
    $(document).on('click', '.btn-edit-recurring-deduction', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/employee.recurring-deduction.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status && res.data) {
                    resetRecurringDeductionForm();
                    populateRecurringDeductionForm(res.data);
                    new bootstrap.Modal(document.getElementById('recurringDeductionModal')).show();
                } else {
                    showWarning(res.message || langData['load_employee_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['load_employee_failed'] || 'Failed to load data.');
            }
        });
    });
    $(document).on('submit', '#recurringDeductionForm', function (e) {
        e.preventDefault();
        const invalidEl = validateRecurringDeductionForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const suspendedFrom = toIsoDate($('#erd_suspended_from').val());
        const suspendedTo = toIsoDate($('#erd_suspended_to').val());
        if (!!suspendedFrom !== !!suspendedTo) {
            showWarning(langData['suspend_period_both_required'] || 'Enter both a suspend start date and end date, or leave both blank.');
            return;
        }
        const feeOn = $('#erdFeeToggle button.active').data('value') === 'fee';
        const payload = {
            id: $('#erd_id').val() || undefined,
            employee_id: currentEmployeeId,
            ped_type_id: $('#erd_ped_type_id').val(),
            amount: $('#erd_amount').val(),
            effective_date: toIsoDate($('#erd_effective_date').val()),
            suspended_from: suspendedFrom || undefined,
            suspended_to: suspendedTo || undefined,
            notes: $('#erd_notes').val().trim()
        };
        if (feeOn) {
            payload.fee_percent = $('#erd_fee_percent').val();
            payload.fee_base = $('#erd_fee_base').val();
        }
        const payeeType = $('#erdPayeeTypeToggle button.active').data('payee-type') || 'none';
        if (payeeType !== 'none') {
            payload.payee_type = payeeType;
            if (payeeType === 'employee') {
                payload.payee_employee_id = $('#erd_payee_employee_id').val();
            } else if (payeeType === 'other_person') {
                const savedDestinationId = $('#erd_destination_select').val();
                if (savedDestinationId) {
                    payload.destination_id = savedDestinationId;
                } else {
                    const accountName = $('#erd_dest_account_name').val().trim();
                    const accountNo = $('#erd_dest_account_no').val().trim();
                    const bankId = $('#erd_dest_bank').val();
                    if (!accountName || !accountNo || !bankId) {
                        showWarning(langData['destination_required_message'] || 'Select a saved destination, or fill in account name, account number, and bank.');
                        return;
                    }
                    payload.account_name = accountName;
                    payload.account_no = accountNo;
                    payload.bank_id = bankId;
                    payload.bank_branch = $('#erd_dest_bank_branch').val().trim() || undefined;
                    payload.is_saved = $('#erd_dest_save_for_reuse').is(':checked');
                }
            }
        }
        const $btn = $('#erdSaveBtn');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/employee.recurring-deduction.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('recurringDeductionModal')).hide();
                    if (tbRecurringDeduction) tbRecurringDeduction.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
            }
        });
    });
    $(document).on('click', '.btn-delete-recurring-deduction', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/employee.recurring-deduction.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id, employee_id: currentEmployeeId }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        if (tbRecurringDeduction) tbRecurringDeduction.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                    }
                },
                error: function () {
                    showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.');
                }
            });
        });
    });
}
// 2026-08-31, mirrors setEedChargeType()'s own 2-choice-relevant slice (this table only ever has
// None/Fee, no Interest -- see the markup comment above #erdFeeDetailWrapper).
function setErdFeeOn(on) {
    $('#erdFeeToggle button').removeClass('active').filter(`[data-value="${on ? 'fee' : 'none'}"]`).addClass('active');
    $('#erdFeeDetailWrapper').toggleClass('d-none', !on);
    $('#erd_fee_percent').toggleClass('required', on);
}
function resetRecurringDeductionForm() {
    $('#recurringDeductionForm')[0].reset();
    $('#erd_id').val('');
    $('#erd_ped_type_id').val(null).trigger('change');
    $('.is-invalid', '#recurringDeductionModal').removeClass('is-invalid');
    setErdFeeOn(false);
    $('#erd_fee_percent').val('');
    setErdPayeeType('none');
    $('#recurringDeductionModalLabel span').attr('data-i18n', 'add_recurring_deduction').text(langData['add_recurring_deduction'] || 'Add Recurring Deduction');
}
function populateRecurringDeductionForm(row) {
    $('#erd_id').val(row.id);
    const label = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || '';
    const opt = new Option(`[${row.item_code}] ${label}`, row.ped_type_id, true, true);
    $('#erd_ped_type_id').empty().append(opt).trigger('change');
    $('#erd_amount').val(row.amount);
    $('#erd_effective_date').val(toDisplayDate(row.effective_date));
    $('#erd_effective_date').datepicker('update');
    $('#erd_suspended_from').val(row.suspended_from ? toDisplayDate(row.suspended_from) : '');
    $('#erd_suspended_from').datepicker('update');
    $('#erd_suspended_to').val(row.suspended_to ? toDisplayDate(row.suspended_to) : '');
    $('#erd_suspended_to').datepicker('update');
    $('#erd_notes').val(row.notes || '');
    const hasFee = !!row.fee_percent;
    setErdFeeOn(hasFee);
    $('#erd_fee_percent').val(hasFee ? row.fee_percent : '');
    const payeeType = row.payee_type || 'none';
    setErdPayeeType(payeeType);
    if (payeeType === 'employee' && row.payee_employee_id) {
        const payeeLabel = (currentLang === 'th' ? `${row.payee_name_th || ''} ${row.payee_surname_th || ''}` : `${row.payee_name_en || ''} ${row.payee_surname_en || ''}`).trim();
        const payeeOpt = new Option(`${payeeLabel} (${row.payee_employee_no || ''})`, row.payee_employee_id, true, true);
        $('#erd_payee_employee_id').empty().append(payeeOpt).trigger('change');
    } else if (payeeType === 'other_person' && row.destination_id) {
        const destOpt = new Option(row.destination_account_name || '', row.destination_id, true, true);
        $('#erd_destination_select').empty().append(destOpt).trigger('change');
        $('#erdDestinationNewFields').addClass('d-none');
    }
    $('#recurringDeductionModalLabel span').attr('data-i18n', 'edit_recurring_deduction').text(langData['edit_recurring_deduction'] || 'Edit Recurring Deduction');
}
function validateRecurringDeductionForm() {
    let firstInvalid = null;
    $('#recurringDeductionModal .required').each(function () {
        const $el = $(this);
        const value = ($el.val() || '').toString().trim();
        if (!value) {
            $el.addClass('is-invalid');
            if (!firstInvalid) firstInvalid = $el;
        } else {
            $el.removeClass('is-invalid');
        }
    });
    return firstInvalid;
}

/* ============================================================================================
   2026-08-26, explicit request: "ในการจัดการพนักงาน เพิ่มการเก็บลายเซ็นต์ของพนักงานแต่ละคนได้" --
   upload-or-draw signature card, direct port of Company Profile's own #cpSignaturePreviewImg/
   #cpSignaturePadModal handling (see company-profile.js's own comment block for the reasoning this
   mirrors) -- `emp` prefix, posts to api/employee.upload-signature instead of
   api/company.upload-signature, everything else identical.
   ============================================================================================ */
function showEmpSignaturePreview(path) {
    if (path) {
        $('#empSignaturePreviewImg').attr('src', `${BASE_URL}/${path}`).removeClass('d-none');
        $('#empSignaturePlaceholder').addClass('d-none');
        $('#empSignatureRemoveBtn').removeClass('d-none');
    } else {
        $('#empSignaturePreviewImg').attr('src', '').addClass('d-none');
        $('#empSignaturePlaceholder').removeClass('d-none');
        $('#empSignatureRemoveBtn').addClass('d-none');
    }
}
// The hidden field is set generically by populateEmployeeForm() (a plain [name] field, no special
// handling needed there) -- this just keeps the VISUAL preview in sync whenever that happens, since
// populateEmployeeForm() already .trigger('change')s every field it sets.
$(document).on('change', '#emp_signature_path', function () {
    showEmpSignaturePreview($(this).val() || null);
});

// 2026-08-29, real bug found and fixed (explicit report: "ใส่รูปพนักงาน กดบันทึกแล้ว ไม่มาแสดงผล") --
// same upload-then-hidden-field convention as the signature functions above. showEmpPhotoPreview()
// is what populateEmployeeForm() ends up triggering (via #emp_profile_photo_path's generic 'change'
// listener below) when loading an EXISTING employee's already-saved photo -- the old code only ever
// showed a photo you had JUST picked in the current browser session via FileReader, never one loaded
// back from the server.
function showEmpPhotoPreview(path) {
    if (path) {
        $('#profilePreview').attr('src', `${BASE_URL}/${path}`).removeClass('d-none');
        $('#profilePlaceholder').addClass('d-none');
    } else {
        $('#profilePreview').attr('src', '').addClass('d-none');
        $('#profilePlaceholder').removeClass('d-none');
    }
}
$(document).on('change', '#emp_profile_photo_path', function () {
    showEmpPhotoPreview($(this).val() || null);
});
function uploadEmpPhotoBlob(blob) {
    const formData = new FormData();
    formData.append('file', blob, 'photo.png');
    $.ajax({
        url: `${BASE_URL}/api/employee.upload-photo`,
        method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) {
                $('#emp_profile_photo_path').val(res.profile_photo_path).trigger('change');
                $('#emp_profile_photo_file_size').val(res.file_size || '');
                $('#emp_profile_photo_thumbnail_path').val(res.thumbnail_path || '');
                // Also refreshes the top-right nav photo live, without waiting for the next full page
                // navigation, when the employee being edited is the currently logged-in user
                // themselves (the only case the header photo could possibly be showing right now).
                if (typeof SESSION_EMPLOYEE_ID !== 'undefined' && currentEmployeeId === SESSION_EMPLOYEE_ID) {
                    $('#navProfilePhoto').attr('src', `${BASE_URL}/${res.profile_photo_path}`);
                }
                // 2026-08-30: this endpoint (api/employee.upload-photo) is separate from the tab
                // Save button's api/employee.save, so it never went through applyEmployeeSaveSuccess()
                // -- now that Employee List renders this same photo (see list.js's own avatar column
                // fix), a manual upload here needs to mark the List dirty too, same as every other
                // profile change.
                if (typeof markTabDirty === 'function') markTabDirty('employee_list_dirty');
                refreshProfileHeader();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Upload failed.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); }
    });
}
function uploadEmpSignatureBlob(blob) {
    const formData = new FormData();
    formData.append('file', blob, 'signature.png');
    $.ajax({
        url: `${BASE_URL}/api/employee.upload-signature`,
        method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) {
                $('#emp_signature_path').val(res.signature_path);
                $('#emp_signature_file_size').val(res.file_size || '');
                showEmpSignaturePreview(res.signature_path);
            } else {
                showWarning(res.message || langData['save_failed'] || 'Upload failed.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); }
    });
}
$(document).on('change', '#emp_signature_file', function () {
    const file = this.files && this.files[0];
    if (!file) return;
    uploadEmpSignatureBlob(file);
    $(this).val('');
});
$(document).on('click', '#empSignatureRemoveBtn', function () {
    $('#emp_signature_path').val('');
    $('#emp_signature_file_size').val('');
    showEmpSignaturePreview(null);
});

let empSignaturePadCtx = null;
let empSignaturePadDrawing = false;
let empSignaturePadHasStrokes = false;
function empSignaturePadPos(canvas, e) {
    const rect = canvas.getBoundingClientRect();
    const point = (e.touches && e.touches[0]) ? e.touches[0] : e;
    return {
        x: (point.clientX - rect.left) * (canvas.width / rect.width),
        y: (point.clientY - rect.top) * (canvas.height / rect.height)
    };
}
function initEmpSignaturePad() {
    const canvas = document.getElementById('empSignaturePadCanvas');
    if (!canvas) return;
    empSignaturePadCtx = canvas.getContext('2d');
    empSignaturePadCtx.fillStyle = '#ffffff';
    empSignaturePadCtx.fillRect(0, 0, canvas.width, canvas.height);
    empSignaturePadCtx.lineWidth = 2.5;
    empSignaturePadCtx.lineCap = 'round';
    empSignaturePadCtx.strokeStyle = '#1a1a1a';
    empSignaturePadHasStrokes = false;
    const startDraw = function (e) {
        e.preventDefault();
        empSignaturePadDrawing = true;
        const p = empSignaturePadPos(canvas, e);
        empSignaturePadCtx.beginPath();
        empSignaturePadCtx.moveTo(p.x, p.y);
    };
    const moveDraw = function (e) {
        if (!empSignaturePadDrawing) return;
        e.preventDefault();
        const p = empSignaturePadPos(canvas, e);
        empSignaturePadCtx.lineTo(p.x, p.y);
        empSignaturePadCtx.stroke();
        empSignaturePadHasStrokes = true;
    };
    const endDraw = function () { empSignaturePadDrawing = false; };
    canvas.onmousedown = startDraw;
    canvas.onmousemove = moveDraw;
    canvas.onmouseup = endDraw;
    canvas.onmouseleave = endDraw;
    canvas.ontouchstart = startDraw;
    canvas.ontouchmove = moveDraw;
    canvas.ontouchend = endDraw;
}
$(document).on('click', '#empDrawSignatureBtn', function () {
    new bootstrap.Modal(document.getElementById('empSignaturePadModal')).show();
});
$('#empSignaturePadModal').on('shown.bs.modal', function () { initEmpSignaturePad(); });
$(document).on('click', '#empSignaturePadClearBtn', function () { initEmpSignaturePad(); });
$(document).on('click', '#empSignaturePadSaveBtn', function () {
    const canvas = document.getElementById('empSignaturePadCanvas');
    if (!canvas) return;
    if (!empSignaturePadHasStrokes) {
        showWarning(langData['draw_signature_hint'] || 'Draw with your mouse or finger, then click Save.');
        return;
    }
    canvas.toBlob(function (blob) {
        if (!blob) return;
        uploadEmpSignatureBlob(blob);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('empSignaturePadModal')).hide();
    }, 'image/png');
});

/* ============================================================================================
   2026-08-26, explicit request: "ส่วนของที่อยู่ให้เพิ่มสามารถปักหมุด Location บน Map ได้" -- OpenStreetMap
   + Leaflet pin picker for the CONTACT address. Nominatim (OSM's own free geocoder, no API key) backs
   the search box; clicking the map or dragging the marker sets the pin. Bangkok is just a reasonable
   starting view when no pin exists yet, not a default value that gets saved on its own -- Save only
   ever persists a pin the admin actually placed/moved.
   ============================================================================================ */
let empMapPinInstance = null;
let empMapPinMarker = null;
let empMapPinLatLng = null;
function empMapSetMarker(lat, lng) {
    empMapPinLatLng = { lat, lng };
    if (empMapPinMarker) {
        empMapPinMarker.setLatLng([lat, lng]);
    } else {
        empMapPinMarker = L.marker([lat, lng], { draggable: true }).addTo(empMapPinInstance);
        empMapPinMarker.on('dragend', function () {
            const pos = empMapPinMarker.getLatLng();
            empMapPinLatLng = { lat: pos.lat, lng: pos.lng };
        });
    }
}
function initEmpMapPin() {
    const container = document.getElementById('empMapPinContainer');
    if (!container || typeof L === 'undefined') return;
    const existingLat = parseFloat($('#address_latitude').val());
    const existingLng = parseFloat($('#address_longitude').val());
    const hasExisting = !isNaN(existingLat) && !isNaN(existingLng);
    const startLat = hasExisting ? existingLat : 13.7563;
    const startLng = hasExisting ? existingLng : 100.5018;
    if (!empMapPinInstance) {
        empMapPinInstance = L.map('empMapPinContainer').setView([startLat, startLng], hasExisting ? 16 : 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(empMapPinInstance);
        empMapPinInstance.on('click', function (e) {
            empMapSetMarker(e.latlng.lat, e.latlng.lng);
        });
    } else {
        empMapPinInstance.setView([startLat, startLng], hasExisting ? 16 : 11);
    }
    empMapPinMarker = null;
    empMapPinLatLng = null;
    if (hasExisting) {
        empMapSetMarker(startLat, startLng);
    }
    // Leaflet computes its tile grid against the container's size at init time -- inside a
    // just-shown Bootstrap modal that size wasn't final yet the very first time this ever runs, so
    // recompute once the modal's own show animation has actually finished.
    setTimeout(function () { if (empMapPinInstance) empMapPinInstance.invalidateSize(); }, 200);
}
$(document).on('click', '#btnPinMapLocation', function () {
    new bootstrap.Modal(document.getElementById('empMapPinModal')).show();
});
$('#empMapPinModal').on('shown.bs.modal', function () { initEmpMapPin(); });
$(document).on('click', '#empMapPinSaveBtn', function () {
    if (!empMapPinLatLng) {
        showWarning(langData['map_pin_hint'] || 'Click anywhere on the map, or drag the marker, to set the location.');
        return;
    }
    $('#address_latitude').val(empMapPinLatLng.lat.toFixed(7)).trigger('change');
    $('#address_longitude').val(empMapPinLatLng.lng.toFixed(7)).trigger('change');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('empMapPinModal')).hide();
});
// 2026-08-26, explicit request: "ถ้ามี Pin Location แล้วให้สามารถลบ Pin ได้ด้วยมีสัญลักษร์บอกว่า Pin
// หรือยังไม่ Pin" -- toggles the pinned/not-pinned icon pair and the Remove button, alongside the
// existing coordinate summary text.
function updateMapPinStatusUi() {
    const lat = parseFloat($('#address_latitude').val());
    const lng = parseFloat($('#address_longitude').val());
    const pinned = !isNaN(lat) && !isNaN(lng);
    $('#mapLocationSummary').text(pinned ? `${lat.toFixed(5)}, ${lng.toFixed(5)}` : '');
    $('#mapPinStatusIconPinned').toggleClass('d-none', !pinned);
    $('#mapPinStatusIconUnpinned').toggleClass('d-none', pinned);
    $('#btnRemoveMapPin').toggleClass('d-none', !pinned);
}
$(document).on('change', '#address_latitude, #address_longitude', updateMapPinStatusUi);
$(document).on('click', '#btnRemoveMapPin', function () {
    $('#address_latitude').val('').trigger('change');
    $('#address_longitude').val('').trigger('change');
});
// Debounced Nominatim search -- free OSM geocoder, no API key. Shows the first match's own bounding
// box zoom level rather than a fixed one, so a country-level search doesn't zoom in absurdly close.
let empMapSearchTimer = null;
$(document).on('input', '#empMapSearchInput', function () {
    const query = $(this).val().trim();
    clearTimeout(empMapSearchTimer);
    if (query.length < 3) return;
    empMapSearchTimer = setTimeout(function () {
        $.ajax({
            url: 'https://nominatim.openstreetmap.org/search',
            method: 'GET', dataType: 'json',
            data: { q: query, format: 'json', limit: 1 },
            success: function (results) {
                if (!Array.isArray(results) || !results.length || !empMapPinInstance) return;
                const lat = parseFloat(results[0].lat);
                const lng = parseFloat(results[0].lon);
                empMapPinInstance.setView([lat, lng], 16);
                empMapSetMarker(lat, lng);
            }
        });
    }, 600);
});
