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
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-warning btn-edit-ped-type" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-ped-type" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
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
function injectSeedDefaultsButton(api) {
    const $wrapper = $(api.table().container());
    const $searchDiv = $wrapper.find('.dt-search');
    if ($searchDiv.find('.btn-seed-ped-defaults').length === 0) {
        const btn = `
            <button type="button" class="btn btn-outline-secondary ms-1 btn-seed-ped-defaults">
                <i class="fa-solid fa-download me-1"></i><span data-i18n="load_default_items">${langData['load_default_items'] || 'Load Default Items'}</span>
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
            data: function (d, settings) {
                d.item_type = 'earning';
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
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
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-center all', render: (d, t, row) => actionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            injectAddButton(self, 'earning', 'earning_type', 'Income Type');
            injectSeedDefaultsButton(self);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, server mode. Excludes the composite item-name+tags cell (1), the boolean
            // calc_sso/calc_pf icons (4, 5), and actions (7).
            initExcelColumnFilters(self, {
                mode: 'server',
                columns: [
                    { index: 0, key: 'item_code' },
                    { index: 2, key: 'calculation_method' },
                    { index: 3, key: 'tax_treatment' },
                    { index: 6, key: 'status' },
                ],
                fetchValues: function (key, done) {
                    $.ajax({
                        url: `${BASE_URL}/api/ped-type.column-values`,
                        method: 'POST',
                        data: { item_type: 'earning', column: key, column_filters: getColumnFilterValues(self) },
                        dataType: 'json'
                    }).done(function (res) {
                        done((res && res.values) || []);
                    }).fail(function () {
                        done([]);
                    });
                },
                onApply: function () { self.ajax.reload(null, false); }
            });
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
            data: function (d, settings) {
                d.item_type = 'deduction';
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
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
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-center all', render: (d, t, row) => actionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            injectAddButton(self, 'deduction', 'deduction_type', 'Deduction Type');
            injectSeedDefaultsButton(self);
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, server mode. Excludes the composite item-name+tags cell (1) and actions (5).
            initExcelColumnFilters(self, {
                mode: 'server',
                columns: [
                    { index: 0, key: 'item_code' },
                    { index: 2, key: 'calculation_method' },
                    { index: 3, key: 'tax_deduction_impact' },
                    { index: 4, key: 'status' },
                ],
                fetchValues: function (key, done) {
                    $.ajax({
                        url: `${BASE_URL}/api/ped-type.column-values`,
                        method: 'POST',
                        data: { item_type: 'deduction', column: key, column_filters: getColumnFilterValues(self) },
                        dataType: 'json'
                    }).done(function (res) {
                        done((res && res.values) || []);
                    }).fail(function () {
                        done([]);
                    });
                },
                onApply: function () { self.ajax.reload(null, false); }
            });
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
        ? (langData['earning_type'] || 'Income Type') 
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
        ? (langData['earning_type'] || 'Income Type') 
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
    }
    // 2026-08-29, explicit request: "ตัดเบี้ยขยันและการบันทึกเบี้ยขยันออกจากการตั้งค่า" -- Attendance
    // Bonus/Ledger UI init removed along with their tabs/modals (see the removal comment on
    // #companySetupTabs in payroll-configuration.php). initAttendanceBonusUI()/initBonusLedgerUI()/
    // initAttendanceBonusTable()/initBonusLedgerTable() and every function they alone called are
    // gone too, not just uncalled -- see this file's own git history if any of it is ever needed
    // again.
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const tabId = $(e.target).attr('id');
        if (tabId === 'deductions-tab') {
            initDeductionTypeTable();
        }
        if (tabId === 'attendance-deduction-tab') {
            loadAttendanceDeductionCards();
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
$(document).on('click', '.btn-seed-ped-defaults', function () {
    const title = langData['load_default_items'] || 'Load Default Items';
    const msg = langData['confirm_load_default_items_message'] || 'Add the system\'s starter set of common earning/deduction items? Any item code you already have is skipped -- nothing gets overwritten or duplicated.';
    showConfirm(title, msg, function () {
        $.ajax({
            url: `${BASE_URL}/api/ped-type.seed-defaults`,
            method: 'POST',
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    const tpl = langData['load_default_items_result'] || '{inserted} item(s) added, {skipped} already existed.';
                    showSuccess(tpl.replace('{inserted}', res.inserted).replace('{skipped}', res.skipped));
                    if (tb_earning_type) tb_earning_type.ajax.reload(null, false);
                    if (tb_deduction_type) tb_deduction_type.ajax.reload(null, false);
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            },
            error: function () {
                showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
            }
        });
    });
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
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-warning btn-edit-cycle" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-cycle" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
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
            // 2026-08-28: className:'all' keeps this last actions column from collapsing into the
            // Responsive expand row.
            { data: null, orderable: false, className: 'text-center all', render: (d, t, row) => cycleActionButtons(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const self = this.api();
            const $wrapper = $(self.table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-cycle').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-cycle">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="cycle">${langData['cycle'] || 'Schedule'}</span>
                    </button>
                `);
            }
            // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter
            // rollout, client mode. Excludes actions (6).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 0, key: 'cycle_name' },
                    { index: 1, key: 'frequency' },
                    { index: 2, key: 'cutoff' },
                    { index: 3, key: 'payment' },
                    { index: 4, key: 'bank_file_format' },
                    { index: 5, key: 'status' },
                ]
            });
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
// 2026-08-29, explicit request: "ตัดเบี้ยขยันและการบันทึกเบี้ยขยันออกจากการตั้งค่า และไม่นำไปคำนวณในเงินเดือน แต่ใน Income ยังคงมีไว้ เพราะจะเชื่อมมาจาก Origami แทน" -- the whole
// Attendance Bonus (scheme config) and Ledger (streak recording) feature -- 20 functions
// (bonusAmountSummary/initAttendanceBonusTable/initAttendanceBonusUI/ledgerStatusBadge/
// initBonusLedgerTable/initBonusLedgerUI/etc), their table inits, and every CRUD handler --
// removed entirely along with the view-side tabs/modals (see the removal comment on
// #companySetupTabs in payroll-configuration.php). PayrollRunModel::recalculate() no longer
// pulls attendance_bonus_ledger into payroll lines either (see that model's own comment).
// The DILIGENCE catalog row in the Income tab itself is untouched -- it becomes a pure sync
// target now, matched by item_code the same generic way ค่าเที่ยว/TRIP_ALLOW already is (see
// SyncPayResolver's generic item-matching loop). 2026-08-29 same-day follow-up, explicit:
// "ถ้ามีลบเพิ่มไฟล์ .sql ให้ด้วยครับ" -- backend model classes (AttendanceBonusSchemeModel/
// AttendanceBonusLedgerModel), their controller endpoints/routes, and the
// attendance_bonus_schemes/attendance_bonus_ledger tables themselves are now fully removed
// too, not left in place -- see database/migrations/2026-08-29_drop_attendance_bonus_tables.sql.

