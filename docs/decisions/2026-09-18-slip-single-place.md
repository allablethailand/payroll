# สลิปดู/สลิปแก้ = ตารางเดียว (4a-1, 2026-09-18)

หน้าดูเคยมี renderer ของตัวเอง (`breakdownViewSlipHtml` + `breakdownLineRowsRd` + `statutoryRowsRd`, layout 2 คอลัมน์) คนละตัวกับตารางที่ใช้แก้ — บรรทัดเดียวกันจึงอ่านได้คนละแบบใน 2 หน้าจอที่ห่างกัน 1 คลิก

**เลือก B1: หน้าดูเรียก `api/payroll-run.sync-lines-for-employee` ตัวเดียวกับหน้าแก้** ไม่ใช่ enrich `getDetails()` เพราะ **แถวที่ถูก exclude ไม่มีอยู่ใน breakdown JSON เลย** (recalculate ตัดทิ้ง) สลิปที่ render จาก JSON นั้นจึงแสดงแถวพวกนั้นไม่ได้ในทางหลักการ จะทำได้ต้องเขียน `syncDeductionLinesForEmployee()` ซ้ำอีกตัว (ผิดกฎ mirror-copy) — endpoint นี้ SELECT ล้วน ไม่มี recalc side-effect, `requireViewAccess()` เหมือนกัน, mask เข้มกว่า

ราคา: **+2 request ต่อการเปิดสลิปหน้าดู** (lines + history) — วัดจริง `blockedRecalculates` 1/page-load เท่าเดิมกับ tiny-C

PHP แตะเท่าที่จำเป็น: row builder ส่ง passthrough 5 field ที่หน้าดูเคยอ่านจาก JSON (`formula`, `is_exempted`, `exempted_amount`, `is_custom`, `is_other`) + `note` ของ earning/deduction ที่เคย hardcode `null` — ไม่มีการคำนวณ/ลำดับ/จำนวนแถวเปลี่ยน — `exempted_amount` เข้า `ADJUST_LINE_MONEY_KEYS` (เป็นยอดเงินจริงของคนนั้น)

**ลบทิ้ง**: renderer หน้าดู 3 ตัว + `payslipEmptyRowRd` + ปุ่ม "?" (`formulaButtonRd` + CSS) + collapse "แสดง n แถวที่ซ่อน" + บรรทัด "ยืนยันแล้ว — ยกเลิกการยืนยันก่อนแก้" + lang key 5 ตัว (net −189 บรรทัด)

**วัดได้** (8 cell th/en × light/dark × 1400/430, 176 assertion): 2 โหมด rows 8/8 · หัวกลุ่ม 3/3 · หน้าดู `col-check`/`lo-action-cell`/switch/ดินสอ = 0 ใน DOM · `d-none` = 0 · ยอดรวม 3 แถวตัวเลขตรงกัน · `-` ในช่องว่าง = 0 · ปุ่ม "?" = 0 · ลำดับ tag ต่อแถวตรงกัน · 430 sticky ยังทำงาน

**ยอดรวม 3 แถวอยู่ล่างสุดนอกตาราง ใต้การ์ด `.ml-mount` ชั่วคราว** (ทั้ง 2 โหมด, renderer เดียวไม่มี mode) — เพราะยังมีเงินอีกก้อนอยู่ใต้ตาราง ยอดรวมที่อยู่ท้ายตารางจะอ่านเป็น "รวมของตารางนี้" ซึ่งผิด · กลับเป็นแถวท้ายตารางเมื่อ 4a-2 ย้าย manual เข้าตาราง

**tag ใต้บรรทัดแสดงเต็ม wrap ได้ ไม่ย่อ ไม่มี `title`** — ที่ 430px ไม่มี hover ให้ใช้เลย การย่อจึงเท่ากับตัดเหตุผลของยอดทิ้งบนอุปกรณ์ที่คนดูสลิปจริง (วัดแล้ว: tag ยาวสุด 151 ตัวอักษร, 14 tag ไม่มีตัวไหน clipped/ellipsis/nowrap, `--fs-xs` = 12px เท่าเดิม)

บั๊กจริงที่เจอจากการวัด: `lineOverridePublishStickyOffsetRd()` return ทิ้งเมื่อไม่มี `th.col-check` → หน้าดูที่ 430px ไม่ publish `--lo-sticky-left-2` คอลัมน์ชื่อจึงไม่ถูก pin — แก้เป็น 0px เมื่อไม่มีคอลัมน์นำหน้า
