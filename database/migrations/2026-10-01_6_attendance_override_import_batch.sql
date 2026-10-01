-- 2026-10-01: tag payroll_run_sync_item_overrides rows created by the Data Import (entity attendance_summary) with the import batch
-- that wrote them, so a whole batch can be rolled back. NULL = entered by hand. New column, so no existing values to clean before the FK.

-- UP
ALTER TABLE `payroll_run_sync_item_overrides`
  ADD COLUMN `import_batch_id` int(11) DEFAULT NULL COMMENT 'sync_batches.id of the import that created this row' AFTER `created_by`,
  ADD KEY `idx_prsio_import_batch` (`import_batch_id`),
  ADD CONSTRAINT `fk_prsio_import_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- DOWN
ALTER TABLE `payroll_run_sync_item_overrides`
  DROP FOREIGN KEY `fk_prsio_import_batch`,
  DROP KEY `idx_prsio_import_batch`,
  DROP COLUMN `import_batch_id`;
