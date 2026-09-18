# Backlog

Deferred items noted during work sessions. Not scheduled — pick up when asked.
Format: Title / 1-2 line detail / Source (which batch).

---

## 4a-2b: tab filter "รายละเอียด / รายการที่แก้ไข (n)" ในสลิปหน้าดู

4a-2a เสร็จแล้ว (manual line เป็นแถวในตาราง, ยอดรวมกลับเข้าท้ายตาราง, ลบ `payslipViewHtml()` + การ์ด `.ml-mount`,
แถว skipped ไม่ render)
เหลือ: tab 2 ตัวเหนือตารางในโหมดดู, n = แถวที่ `override_action` ไม่ว่าง + แถว manual, filter = re-render โดยไม่ render
แถวที่ไม่เข้าเงื่อนไข (กลุ่มว่างไม่ render, ยอดรวมยังแสดง), n=0 → ไม่มี tab, โหมดแก้ไม่มี tab
Source: ก้อน 4a-2a (2026-09-18)

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

---

## `showSuccess()`/`showError()` render HTML content as literal, visible source text

Found while auditing every real `showSuccess()`/`showError()` call site's message content for
Round 2 item 7b's toast conversion (see `docs/design/audit.md`'s own [SC13]). Both helpers
(`public/js/alert.js`) build their Swal2 config with `text: msg` (and, for the new toast form,
`title: msg`) — Swal2's `text`/`title` options render plain text, never HTML.

5 real call sites pass genuine multi-line HTML into `msg` anyway: `setup/data-sync.js`'s
`showSuccess()` (lines 221, 268) and `showError()` (line 223), and
`setup/origami-sync-widget.js`'s `showSuccess()`/`showError()` (lines 95, 98) — all 5 via
`dsResultSummaryHtml()`/`origamiSyncResultSummaryHtml()`, which build a `<div>` plus a `<ul>` of up
to 5+ sync-error `<li>` lines (each individually `escapeHtml()`-ed, confirming the ORIGINAL author's
intent was for this to render as HTML — the escaping would be pointless otherwise). Because of the
`text:`/`title:` mismatch, users have always seen the raw markup as visible text (literal `<div>`,
`<ul>`, `<li>` tags and all) on these 2 Data Sync-adjacent pages, not a formatted error list.

**Not fixed this round** — a rendering/logic bug found during a design pass gets logged, not fixed
alongside the design work (rules.md §0.7).

**Fix, when picked up:** switch these 2 helpers (or add an explicit opt-in, e.g. a 4th
`{isHtml: true}` option) to pass `html: msg` instead of `text`/`title: msg` for these 2 specific
call-site families ONLY — do not flip it for every caller unconditionally: the other 138 call sites
pass a `langData[...]`-sourced or plain-text `msg`, and switching the DEFAULT to `html:` for all of
them would be a real (if unlikely in practice) stored-XSS risk if any of those ever ends up carrying
unescaped user-controlled text expecting Swal2's own plain-text escaping as a safety net. Verify
both pages render a real bulleted list afterward, not just that the tags disappear.

**Source:** Phase Design Round 2 item 7b, incidental finding (2026-09-13).

---

## Comment count badge has no unread/new tracking (Employee Breakdown row action)

Phase Design Round 3 item 3b's own row-action redesign put the per-employee comment count on
Payroll Detail's Employee Breakdown table onto a real `.btn-circle-action` circle (View Breakdown /
Comments / Manage Items) with the count rendered via `countBadgeHtml()` (app.js, §5) overlaid on
the circle's own corner. The explicit design decision for this badge (confirmed same round): stays
`tone: 'neutral'` (gray) always **unless** the count genuinely represents something "new/unread"
the current viewer hasn't seen yet — that gets `tone: 'primary'` instead (a new `.badge.badge-primary`
CSS rule was added this round specifically for this future case, `--c-primary-soft` background +
`--bs-primary-text-emphasis` text, verified ~4.24:1 contrast — see style.css's own comment on that
rule).

**Why it stays neutral today**: `row.comment_count` (`PayrollRunModel::getDetails()`) is a flat
total — there is no read/unread distinction anywhere in the comment data model at all (no
`read_at`/`read_by`/per-viewer read-state column, no notification-style unread tracking the way
`notifications`/`nav-notif-item-unread` already has for the header bell). Passing `{tone:'primary'}`
today would only ever mean "count > 0," which is explicitly NOT what was asked for (a still count
that's just nonzero is not the same thing as "something new since I last looked").

**Fix, when picked up**: needs a real per-viewer read-state concept for run-employee comments first
(new column(s)/table, needs a migration + a decision on what "read" even means here — per-viewer? per-
session? does opening the Comments modal mark everything read, or only what was visible at the time?)
before `commentButtonRd()` (public/js/payroll/detail.js) can compute a genuine unread sub-count and
pass `{tone:'primary'}` only when it's > 0. Likely belongs alongside a broader "notification"-style
batch given the closest existing precedent in this app is the header bell's own unread mechanism
(`notifications.js`/`nav-notif-item-unread`) — worth checking whether that same read-state pattern
can be reused rather than inventing a second one specific to run comments.

**Source:** Phase Design Round 3 item 3b follow-up, explicit instruction (2026-09-13).

---

## Other select2-remote fields may have the same stale-appended-option pattern `initFilterBar()`'s reset bug had

While fixing `initFilterBar()`'s `resetSelect()` (a select2-remote field's underlying `<select>`
never carries a baked-in placeholder option, so clearing it needs to actually remove the option(s)
select2 itself appended, not just set the value to something else — see rules.md §6's own 2026-09-13
entry for the full root cause), noticed several OTHER select2-remote clear sites in
`public/js/payroll/detail.js` use the plain `.val(null).trigger('change')` pattern without removing
the appended option first (e.g. `#joinFilterDepartment`/`#joinFilterTeam`/`#joinFilterPosition`/
`#joinFilterCycle` around line 4739/4754, `#recurringDestPayeeEmployeeSelect`/
`#recurringDestBankAccountSelect` etc. around line 4222-4234, `#manualLinePayeeEmployee`/
`#manualLineBankAccount` etc. around line 4367-4455).

**Not necessarily the same bug** — those fields all still WORK for their own actual "clear it and
let the user pick a different one" purpose (`.val(null)` does deselect visually; the concern is only
a stray leftover `<option>` element lingering in the DOM after a value the user is no longer using,
not a functional no-op the way `initFilterBar()`'s `.find('option').first()` bug was) — this is a
possible latent DOM-hygiene issue (accumulating stray options over many selections), not a confirmed
reproducible bug like the filter-bar one, so **not fixed proactively this round** (scope was
specifically `initFilterBar()`, not a general select2-remote-clear audit).

**Source:** Found incidentally while fixing the filter-bar select2-remote clear bug (2026-09-13),
not itself reported or investigated further.

---

## `migSplitUpDown()` regex `--\s*UP` จับคอมเมนต์ที่ขึ้นต้นด้วย "-- up" ได้

`scripts/migrate.php` แยก UP/DOWN ด้วย `/--\s*UP\s*(.*?)\s*--\s*DOWN\s*(.*)$/is` ซึ่ง case-insensitive และไม่ anchor ต้นบรรทัด
→ คอมเมนต์อธิบายที่บังเอิญขึ้นบรรทัดใหม่ด้วยคำว่า `-- up (...)` ถูกนับเป็น marker ตัด UP ผิดจุดและ migration ล้ม (เจอจริงตอน tiny-C)
ควร anchor เป็นบรรทัด marker ตายตัว (`^--\s*UP\s*$` แบบ multiline) ไม่ใช่จับที่ไหนก็ได้ในไฟล์

---

## `tests/import_test.php` / `tests/transaction_data_sync_test.php` fail when run against a dev DB that already has other sync data in it

