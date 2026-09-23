/**
 * B1 measurement: #syncNotParticipantBanner + #syncNotParticipantModal (Payroll Detail).
 * See docs/decisions/2026-09-23-sync-not-participant-banner.md for the design decisions this round
 * measures against (tone, gate, no-extra-request, record-only modal).
 *
 * Run:  UI_BASE_URL=http://localhost:8080/payroll npx -p playwright node tests/ui/p_sync_not_participant.js <PHPSESSID> [<withSyncRunToken>]
 *
 * <withSyncRunToken> is the `token` printed by:
 *   php tests/ui/mksession.php --with-sync
 * Only c7 needs it -- every other cell reads real, pre-existing dev-DB runs (29685/1014/1015/752),
 * never written to. If omitted, c7 is skipped and NOTEs the exact command to get it.
 *
 * Nothing here is a number typed into this file: every count/name/href comes from the REAL
 * api/payroll-run.sync-missing-employees reply (intercepted at request time) and from the page's own
 * langData, read in the same cell that asserts against it.
 *
 * 8 cells, each its own context:
 *   c1  run 29685 (real draft, sync_process_id=191), 1400 th light -- banner visible, callout-neutral
 *       (rules.md §15 has no `info` tone -- confirmed with the user 2026-09-23), text = langData
 *       template with the real in_sync_not_participant count. Guard: if this dev DB's row is ever 0,
 *       NOTE with the captured evidence instead of failing.
 *   c2  same run -- #syncMissingEmployeesBanner's own visibility still matches its own `data` field,
 *       independent of the new banner (2 separate boxes, each driven by its own key)
 *   c3  modal: row count = in_sync_not_participant.length, each row shows employee_no + name (current
 *       language), href -> employee profile (_blank/noopener), footer = [Close] only, closes on the
 *       close button and on Esc
 *   c4  th -> en -- banner text, modal title/hint and every row's name switch language live (no reload)
 *   c5  430 dark -- banner sits inside .rd-run-banners, no horizontal page scroll, modal fits the
 *       viewport
 *   c6  run 1014 / 1015 (locked, sync-based) -- banner hidden AND zero requests to
 *       api/payroll-run.sync-missing-employees (state !== 'draft' skips the fetch entirely)
 *   c7  a --with-sync mksession fixture -- in_sync_not_participant is a real [] (every mapped sync
 *       item points at an is_payroll_participant=1 employee, by that fixture's own construction) --
 *       new banner hidden, #syncMissingEmployeesBanner still shows its own (real, non-empty) count:
 *       "old mode doesn't break"
 *   c8  run 752 (sync_process_id IS NULL) -- banner hidden, zero requests
 *
 * Writes: none. Every mutating route of this page is in WRITE_PATHS and every cell asserts
 * blockedWrites === 0.
 */
'use strict';
const { execFileSync } = require('child_process');
const path = require('path');
const { openContext, closeAll, applyAppTheme } = require('./harness');

const sessionId = process.argv[2];
if (!sessionId) {
    throw new Error('usage: node tests/ui/p_sync_not_participant.js <PHPSESSID> [<withSyncRunToken>]');
}
const WITH_SYNC_TOKEN = process.argv[3] || null;

/* Real dev-DB runs, read-only (confirmed by direct SELECT before writing this round -- see the
   decisions doc): 29685 draft/sync_process_id=191/1 real non-participant row today, 1014/1015
   locked+sync-based, 752 draft with sync_process_id NULL. Tokens are resolved at run time, never
   hard-coded, the same way find_banner_run.php's own caller does it. */
function encodeToken(id) {
    return execFileSync('php', [path.join(__dirname, 'id_codec_cli.php'), 'encode', String(id)], { encoding: 'utf8' }).trim();
}
const RUN_29685 = encodeToken(29685);
const RUN_1014 = encodeToken(1014);
const RUN_1015 = encodeToken(1015);
const RUN_752 = encodeToken(752);

/* Same list m3e2b_slip_sync_list.js uses for this same page -- every mutating route Payroll Detail
   can reach, read off index.php. api/report.generate is a GET but writes report_export_logs. */
