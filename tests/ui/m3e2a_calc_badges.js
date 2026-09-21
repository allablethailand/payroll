/**
 * 3e-2a measurement: the Calculation column's 2 badges, the sentences behind them, and the spacing
 * of the run-level banner stack.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/m3e2a_calc_badges.js <PHPSESSID> <runToken> [<bannerRunToken>]
 *
 * The 7 fixture rows come from tests/ui/.last-session.json's own `calc_error_fixture` (written by
 * mksession.php --with-calc-errors). NOTHING about them is hard-coded here: every expected count is
 * recomputed from the run's OWN /api/payroll-run.details response at run time, so a fixture change
 * moves this file's expectations with it instead of breaking it -- and so a cell can never pass by
 * agreeing with a number someone typed twice.
 *
 * 10 cells, each its own context:
 *   1  1400 th light -- R1/R2: the error badge is a real button, its list is calc_blocking, one
 *      popover open at a time, the tip is on screen and hit-testable, and positioned `fixed`
 *   2  430x932 th dark -- both popovers stay inside the viewport and inside their own max-height
 *   3  keyboard -- Tab reaches it, Enter opens it, Esc closes it and hands focus back
 *   4  the 2 codes that had no sentence before this round: zz_fixture_unknown, missing_ot_rate_*
 *   5  prorate 0 -- R4 gains a note, R6 gains one on top of its own, R5/R7/k4b gain none
 *   6  the slip: the same strings, as callouts, with the same data-code (+ 6b, the same in dark)
 *   7  employee 28 -- untouched by the fixture, so it proves the fixture did not leak
 *   8  sort + filter of that column still read the enum, not the new button markup
 *   9  768 en -- both titles and every sentence come from en.json
 *  10  spacing: 16px to the tab bar (a), a run with NO banner keeps its old gap (b), and the
 *      2-banner case the report was about, on run 29685 (c)
 *
 * Writes: none. Every mutating route of this page is in WRITE_PATHS (the harness blocks
 * user-preference.save and recalculate by itself), and each cell asserts blockedWrites === 0 --
 * i.e. it never even attempted one.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const { openContext, closeAll, applyAppTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
if (!sessionId || !runToken) {
    throw new Error('usage: node tests/ui/m3e2a_calc_badges.js <PHPSESSID> <runToken>');
}

/* The run that shows 2 run-level banners at once (has_validation_errors=1 AND a sync process, still
   draft). 2026-09-21, 3e-2b: argv still wins, but the fallback is no longer a token typed into this
   file -- tests/ui/find_banner_run.php asks the DB for it (one read-only SELECT, the same 4
   conditions the page's own render path reads). A hard-coded token that stops matching measures the
   wrong run while still calling itself by the right name. `none` -> p10c mocks the field instead of
   reporting that it could not prove anything. Same resolver m3e1_tabs_shared.js's c14 uses. */
function resolveBannerRunToken(argvToken) {
    if (argvToken) return { token: argvToken, source: 'argv' };
    try {
        const out = require('child_process')
            .execFileSync('php', [path.join(__dirname, 'find_banner_run.php')], { encoding: 'utf8' })
            .split('\n').map((x) => x.trim()).filter(Boolean).pop();
        if (out && out !== 'none') return { token: out, source: 'find_banner_run.php' };
        return { token: null, source: 'none' };
    } catch (e) {
        return { token: null, source: 'error: ' + (e && e.message ? String(e.message).split('\n')[0] : e) };
    }
}
const BANNER_RUN = resolveBannerRunToken(process.argv[4]);
/* The `none` branch: patch the field the validation banner reads into api/payroll-run.get's own
   reply (a READ route -- nothing is written either way), so the 2-box case stays measurable on the
   fixture run. Every other field of the real reply is passed through untouched. */
async function mockBannerFields(page) {
    await page.route('**/api/payroll-run.get*', async (route) => {
        const res = await route.fetch();
        let body;
        try { body = JSON.parse(await res.text()); } catch (e) { return route.fulfill({ response: res }); }
        if (body && body.data) body.data.has_validation_errors = 1;
        return route.fulfill({ response: res, body: JSON.stringify(body) });
    });
}

const STATE = JSON.parse(fs.readFileSync(path.join(__dirname, '.last-session.json'), 'utf8'));
const FIXTURE = STATE.calc_error_fixture;
if (!FIXTURE) {
    throw new Error('tests/ui/.last-session.json has no calc_error_fixture -- re-run:\n'
        + '  php tests/ui/mksession.php --with-recurring --with-calc-errors');
}

/* run 1015, locked -- used by cell 10 only, as the "no run-level banner" case. Same run
   m3e1_tabs_shared.js opens read-only for its own after-approval tabs. */
const LOCKED_RUN_TOKEN = 'AV_3zH_fu0Ep03a_cT_NpIvAW19tk9dMf0mw0_k69wur';

/* Same list m3e1_tabs_shared.js blocks, plus the 3 this page can reach from the Employee Breakdown
   tab itself. api/report.generate is a GET that writes report_export_logs, so it belongs here too.
   recalculate + user-preference.save are blocked by the harness for every context already. */
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
    '/api/payroll-run.line-override.save',
    '/api/payroll-run.manual-line.save',
    '/api/payroll-run.employee-exemption.save',
];

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
    await openBreakdownTab(ctx.page);
}
/* The Employee Breakdown tab is not the default one, and its DataTable only builds on first show. */
async function openBreakdownTab(page) {
    await page.click('#run-employee-tab');
    await page.waitForTimeout(900);
}

/* THE source of every expected number in this file: the rows the table itself is holding, read out
   of the live DataTable rather than re-fetched, so what is asserted is exactly what is rendered. */
async function rowsFromTable(page) {
    return page.evaluate(() => (typeof tb_run_detail !== 'undefined' && tb_run_detail)
        ? tb_run_detail.rows().data().toArray().map((r) => ({
            employee_id: Number(r.employee_id),
            employee_no: r.employee_no,
            calc_status: r.calc_status,
            calc_blocking: r.calc_blocking || [],
            calc_warnings: r.calc_warnings || [],
            prorate_days: r.prorate_days,
            prorate_total_days: r.prorate_total_days,
        }))
        : []);
}
/* The client-side rule under test, restated here from the response alone -- deliberately NOT by
   calling calcAdvisoryCodesRd() in the page, which would make this assert "the function agrees with
   itself". Kept to the 3 facts the round's own spec states. */
