<div class="container container-body">
  <nav aria-label="breadcrumb">
    <h5 class="payroll-breadcrumb mt-5 mb-3">
      <span><i class="fas fa-home me-1"></i> Payroll</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span>Settings</span>
      <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
      <span class="bc-current">ภาษี &amp; กองทุน</span>
    </h5>
  </nav>

  <div class="settings-nav">
    <a href="#">ตั้งค่าบริษัท</a><a href="#">รอบการจ่าย</a><a href="#">รายได้ / รายหัก</a>
    <a href="#">บัญชีธนาคาร</a><a href="#">สิทธิ์ผู้ใช้งาน</a>
    <a class="active" href="#">ภาษี &amp; กองทุน</a><a href="#">โครงสร้างองค์กร</a><a href="#">เวลาทำงาน &amp; วันลา</a>
    <a href="#">อนุมัติ &amp; เอกสาร</a><a href="#">การแจ้งเตือน</a>
  </div>

  <ul class="nav nav-tabs mb-4" id="taxTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-tax" type="button"><i class="fa-solid fa-percent me-1"></i> ตารางอัตราภาษี</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sso" type="button"><i class="fa-solid fa-hospital-user me-1"></i> ประกันสังคม</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pvd" type="button"><i class="fa-solid fa-piggy-bank me-1"></i> กองทุนสำรองเลี้ยงชีพ</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-allow" type="button"><i class="fa-solid fa-people-roof me-1"></i> ค่าลดหย่อนภาษี</button></li>
  </ul>

  <div class="tab-content">
    <!-- TAX TABLE -->
    <div class="tab-pane fade show active card-surface p-3 p-md-4" id="tab-tax">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0"><label class="label label-head bg-head-first rounded-2 text-white">1</label> ตารางอัตราภาษีเงินได้บุคคลธรรมดา (ก้าวหน้า)</h6>
        <button class="btn btn-outline-brand btn-sm" onclick="addTaxRow()"><i class="fa-solid fa-plus me-1"></i> เพิ่มขั้นภาษี</button>
      </div>
      <div class="table-responsive">
        <table class="table pl-table mb-0">
          <thead><tr><th>เงินได้สุทธิตั้งแต่ (บาท)</th><th>ถึง (บาท)</th><th>อัตราภาษี (%)</th><th style="width:60px;"></th></tr></thead>
          <tbody id="taxBody"></tbody>
        </table>
      </div>
      <div class="d-flex justify-content-end mt-3"><button class="btn btn-brand">บันทึก</button></div>
    </div>

    <!-- SSO -->
    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-sso">
      <h6 class="fw-bold mb-3"><label class="label label-head bg-head-first rounded-2 text-white">2</label> ประกันสังคม (SSO)</h6>
      <div class="row">
        <div class="col-sm-3 mt-3"><label class="form-label">อัตราลูกจ้าง (%)</label></div>
        <div class="col-sm-3 mt-3"><input type="number" class="form-control" value="5.00" step="0.01"></div>
        <div class="col-sm-3 mt-3"><label class="form-label">อัตรานายจ้าง (%)</label></div>
        <div class="col-sm-3 mt-3"><input type="number" class="form-control" value="5.00" step="0.01"></div>
      </div>
      <div class="row">
        <div class="col-sm-3 mt-3"><label class="form-label">ฐานเงินเดือนขั้นต่ำ (บาท)</label></div>
        <div class="col-sm-3 mt-3"><input type="number" class="form-control" value="1650"></div>
        <div class="col-sm-3 mt-3"><label class="form-label">ฐานเงินเดือนสูงสุด (บาท)</label></div>
        <div class="col-sm-3 mt-3"><input type="number" class="form-control" value="15000"></div>
      </div>
      <div class="row">
        <div class="col-sm-3 mt-3"><label class="form-label">เพดานเงินสมทบสูงสุด/เดือน</label></div>
        <div class="col-sm-3 mt-3"><input type="number" class="form-control" value="750"></div>
        <div class="col-sm-3 mt-3"><label class="form-label">มีผลบังคับใช้ตั้งแต่</label></div>
        <div class="col-sm-3 mt-3"><div class="input-group"><input class="form-control" value="01/01/2569"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div></div>
      </div>
      <div class="d-flex justify-content-end mt-4"><button class="btn btn-brand">บันทึก</button></div>
    </div>

    <!-- PVD -->
    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-pvd">
      <h6 class="fw-bold mb-3"><label class="label label-head bg-head-first rounded-2 text-white">3</label> กองทุนสำรองเลี้ยงชีพ (PVD)</h6>
      <div class="row">
        <div class="col-sm-12 mt-1"><input type="checkbox" class="me-2" checked> เปิดใช้งานกองทุนสำรองเลี้ยงชีพในบริษัท</div>
      </div>
      <div class="row">
        <div class="col-sm-3 mt-3"><label class="form-label">ชื่อกองทุน</label></div>
        <div class="col-sm-9 mt-3"><input class="form-control" value="กองทุนสำรองเลี้ยงชีพ ออริกามิ ซึ่งจดทะเบียนแล้ว"></div>
      </div>
      <div class="row">
        <div class="col-sm-3 mt-3"><label class="form-label">อัตราลูกจ้างเริ่มต้น (%)</label></div>
        <div class="col-sm-3 mt-3">
          <select class="form-select"><option>2%</option><option selected>3%</option><option>5%</option><option>ให้พนักงานเลือกเอง</option></select>
        </div>
        <div class="col-sm-3 mt-3"><label class="form-label">อัตรานายจ้างเริ่มต้น (%)</label></div>
        <div class="col-sm-3 mt-3">
          <select class="form-select"><option>2%</option><option selected>3%</option><option>5%</option></select>
        </div>
      </div>
      <div class="row">
        <div class="col-sm-3 mt-3"><label class="form-label">คุณสมบัติ (อายุงานขั้นต่ำ)</label></div>
        <div class="col-sm-3 mt-3"><input class="form-control" value="120 วัน"></div>
      </div>
      <div class="d-flex justify-content-end mt-4"><button class="btn btn-brand">บันทึก</button></div>
    </div>

    <!-- TAX ALLOWANCE -->
    <div class="tab-pane fade card-surface p-3 p-md-4" id="tab-allow">
      <h6 class="fw-bold mb-3"><label class="label label-head bg-head-first rounded-2 text-white">4</label> ค่าลดหย่อนภาษีมาตรฐาน</h6>
      <div class="table-responsive">
        <table class="table pl-table mb-0">
          <thead><tr><th>รายการ</th><th class="text-end">จำนวนต่อหน่วย (บาท)</th><th>หมายเหตุ</th></tr></thead>
          <tbody>
            <tr><td>ค่าลดหย่อนส่วนตัว</td><td class="amount"><input type="number" class="form-control text-end" value="60000"></td><td class="text-secondary small">ทุกคน</td></tr>
            <tr><td>ค่าลดหย่อนคู่สมรส (ไม่มีรายได้)</td><td class="amount"><input type="number" class="form-control text-end" value="60000"></td><td class="text-secondary small">ต่อคู่สมรส 1 คน</td></tr>
            <tr><td>ค่าลดหย่อนบุตร (คนแรกเป็นต้นไป)</td><td class="amount"><input type="number" class="form-control text-end" value="30000"></td><td class="text-secondary small">ต่อบุตร 1 คน สูงสุดตามกฎหมาย</td></tr>
            <tr><td>ค่าลดหย่อนบิดา/มารดา</td><td class="amount"><input type="number" class="form-control text-end" value="30000"></td><td class="text-secondary small">ต่อท่าน สูงสุด 4 ท่าน</td></tr>
            <tr><td>ค่าลดหย่อนประกันสังคม</td><td class="amount"><input type="number" class="form-control text-end" value="9000"></td><td class="text-secondary small">ตามที่จ่ายจริง สูงสุด</td></tr>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-end mt-4"><button class="btn btn-brand">บันทึก</button></div>
    </div>
  </div>
