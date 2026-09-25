# tiny-2 — ตัด `audit_log` ออกจาก `api/payroll-run.get` (รอบ B)

วางแผน/สืบ (รอบ A) และแก้จริง (รอบ B) วันที่ 2026-09-24, สรุปเอกสารและรัน `run_all.php --compare` วันที่
2026-09-25 (ข้ามเที่ยงคืนกลางรอบ) — โค้ด/คอมเมนต์ในไฟล์ยังคงระบุ "2026-09-24, tiny round B" ตรงกับตอนที่
งานเริ่ม/วางแผนจริง ไม่ใช่ความผิดพลาด

## ผล SELECT จริงที่ผู้ใช้รันหลังรอบ A (ข้อเท็จจริง, ใช้ตัดสินใจว่า "ตัดได้เลย")

เทียบคอลัมน์ `submitted_at/approved_at/paid_at/locked_at` กับ `MAX(performed_at)` ของ audit action
ที่ตรงกัน (`submit/approve/markPaid/lock`) ทุก run ในฐาน dev:

- submit ตรงกัน 4/4 · approve ตรงกัน 4/4 · markPaid ตรงกัน 3/3 · lock ตรงกัน 3/3
- **differ = 0 ทุกขั้น** ทุก run ที่ตรวจ, ค่าตรงกันระดับวินาที (เขตเวลาเดียวกัน)
- `col_has_audit_null` = 0 ทุกขั้น (ไม่มี run ที่คอลัมน์มีค่าแต่ audit ไม่มีแถวคู่กัน)
- `col_null_audit_has` = 1 ที่ markPaid และ lock **เฉพาะ run 752 เท่านั้น** — run 752 เคยจ่ายแล้ว+ปิดรอบ
  จริง (2026-08-29) แล้วถูก reopen 3 ครั้ง ปัจจุบันเป็น draft: คอลัมน์ `paid_at`/`locked_at` ถูก
  `reopen()` เคลียร์เป็น NULL ไปแล้ว (ตามดีไซน์) แต่ audit trail ยังมีแถว `markPaid`/`lock` เก่าค้างอยู่
  ตามธรรมชาติ (audit log ไม่เคยถูกลบ) — **นี่คือพฤติกรรมที่ตั้งใจ ไม่ใช่บั๊ก**
- ชื่อ action ในฐานตรงกับที่โค้ดอ่าน: `submit`, `approve`, `markPaid`, `lock`, `revert`, `reopen`, `cancel`

**เหตุการณ์ fixture run 752, 2026-09-24 ~23:15**: ผู้ใช้กดส่งอนุมัติ+อนุมัติ 752 เองจริงในเบราว์เซอร์
(ทดสอบ manual) แล้วกด revert ย้อนกลับ 2 ครั้งจนสถานะกลับเป็น draft — ยืนยันด้วย SELECT ตรงว่า 4
คอลัมน์วันที่เป็น NULL หมด ณ ตอนนั้น → audit log ของ 752 มีแถวใหม่เพิ่มขึ้น (`submit`, `approve`,
`revert` ×2) ต่อจากประวัติเก่า (`markPaid`/`lock` จาก 8/29 + `reopen` ×3)

**สรุป**: ข้อมูลจริงยืนยันสมมติฐานของรอบ A 100% — คอลัมน์วันที่กับ audit_log ล่าสุด sync กันเสมอในทุก
run รวมถึง run ที่เคย revert/reopen จริง มีข้อยกเว้นเดียวคือ run 752 ซึ่งเป็นกรณี "reopen แล้วคอลัมน์ถูก
เคลียร์แต่ audit เก่ายังอยู่" ตามดีไซน์ที่ตั้งใจอยู่แล้ว ไม่ใช่จุดที่ต้องแก้เพิ่ม → ตัด `audit_log` ออกจาก
`.get()` ได้โดยไม่มีความเสี่ยงข้อมูลไม่ตรงกัน

## ข้อ 1(ก)–(จ): ตรวจก่อนแก้

