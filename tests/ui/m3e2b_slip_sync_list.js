/**
 * 3e-2b measurement: the slip's raw-sync panel, the Payroll List's error pill/modal, and the
 * run-level merge banners.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/m3e2b_slip_sync_list.js <PHPSESSID> <fixtureRunToken> [<lockedRunToken>]
 *
 * NOTHING here is expected against a number typed into this file: every count comes from the run's
 * own /api/payroll-run.details or /api/payroll-run.error-employees reply at run time, read in the
 * same cell that asserts against it. The 7 fixture rows come from tests/ui/.last-session.json's
 * `calc_error_fixture` (mksession.php --with-calc-errors).
 *
 * 9 cells, each its own context:
 *   s1  1400 th light, the locked sync run -- one link per sync row, the panel is a panel (not a
 *       2nd modal), its section count is derived from the payload, its spacing is 12/16, and
 *       open-close-open fetches once
 *   s2  the same run -- a panel opened on employee A is closed, empty and collapsed on employee B
 *   s3  the fixture run in EDIT mode, with the sync fields MOCKed -- one row gains a link, the rest
 *       do not, the slip's own controls still work, and a `status:false` reply reads inline
 *   s4  the fixture run for real (sync_process_id NULL) -- no link anywhere, and the spacing above
 *       the callout is the 16 the panel's absence is supposed to leave
 *   s5  430x932 th dark -- the panel fits, its own table scrolls inside itself, contrast >= 4.5
 *   s6  768 en -- link text, section headings and the inline refusal all come from en.json
 *   s7  the Payroll List, 1400 th light -- the pill is the shared count badge with no icon, and its
 *       modal prints sentences, never codes
 *   s8  the Payroll List, 430 th dark -- the modal fits and the pill does not overflow its column
 *   s9  the merge banners (MOCKed fields) -- neutral, not primary; the waiting one still swaps
 *
 * ORDER: run this file BEFORE tests/ui/h_history_table.js. That round seeds real line-override and
 * manual-line writes onto the same fixture run, which makes the run recalculate and replaces the
 * calc_errors mksession injected into R1/R2 -- s7/s8 read those back. s7/s8 check that precondition
 * and name it rather than reporting a wrong count.
 *
 * Writes: none. Every mutating route of BOTH pages is in WRITE_PATHS (the harness blocks
 * user-preference.save and recalculate by itself) and every cell asserts blockedWrites === 0 --
 * i.e. it never even attempted one. api/payroll-run.error-employees and
 * api/payroll-run.raw-sync-data-for-employee are GETs under test and are deliberately NOT blocked.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const { openContext, closeAll, applyAppTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
if (!sessionId || !runToken) {
    throw new Error('usage: node tests/ui/m3e2b_slip_sync_list.js <PHPSESSID> <fixtureRunToken> [<lockedRunToken>]');
}
/* run 1015 -- locked, sync_process_id set, 2 rows and both of them data_source='sync'. The same run
   m3e1_tabs_shared.js and m3e2a_calc_badges.js open read-only for their own after-approval cells. */
const LOCKED_RUN_TOKEN = process.argv[4] || 'AV_3zH_fu0Ep03a_cT_NpIvAW19tk9dMf0mw0_k69wur';

const STATE = JSON.parse(fs.readFileSync(path.join(__dirname, '.last-session.json'), 'utf8'));
const FIXTURE = STATE.calc_error_fixture;
if (!FIXTURE) {
    throw new Error('tests/ui/.last-session.json has no calc_error_fixture -- re-run:\n'
        + '  php tests/ui/mksession.php --with-recurring --with-calc-errors');
}

/* Every route either page can write through. The Detail half is m3e1/m3e2a's list; the List half was
   read out of payroll/index.js's own `api/...` strings this round (delete/cancel/submit/lock/
   mark-paid/save/verify-all/the 2 merges/the 3 sync ones/report.generate -- a GET that still writes
   report_export_logs). The 2 GETs under test are not here on purpose. */
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
    '/api/payroll-run.delete',
    '/api/payroll-run.cancel',
    '/api/payroll-run.submit',
    '/api/payroll-run.lock',
    '/api/payroll-run.mark-paid',
    '/api/payroll-run.save',
    '/api/payroll-run.merge-into-existing',
    '/api/payroll-run.merge-supplemental',
    '/api/payroll-sync.reject',
    '/api/payroll-sync.blocked-update-apply',
    '/api/payroll-sync.blocked-update-dismiss',
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
function reportOk(label, ctx) {
    const rep = ctx.report();
    measured(label + ' report', rep);
    check(label + ': nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    check(label + ': no page errors', rep.pageErrors.length === 0, rep.pageErrors.join(' | '));
}

async function gotoRun(ctx, token, lang) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    if (lang) {
        await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
        await ctx.page.waitForTimeout(500);
    }
    await ctx.page.click('#run-employee-tab');
    await ctx.page.waitForTimeout(900);
}
/* The rows the table itself is holding -- read out of the live DataTable, so what is asserted is
   exactly what is rendered, not a second fetch that could disagree with it. */
async function rowsFromTable(page) {
    return page.evaluate(() => (typeof tb_run_detail !== 'undefined' && tb_run_detail)
        ? tb_run_detail.rows().data().toArray().map((r) => ({
            employee_id: Number(r.employee_id),
            employee_no: r.employee_no,
            data_source: r.data_source,
        }))
        : []);
}
async function openSlip(page, employeeId) {
    await page.click('.btn-view-breakdown[data-employee-id="' + employeeId + '"]');
    await page.waitForTimeout(900);
}
async function closeSlip(page) {
    await page.keyboard.press('Escape');
    await page.waitForTimeout(700);
}
/* One reading of the whole disclosure: the button, the panel, and the distances between the header
   card, the panel and whatever block comes next. */
async function slipPicture(page) {
    return page.evaluate(() => {
        const modal = document.querySelector('#runDetailBreakdownModal');
        const card = document.querySelector('#breakdownHeaderCard .emp-header-card');
        const btn = document.querySelector('#btnRawSyncPanel');
        const panel = document.querySelector('#rawSyncPanel');
        const notes = document.querySelector('#breakdownCalcNotes');
        const vis = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
        const r = (el) => { const b = el.getBoundingClientRect(); return { top: Math.round(b.top * 10) / 10, bottom: Math.round(b.bottom * 10) / 10, left: Math.round(b.left * 10) / 10, right: Math.round(b.right * 10) / 10, width: Math.round(b.width * 10) / 10 }; };
        // "The block after" is whichever sibling of the panel is the next VISIBLE one -- the notes
        // strip collapses itself when a row has none, so naming it outright would measure a
        // different thing on a row with no callouts than on a row with them.
        let next = panel ? panel.nextElementSibling : null;
        while (next && !vis(next)) next = next.nextElementSibling;
        const ps = panel ? getComputedStyle(panel) : null;
        return {
            modalsOpen: document.querySelectorAll('.modal.show').length,
            btns: document.querySelectorAll('#breakdownHeaderCard .btn-raw-sync-toggle').length,
            btnIcons: btn ? btn.querySelectorAll('i, svg').length : null,
            expanded: btn ? btn.getAttribute('aria-expanded') : null,
            controls: btn ? btn.getAttribute('aria-controls') : null,
            btnText: btn ? (btn.textContent || '').trim() : null,
            panelVisible: vis(panel),
            panelEmpty: panel ? panel.innerHTML.trim() === '' : null,
            panelBg: ps ? ps.backgroundColor : null,
            panelBorder: ps ? ps.borderTopColor : null,
            panelBorderWidth: ps ? ps.borderTopWidth : null,
            sectionCards: panel ? panel.querySelectorAll('.rd-sync-section').length : 0,
            sectionTitles: panel ? Array.from(panel.querySelectorAll('.rd-sync-section > .rd-sync-section-title')).map((h) => (h.textContent || '').trim()) : [],
            // 3e-2b round 1 (U4): the sections wrap against the PANEL's width. Distinct `top` values
            // = how many rows they ended up on; all equal = one row.
            sectionTops: panel ? Array.from(panel.querySelectorAll('.rd-sync-section')).map((c) => Math.round(c.getBoundingClientRect().top * 10) / 10) : [],
            sectionBorder: (() => { const c = panel ? panel.querySelector('.rd-sync-section') : null;
                return c ? getComputedStyle(c).borderTopWidth : null; })(),
            // 2026-09-22, slip2-b: the section is a card now -- its own surface, and the line-items
            // one takes the whole row at the end of the grid.
            sectionBg: (() => { const c = panel ? panel.querySelector('.rd-sync-section') : null;
                return c ? getComputedStyle(c).backgroundColor : null; })(),
            wideCards: panel ? panel.querySelectorAll('.rd-sync-section-wide').length : 0,
            wideCardIsLast: (() => {
                const all = panel ? Array.from(panel.querySelectorAll('.rd-sync-section')) : [];
                return all.length ? all[all.length - 1].classList.contains('rd-sync-section-wide') : null;
            })(),
            // The box that caps the panel's height, and what a keyboard needs to reach it.
            scroll: (() => {
                const b = panel ? panel.querySelector('.rd-sync-scroll') : null;
                if (!b) return null;
                const cs = getComputedStyle(b);
                return { maxH: cs.maxHeight, clientH: b.clientHeight, scrollH: b.scrollHeight,
                    tabindex: b.getAttribute('tabindex'), role: b.getAttribute('role'),
                    ariaLabel: b.getAttribute('aria-label') };
            })(),
            firstCardRowH: (() => {
                const all = panel ? Array.from(panel.querySelectorAll('.rd-sync-section')) : [];
                if (!all.length) return null;
                const top = all[0].getBoundingClientRect().top;
                let bottom = 0;
                for (const c of all) { const r = c.getBoundingClientRect(); if (r.top > top + 1) break; bottom = Math.max(bottom, r.bottom); }
                return Math.round(bottom - top);
            })(),
            sectionOverflow: panel ? Array.from(panel.querySelectorAll('.rd-sync-section')).map((c) => c.scrollWidth - c.clientWidth) : [],
            panelH: panel ? Math.round(panel.getBoundingClientRect().height * 10) / 10 : null,
            panelW: panel ? Math.round(panel.getBoundingClientRect().width * 10) / 10 : null,
            // U3: the item-code cells of the line-items table.
            codeColor: (() => { const c = panel ? panel.querySelector('code') : null;
                return c ? { color: getComputedStyle(c).color, fontSize: getComputedStyle(c).fontSize } : null; })(),
            // U2: the "no shift assigned" line, a callout now.
            shiftNote: (() => {
                const co = panel ? panel.querySelector('.callout') : null;
                if (!co) return null;
                const cs = getComputedStyle(co);
                return { cls: co.className, icons: co.querySelectorAll('i, svg').length,
                    color: cs.color, bg: cs.backgroundColor, text: (co.textContent || '').trim().slice(0, 40) };
            })(),
            legacyPedPanels: panel ? panel.querySelectorAll('.ped-type-panel').length : 0,
            writableControls: panel ? panel.querySelectorAll('input, select, textarea, button[type="submit"]').length : 0,
            panelText: panel ? (panel.textContent || '').replace(/\s+/g, ' ').trim() : '',
            gapCardToPanel: (card && panel && vis(panel)) ? Math.round((r(panel).top - r(card).bottom) * 10) / 10 : null,
            gapPanelToNext: (panel && next && vis(panel)) ? Math.round((r(next).top - r(panel).bottom) * 10) / 10 : null,
            gapCardToNotes: (card && vis(notes)) ? Math.round((r(notes).top - r(card).bottom) * 10) / 10 : null,
            gapNotesToNext: null,
            nextId: next ? (next.id || next.className) : null,
            modalBodyOverflow: modal ? (() => { const b = modal.querySelector('.modal-body'); return b ? { scrollWidth: b.scrollWidth, clientWidth: b.clientWidth } : null; })() : null,
        };
    });
}
/* Counts the requests the page really made to the raw-sync endpoint, so "opened twice, fetched once"
   is a measurement and not an assumption about the cache variable. */
