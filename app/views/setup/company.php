<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> Payroll</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-parent" data-i18n="settings">Settings</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="company_setup">Company Setup</span>
        </h5>
    </nav>
    <div class="mb-4">
        <h5 class="text-secondary fw-bold m-0">
            <i class="fa-solid fa-building"></i>
            <span data-i18n="company_management_title">Company Management</span>
        </h5>
        <p class="text-muted small m-0 mt-1" data-i18n="company_management_description">Configure and manage corporate profile, local tax identification, and primary bank accounts for payroll processing.</p>
    </div>
    <ul class="nav nav-tabs" id="companySetupTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold text-secondary" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-pane" type="button" role="tab" aria-controls="profile-pane" aria-selected="true">
                <i class="fa-solid fa-id-card me-2"></i>Company Profile
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-secondary" id="banks-tab" data-bs-toggle="tab" data-bs-target="#banks-pane" type="button" role="tab" aria-controls="banks-pane" aria-selected="false">
                <i class="fa-solid fa-credit-card me-2"></i>Bank Accounts
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-secondary" id="role-tab" data-bs-toggle="tab" data-bs-target="#role-pane" type="button" role="tab" aria-controls="banks-pane" aria-selected="false">
                <i class="fa-solid fa-user-tag me-2"></i>Role
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-secondary" id="department-tab" data-bs-toggle="tab" data-bs-target="#department-pane" type="button" role="tab" aria-controls="banks-pane" aria-selected="false">
                <i class="fa-solid fa-building me-2"></i>Department
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-secondary" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance-pane" type="button" role="tab" aria-controls="banks-pane" aria-selected="false">
                <i class="fa-regular fa-calendar-days me-2"></i>Attendance Leave
            </button>
        </li>
    </ul>
    <div class="tab-content border-top-0 bg-white rounded-bottom mb-5 mt-5" id="companySetupTabsContent">
        <div class="tab-pane fade show active" id="profile-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">1</label> 
                <span data-i18n="global_general_info">Company Information</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="hq_country">Registered Country</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="registered_country" name="registered_country" required>
                        <option value="">-- Select Country --</option>
                        <option value="TH">Thailand</option>
                        <option value="SG">Singapore</option>
                        <option value="MY">Malaysia</option>
                        <option value="US">United States</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label">
                        <span id="tax_id_label" data-i18n="tax_registration_number">Tax ID / EIN</span> <span class="text-danger">*</span>
                    </label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="global_tax_id" required placeholder="Tax Registration Number">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="company_legal_name">Company Legal Name</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="company_legal_name" required placeholder="e.g., Acme Co., Ltd.">
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="company_local_name">Local Name</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="company_local_name" required placeholder="e.g., บริษัท แอคมี จำกัด">
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-4">
                <label class="label label-head bg-head-first rounded-2 text-white">2</label> 
                <span data-i18n="registered_address">Registered Address</span>
            </h6>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="address_line1">Address Line 1</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="address_line1" placeholder="Street address, P.O. box" required>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="address_line2">Address Line 2 (Optional)</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="address_line2" placeholder="Apartment, suite, unit, building">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="city">City / District</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="city" required>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="postal_code">Postal Code</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="postal_code" required>
                </div>
            </div>
            <h6 class="text-secondary fw-bold mb-3 mt-4">
                <label class="label label-head bg-head-first rounded-2 text-white">3</label> 
                <span data-i18n="local_statutory_settings">Local Statutory & Tax Settings</span>
            </h6>
            <p class="text-muted small mb-3" data-i18n="local_statutory_hint">*Please enter information based on the statutory requirements of your company's country of registration.</p>
            <div id="dynamic_statutory_fields_container" class="row"></div>
            <div class="row">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="authorized_signatory">Authorized Signatory Name</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <input type="text" class="form-control" name="authorized_signatory" placeholder="For official tax reports" required>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-5">
                <button type="button" class="btn btn-primary px-4" id="btnNextToBanks">Next: Bank Accounts <i class="fas fa-chevron-right ms-1"></i></button>
            </div>
        </div>
        <div class="tab-pane fade" id="banks-pane" role="tabpanel" aria-labelledby="banks-tab" tabindex="0">
            <h6 class="text-secondary fw-bold mb-3 mt-2">
                <label class="label label-head bg-head-first rounded-2 text-white">4</label> 
                <span data-i18n="financial_settings">Financial & Payroll Settings</span>
            </h6>
            <div class="row mb-4">
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="base_currency">Base Currency</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="base_currency" name="base_currency" required>
                        <option value="THB">THB (฿)</option>
                        <option value="SGD">SGD (S$)</option>
                        <option value="MYR">MYR (RM)</option>
                        <option value="USD">USD ($)</option>
                    </select>
                </div>
                <div class="col-sm-2 mt-3">
                    <label class="form-label"><span data-i18n="company_timezone">Company Timezone</span> <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-4 mt-3">
                    <select class="form-select" id="company_timezone" name="company_timezone" required>
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
                <button type="button" class="btn btn-outline-success btn-sm" id="btnAddBank">
                    <i class="fas fa-plus me-1"></i> Add Bank Account
                </button>
            </div>
            <div id="dynamic_bank_accounts_container"></div>
            <div class="d-flex justify-content-end gap-2 mt-5 border-top pt-4">
                <button type="button" class="btn btn-light px-4" id="btnBackToProfile"><i class="fas fa-chevron-left me-1"></i> Back</button>
                <button type="submit" class="btn btn-warning px-4 text-white" style="background-color: #ff9900; border-color: #ff9900;" data-i18n="save_settings">Save Settings</button>
            </div>
        </div>
        <div class="tab-pane fade" id="role-pane" role="tabpanel" aria-labelledby="role-tab" tabindex="0">