**(ก) `markPaid()`/`lock()` (`PayrollRunModel.php:8561,8680` ก่อนแก้)** — อ่านเต็มฟังก์ชันทั้งคู่แล้ว:
ทั้งสอง UPDATE คอลัมน์ (`paid_at`/`locked_at`) กับเรียก `logAudit()` (action `markPaid`/`lock`) อยู่ใน
transaction เดียวกัน ติดกัน ไม่มี early return คั่นกลาง ไม่มีเส้นทางที่ set คอลัมน์โดยไม่ log หรือ log โดย
ไม่ set — [ยืนยันจากโค้ด]

**(ข) `runLifecycleSteps()`/การ render วันที่ใต้ stepper** — `runLifecycleSteps()` (app.js) คำนวณ
`step.date` ให้ **ทุกขั้นใน `RUN_LIFECYCLE_STEPS`** เมื่อ `showDates:true` (ไม่กรองตาม done/current) แต่
ผู้ใช้จริงเห็นวันที่แค่บางขั้น เพราะตัว **render** (`renderProcessTimeline()`, `payroll/detail.js:389`)
กรองอีกชั้น: `const showDate = (step.cls === 'done' || step.cls === 'current') && step.date;` — **แสดง
เฉพาะขั้นที่เป็น `done` หรือ `current` เท่านั้น** ไม่ใช่ทุกขั้นที่คำนวณได้ [ยืนยันจากโค้ด]

**(ค) แก้เพิ่ม (หลัง commit ครั้งแรก) — ไล่ทุกเส้นทาง revert/reopen/cancel หา done/current step ที่
คอลัมน์เป็น NULL แต่ audit ยังมี action ของขั้นนั้นค้างอยู่**, cross-check กับโค้ดจริงทีละบรรทัด:

- `computeRunLifecycleProgress(run)` (`app.js:4241-4267`) — สำหรับ state ที่ไม่ใช่ rejected/need_info/
  cancelled (รวม `draft`/`pending_approval`/`approved`/`paid`/`locked`) ตกไปที่ branch ทั่วไป
  `idx = RUN_LIFECYCLE_STEPS.findIndex(s => s.key === state); return {reachedIdx: idx, branch: null};`
  (`app.js:4265-4266`)
- `runLifecycleSteps()` (`app.js:4282-...`): `currentIndex = reachedIdx + 1` (`app.js:4285`) —
  step ที่ `i === currentIndex` ได้ `cls = 'current'` (`app.js:4299-4300`), step ที่ `i <= reachedIdx`
  ได้ `cls = 'done'` (`app.js:4295-4298`)
- คอลัมน์ถูกเคลียร์เป็น NULL แค่ 4 จุดในทั้งไฟล์ (grep `= NULL` ยืนยันครบ ไม่มีจุดอื่น):
  `PayrollRunModel.php:8189` (`revert()`, fromState=`approved` เคลียร์ `approved_at`),
  `PayrollRunModel.php:8204` (`revert()`, fromState=`pending_approval` เคลียร์ `submitted_at`),
  `PayrollRunModel.php:8807-8810` (`reopen()`, fromState=`paid`/`locked` เคลียร์ `paid_at`/
  `locked_at`/`approved_at`/`submitted_at` ทั้ง 4 คอลัมน์พร้อมกัน) — `cancel()`
  (`PayrollRunModel.php:8514-8543`) **ไม่เคลียร์คอลัมน์วันที่ใดเลย** (set แค่ `cancelled_*`/`state`)
  จึงไม่มีกรณีจาก path นี้

**พบ 2 กรณีจริงที่ done/current step มีคอลัมน์ NULL แต่ audit log ยังมี action ของขั้นนั้นค้างอยู่**
(ทั้งคู่เกิดจาก "เคย submit/approve มาก่อนแล้วถูก revert/reopen ถอยกลับ ยังไม่ผ่านขั้นนั้นซ้ำ" ไม่ใช่
เฉพาะ run 752 — เป็น pattern ทั่วไปของ **run ใดก็ตาม** ที่อยู่ในสถานะนี้):

