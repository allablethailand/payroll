-- 2026-09-04, Backlog Phase 9->10, T051: user confirmed "T051 ตามที่คุณเสนอเลยครับ" (go ahead with the
-- proposed approach). T051 asked whether Origami-synced pay items (กยศ/Student Loan opt-in, Diligence/
-- attendance-bonus, Trip Allowance/expenses, and any other SyncPayResolver-resolved line) need to be
-- recorded as a durable, per-employee transaction. Investigation confirmed: they do NOT today. Every
-- such line is computed fresh by SyncPayResolver::resolve() on each PayrollRunModel::recalculate() call
-- and only ever lives inside that ONE run's own payroll_run_details.earning_breakdown/
-- deduction_breakdown JSON -- there is no place in Employee Detail an admin can see this employee's own
-- history of these amounts across multiple pay periods. `employee_earning_deductions` (Income/
-- Deductions tab) and EmployeeRecurringEarningModel (Recurring Allowances) both confirmed to have ZERO
-- auto-write points from sync -- every row in both is 100% manually created by an admin today, so
-- neither is the right home for this (see PayrollSyncTransactionLogModel's own docblock for why this is
-- a NEW, purpose-built, read-only table instead of reusing either).
--
-- WHEN this gets written (the single most important design call, see PayrollRunModel::approve()'s own
-- comment at the write call site for the full reasoning): at the moment a run transitions INTO
-- 'approved', reading the ALREADY-PERSISTED payroll_run_details breakdown for that run (recalculate()
-- only ever runs on a 'draft' run -- once submitted/approved the breakdown is frozen, so this is safe),
-- filtered down to lines whose SyncPayResolver-assigned `source` key is 'sync' (every sync-resolved
-- line sets this, confirmed by reading SyncPayResolver.php directly -- OT/OT-scope lines, rule-driven
-- attendance events like late/absent/trip_allowance, and the generic item_values/catalog-match/CUSTOM:
-- fallback path all set 'source' => 'sync' consistently; a company's own ordinary manually-configured
-- earning/deduction type that isn't event-linked never gets this marker, so it correctly never lands
-- here). Rows are DELETED and NOT rewritten if the run is later reverted away from 'approved' (revert()
-- back to pending_approval/rejected/need_info, or reopen() back to draft from paid/locked) -- an
-- unapproved run's numbers are no longer a settled fact, matching this project's own
-- PayrollReportDataModel::assertRunState() convention that only approved+ data is safe to surface
-- outside the run's own draft-editing screen. A re-approval after revert/reopen writes fresh rows
-- again, naturally correcting the log.
--
-- comp_id/employee_id FK ON DELETE RESTRICT (this project's own hard convention for every company-
-- scoped table, CLAUDE.md). payroll_run_id FK ON DELETE CASCADE mirrors payroll_run_details' own
-- run_id FK exactly (fk_payroll_run_details_run, same ON DELETE CASCADE) -- this table is a derived,
-- run-scoped read cache of that data, so it should disappear the same way if the parent run ever did
-- (in practice PayrollRunModel::delete() only ever permits deleting a draft/cancelled run, which this
-- table never has rows for in the first place, since rows only ever get written at approve() time --
-- CASCADE here is a consistency mirror/safety net, not a load-bearing cleanup path).
--
-- item_name_th/item_name_en/remark are denormalized (copied at write time, not re-joined live) so the
-- history still reads sensibly even if the catalog item is later renamed/deleted -- same "denormalize
-- for durable history" precedent as payroll_run_payment_events' own amount columns.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_3_payroll_sync_transaction_log.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

CREATE TABLE `payroll_sync_transaction_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `payroll_run_id` int(11) NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `item_type` enum('earning','deduction') COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_code` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The resolved catalog/CUSTOM: code -- SyncPayResolver line[''code'']',
  `item_name_th` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name_en` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_item_code` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Origami''s own raw item_code, when present -- SyncPayResolver line[''sync_item_code''], NULL for OT/rule-driven-event lines which have no such field',
  `amount` decimal(14,2) NOT NULL,
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SyncPayResolver line[''note''] -- either Origami''s own real per-line remark (catalog/CUSTOM: lines) or a synthetic descriptive note (OT/rule-driven lines, e.g. sync_ot_weekday_2hours)',
  `data_source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sync',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pstl_comp_employee_period` (`comp_id`,`employee_id`,`pay_period_start`),
  KEY `idx_pstl_run` (`payroll_run_id`),
  CONSTRAINT `fk_pstl_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pstl_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pstl_run` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
