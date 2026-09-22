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
const { openContext, closeAll, ensureCellTheme } = require('./harness');

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
    // 2026-09-21, a0: a cell named "dark" was measuring LIGHT -- see ensureCellTheme() in harness.js.
    if (!await ensureCellTheme(page, opts.colorScheme, { label, when: 'first load', check, log: console.log })) return report();
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
            // 2026-09-21, a0: 4b gave the 2 tri-state rows a sub-line in this same slot, from the
            // same template -- but carrying a WORD, because what was replaced there is a yes/no.
            // This round is about the FIGURE one, so the two are told apart by the row itself.
            answered: !!r.getAttribute('data-exemption-changed'),
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
    /* 2026-09-21, a0: which CODES carry a sub-line is a property of the run being measured, not of
       this rule -- `__base_salary__` and `LOAN_REPAY` were what one particular fixture happened to
       have, and every round that writes to the run (l6a, l6b) changes the set. The rule itself is
       "a figure sub-line appears exactly on the rows that carry an amount override", so the expected
       set is read off the rows and the endpoint instead of being named here. */
    const tagged = rendered.filter((r) => r.sub !== null && !r.answered);
    const untagged = rendered.filter((r) => r.sub === null);
    const overridden = rendered.filter((r) => r.action !== '');
    console.log(`  rows with a figure sub-line: ${JSON.stringify(tagged.map(r => r.code))} · with an override: ${JSON.stringify(overridden.map(r => r.code))}`);
    check(`${label}: the run has an overridden row to measure`, overridden.length > 0,
        JSON.stringify(rendered.map((r) => r.code)));
    check(`${label}: the figure sub-line appears on exactly the rows that carry an override`,
        tagged.map((r) => r.code).sort().join('|') === overridden.map((r) => r.code).sort().join('|'),
        `${JSON.stringify(tagged.map(r => r.code))} vs ${JSON.stringify(overridden.map(r => r.code))}`);
    check(`${label}: no untouched row carries one`,
        untagged.every((r) => r.action === '') && rendered.filter((r) => r.sub !== null && r.answered)
            .every((r) => !/\d/.test(r.sub)),
        JSON.stringify(untagged.filter((r) => r.action !== '')));

    tagged.forEach((r) => {
        const s = served[r.code];
        check(`${label}: ${r.code} is served a computed_amount`, s && s.computed !== null && s.computed !== undefined, JSON.stringify(s));
        if (s && s.computed !== null && s.computed !== undefined) {
            check(`${label}: ${r.code} sub-line "${r.sub}" == computed_amount ${s.computed}`,
                r.sub.indexOf(fmt(s.computed)) !== -1, `${r.sub} vs ${fmt(s.computed)}`);
        }
    });
    /* A row where the engine figure and the live figure genuinely differ -- proof the sub-line is not
       simply echoing the row's own amount back. 2026-09-21, a0: found among the rows that are
       really there rather than named (`LOAN_REPAY` is not a line of this fixture's employee at all,
       so this check has been reporting "row missing" instead of measuring anything). With none to
       find it says so out loud rather than passing on an empty set. */
    const differing = tagged.filter((r) => r.sub.indexOf(r.amount) === -1);
    check(`${label}: at least one sub-line differs from its own row's live figure`,
        differing.length > 0, JSON.stringify(tagged.map((r) => ({ code: r.code, sub: r.sub, amount: r.amount }))));

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