1. **state=`draft` หลัง `revert(fromState='pending_approval')` (`PayrollRunModel.php:8194-8204`) หรือ
   `reopen()` (`:8806-8814`)** — `computeRunLifecycleProgress()` ให้ `reachedIdx=0`, `currentIndex=1`
   → step index 1 (`pending_approval`) ได้ `cls='current'` — คอลัมน์ `submitted_at` ถูกเคลียร์เป็น NULL
   แต่ audit_log อาจยังมีแถว `submit` เก่าค้างอยู่ (ไม่เคยถูกลบ) — **run 752 เป็นตัวอย่างจริงของกรณีนี้**
   (reopen 3 ครั้ง + fixture revert 2 ครั้งล่าสุด)
2. **state=`pending_approval` หลัง `revert(fromState='approved', toState='pending_approval')`
   (ค่า default ของ `revert()`, `PayrollRunModel.php:8145,8188-8189`)** — `computeRunLifecycleProgress()`
   ให้ `reachedIdx=1`, `currentIndex=2` → step index 2 (`approved`) ได้ `cls='current'` — คอลัมน์
   `approved_at` ถูกเคลียร์เป็น NULL แต่ audit_log อาจยังมีแถว `approve` เก่าค้างอยู่ — **ยังไม่พบตัวอย่าง
   จริงในฐาน dev ที่ตรวจแล้ว (ไม่ใช่ run 752) แต่เข้าเงื่อนไขโค้ดเดียวกันทุกประการ [อนุมานจากโค้ด, ยัง
   ไม่ได้หา run ตัวอย่างจริงในฐาน — ถ้าต้องการยืนยัน ให้รัน SELECT หา run ที่ `n_approve>0 AND
   state='pending_approval' AND approved_at IS NULL`]**

ทั้ง 2 กรณีเป็น**หน้าต่างชั่วคราว** — พอ run ถูก submit/approve ซ้ำ (`submit()`/`approve()` ตั้งคอลัมน์
สดใหม่ในจังหวะเดียวกับ log action เสมอ) คอลัมน์กับ audit ก็ sync กันใหม่ทันที ไม่มีกรณีอื่นนอกจาก 2
กรณีนี้ (ตรวจครบทุกจุดที่เคลียร์คอลัมน์ในไฟล์แล้ว, `cancel()`/`markPaid()`/`lock()` ไม่มีเส้นทางแบบนี้
เพราะไม่เคลียร์คอลัมน์เลย และ `revert()`/`reopen()` ไม่มีเส้นทางไหนลงเอยที่ state=`approved`/`paid`/
`locked` พร้อมคอลัมน์ของ done/current step ตัวอื่นเป็น NULL)

**→ พฤติกรรมที่ผู้ใช้เห็นต่าง**: run ที่อยู่ในสถานะ `draft` หลังเคย submit มาก่อน (revert หรือ reopen)
หรือ `pending_approval` หลังเคย approve มาก่อน (revert) จะไม่แสดงวันที่ "ค้าง" จากรอบก่อนหน้าบนขั้น
current ของ stepper อีกต่อไปหลังรอบนี้ — เป็นบั๊กเดิมที่ก้อนนี้แก้ไปในตัว (กรณีที่ 1 ยืนยันได้จาก run 752
จริง, กรณีที่ 2 เข้าเงื่อนไขโค้ดเดียวกันแต่ยังไม่มีตัวอย่าง run จริงในฐาน dev ที่ตรวจแล้ว) ไม่ใช่การ
เปลี่ยนพฤติกรรมที่ตั้งใจแยกต่างหาก [ยืนยันจากโค้ด สำหรับกลไก — [อนุมาน]/[ต้องตรวจเพิ่ม] สำหรับกรณีที่ 2
ยังไม่มี run จริงยืนยัน — ยังไม่ได้ยืนยันด้วยตาจริงในเบราว์เซอร์ตามกติกาห้ามรัน UI/ห้ามแตะ 752 รอบนี้
**ต้อง smoke test ยืนยันจริงตามรายการท้ายรายงาน**]