function countRawSyncCalls(ctx) {
    const state = { n: 0 };
    ctx.page.on('request', (req) => {
        if (req.url().indexOf('/api/payroll-run.raw-sync-data-for-employee') !== -1) state.n++;
    });
    return state;
}
/* The payload itself, fetched by the page's own session, so a cell can restate what the panel is
   supposed to show without trusting the panel to tell it. */
async function rawSyncPayload(page, runId, employeeId) {
    return page.evaluate(async (a) => {
        const res = await fetch(BASE_URL + '/api/payroll-run.raw-sync-data-for-employee?run_id=' + a.runId + '&employee_id=' + a.employeeId, { credentials: 'same-origin' });
        return res.json();
    }, { runId, employeeId });
}
/* The section -> fields grouping is the PAGE's configuration (RAW_SYNC_DATA_SECTIONS_RD); which of
   those sections is empty for a given payload is the thing under test, and that half is computed
   here, from the response, by the rule the round's own spec states (null/''/0 all count as empty). */
async function expectedSectionCount(page, data) {
    const sections = await page.evaluate(() => (typeof RAW_SYNC_DATA_SECTIONS_RD !== 'undefined')
        ? RAW_SYNC_DATA_SECTIONS_RD.map((s) => s.fields) : null);
    if (!sections) return null;
    const isEmpty = (v) => {
        if (v === null || v === undefined || v === '') return true;
        const n = Number(v);
        return !Number.isNaN(n) && n === 0;
    };
    return sections.filter((fields) => !fields.every((k) => isEmpty(data[k]))).length;
}

/* ---- colour helpers, same maths m3e2a uses (WCAG relative luminance) ---- */
function parseRgb(s) {
    const m = /rgba?\(([^)]+)\)/.exec(s || '');
    if (!m) return null;
    const p = m[1].split(',').map((x) => parseFloat(x.trim()));
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
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

