<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i><span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent"><span data-i18n="settings">Settings</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current"><span data-i18n="company_setup">Company Setup</span></span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0"><i class="fa-solid fa-building me-1"></i><span data-i18n="company_management">Company Management</span></h5>
        <p class="text-muted small m-0 mt-1"><span data-i18n="company_management_description">Configure and manage corporate profile, local tax identification, and primary bank accounts for payroll processing.</span></p>
    </div>
    <ul class="nav nav-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="setup-tab" data-bs-toggle="tab" data-bs-target="#setup-pane" type="button" role="tab" aria-controls="setup-pane" aria-selected="true" data-page="p1">
                <i class="fa-solid fa-id-card me-2"></i><span data-i18n="company_profile">Company Profile</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="setup-tab" data-bs-toggle="tab" data-bs-target="#setup-pane" type="button" role="tab" aria-controls="setup-pane" aria-selected="false" data-page="p2">
                <i class="fa-solid fa-credit-card me-2"></i><span data-i18n="bank_accounts">Bank Accounts</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="setup-tab" data-bs-toggle="tab" data-bs-target="#setup-pane" type="button" role="tab" aria-controls="setup-pane" aria-selected="false" data-page="p3">
                <i class="fa-solid fa-building me-2"></i><span data-i18n="organization_structure">Organization Structure</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" id="companySetupTabsContent">
        <div class="tab-pane fade show active" id="setup-pane" role="tabpanel" aria-labelledby="setup-tab" tabindex="0"></div>
    </div>
</div>
<template id="tmpl-profile-pane">
    <div class="mt-5 mb-5">
        <h6 class="text-secondary fw-bold mb-3 mt-2">
            <label class="label label-head bg-head-first rounded-2 text-white me-2">1</label>
            <span data-i18n="company_information">Company Information</span>
        </h6>
        <div class="row">
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span data-i18n="registered_country">Registered Country</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <select id="registered_country" class="select2-remote" data-api="/api/country.get" data-type="country"></select>
            </div>
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span id="tax_id_label" data-i18n="tax_id_ein">Tax ID / EIN</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <input type="text" class="form-control required" name="global_tax_id">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span data-i18n="company_legal_name">Company Legal Name</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <input type="text" class="form-control required" name="company_legal_name">
            </div>
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span data-i18n="local_name">Local Name</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <input type="text" class="form-control required" name="local_name">
            </div>
        </div>
        <h6 class="text-secondary fw-bold mb-3 mt-4">
            <label class="label label-head bg-head-first rounded-2 text-white me-2">2</label>
            <span data-i18n="registered_address">Registered Address</span>
        </h6>
        <div class="row">
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span data-i18n="address_line_1">Address Line 1</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <input type="text" class="form-control required" name="address_line_1">
            </div>
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span data-i18n="address_line_2">Address Line 2 (Optional)</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <input type="text" class="form-control" name="address_line_2">
            </div>
        </div>
        <div class="row">
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span id="address_search_label" data-i18n="search_address_label">Sub-district / City / Postcode</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3 position-relative">
                <input type="text" class="form-control required autocomplete-address" id="search_address" autocomplete="off">
                <div class="address-suggestions-box list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto;"></div>
                <input type="hidden" name="master_address_id" class="master-address-id-field" id="master_address_id">
                <p class="text-muted small mt-2"><i class="fa-solid fa-circle-info me-1"></i><span data-i18n="address_guide">Please enter your postal code, city/district, and state/province.</span></p>
            </div>
        </div>
        <h6 class="text-secondary fw-bold mb-3 mt-4">
            <label class="label label-head bg-head-first rounded-2 text-white me-2">3</label>
            <span data-i18n="local_statutory">Local Statutory</span> & <span data-i18n="tax_settings">Tax Settings</span>
        </h6>
        <p class="text-muted small mb-3" data-i18n="local_statutory_description">*Please enter information based on the statutory requiredments of your company's country of registration.</p>
        <div id="dynamic_statutory_fields_container" class="row"></div>
        <div class="row">
            <div class="col-sm-2 mt-3">
                <label class="form-label">
                    <span data-i18n="authorized_signatory_name">Authorized Signatory Name</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <input type="text" class="form-control required" name="authorized_signatory_name">
            </div>
        </div>
    </div>
    <div class="text-end">
        <button type="button" class="btn btn-warning save-company-profile"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
        <button type="button" class="btn btn-light cancel-company-profile" data-i18n="cancel">Cancel</button>
    </div>
</template>
<template id="tmpl-bank-pane">
    <div class="mt-5 mb-5">เนื้อหาฟอร์มจัดการธนาคาร...</div>
</template>
<template id="tmpl-structure-pane">
    <div class="mt-5 mb-5">
        Role/Department/Position/Rank
    </div>
</template>
<script src="<?=asset('public/js/setup/company-profile.js')?>"></script>