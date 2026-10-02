-- 2026-10-02: shifts.base_hours = hours/day used as the divisor for attendance-deduction rates (absent/late/unpaid leave) when Origami sends no working_mins. New column with a default, so no existing values to clean.

-- UP
ALTER TABLE `shifts`
  ADD COLUMN `base_hours` DECIMAL(4,2) NOT NULL DEFAULT 8.00 COMMENT 'Standard paid hours per day for deduction rates (e.g. 8.00 even if the shift is 7.5h)' AFTER `break_minutes`;

-- DOWN
ALTER TABLE `shifts`
  DROP COLUMN `base_hours`;
