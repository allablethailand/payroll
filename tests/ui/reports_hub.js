/**
 * /reports hub: 3 top tabs (Generate Reports | Annual Summary | Monthly Withholding Tax), the 4 original report tabs as
 * pills inside the first, legacy redirect, 2-item Reports sidebar, Quick Link for the annual tab, lazy init + alignment.
 * Run: UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/reports_hub.js <PHPSESSID>
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const BASE = (process.env.UI_BASE_URL || '').replace(/\/$/, '');
let fails = 0;
const check = (name, ok, extra) => { if (!ok) { fails++; console.log('FAIL', name, extra === undefined ? '' : JSON.stringify(extra)); } };

(async () => {
    const sessionId = process.argv[2];
    for (const cell of [{ width: 1400, theme: 'light', lang: 'th' }, { width: 430, theme: 'dark', lang: 'th' }, { width: 1400, theme: 'light', lang: 'en' }]) {
        const { page, report } = await openContext({ sessionId, height: 950, ...cell });
        const tag = `${cell.width}/${cell.theme}/${cell.lang}`;
        const hits = {};
        page.on('request', (r) => {
            const m = r.url().match(/api\/((?:annual-income-summary|report)\.[a-z-]+)/);
            if (m) hits[m[1]] = (hits[m[1]] || 0) + 1;
        });
        const inited = (sel) => page.evaluate((s) => !!(window.jQuery && $.fn.DataTable.isDataTable(s)), sel);
        const noSideScroll = () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
        const aligned = (sel) => page.evaluate((s) => {
            const t = document.querySelector(s);
            const row = t && t.querySelector('tbody tr');
            if (!row || row.children.length < 2) return { bad: [], skipped: true };
            const bad = [];
            [...row.children].forEach((c, i) => {
                const h = t.querySelectorAll('thead th')[i];
                if (h && Math.abs(h.getBoundingClientRect().left - c.getBoundingClientRect().left) > 2) bad.push(i);
            });
            return { bad };
        }, sel);

        // Default: Generate Reports tab, pills inside, annual panes not built.
        await page.goto(`${BASE}/reports`, { waitUntil: 'networkidle' });
        check(`${tag} 3 top tabs`, (await page.locator('#reportsTopTabs .nav-link').count()) === 3);
        check(`${tag} top tabs are setup-tabs`, (await page.locator('#reportsTopTabs.setup-tabs .nav-link.setup-menu').count()) === 3);
        check(`${tag} main tab active by default`, (await page.locator('#reports-main-tab.active').count()) === 1);
        check(`${tag} 4 sub-pills (structure-tabs)`, (await page.locator('#reports-main-pane .structure-tabs-wrap > #reportsTabs.structure-tabs .nav-link.structure-menu').count()) === 4);
        check(`${tag} annual not loaded while hidden`, !hits['annual-income-summary.years'] && !hits['annual-income-summary.summary'], hits);
        check(`${tag} cycle matrix loaded`, !!hits['report.cycle-runs-matrix'] || !!hits['report.list'], hits);

        // Sub-pill still works (history tab lazy table).
        await page.click('#history-tab');
        await page.waitForSelector('#history-tab.active');
        await page.waitForTimeout(800);
        check(`${tag} history pill opens`, (await page.locator('#history-pane.active').count()) === 1);

        // Annual tab from the main tab.
        await page.click('#ais-annual-tab');
        await page.waitForSelector('#ais-annual-tab.active');
        await page.waitForFunction(() => window.jQuery && ($.fn.DataTable.isDataTable('#tb_annual_summary') || !document.querySelector('#aisTableEmpty').classList.contains('d-none')), null, { timeout: 8000 });
        check(`${tag} annual loads on first show`, hits['annual-income-summary.summary'] === 1, hits);
        check(`${tag} annual pills present`, (await page.locator('#aisMetricTabs .nav-link').count()) === 3);
        if (await inited('#tb_annual_summary')) check(`${tag} annual aligned`, (await aligned('#tb_annual_summary')).bad.length === 0);

        // Monthly tab.
        await page.click('#ais-monthly-pit-tab');
        await page.waitForSelector('#ais-monthly-pit-tab.active');
        await page.waitForTimeout(1500);
        await page.waitForLoadState('networkidle');
        check(`${tag} monthly loads on show`, (hits['annual-income-summary.monthly-pit'] || 0) >= 1, hits);
        if (await inited('#tb_ais_monthly')) check(`${tag} monthly aligned`, (await aligned('#tb_ais_monthly')).bad.length === 0);

        // Back to main: cycle matrix re-measured.
        await page.click('#reports-main-tab');
        await page.waitForSelector('#reports-main-tab.active');
        await page.click('#cycle-tab');
        await page.waitForTimeout(500);
        if (await inited('#tb_cycle_matrix')) check(`${tag} cycle matrix aligned after returning`, (await aligned('#tb_cycle_matrix')).bad.length === 0);
        check(`${tag} no sideways scroll`, await noSideScroll());

        // Deep links.
        await page.goto(`${BASE}/reports?tab=annual`, { waitUntil: 'networkidle' });
        check(`${tag} ?tab=annual active`, (await page.locator('#ais-annual-tab.active').count()) === 1 && (await page.locator('#reports-main-tab.active').count()) === 0);
        await page.waitForFunction(() => window.jQuery && ($.fn.DataTable.isDataTable('#tb_annual_summary') || !document.querySelector('#aisTableEmpty').classList.contains('d-none')), null, { timeout: 8000 });
        check(`${tag} ?tab=annual builds annual at load`, true);
        // The hidden main tab's tables must re-measure when finally shown.
        await page.click('#reports-main-tab');
        await page.waitForSelector('#reports-main-tab.active');
        await page.waitForTimeout(600);
        if (await inited('#tb_cycle_matrix')) check(`${tag} cycle matrix aligned after ?tab=annual -> main`, (await aligned('#tb_cycle_matrix')).bad.length === 0);

        await page.goto(`${BASE}/reports?tab=monthly`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        check(`${tag} ?tab=monthly active`, (await page.locator('#ais-monthly-pit-tab.active').count()) === 1);
        check(`${tag} ?tab=monthly loads monthly at load`, (await inited('#tb_ais_monthly')) || !(await page.locator('#aisMonthlyTableEmpty').evaluate((e) => e.classList.contains('d-none'))));

        if (cell.width === 1400 && cell.lang === 'th') {
            await page.goto(`${BASE}/reports/annual-summary`, { waitUntil: 'networkidle' });
            check('redirect annual-summary', /\/reports\?tab=annual$/.test(page.url()), page.url());
            check('redirect lands on annual tab', (await page.locator('#ais-annual-tab.active').count()) === 1);
            const links = await page.$$eval('.menu-item.has-submenu .submenu-link', (as) => as.map((a) => a.getAttribute('href')));
            const reportsGroup = links.filter((h) => h === `${BASE}/reports` || h === `${BASE}/audit`);
            check('Reports submenu = /reports + /audit only', reportsGroup.length === 2 && reportsGroup.some((h) => h.endsWith('/reports')) && reportsGroup.some((h) => h.endsWith('/audit')), reportsGroup);
            check('sidebar dropped annual-summary link', !links.some((h) => /annual-summary/.test(h || '')), links);
            const label = await page.$eval(`.submenu-link[href="${BASE}/reports"] .submenu-text`, (e) => e.textContent.trim());
            check('sidebar label renamed', label === 'รายงานเงินเดือนและภาษี', label);
            const qa = await page.evaluate(() => (window.QUICK_LINK_CATALOG || (typeof QUICK_LINK_CATALOG !== 'undefined' ? QUICK_LINK_CATALOG : [])).filter((x) => x.key === 'reports.annual_summary').map((x) => x.url));
            check('quick link catalog maps annual summary to ?tab=annual', qa.length === 1 && /\/reports\?tab=annual$/.test(qa[0]), qa);
        }
        const r = report();
        check(`${tag} no page errors`, r.pageErrors.length === 0, r.pageErrors);
        console.log(tag, 'consoleErrors', r.consoleErrors.length);
    }
    await closeAll();
    console.log(fails === 0 ? 'ALL PASS' : `${fails} FAIL`);
    process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
