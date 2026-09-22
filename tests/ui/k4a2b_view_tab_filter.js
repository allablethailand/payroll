/**
 * 4a-2b measurement: the read-only slip's 2 tabs -- "รายละเอียด" / "รายการที่แก้ไข (n)".
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/k4a2b_view_tab_filter.js <PHPSESSID> <runToken> <employeeId>
 *
 * The fixture (tests/ui/mksession.php) already gives its first employee exactly one hand-added line
 * and one overridden line, so n = 2 with nothing seeded here -- and every OTHER employee on the same
 * run has neither, which is the n = 0 case. Nothing is created or deleted by this round; the only
 * state it touches is the per-employee verify flag (the app's own way into the read-only slip), and
 * both employees are put back unverified before the cell ends.
 *
 * 8 cells (a layout round): th/en x light/dark x 1400/430.
 */
'use strict';
const { openContext, closeAll, ensureCellTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/k4a2b_view_tab_filter.js <PHPSESSID> <runToken> <employeeId>');
}

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

const WRAP = '#breakdownLineOverrideWrap';

// Everything this round measures, off the open modal in one pass.
async function measure(page) {
    return page.evaluate((wrap) => {
        const cs = getComputedStyle(document.documentElement);
        const toRgb = (value) => {
            const probe = document.createElement('span');
            probe.style.color = value;
            document.body.appendChild(probe);
            const out = getComputedStyle(probe).color;
            probe.remove();
            return out;
        };
        const ul = document.querySelector(wrap + ' ul.lo-tabs');
        const tabs = Array.from(document.querySelectorAll(wrap + ' ul.lo-tabs .nav-link'));
        const active = tabs.filter(t => t.classList.contains('active'));
        const idle = tabs.filter(t => !t.classList.contains('active'));
        const table = document.querySelector(wrap + ' table.lo-table');
        const rows = Array.from(document.querySelectorAll(wrap + ' tr.lo-row'));
        const groups = Array.from(document.querySelectorAll(wrap + ' tr.lo-group'));
        const scroller = document.querySelector(wrap + ' .table-responsive');
        // The x of the TEXT, not of the box: what has to line up is the first tab's own label and
        // the first column's own title, and each sits inside its own padding.
        const textX = (el) => {
            if (!el) return null;
            const r = document.createRange();
            r.selectNodeContents(el);
            const box = r.getBoundingClientRect();
            return box.width ? box.left : null;
        };
        const firstTh = table ? table.querySelector('thead th') : null;
        return {
            tabRows: document.querySelectorAll(wrap + ' ul.lo-tabs').length,
            tabCount: tabs.length,
            tabTexts: tabs.map(t => t.textContent.trim()),
            tabIsButton: tabs.every(t => t.tagName === 'BUTTON'),
            bsToggle: document.querySelectorAll(wrap + ' ul.lo-tabs [data-bs-toggle]').length,
            activeFilter: active.length ? active[0].getAttribute('data-lo-filter') : null,
            // (n) must be the muted grey token, and must not be a badge.
            countSpan: (() => {
                const s = document.querySelector(wrap + ' ul.lo-tabs .nav-link .text-muted');
                return s ? { text: s.textContent.trim(), color: getComputedStyle(s).color } : null;
            })(),
            badgeInTabs: document.querySelectorAll(wrap + ' ul.lo-tabs .badge').length,
            activeUnderline: active.length ? getComputedStyle(active[0]).borderBottomColor : null,
            idleColor: idle.length ? getComputedStyle(idle[0]).color : null,
            primary: toRgb(cs.getPropertyValue('--c-primary').trim()),
            textMuted: toRgb(cs.getPropertyValue('--c-text-muted').trim()),
            // Geometry: the label on the column title's x, and the whole row above the table.
            tabTextX: tabs.length ? textX(tabs[0]) : null,
            thTextX: textX(firstTh),
            ulBottom: ul ? ul.getBoundingClientRect().bottom : null,
            tableTop: table ? table.getBoundingClientRect().top : null,
            ulOverflow: ul ? ul.scrollWidth - ul.clientWidth : null,
            wrapWidth: document.querySelector(wrap) ? document.querySelector(wrap).clientWidth : null,
            ulWidth: ul ? ul.getBoundingClientRect().width : null,

            rowCount: rows.length,
            /* 2026-09-21, a0: the THIRD kind of change was missing. detail.js's own
               lineOverrideIsChangedRd() -- which is what the tab counts with -- is
               `override_action || manual_line || statutoryExemptionChangedRd`, and this counted only
               the first two. So the tab said 3 and this said 2, and the round called the tab wrong. */
            changedRows: rows.filter(r => r.className.indexOf('lo-row-manual') !== -1
                || (r.getAttribute('data-orig-action') || '') !== ''
                || !!r.getAttribute('data-exemption-changed')).length,
            // The 2 tri-state rows as the row declares them: the read-only slip drops one that has
            // nothing to say (0, untouched, no answer chosen) -- lineOverrideIsSkippedRd()'s `view`
            // branch -- so "the same rows in both slips" is asked of the rows that survive that.
            tristate: rows.filter(r => r.getAttribute('data-exemption-field')).map(r => ({
                code: r.getAttribute('data-item-code'),
                changed: !!r.getAttribute('data-exemption-changed'),
                overridden: !!r.getAttribute('data-orig-action'),
                amount: parseFloat(String(r.getAttribute('data-amount') || '0').replace(/,/g, '')) || 0,
            })),
            groupCount: groups.length,
            groupLabels: groups.map(g => g.textContent.trim()),
            // A group head with no data row after it must not be rendered at all.
            emptyGroups: groups.filter((g) => {
                let el = g.nextElementSibling;
                while (el && el.className.indexOf('lo-group') === -1) {
                    if (el.className.indexOf('lo-row') !== -1) return false;
                    el = el.nextElementSibling;
                }
                return true;
            }).length,
            totals: Array.from(document.querySelectorAll(wrap + ' .lo-totals-row'))
                .map(r => (r.querySelector('.num') || { textContent: '' }).textContent.trim()),
            // 2026-09-19, tiny-4b-fix1 v2: the totals are a block of their own AFTER the scroller --
            // "last" is now about the host, not about the table body.
            totalsIsLast: (() => {
                const host = document.querySelector(wrap);
                const block = document.querySelector(wrap + ' .lo-totals');
                return !!block && !!host && host.lastElementChild === block
                    && !block.closest('.table-responsive');
            })(),
            hidden: document.querySelectorAll(wrap + ' .d-none').length,
            stickyLeft2: table ? table.style.getPropertyValue('--lo-sticky-left-2') : '',
            overflowX: scroller ? scroller.scrollWidth - scroller.clientWidth : -1,
            // The editable slip's own controls, so the last cell can prove they are all still there.
            addLinks: document.querySelectorAll(wrap + ' .lo-add-line-btn').length,
            pencils: document.querySelectorAll(wrap + ' .lo-edit-btn').length,
            manualEditBtns: document.querySelectorAll(wrap + ' .manual-line-edit-btn').length,
            manualRemoveBtns: document.querySelectorAll(wrap + ' .manual-line-remove-btn').length,
        };
    }, WRAP);
}

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
    await page.waitForTimeout(500);
}
async function clickTab(page, filter) {
    await page.evaluate((args) => {
        document.querySelector(args.wrap + ' ul.lo-tabs .nav-link[data-lo-filter="' + args.filter + '"]').click();
    }, { wrap: WRAP, filter });
    await page.waitForTimeout(400);
}
async function post(page, endpoint, payload) {
    return page.evaluate(async (args) => {
        const res = await fetch(BASE_URL + '/api/' + args.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ id: PAYROLL_RUN_ID }, args.payload)),
        });
        return res.json();
    }, { endpoint, payload });
}
async function setVerified(page, id, verified) {
    return post(page, 'payroll-run.employee-verify.save', { employee_id: Number(id), verified });
}
/* 2026-09-21, a0: the theme is re-applied HERE, because a reload is exactly what loses it. The
   preference write-back is blocked (harness.js), so every load comes back at the `light` this
   session's employee is stamped with -- see ensureCellTheme()'s own note. Returns false when the
   theme did not take, and the caller must stop that cell rather than measure light colours under a
   dark label. */
