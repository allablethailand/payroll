-- Platform Hardening Phase 3 (full View/Add/Edit/Delete permission granularity), Stage 1.
--
-- Every module that today only has a single coarse `manage` action_code gets split into the specific
-- actions its own controller methods actually perform (per a full read-only audit of every
-- requirePermission()/checkPermission() call site and the CRUD shape of the model method behind it --
-- status-toggle endpoints fold into `edit`, not a separate 5th action, keeping the set to a clean
-- View/Add/Edit/Delete). A module with NO real add/delete concept (rbac's permission matrix,
-- company_profile's single per-company row) only gets the actions that genuinely apply -- forcing
-- add/delete onto something that can't be "added" or "deleted" would be a worse fit, not more
-- granular.
--
-- `payroll_run` is DELIBERATELY NOT touched here -- its legacy can_process_payroll/can_approve_payroll/
-- can_finalize_payroll structure_roles booleans get folded into their own dedicated
-- payroll_run.process/.approve/.finalize permission keys in a separate migration (Stage 2), since
-- that one has extremely precise Approval-Workflow-engine ordering rules that need its own careful,
-- isolated treatment -- see this project's CLAUDE.md "Approval Workflow" section.
--
-- Old `.manage` rows are intentionally LEFT AS-IS (still is_active=1) by this migration -- they stay
-- live until every controller currently checking them has been re-gated onto the new specific keys
-- (Stage 3), at which point a follow-up migration flips them to is_active=0 (soft-hide, matching this
-- app's own soft-delete-everywhere convention -- never hard-deleted, nothing here depends on it).
--
-- role_permissions is backfilled 1:1 below: any role holding the old `.manage` grant gets every new
-- split action for that same module granted too, so this migration is a zero-admin-action, zero-
-- behavior-change deploy on its own -- nothing stops working until Stage 3 actually flips the
-- controllers over to checking the new keys.

INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('holiday','add','holiday.add','เพิ่มวันหยุด','Add Holidays',1,21),
('holiday','edit','holiday.edit','แก้ไขวันหยุด','Edit Holidays',1,22),
('holiday','delete','holiday.delete','ลบวันหยุด','Delete Holidays',1,23),

('leave_type','add','leave_type.add','เพิ่มประเภทการลา','Add Leave Types',1,41),
('leave_type','edit','leave_type.edit','แก้ไขประเภทการลา','Edit Leave Types',1,42),
('leave_type','delete','leave_type.delete','ลบประเภทการลา','Delete Leave Types',1,43),

('approval_workflow','add','approval_workflow.add','เพิ่มลำดับผู้อนุมัติ','Add Approval Workflows',1,61),
('approval_workflow','edit','approval_workflow.edit','แก้ไขลำดับผู้อนุมัติ','Edit Approval Workflows',1,62),
('approval_workflow','delete','approval_workflow.delete','ลบลำดับผู้อนุมัติ','Delete Approval Workflows',1,63),

-- rbac: the Permission Matrix screen only ever reads (matrix()) or writes-the-whole-grid (save()) --
-- no add/delete concept exists for it (you can't "create" or "delete" a role x permission grid).
('rbac','view','rbac.view','ดูเมทริกซ์สิทธิ์','View Permission Matrix',1,79),
('rbac','edit','rbac.edit','แก้ไขเมทริกซ์สิทธิ์','Edit Permission Matrix',1,81),

('employee','add','employee.add','เพิ่มพนักงาน','Add Employees',1,101),
('employee','edit','employee.edit','แก้ไขพนักงาน','Edit Employees',1,102),
('employee','delete','employee.delete','ลบพนักงาน','Delete Employees',1,103),

('company_structure','add','company_structure.add','เพิ่มโครงสร้างบริษัท','Add Company Structure',1,121),
('company_structure','edit','company_structure.edit','แก้ไขโครงสร้างบริษัท','Edit Company Structure',1,122),
('company_structure','delete','company_structure.delete','ลบโครงสร้างบริษัท','Delete Company Structure',1,123),

-- bank_account never had a `view` key at all (its own list/get endpoints were gated by `.manage`) --
-- shared by both BankAccountController and BankFileFormatController, same reuse precedent as today.
('bank_account','view','bank_account.view','ดูบัญชีธนาคารบริษัท','View Company Bank Accounts',1,129),
('bank_account','add','bank_account.add','เพิ่มบัญชีธนาคารบริษัท','Add Company Bank Accounts',1,131),
('bank_account','edit','bank_account.edit','แก้ไขบัญชีธนาคารบริษัท','Edit Company Bank Accounts',1,132),
('bank_account','delete','bank_account.delete','ลบบัญชีธนาคารบริษัท','Delete Company Bank Accounts',1,133),

('payslip_template','view','payslip_template.view','ดูเทมเพลตสลิปเงินเดือน','View Payslip Templates',1,139),
('payslip_template','add','payslip_template.add','เพิ่มเทมเพลตสลิปเงินเดือน','Add Payslip Templates',1,141),
('payslip_template','edit','payslip_template.edit','แก้ไขเทมเพลตสลิปเงินเดือน','Edit Payslip Templates',1,142),
('payslip_template','delete','payslip_template.delete','ลบเทมเพลตสลิปเงินเดือน','Delete Payslip Templates',1,143),

-- payroll_configuration covers 4 sub-resources today (Cycles/PED Types/Attendance Deduction Rules/
-- Policy) sharing one key already -- kept grouped under this same module_code, only the action
-- dimension splits (see this migration's own header comment on sub-resource scope).
('payroll_configuration','view','payroll_configuration.view','ดูการตั้งค่าเงินเดือน','View Payroll Configuration',1,149),
('payroll_configuration','add','payroll_configuration.add','เพิ่มการตั้งค่าเงินเดือน','Add Payroll Configuration',1,151),
('payroll_configuration','edit','payroll_configuration.edit','แก้ไขการตั้งค่าเงินเดือน','Edit Payroll Configuration',1,152),
('payroll_configuration','delete','payroll_configuration.delete','ลบการตั้งค่าเงินเดือน','Delete Payroll Configuration',1,153),

('employment_certificate_template','view','employment_certificate_template.view','ดูเทมเพลตหนังสือรับรอง','View Employment Cert. Templates',1,149),
('employment_certificate_template','add','employment_certificate_template.add','เพิ่มเทมเพลตหนังสือรับรอง','Add Employment Cert. Templates',1,151),
('employment_certificate_template','edit','employment_certificate_template.edit','แก้ไขเทมเพลตหนังสือรับรอง','Edit Employment Cert. Templates',1,152),
('employment_certificate_template','delete','employment_certificate_template.delete','ลบเทมเพลตหนังสือรับรอง','Delete Employment Cert. Templates',1,153),

-- tax_statutory covers 3 sub-resources (Statutory Items/Rate History/Company Settings) sharing one
-- key already, plus 2 other controllers (NonResidentTaxSettingController, StatutoryFormatVersionController)
-- reusing the same key by the same precedent as bank_account above -- all kept grouped.
('tax_statutory','view','tax_statutory.view','ดูการตั้งค่าภาษีและประกันสังคม','View Tax & Statutory Settings',1,159),
('tax_statutory','add','tax_statutory.add','เพิ่มการตั้งค่าภาษีและประกันสังคม','Add Tax & Statutory Settings',1,161),
('tax_statutory','edit','tax_statutory.edit','แก้ไขการตั้งค่าภาษีและประกันสังคม','Edit Tax & Statutory Settings',1,162),
('tax_statutory','delete','tax_statutory.delete','ลบการตั้งค่าภาษีและประกันสังคม','Delete Tax & Statutory Settings',1,163),

-- company_profile is a single per-company row -- no list, no add-a-new-one, no delete-the-profile
-- concept exists, so only `edit` makes sense here (covers save()/uploadLogo()/uploadSignature()/
-- syncFromOrigami()). Its own read (get()) has never been permission-gated at all and stays that way
-- per this phase's own explicit "don't newly-gate previously-open endpoints" scope boundary.
('company_profile','edit','company_profile.edit','แก้ไขข้อมูลบริษัท','Edit Company Profile',1,171),

-- payroll_run_cash_payment: list() was gated by `.manage` but is a pure read; setStatus() is a
-- status-flip, folded into `edit` per this migration's own convention.
('payroll_run_cash_payment','view','payroll_run_cash_payment.view','ดูสถานะการจ่ายเงินสด','View Cash Payment Status',1,204),
('payroll_run_cash_payment','edit','payroll_run_cash_payment.edit','แก้ไขสถานะการจ่ายเงินสด','Edit Cash Payment Status',1,205),

-- payroll_remittance: all 4 gated methods (markTransferred/confirmSuccess/markFailed/retry) are
-- status/process actions on an existing remittance record, not real add/delete -- folded into `edit`.
-- list()/items() have never been gated and stay that way (same boundary as company_profile.get() above).
('payroll_remittance','edit','payroll_remittance.edit','แก้ไขสถานะการโอนเงินบุคคลที่สาม','Edit Third-Party Remittance Status',1,206);

-- Backfill: any role currently holding the OLD `.manage`/single-action grant gets every NEW split
-- action for that same module granted too, at the exact same allow_scope/detail_level it already had
-- -- this is what makes this migration a zero-behavior-change deploy (Stage 3's controller re-gating
-- is what actually starts consulting these new keys; until then this INSERT is inert extra data).
INSERT INTO `role_permissions` (`role_id`,`permission_id`,`allow_scope`,`detail_level`,`created_by`)
SELECT rp.role_id, p_new.id, rp.allow_scope, rp.detail_level, rp.created_by
FROM `role_permissions` rp
JOIN `permissions` p_old ON p_old.id = rp.permission_id
JOIN `permissions` p_new ON p_new.module_code = p_old.module_code
WHERE p_old.action_code IN ('manage')
  AND p_new.action_code IN ('view','add','edit','delete')
  AND p_new.id <> p_old.id
  AND NOT EXISTS (
      SELECT 1 FROM `role_permissions` rp2 WHERE rp2.role_id = rp.role_id AND rp2.permission_id = p_new.id
  );
