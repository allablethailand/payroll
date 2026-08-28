# Origami Employee Sync — API Guide for Origami Dev Team

**Status: DRAFT — not yet implemented on the Origami side.** The Payroll side is fully built and
ready to integrate against this contract — the review/select/apply/log workflow, the picker UI, and
the audit log are all working today. The only missing piece is these 2 endpoints on Origami's side;
once they exist, Payroll can connect to them with no further changes needed on our end.

## 1. What this is for

Payroll's Employee List page has a "Sync from Origami" button. The filter dropdowns themselves
(Department / Type / Position / Team) are populated from Origami, not from Payroll's own local
data — an admin can only ever pick a value Origami can actually answer for. The admin picks any
combination of these (all optional), clicks Fetch, and gets back a list of candidate employees from
Origami matching that filter. Payroll splits them into **New** (not yet in Payroll) and **Already
Exists** (matched to a Payroll employee already, with an "update available" indicator if any field
differs), lets the admin tick which ones to pull in, and applies only those — logging every apply as
an audit entry.

This is **pull-based and admin-reviewed**, unlike the existing Origami→Payroll master-data sync
(department/position/shift/employee bulk sync, `EmployeeSyncer::sync()`), which is a
fetch-everything-and-auto-apply engine with no review step. Both ultimately write through the same
`employees` table matching rules; this guide only covers the 2 NEW filtered/interactive endpoints.

## 2. Two endpoints, not one

**2026-08-28 update:** the filter dropdowns themselves (Department/Position/Team/Type) must be
populated FROM Origami too, not from Payroll's own local data — an admin should only ever be able
to pick a filter value Origami can actually answer for. This means Origami needs to expose a small
second endpoint alongside the candidates one:

```
GET /api/hr/employees/filter-options?company_ref_id=...
```

```json
{
  "status": true,
  "departments": [{ "ref_id": 12, "name_th": "...", "name_en": "..." }],
  "positions": [{ "ref_id": 34, "name_th": "...", "name_en": "..." }],
  "teams": [{ "ref_id": 56, "name": "..." }],
  "types": [{ "value": "support", "label_th": "...", "label_en": "..." }, { "value": "employee", "label_th": "...", "label_en": "..." }]
}
```

The 4 lists here should be exactly the distinct values that actually appear across this company's
employees — not every department/position that has ever existed. Whatever `ref_id`/`value` a picker
selects from this response is sent straight back as the matching filter param on §3's endpoint
below, unchanged.

## 3. The candidates endpoint (proposed — Origami team may adjust routing/method to fit their own conventions)

```
GET /api/hr/employees/candidates
```

### Auth

Whatever mechanism already protects other Payroll↔Origami calls in this integration (Bearer token /
API key), consistent with the convention already used for the Origami→Payroll direction elsewhere.

### Request parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `company_ref_id` | int | **yes** | Origami-side company id. Equals `companies.ref_id` on the Payroll side. |
| `department_ref_id` | int | no | Filter to one department. Same id space as Origami's existing department sync feed. |
| `position_ref_id` | int | no | Filter to one position. Same id space as Origami's existing position sync feed. |
| `team_ref_id` | int | no | Filter to one team/group. **New concept** — see §4. |
| `type` | string | no | `support` or `employee`. **New concept, not yet confirmed** — see §4. |

### Response

```json
{
  "status": true,
  "data": [
    {
      "ref_id": 123456,
      "employee_no": "EMP-0001",
      "name_th": "สมชาย", "surname_th": "ใจดี",
      "name_en": "Somchai", "surname_en": "Jaidee",
      "date_of_birth": "1990-01-01",
      "gender": "male",
      "department_ref_id": 12, "department_code": "DEPT-01", "department_name_th": "...", "department_name_en": "...",
      "position_ref_id": 34, "position_code": "POS-01", "position_name_th": "...", "position_name_en": "...",
      "shift_ref_id": 78, "shift_code": "SHIFT-01", "shift_name_th": "...", "shift_name_en": "...",
      "shift_start_time": "08:00:00", "shift_end_time": "17:00:00", "shift_break_minutes": 60,
      "team_ref_id": 56, "team_name": "...",
      "type": "support",
      "employment_date": "2024-01-01",
      "employment_status": "probation",
      "personal_email": "somchai@example.com",
      "mobile_no": "0812345678",
      "is_active": true,
      "salary_type": "monthly",
      "base_salary_amount": "25000.00",
      "tax_calculation_method": "average",
      "payment_type": "bank",
      "bank_code": "014",
      "bank_account_no": "123-4-56789-0",
      "bank_account_name": "Somchai Jaidee",
      "bank_branch": "Head Office"
    }
  ]
}
```

**2026-08-28 update — payroll fields are now part of the contract.** Per explicit follow-up request
("synced data must be complete enough to actually run a payroll round with"), Payroll now applies
these 8 fields on first insert too (previously a synced employee landed with ₿0 base salary and no
bank info at all, unusable in a real payroll run until an admin completed it by hand). All 8 are
**optional** — if a field is absent, Payroll falls back to a safe default (`salary_type: monthly`,
`base_salary_amount: 0.00`, `tax_calculation_method: average`, `payment_type: bank`, bank fields
left empty) rather than rejecting the candidate.

