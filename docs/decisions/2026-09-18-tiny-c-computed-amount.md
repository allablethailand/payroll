# tiny-C — persist ค่า engine ก่อน override (`computed_amount`)

บรรทัด `ระบบ: x` เดิมอ่าน `original_value` จาก override history = **ยอด override เก่า** ไม่ใช่ค่าที่ engine คำนวณ
(breakdown JSON เก็บแต่ยอดหลังทับ) tiny-L6b แก้ชั่วคราวด้วยการไม่แสดงอะไรเลยเมื่อไม่มี history — รอบนี้ปิดของจริง
โดยให้ `recalculate()` เก็บค่าก่อนทับไว้ตอนที่ยังมีอยู่

**ทำไมต้อง DDL ทั้งที่กฎให้เลี่ยง** — earning/deduction/statutory มี entry ของตัวเองใน breakdown JSON เพิ่มคีย์
`computed_amount` ได้ฟรี (ทุก consumer รวมยอดด้วยคีย์ระบุชื่อ `amount`/`employee_amount` ไม่มีที่ไหน `array_sum()`
ทั้ง entry — ตรวจครบแล้ว) แต่เงินเดือนพื้นฐานไม่มี entry เลย `base_salary_amount` เป็นคอลัมน์ decimal เดี่ยวที่
recalculate() เขียนค่า**หลัง** override ลงไป ทางเลือกจึงมีแค่คอลัมน์ใหม่ กับยัด entry สังเคราะห์เข้า breakdown ซึ่งจะทำให้
payslip/register/remittance/PND1/กยศ. ได้แถวเกินทันที → เลือกคอลัมน์ nullable (NULL = ไม่มี override)

**ทำไมแถว exclude ยังเงียบ** — `exclude` ดรอป entry ทั้งก้อน จะเก็บค่า engine ได้ต้อง persist entry กลับเข้าไป
= แถว 0 โผล่ในสลิป/รายงานจริง คนละเรื่องกัน ยกไป BACKLOG ข้อเดิม (แถว exclude เสียปลายทาง/เลขงวด) ให้ตามมาพร้อมกัน

**fallback ห้ามถอด** — run ที่คำนวณก่อนรอบนี้ไม่มีคีย์ `lineOverrideComputedTextRd()` จึงอ่านคีย์ก่อน แล้วตกไป history เดิม

**วัดได้** — run 752 recalculate ผ่าน CLI ก่อน/หลังเท่ากันทุกตัว (2 แถว · gross 87,000.00 · net 66,759.45 ·
deduction 20,240.55) override 2 รายการยังอยู่ · EM009 ได้ `base_salary_computed_amount=30,000.00`,
`LOAN_REPAY.computed_amount=4,000` (ยอดจริง 5,000) · CEO ไม่มี override → NULL และไม่มีคีย์ในทุก entry ·
Playwright 2 cell (1400 th light / 430 th dark) 16/16 ตรงกับ `computed_amount` ที่ API คืน
