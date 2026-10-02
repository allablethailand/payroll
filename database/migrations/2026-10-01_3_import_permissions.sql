-- 2026-10-01: permission keys for the Import Framework (import.run gates upload/map/validate/edit/commit/discard, import.view_log the history).

-- UP
INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('import','run','import.run','นำเข้าข้อมูล','Run Data Import',1,290),
('import','view_log','import.view_log','ดูประวัติการนำเข้าข้อมูล','View Import Activity Log',1,291)
ON DUPLICATE KEY UPDATE `name_th` = VALUES(`name_th`), `name_en` = VALUES(`name_en`);

-- DOWN
DELETE FROM `role_permissions` WHERE `permission_id` IN (SELECT `id` FROM `permissions` WHERE `permission_key` IN ('import.run','import.view_log'));
DELETE FROM `permissions` WHERE `permission_key` IN ('import.run','import.view_log');
