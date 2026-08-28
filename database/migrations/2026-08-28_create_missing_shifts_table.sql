-- Fixes: Employee List 500 on production -- EmployeeModel::list() does
-- `LEFT JOIN shifts sh ON e.shift_id = sh.id`, and `shifts` was never created on production
-- (confirmed directly: `employees` itself has the shift_id column and index, but no FK to
-- `shifts` at all -- production's schema history simply skipped this table).
--
-- `shifts` has its own FK to `master_work_locations`, checked and also confirmed missing on
-- production -- created first here, in dependency order. Both use CREATE TABLE IF NOT EXISTS so
-- this is safe to re-run.
--
-- Column shape matches dev's current schema exactly: base CREATE TABLE `shifts`
-- (database/payroll.sql line ~8796) + the two later ALTERs that added origami_ref_id/data_source/
-- sync_batch_id (Master data sync columns) and works_monday..works_sunday (SetupRulesModel's
-- payableDaysForEmployee() weekly pattern, defaults Mon-Fri on / Sat-Sun off).
--
-- Does NOT add the missing `fk_employees_shift`/`fk_employees_work_location` constraints back
-- onto `employees` -- production's `employees` table is missing a much larger block of FK
-- constraints than just these two (department/role/position/branch/bank/report_to/address as
-- well, confirmed from the SHOW CREATE TABLE dump), which needs its own careful pass (existing
-- data must be validated against each FK before MySQL will allow adding it) rather than being
-- rushed into this one urgent hotfix. Tracked as a known follow-up, not fixed here.

CREATE TABLE IF NOT EXISTS `master_work_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL COMMENT 'ID บริษัทที่ล็อกอิน',
  `location_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_name_th` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_name_en` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comp_location_code` (`comp_id`,`location_code`,`deleted_at`),
  KEY `idx_work_locations_tenant` (`comp_id`,`deleted_at`,`status`),
  CONSTRAINT `fk_work_locations_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL COMMENT 'ID บริษัทที่ล็อกอิน',
  `shift_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shift_name_th` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shift_name_en` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `break_minutes` int(11) NOT NULL DEFAULT 0,
  `works_monday` tinyint(1) NOT NULL DEFAULT 1,
  `works_tuesday` tinyint(1) NOT NULL DEFAULT 1,
  `works_wednesday` tinyint(1) NOT NULL DEFAULT 1,
  `works_thursday` tinyint(1) NOT NULL DEFAULT 1,
  `works_friday` tinyint(1) NOT NULL DEFAULT 1,
  `works_saturday` tinyint(1) NOT NULL DEFAULT 0,
  `works_sunday` tinyint(1) NOT NULL DEFAULT 0,
  `origami_ref_id` bigint(20) DEFAULT NULL,
  `data_source` enum('sync','import','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `sync_batch_id` int(11) DEFAULT NULL,
  `work_location_id` int(11) DEFAULT NULL COMMENT 'default work location for employees on this shift',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comp_shift_code` (`comp_id`,`shift_code`,`deleted_at`),
  UNIQUE KEY `uq_shifts_origami_ref` (`origami_ref_id`,`comp_id`),
  KEY `idx_shifts_tenant` (`comp_id`,`deleted_at`,`status`),
  KEY `idx_shifts_work_location` (`work_location_id`),
  CONSTRAINT `fk_shifts_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_shifts_work_location` FOREIGN KEY (`work_location_id`) REFERENCES `master_work_locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_shifts_sync_batch` FOREIGN KEY (`sync_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