/* ==================== ATTENDANCE DEDUCTION RULES (Late / Absent / Unpaid Leave) ====================
 * 2026-08-21: moved from a button+shared-modal-with-pill-switcher on the Deductions tab to its own
 * tab (#attendance-deduction-pane) showing all 3 events as cards -- each card's "Configure" button
 * opens this modal already scoped to that one event (openAttendanceDeductionRuleModal(eventCode)),
 * so there's no in-modal event picker anymore. currentAttendanceRules caches all 3 events' rules
 * (refetched on every card-tab-show and every modal-open, cheap since it's a single GET returning
 * all 3 at once -- same "always pull fresh" spirit as the DataTable Edit-button convention
 * elsewhere in this codebase, just applied to a fixed 3-item list instead). currentAttendanceBrackets
 * is the source-of-truth array for the currently-open event's bracket editor -- same "rebuild rows
 * from an array" idiom used elsewhere in this codebase (approval-workflow.js's step editor,
 * payslip-template.js's field list).
 *
 * rate_unit (2026-08-21, "นาทีละกี่บาท ชั่วโมงละกี่บาท") replaces the old fixed-per-event unit
 * assumption (late was always "per minute", absent/unpaid_leave always "per day") -- freely
 * choosable per rule now, so ATTENDANCE_RATE_UNIT_LABELS is keyed by rate_unit, not by event.
 */