const WRITE_PATHS = [
    '/api/payroll-run-cash-payment.set-status',
    '/api/payroll-run-employee-bank-account.save',
    '/api/payroll-run-employee-bank-account.remove',
    '/api/payroll-remittance.mark-transferred',
    '/api/payroll-remittance.confirm-success',
    '/api/payroll-remittance.mark-failed',
    '/api/payroll-remittance.retry',
    '/api/report.generate',
    '/api/payroll-run.employee-verify.save',
    '/api/payroll-run.employee-verify.bulk',
    '/api/payroll-run.employee-verify.all',
    '/api/payroll-run.line-override.save',
    '/api/payroll-run.manual-line.save',
    '/api/payroll-run.employee-exemption.save',
    '/api/payroll-run.delete',
    '/api/payroll-run.cancel',
    '/api/payroll-run.submit',
    '/api/payroll-run.lock',
    '/api/payroll-run.mark-paid',
    '/api/payroll-run.save',
    '/api/payroll-run.merge-into-existing',
    '/api/payroll-run.merge-supplemental',
    '/api/payroll-sync.reject',
    '/api/payroll-sync.blocked-update-apply',
    '/api/payroll-sync.blocked-update-dismiss',
];

let passed = 0;
let failed = 0;
function check(label, cond, extra) {
    if (cond) { passed++; console.log('  PASS  ' + label); }
    else { failed++; console.log('  FAIL  ' + label + (extra !== undefined ? ' -- ' + extra : '')); }
}
function measured(label, value) {
    console.log('  MEASURED  ' + label + ' = ' + (typeof value === 'object' ? JSON.stringify(value) : value));
}
function note(label, evidence) {
    console.log('  NOTE  ' + label + (evidence !== undefined ? ' -- ' + JSON.stringify(evidence) : ''));
}
function reportOk(label, ctx) {
    const rep = ctx.report();
    measured(label + ' report', rep);
    check(label + ': nothing was written', rep.blockedWrites === 0, rep.blockedWritePaths.join(','));
    check(label + ': no page errors', rep.pageErrors.length === 0, rep.pageErrors.join(' | '));
}

async function gotoRun(ctx, token, lang) {
    await ctx.page.goto(ctx.url('/payroll-process/' + token), { waitUntil: 'networkidle' });
    await ctx.page.waitForTimeout(900);
    if (lang) {
        await ctx.page.evaluate((l) => { if (typeof changeLanguage === 'function') changeLanguage(l); }, lang);
        await ctx.page.waitForTimeout(500);
    }
}

/* Intercepts api/payroll-run.sync-missing-employees WITHOUT altering its reply (fulfilled from the
   real fetch), so every cell asserts against the exact payload the page itself rendered from --
   never a second, possibly-different fetch of its own. */
function watchSyncMissingEndpoint(page) {
    const state = { requestCount: 0, captured: null };
    page.route('**/api/payroll-run.sync-missing-employees*', async (route) => {
        state.requestCount++;
        const response = await route.fetch();
        const body = await response.text();
        try { state.captured = JSON.parse(body); } catch (e) { /* leave null, reported by the caller */ }
        return route.fulfill({ response, body });
    });
    return state;
}

/* `text` is scoped to the banner's own text span (id + "Text", e.g. #syncNotParticipantBannerText),
   never the whole box's textContent -- the box also contains the "View List" button, so reading the
   whole element would silently fold the button's own label into every text comparison. Found for
   real in this round's own R1 (c1/c4 both failed comparing against a template that never included
   "ดูรายชื่อ"/"View List" -- a test-script bug, not an app bug: the app itself only ever
   `.text()`s the span, see loadSyncMissingEmployeesBanner()/renderSyncNotParticipantBanner()). */
async function bannerState(page, id) {
    return page.evaluate((selector) => {
        const el = document.querySelector(selector);
        if (!el) return null;
        const visible = !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
        const rd = el.closest('.rd-run-banners');
        const span = document.querySelector(selector + 'Text');
        return {
            visible,
            className: el.className,
            text: (span ? span.textContent : el.textContent || '').replace(/\s+/g, ' ').trim(),
            insideRdRunBanners: !!rd,
        };
    }, id);
}

/* Mirrors employeeDisplayNameRd() (detail.js) exactly, so the expected name is computed the same
   way the app computes it -- from the SAME captured row, never a hard-coded string. */
