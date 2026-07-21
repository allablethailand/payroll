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
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tpl" type="button"><i class="fa-solid fa-file-invoice me-1"></i> เทมเพลตสลิปเงินเดือน</button></li>
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
        <div class="text-center text-secondary py-4" data-i18n="approval_monitor_coming_soon">The monitor page will be built in the next phase.</div>
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
    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-tpl">
      <h6 class="fw-bold mb-3">เลือกเทมเพลตสลิปเงินเดือน</h6>
      <div class="row g-3 mb-4">
        <div class="col-sm-4">
          <div class="tpl-card selected" onclick="selectTpl(this)">
            <i class="fa-solid fa-file-lines fa-2x text-brand mb-2"></i>
            <div class="fw-bold">Standard</div>
            <div class="text-secondary small">แสดงรายได้-รายหักหลัก</div>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="tpl-card" onclick="selectTpl(this)">
            <i class="fa-solid fa-file-invoice fa-2x text-secondary mb-2"></i>
            <div class="fw-bold">Detailed</div>
            <div class="text-secondary small">แสดงทุกรายการ + สะสมทั้งปี (YTD)</div>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="tpl-card" onclick="selectTpl(this)">
            <i class="fa-solid fa-file fa-2x text-secondary mb-2"></i>
            <div class="fw-bold">Compact</div>
            <div class="text-secondary small">สรุปย่อ 1 หน้า สำหรับพิมพ์จำนวนมาก</div>
          </div>
        </div>
      </div>
      <h6 class="fw-bold mb-3">ตัวเลือกการแสดงผล</h6>
      <div class="row">
        <div class="col-sm-6 mt-2"><input type="checkbox" class="me-2" checked> แสดงโลโก้บริษัท</div>
        <div class="col-sm-6 mt-2"><input type="checkbox" class="me-2" checked> แสดงรายละเอียดเบี้ยเลี้ยง/ค่าตำแหน่ง</div>
        <div class="col-sm-6 mt-2"><input type="checkbox" class="me-2"> แสดงยอดสะสมทั้งปี (YTD)</div>
        <div class="col-sm-6 mt-2"><input type="checkbox" class="me-2" checked> แสดงเลขบัญชีธนาคาร (4 ตัวท้าย)</div>
      </div>
      <div class="row mt-3">
        <div class="col-sm-3"><label class="form-label">ภาษาในสลิป</label></div>
        <div class="col-sm-4">
          <select class="form-select"><option>ไทย</option><option>English</option><option>ไทย + English</option></select>
        </div>
      </div>
      <div class="d-flex justify-content-end mt-4 gap-2">
        <button class="btn btn-outline-brand" onclick="mockToast('เปิดตัวอย่างสลิปเงินเดือน (mock)')"><i class="fa-solid fa-eye me-1"></i> ดูตัวอย่าง</button>
        <button class="btn btn-brand">บันทึก</button>
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
</div>
<script>
function selectTpl(el){ $('.tpl-card').removeClass('selected'); $(el).addClass('selected'); }
function mockToast(msg){ const t=$(`<div class="mock-tag" style="right:auto;left:16px;background:var(--brand);">${msg}</div>`); $('body').append(t); setTimeout(()=>t.fadeOut(400,()=>t.remove()),2200); }
</script>
<script src="<?=asset('public/js/setup/approval-workflow.js')?>"></script>