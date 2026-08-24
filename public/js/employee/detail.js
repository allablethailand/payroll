let currentEmployeeId = null;
let childTablesLoaded = false;
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
function applyPaymentTypeRequired(type) {
    $('#bank_id, #bank_account_no').toggleClass('required', type === 'bank').removeClass('is-invalid');
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
    return data;
}
function populateEmployeeForm(data) {
    const remoteFields = ['department_id', 'role_id', 'position_id', 'branch_id', 'bank_id', 'report_to_id', 'nationality', 'religion', 'cycle_id', 'work_location_id', 'shift_id'];
    Object.keys(data).forEach(function (key) {
        if (remoteFields.indexOf(key) !== -1) return;
        const $el = $(`#employeeTabsContent [name="${key}"]`);
        if (!$el.length) return;
        if ($el.is(':checkbox')) {
            $el.prop('checked', data[key] == 1 || data[key] === true).trigger('change');
            return;
        }
        if ($el.hasClass('datepicker')) {
            $el.val(toDisplayDate(data[key]));
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
    if (data.payment_type) {
        $(`input[name="payment_type_radio"][value="${data.payment_type}"]`).prop('checked', true).trigger('change');
    }
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
    populateSelect2Field('role_id', data.role_id, data.role_name_th, data.role_name_en);
    populateSelect2Field('position_id', data.position_id, data.position_name_th, data.position_name_en);
    populateSelect2Field('branch_id', data.branch_id, data.branch_name_th, data.branch_name_en);
    if (data.bank_id) {
        const prefix = data.bank_code ? `${data.bank_code} - ` : '';
        populateSelect2Field('bank_id', data.bank_id, prefix + (data.bank_name_th || ''), prefix + (data.bank_name_en || ''));
    }
    populateSelect2Field('report_to_id', data.report_to_id, data.report_to_name_th, data.report_to_name_en);
    populateSelect2Field('cycle_id', data.cycle_id, data.cycle_name, data.cycle_name);
    // Real bug fix (2026-08-19, reported as "Employment tab data disappears after save"): these two
    // were never in remoteFields nor explicitly populated, so on every reload they fell through the
    // generic loop's plain .val(id) -- which does nothing on a select2-remote with no matching
    // <option> loaded yet (ajax mode only loads options on search). The select LOOKED empty, and the
    // next save then submitted that empty value, silently overwriting the real saved value with NULL.
    populateSelect2Field('work_location_id', data.work_location_id, data.location_name_th, data.location_name_en);
    populateSelect2Field('shift_id', data.shift_id, data.shift_name_th, data.shift_name_en);
    $('#search_address_register').val((currentLang === 'th' ? data.address_display_th_register : data.address_display_en_register) || '');
    $('#search_address_contact').val((currentLang === 'th' ? data.address_display_th_contact : data.address_display_en_contact) || '');
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
    }
}
function saveEmployee($btn) {
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
    $('#profileHeaderAvatar').text((name.charAt(0) || '?').toUpperCase());
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

    $('#employeeProfileHeader').removeClass('d-none');
}
function refreshProfileHeader() {
    const employeeNo = $('#employee_no').val();
    if (!employeeNo) return;
    $.getJSON(`${BASE_URL}/api/employee.get`, { employee_no: employeeNo }, function (res) {
        if (res.status && res.data) renderProfileHeader(res.data);
    });
}
function loadEmployeeIfEditing() {
    const employeeNo = $('#employee_no').val();
    if (!employeeNo) return;
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
            } else {
                showWarning(res.message || langData['employee_not_found'] || 'Employee not found.');
            }
        },
        error: function () {
            showWarning(langData['load_employee_failed'] || 'Failed to load employee data.');
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
    });
    $('input[name="payment_type_radio"]').on('change', function () {
        const type = $(this).val();
        $('#payment_type').val(type);
        $('#sectionBankPayment').toggleClass('d-none', type !== 'bank');
        applyPaymentTypeRequired(type);
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
        const reader = new FileReader();
        reader.onload = function (ev) {
            $('#profilePreview').attr('src', ev.target.result).removeClass('d-none');
            $('#profilePlaceholder').addClass('d-none');
        };
        reader.readAsDataURL(file);
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
    $('#btnNextSalary').on('click', function () { saveEmployee($(this)); });
    $('#btnNextSocial').on('click', function () { saveEmployee($(this)); });
    $('#btnNextFamily').on('click', function () { saveEmployee($(this)); });
    // 2026-08-20, explicit request ("ตัดให้เหลือปุ่ม Save แค่ปุ่มเดียว"): the Family tab's own
    // button (physically inside #family-pane, historical id aside -- see the 2026-08-19 comment
    // above) now saves spouse + father/mother + every dependent card together -- see
    // saveFamilyTab(). Every other tab's button is untouched, still the plain per-tab saveEmployee().
    $('#btnNextDocuments').on('click', function () { saveFamilyTab($(this)); });
    loadEmployeeIfEditing();
    initChildTables();
    initDocumentUpload();
    initEedUI();
});

