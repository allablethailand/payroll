-- 2026-10-01: staging area for the Import Framework -- an uploaded file is parsed into rows that the user can map, fix and
-- re-validate BEFORE anything touches live tables. Rows hold personal data, so discard deletes them (batch row stays as history).

-- UP
CREATE TABLE IF NOT EXISTS `import_staging_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `entity_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'relative to project root; NULL when the original could not be stored',
  `file_size` int(11) DEFAULT NULL,
  `mapping_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '{file header: internal field key}',
  `total_count` int(11) NOT NULL DEFAULT 0,
  `valid_count` int(11) NOT NULL DEFAULT 0,
  `error_count` int(11) NOT NULL DEFAULT 0,
  `warning_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('uploaded','mapped','validated','committing','committed','discarded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'uploaded',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `validated_at` timestamp NULL DEFAULT NULL,
  `committed_at` timestamp NULL DEFAULT NULL,
  `committed_batch_id` int(11) DEFAULT NULL COMMENT 'sync_batches.id created by the commit',
  PRIMARY KEY (`id`),
  KEY `idx_isb_comp_status` (`comp_id`,`status`),
  KEY `fk_isb_sync_batch` (`committed_batch_id`),
  CONSTRAINT `fk_isb_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_isb_sync_batch` FOREIGN KEY (`committed_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_staging_rows` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `row_no` int(11) NOT NULL COMMENT 'spreadsheet row number (header = 1)',
  `raw_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'parsed row keyed by the file header, never edited',
  `data_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'mapped row keyed by internal field key, editable',
  `status` enum('pending','valid','warning','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `messages_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edited` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_isr_batch_row` (`batch_id`,`row_no`),
  KEY `idx_isr_batch_status` (`batch_id`,`status`),
  CONSTRAINT `fk_isr_batch` FOREIGN KEY (`batch_id`) REFERENCES `import_staging_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN
DROP TABLE IF EXISTS `import_staging_rows`;
DROP TABLE IF EXISTS `import_staging_batches`;
