# Deduction Destination & Third-Party Remittance — Calculation vs Disbursement

Dev-facing architecture note for the feature that lets a deduction be routed somewhere other than
"reduces the employee's own net pay" — to the company itself, to another employee, or to a
third-party bank account — and tracks whether that money has actually been transferred. Written for
another team picking this up cold: what the two layers are, where each one lives, and the rule that
must never be broken when extending either of them.

## The one rule

> **Calculation and Disbursement are two separate engines that never call into each other's
> concerns.** Calculation produces a number. Disbursement decides where that number's money goes
> and tracks whether it got there. A destination is never allowed to change an amount, a
> calculation order, or a tax treatment — and a calculated amount is never allowed to depend on
> whether its destination has been transferred yet.

If a change you're making would violate this — e.g. "let's skip calculating a deduction until we
know its destination is confirmed" — stop and re-read this doc; that's almost certainly the wrong
shape for the change.

## Layer 1: Calculation

**Owns:** `PayrollRunModel::recalculate()` (`app/models/PayrollRunModel.php`) and everything it
calls into (`StatutoryCalculationEngine`, `SyncPayResolver`, etc.).

**Produces:** `payroll_run_details.earning_breakdown` / `deduction_breakdown` — one JSON array per
employee per run, one line per earning/deduction item, each carrying `code`, `name_th`/`name_en`,
`amount`, and (for a deduction) `payee_type`/`payee_employee_id`/`destination_id`.

**What it knows about destinations:** nothing more than "here's where this line is *tagged* to go."
It reads `payee_type`/`destination_id` off whatever source produced the line (a standing
`employee_earning_deductions` assignment, a recurring deduction, an ad-hoc manual line) and carries
those fields through into the breakdown untouched. It never resolves a bank account, never checks
whether a destination exists in `payment_destinations`, never asks whether money has moved. The one
exception that looks like an exception but isn't: `payee_type='employee'` where the payee **is**
part of the same run gets a real `TRANSFER_IN` earning line credited to that payee, computed in a
second pass after every employee's deduction lines are known (`recalculate()`'s own
"Employee-to-employee transfer deductions" section) — this is still pure calculation (both sides'
numbers are being computed), not disbursement, since no external transfer is involved.

**Rule of thumb:** if you're touching `recalculate()`, you're allowed to read `payee_type`/
`destination_id` and pass them through, but you should never need to write to
`payment_destinations` or `payroll_remittances` from in here. If you find yourself wanting to, the
work belongs in Layer 2 instead.

## Layer 2: Disbursement

**Owns:** `PaymentDestinationModel` and `PayrollRemittanceModel`
(`app/models/PaymentDestinationModel.php`, `app/models/PayrollRemittanceModel.php`).

**Only ever runs after a run reaches `approved`** — triggered from
`PayrollRunModel::approve()` calling `PayrollRemittanceModel::generateForRun()` right after the
state transition commits. Everything before that point (draft, pending_approval) is Calculation's
territory exclusively; Disbursement doesn't exist yet for a run that hasn't been approved.

**What it does:**
1. Reads every employee's ALREADY-COMPUTED `deduction_breakdown` for the run — never recomputes an
   amount.
2. Groups lines by destination: `payee_type='company'` → one row per run (auto-`success`, no real
   transfer, kept for audit only); `payee_type='other_person'` → grouped by `destination_id`; a
   `payee_type='employee'` line whose payee is **not** part of this run → grouped by
   `payee_employee_id` as an `employee_fallback` (the run-membership case is excluded here — that
   money already moved via Layer 1's `TRANSFER_IN`, see above).
3. Writes one `payroll_remittances` row per group + `payroll_remittance_items` breakdown rows.
4. Tracks status independently of anything calculation-related:
   `pending → transferred (evidence upload) → success`, or `transferred → failed (reason required)
   → pending` (retry). None of these transitions ever touch `payroll_run_details` or trigger a
   recalculation.

**Rule of thumb:** if you're touching `PayrollRemittanceModel`, you're reading `deduction_breakdown`
as a fixed input, not something you can influence. If a remittance amount looks wrong, the bug is
almost certainly in what Layer 1 already wrote — fix it there, then regenerate (the model refuses
to regenerate once any remittance has moved past `pending`, on purpose — see its own docblock).

## The full data flow, start to finish

```
┌─────────────────────────────────────────────────────────────────────┐
│ TEMPLATE (set once, per employee, doesn't change per run)            │
│                                                                       │
│  employee_earning_deductions.payee_type / destination_id             │
│  employee_recurring_deductions.payee_type / destination_id           │
│  payroll_run_manual_lines.payee_type / destination_id  (per-run,     │
│    ad-hoc — not really a "template", but same shape)                 │
└───────────────────────────────┬───────────────────────────────────────┘
                                 │ read by
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│ LAYER 1 — CALCULATION (PayrollRunModel::recalculate())               │
│                                                                       │
│  • For a recurring deduction ONLY: payroll_run_recurring_deduction_  │
│    overrides can override payee_type/destination_id for THIS RUN,    │
│    without touching the template row (see below).                   │
│  • Every other source's payee_type/destination_id passes straight    │
│    through, unmodified, run after run.                               │
│  • Amount is computed with ZERO knowledge of any of this.            │
│                                                                       │
│  writes → payroll_run_details.earning_breakdown / deduction_breakdown│
└───────────────────────────────┬───────────────────────────────────────┘
                                 │ run reaches 'approved'
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│ LAYER 2 — DISBURSEMENT (PayrollRunModel::approve()                   │
│           → PayrollRemittanceModel::generateForRun())                │
│                                                                       │
│  reads deduction_breakdown (read-only) → groups by destination       │
│  writes → payroll_remittances / payroll_remittance_items             │
│  tracked to completion independently, via the Third-Party            │
│  Remittance tab on Process Detail                                    │
└─────────────────────────────────────────────────────────────────────┘
```

## The per-run recurring-deduction override — why it's a 3rd table, not a 2nd column somewhere

A recurring deduction (`employee_recurring_deductions`, the indefinite-monthly-fee feature — not
the installment-based `employee_earning_deductions`) can have its payee overridden for ONE specific
run without touching the employee's own saved template. This is deliberately a separate table,
`payroll_run_recurring_deduction_overrides` (keyed by `run_id` + `recurring_id`), not a column on
the existing generic `payroll_run_line_overrides` table (which already covers per-run
amount-override/exclude for any earning/deduction line, keyed by `item_code`) — a payee override is
a different *kind* of thing from an amount override, and `item_code` isn't a safe key for it (many
different recurring deductions can legitimately share a code across employees/runs, but a payee
override is about routing money, not adjusting a number). `PayrollRunModel::
resolveRecurringDeductionPayee()` is the single place override-vs-template resolution happens, read
by `recalculate()`; the override table is otherwise invisible to everything downstream — the
resolved `payee_type`/`destination_id` land in `deduction_breakdown` exactly like every other
line's, so Layer 2 never needs to know an override even happened.

