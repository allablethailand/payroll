-- 2026-08-31, explicit request: "ในการทำงวด ให้ตัดปุ่ม Lock ออกไปเลยครับ ให้เหลือแค่ Verify ถ้า Verify แล้ว
-- จะไม่คำนวณอีกต่อไป" -- Verify and Lock used to be two INDEPENDENT flags on
-- payroll_run_employee_verifications (see that table's own 2026-08-29 migration header comment).
-- Lock is retired entirely; Verify itself now carries the "freeze from recalculation, refuse
-- further edits" behavior Lock used to have (see PayrollRunModel::isEmployeeVerifiedForRun(),
-- renamed from isEmployeeLockedForRun(), and recalculate()'s own preserve-byte-for-byte branch,
-- both now keyed on is_verified instead of is_locked).
ALTER TABLE `payroll_run_employee_verifications`
  DROP COLUMN `is_locked`,
  DROP COLUMN `locked_by`,
  DROP COLUMN `locked_at`;
