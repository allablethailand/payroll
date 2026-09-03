-- 2026-09-02, reply from Origami's own team re: "Map รอบการจ่ายเงินเดือน" (Payroll Schedule mapping) --
-- Origami chose the recommended external_cycle_code approach and has already shipped their side
-- (docs/PAYROLL_SYNC_API.md, 2026-09-01 revision, at C:\xampp\htdocs\origami\payroll\docs\ --
-- `payroll_period.external_cycle_code`, sent as a new top-level + per-`items[]` field, additive
-- under schema_version 1, null when the Origami admin hasn't set one for that period yet).
--
-- This migration adds the matching pieces on THIS side:
--   1. `payroll_cycles.external_cycle_code` -- the admin-entered code on the Payroll Schedule
--      settings form (PayrollCycleModel::save()), meant to be re-entered identically to whatever
--      the company's Origami admin set on their own Setup > Period screen. Nullable/optional --
--      a cycle with no code set is unaffected (PayrollCycleModel::matchForSyncProcess() falls back
--      to the existing frequency+cutoff+payment-day heuristic exactly as before this migration).
--   2. `payroll_sync_processes.external_cycle_code` -- stores whatever Origami actually sent for
--      this specific process (PayrollSyncModel::upsertProcess()), so matchForSyncProcess() can
--      compare it against payroll_cycles.external_cycle_code as an exact, unambiguous primary
--      match key before ever falling back to the heuristic.
--
-- No DB-level UNIQUE constraint on payroll_cycles.external_cycle_code (same convention as
-- cycle_name on this same table, which also has no DB unique index) -- duplicate-among-active-rows
-- is checked at the application layer instead (PayrollCycleModel::isExternalCycleCodeDuplicate()),
-- consistent with this table's own deleted_at soft-delete pattern (a DB unique index including
-- deleted_at wouldn't actually catch two ACTIVE rows sharing a code, since MySQL treats each NULL
-- deleted_at as distinct -- see CLAUDE.md's own note on this exact gotcha).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-02_2_payroll_cycle_external_code.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `payroll_cycles`
    ADD COLUMN `external_cycle_code` VARCHAR(100) NULL COMMENT 'Admin-entered code matching Origami''s own payroll_period.external_cycle_code, for exact sync-process matching (see PayrollCycleModel::matchForSyncProcess())' AFTER `payroll_frequency`;

ALTER TABLE `payroll_sync_processes`
    ADD COLUMN `external_cycle_code` VARCHAR(100) NULL COMMENT 'As sent by Origami (payroll_period.external_cycle_code), null when not set on their side for this period' AFTER `frequency_type`,
    ADD KEY `idx_payroll_sync_processes_external_cycle_code` (`comp_id`, `external_cycle_code`);
