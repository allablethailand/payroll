-- Explicit request: "OT Rate เพิ่มให้สามารถ Assing รายบุคคลได้ด้วย เช่นคนนี้ Rate ไม่เหมือนเพื่อน ได้ทั้งตัวคูณ
-- และเป็นจำนวนเงิน...ให้ไป Set แยก ใน Employee ใน Tab ที่มีการติ๊กว่า ได้รับ OT ไหม ถ้ามีสิทธิ์ได้รับ OT ให้
-- เลือกเพิ่มว่า จากการตั้งค่าหลัก หรือจะตั้งค่าแยก ตามประเภท OT".
--
-- `employees.ot_rate_source` -- explicit binary choice (default vs custom), matching the user's own
-- wording ("เลือกเพิ่มว่า...") rather than an implicit presence-of-override-rows check -- lets an admin
-- toggle back to the company default without losing/deleting whatever custom rows they already typed
-- in (same "explicit mode flag, not implicit" precedent as this table's own multi-scope siblings).
ALTER TABLE `employees`
  ADD COLUMN `ot_rate_source` enum('default','custom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default' AFTER `ot_eligible`;

-- `employee_ot_rate_overrides` -- one row per (employee, ot_scope), same shape as `ot_rates` minus
-- the company-wide-only fields (ot_name_th/ot_name_en, status/soft-delete -- an override has no
-- audit/compliance need of its own, delete+reinsert-whole-set on every save, same pattern as
-- holiday_assignments/approval_workflow_step_approvers). A scope with NO override row for this
-- employee falls back to the company-wide `ot_rates` row for that scope -- same "unassigned = general,
-- explicitly assigned = scoped" convention already used for Holiday/Payslip Template assignment and
-- the T041 cross-cycle employees.cycle_id fix.
CREATE TABLE `employee_ot_rate_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `ot_scope_id` int(11) NOT NULL,
  `multiplier_rate` decimal(4,2) NOT NULL DEFAULT 1.50,
  `calculation_base` enum('hourly','daily') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hourly',
  `calculation_method` enum('multiplier','flat_amount') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'multiplier',
  `flat_amount_rate` decimal(10,2) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_ot_override_scope` (`employee_id`,`ot_scope_id`),
  KEY `idx_employee_ot_override_comp` (`comp_id`),
  CONSTRAINT `fk_employee_ot_override_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_ot_override_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_ot_override_scope` FOREIGN KEY (`ot_scope_id`) REFERENCES `master_ot_scope_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
