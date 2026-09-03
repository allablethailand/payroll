-- 2026-09-02, explicit request: extend Probation/Internship pay policy with leave/OT rights during
-- the period, and an internship duration reference (probation already has probation_period_days).
--
-- `intern_period_days`: same "reference/display value only, does NOT gate anything itself" contract
-- as `probation_period_days` (see 2026-08-30_4_probation_pay_policy.sql's own header) -- this app
-- has no cron/scheduled-job infrastructure (same documented limitation as Payslip Distribution's
-- own `send_delay_hours`), so neither ever auto-transitions employment_status/employment_type.
--
-- `{probation,intern}_leave_days_limit` / `allow_leave_during_{probation,intern}`: NULL limit = no
-- explicit cap configured (existing leave-type quota rules still apply unchanged); the allow
-- toggle defaults to 1 (leave permitted) so a company that never visits this section sees zero
-- behavior change. Genuinely informational/policy-reference for now -- no consumer wired yet (same
-- "built, no consumer yet" precedent this project already uses elsewhere, e.g. Holiday's own
-- resolveHolidaysForEmployee() before it had a caller); wiring an actual leave-request-time gate is
-- separate scope.
--
-- `{probation,intern}_ot_eligible_default`: NULL = no default configured (employees.ot_eligible's
-- own existing per-employee checkbox value is left completely alone, same as today). When set,
-- EmployeeModel::save() applies it to ot_eligible EXACTLY ONCE, at the moment an employee's
-- employment_status/employment_type transitions INTO probation/internship -- never on a later save,
-- so the checkbox genuinely "still wins" after that one-time default (confirmed via AskUserQuestion:
-- default only, not a hard payroll-engine gate).
ALTER TABLE `company_payroll_policies`
  ADD COLUMN `intern_period_days` INT UNSIGNED NULL DEFAULT NULL AFTER `intern_base_salary_ratio`,
  ADD COLUMN `probation_leave_days_limit` INT UNSIGNED NULL DEFAULT NULL AFTER `probation_base_salary_ratio`,
  ADD COLUMN `allow_leave_during_probation` TINYINT(1) NOT NULL DEFAULT 1 AFTER `probation_leave_days_limit`,
  ADD COLUMN `probation_ot_eligible_default` TINYINT(1) NULL DEFAULT NULL AFTER `allow_leave_during_probation`,
  ADD COLUMN `intern_leave_days_limit` INT UNSIGNED NULL DEFAULT NULL AFTER `intern_pay_basis_deduct_leave`,
  ADD COLUMN `allow_leave_during_intern` TINYINT(1) NOT NULL DEFAULT 1 AFTER `intern_leave_days_limit`,
  ADD COLUMN `intern_ot_eligible_default` TINYINT(1) NULL DEFAULT NULL AFTER `allow_leave_during_intern`;

-- Rollback:
-- ALTER TABLE `company_payroll_policies`
--   DROP COLUMN `intern_period_days`, DROP COLUMN `probation_leave_days_limit`,
--   DROP COLUMN `allow_leave_during_probation`, DROP COLUMN `probation_ot_eligible_default`,
--   DROP COLUMN `intern_leave_days_limit`, DROP COLUMN `allow_leave_during_intern`,
--   DROP COLUMN `intern_ot_eligible_default`;