function isProrateZero(row) {
    const d = row.prorate_days;
    return d !== null && d !== undefined && d !== '' && Number(d) === 0;
}
function expectedAdvisoryCount(row) {
    const warnings = row.calc_warnings || [];
    // `prorate_days` arrives as a STRING ("0", "15") -- PayrollRunModel::getDetails() does not cast
    // it. The first draft of this file compared it with `=== 0` and so agreed with the very bug it
    // was meant to catch: both sides said "no note", and the cell passed on a feature that fired on
    // nothing. Written from the RESPONSE's real shape now, not from the code's assumption about it.
    const addsProrate = isProrateZero(row) && warnings.indexOf('hourly_salary_no_attendance_data') === -1;
    return warnings.length + (addsProrate ? 1 : 0);
}
/* The <tr> of one employee, by the employee_no cell the table renders. */
async function rowHandle(page, employeeNo) {
    return page.evaluateHandle((no) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        return trs.find((tr) => (tr.textContent || '').indexOf(no) !== -1) || null;
    }, employeeNo);
}
async function badgeCounts(page, employeeNo) {
    return page.evaluate((no) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(no) !== -1);
        if (!tr) return null;
        return {
            errorBtns: tr.querySelectorAll('.rd-calc-error-btn').length,
            warningBtns: tr.querySelectorAll('.rd-calc-warning-btn').length,
            statusBadges: tr.querySelectorAll('[data-badge="status"]').length,
        };
    }, employeeNo);
}
/* Open a popover by clicking its trigger inside one row, then read the tip it produced. The tip is
   found through the trigger's own aria-describedby -- never by "the last .popover in the DOM". */
