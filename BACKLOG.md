# Backlog

Deferred items noted during work sessions. Not scheduled — pick up when asked.
Format: Title / 1-2 line detail / Source (which batch).

---

## langReady-gating not applied to 7 files (bind-only ready handlers, no initial langData render)

Batch 2 item 0's original list (re-grepped 2026-09-10) was actually 29 files, not 28 as counted
verbally — noting the discrepancy rather than silently forcing it to match, same convention as
that entry's own earlier note.

**2026-09-11: 22 of the 29 wrapped in the same `(window.langReady || Promise.resolve()).then(...)`
pattern payroll-process (`detail.js`/`index.js`/`approval.js`) already used** — every ready handler
whose body actually renders text sourced from `langData`/`getLangValue()` (select2 static/ajax
option labels, DataTable initial load, fetched-and-rendered content, etc). Done in one pass, one
report; see that commit's own message for the full per-file list.

**7 deliberately left UNCHANGED** — their `$(document).ready(...)` body only binds a `shown.bs.tab`
handler (or, for 2 of them, does layout/count work with no langData-dependent render at all) and
renders nothing itself at initial page load, so there's no race to fix:

```
public/js/input.js                                   -- textarea auto-expand only, no lang render
public/js/notifications.js                            -- unread-count badge + click bind only
public/js/setup/document-numbering.js                 -- binds shown.bs.tab only, no immediate render
public/js/setup/email-queue-log.js                    -- binds shown.bs.tab only, no immediate render
public/js/setup/employment-certificate-request.js     -- binds shown.bs.tab only, no immediate render
public/js/setup/payslip-delivery-log.js                -- binds shown.bs.tab only, no immediate render
public/js/setup/payslip-distribution.js                -- binds shown.bs.tab only, no immediate render
```

If any of these 7 later grow an immediate (not tab-gated) langData-dependent render, apply the same
wrapper then — not preemptively.

**Source:** Batch 2, item 0 (original list); resolved Batch 3B backlog item 2 (2026-09-11).

---

## 8 modals outside payroll-process whose footer behavior changed (Batch 1 selector fix)

Batch 1, item 1 fixed `app.js`'s global `show.bs.modal` handler (`.find('> .modal-footer')` →
`.find('.modal-footer')`), which happened to also fix the exact same double-footer bug in 8 modals
outside payroll-process (their own footer sits inside a `<form>`, same root cause) — as a side effect
of the shared fix, not a deliberate markup change to these 8. They were never given a `data-footer`
attribute (out of scope for that batch), so worth a quick visual check next time one of these pages
is touched:

```
#documentNumberingModal   -- Setup > Document Numbering
#payrollCycleModal        -- Setup > Payroll Configuration
#itemModal                -- Setup > Payroll Configuration
#payslipRequestModal      -- Setup > Payslip Requests
#ecrRequestModal           -- Setup > Employment Certificate Requests
#recurringEarningModal    -- Employee Detail
#recurringDeductionModal  -- Employee Detail
#employeeRecheckEditModal -- Employee List
```

**Source:** Batch 1, item 1 (system-wide modal audit).

---

## Phase design (deferred, not logic — style/UX pass)

- **Employee Detail tab density** — the parts of Batch 2 item 7 not already done in the
  logic-only pass (filter-icon pruning, action-button dropdown consolidation, single-line headers)
  were explicitly scoped to structure only; anything visual/spacing beyond that waits for a design
  pass.
- **Modal header design** — no dedicated pass yet on header layout/icon conventions across modals
  (raised alongside the footer-prop work in Batch 1, not itself in scope there).
- **Helper/hint text pass** — modal/form helper text wording and placement not covered by the
  logic-only batches; a copy/UX pass, not a bug fix.
- **Confirm-before-close for a modal with unsaved changes** — Batch 1, item 1, step 5 explicitly
  deferred this ("ยังไม่ต้องทำ confirm ก่อนปิดเมื่อฟอร์มมีการแก้ไขค้าง") when the footer-prop system was
  built; the `data-footer` type (form/confirm/view/none) set up in Batch 1 is the natural hook to
  wire this into once it's picked up.