function escapeHtml(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
const DOCUMENT_INPUT_MAP = {
    doc_id_card_copy: 'id_card_copy',
    doc_house_registration_copy: 'house_registration_copy',
    doc_work_permit_copy: 'work_permit_copy',
    doc_employment_contract: 'employment_contract',
    doc_bank_book_copy: 'bank_book_copy',
    doc_resume: 'resume',
    doc_education_certificate: 'education_certificate',
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
    other: 'other_documents'
};
const ALLOWED_DOC_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
const MAX_DOC_SIZE = 10 * 1024 * 1024;
function addDocumentRow(doc) {
    const labelKey = DOCUMENT_TYPE_LABEL_KEY[doc.document_type] || doc.document_type;
    const typeLabel = langData[labelKey] || doc.document_type;
    const $tr = $(
        '<tr>' +
        `<td>${escapeHtml(doc.file_name)}</td>` +
        `<td data-i18n="${labelKey}">${escapeHtml(typeLabel)}</td>` +
        `<td>${escapeHtml(doc.uploaded_at || '')}</td>` +
        '<td class="text-center">' +
        '<div class="btn-group border rounded-3 bg-white">' +
        `<a href="${BASE_URL}/api/employee.document.view?id=${encodeURIComponent(doc.id)}" target="_blank" class="btn btn-link text-info"><i class="fa-solid fa-eye"></i></a>` +
        '<button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-document"><i class="fa-solid fa-trash-can"></i></button>' +
        '</div>' +
        '</td>' +
        '</tr>'
    );
    $tr.attr('data-id', doc.id).data('id', doc.id);
    $('#tableDocumentList tbody').append($tr);
}
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
function removeDependentCards($toRemove, onCancelled) {
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
    if (anyHasData) {
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
        removeDependentCards($cards.slice(-(current - target)), function () {
            $('#dependentCount').val(current);
        });
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
    // Button-group wrapper (2026-08-21, explicit request: "ปุ่มในตารางทั้งหมด ปรับให้เป็น button
    // group ให้หมดเหมือนหน้า Employee") -- same idiom as public/js/employee/list.js's row actions
    // (.btn-group.border.rounded-3.bg-white, btn-link buttons, border-start divider on every button
    // after the first) instead of a manually-gapped flex row.
    let html = '<div class="btn-group border rounded-3 bg-white">';
    // View is always available, Edit only while nothing has been paid yet (2026-08-20, explicit
    // request: "Status ของแต่ละงวดการจ่าย...จ่ายแล้วหรือรอจ่าย") -- once current_installment > 0
    // save() permanently blocks edits (see EmployeeEarningDeductionModel::save()), so this is the
    // only way to see the per-installment paid/pending schedule for an assignment already in
    // progress or finished. Same modal, populateEedForm(row, true) just disables everything.
    html += `<button type="button" class="btn btn-link text-info btn-view-eed" data-id="${row.id}" title="${langData['view'] || 'View'}"><i class="fa-solid fa-eye"></i></button>`;
    if (isOpen && notStarted) {
        html += `<button type="button" class="btn btn-link text-warning border-start btn-edit-eed" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen-to-square"></i></button>`;
    }
    if (isOpen) {
        if (row.status === 'active') {
            html += `<button type="button" class="btn btn-link text-warning border-start btn-eed-status" data-id="${row.id}" data-status="paused" title="${langData['pause_item'] || 'Pause'}"><i class="fa-solid fa-pause"></i></button>`;
        } else {
            html += `<button type="button" class="btn btn-link text-success border-start btn-eed-status" data-id="${row.id}" data-status="active" title="${langData['resume_item'] || 'Resume'}"><i class="fa-solid fa-play"></i></button>`;
        }
        html += `<button type="button" class="btn btn-link text-danger border-start btn-eed-status" data-id="${row.id}" data-status="cancelled" title="${langData['cancel_item'] || 'Cancel'}"><i class="fa-solid fa-ban"></i></button>`;
    }
    if (isOpen && notStarted) {
        html += `<button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-eed" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
    }
    html += '</div>';
    return html;
}
function eedInterestSubLabel(row) {
    if (!row.interest_type || row.interest_type === 'none') return '';
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
    const badge = row.ped_type_id ? '' : ` <span class="badge bg-secondary-subtle text-secondary">${langData['manual_line_custom_badge'] || 'Custom'}</span>`;
    const codeLine = row.item_code ? escapeHtml(row.item_code) : '';
    const payeeTag = row.payee_employee_id
        ? `<div class="text-muted small"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>${langData['payee_transfer_tag'] || 'Paid to'} ${escapeHtml(row.payee_employee_no || ('#' + row.payee_employee_id))}</div>`
        : '';
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
        { data: null, render: (d, t, row) => eedAmountSummary(row) },
        { data: null, className: 'text-center', render: (d, t, row) => eedInstallmentProgressCell(row) },
        { data: 'effective_date', render: d => toDisplayDate(d) },
        { data: null, render: (d, t, row) => eedStatusBadge(row) },
        { data: null, orderable: false, className: 'text-center', render: (d, t, row) => eedActionButtons(row) }
    ];
}
let tbEarning, tbDeduction;
// Two client-side DataTables (2026-08-19, explicit request: "แยกเป็น 2 ตาราง" + "Format ของ
// Datatable") -- per-employee item count is always small, matching this project's client-side
// DataTable convention. Add button injected into .dt-search via initComplete, same as every other
// DataTable in this app. Filtered server-side by item_type (EmployeeEarningDeductionModel::list()).
function initEedTable(tableSelector, itemType, addBtnClass, addLangKey, addLangFallback) {
    return $(tableSelector).DataTable({
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
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find(`.${addBtnClass}`).length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary btn-sm ms-1 ${addBtnClass}">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="${addLangKey}">${langData[addLangKey] || addLangFallback}</span>
                    </button>
                `);
            }
        }
    });
}
function loadEarningDeductions() {
    if ($.fn.DataTable.isDataTable('#tableEarning')) $('#tableEarning').DataTable().ajax.reload(null, false);
    if ($.fn.DataTable.isDataTable('#tableDeduction')) $('#tableDeduction').DataTable().ajax.reload(null, false);
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
    const dateSuffix = (status === 'processed' && processedAt) ? ` <span class="text-muted small">${toDisplayDate(String(processedAt).substring(0, 10))}</span>` : '';
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
}
let eedReadOnly = false;
let eedPreviewTimer = null;
function eedInterestState() {
    const hasInterest = $('#eedInterestToggle button.active').data('value') === 'has_interest';
    return {
        hasInterest: hasInterest,
        interestType: hasInterest ? ($('#eedInterestTypeToggle button.active').data('value') || 'fixed') : 'none',
        interestRate: hasInterest ? parseFloat($('#eed_interest_rate').val() || '0') : null
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
    const { interestType, interestRate } = eedInterestState();
    if (interestType !== 'none' && !(interestRate > 0)) {
        return; // wait for a valid rate rather than previewing a misleading no-interest split
    }
    $.ajax({
        url: `${BASE_URL}/api/employee.earning-deduction.preview-installments`,
        method: 'GET',
        data: { principal: principal, total_installments: totalInstallments, interest_type: interestType, interest_rate: interestRate },
        dataType: 'json',
        success: function (res) {
            if (res.status && res.data && res.data.amounts) {
                renderInstallmentTable(res.data.amounts, null, false);
            }
        }
    });
}
function setEedInterestOn(on) {
    $('#eedInterestToggle button').removeClass('active').filter(`[data-value="${on ? 'has_interest' : 'none'}"]`).addClass('active');
    $('#eedInterestDetailWrapper').toggleClass('d-none', !on);
    $('#eed_interest_rate').toggleClass('required', on);
    $('#eed_principal_amount_label [data-i18n="total_amount"]').toggleClass('d-none', on);
    $('#eed_principal_amount_label [data-i18n="principal_amount_label"]').toggleClass('d-none', !on);
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
        setEedInterestOn(false);
    }
    // Transfer-to-payee (2026-08-21) piggybacks on the same deduction-only toggle point as interest
    // above, rather than a parallel visibility mechanism -- both only make sense on a deduction.
    $('#eedPayeeWrapper').toggleClass('d-none', !isDeduction);
    if (!isDeduction) {
        $('#eed_payee_employee_id').val(null).trigger('change');
    }
}
// Catalog vs custom item toggle (2026-08-19, explicit request). #eed_ped_type_id stays required only
// in catalog mode, the custom pair only in custom mode -- validateEedForm() already skips anything
// inside a .d-none ancestor, so toggling both visibility and .required here is enough, no change
// needed there.
function setEedMode(mode) {
    $('#eedModeToggle button').removeClass('active').filter(`[data-mode="${mode}"]`).addClass('active');
    $('#eedCatalogFields').toggleClass('d-none', mode !== 'catalog');
    $('#eedCustomFields').toggleClass('d-none', mode !== 'custom');
    $('#eed_ped_type_id').toggleClass('required', mode === 'catalog');
    $('#eed_custom_item_name').toggleClass('required', mode === 'custom');
}
// Toggles the whole modal between editable (Add / not-yet-started Edit) and read-only (View, for an
// assignment that already has paid/skipped installments -- save() permanently blocks editing those,
// see eedActionButtons()) -- one code path for both instead of a separate view-only modal.
function setEedReadOnly(readOnly) {
    eedReadOnly = readOnly;
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
    setEedInterestOn(false);
    setEedInterestType('fixed');
    $('#eed_interest_rate').val('');
    // An employee can't be their own transfer payee -- excluded from the picker's own results the
    // same way #report_to_id already excludes self elsewhere (data-exclude-id, read fresh on every
    // ajax search by initSelect2's shared 'ajax' mode).
    $('#eed_payee_employee_id').attr('data-exclude-id', currentEmployeeId || '').val(null).trigger('change');
    applyEedInterestVisibility();
    renderInstallmentTable([], null, false);
    $('#eedModalLabel').text(eedModalTitle('add'));
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
        setEedMode('custom');
        $('#eed_custom_item_name').val(row.item_name_th || row.item_name_en || '');
    }
    $('#eed_effective_date').val(toDisplayDate(row.effective_date));
    $('#eed_total_installments').val(row.total_installments);
    $('#eed_principal_amount').val(row.principal_amount != null ? row.principal_amount : row.total_amount);
    $('#eed_notes').val(row.notes || '');
    $('#eed_external_reference_no').val(row.external_reference_no || '');
    if (row.payee_employee_id) {
        const payeeLabel = row.payee_employee_no || `#${row.payee_employee_id}`;
        $('#eed_payee_employee_id').empty().append(new Option(payeeLabel, row.payee_employee_id, true, true)).trigger('change');
    } else {
        $('#eed_payee_employee_id').val(null).trigger('change');
    }
    const hasInterest = !!row.interest_type && row.interest_type !== 'none';
    setEedInterestOn(hasInterest);
    setEedInterestType(hasInterest ? row.interest_type : 'fixed');
    $('#eed_interest_rate').val(row.interest_rate || '');
    applyEedInterestVisibility();
    const amounts = (row.installments || []).map(i => i.amount);
    renderInstallmentTable(amounts, row.installments || [], !!readOnly);
    setEedReadOnly(!!readOnly);
    $('#eedModalLabel').text(eedModalTitle(readOnly ? 'view' : 'edit'));
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
    const { hasInterest, interestType, interestRate } = eedInterestState();
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
        payee_employee_id: $('#eed_payee_employee_id').val() || undefined
    };
    if (hasInterest) {
        data.interest_rate = interestRate;
    }
    if (mode === 'custom') {
        data.custom_item_name = $('#eed_custom_item_name').val().trim();
        data.custom_item_type = $('#eed_custom_item_type').val();
    } else {
        data.ped_type_id = $('#eed_ped_type_id').val();
    }
    return data;
}
function initEedUI() {
    tbEarning = initEedTable('#tableEarning', 'earning', 'btn-add-earning', 'add_earning_item', 'Earning');
    tbDeduction = initEedTable('#tableDeduction', 'deduction', 'btn-add-deduction', 'add_deduction_item', 'Deduction');
    // Hidden-tab-at-init width gotcha: neither is the active tab/sub-tab on page load, so both
    // tables above compute their column widths against a zero-width container -- readjust once
    // actually visible (cheap/idempotent, DataTables no-ops if nothing changed). Two triggers needed
    // since #tableDeduction sits inside its own nested pill sub-tab (2026-08-19: Earning/Deduction
    // split out into their own tab with Earning/Deduction sub-tabs) -- becoming visible requires BOTH
    // the outer tab AND the inner "Deduction" pill to have been shown at least once.
    document.getElementById('earningDeduction-tab').addEventListener('shown.bs.tab', function () {
        if (tbEarning) tbEarning.columns.adjust();
        if (tbDeduction) tbDeduction.columns.adjust();
    });
    document.getElementById('eedDeductionSub-tab').addEventListener('shown.bs.tab', function () {
        if (tbDeduction) tbDeduction.columns.adjust();
    });
    if (typeof initSelect2 === 'function') {
        initSelect2('#eed_ped_type_id', { mode: 'ajax' });
        // Initialized once here, not per-modal-open (2026-08-21 bug fix precedent from the
        // Attendance Deduction rate_unit dropdown: re-initializing a select2 field every time a
        // modal opens can leave stale state/duplicate options behind -- matches #eed_ped_type_id's
        // own established once-at-page-load pattern directly above).
        initSelect2('#eed_payee_employee_id', { mode: 'ajax', allowClear: true });
    }
    $(document).on('click', '#eedModeToggle button', function () {
        setEedMode($(this).data('mode'));
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
        setEedInterestOn($(this).data('value') === 'has_interest');
    });
    $(document).on('click', '#eedInterestTypeToggle button', function () {
        setEedInterestType($(this).data('value'));
    });
    $(document).on('input change', '#eed_total_installments, #eed_principal_amount, #eed_interest_rate', function () {
        scheduleEedPreviewFetch();
    });
    $(document).on('submit', '#eedForm', function (e) {
        e.preventDefault();
        const invalidEl = validateEedForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
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
