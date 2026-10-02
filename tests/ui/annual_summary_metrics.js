/**
 * Annual Summary (/reports?tab=annual): metric pill (Income | Tax | Social Security) over one filter bar,
 * Monthly Withholding Tax as its own top tab; lazy per-metric load, reload-on-stale, columns re-measured.
 * Run: UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/annual_summary_metrics.js <PHPSESSID>
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const BASE = (process.env.UI_BASE_URL || '').replace(/\/$/, '');
let fails = 0;
const check = (name, ok, extra) => { if (!ok) { fails++; console.log('FAIL', name, extra === undefined ? '' : JSON.stringify(extra)); } };

const METRICS = {
    income: { tab: '#ais-metric-income-tab', table: '#tb_annual_summary', empty: '#aisTableEmpty', endpoint: 'annual-income-summary.summary', card: '#aisSummaryNet' },
    pit: { tab: '#ais-metric-pit-tab', table: '#tb_ais_pit', empty: '#aisPitTableEmpty', endpoint: 'annual-income-summary.pit-summary', card: '#aisSummaryTax' },
    sso: { tab: '#ais-metric-sso-tab', table: '#tb_ais_sso', empty: '#aisSsoTableEmpty', endpoint: 'annual-income-summary.sso-summary', card: '#aisSummarySso' },
};

(async () => {
    const sessionId = process.argv[2];
    for (const cell of [{ width: 1400, theme: 'light', lang: 'th' }, { width: 430, theme: 'dark', lang: 'th' }, { width: 1400, theme: 'light', lang: 'en' }]) {
        const { page, report } = await openContext({ sessionId, height: 950, ...cell });
        const tag = `${cell.width}/${cell.theme}/${cell.lang}`;
        const hits = {};
        page.on('request', (r) => {
            const m = r.url().match(/api\/(annual-income-summary\.[a-z-]+)/);
            if (m) hits[m[1]] = (hits[m[1]] || 0) + 1;
        });
        const inited = (sel) => page.evaluate((s) => !!(window.jQuery && $.fn.DataTable.isDataTable(s)), sel);
        const visibleCards = () => page.$$eval('#aisSummaryCards > div', (els) => els.filter((e) => !e.classList.contains('d-none')).length);
        const aligned = (sel) => page.evaluate((s) => {
            const t = document.querySelector(s);
            const row = t.querySelector('tbody tr');
            if (!row) return { bad: ['no-row'] };
            const bad = [];
            [...row.children].forEach((c, i) => {
                const h = t.querySelectorAll('thead th')[i];
                if (h && Math.abs(h.getBoundingClientRect().left - c.getBoundingClientRect().left) > 2) bad.push(i);
            });
            return { bad, cols: row.children.length };
        }, sel);
        const noSideScroll = () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
        // Either a built DataTable (data exists) or the empty notice is showing -- both are valid end states.
        const settled = async (m) => {
            try {
                await page.waitForFunction((x) => (window.jQuery && $.fn.DataTable.isDataTable(x.table)) || !document.querySelector(x.empty).classList.contains('d-none'), m, { timeout: 8000 });
                await page.waitForLoadState('networkidle');
                return true;
            } catch (e) { return false; }
        };

        await page.goto(`${BASE}/reports?tab=annual`, { waitUntil: 'networkidle' });
        check(`${tag} 3 top tabs on /reports`, (await page.locator('#reportsTopTabs .nav-link').count()) === 3);
        check(`${tag} annual tab active via ?tab=annual`, (await page.locator('#ais-annual-tab.active').count()) === 1);
        check(`${tag} 3 metric pills`, (await page.locator('#aisMetricTabs .nav-link').count()) === 3);
        check(`${tag} pills use structure-tabs`, (await page.locator('#ais-annual-pane .structure-tabs-wrap > .nav-pills.structure-tabs .nav-link.structure-menu').count()) === 3);
        check(`${tag} one filter bar + one fiscal year`, (await page.locator('#aisFilterBar').count()) === 1 && (await page.locator('#aisFiscalYear').count()) === 1);
        check(`${tag} old per-metric filter bars gone`, (await page.locator('#aisPitFilterBar, #aisSsoFilterBar, #aisPitFiscalYear, #aisSsoFiscalYear').count()) === 0);
        check(`${tag} income active`, (await page.locator('#ais-metric-income-tab.active').count()) === 1);
        check(`${tag} income cards: employees + 3`, (await visibleCards()) === 4, await visibleCards());
        check(`${tag} income settled`, await settled(METRICS.income));
        check(`${tag} pit/sso NOT built while hidden`, !(await inited(METRICS.pit.table)) && !(await inited(METRICS.sso.table)));
        check(`${tag} only 'summary' requested at load`, hits['annual-income-summary.summary'] === 1 && !hits['annual-income-summary.pit-summary'] && !hits['annual-income-summary.sso-summary'], hits);
        if (await inited(METRICS.income.table)) {
            const a = await aligned(METRICS.income.table);
            check(`${tag} income aligned`, a.bad.length === 0, a);
        }

        for (const key of ['pit', 'sso']) {
            const m = METRICS[key];
            await page.click(m.tab);
            await page.waitForSelector(`${m.tab}.active`);
            check(`${tag} ${key} settled`, await settled(m));
            check(`${tag} ${key} cards: employees + 1`, (await visibleCards()) === 2, await visibleCards());
            check(`${tag} ${key} requested once`, hits[m.endpoint] === 1, hits);
            if (await inited(m.table)) {
                const a = await aligned(m.table);
                check(`${tag} ${key} aligned after show`, a.bad.length === 0, a);
                check(`${tag} ${key} has 12 month cols + identity + total`, a.cols === 18, a);
            }
            check(`${tag} ${key} no sideways page scroll`, await noSideScroll());
        }

        const before = { ...hits };
        await page.click(METRICS.income.tab);
        await page.waitForSelector(`${METRICS.income.tab}.active`);
        await page.waitForLoadState('networkidle');
        check(`${tag} back to income: cached, no reload`, hits['annual-income-summary.summary'] === before['annual-income-summary.summary'], hits);
        check(`${tag} back to income: 4 cards`, (await visibleCards()) === 4);
        if (await inited(METRICS.income.table)) check(`${tag} income realigned`, (await aligned(METRICS.income.table)).bad.length === 0);

        // Filter change reloads the ACTIVE metric only; the others reload when next shown.
        await page.evaluate(() => { $('#aisFilterStatus').val('active').trigger('change'); });
        await page.waitForTimeout(1500);
        await page.waitForLoadState('networkidle');
        check(`${tag} filter change reloads active metric`, hits['annual-income-summary.summary'] === 2, hits);
        check(`${tag} filter change does not touch hidden metrics`, hits['annual-income-summary.pit-summary'] === 1 && hits['annual-income-summary.sso-summary'] === 1, hits);
        await page.click(METRICS.pit.tab);
        await page.waitForSelector(`${METRICS.pit.tab}.active`);
        await page.waitForTimeout(1500);
        await page.waitForLoadState('networkidle');
        check(`${tag} stale metric reloads on show`, hits['annual-income-summary.pit-summary'] === 2, hits);

        // Monthly tab: lazy, own filters, still works.
        check(`${tag} monthly not requested yet`, !hits['annual-income-summary.monthly-pit']);
        await page.click('#ais-monthly-pit-tab');
        await page.waitForSelector('#ais-monthly-pit-tab.active');
        await page.waitForTimeout(1500);
        await page.waitForLoadState('networkidle');
        // Year/month change handlers can fire a 2nd identical request (pre-existing); >=1 is the contract.
        check(`${tag} monthly requested`, (hits['annual-income-summary.monthly-pit'] || 0) >= 1, hits);
        check(`${tag} monthly keeps its own filter bar`, (await page.locator('#aisMonthlyFilterBar').count()) === 1 && (await page.locator('#aisMonthlyYear').count()) === 1);
        check(`${tag} monthly filter ids intact`, (await page.locator('#aisMonthlyFilterCycle, #aisMonthlyFilterDepartment, #aisMonthlyFilterTeam, #aisMonthlyFilterBranch, #aisMonthlyFilterRole, #aisMonthlyFilterStatus').count()) === 6);
        if (await inited('#tb_ais_monthly')) check(`${tag} monthly aligned`, (await aligned('#tb_ais_monthly')).bad.length === 0);
        await page.click('#ais-annual-tab');
        await page.waitForSelector('#ais-annual-tab.active');
        const activeKey = await page.evaluate(() => $('#aisMetricTabs .nav-link.active').data('ais-metric-tab'));
        if (await inited(METRICS[activeKey].table)) check(`${tag} back on annual: table realigned`, (await aligned(METRICS[activeKey].table)).bad.length === 0);
        check(`${tag} no sideways page scroll (end)`, await noSideScroll());

        const r = report();
        check(`${tag} no page errors`, r.pageErrors.length === 0, r.pageErrors);
        console.log(tag, 'consoleErrors', r.consoleErrors.length, 'requests', JSON.stringify(hits));
    }
    await closeAll();
    console.log(fails === 0 ? 'ALL PASS' : `${fails} FAIL`);
    process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
