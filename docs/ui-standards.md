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

## Page header: every top-level page gets `.page-header-card`

**Rule:** every top-level page (reached from the sidebar/breadcrumb, not a sub-tab within one) opens
with a `.page-header-card` — icon + title + one-sentence description — right after the breadcrumb,
before any filter/toolbar/content:
```html
<div class="page-header-card mb-4">
    <div class="page-header-card-icon"><i class="fa-solid fa-XXX"></i></div>
    <div class="page-header-card-body">
        <h5 class="page-header-card-title" data-i18n="XXX_title">Title</h5>
        <p class="page-header-card-desc" data-i18n="XXX_description">One-sentence description.</p>
    </div>
</div>
```
**NOT automatic** — every new top-level page's own view file must include this markup by hand (a
`data-i18n`'d icon+title+description, same convention every other page's own `.page-header-card`
already uses — see `app/views/payroll/index.php` for the pattern's own origin, 2026-08-21).

**Confirmed exceptions (do not add this to these)**: `app/views/error404.php` (not a content page),
`app/views/payslip-template/edit.php` (a canvas editor with its own bespoke topbar, same pattern
every other canvas-editor page in this app deliberately uses instead).

**2026-09-04 audit found one real, previously-missed gap**: `app/views/payroll/detail.php` — one of
the highest-traffic pages in the app — had no page header at all despite the rule's "apply to every
page header going forward" wording from 2026-08-21. Fixed by adding the standard component ABOVE the
page's own existing dynamic run-summary card (run name/status badge/action buttons stayed exactly as
they were, just gained a static identity header above them) — `#runDetailTabs` further down that same
page is a separate, deliberately-exempted component (its own bespoke tab-polish CSS) and was not
touched by this fix.

**When auditing for gaps again**: grep every top-level page's view file for `page-header-card` — a
page with neither this class nor a documented exception above is a real gap.

---

## Modal Cancel/Close button: `.btn-light`

**Rule:** a modal-footer button whose only job is to dismiss the modal WITHOUT saving (labelled
"Cancel" or "Close", carrying `data-bs-dismiss="modal"`, or — for an in-modal sub-view like a
Rate History "add version" form — a JS-driven "go back without saving" button with the same
semantic meaning) uses `class="btn btn-light"` (`px-4` optional, per-modal, purely a spacing choice
— not part of the rule). Never `.btn-outline-secondary` for this exact job.

**Why `.btn-light` and not `.btn-outline-secondary`**: **2026-09-04 audit found this app already had
`.btn-light` in wider real use for this exact job before the audit (27 instances) than
`.btn-outline-secondary` (28 instances, an almost-even split with no dominant "correct" pattern to
just copy from) — `.btn-light` was picked as the standard because it's visually lighter/quieter than
an outlined button, appropriately deferential to whichever Save/primary-action button sits next to it
in the same footer (`.btn-primary`, the brand-orange `#FF9900`), which an outlined button's stronger
border competes with more than a flat light-gray fill does.

**Do NOT apply this to `.btn-outline-secondary` buttons doing a DIFFERENT job** — this rule is scoped
specifically to "cancel/dismiss without saving." A button like `#btnCancelRequest` ("Cancel Request",
a real business action that cancels an in-progress REQUEST, not "close this dialog") or a Preview/Add
Row button that happens to also use `.btn-outline-secondary` styling is unrelated and untouched.

**2026-09-04 fix**: 19 exact `data-bs-dismiss="modal"` Cancel/Close buttons across
`app/views/layout/modals.php`, `app/views/payroll/detail.php`, `app/views/payroll/index.php`, and
`app/views/setup-rules/index.php` converted from `.btn-outline-secondary` to `.btn-light`, plus one
JS-driven "go back without saving" button (`#srCancelRateVersionBtn`, the Statutory Rate modal's Rate
History sub-view) converted the same way for the same semantic reason even though it doesn't
literally carry `data-bs-dismiss="modal"`.

**When auditing for gaps again**: `grep -rn 'btn-outline-secondary.*data-bs-dismiss="modal"'
app/views` should return nothing; any hit is a real gap, not a stylistic choice.

---

## Modal title: `.modal-title text-secondary`

**Rule:** a modal's own `.modal-title` element carries exactly `class="modal-title text-secondary"`
— no `fw-bold`, no other color/weight class — unless one of the 2 documented structural exceptions
below genuinely applies.

