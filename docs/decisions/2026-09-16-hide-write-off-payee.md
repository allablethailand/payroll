# ซ่อนตัวเลือก payee "หักแต่ไม่มีเงินสดเคลื่อนไหว (Write-off)" + เขียนคำใหม่ทั้งชุด (2026-09-16)

> **อ่านคู่กับ `2026-09-16-payee-three-destinations.md` (วันเดียวกัน รอบถัดมา)** — เอกสารนี้คือ
> เหตุผลที่ `not_disbursed` ถูกถอดออก (ยังใช้อยู่ทั้งหมด) ส่วนรูปร่างของ control ที่อธิบายไว้ข้างล่าง
> (select 4 ค่า) ถูกแทนด้วย 3 ปลายทาง + คำถามย่อย ในรอบถัดมาแล้ว

เกี่ยวกับ dropdown "เงินที่หักได้ ส่งไปที่" (`payee_type`) ที่โผล่ใน 4 ที่: Adjustments modal > tab
"รายการจ่าย" โหมดรายการหัก (`#manualLinePayeeType`), tab "Recurring Deduction Destination"
(`#recurringDestPayeeTypeToggle`), Employee Detail's `#eedModal` และ `#recurringDeductionModal`

## สิ่งที่พบก่อนตัดสินใจ (audit, ไม่ได้เดา)

`none` (เก็บเป็น `payee_type = NULL`) กับ `not_disbursed` **ให้ผลลัพธ์เหมือนกันทุกประการในทุก code path
ที่มีอยู่จริง**:

- `PayrollRunModel::recalculate()` อ่านแค่ `payee_employee_id` ใน transfer-credit pass — ทั้งสองค่าเป็น
  NULL จึงถูกข้ามด้วยเงื่อนไขเดียวกัน ไม่มี branch ไหนใน `recalculate()` เช็ค `'not_disbursed'` เลย
- `PayrollRemittanceModel::generateForRun()` ข้ามทั้งสองค่าด้วย **บรรทัดเดียวกัน**
  (`if ($payeeType === null || $payeeType === 'not_disbursed') continue;`) → ไม่เกิดแถว
  `payroll_remittances` ทั้งคู่
- ไม่มีไฟล์ไหนใน `app/services/reports/`/`app/services/export/` อ้าง `payee_type` เลยสักที่ →
  PayrollRegister / DeductionBreakdown / PaySlip / BankTransferFile เห็นทั้งสองแบบเป็นยอดหักปกติเท่ากัน
- ต่างกันจริงแค่ `include_in_cash_summary` (บังคับ 0 สำหรับ `not_disbursed`) ซึ่ง**ยังไม่มี consumer ใดอ่าน**
  (ดู BACKLOG "include_in_cash_summary is stored but no report reads it")

แปลว่าตัวเลือกนี้ขอให้ผู้ใช้ตัดสินใจสิ่งที่ไม่เปลี่ยนอะไรเลย — เหตุผลเดียวกับที่ checkbox
"รวมใน Cash Payment Summary" ถูกถอดออกจาก tab เดียวกันไปแล้วเมื่อ 2026-09-15 และคำอธิบายที่เคยเขียนไว้
("เงินไม่ออกจากกองทุน") ก็ไม่ตรงกับพฤติกรรมจริง (เงินยังถูกหักจาก net pay ของพนักงานเต็มจำนวนเหมือน `none`)

## สิ่งที่ทำ

- **ถอดตัวเลือกออกจากทั้ง 4 picker** (UI เท่านั้น) — เหลือ 4 ตัวเลือก: `none`/`employee`/`company`/
  `other_person` (ยังเกิน 3 จึงยังเป็น `<select>` ตาม rules.md §9 ไม่ต้องเปลี่ยนเป็น segmented)
- **เขียนคำใหม่ทั้งชุดผ่าน `th.json`/`en.json`** — label เปลี่ยนจาก "เงินที่หักไปจ่ายให้" เป็น
  "เงินที่หักได้ ส่งไปที่" และตัวเลือกเปลี่ยนจากคำที่บอก "ปลายทางคือใคร" เป็นคำที่บอก "เกิดอะไรขึ้นกับเงิน"
  (`none` = "บริษัทเก็บไว้ ไม่ต้องโอน") พร้อม helper 1 บรรทัดต่อค่า (rules.md §9: บรรทัดเทา `--fs-sm`
  ใต้ control — มาจาก `.form-compact` ที่มีอยู่แล้ว ไม่ต้องเพิ่ม CSS)
- `payee_desc_not_disbursed` ถูกลบออกจากทั้ง 2 ไฟล์ภาษา (ไม่มีที่ใช้แล้ว), `payee_type_not_disbursed`
  **ยังอยู่** เพราะ read path ยังต้องใช้แสดง tag ของแถวเก่า

## สิ่งที่ไม่ได้แตะ (ตั้งใจ)

- **Backend ทั้งหมด**: enum `payee_type` ในทุกตาราง, validation ทั้ง 4 write path (ยังรับครบ 5 ค่า
  เหมือนเดิม), การบังคับ `include_in_cash_summary = 0`, `PayrollRemittanceModel` — ไม่มีไฟล์ PHP
  ฝั่งโมเดล/คอนโทรลเลอร์ไฟล์ไหนเปลี่ยนเลย ไม่มี migration
- **Read path**: แถวที่เก็บ `not_disbursed` ไว้แล้วยังแสดง tag ของตัวเองเหมือนเดิมทุกที่
  (`payroll/detail.js`'s 2 จุด, `employee/detail.js`, `recurringDestPayeeSummary()`)

## แถวเก่าเวลาเปิดฟอร์มแก้

picker ที่ไม่มีตัวเลือกนั้นแล้วจะ render เป็น "ไม่มีอะไรถูกเลือก" ซึ่งเซฟกลับไปเป็นอะไรก็ได้แล้วแต่ fallback
ของแต่ละ caller — จึงบังคับให้ชัดผ่าน `payeeTypeForEditor(type, fallback)` (`app.js`, ใช้ร่วมกันทั้ง 2
page script): editor เปิดที่ `none` สำหรับ 2 ฟอร์มของ Employee Detail และที่ `company` สำหรับ per-run
override editor (ตัวนั้นไม่มี `none` ของตัวเองอยู่แล้ว — "ไม่ override" คือปุ่ม Reset) — ค่าที่เก็บไว้ใน DB
ไม่เปลี่ยนจนกว่าผู้ใช้จะกดบันทึกฟอร์มนั้นจริง และเพราะ `none` คำนวณเหมือน `not_disbursed` เป๊ะอยู่แล้ว
การบันทึกทับจึงไม่เปลี่ยนเงินสักบาท

## เปิดกลับเมื่อไหร่

ดู BACKLOG 2 รายการที่เพิ่มพร้อมกันรอบนี้: (a) เปิด `not_disbursed` กลับเมื่อลูกค้านิยาม semantic ที่ต่างจาก
`none` จริง **และ** มีรายงานที่อ่าน `payee_type`, (b) `none` กับ `company` เองก็ต่างกันแค่แถว audit ใน
`payroll_remittances` — ต้องถามลูกค้าพร้อมเรื่อง money-encryption