/* ================= s1: the locked sync run, 1400 th light ================= */
async function s1(opts) {
    const o = opts || {};
    const tag = o.tag || 's1';
    console.log('\n[' + tag + '] ' + (o.width || 1400) + (o.theme === 'dark' ? ' th dark' : ' th light') + ' -- the raw-sync panel on a sync run');
    const ctx = await openContext({ sessionId, width: o.width || 1400, height: o.height || 950, blockPaths: WRITE_PATHS });
    const calls = countRawSyncCalls(ctx);
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    if (o.theme === 'dark') {
        const t = await applyAppTheme(ctx.page, 'dark');
        measured(tag + ' theme', t);
        check(tag + ': the page really is in the dark theme before anything is measured', t.ok === true, t.stamp);
        if (!t.ok) { reportOk(tag, ctx); return; }
    }
    const rows = await rowsFromTable(ctx.page);
    measured(tag + ' rows', rows);
    check(tag + ': the table really loaded its rows', rows.length > 0, rows.length);
    const runId = await ctx.page.evaluate(() => (typeof currentRun !== 'undefined' && currentRun) ? currentRun.id : null);
    const syncProcessId = await ctx.page.evaluate(() => (typeof currentRun !== 'undefined' && currentRun) ? currentRun.sync_process_id : null);
    measured(tag + ' currentRun', { runId, syncProcessId });
    check(tag + ': this run really came from a sync', !!syncProcessId, syncProcessId);

    for (const row of rows) {
        const expectLink = row.data_source === 'sync' ? 1 : 0;
        await openSlip(ctx.page, row.employee_id);
        let pic = await slipPicture(ctx.page);
        measured(tag + ' ' + row.employee_no + ' (' + row.data_source + ') closed', {
            btns: pic.btns, expanded: pic.expanded, panelVisible: pic.panelVisible, modalsOpen: pic.modalsOpen });
        check(tag + ' ' + row.employee_no + ': link count = ' + expectLink + ' (data_source=' + row.data_source + ')',
            pic.btns === expectLink, pic.btns);
        check(tag + ' ' + row.employee_no + ': exactly 1 modal is open', pic.modalsOpen === 1, pic.modalsOpen);
        if (expectLink === 0) { await closeSlip(ctx.page); continue; }

        check(tag + ' ' + row.employee_no + ': the link carries no icon/caret', pic.btnIcons === 0, pic.btnIcons);
        check(tag + ' ' + row.employee_no + ': it starts collapsed', pic.expanded === 'false', pic.expanded);
        check(tag + ' ' + row.employee_no + ': aria-controls names the panel', pic.controls === 'rawSyncPanel', pic.controls);
        // U1: it has to read as the same kind of control as the slip's own group-head links. Run 1015
        // is read-only so `.lo-add-line-btn` is never rendered on it -- a probe element carrying that
        // exact class is inserted beside the real button, read, and removed, so the comparison is
        // against the live stylesheet instead of a colour typed into this file.
        const linkStyle = await ctx.page.evaluate(() => {
            const btn = document.querySelector('#btnRawSyncPanel');
            const probe = document.createElement('button');
            probe.className = 'btn btn-link lo-add-line-btn';
            probe.textContent = 'probe';
            btn.parentNode.appendChild(probe);
            const g = (el) => { const c = getComputedStyle(el); return { color: c.color, fontSize: c.fontSize, textDecorationLine: c.textDecorationLine, cursor: c.cursor }; };
            const out = { link: g(btn), model: g(probe) };
            probe.remove();
            return out;
        });
        measured(tag + ' ' + row.employee_no + ' link vs group-head link', linkStyle);
        check(tag + ' ' + row.employee_no + ': same computed look as the group-head add link',
            JSON.stringify(linkStyle.link) === JSON.stringify(linkStyle.model),
            JSON.stringify(linkStyle.link) + ' vs ' + JSON.stringify(linkStyle.model));
        const labels = await ctx.page.evaluate(() => ({
            open: langData['raw_sync_panel_link'], close: langData['raw_sync_panel_hide'],
        }));
        check(tag + ' ' + row.employee_no + ': closed, it reads the "show" key', pic.btnText === labels.open,
            '"' + pic.btnText + '" vs "' + labels.open + '"');

        const payload = await rawSyncPayload(ctx.page, runId, row.employee_id);
        check(tag + ' ' + row.employee_no + ': the endpoint really answers for this row', !!(payload && payload.status), JSON.stringify(payload && payload.message));
        const expectSections = payload && payload.status ? await expectedSectionCount(ctx.page, payload.data) : null;

        const before = calls.n;
        await ctx.page.click('#btnRawSyncPanel');
        await ctx.page.waitForTimeout(1200);
        pic = await slipPicture(ctx.page);
        measured(tag + ' ' + row.employee_no + ' open', pic);
        check(tag + ' ' + row.employee_no + ': aria-expanded is true', pic.expanded === 'true', pic.expanded);
        check(tag + ' ' + row.employee_no + ': the panel is visible', pic.panelVisible === true);
        check(tag + ' ' + row.employee_no + ': STILL exactly 1 modal -- the panel is not a 2nd modal',
            pic.modalsOpen === 1, pic.modalsOpen);
        /* 2026-09-22, slip2-b: +1. "Additional Line Items" is a card of the SAME grid now instead of a
           bare heading trailing under it -- it is one more thing Origami sent, and it was the only
           block in the panel that did not look like one. */
        check(tag + ' ' + row.employee_no + ': section cards = the non-empty sections of the response, plus the line-items one',
            pic.sectionCards === expectSections + 1, pic.sectionCards + ' vs ' + (expectSections + 1));
        check(tag + ' ' + row.employee_no + ': ...and that one takes the whole row',
            pic.wideCards === 1 && pic.wideCardIsLast === true, JSON.stringify({ wide: pic.wideCards, last: pic.wideCardIsLast }));
        check(tag + ' ' + row.employee_no + ': open, the link reads the "hide" key', pic.btnText === labels.close,
            '"' + pic.btnText + '" vs "' + labels.close + '"');
        measured(tag + ' ' + row.employee_no + ' panel size / section rows',
            { panelW: pic.panelW, panelH: pic.panelH, tops: pic.sectionTops, border: pic.sectionBorder });
        check(tag + ' ' + row.employee_no + ': the old .ped-type-panel card is gone', pic.legacyPedPanels === 0, pic.legacyPedPanels);
        check(tag + ' ' + row.employee_no + ': no section overflows its own box',
            pic.sectionOverflow.every((v) => v <= 0), JSON.stringify(pic.sectionOverflow));
        if ((o.width || 1400) >= 1400) {
            // 2026-09-22, slip2-b: the FIELD sections share one row; the line-items card takes a row of
            // its own because it is a table (`grid-column: 1 / -1`).
            const fieldTops = pic.sectionTops.slice(0, pic.sectionTops.length - 1);
            check(tag + ' ' + row.employee_no + ': at 1400 every field section sits on ONE row',
                new Set(fieldTops).size === 1 && fieldTops.length === 3, JSON.stringify(pic.sectionTops));
        }
        if ((o.width || 1400) <= 430) {
            check(tag + ' ' + row.employee_no + ': at 430 each section has a row to itself',
                new Set(pic.sectionTops).size === pic.sectionTops.length, JSON.stringify(pic.sectionTops));
        }
        if (pic.codeColor) {
            const muted = await ctx.page.evaluate(() => {
                const cs = getComputedStyle(document.documentElement);
                const hex = cs.getPropertyValue('--c-text-muted').trim().replace('#', '');
                const v = parseInt(hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex, 16);
                return { muted: 'rgb(' + ((v >> 16) & 255) + ', ' + ((v >> 8) & 255) + ', ' + (v & 255) + ')',
                    panelFS: getComputedStyle(document.querySelector('#rawSyncPanel')).fontSize };
            });
            measured(tag + ' ' + row.employee_no + ' <code>', { code: pic.codeColor, expected: muted });
            check(tag + ' ' + row.employee_no + ': the item code is --c-text-muted, not Bootstrap pink',
                pic.codeColor.color === muted.muted, pic.codeColor.color + ' vs ' + muted.muted);
            check(tag + ' ' + row.employee_no + ': ...and the same size as the text beside it',
                pic.codeColor.fontSize === muted.panelFS, pic.codeColor.fontSize + ' vs ' + muted.panelFS);
        }
        if (pic.shiftNote) {
            measured(tag + ' ' + row.employee_no + ' shift note', pic.shiftNote);
            check(tag + ' ' + row.employee_no + ': the shift warning is a warning callout',
                /(^| )callout-warning( |$)/.test(pic.shiftNote.cls), pic.shiftNote.cls);
            check(tag + ' ' + row.employee_no + ': it carries no icon', pic.shiftNote.icons === 0, pic.shiftNote.icons);
            const cr = contrastRatio(pic.shiftNote.color, pic.shiftNote.bg);
            measured(tag + ' ' + row.employee_no + ' shift note contrast', cr);
            check(tag + ' ' + row.employee_no + ': its text clears 4.5:1', cr !== null && cr >= 4.5, cr);
        } else {
            console.log('  NOTE  ' + tag + ' ' + row.employee_no + ': this payload has a shift pattern -- the warning line is covered by s3.');
        }
        check(tag + ' ' + row.employee_no + ': nothing in the panel can be typed into or submitted',
            pic.writableControls === 0, pic.writableControls);
        const tokens = await ctx.page.evaluate(() => {
            const cs = getComputedStyle(document.documentElement);
            const hex = (n) => cs.getPropertyValue(n).trim();
            const toRgb = (h) => { const x = h.replace('#', ''); const v = parseInt(x.length === 3 ? x.split('').map((c) => c + c).join('') : x, 16); return 'rgb(' + ((v >> 16) & 255) + ', ' + ((v >> 8) & 255) + ', ' + (v & 255) + ')'; };
            return { bg: toRgb(hex('--c-bg')), border: toRgb(hex('--c-border')), bgSubtle: toRgb(hex('--c-bg-subtle')) };
        });
        measured(tag + ' tokens', tokens);
        /* 2026-09-22, slip2-b: the panel draws NOTHING of its own now -- with a card per heading
           inside it and the dialog's frame around it, its border was the third nested frame, and
           the outer one is the one that says least. */
        /* 2026-09-22, slip2-b: the border moved INWARDS. 3e-2b took it off the sections because a box
           inside a box says nothing; slip2-b agrees and drops the OUTER one instead -- the panel --
           so the reader sees one frame per heading rather than one frame around everything. §5's
           panel rule, the same 3 declarations `.lo-history-panel` uses. */
        check(tag + ' ' + row.employee_no + ': every section is a card of its own', pic.sectionBorder === '1px'
            && pic.sectionBg === tokens.bg, pic.sectionBorder + ' / ' + pic.sectionBg);
        check(tag + ' ' + row.employee_no + ': the panel itself paints nothing',
            pic.panelBg === 'rgba(0, 0, 0, 0)' || pic.panelBg === 'transparent', pic.panelBg);
        check(tag + ' ' + row.employee_no + ': ...and draws no border either',
            pic.panelBorderWidth === '0px', pic.panelBorderWidth);
        check(tag + ' ' + row.employee_no + ': 12px from the header card',
            Math.abs(pic.gapCardToPanel - 12) <= 0.5, pic.gapCardToPanel);
        check(tag + ' ' + row.employee_no + ': 16px to the block below it (' + pic.nextId + ')',
            Math.abs(pic.gapPanelToNext - 16) <= 0.5, pic.gapPanelToNext);
        check(tag + ' ' + row.employee_no + ': opening it made exactly 1 request', calls.n - before === 1, calls.n - before);

        /* ---- 2026-09-22, slip2-b: the panel keeps its own height, and the slip stays readable ----
           The dialog is `modal-xl` now (§9: a dialog that carries a table), read from the CSS rather
           than typed here so this does not have to be re-edited if the scale ever moves. */
        const shape = await ctx.page.evaluate(() => {
            const modal = document.querySelector('#runDetailBreakdownModal');
            const content = modal.querySelector('.modal-content');
            const body = modal.querySelector('.modal-body');
            const firstRow = document.querySelector('#breakdownLineOverrideWrap tr.lo-row');
            const probe = document.createElement('div');
            probe.className = 'modal-dialog modal-xl';
            probe.style.position = 'absolute';
            probe.style.visibility = 'hidden';
            document.body.appendChild(probe);
            const declaredXl = getComputedStyle(probe).getPropertyValue('--bs-modal-width').trim();
            probe.remove();
            return {
                dialogCls: modal.querySelector('.modal-dialog').className,
                contentW: Math.round(content.getBoundingClientRect().width * 10) / 10,
                declaredXl,
                bodyScrollTop: body.scrollTop,
                bodyBottom: Math.round(body.getBoundingClientRect().bottom * 10) / 10,
                firstRowBottom: firstRow ? Math.round(firstRow.getBoundingClientRect().bottom * 10) / 10 : null,
                vh45: Math.round(window.innerHeight * 0.45),
            };
        });
        measured(tag + ' ' + row.employee_no + ' modal / fold', shape);
        check(tag + ' ' + row.employee_no + ': the slip is the xl dialog',
            /modal-xl/.test(shape.dialogCls) && !/modal-lg/.test(shape.dialogCls), shape.dialogCls);
        if ((o.width || 1400) >= 1400) {
            check(tag + ' ' + row.employee_no + ': ...at the width the scale declares for xl',
                shape.declaredXl !== '' && Math.abs(shape.contentW - parseFloat(shape.declaredXl)) <= 0.5,
                shape.contentW + ' vs ' + shape.declaredXl);
        }
        // The cap: one row of cards, measured off the cards that are really there, never past 45vh.
        check(tag + ' ' + row.employee_no + ': the panel caps itself at one row of cards',
            pic.scroll && pic.firstCardRowH > 0
            && Math.abs(parseFloat(pic.scroll.maxH) - pic.firstCardRowH) <= 1,
            JSON.stringify({ maxH: pic.scroll && pic.scroll.maxH, rowH: pic.firstCardRowH }));
        check(tag + ' ' + row.employee_no + ': ...and never past 45vh',
            pic.scroll && parseFloat(pic.scroll.maxH) <= shape.vh45 + 0.5,
            JSON.stringify({ maxH: pic.scroll && pic.scroll.maxH, vh45: shape.vh45 }));
        // What the cap is FOR: the slip's own first line is still on screen with the panel open.
        check(tag + ' ' + row.employee_no + ': the table first row is still above the fold, unscrolled',
            shape.firstRowBottom !== null && shape.firstRowBottom <= shape.bodyBottom + 0.5 && shape.bodyScrollTop === 0,
            JSON.stringify({ firstRowBottom: shape.firstRowBottom, bodyBottom: shape.bodyBottom, scrollTop: shape.bodyScrollTop }));
        // A keyboard can reach the scrolling, not just the content inside it.
        check(tag + ' ' + row.employee_no + ': the scroll box names itself and is reachable',
            pic.scroll && pic.scroll.tabindex === '0' && pic.scroll.role === 'group'
            && !!pic.scroll.ariaLabel && pic.scroll.ariaLabel.length > 0, JSON.stringify(pic.scroll));
        if ((o.width || 1400) <= 430) {
            check(tag + ' ' + row.employee_no + ': at 430 the capped box really scrolls',
                pic.scroll && pic.scroll.scrollH > pic.scroll.clientH,
                JSON.stringify({ scrollH: pic.scroll && pic.scroll.scrollH, clientH: pic.scroll && pic.scroll.clientH }));
            const reached = await ctx.page.evaluate(() => {
                const b = document.querySelector('#rawSyncPanel .rd-sync-scroll');
                b.focus();
                return document.activeElement === b;
            });
            check(tag + ' ' + row.employee_no + ': ...and the keyboard can land on it', reached === true, String(reached));
        }

        // close -> open again: the panel comes back without a second request
        await ctx.page.click('#btnRawSyncPanel');
        await ctx.page.waitForTimeout(400);
        let closed = await slipPicture(ctx.page);
        check(tag + ' ' + row.employee_no + ': pressing it again collapses it',
            closed.expanded === 'false' && closed.panelVisible === false, closed.expanded + '/' + closed.panelVisible);
        await ctx.page.click('#btnRawSyncPanel');
        await ctx.page.waitForTimeout(800);
        const reopened = await slipPicture(ctx.page);
        check(tag + ' ' + row.employee_no + ': re-opening shows it again', reopened.panelVisible === true);
        check(tag + ' ' + row.employee_no + ': open-close-open still = 1 request in total',
            calls.n - before === 1, calls.n - before);

        if (o.theme === 'dark') {
            const fit = await ctx.page.evaluate(() => {
                const p = document.querySelector('#rawSyncPanel');
                const b = p.getBoundingClientRect();
                const tbl = p.querySelector('.table-responsive');
                const cs = getComputedStyle(p.querySelector('.ped-type-panel') || p);
                return {
                    insideViewport: b.left >= -0.5 && b.right <= window.innerWidth + 0.5,
                    tableScroller: tbl ? { scrollWidth: tbl.scrollWidth, clientWidth: tbl.clientWidth, overflowX: getComputedStyle(tbl).overflowX } : null,
                    text: cs.color,
                    bg: getComputedStyle(p).backgroundColor,
                };
            });
            measured(tag + ' ' + row.employee_no + ' dark fit', fit);
            check(tag + ' ' + row.employee_no + ': the panel stays inside the viewport', fit.insideViewport === true);
            check(tag + ' ' + row.employee_no + ': the modal body itself does not scroll sideways',
                pic.modalBodyOverflow && pic.modalBodyOverflow.scrollWidth <= pic.modalBodyOverflow.clientWidth + 0.5,
                JSON.stringify(pic.modalBodyOverflow));
            if (fit.tableScroller) {
                check(tag + ' ' + row.employee_no + ': the line-items table scrolls inside its OWN container',
                    fit.tableScroller.overflowX === 'auto' || fit.tableScroller.overflowX === 'scroll', fit.tableScroller.overflowX);
            }
            const ratio = contrastRatio(fit.text, fit.bg);
            measured(tag + ' ' + row.employee_no + ' contrast', ratio);
            check(tag + ' ' + row.employee_no + ': panel text on panel background >= 4.5:1', ratio !== null && ratio >= 4.5, ratio);
        }
        await closeSlip(ctx.page);
    }
    reportOk(tag, ctx);
    if ((o.width || 1400) >= 1400) await s1StretchCases(tag);
}

