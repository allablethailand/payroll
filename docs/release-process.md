# Release Process — What to Update Alongside a Feature or Bug Fix

**Added 2026-09-05, Backlog Phase 13.** As of this phase, the app has 3 user-facing surfaces that
describe "what changed"/"what this app requires of you" — each is a separate mechanism with its
own audience and update trigger. Every future feature or bug fix should check this list before
being considered done.

## 1. Terms & Conditions (`terms_and_conditions` table, Help/Profile > "Terms and Conditions")

**Update when**: the actual legal terms of using this system change — a new privacy obligation, a
new data-handling commitment, etc. This is NOT for describing product changes (that's the Version
changelog below).

**How**: insert a new row with `is_active = 1` (and flip the previous active row's `is_active` to
`0` in the same statement/transaction — `TermsAndConditionsModel` assumes exactly one active row).
Every employee who already accepted an OLDER version will be re-prompted by the login-gate modal
automatically — no other code change needed.

**As of 2026-09-05**: the seeded content is a clearly-labeled PLACEHOLDER, not real legal text.
Replace it with the company's actual terms before this is relied on for anything binding.

## 2. Version / Changelog (`app_changelog_entries` table, Help > Version)

**Update when**: ANY user-visible feature ships or a user-visible bug gets fixed — this is the
running "what's new" list end users see. Internal refactors, test-only changes, or anything with
zero visible effect on the product don't need an entry.

**How**: insert one row per release/feature (`version_label`, `release_date`, bilingual
`title_th`/`title_en` + `body_th`/`body_en`, `sort_order` — higher sorts first alongside
`release_date` descending). Keep entries short and in plain language — this is for end users, not
a commit message. See the 4 seeded entries from this same round for the expected length/tone.

## 3. Help Guide checklist (`SetupGuideModel`, Help > Setup Guide)

**Update when**: a NEW category of company-level setup becomes a real prerequisite for running
payroll (e.g. a new mandatory integration, a new required master-data table). This is
COMPANY-level setup completeness, distinct from the employee-level profile-completeness % feature
elsewhere in the app — don't confuse the two when deciding whether something belongs here.

**How**: add a new `check*()` private method to `SetupGuideModel` and one more entry in
`checklist()`'s own array — each item needs real "is this actually done" logic (a query), it is
NOT a master table (see that model's own docblock for why). Most feature work will NOT need a new
checklist item — only add one when its ABSENCE would genuinely block a company from running a
correct payroll round.

## 4. Help Drawer content (`help_drawer_content` table, the floating "?" button on every page)

**Update when**: a page's own UI changes enough that its existing help text (if any) would mislead
someone, OR a genuinely new main page/workflow ships that deserves its own written explanation.

**How**: insert/update a row keyed by that page's `page_key` (derived automatically from the URL
path by `help-drawer.js` — see that file's own docblock for the exact derivation, e.g.
`/setup/tax-statutory` → `setup_tax_statutory`). As of 2026-09-05 only ~5 main pages
(`dashboard`, `employees`, `payroll_process`, `setup_payroll_configuration`,
`setup_tax_statutory`) have real content — every other page correctly shows a "not written yet"
placeholder rather than an error; that's expected, not a bug, until more content is written.

## What does NOT need any of the above

Internal refactors, test-only changes, dev-tooling changes, or anything with no visible effect on
what an end user does or sees.
