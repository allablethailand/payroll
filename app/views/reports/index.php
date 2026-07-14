<style>
  :root{
    --bg:#F2F3EE; --surface:#FFFFFF; --ink:#1E2A23; --ink-soft:#5B6259;
    --primary:#1F4D3D; --primary-dark:#173B2E; --primary-soft:#E7EEE8;
    --accent:#B4842A; --accent-soft:#F5ECD8;
    --positive:#2F6E4F; --negative:#A6433F; --negative-soft:#F6E7E4;
    --line:#DEDBCE; --line-strong:#C8C4B4;
  }
  body{
    font-family:'Noto Sans Thai',sans-serif;
    background:var(--bg);
    color:var(--ink);
  }
  .display{font-family:'Noto Serif Thai',serif;}
  .mono{font-family:'JetBrains Mono',monospace; font-variant-numeric:tabular-nums;}
  .eyebrow{font-size:.75rem; color:var(--accent); font-weight:500; letter-spacing:.03em;}
  .ticket{
    position:relative; background:var(--surface); border:1px solid var(--line);
    border-radius:10px; padding:16px 18px;
  }
  .ticket::before{
    content:""; position:absolute; top:-1px; left:18px; right:18px; height:0;
    border-top:1.5px dashed var(--line-strong);
  }
  .kpi-label{font-size:.75rem; color:var(--ink-soft); font-weight:500;}
  .kpi-value{font-size:1.4rem; font-weight:600; color:var(--ink);}
  .kpi-sub{font-size:.72rem; margin-top:4px; color:var(--ink-soft);}
  .kpi-sub.up{color:var(--positive);}
  .kpi-sub.down{color:var(--negative);}
  .section-card{
    background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:18px;
  }
  .section-title{font-size:.95rem; font-weight:600; margin:0;}
  .nav-ledger{border-bottom:1px solid var(--line);}
  .nav-ledger .nav-link{
    color:var(--ink-soft); font-size:.85rem; font-weight:500; border:1px solid transparent;
    border-radius:8px 8px 0 0; padding:.55rem 1rem; display:flex; align-items:center; gap:6px;
  }
  .nav-ledger .nav-link:hover{color:var(--ink);}
  .nav-ledger .nav-link.active{
    color:var(--primary); background:var(--surface); border-color:var(--line);
    border-bottom-color:var(--surface);
  }
  table.ledger-table{font-size:.82rem; width:100%;}
  table.ledger-table thead th{
    font-size:.7rem; font-weight:500; color:var(--ink-soft); letter-spacing:.02em;
    border-bottom:1.5px solid var(--line-strong)!important; white-space:nowrap;
    position:sticky; top:0; background:var(--surface);
  }
  table.ledger-table tbody td{border-bottom:1px solid var(--line); white-space:nowrap; vertical-align:middle;}
  table.ledger-table tbody tr:hover td{background:#FAFAF6;}
  .text-negative{color:var(--negative);}
  .text-positive{color:var(--positive);}
  .form-select, .form-control{
    font-size:.82rem; border-color:var(--line-strong); border-radius:8px;
  }
  .form-select:focus, .form-control:focus{
    border-color:var(--primary); box-shadow:0 0 0 .2rem rgba(31,77,61,.12);
  }
  .btn-ledger{
    background:var(--primary); color:#F6F7F2; border:none; border-radius:8px;
    font-size:.82rem; font-weight:500; padding:.5rem 1rem;
  }
  .btn-ledger:hover{background:var(--primary-dark); color:#F6F7F2;}
  .search-wrap{position:relative;}
  .search-wrap i{position:absolute; left:12px; top:10px; color:var(--ink-soft); font-size:.8rem;}
  .search-wrap input{padding-left:32px;}
  .table-scroll{max-height:520px; overflow:auto;}
  .table-scroll::-webkit-scrollbar{height:8px; width:8px;}
  .table-scroll::-webkit-scrollbar-thumb{background:var(--line-strong); border-radius:4px;}
  .legend-dot{width:8px; height:8px; border-radius:2px; display:inline-block; margin-right:6px;}
  .tab-pane-inner{display:flex; flex-direction:column; gap:16px;}
</style>

<div class="container-fluid px-3 px-md-4 py-4" style="max-width:1400px;">

  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
      <div class="eyebrow">ระบบบริหารเงินเดือน</div>
      <h1 class="display fw-bold mb-1" style="font-size:1.7rem;">รายงานเงินเดือนประจำเดือน</h1>
      <div class="text-secondary" style="font-size:.85rem; color:var(--ink-soft)!important;">
        ข้อมูลจำลองสำหรับสาธิตรายงาน · พนักงานทั้งหมด <span id="headcountLabel">-</span> คน · 6 แผนก
      </div>
    </div>
    <div class="d-flex gap-2">
      <select id="monthSelect" class="form-select" style="width:auto;"></select>
      <button class="btn btn-ledger"><i class="fa-solid fa-download me-1"></i>ส่งออกรายงาน</button>
    </div>
  </div>

  <!-- KPI strip -->
  <div class="row g-3 mb-4" id="kpiStrip"></div>

  <!-- Tabs -->
  <ul class="nav nav-ledger mb-0" id="reportTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button"><i class="fa-solid fa-table-cells-large"></i>ภาพรวม</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-employees" type="button"><i class="fa-solid fa-users"></i>รายพนักงาน</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tax" type="button"><i class="fa-solid fa-receipt"></i>ภาษีเงินได้ (ภ.ง.ด.1)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sso" type="button"><i class="fa-solid fa-shield-halved"></i>ประกันสังคม</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pf" type="button"><i class="fa-solid fa-piggy-bank"></i>กองทุนสำรองเลี้ยงชีพ</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ot" type="button"><i class="fa-solid fa-clock"></i>ค่าล่วงเวลา</button></li>
  </ul>

  <div class="tab-content pt-4">

    <!-- OVERVIEW -->
    <div class="tab-pane fade show active" id="tab-overview">
      <div class="tab-pane-inner">
        <div class="row g-3">
          <div class="col-lg-7">
            <div class="section-card h-100">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="display section-title">แนวโน้มค่าใช้จ่ายเงินเดือน 6 เดือน</h3>
              </div>
              <canvas id="trendChart" height="110"></canvas>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="section-card h-100">
              <h3 class="display section-title mb-3">สัดส่วนต้นทุนเงินเดือน</h3>
              <canvas id="compositionChart" height="150"></canvas>
              <div id="compositionLegend" class="mt-3 d-flex flex-column gap-2"></div>
            </div>
          </div>
        </div>
        <div class="section-card">
          <h3 class="display section-title mb-3">ค่าใช้จ่ายเงินเดือนตามแผนก</h3>
          <canvas id="deptChart" height="90"></canvas>
        </div>
      </div>
    </div>

    <!-- EMPLOYEES -->
    <div class="tab-pane fade" id="tab-employees">
      <div class="section-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <h3 class="display section-title">รายละเอียดเงินเดือนพนักงานรายบุคคล</h3>
          <div class="d-flex gap-2">
            <div class="search-wrap">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" class="form-control" id="empSearch" placeholder="ค้นหาชื่อหรือตำแหน่ง" style="width:220px;">
            </div>
            <select id="deptFilter" class="form-select" style="width:auto;">
              <option value="all">ทุกแผนก</option>
            </select>
          </div>
        </div>
        <div class="table-scroll">
          <table class="ledger-table table table-borderless mb-0">
            <thead>
              <tr>
                <th>ชื่อ-สกุล</th><th>แผนก</th><th>ตำแหน่ง</th>
                <th class="text-end">เงินเดือนพื้นฐาน</th><th class="text-end">OT</th>
                <th class="text-end">เบี้ยเลี้ยง</th><th class="text-end">รวมรายรับ</th>
                <th class="text-end">ภาษี</th><th class="text-end">ประกันสังคม</th>
                <th class="text-end">กองทุนฯ</th><th class="text-end">รับสุทธิ</th>
              </tr>
            </thead>
            <tbody id="employeeTableBody"></tbody>
          </table>
          <div id="empEmptyState" class="text-center py-4 d-none" style="color:var(--ink-soft); font-size:.85rem;">ไม่พบรายชื่อที่ค้นหา</div>
        </div>
      </div>
    </div>

    <!-- TAX -->
    <div class="tab-pane fade" id="tab-tax">
      <div class="tab-pane-inner">
        <div class="row g-3" id="taxKpis"></div>
        <div class="section-card">
          <h3 class="display section-title mb-3">รายละเอียดภาษีเงินได้หัก ณ ที่จ่าย (ภ.ง.ด.1)</h3>
          <div class="table-scroll">
            <table class="ledger-table table table-borderless mb-0">
              <thead>
                <tr><th>ชื่อ-สกุล</th><th>แผนก</th><th class="text-end">รายได้รวม/เดือน</th><th class="text-end">ภาษีหัก ณ ที่จ่าย</th><th class="text-end">สัดส่วนต่อรายได้</th></tr>
              </thead>
              <tbody id="taxTableBody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- SSO -->
    <div class="tab-pane fade" id="tab-sso">
      <div class="tab-pane-inner">
        <div class="row g-3" id="ssoKpis"></div>
        <div class="section-card">
          <h3 class="display section-title mb-3">รายงานเงินสมทบประกันสังคม (สปส. 1-10)</h3>
          <div class="table-scroll">
            <table class="ledger-table table table-borderless mb-0">
              <thead>
                <tr><th>ชื่อ-สกุล</th><th>แผนก</th><th class="text-end">ฐานเงินเดือนคำนวณ</th><th class="text-end">สมทบลูกจ้าง (5%)</th><th class="text-end">สมทบนายจ้าง (5%)</th><th class="text-end">รวมนำส่ง</th></tr>
              </thead>
              <tbody id="ssoTableBody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- PF -->
    <div class="tab-pane fade" id="tab-pf">
      <div class="tab-pane-inner">
        <div class="row g-3" id="pfKpis"></div>
        <div class="section-card">
          <h3 class="display section-title mb-3">รายงานกองทุนสำรองเลี้ยงชีพ</h3>
          <div class="table-scroll">
            <table class="ledger-table table table-borderless mb-0">
              <thead>
                <tr><th>ชื่อ-สกุล</th><th>แผนก</th><th class="text-end">อัตราสะสม</th><th class="text-end">สมทบลูกจ้าง</th><th class="text-end">สมทบนายจ้าง</th><th class="text-end">อายุงาน</th><th class="text-end">มูลค่าสะสมโดยประมาณ</th></tr>
              </thead>
              <tbody id="pfTableBody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- OT -->
    <div class="tab-pane fade" id="tab-ot">
      <div class="tab-pane-inner">
        <div class="row g-3" id="otKpis"></div>
        <div class="section-card">
          <h3 class="display section-title mb-3">ค่าล่วงเวลาตามแผนก</h3>
          <canvas id="otChart" height="90"></canvas>
        </div>
        <div class="section-card">
          <h3 class="display section-title mb-3">พนักงานที่มีค่าล่วงเวลาสูงสุด</h3>
          <div class="table-scroll">
            <table class="ledger-table table table-borderless mb-0">
              <thead>
                <tr><th>ชื่อ-สกุล</th><th>แผนก</th><th class="text-end">ชั่วโมง OT</th><th class="text-end">อัตรา/ชม.</th><th class="text-end">ค่า OT รวม</th></tr>
              </thead>
              <tbody id="otTableBody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
/* ---------------------------------------------------------
   Deterministic mock data generation
--------------------------------------------------------- */
function mulberry32(seed){
  return function(){
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const rng = mulberry32(20260713);
const pick = arr => arr[Math.floor(rng()*arr.length)];
const randInt = (min,max) => Math.floor(rng()*(max-min+1))+min;
const round = n => Math.round(n);
const THB = n => new Intl.NumberFormat('th-TH',{maximumFractionDigits:0}).format(n);

const DEPARTMENTS = ["บัญชีและการเงิน","ขายและการตลาด","ปฏิบัติการ/ผลิต","เทคโนโลยีสารสนเทศ","ทรัพยากรบุคคล","จัดซื้อ"];

const POSITIONS = {
  "บัญชีและการเงิน":[["ผู้จัดการฝ่ายบัญชี","manager"],["นักบัญชีอาวุโส","senior"],["นักบัญชี","mid"],["เจ้าหน้าที่การเงิน","junior"]],
  "ขายและการตลาด":[["ผู้จัดการฝ่ายขาย","manager"],["ผู้บริหารงานขาย","senior"],["เจ้าหน้าที่การตลาด","mid"],["เจ้าหน้าที่ขาย","junior"]],
  "ปฏิบัติการ/ผลิต":[["หัวหน้าฝ่ายผลิต","manager"],["ช่างเทคนิคอาวุโส","senior"],["พนักงานฝ่ายผลิต","junior"],["เจ้าหน้าที่ควบคุมคุณภาพ","mid"]],
  "เทคโนโลยีสารสนเทศ":[["ผู้จัดการฝ่ายไอที","manager"],["นักพัฒนาซอฟต์แวร์อาวุโส","senior"],["นักพัฒนาซอฟต์แวร์","mid"],["เจ้าหน้าที่สนับสนุนระบบ","junior"]],
  "ทรัพยากรบุคคล":[["ผู้จัดการฝ่ายบุคคล","manager"],["เจ้าหน้าที่สรรหาบุคลากร","mid"],["เจ้าหน้าที่บุคคล","junior"]],
  "จัดซื้อ":[["ผู้จัดการฝ่ายจัดซื้อ","manager"],["เจ้าหน้าที่จัดซื้ออาวุโส","senior"],["เจ้าหน้าที่จัดซื้อ","junior"]]
};
const LEVEL_RANGE = { manager:[58000,88000], senior:[36000,51000], mid:[26000,35000], junior:[18000,25000] };
const FIRST_NAMES = ["สมชาย","สมหญิง","วิชัย","มานี","ประยุทธ","สุดา","อนุชา","พิมพ์ใจ","ธนกร","กัญญา","เอกชัย","นภัสสร","ชัยวัฒน์","รัตนา","ปิยะ","วราภรณ์","ณัฐพล","ศิริพร","สุรชัย","อรทัย","พงศกร","จิราภรณ์","กิตติ","มณีรัตน์","วีระ","สุภาพร","ธีรพงษ์","นันทนา","อดิศักดิ์","พรทิพย์","สมบัติ","ลัดดา"];
const LAST_NAMES = ["ใจดี","รักเรียน","มั่งมี","สุขสันต์","เจริญพร","วงศ์ษา","ศรีสุข","บุญมี","ทองดี","แก้วมณี","พูลสวัสดิ์","ชื่นบาน","สายทอง","ประเสริฐ","มีชัย","เพิ่มพูน","สว่างวงศ์","อยู่ดี","รุ่งเรือง","ทรัพย์มาก"];
const MONTH_LABELS = ["ก.พ. 2569","มี.ค. 2569","เม.ย. 2569","พ.ค. 2569","มิ.ย. 2569","ก.ค. 2569"];
const CHART_COLORS = ["#1F4D3D","#B4842A","#5B8C74","#D9B36A","#8AA894","#C8C4B4"];

function annualTax(taxableAnnual){
  const brackets = [[150000,0],[300000,.05],[500000,.1],[750000,.15],[1000000,.2],[2000000,.25],[5000000,.3],[Infinity,.35]];
  let tax=0, prev=0;
  for(const [cap,rate] of brackets){
    if(taxableAnnual > prev){
      const slice = Math.min(taxableAnnual,cap)-prev;
      tax += slice*rate; prev = cap;
    } else break;
  }
  return tax;
}

function generateEmployees(){
  const employees = [];
  let idCounter = 1001;
  const usedNames = new Set();
  DEPARTMENTS.forEach(dept=>{
    const headcount = dept==="ปฏิบัติการ/ผลิต" ? 8 : dept==="ขายและการตลาด" ? 7 : 5;
    for(let i=0;i<headcount;i++){
      let name;
      do{ name = `${pick(FIRST_NAMES)} ${pick(LAST_NAMES)}`; } while(usedNames.has(name));
      usedNames.add(name);
      const [position, level] = pick(POSITIONS[dept]);
      const [lo,hi] = LEVEL_RANGE[level];
      const baseSalary = round(randInt(lo,hi)/100)*100;
      const otEligible = dept==="ปฏิบัติการ/ผลิต" || dept==="เทคโนโลยีสารสนเทศ" || level==="junior";
      const otHours = otEligible ? randInt(0,32) : randInt(0,6);
      const otRate = (baseSalary/30/8)*1.5;
      const otPay = round(otHours*otRate);
      const allowance = randInt(1000,3000);
      const grossMonthly = baseSalary + otPay + allowance;
      const grossAnnualForTax = baseSalary*13 + otPay*12 + allowance*12;
      const expenseDeduction = Math.min(grossAnnualForTax*0.5, 100000);
      const taxableAnnual = Math.max(0, grossAnnualForTax - expenseDeduction - 60000);
      const taxMonthly = round(annualTax(taxableAnnual)/12);
      const ssoBase = Math.min(baseSalary,15000);
      const ssoEmployee = round(ssoBase*0.05);
      const ssoEmployer = ssoEmployee;
      const pfRate = pick([3,5,7]);
      const pfEmployee = round(baseSalary*pfRate/100);
      const pfEmployer = pfEmployee;
      const netPay = grossMonthly - taxMonthly - ssoEmployee - pfEmployee;
      const tenureYears = randInt(1,8);
      employees.push({id:idCounter++,name,dept,position,level,baseSalary,otHours,otPay,allowance,grossMonthly,taxMonthly,ssoEmployee,ssoEmployer,pfRate,pfEmployee,pfEmployer,netPay,tenureYears});
    }
  });
  return employees;
}

function generateTrend(baseTotal){
  return MONTH_LABELS.map((label,i)=>{
    const isLast = i===MONTH_LABELS.length-1;
    const variance = isLast ? 0 : (rng()-0.45)*0.06;
    const factor = 1 + variance - (MONTH_LABELS.length-1-i)*0.004;
    return { month: label, total: round(baseTotal*factor), factor };
  });
}

/* ---------------------------------------------------------
   State + render
--------------------------------------------------------- */
const employees = generateEmployees();
const baseTotal = employees.reduce((s,e)=>s+e.grossMonthly,0);
const trend = generateTrend(baseTotal);
let monthIndex = MONTH_LABELS.length - 1;
let searchQuery = "";
let deptFilterValue = "all";
let charts = {};

function scaledEmployees(){
  const factor = trend[monthIndex].factor;
  return employees.map(e=>({
    ...e,
    baseSalary: round(e.baseSalary*factor),
    otPay: round(e.otPay*factor),
    allowance: round(e.allowance*factor),
    grossMonthly: round(e.grossMonthly*factor),
    taxMonthly: round(e.taxMonthly*factor),
    ssoEmployee: round(e.ssoEmployee*factor),
    ssoEmployer: round(e.ssoEmployer*factor),
    pfEmployee: round(e.pfEmployee*factor),
    pfEmployer: round(e.pfEmployer*factor),
    netPay: round(e.netPay*factor)
  }));
}

function kpiCard(icon,label,value,sub,tone){
  return `
    <div class="col-6 col-md-4 col-lg">
      <div class="ticket h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="kpi-label">${label}</span>
          <i class="fa-solid ${icon}" style="color:var(--accent); font-size:.85rem;"></i>
        </div>
        <div class="mono display kpi-value">${value}</div>
        ${sub ? `<div class="kpi-sub ${tone||''}">${tone==='up'?'<i class="fa-solid fa-arrow-up-right me-1"></i>':tone==='down'?'<i class="fa-solid fa-arrow-down-right me-1"></i>':''}${sub}</div>` : ''}
      </div>
    </div>`;
}

function destroyChart(key){ if(charts[key]){ charts[key].destroy(); delete charts[key]; } }

function renderAll(){
  const scaled = scaledEmployees();

  document.getElementById('headcountLabel').textContent = scaled.length;

  // KPIs overview
  const gross = scaled.reduce((s,e)=>s+e.grossMonthly,0);
  const net = scaled.reduce((s,e)=>s+e.netPay,0);
  const tax = scaled.reduce((s,e)=>s+e.taxMonthly,0);
  const sso = scaled.reduce((s,e)=>s+e.ssoEmployee+e.ssoEmployer,0);
  const pf = scaled.reduce((s,e)=>s+e.pfEmployee+e.pfEmployer,0);
  const ot = scaled.reduce((s,e)=>s+e.otPay,0);

  document.getElementById('kpiStrip').innerHTML = [
    kpiCard('fa-wallet','ค่าใช้จ่ายเงินเดือนรวม',`฿${THB(gross)}`,'รวมก่อนหักภาษี/ประกันสังคม'),
    kpiCard('fa-receipt','ยอดรับสุทธิรวม',`฿${THB(net)}`,'เข้าบัญชีพนักงาน','up'),
    kpiCard('fa-building','ภาษีหัก ณ ที่จ่าย',`฿${THB(tax)}`,'นำส่งกรมสรรพากร'),
    kpiCard('fa-shield-halved','ประกันสังคม (รวม 2 ฝ่าย)',`฿${THB(sso)}`,'นำส่ง สปส.'),
    kpiCard('fa-piggy-bank','กองทุนสำรองเลี้ยงชีพ',`฿${THB(pf)}`,'รวมนายจ้าง+ลูกจ้าง')
  ].join('');

  // By dept
  const byDept = DEPARTMENTS.map(d=>{
    const rows = scaled.filter(e=>e.dept===d);
    return { dept:d, cost: rows.reduce((s,e)=>s+e.grossMonthly,0), ot: rows.reduce((s,e)=>s+e.otPay,0) };
  });

  // Trend chart
  destroyChart('trend');
  charts.trend = new Chart(document.getElementById('trendChart'), {
    type:'line',
    data:{ labels: trend.map(t=>t.month), datasets:[{ data: trend.map(t=>t.total), borderColor:'#1F4D3D', backgroundColor:'rgba(31,77,61,.08)', fill:true, tension:.35, pointBackgroundColor:'#1F4D3D', pointRadius:4 }]},
    options:{ plugins:{legend:{display:false}, tooltip:{callbacks:{label:c=>`฿${THB(c.raw)}`}}}, scales:{ y:{ ticks:{ callback:v=>`${round(v/1000)}k`, font:{size:11} }, grid:{color:'#DEDBCE'} }, x:{ ticks:{font:{size:11}}, grid:{display:false} } } }
  });

  // Composition chart
  const base = scaled.reduce((s,e)=>s+e.baseSalary,0);
  const otSum = scaled.reduce((s,e)=>s+e.otPay,0);
  const allowance = scaled.reduce((s,e)=>s+e.allowance,0);
  const pfEmployer = scaled.reduce((s,e)=>s+e.pfEmployer,0);
  const composition = [
    {name:'เงินเดือนพื้นฐาน', value: base},
    {name:'ค่าล่วงเวลา', value: otSum},
    {name:'เบี้ยเลี้ยง/สวัสดิการ', value: allowance},
    {name:'สมทบกองทุนฯ (นายจ้าง)', value: pfEmployer}
  ];
  destroyChart('composition');
  charts.composition = new Chart(document.getElementById('compositionChart'), {
    type:'doughnut',
    data:{ labels: composition.map(c=>c.name), datasets:[{ data: composition.map(c=>c.value), backgroundColor: CHART_COLORS, borderWidth:0 }]},
    options:{ cutout:'62%', plugins:{legend:{display:false}, tooltip:{callbacks:{label:c=>`${c.label}: ฿${THB(c.raw)}`}}} }
  });
  document.getElementById('compositionLegend').innerHTML = composition.map((c,i)=>`
    <div class="d-flex justify-content-between align-items-center" style="font-size:.78rem;">
      <span style="color:var(--ink-soft);"><span class="legend-dot" style="background:${CHART_COLORS[i]}"></span>${c.name}</span>
      <span class="mono fw-medium">฿${THB(c.value)}</span>
    </div>`).join('');

  // Dept chart
  destroyChart('dept');
  charts.dept = new Chart(document.getElementById('deptChart'), {
    type:'bar',
    data:{ labels: byDept.map(d=>d.dept), datasets:[{ data: byDept.map(d=>d.cost), backgroundColor:'#1F4D3D', borderRadius:6, maxBarThickness:56 }]},
    options:{ plugins:{legend:{display:false}, tooltip:{callbacks:{label:c=>`฿${THB(c.raw)}`}}}, scales:{ y:{ ticks:{ callback:v=>`${round(v/1000)}k`, font:{size:11} }, grid:{color:'#DEDBCE'} }, x:{ ticks:{font:{size:11}}, grid:{display:false} } } }
  });

  // Employees table
  const deptFilterEl = document.getElementById('deptFilter');
  if(deptFilterEl.options.length <= 1){
    DEPARTMENTS.forEach(d=>{
      const opt = document.createElement('option');
      opt.value = d; opt.textContent = d;
      deptFilterEl.appendChild(opt);
    });
  }
  const filtered = scaled.filter(e=>{
    const matchDept = deptFilterValue==='all' || e.dept===deptFilterValue;
    const matchSearch = !searchQuery || e.name.includes(searchQuery) || e.position.includes(searchQuery);
    return matchDept && matchSearch;
  });
  document.getElementById('employeeTableBody').innerHTML = filtered.map(e=>`
    <tr>
      <td>${e.name}</td>
      <td style="color:var(--ink-soft)">${e.dept}</td>
      <td style="color:var(--ink-soft)">${e.position}</td>
      <td class="mono text-end">${THB(e.baseSalary)}</td>
      <td class="mono text-end">${THB(e.otPay)}</td>
      <td class="mono text-end">${THB(e.allowance)}</td>
      <td class="mono text-end fw-medium">${THB(e.grossMonthly)}</td>
      <td class="mono text-end text-negative">-${THB(e.taxMonthly)}</td>
      <td class="mono text-end text-negative">-${THB(e.ssoEmployee)}</td>
      <td class="mono text-end text-negative">-${THB(e.pfEmployee)}</td>
      <td class="mono text-end fw-bold" style="color:var(--primary)">${THB(e.netPay)}</td>
    </tr>`).join('');
  document.getElementById('empEmptyState').classList.toggle('d-none', filtered.length>0);

  // Tax tab
  document.getElementById('taxKpis').innerHTML = [
    kpiCard('fa-receipt','ยอดภาษีหัก ณ ที่จ่ายรวม',`฿${THB(tax)}`,'แบบ ภ.ง.ด.1'),
    kpiCard('fa-users','จำนวนพนักงานที่ถูกหักภาษี', scaled.filter(e=>e.taxMonthly>0).length),
    kpiCard('fa-clock','กำหนดนำส่ง','ภายในวันที่ 7','ของเดือนถัดไป')
  ].join('');
  document.getElementById('taxTableBody').innerHTML = [...scaled].sort((a,b)=>b.taxMonthly-a.taxMonthly).map(e=>`
    <tr>
      <td>${e.name}</td>
      <td style="color:var(--ink-soft)">${e.dept}</td>
      <td class="mono text-end">${THB(e.grossMonthly)}</td>
      <td class="mono text-end text-negative">${THB(e.taxMonthly)}</td>
      <td class="mono text-end" style="color:var(--ink-soft)">${e.grossMonthly ? ((e.taxMonthly/e.grossMonthly)*100).toFixed(1) : '0.0'}%</td>
    </tr>`).join('');

  // SSO tab
  document.getElementById('ssoKpis').innerHTML = [
    kpiCard('fa-shield-halved','สมทบฝั่งลูกจ้าง',`฿${THB(scaled.reduce((s,e)=>s+e.ssoEmployee,0))}`),
    kpiCard('fa-shield-halved','สมทบฝั่งนายจ้าง',`฿${THB(scaled.reduce((s,e)=>s+e.ssoEmployer,0))}`),
    kpiCard('fa-clock','กำหนดนำส่ง','ภายในวันที่ 15','ของเดือนถัดไป')
  ].join('');
  document.getElementById('ssoTableBody').innerHTML = scaled.map(e=>`
    <tr>
      <td>${e.name}</td>
      <td style="color:var(--ink-soft)">${e.dept}</td>
      <td class="mono text-end">${THB(Math.min(e.baseSalary,15000))}</td>
      <td class="mono text-end">${THB(e.ssoEmployee)}</td>
      <td class="mono text-end">${THB(e.ssoEmployer)}</td>
      <td class="mono text-end fw-bold">${THB(e.ssoEmployee+e.ssoEmployer)}</td>
    </tr>`).join('');

  // PF tab
  document.getElementById('pfKpis').innerHTML = [
    kpiCard('fa-piggy-bank','สมทบฝั่งลูกจ้าง',`฿${THB(scaled.reduce((s,e)=>s+e.pfEmployee,0))}`),
    kpiCard('fa-piggy-bank','สมทบฝั่งนายจ้าง',`฿${THB(scaled.reduce((s,e)=>s+e.pfEmployer,0))}`),
    kpiCard('fa-wallet','มูลค่าสะสมกองทุนโดยประมาณ',`฿${THB(scaled.reduce((s,e)=>s+(e.pfEmployee+e.pfEmployer)*12*e.tenureYears,0))}`,'ตามอายุงานแต่ละคน')
  ].join('');
  document.getElementById('pfTableBody').innerHTML = scaled.map(e=>`
    <tr>
      <td>${e.name}</td>
      <td style="color:var(--ink-soft)">${e.dept}</td>
      <td class="mono text-end">${e.pfRate}%</td>
      <td class="mono text-end">${THB(e.pfEmployee)}</td>
      <td class="mono text-end">${THB(e.pfEmployer)}</td>
      <td class="mono text-end">${e.tenureYears} ปี</td>
      <td class="mono text-end fw-bold">${THB((e.pfEmployee+e.pfEmployer)*12*e.tenureYears)}</td>
    </tr>`).join('');

  // OT tab
  document.getElementById('otKpis').innerHTML = [
    kpiCard('fa-clock','ค่าล่วงเวลารวม',`฿${THB(ot)}`),
    kpiCard('fa-users','พนักงานที่มี OT', scaled.filter(e=>e.otHours>0).length),
    kpiCard('fa-clock','ชั่วโมง OT เฉลี่ยต่อคน',`${(scaled.reduce((s,e)=>s+e.otHours,0)/scaled.length).toFixed(1)} ชม.`)
  ].join('');
  destroyChart('ot');
  charts.ot = new Chart(document.getElementById('otChart'), {
    type:'bar',
    data:{ labels: byDept.map(d=>d.dept), datasets:[{ data: byDept.map(d=>d.ot), backgroundColor:'#B4842A', borderRadius:6, maxBarThickness:56 }]},
    options:{ plugins:{legend:{display:false}, tooltip:{callbacks:{label:c=>`฿${THB(c.raw)}`}}}, scales:{ y:{ ticks:{font:{size:11}}, grid:{color:'#DEDBCE'} }, x:{ ticks:{font:{size:11}}, grid:{display:false} } } }
  });
  const topOt = [...scaled].sort((a,b)=>b.otPay-a.otPay).slice(0,8);
  document.getElementById('otTableBody').innerHTML = topOt.map(e=>`
    <tr>
      <td>${e.name}</td>
      <td style="color:var(--ink-soft)">${e.dept}</td>
      <td class="mono text-end">${e.otHours} ชม.</td>
      <td class="mono text-end">${THB(round((e.baseSalary/30/8)*1.5))}</td>
      <td class="mono text-end fw-bold">${THB(e.otPay)}</td>
    </tr>`).join('');
}

// Month selector
const monthSelect = document.getElementById('monthSelect');
MONTH_LABELS.forEach((m,i)=>{
  const opt = document.createElement('option');
  opt.value = i; opt.textContent = `งวด ${m}`;
  if(i===monthIndex) opt.selected = true;
  monthSelect.appendChild(opt);
});
monthSelect.addEventListener('change', e=>{ monthIndex = Number(e.target.value); renderAll(); });

document.getElementById('empSearch').addEventListener('input', e=>{ searchQuery = e.target.value; renderAll(); });
document.getElementById('deptFilter').addEventListener('change', e=>{ deptFilterValue = e.target.value; renderAll(); });

renderAll();
</script>