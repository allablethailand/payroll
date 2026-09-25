# Payee = 3 ปลายทาง + คำถามย่อย 1 ข้อ (2026-09-16)

ต่อจาก `2026-09-16-hide-write-off-payee.md` (รอบเดียวกัน วันเดียวกัน) — รอบนั้นถอด `not_disbursed`
ออกจากรายการ 5 ค่า เหลือ 4 ค่าแบนๆ รอบนี้เลิกให้ผู้ใช้เลือก "ค่าใน enum" ทั้งหมด แล้วถามเป็นคำถามที่ผู้ใช้
ตอบได้จริงแทน

## โครงใหม่

```
เงินที่หักได้ ส่งไปที่
[ หักเข้าบริษัท ] [ โอนให้พนักงานคนอื่น ] [ โอนให้บุคคล/หน่วยงานภายนอก ]     (.segmented, §9)
<คำอธิบายของค่าที่เลือกอยู่ 1 บรรทัด --fs-sm/--c-text-muted>
  └─ callout (.payee-dest-subform, เส้นซ้าย --c-border)
     • หักเข้าบริษัท → "บันทึกเป็นรายการโอนเข้าบัญชีบริษัทหรือไม่" [ไม่บันทึก] [บันทึก]
         ไม่บันทึก (default) → ไม่ส่ง payee_type เลย  = NULL
         บันทึก            → payee_type='company' + เลือกบัญชีบริษัท (preselect ตัว is_default)
     • โอนให้พนักงานคนอื่น → payee_type='employee'    + เลือกพนักงาน (+payeeDetail)
     • โอนให้บุคคล/หน่วยงานภายนอก → payee_type='other_person' + ปลายทาง
         (ไม่มีปลายทางที่บันทึกไว้ = ฟอร์มตรง / มี = [บันทึกไว้][ระบุใหม่])
```

**เหตุผล**: ค่าใน enum ตอบคำถามว่า "แถวนี้ผูกกับใคร" ซึ่งเป็นภาษาของตาราง ไม่ใช่ภาษาของคนทำเงินเดือน —
คนทำเงินเดือนรู้แค่ว่าเงินก้อนนี้ "อยู่กับบริษัท / ไปหาพนักงานอีกคน / ออกไปข้างนอก" ส่วน `NULL` กับ
`'company'` ที่เคยเป็น 2 ตัวเลือกคู่กัน จริงๆ แล้วต่างกันแค่ "มีแถว audit ใน `payroll_remittances` ไหม"
(ดู `2026-09-16-hide-write-off-payee.md`'s audit) — นั่นคือคำถามย่อย ไม่ใช่ปลายทางที่ 4

## ของกลาง 1 ชุด ใช้ 4 ที่

- **`app/views/partials/payee-destination.php`** — label + segmented 3 ตัว + บรรทัดคำอธิบาย + callout
  ที่มีคำถามย่อย และ **slot** (`$payee_slot`) สำหรับ sub-form ของผู้เรียกเอง (ใช้ `ob_start()` แบบเดียวกับ
  `$filter_fields_html` ของ `filter-bar.php` ที่หน้านี้ใช้อยู่แล้ว) — sub-form ทั้ง 3 ของแต่ละ picker
  **ยังเป็นของเดิม id เดิม** แค่ย้ายเข้าไปอยู่ใน callout
- **`initPayeeDestination(prefix, options)` / `payeeDestinationType(prefix)` /
  `setPayeeDestination(prefix, payeeType)` (app.js)** — พฤติกรรม + การแปลงค่า UI → `payee_type`
  ที่เดียวทั้งแอป; `options.onChange(payeeType, dest)` คือที่ที่แต่ละหน้าเก็บเรื่องของตัวเอง
  (เคลียร์ฟิลด์, `.required`, ดึงค่า default)
- `$payee_allow_no_record = false` สำหรับ per-run override editor (`recurringDest`) เพราะ backend
  ของมันไม่มีค่า "ไม่มี payee" เลย (การ "ไม่ override" คือปุ่ม Reset) — "หักเข้าบริษัท" ที่นั่นจึงแปลว่า
  `'company'` ตรงๆ ไม่มีคำถามย่อย
- `applyDefaultCompanyBankAccount()` / `payeeDetailFromOption()` ถูกยกจาก `payroll/detail.js` ขึ้นมาไว้
  ที่ `app.js` เพราะตอนนี้มีผู้ใช้ 3 ราย (กฎ "ห้าม mirror-copy" ของ CLAUDE.md)

## ไม่แตะ backend เลย

enum `payee_type` ยังเป็น 5 ค่าเดิม, validation ทั้ง 4 write path ยังรับครบ 5 ค่า, ไม่มี migration —
การแปลงค่าเกิดที่ client จุดเดียวก่อน submit (`payeeDestinationType()`) และ "ไม่บันทึก" = **ไม่ส่งคีย์
`payee_type` เลย** ซึ่งเป็นสิ่งที่ทำให้โมเดลเก็บ NULL ตาม default ของคอลัมน์เอง (เหมือนที่
`include_in_cash_summary` ทำอยู่แล้ว)

แถวเก่าที่เก็บ `'not_disbursed'` เปิด editor ได้ปกติ — ตกที่ "หักเข้าบริษัท / ไม่บันทึก" ซึ่งคือสิ่งที่มัน
คำนวณเหมือนกันเป๊ะอยู่แล้ว; read path ยังโชว์ tag เดิมของมันจนกว่าจะกดบันทึกทับ

## ที่ตรวจจริง (Playwright + DB)

เพิ่มรายการจริง 1 รายการต่อทาง ในรอบจริง (run #752) แล้วอ่านค่าจาก `payroll_run_manual_lines` ตรงๆ:
หักเข้าบริษัท/ไม่บันทึก → `payee_type` NULL · หักเข้าบริษัท/บันทึก → `'company'` + `bank_account_id`
(บัญชี default ถูก preselect ให้เอง) · โอนให้พนักงานคนอื่น → `'employee'` + `payee_employee_id`
(พนักงานที่ไม่มีบัญชีธนาคาร ปุ่มเพิ่มยัง disabled เหมือนเดิม) · โอนให้ภายนอก แบบเลือกจากที่บันทึกไว้ →
`'other_person'` + `destination_id` เดิม · แบบระบุใหม่ → `'other_person'` + destination ที่เพิ่งสร้าง —
ลบข้อมูลทดสอบออกครบแล้ว (business table กลับเป็น 0 แถวเท่าเดิม)

## ค้างไว้ให้ลูกค้าตัดสิน

ถ้าลูกค้าตอบว่า "ไม่ต้องแยก audit row" คำถามย่อยทั้งข้อหายไปได้เลย เหลือ 3 ปลายทางล้วน — ดู BACKLOG
"`none` กับ `company` ต่างกันแค่แถว audit" (ถามพร้อมเรื่อง money-encryption)