**Source:** Batch 1 (footer-prop work, steps 5-6) + Batch 2 item 7 discussion.

---

## Clean up old fixture data in `payroll_sync_processes`

Investigating item 5's ABSENT-label question turned up several `payroll_sync_processes` rows
(id 22/39/42/65/66/94/107/189) still sitting at `status='pending'`, received 2026-08-18, where
100% of employees show `absent_days` near-equal to `working_days` (25/27, 24/27, etc.) — looks
like test/fixture data used to exercise `SyncPayResolver`'s ABSENT multi-unit dedup logic, not
real synced attendance (recent real pulls, e.g. process 190/192 from 2026-09-08, show 0% of
employees with elevated absence). Not a mapping bug — `absent_days` correctly matches the
payload's own `item_values` "days"-unit ABSENT representation.

**Before UAT/production:** clean these out — either delete the pending fixture processes
outright or write a migration/script removing only `pending` processes received before a cutoff
date. **Must confirm first that no real payroll run references any of these process ids** before
deleting anything.

**Source:** Batch 2, item 5 investigation (2026-09-10).

---

## Annual Income & Tax Summary page has no export at all (any tab)

Item 6's original spec asked to match the tax-withholding tab's "export capability" — investigation
found `/reports/annual-summary` has **zero export function on any of its now-4 tabs** (confirmed via
grep across the controller/JS/view; the view's own docblock states this is deliberately "an
interactive page...not a generate-and-download document card like the rest of the Reports module").
Confirmed via AskUserQuestion: the new SSO tab matches this — no export — rather than adding a new
export capability that doesn't exist anywhere on this page today.

**If export is wanted here in the future:** it would be new scope for the whole page (all 4 tabs),
not a small addition to one tab — decide the format (Excel only, matching Reports module convention?)
and whether it applies to all tabs or just specific ones before starting.

**Source:** Batch 2, item 6 investigation (2026-09-10).

---

## No Setup management page for `company_hospitals`/`company_pvd_plans` (rename/merge duplicates)

Item 7b's SSO Hospital + PVD Investment Plan fields are a generic "company lookup list" (Select2
"tags" -- pick an existing entry or type a new one, auto-created via `CompanyLookupListModel::
resolveOrCreate()`, case-insensitive/trimmed dedup on create). By explicit design (confirmed with
the user), there is **no Setup page to manage either list yet** -- an HR admin who fat-fingers a
name gets a genuine near-duplicate row sitting alongside the real one forever (dedup only catches
an EXACT case-insensitive/trimmed match, not typos), and there's no UI to rename or merge one into
another.

