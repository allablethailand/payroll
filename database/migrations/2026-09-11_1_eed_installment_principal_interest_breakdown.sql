-- 2026-09-11, Batch 3B item 4: persists the per-installment principal/interest split that
-- EmployeeEarningDeductionModel::computeInstallmentSchedule()'s own 'reducing_balance' branch
-- already computes internally (balance * r per period) but has always discarded before returning
-- -- only a single combined `amount` per installment was ever stored. This is purely additive: the
-- existing `amount` column/behavior is completely unchanged, these 2 columns are populated ALONGSIDE
-- it going forward, never replacing it.
--
-- Nullable, no backfill: an installment row saved before this migration has no breakdown to show
-- retroactively (the split was never computed for it, and there is no way to reconstruct it after
-- the fact without guessing) -- the UI/report layer must treat NULL here as "no breakdown available
-- for this row" (falls back to blank/'-'), never as "interest was zero".
--
-- `interest_amount` is reused for interest_type='fee' rows too (a fee is not literally "interest",
-- but shares the exact same "one-time charge added to principal, spread evenly" shape as 'fixed'
-- interest -- see EmployeeEarningDeductionModel::computeInstallmentSchedule()'s own docblock) --
-- the UI labels this column "Fee" instead of "Interest" when interest_type='fee', same column,
-- different label. A row with interest_type='none' gets interest_amount=0.00 (principal_amount ==
-- amount exactly), not NULL, since there genuinely is no ambiguity for that case.

-- UP
ALTER TABLE `employee_earning_deduction_installments`
  ADD COLUMN `principal_amount` decimal(15,2) DEFAULT NULL AFTER `amount`,
  ADD COLUMN `interest_amount` decimal(15,2) DEFAULT NULL AFTER `principal_amount`;

-- DOWN
ALTER TABLE `employee_earning_deduction_installments`
  DROP COLUMN `interest_amount`,
  DROP COLUMN `principal_amount`;
