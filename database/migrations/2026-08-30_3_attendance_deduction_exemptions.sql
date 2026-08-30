-- 2026-08-30, explicit request: "หน้าการตั้งค่า รอบเงินเดือน หักตามข้อมูลเข้างาน อยากให้ปรับให้เป็นตาราง และ
-- เลือกได้ว่าจะหักหรือไม่หัก และตั้งค่าได้ต่อว่า หักหรือไม่หักกับแผนกไหน ทีมไหน หรือเจาะจงรายคน" -- adds a
-- company-wide on/off toggle per attendance-deduction event (is_active) and a scoped EXEMPTION list
-- per rule (department/team/employee), same polymorphic-scope shape as `holiday_assignments`/the
-- newer Payslip/Employment Certificate Template assignment tables -- see
-- AttendanceDeductionRuleModel's own docblock for the exemption-resolution logic this backs.
--
-- is_active: NOT NULL DEFAULT 1 so every existing saved rule (and the virtual "no row yet" default
-- row ruleGetAll() already synthesizes) keeps deducting exactly as before this feature -- opting a
-- whole event OUT entirely is a new, explicit action, not a silent behavior change.
ALTER TABLE `attendance_deduction_rules`
  ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `event_code`;

-- No assignment_mode (include/exclude) unlike holiday_assignments -- this is deliberately
-- EXEMPTION-ONLY: a department/team/employee listed here does NOT get this deduction, regardless of
-- is_active. Nothing asked for the reverse ("force-deduct even though the event is company-wide
-- off"), and adding that direction now would be speculative scope, not a requested feature.
CREATE TABLE `attendance_deduction_rule_exemptions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rule_id` INT NOT NULL,
  `scope_type` ENUM('department','team','employee') NOT NULL,
  `scope_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attendance_deduction_rule_exemptions_rule` (`rule_id`),
  KEY `idx_attendance_deduction_rule_exemptions_scope` (`scope_type`, `scope_id`),
  CONSTRAINT `fk_attendance_deduction_rule_exemptions_rule` FOREIGN KEY (`rule_id`) REFERENCES `attendance_deduction_rules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
