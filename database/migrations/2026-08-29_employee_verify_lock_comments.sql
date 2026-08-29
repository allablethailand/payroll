-- 2026-08-29, explicit request: per-employee Verify/Lock on a draft payroll run's employee
-- breakdown table, plus a per-employee comment timeline with a status tag.
--
-- payroll_run_employee_verifications: one row per (run_id, employee_id), following the same
-- shape/convention as payroll_run_employee_exemptions (the closest existing precedent -- a
-- per-run/per-employee flag table with created_by/created_at/updated_by/updated_at). is_verified
-- and is_locked are deliberately independent (confirmed via AskUserQuestion) -- either can be set
-- without the other. A row only exists while at least one flag is true; both false = the row is
-- deleted (same "clear rather than keep an all-zero row" convention
-- EmployeeExemptionModel/CompanyStatutorySettingsModel already use elsewhere in this app), so
-- "how many verified/locked" is a plain COUNT(*) WHERE is_verified=1 / is_locked=1 with no extra
-- filtering needed.
--
-- Locking (confirmed via AskUserQuestion): PayrollRunModel::recalculate() must preserve a locked
-- employee's existing payroll_run_details row byte-for-byte instead of recomputing it, AND every
-- other per-employee adjustment entry point on a draft run (line overrides, attendance overrides,
-- manual lines, per-run exemptions) must refuse to edit a locked employee's data at all.
CREATE TABLE `payroll_run_employee_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_by` int(11) DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payroll_run_employee_verifications` (`run_id`,`employee_id`),
  KEY `idx_prev_employee` (`employee_id`),
  CONSTRAINT `fk_prev_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prev_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- payroll_run_employee_comments: append-only timeline (no edit/delete UI -- "ใส่ Comment ได้เรื่อยๆ
-- เป็น Timeline เพื่อดูว่าวันไหนทำอะไรไปโดยใคร" -- the point is an unaltered record of who did what,
-- when, same spirit as payroll_run_audit_logs' own append-only shape). `tag` is nullable (a plain
-- note without a status marker is still valid) with 3 fixed values matching the 3 examples given
-- explicitly: "กำลังดำเนินการ"/"ดำเนินการเสร็จแล้ว"/"มีข้อผิดพลาด" -- the specific error description
-- itself lives in the free-text `comment` body, not as a 4th enum value per error type.
CREATE TABLE `payroll_run_employee_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `tag` enum('in_progress','completed','error') DEFAULT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prec_run_employee` (`run_id`,`employee_id`),
  CONSTRAINT `fk_prec_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prec_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
