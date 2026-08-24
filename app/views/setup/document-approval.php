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

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-flow" type="button"><i class="fa-solid fa-diagram-project me-1"></i> <span data-i18n="approval_workflow">Approval Workflow</span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-run" type="button"><i class="fa-solid fa-hashtag me-1"></i> <span data-i18n="document_running_number">Document Numbering</span></button></li>
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

    <!-- RUNNING NUMBER (2026-08-23, explicit request: "ยังไม่สามารถตั้งค่าได้จริง" -- was a static
         mockup table with no backend at all; now a real per-company settings grid (fixed 4 rows,
         no add/delete -- document_type_code is code-tied, see DocumentNumberingModel's own
         docblock), matching the Permission Matrix's "fixed grid, not a DataTable" precedent. -->
    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-run">
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
            <div class="awf-basic-grid">
              <div class="awf-field awf-field-full">
                <label for="workflow_name"><span data-i18n="workflow_name">Workflow Name</span> <span class="text-danger">*</span></label>
                <input type="text" class="form-control required" id="workflow_name" name="workflow_name" maxlength="150">
              </div>
              <div class="awf-field awf-field-full">
                <label for="workflow_description" data-i18n="description">Description</label>
                <textarea class="form-control" id="workflow_description" name="description" rows="2" maxlength="500"></textarea>
              </div>
              <div class="awf-field">
                <label for="workflow_document_types"><span data-i18n="document_types">Document Types</span> <span class="text-danger">*</span></label>
                <select class="form-select select2-remote required" id="workflow_document_types" name="document_type_codes" multiple data-api="/api/approval-workflow.document-type-options"></select>
              </div>
              <div class="awf-field">
                <label for="workflow_status" data-i18n="status">Status</label>
                <select class="form-select select2-static" id="workflow_status" name="status" data-option-keys="active,inactive" data-option-values="active,inactive"></select>
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="awf-section-title">
                <span class="awf-section-icon"><i class="fa-solid fa-diagram-project"></i></span>
                <div>
                  <h6 class="fw-bold" data-i18n="approval_steps">Approval Steps</h6>
                  <div class="text-secondary small" data-i18n="approval_steps_hint">Drag to reorder. Each step's approver is either one specific user or anyone holding a role.</div>
                </div>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" id="btnAddStep"><i class="fa-solid fa-plus me-1"></i><span data-i18n="add_step">Step</span></button>
            </div>
            <div class="awf-step-list" id="stepList"></div>
            <div class="text-center text-secondary small py-4 d-none" id="noStepsMessage">
              <i class="fa-solid fa-diagram-project fa-lg mb-2 d-block text-secondary opacity-50"></i>
              <span data-i18n="no_steps_yet">No steps yet — add at least one.</span>
            </div>
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