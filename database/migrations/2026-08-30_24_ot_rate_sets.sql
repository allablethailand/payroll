-- Explicit request: "การจัดการ OT ตอนนี้สร้างได้เรื่อยๆ ถ้าตอนที่นำไปคำนวณ ถ้าบันทึกข้อมูลซ้ำ แต่คนละ Rate
-- จะแก้ไขยังไง...ตอนกดบวกรายการ ให้ขึ้นมาเลยเป็นชุดของ OT Type แล้วมี form ในแต่ละ Type ให้ระบุ...เท่ากับว่า 1
-- ชุดข้อมูลมีทุก Type ให้จัดการ แต่สามารถจัดการแยกกันได้แต่ละ type ในแถวเดียวกัน และให้เพิ่มการ Assign ให้ด้วย ว่ามี
-- ผลกับแผนก ทีม ตำแหน่ง หรือพนักงานคนไหน และป้องกันการบันทึกซ้ำ...บังคับไปเลยว่าต้องมี Default".
--
-- Fully REPLACES the old flat `ot_rates` table (confirmed with the user: zero real rows exist on
-- this live company, no migration-of-data risk) -- one company-wide "OT Rate Set" now bundles ALL 3
-- OT types (weekday/weekend/holiday) as its own independently-configurable sub-rows
-- (ot_rate_set_items), and can be assigned to specific department/team/position/employee scopes
-- (ot_rate_set_assignments, same polymorphic-scope shape as holiday_assignments -- "team" added,
-- "shift" dropped, since neither concept applies the same way to OT eligibility). Resolution
-- priority for an employee matching more than one assigned scope, confirmed with the user:
-- employee > team > position > department (most-specific-wins, same convention as Holiday's own
-- employee>position>department>shift resolver).
--
-- Exactly ONE set per company must be `is_default` = the mandatory fallback for an OT-eligible
-- employee who matches no assignment at all -- enforced at the application layer (OtRateSetModel),
-- same "app-layer single-active enforcement" precedent as Employment Certificate Template's own
-- is_default. The Default set itself carries NO assignment rows (it IS the catch-all).
CREATE TABLE `ot_rate_sets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `name_th` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ot_rate_sets_comp` (`comp_id`,`deleted_at`,`status`),
  CONSTRAINT `fk_ot_rate_sets_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- One row per (set, OT type) -- "1 ชุดข้อมูลมีทุก Type ให้จัดการ แต่สามารถจัดการแยกกันได้แต่ละ type" -- same
-- multiplier/flat_amount/calculation_base shape the old `ot_rates` table had per row, just nested
-- under a set now instead of being the top-level unit itself.
CREATE TABLE `ot_rate_set_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `set_id` int(11) NOT NULL,
  `ot_scope_id` int(11) NOT NULL,
  `calculation_method` enum('multiplier','flat_amount') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'multiplier',
  `multiplier_rate` decimal(4,2) NOT NULL DEFAULT 1.50,
  `calculation_base` enum('hourly','daily') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hourly',
  `flat_amount_rate` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ot_rate_set_items_scope` (`set_id`,`ot_scope_id`),
  CONSTRAINT `fk_ot_rate_set_items_set` FOREIGN KEY (`set_id`) REFERENCES `ot_rate_sets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ot_rate_set_items_scope` FOREIGN KEY (`ot_scope_id`) REFERENCES `master_ot_scope_types` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Polymorphic assignment, one row per (set, scope) -- "ป้องกันการบันทึกซ้ำ" enforced at the
-- application layer across EVERY active set (not just within one), same "findConflictingAssignment()"
-- precedent Payslip/Employment Certificate Template's own "Assign To" already established -- a
-- department/team/position/employee can only ever be claimed by ONE active set at a time.
CREATE TABLE `ot_rate_set_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `set_id` int(11) NOT NULL,
  `scope_type` enum('department','team','position','employee') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ot_rate_set_assignments_set` (`set_id`),
  KEY `idx_ot_rate_set_assignments_scope` (`scope_type`,`scope_id`),
  CONSTRAINT `fk_ot_rate_set_assignments_set` FOREIGN KEY (`set_id`) REFERENCES `ot_rate_sets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Explicit request: "ถ้าเลือกจาก OT ของระบบ จะมีให้เลือกเพิ่มว่า OT ไหน" -- an employee on
-- ot_rate_source='default' can explicitly pick WHICH set applies to them, overriding the
-- employee>team>position>department auto-resolution below. NULL (the common case) = auto-resolve.
ALTER TABLE `employees`
  ADD COLUMN `assigned_ot_rate_set_id` int(11) DEFAULT NULL AFTER `ot_rate_source`,
  ADD CONSTRAINT `fk_employees_ot_rate_set` FOREIGN KEY (`assigned_ot_rate_set_id`) REFERENCES `ot_rate_sets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
