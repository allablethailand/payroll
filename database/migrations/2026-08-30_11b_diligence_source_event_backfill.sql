-- 2026-08-30_11b_diligence_source_event_backfill.sql
-- T011 follow-up: backfills source_event_code='diligence' onto any EXISTING
-- payroll_earning_deduction_types row with item_code='DILIGENCE' that predates this fix (seeded via
-- the OLD PayrollEarningDeductionTypeModel::seedDefaults() definition -- fixed_amount=0, no
-- source_event_code). Without this, SyncPayResolver's new KNOWN_ITEM_DEFS['diligence'] entry
-- (matched via pedTypeBySourceEvent(), NOT item_code) would never find these existing rows, silently
-- falling back to a hardcoded default code instead and orphaning whatever tax_treatment/calc_sso/
-- calc_pf configuration the admin already had on file for it.
-- Scoped tightly (item_code, item_type, source_event_code IS NULL, not deleted) so this can never
-- touch a row an admin deliberately repurposed for something else under the same item_code.
UPDATE `payroll_earning_deduction_types`
SET `source_event_code` = 'diligence'
WHERE UPPER(`item_code`) = 'DILIGENCE'
  AND `item_type` = 'earning'
  AND `source_event_code` IS NULL
  AND `deleted_at` IS NULL;