</div>
<script>
let taxBrackets = [
  {from:0, to:150000, rate:0},
  {from:150001, to:300000, rate:5},
  {from:300001, to:500000, rate:10},
  {from:500001, to:750000, rate:15},
  {from:750001, to:1000000, rate:20},
  {from:1000001, to:2000000, rate:25},
  {from:2000001, to:5000000, rate:30},
  {from:5000001, to:null, rate:35},
];
function fmt(n){ return n===null ? 'ขึ้นไป' : Number(n).toLocaleString('en-US'); }
function renderTax(){
  const body=$('#taxBody').empty();
  taxBrackets.forEach((b,i)=>{
    body.append(`<tr>
      <td class="amount">${fmt(b.from)}</td>
      <td class="amount">${fmt(b.to)}</td>
      <td class="amount"><input type="number" class="form-control text-end" value="${b.rate}" style="max-width:110px;margin-left:auto;"></td>
      <td><button class="btn btn-sm btn-outline-danger" onclick="removeTaxRow(${i})"><i class="fa-solid fa-trash"></i></button></td>
    </tr>`);
  });
}
function addTaxRow(){ const last=taxBrackets[taxBrackets.length-1]; last.to = last.to || (last.from+1000000); taxBrackets.push({from:last.to+1, to:null, rate:35}); renderTax(); }
function removeTaxRow(i){ taxBrackets.splice(i,1); renderTax(); }
renderTax();
</script>