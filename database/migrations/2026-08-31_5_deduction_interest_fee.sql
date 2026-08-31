-- Explicit request: "Form ที่เป็นรายการหัก ทุก Form ให้เพิ่มว่า คิดดอกเบี้ย ค่าธรรมเนียม หรือไม่มี...ถ้าค่าธรรมเนียม
-- ให้ใส่ได้เป็น % คิดจากอะไร มีให้เลือกเช่นฐานเงินเดือนหรืออื่นๆตามที่เลือกได้...ต้องนำไปรวมคำนวณได้ถูกต้อง" --
-- confirmed via AskUserQuestion: scope is the 2 employee-linked deduction forms (#eedModal / loan-
-- style employee_earning_deductions, which ALREADY has interest_type='none'/'fixed'/'reducing_balance'
-- from 2026-08-20 -- see EmployeeEarningDeductionModel::computeInstallmentSchedule()'s own docblock --
-- and the new #recurringDeductionModal from earlier today), and "ยึดตามรายการหักของหน้าพนักงาน" for
-- interest means "reuse that SAME fixed/reducing-balance mechanism", both confirmed as wanted.
--
-- `employee_earning_deductions.interest_type` widened from ('none','fixed','reducing_balance') to
-- ADD 'fee' as a 4th sibling value (loan/installment side already has a "none vs how much" toggle --
-- 'fee' just adds a THIRD real charge mode alongside the 2 interest modes, instead of introducing a
-- second, redundant top-level enum column that could drift out of sync with this one). When ='fee',
-- `interest_rate` is unused (NULL) and `fee_percent`/`fee_base` drive the calculation instead --
-- see EmployeeEarningDeductionModel::computeInstallmentSchedule()'s own updated docblock for the
-- exact formula (same "one-time charge added to principal, then spread across installments" shape
-- interest_type='fixed' already uses, not a per-installment recurring charge).
ALTER TABLE `employee_earning_deductions`
  MODIFY COLUMN `interest_type` ENUM('none','fixed','reducing_balance','fee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  ADD COLUMN `fee_percent` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'only meaningful while interest_type=fee' AFTER `interest_rate`,
  ADD COLUMN `fee_base` ENUM('base_salary','principal_amount') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'only meaningful while interest_type=fee -- what fee_percent is a percentage OF' AFTER `fee_percent`;

-- employee_recurring_deductions has NO principal/installment-schedule concept at all (see that
-- table's own migration comment -- it's an indefinitely-recurring flat amount, not a loan), so
-- "Interest" (which requires an amortizable balance) genuinely does not apply here -- only "Fee" does
-- (an ongoing % of base salary added on top of the flat amount every run, recomputed fresh each time
-- since base salary can change over time -- see EmployeeRecurringDeductionModel::activeForPeriod()'s
-- own updated docblock). `fee_base` only ever validates to 'base_salary' at the application layer for
-- this table (no 'principal_amount' equivalent exists here) -- kept as its own enum rather than
-- reusing employee_earning_deductions' 2-option one so a future, genuinely-different base for this
-- table doesn't require touching the loan table's own enum.
ALTER TABLE `employee_recurring_deductions`
  ADD COLUMN `fee_percent` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'ongoing fee, % of fee_base, added on top of `amount` every payroll run' AFTER `amount`,
  ADD COLUMN `fee_base` ENUM('base_salary') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'only meaningful while fee_percent is set -- what it is a percentage OF' AFTER `fee_percent`;
