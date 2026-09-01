-- 2026-08-31, same-day follow-up -- Origami's proposed `scheduled_item_occurrences[]` addition to
-- the Payroll Sync API (a per-installment breakdown of an Employee Item's summed value in
-- items[].item_values[], e.g. a loan installment) -- confirmed additive, no PAYLOAD_SCHEMA_VERSION
-- bump needed. Deliberately mirrors payroll_sync_items' own shape: keyed by process_id (not
-- run_id -- these belong to the ORIGAMI PROCESS, correlated to a payroll run via that run's own
-- sync_process_id, same as every other payroll_sync_* table), employee_id nullable + resolved the
-- SAME way payroll_sync_items resolves it (via items[].payroll_code, cross-referenced through
-- items[].emp_id since Origami's own emp_id is not something this app stores as a column anywhere
-- -- see PayrollSyncModel::replaceScheduledItemOccurrences()'s own docblock).
CREATE TABLE `payroll_sync_item_occurrences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL COMMENT 'NULL when the owning emp_id could not be resolved to an employee -- same convention as payroll_sync_items.employee_id',
  `origami_emp_id` bigint(20) DEFAULT NULL COMMENT 'Origami internal id as sent -- kept for traceability only, not matched on',
  `item_code` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'matches an items[].item_values[].item_code for the same employee/process',
  `item_ref_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'the whole obligation/schedule (e.g. a loan contract) -- same code repeats across every installment of it',
  `occurrence_code` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'unique per specific installment (item_ref_code + installment_no)',
  `installment_no` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `applied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_psio_process_emp_item` (`process_id`,`employee_id`,`item_code`),
  CONSTRAINT `fk_psio_process` FOREIGN KEY (`process_id`) REFERENCES `payroll_sync_processes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
