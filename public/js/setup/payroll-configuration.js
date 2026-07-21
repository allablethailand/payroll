let tb_earning_type;
let tb_deduction_type;

function calcMethodBadge(row) {
    if (row.calculation_method === 'fixed_amount') {
        const amt = parseFloat(row.fixed_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return `<span class="badge bg-info-subtle text-info">${amt}</span>`;
    }
    if (row.calculation_method === 'percent_of_base_salary') {
        const rate = parseFloat(row.percent_rate || 0);
        return `<span class="badge bg-info-subtle text-info">${rate}%</span>`;
    }
    return `<span class="badge bg-light text-dark border">${langData['manual_short'] || 'Manual'}</span>`;
}
function sourceEventTag(row) {
    if (!row.source_event_code) return '';
    const label = (currentLang === 'th' ? row.source_event_name_th : row.source_event_name_en) || row.source_event_code;
    return `<div class="text-muted small mt-1"><i class="fa-solid fa-link me-1"></i>${label}</div>`;
}
function statutoryReportTag(row) {
    if (!row.statutory_report_code) return '';
    const label = langData['statutory_report_' + row.statutory_report_code.toLowerCase()] || row.statutory_report_code;
    return `<div class="text-muted small mt-1"><i class="fa-solid fa-landmark me-1"></i>${label}</div>`;
}
function statusBadge(row) {
    const isActive = row.status === 'active';
    const cls = isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
    const text = isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive');
    return `<span class="badge ${cls}">${text}</span>`;
}
function actionButtons(row) {
    return `<div class="d-flex justify-content-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-ped-type" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-ped-type" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
    </div>`;
}
function injectAddButton(api, itemType, i18nKey, defaultLabel) {
    const $wrapper = $(api.table().container());
    const $searchDiv = $wrapper.find('.dt-search');
    if ($searchDiv.find(`.btn-add-ped-type[data-item-type="${itemType}"]`).length === 0) {
        const btn = `
            <button type="button" class="btn btn-primary ms-1 btn-add-ped-type" data-item-type="${itemType}">
                <i class="fa-solid fa-plus me-1"></i><span data-i18n="${i18nKey}">${langData[i18nKey] || defaultLabel}</span>
            </button>
        `;
        $searchDiv.append(btn);
    }
}
function initEarningTypeTable() {
    if ($.fn.DataTable.isDataTable('#tb_earning_type')) {
        $('#tb_earning_type').DataTable().ajax.reload(null, false);
        return;
    }
    tb_earning_type = $('#tb_earning_type').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/ped-type.list`,
            type: 'POST',
            data: function (d) { d.item_type = 'earning'; }
        },
        columns: [
            { data: 'item_code', render: d => `<code class="fw-bold text-dark">${d}</code>` },
            {
                data: null,
                render: (data, type, row) => `<div><strong>${row.item_name_en}</strong></div><div class="text-muted small">${row.item_name_th}</div>${sourceEventTag(row)}`
            },
            { data: null, render: (d, t, row) => calcMethodBadge(row) },
            {
                data: 'tax_treatment',
                render: d => d === 'taxable'
                    ? `<span class="badge bg-success-subtle text-success">${langData['taxable'] || 'Taxable'}</span>`
                    : `<span class="badge bg-secondary-subtle text-secondary">${langData['non_taxable'] || 'Tax-exempt'}</span>`
            },
            { data: 'calc_sso', className: 'text-center', render: d => Number(d) ? '<i class="fa-solid fa-circle-check text-success fs-5"></i>' : '<i class="fa-solid fa-circle-xmark text-muted fs-5"></i>' },
            { data: 'calc_pf', className: 'text-center', render: d => Number(d) ? '<i class="fa-solid fa-circle-check text-success fs-5"></i>' : '<i class="fa-solid fa-circle-xmark text-muted fs-5"></i>' },
            { data: null, render: (d, t, row) => statusBadge(row) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => actionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            injectAddButton(this.api(), 'earning', 'earning_type', 'Earning Type');
        },
        drawCallback: function () { getTableLang(); }
    });
}
function initDeductionTypeTable() {
    if ($.fn.DataTable.isDataTable('#tb_deduction_type')) {
        $('#tb_deduction_type').DataTable().ajax.reload(null, false);
        return;
    }
    tb_deduction_type = $('#tb_deduction_type').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/ped-type.list`,
            type: 'POST',
            data: function (d) { d.item_type = 'deduction'; }
        },
        columns: [
            { data: 'item_code', render: d => `<code class="fw-bold text-dark">${d}</code>` },
            {
                data: null,
                render: (data, type, row) => `<div><strong>${row.item_name_en}</strong></div><div class="text-muted small">${row.item_name_th}</div>${sourceEventTag(row)}${statutoryReportTag(row)}`
            },
            { data: null, render: (d, t, row) => calcMethodBadge(row) },
            {
                data: 'tax_deduction_impact',
                render: d => d === 'before_tax'
                    ? `<span class="badge bg-danger-subtle text-danger">${langData['impact_before_tax'] || 'Before Tax'}</span>`
                    : `<span class="badge bg-secondary-subtle text-secondary">${langData['impact_after_tax'] || 'After Tax'}</span>`
            },
            { data: null, render: (d, t, row) => statusBadge(row) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => actionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            injectAddButton(this.api(), 'deduction', 'deduction_type', 'Deduction Type');
        },
        drawCallback: function () { getTableLang(); }
    });
}
function applyItemTypeFields(type, preserveSourceEvent) {
    $('#earnings_fields_wrapper').toggleClass('d-none', type !== 'earning');
    $('#deductions_fields_wrapper').toggleClass('d-none', type !== 'deduction');
    $('#tax_treatment').toggleClass('required', type === 'earning');
    $('#tax_deduction_impact').toggleClass('required', type === 'deduction');
    if (!preserveSourceEvent) {
        $('#source_event_code').val('').trigger('change');
    }
    $('#source_event_code').attr('data-type', type).data('type', type);
    if (typeof initSelect2 === 'function') {
        initSelect2('#source_event_code', { mode: 'ajax' });
    }
}
function applyCalculationMethodFields(method) {
    $('#fixed_amount_wrapper').toggleClass('d-none', method !== 'fixed_amount');
    $('#percent_rate_wrapper').toggleClass('d-none', method !== 'percent_of_base_salary');
    $('#fixed_amount').toggleClass('required', method === 'fixed_amount');
    $('#percent_rate').toggleClass('required', method === 'percent_of_base_salary');
}
function resetPedTypeForm(itemType) {
    $('#pedTypeForm')[0].reset();
    $('#ped_type_id').val('');
    $('input[name="item_type"]').prop('disabled', false);
    $(`input[name="item_type"][value="${itemType}"]`).prop('checked', true);
    $('input[name="item_type"]').prop('disabled', true);
    $('.is-invalid').removeClass('is-invalid');
    $('#calculation_method').val('').trigger('change');
    $('#tax_treatment').val('').trigger('change');
    $('#tax_deduction_impact').val('').trigger('change');
    $('#statutory_report_code').val('').trigger('change');
    $('#country_code').val('').trigger('change');
    $('#ped_status').val('active').trigger('change');
    applyItemTypeFields(itemType);
    applyCalculationMethodFields('');
    const titleText = itemType === 'earning' 
        ? (langData['earning_type'] || 'Earning Type') 
        : (langData['deduction_type'] || 'Deduction Type');
    $('#pedTypeModalLabel').html(`<i class="fa-solid fa-pen-to-square me-2"></i>${titleText}`);
}
function populatePedTypeForm(row) {
    $('#ped_type_id').val(row.id);
    $(`input[name="item_type"][value="${row.item_type}"]`).prop('checked', true);
    $('input[name="item_type"]').prop('disabled', true);
    $('#item_code').val(row.item_code);
    $('#item_name_en').val(row.item_name_en);
    $('#item_name_th').val(row.item_name_th);
    $('#calculation_method').val(row.calculation_method).trigger('change');
    $('#fixed_amount').val(row.fixed_amount || '');
    $('#percent_rate').val(row.percent_rate || '');
    $('#tax_treatment').val(row.tax_treatment || '').trigger('change');
    $('#tax_deduction_impact').val(row.tax_deduction_impact || '').trigger('change');
    $('#statutory_report_code').val(row.statutory_report_code || '').trigger('change');
    $('#calc_sso').prop('checked', Number(row.calc_sso) === 1);
    $('#calc_pf').prop('checked', Number(row.calc_pf) === 1);
    $('#country_code').val(row.country_code || '').trigger('change');
    applyItemTypeFields(row.item_type, true);
    if (row.source_event_code) {
        const label = (currentLang === 'th' ? row.source_event_name_th : row.source_event_name_en) || row.source_event_code;
        const opt = new Option(label, row.source_event_code, true, true);
        $('#source_event_code').empty().append(opt).trigger('change');
    } else {
        $('#source_event_code').val('').trigger('change');
    }
    $('#ped_status').val(row.status || 'active').trigger('change');
    applyCalculationMethodFields(row.calculation_method);
    const titleText = row.item_type === 'earning' 
        ? (langData['earning_type'] || 'Earning Type') 
        : (langData['deduction_type'] || 'Deduction Type');
    $('#pedTypeModalLabel').html(`<i class="fa-solid fa-pen-to-square me-2"></i>${titleText}`);
}
function validatePedTypeForm() {
    let firstInvalid = null;
    $('#itemModal .required').each(function () {
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
function collectPedTypeFormData() {
    return {
        id: $('#ped_type_id').val() || undefined,
        item_type: $('input[name="item_type"]:checked').val(),
        item_code: $('#item_code').val().trim(),
        item_name_en: $('#item_name_en').val().trim(),
        item_name_th: $('#item_name_th').val().trim(),
        calculation_method: $('#calculation_method').val(),
        fixed_amount: $('#fixed_amount').val(),
        percent_rate: $('#percent_rate').val(),
        tax_treatment: $('#tax_treatment').val(),
        tax_deduction_impact: $('#tax_deduction_impact').val(),
        statutory_report_code: $('#statutory_report_code').val(),
        calc_sso: $('#calc_sso').is(':checked'),
        calc_pf: $('#calc_pf').is(':checked'),
        country_code: $('#country_code').val(),
        source_event_code: $('#source_event_code').val(),
        status: $('#ped_status').val(),
    };
}
$(document).ready(function () {
    initEarningTypeTable();
    initPayrollCycleTable();
    initPayrollCycleUI();
    if (typeof initSelect2 === 'function') {
        initSelect2('#calculation_method', { mode: 'static' });
        initSelect2('#tax_treatment', { mode: 'static' });
        initSelect2('#tax_deduction_impact', { mode: 'static' });
        initSelect2('#statutory_report_code', { mode: 'static', allowClear: true });
        initSelect2('#source_event_code', { mode: 'ajax', allowClear: true });
        initSelect2('#ped_status', { mode: 'static' });
        initSelect2('#country_code', { mode: 'ajax' });
        initSelect2('#payroll_frequency', { mode: 'static' });
        initSelect2('#cutoff_day_of_week', { mode: 'static' });
        initSelect2('#payment_day_of_week', { mode: 'static' });
        initSelect2('#bank_file_format_id', { mode: 'ajax' });
        initSelect2('#cycle_status', { mode: 'static' });
        initSelect2('#reset_cycle_start_month', { mode: 'static' });
        initSelect2('#bonus_status', { mode: 'static' });
        initSelect2('#ledger_filter_scheme', { mode: 'ajax' });
        initSelect2('#ledger_filter_month', { mode: 'static', selectedValue: String(new Date().getMonth() + 1) });
        initSelect2('#ledger_employee_id', { mode: 'ajax' });
    }
    initAttendanceBonusUI();
    initBonusLedgerUI();
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const tabId = $(e.target).attr('id');
        if (tabId === 'deductions-tab') {
            initDeductionTypeTable();
        }
        if (tabId === 'attendance-bonus-tab') {
            initAttendanceBonusTable();
        }
        if (tabId === 'bonus-ledger-tab') {
            initBonusLedgerTable();
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});
$(document).on('change', '#calculation_method', function () {
    applyCalculationMethodFields($(this).val());
});
$(document).on('click', '.btn-add-ped-type', function () {
    const itemType = $(this).data('item-type');
    resetPedTypeForm(itemType);
    new bootstrap.Modal(document.getElementById('itemModal')).show();
});
$(document).on('click', '.btn-edit-ped-type', function () {
    const id = $(this).data('id');
    $.ajax({
        url: `${BASE_URL}/api/ped-type.get`,
        method: 'GET',
        data: { id: id },
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                populatePedTypeForm(res.data);
                new bootstrap.Modal(document.getElementById('itemModal')).show();
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
        }
    });
});
$(document).on('click', '.btn-delete-ped-type', function () {
    const id = $(this).data('id');
    if (!id) return;
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/ped-type.delete`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['delete_success'] || 'Deleted successfully.');
                    if (tb_earning_type) tb_earning_type.ajax.reload(null, false);
                    if ($.fn.DataTable.isDataTable('#tb_deduction_type')) $('#tb_deduction_type').DataTable().ajax.reload(null, false);
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
$(document).on('submit', '#pedTypeForm', function (e) {
    e.preventDefault();
    const invalidEl = validatePedTypeForm();
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = collectPedTypeFormData();
    const $btn = $('#pedTypeForm button[type="submit"]');
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
    $.ajax({
        url: `${BASE_URL}/api/ped-type.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
                if (tb_earning_type) tb_earning_type.ajax.reload(null, false);
                if ($.fn.DataTable.isDataTable('#tb_deduction_type')) $('#tb_deduction_type').DataTable().ajax.reload(null, false);
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
function cycleDayDisplay(dayOfMonth, useLastDay) {
    if (Number(useLastDay) === 1) {
        return langData['last_day_of_month'] || 'Last day of the month';
    }
    const tpl = langData['every_day_of_month'] || 'Every {day} of the month';
    return tpl.replace('{day}', dayOfMonth);
}
function cycleDowDisplay(dow) {
    const key = 'dow_' + dow;
    return langData[key] || dow;
}
function cycleCutoffCell(row) {
    if (row.payroll_frequency === 'weekly') {
        return cycleDowDisplay(row.cutoff_day_of_week);
    }
    return cycleDayDisplay(row.cutoff_day_of_month, row.cutoff_use_last_day);
}
function cyclePaymentCell(row) {
    if (row.payroll_frequency === 'weekly') {
        return cycleDowDisplay(row.payment_day_of_week);
    }
    return cycleDayDisplay(row.payment_day_of_month, row.payment_use_last_day);
}
function cycleFrequencyBadge(freq) {
    const key = 'freq_' + freq;
    const cls = freq === 'weekly' ? 'bg-info-subtle text-info' : 'bg-primary-subtle text-primary';
    return `<span class="badge ${cls} px-2 py-1">${langData[key] || freq}</span>`;
}
function cycleStatusBadge(status) {
    const isActive = status === 'active';
    const cls = isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
    const text = isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive');
    return `<span class="badge ${cls}">${text}</span>`;
}
function escapeHtmlPc(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function cycleActionButtons(row) {
    return `<div class="d-flex justify-content-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-cycle" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-cycle" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
    </div>`;
}
let tb_payroll_cycle;
function initPayrollCycleTable() {
    if ($.fn.DataTable.isDataTable('#tb_payroll_cycle')) {
        $('#tb_payroll_cycle').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payroll_cycle = $('#tb_payroll_cycle').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/payroll-cycle.list`,
            dataSrc: 'data'
        },
        columns: [
            { data: 'cycle_name', render: d => `<strong class="text-dark">${escapeHtmlPc(d)}</strong>` },
            { data: 'payroll_frequency', render: d => cycleFrequencyBadge(d) },
            { data: null, render: (d, t, row) => cycleCutoffCell(row) },
            { data: null, render: (d, t, row) => cyclePaymentCell(row) },
            { data: null, render: (d, t, row) => escapeHtmlPc((currentLang === 'th' ? row.bank_file_format_name_th : row.bank_file_format_name_en) || row.bank_file_format_name_th || row.bank_file_format_name_en || '') },
            { data: 'status', render: d => cycleStatusBadge(d) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => cycleActionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-cycle').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-cycle">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="cycle">${langData['cycle'] || 'Cycle'}</span>
                    </button>
                `);
            }
        },
        drawCallback: function () { getTableLang(); }
    });
}
function applyFrequencyFields(freq) {
    const isWeekly = freq === 'weekly';
    $('#cutoff_dom_wrapper').toggleClass('d-none', isWeekly);
    $('#cutoff_dow_wrapper').toggleClass('d-none', !isWeekly);
    $('#payment_dom_wrapper').toggleClass('d-none', isWeekly);
    $('#payment_dow_wrapper').toggleClass('d-none', !isWeekly);
    $('#cutoff_day_of_month').toggleClass('required', !isWeekly);
    $('#payment_day_of_month').toggleClass('required', !isWeekly);
    $('#cutoff_day_of_week').toggleClass('required', isWeekly);
    $('#payment_day_of_week').toggleClass('required', isWeekly);
}
function applyOtCutoffFields(type) {
    $('#ot_custom_wrapper').toggleClass('d-none', type !== 'custom');
}
function applyLastDayToggle(checkboxId, inputId) {
    const checked = $(`#${checkboxId}`).is(':checked');
    $(`#${inputId}`).prop('disabled', checked).toggleClass('required', !checked);
    if (checked) $(`#${inputId}`).val('');
}
function resetCycleForm() {
    $('#payrollCycleForm')[0].reset();
    $('#cycle_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#payroll_frequency').val('').trigger('change');
    $('#cutoff_day_of_week').val('').trigger('change');
    $('#payment_day_of_week').val('').trigger('change');
    $('#bank_file_format_id').val('').trigger('change');
    $('#cycle_status').val('active').trigger('change');
    $('#cutoff_day_of_month, #payment_day_of_month, #ot_cutoff_day_of_month').prop('disabled', false);
    applyFrequencyFields('');
    applyOtCutoffFields('same_as_attendance');
}
function populateCycleForm(row) {
    $('#cycle_id').val(row.id);
    $('#cycle_name').val(row.cycle_name);
    $('#payroll_frequency').val(row.payroll_frequency).trigger('change');
    applyFrequencyFields(row.payroll_frequency);
    if (row.payroll_frequency === 'weekly') {
        $('#cutoff_day_of_week').val(row.cutoff_day_of_week).trigger('change');
        $('#payment_day_of_week').val(row.payment_day_of_week).trigger('change');
    } else {
        $('#cutoff_use_last_day').prop('checked', Number(row.cutoff_use_last_day) === 1);
        $('#cutoff_day_of_month').val(row.cutoff_day_of_month || '');
        applyLastDayToggle('cutoff_use_last_day', 'cutoff_day_of_month');
        $('#payment_use_last_day').prop('checked', Number(row.payment_use_last_day) === 1);
        $('#payment_day_of_month').val(row.payment_day_of_month || '');
        applyLastDayToggle('payment_use_last_day', 'payment_day_of_month');
    }
    $(`input[name="ot_cutoff_type"][value="${row.ot_cutoff_type}"]`).prop('checked', true);
    applyOtCutoffFields(row.ot_cutoff_type);
    if (row.ot_cutoff_type === 'custom') {
        $('#ot_cutoff_use_last_day').prop('checked', Number(row.ot_cutoff_use_last_day) === 1);
        $('#ot_cutoff_day_of_month').val(row.ot_cutoff_day_of_month || '');
        applyLastDayToggle('ot_cutoff_use_last_day', 'ot_cutoff_day_of_month');
    }
    if (row.bank_file_format_id) {
        const bankFormatLabel = (currentLang === 'th' ? row.bank_file_format_name_th : row.bank_file_format_name_en) || row.bank_file_format_name_th || row.bank_file_format_name_en || '';
        const opt = new Option(bankFormatLabel, row.bank_file_format_id, true, true);
        $('#bank_file_format_id').append(opt).trigger('change');
    } else {
        $('#bank_file_format_id').val('').trigger('change');
    }
    $('#cycle_status').val(row.status).trigger('change');
}
function validateCycleForm() {
    let firstInvalid = null;
    $('#payrollCycleModal .required').each(function () {
        const $el = $(this);
        if ($el.closest('.d-none').length > 0 || $el.is(':disabled')) return;
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
function collectCycleFormData() {
    const freq = $('#payroll_frequency').val();
    const data = {
        id: $('#cycle_id').val() || undefined,
        cycle_name: $('#cycle_name').val().trim(),
        payroll_frequency: freq,
        ot_cutoff_type: $('input[name="ot_cutoff_type"]:checked').val(),
        bank_file_format_id: $('#bank_file_format_id').val(),
        status: $('#cycle_status').val()
    };
    if (freq === 'weekly') {
        data.cutoff_day_of_week = $('#cutoff_day_of_week').val();
        data.payment_day_of_week = $('#payment_day_of_week').val();
    } else {
        data.cutoff_day_of_month = $('#cutoff_day_of_month').val();
        data.cutoff_use_last_day = $('#cutoff_use_last_day').is(':checked');
        data.payment_day_of_month = $('#payment_day_of_month').val();
        data.payment_use_last_day = $('#payment_use_last_day').is(':checked');
    }
    if (data.ot_cutoff_type === 'custom') {
        data.ot_cutoff_day_of_month = $('#ot_cutoff_day_of_month').val();
        data.ot_cutoff_use_last_day = $('#ot_cutoff_use_last_day').is(':checked');
    }
    return data;
}
function initPayrollCycleUI() {
    $(document).on('click', '.btn-add-cycle', function () {
        resetCycleForm();
        new bootstrap.Modal(document.getElementById('payrollCycleModal')).show();
    });
    $(document).on('click', '.btn-edit-cycle', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/payroll-cycle.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    resetCycleForm();
                    populateCycleForm(res.data);
                    new bootstrap.Modal(document.getElementById('payrollCycleModal')).show();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
            }
        });
    });
    $(document).on('change', '#payroll_frequency', function () {
        applyFrequencyFields($(this).val());
    });
    $(document).on('change', 'input[name="ot_cutoff_type"]', function () {
        applyOtCutoffFields($(this).val());
    });
    $(document).on('change', '#cutoff_use_last_day', function () {
        applyLastDayToggle('cutoff_use_last_day', 'cutoff_day_of_month');
    });
    $(document).on('change', '#payment_use_last_day', function () {
        applyLastDayToggle('payment_use_last_day', 'payment_day_of_month');
    });
    $(document).on('change', '#ot_cutoff_use_last_day', function () {
        applyLastDayToggle('ot_cutoff_use_last_day', 'ot_cutoff_day_of_month');
    });
    $(document).on('submit', '#payrollCycleForm', function (e) {
        e.preventDefault();
        const invalidEl = validateCycleForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const payload = collectCycleFormData();
        const $btn = $('#payrollCycleForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
        $.ajax({
            url: `${BASE_URL}/api/payroll-cycle.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('payrollCycleModal')).hide();
                    if (tb_payroll_cycle) tb_payroll_cycle.ajax.reload(null, false);
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
    $(document).on('click', '.btn-delete-cycle', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/payroll-cycle.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        if (tb_payroll_cycle) tb_payroll_cycle.ajax.reload(null, false);
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
let tb_attendance_bonus;
function conditionsBadges(row) {
    const conds = [];
    if (Number(row.condition_no_absent)) conds.push(langData['condition_no_absent'] || 'No absences');
    if (Number(row.condition_no_late)) conds.push(langData['condition_no_late'] || 'No late arrivals');
    if (Number(row.condition_no_leave)) conds.push(langData['condition_no_leave'] || 'No leave taken');
    if (Number(row.condition_no_time_adjust)) conds.push(langData['condition_no_time_adjust'] || 'No time clock adjustments');
    return conds.map(c => `<span class="badge bg-light text-dark border mb-1 me-1">${c}</span>`).join('');
}
function bonusAmountSummary(row) {
    const start = parseFloat(row.starting_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const inc = parseFloat(row.increment_amount || 0);
    let html = `<div>${start}</div>`;
    if (inc > 0) {
        html += `<div class="text-muted small">+${inc.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${langData['per_month'] || '/month'}</div>`;
    }
    if (row.max_amount !== null && row.max_amount !== undefined) {
        const cap = parseFloat(row.max_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        html += `<div class="text-muted small">${langData['max_amount'] || 'Max'}: ${cap}</div>`;
    } else {
        html += `<div class="text-muted small">${langData['no_cap'] || 'No cap'}</div>`;
    }
    return html;
}
function bonusResetCycleSummary(row) {
    const months = row.reset_cycle_months;
    let basisText;
    if (row.reset_cycle_basis === 'fixed_month') {
        const monthKey = 'month_' + row.reset_cycle_start_month;
        basisText = langData[monthKey] || row.reset_cycle_start_month;
    } else {
        basisText = langData['basis_employee_anniversary'] || "From employee's first eligible month";
    }
    return `<div>${months} ${langData['months_unit'] || 'months'}</div><div class="text-muted small">${basisText}</div>`;
}
function bonusStatusBadge(status) {
    const isActive = status === 'active';
    const cls = isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
    const text = isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive');
    return `<span class="badge ${cls}">${text}</span>`;
}
function bonusActionButtons(row) {
    return `<div class="d-flex justify-content-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-bonus" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-sm btn-outline-danger btn-delete-bonus" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
    </div>`;
}
function initAttendanceBonusTable() {
    if ($.fn.DataTable.isDataTable('#tb_attendance_bonus')) {
        $('#tb_attendance_bonus').DataTable().ajax.reload(null, false);
        return;
    }
    tb_attendance_bonus = $('#tb_attendance_bonus').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/attendance-bonus.list`,
            dataSrc: 'data'
        },
        columns: [
            { data: 'scheme_name', render: d => `<strong class="text-dark">${escapeHtmlPc(d)}</strong>` },
            { data: null, render: (d, t, row) => conditionsBadges(row) },
            { data: null, render: (d, t, row) => bonusAmountSummary(row) },
            { data: null, render: (d, t, row) => bonusResetCycleSummary(row) },
            { data: 'status', render: d => bonusStatusBadge(d) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => bonusActionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-bonus').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn text-white ms-1 btn-add-bonus" style="background-color:#FF9900;border-color:#FF9900;">
                        <i class="fa-solid fa-plus me-2"></i><span data-i18n="add_attendance_bonus">${langData['add_attendance_bonus'] || 'Add Attendance Bonus Scheme'}</span>
                    </button>
                `);
            }
        },
        drawCallback: function () { getTableLang(); }
    });
}
function applyResetBasisFields(basis) {
    const isFixed = basis === 'fixed_month';
    $('#reset_start_month_wrapper').toggleClass('d-none', !isFixed);
    $('#reset_cycle_start_month').toggleClass('required', isFixed);
}
function resetBonusForm() {
    $('#attendanceBonusForm')[0].reset();
    $('#bonus_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#reset_cycle_start_month').val('').trigger('change');
    $('#bonus_status').val('active').trigger('change');
    applyResetBasisFields('employee_anniversary');
    $('#attendanceBonusModalLabel').text(langData['add_attendance_bonus'] || 'Add Attendance Bonus Scheme');
}
function populateBonusForm(row) {
    $('#bonus_id').val(row.id);
    $('#scheme_name').val(row.scheme_name);
    $('#condition_no_absent').prop('checked', Number(row.condition_no_absent) === 1);
    $('#condition_no_late').prop('checked', Number(row.condition_no_late) === 1);
    $('#condition_no_leave').prop('checked', Number(row.condition_no_leave) === 1);
    $('#condition_no_time_adjust').prop('checked', Number(row.condition_no_time_adjust) === 1);
    $('#starting_amount').val(row.starting_amount);
    $('#increment_amount').val(row.increment_amount);
    $('#max_amount').val(row.max_amount !== null && row.max_amount !== undefined ? row.max_amount : '');
    $('#reset_cycle_months').val(row.reset_cycle_months);
    $(`input[name="reset_cycle_basis"][value="${row.reset_cycle_basis}"]`).prop('checked', true);
    applyResetBasisFields(row.reset_cycle_basis);
    if (row.reset_cycle_basis === 'fixed_month') {
        $('#reset_cycle_start_month').val(row.reset_cycle_start_month).trigger('change');
    }
    $('#bonus_status').val(row.status).trigger('change');
    $('#attendanceBonusModalLabel').text(langData['edit_attendance_bonus'] || 'Edit Attendance Bonus Scheme');
}
function validateBonusForm() {
    let firstInvalid = null;
    $('#attendanceBonusModal .required').each(function () {
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
    if (!firstInvalid) {
        const anyCondition = $('#condition_no_absent, #condition_no_late, #condition_no_leave, #condition_no_time_adjust').is(':checked');
        if (!anyCondition) {
            showWarning(langData['conditions_hint'] || 'Select at least one condition.');
            firstInvalid = $('#condition_no_absent');
        }
    }
    return firstInvalid;
}
function collectBonusFormData() {
    return {
        id: $('#bonus_id').val() || undefined,
        scheme_name: $('#scheme_name').val().trim(),
        condition_no_absent: $('#condition_no_absent').is(':checked'),
        condition_no_late: $('#condition_no_late').is(':checked'),
        condition_no_leave: $('#condition_no_leave').is(':checked'),
        condition_no_time_adjust: $('#condition_no_time_adjust').is(':checked'),
        starting_amount: $('#starting_amount').val(),
        increment_amount: $('#increment_amount').val() || 0,
        max_amount: $('#max_amount').val(),
        reset_cycle_months: $('#reset_cycle_months').val(),
        reset_cycle_basis: $('input[name="reset_cycle_basis"]:checked').val(),
        reset_cycle_start_month: $('#reset_cycle_start_month').val(),
        status: $('#bonus_status').val()
    };
}
function initAttendanceBonusUI() {
    $(document).on('click', '.btn-add-bonus', function () {
        resetBonusForm();
        new bootstrap.Modal(document.getElementById('attendanceBonusModal')).show();
    });
    $(document).on('click', '.btn-edit-bonus', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/attendance-bonus.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    resetBonusForm();
                    populateBonusForm(res.data);
                    new bootstrap.Modal(document.getElementById('attendanceBonusModal')).show();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
            }
        });
    });
    $(document).on('change', 'input[name="reset_cycle_basis"]', function () {
        applyResetBasisFields($(this).val());
    });
    $(document).on('submit', '#attendanceBonusForm', function (e) {
        e.preventDefault();
        const invalidEl = validateBonusForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const payload = collectBonusFormData();
        const $btn = $('#attendanceBonusForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
        $.ajax({
            url: `${BASE_URL}/api/attendance-bonus.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('attendanceBonusModal')).hide();
                    if (tb_attendance_bonus) tb_attendance_bonus.ajax.reload(null, false);
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
    $(document).on('click', '.btn-delete-bonus', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/attendance-bonus.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        if (tb_attendance_bonus) tb_attendance_bonus.ajax.reload(null, false);
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
let tb_bonus_ledger;
function ledgerStatusBadge(status) {
    const map = {
        passed: { cls: 'bg-success-subtle text-success', key: 'status_passed', fallback: 'Passed' },
        failed: { cls: 'bg-danger-subtle text-danger', key: 'status_failed', fallback: 'Failed' },
        pending_data: { cls: 'bg-secondary-subtle text-secondary', key: 'pending_data', fallback: 'Pending Data' }
    };
    const cfg = map[status] || map.pending_data;
    return `<span class="badge ${cfg.cls}">${langData[cfg.key] || cfg.fallback}</span>`;
}
function ledgerLockedBadge(row) {
    if (row.locked_at) {
        return `<span class="badge bg-primary-subtle text-primary"><i class="fa-solid fa-lock me-1"></i>${langData['locked'] || 'Locked'}</span>`;
    }
    return `<span class="badge bg-light text-dark border">${langData['unlocked'] || 'Unlocked'}</span>`;
}
function ledgerActionButtons(row) {
    const isLocked = !!row.locked_at;
    let html = '<div class="d-flex justify-content-center gap-1">';
    if (!isLocked) {
        html += `<button type="button" class="btn btn-sm btn-link text-secondary btn-edit-ledger" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fa-solid fa-pen-to-square"></i></button>`;
        html += `<button type="button" class="btn btn-sm btn-link text-primary btn-lock-ledger" data-id="${row.id}" title="${langData['lock_entry'] || 'Lock'}"><i class="fa-solid fa-lock"></i></button>`;
        html += `<button type="button" class="btn btn-sm btn-link text-danger btn-delete-ledger" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
    }
    html += '</div>';
    return html;
}
function currentLedgerFilter() {
    return {
        schemeId: $('#ledger_filter_scheme').val(),
        year: $('#ledger_filter_year').val(),
        month: $('#ledger_filter_month').val()
    };
}
function initBonusLedgerTable() {
    if ($.fn.DataTable.isDataTable('#tb_bonus_ledger')) {
        $('#tb_bonus_ledger').DataTable().ajax.reload(null, false);
        return;
    }
    tb_bonus_ledger = $('#tb_bonus_ledger').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/attendance-bonus.ledger.list`,
            data: function (d) {
                const f = currentLedgerFilter();
                d.scheme_id = f.schemeId;
                d.year = f.year;
                d.month = f.month;
            },
            dataSrc: 'data'
        },
        columns: [
            { data: null, render: (d, t, row) => escapeHtmlPc(currentLang === 'th' ? row.employee_name_th : row.employee_name_en) },
            { data: 'status', render: d => ledgerStatusBadge(d) },
            { data: 'streak_count' },
            { data: 'cycle_count' },
            { data: 'amount', render: d => parseFloat(d || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
            { data: null, render: (d, t, row) => ledgerLockedBadge(row) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => ledgerActionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-ledger').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn text-white ms-1 btn-add-ledger" style="background-color:#FF9900;border-color:#FF9900;">
                        <i class="fa-solid fa-plus me-2"></i><span data-i18n="add_ledger_entry">${langData['add_ledger_entry'] || 'Add Ledger Entry'}</span>
                    </button>
                `);
            }
        },
        drawCallback: function () { getTableLang(); }
    });
}
function reloadLedgerTable() {
    if ($.fn.DataTable.isDataTable('#tb_bonus_ledger')) {
        $('#tb_bonus_ledger').DataTable().ajax.reload(null, false);
    }
}
function updateLedgerPeriodDisplay() {
    const f = currentLedgerFilter();
    const schemeText = $('#ledger_filter_scheme option:selected').text() || $('#ledger_filter_scheme').val();
    const monthKey = 'month_' + f.month;
    const monthText = langData[monthKey] || f.month;
    $('#ledger_period_display').text(`${schemeText} — ${monthText} ${f.year}`);
}
function resetLedgerForm() {
    $('#ledgerEntryForm')[0].reset();
    $('#ledger_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#ledger_employee_id').val(null).trigger('change');
    $('#ledger_status_passed').prop('checked', true);
    $('#ledger_fail_reasons_wrapper').addClass('d-none');
    $('.fail-reason-check').prop('checked', false);
    $('#fail_reasons').val('');
    const f = currentLedgerFilter();
    $('#ledger_scheme_id').val(f.schemeId);
    $('#ledger_period_year').val(f.year);
    $('#ledger_period_month').val(f.month);
    updateLedgerPeriodDisplay();
    $('#ledgerEntryModalLabel').text(langData['add_ledger_entry'] || 'Add Ledger Entry');
}
function populateLedgerForm(row) {
    $('#ledger_id').val(row.id);
    const opt = new Option(currentLang === 'th' ? row.employee_name_th : row.employee_name_en, row.employee_id, true, true);
    $('#ledger_employee_id').empty().append(opt).trigger('change');
    $('#ledger_employee_id').prop('disabled', true);
    $('#ledger_scheme_id').val(row.scheme_id);
    $('#ledger_period_year').val(row.period_year);
    $('#ledger_period_month').val(row.period_month);
    const monthKey = 'month_' + row.period_month;
    $('#ledger_period_display').text(`${row.scheme_name} — ${langData[monthKey] || row.period_month} ${row.period_year}`);
    $(`input[name="ledger_status"][value="${row.status}"]`).prop('checked', true);
    $('#ledger_fail_reasons_wrapper').toggleClass('d-none', row.status !== 'failed');
    $('#fail_reasons').val(row.fail_reasons || '');
    $('#ledgerEntryModalLabel').text(langData['edit_ledger_entry'] || 'Edit Ledger Entry');
}
function collectLedgerFormData() {
    return {
        id: $('#ledger_id').val() || undefined,
        employee_id: $('#ledger_employee_id').val(),
        scheme_id: $('#ledger_scheme_id').val(),
        period_year: $('#ledger_period_year').val(),
        period_month: $('#ledger_period_month').val(),
        status: $('input[name="ledger_status"]:checked').val(),
        fail_reasons: $('#fail_reasons').val().trim()
    };
}
function initBonusLedgerUI() {
    $(document).on('change', '#ledger_filter_scheme, #ledger_filter_year, #ledger_filter_month', function () {
        reloadLedgerTable();
    });
    $(document).on('click', '.btn-add-ledger', function () {
        const f = currentLedgerFilter();
        if (!f.schemeId || !f.year || !f.month) {
            showWarning(langData['select_scheme_and_period_first'] || 'Please select a scheme, year, and month first.');
            return;
        }
        resetLedgerForm();
        $('#ledger_employee_id').prop('disabled', false);
        new bootstrap.Modal(document.getElementById('ledgerEntryModal')).show();
    });
    $(document).on('click', '.btn-edit-ledger', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/attendance-bonus.ledger.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    resetLedgerForm();
                    populateLedgerForm(res.data);
                    new bootstrap.Modal(document.getElementById('ledgerEntryModal')).show();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
            }
        });
    });
    $(document).on('change', 'input[name="ledger_status"]', function () {
        $('#ledger_fail_reasons_wrapper').toggleClass('d-none', $(this).val() !== 'failed');
    });
    $(document).on('change', '.fail-reason-check', function () {
        const labels = [];
        $('.fail-reason-check:checked').each(function () {
            const key = $(this).data('label-key');
            labels.push(langData[key] || key);
        });
        if (labels.length > 0) {
            $('#fail_reasons').val(labels.join(', '));
        }
    });
    $(document).on('submit', '#ledgerEntryForm', function (e) {
        e.preventDefault();
        const invalidEl = (function () {
            let firstInvalid = null;
            $('#ledgerEntryModal .required').each(function () {
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
        })();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const payload = collectLedgerFormData();
        const $btn = $('#ledgerEntryForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
        $.ajax({
            url: `${BASE_URL}/api/attendance-bonus.ledger.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('ledgerEntryModal')).hide();
                    reloadLedgerTable();
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
    $(document).on('click', '.btn-lock-ledger', function () {
        const id = $(this).data('id');
        showConfirm(langData['confirm_lock_title'] || 'Lock this entry?', langData['confirm_lock_message'] || 'Once locked, this entry can no longer be edited or deleted.', function () {
            $.ajax({
                url: `${BASE_URL}/api/attendance-bonus.ledger.lock`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['save_success'] || 'Saved successfully.');
                        reloadLedgerTable();
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to lock entry.');
                    }
                },
                error: function () {
                    showWarning(langData['save_failed'] || 'An error occurred.');
                }
            });
        });
    });
    $(document).on('click', '.btn-delete-ledger', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/attendance-bonus.ledger.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        reloadLedgerTable();
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
