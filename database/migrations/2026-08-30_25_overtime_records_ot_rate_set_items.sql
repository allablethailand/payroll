-- Follow-up to 2026-08-30_24_ot_rate_sets.sql: `overtime_records.ot_rate_id` was still FK'd to the
-- now-retired flat `ot_rates` table (used by manual/imported overtime entry, OvertimeRecordModel +
-- TransactionDataPayAdapter -- a separate consumer from SyncPayResolver's own scope-based lookup,
-- discovered while fixing this feature's test fixtures). Repointed to `ot_rate_set_items` (the true
-- structural successor of a single `ot_rates` row -- one scope's rate within a Set) since
-- TransactionDataPayAdapter only ever reads the referenced row's `ot_scope_id` (never its actual
-- rate value -- OT pay amount is always computed via the scope-based Set-resolution path, same as a
-- genuine Origami sync row), so the FK's job is purely "which OT scope does this record belong to."
-- Confirmed both `overtime_records` and `ot_rates` are empty (0 rows) on the live DB before this
-- change -- zero data-migration risk.
ALTER TABLE `overtime_records` DROP FOREIGN KEY `fk_overtime_records_rate`;
ALTER TABLE `overtime_records` ADD CONSTRAINT `fk_overtime_records_rate` FOREIGN KEY (`ot_rate_id`) REFERENCES `ot_rate_set_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
