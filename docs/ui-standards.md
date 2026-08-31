# UI Standards — Origami Payroll

Dev-facing conventions for form inputs across this app. These are applied **automatically,
app-wide, with zero per-page setup** — nothing in a page's own JS needs to call an init function
for these to work. They live in `public/js/input.js`, which every page already loads via
`app/views/layout/header.php` (loaded right after jQuery/`app.js`/`alert.js`, before any
page-specific script or the page's own HTML body content).

If you're building a new form/page: you don't need to do anything for these two — just use a plain
`<input type="number">` / `<textarea>` and both standards apply on their own.

---

## T001 — Number input: select-all on focus

**Rule:** every `<input type="number">` selects its entire current value the moment it receives
focus, so the user can immediately type over it instead of having to select-all/backspace first.

**Scope:** `input[type="number"]` only. Plain `<input type="text">` is deliberately **not**
included — a text field's cursor position when you click/tab into it usually matters (you're often
editing a substring, not replacing the whole thing); a number field's almost never does (you're
almost always replacing the whole number).

**Implementation** (`public/js/input.js`):
```js
$(document).on('focus', 'input[type="number"]', function () {
    this.select();
});
```
Delegated to `document`, so it covers every matching element automatically — including ones that
don't exist yet at page-load time (a field inside a SweetAlert2 modal, a DataTables column-filter
popover, a Bootstrap modal opened later, anything injected by JS afterward). No per-field/per-page
wiring needed, now or for any future page.

**If you need a numeric-looking field that ISN'T `type="number"`** (e.g. a masked/formatted amount
field that has to stay `type="text"` for display formatting reasons), this rule doesn't apply to it
automatically — add the same one-liner scoped to that specific selector if you want the same
behavior there too, rather than widening the global rule (keeps the "text fields don't auto-select"
default intact for every other text input in the app).

---

## T002 — Textarea auto-expand

**Rule:** every `<textarea>` grows tall enough to show its full content — no scrollbar, no clipped
text — both the instant it renders with a pre-filled value AND live while the user types more.

**Implementation** (`public/js/input.js`):
```js
function autoExpandTextarea(el) {
    if (!el || el.tagName !== 'TEXTAREA') return;
    el.style.height = 'auto';               // must reset first, see note below
    el.style.height = el.scrollHeight + 'px';
}
$(document).on('input', 'textarea', function () {
    autoExpandTextarea(this);
});
$(document).ready(function () {
    document.querySelectorAll('textarea').forEach(autoExpandTextarea);
});
$(document).on('shown.bs.modal shown.bs.tab', function (e) {
    $(e.target).find('textarea').each(function () { autoExpandTextarea(this); });
});
```
Why `height = 'auto'` before reading `scrollHeight`: `scrollHeight` reports how tall the content
*would need* the box to be — but if the box is already fixed at some height from a previous resize,
a shorter new value can't report a smaller `scrollHeight` than the box currently occupies. Resetting
to `auto` first lets the browser recompute from actual content before measuring, so shrinking a
textarea's content re-shrinks the box too, not just growing it.

**Three points in a page's lifecycle need separate handling, all covered above:**
1. **Typing** — plain `input` event.
2. **Already on the page at load, pre-filled with content** — the `$(document).ready()` pass over
   every `<textarea>` currently in the DOM. A textarea inside a not-yet-shown Bootstrap modal/tab
   pane reads `scrollHeight` as if it were empty at this point (its ancestor is `display:none`) —
   handled by point 3.
3. **Becomes visible later** (a modal opens, a tab is switched to) — `shown.bs.modal`/`shown.bs.tab`
   re-runs the pass scoped to whatever just became visible.

**Zero-config coverage for values set by JS after page load** (`populateEmployeeForm()`-style code
loading an existing record's long saved text into a textarea, well after point 2 above already ran):
`input.js` wraps the **native `value` setter** on `HTMLTextAreaElement.prototype` so that ANY
assignment — jQuery's `.val(x)` (which sets `.value` under the hood for a `<textarea>`, no special
`valHook` involved) or a plain `el.value = x` — automatically re-runs `autoExpandTextarea()`
afterward, with no explicit call needed at the call site.

