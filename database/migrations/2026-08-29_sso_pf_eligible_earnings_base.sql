-- 2026-08-29, real bug found and fixed (explicit report: "หักประกันสังคมจะไม่ใช่คำนวณจากฐานอย่างเดียว
-- ต้องมาจากที่เราตั้งค่าในรายได้ ว่ารายการไหนหักประกันสังคม ต้องเอามาคำนวณทั้งหมด") -- TH_SSO/TH_PVD's
-- calc_base was hardcoded to 'basic_salary', completely ignoring
-- payroll_earning_deduction_types.calc_sso/calc_pf (a setting that already existed in Payroll
-- Configuration and was being saved, just never read anywhere in the calculation engine). See
-- PayrollRunModel::recalculate()'s own new $calcSsoItemCodes/$calcPfItemCodes prefetch + the
-- sso_eligible_earnings/pf_eligible_earnings salaryContext keys for the actual fix.
ALTER TABLE `statutory_items`
  MODIFY COLUMN `calc_base` enum('basic_salary','gross_salary','taxable_income','net_income','sso_eligible_earnings','pf_eligible_earnings','custom') COLLATE utf8mb4_unicode_ci NOT NULL;

UPDATE `statutory_items` SET `calc_base` = 'sso_eligible_earnings' WHERE `code` = 'TH_SSO';
UPDATE `statutory_items` SET `calc_base` = 'pf_eligible_earnings' WHERE `code` = 'TH_PVD';
