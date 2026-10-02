/**
 * Audit & Activity Logs page (/audit): 2 sub-tabs, lazy DataTable init, legacy redirects, merged sidebar item.
 * Run: UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/audit_center_tabs.js <PHPSESSID>
 */
'use strict';
const { openContext, closeAll } = require('./harness');

const BASE = (process.env.UI_BASE_URL || '').replace(/\/$/, '');
let fails = 0;
const check = (name, ok, extra) => { if (!ok) fails++; if (!ok) console.log('FAIL', name, extra === undefined ? '' : JSON.stringify(extra)); };
const aligned = (page, tableSel) => page.evaluate((sel) => {
    const t = document.querySelector(sel);
    const ths = [...t.closest('.dt-container, .dataTables_wrapper, body').querySelectorAll('thead th')].filter(th => th.offsetParent);
    const td = t.querySelector('tbody tr:not(.dt-empty) td, tbody tr td');
    const wrap = t.closest('.tab-pane');
    const bad = [];
    const scrollHead = wrap.querySelector('.dt-scroll-head, .dataTables_scrollHead');
    const row = t.querySelectorAll('tbody tr')[0];
    if (row && row.children.length > 1) {
        [...row.children].forEach((c, i) => {
            const h = t.querySelectorAll('thead th')[i];
            if (h && Math.abs(h.getBoundingClientRect().left - c.getBoundingClientRect().left) > 2) bad.push(i);
        });
    }
    return { bad, tableW: Math.round(t.getBoundingClientRect().width), paneW: Math.round(wrap.getBoundingClientRect().width), inited: !!(window.jQuery && $.fn.DataTable.isDataTable(sel)) };
}, tableSel);

(async () => {
    const sessionId = process.argv[2];
    for (const cell of [{ width: 1400, theme: 'light', lang: 'th' }, { width: 430, theme: 'dark', lang: 'th' }, { width: 1400, theme: 'light', lang: 'en' }]) {
        const { page, report } = await openContext({ sessionId, height: 950, ...cell });
        const tag = `${cell.width}/${cell.theme}/${cell.lang}`;
        const isInit = (sel) => page.evaluate((s) => !!(window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable(s)), sel);

        await page.goto(`${BASE}/audit`, { waitUntil: 'networkidle' });
        check(`${tag} url`, page.url().endsWith('/audit'), page.url());
        check(`${tag} 2 tabs`, (await page.locator('#auditTopTabs .nav-link').count()) === 2);
        check(`${tag} run tab active`, await page.locator('#audit-run-tab.active').count() === 1);
        check(`${tag} run table inited`, await isInit('#tb_run_audit_list'));
        check(`${tag} general table NOT inited while hidden`, !(await isInit('#tb_audit_log')));
        const a1 = await aligned(page, '#tb_run_audit_list');
        check(`${tag} run table aligned`, a1.bad.length === 0 && (await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)), a1);

        await page.click('#audit-general-tab');
        await page.waitForSelector('#audit-general-pane.active');
        await page.waitForLoadState('networkidle');
        check(`${tag} general table inited on show`, await isInit('#tb_audit_log'));
        const a2 = await aligned(page, '#tb_audit_log');
        check(`${tag} general table aligned`, a2.bad.length === 0 && (await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)), a2);
        await page.click('#audit-run-tab');
        await page.waitForSelector('#audit-run-pane.active');
        check(`${tag} run table realigned after switching back`, (await aligned(page, '#tb_run_audit_list')).bad.length === 0);

        await page.goto(`${BASE}/audit?tab=general`, { waitUntil: 'networkidle' });
        check(`${tag} ?tab=general active`, await page.locator('#audit-general-tab.active').count() === 1);
        check(`${tag} ?tab=general inits general at load`, await isInit('#tb_audit_log'));
        check(`${tag} ?tab=general leaves run table un-inited`, !(await isInit('#tb_run_audit_list')));
        check(`${tag} general aligned at load`, (await aligned(page, '#tb_audit_log')).bad.length === 0);

        if (cell.width === 1400 && cell.lang === 'th') {
            await page.goto(`${BASE}/reports/run-audit`, { waitUntil: 'networkidle' });
            check('redirect run-audit', /\/audit\?tab=run$/.test(page.url()), page.url());
            await page.goto(`${BASE}/audit-log`, { waitUntil: 'networkidle' });
            check('redirect audit-log', /\/audit\?tab=general$/.test(page.url()), page.url());
            check('redirect lands on general tab', await page.locator('#audit-general-tab.active').count() === 1);
            const hrefs = await page.$$eval('a.submenu-link', as => as.map(a => a.getAttribute('href')));
            check('sidebar has /audit', hrefs.some(h => h.endsWith('/audit')), hrefs);
            check('sidebar dropped old links', !hrefs.some(h => /run-audit|\/audit-log/.test(h)), hrefs);
        }
        const r = report();
        check(`${tag} no page errors`, r.pageErrors.length === 0, r.pageErrors);
        console.log(tag, 'consoleErrors', r.consoleErrors.length);
    }
    await closeAll();
    console.log(fails === 0 ? 'ALL PASS' : `${fails} FAIL`);
    process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