Found running the full test suite after Phase Design Round 3 item 3c-1's page-loader work — unrelated
to that work (neither file references anything this round touched — tokens.css/style.css/app.js's
page-loader functions/modals.php/page-loader.php — confirmed via grep, and both failures are pure
dev-DB-state assertions, nothing about a CSS/JS/PHP-view code path). Both tests assert on **global**
sync-status state (`SyncBatchModel::
lastSyncTimes()`, `hasCompletedMasterDataSync()`) that reflects **every** sync batch/import ever run
against the dev DB, company-wide — not scoped to rows either test's own fixture created. A dev DB
that has real department/position/holiday/attendance sync history sitting in it from other sessions'
own testing (this DB does — see the earlier "Dev DB has real user data" feedback memory) makes these
assertions fail even though nothing about the code under test is actually broken:

- `tests/import_test.php`: `FAIL  lastSyncTimes() default (sync) has NO department entry (only
  import ran) => got false, expected true` — asserts the *default* (non-import) sync source has
  no department entry, which only holds if no OTHER session's `sync`-source department batch has
  ever run on this DB.
- `tests/transaction_data_sync_test.php`: `FAIL  hasCompletedMasterDataSync() still false
  (department/position/holiday never run) => got false, expected true` — same shape: asserts a
  company-wide flag is still false, which only holds if department/position/holiday sync has
  genuinely never run for that company anywhere, ever.

**Fix, when picked up (Batch 5):** give both tests a self-contained fixture instead of relying on
"nothing else has touched sync tables yet" — either (a) run each assertion inside a fresh company id
created just for the test (so no other session's rows can be in scope), or (b) snapshot the relevant
`sync_batches`/related rows before the test's own actions and assert on the DELTA (what THIS test
run added) rather than absolute presence/absence. Both tests already wrap in a transaction +
rollback (per this project's own `tests/*.php` convention), so the isolation gap is specifically
"another already-committed session's data, not this test's own" — a rollback at the end doesn't
undo what existed before the test started.

**ตัวอย่างที่ 3 ของอาการเดียวกัน (2026-09-18, tiny-C)**: `tests/payroll_calc_warnings_test.php` §5 เลือกเป้าหมายด้วย
`ORDER BY d.run_id DESC LIMIT 1` = run ที่ใหม่ที่สุดใน DB เสมอ ระหว่างรอบนี้จึงไปเจอ fixture ของ `tests/ui/mksession.php`
ที่ยังไม่ cleanup แล้ว assertion "another employee on the same run is unaffected" ได้ adjustment_count=2 แทน 0
(fixture ใส่ line override + manual line ให้ emp 28) หลัง `--cleanup` ผ่านทันที แก้แบบเดียวกัน: สร้าง run ของตัวเอง หรือ assert เป็น delta

ซ้ำอีกรอบ 2026-09-18 (4a-2a): `tests/payroll_calc_warnings_test.php` fail ใน `run_all --compare` — ยืนยันแล้วว่า
fail เหมือนกันบน HEAD สะอาด = dev-DB drift ไม่ใช่ regression — **ตัวที่ 3 ของ baseline คือไฟล์นี้เสมอ**
(คู่กับ `import_test` / `transaction_data_sync_test`) ไม่ต้องสอบซ้ำทุกรอบ

**Source:** Phase Design Round 3 item 3c-1, page-loader work — full test-suite run turned these up,
explicit instruction to log rather than fix now (2026-09-14).

**RE-CONFIRMED 2026-09-15** (comment-modal restyle round, explicit instruction to verify with
`git stash`): both still fail on a CLEAN HEAD with this round's work stashed away, so neither is a
regression from it. Root cause verified directly against the dev DB this time rather than inferred:
`sync_batches` holds 5 rows each of `source='sync'` department / position / holiday batches for
`comp_id = 1` -- the very company id both tests hardcode (`$compId = 1` in each) -- which is exactly
what makes "no department sync has ever run" and "hasCompletedMasterDataSync() is still false"
untrue for that company. Fix shape (fresh company id per test, or delta assertions) unchanged.

## Statutory line `note`: raw internal code fixed for the "not entitled" + known cases; a genuine misconfiguration (no_brackets_configured/unknown_calc_method/unrecognized_calc_base) now shows NO signal at all

**UPDATE 2026-09-14 (same day, follow-up round):** the original bug reported just below (raw
`(employee_not_enrolled)`/`(th_pit_average_annual_tax_2050)` text shown verbatim next to a
statutory line) is fixed at the view layer: `statutoryRowsRd()` (`public/js/payroll/detail.js`) no
longer renders `item.note` as raw parenthetical text at all — dropped entirely, relying solely on
the existing `formulaButtonRd()`/`explainLineNoteRd()` "?" popover, which already translates the
codes it knows about (`employee_not_enrolled`, `no_rate_configured`, `no_rate_ever_configured`, the
`th_pit_(average|cumulative)_annual_tax_*` pattern, `sync_*` patterns) and shows no button at all
(silent, never raw text) for one it doesn't. Separately, lines whose note is exactly `'disabled'`,
`'employee_not_enrolled'`, or `'employee_tax_exempt'` (StatutoryCalculationEngine's own "this item
does not apply to this employee at all" codes) are now filtered out of the section entirely, per
explicit instruction — an item the employee IS entitled to that simply computed to ฿0 still shows
normally.

**Residual gap, not addressed (view-layer fix can't reach this without guessing at new logic):**
3 of the engine's note codes mean a genuine MISCONFIGURATION, not "not entitled" —
`no_brackets_configured`, `unknown_calc_method`, `unrecognized_calc_base` (plus `no_rate_configured`/
`no_rate_ever_configured` are already handled via `explainLineNoteRd()`, no gap there). Those 3 are NOT in
`explainLineNoteRd()`'s `knownNotes` map, so a line hitting one of them now shows the row (correct,
not filtered — it's a real config problem, not a not-entitled decision) but with **zero visible
signal** that anything is wrong: no "?" button (returns `null` for an unrecognized code), no text
either (removed this round). Before this round's fix, at least the raw code string was visible as a
hint something was off; now it's silently indistinguishable from a legitimately-zero line. **Fix,
when picked up:** add these 3 codes to `explainLineNoteRd()`'s `knownNotes` map with real
translated copy (e.g. "This item's rate table isn't configured — contact your administrator"), so
the "?" button reappears for them. Small, contained addition — but it's still a business-logic
decision (what should the *fallback* signal be for an unmapped code the map might STILL miss later)
that shouldn't be made silently inside a design pass.

**Original report (2026-09-14, earlier same session):**
`StatutoryCalculationEngine::calculateLine()` (`app/services/StatutoryCalculationEngine.php`)
writes machine-readable strings straight into `$line['note']` when a statutory item computes to
zero/skipped — confirmed by reading the source directly: `'employee_not_enrolled'`,
`'employee_tax_exempt'`, `'disabled'`, `'no_brackets_configured'`, `'unknown_calc_method'`,
`'unrecognized_calc_base'`, plus whatever `computeFormula()`'s own `$noRateNote`/`$note` produce
(seen live: `th_pit_average_annual_tax_2050`, clearly a rate-row identifier, not prose). Reproduced
live via Playwright screenshot, Payroll Run 752 / employee 159 (TH_PVD showed
`(employee_not_enrolled)`, TH_PIT showed `(th_pit_average_annual_tax_2050)`).

**Source:** Phase Design Round 3 item 3c-2 (payslip-view.php / Calculation Breakdown modal),
found while screenshotting the new layout for verification, follow-up fix applied same day
(2026-09-14).

---

## `#manageLinesModal`'s 4 hidden in-tab Save buttons should become direct function calls, not `.trigger('click')` on a hidden element

Batch 1/4 of the Adjustments modal shell (§9/§6/§4) built `saveActiveAdjustmentTab()`
(`public/js/payroll/detail.js`) as a dispatcher that maps the active tab to that tab's own EXISTING
save button and fires `.trigger('click')` on it — per explicit instruction ("ไม่เขียน logic บันทึกใหม่
แค่ย้ายจุดเรียก"), each of `#btnSaveAttendanceData`/`#btnSaveEmpItemExclusion`/
`#btnSaveRecurringDestOverride`/`#btnSaveEmpCalcOverride` was left in the DOM, hidden via `d-none`
in its own tab-pane, rather than deleted — a workaround that reuses the click handler's own body
completely untouched, but a hidden button that still exists purely to be `.trigger()`-ed is not the
real end state.

**Fix, in batches 2–4** (each of which touches one or more tabs' own content, unlike batch 1's shell-
only scope): extract each of the 4 handlers' own bodies into a plain named function (e.g.
`saveAttendanceDataRd()`), have the existing `$(document).on('click', '#btnSaveXxx', ...)` binding
call that same function (if the button itself is kept for any other reason) or be removed entirely,
and have `saveActiveAdjustmentTab()`'s dispatch table call the function directly instead of
`.trigger('click')` on a hidden element. Mirrors this project's own "generalize instead of mirror-copy"
convention (CLAUDE.md) — the hidden-button indirection was accepted for batch 1 only because batch 1
was explicitly forbidden from touching tab content/logic.

**Source:** Phase Design Round 3 item 4 batch 1/4, explicit instruction (2026-09-14).

**UPDATE 2026-09-15 (batch 2/4, Payment Items tab)**: this tab turned out to have **no hidden save
button at all** -- its `ADJUSTMENT_TAB_CONFIG_RD` entry is `saveSelector: null`, because every action
on it (add line / remove line) writes to the server the moment it is taken. So there was nothing to
extract here; instead the footer's own Save button is now HIDDEN while this tab is active (it used to
render permanently disabled, which implied a save step that does not exist). The hidden-button
indirection this entry is about therefore applies to **4 tabs, not 5**: Attendance Data, Adjust
Amounts, Recurring Deduction Destination, and Tax & SSO -- still to be done in batches 3/4.

---

## Generic `.modal[data-dirty-guard]` mechanism (app.js) has no built-in support for a modal whose own data loads asynchronously after `shown.bs.modal`

`#manageLinesModal` (Batch 1/4 of the Adjustments modal shell) could not use the existing generic
delegated `.modal[data-dirty-guard]` handler (`app.js`, §9) as-is, for two reasons specific to this
modal: (1) that handler snapshots the WHOLE `.modal` once at `shown.bs.modal`, which fires before this
modal's 4 parallel async tab-data loads land — a whole-modal baseline taken that early would make
freshly-arrived server data look "dirty" the instant it renders; (2) each of the 5 tabs has its own
distinct save target (or none, for Tab 1) — a single whole-modal dirty flag can't express "only tab X
has unsaved input." Worked around by building a bespoke per-tab mechanism in `detail.js` that reuses
the SAME underlying primitives (`snapshotFormState()`/`isFormDirty()`/`showConfirm()`/
`refreshDirtyGuard()`) instead of the generic delegated handler itself — see that modal's own
`ADJUSTMENT_TAB_CONFIG_RD` block for the full shape. rules.md §9 dirty-guard now has a 1-line rule
(item 4 under "ยืนยันแล้วให้รื้อกลับ") documenting that any modal loading data async must scope and
refresh its own baseline, but the GENERIC mechanism itself still has no opt-in support for this —
every future async-loading modal would need to re-derive the same bespoke pattern from scratch.

**Fix, when picked up:** generalize the generic mechanism to accept either (a) an opt-in "defer the
baseline" mode — e.g. `data-dirty-guard-defer` — where `shown.bs.modal` does NOT auto-snapshot, and the
page's own code becomes responsible for calling `refreshDirtyGuard()` once its data lands (this modal's
own pattern, promoted into the shared helper), or (b) a scope-aware variant that accepts a
tab-id → container-selector map directly (closer to what `#manageLinesModal` actually needed) so a
future multi-tab async modal doesn't have to hand-roll its own `show.bs.tab`/`hide.bs.modal` listeners
the way this one did. Decide which shape generalizes better once a 2nd real multi-tab or async-loading
modal shows up — this backlog item and #manageLinesModal's own implementation are the only data point
so far.

**Source:** Phase Design Round 3 item 4 batch 1/4, explicit instruction (2026-09-14).

---

## Employee comment list has no pagination -- "โหลดเพิ่ม" button deferred until the endpoint supports it

The comment-list restyle (rules.md §6 "Comment list + Composer") asked for a full-width outline
"โหลดเพิ่ม" button at the end of the list once a comment thread passes 20 items, with the explicit
escape hatch "ถ้า endpoint ยังไม่รองรับ pagination ให้รายงานแล้วข้าม". It doesn't:
`PayrollRunModel::employeeComments()` (behind `api/payroll-run.employee-comment.list`) runs a single
`SELECT ... ORDER BY c.id DESC` with no LIMIT/OFFSET and no total count, and `loadEmployeeComments()`
(payroll/detail.js) drops the whole result into `employeeCommentsCache` in one shot. A "โหลดเพิ่ม"
button on top of that would only re-reveal rows the browser already downloaded -- a cosmetic truncation,
not pagination -- so it was skipped this round rather than faked.

**Fix, when picked up:** add `limit`/`offset` (or a cursor on `c.id`) + a total count to
`employeeComments()` and its controller, have `loadEmployeeComments()` request the first page only,
then render the button through `renderCommentList()`'s caller (the component itself stays
pagination-agnostic -- it renders whatever array it is handed). Worth doing only once a real thread
gets long enough to matter; the longest one in the dev DB today is 2 comments.

