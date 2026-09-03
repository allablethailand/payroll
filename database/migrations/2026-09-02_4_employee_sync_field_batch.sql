-- 2026-09-02, Origami candidates.php 5-field-group batch (emergency_contact, employment_type,
-- SSO employee/employer rate override, current/house-registration address, foreign worker info).
-- See EmployeeSyncer.php's own docblock for the full field-mapping decisions this migration backs.

-- 1) Employment Type: company-defined classification (e.g. "รายเดือน"/"รายวัน"/"สัญญาจ้าง"), synced
--    from Origami's m_employee_type (employment_type_ref_id/_code/_name) -- genuinely NOT the same
--    concept as employees.employment_type (fixed enum full_time/part_time/daily/internship, which
--    stays untouched). Mirrors structure_departments/structure_positions exactly (same auto-create-
--    on-sync pattern via origami_ref_id).
CREATE TABLE `structure_employment_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL COMMENT 'ID บริษัทที่ล็อกอิน',
  `employment_type_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `origami_ref_id` bigint(20) DEFAULT NULL,
  `data_source` enum('sync','import','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `sync_batch_id` int(11) DEFAULT NULL,
  `employment_type_name_th` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `employment_type_name_en` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comp_employment_type_code` (`comp_id`,`employment_type_code`,`deleted_at`),
  UNIQUE KEY `uq_employment_types_origami_ref` (`origami_ref_id`,`comp_id`),
  KEY `idx_employment_types_tenant` (`comp_id`,`deleted_at`,`status`),
  KEY `fk_employment_types_sync_batch` (`sync_batch_id`),
  CONSTRAINT `fk_employment_types_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_employment_types_sync_batch` FOREIGN KEY (`sync_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

ALTER TABLE `employees`
  ADD COLUMN `employment_type_id` int(11) DEFAULT NULL AFTER `employment_type`;

ALTER TABLE `employees`
  ADD KEY `fk_employees_employment_type` (`employment_type_id`),
  ADD CONSTRAINT `fk_employees_employment_type` FOREIGN KEY (`employment_type_id`) REFERENCES `structure_employment_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- 2) SSO employer-side rate override, per employee. Symmetric to the existing employee-side
--    `sso_contribution_rate` column (which already exists but was, until this batch, never actually
--    read by StatutoryCalculationEngine -- see that class's own 2026-09-02 docblock update). NULL =
--    no override, fall back to company_statutory_settings then the master rate, same precedence
--    StatutoryCalculationEngine already applies for company_statutory_settings vs. master.
ALTER TABLE `employees`
  ADD COLUMN `sso_employer_contribution_rate` decimal(5,2) DEFAULT NULL AFTER `sso_contribution_rate`;