**(ง) grep `tests/ui/` หาคำ `runLifecycle`/`step-date`/`stepper`/selector วันที่ใต้ขั้น** — grep ตรงคำ
`runLifecycleSteps`/`runLifecycleStepDate`/`runLifecycleCancelledFromState`/`runProcessTimeline`/
`status-stepper`/`statusStepper` ทั่ว `tests/ui/` **ไม่พบไฟล์ไหนเรียก/assert ค่าเหล่านี้โดยตรง** — จุด
เดียวที่ match คำ `stepper` คือ `tests/ui/m3e2b_slip_sync_list.js` ซึ่งเป็นเรื่อง**ระยะห่าง CSS ของ
wrapper** ไม่เกี่ยวกับวันที่ [ยืนยันจากโค้ด] → ไม่มี UI test ต้องปรับค่าคาดจากข้อ (ค)

**(จ) `PayrollRunModel.php:302` `cancelled_from_state`** — เป็น correlated subquery ตรงใน SQL ของ
`.get()` เอง (`(SELECT from_state FROM payroll_run_audit_logs WHERE run_id=r.id AND action='cancel'
ORDER BY id DESC LIMIT 1) AS cancelled_from_state`) **ไม่พึ่ง `getAuditLog()`/`audit_log` key ของ
`.get()`'s response เลย** — เป็นคนละกลไกกันโดยสิ้นเชิง [ยืนยันจากโค้ด] → ไม่ติดเงื่อนไขหยุด

## การแก้ (3 ท่อน commit)

### ท่อน 1 — backend
- `PayrollController::get()` (`app/controllers/PayrollController.php`): ลบ 2 บรรทัดที่เติม+mask
  `audit_log` (เดิม `:481,:494`)
- `PayrollRunModel::getAuditLog()` **คงไว้** — grep ทั้ง repo พบ consumer PHP โดยตรงนอก controller
  (`tests/payroll_run_test.php` ~10 จุด, `tests/manual_line_update_test.php:166`) เรียก method นี้
  ตรงๆ ไม่ผ่าน `.get()` — ลบไม่ได้
- `maskAuditNote()` คงไว้ (ยังใช้กับ `auditLogList()`)
- แก้คอมเมนต์ที่อ้างว่า `.get()` ยังมี `audit_log`/เรียก `getAuditLog()` ให้ตรงสภาพใหม่ (docblock ของ
  `auditLogList()` ใน controller, docblock ของ `getAuditLogPaged()` ใน model)
- `tests/audit_note_masking_test.php:78-81` (เดิม): 2 check ที่เช็ค "มี" `maskAuditNote($row['audit_log']...)`
  ใน source ของ `.get()` → เปลี่ยนเป็น **2 assert เชิงลบ** ที่ scope เฉพาะ source ของ `get()` เอง (bound
  ด้วยการหา `\n    }\n` หลัง signature แทน `methodBodySrc()` เดิม — helper เดิมกวาดเอา docblock ของ
  method ถัดไปติดมาด้วย ทำให้ false-positive เจอ `audit_log` จาก docblock ของ `auditLogList()` เอง):
  (1) source ของ `get()` ไม่มีคำว่า `audit_log`, (2) ไม่มีการเรียก `getAuditLog(` — จำนวน check รวมของ
  ไฟล์เท่าเดิม (32 ก่อน/หลัง) — รัน `php tests/audit_note_masking_test.php` ตรง: **32/32 PASS**
- `php -l` ผ่านทุกไฟล์ที่แตะ

### ท่อน 2 — frontend lifecycle (`public/js/app.js`, shared)
- `runLifecycleCancelledFromState(run)` → เหลือ `return run.cancelled_from_state || 'draft';` (ทาง
  หลัก+fallback เดิมตัวสุดท้ายรวมเป็นบรรทัดเดียว, byte-identical กับ fallback เดิม)
- `runLifecycleStepDate(run, step)` → เหลือ `return run[step.dateField] || null;` — ตัด special-case
  `step.key==='draft'` ออกด้วย (redundant: `dateField` ของ draft คือ `created_at` อยู่แล้ว ให้ผลเดียวกัน
  ทุกกรณี) — ตัด `RUN_LIFECYCLE_AUDIT_ACTIONS` ทิ้ง (grep แล้วเหลือ consumer 0 จุดหลังแก้)
