-- 2026-09-04, Backlog Phase 9, T049: "redesign the document-handling section (per selected
-- city/country, what must be filed) for max usability" -- master_statutory_format_versions had NO
-- country_code column at all, so StatutoryFormatVersionModel::listForms() returned every distinct
-- active form_code in the whole table with zero country filtering. This "accidentally" looked fine
-- so far because only 2 form_codes were ever seeded (TH_PND1, TH_SSO110, both Thailand-only), but it
-- directly violates the same "strictly scoped to the company's own registered country, never show
-- another country's items" principle T045 already established for statutory_items (see
-- CompanyStatutorySettingModel::list()'s own `si.country_code = :country_code` filter) -- the moment
-- a non-TH form_code is seeded, every company on the platform, regardless of country, would see it.
--
-- NULL country_code = applies to every country (a generic/international form -- none exist yet, but
-- the column allows one without a further schema change later). Backfilling the 2 existing rows to
-- 'TH' is a known, explicit fact (both are Thailand-specific forms by form_code prefix and by
-- StatutoryFormatVersionModel's own docblock), not a guess.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_2_statutory_format_version_country_scope.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `master_statutory_format_versions`
  ADD COLUMN `country_code` varchar(10) COLLATE utf8mb4_unicode_ci NULL COMMENT 'NULL = applies to every country; matches companies.registered_country / master_countries.countries_code' AFTER `form_code`,
  ADD KEY `idx_country_active` (`country_code`, `is_active`);

UPDATE `master_statutory_format_versions` SET `country_code` = 'TH' WHERE `form_code` IN ('TH_PND1', 'TH_SSO110');
