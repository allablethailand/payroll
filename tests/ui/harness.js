/**
 * The one way a browser test opens this app (2026-09-18, tiny-L4).
 *
 * Every UI round writes its own throwaway script, and each one used to open its own Playwright
 * context with its own cookie/viewport/theme lines. That is not just repetition: it means a rule
 * that has to hold for ALL of them has nowhere to live. The rule that forced this file into
 * existence is the preference write-back.
 *
 * WHY api/user-preference.save IS BLOCKED, ALWAYS
 * The app persists the viewer's own language and theme (app.js -> user-preference.save) as soon as
 * either is switched. A round that measures th AND en, light AND dark -- which every round does --
 * therefore ends by leaving the real admin's saved preference wherever the last assertion happened
 * to stop. That is a browser test writing to a human's row in a shared dev DB. The request is
 * aborted here, for every script, so the page still behaves exactly as it does for a user (the
 * switch is applied client-side either way -- only the write-back is dropped) while employee 28's
 * ui_language/ui_theme come out of the round byte-identical to how they went in. Every context
 * counts what it blocked, and a round reports the number: a count of 0 across a round that switched
 * language means the block stopped matching the real URL, not that nothing needed blocking.
 *
 * Usage (from a round script, run through npx so Playwright resolves -- see resolvePlaywright()):
 *   const { openContext, closeAll } = require('./harness');
 *   const { page, report } = await openContext({ sessionId, width: 1400, height: 950, lang: 'th' });
 *   ...
 *   console.log(report());   // { blockedPreferenceSaves, consoleErrors, pageErrors }
 *   await closeAll();
 *
 * It does NOT create the session or the run -- that is tests/ui/mksession.php, and its own guards
 * (CLI-only, loopback-only, deletes only what it made) are documented in
 * docs/decisions/ui-test-session.md.
 */
'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Playwright is not a dependency of this repo (package.json ships only what the app itself serves),
 * so a round script is run through `npx -p playwright node tests/ui/<script>.js`. That puts the
 * package in npx's own cache rather than in ./node_modules, which plain require() cannot see from a
 * file inside this repo -- hence the explicit walk. Every candidate is a path npx/npm really uses;
 * nothing is downloaded here.
 */
function resolvePlaywright() {
    const tried = [];
    const attempt = (p) => {
        tried.push(p);
        try {
            return require(p);
        } catch (e) {
            return null;
        }
    };
    const direct = attempt('playwright');
    if (direct) return direct;

    const npxCache = path.join(process.env.LOCALAPPDATA || process.env.HOME || '', 'npm-cache', '_npx');
    if (fs.existsSync(npxCache)) {
        for (const entry of fs.readdirSync(npxCache)) {
            const candidate = path.join(npxCache, entry, 'node_modules', 'playwright');
            if (fs.existsSync(candidate)) {
                const mod = attempt(candidate);
                if (mod) return mod;
            }
        }
    }
    throw new Error('playwright not found. Run the script through:  npx -p playwright node tests/ui/<script>.js\nTried:\n  ' + tried.join('\n  '));
}

// The exact requests this harness stops. Matched on the path, not the full URL, so each holds
// whatever BASE_URL this install answers on.
const PREFERENCE_SAVE_PATH = '/api/user-preference.save';
/**
 * The second one, found the hard way in this very round: a draft run with `auto_recalculate = 1`
 * makes loadRunDetail() (payroll/detail.js) POST api/payroll-run.recalculate on the FIRST load of
 * its page -- so merely opening a run to look at it deletes and re-inserts every payroll_run_details
 * row of it. A round told to read a run without writing to it cannot do that, and no amount of care
 * in the round script itself would have prevented it: nothing in the script asks for it.
 * Blocked by default. The page copes: the auto-recalc branch re-calls loadRunDetail() from `.always`
 * and its own once-per-session flag is already set, so the run renders from the figures it already
 * had, with no retry loop. A round that genuinely means to recalculate passes allowRecalculate:true.
 */
const RECALCULATE_PATH = '/api/payroll-run.recalculate';

const openBrowsers = [];

/**
 * @param {object} opts
 * @param {string} opts.sessionId   PHPSESSID printed by mksession.php
 * @param {string} [opts.baseUrl]   defaults to process.env.UI_BASE_URL -- there is no built-in default
 * @param {number} [opts.width]     viewport width  (1400 desktop / 430 phone, this project's 2 sizes)
 * @param {number} [opts.height]
 * @param {'light'|'dark'} [opts.colorScheme]  the OS-level preference, for the media-query path
 */
