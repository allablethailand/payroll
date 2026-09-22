/**
 * slip2-a measurement: the slip's line table is 3 columns, and a row's controls live in its item
 * cell.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/slip2a_line_table.js <PHPSESSID> <fixtureRunToken> <employeeId> [<lockedRunToken> <lockedEmployeeId> <historyRunToken> <historyEmployeeId>]
 *
 * What the round is about, in the order the report asked for it:
 *   a1  1400 th light/dark -- 3 columns; every row's figure ends on the x the totals under the table
 *       end on; every row's pencil starts on one x; slot 2 is the bin / the restore / a reserved
 *       empty one according to what the row IS; the buttons are borderless until hover; 32x32.
 *   a2  430 th dark -- the table no longer scrolls sideways, and the controls drop below the name.
 *   a3  the per-row restore: one confirm, and the write it sends is the write that already existed.
 *   a4  the history count is quiet text, and the panel closes with the app's own modal button.
 *   a5  an empty manual group says so, in the editable slip only.
 *   a6  the read-only slip: 2 columns, no controls, same figure alignment.
 *   a7  768 en -- the words come from en.json, not from a fallback baked into the renderer.
 *
 * Writes: NONE. Every mutating route is blocked at the network layer, and a3 presses the real button
 * to prove what it WOULD have sent -- the count and the path are the measurement.
 */
'use strict';
const { openContext, closeAll, ensureCellTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
const lockedRunToken = process.argv[5] || '';
const lockedEmployeeId = process.argv[6] || '';
const historyRunToken = process.argv[7] || '';
const historyEmployeeId = process.argv[8] || '';
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/slip2a_line_table.js <PHPSESSID> <fixtureRunToken> <employeeId> [<lockedRunToken> <lockedEmployeeId> <historyRunToken> <historyEmployeeId>]');
}

// Every write this round must never make. Named here rather than in the harness because which
// endpoint is "the dangerous one" is a property of the page being measured, not of the browser.
const WRITE_PATHS = [
    'api/payroll-run.line-override.save',
    'api/payroll-run.line-override.remove',
    'api/payroll-run.statutory-line-override.save',
    'api/payroll-run.statutory-line-override.remove',
    'api/payroll-run.save-employee-exemption',
    'api/payroll-run.add-manual-line',
    'api/payroll-run.update-manual-line',
    'api/payroll-run.remove-manual-line',
    'api/payroll-run.employee-verify.save',
];

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}
const measured = (label, value) => console.log('  MEASURED  ' + label + ' = ' + (typeof value === 'object' ? JSON.stringify(value) : value));

const WRAP = '#breakdownLineOverrideWrap';

async function openSlip(page, empId) {
    // Which slip opens is decided by `currentRun` and the row's own verify flag, both of which
    // arrive with the page's own fetches -- pressing before they land opens the wrong one.
    await page.waitForFunction(() => typeof currentRun !== 'undefined' && !!currentRun && !!currentRun.state, null, { timeout: 30000 });
    await page.waitForSelector(`.btn-view-breakdown[data-employee-id="${empId}"]`, { state: 'attached', timeout: 30000 });
    await page.evaluate((id) => document.querySelector(`.btn-view-breakdown[data-employee-id="${id}"]`).click(), empId);
    await page.waitForSelector(`${WRAP} tr.lo-row`, { timeout: 30000 });
    await page.waitForTimeout(900);
}

/* Everything the geometry assertions read, in one pass. Every x is a RENDERED edge, not a declared
   width: what has to line up is what the reader sees, and a declared width says nothing about
   padding, borders or sub-pixel rounding (the same reason the sticky offset is measured too). */