**Fix, when needed:** a small CRUD page (list/rename/soft-delete/merge-into-another) for both
tables, matching this app's existing Organizational Structure sub-tab pattern (Team/Branch/etc.) --
`CompanyLookupListModel` already has everything CRUD needs except rename/merge, which would be new
methods on that same shared model (not 2 new ones, per this feature's own "one model, not 2" rule).

**Source:** Batch 3A item 7b, explicit instruction (2026-09-10).

---

## `scripts/migrate.php`'s auto-detection can't classify 38 historical migration files

Confirmed by actually running `php scripts/migrate.php status` against the real dev DB (which has
every historical migration already applied): of 138 files, 99 were correctly auto-detected as
already applied (`CREATE TABLE`/`ADD COLUMN` marker found and confirmed to exist), but 38 have no
single `CREATE TABLE`/`ALTER TABLE ... ADD COLUMN` the detector's regex can extract as a
representative marker at all (pure `DROP`/`INSERT`-only seed files, multi-statement files with only
`ADD CONSTRAINT`/`MODIFY COLUMN`/etc.) -- these show up under `status`'s "Unknown" bucket and `up`
deliberately SKIPS them rather than guessing whether to run them (see `scripts/migrate.php`'s own
docblock).

**Why this matters for a real prod deploy**: on a target database that's missing one of these 38
for real (not just "can't be auto-confirmed"), `up` would silently skip it without applying it --
looks like a no-op success, but the schema gap remains. Run `php scripts/migrate.php status` and
manually cross-check the 38 listed filenames against the target database before trusting `up`
covered everything on a NEW environment (not just local dev, which is already known-current).

**Update (2026-09-10, same batch, item 3 follow-up): the escape hatch this entry asked for now
exists.** `mark <file>` (single) and `mark-all-unknown --reason="..." --yes` (bulk, refuses without
`--yes`/a reason/while anything is genuinely pending) both record a human's own review without
`migrate.php` needing to re-derive it. Dev's own 38 files were reviewed and marked this way on
2026-09-10 (`status` now shows 0 unknown, 0 pending on dev). **Left open, not closed**: the
underlying detector limitation (only 2 DDL shapes recognized) is still real for any FUTURE
migration file of an unusual shape, and dev being clear doesn't mean any OTHER environment
(production especially) has been reviewed -- see `docs/releases/2026-09-11-batch3b.md`'s own
4-step first-deploy sequence for exactly that reason.

**Source:** Batch 3B item 0, explicit instruction (2026-09-10).

---

## Snapshot department/position onto the run row at lock (or pull-in) time

Both the Process Detail employee table (`PayrollRunModel::getDetails()`, Batch 3C item 7) and
`PayrollRegisterReport`'s own Excel/PDF export (`PayrollReportDataModel::getRunDetails()`) resolve
an employee's Department (and Position, where shown) by joining the employee's **CURRENT**
`structure_departments`/`structure_positions` row live, at read time -- there is no department/
position snapshot stored anywhere on `payroll_run_details` itself. A department transfer or
reorg after a run is paid/locked silently rewrites how that OLD run displays retroactively: an
employee who was in "Sales" when a January run was paid, then moved to "Marketing" in March, will
show "Marketing" if that January run's Detail page or Payroll Register export is opened again
later -- the run no longer reflects what was actually true at the time it was run.

**Fix, when picked up:** snapshot `department_id`/`position_id` (or the resolved name strings
directly, TBD at design time) onto `payroll_run_details` per employee, either at the moment they're
pulled into the run (`recalculate()`) or at lock time specifically (whichever better matches what
"the record as of this run" should mean -- needs a decision, not assumed) -- **needs a migration**
(new column(s) on `payroll_run_details`, following this project's own migration-file convention)
and touches both read paths above once the snapshot exists (`getDetails()`/`getRunDetails()` would
read the snapshot instead of live-joining `employees`/`structure_departments` for any run at/after
whichever point the snapshot is taken). Scope this as its own task, not a drive-by addition to
whatever else is in flight when it's picked up.

**Source:** Batch 3C item 7, explicit instruction (2026-09-11).

---

## `payroll_run_audit_logs` has no auto/admin trigger-type distinction for `recalculate`

`PayrollRunModel::adminRecalculateCount()` (Batch 3C item 4, decision 2's `hasAdminWork()`) counts a
run's own `action='recalculate'` audit-log rows and subtracts 1 whenever `sync_process_id` is set,
to exclude the ONE automatic recalculate `create()` itself fires synchronously for any sync-linked
pull. Confirmed via reading `logAudit()`'s own INSERT columns (`run_id`/`from_state`/`to_state`/
`action`/`note`/`performed_by`/`ip_address`/`user_agent`) that there is no way to distinguish an
auto-triggered recalculate from a genuinely admin-clicked one any other way -- both `create()`'s own
internal call and a real "Recalculate" button click on Detail go through the exact same
`recalculate()` method, log identically, and even carry the SAME `performed_by` (the user who
initiated the pull, passed straight through). The "count then subtract 1" heuristic only works
because there is currently at most one auto-recalculate per run ever (at creation); it would silently
undercount if a future feature ever added a second automatic recalculate somewhere else in the run's
lifecycle.

**Fix, when picked up:** add a trigger-type column to `payroll_run_audit_logs` (e.g.
`trigger_type enum('auto','admin')`, needs a migration) and have every internal auto-recalculate call
site set it explicitly, so `adminRecalculateCount()` can filter on it directly instead of subtracting
a count. Low priority while there's only ever one auto-recalculate per run to account for.

**Source:** Batch 3C item 4 decision 2, user question during commit review (2026-09-11).

---

## `PayrollRunModel::update()` doesn't auto-recalculate after a cycle_id/period/run_purpose change on a no-admin-work draft

Decision was made (Batch 3C item 4, decision 2's `hasAdminWork()` correction) that a draft run with
NO admin work yet still allows changing `cycle_id`/`period_dates`/`run_purpose`(+flags)/
`merge_target` through — same as before this item — but `update()` itself never re-runs
`recalculate()` afterward the way `create()` does for a fresh pull. The run's `payroll_run_details`
keep reflecting whatever was last calculated (under the OLD cycle_id/period/purpose) until the admin
explicitly hits Recalculate again — a real staleness gap, though a low-risk one since there's no
admin work yet to silently invalidate.

**Decided NOT to auto-recalculate here** — instead, item 4's own sub-step 4b (JS mirror of
`runFieldLockState()`) adds a client-side warning after a successful save: if a calc flag changed on
a draft run that already has `employee_count > 0`, show a message telling the admin to hit
Recalculate themselves. No silent auto-recalc, no schema/backend change.

**Source:** Batch 3C item 4 decision 2 follow-up, explicit instruction (2026-09-11).

---

## Remittance Breakdown modal: consider DataTable if rows exceed 5

Batch 3C item 6 confirmed (via AskUserQuestion) that the Remittance Breakdown modal stays a plain
`<table>`, not a DataTable, alongside the Attendance sub-tab's 7-row edit grid, Calculation
Breakdown modal's 3 tables, and Raw Sync Data modal -- all 4 are per-employee drill-downs opened
from a modal, not top-level tabs of the Detail page itself, and typically show few rows. This one
specifically was flagged as a softer case than the other 3: a company with many loan/remittance
creditors on one employee could genuinely exceed a handful of rows, unlike the others which are
fixed-field forms with no realistic growth. Deferred as a logic-only item to the phase design pass
(style/UX pass, not a bug) rather than converting pre-emptively with no evidence it's needed.

**Fix, when picked up:** if real usage shows a company with several remittance rows per employee,
wire this modal's table through the same `initSharedDataTable()` helper (app.js, added this same
batch) used for the 4 tabs converted this round.

**Source:** Batch 3C item 6, explicit instruction (2026-09-11).

---

## Phase design: review row-action icon/tooltip clarity app-wide

Batch 4 item 2b fixed one specific instance -- Tax & Statutory's row-level "Manage" button
(sliders icon) already opens an editable form for a company's own custom item, but its tooltip
only ever said "Manage," giving no hint that it also functions as Edit. Fixed there via a
scope-aware tooltip (`sr_edit_or_manage_rates`, custom rows only) rather than adding a second
button, since T046 had deliberately merged Edit + Manage Rates into that one button already.

**Not investigated elsewhere yet**: the same kind of icon/tooltip ambiguity (an icon that reads as
one action but silently does more, or a tooltip that doesn't name what actually happens on click)
may exist on other tables' own row-action buttons across the app -- this was fixed as a targeted
bug report for ONE page, not a full audit. A phase design pass should review row-action
icons/tooltips system-wide for this same clarity gap, Tax & Statutory's Manage button included (to
confirm the fix holds up once real design/UX attention is applied, not just a functional patch).

**Source:** Batch 4 item 2b, explicit instruction (2026-09-11).

---

## Origami sync writes `employees.sso_*` via raw SQL, bypassing EmployeeModel::save()'s new SSO/PVD gate

Batch 4 item 3 added a 2-axis gate to `EmployeeModel::save()` (employee-level `sso_enrolled`/
`pvd_enrolled` AND company-level `company_statutory_settings` effective_status) that silently
discards SSO/PVD dependent field values when either axis fails, preserving existing data rather
than deleting it. `EmployeeSyncer::pull()` (Origami sync) writes `sso_enrolled`/`sso_no`/
`sso_start_date` directly via its own raw `UPDATE`/`INSERT` SQL (`app/services/sync/
EmployeeSyncer.php`, ~lines 1102-1366) -- a completely separate write path from
`EmployeeModel::save()` that this gate never touches. A sync pull can therefore still write
`sso_no`/`sso_enrolled` for an employee even when the company has TH_SSO switched off, or write a
value with no regard to whether it should be discarded -- the exact case the gate was built to
prevent everywhere else.

**Not touched this round** -- explicit instruction (Batch 4 item 3: "sync path...ไม่แตะรอบนี้").

**Fix, when picked up:** decide whether `EmployeeSyncer` should honor the SAME company-level gate
(likely yes -- a company that's turned SSO off presumably doesn't want it silently re-populated by
a sync pull either) before writing `sso_enrolled`/`sso_no`/`sso_start_date`, reusing
`CompanyStatutorySettingModel::effectiveStatusForItemCode()` (added this same batch) rather than a
new check. `EmployeeSyncer` only ever touches these 3 SSO columns currently -- it does not write
any PVD field, so PVD is unaffected either way.

**Source:** Batch 4 item 3, explicit instruction (2026-09-12).

---

## Employee Recheck tab has no filter for SSO status (`sso_status`)

Batch 4 item 4's column/filter audit of `/employees#employee-recheck-top-tab` identified
`sso_status` (the Social Security Fund column -- computed as `not_enrolled`/`enrolled_missing_no`/
`enrolled_complete` in `EmployeeModel::recheckList()`, not a raw column) as a real enum-shaped
value with no filter, same category as `is_ready` (also computed, not a raw column). Unlike
`is_ready`, which turned out to have a persisted, SQL-filterable column (`employees.
is_payroll_ready`) to reuse, `sso_status` has **no equivalent persisted column** -- it's derived
purely at read time from `sso_enrolled`/`sso_no` (ciphertext presence check), never written back
to the row. Explicitly skipped this round ("SSO status filter: ข้าม เข้า BACKLOG").

**Fix, when picked up:** filtering on this would need a real SQL WHERE expressing the same
3-way logic `recheckList()`'s own PHP branch uses (`sso_enrolled=0` -> not_enrolled;
`sso_enrolled=1 AND sso_no IS NULL` -> enrolled_missing_no; `sso_enrolled=1 AND sso_no IS NOT NULL`
-> enrolled_complete) added as its own block in `EmployeeModel::buildListWhere()` -- there is no
column to equality-match against directly, so this is a small CASE-shaped WHERE, not a 1-line
addition like the 6 filters this round added. Confirm the exact 3 values the UI dropdown should
offer before writing it (mirror the 3 badge states `recheckSsoStatusHtml()` in `list.js` already
renders, not new wording).