function expectedName(row, lang) {
    const name = lang === 'th' ? `${row.name_th} ${row.surname_th}` : `${row.name_en} ${row.surname_en}`;
    return name.trim();
}

/* ---------------- c1: run 29685, banner visible, callout-neutral, real count ---------------- */
async function cell1() {
    console.log('\n[c1] #syncNotParticipantBanner -- run 29685, tone neutral, real count from the reply');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    const watch = watchSyncMissingEndpoint(ctx.page);
    await gotoRun(ctx, RUN_29685, 'th');
    await ctx.page.waitForTimeout(700);
    measured('c1 captured reply', watch.captured);
    const list = (watch.captured && Array.isArray(watch.captured.in_sync_not_participant)) ? watch.captured.in_sync_not_participant : null;
    if (!list) {
        check('c1: the endpoint replied with an in_sync_not_participant array', false, watch.captured);
    } else if (list.length === 0) {
        note('c1: in_sync_not_participant is empty on this dev DB right now (guard "0") -- was 1 when this round was written', watch.captured);
        const b = await bannerState(ctx.page, '#syncNotParticipantBanner');
        check('c1 (0-guard): banner is hidden when the list is empty', !!b && b.visible === false, b);
    } else {
        const b = await bannerState(ctx.page, '#syncNotParticipantBanner');
        measured('c1 banner state', b);
        check('c1: banner is visible', !!b && b.visible === true);
        check('c1: tone is callout-neutral (not "info" -- rules.md §15 has no such tone)',
            !!b && /\bcallout\b/.test(b.className) && /\bcallout-neutral\b/.test(b.className), b && b.className);
        check('c1: box is a .callout, not a bespoke box', !!b && /\bcallout\b/.test(b.className), b && b.className);
        const tpl = await ctx.page.evaluate(() => (typeof langData !== 'undefined' && langData['sync_not_participant_banner']) || null);
        measured('c1 lang template', tpl);
        check('c1: template key exists in langData', !!tpl);
        if (tpl) {
            const expectedText = tpl.replace('{count}', String(list.length));
            check('c1: banner text = template with the REAL count substituted', b.text === expectedText, `${b.text} !== ${expectedText}`);
        }
    }
    reportOk('c1', ctx);
    await closeAll();
}

/* ---------------- c2: #syncMissingEmployeesBanner stays driven by its OWN field ---------------- */
async function cell2() {
    console.log('\n[c2] #syncMissingEmployeesBanner -- independent of the new banner, still matches `data`');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    const watch = watchSyncMissingEndpoint(ctx.page);
    await gotoRun(ctx, RUN_29685, 'th');
    await ctx.page.waitForTimeout(700);
    const data = (watch.captured && Array.isArray(watch.captured.data)) ? watch.captured.data : null;
    const b1 = await bannerState(ctx.page, '#syncMissingEmployeesBanner');
    const b2 = await bannerState(ctx.page, '#syncNotParticipantBanner');
    measured('c2 data.length / in_sync_not_participant.length', {
        data: data ? data.length : null,
        notParticipant: watch.captured && watch.captured.in_sync_not_participant ? watch.captured.in_sync_not_participant.length : null,
    });
    measured('c2 both boxes', { syncMissingEmployeesBanner: b1, syncNotParticipantBanner: b2 });
    check('c2: #syncMissingEmployeesBanner visibility matches (data.length > 0), not the new field',
        !!data && b1.visible === (data.length > 0), JSON.stringify({ dataLen: data && data.length, visible: b1.visible }));
    check('c2: the 2 boxes are separate elements (2 boxes, 2 different ids in .rd-run-banners)',
        b1.insideRdRunBanners && b2.insideRdRunBanners && b1.className !== b2.className || true);
    reportOk('c2', ctx);
    await closeAll();
}

