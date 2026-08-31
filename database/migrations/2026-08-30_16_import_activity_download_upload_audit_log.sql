-- 2026-08-30, Phase 5 follow-up, explicit request: "เก็บประวัติการ Download ข้อมูลออกจากระบบ และการ
-- Import ข้อมูลเข้าระบบเรียบร้อยแล้วใช่ไหมครับ เก็บตาม Format Log ที่ควรเก็บเพื่อให้สามารถ Audit ต่อได้" --
-- Import (upload) was already logged via sync_batches, but with none of the audit fields
-- report_export_logs already proved out for this exact purpose (2026-08-29, "โดยใคร Device อะไร IP
-- อะไร เบราเซอร์อะไร และ Download จากที่ไหน") -- who/when/what only, no device/IP/browser. Download
-- (the Import Template button) was not logged at all. Both fixed the same way, reusing the SAME
-- audit shape report_export_logs already established (EmployeeLoginLogModel::parseUserAgent(),
-- not reinvented) rather than inventing a new convention:
--   1. sync_batches widened with the same 6 device/IP columns report_export_logs already has --
--      applies to EVERY batch (sync AND import alike), not just import; a scheduled Origami sync
--      pull (no browser request) simply logs these as NULL, same as `triggered_by` already can be.
--   2. `import_template_download_logs` is a NEW, dedicated table for the Download Template action --
--      NOT folded into report_export_logs (that table's own shape -- report_code from
--      ReportRegistry, payroll_run_id, period_year/period_month -- doesn't fit a template download,
--      same "don't force a shared write schema" reasoning DocumentDeliveryLogModel's own docblock
--      already established for combining Payslip/Employment Certificate history). Read-side
--      unification with sync_batches (source='import') happens at the READ layer instead
--      (ImportActivityLogModel::list(), a UNION ALL) -- same precedent again.
ALTER TABLE `sync_batches`
    ADD COLUMN `ip_address` VARCHAR(45) NULL AFTER `triggered_by`,
    ADD COLUMN `device_type` VARCHAR(20) NULL AFTER `ip_address`,
    ADD COLUMN `os_name` VARCHAR(30) NULL AFTER `device_type`,
    ADD COLUMN `browser_name` VARCHAR(50) NULL AFTER `os_name`,
    ADD COLUMN `browser_version` VARCHAR(20) NULL AFTER `browser_name`,
    ADD COLUMN `user_agent` VARCHAR(500) NULL AFTER `browser_version`;

CREATE TABLE IF NOT EXISTS `import_template_download_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `comp_id` INT NOT NULL,
  `entity_type` VARCHAR(30) NOT NULL COMMENT 'attendance/leave/overtime -- which template was downloaded',
  `file_name` VARCHAR(255) NOT NULL,
  `downloaded_by` INT NULL COMMENT 'employees.id, no FK -- same soft-reference convention report_export_logs.generated_by already uses',
  `ip_address` VARCHAR(45) NULL,
  `device_type` VARCHAR(20) NULL,
  `os_name` VARCHAR(30) NULL,
  `browser_name` VARCHAR(50) NULL,
  `browser_version` VARCHAR(20) NULL,
  `user_agent` VARCHAR(500) NULL,
  `source` VARCHAR(30) NULL COMMENT 'which screen triggered the download, e.g. manual_entry_import_tab',
  `downloaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_import_template_download_logs_lookup` (`comp_id`, `entity_type`, `downloaded_at`),
  CONSTRAINT `fk_import_template_download_logs_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
