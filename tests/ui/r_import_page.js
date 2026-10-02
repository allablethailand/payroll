/**
 * Data Import page (/imports): upload -> match columns -> check data -> fix + re-validate -> discard.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/r_import_page.js <PHPSESSID> <runToken>
 *
 * 2 cells: 1400 th light, 430 en dark. Each drives the whole flow on a 3-row attendance CSV
 * (2 good rows dated 2099, 1 row with an unknown employee_no).
 * Writes: staging rows + import_audit_logs only. api/import.commit is in blockPaths, so the flow
 * ends at Discard and nothing reaches attendance_records.
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { openContext, closeAll, applyAppTheme } = require('./harness');

const sessionId = process.argv[2];
const runToken = process.argv[3];
if (!sessionId || !runToken) throw new Error('usage: node tests/ui/r_import_page.js <PHPSESSID> <runToken>');

const csv = path.join(os.tmpdir(), 'import_page_test.csv');
fs.writeFileSync(csv, [
    'EmpCode,Work Date (YYYY-MM-DD),Status (present/absent/leave/holiday)',
    'CEO,2099-01-05,present',
    'NOPE-000,2099-01-05,present',
    'CEO,2099-01-06,present',
].join('\n'));

const results = [];
function check(cell, label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    results.push(ok);
    console.log(`${ok ? 'PASS' : 'FAIL'}  [${cell}] ${label}${ok ? '' : `  expected ${JSON.stringify(expected)} got ${JSON.stringify(actual)}`}`);
}

async function cell(name, opts) {
    const { page, report } = await openContext({ sessionId, blockPaths: ['/api/import.commit'], ...opts });
    await page.context().addInitScript((l) => { try { localStorage.setItem('preferred_language', l); } catch (e) { /* storage blocked */ } }, opts.lang);
    await page.goto(`${process.env.UI_BASE_URL}/imports`, { waitUntil: 'networkidle' });
    if (opts.colorScheme === 'dark') await applyAppTheme(page, 'dark');

    check(name, 'page crumb', (await page.textContent('#phBreadcrumbCurrent')).trim().length > 0, true);
    check(name, 'two tabs', await page.locator('#imp-upload-tab, #imp-log-tab').count(), 2);
    check(name, 'no page-header primary button', await page.locator('#phActions .btn-primary').count(), 0);

    await page.setInputFiles('#impFile', csv);
    await page.click('#impUploadBtn');
    await page.waitForSelector('#impWizardModal.show');
    check(name, 'mapping rows (one per file column)', await page.locator('#tb_import_mapping tbody tr').count(), 3);
    const preset = await page.$$eval('.imp-map-select', els => els.map(e => e.value));
    check(name, 'auto-matched by label, EmpCode left empty', preset, ['', 'work_date', 'status']);

    await page.selectOption('.imp-map-select >> nth=0', 'employee_no');
    await page.click('#impPrimary');
    await page.waitForSelector('#impStepVerify:not(.d-none)');
    await page.waitForSelector('#tb_import_rows tbody tr .imp-cell');
    const stat = async () => ({
        total: (await page.textContent('#impStatTotal')).trim(),
        valid: (await page.textContent('#impStatValid')).trim(),
        errors: (await page.textContent('#impStatErrors')).trim(),
    });
    check(name, 'summary after first check', await stat(), { total: '3', valid: '2', errors: '1' });
    check(name, 'confirm blocked while an error remains', await page.isDisabled('#impPrimary'), true);
    check(name, 'error row listed first', await page.locator('#tb_import_rows tbody tr').first().locator('.badge').first().getAttribute('class').then(c => /danger/.test(c)), true);

    const noOverflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    check(name, 'no sideways page scroll', noOverflow, true);

    const bad = page.locator('#tb_import_rows tbody tr').first().locator('.imp-cell[data-field="employee_no"]');
    await bad.fill('CEO');
    check(name, 're-validate enabled after an edit', await page.isDisabled('#impRevalidate'), false);
    check(name, 'confirm still blocked until re-validated', await page.isDisabled('#impPrimary'), true);
    await page.click('#impRevalidate');
    await page.waitForFunction(() => document.querySelector('#impStatErrors').textContent.trim() === '0');
    check(name, 'summary after re-validate', await stat(), { total: '3', valid: '3', errors: '0' });
    check(name, 'confirm enabled with zero errors', await page.isDisabled('#impPrimary'), false);

    await page.screenshot({ path: path.join(os.tmpdir(), `import_page_${name}.png`) });

    await page.click('#impDiscard');
    await page.waitForSelector('#impWizardModal', { state: 'hidden' });
    await page.click('#imp-log-tab');
    await page.waitForSelector('#tb_import_log tbody tr td:not(.dt-empty-cell)');
    await page.waitForFunction(() => document.querySelectorAll('#tb_import_log tbody tr').length >= 6);
    const logRows = await page.locator('#tb_import_log tbody tr').count();
    check(name, 'activity log has the flow (upload, map, validate x2, edit, discard)', logRows >= 6, true);

    const r = report();
    check(name, 'no console/page errors', [r.consoleErrors.length, r.pageErrors.length], [0, 0]);
    if (r.consoleErrors.length || r.pageErrors.length) console.log(JSON.stringify({ c: r.consoleErrors, p: r.pageErrors }));
    check(name, 'commit never sent', r.blockedWrites, 0);
}

(async () => {
    await cell('1400-th-light', { width: 1400, height: 950, lang: 'th', colorScheme: 'light' });
    await cell('430-en-dark', { width: 430, height: 900, lang: 'en', colorScheme: 'dark' });
    await closeAll();
    const failed = results.filter(r => !r).length;
    console.log(failed ? `${failed} FAILED` : 'ALL PASS');
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
