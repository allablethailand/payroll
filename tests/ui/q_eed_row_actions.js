/**
 * ปุ่มแถว EED B1 measurement -- eedActionButtons() (public/js/employee/detail.js, redesigned
 * 2026-09-23 per rules.md §7 L1573-1580 + demo docs/design/components.php:1060-1094): <=3 circular
 * `.btn-icon` buttons outside a row, Edit+Delete fold into one ⋮ dropdown (only while
 * current_installment=0), Delete last under a divider in --c-danger.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/q_eed_row_actions.js <PHPSESSID>
 *
 * READ-ONLY. EM009 (employee 159) is the only fixture with real EED rows (4, all
 * status=active/current_installment=0 -- the "not started" case, docs/decisions/2026-09-23-eed-row-actions.md's
 * own table). Every write endpoint this row's buttons can reach is aborted at the network by the
 * harness (BLOCK_PATHS below); c3/c4 assert the count stays 0 even after opening+cancelling a Swal
 * confirm. Nothing is saved, no status changed, no run opened.
 *
 * 8 cells, each its own context (ONLY_CELLS=n[,n...] to isolate one while iterating):
 *   1  1400 th light   real row: 3 outside + ⋮, uniform size/colour (getBoundingClientRect/computed
 *                       color across all 4 round buttons incl. the ⋮ toggle itself)
 *   2  1400 th light   ⋮ opens, menu = [Edit, divider, Delete] in order, strategy:'fixed' + in-viewport
 *   3  1400 th light   "ลบ" in menu -> Swal opens -> Cancel -> 0 requests to the delete endpoint
 *   4  1400 th light   "ยกเลิก" outside -> Swal opens -> Cancel -> 0 requests to the status endpoint
 *   5  1400 th light   keyboard: Tab to ⋮, Enter opens, ArrowDown moves, Esc closes + focus returns
 *   6  1400 th+en light aria-label/title of ⋮ and menu items match langData, both languages
 *   7  430  th dark    buttons don't overflow the cell, ⋮ menu stays in viewport
 *   8  1400 th light   both tableEarning and tableDeduction -- whichever has 0 rows is NOTEd, not failed
 */
'use strict';
const { openContext, closeAll, applyAppTheme } = require('./harness');

const sessionId = process.argv[2];
if (!sessionId) {
    throw new Error('usage: node tests/ui/q_eed_row_actions.js <PHPSESSID>');
}

const EMPLOYEE_NO = 'EM009';
// Everything the EED row's own buttons (outside + ⋮) can reach. Named here, not in the harness --
// which endpoint is dangerous belongs to the page being measured (same convention as k4c_employee_detail.js).
const BLOCK_PATHS = [
    '/api/employee.earning-deduction.save',
    '/api/employee.earning-deduction.delete',
    '/api/employee.earning-deduction.status',
];

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

async function open(opts) {
    return openContext(Object.assign({ sessionId, blockPaths: BLOCK_PATHS }, opts));
}
async function gotoEmployee(ctx, lang) {
    await ctx.page.goto(ctx.url('/employees/' + EMPLOYEE_NO), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
    await ctx.page.waitForTimeout(700);
}
async function showTab(page, id) {
    await page.evaluate((i) => bootstrap.Tab.getOrCreateInstance(document.getElementById(i)).show(), id);
    await page.waitForTimeout(900);
}
async function reportClean(ctx, cell, extraOk) {
    const r = ctx.report();
    check(`${cell}: nothing this page did had to be blocked${extraOk === false ? ' (see cell assertion above for the ONE expected exception)' : ''}`,
        extraOk === false ? true : r.blockedWrites === 0, `${r.blockedWrites} (${r.blockedWritePaths.join(', ')})`);
    check(`${cell}: no page errors`, r.pageErrors.length === 0, r.pageErrors.join(' | '));
    check(`${cell}: no console errors`, r.consoleErrors.length === 0, r.consoleErrors.slice(0, 2).join(' | '));
}
async function openDeductionTab(ctx, lang) {
    await gotoEmployee(ctx, lang || 'th');
    await showTab(ctx.page, 'earningDeduction-tab');
    await showTab(ctx.page, 'eedDeductionSub-tab');
    await ctx.page.waitForSelector('#tableDeduction tbody tr', { timeout: 20000 });
    await ctx.page.waitForTimeout(600);
}
// The first real row's action cell, measured once and reused by several cells.
async function measureRow1(page) {
    return page.evaluate(() => {
        const row = document.querySelector('#tableDeduction tbody tr');
        if (!row) return null;
        const cell = row.querySelector('td:last-child');
        const outerBtns = Array.from(cell.querySelectorAll(':scope > div > button, .d-flex > button'));
        const toggle = cell.querySelector('.dropdown-toggle');
        const all = toggle ? outerBtns.concat([toggle]) : outerBtns;
        const rectOf = (el) => { const r = el.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) }; };
        return {
            outerClasses: outerBtns.map(b => b.className),
            outerRects: outerBtns.map(rectOf),
            hasToggle: !!toggle,
            toggleRect: toggle ? rectOf(toggle) : null,
            allColors: all.map(b => getComputedStyle(b).color),
            anyColoredClass: all.some(b => /text-(info|warning|success|danger)/.test(b.className)),
        };
    });
}