async function openPopover(page, employeeNo, btnClass) {
    // Scrolled into view FIRST, both axes -- a real user reaches this control by scrolling to it,
    // and `strategy: 'fixed'` positions the tip against the VIEWPORT, so clicking a trigger that is
    // still below the fold (or, at 430, still inside the table's own horizontal scroll) would place
    // the tip off-screen and make this cell measure the test's own impatience, not the page.
    await page.evaluate((args) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(args.no) !== -1);
        const btn = tr && tr.querySelector('.' + args.cls);
        if (btn && btn.scrollIntoView) btn.scrollIntoView({ block: 'center', inline: 'center' });
    }, { no: employeeNo, cls: btnClass });
    await page.waitForTimeout(350);
    await page.evaluate((args) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(args.no) !== -1);
        const btn = tr && tr.querySelector('.' + args.cls);
        if (btn) btn.click();
    }, { no: employeeNo, cls: btnClass });
    await page.waitForTimeout(450);
    return page.evaluate((args) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(args.no) !== -1);
        const btn = tr && tr.querySelector('.' + args.cls);
        if (!btn) return null;
        const id = btn.getAttribute('aria-describedby');
        const tip = id ? document.getElementById(id) : null;
        if (!tip) return { open: false, ariaExpanded: btn.getAttribute('aria-expanded') };
        const r = tip.getBoundingClientRect();
        const body = tip.querySelector('.popover-body');
        const lis = Array.from(tip.querySelectorAll('li'));
        const cs = getComputedStyle(tip);
        const bodyCs = body ? getComputedStyle(body) : null;
        // elementFromPoint at the tip's own centre: if anything else answers, the tip is covered or
        // clipped, which a bounding box alone cannot tell you.
        const cx = Math.round(r.left + r.width / 2);
        const cy = Math.round(r.top + r.height / 2);
        const hit = document.elementFromPoint(cx, cy);
        return {
            open: true,
            ariaExpanded: btn.getAttribute('aria-expanded'),
            title: (tip.querySelector('.popover-header') || {}).textContent || '',
            items: lis.map((li) => ({ code: li.getAttribute('data-code'), text: (li.textContent || '').trim() })),
            rect: { left: Math.round(r.left * 10) / 10, right: Math.round(r.right * 10) / 10,
                top: Math.round(r.top * 10) / 10, bottom: Math.round(r.bottom * 10) / 10,
                width: Math.round(r.width * 10) / 10, height: Math.round(r.height * 10) / 10 },
            position: cs.position,
            color: cs.color,
            background: cs.backgroundColor,
            borderColor: cs.borderTopColor,
            bodyColor: bodyCs ? bodyCs.color : null,
            bodyMaxHeight: bodyCs ? bodyCs.maxHeight : null,
            bodyOverflowY: bodyCs ? bodyCs.overflowY : null,
            bodyScrollable: body ? body.scrollHeight > body.clientHeight : false,
            hitIsInsideTip: !!(hit && tip.contains(hit)),
            openTipCount: document.querySelectorAll('.popover').length,
        };
    }, { no: employeeNo, cls: btnClass });
}
/* The token this page resolves `--c-text` to right now, for the theme it is in. */
async function tokenValue(page, name) {
    return page.evaluate((n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);
}
/* WCAG relative luminance + contrast ratio, from the sRGB triplets getComputedStyle hands back.
   A colour token pair that "looks fine" in a screenshot is not a measurement; a number is. */
function parseRgb(v) {
    const m = String(v).match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const parts = m[1].split(',').map((x) => parseFloat(x.trim()));
    return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
}
function relativeLuminance(c) {
    const f = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
}
function contrastRatio(fg, bg) {
    const a = parseRgb(fg);
    const b = parseRgb(bg);
    if (!a || !b) return null;
    const l1 = relativeLuminance(a);
    const l2 = relativeLuminance(b);
    const hi = Math.max(l1, l2);
    const lo = Math.min(l1, l2);
    return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
}
function rgbOf(hex) {
    const h = hex.replace('#', '');
    const n = parseInt(h.length === 3 ? h.split('').map((c) => c + c).join('') : h, 16);
    return 'rgb(' + ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ')';
}
function reportOk(label, ctx) {
    const rep = ctx.report();
    measured(label + ' report', rep);
    check(label + ': nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    check(label + ': no page errors', rep.pageErrors.length === 0, rep.pageErrors.join(' | '));
}

/* ---------------- cell 1: the error badge is a button, and its list is calc_blocking ------------ */
async function cell1() {
    console.log('\n[p1] 1400 th light -- the error badge opens its own list');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);
    check('p1: the table really loaded its rows', rows.length > 0, rows.length);

    for (const label of ['R1', 'R2']) {
        const no = FIXTURE[label].employee_no;
        const row = rows.find((r) => r.employee_no === no);
        if (!row) { check('p1: ' + label + ' (' + no + ') is in the table', false); continue; }
        const counts = await badgeCounts(ctx.page, no);
        measured('p1 ' + label + ' (' + no + ') badges', counts);
        check('p1 ' + label + ': exactly 1 error button in the cell', counts.errorBtns === 1, counts.errorBtns);

        const tip = await openPopover(ctx.page, no, 'rd-calc-error-btn');
        measured('p1 ' + label + ' error popover', { open: tip && tip.open, items: tip && tip.items, rect: tip && tip.rect,
            position: tip && tip.position, hitIsInsideTip: tip && tip.hitIsInsideTip, openTipCount: tip && tip.openTipCount });
        check('p1 ' + label + ': it opened', !!(tip && tip.open));
        check('p1 ' + label + ': one <li> per blocking code, from the response',
            tip.items.length === row.calc_blocking.length, tip.items.length + ' vs ' + row.calc_blocking.length);
        check('p1 ' + label + ': every <li> carries its own data-code, in order',
            tip.items.every((it, i) => it.code === row.calc_blocking[i]),
            JSON.stringify(tip.items.map((i) => i.code)) + ' vs ' + JSON.stringify(row.calc_blocking));
        check('p1 ' + label + ': no <li> shows a raw code as its TEXT',
            tip.items.every((it) => it.text !== it.code && it.text.length > 0),
            JSON.stringify(tip.items));
        check('p1 ' + label + ': the tip is positioned fixed', tip.position === 'fixed', tip.position);
        check('p1 ' + label + ': the whole tip is on screen',
            tip.rect.left >= 0 && tip.rect.top >= 0 && tip.rect.right <= 1400 && tip.rect.bottom <= 950,
            JSON.stringify(tip.rect) + ' in 1400x950');
        check('p1 ' + label + ': its centre really hits the tip (not clipped or covered)', tip.hitIsInsideTip === true);
    }

    // One at a time: open the error popover, then the warning popover of the SAME row.
    const r2no = FIXTURE.R2.employee_no;
    await openPopover(ctx.page, r2no, 'rd-calc-error-btn');
    const second = await openPopover(ctx.page, r2no, 'rd-calc-warning-btn');
    measured('p1 R2 after opening the warning badge too', { openTipCount: second && second.openTipCount });
    check('p1: opening the second badge leaves exactly 1 tip in the DOM',
        !!second && second.openTipCount === 1, second && second.openTipCount);

    reportOk('p1', ctx);
}

/* ---------------- cell 2: 430 REAL dark -- viewport, ceiling, and the token colours ------------ */
async function cell2() {
    console.log('\n[p2] 430x932 th DARK -- applied through the app own applyTheme');
    const ctx = await openContext({ sessionId, width: 430, height: 932, colorScheme: 'dark', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    // 2026-09-21, 3e-2a round 1: the first version of this cell opened `colorScheme: 'dark'` and
    // measured colours without ever checking the page took the theme. It had not -- the account is
    // saved as 'light', so the page stayed light and every colour assertion below was reading the
    // LIGHT tokens under a cell labelled dark. The theme is applied the app's own way now, and
    // PROVEN before anything is measured.
    const theme = await applyAppTheme(ctx.page, 'dark');
    measured('p2 theme after applyAppTheme', theme);
    check('p2: the page really is in dark theme (stamp + tokens), nothing measured if not',
        theme.ok === true, 'data-bs-theme=' + theme.stamp);
    if (!theme.ok) { reportOk('p2', ctx); return; }
    const expectBg = rgbOf(theme.bg);
    const expectText = rgbOf(theme.text);
    const expectBorder = rgbOf(theme.border);
    // <body> is painted by the LEGACY --app-bg, not --c-bg (they differ in dark: #14181f vs #15181C)
    // -- asserted against the token that really paints it, plus a luminance floor so "dark" is a
    // measured property of the pixel, not just the name of a variable.
    check('p2: <body> is painted the dark --app-bg it resolves to',
        theme.bodyBackground === rgbOf(theme.appBg), theme.bodyBackground + ' vs ' + rgbOf(theme.appBg));
    check('p2: ...and that colour really is dark (luminance well under mid-grey)',
        relativeLuminance(parseRgb(theme.bodyBackground)) < 0.05,
        relativeLuminance(parseRgb(theme.bodyBackground)));

    const no = FIXTURE.R2.employee_no;
    for (const cls of ['rd-calc-error-btn', 'rd-calc-warning-btn']) {
        const tip = await openPopover(ctx.page, no, cls);
        const ratio = tip && tip.open ? contrastRatio(tip.bodyColor || tip.color, tip.background) : null;
        measured('p2 ' + cls, tip && { rect: tip.rect, color: tip.color, bodyColor: tip.bodyColor,
            background: tip.background, borderColor: tip.borderColor, contrast: ratio,
            bodyMaxHeight: tip.bodyMaxHeight, bodyOverflowY: tip.bodyOverflowY, bodyScrollable: tip.bodyScrollable });
        if (!tip || !tip.open) { check('p2 ' + cls + ': it opened', false); continue; }
        check('p2 ' + cls + ': left edge is on screen', tip.rect.left >= 0, tip.rect.left);
        check('p2 ' + cls + ': right edge is on screen', tip.rect.right <= 430, tip.rect.right);
        check('p2 ' + cls + ': no wider than the 320px ceiling', tip.rect.width <= 320, tip.rect.width);
        check('p2 ' + cls + ': the whole tip fits the viewport height', tip.rect.bottom <= 932, tip.rect.bottom);
        check('p2 ' + cls + ': the body scrolls rather than growing past its ceiling',
            tip.bodyOverflowY === 'auto' && tip.bodyMaxHeight !== 'none', tip.bodyOverflowY + ' / ' + tip.bodyMaxHeight);
        // The 3 tokens the round put on this component, read back from the live dark page.
        check('p2 ' + cls + ': text is the DARK --c-text', tip.color === expectText, tip.color + ' vs ' + expectText);
        check('p2 ' + cls + ': background is the DARK --c-bg', tip.background === expectBg, tip.background + ' vs ' + expectBg);
        check('p2 ' + cls + ': border is the DARK --c-border', tip.borderColor === expectBorder, tip.borderColor + ' vs ' + expectBorder);
        check('p2 ' + cls + ': text on its own background clears 4.5:1', ratio !== null && ratio >= 4.5, ratio);
    }
    reportOk('p2', ctx);
}

/* ---------------- cell 3: keyboard ------------------------------------------------------------ */
async function cell3() {
    console.log('\n[p3] keyboard -- Tab reaches it, Enter opens it, Esc closes it and returns focus');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const no = FIXTURE.R2.employee_no;

    const focused = await ctx.page.evaluate((n) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(n) !== -1);
        const btn = tr && tr.querySelector('.rd-calc-error-btn');
        if (!btn) return null;
        btn.scrollIntoView({ block: 'center', inline: 'center' });
        btn.focus();
        return {
            reachable: document.activeElement === btn,
            tabIndex: btn.tabIndex,
            tag: btn.tagName,
            ariaExpandedAtRest: btn.getAttribute('aria-expanded'),
        };
    }, no);
    measured('p3 trigger at rest', focused);
    check('p3: the trigger is a real <button>', !!focused && focused.tag === 'BUTTON', focused && focused.tag);
    check('p3: keyboard focus reaches it', !!focused && focused.reachable === true);
    check('p3: it is in the natural tab order (no tabindex=-1)', !!focused && focused.tabIndex >= 0, focused && focused.tabIndex);
    check('p3: it announces itself as a closed disclosure before being pressed',
        !!focused && focused.ariaExpandedAtRest === 'false', focused && focused.ariaExpandedAtRest);

    await ctx.page.keyboard.press('Enter');
    await ctx.page.waitForTimeout(450);
    const opened = await ctx.page.evaluate((n) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(n) !== -1);
        const btn = tr && tr.querySelector('.rd-calc-error-btn');
        return btn ? { ariaExpanded: btn.getAttribute('aria-expanded'),
            describedBy: btn.getAttribute('aria-describedby'), tips: document.querySelectorAll('.popover').length } : null;
    }, no);
    measured('p3 after Enter', opened);
    check('p3: Enter opens it', !!opened && opened.tips === 1, opened && opened.tips);
    check('p3: aria-expanded flips to true', !!opened && opened.ariaExpanded === 'true', opened && opened.ariaExpanded);
    check('p3: aria-describedby points at the tip', !!opened && !!opened.describedBy, opened && opened.describedBy);

    await ctx.page.keyboard.press('Escape');
    await ctx.page.waitForTimeout(450);
    const closed = await ctx.page.evaluate((n) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(n) !== -1);
        const btn = tr && tr.querySelector('.rd-calc-error-btn');
        return btn ? { ariaExpanded: btn.getAttribute('aria-expanded'),
            tips: document.querySelectorAll('.popover').length,
            focusBack: document.activeElement === btn,
            modalsOpen: document.querySelectorAll('.modal.show').length } : null;
    }, no);
    measured('p3 after Esc', closed);
    check('p3: Esc closes it', !!closed && closed.tips === 0, closed && closed.tips);
    check('p3: aria-expanded flips back to false', !!closed && closed.ariaExpanded === 'false', closed && closed.ariaExpanded);
    check('p3: focus returns to the same button', !!closed && closed.focusBack === true);
    reportOk('p3', ctx);
}