/* 2026-09-22, slip2-b follow-up, reported with a picture: a slip whose Attendance section is hidden
   showed 2 cards of ~215px with the right half of the panel blank.
   Cause, measured: the "Additional Line Items" card was a MEMBER of the grid spanning
   `grid-column: 1 / -1`, and `auto-fit` collapses only tracks that are EMPTY -- that item filled
   every one of them, so `grid-template-columns` computed to `270px 270px 270px 270px` whatever the
   card count was. The card is a sibling of the grid now.
   Each case gets its own context because a route mock must not leak into the assertions above, and
   the payload is doctored rather than hunted for: the cards a run happens to have is a property of
   that run, and what is being measured is the LAYOUT for a given count. */
async function s1StretchCases(tag) {
    // The field lists the renderer itself groups by -- a section whose every field is empty (0 counts
    // as empty, see rawSyncDataValueIsEmpty) is not rendered at all.
    const ATTENDANCE = ['working_days', 'working_mins', 'absent_days', 'absent_mins', 'late_mins', 'early_mins'];
    const PAY = ['pay_type', 'pay_bank_code', 'pay_bank_name', 'trip_allowance', 'pass_pro', 'pass_pro_date'];
    for (const kase of [{ name: '2 cards', drop: ATTENDANCE, want: 2 }, { name: '1 card', drop: ATTENDANCE.concat(PAY), want: 1 }]) {
        const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
        await ctx.page.route('**/api/payroll-run.raw-sync-data-for-employee*', async (route) => {
            const res = await route.fetch();
            const json = await res.json();
            if (json && json.data) for (const k of kase.drop) json.data[k] = 0;
            await route.fulfill({ response: res, body: JSON.stringify(json), headers: { 'content-type': 'application/json' } });
        });
        await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
        const rows = await rowsFromTable(ctx.page);
        await openSlip(ctx.page, rows[0].employee_id);
        await ctx.page.locator('#btnRawSyncPanel').click();
        await ctx.page.waitForTimeout(2000);
        const g = await ctx.page.evaluate(() => {
            const r1 = (n) => Math.round(n * 10) / 10;
            const p = document.querySelector('#rawSyncPanel');
            const grid = p.querySelector('.rd-sync-sections');
            const cards = Array.from(p.querySelectorAll('.rd-sync-sections > .rd-sync-section'));
            const box = p.querySelector('.rd-sync-scroll');
            const all = Array.from(p.querySelectorAll('.rd-sync-section'));
            const top = all.length ? all[0].getBoundingClientRect().top : 0;
            let bottom = 0;
            for (const c of all) { const b = c.getBoundingClientRect(); if (b.top > top + 1) break; bottom = Math.max(bottom, b.bottom); }
            return {
                panelW: r1(p.getBoundingClientRect().width),
                gap: parseFloat(getComputedStyle(grid).columnGap) || 0,
                tracks: getComputedStyle(grid).gridTemplateColumns,
                cards: cards.length,
                widths: cards.map((c) => r1(c.getBoundingClientRect().width)),
                sum: r1(cards.reduce((a, c) => a + c.getBoundingClientRect().width, 0)),
                fieldCols: cards.map((c) => { const f = c.querySelector('.rd-sync-fields');
                    return f ? getComputedStyle(f).gridTemplateColumns.split(' ').length : null; }),
                wideW: (() => { const w = p.querySelector('.rd-sync-section-wide'); return w ? r1(w.getBoundingClientRect().width) : null; })(),
                wideInGrid: !!p.querySelector('.rd-sync-sections > .rd-sync-section-wide'),
                maxH: box ? parseFloat(getComputedStyle(box).maxHeight) : null,
                firstRowH: Math.round(bottom - top),
            };
        });
        measured(tag + ' stretch ' + kase.name, g);
        check(tag + ' stretch ' + kase.name + ': the payload really left ' + kase.want + ' field card(s)',
            g.cards === kase.want, String(g.cards));
        check(tag + ' stretch ' + kase.name + ': the line-items card is NOT a member of the grid',
            g.wideInGrid === false && g.wideW !== null, JSON.stringify({ inGrid: g.wideInGrid, w: g.wideW }));
        // The cards fill the panel: their widths plus the gaps between them ARE the panel.
        const gaps = Math.max(0, g.cards - 1) * g.gap;
        check(tag + ' stretch ' + kase.name + ': the cards fill the panel (+-1)',
            Math.abs(g.sum + gaps - g.panelW) <= 1, JSON.stringify({ sum: g.sum, gaps, panelW: g.panelW }));
        check(tag + ' stretch ' + kase.name + ': ...and each takes an equal share',
            new Set(g.widths.map((w) => Math.round(w))).size === 1, JSON.stringify(g.widths));
        if (kase.want === 1) {
            check(tag + ' stretch 1 card: one card takes the whole panel',
                Math.abs(g.widths[0] - g.panelW) <= 1, JSON.stringify({ card: g.widths[0], panelW: g.panelW }));
        } else {
            check(tag + ' stretch 2 cards: a half-panel card gets at least 3 field columns',
                g.fieldCols.every((n) => n >= 3), JSON.stringify(g.fieldCols));
        }
        // The cap still follows the cards: they are wider now, so the first row is a different height.
        check(tag + ' stretch ' + kase.name + ': the scroll cap is still the real first-row height (+-1)',
            g.maxH !== null && g.firstRowH > 0 && Math.abs(g.maxH - g.firstRowH) <= 1,
            JSON.stringify({ maxH: g.maxH, firstRowH: g.firstRowH }));
        reportOk(tag + ' stretch ' + kase.name, ctx);
        await ctx.context.close();
    }
}

