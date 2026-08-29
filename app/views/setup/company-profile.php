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
        <!-- 2026-08-24, explicit request: "ปรับให้ Logo Upload อยู่ยนสุดของ Form ขอ Design สวยๆ รวมถึงมี
             Preview ด้วย" -- moved from section 1 to its own numbered section 4 at the very bottom of
             the form (was inline with Company Information before), redesigned as a proper upload card
             instead of a plain button+small-preview row. Same upload endpoint/hidden-field-into-save
             convention as before, nothing changed on the backend. -->
        <h6 class="text-secondary fw-bold mb-3 mt-4">
            <label class="label label-head bg-head-first rounded-2 text-white me-2">4</label>
            <span data-i18n="company_logo">Company Logo</span>
        </h6>
        <div class="row">
            <div class="col-sm-6 mt-3">
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
                        <p class="text-muted small mt-2 mb-0" data-i18n="company_logo_reuse_hint">Used as the default logo on Payslip and Employment Certificate templates that don't have their own.</p>
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
        <h6 class="text-secondary fw-bold mb-3 mt-4">
            <label class="label label-head bg-head-first rounded-2 text-white me-2">5</label>
            <span data-i18n="company_signature">Authorized Signature</span>
        </h6>
        <div class="row">
            <div class="col-sm-6 mt-3">
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
                        <p class="text-muted small mt-2 mb-0" data-i18n="company_signature_reuse_hint">Available as the "Authorized Signature" item when designing Payslip and Employment Certificate templates.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="text-end">
        <button type="button" class="btn btn-warning save-company-profile"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
        <button type="button" class="btn btn-light cancel-company-profile" data-i18n="cancel">Cancel</button>
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
                            <input type="text" class="form-control form-control-sm" id="bffDelimiterChar" maxlength="5" value=",">
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
                        <button type="button" class="btn btn-warning btn-sm" id="bffSaveConfigBtn"><i class="fa-solid fa-floppy-disk me-1"></i><span data-i18n="save">Save</span></button>
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
<div class="modal fade" id="bffFieldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" data-i18n="add_field">Add Field</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bffFieldId">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" data-i18n="field_label_th">Label (Thai)</label>
                        <input type="text" class="form-control required" id="bffFieldLabelTh">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="field_label_en">Label (English)</label>
                        <input type="text" class="form-control required" id="bffFieldLabelEn">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="row_type">Row</label>
                        <select class="form-select" id="bffFieldRowType">
                            <option value="detail" data-i18n="row_type_detail">Detail (per employee)</option>
                            <option value="header" data-i18n="row_type_header">Header</option>
                            <option value="trailer" data-i18n="row_type_trailer">Trailer</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="order">Order</label>
                        <input type="number" class="form-control" id="bffFieldSortOrder" min="0" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="source_type">Source Type</label>
                        <select class="form-select" id="bffFieldSourceType">
                            <option value="employee_field" data-i18n="source_type_employee_field">Payroll Field</option>
                            <option value="constant" data-i18n="source_type_constant">Fixed Value</option>
                            <option value="blank" data-i18n="source_type_blank">Blank</option>
                        </select>
                    </div>
                    <div class="col-6" id="bffFieldSourceFieldWrap">
                        <label class="form-label" data-i18n="source">Source</label>
                        <select class="form-select" id="bffFieldSourceField"></select>
                    </div>
                    <div class="col-6 d-none" id="bffFieldConstantWrap">
                        <label class="form-label" data-i18n="constant_value">Fixed Value</label>
                        <input type="text" class="form-control" id="bffFieldConstantValue">
                    </div>
                    <div class="col-6">
                        <label class="form-label" data-i18n="data_type">Data Type</label>
                        <select class="form-select" id="bffFieldDataType">
                            <option value="text" data-i18n="data_type_text">Text</option>
                            <option value="number" data-i18n="data_type_number">Number</option>
                            <option value="date" data-i18n="data_type_date">Date</option>
                        </select>
                    </div>
                    <div class="col-6" id="bffFieldDecimalWrap">
                        <label class="form-label" data-i18n="decimal_places">Decimal Places</label>
                        <input type="number" class="form-control" id="bffFieldDecimalPlaces" min="0" max="6" value="2">
                    </div>
                    <div class="col-6 d-none" id="bffFieldDateFormatWrap">
                        <label class="form-label" data-i18n="date_format">Date Format</label>
                        <input type="text" class="form-control" id="bffFieldDateFormat" value="Ymd" placeholder="Ymd">
                    </div>
                    <div class="col-4">
                        <label class="form-label" data-i18n="width">Width</label>
                        <input type="number" class="form-control" id="bffFieldWidth" min="1">
                    </div>
                    <div class="col-4">
                        <label class="form-label" data-i18n="pad_char">Pad Char</label>
                        <input type="text" class="form-control" id="bffFieldPadChar" maxlength="1" value=" ">
                    </div>
                    <div class="col-4">
                        <label class="form-label" data-i18n="pad_direction">Pad Direction</label>
                        <select class="form-select" id="bffFieldPadDirection">
                            <option value="right" data-i18n="pad_direction_right">Right</option>
                            <option value="left" data-i18n="pad_direction_left">Left</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-warning" id="bffFieldSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="bffLogModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" data-i18n="edit_log">Edit Log</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bffLogModalBody"></div>
        </div>
    </div>
</div>
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
<template id="tmpl-team-pane">
    <div class="mt-5 mb-5 table-responsive">
        <table class="table table-striped table-hover" id="tb_team">
            <thead>
                <tr>
                    <th data-i18n="team_code">Team Code</th>
                    <th data-i18n="team_name">Team Name</th>
                    <th data-i18n="team_client_name">Client / Project</th>
                    <th data-i18n="status">Status</th>
                    <th style="width: 120px;"></th>
                </tr>
            </thead>
        </table>
    </div>
