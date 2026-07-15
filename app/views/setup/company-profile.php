<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> Payroll</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current">Company Setup</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0"><i class="fa-solid fa-building"></i> Company Management</h5>
        <p class="text-muted small m-0 mt-1">Configure and manage corporate profile, local tax identification, and primary bank accounts for payroll processing.</p>
    </div>

    <ul class="nav nav-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-pane" type="button" role="tab" aria-controls="profile-pane" aria-selected="true">
                <i class="fa-solid fa-id-card me-2"></i>Company Profile
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="banks-tab" data-bs-toggle="tab" data-bs-target="#banks-pane" type="button" role="tab" aria-controls="banks-pane" aria-selected="false">
                <i class="fa-solid fa-credit-card me-2"></i>Bank Accounts
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="role-tab" data-bs-toggle="tab" data-bs-target="#role-pane" type="button" role="tab" aria-controls="role-pane" aria-selected="false">
                <i class="fa-solid fa-user-tag me-2"></i>Role
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="department-tab" data-bs-toggle="tab" data-bs-target="#department-pane" type="button" role="tab" aria-controls="department-pane" aria-selected="false">
                <i class="fa-solid fa-building me-2"></i>Department
            </button>
        </li>
    </ul>

    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-0" id="companySetupTabsContent">

        <!-- ============ PROFILE ============ -->
        <div class="tab-pane fade show active" id="profile-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2"><label class="label label-head bg-head-first rounded-2 text-white">1</label> Company Information</h6>
            <div class="row">
                <div class="col-sm-2 mt-3"><label class="form-label">Registered Country <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="registered_country" required>
                        <option value="">-- Select Country --</option>
                        <option value="TH">Thailand</option>
                        <option value="SG">Singapore</option>
                        <option value="MY">Malaysia</option>
                        <option value="US">United States</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3"><label class="form-label"><span id="tax_id_label">Tax ID / EIN</span> <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" name="global_tax_id" required placeholder="Tax Registration Number"></div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3"><label class="form-label">Company Legal Name <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" required placeholder="e.g., Acme Co., Ltd."></div>
                <div class="col-sm-2 mt-3"><label class="form-label">Local Name <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" required placeholder="e.g., บริษัท แอคมี จำกัด"></div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-4"><label class="label label-head bg-head-first rounded-2 text-white">2</label> Registered Address</h6>
            <div class="row">
                <div class="col-sm-2 mt-3"><label class="form-label">Address Line 1 <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" placeholder="Street address, P.O. box" required></div>
                <div class="col-sm-2 mt-3"><label class="form-label">Address Line 2 (Optional)</label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" placeholder="Apartment, suite, unit, building"></div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3"><label class="form-label">City / District <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" required></div>
                <div class="col-sm-2 mt-3"><label class="form-label">Postal Code <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" required></div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-4"><label class="label label-head bg-head-first rounded-2 text-white">3</label> Local Statutory & Tax Settings</h6>
            <p class="text-muted small mb-3">*Please enter information based on the statutory requirements of your company's country of registration.</p>
            <div id="dynamic_statutory_fields_container" class="row"></div>
            <div class="row">
                <div class="col-sm-2 mt-3"><label class="form-label">Authorized Signatory Name <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" placeholder="For official tax reports" required></div>
            </div>
            <div class="d-flex justify-content-end mt-5">
                <button type="button" class="btn btn-brand px-4" id="btnNextToBanks">Next: Bank Accounts <i class="fas fa-chevron-right ms-1"></i></button>
            </div>
        </div>

        <!-- ============ BANKS ============ -->
        <div class="tab-pane fade" id="banks-pane" role="tabpanel" aria-labelledby="banks-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2"><label class="label label-head bg-head-first rounded-2 text-white">4</label> Financial & Payroll Settings</h6>
            <div class="row mb-4">
                <div class="col-sm-2 mt-3"><label class="form-label">Base Currency <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="base_currency" required>
                        <option value="THB">THB (฿)</option><option value="SGD">SGD (S$)</option><option value="MYR">MYR (RM)</option><option value="USD">USD ($)</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3"><label class="form-label">Company Timezone <span class="text-danger">*</span></label></div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="company_timezone" required>
                        <option value="Asia/Bangkok">(GMT+07:00) Bangkok</option>
                        <option value="Asia/Singapore">(GMT+08:00) Singapore</option>
                        <option value="Asia/Kuala_Lumpur">(GMT+08:00) Kuala Lumpur</option>
                        <option value="America/New_York">(GMT-05:00) New York</option>
                    </select>
                </div>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                <h6 class="text-secondary fw-bold m-0">Corporate Bank Accounts</h6>
                <button type="button" class="btn btn-outline-brand btn-sm" id="btnAddBank"><i class="fas fa-plus me-1"></i> Add Bank Account</button>
            </div>
            <div id="dynamic_bank_accounts_container"></div>
            <div class="d-flex justify-content-end gap-2 mt-5 border-top pt-4">
                <button type="button" class="btn btn-light px-4" id="btnBackToProfile"><i class="fas fa-chevron-left me-1"></i> Back</button>
                <button type="submit" class="btn btn-brand px-4">Save Settings</button>
            </div>
        </div>

        <!-- ============ ROLE ============ -->
        <div class="tab-pane fade" id="role-pane" role="tabpanel" aria-labelledby="role-tab" tabindex="0">

            <div class="card-surface p-3 p-md-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div><h6 class="fw-bold mb-0">ผู้ใช้งานระบบ</h6><div class="text-secondary small">รายชื่อผู้ที่สามารถเข้าถึงระบบ Payroll / HR</div></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="userSearch" class="form-control form-control-sm" placeholder="ค้นหาผู้ใช้งาน..."></div>
                        <button class="btn btn-brand" onclick="openUserModal()"><i class="fa-solid fa-plus me-1"></i> เพิ่มผู้ใช้งาน</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table pl-table mb-0">
                        <thead><tr><th></th><th>ชื่อ</th><th>อีเมล</th><th>บทบาท (Role)</th><th>เข้าใช้ล่าสุด</th><th class="text-center">สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody id="userBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="card-surface p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div><h6 class="fw-bold mb-0">บทบาทและสิทธิ์การเข้าถึง (Roles &amp; Permissions)</h6><div class="text-secondary small">กำหนดว่าแต่ละบทบาททำอะไรได้บ้างในแต่ละโมดูล</div></div>
                    <button class="btn btn-outline-brand btn-sm" onclick="openRoleModal()"><i class="fa-solid fa-plus me-1"></i> เพิ่มบทบาทใหม่</button>
                </div>
                <div class="table-responsive">
                    <table class="table pl-table perm-grid mb-0">
                        <thead><tr id="permHeadRow"><th>โมดูล</th></tr></thead>
                        <tbody id="permBody"></tbody>
                    </table>
                </div>
                <div class="text-secondary small mt-3"><i class="fa-solid fa-circle-check text-success me-1"></i> เข้าถึงได้ (View/Edit) &nbsp;|&nbsp; <i class="fa-solid fa-eye text-secondary me-1"></i> ดูอย่างเดียว &nbsp;|&nbsp; <i class="fa-solid fa-minus text-secondary me-1"></i> ไม่มีสิทธิ์</div>
            </div>
        </div>

        <!-- ============ DEPARTMENT ============ -->
        <div class="tab-pane fade" id="department-pane" role="tabpanel" aria-labelledby="department-tab" tabindex="0">
            <ul class="nav nav-tabs mb-4" id="deptSubTabs">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-dept" type="button"><i class="fa-solid fa-sitemap me-1"></i> แผนก</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pos" type="button"><i class="fa-solid fa-id-badge me-1"></i> ตำแหน่ง</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-branch" type="button"><i class="fa-solid fa-building me-1"></i> สาขา</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-loc" type="button"><i class="fa-solid fa-location-dot me-1"></i> สถานที่ทำงาน</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active card-surface p-3 p-md-4" id="tab-dept">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0">รายการแผนก</h6>
                        <div class="d-flex gap-2 flex-wrap">
                            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="deptSearch" class="form-control form-control-sm" placeholder="ค้นหาแผนก..."></div>
                            <button class="btn btn-outline-brand btn-sm" onclick="openDeptModal()"><i class="fa-solid fa-plus me-1"></i> เพิ่มแผนก</button>
                        </div>
                    </div>
                    <table class="table pl-table mb-0">
                        <thead><tr><th>รหัส</th><th>ชื่อแผนก</th><th>หัวหน้าแผนก</th><th class="text-end">จำนวนพนักงาน</th><th class="text-center">สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody id="deptBody"></tbody>
                    </table>
                </div>
                <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-pos">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0">รายการตำแหน่ง</h6>
                        <div class="d-flex gap-2 flex-wrap">
                            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i><input type="text" id="posSearch" class="form-control form-control-sm" placeholder="ค้นหาตำแหน่ง..."></div>
                            <button class="btn btn-outline-brand btn-sm" onclick="openPosModal()"><i class="fa-solid fa-plus me-1"></i> เพิ่มตำแหน่ง</button>
                        </div>
                    </div>
                    <table class="table pl-table mb-0">
                        <thead><tr><th>รหัส</th><th>ชื่อตำแหน่ง</th><th>แผนก</th><th>ระดับ</th><th class="text-center">สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody id="posBody"></tbody>
                    </table>
                </div>
                <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-branch">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0">รายการสาขา</h6>
                        <button class="btn btn-outline-brand btn-sm" onclick="openBranchModal()"><i class="fa-solid fa-plus me-1"></i> เพิ่มสาขา</button>
                    </div>
                    <table class="table pl-table mb-0">
                        <thead><tr><th>รหัส</th><th>ชื่อสาขา</th><th>ที่อยู่</th><th>จังหวัด</th><th class="text-center">สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody id="branchBody"></tbody>
                    </table>
                </div>
                <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-loc">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0">รายการสถานที่ทำงาน</h6>
                        <button class="btn btn-outline-brand btn-sm" onclick="openLocModal()"><i class="fa-solid fa-plus me-1"></i> เพิ่มสถานที่ทำงาน</button>
                    </div>
                    <table class="table pl-table mb-0">
                        <thead><tr><th>ชื่อสถานที่</th><th>ประเภท</th><th>ที่อยู่ / ลิงก์</th><th class="text-center">สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody id="locBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ================= USER MODAL ================= -->
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title fw-bold" id="userModalTitle"><i class="fa-solid fa-user"></i>เพิ่มผู้ใช้งาน</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" id="userId">
    <div class="mb-3"><label class="form-label">ชื่อ-สกุล <span class="text-danger">*</span></label><input class="form-control" id="userName"></div>
    <div class="mb-3"><label class="form-label">อีเมล <span class="text-danger">*</span></label><input type="email" class="form-control" id="userEmail"></div>
    <div class="mb-3"><label class="form-label">บทบาท <span class="text-danger">*</span></label><select class="form-select" id="userRole"></select></div>
    <div class="d-flex align-items-center gap-2 mt-1">
      <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="userStatus" checked></div>
      <label class="form-label m-0" for="userStatus">เปิดใช้งานบัญชีนี้</label>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-brand" onclick="saveUser()"><i class="fa-solid fa-check me-1"></i>บันทึกและส่งคำเชิญ</button></div>
