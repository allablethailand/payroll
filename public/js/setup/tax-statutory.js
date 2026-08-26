let tb_statutory_item;
let tb_rate_history;
let tb_company_setting;
let currentItemCtx = null;
let currentCsItem = null;

function toIsoDateTs(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function toDisplayDateTs(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function escapeHtmlTs(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function itemNameTs(row) {
    return (currentLang === 'th' ? row.name_th : row.name_en) || row.name_th || row.name_en || '';
}
function categoryBadgeTs(cat) {
    const key = 'category_' + cat;
    return `<span class="badge bg-light text-dark border">${langData[key] || cat}</span>`;
}
function calcMethodLabelTs(method) {
    const key = 'calc_method_' + method;
    return langData[key] || method;
}
function statusBadgeTs(status) {
    const isActive = status === 'active';
    const cls = isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
    const text = isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive');
    return `<span class="badge ${cls}">${text}</span>`;
}
function fmtNumTs(n) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function currentRateCellTs(row) {
    if (!row.current_rate_id) {
        return `<span class="text-muted small">${langData['no_rate_configured'] || 'No rate configured yet'}</span>`;
    }
    if (row.calc_method === 'flat_rate') {
        const parts = [];
        if (row.is_employee_applicable == 1 && row.employee_rate !== null) parts.push(`${langData['modal_employee_rate'] ? '' : ''}${Number(row.employee_rate)}%`);
        if (row.is_employer_applicable == 1 && row.employer_rate !== null) parts.push(`${Number(row.employer_rate)}%`);
        return parts.join(' / ');
    }
    if (row.calc_method === 'fixed_amount') {
        const parts = [];
        if (row.is_employee_applicable == 1 && row.employee_amount !== null) parts.push(fmtNumTs(row.employee_amount));
        if (row.is_employer_applicable == 1 && row.employer_amount !== null) parts.push(fmtNumTs(row.employer_amount));
        return parts.join(' / ');
    }
    if (row.calc_method === 'progressive_bracket') {
        return `<span class="text-muted small"><i class="fa-solid fa-layer-group me-1"></i>${langData['tax_brackets'] || 'Tax Brackets'}</span>`;
    }
    return `<span class="text-muted small">${langData['calc_method_formula'] || 'Formula-based'}</span>`;
}
function actionButtonsTs(row) {
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-warning btn-edit-item" data-id="${row.id}" title="${langData['edit'] || 'Edit'}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link text-primary border-start btn-manage-rate" data-id="${row.id}" title="${langData['manage_rate'] || 'Manage Rate'}"><i class="fa-solid fa-clock-rotate-left"></i></button>
        <button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-item" data-id="${row.id}" title="${langData['delete'] || 'Delete'}"><i class="fas fa-trash-alt"></i></button>
    </div>`;
}

function initStatutoryItemTable() {
    if ($.fn.DataTable.isDataTable('#tb_statutory_item')) {
        $('#tb_statutory_item').DataTable().ajax.reload(null, false);
        return;
    }
    tb_statutory_item = $('#tb_statutory_item').DataTable({
        responsive: true,
        ajax: {
            url: `${BASE_URL}/api/statutory-item.list`,
            dataSrc: 'data',
            data: function (d) {
                d.country_code = $('#filter_country_code').val() || '';
            }
        },
        columns: [
            { data: 'country_code', render: d => `<span class="badge bg-primary-subtle text-primary">${d}</span>` },
            { data: 'code', render: d => `<code class="fw-bold text-dark">${escapeHtmlTs(d)}</code>` },
            { data: null, render: (d, t, row) => escapeHtmlTs(itemNameTs(row)) },
            { data: 'category', render: d => categoryBadgeTs(d) },
            { data: 'calc_method', render: d => calcMethodLabelTs(d) },
            { data: null, render: (d, t, row) => currentRateCellTs(row) },
            { data: 'status', render: d => statusBadgeTs(d) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => actionButtonsTs(row) }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-item').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-item">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="statutory_item">${langData['statutory_item'] || 'Statutory Item'}</span>
                    </button>
                `);
            }
        },
        drawCallback: function () { getTableLang(); }
    });
}

