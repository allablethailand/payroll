-- 2026-09-08, real bug found and fixed while running tests/statutory_engine_test.php after the
-- Clone+Version migration (2026-09-08_1_statutory_company_rate_versions.sql) ran earlier this
-- session -- that migration's Step 3 (one-time activation backfill) cloned every active company's
-- currently-effective Master rate into a new `statutory_item_rate_history` row, but ONLY the
-- rate_history row itself -- for a `progressive_bracket` item (e.g. TH_PIT), the actual tax
-- brackets live in a CHILD table (`statutory_item_brackets`, keyed by `statutory_item_rate_
-- history_id`) that Step 3 never copied. Confirmed live on comp_id=1: TH_PIT's own company-scoped
-- clone row (id=28) has 0 bracket rows while Master's own row (id=3) has the real 8.
--
-- This is NOT cosmetic -- StatutoryCalculationEngine::calculateLine()'s progressive_bracket branch
-- resolves the COMPANY's own scoped row first (comp_id=1 now HAS one for TH_PIT, dated today) and
-- calls fetchBrackets() against THAT row's own id; finding zero brackets, it sets
-- note='no_brackets_configured' and returns 0 tax -- meaning every payroll run for comp_id=1
-- calculating Personal Income Tax right now would silently compute 0 THB withheld instead of the
-- real progressive amount, until this is fixed.
--
-- CompanyStatutoryRateVersionModel::cloneMasterForCompany() (the ONGOING per-company-activation
-- hook wired into CompanyProfileModel::save(), written after this migration) already copies
-- brackets correctly for any company activated from here on -- this migration is a one-time
-- correction for whichever company-scoped clone rows already exist from the EARLIER migration's
-- own Step 3, which did not.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-08_2_statutory_company_rate_version_brackets_backfill.sql

INSERT INTO `statutory_item_brackets`
    (`statutory_item_rate_history_id`, `bracket_order`, `min_amount`, `max_amount`, `rate`)
SELECT
    crh.`id`, mb.`bracket_order`, mb.`min_amount`, mb.`max_amount`, mb.`rate`
FROM `statutory_item_rate_history` crh
JOIN `statutory_items` si ON si.`id` = crh.`statutory_item_id` AND si.`calc_method` = 'progressive_bracket'
JOIN `statutory_item_rate_history` mrh ON mrh.`statutory_item_id` = si.`id` AND mrh.`comp_id` IS NULL AND mrh.`deleted_at` IS NULL
    AND mrh.`effective_date` <= CURDATE() AND (mrh.`end_date` IS NULL OR mrh.`end_date` >= CURDATE())
JOIN `statutory_item_brackets` mb ON mb.`statutory_item_rate_history_id` = mrh.`id`
WHERE crh.`comp_id` IS NOT NULL AND crh.`deleted_at` IS NULL
    AND NOT EXISTS (
        SELECT 1 FROM `statutory_item_brackets` existing WHERE existing.`statutory_item_rate_history_id` = crh.`id`
    );
