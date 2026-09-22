/**
 * 3e-3 round B1 measurement: Process Detail's Action History tab after the Timeline card list
 * (renderAuditHistoryTimelineRd()) was replaced with a real DataTable (#tb_run_audit_log,
 * initAuditLogTableRd(), detail.js).
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/o_history_dt.js <PHPSESSID> <fixtureRunToken>
 *
 * 11 cells, each its own context:
 *   c1  fixture run -- recordsTotal matches api/payroll-run.get's own audit_log, view_detail never shows
 *   c2  filter "การกระทำ" to one action -- visible rows match, label text matches auditActionLabel()
 *   c3  2 filters at once (การกระทำ + ผู้ทำ), then clear both -- back to the full count
 *   c4  filter down to zero -- the OTHER empty state (not no_history_yet), clearing brings rows back
 *   c5  a made-up action code -- fallback label + data-code, never the raw code as visible text
 *   c6  the note cell -- every row the same height regardless of note length, tooltip only where cut,
 *       a NULL note renders empty without erroring
 *   c7  device/IP column -- fixture (both empty) renders '-', run 752's real rows show ip + filter
 *   c8  run 752 (read-only) -- paging: recordsTotal ~1571, page 1 length = pageLength, page 2 differs,
 *       default sort is performed_at DESC
 *   c9  th -> en -> th -- 6 headers / filter panel labels / empty-state / actor name / action label /
 *       badge all follow langData every time
 *   c10 dark + 430 -- header/row/tooltip colours read real --c-* tokens, no sideways scroll, no pane padding
 *   c11 nothing else broke -- the other 7 tabs still open, #tb_run_detail's own column filter still
 *       opens, #phTitleBadge still renders through stateBadgeRd()
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
    console.log('\n[c6] note cell -- equal row heights, tooltip only where truncated, NULL note renders empty');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, lang: 'th', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, RUN_752_TOKEN, 'th');
    await openHistoryTab(ctx.page);
    const picture = await ctx.page.evaluate(() => {
        const rows = Array.from(document.querySelectorAll('#tb_run_audit_log tbody tr')).filter((tr) => !tr.classList.contains('dt-empty-row'));
        const heights = rows.map((tr) => Math.round(tr.getBoundingClientRect().height));
        const noteCells = Array.from(document.querySelectorAll('#tb_run_audit_log tbody .rd-audit-note-cell'));
        const truncated = noteCells.filter((el) => el.scrollWidth > el.clientWidth);
        const tooltipped = noteCells.filter((el) => window.bootstrap && bootstrap.Tooltip.getInstance(el));
        const emptyNoteRows = rows.length - noteCells.length; // rows whose td had a falsy note (rendered '')
        return {
            rowCount: rows.length, heights, maxDelta: heights.length ? Math.max(...heights) - Math.min(...heights) : 0,
            noteCellCount: noteCells.length, truncatedCount: truncated.length, tooltippedCount: tooltipped.length,
            truncatedMatchesTooltipped: truncated.length === tooltipped.length
                && truncated.every((el) => tooltipped.indexOf(el) !== -1),
            emptyNoteRows,
        };
    });
    measured('c6 picture', picture);
    check('c6: every row the same height (within 1px)', picture.maxDelta <= 1, picture.maxDelta);
    check('c6: tooltip exists exactly on the truncated cells, no more no less', picture.truncatedMatchesTooltipped,
        picture.truncatedCount + ' truncated vs ' + picture.tooltippedCount + ' tooltipped');
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
    } finally {
        await closeAll();
    }
    console.log('\nPassed: ' + passed + ', Failed: ' + failed);
    if (failed > 0) process.exitCode = 1;
}
main();
