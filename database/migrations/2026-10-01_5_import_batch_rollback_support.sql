-- 2026-10-01: support for rolling back a committed ad-hoc item import batch.
--  1. payroll_run_manual_lines.import_batch_id tags each line the Data Import (entity adhoc_item) wrote with its sync_batches row
--     (NULL = added by hand). New column, so there are no existing values to clean before the FK.
--  2. import_audit_logs.action gains 'rollback' so who undid a batch is on record.

-- UP
ALTER TABLE `payroll_run_manual_lines`
  ADD COLUMN `import_batch_id` int(11) DEFAULT NULL COMMENT 'sync_batches.id of the import that created this line' AFTER `created_by`,
  ADD KEY `idx_prml_import_batch` (`import_batch_id`),
  ADD CONSTRAINT `fk_prml_import_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `import_audit_logs`
  MODIFY COLUMN `action` enum('download_template','upload','map','validate','edit_row','commit','discard','rollback') COLLATE utf8mb4_unicode_ci NOT NULL;

-- DOWN
DELETE FROM `import_audit_logs` WHERE `action` = 'rollback';
ALTER TABLE `import_audit_logs`
  MODIFY COLUMN `action` enum('download_template','upload','map','validate','edit_row','commit','discard') COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE `payroll_run_manual_lines`
  DROP FOREIGN KEY `fk_prml_import_batch`,
  DROP KEY `idx_prml_import_batch`,
  DROP COLUMN `import_batch_id`;
