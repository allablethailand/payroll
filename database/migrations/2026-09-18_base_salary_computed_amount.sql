-- 2026-09-18, tiny-C: persists the amount the ENGINE produced, before a per-employee line override
-- replaced it, so the Adjustments table "ระบบ: x" sub-line prints a figure something really backs.
-- tiny-L6b own interim answer read `original_value` off the override history, which is an OLDER
-- OVERRIDE, not an engine figure, on any row whose first override predates that history.
--
-- Every earning/deduction/statutory line gets this for free as a `computed_amount` key inside the
-- breakdown JSON it already lives in -- no column needed there. Base salary is the one figure with
-- no JSON entry of its own: `base_salary_amount` is a plain decimal column and recalculate() writes
-- the value AFTER the override into it, so the pre-override figure has nowhere to go without this.
-- Nullable on purpose: NULL means no base-salary override on this row, which is every row of every
-- run calculated before today, and stays the common case after it.

-- UP
ALTER TABLE `payroll_run_details`
    ADD COLUMN `base_salary_computed_amount` decimal(15,2) DEFAULT NULL COMMENT 'The base salary recalculate() computed BEFORE a __base_salary__ line override replaced it. NULL = no override on this row (base_salary_amount is already the engine figure).' AFTER `base_salary_amount`;

-- DOWN
ALTER TABLE `payroll_run_details` DROP COLUMN `base_salary_computed_amount`;
