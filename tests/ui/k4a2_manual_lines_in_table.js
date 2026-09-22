/**
 * 4a-2a measurement: a hand-added line is a ROW of the one slip table, and the 3 totals are its last
 * rows again.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/k4a2_manual_lines_in_table.js <PHPSESSID> <runToken> <employeeId>
 *
 * Per cell it seeds 3 hand-added lines (2 income + 1 deduction) through the app's own endpoint, opens
 * the Calculation Breakdown modal for that employee TWICE -- once while the row is editable, once
 * after verifying it, which is the app's own way into the read-only slip -- measures both, then puts
 * the row and the run back exactly as they were (lines removed, verify flag restored).
 *
 * 8 cells (a layout round): th/en x light/dark x 1400/430.
 */
'use strict';
const { openContext, closeAll, ensureCellTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
const pedTypeId = process.argv[5];
if (!sessionId || !runToken || !employeeId || !pedTypeId) {
    throw new Error('usage: node tests/ui/k4a2_manual_lines_in_table.js <PHPSESSID> <runToken> <employeeId> <pedTypeId>');
}

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

const WRAP = '#breakdownLineOverrideWrap';
// The 3 lines this round seeds. 2 income + 1 deduction, so both manual groups are non-empty AND the
// "one group per type" split is really exercised rather than inferred from a single row.
const SEED = [
    { item_type: 'earning', custom_item_name: 'K4A2 Bonus A', amount: 1500, note: 'k4a2 note A' },
    { item_type: 'earning', custom_item_name: 'K4A2 Bonus B', amount: 250.5, note: '' },
    { item_type: 'deduction', custom_item_name: 'K4A2 Fine', amount: 75, note: 'k4a2 note C' },
];

async function measure(page) {
    return page.evaluate((wrap) => {
        const rows = Array.from(document.querySelectorAll(wrap + ' tr.lo-row'));
        const manual = rows.filter(r => r.className.indexOf('lo-row-manual') !== -1);
        const table = document.querySelector(wrap + ' table.lo-table');
        const tbody = table ? table.querySelector('tbody') : null;
        const allTr = tbody ? Array.from(tbody.children) : [];
        // 2026-09-19, tiny-4b-fix1 v2: no longer rows of the table -- a block after the scroller.
        const totalTr = Array.from(document.querySelectorAll(wrap + ' .lo-totals-row'));
        const scroller = document.querySelector(wrap + ' .table-responsive');
        const body = document.querySelector('#breakdownModalBody');
        const textOf = (r) => (r.querySelector('.lo-name') || { textContent: '' }).textContent.trim();
        return {
            rowCount: rows.length,
            codes: rows.map(r => r.getAttribute('data-item-code')),
            // The 2 tri-state rows, as the ROW itself declares them -- the read-only slip drops one
            // that has nothing to say (0, untouched, no answer chosen). See the assertion's own note.
            tristate: rows.filter(r => r.getAttribute('data-exemption-field')).map(r => ({
                code: r.getAttribute('data-item-code'),
                changed: !!r.getAttribute('data-exemption-changed'),
                overridden: !!r.getAttribute('data-orig-action'),
                amount: parseFloat(String(r.getAttribute('data-amount') || '0').replace(/,/g, '')) || 0,
            })),
            groupCount: document.querySelectorAll(wrap + ' tr.lo-group').length,
            groupLabels: Array.from(document.querySelectorAll(wrap + ' tr.lo-group')).map(r => r.textContent.trim()),
            checkCells: document.querySelectorAll(wrap + ' td.col-check').length,
            actionCells: document.querySelectorAll(wrap + ' td.lo-action-cell').length,
            switches: document.querySelectorAll(wrap + ' .lo-include').length,
            pencils: document.querySelectorAll(wrap + ' .lo-edit-btn').length,
            hidden: document.querySelectorAll(wrap + ' .d-none').length,

            // --- what 4a-2 moved ---
            manualRows: manual.length,
            manualNames: manual.map(textOf),
            manualInTable: manual.every(r => !!r.closest('table.lo-table')),
            // A manual row has no switch of its own, and its toggle cell is present-but-empty in the
            // editable slip so it stays in the same grid as every other row.
            manualSwitches: manual.filter(r => r.querySelector('.lo-include')).length,
            manualEmptyCheckCells: manual.filter(r => {
                const td = r.querySelector('td.col-check');
                return td && td.textContent.trim() === '';
            }).length,
            manualPencils: manual.filter(r => r.querySelector('.lo-edit-btn')).length,
            manualEditBtns: document.querySelectorAll(wrap + ' .manual-line-edit-btn').length,
            manualRemoveBtns: document.querySelectorAll(wrap + ' .manual-line-remove-btn').length,
            manualHistoryCells: manual.filter(r => {
                const td = r.querySelector('td.lo-history-cell');
                return td && td.textContent.trim() !== '';
            }).length,
            // ...and the cell itself, present on every manual row whether it carries a badge or not,
            // so the row stays in the same grid as every other one.
            manualHistoryCellSlots: manual.filter(r => !!r.querySelector('td.lo-history-cell')).length,
            manualTags: manual.map(r => Array.from(r.querySelectorAll('.payslip-line-tag')).map(t => t.textContent.trim())),
            // 2026-09-18, follow-up 3: the link rides on the group HEAD, so there is no add row left
            // and every link must be inside a `tr.lo-group`.
            addRows: document.querySelectorAll(wrap + ' tr.lo-row-add').length,
            addButtons: document.querySelectorAll(wrap + ' .lo-add-line-btn').length,
            addButtonsInGroupHead: Array.from(document.querySelectorAll(wrap + ' .lo-add-line-btn'))
                .filter(b => !!b.closest('tr.lo-group')).length,
            // Groups carrying a link, and groups that are only a head (an empty manual group = 1 <tr>).
            groupsWithLink: document.querySelectorAll(wrap + ' tr.lo-group .lo-add-line-btn').length,
            emptyManualGroupRows: Array.from(document.querySelectorAll(wrap + ' tr.lo-group')).filter((g) => {
                let el = g.nextElementSibling;
                while (el && el.className.indexOf('lo-group') === -1) {
                    if (el.className.indexOf('lo-row') !== -1) return false;
                    el = el.nextElementSibling;
                }
                return !!g.querySelector('.lo-add-line-btn');
            }).length,
            // The link's right edge against the table's own right edge, and against the inset every
            // other right-aligned thing in this table ends on (the last cell's content-box right).
            addLinkRightGap: (() => {
                const t = document.querySelector(wrap + ' table.lo-table');
                const b = document.querySelector(wrap + ' .lo-add-line-btn');
                if (!t || !b) return null;
                const tr = t.getBoundingClientRect().right;
                const td = document.querySelector(wrap + ' tr.lo-group > td');
                const inset = td ? parseFloat(getComputedStyle(td).paddingRight) || 0 : 0;
                return { toTable: Math.round((tr - b.getBoundingClientRect().right) * 10) / 10,
                    toInset: Math.round((tr - inset - b.getBoundingClientRect().right) * 10) / 10 };
            })(),
            addButtonTypes: Array.from(document.querySelectorAll(wrap + ' .lo-add-line-btn')).map(b => b.getAttribute('data-item-type')),
            // The orange text link, not a filled button.
            addButtonStyle: (() => {
                const b = document.querySelector(wrap + ' .lo-add-line-btn');
                if (!b) return null;
                const cs = getComputedStyle(b);
                return { color: cs.color, bg: cs.backgroundColor, borderColor: cs.borderTopColor, deco: cs.textDecorationLine };
            })(),

            /* --- the 3 totals: a BLOCK after the scroller, not rows of the table ---
               2026-09-19, tiny-4b-fix1 v2 reversed 4a-2 on exactly this, and for a reason 4a-2 had
               not measured: below `sm` this table is a horizontal scroller (host 394px, table 530px)
               so a ROW of it carries its figures out of view with every drag, whatever cells it is
               built from. A total is the slip's bottom line, not a line of the slip, so it lives
               outside the scroller. See docs/decisions/2026-09-19-4b-fix1-totals-block.md. */
            totals: totalTr.map(r => ({
                label: r.children[0].textContent.trim(),
                amount: (r.querySelector('.num') || { textContent: '' }).textContent.trim(),
            })),
            totalsIsBlockAfterScroller: (() => {
                const host = document.querySelector(wrap);
                const block = document.querySelector(wrap + ' .lo-totals');
                return !!block && !!host && host.lastElementChild === block && !block.closest('.table-responsive');
            })(),
            // ...and carries no table markup at all any more.
            totalsHasNoTableMarkup: document.querySelectorAll(wrap + ' .lo-totals tr, ' + wrap + ' .lo-totals td').length === 0,
            // The 2 x's the decision fixed it on: the label starts where the group headings start,
            // the figure ends where the "เพิ่มรายการ" link ends -- both on the block's own inset.
            totalsAlign: (() => {
                const host = document.querySelector(wrap);
                const block = document.querySelector(wrap + ' .lo-totals');
                const trows = Array.from(document.querySelectorAll(wrap + ' .lo-totals-row'));
                const head = document.querySelector(wrap + ' tr.lo-group .lo-span-sticky');
                if (!host || !block || !trows.length) return null;
                const hostRight = host.getBoundingClientRect().right;
                const inset = parseFloat(getComputedStyle(block).paddingRight) || 0;
                const headLeft = head ? head.getBoundingClientRect().left : null;
                return {
                    labelLeftDelta: headLeft === null ? null
                        : trows.map(r => Math.round((r.children[0].getBoundingClientRect().left - headLeft) * 10) / 10),
                    figureRightGap: trows.map((r) => {
                        const n = r.querySelector('.num');
                        return n ? Math.round((hostRight - inset - n.getBoundingClientRect().right) * 10) / 10 : null;
                    }),
                };
            })(),
            // The retired card and its block, anywhere in the modal.
            retiredNodes: body
                ? body.querySelectorAll('.ml-mount, .lo-totals-block, #breakdownNetSummary, #breakdownManualLines, .payslip-view, .manual-line-item').length
                : -1,

            // --- skipped rows: not rendered at all (2026-09-18, 4a-2 follow-up) ---
            skipped: rows.filter(r => r.className.indexOf('lo-row-skipped') !== -1).map(r => ({
                code: r.getAttribute('data-item-code'),
                badges: r.querySelectorAll('.badge').length,
                tags: r.querySelectorAll('.lo-name-cell .payslip-line-tag').length,
            })),
            // The 2 codes this fixture's employee is not enrolled in must not appear as rows at all.
            enrolmentRows: rows.filter(r => ['TH_SSO', 'TH_PVD'].indexOf(r.getAttribute('data-item-code')) !== -1)
                .map(r => ({ code: r.getAttribute('data-item-code'), amount: (r.querySelector('.lo-amount-view') || { textContent: '' }).textContent.trim() })),
            // Every round icon button in this table, measured -- a circle may not be squashed by the
            // flex row it sits in nor stretched by the cell.
            iconButtonSizes: Array.from(document.querySelectorAll(wrap + ' .lo-actions .btn-icon')).map(b => {
                const r = b.getBoundingClientRect();
                return { cls: b.className.indexOf('manual-line') !== -1 ? 'manual' : 'lo',
                    w: Math.round(r.width), h: Math.round(r.height) };
            }),
            // An ordinary statutory row still says what it is AND shows its sum.
            statutoryNormal: rows.filter(r => r.getAttribute('data-line-type') === 'statutory'
                && r.className.indexOf('lo-row-skipped') === -1).map(r => ({
                code: r.getAttribute('data-item-code'),
                badges: r.querySelectorAll('.badge').length,
                tags: r.querySelectorAll('.lo-name-cell .payslip-line-tag').length,
                // The whole row, not the name cell alone: a tri-state row says why it is 0 in the
                // AMOUNT cell ("ระบบ: คำนวณ"), which is a sub-line of the figure it qualifies.
                rowTags: r.querySelectorAll('.payslip-line-tag').length,
                amountValue: parseFloat(String(r.getAttribute('data-amount') || '0').replace(/,/g, '')) || 0,
            })),

            // --- 430 ---
            overflowX: scroller ? scroller.scrollWidth - scroller.clientWidth : -1,
            modalOverflowX: body ? body.scrollWidth - body.clientWidth : -1,
            stickyLeft2: table ? table.style.getPropertyValue('--lo-sticky-left-2') : '',
            // Nothing may print a raw i18n key.
            rawKeys: body ? (body.textContent.match(/line_override_group_manual_\w+|manual_line_\w+|add_line/g) || []).length : -1,
        };
    }, WRAP);
}

async function openSlip(page) {
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
async function post(page, endpoint, payload) {
    return page.evaluate(async (args) => {
        const res = await fetch(`${BASE_URL}/api/${args.endpoint}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ id: PAYROLL_RUN_ID }, args.payload)),
        });
        return res.json();
    }, { endpoint, payload });
}
async function setVerified(page, verified) {
    return post(page, 'payroll-run.employee-verify.save', { employee_id: Number(employeeId), verified });
}
// The 3 lines this round measures, through the app's own endpoint -- never straight into the DB, so
// what is measured is what a user would really have created.
async function seedManualLines(page) {
    const out = [];
    for (const line of SEED) {
        out.push(await post(page, 'payroll-run.add-manual-line', {
            employee_id: Number(employeeId),
            ped_type_id: null,
            custom_item_name: line.custom_item_name,
            custom_item_type: line.item_type,
            amount: line.amount,
            note: line.note,
        }));
    }
    return out;
}
async function listManualLineIds(page) {
    return page.evaluate(async (args) => {
        const url = `${BASE_URL}/api/payroll-run.manual-lines?run_id=${PAYROLL_RUN_ID}&employee_id=${args.employeeId}`;
        const res = await fetch(url, { credentials: 'same-origin' });
        const json = await res.json();
        return (json.data || []).map(l => l.id);
    }, { employeeId });
}
async function removeManualLines(page, ids) {
    const out = [];
    for (const lineId of ids) out.push(await post(page, 'payroll-run.remove-manual-line', { line_id: lineId }));
    return out;
}

async function runCell(opts) {
    const label = `${opts.width} ${opts.lang} ${opts.colorScheme}`;
    console.log(`\n=== ${label} ===`);
    const applyCellTheme = (page, o, l, when) =>
        ensureCellTheme(page, o.colorScheme, { label: l, when, check, log: console.log });
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme });
    const { page, report } = ctx;
    await page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);
    if (!await applyCellTheme(page, opts, label, 'first load')) return report();
    await page.waitForSelector(`.btn-view-breakdown[data-employee-id="${employeeId}"]`, { state: 'attached', timeout: 30000 });
    // Start from a known state, and leave nothing from an interrupted cell behind.
    await setVerified(page, false);
    // Whatever this run already carries stays untouched -- it is not this round's to delete. The
    // counts below are measured against it, and only the 3 lines seeded here are removed at the end.
    const baseIds = await listManualLineIds(page);
    const seeded = await seedManualLines(page);
    check(`${label}: 3 hand-added lines seeded`, seeded.every(r => r && r.status === true), JSON.stringify(seeded.map(r => r && r.message)));
    const expectedManual = baseIds.length + 3;
    if (baseIds.length) console.log(`  (run already carried ${baseIds.length} hand-added line(s): ${JSON.stringify(baseIds)} -- left alone)`);
    // A cell that has to give up after seeding still owes the run the state it found: the seeded
    // lines go, the verify flag goes back. Nothing below measures anything after this.
    const bailCell = async () => {
        await setVerified(page, false);
        const leftovers = (await listManualLineIds(page)).filter(id => baseIds.indexOf(id) === -1);
        if (leftovers.length) await removeManualLines(page, leftovers);
        return report();
    };
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);
    if (!await applyCellTheme(page, opts, label, 'after seed reload')) return bailCell();

    // 1. the editable slip
    await openSlip(page);
    const edit = await measure(page);
    await closeSlip(page);

    // 2. the read-only slip -- reached the way a user reaches it
    const verifyRes = await setVerified(page, true);
    check(`${label}: verify accepted`, verifyRes && verifyRes.status === true, JSON.stringify(verifyRes));
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.evaluate((lang) => { if (typeof changeLanguage === 'function') changeLanguage(lang); }, opts.lang);
    await page.waitForTimeout(600);
    if (!await applyCellTheme(page, opts, label, 'after verify reload')) return bailCell();
    await openSlip(page);
    const view = await measure(page);
    await closeSlip(page);

    // 3. put the row and the run back exactly as they were
    const restore = await setVerified(page, false);
    check(`${label}: verify flag restored`, restore && restore.status === true, JSON.stringify(restore));
    const mine = (await listManualLineIds(page)).filter(id => baseIds.indexOf(id) === -1);
    const removed = await removeManualLines(page, mine);
    const after = await listManualLineIds(page);
    check(`${label}: the 3 seeded lines removed again, and nothing else`,
        removed.length === 3 && removed.every(r => r && r.status === true)
        && after.join('|') === baseIds.join('|'),
        JSON.stringify({ removed: removed.length, after, baseIds }));

    console.log(`  edit: rows=${edit.rowCount} groups=${edit.groupCount} manual=${edit.manualRows} add=${edit.addButtons} totals=${edit.totals.length}`);
    console.log(`  view: rows=${view.rowCount} groups=${view.groupCount} manual=${view.manualRows} add=${view.addButtons} totals=${view.totals.length}`);
    console.log(`  groups: ${JSON.stringify(edit.groupLabels)}`);
    console.log(`  manual tags: ${JSON.stringify(edit.manualTags)}`);
    console.log(`  totals: ${JSON.stringify(edit.totals)}`);
    console.log(`  add button: ${JSON.stringify(edit.addButtonStyle)}`);
    console.log(`  icon buttons: ${JSON.stringify(edit.iconButtonSizes)}`);
    console.log(`  add link: inGroupHead=${edit.addButtonsInGroupHead} addRows=${edit.addRows} rightGap=${JSON.stringify(edit.addLinkRightGap)}`);
    console.log(`  totals right gap: edit=${JSON.stringify(edit.totalsRightGap)} view=${JSON.stringify(view.totalsRightGap)}`);
    console.log(`  skipped rows: edit=${edit.skipped.length} view=${view.skipped.length}`);

    check(`${label}: every hand-added line is a row of the table itself, in BOTH slips`,
        edit.manualRows === expectedManual && view.manualRows === expectedManual && edit.manualInTable && view.manualInTable,
        `${edit.manualRows} / ${view.manualRows} (expected ${expectedManual})`);
    /* 2026-09-21, a0: "the same rows" is now "the same rows BAR the tri-state ones the read-only
       slip has nothing to say about" -- 4b's own rule (detail.js, lineOverrideIsSkippedRd()'s `view`
       branch): TH_PIT/TH_SSO always render in the editable slip because the switch there is the only
       way to set that answer, and render in the read-only one only when they carry a figure or an
       answer somebody chose. Derived from the editable slip's own rows, so it holds for any fixture
       instead of naming the code this one happens to drop. */
    const silentTriState = edit.tristate.filter(t => t.amount === 0 && !t.overridden && !t.changed).map(t => t.code);
    const expectedViewCodes = edit.codes.filter(c => silentTriState.indexOf(c) === -1);
    if (silentTriState.length) console.log(`  read-only drops (tri-state with nothing to say): ${silentTriState.join('|')}`);
    check(`${label}: the same rows and the same order in both slips, bar the silent tri-state ones`,
        expectedViewCodes.join('|') === view.codes.join('|') && expectedViewCodes.length === view.rowCount,
        `${expectedViewCodes.join('|')} vs ${view.codes.join('|')}`);
    check(`${label}: the 2 manual groups render, named, with no raw i18n key anywhere`,
        edit.groupCount === view.groupCount && edit.rawKeys === 0 && view.rawKeys === 0
        && edit.groupLabels.every(l => l.length > 0),
        JSON.stringify({ e: edit.groupLabels, rawE: edit.rawKeys, rawV: view.rawKeys }));
    check(`${label}: a hand-added row carries no switch, and an empty toggle cell instead`,
        edit.manualSwitches === 0 && edit.manualEmptyCheckCells === expectedManual,
        `${edit.manualSwitches} / ${edit.manualEmptyCheckCells}`);
    check(`${label}: ...and never the override pencil`, edit.manualPencils === 0 && view.manualPencils === 0);
    /* 2026-09-21, a0: was "no history in its own cell". 974b1ac4 (H-ui) reversed exactly that -- a
       hand-added line HAS carried an amount trail since H-backend, and adding one is itself a
       recorded edit, so it gets the same disclosure badge every other edited row gets. What must
       still hold is that the cell is there on every manual row in both slips (the row stays in the
       same grid) and that what it opens is the badge, not a second kind of control. */
    check(`${label}: a hand-added row keeps its own history cell in both slips`,
        edit.manualHistoryCellSlots === expectedManual && view.manualHistoryCellSlots === expectedManual,
        `${edit.manualHistoryCellSlots} / ${view.manualHistoryCellSlots} (expected ${expectedManual})`);
    check(`${label}: ...and a freshly added one already has something recorded to show`,
        edit.manualHistoryCells === expectedManual && view.manualHistoryCells === expectedManual,
        `${edit.manualHistoryCells} / ${view.manualHistoryCells} (expected ${expectedManual})`);
    check(`${label}: one bin per hand-added row in the editable slip, 0 in the read-only one`,
        edit.manualRemoveBtns === expectedManual && view.manualRemoveBtns === 0,
        `${edit.manualRemoveBtns} / ${view.manualRemoveBtns}`);
    check(`${label}: one pencil of its own per hand-added row in the editable slip, 0 in the read-only one`,
        edit.manualEditBtns === expectedManual && view.manualEditBtns === 0,
        `${edit.manualEditBtns} / ${view.manualEditBtns}`);
    check(`${label}: 2 "add a line" links in the editable slip, 0 in the read-only one`,
        edit.addButtons === 2 && view.addButtons === 0,
        `${edit.addButtons} vs ${view.addButtons}`);
    check(`${label}: ...each on a group HEAD row, with no add row left anywhere`,
        edit.addButtonsInGroupHead === 2 && edit.groupsWithLink === 2 && edit.addRows === 0 && view.addRows === 0,
        JSON.stringify({ head: edit.addButtonsInGroupHead, links: edit.groupsWithLink, rows: edit.addRows }));
    check(`${label}: ...one per manual group, never on any other group`,
        edit.groupsWithLink === 2 && edit.groupCount >= 5, `${edit.groupsWithLink} of ${edit.groupCount}`);
    check(`${label}: an empty manual group in the editable slip is exactly 1 <tr>`,
        edit.emptyManualGroupRows === edit.groupCount - view.groupCount,
        `${edit.emptyManualGroupRows} vs ${edit.groupCount} - ${view.groupCount}`);
    check(`${label}: the link ends on the same right edge every figure in the table ends on (+-1px)`,
        edit.addLinkRightGap && Math.abs(edit.addLinkRightGap.toInset) <= 1,
        JSON.stringify(edit.addLinkRightGap));
    check(`${label}: a group with rows renders the same rows in both slips`,
        expectedViewCodes.length === view.rowCount && edit.rowCount > 0,
        `${edit.rowCount} - ${silentTriState.length} vs ${view.rowCount}`);
    check(`${label}: ...one per manual group, each carrying its own type`,
        edit.addButtonTypes.slice().sort().join('|') === 'deduction|earning', JSON.stringify(edit.addButtonTypes));
    check(`${label}: the add row is a text link, not a filled button`,
        edit.addButtonStyle && edit.addButtonStyle.bg === 'rgba(0, 0, 0, 0)'
        && edit.addButtonStyle.borderColor === 'rgba(0, 0, 0, 0)'
        && edit.addButtonStyle.deco === 'none'
        && edit.addButtonStyle.color !== 'rgb(255, 255, 255)', JSON.stringify(edit.addButtonStyle));
    check(`${label}: the note of a hand-added line shows as its own sub-line`,
        edit.manualTags.filter(t => t.some(x => x.indexOf('k4a2 note') !== -1)).length === 2,
        JSON.stringify(edit.manualTags));
    check(`${label}: ...and no "system calculated" sub-line on any of them`,
        edit.manualTags.every(t => t.every(x => x.indexOf('30,') === -1 && !/^(ระบบ|System):/.test(x))),
        JSON.stringify(edit.manualTags));

    check(`${label}: the 3 totals are a block AFTER the scroller, with no table markup, in both slips`,
        edit.totalsIsBlockAfterScroller && view.totalsIsBlockAfterScroller
        && edit.totalsHasNoTableMarkup && view.totalsHasNoTableMarkup && edit.totals.length === 3,
        JSON.stringify({ e: edit.totalsIsBlockAfterScroller, v: view.totalsIsBlockAfterScroller,
            markupE: edit.totalsHasNoTableMarkup, markupV: view.totalsHasNoTableMarkup, n: edit.totals.length }));
    check(`${label}: ...with the same 3 figures in both`,
        edit.totals.map(t => t.amount).join('|') === view.totals.map(t => t.amount).join('|')
        && edit.totals.every(t => t.amount.length > 0), JSON.stringify({ e: edit.totals, v: view.totals }));
    check(`${label}: ...labelled, not bare figures`, view.totals.every(t => t.label.length > 0), JSON.stringify(view.totals));
    check(`${label}: no .ml-mount / .lo-totals-block / #breakdownNetSummary left in the DOM`,
        edit.retiredNodes === 0 && view.retiredNodes === 0, `${edit.retiredNodes} / ${view.retiredNodes}`);

    check(`${label}: a skipped line is not a row of either slip at all`,
        edit.skipped.length === 0 && view.skipped.length === 0
        && edit.enrolmentRows.every(r => r.amount !== '') && view.enrolmentRows.every(r => r.amount !== ''),
        JSON.stringify({ e: edit.skipped, v: view.skipped, enrol: edit.enrolmentRows }));
    check(`${label}: every round action button is exactly 32x32, calculated and hand-added alike`,
        edit.iconButtonSizes.length > 0 && edit.iconButtonSizes.every(b => b.w === 32 && b.h === 32),
        JSON.stringify(edit.iconButtonSizes));
    check(`${label}: ...and the hand-added rows really contribute 2 each`,
        edit.iconButtonSizes.filter(b => b.cls === 'manual').length === expectedManual * 2,
        JSON.stringify(edit.iconButtonSizes.filter(b => b.cls === 'manual').length));
    // The block's own 2 x's, not the table's: label on the x the group headings start on, figure on
    // the x the "add a line" links end on -- both the block's own inset (tiny-4b-fix1 v2).
    console.log(`  totals align: edit=${JSON.stringify(edit.totalsAlign)} view=${JSON.stringify(view.totalsAlign)}`);
    check(`${label}: each totals label starts on the x the group headings start on (+-1px), in both slips`,
        [edit, view].every(m => m.totalsAlign && m.totalsAlign.labelLeftDelta
            && m.totalsAlign.labelLeftDelta.length === 3 && m.totalsAlign.labelLeftDelta.every(d => Math.abs(d) <= 1)),
        JSON.stringify({ e: edit.totalsAlign && edit.totalsAlign.labelLeftDelta, v: view.totalsAlign && view.totalsAlign.labelLeftDelta }));
    check(`${label}: each totals figure ends on the block's own inset (+-1px), in both slips`,
        [edit, view].every(m => m.totalsAlign && m.totalsAlign.figureRightGap
            && m.totalsAlign.figureRightGap.length === 3 && m.totalsAlign.figureRightGap.every(g => g !== null && Math.abs(g) <= 1)),
        JSON.stringify({ e: edit.totalsAlign && edit.totalsAlign.figureRightGap, v: view.totalsAlign && view.totalsAlign.figureRightGap }));
    /* 2026-09-21, a0: was "an ordinary statutory row keeps its badge AND its formula sub-line".
       The formula half is a property of the EMPLOYEE, not of the slip: this fixture's own employee
       is not enrolled in SSO and is tax-exempt, so both statutory rows are 0 and the engine produced
       no formula for either. What the slip really owes is 4a-2's rule -- a statutory row says what
       it is, and a row rendered at 0 says why it is 0. */
    console.log(`  statutory rows: ${JSON.stringify(edit.statutoryNormal)}`);
    check(`${label}: every statutory row says what it is, and one at 0 says why`,
        edit.statutoryNormal.length > 0 && edit.statutoryNormal.every(r => r.badges >= 1)
        && edit.statutoryNormal.filter(r => r.amountValue === 0).every(r => r.rowTags > 0),
        JSON.stringify(edit.statutoryNormal));

    check(`${label}: the read-only slip still has no toggle/action column at all`,
        view.checkCells === 0 && view.actionCells === 0 && view.switches === 0,
        JSON.stringify({ c: view.checkCells, a: view.actionCells, s: view.switches }));
    check(`${label}: nothing is merely hidden in either slip`, edit.hidden === 0 && view.hidden === 0,
        `${edit.hidden} / ${view.hidden}`);
    if (opts.width === 430) {
        check(`${label}: the table scrolls sideways rather than overflowing the modal`,
            edit.overflowX >= 0 && view.overflowX >= 0 && edit.modalOverflowX <= 0 && view.modalOverflowX <= 0,
            JSON.stringify({ e: edit.overflowX, v: view.overflowX, em: edit.modalOverflowX, vm: view.modalOverflowX }));
        check(`${label}: the frozen 2nd column still knows where the 1st one ends`,
            edit.stickyLeft2 !== '' && view.stickyLeft2 !== '', `${edit.stickyLeft2} / ${view.stickyLeft2}`);
    }

    const rep = report();
    console.log(`  cell report: ${JSON.stringify({ blockedPreferenceSaves: rep.blockedPreferenceSaves, blockedRecalculates: rep.blockedRecalculates, consoleErrors: rep.consoleErrors.length, pageErrors: rep.pageErrors.length })}`);
    if (rep.consoleErrors.length) console.log(`  console: ${JSON.stringify(rep.consoleErrors.slice(0, 3))}`);
    if (rep.pageErrors.length) console.log(`  pageerr: ${JSON.stringify(rep.pageErrors.slice(0, 3))}`);
    check(`${label}: no page error`, rep.pageErrors.length === 0, JSON.stringify(rep.pageErrors.slice(0, 2)));
    return rep;
}

(async () => {
    const reports = [];
    const cells = (process.env.K4A2_CELLS === 'two')
        ? [['th', 'light', 1400], ['th', 'dark', 430]]
        : (process.env.K4A2_CELLS === 'one')
            ? [['th', 'light', 1400]]
            : [['th', 'light', 1400], ['th', 'light', 430], ['th', 'dark', 1400], ['th', 'dark', 430],
               ['en', 'light', 1400], ['en', 'light', 430], ['en', 'dark', 1400], ['en', 'dark', 430]];
    for (const [lang, colorScheme, width] of cells) {
        reports.push(await runCell({ width, height: width === 430 ? 930 : 950, lang, colorScheme }));
    }
    await closeAll();
    console.log(`\nblockedPreferenceSaves total: ${reports.reduce((a, r) => a + r.blockedPreferenceSaves, 0)}`);
    console.log(`blockedRecalculates per cell: ${JSON.stringify(reports.map(r => r.blockedRecalculates))}`);
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed === 0 ? 0 : 1);
})();
