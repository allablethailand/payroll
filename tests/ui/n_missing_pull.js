/**
 * Round n measurement: "ดึงพนักงานที่ไม่อยู่ใน Sync เข้ารอบ" -- the sync-missing banner's own button now
 * opens the Join Employees picker in `missing` mode instead of a read-only Swal list, and that mode
 * carries a per-row pull button, its own title/callout/primary label and its own empty state.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/n_missing_pull.js
 *
 * Session, run token and the 2 deliberately-missing employee ids all come from
 * tests/ui/.last-session.json, written by:  php tests/ui/mksession.php --with-sync
 * This script NEVER creates or repairs that file -- if it isn't there it stops and says what to run,
 * because guessing a fixture is how a round ends up measuring a run it does not understand.
 *
 * Writes nothing: /api/payroll-run.join-employees is blocked at the context (n1-n8), and the 2 cells
 * that need to see what happens AFTER a successful join (n9/n10) fulfil it from a page route, which
 * Playwright resolves before the context route, so the request never leaves the browser either way.
 *
 * 10 cells: n1-n5 + n8-n10 at 1400 th light (logic), n6 at 430 th dark, n7 at 768 en.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const { openContext, closeAll, ensureCellTheme } = require('./harness');

/* ---------------- fixture ---------------- */
const STATE_PATH = path.join(__dirname, '.last-session.json');
if (!fs.existsSync(STATE_PATH)) {
    throw new Error('tests/ui/.last-session.json is not there, and this round does not create it. Run:\n'
        + '  php tests/ui/mksession.php --with-sync\n'
        + '  (on its own -- --with-sync refuses to combine with --with-calc-errors, mksession.php:389)');
}
const STATE = JSON.parse(fs.readFileSync(STATE_PATH, 'utf8'));
const MISSING_IDS = Array.isArray(STATE.sync_missing_employee_ids) ? STATE.sync_missing_employee_ids : null;
if (!MISSING_IDS || MISSING_IDS.length === 0) {
    throw new Error('tests/ui/.last-session.json has no sync_missing_employee_ids -- that fixture only exists\n'
        + 'on a --with-sync session. Re-run:  php tests/ui/mksession.php --with-sync');
}
const sessionId = STATE.session_id;
const runToken = STATE.token;

/* Every expected string is read out of the real lang files at run time -- nothing this round asserts
   is typed twice, so a copy edit moves the expectation with it instead of breaking the round. */
const LANG_DIR = path.join(__dirname, '..', '..', 'public', 'lang');
const LANG = {
    th: JSON.parse(fs.readFileSync(path.join(LANG_DIR, 'th.json'), 'utf8')),
    en: JSON.parse(fs.readFileSync(path.join(LANG_DIR, 'en.json'), 'utf8')),
};
const fill = (tpl, vals) => Object.keys(vals).reduce((s, k) => s.split('{' + k + '}').join(String(vals[k])), tpl);

const JOIN_PATH = '/api/payroll-run.join-employees';
const BLOCK = [JOIN_PATH];
const OPTIONS_PATH = '/api/payroll-run.manual-employee-options';
const BANNER_PATH = '/api/payroll-run.sync-missing-employees';

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}
function measured(label, value) {
    console.log(`  MEASURED  ${label} = ${typeof value === 'object' ? JSON.stringify(value) : value}`);
}

/* ---------------- colour helpers (same WCAG maths m3e2a/m3e2b use) ---------------- */
function parseRgb(s) {
    const m = /rgba?\(([^)]+)\)/.exec(s || '');
    if (!m) return null;
    const p = m[1].split(',').map((x) => parseFloat(x.trim()));
    return { r: p[0], g: p[1], b: p[2] };
}
function relativeLuminance(c) {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
}
function contrastRatio(fg, bg) {
    const a = parseRgb(fg);
    const b = parseRgb(bg);
    if (!a || !b) return null;
    const l1 = relativeLuminance(a);
    const l2 = relativeLuminance(b);
    return Math.round(((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)) * 100) / 100;
}

/* ---------------- shared page driving ---------------- */
async function openRun(ctx, opts) {
    await ctx.context.addInitScript((l) => {
        try { localStorage.setItem('preferred_language', l); } catch (e) { /* private mode */ }
    }, opts.lang);
    await ctx.page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(800);
    // A cell named "dark" must really be dark before anything colour-related is measured.
    if (!await ensureCellTheme(ctx.page, opts.colorScheme || 'light', { label: opts.label, when: 'first load', check, log: console.log })) {
        return false;
    }
    await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, opts.lang);
    await ctx.page.waitForTimeout(400);
    return true;
}

