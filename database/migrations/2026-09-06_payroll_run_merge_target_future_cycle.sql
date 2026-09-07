-- Explicit request: "ปรับ Process ที่มีการสร้างรอบเองในฝั่ง Payroll ให้เป็นไปในแนวทางเดียวกัน" -- extends
-- the existing "อ้างอิงถึงรอบ" (reference a round) merge-target mechanism (payroll_runs.merge_target_run_id,
-- 2026-09-01) to also accept a round that doesn't exist yet, for the one and only kind of "future
-- round" this app can identify in advance: the next occurrence of a recurring Payroll Cycle (keyed
-- the same way PayrollRunModel::isDuplicatePeriod() already keys "is this the same round" --
-- cycle_id + period_start_date + period_end_date). See PayrollRunModel::resolveMergeTargetSpec()'s
-- own docblock for the full mechanism.
--
-- Mutually exclusive with merge_target_run_id at the application layer (never both set at once) --
-- the moment a real run appears matching this cycle+period, PayrollRunModel::create()'s own
-- auto-detect resolves these 3 columns back to NULL and sets merge_target_run_id instead, so a
-- "future" target never stays a distinct code path for long -- it collapses into the
-- already-existing, already-tested "reference an existing round" case as soon as it can.
ALTER TABLE `payroll_runs`
  ADD COLUMN `merge_target_cycle_id` INT(11) NULL DEFAULT NULL AFTER `merge_target_run_id`,
  ADD COLUMN `merge_target_period_start_date` DATE NULL DEFAULT NULL AFTER `merge_target_cycle_id`,
  ADD COLUMN `merge_target_period_end_date` DATE NULL DEFAULT NULL AFTER `merge_target_period_start_date`,
  ADD KEY `idx_payroll_runs_merge_target_cycle_period` (`merge_target_cycle_id`, `merge_target_period_start_date`, `merge_target_period_end_date`),
  ADD CONSTRAINT `fk_payroll_runs_merge_target_cycle` FOREIGN KEY (`merge_target_cycle_id`) REFERENCES `payroll_cycles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
