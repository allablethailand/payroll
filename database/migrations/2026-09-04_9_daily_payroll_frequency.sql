-- 2026-09-04, Backlog Phase 10, T060 Step B: "support daily/weekly/bi-weekly pay frequency" --
-- weekly/semi_monthly/bi_weekly already existed (2026-08-31 round); this adds the missing 'daily'
-- value. Step A (StatutoryCalculationEngine monthly-ceiling accumulation fix) already landed
-- separately and is untouched by this migration.
--
-- A daily period needs almost no new logic -- structurally it's the simplest case:
-- period_start = period_end = the single day being paid. Unlike monthly/semi_monthly (a specific
-- day-of-month) or weekly/bi_weekly (a specific day-of-week), there is no "cutoff day" concept
-- that applies to a period that's always exactly 1 day -- so a daily cycle simply leaves
-- cutoff_day_of_month/cutoff_use_last_day/cutoff_day_of_week/payment_day_of_month/
-- payment_use_last_day/payment_day_of_week all NULL/0, the same way a weekly cycle already
-- leaves the *_day_of_month columns NULL (each frequency only populates the columns it actually
-- needs -- no new columns required for 'daily', it needs strictly fewer than any existing value).
--
-- employees.salary_type already has a working 'daily' value with its own divisor
-- ($salaryTypeDayDivisors['daily'] = 1 in PayrollRunModel::recalculate(), shipped 2026-08-31) --
-- that is a SEPARATE, per-employee "how is this employee's own rate quoted" concept, already
-- correct, not touched by this migration. This migration only widens the per-CYCLE
-- "how often does the company run payroll" enum.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_9_daily_payroll_frequency.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `payroll_cycles`
    MODIFY COLUMN `payroll_frequency` ENUM('monthly','semi_monthly','weekly','bi_weekly','daily') NOT NULL;
