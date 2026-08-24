<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-parent" data-i18n="payslip_menu">Payslip</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current" data-i18n="settings">Settings</span>
    </h5>
  </nav>
  <div class="page-header-card mb-4">
    <div class="page-header-card-icon"><i class="fa-solid fa-file-invoice"></i></div>
    <div class="page-header-card-body">
      <h5 class="page-header-card-title" data-i18n="payslip_settings">Payslip Settings</h5>
      <p class="page-header-card-desc" data-i18n="payslip_settings_description">Configure the payslip template and how payslips get distributed to employees.</p>
    </div>
  </div>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-tpl" type="button" id="payslipTemplateTabBtn"><i class="fa-solid fa-file-invoice me-1"></i> <span data-i18n="payslip_template">Payslip Template</span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-dist" type="button" id="payslipDistributionTabBtn"><i class="fa-solid fa-paper-plane me-1"></i> <span data-i18n="payslip_distribution">Payslip Distribution</span></button></li>
  </ul>

  <div class="tab-content">
    <!-- PAYSLIP TEMPLATE -->
    <div class="tab-pane fade show active p-0" id="tab-tpl">
      <div class="card-surface p-3 p-md-4">
        <table class="table table-hover align-middle w-100" id="tb_payslip_template">
          <thead class="table-light text-secondary">
            <tr>
              <th data-i18n="template_name">Template Name</th>
              <th data-i18n="default">Default</th>
              <th data-i18n="language">Language</th>
              <th data-i18n="payslip_fields">Fields</th>
              <th data-i18n="status">Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- PAYSLIP DISTRIBUTION -->
    <div class="tab-pane fade p-0" id="tab-dist">
      <div class="card-surface p-3 p-md-4">
        <form id="payslipDistributionForm">
          <div class="row g-3 mb-2">
            <div class="col-md-5">
              <label class="form-label" data-i18n="distribution_mode">Distribution Mode</label>
              <select class="form-select select2-static" id="pd_mode"
                data-option-keys="mode_auto,mode_request_only,mode_both" data-option-values="auto,request_only,both"></select>
              <div class="text-secondary small mt-1" data-i18n="distribution_mode_hint">Auto = sent automatically when a run reaches Paid. Request-only = employee/HR must request each time. Both = either can happen.</div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="pd_is_active" checked>
                <label class="form-check-label" for="pd_is_active" data-i18n="enable_distribution">Enable payslip distribution</label>
              </div>
            </div>
          </div>

          <div id="pd_auto_settings_block">
            <hr>
            <h6 class="fw-bold mb-1" data-i18n="auto_send_settings">Auto-send Settings</h6>
            <div class="row g-3 mb-2">
              <div class="col-md-6">
                <label class="form-label" data-i18n="delivery_channels">Delivery Channels &amp; Fallback Order</label>
                <select class="form-select select2-remote" id="pd_channels" multiple data-api="/api/payslip-distribution.channel-options"></select>
                <div class="text-secondary small mt-1" data-i18n="delivery_channels_hint">Order you pick them in = attempt order (first = primary, rest = fallback if it fails).</div>
              </div>
              <div class="col-md-3">
                <label class="form-label" data-i18n="send_delay_hours">Send Delay (Hours)</label>
                <input type="number" class="form-control" id="pd_delay_hours" min="0" step="1" value="0">
                <div class="text-secondary small mt-1" data-i18n="send_delay_hours_hint">0 = send immediately when the run becomes Paid.</div>
              </div>
            </div>
            <div class="row g-3 mb-2">
              <div class="col-md-6">
                <label class="form-label" data-i18n="scope_departments">Departments</label>
                <select class="form-select select2-remote" id="pd_scope_departments" multiple data-api="/api/department.get" data-type="department"></select>
                <div class="text-secondary small mt-1" data-i18n="scope_leave_empty_hint">Leave empty = no restriction (applies to all).</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" data-i18n="scope_employment_statuses">Employment Statuses</label>
                <select class="form-select select2-static" id="pd_scope_statuses" multiple
                  data-option-keys="employment_status_probation,employment_status_permanent,employment_status_contract,employment_status_resigned,employment_status_terminated"
                  data-option-values="probation,permanent,contract,resigned,terminated"></select>
                <div class="text-secondary small mt-1" data-i18n="scope_leave_empty_hint">Leave empty = no restriction (applies to all).</div>
              </div>
            </div>
          </div>

          <hr>
          <div class="text-end">
            <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Payslip Template editor modal -->
  <div class="modal fade" id="payslipTemplateModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="payslipTemplateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-secondary" id="payslipTemplateModalLabel">
            <i class="fa-solid fa-file-invoice me-2"></i><span data-i18n="payslip_template">Payslip Template</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="payslipTemplateForm">
          <input type="hidden" id="pt_id" name="id">
          <div class="modal-body">
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label"><span data-i18n="template_name_th">Template Name (Thai)</span> <span class="text-danger">*</span></label>
                <input type="text" class="form-control required" id="pt_name_th" maxlength="150">
              </div>
              <div class="col-md-6">
                <label class="form-label"><span data-i18n="template_name_en">Template Name (English)</span> <span class="text-danger">*</span></label>
                <input type="text" class="form-control required" id="pt_name_en" maxlength="150">
              </div>
              <div class="col-md-4">
                <label class="form-label" data-i18n="language">Language</label>
                <select class="form-select select2-static" id="pt_language_mode" data-option-keys="language_th,language_en,language_both" data-option-values="th,en,both"></select>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" id="pt_is_default">
                  <label class="form-check-label" for="pt_is_default" data-i18n="set_as_default_template">Set as default template</label>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" id="pt_status" checked>
                  <label class="form-check-label" for="pt_status" data-i18n="enable_this_template">Enable this template</label>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label" data-i18n="company_logo">Company Logo</label>
                <input type="file" class="form-control" id="pt_logo_file" accept="image/png,image/jpeg,image/svg+xml">
                <input type="hidden" id="pt_logo_path">
                <div class="mt-2"><img id="pt_logo_preview" src="" alt="" style="max-height:60px;display:none;" class="border rounded p-1"></div>
              </div>
              <div class="col-md-6">
                <label class="form-label" data-i18n="header_text">Header Text</label>
                <input type="text" class="form-control mb-2" id="pt_header_th" data-i18n="header_text_th_placeholder" placeholder="ข้อความหัวกระดาษ (ไทย)" maxlength="500">
                <input type="text" class="form-control" id="pt_header_en" placeholder="Header text (English)" maxlength="500">
              </div>
              <div class="col-md-6">
                <label class="form-label" data-i18n="footer_text">Footer Text</label>
                <input type="text" class="form-control" id="pt_footer_th" data-i18n="footer_text_th_placeholder" placeholder="ข้อความท้ายกระดาษ (ไทย)" maxlength="500">
              </div>
              <div class="col-md-6">
                <label class="form-label">&nbsp;</label>
                <input type="text" class="form-control" id="pt_footer_en" placeholder="Footer text (English)" maxlength="500">
              </div>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h6 class="fw-bold mb-0" data-i18n="payslip_fields">Payslip Fields</h6>
                <div class="text-secondary small" data-i18n="payslip_fields_hint">Drag to reorder. Only fields in this list appear on the payslip.</div>
              </div>
              <div class="d-flex gap-2">
                <select class="form-select form-select-sm select2-remote" id="pt_add_field_select" style="min-width:260px;" data-api="/api/payslip-template.field-options" data-type="payslip_field"></select>
                <button type="button" class="btn btn-outline-secondary btn-sm text-nowrap" id="btnAddPayslipField"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add">Add</span></button>
              </div>
            </div>
            <div id="payslipFieldList"></div>
            <div class="text-center text-secondary small py-3 d-none" id="noPayslipFieldsMessage" data-i18n="no_fields_yet">No fields yet — add at least one.</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary px-4 me-auto" id="btnPreviewPayslip"><i class="fa-solid fa-eye me-1"></i><span data-i18n="preview">Preview</span></button>
            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
            <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="<?=asset('public/js/setup/payslip-template.js')?>"></script>
<script src="<?=asset('public/js/setup/payslip-distribution.js')?>"></script>