let currentAttendanceRules = {};
let currentAttendanceEvent = 'late';
let currentAttendanceBrackets = [];

const ATTENDANCE_DEFAULT_RATE_UNIT = { late: 'minute', absent: 'day', unpaid_leave: 'day', leave_pending: 'day' };

const ATTENDANCE_RATE_UNIT_LABELS = {
    minute: {
        flat: () => langData['attendance_deduction_rate_per_unit_minute'] || 'Deduction Amount per Minute',
        min: () => langData['attendance_deduction_bracket_min_minute'] || 'From (min)',
        max: () => langData['attendance_deduction_bracket_max_minute'] || 'To (min, blank = no limit)'
    },
    hour: {
        flat: () => langData['attendance_deduction_rate_per_unit_hour'] || 'Deduction Amount per Hour',
        min: () => langData['attendance_deduction_bracket_min_hour'] || 'From (hours)',
        max: () => langData['attendance_deduction_bracket_max_hour'] || 'To (hours, blank = no limit)'
    },
    day: {
        flat: () => langData['attendance_deduction_rate_per_unit_day'] || 'Deduction Amount per Day',
        min: () => langData['attendance_deduction_bracket_min_day'] || 'From (days)',
        max: () => langData['attendance_deduction_bracket_max_day'] || 'To (days, blank = no limit)'
    }
};

function attendanceEventLabel(eventCode) {
    const map = { late: 'attendance_deduction_event_late', absent: 'attendance_deduction_event_absent', unpaid_leave: 'attendance_deduction_event_unpaid_leave', leave_pending: 'attendance_deduction_event_leave_pending' };
    return langData[map[eventCode]] || eventCode;
}

function applyAttendanceRateUnitLabels(rateUnit) {
    const labels = ATTENDANCE_RATE_UNIT_LABELS[rateUnit] || ATTENDANCE_RATE_UNIT_LABELS.minute;
    $('#attendanceFlatLabel').text(labels.flat());
    $('#attendanceBracketMinLabel').text(labels.min());
    $('#attendanceBracketMaxLabel').text(labels.max());
}
function applyAttendanceDeductionMethodFields(method) {
    $('#attendanceFlatSection').toggleClass('d-none', method !== 'flat_amount');
    $('#attendancePercentSection').toggleClass('d-none', method !== 'percent_of_rate');
    $('#attendanceBracketSection').toggleClass('d-none', method !== 'tiered_bracket');
    $('#attendanceRateUnitWrapper').toggleClass('d-none', method === 'percent_of_rate');
    applyAttendanceRateUnitLabels($('#attendanceRateUnit').val() || 'minute');
}
$(document).on('change', '#attendanceDeductionMethod', function () {
    applyAttendanceDeductionMethodFields($(this).val());
});
$(document).on('change', '#attendanceRateUnit', function () {
    applyAttendanceRateUnitLabels($(this).val());
});

