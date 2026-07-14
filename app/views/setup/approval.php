<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>อนุมัติ &amp; เอกสาร — Settings</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--brand:#0F6E5D;--brand-dark:#0A4F43;--brand-light:#E6F3F0;--gold:#C08A2E;--ink:#1E2A28;--muted:#6B7876;--bg:#F5F7F6;--line:#E1E7E5;}
body{font-family:'Noto Sans Thai',sans-serif;background:var(--bg);color:var(--ink);}
.container-body{max-width:1180px;margin:0 auto;padding-bottom:60px;}
.payroll-breadcrumb{color:var(--muted);font-weight:500;font-size:.95rem;}
.payroll-breadcrumb .bc-current{color:var(--ink);font-weight:700;}
.payroll-breadcrumb .bc-separator{margin:0 6px;color:#B7C2BF;}
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
.step-row{display:flex;align-items:center;gap:12px;border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#FAFBFB;}
.step-order{width:30px;height:30px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;}
.tpl-card{border:2px solid var(--line);border-radius:12px;padding:16px;cursor:pointer;text-align:center;}
.tpl-card.selected{border-color:var(--brand);background:var(--brand-light);}
.mock-tag{position:fixed;bottom:16px;right:16px;background:var(--ink);color:#fff;padding:6px 14px;border-radius:20px;font-size:.75rem;font-weight:600;opacity:.85;z-index:1050;}
</style>
</head>
<body>
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
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-flow" type="button"><i class="fa-solid fa-diagram-project me-1"></i> ลำดับผู้อนุมัติ</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-run" type="button"><i class="fa-solid fa-hashtag me-1"></i> เลขที่เอกสาร</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tpl" type="button"><i class="fa-solid fa-file-invoice me-1"></i> เทมเพลตสลิปเงินเดือน</button></li>
  </ul>

  <div class="tab-content">
    <!-- APPROVAL WORKFLOW -->
    <div class="tab-pane fade show active card-surface p-3 p-md-4" id="tab-flow">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h6 class="fw-bold mb-0">ลำดับผู้อนุมัติงวดเงินเดือน</h6><div class="text-secondary small">เรียงลำดับขั้นตอนการอนุมัติก่อนจ่ายเงินจริง</div></div>
        <button class="btn btn-outline-brand btn-sm" onclick="addStep()"><i class="fa-solid fa-plus me-1"></i> เพิ่มขั้นตอน</button>
      </div>
      <div id="stepList"></div>
      <div class="d-flex justify-content-end mt-3"><button class="btn btn-brand">บันทึกลำดับการอนุมัติ</button></div>
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
</div>

<div class="mock-tag"><i class="fa-solid fa-flask me-1"></i> Mockup — ข้อมูลจำลอง</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
let steps = [
  {role:'หัวหน้าฝ่ายบุคคล (HR Manager)', name:'สุกัญญา รักษ์งาน'},
  {role:'ผู้จัดการฝ่ายบัญชี (Finance Manager)', name:'ประยุทธ์ ตั้งใจทำ'},
  {role:'ผู้บริหารระดับสูง (Director)', name:'เอกชัย มั่งมี'},
];
function renderSteps(){
  const wrap=$('#stepList').empty();
  steps.forEach((s,i)=>{
    wrap.append(`<div class="step-row">
      <div class="step-order">${i+1}</div>
      <div class="flex-grow-1">
        <input class="form-control form-control-sm mb-1" value="${s.role}" style="font-weight:700;">
        <input class="form-control form-control-sm" value="${s.name}">
      </div>
      <div class="d-flex flex-column gap-1">
        <button class="btn btn-sm btn-outline-secondary" ${i===0?'disabled':''} onclick="moveStep(${i},-1)"><i class="fa-solid fa-arrow-up"></i></button>
        <button class="btn btn-sm btn-outline-secondary" ${i===steps.length-1?'disabled':''} onclick="moveStep(${i},1)"><i class="fa-solid fa-arrow-down"></i></button>
      </div>
      <button class="btn btn-sm btn-outline-danger" onclick="removeStep(${i})"><i class="fa-solid fa-trash"></i></button>
    </div>`);
  });
}
function moveStep(i,dir){ const j=i+dir; [steps[i],steps[j]]=[steps[j],steps[i]]; renderSteps(); }
function removeStep(i){ steps.splice(i,1); renderSteps(); }
function addStep(){ steps.push({role:'ผู้อนุมัติใหม่', name:'ระบุชื่อผู้อนุมัติ'}); renderSteps(); }
function selectTpl(el){ $('.tpl-card').removeClass('selected'); $(el).addClass('selected'); }
function mockToast(msg){ const t=$(`<div class="mock-tag" style="right:auto;left:16px;background:var(--brand);">${msg}</div>`); $('body').append(t); setTimeout(()=>t.fadeOut(400,()=>t.remove()),2200); }
renderSteps();
</script>
</body></html>