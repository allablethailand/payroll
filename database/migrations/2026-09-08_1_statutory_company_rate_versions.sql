-- 2026-09-08, explicit request: "อยากให้ทำแบบ Clone+Multi-Version จริง" -- reverses the Phase 9
-- (T045, 2026-09-03_12_statutory_master_clone_comp_id.sql) decision to use a live "read Master +
-- one flat override" model. Each company now gets its OWN physical, independently-dated copy of
-- every Master statutory item's rate, clonable/re-pullable from Master at any time, with as many
-- of its own dated versions as it wants -- see this session's own plan doc for the full design.
--
-- Only affects MASTER items (statutory_items.comp_id IS NULL) and each company's own clone of
-- them. A company's CUSTOM items (comp_id already set) are untouched -- their rate_history rows
-- already have full, independent per-company ownership transitively via statutory_item_id, which
-- is exactly the shape being extended to Master items here (comp_id added directly to rate
-- history instead, since one Master item's statutory_item_id is shared by every company).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-08_1_statutory_company_rate_versions.sql

-- ---------------------------------------------------------------------------
-- Step 1: schema -- comp_id + source on statutory_item_rate_history
-- ---------------------------------------------------------------------------
-- comp_id NULL = Master's own row (existing rows untouched, meaning unchanged). comp_id set =
-- one company's own cloned/customized version of a Master item's rate. A CUSTOM item's own rate
-- history rows keep comp_id NULL here too (no ambiguity -- a custom item's statutory_item_id
-- never overlaps with another company's, ownership is already transitive via statutory_items.
-- comp_id, unchanged by this migration).
--
-- source is only meaningful for comp_id-scoped rows: 'master_clone' = created by the clone-at-
-- activation step below or a later "Pull from Master" action, byte-identical to what Master had
-- at that moment (the "Default" badge in the UI). 'company_custom' = the company added/edited it
-- themselves (the "Customized" badge). NULL for Master's own rows (concept doesn't apply there).
--
-- No FK added on comp_id -- same "app-layer scoping only" convention statutory_items.comp_id
-- itself already established (a real FK here would add nothing a plain index doesn't already
-- give query-planning-wise, and this project's own precedent for this exact table shape skips it).
ALTER TABLE `statutory_item_rate_history`
    ADD COLUMN `comp_id` INT(11) NULL AFTER `statutory_item_id`,
    ADD COLUMN `source` ENUM('master_clone','company_custom') NULL AFTER `comp_id`,
    ADD KEY `idx_rate_history_comp_item` (`comp_id`, `statutory_item_id`, `effective_date`);

-- ---------------------------------------------------------------------------
-- Step 2: migrate any existing company_statutory_settings override into a real dated version
-- ---------------------------------------------------------------------------
-- There is no real historical effective_date for a flat override -- it was always just "the
-- current rate" -- so it becomes an open-ended version effective today, tagged 'company_custom'
-- since it WAS a deliberate company customization, not a plain clone of Master.
INSERT INTO `statutory_item_rate_history`
    (`statutory_item_id`, `comp_id`, `source`, `effective_date`, `end_date`,
     `employee_rate`, `employer_rate`, `employee_amount`, `employer_amount`, `remark`, `created_by`)
SELECT
    css.`statutory_item_id`, css.`comp_id`, 'company_custom', CURDATE(), NULL,
    css.`employee_rate_override`, css.`employer_rate_override`,
    css.`employee_amount_override`, css.`employer_amount_override`,
    CONCAT('Migrated from company_statutory_settings override on ', CURDATE()),
    css.`updated_by`
FROM `company_statutory_settings` css
WHERE css.`deleted_at` IS NULL
    AND (css.`employee_rate_override` IS NOT NULL OR css.`employer_rate_override` IS NOT NULL
         OR css.`employee_amount_override` IS NOT NULL OR css.`employer_amount_override` IS NOT NULL);

-- Override columns are NOT dropped this round (kept for one release as a safety net -- app code
-- stops reading/writing them entirely as of this same change, see CompanyStatutorySettingModel).
-- Nulled out here so a stale value can never be mistaken for still being in effect.
UPDATE `company_statutory_settings`
SET `employee_rate_override` = NULL, `employer_rate_override` = NULL,
    `employee_amount_override` = NULL, `employer_amount_override` = NULL
WHERE `deleted_at` IS NULL
    AND (`employee_rate_override` IS NOT NULL OR `employer_rate_override` IS NOT NULL
         OR `employee_amount_override` IS NOT NULL OR `employer_amount_override` IS NOT NULL);

-- ---------------------------------------------------------------------------
-- Step 3: clone Master's currently-effective version into every active company that doesn't
-- already have its own comp_id-scoped row for that item (one-time backfill for companies
-- activated before this feature existed -- CompanyProfileModel::save() does the equivalent clone
-- going forward for every NEWLY activated company, see that model's own docblock).
-- ---------------------------------------------------------------------------
-- Only the version Master currently has "in effect" is cloned (not full history) -- a brand-new
-- company starting fresh has no need for e.g. a 2020 SSO rate that stopped applying years ago.
INSERT INTO `statutory_item_rate_history`
    (`statutory_item_id`, `comp_id`, `source`, `effective_date`, `end_date`,
     `employee_rate`, `employer_rate`, `employee_amount`, `employer_amount`,
     `min_base_amount`, `max_base_amount`, `max_employee_contribution`, `max_employer_contribution`,
     `formula_config`, `remark`)
SELECT
    si.`id`, c.`id`, 'master_clone', CURDATE(), NULL,
    mrh.`employee_rate`, mrh.`employer_rate`, mrh.`employee_amount`, mrh.`employer_amount`,
    mrh.`min_base_amount`, mrh.`max_base_amount`, mrh.`max_employee_contribution`, mrh.`max_employer_contribution`,
    mrh.`formula_config`,
    CONCAT('Cloned from master (one-time activation backfill) on ', CURDATE())
FROM `companies` c
JOIN `statutory_items` si ON si.`country_code` = c.`registered_country` AND si.`comp_id` IS NULL
    AND si.`deleted_at` IS NULL AND si.`status` = 'active'
JOIN `statutory_item_rate_history` mrh ON mrh.`statutory_item_id` = si.`id` AND mrh.`comp_id` IS NULL
    AND mrh.`deleted_at` IS NULL
    AND mrh.`effective_date` <= CURDATE() AND (mrh.`end_date` IS NULL OR mrh.`end_date` >= CURDATE())
WHERE c.`setup_status` = 'active'
    AND NOT EXISTS (
        SELECT 1 FROM `statutory_item_rate_history` existing
        WHERE existing.`statutory_item_id` = si.`id` AND existing.`comp_id` = c.`id` AND existing.`deleted_at` IS NULL
    );
