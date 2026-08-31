-- Phase 3, T020 (explicit request): field "จ่าย/ไม่จ่ายเงินเดือน" on employees, default = จ่าย.
-- 1 = pays this employee through payroll (default, unchanged behavior for every existing row);
-- 0 = staff-only record -- excluded from payroll calculation/reports (see PayrollRunModel changes
-- in T021) and payroll-specific fields hidden on the Employee Detail form (see EmployeeModel's
-- missingPayrollFields()/calculateCompleteness() changes and public/js/employee/detail.js).
ALTER TABLE `employees`
    ADD COLUMN `is_payroll_participant` tinyint(1) NOT NULL DEFAULT 1 AFTER `employee_status`;
