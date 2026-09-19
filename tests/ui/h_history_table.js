/**
 * H-ui measurement: the line history as a TABLE under the row -- what replaced the 5-row dropdown
 * behind the "แก้ไข n" badge and the nested modal it linked to.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/h_history_table.js <PHPSESSID> <runToken> <employeeId>
 *
 * The fixture (tests/ui/mksession.php) gives its employee all 3 kinds of recorded edit: one
 * overridden line (__base_salary__), one hand-added line, and one tri-state answer on TH_PIT. Every
 * endpoint that could write one is blocked at the network layer for every cell below, so what is
 * measured is the rendering and nothing else -- the one cell that does press [ใช้ค่านี้] proves the
 * write was attempted and stopped, rather than letting it through.
 *
 * Cells:
 *   h1  the table itself, 8 cells (th/en x light/dark x 1400/430) -- a layout round
 *   h2  the read-only slip's marks, 2 cells
 *   h3  a 38-entry chain on a REAL old run (752 / EM009), READ ONLY, 2 cells
 *   h4  pressing [ใช้ค่านี้] -> exactly 1 blocked write, to the endpoint it claims
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/h_history_table.js <PHPSESSID> <runToken> <employeeId>');
}
// The real run this app has had since long before this round: 38 recorded edits on one line, which
// is the case a 5-row dropdown could never show. Opened READ ONLY -- nothing here writes to it.
const OLD_RUN_TOKEN = 'ARhUfF7hWJhIQ-6xcYkadfq5JzKUD0wZSNsRlRfoQHA';
const OLD_EMPLOYEE_ID = 159;
const OLD_ITEM_CODE = '__base_salary__';

// Every write this round must never make. Named here rather than in the harness because which
// endpoint is "the dangerous one" is a property of the page, not of the browser (harness.js).
const BLOCK_PATHS = [
    'api/payroll-run.line-override.save',
    'api/payroll-run.line-override.remove',
    'api/payroll-run.statutory-line-override.save',
    'api/payroll-run.statutory-line-override.remove',
    'api/payroll-run.save-employee-exemption',
    'api/payroll-run.add-manual-line',
    'api/payroll-run.update-manual-line',
    'api/payroll-run.remove-manual-line',
];

const ONLY = process.env.H_ONLY || '';
const wanted = (label) => !ONLY || label.indexOf(ONLY) !== -1;
let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

const WRAP = '#breakdownLineOverrideWrap';

async function openSlip(page, id) {
    await page.waitForFunction(() => typeof currentRun !== 'undefined' && !!currentRun && !!currentRun.state, null, { timeout: 30000 });
    await page.evaluate((eid) => document.querySelector('.btn-view-breakdown[data-employee-id="' + eid + '"]').click(), id);
    await page.waitForSelector(WRAP + ' tr.lo-row', { timeout: 30000 });
    await page.waitForTimeout(900);
}
async function closeSlip(page) {
    await page.evaluate(() => {
        const inst = bootstrap.Modal.getInstance(document.getElementById('runDetailBreakdownModal'));
        if (inst) inst.hide();
    });
    await page.waitForTimeout(400);
}
/* This account's saved theme is 'light', which header.php stamps as data-bs-theme="light" -- and
   that attribute is exactly what style.css's own `:root:not([data-bs-theme="light"])` guard uses to
   turn the OS-dark media query OFF. So a "dark" browser alone proves nothing here: the dark cells
   apply the theme through the app's own applyTheme(), the same call its toggle makes, and the
   preference write that would follow it is blocked at the network layer by the harness. The saved
   preference is never changed. */
