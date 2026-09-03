-- 2026-09-02, Deduction Destination & Third-Party Remittance.
-- Reconciled against ALREADY-EXISTING infrastructure (checked before writing this, not guessed):
-- `employee_earning_deductions`/`payroll_run_manual_lines` already have `payee_type`
-- ENUM('employee','company','not_disbursed') + `payee_employee_id` + `include_in_cash_summary`
-- (2026-08-31), and PayrollRunModel::recalculate() already auto-credits an employee-to-employee
-- transfer within the same run as a real taxable TRANSFER_IN earning line. This migration EXTENDS
-- that system (adds 'other_person' to the existing enum + a new destination_id FK) rather than
-- replacing it -- payee_type='employee'/'company'/'not_disbursed' behavior is completely
-- unchanged; only 'other_person' is new, and only it ever populates destination_id.
--
-- `payment_destinations` is scoped to ONLY the "saved third-party bank account" case -- NOT a
-- generic type-enum table covering employee/company too (those stay on the existing
-- payee_employee_id/payee_type columns, confirmed via AskUserQuestion 2026-09-02).
--
-- `payroll_scheduled_items` (the "polymorphic source reference" table this feature's original
-- prompt referenced) does NOT exist anywhere in this database -- confirmed by direct query before
-- writing this migration. The real, already-proven "reference a run's own deduction line" pattern
-- in this codebase is a plain (run_id, employee_id, item_code) triple (see
-- `payroll_run_line_overrides`/`payroll_run_line_override_history`), used for
-- `payroll_remittance_items` below instead of inventing a new polymorphic mechanism.

-- 1) Saved third-party bank accounts (comp-scoped, reusable across employees within a company).
CREATE TABLE `payment_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `account_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_no` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'AES-256-GCM encrypted, same convention as employees.bank_account_no',
  `account_no_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_id` int(11) NOT NULL,
  `bank_branch` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_saved` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = appears in the reusable picker; 0 = one-off, kept only as this deduction/line''s own record',
  `key_version` tinyint(3) unsigned DEFAULT NULL,
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payment_destinations_tenant` (`comp_id`,`deleted_at`,`status`),
  KEY `fk_payment_destinations_bank` (`bank_id`),
  CONSTRAINT `fk_payment_destinations_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_destinations_bank` FOREIGN KEY (`bank_id`) REFERENCES `master_banks` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- 2) Widen the EXISTING payee_type enum + add destination_id, on both tables that already carry
--    payee_type/payee_employee_id. Nullable, no backfill needed (matches existing column style).
ALTER TABLE `employee_earning_deductions`
  MODIFY COLUMN `payee_type` enum('employee','company','not_disbursed','other_person') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN `destination_id` int(11) DEFAULT NULL AFTER `payee_employee_id`,
  ADD KEY `fk_eed_destination` (`destination_id`),
  ADD CONSTRAINT `fk_eed_destination` FOREIGN KEY (`destination_id`) REFERENCES `payment_destinations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `payroll_run_manual_lines`
  MODIFY COLUMN `payee_type` enum('employee','company','not_disbursed','other_person') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN `destination_id` int(11) DEFAULT NULL AFTER `payee_employee_id`,
  ADD KEY `fk_pml_destination` (`destination_id`),
  ADD CONSTRAINT `fk_pml_destination` FOREIGN KEY (`destination_id`) REFERENCES `payment_destinations` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- 3) Remittance batches -- one row per (run, destination) group, created when a run is Approved
--    (confirmed trigger point via AskUserQuestion 2026-09-02: gives Finance lead time before Paid).
--    destination_type mirrors the relevant subset of payee_type for money that actually needs a
--    tracked external transfer -- 'employee' is deliberately NOT a destination_type here (that
--    case is paid via the SAME run's own payroll, no external transfer -- see
--    PayrollRunModel::recalculate()'s existing TRANSFER_IN mechanism); 'employee_fallback' is the
--    NEW case where an employee-type deduction's payee isn't part of this run, so it falls back to
--    a real external transfer paid to that employee's own bank details.
CREATE TABLE `payroll_remittances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `destination_type` enum('company','other_person','employee_fallback') COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_id` int(11) DEFAULT NULL COMMENT 'FK payment_destinations, only set when destination_type=other_person',
  `fallback_employee_id` int(11) DEFAULT NULL COMMENT 'FK employees, only set when destination_type=employee_fallback',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','transferred','success','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `evidence_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transferred_at` datetime DEFAULT NULL,
  `transferred_by` int(11) DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_remittances_run` (`run_id`,`status`),
  KEY `fk_remittances_destination` (`destination_id`),
  KEY `fk_remittances_fallback_employee` (`fallback_employee_id`),
  CONSTRAINT `fk_remittances_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_remittances_destination` FOREIGN KEY (`destination_id`) REFERENCES `payment_destinations` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_remittances_fallback_employee` FOREIGN KEY (`fallback_employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- 4) Breakdown -- which employee/deduction line contributed how much to a remittance batch.
--    item_code (not a generic polymorphic source_type/source_id pair) matches the same
--    (run_id, employee_id, item_code) addressing convention `payroll_run_line_overrides` already
--    uses for "a specific deduction line within a specific employee's specific run".
CREATE TABLE `payroll_remittance_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `remittance_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `item_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_remittance_items_remittance` (`remittance_id`),
  KEY `fk_remittance_items_employee` (`employee_id`),
  CONSTRAINT `fk_remittance_items_remittance` FOREIGN KEY (`remittance_id`) REFERENCES `payroll_remittances` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_remittance_items_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Rollback:
-- ALTER TABLE `payroll_run_manual_lines` DROP FOREIGN KEY `fk_pml_destination`, DROP COLUMN `destination_id`, MODIFY COLUMN `payee_type` enum('employee','company','not_disbursed') COLLATE utf8mb4_unicode_ci DEFAULT NULL;
-- ALTER TABLE `employee_earning_deductions` DROP FOREIGN KEY `fk_eed_destination`, DROP COLUMN `destination_id`, MODIFY COLUMN `payee_type` enum('employee','company','not_disbursed') COLLATE utf8mb4_unicode_ci DEFAULT NULL;
-- DROP TABLE IF EXISTS `payroll_remittance_items`;
-- DROP TABLE IF EXISTS `payroll_remittances`;
-- DROP TABLE IF EXISTS `payment_destinations`;
