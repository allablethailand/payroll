# EED row actions — ⋮ overflow (2026-09-23, ก้อน B1/B3)

แบบ B ตาม §7 L1573-1580 (demo `components.php:1060-1094`):

| กรณี | นอก ⋮ | ใน ⋮ |
|---|---|---|
| ยังไม่เริ่มงวด (`current_installment=0`) | ดู, พัก/ทำต่อ, ยกเลิก | แก้ไข, — เส้นคั่น —, ลบ (แดง) |
| เริ่มงวดแล้ว | ดู, พัก/ทำต่อ, ยกเลิก | ไม่มี ⋮ (L1580: ≤3 ห้ามมี ⋮) |
| completed/cancelled | ดู | ไม่มี ⋮ |

**ตำแหน่งปุ่มคงที่**: ดู/พัก-ทำต่อ/ยกเลิก อยู่นอก ⋮ ทุกกรณีที่ `isOpen` ไม่ขยับตามสถานะ — แก้ไข/ลบหายไปเมื่อเริ่มจ่ายงวด
แรก (`save()` ล็อกถาวร) จึงควรเป็นตัวที่ "หาย" ไม่ใช่ตัวที่ต้องอยู่ตำแหน่งเดิมเสมอ

**เลือก B ไม่ใช่ A** (ดู/แก้/⋮ นอก): พัก/ยกเลิกใช้บ่อยกว่าแก้ไข (เปลี่ยน status ตรงๆ) ส่วนแก้ไข/ลบมีเงื่อนไข (แค่ตอนยังไม่เริ่มงวด) จึงเหมาะพับเข้า ⋮ มากกว่า

## บั๊ก shared ที่เจอระหว่างวัด (B3)
`applyFixedStrategyToTableDropdowns()` (`app.js:814`) เช็คแค่ `.dataTables_wrapper`/`.table-responsive` — แอปนี้
ใช้ DataTables 2.3.8 ทั้งแอป (wrapper จริงคือ `.dt-container`, `.dataTables_wrapper` ตายแล้ว) ⋮ ที่ไม่มี
`.table-responsive` ห่อ (EED, `tb_payroll_run`, `tb_ect_template`, `tb_pst_template`) จึงไม่เคยได้ `strategy:'fixed'`
เลย — แก้ 1 บรรทัด เพิ่ม `.dt-container` เข้า selector เดียวกัน (rules.md §7: popper fixed ครอบ DT 1.x/2.x ทั้งคู่)
