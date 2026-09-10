-- Batch 3A item 7b: SSO hospital + PVD investment plan as generic "company lookup list" tables
-- (select2 tags: pick existing or type new, auto-created on employee save via a shared
-- findOrCreateByName() model -- no real government/master hospital list is available to seed in
-- this environment, confirmed with the user; no Setup management page yet, BACKLOG'd), plus the
-- remaining PVD fields (fund manager, member no., end-membership date+reason) and a FIXED
-- (not free-text) SSO leaving-reason code matching the real official สปส.6-09 form's own 7
-- checkbox options (extracted directly from the official PDF at sso.go.th, not guessed):
--   1 = ลาออก/ละทิ้งหน้าที่โดยมีการติดต่อนายจ้างภายใน 6 วันทำงานติดต่อกัน
--   2 = สิ้นสุดระยะเวลาการจ้าง
--   3 = เลิกจ้าง/โครงการเกษียณก่อนกำหนด
--   4 = เกษียณอายุ
--   5 = ไล่ออก/ปลดออก/ให้ออกเนื่องจากกระทำความผิด/ละทิ้งหน้าที่โดยไม่มีการติดต่อนายจ้างภายใน 7 วันทำงานติดต่อกัน
--   6 = ตาย
--   7 = โอนย้ายสาขา
-- `employees.sso_hospital_id`/`pvd_fund_name` already existed with zero non-null rows in the dev DB
-- (confirmed before writing this) -- sso_hospital_id gains its FK in place (same "add the FK onto
-- the existing dead column" precedent as work_location_id/shift_id), no rename needed.

-- UP
CREATE TABLE `company_hospitals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_hospitals_comp` (`comp_id`,`status`),
  CONSTRAINT `fk_company_hospitals_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `company_pvd_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_pvd_plans_comp` (`comp_id`,`status`),
  CONSTRAINT `fk_company_pvd_plans_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `employees`
  ADD COLUMN `sso_leave_reason_code` tinyint(1) DEFAULT NULL COMMENT '1-7, official สปส.6-09 reason codes' AFTER `sso_employer_contribution_rate`,
  ADD COLUMN `pvd_plan_id` int(11) DEFAULT NULL AFTER `pvd_employer_rate`,
  ADD COLUMN `pvd_fund_manager` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ชื่อ บลจ. ผู้จัดการกองทุน' AFTER `pvd_plan_id`,
  ADD COLUMN `pvd_member_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `pvd_fund_manager`,
  ADD COLUMN `pvd_end_date` date DEFAULT NULL COMMENT 'วันสิ้นสุดสมาชิกภาพกองทุน' AFTER `pvd_member_no`,
  ADD COLUMN `pvd_end_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `pvd_end_date`,
  ADD CONSTRAINT `fk_employees_sso_hospital` FOREIGN KEY (`sso_hospital_id`) REFERENCES `company_hospitals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_employees_pvd_plan` FOREIGN KEY (`pvd_plan_id`) REFERENCES `company_pvd_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- DOWN
ALTER TABLE `employees`
  DROP FOREIGN KEY `fk_employees_sso_hospital`,
  DROP FOREIGN KEY `fk_employees_pvd_plan`,
  DROP COLUMN `sso_leave_reason_code`,
  DROP COLUMN `pvd_plan_id`,
  DROP COLUMN `pvd_fund_manager`,
  DROP COLUMN `pvd_member_no`,
  DROP COLUMN `pvd_end_date`,
  DROP COLUMN `pvd_end_reason`;
DROP TABLE IF EXISTS `company_pvd_plans`;
DROP TABLE IF EXISTS `company_hospitals`;
