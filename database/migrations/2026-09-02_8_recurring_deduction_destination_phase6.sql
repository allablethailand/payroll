-- 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6.
-- Employee Detail's recurring deductions (`employee_recurring_deductions`, the indefinite-monthly
-- fee/deduction feature -- see that model's own docblock) get the SAME payee_type/destination_id
-- routing already added to `employee_earning_deductions`/`payroll_run_manual_lines` in the earlier
-- part of this feature. This is the TEMPLATE-level default, inherited by every payroll run.
--
-- A run-scoped override table lets one specific payroll run route a recurring deduction's payee
-- differently for THAT PROCESS ONLY, without touching the template row above (mirrors the existing
-- `payroll_run_line_overrides` per-run override pattern, but keyed by recurring_id instead of
-- item_code since a destination override is a distinct concept from the existing amount/exclude
-- override already covered by that table).

START TRANSACTION;

ALTER TABLE `employee_recurring_deductions`
  ADD COLUMN `payee_type` enum('employee','company','other_person','not_disbursed') COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `notes`,
  ADD COLUMN `payee_employee_id` int(11) DEFAULT NULL AFTER `payee_type`,
  ADD COLUMN `destination_id` int(11) DEFAULT NULL AFTER `payee_employee_id`,
  ADD KEY `idx_erd_payee_employee` (`payee_employee_id`),
  ADD KEY `idx_erd_destination` (`destination_id`),
  ADD CONSTRAINT `fk_erd_payee_employee` FOREIGN KEY (`payee_employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_erd_destination` FOREIGN KEY (`destination_id`) REFERENCES `payment_destinations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

CREATE TABLE `payroll_run_recurring_deduction_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `recurring_id` int(11) NOT NULL COMMENT 'employee_recurring_deductions.id being overridden for this run only -- the template row itself is never touched',
  `payee_type` enum('employee','company','other_person','not_disbursed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payee_employee_id` int(11) DEFAULT NULL,
  `destination_id` int(11) DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prrdo_run_recurring` (`run_id`,`recurring_id`),
  KEY `idx_prrdo_recurring` (`recurring_id`),
  KEY `idx_prrdo_payee_employee` (`payee_employee_id`),
  KEY `idx_prrdo_destination` (`destination_id`),
  CONSTRAINT `fk_prrdo_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prrdo_recurring` FOREIGN KEY (`recurring_id`) REFERENCES `employee_recurring_deductions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prrdo_payee_employee` FOREIGN KEY (`payee_employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_prrdo_destination` FOREIGN KEY (`destination_id`) REFERENCES `payment_destinations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

COMMIT;

-- Rollback:
-- START TRANSACTION;
-- DROP TABLE IF EXISTS `payroll_run_recurring_deduction_overrides`;
-- ALTER TABLE `employee_recurring_deductions`
--   DROP FOREIGN KEY `fk_erd_payee_employee`,
--   DROP FOREIGN KEY `fk_erd_destination`,
--   DROP COLUMN `payee_type`,
--   DROP COLUMN `payee_employee_id`,
--   DROP COLUMN `destination_id`;
-- COMMIT;
