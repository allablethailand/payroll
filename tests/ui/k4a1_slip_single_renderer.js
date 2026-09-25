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
const { openContext, closeAll, ensureCellTheme } = require('./harness');

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
            // 2026-09-22, slip2-a: the controls are a block inside the item cell now, not a column.
            actionBlocks: document.querySelectorAll(wrap + ' .lo-row-actions').length,
            switches: document.querySelectorAll(wrap + ' .lo-include').length,
            pencils: document.querySelectorAll(wrap + ' .lo-edit-btn').length,
            historyBadges: document.querySelectorAll(wrap + ' .lo-history-toggle').length,
            // 2026-09-18, 4a-2: the last 3 rows of the table's own tbody again -- the block they sat
            // in for half a day is gone with the hand-added card that stood between them and it.
            totals: Array.from(document.querySelectorAll(wrap + ' .lo-totals-row')).map(r => ({
                label: r.children[0].textContent.trim(),
                amount: (r.querySelector('.num') || { textContent: '' }).textContent.trim(),
            })),
            totalsHtml: Array.from(document.querySelectorAll(wrap + ' .lo-totals-row')).map(r => r.innerHTML).join(''),
            // 2026-09-19, tiny-4b-fix1 v2: the totals are a block of their own AFTER the scroller --
            // "last" is now about the host, not about the table body.
            totalsIsLast: (() => {
                const host = document.querySelector(wrap);
                const block = document.querySelector(wrap + ' .lo-totals');
                return !!block && !!host && host.lastElementChild === block
                    && !block.closest('.table-responsive');
            })(),
            // The block and the card it sat under are both gone -- neither may come back.
            totalsAfterManual: !document.querySelector('#breakdownNetSummary, .lo-totals-block, .ml-mount'),
            // The tag order of every row that carries one, keyed by code.
            tags: rows.reduce((acc, r) => { acc[r.getAttribute('data-item-code')] = tagsOf(r); return acc; }, {}),
            /* 2026-09-21, a0: the 2 tri-state rows, as the ROW itself declares them. The read-only
               slip drops such a row when it has nothing to say (0, untouched, no answer chosen) --
               lineOverrideIsSkippedRd()'s own `view` branch -- so "the 2 slips carry the same rows"
               has to be asked of the rows that survive that rule, not of every row. Read off the
               data-attributes the row already carries rather than re-deriving the rule here. */
            tristate: rows.filter(r => r.getAttribute('data-exemption-field')).map(r => ({
                code: r.getAttribute('data-item-code'),
                changed: !!r.getAttribute('data-exemption-changed'),
                overridden: !!r.getAttribute('data-orig-action'),
                amount: parseFloat(String(r.getAttribute('data-amount') || '0').replace(/,/g, '')) || 0,
            })),
            // Every statutory row with its figure, so "a 0 says why" can be asked of the rows that
            // really are 0 rather than of a code named here.
            statutoryRows: rows.filter(r => r.getAttribute('data-line-type') === 'statutory').map(r => ({
                code: r.getAttribute('data-item-code'),
                amount: parseFloat(String(r.getAttribute('data-amount') || '0').replace(/,/g, '')) || 0,
                tags: tagsOf(r),
            })),
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
            // By ROLE, not by its Thai/English wording: the way out, the one action that writes
            // every row at once, and any solid main action at all.
            closeButtons: document.querySelectorAll('#breakdownModalFooter [data-bs-dismiss="modal"]').length,
            restoreAllButtons: document.querySelectorAll('#btnRestoreAllComputedLineOverrides').length,
            footerPrimaryButtons: document.querySelectorAll('#breakdownModalFooter .btn-primary').length,
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
/* 2026-09-21, a0: `historyUseCount()` and `historyMenuCount()` are gone with what they measured.
   974b1ac4 replaced BOTH surfaces this round watched -- the 5-row dropdown behind the "แก้ไข n"
   badge and the stacked `#lineOverrideHistoryModal` it linked to -- with one table under the row.
   `openLineOverrideHistoryModalRd`, `.lo-history-timeline`, `.lo-history-view-all` and
   `.lo-history-item-static` have 0 occurrences left in the app, so the first of those calls threw a
   ReferenceError inside page.evaluate() and killed the whole round at its FIRST cell: everything
   below was unmeasured, not passing. What replaced them is measured in full by
   tests/ui/h_history_table.js (the panel's 3 layers, both modes, the 38-entry chain and the write a
   pick really sends) -- so this round drops them rather than re-pointing them at the new markup and
   owning a second, thinner copy of that file's job. */
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

