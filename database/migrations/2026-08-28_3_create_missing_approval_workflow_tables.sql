-- Fixes another confirmed production 500 (real Apache/PHP error log):
--   PDOException: Table 'payroll.approval_workflows' doesn't exist
--   (ApprovalWorkflowModel::getByDocumentType(), /setup/document-approval)
--
-- Same situation as the shifts/permissions/payslip_templates migrations before this one: the
-- whole Approval Workflow feature (8 tables) never made it to production. All 8 CREATE TABLE
-- statements below are pulled via SHOW CREATE TABLE directly off the current dev database, which
-- reflects the true final shape after 3 full rework rounds this feature went through (see
-- CLAUDE.md's "2026-08-23, rework ใหญ่ทั้งชุด" section) -- not hand-reconstructed from history.
--
-- Only `approval_document_types` needs seed data (3 rows: PAYROLL_RUN_APPROVAL,
-- SLIP_REQUEST_APPROVAL, EMPLOYMENT_CERTIFICATE_APPROVAL) -- it's global master data. Every other
-- table here is per-company CONFIGURATION an admin sets up through the Approval Workflow settings
-- UI, not something to pre-seed.
--
-- Dependency order: approval_document_types (standalone) -> approval_workflows (needs companies)
-- -> approval_workflow_document_types (needs approval_workflows + approval_document_types) ->
-- approval_workflow_steps (needs approval_workflows) -> approval_workflow_step_approvers (needs
-- approval_workflow_steps) -> approval_requests (needs companies + approval_document_types +
-- approval_workflows) -> approval_request_step_approvers (needs approval_requests) ->
-- approval_request_logs (needs approval_requests).
--
-- REMINDER: run this with --default-character-set=utf8mb4 -- without it, mysql silently corrupts
-- the Thai seed data below (confirmed the hard way on the previous 2 migrations tonight).
--
-- This is very likely still not the complete gap -- keep checking each page as you click into it,
-- or better, run `SHOW TABLES;` on production and diff against database/payroll.sql's ~88 tables
-- to find everything still missing in one pass.

CREATE TABLE IF NOT EXISTS `approval_document_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_th` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_doc_type_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Master list of document/request types an approval workflow can be bound to (e.g. payroll run approval, slip request approval). Global, not per-company, since these map 1:1 with actual code paths in the system.';

INSERT INTO `approval_document_types` (`code`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('PAYROLL_RUN_APPROVAL','อนุมัติงวดเงินเดือน','Payroll Run Approval','1','1'),
('SLIP_REQUEST_APPROVAL','อนุมัติคำขอสลิปเงินเดือน','Slip Request Approval','1','2'),
('EMPLOYMENT_CERTIFICATE_APPROVAL','อนุมัติคำขอใบรับรองการทำงาน','Employment Certificate Request Approval','1','3');

CREATE TABLE IF NOT EXISTS `approval_workflows` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `workflow_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_workflows_comp_status` (`comp_id`,`status`),
  CONSTRAINT `fk_approval_workflows_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Header record for one configured approval workflow (an ordered set of steps) belonging to a company.';

CREATE TABLE IF NOT EXISTS `approval_workflow_document_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_id` int(11) NOT NULL,
  `document_type_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workflow_doc_type` (`workflow_id`,`document_type_code`),
  KEY `idx_awdt_doc_type` (`document_type_code`),
  CONSTRAINT `fk_awdt_doc_type` FOREIGN KEY (`document_type_code`) REFERENCES `approval_document_types` (`code`) ON UPDATE CASCADE,
  CONSTRAINT `fk_awdt_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `approval_workflows` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Many-to-many: which document types a workflow applies to. App layer enforces that a document_type_code maps to at most one ACTIVE workflow per company at a time (cannot be a DB constraint the same way deleted_at-scoped uniqueness cannot elsewhere in this project).';

CREATE TABLE IF NOT EXISTS `approval_workflow_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `step_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_type` enum('and','or','finish') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'and',
  `requires_previous_step` tinyint(1) NOT NULL DEFAULT 0,
  `joint_approve_mode` enum('any','all') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any' COMMENT 'Only meaningful when approver_type=role and the role has more than one active employee: all = every current holder must approve this step before it advances, any = the first action decides.',
  `status` enum('active','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aws_workflow_step_order` (`workflow_id`,`step_order`),
  CONSTRAINT `fk_aws_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `approval_workflows` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Ordered steps within a workflow. Whole set is replaced (delete+reinsert) on each workflow save, same pattern as employee_earning_deduction_installments -- this is config, not history (see approval_request_logs for the history side).';

CREATE TABLE IF NOT EXISTS `approval_workflow_step_approvers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `step_id` int(11) NOT NULL,
  `approver_type` enum('user','role') COLLATE utf8mb4_unicode_ci NOT NULL,
  `approver_id` int(11) NOT NULL COMMENT 'employees.id when approver_type=user, structure_roles.id when approver_type=role. Polymorphic by design, no single FK possible.',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_awsa_step` (`step_id`),
  CONSTRAINT `fk_awsa_step` FOREIGN KEY (`step_id`) REFERENCES `approval_workflow_steps` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `approval_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `workflow_id` int(11) NOT NULL,
  `document_type_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_id` int(11) NOT NULL COMMENT 'id of the actual document row; meaning depends on document_type_code (e.g. payroll_runs.id for PAYROLL_RUN_APPROVAL). Polymorphic by design, no FK.',
  `reference_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Human-readable snapshot (e.g. run name/period) captured at request creation, so the monitor page does not need a different join per document_type_code.',
  `current_step_order` int(11) NOT NULL DEFAULT 1,
  `status` enum('pending','approved','rejected','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_approval_requests_comp_status` (`comp_id`,`status`),
  KEY `idx_approval_requests_reference` (`document_type_code`,`reference_id`),
  KEY `fk_ar_workflow` (`workflow_id`),
  CONSTRAINT `fk_ar_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_doc_type` FOREIGN KEY (`document_type_code`) REFERENCES `approval_document_types` (`code`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ar_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `approval_workflows` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='A running (or completed) instance of a workflow against one real document. Workflow deletion is a soft delete (status=deleted) gated at the app layer by "no pending requests"; the RESTRICT FK here is a hard backstop so history can never be silently orphaned regardless of request status.';

CREATE TABLE IF NOT EXISTS `approval_request_step_approvers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `step_name_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `group_type` enum('and','or','finish') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'and',
  `requires_previous_step` tinyint(1) NOT NULL DEFAULT 0,
  `joint_approve_mode` enum('any','all') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any',
  `eligible_employee_ids` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'CSV of employees.id eligible to act on this row -- one id when joint_approve_mode=all (one row per eligible person, all must approve), the whole resolved pool as CSV when =any (one shared row, first eligible person to act decides it). Snapshotted once when the step becomes current, not re-resolved on every read.',
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `acted_by` int(11) DEFAULT NULL COMMENT 'employees.id who actually acted on this row. NULL until acted.',
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arsa_request_step` (`request_id`,`step_order`),
  CONSTRAINT `fk_arsa_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `approval_request_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `step_order` int(11) NOT NULL,
  `step_name_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Snapshot of step_name at the time of action, so history stays readable even if the step is later edited/reordered.',
  `action` enum('approve','reject','cancel') COLLATE utf8mb4_unicode_ci NOT NULL,
  `acted_by` int(11) NOT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arl_request` (`request_id`),
  CONSTRAINT `fk_arl_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Audit trail of every approve/reject/cancel action taken against an approval_requests row. Same shape as payroll_run_audit_logs.';