**Source:** Phase Design Round 3, comment-list restyle, item 7 (2026-09-15).

---

## `include_in_cash_summary` is stored but no report reads it -- checkbox removed from the UI until one does

Both `payroll_run_manual_lines` and `employee_earning_deductions` carry an `include_in_cash_summary`
column, written from a "Include in Cash Payment Summary Report" checkbox and echoed back by the list
APIs. Nothing else touches it: there is no query in `app/services/reports/`, `app/services/export/`,
`PayrollRemittanceModel` or `PayrollReportDataModel` that reads the column, and no report named
"Cash Payment Summary" exists (payment reports are `PAY_SLIP`, `BANK_TRANSFER_FILE`,
`PAYMENT_VOUCHER`). It came from a 2026-08-31 request ("ให้ติ๊กเพิ่มได้ว่า รวมไปใน cashlink หรือแยก cash
link") where the column was prepared ahead of the report.

Asking someone to make a choice that changes nothing is worse than not asking, so the checkbox was
removed from the Adjustments modal's Payment Items tab (2026-09-15). **Nothing about the backend
changed**: the column, its default, and the model rules around it (forced 0 for
`payee_type='not_disbursed'`, forced 1 when there is no payee) are untouched, and the form simply
stops sending the key -- which is exactly what makes the model keep the default.

**Fix, when picked up:** build the cash-summary report (or fold the flag into an existing payment
report), then put the checkbox back on the 3 payee types where it is a genuine choice
(`employee`/`company`/`other_person`) -- markup to restore is in this commit's own diff. Employee
Detail's `#eedModal` still shows its own copy of the checkbox and was deliberately left alone.

**Source:** Phase Design Round 3 item 4 batch 2/4 follow-up, explicit instruction (2026-09-15).

---

## Saved payment destinations: no de-duplication, and no screen to manage them

`payment_destinations` rows are created implicitly -- ticking "บันทึกปลายทางนี้ไว้ใช้ครั้งถัดไป" while
adding a deduction routed to `payee_type='other_person'` sets `is_saved = 1`, which is the only thing
that makes a row come back in the picker (`PaymentDestinationModel::listSaved()`). Two gaps found
while redesigning that form (2026-09-15):

1. **No de-duplication.** `PaymentDestinationModel::create()` inserts unconditionally. Saving the
   same third-party account twice produces two rows with the same name, and the picker shows both
   with nothing to tell them apart. The table already stores `account_no_hash` (an exact-match
   companion to the encrypted `account_no`) -- the natural fix is to look up
   `comp_id + account_no_hash + bank_id` first and reuse/flip `is_saved` on a hit instead of
   inserting. Must stay scoped to `is_saved` saves; a genuine one-off row (`is_saved = 0`) should
   still be free to repeat.
2. **No management screen.** The only route is `api/payment-destination.options` (read). There is no
   list, no edit, no delete, and no `deleted_at` ever set from the UI, so a destination saved by
   mistake -- or one belonging to a payee the company no longer uses -- stays in every employee's
   picker forever. Destinations are company-scoped by design (any employee's deduction can route to
   the same third party), which makes the lack of a caretaker screen more visible, not less.

**Fix, when picked up:** 1 is a small model change plus a test; 2 is a real screen (most natural home
is a tab under Payroll Configuration, next to the other company-scoped catalogs) with soft delete and
a guard against deleting a destination still referenced by an unpaid run.

**Source:** Phase Design Round 3 item 4 batch 2/4 follow-up, found while answering the pre-work
questions about the destination picker (2026-09-15).

---

## A deduction can be routed to an employee who has no bank account, and nothing rejects it

`payee_type='employee'` means the deducted money is paid into THAT employee's own bank account --
either as a `TRANSFER_IN` earning line if they are in the same run, or, if they are not, as a real
external transfer (`destination_type='employee_fallback'`, `PayrollRemittanceModel::generateForRun()`)
paid to their bank details. Nothing on the way in checks that those details exist:
`PayrollRunModel::addManualLine()` (and `EmployeeEarningDeductionModel::save()`, same shape) validates
only that the payee employee exists in this company, and the remittance row is created with just
`fallback_employee_id` -- the missing account surfaces at the approval confirmation step at the
earliest, and in practice when someone tries to pay it.

The Payment Items tab now stops this in the UI (2026-09-15): picking such an employee shows a gray
"no bank account on file yet" line under the picker and keeps Add disabled with that as its tooltip.
That is a client-side guard only, and it depends on `api/employee.report_to.get` reporting a
`has_bank_account` flag -- until it does, the UI stays silent rather than accusing every employee.

**Fix, when picked up:** (1) add the flag to that endpoint so the guard actually engages, and (2)
decide whether the MODEL should reject it too -- a design pass must not change validation rules, so
that half was deliberately left alone. Worth pairing with the same check in Employee Detail's own
`#eedModal`, which has the identical routing control and the identical gap.

**Source:** Phase Design Round 3 item 4 batch 2/4 follow-up, found while answering the pre-work
questions about the payee sub-form (2026-09-15).

---

## รอบ 4: ย้ายทุกหน้าที่ยังใช้ `$().DataTable()` ตรงๆ → `initSharedDataTable` + ส่งปุ่มผ่าน `options.toolbar`

`initSharedDataTable()` มี toolbar slot แล้ว (2026-09-15, rules.md §7 "DataTable toolbar") — ปุ่ม toolbar
ส่งผ่าน `{ create, actions: [], export }` และ component จัดตำแหน่ง/การขึ้นแถวใหม่บนจอแคบให้เอง
— แต่**ใช้ได้เฉพาะตารางที่สร้างผ่าน `initSharedDataTable`** ซึ่งตอนนี้มีหน้าเดียว (Payroll Detail)
อีก 14 ไฟล์ยังสร้าง DataTable เองด้วย `$('#x').DataTable({...})` และ append ปุ่มเข้า `.dt-search`/`.dt-length` ตรงๆ
(25 ปุ่ม) จึงยังรับ slot ไม่ได้:

| ไฟล์ | ปุ่มที่ inject อยู่ |
|---|---|
| `employee/list.js` | `#btnBulkSyncSelected` (เข้า `.dt-length`) · `.manage-employee` (create) · `#btnOpenEmployeeSync` · `#btnOpenEmployeeSyncLog` |
| `employee/detail.js` | ปุ่มเพิ่มรายการ (create) · `add_recurring_earning` · `add_recurring_deduction` |
| `manual-entry/index.js` | ปุ่มเพิ่ม (create) · Add Multiple · Import File |
| `setup/setup-rules.js` | Add (create) · `#btnOpenHolidaySync` · `#btnOpenHolidaySyncLog` · `#btnApplyLeaveTypeDefaults` |
| `setup/company-profile.js` | bank_account (create) · org-sync · org-sync-log |
| `setup/payroll-configuration.js` | Add ×2 · cycle (create) |
| `payroll/index.js` | payroll_run (create) · `#bulkPullBar` (ย้าย DOM node เดิมเข้า `.dt-length`) |
| `payroll/approval.js` | `#approvalBulkBar` (ย้าย DOM node เดิมเข้า `.dt-length`) |
| `setup/announcements.js` | ปุ่มประกาศใหม่ (create) |
| `setup/employment-certificate-request.js` | ขอหนังสือรับรอง (create) |
| `setup/employment-certificate-template.js` | add_template (create) |
| `setup/payslip-request.js` | ขอสลิป (create) |
| `setup/payslip-template.js` | add_template (create) |
| `setup/tax-statutory.js` | sr_add_custom_item (create) |

**ทำเมื่อไหร่**: ทำพร้อมตอนไล่หน้านั้นตาม `audit.md` รอบ 4 — **ไม่ทำแยกเป็นงานของตัวเอง** เพราะการย้าย
ตารางมา `initSharedDataTable` แตะ layout/language/columnDefs ของตารางนั้นด้วย ต้องตรวจหน้านั้นทั้งหน้าอยู่ดี
— การไล่แก้ 14 ไฟล์รวดเดียวคือการเสี่ยง regression 14 หน้าพร้อมกันโดยไม่มีใครดูหน้าจริง

**Source:** Phase Design Round 3, DataTable toolbar slot (2026-09-15).

- **`.nav-link:focus-visible` ring โดน clip บนแถว tab** — ring เป็น `box-shadow` 4px รอบปุ่ม
  แต่ `.nav-tabs` เป็น `overflow-x: auto` จึง clip ring ด้านบน/ล่างทิ้ง (การบังคับของ CSS: แกนหนึ่ง
  ไม่ใช่ `visible` อีกแกนก็ไม่ใช่) — ทางแก้คือให้ ring เป็น inset หรือเผื่อ padding ให้แถว — ทำตอนไล่ accessibility
  pass ไม่ใช่รอบนี้ (พบระหว่าง nav-tabs underline clipping, 2026-09-15)

- **`#runDetailTabs.nav-tabs` hardcode สีเส้น `#dee2e6` (dark mode ไม่ตาม)** — override เฉพาะหน้า
  Payroll Detail ที่ตั้งสีเส้นล่างของแถว tab เป็นค่า hex ตรงๆ มาตั้งแต่ก่อนมี token จึงวาดเส้นสีอ่อน
  ทับใน dark theme ด้วย (2026-09-15 ย้ายมาเป็น `box-shadow: inset 0 -1px 0 #dee2e6` กลไกเดียวกับ
  shared rule แต่คงสีเดิมไว้ เพื่อไม่ให้หน้านั้นเปลี่ยนหน้าตาพร้อมกับการแก้บั๊ก clip)
  — **ทางแก้: ลบ rule นี้ทิ้งทั้งก้อน ปล่อยให้ใช้ `var(--c-border)` ของ shared `.nav-tabs`**
  — ทำตอนรอบ 3d ที่ไล่ `payroll/detail.php` ให้เป็น `design:clean` ไม่ทำแยก

- **`#statutoryRateModal` ยังกระโดดตอนสลับ tab (25.6px)** — มี tab ใน `.modal-body` เหมือน `#manageLinesModal`
  แต่ไม่ได้ใส่ `.modal-tabbed` ในรอบ 2026-09-15 เพราะเนื้อในเป็นฟอร์มสั้น ไม่มี footer วัดได้
  473.5 → 499.1 — ถ้าบังคับสูง `calc(100vh - 200px)` จะกลายเป็น modal โล่งเกือบครึ่งใบ แย่กว่าเดิม
  — ตัดสินตอนไล่หน้า Tax & Statutory · **2026-09-17: `.modal-tabbed` ถูกยกเลิกและลบ CSS ทิ้งแล้ว**
  (ดู `docs/decisions/2026-09-17-remove-manage-lines-tabs.md`) ทางเลือกเหลือ "ปล่อยไว้" กับ "ออกแบบใหม่"
  ไม่ใช่ "ใส่ class เดิม" อีกต่อไป

---

## เปิดตัวเลือก payee `not_disbursed` (Write-off) กลับ เมื่อมี semantic จริง + รายงานที่อ่าน `payee_type`

ถอดออกจากทั้ง 4 payee picker เมื่อ 2026-09-16 เพราะ **ให้ผลลัพธ์เหมือน `none` (NULL) ทุกประการ**: ทั้งคู่
ถูกข้ามด้วยบรรทัดเดียวกันใน `PayrollRemittanceModel::generateForRun()`, `PayrollRunModel::recalculate()`
ไม่มี branch ไหนเช็คค่านี้เลย, และไม่มีไฟล์ใน `app/services/reports/` อ้าง `payee_type` สักที่ — ต่างกันจริง
แค่ `include_in_cash_summary` ที่ยังไม่มีใครอ่าน (ดู entry ของมันเองด้านบน) — enum/validation/read path
ฝั่ง backend ยังอยู่ครบ ไม่มี migration

**ต้องมีครบ 2 ข้อก่อนเปิดกลับ** (ไม่ใช่แค่ข้อใดข้อหนึ่ง): (1) ลูกค้านิยามว่า "หักแต่ไม่มีเงินสดเคลื่อนไหว"
ต่างจาก "หักเข้าบริษัท (ไม่บันทึก)" ยังไง — ถ้าคำตอบคือ "ไม่ควรลด net pay ของพนักงานจริง" นั่นคือการแก้
`recalculate()` ไม่ใช่แค่คืนตัวเลือก; (2) มีรายงาน/หน้าจอที่อ่าน `payee_type` แล้วแยก 2 ค่านี้ออกจากกันจริง
— **ถ้าเปิดกลับจริง ต้องตัดสินก่อนว่ามันเป็นปลายทางที่ 4 หรือเป็นอีกคำตอบของคำถามย่อยใต้ "หักเข้าบริษัท"**
(โครงปัจจุบัน: 3 ปลายทาง + คำถามย่อย 1 ข้อ — `docs/decisions/2026-09-16-payee-three-destinations.md`)
— `payee_type_not_disbursed` ยังอยู่ใน lang เพราะ read path ใช้แสดง tag ของแถวเก่า

**Source:** รอบเล็ก "ซ่อน payee not_disbursed + เขียนคำใหม่" (2026-09-16) — เหตุผลเต็ม:
`docs/decisions/2026-09-16-hide-write-off-payee.md`

---

## `none` กับ `company` ต่างกันแค่แถว audit ใน `payroll_remittances` — ถามลูกค้าพร้อมเรื่อง money-encryption

หลังถอด `not_disbursed` ออกแล้ว (entry ด้านบน) เหลือคู่ที่ใกล้กันอีกคู่: `payee_type = NULL` ("บริษัทเก็บไว้
ไม่ต้องโอน") กับ `'company'` ("โอนเข้าบัญชีบริษัท") — ทั้งคู่ไม่มีเงินออกจากบริษัทจริง, ไม่เข้าไฟล์โอนธนาคาร,
ไม่กระทบ net pay/ภาษี/statutory ต่างกันแค่ `'company'` สร้างแถว `payroll_remittances` สถานะ `success`
ทันที (audit-only) + บังคับเลือก `bank_account_id` ส่วน NULL ไม่ทิ้งร่องรอยอะไรเลย

**คำถามที่ต้องถามลูกค้า**: ต้องการ audit trail ต่อรายการหักที่บริษัทเก็บไว้เองทุกใบไหม —
ตอนนี้ความต่างนี้ถูกถามเป็น**คำถามย่อย** "บันทึกเป็นรายการโอนเข้าบัญชีบริษัทหรือไม่" ใต้ปลายทาง
"หักเข้าบริษัท" (2026-09-16, `docs/decisions/2026-09-16-payee-three-destinations.md`) —
**ถ้าลูกค้าตอบว่าไม่ต้องแยก ให้ลบคำถามย่อยทั้งข้อทิ้ง** เหลือ 3 ปลายทางล้วน (ลบ block
`$payee_allow_no_record` ออกจาก partial ให้เหมือน per-run override editor ที่ไม่มีคำถามนี้อยู่แล้ว)
แล้วเลือกว่าจะให้ "หักเข้าบริษัท" หมายถึง NULL หรือ `'company'` อย่างใดอย่างหนึ่งไปเลย —
**ถามรวมทริปเดียวกับเรื่อง money-encryption** (การเข้ารหัสเลขบัญชี/สิทธิ์เห็นเลขบัญชีเต็ม ดู
`tests/payee_option_masking_test.php`) เพราะทั้ง 2 เรื่องอยู่ที่หน้าจอเดียวกันและกระทบตัวเลือกชุดเดียวกัน

**Source:** รอบเล็ก "ซ่อน payee not_disbursed + เขียนคำใหม่" (2026-09-16)

---

## `tests/id_codec_test.php` — assertion "tampered real token (flipped last char)" flaky ~6%

เจอตอนรัน suite เต็มรอบ typography (2026-09-16): ไฟล์นี้ fail 1 assertion ใน batch แต่รันเดี่ยวผ่าน 5/5 —
วัดจริงแล้ว: สร้าง token 3,000 ใบแล้วพลิกตัวอักษรสุดท้าย 'A'↔'B' **decode ผ่าน 193/3000 = 6.4%**

ไม่ใช่บั๊กของ `IdCodec` — ตัวอักษร base64url ตัวสุดท้ายถือ bit จริงแค่ 2-4 bit ที่เหลือเป็น padding ที่ถูกทิ้ง
ตอน decode การพลิกตัวสุดท้ายจึงได้ byte payload ชุดเดิม (signature ครอบ payload ไม่ใช่ตัวอักษร) — **ตัว
assertion เองตั้งสมมติฐานผิด** ว่าเปลี่ยน 1 ตัวอักษรต้องทำให้ token เสียเสมอ

**ทางแก้เมื่อหยิบขึ้นมา**: เปลี่ยนไปพลิกตัวอักษร**กลางๆ** ของ token (bit จริงทั้งหมด) หรือวนพลิกจนกว่าค่าที่ได้
จะต่างจริง แล้วค่อย assert — ห้ามแก้ `IdCodec` เพื่อให้ test ผ่าน (พฤติกรรมปัจจุบันถูกแล้ว)

**Source:** รอบ typography ของ tab รายการจ่าย (2026-09-16) — เจอระหว่างรัน suite ไม่เกี่ยวกับ diff รอบนั้น

---

## Shared empty state ของ DataTable — จบแล้ว (2026-09-16) เหลือแค่หน้าที่ยังไม่ migrate

`initSharedDataTable()`'s `emptyState` ตอนนี้เป็นของ shared ครบทั้ง 2 แบบตาม rules.md §6: มีตัวกรอง
ทำงานอยู่ → "ไม่พบข้อมูลที่ตรงกัน" + ปุ่ม outline "ล้างตัวกรอง"; ไม่มีเลย → ข้อความ/ปุ่มสร้างของหน้านั้น

**บั๊กที่แก้ไปพร้อมกัน**: ปุ่ม "ล้างตัวกรอง" ใน empty state เดิมเรียก `dt.search('').draw()` ซึ่งล้าง
**เฉพาะช่องค้นหา** — ตารางที่ว่างเพราะ filter-bar หรือ column filter (2 กรณีที่พบบ่อยที่สุด) กดแล้วไม่มีอะไร
เกิดขึ้นเลย ตอนนี้ทั้ง 2 ที่เรียก `clearAllTableFilters()` ตัวเดียวกัน ล้างครบ 3 แหล่ง (filter-bar ผ่าน
`$bar.data('filterBarClear')`, column filter ผ่าน `clearColumnFilters()`, ช่องค้นหา)

**ที่ยังเหลือ**: 14 หน้าที่ยังสร้าง DataTable เองด้วย `$().DataTable()` ไม่ได้ผ่าน `initSharedDataTable()`
จึงยังได้ข้อความบรรทัดเดียวของ DataTables เหมือนเดิม (ไม่ใช่ empty state นี้) — ไปพร้อมกับ entry
"รอบ 4: ย้ายทุกหน้าที่ยังใช้ `$().DataTable()` ตรงๆ" ด้านบน ไม่ใช่งานแยก

**Source:** รอบ filter-bar/DataTable control scale (2026-09-16)

---

## แบนเนอร์ "พนักงาน 0 คนมีข้อผิดพลาดในการคำนวณ" ขึ้นบนรอบที่ไม่มีแถวรายละเอียดเลย

เจอตอนตรวจ empty state แบบ (b) บนรอบ draft ที่ยังไม่เคยคำนวณ (2026-09-16): `#validationErrorsBanner`
โชว์ข้อความ **"พนักงาน 0 คนมีข้อผิดพลาด..."** เพราะเงื่อนไขอ่าน `run.has_validation_errors` (flag บนแถว
`payroll_runs`) แต่จำนวนที่เอาไปเติม `{count}` นับจาก `run.details` ซึ่งว่างเปล่า — flag ค้างจากการคำนวณ
ครั้งก่อนที่ถูกล้าง detail ทิ้งไปแล้ว

**ทางแก้เมื่อหยิบขึ้นมา** (เป็นงาน logic ไม่ใช่ design): ตัดสินใจก่อนว่า flag ควรถูกล้างตอนไหน — ตอน
`recalculate()` ลบ detail ทิ้ง หรือให้ UI ไม่แสดงแบนเนอร์เมื่อ `errCount === 0` — แล้วแก้ที่ต้นทางจุดเดียว
ไม่ใช่ทั้งสองที่ (`public/js/payroll/detail.js` บรรทัดที่เรียก `validation_errors_banner`)

**Source:** รอบ filter-bar/empty state (2026-09-16) — ไม่ได้แก้ในรอบนั้นตาม §0.7 (phase design ห้ามแก้ logic)

---

## popover ไม่มีเงาจริง — rules.md §6 บอก `--shadow-soft` แต่ Bootstrap ไม่เคย apply

วัดที่หน้าจริงหลังแก้บั๊ก comment (2026-09-16): `.popover` computed `box-shadow: none` ทั้ง 2 theme
ทั้งที่ `style.css` ตั้ง `--bs-popover-box-shadow: var(--shadow-soft)` ไว้ — ต้นเหตุไม่ใช่ override ไม่ติด
แต่เป็นเพราะ **`bootstrap.min.css` ประกาศตัวแปร `--bs-popover-box-shadow` ไว้เฉยๆ แล้วไม่เคยเขียน
`box-shadow: var(--bs-popover-box-shadow)` บน `.popover` เลย** (ยืนยันจากอ่านไฟล์ตรง — popover ของ
Bootstrap ไม่มีเงามาแต่เดิม ต่างจาก `.dropdown-menu` ที่ apply จริง) บรรทัดนั้นจึงเป็น no-op มาตลอด
ไม่ใช่ของที่เพิ่งหายไปพร้อมบั๊ก comment

**ตัดสินตอนรอบ 3d (2 ทาง เลือกทางเดียว)**:
(ก) ประกาศ `box-shadow: var(--shadow-soft)` ตรงๆ บน `.popover` ให้ตรงกับที่ §6 เขียนไว้ — surface ลอย
ควรมีเงาตามหลักการเดียวกับ dropdown/notification; หรือ (ข) แก้ §6 ให้ตรงกับความจริงว่า popover ใช้ขอบ
`--c-border` อย่างเดียวไม่มีเงา แล้วลบบรรทัด `--bs-popover-box-shadow` ทิ้ง (ไม่ทิ้ง dead declaration ไว้)

**Source:** รอบตาราง "ย้ายข้อมูลออกจากเซลล์" (2026-09-16) — เจอตอนวัดของที่เพิ่งได้ token คืน

---

## legacy base text `#555` ยังเป็นสีตกทอดของ `.popover` และ panel column-filter

`html, body { color: #555555 }` (`style.css` บนสุด — ค่าเดียวกับ legacy token `--app-text` ของ T069)
ยังเป็นสีที่ **surface ลอย 2 ตัวนี้รับช่วงมา** เพราะทั้งคู่ไม่ประกาศ `color` ของตัวเอง: `.tcf-panel`
(ตั้ง background/border/radius/shadow/font-size ครบแต่ไม่มี `color`) และ `.popover` (ตั้งแต่ `--bs-popover-
body-color` ซึ่งมีผลกับ `.popover-body` ไม่ใช่กล่องนอก)

**ตอนนี้ยังไม่เห็นผลด้วยตา** เพราะ child ทุกตัวที่มีข้อความจริง (`.popover-header`/`.popover-body`/
`.tcf-panel-title`/`.tcf-item`) ตั้งสีของตัวเองทับหมด — เป็นสีที่รอ inherit ให้ผิด ถ้ามีใครเพิ่ม element
ข้อความใหม่ในกล่องพวกนี้แล้วลืมตั้งสี

**ทำตอนรอบ 3d ที่ mark `design:clean`**: ตั้ง `color: var(--c-text)` ที่ `.tcf-panel`/`.popover` ให้จบ
(หรือถ้าจะแก้ที่ต้นทางจริงคือ `html, body`'s `#555` ซึ่งกระทบทั้งแอป ต้องเป็นงานของตัวเองพร้อมวัดหน้าอื่นด้วย
ไม่ควรพ่วงกับ 3d เงียบๆ) — ดู `--app-*` ~220 บรรทัดที่เหลือใน `style.css` เป็นงานเดียวกันชุดใหญ่กว่า

**Source:** รอบตาราง "ย้ายข้อมูลออกจากเซลล์" (2026-09-16) — เจอตอนวัดของที่เพิ่งได้ token คืน

---

## Batch 5: คำนวณใหม่เฉพาะพนักงานที่เลือก (partial recalculate)

`recalculate()` ทำได้แค่ทั้งรอบ (ลบ detail ทั้งรอบแล้วสร้างใหม่) — ต้องรับ `?array $onlyEmployeeIds`
ก่อน ถึงจะมีปุ่ม "คำนวณใหม่" ต่อแถว/"คำนวณที่เลือก N" ได้ · เป็นงาน logic จึงไม่ทำในเฟส design (§0.7)
และรอบ design **ไม่โชว์ control ที่ยังไม่มี backend** · สเปกเต็มที่ตัดสินแล้ว (พฤติกรรม/กรณีขอบ/UI/
audit note/test 4 ข้อ): `docs/specs/partial-recalculate.md`

---

## Batch 5: `line-override.save-batch` (บันทึกหลายแถว recalculate ครั้งเดียว)

tab "ปรับตัวเลข" เป็นตารางเดียว + ปุ่มบันทึกเดียวแล้ว แต่ backend ยังไม่มี endpoint รับหลายแถว และ
`lineOverrideSave()` เองจบด้วย `recalculate()` ทั้งรอบทุกครั้ง → แก้ 5 แถว = 5 request + 5 full
recalculate (ยิงพร้อมกันไม่ได้ recalculate จะเขียนทับกัน) · เป็นงาน logic ทำในเฟส design ไม่ได้ (§0.7)
สเปกเต็ม (endpoint/transaction/audit/test 5 ข้อ + สิ่งที่ต้องถอดออกจาก UI ตอนนั้น):
`docs/specs/line-override-batch.md`

---

## ปุ่มบันทึกซ่อนของ Adjustments modal — เหลืออีก 3 tab

dispatcher (`ADJUSTMENT_TAB_CONFIG_RD`) สั่งบันทึกด้วยการ "กดปุ่มที่ซ่อนไว้" (`saveSelector`) ของแต่ละ tab
· 2026-09-17 (D3) tab "ปรับตัวเลข" ที่เคยเป็นตัวอย่าง `saveFn` ถูกลบทั้ง tab แล้ว — เหลือ **ข้อมูลเข้างาน /
ปลายทางรายการหักประจำ / ภาษี & ประกันสังคม** ที่ยังมี `<button class="d-none">` ของตัวเองอยู่ครบทั้ง 3
ให้ย้ายเป็นเรียกฟังก์ชันตรงทีละ tab แล้วลบ `saveSelector` ทิ้งทั้งกลไก

---

## เวลาที่แสดง = UTC หรือเวลาเครื่อง? (`changed_at`/`created_at` ทั้งแอป)

`formatDisplayDateTime()` (app.js) ถือว่า datetime ที่เก็บในฐานเป็น **UTC** แล้วแปลงเป็นเวลาเครื่องผู้ใช้
(เติม `Z` ก่อน parse) — แต่คอลัมน์พวกนี้ส่วนใหญ่เขียนด้วย MySQL `NOW()`/`DEFAULT CURRENT_TIMESTAMP`
ซึ่งเป็น **เวลาของเซิร์ฟเวอร์ฐานข้อมูล** (dev = Asia/Bangkok) ทั้งที่ PHP ตั้ง `date_default_timezone_set('UTC')`
ไว้ที่ `index.php` · ผลจริงที่วัดได้ 2026-09-16: override ที่บันทึกตอน 09:56 แสดงเป็น 16:56 (+7 ชม.)
ทั้งใน dropdown ประวัติและ modal ประวัติ — และเหมือนกันทุกที่ในแอปที่แสดงเวลาจากคอลัมน์เหล่านี้
(audit log, timeline อนุมัติ, ประวัติการเข้าใช้งาน ฯลฯ) ไม่ใช่ปัญหาเฉพาะรอบนี้

**ต้องตัดสินว่าอะไรคือความจริง** ก่อนแก้: (ก) เขียนเป็น UTC ให้หมด (PHP คุมเวลาเอง ไม่ใช้ `NOW()` ของ MySQL)
แล้ว `formatDisplayDateTime()` ถูกอยู่แล้ว หรือ (ข) ยอมรับว่าเก็บเป็นเวลาเซิร์ฟเวอร์ แล้วเลิกเติม `Z`
· ห้ามแก้ทีละหน้า — เป็นกฎเดียวทั้งแอป · งาน logic ไม่ใช่ design (§0.7)

**Source:** วัดด้วย Playwright ตอนทำ dropdown/modal ประวัติของ tab ปรับตัวเลข (2026-09-16)

---

## ข้อความปฏิเสธจาก server เป็นภาษาอังกฤษล้วนทั้งแอป

`assertManualLinesEditable()`/`lineOverrideSave()` ฯลฯ คืนข้อความอย่าง `'This employee is verified for
this run...'` ตรงๆ ไม่ผ่าน i18n — UI แสดงมันใน callout ของฟอร์ม (§15) ตามกฎแล้ว แต่ผู้ใช้ไทยอ่านอังกฤษ
ทั้งประโยค · แก้ต้องทำทั้งชั้น model (คืน key + params แทน string) ไม่ใช่ทีละจุดเรียก

**Source:** D2 (2026-09-17) flag ไว้, D3 ย้ำอีกครั้งตอนลบ tab

---

## override ค้างบน manual line ใน production — ยังไม่เคยตรวจ

D1 กรอง `source='manual_line'` ออกจาก `syncDeductionLinesForEmployee()` แล้ว · dev DB ตอนนั้นมี
`payroll_run_line_overrides` 0 แถว จึงยืนยันได้แค่ว่า dev ไม่มีแถวค้าง · ถ้า production มี override
ที่ผูกกับ `item_code` ของ manual line อยู่จริง มันยังมีผลกับการคำนวณต่อไป แต่มองไม่เห็น/กดยกเลิกจาก
หน้าไหนไม่ได้เลย — ต้อง query ของจริงก่อน แล้วค่อยตัดสินว่าจะเก็บกวาดยังไง

**Source:** D1 (2026-09-16) flag ไว้, ยังไม่ได้ตรวจ ณ D3

---

## `line-override.save` ยังรับ item_code ของ manual line ได้

ฝั่ง read กรอง manual line ออกแล้ว (D1) แต่ฝั่ง write ไม่ได้กัน — POST `item_code` ที่เป็นของ manual
line เข้าไปตรงๆ ยังสร้างแถว override ได้ · UI ปัจจุบันไม่มีทางส่งแบบนั้น แต่ endpoint เป็นของสาธารณะ
· ถ้าจะกัน ต้อง reject ที่ `lineOverrideSave()` และตัดสินด้วยว่าจะทำยังไงกับแถวที่มีอยู่แล้ว (ข้อบน)

**Source:** D3 (2026-09-17)

---

## `tests/id_codec_test.php` flaky ~8% (มาก่อนรอบนี้ ไม่ใช่ regression)

assertion "tampered real token (flipped last char) fails to decode" fail แบบสุ่ม — วัดจริง 5/60 รอบ (2026-09-17)
· สาเหตุ: base64url ตัวท้ายมี bit ที่ไม่ได้ใช้ พลิกตัวอักษรบางตัวจึงได้ ciphertext ชุดเดิมเป๊ะ แล้ว decode ผ่านตามปกติ
· แก้ที่ตัว test (พลิก byte กลาง/ตรวจว่า token เปลี่ยนจริงก่อน assert) ไม่ใช่ที่ `IdCodec`

**Source:** เจอตอนรัน run_all รอบ D3 (2026-09-17) — ไฟล์นี้ไม่ได้ถูกแตะในรอบนั้น

---

## ถามลูกค้า: รายงาน register ควรแยกคอลัมน์ต่อชื่อ custom หรือรวมถังเดียว "อื่นๆ"

รายการที่พิมพ์ชื่อเอง (`CUSTOM:{ชื่อ}`) ตอนนี้ขึ้นรายงานเป็นชื่อของตัวเอง 1 แถว/ชื่อ · ถ้าลูกค้าอยากได้ถังรวม
"อื่นๆ" ถังเดียวแทน ต้องมี master list ของชื่อที่ใช้ได้ (Batch 5) ไม่ใช่ปล่อยพิมพ์อิสระแล้วรวมทีหลัง ·
ถามก่อนทำ อย่าเดา — ผลต่างคือมี/ไม่มีตารางใหม่

**Source:** R1b (2026-09-17)

---

## catalog > 10 แถว: "อื่นๆ (ระบุชื่อ)" ไม่โผล่จนกว่าจะเลื่อนถึงหน้าสุดท้าย

`pinnedOption` (input.js) ต่อท้ายเฉพาะ**หน้าสุดท้าย** เพราะ select2 ต่อผลหน้าใหม่เข้าท้ายรายการเดิม
ใส่ทุกหน้าจะซ้ำ/ไปค้างกลางรายการ · catalog ตอนนี้ 8 แถว (limit 10) เลยยังเห็นทันที ·
ถ้าบริษัทไหนมีเกิน 10 ต้องเขียน results adapter ของ select2 เองให้ pin เป็น footer จริง

**Source:** R1b (2026-09-17)

---

## #eedModal ยังใช้ segmented 3 โหมด ให้เปลี่ยนมาใช้ picker เดียวกับ R1b

`layout/modals.php` (Employee Detail > Earning/Deduction) ยังเป็น `.mode-select-group` 3 โหมด +
lang `manual_line_mode_*`/`mode_desc_*` ซึ่ง 6 key นั้นเหลือที่นี่ที่เดียว · ย้ายมาเป็น select เดียว
(`pinnedOption`+`stripCodePrefix`) แล้วลบ key/CSS ที่เหลือได้ · **ทำรวมกับข้อ `.form-compact` ของ ก้อน 4**

**Source:** R1b (2026-09-17)

---

## แถวที่ถูก exclude เสียปลายทาง/เลขงวดไปทั้งคู่ (ไม่ใช่เรื่อง CSS)

`syncDeductionLinesForEmployee()` สร้างแถวของ line ที่ถูก exclude จาก `payroll_run_line_overrides` +
catalog เท่านั้น (line หายจาก breakdown JSON ไปแล้ว) → `payee`/`installment` เป็น null ทั้งคู่ tag จึงไม่ render
เลย ไม่ใช่ "จางลง" · ถ้าต้องการให้ยังบอกปลายทางได้ ต้องดึงจาก PED assignment เพิ่ม (lookup batch ใหม่ในลูป
fallback) — เป็นการตัดสินใจเชิงพฤติกรรม ไม่ใช่ style จึงไม่ทำในรอบนี้ · `.lo-row-off .payslip-line-tag` ที่เพิ่มไว้
ยังจำเป็นจริงสำหรับแถวที่ปิดจาก Run Settings (ยังอยู่ใน breakdown จึงยังมี tag)

tiny-C (2026-09-18) เจอฝั่งเดียวกันอีกเรื่อง: แถว exclude ไม่มี entry ใน breakdown จึงไม่มี `computed_amount` ด้วย
บรรทัด `ระบบ: x` ของแถวพวกนี้จึงยังเงียบ — ถ้าวันไหน persist entry ที่ถูก exclude (amount 0) ค่านี้จะตามมาเองในคราวเดียวกัน

**Source:** tiny-L5 (2026-09-18)

---

## "ค่าระบบ x" หลอกได้ถ้า override แรกของแถวนั้นไม่มี history

`lineOverrideComputedTextRd()` อ่าน `original_value` ของ **edit แรกที่ถูกบันทึก** ไม่ใช่ค่าที่ engine คำนวณจริง ·
ถ้าแถวนั้นมี override อยู่ก่อนที่จะมี history (เช่น fixture ของ `tests/ui/mksession.php` ที่ insert ตรง) ค่าที่แสดงจะเป็น
ยอด override เก่า ไม่ใช่ค่าคำนวณ — กด "ใช้ค่าที่ระบบคำนวณ" แล้วได้คนละตัวกับที่บอกไว้ (วัดจริงตอน L6a: hint 28,500.00 → คืนจริงได้ 0.00)
· เป็นของเดิมมาตั้งแต่ R1 ไม่ใช่ regression ของ L6a · แก้จริงต้องมีค่า engine จริงเก็บไว้ (breakdown JSON เก็บเฉพาะยอดหลัง override)

**L6b (B3) แก้แบบ interim แล้ว ยังไม่ปิด** — แถวที่ไม่มี history จะ**ไม่แสดงบรรทัด `ระบบ: x` เลย** (ไม่ใช่ `-` ไม่ใช่
ตัวเลขมั่ว) และ hint ในฟอร์มใช้เงื่อนไขเดียวกันผ่าน `lineOverrideComputedTextRd()` ตัวเดิมตัวเดียว · แปลว่า "ไม่หลอก" แล้ว
แต่แถวที่**มี** history ก็ยังโชว์ `original_value` ซึ่งเป็นตัวแทนของค่า engine ไม่ใช่ค่า engine จริง — ปิดจริงต้องทำ tiny-C

**tiny-C ทำแล้ว (2026-09-18) — ปิดข้อนี้** `recalculate()` persist ค่า engine ก่อนทับเป็นคีย์ `computed_amount` ใน breakdown JSON (earning/deduction/statutory) + คอลัมน์ใหม่ `payroll_run_details.base_salary_computed_amount` สำหรับเงินเดือนพื้นฐาน (เลือกคอลัมน์ ไม่เอา entry สังเคราะห์ — ดู `docs/decisions/2026-09-18-tiny-c-computed-amount.md`) · `lineOverrideComputedTextRd()` อ่านคีย์นี้ก่อน fallback history เดิมยังอยู่ครบสำหรับ run เก่า · เหลือเฉพาะแถว exclude (ดูข้อด้านบน) สิ่งที่ทำจริงคือ:
1. `PayrollRunModel::recalculate()` เก็บยอดก่อนทับ 3 จุด — `:4034` (earning/deduction), `:4058` (base salary),
   `:4527` (statutory) ~2 บรรทัด/จุด
2. `syncDeductionLinesForEmployee()` ส่งผ่านออกมาทั้ง 4 row shape (base salary / earning-deduction / statutory /
   exclude fallback) ~8–10 บรรทัด
3. `detail.js` `lineOverrideComputedTextRd()` อ่าน `line.computed_amount` ก่อน แล้วค่อย fallback history เดิม ~3 บรรทัด
4. `tests/line_override_row_render_test.js` + `tests/line_override_table_test.php` เพิ่ม assertion ~20 บรรทัด

รวม ~40–60 บรรทัด · **ข้อควรระวัง**: base salary ไม่ได้อยู่ใน breakdown JSON ใดเลย (`payroll_run_details.base_salary_amount`
เป็นคอลัมน์เดี่ยว) ค่า engine ของมันจึงไม่มีที่เก็บ ต้องเลือกระหว่างคอลัมน์ใหม่ (= DDL + migration) กับยัดเป็น entry
สังเคราะห์ใน breakdown — **ตัดสินข้อนี้ก่อนเริ่ม tiny-C** · และค่าจะถูกต้องเฉพาะ run ที่ recalculate หลังแก้แล้วเท่านั้น
run เก่าจะยังไม่มีคีย์นี้ ต้องคง fallback history ไว้ ห้ามถอด

**ยังเปิดอยู่ (ชุด H):** save ที่ยอดไม่เปลี่ยน (แก้แต่หมายเหตุ) ก็เขียนประวัติเป็นแถว `จาก x → x` = noise ต้องไม่บันทึกเป็นรายการยอด

**Source:** tiny-L6a (2026-09-18) — เจอตอนวัด Playwright · interim ลงใน tiny-L6b (2026-09-18)

---

## dirty-guard ของ #manualLineFormModal: default ที่มาแบบ async อาจทำให้ฟอร์ม dirty ตั้งแต่เปิด

L6a เปิด `data-dirty-guard` ให้ฟอร์มนี้ — baseline ถ่ายตอน `shown.bs.modal` ซึ่งหลัง prefill เสมอ (prefill รันก่อน `.show()`)
แต่มี 2 เส้นทางที่ลงทีหลังจากนั้นได้: `applyDefaultCompanyBankAccount()` (เติมบัญชีบริษัท default ให้แถวที่ payee เป็น company
แต่ยังไม่มีบัญชี) กับ `refreshManualLineSavedDestinationsRd()` (อาจพลิก radio saved/new) — ทั้งคู่ทำให้ปิดฟอร์มแล้วโดนถาม
"มีข้อมูลที่ยังไม่ได้บันทึก" ทั้งที่ผู้ใช้ไม่ได้แตะอะไร · วัดใน L6a ยังไม่เจอ (run ทดสอบไม่มีแถว recurring/ped) · ทางแก้คือ §9 ข้อ 4:
เรียก `refreshDirtyGuard()` อีกครั้งเมื่อ 2 เส้นทางนั้นลงจริง — ต้องมีตัวนับ request ที่ค้างอยู่ก่อน ไม่ใช่ setTimeout

**L6b วัดแล้วด้วย fixture จริง — ไม่เกิด** (2026-09-18): `mksession.php --with-recurring` ทำแถว recurring ที่
payee เป็น company บน run ทดสอบ (คือเงื่อนไขที่ L6a ขาดไป) · เปิดฟอร์ม รอ 2.5 วินาทีให้ async default ลงครบ ปิดโดยไม่แตะอะไร
→ **ไม่ถาม** ทั้ง 4 cell (1400/430 × light/dark) · จึงยังไม่ได้แก้ — อยู่ใน backlog ต่อเพราะเส้นทาง async
ทั้งสองมีจริงในโค้ด แต่ fixture ที่มีอยู่ยังไม่ได้ทำให้มันลงทีหลัง baseline จริง (กรณีที่จะทำได้น่าจะเป็น payee ที่ยัง
ไม่มีบัญชีบริษัท — `applyDefaultCompanyBankAccount()` ถึงจะมีอะไรให้เติมจริง) · วัดซ้ำตอนสร้าง fixture แบบนั้นได้

**Source:** tiny-L6a (2026-09-18) · วัดไม่เจอใน tiny-L6b (2026-09-18)
