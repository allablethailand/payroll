-- 2026-10-01: insert-only audit trail for every Import Framework action (upload/validate/commit/template download/...).
-- user_id/batch_id are deliberately NOT foreign keys: an audit row must outlive the employee and the batch it names.

-- UP
CREATE TABLE IF NOT EXISTS `import_audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `entity_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` enum('download_template','upload','map','validate','edit_row','commit','discard') COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` enum('success','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `batch_id` int(11) DEFAULT NULL COMMENT 'sync_batches.id or import_staging_batches.id depending on flow; no FK by design',
  `route` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'file_name, mapped_fields, total/success/failed counts',
  `performed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ial_comp_time` (`comp_id`,`performed_at`),
  KEY `idx_ial_user` (`user_id`),
  KEY `idx_ial_batch` (`batch_id`),
  CONSTRAINT `fk_ial_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN
DROP TABLE IF EXISTS `import_audit_logs`;
