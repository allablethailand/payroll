/**
 * Payslip Format (template editor) — Document & Approval > "เทมเพลตสลิปเงินเดือน" tab.
 * Field picker mirrors the Approval Workflow step editor's drag-sort pattern (SortableJS,
 * client-side `currentFields` array as the source of truth, full rebuild on structural change).
 * Fields are structural blocks (see master_payslip_field_types), not individual earning/deduction
 * item codes — see PayslipTemplateModel docblock for why.
 */
let currentFields = [];
let fieldKeyCounter = 0;
let fieldSortableInstance = null;
let tb_payslip_template;

function newFieldKey() {
    fieldKeyCounter += 1;
    return 'ptf_' + fieldKeyCounter + '_' + Date.now();
}

function escapeHtmlPt(str) {
    return $('<div>').text(str || '').html().replace(/"/g, '&quot;');
}

function fieldGroupLabel(group) {
    const map = {
        employee_info: langData['employee_info_group'] || 'Employee Info',
        company_info: langData['company_info_group'] || 'Company Info',
        earning: langData['earning_group'] || 'Earning',
        deduction: langData['deduction_group'] || 'Deduction',
        statutory: langData['statutory_group'] || 'Statutory',
        summary: langData['summary_group'] || 'Summary',
    };
    return map[group] || group;
}

function buildFieldRowHtml(field, index) {
    const label = (currentLang === 'th' ? field.label_th : field.label_en) || field.label_th || field.label_en;
    return `
        <div class="payslip-field-row d-flex align-items-center gap-2 border rounded p-2 mb-2" data-key="${field.key}">
            <div class="field-drag-handle text-secondary" style="cursor:grab;"><i class="fa-solid fa-grip-vertical"></i></div>
            <div class="field-order-badge badge bg-secondary rounded-pill" style="min-width:1.75rem;">${index + 1}</div>
            <span class="badge bg-light text-secondary border">${escapeHtmlPt(fieldGroupLabel(field.field_group))}</span>
            <div class="flex-grow-1">${escapeHtmlPt(label)}</div>
            <button type="button" class="btn btn-sm btn-outline-danger delete-field-btn"><i class="fa-solid fa-trash"></i></button>
        </div>
    `;
}

function renderFields() {
    const $wrap = $('#payslipFieldList').empty();
    currentFields.forEach((field, index) => {
        $wrap.append(buildFieldRowHtml(field, index));
    });
    $('#noPayslipFieldsMessage').toggleClass('d-none', currentFields.length > 0);
    initFieldSortable();
}

function initFieldSortable() {
    const el = document.getElementById('payslipFieldList');
    if (!el) return;
    if (fieldSortableInstance) {
        fieldSortableInstance.destroy();
        fieldSortableInstance = null;
    }
    if (typeof Sortable === 'undefined') return;
    fieldSortableInstance = Sortable.create(el, {
        handle: '.field-drag-handle',
        animation: 150,
        onEnd: function () {
            const newOrderKeys = $('#payslipFieldList .payslip-field-row').map(function () { return $(this).data('key'); }).get();
            currentFields.sort((a, b) => newOrderKeys.indexOf(a.key) - newOrderKeys.indexOf(b.key));
            renderFields();
        }
    });
}

function deleteFieldRow(key) {
    currentFields = currentFields.filter(f => f.key !== key);
    renderFields();
}

$(document).on('click', '.delete-field-btn', function () {
    deleteFieldRow($(this).closest('.payslip-field-row').data('key'));
});

$(document).on('click', '#btnAddPayslipField', function () {
    const $select = $('#pt_add_field_select');
    const fieldKey = $select.val();
    if (!fieldKey) {
        showWarning(langData['select_field_first'] || 'Select a field first.');
        return;
    }
    if (currentFields.some(f => f.field_key === fieldKey)) {
        showWarning(langData['field_already_added'] || 'This field is already added.');
        return;
    }
    const selectedData = $select.select2('data');
    const opt = selectedData && selectedData[0] ? selectedData[0] : null;
    currentFields.push({
        key: newFieldKey(),
        field_key: fieldKey,
        field_group: opt && opt.field_group ? opt.field_group : '',
        label_th: opt ? (opt.text_th || opt.text) : fieldKey,
        label_en: opt ? (opt.text_en || opt.text) : fieldKey,
    });
    renderFields();
    $select.val(null).trigger('change');
});

/* ---------- Logo upload ---------- */
$(document).on('change', '#pt_logo_file', function () {
    const file = this.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.upload-logo`,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                $('#pt_logo_path').val(res.logo_path);
                $('#pt_logo_preview').attr('src', `${BASE_URL}/${res.logo_path}`).show();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while uploading the logo.'); }
    });
});

/* ---------- Form reset / populate ---------- */
function resetPayslipTemplateForm() {
    $('#payslipTemplateForm')[0].reset();
    $('#pt_id').val('');
    $('#pt_logo_path').val('');
    $('#pt_logo_preview').attr('src', '').hide();
    $('.is-invalid').removeClass('is-invalid');
    currentFields = [];
    renderFields();
    initSelect2('#pt_language_mode', { mode: 'static', selectedValue: 'both' });
    initSelect2('#pt_add_field_select', { mode: 'ajax' });
    $('#pt_is_default').prop('checked', false);
    $('#pt_status').prop('checked', true);
    $('#payslipTemplateModalLabel').html('<i class="fa-solid fa-file-invoice me-2"></i>' + (langData['payslip_template'] || 'Payslip Template'));
}

function populatePayslipTemplateForm(row) {
    $('#pt_id').val(row.id);
    $('#pt_name_th').val(row.name_th);
    $('#pt_name_en').val(row.name_en);
    initSelect2('#pt_language_mode', { mode: 'static', selectedValue: row.language_mode });
    $('#pt_is_default').prop('checked', parseInt(row.is_default) === 1);
    $('#pt_status').prop('checked', row.status === 'active');
    $('#pt_header_th').val(row.header_text_th || '');
    $('#pt_header_en').val(row.header_text_en || '');
    $('#pt_footer_th').val(row.footer_text_th || '');
    $('#pt_footer_en').val(row.footer_text_en || '');
    $('#pt_logo_path').val(row.logo_path || '');
    if (row.logo_path) {
        $('#pt_logo_preview').attr('src', `${BASE_URL}/${row.logo_path}`).show();
    } else {
        $('#pt_logo_preview').attr('src', '').hide();
    }

    currentFields = (row.fields || []).map(f => ({
        key: newFieldKey(),
        field_key: f.field_key,
        field_group: f.field_group,
        label_th: f.custom_label_th || f.default_label_th,
        label_en: f.custom_label_en || f.default_label_en,
    }));
    renderFields();
    initSelect2('#pt_add_field_select', { mode: 'ajax' });
    $('#payslipTemplateModalLabel').html('<i class="fa-solid fa-file-invoice me-2"></i>' + (langData['payslip_template'] || 'Payslip Template'));
}

/* ---------- List ---------- */
function payslipTemplateStatusBadge(status) {
    const isActive = status === 'active';
    const cls = isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
    const text = isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive');
    return `<span class="badge ${cls}">${text}</span>`;
}
function payslipTemplateActionButtons(row) {
    const toggleIcon = row.status === 'active' ? 'fa-toggle-on' : 'fa-toggle-off';
    const toggleTitle = row.status === 'active' ? (langData['deactivate'] || 'Deactivate') : (langData['activate'] || 'Activate');
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-warning btn-edit-pt" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link text-primary border-start btn-toggle-pt" data-id="${row.id}" data-status="${row.status}" title="${toggleTitle}"><i class="fa-solid ${toggleIcon}"></i></button>
        <button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-pt" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fas fa-trash-alt"></i></button>
    </div>`;
}
function languageModeLabel(mode) {
    if (mode === 'th') return langData['language_th'] || 'Thai';
    if (mode === 'en') return langData['language_en'] || 'English';
    return langData['language_both'] || 'Thai + English';
}
function initPayslipTemplateTable() {
    if ($.fn.DataTable.isDataTable('#tb_payslip_template')) {
        $('#tb_payslip_template').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payslip_template = $('#tb_payslip_template').DataTable({
        responsive: true,
        ajax: { url: `${BASE_URL}/api/payslip-template.list`, dataSrc: 'data' },
        columns: [
            { data: null, render: (d, t, row) => `<strong class="text-dark">${escapeHtmlPt(currentLang === 'th' ? row.name_th : row.name_en)}</strong>` },
            { data: null, className: 'text-center', render: (d, t, row) => parseInt(row.is_default) === 1 ? `<i class="fa-solid fa-star text-warning"></i>` : '' },
            { data: null, render: (d, t, row) => languageModeLabel(row.language_mode) },
            { data: 'field_count' },
            { data: 'status', render: d => payslipTemplateStatusBadge(d) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => payslipTemplateActionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-pt').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-pt">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="add_template">${langData['add_template'] || 'Template'}</span>
                    </button>
                `);
            }
        }
    });
}

$(document).on('click', '.btn-add-pt', function () {
    resetPayslipTemplateForm();
    new bootstrap.Modal(document.getElementById('payslipTemplateModal')).show();
});

$(document).on('click', '.btn-edit-pt', function () {
    const id = $(this).data('id');
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.get`,
        method: 'GET',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                resetPayslipTemplateForm();
                populatePayslipTemplateForm(res.data);
                new bootstrap.Modal(document.getElementById('payslipTemplateModal')).show();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
});

$(document).on('click', '.btn-toggle-pt', function () {
    const id = $(this).data('id');
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.toggle-status`,
        method: 'POST',
        data: { id },
        dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
            tb_payslip_template.ajax.reload(null, false);
        }
    });
});

$(document).on('click', '.btn-delete-pt', function () {
    const id = $(this).data('id');
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/payslip-template.delete`,
            method: 'POST',
            data: { id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.');
                    tb_payslip_template.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'An error occurred.');
                }
            }
        });
    });
});

