-- 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 3 of the 3 deliberately-deferred cases
-- from the Origami `attribution` feature's own plan): a company-configurable flat withholding rate
-- for a supplemental sync process pulled with `attribution_tax_treatment='separate'` -- Origami's
-- own PAYROLL_SYNC_API.md is explicit that "the actual rate/method is entirely your side's own
-- policy; we have no tax-rate concept of our own", so this app has to be the one to offer it.
-- Deliberately NOT a single hardcoded default rate anywhere in code -- this project's own
-- convention for anything tax/statutory-adjacent that hasn't been verified against the official
-- rules (see CLAUDE.md's own DRAFT/unverified caveats elsewhere) is to make it a plain configurable
-- setting the company enters themselves, never a value this app asserts is "the correct" rate.
ALTER TABLE `company_payroll_policies`
  ADD COLUMN `supplemental_flat_tax_rate_percent` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Company-configured flat withholding %, used only when a supplemental run explicitly opts in via payroll_runs.use_flat_tax_rate -- normal average/actual PIT calculation is used otherwise' AFTER `intern_pay_basis_deduct_leave`;

-- Per-run opt-in -- same "admin explicitly turns this on for THIS run" pattern as
-- compute_statutory/include_base_salary/include_standing_items/include_attendance_pay already use
-- for an incentive/supplemental run (see PayrollRunModel::create()'s own 2026-08-19/27 comments) --
-- forced to 0 for a normal 'payroll'-purpose run, never something a regular cycle run can opt into.
ALTER TABLE `payroll_runs`
  ADD COLUMN `use_flat_tax_rate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `include_attendance_pay`;
