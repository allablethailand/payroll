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
function applyEmployeeTypeRequired(type) {
    $('#id_card_no').toggleClass('required', type === 'domestic').removeClass('is-invalid');
    $('#tax_id_no, #passport_no, #work_permit_no, #date_work_permit_issue, #date_work_permit_expire')
        .toggleClass('required', type === 'foreigner').removeClass('is-invalid');
}
function applyPaymentTypeRequired(type) {
    $('#bank_id, #bank_account_no').toggleClass('required', type === 'bank').removeClass('is-invalid');
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
                // New-employee flow (2026-08-19, explicit request): the FIRST save that actually
                // creates the record unlocks the rest of the tabs + the 3rd breadcrumb level -- every
                // save (this one included) stays on whichever tab's Save button was clicked, it never
                // auto-navigates anywhere.
                if (wasNew && res.id) {
                    $('.employee-secondary-tab').removeClass('d-none');
                    $('#bcSeparatorCurrent, #bcCurrent').removeClass('d-none');
                }
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
    $('#sso_enrolled').on('change', function () {
        const checked = $(this).is(':checked');
        $('#ssoDetailFields').toggleClass('d-none', !checked);
        $('#ssoEnrolledToggle button').removeClass('active').filter(`[data-value="${checked ? 'yes' : 'no'}"]`).addClass('active');
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
    $('#btnNextDocuments').on('click', function () { saveEmployee($(this)); });
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
        `<a href="${BASE_URL}/api/employee.document.view?id=${encodeURIComponent(doc.id)}" target="_blank" class="btn btn-sm btn-link text-primary"><i class="fa-solid fa-eye"></i></a>` +
        '<button type="button" class="btn btn-sm btn-link text-danger btn-delete-document"><i class="fa-solid fa-trash-can"></i></button>' +
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
// Dependents only -- Parents became a fixed Father/Mother 2-slot form (2026-08-19, explicit request:
// "มีพ่อแม่แค่ 2 คน ให้มีคำถามว่า ใช้แม่ลดหย่อนไหม ใช้พ่อลดหย่อนไหม"), see PARENT_SLOTS below. Kept
// this generic card+modal+count system for Dependents since a genuinely unknown/open-ended number of
// children is exactly what it was built for.
const CHILD_ENTITY_CONFIG = {
    dependent: {
        cardsContainerId: '#dependentCardsContainer', addBtnId: '#btnAddDependent', addCountId: '#dependentAddCount',
        hasToggleId: '#hasChildrenToggle', sectionId: '#childrenSection', emptyHintId: '#dependentEmptyHint', apiEntity: 'dependent',
        relationshipKeys: ['relationship_child_legitimate', 'relationship_child_adopted'],
        relationshipValues: ['child_legitimate', 'child_adopted'],
        hasDob: true, hasStudying: true,
    }
};
// Cached per-entity list from the last loadAllChildTables() call -- looked up by id for Edit. Safe
// here (unlike DataTables rows) since this is a plain one-shot list render, not paginated/sorted/
// searched, so nothing else can silently make the cache stale between a render and a click. 'parent'
// still cached here too (by loadParentSlots()) even though it's no longer in CHILD_ENTITY_CONFIG.
let childRealRows = { dependent: [], parent: [] };
// How many blank "click to fill in" placeholder cards are currently shown, purely client-side (not
// persisted) -- see the Add button handler below and the markup's own comment for the full reasoning.
let childBlankCount = { dependent: 0 };
// Fixed Father/Mother slots (2026-08-19, explicit request) -- still backed by employee_parents /
// EmployeeModel::saveChild('parent', ...), just with `relationship` fixed per slot instead of a
// dropdown choice, and the UI as 2 inline forms instead of an open-ended card list.
const PARENT_SLOTS = {
    father: {
        toggleId: '#useFatherToggle', fieldsId: '#fatherDetailFields', idInput: '#parent_father_id',
        nameInput: '#parent_father_name', idCardInput: '#parent_father_id_card_no',
        saveBtnId: '#btnSaveFather', deleteBtnId: '#btnDeleteFather',
    },
    mother: {
        toggleId: '#useMotherToggle', fieldsId: '#motherDetailFields', idInput: '#parent_mother_id',
        nameInput: '#parent_mother_name', idCardInput: '#parent_mother_id_card_no',
        saveBtnId: '#btnSaveMother', deleteBtnId: '#btnDeleteMother',
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

function relationshipLabel(entityKey, value) {
    const cfg = CHILD_ENTITY_CONFIG[entityKey];
    const idx = cfg.relationshipValues.indexOf(value);
    return idx === -1 ? (value || '') : (langData[cfg.relationshipKeys[idx]] || value);
}
function childCardHtml(entityKey, row) {
    const cfg = CHILD_ENTITY_CONFIG[entityKey];
    let extra = '';
    if (row.id_card_no) extra += `<div class="text-muted small mt-1">${escapeHtml(row.id_card_no)}</div>`;
    if (cfg.hasDob && row.date_of_birth) extra += `<div class="text-muted small">${toDisplayDate(row.date_of_birth)}</div>`;
    const studyingBadge = (cfg.hasStudying && (row.studying == 1 || row.studying === true))
        ? ` <span class="badge bg-info-subtle text-info">${langData['studying'] || 'Studying'}</span>` : '';
    return `
        <div class="col-sm-4 mb-3">
            <div class="card-surface p-3 h-100">
                <div class="fw-bold">${escapeHtml(row.name || '')}</div>
                <span class="badge bg-secondary-subtle text-secondary">${escapeHtml(relationshipLabel(entityKey, row.relationship))}</span>${studyingBadge}
                ${extra}
                <div class="d-flex justify-content-end gap-1 mt-2">
                    <button type="button" class="btn btn-sm btn-link text-secondary btn-edit-child" data-id="${row.id}" data-entity="${entityKey}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button type="button" class="btn btn-sm btn-link text-danger btn-delete-child" data-id="${row.id}" data-entity="${entityKey}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </div>
        </div>`;
}
function blankChildCardHtml(entityKey) {
    return `
        <div class="col-sm-4 mb-3">
            <div class="card-surface p-3 h-100 d-flex flex-column align-items-center justify-content-center text-center" style="border:2px dashed rgba(255,153,0,.35);">
                <i class="fa-solid fa-user-plus text-secondary mb-2"></i>
                <button type="button" class="btn btn-sm btn-outline-brand btn-fill-child" data-entity="${entityKey}"><span data-i18n="click_to_fill_in">${langData['click_to_fill_in'] || 'Click to fill in'}</span></button>
            </div>
        </div>`;
}
function renderChildCards(entityKey) {
    const cfg = CHILD_ENTITY_CONFIG[entityKey];
    const totalCount = childRealRows[entityKey].length + childBlankCount[entityKey];
    let html = '';
    childRealRows[entityKey].forEach(function (row) { html += childCardHtml(entityKey, row); });
    for (let i = 0; i < childBlankCount[entityKey]; i++) { html += blankChildCardHtml(entityKey); }
    $(cfg.cardsContainerId).html(html);
    $(cfg.emptyHintId).toggleClass('d-none', totalCount > 0);
    if (typeof updateText === 'function') updateText($(cfg.cardsContainerId)[0]);
    // Auto-flip the has-X toggle to Yes + reveal the section once any real (or in-progress blank)
    // card exists -- an employee who already has dependents on file shouldn't load with the section
    // collapsed behind a "No" that doesn't match their actual data.
    if (totalCount > 0) {
        $(`${cfg.hasToggleId} button`).removeClass('active').filter('[data-value="yes"]').addClass('active');
        $(cfg.sectionId).removeClass('d-none');
    }
}
function loadAllChildTables() {
    Object.keys(CHILD_ENTITY_CONFIG).forEach(function (entityKey) {
        const cfg = CHILD_ENTITY_CONFIG[entityKey];
        $.ajax({
            url: `${BASE_URL}/api/employee.${cfg.apiEntity}.list`,
            method: 'GET',
            data: { employee_id: currentEmployeeId },
            dataType: 'json',
            success: function (res) {
                childRealRows[entityKey] = (res.status && res.data) ? res.data : [];
                renderChildCards(entityKey);
            }
        });
    });
    loadParentSlots();
    loadEarningDeductions();
}
function resetChildForm(entityKey) {
    $('#childForm')[0].reset();
    $('#child_id').val('');
    $('#child_entity').val(entityKey);
    const cfg = CHILD_ENTITY_CONFIG[entityKey];
    $('#childDobRow').toggleClass('d-none', !cfg.hasDob);
    $('#childStudyingRow').toggleClass('d-none', !cfg.hasStudying);
    $('#child_relationship').attr('data-option-keys', cfg.relationshipKeys.join(',')).attr('data-option-values', cfg.relationshipValues.join(','));
    if (typeof initSelect2 === 'function') initSelect2('#child_relationship', { mode: 'static' });
    $('#child_relationship').val(null).trigger('change');
    $('.is-invalid').removeClass('is-invalid');
    $('#childModalLabel').text((entityKey === 'dependent' ? langData['add_dependent'] : langData['add_parent']) || 'Add');
}
function populateChildForm(entityKey, row) {
    const cfg = CHILD_ENTITY_CONFIG[entityKey];
    $('#child_id').val(row.id);
    $('#child_name').val(row.name || '');
    $('#child_id_card_no').val(row.id_card_no || '');
    if (cfg.hasDob) $('#child_date_of_birth').val(toDisplayDate(row.date_of_birth));
    $('#child_relationship').val(row.relationship).trigger('change');
    if (cfg.hasStudying) $('#child_studying').prop('checked', row.studying == 1 || row.studying === true);
    $('#childModalLabel').text(langData['edit'] || 'Edit');
}
function validateChildForm() {
    let firstInvalid = null;
    $('#childModal .required').each(function () {
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
function collectChildFormData(entityKey) {
    const cfg = CHILD_ENTITY_CONFIG[entityKey];
    const data = {
        id: $('#child_id').val() || undefined,
        employee_id: currentEmployeeId,
        name: $('#child_name').val().trim(),
        id_card_no: $('#child_id_card_no').val().trim(),
        relationship: $('#child_relationship').val(),
    };
    if (cfg.hasDob) data.date_of_birth = toIsoDate($('#child_date_of_birth').val());
    if (cfg.hasStudying) data.studying = $('#child_studying').is(':checked');
    return data;
}
function initChildTables() {
    Object.keys(CHILD_ENTITY_CONFIG).forEach(function (entityKey) {
        const cfg = CHILD_ENTITY_CONFIG[entityKey];
        $(cfg.hasToggleId + ' button').on('click', function () {
            const val = $(this).data('value');
            $(cfg.hasToggleId + ' button').removeClass('active').filter(`[data-value="${val}"]`).addClass('active');
            // Just collapses the section from view -- never deletes existing dependent/parent rows,
            // which live entirely in their own table and are untouched by this toggle either way.
            $(cfg.sectionId).toggleClass('d-none', val !== 'yes');
        });
        $(cfg.addBtnId).on('click', function () {
            if (!currentEmployeeId) {
                showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
                return;
            }
            const count = Math.max(1, parseInt($(cfg.addCountId).val() || '1', 10));
            childBlankCount[entityKey] += count;
            renderChildCards(entityKey);
        });
    });
    $(document).on('click', '.btn-fill-child', function () {
        const entityKey = $(this).data('entity');
        resetChildForm(entityKey);
        new bootstrap.Modal(document.getElementById('childModal')).show();
    });
    $(document).on('click', '.btn-edit-child', function () {
        const entityKey = $(this).data('entity');
        const id = $(this).data('id');
        const row = (childRealRows[entityKey] || []).find(r => String(r.id) === String(id));
        if (!row) return;
        resetChildForm(entityKey);
        populateChildForm(entityKey, row);
        new bootstrap.Modal(document.getElementById('childModal')).show();
    });
    $(document).on('submit', '#childForm', function (e) {
        e.preventDefault();
        const entityKey = $('#child_entity').val();
        const cfg = CHILD_ENTITY_CONFIG[entityKey];
        const invalidEl = validateChildForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const isNew = !$('#child_id').val();
        const payload = collectChildFormData(entityKey);
        const $btn = $('#childForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/employee.${cfg.apiEntity}.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('childModal')).hide();
                    // This blank slot just became a real saved row -- shrink the placeholder count so
                    // it isn't counted twice once the real row comes back from loadAllChildTables().
                    if (isNew && childBlankCount[entityKey] > 0) childBlankCount[entityKey]--;
                    loadAllChildTables();
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
    $(document).on('click', '.btn-delete-child', function () {
        const entityKey = $(this).data('entity');
        const cfg = CHILD_ENTITY_CONFIG[entityKey];
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/employee.${cfg.apiEntity}.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ employee_id: currentEmployeeId, id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        loadAllChildTables();
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
    // Father/Mother fixed slots (2026-08-19, explicit request) -- each slot's Save button posts
    // directly to /api/employee.parent.save with relationship fixed to that slot ('father'/'mother'),
    // carrying the row's own id once one exists so re-saving updates it instead of creating a
    // duplicate. Auto-save on click, same convention as every other add/edit action in this app.
    Object.keys(PARENT_SLOTS).forEach(function (relationship) {
        const cfg = PARENT_SLOTS[relationship];
        $(cfg.toggleId + ' button').on('click', function () {
            const val = $(this).data('value');
            $(cfg.toggleId + ' button').removeClass('active').filter(`[data-value="${val}"]`).addClass('active');
            // Just collapses the fields from view -- never deletes an already-saved father/mother row,
            // which only the trash-icon button below actually does.
            $(cfg.fieldsId).toggleClass('d-none', val !== 'yes');
        });
        $(cfg.saveBtnId).on('click', function () {
            if (!currentEmployeeId) {
                showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
                return;
            }
            const name = $(cfg.nameInput).val().trim();
            if (!name) {
                $(cfg.nameInput).addClass('is-invalid');
                showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
                return;
            }
            $(cfg.nameInput).removeClass('is-invalid');
            const payload = {
                employee_id: currentEmployeeId,
                name: name,
                id_card_no: $(cfg.idCardInput).val().trim(),
                relationship: relationship,
            };
            const existingId = $(cfg.idInput).val();
            if (existingId) payload.id = existingId;
            const $btn = $(this);
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
            $.ajax({
                url: `${BASE_URL}/api/employee.parent.save`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(payload),
                success: function (res) {
                    $btn.prop('disabled', false).html(originalHtml);
                    if (typeof updateText === 'function') updateText($btn[0]);
                    if (res.status) {
                        showSuccess(langData['save_success'] || 'Saved successfully.');
                        if (res.id) $(cfg.idInput).val(res.id);
                        $(cfg.deleteBtnId).removeClass('d-none');
                        loadParentSlots();
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
    let html = '<div class="d-flex justify-content-center gap-1">';
    if (isOpen && notStarted) {
        html += `<button type="button" class="btn btn-sm btn-link text-secondary btn-edit-eed" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen-to-square"></i></button>`;
    }
    if (isOpen) {
        if (row.status === 'active') {
            html += `<button type="button" class="btn btn-sm btn-link text-warning btn-eed-status" data-id="${row.id}" data-status="paused" title="${langData['pause_item'] || 'Pause'}"><i class="fa-solid fa-pause"></i></button>`;
        } else {
            html += `<button type="button" class="btn btn-sm btn-link text-success btn-eed-status" data-id="${row.id}" data-status="active" title="${langData['resume_item'] || 'Resume'}"><i class="fa-solid fa-play"></i></button>`;
        }
        html += `<button type="button" class="btn btn-sm btn-link text-danger btn-eed-status" data-id="${row.id}" data-status="cancelled" title="${langData['cancel_item'] || 'Cancel'}"><i class="fa-solid fa-ban"></i></button>`;
    }
    if (isOpen && notStarted) {
        html += `<button type="button" class="btn btn-sm btn-link text-danger btn-delete-eed" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
    }
    html += '</div>';
    return html;
}
function eedAmountSummary(row) {
    const total = parseFloat(row.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (row.amount_mode === 'custom_per_installment') {
        return `${total} <span class="text-muted small">(${langData['custom_per_installment'] || 'custom'})</span>`;
    }
    const per = (parseFloat(row.total_amount || 0) / Math.max(1, parseInt(row.total_installments || 1, 10))).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${total} <span class="text-muted small">(${per} x ${row.total_installments})</span>`;
}
// Item-name cell no longer shows the earning/deduction word (2026-08-19: the two tables are split by
// type now, so it would just repeat the table's own heading on every row) -- shows the "Custom" badge
// instead when the row has no ped_type_id (custom item, see EmployeeEarningDeductionModel::save()).
function eedItemNameCell(row) {
    const label = escapeHtml((currentLang === 'th' ? row.item_name_th : row.item_name_en) || '');
    const badge = row.ped_type_id ? '' : ` <span class="badge bg-secondary-subtle text-secondary">${langData['manual_line_custom_badge'] || 'Custom'}</span>`;
    const codeLine = row.item_code ? escapeHtml(row.item_code) : '';
    return `<div><strong>${label}</strong>${badge}</div><div class="text-muted small">${codeLine}</div>`;
}
function eedTableColumns() {
    return [
        { data: null, render: (d, t, row) => eedItemNameCell(row) },
        { data: null, render: (d, t, row) => eedAmountSummary(row) },
        { data: null, className: 'text-center', render: (d, t, row) => `${row.current_installment}/${row.total_installments}` },
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
                    <button type="button" class="btn btn-sm text-white ms-1 ${addBtnClass}" style="background-color:#FF9900;border-color:#FF9900;">
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
function regenerateCustomAmountInputs(count, existingAmounts) {
    const $container = $('#eed_custom_amounts_container');
    $container.empty();
    for (let i = 0; i < count; i++) {
        const val = (existingAmounts && existingAmounts[i] !== undefined) ? existingAmounts[i] : '';
        $container.append(`
            <div class="input-group mb-2">
                <span class="input-group-text" style="min-width:110px;">${langData['installment_label'] || 'Installment'} ${i + 1}</span>
                <input type="number" step="0.01" min="0" class="form-control eed-custom-amount required">
            </div>
        `);
        $container.find('.eed-custom-amount').last().val(val);
    }
}
function applyAmountModeFields(mode) {
    $('#eed_total_amount_wrapper').toggleClass('d-none', mode !== 'even_split');
    $('#eed_total_amount').toggleClass('required', mode === 'even_split');
    $('#eed_custom_amounts_wrapper').toggleClass('d-none', mode !== 'custom_per_installment');
    if (mode === 'custom_per_installment') {
        const count = Math.max(1, parseInt($('#eed_total_installments').val() || '1', 10));
        regenerateCustomAmountInputs(count, []);
    } else {
        $('#eed_custom_amounts_container').empty();
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
function applyAmountModeFields(mode) {
    $('#eed_total_amount_wrapper').toggleClass('d-none', mode !== 'even_split');
    $('#eed_total_amount').toggleClass('required', mode === 'even_split');
    $('#eed_custom_amounts_wrapper').toggleClass('d-none', mode !== 'custom_per_installment');
    if (mode === 'custom_per_installment') {
        const count = Math.max(1, parseInt($('#eed_total_installments').val() || '1', 10));
        regenerateCustomAmountInputs(count, []);
    } else {
        $('#eed_custom_amounts_container').empty();
    }
}
// $context: 'earning'/'deduction' when opened from that table's own Add button (2026-08-19, explicit
// request) -- pre-filters the catalog dropdown to that item_type (via #eed_ped_type_id's data-type,
// read fresh on every select2 ajax search) and pre-selects the same type in custom mode, so which
// table the new item will show up in is obvious from which Add button was clicked, not a separate
// choice buried inside the modal.
function resetEedForm(context) {
    $('#eedForm')[0].reset();
    $('#eed_id').val('');
    setEedMode('catalog');
    $('#eed_ped_type_id').attr('data-type', context || '').val(null).trigger('change');
    $('#eed_custom_item_type').val(context || 'earning').trigger('change.select2');
    $('.is-invalid').removeClass('is-invalid');
    applyAmountModeFields('even_split');
    $('#eedModalLabel').text(langData['add_earning_deduction'] || 'Add Earning / Deduction');
}
function populateEedForm(row) {
    $('#eed_id').val(row.id);
    if (row.ped_type_id) {
        setEedMode('catalog');
        const label = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || '';
        const opt = new Option(`[${row.item_code}] ${label}`, row.ped_type_id, true, true);
        $('#eed_ped_type_id').empty().append(opt).trigger('change');
    } else {
        setEedMode('custom');
        $('#eed_custom_item_name').val(row.item_name_th || row.item_name_en || '');
        $('#eed_custom_item_type').val(row.item_type).trigger('change.select2');
    }
    $('#eed_effective_date').val(toDisplayDate(row.effective_date));
    $(`input[name="amount_mode"][value="${row.amount_mode}"]`).prop('checked', true);
    $('#eed_total_installments').val(row.total_installments);
    $('#eed_total_amount').val(row.total_amount);
    $('#eed_notes').val(row.notes || '');
    $('#eed_external_reference_no').val(row.external_reference_no || '');
    applyAmountModeFields(row.amount_mode);
    if (row.amount_mode === 'custom_per_installment' && row.installments) {
        const amounts = row.installments.map(i => i.amount);
        regenerateCustomAmountInputs(row.installments.length, amounts);
    }
    $('#eedModalLabel').text(langData['edit_earning_deduction'] || 'Edit Earning / Deduction');
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
    const data = {
        id: $('#eed_id').val() || undefined,
        employee_id: currentEmployeeId,
        effective_date: toIsoDate($('#eed_effective_date').val()),
        total_installments: $('#eed_total_installments').val(),
        amount_mode: $('input[name="amount_mode"]:checked').val(),
        notes: $('#eed_notes').val().trim(),
        external_reference_no: $('#eed_external_reference_no').val().trim()
    };
    if (mode === 'custom') {
        data.custom_item_name = $('#eed_custom_item_name').val().trim();
        data.custom_item_type = $('#eed_custom_item_type').val();
    } else {
        data.ped_type_id = $('#eed_ped_type_id').val();
    }
    if (data.amount_mode === 'even_split') {
        data.total_amount = $('#eed_total_amount').val();
    } else {
        data.installment_amounts = $('.eed-custom-amount').map(function () { return $(this).val(); }).get();
    }
    return data;
}
function initEedUI() {
    tbEarning = initEedTable('#tableEarning', 'earning', 'btn-add-earning', 'add_earning_item', 'Add Earning');
    tbDeduction = initEedTable('#tableDeduction', 'deduction', 'btn-add-deduction', 'add_deduction_item', 'Add Deduction');
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
        initSelect2('#eed_custom_item_type', { mode: 'static', selectedValue: 'earning' });
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
    $(document).on('click', '.btn-edit-eed', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/employee.earning-deduction.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status && res.data) {
                    resetEedForm();
                    populateEedForm(res.data);
                    new bootstrap.Modal(document.getElementById('eedModal')).show();
                } else {
                    showWarning(res.message || langData['load_employee_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['load_employee_failed'] || 'Failed to load data.');
            }
        });
    });
    $(document).on('change', '#eed_total_installments', function () {
        if ($('input[name="amount_mode"]:checked').val() === 'custom_per_installment') {
            const existing = $('.eed-custom-amount').map(function () { return $(this).val(); }).get();
            regenerateCustomAmountInputs(Math.max(1, parseInt($(this).val() || '1', 10)), existing);
        }
    });
    $(document).on('change', 'input[name="amount_mode"]', function () {
        applyAmountModeFields($(this).val());
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