| Field | Type | Notes |
|---|---|---|
| `department_code` / `position_code` / `shift_code` | string | **New, 2026-08-28.** See "Auto-creating missing master data" below — required for that to work well, though Payroll falls back to a derived code if absent. |
| `shift_start_time` / `shift_end_time` / `shift_break_minutes` | string (`HH:MM:SS`) / string / int | Only used if `shift_ref_id` doesn't already exist in Payroll — see below. |
| `salary_type` | string | `monthly` / `daily` / `hourly` |
| `base_salary_amount` | string (decimal) | Stored encrypted on Payroll's side (AES-256-GCM) — send the plain number as a string. |
| `tax_calculation_method` | string | `average` / `actual` |
| `payment_type` | string | `bank` / `cash` — when `cash`, the 4 bank fields below can be omitted. |
| `bank_code` | string | Thailand bank code (e.g. `004`=KBANK, `014`=SCB) — same code space as any other bank-code field already used elsewhere in this integration. |
| `bank_account_no` | string | Stored encrypted on Payroll's side. |
| `bank_account_name` | string | |
| `bank_branch` | string | |

### Field notes

- **`ref_id`** — required. Origami's own numeric id for this employee. Payroll matches an incoming
  candidate to an existing Payroll employee by this field FIRST.
- **`employee_no`** ("payroll code") — Payroll falls back to matching by this field if no Payroll
  employee has this `ref_id` linked yet (e.g. the employee already exists in Payroll from manual
  entry or a spreadsheet import, and this is the first time it's being linked to Origami).
- **`department_ref_id` / `position_ref_id`** — must be the SAME id space Origami's existing
  department/position sync feed already uses (`fetchDepartments()`/`fetchPositions()` in the
  existing integration) — do not invent a new id space for these two.
- **`employment_status`** — one of `probation` / `permanent` / `contract` / `resigned` / `terminated`
  (Payroll's own enum — please map Origami's own status values to these 5).
- **`is_active`** — when `false`, Payroll marks the matched employee `resigned` instead of updating
  their other fields (same behavior as the existing bulk sync).
- All other fields mirror the existing `fetchEmployees()` contract already used by Origami→Payroll
  master-data sync — no new fields there.

### Auto-creating missing master data (2026-08-28)

**Department/position/shift no longer need to be pre-synced into Payroll before an employee
referencing them can be pulled in.** If `department_ref_id` (or position/shift) doesn't match
anything Payroll already has, Payroll now creates a new department/position/shift row on the spot,
using whichever of `department_code`/`department_name_th`/`department_name_en` (etc.) the candidate
provided — falling back to a generated code (`ORG-{ref_id}`) if even the code is missing. This is
specific to this employee-sync flow; Payroll's older, separate bulk master-data sync (department/
position/shift as their own step, done before employees) still refuses to guess and fails a row
instead — the two are intentionally different.

Practically: **please send `department_code`/`position_code`/`shift_code` whenever you have them.**
A derived `ORG-{ref_id}` code is a safe fallback, not something to rely on — it won't match a
"real" department code a payroll admin might already recognize from your own system.

## 4. What Payroll does with the response

1. Loads the filter dropdowns from §2's filter-options endpoint.
2. Matches each candidate from §3's candidates endpoint to an existing Payroll employee (`ref_id` →
   `employee_no` fallback, see §3's field notes).
3. Unmatched candidates go in the **New** tab.
4. Matched candidates go in the **Already Exists** tab, with a per-field diff against the
   HR-owned fields (name/DOB/gender/email/mobile/employment date+status — the same fields the
   existing bulk sync already treats as "always overwrite from Origami," see `EmployeeSyncer`'s own
   field-ownership docblock). If any differ, that row is flagged "Update available." The 8 payroll
   fields (§3) are **not** part of this diff — see the next point.
5. The 8 payroll fields (`salary_type` through `bank_branch`) are applied **once, on first insert
   only** — a re-sync of an already-existing employee never overwrites them, even if the value
   Origami returns has since changed. This protects whatever a payroll admin has since corrected
   locally (e.g. a salary revision entered directly in Payroll). If Origami's own values change
   after the fact, that's expected to be handled as a normal payroll admin edit, not a re-sync.
6. The admin ticks which rows to pull in and clicks Sync. Payroll re-fetches the candidates endpoint
   with the same filters (never trusts client-cached candidate data) and applies only the selected
   `ref_id`s.
7. Every apply is logged (who, when, how many succeeded/failed, per-item error detail) to Payroll's
   own audit table — visible via a "Sync Log" button next to the picker.

## 5. Open questions for Origami's side (not yet confirmed)

- **`type` ("support" / "employee")** — this is a filter dimension explicitly requested on the
  Payroll side, but has **no existing equivalent field anywhere in Payroll's own employee schema**.
  Please confirm what this classification actually represents on Origami's side (and whether more
  than 2 values will ever exist) before this is finalized — Payroll currently treats it as a purely
  informational/filter-only value and does not store it anywhere locally.
- **`team_ref_id` / `team_name`** — Payroll has its own, unrelated local "Team" concept (which
  client/project an outsourced employee is deployed to, assigned manually by a Payroll admin after
  the employee already exists in Payroll). This field is assumed to be an **Origami-side-only**
  grouping with no relationship to Payroll's own Team feature. If Origami has no such concept at
  all, this field can simply be omitted from the response — the Team filter will just have nothing
  to narrow by.
- Pagination / result-size limits are not yet specified. If a company could return hundreds or
  thousands of employees, let's agree on a paging or result-cap convention before go-live.

