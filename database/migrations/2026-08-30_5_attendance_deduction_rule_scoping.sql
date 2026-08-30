-- 2026-08-30, explicit request: "ในกรณีที่มีการคำนวณประเภทเดียวกันแต่หลายทีม ให้เพิ่มปุ่ม Clone ขึ้นมาและใส่
-- รายละเอียดเพิ่มเข้าไปในส่วนของรายการด้วยมาใช้กับอะไร" -- confirmed via AskUserQuestion: full schema
-- redesign, not a UI-only add. ONE event_code can now have multiple `attendance_deduction_rules`
-- rows, each optionally scoped to a single team or department (NULL scope_type = the company-wide
-- default, applies to anyone not covered by a more specific scoped row). Resolution priority
-- (most-specific-wins): team > department > company-wide default -- same precedent as Holiday's own
-- employee>position>department>shift resolver (SetupRulesModel::resolveHolidaysForEmployee()).
--
-- `label` is the "ใช้กับอะไร" (what this is used for) free-text note requested alongside Clone --
-- shown in the settings list so an admin can tell apart multiple rows for the same event_code at a
-- glance (e.g. "Warehouse team - late policy"). Nullable/optional, purely descriptive, never read by
-- the calculation engine.
--
-- No unique constraint is added for the (comp_id, event_code, scope_type, scope_id) combination --
-- same "app-layer re-check required" reasoning CLAUDE.md documents for every deleted_at-adjacent
-- unique key in this project: scope_id is polymorphic (team OR department, validated against 2
-- different tables) and scope_type/scope_id are both NULL for the company-wide default row, so a
-- plain composite unique key can't express "at most one NULL-scope row per (comp_id, event_code)"
-- MySQL-portably anyway (NULL is never equal to NULL in a unique key). AttendanceDeductionRuleModel::
-- ruleSave() enforces this itself before insert.
ALTER TABLE `attendance_deduction_rules`
  ADD COLUMN `scope_type` ENUM('team','department') NULL DEFAULT NULL AFTER `event_code`,
  ADD COLUMN `scope_id` INT NULL DEFAULT NULL AFTER `scope_type`,
  ADD COLUMN `label` VARCHAR(150) NULL DEFAULT NULL AFTER `scope_id`,
  DROP INDEX `uq_attendance_deduction_rules_comp_event`,
  ADD INDEX `idx_attendance_deduction_rules_comp_event` (`comp_id`, `event_code`),
  ADD INDEX `idx_attendance_deduction_rules_scope` (`scope_type`, `scope_id`);
