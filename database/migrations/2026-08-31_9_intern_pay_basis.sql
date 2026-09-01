-- Explicit request: "เงื่อนไขการจ่ายเงินเด็กฝึกงาน...จ่ายเต็มเดือน หรือจ่ายแค่วันที่มาทำจริง หักลา หักวันหยุดไหม
-- เหมือน Probation" -- Probation already has `pay_basis`/`pay_basis_deduct_holidays`/
-- `pay_basis_deduct_leave` (see 2026-08-30_6_payroll_policy_pay_basis.sql) but Internship never got
-- the equivalent (confirmed real gap -- an intern was offered the same 3 pay_basis radios in the UI
-- copy but the calc engine never actually branched on employment_type='internship' for this
-- setting, only for the ratio/defer_pvd/defer_recurring_earning fields added the same day).
--
-- Own separate field set, same "own separate columns, same engine, independently configurable"
-- pattern intern_defer_pvd/intern_defer_recurring_earning/intern_base_salary_ratio already use
-- (see 2026-08-31_4_intern_pay_policy.sql's own header) -- NOT a shared column with probation's own
-- pay_basis, so the two conditions can be configured completely independently. Precedence when an
-- employee is somehow both employment_type='internship' AND employment_status='probation': intern's
-- OWN pay_basis governs (same precedence PayrollRunModel::recalculate() already established for the
-- ratio/defer flags), probation's pay_basis is simply never consulted for that employee.
ALTER TABLE `company_payroll_policies`
  ADD COLUMN `intern_pay_basis` ENUM('full_month','schedule_based','sync_actual_days') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full_month' AFTER `intern_base_salary_ratio`,
  ADD COLUMN `intern_pay_basis_deduct_holidays` TINYINT(1) NOT NULL DEFAULT 0 AFTER `intern_pay_basis`,
  ADD COLUMN `intern_pay_basis_deduct_leave` TINYINT(1) NOT NULL DEFAULT 0 AFTER `intern_pay_basis_deduct_holidays`;