async function gotoRun(ctx, token, lang, theme) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(700);
    await ctx.page.evaluate((args) => {
        if (typeof changeLanguage === 'function') changeLanguage(args.lang);
        if (args.theme && typeof applyTheme === 'function') applyTheme(args.theme);
    }, { lang, theme });
    await ctx.page.waitForTimeout(600);
}
async function post(page, endpoint, payload) {
    return page.evaluate(async (args) => {
        const res = await fetch(BASE_URL + '/api/' + args.endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ id: PAYROLL_RUN_ID }, args.payload)),
        });
        return res.json();
    }, { endpoint, payload });
}
// Opens the history of the row carrying `code` and returns everything about it in one pass.
async function openHistory(page, code) {
    return page.evaluate(async (args) => {
        const row = document.querySelector(args.wrap + ' tr.lo-row[data-item-code="' + args.code + '"]');
        if (!row) return { found: false };
        const badge = row.querySelector('.lo-history-toggle');
        if (!badge) return { found: true, badge: false };
        badge.click();
        await new Promise(r => setTimeout(r, 500));
        const panelRow = row.nextElementSibling;
        const panel = panelRow ? panelRow.querySelector('.lo-history-panel') : null;
        const table = panel ? panel.querySelector('table.lo-history-table') : null;
        const bar = panel ? panel.querySelector('.lo-history-titlebar') : null;
        const box = panel ? panel.querySelector('.lo-history-scroll') : null;
        const closeBtn = panel ? panel.querySelector('.lo-history-close') : null;
        const scroller = document.querySelector(args.wrap + ' .table-responsive');
        const cs = getComputedStyle(document.documentElement);
        const toRgb = (value) => {
            const probe = document.createElement('span');
            probe.style.color = value;
            document.body.appendChild(probe);
            const out = getComputedStyle(probe).color;
            probe.remove();
            return out;
        };
        // The measured one: drag the scroller and see whether the panel stays put. A cell's own
        // content slides away with the scroll unless it really is pinned.
        let panelPinned = null;
        let panelLeftAfterScroll = null;
        let scrollAmount = null;
        let overflowX = null;
        if (scroller && panel) {
            overflowX = scroller.scrollWidth - scroller.clientWidth;
            const base = Math.round(panel.getBoundingClientRect().left - scroller.getBoundingClientRect().left);
            scrollAmount = Math.min(120, Math.max(0, overflowX));
            scroller.scrollLeft = scrollAmount;
            await new Promise(r => setTimeout(r, 120));
            panelLeftAfterScroll = Math.round(panel.getBoundingClientRect().left - scroller.getBoundingClientRect().left);
            // It starts at `base` (the item name's own x) and may travel at most that far: after a
            // drag of N it sits at max(0, base - N) and stops there instead of sliding out of view.
            panelPinned = panelLeftAfterScroll === Math.max(0, base - scrollAmount);
            scroller.scrollLeft = 0;
            await new Promise(r => setTimeout(r, 80));
        }
        // `:scope >` on purpose: this table is nested inside the slip's own <tbody>, so a plain
        // 'tbody tr' matches its HEADER row too (the ancestor combinator is evaluated against the
        // whole tree, then filtered to descendants of `table`).
        const cells = (sel) => Array.from(table ? table.querySelectorAll(sel) : []);
        return {
            found: true,
            badge: true,
            badgeText: badge.textContent.trim(),
            badgeIsButton: badge.tagName === 'BUTTON',
            badgeExpanded: badge.getAttribute('aria-expanded'),
            badgeHasCaret: badge.classList.contains('dropdown-toggle') || !!badge.querySelector('i,svg'),
            // Nothing may be left of the surfaces this replaced.
            dropdowns: document.querySelectorAll(args.wrap + ' .lo-history-cell .dropdown-menu').length,
            historyModal: document.querySelectorAll('#lineOverrideHistoryModal').length,
            panelRows: panelRow ? 1 : 0,
            panelIsSibling: !!panelRow && panelRow.classList.contains('lo-history-row'),
            colspan: panelRow ? Number(panelRow.querySelector('td').getAttribute('colspan')) : null,
            rowCells: row.querySelectorAll('td').length,
            panelPosition: panel ? getComputedStyle(panel).position : null,
            panelLeft: panel ? getComputedStyle(panel).left : null,
            panelWidth: panel ? Math.round(panel.getBoundingClientRect().width) : null,
            scrollerWidth: scroller ? scroller.clientWidth : null,
            panelPinned,
            panelLeftAfterScroll,
            scrollAmount,
            overflowX,
            varW: (panel && panel.closest('table.lo-table')) ? panel.closest('table.lo-table').style.getPropertyValue('--lo-history-panel-w') : '',
            tdWidth: panelRow ? Math.round(panelRow.querySelector('td').getBoundingClientRect().width) : null,
            headCount: cells(':scope > thead > tr > th').length,
            headTexts: cells(':scope > thead > tr > th').map(t => t.textContent.trim()),
            bodyRows: cells(':scope > tbody > tr').length,
            // Layer (a): the baseline(s) this line has, now in the title bar and never a row.
            computedRows: panel ? panel.querySelectorAll('.lo-history-computed-line').length : 0,
            computedValues: panel ? Array.from(panel.querySelectorAll('.lo-history-computed-line .num'))
                .map(n => n.textContent.trim()) : [],
            computedInList: cells(':scope > tbody > tr.lo-history-computed-row').length,
            // The first row of the list has to be a real edit: a time, not a word.
            firstRowWhen: (() => {
                const c = cells(':scope > tbody > tr > td.lo-history-when')[0];
                return c ? c.textContent.trim() : null;
            })(),
            // Nothing worded in here but the one action that repeats per entry.
            textButtons: panel ? Array.from(panel.querySelectorAll('button'))
                .filter(b => b.textContent.trim() !== '' && !b.classList.contains('lo-history-use'))
                .map(b => b.className + ':' + b.textContent.trim()) : [],
            closeSize: closeBtn ? [Math.round(closeBtn.getBoundingClientRect().width),
                Math.round(closeBtn.getBoundingClientRect().height)] : null,
            barBg: bar ? getComputedStyle(bar).backgroundColor : null,
            barHeight: bar ? Math.round(bar.getBoundingClientRect().height) : null,
            useButtons: cells(':scope > tbody .lo-history-use').length,
            currentTags: cells(':scope > tbody .lo-history-current').length,
            // The word can sit in the title bar instead, when the live figure IS the baseline.
            panelCurrentTags: panel ? panel.querySelectorAll('.lo-history-current').length : 0,
            notes: cells(':scope > tbody .lo-history-note').length,
            useKinds: panel ? Array.from(panel.querySelectorAll('.lo-history-use')).map(b => b.getAttribute('data-kind')) : [],
            panelUseButtons: panel ? panel.querySelectorAll('.lo-history-use').length : 0,
            panelUseClasses: panel ? Array.from(panel.querySelectorAll('.lo-history-use'))
                .map(b => b.className).filter((v, i, a) => a.indexOf(v) === i) : [],
            panelComputedFlags: panel ? panel.querySelectorAll('.lo-history-use[data-computed="1"]').length : 0,
            useEnabledEntries: cells(':scope > tbody .lo-history-use')
                .filter(b => b.getAttribute('data-computed') !== '1' && !b.disabled).length,
            useDisabledEntries: cells(':scope > tbody .lo-history-use').filter(b => b.disabled).length,
            closeButtons: panel ? panel.querySelectorAll('.lo-history-close').length : 0,
            // `overflow-x: hidden` on the panel may not be CLIPPING anything: the 4 columns have to
            // fit the width they are given, at 430 as much as at 1400.
            tableScrollW: table ? table.scrollWidth : null,
            panelClientW: panel ? panel.clientWidth : null,
            useCellRight: (() => {
                const c = table ? table.querySelector('tbody td.lo-history-use-cell') : null;
                if (!c || !panel) return null;
                return Math.round(panel.getBoundingClientRect().right - c.getBoundingClientRect().right);
            })(),
            // The panel caps itself and scrolls inside rather than pushing the slip's table down.
            panelHeight: panel ? Math.round(panel.getBoundingClientRect().height) : null,
            panelScrollH: box ? box.scrollHeight : null,
            panelMaxH: box ? getComputedStyle(box).maxHeight : null,
            boxHeight: box ? Math.round(box.getBoundingClientRect().height) : null,
            headPosition: cells(':scope > thead > tr > th').length
                ? getComputedStyle(cells(':scope > thead > tr > th')[0]).position : null,
            // title bar + column titles + 5 entries, measured off the rows that are really there.
            expectedPanelHeight: (() => {
                const head = table ? table.querySelector('thead') : null;
                const body = cells(':scope > tbody > tr');
                if (!head || !bar || body.length <= 5) return null;
                return Math.round(bar.getBoundingClientRect().height
                    + body[4].getBoundingClientRect().bottom - head.getBoundingClientRect().top);
            })(),
            // Scroll the panel to the end and see whether the head stayed and the last row arrived.
            afterPanelScroll: await (async () => {
                if (!box || box.scrollHeight <= box.clientHeight) return null;
                const head = cells(':scope > thead > tr > th')[0];
                const rows = cells(':scope > tbody > tr');
                const last = rows[rows.length - 1];
                box.scrollTop = box.scrollHeight;
                await new Promise(r => setTimeout(r, 150));
                const pr = box.getBoundingClientRect();
                const hr = head.getBoundingClientRect();
                const lr = last.getBoundingClientRect();
                const out = {
                    headTopOffset: Math.round(hr.top - pr.top),
                    lastRowVisible: lr.top >= pr.top - 1 && lr.bottom <= pr.bottom + 1,
                    headColor: getComputedStyle(head).color,
                    headBg: getComputedStyle(head).backgroundColor,
                    barStillThere: !!bar && bar.getBoundingClientRect().height > 0,
                };
                box.scrollTop = 0;
                await new Promise(r => setTimeout(r, 80));
                return out;
            })(),
            computedFlags: cells(':scope > tbody .lo-history-use').filter(b => b.getAttribute('data-computed') === '1').length,
            useClasses: cells(':scope > tbody .lo-history-use').map(b => b.className).filter((v, i, a) => a.indexOf(v) === i),
            firstChange: (cells(':scope > tbody > tr')[0] || { textContent: '' }).textContent.replace(/\s+/g, ' ').trim(),
            whenTexts: cells(':scope > tbody > tr > td.lo-history-when').map(t => t.textContent.trim()),
            // Theme: the panel's own surface and the muted text on it, against the tokens.
            // The panel's OWN surface, and the heading it sits under -- they used to be the same
            // token, which read as one block rather than a box belonging to one line.
            panelBg: panel ? getComputedStyle(panel).backgroundColor : null,
            panelBorder: panel ? getComputedStyle(panel).borderTopWidth + ' ' + getComputedStyle(panel).borderTopColor : null,
            groupBg: (() => {
                const g = document.querySelector(args.wrap + ' tr.lo-group > td');
                return g ? getComputedStyle(g).backgroundColor : null;
            })(),
            tokenBorder: (() => {
                const probe = document.createElement('span');
                probe.style.color = cs.getPropertyValue('--c-border').trim();
                document.body.appendChild(probe);
                const out = getComputedStyle(probe).color;
                probe.remove();
                return out;
            })(),
            panelIndent: panel ? Math.round(panel.getBoundingClientRect().left
                - panelRow.querySelector('td').getBoundingClientRect().left) : null,
            nameColLeft: (() => {
                const th = document.querySelector(args.wrap + ' table.lo-table thead th.lo-name-col');
                if (!th) return null;
                return Math.round(th.getBoundingClientRect().left + (parseFloat(getComputedStyle(th).paddingLeft) || 0));
            })(),
            tableLeft: (() => {
                const t = document.querySelector(args.wrap + ' table.lo-table');
                return t ? Math.round(t.getBoundingClientRect().left) : null;
            })(),
            // The way out has to sit on the same x as the buttons in the column below it.
            panelRight: panel ? Math.round(panel.getBoundingClientRect().right) : null,
            panelPadRight: panel ? Math.round(parseFloat(getComputedStyle(panel).paddingRight) || 0)
                + Math.round(parseFloat(getComputedStyle(panel).borderRightWidth) || 0) : null,
            closeRight: (() => {
                const b = panel ? panel.querySelector('.lo-history-close') : null;
                return b ? Math.round(b.getBoundingClientRect().right) : null;
            })(),
            lastThRight: (() => {
                const ths = cells(':scope > thead > tr > th');
                if (!ths.length) return null;
                const th = ths[ths.length - 1];
                return Math.round(th.getBoundingClientRect().right - (parseFloat(getComputedStyle(th).paddingRight) || 0));
            })(),
            // The LIST's own button, not the title bar's: `panel.querySelector('tbody ...')` would
            // match the bar's too, because the panel itself sits inside the slip's <tbody>.
            useRight: (() => {
                const b = cells(':scope > tbody .lo-history-use')[0];
                return b ? Math.round(b.getBoundingClientRect().right) : null;
            })(),
            headOnPanel: (() => {
                const th = table ? table.querySelector('thead th') : null;
                if (!th) return null;
                return { color: getComputedStyle(th).color, bg: getComputedStyle(th).backgroundColor };
            })(),
            tokenBgSubtle: toRgb(cs.getPropertyValue('--c-bg-subtle').trim()),
            tokenTextMuted: toRgb(cs.getPropertyValue('--c-text-muted').trim()),
            headColor: cells(':scope > thead > tr > th').length ? getComputedStyle(cells(':scope > thead > tr > th')[0]).color : null,
            currentColor: cells(':scope > tbody .lo-history-current').length
                ? getComputedStyle(cells(':scope > tbody .lo-history-current')[0]).color : null,
            // The slip around it must not have moved.
            totalsOutsideScroller: (() => {
                const block = document.querySelector(args.wrap + ' .lo-totals');
                return !!block && !block.closest('.table-responsive');
            })(),
        };
    }, { wrap: WRAP, code });
}
// WCAG relative luminance, so "the pinned head is readable on its own background" is a number.
function contrastOf(fg, bg) {
    const parse = (c) => (c.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
    const lum = (rgb) => {
        const [r, g, b] = rgb.map((v) => {
            const x = v / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const a = lum(parse(fg));
    const b = lum(parse(bg));
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
async function tableHeight(page) {
    return page.evaluate((wrap) => {
        const t = document.querySelector(wrap + ' table.lo-table');
        return t ? Math.round(t.getBoundingClientRect().height) : 0;
    }, WRAP);
}
/* What the ENDPOINT says about one line, worked out the way the page has to: the newest entry whose
   figure equals the live one is the current one, every other entry repeating it can do nothing, and
   the rest are real changes. Read straight off api/payroll-run.line-history so the page's own render
   is compared against the data rather than against itself. */
/* The figure the engine produced for this line, as the ENDPOINT's own trail records it: the
   `old_value` of the oldest amount entry, which is what the title bar must be showing. */
async function computedFromEndpoint(page, code) {
    return page.evaluate(async (args) => {
        const url = BASE_URL + '/api/payroll-run.line-history?run_id=' + PAYROLL_RUN_ID
            + '&employee_id=' + args.employeeId + '&line_type=earning_deduction&item_code=' + encodeURIComponent(args.code);
        const json = await (await fetch(url, { credentials: 'same-origin' })).json();
        const rows = ((json.data && json.data.rows) || []).filter(r => r.source_type !== 'manual_line'
            && !(r.new_text !== null && r.new_text !== undefined && r.new_text !== ''));
        const line = (typeof lineOverrideRowsRd !== 'undefined' ? lineOverrideRowsRd : [])
            .find(l => String(l.code) === args.code && (l.line_type || 'earning_deduction') === 'earning_deduction');
        // The persisted engine figure wins where the run has one; the trail is the older stand-in.
        const raw = (line && line.computed_amount !== null && line.computed_amount !== undefined)
            ? line.computed_amount
            : (rows.length ? rows[rows.length - 1].old_value : null);
        return raw === null || raw === undefined ? '' : Number(raw).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }, { employeeId: Number(employeeId), code });
}
async function expectedFromEndpoint(page, code) {
    return page.evaluate(async (args) => {
        const url = BASE_URL + '/api/payroll-run.line-history?run_id=' + PAYROLL_RUN_ID
            + '&employee_id=' + args.employeeId + '&line_type=earning_deduction&item_code=' + encodeURIComponent(args.code);
        const json = await (await fetch(url, { credentials: 'same-origin' })).json();
        const rows = ((json.data && json.data.rows) || []).filter(r => r.source_type !== 'manual_line');
        const line = (typeof lineOverrideRowsRd !== 'undefined' ? lineOverrideRowsRd : [])
            .find(l => String(l.code) === args.code && (l.line_type || 'earning_deduction') === 'earning_deduction');
        const current = line ? Number(line.current_amount) : NaN;
        let seen = false;
        let enabled = 0;
        let noop = 0;
        rows.forEach((r) => {
            const same = !isNaN(current) && !isNaN(Number(r.new_value)) && Math.abs(Number(r.new_value) - current) < 0.005;
            if (same && !seen) { seen = true; return; }
            if (same) { noop++; return; }
            enabled++;
        });
        return { total: rows.length, enabled: enabled, noop: noop };
    }, { employeeId: Number(employeeId), code });
}
// How far the slip itself scrolls sideways right now -- the baseline an opened panel must not move.
async function measureOverflow(page) {
    return page.evaluate((wrap) => {
        const sc = document.querySelector(wrap + ' .table-responsive');
        return sc ? sc.scrollWidth - sc.clientWidth : -1;
    }, WRAP);
}
async function closeHistory(page, code) {
    return page.evaluate((args) => {
        const row = document.querySelector(args.wrap + ' tr.lo-row[data-item-code="' + args.code + '"]');
        const badge = row ? row.querySelector('.lo-history-toggle') : null;
        if (badge) badge.click();
        const after = row ? row.nextElementSibling : null;
        return {
            expanded: badge ? badge.getAttribute('aria-expanded') : null,
            stillOpen: !!after && after.classList.contains('lo-history-row'),
        };
    }, { wrap: WRAP, code });
}

/* ---------------- h1: the table itself, 8 cells ---------------- */
const h1Themes = [];
async function h1(opts) {
    const label = `h1 ${opts.width} ${opts.lang} ${opts.colorScheme}`;
    if (!wanted(label)) return;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme, blockPaths: BLOCK_PATHS });
    const { page, report } = ctx;
    await gotoRun(ctx, runToken, opts.lang, opts.colorScheme);
    await openSlip(page, employeeId);

    const overflowBefore = await measureOverflow(page);
    const base = await openHistory(page, '__base_salary__');
    check(`${label}: the overridden line has a badge, and it is a real button`, base.badge === true && base.badgeIsButton === true, JSON.stringify(base.badge));
    check(`${label}: it is a disclosure -- aria-expanded, no caret, no icon`,
        base.badgeExpanded === 'true' && base.badgeHasCaret === false, `${base.badgeExpanded}/${base.badgeHasCaret}`);
    check(`${label}: no dropdown menu is left in the cell`, base.dropdowns === 0, String(base.dropdowns));
    check(`${label}: no history modal is left in the page`, base.historyModal === 0, String(base.historyModal));
    check(`${label}: the panel is the row's own next sibling, spanning every column`,
        base.panelIsSibling === true && base.colspan === base.rowCells, `${base.colspan} of ${base.rowCells}`);
    check(`${label}: 4 columns in the editable slip`, base.headCount === 4, base.headTexts.join(' | '));
    check(`${label}: the calculated value is in the title bar, exactly once`, base.computedRows === 1, String(base.computedRows));
    check(`${label}: ...and no longer a row of the list`, base.computedInList === 0, String(base.computedInList));
    check(`${label}: ...so the list opens on a real edit, a time and not a word`,
        /\d{2}:\d{2}/.test(base.firstRowWhen || ''), String(base.firstRowWhen));
    // The figure in the bar is the engine's own, read back off the endpoint rather than off itself.
    const computedExpected = await computedFromEndpoint(page, '__base_salary__');
    check(`${label}: ...and it is the figure the endpoint's own trail starts from`,
        base.computedValues.length === 1 && base.computedValues[0] === computedExpected,
        `${base.computedValues.join(',')} vs ${computedExpected}`);
    check(`${label}: the title bar sits on the panel's own surface`, base.barBg === base.panelBg,
        `${base.barBg} vs ${base.panelBg}`);
    check(`${label}: one entry is marked as the value in force`, base.currentTags === 1, String(base.currentTags));
    check(`${label}: every [use this value] in the panel is the same neutral button`,
        base.panelUseButtons >= 1 && base.panelUseClasses.length === 1
        && base.panelUseClasses[0].indexOf('btn-sm') !== -1 && base.panelUseClasses[0].indexOf('btn-outline-secondary') !== -1
        && base.panelUseClasses[0].indexOf('btn-primary') === -1 && base.panelUseClasses[0].indexOf('btn-outline-primary') === -1,
        base.panelUseClasses.join(' / '));
    check(`${label}: exactly one of them drops the override, and it is the title bar's`,
        base.panelComputedFlags === 1 && base.computedFlags === 0,
        `panel=${base.panelComputedFlags} list=${base.computedFlags}`);
    // Every row of the list is an edit now, so every "when" cell has to read as a moment in time.
    check(`${label}: every "when" cell carries a date AND a time`,
        base.whenTexts.length >= 1 && base.whenTexts.every(t => /\d/.test(t) && t.indexOf(':') !== -1),
        base.whenTexts.join(' | '));
    // The panel is what keeps the table readable on a phone: pinned, and the visible width.
    check(`${label}: the panel is pinned at the scroller's left edge`,
        base.panelPosition === 'sticky' && base.panelLeft === '0px', `${base.panelPosition}/${base.panelLeft}`);
    // What matters is that it is sized to what is VISIBLE, never to the scrolled content (which at
    // 430px is half as wide again). A scrollbar that appears while the panel opens can take up to a
    // scrollbar's width off the published figure, which is slack, not a failure.
    check(`${label}: ...and it takes the VISIBLE width, not the scrolled one`,
        base.scrollerWidth !== null
        && base.panelWidth <= base.scrollerWidth - base.panelIndent + 2
        && base.panelWidth >= base.scrollerWidth - base.panelIndent - 32,
        `panel=${base.panelWidth} scroller=${base.scrollerWidth} indent=${base.panelIndent}`);
    check(`${label}: ...so dragging the table sideways cannot carry it off screen`,
        base.panelPinned === true && base.panelLeftAfterScroll >= 0,
        `left=${base.panelLeftAfterScroll} after ${base.scrollAmount}px of ${base.overflowX} (indent=${base.panelIndent})`);
    check(`${label}: the 3 totals are still outside the scroller`, base.totalsOutsideScroller === true);
    // What the endpoint itself says, worked out the same way the page has to: the newest entry whose
    // figure equals the live one is "current", every other entry that repeats it can do nothing.
    const expect = await expectedFromEndpoint(page, '__base_salary__');
    check(`${label}: one pressable button per entry the endpoint says is a real change`,
        base.useEnabledEntries === expect.enabled,
        `dom=${base.useEnabledEntries} endpoint=${expect.enabled} of ${expect.total}`);
    check(`${label}: ...and the repeats of the live figure are disabled, not missing`,
        base.useDisabledEntries === expect.noop, `dom=${base.useDisabledEntries} endpoint=${expect.noop}`);
    check(`${label}: the title bar carries exactly one way out`, base.closeButtons === 1, String(base.closeButtons));
    check(`${label}: ...as the app's own 32px circle`,
        !!base.closeSize && base.closeSize[0] === 32 && base.closeSize[1] === 32, JSON.stringify(base.closeSize));
    // One worded action in the whole panel, and it is the one that repeats per entry.
    check(`${label}: nothing else in the panel is a worded button`,
        base.textButtons.length === 0, base.textButtons.join(' | '));
    // The panel hides its horizontal overflow, so nothing inside it may HAVE any -- least of all the
    // column the buttons live in, which is the last one and would be the first to be cut.
    check(`${label}: the 4 columns fit the panel, so nothing is clipped`,
        base.tableScrollW <= base.panelClientW + 1, `table=${base.tableScrollW} panel=${base.panelClientW}`);
    check(`${label}: ...and the buttons end inside it, not past its edge`,
        base.useCellRight !== null && base.useCellRight >= 0, String(base.useCellRight));
    const closedByBtn = await page.evaluate((args) => {
        const row = document.querySelector(args.wrap + ' tr.lo-row[data-item-code="__base_salary__"]');
        const btn = row.nextElementSibling.querySelector('.lo-history-close');
        btn.focus();
        btn.click();
        const badge = row.querySelector('.lo-history-toggle');
        return {
            collapsed: !row.nextElementSibling || !row.nextElementSibling.classList.contains('lo-history-row'),
            expanded: badge.getAttribute('aria-expanded'),
            focused: document.activeElement === badge,
        };
    }, { wrap: WRAP });
    check(`${label}: [x] collapses the panel and says so`,
        closedByBtn.collapsed === true && closedByBtn.expanded === 'false', JSON.stringify(closedByBtn));
    check(`${label}: ...and hands the focus back to the badge that opened it`, closedByBtn.focused === true);
    await openHistory(page, '__base_salary__');
    // The slip's own 5 columns are wider than a 430px screen whatever is open -- that drag is the
    // table's, and predates this round. What must be zero is what the PANEL adds to it.
    check(`${label}: the open panel adds no horizontal overflow of its own`,
        base.overflowX === overflowBefore, `before=${overflowBefore} after=${base.overflowX}`);
    // Closing it again is the same control, and it leaves nothing behind.
    const closed = await closeHistory(page, '__base_salary__');
    check(`${label}: the same badge closes it`, closed.stillOpen === false && closed.expanded === 'false', JSON.stringify(closed));

    // The hand-added line: a trail, but never a calculated row.
    const manualCode = await page.evaluate((wrap) => {
        const row = document.querySelector(wrap + ' tr.lo-row.lo-row-manual[data-manual-line-id]');
        return row ? row.getAttribute('data-item-code') : null;
    }, WRAP);
    check(`${label}: the hand-added line is on screen`, !!manualCode, String(manualCode));
    if (manualCode) {
        const man = await openHistory(page, manualCode);
        check(`${label}: it has a badge of its own now`, man.badge === true);
        check(`${label}: ...with no calculated row -- nothing calculated it`, man.computedRows === 0, String(man.computedRows));
        check(`${label}: ...and its live figure is the one in force`, man.currentTags === 1, String(man.currentTags));
        check(`${label}: ...with an empty left side in its title bar`, man.computedRows === 0, String(man.computedRows));
        await closeHistory(page, manualCode);
    }

    // The tri-state answer: words, not figures, and its calculated row applies 'inherit'.
    const pit = await openHistory(page, 'TH_PIT');
    check(`${label}: TH_PIT has a history of its own`, pit.badge === true, JSON.stringify(pit.found));
    if (pit.badge) {
        check(`${label}: at least one entry, and it is an answer, not a figure`,
            pit.bodyRows >= 1 && /[A-Za-z฀-๿]/.test(pit.firstChange), pit.firstChange);
        check(`${label}: its title bar offers the third value, 'inherit'`,
            pit.useKinds.indexOf('text') !== -1, pit.useKinds.join(','));
        check(`${label}: ...and no amount baseline is invented beside it`,
            pit.computedRows === 1 && pit.computedInList === 0, `${pit.computedRows}/${pit.computedInList}`);
        await closeHistory(page, 'TH_PIT');
    }

    h1Themes.push({ label, colorScheme: opts.colorScheme, width: opts.width, lang: opts.lang,
        panelBg: base.panelBg, tokenBgSubtle: base.tokenBgSubtle, headColor: base.headColor,
        tokenTextMuted: base.tokenTextMuted, heads: base.headTexts.join(' | '),
        headContrast: contrastOf(base.headOnPanel.color, base.headOnPanel.bg) });
    // 2026-09-19: the panel used to paint the very token the group headings use, so an opened panel
    // and the heading above it read as one block instead of a box belonging to one line.
    check(`${label}: the panel does not wear the group heading's own surface`,
        base.panelBg !== base.groupBg && base.groupBg === base.tokenBgSubtle,
        `panel=${base.panelBg} group=${base.groupBg}`);
    check(`${label}: ...it is a box of its own, with a 1px border on the border token`,
        base.panelBorder === '1px ' + base.tokenBorder, `${base.panelBorder} vs ${base.tokenBorder}`);
    check(`${label}: the column titles are the muted token`, base.headColor === base.tokenTextMuted,
        `${base.headColor} vs ${base.tokenTextMuted}`);
    // Quiet by their COLOUR, not by a second muted band behind them.
    check(`${label}: ...on the panel's own surface, not a muted band`,
        !!base.headOnPanel && base.headOnPanel.bg === base.panelBg && base.headOnPanel.bg !== base.tokenBgSubtle,
        JSON.stringify(base.headOnPanel));
    const headContrast = contrastOf(base.headOnPanel.color, base.headOnPanel.bg);
    check(`${label}: ...and readable on it (>= 4.5:1)`, headContrast >= 4.5,
        `${headContrast.toFixed(2)}:1 (${base.headOnPanel.color} on ${base.headOnPanel.bg})`);
    // The panel belongs to one line, and starts where that line's own name starts.
    check(`${label}: the first column starts on the item name's own x`,
        base.nameColLeft !== null && Math.abs((base.tableLeft + base.panelIndent) - base.nameColLeft) <= 2,
        `panel=${base.tableLeft + base.panelIndent} name=${base.nameColLeft}`);
    // The way out sits on the same x as the buttons it is above.
    check(`${label}: [x] ends on the same x as the [use this value] column`,
        base.closeRight !== null && base.lastThRight !== null
        && Math.abs(base.closeRight - base.lastThRight) <= 1
        && (base.useRight === null || Math.abs(base.closeRight - base.useRight) <= 1),
        `close=${base.closeRight} column=${base.lastThRight} use=${base.useRight}`);

    // The geometry this cell exists to measure, printed whether it passes or not.
    console.log(`  MEASURED ${label}: close.right=${base.closeRight} use.right=${base.useRight}`
        + ` | panelBg=${base.panelBg} groupBg=${base.groupBg}`
        + ` | head ${contrastOf(base.headOnPanel.color, base.headOnPanel.bg).toFixed(2)}:1`
        + ` | indent=${base.panelIndent} (name x=${base.nameColLeft - base.tableLeft})`
        + ` | panel=${base.panelWidth} of ${base.scrollerWidth}`);
    const r = report();
    check(`${label}: nothing was written`, r.blockedWrites === 0 && r.blockedRecalculates === 0,
        `writes=${r.blockedWrites} recalc=${r.blockedRecalculates}`);
    check(`${label}: no console errors`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors)));
    await closeSlip(page);
    await closeAll();
}

/* ---------------- h2: the read-only slip's marks ---------------- */
async function h2(opts) {
    const label = `h2 ${opts.width} ${opts.lang} ${opts.colorScheme}`;
    if (!wanted(label)) return;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme, blockPaths: BLOCK_PATHS });
    const { page, report } = ctx;
    await gotoRun(ctx, runToken, opts.lang, opts.colorScheme);
    const verified = await post(page, 'payroll-run.employee-verify.save', { employee_id: Number(employeeId), verified: true });
    check(`${label}: the employee could be verified (this is how the read-only slip is reached)`,
        verified && verified.status === true, JSON.stringify(verified));
    await gotoRun(ctx, runToken, opts.lang, opts.colorScheme);
    await openSlip(page, employeeId);

    const marks = await page.evaluate((args) => {
        const rows = Array.from(document.querySelectorAll(args.wrap + ' tr.lo-row'));
        const tagsOf = (row) => Array.from(row.querySelectorAll('.payslip-line-tag')).map(t => t.textContent.trim());
        const edited = rows.find(r => r.getAttribute('data-item-code') === '__base_salary__');
        const manual = rows.find(r => r.classList.contains('lo-row-manual'));
        const plain = rows.find(r => !r.classList.contains('lo-row-manual')
            && r.getAttribute('data-item-code') !== '__base_salary__'
            && !r.getAttribute('data-orig-action')
            && !r.getAttribute('data-exemption-changed'));
        return {
            useCols: document.querySelectorAll(args.wrap + ' .lo-history-use-cell').length,
            badges: document.querySelectorAll(args.wrap + ' .lo-history-toggle').length,
            editedTags: edited ? tagsOf(edited) : null,
            manualTags: manual ? tagsOf(manual) : null,
            plainTags: plain ? tagsOf(plain) : null,
            tagFontSizes: Array.from(document.querySelectorAll(args.wrap + ' .payslip-line-tag'))
                .map(t => getComputedStyle(t).fontSize).filter((v, i, a) => a.indexOf(v) === i),
        };
    }, { wrap: WRAP });
    check(`${label}: the read-only slip still shows the badges`, marks.badges >= 1, String(marks.badges));
    check(`${label}: an edited line and a hand-added one carry DIFFERENT words`,
        !!marks.editedTags && !!marks.manualTags
        && marks.editedTags[marks.editedTags.length - 1] !== marks.manualTags[marks.manualTags.length - 1],
        `${JSON.stringify(marks.editedTags)} vs ${JSON.stringify(marks.manualTags)}`);
    check(`${label}: an untouched line carries no such mark`,
        !marks.plainTags || marks.plainTags.length === 0
        || (marks.plainTags[marks.plainTags.length - 1] !== marks.editedTags[marks.editedTags.length - 1]
            && marks.plainTags[marks.plainTags.length - 1] !== marks.manualTags[marks.manualTags.length - 1]),
        JSON.stringify(marks.plainTags));
    check(`${label}: every tag in the slip is the same size -- one tag class, no exceptions`,
        marks.tagFontSizes.length === 1, marks.tagFontSizes.join(','));

    const base = await openHistory(page, '__base_salary__');
    check(`${label}: the read-only history has 3 columns, not 4`, base.headCount === 3, base.headTexts.join(' | '));
    check(`${label}: ...and offers no way to write at all`,
        base.useButtons === 0 && base.currentTags === 0, `${base.useButtons}/${base.currentTags}`);
    // As many rows as the badge counts -- the calculated value is above the list, not in it.
    const viewCount = Number((base.badgeText.match(/\d+/) || [0])[0]);
    check(`${label}: it still lists every entry the badge counts`,
        base.bodyRows === viewCount, `${base.bodyRows} rows for "${base.badgeText}"`);
    check(`${label}: ...with the calculated value above it, not inside it`,
        base.computedRows === 1 && base.computedInList === 0, `${base.computedRows}/${base.computedInList}`);
    check(`${label}: the read-only slip has the same way out`, base.closeButtons === 1, String(base.closeButtons));
    // No buttons column here to line up with, so it ends on the panel's own right edge instead.
    check(`${label}: ...and it ends on the last column's right edge, not mid-table`,
        base.closeRight !== null && base.useRight === null
        && Math.abs(base.closeRight - base.lastThRight) <= 1,
        `close=${base.closeRight} lastTh=${base.lastThRight}`);
    check(`${label}: ...and the title bar states the baseline without offering it`,
        base.computedRows === 1 && base.textButtons.length === 0,
        `${base.computedRows} / ${base.textButtons.join(' | ')}`);
    check(`${label}: the panel is pinned here too`, base.panelPosition === 'sticky' && base.panelPinned === true,
        `${base.panelPosition}/left=${base.panelLeftAfterScroll} after ${base.scrollAmount}px`);
    await closeSlip(page);

    const restored = await post(page, 'payroll-run.employee-verify.save', { employee_id: Number(employeeId), verified: false });
    check(`${label}: the employee is put back unverified`, restored && restored.status === true, JSON.stringify(restored));
    const r = report();
    check(`${label}: nothing this round forbids was written`, r.blockedWrites === 0, String(r.blockedWrites));
    check(`${label}: no console errors`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors)));
    await closeAll();
}

