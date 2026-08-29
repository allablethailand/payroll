-- 2026-08-29: adds 'leave_pending' to master_payroll_source_events, the same global reference
-- table 'late'/'absent'/'unpaid_leave'/etc. already live in -- used both by
-- AttendanceDeductionRuleModel (Payroll Configuration > "Attendance Deduction" tab, lets a company
-- configure how much to deduct per unit) and by PayrollEarningDeductionTypeModel's "Linked
-- Attendance Event" dropdown (lets a company map their own custom deduction type to this event).
--
-- Explicit bug report: "Leave Approved ต้องไม่นำมาบวกเป็นเงินได้ ลาไม่รับเงิน และลารออนุมัติ
-- ถึงจะเอามาคำนวณเป็นเงินหัก" -- leave still awaiting approval (Origami item_code LEAVE_PENDING) must
-- be provisionally deducted like unpaid leave until it's actually approved, not silently ignored
-- (or worse, added as income -- see the accompanying SyncPayResolver.php fix for that half of the
-- report). See app/services/SyncPayResolver.php's RULE_DRIVEN_ITEM_DEFS['leave_pending'].
INSERT INTO `master_payroll_source_events` (`code`, `name_th`, `name_en`, `applies_to`, `is_active`, `sort_order`, `created_at`)
SELECT 'leave_pending', 'ลารออนุมัติ', 'Leave pending approval', 'deduction', 1, 45, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `master_payroll_source_events` WHERE `code` = 'leave_pending');

-- `attendance_deduction_rules.event_code` is a hard DB-level enum, separate from
-- AttendanceDeductionRuleModel::EVENT_CODES (a PHP-side allow-list) -- widening the PHP list alone
-- is not enough. Real bug caught while testing this exact change: this dev DB's sql_mode does NOT
-- include STRICT_TRANS_TABLES, so inserting event_code='leave_pending' against the OLD enum
-- silently succeeded with the value coerced to '' instead of raising an error -- the row looked
-- like it saved fine, but AttendanceDeductionRuleModel::ruleGetAll()/SyncPayResolver's own
-- attendanceDeductionRuleFor() would never find it again (WHERE event_code = 'leave_pending' matches
-- nothing), so a company's configured rule would silently never take effect. Confirmed via
-- `SELECT @@sql_mode` on this DB before writing this, not guessed.
ALTER TABLE `attendance_deduction_rules`
  MODIFY COLUMN `event_code` enum('late','absent','unpaid_leave','leave_pending') COLLATE utf8mb4_unicode_ci NOT NULL;
