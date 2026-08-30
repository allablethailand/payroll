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
/** 2026-08-30, explicit request: "ตรง ประเภท * น่าจะตัดออก...หรือเปลี่ยนเป็นแค่แสดงคำเฉยๆ เป็นสีเขียวกับสีแดง
 *  เป็นหัวข้อว่ากำลังตั้งค่าอะไร" -- replaces the old checked+disabled radio-group dance (item_type was
 *  ALREADY locked before the modal opened, from whichever tab's Add button was clicked -- the radio
 *  group only ever LOOKED editable) with a single hidden input + a colored badge that IS the modal's
 *  own title now. */
function applyPedTypeModalBadge(itemType) {
    $('#ped_item_type').val(itemType);
    const isEarning = itemType === 'earning';
    $('#pedTypeModalBadge')
        .removeClass('bg-success-subtle text-success bg-danger-subtle text-danger')
        .addClass(isEarning ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger')
        .text(isEarning ? (langData['earning_singular'] || 'Income') : (langData['deduction_singular'] || 'Deduction'));
}
function resetPedTypeForm(itemType) {
    $('#pedTypeForm')[0].reset();
    $('#ped_type_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#calculation_method').val('').trigger('change');
    $('#tax_treatment').val('').trigger('change');
    $('#tax_deduction_impact').val('').trigger('change');
    $('#statutory_report_code').val('').trigger('change');
    $('#ped_status').val('active').trigger('change');
    applyItemTypeFields(itemType);
    applyCalculationMethodFields('');
    applyPedTypeModalBadge(itemType);
}
function populatePedTypeForm(row) {
    $('#ped_type_id').val(row.id);
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
    applyPedTypeModalBadge(row.item_type);
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
        item_type: $('#ped_item_type').val(),
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
        initSelect2('#payroll_frequency', { mode: 'static' });
        initSelect2('#cutoff_day_of_week', { mode: 'static' });
        initSelect2('#payment_day_of_week', { mode: 'static' });
        initSelect2('#bank_file_format_id', { mode: 'ajax' });
        initSelect2('#cycle_bank_account_id', { mode: 'ajax', allowClear: true });
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
        if (tabId === 'policies-tab') {
            loadPayrollPolicies();
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});

/* ==================== PAYROLL POLICIES (2026-08-30, new tab) ==================== */
function applyPolicyPayBasisFields(payBasis) {
    $('#policyPayBasisSubOptions').toggleClass('d-none', payBasis !== 'schedule_based');
}
$(document).on('change', '#policyPayBasis', function () {
    applyPolicyPayBasisFields($(this).val());
});
function loadPayrollPolicies() {
    $.get(`${BASE_URL}/api/payroll-policy.get`, function (res) {
        if (res && res.status && res.data) {
            const d = res.data;
            $('#policyReopenWindowDays').val(d.reopen_window_days !== null && d.reopen_window_days !== undefined ? d.reopen_window_days : '');
            $('#policyProbationPeriodDays').val(d.probation_period_days !== null && d.probation_period_days !== undefined ? d.probation_period_days : '');
            $('#policyProbationBaseSalaryRatio').val(d.probation_base_salary_ratio !== null && d.probation_base_salary_ratio !== undefined ? d.probation_base_salary_ratio : '');
            $('#policyProbationDeferPvd').prop('checked', !!d.probation_defer_pvd);
            $('#policyProbationDeferRecurringEarning').prop('checked', !!d.probation_defer_recurring_earning);
            const payBasis = d.pay_basis || 'full_month';
            $('#policyPayBasis').val(payBasis).trigger('change');
            $('#policyPayBasisDeductHolidays').prop('checked', !!d.pay_basis_deduct_holidays);
            $('#policyPayBasisDeductLeave').prop('checked', !!d.pay_basis_deduct_leave);
            applyPolicyPayBasisFields(payBasis);
        }
    });
}
// ONE shared Save for the whole tab (reads every card's fields together) -- see the view's own
// comment on why a per-card save would silently reset the OTHER card's fields.
$(document).on('click', '#btnSavePayrollPolicies', function () {
    const $btn = $(this);
    const reopenDaysRaw = $('#policyReopenWindowDays').val();
    const probationDaysRaw = $('#policyProbationPeriodDays').val();
    const probationRatioRaw = $('#policyProbationBaseSalaryRatio').val();
    $btn.prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-policy.save`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            reopen_window_days: reopenDaysRaw === '' ? null : reopenDaysRaw,
            probation_period_days: probationDaysRaw === '' ? null : probationDaysRaw,
            probation_base_salary_ratio: probationRatioRaw === '' ? null : probationRatioRaw,
            probation_defer_pvd: $('#policyProbationDeferPvd').is(':checked'),
            probation_defer_recurring_earning: $('#policyProbationDeferRecurringEarning').is(':checked'),
            pay_basis: $('#policyPayBasis').val() || 'full_month',
            pay_basis_deduct_holidays: $('#policyPayBasisDeductHolidays').is(':checked'),
            pay_basis_deduct_leave: $('#policyPayBasisDeductLeave').is(':checked'),
        }),
        success: function (res) {
            $btn.prop('disabled', false);
            if (res && res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
            } else {
                showError((res && res.message) || (langData['save_failed'] || 'Save failed'));
            }
        },
        error: function () {
            $btn.prop('disabled', false);
            showError(langData['save_failed'] || 'Save failed');
        },
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
    $('#cycle_bank_account_id').val('').trigger('change');
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
    // 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" --
    // same preload-a-single-Option pattern as bank_file_format_id above (avoids the select2-remote-
    // empty-preload gotcha this app has hit before -- see CLAUDE.md). Optional, so a cycle with no
    // bank_account_id set (falls back to the company's default account) just clears the field.
    if (row.bank_account_id) {
        const bankAccountLabel = row.bank_account_name
            ? `${row.bank_account_name}${row.bank_account_company_code ? ' (' + row.bank_account_company_code + ')' : ''}`
            : `#${row.bank_account_id}`;
        const acctOpt = new Option(bankAccountLabel, row.bank_account_id, true, true);
        $('#cycle_bank_account_id').append(acctOpt).trigger('change');
    } else {
        $('#cycle_bank_account_id').val('').trigger('change');
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
        bank_account_id: $('#cycle_bank_account_id').val() || null,
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

/* ==================== ATTENDANCE DEDUCTION RULES (Late / Absent / Unpaid Leave / Leave Pending) ====================
 * 2026-08-21: moved from a button+shared-modal-with-pill-switcher on the Deductions tab to its own
 * tab (#attendance-deduction-pane) showing all events as cards, later a table.
 *
 * 2026-08-30, multi-scope rollout ("ในกรณีที่มีการคำนวณประเภทเดียวกันแต่หลายทีม ให้เพิ่มปุ่ม Clone ขึ้นมา")
 * -- currentAttendanceRules changed shape from {eventCode: row} (one row per event) to
 * {eventCode: [row, ...]} (a LIST of rule variants per event -- the company-wide default always
 * first, index 0, followed by any team/department-scoped overrides), matching
 * AttendanceDeductionRuleModel::ruleGetAll()'s own new return shape 1:1. Every function below that
 * used to look a row up by eventCode alone now takes the specific ROW OBJECT (or an explicit
 * variantId, null = the default) -- see findAttendanceVariant(). Clone is NOT a separate backend
 * endpoint -- it's a pure client-side convenience that opens the same Configure modal pre-filled
 * with an existing row's method/rate/brackets, but with id AND scope left BLANK, forcing the admin
 * to pick a new team/department before Save creates a genuinely new row (ruleSave() with no `id` in
 * the payload always inserts). currentAttendanceBrackets is still the source-of-truth array for the
 * currently-open row's bracket editor -- unchanged idiom (approval-workflow.js's step editor,
 * payslip-template.js's field list).
 *
 * rate_unit (2026-08-21, "นาทีละกี่บาท ชั่วโมงละกี่บาท") replaces the old fixed-per-event unit
 * assumption (late was always "per minute", absent/unpaid_leave always "per day") -- freely
 * choosable per rule now, so ATTENDANCE_RATE_UNIT_LABELS is keyed by rate_unit, not by event.
 */
let currentAttendanceRules = {};
let currentAttendanceEvent = 'late';
let currentAttendanceEditingId = null; // null = creating a NEW variant (blank/cloned form); a real id = editing that existing row.
let currentAttendanceBrackets = [];
const ATTENDANCE_EVENT_ICON = {
    late: { icon: 'fa-user-clock', rt: 'rt-1' },
    absent: { icon: 'fa-user-slash', rt: 'rt-4' },
    unpaid_leave: { icon: 'fa-calendar-xmark', rt: 'rt-3' },
    leave_pending: { icon: 'fa-hourglass-half', rt: 'rt-5' },
};
/** Finds one variant row out of currentAttendanceRules[eventCode] -- null id = the company-wide default (always index 0, but looked up by scope_type===null rather than assumed-position for clarity). */
function findAttendanceVariant(eventCode, id) {
    const rows = currentAttendanceRules[eventCode] || [];
    if (id === null || id === undefined) {
        return rows.find(r => r.scope_type === null) || rows[0] || null;
    }
    return rows.find(r => String(r.id) === String(id)) || null;
}
function attendanceScopeBadgeHtml(row) {
    if (!row || row.scope_type === null) {
        return `<span class="badge bg-secondary-subtle text-secondary">${langData['attendance_deduction_scope_default'] || 'Company-wide Default'}</span>`;
    }
    const scopeLabel = row.scope_type === 'team' ? (langData['team'] || 'Team') : (langData['department'] || 'Department');
    return `<span class="badge bg-info-subtle text-info">${scopeLabel}: ${escapeHtmlPc(row.scope_label || '?')}</span>`;
}

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
    $('#attendanceCalcPreviewResult').addClass('d-none'); // bracket edits invalidate any shown preview too
}
function addAttendanceBracketRow() {
    currentAttendanceBrackets.push({ min_units: null, max_units: null, deduction_amount: null });
    renderAttendanceBracketRows();
    $('#attendanceCalcPreviewResult').addClass('d-none');
}
function removeAttendanceBracketRow(index) {
    currentAttendanceBrackets.splice(index, 1);
    renderAttendanceBracketRows();
    $('#attendanceCalcPreviewResult').addClass('d-none');
}

function renderAttendanceDeductionModalFields(eventCode, row) {
    const r = row || { method_code: 'percent_of_rate', rate_unit: ATTENDANCE_DEFAULT_RATE_UNIT[eventCode], rate_per_unit: null, multiplier_rate: '1.00', method_name_th: '', method_name_en: '', brackets: [] };
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
function applyAttendanceScopeTargetFields(scopeType) {
    // 2026-08-30, real bug found and fixed (explicit report: "เลือกทีม แต่ select ของ Department ขึ้นมา
    // ด้วย") -- select2 renders its own widget as a separate sibling DOM node, so toggling .d-none on
    // the raw <select> (what this used to do) never actually hid it. Toggles the WRAPPING <div> now
    // instead -- see modals.php's own comment at #attendanceRuleScopeTeamWrap/-DepartmentWrap.
    $('#attendanceRuleScopeTargetLabel').text(scopeType === 'department' ? (langData['department'] || 'Department') : (langData['team'] || 'Team'));
    $('#attendanceRuleScopeTeamWrap').toggleClass('d-none', scopeType !== 'team');
    $('#attendanceRuleScopeDepartmentWrap').toggleClass('d-none', scopeType !== 'department');
}
$(document).on('change', '#attendanceRuleScopeType', function () {
    applyAttendanceScopeTargetFields($(this).val());
});
function attendanceScopeBadgeParts(row) {
    if (!row || row.scope_type === null || row.scope_type === undefined) {
        return { cls: 'badge bg-secondary-subtle text-secondary', text: langData['attendance_deduction_scope_default'] || 'Company-wide Default' };
    }
    const scopeLabel = row.scope_type === 'team' ? (langData['team'] || 'Team') : (langData['department'] || 'Department');
    return { cls: 'badge bg-info-subtle text-info', text: `${scopeLabel}: ${row.scope_label || '?'}` };
}
function attendanceScopeBadgeHtml(row) {
    const parts = attendanceScopeBadgeParts(row);
    return `<span class="${parts.cls}">${escapeHtmlPc(parts.text)}</span>`;
}

/**
 * 2026-08-30, multi-scope rollout. `variantId`: null = edit the company-wide default (real row if one
 * exists, else the virtual not-yet-persisted one) -- scope is fixed, shown as a read-only badge.
 * A number = edit that specific EXISTING scoped row by id -- same read-only-scope treatment, just a
 * different (team/department) badge. The string 'new' = create a brand-new scoped variant -- scope
 * picker shown, required, nothing pre-filled unless `cloneFromRow` is passed (see
 * cloneAttendanceDeductionRule() below), in which case method/rate/brackets/label are copied from it
 * as a starting point (label gets a " (Copy)" suffix) while id/scope stay blank.
 */
let currentAttendanceEditingMode = 'default'; // 'default' | 'existing_scoped' | 'new'
let currentAttendanceEditingRow = null;
function openAttendanceDeductionRuleModal(eventCode, variantId, cloneFromRow) {
    // #attendanceRateUnit/#attendanceRuleScopeType are select2-static -- already initialized once by
    // app.js's global `.select2-static` sweep on page load (2026-08-21 bug fix: re-running initSelect2
    // static mode here on every open re-synced its `data` array into real <option> elements each time
    // without clearing the previous set -- select2('destroy') tears down the widget but doesn't strip
    // options it added, so the dropdown showed every option duplicated after the modal was opened
    // once). #attendanceDeductionMethod/#attendanceRuleScopeTeamId/#attendanceRuleScopeDepartmentId
    // are select2-remote/ajax mode instead, which doesn't upfront-populate <option> elements this way,
    // so re-initializing them per-open (unchanged below) is safe.
    initSelect2('#attendanceDeductionMethod', { mode: 'ajax' });
    initSelect2('#attendanceRuleScopeTeamId', { mode: 'ajax' });
    initSelect2('#attendanceRuleScopeDepartmentId', { mode: 'ajax' });
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.get-all`, method: 'GET', dataType: 'json',
        success: function (res) {
            if (!res.status) { showWarning(res.message || langData['save_failed'] || 'An error occurred.'); return; }
            currentAttendanceRules = res.data;
            currentAttendanceEvent = eventCode;

            if (variantId === 'new') {
                currentAttendanceEditingMode = 'new';
                currentAttendanceEditingRow = null;
            } else {
                const row = findAttendanceVariant(eventCode, variantId);
                currentAttendanceEditingMode = (row && row.scope_type !== null && row.scope_type !== undefined) ? 'existing_scoped' : 'default';
                currentAttendanceEditingRow = row;
            }

            const fieldsSource = currentAttendanceEditingMode === 'new' ? (cloneFromRow || null) : currentAttendanceEditingRow;
            renderAttendanceDeductionModalFields(eventCode, fieldsSource);
            $('#attendanceRuleLabel').val(
                currentAttendanceEditingMode === 'new'
                    ? (cloneFromRow && cloneFromRow.label ? cloneFromRow.label + ' (Copy)' : '')
                    : (currentAttendanceEditingRow ? (currentAttendanceEditingRow.label || '') : '')
            );

            if (currentAttendanceEditingMode === 'new') {
                $('#attendanceRuleScopeBadgeWrapper').addClass('d-none');
                $('#attendanceRuleScopePickerWrapper').removeClass('d-none');
                $('#attendanceRuleScopeType').val('team').trigger('change');
                $('#attendanceRuleScopeTeamId').val(null).trigger('change');
                $('#attendanceRuleScopeDepartmentId').val(null).trigger('change');
                applyAttendanceScopeTargetFields('team');
            } else {
                $('#attendanceRuleScopePickerWrapper').addClass('d-none');
                $('#attendanceRuleScopeBadgeWrapper').removeClass('d-none');
                const parts = attendanceScopeBadgeParts(currentAttendanceEditingRow);
                $('#attendanceRuleScopeBadge').attr('class', parts.cls).text(parts.text);
            }

            const modeSuffix = currentAttendanceEditingMode === 'new' ? ` (${cloneFromRow ? (langData['clone'] || 'Clone') : (langData['add'] || 'Add')})` : '';
            $('#attendanceDeductionRuleModalEvent').text(attendanceEventLabel(eventCode) + modeSuffix);
            // Calculation Preview: reset to the default sample every time the modal opens for a
            // (possibly different) event, and hide any stale result from a previous open.
            $('#attendanceCalcPreviewBaseSalary').val(30000);
            $('#attendanceCalcPreviewMinutes').val(30);
            $('#attendanceCalcPreviewResult').addClass('d-none').empty();
            const minutesLabelKey = eventCode === 'late' ? 'calc_preview_sample_minutes_late' : 'calc_preview_sample_minutes_other';
            $('#attendanceCalcPreviewMinutesLabel').text(langData[minutesLabelKey] || (eventCode === 'late' ? 'Sample Minutes Late' : 'Sample Minutes'));
            new bootstrap.Modal(document.getElementById('attendanceDeductionRuleModal')).show();
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}
/* ==================== Calculation Preview (2026-08-30, Attendance Deduction Rule modal) ====================
 * "อยากให้เพิ่มปุ่มแสดงตัวอย่างการคำนวณจากการตั้งค่าที่เลือก...ก็อยากให้มี Area แสดงตัวอย่างการคำนวณครับ" --
 * reads whatever is CURRENTLY in the form (not yet saved) and posts it to
 * api/attendance-deduction-rule.preview, which runs the exact same formula real payroll uses (see
 * AttendanceDeductionRuleModel::previewCalculation()'s own docblock) against an editable sample
 * scenario. Intended as the first of several forms this same .calc-preview-box pattern gets applied
 * to (see style.css's own comment on that class). */
function attendanceCalcPreviewFormulaStepsHtml(formula) {
    if (!formula) return '';
    const fmt = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (formula.type === 'attendance_flat') {
        return `<div class="calc-preview-step">${(langData['calc_preview_step_quantity'] || 'Quantity in {unit}').replace('{unit}', attendanceRateUnitShortLabel(formula.rate_unit))}: <code>${formula.minutes} ${langData['minutes_short'] || 'min'} = ${fmt(formula.quantity_in_rate_unit)} ${attendanceRateUnitShortLabel(formula.rate_unit)}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_rate'] || 'Rate'}: <code>${fmt(formula.rate_per_unit)}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_formula'] || 'Formula'}: <code>${fmt(formula.rate_per_unit)} &times; ${fmt(formula.quantity_in_rate_unit)} = ${fmt(formula.result)}</code></div>`;
    }
    if (formula.type === 'attendance_bracket') {
        const maxLabel = formula.bracket_max === null || formula.bracket_max === undefined ? (langData['no_limit'] || 'No limit') : fmt(formula.bracket_max);
        return `<div class="calc-preview-step">${(langData['calc_preview_step_quantity'] || 'Quantity in {unit}').replace('{unit}', attendanceRateUnitShortLabel(formula.rate_unit))}: <code>${formula.minutes} ${langData['minutes_short'] || 'min'} = ${fmt(formula.quantity_in_rate_unit)} ${attendanceRateUnitShortLabel(formula.rate_unit)}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_bracket_matched'] || 'Matched bracket'}: <code>${fmt(formula.bracket_min)} - ${maxLabel}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_formula'] || 'Formula'}: <code>${fmt(formula.result)}</code></div>`;
    }
    if (formula.type === 'attendance_percent') {
        return `<div class="calc-preview-step">${langData['calc_preview_step_hourly_rate'] || 'Sample hourly rate'}: <code>${fmt(formula.hourly_rate)}</code></div>
            <div class="calc-preview-step">${langData['calc_preview_step_formula'] || 'Formula'}: <code>(${fmt(formula.hourly_rate)} &divide; 60) &times; ${formula.minutes} &times; ${formula.multiplier} = ${fmt(formula.result)}</code></div>`;
    }
    return '';
}
function attendanceRateUnitShortLabel(unit) {
    return { minute: langData['unit_noun_minute'] || 'minute(s)', hour: langData['unit_noun_hour'] || 'hour(s)', day: langData['unit_noun_day'] || 'day(s)' }[unit] || unit;
}
function collectAttendanceDeductionDraftForPreview() {
    const method = $('#attendanceDeductionMethod').val();
    const payload = { method_code: method, rate_unit: $('#attendanceRateUnit').val() || 'minute' };
    if (method === 'flat_amount') {
        payload.rate_per_unit = parseFloat($('#attendanceRatePerUnit').val()) || 0;
    } else if (method === 'percent_of_rate') {
        payload.multiplier_rate = parseFloat($('#attendanceMultiplierRate').val()) || 1.00;
    } else if (method === 'tiered_bracket') {
        payload.brackets = currentAttendanceBrackets.map(b => ({
            min_units: parseInt(b.min_units) || 0,
            max_units: (b.max_units === null || b.max_units === '') ? null : parseInt(b.max_units),
            deduction_amount: parseFloat(b.deduction_amount) || 0
        }));
    }
    return payload;
}
$(document).on('click', '#btnAttendanceCalcPreview', function () {
    const method = $('#attendanceDeductionMethod').val();
    if (!method) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = collectAttendanceDeductionDraftForPreview();
    payload.sample_base_salary = parseFloat($('#attendanceCalcPreviewBaseSalary').val()) || 30000;
    payload.sample_minutes = parseFloat($('#attendanceCalcPreviewMinutes').val());
    if (payload.sample_minutes === '' || isNaN(payload.sample_minutes)) payload.sample_minutes = 0;
    const $btn = $(this).prop('disabled', true);
    const $result = $('#attendanceCalcPreviewResult');
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.preview`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            $btn.prop('disabled', false);
            if (!res.status) {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                return;
            }
            const amount = Number(res.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            $result.removeClass('d-none').html(
                `<div class="calc-preview-amount mb-1">${langData['calc_preview_result_label'] || 'Result'}: ${amount}</div>` +
                attendanceCalcPreviewFormulaStepsHtml(res.formula)
            );
        },
        error: function () {
            $btn.prop('disabled', false);
            showWarning(langData['save_failed'] || 'An error occurred while calculating the preview.');
        }
    });
});
// Any change to the form invalidates the shown preview -- re-run explicitly via the button rather
// than silently going stale (matches this feature's own "ปุ่มแสดงตัวอย่าง" framing -- a button, not a
// fully-live area) but hide the now-outdated result so it's never mistaken for current.
$(document).on('change input', '#attendanceDeductionMethod, #attendanceRateUnit, #attendanceRatePerUnit, #attendanceMultiplierRate', function () {
    $('#attendanceCalcPreviewResult').addClass('d-none');
});

