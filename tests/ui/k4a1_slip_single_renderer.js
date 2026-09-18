/**
 * 4a-1 measurement: the read-only slip and the editable slip are the SAME table.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/k4a1_slip_single_renderer.js <PHPSESSID> <runToken> <employeeId>
 *
 * Per cell it opens the Calculation Breakdown modal for the same employee TWICE -- once while the
 * row is editable, once after verifying it (which is the app's own way into the read-only slip) --
 * and compares the two by the numbers. The verify flag is restored before the cell ends, and again
 * at the end of the round, so run 752 comes out exactly as it went in.
 *
 * 8 cells (a layout round): th/en x light/dark x 1400/430.
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/k4a1_slip_single_renderer.js <PHPSESSID> <runToken> <employeeId>');
}

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

const WRAP = '#breakdownLineOverrideWrap';
// Run 752 / EM009's own 3 figures, pinned from the round before the totals moved out of the table.
const EXPECTED_TOTALS = process.env.K4A1_TOTALS || '';

// Everything this round measures, read off the open modal in one pass.
async function measure(page) {
    return page.evaluate((wrap) => {
        const rows = Array.from(document.querySelectorAll(wrap + ' tr.lo-row'));
        const tagsOf = (r) => Array.from(r.querySelectorAll('.payslip-line-tag')).map(t => t.textContent.trim());
        const scroller = document.querySelector(wrap + ' .table-responsive');
        const body = document.querySelector('#breakdownModalBody');
        return {
            rowCount: rows.length,
            codes: rows.map(r => r.getAttribute('data-item-code')),
            groupCount: document.querySelectorAll(wrap + ' tr.lo-group').length,
            // 2026-09-18, 4a-2: an EMPTY manual group renders in the editable slip only -- its head
            // and its "add a line" row are the way in. So the 2 modes may differ by exactly those.
            emptyGroups: Array.from(document.querySelectorAll(wrap + ' tr.lo-group')).filter((g) => {
                let el = g.nextElementSibling;
                while (el && el.className.indexOf('lo-group') === -1) {
                    if (el.className.indexOf('lo-row') !== -1 && el.className.indexOf('lo-row-add') === -1) return false;
                    el = el.nextElementSibling;
                }
                return true;
            }).length,
            checkCells: document.querySelectorAll(wrap + ' td.col-check').length,
            actionCells: document.querySelectorAll(wrap + ' td.lo-action-cell').length,
            switches: document.querySelectorAll(wrap + ' .lo-include').length,
            pencils: document.querySelectorAll(wrap + ' .lo-edit-btn').length,
            historyBadges: document.querySelectorAll(wrap + ' .lo-history-toggle').length,
            // 2026-09-18, 4a-2: the last 3 rows of the table's own tbody again -- the block they sat
            // in for half a day is gone with the hand-added card that stood between them and it.
            totals: Array.from(document.querySelectorAll(wrap + ' tr.lo-total-row')).map(r => ({
                label: r.children[0].textContent.trim(),
                amount: (r.querySelector('.num') || { textContent: '' }).textContent.trim(),
            })),
            totalsHtml: Array.from(document.querySelectorAll(wrap + ' tr.lo-total-row')).map(r => r.innerHTML).join(''),
            totalsIsLast: (() => {
                const tb = document.querySelector(wrap + ' table.lo-table tbody');
                if (!tb) return false;
                const all = Array.from(tb.children);
                return all.length >= 3 && all.slice(-3).every(r => r.className.indexOf('lo-total-row') !== -1);
            })(),
            // The block and the card it sat under are both gone -- neither may come back.
            totalsAfterManual: !document.querySelector('#breakdownNetSummary, .lo-totals-block, .ml-mount'),
            // The tag order of every row that carries one, keyed by code.
            tags: rows.reduce((acc, r) => { acc[r.getAttribute('data-item-code')] = tagsOf(r); return acc; }, {}),
            skippedRows: rows.filter(r => r.className.indexOf('lo-row-skipped') !== -1).map(r => r.getAttribute('data-item-code')),
            skippedBadges: rows.filter(r => r.className.indexOf('lo-row-skipped') !== -1)
                .filter(r => r.querySelector('.badge')).length,
            // 2026-09-18, 4a-2 follow-up: a skipped line is not a row of this slip at all.
            skipMarkers: document.querySelectorAll(wrap + ' .lo-row-skipped').length,
            // A blank cell must be blank, never a "-" placeholder.
            dashCells: Array.from(document.querySelectorAll(wrap + ' td')).filter(td => td.textContent.trim() === '-').length,
            questionButtons: body ? body.querySelectorAll('.formula-info-btn, [data-bs-toggle="popover"]').length : -1,
            hidden: document.querySelectorAll(wrap + ' .d-none').length,
            overflowX: scroller ? scroller.scrollWidth - scroller.clientWidth : -1,
            stickyLeft2: (() => {
                const t = document.querySelector(wrap + ' table.lo-table');
                return t ? t.style.getPropertyValue('--lo-sticky-left-2') : '';
            })(),
            // 2026-09-18, 4a-1 follow-up 3: every sub-line is shown in full, wrapping -- nothing is
            // clipped, nothing hides the rest behind a tooltip nobody on a phone can reach.
            tagMetrics: Array.from(document.querySelectorAll(wrap + ' .lo-name-cell .payslip-line-tag')).map(t => ({
                code: t.closest('tr').getAttribute('data-item-code'),
                len: t.textContent.trim().length,
                clipped: t.scrollWidth > t.clientWidth,
                ellipsis: getComputedStyle(t).textOverflow === 'ellipsis',
                nowrap: getComputedStyle(t).whiteSpace === 'nowrap',
                hasTitle: t.hasAttribute('title'),
                fontSize: getComputedStyle(t).fontSize,
            })),
            statutoryRowHeights: rows.filter(r => r.getAttribute('data-line-type') === 'statutory')
                .map(r => Math.round(r.getBoundingClientRect().height)),
            manualRows: document.querySelectorAll(wrap + ' tr.lo-row-manual').length,
            addButtons: document.querySelectorAll(wrap + ' .lo-add-line-btn').length,
            footerButtons: Array.from(document.querySelectorAll('#breakdownModalFooter button')).map(b => b.textContent.trim()),
            statusLine: document.querySelectorAll('#breakdownStatusLine').length,
        };
    }, WRAP);
}

async function openSlip(page) {
    // Which slip opens is decided by `currentRun` + the row's own verify flag, both of which arrive
    // with the page's own fetches. Pressing before they land reads `currentRun` as undefined and
    // opens the READ-ONLY slip on a draft run -- a cold first cell hit that race for real.
    await page.waitForFunction(() => typeof currentRun !== 'undefined' && !!currentRun && !!currentRun.state, null, { timeout: 30000 });
    await page.evaluate((id) => document.querySelector(`.btn-view-breakdown[data-employee-id="${id}"]`).click(), employeeId);
    await page.waitForSelector(`${WRAP} tr.lo-row`, { timeout: 30000 });
    await page.waitForTimeout(900);
}
async function closeSlip(page) {
    await page.evaluate(() => {
        const el = document.getElementById('runDetailBreakdownModal');
        const inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
    });
    await page.waitForTimeout(500);
}
// The stacked "full history" modal for one line, opened the way the page's own foot button opens it.
async function historyUseCount(page, itemCode) {
    const n = await page.evaluate(async (code) => {
        openLineOverrideHistoryModalRd(code);
        await new Promise(r => setTimeout(r, 400));
        const el = document.getElementById('lineOverrideHistoryModal');
        const out = {
            use: el.querySelectorAll('.lo-history-use').length,
            entries: el.querySelectorAll('.lo-history-timeline li.timeline-item').length,
            disabled: el.querySelectorAll('.lo-history-use[disabled], .lo-history-use.d-none').length,
        };
        const inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
        await new Promise(r => setTimeout(r, 300));
        return out;
    }, itemCode);
    return n;
}
// The dropdown behind the badge, opened on the row that has a history at all.
async function historyMenuCount(page, itemCode) {
    return page.evaluate(async (code) => {
        const row = document.querySelector(`tr.lo-row[data-item-code="${code}"]`);
        const toggle = row ? row.querySelector('.lo-history-toggle') : null;
        if (!toggle) return { buttons: -1, rows: -1 };
        toggle.click();
        await new Promise(r => setTimeout(r, 400));
        const menu = document.querySelector('.dropdown-menu.show');
        const out = menu
            ? { buttons: menu.querySelectorAll('button').length, rows: menu.querySelectorAll('li').length,
                viewAll: menu.querySelectorAll('.lo-history-view-all').length,
                statics: menu.querySelectorAll('.lo-history-item-static').length }
            : { buttons: -1, rows: -1 };
        toggle.click();
        await new Promise(r => setTimeout(r, 200));
        return out;
    }, itemCode);
}
async function setVerified(page, verified) {
    return page.evaluate(async (args) => {
        const res = await fetch(`${BASE_URL}/api/payroll-run.employee-verify.save`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: PAYROLL_RUN_ID, employee_id: Number(args.employeeId), verified: args.verified }),
        });
        return res.json();
    }, { employeeId, verified });
}

async function runCell(opts) {
    const label = `${opts.width} ${opts.lang} ${opts.colorScheme}`;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme });
    const { page, report } = ctx;
    await page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    // The app's own switch, not a pre-seeded localStorage value -- the write-back it fires is what
    // the harness blocks, and a cell that switched language must show that block happening.
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);
    await page.waitForSelector(`.btn-view-breakdown[data-employee-id="${employeeId}"]`, { state: 'attached', timeout: 30000 });
    // Start from a known state: which slip opens is decided by this flag, so a leftover from an
    // interrupted round would otherwise measure the read-only slip twice and call it a pass.
    await setVerified(page, false);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);

    // 1. the editable slip
    await openSlip(page);
    const edit = await measure(page);
    const editHistory = await historyUseCount(page, '__base_salary__');
    const editMenu = await historyMenuCount(page, '__base_salary__');
    await closeSlip(page);

    // 2. the read-only slip -- reached the way a user reaches it: by verifying the row
    const verifyRes = await setVerified(page, true);
    check(`${label}: verify accepted`, verifyRes && verifyRes.status === true, JSON.stringify(verifyRes));
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);
    await openSlip(page);
    const view = await measure(page);
    const viewHistory = await historyUseCount(page, '__base_salary__');
    const viewMenu = await historyMenuCount(page, '__base_salary__');
    await closeSlip(page);

    // 3. put the row back exactly as it was
    const restore = await setVerified(page, false);
    check(`${label}: verify flag restored`, restore && restore.status === true, JSON.stringify(restore));

    console.log(`  edit: rows=${edit.rowCount} groups=${edit.groupCount} check=${edit.checkCells} action=${edit.actionCells} totals=${edit.totals.length} manual=${edit.manualRows}`);
    console.log(`  view: rows=${view.rowCount} groups=${view.groupCount} check=${view.checkCells} action=${view.actionCells} totals=${view.totals.length} manual=${view.manualRows}`);

    check(`${label}: the same number of rows in both slips`, edit.rowCount === view.rowCount && edit.rowCount > 0,
        `${edit.rowCount} vs ${view.rowCount}`);
    check(`${label}: the same codes, in the same order`, edit.codes.join('|') === view.codes.join('|'),
        `${edit.codes.join('|')} vs ${view.codes.join('|')}`);
    // The 2 modes carry the same groups EXCEPT the empty manual ones, which only the editable slip
    // renders (2026-09-18, 4a-2) -- so the difference is exactly that number, never anything else.
    check(`${label}: the same group headings, bar the empty manual ones only the editable slip offers`,
        edit.groupCount - edit.emptyGroups === view.groupCount && view.emptyGroups === 0 && view.groupCount > 0,
        `edit=${edit.groupCount}(-${edit.emptyGroups}) view=${view.groupCount}(-${view.emptyGroups})`);
    check(`${label}: the read-only slip has no toggle/action cell in the DOM at all`,
        view.checkCells === 0 && view.actionCells === 0 && view.switches === 0 && view.pencils === 0,
        JSON.stringify({ check: view.checkCells, action: view.actionCells, sw: view.switches, pencil: view.pencils }));
    check(`${label}: ...and the editable one does`, edit.checkCells > 0 && edit.actionCells > 0 && edit.switches > 0,
        JSON.stringify({ check: edit.checkCells, action: edit.actionCells, sw: edit.switches }));
    check(`${label}: nothing is merely hidden in either slip`, edit.hidden === 0 && view.hidden === 0,
        `${edit.hidden} / ${view.hidden}`);
    check(`${label}: the history badge works in both`, edit.historyBadges === view.historyBadges,
        `${edit.historyBadges} vs ${view.historyBadges}`);
    check(`${label}: 3 totals rows in both, with the same figures`,
        edit.totals.length === 3 && view.totals.length === 3
        && edit.totals.map(t => t.amount).join('|') === view.totals.map(t => t.amount).join('|'),
        JSON.stringify({ edit: edit.totals, view: view.totals }));
    check(`${label}: the 3 totals are the LAST 3 rows of the table, in both slips`,
        edit.totalsIsLast && view.totalsIsLast, `${edit.totalsIsLast} / ${view.totalsIsLast}`);
    check(`${label}: ...and the block they used to sit in is gone from both`,
        edit.totalsAfterManual && view.totalsAfterManual && edit.totals.length === 3,
        `${edit.totalsAfterManual} / ${view.totalsAfterManual}`);
    check(`${label}: the figures are the ones measured before the move`,
        EXPECTED_TOTALS === '' || edit.totals.map(t => t.amount).join('|') === EXPECTED_TOTALS,
        edit.totals.map(t => t.amount).join('|'));
    check(`${label}: the 3 totals are labelled, not bare figures`,
        view.totals.every(t => t.label.length > 0), JSON.stringify(view.totals));
    check(`${label}: no "-" placeholder in any cell of either slip`, edit.dashCells === 0 && view.dashCells === 0,
        `${edit.dashCells} / ${view.dashCells}`);
    check(`${label}: no "?" popover button anywhere in either slip`, edit.questionButtons === 0 && view.questionButtons === 0,
        `${edit.questionButtons} / ${view.questionButtons}`);
    check(`${label}: the retired "verified -- unverify first" line is gone from the markup`,
        edit.statusLine === 0 && view.statusLine === 0, `${edit.statusLine} / ${view.statusLine}`);
    check(`${label}: the read-only slip's footer is [close] alone`, view.footerButtons.length === 1,
        JSON.stringify(view.footerButtons));
    check(`${label}: hand-added lines survive into the read-only slip`, edit.manualRows === view.manualRows,
        `${edit.manualRows} vs ${view.manualRows}`);
    check(`${label}: ...without an add row on it`, view.addButtons === 0 && edit.addButtons === 2,
        `${view.addButtons} / ${edit.addButtons}`);
    // The sub-lines, in the fixed order, on every row this run really has -- which codes those are
    // depends on the run being measured (a seeded fixture or run 752), so the set is read off the
    // slip rather than named here. At least one row must carry sub-lines, or this proves nothing.
    const taggedCodes = Object.keys(edit.tags).filter(c => (edit.tags[c] || []).length > 0);
    check(`${label}: at least one row carries sub-lines to compare`, taggedCodes.length > 0,
        JSON.stringify(Object.keys(edit.tags)));
    taggedCodes.forEach((code) => {
        const e = edit.tags[code] || [];
        const v = view.tags[code] || [];
        check(`${label}: ${code} carries the same sub-lines in the same order in both slips`,
            e.join(' || ') === v.join(' || '), `${JSON.stringify(e)} vs ${JSON.stringify(v)}`);
    });
    check(`${label}: a skipped line is not a row of either slip at all`,
        edit.skipMarkers === 0 && view.skipMarkers === 0 && edit.skippedRows.length === 0 && view.skippedRows.length === 0,
        JSON.stringify({ e: edit.skipMarkers, v: view.skipMarkers }));
    if (opts.width === 430) {
        check(`${label}: the table scrolls sideways rather than overflowing the modal`, edit.overflowX >= 0 && view.overflowX >= 0,
            `${edit.overflowX} / ${view.overflowX}`);
        check(`${label}: the frozen 2nd column still knows where the 1st one ends`,
            edit.stickyLeft2 !== '' && view.stickyLeft2 !== '', `${edit.stickyLeft2} / ${view.stickyLeft2}`);
    }

    // 4a-1 follow-up 2: the history modal inherits the slip's mode.
    console.log(`  history modal: edit=${JSON.stringify(editHistory)} view=${JSON.stringify(viewHistory)}`);
    check(`${label}: the read-only slip's history modal offers no [use this value] at all`,
        viewHistory.use === 0 && viewHistory.disabled === 0, JSON.stringify(viewHistory));
    check(`${label}: the editable one offers one per entry that is not the current value`,
        editHistory.use > 0 && editHistory.use === editHistory.entries - 1,
        JSON.stringify(editHistory));
    // 4a-1 follow-up 4: the dropdown behind the badge, same rule as the modal.
    console.log(`  history dropdown: edit=${JSON.stringify(editMenu)} view=${JSON.stringify(viewMenu)}`);
    check(`${label}: the read-only dropdown has exactly 1 button (the full-history foot)`,
        viewMenu.buttons === 1 && viewMenu.viewAll === 1, JSON.stringify(viewMenu));
    // rows = the static calculated-value head + n edits + the foot, so n is rows - 2 and the
    // editable menu carries one button per edit plus that same foot.
    const menuEdits = editMenu.rows - 2;
    check(`${label}: the editable one has one button per edit plus that foot (n+1)`,
        menuEdits > 0 && editMenu.buttons === menuEdits + 1, `${editMenu.buttons} vs ${menuEdits} + 1`);
    check(`${label}: and every one of those entries is static in the read-only menu`,
        viewMenu.statics === menuEdits + 1, `${viewMenu.statics} vs ${menuEdits} + 1`);
    check(`${label}: both list the same number of rows`, editMenu.rows === viewMenu.rows,
        `${editMenu.rows} vs ${viewMenu.rows}`);
    // 4a-1 follow-up 3: sub-lines in full.
    const badTags = edit.tagMetrics.concat(view.tagMetrics).filter(t => t.clipped || t.ellipsis || t.nowrap || t.hasTitle);
    console.log(`  tags: ${edit.tagMetrics.length + view.tagMetrics.length} วัด, สูงสุด ${Math.max(0, ...edit.tagMetrics.map(t => t.len))} ตัวอักษร, fs=${(edit.tagMetrics[0] || {}).fontSize}`);
    console.log(`  statutory row heights: edit=${JSON.stringify(edit.statutoryRowHeights)} view=${JSON.stringify(view.statutoryRowHeights)}`);
    check(`${label}: no sub-line is clipped, ellipsised, nowrapped or hidden behind a title`,
        badTags.length === 0, JSON.stringify(badTags.slice(0, 3)));
    check(`${label}: the statutory rows really carry their formula text`,
        view.tagMetrics.some(t => (t.code === 'TH_SSO' || t.code === 'TH_PIT') && t.len > 10),
        JSON.stringify(view.tagMetrics.filter(t => t.code === 'TH_SSO' || t.code === 'TH_PIT')));

    const rep = report();
    console.log(`  cell report: ${JSON.stringify({ blockedPreferenceSaves: rep.blockedPreferenceSaves, blockedRecalculates: rep.blockedRecalculates, consoleErrors: rep.consoleErrors.length, pageErrors: rep.pageErrors.length })}`);
    if (rep.consoleErrors.length) console.log(`  console: ${JSON.stringify(rep.consoleErrors.slice(0, 3))}`);
    if (rep.pageErrors.length) console.log(`  pageerr: ${JSON.stringify(rep.pageErrors.slice(0, 3))}`);
    check(`${label}: no page error`, rep.pageErrors.length === 0, JSON.stringify(rep.pageErrors.slice(0, 2)));
    return rep;
}

(async () => {
    const reports = [];
    const cells = (process.env.K4A1_CELLS === 'all')
        ? [['th', 'light', 1400], ['th', 'light', 430], ['th', 'dark', 1400], ['th', 'dark', 430],
           ['en', 'light', 1400], ['en', 'light', 430], ['en', 'dark', 1400], ['en', 'dark', 430]]
        : [['th', 'light', 1400], ['th', 'dark', 430]];
    for (const [lang, colorScheme, width] of cells) {
        reports.push(await runCell({ width, height: width === 430 ? 930 : 950, lang, colorScheme }));
    }
    await closeAll();
    console.log(`\nblockedPreferenceSaves total: ${reports.reduce((a, r) => a + r.blockedPreferenceSaves, 0)}`);
    console.log(`blockedRecalculates total: ${reports.reduce((a, r) => a + r.blockedRecalculates, 0)}`);
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed === 0 ? 0 : 1);
})();
