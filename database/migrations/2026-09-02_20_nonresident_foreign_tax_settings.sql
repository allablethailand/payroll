-- 2026-09-02_20_nonresident_foreign_tax_settings.sql
--
-- Foreign-worker tax-residency treatment, confirmed via AskUserQuestion after flagging (not
-- guessing) that Thai PIT withholding on Thailand-source SALARY income (มาตรา 50 ทวิ) uses the
-- SAME progressive bracket table regardless of resident/non-resident status -- the residency
-- distinction mainly affects foreign-source income remitted into Thailand, a separate concern this
-- app has no reason to model. The user confirmed there MAY be a real different rate/rule their
-- accountant applies, but doesn't know the exact figure right now -- so this migration adds a
-- plain COMPANY-CONFIGURABLE setting (never a hardcoded "correct" rate this app asserts), same
-- established convention as `company_payroll_policies.supplemental_flat_tax_rate_percent`
-- (2026-08-31_14_supplemental_flat_tax_rate.sql) for the exact same reason -- see that migration's
-- own header for the precedent.
--
-- `enabled=0` (the default) means zero behavior change from today for every company that never
-- visits this new settings tab -- PayrollRunModel::recalculate() only takes the flat-rate branch
-- when BOTH this is enabled AND flat_rate_percent is actually set AND the specific employee is
-- flagged as a tax non-resident (employees.tax_non_resident below). `reference_note` is a plain
-- free-text field so whoever configures this can record where the rate/rule came from (Revenue
-- Department ruling number, the company's own accountant, etc.) -- not read by any calculation,
-- purely a compliance-audit convenience.
CREATE TABLE `company_nonresident_tax_settings` (
  `comp_id` INT(11) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `flat_rate_percent` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Company-configured flat withholding %% applied instead of the normal progressive PIT calculation, used ONLY for employees flagged tax_non_resident -- never a hardcoded/assumed rate',
  `reference_note` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Free-text: where this rate/rule came from (RD ruling no., accountant advice, etc.) -- not read by calculation logic',
  `updated_by` INT(11) NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`comp_id`),
  CONSTRAINT `fk_nonresident_tax_settings_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-employee flag -- NULL/0 = normal (progressive calc, same as every employee today). Only
-- meaningful for employee_type='foreigner' (gated in the UI), but not DB-constrained to it since a
-- domestic employee flipping employee_type later shouldn't lose a previously-set flag silently.
ALTER TABLE `employees`
  ADD COLUMN `tax_non_resident` TINYINT(1) NULL DEFAULT NULL COMMENT 'Employee is a tax non-resident for Thai PIT withholding purposes (foreign worker, <180 days/year in Thailand) -- only takes effect when company_nonresident_tax_settings.enabled=1 and a flat_rate_percent is configured' AFTER `employee_type`;
