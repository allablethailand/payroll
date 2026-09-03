-- Platform Hardening Phase 3, Stage 2: folds the 3 legacy structure_roles booleans
-- (can_process_payroll/can_approve_payroll/can_finalize_payroll) into real permission_key rows, and
-- splits payroll_run's remaining true-CRUD sub-actions (save/delete/joinEmployees/removeEmployee/
-- addManualLine/removeManualLine/lineOverride*/employeeComment*) off of the coarse `payroll_run.manage`
-- key onto add/edit/delete -- everything else `payroll_run.manage` used to cover (submit/approve/
-- reject/bulkApprove/bulkReject/cancel/requestInfo/bulkRequestInfo/reviseAfterReject/
-- reviseAfterNeedInfo/markPaid/lock/reopen/mergeSupplemental/mergeIntoExistingRun/employeeVerify*,
-- 17 methods) is a workflow state-transition, not CRUD -- it maps onto process/approve/finalize
-- instead, mirroring the existing approval_request.act precedent for the same reason.
--
-- payroll_run.view is untouched (already correctly scoped, per the 2026-09-02 migration).
-- payroll_run.manage itself is left is_active=1 for now -- flipped off in a later cleanup migration
-- once PayrollController is confirmed fully re-gated off it (this same stage, application-code side).
--
-- Backfill #1: any role with can_process_payroll=1 gets payroll_run.process granted (etc for the
-- other 2 flags) -- this is what makes dropping the 3 structure_roles columns later, once the
-- application code is confirmed switched over, a zero-behavior-change cleanup rather than a real
-- change in who can do what.
-- Backfill #2: any role currently holding payroll_run.manage gets payroll_run.add/.edit/.delete too
-- (same "old coarse grant implies every new split action" pattern the Stage 1 migration used).

INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('payroll_run','process','payroll_run.process','ดำเนินการรอบเงินเดือน (สร้าง/ส่งอนุมัติ/ดึงกลับ)','Process Payroll Run (submit/pull back)',1,231),
('payroll_run','approve','payroll_run.approve','อนุมัติ/ไม่อนุมัติรอบเงินเดือน','Approve/Reject Payroll Run',1,232),
('payroll_run','finalize','payroll_run.finalize','บันทึกจ่ายและปิดรอบเงินเดือน','Finalize Payroll Run (mark paid/lock)',1,233),
('payroll_run','add','payroll_run.add','เพิ่มรายการในรอบเงินเดือน','Add Payroll Run Line Items',1,234),
('payroll_run','edit','payroll_run.edit','แก้ไขรอบเงินเดือน','Edit Payroll Run',1,235),
('payroll_run','delete','payroll_run.delete','ลบรอบเงินเดือน/รายการ','Delete Payroll Run / Line Items',1,236);

-- Backfill #1: legacy structure_roles booleans -> new permission keys.
INSERT INTO `role_permissions` (`role_id`,`permission_id`,`allow_scope`,`detail_level`,`created_by`)
SELECT sr.id, p.id, 'all', 'full', NULL
FROM `structure_roles` sr
JOIN `permissions` p ON p.permission_key = 'payroll_run.process'
WHERE sr.can_process_payroll = 1 AND sr.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp2 WHERE rp2.role_id = sr.id AND rp2.permission_id = p.id);

INSERT INTO `role_permissions` (`role_id`,`permission_id`,`allow_scope`,`detail_level`,`created_by`)
SELECT sr.id, p.id, 'all', 'full', NULL
FROM `structure_roles` sr
JOIN `permissions` p ON p.permission_key = 'payroll_run.approve'
WHERE sr.can_approve_payroll = 1 AND sr.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp2 WHERE rp2.role_id = sr.id AND rp2.permission_id = p.id);

INSERT INTO `role_permissions` (`role_id`,`permission_id`,`allow_scope`,`detail_level`,`created_by`)
SELECT sr.id, p.id, 'all', 'full', NULL
FROM `structure_roles` sr
JOIN `permissions` p ON p.permission_key = 'payroll_run.finalize'
WHERE sr.can_finalize_payroll = 1 AND sr.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp2 WHERE rp2.role_id = sr.id AND rp2.permission_id = p.id);

-- Backfill #2: existing payroll_run.manage grants -> the new true-CRUD split.
INSERT INTO `role_permissions` (`role_id`,`permission_id`,`allow_scope`,`detail_level`,`created_by`)
SELECT rp.role_id, p_new.id, rp.allow_scope, rp.detail_level, rp.created_by
FROM `role_permissions` rp
JOIN `permissions` p_old ON p_old.id = rp.permission_id AND p_old.permission_key = 'payroll_run.manage'
JOIN `permissions` p_new ON p_new.module_code = 'payroll_run' AND p_new.action_code IN ('add','edit','delete')
WHERE NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp2 WHERE rp2.role_id = rp.role_id AND rp2.permission_id = p_new.id
);
