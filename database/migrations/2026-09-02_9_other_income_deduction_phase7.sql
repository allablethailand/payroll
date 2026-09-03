-- 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7: "Other Income"/"Other
-- Deduction" as a formal, aggregating bucket -- distinct from an ordinary free-text custom item
-- (which each gets its own unique CUSTOM:{name} code/report column, see
-- PayrollRunModel::resolveManualLineRow()'s own pre-existing docblock).
--
-- `is_other` tags an EXISTING custom item (ped_type_id IS NULL, custom_item_name/custom_item_type
-- already required) as belonging to the shared "Other Income"/"Other Deduction" bucket instead of
-- being its own unique line. The employee-typed custom_item_name is UNCHANGED and still shown
-- as-is in every per-line breakdown UI (so "ค่าปรับผิดสัญญาจ้าง" stays distinguishable row-to-row) --
-- only the synthetic `code` PayrollRunModel::resolveManualLineRow() derives for
-- calculation/report-grouping purposes becomes the fixed sentinel OTHER_INCOME/OTHER_DEDUCTION
-- (derived from custom_item_type, no separate value needed) instead of CUSTOM:{name}, so every
-- "Other" item across every employee collapses into ONE report column/one taxable-income bucket
-- regardless of what free text each admin typed.

START TRANSACTION;

ALTER TABLE `employee_earning_deductions`
  ADD COLUMN `is_other` tinyint(1) NOT NULL DEFAULT 0 AFTER `custom_item_type`;

ALTER TABLE `payroll_run_manual_lines`
  ADD COLUMN `is_other` tinyint(1) NOT NULL DEFAULT 0 AFTER `custom_item_type`;

COMMIT;

-- Rollback:
-- START TRANSACTION;
-- ALTER TABLE `employee_earning_deductions` DROP COLUMN `is_other`;
-- ALTER TABLE `payroll_run_manual_lines` DROP COLUMN `is_other`;
-- COMMIT;
