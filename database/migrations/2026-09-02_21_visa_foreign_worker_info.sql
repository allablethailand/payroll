-- 2026-09-02_21_visa_foreign_worker_info.sql
--
-- Extends the 2026-09-02_4 Origami candidates.php field batch, which deliberately deferred visa
-- details and foreign_worker_info ("no schema home anywhere in this app yet" -- see
-- EmployeeSyncer.php's own docblock at that time). Field shapes confirmed directly from Origami's
-- own api/hr/employees/candidates.php source (passport{}/visa{}/work_permit{}/foreign_worker_info{}
-- blocks), not guessed. Document scan URLs (passport/visa/work_permit .document_url/.document_name)
-- are DELIBERATELY still not stored -- this app has no viewer UI anywhere to open a synced
-- document yet, so the URLs would be inert data with nothing to use them; a separate follow-up if
-- ever requested.
--
-- passport_no/passport_expire_date/work_permit_no/date_work_permit_issue/date_work_permit_expire/
-- visa_type/date_visa_expire already exist (added before this session). This migration adds only
-- the genuinely NEW fields Origami's payload carries that this app has nowhere to put yet.
-- visa_type stays the existing free-text column (not converted to Origami's fixed 8-code enum --
-- that would be a bigger, separately-scoped UI change since this field is also manually editable by
-- No-HR-user companies who never sync at all); the resolved `type_name` string is written into it.

ALTER TABLE `employees`
  ADD COLUMN `passport_issued_place` VARCHAR(255) NULL DEFAULT NULL AFTER `passport_expire_date`,
  ADD COLUMN `passport_issue_date` DATE NULL DEFAULT NULL AFTER `passport_issued_place`,
  ADD COLUMN `work_permit_issued_place` VARCHAR(255) NULL DEFAULT NULL AFTER `date_work_permit_expire`,
  ADD COLUMN `visa_no` VARCHAR(100) NULL DEFAULT NULL AFTER `visa_type`,
  ADD COLUMN `visa_issued_place` VARCHAR(255) NULL DEFAULT NULL AFTER `visa_no`,
  ADD COLUMN `visa_issue_date` DATE NULL DEFAULT NULL AFTER `visa_issued_place`;

-- Thai-immigration-arrival-card-style data (Origami's own `m_employee_foreign`'s `non_thai_*`
-- columns) -- a genuinely large (12-field) block only ever relevant for the subset of employees
-- who are actual foreign workers, kept as its own 1:1 table rather than ballooning `employees`
-- further (same reasoning `employee_dependents`/`employee_documents` are their own tables, not
-- columns bolted onto `employees`) -- one row per employee, never more than one (matches Origami's
-- own m_employee_foreign shape).
CREATE TABLE `employee_foreign_worker_details` (
  `employee_id` INT(11) NOT NULL,
  `recruitment_agency` VARCHAR(255) NULL DEFAULT NULL,
  `arrival_date` DATE NULL DEFAULT NULL,
  `due_date` DATE NULL DEFAULT NULL,
  `arrival_card_no` VARCHAR(100) NULL DEFAULT NULL,
  `arrival_by_vehicle` VARCHAR(255) NULL DEFAULT NULL,
  `address` VARCHAR(255) NULL DEFAULT NULL,
  `soi` VARCHAR(255) NULL DEFAULT NULL,
  `province` VARCHAR(255) NULL DEFAULT NULL,
  `district` VARCHAR(255) NULL DEFAULT NULL,
  `sub_district` VARCHAR(255) NULL DEFAULT NULL,
  `tel_code` VARCHAR(20) NULL DEFAULT NULL,
  `tel` VARCHAR(50) NULL DEFAULT NULL,
  `updated_by` INT(11) NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_id`),
  CONSTRAINT `fk_employee_foreign_worker_details_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
