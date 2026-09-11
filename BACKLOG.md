# Backlog

Deferred items noted during work sessions. Not scheduled — pick up when asked.
Format: Title / 1-2 line detail / Source (which batch).

---

## Defer langReady-gating to 28 files outside payroll-process

`public/js/app.js` now exposes `window.langReady` (a Promise that resolves once `langData` is
populated) so a page can defer its own initial render until translations are actually loaded. Only
the 3 payroll-process files (`detail.js`/`index.js`/`approval.js`) were updated to wait on it. The
following 28 files each have their own `$(document).ready(function () {...})` calling an initial
load/render directly, not gated behind `window.langReady` — same latent race condition is possible
in any of them (not confirmed reproduced in each one individually, just the same pattern):

```
public/js/dashboard.js
public/js/employee/detail.js
public/js/employee/list.js
public/js/employee/login-history.js
public/js/employee/reports.js
public/js/input.js
public/js/notifications.js
public/js/quick-links.js
public/js/reports/annual-summary.js
public/js/reports/index.js
public/js/reports/run-audit.js
public/js/setup/announcements.js
public/js/setup/approval-workflow.js
public/js/setup/audit-log.js
public/js/setup/changelog.js
public/js/setup/company-profile.js
public/js/setup/document-numbering.js
public/js/setup/email-queue-log.js
public/js/setup/employment-certificate-request.js
public/js/setup/employment-certificate-template.js
public/js/setup/help-drawer.js
public/js/setup/payroll-configuration.js
public/js/setup/payslip-delivery-log.js
public/js/setup/payslip-distribution.js
public/js/setup/payslip-request.js
public/js/setup/payslip-template.js
public/js/setup/setup-guide.js
public/js/setup/tax-statutory.js
public/js/setup/terms-and-conditions.js
```

(Re-grepped 2026-09-10 to confirm this list: 28 files, not 29 — noting the discrepancy from the
count mentioned verbally rather than silently forcing it to match.)

**Source:** Batch 2, item 0.

---

## Cache-bust `<script src>` for JS files and lang JSON

None of the JS `<script src="...">` tags (app.js, payroll-process files, or any other page script)
carry a version query string, and the lang JSON fetch (`loadLang()` in app.js) busts cache via
`?v=${Date.now()}` — which forces a full network fetch on every single page load (no caching benefit
at all). A stale browser cache serving a pre-fix copy of `index.js`/`app.js` was the actual cause of
a false "still broken" report in Batch 2 (item 0) right after the langReady race fix shipped.

**Fix direction (explicit instruction, not yet decided in detail):** use a stable version string from
app config (e.g. `APP_VERSION`, bumped on deploy) for cache-busting — **not** `Date.now()`, which
would defeat caching entirely rather than just busting it across deploys. Apply to every JS
`<script src>` (not just app.js/payroll-process) and to the lang JSON fetch's own `?v=` param.

**Source:** Batch 2, item 0 (first flagged), reconfirmed as backlog after item 0's fix.

---

## Consolidate apvApproverSubstepHtml* (Rd/Pr/Ap) into app.js

`apvAvatarHtml*`/`apvPersonLineHtml*`/`apvIconHtml*`/`apvBadgeHtml*`/`APV_COLORS_*`/
`apvCreatedStageHtml*` were consolidated into app.js in Batch 3A item 3 (byte-identical across all
3 pages, confirmed before merging). **Still duplicated 3x, not yet done**: `apvApproverSubstepHtmlRd`/
`Pr`/`Ap` (the per-approver row inside the Approval stage's own step-group body) — same
`auditActionLabel()` consolidation precedent, not folded into item 3 since it wasn't part of that
item's explicit scope.

**Source:** Batch 2, item 2 (originally flagged) — `apvAvatarHtml*` half done in Batch 3A item 3
(2026-09-10); `apvApproverSubstepHtml*` still pending.

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
