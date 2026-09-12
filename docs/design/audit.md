# Design Audit — Round 1 (2026-09-12)

Methodology per rules.md §13 row 1: manual `grep`-based counts, no `check-design.php` yet (that's
round 2's job) — every number here is an approximate hit count, not pixel-perfect. §2–§10
citations are based on structural patterns already confirmed systemic during round 0 (verified
again here with fresh greps, not re-guessed) plus spot checks of representative files. No code was
touched this round — audit only, per §13's own "ห้าม: แก้โค้ด" for round 1.

32 pages/routes audited (31 "normal" pages + Employee Detail, which is fully audited here too even
though it's the round-3 pilot and therefore excluded from the round-4 ordering proposal at the end).
2 shared error/fallback views (`error404.php`, `permission.php`) are noted once, briefly, not
audited like a real page — they have no §2–§10-relevant structure beyond an icon+message card.
93 `<div class="modal` blocks found app-wide (54 in the shared `app/views/layout/modals.php`, 39
embedded in page views) — audited grouped by owning page, not one-by-one (see "Modals" section).

---

## Systemic violations (cited once here, referenced by tag per page below)

These are the same patterns round 0's conflict report already found — re-verified with fresh greps
for this audit, broken down per file this time.

- **[SC1] `.page-header-card`** (old "card+icon" header, §2 violation — replaced by `page-header.php`)
  — **31 files**: `reports/annual-summary.php`, `employee/list.php`, `employee/detail.php`,
  `payroll/detail.php`, `help/setup-guide.php`, `setup/audit-log.php`, `help/version.php`,
  `reports/run-audit.php`, `setup/tax-statutory.php`, `payroll/index.php`, `payroll/approval.php`,
  `employee/login-history.php`, `manual-entry/index.php`, `notification/index.php`,
  `payslip/requests.php`, `setup/document-approval.php`, `setup/data-sync.php`,
  `setup/announcements.php`, `reports/index.php`, `employee/reports.php`,
  `announcements/my-list.php`, `dashboard.php`, `employment-certificate/settings.php`,
  `employment-certificate/edit.php`, `payslip/settings.php`, `setup-rules/index.php`,
  `setup/payroll-configuration.php`, `setup/company-profile.php`, `setup/permissions.php`,
  `permission.php`, `employment-certificate/_list_partial.php`. Essentially every real page in the
  app — this is THE blocking prerequisite for round 4 (see prerequisites section).
- **[SC2] `.stat-card`/`.stat-card-icon`** (colored stat card w/ icon, §2 violation — replaced by
  `.stat`/`stat-card.php`) — **5 files**: `reports/annual-summary.php`, `payroll/detail.php`,
  `setup/document-approval.php`, `employee/reports.php`, `dashboard.php`.
- **[SC3] `.btn-circle-action`** (colored round row-action button, §4/§7 violation) — **14 JS
  files** (rendered client-side, not in view markup): `employee/list.js`, `employee/detail.js`,
  `setup/tax-statutory.js`, `payroll/detail.js`, `payroll/approval.js`, `payroll/index.js`,
  `setup/payslip-template.js`, `setup/payroll-configuration.js`,
  `setup/employment-certificate-template.js`, `setup/company-profile.js`,
  `setup/announcements.js`, `reports/index.js`, `setup/setup-rules.js`, `manual-entry/index.js`.
- **[SC4] `.btn-outline-{brand,primary,danger,success,info,warning,dark}`** (§4/§12.3 violation,
  all must become `.btn-outline-secondary`) — 145 total, worst offenders: `layout/modals.php` (34+4+2
  = 40, shared across many modals), `employee/detail.php` (22+2+3+1 = 28), `payroll/detail.php`
  (9+1+1+3 = 14), `setup-rules/index.php` (7), `setup/company-profile.php` (2+2 = 4), the ECT/PST
  editor partials (2+2+1+1 = ~6 each), the rest scattered 1–3 per file across ~20 more JS/view files.
  Full per-variant breakdown in the §12 totals section.
- **[SC5] `.DataTable({...})` / `.dataTable({...})` direct calls, not via `initSharedDataTable()`**
  (§7/§12.5 violation) — **75 hits across 31 JS files**; only `reports/annual-summary.js` (8 calls)
  and `payroll/detail.js` (10 of its calls) are already migrated (Batch 3C item 6 / Batch 5 item 5).
  Every other DataTable-using page (28 files' worth) still calls `.DataTable(`/`.dataTable(`
  directly.
- **[SC6] `Swal.fire(` outside `alert.js`/`app.js`** (§10/§12.6 violation) — **16 hits across 9
  files**: `employee/detail.js`(1), `payroll/index.js`(3), `session-guard.js`(1),
  `payroll/detail.js`(5), `setup/data-sync.js`(1), `setup/payroll-configuration.js`(1),
  `setup/setup-rules.js`(1), `setup/structure-assign.js`(1), `setup/tax-statutory.js`(2).
- **[SC7] `.toLocaleString(` in JS not delegating to the existing `fmtNum()` helper**
  (`public/js/format-helpers.js`, §8/§12.7 violation) — **60 hits across 10 files** (excludes
  `format-helpers.js`'s own 1 legitimate definition): `employee/detail.js`(10),
  `employee/reports.js`(30 — the single worst offender in the whole app), `payroll/index.js`(1),
  `dashboard.js`(6), `manual-entry/index.js`(1), `reports/annual-summary.js`(1),
  `reports/run-audit.js`(1), `setup/company-profile.js`(3), `setup/payroll-configuration.js`(4),
  `setup/setup-rules.js`(3). **Real finding**: rules.md §8/§11's `formatMoney()` does not need to be
  invented — `fmtNum()` already exists in `format-helpers.js` and is functionally identical
  (`toLocaleString` with 2 fixed decimals); round 2 should adopt/rename this existing helper (same
  "don't build a second one" principle as round 0's `showConfirm`/`isFormDirty` decisions), then
  round 4 migrates these 60 call sites onto it.
- **[SC8] `<span class="badge` with no `data-badge="status"` marker** (§5/§12.8 violation) — **184
  total** (21 in views + 163 in JS across 30 JS files, worst: `payroll/index.js`(18),
  `payroll/detail.js`(28), `setup/company-profile.js`(9), `employee/detail.js`(16),
  `employee/list.js`(13), `setup/tax-statutory.js`(12), `layout/modals.php`(10)). Every single one
  is technically a hit right now since `statusBadge()`/`statusBadgeHtml()` don't exist yet at all —
  this number will not usefully shrink until round 2 ships the shared helper.
- **[SC9] icons inside `.nav-link` (tab icons)** (§6/§12.4 violation) — **32 hits across 8 files**:
  `employee/list.php`(2), `employee/detail.php`(13 — 10 main tabs + 3 EED sub-pills),
  `employment-certificate/_editor_content.php`(2), `setup/document-approval.php`(3),
  `reports/annual-summary.php`(4), `payslip/requests.php`(3), `payslip-template/_editor_content.php`(2),
  `payslip/settings.php`(3). **Correction to a possible false assumption**: CLAUDE.md's own "Tab"
  section (2026-08-24) documents a pass that unified tab VISUAL STYLING
  (`.setup-tabs`/`.setup-menu`/`.structure-tabs`) — it did **not** remove icons. rules.md §6's
  "ไม่มีไอคอนใน tab ทุกหน้า" is a genuinely new requirement, not something already half-done.
- **[SC10] `fa-regular`** (§1 violation once `fa-solid` is picked as the system's one weight) — 33
  hits, thinly scattered across 18 files (max 5 in one file — `setup/setup-rules.js`,
  `payroll/detail.js`) — not concentrated, cheap to convert page-by-page in round 4.
- **[SC11] hex/rgb/hsl color literals outside `tokens.css`** (§12.1 — `tokens.css` doesn't exist yet,
  so this is currently "the entire app"): 941 hex + 200 rgb/rgba/hsl in `style.css` alone, +54 hex in
  views, +501 hex in JS ≈ **1,696+ color literals app-wide**. Not broken down per-page — this is a
  CSS-file-wide/scattered-JS problem, not a per-page one; it shrinks only as `tokens.css` +
  per-component overrides land in round 2, then as each page's own inline colors are migrated in
  round 4.
- **[SC12] inline `style="` in views/JS** (§12.2, before subtracting the 2 allowlisted exceptions —
  JS `display:none` toggles and table-column `width`) — **221 in views across 22 files** + **54 in
  JS across 18 files** ≈ 275 raw hits. Worst view offenders: `layout/modals.php`(32),
  `employee/detail.php`(44), `setup/payroll-configuration.php`(23), `payroll/index.php`(18),
  `setup-rules/index.php`(17), `setup/tax-statutory.php`(11), `payroll/approval.php`(11). A real
  count needs `check-design.php`'s own exemption logic (round 2) — this is the raw, unfiltered
  number.
- **`.station-filter`** (the app's own existing collapsible filter pattern) — **15 files** already
  use it: `reports/annual-summary.php`, `employee/list.php`, `employee/detail.php`,
  `setup/audit-log.php`, `reports/run-audit.php`, `payroll/index.php`, `payroll/approval.php`,
  `employee/login-history.php`, `manual-entry/index.php`, `notification/index.php`,
  `payslip/requests.php`, `setup/document-approval.php`, `setup/announcements.php`,
  `reports/index.php`, `employee/reports.php`. **Not itself a violation** the way SC1–SC12 are — it's
  the pre-existing convention §6's new `filter-bar.php` replaces. Gap vs. §6: none of these 15 show
  active filters as removable chips; they only have the collapse-toggle + a single "Clear Filter"
  button. This is the most mechanical migration in the whole audit (swap markup/init call, logic
  identical) once `filter-bar.php` exists.
- **Dead route, not a design issue, flagged for awareness only**: `setup/notification` →
  `NotificationController@index` — that method does not exist on `NotificationController`
  (confirmed by listing every `public function` on the class); hitting this route would 500. The
  real Notifications page is reached via `/notifications` → `NotificationController@page()` →
  `notification/index.php`, which works fine. Out of scope for a design-only round (rules.md §0.7 —
  logic bugs go to BACKLOG.md, not fixed here); noting it here since it surfaced during route-mapping
  for this audit.

---

## Pages

| # | Route | Size | Notable §-cited issues (beyond the systemic tags above) | Lint hits (own file, §12.1–8) |
|---|---|---|---|---|
| 1 | `/`, `/dashboard` | ใหญ่ | SC1, SC2, SC7(6). §2: welcome-style layout partially remains (quick links row, calendar widget) — needs re-check against §2's "งานที่ต้องทำก่อน" ordering once `page-header.php` exists. §3: dept headcount chart mixes brand orange with 2 other named accent colors — needs recheck against §3's "ส้ม 3 ที่เท่านั้น" once tokens exist. | style: n/a (own JS ~320 view lines) |
| 2 | `/employees` (Employee tab + `#employee-recheck-top-tab`) | ใหญ่ | SC1, SC3, SC5, SC6(0 own, uses shared), SC8, SC9(2), station-filter(✓, needs chip upgrade). §7: server-side DataTable, not yet on `initSharedDataTable()`. §4: multiple `.btn-outline-primary`/`.btn-outline-danger` row/bulk-action buttons. | .3=n (list.js), .4=2 |
| 3 | `/employees/login-history` | เล็ก | SC1. §7: `.DataTable(` direct call (1), deliberately `ordering:false` w/ its own top-level filter per CLAUDE.md's own documented exemption — still needs the `initSharedDataTable()` wrapper call per §7, exemption is about column filters not about which init function to call. | minimal |
| 4 | `/employees/reports` (multiple report sub-views in one page) | ใหญ่ | SC1, SC2, SC7 (**30** — the single worst `.toLocaleString` offender app-wide), SC10. §8: heaviest number-formatting cleanup target in the whole app. 931-line view, many stat/summary cards. | .7=30 |
| 5 | `/payroll-process` | ใหญ่ | SC1, SC3, SC5(3), SC6(3), SC7(1), SC8(18 badges), SC9(0), station-filter(✓). §4: process-state badges/buttons mix colors beyond the 4-tone system. Opens `payrollRunModal`/`bulkPullModal`/`cancelRunModal`/`runWorkflowModal` (see Modals). | .3, .5=3, .6=3, .7=1, .8=18 |
| 6 | `/payroll-process/{id}` | **ใหญ่มาก** | SC1, SC2, SC3, SC4(9+1+1+3=14), SC5(4 remaining direct + 10 already migrated — **partially done**), SC6(5), SC8(28 badges — worst in app), SC10(5). 1,876-line view, opens **17** page-local modals, has its own `stat-card` row. Biggest single non-Detail page in the app; the DataTable migration here is already half-done (Batch 3C item 6), a real precedent to reuse for round 4's own DataTable migrations elsewhere. | .3=14, .5=4, .6=5, .8=28 |
| 7 | `/payroll-approval` | เล็ก–กลาง | SC1, SC3, SC4(1), SC6(1), SC8(1), station-filter(✓). Opens `approveRunModal`/`rejectRunModal`/`requestInfoRunModal`/`approvalTimelineModal`. §9: those 3 confirm-style modals should collapse toward `showConfirm()`'s new object form (round 0 decision 5) rather than staying full custom modals — worth a design call in round 2/3, not decided here. | .4=1, .6=1, .8=1 |
| 8 | `/setup/company-profile` (Company Info, Branch/Role/Department/Position/Rank/Team sub-tabs, Permission Matrix sub-tab, Logo) | ใหญ่ | SC1, SC3, SC4(2+2=4), SC5(2), SC8(9), SC12(9 inline style). §6: `.structure-tabs`/`.structure-menu` pill sub-tabs are a SEPARATE, already-CSS'd pattern from `.nav-tabs` — rules.md §6 doesn't currently distinguish top-level tabs from this pill sub-tab convention; needs a decision in round 2 whether pills get the same "no icon" treatment (they already have none, per CLAUDE.md's own history) and same underline-selected treatment or keep their own gradient-pill look. Opens `structureAssignModal`, `orgStructureSyncModal`+`orgStructureSyncLogModal`, `cpSignaturePadModal`. | .3, .4=4, .5=2, .8=9, .12=9 |
| 9 | `/setup/payroll-configuration` (Cycle/Earnings/Deductions tabs) | ใหญ่ | SC1, SC3, SC5(3), SC6(1), SC7(4), SC8(18 badges), SC12(23 — 2nd worst inline-style offender). Opens the very large `itemModal`. | .5=3, .6=1, .7=4, .8=18, .12=23 |
| 10 | `/setup/announcements` (admin settings) | กลาง | SC1, SC4(1), SC5(1), SC8(2), station-filter(✓). | .4=1, .5=1, .8=2 |
| 11 | `/announcements` (my list, employee-facing) | เล็ก | SC1, SC8(2). | .8=2 |
| 12 | `/setup/tax-statutory` | ใหญ่ | SC1, SC3, SC4(2+1=3), SC5(1), SC6(2), SC8(12), SC12(11). Owns `statutoryRateModal` (365 lines, the single biggest modal block) + the newly-added `srAddVersionModal` (this session's own stacked-modal work). | .4=3, .5=1, .6=2, .8=12, .12=11 |
| 13 | `/setup/document-approval` (Approval Workflow / Document Numbering / Email Log, 3 tabs) | ใหญ่ | SC1, SC2, SC3, SC4(1 own view — `.btn-outline-brand` — +1 in `document-numbering.js`), SC8(1 own +1 in `email-queue-log.js`), SC9(3 — all 3 tab icons). | .4=1, .8=1, .9=3 |
| 14 | `/payslip-documents/requests` (Payslip Requests / Employment Certificate Requests / Delivery Log, 3 tabs) | ใหญ่ | SC1, SC3, SC4(0 own view, several in the 3 tab JS files), SC8(1 in `employment-certificate-request.js`, plus `payslip-request.js`), SC9(3 — all 3 tab icons). Shares the generic `requestDetailModal` timeline (Batch approval-request-detail work) across all 3 tabs. | .9=3 |
| 15 | `/payslip-documents/settings` (Payslip Template list / Distribution / Employment Certificate Template list, 3 tabs) | ใหญ่ | SC1, SC3, SC9(3 — all 3 tab icons). Embeds `employment-certificate/_list_partial.php` (has SC1 itself) + `payslip-template/_list_partial.php` as tab content. | .9=3 |
| 16 | *(dead route, see systemic note)* `setup/notification` | — | Not audited — 500s if hit, logic bug not design. | — |
| 17 | `/notifications` | กลาง | SC1(0 — check: not in SC1 list, i.e. this page may already lack the old header card), SC3(0), SC5(1 in `notifications.js`), SC8(2). | .5=1, .8=2 |
| 18 | `/setup-rules` (Shift/Holiday/Leave Type/OT Rate/Work Location, 5 tabs) | **ใหญ่มาก** | SC1, SC3, SC4(7 — all `.btn-outline-brand`), SC5(5), SC6(1), SC10(4+5=9), SC12(17 — 3rd worst). 667-line view, opens 7 page-local modals (attendance/leave/overtime/shift-assign etc. — cross-check against Manual Entry's own similarly-named modals, they may overlap/share ids, worth confirming in round 2). | .3, .4=7, .5=5, .6=1, .10=4, .12=17 |
| 19 | `/setup/permissions` (standalone route) | เล็ก | SC1. 57-line view — likely a thin wrapper; the FULL Permission Matrix UI lives as Company Profile's own "Permissions" sub-tab (`#8` above) per this project's own documented history. Round 2 should confirm whether this standalone route is still reachable/used, or is legacy — out of scope to resolve here (logic/routing question, not design). | minimal |
| 20 | `/payslip-template/edit/{key}` (canvas designer, standalone page) | ใหญ่ | SC9(2 — Design/Assign To tabs), SC10(1), SC4(2+1=3), SC12(6 in `payslip-template.js` +4 in `_editor_content.php`). **Deliberately excluded from tokens.css/component work per round 0's own scope note carried over from CLAUDE.md** (canvas PDF-designer pages are Tier B / light-only, separate concern) — still audited here for completeness since it IS a real page, but likely gets a DIFFERENT round-4 treatment (or deferred) rather than the standard component swap other pages get. Font: uses TH Sarabun New/DejaVu Sans **for the PDF canvas content only** — excluded from the font-finding below per the task's own instruction. | .4=3, .9=2, .10=1, .12=10 |
| 21 | `/employment-certificate/settings` | เล็ก | SC1. 27-line thin wrapper — real list UI is `_list_partial.php` (embedded in `/payslip-documents/settings`'s 3rd tab) rather than this route's own content; likely legacy standalone entry point (kept per its own documented history: "route เดิม...ยังทำงานเหมือนเดิม...แค่เอาออกจากเมนู"). | minimal |
| 22 | `/employment-certificate/edit/{key}` (canvas designer) | ใหญ่ | Same profile/caveat as #20 (Payslip Template editor) — SC4(2+1+1=4), SC9(2), SC10(2), SC12(4 view +6 JS). | .4=4, .9=2, .10=2, .12=10 |
| 23 | `/manual-entry` | ใหญ่ | SC1, SC5(5), SC7(1), SC9(0), SC10(1). Opens 7 modals (`attendanceModal`/`leaveModal`/`overtimeModal`/`manualEntryDeleteModal`/`bulkEntryModal`/`bulkImportModal`/`importBatchDetailModal`). | .5=5, .7=1, .10=1 |
| 24 | `/help/setup-guide` | เล็ก | SC1. Trivial checklist page. | minimal |
| 25 | `/help/version` | เล็ก | SC1. Trivial changelog page. | minimal |
| 26 | `/reports` (hub linking to every report generator) | กลาง | SC1, station-filter(✓). Mostly link-cards, low structural risk. | minimal |
| 27 | `/reports/annual-summary` (4 tabs — this session's own recent work) | ใหญ่ | SC1, SC2, SC7(1), SC9(4 — all 4 tabs), station-filter(✓ x4, one per tab). §7: **already migrated** to `initSharedDataTable()` (Batch 3C/5) — a real precedent for round 4's DataTable work elsewhere, same as `/payroll-process/{id}`. | .7=1, .9=4 |
| 28 | `/reports/run-audit` | กลาง | SC1, SC5(1), SC7(1), SC12(2). | .5=1, .7=1, .12=2 |
| 29 | `/audit-log` | เล็ก | SC1, SC5(1), SC8(1), station-filter(✓). Recently redone as a real DataTable per project history — still not on `initSharedDataTable()` yet though. | .5=1, .8=1 |
| 30 | `/setup/data-sync` (Sync/History tabs) | กลาง | SC1, SC4(1 view +1+1+1 in 3 JS widget files), SC5(2), SC8(2), SC12(4). | .4=1, .5=2, .8=2, .12=4 |
| **P** | **`/employees/create`, `/employees/{id}` (Employee Detail — round-3 pilot, audited fully, EXCLUDED from round-4 ordering)** | **ใหญ่มาก** | SC1, SC3, SC4(**28** — worst single-file outline-button offender: 22 brand+2 primary+3 danger+1 success), SC5(6), SC7(**10**), SC8(**16**), SC9(**13** — 10 main tabs + 3 sub-pills, worst tab-icon offender), SC12(**44** — worst inline-style offender in any view file). 2,657-line view, 11 top-level tabs, opens the single largest number of modals of any page (via `employeeQuickViewModal`, `eedModal`, `recurringEarningModal`, `recurringDeductionModal`, `empSignaturePadModal`, `empMapPinModal`, `employeeRecheckEditModal`, `probationSetModal`, `entityAssignModal`, and more — see Modals section). This is genuinely the single most violation-dense page in the app, consistent with it being picked as the round-3 pilot: fixing every rules.md rule at least once here is the highest-value single page to prove the ruleset against. | .3, .4=28, .5=6, .7=10, .8=16, .9=13, .12=44 |

*(error404.php / permission.php: trivial shared fallback views, 14/15 lines each, an icon + one message
+ optional back-link. §2's "โครงสร้างต้องสื่อข้อมูล" is already satisfied by their simplicity;
worth a 1-line pass in round 4 alongside whichever page most commonly triggers them, not a
standalone entry.)*

---

## Modals

93 `<div class="modal` blocks total. `app/views/layout/modals.php` alone holds **54** (53 distinct
`id`s were enumerated by name; the 54th grep hit is very likely a nested/nested-selector false
match inside one of the 53 blocks' own inner markup, not a 54th real modal — worth a byte-exact
recount once `check-design.php` exists in round 2, not chased further by hand here). A further 39
are page-embedded: `dashboard.php`(1), `employment-certificate/_modals_partial.php`(4),
`employment-certificate/_editor_content.php`(1), `payslip-template/_modals_partial.php`(4),
`payslip-template/_editor_content.php`(1), `reports/run-audit.php`(1), `setup/announcements.php`(1),
`setup/data-sync.php`(1), `payroll/index.php`(1), `setup-rules/index.php`(7),
`payroll/detail.php`(17).

Grouped by owning page (by id/context, not exhaustively re-derived per modal):

- **Employee-row modals** (opened from Employee List/Detail/Recheck rows): `employeeQuickViewModal`,
  `employeeSyncModal`+`employeeSyncLogModal`, `employeeRecheckEditModal`, `entityAssignModal`,
  `probationSetModal`, `eedModal`, `recurringEarningModal`, `recurringDeductionModal`,
  `empSignaturePadModal`, `empMapPinModal`, `bffFieldModal`+`bffLogModal`. §9 spot check: **10 of
  52** modal `<h5 class="modal-title">` blocks embed an icon inline in the title text (§9
  violation: "Header: ชื่ออย่างเดียว...ไม่มีธง/ไอคอน/badge") — a real, confirmed count, not every
  modal, but a substantial minority. Several of these already use `.emp-header-card` correctly
  (Batch 3C item 8) — a genuine partial-compliance precedent worth reusing as-is in round 2/3
  rather than rebuilding.
- **Payroll Run modals**: `payrollRunModal` (biggest form modal outside Tax & Statutory),
  `approveRunModal`/`rejectRunModal`/`requestInfoRunModal`/`cancelRunModal`/`runWorkflowModal`/
  `runErrorEmployeesModal`/`approvalTimelineModal` — all `data-footer="confirm"`/`data-footer="form"`
  marker-driven (a real, reusable existing convention worth carrying into `filter-bar.php`/modal
  partial work rather than treating as itself a violation).
  `aisCellDetailModal`/`aisAnnualDetailModal` (this session's own recent Annual Summary work).
- **Tax & Statutory**: `statutoryRateModal` (365 lines — biggest single modal in the app) +
  `srAddVersionModal` (this session's own stacked-modal addition).
  `attendanceDeductionAssignModal`/`attendanceDeductionRuleModal`.
- **Manual Entry**: `attendanceModal`/`leaveModal`/`overtimeModal`/`manualEntryDeleteModal`/
  `bulkEntryModal`/`bulkImportModal`/`importBatchDetailModal`.
- **Sync widgets** (reused across Company Profile/Employee List/Setup Rules): `orgStructureSyncModal`
  +`orgStructureSyncLogModal`, `pendingSyncViewModal`, `bulkPullModal`.
- **Requests/Approval**: `payslipRequestModal`, `ecrRequestModal`, `requestDetailModal` (the shared
  generic approval-timeline modal, Batch approval-request-detail work — a genuinely good existing
  "one modal, many callers" precedent, same spirit as §0's own "ซ้ำ=shared" principle already
  applied once here).
- **Header/global**: `systemModal`, `userSettingsModal`, `quickLinksCustomizeModal`, `termsModal`,
  `systemAccessHistoryModal` — reachable from every page via the navbar, not owned by any one route.
- **Setup**: `structureAssignModal`, `documentNumberingModal`, `payrollCycleModal`, `itemModal`
  (Payroll Configuration's earning/deduction type editor), `cpSignaturePadModal`.
- **Reports**: `payslipRosterModal`, `reportsPreviewModal`, `cycleReportHistoryModal`.

---

## Fonts & icons actually in use (live app UI — excludes the Payslip/ECT canvas PDF-designer's own
TH Sarabun New / DejaVu Sans font-family choices, which are a separate, already-flagged, out-of-scope
concern per round 0)

- **Base font**: `Sarabun` (Google Fonts, weights 300/400/700 loaded in `layout/header.php`) —
  **already matches rules.md §1's `--font-sans: "Sarabun"` exactly**. No conversion work needed
  here — confirmed, not a violation.
- **Icon set**: Font Awesome, overwhelmingly `fa-solid` — **1,176 `fa-solid` vs. 33 `fa-regular`**
  app-wide (0 `fa-light`/`fa-thin`, 2 `fa-brands` — flag/social-style icons, presumably harmless).
  Picking `fa-solid` as the system's one weight (§1) is cheap: the 33 `fa-regular` hits are thinly
  scattered across 18 files (max 5 in any one file), no page is `fa-regular`-heavy.
- **Other icon assets**: `public/flags/th.png`/`gb.png` (language switcher + several TH/EN
  language-tab UIs — Payslip Template/ECT editors, language toggle in the navbar) — not Font
  Awesome, a real separate icon asset class rules.md doesn't currently address (should it also be
  `currentColor`-consistent per §1? — flag images are inherently full-color/national-flag-colored,
  can't be recolored via `currentColor` — worth a explicit carve-out note in rules.md §1 in round 2,
  not decided here). No other custom SVG-as-UI-icon usage found beyond the bundled FA icon font and
  these 2 flag images.
- **Multi-color icon usage** (an icon combined with a `text-{tone}` utility class, i.e. NOT plain
  `currentColor`): confirmed 16 occurrences during round 0, not re-broken-down per page here — low
  volume, folds into each page's own §1/§3 cleanup in round 4 rather than needing its own tracking.

---

## Totals per §12 lint rule (app-wide)

| Rule | What | Total | Note |
|---|---|---|---|
| §12.1 | hex/rgb/hsl outside `tokens.css` | **~1,696+** | 941 hex + 200 rgb/rgba/hsl in `style.css`, +54 hex in views, +501 hex in JS. Effectively "the whole app" until `tokens.css` exists — see SC11. |
| §12.2 | inline `style="` (raw, before allowlist exemptions) | **275** | 221 in views (22 files) + 54 in JS (18 files) — see SC12. Real filtered count needs round 2's `check-design.php`. |
| §12.3 | forbidden classes (`btn-success/info/warning/light/dark`, `btn-outline-(?!secondary)`, `bg-/text-primary/info/success`, `rounded-circle`, `btn-circle`) | **145** (outline-* alone) **+ 14 files'** worth of `.btn-circle-action` (not a single count — it's JS-rendered per row, volume scales with row count at runtime, not with source lines) | `.btn-outline-brand`=77, `-primary`=21, `-danger`=23, `-success`=12, `-info`=6, `-warning`=2, `-dark`=1. `bg-primary`/`text-primary`/`rounded-circle` bare usage not separately re-counted this round — fold into round 2's script. |
| §12.4 | `<i class="fa` inside `.nav-link` | **32** | 8 files — see SC9. |
| §12.5 | `.DataTable(`/`.dataTable(` outside `initSharedDataTable` | **75** raw JS hits across 31 files, **2 files already migrated** (`reports/annual-summary.js`, `payroll/detail.js` partially) | See SC5. |
| §12.6 | `Swal.fire(` outside `app.js`/`alert.js` | **16** | 9 files — see SC6. |
| §12.7 | `number_format(` in views / `.toLocaleString(` in JS | **0** in views, **60** in JS (10 files) | `number_format()` genuinely never used in any view — good, this half of the rule is already clean app-wide. See SC7 re: the existing `fmtNum()` helper. |
| §12.8 | `<span class="badge` without `data-badge="status"` | **184** | 21 views + 163 JS (30 files) — see SC8. Will not shrink until round 2 ships `statusBadgeHtml()`. |

## Totals per §2–§10 structural rule (app-wide)

| Rule | Pattern | Pages affected |
|---|---|---|
| §2 | Old `.page-header-card` (card+icon header) | **31 of 32** pages audited |
| §2 | Old colored `.stat-card`+icon | **5** pages (`annual-summary`, `payroll/detail`, `document-approval`, `employee/reports`, `dashboard`) |
| §4/§7 | `.btn-circle-action` round row-action buttons | **14** JS files |
| §4 | Non-secondary `.btn-outline-*` | **~20+** files (views + JS combined, see §12.3 breakdown) |
| §6 | Icons inside tab `.nav-link` | **8** pages (32 individual tab instances) |
| §6 | `.station-filter` in use but missing §6's chip-based active-filter display | **15** pages |
| §7 | `.DataTable(` called directly, not via `initSharedDataTable()` | **~29** pages' worth of JS files (2 already done or partially done) |
| §8 | Ad-hoc `.toLocaleString(` instead of the existing `fmtNum()` | **10** files |
| §5 | `<span class="badge` not through a shared status-badge helper | **~32** files (views + JS) |
| §1 | `fa-regular` icon usage (needs conversion to `fa-solid` once that's picked) | **18** files, thin/scattered |

---

## Shared-component prerequisites — which pages are blocked by which §11 component

Every page needing round 4 work needs **at minimum** `page-header.php` (31 of 32 pages) — this is
the single highest-leverage component to ship first in round 2, full stop.

- **Blocked on `page-header.php`**: all 31 pages carrying SC1 (see the full list under SC1 above) —
  i.e., essentially every page in this audit except the already-thin `employment-certificate/settings.php`
  edge cases and the 2 trivial error/permission views.
- **Blocked on `.stat`/`stat-card.php`**: Dashboard, Payroll Detail, Document & Approval, Employee
  Reports, Annual Summary (5 pages, SC2).
- **Blocked on `filter-bar.php`**: the 15 `.station-filter` pages (mechanical swap once it exists,
  see the SC note — cheapest migration category in the whole audit).
- **Blocked on `initSharedDataTable()` gaining §7's "wrap `initExcelColumnFilters()` itself"
  behavior (round 0 decision 8) + its layout/export/fixed-column features**: the ~29 pages still on
  direct `.DataTable(` calls (SC5).
- **Blocked on `statusBadgeHtml()`/`status_map.php`**: every page with SC8 (~32 files) — this is the
  single largest lint-count blocker (184 hits) and needs round 0 decision 7's rename
  (`payroll-configuration.js`'s local `statusBadge(row)` → `pcRowStatusBadge(row)`) to land FIRST,
  before the global helper can even be declared safely.
- **Blocked on the extended `showConfirm()` object form (round 0 decision 5)**: any page currently
  hand-rolling a `Swal.fire(` confirm dialog outside `alert.js` (SC6, 9 files) — most visibly
  Payroll Approval's 3 dedicated confirm modals (`approveRunModal`/`rejectRunModal`/
  `requestInfoRunModal`), which are candidates to collapse into the new object-form `showConfirm()`
  rather than staying full custom modals (a design call for round 2/3, not made here).
- **Blocked on `isFormDirty()`/`confirmIfDirtyThen()` being wired from ONE place in `app.js` (round
  0 decision 6)**: every page with a form-carrying modal that doesn't already call these manually —
  not separately re-counted per page this round (would need reading each modal's own JS wiring);
  flag for round 2 to verify while building the app.js-wide wiring itself.
- **Blocked on `renderStatusStepper()`/`status-stepper.php`**: Payroll Detail and Payroll Approval
  specifically (the only 2 pages rendering the 5-step payroll lifecycle timeline via
  `runLifecycleSteps()`'s existing HTML-building half).
- **Not blocked on anything new — already-correct precedents to copy forward**: `initSharedDataTable()`
  itself (already exists, 2 pages already use it), `apvAvatarHtml()`/`apvPersonLineHtml()` (already
  used correctly per round 0), `resetModalTabs()` (already exists, used by Tax & Statutory),
  `.emp-header-card` (already exists and correctly used on several employee-row modals), the
  `data-footer="confirm"/"form"/"view"` marker convention on Payroll Run's own modals (a good
  existing pattern worth generalizing rather than replacing).

---

## Proposed round-4 page order

Employee Detail (the round-3 pilot) is excluded from this list per the task's own instruction — it's
handled before round 4 starts. Ordered by: (1) how many pages/other work depend on the SAME
components being proven first, (2) violation density (fix the worst offenders while the pattern is
freshest), (3) genuinely small/low-risk pages interspersed to keep round 4's own pace sane rather
than front-loading only the hardest pages.

1. **`/employees` (Employee List + Recheck tab)** — second-most representative page after the
   pilot itself (shares almost every component: page-header, stat-adjacent counts, DataTable,
   station-filter, badges, row actions); proves the components work on a second, independently-large
   page right after the pilot while the lessons from Employee Detail are freshest.
2. **`/payroll-process/{id}` (Payroll Detail)** — the single biggest remaining page by violation
   density (SC4=14, SC8=28) and already has DataTable migration half-done — finishing it validates
   the "partial migration" pattern round 4 will hit again elsewhere.
3. **`/payroll-process` (Payroll Process List)** — shares Payroll Detail's modals/vocabulary,
   natural pairing right after it.
4. **`/payroll-approval`** — small, and the `showConfirm()` object-form / stepper decisions from
   Payroll Detail/List directly inform how its 3 confirm modals should be redesigned.
5. **`/setup-rules`** — very high violation density (SC4=7, SC12=17, 7 modals), tests the component
   set against a very different page shape (5 unrelated tabs, not a single dataset).
6. **`/setup/tax-statutory`** — the app's single biggest modal (`statutoryRateModal`) plus this
   session's own recent stacked-modal work — good stress test for the modal-header/footer rules.
7. **`/setup/payroll-configuration`** — 2nd-worst inline-style offender (23), pairs naturally with
   Tax & Statutory as "Setup" pages.
8. **`/setup/company-profile`** — biggest structural page after Detail/Payroll Detail (many
   sub-tabs); also the one place the §6 "pill sub-tab vs top-level tab" open question (round-1
   finding above) needs resolving in practice.
9. **`/reports/annual-summary`** — already closest to compliant (DataTable done, station-filter
   everywhere) — cheap win, and a good template for the REMAINING report pages right after it.
10. **`/employees/reports`** — worst `.toLocaleString` offender (30) — do it once `fmtNum()`
    adoption (round 2) is proven on the smaller annual-summary page first.
11. **`/reports` (hub)**, **`/reports/run-audit`**, **`/audit-log`** — 3 small/medium report-adjacent
    pages, batched together since they share little structure worth doing far apart.
12. **`/manual-entry`** — 7 modals, moderate size, no single blocking dependency not already proven
    above.
13. **`/setup/document-approval`** — 3 tabs, moderate size.
14. **`/payslip-documents/requests`** and **`/payslip-documents/settings`** — paired (share the
    generic request-detail-timeline modal and several embedded partials).
15. **`/employees/login-history`**, **`/announcements`**, **`/setup/announcements`**,
    **`/setup/data-sync`**, **`/notifications`** — remaining small/medium pages, any order.
16. **`/help/setup-guide`**, **`/help/version`** — trivial, last (lowest violation density, no
    modals, no tables).
17. **`/payslip-template/edit/{key}`**, **`/employment-certificate/edit/{key}`** (canvas designers)
    — deliberately last/separate: these are the Tier-B, light-only, PDF-designer pages this
    project's own dark-mode work already carved out as a different tier — round 2/3 should decide
    explicitly whether they get the FULL component treatment or a lighter pass (not decided here).
18. **`/employment-certificate/settings`**, **`/setup/permissions`** (both thin, likely
    legacy/reduced-relevance standalone routes) — lowest priority, may not even need independent
    round-4 commits if their real content lives elsewhere (Payslip Settings tab / Company Profile's
    own Permissions sub-tab respectively).