<style>
:root{--brand:#0F6E5D;--brand-dark:#0A4F43;--brand-light:#E6F3F0;--gold:#C08A2E;--ink:#1E2A28;--muted:#6B7876;--bg:#F5F7F6;--line:#E1E7E5;}
.btn-brand{background:var(--brand);border-color:var(--brand);color:#fff;}
.btn-brand:hover{background:var(--brand-dark);border-color:var(--brand-dark);color:#fff;}
.btn-outline-brand{border-color:var(--brand);color:var(--brand);}
.btn-outline-brand:hover{background:var(--brand);color:#fff;}
.card-surface{background:#fff;border:1px solid var(--line);border-radius:12px;}
.settings-nav{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.settings-nav a{border:1px solid var(--line);background:#fff;border-radius:20px;padding:6px 14px;font-size:.83rem;font-weight:600;color:var(--muted);text-decoration:none;}
.settings-nav a.active{background:var(--brand);border-color:var(--brand);color:#fff;}
table.pl-table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);border-bottom:2px solid var(--line);font-weight:700;}
table.pl-table td{vertical-align:middle;}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:700;}
.status-pill .dot{width:6px;height:6px;border-radius:50%;}
.status-active{background:#E9F5EA;color:#2E7D32;} .status-active .dot{background:#2E7D32;}
.status-inactive{background:#EDEFEF;color:#5A6462;} .status-inactive .dot{background:#8C9896;}
.avatar-circle{width:34px;height:34px;border-radius:50%;background:var(--brand-light);color:var(--brand-dark);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;}
.perm-grid th, .perm-grid td{text-align:center;vertical-align:middle;}
.perm-grid th:first-child, .perm-grid td:first-child{text-align:left;}
.role-badge{background:var(--brand-light);color:var(--brand-dark);border-radius:6px;padding:3px 10px;font-size:.78rem;font-weight:700;}
.mock-tag{position:fixed;bottom:16px;right:16px;background:var(--ink);color:#fff;padding:6px 14px;border-radius:20px;font-size:.75rem;font-weight:600;opacity:.85;z-index:1050;}
</style>
  <div class="card-surface p-3 p-md-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div><h6 class="fw-bold mb-0">ผู้ใช้งานระบบ</h6><div class="text-secondary small">รายชื่อผู้ที่สามารถเข้าถึงระบบ Payroll / HR</div></div>
      <button class="btn btn-brand" id="btnAddUser"><i class="fa-solid fa-plus me-1"></i> เพิ่มผู้ใช้งาน</button>
    </div>
    <div class="table-responsive">
      <table class="table pl-table mb-0">
        <thead><tr><th></th><th>ชื่อ</th><th>อีเมล</th><th>บทบาท (Role)</th><th>เข้าใช้ล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
        <tbody id="userBody"></tbody>
      </table>
    </div>
  </div>

  <!-- Roles & Permission Matrix -->
  <div class="card-surface p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div><h6 class="fw-bold mb-0">บทบาทและสิทธิ์การเข้าถึง (Roles &amp; Permissions)</h6><div class="text-secondary small">กำหนดว่าแต่ละบทบาททำอะไรได้บ้างในแต่ละโมดูล</div></div>
      <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มบทบาทใหม่</button>
    </div>
    <div class="table-responsive">
      <table class="table pl-table perm-grid mb-0">
        <thead><tr>
          <th>โมดูล</th>
          <th><span class="role-badge">Admin</span></th>
          <th><span class="role-badge">HR Manager</span></th>
          <th><span class="role-badge">Payroll Officer</span></th>
          <th><span class="role-badge">Approver</span></th>
          <th><span class="role-badge">Employee</span></th>
        </tr></thead>
        <tbody id="permBody"></tbody>
      </table>
    </div>
    <div class="text-secondary small mt-3"><i class="fa-solid fa-circle-check text-brand me-1"></i> เข้าถึงได้ (View/Edit) &nbsp;|&nbsp; <i class="fa-solid fa-eye text-secondary me-1"></i> ดูอย่างเดียว &nbsp;|&nbsp; <i class="fa-solid fa-minus text-secondary me-1"></i> ไม่มีสิทธิ์</div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title fw-bold">เพิ่มผู้ใช้งาน</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">ชื่อ-สกุล <span class="text-danger">*</span></label><input class="form-control"></div>
      <div class="mb-3"><label class="form-label">อีเมล <span class="text-danger">*</span></label><input type="email" class="form-control"></div>
      <div class="mb-3"><label class="form-label">บทบาท <span class="text-danger">*</span></label>
        <select class="form-select"><option>Admin</option><option>HR Manager</option><option>Payroll Officer</option><option>Approver</option><option>Employee</option></select>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-brand" onclick="addUser()">บันทึกและส่งคำเชิญ</button></div>
  </div></div>
</div>

<div class="mock-tag"><i class="fa-solid fa-flask me-1"></i> Mockup — ข้อมูลจำลอง</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
let users = [
  {name:'สุกัญญา รักษ์งาน', email:'sukanya@origami.co', role:'HR Manager', last:'วันนี้ 09:12', status:'active'},
  {name:'ประยุทธ์ ตั้งใจทำ', email:'prayut@origami.co', role:'Payroll Officer', last:'เมื่อวาน 17:40', status:'active'},
  {name:'เอกชัย มั่งมี', email:'ekachai@origami.co', role:'Approver', last:'3 วันก่อน', status:'active'},
  {name:'นภัสสร ทองคำ', email:'napassorn@origami.co', role:'Employee', last:'-', status:'inactive'},
];
function renderUsers(){
  const body=$('#userBody').empty();
  users.forEach(u=>{
    const initials=u.name.split(' ').map(s=>s[0]).join('');
    body.append(`<tr>
      <td><div class="avatar-circle">${initials}</div></td>
      <td class="fw-bold">${u.name}</td>
      <td>${u.email}</td>
      <td><span class="role-badge">${u.role}</span></td>
      <td>${u.last}</td>
      <td><span class="status-pill status-${u.status}"><span class="dot"></span>${u.status==='active'?'ใช้งาน':'ปิดใช้งาน'}</span></td>
      <td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td>
    </tr>`);
  });
}
const modules = ['Dashboard','Employees','Payroll Run','Reports','Settings'];
// matrix[module][role] = 'full' | 'view' | 'none'
const matrix = {
  'Dashboard':      {Admin:'full', 'HR Manager':'full', 'Payroll Officer':'full', 'Approver':'view', 'Employee':'view'},
  'Employees':      {Admin:'full', 'HR Manager':'full', 'Payroll Officer':'view', 'Approver':'none', 'Employee':'none'},
  'Payroll Run':    {Admin:'full', 'HR Manager':'view', 'Payroll Officer':'full', 'Approver':'view', 'Employee':'none'},
  'Reports':        {Admin:'full', 'HR Manager':'full', 'Payroll Officer':'view', 'Approver':'view', 'Employee':'none'},
  'Settings':       {Admin:'full', 'HR Manager':'view', 'Payroll Officer':'none', 'Approver':'none', 'Employee':'none'},
};
function icon(v){
  if(v==='full') return '<i class="fa-solid fa-circle-check text-brand"></i>';
  if(v==='view') return '<i class="fa-solid fa-eye text-secondary"></i>';
  return '<i class="fa-solid fa-minus text-secondary opacity-50"></i>';
}
function renderMatrix(){
  const body=$('#permBody').empty();
  modules.forEach(m=>{
    const row = matrix[m];
    body.append(`<tr><td class="fw-bold">${m}</td><td>${icon(row['Admin'])}</td><td>${icon(row['HR Manager'])}</td><td>${icon(row['Payroll Officer'])}</td><td>${icon(row['Approver'])}</td><td>${icon(row['Employee'])}</td></tr>`);
  });
}
function addUser(){ bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide(); mockToast('ส่งคำเชิญผู้ใช้งานแล้ว (mock)'); }
function mockToast(msg){ const t=$(`<div class="mock-tag" style="right:auto;left:16px;background:var(--brand);">${msg}</div>`); $('body').append(t); setTimeout(()=>t.fadeOut(400,()=>t.remove()),2200); }
$('#btnAddUser').on('click',()=> new bootstrap.Modal(document.getElementById('addUserModal')).show());
renderUsers(); renderMatrix();
</script>
        </div>
        <div class="tab-pane fade" id="department-pane" role="tabpanel" aria-labelledby="department-tab" tabindex="0">
<style>
:root{--brand:#0F6E5D;--brand-dark:#0A4F43;--brand-light:#E6F3F0;--gold:#C08A2E;--ink:#1E2A28;--muted:#6B7876;--bg:#F5F7F6;--line:#E1E7E5;}
.btn-brand{background:var(--brand);border-color:var(--brand);color:#fff;}
.btn-brand:hover{background:var(--brand-dark);border-color:var(--brand-dark);color:#fff;}
.btn-outline-brand{border-color:var(--brand);color:var(--brand);}
.btn-outline-brand:hover{background:var(--brand);color:#fff;}
.card-surface{background:#fff;border:1px solid var(--line);border-radius:12px;}
.settings-nav{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.settings-nav a{border:1px solid var(--line);background:#fff;border-radius:20px;padding:6px 14px;font-size:.83rem;font-weight:600;color:var(--muted);text-decoration:none;}
.settings-nav a.active{background:var(--brand);border-color:var(--brand);color:#fff;}
.nav-tabs .nav-link{color:var(--muted);font-weight:700;border:none;border-bottom:3px solid transparent;}
.nav-tabs .nav-link.active{color:var(--brand-dark);border-bottom:3px solid var(--brand);background:none;}
table.pl-table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);border-bottom:2px solid var(--line);font-weight:700;}
table.pl-table td{vertical-align:middle;}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:700;}
.status-pill .dot{width:6px;height:6px;border-radius:50%;}
.status-active{background:#E9F5EA;color:#2E7D32;} .status-active .dot{background:#2E7D32;}
.mock-tag{position:fixed;bottom:16px;right:16px;background:var(--ink);color:#fff;padding:6px 14px;border-radius:20px;font-size:.75rem;font-weight:600;opacity:.85;z-index:1050;}
</style>
  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-dept" type="button"><i class="fa-solid fa-sitemap me-1"></i> แผนก</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pos" type="button"><i class="fa-solid fa-id-badge me-1"></i> ตำแหน่ง</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-branch" type="button"><i class="fa-solid fa-building me-1"></i> สาขา</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-loc" type="button"><i class="fa-solid fa-location-dot me-1"></i> สถานที่ทำงาน</button></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active card-surface p-3 p-md-4" id="tab-dept">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">รายการแผนก</h6>
        <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มแผนก</button>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>รหัส</th><th>ชื่อแผนก</th><th>หัวหน้าแผนก</th><th class="text-end">จำนวนพนักงาน</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <tr><td>DEP01</td><td class="fw-bold">ฝ่ายขาย</td><td>ธนกร วัฒนกิจ</td><td class="text-end">3</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>DEP02</td><td class="fw-bold">ฝ่ายบัญชี</td><td>ปิยะดา สายใจ</td><td class="text-end">3</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>DEP03</td><td class="fw-bold">ฝ่าย IT</td><td>ศักดิ์ชัย บุญมี</td><td class="text-end">2</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>DEP04</td><td class="fw-bold">ฝ่ายการตลาด</td><td>นภัสสร ทองคำ</td><td class="text-end">2</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>DEP05</td><td class="fw-bold">ฝ่ายผลิต</td><td>รัชนก ไพบูลย์</td><td class="text-end">2</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>

    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-pos">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">รายการตำแหน่ง</h6>
        <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มตำแหน่ง</button>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>รหัส</th><th>ชื่อตำแหน่ง</th><th>แผนก</th><th>ระดับ</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <tr><td>POS01</td><td class="fw-bold">Sales Executive</td><td>ฝ่ายขาย</td><td>Staff</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>POS02</td><td class="fw-bold">Sales Manager</td><td>ฝ่ายขาย</td><td>Manager</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>POS03</td><td class="fw-bold">Developer</td><td>ฝ่าย IT</td><td>Staff</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>POS04</td><td class="fw-bold">Director</td><td>ผู้บริหาร</td><td>Executive</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>

    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-branch">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">รายการสาขา</h6>
        <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มสาขา</button>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>รหัส</th><th>ชื่อสาขา</th><th>ที่อยู่</th><th>จังหวัด</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <tr><td>BR01</td><td class="fw-bold">สำนักงานใหญ่</td><td>เลขที่ 99 ถ.สุขุมวิท</td><td>กรุงเทพมหานคร</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>BR02</td><td class="fw-bold">สาขาเชียงใหม่</td><td>เลขที่ 12 ถ.นิมมานเหมินท์</td><td>เชียงใหม่</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>

    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-loc">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">รายการสถานที่ทำงาน</h6>
        <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มสถานที่ทำงาน</button>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>ชื่อสถานที่</th><th>ประเภท</th><th>ที่อยู่ / ลิงก์</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <tr><td class="fw-bold">สำนักงานใหญ่</td><td>Office</td><td>เลขที่ 99 ถ.สุขุมวิท</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">โรงงานสมุทรปราการ</td><td>Site</td><td>นิคมอุตสาหกรรมบางพลี</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">Work From Home</td><td>Remote</td><td>-</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>
  </div>
        </div>
        <div class="tab-pane fade" id="attendance-pane" role="tabpanel" aria-labelledby="attendance-tab" tabindex="0">
<style>
:root{--brand:#0F6E5D;--brand-dark:#0A4F43;--brand-light:#E6F3F0;--gold:#C08A2E;--ink:#1E2A28;--muted:#6B7876;--bg:#F5F7F6;--line:#E1E7E5;}
.btn-brand{background:var(--brand);border-color:var(--brand);color:#fff;}
.btn-brand:hover{background:var(--brand-dark);border-color:var(--brand-dark);color:#fff;}
.btn-outline-brand{border-color:var(--brand);color:var(--brand);}
.btn-outline-brand:hover{background:var(--brand);color:#fff;}
.card-surface{background:#fff;border:1px solid var(--line);border-radius:12px;}
.settings-nav{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.settings-nav a{border:1px solid var(--line);background:#fff;border-radius:20px;padding:6px 14px;font-size:.83rem;font-weight:600;color:var(--muted);text-decoration:none;}
.settings-nav a.active{background:var(--brand);border-color:var(--brand);color:#fff;}
.nav-tabs .nav-link{color:var(--muted);font-weight:700;border:none;border-bottom:3px solid transparent;}
.nav-tabs .nav-link.active{color:var(--brand-dark);border-bottom:3px solid var(--brand);background:none;}
table.pl-table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);border-bottom:2px solid var(--line);font-weight:700;}
table.pl-table td{vertical-align:middle;}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:700;}
.status-pill .dot{width:6px;height:6px;border-radius:50%;}
.status-active{background:#E9F5EA;color:#2E7D32;} .status-active .dot{background:#2E7D32;}
.badge-holiday{background:#FCF3E3;color:var(--gold);border-radius:6px;padding:3px 8px;font-size:.75rem;font-weight:700;}
.badge-company{background:var(--brand-light);color:var(--brand-dark);border-radius:6px;padding:3px 8px;font-size:.75rem;font-weight:700;}
.mock-tag{position:fixed;bottom:16px;right:16px;background:var(--ink);color:#fff;padding:6px 14px;border-radius:20px;font-size:.75rem;font-weight:600;opacity:.85;z-index:1050;}
</style>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-shift" type="button"><i class="fa-solid fa-clock me-1"></i> กะการทำงาน</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-holiday" type="button"><i class="fa-solid fa-calendar-days me-1"></i> ปฏิทินวันหยุด</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-leave" type="button"><i class="fa-solid fa-umbrella-beach me-1"></i> ประเภทการลา</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ot" type="button"><i class="fa-solid fa-business-time me-1"></i> อัตรา OT</button></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active card-surface p-3 p-md-4" id="tab-shift">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">รายการกะการทำงาน</h6>
        <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มกะ</button>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>ชื่อกะ</th><th>เวลาเข้า</th><th>เวลาออก</th><th>พักเบรก</th><th>ผ่อนผันสาย (นาที)</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <tr><td class="fw-bold">กะปกติ (Office Hours)</td><td>08:30</td><td>17:30</td><td>1 ชม.</td><td>15</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">กะเช้า (Morning Shift)</td><td>06:00</td><td>15:00</td><td>1 ชม.</td><td>10</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">กะบ่าย (Afternoon Shift)</td><td>14:00</td><td>23:00</td><td>1 ชม.</td><td>10</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>

    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-holiday">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">ปฏิทินวันหยุด ปี 2569</h6>
        <div class="d-flex gap-2">
          <select class="form-select form-select-sm" style="width:110px;"><option>2569</option><option>2568</option></select>
          <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มวันหยุด</button>
        </div>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>วันที่</th><th>ชื่อวันหยุด</th><th>ประเภท</th><th>ประจำทุกปี</th><th></th></tr></thead>
        <tbody>
          <tr><td>01/01/2569</td><td class="fw-bold">วันขึ้นปีใหม่</td><td><span class="badge-holiday">Public</span></td><td><i class="fa-solid fa-check text-brand"></i></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>08/04/2569</td><td class="fw-bold">วันจ่ายโบนัสประจำปี</td><td><span class="badge-company">Company</span></td><td><i class="fa-solid fa-xmark text-secondary"></i></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>13/04/2569</td><td class="fw-bold">วันสงกรานต์</td><td><span class="badge-holiday">Public</span></td><td><i class="fa-solid fa-check text-brand"></i></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td>01/05/2569</td><td class="fw-bold">วันแรงงานแห่งชาติ</td><td><span class="badge-holiday">Public</span></td><td><i class="fa-solid fa-check text-brand"></i></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>

    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-leave">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">ประเภทการลา</h6>
        <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มประเภทการลา</button>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>ประเภทการลา</th><th class="text-end">โควตา (วัน/ปี)</th><th>รับค่าจ้าง</th><th>สะสมข้ามปีได้</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <tr><td class="fw-bold">ลาพักร้อน</td><td class="text-end">6</td><td><i class="fa-solid fa-check text-brand"></i></td><td>สูงสุด 3 วัน</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">ลาป่วย</td><td class="text-end">30</td><td><i class="fa-solid fa-check text-brand"></i></td><td>ไม่ได้</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">ลากิจ</td><td class="text-end">3</td><td><i class="fa-solid fa-check text-brand"></i></td><td>ไม่ได้</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">ลาคลอด</td><td class="text-end">98</td><td><i class="fa-solid fa-check text-brand"></i></td><td>ไม่ได้</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">ลาไม่รับค่าจ้าง</td><td class="text-end">-</td><td><i class="fa-solid fa-xmark text-secondary"></i></td><td>ไม่ได้</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>

    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-ot">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">อัตราค่าล่วงเวลา (OT Rate)</h6>
        <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มเงื่อนไข</button>
      </div>
      <table class="table pl-table mb-0">
        <thead><tr><th>เงื่อนไข</th><th class="text-end">ตัวคูณ (เท่าของค่าแรงต่อชม.)</th><th>สถานะ</th><th></th></tr></thead>
        <tbody>
          <tr><td class="fw-bold">วันทำงานปกติ (Weekday OT)</td><td class="text-end">x1.5</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">วันหยุดประจำสัปดาห์ (Weekend OT)</td><td class="text-end">x2.0</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
          <tr><td class="fw-bold">วันหยุดนักขัตฤกษ์ (Public Holiday OT)</td><td class="text-end">x3.0</td><td><span class="status-pill status-active"><span class="dot"></span>ใช้งาน</span></td><td><button class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen"></i></button></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
        </div>
    </div>
</div>
<script>
    $(document).ready(function () {
        const countryMasterConfig = {
            "TH": {
                "tax_label": "Tax ID / เลขประจำตัวผู้เสียภาษี",
                "currency": "THB",
                "timezone": "Asia/Bangkok",
                "fields": [
                    { "name": "th_branch_code", "label": "Tax Branch Code", "placeholder": "e.g., 00000 (Head Office)", "required": true },
                    { "name": "th_sso_id", "label": "Social Security Employer ID", "placeholder": "10 digits Number", "required": true }
                ]
            },
            "SG": {
                "tax_label": "Unique Entity Number (UEN)",
                "currency": "SGD",
                "timezone": "Asia/Singapore",
                "fields": [
                    { "name": "sg_csn", "label": "CPF Submission Number (CSN)", "placeholder": "UEN + CPF Payment Code", "required": true }
                ]
            },
            "MY": {
                "tax_label": "Income Tax Number",
                "currency": "MYR",
                "timezone": "Asia/Kuala_Lumpur",
                "fields": [
                    { "name": "my_epf_no", "label": "EPF Employer Number", "placeholder": "", "required": true },
                    { "name": "my_socso_no", "label": "SOCSO Number", "placeholder": "", "required": true }
                ]
            },
            "US": {
                "tax_label": "EIN",
                "currency": "USD",
                "timezone": "America/New_York",
                "fields": [
                    { "name": "us_sui_account_number", "label": "State Unemployment ID (SUI)", "placeholder": "State-issued SUI Account Number", "required": true }
                ]
            }
        };
        function renderCountrySpecificForm(countryCode) {
            const $container = $('#dynamic_statutory_fields_container');
            $container.empty();
            const config = countryMasterConfig[countryCode];
            if (!config) {
                $('#tax_id_label').text('Tax ID / EIN');
                return;
            }
            $('#tax_id_label').text(config.tax_label);
            $('#base_currency').val(config.currency).trigger('change');
            $('#company_timezone').val(config.timezone).trigger('change');
            config.fields.forEach(field => {
                const requiredAttribute = field.required ? 'required' : '';
                const redAsterisk = field.required ? '<span class="text-danger">*</span>' : '';
                const elementHtml = `
                    <div class="col-sm-2 mt-3">
                        <label class="form-label"><span>${field.label}</span> ${redAsterisk}</label>
                    </div>
                    <div class="col-sm-4 mt-3">
                        <input type="text" class="form-control" name="${field.name}" placeholder="${field.placeholder}" ${requiredAttribute}>
                    </div>
                `;
                $container.append(elementHtml);
            });
        }
        let bankAccountIndex = 0;
        function addBankAccountRow(isPrimary = false) {
            bankAccountIndex++;
            const badgeText = isPrimary ? 'Primary Account' : 'Secondary Account';
            const badgeClass = isPrimary ? 'bg-primary' : 'bg-secondary';
            const deleteButton = !isPrimary ? `<button type="button" class="btn btn-sm btn-outline-danger btn-remove-bank"><i class="fas fa-trash-alt"></i> Remove</button>` : '';
            const bankCardHtml = `
                <div class="card bank-account-card mb-3 shadow-sm border">
                    <div class="card-header d-flex justify-content-between align-items-center bg-light py-2">
                        <span class="badge ${badgeClass}">${badgeText}</span>
                        ${deleteButton}
                    </div>
                    <div class="card-body py-2 pb-4">
                        <div class="row">
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][bank_name]" placeholder="e.g., Kasikorn Bank, DBS" required>
                            </div>
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">Account Number / IBAN <span class="text-danger">*</span></label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][account_no]" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">SWIFT Code / BIC</label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][swift_code]" placeholder="e.g., BKCHTHBK">
                            </div>
                            <div class="col-sm-2 mt-3">
                                <label class="form-label">Company Bank Code</label>
                            </div>
                            <div class="col-sm-4 mt-3">
                                <input type="text" class="form-control" name="banks[${bankAccountIndex}][service_code]" placeholder="e.g., Corporate ID">
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('#dynamic_bank_accounts_container').append(bankCardHtml);
        }
        $('#registered_country').change(function () {
            renderCountrySpecificForm($(this).val());
        });
        $('#btnAddBank').click(function() {
            addBankAccountRow(false);
        });
        $(document).on('click', '.btn-remove-bank', function() {
            $(this).closest('.bank-account-card').remove();
        });
        $('#btnNextToBanks').click(function() {
            const triggerEl = document.querySelector('#companySetupTabs button[data-bs-target="#banks-pane"]');
            bootstrap.Tab.getInstance(triggerEl).show();
        });
        $('#btnBackToProfile').click(function() {
            const triggerEl = document.querySelector('#companySetupTabs button[data-bs-target="#profile-pane"]');
            bootstrap.Tab.getInstance(triggerEl).show();
        });
        const defaultInitialCountry = "TH";
        $('#registered_country').val(defaultInitialCountry); 
        renderCountrySpecificForm(defaultInitialCountry);
        addBankAccountRow(true);
    });
</script>