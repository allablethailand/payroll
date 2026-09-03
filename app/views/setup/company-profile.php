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
    <!-- 2026-08-30, explicit request: "อยากให้ Clear เรื่องกลุ่มของข้อมูล ตอนนี้เหมือนยังแยกกันไม่ชัดเจน
         ส่วนไหนที่แยกออกมาเป็นอีก Tab ได้ก็ควรแยกครับ" -- was one long scroll of 6 numbered sections;
         split into 4 sub-tabs (same .structure-tabs pill visual convention as the Bank Accounts/
         Organization Structure sub-tabs on this same page), grouped by what they actually are:
         Company Information (identity + address, what every company fills first), Statutory & Tax
         (per-country dynamic fields only), Signatory & Branding (Signatory Name + Logo + Signature
         reunited -- Signatory Name used to sit stranded at the end of the Statutory section, split
         from its own Signature by the unrelated Logo section in between; all three are genuinely
         "what appears on generated documents"), and Origami Integration (newest section, a
         genuinely distinct system-linkage concern, not company data).
         Uses REAL Bootstrap tab-panes (data-bs-toggle="tab", CSS display toggle only), NOT the
         swap-innerHTML-per-tab pattern Bank Accounts/Bank File Format use just above -- every
         field must stay present in the DOM at once regardless of which sub-tab is showing, since
         .save-company-profile's own handler (company-profile.js) reads every field via a single
         flat set of global jQuery selectors in one JSON payload, not scoped to whichever pane
         happens to be visible. Save/Cancel stay OUTSIDE the tab-content, visible on every sub-tab,
         since one Save call already covers the whole form -- no per-tab save needed (confirmed
         no backend change required: CompanyProfileModel::save() already accepts today's full
         payload shape unchanged, just re-grouped in the UI).
         2026-08-30, explicit bug report: "sub tab ไม่มีช่องว่าง padding" -- root cause found by
         comparing against this page's own sibling tabs: #tmpl-bank-pane/#tmpl-structure-pane (below)
         both wrap their sub-tab-pill-bar + content in an outer `<div class="mt-5 mb-5">`, giving the
         pill bar real breathing room from the white tab-content card's own top edge (that card itself
         is `mt-0` -- the gap has always had to come from the injected template, not the outer
         wrapper). This template (Company Profile's own default/first-shown tab) never had that same
         outer wrapper -- it started directly with .structure-tabs-wrap (mb-4 only, no mt-*), so its
         pill bar sat flush against the card's top edge with zero gap. Added the same `mt-5 mb-5`
         wrapper here for parity -- purely a spacing fix, no markup inside changed. -->
    <div class="mt-5 mb-5">
    <div class="bg-light rounded-3 p-2 mb-4 structure-tabs-wrap">
        <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" id="cpProfileSubTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link structure-menu active" id="cpSub-info-tab" data-bs-toggle="tab" data-bs-target="#cpSub-info-pane" type="button" role="tab" aria-controls="cpSub-info-pane" aria-selected="true">
                    <i class="fa-solid fa-building me-2"></i><span data-i18n="company_information">Company Information</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link structure-menu" id="cpSub-statutory-tab" data-bs-toggle="tab" data-bs-target="#cpSub-statutory-pane" type="button" role="tab" aria-controls="cpSub-statutory-pane" aria-selected="false">
                    <i class="fa-solid fa-landmark me-2"></i><span data-i18n="local_statutory_and_tax_settings">Local Statutory &amp; Tax Settings</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link structure-menu" id="cpSub-brand-tab" data-bs-toggle="tab" data-bs-target="#cpSub-brand-pane" type="button" role="tab" aria-controls="cpSub-brand-pane" aria-selected="false">
                    <i class="fa-solid fa-signature me-2"></i><span data-i18n="cp_signatory_branding_tab">Signatory &amp; Branding</span>
                </button>
            </li>
        </ul>
    </div>
    <div class="tab-content mb-5">
        <div class="tab-pane fade show active" id="cpSub-info-pane" role="tabpanel" aria-labelledby="cpSub-info-tab" tabindex="0">
            <!-- 2026-09-02, real Origami `GET /api/hr/company` endpoint confirmed live -- always
                 overwrites (Origami is the data owner, confirmed via AskUserQuestion), so this is
                 always shown rather than gated behind a link-status check client-side; the backend
                 (CompanySyncModel::sync()) returns a clear not-connected/not-linked message either
                 way, same "backend decides, don't guess client-side" convention as every other
                 Origami-gated action in this app. -->
            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-outline-brand btn-sm" id="btnSyncCompanyOrigami">
                    <i class="fa-solid fa-rotate me-1"></i><span data-i18n="sync_from_origami">Sync from Origami</span>
                </button>
            </div>
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
                    <input type="text" class="form-control required" name="global_tax_id" data-i18n="tax_id_placeholder" placeholder="e.g., 1234567890123">
                </div>
                <!-- 2026-09-03, Manual Entry / Platform UX review Phase 5 (fee currency), Option A --
                     a real company-level default currency setting (companies.currency_code), replacing
                     3 spots across the app that hardcoded a literal "THB" input-group badge regardless
                     of the company's actual registered country (base_salary_amount here, plus
                     Recurring Earning/Deduction's own Amount field -- see modals.php). id="base_currency"
                     is the pre-existing hook renderCountrySpecificForm() already reads (see that
                     function's own `$baseCurrency` line in company-profile.js, dead code until now --
                     auto-derives from country-config.json's own `currency` per country) -- initCompanyData()
                     overrides it with the company's own SAVED value right after, so a company that has
                     already picked a currency never gets silently reset back to the country default on
                     load; only actively switching the Country dropdown re-suggests one. -->
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="base_currency">Currency</span>
                        <span class="text-danger">*</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select id="base_currency" name="currency_code" class="form-select select2-static required"
                            data-option-keys="currency_thb,currency_sgd,currency_myr,currency_usd"
                            data-option-values="THB,SGD,MYR,USD"></select>
                    <div class="form-text" data-i18n="base_currency_hint">Defaults to your registered country's currency -- change it if your company pays employees in a different currency.</div>
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
                    <input type="text" class="form-control required" name="company_legal_name" data-i18n="company_legal_name_placeholder" placeholder="e.g., ABC Company Limited">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="company_local_name">Local Name</span>
                        <span class="text-danger">*</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="local_name" data-i18n="company_local_name_placeholder" placeholder="e.g., บริษัท เอบีซี จำกัด">
                </div>
                <!-- 2026-08-29, follow-up to the Annual Income Summary report request: "การตั้งค่ารอบปี
                     ให้เอาไปไว้ในส่วนของการตั้งค่า" -- a single company-wide value (like registered_country
                     above), so it lives here in Company Information rather than a new settings section
                     of its own. Governs which calendar month a "fiscal year" starts on for that report's
                     own year grouping/filter (1=January, the default, is a plain calendar year -- so a
                     company that never touches this sees no behavior change at all). -->
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="fiscal_year_start_month">Fiscal Year Start Month</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" name="fiscal_year_start_month" id="fiscal_year_start_month" data-option-keys="month_1,month_2,month_3,month_4,month_5,month_6,month_7,month_8,month_9,month_10,month_11,month_12" data-option-values="1,2,3,4,5,6,7,8,9,10,11,12"></select>
                </div>
                <!-- 2026-08-29, explicit request: "กรณีคนเข้า และคนออก การคิดเงินเดือน ต้องจับหาร 30 ตามกฏหมาย
                     ช่วยเพิ่มให้ตั้งค่าตัวเลขนี้ได้หน่อยได้ไหมครับ" -- a single company-wide value (same shape
                     as Fiscal Year Start Month above), governs the divisor PayrollRunModel::recalculate()
                     uses for a monthly-rate employee's mid-period join/leave proration -- default 30
                     matches the Thai labor law convention named outright in the request. -->
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="prorate_divisor_days">Proration Divisor (Days)</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="number" class="form-control" name="prorate_divisor_days" id="prorate_divisor_days" min="1" max="31" value="30" data-i18n="days_placeholder" placeholder="e.g., 1">
                    <div class="form-text" data-i18n="prorate_divisor_days_hint">Used to calculate partial-month pay when an employee joins or leaves mid-period (Thai labor law: 30).</div>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-4" data-i18n="registered_address">Registered Address</h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="address_line_1">Address Line 1</span>
                        <span class="text-danger">*</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control required" name="address_line_1" data-i18n="address_line_1_placeholder" placeholder="House no., building, street">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span data-i18n="address_line_2">Address Line 2 (Optional)</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="address_line_2" data-i18n="address_line_2_placeholder" placeholder="Sub-district, district, province">
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
                    <input type="text" class="form-control required autocomplete-address" id="search_address" autocomplete="off" data-i18n="map_search_placeholder" placeholder="Search for an address...">
                    <div class="address-suggestions-box list-group position-absolute w-100 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto;"></div>
                    <input type="hidden" name="master_address_id" class="master-address-id-field" id="master_address_id">
                    <p class="text-muted small mt-2"><i class="fa-solid fa-circle-info me-1"></i><span data-i18n="address_guide">Please enter your postal code, city/district, and state/province.</span></p>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="cpSub-statutory-pane" role="tabpanel" aria-labelledby="cpSub-statutory-tab" tabindex="0">
            <p class="text-muted small mb-3" data-i18n="local_statutory_description">*Fields follow the statutory requirements of your company's registered country.</p>
            <div id="dynamic_statutory_fields_container" class="row"></div>
        </div>
        <!-- 2026-08-30, explicit request: "Tab Signatory & Branding สามารถปรับให้สวยขึ้นกว่านี้ได้ไหมครับ" --
             was 3 plain stacked sections (a bare label/input row, then two full-width h6-headed rows,
             each upload card alone on its own line) with no visual grouping at all. Rewrapped into
             .settings-info-card (new component below, same gradient-header-strip + card-surface shell
             convention as Payslip Template's own .pst-info-card -- see that class's docblock) -- one
             card for the signatory name, then Logo/Signature side-by-side in a 2-column row instead of
             stacked, since both are genuinely the same kind of thing (an uploadable brand asset) and a
             wide monitor was showing a huge empty gutter next to each single-column upload card before.
             Every id/name attribute below is UNCHANGED from before this pass -- company-profile.js's
             own selectors need zero changes, this is a pure markup/CSS restructure. -->
        <div class="tab-pane fade" id="cpSub-brand-pane" role="tabpanel" aria-labelledby="cpSub-brand-tab" tabindex="0">
            <div class="settings-info-card mb-4">
                <div class="settings-info-card-header">
                    <i class="fa-solid fa-user-pen"></i>
                    <div>
                        <p class="settings-info-card-title" data-i18n="authorized_signatory_title">Authorized Signatory</p>
                        <p class="settings-info-card-desc" data-i18n="authorized_signatory_hint">The name printed alongside the signature on generated Payslip and Employment Certificate documents.</p>
                    </div>
                </div>
                <div class="settings-info-card-body">
                    <div class="row">
                        <div class="col-sm-3 align-self-center">
                            <label class="form-label mb-0">
                                <span data-i18n="authorized_signatory_name">Authorized Signatory Name</span>
                                <span class="text-danger">*</span>
                            </label>
                        </div>
                        <div class="col-sm-6">
                            <input type="text" class="form-control required" name="authorized_signatory_name" data-i18n="authorized_signatory_name_placeholder" placeholder="e.g., Somchai Jaidee">
                        </div>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="settings-info-card h-100">
                        <div class="settings-info-card-header">
                            <i class="fa-solid fa-image"></i>
                            <div>
                                <p class="settings-info-card-title" data-i18n="company_logo">Company Logo</p>
                                <p class="settings-info-card-desc" data-i18n="company_logo_reuse_hint">Used as the default logo on Payslip and Employment Certificate templates that don't have their own.</p>
                            </div>
                        </div>
                        <div class="settings-info-card-body">
                            <div class="cp-logo-upload-card" id="cpLogoUploadCard">
                                <div class="cp-logo-preview-box" id="cpLogoPreviewBox">
                                    <img id="cpLogoPreviewImg" src="" alt="Logo" class="d-none">
                                    <div class="cp-logo-placeholder" id="cpLogoPlaceholder">
                                        <i class="fa-solid fa-building"></i>
                                        <span data-i18n="no_logo_uploaded">No logo uploaded</span>
                                    </div>
                                </div>
                                <div class="cp-logo-actions">
                                    <label class="btn btn-outline-secondary btn-sm" for="cp_logo_file">
                                        <i class="fa-solid fa-upload me-1"></i><span data-i18n="upload_logo">Upload Logo</span>
                                    </label>
                                    <button type="button" class="btn btn-outline-danger btn-sm d-none" id="cpLogoRemoveBtn">
                                        <i class="fa-solid fa-trash me-1"></i><span data-i18n="remove">Remove</span>
                                    </button>
                                    <input type="file" id="cp_logo_file" accept=".jpg,.jpeg,.png,.svg" class="d-none">
                                    <input type="hidden" id="cp_logo_path" name="logo_path">
                                    <input type="hidden" id="cp_logo_file_size" name="logo_file_size">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- 2026-08-26, explicit request: "เพิ่มให้แนบลายเซ็นต์ Authorized Signatory Name หรือสามารถเซ็นต์สด
                     ผ่านหน้าจอได้" -- same upload-card layout as Company Logo just above, plus a second input
                     method (a live signature-pad drawn on a canvas, opened in a modal -- see
                     #cpSignaturePadModal below this template). Both paths end up producing the exact same kind
                     of file through the exact same upload endpoint (uploadSignature()), so this card doesn't
                     need to know or care which one was used. -->
                <div class="col-lg-6">
                    <div class="settings-info-card h-100">
                        <div class="settings-info-card-header">
                            <i class="fa-solid fa-signature"></i>
                            <div>
                                <p class="settings-info-card-title" data-i18n="company_signature">Authorized Signature</p>
                                <p class="settings-info-card-desc" data-i18n="company_signature_reuse_hint">Available as the "Authorized Signature" item when designing Payslip and Employment Certificate templates.</p>
                            </div>
                        </div>
                        <div class="settings-info-card-body">
                            <div class="cp-logo-upload-card" id="cpSignatureUploadCard">
                                <div class="cp-logo-preview-box" id="cpSignaturePreviewBox">
                                    <img id="cpSignaturePreviewImg" src="" alt="Signature" class="d-none">
                                    <div class="cp-logo-placeholder" id="cpSignaturePlaceholder">
                                        <i class="fa-solid fa-signature"></i>
                                        <span data-i18n="no_signature_uploaded">No signature yet</span>
                                    </div>
                                </div>
                                <div class="cp-logo-actions">
                                    <label class="btn btn-outline-secondary btn-sm" for="cp_signature_file">
                                        <i class="fa-solid fa-upload me-1"></i><span data-i18n="upload_signature">Upload Image</span>
                                    </label>
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="cpDrawSignatureBtn">
                                        <i class="fa-solid fa-pen-nib me-1"></i><span data-i18n="draw_signature">Draw Signature</span>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm d-none" id="cpSignatureRemoveBtn">
                                        <i class="fa-solid fa-trash me-1"></i><span data-i18n="remove">Remove</span>
                                    </button>
                                    <input type="file" id="cp_signature_file" accept=".jpg,.jpeg,.png,.svg" class="d-none">
                                    <input type="hidden" id="cp_signature_path" name="signature_path">
                                    <input type="hidden" id="cp_signature_file_size" name="signature_file_size">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- 2026-08-30, explicit request: "tab การเชื่อมต่อ Origami ไม่จำเป็นต้องมีนะครับ เพราะว่าเป็นการ
             ตั้งค่าหลังบ้านตอน Sync มาผู้ใช้ไม่สามารถตั้งค่าเองได้" -- the Origami Integration sub-tab
             (ref_id/origami_payroll_comp_code) is removed. NOTE: this WAS the only save path for
             these 2 columns anywhere in the app (added 2026-08-29 specifically because Origami's own
             SSO payload never carries them automatically) -- flagged to the user before removing;
             they confirmed removing it anyway. CompanyProfileModel::save() switched both columns to
             COALESCE(:param, existing_column) so an already-configured company's values are frozen in
             place, not silently nulled out by a future unrelated profile save (see that model's own
             comment). -->
    </div>
    <div class="text-end">
        <button type="button" class="btn btn-primary save-company-profile"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
        <button type="button" class="btn btn-light cancel-company-profile" data-i18n="cancel">Cancel</button>
    </div>
    </div>
</template>
<template id="tmpl-bank-pane">
    <div class="mt-5 mb-5">
        <!-- 2026-08-29, explicit request: "เอาไปไว้ในส่วนของหน้าจัดการธนาคารให้สามารถจัดการ Format เพื่อนำ
             ส่งธนาคารได้" -- 2nd sub-tab alongside the existing Bank Accounts list, same
             .structure-tabs pill convention as Organizational Structure's Branch/Role/Department/etc. -->
        <div class="bg-light rounded-3 p-2 mb-4 structure-tabs-wrap">
            <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu active" id="bank-sub-tab-accounts" type="button" role="tab" aria-controls="bank-sub-pane" aria-selected="true" data-bank-page="accounts">
                        <i class="fa-solid fa-credit-card me-2"></i><span data-i18n="bank_accounts">Bank Accounts</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="bank-sub-tab-format" type="button" role="tab" aria-controls="bank-sub-pane" aria-selected="false" data-bank-page="format">
                        <i class="fa-solid fa-file-lines me-2"></i><span data-i18n="bank_file_format">Bank File Format</span>
                    </button>
                </li>
            </ul>
        </div>
        <div id="bank-sub-pane"></div>
    </div>
</template>
<template id="tmpl-bank-accounts-subpane">
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="tb_bank_account">
            <thead>
                <tr>
                    <th data-i18n="status">Status</th>
                    <th data-i18n="bank_name">Bank</th>
                    <th data-i18n="account_no">Account No.</th>
                    <th data-i18n="account_name">Account Name</th>
                    <th data-i18n="bank_account_company_code">Company/Service Code</th>
                    <th data-i18n="branch_name">Branch</th>
                    <th data-i18n="account_type">Type</th>
                    <th data-i18n="default">Default</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<template id="tmpl-bank-format-subpane">
    <p class="text-secondary small mb-3" data-i18n="bank_file_format_hint">
        Configure how the bank transfer file is laid out for each bank format — no code changes needed. A format with no customization yet uses the system-provided starting template.
    </p>
    <div class="row">
        <div class="col-lg-4 mb-3">
            <div class="card-surface p-3">
                <h6 class="fw-bold mb-3" data-i18n="bank_file_format_list">Bank Formats</h6>
                <div id="bffFormatList" class="d-flex flex-column gap-2"></div>
            </div>
        </div>
        <div class="col-lg-8 mb-3">
            <div id="bffDetailEmpty" class="card-surface p-4 text-center text-secondary">
                <i class="fa-solid fa-arrow-left me-2"></i><span data-i18n="bank_file_format_select_hint">Select a bank format on the left to view or edit its file layout.</span>
            </div>
            <div id="bffDetailPanel" class="d-none">
                <div class="card-surface p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h6 class="fw-bold mb-0"><span id="bffDetailFormatName"></span></h6>
                            <span class="badge bg-warning-subtle text-warning" id="bffDraftBadge" data-i18n="draft_not_verified">DRAFT — not verified</span>
                        </div>
                        <div class="btn-group border rounded-3 bg-white">
                            <button type="button" class="btn btn-link btn-sm" id="bffViewLogBtn"><i class="fa-solid fa-clock-rotate-left me-1"></i><span data-i18n="edit_log">Edit Log</span></button>
                            <button type="button" class="btn btn-link btn-sm text-danger border-start" id="bffResetBtn"><i class="fa-solid fa-rotate-left me-1"></i><span data-i18n="reset_to_default">Reset to Default</span></button>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-3">
                            <label class="form-label small" data-i18n="delimiter_type">Layout Type</label>
                            <select class="form-select form-select-sm" id="bffDelimiterType">
                                <option value="delimited" data-i18n="delimited">Delimited (CSV)</option>
                                <option value="fixed_width" data-i18n="fixed_width">Fixed-Width</option>
                            </select>
                        </div>
                        <div class="col-sm-2" id="bffDelimiterCharWrap">
                            <label class="form-label small" data-i18n="delimiter_char">Delimiter</label>
                            <input type="text" class="form-control form-control-sm" id="bffDelimiterChar" maxlength="5" value="," data-i18n="bff_field_pad_char_placeholder" placeholder="e.g., 0">
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small" data-i18n="line_ending">Line Ending</label>
                            <select class="form-select form-select-sm" id="bffLineEnding">
                                <option value="crlf">CRLF</option>
                                <option value="lf">LF</option>
                            </select>
                        </div>
                        <div class="col-sm-2">
                            <label class="form-label small" data-i18n="text_encoding">Encoding</label>
                            <select class="form-select form-select-sm" id="bffTextEncoding">
                                <option value="utf8">UTF-8</option>
                                <option value="tis620">TIS-620</option>
                            </select>
                        </div>
                        <div class="col-sm-3 d-flex align-items-end gap-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="bffHasHeaderRow">
                                <label class="form-check-label small" for="bffHasHeaderRow" data-i18n="has_header_row">Header row</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="bffHasTrailerRow">
                                <label class="form-check-label small" for="bffHasTrailerRow" data-i18n="has_trailer_row">Trailer row</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="bffIsVerified">
                                <label class="form-check-label small" for="bffIsVerified" data-i18n="format_is_verified_label">I've confirmed this layout against our bank / RM</label>
                            </div>
                        </div>
                    </div>
                    <div class="text-end mt-3">
                        <button type="button" class="btn btn-primary btn-sm" id="bffSaveConfigBtn"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
                    </div>
                </div>
                <div class="card-surface p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0" data-i18n="bank_file_format_fields">Fields</h6>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="bffAddFieldBtn"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_field">Add Field</span></button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th data-i18n="row_type">Row</th>
                                    <th data-i18n="order">Order</th>
                                    <th data-i18n="field_label">Label</th>
                                    <th data-i18n="source">Source</th>
                                    <th data-i18n="width">Width</th>
                                    <th style="width:90px;"></th>
                                </tr>
                            </thead>
                            <tbody id="bffFieldsBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<!-- bffFieldModal / bffLogModal moved to app/views/layout/modals.php (2026-08-30, modal consolidation). -->
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
                <!-- 2026-08-24, explicit request: "ในหน้าตั้งค่าพนักงาน ให้เพิ่ม Team เข้าไปได้ด้วย...ทีมให้
                     เป็นการเพิ่มการตั้งค่าเช่นเดียวกับ Department" -- outsourcing company's own project/
                     client team grouping, same CRUD pattern as Department/Position/Rank above (see
                     CompanyProfileModel::structureConfig()'s 'team' entry + company-profile.js's
                     formSchemas.team for the generic dispatcher wiring). Moved before Permissions
                     (explicit follow-up: "ในหน้าตั้งค่าย้ายทีมมาไว้ก่อน permission") -- purely a DOM
                     reorder, `data-page="p7"`/`id="structure-tab-p7"` untouched on purpose so
                     company-profile.js's initStructure() switch(page) dispatch needs no change at
                     all; only visual tab order moved. -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="structure-tab-p7" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="false" data-page="p7">
                        <i class="fa-solid fa-people-group me-2"></i><span data-i18n="team">Team</span>
                    </button>
                </li>
                <!-- 2026-08-31, explicit request: "สิทธิ์การใช้งาน...อยากให้แยกออกมาเป็นอีก Menu ไปเลย" --
                     the actual RBAC Permission Matrix moved out to its own standalone top-level
                     page (setup/permissions). This pill KEPT its id="structure-tab-p6"/data-page="p6"
                     unchanged (same "don't renumber, just relabel" precedent Team's own reorder
                     already established) but now shows ONLY the Notification Preferences by Role
                     section that happened to share the same tab/template before this split --
                     unrelated to RBAC, a role-level notification default, not moved. -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link structure-menu" id="structure-tab-p6" type="button" role="tab" aria-controls="structure-pane-content" aria-selected="false" data-page="p6">
                        <i class="fa-solid fa-bell me-2"></i><span data-i18n="notification_role_matrix_title">Notification Preferences by Role</span>
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
                    <th data-i18n="status">Status</th>
                    <th data-i18n="branch_code">Branch Code</th>
                    <th data-i18n="branch_name">Branch Name</th>
                    <th data-i18n="tax_branch_id">Tax Branch ID</th>
                    <th data-i18n="sso_branch_code">SSO Code</th>
                    <th data-i18n="default">Default</th>
                    <th data-i18n="location">Location</th>
                    <th data-i18n="lock_stamp">Lock Stamp</th>
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
                    <th data-i18n="status">Status</th>
                    <th data-i18n="role_name">Role Name</th>
                    <th data-i18n="salary_access">Salary Access</th>
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
                    <th data-i18n="status">Status</th>
                    <th data-i18n="department_code">Department Code</th>
                    <th data-i18n="department_name">Department Name</th>
                    <th data-i18n="cost_center">Cost Center</th>
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
                    <th data-i18n="status">Status</th>
                    <th data-i18n="position_code">Position Code</th>
                    <th data-i18n="position_name">Position Name</th>
                    <th data-i18n="allowance_base">Allowance (Base)</th>
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
                    <th data-i18n="status">Status</th>
                    <th data-i18n="rank_code">Rank Code</th>
                    <th data-i18n="rank_name">Rank Name</th>
                    <th data-i18n="salary_range">Salary Range (Min - Max)</th>
                    <th data-i18n="ot_eligible">OT Eligible</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>

<!-- 2026-08-31: was tmpl-permission-pane -- the RBAC Permission Matrix section that used to sit
     above the Notification Preferences section below moved out to its own standalone page
     (app/views/setup/permissions.php), since it's an unrelated concern that only shared this tab
     by convenience (see this pill's own comment above). Renamed for clarity now that this
     template's only remaining content is notification defaults, not permissions. -->
<template id="tmpl-notification-role-pane">
    <!-- 2026-08-29, explicit follow-up request: "ทำ Notification Settings...ผูกกับ user preference ใน
         ระดับ role ได้ด้วยถ้าไม่ซับซ้อนเกินไป" -- a checked cell means that role receives this
         notification type BY DEFAULT -- an individual's own personal Settings-modal preference, if
         they ever set one, still wins (see NotificationModel::shouldNotify()'s own docblock). -->
    <div class="mt-5 mb-5">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h6 class="mb-1 text-secondary fw-bold"><i class="fa-solid fa-bell me-2 text-brand"></i><span data-i18n="notification_role_matrix_title">Notification Preferences by Role</span></h6>
                <p class="text-muted small mb-0" data-i18n="notification_role_matrix_hint">Check the boxes to set which notification types each role receives by default. An employee's own personal Notification Settings, if set, always take priority over this.</p>
            </div>
            <button type="button" class="btn btn-primary btn-sm" id="btnSaveNotificationRoleMatrix">
                <i class="fa-solid fa-check me-1"></i><span data-i18n="save">Save</span>
            </button>
        </div>
        <div id="notificationRoleMatrixContainer" class="table-responsive"></div>
    </div>
</template>
<template id="tmpl-team-pane">
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_team">
            <thead>
                <tr>
                    <th data-i18n="status">Status</th>
                    <th data-i18n="team_code">Team Code</th>
                    <th data-i18n="team_name">Team Name</th>
                    <th data-i18n="team_client_name">Client / Project</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<!-- cpSignaturePadModal / orgStructureSyncModal / orgStructureSyncLogModal moved to
     app/views/layout/modals.php (2026-08-30, modal consolidation). -->

<script src="<?=asset('public/js/setup/company-profile.js')?>"></script>
<script src="<?=asset('public/js/setup/structure-assign.js')?>"></script>
<script src="<?=asset('public/js/setup/notification-preferences-matrix.js')?>"></script>
<script src="<?=asset('public/js/setup/org-structure-sync.js')?>"></script>