/* ---------------- cell 4: the 2 codes that had no sentence before this round ------------------- */
async function cell4() {
    console.log('\n[p4] the codes that used to render raw');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const lang = await ctx.page.evaluate(() => langData);
    const no = FIXTURE.R2.employee_no;
    const tip = await openPopover(ctx.page, no, 'rd-calc-error-btn');
    measured('p4 R2 blocking items', tip && tip.items);
    if (!tip || !tip.open) { check('p4: the error popover opened', false); reportOk('p4', ctx); return; }

    const unknown = tip.items.filter((it) => it.code === 'zz_fixture_unknown');
    check('p4: the unknown code appears exactly once, by data-code', unknown.length === 1, unknown.length);
    check('p4: ...and reads as the one neutral sentence, never as itself',
        unknown.length === 1 && unknown[0].text === lang['calc_error_unknown'], unknown.length ? unknown[0].text : '-');

    const otItems = tip.items.filter((it) => it.code && it.code.indexOf('missing_ot_rate_') === 0);
    check('p4: the missing_ot_rate code is present', otItems.length >= 1, otItems.length);
    for (const it of otItems) {
        const scope = it.code.substring('missing_ot_rate_'.length);
        const key = 'calc_error_missing_ot_rate_' + scope;
        check('p4: ' + it.code + ' reads as ' + key,
            !!lang[key] && it.text === lang[key], it.text);
    }
    const allText = tip.items.map((i) => i.text).join(' ');
    check('p4: no raw code string survives anywhere in the tip text',
        tip.items.every((it) => allText.indexOf(it.code) === -1 || it.code.indexOf(':') !== -1),
        allText.slice(0, 160));
    reportOk('p4', ctx);
}

