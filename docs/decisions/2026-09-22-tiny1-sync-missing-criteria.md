# tiny-1 (2026-09-22) — เกณฑ์ "ควรอยู่ในรอบนี้" ของ banner "ไม่พบใน Sync รอบนี้"

- **Gate ตาม `run_purpose`** — `syncMissingEmployeeWhere()` คืน `null` (= ไม่มีรายการ) เมื่อ run ไม่ใช่
  `run_purpose='payroll'` · ของจริงที่เจอ: run 29685 (incentive, ค่าเที่ยว, sync มี 3 รายการ) บอกว่าขาด
  **25 คน** = พนักงานที่รับเงินเดือนทั้งบริษัทที่ไม่ได้มีค่าเที่ยวเดือนนั้น — รอบ incentive ไม่มีนิยามว่าใคร
  "ควร" อยู่ สมาชิกคือรายการที่ถูกส่ง/เลือกมาเท่านั้น
- **คงสาขา `e.cycle_id IS NULL OR e.cycle_id = :cycle_id` ไว้** (รอบเสนอให้ตัดเหลือ equality แล้วถอน) —
  ต้องเป็นชุดเดียวกับที่ `recalculate()` ใช้เลือกสมาชิกจริงใน branch `elseif ($run['cycle_id'] !== null)`
  ซึ่งจงใจไม่ใช้ equality ล้วน ("NULL = ไม่ผูกรอบไหน = มีสิทธิ์ทุกรอบ") · ถ้าเกณฑ์ตรงนี้เข้มกว่า จะซ่อน
  พนักงานกลุ่มที่ `recalculate()` จะจ่ายเงินให้จริง — **สองที่นี้ต้องแก้พร้อมกันเสมอ**
- **noise 22 แถวบน dev เป็นปัญหาข้อมูล ไม่ใช่ปัญหาเกณฑ์** — พนักงาน 22 คนไม่เคยถูกผูก `cycle_id` และบัญชี
  `SSO-D4735E3A26` (auto-provision จาก SSO) กับ `CEO` ยังเป็น `is_payroll_participant=1` · แก้ที่ข้อมูล
  (ตั้ง cycle / ตั้ง participant=0) ไม่เพิ่มเงื่อนไขกันบัญชีระบบในโค้ด → ดู BACKLOG ข้อ 2/3
- **`in_sync_not_participant_count`** (`syncMappedNotParticipantCount()`) = เคสกลับด้านที่ banner เดิม
  มองไม่เห็นเลย: Origami ส่งมาแล้ว mapped แล้ว แต่ไม่เข้ารอบเพราะ `is_payroll_participant=0` (ของจริง:
  emp 661 `EM062` อยู่ใน sync 191/192 ทั้งคู่ ไม่มีแถวใน `payroll_run_details` เลย) · คืนทุกครั้งที่ run มี
  `sync_process_id` **ไม่ผ่าน gate `run_purpose`** เพราะเป็นข้อเท็จจริงของ payload ไม่ใช่ของรอบ · ยังไม่มี
  UI แสดง (BACKLOG ข้อ 1)
- **`joinEmployees()` ข้าม `is_payroll_participant=0`** แล้วคืน `skipped_employee_ids` — เดิม insert แถว
  `payroll_run_manual_employees` ผ่าน แต่ `recalculate()` กรองทิ้งทุกครั้ง = แถวสมาชิกที่ดูเหมือนเข้าแล้วแต่
  ไม่เคยเข้าจริง · ข้ามเฉพาะ id นั้น ไม่ทิ้งทั้งคอล (bulk join 20 คนไม่ควรพังเพราะ 1 คน)
