/**
 * 3e-3 round B1 measurement: Process Detail's Action History tab after the Timeline card list
 * (renderAuditHistoryTimelineRd()) was replaced with a real DataTable (#tb_run_audit_log,
 * initAuditLogTableRd(), detail.js).
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/o_history_dt.js <PHPSESSID> <fixtureRunToken>
 *
 * 17 cells, each its own context:
 *   c1  fixture run -- recordsTotal matches api/payroll-run.get's own audit_log, view_detail never shows
 *   c2  filter "การกระทำ" to one action -- visible rows match, label text matches auditActionLabel()
 *   c3  2 filters at once (การกระทำ + ผู้ทำ), then clear both -- back to the full count
 *   c4  filter down to zero -- the OTHER empty state (not no_history_yet), clearing brings rows back
 *   c5  a made-up action code -- fallback label + data-code, never the raw code as visible text
 *   c6  the note cell -- every row the same height regardless of note length, NO tooltip left
 *       (3e-3b round B1 -- no `data-full-note` attribute, no live bootstrap.Tooltip instance), every
 *       row carries exactly one view-detail button (fa-eye)
 *   c7  device/IP column -- fixture (both empty) renders '-', run 752's real rows show ip + filter
 *   c8  run 752 (read-only) -- paging: recordsTotal ~1571, page 1 length = pageLength, page 2 differs,
 *       default sort is performed_at DESC
 *   c9  th -> en -> th -- 6 headers / filter panel labels / empty-state / actor name / action label /
 *       badge all follow langData every time
 *   c10 dark + 430 -- header/row/tooltip colours read real --c-* tokens, no sideways scroll, no pane padding
 *   c11 nothing else broke -- the other 7 tabs still open, #tb_run_detail's own column filter still
 *       opens, #phTitleBadge still renders through stateBadgeRd()
 *   c12 3e-3b round B1: #auditLogDateFrom/To on run 752 -- a day with rows narrows recordsDisplay to
 *       exactly that day's count, a day with none -> 0 + the FILTERED empty state
 *   c13 from > to -- #auditLogDateRangeInvalidCallout shows, recordsDisplay stays put (not filtered);
 *       fixed -- callout hides, filtering resumes
 *   c14 #auditLogFilterBar chip/count/Clear progression (round B5, now that initFilterBar() is
 *       input-aware): 1 field set -> header (1) + 1 chip + Clear visible; 2nd field -> (2); chip's
 *       own x on one field -> back to (1), recordsDisplay follows; then a column filter + date +
 *       search all at once -> the shared Clear button resets everything (reads the component's own
 *       state, not just the visible icon)
 *   c15 #auditLogDetailModal -- a real click on a visible row's own view-detail button opens it with
 *       THAT row's data (dt.row($tr).data() wiring); longest-note / NULL-note / from!=to rows (found
 *       in run 752's own audit_log, NOTEd if any are missing) all render every field correctly;
 *       closing then opening a different row shows different content; never 2 `.modal.show` at once
 *   c16 filter bar shape (round B4 -- the SAME `.filter-bar` component #tb_run_detail's own
 *       #runDetailFilterBar uses, not a bespoke box): exactly 1 `.filter-bar` in this pane, its
 *       className set matches #runDetailFilterBar's own (same partial, same classes), header text
 *       follows langData, the toggle really collapses/expands it (no `aria-expanded` in the real
 *       markup -- checks the actual `.collapsed` class instead), no `<i>` inside a field's own label,
 *       every control ~30.5px, no `.station-filter`/`fieldset` anywhere · round B5: Clear button
 *       HIDDEN with nothing set, chip actually VISIBLE (not just present) once collapsed with a
 *       value set · th -> en -> th while the modal is open (title/filter label/close button all
 *       follow langData, modal stays open through the switch) · dark + 430: filter bar causes no
 *       sideways scroll, the datepicker popup opens and isn't stuck light, modal-lg doesn't overflow
 *       the viewport
 *   c17 round B5 regression check ("โหมดเดิมไม่แตก"): #runDetailFilterBar (Employee tab, 3 SELECT
 *       fields, 0 inputs) still gets a working (1)/chip/Clear-button/chip-x/Clear-button cycle after
 *       initFilterBar() gained its input branch -- every expected value read from the DOM itself
 *       (option text, field's own <label>, select's own default), never hardcoded
 *
 * Writes: none. This suite never clicks anything that would trigger a mutating call -- but round B2
 * stopped relying on that alone once cells started opening run 752 (real dev data, not a throwaway
 * fixture): every context now also passes `blockPaths: WRITE_PATHS` (same list
 * m3e1_tabs_shared.js's own WRITE_PATHS blocks, copied verbatim), on top of the harness's 2 built-in
 * blocks (api/user-preference.save, api/payroll-run.recalculate). Opening a run page itself writes
 * exactly one `view_detail` audit row per open (PayrollRunModel::logViewDetail(), called from the
 * Detail page route, not from this tab's own data endpoint) -- expected, unrelated to anything this
 * suite counts, and never returned by getAuditLog() in the first place (see that method's own
 * action != 'view_detail' filter).
 *
 * Run 752's token is never hardcoded here -- IdCodec::encode()'s own ciphertext is
 * non-deterministic (fresh IV per call), so a token string committed once would already differ from
 * one this same id encodes right now. tests/ui/id_codec_cli.php (PHP-only, IdCodec needs
 * EncryptionService/.env) is shelled out to for both directions: encode(id) to get a fresh, valid
 * token to navigate to, and decode(token) to prove that token really points at the id this cell's
 * own label says it does, before spending a browser context on it. (Round B1 also resolved a
 * RUN_1015_TOKEN this way, for a check that later moved to run 752 instead -- round B2 removed the
 * now-dead constant; nothing in this file opens run 1015 any more.)
 */
'use strict';
const { openContext, closeAll, applyAppTheme } = require('./harness');
const { execFileSync } = require('child_process');
const path = require('path');

const sessionId = process.argv[2];
const fixtureRunToken = process.argv[3];
if (!sessionId || !fixtureRunToken) {
    throw new Error('usage: node tests/ui/o_history_dt.js <PHPSESSID> <fixtureRunToken>');
}

// 2026-09-22, real bug found running this for the first time: `check()`/`measured()` are called
// from resolveRunToken() below at TOP-LEVEL script scope (module load time, to prove the 2
// IdCodec-encoded tokens before any cell spends a browser context on them) -- `let passed`/`let
// failed` therefore have to exist before that point too, not just before the cells that were
// always going to need them. Moved both declarations (and the 2 functions that close over them)
// up here, ahead of every top-level statement that calls either.
let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log('  PASS  ' + label); }
    else { failed++; console.log('  FAIL  ' + label + (extra !== undefined ? ' -- ' + extra : '')); }
}
function measured(label, value) {
    console.log('  MEASURED  ' + label + ' = ' + (typeof value === 'object' ? JSON.stringify(value) : value));
}
// Same 'NOTE' convention m3e1_tabs_shared.js's own searchThreshold gotcha (its c5) already prints --
// a cell that cannot prove anything on the run it was handed (row count under the threshold) says
// so and skips, counted in neither passed nor failed.
function note(msg) {
    console.log('  NOTE  ' + msg);
}

const RUN_752_ID = 752;   // real dev run, 1571 audit rows (3e-3 round A's own COUNT) -- read-only, never mutated

// 2026-09-22, round B2: run 752 is real dev data (not a throwaway fixture) -- every context below
// now passes this so a genuine write is aborted-and-counted rather than merely "never having been
// clicked", the same list m3e1_tabs_shared.js's own WRITE_PATHS already blocks with (copied
// verbatim, not reduced to only the routes this file's own cells currently reach -- a cell added
// later inherits the same protection without anyone having to remember to extend the list).
const WRITE_PATHS = [
    '/api/payroll-run-cash-payment.set-status',
    '/api/payroll-run-employee-bank-account.save',
    '/api/payroll-run-employee-bank-account.remove',
    '/api/payroll-remittance.mark-transferred',
    '/api/payroll-remittance.confirm-success',
    '/api/payroll-remittance.mark-failed',
    '/api/payroll-remittance.retry',
    '/api/report.generate',
    '/api/payroll-run.employee-verify.save',
    '/api/payroll-run.employee-verify.bulk',
    '/api/payroll-run.employee-verify.all',
];

const ID_CODEC_CLI = path.join(__dirname, 'id_codec_cli.php');
function idCodecEncode(id) {
    return execFileSync('php', [ID_CODEC_CLI, 'encode', String(id)], { encoding: 'utf8' }).trim();
}
function idCodecDecode(token) {
    const out = execFileSync('php', [ID_CODEC_CLI, 'decode', token], { encoding: 'utf8' }).trim();
    return out === 'none' ? null : parseInt(out, 10);
}
/** Every token this suite navigates to is decoded back here first -- a cell that opens the wrong
 *  run (a stale argv, a copy/paste slip) measures the wrong thing while still printing the right
 *  label, which is exactly what a decode-and-compare guard catches before any assertion runs. */
function resolveRunToken(label, token, expectedId) {
    const decoded = idCodecDecode(token);
    measured('IdCodec decode: ' + label, { token: token, decoded: decoded, expected: expectedId });
    check('IdCodec: "' + label + '" token really decodes to ' + expectedId, decoded === expectedId, String(decoded));
    return token;
}

const RUN_752_TOKEN = resolveRunToken('run 752', idCodecEncode(RUN_752_ID), RUN_752_ID);
const fixtureId = idCodecDecode(fixtureRunToken);
measured('IdCodec decode: fixture run (argv)', { token: fixtureRunToken, decoded: fixtureId });

