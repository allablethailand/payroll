# Payslip / Employment Certificate — Field Requirements for Origami HR Sync

**Status: Data request draft, for internal review before forwarding to the Origami team.**
Prepared 2026-09-05 (Phase 12, T070) by walking the actual field catalogs the Payslip Template and
Employment Certificate Template canvas designers offer today (`master_payslip_field_types`,
`master_employment_certificate_field_types`, 23 + 21 fields), then tracing each one back to its
real data source in this codebase (`EmployeeSyncer.php`, `CompanySyncModel.php`, the candidates-
sync endpoint contract in `docs/origami-employee-sync-api-guide.md`) — not assumed from field
names alone.

## 1. Purpose

An Origami HR-linked company's employees/company data flows into Payroll automatically via sync.
Every field a Payslip or Employment Certificate template can place on a document should ideally be
one of those synced fields, so an Origami HR user never has to manually re-type HR data Origami
already has. This document lists every field both template designers currently expose, marks which
ones ALREADY sync correctly today, and — the actual ask — names the ones that do **not**, so the
Origami team can confirm whether the underlying data exists on their side and, if so, add it to the
existing sync contract.

## 2. Already covered — no request needed

These fields are already populated correctly today via the existing Origami→Payroll sync paths
(`EmployeeSyncer::sync()` for the bulk master-data sync, `CompanySyncModel::sync()` for company
data). Listed for completeness, not because anything is being asked for here.

| Field (used by) | Source |
|---|---|
| Employee no., name (TH/EN), department, position, branch, gender, nationality, date of birth, employment date/status/type | `EmployeeSyncer::sync()` — core HR fields, always-overwrite-from-Origami |
| National ID card no. (`employees.id_card_no`) | `EmployeeSyncer::sync()` (see §3 for one caveat) |
| Bank code / account no. / account name (for the masked bank field on the payslip) | `EmployeeSyncer::sync()`'s own 8-payroll-field batch (applied once, on first insert only) |
| Base salary (initial value only) | Same as above — first-insert only, a payroll admin's own later revision is never overwritten by a re-sync (by design, so a real salary correction entered in Payroll survives) |
| Company legal/local name, tax id, address, logo | `CompanySyncModel::sync()` |

## 3. Caveat on an already-synced field: national ID (`id_card_no`)

Not a new request, but worth flagging while this document is being reviewed: the **candidates
picker** (Employee List's "Sync from Origami" button, contract in
`docs/origami-employee-sync-api-guide.md`) is a **separate, narrower** endpoint from the older bulk
sync, and its own sample payload does **not** include a national-ID field at all. An employee
pulled in exclusively through that picker (rather than the older bulk sync) will land in Payroll
with no `id_card_no` — which every statutory export this app produces (ภ.ง.ด.1, สปส.1-10/6-09,
กยศ.) requires. **Ask**: add `id_card_no` (or whatever Origami's own field is called) to the
candidates endpoint's response so every path into Payroll carries it consistently.

## 4. Real gaps — requesting confirmation from Origami

### 4.1 Taxpayer ID (`employees.tax_id_no`)

**Never synced from Origami at all**, on any path (bulk sync or candidates picker) — confirmed by
reading `EmployeeSyncer.php` directly: the column is only ever read back (to preserve an
already-locally-entered value across an unrelated encryption-key rotation), never written from an
Origami payload field. Today it is either left blank or entered by hand in Payroll.

This is a genuinely real gap, not a template-field naming quirk — while researching the correct
format for ภ.ง.ด.1/ภ.ง.ด.1ก this same round (Phase 12, T071), every one of this app's own statutory
exporters was deliberately built against `id_card_no` instead, specifically because a real
taxpayer-ID field was known to be unreliable/absent for Origami-synced employees.

**Ask**: does Origami's own system track a taxpayer ID distinct from the national ID card number
at all? For a Thai national employee these are almost always the identical 13-digit number, but a
foreign employee can have a separately-issued Thai Tax ID that genuinely differs from any ID card
number — if Origami has this field, please add it to the sync contract; if Origami has no such
concept either, this gap can simply be closed here (drop the unused `tax_id_no` column/field from
future template work) rather than chased further.

### 4.2 Authorized signatory name + signature image (`companies.authorized_signatory_name` / `signature_path`)

**Explicitly documented as Payroll-owned, not synced** (`CompanySyncModel`'s own docblock lists
these among the fields deliberately left out of company sync). Both the Payslip Template and
Employment Certificate Template field catalogs offer a "Company Signatory" (text) and "Company
Signature" (image) field — used on both document types for the "signed by" line — and today a
company must type the name and upload a signature image separately inside Payroll's own Company
Profile settings, with zero connection to whatever signatory Origami itself may already have on
file for that company.

**Ask**: does Origami's own company record track an authorized signatory name and/or a signature
image? If yes, please add both to the company sync contract (`CompanySyncModel::sync()`'s existing
`GET` call) the same way `logo_url` already works (a URL Payroll downloads once and stores
locally). If Origami has no signature-image concept at all, at minimum the signatory NAME (a plain
text field) would still be a useful addition — a name is far more likely to already exist
somewhere in an HR system than a scanned signature image is.

## 5. Fields deliberately NOT being requested (by design, not a gap)

For completeness, so nothing here reads as an oversight:

- **Team** (Payslip/Employment Certificate's own "employee_team" field) — this is a Payroll-only
  concept (which client/project an outsourced employee is currently deployed to, see CLAUDE.md's
  own Team feature section) with no Origami equivalent; the sync guide's own §5 already confirms
  Origami's `team_ref_id` (if it exists at all) is an unrelated, Origami-side-only grouping.
- **Pay period, payment date, YTD summary, earning/deduction/statutory line items, gross/net
  amounts, payslip/document numbers, any free-text field** — all computed or generated by Payroll
  itself from payroll-run data, config, and document numbering. There is nothing for Origami to
  supply here; these were only in the field catalog dump for completeness, not overlooked.

## 6. Summary — the actual ask, in one place

1. Add a national-ID field to the **candidates picker** endpoint's response (§3), so it's
   consistently available regardless of which sync path an employee comes through.
2. Confirm whether Origami tracks a **taxpayer ID** distinct from the national ID card number (§4.1)
   — add to sync if yes, otherwise this app can retire the unused field.
3. Confirm whether Origami tracks an **authorized signatory name and/or signature image** per
   company (§4.2) — add to company sync if yes.

Everything else a Payslip or Employment Certificate can currently display is either already synced
correctly or is data Payroll itself is the correct owner of.