</template>
<!-- 2026-08-26, explicit request: "สามารถเซ็นต์สดผ่านหน้าจอได้" -- signature-pad modal. Lives OUTSIDE
     every <template> above (a <template>'s content is inert until cloned by JS, so a live
     bootstrap.Modal needs to sit in real page DOM instead) -- plain mouse/touch canvas drawing, no new
     dependency (same "no reason to add a library for basic bounding-box interaction" precedent
     Employment Certificate Template's own canvas designer already established). -->
<div class="modal fade" id="cpSignaturePadModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-secondary"><i class="fa-solid fa-pen-nib me-2"></i><span data-i18n="draw_signature">Draw Signature</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <canvas id="cpSignaturePadCanvas" class="cp-signature-pad-canvas" width="500" height="220"></canvas>
                <p class="text-muted small mt-2 mb-0" data-i18n="draw_signature_hint">Draw with your mouse or finger, then click Save.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="cpSignaturePadClearBtn"><i class="fa-solid fa-eraser me-1"></i><span data-i18n="clear">Clear</span></button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="cpSignaturePadSaveBtn"><span data-i18n="save">Save</span></button>
            </div>
        </div>
    </div>
</div>

<!-- Sync Department/Position/Team from Origami (2026-08-28, explicit request: "ส่วนของ Department
     หรือข้อมูลที่ดึง Filter ได้ตอนนี้ เพิ่มปุ่มให้ Sync ได้ด้วย") -- ONE shared modal for all 3 entity
     types (title/columns swapped by JS via #orgStructureSyncModalLabel/orgSyncCurrentEntityType),
     same review-first architecture and side-by-side New/Already-Exists layout as Employee/Holiday
     Sync (see OrgStructureSyncModel's own docblock). No filter row -- unlike Employee Sync, there's
     nothing to narrow by, so the modal fetches immediately on open. -->
<div class="modal fade" id="orgStructureSyncModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="orgStructureSyncModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="orgStructureSyncModalLabel">
                    <i class="fa-solid fa-rotate me-1"></i><span id="orgStructureSyncModalLabelText">Sync from Origami</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center py-5 d-none" id="orgStructureSyncNotConnected">
                    <i class="fa-solid fa-plug-circle-xmark fa-2x text-danger mb-3"></i>
                    <div class="fw-bold mb-1" data-i18n="employee_sync_not_connected_title">Not connected to Origami</div>
                    <div class="text-muted small" id="orgStructureSyncNotConnectedMessage" data-i18n="employee_sync_not_connected_message">The connection to Origami has not been configured yet. Please contact your system administrator.</div>
                </div>
                <div id="orgStructureSyncBody" class="d-none">
                    <div id="orgStructureSyncResultArea" class="d-none">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="d-flex align-items-center mb-2">
                                    <h6 class="mb-0 text-success"><span data-i18n="employee_sync_tab_new">New</span> <span class="badge bg-success ms-1" id="orgSyncNewCount">0</span></h6>
                                </div>
                                <div class="border rounded" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle w-100 mb-0" id="tb_org_sync_new">
                                        <thead class="table-light text-secondary" style="position: sticky; top: 0; z-index: 1;">
                                            <tr>
                                                <th style="width:3%;"><input type="checkbox" id="orgSyncNewSelectAll"></th>
                                                <th data-i18n="name">Name</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="d-flex align-items-center mb-2">
                                    <h6 class="mb-0 text-secondary"><span data-i18n="employee_sync_tab_existing">Already Exists</span> <span class="badge bg-secondary ms-1" id="orgSyncExistingCount">0</span></h6>
                                </div>
                                <div class="border rounded" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-hover table-sm align-middle w-100 mb-0" id="tb_org_sync_existing">
                                        <thead class="table-light text-secondary" style="position: sticky; top: 0; z-index: 1;">
                                            <tr>
                                                <th style="width:3%;"><input type="checkbox" id="orgSyncExistingSelectAll"></th>
                                                <th data-i18n="name">Name</th>
                                                <th data-i18n="employee_sync_update_col">Update Available</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-muted small text-center py-4" id="orgStructureSyncLoadingHint">
                        <i class="fa-solid fa-spinner fa-spin me-1"></i><span data-i18n="loading">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <span class="text-muted small" id="orgSyncSelectedCountLabel"></span>
                <div>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
                    <button type="button" class="btn btn-primary d-none" id="btnApplyOrgStructureSync">
                        <i class="fa-solid fa-download me-1"></i><span data-i18n="employee_sync_apply_button">Sync Selected</span> (<span id="orgSyncSelectedCount">0</span>)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Org Structure Sync Log -->
<div class="modal fade" id="orgStructureSyncLogModal" tabindex="-1" aria-labelledby="orgStructureSyncLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-secondary" id="orgStructureSyncLogModalLabel">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i><span id="orgStructureSyncLogModalLabelText">Sync Log</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-hover table-sm align-middle w-100" id="tb_org_structure_sync_log">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th data-i18n="employee_sync_log_col_date">Date</th>
                            <th data-i18n="employee_sync_log_col_triggered_by">By</th>
                            <th data-i18n="employee_sync_log_col_status">Status</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_total">Total</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_success">Success</th>
                            <th class="text-end" data-i18n="employee_sync_log_col_error">Error</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-i18n="close">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="<?=asset('public/js/setup/company-profile.js')?>"></script>
<script src="<?=asset('public/js/setup/permission-matrix.js')?>"></script>
<script src="<?=asset('public/js/setup/org-structure-sync.js')?>"></script>