/* ---------------- cell 5: prorate 0 becomes a warning ----------------------------------------- */
async function cell5() {
    console.log('\n[p5] prorate 0 -- who gains a note and who does not');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);

    const labels = ['R4', 'R5', 'R6', 'R7'];
    for (const label of labels) {
        const no = FIXTURE[label].employee_no;
        const row = rows.find((r) => r.employee_no === no);
        if (!row) { check('p5: ' + label + ' (' + no + ') is in the table', false); continue; }
        const want = expectedAdvisoryCount(row);
        const counts = await badgeCounts(ctx.page, no);
        measured('p5 ' + label + ' (' + no + ')', { prorate_days: row.prorate_days, prorate_total_days: row.prorate_total_days,
            calc_warnings: row.calc_warnings, expectedAdvisory: want, badges: counts });
        check('p5 ' + label + ': a warning badge exists exactly when there is something to say',
            (counts.warningBtns === 1) === (want > 0), counts.warningBtns + ' badge(s) for ' + want + ' note(s)');
        if (want > 0) {
            const tip = await openPopover(ctx.page, no, 'rd-calc-warning-btn');
            check('p5 ' + label + ': its list length matches the rule applied to the response',
                !!tip && tip.open && tip.items.length === want,
                (tip && tip.items ? tip.items.length : '-') + ' vs ' + want);
            const proated = tip && tip.items.filter((it) => it.code && it.code.indexOf('prorate_zero_days:') === 0);
            check('p5 ' + label + ': the prorate note is present exactly when prorate_days is 0',
                (proated ? proated.length : 0) === (isProrateZero(row) ? 1 : 0),
                JSON.stringify(tip && tip.items.map((i) => i.code)));
        }
    }

    // k4b's reserved row, addressed by employee_id (the fixture does not record its employee_no).
    const k4b = rows.find((r) => r.employee_id === FIXTURE.k4b_reserved);
    if (k4b) {
        const counts = await badgeCounts(ctx.page, k4b.employee_no);
        const want = expectedAdvisoryCount(k4b);
        measured('p5 k4b_reserved (' + k4b.employee_no + ')', { expectedAdvisory: want, badges: counts, row: k4b });
        check('p5 k4b_reserved: badge count follows the same rule',
            (counts.warningBtns === 1) === (want > 0), counts.warningBtns + ' vs ' + want);
    } else {
        check('p5: k4b_reserved is in the table', false, FIXTURE.k4b_reserved);
    }

    // The footer counts EMPLOYEES with at least one advisory note -- so it must equal the number of
    // rows that really rendered a warning badge, not the number of notes.
    const footer = await ctx.page.evaluate(() => {
        const rowsWithBadge = document.querySelectorAll('#tb_run_detail tbody tr .rd-calc-warning-btn').length;
        return { text: (document.querySelector('#rdFootCalcStatus') || {}).textContent || '', rowsWithBadge };
    });
    const expectedFooter = rows.filter((r) => expectedAdvisoryCount(r) > 0).length;
    measured('p5 footer', Object.assign({}, footer, { expectedFromResponse: expectedFooter }));
    check('p5: rows showing a warning badge = rows the rule says should',
        footer.rowsWithBadge === expectedFooter, footer.rowsWithBadge + ' vs ' + expectedFooter);
    check('p5: the footer prints that same number',
        expectedFooter === 0 || footer.text.indexOf(String(expectedFooter)) !== -1,
        footer.text + ' (expected to contain ' + expectedFooter + ')');
    reportOk('p5', ctx);
}

/* ---------------- cell 6: the slip shows the SAME strings ------------------------------------- */
async function cell6() {
    console.log('\n[p6] the slip -- same sentences, as callouts, with the same data-code');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);

    for (const label of ['R2', 'R4']) {
        const no = FIXTURE[label].employee_no;
        const row = rows.find((r) => r.employee_no === no);
        if (!row) { check('p6: ' + label + ' is in the table', false); continue; }

        // Collect what the 2 popovers say for this row, then close them before opening the modal.
        const errTip = row.calc_blocking.length ? await openPopover(ctx.page, no, 'rd-calc-error-btn') : null;
        const warnTip = expectedAdvisoryCount(row) > 0 ? await openPopover(ctx.page, no, 'rd-calc-warning-btn') : null;
        await ctx.page.keyboard.press('Escape');
        await ctx.page.waitForTimeout(300);

        await ctx.page.evaluate((n) => {
            const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
            const tr = trs.find((t) => (t.textContent || '').indexOf(n) !== -1);
            const btn = tr && tr.querySelector('.btn-view-breakdown');
            if (btn) btn.click();
        }, no);
        await ctx.page.waitForTimeout(900);

        const notes = await ctx.page.evaluate(() => {
            const host = document.querySelector('#breakdownCalcNotes');
            if (!host) return null;
            const read = (sel) => Array.from(host.querySelectorAll(sel)).map((el) => {
                const marked = el.querySelector('[data-code]');
                return { code: marked ? marked.getAttribute('data-code') : null, text: (el.textContent || '').trim() };
            });
            return { danger: read('.callout-danger'), warning: read('.callout-warning'),
                modalOpen: document.querySelectorAll('.modal.show').length };
        });
        measured('p6 ' + label + ' #breakdownCalcNotes', notes);
        check('p6 ' + label + ': the slip opened', !!notes && notes.modalOpen === 1, notes && notes.modalOpen);
        check('p6 ' + label + ': one danger callout per blocking code',
            !!notes && notes.danger.length === row.calc_blocking.length,
            (notes ? notes.danger.length : '-') + ' vs ' + row.calc_blocking.length);
        check('p6 ' + label + ': one warning callout per advisory note',
            !!notes && notes.warning.length === expectedAdvisoryCount(row),
            (notes ? notes.warning.length : '-') + ' vs ' + expectedAdvisoryCount(row));
        check('p6 ' + label + ': every callout carries its data-code',
            !!notes && notes.danger.concat(notes.warning).every((c) => !!c.code),
            JSON.stringify(notes && notes.danger.concat(notes.warning).map((c) => c.code)));
        if (errTip && errTip.open) {
            check('p6 ' + label + ': each danger callout is the SAME string as its popover <li>',
                notes.danger.every((c, i) => errTip.items[i] && c.text === errTip.items[i].text),
                JSON.stringify(notes.danger.map((c) => c.text)) + ' vs ' + JSON.stringify(errTip.items.map((i) => i.text)));
        }
        if (warnTip && warnTip.open) {
            check('p6 ' + label + ': each warning callout is the SAME string as its popover <li>',
                notes.warning.every((c, i) => warnTip.items[i] && c.text === warnTip.items[i].text),
                JSON.stringify(notes.warning.map((c) => c.text)) + ' vs ' + JSON.stringify(warnTip.items.map((i) => i.text)));
        }
        await ctx.page.evaluate(() => {
            const m = document.querySelector('.modal.show');
            if (m && window.bootstrap) bootstrap.Modal.getInstance(m).hide();
        });
        await ctx.page.waitForTimeout(600);
    }
    reportOk('p6', ctx);
}

