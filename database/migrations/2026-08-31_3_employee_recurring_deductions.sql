-- Explicit request: "หน้า Employee Detail เพิ่มรายหักประจำด้วยครับ และนำไปเพิ่มใน ตรงสรุปรายได้ประจำ ด้วย" --
-- mirrors `employee_recurring_earnings` exactly (same shape, same suspend-window mechanism, same
-- "own new table, not a mode of employee_earning_deductions" reasoning -- that table is inherently
-- installment-based with a finite schedule and genuinely doesn't fit a deduction that recurs
-- indefinitely with no end date, e.g. a recurring uniform/locker fee). See
-- EmployeeRecurringDeductionModel's own docblock for the full design (a near-mirror of
-- EmployeeRecurringEarningModel, restricted to item_type='deduction' instead of 'earning').
CREATE TABLE `employee_recurring_deductions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `ped_type_id` int(11) NOT NULL COMMENT 'payroll_earning_deduction_types.id, restricted at the application layer to item_type=deduction AND calculation_method=fixed_amount',
  `amount` decimal(15,2) NOT NULL COMMENT 'this employee''s own flat monthly amount -- independent of payroll_earning_deduction_types.fixed_amount, which is only a company-wide default/reference',
  `effective_date` date NOT NULL,
  `suspended_from` date DEFAULT NULL COMMENT 'both suspended_from/suspended_to set together or neither -- see table comment',
  `suspended_to` date DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erd_employee` (`employee_id`),
  KEY `idx_erd_ped_type` (`ped_type_id`),
  CONSTRAINT `fk_erd_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_erd_ped_type` FOREIGN KEY (`ped_type_id`) REFERENCES `payroll_earning_deduction_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
