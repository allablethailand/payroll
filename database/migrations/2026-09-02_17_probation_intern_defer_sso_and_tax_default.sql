-- 2026-09-02, follow-up to close a gap flagged after review: "เงื่อนไขการหักภาษี/ประกันสังคมที่แตกต่างจาก
-- พนักงานปกติ (ถ้ามี)" was never actually built -- only the pre-existing probation_defer_pvd/
-- intern_defer_pvd (added before this feature) covered the PVD half. SSO gets the exact same
-- "defer contribution until probation/internship passes" mechanism PVD already has (same engine,
-- same StatutoryCalculationEngine::$employeeFlags gate -- see PayrollRunModel::recalculate()'s own
-- comment at the defer_pvd block for why 'sso_enrolled' is set the same way). Tax gets a soft
-- DEFAULT (same "default only, checkbox still wins, CREATE-TIME-ONLY" contract as
-- probation_ot_eligible_default/intern_ot_eligible_default -- see that column's own migration/
-- EmployeeModel::save() docblock) applied to the EXISTING employees.tax_exempt checkbox, not a new
-- tax computation formula -- this app has no basis to invent a probation-specific PIT withholding
-- rule Thai tax law itself doesn't actually define.
ALTER TABLE `company_payroll_policies`
  ADD COLUMN `probation_defer_sso` TINYINT(1) NOT NULL DEFAULT 0 AFTER `probation_ot_eligible_default`,
  ADD COLUMN `probation_tax_exempt_default` TINYINT(1) NULL DEFAULT NULL AFTER `probation_defer_sso`,
  ADD COLUMN `intern_defer_sso` TINYINT(1) NOT NULL DEFAULT 0 AFTER `intern_ot_eligible_default`,
  ADD COLUMN `intern_tax_exempt_default` TINYINT(1) NULL DEFAULT NULL AFTER `intern_defer_sso`;

-- Rollback:
-- ALTER TABLE `company_payroll_policies`
--   DROP COLUMN `probation_defer_sso`, DROP COLUMN `probation_tax_exempt_default`,
--   DROP COLUMN `intern_defer_sso`, DROP COLUMN `intern_tax_exempt_default`;
