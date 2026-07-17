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
        COUNTRY_MASTER_CONFIG = {}; 
        if (typeof callback === 'function') callback();
    });
}
let activePage = ''; 
function initPage(page) {
    activePage = page; 
    switch(page) {
        case 'p1': 
            loadCountryConfig(function() {
                if (activePage !== 'p1') {
                    return; 
                }
                initProfilePane(); 
            });
            break; 
        case 'p2': 
            initBankPane(); 
            break;
        case 'p3': 
            initStructurePane(); 
            break;
    }
}
function initProfilePane() {
    const html = $('#tmpl-profile-pane').html();
    $pane.html(html);
    const defaultCountry = "TH";
    $('#registered_country').val(defaultCountry);
    renderCountrySpecificForm(defaultCountry);
    updateText($pane[0]);
    initSelect2Remote('.select2-remote');
    initCompanyData();
}
function renderCountrySpecificForm(countryCode) {
    const $container = $('#dynamic_statutory_fields_container');
    if (!$container.length) return;
    $container.empty();
    const config = (COUNTRY_MASTER_CONFIG && COUNTRY_MASTER_CONFIG[countryCode]) ? COUNTRY_MASTER_CONFIG[countryCode] : null;
    if (!config) {
        $('#tax_id_label').attr('data-i18n', 'tax.default_label').text('Tax ID / EIN');
        if (typeof updateText === 'function') updateText('#tax_id_label');
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
        updateText('#tax_id_label');
        updateText($container[0]);
    }
}
let currentCompanyAddresses = { th: '', en: '' };

function initCompanyData() {
    $.ajax({
        url: `${BASE_URL}/api/company.get`,
        method: 'POST',
        dataType: 'json',
        success: function(response) {
            if (response.status && response.data) {
                const data = response.data;
                currentCompanyAddresses.th = data.address_display_th || '';
                currentCompanyAddresses.en = data.address_display_en || '';
                $('#search_address').val(currentCompanyAddresses[currentLang]);
                $('#master_address_id').val(data.master_address_id || '');
                const countryCode = data.registered_country || "TH";
                const $countrySelect = $('#registered_country');
                if ($countrySelect.hasClass("select2-hidden-accessible")) {
                    $countrySelect.empty();
                    const countryText = (currentLang === 'th') ? data.country_data.text_th : data.country_data.text_en;
                    const newOption = new Option(countryText, countryCode, true, true);
                    $(newOption).data('data', {
                        id: countryCode,
                        text: countryText,
                        text_th: data.country_data.text_th,
                        text_en: data.country_data.text_en
                    });    
                    $countrySelect.append(newOption).trigger('change.select2');
                } else {
                    $countrySelect.val(countryCode);
                }
                renderCountrySpecificForm(countryCode);
                $('input[name="global_tax_id"]').val(data.global_tax_id || '');
                $('input[name="company_legal_name"]').val(data.company_legal_name || '');
                $('input[name="local_name"]').val(data.local_name || '');
                $('input[name="address_line_1"]').val(data.address_line_1 || '');
                $('input[name="address_line_2"]').val(data.address_line_2 || '');
                $('input[name="authorized_signatory_name"]').val(data.authorized_signatory_name || '');
                if (data.statutory_data && typeof data.statutory_data === 'object') {
                    Object.keys(data.statutory_data).forEach(key => {
                        const $field = $(`[name="${key}"]`);
                        if ($field.length) {
                            $field.val(data.statutory_data[key]);
                        }
                    });
                }

                if (typeof updateText === 'function') {
                    updateText($pane[0]);
                }
            } else {
                const defaultCountry = "TH";
                $('#registered_country').val(defaultCountry).trigger('change');
                renderCountrySpecificForm(defaultCountry);
                if (typeof updateText === 'function') {
                    updateText($pane[0]);
                }
            }
        },
        error: function(xhr, status, error) {
            console.error("Failed to load company profile data:", error);
        }
    });
}
$(document).on('click', '.save-company-profile', function () {
    let errors = [];
    const $btn = $(this);
    $pane.find('.required').each(function () {
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
    $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...');
    let formData = {
        registered_country: $('#registered_country').val(),
        global_tax_id: $('input[name="global_tax_id"]').val()?.trim() || '',
        company_legal_name: $('input[name="company_legal_name"]').val()?.trim() || '',
        local_name: $('input[name="local_name"]').val()?.trim() || '',
        address_line_1: $('input[name="address_line_1"]').val()?.trim() || '',
        address_line_2: $('input[name="address_line_2"]').val()?.trim() || '',
        master_address_id: $('input[name="master_address_id"]').val() || null,
        authorized_signatory_name: $('input[name="authorized_signatory_name"]').val()?.trim() || '',
        statutory_data: {}
    };
    $('#dynamic_statutory_fields_container input').each(function() {
        const name = $(this).attr('name');
        if (name) {
            formData.statutory_data[name] = $(this).val()?.trim() || '';
        }
    });
    $.ajax({
        url: `${BASE_URL}/api/company.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(formData),
        success: function(res) {
            $btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> <span>Save</span>');
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                if (typeof showSuccess === 'function') {
                    showSuccess(res.message || 'Company profile saved successfully!');
                } else {
                    alert('Company profile saved successfully!');
                }
                initCompanyData();
            } else {
                if (typeof showWarning === 'function') {
                    showWarning(res.message || 'Failed to save company profile.');
                } else {
                    alert(res.message || 'Failed to save company profile.');
                }
            }
        },
        error: function(xhr, status, error) {
            $btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> <span>Save</span>');
            if (typeof updateText === 'function') updateText($btn[0]);
            console.error("Save error:", error);
            if (typeof showWarning === 'function') {
                showWarning('An error occurred while saving the data.');
            } else {
                alert('An error occurred while saving the data.');
            }
        }
    });
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
        }
    );
});
function initBankPane() { 
    $pane.html($('#tmpl-bank-pane').html()); 
}
function initStructurePane() { 
    $pane.html($('#tmpl-structure-pane').html()); 
}