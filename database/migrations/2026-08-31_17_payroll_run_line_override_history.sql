-- 2026-08-31, same-day follow-up (item 10): append-only history of every line/statutory/attendance
-- override made on a payroll run, so the new "Payroll Run Audit" report can show a real
-- Original -> Edit 1 -> Edit 2 -> ... -> Current diff chain per employee/item.
--
-- IMPORTANT (communicated to the user): this can only capture edits made FROM THE DAY THIS SHIPS
-- FORWARD. payroll_run_line_overrides/payroll_run_sync_item_overrides only ever stored the CURRENT
-- value per (run_id, employee_id, item_code) -- a second edit overwrote the row, so there is no
-- structured before/after data for anything edited before this table existed. The report built on
-- top of this must show "no edit history available (feature started 2026-08-31)" for such runs,
-- never a misleading blank/zero-edits state.
CREATE TABLE `payroll_run_line_override_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `line_type` enum('earning_deduction','statutory','attendance') COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'earning/deduction/statutory item_code, or one of PayrollRunModel::ATTENDANCE_OVERRIDE_FIELDS for line_type=attendance',
  `action` enum('override','exclude','restore') COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_value` decimal(14,2) DEFAULT NULL,
  `new_value` decimal(14,2) DEFAULT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prloh_run_employee_item` (`run_id`,`employee_id`,`item_code`),
  CONSTRAINT `fk_prloh_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