**Source:** Batch 4 item 4, explicit instruction (2026-09-12).

---

## `employees.is_payroll_ready` drifts from live readiness for employee-master writes that bypass EmployeeModel::save() (Origami sync)

Investigated as a direct follow-up to the "Ready" filter above (its own WHERE reuses this SAME
persisted column). Confirmed via full read-through of both write paths, not guessed:

**1. Which sync-written fields are actually inside `missingPayrollFields()`'s own definition, and
does either sync path recompute `is_payroll_ready`?**
- `EmployeeSyncer::upsertItem()` (Origami candidates.php / employee-master sync) writes
  `employee_type` and `branch_id` UNCONDITIONALLY on every UPDATE (`branch_id` specifically via
  `$links['branch_id'] ?? null` -- can genuinely null out an existing value). `title`/`nationality`
  are conditional-write (add-only, never null out an existing value -- safe direction). Per its own
  docblock, `payment_method_id`/`salary_type`/`base_salary_amount`/`salary_effective_date`/
  `tax_calculation_method`/bank fields are explicitly insert-only, never touched on UPDATE.
  `department_id`/`position_id`/`personal_email`/`mobile_no`/`name_th`/`name_en`/`date_of_birth`/
  `employment_date`/`employment_status` are also written every UPDATE but are validated/defaulted
  so they structurally can't become blank via sync (not a drift risk for the presence check
  specifically, though placeholder defaults like `sync-pending-...@placeholder.local`/
  `0000000000`/`1900-01-01` DO satisfy the presence check without being real data -- a separate,
  pre-existing characteristic of `missingPayrollFields()` itself, not something this investigation
  introduced).
  Note: the user's own example (`sso_*`) is actually **not** part of `missingPayrollFields()`/
  `is_payroll_ready` at all -- `sso_enrolled`/`sso_no` only feed the separate `completenessColumns()`
  / `calculateCompleteness()` percentage metric, unrelated to this Ready filter or to
  `PayrollRunModel::recalculate()`'s own readiness gate.
