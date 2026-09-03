-- 2026-09-02, explicit request: "ต้องการครับ ทำได้เลยครับ" (confirming the Reports/Payroll Process
-- gap flagged when reviewing the Permission Matrix page) -- adds RBAC gating to the Payroll
-- Process module, which previously had ZERO permission_key checks anywhere in PayrollController
-- (it relies entirely on the older, separate structure_roles.can_process_payroll/
-- can_approve_payroll/can_finalize_payroll flat-role-flag system -- see PayrollRunModel::userCan()).
--
-- 2 new permissions, module_code = 'payroll_run':
--   payroll_run.view    -- can see Process List/Detail pages and their data (read-only)
--   payroll_run.manage  -- can create/edit/recalculate/delete a draft run, manage its roster/items/
--                          overrides/comments/verify state, and submit it for approval
--
-- Deliberately does NOT touch approve/reject/markPaid/lock/reopen/cancel/requestInfo/bulk* --
-- those already have their own well-tested, much more granular authorization (the Approval
-- Workflow engine + can_approve_payroll/can_finalize_payroll + department-scoping, see this
-- project's own CLAUDE.md "Approval Workflow" section for the real bugs already found/fixed
-- there). Adding a coarse RBAC gate on top of that risked conflicting with or duplicating logic
-- that's already correct. payroll_run.manage maps 1:1 onto exactly the set of PayrollRunModel
-- methods that already check can_process_payroll internally -- see PayrollController's own new
-- requirePermission('payroll_run.manage') calls for the full list.
--
-- Safety: as with every permission this app has ever added, a non-admin role with NO explicit
-- grant loses access to the gated endpoints immediately once PayrollController starts checking
-- for it. To avoid silently locking out every existing non-admin user of this app's single most
-- used module on deploy day, this migration ALSO backfills role_permissions for every role that
-- already has the corresponding legacy flag set -- so nothing regresses until an admin
-- deliberately revisits the Permission Matrix page. INSERT IGNORE (respects role_permissions'
-- own uq_role_permission) makes this safe to re-run.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-02_3_payroll_run_permission_gate.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

INSERT INTO `permissions` (`module_code`, `action_code`, `permission_key`, `name_th`, `name_en`, `is_active`, `sort_order`) VALUES
    ('payroll_run', 'view', 'payroll_run.view', 'ดูรอบการทำเงินเดือน', 'View Payroll Process', 1, 220),
    ('payroll_run', 'manage', 'payroll_run.manage', 'จัดการรอบการทำเงินเดือน (สร้าง/แก้ไข/คำนวณ/ส่งอนุมัติ)', 'Manage Payroll Process (create/edit/recalculate/submit)', 1, 230);

-- Backfill: any role that could already SEE payroll data (any of the 3 legacy flags) keeps view access.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `allow_scope`)
SELECT sr.id, (SELECT id FROM `permissions` WHERE permission_key = 'payroll_run.view'), 'all'
FROM `structure_roles` sr
WHERE sr.deleted_at IS NULL
  AND (sr.can_process_payroll = 1 OR sr.can_approve_payroll = 1 OR sr.can_finalize_payroll = 1);

-- Backfill: any role that could already CREATE/EDIT a run (can_process_payroll) keeps manage access.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `allow_scope`)
SELECT sr.id, (SELECT id FROM `permissions` WHERE permission_key = 'payroll_run.manage'), 'all'
FROM `structure_roles` sr
WHERE sr.deleted_at IS NULL AND sr.can_process_payroll = 1;
