# ตาราง "รายละเอียดพนักงาน" — ย้ายข้อมูลออกจากเซลล์ (2026-09-16)

## ปัญหาที่วัดได้ก่อนแก้ (Payroll Detail → tab รายละเอียดพนักงาน)

| จุด | ของเดิม |
|---|---|
| คอลัมน์รหัสพนักงาน | รหัส + ปุ่ม "ปรับแล้ว N" + ไอคอน `fa-file-invoice-dollar` **สีฟ้า** (`text-info`) |
| คอลัมน์การคำนวณ | badge สถานะ + ข้อความ calc_errors ต่อกันทั้งหมดในบรรทัดที่ 2 (`<div class="small">`) สีแดงเสมอ |
| ความสูงแถว | ไม่เท่ากัน — แถวที่มีคำเตือน 2-3 ข้อ wrap เป็น 2-3 บรรทัด |
| "ปรับแล้ว N" นับจาก | `line_override_count + manual_line_count` = 2 ใน 6 ตารางที่ modal ปรับรายการเขียนจริง |

ผลหลังแก้ (วัดที่ 1400/430 ทั้ง light/dark, 3 แถวจริงที่ seed ไว้): **ทุกแถวสูง 45px เท่ากัน**,
เซลล์การคำนวณเหลือบรรทัดเดียว, ไม่มี console error

## สิ่งที่ตัดสินใจ

### 1. "ปรับแล้ว N" → count badge บนปุ่มกลม "ปรับรายการ"

ตัวเลขย้ายไปอยู่กับปุ่มที่เปิด modal ที่ใช้ปรับจริง (§5's `countBadgeHtml()` + `.btn-circle-action-badge`
แบบเดียวกับปุ่มคอมเมนต์) — **tone neutral** เพราะ §5 สงวน `primary` ไว้สำหรับ "ใหม่/ยังไม่อ่าน" เท่านั้น
ส่วน "คนนี้มีการปรับ" เป็นข้อเท็จจริงที่ค้างอยู่ ไม่ใช่ของใหม่

`adjustment_count` (ใหม่ใน `getDetails()`) นับ **ทุกตารางที่ 5 tab ของ `#manageLinesModal` เขียน**:
`payroll_run_manual_lines`, `payroll_run_line_overrides`, `payroll_run_sync_item_overrides`,
`payroll_run_recurring_deduction_overrides` (join `employee_recurring_deductions` เพราะตารางนี้ key ด้วย
`recurring_id` ไม่มี `employee_id` ของตัวเอง), `payroll_run_employee_exemptions` (เฉพาะแถวที่ override จริง
ไม่ใช่ `inherit` ล้วน)

**ไม่นับ `payroll_run_item_exclusions`** — ตารางนี้เป็น run + item_code ไม่มี `employee_id` เลย เป็นการตั้งค่า
ระดับรอบ ไม่ใช่ของพนักงานคนใดคนหนึ่ง (จะนับให้ทุกคนเท่ากันซึ่งไม่มีความหมาย) · **ไม่มี `employee_bank_accounts`**
ในสคีมา — tab ปลายทางเก็บบัญชีไว้ในคอลัมน์ `bank_account_id` ของแถว override เอง จึงถูกนับไปแล้วพร้อมแถวนั้น

ปุ่มกลมนี้มีเฉพาะรอบ draft ตามเดิม — viewer "รายการที่ปรับ" (`empAdjustmentsModal`) ที่เคยเปิดจากปุ่ม
"ปรับแล้ว N" ย้ายไปอยู่ในเมนู ⋮ ของแถว จึงยังเปิดได้บนรอบที่ไม่ใช่ draft (ซึ่งเป็นเหตุผลเดิมที่ badge ไป
อยู่ในคอลัมน์รหัสตั้งแต่แรก)

### 2. advisory vs blocking: ย้ายรายการเดียวขึ้นมาเป็น const

`recalculate()` มีรายการ "error ที่ไม่บล็อก" เขียน inline มาตั้งแต่ 2026-09-02 (array_diff 6 ค่า + strpos 2
prefix) — ย้ายเป็น `PayrollRunModel::ADVISORY_CALC_ERROR_CODES`/`_PREFIXES` + `isAdvisoryCalcError()` +
`splitCalcErrors()` เพื่อให้ **ฝั่งอ่าน (`getDetails()`) แยกด้วยเกณฑ์เดียวกับฝั่งเขียน** ไม่ใช่ re-derive เอง

**ไม่เปลี่ยนพฤติกรรม** — `tests/payroll_calc_warnings_test.php` รันนิพจน์เดิมคู่กับของใหม่บน 7 ชุดตัวอย่าง
แล้วยืนยันว่าได้ blocking set และ `calc_status` ตรงกันทุกชุด (รวม 6 exact code + 2 prefix = 8 รายการ ไม่ใช่ 7)

`getDetails()` จึงคืน `calc_warnings[]`/`calc_blocking[]` เป็น code ดิบ — การแปลเป็นประโยคยังอยู่ที่
`calcErrorMessageRd()` (detail.js) ที่เดียวเหมือนเดิม