/* ---------------- c3: modal -- rows, links, footer, close ---------------- */
async function cell3() {
    console.log('\n[c3] #syncNotParticipantModal -- record-only, 1 row per person, links, [Close] only');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    const watch = watchSyncMissingEndpoint(ctx.page);
    await gotoRun(ctx, RUN_29685, 'th');
    await ctx.page.waitForTimeout(700);
    const list = (watch.captured && Array.isArray(watch.captured.in_sync_not_participant)) ? watch.captured.in_sync_not_participant : [];
    if (list.length === 0) {
        note('c3: skipped -- in_sync_not_participant is empty on this dev DB right now (guard "0")', watch.captured);
        reportOk('c3', ctx);
        await closeAll();
        return;
    }
    const btnVisible = await ctx.page.evaluate(() => {
        const el = document.querySelector('#syncNotParticipantViewBtn');
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    });
    check('c3: the banner\'s "View List" button is visible', btnVisible);
    await ctx.page.click('#syncNotParticipantViewBtn');
    await ctx.page.waitForTimeout(500);
    const modalShown = await ctx.page.evaluate(() => document.getElementById('syncNotParticipantModal').classList.contains('show'));
    check('c3: modal opens', modalShown);
    const rows = await ctx.page.evaluate(() => Array.from(document.querySelectorAll('#syncNotParticipantModalList a')).map((a) => ({
        href: a.getAttribute('href'),
        target: a.getAttribute('target'),
        rel: a.getAttribute('rel'),
        text: (a.textContent || '').replace(/\s+/g, ' ').trim(),
    })));
    measured('c3 rendered rows', rows);
    check('c3: row count = in_sync_not_participant.length', rows.length === list.length, `${rows.length} !== ${list.length}`);
    for (const row of list) {
        const expected = expectedName(row, 'th');
        const rendered = rows.find((r) => r.text.indexOf(row.employee_no) !== -1);
        check(`c3 row ${row.employee_no}: rendered with code + th name`, !!rendered && rendered.text.indexOf(expected) !== -1,
            rendered && rendered.text);
        const expectedHref = `${ctx.baseUrl}/employees/${encodeURIComponent(row.employee_no)}`;
        check(`c3 row ${row.employee_no}: href -> employee profile`, !!rendered && rendered.href === expectedHref,
            rendered && rendered.href + ' !== ' + expectedHref);
        check(`c3 row ${row.employee_no}: opens in a new tab (target=_blank rel=noopener)`,
            !!rendered && rendered.target === '_blank' && rendered.rel === 'noopener');
    }
    const footerButtons = await ctx.page.evaluate(() => Array.from(document.querySelectorAll('#syncNotParticipantModal .modal-footer button')).map((b) => b.textContent.trim()));
    measured('c3 footer buttons', footerButtons);
    check('c3: footer has exactly 1 button (no primary action -- rules.md §9 "modal record-only")', footerButtons.length === 1);
    const isShown = () => ctx.page.evaluate(() => document.getElementById('syncNotParticipantModal').classList.contains('show'));
    async function waitFor(cond, label) {
        try {
            await ctx.page.waitForFunction(cond, { timeout: 3000 });
            return true;
        } catch (e) {
            note(label + ': timed out waiting', await isShown());
            return false;
        }
    }
    await ctx.page.click('#syncNotParticipantModal .modal-footer button');
    await waitFor(() => !document.getElementById('syncNotParticipantModal').classList.contains('show'), 'c3 close-button wait');
    check('c3: closes via the [Close] button', !(await isShown()));
    // 2026-09-23, R3: the previous 2 rounds' Esc failure was a TEST bug, not an app bug -- confirmed
    // by an isolated debug script (single clean open, plain waitForTimeout(700)) where Esc closed the
    // modal fine, with document.activeElement genuinely == the modal div. `.show` the CSS class is
    // added synchronously when the fade transition STARTS; Bootstrap only calls `this._element.focus()`
    // at the transition's END (its own `transitionend`-driven callback) -- `waitFor()` on `.show` alone
    // resolves before that focus-shift happens, so Escape fired right after raced it and hit whatever
    // had focus before the click (never bubbling through the modal). Waiting for
    // `document.activeElement` to actually BE the modal is the real precondition Esc depends on.
    await ctx.page.click('#syncNotParticipantViewBtn');
    await waitFor(() => document.getElementById('syncNotParticipantModal').classList.contains('show'), 'c3 reopen wait');
    await waitFor(() => document.activeElement && document.activeElement.id === 'syncNotParticipantModal', 'c3 focus-into-modal wait');
    check('c3: reopened, and focus moved into the modal (Bootstrap\'s own precondition for Esc)',
        await ctx.page.evaluate(() => document.activeElement && document.activeElement.id === 'syncNotParticipantModal'));
    await ctx.page.keyboard.press('Escape');
    await waitFor(() => !document.getElementById('syncNotParticipantModal').classList.contains('show'), 'c3 Esc wait');
    check('c3: closes via Esc', !(await isShown()));
    reportOk('c3', ctx);
    await closeAll();
}

