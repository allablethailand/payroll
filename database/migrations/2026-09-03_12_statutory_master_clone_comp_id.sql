-- 2026-09-03, Backlog Phase 9, T045: Master/Clone architecture for statutory_items.
-- Confirmed via AskUserQuestion: "promote to master" needs a NEW, separate permission
-- (`tax_statutory.promote_master`), not granted to anyone by default -- reusing `tax_statutory.edit`
-- would recreate the exact cross-company mutation hole T044 just closed (any ordinary company admin
-- could then push their own rate to become the platform-wide default for every other company).
--
-- `comp_id` (nullable): NULL = a genuine system-wide master item (visible/cloneable by every company
-- in that country_code, exactly as today). Non-NULL = a single company's own custom item -- never
-- shown to, or affected by, any other company. This is a lighter-weight design than a real "clone as
-- a physical row copy" table: a company's existing RATE OVERRIDE on a master item already lives in
-- `company_statutory_settings` (unchanged by this migration, still what StatutoryCalculationEngine
-- reads) -- comp_id here only needs to cover the genuinely NEW case, a company defining a wholly
-- custom item that has no master counterpart at all (e.g. a provincial payroll tax specific to one
-- company). See TaxStatutoryModel/CompanyStatutorySettingModel's own docblocks for the read/write
-- logic this column drives.
--
-- `promoted_from_comp_id`: set once, when a company-owned custom item is promoted to master
-- (comp_id cleared back to NULL) -- a permanent audit trail of which company originated a given
-- master item, never cleared afterward even though comp_id itself is.
--
-- The old `UNIQUE KEY country_code_code (country_code, code)` is dropped -- MySQL treats every NULL
-- as distinct for uniqueness, so it would silently stop enforcing uniqueness among master rows
-- (comp_id IS NULL) the moment more than one exists, AND it would incorrectly block two DIFFERENT
-- companies from independently choosing the same custom item code. Uniqueness is enforced at the
-- APPLICATION layer instead, scoped per (country_code, comp_id) -- same "deleted_at/nullable-column
-- unique constraint needs an app-layer re-check" convention this project already follows everywhere
-- else (see TaxStatutoryModel::isCodeDuplicate()'s own updated docblock).

ALTER TABLE `statutory_items`
  ADD COLUMN `comp_id` INT NULL COMMENT 'NULL = system-wide master item. Non-NULL = a single company''s own custom item, never shown to any other company.' AFTER `country_code`,
  ADD COLUMN `promoted_from_comp_id` INT NULL COMMENT 'Set once when a company-owned item is promoted to master (comp_id cleared back to NULL): audit trail of which company originated this master item, never cleared afterward.' AFTER `comp_id`;

ALTER TABLE `statutory_items`
  DROP KEY `country_code_code`,
  ADD KEY `idx_statutory_items_scope` (`country_code`, `comp_id`, `status`);

ALTER TABLE `statutory_items`
  ADD CONSTRAINT `fk_statutory_items_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('tax_statutory','promote_master','tax_statutory.promote_master','เลื่อนรายการเป็นค่ากลางระบบ (กระทบทุกบริษัท)','Promote to System Master (affects every company)',1,164)
ON DUPLICATE KEY UPDATE `name_th` = VALUES(`name_th`), `name_en` = VALUES(`name_en`);