- แก้คอมเมนต์ที่อ้าง `run.audit_log` ใน app.js (2 จุด), `payroll/detail.js` (2 จุด: บรรทัด ~1746 เดิม +
  บรรทัด ~4020 เดิม), `payroll/index.js` (1 จุด: บรรทัด ~172 เดิม) ให้ตรงสภาพใหม่ — ไม่แก้โค้ดอื่นในสอง
  ไฟล์นั้น
- `payroll/index.js` mini-timeline (`showDates:false`) ได้ผลเหมือนเดิมทุกกรณี — หลักฐาน: (1)
  `runLifecycleStepDate()` ไม่ถูกเรียกเลยเมื่อ `showDates:false` (`runLifecycleSteps()`'s own
  `const date = showDates ? runLifecycleStepDate(...) : null;`) จึงไม่กระทบ index.js เลย (2)
  `runLifecycleCancelledFromState()` ถูกเรียกทุกกรณี (ไม่ผูกกับ showDates) แต่ list() rows ไม่เคยมี
  `audit_log` เต็มมาตั้งแต่ต้น (คอมเมนต์เดิมของไฟล์เองยืนยัน) → โค้ดเดิมตกไปที่ fallback
  `run.cancelled_from_state || 'draft'` เสมอสำหรับ list rows อยู่แล้ว → เท่ากับ behavior ใหม่ทุก
  ประการ [ยืนยันจากโค้ด]
- `node --check public/js/app.js` ผ่าน
- เพิ่ม `tests/run_lifecycle_date_test.js` (pure function, extract ด้วยวิธี brace-matching แบบเดียวกับ
  `tests/tcf_format_value_test.js`, ไม่ต้อง refactor app.js) ครอบคลุม: มีคอลัมน์ · คอลัมน์ NULL/missing/
  empty-string · run ที่มี `audit_log` ค้างในอ็อบเจกต์ (ต้องไม่ถูกอ่านแม้จะมีแถวตรงเงื่อนไข) ·
  `cancelled_from_state` มี/ไม่มี/null — **12/12 PASS**

### ท่อน 3 — UI test (เขียนอย่างเดียว ไม่รัน) + CLI + เอกสาร
- **`tests/ui/audit_log_ref_cli.php` (ใหม่)** — pattern เดียวกับ `id_codec_cli.php`: CLI-only, loopback
  guard, read-only (ไม่มี DB write) รับ `run_id` คืน JSON `{run_id,count,rows}` — query byte-for-byte
  เดียวกับ `PayrollRunModel::getAuditLog()` (`action != 'view_detail'`, `ORDER BY a.id ASC`, join ชื่อ
  ผู้ทำ) ตัด comp-scope guard ออก (CLI dev-only) — `note` เป็นค่าดิบ ไม่ผ่าน `maskAuditNote()`