/* ---------------- cell 6b: the same 2 callouts, in REAL dark ---------------------------------- */
/* The popover and the callout carry the same sentences, but they are 2 different surfaces with 2
   different backgrounds -- p2 proving the popover is readable in dark says nothing about the callout
   inside the slip, which paints `--c-bg-subtle` and a tone-coloured left border. Measured, not
   assumed, for the same reason p2 now applies the theme properly instead of labelling it. */
async function cell6dark() {
    console.log('\n[p6-dark] the slip callouts in REAL dark');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'dark', blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const theme = await applyAppTheme(ctx.page, 'dark');
    measured('p6-dark theme', theme);
    check('p6-dark: the page really is in dark theme, nothing measured if not', theme.ok === true, 'data-bs-theme=' + theme.stamp);
    if (!theme.ok) { reportOk('p6-dark', ctx); return; }

    const no = FIXTURE.R2.employee_no;   // has both a blocking list and an advisory one
    await ctx.page.evaluate((n) => {
        const trs = Array.from(document.querySelectorAll('#tb_run_detail tbody tr'));
        const tr = trs.find((t) => (t.textContent || '').indexOf(n) !== -1);
        const btn = tr && tr.querySelector('.btn-view-breakdown');
        if (btn) { btn.scrollIntoView({ block: 'center' }); btn.click(); }
    }, no);
    await ctx.page.waitForTimeout(900);

    const seen = await ctx.page.evaluate(() => {
        const host = document.querySelector('#breakdownCalcNotes');
        if (!host) return null;
        const read = (sel) => Array.from(host.querySelectorAll(sel)).map((el) => {
            const cs = getComputedStyle(el);
            return { color: cs.color, background: cs.backgroundColor, borderLeftColor: cs.borderLeftColor,
                text: (el.textContent || '').trim().slice(0, 40) };
        });
        return { danger: read('.callout-danger'), warning: read('.callout-warning'),
            modalOpen: document.querySelectorAll('.modal.show').length };
    });
    measured('p6-dark callouts', seen);
    check('p6-dark: the slip opened', !!seen && seen.modalOpen === 1, seen && seen.modalOpen);
    const all = (seen ? seen.danger : []).concat(seen ? seen.warning : []);
    check('p6-dark: there are callouts of both tones to measure', all.length >= 2, all.length);
    for (const c of all) {
        const ratio = contrastRatio(c.color, c.background);
        measured('p6-dark callout contrast', { text: c.text, color: c.color, background: c.background, contrast: ratio });
        check('p6-dark: callout text is the DARK --c-text', c.color === rgbOf(theme.text), c.color + ' vs ' + rgbOf(theme.text));
        check('p6-dark: its background is NOT the light surface it had before the theme applied',
            c.background !== 'rgb(255, 255, 255)' && c.background !== rgbOf('#F9FAFB'), c.background);
        check('p6-dark: callout text on its own background clears 4.5:1', ratio !== null && ratio >= 4.5, ratio);
    }
    const tones = all.map((c) => c.borderLeftColor);
    measured('p6-dark left-border tones', tones);
    check('p6-dark: danger and warning still carry DIFFERENT left borders in dark',
        !seen.danger.length || !seen.warning.length || seen.danger[0].borderLeftColor !== seen.warning[0].borderLeftColor,
        tones.join(' / '));
    reportOk('p6-dark', ctx);
}

/* ---------------- cell 7: employee 28, the control -------------------------------------------- */
async function cell7() {
    console.log('\n[p7] employee 28 -- the fixture never touched it');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);
    const row = rows.find((r) => r.employee_id === 28);
    if (!row) { check('p7: employee 28 is in this run', false); reportOk('p7', ctx); return; }

    const counts = await badgeCounts(ctx.page, row.employee_no);
    const want = expectedAdvisoryCount(row);
    measured('p7 employee 28 (' + row.employee_no + ')', { row, expectedAdvisory: want, badges: counts });
    check('p7: its status badge is still rendered once', counts.statusBadges >= 1, counts.statusBadges);
    check('p7: error button exists exactly when it has blocking codes',
        (counts.errorBtns === 1) === (row.calc_status === 'error' && row.calc_blocking.length > 0),
        counts.errorBtns + ' for ' + JSON.stringify(row.calc_blocking));
    check('p7: warning button exists exactly when the rule says',
        (counts.warningBtns === 1) === (want > 0), counts.warningBtns + ' vs ' + want);
    reportOk('p7', ctx);
}