async function reload(page, opts, label, when) {
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, opts.lang);
    await page.waitForTimeout(600);
    return ensureCellTheme(page, opts.colorScheme, { label, when, check, log: console.log });
}

async function runCell(opts) {
    const label = `${opts.width} ${opts.lang} ${opts.colorScheme}`;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme });
    const { page, report } = ctx;
    // Every request the page makes, so "switching tabs fetches nothing" is measured, not assumed.
    let apiRequests = 0;
    page.on('request', (req) => { if (req.url().indexOf('/api/') !== -1) apiRequests++; });

    await page.goto(ctx.url('/payroll-process/' + runToken), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, opts.lang);
    await page.waitForTimeout(600);
    if (!await ensureCellTheme(page, opts.colorScheme, { label, when: 'first load', check, log: console.log })) return report();
    await page.waitForSelector('.btn-view-breakdown[data-employee-id="' + employeeId + '"]', { state: 'attached', timeout: 30000 });

    // The other employee of this same run -- no hand-added line, no override, so n = 0 there.
    const otherId = await page.evaluate((mine) => {
        const ids = Array.from(document.querySelectorAll('.btn-view-breakdown'))
            .map(b => String(b.getAttribute('data-employee-id')))
            .filter(v => v && v !== String(mine));
        return ids.length ? ids[0] : null;
    }, employeeId);
    check(`${label}: the run has a second employee to measure n=0 on`, !!otherId, String(otherId));
    // A known state to start from, whatever an interrupted cell left behind.
    await setVerified(page, employeeId, false);
    if (otherId) await setVerified(page, otherId, false);
    // ...and the same 2 writes on the way out of a cell that has to give up half-way: both rows go
    // back unverified, so the next cell starts where this one was supposed to.
    const bailCell = async () => {
        await setVerified(page, employeeId, false);
        if (otherId) await setVerified(page, otherId, false);
        return report();
    };

    // --- the editable slip first, in the state the fixture is already in ---
    if (!await reload(page, opts, label, 'before the editable slip')) return bailCell();
    await openSlip(page, employeeId);
    const edit = await measure(page);
    await closeSlip(page);

    // --- n = 0: the other employee's read-only slip ---
    let zero = null;
    if (otherId) {
        await setVerified(page, otherId, true);
        if (!await reload(page, opts, label, 'before the n=0 slip')) return bailCell();
        await openSlip(page, otherId);
        zero = await measure(page);
        await closeSlip(page);
        await setVerified(page, otherId, false);
    }

    // --- n = 2: this employee's read-only slip ---
    const verifyRes = await setVerified(page, employeeId, true);
    check(`${label}: verify accepted`, verifyRes && verifyRes.status === true, JSON.stringify(verifyRes));
    if (!await reload(page, opts, label, 'before the read-only slip')) return bailCell();
    await openSlip(page, employeeId);
    const all = await measure(page);

    const beforeClick = { api: apiRequests, recalc: report().blockedRecalculates };
    await clickTab(page, 'changed');
    const changed = await measure(page);
    const afterClick = { api: apiRequests, recalc: report().blockedRecalculates };

    await clickTab(page, 'all');
    const back = await measure(page);

    // ...and the tab it is on does not survive a reopen.
    await clickTab(page, 'changed');
    await closeSlip(page);
    await openSlip(page, employeeId);
    const reopened = await measure(page);
    await closeSlip(page);

    // --- put the row back exactly as it was ---
    const restore = await setVerified(page, employeeId, false);
    check(`${label}: verify flag restored`, restore && restore.status === true, JSON.stringify(restore));

    const r = report();
    console.log(`  n=0 slip: tabRows=${zero ? zero.tabRows : 'n/a'} rows=${zero ? zero.rowCount : 'n/a'}`);
    console.log(`  n=2 slip: tabs=${all.tabCount} ${JSON.stringify(all.tabTexts)} rows=${all.rowCount} groups=${all.groupCount}`);
    console.log(`  filtered: rows=${changed.rowCount} groups=${changed.groupCount} empty=${changed.emptyGroups} totals=${JSON.stringify(changed.totals)}`);
    console.log(`  geometry: tabTextX-thTextX=${all.tabTextX !== null && all.thTextX !== null ? (all.tabTextX - all.thTextX).toFixed(2) : 'n/a'}`
        + ` ulBottom=${all.ulBottom && all.ulBottom.toFixed(1)} tableTop=${all.tableTop && all.tableTop.toFixed(1)}`
        + ` stickyLeft2=${all.stickyLeft2} ulOverflow=${all.ulOverflow}`);
    console.log(`  colors: underline=${all.activeUnderline} primary=${all.primary} idle=${all.idleColor} muted=${all.textMuted} count=${JSON.stringify(all.countSpan)}`);
    console.log(`  requests: api +${afterClick.api - beforeClick.api} recalc +${afterClick.recalc - beforeClick.recalc}`
        + ` | blockedPrefs=${r.blockedPreferenceSaves} blockedRecalc=${r.blockedRecalculates}`);
    console.log(`  edit slip: tabRows=${edit.tabRows} rows=${edit.rowCount} add=${edit.addLinks} pencils=${edit.pencils} manualEdit=${edit.manualEditBtns} manualRemove=${edit.manualRemoveBtns}`);

    // 1. nothing changed -> no tab row at all (absent, not hidden)
    if (zero) {
        check(`${label}: an employee with nothing changed gets no tab row at all`,
            zero.tabRows === 0 && zero.hidden === 0, `tabRows=${zero.tabRows} hidden=${zero.hidden}`);
        check(`${label}: ...and its table is unaffected`, zero.rowCount > 0 && zero.totals.length === 3,
            `rows=${zero.rowCount} totals=${zero.totals.length}`);
    }
    // 2. the tab row itself
    check(`${label}: 2 tabs, both real buttons, none driven by the Bootstrap tab plugin`,
        all.tabRows === 1 && all.tabCount === 2 && all.tabIsButton && all.bsToggle === 0,
        `${all.tabRows}/${all.tabCount}/${all.bsToggle}`);
    /* 2026-09-21, a0: the number is DERIVED, not pinned at 2. H-ui made a tri-state answer a
       recorded change like any other, so this fixture now carries 3 -- and a round that hard-codes
       the figure fails the day the fixture gains an edit instead of the day the tab starts lying.
       What must hold is that the 3 numbers agree: the bracketed count, the rows the filter leaves,
       and the rows that really are changed. */
    const tabCount = Number((String(all.tabTexts[1] || '').match(/\((\d+)\)\s*$/) || [])[1]);
    console.log(`  changed: tab=${tabCount} filteredRows=${changed.rowCount} reallyChanged=${changed.changedRows} (of ${all.rowCount})`);
    check(`${label}: the second tab carries the count in brackets`,
        Number.isFinite(tabCount) && tabCount > 0 && tabCount === all.changedRows,
        JSON.stringify({ tabs: all.tabTexts, changedRows: all.changedRows }));
    check(`${label}: the count is the muted grey token, and is not a badge`,
        !!all.countSpan && all.countSpan.color === all.textMuted && all.badgeInTabs === 0,
        `${all.countSpan && all.countSpan.color} vs ${all.textMuted}`);
    // 3. the open tab, and where the row sits
    check(`${label}: the first tab is open, underlined in primary`,
        all.activeFilter === 'all' && all.activeUnderline === all.primary,
        `${all.activeFilter} ${all.activeUnderline} vs ${all.primary}`);
    check(`${label}: the closed tab is muted`, all.idleColor === all.textMuted, `${all.idleColor} vs ${all.textMuted}`);
    check(`${label}: the first tab's label starts on the first column title's x`,
        all.tabTextX !== null && all.thTextX !== null && Math.abs(all.tabTextX - all.thTextX) <= 1,
        `${all.tabTextX} vs ${all.thTextX}`);
    check(`${label}: the tab row sits above the table, not over it`,
        all.ulBottom !== null && all.tableTop !== null && all.ulBottom <= all.tableTop + 0.5,
        `${all.ulBottom} / ${all.tableTop}`);
    // 4. the filter
    /* "fewer than all" only holds while the run still HAS an unchanged row -- and a fixture the
       write-rounds have been through may not (measured: 4 of 4 changed). What the filter owes is
       that it leaves exactly the changed ones, and that it drops a group head whose rows all went;
       both are asked against what is really there. */
    const hasUnchangedRow = all.changedRows < all.rowCount;
    if (!hasUnchangedRow) console.log(`  (every row of this run is changed -- the filter has nothing to drop)`);
    check(`${label}: the filtered table renders exactly the changed rows`,
        changed.rowCount === tabCount && changed.changedRows === tabCount
        && (hasUnchangedRow ? changed.rowCount < all.rowCount : changed.rowCount === all.rowCount),
        `${changed.rowCount}/${changed.changedRows} vs tab ${tabCount}, of ${all.rowCount}`);
    check(`${label}: ...and no group head is left standing over nothing`,
        changed.emptyGroups === 0 && changed.groupCount > 0
        && (hasUnchangedRow ? changed.groupCount < all.groupCount : changed.groupCount === all.groupCount),
        `${changed.groupCount} of ${all.groupCount}, empty=${changed.emptyGroups}`);
    check(`${label}: nothing is merely hidden`, changed.hidden === 0, String(changed.hidden));
    check(`${label}: the 3 totals are still the table's last rows, with the FULL figures`,
        changed.totals.length === 3 && changed.totalsIsLast
        && changed.totals.join('|') === all.totals.join('|'),
        `${JSON.stringify(changed.totals)} vs ${JSON.stringify(all.totals)}`);
    check(`${label}: switching tabs costs no request at all`,
        afterClick.api === beforeClick.api && afterClick.recalc === beforeClick.recalc,
        `api +${afterClick.api - beforeClick.api} recalc +${afterClick.recalc - beforeClick.recalc}`);
    // 5. back, and the reset
    check(`${label}: switching back renders the whole slip again`,
        back.rowCount === all.rowCount && back.groupCount === all.groupCount && back.activeFilter === 'all',
        `${back.rowCount}/${all.rowCount}`);
    check(`${label}: the tab does not survive a reopen`,
        reopened.activeFilter === 'all' && reopened.rowCount === all.rowCount,
        `${reopened.activeFilter} ${reopened.rowCount}/${all.rowCount}`);
    // 8. the editable slip has no tab row and lost none of its controls
    check(`${label}: the editable slip renders no tab row`, edit.tabRows === 0, String(edit.tabRows));
    check(`${label}: ...and keeps both "add a line" links and every row control`,
        edit.addLinks === 2 && edit.pencils > 0 && edit.manualEditBtns === 1 && edit.manualRemoveBtns === 1,
        `add=${edit.addLinks} pencils=${edit.pencils} mEdit=${edit.manualEditBtns} mRemove=${edit.manualRemoveBtns}`);
    // ...bar the tri-state rows the read-only slip has nothing to say about (see `tristate` above).
    const silentTriState = edit.tristate.filter(t => t.amount === 0 && !t.overridden && !t.changed).map(t => t.code);
    if (silentTriState.length) console.log(`  read-only drops (tri-state with nothing to say): ${silentTriState.join('|')}`);
    check(`${label}: the same rows in both slips, bar the silent tri-state ones`,
        edit.rowCount - silentTriState.length === all.rowCount,
        `${edit.rowCount} - ${silentTriState.length} vs ${all.rowCount}`);

    // 6. the phone
    if (opts.width === 430) {
        check(`${label}: the tab row does not scroll or overlap the table`,
            all.ulOverflow === 0 && all.ulWidth <= all.wrapWidth + 0.5,
            `overflow=${all.ulOverflow} ul=${all.ulWidth} wrap=${all.wrapWidth}`);
        check(`${label}: the pinned-column offset the read-only slip publishes is unchanged (4a-1)`,
            all.stickyLeft2 === '0px', all.stickyLeft2);
    }
    // 7. the language
    check(`${label}: both tab labels come from this language's own file`,
        all.tabTexts.length === 2 && all.tabTexts.every(t => t && t.length > 0 && t.indexOf('line_override_tab') === -1),
        JSON.stringify(all.tabTexts));

    check(`${label}: no page error`, r.pageErrors.length === 0 && r.consoleErrors.length === 0,
        JSON.stringify(r.pageErrors.concat(r.consoleErrors)).slice(0, 300));
    return { label, zero, all, changed, edit, report: r, tabTexts: all.tabTexts };
}

