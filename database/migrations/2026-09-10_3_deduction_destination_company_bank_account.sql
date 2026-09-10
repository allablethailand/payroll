-- 2026-09-10, Batch 3B item 3: "company" deduction destination gains a level-2 selection (WHICH
-- specific company bank account), matching the level-2 already built for 'employee' (payee_employee_id)
-- and 'other_person' (destination_id -> payment_destinations) since 2026-09-02. Confirmed via
-- investigation before writing this: 'employee'/'other_person' level-2 already existed; 'company'
-- had none at all -- PayrollRemittanceModel::generateForRun() lumped every company-type deduction
-- in a run into one blanket audit row with no account attribution. This migration adds the missing
-- column only -- payee_type enum/other columns are UNCHANGED.
--
-- Nullable, no backfill: every existing payee_type='company' row keeps bank_account_id=NULL,
-- resolved as the "unspecified" bucket by the application layer going forward (never silently
-- dropped -- see PayrollRemittanceModel/ThirdPartyRemittanceSummaryReport's own app-layer handling).
-- Mandatory-when-selecting-company is enforced at the PHP save() layer (all 4 write paths), not a
-- DB-level NOT NULL, so old rows are never blocked by a constraint they predate.
--
-- ON DELETE SET NULL ON UPDATE CASCADE matches this app's own established convention for every
-- other per-record bank_account_id FK (employees.default_bank_account_id, employee_payment_method_
-- lines.bank_account_id, payroll_cycles.bank_account_id) -- NOT the ON DELETE RESTRICT this same
-- feature's own destination_id/payee_employee_id FKs use, since those reference a different kind of
-- record (a saved third-party payee / an employee) with different deletion semantics.

-- UP
ALTER TABLE `employee_earning_deductions`
  ADD COLUMN `bank_account_id` int(11) DEFAULT NULL AFTER `destination_id`,
  ADD KEY `fk_eed_bank_account` (`bank_account_id`),
  ADD CONSTRAINT `fk_eed_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `payroll_run_manual_lines`
  ADD COLUMN `bank_account_id` int(11) DEFAULT NULL AFTER `destination_id`,
  ADD KEY `fk_pml_bank_account` (`bank_account_id`),
  ADD CONSTRAINT `fk_pml_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `employee_recurring_deductions`
  ADD COLUMN `bank_account_id` int(11) DEFAULT NULL AFTER `destination_id`,
  ADD KEY `fk_erd_bank_account` (`bank_account_id`),
  ADD CONSTRAINT `fk_erd_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `payroll_run_recurring_deduction_overrides`
  ADD COLUMN `bank_account_id` int(11) DEFAULT NULL AFTER `destination_id`,
  ADD KEY `fk_prrdo_bank_account` (`bank_account_id`),
  ADD CONSTRAINT `fk_prrdo_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `payroll_remittances`
  ADD COLUMN `bank_account_id` int(11) DEFAULT NULL COMMENT 'FK bank_accounts, only set when destination_type=company' AFTER `fallback_employee_id`,
  ADD KEY `fk_remittances_bank_account` (`bank_account_id`),
  ADD CONSTRAINT `fk_remittances_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- DOWN
ALTER TABLE `payroll_remittances` DROP FOREIGN KEY `fk_remittances_bank_account`, DROP COLUMN `bank_account_id`;
ALTER TABLE `payroll_run_recurring_deduction_overrides` DROP FOREIGN KEY `fk_prrdo_bank_account`, DROP COLUMN `bank_account_id`;
ALTER TABLE `employee_recurring_deductions` DROP FOREIGN KEY `fk_erd_bank_account`, DROP COLUMN `bank_account_id`;
ALTER TABLE `payroll_run_manual_lines` DROP FOREIGN KEY `fk_pml_bank_account`, DROP COLUMN `bank_account_id`;
ALTER TABLE `employee_earning_deductions` DROP FOREIGN KEY `fk_eed_bank_account`, DROP COLUMN `bank_account_id`;
