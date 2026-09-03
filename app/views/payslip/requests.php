<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-5">
      <span class="bc-root"><i class="fas fa-home me-1"></i> <span data-i18n="payroll">Payroll</span></span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-parent" data-i18n="payslip_menu">Payslip</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current" data-i18n="requests">Requests</span>
    </h5>
  </nav>
  <!-- 2026-08-26, generalized alongside the new Employment Certificate Requests tab -- title/
       description use their OWN new i18n keys (not `payslip_requests`, which is still the tab
       button's own label specifically) so adding a second document type here doesn't misword the
       page header into sounding payslip-only. -->
  <div class="page-header-card mb-4">
    <div class="page-header-card-icon"><i class="fa-solid fa-inbox"></i></div>
    <div class="page-header-card-body">
      <h5 class="page-header-card-title" data-i18n="document_requests_title">Document Requests</h5>
      <p class="page-header-card-desc" data-i18n="document_requests_description">Submit and track payslip and employment certificate requests, and review the payslip delivery history.</p>
    </div>
  </div>

  <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link setup-menu active" data-bs-toggle="tab" data-bs-target="#tab-req" type="button" id="payslipRequestTabBtn" role="tab"><i class="fa-solid fa-inbox me-1"></i> <span data-i18n="payslip_requests">Payslip Requests</span></button></li>
    <!-- 2026-08-26, explicit request: "เพิ่ม Tab สำหรับการ Request ใบรับรองขึ้นมาด้วยคู่กับ Pay slip" --
         paired here as a 3rd top-level tab on this same page, not a separate submenu item (see
         EmploymentCertificateRequestModel's own docblock for the backend this drives). -->
    <li class="nav-item"><button class="nav-link setup-menu" data-bs-toggle="tab" data-bs-target="#tab-ecr" type="button" id="ecrRequestTabBtn" role="tab"><i class="fa-solid fa-file-shield me-1"></i> <span data-i18n="employment_certificate_requests">Employment Certificate Requests</span></button></li>
    <li class="nav-item"><button class="nav-link setup-menu" data-bs-toggle="tab" data-bs-target="#tab-dlog" type="button" id="payslipDeliveryLogTabBtn" role="tab"><i class="fa-solid fa-clock-rotate-left me-1"></i> <span data-i18n="payslip_delivery_log">Delivery Log</span></button></li>
  </ul>

  <div class="tab-content">
    <!-- PAYSLIP REQUESTS (Mode B) -->
    <div class="tab-pane fade show active p-0" id="tab-req">
      <table class="table table-hover align-middle w-100" id="tb_payslip_request">
        <thead class="table-light text-secondary">
          <tr>
            <th data-i18n="employee">Employee</th>
            <th data-i18n="pay_period">Pay Period</th>
            <th data-i18n="requested_by">Requested By</th>
            <th data-i18n="status">Status</th>
            <th data-i18n="requested_at">Requested At</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <!-- EMPLOYMENT CERTIFICATE REQUESTS -->
    <div class="tab-pane fade p-0" id="tab-ecr">
      <table class="table table-hover align-middle w-100" id="tb_ecr_request">
        <thead class="table-light text-secondary">
          <tr>
            <th data-i18n="employee">Employee</th>
            <th data-i18n="language">Language</th>
            <th data-i18n="requested_by">Requested By</th>
            <th data-i18n="status">Status</th>
            <th data-i18n="requested_at">Requested At</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <!-- DOCUMENT DELIVERY / ISSUANCE LOG -- 2026-08-26, explicit request: "ปรับ Filter ให้เหมือนหน้า
         พนักงาน และมีเพิ่มประเภทเอกสารที่ส่งด้วยครับ". Filter box mirrors Employee List's own collapsible
         `.station-filter` (see app/views/employee/list.php) instead of the old plain row of dropdowns.
         Now covers BOTH payslip sends AND employment certificate issuances (see
         DocumentDeliveryLogModel's own docblock for why this is a UNION at the read layer, not a
         schema change to payslip_delivery_logs). -->
    <div class="tab-pane fade p-0" id="tab-dlog">
        <div class="station-filter" id="dlogStationFilter">
          <span class="station-filter-label" data-i18n="label_filter">Filter</span>
          <button type="button" class="station-filter-toggle" id="dlogStationFilterToggle" title="Toggle filter">
            <i class="fas fa-chevron-up"></i>
          </button>
          <div class="station-filter-body">
            <div class="row g-2">
              <div class="col-6 col-md-3">
                <label class="form-label mb-1 small" data-i18n="document_types">Document Type</label>
                <select class="form-select select2-static" id="dlog_filter_document_type" data-option-keys="doc_type_payslip,doc_type_employment_certificate" data-option-values="payslip,employment_certificate"></select>
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label mb-1 small" data-i18n="status">Status</label>
                <select class="form-select select2-static" id="dlog_filter_status" data-option-keys="status_sent,status_send_failed" data-option-values="success,failed"></select>
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label mb-1 small" data-i18n="channel">Channel</label>
                <select class="form-select select2-remote" id="dlog_filter_channel" data-api="/api/payslip-distribution.channel-options"></select>
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label mb-1 small" data-i18n="source">Source</label>
                <select class="form-select select2-static" id="dlog_filter_source" data-option-keys="source_auto,source_request" data-option-values="auto,request"></select>
              </div>
            </div>
          </div>
        </div>
        <div class="station-filter-clear-row d-none" id="dlogFilterClearRow">
          <button type="button" class="btn btn-outline-secondary btn-sm" id="btnClearDlogFilter">
            <i class="fa-solid fa-filter-circle-xmark me-1"></i><span data-i18n="clear_filter">Clear Filter</span>
          </button>
        </div>
        <table class="table table-hover align-middle w-100" id="tb_payslip_delivery_log">
          <thead class="table-light text-secondary">
            <tr>
              <th data-i18n="employee">Employee</th>
              <th data-i18n="document_types">Document Type</th>
              <th data-i18n="reference">Reference</th>
              <th data-i18n="source">Source</th>
              <th data-i18n="channel">Channel</th>
              <th data-i18n="recipient">Recipient</th>
              <th data-i18n="status">Status</th>
              <th data-i18n="sent_at">Sent At</th>
              <th data-i18n="sent_by">Sent By</th>
              <th></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
    </div>
  </div>

  <!-- payslipRequestModal / ecrRequestModal / requestDetailModal moved to
       app/views/layout/modals.php (2026-08-30, modal consolidation). -->
</div>
<script src="<?=asset('public/js/setup/approval-request-detail.js')?>"></script>
<script src="<?=asset('public/js/setup/payslip-request.js')?>"></script>
<script src="<?=asset('public/js/setup/employment-certificate-request.js')?>"></script>
<script src="<?=asset('public/js/setup/payslip-delivery-log.js')?>"></script>