async function gotoRun(ctx, token, lang) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    if (lang) {
        await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
        await ctx.page.waitForTimeout(500);
    }
}
/** Unlike the 4 other tabs (openTab() in m3e1_tabs_shared.js, which can legitimately be hidden),
 *  Action History is always shown -- this just clicks it and waits for #tb_run_audit_log to exist
 *  as a real DataTable (constructed unconditionally inside loadRunDetail(), same call site as
 *  before, whether or not this tab happens to be the one on screen at that moment). */
async function openHistoryTab(page) {
    await page.click('#run-history-tab');
    await page.waitForFunction(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_audit_log'), { timeout: 10000 });
    await page.waitForTimeout(300);
}
// 2026-09-23, round B5, c17 -- the Employee tab, home of #runDetailFilterBar (the select-only bar
// this round's own regression check is about).
async function openEmployeeTab(page) {
    await page.click('#run-employee-tab');
    await page.waitForFunction(() => window.jQuery && jQuery.fn.dataTable.isDataTable('#tb_run_detail'), { timeout: 10000 });
    await page.waitForTimeout(300);
}
// Same shape as auditLogFilterBarState() above, just pointed at #runDetailFilterBar -- kept as its
// own function rather than parameterizing that one, since c17's whole point is a bar this round's
// code changes were never supposed to touch, not a shared code path with the audit-log one.
function runDetailFilterBarState(page) {
    return page.evaluate(() => {
        const bar = document.getElementById('runDetailFilterBar');
        const countWrap = bar.querySelector('.filter-bar-count-wrap');
        const count = bar.querySelector('.filter-bar-count');
        const clearBtn = bar.querySelector('.filter-bar-clear');
        const chips = Array.from(bar.querySelectorAll('.filter-bar-chip')).map((c) => ({
            target: c.getAttribute('data-target'),
            label: ((c.querySelector('.filter-bar-chip-label') || {}).textContent || '').trim(),
            value: ((c.querySelector('.filter-bar-chip-value') || {}).textContent || '').trim(),
        }));
        return {
            countVisible: countWrap ? !countWrap.classList.contains('d-none') : false,
            count: count ? count.textContent.trim() : null,
            clearBtnVisible: clearBtn ? !clearBtn.classList.contains('d-none') : false,
            chips,
        };
    });
}
function historyState(page) {
    return page.evaluate(() => {
        const dt = jQuery('#tb_run_audit_log').DataTable();
        const info = dt.page.info();
        const bodyRows = Array.from(document.querySelectorAll('#tb_run_audit_log tbody tr'));
        const emptyRow = document.querySelector('#tb_run_audit_log tbody tr.dt-empty-row');
        return {
            recordsTotal: info.recordsTotal,
            recordsDisplay: info.recordsDisplay,
            page: info.page,
            pages: info.pages,
            length: info.length,
            bodyRowCount: bodyRows.filter((tr) => tr !== emptyRow).length,
            emptyRowTitle: emptyRow ? (emptyRow.querySelector('.empty-state-title') || {}).textContent : null,
            headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
            firstRowTime: dt.rows({ page: 'current' }).data().length ? dt.rows({ page: 'current' }).data()[0].performed_at : null,
        };
    });
}
/** 2026-09-22, 3e-3 round B2: whether #tb_run_audit_log's OWN filtering engine (search box AND
 *  every $.fn.dataTable.ext.search predicate, including the column filter this whole file spent
 *  cells c2-c4/c7 proving) is even switched on right now -- initSharedDataTable()'s own
 *  `searchThreshold` (app.js, default 10 when a caller doesn't override it) sets `searching: rowCount
 *  > searchThreshold` at CONSTRUCTION time, so a table at or under that row count never runs a
 *  filter predicate at all, no matter how correctly one is wired up (the real bug B1's own
 *  measurement round hit on the 9-row fixture, before c2-c4/c7 were pointed at run 752 instead).
 *  Reads the table's own LIVE `oFeatures.bFilter` flag rather than importing/duplicating the
 *  `searchThreshold` NUMBER itself -- that local const has no exported reference this file could
 *  read anyway, and the flag is the authoritative, already-applied answer to the exact question a
 *  cell actually needs answered, same signal m3e1_tabs_shared.js's own c4 already reads this way. */
function searchingEnabled(page) {
    return page.evaluate(() => !!jQuery('#tb_run_audit_log').DataTable().settings()[0].oFeatures.bFilter);
}
/** Excel-style column filter (table-column-filter.js) -- opens the panel for `key`, unchecks
 *  "select all", checks only the boxes whose raw value is in `wantedValues`, applies. Checkbox
 *  `.val()` is read via the DOM `value` property (Playwright's own `inputValue()` refuses
 *  checkbox/radio inputs). */
async function applyColumnFilter(page, key, wantedValues) {
    await page.click(`.tcf-filter-btn[data-tcf-key="${key}"]`);
    await page.waitForSelector('.tcf-panel:not(.d-none) .tcf-list .tcf-item', { timeout: 5000 });
    const selectAll = await page.$('.tcf-select-all');
    if (selectAll && (await selectAll.isChecked())) await selectAll.click();
    const boxes = await page.$$('.tcf-value-cb');
    for (const box of boxes) {
        const val = await box.evaluate((el) => el.value);
        const want = wantedValues.indexOf(val) !== -1;
        if ((await box.isChecked()) !== want) await box.click();
    }
    await page.click('.tcf-apply-btn');
    await page.waitForTimeout(300);
}
async function clearColumnFilter(page, key) {
    await page.click(`.tcf-filter-btn[data-tcf-key="${key}"]`);
    await page.waitForSelector('.tcf-panel:not(.d-none)', { timeout: 5000 });
    await page.click('.tcf-clear-btn');
    await page.waitForTimeout(300);
}
/** 3e-3b round B1 helpers -- the date-range filter bar above #tb_run_audit_log. */
function toDisplayDateFromIso(iso) {
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}
// Sets the field the SAME way this codebase's own tests already set a bootstrap-datepicker input
// (k4c_employee_detail.js's own `.val(...).datepicker('update')`), plus an explicit `trigger('change')`
// -- a plain `.val()` fires no DOM event on its own, and detail.js's own filter listens on `change`
// (same convention this file's OWN #reportHistoryDateFrom/To already uses, not `changeDate`).
async function setAuditLogDate(page, id, displayValue) {
    await page.evaluate(({ id, displayValue }) => {
        $('#' + id).val(displayValue).datepicker('update').trigger('change');
    }, { id, displayValue });
    await page.waitForTimeout(200);
}
// 2026-09-23, round B5: the shared `.filter-bar-clear` button (#auditLogFilterBar), not a page-local
// button -- it's `d-none` until a date field has a value (initFilterBar()'s own input-aware
// `refresh()`, app.js), same as every caller of this helper already ensures before calling it.
async function clearAuditLogDateFilter(page) {
    await page.click('#auditLogFilterBar .filter-bar-clear');
    await page.waitForTimeout(300);
}
// 2026-09-23, round B5: #auditLogFilterBar's own header count/chips/Clear-button visibility --
// all driven by app.js's now-input-aware refresh() (initFilterBar()), nothing page-local left to
// read instead. Chips exist in the DOM regardless of collapse state (only `.filter-bar-chips`
// itself is CSS-hidden while expanded, filter-bar.php) -- this reads their content directly rather
// than through `offsetWidth`/`getClientRects()`, so a caller checking chip TEXT doesn't also have
// to first force the bar collapsed; a caller checking chip VISIBILITY (c16) reads `collapsed`
// alongside and combines the two itself.
function auditLogFilterBarState(page) {
    return page.evaluate(() => {
        const bar = document.getElementById('auditLogFilterBar');
        const countWrap = bar.querySelector('.filter-bar-count-wrap');
        const count = bar.querySelector('.filter-bar-count');
        const clearBtn = bar.querySelector('.filter-bar-clear');
        const chips = Array.from(bar.querySelectorAll('.filter-bar-chip')).map((c) => ({
            target: c.getAttribute('data-target'),
            label: ((c.querySelector('.filter-bar-chip-label') || {}).textContent || '').trim(),
            value: ((c.querySelector('.filter-bar-chip-value') || {}).textContent || '').trim(),
        }));
        return {
            collapsed: bar.classList.contains('collapsed'),
            countVisible: countWrap ? !countWrap.classList.contains('d-none') : false,
            count: count ? count.textContent.trim() : null,
            clearBtnVisible: clearBtn ? !clearBtn.classList.contains('d-none') : false,
            chips,
        };
    });
}
function capturePayrollGetPayloads(page) {
    const payloads = [];
    page.on('response', async (res) => {
        if (res.url().indexOf('payroll-run.get') === -1) return;
        try { payloads.push(await res.json()); } catch (e) { /* not json */ }
    });
    return payloads;
}
function lastAuditLog(payloads) {
    const last = payloads[payloads.length - 1];
    return (last && last.data && Array.isArray(last.data.audit_log)) ? last.data.audit_log : null;
}

/* ---------------- c1 ---------------- */
async function c1() {
    console.log('\n[c1] fixture run, 1400 th light -- recordsTotal matches audit_log, view_detail never shows');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads = capturePayrollGetPayloads(ctx.page);
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const state = await historyState(ctx.page);
    const logs = lastAuditLog(payloads);
    measured('c1 state', state);
    measured('c1 audit_log.length (server)', logs ? logs.length : null);
    if (logs) check('c1: recordsTotal === audit_log.length', state.recordsTotal === logs.length, state.recordsTotal + ' vs ' + logs.length);
    const actions = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().rows().data().toArray().map((r) => r.action));
    check('c1: no view_detail row in the table\'s own data', actions.indexOf('view_detail') === -1, actions.join(','));
    const rep = ctx.report();
    measured('c1 report', rep);
    check('c1: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c2 ---------------- */
async function c2() {
    console.log('\n[c2] filter "การกระทำ" to one action -- visible rows match, label matches auditActionLabel()');
    // 2026-09-22, real bug found running this cell: initSharedDataTable()'s own `searchThreshold`
    // (app.js, default 10) switches OFF DataTables' whole filtering engine -- search box AND every
    // $.fn.dataTable.ext.search predicate, including this column filter's own -- for a table at or
    // under that row count. The fixture run has 9 rows (round A's own COUNT), always under it, so a
    // filter cell needs a run past the threshold to prove anything: run 752 (1571 rows, confirmed
    // recalculate=1263 of them per round A's own per-run action breakdown).
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads = capturePayrollGetPayloads(ctx.page);
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    if (!(await searchingEnabled(ctx.page))) {
        note('c2 skipped -- filtering is OFF on run 752 (rowCount <= searchThreshold, unexpected for a 1571-row run)');
        await closeAll();
        return;
    }
    const logs = lastAuditLog(payloads) || [];
    const wantAction = 'recalculate';
    const expectedCount = logs.filter((l) => l.action === wantAction).length;
    const wantLabel = await ctx.page.evaluate((a) => auditActionLabel(a), wantAction);
    await applyColumnFilter(ctx.page, 'audit_action', [wantLabel]);
    const state = await historyState(ctx.page);
    measured('c2 state', Object.assign({ wantAction, wantLabel, expectedCount }, state));
    check('c2: filtered recordsDisplay matches audit_log rows with this action',
        state.recordsDisplay === expectedCount, state.recordsDisplay + ' vs ' + expectedCount);
    const cellTexts = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(3)', (tds) => tds.map((td) => td.textContent.trim()));
    check('c2: every visible row shows the same label text', cellTexts.every((t) => t === wantLabel), JSON.stringify(cellTexts));
    const rep = ctx.report();
    measured('c2 report', rep);
    check('c2: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c3 ---------------- */
async function c3() {
    console.log('\n[c3] 2 filters at once (การกระทำ + ผู้ทำ), then clear both -- back to the full count');
    // Same searchThreshold reason as c2 -- run 752, not the 9-row fixture.
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads = capturePayrollGetPayloads(ctx.page);
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    if (!(await searchingEnabled(ctx.page))) {
        note('c3 skipped -- filtering is OFF on run 752 (rowCount <= searchThreshold, unexpected for a 1571-row run)');
        await closeAll();
        return;
    }
    const logs = lastAuditLog(payloads) || [];
    const fullCount = logs.length;
    const wantAction = 'recalculate';
    const performerId = (logs.find((l) => l.action === wantAction) || {}).performed_by;
    const wantActionLabel = await ctx.page.evaluate((a) => auditActionLabel(a), wantAction);
    const wantActorName = await ctx.page.evaluate((row) => personDisplayNameRd(row, 'performed_by'),
        logs.find((l) => l.performed_by === performerId) || {});
    await applyColumnFilter(ctx.page, 'audit_action', [wantActionLabel]);
    await applyColumnFilter(ctx.page, 'audit_performed_by', [wantActorName]);
    const expectedBoth = logs.filter((l) => l.action === wantAction && l.performed_by === performerId).length;
    let state = await historyState(ctx.page);
    measured('c3 state (2 filters)', Object.assign({ expectedBoth }, state));
    check('c3: 2 filters together match the same subset', state.recordsDisplay === expectedBoth, state.recordsDisplay + ' vs ' + expectedBoth);
    await clearColumnFilter(ctx.page, 'audit_action');
    await clearColumnFilter(ctx.page, 'audit_performed_by');
    state = await historyState(ctx.page);
    measured('c3 state (cleared)', state);
    check('c3: clearing both filters restores the full count', state.recordsDisplay === fullCount, state.recordsDisplay + ' vs ' + fullCount);
    const rep = ctx.report();
    measured('c3 report', rep);
    check('c3: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c4 ---------------- */
async function c4() {
    console.log('\n[c4] filter down to zero -- the OTHER empty state (not no_history_yet), then clear');
    // Same searchThreshold reason as c2 -- run 752, not the 9-row fixture.
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    if (!(await searchingEnabled(ctx.page))) {
        note('c4 skipped -- filtering is OFF on run 752 (rowCount <= searchThreshold, unexpected for a 1571-row run)');
        await closeAll();
        return;
    }
    const noRowsTitle = await ctx.page.evaluate(() => getLangValue('no_history_yet') || '');
    const zeroFilteredTitle = await ctx.page.evaluate(() => getLangValue('zeroRecords') || '');
    await applyColumnFilter(ctx.page, 'audit_action', ['__no_such_action_label__']);
    const state = await historyState(ctx.page);
    measured('c4 state (filtered to zero)', Object.assign({ noRowsTitle, zeroFilteredTitle }, state));
    check('c4: recordsDisplay is 0', state.recordsDisplay === 0, state.recordsDisplay);
    check('c4: shows the FILTERED empty state, not "no history yet"',
        state.emptyRowTitle === zeroFilteredTitle && state.emptyRowTitle !== noRowsTitle, state.emptyRowTitle);
    await clearColumnFilter(ctx.page, 'audit_action');
    const state2 = await historyState(ctx.page);
    measured('c4 state (cleared)', state2);
    check('c4: clearing brings rows back', state2.recordsDisplay > 0, state2.recordsDisplay);
    const rep = ctx.report();
    measured('c4 report', rep);
    check('c4: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c5 ---------------- */
async function c5() {
    console.log('\n[c5] a made-up action code -- fallback label + data-code, never the raw code as text');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const FAKE_ACTION = '__zz_test';
    const result = await ctx.page.evaluate((fakeAction) => {
        const fakeRow = {
            id: 999999999, run_id: 0, from_state: 'draft', to_state: 'draft', action: fakeAction,
            note: null, performed_by: null, ip_address: null, user_agent: null,
            performed_at: '2026-01-01 00:00:00',
            performed_by_name_th: null, performed_by_name_en: null, performed_by_profile_photo_path: null,
        };
        initAuditLogTableRd([fakeRow]);
        const cell = document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)');
        const codeEl = cell ? cell.querySelector('[data-code]') : null;
        return {
            cellText: cell ? cell.textContent.trim() : null,
            dataCode: codeEl ? codeEl.getAttribute('data-code') : null,
            fallbackLabel: getLangValue('action_unknown') || '',
        };
    }, FAKE_ACTION);
    measured('c5 result', result);
    check('c5: cell shows the shared fallback label', result.cellText === result.fallbackLabel, result.cellText);
    check('c5: raw code is NOT the visible text', result.cellText !== FAKE_ACTION, result.cellText);
    check('c5: raw code is recoverable via data-code', result.dataCode === FAKE_ACTION, result.dataCode);
    const rep = ctx.report();
    measured('c5 report', rep);
    check('c5: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c6 ---------------- */
async function c6() {
    console.log('\n[c6] note cell -- equal row heights, NO tooltip left (3e-3b), 1 view-detail button per row');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const picture = await ctx.page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('#tb_run_audit_log tbody tr')).filter((tr) => !tr.classList.contains('dt-empty-row'));
        const heights = rows.map((tr) => Math.round(tr.getBoundingClientRect().height));
        const noteCells = Array.from(document.querySelectorAll('#tb_run_audit_log tbody .rd-audit-note-cell'));
        const withFullNoteAttr = noteCells.filter((el) => el.hasAttribute('data-full-note'));
        const withLiveTooltip = noteCells.filter((el) => window.bootstrap && bootstrap.Tooltip.getInstance(el));
        const viewBtns = Array.from(document.querySelectorAll('#tb_run_audit_log tbody .audit-log-view-detail-btn'));
        const viewBtnsWithEye = viewBtns.filter((b) => b.querySelector('i.fa-eye'));
        const emptyNoteRows = rows.length - noteCells.length; // rows whose td had a falsy note (rendered '')
        return {
            rowCount: rows.length, heights, maxDelta: heights.length ? Math.max(...heights) - Math.min(...heights) : 0,
            noteCellCount: noteCells.length, withFullNoteAttrCount: withFullNoteAttr.length, withLiveTooltipCount: withLiveTooltip.length,
            viewBtnCount: viewBtns.length, viewBtnsWithEyeCount: viewBtnsWithEye.length,
            emptyNoteRows,
        };
    });
    measured('c6 picture', picture);
    check('c6: every row the same height (within 1px)', picture.maxDelta <= 1, picture.maxDelta);
    check('c6: no data-full-note attribute left on any note cell', picture.withFullNoteAttrCount === 0, picture.withFullNoteAttrCount);
    check('c6: no live tooltip instance on any note cell', picture.withLiveTooltipCount === 0, picture.withLiveTooltipCount);
    check('c6: every row has exactly one view-detail button', picture.viewBtnCount === picture.rowCount, picture.viewBtnCount + ' vs ' + picture.rowCount);
    check('c6: every view-detail button shows fa-eye', picture.viewBtnsWithEyeCount === picture.viewBtnCount, picture.viewBtnsWithEyeCount + ' vs ' + picture.viewBtnCount);
    const rep = ctx.report();
    measured('c6 report', rep);
    check('c6: no console/page error from a NULL note (' + picture.emptyNoteRows + ' rows on this page had one)',
        rep.consoleErrors.length === 0 && rep.pageErrors.length === 0, JSON.stringify(rep.consoleErrors.concat(rep.pageErrors)));
    check('c6: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c7 ---------------- */
async function c7() {
    console.log('\n[c7] device/IP -- fixture (both empty) renders "-", run 752\'s real rows show ip + filter');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const fixtureCells = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(6)', (tds) => tds.map((td) => td.textContent.trim()));
    measured('c7 fixture device/ip cells', fixtureCells);
    check('c7: fixture rows (no ip/ua, CLI-written) all render "-"', fixtureCells.length > 0 && fixtureCells.every((t) => t === '-'), JSON.stringify(fixtureCells));
    await closeAll();

    // 2026-09-22, real gap found running this cell against run 1015: EVERY one of its 16 audit rows
    // was written from a CLI-driven fixture path (tests/ui/mksession.php's own state machine, not a
    // real browser action), so ip_address is NULL on all of them -- there is genuinely no ip to
    // filter by there. Run 752 (1566/1571 non-view_detail rows carry '::1', round A's own COUNT)
    // does, and it clears the searchThreshold the same way c2-c4 needed it to.
    const ctx2 = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads2 = capturePayrollGetPayloads(ctx2.page);
    await gotoRun(ctx2, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx2.page);
    if (!(await searchingEnabled(ctx2.page))) {
        note('c7 (ip-filter half) skipped -- filtering is OFF on run 752 (rowCount <= searchThreshold, unexpected for a 1571-row run)');
        const rep0 = ctx2.report();
        measured('c7 report', rep0);
        check('c7: nothing was written', rep0.blockedWrites === 0, rep0.blockedWritePaths.join(','));
        await closeAll();
        return;
    }
    const logs = lastAuditLog(payloads2) || [];
    const withIp = logs.filter((l) => l.ip_address);
    const realCells = await ctx2.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(6)', (tds) => tds.map((td) => td.textContent.trim()));
    measured('c7 run 752 device/ip cells', { withIpCount: withIp.length, sample: realCells.slice(0, 3) });
    if (withIp.length) {
        const distinctIps = Array.from(new Set(withIp.map((l) => l.ip_address)));
        measured('c7 distinct real ip values in run 752', distinctIps);
        const ip = distinctIps[0];
        const expected = logs.filter((l) => l.ip_address === ip).length;
        if (distinctIps.length > 1) {
            // A real, meaningful narrowing test -- more than one value on offer, so picking just
            // one genuinely excludes the rest.
            await applyColumnFilter(ctx2.page, 'audit_device_ip', [ip]);
            const state = await historyState(ctx2.page);
            measured('c7 filtered by ip=' + ip, state);
            check('c7: filtering by an ip matches the rows carrying it', state.recordsDisplay === expected, state.recordsDisplay + ' vs ' + expected);
        } else {
            // 2026-09-22, real finding (not a bug): run 752 carries exactly ONE distinct non-empty
            // ip ('::1', round A's own COUNT -- NULL rows have no checkbox at all, filtered out of
            // uniqueClientValues() by its own `text !== ''` guard). Checking that single available
            // box is therefore indistinguishable from "select all" under this component's own
            // documented Excel semantics (table-column-filter.js's own apply handler: "every
            // visible+checked box === select all... store null rather than a redundant full set"),
            // so the correct, EXPECTED outcome is every row still shows -- proving narrowing on
            // THIS column instead needs a value that genuinely isn't on offer, same technique c4
            // already uses on the "การกระทำ" column.
            await applyColumnFilter(ctx2.page, 'audit_device_ip', [ip]);
            const selectAllState = await historyState(ctx2.page);
            measured('c7 the-only-value checked (= select all, by design)', selectAllState);
            check('c7: checking the one-and-only ip value is "select all" (unfiltered)',
                selectAllState.recordsDisplay === selectAllState.recordsTotal, selectAllState.recordsDisplay + ' vs ' + selectAllState.recordsTotal);
            await applyColumnFilter(ctx2.page, 'audit_device_ip', ['__no_such_ip__']);
            const zeroState = await historyState(ctx2.page);
            measured('c7 filtered to a nonexistent ip', zeroState);
            check('c7: this column\'s filter genuinely narrows (0 for a value nothing carries)', zeroState.recordsDisplay === 0, zeroState.recordsDisplay);
            await clearColumnFilter(ctx2.page, 'audit_device_ip');
        }
    } else {
        check('c7: run 752 has at least one row with a real ip to filter by', false, 'none found');
    }
    const rep = ctx2.report();
    measured('c7 report', rep);
    check('c7: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c8 ---------------- */
async function c8() {
    console.log('\n[c8] run 752 (read-only) -- paging: recordsTotal, page length, page 2 differs, sort desc');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads = capturePayrollGetPayloads(ctx.page);
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = lastAuditLog(payloads) || [];
    const state1 = await historyState(ctx.page);
    measured('c8 page 1 state', Object.assign({ serverLogCount: logs.length }, state1));
    check('c8: recordsTotal matches audit_log.length (~1571 per round A)', state1.recordsTotal === logs.length, state1.recordsTotal + ' vs ' + logs.length);
    check('c8: page 1 body row count equals the page length', state1.bodyRowCount === state1.length, state1.bodyRowCount + ' vs ' + state1.length);
    const page1Times = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(1)', (tds) => tds.map((td) => td.textContent.trim()));
    const maxServerTime = logs.slice().sort((a, b) => (a.performed_at < b.performed_at ? 1 : -1))[0].performed_at;
    measured('c8 page 1 first row time cell / server max performed_at', { firstCell: page1Times[0], maxServerTime });
    check('c8: default sort is performed_at DESC (row 1 = the newest)', state1.firstRowTime === maxServerTime, state1.firstRowTime + ' vs ' + maxServerTime);
    await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().page('next').draw('page'));
    await ctx.page.waitForTimeout(300);
    const page2Times = await ctx.page.$$eval('#tb_run_audit_log tbody tr td:nth-child(1)', (tds) => tds.map((td) => td.textContent.trim()));
    measured('c8 page 2 first row time cell', page2Times[0]);
    check('c8: page 2 shows different rows than page 1', JSON.stringify(page1Times) !== JSON.stringify(page2Times), page2Times[0]);
    const rep = ctx.report();
    measured('c8 report', rep);
    check('c8: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c9 ---------------- */
async function c9() {
    console.log('\n[c9] th -> en -> th -- headers / filter panel / empty state / actor / action / badge');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const before = await ctx.page.evaluate(() => ({
        headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
        actionCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)') || {}).textContent,
        actorCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(2) .apv-person-name') || {}).textContent,
        statusCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(4) [data-badge="status"]') || {}).textContent,
    }));
    await ctx.page.evaluate(() => changeLanguage('en'));
    await ctx.page.waitForTimeout(600);
    const afterEn = await ctx.page.evaluate(() => ({
        headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
        actionCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)') || {}).textContent,
        actorCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(2) .apv-person-name') || {}).textContent,
        statusCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(4) [data-badge="status"]') || {}).textContent,
    }));
    await ctx.page.click('.tcf-filter-btn[data-tcf-key="audit_action"]');
    await ctx.page.waitForSelector('.tcf-panel:not(.d-none)');
    const panelEn = await ctx.page.evaluate(() => document.querySelector('.tcf-panel-title').textContent.trim());
    await ctx.page.keyboard.press('Escape');
    await ctx.page.evaluate(() => changeLanguage('th'));
    await ctx.page.waitForTimeout(600);
    const afterTh = await ctx.page.evaluate(() => ({
        headers: Array.from(document.querySelectorAll('#tb_run_audit_log thead th')).map((th) => th.textContent.trim()),
        actionCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(3)') || {}).textContent,
        actorCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(2) .apv-person-name') || {}).textContent,
        statusCell: (document.querySelector('#tb_run_audit_log tbody tr td:nth-child(4) [data-badge="status"]') || {}).textContent,
    }));
    measured('c9 th', before);
    measured('c9 en', Object.assign({ panelTitle: panelEn }, afterEn));
    measured('c9 th again', afterTh);
    check('c9: headers changed th -> en', JSON.stringify(before.headers) !== JSON.stringify(afterEn.headers), JSON.stringify(afterEn.headers));
    check('c9: filter panel title is English mid-switch', panelEn !== before.headers[3] && panelEn.length > 0, panelEn);
    check('c9: headers back to th values byte-for-byte', JSON.stringify(before.headers) === JSON.stringify(afterTh.headers), JSON.stringify(afterTh.headers));
    check('c9: action cell text changed and returned', before.actionCell !== afterEn.actionCell && before.actionCell === afterTh.actionCell, afterTh.actionCell);
    check('c9: status badge text changed and returned', before.statusCell !== afterEn.statusCell && before.statusCell === afterTh.statusCell, afterTh.statusCell);
    const rep = ctx.report();
    measured('c9 report', rep);
    check('c9: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c10 ---------------- */
async function c10() {
    for (const spec of [{ label: '1400 dark', width: 1400, height: 950 }, { label: '430 dark', width: 430, height: 900 }]) {
        console.log('\n[c10] ' + spec.label + ' -- token colours, no sideways scroll, no pane padding');
        const ctx = await openContext({ sessionId, width: spec.width, height: spec.height, lang: 'th', blockPaths: WRITE_PATHS });
        await gotoRun(ctx, fixtureRunToken, 'th');
        const theme = await applyAppTheme(ctx.page, 'dark');
        measured('c10 @' + spec.label + ' theme', theme);
        check('c10 @' + spec.label + ': page really is dark', theme.ok === true, theme.stamp);
        if (!theme.ok) { await closeAll(); continue; }
        await openHistoryTab(ctx.page);
        const picture = await ctx.page.evaluate(() => {
            const cs = getComputedStyle(document.documentElement);
            const token = (n) => cs.getPropertyValue(n).trim();
            const thead = document.querySelector('#tb_run_audit_log thead');
            const pane = document.querySelector('#run-history-pane');
            return {
                theadBg: getComputedStyle(thead).backgroundColor,
                cBg: token('--c-bg'), cBgSubtle: token('--c-bg-subtle'), cText: token('--c-text'),
                bodyScrollWidth: document.body.scrollWidth, windowWidth: window.innerWidth,
                panePadL: getComputedStyle(pane).paddingLeft, panePadR: getComputedStyle(pane).paddingRight,
            };
        });
        measured('c10 @' + spec.label + ' picture', picture);
        check('c10 @' + spec.label + ': no horizontal overflow', picture.bodyScrollWidth <= picture.windowWidth + 1, picture.bodyScrollWidth + ' vs ' + picture.windowWidth);
        check('c10 @' + spec.label + ': pane has no side padding', picture.panePadL === '0px' && picture.panePadR === '0px', picture.panePadL + '/' + picture.panePadR);
        check('c10 @' + spec.label + ': dark tokens really are non-default (not still light values)', picture.cBg !== '' && picture.cBg !== '#ffffff', picture.cBg);
        const rep = ctx.report();
        measured('c10 @' + spec.label + ' report', rep);
        check('c10 @' + spec.label + ': nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
        await closeAll();
    }
}

/* ---------------- c11 ---------------- */
async function c11() {
    console.log('\n[c11] nothing else broke -- other tabs open, #tb_run_detail filter still works, #phTitleBadge intact');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    const TABS = ['#run-employee-tab', '#run-cash-tab', '#run-bank-account-tab', '#run-remittance-tab',
        '#run-reports-tab', '#run-history-tab'];
    const results = {};
    for (const tab of TABS) {
        const visible = await ctx.page.evaluate((s) => {
            const el = document.querySelector(s);
            return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
        }, tab);
        if (!visible) { results[tab] = 'hidden (not a failure -- run-dependent)'; continue; }
        await ctx.page.click(tab);
        await ctx.page.waitForTimeout(300);
        const active = await ctx.page.evaluate((s) => document.querySelector(s).classList.contains('active'), tab);
        results[tab] = active ? 'ok' : 'FAILED TO ACTIVATE';
    }
    measured('c11 tab open results', results);
    for (const tab of TABS) {
        check('c11: ' + tab + ' opened without error', results[tab] === 'ok' || results[tab].indexOf('hidden') === 0, results[tab]);
    }
    await ctx.page.click('#run-employee-tab');
    await ctx.page.waitForTimeout(400);
    const tcfBtn = await ctx.page.$('#tb_run_detail .tcf-filter-btn');
    check('c11: #tb_run_detail still has its own column-filter buttons', !!tcfBtn, tcfBtn ? 'found' : 'missing');
    if (tcfBtn) {
        await tcfBtn.click();
        const panelOpen = await ctx.page.waitForSelector('.tcf-panel:not(.d-none)', { timeout: 5000 }).then(() => true).catch(() => false);
        check('c11: #tb_run_detail\'s own filter panel still opens', panelOpen, panelOpen);
        if (panelOpen) await ctx.page.keyboard.press('Escape');
    }
    const badgeInfo = await ctx.page.evaluate(() => {
        const el = document.querySelector('#phTitleBadge .badge');
        return el ? { cls: el.className, text: el.textContent.trim() } : null;
    });
    measured('c11 #phTitleBadge', badgeInfo);
    check('c11: #phTitleBadge still renders a badge (stateBadgeRd(), untouched)', !!badgeInfo, JSON.stringify(badgeInfo));
    const rep = ctx.report();
    measured('c11 report', rep);
    check('c11: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c12 ---------------- */
async function c12() {
    console.log('\n[c12] date range on run 752 -- a day with rows narrows to that day, a day with none -> 0 + filtered empty state');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads = capturePayrollGetPayloads(ctx.page);
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    if (!(await searchingEnabled(ctx.page))) {
        note('c12 skipped -- filtering is OFF on run 752 (unexpected)');
        await closeAll();
        return;
    }
    const logs = lastAuditLog(payloads) || [];
    const dayCounts = {};
    logs.forEach((l) => { const d = String(l.performed_at).slice(0, 10); dayCounts[d] = (dayCounts[d] || 0) + 1; });
    const days = Object.keys(dayCounts);
    if (!days.length) { note('c12 skipped -- run 752 has no audit rows'); await closeAll(); return; }
    const pickDay = days[0];
    const expectedCount = dayCounts[pickDay];
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', toDisplayDateFromIso(pickDay));
    await setAuditLogDate(ctx.page, 'auditLogDateTo', toDisplayDateFromIso(pickDay));
    const state = await historyState(ctx.page);
    measured('c12 state (single day)', Object.assign({ pickDay, expectedCount }, state));
    check('c12: recordsDisplay matches rows on that exact day', state.recordsDisplay === expectedCount, state.recordsDisplay + ' vs ' + expectedCount);
    // 2000-01-01 -- run 752's own audit log is all 2026 dates (round A's own COUNT), so this day is
    // genuinely absent, not a value that merely never got picked.
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', '01/01/2000');
    await setAuditLogDate(ctx.page, 'auditLogDateTo', '01/01/2000');
    const state2 = await historyState(ctx.page);
    const zeroFilteredTitle = await ctx.page.evaluate(() => getLangValue('zeroRecords') || '');
    measured('c12 state (day with 0 rows)', Object.assign({ zeroFilteredTitle }, state2));
    check('c12: a day with no rows -> recordsDisplay 0', state2.recordsDisplay === 0, state2.recordsDisplay);
    check('c12: shows the FILTERED empty state', state2.emptyRowTitle === zeroFilteredTitle, state2.emptyRowTitle);
    // 2026-09-23, 3e-3b round B2: dtRenderEmptyState()'s own auto "ล้างตัวกรอง" button (app.js) --
    // the one clear path #auditLogFilterBar's own `.filter-bar-clear` itself does NOT prove, since
    // this button lives inside the empty-state row, not the filter bar.
    await ctx.page.click('#run-history-pane .empty-state-action');
    await ctx.page.waitForTimeout(300);
    const state3 = await historyState(ctx.page);
    const fieldsEmptyAfterEmptyStateClear = await ctx.page.evaluate(() => $('#auditLogDateFrom').val() === '' && $('#auditLogDateTo').val() === '');
    measured('c12 state (after empty-state Clear Filter click)', Object.assign({ fieldsEmptyAfterEmptyStateClear }, state3));
    check("c12: empty-state's own Clear Filter button also empties both date fields", fieldsEmptyAfterEmptyStateClear, fieldsEmptyAfterEmptyStateClear);
    check('c12: recordsDisplay back to full count after empty-state Clear Filter', state3.recordsDisplay === state3.recordsTotal, state3.recordsDisplay + ' vs ' + state3.recordsTotal);
    // 2026-09-23, round B5: no trailing clearAuditLogDateFilter() call here any more -- both fields
    // are ALREADY empty at this point (just asserted above), so #auditLogFilterBar's own
    // `.filter-bar-clear` is correctly `d-none` (nothing left to clear) -- clicking a genuinely
    // hidden button is what timed out here for real before this fix, not a flake.
    const rep = ctx.report();
    measured('c12 report', rep);
    check('c12: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c13 ---------------- */
async function c13() {
    console.log('\n[c13] from > to -- callout warns, recordsDisplay unchanged; fixed -- callout hides, filters');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    if (!(await searchingEnabled(ctx.page))) {
        note('c13 skipped -- filtering is OFF on run 752 (unexpected)');
        await closeAll();
        return;
    }
    const before = await historyState(ctx.page);
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', '31/12/2026');
    await setAuditLogDate(ctx.page, 'auditLogDateTo', '01/01/2026');
    const invalidVisible = await ctx.page.evaluate(() => !document.getElementById('auditLogDateRangeInvalidCallout').classList.contains('d-none'));
    const during = await historyState(ctx.page);
    measured('c13 state (from > to)', Object.assign({ invalidVisible }, during));
    check('c13: callout shows when from > to', invalidVisible === true, invalidVisible);
    check('c13: recordsDisplay unchanged while invalid (not filtered)', during.recordsDisplay === before.recordsDisplay, during.recordsDisplay + ' vs ' + before.recordsDisplay);
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', '01/01/2026');
    const fixedVisible = await ctx.page.evaluate(() => !document.getElementById('auditLogDateRangeInvalidCallout').classList.contains('d-none'));
    const after = await historyState(ctx.page);
    measured('c13 state (fixed)', Object.assign({ fixedVisible }, after));
    check('c13: callout hides once the range is valid again', fixedVisible === false, fixedVisible);
    check('c13: filtering resumes (recordsDisplay <= before)', after.recordsDisplay <= before.recordsDisplay, after.recordsDisplay + ' vs ' + before.recordsDisplay);
    await clearAuditLogDateFilter(ctx.page);
    const rep = ctx.report();
    measured('c13 report', rep);
    check('c13: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c14 ---------------- */
async function c14() {
    console.log('\n[c14] Clear Filter -- action column filter + date range + search box all clear in one click');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads = capturePayrollGetPayloads(ctx.page);
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    if (!(await searchingEnabled(ctx.page))) {
        note('c14 skipped -- filtering is OFF on run 752 (unexpected)');
        await closeAll();
        return;
    }
    const logs = lastAuditLog(payloads) || [];
    const fullCount = logs.length;
    const day = String(logs[0].performed_at).slice(0, 10); // newest row's own day (order DESC)
    const displayDay = toDisplayDateFromIso(day);
    const fromFieldLabel = await ctx.page.evaluate(() => document.querySelector('label[for="auditLogDateFrom"]').textContent.trim());

    // 2026-09-23, round B5: 1 field set -- header shows (1), exactly 1 chip labeled from the
    // field's own <label> (fieldLabelFor(), app.js), Clear button visible. All 3 driven by
    // initFilterBar()'s now-input-aware refresh() -- nothing page-local computes any of this.
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', displayDay);
    let barState = await auditLogFilterBarState(ctx.page);
    measured('c14 bar state (1 field set)', barState);
    check('c14: header shows (1)', barState.countVisible && barState.count === '1', JSON.stringify(barState));
    check('c14: exactly 1 chip, labeled with the field\'s own <label>',
        barState.chips.length === 1 && barState.chips[0].label.indexOf(fromFieldLabel) !== -1, JSON.stringify(barState.chips));
    check('c14: chip value matches what was typed', barState.chips[0] && barState.chips[0].value === displayDay, JSON.stringify(barState.chips));
    check('c14: Clear button visible with 1 field set', barState.clearBtnVisible, barState.clearBtnVisible);

    // 2nd field set to the SAME day -- header (2), narrows recordsDisplay to just that one day
    // (same shape c12's own single-day test already proved) -- the "before" half of the chip-×
    // contrast below.
    await setAuditLogDate(ctx.page, 'auditLogDateTo', displayDay);
    barState = await auditLogFilterBarState(ctx.page);
    const narrowedState = await historyState(ctx.page);
    const expectedNarrowed = logs.filter((l) => String(l.performed_at).slice(0, 10) === day).length;
    measured('c14 bar state (2 fields set, same day)', Object.assign({ expectedNarrowed }, barState, narrowedState));
    check('c14: header shows (2)', barState.countVisible && barState.count === '2', JSON.stringify(barState));
    check('c14: 2 chips now', barState.chips.length === 2, JSON.stringify(barState.chips));
    check('c14: narrowed to exactly that day\'s own rows', narrowedState.recordsDisplay === expectedNarrowed, narrowedState.recordsDisplay + ' vs ' + expectedNarrowed);

    // Remove the "From" chip via its own × -- back to (1); only the "To" constraint (same day,
    // now an upper bound alone) remains, which the newest row's own day satisfies trivially --
    // recordsDisplay jumps back up, the other half of the contrast.
    await ctx.page.evaluate(() => {
        const chip = Array.from(document.querySelectorAll('#auditLogFilterBar .filter-bar-chip'))
            .find((c) => c.getAttribute('data-target') === 'auditLogDateFrom');
        chip.querySelector('.filter-bar-chip-remove').click();
    });
    await ctx.page.waitForTimeout(300);
    barState = await auditLogFilterBarState(ctx.page);
    const afterRemoveState = await historyState(ctx.page);
    const expectedToOnly = logs.filter((l) => String(l.performed_at).slice(0, 10) <= day).length;
    const fromFieldNowEmpty = (await ctx.page.evaluate(() => $('#auditLogDateFrom').val())) === '';
    measured('c14 bar state (after removing "From" chip)', Object.assign({ expectedToOnly, fromFieldNowEmpty }, barState, afterRemoveState));
    check('c14: header back to (1) after removing one chip', barState.countVisible && barState.count === '1', JSON.stringify(barState));
    check('c14: only the "To" chip remains', barState.chips.length === 1 && barState.chips[0].target === 'auditLogDateTo', JSON.stringify(barState.chips));
    check('c14: #auditLogDateFrom itself is empty after its own chip ×', fromFieldNowEmpty, fromFieldNowEmpty);
    check('c14: recordsDisplay widens back out once the lower bound is gone', afterRemoveState.recordsDisplay === expectedToOnly, afterRemoveState.recordsDisplay + ' vs ' + expectedToOnly);

    // Column filter + search + the remaining date field, all at once -- Clear resets everything,
    // including the filter bar's own chip/count/button state.
    const wantLabel = await ctx.page.evaluate((a) => auditActionLabel(a), 'recalculate');
    await applyColumnFilter(ctx.page, 'audit_action', [wantLabel]);
    await ctx.page.evaluate(() => { jQuery('#tb_run_audit_log').DataTable().search('recalculate').draw(); });
    await ctx.page.waitForTimeout(300);
    await clearAuditLogDateFilter(ctx.page);
    const finalState = await historyState(ctx.page);
    barState = await auditLogFilterBarState(ctx.page);
    const fieldsEmpty = await ctx.page.evaluate(() => $('#auditLogDateFrom').val() === '' && $('#auditLogDateTo').val() === '');
    const searchEmpty = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().search() === '');
    const columnFiltersActive = await ctx.page.evaluate(() => hasActiveColumnFilters(jQuery('#tb_run_audit_log').DataTable()));
    measured('c14 state after Clear Filter', Object.assign({ fullCount, fieldsEmpty, searchEmpty, columnFiltersActive }, finalState, barState));
    check('c14: recordsDisplay back to full count', finalState.recordsDisplay === fullCount, finalState.recordsDisplay + ' vs ' + fullCount);
    check('c14: both date fields empty', fieldsEmpty, fieldsEmpty);
    check('c14: search box empty', searchEmpty, searchEmpty);
    check('c14: no column filter left checked', columnFiltersActive === false, columnFiltersActive);
    check('c14: no chip/count left, Clear button hidden again',
        barState.chips.length === 0 && !barState.countVisible && !barState.clearBtnVisible, JSON.stringify(barState));
    const rep = ctx.report();
    measured('c14 report', rep);
    check('c14: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c15 ---------------- */
async function c15() {
    console.log('\n[c15] #auditLogDetailModal -- real click opens the RIGHT row; edge-case rows render correctly; no stacked .show');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    const payloads = capturePayrollGetPayloads(ctx.page);
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const logs = lastAuditLog(payloads) || [];
    if (!logs.length) { note('c15 skipped -- run 752 has no audit rows'); await closeAll(); return; }

    // A REAL click on page 1's own first row -- proves the button's own dt.row($tr).data() wiring,
    // not just the renderer function in isolation (the 3 edge-case rows below use that instead --
    // finding a SPECIFIC row like "longest note" via a real click would mean depaginating all 1571
    // rows first, which the button-wiring check itself does not need).
    const page1Row0 = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().row({ page: 'current' }, 0).data());
    await ctx.page.click('#tb_run_audit_log tbody tr:first-child .audit-log-view-detail-btn');
    await ctx.page.waitForTimeout(300);
    const realClickTime = await ctx.page.evaluate(() => (document.querySelector('#auditLogDetailModalBody .rd-sync-field-value') || {}).textContent || '');
    measured('c15 real click -- row shown vs page-1-row-0', { expectedTime: page1Row0.performed_at, modalShowsTime: realClickTime.trim() });
    check('c15: a real click opens the modal for THAT row (time field matches)', realClickTime.indexOf(String(page1Row0.performed_at).slice(0, 10).split('-').reverse().join('/')) !== -1, realClickTime);
    await ctx.page.click('#auditLogDetailModal .btn-close');
    await ctx.page.waitForTimeout(400);

    const longestNoteRow = logs.slice().sort((a, b) => (b.note || '').length - (a.note || '').length)[0];
    const nullNoteRow = logs.find((l) => !l.note);
    const fromNeToRow = logs.find((l) => l.from_state && l.from_state !== l.to_state);

    async function openAndCheck(label, row) {
        if (!row) { note('c15 ' + label + ' skipped -- no matching row on run 752'); return; }
        await ctx.page.evaluate((r) => openAuditLogDetailRd(r), row);
        await ctx.page.waitForTimeout(200);
        const body = await ctx.page.evaluate(() => document.getElementById('auditLogDetailModalBody').innerText);
        const shownCount = await ctx.page.evaluate(() => document.querySelectorAll('.modal.show').length);
        measured('c15 ' + label + ' shownModalCount', shownCount);
        check('c15 ' + label + ': exactly 1 modal shown', shownCount === 1, shownCount);
        if (label === 'longest note' && row.note) {
            check('c15 longest note: full note text present in modal (' + row.note.length + ' chars)', body.indexOf(row.note) !== -1, body.length);
        }
        if (label === 'null note') {
            check('c15 null note: renders the em-dash placeholder', body.indexOf('—') !== -1, JSON.stringify(body));
        }
        if (label === 'from!=to') {
            const badgeCount = await ctx.page.evaluate(() => document.querySelectorAll('#auditLogDetailModalBody [data-badge="status"]').length);
            check('c15 from!=to: modal shows 2 status badges (from -> to), not 1', badgeCount === 2, badgeCount);
        }
        await ctx.page.click('#auditLogDetailModal .btn-close');
        await ctx.page.waitForTimeout(400);
    }
    await openAndCheck('longest note', longestNoteRow);
    await openAndCheck('null note', nullNoteRow);
    await openAndCheck('from!=to', fromNeToRow);

    if (longestNoteRow && nullNoteRow && longestNoteRow.id !== nullNoteRow.id) {
        await ctx.page.evaluate((r) => openAuditLogDetailRd(r), longestNoteRow);
        await ctx.page.waitForTimeout(200);
        const bodyA = await ctx.page.evaluate(() => document.getElementById('auditLogDetailModalBody').innerText);
        await ctx.page.click('#auditLogDetailModal .btn-close');
        await ctx.page.waitForTimeout(400);
        await ctx.page.evaluate((r) => openAuditLogDetailRd(r), nullNoteRow);
        await ctx.page.waitForTimeout(200);
        const bodyB = await ctx.page.evaluate(() => document.getElementById('auditLogDetailModalBody').innerText);
        check('c15: opening a different row after closing shows different content', bodyA !== bodyB, 'A==B: ' + (bodyA === bodyB));
        await ctx.page.click('#auditLogDetailModal .btn-close');
        await ctx.page.waitForTimeout(400);
    }
    const rep = ctx.report();
    measured('c15 report', rep);
    check('c15: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

/* ---------------- c16 ---------------- */
function auditLogC16Labels(page) {
    return page.evaluate(() => ({
        modalTitle: document.getElementById('auditLogDetailModalLabel').textContent.trim(),
        filterLabel: document.querySelector('label[for="auditLogDateFrom"]').textContent.trim(),
        filterHeaderText: document.querySelector('#auditLogFilterBar .filter-bar-label').textContent.trim(),
        closeLabel: document.querySelector('#auditLogDetailModal .modal-footer button').textContent.trim(),
    }));
}
// 2026-09-23, 3e-3b round B4: #auditLogFilterBar is now the SAME `.filter-bar` component
// (filter-bar.php) #tb_run_detail's own #runDetailFilterBar uses -- this checks it really is (same
// classes, byte-identical partial) rather than a look-alike, and that the 2 things B3 got wrong
// about the shape it replaced (icon-in-field-label, non-30.5px controls) are still right under the
// NEW shape too. The header's own `fa-filter` icon is §6's documented exception (rules.md,
// filter-bar.php:133-138) -- scoped to `.filter-bar-body label i` on purpose, not the header.
function auditLogFilterBarShape(page) {
    return page.evaluate(() => {
        const pane = document.getElementById('run-history-pane');
        const bar = document.getElementById('auditLogFilterBar');
        const otherBar = document.getElementById('runDetailFilterBar');
        const classesOf = (el) => el ? Array.from(el.classList).filter((c) => c !== 'collapsed').sort() : null;
        const controls = bar ? Array.from(bar.querySelectorAll('.filter-bar-body input.form-control')) : [];
        return {
            barCountInPane: pane.querySelectorAll('.filter-bar').length,
            barClasses: classesOf(bar),
            otherBarClasses: classesOf(otherBar),
            fieldLabelIcons: bar ? bar.querySelectorAll('.filter-bar-body label i').length : null,
            heights: controls.map((el) => Math.round(el.getBoundingClientRect().height * 10) / 10),
            hasStationOrFieldsetAncestor: !!(bar && bar.closest('.station-filter, fieldset')),
        };
    });
}
async function c16() {
    console.log('\n[c16] th -> en -> th while the modal is open; dark+430 filter-bar/datepicker/modal checks');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openHistoryTab(ctx.page);
    const filterShape = await auditLogFilterBarShape(ctx.page);
    measured('c16 filter bar shape', filterShape);
    check('c16: exactly 1 .filter-bar in #run-history-pane', filterShape.barCountInPane === 1, filterShape.barCountInPane);
    check('c16: #auditLogFilterBar carries the SAME classes as #runDetailFilterBar (same partial)',
        JSON.stringify(filterShape.barClasses) === JSON.stringify(filterShape.otherBarClasses), JSON.stringify(filterShape));
    check('c16: no icon inside a field label (header fa-filter icon is the documented §6 exception)', filterShape.fieldLabelIcons === 0, filterShape.fieldLabelIcons);
    check('c16: every date input is ~30.5px tall (+/-0.5)',
        filterShape.heights.length > 0 && filterShape.heights.every((h) => Math.abs(h - 30.5) <= 0.5), JSON.stringify(filterShape.heights));
    check('c16: no .station-filter/fieldset wraps the filter bar', !filterShape.hasStationOrFieldsetAncestor, filterShape.hasStationOrFieldsetAncestor);

    // 2026-09-23, round B5: with NO filter set at all, the shared Clear button must be HIDDEN --
    // a real bug shape flagged this round ("ภาพ 2 คือบั๊ก" -- a Clear button visible with nothing
    // to clear). Now that refresh() (app.js) is input-aware, this is driven the same way it always
    // was for a select-only bar; nothing page-local sets it either way any more (round B4's own
    // manual toggle helper is gone).
    const initialBarState = await auditLogFilterBarState(ctx.page);
    measured('c16 initial bar state (no filter set)', initialBarState);
    check('c16: Clear button hidden when no filter is set', !initialBarState.clearBtnVisible, initialBarState.clearBtnVisible);
    check('c16: no chip, count hidden when no filter is set', initialBarState.chips.length === 0 && !initialBarState.countVisible, JSON.stringify(initialBarState));

    // The toggle -- real markup carries no `aria-expanded` at all (checked, not assumed); the actual
    // mechanism (`initFilterBar()`, app.js) is a plain `.collapsed` class flip, so that's what's
    // measured here instead of the attribute the round's own instructions guessed at.
    const beforeCollapsed = await ctx.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'));
    const hasAriaExpanded = await ctx.page.evaluate(() => document.querySelector('#auditLogFilterBar .filter-bar-toggle').hasAttribute('aria-expanded'));
    await ctx.page.click('#auditLogFilterBar .filter-bar-toggle');
    await ctx.page.waitForTimeout(300);
    const afterCollapsed = await ctx.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'));
    measured('c16 toggle', { beforeCollapsed, afterCollapsed, hasAriaExpanded });
    check('c16: clicking .filter-bar-toggle flips .collapsed', beforeCollapsed !== afterCollapsed, beforeCollapsed + ' -> ' + afterCollapsed);
    note('c16: real .filter-bar-toggle markup carries no aria-expanded attribute (hasAriaExpanded=' + hasAriaExpanded + ') -- checked the actual .collapsed class mechanism instead');
    await ctx.page.click('#auditLogFilterBar .filter-bar-toggle'); // restore, so later assertions see the original state
    await ctx.page.waitForTimeout(300);

    // 2026-09-23, round B5: "หุบแล้ว chip ยังเห็น" -- filter-bar.php's own CSS shows `.filter-bar-chips`
    // ONLY while collapsed (hidden while expanded, where the fields themselves carry the signal
    // instead). Sets a real value, forces collapsed, then checks the chip is ACTUALLY visible
    // (offsetWidth/offsetHeight/getClientRects -- not just present in the DOM, which a display:none
    // ancestor would still satisfy).
    await setAuditLogDate(ctx.page, 'auditLogDateFrom', '01/01/2026');
    const isCollapsedNow = await ctx.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'));
    if (!isCollapsedNow) {
        await ctx.page.click('#auditLogFilterBar .filter-bar-toggle');
        await ctx.page.waitForTimeout(300);
    }
    const chipVisibleWhileCollapsed = await ctx.page.evaluate(() => {
        const chip = document.querySelector('#auditLogFilterBar .filter-bar-chip');
        return !!(chip && (chip.offsetWidth || chip.offsetHeight || chip.getClientRects().length));
    });
    measured('c16 chip visible while collapsed', chipVisibleWhileCollapsed);
    check('c16: chip is actually visible once collapsed, not just present in the DOM', chipVisibleWhileCollapsed, chipVisibleWhileCollapsed);
    await clearAuditLogDateFilter(ctx.page);

    const rowData = await ctx.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().row(0).data());
    if (!rowData) {
        note('c16 skipped -- fixture run has no audit rows');
        await closeAll();
    } else {
        await ctx.page.evaluate((r) => openAuditLogDetailRd(r), rowData);
        await ctx.page.waitForTimeout(200);
        const before = await auditLogC16Labels(ctx.page);
        await ctx.page.evaluate(() => changeLanguage('en'));
        await ctx.page.waitForTimeout(600);
        const afterEn = Object.assign(await auditLogC16Labels(ctx.page), {
            modalStillShown: await ctx.page.evaluate(() => document.getElementById('auditLogDetailModal').classList.contains('show')),
        });
        await ctx.page.evaluate(() => changeLanguage('th'));
        await ctx.page.waitForTimeout(600);
        const afterTh = await auditLogC16Labels(ctx.page);
        measured('c16 th', before);
        measured('c16 en', afterEn);
        measured('c16 th again', afterTh);
        check('c16: modal stayed open through the switch', afterEn.modalStillShown === true, afterEn.modalStillShown);
        check('c16: modal title changed th -> en', before.modalTitle !== afterEn.modalTitle, afterEn.modalTitle);
        check('c16: filter field label ("From") changed th -> en', before.filterLabel !== afterEn.filterLabel, afterEn.filterLabel);
        check('c16: filter bar header ("ตัวกรอง") changed th -> en', before.filterHeaderText !== afterEn.filterHeaderText, afterEn.filterHeaderText);
        check('c16: close button label changed th -> en', before.closeLabel !== afterEn.closeLabel, afterEn.closeLabel);
        check('c16: all 4 back to th values byte-for-byte',
            before.modalTitle === afterTh.modalTitle && before.filterLabel === afterTh.filterLabel
            && before.filterHeaderText === afterTh.filterHeaderText && before.closeLabel === afterTh.closeLabel,
            JSON.stringify(afterTh));
        await ctx.page.click('#auditLogDetailModal .btn-close');
        await ctx.page.waitForTimeout(400);
        const rep1 = ctx.report();
        measured('c16 report (th/en switch)', rep1);
        check('c16: nothing was written (th/en switch)', rep1.blockedWrites === 0, rep1.blockedWritePaths.join(','));
        await closeAll();
    }

    for (const spec of [{ label: '1400 dark', width: 1400, height: 950 }, { label: '430 dark', width: 430, height: 900 }]) {
        console.log('\n[c16] ' + spec.label + ' -- filter bar wrap, datepicker popup, modal-lg width');
        const ctx2 = await openContext({ sessionId, width: spec.width, height: spec.height, lang: 'th', blockPaths: WRITE_PATHS });
        await gotoRun(ctx2, fixtureRunToken, 'th');
        const theme = await applyAppTheme(ctx2.page, 'dark');
        measured('c16 @' + spec.label + ' theme', theme);
        check('c16 @' + spec.label + ': page really is dark', theme.ok === true, theme.stamp);
        if (!theme.ok) { await closeAll(); continue; }
        await openHistoryTab(ctx2.page);
        const overflowPicture = await ctx2.page.evaluate(() => ({
            bodyScrollWidth: document.body.scrollWidth, windowWidth: window.innerWidth,
        }));
        measured('c16 @' + spec.label + ' overflow picture', overflowPicture);
        check('c16 @' + spec.label + ': filter bar causes no horizontal overflow',
            overflowPicture.bodyScrollWidth <= overflowPicture.windowWidth + 1, overflowPicture.bodyScrollWidth + ' vs ' + overflowPicture.windowWidth);

        // 2026-09-23, round B6: the bare <table> vs #auditLogFilterBar left edge -- the gutter fix
        // (style.css, `#tb_run_audit_log_wrapper .row { --bs-gutter-x: 0; }`, same mechanism the
        // OTHER 5 tables already had) makes these line up at every width, same as #tb_run_detail's
        // own table already does against #runDetailFilterBar. Right edge is checked too, but ONLY
        // expected to match at a WIDE viewport -- measured #tb_run_detail itself (the very reference
        // this round asked to compare against) diverging there on ITS OWN table at 430px
        // (table.right landed at 1189 against its filter bar's 421, matching its own explicit
        // `.rd-detail-table-flush` 1180px min-width almost exactly) -- `.table-responsive`'s
        // horizontal-scroll fallback widening the table past its container on a narrow screen is
        // the app's own established, intentional pattern (CLAUDE.md's "no DataTables scrollX, just
        // .table-responsive" convention), not a defect unique to this table.
        const alignPicture = await ctx2.page.evaluate(() => {
            const r = (el) => { const b = el.getBoundingClientRect(); return { l: Math.round(b.left * 10) / 10, r: Math.round(b.right * 10) / 10 }; };
            return { bar: r(document.getElementById('auditLogFilterBar')), table: r(document.querySelector('#tb_run_audit_log')) };
        });
        measured('c16 @' + spec.label + ' table/filter-bar alignment', alignPicture);
        check('c16 @' + spec.label + ': <table> left edge == filter bar left edge (+/-1)',
            Math.abs(alignPicture.table.l - alignPicture.bar.l) <= 1, alignPicture.table.l + ' vs ' + alignPicture.bar.l);
        if (spec.width >= 992) {
            check('c16 @' + spec.label + ': <table> right edge == filter bar right edge (+/-1, wide viewport only)',
                Math.abs(alignPicture.table.r - alignPicture.bar.r) <= 1, alignPicture.table.r + ' vs ' + alignPicture.bar.r);
        } else {
            note('c16 @' + spec.label + ': right edge NOT compared -- .table-responsive\'s own horizontal-scroll fallback legitimately widens the table past its container below `lg` (992px), same as #tb_run_detail\'s own table does against #runDetailFilterBar');
        }

        // 2026-09-23, round B5, real bug found running this: below `lg` (992px) initFilterBar()'s
        // own viewport fallback starts the bar COLLAPSED (no `pageKey` set here, same as
        // #runDetailFilterBar -- see that fallback's own comment, app.js) -- at 430px, `#auditLogDateFrom`
        // sits inside the collapsed (0-height) `.filter-bar-body`, so a raw click on it timed out
        // ("intercepts pointer events", the collapsed ancestor). Expand first if needed, same as a
        // real user would tap the toggle before typing a date on a narrow screen.
        if (await ctx2.page.evaluate(() => document.getElementById('auditLogFilterBar').classList.contains('collapsed'))) {
            await ctx2.page.click('#auditLogFilterBar .filter-bar-toggle');
            await ctx2.page.waitForTimeout(300);
        }
        await ctx2.page.click('#auditLogDateFrom');
        const dpOpened = await ctx2.page.waitForSelector('.datepicker-dropdown', { timeout: 5000 }).then(() => true).catch(() => false);
        const dpPicture = dpOpened ? await ctx2.page.evaluate(() => {
            const dp = document.querySelector('.datepicker-dropdown');
            const cs = getComputedStyle(document.documentElement);
            return { dpBg: getComputedStyle(dp).backgroundColor, cBg: cs.getPropertyValue('--c-bg').trim() };
        }) : null;
        measured('c16 @' + spec.label + ' datepicker picture', { dpOpened, dpPicture });
        check('c16 @' + spec.label + ': datepicker popup opens in dark mode', dpOpened, dpOpened);
        if (dpPicture) {
            check('c16 @' + spec.label + ": datepicker popup isn't stuck light (--c-bg token, not white)",
                dpPicture.cBg !== '' && dpPicture.cBg !== '#ffffff', dpPicture.cBg);
        }
        await ctx2.page.keyboard.press('Escape');

        const rowData2 = await ctx2.page.evaluate(() => jQuery('#tb_run_audit_log').DataTable().row(0).data());
        if (rowData2) {
            await ctx2.page.evaluate((r) => openAuditLogDetailRd(r), rowData2);
            await ctx2.page.waitForTimeout(300);
            const modalPicture = await ctx2.page.evaluate(() => ({
                scrollWidth: document.body.scrollWidth, windowWidth: window.innerWidth,
            }));
            measured('c16 @' + spec.label + ' modal picture', modalPicture);
            check('c16 @' + spec.label + ': modal-lg does not overflow the viewport',
                modalPicture.scrollWidth <= modalPicture.windowWidth + 1, modalPicture.scrollWidth + ' vs ' + modalPicture.windowWidth);
            await ctx2.page.click('#auditLogDetailModal .btn-close');
            await ctx2.page.waitForTimeout(300);
        } else {
            note('c16 @' + spec.label + ' modal check skipped -- fixture run has no audit rows');
        }
        const rep2 = ctx2.report();
        measured('c16 @' + spec.label + ' report', rep2);
        check('c16 @' + spec.label + ': nothing was written', rep2.blockedWrites === 0, rep2.blockedWritePaths.join(','));
        await closeAll();
    }
}

/* ---------------- c17 ---------------- */
// 2026-09-23, round B5: "โหมดเดิมไม่แตก" -- #runDetailFilterBar (Employee tab, 3 SELECT fields, no
// `<input class="form-control">` at all) is the ONE existing real consumer of initFilterBar() that
// this round's own app.js change could regress, since every select-branch line in that function
// was supposed to survive completely untouched. Every expectation below is read from the DOM
// itself (the option's own text, the field's own <label>, the select's own default value) rather
// than a hardcoded Thai/English string, so a copy change elsewhere can never make this cell lie.
async function c17() {
    console.log('\n[c17] "โหมดเดิมไม่แตก" -- #runDetailFilterBar (select-only) still works exactly as before');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, fixtureRunToken, 'th');
    await openEmployeeTab(ctx.page);
    const before = await runDetailFilterBarState(ctx.page);
    measured('c17 before (no select filter set)', before);
    check('c17: starts with no chip/count, Clear hidden', before.chips.length === 0 && !before.countVisible && !before.clearBtnVisible, JSON.stringify(before));

    const optionText = await ctx.page.evaluate(() => ($('#rdPaymentMethodFilter').find('option[value="bank"]').text() || '').trim());
    const fieldLabel = await ctx.page.evaluate(() => document.querySelector('label[for="rdPaymentMethodFilter"]').textContent.trim());
    await ctx.page.evaluate(() => { $('#rdPaymentMethodFilter').val('bank').trigger('change'); });
    await ctx.page.waitForTimeout(300);
    let state = await runDetailFilterBarState(ctx.page);
    measured('c17 after selecting a value', Object.assign({ optionText, fieldLabel }, state));
    check('c17: header shows (1)', state.countVisible && state.count === '1', JSON.stringify(state));
    check('c17: exactly 1 chip, labeled with the field\'s own <label>', state.chips.length === 1 && state.chips[0].label.indexOf(fieldLabel) !== -1, JSON.stringify(state.chips));
    check('c17: chip value matches the option\'s own text', !!state.chips[0] && state.chips[0].value === optionText, JSON.stringify(state.chips));
    check('c17: Clear button visible', state.clearBtnVisible, state.clearBtnVisible);

    // × on the chip -- resets JUST this select, back to its own default ('all', this field's own
    // markup convention -- read back from the field itself, never assumed).
    await ctx.page.evaluate(() => { document.querySelector('#runDetailFilterBar .filter-bar-chip .filter-bar-chip-remove').click(); });
    await ctx.page.waitForTimeout(300);
    state = await runDetailFilterBarState(ctx.page);
    const selectValueAfterChipRemove = await ctx.page.evaluate(() => $('#rdPaymentMethodFilter').val());
    measured('c17 after chip x', Object.assign({ selectValueAfterChipRemove }, state));
    check('c17: chip x brings count/chip back to nothing', state.chips.length === 0 && !state.countVisible && !state.clearBtnVisible, JSON.stringify(state));
    check('c17: the select itself is back to its own default value', selectValueAfterChipRemove === 'all', selectValueAfterChipRemove);

    // Select again, then use the bar's own Clear button this time -- same end state either path.
    await ctx.page.evaluate(() => { $('#rdPaymentMethodFilter').val('cash').trigger('change'); });
    await ctx.page.waitForTimeout(300);
    await ctx.page.click('#runDetailFilterBar .filter-bar-clear');
    await ctx.page.waitForTimeout(300);
    const afterClear = await runDetailFilterBarState(ctx.page);
    const selectValueAfterClear = await ctx.page.evaluate(() => $('#rdPaymentMethodFilter').val());
    measured('c17 after Clear button', Object.assign({ selectValueAfterClear }, afterClear));
    check('c17: Clear button resets the same way chip x did', afterClear.chips.length === 0 && !afterClear.countVisible && !afterClear.clearBtnVisible, JSON.stringify(afterClear));
    check('c17: back to the exact same starting state as "before"', JSON.stringify(afterClear) === JSON.stringify(before), JSON.stringify(afterClear));
    const rep = ctx.report();
    measured('c17 report', rep);
    check('c17: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    await closeAll();
}

async function main() {
    try {
        await c1();
        await c2();
        await c3();
        await c4();
        await c5();
        await c6();
        await c7();
        await c8();
        await c9();
        await c10();
        await c11();
        await c12();
        await c13();
        await c14();
        await c15();
        await c16();
        await c17();
    } finally {
        await closeAll();
    }
    console.log('\nPassed: ' + passed + ', Failed: ' + failed);
    if (failed > 0) process.exitCode = 1;
}
main();