/* ---------------- cell 8: sort + filter still read the enum, not the markup -------------------- */
async function cell8() {
    console.log('\n[p8] the Calculation column still sorts and filters on its value');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);

    const calcColIdx = await ctx.page.evaluate(() => {
        const api = tb_run_detail;
        const settings = api.settings()[0];
        const idx = settings.aoColumns.findIndex((c) => c.mData === 'calc_status');
        return idx;
    });
    measured('p8 calc_status column index', calcColIdx);

    const colData = await ctx.page.evaluate((idx) => ({
        sortValues: tb_run_detail.column(idx).data().toArray(),
        filterValues: Array.from(new Set(tb_run_detail.column(idx, { search: 'applied' }).data().toArray()
            .map((d, i) => d))),
        renderedFilter: Array.from(new Set(
            tb_run_detail.rows().data().toArray().map((r) => r.calc_status))),
    }), calcColIdx);
    measured('p8 column values', colData);

    const expectedSort = rows.map((r) => r.calc_status);
    check('p8: the column\'s own data is the raw enum, not the badge markup',
        JSON.stringify(colData.sortValues) === JSON.stringify(expectedSort),
        JSON.stringify(colData.sortValues.slice(0, 5)));
    check('p8: no column value contains any button/markup at all',
        colData.sortValues.every((v) => typeof v === 'string' && v.indexOf('<') === -1),
        JSON.stringify(colData.sortValues.slice(0, 3)));

    const filterTexts = await ctx.page.evaluate((idx) => {
        const api = tb_run_detail;
        return api.rows().data().toArray().map((r, i) => api.cell(i, idx).render('filter'));
    }, calcColIdx);
    const distinctFilter = Array.from(new Set(filterTexts)).sort();
    const distinctStatus = Array.from(new Set(rows.map((r) => r.calc_status))).sort();
    measured('p8 filter values', { distinctFilter, distinctStatus });
    check('p8: the filter offers one label per distinct calc_status, no more',
        distinctFilter.length === distinctStatus.length, distinctFilter.length + ' vs ' + distinctStatus.length);
    check('p8: no filter value carries markup or a raw error code',
        distinctFilter.every((v) => typeof v === 'string' && v.indexOf('<') === -1 && v.indexOf('rd-calc-') === -1),
        JSON.stringify(distinctFilter));

    // Sort ascending by that column, then read the order back out of the API.
    await ctx.page.evaluate((idx) => tb_run_detail.order([idx, 'asc']).draw(), calcColIdx);
    await ctx.page.waitForTimeout(600);
    const afterSort = await ctx.page.evaluate((idx) => tb_run_detail.column(idx, { order: 'applied' }).data().toArray(), calcColIdx);
    const expectedOrder = expectedSort.slice().sort();
    measured('p8 order after asc sort', afterSort);
    check('p8: sorting asc gives the enum order, computed from the response',
        JSON.stringify(afterSort) === JSON.stringify(expectedOrder),
        JSON.stringify(afterSort) + ' vs ' + JSON.stringify(expectedOrder));
    reportOk('p8', ctx);
}

/* ---------------- cell 9: en ------------------------------------------------------------------ */
async function cell9() {
    console.log('\n[p9] 768 en -- every word comes from en.json');
    const ctx = await openContext({ sessionId, width: 768, height: 1024, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'en');
    const lang = await ctx.page.evaluate(() => langData);
    const enJson = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'lang', 'en.json'), 'utf8'));
    check('p9: the page really switched to en', lang['calc_warnings_title'] === enJson['calc_warnings_title'],
        lang['calc_warnings_title']);

    const no = FIXTURE.R2.employee_no;
    const errTip = await openPopover(ctx.page, no, 'rd-calc-error-btn');
    const warnTip = await openPopover(ctx.page, no, 'rd-calc-warning-btn');
    measured('p9 titles', { error: errTip && errTip.title.trim(), warning: warnTip && warnTip.title.trim() });
    check('p9: the error popover title is en.json\'s calc_errors_title',
        !!errTip && errTip.title.trim() === enJson['calc_errors_title'], errTip && errTip.title.trim());
    check('p9: the warning popover title is en.json\'s calc_warnings_title',
        !!warnTip && warnTip.title.trim() === enJson['calc_warnings_title'], warnTip && warnTip.title.trim());

    const allItems = (errTip ? errTip.items : []).concat(warnTip ? warnTip.items : []);
    measured('p9 items', allItems);
    check('p9: no item shows a raw code as text', allItems.every((it) => it.text !== it.code && it.text.length > 0));
    // Every sentence must exist in en.json -- caught by finding the value for a code's own key.
    const unknownEn = allItems.filter((it) => it.code === 'zz_fixture_unknown');
    check('p9: the unknown code uses en.json\'s own neutral sentence',
        unknownEn.length === 0 || unknownEn[0].text === enJson['calc_error_unknown'],
        unknownEn.length ? unknownEn[0].text : '-');
    reportOk('p9', ctx);
}