function resetItemForm() {
    $('#statutoryItemForm')[0].reset();
    $('#item_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#item_country_code').val('').trigger('change');
    $('#item_category').val('').trigger('change');
    $('#item_calc_method').val('').trigger('change');
    $('#item_calc_base').val('').trigger('change');
    $('#item_is_employee_applicable, #item_is_employer_applicable, #item_default_is_active, #item_status').prop('checked', true);
    $('#item_is_company_rate_editable').prop('checked', false);
    $('#item_sort_order').val(0);
}
function populateItemForm(row) {
    $('#item_id').val(row.id);
    if (row.country_code) {
        const opt = new Option(row.countries_name_th || row.country_code, row.country_code, true, true);
        $('#item_country_code').append(opt).trigger('change');
    }
    $('#item_code').val(row.code);
    $('#item_name_th').val(row.name_th);
    $('#item_name_en').val(row.name_en);
    $('#item_category').val(row.category).trigger('change');
    $('#item_calc_method').val(row.calc_method).trigger('change');
    $('#item_calc_base').val(row.calc_base).trigger('change');
    $('#item_is_employee_applicable').prop('checked', Number(row.is_employee_applicable) === 1);
    $('#item_is_employer_applicable').prop('checked', Number(row.is_employer_applicable) === 1);
    $('#item_default_is_active').prop('checked', Number(row.default_is_active) === 1);
    $('#item_is_company_rate_editable').prop('checked', Number(row.is_company_rate_editable) === 1);
    $('#item_sort_order').val(row.sort_order || 0);
    $('#item_status').prop('checked', row.status === 'active');
}
function validateItemForm() {
    let firstInvalid = null;
    $('#statutoryItemModal .required').each(function () {
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
function collectItemFormData() {
    return {
        id: $('#item_id').val() || undefined,
        country_code: $('#item_country_code').val(),
        code: $('#item_code').val().trim(),
        name_th: $('#item_name_th').val().trim(),
        name_en: $('#item_name_en').val().trim(),
        category: $('#item_category').val(),
        calc_method: $('#item_calc_method').val(),
        calc_base: $('#item_calc_base').val(),
        is_employee_applicable: $('#item_is_employee_applicable').is(':checked'),
        is_employer_applicable: $('#item_is_employer_applicable').is(':checked'),
        default_is_active: $('#item_default_is_active').is(':checked'),
        is_company_rate_editable: $('#item_is_company_rate_editable').is(':checked'),
        sort_order: $('#item_sort_order').val() || 0,
        status: $('#item_status').is(':checked') ? 'active' : 'inactive'
    };
}

/* ---------- Rate History (list) ---------- */
function rateHistoryActionButtonsTs(row) {
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-warning btn-edit-rate" data-id="${row.id}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-link py-1 text-danger border-start btn-delete-rate" data-id="${row.id}"><i class="fas fa-trash-alt"></i></button>
    </div>`;
}
function rateSummaryTs(row) {
    if (!currentItemCtx) return '';
    if (currentItemCtx.calc_method === 'flat_rate') {
        const parts = [];
        if (row.employee_rate !== null) parts.push(`${langData['modal_employee_rate'] || 'Employee'}: ${Number(row.employee_rate)}%`);
        if (row.employer_rate !== null) parts.push(`${langData['modal_employer_rate'] || 'Employer'}: ${Number(row.employer_rate)}%`);
        return parts.join(' / ');
    }
    if (currentItemCtx.calc_method === 'fixed_amount') {
        const parts = [];
        if (row.employee_amount !== null) parts.push(`${langData['modal_employee_amount'] || 'Employee'}: ${fmtNumTs(row.employee_amount)}`);
        if (row.employer_amount !== null) parts.push(`${langData['modal_employer_amount'] || 'Employer'}: ${fmtNumTs(row.employer_amount)}`);
        return parts.join(' / ');
    }
    if (currentItemCtx.calc_method === 'progressive_bracket') {
        return `${row.bracket_count || 0} ${langData['tax_brackets'] || 'Tax Brackets'}`;
    }
    return langData['calc_method_formula'] || 'Formula-based';
}
function initRateHistoryTable() {
    if ($.fn.DataTable.isDataTable('#tb_rate_history')) {
        $('#tb_rate_history').DataTable().ajax.reload(null, false);
        return;
    }
    tb_rate_history = $('#tb_rate_history').DataTable({
        responsive: true,
        searching: false,
        paging: false,
        info: false,
        ajax: {
            url: `${BASE_URL}/api/statutory-item.rate-history.list`,
            dataSrc: 'data',
            data: function (d) { d.item_id = currentItemCtx ? currentItemCtx.id : 0; }
        },
        columns: [
            // object-form render (display only) -- client-side table with no explicit `order` set,
            // so DataTables defaults to sorting by column 0 (this one) ascending; 'sort'/'filter'
            // must stay on the raw ISO string, see reports/index.js's own comment for why.
            { data: 'effective_date', render: { display: d => formatDisplayDate(d), sort: d => d, filter: d => d } },
            { data: 'end_date', render: { display: d => d ? formatDisplayDate(d) : `<span class="badge bg-success-subtle text-success">${langData['current_version'] || 'Current'}</span>`, sort: d => d || '', filter: d => d || '' } },
            { data: null, render: (d, t, row) => rateSummaryTs(row) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => rateHistoryActionButtonsTs(row) }
        ],
        language: getTableLang(),
        drawCallback: function () { getTableLang(); }
    });
}
function openRateHistoryModal(row) {
    currentItemCtx = {
        id: row.id,
        calc_method: row.calc_method,
        is_employee_applicable: Number(row.is_employee_applicable) === 1,
        is_employer_applicable: Number(row.is_employer_applicable) === 1
    };
    $('#rateHistoryItemName').text(`(${row.code} - ${itemNameTs(row)})`);
    initRateHistoryTable();
    new bootstrap.Modal(document.getElementById('rateHistoryModal')).show();
}

/* ---------- Rate Version (form) ---------- */
function applyCalcMethodFieldsTs(calcMethod) {
    $('#rate_flat_fields').toggleClass('d-none', calcMethod !== 'flat_rate');
    $('#rate_amount_fields').toggleClass('d-none', calcMethod !== 'fixed_amount');
    $('#rate_bracket_fields').toggleClass('d-none', calcMethod !== 'progressive_bracket');
    $('#rate_formula_fields').toggleClass('d-none', calcMethod !== 'formula');

    const showEmployee = currentItemCtx ? currentItemCtx.is_employee_applicable : true;
    const showEmployer = currentItemCtx ? currentItemCtx.is_employer_applicable : true;
    $('#rate_employee_rate_wrapper, #rate_employee_amount_wrapper').toggleClass('d-none', !showEmployee);
    $('#rate_employer_rate_wrapper, #rate_employer_amount_wrapper').toggleClass('d-none', !showEmployer);
    $('#rate_employee_rate').toggleClass('required', calcMethod === 'flat_rate' && showEmployee);
    $('#rate_employer_rate').toggleClass('required', calcMethod === 'flat_rate' && showEmployer);
    $('#rate_employee_amount').toggleClass('required', calcMethod === 'fixed_amount' && showEmployee);
    $('#rate_employer_amount').toggleClass('required', calcMethod === 'fixed_amount' && showEmployer);
    $('#rate_formula_config').toggleClass('required', calcMethod === 'formula');
}
function recalcBracketRowsTs() {
    $('#bracketBody tr').each(function (idx) {
        if (idx === 0) return;
        const prevMax = $('#bracketBody tr').eq(idx - 1).find('.bracket-max').val();
        const min = prevMax !== '' ? (parseFloat(prevMax) + 0.01).toFixed(2) : '';
        $(this).find('.bracket-min').val(min);
    });
}
function addBracketRow(min, max, rate) {
    const idx = $('#bracketBody tr').length;
    const $row = $(`<tr>
        <td><input type="number" step="0.01" class="form-control form-control-sm bracket-min" value="${min !== undefined ? min : ''}" ${idx > 0 ? 'readonly' : ''}></td>
        <td><input type="number" step="0.01" class="form-control form-control-sm bracket-max" value="${max !== undefined && max !== null ? max : ''}" placeholder="${langData['no_upper_limit'] || 'No upper limit'}"></td>
        <td><input type="number" step="0.0001" min="0" max="100" class="form-control form-control-sm bracket-rate" value="${rate !== undefined ? rate : ''}"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-bracket"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`);
    $('#bracketBody').append($row);
    if (idx === 0 && min === undefined) {
        $row.find('.bracket-min').val(0);
    }
}
function collectBrackets() {
    const brackets = [];
    $('#bracketBody tr').each(function () {
        const min = $(this).find('.bracket-min').val();
        const max = $(this).find('.bracket-max').val();
        const rate = $(this).find('.bracket-rate').val();
        brackets.push({
            min_amount: min,
            max_amount: max === '' ? null : max,
            rate: rate
        });
    });
    return brackets;
}
function resetRateVersionForm() {
    $('#rateVersionForm')[0].reset();
    $('#rate_id').val('');
    $('.is-invalid').removeClass('is-invalid');
    $('#bracketBody').empty();
    if (currentItemCtx) {
        $('#rate_statutory_item_id').val(currentItemCtx.id);
        applyCalcMethodFieldsTs(currentItemCtx.calc_method);
        if (currentItemCtx.calc_method === 'progressive_bracket') {
            addBracketRow(0, '', '');
        }
    }
}
function populateRateVersionForm(row) {
    $('#rate_id').val(row.id);
    $('#rate_statutory_item_id').val(row.statutory_item_id);
    $('#rate_effective_date').val(toDisplayDateTs(row.effective_date));
    $('#rate_end_date').val(toDisplayDateTs(row.end_date));
    $('#rate_employee_rate').val(row.employee_rate !== null ? row.employee_rate : '');
    $('#rate_employer_rate').val(row.employer_rate !== null ? row.employer_rate : '');
    $('#rate_employee_amount').val(row.employee_amount !== null ? row.employee_amount : '');
    $('#rate_employer_amount').val(row.employer_amount !== null ? row.employer_amount : '');
    $('#rate_min_base_amount').val(row.min_base_amount !== null ? row.min_base_amount : '');
    $('#rate_max_base_amount').val(row.max_base_amount !== null ? row.max_base_amount : '');
    $('#rate_max_employee_contribution').val(row.max_employee_contribution !== null ? row.max_employee_contribution : '');
    $('#rate_max_employer_contribution').val(row.max_employer_contribution !== null ? row.max_employer_contribution : '');
    $('#rate_remark').val(row.remark || '');
    $('#rate_formula_config').val(row.formula_config ? JSON.stringify(JSON.parse(row.formula_config), null, 2) : '');
    $('#bracketBody').empty();
    if (currentItemCtx && currentItemCtx.calc_method === 'progressive_bracket') {
        (row.brackets || []).forEach(b => addBracketRow(b.min_amount, b.max_amount, b.rate));
        if (!row.brackets || row.brackets.length === 0) addBracketRow(0, '', '');
    }
    applyCalcMethodFieldsTs(currentItemCtx ? currentItemCtx.calc_method : '');
}
function validateRateVersionForm() {
    let firstInvalid = null;
    $('#rateVersionModal .required').each(function () {
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
function collectRateVersionFormData() {
    const data = {
        id: $('#rate_id').val() || undefined,
        statutory_item_id: $('#rate_statutory_item_id').val(),
        effective_date: toIsoDateTs($('#rate_effective_date').val()),
        end_date: $('#rate_end_date').val() ? toIsoDateTs($('#rate_end_date').val()) : null,
        min_base_amount: $('#rate_min_base_amount').val(),
        max_base_amount: $('#rate_max_base_amount').val(),
        max_employee_contribution: $('#rate_max_employee_contribution').val(),
        max_employer_contribution: $('#rate_max_employer_contribution').val(),
        remark: $('#rate_remark').val().trim()
    };
    if (currentItemCtx && currentItemCtx.calc_method === 'flat_rate') {
        data.employee_rate = $('#rate_employee_rate').val();
        data.employer_rate = $('#rate_employer_rate').val();
    } else if (currentItemCtx && currentItemCtx.calc_method === 'fixed_amount') {
        data.employee_amount = $('#rate_employee_amount').val();
        data.employer_amount = $('#rate_employer_amount').val();
    } else if (currentItemCtx && currentItemCtx.calc_method === 'progressive_bracket') {
        data.brackets = collectBrackets();
    } else if (currentItemCtx && currentItemCtx.calc_method === 'formula') {
        data.formula_config = $('#rate_formula_config').val();
    }
    return data;
}

/* ---------- UI bindings ---------- */
function initStatutoryItemUI() {
    $(document).on('change', '#filter_country_code', function () {
        if (tb_statutory_item) tb_statutory_item.ajax.reload(null, true);
    });
    $(document).on('click', '.btn-add-item', function () {
        resetItemForm();
        new bootstrap.Modal(document.getElementById('statutoryItemModal')).show();
    });
    $(document).on('click', '.btn-edit-item', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    resetItemForm();
                    populateItemForm(res.data);
                    new bootstrap.Modal(document.getElementById('statutoryItemModal')).show();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
            }
        });
    });
    $(document).on('click', '.btn-manage-rate', function () {
        const id = $(this).data('id');
        const rowData = tb_statutory_item.rows().data().toArray().find(r => Number(r.id) === Number(id));
        if (rowData) openRateHistoryModal(rowData);
    });
    $(document).on('submit', '#statutoryItemForm', function (e) {
        e.preventDefault();
        const invalidEl = validateItemForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const payload = collectItemFormData();
        const $btn = $('#statutoryItemForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('statutoryItemModal')).hide();
                    if (tb_statutory_item) tb_statutory_item.ajax.reload(null, false);
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
    $(document).on('click', '.btn-delete-item', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/statutory-item.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        if (tb_statutory_item) tb_statutory_item.ajax.reload(null, false);
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

    /* Rate history + version */
    $(document).on('click', '#btnAddRateVersion', function () {
        resetRateVersionForm();
        bootstrap.Modal.getInstance(document.getElementById('rateHistoryModal')).hide();
        new bootstrap.Modal(document.getElementById('rateVersionModal')).show();
    });
    $(document).on('click', '.btn-edit-rate', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.rate-history.get`,
            method: 'GET',
            data: { id: id },
            dataType: 'json',
            success: function (res) {
                if (res.status) {
                    resetRateVersionForm();
                    populateRateVersionForm(res.data);
                    bootstrap.Modal.getInstance(document.getElementById('rateHistoryModal')).hide();
                    new bootstrap.Modal(document.getElementById('rateVersionModal')).show();
                } else {
                    showWarning(res.message || langData['save_failed'] || 'Failed to load data.');
                }
            },
            error: function () {
                showWarning(langData['save_failed'] || 'An error occurred while loading the data.');
            }
        });
    });
    $(document).on('click', '.btn-delete-rate', function () {
        const id = $(this).data('id');
        const title = langData['confirm_delete_title'] || 'Confirm Delete';
        const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/statutory-item.rate-history.delete`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ id: id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['delete_success'] || 'Deleted successfully.');
                        if (tb_rate_history) tb_rate_history.ajax.reload(null, false);
                        if (tb_statutory_item) tb_statutory_item.ajax.reload(null, false);
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
    $(document).on('click', '#btnBackToRateHistory', function () {
        bootstrap.Modal.getInstance(document.getElementById('rateVersionModal')).hide();
        new bootstrap.Modal(document.getElementById('rateHistoryModal')).show();
    });
    $(document).on('click', '#btnAddBracketRow', function () {
        const lastMax = $('#bracketBody tr:last .bracket-max').val();
        addBracketRow(lastMax !== '' && lastMax !== undefined ? (parseFloat(lastMax) + 0.01).toFixed(2) : '', '', '');
    });
    $(document).on('click', '.btn-remove-bracket', function () {
        $(this).closest('tr').remove();
        recalcBracketRowsTs();
    });
    $(document).on('change', '.bracket-max', function () {
        recalcBracketRowsTs();
    });
    $(document).on('submit', '#rateVersionForm', function (e) {
        e.preventDefault();
        const invalidEl = validateRateVersionForm();
        if (invalidEl) {
            showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
            return;
        }
        const payload = collectRateVersionFormData();
        const $btn = $('#rateVersionForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/statutory-item.rate-history.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('rateVersionModal')).hide();
                    new bootstrap.Modal(document.getElementById('rateHistoryModal')).show();
                    if (tb_rate_history) tb_rate_history.ajax.reload(null, false);
                    if (tb_statutory_item) tb_statutory_item.ajax.reload(null, false);
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
}

/* ---------- Company Statutory Settings (Part 2) ---------- */
function csEffectiveStatusBadgeTs(status) {
    const isActive = status === 'active';
    const cls = isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
    const text = isActive ? (langData['active'] || 'Active') : (langData['inactive'] || 'Inactive');
    return `<span class="badge ${cls}">${text}</span>`;
}
function csHasOverrideTs(row) {
    return row.employee_rate_override !== null || row.employer_rate_override !== null
        || row.employee_amount_override !== null || row.employer_amount_override !== null;
}
function csRateInUseCellTs(row) {
    const hasOverride = csHasOverrideTs(row);
    const badge = hasOverride
        ? `<span class="badge bg-warning-subtle text-warning border me-1">${langData['custom_rate'] || 'Custom Rate'}</span>`
        : `<span class="badge bg-light text-dark border me-1">${langData['using_default'] || 'Using Default'}</span>`;
    let valueText = '';
    if (row.calc_method === 'flat_rate') {
        const empRate = hasOverride ? row.employee_rate_override : row.master_employee_rate;
        const erRate = hasOverride ? row.employer_rate_override : row.master_employer_rate;
        const parts = [];
        if (row.is_employee_applicable == 1 && empRate !== null) parts.push(`${Number(empRate)}%`);
        if (row.is_employer_applicable == 1 && erRate !== null) parts.push(`${Number(erRate)}%`);
        valueText = parts.join(' / ');
    } else if (row.calc_method === 'fixed_amount') {
        const empAmt = hasOverride ? row.employee_amount_override : row.master_employee_amount;
        const erAmt = hasOverride ? row.employer_amount_override : row.master_employer_amount;
        const parts = [];
        if (row.is_employee_applicable == 1 && empAmt !== null) parts.push(fmtNumTs(empAmt));
        if (row.is_employer_applicable == 1 && erAmt !== null) parts.push(fmtNumTs(erAmt));
        valueText = parts.join(' / ');
    } else if (row.calc_method === 'progressive_bracket') {
        valueText = langData['tax_brackets'] || 'Tax Brackets';
    } else {
        valueText = langData['calc_method_formula'] || 'Formula-based';
    }
    return `<div>${badge}</div><div class="small mt-1">${valueText}</div>`;
}
function csAdjustableCellTs(row) {
    const adjustable = Number(row.is_company_rate_editable) === 1 && ['flat_rate', 'fixed_amount'].includes(row.calc_method);
    return adjustable ? (langData['yes'] || 'Yes') : `<span class="text-muted">${langData['no'] || 'No'}</span>`;
}
function csActionButtonsTs(row) {
    return `<div class="btn-group border rounded-3 bg-white">
        <button type="button" class="btn btn-link text-warning btn-edit-cs" data-id="${row.statutory_item_id}"><i class="fas fa-edit"></i></button>
    </div>`;
}
function initCompanySettingTable() {
    if ($.fn.DataTable.isDataTable('#tb_company_setting')) {
        $('#tb_company_setting').DataTable().ajax.reload(null, false);
        return;
    }
    tb_company_setting = $('#tb_company_setting').DataTable({
        responsive: true,
        searching: false,
        paging: false,
        info: false,
        ajax: {
            url: `${BASE_URL}/api/company-statutory-setting.list`,
            dataSrc: 'data'
        },
        columns: [
            { data: 'code', render: d => `<code class="fw-bold text-dark">${escapeHtmlTs(d)}</code>` },
            { data: null, render: (d, t, row) => escapeHtmlTs(itemNameTs(row)) },
            { data: 'category', render: d => categoryBadgeTs(d) },
            { data: null, render: (d, t, row) => csRateInUseCellTs(row) },
            { data: 'effective_status', render: d => csEffectiveStatusBadgeTs(d) },
            { data: null, render: (d, t, row) => csAdjustableCellTs(row) },
            { data: null, orderable: false, className: 'text-center', render: (d, t, row) => csActionButtonsTs(row) }
        ],
        language: getTableLang(),
        drawCallback: function () { getTableLang(); }
    });
}
function masterRateDisplayTs(row) {
    if (row.calc_method === 'flat_rate') {
        const parts = [];
        if (row.is_employee_applicable == 1 && row.master_employee_rate !== null) parts.push(`${langData['modal_employee_rate'] || 'Employee'}: ${Number(row.master_employee_rate)}%`);
        if (row.is_employer_applicable == 1 && row.master_employer_rate !== null) parts.push(`${langData['modal_employer_rate'] || 'Employer'}: ${Number(row.master_employer_rate)}%`);
        return parts.join(', ');
    }
    if (row.calc_method === 'fixed_amount') {
        const parts = [];
        if (row.is_employee_applicable == 1 && row.master_employee_amount !== null) parts.push(`${langData['modal_employee_amount'] || 'Employee'}: ${fmtNumTs(row.master_employee_amount)}`);
        if (row.is_employer_applicable == 1 && row.master_employer_amount !== null) parts.push(`${langData['modal_employer_amount'] || 'Employer'}: ${fmtNumTs(row.master_employer_amount)}`);
        return parts.join(', ');
    }
    return '';
}
function openCompanySettingModal(row) {
    const adjustable = Number(row.is_company_rate_editable) === 1 && ['flat_rate', 'fixed_amount'].includes(row.calc_method);
    currentCsItem = {
        id: row.statutory_item_id,
        calc_method: row.calc_method,
        adjustable: adjustable
    };
    $('#companySettingForm')[0].reset();
    $('.is-invalid').removeClass('is-invalid');
    $('#cs_statutory_item_id').val(row.statutory_item_id);
    $('#companySettingItemName').text(`(${row.code} - ${itemNameTs(row)})`);
    $('#cs_is_active').prop('checked', row.effective_status === 'active');
    $('#cs_rate_fields').toggleClass('d-none', !adjustable || row.calc_method !== 'flat_rate');
    $('#cs_amount_fields').toggleClass('d-none', !adjustable || row.calc_method !== 'fixed_amount');
    $('#cs_employee_rate_override').val(row.employee_rate_override !== null ? row.employee_rate_override : '');
    $('#cs_employer_rate_override').val(row.employer_rate_override !== null ? row.employer_rate_override : '');
    $('#cs_employee_amount_override').val(row.employee_amount_override !== null ? row.employee_amount_override : '');
    $('#cs_employer_amount_override').val(row.employer_amount_override !== null ? row.employer_amount_override : '');
    $('#cs_remark').val(row.remark || '');
    if (adjustable) {
        const tpl = langData['company_setting_rate_hint'] || "Master default rate: {value}. Leave the fields below blank to use this default.";
        $('#cs_master_default_hint').text(tpl.replace('{value}', masterRateDisplayTs(row) || '-'));
    } else {
        $('#cs_master_default_hint').text(langData['not_adjustable_hint'] || "This item's rate is fixed by law and cannot be adjusted per company. You may only enable or disable it.");
    }
    new bootstrap.Modal(document.getElementById('companySettingModal')).show();
}
function collectCompanySettingFormData() {
    return {
        statutory_item_id: $('#cs_statutory_item_id').val(),
        is_active: $('#cs_is_active').is(':checked'),
        employee_rate_override: $('#cs_employee_rate_override').val(),
        employer_rate_override: $('#cs_employer_rate_override').val(),
        employee_amount_override: $('#cs_employee_amount_override').val(),
        employer_amount_override: $('#cs_employer_amount_override').val(),
        remark: $('#cs_remark').val().trim()
    };
}
function initCompanySettingUI() {
    $(document).on('click', '.btn-edit-cs', function () {
        const itemId = $(this).data('id');
        const rowData = tb_company_setting.rows().data().toArray().find(r => Number(r.statutory_item_id) === Number(itemId));
        if (rowData) openCompanySettingModal(rowData);
    });
    $(document).on('submit', '#companySettingForm', function (e) {
        e.preventDefault();
        const payload = collectCompanySettingFormData();
        const $btn = $('#companySettingForm button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
        $.ajax({
            url: `${BASE_URL}/api/company-statutory-setting.save`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(payload),
            success: function (res) {
                $btn.prop('disabled', false).html(originalHtml);
                if (typeof updateText === 'function') updateText($btn[0]);
                if (res.status) {
                    showSuccess(langData['save_success'] || 'Saved successfully.');
                    bootstrap.Modal.getInstance(document.getElementById('companySettingModal')).hide();
                    if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
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
    $(document).on('click', '#btnResetCompanySetting', function () {
        if (!currentCsItem) return;
        const title = langData['reset_confirm_title'] || 'Reset to system default?';
        const message = langData['reset_confirm_message'] || "This will remove your company's custom rate/enable setting for this item and fall back to the system default.";
        showConfirm(title, message, function () {
            $.ajax({
                url: `${BASE_URL}/api/company-statutory-setting.reset`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify({ statutory_item_id: currentCsItem.id }),
                success: function (res) {
                    if (res.status) {
                        showSuccess(langData['reset_success'] || 'Reset to system default successfully.');
                        bootstrap.Modal.getInstance(document.getElementById('companySettingModal')).hide();
                        if (tb_company_setting) tb_company_setting.ajax.reload(null, false);
                    } else {
                        showWarning(res.message || langData['save_failed'] || 'Failed to reset data.');
                    }
                },
                error: function () {
                    showWarning(langData['save_failed'] || 'An error occurred.');
                }
            });
        });
    });
}

$(document).ready(function () {
    initStatutoryItemTable();
    initStatutoryItemUI();
    initCompanySettingUI();
    if (typeof initSelect2 === 'function') {
        initSelect2('#filter_country_code', { mode: 'ajax' });
        initSelect2('#item_country_code', { mode: 'ajax' });
        initSelect2('#item_category', { mode: 'static' });
        initSelect2('#item_calc_method', { mode: 'static' });
        initSelect2('#item_calc_base', { mode: 'static' });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#rate_effective_date');
        initDatepicker('#rate_end_date');
    }
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const tabId = $(e.target).attr('id');
        if (tabId === 'company-setting-tab') {
            initCompanySettingTable();
        }
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});
