/**
 * 3e-1 measurement: the 5 non-slip tabs of Process Detail after they were moved onto the shared
 * empty-state / callout / stat-card / money-column / status-badge components, and after the lint
 * items rules.md 12 named on this page were cleared.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/m3e1_tabs_shared.js <PHPSESSID> <runToken>
 *
 * 14 cells, each its own context:
 *   1  fixture run (draft), 1400 th light -- what each of the 5 panes is made of now
 *   2  the same at 430 dark -- nothing scrolls sideways, and the empty-state icon takes the theme
 *   3  fixture run, 768 en -- every word in the converted markup comes from en.json
 *   4  run 1015 (locked, read-only) -- the tables against their own API responses
 *   5  run 1015 -- a search that matches nothing gives the OTHER empty state, not the caller's
 *   6  run 1015 -- the 2 GET-only modals: their [Close] button, and no data-i18n left on a <th>
 *   7  the bulk verify confirm, now showConfirm(): cancel writes nothing, confirm writes once
 *   8  the single-row verify confirm, same two halves
 *   9  MOCK: the Remittance table, one row per status in the map
 *  10  MOCK: the Bank Account table, then the same tab with an empty list (th + en)
 *  11  MOCK: 8 cash rows -> search is on -> the "filtered to nothing" empty state
 *  12  th[data-i18n] across the whole page, with the join picker open
 *  13  every pane starts exactly where the tab bar starts (1400 light + 430 dark)
 *  14  the run-level banners above the tab bar are callouts, not solid .alert tiles
 *
 * Writes: none. Every mutating route of these tabs is in blockPaths, so cells 7/8 prove the confirm
 * reaches its action by counting the blocked attempt rather than by letting it through. Run 1015 is
 * opened read-only; run 752 / EM009 are never opened at all.
 */
'use strict';
const { openContext, closeAll, applyAppTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
if (!sessionId || !runToken) {
    throw new Error('usage: node tests/ui/m3e1_tabs_shared.js <PHPSESSID> <runToken> [<bannerRunToken>]');
}

// run 1015, locked -- the only run in this dev DB whose "after approval" tabs can have real content.
const LOCKED_RUN_TOKEN = 'AV_3zH_fu0Ep03a_cT_NpIvAW19tk9dMf0mw0_k69wur';

/* Every mutating endpoint the 5 panes can reach, read off index.php. api/report.generate is a GET
   but it writes report_export_logs, so it belongs in this list too. */
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

const PANES = [
    { tab: '#run-reports-tab', pane: '#run-reports-pane' },
    { tab: '#run-cash-tab', pane: '#run-cash-pane' },
    { tab: '#run-bank-account-tab', pane: '#run-bank-account-pane' },
    { tab: '#run-remittance-tab', pane: '#run-remittance-pane' },
    { tab: '#run-history-tab', pane: '#run-history-pane' },
];
// The 4 that hold a table -- "th[data-i18n] in 4 panes" means these; History has no table.
const TABLE_PANES = PANES.slice(0, 4);

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log('  PASS  ' + label); }
    else { failed++; console.log('  FAIL  ' + label + (extra !== undefined ? ' -- ' + extra : '')); }
}
function measured(label, value) {
    console.log('  MEASURED  ' + label + ' = ' + (typeof value === 'object' ? JSON.stringify(value) : value));
}

async function gotoRun(ctx, token, lang) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    if (lang) {
        await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
        await ctx.page.waitForTimeout(500);
    }
}
/* updateRunDetailTabVisibility() (detail.js) hides Cash/Bank/Remittance entirely when the run has
   nothing for them -- pre-existing behaviour this round does not change. A hidden tab is not a
   failure, it is a pane this cell cannot measure, so it is skipped and NAMED rather than clicked
   into a 30s timeout. */
async function openTab(page, tabSel) {
    const visible = await page.evaluate((s) => {
        const el = document.querySelector(s);
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }, tabSel);
    if (!visible) return false;
    await page.click(tabSel);
    await page.waitForTimeout(450);
    return true;
}

/* Per-pane shape, measured inside the page so one round trip covers the whole pane. */
async function panePicture(page, paneSel) {
    return page.$eval(paneSel, (pane) => {
        const vis = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
        const all = (sel) => Array.from(pane.querySelectorAll(sel));
        const rows = all('table > tbody > tr').filter((tr) => !tr.classList.contains('dt-empty-row'));
        const rowButtonCounts = rows.map((tr) => tr.querySelectorAll('button').length);
        // A "text button" is a <button> that renders words. An icon-only control (a circle action,
        // a close x) renders none, so it is not what rules.md 4's "no icon in a text button" is about.
        const textButtons = all('button').filter((b) => (b.textContent || '').trim().length > 0);
        const emptyStates = all('.empty-state');
        const visibleEmpty = emptyStates.filter(vis);
        const icon = visibleEmpty.length ? visibleEmpty[0].querySelector('.empty-state-icon') : null;
        return {
            emptyStateTotal: emptyStates.length,
            emptyStateVisible: visibleEmpty.length,
            emptyStateIconFontSize: icon ? getComputedStyle(icon).fontSize : null,
            emptyStateIconColor: icon ? getComputedStyle(icon).color : null,
            calloutVisible: all('.callout').filter(vis).length,
            legacyBanner: all('.reports-not-ready-banner').length,
            thWithI18n: all('th[data-i18n]').length,
            thTotal: all('th').length,
            outlineSuccess: all('.btn-outline-success').length,
            btnLight: all('.btn-light').length,
            textButtonsWithIcon: textButtons.filter((b) => b.querySelector('i')).length,
            textButtonCount: textButtons.length,
            rowCount: rows.length,
            maxButtonsPerRow: rowButtonCounts.length ? Math.max.apply(null, rowButtonCounts) : 0,
            primaryButtons: all('.btn-primary').length,
            colouredCircleActions: all('.btn-circle-action.text-primary, .btn-circle-action.text-success, .btn-circle-action.text-info').length,
            statCardsLegacy: all('.stat-card').length,
            statsShared: all('.stat').length,
            statusBadges: all('[data-badge="status"]').length,
            badgesWithoutMarker: all('.badge:not([data-badge])').length,
            disabledRowButtons: rows.reduce((n, tr) => n + tr.querySelectorAll('button[disabled]').length, 0),
            moneyCells: all('td.col-money').length,
        };
    });
}

