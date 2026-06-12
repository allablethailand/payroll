<div class="container container-body">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
        <span class="bc-root">Payroll</span>
        <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
        <span class="bc-parent" data-i18n="settings">Settings</span>
        <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
        <span class="bc-current" data-i18n="company_setup">Company Setup</span>
    </h5>
    <div>
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-building"></i>
            <span data-i18n="payroll_cycle_management">Company Management</span>
        </h5>
        <p class="text-muted small m-0 mt-1">กำหนดและจัดการรอบการจ่ายเงินเดือนพนักงาน (สามารถแยกตามกลุ่มพนักงาน หรือประเภทการจ้างงานได้)</p>
    </div>
    <div class="mt-5 mb-5">
        <form id="companySetupForm" novalidate>
            <div class="mb-3">
                <h6 class="text-secondary fw-bold mb-3 mt-3">
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
                        <label class="form-label"><span data-i18n="company_legal_name">Company Name</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="company_legal_name" required placeholder="e.g., Acme Co., Ltd.">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="company_local_name">Local Name</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="company_local_name" required placeholder="e.g., Acme Co., Ltd.">
                    </div>
                </div>
                <h6 class="text-secondary fw-bold mb-3 mt-3">
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
                <h6 class="text-secondary fw-bold mb-3 mt-3">
                    <label class="label label-head bg-head-first rounded-2 text-white">3</label> 
                    <span data-i18n="local_statutory_settings">Local Statutory & Tax Settings</span>
                </h6>
                <p class="text-muted small mb-3">*กรอกข้อมูลตามเงื่อนไขทางกฎหมายของประเทศที่บริษัทท่านจดทะเบียน</p>
                <div id="local_fields_container">
                    <div class="row country-specific d-none" data-country="TH">
                        <div class="col-sm-2 mt-3">
                            <label class="form-label"><span>Tax Branch Code</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4 mt-3">
                            <input type="text" class="form-control local-required" name="th_branch_code" placeholder="e.g., 00000 (Head Office)">
                        </div>
                        <div class="col-sm-2 mt-3">
                            <label class="form-label"><span>Social Security Employer ID</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4 mt-3">
                            <input type="text" class="form-control local-required" name="th_sso_id" placeholder="10 digits Number">
                        </div>
                    </div>
                    <div class="row country-specific d-none" data-country="SG">
                        <div class="col-sm-2 mt-3">
                            <label class="form-label"><span>CPF Submission Number (CSN)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4 mt-3">
                            <input type="text" class="form-control local-required" name="sg_csn" placeholder="UEN + CPF Payment Code">
                        </div>
                    </div>
                    <div class="row country-specific d-none" data-country="MY">
                        <div class="col-sm-2 mt-3">
                            <label class="form-label"><span>EPF Employer Number</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4 mt-3">
                            <input type="text" class="form-control local-required" name="my_epf_no">
                        </div>
                        <div class="col-sm-2 mt-3">
                            <label class="form-label"><span>SOCSO Number</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4 mt-3">
                            <input type="text" class="form-control local-required" name="my_socso_no">
                        </div>
                    </div>
                    <div class="row country-specific d-none" data-country="US">
                        <div class="col-sm-2 mt-3">
                            <label class="form-label"><span>State Unemployment ID (SUI)</span> <span class="text-danger">*</span></label>
                        </div>
                        <div class="col-sm-4 mt-3">
                            <input type="text" class="form-control local-required" name="us_sui_id">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="authorized_signatory">Authorized Signatory Name</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="authorized_signatory" placeholder="For official tax reports" required>
                    </div>
                </div>

                <h6 class="text-secondary fw-bold mb-3 mt-3">
                    <label class="label label-head bg-head-first rounded-2 text-white">4</label> 
                    <span data-i18n="financial_settings">Financial & Payroll Currency Settings</span>
                </h6>
                <div class="row">
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
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="bank_name">Bank Name</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="int_bank_name" placeholder="e.g., Citibank, DBS, Kasikorn Bank" required>
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="bank_account_no">Bank Account Number / IBAN</span> <span class="text-danger">*</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="int_bank_account_no" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="swift_code">SWIFT Code / BIC</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="bank_swift_code" placeholder="e.g., BKCHTHBK">
                    </div>
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span data-i18n="bank_service_code">Company Bank Code / Service Code</span></label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="int_bank_service_code" placeholder="e.g., Corporate ID">
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-5">
                    <button type="button" class="btn btn-light px-4" data-i18n="cancel">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #ff9900; border-color: #ff9900;" data-i18n="save_settings">Save Settings</button>
                </div>
            </div>
        </form>
    </div>
</div>