/* ---------------- h3: a 38-entry chain on a real old run, READ ONLY ---------------- */
async function h3(opts) {
    const label = `h3 ${opts.width} ${opts.lang} ${opts.colorScheme}`;
    if (!wanted(label)) return;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme, blockPaths: BLOCK_PATHS });
    const { page, report } = ctx;
    await gotoRun(ctx, OLD_RUN_TOKEN, opts.lang, opts.colorScheme);
    await openSlip(page, OLD_EMPLOYEE_ID);
    const overflowBefore = await measureOverflow(page);
    const tableBefore = await tableHeight(page);
    const long = await openHistory(page, OLD_ITEM_CODE);
    const tableGrowth = (await tableHeight(page)) - tableBefore;
    check(`${label}: the old line's badge is there`, long.badge === true, JSON.stringify(long.found));
    // The regression that cannot be seen on screen: a list of 5 rows looks the same whether the line
    // has 5 edits or 38. The badge says the number, and the table has to render that many.
    const badgeNumber = Number((long.badgeText.match(/\d+/) || [0])[0]);
    check(`${label}: the badge names a real count`, badgeNumber >= 38, long.badgeText);
    check(`${label}: every entry is rendered -- the list is never cut to the first N`,
        long.bodyRows === badgeNumber,
        `${long.bodyRows} rendered rows for a badge of ${badgeNumber} plus ${long.computedRows} calculated`);
    check(`${label}: still exactly one baseline, in the title bar`,
        long.computedRows === 1 && long.computedInList === 0, `${long.computedRows}/${long.computedInList}`);
    // The word sits on whichever of the two IS the live figure -- an entry, or the baseline above
    // them when nothing has replaced it. Exactly one of them, either way.
    check(`${label}: still exactly one thing marked as the value in force`,
        long.panelCurrentTags === 1, `${long.panelCurrentTags} (list=${long.currentTags})`);
    check(`${label}: the panel is pinned, even this tall`,
        long.panelPosition === 'sticky' && long.panelPinned === true,
        `${long.panelPosition}/left=${long.panelLeftAfterScroll} after ${long.scrollAmount}px`);
    check(`${label}: ...and it still takes the visible width, not the scrolled one`,
        long.panelWidth <= long.scrollerWidth - long.panelIndent + 2
        && long.panelWidth >= long.scrollerWidth - long.panelIndent - 32,
        `panel=${long.panelWidth} scroller=${long.scrollerWidth} indent=${long.panelIndent}`);
    check(`${label}: the 4 columns fit the panel here too`,
        long.tableScrollW <= long.panelClientW + 1, `table=${long.tableScrollW} panel=${long.panelClientW}`);
    check(`${label}: 38 rows open add nothing to the slip's own sideways drag`,
        long.overflowX === overflowBefore, `before=${overflowBefore} after=${long.overflowX}`);
    // The cap: the head plus 6 entries, measured off the rows that are really there.
    check(`${label}: the panel is its title bar plus the column titles plus 5 entries`,
        long.expectedPanelHeight !== null && Math.abs(long.panelHeight - long.expectedPanelHeight) <= 2,
        `panel=${long.panelHeight} expected=${long.expectedPanelHeight} (bar=${long.barHeight}) max=${long.panelMaxH}`);
    check(`${label}: ...and the rest is reachable by scrolling the list inside it`,
        long.panelScrollH > long.boxHeight, `scrollH=${long.panelScrollH} box=${long.boxHeight}`);
    check(`${label}: ...with the title bar still there when it does`,
        !!long.afterPanelScroll && long.afterPanelScroll.barStillThere === true);
    check(`${label}: scrolling to the end really reaches the last entry`,
        !!long.afterPanelScroll && long.afterPanelScroll.lastRowVisible === true,
        JSON.stringify(long.afterPanelScroll));
    check(`${label}: ...and the column titles are still on top when it gets there`,
        long.headPosition === 'sticky' && !!long.afterPanelScroll
        && Math.abs(long.afterPanelScroll.headTopOffset) <= 1,
        `${long.headPosition} / ${long.afterPanelScroll && long.afterPanelScroll.headTopOffset}`);
    check(`${label}: the pinned head is opaque, never see-through`,
        !!long.afterPanelScroll && long.afterPanelScroll.headBg.indexOf('rgba(0, 0, 0, 0)') === -1,
        long.afterPanelScroll && long.afterPanelScroll.headBg);
    const contrast = contrastOf(long.afterPanelScroll.headColor, long.afterPanelScroll.headBg);
    check(`${label}: ...and readable on it (>= 4.5:1)`, contrast >= 4.5,
        `${contrast.toFixed(2)}:1 (${long.afterPanelScroll.headColor} on ${long.afterPanelScroll.headBg})`);
    // A 38-entry chain may not push the slip's own table down by more than the panel it opened.
    // Plus the row's own box (its border and the cell's line box) -- what must NOT happen is the
    // table growing by the whole 38-entry list, which is what the cap is for.
    check(`${label}: the slip's table grows by no more than the panel itself`,
        tableGrowth <= long.panelHeight + 16, `growth=${tableGrowth} panel=${long.panelHeight}`);
    check(`${label}: ...which is far less than the uncapped list would have taken`,
        tableGrowth < long.panelScrollH, `growth=${tableGrowth} full=${long.panelScrollH}`);
    await closeSlip(page);
    const r = report();
    check(`${label}: this cell wrote NOTHING to run 752`, r.blockedWrites === 0, String(r.blockedWrites));
    // Opening a draft run makes the page ask for a recalculate of its own accord. It never reached
    // the server: the harness aborts that path for every cell here, which is exactly why this round
    // can read a real old run at all.
    check(`${label}: ...and the recalculate it asked for was stopped, not performed`,
        r.blockedRecalculates >= 1, String(r.blockedRecalculates));
    check(`${label}: no console errors`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors)));
    await closeAll();
}

