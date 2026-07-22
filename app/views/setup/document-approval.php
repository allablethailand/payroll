<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-3">
      <span><i class="fas fa-home me-1"></i> Payroll</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span>Settings</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current">อนุมัติ &amp; เอกสาร</span>
    </h5>
  </nav>

  <div class="settings-nav">
    <a href="#">ตั้งค่าบริษัท</a><a href="#">รอบการจ่าย</a><a href="#">รายได้ / รายหัก</a>
    <a href="#">บัญชีธนาคาร</a><a href="#">สิทธิ์ผู้ใช้งาน</a><a href="#">ภาษี &amp; กองทุน</a>
    <a href="#">โครงสร้างองค์กร</a><a href="#">เวลาทำงาน &amp; วันลา</a>
    <a class="active" href="#">อนุมัติ &amp; เอกสาร</a><a href="#">การแจ้งเตือน</a>
  </div>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-flow" type="button"><i class="fa-solid fa-diagram-project me-1"></i> <span data-i18n="approval_workflow">Approval Workflow</span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-monitor" type="button" id="approvalMonitorTabBtn"><i class="fa-solid fa-list-check me-1"></i> <span data-i18n="approval_monitor">Monitor</span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-run" type="button"><i class="fa-solid fa-hashtag me-1"></i> เลขที่เอกสาร</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tpl" type="button" id="payslipTemplateTabBtn"><i class="fa-solid fa-file-invoice me-1"></i> เทมเพลตสลิปเงินเดือน</button></li>
  </ul>

  <div class="tab-content">
    <!-- APPROVAL WORKFLOW -->
    <div class="tab-pane fade show active p-0" id="tab-flow">
      <div class="card-surface p-3 p-md-4">
        <table class="table table-hover align-middle w-100" id="tb_approval_workflow">
          <thead class="table-light text-secondary">
            <tr>
              <th data-i18n="workflow_name">Workflow Name</th>
              <th data-i18n="document_types">Document Types</th>
              <th data-i18n="steps">Steps</th>
              <th data-i18n="status">Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- APPROVAL MONITOR -->
    <div class="tab-pane fade p-0" id="tab-monitor">
      <div class="card-surface p-3 p-md-4">
        <div class="row mb-3 g-2">
          <div class="col-sm-3">
            <label class="form-label mb-1 small" data-i18n="status">Status</label>
            <select class="form-select select2-static" id="monitor_filter_status" data-option-keys="status_pending,status_approved,status_rejected,status_cancelled" data-option-values="pending,approved,rejected,cancelled"></select>
          </div>
          <div class="col-sm-4">
            <label class="form-label mb-1 small" data-i18n="document_types">Document Types</label>
            <select class="form-select select2-remote" id="monitor_filter_document_type" data-api="/api/approval-workflow.document-type-options"></select>
          </div>
        </div>
        <table class="table table-hover align-middle w-100" id="tb_approval_monitor">
          <thead class="table-light text-secondary">
            <tr>
              <th data-i18n="document_types">Document Type</th>
              <th data-i18n="reference">Reference</th>
              <th data-i18n="workflow_name">Workflow</th>
              <th data-i18n="current_step">Current Step</th>
              <th data-i18n="status">Status</th>
              <th data-i18n="requested_by">Requested By</th>
              <th data-i18n="requested_at">Requested At</th>
              <th></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- RUNNING NUMBER -->
    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-run">
      <h6 class="fw-bold mb-3">รูปแบบเลขที่เอกสาร</h6>
      <table class="table pl-table mb-0">
        <thead><tr><th>ประเภทเอกสาร</th><th>รูปแบบ (Prefix)</th><th class="text-end">จำนวนหลัก</th><th class="text-end">เลขที่ปัจจุบัน</th><th>รีเซ็ต</th><th></th></tr></thead>
        <tbody>
          <tr><td class="fw-bold">สลิปเงินเดือน</td><td>PS-{YYYY}{MM}-</td><td class="text-end">4</td><td class="text-end">0128</td><td>รายเดือน</td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">งวดเงินเดือน (Payroll Run)</td><td>PR-{YYYY}-</td><td class="text-end">3</td><td class="text-end">003</td><td>รายปี</td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">หนังสือรับรองการหักภาษี (50 ทวิ)</td><td>WHT-{YYYY}-</td><td class="text-end">4</td><td class="text-end">0000</td><td>รายปี</td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">ไฟล์โอนเงินธนาคาร</td><td>BT-{YYYYMMDD}-</td><td class="text-end">3</td><td class="text-end">012</td><td>ไม่รีเซ็ต</td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>

    <!-- PAYSLIP TEMPLATE -->
    <div class="tab-pane fade p-0" id="tab-tpl">
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
  </div>

  <!-- Approval Workflow editor modal -->
  <div class="modal fade" id="workflowModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="workflowModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-secondary" id="workflowModalLabel">
            <i class="fa-solid fa-pen-to-square me-2"></i><span data-i18n="approval_workflow">Approval Workflow</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="workflowForm">
          <input type="hidden" id="workflow_id" name="id">
          <div class="modal-body">
            <div class="row mb-3">
              <div class="col-sm-3 align-self-center">
                <label class="form-label mb-0"><span data-i18n="workflow_name">Workflow Name</span> <span class="text-danger">*</span></label>
              </div>
              <div class="col-sm-9">
                <input type="text" class="form-control required" id="workflow_name" name="workflow_name" maxlength="150">
              </div>
            </div>
            <div class="row mb-3">
              <div class="col-sm-3 align-self-center">
                <label class="form-label mb-0" data-i18n="description">Description</label>
              </div>
              <div class="col-sm-9">
                <textarea class="form-control" id="workflow_description" name="description" rows="2" maxlength="500"></textarea>
              </div>
            </div>
            <div class="row mb-3">
              <div class="col-sm-3 align-self-center">
                <label class="form-label mb-0"><span data-i18n="document_types">Document Types</span> <span class="text-danger">*</span></label>
              </div>
              <div class="col-sm-9">
                <select class="form-select select2-remote required" id="workflow_document_types" name="document_type_codes" multiple data-api="/api/approval-workflow.document-type-options"></select>
              </div>
            </div>
            <div class="row mb-4">
              <div class="col-sm-3 align-self-center">
                <label class="form-label mb-0" data-i18n="status">Status</label>
              </div>
              <div class="col-sm-3">
                <select class="form-select select2-static" id="workflow_status" name="status" data-option-keys="active,inactive" data-option-values="active,inactive"></select>
              </div>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h6 class="fw-bold mb-0" data-i18n="approval_steps">Approval Steps</h6>
                <div class="text-secondary small" data-i18n="approval_steps_hint">Drag to reorder. Each step's approver is either one specific user or anyone holding a role.</div>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddStep"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_step">Add Step</span></button>
            </div>
            <div id="stepList"></div>
            <div class="text-center text-secondary small py-3 d-none" id="noStepsMessage" data-i18n="no_steps_yet">No steps yet — add at least one.</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
            <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Approval Request detail modal -->
  <div class="modal fade" id="requestDetailModal" tabindex="-1" aria-labelledby="requestDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-secondary" id="requestDetailModalLabel">
            <i class="fa-solid fa-list-check me-2"></i><span data-i18n="approval_request_detail">Request Detail</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="requestDetailSummary" class="mb-3"></div>
          <h6 class="fw-bold" data-i18n="approval_history">History</h6>
          <div id="requestDetailTimeline"></div>
          <div id="requestActionArea" class="mt-3 d-none">
            <hr>
            <div class="mb-2">
              <label class="form-label small" data-i18n="note_optional">Note (optional)</label>
              <textarea id="requestActionNote" class="form-control" rows="2" maxlength="500"></textarea>
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-success btn-sm" id="btnApproveRequest"><i class="fa-solid fa-check me-1"></i><span data-i18n="approve">Approve</span></button>
              <button type="button" class="btn btn-outline-danger btn-sm" id="btnRejectRequest"><i class="fa-solid fa-xmark me-1"></i><span data-i18n="reject">Reject</span></button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCancelRequest"><i class="fa-solid fa-ban me-1"></i><span data-i18n="cancel_request">Cancel Request</span></button>
            </div>
          </div>
        </div>
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
<script src="<?=asset('public/js/setup/approval-workflow.js')?>"></script>
<script src="<?=asset('public/js/setup/payslip-template.js')?>"></script>