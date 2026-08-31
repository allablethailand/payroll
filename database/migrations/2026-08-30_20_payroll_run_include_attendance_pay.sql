-- Phase 8, T041 (real gap found and confirmed via a live grep of recalculate()'s own $isIncentive
-- branch, which explicitly documents "No attendance bonus, no sync-derived lines, ever" for an
-- off-cycle/incentive run): there was no way to create an off-cycle run that pays OT/trip
-- allowance (or any other sync-derived attendance EARNING) through the real rate engine -- only a
-- hand-typed manual line with no calculation behind it. New opt-in toggle, same additive pattern as
-- include_base_salary/include_standing_items (settable only when run_purpose='incentive', DEFAULT 0
-- preserves every pre-existing incentive run's behavior unchanged on backfill).
ALTER TABLE `payroll_runs`
  ADD COLUMN `include_attendance_pay` tinyint(1) NOT NULL DEFAULT 0 AFTER `include_standing_items`;
