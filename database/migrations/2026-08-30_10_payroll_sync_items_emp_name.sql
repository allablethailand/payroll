-- 2026-08-30_10_payroll_sync_items_emp_name.sql
-- PAYROLL_SYNC_API.md, 2026-08-30 revision (2): new field `items[].emp_name` -- additive, schema_version
-- stays 1. Previously the only place an employee's name appeared in this payload was
-- `employee_status[]` (stored in payroll_sync_employee_status.emp_name), a subset that only ever
-- covers new-hire/resigned-this-period rows -- an ordinary ongoing employee that happens to still be
-- unmapped (no matching employees.employee_no yet) had no name available anywhere, showing as a bare
-- payroll_code in the Pending Pull review UI. `items[].emp_name` is sent on EVERY employee row, every
-- cycle, so it becomes the more complete/reliable source going forward.
ALTER TABLE `payroll_sync_items`
    ADD COLUMN `emp_name` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'items[].emp_name (added 2026-08-30 rev 2) -- display/verification only, NOT a mapping key (payroll_code/emp_code still are). Origami resolves firstname/lastname falling back to _th, null if neither half is on file.'
        AFTER `emp_code`;