/** Opens the picker the way a real user would -- `banner` = the sync-missing callout's own button
 *  (missing mode), `toolbar` = the employee table's own Add button (the pre-existing all mode). */
async function openPicker(page, how) {
    const sel = how === 'banner' ? '#syncMissingEmployeesViewBtn' : '#btnJoinEmployees';
    if (how !== 'banner') {
        // #btnJoinEmployees is injected into the employee table's own toolbar, and that table lives
        // in a tab that is NOT the page's default one (measured: `run-details-pane` is active on
        // load). A real user opens that tab first -- so does this, rather than reaching past the UI
        // with a scripted click on something nobody can see yet.
        await page.click('#run-employee-tab');
        await page.waitForSelector('#run-employee-pane.active', { timeout: 15000 });
        await page.waitForTimeout(800);
    }
    await page.waitForSelector(sel, { state: 'visible', timeout: 30000 });
    await page.click(sel);
    await page.waitForSelector('#joinEmployeesModal.show', { timeout: 15000 });
    await page.waitForTimeout(1500);
}

/** One read of everything this round cares about, straight off the live DOM. */
function readPicker(page) {
    return page.evaluate(() => {
        const txt = (el) => (el ? el.textContent.trim().replace(/\s+/g, ' ') : null);
        const rows = Array.from(document.querySelectorAll('#tb_join_employees tbody tr'))
            .filter((r) => r.querySelector('.join-emp-checkbox'));
        const label = document.querySelector('#btnJoinSelectedLabel');
        const callout = document.querySelector('#joinEmployeesMissingCallout');
        const swal = document.querySelector('.swal2-container');
        return {
            open: !!document.querySelector('#joinEmployeesModal.show'),
            swalOpen: !!swal,
            swalText: swal ? swal.innerText.replace(/\s+/g, ' ').trim() : null,
            title: txt(document.querySelector('#joinEmployeesModalTitleText')),
            titleKey: (document.querySelector('#joinEmployeesModalTitleText') || {}).getAttribute
                ? document.querySelector('#joinEmployeesModalTitleText').getAttribute('data-i18n') : null,
            calloutShown: !!callout && !callout.classList.contains('d-none'),
            calloutText: txt(callout),
            primaryLabel: txt(label),
            primaryHasI18n: !!label && label.hasAttribute('data-i18n'),
            primaryDisabled: !!(document.querySelector('#btnJoinSelected') || {}).disabled,
            selectedLine: txt(document.querySelector('#joinSelectedCount')),
            rowCount: rows.length,
            // The name cell must be the app's standard avatar+name line in BOTH modes.
            names: rows.map((r) => txt(r.querySelector('.apv-person-name'))),
            avatars: rows.filter((r) => r.querySelector('.apv-person-avatar, img[data-initial]')).length,
            clickableAvatars: document.querySelectorAll('#tb_join_employees .emp-avatar-link').length,
            pullButtons: document.querySelectorAll('.join-emp-pull-one').length,
            headCells: document.querySelectorAll('#tb_join_employees thead th').length,
            emptyTitle: txt(document.querySelector('#tb_join_employees tbody .empty-state-title')),
            emptyBlocks: document.querySelectorAll('#tb_join_employees tbody .empty-state').length,
            // m3e1's own c12 invariant, re-measured here because this round added a <th>.
            thI18n: document.querySelectorAll('#joinEmployeesModal th[data-i18n]').length,
        };
    });
}

/** Records the `missing_only` every picker request actually carried -- the one proof the mode
 *  reached the server that does not depend on what this dev database happens to hold. */
function trackMissingOnly(ctx) {
    const seen = [];
    ctx.page.on('request', (req) => {
        if (req.url().indexOf(OPTIONS_PATH) === -1) return;
        const body = req.postData() || '';
        const m = /(?:^|&)missing_only=([^&]*)/.exec(body);
        seen.push(m ? decodeURIComponent(m[1]) : '(absent)');
    });
    return seen;
}

