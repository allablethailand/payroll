-- Origami PAYROLL_SYNC_API.md, 2026-08-31 revision: new top-level `attribution` field on the
-- inbound sync payload -- confirmed and already sending on Origami's side. Only meaningful when
-- `run_kind = 'supplemental'` (a standalone/ad-hoc Origami cycle, e.g. OT-only/Trip-only). Tells
-- us whether that batch's amount should be folded into a SPECIFIC regular cycle's own gross pay
-- before withholding ("merge") or withheld independently on its own ("separate"), or left as a
-- fully stand-alone off-cycle payment (`attribution` itself absent/null -- today's existing,
-- unchanged behavior).
--
-- `attribution_target_origami_process_id` mirrors how `origami_process_id` itself is already
-- stored on this table -- Origami's own natural key for the REGULAR cycle this supplemental batch
-- should be attributed to, resolved against this SAME table's own `origami_process_id` unique key
-- to find our own linked payroll_runs row (see PayrollRunModel::mergeSupplementalIntoRun()).
-- `attribution_target_process_no` is display-only (Pending Pull UI badge), never used for matching.
-- `attribution_tax_treatment` is NULL when there's no attribution at all (matches Origami's own
-- `attribution: null` case) -- NOT a 3rd enum value, so "no attribution" and "attribution with an
-- as-yet-undetermined treatment" can never be confused.
ALTER TABLE `payroll_sync_processes`
  ADD COLUMN `attribution_target_origami_process_id` BIGINT(20) NULL DEFAULT NULL AFTER `run_kind`,
  ADD COLUMN `attribution_target_process_no` VARCHAR(50) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `attribution_target_origami_process_id`,
  ADD COLUMN `attribution_tax_treatment` ENUM('merge','separate') COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `attribution_target_process_no`,
  -- Phase C: records that this supplemental process's items were merged into an EXISTING run
  -- (payroll_runs.id) rather than pulled as its own standalone run -- distinct from the existing
  -- "pulled as its own run" mechanism (payroll_runs.sync_process_id pointing back at THIS row),
  -- since a merged process is never itself the primary sync_process_id of any run. Both this
  -- column and a real sync_process_id linkage mean "no longer in Pending Pull" -- pendingList()'s
  -- own query is updated to check both.
  ADD COLUMN `merged_into_run_id` INT(11) NULL DEFAULT NULL AFTER `attribution_tax_treatment`;
