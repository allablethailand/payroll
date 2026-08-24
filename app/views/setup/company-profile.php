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
    <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
         app/views/payroll/index.php). -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-building"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="company_management">Company Management</h5>
            <p class="page-header-card-desc" data-i18n="company_management_description">Configure and manage corporate profile, local tax identification, and primary bank accounts for payroll processing.</p>
        </div>
    </div>
    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu active" id="setup-tab-p1" type="button" role="tab"
                    aria-controls="setup-pane" aria-selected="true" data-page="p1">
                <i class="fa-solid fa-id-card me-2"></i><span data-i18n="company_profile">Company Profile</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="setup-tab-p2" type="button" role="tab"
                    aria-controls="setup-pane" aria-selected="false" data-page="p2">
                <i class="fa-solid fa-credit-card me-2"></i><span data-i18n="bank_accounts">Bank Accounts</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="setup-tab-p3" type="button" role="tab"
                    aria-controls="setup-pane" aria-selected="false" data-page="p3">
                <i class="fa-solid fa-building me-2"></i><span data-i18n="organization_structure">Organization Structure</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0">
        <div class="tab-pane fade show active" id="setup-pane" role="tabpanel" aria-labelledby="setup-tab-p1" tabindex="0"></div>
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
                    <span data-i18n="company_local_name">Local Name</span>
                    <span class="text-danger">*</span>
                </label>
            </div>
            <div class="col-sm-4 mt-3">
                <input type="text" class="form-control required" name="local_name">
            </div>
        </div>
        <!-- 2026-08-24, explicit request: "ในหน้า Profile บริษัท ให้สามารถใส่ Logo ได้ และดึงไปใช้กับหน้า
             ตั้งค่า Slip เงินเดือน และใบรับรอง" -- same upload UI convention as Payslip Template's own
             logo field (immediate upload on file select, hidden field carries the path into the
             main form save). -->
        <div class="row">
            <div class="col-sm-2 mt-3">
                <label class="form-label" data-i18n="company_logo">Company Logo</label>
            </div>
            <div class="col-sm-4 mt-3">
                <div id="cpLogoPreview" class="mb-2 d-none"><img src="" alt="Logo" style="max-height:70px;" class="border rounded p-1"></div>
                <label class="btn btn-outline-secondary btn-sm" for="cp_logo_file">
                    <i class="fa-solid fa-upload me-1"></i><span data-i18n="upload_logo">Upload Logo</span>
                </label>
                <input type="file" id="cp_logo_file" accept=".jpg,.jpeg,.png,.svg" class="d-none">
                <input type="hidden" id="cp_logo_path" name="logo_path">
                <p class="text-muted small mt-2 mb-0" data-i18n="company_logo_reuse_hint">Used as the default logo on Payslip and Employment Certificate templates that don't have their own.</p>
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
            <span data-i18n="local_statutory_and_tax_settings">Local Statutory & Tax Settings</span>
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
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_bank_account">
            <thead>
                <tr>
                    <th data-i18n="bank_name">Bank</th>
                    <th data-i18n="account_no">Account No.</th>
                    <th data-i18n="account_name">Account Name</th>
                    <th data-i18n="branch_name">Branch</th>
                    <th data-i18n="account_type">Type</th>
                    <th data-i18n="default">Default</th>
                    <th data-i18n="status">Status</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<template id="tmpl-structure-pane">
    <div class="mt-5 mb-5">
        <div class="bg-light rounded-3 p-2 mb-4 structure-tabs-wrap">
            <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu active" id="structure-tab-p1" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="true" data-page="p1">
                        <i class="fa-solid fa-code-branch me-2"></i><span data-i18n="branch">Branch</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="structure-tab-p2" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="false" data-page="p2">
                        <i class="fa-solid fa-user-tag me-2"></i><span data-i18n="role">Role</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="structure-tab-p3" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="false" data-page="p3">
                        <i class="fa-solid fa-sitemap me-2"></i><span data-i18n="department">Department</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="structure-tab-p4" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="false" data-page="p4">
                        <i class="fa-solid fa-briefcase me-2"></i><span data-i18n="position">Position</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="structure-tab-p5" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="false" data-page="p5">
                        <i class="fa-solid fa-ranking-star me-2"></i><span data-i18n="rank">Rank</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="structure-tab-p6" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="false" data-page="p6">
                        <i class="fa-solid fa-shield-halved me-2"></i><span data-i18n="permissions">Permissions</span>
                    </button>
                </li>
            </ul>
        </div>
        <div class="tab-content border-0 bg-white mb-5 mt-0">
            <div class="tab-pane fade show active" id="structure-pane-content" role="tabpanel" aria-labelledby="structure-tab-p1" tabindex="0"></div>
        </div>
    </div>
</template>
<template id="tmpl-branch-pane">
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_branch">
            <thead>
                <tr>
                    <th data-i18n="branch_code">Branch Code</th>
                    <th data-i18n="branch_name">Branch Name</th>
                    <th data-i18n="tax_branch_id">Tax Branch ID</th> 
                    <th data-i18n="sso_branch_code">SSO Code</th>
                    <th data-i18n="default">Default</th>
                    <th data-i18n="location">Location</th>
                    <th data-i18n="lock_stamp">Lock Stamp</th>
                    <th data-i18n="status">Status</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<template id="tmpl-role-pane">
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_role">
            <thead>
                <tr>
                    <th data-i18n="role_name">Role Name</th>
                    <th data-i18n="salary_access">Salary Access</th>
                    <th data-i18n="status">Status</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<template id="tmpl-department-pane">
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_department">
            <thead>
                <tr>
                    <th data-i18n="department_code">Department Code</th>
                    <th data-i18n="department_name">Department Name</th>
                    <th data-i18n="cost_center">Cost Center</th>
                    <th data-i18n="status">Status</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<template id="tmpl-position-pane">
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_position">
            <thead>
                <tr>
                    <th data-i18n="position_code">Position Code</th>
                    <th data-i18n="position_name">Position Name</th>
                    <th data-i18n="allowance_base">Allowance (Base)</th>
                    <th data-i18n="status">Status</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<template id="tmpl-rank-pane">
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_rank">
            <thead>
                <tr>
                    <th data-i18n="rank_code">Rank Code</th>
                    <th data-i18n="rank_name">Rank Name</th>
                    <th data-i18n="salary_range">Salary Range (Min - Max)</th>
                    <th data-i18n="ot_eligible">OT Eligible</th>
                    <th data-i18n="status">Status</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<template id="tmpl-permission-pane">
    <div class="mt-5 mb-5">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <p class="text-muted small mb-0" data-i18n="permission_matrix_hint">Check the boxes to grant each role access. Roles are managed in the Role tab.</p>
            <button type="button" class="btn btn-primary btn-sm" id="btnSavePermissionMatrix">
                <i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span>
            </button>
        </div>
        <div id="permissionMatrixContainer" class="table-responsive"></div>
    </div>
</template>
<script src="<?=asset('public/js/setup/company-profile.js')?>"></script>
<script src="<?=asset('public/js/setup/permission-matrix.js')?>"></script>