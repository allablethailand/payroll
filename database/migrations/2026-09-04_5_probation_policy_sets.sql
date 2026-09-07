-- 2026-09-04, Backlog Phase 10, T056: "Probation setting gains Clone + Assign, using T055's
-- template" -- converts `company_payroll_policies`' 9 probation_* columns (ONE company-wide
-- singleton row today) into `probation_policy_sets`, a real multi-row, cloneable, per-scope-
-- assignable table -- mirroring OtRateSetModel/ot_rate_sets (Sets + exactly-one-mandatory-Default +
-- Assign), the closest existing precedent in this codebase for "one setting -> many named,
-- cloneable, assignable Sets". See project_ot_rate_set_redesign_2026_08_30 memory for the original
-- design this one deliberately follows.
--
-- The ONE deliberate difference from ot_rate_sets: assignment scoping uses T055's brand-new,
-- GENERIC `entity_assignments` table (entity_type='probation_policy_set') instead of a 4th bespoke
-- per-feature assignment table -- ot_rate_sets predates T055 and still has its own
-- ot_rate_set_assignments; T054/T056 are T055's own docblock-recommended first real consumers, this
-- is the literal reason T055 was built. See app/models/EntityAssignmentModel.php.
--
-- `company_payroll_policies.probation_*` columns are LEFT IN PLACE, deliberately NOT dropped here --
-- they become unused/legacy once PayrollPolicyModel::probationSettings() is repointed at this new
-- table (see that method's own updated docblock) -- same "leave a now-dead column rather than a
-- destructive drop" caution this project already applies elsewhere (e.g. orphaned upload files).
-- `PayrollPolicyModel::save()`'s own handling of those 9 keys is UNCHANGED/untouched (harmless,
-- unreachable from the new UI going forward, but not worth touching either -- see this task's own
-- memory entry for the full reasoning).
--
-- Backward compatibility: every company that has EVER configured non-default probation policy gets
-- exactly one auto-created "Default" Set (is_default=1) carrying its EXACT current probation_*
-- values over verbatim -- this is what makes the schema change 100% behavior-preserving for every
-- existing company on day one, before any admin ever opens the new UI. A company that never touched
-- probation policy (every probation_* column still at its column default) gets NO row at all --
-- PayrollPolicyModel::probationSettings() falls back to the exact same all-defaults shape it already
-- returns today when no row/Set exists, so there is no behavior change for that case either.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_5_probation_policy_sets.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

CREATE TABLE `probation_policy_sets` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `set_name_th` VARCHAR(150) NOT NULL,
    `set_name_en` VARCHAR(150) NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    -- Exact same 9 columns/types/defaults as company_payroll_policies' own probation_* columns
    -- (see 2026-08-30_4_probation_pay_policy.sql, 2026-09-02_15_probation_intern_policy_extension.sql,
    -- 2026-09-02_17_probation_intern_defer_sso_and_tax_default.sql for their original definitions).
    `probation_period_days` INT UNSIGNED NULL DEFAULT NULL,
    `probation_defer_pvd` TINYINT(1) NOT NULL DEFAULT 0,
    `probation_defer_recurring_earning` TINYINT(1) NOT NULL DEFAULT 0,
    `probation_base_salary_ratio` DECIMAL(5,2) NULL DEFAULT NULL,
    `probation_leave_days_limit` INT UNSIGNED NULL DEFAULT NULL,
    `allow_leave_during_probation` TINYINT(1) NOT NULL DEFAULT 1,
    `probation_ot_eligible_default` TINYINT(1) NULL DEFAULT NULL,
    `probation_defer_sso` TINYINT(1) NOT NULL DEFAULT 0,
    `probation_tax_exempt_default` TINYINT(1) NULL DEFAULT NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_by` INT NULL DEFAULT NULL,
    `created_by` INT NULL DEFAULT NULL,
    `updated_by` INT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pps_comp` (`comp_id`, `status`, `deleted_at`),
    CONSTRAINT `fk_pps_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Backfill: one Default Set per company that has ever configured probation policy (any probation_*
-- column different from its own bare column default). A company whose row is entirely at defaults
-- (never opened the tab) is intentionally skipped -- see this file's own header comment above.
INSERT INTO `probation_policy_sets`
    (`comp_id`, `set_name_th`, `set_name_en`, `is_default`, `status`,
     `probation_period_days`, `probation_defer_pvd`, `probation_defer_recurring_earning`, `probation_base_salary_ratio`,
     `probation_leave_days_limit`, `allow_leave_during_probation`, `probation_ot_eligible_default`,
     `probation_defer_sso`, `probation_tax_exempt_default`)
SELECT
    `comp_id`, 'ค่าเริ่มต้น', 'Default', 1, 'active',
    `probation_period_days`, `probation_defer_pvd`, `probation_defer_recurring_earning`, `probation_base_salary_ratio`,
    `probation_leave_days_limit`, `allow_leave_during_probation`, `probation_ot_eligible_default`,
    `probation_defer_sso`, `probation_tax_exempt_default`
FROM `company_payroll_policies`
WHERE `probation_period_days` IS NOT NULL
   OR `probation_defer_pvd` <> 0
   OR `probation_defer_recurring_earning` <> 0
   OR `probation_base_salary_ratio` IS NOT NULL
   OR `probation_leave_days_limit` IS NOT NULL
   OR `allow_leave_during_probation` <> 1
   OR `probation_ot_eligible_default` IS NOT NULL
   OR `probation_defer_sso` <> 0
   OR `probation_tax_exempt_default` IS NOT NULL;
