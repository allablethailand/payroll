-- 2026-10-01: opening YTD balances for employees who go live mid-year. ThPitCalculator::calculateActual() adds these to the
-- figures it otherwise sums from this system's own payroll_run_details, so the first period here is not treated as period 1 of the year.
-- One row per employee per tax year (a true unique key: this table is never soft-deleted, a re-import replaces the row).
-- No existing column gets a new FK, so no orphan clean-up is needed in UP.

-- UP
CREATE TABLE IF NOT EXISTS `employee_ytd_opening_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `tax_year` smallint(5) unsigned NOT NULL,
  `as_of_date` date NOT NULL COMMENT 'first day this system calculates; the balance covers everything before it',
  `periods_paid` smallint(5) unsigned NOT NULL COMMENT 'pay periods already paid in the old system this tax year',
  `ytd_taxable_gross` decimal(15,2) NOT NULL DEFAULT 0.00,
  `ytd_pit_withheld` decimal(15,2) NOT NULL DEFAULT 0.00,
  `ytd_sso_employee` decimal(15,2) DEFAULT NULL COMMENT 'reporting only, not used by the PIT calculation',
  `ytd_pvd_employee` decimal(15,2) DEFAULT NULL COMMENT 'reporting only, not used by the PIT calculation',
  `data_source` enum('import','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'import',
  `sync_batch_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eyob_employee_year` (`comp_id`,`employee_id`,`tax_year`),
  KEY `fk_eyob_employee` (`employee_id`),
  KEY `fk_eyob_sync_batch` (`sync_batch_id`),
  CONSTRAINT `fk_eyob_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_eyob_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_eyob_sync_batch` FOREIGN KEY (`sync_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN
DROP TABLE IF EXISTS `employee_ytd_opening_balances`;
