# H-backend — ประวัติต่อบรรทัดรับ 3 แหล่ง (2026-09-19)

`payroll_run_line_override_history` เคยบันทึกเฉพาะ line override — manual line กับคำตอบ tri-state (tax/SSO)
มีแค่ free text ใน `payroll_run_audit_logs` ซึ่ง parse กลับเป็นคู่ from→to ไม่ได้ และ `update_manual_line`
ไม่เคยเก็บยอดก่อนแก้เลย

- **ขยายตารางเดิม ไม่สร้างตารางใหม่** — `source_type` (`override`/`manual_line`/`exemption`, DEFAULT `override`
  จึง backfill 48 แถวเก่าด้วยตัว ALTER เอง), `source_id`, `old_value_text`/`new_value_text` (varchar 20)
  สำหรับค่าที่เป็นคำ (`inherit`/`yes`/`no`) — แถวหนึ่งใช้คู่ decimal หรือคู่ text อย่างใดอย่างหนึ่งเสมอ
- **ไม่มี FK บน `source_id`** — manual line ถูก hard delete: CASCADE จะลบประวัติการลบทิ้งไปพร้อมกัน
  ส่วน RESTRICT จะทำให้ลบไม่ได้เลย
- **กฎ "ไม่บันทึก" ย้ายมาอยู่ที่เดียว** — `historyIsNoOp()`: ค่าเท่าเดิม (tolerance 0.005) หรือคำเท่าเดิม → ไม่เขียน
  และ undo สิ่งที่ไม่เคยมี → ไม่เขียน เดิมกฎนี้มีเฉพาะ writer ฝั่ง attendance เท่านั้น
- **`item_code` ของ manual line = `resolveManualLineRow()['code']`** (รวมรูป `CUSTOM:` / `OTHER_INCOME` /
  `OTHER_DEDUCTION`) ซึ่งเป็น key เดียวกับที่ slip/ตาราง Adjustments ใช้อยู่แล้ว ส่วนตัวระบุแถวจริงคือ
  `source_id` เพราะ manual line 2 แถวของคนเดียวกันใช้ item_code ซ้ำกันได้
- **ของเดิมไม่ขยับ** — `lineOverrideAuditDiff()` และ `runAuditList()`'s `edit_count` เติม
  `source_type='override'` เพื่อให้ shape/ลำดับ/ตัวเลขของ endpoint เดิมเท่าเดิมเป๊ะ (รอบนี้ไม่แตะ UI เลย)
  ของใหม่อยู่ที่ `api/payroll-run.line-history` แยกต่างหาก เรียงใหม่→เก่า
- **migration เลี่ยง `migSplitUpDown()` แทนที่จะแก้ regex** — เหตุผลและขอบเขตอยู่ใน BACKLOG.md

ทดสอบ: `tests/line_override_history_sources_test.php` (82 assertions)