/* 2026-09-21, a0: a cell named "dark" was measuring LIGHT, in every one of them -- see
   ensureCellTheme()'s own note in harness.js for why, and why it is called after EVERY page load
   rather than once per cell (this round reloads twice inside one cell). */
const applyCellTheme = (page, opts, label, when) =>
    ensureCellTheme(page, opts.colorScheme, { label, when, check, log: console.log });

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
    if (!await applyCellTheme(page, opts, label, 'first load')) return report();
    await page.waitForSelector(`.btn-view-breakdown[data-employee-id="${employeeId}"]`, { state: 'attached', timeout: 30000 });
    // Start from a known state: which slip opens is decided by this flag, so a leftover from an
    // interrupted round would otherwise measure the read-only slip twice and call it a pass.
    await setVerified(page, false);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);
    if (!await applyCellTheme(page, opts, label, 'after reset reload')) return report();

    // 1. the editable slip
    await openSlip(page);
    const edit = await measure(page);
    await closeSlip(page);

    // 2. the read-only slip -- reached the way a user reaches it: by verifying the row
    const verifyRes = await setVerified(page, true);
    check(`${label}: verify accepted`, verifyRes && verifyRes.status === true, JSON.stringify(verifyRes));
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);
    // The verify flag is already set at this point, so a theme that did not take has to put the row
    // back before it leaves -- an interrupted cell must not hand the next one a verified row.
    if (!await applyCellTheme(page, opts, label, 'after verify reload')) { await setVerified(page, false); return report(); }
    await openSlip(page);
    const view = await measure(page);
    await closeSlip(page);

    // 3. put the row back exactly as it was
    const restore = await setVerified(page, false);
    check(`${label}: verify flag restored`, restore && restore.status === true, JSON.stringify(restore));

    console.log(`  edit: rows=${edit.rowCount} groups=${edit.groupCount} check=${edit.checkCells} blocks=${edit.actionBlocks} totals=${edit.totals.length} manual=${edit.manualRows}`);
    console.log(`  view: rows=${view.rowCount} groups=${view.groupCount} check=${view.checkCells} blocks=${view.actionBlocks} totals=${view.totals.length} manual=${view.manualRows}`);

    /* 2026-09-21, a0: "the same rows" is now "the same rows BAR the tri-state ones the read-only
       slip has nothing to say about". 4b gave those 2 rows their own rule: in the editable slip they
       always render (the switch is the only way to set that answer, so hiding the row would make it
       unreachable), in the read-only one they render only when they carry a figure or an answer
       somebody chose (detail.js, lineOverrideIsSkippedRd()). The expectation is DERIVED from the
       editable slip's own rows -- each row declares its field/answer/figure -- so it stays right for
       any fixture instead of naming the codes this one happens to drop. */
    const silentTriState = edit.tristate.filter(t => t.amount === 0 && !t.overridden && !t.changed).map(t => t.code);
    const expectedViewCodes = edit.codes.filter(c => silentTriState.indexOf(c) === -1);
    if (silentTriState.length) console.log(`  read-only drops (tri-state with nothing to say): ${silentTriState.join('|')}`);
    check(`${label}: the same number of rows in both slips, bar the silent tri-state ones`,
        expectedViewCodes.length === view.rowCount && edit.rowCount > 0,
        `${edit.rowCount} - ${silentTriState.length} vs ${view.rowCount}`);
    check(`${label}: the same codes, in the same order`, expectedViewCodes.join('|') === view.codes.join('|'),
        `${expectedViewCodes.join('|')} vs ${view.codes.join('|')}`);
    // The 2 modes carry the same groups EXCEPT the empty manual ones, which only the editable slip
    // renders (2026-09-18, 4a-2) -- so the difference is exactly that number, never anything else.
    check(`${label}: the same group headings, bar the empty manual ones only the editable slip offers`,
        edit.groupCount - edit.emptyGroups === view.groupCount && view.emptyGroups === 0 && view.groupCount > 0,
        `edit=${edit.groupCount}(-${edit.emptyGroups}) view=${view.groupCount}(-${view.emptyGroups})`);
    check(`${label}: the read-only slip has no toggle cell and no row control in the DOM at all`,
        view.checkCells === 0 && view.switches === 0 && view.pencils === 0,
        JSON.stringify({ check: view.checkCells, sw: view.switches, pencil: view.pencils }));
    check(`${label}: ...and the editable one does`, edit.checkCells > 0 && edit.actionBlocks > 0 && edit.switches > 0,
        JSON.stringify({ check: edit.checkCells, blocks: edit.actionBlocks, sw: edit.switches }));
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
    /* 2026-09-21, a0: not [ปิด] alone any more, and deliberately so. A row is read-only HERE because
       somebody froze it, and unfreezing it is what turns this slip back into the editable one -- so
       the read-only slip on a draft run carries [ยกเลิกการตรวจสอบ] in §9's left slot
       (renderBreakdownFooterRd()/breakdownCanUnverifyRd()). What must still hold is that it has NO
       main action: no primary button, and nothing that writes a figure. */
    check(`${label}: the read-only slip's footer offers the way out and the way back in, nothing else`,
        view.footerButtons.length <= 2 && view.closeButtons === 1
        && view.restoreAllButtons === 0 && view.footerPrimaryButtons === 0,
        JSON.stringify({ buttons: view.footerButtons, close: view.closeButtons, restoreAll: view.restoreAllButtons, primary: view.footerPrimaryButtons }));
    check(`${label}: ...and the editable one carries [คืนค่าระบบทั้งหมด] instead`,
        edit.restoreAllButtons === 1, JSON.stringify(edit.footerButtons));
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
    /* 2026-09-21, a0: the read-only slip carries ONE sub-line the editable one does not -- H-ui's
       "แก้ไขแล้ว"/"เพิ่มเอง" mark (detail.js's lineOverrideChangeTagTextRd, rendered `isView` only).
       It is there because the read-only slip is the only one with nothing else saying a line was
       touched: the editable one already has the switch, the pencil and the "ระบบ: x" sub-line on
       those same rows. So it is stripped before comparing -- the same thing the unit twin does
       (tests/slip_single_renderer_test.js) -- and the REST must still match exactly.
       Read off the page rather than hard-coded, so it holds in th and en alike. */
    const changeTags = await page.evaluate(() => [
        (typeof langData === 'object' && langData['line_override_row_tag_edited']) || 'Edited',
        (typeof langData === 'object' && langData['line_override_row_tag_added']) || 'Added by hand',
    ]);
    const stripChangeTag = (list) => list.filter(t => changeTags.indexOf(t) === -1);
    check(`${label}: the read-only slip's own "edited/added" mark is the only sub-line it adds`,
        Object.keys(view.tags).every(c => (view.tags[c] || []).filter(t => changeTags.indexOf(t) !== -1).length <= 1),
        JSON.stringify(view.tags));
    taggedCodes.filter(code => view.codes.indexOf(code) !== -1).forEach((code) => {
        const e = edit.tags[code] || [];
        const v = stripChangeTag(view.tags[code] || []);
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

    // 4a-1 follow-up 2 and 4 (the history modal and the dropdown behind the badge) are not measured
    // here any more -- see the note where their 2 helpers used to be.
    // 4a-1 follow-up 3: sub-lines in full.
    const badTags = edit.tagMetrics.concat(view.tagMetrics).filter(t => t.clipped || t.ellipsis || t.nowrap || t.hasTitle);
    console.log(`  tags: ${edit.tagMetrics.length + view.tagMetrics.length} วัด, สูงสุด ${Math.max(0, ...edit.tagMetrics.map(t => t.len))} ตัวอักษร, fs=${(edit.tagMetrics[0] || {}).fontSize}`);
    console.log(`  statutory row heights: edit=${JSON.stringify(edit.statutoryRowHeights)} view=${JSON.stringify(view.statutoryRowHeights)}`);
    check(`${label}: no sub-line is clipped, ellipsised, nowrapped or hidden behind a title`,
        badTags.length === 0, JSON.stringify(badTags.slice(0, 3)));
    /* 2026-09-21, a0: was "TH_SSO or TH_PIT carries a formula sub-line longer than 10 characters",
       which is a property of the EMPLOYEE, not of the slip -- this fixture's own employee is not
       enrolled in SSO and is tax-exempt, so both rows are 0 and there is no formula anywhere to
       print. What the slip really owes the reader is the rule 4a-2 settled on: a statutory row that
       is rendered at 0 has to say WHY it is 0, on the row itself. Asked of the rows that really are
       0 rather than of a code named here. */
    const zeroStatutory = edit.statutoryRows.filter(r => r.amount === 0);
    console.log(`  statutory rows (edit): ${JSON.stringify(edit.statutoryRows)}`);
    check(`${label}: every statutory row rendered at 0 says on the row why it is 0`,
        zeroStatutory.length === 0 || zeroStatutory.every(r => r.tags.length > 0),
        JSON.stringify(zeroStatutory));

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
