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
    <li class="nav-item"><button class="nav-link setup-menu" id="emailQueueLogTabBtn" data-bs-toggle="tab" data-bs-target="#tab-email-log" type="button" role="tab"><i class="fa-solid fa-envelope-circle-check me-1"></i> <span data-i18n="email_queue_log">Email Log</span></button></li>
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
         mockup table with no backend at all; a real per-company settings grid (fixed 4 rows,
         no add/delete -- document_type_code is code-tied, see DocumentNumberingModel's own
         docblock) followed.
         2026-09-04, Backlog Phase 11, T061 ("redesigned as cards with example settings shown;
         simplify the form; wire into real document generation; support comp_code") -- the plain
         table + hidden-in-a-modal preview replaced by one .settings-info-card per document type
         (same markup convention the Data Sync page's own per-entity cards already established --
         see data-sync.php), each showing the current format AND a live "next number" preview
         directly on the card (previously only visible after opening the Edit modal) -- this is
         the "example settings shown" simplification: seeing what a document type's numbering
         actually produces no longer requires opening anything. PAYSLIP/BANK_TRANSFER are now real
         wired consumers (PaySlipReport/BankTransferFileReport); WHT_CERT stays config-only (no
         real withholding-certificate generator exists anywhere in this codebase to wire it to --
         confirmed via AskUserQuestion, same "built ahead of its consumer" precedent this app
         already has elsewhere) and its own card says so. -->
    <div class="tab-pane fade" id="tab-run">
      <h6 class="fw-bold mb-3" data-i18n="document_running_number_format">Document Number Format</h6>
      <div class="row g-3" id="documentNumberingCards"></div>
    </div>

    <!-- EMAIL LOG (2026-08-31, explicit request: "สร้าง Cronjob สำหรับการส่งอีเมล และเพิ่มหน้าให้ดู Log
         การส่งได้ มี Filter และตาราง รวมถึง Summary" -- the cron itself (cron/send_queued_emails.php)
         already existed from Phase 7 (T040); this is the new admin log/summary page on top of it.
         Summary = stat cards (same .stat-card markup as dashboard.php's own), filter = the shared
         .station-filter pattern (per CLAUDE.md's own Table convention), table = a plain client-side
         DataTable (unbounded-but-capped at 200 rows server-side, LIMIT 200 in EmailQueueModel::
         list(), same "recent window, not a full unbounded archive" convention every other
         audit-log-style table in this app already uses -- e.g. PayslipDeliveryLogModel::list()). -->
    <div class="tab-pane fade" id="tab-email-log">
      <div class="row g-3 mb-4" id="emailQueueStatRow">
        <div class="col-6 col-lg-4">
          <div class="stat-card stat-card-info h-100">
            <div class="stat-card-icon"><i class="fa-solid fa-clock"></i></div>
            <div>
              <div class="stat-card-label" data-i18n="email_queue_status_pending">Pending</div>
              <div class="stat-card-value" id="emailQueueStatPending">-</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-4">
          <div class="stat-card stat-card-success h-100">
            <div class="stat-card-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div>
              <div class="stat-card-label" data-i18n="email_queue_status_sent">Sent</div>
              <div class="stat-card-value" id="emailQueueStatSent">-</div>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-4">
          <div class="stat-card stat-card-danger h-100">
            <div class="stat-card-icon"><i class="fa-solid fa-circle-xmark"></i></div>
            <div>
              <div class="stat-card-label" data-i18n="email_queue_status_failed">Failed</div>
              <div class="stat-card-value" id="emailQueueStatFailed">-</div>
            </div>
          </div>
        </div>
      </div>
      <div class="station-filter" id="emailQueueStationFilter">
        <i class="fa-solid fa-filter me-1"></i><span class="station-filter-label" data-i18n="label_filter">Filter</span>
        <button type="button" class="station-filter-toggle" id="emailQueueStationFilterToggle" title="Toggle filter">
          <i class="fas fa-chevron-up"></i>
        </button>
        <div class="station-filter-body">
          <div class="row g-2">
            <div class="col-6 col-md-4 col-lg-3">
              <label class="form-label mb-1" data-i18n="status">Status</label>
              <select class="form-select select2-static" id="emailQueueFilterStatus" data-option-keys="email_queue_status_pending,email_queue_status_sent,email_queue_status_failed" data-option-values="pending,sent,failed"></select>
            </div>
            <div class="col-6 col-md-4 col-lg-3">
              <label class="form-label mb-1" data-i18n="date_from">From</label>
              <input type="text" class="form-control datepicker" id="emailQueueFilterDateFrom" autocomplete="off">
            </div>
            <div class="col-6 col-md-4 col-lg-3">
              <label class="form-label mb-1" data-i18n="date_to">To</label>
              <input type="text" class="form-control datepicker" id="emailQueueFilterDateTo" autocomplete="off">
            </div>
            <div class="col-6 col-md-4 col-lg-3">
              <label class="form-label mb-1" data-i18n="recipient">Recipient</label>
              <input type="text" class="form-control" id="emailQueueFilterToAddress" autocomplete="off" data-i18n="email_filter_placeholder" placeholder="e.g., name@company.com">
            </div>
          </div>
        </div>
      </div>
      <div class="station-filter-clear-row d-none" id="emailQueueFilterClearRow">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearEmailQueueFilter">
          <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
        </button>
      </div>
      <table class="table table-striped table-hover" id="tb_email_queue_log">
        <thead class="table-light text-secondary">
          <tr>
            <th data-i18n="recipient">Recipient</th>
            <th data-i18n="email_subject">Subject</th>
            <th data-i18n="status">Status</th>
            <th data-i18n="email_attempts">Attempts</th>
            <th data-i18n="email_error">Error</th>
            <th data-i18n="created_at">Created At</th>
            <th data-i18n="email_sent_at">Sent At</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

  </div>

  <!-- documentNumberingModal moved to app/views/layout/modals.php (2026-08-30, modal consolidation). -->
</div>
<script src="<?=asset('public/js/setup/approval-workflow.js')?>"></script>
<script src="<?=asset('public/js/setup/document-numbering.js')?>"></script>
<script src="<?=asset('public/js/setup/email-queue-log.js')?>"></script>