function countBannerCalls(ctx) {
    const box = { n: 0 };
    ctx.page.on('request', (req) => { if (req.url().indexOf(BANNER_PATH) !== -1) box.n++; });
    return box;
}

/** Replies to the picker's own fetch with an empty page, keeping DataTables' `draw` echo intact
 *  (a hand-built body with the wrong draw is silently discarded). Read-only either way. */
async function routeEmptyOptions(page) {
    await page.route('**' + OPTIONS_PATH + '*', async (route) => {
        const res = await route.fetch();
        let body;
        try { body = JSON.parse(await res.text()); } catch (e) { return route.fulfill({ response: res }); }
        body.data = [];
        body.recordsTotal = 0;
        body.recordsFiltered = 0;
        return route.fulfill({ response: res, body: JSON.stringify(body) });
    });
}

/** Answers the join call from inside the browser -- never fetched, so nothing is ever written. */
async function routeJoinReply(page, skippedIds) {
    await page.route('**' + JOIN_PATH + '*', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: true, message: 'ok', skipped_employee_ids: skippedIds }),
    }));
}

async function tickAllOnPage(page) {
    await page.click('#joinSelectAll');
    await page.waitForTimeout(400);
}

/* ================= n1: the banner opens the picker, not a Swal, and it is the missing list ======= */
async function n1() {
    const label = 'n1 1400 th light';
    console.log(`\n=== ${label}: "ดูรายชื่อ" opens the picker in missing mode ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: BLOCK });
    const seen = trackMissingOnly(ctx);
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    await openPicker(ctx.page, 'banner');
    const pic = await readPicker(ctx.page);
    measured('picker', pic);
    measured('missing_only sent on ' + OPTIONS_PATH, seen);

    check(`${label}: the banner opened the modal, not a Swal list`, pic.open === true && pic.swalOpen === false,
        `open=${pic.open} swal=${pic.swalOpen}`);
    check(`${label}: every picker request carried missing_only=1`,
        seen.length > 0 && seen.every((v) => v === '1'), JSON.stringify(seen));
    check(`${label}: the list holds exactly the employees the fixture left out of the sync`,
        pic.rowCount === MISSING_IDS.length, `${pic.rowCount} rows vs ${MISSING_IDS.length} ids in .last-session.json`);
    check(`${label}: every row renders the standard avatar+name line`,
        pic.rowCount > 0 && pic.avatars === pic.rowCount && pic.names.every((n) => n && n !== '-'),
        `${pic.avatars}/${pic.rowCount} avatars, names=${JSON.stringify(pic.names)}`);
    check(`${label}: no avatar in this picker is clickable (quick-view would stack on this modal)`,
        pic.clickableAvatars === 0, pic.clickableAvatars);
    check(`${label}: the title is the missing-mode one, from th.json`,
        pic.title === LANG.th.sync_missing_pull_title, `${pic.title} vs ${LANG.th.sync_missing_pull_title}`);
    check(`${label}: the title's own data-i18n was swapped, not just its text`,
        pic.titleKey === 'sync_missing_pull_title', pic.titleKey);
    check(`${label}: the neutral callout is shown with the th.json sentence`,
        pic.calloutShown === true && pic.calloutText === LANG.th.sync_missing_pull_hint, pic.calloutText);
    check(`${label}: one pull button per row`, pic.pullButtons === pic.rowCount,
        `${pic.pullButtons} buttons / ${pic.rowCount} rows`);
    check(`${label}: the action column is really there (8 header cells)`, pic.headCells === 8, pic.headCells);
    check(`${label}: the new <th> carries no data-i18n of its own (m3e1 c12)`, pic.thI18n === 0, pic.thI18n);

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: nothing was written`, rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

/* ================= n2: the primary button counts what it will act on ================= */
async function n2() {
    const label = 'n2 1400 th light';
    console.log(`\n=== ${label}: select-all relabels the primary with {count} ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: BLOCK });
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    await openPicker(ctx.page, 'banner');

    const before = await readPicker(ctx.page);
    check(`${label}: with nothing ticked the primary is disabled and reads ({count}=0)`,
        before.primaryDisabled === true && before.primaryLabel === fill(LANG.th.action_pull_selected, { count: 0 }),
        `${before.primaryLabel} / disabled=${before.primaryDisabled}`);
    check(`${label}: the {count} label dropped its data-i18n marker (the sweep would print "{count}")`,
        before.primaryHasI18n === false, before.primaryHasI18n);

    await tickAllOnPage(ctx.page);
    const after = await readPicker(ctx.page);
    measured('after select-all', { label: after.primaryLabel, rows: after.rowCount, line: after.selectedLine });
    const ticked = await ctx.page.evaluate(() => document.querySelectorAll('#tb_join_employees .join-emp-checkbox:checked').length);
    check(`${label}: the primary's number equals what is actually ticked`,
        after.primaryLabel === fill(LANG.th.action_pull_selected, { count: ticked }),
        `${after.primaryLabel} vs ${ticked} ticked`);
    check(`${label}: and that is every row on the page`, ticked === after.rowCount, `${ticked}/${after.rowCount}`);
    check(`${label}: the primary is enabled once something is ticked`, after.primaryDisabled === false, after.primaryDisabled);

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: nothing was written`, rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

/* ================= n3/n4: both ways of pulling hit the same blocked endpoint ================= */
async function pullCell(tag, how) {
    const label = `${tag} 1400 th light`;
    console.log(`\n=== ${label}: ${how === 'row' ? 'the row button' : 'the footer primary'} calls the blocked join endpoint ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: BLOCK });
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    await openPicker(ctx.page, 'banner');

    if (how === 'row') {
        await ctx.page.click('#tb_join_employees tbody .join-emp-pull-one');
    } else {
        await tickAllOnPage(ctx.page);
        await ctx.page.click('#btnJoinSelected');
    }
    await ctx.page.waitForTimeout(1800);
    const pic = await readPicker(ctx.page);
    measured('after the click', { open: pic.open, swal: pic.swalOpen, swalText: pic.swalText });

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: exactly one write attempt, and it was the join endpoint`,
        rep.blockedWrites === 1 && rep.blockedWritePaths.join(',') === JOIN_PATH,
        `${rep.blockedWrites} / ${rep.blockedWritePaths.join(',')}`);
    check(`${label}: the picker stays open when the call fails`, pic.open === true, pic.open);
    check(`${label}: the failure is said out loud, in the app's own words`,
        pic.swalOpen === true && pic.swalText !== null && pic.swalText.indexOf(LANG.th.save_failed) !== -1,
        pic.swalText);
    return rep;
}

