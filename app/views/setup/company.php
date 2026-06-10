<div class="container container-body">
    <div class="payroll-breadcrumb mt-5 mb-3">
        <span class="bc-root">Payroll</span>
        <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
        <span class="bc-parent" data-i18n="settings">Settings</span>
        <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
        <span class="bc-current" data-i18n="company_setup">Company Setup</span>
    </div>
    <div class="card p-4 shadow-sm mb-3">
        <h4 class="fw-bold text-dark m-0" data-i18n="company_setup">Company Setup</h4>
        <hr class="text-muted">
        <form id="companySetupForm" method="POST" action="">
            <h5 class="text-secondary fw-bold mb-3">
                <i class="fas fa-globe me-2"></i> <span data-i18n="global_general_info">Company Global Information</span>
            </h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-bold"><span data-i18n="company_legal_name">Company Legal Name</span> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="company_legal_name" required placeholder="e.g., Acme Co., Ltd.">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold"><span data-i18n="tax_registration_number">Tax Registration ID / EIN</span> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="global_tax_id" required placeholder="Tax Registration Number">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold"><span data-i18n="hq_country">Registered Country</span> <span class="text-danger">*</span></label>
                    <select class="form-select" name="registered_country" required>
                        <option value="">-- Select Country --</option>
                        <option value="TH">Thailand</option>
                        <option value="SG">Singapore</option>
                        <option value="MY">Malaysia</option>
                        <option value="US">United States</option>
                        <option value="JP">Japan</option>
                    </select>
                </div>
            </div>
            <h5 class="text-secondary fw-bold mb-3">
                <i class="fa-solid fa-map-location me-2"></i> <span data-i18n="registered_address">Registered Address</span>
            </h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label"><span data-i18n="address_line1">Address Line 1</span> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="address_line1" placeholder="Street address, P.O. box, company name" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><span data-i18n="address_line2">Address Line 2 (Optional)</span></label>
                    <input type="text" class="form-control" name="address_line2" placeholder="Apartment, suite, unit, building, floor">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="city">City / District</span> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="city" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="state_province">State / Province / Region</span> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="state_province" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="postal_code">Postal / ZIP Code</span> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="postal_code" required>
                </div>
            </div>
            <h5 class="text-secondary fw-bold mb-3">
                <i class="fas fa-file-invoice-dollar me-2"></i> <span data-i18n="local_statutory_settings">Local Statutory & Tax Settings</span>
            </h5>
            <p class="text-muted small mb-3">*กรอกข้อมูลตามเงื่อนไขทางกฎหมายของประเทศที่บริษัทท่านจดทะเบียน</p>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="tax_branch_code">Tax Branch Code (if applicable)</span></label>
                    <input type="text" class="form-control" name="local_tax_branch" placeholder="e.g., 00000 (Thailand)">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="social_security_id">Social Security / Provident Fund ID</span></label>
                    <input type="text" class="form-control" name="local_sso_id">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="authorized_signatory">Authorized Signatory Name</span></label>
                    <input type="text" class="form-control" name="authorized_signatory" placeholder="For official tax reports">
                </div>
            </div>
            <h5 class="text-secondary fw-bold mb-3">
                <i class="fas fa-university me-2"></i> <span data-i18n="financial_settings">Financial & Payroll Currency Settings</span>
            </h5>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label fw-bold"><span data-i18n="base_currency">Base Currency</span> <span class="text-danger">*</span></label>
                    <select class="form-select" name="base_currency" required>
                        <option value="THB">THB (฿)</option>
                        <option value="USD">USD ($)</option>
                        <option value="SGD">SGD (S$)</option>
                        <option value="EUR">EUR (€)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold"><span data-i18n="timezone">Company Timezone</span> <span class="text-danger">*</span></label>
                    <select class="form-select" name="company_timezone" required>
                        <option value="Asia/Bangkok">(GMT+07:00) Bangkok</option>
                        <option value="Asia/Singapore">(GMT+08:00) Singapore</option>
                        <option value="America/New_York">(GMT-05:00) New York</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><span data-i18n="bank_name">Bank Name</span></label>
                    <input type="text" class="form-control" name="int_bank_name" placeholder="e.g., Citibank, DBS, Kasikorn Bank">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="account_number">Bank Account Number / IBAN</span></label>
                    <input type="text" class="form-control" name="int_bank_account_no">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="swift_bic">SWIFT Code / BIC (For Int'l Transfer)</span></label>
                    <input type="text" class="form-control" name="bank_swift_code" placeholder="e.g., BKCHTHBK">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><span data-i18n="company_bank_code">Company Bank Code / Service Code</span></label>
                    <input type="text" class="form-control" name="int_bank_service_code" placeholder="e.g., Corporate ID">
                </div>
            </div>
            <hr class="text-muted">
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light px-4" data-i18n="cancel">Cancel</button>
                <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #ff9900; border-color: #ff9900;" data-i18n="save_settings">Save Settings</button>
            </div>
        </form>
    </div>
</div>