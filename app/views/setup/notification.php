<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>การแจ้งเตือน — Settings</title>
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
.card-surface{background:#fff;border:1px solid var(--line);border-radius:12px;}
.settings-nav{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.settings-nav a{border:1px solid var(--line);background:#fff;border-radius:20px;padding:6px 14px;font-size:.83rem;font-weight:600;color:var(--muted);text-decoration:none;}
.settings-nav a.active{background:var(--brand);border-color:var(--brand);color:#fff;}
table.pl-table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);border-bottom:2px solid var(--line);font-weight:700;}
table.pl-table td{vertical-align:middle;}
table.pl-table th,table.pl-table td{text-align:center;}
table.pl-table th:first-child,table.pl-table td:first-child{text-align:left;}
.form-check-input:checked{background-color:var(--brand);border-color:var(--brand);}
.section-title{font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin:24px 0 10px;}
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
      <span class="bc-current">การแจ้งเตือน</span>
    </h5>
  </nav>

  <div class="settings-nav">
    <a href="#">ตั้งค่าบริษัท</a><a href="#">รอบการจ่าย</a><a href="#">รายได้ / รายหัก</a>
    <a href="#">บัญชีธนาคาร</a><a href="#">สิทธิ์ผู้ใช้งาน</a><a href="#">ภาษี &amp; กองทุน</a>
    <a href="#">โครงสร้างองค์กร</a><a href="#">เวลาทำงาน &amp; วันลา</a>
    <a href="#">อนุมัติ &amp; เอกสาร</a><a class="active" href="#">การแจ้งเตือน</a>
  </div>

  <div class="card-surface p-3 p-md-4">
    <h6 class="fw-bold mb-1">การแจ้งเตือนของระบบ Payroll</h6>
    <div class="text-secondary small mb-3">เลือกช่องทางที่จะแจ้งเตือนสำหรับแต่ละเหตุการณ์</div>

    <div class="section-title">งวดเงินเดือน / การอนุมัติ</div>
    <table class="table pl-table mb-0">
      <thead><tr><th>เหตุการณ์</th><th style="width:110px;">อีเมล</th><th style="width:110px;">LINE</th><th style="width:110px;">ในระบบ (In-app)</th></tr></thead>
      <tbody>
        <tr><td>มีงวดเงินเดือนรอการอนุมัติ</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox" checked></td></tr>
        <tr><td>งวดเงินเดือนได้รับการอนุมัติครบทุกขั้นตอน</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox"></td><td><input class="form-check-input" type="checkbox" checked></td></tr>
        <tr><td>งวดเงินเดือนถูกปฏิเสธ / ไม่อนุมัติ</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox" checked></td></tr>
        <tr><td>จ่ายเงินเดือนเรียบร้อยแล้ว</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox"></td><td><input class="form-check-input" type="checkbox" checked></td></tr>
      </tbody>
    </table>

    <div class="section-title">พนักงาน</div>
    <table class="table pl-table mb-0">
      <thead><tr><th>เหตุการณ์</th><th style="width:110px;">อีเมล</th><th style="width:110px;">LINE</th><th style="width:110px;">ในระบบ (In-app)</th></tr></thead>
      <tbody>
        <tr><td>สลิปเงินเดือนพร้อมให้ดาวน์โหลด</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox"></td></tr>
        <tr><td>เอกสาร 50 ทวิ พร้อมให้ดาวน์โหลด</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox"></td><td><input class="form-check-input" type="checkbox"></td></tr>
        <tr><td>เพิ่มพนักงานใหม่เข้าระบบ</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox"></td><td><input class="form-check-input" type="checkbox" checked></td></tr>
      </tbody>
    </table>

    <div class="section-title">เอกสารและวันครบกำหนด</div>
    <table class="table pl-table mb-0">
      <thead><tr><th>เหตุการณ์</th><th style="width:110px;">อีเมล</th><th style="width:110px;">LINE</th><th style="width:110px;">ในระบบ (In-app)</th></tr></thead>
      <tbody>
        <tr><td>ใบอนุญาตทำงาน / วีซ่า ใกล้หมดอายุ (พนักงานต่างชาติ)</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox"></td><td><input class="form-check-input" type="checkbox" checked></td></tr>
        <tr><td>สัญญาจ้างใกล้ครบกำหนด</td><td><input class="form-check-input" type="checkbox" checked></td><td><input class="form-check-input" type="checkbox"></td><td><input class="form-check-input" type="checkbox" checked></td></tr>
      </tbody>
    </table>

    <div class="mt-4">
      <label class="form-label fw-bold">ผู้รับแจ้งเตือนสำรอง (Fallback Recipient)</label>
      <input class="form-control" style="max-width:400px;" value="hr-admin@origami.co" placeholder="อีเมลสำหรับรับแจ้งเตือนกรณีหาผู้รับหลักไม่ได้">
    </div>

    <div class="d-flex justify-content-end mt-4"><button class="btn btn-brand">บันทึกการตั้งค่า</button></div>
  </div>
</div>

<div class="mock-tag"><i class="fa-solid fa-flask me-1"></i> Mockup — ข้อมูลจำลอง</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body></html>