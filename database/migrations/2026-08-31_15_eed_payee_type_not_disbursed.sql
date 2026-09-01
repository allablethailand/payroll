-- 2026-08-31, same-day follow-up: "รายการหัก ของการตั้งค่า ในหน้าทำรอบ และหน้าตั้งค่าพนักงาน ให้เพิ่มเติมตรงที่
-- หักไปที่ไหนได้เพิ่มว่า ไม่หักไปที่ไหน เพราะเป็นการหักเพื่อไม่ทำจ่ายเฉยๆ โดยเงินไม่ออกจากกองทุน" -- confirmed via
-- AskUserQuestion: a genuinely NEW, distinct payee_type value (not just decoupling
-- include_in_cash_summary from requiring a payee, which was the other option offered) -- existing
-- `payee_type=NULL` already means "reduces this employee's own net pay, nothing tracked" (a REAL
-- deduction that still leaves the payroll fund via that employee's own lower net pay), which is
-- semantically different from this new case: a deduction that corresponds to NO real money movement
-- at all -- not to another employee, not retained by the company, not even reducing what actually
-- gets disbursed from the fund (e.g. reversing a bonus that was never actually paid out, or a
-- clawback tracked purely as a paper adjustment for reconciliation).
--
-- `payee_type` = 'not_disbursed': payee_employee_id stays NULL (same as 'company'), and
-- `include_in_cash_summary` is FORCED to 0 at the application layer (EmployeeEarningDeductionModel::
-- save()) -- definitionally never a cash/bank remittance, so it must never be offered as a toggle
-- the way 'employee'/'company' rows' own include_in_cash_summary genuinely is.
--
-- Also adds the SAME payee_type/include_in_cash_summary column pair to `payroll_run_manual_lines`
-- (the Payroll Run process's own ad-hoc manual-line mechanism) -- that table never got this concept
-- at all when the original 2026-08-31_7 migration shipped (it only widened
-- employee_earning_deductions, the Employee Detail standing-assignment table), so the Process
-- Detail page's own manual-line editor has had no payee-routing UI whatsoever until now.
ALTER TABLE `employee_earning_deductions`
  MODIFY COLUMN `payee_type` ENUM('employee','company','not_disbursed') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'NULL = no payee (reduces own net pay). employee = payee_employee_id used. company = retained by company. not_disbursed = withheld but no money moves anywhere (never counted in cash summary)';

ALTER TABLE `payroll_run_manual_lines`
  ADD COLUMN `payee_type` ENUM('employee','company','not_disbursed') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Same meaning as employee_earning_deductions.payee_type' AFTER `payee_employee_id`,
  ADD COLUMN `include_in_cash_summary` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Same meaning as employee_earning_deductions.include_in_cash_summary -- forced 0 when payee_type=not_disbursed' AFTER `payee_type`;
