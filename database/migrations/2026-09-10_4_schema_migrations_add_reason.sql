-- 2026-09-10, Batch 3B item 3 follow-up: backs `php scripts/migrate.php mark-all-unknown
-- --reason="..." --yes` (see scripts/migrate.php's own docblock) -- a human-readable audit trail of
-- WHY a batch of "Unknown" files was marked applied without being reviewed one by one, for whoever
-- reads schema_migrations later wondering why 38 files all got applied_via='manual' the same minute.
-- Nullable -- every row recorded before this column existed (including this file's own bootstrap
-- row, and every ordinary single-file `mark <file>` call, which does not pass a reason) stays NULL,
-- not blocked by a NOT NULL default it predates.

-- UP
ALTER TABLE `schema_migrations` ADD COLUMN `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `applied_via`;

-- DOWN
ALTER TABLE `schema_migrations` DROP COLUMN `reason`;