/* ================= s2: no state carried from one employee to the next ================= */
async function s2() {
    console.log('\n[s2] the panel does not carry over from one slip to the next');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'th');
    const rows = (await rowsFromTable(ctx.page)).filter((r) => r.data_source === 'sync');
    if (rows.length < 2) {
        check('s2: this run has 2 sync rows to compare', false, rows.length);
        reportOk('s2', ctx);
        return;
    }
    const [a, b] = rows;
    const runId = await ctx.page.evaluate(() => currentRun.id);
    await openSlip(ctx.page, a.employee_id);
    await ctx.page.click('#btnRawSyncPanel');
    await ctx.page.waitForTimeout(1200);
    const openA = await slipPicture(ctx.page);
    check('s2: A (' + a.employee_no + ') has its panel open', openA.panelVisible === true && openA.expanded === 'true');
    await closeSlip(ctx.page);

    await openSlip(ctx.page, b.employee_id);
    const freshB = await slipPicture(ctx.page);
    measured('s2 B (' + b.employee_no + ') on open', { expanded: freshB.expanded, panelVisible: freshB.panelVisible, panelEmpty: freshB.panelEmpty });
    check('s2: B opens with the panel collapsed', freshB.panelVisible === false, freshB.panelVisible);
    check('s2: B opens with the panel EMPTY -- not holding A\'s payload', freshB.panelEmpty === true, freshB.panelEmpty);
    check('s2: B\'s link reads aria-expanded=false', freshB.expanded === 'false', freshB.expanded);

    const payloadB = await rawSyncPayload(ctx.page, runId, b.employee_id);
    await ctx.page.click('#btnRawSyncPanel');
    await ctx.page.waitForTimeout(1200);
    const shown = await ctx.page.evaluate(() => (document.querySelector('#rawSyncPanel').textContent || '').replace(/\s+/g, ' '));
    const codeB = payloadB && payloadB.status ? payloadB.data.payroll_code : null;
    const codeA = await (async () => { const p = await rawSyncPayload(ctx.page, runId, a.employee_id); return p && p.status ? p.data.payroll_code : null; })();
    measured('s2 payroll_code A/B from the response', { A: codeA, B: codeB });
    check('s2: the panel shows B\'s payroll_code', !!codeB && shown.indexOf(codeB) !== -1, codeB);
    if (codeA && codeA !== codeB) {
        check('s2: and does NOT show A\'s', shown.indexOf(codeA) === -1, codeA);
    }
    reportOk('s2', ctx);
}

/* ================= s3: edit mode, MOCKed sync fields ================= */
/* The fixture run is a cycle run with sync_process_id NULL and no row of data_source='sync', so the
   edit-mode case cannot be reached on real data here. MOCK, stated as one: api/payroll-run.get gains
   a sync_process_id, api/payroll-run.details marks exactly ONE row (R5) as sync, and the raw-sync
   endpoint is answered with a payload whose shape is copied from
   PayrollRunModel::rawSyncDataForEmployee() (app/models/PayrollRunModel.php:7374-7418 -- the
   RAW_SYNC_DATA_FIELDS columns + item_values + exemption + working_days_breakdown). All 3 are READ
   routes; nothing is written either way. */
const MOCK_RAW_SYNC = {
    payroll_code: 'MOCK-PC-001',
    emp_code: 'MOCK-EMP-001',
    mapping_status: 'mapped',
    dept_description: 'Mock Department',
    position_name: 'Mock Position',
    branch_name: 'Mock Branch',
    shift_working_name: 'Mock Shift',
    pay_type: 'monthly',
    pay_bank_code: '014',
    pay_bank_name: 'Mock Bank',
    working_days: 26, working_mins: 12480, absent_days: 0, absent_mins: 0, late_mins: 15, early_mins: 0,
    ot_mins: 120, ot_req_hrs: 2, ot_req_working_day_hrs: 2, ot_req_weekend_hrs: 0, ot_req_holiday_hrs: 0,
    leave_approve_days: 1, leave_wait_days: 0, leave_without_pay_days: 0,
    trip_allowance: 500, pass_pro: 'Y', pass_pro_date: '2026-01-31',
    item_values: [{ item_code: 'MOCK_ITEM', item_name: 'Mock Item', item_type: 'earning', unit_type: 'amount', value: 100, remark: 'mock' }],
    exemption: null,
    working_days_breakdown: { total_days: 30, working_days: 26, holiday_days: 1, weekly_off_days: 3, has_shift_pattern: true },
};
async function mockSyncRoutes(page, syncEmployeeId, opts) {
    const o = opts || {};
    // ONE route: the page loads the header AND every row of the table from the same
    // api/payroll-run.get reply (loadRunDetail() -> initRunDetailTable(res.data.details)), so both
    // halves of the mock are patched onto that single response.
    await page.route('**/api/payroll-run.get*', async (route) => {
        const res = await route.fetch();
        let body;
        try { body = JSON.parse(await res.text()); } catch (e) { return route.fulfill({ response: res }); }
        if (body && body.data) {
            body.data.sync_process_id = 999999;
            for (const r of (body.data.details || [])) {
                if (Number(r.employee_id) === Number(syncEmployeeId)) r.data_source = 'sync';
            }
        }
        return route.fulfill({ response: res, body: JSON.stringify(body) });
    });
    await page.route('**/api/payroll-run.raw-sync-data-for-employee*', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(o.refuse
            ? { status: false, message: 'mock refusal' }
            : { status: true, data: MOCK_RAW_SYNC }),
    }));
}
async function s3() {
    console.log('\n[s3] MOCK -- edit mode, one row marked data_source=sync');
    const r5 = FIXTURE.R5;
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await mockSyncRoutes(ctx.page, r5.employee_id);
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);
    const mocked = rows.find((r) => Number(r.employee_id) === Number(r5.employee_id));
    check('s3: the MOCK really marked R5 (' + r5.employee_no + ') as sync', !!mocked && mocked.data_source === 'sync',
        mocked ? mocked.data_source : 'row missing');

    const controlsBefore = await (async () => {
        await openSlip(ctx.page, r5.employee_id);
        return ctx.page.evaluate(() => ({
            pencils: document.querySelectorAll('#runDetailBreakdownModal .lo-row-edit, #runDetailBreakdownModal .btn-icon').length,
            switches: document.querySelectorAll('#runDetailBreakdownModal .form-check-input').length,
        }));
    })();
    let pic = await slipPicture(ctx.page);
    measured('s3 R5 slip', { btns: pic.btns, controlsBefore });
    check('s3: R5 has exactly 1 link', pic.btns === 1, pic.btns);
    await ctx.page.click('#btnRawSyncPanel');
    await ctx.page.waitForTimeout(900);
    pic = await slipPicture(ctx.page);
    measured('s3 R5 panel', { visible: pic.panelVisible, sections: pic.sectionCards, expanded: pic.expanded });
    check('s3: the panel rendered', pic.panelVisible === true && pic.sectionCards > 0, pic.sectionCards);
    const controlsAfter = await ctx.page.evaluate(() => ({
        pencils: document.querySelectorAll('#runDetailBreakdownModal .lo-row-edit, #runDetailBreakdownModal .btn-icon').length,
        switches: document.querySelectorAll('#runDetailBreakdownModal .form-check-input').length,
    }));
    measured('s3 slip controls before/after', { controlsBefore, controlsAfter });
    check('s3: the slip\'s own pencils/switches are untouched by the panel',
        controlsAfter.pencils >= controlsBefore.pencils && controlsAfter.switches >= controlsBefore.switches,
        JSON.stringify(controlsAfter) + ' vs ' + JSON.stringify(controlsBefore));
    await closeSlip(ctx.page);

    // R1 and employee 28 must NOT gain a link -- only the one mocked row does.
    for (const label of ['R1']) {
        const f = FIXTURE[label];
        await openSlip(ctx.page, f.employee_id);
        const p = await slipPicture(ctx.page);
        check('s3: ' + label + ' (' + f.employee_no + ') has no link', p.btns === 0, p.btns);
        await closeSlip(ctx.page);
    }
    const emp28 = rows.find((r) => Number(r.employee_id) === Number(STATE.employee_id));
    if (emp28) {
        await openSlip(ctx.page, emp28.employee_id);
        const p = await slipPicture(ctx.page);
        check('s3: employee 28 (' + emp28.employee_no + ') has no link', p.btns === 0, p.btns);
        await closeSlip(ctx.page);
    } else {
        console.log('  NOTE  s3: employee 28 is not a row of this run -- not measurable here.');
    }
    reportOk('s3', ctx);

    console.log('\n[s3b] MOCK -- the endpoint refuses');
    const ctx2 = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await mockSyncRoutes(ctx2.page, r5.employee_id, { refuse: true });
    await gotoRun(ctx2, runToken, 'th');
    await openSlip(ctx2.page, r5.employee_id);
    await ctx2.page.click('#btnRawSyncPanel');
    await ctx2.page.waitForTimeout(900);
    const refusal = await ctx2.page.evaluate(() => ({
        text: (document.querySelector('#rawSyncPanel').textContent || '').trim(),
        expected: (typeof langData !== 'undefined' && langData['raw_sync_panel_unavailable']) || null,
        toasts: document.querySelectorAll('.swal2-container, .swal2-popup').length,
        visible: !!document.querySelector('#rawSyncPanel') && !document.querySelector('#rawSyncPanel').classList.contains('d-none'),
    }));
    measured('s3b refusal', refusal);
    check('s3b: the refusal is printed INSIDE the panel, from the new key',
        refusal.text === refusal.expected, '"' + refusal.text + '" vs "' + refusal.expected + '"');
    check('s3b: no toast/dialog was raised over the slip', refusal.toasts === 0, refusal.toasts);
    check('s3b: the panel stayed open to hold it', refusal.visible === true);
    reportOk('s3b', ctx2);
}

