-- 2026-09-30: audit trail for Monthly Report payslip downloads (api/report.monthly-slip).
-- One row per download request. user_id/target_employee_id are deliberately NOT foreign keys:
-- an audit row must outlive the employee it names. comp_id is the company scope (FK per convention).

-- UP
CREATE TABLE IF NOT EXISTS `report_download_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `download_type` enum('single_pdf','employee_zip','monthly_zip') COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_employee_id` int(11) DEFAULT NULL,
  `target_year` smallint(5) unsigned NOT NULL,
  `target_month` tinyint(3) unsigned NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `downloaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rdl_comp_period` (`comp_id`,`target_year`,`target_month`),
  KEY `idx_rdl_user` (`user_id`),
  CONSTRAINT `fk_rdl_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN
DROP TABLE IF EXISTS `report_download_logs`;
