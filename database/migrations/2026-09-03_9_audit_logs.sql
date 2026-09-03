-- Platform Hardening Phase 6 (pilot): field-level audit log. One row per CHANGED field on an
-- update (field_name set); one row per whole-row event on create/delete (field_name NULL,
-- old_value/new_value holds the full JSON-encoded row). No generic audit mechanism existed
-- anywhere in this app before this -- see AuditLogModel's own docblock for the diff contract.
--
-- Pilot scope (confirmed with the user, NOT app-wide): EmployeeModel::save(), CompanyProfileModel::
-- save(), and the Payroll Configuration rate/rule models (PayrollEarningDeductionTypeModel,
-- PayrollPolicyModel, AttendanceDeductionRuleModel). Other models are wired in a later round, if
-- ever -- this table's shape is generic enough to support that without a schema change.

CREATE TABLE `audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `table_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int(11) NOT NULL,
  `action` enum('create','update','delete') COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NULL for create/delete (whole-row event); one row per changed field on update',
  `old_value` text COLLATE utf8mb4_unicode_ci,
  `new_value` text COLLATE utf8mb4_unicode_ci,
  `performed_by` int(11) DEFAULT NULL COMMENT 'employees.id, no FK -- same soft-reference convention report_export_logs.generated_by already uses',
  `source` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'web/sync/import/system',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_lookup` (`comp_id`,`table_name`,`record_id`,`performed_at`),
  CONSTRAINT `fk_audit_logs_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('audit_log','view','audit_log.view','ดูประวัติการเปลี่ยนแปลงข้อมูล','View Audit Log',1,280)
ON DUPLICATE KEY UPDATE `permission_key` = `permission_key`;