## `payee_type` — the field every layer reads the same way

One enum, shared by `employee_earning_deductions`, `employee_recurring_deductions`,
`payroll_run_manual_lines`, and `payroll_run_recurring_deduction_overrides`:

| `payee_type`     | What it means                                    | `payee_employee_id` | `destination_id` |
|------------------|---------------------------------------------------|----------------------|-------------------|
| `null`/absent    | Unspecified — reduces the employee's own net pay, never tracked by Disbursement at all | — | — |
| `employee`       | Paid to another employee's payroll — `TRANSFER_IN` if they're in this run, `employee_fallback` remittance if not | required | — |
| `company`        | Retained by the company — always tracked (audit-only, auto-`success`) | — | — |
| `other_person`   | A real third-party bank account (`payment_destinations`) — real remittance, tracked to `success` | — | required |
| `not_disbursed`  | Withheld but no money moves anywhere at all (distinct from `null` — that still reduces net pay; this is a no-op transfer entirely) | — | — |

`payment_destinations` (`app/models/PaymentDestinationModel.php`) is the catalog for `other_person`
only — `employee`/`company`/`not_disbursed` never touch it. AES-256-GCM encrypted `account_no`,
same convention as every other bank-account column in this app.

## "Other Income" / "Other Deduction" — a Calculation-layer concept, not a Disbursement one

Worth calling out since it's easy to assume it belongs to the routing side given the name: `is_other`
(`employee_earning_deductions.is_other` / `payroll_run_manual_lines.is_other`) is purely a
Calculation-layer aggregation concern — it makes `resolveManualLineRow()` derive a fixed
`OTHER_INCOME`/`OTHER_DEDUCTION` report-grouping code instead of a per-employee-typed one, so every
"Other" line collapses into one column in `PayrollRegisterReport` regardless of what free-text label
each admin typed. It has nothing to do with where the money goes — an "Other Deduction" line can
carry its own `payee_type`/`destination_id` exactly like any other deduction, completely
independently of the `is_other` flag.

## Where to look for each piece

| Concern | File |
|---|---|
| Template-level payee/destination (installment deductions) | `app/models/EmployeeEarningDeductionModel.php` |
| Template-level payee/destination (recurring deductions) | `app/models/EmployeeRecurringDeductionModel.php` |
| Ad-hoc per-run payee/destination (manual lines) | `PayrollRunModel::addManualLine()` |
| Per-run recurring-deduction override | `PayrollRunModel::recurringDeductionDestinationOverrideSave()`/`Remove()`/`recurringDeductionDestinationsForEmployee()` |
| Calculation → breakdown line assembly | `PayrollRunModel::recalculate()`, `resolveManualLineRow()`, `resolveRecurringDeductionPayee()` |
| Saved/one-off destination catalog | `app/models/PaymentDestinationModel.php` |
| Remittance grouping + status machine | `app/models/PayrollRemittanceModel.php` |
| Remittance batch trigger | `PayrollRunModel::approve()` |
| Third-Party Remittance UI (Process Detail) | `app/views/payroll/detail.php` (`#run-remittance-pane`), `public/js/payroll/detail.js` |
| Remittance Excel export | `app/services/reports/payment/ThirdPartyRemittanceSummaryReport.php` |
| Other Income/Deduction aggregation | `PayrollRunModel::resolveManualLineRow()`, `app/services/reports/internal/PayrollRegisterReport.php` |

## Tests

Each phase has its own dedicated, self-contained test file (fresh company/employees, transaction
rolled back, no shared dev-DB state) — read these before changing behavior, they encode the actual
contracts:

- `tests/payroll_remittance_test.php` — destination CRUD, grouping (company/other_person/
  employee_fallback), idempotent regeneration, full status machine.
- `tests/employee_recurring_deduction_destination_test.php` — template inheritance, per-run
  override winning without touching the template, revert, and that the remittance/TRANSFER_IN
  mechanisms need zero code changes to pick up a recurring-deduction-routed line.
- `tests/other_income_deduction_test.php` — aggregation across differently-labeled entries, report
  column stability, that destination selection is unaffected by `is_other`.
