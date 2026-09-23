/**
 * Shared-fix regression: applyFixedStrategyToTableDropdowns()'s wrapper guard (app.js:814) was
 * missing `.dt-container` (DataTables 2.x's wrapper class -- this app bundles DataTables 2.3.8
 * app-wide, confirmed via node_modules/datatables.net/package.json, so `.dataTables_wrapper` never
 * actually occurs in this app's live DOM) alongside the DT 1.x class it already checked. Found live
 * while measuring ปุ่มแถว EED B1 (tests/ui/q_eed_row_actions.js cell 2 failed: menu position was
 * 'absolute', not 'fixed'). Fixed 2026-09-23, ปุ่มแถว EED B3, 1 line -- see
 * docs/decisions/2026-09-23-eed-row-actions.md's own "shared bug" section.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/r_table_dropdown_fixed.js <PHPSESSID>
 *
 * READ-ONLY. Every consumer here is opened, measured, and closed with Esc -- no menu ITEM is ever
 * clicked, so no export/preview/delete/save request is ever attempted; blockPaths still covers the
 * write endpoints behind each of these pages as a second layer.
 *
 * 5 cells, each its own context. 4 are the pages whose behaviour genuinely CHANGES (no
 * `.table-responsive` ancestor -- the `.dt-container` guard is the only thing that now catches them);
 * 1 proves the fix is a strict ADD, not a behaviour change, for a page that was already working via
 * the pre-existing `.table-responsive` clause:
 *   1  Employee Detail (EM009)            #tableDeduction  -- ⋮ (changes: no .table-responsive)
 *   2  Payroll Process list                #tb_payroll_run  -- Export ⋮ (changes: no .table-responsive)
 *   3  Document & Approval > Payslip Template   #tb_pst_template -- Preview ⋮ (changes)
 *   4  Document & Approval > Employment Certificate Template  #tb_ect_template -- Preview ⋮ (changes)
 *   5  Reports > Per-Cycle                #tb_cycle_matrix -- format-picker ⋮ (OLD MODE: already
 *      `.table-responsive`-wrapped, docs/design's own `docs/design/rules.md` §7 demo notwithstanding
 *      -- must stay 'fixed' exactly as it already was, proving the added `.dt-container` clause is
 *      additive and touches nothing that already worked)
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const sessionId = process.argv[2];
if (!sessionId) {
    throw new Error('usage: node tests/ui/r_table_dropdown_fixed.js <PHPSESSID>');
}

// Every write endpoint any of these 5 pages' dropdown menus could reach -- named here, not in the
// harness, same convention as k4c_employee_detail.js/q_eed_row_actions.js. No menu ITEM is ever
// clicked in this round, so none of these should ever actually fire; they are a second layer.
const WRITE_PATHS = [
    '/api/employee.earning-deduction.save',
    '/api/employee.earning-deduction.delete',
    '/api/employee.earning-deduction.status',
    '/api/payroll-run.save',
    '/api/payroll-run.delete',
    '/api/employment-certificate-template.save',
    '/api/employment-certificate-template.delete',
    '/api/employment-certificate-template.duplicate-pair',
    '/api/payslip-template.save',
    '/api/payslip-template.delete',
    '/api/payslip-template.duplicate-pair',
];

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

async function open(opts) {
    return openContext(Object.assign({ sessionId, blockPaths: WRITE_PATHS }, opts));
}
async function reportClean(ctx, cell) {
    const r = ctx.report();
    check(`${cell}: nothing had to be blocked (no menu item was ever clicked)`, r.blockedWrites === 0, `${r.blockedWrites} (${r.blockedWritePaths.join(', ')})`);
    check(`${cell}: no page errors`, r.pageErrors.length === 0, r.pageErrors.join(' | '));
    check(`${cell}: no console errors`, r.consoleErrors.length === 0, r.consoleErrors.slice(0, 2).join(' | '));
}

// The one thing every cell measures. `toggle` is a Playwright Locator already resolved to exactly
// one element -- opens it, reads the ONE resulting `.dropdown-menu.show` (never more than one open
// at a time in this app), closes it with Esc, and reports both states.
async function measureDropdown(page, toggle) {
    await toggle.click();
    await page.waitForTimeout(400);
    const opened = await page.evaluate(() => {
        const menu = document.querySelector('.dropdown-menu.show');
        if (!menu) return null;
        const r = menu.getBoundingClientRect();
        return {
            position: getComputedStyle(menu).position,
            rect: { left: r.left, top: r.top, right: r.right, bottom: r.bottom },
            viewport: { w: window.innerWidth, h: window.innerHeight },
        };
    });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    const closedCount = await page.locator('.dropdown-menu.show').count();
    return { opened, stillOpenAfterEsc: closedCount > 0 };
}
function assertFixedAndInViewport(cell, m) {
    check(`${cell}: menu opened`, m.opened !== null);
    if (!m.opened) return;
    check(`${cell}: strategy is 'fixed' (applyFixedStrategyToTableDropdowns, app.js:814)`,
        m.opened.position === 'fixed', m.opened.position);
    check(`${cell}: menu is fully inside the viewport`,
        m.opened.rect.left >= 0 && m.opened.rect.top >= 0
        && m.opened.rect.right <= m.opened.viewport.w && m.opened.rect.bottom <= m.opened.viewport.h,
        JSON.stringify(m.opened));
    check(`${cell}: Esc closes the menu`, m.stillOpenAfterEsc === false);
}

/* ---------------------------------------------------------------- cell 1: Employee Detail (EED) */
async function cell1() {
    console.log('\n=== 1. Employee Detail (EM009) #tableDeduction ⋮ -- changes (no .table-responsive) ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await page.goto(ctx.url('/employees/EM009'), { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => bootstrap.Tab.getOrCreateInstance(document.getElementById('earningDeduction-tab')).show());
    await page.waitForTimeout(900);
    await page.evaluate(() => bootstrap.Tab.getOrCreateInstance(document.getElementById('eedDeductionSub-tab')).show());
    await page.waitForTimeout(900);
    await page.waitForSelector('#tableDeduction tbody tr .dropdown-toggle', { timeout: 20000 });
    const m = await measureDropdown(page, page.locator('#tableDeduction tbody tr:first-child .dropdown-toggle'));
    assertFixedAndInViewport('cell 1', m);
    await reportClean(ctx, 'cell 1');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 2: Payroll Process list */
async function cell2() {
    console.log('\n=== 2. Payroll Process list #tb_payroll_run Export ⋮ -- changes (no .table-responsive) ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await page.goto(ctx.url('/payroll-process'), { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    const found = await page.locator('#tb_payroll_run tbody tr .dropdown-toggle').count();
    check('cell 2: at least one run row has an Export ⋮', found > 0, String(found));
    if (found > 0) {
        const m = await measureDropdown(page, page.locator('#tb_payroll_run tbody tr .dropdown-toggle').first());
        assertFixedAndInViewport('cell 2', m);
    }
    await reportClean(ctx, 'cell 2');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 3: Payslip Template list */
async function cell3() {
    console.log('\n=== 3. Document & Approval > Payslip Template #tb_pst_template Preview ⋮ -- changes ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await page.goto(ctx.url('/payslip-documents/settings'), { waitUntil: 'networkidle' });
    await page.waitForTimeout(900); // #tab-tpl is the default-active tab, no click needed
    await page.waitForSelector('#tb_pst_template tbody tr .dropdown-toggle', { timeout: 20000 });
    const m = await measureDropdown(page, page.locator('#tb_pst_template tbody tr:first-child .dropdown-toggle').first());
    assertFixedAndInViewport('cell 3', m);
    await reportClean(ctx, 'cell 3');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 4: Employment Certificate Template list */
async function cell4() {
    console.log('\n=== 4. Document & Approval > Employment Certificate Template #tb_ect_template Preview ⋮ -- changes ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await page.goto(ctx.url('/payslip-documents/settings'), { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => bootstrap.Tab.getOrCreateInstance(document.getElementById('employmentCertificateTemplateTabBtn')).show());
    await page.waitForTimeout(900);
    await page.waitForSelector('#tb_ect_template tbody tr .dropdown-toggle', { timeout: 20000 });
    const m = await measureDropdown(page, page.locator('#tb_ect_template tbody tr:first-child .dropdown-toggle').first());
    assertFixedAndInViewport('cell 4', m);
    await reportClean(ctx, 'cell 4');
    await closeAll();
}

/* ---------------------------------------------------------------- cell 5: Reports > Per-Cycle (old mode) */
async function cell5() {
    console.log('\n=== 5. Reports > Per-Cycle #tb_cycle_matrix ⋮ -- OLD MODE, must stay unchanged (.table-responsive) ===');
    const ctx = await open({ width: 1400, height: 950 });
    const page = ctx.page;
    await page.goto(ctx.url('/reports'), { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200); // cycle-pane is the default-active tab; loadCycleRunsMatrix() fires on document.ready
    const count = await page.locator('#tb_cycle_matrix tbody tr .dropdown-toggle').count();
    if (count === 0) {
        console.log('  NOTE  #tb_cycle_matrix has no row with an applicable-report dropdown right now (every'
            + ' completed run in the default date-filter window has 0 applicable_codes, or there is no'
            + ' completed run at all) -- cannot measure the "old mode still works" case live this round.'
            + ' Confirmed via read-only SELECT during B3: payroll_runs has real state IN (approved,paid,locked)'
            + ' rows for comp_id=1 (e.g. TDI-2026-00019/17/11) independent of any mksession fixture, so a real'
            + ' cell is expected to exist -- if this NOTE fires, the date filter or applicable_codes logic is'
            + ' worth a second look, not silently accepted as "nothing to test".');
    } else {
        const m = await measureDropdown(page, page.locator('#tb_cycle_matrix tbody tr .dropdown-toggle').first());
        assertFixedAndInViewport('cell 5', m);
    }
    await reportClean(ctx, 'cell 5');
    await closeAll();
}

const CELLS = [cell1, cell2, cell3, cell4, cell5];
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
