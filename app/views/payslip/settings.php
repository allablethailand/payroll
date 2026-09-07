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

  <ul class="nav nav-tabs flex-nowrap scrollable-tabs setup-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link setup-menu active" data-bs-toggle="tab" data-bs-target="#tab-tpl" type="button" id="payslipTemplateTabBtn" role="tab"><i class="fa-solid fa-file-invoice me-1"></i> <span data-i18n="payslip_template">Payslip Template</span></button></li>
    <li class="nav-item"><button class="nav-link setup-menu" data-bs-toggle="tab" data-bs-target="#tab-dist" type="button" id="payslipDistributionTabBtn" role="tab"><i class="fa-solid fa-paper-plane me-1"></i> <span data-i18n="payslip_distribution">Payslip Distribution</span></button></li>
    <!-- 2026-08-24, explicit request: "Menu Employment Ceritficate น่าจะนำไปรวมใน Play Slip แต่เปลี่ยน Menu
         ส่วนของการตั้งค่าก็เอาไปไว้ด้วยกัน แต่แยก Tab มีแค่ส่วนของการ Request ที่แยก Sub menu ย่อย" -- the
         standalone "Employment Certificate" top-level menu item is gone (see header.php); its designer
         now lives here as a 3rd tab. Requests stays a separate submenu item under Payslip because
         Employment Certificate has no request/issuance flow yet -- once that's built it gets its own
         entry there, not folded into Payslip Requests' existing table. -->
    <li class="nav-item"><button class="nav-link setup-menu" data-bs-toggle="tab" data-bs-target="#tab-ect" type="button" id="employmentCertificateTemplateTabBtn" role="tab"><i class="fa-solid fa-file-shield me-1"></i> <span data-i18n="employment_certificate_template">Employment Certificate Template</span></button></li>
  </ul>

  <div class="tab-content">
    <!-- PAYSLIP TEMPLATE -- rebuilt as a free-form canvas designer, explicit request: "ปรับให้การ
         ตั้งค่า Slip เงินเดือน Template เป็นเหมือนกับใบรับรอง" (matches the Employment Certificate
         Template designer below: List+row-actions here, editing happens on its own standalone page
         opened in a new tab -- see app/views/payslip-template/). -->
    <div class="tab-pane fade show active p-0" id="tab-tpl">
      <?php include __DIR__ . '/../payslip-template/_list_partial.php'; ?>
    </div>

    <!-- PAYSLIP DISTRIBUTION -->
    <!-- 2026-08-26, explicit follow-up: "ช่วยวางโครงสร้างการตั้งค่าการส่ง Payslip ให้ใหม่หน่อยครับ ให้ตอบโจทย์
         การใช้งานจริง" -- confirmed via AskUserQuestion the pain point was purely visual ("หน้าตาดูรก
         ไม่แยกหมวดชัดเจน", the page looked cluttered with no clear sections), NOT the underlying
         fields/logic. Every field id, the show/hide-on-mode behavior (#pd_auto_settings_block), and
         the single submit handler in payslip-distribution.js are all UNCHANGED -- this is purely a
         markup reorganization into 3 clearly separated cards (General / Auto-send Rule / Scope),
         mirroring the .pst-info-card-header icon+title convention already used elsewhere in this app's
         settings pages instead of one long undifferentiated form. -->
    <div class="tab-pane fade p-0" id="tab-dist">
        <form id="payslipDistributionForm">
          <div class="pst-info-card card-surface mb-3">
            <div class="pst-info-card-header">
              <div class="pst-info-card-title"><i class="fa-solid fa-sliders"></i><span data-i18n="pd_section_general">General</span></div>
            </div>
            <div class="pst-info-card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label mb-1" data-i18n="distribution_mode">Distribution Mode</label>
                  <select class="form-select select2-static" id="pd_mode"
                    data-option-keys="mode_auto,mode_request_only,mode_both" data-option-values="auto,request_only,both"></select>
                  <div class="text-secondary small mt-1" data-i18n="distribution_mode_hint">Auto = sent automatically when a run reaches Paid. Request-only = employee/HR must request each time. Both = either can happen.</div>
                </div>
                <div class="col-md-6 d-flex align-items-center">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="pd_is_active" checked>
                    <label class="form-check-label" for="pd_is_active" data-i18n="enable_distribution">Enable payslip distribution</label>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div id="pd_auto_settings_block">
            <div class="pst-info-card card-surface mb-3">
              <div class="pst-info-card-header">
                <div class="pst-info-card-title"><i class="fa-solid fa-paper-plane"></i><span data-i18n="pd_section_auto_send">Auto-send Rule</span></div>
              </div>
              <div class="pst-info-card-body">
                <div class="row g-3">
                  <div class="col-md-8">
                    <label class="form-label mb-1" data-i18n="delivery_channels">Delivery Channels &amp; Fallback Order</label>
                    <select class="form-select select2-remote" id="pd_channels" multiple data-api="/api/payslip-distribution.channel-options"></select>
                    <div class="text-secondary small mt-1" data-i18n="delivery_channels_hint">Order you pick them in = attempt order (first = primary, rest = fallback if it fails).</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label mb-1" data-i18n="send_delay_hours">Send Delay (Hours)</label>
                    <input type="number" class="form-control" id="pd_delay_hours" min="0" step="1" value="0" data-i18n="hours_placeholder" placeholder="e.g., 2">
                    <div class="text-secondary small mt-1" data-i18n="send_delay_hours_hint">0 = send immediately when the run becomes Paid.</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="pst-info-card card-surface mb-3">
              <div class="pst-info-card-header">
                <div class="pst-info-card-title"><i class="fa-solid fa-filter"></i><span data-i18n="pd_section_scope">Scope</span></div>
              </div>
              <div class="pst-info-card-body">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label mb-1" data-i18n="scope_departments">Departments</label>
                    <select class="form-select select2-remote" id="pd_scope_departments" multiple data-api="/api/department.get" data-type="department"></select>
                    <div class="text-secondary small mt-1" data-i18n="scope_leave_empty_hint">Leave empty = no restriction (applies to all).</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label mb-1" data-i18n="scope_employment_statuses">Employment Statuses</label>
                    <select class="form-select select2-static" id="pd_scope_statuses" multiple
                      data-option-keys="employment_status_probation,employment_status_permanent,employment_status_contract,employment_status_resigned,employment_status_terminated"
                      data-option-values="probation,permanent,contract,resigned,terminated"></select>
                    <div class="text-secondary small mt-1" data-i18n="scope_leave_empty_hint">Leave empty = no restriction (applies to all).</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="text-end">
            <button type="submit" class="btn btn-primary px-4" data-i18n="save">Save</button>
          </div>
        </form>
    </div>

    <!-- EMPLOYMENT CERTIFICATE TEMPLATE (merged in 2026-08-24, own designer -- see the tab button comment above) -->
    <div class="tab-pane fade p-0" id="tab-ect">
      <?php include __DIR__ . '/../employment-certificate/_list_partial.php'; ?>
    </div>
  </div>

  <?php include __DIR__ . '/../employment-certificate/_modals_partial.php'; ?>
  <?php include __DIR__ . '/../payslip-template/_modals_partial.php'; ?>
</div>
<!-- 2026-09-04, Backlog Phase 11, T064 -- shared, genuinely stateless canvas-designer utilities
     (page-size constants, margin presets, zoom levels, font-family CSS stack, a few pure helpers)
     factored out of payslip-template.js/employment-certificate-template.js's own near-duplicate
     copies. MUST load before both of those (they alias their own local const/function names to
     window.CanvasDesignerCore's own members at their own top level). -->
<script src="<?=asset('public/js/setup/canvas-designer-core.js')?>"></script>
<script src="<?=asset('public/js/setup/payslip-template.js')?>"></script>
<script src="<?=asset('public/js/setup/payslip-distribution.js')?>"></script>
<script src="<?=asset('public/js/setup/employment-certificate-template.js')?>"></script>
