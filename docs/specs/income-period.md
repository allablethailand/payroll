# Design: งวดรายได้และการรวมยอด (Income Period & Merge Rules)

สถานะ: **ยืนยันแล้ว** — พร้อมใช้เป็นสเปก Batch 5
วางที่: `docs/specs/income-period.md`
ที่มา: การตัดสินใจร่วมกัน 2026-09-12

---

## 1. หลักการเดียว

> **รายได้เป็นของเดือนที่จ่ายจริง (`payment_date`)** — ไม่ใช่เดือนที่เกิดงาน ไม่ใช่รอบอ้างอิง

เหตุผล: ภาษีเงินได้บุคคลธรรมดาไทยรับรู้เงินได้เมื่อ "ได้รับ" และเงินสมทบ สปส. คิดจากค่าจ้างที่จ่ายในเดือนนั้น ระบบจึงใช้ `payment_date` เป็นตัวตัดสินทุกอย่าง: สลิป, ภงด.1/91, 50 ทวิ, สปส., annual summary

นิยาม: **income_period** = `YYYY-MM` ของ `payment_date` ของ run ที่รายการนั้นถูกจ่าย

---

## 2. กติกา

| # | กติกา | ผล |
|---|---|---|
| R1 | ทุก run (ปกติ/พิเศษ) มี `payment_date` และ income_period คำนวณจากมันเสมอ | ไม่มี column "เดือนรายได้" แยกให้กรอก |
| R2 | "รอบอ้างอิง" / "งวดงาน" ที่ Origami ส่งมา เป็น **metadata** เท่านั้น — เก็บไว้แสดงในสลิป ("ค่าเที่ยว (งวด ส.ค.)") ไม่มีผลต่อ income_period | กรณี D |
| R3 | Origami ส่ง `payment_date` มาให้; แอดมินเปลี่ยนได้ตราบที่ run ยังเป็น draft (กฎ lock เดิมจาก 3C ข้อ 4) | การเปลี่ยน payment_date = เปลี่ยน income_period + merge target (R5) |
| R4 | `attribution = separate` → run พิเศษจ่ายเอง สลิปแยก ภาษีหักตาม `use_flat_tax_rate` ถ้าเปิด; income_period ตาม payment_date ของตัวเอง; ยอดปีรวมเข้า ภงด./50 ทวิ ตามเดือนนั้น | กรณี C |
| R5 | `attribution = merge` → รายการของ run พิเศษถูกจ่าย**พร้อม**กับ regular run ปลายทาง (merge target) ดังนั้น **payment_date และ income_period ของรายการ = ของ target** (ไม่ใช่ของ run พิเศษเอง) | กรณี B |
| R6 | merge target **หาให้อัตโนมัติ** = regular run ของ cycle เดียวกัน ที่ `payment_date` อยู่เดือนเดียวกับ `payment_date` ที่ Origami ส่งมา และยังรับได้ (R7) — แอดมินเห็นค่าที่ระบบเสนอ กด **ยืนยัน** หรือ **เลือกเอง** จากรายการ target ที่รับได้ | คำตอบข้อ 5 |
| R7 | target "รับได้" = state เป็น draft และยังไม่มี admin work ที่ขัด (ใช้ `hasAdminWork()` เดิม — มี admin work ยัง merge ได้ แต่ต้อง recalculate; ถ้า state ≠ draft รับไม่ได้) | |
| R8 | ถ้า target ของเดือนนั้น**ไม่มี**หรือ**รับไม่ได้** (อนุมัติ/จ่ายแล้ว) → ระบบเสนอ regular run ของ**เดือนถัดไป** (สร้างเป็น "จับคู่รอบที่จะสร้างภายหลัง" ถ้ายังไม่มี) พร้อมข้อความเตือนว่า income_period จะเลื่อนเป็นเดือนถัดไป — แอดมินยืนยัน หรือเปลี่ยนเป็น separate เอง | คำตอบข้อ 2 (ข) |
| R9 | ข้ามปี (งาน ธ.ค. จ่าย ม.ค.) = รายได้ปีใหม่ ตาม R1 ไม่มีข้อยกเว้น | คำตอบข้อ 4 |
| R10 | เมื่อ merge สำเร็จ target ต้อง recalculate (ภาษี/สปส. คิดจากยอดรวม) — ใช้ warning เดิมจาก 3C 4b (ไม่ auto-recalc) | |
| R11 | run พิเศษที่ merge แล้ว ไม่มีสลิปของตัวเอง ไม่มี payment ของตัวเอง state ของมันเปลี่ยนเป็น `merged` (ถ้า enum ยังไม่มี ต้องเสนอ migration) | |

---

## 3. กรณีอ้างอิง (ใช้เป็น test case)