function renderAttendanceBracketRows() {
    const $tbody = $('#attendanceBracketRows');
    if (!currentAttendanceBrackets.length) {
        $tbody.html(`<tr><td colspan="4" class="text-center text-muted small py-2">-</td></tr>`);
        return;
    }
    $tbody.html(currentAttendanceBrackets.map((b, i) => `
        <tr>
            <td><input type="number" min="0" class="form-control form-control-sm" value="${b.min_units ?? ''}" onchange="updateAttendanceBracketField(${i}, 'min_units', this.value)"></td>
            <td><input type="number" min="0" class="form-control form-control-sm" value="${b.max_units ?? ''}" placeholder="${langData['no_limit'] || 'No limit'}" onchange="updateAttendanceBracketField(${i}, 'max_units', this.value)"></td>
            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" value="${b.deduction_amount ?? ''}" onchange="updateAttendanceBracketField(${i}, 'deduction_amount', this.value)"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeAttendanceBracketRow(${i})"><i class="fa-solid fa-trash"></i></button></td>
        </tr>
    `).join(''));
}
function updateAttendanceBracketField(index, field, value) {
    currentAttendanceBrackets[index][field] = value === '' ? null : value;
}
function addAttendanceBracketRow() {
    currentAttendanceBrackets.push({ min_units: null, max_units: null, deduction_amount: null });
    renderAttendanceBracketRows();
}
function removeAttendanceBracketRow(index) {
    currentAttendanceBrackets.splice(index, 1);
    renderAttendanceBracketRows();
}

function renderAttendanceDeductionEvent(eventCode) {
    currentAttendanceEvent = eventCode;
    const r = currentAttendanceRules[eventCode] || { method_code: 'percent_of_rate', rate_unit: ATTENDANCE_DEFAULT_RATE_UNIT[eventCode], rate_per_unit: null, multiplier_rate: '1.00', method_name_th: '', method_name_en: '', brackets: [] };
    const $method = $('#attendanceDeductionMethod');
    const methodLabel = (currentLang === 'th' ? r.method_name_th : r.method_name_en) || langData['attendance_deduction_method_percent_of_rate'] || 'Percent of Rate';
    $method.empty().append(new Option(methodLabel, r.method_code, true, true)).trigger('change.select2');
    $('#attendanceRateUnit').val(r.rate_unit || ATTENDANCE_DEFAULT_RATE_UNIT[eventCode] || 'minute').trigger('change.select2');
    applyAttendanceDeductionMethodFields(r.method_code);
    $('#attendanceRatePerUnit').val(r.rate_per_unit || '');
    $('#attendanceMultiplierRate').val(r.multiplier_rate || '1.00');
    currentAttendanceBrackets = (r.brackets || []).map(b => ({ min_units: b.min_units, max_units: b.max_units, deduction_amount: b.deduction_amount }));
    renderAttendanceBracketRows();
}