**Why this needed to be automatic rather than "remember to call something after you set the
value"**: this app already has one well-documented bug class from exactly that shape of mistake —
see `CLAUDE.md`'s Date Picker section on `bootstrap-datepicker` (a real, reported bug: a field set
via `.val(...)` without a required follow-up `.datepicker('update')` call left the widget's internal
state stale, and it silently blanked the field on the user's next click-away). Making the same class
of oversight structurally impossible for textareas — instead of documenting a rule every future call
site has to remember — is the point of the prototype override, not over-engineering for its own
sake.

**If you ever need to force a resize manually** (e.g. after a CSS change that alters the textarea's
font-size/width without changing its `.value`, which the automatic hooks above have no way to detect
on their own), call `autoExpandTextarea(el)` directly — it's a plain global function, not wrapped in
a closure.

**Known interaction, not a bug**: a textarea styled with `resize: vertical` (the browser's native
manual drag-handle — several existing pages already set this, e.g. `.pst-info-textarea-group
textarea`) still shows that handle, but the next keystroke re-runs the auto-expand and snaps the
height back to fit content, overriding whatever height the user just dragged it to. This is the
normal, expected trade-off of pairing auto-grow with a manual resize handle (most auto-grow textarea
implementations elsewhere use `resize: none` for exactly this reason) — left as-is here since
changing any existing page's `resize` CSS wasn't part of this standard's scope; if a specific page's
manual-resize handle genuinely needs to coexist without being fought, set `resize: none` on that
page's own textarea instead of changing the global behavior.

---

## T004 — DataTable language: re-bind on every language change, not just page load

**Rule:** every DataTable's own rendered chrome text (Search label, "Show N entries" length-menu
label, First/Previous/Next/Last pagination buttons, "Showing X to Y of Z entries" info text) and
every column whose `render()` branches on `currentLang` must reflect the new language the instant
`changeLanguage()` runs — not only on the table's very first `.DataTable({...})` construction.

**Zero-config, same as T001/T002** — this lives in `public/js/app.js` and runs automatically for
every DataTable on the page, current and future, with no per-table/per-page opt-in. You still need
to pass `language: getTableLang()` at construction time as usual (unchanged) — the fix below is
purely about keeping that in sync afterward.

**Two separate mechanisms, both required, both already wired into `changeLanguage()`:**
1. `refreshAllDataTablesLanguage()` — fixes the DataTables-native chrome text. Confirmed via
   reading the actual bundled `node_modules/datatables.net/js/dataTables.js` source (not guessed)
   that a plain `.draw()` does NOT refresh the Search/length-menu labels (built once, at feature-
   registration time) or the info text (captured in a closure that never re-reads `oLanguage`) —
   only the pagination buttons read `settings.oLanguage` fresh on every draw. So this function: (a)
   mutates `settings.oLanguage` in place using DataTables' internal "Hungarian" property names
   (`sSearch`/`oPaginate.sFirst`/etc — NOT the camelCase shape `getTableLang()` itself returns,
   which DataTables only converts once, at init) + calls `table.draw(false)`, which is enough for
   pagination buttons; (b) directly patches the rendered `.dt-search > label` and `.dt-length >
   label` DOM text (the length-menu label wraps a live `<select>` inside two surrounding text
   nodes — only those text nodes are touched, never the `<select>` itself); (c) renders the info
   text itself using DataTables' own public `table.page.info()` API, bypassing the unreachable
   closure entirely.
