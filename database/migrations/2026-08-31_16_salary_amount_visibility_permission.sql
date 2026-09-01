-- 2026-08-31, explicit request: "ตอนนี้มีการตั้งค่าสิทธิ์ในการมองเห็นเงินเดือนหรือเงินได้ส่วนอื่นๆ ที่เป็นตัวเงิน
-- หรือยังครับ ถ้ายังไม่มีให้เพิ่ม และจะเห็นเป็น XXXX แต่ยังสามารถคำนวณเงินเดือนและทำงานส่วนอื่นๆได้ตามสิทธิ์ โดยที่
-- Process การทำงานไม่เพี้ยน" -- confirmed via AskUserQuestion: 3 axes matter (by module/page, own-vs-
-- others, and figure granularity), kept to a small maintainable key count rather than a full
-- cross-product (see PermissionModel's own docblock for the design reasoning).
--
-- 3 new permission keys, one per module area -- module_code='salary_amount' groups them together
-- under one pill on the Permissions page (2026-08-31, same-day: Permissions moved to its own
-- top-level menu with module-grouped pill tabs, see that migration's own history).
INSERT INTO `permissions` (module_code, action_code, permission_key, name_th, name_en, is_active, sort_order) VALUES
('salary_amount', 'view_employee', 'salary_amount.view_employee', 'ดูตัวเลขเงินเดือน (หน้าพนักงาน)', 'View Salary Amounts (Employee pages)', 1, 210),
('salary_amount', 'view_payroll_process', 'salary_amount.view_payroll_process', 'ดูตัวเลขเงินเดือน (หน้าทำรอบจ่าย)', 'View Salary Amounts (Payroll Process pages)', 1, 211),
('salary_amount', 'view_reports', 'salary_amount.view_reports', 'ดูตัวเลขเงินเดือน (รายงาน)', 'View Salary Amounts (Reports)', 1, 212);

-- Own-vs-others axis: a grant limited to this employee's own figures -- everyone else's amounts
-- show as XXXX. Widens the SAME generic-but-selectively-enforced allow_scope column
-- `approval_request.act` already established the precedent for (see PermissionModel's own
-- docblock) -- 'own_only' is meaningless for every OTHER existing permission key and is simply
-- never offered as a choice for those in the Matrix UI, same as 'own_department' already is today.
ALTER TABLE `role_permissions`
  MODIFY COLUMN `allow_scope` ENUM('all','own_department','own_only') NOT NULL DEFAULT 'all';

-- Granularity axis: 'summary' shows net/total pay figures but masks itemized earning/deduction/
-- statutory breakdowns as XXXX; 'full' shows everything the module+scope already allows. Only ever
-- meaningful for the 3 salary_amount.* keys above -- stored generically (same "not every permission
-- uses every column" precedent allow_scope itself already set) so a future permission needing the
-- same concept doesn't need its own migration.
ALTER TABLE `role_permissions`
  ADD COLUMN `detail_level` ENUM('summary','full') NOT NULL DEFAULT 'full' COMMENT 'Only meaningful for salary_amount.* permissions -- summary masks itemized breakdowns, full does not' AFTER `allow_scope`;
