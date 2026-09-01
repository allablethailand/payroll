-- 2026-08-31, same-day follow-up (explicit report from the Origami dev team: their admins can now
-- "pull back" a process they already pushed to us and re-edit/re-push it, with no real-time
-- notification to Payroll). Investigating surfaced a real, separate data-integrity gap on OUR own
-- receiving side: PayrollSyncModel::ingest()/upsertProcess() had NO guard against re-ingesting a
-- process whose origami_process_id was already linked to a payroll_runs row -- it silently
-- overwrote payroll_sync_processes/payroll_sync_items/payroll_sync_employee_status regardless of
-- that run's state, and PayrollRunModel::recalculate() reads payroll_sync_items LIVE every time --
-- so a re-push after our admin already pulled the process into a draft run could silently change
-- that run's own numbers on its next recalculate(), with zero warning. This table is the guard's
-- own audit trail: every time ingest() detects and BLOCKS an overwrite attempt like this, the
-- attempted payload is preserved here (never discarded) so an admin can review it and explicitly
-- decide to apply it (PayrollSyncModel::applyBlockedUpdate()) or dismiss it, instead of it being
-- silently applied or silently lost.
CREATE TABLE `payroll_sync_blocked_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `process_row_id` int(11) NOT NULL COMMENT 'payroll_sync_processes.id -- the row this blocked push targeted, left untouched',
  `linked_run_id` int(11) NOT NULL COMMENT 'payroll_runs.id -- the run already using this process, the reason the push was blocked',
  `attempted_payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The full JSON body Origami pushed, preserved verbatim so nothing is lost while blocked',
  `status` enum('pending','applied','dismissed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `received_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_psbu_comp_status` (`comp_id`,`status`),
  KEY `idx_psbu_process_row` (`process_row_id`),
  CONSTRAINT `fk_psbu_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_psbu_process` FOREIGN KEY (`process_row_id`) REFERENCES `payroll_sync_processes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_psbu_run` FOREIGN KEY (`linked_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
