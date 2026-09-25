# ลบ `app/views/partials/payslip-view.php` (2026-09-16)

`payslip-view.php` เกิดพร้อม `payslipViewHtml()` (`public/js/app.js`) ตอน Round 3 item 3c-2 ในฐานะ "PHP twin"
เผื่อหน้าพิมพ์/PDF สลิปที่ render ฝั่ง server — หน้านั้นไม่เคยเกิด สลิปที่ render จริงทุกที่ (modal รายละเอียด
การคำนวณ, Adjustments modal > รายการจ่าย) เรียก `payslipViewHtml()` ฝั่ง client และ PDF สลิปจริงไปทาง
`PayslipTemplateRenderer` (canvas template) ซึ่งไม่เกี่ยวกับ markup ชุดนี้เลย

ยืนยันว่า dead: `grep -rn "payslip-view\.php"` ทั้ง repo เจอ 5 จุด เป็น **คอมเมนต์ล้วน** ทุกจุด ไม่มี `include`/`require` ที่ไหนเลย

ที่ตัดทิ้งไปด้วย: ไฟล์ที่ไม่มีใครเรียกแต่ §11 ระบุว่า "ต้องใช้ ห้ามเขียนเอง" ทำให้ต้องแก้ layout สลิป 2 ที่ทุกครั้ง
โดยครึ่งหนึ่งไม่มีทางทดสอบได้ — CSS (`.payslip-*`) อยู่ครบ ใช้กับ `payslipViewHtml()` ตามเดิม

**ฟื้นจาก git history**: `git show 22e4f5a:app/views/partials/payslip-view.php > app/views/partials/payslip-view.php`
(`22e4f5a` = commit สุดท้ายที่แตะไฟล์นี้) — ถ้าวันหนึ่งต้องมีหน้าพิมพ์สลิปฝั่ง server จริง ให้ port จาก
`payslipViewHtml()` ที่เป็นของจริงตอนนั้นแทน ไม่ใช่ฟื้นไฟล์นี้กลับมาเป็น twin อีกรอบ
