<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> Payroll</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="settings">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="company_setup">Company Setup</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-building"></i>
            <span data-i18n="company_management_title">Company Management</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="company_management_description">Configure and manage corporate profile, local tax identification, and primary bank accounts for payroll processing.</p>
    </div>
    <ul class="nav nav-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold text-secondary" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-pane" type="button" role="tab" aria-controls="profile-pane" aria-selected="true">
                <i class="fa-solid fa-id-card me-2"></i>Company Profile
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-secondary" id="banks-tab" data-bs-toggle="tab" data-bs-target="#banks-pane" type="button" role="tab" aria-controls="banks-pane" aria-selected="false">
                <i class="fa-solid fa-credit-card me-2"></i>Bank Accounts
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-5" id="companySetupTabsContent">
        <div class="tab-pane fade show active" id="profile-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label> 
                <span data-i18n="global_general_info">Company Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="hq_country">Registered Country</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="registered_country" name="registered_country" required>
                        <option value="">-- Select Country --</option>
                        <option value="TH">Thailand</option>
                        <option value="SG">Singapore</option>
                        <option value="MY">Malaysia</option>
                        <option value="US">United States</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span id="tax_id_label" data-i18n="tax_registration_number">Tax ID / EIN</span> <span class="text-danger">*</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="global_tax_id" required placeholder="Tax Registration Number">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="company_legal_name">Company Legal Name</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="company_legal_name" required placeholder="e.g., Acme Co., Ltd.">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="company_local_name">Local Name</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="company_local_name" required placeholder="e.g., บริษัท แอคมี จำกัด">
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-4">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label> 
                <span data-i18n="registered_address">Registered Address</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="address_line1">Address Line 1</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="address_line1" placeholder="Street address, P.O. box" required>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="address_line2">Address Line 2 (Optional)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="address_line2" placeholder="Apartment, suite, unit, building">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="city">City / District</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="city" required>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="postal_code">Postal Code</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="postal_code" required>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-4">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label> 
                <span data-i18n="local_statutory_settings">Local Statutory & Tax Settings</span>
            </h6>
            <p class="text-muted small mb-3" data-i18n="local_statutory_hint">*Please enter information based on the statutory requirements of your company's country of registration.</p>
            <div id="dynamic_statutory_fields_container" class="row"></div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="authorized_signatory">Authorized Signatory Name</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="authorized_signatory" placeholder="For official tax reports" required>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-5">
                <button type="button" class="btn btn-primary px-4" id="btnNextToBanks">Next: Bank Accounts <i class="fas fa-chevron-right ms-1"></i></button>
            </div>
        </div>
        <div class="tab-pane fade" id="banks-pane" role="tabpanel" aria-labelledby="banks-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">4</label> 
                <span data-i18n="financial_settings">Financial & Payroll Settings</span>
            </h6>
            <div class="row mb-4">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="base_currency">Base Currency</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="base_currency" name="base_currency" required>
                        <option value="THB">THB (฿)</option>
                        <option value="SGD">SGD (S$)</option>
                        <option value="MYR">MYR (RM)</option>
                        <option value="USD">USD ($)</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="company_timezone">Company Timezone</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="company_timezone" name="company_timezone" required>
                        <option value="Asia/Bangkok">(GMT+07:00) Bangkok</option>
                        <option value="Asia/Singapore">(GMT+08:00) Singapore</option>
                        <option value="Asia/Kuala_Lumpur">(GMT+08:00) Kuala Lumpur</option>
                        <option value="America/New_York">(GMT-05:00) New York</option>
                    </select>
                </div>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                <h6 class="text-secondary fw-bold m-0">Corporate Bank Accounts</h6>
                <button type="button" class="btn btn-outline-success btn-sm" id="btnAddBank">
                    <i class="fas fa-plus me-1"></i> Add Bank Account
                </button>
            </div>
            <div id="dynamic_bank_accounts_container"></div>
            <div class="d-flex justify-content-end gap-2 mt-5 border-top pt-4">
                <button type="button" class="btn btn-light px-4" id="btnBackToProfile"><i class="fas fa-chevron-left me-1"></i> Back</button>
                <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #ff9900; border-color: #ff9900;" data-i18n="save_settings">Save Settings</button>
            </div>
        </div>
    </div>