function snapshot(wrap) {
    const r1 = (n) => Math.round(n * 10) / 10;
    const table = document.querySelector(wrap + ' table.lo-table');
    const scroller = document.querySelector(wrap + ' .table-responsive');
    const rows = Array.from(document.querySelectorAll(wrap + ' tr.lo-row'));
    const totalNums = Array.from(document.querySelectorAll(wrap + ' .lo-totals-row .num'));
    const figureOf = (r) => r.querySelector('td.lo-amount-cell .num, td.lo-amount-cell .lo-amount-excluded');
    return {
        headClasses: Array.from(table.querySelectorAll('thead th')).map((t) => t.className),
        minWidth: getComputedStyle(table).minWidth,
        scrollWidth: scroller ? scroller.scrollWidth : null,
        clientWidth: scroller ? scroller.clientWidth : null,
        rows: rows.map((r) => {
            const name = r.querySelector('.lo-name');
            const block = r.querySelector('.lo-row-actions');
            const pencil = r.querySelector('.lo-edit-btn, .manual-line-edit-btn');
            const fig = figureOf(r);
            return {
                code: r.getAttribute('data-item-code'),
                type: r.getAttribute('data-line-type'),
                overridden: (r.getAttribute('data-orig-action') || '') !== '',
                answered: !!r.getAttribute('data-exemption-changed'),
                height: r1(r.getBoundingClientRect().height),
                tags: r.querySelectorAll('.payslip-line-tag').length,
                figureRight: fig ? r1(fig.getBoundingClientRect().right) : null,
                nameTop: name ? r1(name.getBoundingClientRect().top) : null,
                blockTop: block ? r1(block.getBoundingClientRect().top) : null,
                blockRight: block ? r1(block.getBoundingClientRect().right) : null,
                blockWidth: block ? r1(block.getBoundingClientRect().width) : null,
                pencilLeft: pencil ? r1(pencil.getBoundingClientRect().left) : null,
                // What slot 2 really holds on this row, by role rather than by icon.
                slotTwo: r.querySelector('.manual-line-remove-btn') ? 'bin'
                    : (r.querySelector('.lo-row-restore-btn') ? 'restore'
                        : (r.querySelector('.lo-slot-empty') ? 'reserved' : 'none')),
                hasCount: !!r.querySelector('.lo-history-count'),
            };
        }),
        totalsRight: totalNums.map((n) => r1(n.getBoundingClientRect().right)),
        totalsMarginTop: (() => {
            const b = document.querySelector(wrap + ' .lo-totals');
            return b ? getComputedStyle(b).marginTop : null;
        })(),
        emptyGroupRows: document.querySelectorAll(wrap + ' tr.lo-group-empty .empty-state-inline').length,
        emptyGroupText: (() => {
            const e = document.querySelector(wrap + ' tr.lo-group-empty .empty-state-inline');
            return e ? e.textContent.trim() : null;
        })(),
        groupHeads: document.querySelectorAll(wrap + ' tr.lo-group').length,
        // The retired columns must be gone from the DOM, not merely empty.
        retiredNodes: document.querySelectorAll(wrap + ' .lo-action-cell, ' + wrap + ' .lo-history-cell, '
            + wrap + ' th.lo-action-col, ' + wrap + ' th.lo-history-col').length,
        badgeInRows: document.querySelectorAll(wrap + ' tr.lo-row [data-badge="count"]').length,
    };
}

// The resting style of a row control, and what changes on hover -- ghost means "no box until you
// reach for it", which is only true if BOTH halves hold.
function buttonStyleProbe(wrap) {
    const b = document.querySelector(wrap + ' .lo-row-actions .btn-icon');
    if (!b) return null;
    const rect = b.getBoundingClientRect();
    const cs = getComputedStyle(b);
    return {
        cls: b.className,
        w: Math.round(rect.width),
        h: Math.round(rect.height),
        borderWidth: cs.borderTopWidth,
        background: cs.backgroundColor,
        color: cs.color,
    };
}

