-- Batch 3B item 0: bootstrap table for scripts/migrate.php. Always the FIRST migration file
-- (name sorts before every other migration filename in this project, including every historical
-- one -- see scripts/migrate.php's own docblock for why that matters). migrate.php's own bootstrap
-- step also creates this table directly (by running this exact file) the first time it's needed,
-- before touching anything else, so this file's position in the directory listing is mostly
-- documentary -- the tool doesn't rely on directory order to find it.
--
-- `applied_via` distinguishes 3 ways a row can get here: this tool actually EXECUTED the file's UP
-- ('run'), it found the file's main table/column ALREADY on the database and recorded it without
-- touching it ('detected' -- see scripts/migrate.php's own detection heuristic), or a human
-- explicitly ran `php scripts/migrate.php mark <file>` after manually verifying it ('manual' --
-- for the files detection genuinely can't classify on its own, see status's own "Unknown" bucket).

-- UP
-- 2026-09-10, real bug found and fixed before shipping (not guessed): `varchar(255)` under
-- utf8mb4 is up to 1020 bytes, over MySQL's 767-byte max key-prefix length on the default
-- (non-Barracuda/large-prefix) row format -- CREATE TABLE genuinely failed with "Specified key
-- was too long" the first time this ran. `varchar(190)` (max 760 bytes, under the 767 limit) plus
-- ROW_FORMAT=DYNAMIC (same fix already used elsewhere in this schema, e.g. payroll_run_line_
-- overrides) -- belt and suspenders, real migration filenames never come close to 190 chars anyway.
CREATE TABLE `schema_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_via` enum('run','detected','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'run',
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- DOWN
DROP TABLE IF EXISTS `schema_migrations`;
