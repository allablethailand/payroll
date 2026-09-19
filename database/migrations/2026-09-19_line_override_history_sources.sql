-- 2026-09-19, H-backend: payroll_run_line_override_history stops being an override-only table.
-- The same append-only trail now also carries the per-run tri-state answer (tax/SSO calculate)
-- and the hand-added items, which until today left no before/after record anywhere at all -- only
-- a free-text payroll_run_audit_logs note that cannot be parsed back into a from/to pair.
--
-- source_type tells the 3 apart and DEFAULTs to 'override', which is what every one of the 48
-- existing rows is, so they are backfilled by the ALTER itself with no second statement.
-- source_id is the writer's own row id where one exists (payroll_run_manual_lines.id) and is
-- deliberately NOT a foreign key: a manual line is hard-deleted, so CASCADE would erase the very
-- history of the deletion and RESTRICT would block the delete outright.
-- old_value_text/new_value_text hold a non-numeric before/after ('inherit'/'yes'/'no'); a row uses
-- either the decimal pair or the text pair, never both.
--
-- Rolling this back re-narrows the action enum, so any row recorded with one of the 4 new values
-- loses it. That is inherent to reverting the feature, not a defect of the rollback.

-- UP
ALTER TABLE `payroll_run_line_override_history`
    ADD COLUMN `source_type` enum('override','manual_line','exemption') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'override' AFTER `line_type`,
    ADD COLUMN `source_id` int(11) DEFAULT NULL AFTER `source_type`,
    ADD COLUMN `old_value_text` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `old_value`,
    ADD COLUMN `new_value_text` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `new_value`,
    MODIFY COLUMN `action` enum('override','exclude','restore','add','edit','delete','exemption_change') COLLATE utf8mb4_unicode_ci NOT NULL;

-- DOWN
ALTER TABLE `payroll_run_line_override_history`
    MODIFY COLUMN `action` enum('override','exclude','restore') COLLATE utf8mb4_unicode_ci NOT NULL,
    DROP COLUMN `new_value_text`,
    DROP COLUMN `old_value_text`,
    DROP COLUMN `source_id`,
    DROP COLUMN `source_type`;
