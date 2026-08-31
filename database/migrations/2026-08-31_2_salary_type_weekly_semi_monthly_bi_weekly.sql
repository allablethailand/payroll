-- Explicit request/investigation: "ถ้าเป็นพนักงานรายวัน การระบุเงินเดือน และการคำนวณจะเป็นแบบไหนครับ
-- รายสัปดาห์ด้วย และรายปักษ์...Form เงินเดือน และการนำไปคำนวณจะต้องครอบคลุมทั้งหมด". Confirmed via
-- AskUserQuestion: "รายปักษ์" covers BOTH semi-monthly (1st-15th/16th-end) AND literal bi-weekly
-- (every 14 days) -- these already exist as DISTINCT, already-fully-implemented
-- `payroll_cycles.payroll_frequency` values ('semi_monthly'/'bi_weekly', see PayrollCycleModel's own
-- suggestNextPeriod()) with their own real period-boundary math, just never offered as an
-- EMPLOYEE-level `salary_type` (what a single employee's own base_salary_amount rate represents).
-- Widened to reuse the EXACT SAME enum vocabulary as payroll_cycles.payroll_frequency for internal
-- consistency (same freq_weekly/freq_semi_monthly/freq_bi_weekly i18n labels reused, no new
-- translation keys needed) -- confirmed via a second AskUserQuestion: these employees should be
-- assigned (via the already-existing employees.cycle_id) to a Payroll Cycle of matching frequency
-- (weekly/semi_monthly/bi_weekly), NOT reuse the company's monthly cycle.
ALTER TABLE `employees`
  MODIFY COLUMN `salary_type` enum('monthly','daily','hourly','weekly','semi_monthly','bi_weekly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly';