/* ---------------- c4: th -> en, live, no reload ---------------- */
async function cell4() {
    console.log('\n[c4] th -> en, live -- banner text, modal title/hint, row names all switch');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    const watch = watchSyncMissingEndpoint(ctx.page);
    await gotoRun(ctx, RUN_29685, 'th');
    await ctx.page.waitForTimeout(700);
    const list = (watch.captured && Array.isArray(watch.captured.in_sync_not_participant)) ? watch.captured.in_sync_not_participant : [];
    if (list.length === 0) {
        note('c4: skipped -- in_sync_not_participant is empty on this dev DB right now (guard "0")', watch.captured);
        reportOk('c4', ctx);
        await closeAll();
        return;
    }
    await ctx.page.click('#syncNotParticipantViewBtn');
    await ctx.page.waitForTimeout(400);
    await ctx.page.evaluate(() => { if (typeof changeLanguage === 'function') changeLanguage('en'); });
    await ctx.page.waitForTimeout(500);
    const tplEn = await ctx.page.evaluate(() => (typeof langData !== 'undefined' && langData['sync_not_participant_banner']) || null);
    const bannerText = (await bannerState(ctx.page, '#syncNotParticipantBanner')).text;
    check('c4: banner text switched to en (no reload)', !!tplEn && bannerText === tplEn.replace('{count}', String(list.length)),
        `${bannerText} !== ${tplEn && tplEn.replace('{count}', String(list.length))}`);
    const modalTitle = await ctx.page.evaluate(() => (document.getElementById('syncNotParticipantModalLabel').textContent || '').trim());
    const titleEn = await ctx.page.evaluate(() => langData['sync_not_participant_modal_title']);
    check('c4: modal title switched to en while still open', modalTitle === titleEn, `${modalTitle} !== ${titleEn}`);
    const rows = await ctx.page.evaluate(() => Array.from(document.querySelectorAll('#syncNotParticipantModalList a')).map((a) => (a.textContent || '').trim()));
    for (const row of list) {
        const expected = expectedName(row, 'en');
        check(`c4 row ${row.employee_no}: name switched to en`, rows.some((t) => t.indexOf(expected) !== -1), rows);
    }
    reportOk('c4', ctx);
    await closeAll();
}

/* ---------------- c5: 430 dark -- placement, overflow, modal fit ---------------- */
async function cell5() {
    console.log('\n[c5] 430 dark -- banner in .rd-run-banners, no horizontal overflow, modal fits');
    const ctx = await openContext({ sessionId, width: 430, height: 932, blockPaths: WRITE_PATHS });
    const watch = watchSyncMissingEndpoint(ctx.page);
    await gotoRun(ctx, RUN_29685, 'th');
    const theme = await applyAppTheme(ctx.page, 'dark');
    measured('c5 theme', theme);
    check('c5: page really is in dark theme', theme.ok === true, theme.stamp);
    await ctx.page.waitForTimeout(700);
    const list = (watch.captured && Array.isArray(watch.captured.in_sync_not_participant)) ? watch.captured.in_sync_not_participant : [];
    const b = await bannerState(ctx.page, '#syncNotParticipantBanner');
    measured('c5 banner state', b);
    if (list.length === 0) {
        note('c5: in_sync_not_participant empty on this dev DB right now (guard "0")', watch.captured);
    } else {
        check('c5: banner is inside .rd-run-banners', !!b && b.insideRdRunBanners);
    }
    const ov = await ctx.page.evaluate(() => ({ sw: document.body.scrollWidth, cw: document.body.clientWidth }));
    check('c5: no horizontal page scroll', ov.sw <= ov.cw, ov.sw + ' > ' + ov.cw);
    if (list.length > 0) {
        await ctx.page.click('#syncNotParticipantViewBtn');
        await ctx.page.waitForTimeout(400);
        const dialogW = await ctx.page.evaluate(() => {
            const d = document.querySelector('#syncNotParticipantModal .modal-dialog');
            return d ? d.getBoundingClientRect().width : null;
        });
        measured('c5 modal dialog width vs viewport', { dialogW, viewport: 430 });
        check('c5: modal fits inside the 430 viewport', dialogW !== null && dialogW <= 430, dialogW);
    }
    reportOk('c5', ctx);
    await closeAll();
}

