-- 2026-09-03, Manual Entry / Platform UX review, Phase 5 (fee currency), Option A (user-confirmed):
-- the original request ("add a currency-unit selector to fee input fields, plus a company-level
-- default currency setting") had no literal target field to attach to -- every "fee" field in this
-- app (employee_earning_deductions.fee_percent, employee_recurring_deductions.fee_percent,
-- payroll_earning_deduction_types.default_fee_percent) is a PERCENTAGE paired with a fee_base, never
-- a bare money amount. Reinterpreted per the user's own choice as the broader, genuinely useful half
-- of the request: a real company-level default currency setting (this migration) + labeling the
-- monetary fields actually adjacent to fee/loan context with it (view/JS only, no further schema).
--
-- `currency_code` mirrors `master_countries.currency_code`/`bank_accounts.currency_code`'s own
-- varchar(3) shape (ISO 4217). Defaults to 'THB' (this app's original, single-currency assumption)
-- so an existing company that never touches the new Company Profile field keeps exactly the
-- behavior it already had. Backfilled from each company's own `registered_country` via
-- `master_countries` where a mapping exists (matches what a genuinely-new company would get from
-- Company Profile's own Country-driven default -- see company-profile.js's `renderCountrySpecificForm()`
-- for the client-side equivalent of this same derivation).
--
-- Deliberately NOT a new `master_currencies` table / FK -- this app only ever supports 4 countries
-- (TH/SG/MY/US, see public/json/country-config.json, itself a static file requiring a code deploy to
-- add a country), so a 4-value currency list is validated server-side against a small const array
-- (CompanyProfileModel::VALID_CURRENCY_CODES) and rendered client-side as a static Select2 (see
-- company-profile.php), matching how every other country-specific config in this app is already
-- static/code-deployed rather than DB-driven.

ALTER TABLE `companies`
  ADD COLUMN `currency_code` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'THB' AFTER `registered_country`;

UPDATE `companies` c
JOIN `master_countries` mc ON mc.countries_code = c.registered_country
SET c.currency_code = mc.currency_code
WHERE mc.currency_code IS NOT NULL AND mc.currency_code != '';
