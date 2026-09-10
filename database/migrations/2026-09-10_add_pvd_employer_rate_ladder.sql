-- Batch 3A item 7a: company-level Provident Fund employer contribution ladder (rate by tenure).
-- Explicit request: a config table of "อายุงานตั้งแต่ (ปี) -> % นายจ้าง" tiers, replacing the whole
-- set on save (same pattern as approval_workflow_steps/holiday_assignments). ladder_type is a real
-- column (not hardcoded) so a future tier type (e.g. vesting) can reuse this same table.
--
-- Resolution priority when calculating an employee's TH_PVD employer contribution:
--   1. employees.pvd_employer_rate (per-employee override, wins outright if set)
--   2. this ladder, matched by years of tenure as of the run's period_end_date
--      (tenure = employees.pvd_start_date, fallback employment_date) -- only when
--      this company has at least one active row here (opt-in: 0 rows = ladder unused)
--   3. today's unchanged company/master TH_PVD rate (StatutoryCalculationEngine's own resolution)

-- UP
CREATE TABLE `company_pvd_employer_rate_ladders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `ladder_type` enum('employer_rate') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'employer_rate'
      COMMENT 'reserved for future ladder types, e.g. vesting',
  `min_service_years` decimal(5,2) NOT NULL
      COMMENT 'inclusive lower bound, years of tenure from pvd_start_date (fallback employment_date)',
  `max_service_years` decimal(5,2) DEFAULT NULL
      COMMENT 'exclusive upper bound; NULL = open-ended (last tier)',
  `rate_percent` decimal(5,2) NOT NULL COMMENT 'employer contribution % for this tier',
  `status` enum('active','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cperl_comp_type` (`comp_id`,`ladder_type`),
  CONSTRAINT `fk_cperl_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`)
      ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN
DROP TABLE IF EXISTS `company_pvd_employer_rate_ladders`;