| กรณี | Input | Output ที่ต้องได้ |
|---|---|---|
| A | เงินเดือน ส.ค. regular, payment_date 25/08 | income_period 2026-08, สลิป ส.ค. |
| B | ค่าเที่ยว ส.ค. พิเศษ, Origami payment_date 15/09, merge | target = regular ก.ย. (payment 25/09) → รายการอยู่ในสลิป ก.ย. บรรทัด "ค่าเที่ยว (งวด ส.ค.)", income_period 2026-09, ภาษีคิดรวมเงินเดือน ก.ย. |
| B2 | เหมือน B แต่ regular ก.ย. จ่ายไปแล้ว | ระบบเสนอ target = regular ต.ค. + เตือน; แอดมินยืนยัน → income_period 2026-10 หรือเปลี่ยนเป็น separate → C |
| C | ค่าเที่ยว ส.ค. พิเศษ, payment_date 15/09, separate, flat tax เปิด | สลิปแยกลงวันที่ 15/09, income_period 2026-09, ภาษีหักตาม flat rate, ยอดรวมเข้า ภงด.1 ก.ย. และ 50 ทวิ ปี 2026 |
| D | โบนัสอ้างอิงรอบ ส.ค., payment_date 05/09, separate | income_period 2026-09, สลิปแสดง "(งวด ส.ค.)" |
| E | แอดมินเปลี่ยน payment_date ของ run พิเศษ (merge, ยัง draft) จาก 15/09 เป็น 05/10 | ระบบเสนอ target ใหม่ = regular ต.ค. ต้องยืนยันอีกครั้ง; ถ้า target เดิม (ก.ย.) เคยยืนยันแล้ว ให้ถอดออกจากเดิมก่อน + เตือน recalc ทั้งสองรอบ |
| F | งาน ธ.ค. 2026 จ่าย 05/01/2027 | income_period 2027-01, อยู่ใน 50 ทวิ ปี 2027 |
| G | run พิเศษ merge แล้ว target ถูก revert กลับ draft | รายการที่ merge ยังอยู่ใน target; ถ้าแอดมิน "ถอด" ออก run พิเศษกลับเป็น draft ตาม state เดิมก่อน merge |

---

## 4. ผลกระทบกับของที่มีอยู่ (ให้ Claude Code ตรวจตอนเสนอ schema)

- `payroll_runs.payment_date` มีแล้ว → เพิ่ม **generated/derived** `income_period` (หรือคำนวณใน model ที่เดียว `PayrollRunModel::incomePeriod()`) — ห้ามมี 2 นิยาม
- `payroll_run_details` / รายการที่ merge: ต้องรู้ว่า "มาจาก run พิเศษ id ไหน" + "งวดอ้างอิง" (metadata R2) เพื่อแสดงในสลิปและ report ตรวจย้อน — น่าจะมีบางส่วนแล้วจาก attribution งาน 2026-08-31 ให้รายงานว่ามี column ไหนแล้ว ขาดอะไร
- merge target picker ปัจจุบัน ("จับคู่รอบที่จะสร้างภายหลัง") → เปลี่ยนเป็น **เสนออัตโนมัติ + ยืนยัน/เลือกเอง** (R6, R8) — UI อยู่ในฟอร์มจัดการรอบที่รวมแล้ว (3C ข้อ 4)
- Report ที่ใช้เดือน: ภงด.1, 50 ทวิ, สปส., annual-summary, PaymentVoucher — ทุกตัวต้อง group ด้วย income_period ไม่ใช่ `period_start` ของ run — ให้ grep รายงานว่าตัวไหนใช้อะไรอยู่
- Sync จาก Origami: ต้องรับ `payment_date` + reference period + attribution มาเก็บ — ยืนยันว่า payload ปัจจุบันมีครบ
- State machine: เพิ่ม `merged` (R11) ถ้ายังไม่มี

---

## 5. ตัดสินแล้ว (2026-09-12)

1. run พิเศษที่ merge แล้ว **ยังแสดง**ในรายการรอบเป็นแถวเทา state "รวมแล้ว → <เดือน target>" กดแล้วไปรอบปลายทาง
2. Origami ส่ง payment_date ที่เป็นอดีตมาแบบ merge → ใช้ **R8** (เลื่อนไปเดือนถัดไปที่รับได้ + เตือน) ไม่ block
3. flat tax ใช้ได้เฉพาะ **separate** เท่านั้น — merge ต้องคิดรวมกับเงินเดือนเสมอ (คงกฎ 3C 4c)

---

## 6. ลำดับทำ (Batch 5 ข้อแยก)

1. Claude Code เสนอ schema/migration/flow ตาม §4 (รายงานก่อน)
2. `incomePeriod()` + ให้ report ทุกตัวใช้ (logic เท่านั้น)
3. auto-suggest merge target + ยืนยัน/เลือกเอง + R8
4. state `merged` + สลิปแสดงงวดอ้างอิง
5. test cases A–G เป็น test file เดียว
