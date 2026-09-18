/**
 * tiny-C measurement: the "ระบบ: x" sub-line now prints the ENGINE figure that recalculate()
 * persisted (`computed_amount` / `base_salary_computed_amount`), not the override-history stand-in.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/tinyc_computed_amount.js <PHPSESSID> <runToken> <employeeId>
 *
 * Read-only against run 752 / EM009: it opens the Calculation Breakdown modal and compares what the
 * table renders with what api/payroll-run.sync-lines-for-employee answers for the same rows. It
 * writes nothing.
 *
 * 2 cells (a logic round): 1400 th light, 430 th dark.
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
const employeeId = process.argv[4];
if (!sessionId || !runToken || !employeeId) {
    throw new Error('usage: node tests/ui/tinyc_computed_amount.js <PHPSESSID> <runToken> <employeeId>');
}

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}${extra !== undefined ? ' -- ' + extra : ''}`); }
}

const WRAP = '#breakdownLineOverrideWrap';

async function runCell(opts) {
    const label = `${opts.width}x${opts.height} ${opts.lang} ${opts.colorScheme}`;
    console.log(`\n=== ${label} ===`);
    const ctx = await openContext({ sessionId, width: opts.width, height: opts.height, colorScheme: opts.colorScheme });
    const { page, report } = ctx;
    await ctx.context.addInitScript((lang) => {
        try { localStorage.setItem('preferred_language', lang); } catch (e) { /* private mode */ }
    }, opts.lang);
    await page.goto(ctx.url(`/payroll-process/${runToken}`), { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
    await page.waitForSelector(`.btn-view-breakdown[data-employee-id="${employeeId}"]`, { state: 'attached', timeout: 30000 });
    await page.evaluate((id) => document.querySelector(`.btn-view-breakdown[data-employee-id="${id}"]`).click(), employeeId);
    await page.waitForSelector(`${WRAP} tr.lo-row`, { timeout: 30000 });
    await page.waitForTimeout(800);

    // What the table renders, per row.
    const rendered = await page.evaluate((wrap) => Array.from(document.querySelectorAll(wrap + ' tr.lo-row')).map((r) => {
        const tag = r.querySelector('td.lo-amount-cell .payslip-line-tag');
        return {
            code: r.getAttribute('data-item-code'),
            action: r.getAttribute('data-orig-action') || '',
            amount: r.getAttribute('data-amount'),
            sub: tag ? tag.textContent.trim() : null,
        };
    }), WRAP);

    // What the endpoint the table was drawn from actually answered, for the same rows.
    // BASE_URL/PAYROLL_RUN_ID are top-level `const`s of the page's own scripts (global lexical
    // scope, not properties of window) -- referenced bare, the way the page's own code does.
    const served = await page.evaluate(async (empId) => {
        const res = await fetch(`${BASE_URL}/api/payroll-run.sync-lines-for-employee?run_id=${PAYROLL_RUN_ID}&employee_id=${empId}`, { credentials: 'same-origin' });
        const json = await res.json();
        const out = {};
        (json.data || []).forEach((l) => { out[l.code] = { computed: l.computed_amount, current: l.current_amount, action: l.override_action }; });
        return out;
    }, employeeId);

    const fmt = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const tagged = rendered.filter((r) => r.sub !== null);
    const untagged = rendered.filter((r) => r.sub === null);

    check(`${label}: exactly 2 rows carry the sub-line`, tagged.length === 2, JSON.stringify(tagged.map((r) => r.code)));
    check(`${label}: they are the 2 rows that really carry an override`,
        ['__base_salary__', 'LOAN_REPAY'].every((c) => tagged.some((r) => r.code === c)),
        JSON.stringify(tagged.map((r) => r.code)));
    check(`${label}: no other row carries one`, untagged.every((r) => r.action === ''),
        JSON.stringify(untagged.filter((r) => r.action !== '')));

    tagged.forEach((r) => {
        const s = served[r.code];
        check(`${label}: ${r.code} is served a computed_amount`, s && s.computed !== null && s.computed !== undefined, JSON.stringify(s));
        if (s && s.computed !== null && s.computed !== undefined) {
            check(`${label}: ${r.code} sub-line "${r.sub}" == computed_amount ${s.computed}`,
                r.sub.indexOf(fmt(s.computed)) !== -1, `${r.sub} vs ${fmt(s.computed)}`);
        }
    });
    // The one row where the engine figure and the live figure genuinely differ -- proof the sub-line
    // is not simply echoing the row's own amount back.
    const loan = tagged.find((r) => r.code === 'LOAN_REPAY');
    check(`${label}: LOAN_REPAY sub-line differs from its own live figure`,
        loan && loan.sub.indexOf(loan.amount) === -1, loan ? `${loan.sub} / ${loan.amount}` : 'row missing');

    const rep = report();
    console.log(`  cell report: ${JSON.stringify(rep)}`);
    return rep;
}

(async () => {
    const reports = [];
    reports.push(await runCell({ width: 1400, height: 950, lang: 'th', colorScheme: 'light' }));
    reports.push(await runCell({ width: 430, height: 930, lang: 'th', colorScheme: 'dark' }));
    await closeAll();
    console.log(`\nblockedPreferenceSaves total: ${reports.reduce((a, r) => a + r.blockedPreferenceSaves, 0)}`);
    console.log(`Passed: ${passed}, Failed: ${failed}`);
    process.exit(failed === 0 ? 0 : 1);
})();