(async () => {
    const quick = process.argv.indexOf('--quick') !== -1;
    const matrix = quick
        ? [['th', 'light', 1400], ['th', 'dark', 430]]
        : [['th', 'light', 1400], ['th', 'light', 430], ['th', 'dark', 1400], ['th', 'dark', 430],
           ['en', 'light', 1400], ['en', 'light', 430], ['en', 'dark', 1400], ['en', 'dark', 430]];
    const reports = [];
    for (const [lang, colorScheme, width] of matrix) {
        reports.push(await runCell({ width, height: width === 430 ? 932 : 950, lang, colorScheme }));
    }
    await closeAll();

    console.log('\n================ round summary ================');
    reports.forEach((r) => {
        console.log(`${r.label.padEnd(18)} n0Tabs=${r.zero ? r.zero.tabRows : '-'} tabs=${JSON.stringify(r.tabTexts)} `
            + `rows=${r.all.rowCount}->${r.changed.rowCount} groups=${r.all.groupCount}->${r.changed.groupCount} `
            + `sticky=${r.all.stickyLeft2} blockedPrefs=${r.report.blockedPreferenceSaves} blockedRecalc=${r.report.blockedRecalculates}`);
    });
    console.log('-'.repeat(50));
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    if (failed > 0) { console.log('SOME TESTS FAILED'); process.exit(1); }
    console.log('ALL TESTS PASSED');
})();
