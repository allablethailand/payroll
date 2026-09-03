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
        <!-- 2026-09-02, explicit request following an AskUserQuestion exchange -- confirmed Thai
             PIT withholding on Thailand-source salary uses the SAME progressive table regardless of
             resident/non-resident status; the user's own accountant may still apply a different
             rate this app has no basis to assume, so this is a plain company-configurable setting
             (never a hardcoded "correct" rate) -- see NonResidentTaxSettingModel's own docblock. -->
        <li class="nav-item" role="presentation">
            <button class="nav-link setup-menu" id="nonresident-tax-tab" data-bs-toggle="tab" data-bs-target="#nonresident-tax-pane" type="button" role="tab" aria-controls="nonresident-tax-pane" aria-selected="false">
                <i class="fa-solid fa-passport me-2"></i><span data-i18n="tab_nonresident_tax">Non-Resident Foreign Tax</span>
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
                    <th scope="col" style="width: 8%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 8%;" data-i18n="table_country">Country</th>
                    <th scope="col" style="width: 11%;" data-i18n="table_code">Code</th>
                    <th scope="col" style="width: 16%;" data-i18n="table_name">Name</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_category">Category</th>
                    <th scope="col" style="width: 12%;" data-i18n="table_calc_method">Calculation Method</th>
                    <th scope="col" style="width: 11%;" data-i18n="table_current_rate">Current Rate</th>
                    <th scope="col" style="width: 13%;" data-i18n="table_last_updated">Last Updated</th>
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
                    <th scope="col" style="width: 9%;" data-i18n="col_status">Status</th>
                    <th scope="col" style="width: 10%;" data-i18n="table_code">Code</th>
                    <th scope="col" style="width: 18%;" data-i18n="table_name">Name</th>
                    <th scope="col" style="width: 11%;" data-i18n="table_category">Category</th>
                    <th scope="col" style="width: 15%;" data-i18n="table_current_rate">Rate in Use</th>
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
    <div class="tab-pane fade" id="nonresident-tax-pane" role="tabpanel" aria-labelledby="nonresident-tax-tab" tabindex="0">
        <p class="text-muted small" data-i18n="nonresident_tax_description">Optional: withhold a flat percentage instead of the normal progressive calculation for employees flagged as tax non-residents (foreign workers). Off by default -- turn this on only if your own accountant/tax advisor has confirmed a specific rate to use, this app does not assume one.</p>
        <div class="card-surface p-4" style="max-width: 640px;">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="nonresidentTaxEnabled">
                <label class="form-check-label fw-bold" for="nonresidentTaxEnabled" data-i18n="nonresident_tax_enabled">Enable non-resident flat withholding rate</label>
            </div>
            <div id="nonresidentTaxFieldsWrap">
                <div class="mb-3">
                    <label class="form-label" data-i18n="nonresident_tax_flat_rate">Flat Withholding Rate (%)</label>
                    <input type="number" class="form-control" id="nonresidentTaxFlatRate" min="0" max="100" step="0.01" data-i18n="percent_rate_placeholder" placeholder="e.g., 1.5">
                </div>
                <div class="mb-3">
                    <label class="form-label" data-i18n="nonresident_tax_reference_note">Reference Note (optional)</label>
                    <textarea class="form-control" id="nonresidentTaxReferenceNote" rows="2" maxlength="500" data-i18n="nonresident_tax_reference_note_placeholder" placeholder="e.g. Revenue Department ruling no., or your accountant's advice"></textarea>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border" id="nonresidentTaxCancelBtn">
                    <i class="fa-solid fa-xmark me-1"></i><span data-i18n="cancel">Cancel</span>
                </button>
                <button type="button" class="btn btn-primary" id="nonresidentTaxSaveBtn">
                    <i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span>
                </button>
            </div>
        </div>
        <p class="text-muted small mt-3" data-i18n="nonresident_tax_employee_flag_hint">To apply this rate to a specific employee, mark them as a tax non-resident on their own profile (Employee Detail, foreigner employees only).</p>
    </div>
    </div>

    <!-- statutoryItemModal / rateHistoryModal / rateVersionModal / companySettingModal moved to
         app/views/layout/modals.php (2026-08-30, modal consolidation). -->
</div>
<script src="<?=asset('public/js/setup/tax-statutory.js')?>"></script>
