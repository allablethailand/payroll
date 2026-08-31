-- 2026-08-30_11_diligence_source_event.sql
-- T011 (Phase 2 payroll-configuration cleanup): "เบี้ยขยัน" (Diligence Allowance) becomes a proper
-- Linked Attendance Event (master_payroll_source_events), same discoverable pattern OT/Trip
-- Allowance already use, instead of relying on an admin-typed item_code coincidentally matching
-- whatever Origami sends. See SyncPayResolver.php's own EVENT_ALIASES/KNOWN_ITEM_DEFS additions
-- for the calculation-side wiring -- this migration is schema-only.
INSERT INTO `master_payroll_source_events` (`code`, `name_th`, `name_en`, `applies_to`, `is_active`, `sort_order`)
VALUES ('diligence', 'เบี้ยขยัน', 'Diligence Allowance', 'earning', 1, 80);