/* ================= n5: the toolbar button still opens the unfiltered picker ================= */
async function n5() {
    const label = 'n5 1400 th light';
    console.log(`\n=== ${label}: #btnJoinEmployees is unchanged -- all mode, no action column ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: BLOCK });
    const seen = trackMissingOnly(ctx);
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    await openPicker(ctx.page, 'toolbar');
    const pic = await readPicker(ctx.page);
    measured('picker', pic);
    measured('missing_only sent on ' + OPTIONS_PATH, seen);

    check(`${label}: every request carried missing_only=0`,
        seen.length > 0 && seen.every((v) => v === '0'), JSON.stringify(seen));
    check(`${label}: the title is back to the plain one`, pic.title === LANG.th.join_employees_title, pic.title);
    check(`${label}: the primary keeps its own data-i18n and its original label`,
        pic.primaryHasI18n === true && pic.primaryLabel === LANG.th.action_join_employees,
        `${pic.primaryLabel} / i18n=${pic.primaryHasI18n}`);
    check(`${label}: the missing-mode callout is hidden`, pic.calloutShown === false, pic.calloutText);
    check(`${label}: the action column is hidden (7 header cells, no row buttons)`,
        pic.headCells === 7 && pic.pullButtons === 0, `${pic.headCells} th / ${pic.pullButtons} buttons`);
    check(`${label}: the name column is still the avatar+name line here too`,
        pic.rowCount > 0 && pic.avatars === pic.rowCount, `${pic.avatars}/${pic.rowCount}`);

    /* The two populations, asked of the server directly -- what "a different list" means, measured
       instead of inferred from a page that only ever shows one page of rows. */
    const totals = await ctx.page.evaluate(async () => {
        const ask = async (mode) => {
            const body = new URLSearchParams({ run_id: String(PAYROLL_RUN_ID), draw: '1', start: '0', length: '1', missing_only: mode });
            const r = await fetch(`${BASE_URL}/api/payroll-run.manual-employee-options`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body,
            });
            return (await r.json()).recordsTotal;
        };
        return { all: await ask('0'), missing: await ask('1') };
    });
    measured('recordsTotal per mode', totals);
    check(`${label}: missing mode's own total is the fixture's 2`, totals.missing === MISSING_IDS.length,
        `${totals.missing} vs ${MISSING_IDS.length}`);
    /* missing mode's WHERE is strictly the narrower one -- it adds the employment-date/cycle test and
       excludes anyone this run has already removed, on top of every condition all mode applies (see
       buildManualEmployeeWhere()/syncMissingEmployeeWhere()). So all >= missing always holds, and
       that, not "strictly more rows", is the invariant worth asserting: whether the two sets are
       equal is a property of the DATABASE, not of the code. On the --with-sync fixture they are
       (it maps every payroll participant but two), which is said out loud rather than asserted away. */
    check(`${label}: all mode's population is never narrower than missing mode's`,
        totals.all >= totals.missing, `all=${totals.all} missing=${totals.missing}`);
    if (totals.all === totals.missing) {
        console.log('  NOTE  on this fixture the 2 modes resolve to the SAME employees (mksession maps every');
        console.log('  NOTE  participant except the 2 it holds back) -- the modes are told apart by the');
        console.log('  NOTE  missing_only each request carried, above, not by a row count.');
    }

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: nothing was written`, rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

/* ================= n6: 430 th dark ================= */
async function n6() {
    const label = 'n6 430x932 th dark';
    console.log(`\n=== ${label}: the picker on a phone, in the dark theme ===`);
    const ctx = await openContext({ sessionId, width: 430, height: 932, colorScheme: 'dark', blockPaths: BLOCK });
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'dark', label })) return ctx.report();
    await openPicker(ctx.page, 'banner');
    if (!await ensureCellTheme(ctx.page, 'dark', { label, when: 'picker open', check, log: console.log })) return ctx.report();

    const box = await ctx.page.evaluate(() => {
        const bgOf = (el) => {
            let node = el;
            while (node && node !== document.documentElement) {
                const c = getComputedStyle(node).backgroundColor;
                if (c && c !== 'transparent' && !/rgba\([^)]*,\s*0\s*\)/.test(c)) return c;
                node = node.parentElement;
            }
            return getComputedStyle(document.body).backgroundColor;
        };
        const callout = document.querySelector('#joinEmployeesMissingCallout');
        const icon = document.querySelector('#tb_join_employees .join-emp-pull-one i');
        const name = document.querySelector('#tb_join_employees .apv-person-name');
        const body = document.querySelector('#joinEmployeesModal .modal-body');
        const wrap = document.querySelector('#tb_join_employees_wrapper');
        return {
            callout: callout ? { color: getComputedStyle(callout).color, bg: bgOf(callout) } : null,
            icon: icon ? { color: getComputedStyle(icon).color, bg: bgOf(icon) } : null,
            name: name ? { color: getComputedStyle(name).color, bg: bgOf(name) } : null,
            docScrollW: document.documentElement.scrollWidth,
            docClientW: document.documentElement.clientWidth,
            bodyScrollW: body ? body.scrollWidth : null,
            bodyClientW: body ? body.clientWidth : null,
            wrapScrollW: wrap ? wrap.scrollWidth : null,
            wrapClientW: wrap ? wrap.clientWidth : null,
        };
    });
    measured('box', box);

    check(`${label}: the page itself never scrolls sideways -- any overflow stays inside the table`,
        box.docScrollW <= box.docClientW + 1, `${box.docScrollW} vs ${box.docClientW}`);
    [['callout', box.callout], ['row pull icon', box.icon], ['employee name', box.name]].forEach(([what, m]) => {
        if (!m) { check(`${label}: ${what} is present to measure`, false, 'missing'); return; }
        const cr = contrastRatio(m.color, m.bg);
        measured(`${what} contrast`, `${cr} (${m.color} on ${m.bg})`);
        check(`${label}: ${what} clears 4.5:1 in the dark theme`, cr !== null && cr >= 4.5, cr);
    });

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: nothing was written`, rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

/* ================= n7: 768 en, and a live th -> en switch with the picker open ================= */
async function n7() {
    const label = 'n7 768 en';
    console.log(`\n=== ${label}: every new string comes from en.json, and switching language repaints them ===`);
    const ctx = await openContext({ sessionId, width: 768, height: 950, blockPaths: BLOCK });
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    // Empty from the start, so the empty state is on screen for the language switch to act on.
    await routeEmptyOptions(ctx.page);
    await openPicker(ctx.page, 'banner');

    const inTh = await readPicker(ctx.page);
    measured('th, before the switch', { title: inTh.title, empty: inTh.emptyTitle, primary: inTh.primaryLabel });
    check(`${label}: th first -- title, empty state and primary all in Thai`,
        inTh.title === LANG.th.sync_missing_pull_title
        && inTh.emptyTitle === LANG.th.sync_missing_empty_state
        && inTh.primaryLabel === fill(LANG.th.action_pull_selected, { count: 0 }),
        JSON.stringify({ t: inTh.title, e: inTh.emptyTitle, p: inTh.primaryLabel }));

    await ctx.page.evaluate(() => { if (typeof changeLanguage === 'function') changeLanguage('en'); });
    /* changeLanguage() fetches the lang file, then refreshPayrollDetailLanguage() redraws this table
       (measured: 4 further requests settle out of one switch). Waited ON THE RESULT rather than on a
       fixed sleep -- a sleep long enough today is a flake tomorrow. A timeout here is not fatal: the
       checks below then read whatever is really on screen and fail with it, which is the point. */
    await ctx.page.waitForFunction(() => {
        const t = document.querySelector('#joinEmployeesModalTitleText');
        const e = document.querySelector('#tb_join_employees tbody .empty-state-title');
        return t && e && !/[฀-๿]/.test(t.textContent) && !/[฀-๿]/.test(e.textContent);
    }, null, { timeout: 15000 }).catch(() => console.log('  NOTE  timed out waiting for the switch to settle -- measuring anyway'));
    await ctx.page.waitForTimeout(600);
    const inEn = await readPicker(ctx.page);
    measured('en, after the switch', { title: inEn.title, callout: inEn.calloutText, empty: inEn.emptyTitle, primary: inEn.primaryLabel });

    check(`${label}: the title changed to the en.json one`, inEn.title === LANG.en.sync_missing_pull_title,
        `${inEn.title} vs ${LANG.en.sync_missing_pull_title}`);
    check(`${label}: the callout changed to the en.json one`, inEn.calloutText === LANG.en.sync_missing_pull_hint,
        inEn.calloutText);
    check(`${label}: the empty state changed too (it is cached on the DataTable settings, not re-read)`,
        inEn.emptyTitle === LANG.en.sync_missing_empty_state, `${inEn.emptyTitle} vs ${LANG.en.sync_missing_empty_state}`);
    check(`${label}: the {count} primary label was re-rendered, not left frozen`,
        inEn.primaryLabel === fill(LANG.en.action_pull_selected, { count: 0 }), inEn.primaryLabel);
    const strings = [inEn.title, inEn.calloutText, inEn.emptyTitle, inEn.primaryLabel];
    check(`${label}: nothing on screen is a raw lang key or an unfilled placeholder`,
        strings.every((s) => s && s.indexOf('{') === -1 && s.indexOf('_') === -1), JSON.stringify(strings));
    check(`${label}: no Thai character survived the switch`,
        strings.every((s) => !/[฀-๿]/.test(s)), JSON.stringify(strings));

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: nothing was written`, rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