</div>
<script>
    $(document).ready(function () {
        const countryMasterConfig = {
            "TH": {
                "tax_label": "Tax ID / เลขประจำตัวผู้เสียภาษี",
                "currency": "THB",
                "timezone": "Asia/Bangkok",
                "fields": [
                    { "name": "th_branch_code", "label": "Tax Branch Code", "placeholder": "e.g., 00000 (Head Office)", "required": true },
                    { "name": "th_sso_id", "label": "Social Security Employer ID", "placeholder": "10 digits Number", "required": true }
                ]
            },
            "SG": {
                "tax_label": "Unique Entity Number (UEN)",
                "currency": "SGD",
                "timezone": "Asia/Singapore",
                "fields": [
                    { "name": "sg_csn", "label": "CPF Submission Number (CSN)", "placeholder": "UEN + CPF Payment Code", "required": true }
                ]
            },
            "MY": {
                "tax_label": "Income Tax Number",
                "currency": "MYR",
                "timezone": "Asia/Kuala_Lumpur",
                "fields": [
                    { "name": "my_epf_no", "label": "EPF Employer Number", "placeholder": "", "required": true },
                    { "name": "my_socso_no", "label": "SOCSO Number", "placeholder": "", "required": true }
                ]
            },
            "US": {
                "tax_label": "EIN",
                "currency": "USD",
                "timezone": "America/New_York",
                "fields": [
                    { "name": "us_sui_account_number", "label": "State Unemployment ID (SUI)", "placeholder": "State-issued SUI Account Number", "required": true }
                ]
            }
        };
        function renderCountrySpecificForm(countryCode) {
            const $container = $('#dynamic_statutory_fields_container');
            $container.empty();
            const config = countryMasterConfig[countryCode];
            if (!config) {
                $('#tax_id_label').text('Tax ID / EIN');
                return;
            }
            $('#tax_id_label').text(config.tax_label);
            $('#base_currency').val(config.currency).trigger('change');
            $('#company_timezone').val(config.timezone).trigger('change');
            config.fields.forEach(field => {
                const requiredAttribute = field.required ? 'required' : '';
                const redAsterisk = field.required ? '<span class="text-danger">*</span>' : '';
                const elementHtml = `
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span>${field.label}</span> ${redAsterisk}</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="${field.name}" placeholder="${field.placeholder}" ${requiredAttribute}>
                    </div>
                `;
                $container.append(elementHtml);
            });
        }
        let bankAccountIndex = 0;
        function addBankAccountRow(isPrimary = false) {
            bankAccountIndex++;
            const badgeText = isPrimary ? 'Primary Account' : 'Secondary Account';
            const badgeClass = isPrimary ? 'bg-primary' : 'bg-secondary';
            const deleteButton = !isPrimary ? `<button type="button" class="btn btn-sm btn-outline-danger btn-remove-bank"><i class="fas fa-trash-alt"></i> Remove</button>` : '';
            const bankCardHtml = `
                <div class="card bank-account-card mb-3 shadow-sm border">
                    <div class="card-header d-flex justify-content-between align-items-center bg-light py-2">
                        <span class="badge ${badgeClass}">${badgeText}</span>
                        ${deleteButton}
                    </div>
                    <div class="card-body py-2 pb-4">
                        <div class="row">
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][bank_name]" placeholder="e.g., Kasikorn Bank, DBS" required>
                            </div>
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">Account Number / IBAN <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][account_no]" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">SWIFT Code / BIC</label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][swift_code]" placeholder="e.g., BKCHTHBK">
                            </div>
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">Company Bank Code</label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][service_code]" placeholder="e.g., Corporate ID">
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('#dynamic_bank_accounts_container').append(bankCardHtml);
        }
        $('#registered_country').change(function () {
            renderCountrySpecificForm($(this).val());
        });
        $('#btnAddBank').click(function() {
            addBankAccountRow(false);
        });
        $(document).on('click', '.btn-remove-bank', function() {
            $(this).closest('.bank-account-card').remove();
        });
        $('#btnNextToBanks').click(function() {
            const triggerEl = document.querySelector('#companySetupTabs button[data-bs-target="#banks-pane"]');
            bootstrap.Tab.getInstance(triggerEl).show();
        });
        $('#btnBackToProfile').click(function() {
            const triggerEl = document.querySelector('#companySetupTabs button[data-bs-target="#profile-pane"]');
            bootstrap.Tab.getInstance(triggerEl).show();
        });
        const defaultInitialCountry = "TH";
        $('#registered_country').val(defaultInitialCountry); 
        renderCountrySpecificForm(defaultInitialCountry);
        addBankAccountRow(true);
    });
</script>