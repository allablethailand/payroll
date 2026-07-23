/**
 * Payslip Distribution settings — Document & Approval > "Payslip Distribution" tab.
 * Singleton settings form per company (not a DataTable/modal like the other tabs here) --
 * loaded on tab show, upserted on save. Channel picker is a Select2 multi-select where
 * *selection order* is the fallback attempt order (first picked = primary channel).
 */
function toggleDistAutoSettingsBlock() {
    const mode = $('#pd_mode').val();
    $('#pd_auto_settings_block').toggle(mode === 'auto' || mode === 'both');
}

function populateDistributionForm(data) {
    $('#pd_mode').val(data.distribution_mode || 'request_only').trigger('change');
    $('#pd_is_active').prop('checked', parseInt(data.is_active) !== 0);
    $('#pd_delay_hours').val(data.send_delay_hours || 0);

    const $channels = $('#pd_channels');
    $channels.empty();
    (data.channels || []).forEach(c => {
        const label = (currentLang === 'th' ? c.name_th : c.name_en) || c.name_th || c.name_en || c.code;
        $channels.append(new Option(label, c.code, true, true));
    });
    $channels.trigger('change');

    const $depts = $('#pd_scope_departments');
    $depts.empty();
    (data.scope_departments || []).forEach(d => {
        const label = (currentLang === 'th' ? d.name_th : d.name_en) || d.name_th || d.name_en;
        $depts.append(new Option(label, d.id, true, true));
    });
    $depts.trigger('change');

    const statuses = (data.scope_employment_statuses || '').split(',').filter(Boolean);
    $('#pd_scope_statuses').val(statuses).trigger('change');

    toggleDistAutoSettingsBlock();
}

function loadDistributionSettings() {
    $.ajax({
        url: `${BASE_URL}/api/payslip-distribution.settings-get`,
        method: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                populateDistributionForm(res.data);
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred while loading the data.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while loading the data.'); }
    });
}

$(document).on('change', '#pd_mode', function () {
    toggleDistAutoSettingsBlock();
});

$(document).on('submit', '#payslipDistributionForm', function (e) {
    e.preventDefault();
    const mode = $('#pd_mode').val();
    const channels = $('#pd_channels').val() || [];
    if ((mode === 'auto' || mode === 'both') && channels.length === 0) {
        showWarning(langData['select_channel_first'] || 'Select at least one delivery channel for auto-send.');
        return;
    }
    const payload = {
        distribution_mode: mode,
        is_active: $('#pd_is_active').is(':checked') ? 1 : 0,
        send_delay_hours: parseInt($('#pd_delay_hours').val() || '0', 10),
        channels: channels,
        scope_department_ids: ($('#pd_scope_departments').val() || []).join(','),
        scope_employment_statuses: ($('#pd_scope_statuses').val() || []).join(','),
    };
    $.ajax({
        url: `${BASE_URL}/api/payslip-distribution.settings-save`,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        dataType: 'json',
        success: function (res) {
            if (res.status) {
                showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                loadDistributionSettings();
            } else {
                showWarning(res.message || langData['save_failed'] || 'An error occurred.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'An error occurred while saving.'); }
    });
});

$(document).ready(function () {
    $('#payslipDistributionTabBtn').on('shown.bs.tab', function () {
        loadDistributionSettings();
    });
});
