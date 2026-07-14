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
const BANKS = ["ธนาคารไทยพาณิชย์","ธนาคารกสิกรไทย","ธนาคารกรุงเทพ","ธนาคารกรุงไทย","ธนาคารกรุงศรีอยุธยา"];
const COMPANY_BANKS = [
    {name:"ธนาคารไทยพาณิชย์", product:"SCB Business Anywhere", account:"111-2-345678-9"},
    {name:"ธนาคารกสิกรไทย", product:"K-Cash Connect", account:"222-1-098765-4"},
    {name:"ธนาคารกรุงเทพ", product:"Bualuang iCash", account:"333-4-567891-2"},
    {name:"ธนาคารกรุงไทย", product:"Krungthai Corporate Online", account:"444-5-678912-3"},
];
function annualTax(taxableAnnual){
    const brackets = [[150000,0],[300000,.05],[500000,.1],[750000,.15],[1000000,.2],[2000000,.25],[5000000,.3],[Infinity,.35]];
    let tax=0, prev=0;
    for(const [cap,rate] of brackets){
        if(taxableAnnual > prev){ const slice = Math.min(taxableAnnual,cap)-prev; tax += slice*rate; prev = cap; } else break;
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
            const netPay = grossMonthly - taxMonthly - ssoEmployee - round(baseSalary*pick([3,5,7])/100);
            const bankName = pick(BANKS);
            const acctDigits = String(randInt(1000,9999));
            const bankAccount = `xxx-x-${acctDigits}-x`;
            employees.push({id:idCounter++,name,dept,position,baseSalary,otPay,allowance,grossMonthly,taxMonthly,ssoEmployee,ssoEmployer,netPay,bankName,bankAccount});
        }
    });
    return employees;
}
const employees = generateEmployees();
let periodIndex = MONTH_LABELS.length - 1;
let ssoSubmitted = false;
let bankSubmitted = false;
let taxSubmitted = false;
let bankConnected = false;
function showToast(message){
    document.getElementById('toastBody').textContent = message;
    new bootstrap.Toast(document.getElementById('mainToast'), {delay:3200}).show();
}
function pill(kind, label){
    const cls = kind==='success' ? 'status-success' : kind==='pending' ? 'status-pending' : 'status-idle';
    const icon = kind==='success' ? 'fa-circle-check' : kind==='pending' ? 'fa-clock' : 'fa-circle-minus';
    return `<span class="status-pill ${cls}"><i class="fa-solid ${icon}"></i>${label}</span>`;
}
function updateStepper(){
    const allDone = ssoSubmitted && bankSubmitted && taxSubmitted;
    const anyDone = ssoSubmitted || bankSubmitted || taxSubmitted;
    document.getElementById('stepSubmit').classList.toggle('done', anyDone);
    document.getElementById('stepDone').classList.toggle('done', allDone);
    document.getElementById('line2').classList.toggle('done', anyDone);
    document.getElementById('line3').classList.toggle('done', allDone);
}
function renderPeriod(){
    document.getElementById('periodLabel').textContent = MONTH_LABELS[periodIndex];
}
function renderSso(){
    const ssoEmpTotal = employees.reduce((s,e)=>s+e.ssoEmployee,0);
    const ssoEmrTotal = employees.reduce((s,e)=>s+e.ssoEmployer,0);
    document.getElementById('ssoHeadcount').textContent = employees.length;
    document.getElementById('ssoEmployeeTotal').textContent = `฿${THB(ssoEmpTotal)}`;
    document.getElementById('ssoEmployerTotal').textContent = `฿${THB(ssoEmrTotal)}`;
    document.getElementById('ssoAmountLabel').textContent = `฿${THB(ssoEmpTotal+ssoEmrTotal)}`;
    const statusHtml = ssoSubmitted ? pill('success','นำส่งสำเร็จ') : pill('pending','รอนำส่ง');
    document.getElementById('ssoStatusPill').innerHTML = statusHtml;
    document.getElementById('ssoHeaderPill').innerHTML = ssoSubmitted ? pill('success','เชื่อมต่อ e-Service แล้ว') : pill('idle','ยังไม่นำส่งงวดนี้');
    document.getElementById('ssoTableBody').innerHTML = employees.map(e=>`
        <tr>
            <td>${e.name}</td>
            <td style="color:var(--ink-soft)">${e.dept}</td>
            <td class="mono text-end">${THB(e.ssoEmployee)}</td>
            <td class="mono text-end">${THB(e.ssoEmployer)}</td>
            <td class="mono text-end fw-medium">${THB(e.ssoEmployee+e.ssoEmployer)}</td>
            <td>${ssoSubmitted ? pill('success','สำเร็จ') : pill('pending','รอนำส่ง')}</td>
        </tr>`).join('');
    document.getElementById('ssoReceipt').style.display = ssoSubmitted ? 'block' : 'none';
}
document.getElementById('ssoExportBtn').addEventListener('click', ()=>{
    showToast('สร้างไฟล์นำส่งประกันสังคม (รูปแบบ สปส. 1-10) เรียบร้อย พร้อมดาวน์โหลด');
});
document.getElementById('ssoSubmitBtn').addEventListener('click', ()=>{
    const ref = `SSO-${MONTH_LABELS[periodIndex].replace(/\D/g,'')}-${randInt(10000,99999)}`;
    ssoSubmitted = true;
    document.getElementById('ssoReceipt').textContent = `นำส่งสำเร็จ · เลขที่อ้างอิง ${ref} · วันที่นำส่ง ${new Date().toLocaleDateString('th-TH')}`;
    renderSso(); updateStepper();
    showToast('นำส่งข้อมูลประกันสังคมไปสำนักงานประกันสังคมเรียบร้อยแล้ว');
});
function renderBank(){
    const total = employees.reduce((s,e)=>s+e.netPay,0);
    document.getElementById('bankTotalAmount').textContent = `฿${THB(total)}`;
    document.getElementById('bankHeadcount').textContent = employees.length;
    document.getElementById('bankAmountLabel').textContent = `฿${THB(total)}`;
    document.getElementById('bankStatusPill').innerHTML = bankSubmitted ? pill('success','โอนสำเร็จ') : bankConnected ? pill('pending','พร้อมโอน') : pill('idle','ยังไม่เชื่อมต่อ');
    document.getElementById('bankHeaderPill').innerHTML = bankConnected ? pill('success','เชื่อมต่อบัญชีแล้ว') : pill('idle','ยังไม่ได้เลือกธนาคาร');
    document.getElementById('bankTableBody').innerHTML = employees.map(e=>`
        <tr>
            <td>${e.name}</td>
            <td style="color:var(--ink-soft)">${e.bankName}</td>
            <td class="mono">${e.bankAccount}</td>
            <td class="mono text-end fw-medium">${THB(e.netPay)}</td>
            <td>${bankSubmitted ? pill('success','โอนสำเร็จ') : pill('pending','รอโอน')}</td>
        </tr>`).join('');
    document.getElementById('bankReceipt').style.display = bankSubmitted ? 'block' : 'none';
}
const bankSelect = document.getElementById('bankConnectorSelect');
const placeholderOpt = document.createElement('option');
placeholderOpt.value = ''; placeholderOpt.textContent = 'เลือกธนาคาร / ระบบ Cash Management';
bankSelect.appendChild(placeholderOpt);
COMPANY_BANKS.forEach((b,i)=>{
    const opt = document.createElement('option');
    opt.value = i; opt.textContent = `${b.name} · ${b.product} (${b.account})`;
    bankSelect.appendChild(opt);
});
bankSelect.addEventListener('change', e=>{
    bankConnected = e.target.value !== '';
    if(bankConnected){
        const b = COMPANY_BANKS[Number(e.target.value)];
        showToast(`เชื่อมต่อ ${b.product} ของ${b.name} สำเร็จ`);
    }
    renderBank();
});
document.getElementById('bankExportBtn').addEventListener('click', ()=>{
    if(!bankConnected){ showToast('กรุณาเลือกธนาคารต้นทางก่อนสร้างไฟล์โอนเงิน'); return; }
    showToast('สร้างไฟล์โอนเงินเดือนแบบกลุ่ม (Bulk Transfer File) เรียบร้อย พร้อมดาวน์โหลด');
});
document.getElementById('bankSubmitBtn').addEventListener('click', ()=>{
    if(!bankConnected){ showToast('กรุณาเลือกธนาคารต้นทางก่อนยืนยันการโอนเงิน'); return; }
    const ref = `TXN-${randInt(100000,999999)}`;
    bankSubmitted = true;
    document.getElementById('bankReceipt').textContent = `โอนเงินสำเร็จ · เลขที่รายการ ${ref} · วันที่ทำรายการ ${new Date().toLocaleDateString('th-TH')}`;
    renderBank(); updateStepper();
    showToast('ยืนยันการโอนเงินเดือนผ่านธนาคารเรียบร้อยแล้ว');
});
function renderTax(){
    const total = employees.reduce((s,e)=>s+e.taxMonthly,0);
    document.getElementById('taxHeadcount').textContent = employees.filter(e=>e.taxMonthly>0).length;
    document.getElementById('taxTotal').textContent = `฿${THB(total)}`;
    document.getElementById('taxAmountLabel').textContent = `฿${THB(total)}`;
    document.getElementById('taxStatusPill').innerHTML = taxSubmitted ? pill('success','ยื่นแบบสำเร็จ') : pill('pending','รอยื่นแบบ');
    document.getElementById('taxHeaderPill').innerHTML = taxSubmitted ? pill('success','ยื่นแบบงวดนี้แล้ว') : pill('idle','ยังไม่ได้ยื่นงวดนี้');
    document.getElementById('taxConnStatus').innerHTML = pill('success','เชื่อมต่อระบบแล้ว');
    document.getElementById('taxReceipt').style.display = taxSubmitted ? 'block' : 'none';
}
document.getElementById('taxExportBtn').addEventListener('click', ()=>{
    showToast('สร้างไฟล์แบบ ภ.ง.ด.1 เรียบร้อย พร้อมดาวน์โหลด');
});
document.getElementById('taxSubmitBtn').addEventListener('click', ()=>{
    const ref = `RD-PND1-${randInt(1000000,9999999)}`;
    taxSubmitted = true;
    document.getElementById('taxRefLabel').textContent = ref;
    document.getElementById('taxReceipt').textContent = `ยื่นแบบสำเร็จ · เลขที่อ้างอิง ${ref} · วันที่ยื่น ${new Date().toLocaleDateString('th-TH')}`;
    renderTax(); updateStepper();
    showToast('ยื่นแบบ ภ.ง.ด.1 ผ่านระบบ e-Filing กรมสรรพากรเรียบร้อยแล้ว');
});
const periodSelect = document.getElementById('periodSelect');
MONTH_LABELS.forEach((m,i)=>{
    const opt = document.createElement('option');
    opt.value = i; opt.textContent = `งวด ${m}`;
    if(i===periodIndex) opt.selected = true;
    periodSelect.appendChild(opt);
});
periodSelect.addEventListener('change', e=>{
    periodIndex = Number(e.target.value);
    ssoSubmitted = false; bankSubmitted = false; taxSubmitted = false;
    renderPeriod(); renderSso(); renderBank(); renderTax(); updateStepper();
});
renderPeriod(); renderSso(); renderBank(); renderTax(); updateStepper();