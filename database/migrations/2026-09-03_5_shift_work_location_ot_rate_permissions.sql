-- Platform Hardening Phase 3, Stage 3: brand-new permission gate for Shift / Work Location / OT
-- Rate (SetupRulesController) -- explicitly OUT of RBAC scope in the original 2026-08-xx rollout
-- (see that controller's own now-stale docblock comment on scopeEmployeesInRow(), being removed in
-- this same Stage 3 code change) but folded in during Phase 3 planning as a "natural small
-- extension, sits right next to Holiday/Leave Type which are already being expanded" (see
-- project_platform_hardening_phase3_2026_09_03 memory's own scope-boundary section).
--
-- Unlike the Stage 1 CRUD-split migration, these 3 modules have NO prior permission row at all to
-- backfill role_permissions from (checked: zero role_permissions rows exist for holiday/leave_type
-- in the real dev DB either, so no non-admin role currently depends on those gates in practice --
-- extending the same effectively-admin-only gate to 3 more entity types is not a behavior change
-- for any real role today). No backfill INSERT needed or possible.
--
-- module_code kept as `shift`/`work_location`/`ot_rate` (not `ot_rate_set`) to match the existing
-- route/method naming convention (`api/ot-rate.*`, `otRate*()`) -- see OtRateSetModel's own
-- docblock on why the method names stayed `otRate*` after the Set-based rebuild.

INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('shift','view','shift.view','ดูกะการทำงาน','View Shifts',1,251),
('shift','add','shift.add','เพิ่มกะการทำงาน','Add Shifts',1,252),
('shift','edit','shift.edit','แก้ไขกะการทำงาน','Edit Shifts',1,253),
('shift','delete','shift.delete','ลบกะการทำงาน','Delete Shifts',1,254),

('work_location','view','work_location.view','ดูสถานที่ทำงาน','View Work Locations',1,261),
('work_location','add','work_location.add','เพิ่มสถานที่ทำงาน','Add Work Locations',1,262),
('work_location','edit','work_location.edit','แก้ไขสถานที่ทำงาน','Edit Work Locations',1,263),
('work_location','delete','work_location.delete','ลบสถานที่ทำงาน','Delete Work Locations',1,264),

('ot_rate','view','ot_rate.view','ดูอัตราค่าล่วงเวลา','View OT Rates',1,271),
('ot_rate','add','ot_rate.add','เพิ่มอัตราค่าล่วงเวลา','Add OT Rates',1,272),
('ot_rate','edit','ot_rate.edit','แก้ไขอัตราค่าล่วงเวลา','Edit OT Rates',1,273),
('ot_rate','delete','ot_rate.delete','ลบอัตราค่าล่วงเวลา','Delete OT Rates',1,274);
