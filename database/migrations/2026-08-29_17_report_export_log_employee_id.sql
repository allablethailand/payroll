-- 2026-08-29, same-day follow-up: "ตรงที่ปริ้น Slip ของพนักงาน ปรับให้ขึ้นเป็นรายชื่อพนักงานมาเลย...และแสดง
-- ด้วยว่า Download ไปแล้วกี่ครั้ง" -- report_export_logs previously had no way to attribute a single
-- download to a specific employee (only comp_id/payroll_run_id), so a per-employee download count for
-- Pay Slip (and any future per-employee report, e.g. Payment Voucher) was impossible to compute.
-- Nullable, no FK constraint -- same soft-reference convention `generated_by` on this same table
-- already uses (no FK there either despite being a real employees.id reference).
ALTER TABLE `report_export_logs`
    ADD COLUMN `employee_id` INT NULL AFTER `payroll_run_id`,
    ADD KEY `employee_id` (`employee_id`);