/* ---------------- cell 1 ---------------- */
async function cell1() {
    console.log('\n[c1] fixture run (draft), 1400 th light');
    const ctx = await openContext({ sessionId: sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const pics = {};
    const skipped = [];
    for (const p of PANES) {
        if (!(await openTab(ctx.page, p.tab))) { skipped.push(p.pane); continue; }
        pics[p.pane] = await panePicture(ctx.page, p.pane);
    }
    measured('c1 tabs hidden by the run itself (not measurable)', skipped);
    for (const p of PANES) if (pics[p.pane]) measured(p.pane, pics[p.pane]);

    const reports = pics['#run-reports-pane'];
    check('reports: the bespoke not-ready banner is gone from this page', reports.legacyBanner === 0, reports.legacyBanner);
    check('reports: exactly 1 shared callout is showing instead', reports.calloutVisible === 1, reports.calloutVisible);
    // 4.2 -- a fixed catalogue of 3 report rows has nothing for a page-length select, an
    // "showing 1 to 3 of 3" line or a pagination bar to do. Search was already off below the
    // table's own searchThreshold; all four are counted so the number is on the record either way.
    const dtControls = await ctx.page.evaluate(() => ({
        length: document.querySelectorAll('#tb_run_reports_wrapper .dt-length').length,
        info: document.querySelectorAll('#tb_run_reports_wrapper .dt-info').length,
        paging: document.querySelectorAll('#tb_run_reports_wrapper .dt-paging').length,
        search: document.querySelectorAll('#tb_run_reports_wrapper .dt-search').length,
    }));
    measured('reports table DataTables controls', dtControls);
    check('reports: no length/info/paging control on a fixed 3-row catalogue',
        dtControls.length === 0 && dtControls.info === 0 && dtControls.paging === 0, JSON.stringify(dtControls));
    // 4.3 -- one neutral tile for every row, no per-report-type colour left.
    const tiles = await ctx.page.evaluate(() => Array.from(document.querySelectorAll('#run-reports-pane .rd-report-tile'))
        .map((t) => ({ cls: t.className, bg: getComputedStyle(t).backgroundColor })));
    measured('reports row tiles', tiles);
    check('reports: every tile is the same neutral swatch',
        tiles.length > 0 && new Set(tiles.map((t) => t.bg)).size === 1
        && tiles.every((t) => !/rd-report-tile-/.test(t.cls)), JSON.stringify(tiles.slice(0, 2)));
    check('reports: every row button is disabled on a draft run',
        reports.rowCount > 0 && reports.disabledRowButtons === reports.rowCount * reports.maxButtonsPerRow,
        reports.disabledRowButtons + ' of ' + reports.rowCount + 'x' + reports.maxButtonsPerRow);

    for (const sel of ['#run-cash-pane', '#run-bank-account-pane', '#run-remittance-pane']) {
        if (!pics[sel]) continue;
        check(sel + ': 1 visible shared empty-state on a draft run', pics[sel].emptyStateVisible === 1, pics[sel].emptyStateVisible);
        check(sel + ': its icon is the component 32px', pics[sel].emptyStateIconFontSize === '32px', pics[sel].emptyStateIconFontSize);
    }
    for (const p of TABLE_PANES) {
        if (!pics[p.pane]) continue;
        check(p.pane + ': no data-i18n left on a <th>', pics[p.pane].thWithI18n === 0, pics[p.pane].thWithI18n);
    }
    for (const p of PANES) {
        const pic = pics[p.pane];
        if (!pic) continue;
        check(p.pane + ': no btn-outline-success', pic.outlineSuccess === 0, pic.outlineSuccess);
        check(p.pane + ': no icon inside a text button', pic.textButtonsWithIcon === 0, pic.textButtonsWithIcon);
        check(p.pane + ': at most 3 buttons per row', pic.maxButtonsPerRow <= 3, pic.maxButtonsPerRow);
        check(p.pane + ': at most 1 primary button', pic.primaryButtons <= 1, pic.primaryButtons);
        check(p.pane + ': no colour class left on a circle action', pic.colouredCircleActions === 0, pic.colouredCircleActions);
        check(p.pane + ': no legacy .stat-card', pic.statCardsLegacy === 0, pic.statCardsLegacy);
    }
    const rep = ctx.report();
    measured('c1 report', rep);
    check('c1: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    check('c1: no page error', rep.pageErrors.length === 0, rep.pageErrors.join(' | '));
}

/* ---------------- cell 2 ---------------- */
async function cell2() {
    console.log('\n[c2] fixture run (draft), 430x932 th dark');
    const ctx = await openContext({ sessionId: sessionId, width: 430, height: 932, colorScheme: 'dark', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    // The app stamps `data-bs-theme` from the viewer's SAVED preference (employee 28 = light), so a
    // Chromium colorScheme alone leaves the page in light. 2026-09-21, 3e-2a round 1: this cell's own
    // inline setTheme() call moved to harness.js's applyAppTheme(), so every round applies a theme
    // one way and proves it the same way. Same behaviour, same blocked write-back -- employee 28's
    // row is untouched either way.
    const themeInfo = await applyAppTheme(ctx.page, 'dark');
    measured('c2 theme', themeInfo);
    check('c2: applyAppTheme really put the page in dark', themeInfo.ok === true, themeInfo.stamp);
    const faint = await ctx.page.evaluate(() => {
        const raw = getComputedStyle(document.documentElement).getPropertyValue('--c-text-faint').trim();
        const probe = document.createElement('span');
        probe.style.color = raw;
        document.body.appendChild(probe);
        const resolved = getComputedStyle(probe).color;
        probe.remove();
        return { raw: raw, resolved: resolved, theme: document.documentElement.getAttribute('data-bs-theme') };
    });
    measured('c2 --c-text-faint', faint);
    check('c2: the page really is in dark theme', faint.theme === 'dark', faint.theme);
    const skipped2 = [];
    for (const p of PANES) {
        if (!(await openTab(ctx.page, p.tab))) { skipped2.push(p.pane); continue; }
        const pic = await panePicture(ctx.page, p.pane);
        const overflow = await ctx.page.evaluate(() => ({ sw: document.body.scrollWidth, cw: document.body.clientWidth }));
        measured(p.pane + ' @430 dark', Object.assign({}, pic, overflow));
        check(p.pane + ': no horizontal page scroll at 430', overflow.sw <= overflow.cw, overflow.sw + ' > ' + overflow.cw);
        check(p.pane + ': no data-i18n on a <th>', pic.thWithI18n === 0, pic.thWithI18n);
        check(p.pane + ': no colour class on a circle action', pic.colouredCircleActions === 0, pic.colouredCircleActions);
        if (pic.emptyStateVisible > 0) {
            check(p.pane + ': empty-state icon is 32px', pic.emptyStateIconFontSize === '32px', pic.emptyStateIconFontSize);
            check(p.pane + ': empty-state icon takes the dark --c-text-faint', pic.emptyStateIconColor === faint.resolved,
                pic.emptyStateIconColor + ' vs ' + faint.resolved);
        }
    }
    measured('c2 tabs hidden by the run itself', skipped2);
    const rep = ctx.report();
    measured('c2 report', rep);
    check('c2: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cell 3 ---------------- */
async function cell3() {
    console.log('\n[c3] fixture run, 768 en light -- every converted string against en.json');
    const ctx = await openContext({ sessionId: sessionId, width: 768, height: 1024, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'en');
    const en = await ctx.page.evaluate(async () => {
        const res = await fetch(BASE_URL + '/public/lang/en.json');
        return res.json();
    });
    const skipped3 = [];
    for (const p of TABLE_PANES) {
        if (!(await openTab(ctx.page, p.tab))) { skipped3.push(p.pane); continue; }
        const heads = await ctx.page.$$eval(p.pane + ' th span[data-i18n]', (els) =>
            els.map((e) => ({ key: e.getAttribute('data-i18n'), text: (e.textContent || '').trim() })));
        measured(p.pane + ' th spans', heads);
        for (const h of heads) {
            check(p.pane + ' <th> "' + h.key + '" reads en.json', en[h.key] === h.text, '"' + h.text + '" vs "' + en[h.key] + '"');
        }
    }
    measured('c3 tabs hidden by the run itself', skipped3);
    for (const id of ['#btnExportRunBankAccountSummary', '#btnExportRunRemittance']) {
        const label = await ctx.page.$eval(id, (e) => (e.textContent || '').trim());
        const icons = await ctx.page.$eval(id, (e) => e.querySelectorAll('i').length);
        measured(id, { label: label, icons: icons });
        check(id + ' label is en.json export_excel', label === en['export_excel'], '"' + label + '" vs "' + en['export_excel'] + '"');
        check(id + ' carries no icon', icons === 0, icons);
    }
    const es = await ctx.page.$$eval('.empty-state [data-i18n]', (els) =>
        els.map((e) => ({ key: e.getAttribute('data-i18n'), text: (e.textContent || '').trim() })));
    measured('empty-state i18n lines', es);
    for (const e of es) check('empty-state "' + e.key + '" reads en.json', en[e.key] === e.text, '"' + e.text + '" vs "' + en[e.key] + '"');
    const cal = await ctx.page.$$eval('.callout [data-i18n]', (els) =>
        els.map((e) => ({ key: e.getAttribute('data-i18n'), text: (e.textContent || '').trim() })));
    measured('callout i18n lines', cal);
    for (const e of cal) check('callout "' + e.key + '" reads en.json', en[e.key] === e.text, '"' + e.text + '" vs "' + en[e.key] + '"');
    const rep = ctx.report();
    measured('c3 report', rep);
    check('c3: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cell 4 ---------------- */
async function cell4() {
    console.log('\n[c4] run 1015 (locked, read-only) -- tables against their own API responses');
    const ctx = await openContext({ sessionId: sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    const payloads = {};
    ctx.page.on('response', async (res) => {
        const u = res.url();
        const keys = ['payroll-run-cash-payment.list', 'payroll-run-employee-bank-account.list',
            'payroll-remittance.list', 'report.run-summary', 'payroll-run.get'];
        for (const key of keys) {
            if (u.indexOf(key) !== -1) { try { payloads[key] = await res.json(); } catch (e) { /* not json */ } }
        }
    });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');

    const expect = {
        '#run-cash-pane': (payloads['payroll-run-cash-payment.list'] || {}).data,
        '#run-bank-account-pane': (payloads['payroll-run-employee-bank-account.list'] || {}).data,
        '#run-remittance-pane': (payloads['payroll-remittance.list'] || {}).data,
    };
    const skipped4 = [];
    for (const p of PANES) {
        if (!(await openTab(ctx.page, p.tab))) { skipped4.push(p.pane); continue; }
        const pic = await panePicture(ctx.page, p.pane);
        measured(p.pane + ' @1015', pic);
        const exp = expect[p.pane];
        if (exp === undefined) continue;
        const list = Array.isArray(exp) ? exp : (exp && Array.isArray(exp.rows) ? exp.rows : null);
        if (list === null) { measured(p.pane + ' response shape', JSON.stringify(exp).slice(0, 200)); continue; }
        if (list.length === 0) {
            check(p.pane + ': 0 rows in the response -> the "nothing here" empty-state', pic.emptyStateVisible === 1, pic.emptyStateVisible);
            console.log('  NOTE  ' + p.pane + ': run 1015 has no rows for this tab -- this cell proves its empty state, NOT its table.');
        } else {
            check(p.pane + ': row count matches the response', pic.rowCount === list.length, pic.rowCount + ' vs ' + list.length);
            check(p.pane + ': no badge without the shared status marker', pic.badgesWithoutMarker === 0, pic.badgesWithoutMarker);
        }
    }
    measured('c4 tabs hidden by the run itself (table NOT provable there)', skipped4);
    const cash = payloads['payroll-run-cash-payment.list'];
    if (cash && cash.data && skipped4.indexOf('#run-cash-pane') === -1) {
        await openTab(ctx.page, '#run-cash-tab');
        const shown = await ctx.page.evaluate(() => {
            const c = document.querySelector('#runCashTotalCash');
            const b = document.querySelector('#runCashTotalBank');
            return {
                cash: c ? c.textContent : null, bank: b ? b.textContent : null,
                cashCls: c ? c.className : null,
            };
        });
        measured('cash stat cards', Object.assign({}, shown, { total_cash: cash.data.total_cash, total_bank: cash.data.total_bank }));
        check('cash stat card value id survived the move to stat-card.php', shown.cash !== null && shown.bank !== null);
        check('cash stat value carries .num (stat-card.php always does)', /(^|\s)num(\s|$)/.test(shown.cashCls || ''), shown.cashCls);
    }
    for (const sel of ['#run-cash-pane', '#run-remittance-pane']) {
        const money = await ctx.page.evaluate((s) => {
            const tds = Array.from(document.querySelectorAll(s + ' td.col-money'));
            return tds.slice(0, 3).map((td) => ({ cls: td.className, align: getComputedStyle(td).textAlign }));
        }, sel);
        measured(sel + ' money cells', money);
        for (const m of money) {
            check(sel + ': money cell right-aligned through .num', m.align === 'right' && /(^|\s)num(\s|$)/.test(m.cls), JSON.stringify(m));
        }
    }
    await openTab(ctx.page, '#run-history-tab');
    const runPayload = payloads['payroll-run.get'] || {};
    const logs = (runPayload.data || {}).audit_log;
    const items = await ctx.page.$$eval('#run_audit_timeline > *', (els) => els.length);
    measured('history', { timelineItems: items, logs: Array.isArray(logs) ? logs.length : null });
    if (Array.isArray(logs)) check('history: one timeline entry per log row', items === logs.length, items + ' vs ' + logs.length);
    const rep = ctx.report();
    measured('c4 report', rep);
    check('c4: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cell 5 ---------------- */
async function cell5() {
    console.log('\n[c5] run 1015 -- a search matching nothing gives the OTHER empty state');
    const ctx = await openContext({ sessionId: sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    const zero = await ctx.page.evaluate(() => getLangValue('zeroRecords'));
    // #tb_run_reports is not one of the 3 tables this round put on the `emptyState` option -- its
    // pane answers "not ready" with the callout instead -- so it has no empty-state row to render.
    const EMPTY_STATE_PANES = TABLE_PANES.filter((p) => p.pane !== '#run-reports-pane');
    let proved = 0;
    const unprovable = [];
    for (const p of EMPTY_STATE_PANES) {
        if (!(await openTab(ctx.page, p.tab))) { unprovable.push({ pane: p.pane, why: 'tab hidden by the run' }); continue; }
        const table = await ctx.page.$(p.pane + ' table');
        if (!table) { unprovable.push({ pane: p.pane, why: 'no table in the DOM' }); continue; }
        const id = await table.evaluate((t) => t.id);
        const state = await ctx.page.evaluate((tid) => {
            if (!(window.jQuery && jQuery.fn.dataTable.isDataTable('#' + tid))) return null;
            const dt = jQuery('#' + tid).DataTable();
            return { recordsTotal: dt.page.info().recordsTotal, searching: !!dt.settings()[0].oFeatures.bFilter };
        }, id);
        measured(p.pane + ' (' + id + ')', state);
        if (!state) { unprovable.push({ pane: p.pane, why: 'not a DataTable' }); continue; }
        // initSharedDataTable() sets `searching: rowCount > searchThreshold` -- a table under its
        // own threshold has DataTables' filter feature switched OFF entirely, so search() is a
        // no-op and the "narrowed to nothing" branch cannot be reached through the UI at all.
        if (!state.searching || state.recordsTotal <= 0) {
            unprovable.push({ pane: p.pane, id: id, recordsTotal: state.recordsTotal, searching: state.searching,
                why: 'search feature off below searchThreshold -- the filtered branch is unreachable on this data' });
            continue;
        }
        await ctx.page.evaluate((tid) => jQuery('#' + tid).DataTable().search('zzzz-no-such-row-zzzz').draw(), id);
        await ctx.page.waitForTimeout(600);
        const after = await ctx.page.$eval(p.pane + ' .dt-empty-cell', (td) => {
            const t = td.querySelector('.empty-state-title');
            return { title: t ? t.textContent : null, hasClear: !!td.querySelector('.empty-state-action') };
        });
        measured(p.pane + ' filtered-empty', after);
        check(p.pane + ': filtered empty-state title is zeroRecords, not the caller copy',
            (after.title || '').trim() === zero, '"' + after.title + '" vs "' + zero + '"');
        check(p.pane + ': it offers the Clear action', after.hasClear === true);
        proved++;
        await ctx.page.evaluate((tid) => jQuery('#' + tid).DataTable().search('').draw(), id);
        await ctx.page.waitForTimeout(300);
    }
    measured('c5 panes proved', proved);
    measured('c5 panes NOT provable on this dev DB', unprovable);
    if (proved === 0) {
        console.log('  NOTE  c5 proved NOTHING: every emptyState table on run 1015 sits under its own');
        console.log('  NOTE  searchThreshold, so DataTables has filtering switched off and the');
        console.log('  NOTE  "filtered to nothing" empty state cannot be triggered here. The branch is');
        console.log('  NOTE  the branch is app.js dtRenderEmptyState()s own, shared and exercised elsewhere --');
        console.log('  NOTE  what this round changed is only which config reaches it. NOT a pass.');
    }
    const rep = ctx.report();
    measured('c5 report', rep);
    check('c5: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cell 6 ---------------- */
async function cell6() {
    console.log('\n[c6] run 1015 -- the 2 GET-only modals');
    const ctx = await openContext({ sessionId: sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    const probe = async (sel) => ctx.page.evaluate((s) => {
        const m = document.querySelector(s);
        if (!m) return null;
        const btn = m.querySelector('.modal-footer button[data-bs-dismiss="modal"]');
        return {
            open: m.classList.contains('show'),
            closeClass: btn ? btn.className : null,
            closeHeight: btn ? Math.round(btn.getBoundingClientRect().height * 10) / 10 : null,
            thWithI18n: m.querySelectorAll('th[data-i18n]').length,
            btnLight: m.querySelectorAll('.btn-light').length,
        };
    }, sel);
    const results = {};

    if (!(await openTab(ctx.page, '#run-reports-tab'))) measured('c6 reports tab hidden', true);
    const histBtn = await ctx.page.$('#run-reports-pane .btn-report-history:not([disabled])');
    if (histBtn) {
        await histBtn.click();
        await ctx.page.waitForTimeout(1000);
        results['#reportHistoryModal'] = await probe('#reportHistoryModal');
        await ctx.page.evaluate(() => {
            const m = document.querySelector('#reportHistoryModal');
            const inst = m && window.bootstrap ? bootstrap.Modal.getInstance(m) : null;
            if (inst) inst.hide();
        });
        await ctx.page.waitForTimeout(600);
    }
    if (!(await openTab(ctx.page, '#run-remittance-tab'))) measured('c6 remittance tab hidden', true);
    const brkBtn = await ctx.page.$('#run-remittance-pane .btn-remittance-breakdown');
    if (brkBtn) {
        await brkBtn.click();
        await ctx.page.waitForTimeout(1000);
        results['#remittanceBreakdownModal'] = await probe('#remittanceBreakdownModal');
    }
    // A modal that never opened is still worth reading statically: its [Close] markup is in the view.
    for (const sel of ['#reportHistoryModal', '#remittanceBreakdownModal']) {
        if (!results[sel]) results[sel] = await probe(sel);
        measured(sel, results[sel]);
    }
    for (const sel of ['#reportHistoryModal', '#remittanceBreakdownModal']) {
        const info = results[sel];
        if (!info) continue;
        check(sel + ': [Close] is btn-outline-secondary (model: docs/design/components.php:1288)',
            /btn-outline-secondary/.test(info.closeClass || ''), info.closeClass);
        check(sel + ': no .btn-light left', info.btnLight === 0, info.btnLight);
        if (info.open) {
            check(sel + ': [Close] is 30.5px tall', info.closeHeight === 30.5, info.closeHeight);
        } else {
            console.log('  NOTE  ' + sel + ' never opened on run 1015 -- its height is not measurable here.');
        }
    }
    // Only the remittance breakdown modal's <th> row was in this round's scope (task item 4).
    // #reportHistoryModal's own headers were explicitly left alone -- measured, not asserted.
    check('#remittanceBreakdownModal: no data-i18n on a <th>',
        results['#remittanceBreakdownModal'] && results['#remittanceBreakdownModal'].thWithI18n === 0,
        results['#remittanceBreakdownModal'] ? results['#remittanceBreakdownModal'].thWithI18n : 'n/a');
    measured('#reportHistoryModal th[data-i18n] (OUT OF SCOPE this round, left as-is)',
        results['#reportHistoryModal'] ? results['#reportHistoryModal'].thWithI18n : 'n/a');

    const rep = ctx.report();
    measured('c6 report', rep);
    check('c6: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cells 7 / 8: the 2 converted confirms ---------------- */
async function confirmCell(name, routePath, trigger) {
    console.log('\n[' + name + '] ' + routePath + ' -- cancel then confirm');
    const ctx = await openContext({ sessionId: sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    await openTab(ctx.page, '#run-employee-tab');
    const lang = await ctx.page.evaluate(() => langData);

    const opened = await trigger(ctx.page);
    if (!opened) { check(name + ': the trigger exists on the fixture run', false, 'trigger not found'); return; }
    await ctx.page.waitForTimeout(700);
    const dlg = await ctx.page.evaluate(() => {
        const els = document.querySelectorAll('.swal2-container');
        const c = els[0];
        const pick = (s) => { const e = c ? c.querySelector(s) : null; return e ? e.textContent : null; };
        return { count: els.length, confirm: pick('.swal2-confirm'), cancel: pick('.swal2-cancel'), title: pick('.swal2-title') };
    });
    measured(name + ' dialog', dlg);
    check(name + ': exactly 1 dialog', dlg.count === 1, dlg.count);
    check(name + ': cancel button reads langData.cancel', (dlg.cancel || '').trim() === lang['cancel'], '"' + dlg.cancel + '" vs "' + lang['cancel'] + '"');
    check(name + ': confirm button is a real action label, not Yes',
        !!(dlg.confirm || '').trim() && (dlg.confirm || '').trim() !== (lang['yes'] || 'Yes'), dlg.confirm);

    await ctx.page.click('.swal2-cancel');
    await ctx.page.waitForTimeout(600);
    let rep = ctx.report();
    measured(name + ' after cancel', { blockedWrites: rep.blockedWrites, paths: rep.blockedWritePaths });
    check(name + ': cancel wrote nothing', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));

    await trigger(ctx.page);
    await ctx.page.waitForTimeout(700);
    await ctx.page.click('.swal2-confirm');
    await ctx.page.waitForTimeout(1200);
    rep = ctx.report();
    measured(name + ' after confirm', { blockedWrites: rep.blockedWrites, paths: rep.blockedWritePaths });
    const hits = rep.blockedWritePaths.filter((p) => p === routePath).length;
    check(name + ': confirm attempted ' + routePath + ' exactly once', hits === 1, hits + ' (all: ' + rep.blockedWritePaths.join(',') + ')');
    check(name + ': and nothing else', rep.blockedWrites === hits, rep.blockedWritePaths.join(','));
}

/* ---------------- cells 9-13 (2026-09-20, 3e-1 round 1) ----------------
   MOCK, and why. A SELECT over this dev DB first: `payroll_remittances` has 0 rows in the whole
   database, `payroll_run_employee_bank_accounts` has 0, and the only run whose cash list is longer
   than initSharedDataTable()'s own searchThreshold (run 461, 10 rows) is soft-deleted. So there is
   no run at all that can show a populated Remittance table, a populated Bank Account table, or a
   cash table with filtering switched on. These 5 cells therefore intercept the LIST endpoints on
   run 1015 with route.fulfill and answer them with rows whose shape is copied field-for-field from
   the model that really builds them:
     - PayrollRemittanceModel::listForRun()            app/models/PayrollRemittanceModel.php:227-251
     - PayrollRunEmployeeBankAccountModel::listForRun() app/models/PayrollRunEmployeeBankAccountModel.php:146-166
     - PayrollRunCashPaymentModel::listForRun()         app/models/PayrollRunCashPaymentModel.php:108-119
     - `remittance_count` on the run payload            app/models/PayrollRunModel.php:296
   Nothing is written: these are GET responses replaced in the browser, the database is never
   touched, and every mutating route stays in blockPaths as in every other cell. Every MEASURED line
   from these cells is prefixed MOCK so no number here is ever mistaken for real data. */

const MOCK_REMITTANCE_ROWS = [
    { id: 9001, run_id: 1015, destination_type: 'company', destination_id: null, fallback_employee_id: null, bank_account_id: 5, total_amount: '12500.00', status: 'pending', evidence_file_path: null, transferred_at: null, note: null, destination_account_name: null, bank_name_th: 'กสิกรไทย', bank_name_en: 'KBank', bank_account_name: 'บัญชีบริษัท', employee_count: 3, is_unspecified_company_account: false },
    { id: 9002, run_id: 1015, destination_type: 'third_party', destination_id: 11, fallback_employee_id: null, bank_account_id: null, total_amount: '4300.50', status: 'transferred', evidence_file_path: 'x.pdf', transferred_at: '2026-09-01 10:00:00', note: null, destination_account_name: 'สหกรณ์ออมทรัพย์', bank_name_th: 'ไทยพาณิชย์', bank_name_en: 'SCB', bank_account_name: null, employee_count: 2, is_unspecified_company_account: false },
    { id: 9003, run_id: 1015, destination_type: 'third_party', destination_id: 12, fallback_employee_id: null, bank_account_id: null, total_amount: '980.00', status: 'success', evidence_file_path: 'y.pdf', transferred_at: '2026-09-02 09:30:00', note: null, destination_account_name: 'กยศ.', bank_name_th: 'กรุงไทย', bank_name_en: 'KTB', bank_account_name: null, employee_count: 1, is_unspecified_company_account: false },
    { id: 9004, run_id: 1015, destination_type: 'employee', destination_id: null, fallback_employee_id: 159, bank_account_id: null, total_amount: '250.75', status: 'failed', evidence_file_path: null, transferred_at: null, note: 'บัญชีปลายทางถูกปิด', destination_account_name: null, bank_name_th: null, bank_name_en: null, bank_account_name: null, fallback_employee_no: 'EM009', fallback_name_th: 'ทดสอบ', fallback_surname_th: 'ระบบ', fallback_name_en: 'Test', fallback_surname_en: 'User', employee_count: 1, is_unspecified_company_account: false },
];
const MOCK_BANK_ROWS = ['override', 'employee_default', 'cycle', 'company_default'].map((source, i) => ({
    employee_id: 900 + i,
    employee_no: 'MK' + String(i + 1).padStart(3, '0'),
    name_th: 'ทดสอบ', surname_th: 'ที่ ' + (i + 1), name_en: 'Mock', surname_en: 'Row ' + (i + 1),
    profile_photo_path: null,
    department_name_th: null, department_name_en: null, position_name_th: null, position_name_en: null,
    bank_account_id: 5 + i, bank_account_name: 'บัญชี ' + (i + 1), bank_name_th: 'กสิกรไทย', bank_name_en: 'KBank',
    source: source, is_overridden: source === 'override',
}));
const MOCK_CASH_ROWS = Array.from({ length: 8 }, (_, i) => ({
    id: 8000 + i, run_id: 1015, employee_id: 800 + i,
    employee_no: 'CK' + String(i + 1).padStart(3, '0'),
    name_th: 'เงินสด', surname_th: 'แถว ' + (i + 1), name_en: 'Cash', surname_en: 'Row ' + (i + 1),
    amount: String((1000 + i * 137) + '.00'),
    status: i % 2 === 0 ? 'unpaid' : 'paid',
    paid_at: i % 2 === 0 ? null : '2026-09-03 11:00:00',
    paid_by: i % 2 === 0 ? null : 28,
    paid_by_name_th: i % 2 === 0 ? null : 'ผู้ดูแล ระบบ', paid_by_name_en: i % 2 === 0 ? null : 'Admin User',
}));

/* One place that installs the interception, so every cell below gets the same answers. `only` picks
   which lists to replace; anything not named is left to the real server. */
async function mockContext(opts) {
    const o = opts || {};
    const ctx = await openContext({
        sessionId: sessionId, width: o.width || 1400, height: o.height || 950,
        colorScheme: o.colorScheme, blockPaths: WRITE_PATHS,
    });
    // The run payload itself is fetched for real and only PATCHED: `remittance_count` is what makes
    // the Remittance tab visible at all (detail.js updateRunDetailTabVisibility()), and a detail
    // row's payment_method_code is what makes the Bank Account tab visible.
    await ctx.context.route('**/api/payroll-run.get*', async (route) => {
        const res = await route.fetch();
        let body;
        try { body = await res.json(); } catch (e) { return route.fulfill({ response: res }); }
        if (body && body.data) {
            if (o.remittance) body.data.remittance_count = MOCK_REMITTANCE_ROWS.length;
            if (o.bank !== undefined && Array.isArray(body.data.details)) {
                body.data.details.forEach((d) => { d.payment_method_code = 'transfer'; });
            }
            if (o.cash && Array.isArray(body.data.details)) {
                body.data.details.forEach((d) => { d.payment_method_code = 'cash'; });
            }
        }
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
    const json = (data) => ({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: true, data: data }) });
    if (o.remittance) {
        await ctx.context.route('**/api/payroll-remittance.list*', (route) => route.fulfill(json(MOCK_REMITTANCE_ROWS)));
        await ctx.context.route('**/api/payroll-remittance.items*', (route) => route.fulfill(json([
            { id: 1, remittance_id: 9001, employee_id: 159, item_code: 'TH_SSO', amount: '750.00', employee_no: 'EM009', name_th: 'ทดสอบ', surname_th: 'ระบบ', name_en: 'Test', surname_en: 'User' },
        ])));
    }
    if (o.bank !== undefined) {
        await ctx.context.route('**/api/payroll-run-employee-bank-account.list*', (route) => route.fulfill(json(o.bank)));
    }
    if (o.cash) {
        await ctx.context.route('**/api/payroll-run-cash-payment.list*', (route) => route.fulfill(json({
            rows: MOCK_CASH_ROWS, total_cash: 9096, total_bank: 0,
        })));
    }
    return ctx;
}

/* ---------------- cell 9: the Remittance table ---------------- */
async function cell9() {
    console.log('\n[c9] MOCK run 1015 -- the Remittance table, one row per status in the map');
    const ctx = await mockContext({ remittance: true });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    if (!(await openTab(ctx.page, '#run-remittance-tab'))) {
        check('c9: the Remittance tab is visible once remittance_count > 0', false, 'still hidden');
        return;
    }
    const pic = await panePicture(ctx.page, '#run-remittance-pane');
    measured('MOCK #run-remittance-pane', pic);
    check('c9: row count = the mocked list length', pic.rowCount === MOCK_REMITTANCE_ROWS.length, pic.rowCount + ' vs ' + MOCK_REMITTANCE_ROWS.length);
    check('c9: one shared status badge per row', pic.statusBadges === MOCK_REMITTANCE_ROWS.length, pic.statusBadges);
    check('c9: no badge without the shared marker', pic.badgesWithoutMarker === 0, pic.badgesWithoutMarker);
    check('c9: at most 3 buttons per row (transferred row has exactly 3)', pic.maxButtonsPerRow === 3, pic.maxButtonsPerRow);

    const seen = await ctx.page.$$eval('#run-remittance-pane tbody [data-badge="status"]', (els) =>
        els.map((e) => ({ key: e.getAttribute('data-i18n'), text: (e.textContent || '').trim(), cls: e.className })));
    const lang = await ctx.page.evaluate(() => langData);
    measured('MOCK remittance badges', seen);
    for (const st of ['pending', 'transferred', 'success', 'failed']) {
        const key = 'remittance_status_' + st;
        const hit = seen.find((b) => b.key === key);
        check('c9: status "' + st + '" renders once, text from langData', !!hit && hit.text === lang[key],
            hit ? '"' + hit.text + '" vs "' + lang[key] + '"' : 'missing');
    }
    const money = await ctx.page.evaluate(() => Array.from(document.querySelectorAll('#run-remittance-pane td.col-money'))
        .map((td) => ({ cls: td.className, align: getComputedStyle(td).textAlign })));
    measured('MOCK remittance money cells', money.slice(0, 2));
    check('c9: money cells go through the shared money column', money.length === MOCK_REMITTANCE_ROWS.length
        && money.every((m) => m.align === 'right' && /(^|\s)num(\s|$)/.test(m.cls)), JSON.stringify(money.slice(0, 2)));

    await ctx.page.click('#run-remittance-pane .btn-remittance-breakdown');
    await ctx.page.waitForTimeout(900);
    const modal = await ctx.page.evaluate(() => {
        const m = document.querySelector('#remittanceBreakdownModal');
        const btn = m.querySelector('.modal-footer button[data-bs-dismiss="modal"]');
        return { open: m.classList.contains('show'), closeClass: btn ? btn.className : null, thWithI18n: m.querySelectorAll('th[data-i18n]').length };
    });
    measured('MOCK #remittanceBreakdownModal', modal);
    check('c9: breakdown modal really opened', modal.open === true);
    check('c9: its [Close] is btn-outline-secondary', /btn-outline-secondary/.test(modal.closeClass || ''), modal.closeClass);
    check('c9: no data-i18n on a <th> inside it', modal.thWithI18n === 0, modal.thWithI18n);
    const rep = ctx.report();
    measured('c9 report', rep);
    check('c9: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cell 10: the Bank Account table, and itsempty state ---------------- */
async function cell10() {
    console.log('\n[c10] MOCK run 1015 -- the Bank Account table, then the same tab with an empty list');
    const ctx = await mockContext({ bank: MOCK_BANK_ROWS });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    if (!(await openTab(ctx.page, '#run-bank-account-tab'))) {
        check('c10: the Bank Account tab is visible once a detail row pays by transfer', false, 'still hidden');
        return;
    }
    const pic = await panePicture(ctx.page, '#run-bank-account-pane');
    measured('MOCK #run-bank-account-pane', pic);
    check('c10: row count = the mocked list length', pic.rowCount === MOCK_BANK_ROWS.length, pic.rowCount + ' vs ' + MOCK_BANK_ROWS.length);
    check('c10: at most 2 buttons per row', pic.maxButtonsPerRow <= 2, pic.maxButtonsPerRow);
    check('c10: no badge left in this table (Source is plain text now, rules.md 5)', pic.badgesWithoutMarker === 0 && pic.statusBadges === 0,
        'marker-less ' + pic.badgesWithoutMarker + ' / status ' + pic.statusBadges);
    const lang = await ctx.page.evaluate(() => langData);
    const sources = await ctx.page.$$eval('#run-bank-account-pane tbody tr', (rows) =>
        rows.map((tr) => (tr.children[3] ? (tr.children[3].textContent || '').trim() : null)));
    measured('MOCK bank source cells', sources);
    for (const s of ['override', 'employee_default', 'cycle', 'company_default']) {
        const want = lang['bank_account_source_' + s];
        check('c10: source "' + s + '" reads langData', sources.indexOf(want) !== -1, want + ' not in ' + JSON.stringify(sources));
    }
    await closeAll();

    // Same tab, empty list -> the "nothing here at all" empty state, in th then en.
    for (const lng of ['th', 'en']) {
        const ctx2 = await mockContext({ bank: [] });
        await gotoRun(ctx2, LOCKED_RUN_TOKEN, lng);
        if (!(await openTab(ctx2.page, '#run-bank-account-tab'))) { check('c10/' + lng + ': tab visible', false); continue; }
        const es = await ctx2.page.$eval('#run-bank-account-pane .dt-empty-cell', (td) => {
            const t = td.querySelector('.empty-state-title');
            return { title: t ? t.textContent.trim() : null, hasClear: !!td.querySelector('.empty-state-action') };
        });
        const want = await ctx2.page.evaluate(() => getLangValue('bank_account_no_employees'));
        measured('MOCK bank empty-state @' + lng, Object.assign({}, es, { expected: want }));
        check('c10/' + lng + ': empty title is the NEW bank_account_no_employees key', es.title === want, '"' + es.title + '" vs "' + want + '"');
        check('c10/' + lng + ': it is the "nothing here" variant, no Clear action', es.hasClear === false);
        const rep2 = ctx2.report();
        check('c10/' + lng + ': nothing was written', rep2.blockedWrites === 0, rep2.blockedWritePaths.join(','));
    }
}

/* ---------------- cell 11: filtered-empty vs really-empty ---------------- */
async function cell11() {
    console.log('\n[c11] MOCK run 1015 -- 8 cash rows, so search is on and the OTHER empty state is reachable');
    const ctx = await mockContext({ cash: true });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    if (!(await openTab(ctx.page, '#run-cash-tab'))) { check('c11: cash tab visible', false); return; }
    const state = await ctx.page.evaluate(() => {
        const dt = jQuery('#tb_run_cash').DataTable();
        return { recordsTotal: dt.page.info().recordsTotal, searching: !!dt.settings()[0].oFeatures.bFilter };
    });
    measured('MOCK cash table', state);
    check('c11: 8 rows put the table over searchThreshold, so filtering is ON', state.searching === true && state.recordsTotal === MOCK_CASH_ROWS.length,
        JSON.stringify(state));
    const callerCopy = await ctx.page.evaluate(() => getLangValue('no_cash_payments'));
    await ctx.page.evaluate(() => jQuery('#tb_run_cash').DataTable().search('zzzz-no-such-row-zzzz').draw());
    await ctx.page.waitForTimeout(600);
    const filtered = await ctx.page.$eval('#run-cash-pane .dt-empty-cell', (td) => {
        const t = td.querySelector('.empty-state-title');
        const x = td.querySelector('.empty-state-text');
        return { title: t ? t.textContent.trim() : null, text: x ? x.textContent.trim() : null, hasClear: !!td.querySelector('.empty-state-action') };
    });
    const zero = await ctx.page.evaluate(() => getLangValue('zeroRecords'));
    measured('MOCK cash filtered-empty', Object.assign({}, filtered, { callerCopy: callerCopy, zeroRecords: zero }));
    check('c11: the filtered empty state says zeroRecords', filtered.title === zero, '"' + filtered.title + '" vs "' + zero + '"');
    check('c11: and it offers Clear', filtered.hasClear === true);
    check('c11: it is a DIFFERENT message from the caller own "nothing here" copy', filtered.title !== callerCopy,
        '"' + filtered.title + '" vs "' + callerCopy + '"');
    const rep = ctx.report();
    measured('c11 report', rep);
    check('c11: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cell 12: no data-i18n left on a <th> anywhere on the page ---------------- */
async function cell12() {
    console.log('\n[c12] fixture run -- th[data-i18n] across the WHOLE page, join picker opened');
    const ctx = await openContext({ sessionId: sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    // Scope: this round owns app/views/payroll/detail.php. Everything that file renders lives under
    // #runDetailTabsContent, the page header, or one of ITS OWN modals; the rest of the document is
    // app/views/layout/modals.php, a global include on every page of the app (47 <th data-i18n>
    // there, out of scope -- see BACKLOG). Both numbers are reported, only the owned one asserted.
    const DETAIL_OWNED = ['#runDetailTabsContent', '#runDetailBreakdownModal', '#reportHistoryModal',
        '#reportPreviewModal', '#remittanceBreakdownModal', '#remittanceMarkTransferredModal',
        '#remittanceMarkFailedModal', '#bankAccountAssignModal', '#rawSyncDataModal',
        '#joinEmployeesModal', '#runApproveModal', '#runRejectModal', '#runRequestInfoModal',
        '#runMarkPaidModal', '#runTimelineModal', '#employeeCommentModal'];
    const count = async () => ctx.page.evaluate((sels) => ({
        owned: sels.reduce((n, s) => n + document.querySelectorAll(s + ' th[data-i18n]').length, 0),
        page: document.querySelectorAll('th[data-i18n]').length,
        globalModals: document.querySelectorAll('#payrollRunModal th[data-i18n]').length,
    }), DETAIL_OWNED);
    const before = await count();
    measured('th[data-i18n] before opening any modal', before);
    check('c12: 0 in everything payroll/detail.php owns', before.owned === 0, before.owned);
    // #joinEmployeesModal is opened by a button whose own load is a POST
    // (api/payroll-run.manual-employee-options) -- a READ that happens to use POST, not a write, and
    // it is not in WRITE_PATHS. Opened here so its own <thead> is measured in the live DOM.
    const joinBtn = await ctx.page.$('#btnJoinEmployees');
    let opened = false;
    if (joinBtn && await joinBtn.isVisible()) {
        await joinBtn.click();
        await ctx.page.waitForTimeout(1400);
        opened = await ctx.page.evaluate(() => !!document.querySelector('#joinEmployeesModal.show'));
    }
    measured('#joinEmployeesModal opened', opened);
    const after = await count();
    const detail = await ctx.page.evaluate(() => ({
        join: document.querySelectorAll('#joinEmployeesModal th[data-i18n]').length,
        joinThTotal: document.querySelectorAll('#joinEmployeesModal th').length,
        historyModal: document.querySelectorAll('#reportHistoryModal th[data-i18n]').length,
    }));
    measured('th[data-i18n] after', Object.assign({}, after, detail));
    check('c12: still 0 in detail.php-owned markup with the join picker in the DOM', after.owned === 0, after.owned);
    check('c12: 0 inside #joinEmployeesModal itself', detail.join === 0, detail.join);
    check('c12: 0 inside #reportHistoryModal itself', detail.historyModal === 0, detail.historyModal);
    console.log('  NOTE  the ' + after.page + ' left page-wide are ALL app/views/layout/modals.php, a global');
    console.log('  NOTE  include on every page of the app -- out of this round scope, see BACKLOG.');
    const rep = ctx.report();
    measured('c12 report', rep);
    check('c12: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

/* ---------------- cell 13: every pane starts where the tab bar starts ---------------- */
async function cell13() {
    console.log('\n[c13] pane padding -- 1400 th light, then 430 th dark');
    const ALL_PANES = [{ tab: '#run-details-tab', pane: '#run-details-pane' },
        { tab: '#run-employee-tab', pane: '#run-employee-pane' }].concat(PANES);
    for (const view of [{ w: 1400, h: 950, dark: false }, { w: 430, h: 932, dark: true }]) {
        const label = view.w + (view.dark ? ' dark' : ' light');
        // Bank/Remittance are only reachable with the same mock c9/c10 use.
        const ctx = await mockContext({ width: view.w, height: view.h, remittance: true, bank: MOCK_BANK_ROWS });
        await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
        if (view.dark) {
            await ctx.page.evaluate(() => { if (typeof setTheme === 'function') setTheme('dark'); });
            await ctx.page.waitForTimeout(600);
        }
        const frame = await ctx.page.evaluate(() => {
            const t = document.querySelector('#runDetailTabs').getBoundingClientRect();
            const c = getComputedStyle(document.querySelector('#runDetailTabsContent'));
            return { left: Math.round(t.left * 10) / 10, right: Math.round(t.right * 10) / 10, bottom: Math.round(t.bottom * 10) / 10,
                tabContentPadL: c.paddingLeft, tabContentPadR: c.paddingRight, tabContentMarginTop: c.marginTop };
        });
        measured('c13 @' + label + ' frame', frame);
        check('c13 @' + label + ': .tab-content has no side padding',
            frame.tabContentPadL === '0px' && frame.tabContentPadR === '0px', frame.tabContentPadL + '/' + frame.tabContentPadR);
        const gaps = [];
        const skipped = [];
        for (const p of ALL_PANES) {
            if (!(await openTab(ctx.page, p.tab))) { skipped.push(p.pane); continue; }
            const m = await ctx.page.evaluate((sel) => {
                const pane = document.querySelector(sel);
                const cs = getComputedStyle(pane);
                const r = (el) => { const b = el.getBoundingClientRect(); return { l: Math.round(b.left * 10) / 10, r: Math.round(b.right * 10) / 10, t: Math.round(b.top * 10) / 10 }; };
                const first = [...pane.children].find((e) => e.offsetParent !== null && e.getBoundingClientRect().height > 0);
                const tbl = pane.querySelector('table');
                const anchor = [...pane.querySelectorAll('.callout, .empty-state, .detail-section, .apv-history-timeline, .filter-bar')]
                    .find((e) => e.offsetParent !== null && e.getBoundingClientRect().width > 0) || null;
                return {
                    padL: cs.paddingLeft, padR: cs.paddingRight, paneTop: r(pane).t,
                    first: first ? r(first) : null, table: tbl ? r(tbl) : null, anchor: anchor ? r(anchor) : null,
                };
            }, p.pane);
            measured('c13 @' + label + ' ' + p.pane, m);
            check('c13 @' + label + ' ' + p.pane + ': pane has no side padding',
                m.padL === '0px' && m.padR === '0px', m.padL + '/' + m.padR);
            if (m.first) {
                check('c13 @' + label + ' ' + p.pane + ': first visible block starts at the tab bar left',
                    Math.abs(m.first.l - frame.left) <= 0.5, m.first.l + ' vs ' + frame.left);
                check('c13 @' + label + ' ' + p.pane + ': ...and ends at its right',
                    Math.abs(m.first.r - frame.right) <= 0.5, m.first.r + ' vs ' + frame.right);
                gaps.push({ pane: p.pane, paneGap: Math.round((m.paneTop - frame.bottom) * 10) / 10,
                    firstChildGap: Math.round((m.first.t - frame.bottom) * 10) / 10 });
            }
            if (m.table && m.anchor) {
                check('c13 @' + label + ' ' + p.pane + ': <table> left == the block beside it',
                    Math.abs(m.table.l - m.anchor.l) <= 0.5, m.table.l + ' vs ' + m.anchor.l);
            }
            const ov = await ctx.page.evaluate(() => ({ sw: document.body.scrollWidth, cw: document.body.clientWidth }));
            check('c13 @' + label + ' ' + p.pane + ': no horizontal page scroll', ov.sw <= ov.cw, ov.sw + ' > ' + ov.cw);
        }
        measured('c13 @' + label + ' tab->content gap per pane', gaps);
        measured('c13 @' + label + ' panes skipped', skipped);
        // The PANE's own top is what "the gap between the tab bar and the content" means -- it is
        // #runDetailTabsContent's margin-top and nothing else. A pane's first CHILD can sit lower
        // when that child brings its own margin (a DataTables control row's .mt-2, a .mb-3 wrapper);
        // that is the child's spacing, not the pane's, so it is reported, never asserted equal.
        const uniq = [...new Set(gaps.map((g) => g.paneGap))];
        check('c13 @' + label + ': every pane starts the same distance below the tab bar',
            uniq.length === 1 && uniq[0] === 16, JSON.stringify(uniq));
        const rep = ctx.report();
        measured('c13 @' + label + ' report', rep);
        check('c13 @' + label + ': nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
        await closeAll();
    }
}

/* ---------------- cell 14: the run-level banners above the tab bar ---------------- */
/* Run 29685 is a REAL run in this dev DB that carries both conditions at once: has_validation_errors
   = 1 and sync_process_id = 191 (so syncMissingEmployees() has something to report). It is opened
   read-only with every write route blocked, and its auto_recalculate is 0, so nothing recalculates
   on load either. No mock is needed for this cell. */
const BANNER_RUN_TOKEN = process.argv[4] || null;
async function cell14() {
    console.log('\n[c14] run-level banners -- callout, not a solid .alert tile');
    if (!BANNER_RUN_TOKEN) {
        check('c14: a run token with has_validation_errors=1 was supplied', false, 'pass it as argv[4]');
        return;
    }
    const ctx = await openContext({ sessionId: sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, BANNER_RUN_TOKEN, 'th');
    await ctx.page.waitForTimeout(900);
    const IDS = ['#nextStepBanner', '#validationErrorsBanner', '#syncMissingEmployeesBanner',
        '#mergeTargetBanner', '#mergeTargetWaitingBanner'];
    const shot = await ctx.page.evaluate((ids) => {
        const head = document.querySelector('#runDetailTabs').parentElement;
        const tabs = document.querySelector('#runDetailTabs').getBoundingClientRect();
        const vis = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
        const out = { alertsInHead: head.querySelectorAll('.alert').length, calloutsVisible: 0, boxes: {},
            tabsLeft: Math.round(tabs.left * 10) / 10, tabsRight: Math.round(tabs.right * 10) / 10 };
        for (const id of ids) {
            const el = document.querySelector(id);
            if (!el) { out.boxes[id] = null; continue; }
            const b = el.getBoundingClientRect();
            const v = vis(el);
            if (v && el.classList.contains('callout')) out.calloutsVisible++;
            out.boxes[id] = { visible: v, cls: el.className,
                left: v ? Math.round(b.left * 10) / 10 : null, right: v ? Math.round(b.right * 10) / 10 : null,
                icons: el.querySelectorAll('i').length,
                text: v ? (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60) : null };
        }
        return out;
    }, IDS);
    measured('c14 header banners', shot);
    check('c14: no solid .alert tile left anywhere above the tab bar', shot.alertsInHead === 0, shot.alertsInHead);
    const expected = IDS.filter((id) => shot.boxes[id] && shot.boxes[id].visible).length;
    check('c14: every visible box in that region is a callout', shot.calloutsVisible === expected,
        shot.calloutsVisible + ' of ' + expected);
    check('c14: this run really shows the red validation banner',
        !!(shot.boxes['#validationErrorsBanner'] && shot.boxes['#validationErrorsBanner'].visible));
    for (const id of IDS) {
        const b = shot.boxes[id];
        if (!b || !b.visible) continue;
        check('c14 ' + id + ': starts at the tab bar left', Math.abs(b.left - shot.tabsLeft) <= 0.5, b.left + ' vs ' + shot.tabsLeft);
        check('c14 ' + id + ': ends at the tab bar right', Math.abs(b.right - shot.tabsRight) <= 0.5, b.right + ' vs ' + shot.tabsRight);
        check('c14 ' + id + ': carries a callout tone class', /callout-(primary|success|warning|danger|neutral)/.test(b.cls), b.cls);
    }
    // The one button that lives inside a banner: neutral, btn-sm, opens its list.
    const btn = await ctx.page.$('#syncMissingEmployeesViewBtn');
    if (btn && await btn.isVisible()) {
        const bcls = await btn.evaluate((e) => e.className);
        measured('c14 #syncMissingEmployeesViewBtn', bcls);
        check('c14: the View List button is btn-sm neutral', /btn-sm/.test(bcls) && /btn-outline-secondary/.test(bcls), bcls);
        await btn.click();
        await ctx.page.waitForTimeout(700);
        const dlgs = await ctx.page.evaluate(() => document.querySelectorAll('.swal2-container').length);
        measured('c14 dialogs after View List', dlgs);
        check('c14: it opens exactly 1 dialog', dlgs === 1, dlgs);
        await ctx.page.keyboard.press('Escape');
        await ctx.page.waitForTimeout(400);
    } else {
        console.log('  NOTE  c14: the sync-missing banner is not showing on this run -- its button is not measurable here.');
    }
    const rep = ctx.report();
    measured('c14 report', rep);
    check('c14: nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
}

async function main() {
    try {
        await cell1();
        await cell2();
        await cell3();
        await cell4();
        await cell5();
        await cell6();
        await confirmCell('c7 bulk verify', '/api/payroll-run.employee-verify.bulk', async (page) => {
            const boxes = await page.$$('#run-employee-pane tbody input[type="checkbox"]');
            if (!boxes.length) return false;
            for (const b of boxes) { if (!(await b.isChecked())) await b.check(); }
            await page.waitForTimeout(400);
            const btn = await page.$('#btnBulkVerify');
            if (!btn || !(await btn.isVisible())) return false;
            await btn.click();
            return true;
        });
        await confirmCell('c8 single verify', '/api/payroll-run.employee-verify.save', async (page) => {
            // verifyLockButtonsRd() renders the verify control as a badge dropdown (rules.md 7), so
            // .btn-verify-employee is inside a closed <ul class="dropdown-menu"> until the badge is
            // opened -- clicking it directly waits forever on an invisible element.
            const toggle = await page.$('#run-employee-pane tbody [data-bs-toggle="dropdown"]');
            if (!toggle) return false;
            await toggle.click();
            await page.waitForTimeout(400);
            const item = await page.$('#run-employee-pane tbody .dropdown-menu.show .btn-verify-employee');
            if (!item) return false;
            await item.click();
            return true;
        });
        await cell9();
        await cell10();
        await cell11();
        await cell12();
        await cell13();
        await cell14();
    } finally {
        await closeAll();
    }
    console.log('\nPassed: ' + passed + ', Failed: ' + failed);
    if (failed > 0) process.exitCode = 1;
}
main();
