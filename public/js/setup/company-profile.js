let COUNTRY_MASTER_CONFIG = null;
const $pane = $('#setup-pane');
$(document).ready(function () {
    loadCountryConfig(function() {
        initPage('p1');
    });
});
$(document).on('click', '.nav-link', function () {
    const page = $(this).data("page");
    initPage(page);
});
$(document).on('change', '#registered_country', function () {
    renderCountrySpecificForm($(this).val());
});
function loadCountryConfig(callback) {
    if (COUNTRY_MASTER_CONFIG !== null) {
        if (typeof callback === 'function') callback();
        return;
    }
    $.getJSON(`${BASE_URL}/public/json/country-config.json`, function(data) {
        COUNTRY_MASTER_CONFIG = data;
        if (typeof callback === 'function') callback();
    }).fail(function() {
        console.error("ไม่สามารถโหลดไฟล์ Country Config ได้");
        COUNTRY_MASTER_CONFIG = {}; 
        if (typeof callback === 'function') callback();
    });
}
function initPage(page) {
    switch(page) {
        case 'p1': 
            loadCountryConfig(function() {
                initProfilePane(); 
            });
            break;
        case 'p2': initBankPane(); break;
        case 'p3': initStructurePane(); break;
    }
}
function initProfilePane() {
    const html = $('#tmpl-profile-pane').html();
    $pane.html(html);
    const defaultCountry = "TH";
    $('#registered_country').val(defaultCountry);
    renderCountrySpecificForm(defaultCountry);
    updateText($pane[0]);
}
function renderCountrySpecificForm(countryCode) {
    const $container = $('#dynamic_statutory_fields_container').empty();
    const config = (COUNTRY_MASTER_CONFIG && COUNTRY_MASTER_CONFIG[countryCode]) ? COUNTRY_MASTER_CONFIG[countryCode] : null;
    if (!config) {
        $('#tax_id_label').attr('data-i18n', 'tax.default_label').text('Tax ID / EIN');
        if (typeof updateText === 'function') updateText(document);
        return;
    }
    $('#tax_id_label').attr('data-i18n', config.taxLabelKey).text(config.taxDefaultText);
    const $baseCurrency = $('#base_currency');
    if ($baseCurrency.length) $baseCurrency.val(config.currency).trigger('change');
    const $companyTimezone = $('#company_timezone');
    if ($companyTimezone.length) $companyTimezone.val(config.timezone).trigger('change');
    let fieldsHtml = '';
    if (config.fields && Array.isArray(config.fields)) {
        config.fields.forEach(field => {
            const requiredAttr = field.required ? 'required' : '';
            const redAsterisk = field.required ? '<span class="text-danger">*</span>' : '';
            fieldsHtml += `
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="${field.labelKey}">${field.labelDefault}</span> ${redAsterisk}
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control ${requiredAttr}" name="${field.name}">
                </div>
            `;
        });
    }
    $container.append(fieldsHtml);
    if (typeof updateText === 'function') {
        updateText($container[0]);
    }
}
$(document).on('click', '.save-company-profile', function () {
    let errors = [];
    $('.required').each(function () {
        let value = $(this).val()?.trim() || '';
        if (!value) {
            $(this).addClass('is-invalid');
            errors.push(this.name || this.id);
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    if (errors.length) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        $('.is-invalid').first().focus();
        return;
    }
});
$(document).on('click', '.cancel-company-profile', function () {
    const title = langData['confirm_cancel_title'] || 'Confirm Cancel';
    const message = langData['confirm_cancel_message'] || 'Are you sure you want to cancel this operation?';
    showConfirm(
        title, 
        message, 
        function() {
            initPage('p1'); 
        }, 
        function() {
            console.log('User canceled the cancellation.');
        }
    );
});
function initBankPane() { 
    $pane.html($('#tmpl-bank-pane').html()); 
}
function initStructurePane() { 
    $pane.html($('#tmpl-structure-pane').html()); 
}