<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="settings">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="tax_and_statutory">Tax & Statutory</span>
        </h5>
    </nav>
    <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
         app/views/payroll/index.php). -->
    <div class="page-header-card mb-4">
        <div class="page-header-card-icon"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="page-header-card-body">
            <h5 class="page-header-card-title" data-i18n="tax_and_statutory">Tax & Statutory</h5>
            <p class="page-header-card-desc" data-i18n="tax_statutory_description">Configure statutory items (tax, social insurance, provident fund) per country, with rate history and progressive tax brackets.</p>
        </div>
    </div>

    <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs" id="taxStatutoryTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu active" id="master-rate-tab" data-bs-toggle="tab" data-bs-target="#master-rate-pane" type="button" role="tab" aria-controls="master-rate-pane" aria-selected="true">
                <i class="fa-solid fa-globe me-2"></i><span data-i18n="tab_master_rate">Master Rates</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="company-setting-tab" data-bs-toggle="tab" data-bs-target="#company-setting-pane" type="button" role="tab" aria-controls="company-setting-pane" aria-selected="false">
                <i class="fa-solid fa-building me-2"></i><span data-i18n="tab_company_setting">Company Settings</span>
            </button>
        </li>
        <!-- 2026-08-29, follow-up to Bank File Format: "ส่วน Format เอกสารของการนำส่งสรรพากร และ
             ประกันสังคม ก็อยากให้มีการตั้งค่าเหมือนกัน" -- a VERSION SELECTOR (pick which known format
             version to file), not a field editor like Bank File Format -- see
             StatutoryFormatVersionModel's own docblock for why. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="document-format-tab" data-bs-toggle="tab" data-bs-target="#document-format-pane" type="button" role="tab" aria-controls="document-format-pane" aria-selected="false">
                <i class="fa-solid fa-file-lines me-2"></i><span data-i18n="tab_document_format">Document Format</span>
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" style="border-top-left-radius:0;border-top-right-radius:0;">
    <div class="tab-pane fade show active" id="master-rate-pane" role="tabpanel" aria-labelledby="master-rate-tab" tabindex="0">
        <!-- 2026-08-28, explicit request: "แสดงผลเฉพาะตามประเทศที่ตัวเองตั้งค่า...ให้รองรับเฉพาะ
             ประเทศไทยก่อน" -- the interactive country filter (which defaulted to blank, showing
             every country's items mixed together -- a real gap, see TaxStatutoryController's own
             companyCountry() docblock) is replaced by a plain label: this whole page is now always
             scoped server-side to the company's own registered country, so a filter that could only
             ever show ONE value would just be confusing UI. #masterRateCountryLabel is filled in
             from the list response itself (countries_name_th/en, already joined) -- no separate
             lookup call needed. -->
        <div class="mb-3">
            <span class="text-muted small" data-i18n="master_rate_country_scope_label">Showing statutory items for your company's registered country:</span>
            <span class="fw-bold" id="masterRateCountryLabel">-</span>
        </div>
        <table class="table table-hover table-border align-middle w-100" id="tb_statutory_item">
            <thead class="table-light text-secondary">
                <tr>
                    <th scope="col" style="width: 8%;" data-i18n="table_country">Country</th>
                    <th scope="col" style="width: 11%;" data-i18n="table_code">Code</th>
                    <th scope="col" style="width: 16%;" data-i18n="table_name">Name</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_category">Category</th>
                    <th scope="col" style="width: 12%;" data-i18n="table_calc_method">Calculation Method</th>
                    <th scope="col" style="width: 11%;" data-i18n="table_current_rate">Current Rate</th>
                    <th scope="col" style="width: 13%;" data-i18n="table_last_updated">Last Updated</th>
                    <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 11%; text-align: center;"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="tab-pane fade" id="company-setting-pane" role="tabpanel" aria-labelledby="company-setting-tab" tabindex="0">
        <p class="text-muted small" data-i18n="company_setting_description">Enable/disable statutory items for your company and adjust rates where the law permits, based on your company's registered country.</p>
        <table class="table table-hover table-border align-middle w-100" id="tb_company_setting">
            <thead class="table-light text-secondary">
                <tr>
                    <th scope="col" style="width: 10%;" data-i18n="table_code">Code</th>
                    <th scope="col" style="width: 18%;" data-i18n="table_name">Name</th>
                    <th scope="col" style="width: 11%;" data-i18n="table_category">Category</th>
                    <th scope="col" style="width: 15%;" data-i18n="table_current_rate">Rate in Use</th>
                    <th scope="col" style="width: 9%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 7%;" data-i18n="modal_company_rate_editable_short">Adjustable</th>
                    <th scope="col" style="width: 13%;" data-i18n="table_last_updated">Last Updated</th>
                    <th scope="col" style="width: 8%; text-align: center;"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="tab-pane fade" id="document-format-pane" role="tabpanel" aria-labelledby="document-format-tab" tabindex="0">
        <p class="text-muted small" data-i18n="document_format_description">Choose which known submission format version to use for each statutory document. Adding a new version in the future needs no code change here -- it's picked from this list.</p>
        <div id="statutoryFormatCards" class="row g-3"></div>
    </div>
    </div>

    <!-- Statutory Item Modal -->
    <div class="modal fade" id="statutoryItemModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="statutoryItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="statutoryItemModalLabel">
                        <i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="statutory_item">Statutory Item</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="statutoryItemForm" novalidate>
                    <input type="hidden" name="id" id="item_id">
                    <div class="modal-body">
                        <h6 class="text-secondary fw-bold mb-3 mt-2">
                            <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">1</label>
                            <span data-i18n="modal_sec_item_info">Item Information</span>
                        </h6>
                        <!-- 2026-08-28, explicit request -- country is no longer a field the user
                             fills in here at all (same precedent as Holiday's own country_code:
                             "ไม่ใช่ field ที่ผู้ใช้กรอกเอง ระบบดึงจาก companies.registered_country
                             อัตโนมัติ"). TaxStatutoryController::itemSave() always forces it to the
                             acting company's own registered country server-side regardless of what
                             a request contains, so showing a picker here would just be misleading. -->
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_code">Code</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <input type="text" class="form-control required" id="item_code" name="code" data-i18n="statutory_item_code_placeholder" placeholder="e.g. TH_SSO">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_name_th">Name (Thai)</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <input type="text" class="form-control required" id="item_name_th" name="name_th">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_name_en">Name (English)</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <input type="text" class="form-control required" id="item_name_en" name="name_en">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_category">Category</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static required" id="item_category" name="category" data-option-keys="category_tax,category_social_insurance,category_provident_fund,category_other" data-option-values="tax,social_insurance,provident_fund,other"></select>
                            </div>
                        </div>
                        <hr class="my-4 text-muted opacity-25">
                        <h6 class="text-secondary fw-bold mb-3">
                            <label class="label label-head bg-head-first rounded-2 text-white px-2 py-0">2</label>
                            <span data-i18n="modal_sec_calc_config">Calculation Configuration</span>
                        </h6>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_calc_method">Calculation Method</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static required" id="item_calc_method" name="calc_method" data-option-keys="calc_method_flat_rate,calc_method_progressive_bracket,calc_method_fixed_amount,calc_method_formula" data-option-values="flat_rate,progressive_bracket,fixed_amount,formula"></select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_calc_base">Calculation Base</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static required" id="item_calc_base" name="calc_base" data-option-keys="calc_base_basic_salary,calc_base_gross_salary,calc_base_taxable_income,calc_base_net_income,calc_base_sso_eligible_earnings,calc_base_pf_eligible_earnings,calc_base_custom" data-option-values="basic_salary,gross_salary,taxable_income,net_income,sso_eligible_earnings,pf_eligible_earnings,custom"></select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_rounding_mode">Rounding</span></label>
                            </div>
                            <div class="col-sm-9">
                                <select class="form-select select2-static" id="item_rounding_mode" name="rounding_mode" data-option-keys="rounding_mode_round,rounding_mode_up,rounding_mode_down,rounding_mode_none" data-option-values="round,up,down,none"></select>
                                <div class="form-text" data-i18n="modal_rounding_mode_hint">How to handle decimals left over after calculation.</div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_decimal_places">Decimal Places</span></label>
                            </div>
                            <div class="col-sm-3">
                                <input type="number" min="0" max="4" class="form-control" id="item_decimal_places" name="decimal_places" value="2">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_sort_order">Sort Order</span></label>
                            </div>
                            <div class="col-sm-3">
                                <input type="number" min="0" class="form-control" id="item_sort_order" name="sort_order" value="0">
                            </div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-6">
                                <input type="checkbox" class="me-2" id="item_is_employee_applicable" name="is_employee_applicable" checked><span data-i18n="modal_employee_applicable">Applies to Employee</span>
                            </div>
                            <div class="col-sm-6">
                                <input type="checkbox" class="me-2" id="item_is_employer_applicable" name="is_employer_applicable" checked><span data-i18n="modal_employer_applicable">Applies to Employer</span>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-sm-6">
                                <input type="checkbox" class="me-2" id="item_default_is_active" name="default_is_active" checked><span data-i18n="modal_default_active">Active by Default for New Companies</span>
                            </div>
                            <div class="col-sm-6">
                                <input type="checkbox" class="me-2" id="item_is_company_rate_editable" name="is_company_rate_editable"><span data-i18n="modal_company_rate_editable">Company May Adjust Rate</span>
                            </div>
                        </div>
                        <div class="row mb-2 mt-2">
                            <div class="col-sm-6">
                                <input type="checkbox" class="me-2" id="item_status" name="status" checked><span data-i18n="active">Active</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary"><span data-i18n="save">Save</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Rate History Modal -->
    <div class="modal fade" id="rateHistoryModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="rateHistoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="rateHistoryModalLabel">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="rate_history">Rate History</span>
                        <span class="text-muted small ms-1" id="rateHistoryItemName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small" data-i18n="rate_history_description">Statutory rates change over time. Add a new version instead of editing the current one to preserve history.</p>
                    <div class="d-flex justify-content-end mb-2">
                        <button type="button" class="btn btn-primary btn-sm" id="btnAddRateVersion">
                            <i class="fa-solid fa-plus me-1"></i><span data-i18n="rate_version">Rate Version</span>
                        </button>
                    </div>
                    <table class="table table-hover table-border align-middle w-100" id="tb_rate_history">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th scope="col" data-i18n="table_effective_date">Effective Date</th>
                                <th scope="col" data-i18n="table_end_date">End Date</th>
                                <th scope="col" data-i18n="table_rate_summary">Rate</th>
                                <th scope="col" data-i18n="table_last_updated">Last Updated</th>
                                <th scope="col" style="text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Rate Version Modal -->
    <div class="modal fade" id="rateVersionModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="rateVersionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="rateVersionModalLabel">
                        <i class="fa-solid fa-pen-to-square me-1"></i><span data-i18n="rate_version">Rate Version</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="rateVersionForm" novalidate>
                    <input type="hidden" name="id" id="rate_id">
                    <input type="hidden" name="statutory_item_id" id="rate_statutory_item_id">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_effective_date">Effective Date</span> <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input type="text" class="form-control required datepicker" id="rate_effective_date" name="effective_date" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_end_date">End Date</span></label>
                            </div>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input type="text" class="form-control datepicker" id="rate_end_date" name="end_date" autocomplete="off">
                                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                </div>
                            </div>
                            <div class="col-sm-5 pt-2">
                                <span class="text-muted small" data-i18n="end_date_optional_hint">Leave blank if this rate is still in effect (open-ended).</span>
                            </div>
                        </div>
                        <hr class="my-3 text-muted opacity-25">

                        <div id="rate_flat_fields">
                            <div class="row mb-3" id="rate_employee_rate_wrapper">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employee_rate">Employee Rate (%)</span> <span class="text-danger">*</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.0001" min="0" class="form-control" id="rate_employee_rate" name="employee_rate">
                                </div>
                            </div>
                            <div class="row mb-3" id="rate_employer_rate_wrapper">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employer_rate">Employer Rate (%)</span> <span class="text-danger">*</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.0001" min="0" class="form-control" id="rate_employer_rate" name="employer_rate">
                                </div>
                            </div>
                        </div>

                        <div id="rate_amount_fields" class="d-none">
                            <div class="row mb-3" id="rate_employee_amount_wrapper">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employee_amount">Employee Amount</span> <span class="text-danger">*</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.01" min="0" class="form-control" id="rate_employee_amount" name="employee_amount">
                                </div>
                            </div>
                            <div class="row mb-3" id="rate_employer_amount_wrapper">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_employer_amount">Employer Amount</span> <span class="text-danger">*</span></label>
                                </div>
                                <div class="col-sm-4">
                                    <input type="number" step="0.01" min="0" class="form-control" id="rate_employer_amount" name="employer_amount">
                                </div>
                            </div>
                        </div>

                        <div id="rate_bracket_fields" class="d-none">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold mb-0"><span data-i18n="tax_brackets">Tax Brackets</span></h6>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddBracketRow"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_bracket">Bracket</span></button>
                            </div>
                            <div class="table-responsive">
                                <table class="table pl-table mb-0">
                                    <thead>
                                        <tr>
                                            <th data-i18n="bracket_from">From</th>
                                            <th data-i18n="bracket_to">To</th>
                                            <th data-i18n="bracket_rate">Rate (%)</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="bracketBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div id="rate_formula_fields" class="d-none">
                            <div class="row mb-3">
                                <div class="col-sm-3 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_formula_config">Formula Config (JSON)</span> <span class="text-danger">*</span></label>
                                </div>
                                <div class="col-sm-9">
                                    <textarea class="form-control" id="rate_formula_config" name="formula_config" rows="4" data-i18n="formula_config_json_example" placeholder='{"base_rate": 1.45, "additional_rate": 0.9, "additional_threshold": 200000}'></textarea>
                                    <p class="text-muted small mt-1 mb-0" data-i18n="formula_config_hint">Reserved for future formula-based calculations, e.g. threshold-based extra rates. Enter a valid JSON object.</p>
                                </div>
                            </div>
                        </div>

                        <hr class="my-3 text-muted opacity-25">
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_min_base">Minimum Base Amount</span></label>
                            </div>
                            <div class="col-sm-3">
                                <input type="number" step="0.01" min="0" class="form-control" id="rate_min_base_amount" name="min_base_amount">
                            </div>
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_max_base">Maximum Base Amount</span></label>
                            </div>
                            <div class="col-sm-3">
                                <input type="number" step="0.01" min="0" class="form-control" id="rate_max_base_amount" name="max_base_amount">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_max_employee_contribution">Max Employee Contribution</span></label>
                            </div>
                            <div class="col-sm-3">
                                <input type="number" step="0.01" min="0" class="form-control" id="rate_max_employee_contribution" name="max_employee_contribution">
                            </div>
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_max_employer_contribution">Max Employer Contribution</span></label>
                            </div>
                            <div class="col-sm-3">
                                <input type="number" step="0.01" min="0" class="form-control" id="rate_max_employer_contribution" name="max_employer_contribution">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-3 align-self-center">
                                <label class="form-label mb-0"><span data-i18n="modal_remark">Remark</span></label>
                            </div>
                            <div class="col-sm-9">
                                <input type="text" class="form-control" id="rate_remark" name="remark">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" id="btnBackToRateHistory" data-i18n="back">Back</button>
                        <button type="submit" class="btn btn-primary"><span data-i18n="save">Save</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Company Statutory Setting Modal -->
    <div class="modal fade" id="companySettingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="companySettingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title text-secondary" id="companySettingModalLabel">
                        <i class="fa-solid fa-building me-1"></i><span data-i18n="tab_company_setting">Company Settings</span>
                        <span class="text-muted small ms-1" id="companySettingItemName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="companySettingForm" novalidate>
                    <input type="hidden" name="statutory_item_id" id="cs_statutory_item_id">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-sm-12">
                                <input type="checkbox" class="me-2" id="cs_is_active" name="is_active" checked>
                                <span data-i18n="modal_company_enable_item">Enable this statutory item for our company</span>
                            </div>
                        </div>
                        <div id="cs_override_wrapper">
                            <hr class="my-3 text-muted opacity-25">
                            <p class="text-muted small" id="cs_master_default_hint"></p>
                            <div id="cs_rate_fields" class="d-none">
                                <div class="row mb-3">
                                    <div class="col-sm-4 align-self-center">
                                        <label class="form-label mb-0"><span data-i18n="modal_employee_rate">Employee Rate (%)</span></label>
                                    </div>
                                    <div class="col-sm-4">
                                        <input type="number" step="0.0001" min="0" class="form-control" id="cs_employee_rate_override" name="employee_rate_override" data-i18n="default" placeholder="Default">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4 align-self-center">
                                        <label class="form-label mb-0"><span data-i18n="modal_employer_rate">Employer Rate (%)</span></label>
                                    </div>
                                    <div class="col-sm-4">
                                        <input type="number" step="0.0001" min="0" class="form-control" id="cs_employer_rate_override" name="employer_rate_override" data-i18n="default" placeholder="Default">
                                    </div>
                                </div>
                            </div>
                            <div id="cs_amount_fields" class="d-none">
                                <div class="row mb-3">
                                    <div class="col-sm-4 align-self-center">
                                        <label class="form-label mb-0"><span data-i18n="modal_employee_amount">Employee Amount</span></label>
                                    </div>
                                    <div class="col-sm-4">
                                        <input type="number" step="0.01" min="0" class="form-control" id="cs_employee_amount_override" name="employee_amount_override" data-i18n="default" placeholder="Default">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-sm-4 align-self-center">
                                        <label class="form-label mb-0"><span data-i18n="modal_employer_amount">Employer Amount</span></label>
                                    </div>
                                    <div class="col-sm-4">
                                        <input type="number" step="0.01" min="0" class="form-control" id="cs_employer_amount_override" name="employer_amount_override" data-i18n="default" placeholder="Default">
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-sm-4 align-self-center">
                                    <label class="form-label mb-0"><span data-i18n="modal_remark">Remark</span></label>
                                </div>
                                <div class="col-sm-8">
                                    <input type="text" class="form-control" id="cs_remark" name="remark">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-outline-danger" id="btnResetCompanySetting"><i class="fa-solid fa-rotate-left me-1"></i><span data-i18n="reset_to_default">Reset to Default</span></button>
                        <div>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                            <button type="submit" class="btn btn-primary"><span data-i18n="save">Save</span></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="<?=asset('public/js/setup/tax-statutory.js')?>"></script>