function buildPayslipTemplatePayload() {
    return {
        id: $('#pt_id').val() || undefined,
        name_th: $('#pt_name_th').val().trim(),
        name_en: $('#pt_name_en').val().trim(),
        language_mode: $('#pt_language_mode').val() || 'both',
        is_default: $('#pt_is_default').is(':checked') ? 1 : 0,
        status: $('#pt_status').is(':checked') ? 'active' : 'inactive',
        logo_path: $('#pt_logo_path').val(),
        header_text_th: $('#pt_header_th').val().trim(),
        header_text_en: $('#pt_header_en').val().trim(),
        footer_text_th: $('#pt_footer_th').val().trim(),
        footer_text_en: $('#pt_footer_en').val().trim(),
        fields: currentFields.map(f => ({ field_key: f.field_key }))
    };
}

/* ---------- Preview (mock data, current unsaved form state, opens PDF in a new tab) ---------- */
$(document).on('click', '#btnPreviewPayslip', function () {
    if (currentFields.length === 0) {
        showWarning(langData['no_fields_yet'] || 'No fields yet — add at least one.');
        return;
    }
    const $btn = $(this);
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i>' + (langData['loading'] || 'Loading...'));
    fetch(`${BASE_URL}/api/payslip-template.preview`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildPayslipTemplatePayload())
    }).then(function (res) {
        const contentType = res.headers.get('Content-Type') || '';
        if (!res.ok || contentType.indexOf('application/pdf') === -1) {
            return res.json().then(function (data) {
                throw new Error((data && data.message) || (langData['save_failed'] || 'An error occurred.'));
            });
        }
        return res.blob();
    }).then(function (blob) {
        const url = URL.createObjectURL(blob);
        window.open(url, '_blank');
    }).catch(function (err) {
        showWarning(err.message || langData['save_failed'] || 'An error occurred while generating the preview.');
    }).finally(function () {
        $btn.prop('disabled', false).html(originalHtml);
    });
});

$(document).on('submit', '#payslipTemplateForm', function (e) {
    e.preventDefault();
    const nameTh = $('#pt_name_th').val().trim();
    const nameEn = $('#pt_name_en').val().trim();
    if (!nameTh || !nameEn) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    if (currentFields.length === 0) {
        showWarning(langData['no_fields_yet'] || 'No fields yet — add at least one.');
        return;
    }
    const payload = buildPayslipTemplatePayload();
    $.ajax({
        url: `${BASE_URL}/api/payslip-template.save`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('payslipTemplateModal')).hide();
                tb_payslip_template.ajax.reload(null, false);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

$(document).ready(function () {
    initPayslipTemplateTable();
    $('#payslipTemplateTabBtn').on('shown.bs.tab', function () {
        initPayslipTemplateTable();
    });
});
