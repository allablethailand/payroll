let COUNTRY_MASTER_CONFIG = null;
const $pane = $('#setup-pane');
$(document).ready(function () {
    loadCountryConfig(function () {
        initPage('p1');
    });
});
$(document).on('click', '.setup-tabs .setup-menu', function () {
    const page = $(this).data('page');
    $('.setup-tabs .setup-menu').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');

    initPage(page);
});
$(document).on('change', '#cp_logo_file', function () {
    const file = this.files && this.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    $.ajax({
        url: `${BASE_URL}/api/company.upload-logo`,
        method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
        success: function (res) {
            if (res.status) {
                $('input[name="logo_path"]').val(res.logo_path);
                $('#cpLogoPreview img').attr('src', `${BASE_URL}/${res.logo_path}`);
                $('#cpLogoPreview').removeClass('d-none');
            } else {
                showWarning(res.message || langData['save_failed'] || 'Upload failed.');
            }
        },
        error: function () { showWarning(langData['save_failed'] || 'Upload failed.'); }
    });
    $(this).val('');
});
$(document).on('change', '#registered_country', function () {
    renderCountrySpecificForm($(this).val());
});
function loadCountryConfig(callback) {
    if (COUNTRY_MASTER_CONFIG !== null) {
        if (typeof callback === 'function') callback();
        return;
    }
    $.getJSON(`${BASE_URL}/public/json/country-config.json`, function (data) {
        COUNTRY_MASTER_CONFIG = data;
        if (typeof callback === 'function') callback();
    }).fail(function () {
        COUNTRY_MASTER_CONFIG = {};
        if (typeof callback === 'function') callback();
    });
}
let activePage = '';
function initPage(page) {
    activePage = page;
    switch (page) {
        case 'p1':
            loadCountryConfig(function () {
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
    const defaultCountry = 'TH';
    $('#registered_country').val(defaultCountry);
    renderCountrySpecificForm(defaultCountry);
    updateText($pane[0]);
    initSelect2Remote('.select2-remote');
    initCompanyData();
}
function renderCountrySpecificForm(countryCode) {
    const $container = $('#dynamic_statutory_fields_container');
    if (!$container.length) return;
    const savedValues = {};
    $container.find('input, select, textarea').each(function () {
        const name = $(this).attr('name');
        if (name) savedValues[name] = $(this).val();
    });
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
            const savedVal = savedValues[field.name] !== undefined ? savedValues[field.name] : '';
            fieldsHtml += `
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="${field.labelKey}">${field.labelDefault}</span> ${requiredMark(field.required)}
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control ${requiredAttr}" name="${field.name}" value="${savedVal}">
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
        success: function (response) {
            if (response.status && response.data) {
                const data = response.data;
                currentCompanyAddresses.th = data.address_display_th || '';
                currentCompanyAddresses.en = data.address_display_en || '';
                $('#search_address').val(currentCompanyAddresses[currentLang]);
                $('#master_address_id').val(data.master_address_id || '');
                const countryCode = data.registered_country || 'TH';
                const $countrySelect = $('#registered_country');
                if ($countrySelect.hasClass('select2-hidden-accessible')) {
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
                $('input[name="logo_path"]').val(data.logo_path || '');
                if (data.logo_path) {
                    $('#cpLogoPreview img').attr('src', `${BASE_URL}/${data.logo_path}`);
                    $('#cpLogoPreview').removeClass('d-none');
                } else {
                    $('#cpLogoPreview').addClass('d-none');
                }
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
                const defaultCountry = 'TH';
                $('#registered_country').val(defaultCountry).trigger('change');
                renderCountrySpecificForm(defaultCountry);
                if (typeof updateText === 'function') {
                    updateText($pane[0]);
                }
            }
        },
        error: function (xhr, status, error) {
            console.error('Failed to load company profile data:', error);
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
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    let formData = {
        registered_country: $('#registered_country').val(),
        global_tax_id: $('input[name="global_tax_id"]').val()?.trim() || '',
        company_legal_name: $('input[name="company_legal_name"]').val()?.trim() || '',
        local_name: $('input[name="local_name"]').val()?.trim() || '',
        address_line_1: $('input[name="address_line_1"]').val()?.trim() || '',
        address_line_2: $('input[name="address_line_2"]').val()?.trim() || '',
        master_address_id: $('input[name="master_address_id"]').val() || null,
        authorized_signatory_name: $('input[name="authorized_signatory_name"]').val()?.trim() || '',
        logo_path: $('input[name="logo_path"]').val() || null,
        statutory_data: {}
    };
    $('#dynamic_statutory_fields_container input').each(function () {
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
        success: function (res) {
            $btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> <span data-i18n="save">Save</span>');
            if (typeof updateText === 'function') updateText($btn[0]);
            if (res.status) {
                if (typeof showSuccess === 'function') {
                    showSuccess(res.message || langData['save_success'] || 'Saved successfully.');
                } else {
                    alert(res.message || langData['save_success'] || 'Saved successfully.');
                }
                initCompanyData();
            } else {
                if (typeof showWarning === 'function') {
                    showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
                } else {
                    alert(res.message || langData['save_failed'] || 'Failed to save data.');
                }
            }
        },
        error: function (xhr, status, error) {
            $btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> <span data-i18n="save">Save</span>');
            if (typeof updateText === 'function') updateText($btn[0]);
            console.error('Save error:', error);
            if (typeof showWarning === 'function') {
                showWarning(langData['save_failed'] || 'Failed to save data.');
            } else {
                alert(langData['save_failed'] || 'Failed to save data.');
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
        function () {
            initPage('p1');
        },
        function () {
        }
    );
});
function initBankPane() {
    $pane.html($('#tmpl-bank-pane').html());
    updateText($pane[0]);
    initBankAccountTable();
}
function maskAccountNo(accountNo) {
    if (!accountNo) return '-';
    const digits = String(accountNo).replace(/\D/g, '');
    if (digits.length <= 4) return accountNo;
    return `••••-${digits.slice(-4)}`;
}
function initBankAccountTable() {
    const tableId = '#tb_bank_account';
    if ($.fn.DataTable.isDataTable(tableId)) {
        $(tableId).DataTable().ajax.reload(null, false);
        return;
    }
    structureTables['bank_account'] = $(tableId).DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/bank_account.list`,
            type: 'POST'
        },
        columns: [
            {
                data: null,
                render: function (data, type, row) {
                    const name = (currentLang === 'th') ? row.bank_name_th : row.bank_name_en;
                    return `${row.bank_code || ''} - ${name || ''}`;
                }
            },
            {
                data: 'account_no',
                render: function (data) {
                    return maskAccountNo(data);
                }
            },
            { data: 'account_name' },
            { data: 'branch_name', defaultContent: '-' },
            {
                data: 'account_type',
                render: function (data) {
                    const key = data === 'current' ? 'current' : 'savings';
                    return `<span data-i18n="${key}">${langData[key] || key}</span>`;
                }
            },
            {
                data: 'is_default',
                className: 'text-center',
                render: function (data) {
                    return data ? `<span class="badge bg-primary" data-i18n="default">Default</span>` : `-`;
                }
            },
            {
                data: 'status',
                render: function (data) {
                    let badge = data === 'active' ? 'bg-success' : 'bg-danger';
                    let key = data === 'active' ? 'active' : 'inactive';
                    return `<span class="badge ${badge}" data-i18n="${key}">${langData[key] || key}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: (data, type, row) => `
                    <div class="btn-group border rounded-3 bg-white">
                        <button class="btn btn-link text-warning btn-open-modal manage-bank_account" data-action="edit" data-type="bank_account" data-id="${row.id}" data-i18n-title="edit">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button class="btn btn-link py-1 text-danger border-start btn-delete-item delete-bank_account" data-type="bank_account" data-id="${row.id}" data-i18n-title="delete">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                `
            }
        ],
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            let self = this.api();
            let $wrapper = $(self.table().container());
            let $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find('.manage-bank_account').length === 0) {
                let btn = `
                    <button class="btn btn-primary btn-open-modal manage-bank_account ms-1" data-action="add" data-type="bank_account" data-id="">
                        <i class="fa-solid fa-plus me-2"></i><span data-i18n="bank_account">${langData['bank_account'] || 'Bank Account'}</span>
                    </button>
                `;
                $searchDiv.append(btn);
            }
            updateText($wrapper[0]);
        },
        drawCallback: function () {
            getTableLang();
            let self = this.api();
            let $wrapper = $(self.table().container());
            updateText($wrapper[0]);
        }
    });
}

function initStructurePane() {
    $pane.html($('#tmpl-structure-pane').html());
    updateText($pane[0]); 
    initStructure('p1');
}
$(document).on('click', '#setup-pane .structure-menu', function () {
    const page = $(this).data('page');
    $('#setup-pane .structure-menu').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');
    initStructure(page);
});
let structureTables = {};
function initStructure(page) {
    const $structureContent = $('#structure-pane-content');
    if (!$structureContent.length) return;
    switch(page) {
        case 'p1':
            $structureContent.html($('#tmpl-branch-pane').html());
            initStructureTable('branch', '#tb_branch');
            break;
        case 'p2':
            $structureContent.html($('#tmpl-role-pane').html());
            initStructureTable('role', '#tb_role');
            break;
        case 'p3':
            $structureContent.html($('#tmpl-department-pane').html());
            initStructureTable('department', '#tb_department');
            break;
        case 'p4':
            $structureContent.html($('#tmpl-position-pane').html());
            initStructureTable('position', '#tb_position');
            break;
        case 'p5':
            $structureContent.html($('#tmpl-rank-pane').html());
            initStructureTable('rank', '#tb_rank');
            break;
        case 'p6':
            $structureContent.html($('#tmpl-permission-pane').html());
            updateText($structureContent[0]);
            if (typeof initPermissionMatrix === 'function') { initPermissionMatrix(); }
            break;
    }
}
function initStructureTable(type, tableId) {
    if ($.fn.DataTable.isDataTable(tableId)) {
        $(tableId).DataTable().ajax.reload(null, false);
        return;
    }
    structureTables[type] = $(tableId).DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[0, 'asc']],
        ajax: {
            url: `${BASE_URL}/api/structure.${type}`,
            type: "POST",
            data: function (d) {
                d.status = $('#filter_status').val() || 'Active'; 
            }
        },
        columns: getStructureColumns(type), 
        pageLength: pageLength,
        lengthMenu: lengthMenu,
        language: getTableLang(),
        initComplete: function () {
            let self = this.api();
            let $wrapper = $(self.table().container());
            let $searchDiv = $wrapper.find('.dt-search');
            if ($searchDiv.find(`.manage-${type}`).length === 0) {
                let btn = `
                    <button class="btn btn-primary btn-open-modal manage-${type} ms-1" data-action="add" data-type="${type}" data-id="">
                        <i class="fa-solid fa-plus me-1"></i>
                        <span data-i18n="${type}">${type}</span>
                    </button>
                `;
                $searchDiv.append(btn);
            }
            let $input = $searchDiv.find('input').off(`.${type}Search`);
            $input.on(`keypress.${type}Search`, function (e) {
                if (e.keyCode === 13) {
                    self.search(this.value).draw();
                }
                if (this.value === "") {
                    self.search(this.value).draw();
                }
            });
            updateText($wrapper[0]);
        },
        drawCallback: function () {
            getTableLang(); 
            let self = this.api();
            let $wrapper = $(self.table().container());
            updateText($wrapper[0]);
        }
    });
}
function getStructureColumns(type) {
    const getActionButtons = (row, type) => {
        return `
            <div class="btn-group border rounded-3 bg-white">
                <button class="btn btn-link text-warning btn-open-modal manage-${type}" data-action="edit" data-type="${type}" data-id="${row.id}" data-i18n-title="edit">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="btn btn-link py-1 text-danger border-start btn-delete-item delete-${type}" data-type="${type}" data-id="${row.id}" data-i18n-title="delete">
                    <i class="fa-solid fa-trash-can"></i>
                </button> 
            </div>
        `;
    };
    const statusRender = (data) => {
        let badge = data === 'active' || data === 1 || data === true ? 'bg-success' : 'bg-danger';
        let text = data === 'active' || data === 1 || data === true ? 'Active' : 'Inactive';
        let textLang = data === 'active' || data === 1 || data === true ? 'active' : 'inactive';
        return `<span class="badge ${badge}" data-i18n="${textLang}">${text}</span>`;
    };
    const getLocaleText = (row, field) => {
        return row[`${field}_${currentLang}`] || row[`${field}_th`] || row[field] || '-';
    };
    switch(type) {
        case 'branch':
            return [
                { data: "branch_code" },
                { 
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'branch_name')
                },
                { data: "tax_branch_id", defaultContent: "-" },
                { data: "sso_branch_code", defaultContent: "-" },
                { 
                    data: "is_default",
                    className: "text-center",
                    render: function (data) {
                        return data ? `<span class="badge bg-primary" data-i18n="default">Default</span>` : `-`;
                    }
                },
                { data: "location", defaultContent: "-" },
                { 
                    data: "lock_stamp",
                    className: "text-center",
                    render: function (data) {
                        return data ? `<i class="fa-solid fa-lock text-warning"></i>` : `<i class="fa-solid fa-lock-open text-muted"></i>`;
                    }
                },
                { data: "status", render: statusRender },
                { 
                    data: null, 
                    orderable: false, 
                    className: "text-center",
                    render: (data, type, row) => getActionButtons(row, 'branch') 
                }
            ];
        case 'role':
            return [
                { 
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'role_name')
                },
                { 
                    data: "salary_access",
                    render: function (data) {
                        return data ? `
                            <span class="badge bg-info" data-i18n="allowed">Allowed</span>
                        ` : `
                            <span class="badge bg-secondary" data-i18n="restricted">Restricted</span>
                        `;
                    }
                },
                { data: "status", render: statusRender },
                { 
                    data: null, 
                    orderable: false, 
                    className: "text-center",
                    render: (data, type, row) => getActionButtons(row, 'role') 
                }
            ];
        case 'department':
            return [
                { data: "department_code" },
                { 
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'department_name')
                },
                { data: "cost_center", defaultContent: "-" },
                { data: "status", render: statusRender },
                { 
                    data: null, 
                    orderable: false, 
                    className: "text-center",
                    render: (data, type, row) => getActionButtons(row, 'department') 
                }
            ];
        case 'position':
            return [
                { data: "position_code" },
                { 
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'position_name')
                },
                { 
                    data: "position_allowance",
                    className: "text-end",
                    render: function (data) {
                        let amount = data ? parseFloat(data) : 0;
                        return amount.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }
                },
                { data: "status", render: statusRender },
                { 
                    data: null, 
                    orderable: false, 
                    className: "text-center",
                    render: (data, type, row) => getActionButtons(row, 'position') 
                }
            ];
        case 'rank':
            return [
                { data: "rank_code" },
                { 
                    data: null,
                    render: (data, type, row) => getLocaleText(row, 'rank_name')
                },
                { 
                    data: null,
                    render: function (data, type, row) {
                        let min = row.salary_min ? parseFloat(row.salary_min).toLocaleString('th-TH') : '0';
                        let max = row.salary_max ? parseFloat(row.salary_max).toLocaleString('th-TH') : 'Max';
                        return `${min} - ${max}`;
                    }
                },
                { 
                    data: "ot_eligible",
                    className: "text-center",
                    render: function (data) {
                        return data ? `<span class="badge bg-info" data-i18n="yes">Yes</span>` : `<span class="badge bg-light text-dark" data-i18n="no">No</span>`;
                    }
                },
                { data: "status", render: statusRender },
                { 
                    data: null, 
                    orderable: false, 
                    className: "text-center",
                    render: (data, type, row) => getActionButtons(row, 'rank') 
                }
            ];
    }
}
const apiEndpointPrefix = {
    branch: 'structure.branch',
    role: 'structure.role',
    department: 'structure.department',
    position: 'structure.position',
    rank: 'structure.rank',
    bank_account: 'bank_account'
};
const formSchemas = {
    branch: {
        fields: [
            { name: 'branch_code', label: 'branch_code', type: 'text', required: true },
            { name: 'branch_name_th', label: 'branch_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'branch_name_en', label: 'branch_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'tax_branch_id', label: 'tax_branch_id', type: 'text' },
            { name: 'sso_branch_code', label: 'sso_branch_code', type: 'text' },
            { name: 'location', label: 'location', type: 'text' },
            { name: 'is_default', label: 'default', type: 'checkbox' },
            { name: 'lock_stamp', label: 'lock_stamp', type: 'checkbox' },
            { name: 'status', label: 'status', type: 'select', optionKeys: ['active', 'inactive'] }
        ]
    },
    role: {
        fields: [
            { name: 'role_name_th', label: 'role_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'role_name_en', label: 'role_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'salary_access', label: 'salary_access', type: 'checkbox' },
            { name: 'status', label: 'status', type: 'select', optionKeys: ['active', 'inactive'] }
        ]
    },
    department: {
        fields: [
            { name: 'department_code', label: 'department_code', type: 'text', required: true },
            { name: 'department_name_th', label: 'department_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'department_name_en', label: 'department_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'cost_center', label: 'cost_center', type: 'text' },
            { name: 'status', label: 'status', type: 'select', optionKeys: ['active', 'inactive'] }
        ]
    },
    position: {
        fields: [
            { name: 'position_code', label: 'position_code', type: 'text', required: true },
            { name: 'position_name_th', label: 'position_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'position_name_en', label: 'position_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'position_allowance', label: 'allowance_base', type: 'number', step: '0.01' },
            { name: 'status', label: 'status', type: 'select', optionKeys: ['active', 'inactive'] }
        ]
    },
    rank: {
        fields: [
            { name: 'rank_code', label: 'rank_code', type: 'text', required: true },
            { name: 'rank_name_th', label: 'rank_name', type: 'text', required: true, legal_key: 'local_name' },
            { name: 'rank_name_en', label: 'rank_name', type: 'text', required: true, legal_key: 'en_name' },
            { name: 'salary_min', label: 'salary_range', type: 'number', step: '0.01', legal_key: 'min' },
            { name: 'salary_max', label: 'salary_range', type: 'number', step: '0.01', legal_key: 'max' },
            { name: 'ot_eligible', label: 'ot_eligible', type: 'checkbox' },
            { name: 'status', label: 'status', type: 'select', optionKeys: ['active', 'inactive'] }
        ]
    },
    bank_account: {
        fields: [
            { name: 'bank_id', label: 'bank_name', type: 'select2', required: true, api: '/api/bank.get', apiType: 'bank', displayTextTh: 'bank_name_th', displayTextEn: 'bank_name_en', displayPrefix: 'bank_code' },
            { name: 'account_no', label: 'account_no', type: 'text', required: true },
            { name: 'account_name', label: 'account_name', type: 'text', required: true },
            { name: 'branch_name', label: 'branch_name', type: 'text' },
            { name: 'account_type', label: 'account_type', type: 'select', optionKeys: ['savings', 'current'] },
            { name: 'is_default', label: 'default', type: 'checkbox' },
            { name: 'status', label: 'status', type: 'select', optionKeys: ['active', 'inactive'] }
        ]
    }
};
$(document).on('click', '.btn-open-modal', function (e) {
    e.preventDefault();
    const btn = $(this);
    const action = btn.data('action'); 
    const type = btn.data('type'); 
    const schema = formSchemas[type];
    if (!schema) return;
    $('#systemModal .modal-header').html(`
        <h5 class="modal-title"><i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="${type}">${langData[type]}</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    `);
    let rowData = {};
    if (action === 'edit') {
        const table = $(`#tb_${type}`).DataTable();
        rowData = table.row(btn.closest('tr')).data() || {};
    }
    let bodyHtml = `<form id="modalForm" data-type="${type}" data-action="${action}">`;
    bodyHtml += `<input type="hidden" name="id" value="${rowData.id || ''}">`;
    schema.fields.forEach(field => {
        const val = rowData[field.name] !== undefined && rowData[field.name] !== null ? rowData[field.name] : '';
        const requiredAttr = field.required ? 'required' : '';
        bodyHtml += `<div class="mb-3 row">
            <label class="col-sm-3 col-form-label text-end"><span data-i18n="${field.label}">${langData[field.label]}</span> ${(field.legal_key) ? `(<span data-i18n="${field.legal_key}">${langData[field.legal_key]}</span>)` : ``} ${requiredMark(field.required)}</label>
            <div class="col-sm-9">`;
        if (field.type === 'select') {
            const requiredClass = field.required ? 'required' : '';
            bodyHtml += `<select class="form-select select2-static ${requiredClass}" name="${field.name}" data-option-keys="${field.optionKeys.join(',')}" ${requiredAttr}></select>`;
        } else if (field.type === 'select2') {
            const requiredClass = field.required ? 'required' : '';
            bodyHtml += `<select class="form-select select2-remote ${requiredClass}" name="${field.name}" data-api="${field.api}" data-type="${field.apiType}" ${requiredAttr}></select>`;
        } else if (field.type === 'checkbox') {
            let checked = val === true || val == 1 ? 'checked' : '';
            bodyHtml += `
                <div class="form-check form-switch pt-2">
                    <input class="form-check-input" type="checkbox" name="${field.name}" value="1" ${checked}>
                </div>`;
        } else {
            const requiredClass = field.required ? 'required' : '';
            bodyHtml += `<input type="${field.type}" class="form-control ${requiredClass}" name="${field.name}" value="${val}" ${requiredAttr} ${field.step ? `step="${field.step}"` : ''}>`;
        }
        bodyHtml += `</div></div>`;
    });
    bodyHtml += `</form>`;
    $('#systemModal .modal-body').html(bodyHtml);
    $('#systemModal .modal-footer').html(`
        <button type="button" class="btn btn-warning" id="btnSubmitModalForm" data-i18n="save">Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
    `);
    if (typeof initSelect2 === 'function') {
        initSelect2('#systemModal .select2-remote', { mode: 'ajax' });
    }
    schema.fields.forEach(field => {
        if (field.type === 'select2' && rowData[field.name]) {
            const $sel = $(`#systemModal [name="${field.name}"]`);
            const textField = currentLang === 'th' ? field.displayTextTh : field.displayTextEn;
            let label = textField ? (rowData[textField] || '') : '';
            if (field.displayPrefix && rowData[field.displayPrefix]) {
                label = `${rowData[field.displayPrefix]} - ${label}`;
            }
            const opt = new Option(label, rowData[field.name], true, true);
            $sel.append(opt).trigger('change');
        }
        if (field.type === 'select') {
            const $sel = $(`#systemModal [name="${field.name}"]`);
            const selectedValue = rowData[field.name] !== undefined && rowData[field.name] !== ''
                ? rowData[field.name]
                : field.optionKeys[0];
            initSelect2($sel, { mode: 'static', keys: field.optionKeys, selectedValue: selectedValue });
        }
    });
    const $modalEl = $('#systemModal');
    $modalEl.modal('show');
    if (typeof updateText === 'function') updateText($modalEl[0]);
});
$(document).on('click', '#btnSubmitModalForm', function () {
    const $btn = $(this);
    const $form = $('#modalForm');
    const type = $form.data('type');
    const schema = formSchemas[type];
    if (!schema) return;
    let hasError = false;
    $form.find('.required').each(function () {
        const value = $(this).val()?.trim() || '';
        if (!value) {
            $(this).addClass('is-invalid');
            hasError = true;
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    if (hasError) {
        showWarning(langData['required_star_message'] || 'Please fill all fields marked with *');
        $form.find('.is-invalid').first().focus();
        return;
    }
    const payload = { id: $form.find('[name="id"]').val() || '' };
    schema.fields.forEach(field => {
        const $field = $form.find(`[name="${field.name}"]`);
        if (field.type === 'checkbox') {
            payload[field.name] = $field.is(':checked');
        } else {
            payload[field.name] = $field.val()?.trim() ?? '';
        }
    });
    $btn.prop('disabled', true).html(`<i class="fa-solid fa-spinner fa-spin me-1"></i> <span>${langData['saving'] || 'Saving...'}</span>`);
    $.ajax({
        url: `${BASE_URL}/api/${apiEndpointPrefix[type] || `structure.${type}`}.save`,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        data: JSON.stringify(payload),
        success: function (res) {
            $btn.prop('disabled', false).html('<span data-i18n="save">Save</span>');
            if (res.status) {
                $('#systemModal').modal('hide');
                showSuccess(langData['save_success'] || 'Saved successfully.');
                if (structureTables[type]) {
                    structureTables[type].ajax.reload(null, false);
                }
            } else {
                showWarning(res.message || langData['save_failed'] || 'Failed to save data.');
            }
        },
        error: function () {
            $btn.prop('disabled', false).html('<span data-i18n="save">Save</span>');
            showWarning(langData['save_failed'] || 'An error occurred while saving the data.');
        }
    });
});
$(document).on('click', '.btn-delete-item', function () {
    const $btn = $(this);
    const type = $btn.data('type');
    const id = $btn.data('id');
    if (!type || !id) return;
    const title = langData['confirm_delete_title'] || 'Confirm Delete';
    const message = langData['confirm_delete_message'] || 'Are you sure you want to delete this item?';
    showConfirm(title, message, function () {
        $.ajax({
            url: `${BASE_URL}/api/${apiEndpointPrefix[type] || `structure.${type}`}.delete`,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({ id: id }),
            success: function (res) {
                if (res.status) {
                    showSuccess(langData['delete_success'] || 'Deleted successfully.');
                    if (structureTables[type]) {
                        structureTables[type].ajax.reload(null, false);
                    }
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