/* ================= s4: the fixture run for real -- sync_process_id is NULL ================= */
async function s4() {
    console.log('\n[s4] the fixture run (sync_process_id NULL) -- no link anywhere, and the spacing above the callout');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const syncProcessId = await ctx.page.evaluate(() => currentRun.sync_process_id);
    measured('s4 currentRun.sync_process_id', syncProcessId);
    check('s4: this run really has no sync process', !syncProcessId, syncProcessId);
    const rows = await rowsFromTable(ctx.page);

    // R2 -- a row with blocking codes, so the callout strip below the card is not empty.
    const r2 = rows.find((r) => r.employee_no === FIXTURE.R2.employee_no);
    check('s4: R2 (' + FIXTURE.R2.employee_no + ') is in the table', !!r2);
    if (r2) {
        await openSlip(ctx.page, r2.employee_id);
        const p = await slipPicture(ctx.page);
        measured('s4 R2 slip', p);
        check('s4 R2: no link is rendered at all', p.btns === 0, p.btns);
        check('s4 R2: the hidden panel takes no height', p.panelVisible === false, p.panelVisible);
        check('s4 R2: the header card sits 16px above the callout strip',
            Math.abs(p.gapCardToNotes - 16) <= 0.5, p.gapCardToNotes);
        // "unchanged below the callout" -- measured against the same distance with the panel's own
        // 3 declarations stripped off the live element, not against a number typed here.
        const beforeAfter = await ctx.page.evaluate(() => {
            const notes = document.querySelector('#breakdownCalcNotes');
            const panel = document.querySelector('#rawSyncPanel');
            let next = notes.nextElementSibling;
            const vis = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
            while (next && !vis(next)) next = next.nextElementSibling;
            const dist = () => Math.round((next.getBoundingClientRect().top - notes.getBoundingClientRect().bottom) * 10) / 10;
            const withPanel = dist();
            panel.style.display = 'block';
            panel.style.margin = '0';
            panel.style.padding = '0';
            panel.style.border = '0';
            const asIfNoPanel = dist();
            panel.removeAttribute('style');
            return { nextId: next ? (next.id || next.className) : null, withPanel, asIfNoPanel, marginBottom: getComputedStyle(notes).marginBottom };
        });
        measured('s4 R2 callout -> next block', beforeAfter);
        check('s4 R2: the distance under the callout is what it always was (its own --sp-3)',
            beforeAfter.marginBottom === '12px' && Math.abs(beforeAfter.withPanel - beforeAfter.asIfNoPanel) <= 0.5,
            JSON.stringify(beforeAfter));
        await closeSlip(ctx.page);
    }
    const emp28 = rows.find((r) => Number(r.employee_id) === Number(STATE.employee_id));
    if (emp28) {
        await openSlip(ctx.page, emp28.employee_id);
        const p = await slipPicture(ctx.page);
        check('s4: employee 28 (' + emp28.employee_no + ') has no link either', p.btns === 0, p.btns);
        await closeSlip(ctx.page);
    }
    reportOk('s4', ctx);
}