- `PayrollSyncModel::applyOneEmployeeMasterFields()` (the OTHER Origami integration,
  PAYROLL_SYNC_API) is the bigger risk: on every "mapped" row of every regular payroll sync pull
  (`applyEmployeeMasterFields()`, not a rare/admin-only path) it writes `payment_method_id`/
  `bank_id`/`bank_account_no` UNCONDITIONALLY whenever the payload carries a `pay_type` --
  including explicitly NULLing `bank_id`/`bank_account_no` when `pay_type='transfer'` but no
  `pay_bank_no` was sent. It also conditionally writes `department_id`/`position_id`.
  **Neither path calls `isPayrollReady()`/updates `is_payroll_ready` anywhere.**

**2. Attempted fix, wiring reverted -- method KEPT for Batch 5.** Added
`EmployeeModel::recomputeIsPayrollReady($employeeId, $compId)` (~20 lines: re-select the row,
decrypt only `base_salary_amount` -- every other encrypted column `missingPayrollFields()` touches
only needs a null/non-null presence check, and `EncryptionService::encrypt()` always stores NULL
ciphertext for null/empty plaintext, so raw ciphertext is a valid proxy without decrypting -- then
call the SAME private `isPayrollReady()` save() uses, then
`UPDATE employees SET is_payroll_ready = ...`), then wired it into 3 call sites (both sync UPDATE
branches + EmployeeSyncer's INSERT branch, 1 line each). Reused the existing definition exactly as
instructed, no second readiness rule. **The wiring (the 3 call sites) was reverted; the method
itself stays in `EmployeeModel.php`, unwired/uncalled, for Batch 5 to pick up** once the decision
below is made -- see its own docblock ("NOT CALLED ANYWHERE YET").

**Reverted after `tests/payroll_sync_attribution_test.php` broke (23 failures)**, root-caused (not
guessed) to: that test's own fixture employee is inserted via raw SQL with NO `department_id`/
`position_id`/`branch_id`/`payment_method_id` at all (all genuinely NULL, matching Origami payloads
that legitimately send `dept_id`/`posi_id`/`branch_id` as `null`) -- meaning this employee has
**never** actually satisfied `requiredColumns()`. It only ever appeared "ready" because
`employees.is_payroll_ready` schema-defaults to **1** (`tinyint(1) NOT NULL DEFAULT 1`, confirmed
in `database/migrations/2026-08-28_4_comprehensive_schema_catchup.sql`) and nothing had ever
recomputed it. The moment recompute ran, it correctly flipped to 0, which then made
`PayrollRunModel::submit()`/`markPaid()` start refusing with
`calc_errors='profile_incomplete'` -- breaking the merge/revert scheduling flow that test actually
exercises (unrelated to readiness).

**Why this is a BACKLOG item, not a quick retry:** the test fixture isn't uniquely wrong -- it
mirrors a real, plausible production shape (an Origami-synced employee whose department/position/
branch/payment method were never resolved, e.g. a genuinely `null` payload field). Turning on this
recompute for real would very likely flip a nontrivial number of REAL synced employees, company-
wide, from a false `is_payroll_ready=1` (never checked before) to the true 0 -- which would newly
start blocking their payroll run's submit/pay step in production. That is a business-behavior
change requiring a deliberate decision (and likely a company-wide audit/notification plan for
newly-surfaced incomplete profiles), not a safe drop-in bugfix alongside a filter-UI feature.

**3. Does the readiness definition itself depend on anything outside the employee row (e.g.
company settings)?** No -- `missingPayrollFields()`/`isPayrollReady()` read only `requiredColumns()`
values off the employee row itself, plus `$isThCompany` (`companies.registered_country === 'TH'`,
looked up once per call) purely to relax 2 TH-only required fields for non-TH companies. A company
changing its `registered_country` is not a realistic/supported scenario elsewhere in this codebase
(no UI to edit it after creation was found), so this is a theoretical, not practical, staleness
vector -- noted per the question asked, not treated as a real risk to chase further.

**Also pulled back before commit:** the Recheck tab's "Ready/Not Ready" filter UI itself
(`#employee_recheck_filter_ready` in `list.php` + its wiring in `list.js`) was removed for the same
reason -- exposing a filter on a column known to be unreliable for sync-written employees would let
an admin "narrow to Ready" and silently miss/misjudge employees that are actually incomplete (or
the reverse). `EmployeeModel::buildListWhere()`'s own `is_ready` WHERE clause and
`tests/employee_recheck_filters_test.php`'s coverage of it stay in place -- backend-only, unused by
any UI, ready to reconnect once the column itself is trustworthy.

**Fix, when picked up:** needs a product decision first (should sync-written employees missing
department/position/branch/payment method actually block payroll the way a manually-created
incomplete profile does, or should the Recheck tab / PayrollRunModel treat "still being resolved by
sync" as its own state?) before re-attempting `recomputeIsPayrollReady()`'s wiring. If proceeding:
(a) fix `tests/payroll_sync_attribution_test.php`'s fixture to be genuinely complete (or accept its
readiness flipping and adjust its own assertions), (b) audit every OTHER sync-adjacent test fixture
across the suite for the same "relies on the untouched schema default" shortcut before trusting a
clean test run, (c) consider running the recompute once, offline, as a one-time backfill script
first to see the real-world scale of the flip on the actual dev DB before wiring it into the live
sync path, (d) **reopen the UI "Ready" filter in `list.php`/`list.js`** (the 2 blocks pulled out
this round, `is_ready`-related code in `currentEmployeeRecheckFilters()`/
`updateClearEmployeeRecheckFilterVisibility()`/the change+Clear Filter handlers) once
`is_payroll_ready` is trustworthy for sync-written employees.

**Source:** Batch 4 item 4, explicit instruction (2026-09-12).

---

## Dead route: `setup/notification` → `NotificationController@index` (method doesn't exist)

Found incidentally during Phase Design Round 1's route-mapping (auditing every page/route against
`docs/design/rules.md` §2–§10 — not a design issue, logged here per rules.md §0.7: "phase design
ห้ามแก้ logic...ให้จดลง BACKLOG.md แล้วทำต่อ"). Confirmed by listing every `public function` on
`NotificationController` — `index()` does not exist on that class, so the route `setup/notification`
would 500 if anyone actually hit it. The real, working Notifications page is `/notifications` →
`NotificationController@page()` → `notification/index.php`, unaffected.

**Fix, when picked up:** either remove the dead route mapping (if `setup/notification` was a typo/
leftover and nothing links to it), or point it at `page()` like the real route does (if something
still links to `setup/notification` specifically and that link should keep working) — check
`app/views/**` and `public/js/**` for any remaining reference to `setup/notification` before
choosing which.

**Source:** Phase Design Round 1 audit, incidental finding (2026-09-12).

---

## Quill announcement editor's dark-mode CSS uses the wrong attribute name (`data-theme`, not `data-bs-theme`) — never actually applies

Found while implementing Phase Design Round 2 item 1b (dark-mode tokens, checking every existing
`[data-bs-theme="dark"]`-style block in `style.css` before adding `tokens.css`'s own). 4 rules at
`style.css` lines ~8108–8114 (the `.ann-rich-content`/`.ann-quill-wrap` announcement rich-text
editor's icon/picker theming) are scoped to `:root:not([data-theme="light"])` — **`data-theme`, not
`data-bs-theme`**. `public/js/app.js` (`applyTheme()`) only ever sets/removes the `data-bs-theme`
attribute on `<html>` — it never sets a plain `data-theme` attribute at all, on this or any other
element. These 4 rules can therefore never match anything in this app, on any theme, ever — the
Quill editor's stroke/fill/picker colors have been silently stuck at their light-mode values in dark
mode since T069 shipped. Every OTHER dark-mode selector in the file correctly uses `data-bs-theme`
(confirmed by listing all 16 occurrences of the pattern in the file) — this is the one-off exception,
not a wider naming split.

**Not fixed this round** — Phase Design rounds don't fix logic/CSS-selector bugs per rules.md §0.7
("phase design ห้ามแก้ logic...ให้จดลง BACKLOG.md แล้วทำต่อ"), even though the fix itself is trivial
(rename `data-theme` → `data-bs-theme` in those 4 selectors).

**Fix, when picked up:** `sed -i 's/data-theme="light"/data-bs-theme="light"/' public/css/style.css`
scoped to just those 4 lines (or open them individually) — one-line-per-rule fix, no JS/schema change
needed at all.

**Source:** Phase Design Round 2 item 1b, incidental finding (2026-09-12).

---

## `.text-primary-emphasis`/`.bg-primary-border-subtle` (Bootstrap utility classes) have no dark-mode variant

Found during Phase Design Round 2 item 1b's own check ("ตรวจว่า override ราย-component จากข้อ 1
อ้าง var ทั้งหมด ไม่มี hex ค้างที่ทำให้ dark เพี้ยน"). `style.css`'s Bootstrap-override `:root` block
(Round 2 item 1) sets `--bs-primary-text-emphasis: #b45f00` and `--bs-primary-border-subtle: #ffd699`
as static literals — Bootstrap-derived tint/shade variants with no equivalent token in
`docs/design/rules.md` §1's own token list, so they were deliberately left un-tokenized rather than
inventing a new token unasked. Confirmed 2 real consumers exist: `public/js/payroll/detail.js` and
`public/js/setup/changelog.js` both use `.text-primary-emphasis`/`.bg-primary-border-subtle` — these
2 static, light-mode-tuned colors will render exactly as-is in dark mode too (no adaptation), likely
reading as a washed-out/wrong-contrast amber against a dark surface at both call sites.

**Not fixed this round** — per the same "report count, don't fix, round 4" instruction Round 2 item
1b gave for any other T069/dark-mode overlap found during this check.

**Fix, when picked up:** either (a) add a genuine `--c-primary-text-emphasis`/`--c-primary-border-
subtle` pair to `tokens.css` (both light AND dark values) if this tint/shade pairing is worth
promoting to a real rules.md §1 token, or (b) give `--bs-primary-text-emphasis`/
`--bs-primary-border-subtle` their own dark-mode values directly in `style.css`'s existing
`[data-bs-theme="dark"]`/`@media` blocks (next to `--app-*`'s own dark overrides) if it's not worth a
new token — a design call, not decided here. Check both real call sites render correctly either way.

**Source:** Phase Design Round 2 item 1b, incidental finding (2026-09-12).
