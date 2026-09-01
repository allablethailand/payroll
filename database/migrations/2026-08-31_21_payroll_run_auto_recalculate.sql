-- 2026-08-31, explicit request: "เพิ่ม Function ให้มี checkbox ติ๊กว่าคำนวณอัตโนมัติหลังจากที่แก้ไขข้อมูล
-- ทันที...บันทึกลง DB ผูกกับ Run นั้นๆ" -- per-run toggle (confirmed via AskUserQuestion: persisted
-- per-run, not a personal browser preference, so every user opening this run sees the same setting).
-- When on, PayrollRunModel's own mutation entry points on the Process Detail page (manual lines,
-- line overrides, attendance overrides, exemptions, join/remove employee, run settings) trigger a
-- real recalculate() immediately after they succeed instead of just reloading the details table; the
-- "please recalculate" reminder banner is hidden whenever this is on (see PayrollRunModel's own
-- setAutoRecalculate()/EMPLOYEE VERIFY section-adjacent comment for the read-side wiring).
ALTER TABLE `payroll_runs`
  ADD COLUMN `auto_recalculate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `state`;
