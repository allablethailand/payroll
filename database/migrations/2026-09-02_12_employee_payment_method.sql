-- 2026-09-02, explicit request: employee-level payment method type (transfer/cash/check/mixed).
--
-- `employees.payment_type` (enum('bank','cash')) already exists and is read by several
-- established call sites (EmployeeModel::missingPayrollFields()/calculateCompleteness()'s bankOk
-- check, PayrollRunEmployeeBankAccountModel::resolveForRun(), BankTransferFileReport::generate())
-- via a plain `=== 'bank'` check. Rather than rip that out now, `payment_type` stays the legacy
-- MIRROR column -- widened here to also allow 'check'/'mixed' (its EXISTING 'bank'/'cash' literals
-- are UNCHANGED, so every existing `=== 'bank'` comparison keeps working exactly as before) --
-- while `payment_method_id` becomes the new, richer source of truth going forward.
-- EmployeeModel::save() keeps both in sync on every save via a fixed code=>legacy-literal map
-- ('transfer'=>'bank', 'cash'=>'cash', 'check'=>'check', 'mixed'=>'mixed' -- only 'transfer' maps
-- to a differently-spelled legacy literal, because 'bank' was the ONLY option before this feature
-- and can't be renamed without touching every existing `=== 'bank'` comparison in the codebase).
-- Old read sites keep working unmodified through Phase 4; Phase 5 (payroll engine integration)
-- cuts the DISBURSEMENT-relevant sites over to read employee_payment_method_lines directly for
-- the 'mixed' case, since a single payment_type value can never fully describe a mixed split.

START TRANSACTION;

ALTER TABLE `employees`
  MODIFY COLUMN `payment_type` enum('bank','cash','check','mixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank',
  ADD COLUMN `payment_method_id` int(11) DEFAULT NULL COMMENT 'FK master_payment_methods -- new source of truth, payment_type above is kept in sync as a legacy mirror' AFTER `payment_type`,
  ADD KEY `idx_employees_payment_method` (`payment_method_id`),
  ADD CONSTRAINT `fk_employees_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `master_payment_methods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

UPDATE `employees` e
  JOIN `master_payment_methods` mpm ON mpm.code = (CASE e.payment_type WHEN 'bank' THEN 'transfer' ELSE e.payment_type END)
  SET e.payment_method_id = mpm.id;

CREATE TABLE `employee_payment_method_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `payment_method_id` int(11) NOT NULL COMMENT 'FK master_payment_methods -- transfer/cash/check for THIS line (never mixed -- a mixed line cannot itself be mixed)',
  `amount_type` enum('fixed','percent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_value` decimal(14,2) NOT NULL COMMENT 'either a fixed currency amount or a percent (0-100) of net pay, per amount_type',
  `bank_account_id` int(11) DEFAULT NULL COMMENT 'FK bank_accounts -- required only when this line''s payment_method_id resolves to transfer, validated at the application layer',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_epml_employee` (`employee_id`),
  KEY `idx_epml_payment_method` (`payment_method_id`),
  KEY `idx_epml_bank_account` (`bank_account_id`),
  CONSTRAINT `fk_epml_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_epml_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `master_payment_methods` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_epml_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

COMMIT;

-- Rollback:
-- START TRANSACTION;
-- DROP TABLE IF EXISTS `employee_payment_method_lines`;
-- ALTER TABLE `employees` DROP FOREIGN KEY `fk_employees_payment_method`, DROP COLUMN `payment_method_id`,
--   MODIFY COLUMN `payment_type` enum('bank','cash') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank';
-- COMMIT;
