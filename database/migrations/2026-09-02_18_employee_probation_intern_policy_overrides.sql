-- 2026-09-02, follow-up to close a gap flagged after review: the per-employee "use company policy
-- vs custom settings" toggle (Salary tab) only ever let ONE field (base_salary_ratio) be
-- overridden, not "ครบทุกช่อง ไม่ตัดทอน" (every field, not truncated) as explicitly requested. Adds a
-- nullable override column PER company_payroll_policies field -- NULL = use the company default
-- (unchanged from before this migration for every existing employee), a non-null value is this ONE
-- employee's own setting instead, same "one nullable column doubles as its own on/off toggle"
-- convention probation_base_salary_ratio_override/intern_base_salary_ratio_override already use.
--
-- `*_ot_eligible_default` is NOT duplicated here: it's a one-time CREATE-TIME seed for the
-- ALREADY-existing, ALREADY-per-employee `employees.ot_eligible` checkbox -- overriding "the
-- default" for an employee that already has their own real value doesn't correspond to anything
-- meaningful to store; the checkbox itself already IS this employee's own setting.
-- `*_period_days_override` IS included (same reference/display-only status as the company-level
-- field, but included anyway for genuinely complete field-parity per the explicit "ทุกช่อง ไม่ตัดทอน"
-- instruction -- e.g. "this specific employee's own probation is 90 days, not the company's
-- standard 119").
ALTER TABLE `employees`
  ADD COLUMN `probation_defer_pvd_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.probation_defer_pvd' AFTER `probation_base_salary_ratio_override`,
  ADD COLUMN `probation_defer_sso_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.probation_defer_sso' AFTER `probation_defer_pvd_override`,
  ADD COLUMN `probation_defer_recurring_earning_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.probation_defer_recurring_earning' AFTER `probation_defer_sso_override`,
  ADD COLUMN `probation_leave_days_limit_override` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.probation_leave_days_limit; reference/display only, same as the company-level field' AFTER `probation_defer_recurring_earning_override`,
  ADD COLUMN `probation_allow_leave_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.allow_leave_during_probation' AFTER `probation_leave_days_limit_override`,
  ADD COLUMN `probation_period_days_override` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.probation_period_days; reference/display only, same as the company-level field' AFTER `probation_allow_leave_override`,
  ADD COLUMN `intern_defer_pvd_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.intern_defer_pvd' AFTER `intern_base_salary_ratio_override`,
  ADD COLUMN `intern_defer_sso_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.intern_defer_sso' AFTER `intern_defer_pvd_override`,
  ADD COLUMN `intern_defer_recurring_earning_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.intern_defer_recurring_earning' AFTER `intern_defer_sso_override`,
  ADD COLUMN `intern_leave_days_limit_override` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.intern_leave_days_limit; reference/display only, same as the company-level field' AFTER `intern_defer_recurring_earning_override`,
  ADD COLUMN `intern_allow_leave_override` TINYINT(1) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.allow_leave_during_intern' AFTER `intern_leave_days_limit_override`,
  ADD COLUMN `intern_period_days_override` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.intern_period_days; reference/display only, same as the company-level field' AFTER `intern_allow_leave_override`;

-- Rollback:
-- ALTER TABLE `employees`
--   DROP COLUMN `probation_defer_pvd_override`, DROP COLUMN `probation_defer_sso_override`,
--   DROP COLUMN `probation_defer_recurring_earning_override`, DROP COLUMN `probation_leave_days_limit_override`,
--   DROP COLUMN `probation_allow_leave_override`, DROP COLUMN `probation_period_days_override`,
--   DROP COLUMN `intern_defer_pvd_override`, DROP COLUMN `intern_defer_sso_override`,
--   DROP COLUMN `intern_defer_recurring_earning_override`, DROP COLUMN `intern_leave_days_limit_override`,
--   DROP COLUMN `intern_allow_leave_override`, DROP COLUMN `intern_period_days_override`;