- **ตารางค่าอ้างอิงใหม่ต่อ call site** (`tests/ui/o_history_dt.js`, แทนที่ `lastAuditLog(payloads)`
  ทั้ง 11 จุด ด้วย `auditLogRef(runId)` ที่ shell ไปเรียก CLI ข้างบน — ไม่ใช่ tautology เพราะอ่านจาก DB
  ตรง ไม่ผ่าน endpoint ที่กำลังทดสอบเลย):

  | call site | run | เหตุผลที่ไม่เป็น tautology |
  |---|---|---|
  | c1 | fixture (`fixtureId`) | นับจำนวนแถวจริงใน DB ตรง เทียบกับ `recordsTotal` ที่ `audit-log.list` คืนมา — คนละ source |
  | c2 | 1014 | ค่า action ที่ต้องกรองมาจาก DB reference เอง ไม่ใช่จากผลการกรองของ endpoint |
  | c3 | 1014 | เช่นเดียวกับ c2, ทั้ง 2 filter ร่วมกันคำนวณจาก DB reference |
  | c7 (ctx1) | fixture | reference null-ip/ua นับจาก DB ตรง เทียบกับสิ่งที่ table render |
  | c7 (ctx2) | 752 | distinct ip มาจาก DB reference, ไม่ใช่จาก dropdown ของ endpoint เอง |
  | c8 | 752 | `recordsTotal`/sort-order เทียบกับจำนวนแถว+ค่า `performed_at` สูงสุดจาก DB ตรง |
  | c9 | 1014 | narrow-count มาจาก DB reference ก่อน แล้วค่อยเทียบกับผลจริงของ endpoint |
  | c12 | 1014 | นับแถวต่อวันจาก DB reference เอง ไม่ใช่จากผล date-range query |
  | c14 | 1014 | full/narrowed/to-only count ทุกค่ามาจาก DB reference คำนวณเอง |
  | c15 | 752 | ใช้แค่ยืนยันว่ามีแถวจริง (`.length`) ไม่ผูกกับ endpoint |
  | N6 | 1014 | day/ip ที่ใช้ตั้ง filter มาจาก DB reference |

  ลบป้าย `TINY-2` ที่เป็น label/tag บน check/comment ที่ผูกกับ reference เดิมออกทั้งหมด (เหลือ 1 จุดที่
  เป็น prose อธิบายประวัติในคอมเมนต์ท้ายไฟล์ตอนอธิบาย `auditLogRef()` เอง ซึ่งยังถูกต้องตามบริบท)
- **`tests/ui/m3e1_tabs_shared.js` cell4 (`:297` เดิม)**: เปลี่ยนจากอ่าน `runPayload.data.audit_log`
  (จาก captured `.get()` response) เป็นเรียก `audit_log_ref_cli.php 1015` (shell ตรง, run 1015 คือ run
  ที่ cell นี้เปิดอยู่แล้ว — `LOCKED_RUN_TOKEN`) **เพิ่ม 1 check ใหม่**: response ของ `.get()` ไม่มี key
  `audit_log` เลย (`!Object.prototype.hasOwnProperty.call(runPayload.data||{}, 'audit_log')`)
- `node --check` ทุกไฟล์ JS ที่แตะ ผ่านหมด, `php -l tests/ui/audit_log_ref_cli.php` ผ่าน
- **จำนวน check ที่คาดต่อไฟล์หลังแก้**:
  - `o_history_dt.js`: **ไม่เปลี่ยน** จากก่อนรอบนี้ (เดิม 181 ตามที่ผู้ใช้ระบุ) — grep ยืนยันว่าไม่มีการ
    เพิ่ม/ลบ `check(...)` call site เลยตลอดการแก้ 11 จุด มีแต่เปลี่ยนแหล่งอ้างอิง+ข้อความ label (grep
    `\bcheck\('` แบบ static นับได้ 165 แต่ไม่ตรงกับ 181 ของรอบ A เพราะ 181 เป็นค่าที่วัดจากการรันจริง
    ซึ่งรวม check ที่เกิดจาก loop — [ต้องตรวจเพิ่ม] ตัวเลขจริงต้องยืนยันตอนรันจริงเท่านั้น แต่ **0 การ
    เปลี่ยนแปลงสุทธิของจำนวน call site ยืนยันได้จาก diff**)
  - `m3e1_tabs_shared.js`: **+1** จาก 240 (ตามที่ผู้ใช้ระบุ) → **241** — มาจาก check "no audit_log key"
    ใหม่ 1 จุดใน cell4 เท่านั้น (ไม่ loop, รันครั้งเดียวแน่นอน) ไม่มีจุดอื่นถูกลบ

## ขนาด response ของ `.get()` ที่ลดลง (ประมาณการ ไม่ได้วัดจริงผ่าน HTTP ตามกติกา)