/* ================= n8: the missing-mode empty state ================= */
async function n8() {
    const label = 'n8 1400 th light';
    console.log(`\n=== ${label}: nobody missing -- the picker says so in its own words ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: BLOCK });
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    await routeEmptyOptions(ctx.page);
    await openPicker(ctx.page, 'banner');
    const pic = await readPicker(ctx.page);
    measured('picker', { rows: pic.rowCount, emptyBlocks: pic.emptyBlocks, emptyTitle: pic.emptyTitle });

    check(`${label}: no rows are rendered`, pic.rowCount === 0, pic.rowCount);
    check(`${label}: the shared empty-state component is used, exactly once`, pic.emptyBlocks === 1, pic.emptyBlocks);
    check(`${label}: it reads the missing-mode sentence from th.json`,
        pic.emptyTitle === LANG.th.sync_missing_empty_state, `${pic.emptyTitle} vs ${LANG.th.sync_missing_empty_state}`);
    check(`${label}: the primary is disabled with nothing to pull`, pic.primaryDisabled === true, pic.primaryDisabled);

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: nothing was written`, rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

/* ================= n9: a clean success closes the picker and re-counts the banner ================= */
async function n9() {
    const label = 'n9 1400 th light';
    console.log(`\n=== ${label}: success -- modal closes, the run reloads, the banner is re-counted ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: BLOCK });
    const banner = countBannerCalls(ctx);
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    await routeJoinReply(ctx.page, []);
    await openPicker(ctx.page, 'banner');
    const bannerBefore = banner.n;
    measured('banner fetches before the pull', bannerBefore);

    await tickAllOnPage(ctx.page);
    await ctx.page.click('#btnJoinSelected');
    await ctx.page.waitForTimeout(2500);
    const pic = await readPicker(ctx.page);
    measured('after the pull', { open: pic.open, swal: pic.swalOpen, swalText: pic.swalText, banner: banner.n });

    check(`${label}: the picker closed`, pic.open === false, pic.open);
    check(`${label}: the success toast is the app's own save_success`,
        pic.swalText !== null && pic.swalText.indexOf(LANG.th.save_success) !== -1, pic.swalText);
    check(`${label}: the run reloaded and re-counted the banner (no second call added by hand)`,
        banner.n === bannerBefore + 1, `${bannerBefore} -> ${banner.n}`);

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: the join call never reached the server (answered in the browser)`,
        rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

/* ================= n10: a partial skip warns instead of claiming success ================= */
async function n10() {
    const label = 'n10 1400 th light';
    console.log(`\n=== ${label}: some ids skipped -- the warning replaces the success toast ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: BLOCK });
    if (!await openRun(ctx, { lang: 'th', colorScheme: 'light', label })) return ctx.report();
    const skipped = [MISSING_IDS[0]];
    await routeJoinReply(ctx.page, skipped);
    await openPicker(ctx.page, 'banner');

    await tickAllOnPage(ctx.page);
    const ticked = await ctx.page.evaluate(() => document.querySelectorAll('#tb_join_employees .join-emp-checkbox:checked').length);
    await ctx.page.click('#btnJoinSelected');
    await ctx.page.waitForTimeout(2500);
    const pic = await readPicker(ctx.page);
    const expected = fill(LANG.th.sync_missing_pull_skipped, { joined: ticked - skipped.length, count: skipped.length });
    measured('expected warning', expected);
    measured('what was shown', pic.swalText);

    check(`${label}: the warning names both numbers, from the th.json template`,
        pic.swalText !== null && pic.swalText.indexOf(expected) !== -1, pic.swalText);
    check(`${label}: it did NOT also claim success`,
        pic.swalText !== null && pic.swalText.indexOf(LANG.th.save_success) === -1, pic.swalText);
    check(`${label}: the picker still closed -- the run really did change`, pic.open === false, pic.open);

    const rep = ctx.report();
    measured('report', rep);
    check(`${label}: the join call never reached the server (answered in the browser)`,
        rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    return rep;
}

(async () => {
    console.log(`fixture: run ${runToken} · ${MISSING_IDS.length} employee(s) left out of the sync (${MISSING_IDS.join(', ')})`);
    const reports = [];
    reports.push(await n1());
    reports.push(await n2());
    reports.push(await pullCell('n3', 'row'));
    reports.push(await pullCell('n4', 'footer'));
    reports.push(await n5());
    reports.push(await n6());
    reports.push(await n7());
    reports.push(await n8());
    reports.push(await n9());
    reports.push(await n10());
    await closeAll();

    const pageErrors = reports.reduce((n, r) => n + (r.pageErrors || []).length, 0);
    const consoleErrors = reports.reduce((n, r) => n + (r.consoleErrors || []).length, 0);
    console.log(`\npage errors: ${pageErrors} · console errors: ${consoleErrors}`);
    reports.forEach((r, i) => {
        (r.pageErrors || []).forEach((e) => console.log(`  PAGEERROR  cell ${i + 1}: ${e}`));
        (r.consoleErrors || []).forEach((e) => console.log(`  CONSOLE    cell ${i + 1}: ${e}`));
    });
    console.log('\n--------------------------------------------------');
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    if (failed > 0) {
        console.log('SOME CHECKS FAILED');
        process.exit(1);
    }
    console.log('ALL CHECKS PASSED');
})().catch(async (e) => {
    await closeAll();
    console.error(e);
    process.exit(1);
});
