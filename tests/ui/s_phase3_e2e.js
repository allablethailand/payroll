/**
 * Phase 3 E2E: Import Center (/imports), Statutory Reports (/reports annual tab incl. TH_WHT50 and TH_PND1K_SUMMARY previews,
 * /reports/annual-summary), at 1400 th light and 430 en dark.
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/s_phase3_e2e.js <PHPSESSID> <runToken> <employeeId> <yearAD>
 *
 * Writes: import staging rows + import_audit_logs only (every file row names an unknown employee, so nothing can reach a payroll table);
 * api/import.commit is in blockPaths, api/import.rollback is answered by a route stub, the activity log is a stub (rendering test).
 * PDF checks use preview=1, which the server does not log.
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { openContext, closeAll, applyAppTheme } = require('./harness');

const [sessionId, runToken, employeeId, yearAd] = process.argv.slice(2);
if (!sessionId || !runToken || !employeeId || !yearAd) throw new Error('usage: node tests/ui/s_phase3_e2e.js <PHPSESSID> <runToken> <employeeId> <yearAD>');
const yearBe = String(Number(yearAd) + 543);

const results = [];
function check(cell, label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    results.push(ok);
    console.log(`${ok ? 'PASS' : 'FAIL'}  [${cell}] ${label}${ok ? '' : `  expected ${JSON.stringify(expected)} got ${JSON.stringify(actual)}`}`);
}

const csvFor = {
    attendance_summary: ['Employee Code,Period Month (1-12),Period Year (YYYY),Late Minutes,Absent Days', 'E2E-NOPE-1,3,2026,10,1', 'E2E-NOPE-2,3,2026,0,2'],
    adhoc_item: ['Employee Code,Item Code,Amount,Period Month (1-12),Period Year (YYYY)', 'E2E-NOPE-1,E2E_ITEM,100,3,2026', 'E2E-NOPE-2,E2E_ITEM,200,3,2026'],
};
const templateWidth = { attendance_summary: 10, adhoc_item: 8 };

async function importCenter(cell, opts) {
    const { page, report } = await openContext({ sessionId, blockPaths: ['/api/import.commit'], ...opts });
    await page.context().addInitScript((l) => { try { localStorage.setItem('preferred_language', l); } catch (e) { /* storage blocked */ } }, opts.lang);
    await page.goto(`${process.env.UI_BASE_URL}/imports`, { waitUntil: 'networkidle' });
    if (opts.colorScheme === 'dark') await applyAppTheme(page, 'dark');

    // --- dropdown options ---
    const options = await page.$$eval('#impEntityType option', els => els.map(e => [e.value, e.textContent.trim()]));
    check(cell, 'entity dropdown lists all 7 types, adhoc_item and attendance_summary included', options.map(o => o[0]),
        ['attendance', 'leave', 'overtime', 'employee_import', 'ytd_opening', 'adhoc_item', 'attendance_summary']);
    check(cell, 'the two new options carry a real label (not the raw key)', options.filter(o => ['adhoc_item', 'attendance_summary'].includes(o[0])).map(o => o[1] !== o[0] && o[1].length > 5), [true, true]);

    // --- wizard for both new entities ---
    for (const entity of ['attendance_summary', 'adhoc_item']) {
        const file = path.join(os.tmpdir(), `e2e_${entity}.csv`);
        fs.writeFileSync(file, csvFor[entity].join('\n'));
        await page.selectOption('#impEntityType', entity);
        await page.setInputFiles('#impFile', file);
        await page.click('#impUploadBtn');
        await page.waitForSelector('#impWizardModal.show');
        const fileColumns = csvFor[entity][0].split(',').length;
        check(cell, `${entity}: mapping lists one row per file column`, await page.locator('#tb_import_mapping tbody tr').count(), fileColumns);
        const preset = await page.$$eval('.imp-map-select', els => els.map(e => e.value).filter(Boolean).length);
        check(cell, `${entity}: every file header matched a template column by label`, preset, fileColumns);
        const templateOptions = await page.$$eval('.imp-map-select >> nth=0', els => els[0].options.length - 1);
        check(cell, `${entity}: the field list has the template's ${templateWidth[entity]} columns`, templateOptions, templateWidth[entity]);

        await page.click('#impPrimary');
        await page.waitForSelector('#impStepVerify:not(.d-none)');
        await page.waitForSelector('#tb_import_rows tbody tr .imp-cell');
        const stat = async () => ({ total: (await page.textContent('#impStatTotal')).trim(), errors: (await page.textContent('#impStatErrors')).trim(), valid: (await page.textContent('#impStatValid')).trim() });
        check(cell, `${entity}: preview grid summary (2 rows, both errors)`, await stat(), { total: '2', errors: '2', valid: '0' });
        const firstMessage = await page.locator('#tb_import_rows tbody tr').first().locator('td').last().innerText();
        check(cell, `${entity}: the error names the rule (employee_not_found)`, /^(employee_not_found|item_not_found):/.test(firstMessage.trim()), true);
        check(cell, `${entity}: Confirm Import stays blocked`, await page.isDisabled('#impPrimary'), true);
        check(cell, `${entity}: no sideways page scroll`, await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true);
        check(cell, `${entity}: grid cells are editable inputs`, await page.locator('#tb_import_rows .imp-cell').count() >= 2, true);

        // race: re-validate and discard back to back, the redraw must not throw after the wizard closes
        const firstCell = page.locator('#tb_import_rows tbody tr').first().locator('.imp-cell').first();
        await firstCell.fill('E2E-NOPE-9');
        await page.click('#impRevalidate', { noWaitAfter: true });
        await page.click('#impDiscard', { noWaitAfter: true });
        await page.waitForSelector('#impWizardModal', { state: 'hidden' });
        await page.waitForTimeout(700);
    }

    // --- activity log: rollback button rendering, against a stubbed log ---
    const log = [
        { id: 4, performed_at: '2026-10-01 10:00:00', action: 'commit', outcome: 'success', entity_type: 'adhoc_item', performed_by_name_th: 'ผู้ทดสอบ', performed_by_name_en: 'Tester', sync_batch_id: 901, rolled_back: false, can_rollback: true, file_name: 'a.csv', total: 3, success: 3, failed: 0, ip_address: '::1', browser: 'Chrome', os: 'Windows' },
        { id: 3, performed_at: '2026-10-01 09:00:00', action: 'commit', outcome: 'success', entity_type: 'attendance_summary', performed_by_name_th: 'ผู้ทดสอบ', performed_by_name_en: 'Tester', sync_batch_id: 902, rolled_back: false, can_rollback: true, file_name: 'b.csv', total: 2, success: 2, failed: 0, ip_address: '::1', browser: 'Chrome', os: 'Windows' },
        { id: 2, performed_at: '2026-10-01 08:00:00', action: 'commit', outcome: 'success', entity_type: 'adhoc_item', performed_by_name_th: 'ผู้ทดสอบ', performed_by_name_en: 'Tester', sync_batch_id: 900, rolled_back: true, can_rollback: false, file_name: 'c.csv', total: 1, success: 1, failed: 0, ip_address: '::1', browser: 'Chrome', os: 'Windows' },
        { id: 1, performed_at: '2026-10-01 07:00:00', action: 'commit', outcome: 'failed', entity_type: 'attendance_summary', performed_by_name_th: 'ผู้ทดสอบ', performed_by_name_en: 'Tester', sync_batch_id: null, rolled_back: false, can_rollback: false, file_name: 'd.csv', total: 1, success: 0, failed: 1, ip_address: '::1', browser: 'Chrome', os: 'Windows' },
    ];
    await page.route('**/api/import.activity-log*', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: true, data: log }) }));
    const rollbackCalls = [];
    await page.route('**/api/import.rollback', route => { rollbackCalls.push(route.request().postData()); route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: true, lines_removed: 3, runs_recalculated: 1 }) }); });
    await page.click('#imp-log-tab');
    await page.waitForFunction(() => document.querySelectorAll('#tb_import_log tbody tr').length === 4);
    check(cell, 'activity log: rollback button only on the 2 rollbackable commits', await page.locator('#tb_import_log .imp-rollback').count(), 2);
    check(cell, 'activity log: the rolled-back batch shows a label instead of a button', await page.locator('#tb_import_log tbody tr', { hasText: 'c.csv' }).locator('.imp-rollback').count(), 0);
    check(cell, 'activity log: Actions column header present', await page.locator('#tb_import_log thead th').count(), 11);
    check(cell, 'activity log: no sideways page scroll', await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true);
    await page.locator('#tb_import_log .imp-rollback').first().click();
    await page.waitForSelector('.swal2-popup');
    check(cell, 'rollback asks for confirmation first and sends nothing yet', rollbackCalls.length, 0);
    await page.click('.swal2-confirm');
    await page.waitForFunction(() => true);
    await page.waitForTimeout(600);
    check(cell, 'confirming sends one rollback for that batch', rollbackCalls.map(c => JSON.parse(c).sync_batch_id), [901]);

    const r = report();
    check(cell, 'import center: no console or page errors', [r.consoleErrors.length, r.pageErrors.length], [0, 0]);
    if (r.consoleErrors.length || r.pageErrors.length) console.log(JSON.stringify({ c: r.consoleErrors, p: r.pageErrors }));
    check(cell, 'import center: commit never sent', r.blockedWrites, 0);
}

