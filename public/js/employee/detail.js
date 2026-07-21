let currentEmployeeId = null;
let childTablesLoaded = false;
const TAB_BUTTON_BY_PANE = {
    'info-pane': 'info-tab',
    'contact-pane': 'contact-tab',
    'employment-pane': 'employment-tab',
    'salary-pane': 'salary-tab',
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
    const remoteFields = ['department_id', 'role_id', 'position_id', 'branch_id', 'bank_id', 'report_to_id', 'nationality', 'religion'];
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
    $('#search_address_register').val((currentLang === 'th' ? data.address_display_th_register : data.address_display_en_register) || '');
    $('#search_address_contact').val((currentLang === 'th' ? data.address_display_th_contact : data.address_display_en_contact) || '');
}
function validateEmployeeForm() {
    let firstInvalid = null;
    $('#employeeTabsContent .required').each(function () {
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
    const idCardVal = $('#id_card_no').val();
    if ($('#id_card_no').hasClass('required') && idCardVal && !isValidThaiID(idCardVal)) {
        $('#id_card_no').addClass('is-invalid');
        if (!firstInvalid) firstInvalid = $('#id_card_no');
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
function saveEmployee($btn, onSuccessTabId) {
    const invalidEl = validateEmployeeForm();
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        jumpToField(invalidEl);
        return;
    }
    const payload = collectEmployeeFormData();
    if (currentEmployeeId) {
        payload.id = currentEmployeeId;
    }
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
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
                    $('.bc-current').text(res.employee_no);
                }
                if (onSuccessTabId) {
                    const btnEl = document.getElementById(onSuccessTabId);
                    if (btnEl && typeof bootstrap !== 'undefined') {
                        bootstrap.Tab.getOrCreateInstance(btnEl).show();
                    }
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
                if (!childTablesLoaded) {
                    childTablesLoaded = true;
                    loadAllChildTables();
                    loadDocumentList();
                }
            } else {
                showWarning(res.message || 'Employee not found.');
            }
        },
        error: function () {
            showWarning('Failed to load employee data.');
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
    $('#btnNextContact').on('click', function () { saveEmployee($(this), 'contact-tab'); });
    $('#btnNextEmployment').on('click', function () { saveEmployee($(this), 'employment-tab'); });
    $('#btnNextSalary').on('click', function () { saveEmployee($(this), 'salary-tab'); });
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

const CHILD_TABLE_CONFIG = {
    dependent: {
        tableId: '#tableDependent',
        addBtnId: '#btnAddDependent',
        apiEntity: 'dependent',
        buildRow: function (row) {
            const $tr = $(
                '<tr>' +
                '<td><input type="text" class="form-control form-control-sm required-child" data-field="name"></td>' +
                '<td><input type="text" class="form-control form-control-sm" data-field="id_card_no" maxlength="13"></td>' +
                '<td><input type="text" class="form-control form-control-sm datepicker" data-field="date_of_birth" autocomplete="off"></td>' +
                '<td><input type="text" class="form-control form-control-sm required-child" data-field="relationship"></td>' +
                '<td class="text-center"><input type="checkbox" data-field="studying"></td>' +
                '<td class="text-center">' +
                '<button type="button" class="btn btn-sm btn-link text-success btn-save-row"><i class="fa-solid fa-check"></i></button>' +
                '<button type="button" class="btn btn-sm btn-link text-danger btn-delete-row"><i class="fa-solid fa-trash-can"></i></button>' +
                '</td>' +
                '</tr>'
            );
            $tr.find('[data-field="name"]').val(row.name || '');
            $tr.find('[data-field="id_card_no"]').val(row.id_card_no || '');
            $tr.find('[data-field="date_of_birth"]').val(toDisplayDate(row.date_of_birth));
            $tr.find('[data-field="relationship"]').val(row.relationship || '');
            $tr.find('[data-field="studying"]').prop('checked', row.studying == 1 || row.studying === true);
            return $tr;
        }
    },
    parent: {
        tableId: '#tableParent',
        addBtnId: '#btnAddParent',
        apiEntity: 'parent',
        buildRow: function (row) {
            const $tr = $(
                '<tr>' +
                '<td><input type="text" class="form-control form-control-sm required-child" data-field="name"></td>' +
                '<td><input type="text" class="form-control form-control-sm" data-field="id_card_no" maxlength="13"></td>' +
                '<td><input type="text" class="form-control form-control-sm required-child" data-field="relationship"></td>' +
                '<td class="text-center">' +
                '<button type="button" class="btn btn-sm btn-link text-success btn-save-row"><i class="fa-solid fa-check"></i></button>' +
                '<button type="button" class="btn btn-sm btn-link text-danger btn-delete-row"><i class="fa-solid fa-trash-can"></i></button>' +
                '</td>' +
                '</tr>'
            );
            $tr.find('[data-field="name"]').val(row.name || '');
            $tr.find('[data-field="id_card_no"]').val(row.id_card_no || '');
            $tr.find('[data-field="relationship"]').val(row.relationship || '');
            return $tr;
        }
    }
};
function addChildRow(entityKey, row) {
    const cfg = CHILD_TABLE_CONFIG[entityKey];
    const $tr = cfg.buildRow(row || {});
    $tr.attr('data-id', row && row.id ? row.id : '').data('id', row && row.id ? row.id : '');
    $tr.data('entity', entityKey);
    $(`${cfg.tableId} tbody`).append($tr);
    initDatepicker($tr.find('.datepicker'));
    if (typeof updateText === 'function') updateText($tr[0]);
}
function loadAllChildTables() {
    Object.keys(CHILD_TABLE_CONFIG).forEach(function (entityKey) {
        const cfg = CHILD_TABLE_CONFIG[entityKey];
        $(`${cfg.tableId} tbody`).empty();
        $.ajax({
            url: `${BASE_URL}/api/employee.${cfg.apiEntity}.list`,
            method: 'GET',
            data: { employee_id: currentEmployeeId },
            dataType: 'json',
            success: function (res) {
                if (res.status && res.data) {
                    res.data.forEach(function (row) { addChildRow(entityKey, row); });
                }
            }
        });
    });
    loadEarningDeductions();
}
function saveChildRow($tr) {
    if (!currentEmployeeId) {
        showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
        return;
    }
    const entityKey = $tr.data('entity');
    const cfg = CHILD_TABLE_CONFIG[entityKey];
    const payload = { employee_id: currentEmployeeId };
    const id = $tr.data('id');
    if (id) payload.id = id;
    let hasError = false;
    $tr.find('[data-field]').each(function () {
        const $input = $(this);
        const field = $input.data('field');
        if ($input.is(':checkbox')) {
            payload[field] = $input.is(':checked');
            return;
        }
        const val = $input.hasClass('datepicker') ? toIsoDate($input.val()) : $input.val();
        payload[field] = val;
        if ($input.hasClass('required-child')) {
            if (!String(val || '').trim()) {
                $input.addClass('is-invalid');
                hasError = true;
            } else {
                $input.removeClass('is-invalid');
            }
        }
    });
    if (hasError) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    $.ajax({
        url: `${BASE_URL}/api/employee.${cfg.apiEntity}.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                if (res.id) {
                    $tr.attr('data-id', res.id).data('id', res.id);
                }
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
}
function deleteChildRow($tr) {
    const id = $tr.data('id');
    if (!id) {
        $tr.remove();
        return;
    }
    const entityKey = $tr.data('entity');
    const cfg = CHILD_TABLE_CONFIG[entityKey];
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
}
function initChildTables() {
    Object.keys(CHILD_TABLE_CONFIG).forEach(function (entityKey) {
        const cfg = CHILD_TABLE_CONFIG[entityKey];
        $(cfg.addBtnId).on('click', function () {
            if (!currentEmployeeId) {
                showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
                return;
            }
            addChildRow(entityKey, {});
        });
    });
    $(document).on('click', '.btn-save-row', function () {
        saveChildRow($(this).closest('tr'));
    });
    $(document).on('click', '.btn-delete-row', function () {
        deleteChildRow($(this).closest('tr'));
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
function renderEedRow(row) {
    const typeLabel = row.item_type === 'earning' ? (langData['earning_singular'] || 'Earning') : (langData['deduction_singular'] || 'Deduction');
    const $tr = $('<tr>' +
        `<td><div><strong>${escapeHtml(currentLang === 'th' ? row.item_name_th : row.item_name_en)}</strong></div><div class="text-muted small">${escapeHtml(row.item_code)} &middot; ${typeLabel}</div></td>` +
        `<td>${eedAmountSummary(row)}</td>` +
        `<td class="text-center">${row.current_installment}/${row.total_installments}</td>` +
        `<td>${toDisplayDate(row.effective_date)}</td>` +
        `<td>${eedStatusBadge(row)}</td>` +
        `<td class="text-center">${eedActionButtons(row)}</td>` +
        '</tr>');
    $tr.data('row', row);
    return $tr;
}
function loadEarningDeductions() {
    $('#tableEarningDeduction tbody').empty();
    if (!currentEmployeeId) return;
    $.ajax({
        url: `${BASE_URL}/api/employee.earning-deduction.list`,
        method: 'GET',
        data: { employee_id: currentEmployeeId },
        dataType: 'json',
        success: function (res) {
            if (res.status && res.data) {
                res.data.forEach(function (row) {
                    $('#tableEarningDeduction tbody').append(renderEedRow(row));
                });
                if (typeof updateText === 'function') updateText(document.getElementById('tableEarningDeduction'));
            }
        }
    });
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
function resetEedForm() {
    $('#eedForm')[0].reset();
    $('#eed_id').val('');
    $('#eed_ped_type_id').val(null).trigger('change');
    $('.is-invalid').removeClass('is-invalid');
    applyAmountModeFields('even_split');
    $('#eedModalLabel').text(langData['add_earning_deduction'] || 'Add Earning / Deduction');
}
function populateEedForm(row) {
    $('#eed_id').val(row.id);
    const label = (currentLang === 'th' ? row.item_name_th : row.item_name_en) || '';
    const opt = new Option(`[${row.item_code}] ${label}`, row.ped_type_id, true, true);
    $('#eed_ped_type_id').empty().append(opt).trigger('change');
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
    const data = {
        id: $('#eed_id').val() || undefined,
        employee_id: currentEmployeeId,
        ped_type_id: $('#eed_ped_type_id').val(),
        effective_date: toIsoDate($('#eed_effective_date').val()),
        total_installments: $('#eed_total_installments').val(),
        amount_mode: $('input[name="amount_mode"]:checked').val(),
        notes: $('#eed_notes').val().trim(),
        external_reference_no: $('#eed_external_reference_no').val().trim()
    };
    if (data.amount_mode === 'even_split') {
        data.total_amount = $('#eed_total_amount').val();
    } else {
        data.installment_amounts = $('.eed-custom-amount').map(function () { return $(this).val(); }).get();
    }
    return data;
}
function initEedUI() {
    if (typeof initSelect2 === 'function') {
        initSelect2('#eed_ped_type_id', { mode: 'ajax' });
    }
    $('#btnAddEarningDeduction').on('click', function () {
        if (!currentEmployeeId) {
            showWarning(langData['save_basic_info_first'] || "Please save the employee's basic info first.");
            return;
        }
        resetEedForm();
        new bootstrap.Modal(document.getElementById('eedModal')).show();
    });
    $(document).on('click', '.btn-edit-eed', function () {
        const $tr = $(this).closest('tr');
        const row = $tr.data('row');
        resetEedForm();
        populateEedForm(row);
        new bootstrap.Modal(document.getElementById('eedModal')).show();
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
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
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