Run 752 (audit log 1571 แถว ตามที่รอบ A นับไว้) เป็น worst case ที่ dev DB มีอยู่ — แต่ละแถวมี
`user_agent` (varchar(255), มักเต็มความยาว), `note`, `ip_address`, ชื่อ th/en ที่ join มา ฯลฯ
คูณคร่าวๆ ที่ ~400-450 ไบต์/แถว (JSON key ยาว + user_agent เต็ม) × 1571 แถว ≈ **600-700 KB** ที่หายไป
จาก payload ของ `.get()` สำหรับ run นี้โดยเฉพาะ — รันทั่วไปที่มี audit log ไม่กี่สิบแถว (เช่น 1014/1015)
ลดลงในหลัก **ไม่กี่ถึงสิบกว่า KB** เท่านั้น

## Smoke test ที่ผู้ใช้ต้องทำก่อนรอบวัด (ห้ามใช้ run 752)

ดูรายการท้าย `tmp-tiny2-roundB.md` — ครอบคลุม run 1014/1015 (stepper วันที่ตรงเดิม), run cancelled
1018 (stepper แสดงขั้นที่ถูกยกเลิกถูกต้อง), แท็บประวัติยังทำงาน, DevTools ยืนยัน response ของ `.get()`
ไม่มี `audit_log`, light/dark, th/en — **และเพิ่มเติมสำหรับข้อ (ค) ด้านบน (2 กรณี)**: (1) ถ้ามี run ที่
เคย submit แล้ว revert กลับ draft หรือถูก reopen (หรือทดสอบ manual ใหม่) ให้เช็คว่า stepper ไม่แสดง
วันที่ submit ค้างบนขั้น pending_approval (current) อีกต่อไป (2) ถ้ามี run ที่เคย approve แล้ว revert
กลับ pending_approval (หรือทดสอบ manual ใหม่) ให้เช็คว่า stepper ไม่แสดงวันที่ approve ค้างบนขั้น
approved (current) อีกต่อไป — ทั้งสองกรณีขั้นที่ยังไม่เกิดขึ้นจริงต้องไม่มีวันที่แสดง

## รอบวัด (tiny-2 รอบ C, 2026-09-25) — ฉบับเต็มดู `tmp-tiny2-roundC.md`

**Smoke test ของผู้ใช้ (ก่อนรอบวัด, ตามที่ยืนยันมา): 5/5 ผ่านทั้งหมด** — run 1014/1015 stepper แสดง
08/09/2026 ครบ 5 ขั้น, run 1018 แสดง Cancelled ที่ขั้นแรกถูกต้องตาม `from_state=draft`, แท็บประวัติ
กรอง/เรียงได้ th/en light/dark, response ของ `payroll-run.get` ไม่มี `audit_log` แล้ว

**ตารางค่าจริงทุก script (session ปกติ + session `--with-sync`)**:

| script | ค่าคาด | ค่าจริง |
|---|---|---|
| m3e2a | 147/1 | 147/1 (fail เดิมตรงตัว: `p10c`) |
| m3e2b | 315 | 315/0 |
| slip2a | 68 | 68/0 |
| k4a1 | 65 | 65/0 |
| k4a2 | 300/8 | 300/8 (fail เดิมตรงตัว ×8: inset ±1px) |
| k4a2b | 216 | 216/0 |
| k4c_employee_detail | 59 | 59/0 |
| q_eed_row_actions | 59 | 59/0 |
| r_table_dropdown_fixed | 36 | 36/0 |
| l6a | 39 | 39/0 |
| l6b | 117 | 117/0 |
| tinyc | 17 | 17/0 |
| h_history_table | 539 | 539/0 |
| o_history_dt | 181 | รอบ 1: 180/1 (**fail ใหม่ `c9`, ดูล่าง**) · รอบ 2-4: 181/0 เขียว 3 ติด |
| s_run_detail_c3 | 29 | 29/0 |
| m3e1 ปกติ | 240→**241** | รอบ 1-3: 241/0 เขียว 3 ติดตั้งแต่รอบแรก |
| n_missing_pull (sync) | 61 | 61/0 |
| p_sync_not_participant (sync) | 53 | 53/0 |
| m3e1 `--with-sync` | 245→**246** | 246/0 |

