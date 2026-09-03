-- 2026-09-02, explicit request: Probation never got the per-employee ratio override Internship
-- already has (`employees.intern_base_salary_ratio_override`, 2026-08-31_4_intern_pay_policy.sql)
-- -- confirmed real gap, not a rebuild. Same "one nullable column doubles as its own on/off toggle"
-- convention: NULL = use company_payroll_policies.probation_base_salary_ratio, a non-null value is
-- this ONE employee's own ratio instead. Only meaningful while employment_status='probation'.
ALTER TABLE `employees`
  ADD COLUMN `probation_base_salary_ratio_override` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.probation_base_salary_ratio; only meaningful while employment_status=probation' AFTER `intern_base_salary_ratio_override`;

-- Rollback:
-- ALTER TABLE `employees` DROP COLUMN `probation_base_salary_ratio_override`;
