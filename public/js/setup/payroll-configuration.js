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
// 2026-08-30 (Phase 2, T014, explicit request: "ย้าย 'สถานะ' ออกจาก modal ไปไว้ที่แถวในตาราง") -- was a
// plain read-only badge; the Add/Edit modal no longer has a Status field at all (see the view/JS
// changes this same round), so this row-level switch is now the ONLY way to change it.
// 2026-09-02, Platform Hardening Phase 1.1 -- upgraded to the shared renderStatusToggleHtml()/
// .status-toggle-switch handler in app.js (confirm-before-deactivate + success toast + revert-on-
// failure, none of which this hand-rolled version had) -- see that function's own docblock. The old
// per-row `onclick="toggleItemStatus(id)"` handler is gone; app.js's shared delegated handler covers
// it, this file only needs to know how to reload afterward.
function statusBadge(row) {
    return renderStatusToggleHtml(row.id, row.status === 'active', '/api/ped-type.toggle-status');
}
// Both tables share the same underlying catalog -- only one of them actually has any one row, but
// reloading whichever is currently initialized is cheap and avoids needing to know which table
// (earning/deduction) a given toggle belongs to. Delegated + scoped to these 2 table ids so it
// survives every ajax.reload() (only rows redraw, not the table element itself).
$(document).on('statusToggle:success', '#tb_earning_type, #tb_deduction_type', function () {
    if (tb_earning_type) tb_earning_type.ajax.reload(null, false);
    if (tb_deduction_type) tb_deduction_type.ajax.reload(null, false);
});
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group.
function actionButtons(row) {
    return `<div class="d-flex gap-1 justify-content-center">
        <button type="button" class="btn btn-link btn-circle-action text-warning btn-edit-ped-type" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link btn-circle-action text-danger btn-delete-ped-type" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
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
        // 2026-09-02, Platform Hardening Phase 1.1 follow-up (explicit request: finish repositioning
        // the status switch to column 0 on every remaining table) -- default sort points to column 1
        // (item_code) now that column 0 is the non-orderable status switch. This table's own backend
        // sortColumns map (PayrollEarningDeductionTypeModel::list()) was ALREADY drifted from the
        // real visible column layout before this change (a separate, pre-existing, documented bug --
        // see project memory), so colIndex=1 there resolves to `item_name_th`, not `item_code` --
        // the default sort on first page load is now by name instead of code. Deliberately not
        // fixing that separate sortColumns map here (same "don't take on that bug as a prerequisite"
        // decision already applied to Branch/Role/Department/Position/Rank/Team above).
        order: [[1, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/ped-type.list`,
            type: 'POST',
            data: function (d, settings) {
                d.item_type = 'earning';
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
        },
        columns: [
            { data: null, orderable: false, render: (d, t, row) => statusBadge(row) },
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
            // rollout, server mode. Excludes the interactive status SWITCH (0), the composite
            // item-name+tags cell (2), the boolean calc_sso/calc_pf icons (5, 6), and actions (7).
            // 2026-09-02, real bug found and fixed: indices shifted +1 (status switch moved to
            // column 0, see the columns array's own comment above) -- same "indices never shifted
            // when the status switch was inserted at column 0" bug as setup-rules.js's 5 tables.
            initExcelColumnFilters(self, {
                mode: 'server',
                columns: [
                    { index: 1, key: 'item_code' },
                    { index: 3, key: 'calculation_method' },
                    { index: 4, key: 'tax_treatment' },
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
        // 2026-09-02, Platform Hardening Phase 1.1 follow-up -- same repositioning + same accepted
        // default-sort side effect as initEarningTypeTable()'s own identical change just above.
        order: [[1, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/ped-type.list`,
            type: 'POST',
            data: function (d, settings) {
                d.item_type = 'deduction';
                d.column_filters = getColumnFilterValues(new $.fn.dataTable.Api(settings));
            }
        },
        columns: [
            { data: null, orderable: false, render: (d, t, row) => statusBadge(row) },
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
            // rollout, server mode. Excludes the interactive status SWITCH (0), the composite
            // item-name+tags cell (2), and actions (5).
            // 2026-09-02, real bug found and fixed: indices shifted +1, same reasoning as
            // initEarningTypeTable()'s own identical fix just above.
            initExcelColumnFilters(self, {
                mode: 'server',
                columns: [
                    { index: 1, key: 'item_code' },
                    { index: 3, key: 'calculation_method' },
                    { index: 4, key: 'tax_deduction_impact' },
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
// 2026-09-03, Manual Entry / Employee Salary tab review Phase 1B -- same conditional-field-visibility
// pattern as applyAmountSourceFields() below, just for the 2 default_interest_type-dependent groups.
function applyDefaultInterestTypeFields(type) {
    $('#default_interest_rate_wrapper').toggleClass('d-none', type !== 'fixed' && type !== 'reducing_balance');
    $('#default_fee_wrapper').toggleClass('d-none', type !== 'fee');
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
// 2026-08-30 (Phase 2, T013b, full redesign confirmed with user) -- `amount_source` is a UI-only
// concept ('event_linked'/'fixed_amount'/'percent_of_base_salary'/'manual_entry') that replaces the
// old bare `calculation_method` dropdown as the form's primary choice. See modals.php's own comment
// on `#amount_source` for the full "why" (calculation_method/fixed_amount/percent_rate never drove
// automatic calculation for ANY item -- confirmed by an audit earlier this same day -- they only
// ever matter as a suggested starting value pre-filled when HR assigns this item to an employee,
// and even that suggestion is meaningless once an item is Origami-linked).
function applyAmountSourceFields(amountSource) {
    const isEventLinked = amountSource === 'event_linked';
    const isFixed = amountSource === 'fixed_amount';
    const isPercent = amountSource === 'percent_of_base_salary';
    $('#source_event_code_wrapper').toggleClass('d-none', !isEventLinked);
    $('#source_event_code').toggleClass('required', isEventLinked);
    $('#fixed_amount_wrapper').toggleClass('d-none', !isFixed);
    $('#fixed_amount').toggleClass('required', isFixed);
    $('#percent_rate_wrapper').toggleClass('d-none', !isPercent);
    $('#percent_rate').toggleClass('required', isPercent);
    if (!isEventLinked) {
        // Switching AWAY from event_linked must clear whatever event was selected -- otherwise a
        // stale source_event_code could survive into collectPedTypeFormData() if something read the
        // select's own value directly instead of going through amount_source (defense in depth; the
        // actual submit path below always derives it from amount_source, but a stale UI selection
        // left visible-if-re-shown would be confusing regardless).
        $('#source_event_code').val('').trigger('change');
    }
}
// Kept for the 3 real DB calculation_method values (fixed_amount/percent_of_base_salary/
// manual_entry) -- amount_source='event_linked' has no calculation_method equivalent of its own
// (see applyAmountSourceFields() above), so this is only ever called with one of those 3.
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
// 2026-08-30 (T017b, leftover from T008's audit): re-applies the badge text in the NEW language
// while #itemModal is still open -- applyPedTypeModalBadge() itself is only ever called when the
// modal first opens (resetPedTypeForm()/populatePedTypeForm()), so without this hook the badge/
// title stays in whatever language it was when the modal opened, same bug class as the T007 fixes
// (refreshEedModalTitleLanguage()/orgSyncRefreshModalTitleLanguage()). Called from
// changeLanguage() in app.js on every language switch; a no-op whenever #itemModal isn't open
// (the hidden #ped_item_type still holds the last-set value, but re-painting a closed modal's
// badge is harmless either way).
function refreshPedTypeModalBadgeLanguage() {
    const itemType = $('#ped_item_type').val();
    if (itemType === 'earning' || itemType === 'deduction') {
        applyPedTypeModalBadge(itemType);
    }
}
function resetPedTypeForm(itemType) {
    $('#pedTypeForm')[0].reset();
    $('#ped_type_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#amount_source').val('').trigger('change');
    $('#calculation_method').val('');
    setPedSegmentedValue('pedTaxTreatmentToggle', '');
    setPedSegmentedValue('pedTaxDeductionImpactToggle', '');
    setPedSegmentedValue('pedStatutoryReportToggle', '');
    $('#default_interest_type').val('').trigger('change');
    $('#default_interest_rate').val('');
    $('#default_fee_percent').val('');
    setPedSegmentedValue('pedDefaultFeeBaseToggle', '');
    applyDefaultInterestTypeFields('');
    applyItemTypeFields(itemType);
    applyAmountSourceFields('');
    applyPedTypeModalBadge(itemType);
}
function populatePedTypeForm(row) {
    $('#ped_type_id').val(row.id);
    $('#item_code').val(row.item_code);
    $('#item_name_en').val(row.item_name_en);
    $('#item_name_th').val(row.item_name_th);
    $('#fixed_amount').val(row.fixed_amount || '');
    $('#percent_rate').val(row.percent_rate || '');
    setPedSegmentedValue('pedTaxTreatmentToggle', row.tax_treatment || '');
    setPedSegmentedValue('pedTaxDeductionImpactToggle', row.tax_deduction_impact || '');
    setPedSegmentedValue('pedStatutoryReportToggle', row.statutory_report_code || '');
    $('#default_interest_type').val(row.default_interest_type || '').trigger('change');
    $('#default_interest_rate').val(row.default_interest_rate || '');
    $('#default_fee_percent').val(row.default_fee_percent || '');
    setPedSegmentedValue('pedDefaultFeeBaseToggle', row.default_fee_base || '');
    applyDefaultInterestTypeFields(row.default_interest_type || '');
    $('#calc_sso').prop('checked', Number(row.calc_sso) === 1);
    $('#calc_pf').prop('checked', Number(row.calc_pf) === 1);
    applyItemTypeFields(row.item_type, true);
    // 2026-08-30 (T013b): amount_source is DERIVED from the existing row -- a linked event always
    // wins the derivation regardless of whatever calculation_method happens to be stored underneath
    // it (that column is just an inert placeholder, always 'manual_entry', for every event-linked
    // row -- see seedDefaults()'s own event-linked defaults, they're all 'manual_entry').
    const amountSource = row.source_event_code ? 'event_linked' : (row.calculation_method || 'manual_entry');
    if (row.source_event_code) {
        const label = (currentLang === 'th' ? row.source_event_name_th : row.source_event_name_en) || row.source_event_code;
        const opt = new Option(label, row.source_event_code, true, true);
        $('#source_event_code').empty().append(opt).trigger('change');
    } else {
        $('#source_event_code').val('').trigger('change');
    }
    $('#amount_source').val(amountSource).trigger('change');
    $('#calculation_method').val(row.calculation_method || 'manual_entry');
    applyAmountSourceFields(amountSource);
    applyPedTypeModalBadge(row.item_type);
}
// 2026-09-10, Batch 3A item 6: generic segmented-toggle helper for the 4 dropdowns converted below
// (tax_treatment/tax_deduction_impact/statutory_report_code/default_fee_base) -- one function
// instead of 4 near-copies (CLAUDE.md: "generalize instead of mirror-copy"). Same
// .btn-group.btn-group-sm/.btn-outline-brand pattern the existing Interest/Fee toggle
// (employee/detail.js's #eedInterestToggle) already established -- not a new one.
function setPedSegmentedValue(toggleId, value) {
    const $toggle = $(`#${toggleId}`);
    $toggle.find('button').removeClass('active').filter(`[data-value="${value || ''}"]`).addClass('active');
    $toggle.removeClass('border border-danger rounded-2 p-1');
    $(`#${$toggle.data('ped-segmented')}`).val(value || '');
}
$(document).on('click', '#itemModal [data-ped-segmented] button', function () {
    setPedSegmentedValue($(this).closest('[data-ped-segmented]').attr('id'), $(this).data('value'));
});
function validatePedTypeForm() {
    let firstInvalid = null;
    $('#itemModal .required').each(function () {
        const $el = $(this);
        if ($el.closest('.d-none').length > 0) return;
        const value = ($el.val() || '').toString().trim();
        // A required field that's now a hidden input backing a segmented toggle (see
        // setPedSegmentedValue() above) can't show Bootstrap's own .is-invalid border (invisible
        // element) -- flag the visible toggle container instead so the user still sees which field
        // needs a choice.
        const $toggle = $(`[data-ped-segmented="${$el.attr('id')}"]`);
        if (!value) {
            $el.addClass('is-invalid');
            $toggle.addClass('border border-danger rounded-2 p-1');
            if (!firstInvalid) firstInvalid = $toggle.length ? $toggle : $el;
        } else {
            $el.removeClass('is-invalid');
            $toggle.removeClass('border border-danger rounded-2 p-1');
        }
    });
    return firstInvalid;
}
function collectPedTypeFormData() {
    // 2026-08-30 (T013b/T014): amount_source is the single source of truth submitted by the user --
    // calculation_method/source_event_code are DERIVED from it here, never read from their own
    // controls directly (the hidden #calculation_method input is kept in sync as a convenience/
    // debugging aid, not relied on). `status` is no longer part of this form at all (T014) -- the
    // backend's own save() now preserves whatever status an existing row already had when the key is
    // simply absent from the payload (see PayrollEarningDeductionTypeModel::save()'s own comment on
    // this), and still defaults a brand-new row to 'active' same as before.
    const amountSource = $('#amount_source').val();
    const isEventLinked = amountSource === 'event_linked';
    return {
        id: $('#ped_type_id').val() || undefined,
        item_type: $('#ped_item_type').val(),
        item_code: $('#item_code').val().trim(),
        item_name_en: $('#item_name_en').val().trim(),
        item_name_th: $('#item_name_th').val().trim(),
        calculation_method: isEventLinked ? 'manual_entry' : (amountSource || 'manual_entry'),
        fixed_amount: amountSource === 'fixed_amount' ? $('#fixed_amount').val() : '',
        percent_rate: amountSource === 'percent_of_base_salary' ? $('#percent_rate').val() : '',
        tax_treatment: $('#tax_treatment').val(),
        tax_deduction_impact: $('#tax_deduction_impact').val(),
        statutory_report_code: $('#statutory_report_code').val(),
        default_interest_type: $('#default_interest_type').val(),
        default_interest_rate: $('#default_interest_rate').val(),
        default_fee_percent: $('#default_fee_percent').val(),
        default_fee_base: $('#default_fee_base').val(),
        calc_sso: $('#calc_sso').is(':checked'),
        calc_pf: $('#calc_pf').is(':checked'),
        source_event_code: isEventLinked ? $('#source_event_code').val() : '',
    };
}
$(document).ready(function () {
    initEarningTypeTable();
    initPayrollCycleTable();
    initPayrollCycleUI();
    if (typeof initSelect2 === 'function') {
        // 2026-08-30 (T013b): #calculation_method is now a plain hidden input (no widget needed at
        // all -- see collectPedTypeFormData()'s own comment); #amount_source is the new user-facing
        // selector that replaces it. #ped_status is gone entirely (T014, moved to the table row).
        initSelect2('#amount_source', { mode: 'static' });
        // tax_treatment/tax_deduction_impact/statutory_report_code/default_fee_base converted to
        // segmented buttons (Batch 3A item 6) -- no longer <select> elements, nothing to init here.
        // 2026-09-03, Manual Entry / Employee Salary tab review Phase 1B.
        initSelect2('#default_interest_type', { mode: 'static', allowClear: true });
        initSelect2('#source_event_code', { mode: 'ajax', allowClear: true });
        initSelect2('#payroll_frequency', { mode: 'static' });
        initSelect2('#cutoff_day_of_week', { mode: 'static' });
        initSelect2('#payment_day_of_week', { mode: 'static' });
        initSelect2('#bank_file_format_id', { mode: 'ajax' });
        // 2026-09-02, multi-bank-account payroll -- the old single #cycle_bank_account_id select is
        // gone, replaced by the checkbox list (rendered/collected directly, no select2 widget --
        // see renderCycleBankAccountsList()/collectCycleBankAccounts()). Its default payment method
        // picker is new.
        initSelect2('#cycle_default_payment_method_id', { mode: 'ajax', allowClear: true });
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
            loadProbationSets();
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});

/* ==================== PAYROLL POLICIES (2026-08-30, new tab) ==================== */
function applyPolicyPayBasisFields(payBasis) {
    $('#policyPayBasisSubOptions').toggleClass('d-none', payBasis !== 'schedule_based');
}
// 2026-08-30 (Phase 2, T016) -- was a single <select id="policyPayBasis">, now 3 radio inputs
// sharing name="policyPayBasisRadio" (see payroll-configuration.php's own comment on this block).
$(document).on('change', 'input[name="policyPayBasisRadio"]', function () {
    applyPolicyPayBasisFields($(this).val());
});
// 2026-08-31, direct mirror of applyPolicyPayBasisFields()/its own change handler immediately above.
function applyPolicyInternPayBasisFields(payBasis) {
    $('#policyInternPayBasisSubOptions').toggleClass('d-none', payBasis !== 'schedule_based');
}
$(document).on('change', 'input[name="policyInternPayBasisRadio"]', function () {
    applyPolicyInternPayBasisFields($(this).val());
});
// 2026-09-02, Platform Hardening Phase 1.2 -- dirty-check baseline for the new Cancel button.
let payrollPoliciesBaselineSnapshot = null;
function refreshPayrollPoliciesBaseline() {
    if (typeof snapshotFormState === 'function') {
        payrollPoliciesBaselineSnapshot = snapshotFormState($('#policies-pane'));
    }
}
function loadPayrollPolicies() {
    $.get(`${BASE_URL}/api/payroll-policy.get`, function (res) {
        if (res && res.status && res.data) {
            const d = res.data;
            $('#policyReopenWindowDays').val(d.reopen_window_days !== null && d.reopen_window_days !== undefined ? d.reopen_window_days : '');
            // 2026-09-04, Backlog Phase 10, T056: probation_* fields moved off this singleton form
            // entirely, into probation_policy_sets (multiple named/cloneable/assignable Sets) -- see
            // loadProbationSets()/the view's own header comment on #probationSetsCard. Intern_* fields
            // immediately below are UNRELATED, own separate field set, untouched by this task.
            $('#policyInternBaseSalaryRatio').val(d.intern_base_salary_ratio !== null && d.intern_base_salary_ratio !== undefined ? d.intern_base_salary_ratio : '');
            $('#policyInternDeferPvd').prop('checked', !!d.intern_defer_pvd);
            $('#policyInternDeferRecurringEarning').prop('checked', !!d.intern_defer_recurring_earning);
            $('#policyInternPeriodDays').val(d.intern_period_days !== null && d.intern_period_days !== undefined ? d.intern_period_days : '');
            // 2026-09-02, explicit request: leave/OT rights during probation/internship -- the
            // OT-eligible-default selects are TRI-STATE (null/0/1), populated via select2's own
            // '' /1/0 values (data-option-values on the view's own markup) so 'not set' round-trips
            // as a genuinely empty selection, not a false-y 0.
            $('#policyInternLeaveDaysLimit').val(d.intern_leave_days_limit !== null && d.intern_leave_days_limit !== undefined ? d.intern_leave_days_limit : '');
            $('#policyAllowLeaveDuringIntern').prop('checked', d.allow_leave_during_intern !== false);
            $('#policyInternOtEligibleDefault').val(d.intern_ot_eligible_default === null || d.intern_ot_eligible_default === undefined ? '' : (d.intern_ot_eligible_default ? '1' : '0')).trigger('change');
            // 2026-09-02, follow-up to close a review-flagged gap: SSO deferral (same mechanism as
            // defer_pvd) + a tri-state tax-exempt default (same round-trip pattern as
            // *_ot_eligible_default immediately above). Probation's own equivalents moved to
            // probation_policy_sets (T056) -- intern_* is unaffected/untouched.
            $('#policyInternDeferSso').prop('checked', !!d.intern_defer_sso);
            $('#policyInternTaxExemptDefault').val(d.intern_tax_exempt_default === null || d.intern_tax_exempt_default === undefined ? '' : (d.intern_tax_exempt_default ? '1' : '0')).trigger('change');
            const payBasis = d.pay_basis || 'full_month';
            $('input[name="policyPayBasisRadio"]').prop('checked', false);
            $('input[name="policyPayBasisRadio"][value="' + payBasis + '"]').prop('checked', true);
            $('#policyPayBasisDeductHolidays').prop('checked', !!d.pay_basis_deduct_holidays);
            $('#policyPayBasisDeductLeave').prop('checked', !!d.pay_basis_deduct_leave);
            applyPolicyPayBasisFields(payBasis);
            // 2026-08-31, direct mirror of the pay_basis block immediately above.
            const internPayBasis = d.intern_pay_basis || 'full_month';
            $('input[name="policyInternPayBasisRadio"]').prop('checked', false);
            $('input[name="policyInternPayBasisRadio"][value="' + internPayBasis + '"]').prop('checked', true);
            $('#policyInternPayBasisDeductHolidays').prop('checked', !!d.intern_pay_basis_deduct_holidays);
            $('#policyInternPayBasisDeductLeave').prop('checked', !!d.intern_pay_basis_deduct_leave);
            applyPolicyInternPayBasisFields(internPayBasis);
            // 2026-08-31, same-day follow-up (Origami `attribution` plan's item 3).
            $('#policySupplementalFlatTaxRate').val(d.supplemental_flat_tax_rate_percent !== null && d.supplemental_flat_tax_rate_percent !== undefined ? d.supplemental_flat_tax_rate_percent : '');
        }
        refreshPayrollPoliciesBaseline();
    });
}
$(document).on('click', '#btnCancelPayrollPolicies', function () {
    confirmIfDirtyThen($('#policies-pane'), payrollPoliciesBaselineSnapshot, loadPayrollPolicies);
});
// ONE shared Save for the whole tab (reads every card's fields together) -- see the view's own
// comment on why a per-card save would silently reset the OTHER card's fields.
$(document).on('click', '#btnSavePayrollPolicies', function () {
    const $btn = $(this);
    const reopenDaysRaw = $('#policyReopenWindowDays').val();
    const internRatioRaw = $('#policyInternBaseSalaryRatio').val();
    const flatTaxRateRaw = $('#policySupplementalFlatTaxRate').val();
    const internPeriodDaysRaw = $('#policyInternPeriodDays').val();
    const internLeaveDaysLimitRaw = $('#policyInternLeaveDaysLimit').val();
    const internOtDefaultRaw = $('#policyInternOtEligibleDefault').val();
    const internTaxExemptDefaultRaw = $('#policyInternTaxExemptDefault').val();
    if (typeof setButtonLoading === 'function') setButtonLoading($btn, true);
    else $btn.prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/payroll-policy.save`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            reopen_window_days: reopenDaysRaw === '' ? null : reopenDaysRaw,
            // 2026-09-04, Backlog Phase 10, T056: probation_* keys removed from this payload entirely
            // -- probation policy is saved per-Set now, via #btnSaveProbationSet -> api/probation-
            // policy-set.save, never through this shared singleton endpoint any more.
            intern_base_salary_ratio: internRatioRaw === '' ? null : internRatioRaw,
            intern_defer_pvd: $('#policyInternDeferPvd').is(':checked'),
            intern_defer_recurring_earning: $('#policyInternDeferRecurringEarning').is(':checked'),
            pay_basis: $('input[name="policyPayBasisRadio"]:checked').val() || 'full_month',
            pay_basis_deduct_holidays: $('#policyPayBasisDeductHolidays').is(':checked'),
            pay_basis_deduct_leave: $('#policyPayBasisDeductLeave').is(':checked'),
            // 2026-08-31, direct mirror of the pay_basis fields immediately above.
            intern_pay_basis: $('input[name="policyInternPayBasisRadio"]:checked').val() || 'full_month',
            intern_pay_basis_deduct_holidays: $('#policyInternPayBasisDeductHolidays').is(':checked'),
            intern_pay_basis_deduct_leave: $('#policyInternPayBasisDeductLeave').is(':checked'),
            // 2026-08-31, direct mirror of the fields above (Origami `attribution` plan's item 3).
            supplemental_flat_tax_rate_percent: flatTaxRateRaw === '' ? null : flatTaxRateRaw,
            // 2026-09-02, explicit request: leave/OT rights during probation/internship. Probation's
            // own equivalents moved to probation_policy_sets (T056) -- intern_* untouched here.
            intern_period_days: internPeriodDaysRaw === '' ? null : internPeriodDaysRaw,
            intern_leave_days_limit: internLeaveDaysLimitRaw === '' ? null : internLeaveDaysLimitRaw,
            allow_leave_during_intern: $('#policyAllowLeaveDuringIntern').is(':checked'),
            intern_ot_eligible_default: internOtDefaultRaw === '' ? null : internOtDefaultRaw,
            // 2026-09-02, follow-up to close a review-flagged gap.
            intern_defer_sso: $('#policyInternDeferSso').is(':checked'),
            intern_tax_exempt_default: internTaxExemptDefaultRaw === '' ? null : internTaxExemptDefaultRaw,
        }),
        success: function (res) {
            if (typeof setButtonLoading === 'function') setButtonLoading($btn, false);
            else $btn.prop('disabled', false);
            if (res && res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                refreshPayrollPoliciesBaseline();
            } else {
                showError((res && res.message) || (langData['save_failed'] || 'Save failed'));
            }
        },
        error: function () {
            if (typeof setButtonLoading === 'function') setButtonLoading($btn, false);
            else $btn.prop('disabled', false);
            showError(langData['save_failed'] || 'Save failed');
        },
    });
});

/* ==================== Probation Policy Sets (2026-09-04, Backlog Phase 10, T056) ====================
 * "Probation setting gains Clone + Assign, using T055's template" -- probation_* policy is no longer
 * a single company-wide singleton, it's now multiple named/cloneable Sets, exactly one mandatory
 * Default, each optionally scoped via T055's assign-widget.js (department/position/team/employee).
 * Mirrors OtRateSetModel's own Set-card UI pattern (the closest existing precedent in this app). */
let currentProbationSets = [];
function findProbationSet(id) {
    return currentProbationSets.find(s => s.id === id) || null;
}
function probationSetSummaryHtml(s) {
    const parts = [];
    if (s.probation_base_salary_ratio !== null && s.probation_base_salary_ratio !== undefined) {
        parts.push(`${langData['policy_probation_base_salary_ratio_label'] || 'Base Salary Ratio'}: ${parseFloat(s.probation_base_salary_ratio).toFixed(2)}%`);
    }
    if (s.probation_defer_pvd) parts.push(langData['probation_summary_defer_pvd'] || 'Defer PVD');
    if (s.probation_defer_sso) parts.push(langData['probation_summary_defer_sso'] || 'Defer SSO');
    if (s.probation_defer_recurring_earning) parts.push(langData['probation_summary_defer_recurring'] || 'Defer Recurring Allowances');
    return parts.length ? parts.map(p => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(p)}</span>`).join('') : `<span class="text-muted small">${langData['policy_probation_base_salary_ratio_placeholder'] || '100 (no reduction)'}</span>`;
}
function probationSetCardHtml(s) {
    const defaultBadge = s.is_default ? `<span class="badge bg-primary-subtle text-primary border me-2">${langData['default'] || 'Default'}</span>` : '';
    const assignBtn = s.is_default ? '' : `<button type="button" class="btn btn-link btn-circle-action text-secondary" onclick="openProbationSetAssignModal(${s.id})" title="${langData['assign'] || 'Assign'}"><i class="fa-solid fa-user-shield"></i></button>`;
    const setDefaultBtn = s.is_default ? '' : `<button type="button" class="btn btn-link btn-circle-action text-primary" onclick="setDefaultProbationSet(${s.id})" title="${langData['probation_set_as_default'] || 'Set as Default'}"><i class="fa-solid fa-star"></i></button>`;
    const deleteBtn = s.is_default ? '' : `<button type="button" class="btn btn-link btn-circle-action text-danger" onclick="deleteProbationSet(${s.id})" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>`;
    return `<div class="adr-variant-row mb-2" data-set-id="${s.id}">
        <div class="adr-variant-main">
            ${defaultBadge}
            <span class="adr-variant-label fw-bold me-2">${escapeHtml(currentLang === 'th' ? s.set_name_th : (s.set_name_en || s.set_name_th))}</span>
            ${probationSetSummaryHtml(s)}
        </div>
        <div class="adr-variant-exemptions">${s.is_default ? '' : assignSummaryBadgeHtml(s.assignments)}</div>
        <div class="adr-variant-actions">
            <div class="d-flex gap-1 justify-content-center flex-wrap">
                <button type="button" class="btn btn-link btn-circle-action text-warning" onclick="openProbationSetModal(${s.id})" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>
                <button type="button" class="btn btn-link btn-circle-action text-info" onclick="cloneProbationSet(${s.id})" title="${langData['clone'] || 'Clone'}"><i class="fa-solid fa-clone"></i></button>
                ${assignBtn}
                ${setDefaultBtn}
                ${deleteBtn}
            </div>
        </div>
    </div>`;
}
function loadProbationSets() {
    $.get(`${BASE_URL}/api/probation-policy-set.list`, function (res) {
        if (res && res.status) {
            currentProbationSets = res.data || [];
            $('#probationSetsContainer').html(currentProbationSets.map(probationSetCardHtml).join(''));
            if (typeof updateText === 'function') updateText(document.getElementById('probationSetsContainer'));
        }
    });
}
function resetProbationSetForm() {
    $('#pps_id').val('');
    $('#pps_set_name_th, #pps_set_name_en, #pps_probation_period_days, #pps_probation_base_salary_ratio, #pps_probation_leave_days_limit').val('');
    $('#pps_probation_defer_pvd, #pps_probation_defer_sso, #pps_probation_defer_recurring_earning').prop('checked', false);
    $('#pps_allow_leave_during_probation').prop('checked', true);
    $('#pps_probation_ot_eligible_default, #pps_probation_tax_exempt_default').val('').trigger('change');
}
function populateProbationSetForm(s) {
    $('#pps_id').val(s.id);
    $('#pps_set_name_th').val(s.set_name_th);
    $('#pps_set_name_en').val(s.set_name_en);
    $('#pps_probation_period_days').val(s.probation_period_days ?? '');
    $('#pps_probation_base_salary_ratio').val(s.probation_base_salary_ratio ?? '');
    $('#pps_probation_leave_days_limit').val(s.probation_leave_days_limit ?? '');
    $('#pps_probation_defer_pvd').prop('checked', !!s.probation_defer_pvd);
    $('#pps_probation_defer_sso').prop('checked', !!s.probation_defer_sso);
    $('#pps_probation_defer_recurring_earning').prop('checked', !!s.probation_defer_recurring_earning);
    $('#pps_allow_leave_during_probation').prop('checked', s.allow_leave_during_probation !== false);
    $('#pps_probation_ot_eligible_default').val(s.probation_ot_eligible_default === null || s.probation_ot_eligible_default === undefined ? '' : (s.probation_ot_eligible_default ? '1' : '0')).trigger('change');
    $('#pps_probation_tax_exempt_default').val(s.probation_tax_exempt_default === null || s.probation_tax_exempt_default === undefined ? '' : (s.probation_tax_exempt_default ? '1' : '0')).trigger('change');
}
function openProbationSetModal(id) {
    const s = findProbationSet(id);
    if (s) {
        populateProbationSetForm(s);
        $('#probationSetModalTitle').text(currentLang === 'th' ? s.set_name_th : (s.set_name_en || s.set_name_th));
    } else {
        resetProbationSetForm();
        $('#probationSetModalTitle text, #probationSetModalTitle').text(langData['probation_add_set'] || 'Add Set');
    }
    new bootstrap.Modal(document.getElementById('probationSetModal')).show();
}
$(document).on('click', '#btnAddProbationSet', function () {
    openProbationSetModal(null);
});
$(document).on('click', '#btnSaveProbationSet', function () {
    const $btn = $(this);
    const id = $('#pps_id').val();
    const periodDaysRaw = $('#pps_probation_period_days').val();
    const ratioRaw = $('#pps_probation_base_salary_ratio').val();
    const leaveLimitRaw = $('#pps_probation_leave_days_limit').val();
    const otDefaultRaw = $('#pps_probation_ot_eligible_default').val();
    const taxExemptDefaultRaw = $('#pps_probation_tax_exempt_default').val();
    const nameTh = $('#pps_set_name_th').val().trim();
    if (!nameTh) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const existing = id ? findProbationSet(parseInt(id, 10)) : null;
    const payload = {
        set_name_th: nameTh, set_name_en: $('#pps_set_name_en').val().trim(),
        probation_period_days: periodDaysRaw === '' ? null : periodDaysRaw,
        probation_base_salary_ratio: ratioRaw === '' ? null : ratioRaw,
        probation_leave_days_limit: leaveLimitRaw === '' ? null : leaveLimitRaw,
        probation_defer_pvd: $('#pps_probation_defer_pvd').is(':checked'),
        probation_defer_sso: $('#pps_probation_defer_sso').is(':checked'),
        probation_defer_recurring_earning: $('#pps_probation_defer_recurring_earning').is(':checked'),
        allow_leave_during_probation: $('#pps_allow_leave_during_probation').is(':checked'),
        probation_ot_eligible_default: otDefaultRaw === '' ? null : otDefaultRaw,
        probation_tax_exempt_default: taxExemptDefaultRaw === '' ? null : taxExemptDefaultRaw,
        // Assignments are edited via the SEPARATE Assign modal (openProbationSetAssignModal), not
        // this form -- re-submit whatever this Set already has so a plain Edit+Save never wipes them.
        assignments: (existing && existing.assignments) ? existing.assignments.map(a => ({ scope_type: a.scope_type, scope_id: a.scope_id })) : [],
    };
    if (id) payload.id = parseInt(id, 10);
    if (typeof setButtonLoading === 'function') setButtonLoading($btn, true);
    else $btn.prop('disabled', true);
    $.ajax({
        url: `${BASE_URL}/api/probation-policy-set.save`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify(payload),
        success: function (res) {
            if (typeof setButtonLoading === 'function') setButtonLoading($btn, false);
            else $btn.prop('disabled', false);
            if (res && res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('probationSetModal'))?.hide();
                loadProbationSets();
            } else {
                showWarning((res && res.message) || (langData['save_failed'] || 'Save failed'));
            }
        },
        error: function () {
            if (typeof setButtonLoading === 'function') setButtonLoading($btn, false);
            else $btn.prop('disabled', false);
            showError(langData['save_failed'] || 'Save failed');
        },
    });
});
function cloneProbationSet(id) {
    $.ajax({
        url: `${BASE_URL}/api/probation-policy-set.duplicate`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ id: id }),
        success: function (res) {
            if (res && res.status) {
                showSuccess(res.message || langData['save_success'] || 'Cloned successfully.');
                loadProbationSets();
            } else {
                showWarning((res && res.message) || (langData['save_failed'] || 'Clone failed'));
            }
        },
    });
}
function deleteProbationSet(id) {
    showConfirm(langData['confirm_delete_title'] || 'Delete?', langData['confirm_delete_message'] || 'This cannot be undone.', function () {
        $.ajax({
            url: `${BASE_URL}/api/probation-policy-set.delete`, method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res && res.status) {
                    showSuccess(res.message || langData['delete_success'] || 'Deleted successfully.');
                    loadProbationSets();
                } else {
                    showWarning((res && res.message) || (langData['delete_failed'] || 'Delete failed'));
                }
            },
        });
    });
}
function setDefaultProbationSet(id) {
    $.ajax({
        url: `${BASE_URL}/api/probation-policy-set.set-default`, method: 'POST', contentType: 'application/json',
        data: JSON.stringify({ id: id }),
        success: function (res) {
            if (res && res.status) {
                showSuccess(res.message || langData['save_success'] || 'Updated successfully.');
                loadProbationSets();
            } else {
                showWarning((res && res.message) || (langData['save_failed'] || 'Update failed'));
            }
        },
    });
}
function openProbationSetAssignModal(id) {
    const s = findProbationSet(id);
    if (!s) return;
    $.get(`${BASE_URL}/api/probation-policy-set.assignable-options`, function (optRes) {
        if (!optRes || !optRes.status) {
            showError(langData['save_failed'] || 'An error occurred while loading the data.');
            return;
        }
        const label = (langData['assign'] || 'Assign') + ': ' + (currentLang === 'th' ? s.set_name_th : (s.set_name_en || s.set_name_th));
        openAssignModal({
            entityLabel: label,
            assignableOptions: optRes.data,
            currentAssignments: s.assignments || [],
            onSave: function (newAssignments) {
                $.ajax({
                    url: `${BASE_URL}/api/probation-policy-set.save`, method: 'POST', contentType: 'application/json',
                    data: JSON.stringify({
                        id: s.id, set_name_th: s.set_name_th, set_name_en: s.set_name_en,
                        probation_period_days: s.probation_period_days, probation_base_salary_ratio: s.probation_base_salary_ratio,
                        probation_leave_days_limit: s.probation_leave_days_limit, probation_defer_pvd: !!s.probation_defer_pvd,
                        probation_defer_sso: !!s.probation_defer_sso, probation_defer_recurring_earning: !!s.probation_defer_recurring_earning,
                        allow_leave_during_probation: s.allow_leave_during_probation !== false,
                        probation_ot_eligible_default: s.probation_ot_eligible_default,
                        probation_tax_exempt_default: s.probation_tax_exempt_default,
                        assignments: newAssignments,
                    }),
                    success: function (res) {
                        if (res && res.status) {
                            showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                            loadProbationSets();
                        } else {
                            showWarning((res && res.message) || (langData['save_failed'] || 'Save failed'));
                        }
                    },
                });
            },
        });
    });
}

// 2026-08-30 (Phase 2, T013b) -- replaces the old direct #calculation_method change handler
// (that element is now a plain hidden input, never user-driven -- see collectPedTypeFormData()'s
// own comment). #calculation_method is kept in sync here too (not just at submit time in
// collectPedTypeFormData()) purely as a convenience/debugging aid -- nothing reads it before submit.
$(document).on('change', '#amount_source', function () {
    const val = $(this).val();
    applyAmountSourceFields(val);
    $('#calculation_method').val(val === 'event_linked' ? 'manual_entry' : (val || 'manual_entry'));
});
// 2026-09-03, Manual Entry / Employee Salary tab review Phase 1B.
$(document).on('change', '#default_interest_type', function () {
    applyDefaultInterestTypeFields($(this).val());
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
// 2026-09-02, explicit request: circular row-action buttons (see style.css's own
// ".btn-circle-action" section) replace the old adjacent .btn-group.
function cycleActionButtons(row) {
    return `<div class="d-flex gap-1 justify-content-center">
        <button type="button" class="btn btn-link btn-circle-action text-warning btn-edit-cycle" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link btn-circle-action text-danger btn-delete-cycle" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
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
            // 2026-09-02, Platform Hardening Phase 1.1 follow-up -- status switch is the first
            // column now (client-side table, safe to reorder), same shared mechanism as every other
            // table already converted. The old read-only cycleStatusBadge() renderer is gone
            // (unused after this change, deleted rather than left dead).
            { data: 'status', className: 'text-center', render: (d, t, row) => renderStatusToggleHtml(row.id, d === 'active', '/api/payroll-cycle.toggle-status') },
            { data: 'cycle_name', render: d => `<strong class="text-dark">${escapeHtml(d)}</strong>` },
            { data: 'payroll_frequency', render: d => cycleFrequencyBadge(d) },
            // 2026-09-02, reply from Origami's own team re: payroll schedule mapping -- see
            // modals.php's own comment on #external_cycle_code for the full context.
            { data: 'external_cycle_code', render: d => d ? `<code>${escapeHtml(d)}</code>` : `<span class="text-muted">-</span>` },
            { data: null, render: (d, t, row) => cycleCutoffCell(row) },
            { data: null, render: (d, t, row) => cyclePaymentCell(row) },
            { data: null, render: (d, t, row) => escapeHtml((currentLang === 'th' ? row.bank_file_format_name_th : row.bank_file_format_name_en) || row.bank_file_format_name_th || row.bank_file_format_name_en || '') },
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
            // rollout, client mode. Excludes the interactive status SWITCH (0) and actions (7).
            // 2026-09-02, Platform Hardening Phase 1.1 follow-up: indices shifted +1 now that the
            // status switch was inserted at column 0, and `status` itself dropped from the filter
            // list (interactive widget, not a plain display value, same exemption already applied
            // to every other status-switch column in this app).
            initExcelColumnFilters(self, {
                mode: 'client',
                columns: [
                    { index: 1, key: 'cycle_name' },
                    { index: 2, key: 'frequency' },
                    { index: 3, key: 'external_cycle_code' },
                    { index: 4, key: 'cutoff' },
                    { index: 5, key: 'payment' },
                    { index: 6, key: 'bank_file_format' },
                ]
            });
        },
        drawCallback: function () { getTableLang(); }
    });
}
// 2026-09-02, Platform Hardening Phase 1.1 follow-up -- reload after a successful status toggle,
// same pattern as every other converted table's own identical listener.
$(document).on('statusToggle:success', '#tb_payroll_cycle', function () { tb_payroll_cycle.ajax.reload(null, false); });
function applyFrequencyFields(freq) {
    // 2026-09-04, Backlog Phase 10, T060 Step B -- real, pre-existing, unrelated bug found and
    // fixed in the same edit: this only ever checked `freq === 'weekly'`, so a 'bi_weekly' cycle
    // NEVER showed the day-of-week fields (it silently fell into the day-of-month branch instead,
    // matching PayrollCycleModel::save()'s own identical bug on the backend side -- both fixed
    // together here, see that method's own comment for the full story). Confirmed real via
    // PayrollCycleModel::suggestNextPeriod(), which has always treated 'bi_weekly' the same as
    // 'weekly' (both go through nextWeekBasedPeriod()/cutoff_day_of_week) -- this UI/save()
    // mismatch meant a bi_weekly cycle could never actually be configured correctly through this
    // form before now. 'daily' is new this round -- neither day-of-month NOR day-of-week applies
    // to a period that's always exactly today, so both field groups hide for it.
    const isWeekBased = freq === 'weekly' || freq === 'bi_weekly';
    const isDaily = freq === 'daily';
    $('#cutoff_dom_wrapper').toggleClass('d-none', isWeekBased || isDaily);
    $('#cutoff_dow_wrapper').toggleClass('d-none', !isWeekBased);
    $('#payment_dom_wrapper').toggleClass('d-none', isWeekBased || isDaily);
    $('#payment_dow_wrapper').toggleClass('d-none', !isWeekBased);
    $('#cutoff_day_of_month').toggleClass('required', !isWeekBased && !isDaily);
    $('#payment_day_of_month').toggleClass('required', !isWeekBased && !isDaily);
    $('#cutoff_day_of_week').toggleClass('required', isWeekBased);
    $('#payment_day_of_week').toggleClass('required', isWeekBased);
}
function applyOtCutoffFields(type) {
    $('#ot_custom_wrapper').toggleClass('d-none', type !== 'custom');
}
function applyLastDayToggle(checkboxId, inputId) {
    const checked = $(`#${checkboxId}`).is(':checked');
    $(`#${inputId}`).prop('disabled', checked).toggleClass('required', !checked);
    if (checked) $(`#${inputId}`).val('');
}
// 2026-09-02, explicit request: multi-bank-account payroll cycles -- renders the checkbox list of
// EVERY one of this company's own active accounts (bankAccountOptions() called with a large limit
// instead of select2's own paginated ajax mode, since a checkbox list needs the WHOLE set up front,
// not searched page by page -- a company's own bank account list is realistically small). `selected`
// is an array of {bank_account_id, is_default} from PayrollCycleModel::get()'s own bank_accounts
// join (empty for a brand-new cycle). Each row: a checkbox (include this account at all) + a radio
// (which included account is the default) -- the radio is only enabled while its own checkbox is
// checked, and PayrollCycleModel::saveBankAccounts() itself rejects anything but exactly one default
// whenever the list isn't empty (this function just keeps the UI from letting that state happen).
function renderCycleBankAccountsList(selected) {
    const selectedMap = {};
    (selected || []).forEach(function (row) { selectedMap[row.bank_account_id] = !!Number(row.is_default); });
    $.ajax({
        url: `${BASE_URL}/api/payroll-cycle.bank-account.options`,
        method: 'POST', dataType: 'json', data: { page: 1, limit: 200, searchTerm: '' },
        success: function (res) {
            const items = (res.status && res.data && res.data.items) || [];
            const $list = $('#cycleBankAccountsList');
            if (items.length === 0) {
                $list.html(`<div class="text-muted small" data-i18n="modal_cycle_bank_account_none">This company has no bank accounts configured yet.</div>`);
                if (typeof updateText === 'function') updateText($list[0]);
                return;
            }
            $list.html(items.map(function (item) {
                const label = currentLang === 'th' ? (item.text_th || item.text_en) : (item.text_en || item.text_th);
                const isChecked = Object.prototype.hasOwnProperty.call(selectedMap, item.id);
                const isDefault = isChecked && selectedMap[item.id];
                return `
                    <div class="form-check d-flex align-items-center justify-content-between py-1 cycle-bank-account-row" data-id="${item.id}">
                        <div class="form-check">
                            <input class="form-check-input cycle-bank-account-check" type="checkbox" id="cycleBankAcc${item.id}" value="${item.id}"${isChecked ? ' checked' : ''}>
                            <label class="form-check-label small" for="cycleBankAcc${item.id}">${$('<div>').text(label).html()}</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input cycle-bank-account-default" type="radio" name="cycle_bank_account_default" value="${item.id}"${isDefault ? ' checked' : ''}${!isChecked ? ' disabled' : ''}>
                            <label class="form-check-label small text-muted" data-i18n="default">Default</label>
                        </div>
                    </div>`;
            }).join(''));
            if (typeof updateText === 'function') updateText($list[0]);
        }
    });
}
function collectCycleBankAccounts() {
    const accounts = [];
    $('#cycleBankAccountsList .cycle-bank-account-check:checked').each(function () {
        const id = $(this).val();
        accounts.push({
            bank_account_id: id,
            is_default: $(`#cycleBankAccountsList .cycle-bank-account-default[value="${id}"]`).is(':checked') ? 1 : 0,
        });
    });
    return accounts;
}
function resetCycleForm() {
    $('#payrollCycleForm')[0].reset();
    $('#cycle_id').val('');
    $('#external_cycle_code').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#payroll_frequency').val('').trigger('change');
    $('#cutoff_day_of_week').val('').trigger('change');
    $('#payment_day_of_week').val('').trigger('change');
    $('#bank_file_format_id').val('').trigger('change');
    $('#cycle_default_payment_method_id').val('').trigger('change');
    renderCycleBankAccountsList([]);
    $('#cutoff_day_of_month, #payment_day_of_month, #ot_cutoff_day_of_month').prop('disabled', false);
    applyFrequencyFields('');
    applyOtCutoffFields('same_as_attendance');
}
function populateCycleForm(row) {
    $('#cycle_id').val(row.id);
    $('#cycle_name').val(row.cycle_name);
    $('#external_cycle_code').val(row.external_cycle_code || '');
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
    // 2026-09-02, multi-bank-account payroll -- row.bank_accounts comes from
    // PayrollCycleModel::get()'s own getBankAccounts() join (every account this cycle currently
    // offers, most-default-first). row.bank_account_id (the single legacy/denormalized column) is
    // no longer read directly here -- the checkbox list below is the real source of truth now.
    renderCycleBankAccountsList(row.bank_accounts || []);
    if (row.default_payment_method_id) {
        const pmLabel = currentLang === 'th' ? row.default_payment_method_name_th : row.default_payment_method_name_en;
        const pmOpt = new Option(pmLabel || row.default_payment_method_name_th || row.default_payment_method_name_en || '', row.default_payment_method_id, true, true);
        $('#cycle_default_payment_method_id').append(pmOpt).trigger('change');
    } else {
        $('#cycle_default_payment_method_id').val('').trigger('change');
    }
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
        external_cycle_code: $('#external_cycle_code').val().trim() || null,
        payroll_frequency: freq,
        ot_cutoff_type: $('input[name="ot_cutoff_type"]:checked').val(),
        bank_file_format_id: $('#bank_file_format_id').val(),
        default_payment_method_id: $('#cycle_default_payment_method_id').val() || null
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
    // 2026-09-02, multi-bank-account payroll -- an account's own "Default" radio only makes sense
    // while that account is actually included; unchecking the checkbox disables (and unchecks) its
    // radio, matching PayrollCycleModel::saveBankAccounts()'s own "exactly one default among the
    // INCLUDED accounts" invariant.
    $(document).on('change', '.cycle-bank-account-check', function () {
        const $row = $(this).closest('.cycle-bank-account-row');
        const $radio = $row.find('.cycle-bank-account-default');
        if ($(this).is(':checked')) {
            $radio.prop('disabled', false);
            // 2026-09-03, Manual Entry / Platform UX review Phase 8: auto-select this account as the
            // default the moment it becomes the FIRST included account with no default chosen yet --
            // previously nothing did this, so including the company's first bank account (the
            // overwhelmingly common case) required a separate manual "Default" click or else hit
            // saveBankAccounts()'s own "exactly one default required" validation block on submit.
            // Only fires when no OTHER account is already marked default -- never silently steals the
            // default away from an account already chosen (a loaded existing cycle, or one the admin
            // already picked earlier in this same session).
            if ($('#cycleBankAccountsList .cycle-bank-account-default:checked').length === 0) {
                $radio.prop('checked', true);
            }
        } else {
            $radio.prop('disabled', true).prop('checked', false);
        }
    });
    $(document).on('submit', '#payrollCycleForm', function (e) {
        e.preventDefault();
        const invalidEl = validateCycleForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        // 2026-09-02, multi-bank-account payroll -- client-side mirror of
        // PayrollCycleModel::saveBankAccounts()'s own "exactly one default among the included
        // accounts" invariant (an empty list is fine -- falls back to the company default, same as
        // before this feature existed).
        const accounts = collectCycleBankAccounts();
        if (accounts.length > 0 && !accounts.some(function (a) { return a.is_default; })) {
            showWarning(langData['modal_cycle_bank_account_default_required'] || 'Please mark exactly one account as the default.');
            return;
        }
        const payload = collectCycleFormData();
        const $btn = $('#payrollCycleForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
        // 2026-09-02, multi-bank-account payroll -- cycle fields and its account list are 2
        // genuinely separate tables/endpoints (PayrollCycleModel::save() / saveBankAccounts()), so
        // this chains both AJAX calls under one Save button -- the SECOND call needs the cycle's own
        // id, which only exists after the FIRST call succeeds for a brand-new cycle.
        $.ajax({
            url: `${BASE_URL}/api/payroll-cycle.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
        }).then(function (res) {
            if (!res.status) {
                return $.Deferred().reject(res).promise();
            }
            return $.ajax({
                url: `${BASE_URL}/api/payroll-cycle.save-bank-accounts`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ cycle_id: res.id, accounts: accounts }),
            }).then(function (acctRes) {
                return acctRes.status ? res : $.Deferred().reject(acctRes).promise();
            });
        }).then(function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            showSuccess(langData['save_success'] || 'Saved successfully.');
            bootstrap.Modal.getInstance(document.getElementById('payrollCycleModal')).hide();
            if (tb_payroll_cycle) tb_payroll_cycle.ajax.reload(null, false);
        }, function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            showWarning((res && res.message) || langData['save_failed'] || 'Failed to save data.');
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
    // 2026-08-30, Phase 8 (T043) -- added alongside the original 4, same table/pattern.
    early_leave: { icon: 'fa-door-open', rt: 'rt-2' },
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
    return `<span class="badge bg-info-subtle text-info">${scopeLabel}: ${escapeHtml(row.scope_label || '?')}</span>`;
}

const ATTENDANCE_DEFAULT_RATE_UNIT = { late: 'minute', early_leave: 'minute', absent: 'day', unpaid_leave: 'day', leave_pending: 'day' };

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
    const map = { late: 'attendance_deduction_event_late', early_leave: 'attendance_deduction_event_early_leave', absent: 'attendance_deduction_event_absent', unpaid_leave: 'attendance_deduction_event_unpaid_leave', leave_pending: 'attendance_deduction_event_leave_pending' };
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
    // 2026-08-30 (T015, "เพิ่มตัวเลือก 'ไม่หัก'") -- no_deduction has no rate/formula of its own at
    // all, same as percent_of_rate already doesn't need the rate-unit picker (it's always
    // minute-based internally regardless -- see SyncPayResolver's own docblock on that).
    $('#attendanceRateUnitWrapper').toggleClass('d-none', method === 'percent_of_rate' || method === 'no_deduction');
    applyAttendanceRateUnitLabels($('#attendanceRateUnit').val() || 'minute');
}
/* 2026-09-01, explicit request: "ให้เลือกก่อนว่าหัก หรือไม่หัก เป็น radio จากนั้นค่อยแสดงหรือซ่อน Form ที่
 * เหลือ" -- 'no_deduction' was already just another #attendanceDeductionMethod option; this promotes
 * it to an up-front radio (#attendanceRuleDeductChoice) that shows/hides #attendanceRuleDeductFieldsWrapper
 * (Deduction Method + its rate/amount sub-sections + Calculation Preview) as a whole. Still drives the
 * SAME underlying <select id="attendanceDeductionMethod"> value under the hood, so
 * saveAttendanceDeductionRule()/collectAttendanceDeductionDraftForPreview()/AttendanceDeductionRuleModel
 * needed zero changes -- method_code just arrives as 'no_deduction' exactly like it always could. */
function attendanceMethodStaticLabel(code) {
    const key = { percent_of_rate: 'attendance_deduction_method_percent_of_rate', flat_amount: 'attendance_deduction_method_flat_amount', tiered_bracket: 'attendance_deduction_method_tiered_bracket', no_deduction: 'attendance_deduction_method_no_deduction' }[code];
    return (key && langData[key]) || code;
}
let attendanceMethodBeforeNoDeduction = 'percent_of_rate'; // remembers the real method so toggling ไม่หัก -> หัก restores it, not a blank picker
function applyAttendanceRuleDeductChoice(choice) {
    const wantsNoDeduction = choice === 'no_deduction';
    $('#attendanceRuleDeductFieldsWrapper').toggleClass('d-none', wantsNoDeduction);
    const $method = $('#attendanceDeductionMethod');
    if (wantsNoDeduction) {
        const current = $method.val();
        if (current && current !== 'no_deduction') attendanceMethodBeforeNoDeduction = current;
        $method.empty().append(new Option(attendanceMethodStaticLabel('no_deduction'), 'no_deduction', true, true)).trigger('change.select2');
        applyAttendanceDeductionMethodFields('no_deduction');
    } else {
        const restoreTo = attendanceMethodBeforeNoDeduction || 'percent_of_rate';
        $method.empty().append(new Option(attendanceMethodStaticLabel(restoreTo), restoreTo, true, true)).trigger('change.select2');
        applyAttendanceDeductionMethodFields(restoreTo);
    }
    $('#attendanceCalcPreviewResult').addClass('d-none');
}
$(document).on('change', 'input[name="attendanceRuleDeductChoice"]', function () {
    applyAttendanceRuleDeductChoice($(this).val());
});
$(document).on('change', '#attendanceDeductionMethod', function () {
    const val = $(this).val();
    // Safety net: the dropdown itself still technically offers 'no_deduction' as a searchable result
    // (server-side master list, unfiltered) -- if picked directly, keep the radio/wrapper in sync
    // instead of leaving the radio saying "หัก" while the hidden select says otherwise.
    if (val === 'no_deduction') {
        $('#attendanceRuleDeductNo').prop('checked', true);
        $('#attendanceRuleDeductFieldsWrapper').addClass('d-none');
    } else if (val) {
        attendanceMethodBeforeNoDeduction = val;
    }
    applyAttendanceDeductionMethodFields(val);
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
    // 2026-09-01: init the หัก/ไม่หัก radio + wrapper visibility to match the loaded row's real
    // method_code (see applyAttendanceRuleDeductChoice() above for the full mechanism).
    const isNoDeduction = r.method_code === 'no_deduction';
    attendanceMethodBeforeNoDeduction = isNoDeduction ? 'percent_of_rate' : (r.method_code || 'percent_of_rate');
    $('#attendanceRuleDeductYes').prop('checked', !isNoDeduction);
    $('#attendanceRuleDeductNo').prop('checked', isNoDeduction);
    $('#attendanceRuleDeductFieldsWrapper').toggleClass('d-none', isNoDeduction);
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
    return `<span class="${parts.cls}">${escapeHtml(parts.text)}</span>`;
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
    // 2026-08-30 (T015) -- always zero, nothing to trace through a rate/multiplier for.
    if (formula.type === 'attendance_no_deduction') {
        return `<div class="calc-preview-step">${langData['calc_preview_step_no_deduction'] || 'This method always deducts 0, regardless of the sample minutes above.'}</div>`;
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
    submitAttendanceDeductionRule(payload);
}
// 2026-09-02, explicit request: "Recheck อีกทีว่า ถ้าประเภทเดียว Assign ซ้ำ ต้องมี Alert เตือน และถ้าผู้ใช้
// ต้องการ Save ทับเพื่อ Update ข้อมูลใหม่ต้องทำได้" -- AttendanceDeductionRuleModel::ruleSave() already
// refused a duplicate (event_code, scope) pair server-side (confirmed still true, see
// tests/attendance_deduction_rule_test.php's own "duplicate-variant rejection" section) but the
// frontend only ever showed it as a plain toast with no way forward -- an admin hitting this had to
// cancel, go find the existing variant themselves, and re-enter everything by hand. Split the actual
// ajax call out into its own function so a confirmed "update the existing one instead" can re-invoke
// it with the SAME payload plus the conflicting row's own id (res.conflict_id, added server-side this
// same round) -- turning the rejected create into a normal update, no data re-entry needed.
function submitAttendanceDeductionRule(payload) {
    $.ajax({
        url: `${BASE_URL}/api/attendance-deduction-rule.save`, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                const modalEl = document.getElementById('attendanceDeductionRuleModal');
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) { modalInstance.hide(); }
                loadAttendanceDeductionCards();
            } else if (res.conflict_id) {
                Swal.fire({
                    icon: 'warning',
                    title: langData['attendance_deduction_conflict_title'] || 'A Rule Already Exists',
                    text: res.message,
                    showCancelButton: true,
                    confirmButtonText: langData['attendance_deduction_conflict_confirm'] || 'Update the Existing Rule',
                    cancelButtonText: langData['cancel'] || 'Cancel',
                    confirmButtonColor: '#FF9900',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        submitAttendanceDeductionRule(Object.assign({}, payload, { id: res.conflict_id }));
                    }
                });
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
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
    // 2026-08-30 (T015, "เพิ่มตัวเลือก 'ไม่หัก'") -- checked explicitly, NOT folded into the trailing
    // percent_of_rate fallback below (that fallback used to be an unconditional catch-all -- a real
    // bug this new method_code would have hit immediately, showing a misleading "Percent of Rate
    // (1.00x)" badge for a rule actually configured to deduct nothing at all).
    if (r.method_code === 'no_deduction') {
        return `<span class="badge bg-secondary-subtle text-secondary">${langData['attendance_deduction_method_no_deduction'] || 'No Deduction'}</span>`;
    }
    const mult = parseFloat(r.multiplier_rate || 1).toFixed(2);
    return `<span class="badge bg-success-subtle text-success">${langData['attendance_deduction_method_percent_of_rate'] || 'Percent of Rate'}</span> <span class="text-muted small ms-1">${mult}x</span>`;
}
function attendanceDeductionExemptionsSummary(r) {
    const exemptions = (r && r.exemptions) || [];
    if (!exemptions.length) {
        return `<span class="text-muted small">${langData['attendance_deduction_no_exemptions'] || 'None'}</span>`;
    }
    const labels = exemptions.slice(0, 3).map(ex => `<span class="badge bg-light text-secondary border me-1 mb-1">${escapeHtml(ex.label || '?')}</span>`).join('');
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
    // 2026-09-02, explicit request: "หัก ไม่หัก ควรตั้งค่าได้จากในหน้า Card เลยครับ และถ้าไม่หักก็ไม่ต้องมี
    // ปุ่มแก้ไข" -- was only settable inside the Edit modal's own radio (see modals.php's own
    // attendanceRuleDeductChoice comment). isDeduct mirrors that SAME method_code === 'no_deduction'
    // check, now surfaced as a second inline switch right next to the existing Active one, saved the
    // same "toggle -> immediate AJAX save, no modal" way .attendance-deduction-active-toggle already
    // works. When off, there is nothing left to configure (no rate/method at all for 'no_deduction'
    // -- see AttendanceDeductionRuleModel's own docblock), so the Edit button is dropped entirely
    // rather than opening a modal with nothing meaningful in it.
    // 2026-09-04, Backlog Phase 10, T054: the 2026-09-02 round above only ever dropped Edit -- Assign
    // (exemptions) stayed visible even when method_code='no_deduction', even though an exemption list
    // is meaningless once nothing is being deducted in the first place (there's nothing to exempt
    // anyone FROM). T054's own literal wording ("checked = card shows only a Clone button, no Assign/
    // Edit") is what this fixes -- Assign now shares the exact same isDeduct gate Edit already had.
    // Exemption semantics themselves are UNCHANGED (still "deduct everyone except the listed scopes",
    // not converted to T055's new inclusion-based entity_assignments -- confirmed via AskUserQuestion
    // this round: reuse the EXISTING working mechanism, don't replace it). The exemption badge list
    // (attendanceDeductionExemptionsSummary() below) already doubles as T054's "card must show a tag
    // saying so" requirement -- it renders the actual exempted department/team/employee names as
    // badges directly on the row whenever any exist, not just a generic "Scoped" flag -- and is left
    // untouched (still visible even when no_deduction is on, so stale exemption data isn't hidden if
    // deduction is ever turned back on).
    const isDeduct = r.method_code !== 'no_deduction';
    const editBtn = isDeduct
        ? `<button type="button" class="btn btn-link btn-circle-action text-warning" onclick="openAttendanceDeductionRuleModal('${eventCode}', ${r.id ? r.id : 'null'})" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>`
        : '';
    const assignBtn = isDeduct
        ? `<button type="button" class="btn btn-link btn-circle-action text-secondary" onclick="openAttendanceDeductionAssignModal('${eventCode}', ${r.id ? r.id : 'null'})" title="${langData['attendance_deduction_assign_title'] || 'Exempt Departments / Teams / Employees'}"><i class="fa-solid fa-user-shield"></i></button>`
        : '';
    return `<div class="adr-variant-row" data-event="${eventCode}" data-id="${idAttr}">
        <div class="adr-variant-toggles">
            <div class="form-check form-switch form-switch-sm mb-1" title="${langData['status'] || 'Status'}">
                <input class="form-check-input attendance-deduction-active-toggle" type="checkbox" role="switch" data-event="${eventCode}" data-id="${idAttr}" ${isActive ? 'checked' : ''}>
                <label class="form-check-label small text-muted">${langData['active'] || 'Active'}</label>
            </div>
            <div class="form-check form-switch form-switch-sm mb-0" title="${langData['attendance_deduction_apply'] || 'Apply Deduction?'}">
                <input class="form-check-input attendance-deduction-deduct-toggle" type="checkbox" role="switch" data-event="${eventCode}" data-id="${idAttr}" ${isDeduct ? 'checked' : ''}>
                <label class="form-check-label small text-muted">${langData['attendance_deduction_apply_yes'] || 'Deduct'}</label>
            </div>
        </div>
        <div class="adr-variant-main">
            ${attendanceScopeBadgeHtml(r)}
            ${r.label ? `<span class="adr-variant-label">${escapeHtml(r.label)}</span>` : ''}
            ${attendanceDeductionMethodSummary(r)}
        </div>
        <div class="adr-variant-exemptions">${attendanceDeductionExemptionsSummary(r)}</div>
        <div class="adr-variant-actions">
            <!-- 2026-09-02, explicit request: circular row-action buttons (see style.css's own
                 ".btn-circle-action" section) replace the old adjacent .btn-group -- this card's own
                 per-variant action cluster uses the exact same old convention as every DataTable row,
                 so it's included in the same rollout for consistency within this settings page. -->
            <div class="d-flex gap-1 justify-content-center flex-wrap">
                ${editBtn}
                <button type="button" class="btn btn-link btn-circle-action text-primary" onclick="cloneAttendanceDeductionRule('${eventCode}', ${r.id ? r.id : 'null'})" title="${langData['clone'] || 'Clone'}"><i class="fa-solid fa-clone"></i></button>
                ${assignBtn}
                ${canDelete ? `<button type="button" class="btn btn-link btn-circle-action text-danger" onclick="deleteAttendanceDeductionVariant(${r.id})" title="${langData['delete'] || 'Delete'}"><i class="fa-solid fa-trash-can"></i></button>` : ''}
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
    // AttendanceDeductionRuleModel's own docblock). 2026-08-30 (Phase 8, T043): early_leave added
    // the same way -- was already computable via SyncPayResolver's own default formula, just never
    // configurable here.
    const events = ['late', 'early_leave', 'absent', 'unpaid_leave', 'leave_pending'];
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
// 2026-09-02, explicit request: "หัก ไม่หัก ควรตั้งค่าได้จากในหน้า Card เลยครับ" -- same inline
// toggle-then-immediately-save pattern as .attendance-deduction-active-toggle just above (no modal).
// Always an UPDATE of an existing row (r.id is always set here -- the un-saved virtual default row
// already resolves to method_code='percent_of_rate', i.e. isDeduct=true, so its own switch never
// needs to fire this handler to flip it on), so the duplicate-scope conflict_id flow
// submitAttendanceDeductionRule() handles elsewhere can never trigger from this toggle -- scope
// never changes on an update, only on a brand-new variant.
$(document).on('change', '.attendance-deduction-deduct-toggle', function () {
    const eventCode = $(this).data('event');
    const id = $(this).data('id') || null;
    const wantsDeduct = $(this).is(':checked');
    const r = findAttendanceVariant(eventCode, id) || {};
    // Turning deduction back ON with nothing real configured yet (was 'no_deduction', or somehow no
    // method at all) falls back to the master default (percent_of_rate @ 1.00x) -- the exact same
    // default a brand-new row already starts with -- rather than guessing at a rate. The Edit button
    // reappears the moment this saves, so fine-tuning away from the default is still one click away.
    const needsDefaultMethod = wantsDeduct && (!r.method_code || r.method_code === 'no_deduction');
    const payload = {
        event_code: eventCode, is_active: r.is_active !== false,
        method_code: wantsDeduct ? (needsDefaultMethod ? 'percent_of_rate' : r.method_code) : 'no_deduction',
        rate_unit: needsDefaultMethod ? (ATTENDANCE_DEFAULT_RATE_UNIT[eventCode] || 'minute') : r.rate_unit,
        rate_per_unit: needsDefaultMethod ? null : r.rate_per_unit,
        multiplier_rate: needsDefaultMethod ? '1.00' : r.multiplier_rate,
        scope_type: r.scope_type || null, scope_id: r.scope_id || null, label: r.label || null,
        brackets: needsDefaultMethod ? [] : (r.brackets || []).map(b => ({ min_units: b.min_units, max_units: b.max_units, deduction_amount: b.deduction_amount })),
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
                $toggle.prop('checked', !wantsDeduct);
            }
        },
        error: function () {
            showWarning(langData['save_failed'] || 'An error occurred while saving.');
            $toggle.prop('checked', !wantsDeduct);
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
        <label class="form-check-label small" for="ada_${scopeType}_${item.id}">${escapeHtml(item.label)}</label>
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