async function reportsCell(cell, opts) {
    const { page, report } = await openContext({ sessionId, ...opts });
    await page.context().addInitScript((l) => { try { localStorage.setItem('preferred_language', l); } catch (e) { /* storage blocked */ } }, opts.lang);
    await page.goto(`${process.env.UI_BASE_URL}/reports`, { waitUntil: 'networkidle' });
    if (opts.colorScheme === 'dark') await applyAppTheme(page, 'dark');
    await page.click('#annual-tab');
    await page.waitForSelector('#reportsPeriodYear option', { state: 'attached' });
    const years = await page.$$eval('#reportsPeriodYear option', els => els.map(e => e.value));
    const wanted = years.find(y => y === yearBe || y === String(yearAd));
    check(cell, 'annual tab: the data year is selectable', Boolean(wanted), true);
    await page.selectOption('#reportsPeriodYear', wanted);
    await page.waitForSelector('#tb_annual_reports tbody tr .btn-annual-report-download');
    const codes = await page.$$eval('#tb_annual_reports .btn-annual-report-download', els => els.map(e => e.dataset.code));
    check(cell, 'annual tab lists the 50 Tawi and the P.N.D.1 Kor reports', [codes.includes('TH_WHT50'), codes.includes('TH_PND1K_SUMMARY')], [true, true]);
    const tawiRow = page.locator('#tb_annual_reports tbody tr', { has: page.locator('[data-code="TH_WHT50"]') });
    check(cell, '50 Tawi row is labelled as a draft', /ฉบับร่าง|Draft/.test(await tawiRow.innerText()), true);
    check(cell, 'annual tab: no sideways page scroll', await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true);

    // 50 Tawi preview with an employee
    await tawiRow.locator('.btn-annual-report-download').click();
    await page.waitForSelector('#reportsPreviewModal.show');
    check(cell, '50 Tawi modal asks for an employee and offers no format switch (PDF only)', [await page.isVisible('#reportsPreviewEmployeeWrap'), await page.isVisible('#reportsPreviewFormatWrap')], [true, false]);
    const pdfResponse = page.waitForResponse(res => res.url().includes('report_code=TH_WHT50') && res.url().includes(`employee_id=${employeeId}`), { timeout: 20000 });
    await page.evaluate((id) => {
        const $s = $('#reportsPreviewEmployeeSelect');
        $s.append(new Option('E2E employee', id, true, true)).trigger('change');
    }, employeeId);
    const res = await pdfResponse;
    // The iframe's own response body is discarded by the browser, so the same URL is fetched again with the session cookie.
    const fetched = await page.request.get(res.url());
    const body = await fetched.body();
    check(cell, '50 Tawi preview: 200, application/pdf, a real %PDF file', [fetched.status(), (fetched.headers()['content-type'] || '').startsWith('application/pdf'), body.slice(0, 4).toString(), body.length > 1000], [200, true, '%PDF', true]);
    check(cell, '50 Tawi preview is inline and unlogged (preview=1)', [res.url().includes('preview=1'), (fetched.headers()['content-disposition'] || '').startsWith('inline')], [true, true]);
    check(cell, '50 Tawi modal: no sideways page scroll', await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true);
    await page.keyboard.press('Escape');
    await page.waitForSelector('#reportsPreviewModal', { state: 'hidden' });

    // P.N.D.1 Kor preview (txt/pdf/excel; first format)
    const pndRow = page.locator('#tb_annual_reports tbody tr', { has: page.locator('[data-code="TH_PND1K_SUMMARY"]') });
    const pndResponse = page.waitForResponse(res => res.url().includes('report_code=TH_PND1K_SUMMARY'), { timeout: 20000 });
    await pndRow.locator('.btn-annual-report-download').click();
    const pnd = await pndResponse;
    check(cell, 'P.N.D.1 Kor preview responds 200 with a file, not a JSON error', [pnd.status(), (pnd.headers()['content-type'] || '').includes('json')], [200, false]);
    await page.waitForTimeout(500);

    const r = report();
    check(cell, 'reports page: no console or page errors', [r.consoleErrors.length, r.pageErrors.length], [0, 0]);
    if (r.consoleErrors.length || r.pageErrors.length) console.log(JSON.stringify({ c: r.consoleErrors, p: r.pageErrors }));
}