/* ---------------- cell 10: banner spacing ----------------------------------------------------- */
async function readBannerGeometry(page) {
    return page.evaluate(() => {
        const wrap = document.querySelector('.rd-run-banners');
        const tabs = document.querySelector('#runDetailTabs');
        const vis = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
        const kids = wrap ? Array.from(wrap.children).filter((el) => el.nodeType === 1) : [];
        const visible = kids.filter(vis).map((el) => {
            const r = el.getBoundingClientRect();
            return { id: el.id, top: r.top, bottom: r.bottom, left: Math.round(r.left * 10) / 10,
                right: Math.round(r.right * 10) / 10, cls: el.className };
        });
        const tabsRect = tabs.getBoundingClientRect();
        // The element the wrapper follows -- what "the gap before the tabs" was measured against.
        const prev = wrap ? wrap.previousElementSibling : null;
        const prevRect = prev ? prev.getBoundingClientRect() : null;
        const gaps = [];
        for (let i = 1; i < visible.length; i++) {
            gaps.push(Math.round((visible[i].top - visible[i - 1].bottom) * 10) / 10);
        }
        return {
            visibleIds: visible.map((v) => v.id),
            visible,
            gapsBetween: gaps,
            gapToTabs: visible.length
                ? Math.round((tabsRect.top - visible[visible.length - 1].bottom) * 10) / 10
                : null,
            gapPrevBlockToTabs: prevRect ? Math.round((tabsRect.top - prevRect.bottom) * 10) / 10 : null,
            prevId: prev ? (prev.id || prev.className) : null,
            tabsLeft: Math.round(tabsRect.left * 10) / 10,
            tabsRight: Math.round(tabsRect.right * 10) / 10,
            wrapperHeight: wrap ? Math.round(wrap.getBoundingClientRect().height * 10) / 10 : null,
        };
    });
}
async function cell10() {
    console.log('\n[p10] the run-level banner stack -- 12 between, 16 to the tab bar');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await ctx.page.goto(ctx.url('/payroll-process/' + runToken), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(1200);
    const g = await readBannerGeometry(ctx.page);
    measured('p10 fixture run', g);
    check('p10: the wrapper exists and holds the run-level boxes', g.visibleIds !== null);
    if (g.visible.length >= 2) {
        check('p10: every gap between two visible banners is 12px',
            g.gapsBetween.every((v) => Math.abs(v - 12) <= 0.5), JSON.stringify(g.gapsBetween));
    } else {
        console.log('  NOTE  p10: this run shows ' + g.visible.length + ' banner(s) -- the between-gap is not measurable here.');
    }
    if (g.visible.length >= 1) {
        check('p10: the last visible banner sits 16px above the tab bar',
            Math.abs(g.gapToTabs - 16) <= 0.5, g.gapToTabs);
        check('p10: every visible banner still spans exactly the tab bar\'s width',
            g.visible.every((b) => Math.abs(b.left - g.tabsLeft) <= 0.5 && Math.abs(b.right - g.tabsRight) <= 0.5),
            JSON.stringify(g.visible.map((b) => [b.left, b.right])));
    } else {
        check('p10: the fixture run shows at least one banner (has_validation_errors=1)', false, JSON.stringify(g.visibleIds));
    }
    reportOk('p10a', ctx);

    console.log('\n[p10b] run 1015 -- no banner at all, so the spacing before it is unchanged');
    const ctx2 = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await ctx2.page.goto(ctx2.url('/payroll-process/' + LOCKED_RUN_TOKEN), { waitUntil: 'networkidle' });
    await ctx2.page.waitForTimeout(1200);
    const g2 = await readBannerGeometry(ctx2.page);
    measured('p10b run 1015', g2);
    // "Unchanged from before" is measured, not asserted against a number typed here: the wrapper's own
    // 3 declarations are stripped off the live element (leaving exactly what the 4 bare siblings were
    // before this round -- plain blocks, no flex, no gap, no margin) and the SAME distance is read
    // again. Equal means the wrapper contributes nothing when no banner is visible, which is the
    // actual requirement; a hard-coded 24 would only have asserted a guess about Bootstrap's mb-4.
    if (g2.visible.length === 0) {
        check('p10b: a hidden banner takes up no height at all', g2.wrapperHeight === 0, g2.wrapperHeight);
        const asBefore = await ctx2.page.evaluate(() => {
            const wrap = document.querySelector('.rd-run-banners');
            const tabs = document.querySelector('#runDetailTabs');
            const prev = wrap.previousElementSibling;
            const dist = () => Math.round((tabs.getBoundingClientRect().top - prev.getBoundingClientRect().bottom) * 10) / 10;
            const withWrapper = dist();
            const cs = getComputedStyle(wrap);
            const applied = { display: cs.display, rowGap: cs.rowGap, marginTop: cs.marginTop, marginBottom: cs.marginBottom };
            wrap.style.display = 'block';
            wrap.style.gap = '0';
            wrap.style.margin = '0';
            const withoutWrapper = dist();
            wrap.removeAttribute('style');
            return { withWrapper, withoutWrapper, applied };
        });
        measured('p10b gap with vs without the wrapper own styles', asBefore);
        check('p10b: with no banner visible the wrapper adds nothing -- same gap either way',
            Math.abs(asBefore.withWrapper - asBefore.withoutWrapper) <= 0.5,
            asBefore.withWrapper + ' vs ' + asBefore.withoutWrapper);
        check('p10b: and it carries no margin of its own in this state',
            asBefore.applied.marginTop === '0px' && asBefore.applied.marginBottom === '0px',
            JSON.stringify(asBefore.applied));
    } else {
        console.log('  NOTE  p10b: run 1015 is showing ' + g2.visible.length + ' banner(s) -- the no-banner case is not measurable here.');
    }
    reportOk('p10b', ctx2);

    /* The case the user actually reported: TWO boxes visible at once, which before this round
       rendered as one box with a 2-coloured left edge. run 29685 is a REAL run in this dev DB that
       shows both #validationErrorsBanner (has_validation_errors=1) and #syncMissingEmployeesBanner
       (sync_process_id=191, still draft) -- the same run m3e1_tabs_shared.js's c14 opens, and for the
       same reason. Opened read-only, auto_recalculate=0, every write route blocked. No mock needed:
       if this run ever stops showing 2, the check below says so instead of quietly measuring one. */
    console.log('\n[p10c] the 2-banner case the report was about');
    measured('p10c banner run source', BANNER_RUN.source + ' -> ' + (BANNER_RUN.token || 'none'));
    const ctx3 = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    if (!BANNER_RUN.token) {
        console.log('  MOCK  p10c: no draft run in this DB carries has_validation_errors=1 + a sync process --');
        console.log('  MOCK  p10c: has_validation_errors is patched into api/payroll-run.get on the fixture run.');
        await mockBannerFields(ctx3.page);
    }
    await ctx3.page.goto(ctx3.url('/payroll-process/' + (BANNER_RUN.token || runToken)), { waitUntil: 'networkidle' });
    await ctx3.page.waitForTimeout(1400);
    const g3 = await readBannerGeometry(ctx3.page);
    measured('p10c banner run', g3);
    check('p10c: this run really shows 2 banners in the wrapper', g3.visible.length === 2,
        g3.visible.length + ' -> ' + JSON.stringify(g3.visibleIds));
    if (g3.visible.length === 2) {
        check('p10c: the gap between the 2 boxes is 12px -- they are no longer one box with 2 edges',
            Math.abs(g3.gapsBetween[0] - 12) <= 0.5, g3.gapsBetween[0]);
        check('p10c: the last box sits 16px above the tab bar', Math.abs(g3.gapToTabs - 16) <= 0.5, g3.gapToTabs);
        check('p10c: both boxes span exactly the tab bar width',
            g3.visible.every((b) => Math.abs(b.left - g3.tabsLeft) <= 0.5 && Math.abs(b.right - g3.tabsRight) <= 0.5),
            JSON.stringify(g3.visible.map((b) => [b.left, b.right])));
        check('p10c: they carry DIFFERENT tone classes (this is the pair that read as one box)',
            g3.visible[0].cls !== g3.visible[1].cls, g3.visible.map((b) => b.cls).join(' | '));
    }
    reportOk('p10c', ctx3);
}

async function main() {
    console.log('fixture rows: ' + Object.keys(FIXTURE).filter((k) => /^R\d$/.test(k))
        .map((k) => k + '=' + FIXTURE[k].employee_no).join(' '));
    try {
        await cell1();
        await cell2();
        await cell3();
        await cell4();
        await cell5();
        await cell6();
        await cell6dark();
        await cell7();
        await cell8();
        await cell9();
        await cell10();
    } finally {
        await closeAll();
    }
    console.log('\nPassed: ' + passed + ', Failed: ' + failed);
    if (failed > 0) process.exitCode = 1;
}
main();