</div></div></div>

<!-- ================= ROLE MODAL ================= -->
<div class="modal fade" id="roleModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title fw-bold" id="roleModalTitle"><i class="fa-solid fa-user-tag"></i>เพิ่มบทบาทใหม่</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" id="roleId">
    <div class="row g-3 mb-2">
      <div class="col-md-5"><label class="form-label">ชื่อบทบาท <span class="text-danger">*</span></label><input class="form-control" id="roleName" placeholder="เช่น Finance Reviewer"></div>
      <div class="col-md-7"><label class="form-label">คำอธิบาย</label><input class="form-control" id="roleDesc" placeholder="สิทธิ์และหน้าที่โดยสรุป"></div>
    </div>
    <label class="form-label">สิทธิ์การเข้าถึงแต่ละโมดูล</label>
    <div class="table-responsive">
      <table class="table pl-table mb-0" id="rolePermTable">
        <thead><tr><th>โมดูล</th><th style="width:220px;">ระดับสิทธิ์</th></tr></thead>
        <tbody id="rolePermBody"></tbody>
      </table>
    </div>
    <div class="d-flex align-items-center gap-2 mt-3">
      <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="roleStatus" checked></div>
      <label class="form-label m-0" for="roleStatus">เปิดใช้งานบทบาทนี้</label>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-brand" onclick="saveRole()"><i class="fa-solid fa-check me-1"></i>บันทึก</button></div>
