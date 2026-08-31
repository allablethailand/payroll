-- Explicit request: "เพิ่มหักกลับก่อนเวลาเข้าไปใน Master การหักด้วยครับ" -- adds the EARLY_LEAVE_DEDUCT
-- catalog row to `payroll_earning_deduction_types` (the "Earning-Deduction Types" master list in
-- Payroll Configuration), mirroring LATE_DEDUCT/ABSENT_DEDUCT's own row shape exactly (same
-- item_type/calculation_method/tax_deduction_impact/calc_sso/calc_pf/is_sync_only values LATE_DEDUCT
-- already has). Confirmed via SyncPayResolver::RULE_DRIVEN_ITEM_DEFS['early_leave'] that the
-- calculation itself already falls back to a hardcoded EARLY_LEAVE_DEDUCT/'หักกลับก่อนเวลา' definition
-- when no catalog row exists (2026-08-30's earlier fix for the event itself already computes
-- correctly without this row) -- this migration is purely about making it a real, admin-editable
-- catalog entry (so tax_treatment/calc_sso/calc_pf can be explicitly set, and it shows up in Payroll
-- Configuration's own Earning-Deduction Types list like every other deduction type), same as this
-- session's own "leave_pending"/"diligence" precedent of promoting a source_event_code from
-- hardcoded-fallback-only to a real catalog row.
--
-- One row per company that doesn't already have a source_event_code='early_leave' mapping, idempotent
-- (safe to re-run) via NOT EXISTS.
INSERT INTO `payroll_earning_deduction_types`
    (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method,
     tax_deduction_impact, calc_sso, calc_pf, source_event_code, is_sync_only, status, created_by)
SELECT c.id, 'EARLY_LEAVE_DEDUCT', 'หักกลับก่อนเวลา', 'Early Leave Deduction', 'deduction', 'manual_entry',
       'before_tax', 0, 0, 'early_leave', 0, 'active', NULL
FROM `companies` c
WHERE NOT EXISTS (
    SELECT 1 FROM `payroll_earning_deduction_types` t
    WHERE t.comp_id = c.id AND t.source_event_code = 'early_leave' AND t.deleted_at IS NULL
);
