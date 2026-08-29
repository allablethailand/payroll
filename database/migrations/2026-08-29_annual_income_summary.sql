-- 2026-08-29, explicit request: "ต้องการอีกหน้าคล้ายๆหน้าของ Employee เป็นข้อมูลสรุปรอบตามปี และให้มีการ
-- ตั้งค่าวันที่ในการตัดรอบปีด้วยครับ...คือสรุปรายได้รายหักเงินได้สุทธิของพนักงานแต่ละคน สรุปเป็นเดือนๆไป และ
-- สรุปรายได้ทั้งปี" -- new "Annual Income Summary" report/page: per-employee, month-by-month net pay
-- breakdown for a company-configurable fiscal year, plus an annual total column.
--
-- `companies.fiscal_year_start_month` -- a single company-wide value (like registered_country),
-- so it's a column on `companies` rather than a new table, surfaced in Company Profile's own
-- Company Information section. 1=January (plain calendar year) is the default, so a company that
-- never touches this sees zero behavior change -- AnnualIncomeSummaryModel groups payroll runs
-- into fiscal-year "buckets" starting at this month each year.
--
-- The report itself needs NO new storage -- it's a pure read/aggregation over the existing
-- payroll_runs/payroll_run_details tables (same source every other report already reads from),
-- so this migration is schema-only.
--
-- New permission `annual_income_summary.view` -- explicit request: "ต้องมีการเพิ่มสิทธิ์ให้เห็น Report
-- นี้ด้วย" -- gates visibility of the new page/menu item, same permissions table/RBAC engine every
-- other permission in this app already uses (shows up in the Permission Matrix automatically, no
-- code change needed there -- that UI queries `permissions` dynamically).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-08-29_annual_income_summary.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `companies`
    ADD COLUMN `fiscal_year_start_month` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1-12, which calendar month a fiscal year starts on for the Annual Income Summary report (1=January=plain calendar year)' AFTER `local_name`;

INSERT INTO `permissions` (`module_code`, `action_code`, `permission_key`, `name_th`, `name_en`, `is_active`, `sort_order`) VALUES
('reports', 'annual_summary_view', 'annual_income_summary.view', 'ดูรายงานสรุปรายได้ประจำปี', 'View Annual Income Summary Report', '1', '180');
