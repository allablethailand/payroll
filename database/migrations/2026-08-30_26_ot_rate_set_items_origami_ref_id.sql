-- Follow-up to 2026-08-30_24_ot_rate_sets.sql / 2026-08-30_25_overtime_records_ot_rate_set_items.sql:
-- OtRateSyncer (Origami HR master-data sync for the OT rate catalog) still needs to track which
-- local row corresponds to which Origami-side record across repeated syncs, the same way every
-- other synced entity in this app does via `origami_ref_id` -- discovered while fixing this
-- feature's own test fixtures. `ot_rate_set_items` never needed this column before because it had
-- no sync consumer of its own until the OT Rate Set replacement took over `ot_rates`' old job.
ALTER TABLE `ot_rate_set_items` ADD COLUMN `origami_ref_id` int(11) DEFAULT NULL AFTER `flat_amount_rate`;
