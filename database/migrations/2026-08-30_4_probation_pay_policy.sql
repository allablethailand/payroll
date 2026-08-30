-- 2026-08-30, explicit request: "อาจมีการตั้งค่าเพิ่มสำหรับเงื่อนไขการจ่ายเงินของพนักงานทดลองงานครับ" --
-- confirmed via AskUserQuestion (selected all 4 proposed topics): defer PVD contribution until
-- probation passes, defer Recurring Allowances until probation passes, a configurable base-salary
-- ratio applied during probation, and a configurable standard probation period length. Added as
-- more columns on the same singleton `company_payroll_policies` row this tab already uses (see that
-- table's own 2026-08-30 header comment on why it's one heterogeneous row per company, not a
-- separate table per topic).
--
-- Every new column defaults to "no behavior change" (0/NULL) so a company that never visits this
-- section of the tab sees zero difference from before this feature existed:
--   probation_defer_pvd / probation_defer_recurring_earning: 0 = unchanged existing behavior
--     (PVD/Recurring Allowances follow their own existing per-employee settings regardless of
--     probation status, exactly as before).
--   probation_base_salary_ratio: NULL = 100% (no reduction), same as today.
--   probation_period_days: NULL = not configured; this is a REFERENCE/DISPLAY value only (e.g. an
--     expected probation-end-date hint) -- it does NOT gate probation-specific pay behavior itself.
--     The actual gate for probation_defer_pvd/probation_defer_recurring_earning/
--     probation_base_salary_ratio is `employees.employment_status = 'probation'`, the real,
--     HR-maintained current status, not a day-count computed from employment_date -- an employee
--     whose actual promotion timing differs from the "standard" length would otherwise be
--     mis-gated by a day-count guess.
ALTER TABLE `company_payroll_policies`
  ADD COLUMN `probation_period_days` INT UNSIGNED NULL DEFAULT NULL AFTER `reopen_window_days`,
  ADD COLUMN `probation_defer_pvd` TINYINT(1) NOT NULL DEFAULT 0 AFTER `probation_period_days`,
  ADD COLUMN `probation_defer_recurring_earning` TINYINT(1) NOT NULL DEFAULT 0 AFTER `probation_defer_pvd`,
  ADD COLUMN `probation_base_salary_ratio` DECIMAL(5,2) NULL DEFAULT NULL AFTER `probation_defer_recurring_earning`;