/* ---------------- h4: pressing [ใช้ค่านี้] really writes, and is really stopped ----------------
   Two picks, one per KIND, because the whole point of the dispatcher is that the entry decides where
   the write goes -- not the place it was clicked, of which there is now only one. The fixture's own
   override is already the value in force (so it carries the word, not a button); what is pickable on
   it is the calculated row, which means "drop the override". */
async function h4(pick) {
    const label = `h4 ${pick.name}`;
    if (!wanted(label)) return;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light', blockPaths: BLOCK_PATHS });
    const { page, report } = ctx;
    await gotoRun(ctx, runToken, 'th', 'light');
    await openSlip(page, employeeId);
    await openHistory(page, pick.code);
    // A baseline's button lives in the title bar now, an entry's in its own row -- one selector
    // covers both, because both are inside the opened panel.
    const picked = await page.evaluate((args) => {
        const btns = Array.from(document.querySelectorAll(args.wrap + ' tr.lo-history-row .lo-history-use'))
            .filter(b => b.getAttribute('data-kind') === args.kind
                && (args.computed ? b.getAttribute('data-computed') === '1' : b.getAttribute('data-computed') !== '1'));
        if (!btns.length) return null;
        btns[0].click();
        return { kind: btns[0].getAttribute('data-kind'), value: btns[0].getAttribute('data-value'), label: btns[0].getAttribute('data-label') };
    }, { wrap: WRAP, kind: pick.kind, computed: pick.computed });
    check(`${label}: there is such an entry to pick`, !!picked, JSON.stringify(picked));
    await page.waitForTimeout(700);
    const asked = await page.evaluate(() => {
        const box = document.querySelector('.swal2-popup');
        const btn = document.querySelector('.swal2-confirm');
        const text = box ? box.textContent.replace(/\s+/g, ' ').trim() : '';
        if (btn) btn.click();
        return { shown: !!box, text };
    });
    check(`${label}: it asked before writing anything`, asked.shown === true, asked.text);
    check(`${label}: ...and the question names the value being applied`,
        !!picked && asked.text.indexOf(picked.label) !== -1, `${asked.text} :: ${picked && picked.label}`);
    await page.waitForTimeout(1800);
    const r = report();
    check(`${label}: exactly one write was attempted`, r.blockedWrites === 1, String(r.blockedWrites));
    check(`${label}: ...and it went to the endpoint this kind of entry claims`,
        r.blockedWritePaths.length === 1 && r.blockedWritePaths[0] === pick.endpoint,
        `${r.blockedWritePaths.join(',')} (wanted ${pick.endpoint})`);
    check(`${label}: no console errors beyond the blocked request itself`,
        r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors)));
    await closeAll();
}