**ผลต่อ cell**: `o_history_dt.js` (c1-c17+N1-N6, 181 checks รวม, นับจาก log รอบเขียวรอบ 2) และ
`m3e1_tabs_shared.js` (c1-c15, 241 checks รวม, นับจาก log รอบ 1) — breakdown เต็มต่อ cell อยู่ใน
`tmp-tiny2-roundC.md` (ตารางไม่ซ้ำที่นี่ตามกฎห้ามใส่ตัวเลขที่วัดได้ซ้ำสองที่) — สรุปสั้น: ไม่มี cell ไหน
ที่จำนวน check เปลี่ยนจากที่คาด นอกจาก `m3e1`'s c4 ที่ +1 check ตามที่ตั้งใจ (ยืนยัน `.get()` ไม่มี
`audit_log`)

**หมายเหตุ `run_all.php --compare`**: ผลจากรอบ B คือ **7,866/2** (baseline เดิม 7,854/2 + ไฟล์ใหม่
`tests/run_lifecycle_date_test.js` 12/0) — **ผู้ใช้ต้องเก็บ `baseline-audit-fe.json` ใหม่หลัง commit
รอบนี้** (จำนวนรวมเปลี่ยนจากไฟล์เทสใหม่ ไม่ใช่ regression)

**`tests/ui/o_history_dt.js`'s `c9` — fail ใหม่ที่ยังไม่รู้สาเหตุ (ไม่ใช่ fail เดิม)**: รอบ 1 ของลำดับเต็ม
16 script วัดได้ `audit-log.list` request 4 ครั้งระหว่างสลับภาษา th→en (`c9()`, ตรวจว่า stale column
filter ถูกเคลียร์ตอนสลับภาษา) แต่ assertion ล็อกไว้ที่ค่า **3** (`tests/ui/o_history_dt.js:884`,
อ้างอิง BACKLOG.md หัวข้อ "Audit Log serverSide (Round B4): N1=2, c9=3 ล็อกเป็นค่าที่รู้แล้ว") →
`FAIL c9: fetch count equals the known, investigated value (3 -- see BACKLOG.md) -- 4` — **ค่านี้ไม่ใช่
1 ใน 2 fail ที่รู้จักอยู่แล้วของก้อนนี้** (`m3e2a`'s `p10c`, `k4a2`'s inset ±1px ×8) และไม่เคยมีบันทึกที่
ไหนว่า c9 เคยวัดได้ 4 มาก่อน — 3 รอบถัดมา (รอบ 2, 3, 4 ในลำดับรันจริง) วัดได้ 3 ตรงค่าที่ล็อกไว้ทุกรอบ
ไม่เกิดซ้ำ — **รายละเอียดที่มี**: `collectAuditListResponses()` (`o_history_dt.js:276-296`) เก็บ
`{url,request,json,seq}` ต่อ request จริง แต่ `c9()` log ออกมาแค่ `.length` (`:883`) เดี่ยวๆ ไม่มี URL/
seq ของ request ที่ 4 หลงเหลือใน log ที่มี — `blockedPreferenceSaves` เท่ากันทุกรอบ (=3) ตัวที่ต่างมีแค่
จำนวน `audit-log.list` request — **ว่าเกี่ยวกับการตัด `audit_log` ออกจาก `.get()` (เรื่องหลักของก้อนนี้)
หรือไม่ ยังไม่ทราบ** — `c9()` เรียก `auditLogRef(RUN_1014_ID)` ก่อน switch ภาษา (`:843`) แต่เป็นแค่
child-process CLI call แยกจาก browser, ไม่น่ากระทบจำนวน request ที่ browser ยิงจริงตอน switch [อนุมาน,
ไม่ใช่หลักฐานพิสูจน์ขาด] — root cause ของ "ทำไมมี 3 ครั้ง" (3 reload mechanism ทับกัน, `app.js:
4917-4944`) ที่มีอยู่แล้วใน BACKLOG.md ไม่ได้อธิบายว่า "ทำไมบางครั้งมี 4 ครั้ง" — ต้องตรวจเพิ่มถ้าเกิดซ้ำ
(เพิ่ม log รายละเอียด `collector.list` ก่อนสรุปสาเหตุจริง) — ดูรายละเอียดเต็มใน `tmp-tiny2-roundC.md`
