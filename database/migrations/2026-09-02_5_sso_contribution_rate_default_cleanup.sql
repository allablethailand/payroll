-- 2026-09-02, follow-up to 2026-09-02_4 (SSO rate override wiring). `employees.sso_contribution_rate`
-- had `DEFAULT '5.00'` ever since it was added -- harmless while the column was dead (never read by
-- StatutoryCalculationEngine), but now that it's wired in as a real "employee > company > master"
-- override, a baked-in 5.00 default would silently pin EVERY employee to 5% forever, even once a
-- company/master rate changes (e.g. a government SSO relief period), since a non-null value always
-- wins over the company/master rate regardless of how it got there.
--
-- Checked the real dev DB first: all 28 employees currently hold exactly 5.00 (zero deviation) --
-- consistent with this being unused default-fill from the form's own hardcoded `value="5.00"`
-- (removed in the same round, see app/views/employee/detail.php), not deliberately-entered
-- per-employee override data. Safe to null out.
UPDATE `employees` SET `sso_contribution_rate` = NULL WHERE `sso_contribution_rate` = 5.00;

ALTER TABLE `employees`
  MODIFY COLUMN `sso_contribution_rate` decimal(5,2) DEFAULT NULL;
