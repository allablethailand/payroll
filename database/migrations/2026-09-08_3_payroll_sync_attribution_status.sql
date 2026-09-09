-- 2026-09-08, Origami email exchange (2 rounds) -- Origami's own "Send to Payroll" button used to
-- BLOCK sending a supplemental batch (e.g. ค่าเที่ยว) at all until an admin manually linked it to a
-- real regular process. They are changing this to send the batch immediately as a standalone item
-- (`attribution.status = 'pending_fold_in'`, `attribution.target_process_id = null`), then notify
-- us later via a new `attribution_update` event once the target is actually chosen
-- (`attribution.status = 'resolved'`).
--
-- Confirmed with Origami (2nd round) that `attribution.status` (link state -- do we know the
-- target yet) and `attribution.tax_treatment` (calc intent -- should this batch's tax wait for the
-- merge, or calculate standalone regardless) are DELIBERATELY independent axes, not a typo:
--   - tax_treatment='merge'    + status='pending_fold_in' -> do not finalize tax yet, wait for the
--     target (this is the ONLY combination that gates StatutoryCalculationEngine-relevant
--     finalization on our side -- see PayrollSyncModel::attributionTargetStatus()'s own docblock).
--   - tax_treatment='separate' + status='pending_fold_in' -> calculate tax now as a standalone
--     item (unaffected); attribution is purely for later report/period reconciliation, not a gate.
--
-- New column ONLY (no existing column repurposed) -- `payroll_sync_processes.status` already means
-- something different (Pending-Pull row status: pending/rejected), so this needed a distinct name
-- to avoid colliding with it.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-08_3_payroll_sync_attribution_status.sql

ALTER TABLE `payroll_sync_processes`
    ADD COLUMN `attribution_status` ENUM('resolved','pending_fold_in') NULL AFTER `attribution_tax_treatment`;
