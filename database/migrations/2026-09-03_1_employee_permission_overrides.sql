-- Platform Hardening Phase 3 (per-user permission overrides): a per-employee grant or deny of a
-- specific permission_key, overriding whatever their role would normally give them. Fully additive
-- to the existing role_permissions system -- an employee with no row here behaves identically to
-- today, since PermissionModel::checkPermission() checks this table FIRST and only falls through to
-- the existing role-based lookup when no override row exists for that employee+permission pair.
--
-- allow_scope/detail_level mirror role_permissions' own columns (same enum values, same meaning) --
-- only populated when effect='grant' (a 'deny' override needs no scope, it's an outright refusal).
--
-- comp_id is redundant with employees.comp_id but kept directly on this table (same precedent as
-- every other comp-scoped table in this app) so a lookup never needs to join through employees just
-- to confirm company scoping.

CREATE TABLE `employee_permission_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `effect` enum('grant','deny') COLLATE utf8mb4_unicode_ci NOT NULL,
  `allow_scope` enum('all','own_department','own_only') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detail_level` enum('summary','full') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_permission` (`employee_id`,`permission_id`),
  KEY `idx_epo_permission` (`permission_id`),
  KEY `idx_epo_comp` (`comp_id`),
  CONSTRAINT `fk_epo_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_epo_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_epo_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