/* ---------------- h4c / h4d: the 2 writes the fixture cannot offer without a second edit ---------
   The fixture's override and its hand-added line each have exactly ONE recorded entry, and that
   entry is the value in force -- so neither has a pressable "use this value" that is not the
   calculated row. Each cell therefore makes one real edit first, through the app's own endpoint on
   the fixture run (swept by `mksession.php --cleanup` like everything else it creates), which turns
   the original entry into a past one. */
async function seedWrite(fn) {
    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light' });
    await gotoRun(ctx, runToken, 'th', 'light');
    await openSlip(ctx.page, employeeId);
    const out = await fn(ctx.page);
    await closeAll();
    return out;
}
// Every request the page makes to a blocked path, with the body it would have sent.
function captureWrites(page) {
    const seen = [];
    page.on('request', (req) => {
        const url = req.url();
        const hit = BLOCK_PATHS.find(p => url.indexOf(p) !== -1);
        if (!hit || req.method() !== 'POST') return;
        let body = null;
        try { body = JSON.parse(req.postData() || 'null'); } catch (e) { body = req.postData(); }
        seen.push({ path: hit, body });
    });
    return seen;
}
async function h4c() {
    const label = 'h4c amount entry -> line-override.save';
    if (!wanted(label)) return;
    console.log(`\n=== ${label} ===`);
    const seeded = await seedWrite(async (page) => {
        const res = await post(page, 'payroll-run.line-override.save', {
            employee_id: Number(employeeId), item_code: '__base_salary__',
            action: 'override_amount', override_amount: 27000,
        });
        return { ok: !!(res && res.status), message: res && res.message };
    });
    check(`${label}: the extra edit the fixture needed landed`, seeded.ok === true, JSON.stringify(seeded));

    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light', blockPaths: BLOCK_PATHS });
    const { page, report } = ctx;
    const writes = captureWrites(page);
    await gotoRun(ctx, runToken, 'th', 'light');
    await openSlip(page, employeeId);
    await openHistory(page, '__base_salary__');
    // The entry the seed pushed into the past: a figure, not the calculated one, not the live one.
    const picked = await page.evaluate((wrap) => {
        const btns = Array.from(document.querySelectorAll(wrap + ' tr.lo-history-row .lo-history-use'))
            .filter(b => b.getAttribute('data-kind') === 'amount' && b.getAttribute('data-computed') !== '1' && !b.disabled);
        if (!btns.length) return null;
        btns[0].click();
        return { label: btns[0].getAttribute('data-label'), value: btns[0].getAttribute('data-value') };
    }, WRAP);
    check(`${label}: there is a past figure to put back`, !!picked, JSON.stringify(picked));
    await page.waitForTimeout(700);
    await page.evaluate(() => { const b = document.querySelector('.swal2-confirm'); if (b) b.click(); });
    await page.waitForTimeout(1800);
    const r = report();
    check(`${label}: exactly one write was attempted`, r.blockedWrites === 1, String(r.blockedWrites));
    check(`${label}: ...to line-override.save`,
        r.blockedWritePaths.length === 1 && r.blockedWritePaths[0] === 'api/payroll-run.line-override.save',
        r.blockedWritePaths.join(','));
    // The figure comes off the REQUEST, not off an expectation typed here.
    const sent = writes.find(w => w.path === 'api/payroll-run.line-override.save');
    check(`${label}: ...carrying the figure that entry holds, and that item code`,
        !!sent && !!picked && Math.abs(Number(sent.body.override_amount) - Number(String(picked.value).replace(/,/g, ''))) < 0.005
        && sent.body.item_code === '__base_salary__' && sent.body.action === 'override_amount',
        JSON.stringify(sent && sent.body));
    check(`${label}: no console errors`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors)));
    await closeAll();
}
async function h4d() {
    const label = 'h4d hand-added entry -> update-manual-line';
    if (!wanted(label)) return;
    console.log(`\n=== ${label} ===`);
    const seeded = await seedWrite(async (page) => page.evaluate(async () => {
        const line = lineOverrideRowsRd.find(l => (l.line_type || '') === 'manual_line');
        if (!line) return { ok: false, why: 'no hand-added line on this run' };
        const raw = manualLineRawByIdRd[String(line.manual_line_id)];
        // The app's own path, so the seed is the same write the measured click will make.
        manualLineAmountOnlyUpdateRd(line, Number(raw.amount) + 265.5);
        await new Promise(r => setTimeout(r, 3000));
        return { ok: true, was: Number(raw.amount), code: line.code, id: line.manual_line_id,
            pedTypeId: raw.ped_type_id, note: raw.note };
    }));
    check(`${label}: the hand-added line could be edited once first`, seeded.ok === true, JSON.stringify(seeded));

    const ctx = await openContext({ sessionId, width: 1400, height: 950, colorScheme: 'light', blockPaths: BLOCK_PATHS });
    const { page, report } = ctx;
    const writes = captureWrites(page);
    await gotoRun(ctx, runToken, 'th', 'light');
    await openSlip(page, employeeId);
    const man = await openHistory(page, seeded.code);
    check(`${label}: it carries a badge of its own`, man.badge === true, JSON.stringify(man.found));
    check(`${label}: ...and its title bar's left side is empty, because nothing calculated it`,
        man.computedRows === 0 && man.computedValues.length === 0, String(man.computedRows));
    check(`${label}: ...but the way out is still there`, man.closeButtons === 1, String(man.closeButtons));
    // One more entry than it had, and every entry except the live one is pressable -- the count is
    // read off the panel rather than assumed, because a cell may run more than once on one fixture.
    check(`${label}: the seed added an entry, and every past one is pressable`,
        man.bodyRows >= 2 && man.useEnabledEntries === man.bodyRows - 1,
        `rows=${man.bodyRows} enabled=${man.useEnabledEntries}`);
    const picked = await page.evaluate((wrap) => {
        const btns = Array.from(document.querySelectorAll(wrap + ' tr.lo-history-row .lo-history-use')).filter(b => !b.disabled);
        if (!btns.length) return null;
        btns[0].click();
        return { value: btns[0].getAttribute('data-value') };
    }, WRAP);
    check(`${label}: there is a past figure to put back`, !!picked, JSON.stringify(picked));
    await page.waitForTimeout(700);
    await page.evaluate(() => { const b = document.querySelector('.swal2-confirm'); if (b) b.click(); });
    await page.waitForTimeout(1800);
    const r = report();
    check(`${label}: exactly one write was attempted`, r.blockedWrites === 1, String(r.blockedWrites));
    check(`${label}: ...to update-manual-line`,
        r.blockedWritePaths.length === 1 && r.blockedWritePaths[0] === 'api/payroll-run.update-manual-line',
        r.blockedWritePaths.join(','));
    const sent = writes.find(w => w.path === 'api/payroll-run.update-manual-line');
    check(`${label}: ...carrying that entry's own amount`,
        !!sent && Math.abs(Number(sent.body.amount) - seeded.was) < 0.005,
        JSON.stringify(sent && sent.body));
    // update-manual-line takes the WHOLE row: a field left out is a field cleared, not one kept.
    check(`${label}: ...and every other field exactly as the line already holds it`,
        !!sent && Number(sent.body.line_id) === Number(seeded.id)
        && Number(sent.body.ped_type_id || 0) === Number(seeded.pedTypeId || 0)
        && String(sent.body.note || '') === String(seeded.note || ''),
        JSON.stringify(sent && sent.body));
    check(`${label}: no console errors`, r.consoleErrors.length === 0 && r.pageErrors.length === 0,
        JSON.stringify(r.consoleErrors.concat(r.pageErrors)));
    await closeAll();
}

