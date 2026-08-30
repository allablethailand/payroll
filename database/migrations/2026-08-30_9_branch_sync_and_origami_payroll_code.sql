-- 2026-08-30, Origami's `api/hr/employees/candidates.php` gained several new fields (branch_ref_id/
-- branch_name, payroll_code, emp_tel, title, nickname, nationality, religion, marital_status,
-- military_service, idcard/idcard_issued/idcard_expire, pass_pro/pass_pro_date, deduct_sso,
-- photo_url, signature_drawing, spouse, children) -- see EmployeeSyncer's own docblock for how each
-- is consumed. Two of them have no home in the existing schema at all:
--
-- 1. `structure_branches` has NEVER been synced from Origami before (unlike department/position/
--    team, which already carry `origami_ref_id`/`data_source`/`sync_batch_id` from earlier rounds)
--    -- widened here with the EXACT same 3 columns, same types/index/FK shape as
--    `structure_departments`' own (`uq_branches_origami_ref` UNIQUE on origami_ref_id alone, not
--    composite with comp_id -- Origami's own ref_id is already globally unique on its side, same
--    reasoning as every other entity's own origami_ref_id column in this app).
-- 2. `employees.origami_payroll_code` -- the new `payroll_code` field is a DIFFERENT concept from
--    this app's own `employees.employee_no` (which the candidate API's existing `employee_no` field,
--    from `emp_code`, already maps to for MATCHING purposes -- see EmployeeSyncer::findByEmployeeNo()).
--    Confirmed via AskUserQuestion: store it as its own reference-only column, do NOT change existing
--    matching logic (which stays on employee_no/emp_code) -- this field currently has no confirmed
--    consumer/use case on this app's side beyond "keep it visible for later".
ALTER TABLE `structure_branches`
  ADD COLUMN `origami_ref_id` BIGINT(20) DEFAULT NULL AFTER `location`,
  ADD COLUMN `data_source` ENUM('sync','import','manual') NOT NULL DEFAULT 'manual' AFTER `origami_ref_id`,
  ADD COLUMN `sync_batch_id` INT(11) DEFAULT NULL AFTER `data_source`,
  ADD UNIQUE KEY `uq_branches_origami_ref` (`origami_ref_id`),
  ADD CONSTRAINT `fk_branches_sync_batch` FOREIGN KEY (`sync_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `employees`
  ADD COLUMN `origami_payroll_code` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'reference-only -- Origami''s own employee_payroll.emp_payroll_code, distinct from employees.employee_no (which stays the actual matching key). Not used for matching.' AFTER `origami_ref_id`;