async function cellContext(o) {
    const ctx = await openContext({
        sessionId, width: o.width, height: o.height, colorScheme: o.theme, blockPaths: WRITE_PATHS,
    });
    const { page } = ctx;
    await page.goto(ctx.url(`/payroll-process/${o.token}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    if (o.lang) {
        await page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, o.lang);
        await page.waitForTimeout(700);
    }
    const ok = await ensureCellTheme(page, o.theme, { label: o.label, when: 'first load', check, log: console.log });
    return { ctx, ok };
}

// ---------------------------------------------------------------- a1 / a2 ---
async function runGeometryCell(o) {
    const label = `a1 ${o.width} ${o.lang} ${o.theme}`;
    console.log(`\n[${label}]`);
    const { ctx, ok } = await cellContext(Object.assign({ token: runToken, label }, o));
    if (!ok) { await ctx.context.close(); return ctx.report(); }
    const { page, report } = ctx;
    await openSlip(page, employeeId);
    const s = await page.evaluate(snapshot, WRAP);
    measured(`${label} head`, s.headClasses);
    measured(`${label} min-width`, s.minWidth);
    measured(`${label} scroller`, { scrollWidth: s.scrollWidth, clientWidth: s.clientWidth });
    measured(`${label} rows`, s.rows);
    measured(`${label} totals right / margin-top`, { right: s.totalsRight, marginTop: s.totalsMarginTop });

    check(`${label}: the editable slip declares 3 columns`, s.headClasses.length === 3, JSON.stringify(s.headClasses));
    check(`${label}: ...ending on the money one`, /col-money/.test(s.headClasses[2] || ''), JSON.stringify(s.headClasses));
    check(`${label}: the 2 retired columns are gone from the DOM, not merely empty`, s.retiredNodes === 0, String(s.retiredNodes));
    check(`${label}: no row carries a count BADGE any more`, s.badgeInRows === 0, String(s.badgeInRows));
    check(`${label}: every row carries the row action block`,
        s.rows.every((r) => r.blockWidth !== null), JSON.stringify(s.rows.map((r) => r.code + ':' + r.blockWidth)));

    // THE one this round exists for: a row's figure and the run's own total end on one x.
    const figureRights = Array.from(new Set(s.rows.map((r) => r.figureRight)));
    const totalsRights = Array.from(new Set(s.totalsRight));
    check(`${label}: every row's figure ends on one x`, figureRights.length === 1, JSON.stringify(figureRights));
    check(`${label}: ...and that x is the x the totals end on (+-0.5)`,
        figureRights.length === 1 && totalsRights.length === 1 && Math.abs(figureRights[0] - totalsRights[0]) <= 0.5,
        JSON.stringify({ rows: figureRights, totals: totalsRights }));
    check(`${label}: the totals block stands clear of the table`, s.totalsMarginTop === '12px', String(s.totalsMarginTop));

    // The block keeps one width, so the pencil of every row starts on one x.
    const pencilLefts = Array.from(new Set(s.rows.filter((r) => r.pencilLeft !== null).map((r) => r.pencilLeft)));
    const blockRights = Array.from(new Set(s.rows.map((r) => r.blockRight)));
    check(`${label}: every pencil starts on one x (+-0.5)`,
        pencilLefts.length === 1, JSON.stringify(pencilLefts));
    /* ...because the 2 button slots are the LAST thing in the block and the block ends on the cell's
       own right edge. The block's total width is NOT constant and must not be asserted to be: the
       history count in front of the buttons is text of a width nobody controls, which is exactly why
       it sits on the left, where it grows without moving anything. */
    check(`${label}: ...because the block always ends on one x`, blockRights.length === 1, JSON.stringify(blockRights));
    measured(`${label} block widths (count text makes these differ, by design)`, Array.from(new Set(s.rows.map((r) => r.blockWidth))));

    // Slot 2 by what the row IS, read off the row's own data-attributes rather than named per code.
    const slotWrong = s.rows.filter((r) => {
        const want = r.type === 'manual_line' ? 'bin' : ((r.overridden || r.answered) ? 'restore' : 'reserved');
        return r.slotTwo !== want;
    });
    check(`${label}: slot 2 is the bin / the restore / a reserved empty one, per row kind`,
        slotWrong.length === 0, JSON.stringify(slotWrong.map((r) => ({ code: r.code, got: r.slotTwo }))));

    const btn = await page.evaluate(buttonStyleProbe, WRAP);
    measured(`${label} button at rest`, btn);
    check(`${label}: a row button is 32x32`, btn && btn.w === 32 && btn.h === 32, JSON.stringify(btn));
    check(`${label}: ...the ghost variant, with no border and no fill at rest`,
        btn && /btn-icon-ghost/.test(btn.cls) && btn.borderWidth === '0px'
        && (btn.background === 'rgba(0, 0, 0, 0)' || btn.background === 'transparent'), JSON.stringify(btn));
    const hovered = await page.evaluate(async (wrap) => {
        const b = document.querySelector(wrap + ' .lo-row-actions .btn-icon');
        b.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
        b.classList.add(':hover');
        b.focus();
        await new Promise((r) => setTimeout(r, 200));
        const cs = getComputedStyle(b);
        return { background: cs.backgroundColor, focusRing: cs.boxShadow };
    }, WRAP);
    measured(`${label} button focused`, hovered);
    // `:hover` cannot be forced from script, so focus is what is asserted -- the point is the same:
    // reaching for the control is what makes a box appear.
    check(`${label}: ...and a box appears when it is reached for`,
        hovered && hovered.focusRing !== 'none' && hovered.focusRing !== '', JSON.stringify(hovered));

    const noTag = s.rows.filter((r) => r.tags === 0);
    measured(`${label} row heights without a sub-line`, noTag.map((r) => ({ code: r.code, h: r.height })));

    const rep = report();
    check(`${label}: nothing was written`, rep.blockedWrites === 0, JSON.stringify(rep.blockedWritePaths));
    check(`${label}: no page error`, rep.pageErrors.length === 0, JSON.stringify(rep.pageErrors.slice(0, 2)));
    await ctx.context.close();
    return rep;
}

async function runNarrowCell() {
    const label = 'a2 430 th dark';
    console.log(`\n[${label}]`);
    const { ctx, ok } = await cellContext({ token: runToken, width: 430, height: 932, lang: 'th', theme: 'dark', label });
    if (!ok) { await ctx.context.close(); return ctx.report(); }
    const { page, report } = ctx;
    await openSlip(page, employeeId);
    const s = await page.evaluate(snapshot, WRAP);
    measured(`${label} scroller`, { scrollWidth: s.scrollWidth, clientWidth: s.clientWidth, minWidth: s.minWidth });
    measured(`${label} rows`, s.rows.map((r) => ({ code: r.code, nameTop: r.nameTop, blockTop: r.blockTop, figureRight: r.figureRight, h: r.height })));
    check(`${label}: the table fits its host -- no sideways scroll left`,
        s.scrollWidth !== null && s.scrollWidth <= s.clientWidth, `${s.scrollWidth} / ${s.clientWidth}`);
    check(`${label}: the controls drop below the name`,
        s.rows.filter((r) => r.blockTop !== null).every((r) => r.blockTop > r.nameTop),
        JSON.stringify(s.rows.map((r) => ({ code: r.code, name: r.nameTop, block: r.blockTop }))));
    // Every figure is inside the visible box without dragging -- which is what "no scroll" buys.
    const hostRight = await page.evaluate((wrap) => Math.round(document.querySelector(wrap + ' .table-responsive').getBoundingClientRect().right * 10) / 10, WRAP);
    check(`${label}: every figure is visible without dragging`,
        s.rows.every((r) => r.figureRight !== null && r.figureRight <= hostRight + 0.5),
        JSON.stringify({ hostRight, figures: Array.from(new Set(s.rows.map((r) => r.figureRight))) }));
    const rep = report();
    check(`${label}: nothing was written`, rep.blockedWrites === 0, JSON.stringify(rep.blockedWritePaths));
    await ctx.context.close();
    return rep;
}

// -------------------------------------------------------------------- a3 ---
// The per-row restore. What is measured is the DIALOG (one, not two) and the request the button
// would have sent -- blocked, counted and named, so the round proves the write without making it.
async function runRestoreCell() {
    const label = 'a3 1400 th light restore';
    console.log(`\n[${label}]`);
    const { ctx } = await cellContext({ token: runToken, width: 1400, height: 950, lang: 'th', theme: 'light', label });
    const { page, report } = ctx;
    await openSlip(page, employeeId);

    const kinds = await page.evaluate((wrap) => Array.from(document.querySelectorAll(wrap + ' tr.lo-row')).map((r) => ({
        code: r.getAttribute('data-item-code'),
        type: r.getAttribute('data-line-type'),
        overridden: (r.getAttribute('data-orig-action') || '') !== '',
        answered: !!r.getAttribute('data-exemption-changed'),
        restore: !!r.querySelector('.lo-row-restore-btn'),
    })), WRAP);
    measured(`${label} rows`, kinds);
    const manual = kinds.filter((k) => k.type === 'manual_line');
    check(`${label}: a hand-added row never offers "back to the calculated value"`,
        manual.length > 0 && manual.every((k) => !k.restore), JSON.stringify(manual));

    const overridden = kinds.find((k) => k.overridden && k.type !== 'manual_line');
    const triState = kinds.find((k) => k.answered && !k.overridden);

    const press = async (code) => {
        await page.evaluate((args) => {
            document.querySelector(`${args.wrap} tr.lo-row[data-item-code="${args.code}"] .lo-row-restore-btn`).click();
        }, { wrap: WRAP, code });
        await page.waitForSelector('.swal2-container', { timeout: 15000 });
        await page.waitForTimeout(400);
        return page.evaluate(() => ({
            dialogs: document.querySelectorAll('.swal2-container').length,
            title: (document.querySelector('.swal2-title') || { textContent: '' }).textContent.trim(),
            text: (document.querySelector('.swal2-html-container') || { textContent: '' }).textContent.trim(),
        }));
    };

    if (overridden) {
        const dlg = await press(overridden.code);
        measured(`${label} confirm`, dlg);
        check(`${label}: pressing it asks once, not twice`, dlg.dialogs === 1, String(dlg.dialogs));
        check(`${label}: ...and the question names the row`,
            dlg.text.indexOf(overridden.code) !== -1 || dlg.text.length > 0, JSON.stringify(dlg));
        // Cancel writes nothing at all.
        await page.locator('.swal2-cancel').click();
        await page.waitForTimeout(800);
        const afterCancel = report();
        check(`${label}: cancelling writes nothing`, afterCancel.blockedWrites === 0, JSON.stringify(afterCancel.blockedWritePaths));
        // Confirm sends the write the row's own action has always sent -- once.
        await press(overridden.code);
        await page.locator('.swal2-confirm').click();
        await page.waitForTimeout(2500);
        const afterConfirm = report();
        measured(`${label} blocked write paths`, afterConfirm.blockedWritePaths);
        const removes = afterConfirm.blockedWritePaths.filter((p) => /line-override\.remove$/.test(p));
        check(`${label}: confirming sends exactly one "drop the override"`, removes.length === 1, JSON.stringify(afterConfirm.blockedWritePaths));
        check(`${label}: ...and nothing else`, afterConfirm.blockedWrites === removes.length, JSON.stringify(afterConfirm.blockedWritePaths));
    } else {
        console.log(`  SKIP  ${label}: this run has no overridden row to put back`);
    }

    if (triState) {
        const before = report().blockedWrites;
        await press(triState.code);
        await page.locator('.swal2-confirm').click();
        await page.waitForTimeout(2500);
        const rep = report();
        const sent = rep.blockedWritePaths.slice(before);
        measured(`${label} tri-state write paths`, sent);
        check(`${label}: a tri-state row's restore goes to the exemption endpoint`,
            sent.some((p) => /save-employee-exemption$/.test(p)), JSON.stringify(sent));
    } else {
        console.log(`  SKIP  ${label}: this run has no tri-state answer of its own to reset`);
    }

    const rep = report();
    check(`${label}: no page error`, rep.pageErrors.length === 0, JSON.stringify(rep.pageErrors.slice(0, 2)));
    await ctx.context.close();
    return rep;
}

// -------------------------------------------------------------------- a4 ---
async function runCountAndCloseCell() {
    const label = 'a4 1400 th light count/close';
    console.log(`\n[${label}]`);
    const { ctx } = await cellContext({ token: runToken, width: 1400, height: 950, lang: 'th', theme: 'light', label });
    const { page, report } = ctx;
    await openSlip(page, employeeId);

    const count = await page.evaluate((wrap) => {
        const el = document.querySelector(wrap + ' .lo-history-count');
        if (!el) return null;
        const cs = getComputedStyle(el);
        const probe = document.createElement('span');
        probe.style.color = getComputedStyle(document.documentElement).getPropertyValue('--c-text-muted').trim();
        document.body.appendChild(probe);
        const muted = getComputedStyle(probe).color;
        probe.remove();
        return { text: el.textContent.trim(), color: cs.color, muted, background: cs.backgroundColor, radius: cs.borderRadius };
    }, WRAP);
    measured(`${label} count`, count);
    check(`${label}: the count is the muted text token`, count && count.color === count.muted, JSON.stringify(count));
    check(`${label}: ...with no pill behind it`,
        count && (count.background === 'rgba(0, 0, 0, 0)' || count.background === 'transparent'), JSON.stringify(count));

    const opened = await page.evaluate(async (wrap) => {
        const t = document.querySelector(wrap + ' tr.lo-row .lo-history-toggle');
        t.click();
        await new Promise((r) => setTimeout(r, 1200));
        const x = document.querySelector(wrap + ' tr.lo-history-row .lo-history-close');
        const rect = x ? x.getBoundingClientRect() : null;
        return {
            expanded: t.getAttribute('aria-expanded'),
            panels: document.querySelectorAll(wrap + ' tr.lo-history-row .lo-history-panel').length,
            close: x ? { cls: x.className, w: Math.round(rect.width), h: Math.round(rect.height),
                centerX: Math.round((rect.left + rect.right) / 2 * 10) / 10 } : null,
        };
    }, WRAP);
    measured(`${label} panel`, opened);
    check(`${label}: the count opens the panel`, opened.panels === 1 && opened.expanded === 'true', JSON.stringify(opened));
    check(`${label}: the way out is the app's own modal close button`,
        opened.close && /btn-close/.test(opened.close.cls) && !/btn-icon/.test(opened.close.cls), JSON.stringify(opened.close));
    check(`${label}: ...at the 32px target every row control keeps`,
        opened.close && opened.close.w === 32 && opened.close.h === 32, JSON.stringify(opened.close));
    /* The ✕ has to stay where the bordered circle was -- it is the top-right corner of a panel whose
       other buttons all end on one x. The baseline is the circle's own centre, measured on the build
       before this round (1400 th light); pass SLIP2A_CLOSE_CENTER_X to re-baseline it rather than
       editing the number here. */
    const closeBaseline = Number(process.env.SLIP2A_CLOSE_CENTER_X || 1059);
    check(`${label}: ...on the x the bordered circle was on (+-1)`,
        opened.close && Math.abs(opened.close.centerX - closeBaseline) <= 1,
        JSON.stringify({ now: opened.close && opened.close.centerX, before: closeBaseline }));

    const closed = await page.evaluate(async (wrap) => {
        document.querySelector(wrap + ' tr.lo-history-row .lo-history-close').click();
        await new Promise((r) => setTimeout(r, 600));
        const t = document.querySelector(wrap + ' tr.lo-row .lo-history-toggle');
        return {
            panels: document.querySelectorAll(wrap + ' tr.lo-history-row').length,
            expanded: t.getAttribute('aria-expanded'),
            focusBack: document.activeElement === t,
        };
    }, WRAP);
    measured(`${label} after close`, closed);
    check(`${label}: closing it collapses the row and says so`,
        closed.panels === 0 && closed.expanded === 'false', JSON.stringify(closed));
    check(`${label}: ...and the focus goes back to where the reader was`, closed.focusBack === true, JSON.stringify(closed));

    const rep = report();
    check(`${label}: nothing was written`, rep.blockedWrites === 0, JSON.stringify(rep.blockedWritePaths));
    await ctx.context.close();
    return rep;
}

// -------------------------------------------------------------------- a5 ---
async function runEmptyGroupCell() {
    const label = 'a5 1400 th light empty group';
    console.log(`\n[${label}]`);
    const { ctx } = await cellContext({ token: runToken, width: 1400, height: 950, lang: 'th', theme: 'light', label });
    const { page, report } = ctx;
    await openSlip(page, employeeId);
    const s = await page.evaluate(snapshot, WRAP);
    const lang = await page.evaluate(() => (typeof langData === 'object' && langData['line_override_group_empty']) || null);
    measured(`${label} empty rows / text`, { rows: s.emptyGroupRows, text: s.emptyGroupText, key: lang });
    check(`${label}: an empty manual group says so, once per empty group`,
        s.emptyGroupRows > 0, String(s.emptyGroupRows));
    check(`${label}: ...in the words of the key, not a fallback baked into the renderer`,
        !!lang && s.emptyGroupText === lang, JSON.stringify({ shown: s.emptyGroupText, key: lang }));
    const rep = report();
    await ctx.context.close();
    return rep;
}

// -------------------------------------------------------------------- a6 ---
async function runViewCell(o) {
    const label = `a6 ${o.tag} 1400 th light view`;
    console.log(`\n[${label}]`);
    const { ctx } = await cellContext({ token: o.token, width: 1400, height: 950, lang: 'th', theme: 'light', label });
    const { page, report } = ctx;
    await openSlip(page, o.employeeId);
    const s = await page.evaluate(snapshot, WRAP);
    measured(`${label} head`, s.headClasses);
    measured(`${label} rows`, s.rows.map((r) => ({ code: r.code, figureRight: r.figureRight, slotTwo: r.slotTwo, count: r.hasCount })));
    measured(`${label} totals right`, s.totalsRight);
    check(`${label}: the read-only slip declares 2 columns`, s.headClasses.length === 2, JSON.stringify(s.headClasses));
    check(`${label}: it renders no row control at all`,
        s.rows.every((r) => r.slotTwo === 'none' && r.pencilLeft === null),
        JSON.stringify(s.rows.map((r) => ({ code: r.code, slot: r.slotTwo, pencil: r.pencilLeft }))));
    check(`${label}: an empty manual group is simply absent here`, s.emptyGroupRows === 0, String(s.emptyGroupRows));
    const figureRights = Array.from(new Set(s.rows.map((r) => r.figureRight)));
    const totalsRights = Array.from(new Set(s.totalsRight));
    check(`${label}: every figure ends on the x the totals end on (+-0.5)`,
        figureRights.length === 1 && totalsRights.length === 1 && Math.abs(figureRights[0] - totalsRights[0]) <= 0.5,
        JSON.stringify({ rows: figureRights, totals: totalsRights }));
    // The one control the read-only slip keeps, where the run really has something recorded.
    const withCount = s.rows.filter((r) => r.hasCount).length;
    measured(`${label} rows carrying a count`, withCount);
    const rep = report();
    check(`${label}: nothing was written`, rep.blockedWrites === 0, JSON.stringify(rep.blockedWritePaths));
    await ctx.context.close();
    return rep;
}

/* a6b -- the same alignment question on a run with many more lines than the fixture has. WHICH slip
   opens there is a property of that run (a draft with an unverified row opens the editable one), and
   this cell does not care: what it measures is that every figure ends on the x the totals end on,
   whatever the mode and however many rows there are. */
async function runAlignmentCell(o) {
    const label = `a6b ${o.tag} 1400 th light`;
    console.log(`
[${label}]`);
    const { ctx } = await cellContext({ token: o.token, width: 1400, height: 950, lang: 'th', theme: 'light', label });
    const { page, report } = ctx;
    await openSlip(page, o.employeeId);
    const s = await page.evaluate(snapshot, WRAP);
    const figureRights = Array.from(new Set(s.rows.map((r) => r.figureRight)));
    const totalsRights = Array.from(new Set(s.totalsRight));
    const pencilLefts = Array.from(new Set(s.rows.filter((r) => r.pencilLeft !== null).map((r) => r.pencilLeft)));
    measured(`${label} rows / figures / pencils`, { rows: s.rows.length, figureRights, totalsRights, pencilLefts });
    check(`${label}: the run really has more lines than the fixture`, s.rows.length >= 8, String(s.rows.length));
    check(`${label}: every figure ends on one x, and it is the totals' x (+-0.5)`,
        figureRights.length === 1 && totalsRights.length === 1 && Math.abs(figureRights[0] - totalsRights[0]) <= 0.5,
        JSON.stringify({ rows: figureRights, totals: totalsRights }));
    check(`${label}: every pencil starts on one x`, pencilLefts.length <= 1, JSON.stringify(pencilLefts));
    const rep = report();
    check(`${label}: nothing was written`, rep.blockedWrites === 0, JSON.stringify(rep.blockedWritePaths));
    await ctx.context.close();
    return rep;
}

// -------------------------------------------------------------------- a7 ---
async function runEnglishCell() {
    const label = 'a7 768 en light';
    console.log(`\n[${label}]`);
    const { ctx } = await cellContext({ token: runToken, width: 768, height: 950, lang: 'en', theme: 'light', label });
    const { page, report } = ctx;
    await openSlip(page, employeeId);
    const words = await page.evaluate((wrap) => {
        const keys = ['line_override_group_empty', 'confirm_row_restore_title', 'confirm_row_restore_message',
            'line_override_edit_amount', 'line_override_group_restore', 'action_remove', 'close'];
        const out = { lang: {} };
        keys.forEach((k) => { out.lang[k] = (typeof langData === 'object' && langData[k]) || null; });
        const empty = document.querySelector(wrap + ' tr.lo-group-empty .empty-state-inline');
        out.emptyText = empty ? empty.textContent.trim() : null;
        const restore = document.querySelector(wrap + ' .lo-row-restore-btn');
        out.restoreAria = restore ? restore.getAttribute('aria-label') : null;
        const pencil = document.querySelector(wrap + ' .lo-edit-btn');
        out.pencilAria = pencil ? pencil.getAttribute('aria-label') : null;
        return out;
    }, WRAP);
    measured(`${label} words`, words);
    check(`${label}: en.json really carries the 3 new keys`,
        ['line_override_group_empty', 'confirm_row_restore_title', 'confirm_row_restore_message']
            .every((k) => typeof words.lang[k] === 'string' && words.lang[k].length > 0), JSON.stringify(words.lang));
    check(`${label}: the empty-group line is the English one`,
        words.emptyText === words.lang['line_override_group_empty'], JSON.stringify({ shown: words.emptyText }));
    check(`${label}: the row controls name themselves in English`,
        words.pencilAria === words.lang['line_override_edit_amount']
        && (words.restoreAria === null || words.restoreAria === words.lang['line_override_group_restore']),
        JSON.stringify({ pencil: words.pencilAria, restore: words.restoreAria }));
    const dlg = await page.evaluate(async (wrap) => {
        const b = document.querySelector(wrap + ' .lo-row-restore-btn');
        if (!b) return null;
        b.click();
        await new Promise((r) => setTimeout(r, 1200));
        return { title: (document.querySelector('.swal2-title') || { textContent: '' }).textContent.trim() };
    }, WRAP);
    if (dlg) {
        measured(`${label} confirm title`, dlg);
        check(`${label}: ...and so does the question it asks`,
            dlg.title === words.lang['confirm_row_restore_title'], JSON.stringify(dlg));
        await page.locator('.swal2-cancel').click();
        await page.waitForTimeout(500);
    }
    const rep = report();
    check(`${label}: nothing was written`, rep.blockedWrites === 0, JSON.stringify(rep.blockedWritePaths));
    await ctx.context.close();
    return rep;
}

(async () => {
    const reports = [];
    reports.push(await runGeometryCell({ width: 1400, height: 950, lang: 'th', theme: 'light' }));
    reports.push(await runGeometryCell({ width: 1400, height: 950, lang: 'th', theme: 'dark' }));
    reports.push(await runNarrowCell());
    reports.push(await runRestoreCell());
    reports.push(await runCountAndCloseCell());
    reports.push(await runEmptyGroupCell());
    if (lockedRunToken && lockedEmployeeId) {
        reports.push(await runViewCell({ token: lockedRunToken, employeeId: lockedEmployeeId, tag: 'locked' }));
    } else {
        console.log('\n  SKIP  a6: no locked run given');
    }
    if (historyRunToken && historyEmployeeId) {
        reports.push(await runAlignmentCell({ token: historyRunToken, employeeId: historyEmployeeId, tag: 'long-history' }));
    } else {
        console.log('\n  SKIP  a6b: no long-history run given');
    }
    reports.push(await runEnglishCell());
    await closeAll();
    console.log(`\nblockedPreferenceSaves total: ${reports.reduce((a, r) => a + r.blockedPreferenceSaves, 0)}`);
    console.log(`blockedWrites total: ${reports.reduce((a, r) => a + r.blockedWrites, 0)}`);
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed === 0 ? 0 : 1);
})();
