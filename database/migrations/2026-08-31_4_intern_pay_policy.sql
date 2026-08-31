-- Explicit request: "และถ้าต้องการ Set การจ่ายสำหรับเด็กฝึกงาน...ให้ครอบคลุมถึงเด็กฝึกงานบางคนที่ให้เงินเดือน
-- แต่อยากให้ตั้งเงื่อนไขได้แบบ Probation...ในหน้าเงินเดือนก็แก้ไขได้เป็นรายบุคคลด้วย" -- confirmed via
-- AskUserQuestion: daily-allowance interns reuse the EXISTING salary_type='daily' + Recurring
-- Allowances mechanism (no new schema needed for that half). Salaried interns who want Probation-like
-- conditions reuse the SAME engine gates as company_payroll_policies' own probation_* columns (see
-- 2026-08-30_4_probation_pay_policy.sql), just with their OWN separate field set so the two
-- conditions (probation vs internship) can be configured with different ratios independently --
-- PayrollRunModel treats employment_type='internship' as taking PRECEDENCE over
-- employment_status='probation' when both happen to be true for the same employee (an intern is a
-- more specific classification in this app's own domain), never stacking/multiplying both ratios
-- together.
--
--   intern_defer_pvd / intern_defer_recurring_earning: 0 = unchanged existing behavior (same
--   semantics as their probation_* counterparts, just gated by employment_type='internship' instead).
--   intern_base_salary_ratio: NULL = 100% (no reduction), same convention as probation_base_salary_ratio.
--
--   Per-employee override (the "แก้ไขได้เป็นรายบุคคล" half, confirmed via AskUserQuestion: a toggle +
--   per-employee ratio, NOT a full per-employee copy of every intern_* field) lives on `employees`
--   itself as a single nullable column -- NULL means "no override, use this company's intern_
--   base_salary_ratio default", a non-null value is this ONE employee's own ratio instead. The
--   nullable column doubles as its own on/off toggle (checked+filled vs unchecked+null), same
--   "one nullable column, no separate boolean needed" pattern probation_base_salary_ratio itself
--   already uses at the company level.
ALTER TABLE `company_payroll_policies`
  ADD COLUMN `intern_defer_pvd` TINYINT(1) NOT NULL DEFAULT 0 AFTER `probation_base_salary_ratio`,
  ADD COLUMN `intern_defer_recurring_earning` TINYINT(1) NOT NULL DEFAULT 0 AFTER `intern_defer_pvd`,
  ADD COLUMN `intern_base_salary_ratio` DECIMAL(5,2) NULL DEFAULT NULL AFTER `intern_defer_recurring_earning`;

ALTER TABLE `employees`
  ADD COLUMN `intern_base_salary_ratio_override` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'NULL = use company_payroll_policies.intern_base_salary_ratio; only meaningful while employment_type=internship' AFTER `employment_type`;