</div></div></div>

<!-- ================= DEPARTMENT MODAL ================= -->
<div class="modal fade" id="deptModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title fw-bold" id="deptModalTitle"><i class="fa-solid fa-sitemap"></i>เพิ่มแผนก</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" id="deptId">
    <div class="row g-3">
      <div class="col-md-5"><label class="form-label">รหัสแผนก <span class="text-danger">*</span></label><input class="form-control" id="deptCode" placeholder="เช่น DEP06"></div>
      <div class="col-md-7"><label class="form-label">ชื่อแผนก <span class="text-danger">*</span></label><input class="form-control" id="deptName" placeholder="เช่น ฝ่ายทรัพยากรบุคคล"></div>
      <div class="col-md-8"><label class="form-label">หัวหน้าแผนก</label><input class="form-control" id="deptHead" placeholder="ชื่อผู้จัดการแผนก"></div>
      <div class="col-md-4"><label class="form-label">จำนวนพนักงาน</label><input type="number" min="0" class="form-control" id="deptEmp" value="0"></div>
      <div class="col-12 d-flex align-items-center gap-2 mt-1">
        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="deptStatus" checked></div>
        <label class="form-label m-0" for="deptStatus">เปิดใช้งานแผนกนี้</label>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-brand" onclick="saveDept()"><i class="fa-solid fa-check me-1"></i>บันทึก</button></div>
</div></div></div>

<!-- ================= POSITION MODAL ================= -->
<div class="modal fade" id="posModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title fw-bold" id="posModalTitle"><i class="fa-solid fa-id-badge"></i>เพิ่มตำแหน่ง</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" id="posId">
    <div class="row g-3">
      <div class="col-md-5"><label class="form-label">รหัสตำแหน่ง <span class="text-danger">*</span></label><input class="form-control" id="posCode" placeholder="เช่น POS05"></div>
      <div class="col-md-7"><label class="form-label">ชื่อตำแหน่ง <span class="text-danger">*</span></label><input class="form-control" id="posName" placeholder="เช่น HR Officer"></div>
      <div class="col-md-7"><label class="form-label">แผนก</label><select class="form-select" id="posDept"></select></div>
      <div class="col-md-5"><label class="form-label">ระดับ</label>
        <select class="form-select" id="posLevel"><option>Staff</option><option>Senior</option><option>Manager</option><option>Executive</option></select>
      </div>
      <div class="col-12 d-flex align-items-center gap-2 mt-1">
        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="posStatus" checked></div>
        <label class="form-label m-0" for="posStatus">เปิดใช้งานตำแหน่งนี้</label>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-brand" onclick="savePos()"><i class="fa-solid fa-check me-1"></i>บันทึก</button></div>
</div></div></div>

<!-- ================= BRANCH MODAL ================= -->
<div class="modal fade" id="branchModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title fw-bold" id="branchModalTitle"><i class="fa-solid fa-building"></i>เพิ่มสาขา</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" id="branchId">
    <div class="row g-3">
      <div class="col-md-5"><label class="form-label">รหัสสาขา <span class="text-danger">*</span></label><input class="form-control" id="branchCode" placeholder="เช่น BR03"></div>
      <div class="col-md-7"><label class="form-label">ชื่อสาขา <span class="text-danger">*</span></label><input class="form-control" id="branchName" placeholder="เช่น สาขาภูเก็ต"></div>
      <div class="col-md-7"><label class="form-label">ที่อยู่</label><input class="form-control" id="branchAddress" placeholder="เลขที่ / ถนน"></div>
      <div class="col-md-5"><label class="form-label">จังหวัด</label><input class="form-control" id="branchProvince" placeholder="เช่น ภูเก็ต"></div>
      <div class="col-12 d-flex align-items-center gap-2 mt-1">
        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="branchStatus" checked></div>
        <label class="form-label m-0" for="branchStatus">เปิดใช้งานสาขานี้</label>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-brand" onclick="saveBranch()"><i class="fa-solid fa-check me-1"></i>บันทึก</button></div>
</div></div></div>

<!-- ================= LOCATION MODAL ================= -->
<div class="modal fade" id="locModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h6 class="modal-title fw-bold" id="locModalTitle"><i class="fa-solid fa-location-dot"></i>เพิ่มสถานที่ทำงาน</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input type="hidden" id="locId">
    <div class="row g-3">
      <div class="col-12"><label class="form-label">ชื่อสถานที่ <span class="text-danger">*</span></label><input class="form-control" id="locName" placeholder="เช่น คลังสินค้าระยอง"></div>
      <div class="col-md-5"><label class="form-label">ประเภท</label>
        <select class="form-select" id="locType"><option value="Office">Office</option><option value="Site">Site</option><option value="Remote">Remote</option></select>
      </div>
      <div class="col-md-7"><label class="form-label">ที่อยู่ / ลิงก์</label><input class="form-control" id="locAddress" placeholder="ที่อยู่ หรือ ลิงก์ WFH policy"></div>
      <div class="col-12 d-flex align-items-center gap-2 mt-1">
        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="locStatus" checked></div>
        <label class="form-label m-0" for="locStatus">เปิดใช้งานสถานที่นี้</label>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-brand" onclick="saveLoc()"><i class="fa-solid fa-check me-1"></i>บันทึก</button></div>
</div></div></div>