/* ================= s6: 768 en ================= */
async function s6() {
    console.log('\n[s6] 768 en -- every new string comes from en.json');
    const ctx = await openContext({ sessionId, width: 768, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, LOCKED_RUN_TOKEN, 'en');
    const rows = (await rowsFromTable(ctx.page)).filter((r) => r.data_source === 'sync');
    if (!rows.length) { check('s6: this run has a sync row', false, 0); reportOk('s6', ctx); return; }
    await openSlip(ctx.page, rows[0].employee_id);
    const lang = await ctx.page.evaluate(() => ({
        lang: currentLang,
        link: langData['raw_sync_panel_link'],
        unavailable: langData['raw_sync_panel_unavailable'],
        sections: (typeof RAW_SYNC_DATA_SECTIONS_RD !== 'undefined') ? RAW_SYNC_DATA_SECTIONS_RD.map((s) => langData[s.titleKey]) : [],
    }));
    measured('s6 langData', lang);
    check('s6: the page is in en', lang.lang === 'en', lang.lang);
    const pic = await slipPicture(ctx.page);
    check('s6: the link text is en.json\'s own raw_sync_panel_link', pic.btnText === lang.link,
        '"' + pic.btnText + '" vs "' + lang.link + '"');
    await ctx.page.click('#btnRawSyncPanel');
    await ctx.page.waitForTimeout(1200);
    const open = await slipPicture(ctx.page);
    measured('s6 section titles rendered', open.sectionTitles);
    const itemsTitle = await ctx.page.evaluate(() => langData['raw_sync_data_item_values_title']);
    check('s6: every rendered section heading is one of en.json\'s own',
        open.sectionTitles.length > 0
        && open.sectionTitles.every((t) => lang.sections.indexOf(t) !== -1 || t === itemsTitle),
        JSON.stringify({ shown: open.sectionTitles, itemsTitle }));
    const hideLabel = await ctx.page.evaluate(() => langData['raw_sync_panel_hide']);
    check('s6: opened, the link reads en.json\'s own hide label', open.btnText === hideLabel,
        '"' + open.btnText + '" vs "' + hideLabel + '"');
    measured('s6 sections per row at 768', { tops: open.sectionTops, rows: new Set(open.sectionTops).size,
        panelW: open.panelW, panelH: open.panelH });
    await closeSlip(ctx.page);
    reportOk('s6', ctx);

    // The inline refusal, in en, through the same mock s3b uses.
    const ctx2 = await openContext({ sessionId, width: 768, height: 950, blockPaths: WRITE_PATHS });
    await mockSyncRoutes(ctx2.page, FIXTURE.R5.employee_id, { refuse: true });
    await gotoRun(ctx2, runToken, 'en');
    await openSlip(ctx2.page, FIXTURE.R5.employee_id);
    await ctx2.page.click('#btnRawSyncPanel');
    await ctx2.page.waitForTimeout(900);
    const refusal = await ctx2.page.evaluate(() => ({
        text: (document.querySelector('#rawSyncPanel').textContent || '').trim(),
        expected: langData['raw_sync_panel_unavailable'],
    }));
    measured('s6 refusal (en)', refusal);
    check('s6: the inline refusal is en.json\'s own string', refusal.text === refusal.expected,
        '"' + refusal.text + '" vs "' + refusal.expected + '"');
    reportOk('s6b', ctx2);
}

/* ================= s7/s8: the Payroll List ================= */
async function listCell(opts) {
    const o = opts || {};
    const tag = o.tag;
    console.log('\n[' + tag + '] the Payroll List at ' + o.width + (o.theme === 'dark' ? ' th dark' : ' th light'));
    const ctx = await openContext({ sessionId, width: o.width, height: o.height || 950, blockPaths: WRITE_PATHS });
    await ctx.page.goto(ctx.url('/payroll-process'), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(1500);
    await ctx.page.evaluate(() => { if (typeof changeLanguage === 'function') changeLanguage('th'); });
    await ctx.page.waitForTimeout(600);
    if (o.theme === 'dark') {
        const t = await applyAppTheme(ctx.page, 'dark');
        measured(tag + ' theme', t);
        check(tag + ': the page really is in the dark theme before anything is measured', t.ok === true, t.stamp);
        if (!t.ok) { reportOk(tag, ctx); return; }
    }
    // The fixture run's own row, found by its id in the live table rather than by position.
    const runId = STATE.run_id;
    const pill = await ctx.page.evaluate((id) => {
        const btn = document.querySelector('.btn-view-run-errors[data-id="' + id + '"]');
        if (!btn) return { found: false };
        const badge = btn.querySelector('[data-badge]');
        const cell = btn.closest('td');
        const cb = cell ? cell.getBoundingClientRect() : null;
        const bb = btn.getBoundingClientRect();
        return {
            found: true,
            text: (btn.textContent || '').trim(),
            icons: btn.querySelectorAll('i, svg').length,
            badgeMarker: badge ? badge.getAttribute('data-badge') : null,
            badgeClass: badge ? badge.className : null,
            tabIndex: btn.tabIndex,
            overflowsCell: cb ? (bb.right > cb.right + 0.5 || bb.left < cb.left - 0.5) : null,
            cellScroll: cell ? { scrollWidth: cell.scrollWidth, clientWidth: cell.clientWidth } : null,
            // DataTables Responsive (`responsive: true`, payroll/index.js) folds the Employees column
            // into the child row below `sm`, so at 430 the pill is in the DOM but its cell is 0x0.
            // That is the library's own behaviour, not this round's -- it is reported and worked
            // around rather than asserted away.
            cellHidden: cb ? (cb.width === 0 && cb.height === 0) : null,
        };
    }, runId);
    measured(tag + ' pill', pill);
    check(tag + ': the fixture run\'s error pill is on the page', pill.found === true);
    if (!pill.found) { reportOk(tag, ctx); return; }

    // The expected text and count both come from the live reply, not from this file.
    const reply = await ctx.page.evaluate(async (id) => {
        const res = await fetch(BASE_URL + '/api/payroll-run.error-employees?id=' + id, { credentials: 'same-origin' });
        return res.json();
    }, runId);
    const langBits = await ctx.page.evaluate(() => ({
        label: langData['run_error_employee_count'],
        close: langData['close'],
        unknown: langData['calc_error_unknown'],
    }));
    const rowsN = (reply && reply.data) ? reply.data.length : -1;
    // PRECONDITION, not an assertion about the feature: s7/s8 read the fixture's own injected
    // calc_errors back out of the List. Anything that makes the run recalculate DELETEs and re-INSERTs
    // payroll_run_details, and the engine's real output replaces what mksession injected -- which is
    // exactly what tests/ui/h_history_table.js does through its seedWrite(). Run THIS file before that
    // one, or re-create the fixture in between. Said out loud here so a stale fixture cannot read as a
    // bug in the pill.
    const fixtureIntact = (reply.data || []).some((r) => (r.calc_blocking || []).indexOf('zz_fixture_unknown') !== -1);
    check(tag + ': the calc-error fixture is still intact (nothing recalculated this run since mksession)',
        fixtureIntact, 'R2 no longer carries zz_fixture_unknown -- re-run mksession, and run this file BEFORE h_history_table.js');
    const expectedText = String(langBits.label || '').replace('{n}', String(rowsN));
    measured(tag + ' error-employees reply', { rows: rowsN, expectedText,
        baselineFixture: (FIXTURE.baseline_error_count || 0) + 2 });
    check(tag + ': the pill text is the new key with n from the reply', pill.text === expectedText,
        '"' + pill.text + '" vs "' + expectedText + '"');
    check(tag + ': the pill carries no icon', pill.icons === 0, pill.icons);
    check(tag + ': it is the shared count badge (data-badge marker + badge-danger)',
        pill.badgeMarker === 'count' && /(^| )badge-danger( |$)/.test(pill.badgeClass || ''),
        pill.badgeMarker + ' / ' + pill.badgeClass);
    check(tag + ': it is reachable by Tab', pill.tabIndex >= 0, pill.tabIndex);
    check(tag + ': R1 and R2 are counted -- reply length = baseline + 2',
        rowsN === (FIXTURE.baseline_error_count || 0) + 2, rowsN + ' vs ' + ((FIXTURE.baseline_error_count || 0) + 2));
    if (o.theme === 'dark') {
        measured(tag + ' pill cell collapsed by DataTables Responsive', pill.cellHidden);
        check(tag + ': the pill does not overflow its own column', pill.overflowsCell === false, pill.overflowsCell);
        check(tag + ': its cell does not scroll sideways because of it',
            pill.cellScroll && pill.cellScroll.scrollWidth <= pill.cellScroll.clientWidth + 0.5, JSON.stringify(pill.cellScroll));
    }

    if (pill.cellHidden) {
        console.log('  NOTE  ' + tag + ': the Employees column is collapsed into the responsive child row at this width --');
        console.log('  NOTE  ' + tag + ': the pill cannot be hit-tested here, so the modal is opened through the same handler.');
        await ctx.page.evaluate((id) => { $('.btn-view-run-errors[data-id="' + id + '"]').trigger('click'); }, runId);
    } else {
        await ctx.page.click('.btn-view-run-errors[data-id="' + runId + '"]');
    }
    await ctx.page.waitForTimeout(1400);
    const modal = await ctx.page.evaluate(() => {
        const m = document.querySelector('#runErrorEmployeesModal');
        const head = m.querySelector('.modal-header');
        const title = m.querySelector('.modal-title');
        const foot = m.querySelector('.modal-footer button');
        const mb = m.getBoundingClientRect();
        const dlg = m.querySelector('.modal-dialog').getBoundingClientRect();
        return {
            open: m.classList.contains('show'),
            headerIcons: head.querySelectorAll('i, svg').length,
            titleClass: title.className,
            footClass: foot ? foot.className : null,
            footText: foot ? (foot.textContent || '').trim() : null,
            people: m.querySelectorAll('.run-error-employee').length,
            cards: m.querySelectorAll('#runErrorEmployeesModalBody .border.rounded-3').length,
            items: Array.from(m.querySelectorAll('#runErrorEmployeesModalBody li')).map((li) => ({
                code: li.getAttribute('data-code'), text: (li.textContent || '').trim(),
            })),
            perPerson: Array.from(m.querySelectorAll('.run-error-employee')).map((d) => ({
                head: (d.querySelector('.fw-semibold').textContent || '').trim(),
                n: d.querySelectorAll('li').length,
            })),
            insideViewport: dlg.left >= -0.5 && dlg.right <= window.innerWidth + 0.5 && mb.width <= window.innerWidth + 0.5,
        };
    });
    measured(tag + ' modal', modal);
    check(tag + ': the modal opened', modal.open === true);
    check(tag + ': its header carries no icon', modal.headerIcons === 0, modal.headerIcons);
    check(tag + ': its title is not text-danger', !/text-danger/.test(modal.titleClass), modal.titleClass);
    check(tag + ': its close button is btn-outline-secondary',
        /btn-outline-secondary/.test(modal.footClass || ''), modal.footClass);
    check(tag + ': that button reads the shared close key', modal.footText === langBits.close,
        '"' + modal.footText + '" vs "' + langBits.close + '"');
    check(tag + ': one block per person = the reply length', modal.people === rowsN, modal.people + ' vs ' + rowsN);
    check(tag + ': no bordered card per person any more', modal.cards === 0, modal.cards);
    if (o.theme === 'dark') {
        check(tag + ': the modal stays inside the viewport', modal.insideViewport === true);
    }

    // <li> count per person, and NO raw code as text -- both restated from the reply.
    const byNo = {};
    for (const r of (reply.data || [])) byNo[r.employee_no] = r;
    let liTotal = 0;
    let mismatched = [];
    for (const p of modal.perPerson) {
        const no = p.head.split(' - ')[0].trim();
        const r = byNo[no];
        if (!r) { mismatched.push(no + ' (not in reply)'); continue; }
        liTotal += r.calc_blocking.length;
        if (p.n !== r.calc_blocking.length) mismatched.push(no + ': ' + p.n + ' vs ' + r.calc_blocking.length);
    }
    check(tag + ': every person\'s <li> count = their own calc_blocking length', mismatched.length === 0, mismatched.join(' | '));
    check(tag + ': total <li> = total blocking codes in the reply', modal.items.length === liTotal,
        modal.items.length + ' vs ' + liTotal);
    const allCodes = [].concat.apply([], (reply.data || []).map((r) => r.calc_blocking));
    check(tag + ': every <li> carries a data-code', modal.items.every((i) => !!i.code), JSON.stringify(modal.items.filter((i) => !i.code)));
    check(tag + ': every data-code is one the reply really sent',
        modal.items.every((i) => allCodes.indexOf(i.code) !== -1), JSON.stringify(modal.items.map((i) => i.code)));
    const codeAsText = modal.items.filter((i) => i.text === i.code);
    check(tag + ': no <li> prints its raw code as the text', codeAsText.length === 0, JSON.stringify(codeAsText));
    const zz = modal.items.find((i) => i.code === 'zz_fixture_unknown');
    measured(tag + ' zz_fixture_unknown', zz);
    check(tag + ': R2\'s unknown code falls back to the one shared sentence',
        !!zz && zz.text === langBits.unknown, zz ? '"' + zz.text + '" vs "' + langBits.unknown + '"' : 'missing');
    reportOk(tag, ctx);
}

/* ================= s9: the merge banners ================= */
/* renderMergeTargetBanner() (payroll/detail.js) reads 6 fields off api/payroll-run.get's reply:
   merge_target_run_id + state + cycle_id + sync_process_id decide the "ready" box, and
   merge_target_cycle_id (+ merge_target_cycle_status) the "waiting" one. The fixture run is a draft
   with cycle_id set and no merge target at all, so all 3 shapes are MOCKed onto that same READ
   route -- the round's own spec calls for the mock and it is labelled as one. */
async function mockMergeFields(page, patch) {
    await page.route('**/api/payroll-run.get*', async (route) => {
        const res = await route.fetch();
        let body;
        try { body = JSON.parse(await res.text()); } catch (e) { return route.fulfill({ response: res }); }
        if (body && body.data) Object.assign(body.data, patch);
        return route.fulfill({ response: res, body: JSON.stringify(body) });
    });
}
const MERGE_BASE = { state: 'draft', cycle_id: null, sync_process_id: null };
async function bannerGeometry(page) {
    return page.evaluate(() => {
        // ONLY the boxes inside the run-banner stack. #nextStepBanner is a callout too, but it
        // lives above the stepper/stat cards, not in this wrapper -- including it measured a
        // ~162px gap that is the page's section spacing, not the stack's own 12.
        const ids = ['#validationErrorsBanner', '#syncMissingEmployeesBanner', '#mergeTargetBanner', '#mergeTargetWaitingBanner']
            .filter((id) => document.querySelector('.rd-run-banners ' + id));
        const tabs = document.querySelector('#runDetailTabs').getBoundingClientRect();
        const vis = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
        const out = { boxes: {}, visible: [], tabsLeft: Math.round(tabs.left * 10) / 10, tabsRight: Math.round(tabs.right * 10) / 10 };
        for (const id of ids) {
            const el = document.querySelector(id);
            if (!el) { out.boxes[id] = null; continue; }
            const b = el.getBoundingClientRect();
            const v = vis(el);
            // Icons are counted in 2 places on purpose. §15 says a CALLOUT carries no icon in front
            // of its text; an icon on an action button INSIDE one is a §4 question that
            // payroll/detail.php's own comment and BACKLOG.md already own (#btnMergeIntoTarget's
            // fa-code-merge). Reporting both keeps that distinction visible instead of either
            // failing on something this round did not touch or asserting nothing at all.
            const inButtons = el.querySelectorAll('button i, button svg, a i, a svg').length;
            out.boxes[id] = { visible: v, cls: el.className,
                icons: el.querySelectorAll('i, svg').length - inButtons, iconsInButtons: inButtons,
                left: Math.round(b.left * 10) / 10, right: Math.round(b.right * 10) / 10 };
            if (v) out.visible.push({ id, top: b.top, bottom: b.bottom, left: b.left, right: b.right });
        }
        out.gaps = [];
        for (let i = 1; i < out.visible.length; i++) out.gaps.push(Math.round((out.visible[i].top - out.visible[i - 1].bottom) * 10) / 10);
        out.gapToTabs = out.visible.length ? Math.round((tabs.top - out.visible[out.visible.length - 1].bottom) * 10) / 10 : null;
        return out;
    });
}
async function s9() {
    console.log('\n[s9] MOCK -- the merge banners');
    const cases = [
        { tag: 's9a', id: '#mergeTargetBanner', patch: Object.assign({}, MERGE_BASE, { merge_target_run_id: 99999, merge_target_run_name: 'MOCK target', merge_target_cycle_id: null }), expect: 'callout-neutral', forbid: 'callout-primary' },
        { tag: 's9b', id: '#mergeTargetWaitingBanner', patch: Object.assign({}, MERGE_BASE, { merge_target_run_id: null, merge_target_cycle_id: 88888, merge_target_cycle_name: 'MOCK cycle', merge_target_cycle_status: 'active', merge_target_period_start_date: '2026-09-01', merge_target_period_end_date: '2026-09-30' }), expect: 'callout-warning', forbid: 'callout-danger' },
        { tag: 's9c', id: '#mergeTargetWaitingBanner', patch: Object.assign({}, MERGE_BASE, { merge_target_run_id: null, merge_target_cycle_id: 88888, merge_target_cycle_name: 'MOCK cycle', merge_target_cycle_status: 'inactive', merge_target_period_start_date: '2026-09-01', merge_target_period_end_date: '2026-09-30' }), expect: 'callout-danger', forbid: 'callout-warning' },
    ];
    for (const c of cases) {
        const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
        await mockMergeFields(ctx.page, c.patch);
        await ctx.page.goto(ctx.url('/payroll-process/' + runToken), { waitUntil: 'networkidle' });
        await ctx.page.waitForTimeout(1400);
        const g = await bannerGeometry(ctx.page);
        measured(c.tag + ' banners', g);
        const box = g.boxes[c.id];
        check(c.tag + ': ' + c.id + ' is showing under this MOCK', !!(box && box.visible), JSON.stringify(box));
        if (box && box.visible) {
            check(c.tag + ': it carries ' + c.expect, new RegExp('(^| )' + c.expect + '( |$)').test(box.cls), box.cls);
            check(c.tag + ': and NOT ' + c.forbid, !new RegExp('(^| )' + c.forbid + '( |$)').test(box.cls), box.cls);
            check(c.tag + ': its own text carries no icon (§15)', box.icons === 0, box.icons);
            measured(c.tag + ': icons on action buttons inside it (BACKLOG, §4 -- not this round)', box.iconsInButtons);
            check(c.tag + ': it spans exactly the tab bar', Math.abs(box.left - g.tabsLeft) <= 0.5 && Math.abs(box.right - g.tabsRight) <= 0.5,
                box.left + '/' + box.right + ' vs ' + g.tabsLeft + '/' + g.tabsRight);
        }
        if (g.visible.length >= 2) {
            check(c.tag + ': every gap between 2 visible boxes is 12px', g.gaps.every((v) => Math.abs(v - 12) <= 0.5), JSON.stringify(g.gaps));
        }
        if (g.visible.length >= 1) {
            check(c.tag + ': the last box sits 16px above the tab bar', Math.abs(g.gapToTabs - 16) <= 0.5, g.gapToTabs);
        }
        reportOk(c.tag, ctx);
    }
}

/* s10 -- the line form as a window ON TOP of the slip, not a replacement of it (2026-09-22,
   slip2-b). Before this round both dialogs were `modal-lg` and came out at exactly the same width
   (measured: 800 vs 800), so opening one simply swapped the contents of the same rectangle. The slip
   is `modal-xl` now and the form stays `modal-lg`, which is §9's own split: xl for a dialog that
   carries a table, lg for a form.
   The segmented labels are measured too, because THAT is what a narrower form would have cost: the
   short-label swap in `.segmented` is keyed to the VIEWPORT, not to the box, so a form squeezed at a
   1400 viewport would have shown the full wording inside a box too small for it. */
async function s10() {
    console.log('\n[s10] the line form on top of the slip -- a child window, not the same rectangle');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);
    const me = rows.find((r) => String(r.employee_id) === String(STATE.employee_id)) || rows[0];
    await openSlip(ctx.page, me.employee_id);
    /* The table is drawn twice on an open -- once from what the slip already holds, once when
       loadSyncLineOverridesRd() comes back -- so the pencils are not there on the first frame.
       Waiting for the CONTROL rather than for the row is what makes this stable (measured: it read
       `null` on one run out of several and reported "no row with a pencil" on a run that has 4). */
    await ctx.page.waitForSelector('#breakdownLineOverrideWrap tr.lo-row .lo-edit-btn', { timeout: 20000 }).catch(() => {});
    // The recurring line: its form is the one that really shows the destination picker, which is
    // where the segmented labels live.
    const code = await ctx.page.evaluate(() => {
        const rec = document.querySelector('#breakdownLineOverrideWrap tr.lo-row[data-item-code="TINYL6TMP"] .lo-edit-btn');
        if (rec) return 'TINYL6TMP';
        const any = document.querySelector('#breakdownLineOverrideWrap tr.lo-row .lo-edit-btn');
        return any ? any.closest('tr').getAttribute('data-item-code') : null;
    });
    check('s10: the run has a row with a pencil to open', !!code, String(code));
    if (!code) { reportOk('s10', ctx); await ctx.context.close(); return; }
    await ctx.page.evaluate((c) => document.querySelector(`#breakdownLineOverrideWrap tr.lo-row[data-item-code="${c}"] .lo-edit-btn`).click(), code);
    await ctx.page.waitForSelector('#manualLineFormModal.show', { timeout: 15000 });
    await ctx.page.waitForTimeout(1400);
    const pair = await ctx.page.evaluate(() => {
        const box = (sel) => {
            const m = document.querySelector(sel);
            const c = m.querySelector('.modal-content');
            return { cls: m.querySelector('.modal-dialog').className,
                w: Math.round(c.getBoundingClientRect().width * 10) / 10,
                z: parseInt(getComputedStyle(m).zIndex, 10) };
        };
        return {
            open: document.querySelectorAll('.modal.show').length,
            slip: box('#runDetailBreakdownModal'),
            form: box('#manualLineFormModal'),
            segLabels: Array.from(document.querySelectorAll('#manualLinePayeeDest label')).map((l) => ({
                w: Math.round(l.clientWidth), sw: Math.round(l.scrollWidth),
                clipped: l.scrollWidth > l.clientWidth + 0.5,
                text: (l.textContent || '').trim().slice(0, 30),
            })),
        };
    });
    measured('s10 slip / form', pair);
    check('s10: both are open, and the form is the second one', pair.open === 2, String(pair.open));
    check('s10: the form is the lg dialog, the slip the xl one',
        /modal-lg/.test(pair.form.cls) && /modal-xl/.test(pair.slip.cls), pair.form.cls + ' | ' + pair.slip.cls);
    check('s10: the child is at least 300px narrower than its parent',
        pair.form.w + 300 <= pair.slip.w, pair.form.w + ' + 300 vs ' + pair.slip.w);
    check('s10: ...and sits above it', pair.form.z > pair.slip.z, pair.form.z + ' vs ' + pair.slip.z);
    if (pair.segLabels.length) {
        check('s10: no segmented label is clipped in the form',
            pair.segLabels.every((l) => !l.clipped), JSON.stringify(pair.segLabels));
    } else {
        console.log('  NOTE  s10: this row form has no destination picker -- nothing to measure there.');
    }
    const closed = await ctx.page.evaluate(async () => {
        window.jQuery('#manualLineFormModal').data('dirtyGuardBypass', true);
        window.bootstrap.Modal.getInstance(document.querySelector('#manualLineFormModal')).hide();
        await new Promise((r) => setTimeout(r, 900));
        return { open: document.querySelectorAll('.modal.show').length,
            focusOnPencil: !!document.activeElement && document.activeElement.classList.contains('lo-edit-btn') };
    });
    measured('s10 after close', closed);
    check('s10: closing the form leaves the slip open behind it', closed.open === 1, String(closed.open));
    reportOk('s10', ctx);
    await ctx.context.close();
}

/* s11 -- the history panel's own scroll box takes the same 3 attributes the raw-sync one got. It
   caps itself at 5 entries, so without them a keyboard reader could not get past the 5th. */
async function s11() {
    console.log('\n[s11] the history panel scroll box is reachable from a keyboard');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    await gotoRun(ctx, runToken, 'th');
    const rows = await rowsFromTable(ctx.page);
    const me = rows.find((r) => String(r.employee_id) === String(STATE.employee_id)) || rows[0];
    await openSlip(ctx.page, me.employee_id);
    // Same race as s10: the toggles arrive with loadSyncLineOverridesRd(), not with the first frame.
    await ctx.page.waitForSelector('#breakdownLineOverrideWrap .lo-history-toggle', { timeout: 20000 }).catch(() => {});
    const info = await ctx.page.evaluate(async () => {
        const t = document.querySelector('#breakdownLineOverrideWrap .lo-history-toggle');
        if (!t) return { toggle: false };
        t.click();
        await new Promise((r) => setTimeout(r, 1200));
        const b = document.querySelector('#breakdownLineOverrideWrap .lo-history-scroll');
        if (!b) return { toggle: true, box: false };
        b.focus();
        return { toggle: true, box: true, tabindex: b.getAttribute('tabindex'), role: b.getAttribute('role'),
            ariaLabel: b.getAttribute('aria-label'), focused: document.activeElement === b };
    });
    measured('s11 history scroll box', info);
    check('s11: the fixture row has a history to open', info.toggle === true && info.box === true, JSON.stringify(info));
    if (info.box) {
        check('s11: the box is reachable from a keyboard', info.tabindex === '0' && info.focused === true, JSON.stringify(info));
        check('s11: ...and names which line it belongs to', info.role === 'group'
            && !!info.ariaLabel && info.ariaLabel.length > 0, JSON.stringify(info));
    }
    reportOk('s11', ctx);
    await ctx.context.close();
}

async function main() {
    console.log('fixture rows: ' + Object.keys(FIXTURE).filter((k) => /^R\d$/.test(k))
        .map((k) => k + '=' + FIXTURE[k].employee_no).join(' '));
    try {
        await s1({ tag: 's1', width: 1400, height: 950 });
        await s2();
        await s3();
        await s4();
        await s1({ tag: 's5', width: 430, height: 932, theme: 'dark' });
        await s6();
        await listCell({ tag: 's7', width: 1400 });
        await listCell({ tag: 's8', width: 430, height: 932, theme: 'dark' });
        await s9();
        await s10();
        await s11();
    } finally {
        await closeAll();
    }
    console.log('\nPassed: ' + passed + ', Failed: ' + failed);
    if (failed > 0) process.exitCode = 1;
}
main();