### 3. คำเตือนออกจากเซลล์ไปอยู่ 2 ที่

- **ในตาราง**: badge "N คำเตือน" tone warning ไม่มีไอคอน คลิกเปิด **popover กลาง** (`initPopovers()`, §11)
  บรรทัดละข้อ — **ไม่ reuse popup ของ column-filter**: panel นั้นมีตัวเดียวทั้งแอป (`ensurePanel()`) และมี
  checklist + ปุ่ม [ล้าง]/[ใช้ตัวกรอง] เป็นโครงหลัก ซึ่งรายการอ่านอย่างเดียวนี้ไม่ใช้เลยสักส่วน
- **ใน modal "รายละเอียดการคำนวณ" ของแถวนั้น**: ข้อความเต็ม — `calc_blocking` เป็น callout danger (ขึ้นก่อน
  เพราะเป็นเหตุผลที่แถวขึ้นว่า error), `calc_warnings` เป็น callout warning — **1 ข้อ = 1 callout** (§15: callout
  คือหนึ่งใจความ; 3 ข้อในกล่องเดียวอ่านเป็นเรื่องเดียว ทั้งที่เป็น 3 เรื่องที่ต้องจัดการแยกกัน)
- สีแดงเหลือที่เดียวคือ badge `calc_status = error` ตามที่สั่ง (ของเดิมข้อความ advisory ก็แดงด้วยทั้งที่ไม่บล็อก)

**หมายเหตุการตีความ**: "quick-view" ในคำสั่งข้อ 5 = modal **รายละเอียดการคำนวณ** ของแถว (`#runDetailBreakdownModal`)
ไม่ใช่ Employee Quick View กลาง (`#employeeQuickViewModal`) — ตัวหลังเป็น modal ระดับแอปที่เปิดจาก avatar ทุกหน้า
ข้อมูลมาจาก `api/employee.quick-view` ซึ่งไม่รู้จักรอบเงินเดือนเลย จึงไม่มีทางมีคำเตือนของรอบนี้ให้แสดง

### 4. แถวสรุปท้ายตาราง

`คำนวณแล้ว a/b · คำเตือน c` — **c นับ "จำนวนพนักงานที่มีคำเตือนอย่างน้อย 1 ข้อ"** ไม่ใช่จำนวนข้อความรวม
เพราะอยู่บรรทัดเดียวกับ a/b ซึ่งเป็นจำนวนคน (ผสมหน่วยในบรรทัดเดียวจะอ่านผิด) — ซ่อนทั้งท่อนเมื่อ c = 0

## บั๊กจริงที่เจอระหว่างทาง (แก้แล้ว) — comment CSS ปิดเองกลางประโยค รอบที่ 2 และ 3

วัดขนาดตัวอักษรของ popover ที่เพิ่งต่อเข้ามาแล้วได้ **10.5px** (ค่า default ของ Bootstrap) แทนที่จะเป็น
`--fs-sm` 13px ตามที่ `style.css` เขียนไว้ — ไล่ไปเจอว่าเป็นบั๊กเดิมที่ CLAUDE.md เตือนไว้ (tokens.css,
2026-09-14) ซ้ำอีก 2 จุดใน `style.css`:

| จุด | ข้อความในคอมเมนต์ | ผลจริง |
|---|---|---|
| Shared popover (§9/§11) | `Every value is a --c-*/` | `.popover { --bs-popover-* }` **ทั้งบล็อกไม่เคยถูกใช้เลย** ตั้งแต่ 2026-09-14 — popover ทุกตัวในแอปใช้สี/ขนาดเริ่มต้นของ Bootstrap มาตลอด |
| Bootstrap-datepicker theme | `§1 --c-*/--radius-lg/--shadow-modal` | `.datepicker.datepicker-dropdown { ... }` เสียเช่นกัน ตั้งแต่ 2026-09-13 |

`tests/css_parse_test.php` เดิมจับไม่ได้ เพราะไฟล์ยัง **balanced** (คอมเมนต์ที่ปิดไปแล้วไม่กินวงเล็บปีกกา) และ
`style.css` ใหญ่เกินกว่าจะ assert รายชื่อ selector ได้แบบ tokens.css — เพิ่มกฎใหม่ที่ **แน่นอน ไม่ใช่ heuristic**:
*ตัวปิดคอมเมนต์ที่โผล่ตอนที่ไม่ได้อยู่ในคอมเมนต์ คือหลักฐานว่ามีคอมเมนต์ถูกปิดไปก่อนหน้าแล้วเสมอ* (selector/
declaration จริงไม่มีทางมีตัวนี้อยู่) → `cssStrayCommentCloses()` + assert = 0 ทั้ง `tokens.css` และ `style.css`
พร้อม fixture 3 ตัว (รูปแบบบั๊กจริง / คอมเมนต์ปกติ / ตัวปิดที่อยู่ใน string ต้องไม่ถูกจับ)