async function annualSummaryCell(cell, opts) {
    const { page, report } = await openContext({ sessionId, ...opts });
    await page.context().addInitScript((l) => { try { localStorage.setItem('preferred_language', l); } catch (e) { /* storage blocked */ } }, opts.lang);
    await page.goto(`${process.env.UI_BASE_URL}/reports/annual-summary`, { waitUntil: 'networkidle' });
    if (opts.colorScheme === 'dark') await applyAppTheme(page, 'dark');
    check(cell, 'annual summary: 4 tabs', await page.locator('#aisTopTabs .nav-link').count(), 4);
    await page.waitForFunction(() => document.querySelectorAll('#aisFiscalYear option').length > 0);
    check(cell, 'annual summary: fiscal-year list is filled', await page.locator('#aisFiscalYear option').count() > 0, true);
    await page.waitForFunction(() => /\d/.test(document.querySelector('#aisSummaryEmployeeCount').textContent));
    for (const tab of ['#ais-pit-tab', '#ais-sso-tab', '#ais-monthly-pit-tab', '#ais-income-tab']) {
        await page.click(tab);
        await page.waitForTimeout(500);
        check(cell, `annual summary: ${tab} opens its pane`, await page.locator(tab).getAttribute('aria-selected'), 'true');
    }
    check(cell, 'annual summary: no sideways page scroll', await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true);
    const r = report();
    check(cell, 'annual summary: no console or page errors', [r.consoleErrors.length, r.pageErrors.length], [0, 0]);
    if (r.consoleErrors.length || r.pageErrors.length) console.log(JSON.stringify({ c: r.consoleErrors, p: r.pageErrors }));
}

(async () => {
    const cells = [
        ['1400-th-light', { width: 1400, height: 950, lang: 'th', colorScheme: 'light' }],
        ['430-en-dark', { width: 430, height: 900, lang: 'en', colorScheme: 'dark' }],
    ];
    for (const [name, opts] of cells) {
        await importCenter(`import ${name}`, opts);
        await reportsCell(`reports ${name}`, opts);
        await annualSummaryCell(`annual-summary ${name}`, opts);
    }
    await closeAll();
    const failed = results.filter(r => !r).length;
    console.log(`${results.length - failed} passed, ${failed} failed`);
    console.log(failed ? 'SOME FAILED' : 'ALL PASS');
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