**2026-09-04 audit found 8+ different class combinations app-wide** with no single dominant pattern
(`text-secondary` alone: 50 uses at audit time; `fw-bold text-secondary`: 32; bare `modal-title`: 29;
several smaller variants) — user confirmed `text-secondary` alone as the canonical standard (the
plain-weight version was the single largest existing group, and reads calmer/more consistent with
this app's own general typography than a bold modal title fighting for attention against its own
body content).

**2 legitimate, preserved exceptions — do not flatten these into the plain style:**
1. **`.modal-title text-secondary mb-0`** — used whenever the title `<h5>`/`<h6>` sits inside its own
   wrapper `<div>` within `.modal-header` (almost always because it also contains an inline icon,
   e.g. `<i class="fa-solid fa-list-check me-1"></i><span>Title</span>`) — the `mb-0` removes the
   heading's default bottom margin, which would otherwise misalign the header's own flex layout.
   Confirmed real, consistent, and necessary across 9 real modals (e.g. `#approvalTimelineModalLabel`,
   `#manageLinesModalLabel`, `#joinEmployeesModalLabel`) — keep `mb-0` on any NEW modal that follows
   this same "title wrapped in a div with an inline icon" shape.
2. **`.modal-title text-secondary d-flex align-items-center gap-2`** — used exactly once
   (`#pedTypeModalLabel`, the Earning/Deduction Type modal, whose title carries a live
   Income/Deduction color badge next to the text) — the flex/gap classes are load-bearing for that
   badge's own inline alignment, not decorative. Keep this shape only where a modal title genuinely
   needs to lay out more than plain text next to itself.
3. **`.modal-title text-danger`** — used exactly once, for a modal whose whole context is a
   destructive/warning action — the color itself IS the information (this is a warning dialog), so
   flattening it to the plain `text-secondary` style would remove a real, meaningful signal. Keep
   `text-danger` (or another semantic color) for any future modal in a genuinely equivalent context —
   this is not "any modal that feels important," only ones where the color change itself communicates
   real risk/danger, same restraint CLAUDE.md's own UI Convention section already asks for elsewhere
   (semantic color is separate from decorative choice).

**2026-09-04 fix**: normalized ~46 instances across `app/views/layout/modals.php` and 8 other view
files (`dashboard.php`, `payroll/detail.php`, `payroll/index.php`, `reports/run-audit.php`,
`setup/announcements.php`, `setup-rules/index.php`, `employment-certificate/_modals_partial.php`,
`payslip-template/_modals_partial.php`) to the canonical class, preserving the 2 structural exceptions
and the 1 semantic-color exception above unchanged.

**When auditing for gaps again**: `grep -orn 'class="modal-title[^"]*"' app/views -r --include=*.php`
— every result should be exactly `modal-title text-secondary` (with an optional `mb-0` in the icon-div
shape, or the 2 documented one-off exceptions above) — anything else is a real gap.

---

## Form field labels: `.form-label` spacing/modifier baseline

**Rule:** every `<label class="form-label...">` carries a `mb-1` bottom-margin as its baseline —
`class="form-label mb-1"` for an ordinary field with no other styling need. A label that genuinely
needs a semantic/layout modifier (see the 4 preserved families below) keeps that modifier, in the
canonical order `form-label [fw-semibold] [small] [text-muted] [d-block|pt-1] mb-1`, but still ends
in `mb-1` — there is no longer a form-label anywhere in this app with `mb-0`/`mb-2`/`m-0`/no
bottom-margin class at all.

**2026-09-04 audit found ~21 different class combinations, 606 total instances, no dominant
convention** — `form-label` bare (255) and `form-label mb-0` (128) together were the plurality, but
neither was a real majority, and the remaining ~19 variants were mostly pure spacing drift
(`mb-2`/`m-0`/reordered `mb-1 small` vs `small mb-1`) with no semantic difference from each other.
User confirmed: retrofit the WHOLE app onto `mb-1` as the baseline, not just new pages going forward.

**4 genuine semantic/layout families, investigated case-by-case before flattening anything (not a
blind global regex) — preserved, not stripped, because removing them would be a real visible
regression, not just spacing cleanup:**
1. **`small`** (59 instances after normalization) — used for 2 real, distinct, legitimate contexts:
   compact `.station-filter` filter-bar labels (e.g. `reports/annual-summary.php`'s own Fiscal
   Year/Department/Team/Branch/Role filter row) and other intentionally-de-emphasized single fields
   inside a dense modal section. Both are genuine size reductions, not drift — kept as `small mb-1`.
