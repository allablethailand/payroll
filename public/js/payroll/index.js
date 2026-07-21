let tb_payroll_run;

function toIsoDatePr(displayVal) {
    if (!displayVal) return '';
    const parts = String(displayVal).split('/');
    if (parts.length !== 3) return displayVal;
    const [dd, mm, yyyy] = parts;
    return `${yyyy}-${mm.padStart(2, '0')}-${dd.padStart(2, '0')}`;
}
function toDisplayDatePr(isoVal) {
    if (!isoVal) return '';
    const parts = String(isoVal).split('-');
    if (parts.length !== 3) return isoVal;
    const [yyyy, mm, dd] = parts;
    return `${dd}/${mm}/${yyyy}`;
}
function escapeHtmlPr(str) {
    return $('<div>').text(str === null || str === undefined ? '' : str).html();
}
function fmtNumPr(n) {
    return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function stateBadgePr(state) {
    const map = {
        draft: 'bg-secondary-subtle text-secondary',
        pending_approval: 'bg-warning-subtle text-warning',
        approved: 'bg-info-subtle text-info',
        paid: 'bg-success-subtle text-success',
        locked: 'bg-dark-subtle text-dark',
        rejected: 'bg-danger-subtle text-danger',
    };
    const cls = map[state] || 'bg-light text-dark';
    const text = langData['state_' + state] || state;
    return `<span class="badge ${cls}">${text}</span>`;
}
function employeeNamePr(row) {
    return (currentLang === 'th' ? row.created_by_name_th : row.created_by_name_en) || row.created_by_name_th || row.created_by_name_en || '-';
}

function initPayrollRunTable() {
    if ($.fn.DataTable.isDataTable('#tb_payroll_run')) {
        $('#tb_payroll_run').DataTable().ajax.reload(null, false);
        return;
    }
    tb_payroll_run = $('#tb_payroll_run').DataTable({
        responsive: true,
        order: [[1, 'desc']],
        ajax: {
            url: `${BASE_URL}/api/payroll-run.list`,
            dataSrc: 'data',
            data: function (d) {
                d.state = $('#filter_state').val() || '';
                d.date_from = toIsoDatePr($('#filter_date_from').val());
                d.date_to = toIsoDatePr($('#filter_date_to').val());
            }
        },
        columns: [
            { data: 'run_name', render: d => `<strong class="text-dark">${escapeHtmlPr(d)}</strong>` },
            { data: null, render: (d, t, row) => `${toDisplayDatePr(row.period_start_date)} - ${toDisplayDatePr(row.period_end_date)}` },
            { data: 'state', render: d => stateBadgePr(d) },
            { data: 'employee_count', className: 'text-end' },
            { data: 'total_net_amount', className: 'text-end', render: d => fmtNumPr(d) },
            { data: null, render: (d, t, row) => escapeHtmlPr(employeeNamePr(row)) },
            { data: 'updated_at', render: d => d ? toDisplayDatePr(d.substring(0, 10)) + ' ' + d.substring(11, 16) : '-' },
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            const $wrapper = $(this.api().table().container());
            const $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.btn-add-run').length === 0) {
                $searchDiv.append(`
                    <button type="button" class="btn btn-primary ms-1 btn-add-run">
                        <i class="fa-solid fa-plus me-1"></i><span data-i18n="payroll_run">${langData['payroll_run'] || 'Payroll Run'}</span>
                    </button>
                `);
            }
        },
        drawCallback: function () { getTableLang(); }
    });
    $('#tb_payroll_run tbody').off('click', 'tr').on('click', 'tr', function (e) {
        if ($(e.target).closest('.btn-add-run').length) return;
        const rowData = tb_payroll_run.row(this).data();
        if (rowData && rowData.id) {
            window.location.href = `${BASE_URL}/payroll-process/${rowData.id}`;
        }
    });
}

function resetRunForm() {
    $('#payrollRunForm')[0].reset();
    $('.is-invalid').removeClass('is-invalid');
    $('#run_cycle_id').val('').trigger('change');
}
function validateRunForm() {
    let firstInvalid = null;
    $('#payrollRunModal .required').each(function () {
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
function collectRunFormData() {
    return {
        cycle_id: $('#run_cycle_id').val(),
        run_name: $('#run_name').val().trim(),
        period_start_date: toIsoDatePr($('#run_period_start').val()),
        period_end_date: toIsoDatePr($('#run_period_end').val()),
        payment_date: toIsoDatePr($('#run_payment_date').val()),
        notes: $('#run_notes').val().trim(),
    };
}

$(document).on('change', '#filter_state, #filter_date_from, #filter_date_to', function () {
    if (tb_payroll_run) tb_payroll_run.ajax.reload(null, true);
});
$(document).on('click', '.btn-add-run', function () {
    resetRunForm();
    new bootstrap.Modal(document.getElementById('payrollRunModal')).show();
});
$(document).on('submit', '#payrollRunForm', function (e) {
    e.preventDefault();
    const invalidEl = validateRunForm();
    if (invalidEl) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        return;
    }
    const payload = collectRunFormData();
    const $btn = $('#payrollRunForm button[type="submit"]');
    const originalHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>Saving...</span>');
    $.ajax({
        url: `${BASE_URL}/api/payroll-run.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html(originalHtml);
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                showSuccess(langData['save_success'] || 'Saved successfully.');
                bootstrap.Modal.getInstance(document.getElementById('payrollRunModal')).hide();
                if (tb_payroll_run) tb_payroll_run.ajax.reload(null, false);
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

$(document).ready(function () {
    initPayrollRunTable();
    if (typeof initSelect2 === 'function') {
        initSelect2('#filter_state', { mode: 'static', allowClear: true });
        initSelect2('#run_cycle_id', { mode: 'ajax' });
    }
    if (typeof initDatepicker === 'function') {
        initDatepicker('#filter_date_from');
        initDatepicker('#filter_date_to');
        initDatepicker('#run_period_start');
        initDatepicker('#run_period_end');
        initDatepicker('#run_payment_date');
    }
});
