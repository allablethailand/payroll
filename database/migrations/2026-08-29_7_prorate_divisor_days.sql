-- 2026-08-29, explicit request: "กรณีคนเข้า และคนออก การคิดเงินเดือน ต้องจับหาร 30 ตามกฏหมาย ช่วยเพิ่มให้
-- ตั้งค่าตัวเลขนี้ได้หน่อยได้ไหมครับ และปรับการคิดเงินเดือนใหม่ ตอนนี้หารจำนวนวันจริงของเดือนครับ" -- for a
-- monthly-salaried employee who joins/leaves mid pay-period, PayrollRunModel::recalculate() was
-- dividing by the ACTUAL number of days in that specific period (28/29/30/31, whatever
-- period_start_date..period_end_date spans) -- Thai labor law convention instead divides by a
-- FIXED 30 regardless of the real month length (this is what keeps a partial-month daily rate
-- consistent across a 28-day February vs a 31-day January, rather than penalizing/rewarding an
-- employee based on which month they happened to join in).
--
-- `companies.prorate_divisor_days` -- a single company-wide value (same "one number, set once,
-- read everywhere" shape as companies.fiscal_year_start_month, added the same way for the Annual
-- Income Summary report earlier this same day) -- default 30 matches the legal convention the
-- request names outright, so every existing company gets the CORRECT behavior automatically the
-- moment this migration runs, not a value that has to be set before proration is right.
ALTER TABLE `companies`
  ADD COLUMN `prorate_divisor_days` TINYINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Fixed divisor for mid-period join/leave proration of a monthly-rate employee (Thai labor law convention: 30, NOT the real number of days in that period)' AFTER `fiscal_year_start_month`;