2. **`small text-muted`** (18) — a label for an optional/secondary field where the muted color is
   itself doing real communicative work (e.g. `destination_saved_label`, `select_item_placeholder`) —
   kept as `small text-muted mb-1`.
3. **`fw-semibold small`** (12, unified from the previously-separate `fw-bold small` /
   `mb-0 fw-semibold small` / `small fw-semibold mb-1` variants — same real role, 3 different class
   strings from copy-paste drift between an older bespoke assign-modal and the newer T055 shared
   `#entityAssignModal`) — used specifically for CHECKBOX-GROUP COLUMN HEADERS (Department/Position/
   Team/Employee headers above a multi-select checkbox list), a genuinely different UI role from an
   ordinary field label (more like a mini section header) — kept, `fw-bold` normalized to
   `fw-semibold` for consistency since both were being used for the identical role.
4. **`d-block`** (15) and **`pt-1`** (2, kept separate from the `d-block` family — different real
   purpose) — `d-block` labels all wrap a nested `<span data-i18n="...">` and sit above a toggle
   switch/checkbox on the same row (`.form-label` is `display:inline-block` by default, which would
   let it sit awkwardly inline next to the switch instead of stacking above it) — genuinely
   load-bearing for layout, not decorative. `pt-1`'s 2 instances nudge a label down to vertically
   align with an adjacent taller control in the same row — also a real alignment fix, not drift. Both
   kept exactly as-is, with `mb-1` appended for the spacing baseline.

**2026-09-04 fix**: 606 instances across 25 view files retargeted (496 flattened to the plain
`form-label mb-1` baseline, 110 preserved into one of the 4 families above with `mb-1` appended/
normalized) — confirmed via before/after total-instance-count match (606 = 606, nothing lost or
duplicated) and a full `php -l` pass on all 25 touched files.

**When auditing for gaps again**: `grep -roh 'class="form-label[^"]*"' app/views --include=*.php |
sort | uniq -c | sort -rn` should show exactly 7 distinct strings (the plain baseline + the 4 families
above, some split by whether `text-muted`/`fw-semibold` is present) — any 8th variant, or any string
not ending in `mb-1`/`pt-1 mb-1`, is a real gap, not a stylistic choice.

---

## `.station-filter-label`: always paired with a leading `fa-solid fa-filter` icon

**Rule:** every `.station-filter`'s own label span gets a sibling icon immediately before it —
`<i class="fa-solid fa-filter me-1"></i><span class="station-filter-label" data-i18n="label_filter">Filter</span>`.
The icon lives in MARKUP as a sibling `<i>` tag, not baked into the `label_filter` i18n string
itself — matches this app's own established icon+label convention (every tab button already pairs
an `<i>` icon with its own `data-i18n` span the same way, e.g. `.setup-menu`/`.structure-menu`), not
a new pattern invented for this one component.

**Scope:** T066's own 2026-09-04 audit found all 32 `.station-filter-label` instances across the
whole app (`employee/list.php`, `employee/reports.php`, `reports/annual-summary.php`,
`reports/index.php`, `payroll/index.php`, `payroll/approval.php`, `payroll/detail.php`,
`manual-entry/index.php`, `notification/index.php`, `data-sync.php`, `document-approval.php`,
`run-audit.php`, `employee/login-history.php`, `payslip/requests.php`, `layout/modals.php`,
`employee/detail.php`) shared byte-identical markup with zero icon — a mechanical find/replace
across all 16 files applied the icon everywhere at once.

**When adding a new `.station-filter` going forward:** copy the icon+span pair verbatim from any
existing instance (e.g. `employee/list.php`'s own filter header) — do not add a bare
`<span class="station-filter-label">` without the icon.

---

## Adding a new standard to this file

Same shape as the two above: a short **Rule**/**Scope** statement, the actual snippet from
`input.js` (or wherever it lives), and a **why** section if the implementation choice isn't obvious
from reading the code alone (the datepicker-bug-class reasoning above is exactly that kind of note —
without it, a future reader might "simplify" the prototype override back down to a plain `input`
listener and reintroduce the exact gap it was built to close).