async function openContext(opts) {
    const o = opts || {};
    // No built-in default, on purpose (2026-09-18, tiny-L6b). It used to fall back to
    // http://localhost/payroll, which on this machine is a DIFFERENT Apache/PHP that answers 500 on
    // every route -- so a round that forgot the variable did not fail, it hung, and the timeout named
    // neither the URL nor the reason. A missing setting is a setup mistake and says so immediately.
    const baseUrl = (o.baseUrl || process.env.UI_BASE_URL || '').replace(/\/$/, '');
    if (!baseUrl) {
        throw new Error('UI_BASE_URL is not set. It is this install\'s own BASE_URL (see .env), e.g.\n'
            + '  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/<script>.js\n'
            + 'or pass it per context: openContext({ baseUrl: "http://localhost:8080/payroll", ... }).');
    }
    const { chromium } = resolvePlaywright();
    const browser = await chromium.launch({ headless: o.headless !== false });
    openBrowsers.push(browser);

    const context = await browser.newContext({
        viewport: { width: o.width || 1400, height: o.height || 950 },
        colorScheme: o.colorScheme || 'light',
        deviceScaleFactor: 1,
    });

    let blockedPreferenceSaves = 0;
    let blockedRecalculates = 0;
    // 2026-09-19, 4c: `blockPaths` -- any OTHER write this round must never make, named per round
    // rather than hard-coded here, because which endpoint is "the dangerous one" is a property of the
    // page being measured, not of the harness. Counted and reported exactly like the 2 fixed ones, so
    // a round says "0 blocked" and means it, instead of not knowing.
    let blockedWrites = 0;
    const blockedWritePaths = [];
    // Aborted, not fulfilled with a fake 200: the page's own handler is fire-and-forget (app.js does
    // not read the reply), so an abort is indistinguishable from a slow network to the UI, and it
    // cannot be mistaken for a write that succeeded.
    await context.route('**' + PREFERENCE_SAVE_PATH + '*', (route) => {
        blockedPreferenceSaves++;
        return route.abort();
    });
    if (o.allowRecalculate !== true) {
        await context.route('**' + RECALCULATE_PATH + '*', (route) => {
            blockedRecalculates++;
            return route.abort();
        });
    }
    for (const p of (o.blockPaths || [])) {
        await context.route('**' + p + '*', (route) => {
            blockedWrites++;
            blockedWritePaths.push(p);
            return route.abort();
        });
    }

    if (o.sessionId) {
        const url = new URL(baseUrl);
        await context.addCookies([{
            name: 'PHPSESSID',
            value: o.sessionId,
            domain: url.hostname,
            path: '/',
            httpOnly: true,
            secure: false,
            sameSite: 'Lax',
        }]);
    }

    const consoleErrors = [];
    const pageErrors = [];
    const page = await context.newPage();
    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', (err) => pageErrors.push(String(err && err.message ? err.message : err)));

    // Chromium logs an aborted request as a console error of its own. That one is the harness's
    // doing, not the page's, so it is separated out rather than left to make every round report a
    // non-zero console-error count that means nothing -- and it is capped at the number of requests
    // really blocked, so a genuine network failure can never hide behind it.
    const ABORT_NOISE = /Failed to load resource.*(ERR_FAILED|ERR_ABORTED|ERR_BLOCKED)/;

    return {
        browser,
        context,
        page,
        baseUrl,
        url: (suffix) => baseUrl + suffix,
        report: () => {
            let budget = blockedPreferenceSaves + blockedRecalculates + blockedWrites;
            const real = [];
            let suppressed = 0;
            for (const text of consoleErrors) {
                if (budget > 0 && ABORT_NOISE.test(text)) {
                    budget--;
                    suppressed++;
                } else {
                    real.push(text);
                }
            }
            return {
                blockedPreferenceSaves,
                blockedRecalculates,
                blockedWrites,
                blockedWritePaths: blockedWritePaths.slice(),
                consoleErrors: real,
                pageErrors: pageErrors.slice(),
                suppressedAbortNoise: suppressed,
                rawConsoleErrors: consoleErrors.slice(),
            };
        },
    };
}

async function closeAll() {
    while (openBrowsers.length) {
        const b = openBrowsers.pop();
        try {
            await b.close();
        } catch (e) {
            /* a browser that already died is not a failure of the round */
        }
    }
}

module.exports = { openContext, closeAll, resolvePlaywright, PREFERENCE_SAVE_PATH, RECALCULATE_PATH };