2. `reloadAllTablesForLanguageChange()` — re-runs `.ajax.reload()` (ajax-backed tables) or
   `.rows().invalidate().draw(false)` (client-side tables — `.rows().invalidate()` is required
   before `.draw()` for a column's `render()` callback to actually re-run; a plain `.draw()` reuses
   DataTables' own per-row render cache) for every table, so a column whose `render()` branches on
   `currentLang` re-renders with the new language's data. A `shown.bs.tab` handler catches up any
   table that was inside a hidden tab pane at the moment language changed (would have been silently
   skipped otherwise, staying stale indefinitely).

**A real, subtle trap if you ever touch either of these again**: `$.fn.dataTable.tables({api:true})`
(the STATIC function, called with no Api instance yet) returns a *single* multi-table `_Api` object
that does **not** support `.every()` — `.every()` is only registered on the result of the *instance*
method `api.tables()` (called on an Api you already have). Confirmed by an actual `TypeError` in a
real jsdom + real bundled-DataTables test, not assumed — this exact call shape was silently broken
in this codebase for a while (wrapped in a `try/catch` written as a defensive "no DataTables on this
page" guard, which was actually swallowing a real, always-firing bug). Use the documented pattern
instead: `$.fn.dataTable.tables()` (no `{api:true}`) returns a plain array of `<table>` DOM nodes;
`$(node).DataTable()` (no args) returns the *existing* Api instance for an already-initialized table.

**Why this never destroys/recreates any table**: most pages keep their own page-level DataTables
variable (`tb_employee`, `tb_holiday`, ...) that other code calls methods on afterward
(`.ajax.reload()` from an Add/Edit/Delete success handler, etc.). A generic, page-agnostic sweep
that destroyed and reconstructed tables would silently orphan every such variable — it would still
point at the old, now-destroyed instance — breaking that page's own buttons until a full refresh.
Everything above only ever touches visible text or forces a redraw/reload; it never calls
`.destroy()` or constructs a new DataTables instance anywhere.

---

## T007 — Modal titles must survive a language change while the modal is open

**Rule:** a modal's title/header text must reflect the new language immediately when
`changeLanguage()` runs, even if that modal is currently open (Bootstrap modals stay in the DOM the
whole time, just toggled via CSS classes — they're not re-rendered from scratch on open).

**The easy 90% of cases**: if the title is a fixed, non-interpolated string, just give it
`data-i18n` the same way any other static label would get it — the existing generic
`updateText(document)` sweep (already run on every language change via `applyLanguage()`) picks it
up for free. No modal-specific code needed at all:
```js
$('#someModalLabel').attr('data-i18n', 'some_key').text(langData['some_key'] || 'Fallback');
```
(`app/views/setup/company-profile.php`'s `#bffFieldModal` and `public/js/employee/detail.js`'s
`#recurringEarningModalLabel` both follow this pattern.)

**The harder case: a title that depends on a small, already-tracked piece of state** (which action
opened it — add/edit/view; which entity type a generic sync modal is scoped to; etc.) — `data-i18n`
alone can't reproduce it, because the RIGHT string depends on more than just the current language.
Fix: remember whatever small piece of state the title depends on in a module-level variable, extract
the title-setting call into its own named function, and register a `refreshXyzLanguage()` function
that re-runs it (only if that modal is actually `.hasClass('show')` right now) — wired into
`changeLanguage()` (`app.js`) the same way `loadDashboardSummary()` already is:
```js
if (typeof refreshXyzLanguage === 'function') refreshXyzLanguage();
```
(guarded with `typeof`, since most such functions only exist on the one page that defines them).
See `employee/detail.js`'s `eedModalTitle()`/`setEedModalTitle()`/`refreshEedModalTitleLanguage()`
(depends on action + item type) and `setup/org-structure-sync.js`'s
`orgSyncRenderModalTitle()`/`orgSyncRefreshModalTitleLanguage()` (depends on entity type) for two
different real examples of this shape.

**Known, accepted gap — genuinely freeform/data-driven titles**: a handful of modal titles
interpolate data that isn't small tracked state but an entire fetched object passed in at open time
(`reports/index.js`'s `#reportsPreviewModalTitle`/`#payslipRosterModalTitle`/
`#cycleReportHistoryModalTitle`, `payroll/detail.js`'s `#reportPreviewModalTitle`/
`#reportHistoryModalTitle`, `reports/annual-summary.js`'s `#aisCellDetailModalTitle`) — these would
need their OWN "remember the whole open-context object" mechanism (not just a small state variable)
and weren't fixed in this round, given the disproportionate effort-to-benefit ratio for what's a
narrow edge case (actively switching language while one specific transient/contextual modal happens
to be open). If a real complaint comes in about one of these specifically, apply the SAME
remember-and-re-render pattern above, scoped to that one modal — don't build a generic solution for
all of them speculatively.

---

## T008 — Modal footers: one dismiss control set, never a duplicate

**Rule:** a modal gets exactly one way to dismiss it via the footer (a "Cancel" or "Close" button
carrying `data-bs-dismiss="modal"`) plus the header's own `.btn-close` (the × in the top-right
corner) — never a SEPARATE, redundant close-only button alongside an existing Cancel button in the
same footer, and never two stacked `.modal-footer` elements ("a 2-tier footer") in one modal.

**Enforced automatically, app-wide, for any FUTURE modal that forgets a footer entirely** — the
generic `show.bs.modal` handler in `app.js` (2026-08-30, a separate earlier fix — see that handler's
own comment) injects a `.modal-header`/`.modal-footer` with a dismiss button into any modal that's
missing one, but only when `$footer.find('[data-bs-dismiss="modal"]').length === 0` — so a modal
whose Cancel button already carries `data-bs-dismiss="modal"` (the required, standard convention,
see the audit below) is correctly left alone, never gets a second button injected on top.

**2026-08-30 audit result (T008's own deliverable)**: scanned every modal in the app — 75+ across
20 files, per `layout/modals.php`'s own consolidation history — for (a) 3+ elements carrying
`data-bs-dismiss="modal"` inside one modal (the actual duplicate-button shape) and (b) 2+ separate
`.modal-footer` divs inside one modal (the "2-tier footer" shape). **Zero violations of either found
anywhere in the app.** Every modal has exactly the expected 2 dismiss controls (header `.btn-close`
+ one footer Cancel/Close button), except `#systemModal` (a deliberately bare, dynamically-populated
generic shell reused across different contexts — correctly picked up and completed by the generic
JS injector above whenever it's actually shown). **No modals needed changing this round** — this
section documents the rule (the actual deliverable) and the audit method, so a future modal that
violates it can be caught the same way rather than needing a fresh full-codebase re-scan:
```bash
# Find any modal with 3+ dismiss controls (a real duplicate) or a genuine 2-tier footer -- adapt
# the file glob as needed. Counts of exactly 2 are correct (header X + one footer button) and
# should NOT be flagged.
```
(see this round's own audit script in git history / ask for it to be re-run — it walks each
`<div class="modal fade" ... id="...">` block via balanced-brace matching, since a plain grep/awk
line-range can't reliably find real modal boundaries in files with deeply nested markup.)

---

## DataTable default page length: 50 entries, app-wide

**Rule:** every DataTable in the app shows 50 rows per page by default, with a length-menu offering
50/100/250/500/1000/All — not DataTables' own built-in default of 10, and not some other
one-off number picked per table.

**NOT automatic — every table init must opt in explicitly.** Unlike T001/T002/T004/T007/T008 above,
this is not a delegated document-level handler; `app.js` (loaded on every page, before any
page-specific script) just declares 2 plain global variables your own `.DataTable({...})` call has
to reference:
```js
// public/js/app.js, line 1-2
const pageLength = 50;
const lengthMenu = [[50, 100, 250, 500, 1000, -1], [50, 100, 250, 500, 1000, "All"]];
```
**Every new table's own init must include:**
```js
$('#tb_whatever').DataTable({
    // ...
    pageLength: pageLength,
    lengthMenu: lengthMenu,
    language: getTableLang(), // unrelated existing requirement, shown for context
});
```
Leaving these 2 lines out is easy to miss — the table still works, just silently shows 10 rows and a
different length-menu than every other table in the app, with no error or visual cue that anything's
wrong.

**2026-08-30 full-codebase audit (this doc section's own deliverable)** — a fork read every
`.DataTable({...})` call site across all of `public/js/` (46 real table-init calls found across 21
files) and classified each one. Result: **25 already conforming**, **18 legitimate exceptions** (see
below), **6 real gaps, all fixed same-day**:
- `public/js/employee/list.js` — `tb_login_history_overview`, `tb_employee_recheck` (found first,
  by hand, before the fork ran)
- `public/js/employee/detail.js` — `tb_login_history` (`#tableLoginHistory`, the per-employee Login
  History tab — unrelated to CLAUDE.md's own note that this table is deliberately exempt from the
  *Excel column filter* convention; that exemption says nothing about `pageLength`)
- `public/js/payroll/detail.js` — `dtReportHistory` (`#tb_report_history`, per-run report download
  history modal), `tb_join_employees` (`#tb_join_employees`, the off-cycle "Join Employees" picker)
- `public/js/reports/index.js` — `dtCycleReportHistory` (`#tb_cycle_report_history`, the cycle-wide
  equivalent of `dtReportHistory` above — same report-download-history shape, same gap, fixed the
  same way)

**Legitimate exceptions — do NOT add `pageLength`/`lengthMenu` to these shapes:**
1. **`paging: false`** — the table shows every row at once, no pagination controls exist at all, so
   "entries per page" is meaningless. Confirmed examples: `reports/annual-summary.js`'s
   `#tb_annual_summary` (wide scrollX+fixedColumns, whole fiscal year in one glance),
   `payroll/detail.js`'s `tb_run_detail` (every employee in one run, bounded by run size),
   `tax-statutory.js`'s `tb_rate_history`/`tb_company_setting` (small bounded per-item/per-company
   lists), and every "review-and-select sync candidate" picker across the app (`employee-sync.js`'s
   `tb_sync_new`/`tb_sync_existing`, `org-structure-sync.js`'s `tb_org_sync_new`/
   `tb_org_sync_existing`, `holiday-sync.js`'s `tb_holiday_sync_new`/`tb_holiday_sync_existing`) —
   these are always a small, one-time reviewed batch, never an open-ended paginated list.
2. **`lengthChange: false` paired with a small hardcoded `pageLength` (usually 10)** — a genuinely
   small/fixed master-data list. Confirmed examples: Setup & Rules' 5 tables in `setup/setup-rules.js`
   (Shift/Holiday/Work Location/Leave Type/OT Rate) and Manual Entry's 3 tables in
   `manual-entry/index.js` (Attendance/Leave/Overtime, one per employee per period) — the admin will
   realistically never have enough rows to need a 2nd page, and offering a length-menu dropdown
   would just be UI clutter with nothing meaningful to switch between. If a table like this ever
   plausibly grows past ~1 page of real data, switch it to the standard `pageLength`/`lengthMenu`
   pair instead of leaving `lengthChange:false` as stale, no-longer-true scope-limiting.

**When auditing for gaps again in the future** (e.g. after adding a new table, or periodically):
grep every `.DataTable({...})` call site in `public/js/` and confirm each one falls into the
standard pair, OR carries an explicit `paging:false` / `lengthChange:false` marker matching one of
the 2 exception shapes above. A call site with NEITHER is a real gap, not a stylistic choice — fix
it the same way the 6 examples above were fixed.

---

## Adding a new standard to this file

Same shape as the two above: a short **Rule**/**Scope** statement, the actual snippet from
`input.js` (or wherever it lives), and a **why** section if the implementation choice isn't obvious
from reading the code alone (the datepicker-bug-class reasoning above is exactly that kind of note —
without it, a future reader might "simplify" the prototype override back down to a plain `input`
listener and reintroduce the exact gap it was built to close).
