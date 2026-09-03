-- Manual Entry / Employee Salary tab review, Phase 1B (explicit request: "เมื่อเลือก PED Type ในฟอร์ม
-- เงินกู้/ผ่อนชำระ ให้ auto-fill ดอกเบี้ย/เงื่อนไข default จาก catalog"). `payroll_earning_deduction_types`
-- (the catalog Payroll Configuration > Earning-Deduction Types manages) had no interest/fee/
-- installment-mode defaults at all -- the per-employee assignment form
-- (employee_earning_deductions, #eedModal in Employee Detail's Salary tab) already has its own real
-- interest_type/interest_rate/fee_percent/fee_base/amount_mode columns (see
-- database/migrations/2026-08-31_5_deduction_interest_fee.sql), but nothing on the catalog side to
-- suggest a starting value from, unlike fixed_amount/percent_rate which already do this exact job
-- for the plain Amount field (see EmployeeController::earningDeductionOptions()'s own docblock).
--
-- Deduction-only (mirrors tax_deduction_impact/statutory_report_code's own existing "meaningless for
-- earning, always NULL there" convention on this same table) -- interest/installment concepts don't
-- apply to an earning item. All nullable: NULL = "no default configured for this item", the
-- assignment form's own existing defaults (interest_type='none') apply exactly as they already do
-- today, zero behavior change for any item that doesn't opt in.
--
-- NOTE: a `default_amount_mode` column was added and then DROPPED again in this same session, before
-- ever being used anywhere -- detail.js's own collectEedFormData() confirmed amount_mode is
-- hardcoded to 'custom_per_installment' from the assignment modal since 2026-08-20 (no UI control
-- left to auto-fill), so a catalog-level default for it would have been dead, unreachable
-- configuration. Caught before shipping, not left in as unused scaffolding.
ALTER TABLE `payroll_earning_deduction_types`
  ADD COLUMN `default_interest_type` ENUM('none','fixed','reducing_balance','fee') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'deduction only -- suggested starting interest_type for a new employee_earning_deductions assignment of this item' AFTER `statutory_report_code`,
  ADD COLUMN `default_interest_rate` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'only meaningful when default_interest_type IN (fixed, reducing_balance)' AFTER `default_interest_type`,
  ADD COLUMN `default_fee_percent` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'only meaningful when default_interest_type = fee' AFTER `default_interest_rate`,
  ADD COLUMN `default_fee_base` ENUM('base_salary','principal_amount') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'only meaningful when default_interest_type = fee -- what default_fee_percent is a percentage OF' AFTER `default_fee_percent`;
