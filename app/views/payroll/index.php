<style>
  :root{
    --brand:#0F6E5D;
    --brand-dark:#0A4F43;
    --brand-light:#E6F3F0;
    --gold:#C08A2E;
    --ink:#1E2A28;
    --muted:#6B7876;
    --bg:#F5F7F6;
    --line:#E1E7E5;
  }

  .btn-brand{background:var(--brand);border-color:var(--brand);color:#fff;}
  .btn-brand:hover{background:var(--brand-dark);border-color:var(--brand-dark);color:#fff;}
  .btn-outline-brand{border-color:var(--brand);color:var(--brand);}
  .btn-outline-brand:hover{background:var(--brand);color:#fff;}
  .bg-brand{background:var(--brand)!important;}
  .text-brand{color:var(--brand)!important;}

  .label-head{
    display:inline-flex;align-items:center;justify-content:center;
    width:26px;height:26px;font-size:.85rem;font-weight:700;
  }
  .bg-head-first{background:var(--brand);}

  .card-surface{
    background:#fff;border:1px solid var(--line);border-radius:12px;
  }

  /* Stat cards on the list page */
  .stat-card{
    background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px 20px;
  }
  .stat-card .stat-label{font-size:.8rem;color:var(--muted);font-weight:600;letter-spacing:.02em;text-transform:uppercase;}
  .stat-card .stat-value{font-size:1.6rem;font-weight:700;color:var(--ink);}
  .stat-card .stat-icon{
    width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;
    background:var(--brand-light);color:var(--brand);font-size:1.1rem;
  }

  /* Status badges */
  .status-pill{
    display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;
    font-size:.78rem;font-weight:700;letter-spacing:.02em;
  }
  .status-pill .dot{width:6px;height:6px;border-radius:50%;}
  .status-draft{background:#EDEFEF;color:#5A6462;}
  .status-draft .dot{background:#8C9896;}
  .status-calculated{background:#EAF1FB;color:#2A5FA8;}
  .status-calculated .dot{background:#2A5FA8;}
  .status-pending{background:#FCF3E3;color:var(--gold);}
  .status-pending .dot{background:var(--gold);}
  .status-approved{background:#E9F5EA;color:#2E7D32;}
  .status-approved .dot{background:#2E7D32;}
  .status-paid{background:var(--brand-light);color:var(--brand-dark);}
  .status-paid .dot{background:var(--brand);}
  .status-rejected{background:#FBEAEA;color:#B23A3A;}
  .status-rejected .dot{background:#B23A3A;}

  table.payroll-table thead th{
    font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);
    border-bottom:2px solid var(--line);font-weight:700;white-space:nowrap;
  }
  table.payroll-table td{vertical-align:middle;}
  table.payroll-table tbody tr:hover{background:#FAFBFB;}

  /* Wizard stepper */
  .stepper{display:flex;align-items:flex-start;gap:0;margin-bottom:8px;}
  .step-item{flex:1;position:relative;text-align:center;}
  .step-item:not(:last-child)::after{
    content:'';position:absolute;top:19px;left:calc(50% + 24px);width:calc(100% - 48px);
    height:2px;background:var(--line);z-index:0;
  }
  .step-item.done:not(:last-child)::after{background:var(--brand);}
  .step-circle{
    width:38px;height:38px;border-radius:50%;background:#fff;border:2px solid var(--line);
    display:flex;align-items:center;justify-content:center;margin:0 auto 8px;
    position:relative;z-index:1;font-weight:700;color:var(--muted);font-size:.95rem;
  }
  .step-item.active .step-circle{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
  .step-item.done .step-circle{border-color:var(--brand);background:var(--brand);color:#fff;}
  .step-label{font-size:.82rem;font-weight:600;color:var(--muted);}
  .step-item.active .step-label{color:var(--ink);}
  .step-item.done .step-label{color:var(--brand-dark);}

  .amount{font-variant-numeric:tabular-nums;text-align:right;}
  .table-total-row td{font-weight:700;border-top:2px solid var(--line);background:#FAFBFB;}

  .emp-select-row.selected{background:var(--brand-light);}
  .chip-filter{
    border:1px solid var(--line);background:#fff;border-radius:20px;padding:5px 14px;
    font-size:.85rem;font-weight:600;color:var(--muted);cursor:pointer;
  }
  .chip-filter.active{background:var(--brand);border-color:var(--brand);color:#fff;}

  .approval-track{border-left:2px solid var(--line);margin-left:19px;}
  .approval-node{position:relative;padding:2px 0 24px 28px;}
  .approval-node:last-child{padding-bottom:2px;}
  .approval-node::before{
    content:'';position:absolute;left:-9px;top:2px;width:16px;height:16px;border-radius:50%;
    background:#fff;border:2px solid var(--line);
  }
  .approval-node.done::before{background:var(--brand);border-color:var(--brand);}
  .approval-node.rejected::before{background:#B23A3A;border-color:#B23A3A;}
  .approval-node.current::before{background:var(--gold);border-color:var(--gold);}

  .mock-tag{
    position:fixed;bottom:16px;right:16px;background:var(--ink);color:#fff;
    padding:6px 14px;border-radius:20px;font-size:.75rem;font-weight:600;opacity:.85;z-index:1050;
  }
  #wizardView{display:none;}
</style>

<div class="container container-body">
    <nav aria-label="breadcrumb">
        <h5 class="payroll-breadcrumb mt-5 mb-5">
            <span class="bc-root"><i class="fas fa-home me-1"></i> Payroll</span>
            <span class="bc-separator"><i class="fas fa-chevron-right"></i></span>
            <span class="bc-current" data-i18n="company_setup">Payroll Process</span>
        </h5>
    </nav>

  <!-- ============ LIST VIEW ============ -->
  <div id="listView">

    <div class="row g-3 mb-4">
      <div class="col-sm-3">
        <div class="stat-card d-flex justify-content-between align-items-start">
          <div><div class="stat-label">งวดที่จ่ายแล้ว</div><div class="stat-value">2</div></div>
          <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
        </div>
      </div>
      <div class="col-sm-3">
        <div class="stat-card d-flex justify-content-between align-items-start">
          <div><div class="stat-label">รออนุมัติ</div><div class="stat-value">1</div></div>
          <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
        </div>
      </div>
      <div class="col-sm-3">
        <div class="stat-card d-flex justify-content-between align-items-start">
          <div><div class="stat-label">พนักงานทั้งหมด</div><div class="stat-value">12</div></div>
          <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
        </div>
      </div>
      <div class="col-sm-3">
        <div class="stat-card d-flex justify-content-between align-items-start">
          <div><div class="stat-label">ยอดจ่ายงวดล่าสุด</div><div class="stat-value">468,500</div></div>
          <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
        </div>
      </div>
    </div>

    <div class="card-surface p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">รายการงวดเงินเดือน</h6>
        <button class="btn btn-brand" id="btnNewRun"><i class="fa-solid fa-plus me-1"></i> สร้างงวดใหม่</button>
      </div>
      <div class="table-responsive">
        <table class="table payroll-table mb-0">
          <thead>
            <tr>
              <th>งวด</th>
              <th>วันที่ตัดรอบ</th>
              <th>วันที่จ่าย</th>
              <th class="text-end">จำนวนพนักงาน</th>
              <th class="text-end">ยอดสุทธิ (บาท)</th>
              <th>สถานะ</th>
              <th style="width:120px;"></th>
            </tr>
          </thead>
          <tbody id="runListBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ============ WIZARD VIEW ============ -->
  <div id="wizardView">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h5 class="fw-bold mb-0" id="wizardTitle">สร้างงวดเงินเดือนใหม่</h5>
        <div class="text-secondary small" id="wizardSubtitle">ระบุช่วงเวลาของงวดเพื่อเริ่มดำเนินการ</div>
      </div>
      <button class="btn btn-outline-secondary" id="btnBackToList"><i class="fa-solid fa-arrow-left me-1"></i> กลับไปหน้ารายการ</button>
    </div>

    <div class="card-surface p-3 p-md-4 mb-4">
      <div class="stepper">
        <div class="step-item" data-step="1"><div class="step-circle">1</div><div class="step-label">กำหนดงวด</div></div>
        <div class="step-item" data-step="2"><div class="step-circle">2</div><div class="step-label">เลือกพนักงาน</div></div>
        <div class="step-item" data-step="3"><div class="step-circle">3</div><div class="step-label">คำนวณเงินเดือน</div></div>
        <div class="step-item" data-step="4"><div class="step-circle">4</div><div class="step-label">ตรวจสอบ &amp; อนุมัติ</div></div>
        <div class="step-item" data-step="5"><div class="step-circle">5</div><div class="step-label">จ่ายเงิน</div></div>
      </div>
    </div>

    <!-- STEP 1: Period -->
    <div class="wizard-step card-surface p-3 p-md-4 mb-4" data-step-panel="1">
      <h6 class="text-secondary fw-bold mb-3">
        <label class="label label-head bg-head-first rounded-2 text-white">1</label>
        กำหนดช่วงเวลาของงวด
      </h6>
      <div class="row">
        <div class="col-sm-2 mt-3"><label class="form-label">ชื่องวด <span class="text-danger">*</span></label></div>
        <div class="col-sm-4 mt-3"><input type="text" class="form-control" id="periodName" value="งวดที่ 3 - กรกฎาคม 2569"></div>
        <div class="col-sm-2 mt-3"><label class="form-label">ประเภทงวด</label></div>
        <div class="col-sm-4 mt-3">
          <select class="form-select" id="periodType">
            <option>รายเดือน (Monthly)</option>
            <option>ราย 15 วัน (Semi-monthly)</option>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="col-sm-2 mt-3"><label class="form-label">วันที่เริ่มตัดรอบ <span class="text-danger">*</span></label></div>
        <div class="col-sm-4 mt-3">
          <div class="input-group"><input type="text" class="form-control" value="01/07/2569"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
        </div>
        <div class="col-sm-2 mt-3"><label class="form-label">วันที่สิ้นสุดรอบ <span class="text-danger">*</span></label></div>
        <div class="col-sm-4 mt-3">
          <div class="input-group"><input type="text" class="form-control" value="31/07/2569"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
        </div>
      </div>
      <div class="row">
        <div class="col-sm-2 mt-3"><label class="form-label">วันที่จ่ายเงิน <span class="text-danger">*</span></label></div>
        <div class="col-sm-4 mt-3">
          <div class="input-group"><input type="text" class="form-control" value="31/07/2569"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
        </div>
        <div class="col-sm-2 mt-3"><label class="form-label">ขอบเขต</label></div>
        <div class="col-sm-4 mt-3">
          <select class="form-select">
            <option>ทุกสาขา / ทุกแผนก</option>
            <option>เฉพาะสำนักงานใหญ่</option>
          </select>
        </div>
      </div>
      <div class="d-flex justify-content-end mt-4">
        <button class="btn btn-brand" onclick="goToStep(2)">ถัดไป <i class="fa-solid fa-arrow-right ms-1"></i></button>
      </div>
    </div>

    <!-- STEP 2: Select Employees -->
    <div class="wizard-step card-surface p-3 p-md-4 mb-4" data-step-panel="2" style="display:none;">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-secondary fw-bold mb-0">
          <label class="label label-head bg-head-first rounded-2 text-white">2</label>
          เลือกพนักงานที่จะรวมในงวดนี้
        </h6>
        <div class="text-secondary small">เลือกแล้ว <strong id="selectedCount" class="text-brand">12</strong> / 12 คน</div>
      </div>
      <div class="d-flex gap-2 mb-3 flex-wrap">
        <span class="chip-filter active" data-dept="all">ทั้งหมด</span>
        <span class="chip-filter" data-dept="ฝ่ายขาย">ฝ่ายขาย</span>
        <span class="chip-filter" data-dept="ฝ่ายบัญชี">ฝ่ายบัญชี</span>
        <span class="chip-filter" data-dept="ฝ่าย IT">ฝ่าย IT</span>
        <span class="chip-filter" data-dept="ฝ่ายการตลาด">ฝ่ายการตลาด</span>
        <span class="chip-filter" data-dept="ฝ่ายผลิต">ฝ่ายผลิต</span>
        <span class="chip-filter" data-dept="ผู้บริหาร">ผู้บริหาร</span>
      </div>
      <div class="table-responsive">
        <table class="table payroll-table mb-0">
          <thead>
            <tr>
              <th style="width:40px;"><input type="checkbox" id="chkSelectAll" checked></th>
              <th>รหัส</th>
              <th>ชื่อ-สกุล</th>
              <th>แผนก</th>
              <th>ตำแหน่ง</th>
              <th class="text-end">เงินเดือนฐาน</th>
            </tr>
          </thead>
          <tbody id="employeeSelectBody"></tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between mt-4">
        <button class="btn btn-outline-secondary" onclick="goToStep(1)"><i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ</button>
        <button class="btn btn-brand" onclick="goToStep(3)">ถัดไป <i class="fa-solid fa-arrow-right ms-1"></i></button>
      </div>
    </div>

    <!-- STEP 3: Calculate -->
    <div class="wizard-step card-surface p-3 p-md-4 mb-4" data-step-panel="3" style="display:none;">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-secondary fw-bold mb-0">
          <label class="label label-head bg-head-first rounded-2 text-white">3</label>
          คำนวณเงินเดือน
        </h6>
        <button class="btn btn-outline-brand btn-sm" id="btnCalculate"><i class="fa-solid fa-calculator me-1"></i> เริ่มคำนวณ</button>
      </div>
      <div id="calcEmptyState" class="text-center text-secondary py-5">
        <i class="fa-solid fa-calculator fa-2x mb-3 d-block text-secondary opacity-50"></i>
        ยังไม่ได้คำนวณ กด "เริ่มคำนวณ" เพื่อประมวลผลเงินเดือน OT เบี้ยเลี้ยง และภาษี ของพนักงานที่เลือกไว้
      </div>
      <div id="calcResultWrap" style="display:none;">
        <div class="table-responsive">
          <table class="table payroll-table mb-0">
            <thead>
              <tr>
                <th>ชื่อ-สกุล</th>
                <th class="text-end">เงินเดือนฐาน</th>
                <th class="text-end">OT</th>
                <th class="text-end">เบี้ยเลี้ยง/ค่าตำแหน่ง</th>
                <th class="text-end">รวมรายได้</th>
                <th class="text-end">ประกันสังคม</th>
                <th class="text-end">ภาษี หัก ณ ที่จ่าย</th>
                <th class="text-end">ยอดจ่ายสุทธิ</th>
              </tr>
            </thead>
            <tbody id="calcResultBody"></tbody>
            <tfoot>
              <tr class="table-total-row">
                <td>รวมทั้งหมด</td>
                <td class="amount" id="sumBase">-</td>
                <td class="amount" id="sumOt">-</td>
                <td class="amount" id="sumAllow">-</td>
                <td class="amount" id="sumGross">-</td>
                <td class="amount" id="sumSso">-</td>
                <td class="amount" id="sumTax">-</td>
                <td class="amount" id="sumNet">-</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <div class="d-flex justify-content-between mt-4">
        <button class="btn btn-outline-secondary" onclick="goToStep(2)"><i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ</button>
        <button class="btn btn-brand" id="btnToReview" onclick="goToStep(4)" disabled>ถัดไป <i class="fa-solid fa-arrow-right ms-1"></i></button>
      </div>
    </div>

    <!-- STEP 4: Review & Approve -->
    <div class="wizard-step card-surface p-3 p-md-4 mb-4" data-step-panel="4" style="display:none;">
      <h6 class="text-secondary fw-bold mb-3">
        <label class="label label-head bg-head-first rounded-2 text-white">4</label>
        สรุปผลและขั้นตอนการอนุมัติ
      </h6>
      <div class="row g-3 mb-4">
        <div class="col-sm-3">
          <div class="stat-card"><div class="stat-label">พนักงาน</div><div class="stat-value" id="reviewCount">12 คน</div></div>
        </div>
        <div class="col-sm-3">
          <div class="stat-card"><div class="stat-label">รวมรายได้</div><div class="stat-value" id="reviewGross">-</div></div>
        </div>
        <div class="col-sm-3">
          <div class="stat-card"><div class="stat-label">รวมหักทั้งหมด</div><div class="stat-value" id="reviewDeduct">-</div></div>
        </div>
        <div class="col-sm-3">
          <div class="stat-card"><div class="stat-label">ยอดจ่ายสุทธิ</div><div class="stat-value text-brand" id="reviewNet">-</div></div>
        </div>
      </div>

      <h6 class="fw-bold mb-3">ลำดับการอนุมัติ</h6>
      <div class="approval-track mb-4" id="approvalTrack"></div>

      <div class="row">
        <div class="col-sm-12">
          <label class="form-label">ความคิดเห็น (ถ้ามี)</label>
          <textarea class="form-control" rows="2" id="approvalComment" placeholder="ระบุความคิดเห็นประกอบการอนุมัติ..."></textarea>
        </div>
      </div>

      <div class="d-flex justify-content-between mt-4">
        <button class="btn btn-outline-secondary" onclick="goToStep(3)"><i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ</button>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-danger" id="btnReject"><i class="fa-solid fa-xmark me-1"></i> ไม่อนุมัติ</button>
          <button class="btn btn-brand" id="btnApproveStep"><i class="fa-solid fa-check me-1"></i> อนุมัติขั้นนี้</button>
        </div>
      </div>
    </div>

    <!-- STEP 5: Payment -->
    <div class="wizard-step card-surface p-3 p-md-4 mb-4" data-step-panel="5" style="display:none;">
      <h6 class="text-secondary fw-bold mb-3">
        <label class="label label-head bg-head-first rounded-2 text-white">5</label>
        การจ่ายเงิน
      </h6>
      <div id="paymentLocked" class="text-center text-secondary py-5">
        <i class="fa-solid fa-lock fa-2x mb-3 d-block text-secondary opacity-50"></i>
        งวดนี้ยังไม่ได้รับการอนุมัติครบทุกขั้นตอน จึงยังไม่สามารถดำเนินการจ่ายเงินได้
      </div>
      <div id="paymentUnlocked" style="display:none;">
        <div class="row">
          <div class="col-sm-2 mt-3"><label class="form-label">ช่องทางจ่าย</label></div>
          <div class="col-sm-4 mt-3">
            <select class="form-select">
              <option>โอนผ่านธนาคาร (Bulk Transfer)</option>
              <option>เงินสด</option>
              <option>เช็ค</option>
            </select>
          </div>
          <div class="col-sm-2 mt-3"><label class="form-label">วันที่จ่ายจริง</label></div>
          <div class="col-sm-4 mt-3">
            <div class="input-group"><input type="text" class="form-control" value="31/07/2569"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
          </div>
        </div>
        <div class="row mt-3">
          <div class="col-sm-12">
            <div class="card-surface p-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#FAFBFB;">
              <div>
                <div class="fw-bold"><i class="fa-solid fa-file-invoice me-1 text-brand"></i> ไฟล์โอนเงินธนาคาร (Bank Transfer Batch File)</div>
                <div class="text-secondary small">รวม 12 รายการ ยอดรวม <span id="paymentFileTotal">-</span> บาท</div>
              </div>
              <button class="btn btn-outline-brand btn-sm" onclick="mockToast('จำลองการดาวน์โหลดไฟล์โอนเงิน (mock)')"><i class="fa-solid fa-download me-1"></i> สร้างไฟล์โอนเงิน</button>
            </div>
          </div>
        </div>
        <div class="d-flex justify-content-between mt-4">
          <button class="btn btn-outline-secondary" onclick="goToStep(4)"><i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ</button>
          <button class="btn btn-brand" id="btnMarkPaid"><i class="fa-solid fa-circle-check me-1"></i> ยืนยันจ่ายเงินแล้ว</button>
        </div>
      </div>
    </div>

  </div>

</div>

<div class="mock-tag"><i class="fa-solid fa-flask me-1"></i> Mockup — ข้อมูลจำลองเพื่อสาธิต process</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
/* ======================= MOCK DATA ======================= */
const employees = [
  {id:'EMP001', name:'สมชาย ใจดี', dept:'ฝ่ายขาย', pos:'Sales Executive', base:25000, ot:2, allow:1000},
  {id:'EMP002', name:'สุดา พงษ์ไพศาล', dept:'ฝ่ายบัญชี', pos:'Accountant', base:32000, ot:0, allow:1500},
  {id:'EMP003', name:'วิชัย ตั้งมั่นคง', dept:'ฝ่าย IT', pos:'Developer', base:45000, ot:6, allow:2000},
  {id:'EMP004', name:'พรทิพย์ เรืองรุ่ง', dept:'ฝ่ายการตลาด', pos:'Marketing Officer', base:28000, ot:0, allow:1000},
  {id:'EMP005', name:'อนุชา ศรีสุข', dept:'ฝ่ายผลิต', pos:'Production Staff', base:18000, ot:12, allow:800},
  {id:'EMP006', name:'กมลชนก แก้วมณี', dept:'ฝ่ายบัญชี', pos:'HR Officer', base:30000, ot:0, allow:1000},
  {id:'EMP007', name:'ธนกร วัฒนกิจ', dept:'ฝ่ายขาย', pos:'Sales Manager', base:55000, ot:0, allow:3000},
  {id:'EMP008', name:'ปิยะดา สายใจ', dept:'ฝ่ายบัญชี', pos:'Senior Accountant', base:38000, ot:0, allow:1500},
  {id:'EMP009', name:'ศักดิ์ชัย บุญมี', dept:'ฝ่าย IT', pos:'System Admin', base:40000, ot:4, allow:2000},
  {id:'EMP010', name:'นภัสสร ทองคำ', dept:'ฝ่ายการตลาด', pos:'Marketing Manager', base:48000, ot:0, allow:3000},
  {id:'EMP011', name:'รัชนก ไพบูลย์', dept:'ฝ่ายผลิต', pos:'Line Supervisor', base:22000, ot:8, allow:1000},
  {id:'EMP012', name:'เอกชัย มั่งมี', dept:'ผู้บริหาร', pos:'Director', base:85000, ot:0, allow:5000},
];

let runs = [
  {name:'งวดที่ 1 - พฤษภาคม 2569', cutoff:'01/05/2569 - 31/05/2569', payDate:'31/05/2569', count:12, total:450200, status:'paid'},
  {name:'งวดที่ 2 - มิถุนายน 2569', cutoff:'01/06/2569 - 30/06/2569', payDate:'30/06/2569', count:12, total:461950, status:'paid'},
  {name:'งวดที่ 3 - กรกฎาคม 2569', cutoff:'01/07/2569 - 31/07/2569', payDate:'31/07/2569', count:12, total:468520, status:'pending'},
];

const statusMeta = {
  draft:      {label:'ฉบับร่าง',        cls:'status-draft'},
  calculated: {label:'คำนวณแล้ว',       cls:'status-calculated'},
  pending:    {label:'รออนุมัติ',        cls:'status-pending'},
  approved:   {label:'อนุมัติแล้ว',      cls:'status-approved'},
  paid:       {label:'จ่ายแล้ว',        cls:'status-paid'},
  rejected:   {label:'ไม่อนุมัติ',       cls:'status-rejected'},
};

let selectedEmployeeIds = new Set(employees.map(e=>e.id));
let calcResults = [];
let approvalSteps = [
  {role:'หัวหน้าฝ่ายบุคคล (HR Manager)', name:'สุกัญญา รักษ์งาน', state:'current'},
  {role:'ผู้จัดการฝ่ายบัญชี (Finance Manager)', name:'ประยุทธ์ ตั้งใจทำ', state:'pending'},
  {role:'ผู้บริหารระดับสูง (Director)', name:'เอกชัย มั่งมี', state:'pending'},
];

/* ======================= HELPERS ======================= */
function fmt(n){ return Number(n).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function statusPill(status){
  const m = statusMeta[status];
  return `<span class="status-pill ${m.cls}"><span class="dot"></span>${m.label}</span>`;
}
function mockToast(msg){
  const t = $(`<div class="mock-tag" style="right:auto;left:16px;background:var(--brand);">${msg}</div>`);
  $('body').append(t);
  setTimeout(()=>t.fadeOut(400, ()=>t.remove()), 2200);
}

/* ======================= LIST VIEW ======================= */
function renderRunList(){
  const body = $('#runListBody').empty();
  runs.forEach((r, idx)=>{
    const actionBtn = r.status==='paid'
      ? `<button class="btn btn-sm btn-outline-secondary" onclick="viewRun(${idx})"><i class="fa-solid fa-eye"></i></button>`
      : `<button class="btn btn-sm btn-outline-brand" onclick="continueRun(${idx})">ดำเนินการ</button>`;
    body.append(`
      <tr>
        <td class="fw-bold">${r.name}</td>
        <td>${r.cutoff}</td>
        <td>${r.payDate}</td>
        <td class="text-end">${r.count}</td>
        <td class="amount">${fmt(r.total)}</td>
        <td>${statusPill(r.status)}</td>
        <td>${actionBtn}</td>
      </tr>
    `);
  });
}
function viewRun(idx){ mockToast('เปิดดูงวดที่จ่ายแล้ว (read-only, mock)'); }
function continueRun(idx){
  activeRunIndex = idx;
  $('#periodName').val(runs[idx].name);
  openWizard(runs[idx].status==='pending' ? 4 : 1);
}

let activeRunIndex = null;

$('#btnNewRun').on('click', function(){
  activeRunIndex = null;
  runs.push({name:'งวดที่ '+(runs.length+1)+' - สิงหาคม 2569', cutoff:'01/08/2569 - 31/08/2569', payDate:'31/08/2569', count:12, total:0, status:'draft'});
  activeRunIndex = runs.length-1;
  $('#periodName').val(runs[activeRunIndex].name);
  openWizard(1);
});
$('#btnBackToList').on('click', function(){
  $('#wizardView').hide();
  $('#listView').show();
  $('#breadcrumbCurrent').text('งวดเงินเดือนทั้งหมด');
  renderRunList();
});

function openWizard(step){
  $('#listView').hide();
  $('#wizardView').show();
  $('#breadcrumbCurrent').text('ดำเนินการงวดเงินเดือน');
  renderEmployeeSelect();
  renderApprovalTrack();
  goToStep(step);
}

/* ======================= STEP NAV ======================= */
function goToStep(step){
  $('.wizard-step').hide();
  $(`.wizard-step[data-step-panel="${step}"]`).show();
  $('.step-item').removeClass('active done');
  $('.step-item').each(function(){
    const s = parseInt($(this).data('step'));
    if(s < step) $(this).addClass('done');
    if(s === step) $(this).addClass('active');
  });
  const titles = {
    1:['กำหนดงวดเงินเดือน','ระบุช่วงเวลาของงวดเพื่อเริ่มดำเนินการ'],
    2:['เลือกพนักงาน','เลือกพนักงานที่จะรวมอยู่ในงวดนี้'],
    3:['คำนวณเงินเดือน','ประมวลผลเงินเดือน OT เบี้ยเลี้ยง และภาษี'],
    4:['ตรวจสอบ และอนุมัติ','ตรวจสอบยอดสรุปและดำเนินการตามลำดับอนุมัติ'],
    5:['การจ่ายเงิน','สร้างไฟล์โอนเงินและยืนยันการจ่าย'],
  };
  $('#wizardTitle').text(titles[step][0]);
  $('#wizardSubtitle').text(titles[step][1]);
  if(step===4){ updateReviewSummary(); }
  if(step===5){ updatePaymentPanel(); }
}

/* ======================= STEP 2: SELECT EMPLOYEES ======================= */
function renderEmployeeSelect(filterDept){
  filterDept = filterDept || 'all';
  const body = $('#employeeSelectBody').empty();
  employees.filter(e=> filterDept==='all' || e.dept===filterDept).forEach(e=>{
    const checked = selectedEmployeeIds.has(e.id) ? 'checked' : '';
    body.append(`
      <tr class="emp-select-row ${checked ? 'selected':''}" data-id="${e.id}">
        <td><input type="checkbox" class="emp-chk" data-id="${e.id}" ${checked}></td>
        <td>${e.id}</td>
        <td>${e.name}</td>
        <td>${e.dept}</td>
        <td>${e.pos}</td>
        <td class="amount">${fmt(e.base)}</td>
      </tr>
    `);
  });
  updateSelectedCount();
}
function updateSelectedCount(){
  $('#selectedCount').text(selectedEmployeeIds.size);
}
$(document).on('change', '.emp-chk', function(){
  const id = $(this).data('id');
  if(this.checked){ selectedEmployeeIds.add(id); $(this).closest('tr').addClass('selected'); }
  else{ selectedEmployeeIds.delete(id); $(this).closest('tr').removeClass('selected'); }
  updateSelectedCount();
  $('#chkSelectAll').prop('checked', selectedEmployeeIds.size===employees.length);
});
$('#chkSelectAll').on('change', function(){
  const checked = this.checked;
  employees.forEach(e=> checked ? selectedEmployeeIds.add(e.id) : selectedEmployeeIds.delete(e.id));
  renderEmployeeSelect(currentDeptFilter);
});
let currentDeptFilter = 'all';
$(document).on('click', '.chip-filter', function(){
  $('.chip-filter').removeClass('active');
  $(this).addClass('active');
  currentDeptFilter = $(this).data('dept');
  renderEmployeeSelect(currentDeptFilter);
});

/* ======================= STEP 3: CALCULATE ======================= */
$('#btnCalculate').on('click', function(){
  const btn = $(this);
  btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังคำนวณ...');
  setTimeout(()=>{
    calcResults = employees.filter(e=> selectedEmployeeIds.has(e.id)).map(e=>{
      const otPay = Math.round(e.ot * (e.base/240) * 1.5);
      const gross = e.base + otPay + e.allow;
      const sso = Math.min(Math.round(e.base*0.05), 750);
      const tax = Math.round(Math.max(0, (gross - 15000)) * 0.03);
      const net = gross - sso - tax;
      return {...e, otPay, gross, sso, tax, net};
    });
    renderCalcResults();
    btn.prop('disabled', false).html('<i class="fa-solid fa-rotate me-1"></i> คำนวณใหม่');
    $('#btnToReview').prop('disabled', false);
    if(activeRunIndex!==null){ runs[activeRunIndex].status='calculated'; runs[activeRunIndex].count = calcResults.length; }
    mockToast('คำนวณเงินเดือนสำเร็จ (mock)');
  }, 700);
});
function renderCalcResults(){
  $('#calcEmptyState').hide();
  $('#calcResultWrap').show();
  const body = $('#calcResultBody').empty();
  let sBase=0,sOt=0,sAllow=0,sGross=0,sSso=0,sTax=0,sNet=0;
  calcResults.forEach(r=>{
    body.append(`
      <tr>
        <td>${r.name}</td>
        <td class="amount">${fmt(r.base)}</td>
        <td class="amount">${fmt(r.otPay)}</td>
        <td class="amount">${fmt(r.allow)}</td>
        <td class="amount">${fmt(r.gross)}</td>
        <td class="amount">${fmt(r.sso)}</td>
        <td class="amount">${fmt(r.tax)}</td>
        <td class="amount fw-bold">${fmt(r.net)}</td>
      </tr>
    `);
    sBase+=r.base; sOt+=r.otPay; sAllow+=r.allow; sGross+=r.gross; sSso+=r.sso; sTax+=r.tax; sNet+=r.net;
  });
  $('#sumBase').text(fmt(sBase));
  $('#sumOt').text(fmt(sOt));
  $('#sumAllow').text(fmt(sAllow));
  $('#sumGross').text(fmt(sGross));
  $('#sumSso').text(fmt(sSso));
  $('#sumTax').text(fmt(sTax));
  $('#sumNet').text(fmt(sNet));
  if(activeRunIndex!==null){ runs[activeRunIndex].total = sNet; }
}

/* ======================= STEP 4: REVIEW & APPROVE ======================= */
function updateReviewSummary(){
  if(calcResults.length===0) return;
  const sGross = calcResults.reduce((a,r)=>a+r.gross,0);
  const sDeduct = calcResults.reduce((a,r)=>a+r.sso+r.tax,0);
  const sNet = calcResults.reduce((a,r)=>a+r.net,0);
  $('#reviewCount').text(calcResults.length+' คน');
  $('#reviewGross').text(fmt(sGross));
  $('#reviewDeduct').text(fmt(sDeduct));
  $('#reviewNet').text(fmt(sNet));
}
function renderApprovalTrack(){
  const wrap = $('#approvalTrack').empty();
  approvalSteps.forEach(s=>{
    let cls = 'pending', icon = '';
    if(s.state==='done'){ cls='done'; icon='<i class="fa-solid fa-check text-brand ms-2"></i>'; }
    if(s.state==='current'){ cls='current'; icon='<span class="badge bg-warning text-dark ms-2">รอดำเนินการ</span>'; }
    if(s.state==='rejected'){ cls='rejected'; icon='<i class="fa-solid fa-xmark text-danger ms-2"></i>'; }
    wrap.append(`
      <div class="approval-node ${cls}">
        <div class="fw-bold">${s.role} ${icon}</div>
        <div class="text-secondary small">${s.name}</div>
      </div>
    `);
  });
  updateApprovalButtons();
}
function updateApprovalButtons(){
  const anyCurrent = approvalSteps.some(s=>s.state==='current');
  const anyRejected = approvalSteps.some(s=>s.state==='rejected');
  $('#btnApproveStep, #btnReject').prop('disabled', !anyCurrent || anyRejected);
  if(!anyCurrent && !anyRejected){
    $('#btnApproveStep').html('<i class="fa-solid fa-check-double me-1"></i> อนุมัติครบทุกขั้นแล้ว').prop('disabled', true);
  }
}
$('#btnApproveStep').on('click', function(){
  const idx = approvalSteps.findIndex(s=>s.state==='current');
  if(idx===-1) return;
  approvalSteps[idx].state='done';
  if(idx+1 < approvalSteps.length){ approvalSteps[idx+1].state='current'; }
  else{
    if(activeRunIndex!==null) runs[activeRunIndex].status='approved';
    mockToast('อนุมัติครบทุกขั้นตอนแล้ว พร้อมดำเนินการจ่ายเงิน');
  }
  renderApprovalTrack();
});
$('#btnReject').on('click', function(){
  const idx = approvalSteps.findIndex(s=>s.state==='current');
  if(idx===-1) return;
  approvalSteps[idx].state='rejected';
  if(activeRunIndex!==null) runs[activeRunIndex].status='rejected';
  renderApprovalTrack();
  mockToast('ไม่อนุมัติงวดเงินเดือนนี้ — กรุณาแก้ไขและส่งใหม่');
});

/* ======================= STEP 5: PAYMENT ======================= */
function updatePaymentPanel(){
  const allApproved = approvalSteps.every(s=>s.state==='done');
  if(allApproved && calcResults.length){
    $('#paymentLocked').hide();
    $('#paymentUnlocked').show();
    const sNet = calcResults.reduce((a,r)=>a+r.net,0);
    $('#paymentFileTotal').text(fmt(sNet));
  } else {
    $('#paymentLocked').show();
    $('#paymentUnlocked').hide();
  }
}
$('#btnMarkPaid').on('click', function(){
  if(activeRunIndex!==null){ runs[activeRunIndex].status='paid'; }
  mockToast('บันทึกการจ่ายเงินสำเร็จ (mock) — กลับไปหน้ารายการ');
  setTimeout(()=>{ $('#btnBackToList').click(); }, 900);
});

/* ======================= INIT ======================= */
renderRunList();
</script>