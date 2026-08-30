-- 2026-08-30, explicit request: "นโยบายการทำเงินเดือน...ที่อยากให้มี Set เพิ่มคือ จ่ายตามวันที่มาทำงาน หัก
-- วันหยุด หักวันลาไหม หรือจ่ายเต็มเดือน" -- confirmed via AskUserQuestion: "attendance-based" here means
-- schedule-based (same spirit as `employees.salary_type='daily'` proration, which already excludes
-- weekly-off/holiday days from a daily-rate employee's payable-day count), NOT re-deriving from
-- Origami sync attendance data -- the sync-derived path already exists as a SEPARATE mechanism
-- (Attendance Deduction Rule's own percent_of_rate absence/unpaid_leave formulas), and reusing the
-- same sync data here would double-deduct the same absence for any company that has both features
-- configured. See SetupRulesModel::scheduledPayableDaysForEmployee()'s own docblock for the full
-- calculation.
--
-- pay_basis: 'full_month' (default) = today's existing behavior, completely unchanged (base salary
-- paid in full except mid-period join/leave proration via companies.prorate_divisor_days) --
-- 'schedule_based' = PayrollRunModel::recalculate() prorates a MONTHLY-rate employee's base salary
-- by scheduledPayableDaysForEmployee()'s own payable_days/total_scheduled_days ratio instead. Has no
-- effect on salary_type='daily'/'hourly' employees (already schedule-based / unsupported respectively).
--
-- pay_basis_deduct_holidays/pay_basis_deduct_leave: both default 0 (no effect) and only read when
-- pay_basis='schedule_based' -- a company that turns pay_basis on but leaves both of these off still
-- pays 100% of base salary for a normal period (holidays/leave neither help nor hurt), matching the
-- "safe default, opt-in only" precedent every other column on this table already follows.
ALTER TABLE `company_payroll_policies`
  ADD COLUMN `pay_basis` ENUM('full_month','schedule_based') NOT NULL DEFAULT 'full_month' AFTER `probation_base_salary_ratio`,
  ADD COLUMN `pay_basis_deduct_holidays` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pay_basis`,
  ADD COLUMN `pay_basis_deduct_leave` TINYINT(1) NOT NULL DEFAULT 0 AFTER `pay_basis_deduct_holidays`;
