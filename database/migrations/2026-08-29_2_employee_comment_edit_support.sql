-- 2026-08-29, explicit follow-up request: "สามารถแก้ไข Comment และลบ Comment ได้ด้วย" -- adds
-- edit tracking to payroll_run_employee_comments (originally pure append-only). updated_by/
-- updated_at stay NULL for a comment that's never been edited, so the UI can show a small
-- "(edited)" marker only when genuinely applicable. Delete is a real hard DELETE (no soft-delete
-- columns added) -- these are lightweight per-employee reminder notes, not compliance/audit data
-- (the real audit trail stays payroll_run_audit_logs, which still gets an entry for every edit/
-- delete action -- see PayrollRunModel::employeeCommentUpdate()/employeeCommentDelete()), same
-- "hard delete is fine here" precedent as employment_certificate_images.
ALTER TABLE `payroll_run_employee_comments`
  ADD COLUMN `updated_by` int(11) DEFAULT NULL AFTER `created_by`,
  ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL AFTER `updated_by`;