(async () => {
    for (const lang of ['th', 'en']) {
        for (const colorScheme of ['light', 'dark']) {
            for (const width of [1400, 430]) {
                await h1({ width, height: width === 430 ? 900 : 950, lang, colorScheme });
            }
        }
    }
    // The dark cells must really be dark -- the same token, resolved to a different colour.
    if (h1Themes.length >= 4) {
    const light = h1Themes.find(t => t.colorScheme === 'light' && t.width === 1400 && t.lang === 'th');
    const dark = h1Themes.find(t => t.colorScheme === 'dark' && t.width === 1400 && t.lang === 'th');
    console.log('\n=== theme + language, across cells ===');
    check('the panel really changes surface between the 2 themes',
        !!light && !!dark && light.panelBg !== dark.panelBg, `${light && light.panelBg} vs ${dark && dark.panelBg}`);
    check('...and so does the muted text on it',
        !!light && !!dark && light.headColor !== dark.headColor, `${light && light.headColor} vs ${dark && dark.headColor}`);
    check('the column titles stay readable in BOTH themes',
        h1Themes.every(t => t.headContrast >= 4.5),
        h1Themes.map(t => `${t.colorScheme}/${t.width}=${t.headContrast.toFixed(2)}`).join(' '));
    const th = h1Themes.find(t => t.lang === 'th' && t.width === 1400 && t.colorScheme === 'light');
    const en = h1Themes.find(t => t.lang === 'en' && t.width === 1400 && t.colorScheme === 'light');
    check('the 4 column titles are translated, not the same string twice',
        !!th && !!en && th.heads !== en.heads, `${th && th.heads} :: ${en && en.heads}`);
    }

    await h2({ width: 1400, height: 950, lang: 'th', colorScheme: 'light' });
    await h2({ width: 430, height: 900, lang: 'th', colorScheme: 'dark' });
    await h3({ width: 1400, height: 950, lang: 'th', colorScheme: 'light' });
    await h3({ width: 430, height: 900, lang: 'th', colorScheme: 'dark' });
    // The calculated figure -> drop the override. The tri-state answer -> the exemption endpoint,
    // which is a different table entirely. Same button, same confirm, 2 destinations.
    await h4({ name: 'calculated figure', code: '__base_salary__', kind: 'amount', computed: true,
        endpoint: 'api/payroll-run.line-override.remove' });
    await h4({ name: 'tri-state answer', code: 'TH_PIT', kind: 'text', computed: true,
        endpoint: 'api/payroll-run.save-employee-exemption' });
    // These 2 edit the fixture before they measure, so they run LAST -- everything above reads the
    // fixture in the state mksession.php left it in.
    await h4c();
    await h4d();

    console.log(`\n--------------------------------------------------`);
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed > 0 ? 1 : 0);
})().catch(async (err) => {
    console.error(err);
    await closeAll();
    process.exit(1);
});
