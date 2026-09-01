-- Explicit request: "และถ้าหักไปจ่ายใคร หรือจ่ายเข้าบัญชีบริษัท ให้ติ๊กเพิ่มได้ว่า รวมไปใน cashlink หรือแยก
-- cash link" -- confirmed via AskUserQuestion: "cashlink" = the new Cash Payment Summary Report (see
-- 2026-08-31_8_payroll_run_cash_payments.sql). Widens the EXISTING transfer-payee mechanism
-- (employee_earning_deductions.payee_employee_id, 2026-08-21, "หักเพื่อไปจ่ายให้ใคร") which today only
-- ever means "another employee" -- `payee_employee_id` alone can't represent "paid into the
-- company's own account" (no employee row to point at), so `payee_type` is the real new distinction.
--
-- `payee_type` = NULL: unchanged existing behavior, no payee at all (deduction just reduces this
-- employee's own net pay, nothing tracked/transferred).
-- `payee_type` = 'employee': payee_employee_id is used exactly as before (PayrollRunModel::
-- recalculate()'s transfer-credit pass reads it unchanged -- no calc-engine code needed here).
-- `payee_type` = 'company': money is retained by the company, NOT credited to any employee --
-- payee_employee_id stays NULL in this case too (the transfer-credit pass already skips a NULL
-- payee_employee_id, so no new branch was needed there either); `payee_type` alone is what lets the
-- Cash Payment Summary Report tell "no payee" apart from "company-retained" when both leave
-- payee_employee_id NULL.
--
-- `include_in_cash_summary` only has real meaning while payee_type is not NULL (i.e. this deduction
-- routes money somewhere other than reducing the employee's own net pay) -- defaults to 1 (included)
-- so existing/typical rows behave the same as before this column existed; unchecking it is the
-- "แยก cash link" (keep tracked separately, excluded from the aggregate) half of the request.
ALTER TABLE `employee_earning_deductions`
  ADD COLUMN `payee_type` ENUM('employee','company') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'NULL = no payee (unchanged default). employee = payee_employee_id used as before. company = retained by company, payee_employee_id stays NULL' AFTER `payee_employee_id`,
  ADD COLUMN `include_in_cash_summary` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'only meaningful while payee_type is not NULL -- whether this line folds into the Cash Payment Summary Report aggregate or is tracked as its own separate cash link' AFTER `payee_type`;

-- Backfill: every existing row with a payee_employee_id already set was, by definition, an
-- employee-type transfer (the only kind that existed before this migration).
UPDATE `employee_earning_deductions` SET `payee_type` = 'employee' WHERE `payee_employee_id` IS NOT NULL;