function openAttendanceDeductionRuleModal(eventCode) {
    // #attendanceRateUnit is select2-static -- already initialized once by app.js's global
    // `.select2-static` sweep on page load (2026-08-21 bug fix: re-running initSelect2 static mode
    // here on every open re-synced its `data` array into real <option> elements each time without
    // clearing the previous set -- select2('destroy') tears down the widget but doesn't strip
    // options it added, so the dropdown showed every option duplicated after the modal was opened
    // once. #attendanceDeductionMethod is select2-remote/ajax mode instead, which doesn't upfront-
    // populate <option> elements this way, so re-initializing it per-open (unchanged below) is safe.
    initSelect2('#attendanceDeductionMethod', { mode: 'ajax' });
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.get-all`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            currentAttendanceRules = res.data;
            renderAttendanceDeductionEvent(eventCode);
            $('#attendanceDeductionRuleModalEvent').text(attendanceEventLabel(eventCode));
            new bootstrap.Modal(document.getElementById('attendanceDeductionRuleModal')).show();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
function saveAttendanceDeductionRule() {
    const method = $('#attendanceDeductionMethod').val();
    if (!method) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = { event_code: currentAttendanceEvent, method_code: method };
    if (method === 'flat_amount') {
        payload.rate_unit = $('#attendanceRateUnit').val() || 'minute';
        payload.rate_per_unit = parseFloat($('#attendanceRatePerUnit').val());
        if (!payload.rate_per_unit || payload.rate_per_unit <= 0) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
    } else if (method === 'percent_of_rate') {
        payload.multiplier_rate = parseFloat($('#attendanceMultiplierRate').val()) || 1.00;
    } else if (method === 'tiered_bracket') {
        payload.rate_unit = $('#attendanceRateUnit').val() || 'minute';
        if (!currentAttendanceBrackets.length) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        payload.brackets = currentAttendanceBrackets.map(b => ({
            min_units: parseInt(b.min_units) || 0,
            max_units: (b.max_units === null || b.max_units === '') ? null : parseInt(b.max_units),
            deduction_amount: parseFloat(b.deduction_amount) || 0
        }));
    }
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                const modalEl = document.getElementById('attendanceDeductionRuleModal');
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) { modalInstance.hide(); }
                loadAttendanceDeductionCards();
            } else { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
}

/* Cards on #attendance-deduction-pane -- 3 fixed items (Late/Absent/Unpaid Leave), not a DataTable
 * (same reasoning as the Permission Matrix page: a fixed small grid, not a record list to paginate). */
function attendanceDeductionCardSummary(eventCode) {
    const r = currentAttendanceRules[eventCode];
    if (!r || !r.id) {
        return `<span class="badge bg-secondary-subtle text-secondary">${langData['attendance_deduction_default_badge'] || 'Default'}</span> <div class="text-muted small mt-1">${langData['attendance_deduction_method_percent_of_rate'] || 'Percent of Rate'} (1.00x)</div>`;
    }
    if (r.method_code === 'flat_amount') {
        const unitLabel = (ATTENDANCE_RATE_UNIT_LABELS[r.rate_unit] || ATTENDANCE_RATE_UNIT_LABELS.minute).flat();
        const amt = parseFloat(r.rate_per_unit || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return `<span class="badge bg-info-subtle text-info">${langData['attendance_deduction_method_flat_amount'] || 'Flat Amount'}</span> <div class="text-muted small mt-1">${unitLabel}: ${amt}</div>`;
    }
    if (r.method_code === 'tiered_bracket') {
        const n = (r.brackets || []).length;
        return `<span class="badge bg-warning-subtle text-warning">${langData['attendance_deduction_method_tiered_bracket'] || 'Tiered Brackets'}</span> <div class="text-muted small mt-1">${n} ${langData['attendance_deduction_brackets'] || 'Brackets'}</div>`;
    }
    const mult = parseFloat(r.multiplier_rate || 1).toFixed(2);
    return `<span class="badge bg-success-subtle text-success">${langData['attendance_deduction_method_percent_of_rate'] || 'Percent of Rate'}</span> <div class="text-muted small mt-1">${mult}x</div>`;
}
function renderAttendanceDeductionCards() {
    // 2026-08-29: leave_pending added alongside the original 3 (explicit request -- leave still
    // awaiting approval is provisionally deducted like unpaid leave until approved, see
    // AttendanceDeductionRuleModel's own docblock).
    const events = ['late', 'absent', 'unpaid_leave', 'leave_pending'];
    const icons = { late: 'fa-user-clock', absent: 'fa-user-slash', unpaid_leave: 'fa-calendar-xmark', leave_pending: 'fa-hourglass-half' };
    $('#attendanceDeductionCards').html(events.map(eventCode => `
        <div class="col-md-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <h6 class="fw-bold mb-3"><i class="fa-solid ${icons[eventCode]} me-2 text-brand"></i>${attendanceEventLabel(eventCode)}</h6>
                    <div class="mb-3">${attendanceDeductionCardSummary(eventCode)}</div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-auto" onclick="openAttendanceDeductionRuleModal('${eventCode}')">
                        <i class="fa-solid fa-gear me-1"></i><span data-i18n="attendance_deduction_configure">${langData['attendance_deduction_configure'] || 'Configure'}</span>
                    </button>
                </div>
            </div>
        </div>
    `).join(''));
}
function loadAttendanceDeductionCards() {
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.get-all`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            currentAttendanceRules = res.data;
            renderAttendanceDeductionCards();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