<!-- ================= DELETE CONFIRM (shared) ================= -->
<div class="modal fade" id="deleteModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content">
  <div class="modal-body text-center pt-4">
    <div class="confirm-icon"><i class="fa-solid fa-trash"></i></div>
    <h6 class="fw-bold mb-1">ยืนยันการลบ</h6>
    <p class="text-muted small mb-0">คุณต้องการลบ "<span id="deleteTargetName"></span>" ใช่หรือไม่?<br>การลบไม่สามารถย้อนกลับได้</p>
  </div>
  <div class="modal-footer border-0 justify-content-center pb-4">
    <button class="btn btn-light px-3" data-bs-dismiss="modal">ยกเลิก</button>
    <button class="btn btn-danger px-3" onclick="confirmDelete()"><i class="fa-solid fa-trash me-1"></i>ลบ</button>
  </div>
</div></div></div>

<div class="toast-container position-fixed bottom-0 end-0 p-4">
  <div id="opToast" class="toast align-items-center border-0" role="alert">
    <div class="d-flex"><div class="toast-body fw-semibold"><i class="fa-solid fa-circle-check text-success me-2"></i><span id="opToastMsg">Saved</span></div>
    <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>
<script>
/* ============ COMMON HELPERS ============ */
function toast(msg){ $('#opToastMsg').text(msg); new bootstrap.Toast(document.getElementById('opToast'), {delay:2200}).show(); }
function statusSwitch(checked, onchange){
  return `<div class="form-check form-switch d-flex justify-content-center m-0"><input class="form-check-input" type="checkbox" ${checked?'checked':''} onchange="${onchange}"></div>`;
}
function actionBtns(editFn, delFn){
  return `<div class="action-btns"><button class="icon-btn" onclick="${editFn}" title="แก้ไข"><i class="fa-solid fa-pen"></i></button><button class="icon-btn danger" onclick="${delFn}" title="ลบ"><i class="fa-solid fa-trash"></i></button></div>`;
}
function esc(s){ return String(s).replace(/'/g,"\\'"); }
let deleteContext=null;
function askDelete(type,id,name){ deleteContext={type,id,name}; $('#deleteTargetName').text(name); new bootstrap.Modal('#deleteModal').show(); }
function confirmDelete(){
  if(!deleteContext) return;
  const {type,id,name}=deleteContext;
  const map={
    user:()=>{ users=users.filter(x=>x.id!==id); renderUsers(); },
    role:()=>{ roles=roles.filter(x=>x.id!==id); renderUsers(); renderPermMatrix(); },
    dept:()=>{ departments=departments.filter(x=>x.id!==id); renderDept(); },
    pos:()=>{ positions=positions.filter(x=>x.id!==id); renderPos(); },
    branch:()=>{ branches=branches.filter(x=>x.id!==id); renderBranch(); },
    loc:()=>{ locations=locations.filter(x=>x.id!==id); renderLoc(); },
  };
  if(map[type]) map[type]();
  bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
  toast(`ลบ "${name}" เรียบร้อยแล้ว`);
  deleteContext=null;
}
function attachSearch(inputId, renderFn){ $('#'+inputId).off('keyup').on('keyup', renderFn); }

/* ============ MODULES for permissions ============ */
const modules = ['Dashboard','Employees','Payroll Run','Reports','Settings'];
function permIcon(v){
  if(v==='full') return '<i class="fa-solid fa-circle-check text-success"></i>';
  if(v==='view') return '<i class="fa-solid fa-eye text-secondary"></i>';
  return '<i class="fa-solid fa-minus text-secondary opacity-50"></i>';
}

/* ============ DATA: USERS ============ */
let users = [
  {id:1, name:'สุกัญญา รักษ์งาน', email:'sukanya@origami.co', role:'HR Manager', last:'วันนี้ 09:12', status:1},
  {id:2, name:'ประยุทธ์ ตั้งใจทำ', email:'prayut@origami.co', role:'Payroll Officer', last:'เมื่อวาน 17:40', status:1},
  {id:3, name:'เอกชัย มั่งมี', email:'ekachai@origami.co', role:'Approver', last:'3 วันก่อน', status:1},
  {id:4, name:'นภัสสร ทองคำ', email:'napassorn@origami.co', role:'Employee', last:'-', status:0},
];
let nextUserId=5;

function renderUsers(){
  const q=($('#userSearch').val()||'').toLowerCase();
  const body=$('#userBody').empty();
  const filtered = users.filter(u => !q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q) || u.role.toLowerCase().includes(q));
  if(!filtered.length){ body.append(`<tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูลผู้ใช้งาน</td></tr>`); return; }
  filtered.forEach(u=>{
    const initials=u.name.split(' ').map(s=>s[0]).join('');
    body.append(`<tr>
      <td><div class="avatar-circle">${initials}</div></td>
      <td class="fw-bold">${u.name}</td>
      <td>${u.email}</td>
      <td><span class="role-badge">${u.role}</span></td>
      <td>${u.last}</td>
      <td class="text-center">${statusSwitch(u.status, `toggleUserStatus(${u.id})`)}</td>
      <td>${actionBtns(`openUserModal(${u.id})`, `askDelete('user', ${u.id}, '${esc(u.name)}')`)}</td>
    </tr>`);
  });
}
function toggleUserStatus(id){ const u=users.find(x=>x.id===id); u.status=u.status?0:1; toast(`${u.status?'เปิด':'ปิด'}ใช้งานบัญชี "${u.name}" แล้ว`); renderUsers(); }
function openUserModal(id){
  $('#userRole').html(roles.map(r=>`<option value="${r.name}">${r.name}</option>`).join(''));
  if(id){
    const u=users.find(x=>x.id===id);
    $('#userModalTitle').html('<i class="fa-solid fa-user"></i>แก้ไขผู้ใช้งาน');
    $('#userId').val(u.id); $('#userName').val(u.name); $('#userEmail').val(u.email); $('#userRole').val(u.role); $('#userStatus').prop('checked', !!u.status);
  } else {
    $('#userModalTitle').html('<i class="fa-solid fa-user"></i>เพิ่มผู้ใช้งาน');
    $('#userId').val(''); $('#userName').val(''); $('#userEmail').val(''); $('#userStatus').prop('checked', true);
  }
  new bootstrap.Modal('#userModal').show();
}
function saveUser(){
  const name=$('#userName').val().trim(), email=$('#userEmail').val().trim();
  if(!name || !email){ toast('กรุณากรอกชื่อและอีเมล'); return; }
  const id=$('#userId').val();
  const payload={name, email, role:$('#userRole').val(), status:$('#userStatus').is(':checked')?1:0, last: id ? users.find(x=>x.id==id).last : '-'};
  if(id){ Object.assign(users.find(x=>x.id==id), payload); toast('บันทึกการแก้ไขผู้ใช้งานแล้ว'); }
  else { users.push({id: nextUserId++, ...payload}); toast('เพิ่มผู้ใช้งานและส่งคำเชิญแล้ว'); }
  bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
  renderUsers();
}

/* ============ DATA: ROLES & PERMISSIONS ============ */
let roles = [
  {id:1, name:'Admin', desc:'สิทธิ์เข้าถึงทุกส่วนของระบบ', status:1, permissions:{Dashboard:'full',Employees:'full','Payroll Run':'full',Reports:'full',Settings:'full'}},
  {id:2, name:'HR Manager', desc:'ดูแลข้อมูลพนักงานและรายงาน', status:1, permissions:{Dashboard:'full',Employees:'full','Payroll Run':'view',Reports:'full',Settings:'view'}},
  {id:3, name:'Payroll Officer', desc:'ดำเนินการรอบจ่ายเงินเดือน', status:1, permissions:{Dashboard:'full',Employees:'view','Payroll Run':'full',Reports:'view',Settings:'none'}},
  {id:4, name:'Approver', desc:'อนุมัติรอบจ่ายเงินเดือน', status:1, permissions:{Dashboard:'view',Employees:'none','Payroll Run':'view',Reports:'view',Settings:'none'}},
  {id:5, name:'Employee', desc:'พนักงานทั่วไป ดูข้อมูลตนเอง', status:1, permissions:{Dashboard:'view',Employees:'none','Payroll Run':'none',Reports:'none',Settings:'none'}},
];
let nextRoleId=6;

function renderPermMatrix(){
  const headRow=$('#permHeadRow').empty().append('<th>โมดูล</th>');
  roles.forEach(r=>{
    headRow.append(`<th class="perm-col-head">
      <span class="role-badge">${r.name}</span>
      ${r.status ? '' : '<div class="small text-muted mt-1">(ปิดใช้งาน)</div>'}
      <div class="role-actions">
        <button class="icon-btn" onclick="openRoleModal(${r.id})" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
        <button class="icon-btn danger" onclick="askDelete('role', ${r.id}, '${esc(r.name)}')" title="ลบ"><i class="fa-solid fa-trash"></i></button>
      </div>
    </th>`);
  });
  const body=$('#permBody').empty();
  modules.forEach(m=>{
    let row = `<tr><td class="fw-bold">${m}</td>`;
    roles.forEach(r=>{ row += `<td>${permIcon(r.permissions[m]||'none')}</td>`; });
    row += `</tr>`;
    body.append(row);
  });
}
function openRoleModal(id){
  const body=$('#rolePermBody').empty();
  const existing = id ? roles.find(x=>x.id===id) : null;
  modules.forEach(m=>{
    const current = existing ? (existing.permissions[m]||'none') : 'none';
    body.append(`<tr><td>${m}</td><td>
      <select class="form-select form-select-sm role-perm-select" data-module="${m}">
        <option value="full" ${current==='full'?'selected':''}>เข้าถึงได้ (Full)</option>
        <option value="view" ${current==='view'?'selected':''}>ดูอย่างเดียว (View)</option>
        <option value="none" ${current==='none'?'selected':''}>ไม่มีสิทธิ์ (None)</option>
      </select>
    </td></tr>`);
  });
  if(existing){
    $('#roleModalTitle').html('<i class="fa-solid fa-user-tag"></i>แก้ไขบทบาท');
    $('#roleId').val(existing.id); $('#roleName').val(existing.name); $('#roleDesc').val(existing.desc);
    $('#roleStatus').prop('checked', !!existing.status);
  } else {
    $('#roleModalTitle').html('<i class="fa-solid fa-user-tag"></i>เพิ่มบทบาทใหม่');
    $('#roleId').val(''); $('#roleName').val(''); $('#roleDesc').val(''); $('#roleStatus').prop('checked', true);
  }
  new bootstrap.Modal('#roleModal').show();
}
function saveRole(){
  const name=$('#roleName').val().trim();
  if(!name){ toast('กรุณากรอกชื่อบทบาท'); return; }
  const permissions={};
  $('.role-perm-select').each(function(){ permissions[$(this).data('module')] = $(this).val(); });
  const id=$('#roleId').val();
  const payload={name, desc:$('#roleDesc').val().trim(), status:$('#roleStatus').is(':checked')?1:0, permissions};
  if(id){
    const oldName = roles.find(x=>x.id==id).name;
    Object.assign(roles.find(x=>x.id==id), payload);
    users.filter(u=>u.role===oldName).forEach(u=>u.role=name);
    toast('บันทึกการแก้ไขบทบาทแล้ว');
  } else {
    roles.push({id: nextRoleId++, ...payload});
    toast('เพิ่มบทบาทใหม่แล้ว');
  }
  bootstrap.Modal.getInstance(document.getElementById('roleModal')).hide();
  renderPermMatrix(); renderUsers();
}

/* ============ DATA: DEPARTMENT ============ */
let departments = [
  {id:1, code:'DEP01', name:'ฝ่ายขาย', head:'ธนกร วัฒนกิจ', emp:3, status:1},
  {id:2, code:'DEP02', name:'ฝ่ายบัญชี', head:'ปิยะดา สายใจ', emp:3, status:1},
  {id:3, code:'DEP03', name:'ฝ่าย IT', head:'ศักดิ์ชัย บุญมี', emp:2, status:1},
  {id:4, code:'DEP04', name:'ฝ่ายการตลาด', head:'นภัสสร ทองคำ', emp:2, status:1},
  {id:5, code:'DEP05', name:'ฝ่ายผลิต', head:'รัชนก ไพบูลย์', emp:2, status:1},
];
let nextDeptId=6;

function renderDept(){
  const q=($('#deptSearch').val()||'').toLowerCase();
  const body=$('#deptBody').empty();
  const filtered = departments.filter(d => !q || d.name.toLowerCase().includes(q) || d.code.toLowerCase().includes(q) || d.head.toLowerCase().includes(q));
  if(!filtered.length){ body.append(`<tr><td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูลแผนก</td></tr>`); return; }
  filtered.forEach(d=>{
    body.append(`<tr>
      <td><span class="code-tag">${d.code}</span></td>
      <td class="fw-bold">${d.name}</td>
      <td>${d.head || '-'}</td>
      <td class="text-end">${d.emp}</td>
      <td class="text-center">${statusSwitch(d.status, `toggleDeptStatus(${d.id})`)}</td>
      <td>${actionBtns(`openDeptModal(${d.id})`, `askDelete('dept', ${d.id}, '${esc(d.name)}')`)}</td>
    </tr>`);
  });
}
function toggleDeptStatus(id){ const d=departments.find(x=>x.id===id); d.status=d.status?0:1; toast(`${d.status?'เปิด':'ปิด'}ใช้งานแผนก "${d.name}" แล้ว`); renderDept(); }
function openDeptModal(id){
  if(id){
    const d=departments.find(x=>x.id===id);
    $('#deptModalTitle').html('<i class="fa-solid fa-sitemap"></i>แก้ไขแผนก');
    $('#deptId').val(d.id); $('#deptCode').val(d.code); $('#deptName').val(d.name); $('#deptHead').val(d.head); $('#deptEmp').val(d.emp); $('#deptStatus').prop('checked', !!d.status);
  } else {
    $('#deptModalTitle').html('<i class="fa-solid fa-sitemap"></i>เพิ่มแผนก');
    $('#deptId').val(''); $('#deptCode').val(''); $('#deptName').val(''); $('#deptHead').val(''); $('#deptEmp').val(0); $('#deptStatus').prop('checked', true);
  }
  new bootstrap.Modal('#deptModal').show();
}
function saveDept(){
  const code=$('#deptCode').val().trim(), name=$('#deptName').val().trim();
  if(!code || !name){ toast('กรุณากรอกรหัสและชื่อแผนก'); return; }
  const id=$('#deptId').val();
  const payload={code, name, head:$('#deptHead').val().trim(), emp:parseInt($('#deptEmp').val())||0, status:$('#deptStatus').is(':checked')?1:0};
  if(id){
    const oldName = departments.find(x=>x.id==id).name;
    Object.assign(departments.find(x=>x.id==id), payload);
    positions.filter(p=>p.dept===oldName).forEach(p=>p.dept=name);
    toast('บันทึกการแก้ไขแผนกแล้ว');
  } else { departments.push({id: nextDeptId++, ...payload}); toast('เพิ่มแผนกใหม่แล้ว'); }
  bootstrap.Modal.getInstance(document.getElementById('deptModal')).hide();
  renderDept();
}

/* ============ DATA: POSITION ============ */
let positions = [
  {id:1, code:'POS01', name:'Sales Executive', dept:'ฝ่ายขาย', level:'Staff', status:1},
  {id:2, code:'POS02', name:'Sales Manager', dept:'ฝ่ายขาย', level:'Manager', status:1},
  {id:3, code:'POS03', name:'Developer', dept:'ฝ่าย IT', level:'Staff', status:1},
  {id:4, code:'POS04', name:'Director', dept:'ผู้บริหาร', level:'Executive', status:1},
];
let nextPosId=5;

function renderPos(){
  const q=($('#posSearch').val()||'').toLowerCase();
  const body=$('#posBody').empty();
  const filtered = positions.filter(p => !q || p.name.toLowerCase().includes(q) || p.code.toLowerCase().includes(q) || p.dept.toLowerCase().includes(q));
  if(!filtered.length){ body.append(`<tr><td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูลตำแหน่ง</td></tr>`); return; }
  filtered.forEach(p=>{
    body.append(`<tr>
      <td><span class="code-tag">${p.code}</span></td>
      <td class="fw-bold">${p.name}</td>
      <td>${p.dept}</td>
      <td>${p.level}</td>
      <td class="text-center">${statusSwitch(p.status, `togglePosStatus(${p.id})`)}</td>
      <td>${actionBtns(`openPosModal(${p.id})`, `askDelete('pos', ${p.id}, '${esc(p.name)}')`)}</td>
    </tr>`);
  });
}
function togglePosStatus(id){ const p=positions.find(x=>x.id===id); p.status=p.status?0:1; toast(`${p.status?'เปิด':'ปิด'}ใช้งานตำแหน่ง "${p.name}" แล้ว`); renderPos(); }
function openPosModal(id){
  $('#posDept').html(departments.map(d=>`<option value="${d.name}">${d.name}</option>`).join('') + `<option value="ผู้บริหาร">ผู้บริหาร</option>`);
  if(id){
    const p=positions.find(x=>x.id===id);
    $('#posModalTitle').html('<i class="fa-solid fa-id-badge"></i>แก้ไขตำแหน่ง');
    $('#posId').val(p.id); $('#posCode').val(p.code); $('#posName').val(p.name); $('#posDept').val(p.dept); $('#posLevel').val(p.level); $('#posStatus').prop('checked', !!p.status);
  } else {
    $('#posModalTitle').html('<i class="fa-solid fa-id-badge"></i>เพิ่มตำแหน่ง');
    $('#posId').val(''); $('#posCode').val(''); $('#posName').val(''); $('#posLevel').val('Staff'); $('#posStatus').prop('checked', true);
  }
  new bootstrap.Modal('#posModal').show();
}
function savePos(){
  const code=$('#posCode').val().trim(), name=$('#posName').val().trim();
  if(!code || !name){ toast('กรุณากรอกรหัสและชื่อตำแหน่ง'); return; }
  const id=$('#posId').val();
  const payload={code, name, dept:$('#posDept').val(), level:$('#posLevel').val(), status:$('#posStatus').is(':checked')?1:0};
  if(id){ Object.assign(positions.find(x=>x.id==id), payload); toast('บันทึกการแก้ไขตำแหน่งแล้ว'); }
  else { positions.push({id: nextPosId++, ...payload}); toast('เพิ่มตำแหน่งใหม่แล้ว'); }
  bootstrap.Modal.getInstance(document.getElementById('posModal')).hide();
  renderPos();
}

/* ============ DATA: BRANCH ============ */
let branches = [
  {id:1, code:'BR01', name:'สำนักงานใหญ่', address:'เลขที่ 99 ถ.สุขุมวิท', province:'กรุงเทพมหานคร', status:1},
  {id:2, code:'BR02', name:'สาขาเชียงใหม่', address:'เลขที่ 12 ถ.นิมมานเหมินท์', province:'เชียงใหม่', status:1},
];
let nextBranchId=3;

function renderBranch(){
  const body=$('#branchBody').empty();
  if(!branches.length){ body.append(`<tr><td colspan="6" class="text-center text-muted py-4">ไม่พบข้อมูลสาขา</td></tr>`); return; }
  branches.forEach(b=>{
    body.append(`<tr>
      <td><span class="code-tag">${b.code}</span></td>
      <td class="fw-bold">${b.name}</td>
      <td>${b.address || '-'}</td>
      <td>${b.province || '-'}</td>
      <td class="text-center">${statusSwitch(b.status, `toggleBranchStatus(${b.id})`)}</td>
      <td>${actionBtns(`openBranchModal(${b.id})`, `askDelete('branch', ${b.id}, '${esc(b.name)}')`)}</td>
    </tr>`);
  });
}
function toggleBranchStatus(id){ const b=branches.find(x=>x.id===id); b.status=b.status?0:1; toast(`${b.status?'เปิด':'ปิด'}ใช้งานสาขา "${b.name}" แล้ว`); renderBranch(); }
function openBranchModal(id){
  if(id){
    const b=branches.find(x=>x.id===id);
    $('#branchModalTitle').html('<i class="fa-solid fa-building"></i>แก้ไขสาขา');
    $('#branchId').val(b.id); $('#branchCode').val(b.code); $('#branchName').val(b.name); $('#branchAddress').val(b.address); $('#branchProvince').val(b.province); $('#branchStatus').prop('checked', !!b.status);
  } else {
    $('#branchModalTitle').html('<i class="fa-solid fa-building"></i>เพิ่มสาขา');
    $('#branchId').val(''); $('#branchCode').val(''); $('#branchName').val(''); $('#branchAddress').val(''); $('#branchProvince').val(''); $('#branchStatus').prop('checked', true);
  }
  new bootstrap.Modal('#branchModal').show();
}
function saveBranch(){
  const code=$('#branchCode').val().trim(), name=$('#branchName').val().trim();
  if(!code || !name){ toast('กรุณากรอกรหัสและชื่อสาขา'); return; }
  const id=$('#branchId').val();
  const payload={code, name, address:$('#branchAddress').val().trim(), province:$('#branchProvince').val().trim(), status:$('#branchStatus').is(':checked')?1:0};
  if(id){ Object.assign(branches.find(x=>x.id==id), payload); toast('บันทึกการแก้ไขสาขาแล้ว'); }
  else { branches.push({id: nextBranchId++, ...payload}); toast('เพิ่มสาขาใหม่แล้ว'); }
  bootstrap.Modal.getInstance(document.getElementById('branchModal')).hide();
  renderBranch();
}

/* ============ DATA: LOCATION ============ */
let locations = [
  {id:1, name:'สำนักงานใหญ่', type:'Office', address:'เลขที่ 99 ถ.สุขุมวิท', status:1},
  {id:2, name:'โรงงานสมุทรปราการ', type:'Site', address:'นิคมอุตสาหกรรมบางพลี', status:1},
  {id:3, name:'Work From Home', type:'Remote', address:'-', status:1},
];
let nextLocId=4;

function renderLoc(){
  const body=$('#locBody').empty();
  if(!locations.length){ body.append(`<tr><td colspan="5" class="text-center text-muted py-4">ไม่พบข้อมูลสถานที่ทำงาน</td></tr>`); return; }
  locations.forEach(l=>{
    body.append(`<tr>
      <td class="fw-bold">${l.name}</td>
      <td>${l.type}</td>
      <td>${l.address || '-'}</td>
      <td class="text-center">${statusSwitch(l.status, `toggleLocStatus(${l.id})`)}</td>
      <td>${actionBtns(`openLocModal(${l.id})`, `askDelete('loc', ${l.id}, '${esc(l.name)}')`)}</td>
    </tr>`);
  });
}
function toggleLocStatus(id){ const l=locations.find(x=>x.id===id); l.status=l.status?0:1; toast(`${l.status?'เปิด':'ปิด'}ใช้งาน "${l.name}" แล้ว`); renderLoc(); }
function openLocModal(id){
  if(id){
    const l=locations.find(x=>x.id===id);
    $('#locModalTitle').html('<i class="fa-solid fa-location-dot"></i>แก้ไขสถานที่ทำงาน');
    $('#locId').val(l.id); $('#locName').val(l.name); $('#locType').val(l.type); $('#locAddress').val(l.address); $('#locStatus').prop('checked', !!l.status);
  } else {
    $('#locModalTitle').html('<i class="fa-solid fa-location-dot"></i>เพิ่มสถานที่ทำงาน');
    $('#locId').val(''); $('#locName').val(''); $('#locType').val('Office'); $('#locAddress').val(''); $('#locStatus').prop('checked', true);
  }
  new bootstrap.Modal('#locModal').show();
}
function saveLoc(){
  const name=$('#locName').val().trim();
  if(!name){ toast('กรุณากรอกชื่อสถานที่ทำงาน'); return; }
  const id=$('#locId').val();
  const payload={name, type:$('#locType').val(), address:$('#locAddress').val().trim(), status:$('#locStatus').is(':checked')?1:0};
  if(id){ Object.assign(locations.find(x=>x.id==id), payload); toast('บันทึกการแก้ไขสถานที่ทำงานแล้ว'); }
  else { locations.push({id: nextLocId++, ...payload}); toast('เพิ่มสถานที่ทำงานใหม่แล้ว'); }
  bootstrap.Modal.getInstance(document.getElementById('locModal')).hide();
  renderLoc();
}

/* ============ COMPANY PROFILE / BANKS (existing logic) ============ */
$(document).ready(function () {
    const countryMasterConfig = {
        "TH": {"tax_label":"Tax ID / เลขประจำตัวผู้เสียภาษี","currency":"THB","timezone":"Asia/Bangkok","fields":[
            {"name":"th_branch_code","label":"Tax Branch Code","placeholder":"e.g., 00000 (Head Office)","required":true},
            {"name":"th_sso_id","label":"Social Security Employer ID","placeholder":"10 digits Number","required":true}]},
        "SG": {"tax_label":"Unique Entity Number (UEN)","currency":"SGD","timezone":"Asia/Singapore","fields":[
            {"name":"sg_csn","label":"CPF Submission Number (CSN)","placeholder":"UEN + CPF Payment Code","required":true}]},
        "MY": {"tax_label":"Income Tax Number","currency":"MYR","timezone":"Asia/Kuala_Lumpur","fields":[
            {"name":"my_epf_no","label":"EPF Employer Number","placeholder":"","required":true},
            {"name":"my_socso_no","label":"SOCSO Number","placeholder":"","required":true}]},
        "US": {"tax_label":"EIN","currency":"USD","timezone":"America/New_York","fields":[
            {"name":"us_sui_account_number","label":"State Unemployment ID (SUI)","placeholder":"State-issued SUI Account Number","required":true}]}
    };
    function renderCountrySpecificForm(countryCode) {
        const $container = $('#dynamic_statutory_fields_container');
        $container.empty();
        const config = countryMasterConfig[countryCode];
        if (!config) { $('#tax_id_label').text('Tax ID / EIN'); return; }
        $('#tax_id_label').text(config.tax_label);
        $('#base_currency').val(config.currency).trigger('change');
        $('#company_timezone').val(config.timezone).trigger('change');
        config.fields.forEach(field => {
            const requiredAttribute = field.required ? 'required' : '';
            const redAsterisk = field.required ? '<span class="text-danger">*</span>' : '';
            $container.append(`
                <div class="col-sm-2 mt-3"><label class="form-label"><span>${field.label}</span> ${redAsterisk}</label></div>
                <div class="col-sm-4 mt-3"><input type="text" class="form-control" name="${field.name}" placeholder="${field.placeholder}" ${requiredAttribute}></div>
            `);
        });
    }
    let bankAccountIndex = 0;
    function addBankAccountRow(isPrimary = false) {
        bankAccountIndex++;
        const badgeText = isPrimary ? 'Primary Account' : 'Secondary Account';
        const badgeClass = isPrimary ? 'bg-primary' : 'bg-secondary';
        const deleteButton = !isPrimary ? `<button type="button" class="btn btn-sm btn-outline-danger btn-remove-bank"><i class="fas fa-trash-alt"></i> Remove</button>` : '';
        $('#dynamic_bank_accounts_container').append(`
            <div class="card bank-account-card mb-3 shadow-sm border">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="badge ${badgeClass}">${badgeText}</span>${deleteButton}
                </div>
                <div class="card-body py-2 pb-4">
                    <div class="row">
                        <div class="col-sm-2 mt-3"><label class="form-label">Bank Name <span class="text-danger">*</span></label></div>
                        <div class="col-sm-4 mt-3"><input type="text" class="form-control" name="banks[${bankAccountIndex}][bank_name]" placeholder="e.g., Kasikorn Bank, DBS" required></div>
                        <div class="col-sm-2 mt-3"><label class="form-label">Account Number / IBAN <span class="text-danger">*</span></label></div>
                        <div class="col-sm-4 mt-3"><input type="text" class="form-control" name="banks[${bankAccountIndex}][account_no]" required></div>
                    </div>
                    <div class="row">
                        <div class="col-sm-2 mt-3"><label class="form-label">SWIFT Code / BIC</label></div>
                        <div class="col-sm-4 mt-3"><input type="text" class="form-control" name="banks[${bankAccountIndex}][swift_code]" placeholder="e.g., BKCHTHBK"></div>
                        <div class="col-sm-2 mt-3"><label class="form-label">Company Bank Code</label></div>
                        <div class="col-sm-4 mt-3"><input type="text" class="form-control" name="banks[${bankAccountIndex}][service_code]" placeholder="e.g., Corporate ID"></div>
                    </div>
                </div>
            </div>
        `);
    }
    $('#registered_country').change(function () { renderCountrySpecificForm($(this).val()); });
    $('#btnAddBank').click(function() { addBankAccountRow(false); });
    $(document).on('click', '.btn-remove-bank', function() { $(this).closest('.bank-account-card').remove(); });
    $('#btnNextToBanks').click(function() {
        bootstrap.Tab.getInstance(document.querySelector('#companySetupTabs button[data-bs-target="#banks-pane"]')).show();
    });
    $('#btnBackToProfile').click(function() {
        bootstrap.Tab.getInstance(document.querySelector('#companySetupTabs button[data-bs-target="#profile-pane"]')).show();
    });
    renderCountrySpecificForm("TH");
    $('#registered_country').val("TH");
    addBankAccountRow(true);

    /* init tab buttons for bootstrap (needed since no data-bs-toggle instance auto by default click works, but for programmatic .show() need Tab instances) */
    document.querySelectorAll('#companySetupTabs button').forEach(el => new bootstrap.Tab(el));

    /* ============ INIT ALL CRUD RENDERS ============ */
    renderUsers();
    renderPermMatrix();
    renderDept();
    renderPos();
    renderBranch();
    renderLoc();

    attachSearch('userSearch', renderUsers);
    attachSearch('deptSearch', renderDept);
    attachSearch('posSearch', renderPos);
});
</script>