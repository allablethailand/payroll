-- 2026-09-04, Backlog Phase 9, T047: "generic/extensible form for future deduction types" -- not
-- hardcoded to only today's item set. Confirmed via AskUserQuestion which of 2 real gaps to close:
-- category/calc_base/rounding_mode being hardcoded PHP const arrays + DB ENUMs (adding a new value
-- needs a code deploy) vs. calc_method='formula' only supporting ONE hardcoded formula shape
-- (% + one extra tier above one threshold -- touching this needs new calculation-engine code, same
-- risk class as T045). User picked the SAFER scope: master tables for the closed-set-that-may-grow
-- fields only, formula stays as-is for now.
--
-- Deliberately did NOT include `rounding_mode` here (only category + calc_base) -- confirmed by
-- reading StatutoryCalculationEngine::applyRounding() directly: rounding modes are tied to actual
-- PHP math (round()/ceil()/floor()/truncate), a small, universally-understood, near-permanently-
-- closed set -- adding a genuinely NEW rounding mode needs a new `switch` case regardless of whether
-- the value lives in an enum or a master table, so converting it wouldn't remove any real future
-- code-change need. Same precedent CLAUDE.md already documents for OT Rate's own `calculation_base`
-- (tied to real calc logic, intentionally NOT a master table).
--
-- `category` (TaxStatutoryModel::CATEGORIES) is a pure classification/display label with ZERO
-- calculation logic tied to it -- a genuinely clean master-table candidate, matches this project's
-- own "closed set that may grow, business-addable without a deploy" convention exactly.
--
-- `calc_base` is DIFFERENT and needed one more safety fix alongside the table (see the companion
-- PHP change in StatutoryCalculationEngine::calculateLine()): each value is really a KEY into the
-- $salaryContext array PayrollRunModel::recalculate() populates -- adding a new calc_base row here
-- alone does NOT make the payroll engine actually compute that new context key. Before this round,
-- an item picking an unrecognized calc_base silently computed 0.0 with NO warning at all
-- (`array_key_exists($baseKey, $salaryContext) ? ... : 0.0`) -- a dangerous silent-zero failure mode
-- for a real payroll deduction. Converting this to a master table makes that risk MORE reachable (an
-- admin can now add a calc_base value with zero engine wiring behind it), so this migration is
-- shipped together with a companion code fix that turns the silent 0.0 into an explicit
-- 'unrecognized_calc_base' note surfaced in the calc breakdown -- makes the gap visible/diagnosable
-- instead of quietly wrong, without changing any currently-correct calculation's actual output.
--
-- Same "code stored by its own varchar `code` string, not a numeric FK id" convention this project's
-- own `master_payroll_source_events`/`payroll_earning_deduction_types.source_event_code` pair
-- already established (app-layer validated via a lookup query, no DB FK constraint -- same
-- "deleted_at/soft-delete tables don't get real FKs pointing at them" reasoning applies here as it
-- does everywhere else this pattern is used).

CREATE TABLE `master_statutory_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_th` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_statutory_category_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `master_statutory_categories` (`code`,`name_th`,`name_en`,`sort_order`) VALUES
('tax','ภาษี','Tax',10),
('social_insurance','ประกันสังคม','Social Insurance',20),
('provident_fund','กองทุนสำรองเลี้ยงชีพ','Provident Fund',30),
('other','อื่นๆ','Other',40);

CREATE TABLE `master_statutory_calc_bases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_th` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_statutory_calc_base_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `master_statutory_calc_bases` (`code`,`name_th`,`name_en`,`sort_order`) VALUES
('basic_salary','เงินเดือนพื้นฐาน','Basic Salary',10),
('gross_salary','เงินเดือนรวม','Gross Salary',20),
('taxable_income','เงินได้ที่ต้องเสียภาษี','Taxable Income',30),
('net_income','เงินได้สุทธิ','Net Income',40),
('sso_eligible_earnings','รายได้ที่หักประกันสังคม','SSO-Eligible Earnings',50),
('pf_eligible_earnings','รายได้ที่หักกองทุนสำรองเลี้ยงชีพ','Provident Fund-Eligible Earnings',60),
('custom','กำหนดเอง','Custom',70);

-- Widened from ENUM to varchar -- existing string VALUES are unaffected (an enum's stored/read
-- representation IS its label string, identical to what a varchar column already holds), only the
-- set of values MySQL will accept at the column-constraint level changes (now open, validated at the
-- application layer instead via master_statutory_categories/master_statutory_calc_bases -- same
-- "app-layer re-check" convention this project's own CLAUDE.md already documents for soft-delete-
-- aware unique constraints, extended here to this master-table-referencing case).
ALTER TABLE `statutory_items`
  MODIFY COLUMN `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  MODIFY COLUMN `calc_base` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL;