function saveAttendanceDeductionRule() {
    const method = $('#attendanceDeductionMethod').val();
    if (!method) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    // 2026-08-30, real bug found and fixed while adding is_active/exemptions: this payload used to
    // send ONLY the method/rate fields being edited here, and AttendanceDeductionRuleModel::
    // ruleSave() treats an ABSENT is_active/exemptions key as "reset to default" (active=true, no
    // exemptions), not "leave unchanged" -- saving from this Configure modal would have silently
    // wiped out whatever was set via the table's own is_active toggle or the Assign modal.
    // Preserving both from currentAttendanceEditingRow (already loaded before this modal opened) --
    // null for a brand-new variant, correctly defaulting to active/no-exemptions below.
    const payload = {
        event_code: currentAttendanceEvent, method_code: method,
        label: ($('#attendanceRuleLabel').val() || '').trim() || null,
        is_active: currentAttendanceEditingRow ? (currentAttendanceEditingRow.is_active !== false) : true,
        exemptions: currentAttendanceEditingRow ? (currentAttendanceEditingRow.exemptions || []).map(ex => ({ scope_type: ex.scope_type, scope_id: ex.scope_id })) : [],
    };
    if (currentAttendanceEditingRow && currentAttendanceEditingRow.id) {
        payload.id = currentAttendanceEditingRow.id;
    }
    // 2026-08-30, multi-scope rollout: scope is fixed once a row exists (default OR an existing
    // scoped variant) -- only a genuinely NEW variant reads it from the (otherwise hidden) picker.
    if (currentAttendanceEditingMode === 'new') {
        const scopeType = $('#attendanceRuleScopeType').val();
        const scopeId = scopeType === 'department' ? $('#attendanceRuleScopeDepartmentId').val() : $('#attendanceRuleScopeTeamId').val();
        if (!scopeType || !scopeId) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        payload.scope_type = scopeType;
        payload.scope_id = parseInt(scopeId, 10);
    } else {
        payload.scope_type = currentAttendanceEditingRow ? (currentAttendanceEditingRow.scope_type || null) : null;
        payload.scope_id = currentAttendanceEditingRow ? (currentAttendanceEditingRow.scope_id || null) : null;
    }
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

/* Table on #attendance-deduction-pane -- NOT a DataTable (same reasoning as the Permission Matrix
 * page: a small, fully-loaded-at-once grid, not a paginated record list). 2026-08-30, multi-scope
 * rollout: was exactly 4 rows (one per event); now each event can have several rows (the
 * company-wide default + any team/department-scoped variants, see findAttendanceVariant()'s own
 * docblock) -- every function below takes the specific ROW OBJECT, not an eventCode lookup. */
function attendanceDeductionMethodSummary(r) {
    if (!r || !r.id) {
        return `<span class="badge bg-secondary-subtle text-secondary">${langData['attendance_deduction_default_badge'] || 'Default'}</span> <span class="text-muted small ms-1">${langData['attendance_deduction_method_percent_of_rate'] || 'Percent of Rate'} (1.00x)</span>`;
    }
    if (r.method_code === 'flat_amount') {
        const unitLabel = (ATTENDANCE_RATE_UNIT_LABELS[r.rate_unit] || ATTENDANCE_RATE_UNIT_LABELS.minute).flat();
        const amt = parseFloat(r.rate_per_unit || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return `<span class="badge bg-info-subtle text-info">${langData['attendance_deduction_method_flat_amount'] || 'Flat Amount'}</span> <span class="text-muted small ms-1">${unitLabel}: ${amt}</span>`;
    }
    if (r.method_code === 'tiered_bracket') {
        const n = (r.brackets || []).length;
        return `<span class="badge bg-warning-subtle text-warning">${langData['attendance_deduction_method_tiered_bracket'] || 'Tiered Brackets'}</span> <span class="text-muted small ms-1">${n} ${langData['attendance_deduction_brackets'] || 'Brackets'}</span>`;
    }
    const mult = parseFloat(r.multiplier_rate || 1).toFixed(2);
    return `<span class="badge bg-success-subtle text-success">${langData['attendance_deduction_method_percent_of_rate'] || 'Percent of Rate'}</span> <span class="text-muted small ms-1">${mult}x</span>`;
}
function attendanceDeductionExemptionsSummary(r) {
    const exemptions = (r && r.exemptions) || [];
    if (!exemptions.length) {
        return `<span class="text-muted small">${langData['attendance_deduction_no_exemptions'] || 'None'}</span>`;
    }
    const labels = exemptions.slice(0, 3).map(ex => `<span class="badge bg-light text-secondary border me-1 mb-1">${escapeHtmlPc(ex.label || '?')}</span>`).join('');
    const more = exemptions.length > 3 ? `<span class="text-muted small">+${exemptions.length - 3}</span>` : '';
    return labels + more;
}
/** One compact row per rule variant, rendered INSIDE its event's card body (see
 *  attendanceDeductionEventCardHtml() below) -- not a <tr> anymore (2026-08-30 follow-up, reverted
 *  from a table back to cards: "กฎการหักตามข้อมูลเข้างาน ก็ให้เป็น Card เหมือนกัน"). */
function attendanceDeductionVariantRowHtml(eventCode, r) {
    const isActive = r.is_active !== false; // default true when no row saved yet
    const idAttr = r.id || '';
    const canDelete = !!(r.id && r.scope_type);
    return `<div class="adr-variant-row" data-event="${eventCode}" data-id="${idAttr}">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input attendance-deduction-active-toggle" type="checkbox" role="switch" data-event="${eventCode}" data-id="${idAttr}" ${isActive ? 'checked' : ''}>
        </div>
        <div class="adr-variant-main">
            ${attendanceScopeBadgeHtml(r)}
            ${r.label ? `<span class="adr-variant-label">${escapeHtmlPc(r.label)}</span>` : ''}
            ${attendanceDeductionMethodSummary(r)}
        </div>
        <div class="adr-variant-exemptions">${attendanceDeductionExemptionsSummary(r)}</div>
        <div class="adr-variant-actions">
            <div class="btn-group border rounded-3 bg-white">
                <button type="button" class="btn btn-link text-warning" onclick="openAttendanceDeductionRuleModal('${eventCode}', ${r.id ? r.id : 'null'})" title="${langData['attendance_deduction_configure'] || 'Configure'}"><i class="fa-solid fa-gear"></i></button>
                <button type="button" class="btn btn-link text-primary border-start" onclick="cloneAttendanceDeductionRule('${eventCode}', ${r.id ? r.id : 'null'})" title="${langData['clone'] || 'Clone'}"><i class="fa-solid fa-clone"></i></button>
                <button type="button" class="btn btn-link text-secondary border-start" onclick="openAttendanceDeductionAssignModal('${eventCode}', ${r.id ? r.id : 'null'})" title="${langData['attendance_deduction_assign_title'] || 'Exempt Departments / Teams / Employees'}"><i class="fa-solid fa-user-shield"></i></button>
                ${canDelete ? `<button type="button" class="btn btn-link py-1 text-danger border-start" onclick="deleteAttendanceDeductionVariant(${r.id})" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>` : ''}
            </div>
        </div>
    </div>`;
}
/** One .settings-info-card per event, header color-coded by deduction group (2026-08-30 explicit
 *  request: "แยกสีตามกลุ่มการหักครับ" -- reuses the shared .row-type-icon component/rt-N palette,
 *  "ตรง icon ปรับให้เหมือนในหน้า Report ครับ", same one reports/index.php's own report-type icon
 *  introduced). Body lists that event's rule variants as compact rows. */
function attendanceDeductionEventCardHtml(eventCode) {
    const meta = ATTENDANCE_EVENT_ICON[eventCode];
    const rows = currentAttendanceRules[eventCode] || [];
    return `<div class="settings-info-card mb-4" data-event-card="${eventCode}">
        <div class="settings-info-card-header adr-hdr-${meta.rt.replace('rt-', '')}">
            <span class="row-type-icon ${meta.rt}"><i class="fa-solid ${meta.icon}"></i></span>
            <div>
                <p class="settings-info-card-title mb-0">${attendanceEventLabel(eventCode)}</p>
            </div>
        </div>
        <div class="settings-info-card-body">
            ${rows.map(row => attendanceDeductionVariantRowHtml(eventCode, row)).join('')}
        </div>
    </div>`;
}
function renderAttendanceDeductionCards() {
    // 2026-08-29: leave_pending added alongside the original 3 (explicit request -- leave still
    // awaiting approval is provisionally deducted like unpaid leave until approved, see
    // AttendanceDeductionRuleModel's own docblock).
    const events = ['late', 'absent', 'unpaid_leave', 'leave_pending'];
    $('#attendanceDeductionCardsContainer').html(events.map(attendanceDeductionEventCardHtml).join(''));
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
/** Opens the Configure modal pre-filled from an existing row's config, blank id + blank scope, so
 *  Save creates a genuinely NEW variant for a different team/department -- this IS the "Clone"
 *  feature (2026-08-30, explicit request: "ให้เพิ่มปุ่ม Clone ขึ้นมา"), not a separate backend
 *  endpoint (ruleSave() with no id already inserts). sourceId=null clones the company-wide default. */
function cloneAttendanceDeductionRule(eventCode, sourceId) {
    const sourceRow = findAttendanceVariant(eventCode, sourceId);
    openAttendanceDeductionRuleModal(eventCode, 'new', sourceRow);
}
function deleteAttendanceDeductionVariant(id) {
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/attendance-deduction-rule.delete`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ id: id }), dataType: 'json',
            success: function (res) {
                if (res.status) {
                    showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.');
                    loadAttendanceDeductionCards();
                } else {
                    showWarning(res.message || langData['delete_failed'] || 'Failed to delete data.');
                }
            },
            error: function () { showWarning(langData['delete_failed'] || 'An error occurred while deleting the data.'); }
        });
    });
}
function escapeHtmlPc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
}

/* is_active quick toggle -- preserves the rule's existing method/rate/scope/exemptions, only flips
   the one flag, so it can be saved right from the table without opening a modal. */
$(document).on('change', '.attendance-deduction-active-toggle', function () {
    const eventCode = $(this).data('event');
    const id = $(this).data('id') || null;
    const isActive = $(this).is(':checked');
    const r = findAttendanceVariant(eventCode, id) || {};
    const payload = {
        event_code: eventCode, is_active: isActive,
        method_code: r.method_code || 'percent_of_rate', rate_unit: r.rate_unit,
        rate_per_unit: r.rate_per_unit, multiplier_rate: r.multiplier_rate,
        scope_type: r.scope_type || null, scope_id: r.scope_id || null, label: r.label || null,
        brackets: (r.brackets || []).map(b => ({ min_units: b.min_units, max_units: b.max_units, deduction_amount: b.deduction_amount })),
        exemptions: (r.exemptions || []).map(ex => ({ scope_type: ex.scope_type, scope_id: ex.scope_id })),
    };
    if (r.id) { payload.id = r.id; }
    const $toggle = $(this);
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                loadAttendanceDeductionCards();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
                $toggle.prop('checked', !isActive); // revert the switch on failure
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving.');
            $toggle.prop('checked', !isActive);
        }
    });
});

/* ==================== Attendance Deduction: Assign (exemptions) modal ==================== */
let attendanceDeductionAssignEvent = null;
let attendanceDeductionAssignId = null; // 2026-08-30, multi-scope rollout -- which specific row's exemption list this modal is editing (null = the company-wide default).
let attendanceDeductionAssignableOptions = null;
function adaScopeItemHtml(scopeType, item, checked) {
    return `<div class="form-check ada-assign-item">
        <input class="form-check-input ada-assign-checkbox" type="checkbox" data-scope="${scopeType}" value="${item.id}" id="ada_${scopeType}_${item.id}" ${checked ? 'checked' : ''}>
        <label class="form-check-label small" for="ada_${scopeType}_${item.id}">${escapeHtmlPc(item.label)}</label>
    </div>`;
}
function renderAttendanceDeductionAssignLists(checkedByScope) {
    const opts = attendanceDeductionAssignableOptions || { departments: [], teams: [], employees: [] };
    $('#adaScopeListDepartment').html(opts.departments.map(d => adaScopeItemHtml('department', d, (checkedByScope.department || []).includes(String(d.id)))).join('') || `<span class="text-muted small">${langData['no_data_found'] || 'No data found'}</span>`);
    $('#adaScopeListTeam').html(opts.teams.map(t => adaScopeItemHtml('team', t, (checkedByScope.team || []).includes(String(t.id)))).join('') || `<span class="text-muted small">${langData['no_data_found'] || 'No data found'}</span>`);
    $('#adaScopeListEmployee').html(opts.employees.map(e => adaScopeItemHtml('employee', e, (checkedByScope.employee || []).includes(String(e.id)))).join('') || `<span class="text-muted small">${langData['no_data_found'] || 'No data found'}</span>`);
}
function openAttendanceDeductionAssignModal(eventCode, id) {
    attendanceDeductionAssignEvent = eventCode;
    attendanceDeductionAssignId = id || null;
    const r = findAttendanceVariant(eventCode, attendanceDeductionAssignId) || {};
    const scopeParts = attendanceScopeBadgeParts(r);
    $('#attendanceDeductionAssignEvent').text(`${attendanceEventLabel(eventCode)} (${scopeParts.text})`);
    const checkedByScope = { department: [], team: [], employee: [] };
    (r.exemptions || []).forEach(ex => { if (checkedByScope[ex.scope_type]) { checkedByScope[ex.scope_type].push(String(ex.scope_id)); } });

    const openModal = function () {
        renderAttendanceDeductionAssignLists(checkedByScope);
        $('.ada-select-all').prop('checked', false);
        $('.ada-scope-search').val('');
        new bootstrap.Modal(document.getElementById('attendanceDeductionAssignModal')).show();
    };
    if (attendanceDeductionAssignableOptions) {
        openModal();
        return;
    }
    $.get(`${BASE_URL}/api/attendance-deduction-rule.assignable-options`, function (res) {
        if (res && res.status) {
            attendanceDeductionAssignableOptions = res.data;
            openModal();
        } else {
            showWarning((res && res.message) || langData['save_failed'] || 'An error occurred.');
        }
    });
}
$(document).on('input', '.ada-scope-search', function () {
    const scope = $(this).data('scope');
    const term = $(this).val().toLowerCase();
    $(`#adaScopeList${scope.charAt(0).toUpperCase()}${scope.slice(1)} .ada-assign-item`).each(function () {
        $(this).toggle($(this).text().toLowerCase().includes(term));
    });
});
$(document).on('change', '.ada-select-all', function () {
    const scope = $(this).data('scope');
    const checked = $(this).is(':checked');
    $(`#adaScopeList${scope.charAt(0).toUpperCase()}${scope.slice(1)} .ada-assign-checkbox:visible`).prop('checked', checked);
});
$(document).on('click', '#btnSaveAttendanceDeductionAssign', function () {
    const exemptions = [];
    $('.ada-assign-checkbox:checked').each(function () {
        exemptions.push({ scope_type: $(this).data('scope'), scope_id: parseInt($(this).val(), 10) });
    });
    const r = findAttendanceVariant(attendanceDeductionAssignEvent, attendanceDeductionAssignId) || {};
    const payload = {
        event_code: attendanceDeductionAssignEvent, is_active: r.is_active !== false,
        method_code: r.method_code || 'percent_of_rate', rate_unit: r.rate_unit,
        rate_per_unit: r.rate_per_unit, multiplier_rate: r.multiplier_rate,
        scope_type: r.scope_type || null, scope_id: r.scope_id || null, label: r.label || null,
        brackets: (r.brackets || []).map(b => ({ min_units: b.min_units, max_units: b.max_units, deduction_amount: b.deduction_amount })),
        exemptions: exemptions,
    };
    if (r.id) { payload.id = r.id; }
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('attendanceDeductionAssignModal'))?.hide();
                loadAttendanceDeductionCards();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});