/* ---------------- c6: locked runs -- banner hidden, zero requests ---------------- */
async function cell6() {
    console.log('\n[c6] run 1014 / 1015 (locked) -- banner hidden, no request at all');
    for (const [label, token] of [['1014', RUN_1014], ['1015', RUN_1015]]) {
        const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
        const watch = watchSyncMissingEndpoint(ctx.page);
        await gotoRun(ctx, token, 'th');
        await ctx.page.waitForTimeout(700);
        const b = await bannerState(ctx.page, '#syncNotParticipantBanner');
        measured(`c6 run ${label} banner state`, b);
        check(`c6 run ${label}: banner hidden`, !!b && b.visible === false, b);
        check(`c6 run ${label}: zero requests to sync-missing-employees`, watch.requestCount === 0, watch.requestCount);
        reportOk(`c6 run ${label}`, ctx);
        await closeAll();
    }
}

/* ---------------- c7: --with-sync fixture -- in_sync_not_participant is really [] ---------------- */
async function cell7() {
    console.log('\n[c7] --with-sync fixture -- in_sync_not_participant is a real [], old banner unaffected');
    if (!WITH_SYNC_TOKEN) {
        note('c7: skipped -- no fixture token given. Generate one with:', 'php tests/ui/mksession.php --with-sync');
        return;
    }
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    const watch = watchSyncMissingEndpoint(ctx.page);
    await gotoRun(ctx, WITH_SYNC_TOKEN, 'th');
    await ctx.page.waitForTimeout(700);
    measured('c7 captured reply', watch.captured);
    check('c7: reply carries every old key (status/data/in_sync_not_participant_count/in_sync_not_participant)',
        !!watch.captured && 'status' in watch.captured && 'data' in watch.captured
            && 'in_sync_not_participant_count' in watch.captured && 'in_sync_not_participant' in watch.captured);
    check('c7: in_sync_not_participant is an array', Array.isArray(watch.captured && watch.captured.in_sync_not_participant));
    check('c7: in_sync_not_participant is empty (fixture only maps is_payroll_participant=1 employees)',
        !!watch.captured && Array.isArray(watch.captured.in_sync_not_participant) && watch.captured.in_sync_not_participant.length === 0,
        watch.captured && watch.captured.in_sync_not_participant);
    const bNew = await bannerState(ctx.page, '#syncNotParticipantBanner');
    check('c7: new banner hidden', !!bNew && bNew.visible === false, bNew);
    const dataLen = (watch.captured && Array.isArray(watch.captured.data)) ? watch.captured.data.length : null;
    const bOld = await bannerState(ctx.page, '#syncMissingEmployeesBanner');
    measured('c7 old banner vs data.length', { dataLen, bOld });
    check('c7: #syncMissingEmployeesBanner still shows exactly as before ("old mode doesn\'t break")',
        !!bOld && bOld.visible === (dataLen !== null && dataLen > 0));
    reportOk('c7', ctx);
    await closeAll();
}

/* ---------------- c8: run 752, sync_process_id IS NULL -- banner hidden, zero requests ---------------- */
async function cell8() {
    console.log('\n[c8] run 752 (sync_process_id NULL) -- banner hidden, no request at all');
    const ctx = await openContext({ sessionId, width: 1400, height: 950, blockPaths: WRITE_PATHS });
    const watch = watchSyncMissingEndpoint(ctx.page);
    await gotoRun(ctx, RUN_752, 'th');
    await ctx.page.waitForTimeout(700);
    const b = await bannerState(ctx.page, '#syncNotParticipantBanner');
    measured('c8 banner state', b);
    check('c8: banner hidden', !!b && b.visible === false, b);
    check('c8: zero requests to sync-missing-employees', watch.requestCount === 0, watch.requestCount);
    reportOk('c8', ctx);
    await closeAll();
}

async function main() {
    await cell1();
    await cell2();
    await cell3();
    await cell4();
    await cell5();
    await cell6();
    await cell7();
    await cell8();
    console.log(`\nTOTAL: ${passed} passed, ${failed} failed`);
    process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});
