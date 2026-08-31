-- Phase 8, T043: "payroll-configuration หักตามข้อมูลการเข้างาน: เพิ่ม 'กลับก่อนเวลา' ตั้งค่าได้แบบเดียวกับ
-- 'มาสาย'". Widens AttendanceDeductionRuleModel::EVENT_CODES to include 'early_leave' so the settings
-- UI can configure it (SyncPayResolver::attendanceDeductionRuleFor() already queries
-- attendance_deduction_rules generically by event_code, no hardcoded list there -- see that class's
-- own RULE_DRIVEN_ITEM_DEFS['early_leave'] entry, already live since an earlier fix the same day).
--
-- `attendance_deduction_rules.event_code` is a hard DB-level enum, separate from the PHP-side
-- EVENT_CODES allow-list -- widening the PHP list alone is not enough, same exact bug class already
-- documented once in 2026-08-29_leave_pending_source_event.sql: this dev DB's sql_mode does not
-- include STRICT_TRANS_TABLES, so inserting event_code='early_leave' against the OLD enum silently
-- succeeds with the value coerced to '' instead of raising an error -- confirmed by direct
-- reproduction (a real save()+ruleGetAll() round-trip returned event_code='' in the DB row, and
-- ruleGetAll() then couldn't find it, always falling back to the virtual "never configured" default)
-- before writing this migration, not guessed.
ALTER TABLE `attendance_deduction_rules`
  MODIFY COLUMN `event_code` enum('late','early_leave','absent','unpaid_leave','leave_pending') COLLATE utf8mb4_unicode_ci NOT NULL;
