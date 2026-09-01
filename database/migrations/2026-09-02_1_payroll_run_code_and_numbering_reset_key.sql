-- 2026-09-02, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ" -- payroll_runs.run_code is the
-- first real CONSUMER of the Document Numbering settings (document_numbering_settings, PAYROLL_RUN
-- row) that has existed since 2026-08-23 but was never actually wired up to stamp a code onto
-- anything (see DocumentNumberingModel's own docblock, explicit about this being "a separate, much
-- larger task" left undone). See PayrollRunModel::create()/DocumentNumberingModel::generateNext().
--
-- run_code is nullable and NEVER backfilled for existing runs -- generated once, at creation time,
-- going forward only (same "no historical snapshot, starts from the day it ships" precedent this
-- project already documents for payroll_run_line_override_history-style features).
--
-- last_reset_key (on document_numbering_settings) tracks when the running counter was last reset,
-- so DocumentNumberingModel::generateNext() can actually honor reset_cycle='yearly'/'monthly'
-- (stores 'YYYY' or 'YYYY-MM' depending on which granularity applies; unused/NULL for 'never').
-- This column didn't exist before because nothing ever consumed current_number/reset_cycle to need
-- it.

ALTER TABLE `payroll_runs`
  ADD COLUMN `run_code` VARCHAR(30) NULL DEFAULT NULL AFTER `id`;

ALTER TABLE `payroll_runs`
  ADD UNIQUE KEY `uq_payroll_runs_comp_run_code` (`comp_id`, `run_code`);

ALTER TABLE `document_numbering_settings`
  ADD COLUMN `last_reset_key` VARCHAR(7) NULL DEFAULT NULL AFTER `current_number`;