/* ---------------------------------------------------------------- cell 1 */
async function cell1() {
    console.log('\n=== 1. 1400 th light -- real EED row: 3 outside + ⋮, uniform size/colour ===');
    const ctx = await open({ width: 1400, height: 950 });
    await openDeductionTab(ctx);
    const m = await measureRow1(ctx.page);
    check('row 1 found', m !== null);
    if (m) {
        check('3 buttons outside the ⋮', m.outerRects.length === 3, m.outerClasses.join(' | '));
        check('a ⋮ toggle is present (row is not-started: current_installment=0)', m.hasToggle === true);
        check('no per-action colour class survives on any round button', m.anyColoredClass === false, m.outerClasses.join(' | '));
        const sizes = m.outerRects.concat(m.toggleRect ? [m.toggleRect] : []);
        check('every round button (incl. ⋮) is the same size',
            sizes.every(s => s.w === sizes[0].w && s.h === sizes[0].h), JSON.stringify(sizes));
        check('every round button (incl. ⋮) has the same icon/text colour',
            m.allColors.every(c => c === m.allColors[0]), JSON.stringify(m.allColors));
    }
    await reportClean(ctx, 'cell 1');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 2 */
async function cell2() {
    console.log('\n=== 2. 1400 th light -- ⋮ opens, [Edit, divider, Delete], not clipped ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await openDeductionTab(ctx);
    await page.click('#tableDeduction tbody tr:first-child .dropdown-toggle');
    await page.waitForTimeout(400);
    const menu = await page.evaluate(() => {
        const ul = document.querySelector('#tableDeduction tbody tr .dropdown-menu.show');
        if (!ul) return null;
        const items = Array.from(ul.querySelectorAll(':scope > li'));
        const kinds = items.map(li => li.querySelector('hr.dropdown-divider') ? 'DIVIDER'
            : (li.querySelector('.btn-edit-eed') ? 'edit' : (li.querySelector('.btn-delete-eed') ? 'delete' : '?')));
        const r = ul.getBoundingClientRect();
        const deleteItem = ul.querySelector('.btn-delete-eed');
        return {
            kinds,
            rect: { left: r.left, top: r.top, right: r.right, bottom: r.bottom },
            position: getComputedStyle(ul).position,
            deleteHasDangerClass: deleteItem ? deleteItem.className.indexOf('text-danger') !== -1 : false,
            viewport: { w: window.innerWidth, h: window.innerHeight },
        };
    });
    check('menu opened', menu !== null);
    if (menu) {
        check('menu order is [Edit, DIVIDER, Delete]', menu.kinds.join(',') === 'edit,DIVIDER,delete', menu.kinds.join(','));
        check('Delete carries text-danger (§7: "รายการลบอยู่ล่างสุด...text-danger")', menu.deleteHasDangerClass === true);
        // 2026-09-23, measured live: FAILS as of this commit -- real bug, confirmed, in shared app.js
        // (out of scope for this round, see the report). applyFixedStrategyToTableDropdowns()'s own
        // wrapper guard (app.js:814) only checks `.closest('.dataTables_wrapper, .table-responsive')`,
        // but #tableDeduction's real DOM ancestor is `.dt-container` (DataTables 2.x's wrapper class
        // -- app.js ITSELF already knows this at lines 1049/1080, `.closest('.dataTables_wrapper, .dt-container')`)
        // -- so the guard's `.length` is 0, `return`s early, and this ⋮ never gets strategy:'fixed' at all.
        check('menu uses position:fixed (applyFixedStrategyToTableDropdowns, app.js -- no extra wiring needed)',
            menu.position === 'fixed', menu.position);
        check('menu is fully inside the viewport, not clipped by .table-responsive',
            menu.rect.left >= 0 && menu.rect.top >= 0 && menu.rect.right <= menu.viewport.w && menu.rect.bottom <= menu.viewport.h,
            JSON.stringify(menu));
    }
    await reportClean(ctx, 'cell 2');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 3 */
async function cell3() {
    console.log('\n=== 3. 1400 th light -- "ลบ" in ⋮ -> Swal -> Cancel -> 0 requests ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await openDeductionTab(ctx);
    await page.click('#tableDeduction tbody tr:first-child .dropdown-toggle');
    await page.waitForTimeout(300);
    await page.click('#tableDeduction tbody tr:first-child .btn-delete-eed');
    await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 10000 });
    const beforeCancel = ctx.report().blockedWrites;
    check('Swal confirm opened for Delete, no request fired yet', beforeCancel === 0, String(beforeCancel));
    await page.click('.swal2-cancel');
    await page.waitForTimeout(500);
    const after = ctx.report();
    check('after Cancel: still 0 requests to any EED write endpoint', after.blockedWrites === 0, JSON.stringify(after.blockedWritePaths));
    check('Swal is gone', await page.locator('.swal2-popup').count() === 0);
    await reportClean(ctx, 'cell 3');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 4 */
async function cell4() {
    console.log('\n=== 4. 1400 th light -- "ยกเลิก" outside -> Swal -> Cancel -> 0 requests ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await openDeductionTab(ctx);
    await page.click('#tableDeduction tbody tr:first-child .btn-eed-status[data-status="cancelled"]');
    await page.waitForSelector('.swal2-popup', { state: 'visible', timeout: 10000 });
    const beforeCancel = ctx.report().blockedWrites;
    check('Swal confirm opened for Cancel, no request fired yet', beforeCancel === 0, String(beforeCancel));
    await page.click('.swal2-cancel');
    await page.waitForTimeout(500);
    const after = ctx.report();
    check('after Cancel: still 0 requests to the status endpoint', after.blockedWrites === 0, JSON.stringify(after.blockedWritePaths));
    await reportClean(ctx, 'cell 4');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 5 */
async function cell5() {
    console.log('\n=== 5. 1400 th light -- keyboard: Tab to ⋮, Enter, ArrowDown, Esc ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await openDeductionTab(ctx);
    // Start focus at row 1's View button, then Tab across Pause/Resume + Cancel to reach ⋮ (3 hops).
    await page.evaluate(() => document.querySelector('#tableDeduction tbody tr:first-child .btn-view-eed').focus());
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    const onToggle = await page.evaluate(() => document.activeElement.classList.contains('dropdown-toggle'));
    check('3 Tabs from "ดู" lands on the ⋮ toggle', onToggle === true);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(300);
    check('Enter opens the menu', await page.locator('#tableDeduction tbody tr:first-child .dropdown-menu.show').count() === 1);
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(200);
    const onFirstItem = await page.evaluate(() => document.activeElement.classList.contains('btn-edit-eed'));
    check('ArrowDown moves focus onto the first item (Edit)', onFirstItem === true);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    const closedAndReturned = await page.evaluate(() => ({
        open: document.querySelectorAll('#tableDeduction tbody tr:first-child .dropdown-menu.show').length,
        onToggle: document.activeElement.classList.contains('dropdown-toggle'),
    }));
    check('Esc closes the menu', closedAndReturned.open === 0);
    check('...and returns focus to the ⋮ toggle', closedAndReturned.onToggle === true);
    await reportClean(ctx, 'cell 5');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 6 */
async function cell6() {
    console.log('\n=== 6. 1400 th+en light -- ⋮/menu text matches langData ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    for (const lang of ['th', 'en']) {
        await openDeductionTab(ctx, lang);
        const got = await page.evaluate(() => {
            const toggle = document.querySelector('#tableDeduction tbody tr:first-child .dropdown-toggle');
            const edit = document.querySelector('#tableDeduction tbody tr:first-child .btn-edit-eed');
            const del = document.querySelector('#tableDeduction tbody tr:first-child .btn-delete-eed');
            return {
                toggleTitle: toggle ? toggle.getAttribute('title') : null,
                toggleAria: toggle ? toggle.getAttribute('aria-label') : null,
                editText: edit ? edit.textContent.trim() : null,
                deleteText: del ? del.textContent.trim() : null,
                expectedMore: langData['action_more'] || 'More',
                expectedEdit: langData['edit'] || 'Edit',
                expectedDelete: langData['delete'] || 'Delete',
            };
        });
        check(`[${lang}] ⋮ title = langData.action_more ("${got.expectedMore}")`, got.toggleTitle === got.expectedMore, got.toggleTitle);
        check(`[${lang}] ⋮ aria-label = langData.action_more`, got.toggleAria === got.expectedMore, got.toggleAria);
        check(`[${lang}] menu "Edit" text = langData.edit ("${got.expectedEdit}")`, got.editText && got.editText.indexOf(got.expectedEdit) !== -1, got.editText);
        check(`[${lang}] menu "Delete" text = langData.delete ("${got.expectedDelete}")`, got.deleteText && got.deleteText.indexOf(got.expectedDelete) !== -1, got.deleteText);
    }
    await reportClean(ctx, 'cell 6');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 7 */
async function cell7() {
    console.log('\n=== 7. 430 th dark -- buttons fit the cell, ⋮ menu stays in viewport ===');
    const ctx = await open({ width: 430, height: 900 });
    const page = ctx.page;
    await openDeductionTab(ctx);
    const theme = await applyAppTheme(page, 'dark');
    check('page really is in the dark theme', theme.ok === true, theme.stamp);
    if (theme.ok) {
        await page.waitForTimeout(300);
        const overflow = await page.evaluate(() => {
            const cell = document.querySelector('#tableDeduction tbody tr:first-child td:last-child');
            const row = document.querySelector('#tableDeduction tbody tr:first-child');
            const cellR = cell.getBoundingClientRect();
            const rowR = row.getBoundingClientRect();
            return { cellFits: cellR.right <= rowR.right + 1, cellWidth: Math.round(cellR.width), rowWidth: Math.round(rowR.width) };
        });
        check('the action cell does not push past its own row at 430px', overflow.cellFits === true, JSON.stringify(overflow));
        await page.click('#tableDeduction tbody tr:first-child .dropdown-toggle');
        await page.waitForTimeout(400);
        const menu = await page.evaluate(() => {
            const ul = document.querySelector('#tableDeduction tbody tr .dropdown-menu.show');
            if (!ul) return null;
            const r = ul.getBoundingClientRect();
            return { rect: r, viewport: { w: window.innerWidth, h: window.innerHeight } };
        });
        check('⋮ menu opened at 430px', menu !== null);
        if (menu) {
            check('menu stays inside the 430px viewport',
                menu.rect.left >= 0 && menu.rect.right <= menu.viewport.w, JSON.stringify(menu));
        }
    }
    await reportClean(ctx, 'cell 7');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 8 */
async function cell8() {
    console.log('\n=== 8. 1400 th light -- both tableEarning and tableDeduction ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await gotoEmployee(ctx, 'th');
    await showTab(page, 'earningDeduction-tab');

    await showTab(page, 'eedEarningSub-tab');
    await page.waitForTimeout(700);
    // DataTables' own empty-state row (`<tr><td class="dt-empty">...` -- confirmed live, DataTables
    // 2.x's dt-empty class, see the app.js:814 finding below) still counts as 1 `tbody tr`, so a plain
    // row count cannot tell "no data" apart from "1 real row" -- exclude it explicitly.
    const earningRows = await page.locator('#tableEarning tbody tr').filter({ hasNot: page.locator('td.dt-empty') }).count();
    if (earningRows === 0) {
        console.log('  NOTE  tableEarning has 0 rows for EM009 -- confirmed by SELECT during round A: all 4 real'
            + ' employee_earning_deductions rows in this dev DB are item_type=deduction (ids 1396-1399, employee_id=159).'
            + ' Not a failure -- there is simply no earning-side fixture to measure the row markup against.');
    } else {
        const m = await measureRow1(page);
        // measureRow1() reads #tableDeduction specifically; re-check against #tableEarning here.
        const mEarning = await page.evaluate(() => {
            const row = document.querySelector('#tableEarning tbody tr');
            const cell = row.querySelector('td:last-child');
            const outer = Array.from(cell.querySelectorAll('.d-flex > button'));
            return { count: outer.length, hasToggle: !!cell.querySelector('.dropdown-toggle') };
        });
        check('tableEarning row also renders <=3 outside buttons', mEarning.count <= 3, String(mEarning.count));
    }

    await showTab(page, 'eedDeductionSub-tab');
    await page.waitForSelector('#tableDeduction tbody tr', { timeout: 20000 });
    await page.waitForTimeout(600);
    const deductionRows = await page.locator('#tableDeduction tbody tr').count();
    check('tableDeduction has real EM009 rows to measure', deductionRows > 0, String(deductionRows));
    const m2 = await measureRow1(page);
    check('tableDeduction row 1: <=3 outside buttons + ⋮ for the not-started case', m2 && m2.outerRects.length === 3 && m2.hasToggle === true, JSON.stringify(m2));

    await reportClean(ctx, 'cell 8');
    await closeAll();
}

const CELLS = [cell1, cell2, cell3, cell4, cell5, cell6, cell7, cell8];
const only = String(process.env.ONLY_CELLS || '').split(',').map(v => v.trim()).filter(Boolean).map(Number);

(async () => {
    try {
        for (let i = 0; i < CELLS.length; i++) {
            if (only.length && only.indexOf(i + 1) === -1) continue;
            await CELLS[i]();
        }
    } catch (e) {
        failed++;
        console.log('  FAIL  round threw -- ' + (e && e.stack ? e.stack : e));
        await closeAll();
    }
    console.log('\n' + '-'.repeat(50));
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed ? 1 : 0);
})();
