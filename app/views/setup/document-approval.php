<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-parent" data-i18n="settings">Settings</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current" data-i18n="document_and_approval">Document &amp; Approval</span>
    </h5>
  </nav>
  <!-- .page-header-card rollout (2026-08-21, explicit request -- see the matching comment in
       app/views/payroll/index.php). -->
  <div class="page-header-card mb-4">
    <div class="page-header-card-icon"><i class="fa-solid fa-file-signature"></i></div>
    <div class="page-header-card-body">
      <h5 class="page-header-card-title" data-i18n="document_and_approval">Document &amp; Approval</h5>
      <p class="page-header-card-desc" data-i18n="document_and_approval_description">Configure approval workflows and document numbering for payroll documents.</p>
    </div>
  </div>

  <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link setup-menu active" data-bs-toggle="tab" data-bs-target="#tab-flow" type="button" role="tab"><i class="fa-solid fa-diagram-project me-1"></i> <span data-i18n="approval_workflow">Approval Workflow</span></button></li>
    <li class="nav-item"><button class="nav-link setup-menu" data-bs-toggle="tab" data-bs-target="#tab-run" type="button" role="tab"><i class="fa-solid fa-hashtag me-1"></i> <span data-i18n="document_running_number">Document Numbering</span></button></li>
  </ul>

  <div class="tab-content">
    <!-- APPROVAL WORKFLOW (2026-08-24 redesign, explicit request: "ให้แบ่งเป็น Tab อนุมัติงวดเงินเดือน
         และ อนุมัติ Pay Slip ไปเลยให้ตั้งค่า และในแต่ละ Tab ก็ให้จัดการได้เลย 1 Tab ต่อ 1 Flow ไม่ต้องเปิด
         Modal เข้าไปจัดการ แต่เป็นการเปิดแก้ไข แถว by แถว มีปุ่ม Save แยกตามแถว และมีปุ่มในการบันทึก Sort" --
         replaces the old generic DataTable-of-workflows + modal editor. Each pill below IS one
         document type's one flow (no workflow picker, no document-type multi-select, no
         workflow_name field -- see ApprovalWorkflowModel::getByDocumentType()'s own docblock for
         the full reasoning); steps render as in-page rows, edited/saved/deleted one row at a time,
         mirroring C:\xampp\htdocs\origami\controls\approval\views\approval.php's own row-level
         save/cancel/delete + separate drag-then-explicit-save-order pattern. -->
    <div class="tab-pane fade show active p-0" id="tab-flow">
      <!-- 2026-08-24, explicit request: "ปรับให้ tab เป็น Design เดียวกันทั้งหมด เหมือนในหน้าตั้งค่า
           โครงสร้างบริษัท" -- reuses company-profile.php's own Organizational Structure sub-tab
           classes (.structure-tabs-wrap/.structure-tabs/.structure-menu) verbatim instead of the
           bespoke .awf-doctype-pills gradient style from the previous redesign, so every pill-style
           sub-tab in the app looks identical. -->
      <div class="bg-light rounded-3 p-2 mb-3 structure-tabs-wrap">
        <ul class="nav nav-pills flex-nowrap scrollable-tabs structure-tabs" id="approvalFlowDocTypeTabs">
          <li class="nav-item">
            <button type="button" class="nav-link structure-menu active" data-document-type="PAYROLL_RUN_APPROVAL">
              <i class="fa-solid fa-money-check-dollar me-1"></i><span data-i18n="tab_payroll_run_approval">Payroll Run Approval</span>
            </button>
          </li>
          <li class="nav-item">
            <button type="button" class="nav-link structure-menu" data-document-type="SLIP_REQUEST_APPROVAL">
              <i class="fa-solid fa-file-invoice me-1"></i><span data-i18n="tab_slip_request_approval">Payslip Approval</span>
            </button>
          </li>
          <!-- 2026-08-24, explicit request: "ใน Approval Flow เพิ่มอีก Tab เป็น Tab การตั้งค่าการอนุมัติการ
               ขอใบรับรอง" -- 3rd pill, same generic engine (ApprovalWorkflowModel::getByDocumentType()/
               stepSave() etc. are fully DB-driven off `approval_document_types`, no code change needed
               beyond seeding the new row -- see database/payroll.sql's own comment on this migration).
               Config-only for now: there is still no request/issuance flow for certificates at all, so
               a workflow built here has no caller yet (same "built ahead of its consumer" situation as
               Holiday's resolver) -- once that flow exists it'll call ApprovalRequestModel::create()
               with this code. -->
          <li class="nav-item">
            <button type="button" class="nav-link structure-menu" data-document-type="EMPLOYMENT_CERTIFICATE_APPROVAL">
              <i class="fa-solid fa-file-shield me-1"></i><span data-i18n="tab_employment_certificate_approval">Employment Certificate Approval</span>
            </button>
          </li>
        </ul>
      </div>

      <div class="card-surface">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div class="awf-flow-status-wrap">
            <span class="badge" id="flowStatusBadge"></span>
            <button type="button" class="btn btn-link btn-sm p-0 ms-2 d-none" id="btnToggleFlowStatus"></button>
            <div class="text-secondary small mt-1" id="flowStatusHint"></div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="text-warning small fw-semibold d-none" id="sortUnsavedHint"><i class="fa-solid fa-triangle-exclamation me-1"></i><span data-i18n="sort_unsaved_hint">Order changed — not saved yet</span></span>
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnSaveSort"><i class="fa-solid fa-arrow-down-short-wide me-1"></i><span data-i18n="save_sort">Save Order</span></button>
            <button type="button" class="btn btn-primary btn-sm" id="btnAddFlowStep"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_step">Step</span></button>
          </div>
        </div>
        <div class="awf-step-list" id="flowStepList"></div>
        <div class="text-center text-secondary small py-4 d-none" id="flowNoStepsMessage">
          <i class="fa-solid fa-diagram-project fa-lg mb-2 text-secondary opacity-50"></i>
          <span data-i18n="flow_not_configured_yet">This approval flow hasn't been set up yet — click "Step" to add the first one.</span>
        </div>
      </div>
    </div>

    <!-- RUNNING NUMBER (2026-08-23, explicit request: "ยังไม่สามารถตั้งค่าได้จริง" -- was a static
         mockup table with no backend at all; now a real per-company settings grid (fixed 4 rows,
         no add/delete -- document_type_code is code-tied, see DocumentNumberingModel's own
         docblock), matching the Permission Matrix's "fixed grid, not a DataTable" precedent. -->
    <div class="tab-pane fade" id="tab-run">
      <h6 class="fw-bold mb-3" data-i18n="document_running_number_format">Document Number Format</h6>
      <table class="table pl-table mb-0">
        <thead>
          <tr>
            <th data-i18n="doc_numbering_type">Document Type</th>
            <th data-i18n="doc_numbering_prefix">Format (Prefix)</th>
            <th class="text-end" data-i18n="doc_numbering_digits">Digits</th>
            <th class="text-end" data-i18n="doc_numbering_current">Current Number</th>
            <th data-i18n="doc_numbering_reset">Reset</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="documentNumberingBody"></tbody>
      </table>
    </div>

  </div>

  <!-- Document Numbering edit modal -->
  <div class="modal fade" id="documentNumberingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="documentNumberingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-secondary" id="documentNumberingModalLabel">
            <i class="fa-solid fa-hashtag me-2"></i><span id="documentNumberingModalTypeLabel"></span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="documentNumberingForm">
          <input type="hidden" id="dn_document_type_code">
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label"><span data-i18n="doc_numbering_prefix">Format (Prefix)</span> <span class="text-danger">*</span></label>
              <input type="text" class="form-control required" id="dn_prefix_format" maxlength="50">
              <div class="text-secondary small mt-1" data-i18n="doc_numbering_prefix_hint">Placeholders: {YYYY} = year, {MM} = month, {YYYYMMDD} = full date.</div>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-sm-6">
                <label class="form-label"><span data-i18n="doc_numbering_digits">Digits</span> <span class="text-danger">*</span></label>
                <input type="number" class="form-control required" id="dn_digit_count" min="1" max="10" step="1">
              </div>
              <div class="col-sm-6">
                <label class="form-label"><span data-i18n="doc_numbering_current">Current Number</span> <span class="text-danger">*</span></label>
                <input type="number" class="form-control required" id="dn_current_number" min="0" step="1">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label" data-i18n="doc_numbering_reset">Reset</label>
              <select class="form-select select2-static" id="dn_reset_cycle" data-option-keys="reset_cycle_never,reset_cycle_yearly,reset_cycle_monthly" data-option-values="never,yearly,monthly"></select>
            </div>
            <div class="text-secondary small" id="documentNumberingPreview"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" data-i18n="cancel">Cancel</button>
            <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>
<script src="<?=asset('public/js/setup/approval-workflow.js')?>"></script>
<script src="<?=asset('public/js/setup/document-numbering.js')?>"></script>