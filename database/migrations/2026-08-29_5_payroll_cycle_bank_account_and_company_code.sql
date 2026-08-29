-- 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" -- a
-- company may run multiple payroll cycles that each settle from a DIFFERENT bank account (and
-- therefore a different bank-registered Company/Service Code, e.g. Krungsri's own "712" example).
-- The previous design only supported ONE company-wide value for both (bank_accounts.is_default=1
-- for the account, a single shared `constant_value` on the bank format's own header field for the
-- code) -- neither varies per cycle.
--
--   payroll_cycles.bank_account_id (nullable FK -> bank_accounts): lets a cycle pin down WHICH of
--   the company's own bank accounts it settles from. NULL (the default -- every existing cycle row
--   stays exactly as-is after this migration) means "fall back to the company's is_default=1
--   account", same behavior as before this feature existed -- see
--   BankTransferFileReport::resolveCompanyBankAccount()'s own docblock for the actual resolution
--   order.
--
--   bank_accounts.company_code: the bank-registered Company/Service Code now lives PER ACCOUNT
--   (not as a shared constant on the bank file format) -- moves naturally with "which account is
--   this cycle using" instead of needing a separate, easy-to-forget-to-update setting. Nullable
--   free text (not every bank assigns one, and this app doesn't validate its format per bank).
ALTER TABLE `payroll_cycles`
  ADD COLUMN `bank_account_id` INT NULL AFTER `bank_file_format_id`,
  ADD CONSTRAINT `fk_payroll_cycles_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `bank_accounts`
  ADD COLUMN `company_code` VARCHAR(20) NULL AFTER `account_name`;
